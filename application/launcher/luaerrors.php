<?php
/**
 * Lua errors from Portalkeeper: an addon the launcher writes into the client records the game's Lua
 * errors, and after the game exits the launcher sends what it recorded. Read back through
 * luaerrors.php or api/luaerrors.php with the view key.
 *
 * Each batch is one file in LUAERROR_DIR, <id>.json: the account, the logins it covers (character,
 * client build, loaded addons) and its errors (message, stack, a few locals, how often and when).
 * The same error from different batches and players is grouped by a fingerprint when read. Nothing
 * is added to the database.
 **/

declare(strict_types=1);

require_once __DIR__ . '/launcher.php';

const LUAERR_MAX_BODY = 1048576;     // the whole request, JSON included
const LUAERR_MAX_ERRORS = 200;       // errors per batch; the addon keeps at most 100
const LUAERR_MAX_SESSIONS = 50;      // logins per batch
const LUAERR_MAX_ADDONS = 300;       // addons per login
const LUAERR_PER_ACCOUNT_DAY = 20;   // batches per account per 24 hours
const LUAERR_BYTES_ACCOUNT_DAY = 5242880; // and what they may take together
const LUAERR_MESSAGE_BYTES = 2048;   // longer text is cut, not refused
const LUAERR_STACK_BYTES = 4096;
const LUAERR_LOCALS_BYTES = 4096;

function luaerr_enabled(): bool
{
    return launcher_enabled() && filter_var(launcher_env('LUAERROR_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
}

function luaerr_dir(): string
{
    return rtrim(launcher_env('LUAERROR_DIR', '/srv/luaerrors'), '/');
}

/** All batches together may take this much room (LUAERROR_MAX_MB, default 50); past it new ones are refused. */
function luaerr_max_bytes(): int
{
    return max(1, (int)launcher_env('LUAERROR_MAX_MB', '50')) * 1048576;
}

/** Days a batch is kept (LUAERROR_KEEP_DAYS, default 90; 0 keeps them forever). */
function luaerr_keep_days(): int
{
    return max(0, (int)launcher_env('LUAERROR_KEEP_DAYS', '90'));
}

/** LUAERROR_VIEW_KEY, else the combat logs' COMBATLOG_VIEW_KEY. Empty = nobody may read them. */
function luaerr_view_key(): string
{
    return trim(launcher_env('LUAERROR_VIEW_KEY', launcher_env('COMBATLOG_VIEW_KEY')));
}

function luaerr_key_matches(string $given): bool
{
    $key = luaerr_view_key();
    return strlen($key) >= 16 && hash_equals($key, $given);
}

function luaerr_read(string $id): ?array
{
    if (preg_match('/^[0-9a-f]{32}$/', $id) !== 1) {
        return null;
    }
    $data = json_decode((string)@file_get_contents(luaerr_dir() . '/' . $id . '.json'), true);
    return is_array($data) ? $data : null;
}

/** Every batch, one at a time, so they aren't all in memory together. */
function luaerr_each_batch(): Generator
{
    foreach (glob(luaerr_dir() . '/*.json') ?: [] as $file) {
        $batch = json_decode((string)@file_get_contents($file), true);
        if (is_array($batch) && isset($batch['id'], $batch['errors'])) {
            yield $batch;
        }
    }
}

/** Drops batches past LUAERROR_KEEP_DAYS. */
function luaerr_prune(): void
{
    $days = luaerr_keep_days();
    if ($days === 0) {
        return;
    }
    foreach (glob(luaerr_dir() . '/*.json') ?: [] as $file) {
        if (time() - (int)@filemtime($file) > $days * 86400) {
            @unlink($file);
        }
    }
}

/** Takes out what differs between two sightings of one error: "table: 1F8A3B20", "0x7ff...". The addon's Normalize does the same. */
function luaerr_normalize(string $text): string
{
    $text = preg_replace('/([A-Za-z]+: )[0-9a-fA-F]{6,}/', '$1?', $text);
    return preg_replace('/0x[0-9a-fA-F]+/', '0x?', $text);
}

/** Identifies an error across players and game runs: its message and the top of its stack. */
function luaerr_fingerprint(array $error): string
{
    $top = array_slice(explode("\n", (string)$error['stack']), 0, 3);
    return substr(sha1(luaerr_normalize((string)$error['message'] . "\n" . implode("\n", $top))), 0, 16);
}

/**
 * The addon an error points at: the first "AddOns\Name\" going from its message down its stack, past the
 * client's own interface. Lua keeps only the end of a long path ("...ions\modules\foo.lua"): one that
 * doesn't say whose it is ends the search, as the next one down may be a different addon's. A blocked
 * action names the addon itself.
 */
function luaerr_addon(array $error): string
{
    if (($error['kind'] ?? '') === 'blocked' && preg_match('/^(\S+) tried/', (string)$error['message'], $name)) {
        return $name[1];
    }
    $lines = array_merge([strtok((string)$error['message'], "\n")], explode("\n", (string)$error['stack']));
    foreach ($lines as $line) {
        if (preg_match('/AddOns[\\\\\/]([^\\\\\/:\r\n]+)[\\\\\/]/i', (string)$line, $name)) {
            if (stripos($name[1], 'Blizzard_') !== 0) {
                return $name[1];
            }
        } elseif (str_starts_with((string)$line, '...')) {
            return '';
        }
    }
    return '';
}

/** The version of an addon a login had loaded ("DragonUI 2.19" in its list), or empty. */
function luaerr_addon_version(array $session, string $addon): string
{
    if ($addon === '') {
        return '';
    }
    foreach ($session['addons'] ?? [] as $line) {
        if (strcasecmp((string)$line, $addon) === 0) {
            return '';
        }
        if (stripos((string)$line, $addon . ' ') === 0) {
            return substr((string)$line, strlen($addon) + 1);
        }
    }
    return '';
}

/**
 * The same error from every batch, one row each, most recently seen first: fingerprint, kind, message,
 * addon, count, accounts (name => count), first, last and the version of the addon each time. With a
 * fingerprint, only that error, with every sighting's stack, locals and login in "sightings".
 */
function luaerr_groups(?string $fingerprint = null): array
{
    $groups = [];
    foreach (luaerr_each_batch() as $batch) {
        foreach ($batch['errors'] as $error) {
            $print = luaerr_fingerprint($error);
            if ($fingerprint !== null && $print !== $fingerprint) {
                continue;
            }
            $session = is_int($error['session'] ?? null) ? ($batch['sessions'][$error['session']] ?? []) : [];
            $addon = luaerr_addon($error);
            $group = $groups[$print] ?? [
                'fingerprint' => $print,
                'kind' => $error['kind'],
                'message' => $error['message'],
                'addon' => $addon,
                'count' => 0,
                'accounts' => [],
                'versions' => [],
                'first' => null,
                'last' => null,
            ];
            $group['count'] += (int)$error['count'];
            $group['accounts'][$batch['account']] = ($group['accounts'][$batch['account']] ?? 0) + (int)$error['count'];
            $version = luaerr_addon_version($session, $addon);
            if ($version !== '' && !in_array($version, $group['versions'], true)) {
                $group['versions'][] = $version;
            }
            $first = $error['first'] ?? $batch['received_at'];
            $last = $error['last'] ?? $batch['received_at'];
            $group['first'] = $group['first'] === null ? $first : min($group['first'], $first);
            $group['last'] = $group['last'] === null ? $last : max($group['last'], $last);
            if ($fingerprint !== null) {
                $group['sightings'][] = [
                    'batch' => $batch['id'],
                    'account' => $batch['account'],
                    'received_at' => $batch['received_at'],
                    'client' => $batch['client'],
                    'count' => $error['count'],
                    'first' => $first,
                    'last' => $last,
                    'stack' => $error['stack'],
                    'locals' => $error['locals'],
                    'session' => $session,
                ];
            }
            $groups[$print] = $group;
        }
    }
    $groups = array_values($groups);
    usort($groups, static fn(array $a, array $b): int => $b['last'] <=> $a['last']);
    foreach ($groups as &$group) {
        if (isset($group['sightings'])) {
            usort($group['sightings'], static fn(array $a, array $b): int => $b['received_at'] <=> $a['received_at']);
        }
    }
    return $groups;
}

/** The batches without their errors and logins, newest first. */
function luaerr_batch_metas(): array
{
    $metas = [];
    foreach (luaerr_each_batch() as $batch) {
        $metas[] = [
            'id' => $batch['id'],
            'account' => $batch['account'],
            'received_at' => $batch['received_at'],
            'client' => $batch['client'],
            'errors' => count($batch['errors']),
            'count' => array_sum(array_column($batch['errors'], 'count')),
            'dropped' => $batch['dropped'],
            'suppressed' => $batch['suppressed'],
        ];
    }
    usort($metas, static fn(array $a, array $b): int => $b['received_at'] <=> $a['received_at']);
    return $metas;
}

/** Text as stored: cut to size on a character boundary, no user folder from any path. */
function luaerr_text(mixed $value, int $bytes): string
{
    if (!is_string($value)) {
        return '';
    }
    $value = preg_replace('/([\\\\\/](?:Users|home)[\\\\\/])[^\\\\\/\r\n"]+/i', '$1(user)', $value);
    return strlen($value) > $bytes ? mb_strcut($value, 0, $bytes, 'UTF-8') : $value;
}

/** A moment the client reported, or null when it's missing or in the future. */
function luaerr_time(mixed $value): ?int
{
    return is_int($value) && $value > 0 && $value <= time() + 300 ? $value : null;
}

/**
 * The request's body as what gets stored, or null when it's malformed: only known fields of known
 * types, within the limits.
 */
function luaerr_clean(string $body): ?array
{
    $request = json_decode($body, true);
    $errors = is_array($request) ? ($request['errors'] ?? null) : null;
    $sessions = is_array($request) ? ($request['sessions'] ?? []) : null;
    if (!is_array($errors) || !$errors || count($errors) > LUAERR_MAX_ERRORS || !array_is_list($errors)
        || !is_array($sessions) || count($sessions) > LUAERR_MAX_SESSIONS || !array_is_list($sessions)) {
        return null;
    }

    $cleanSessions = [];
    foreach ($sessions as $session) {
        $addons = is_array($session) ? ($session['addons'] ?? []) : null;
        if (!is_array($addons) || count($addons) > LUAERR_MAX_ADDONS || !array_is_list($addons)) {
            return null;
        }
        $cleanSessions[] = [
            'character' => luaerr_text($session['character'] ?? '', 64),
            'realm' => luaerr_text($session['realm'] ?? '', 64),
            'build' => luaerr_text($session['build'] ?? '', 32),
            'locale' => luaerr_text($session['locale'] ?? '', 8),
            'started' => luaerr_time($session['started'] ?? null),
            'addons' => array_map(static fn(mixed $addon): string => luaerr_text($addon, 128), $addons),
        ];
    }

    $cleanErrors = [];
    foreach ($errors as $error) {
        $message = is_array($error) ? luaerr_text($error['message'] ?? '', LUAERR_MESSAGE_BYTES) : '';
        if ($message === '') {
            return null;
        }
        $session = $error['session'] ?? null;
        $cleanErrors[] = [
            'kind' => ($error['kind'] ?? '') === 'blocked' ? 'blocked' : 'error',
            'message' => $message,
            'stack' => luaerr_text($error['stack'] ?? '', LUAERR_STACK_BYTES),
            'locals' => luaerr_text($error['locals'] ?? '', LUAERR_LOCALS_BYTES),
            'count' => is_int($error['count'] ?? null) ? max(1, $error['count']) : 1,
            'first' => luaerr_time($error['first'] ?? null),
            'last' => luaerr_time($error['last'] ?? null),
            'session' => is_int($session) && isset($cleanSessions[$session]) ? $session : null,
        ];
    }

    return [
        'sessions' => $cleanSessions,
        'errors' => $cleanErrors,
        'dropped' => is_int($request['dropped'] ?? null) ? max(0, $request['dropped']) : 0,
        'suppressed' => is_int($request['suppressed'] ?? null) ? max(0, $request['suppressed']) : 0,
    ];
}

/**
 * POST api/launcher/luaerrors.php for a logged-in account: stores the batch and answers
 * {"id", "errors"} (also for one already sent). Answers and exits.
 *
 * @param array{id:int, name:string} $session from launcher_require_session()
 */
function luaerr_receive(array $session): never
{
    $body = file_get_contents('php://input', false, null, 0, LUAERR_MAX_BODY + 1);
    if ($body === false || strlen($body) > LUAERR_MAX_BODY) {
        launcher_json(413, ['error' => 'That batch of Lua errors is too big.']);
    }
    $clean = luaerr_clean($body);
    if ($clean === null) {
        launcher_json(400, ['error' => 'That isn\'t a batch of Lua errors.']);
    }

    $dir = luaerr_dir();
    if ((!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) || !is_writable($dir)) {
        error_log('luaerrors: LUAERROR_DIR ' . $dir . ' can\'t be written');
        launcher_json(503, ['error' => 'The realm can\'t store Lua errors right now.']);
    }
    luaerr_prune();

    // One batch at a time, so the daily count and the room left can't be raced past.
    $lock = @fopen($dir . '/.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        launcher_json(503, ['error' => 'The realm can\'t store Lua errors right now.']);
    }

    // The same batch sent twice (a retry after a lost answer) is stored once.
    $id = substr(hash('sha256', $session['id'] . ':' . json_encode($clean)), 0, 32);
    if (luaerr_read($id) !== null) {
        launcher_json(200, ['id' => $id, 'errors' => count($clean['errors'])]);
    }
    $today = 0;
    $todayBytes = 0;
    $used = 0;
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $size = (int)@filesize($file);
        $used += $size;
        // Only batches from the last day are opened to see whose they are.
        if (time() - (int)@filemtime($file) < 86400
            && (int)(json_decode((string)@file_get_contents($file), true)['account_id'] ?? 0) === (int)$session['id']) {
            $today++;
            $todayBytes += $size;
        }
    }
    if ($today >= LUAERR_PER_ACCOUNT_DAY || $todayBytes + strlen($body) > LUAERR_BYTES_ACCOUNT_DAY) {
        launcher_json(429, ['error' => 'That\'s enough Lua errors for today. Thank you!']);
    }
    if ($used + strlen($body) * 2 > luaerr_max_bytes()) {
        error_log('luaerrors: LUAERROR_MAX_MB reached; new batches are refused');
        launcher_json(507, ['error' => 'The realm can\'t store more Lua errors right now.']);
    }

    $request = json_decode($body, true);
    $client = is_string($request['client'] ?? null) ? $request['client'] : '';
    $batch = [
        'id' => $id,
        'account_id' => $session['id'],
        'account' => $session['name'],
        'received_at' => time(),
        'client' => preg_match('/^[\x20-\x7E]{1,64}$/', $client) ? $client : '',
    ] + $clean;
    $path = $dir . '/' . $id . '.json';
    $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $json = json_encode($batch, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || @file_put_contents($temp, $json) === false || !@rename($temp, $path)) {
        @unlink($temp);
        launcher_json(507, ['error' => 'The realm can\'t store Lua errors right now.']);
    }
    launcher_json(200, ['id' => $id, 'errors' => count($clean['errors'])]);
}
