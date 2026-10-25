<?php
/**
 * Legacy perks behind api/launcher/legacy.php: the points a logged-in account earned with its
 * challenges, and each character's build in the three perk trees. mod-legacy on the worldserver
 * keeps the challenges, the trees and the builds; this asks it over SOAP with two console
 * commands, which answer one line of JSON each:
 *
 *   .legacy list <account id>                       cap, earned, characters, trees, challenges
 *   .legacy set <account id> <guid> <ranks>         {"ok", "character", "message"} or
 *                                                   {"ok": false, "error", "message"}
 *
 * <ranks> is the character's whole new build, "well_rested=5,diplomat=3", or "-" for none. The
 * worldserver checks it (points, requirements, ownership); the portal only makes sure the command
 * is built from nothing but numbers and perk ids.
 *
 * Like the coin shop (shop.php), a list answer is kept for LEGACY_CACHE_SECONDS per account (a save
 * drops it), and an account gets one save at a time and at most LEGACY_SAVES_PER_MINUTE of them.
 * The cache and the locks are files in LEGACY_CACHE_DIR.
 **/

declare(strict_types=1);

require_once __DIR__ . '/launcher.php';

// What a build may hold. mod-legacy has far fewer perks and ranks; these only bound the command.
const LEGACY_MAX_PERKS = 40;
const LEGACY_MAX_RANK = 9;

function legacy_enabled(): bool
{
    return launcher_enabled() && filter_var(launcher_env('LEGACY_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
}

function legacy_cache_seconds(): int
{
    return max(0, (int)launcher_env('LEGACY_CACHE_SECONDS', '10'));
}

function legacy_saves_per_minute(): int
{
    return max(1, (int)launcher_env('LEGACY_SAVES_PER_MINUTE', '6'));
}

/** The cache and lock folder, made on first use. Null when it can't be written. */
function legacy_dir(): ?string
{
    $dir = rtrim(launcher_env('LEGACY_CACHE_DIR', sys_get_temp_dir() . '/portal-legacy'), '/');
    if ((!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) || !is_writable($dir)) {
        error_log('legacy: LEGACY_CACHE_DIR ' . $dir . ' can\'t be written; listings aren\'t cached and saves are refused');
        return null;
    }
    return $dir;
}

function legacy_cache_path(string $dir, int $accountId): string
{
    return $dir . '/list-' . $accountId . '.json';
}

/** When the account last saved a build (microtime), so an older listing isn't cached after it. */
function legacy_saved_at(string $dir, int $accountId): float
{
    return (float)@file_get_contents($dir . '/saved-' . $accountId);
}

/** A whole number from JSON (an int) or a form field (digits only) within the range, else null. */
function legacy_int(mixed $value, int $min, int $max): ?int
{
    if (is_string($value) && preg_match('/^[0-9]{1,10}\z/', $value)) {
        $value = (int)$value;
    }
    return is_int($value) && $value >= $min && $value <= $max ? $value : null;
}

/**
 * The <ranks> argument of .legacy set for a {"<perk id>": <rank>, ...} build: "id=rank,id=rank",
 * or "-" when it's empty. Null when the build isn't one: not an object, too many perks, an id that
 * isn't [a-z_]{1,40}, a rank that isn't 1..LEGACY_MAX_RANK.
 */
function legacy_ranks_argument(mixed $ranks): ?string
{
    if (!is_array($ranks) || count($ranks) > LEGACY_MAX_PERKS) {
        return null;
    }

    $parts = [];
    foreach ($ranks as $id => $rank) {
        $rank = legacy_int($rank, 1, LEGACY_MAX_RANK);
        if (!is_string($id) || !preg_match('/^[a-z_]{1,40}\z/', $id) || $rank === null) {
            return null;
        }
        $parts[] = $id . '=' . $rank;
    }
    return $parts === [] ? '-' : implode(',', $parts);
}

/**
 * A character as the launcher reads it. PHP decodes mod-legacy's empty build {"ranks":{}} as [] and
 * would send it on as a list, which the launcher can't read as a build: ranks always go out as an
 * object.
 */
function legacy_character_out(array $character): array
{
    $ranks = $character['ranks'] ?? [];
    $character['ranks'] = (object)(is_array($ranks) ? $ranks : []);
    return $character;
}

/** A listing as the launcher reads it: every character through legacy_character_out(). */
function legacy_listing_out(array $listing): array
{
    if (is_array($listing['characters'] ?? null)) {
        $listing['characters'] = array_map(
            static fn(mixed $character): mixed => is_array($character) ? legacy_character_out($character) : $character,
            $listing['characters']
        );
    }
    return $listing;
}

function legacy_handle_request(array $session): never
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        legacy_list($session);
    }
    if ($method === 'POST') {
        legacy_save($session);
    }

    header('Allow: GET, POST');
    launcher_json(405, ['error' => 'Use GET or POST.']);
}

/** GET: what mod-legacy answers .legacy list, minus its "ok", plus "cachedAt". */
function legacy_list(array $session): never
{
    $dir = legacy_dir();
    $accountId = (int)$session['id'];

    if ($dir !== null && legacy_cache_seconds() > 0) {
        $path = legacy_cache_path($dir, $accountId);
        $mtime = @filemtime($path);
        if ($mtime !== false && time() - $mtime < legacy_cache_seconds()) {
            $cached = json_decode((string)@file_get_contents($path), true);
            if (is_array($cached)) {
                launcher_json(200, legacy_listing_out($cached));
            }
        }
    }

    $started = microtime(true);
    $answer = launcher_soap_json('.legacy list ' . $accountId, 8);
    if ($answer === null) {
        launcher_json(503, ['error' => 'Legacy can\'t reach the game server right now. Try again in a minute.']);
    }
    if (empty($answer['ok'])) {
        launcher_json(503, ['error' => (string)($answer['message'] ?? 'Legacy is closed.'), 'code' => (string)($answer['error'] ?? '')]);
    }

    unset($answer['ok']);
    $body = $answer + ['cachedAt' => time()];

    // A save that finished while this listing was asked for has made it old already.
    if ($dir !== null && legacy_cache_seconds() > 0 && legacy_saved_at($dir, $accountId) < $started) {
        $path = legacy_cache_path($dir, $accountId);
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temp, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
            @rename($temp, $path) || @unlink($temp);
        }
    }

    launcher_json(200, legacy_listing_out($body));
}

/**
 * POST {"character": <guid>, "ranks": {"<perk id>": <rank>, ...}} (JSON, or form fields with
 * ranks[<perk id>]=<rank>): the character's whole new build; {} resets it.
 *   200 {"character", "message"}
 *   409 {"error", "message"}   refused: no_character, combat, points, locked, invalid
 *   503 Legacy is closed or the worldserver can't be reached
 *   429 another save of this account is still on its way, or too many in the last minute
 *   502 the worldserver didn't answer: the build may or may not have been saved
 */
function legacy_save(array $session): never
{
    $input = $_POST;
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
        $input = json_decode((string)file_get_contents('php://input'), true);
        $input = is_array($input) ? $input : [];
    }

    $character = legacy_int($input['character'] ?? null, 1, 4294967295);
    $ranks = array_key_exists('ranks', $input) ? legacy_ranks_argument($input['ranks']) : null;
    if ($character === null || $ranks === null) {
        launcher_json(400, ['error' => 'Pick a character and the perks it should have.']);
    }

    $dir = legacy_dir();
    if ($dir === null) {
        launcher_json(503, ['error' => 'Legacy is closed right now.']);
    }

    $accountId = (int)$session['id'];
    $lock = @fopen($dir . '/save-' . $accountId . '.lock', 'c+');
    if ($lock === false) {
        error_log('legacy: can\'t open the save lock of account ' . $accountId . ' in ' . $dir);
        launcher_json(503, ['error' => 'Legacy is closed right now.']);
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        launcher_json(429, ['error' => 'Your last save is still going through. Try again in a moment.']);
    }

    // The lock file holds the times of the account's saves in the last minute. Every one, refused
    // or not, costs the worldserver a look at the database on its main thread.
    $now = time();
    $recent = array_values(array_filter(
        array_map('intval', explode(',', (string)stream_get_contents($lock))),
        static fn(int $at): bool => $at > $now - 60
    ));
    if (count($recent) >= legacy_saves_per_minute()) {
        flock($lock, LOCK_UN);
        fclose($lock);
        header('Retry-After: 60');
        launcher_json(429, ['error' => 'That was a lot of saves at once. Wait a minute and try again.']);
    }
    $recent[] = $now;
    ftruncate($lock, 0);
    rewind($lock);
    fwrite($lock, implode(',', $recent));
    fflush($lock);

    try {
        $answer = launcher_soap_json(sprintf('.legacy set %d %d %s', $accountId, $character, $ranks), 20);
    } finally {
        // Whatever happened, the build may have changed: drop the listing, and stop a listing asked
        // for meanwhile from being cached.
        @file_put_contents($dir . '/saved-' . $accountId, (string)microtime(true));
        @unlink(legacy_cache_path($dir, $accountId));
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    if ($answer === null || (!empty($answer['ok']) && !is_array($answer['character'] ?? null))) {
        launcher_json(502, ['error' => 'The game server didn\'t answer. Look at your perks before you try again: '
            . 'they may have been saved.']);
    }

    if (!empty($answer['ok'])) {
        launcher_json(200, [
            'character' => legacy_character_out($answer['character']),
            'message' => (string)($answer['message'] ?? ''),
        ]);
    }

    $error = (string)($answer['error'] ?? 'refused');
    $message = (string)($answer['message'] ?? 'The perks weren\'t saved.');
    if ($error === 'closed') {
        launcher_json(503, ['error' => $message, 'code' => $error]);
    }
    launcher_json(409, ['error' => $error, 'message' => $message]);
}
