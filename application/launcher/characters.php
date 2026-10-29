<?php
/**
 * Character order behind api/launcher/characters.php: the logged-in account's characters in the
 * order the character select screen shows them, and saving a new one.
 *
 * The order is the core's own `characters`.`order` column (NULL until set). The worldserver sends
 * the character list ORDER BY COALESCE(`order`, guid) and never writes the column, so the portal
 * can write it while the player is online; the new order shows the next time the list is asked for.
 * The Evermore core's `favourite` column (0 or 1, the star on its character select screen) works
 * the same way. Writing needs a column grant on top of the portal user's SELECT (see the README):
 *
 *   GRANT UPDATE (`order`, `favourite`) ON acore_characters.characters TO 'wow_register'@'%';
 *
 * An account gets one save at a time and at most CHARACTER_ORDER_SAVES_PER_MINUTE of them: a lock
 * file per account in CHARACTER_ORDER_CACHE_DIR.
 **/

declare(strict_types=1);

require_once __DIR__ . '/launcher.php';

// `order` is a signed tinyint, so no more characters than this can get a place.
const CHARACTER_ORDER_MAX = 127;

function character_order_enabled(): bool
{
    return launcher_enabled() && filter_var(launcher_env('CHARACTER_ORDER_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
}

function character_order_saves_per_minute(): int
{
    return max(1, (int)launcher_env('CHARACTER_ORDER_SAVES_PER_MINUTE', '10'));
}

/** The lock folder, made on first use. Null when it can't be written. */
function character_order_dir(): ?string
{
    $dir = rtrim(launcher_env('CHARACTER_ORDER_CACHE_DIR', sys_get_temp_dir() . '/portal-character-order'), '/');
    if ((!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) || !is_writable($dir)) {
        error_log('characters: CHARACTER_ORDER_CACHE_DIR ' . $dir . ' can\'t be written; saves are refused');
        return null;
    }
    return $dir;
}

function character_order_table(): string
{
    return '`' . str_replace('`', '', launcher_env('DB_CHARACTERS_NAME', 'acore_characters')) . '`.`characters`';
}

/** A guid from JSON (an int) or a form field (digits only), else null. */
function character_order_guid(mixed $value): ?int
{
    if (is_string($value) && preg_match('/^[0-9]{1,10}\z/', $value)) {
        $value = (int)$value;
    }
    return is_int($value) && $value >= 1 && $value <= 4294967295 ? $value : null;
}

function character_order_handle_request(array $session): never
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        character_order_list($session);
    }
    if ($method === 'POST') {
        character_order_save($session);
    }

    header('Allow: GET, POST');
    launcher_json(405, ['error' => 'Use GET or POST.']);
}

/**
 * Whether the characters table has the `favourite` column (a core without it lists every character
 * as not a favourite and saves no favourites). Asked once per request.
 */
function character_order_has_favourites(PDO $db): bool
{
    static $has = null;
    if ($has === null) {
        try {
            $db->query('SELECT `favourite` FROM ' . character_order_table() . ' LIMIT 0');
            $has = true;
        } catch (PDOException $e) {
            // 1054: unknown column. Anything else is the database's trouble, not the column's.
            if ((int)($e->errorInfo[1] ?? 0) !== 1054) {
                throw $e;
            }
            $has = false;
        }
    }
    return $has;
}

/** The account's characters that aren't deleted, in the order the character select screen shows them. */
function character_order_characters(PDO $db, int $accountId): array
{
    $favourite = character_order_has_favourites($db) ? '`favourite`' : '0 AS `favourite`';
    $statement = $db->prepare(
        'SELECT guid, name, race, class, gender, level, logout_time, online, `order`, ' . $favourite . ' FROM '
        . character_order_table() . ' WHERE account = ? AND deleteInfos_Name IS NULL ORDER BY COALESCE(`order`, guid), guid');
    $statement->execute([$accountId]);

    $characters = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $characters[] = [
            'guid' => (int)$row['guid'],
            'name' => (string)$row['name'],
            'race' => (int)$row['race'],
            'class' => (int)$row['class'],
            'gender' => (int)$row['gender'],
            'level' => (int)$row['level'],
            'lastPlayed' => (int)$row['logout_time'],
            'online' => (int)$row['online'] !== 0,
            'order' => $row['order'] === null ? null : (int)$row['order'],
            'favourite' => (int)$row['favourite'] !== 0,
        ];
    }
    return $characters;
}

/** The listing, or 503 when the characters database can't be read. */
function character_order_body(PDO $db, int $accountId): array
{
    try {
        return ['characters' => character_order_characters($db, $accountId)];
    } catch (Throwable $e) {
        error_log('characters: listing account ' . $accountId . ': ' . $e->getMessage());
        launcher_json(503, ['error' => 'Your characters can\'t be read right now. Try again in a minute.']);
    }
}

/** GET: {"characters": [...]} in the current order. */
function character_order_list(array $session): never
{
    launcher_json(200, character_order_body(launcher_db(), (int)$session['id']));
}

/**
 * A list of guids from the request (JSON ints, or form fields), or null when it isn't one or is
 * longer than CHARACTER_ORDER_MAX.
 */
function character_order_guid_list(mixed $listed): ?array
{
    if (!is_array($listed) || !array_is_list($listed) || count($listed) > CHARACTER_ORDER_MAX) {
        return null;
    }
    $guids = [];
    foreach ($listed as $value) {
        $guid = character_order_guid($value);
        if ($guid === null) {
            return null;
        }
        $guids[] = $guid;
    }
    return $guids;
}

/**
 * POST {"order": [<guid>, ...], "favourites": [<guid>, ...]} (JSON, or form fields order[]=<guid>
 * and favourites[]=<guid>): the account's characters from the top. Characters left out keep their
 * current order after the listed ones. "favourites", when it's there, is every favourite of the
 * account (an empty list clears them); without it (or null) the favourites stay as they are, and a core
 * without the `favourite` column ignores it. An order that's already saved answers 200 without
 * writing.
 *   200 {"characters": [...]}   the new order, like GET
 *   400 malformed
 *   409 {"error", "message"}    duplicate: a guid twice in a list; not_yours: not a character of this account
 *   429 another save of this account is still on its way, or too many in the last minute
 *   503 the order can't be written (the column grant is missing, or the database is down)
 */
function character_order_save(array $session): never
{
    $input = $_POST;
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
        $input = json_decode((string)file_get_contents('php://input'), true);
        $input = is_array($input) ? $input : [];
    }

    $order = character_order_guid_list($input['order'] ?? null);
    if ($order === null) {
        launcher_json(400, ['error' => 'Send the order as a list of character guids.']);
    }
    if (count(array_unique($order)) !== count($order)) {
        launcher_json(409, ['error' => 'duplicate', 'message' => 'A character is in the order twice.']);
    }
    // Form fields can't send an empty list, so favourites= (one empty field) clears them too; a JSON
    // null is the same as leaving it out.
    $favourites = null;
    if (($input['favourites'] ?? null) !== null) {
        $favourites = $input['favourites'] === '' ? [] : character_order_guid_list($input['favourites']);
        if ($favourites === null) {
            launcher_json(400, ['error' => 'Send the favourites as a list of character guids.']);
        }
        if (count(array_unique($favourites)) !== count($favourites)) {
            launcher_json(409, ['error' => 'duplicate', 'message' => 'A character is in the favourites twice.']);
        }
    }

    $dir = character_order_dir();
    if ($dir === null) {
        launcher_json(503, ['error' => 'Character ordering isn\'t available right now.']);
    }

    $accountId = (int)$session['id'];
    $lock = @fopen($dir . '/save-' . $accountId . '.lock', 'c+');
    if ($lock === false) {
        error_log('characters: can\'t open the save lock of account ' . $accountId . ' in ' . $dir);
        launcher_json(503, ['error' => 'Character ordering isn\'t available right now.']);
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        launcher_json(429, ['error' => 'Your last save is still going through. Try again in a moment.']);
    }

    $db = launcher_db();
    try {
        $characters = character_order_body($db, $accountId)['characters'];
        $current = array_column($characters, 'guid');

        $foreign = array_diff(array_merge($order, $favourites ?? []), $current);
        if ($foreign !== []) {
            launcher_json(409, ['error' => 'not_yours', 'message' => 'That isn\'t one of your characters any more.']);
        }

        $all = array_merge($order, array_values(array_diff($current, $order)));
        if (count($all) > CHARACTER_ORDER_MAX) {
            error_log('characters: account ' . $accountId . ' has ' . count($all) . ' characters, more than `order` holds');
            launcher_json(503, ['error' => 'This account has too many characters to order.']);
        }

        // The order as it will be, from the rows just read: the answer when nothing changes, and when
        // the order is saved but can't be read back.
        if ($favourites !== null && !character_order_has_favourites($db)) {
            error_log('characters: account ' . $accountId . ' sent favourites, but the characters table has no '
                . '`favourite` column (an older core); they aren\'t saved');
            $favourites = null;
        }
        $byGuid = array_column($characters, null, 'guid');
        $saved = [];
        $unchanged = true;
        foreach ($all as $place => $guid) {
            $favourite = $favourites === null ? $byGuid[$guid]['favourite'] : in_array($guid, $favourites, true);
            $unchanged = $unchanged && $byGuid[$guid]['order'] === $place && $byGuid[$guid]['favourite'] === $favourite;
            $saved[] = array_replace($byGuid[$guid], ['order' => $place, 'favourite' => $favourite]);
        }
        if ($unchanged) {
            launcher_json(200, ['characters' => $saved]);
        }

        // The lock file holds the times of the account's saves in the last minute: each is a commit on
        // the characters database.
        $now = time();
        $recent = array_values(array_filter(
            array_map('intval', explode(',', (string)stream_get_contents($lock))),
            static fn(int $at): bool => $at > $now - 60
        ));
        if (count($recent) >= character_order_saves_per_minute()) {
            header('Retry-After: 60');
            launcher_json(429, ['error' => 'That was a lot of saves at once. Wait a minute and try again.']);
        }
        $recent[] = $now;
        ftruncate($lock, 0);
        rewind($lock);
        fwrite($lock, implode(',', $recent));
        fflush($lock);

        // A realm that only ran the older GRANT UPDATE (`order`) line still gets the order saved, once more
        // without the favourites.
        $withFavourites = $favourites !== null;
        while (true) {
            try {
                $db->beginTransaction();
                $update = $db->prepare('UPDATE ' . character_order_table() . ' SET `order` = ?'
                    . ($withFavourites ? ', `favourite` = ?' : '') . ' WHERE guid = ? AND account = ?');
                foreach ($saved as $place => $character) {
                    $values = $withFavourites ? [$place, $character['favourite'] ? 1 : 0] : [$place];
                    $update->execute([...$values, $character['guid'], $accountId]);
                }
                $db->commit();
                break;
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                // 1142: UPDATE denied on the table, 1143: on the column. Both mean a grant is missing.
                $code = $e instanceof PDOException ? (int)($e->errorInfo[1] ?? 0) : 0;
                if (($code === 1142 || $code === 1143) && $withFavourites) {
                    error_log('characters: the portal database user can\'t write `favourite` (run the GRANT UPDATE '
                        . '(`order`, `favourite`) line of docker/create-db-user.sql.example); saving the order without '
                        . 'the favourites: ' . $e->getMessage());
                    $withFavourites = false;
                    $saved = array_map(
                        static fn(array $character): array => array_replace($character, ['favourite' => $byGuid[$character['guid']]['favourite']]),
                        $saved
                    );
                    continue;
                }
                if ($code === 1142 || $code === 1143) {
                    error_log('characters: the portal database user can\'t write `order` (run the GRANT UPDATE '
                        . '(`order`, `favourite`) line of docker/create-db-user.sql.example): ' . $e->getMessage());
                    launcher_json(503, ['error' => 'Character ordering isn\'t set up on this realm yet.']);
                }
                error_log('characters: saving the order of account ' . $accountId . ': ' . $e->getMessage());
                launcher_json(503, ['error' => 'Your order wasn\'t saved. Try again in a minute.']);
            }
        }

        // Saved: answer 200 whatever happens now.
        try {
            $body = ['characters' => character_order_characters($db, $accountId)];
        } catch (Throwable $e) {
            error_log('characters: reading back the order of account ' . $accountId . ': ' . $e->getMessage());
            $body = ['characters' => $saved];
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    launcher_json(200, $body);
}
