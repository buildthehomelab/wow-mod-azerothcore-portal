<?php
/**
 * Combat logs from players who opted in to sharing them in Portalkeeper: the upload protocol behind
 * api/launcher/combatlog.php, where the logs are kept, and the digest made from each one
 * (combatlog_digest.php) for combatlogs.php and api/combatlogs.php.
 *
 * Everything lives as files in COMBATLOG_DIR; nothing is added to the database:
 *   incoming/<id>.json + <id>.part       an upload in progress
 *   logs/<id>.json + <id>.txt.gz         a finished upload, as the launcher sent it
 *   logs/<id>.digest.json                its digest
 **/

declare(strict_types=1);

require_once __DIR__ . '/launcher.php';
require_once __DIR__ . '/combatlog_digest.php';

const CLOG_PART_SIZE = 4194304;        // under PHP's default post_max_size (8M) and any proxy's body limit
const CLOG_INCOMING_HOURS = 48;        // an upload that stalls longer than this starts over
const CLOG_PENDING_PER_ACCOUNT = 4;
const CLOG_LOGS_PER_ACCOUNT = 200;     // finished uploads kept per account: about three a day for COMBATLOG_KEEP_DAYS
const CLOG_SUMMARY_BYTES = 8192;       // bytes of boss names per list in a summary
const CLOG_SUMMARY_NAMES = 100;        // boss names per list in a summary: a raid night has a few dozen
const CLOG_LIST_ROWS = 1000;           // uploads on the list and the API's list

function clog_enabled(): bool
{
    return launcher_enabled() && filter_var(launcher_env('COMBATLOG_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
}

function clog_dir(): string
{
    return rtrim(launcher_env('COMBATLOG_DIR', '/srv/combatlogs'), '/');
}

/**
 * A size setting in bytes. 0, or anything but a whole number of MB such as "2G", is no limit: the launcher
 * deletes logs it's told are too big, so a mistake here mustn't make the limit small.
 */
function clog_megabytes(string $key, string $default): int
{
    $mb = launcher_env($key, $default);
    return preg_match('/^\d+$/D', $mb) === 1 && (int)$mb > 0 && (int)$mb <= PHP_INT_MAX >> 20 ? (int)$mb * 1048576 : PHP_INT_MAX;
}

/** Bytes one account may keep (COMBATLOG_MAX_MB_PER_ACCOUNT, default 2048): about 200 4-hour raids. */
function clog_account_limit(): int
{
    return clog_megabytes('COMBATLOG_MAX_MB_PER_ACCOUNT', '2048');
}

/** Largest upload accepted, in bytes of gzip (COMBATLOG_MAX_MB, default 512), and never more than an account may keep. */
function clog_max_size(): int
{
    return min(clog_megabytes('COMBATLOG_MAX_MB', '512'), clog_account_limit());
}

/** Days a log is kept (COMBATLOG_KEEP_DAYS, default 60; 0 keeps them forever). */
function clog_keep_days(): int
{
    return max(0, (int)launcher_env('COMBATLOG_KEEP_DAYS', '60'));
}

function clog_valid_id(string $id): bool
{
    return preg_match('/^[0-9a-f]{32}$/', $id) === 1;
}

function clog_path(string $folder, string $id, string $suffix): string
{
    return clog_dir() . '/' . $folder . '/' . $id . $suffix;
}

/** The storage folders, made on first use. False when COMBATLOG_DIR can't be written. */
function clog_prepare(): bool
{
    foreach (['incoming', 'logs'] as $folder) {
        $path = clog_dir() . '/' . $folder;
        if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) {
            return false;
        }
    }
    return is_writable(clog_dir() . '/incoming') && is_writable(clog_dir() . '/logs');
}

function clog_read_json(string $path): ?array
{
    $json = @file_get_contents($path);
    $data = $json === false ? null : json_decode($json, true);
    return is_array($data) ? $data : null;
}

/**
 * Writes through a temp file, so a reader never sees half a file. False when $data can't be written,
 * and whatever was at $path stays as it was. A byte that isn't UTF-8 is no reason to fail: it's stored
 * as a replacement character.
 */
function clog_write_json(string $path, array $data): bool
{
    $json = json_encode($data, CLOG_JSON_FLAGS);
    if ($json === false) {
        error_log('combat log: ' . basename($path) . ' not written: ' . json_last_error_msg());
        return false;
    }
    $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($temp, $json) === false) {
        @unlink($temp);
        return false;
    }
    if (!@rename($temp, $path)) {
        @unlink($temp);
        return false;
    }
    return true;
}

/**
 * Every upload's metadata in a folder, newest first, without its summary: every upload begins by reading
 * this, and the names in other players' summaries mustn't be able to stop that. clog_list has them.
 */
function clog_metas(string $folder): array
{
    $metas = [];
    foreach (glob(clog_dir() . '/' . $folder . '/*.json') ?: [] as $file) {
        if (str_ends_with($file, '.digest.json')) {
            continue;
        }
        $meta = clog_read_json($file);
        if ($meta !== null && clog_valid_id((string)($meta['id'] ?? ''))) {
            unset($meta['summary']);
            $metas[] = $meta;
        }
    }
    usort($metas, static fn(array $a, array $b): int =>
        (int)($b['completed_at'] ?? $b['created_at'] ?? 0) <=> (int)($a['completed_at'] ?? $a['created_at'] ?? 0));
    return $metas;
}

function clog_discard_incoming(string $id): void
{
    @unlink(clog_path('incoming', $id, '.part'));
    @unlink(clog_path('incoming', $id, '.json'));
}

/**
 * Deletes a finished upload, log file first (clog_digest relies on that). False when a file stayed
 * behind, which means COMBATLOG_DIR can't be written.
 */
function clog_delete_log(string $id): bool
{
    $gone = true;
    foreach (['.txt.gz', '.digest.json', '.digest.lock', '.json'] as $suffix) {
        $path = clog_path('logs', $id, $suffix);
        if (!@unlink($path) && file_exists($path)) {
            $gone = false;
        }
    }
    return $gone;
}

/**
 * The account's lock, or a 503 answer when another of its requests has it. Held until it's closed or the
 * request ends, so begin and finish each see the account's uploads as they are. It doesn't wait, because
 * waiting requests would hold web workers: the launcher sends one at a time and tries again on its next run.
 *
 * @return resource
 */
function clog_lock_account(int $accountId)
{
    $lock = @fopen(clog_path('incoming', 'account-' . $accountId, '.lock'), 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        launcher_json(503, ['error' => 'The realm can\'t store combat logs right now.']);
    }
    return $lock;
}

/**
 * About the most a digest takes on disk, which a small crafted gzip can reach. It's more than CLOG_JSON_SHARE
 * because reading only stops after passing that, with the fight it's in still added.
 */
function clog_digest_reserve(): int
{
    return (int)(clog_memory_limit() * CLOG_JSON_SHARE * 1.5);
}

/** What a finished upload holds on disk, with a digest still to come counted at its largest. */
function clog_log_bytes(string $id): int
{
    $gzip = @filesize(clog_path('logs', $id, '.txt.gz'));
    if ($gzip === false) {
        return 0;
    }
    $digest = @filesize(clog_path('logs', $id, '.digest.json'));
    return $gzip + ($digest === false ? clog_digest_reserve() : $digest);
}

/**
 * Makes room for an account's new upload of $size bytes, from its finished logs' ids and its unfinished
 * uploads' metadata, both newest first. Unfinished uploads go first, by what they announced they'd grow
 * to and their digest: the launcher still has those and sends them again, while the only copy of a
 * finished log is here.
 */
function clog_make_room(array $logs, array $uploads, int $size): void
{
    foreach (array_splice($uploads, CLOG_PENDING_PER_ACCOUNT - 1) as $meta) {
        clog_discard_incoming($meta['id']);
    }
    foreach (array_splice($logs, CLOG_LOGS_PER_ACCOUNT - 1) as $id) {
        clog_delete_log($id);
    }

    $over = $size + clog_digest_reserve() - clog_account_limit() + array_sum(array_map('clog_log_bytes', $logs));
    foreach ($uploads as $meta) {
        $over += (int)$meta['size'] + clog_digest_reserve();
    }
    foreach (array_reverse($uploads) as $meta) {
        if ($over <= 0) {
            return;
        }
        $over -= (int)$meta['size'] + clog_digest_reserve();
        clog_discard_incoming($meta['id']);
    }
    foreach (array_reverse($logs) as $id) {
        if ($over <= 0) {
            return;
        }
        $over -= clog_log_bytes($id);
        clog_delete_log($id);
    }
}

/** Drops stalled uploads and logs past COMBATLOG_KEEP_DAYS. */
function clog_prune(): void
{
    $now = time();
    foreach (clog_metas('incoming') as $meta) {
        if ($now - (int)($meta['created_at'] ?? 0) > CLOG_INCOMING_HOURS * 3600) {
            clog_discard_incoming($meta['id']);
        }
    }
    $days = clog_keep_days();
    if ($days > 0) {
        foreach (clog_metas('logs') as $meta) {
            if ($now - (int)($meta['completed_at'] ?? 0) > $days * 86400) {
                clog_delete_log($meta['id']);
            }
        }
    }
}

/**
 * The launcher's side of an upload: the request's action for a logged-in account. Answers and exits.
 *
 * @param array{id:int, name:string} $session from launcher_require_session()
 */
function clog_handle_request(array $session): never
{
    if (!clog_prepare()) {
        launcher_json(503, ['error' => 'The realm can\'t store combat logs right now.']);
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        launcher_json(200, ['enabled' => true, 'part_size' => CLOG_PART_SIZE, 'max_size' => clog_max_size()]);
    }
    if ($method !== 'POST') {
        header('Allow: GET, POST');
        launcher_json(405, ['error' => 'Use GET or POST.']);
    }

    $id = (string)($_GET['upload'] ?? '');
    switch ((string)($_GET['action'] ?? '')) {
        case 'begin':
            $input = json_decode((string)file_get_contents('php://input', false, null, 0, 65536), true);
            clog_begin($session, is_array($input) ? $input : []);

        case 'part':
            clog_part($session, $id, (string)($_GET['offset'] ?? ''));

        case 'finish':
            clog_finish($session, $id);

        default:
            launcher_json(400, ['error' => 'Unknown action.']);
    }
}

function clog_begin(array $session, array $input): never
{
    $size = $input['size'] ?? null;
    $sha256 = strtolower((string)($input['sha256'] ?? ''));
    if (!is_int($size) || $size < 1 || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
        launcher_json(400, ['error' => 'Send the upload\'s size and sha256.']);
    }
    if ($size > clog_max_size()) {
        launcher_json(413, ['error' => 'That combat log is larger than this realm accepts.']);
    }

    $accountId = (int)$session['id'];
    // Uploads begun together would each count the room the others are about to use.
    $lock = clog_lock_account($accountId);

    $finished = [];
    foreach (clog_metas('logs') as $meta) {
        if ((int)$meta['account_id'] !== $accountId) {
            continue;
        }
        if ($meta['sha256'] === $sha256) {
            launcher_json(200, ['upload' => $meta['id'], 'complete' => true]);
        }
        $finished[] = $meta['id'];
    }

    clog_prune();
    $unfinished = [];
    foreach (clog_metas('incoming') as $meta) {
        if ((int)$meta['account_id'] !== $accountId) {
            continue;
        }
        if ($meta['sha256'] === $sha256 && (int)$meta['size'] === $size) {
            clearstatcache(true, clog_path('incoming', $meta['id'], '.part'));
            $received = @filesize(clog_path('incoming', $meta['id'], '.part'));
            if ($received !== false) {
                launcher_json(200, ['upload' => $meta['id'], 'received' => $received, 'part_size' => CLOG_PART_SIZE]);
            }
            // A crash left the metadata without its file. No part could ever be written, so it starts over.
            clog_discard_incoming($meta['id']);
            continue;
        }
        $unfinished[] = $meta;
    }

    $free = @disk_free_space(clog_dir());
    if ($free !== false && $free < 2 * $size + 268435456) {
        launcher_json(507, ['error' => 'The realm is out of room for combat logs.']);
    }

    $now = time();
    $loggedUntil = $input['logged_until'] ?? null;
    $offset = $input['utc_offset_minutes'] ?? null;
    $rawSize = $input['raw_size'] ?? null;
    $id = bin2hex(random_bytes(16));
    $meta = [
        'id' => $id,
        'account_id' => $accountId,
        'account' => (string)$session['name'],
        'size' => $size,
        'sha256' => $sha256,
        'raw_size' => is_int($rawSize) && $rawSize >= 0 ? $rawSize : null,
        // A clock that's off by years would put the log in the wrong year: trust it within a month.
        'logged_until' => is_int($loggedUntil) && abs($loggedUntil - $now) < 31 * 86400 ? min($loggedUntil, $now) : $now,
        'utc_offset_minutes' => is_int($offset) && abs($offset) <= 900 ? $offset : 0,
        'client' => substr(preg_replace('/[^\x20-\x7e]/', '', (string)($input['client'] ?? '')) ?? '', 0, 64),
        'created_at' => $now,
    ];
    if (!clog_write_json(clog_path('incoming', $id, '.json'), $meta) || @file_put_contents(clog_path('incoming', $id, '.part'), '') === false) {
        clog_discard_incoming($id);
        launcher_json(503, ['error' => 'The realm can\'t store combat logs right now.']);
    }
    // Only now: nothing is deleted for an upload that's refused above.
    clog_make_room($finished, $unfinished, $size);
    launcher_json(200, ['upload' => $id, 'received' => 0, 'part_size' => CLOG_PART_SIZE]);
}

/** The account's own unfinished upload, or a 404 answer. */
function clog_incoming(array $session, string $id): array
{
    $meta = clog_valid_id($id) ? clog_read_json(clog_path('incoming', $id, '.json')) : null;
    if ($meta === null || (int)$meta['account_id'] !== (int)$session['id']) {
        launcher_json(404, ['error' => 'No such upload. Start it again.']);
    }
    return $meta;
}

function clog_part(array $session, string $id, string $offset): never
{
    $meta = clog_incoming($session, $id);
    if (preg_match('/^\d{1,12}$/', $offset) !== 1) {
        launcher_json(400, ['error' => 'Send the part\'s offset.']);
    }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > CLOG_PART_SIZE) {
        launcher_json(413, ['error' => 'That part is larger than part_size.']);
    }

    // The lock is held until the request ends, so two parts for one upload can't interleave. Begin made the
    // file: making it here would bring back one that was dropped, with no metadata to ever find it by.
    $file = @fopen(clog_path('incoming', $id, '.part'), 'r+b');
    if ($file === false || !flock($file, LOCK_EX)) {
        launcher_json(503, ['error' => 'The realm can\'t store combat logs right now.']);
    }
    $have = (int)fstat($file)['size'];
    if ((int)$offset !== $have) {
        launcher_json(409, ['error' => 'That part doesn\'t follow what was received.', 'received' => $have]);
    }

    fseek($file, $have);
    $input = fopen('php://input', 'rb');
    $wrote = $input === false ? false : stream_copy_to_stream($input, $file, CLOG_PART_SIZE + 1);
    if ($wrote === false || $wrote === 0) {
        ftruncate($file, $have);
        launcher_json(400, ['error' => 'The part was empty.']);
    }
    if ($wrote > CLOG_PART_SIZE || $have + $wrote > (int)$meta['size']) {
        ftruncate($file, $have);
        launcher_json(413, ['error' => 'That part goes past the size the upload announced.', 'received' => $have]);
    }
    fflush($file);
    launcher_json(200, ['received' => $have + $wrote]);
}

function clog_finish(array $session, string $id): never
{
    $meta = clog_incoming($session, $id);
    $part = clog_path('incoming', $id, '.part');

    $file = @fopen($part, 'rb');
    if ($file === false || !flock($file, LOCK_EX)) {
        launcher_json(503, ['error' => 'The realm can\'t store combat logs right now.']);
    }
    try {
        $gzip = fread($file, 2) === "\x1f\x8b";
        $size = (int)fstat($file)['size'];
        if ($size < (int)$meta['size']) {
            // Not all of it arrived yet: the launcher sends the rest and finishes again.
            launcher_json(409, ['error' => 'The upload isn\'t complete yet.', 'received' => $size]);
        }
        set_time_limit(0);
        if (!$gzip || $size !== (int)$meta['size'] || !hash_equals((string)$meta['sha256'], (string)hash_file('sha256', $part))) {
            clog_discard_incoming($id);
            launcher_json(422, ['error' => 'The upload doesn\'t match its size and checksum. Send it again.']);
        }

        // Only around the move, not the hash: begin must find this upload as unfinished or as finished.
        $lock = clog_lock_account((int)$meta['account_id']);
        $meta['completed_at'] = time();
        if (!@rename($part, clog_path('logs', $id, '.txt.gz')) || !clog_write_json(clog_path('logs', $id, '.json'), $meta)) {
            clog_delete_log($id);
            clog_discard_incoming($id);
            launcher_json(503, ['error' => 'The realm can\'t store combat logs right now.']);
        }
        @unlink(clog_path('incoming', $id, '.json'));
        fclose($lock);
    } finally {
        flock($file, LOCK_UN);
        fclose($file);
    }

    clog_spawn_digest($id);
    launcher_json(200, ['upload' => $id, 'complete' => true]);
}

/**
 * Starts the digest in the background, so the launcher's request doesn't wait for it. Where the
 * host doesn't allow that, the digest is made the first time someone opens the log instead.
 */
function clog_spawn_digest(string $id): void
{
    if (!clog_valid_id($id) || !function_exists('exec')
        || in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) {
        return;
    }
    $php = PHP_SAPI === 'cli' && PHP_BINARY !== '' ? PHP_BINARY : 'php';
    @exec(sprintf('%s %s %s > /dev/null 2>&1 &', escapeshellarg($php), escapeshellarg(__DIR__ . '/combatlog_cli.php'), escapeshellarg($id)));
}

/** The newest finished uploads with their summaries, which are all in memory at once. */
function clog_list(): array
{
    $logs = [];
    foreach (array_slice(clog_metas('logs'), 0, CLOG_LIST_ROWS) as $meta) {
        $log = clog_log($meta['id']);
        if ($log === null) {
            continue;
        }
        if (is_array($log['summary'] ?? null)) {
            $log['summary'] = clog_cap_summary($log['summary']);
        }
        $logs[] = $log;
    }
    return $logs;
}

/** A finished upload's metadata, or null. */
function clog_log(string $id): ?array
{
    $meta = clog_valid_id($id) ? clog_read_json(clog_path('logs', $id, '.json')) : null;
    return ($meta['id'] ?? null) === $id ? $meta : null;
}

/**
 * Whether a finished upload's <id>.json holds something other than its metadata, which keeps it off
 * the list and away from clog_prune. clog_write_json used to leave an empty one when a log's names
 * weren't valid UTF-8. A file that can't be read says nothing about its contents, so it doesn't count.
 * Nor does no <id>.json at all: that's an upload between its two steps in clog_finish.
 */
function clog_meta_damaged(string $id): bool
{
    $path = clog_path('logs', $id, '.json');
    clearstatcache(true, $path);
    $json = @file_get_contents($path);
    if ($json === false || strlen($json) !== @filesize($path)) {
        return false;
    }
    $meta = json_decode($json, true);
    return !is_array($meta) || (string)($meta['id'] ?? '') !== $id;
}

/** @return list<string> ids of the uploads clog_meta_damaged() is true for */
function clog_damaged_logs(): array
{
    $damaged = [];
    foreach (glob(clog_dir() . '/logs/*.txt.gz') ?: [] as $file) {
        $id = basename($file, '.txt.gz');
        if (clog_valid_id($id) && clog_meta_damaged($id)) {
            $damaged[] = $id;
        }
    }
    return $damaged;
}

/**
 * Writes a damaged upload's metadata again from what's left. The account that sent it and its PC's
 * offset from UTC are lost, so the account reads "unknown" and the log's times can be off by that offset.
 */
function clog_repair_meta(string $id): bool
{
    $path = clog_path('logs', $id, '.txt.gz');
    clearstatcache(true, $path);
    $size = @filesize($path);
    $time = @filemtime($path);
    $sha256 = @hash_file('sha256', $path);
    if ($size === false || $time === false || $sha256 === false) {
        return false;
    }
    $meta = [
        'id' => $id,
        'account_id' => 0,
        'account' => 'unknown',
        'size' => $size,
        'sha256' => $sha256,
        'raw_size' => null,
        'logged_until' => $time,
        'utc_offset_minutes' => 0,
        'client' => '',
        'created_at' => $time,
        'completed_at' => $time,
    ];
    // clog_digest won't touch a current digest, so the summary it would have written goes in here.
    // One that can't give a summary is corrupt too: without it, clog_digest makes a new one.
    $digestPath = clog_path('logs', $id, '.digest.json');
    $digest = clog_read_json($digestPath);
    if ($digest !== null && (int)($digest['version'] ?? 0) === CLOG_DIGEST_VERSION) {
        try {
            $meta['summary'] = clog_summary($digest);
        } catch (Throwable) {
            @unlink($digestPath);
        }
    }
    return clog_write_json(clog_path('logs', $id, '.json'), $meta);
}

/**
 * The version a digest was made by, from the start of the file, or 0 when it has lost its end: decoding
 * the whole thing to find out could run out of memory, and one made before digests had limits can be too big to.
 */
function clog_digest_version(string $id): int
{
    $file = @fopen(clog_path('logs', $id, '.digest.json'), 'rb');
    if ($file === false) {
        return 0;
    }
    $start = (string)fread($file, 32);
    fseek($file, -1, SEEK_END);
    $end = fread($file, 1);
    fclose($file);
    return $end === '}' && preg_match('/^\{"version":(\d+),/', $start, $parts) === 1 ? (int)$parts[1] : 0;
}

/**
 * The digest of a finished upload, made now when it's missing or from an older digest version.
 * Null while another request is making it, or when the log can't be read.
 */
function clog_digest(string $id): ?array
{
    $meta = clog_log($id);
    if ($meta === null) {
        return null;
    }
    $path = clog_path('logs', $id, '.digest.json');
    $digest = clog_read_json($path);
    if ($digest !== null && (int)($digest['version'] ?? 0) === CLOG_DIGEST_VERSION) {
        return $digest;
    }

    $lock = @fopen(clog_path('logs', $id, '.digest.lock'), 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        return null;
    }
    try {
        set_time_limit(0);
        try {
            $digest = clog_digest_file(clog_path('logs', $id, '.txt.gz'), (int)$meta['logged_until'], (int)$meta['utc_offset_minutes']);
        } catch (Throwable $e) {
            error_log('combat log ' . $id . ': ' . $e->getMessage());
            return null;
        }
        clog_enrich($digest);
        clog_write_json($path, $digest);

        $meta['summary'] = clog_summary($digest);
        clog_write_json(clog_path('logs', $id, '.json'), $meta);

        // Deleted while the digest was being made: those two writes brought the log back, so finish the job.
        clearstatcache(true, clog_path('logs', $id, '.txt.gz'));
        if (!is_file(clog_path('logs', $id, '.txt.gz'))) {
            clog_delete_log($id);
            return null;
        }
        return $digest;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
        @unlink(clog_path('logs', $id, '.digest.lock'));
    }
}

/** The few numbers the list of uploads shows, kept in the upload's metadata. */
function clog_summary(array $digest): array
{
    $kills = [];
    $wipes = [];
    foreach ($digest['fights'] as $fight) {
        if (!$fight['boss']) {
            continue;
        }
        if ($fight['outcome'] === 'kill') {
            $kills[$fight['name']] = true;
        } else {
            $wipes[$fight['name']] = ($wipes[$fight['name']] ?? 0) + 1;
        }
    }
    return clog_cap_summary([
        'version' => $digest['version'],
        'logger' => $digest['logger'],
        'start' => $digest['start'], 'end' => $digest['end'],
        'lines' => $digest['lines'],
        'fights' => count($digest['fights']),
        'deaths' => $digest['deaths'],
        'players' => count($digest['players']),
        'kills' => array_keys($kills),
        'wipes' => $wipes,
    ]);
}

/**
 * A summary as the list of uploads can take it, also for one stored before there were limits. The boss
 * names are text even when one reads as a number, which as an array key it turned into.
 */
function clog_cap_summary(array $summary): array
{
    $room = CLOG_SUMMARY_BYTES;
    $kills = [];
    foreach ($summary['kills'] ?? [] as $name) {
        $name = clog_cut_name((string)$name);
        $room -= strlen($name);
        if ($room < 0 || count($kills) >= CLOG_SUMMARY_NAMES) {
            break;
        }
        $kills[] = $name;
    }

    $room = CLOG_SUMMARY_BYTES;
    $wipes = [];
    foreach ($summary['wipes'] ?? [] as $name => $times) {
        $name = clog_cut_name((string)$name);
        $room -= strlen($name);
        if ($room < 0 || count($wipes) >= CLOG_SUMMARY_NAMES) {
            break;
        }
        $wipes[$name] = $times;
    }

    $summary['kills'] = $kills;
    $summary['wipes'] = $wipes;
    if (is_string($summary['logger'] ?? null)) {
        $summary['logger'] = clog_cut_name($summary['logger']);
    }
    return $summary;
}

/**
 * Adds what only the realm's databases know: which characters are bots, and which creatures are
 * bosses. Both are optional: without the grants (see docker/create-db-user.sql.example) the digest
 * keeps its own guess at the bosses and marks nobody as a bot.
 */
function clog_enrich(array &$digest): void
{
    try {
        $pdo = launcher_db();
    } catch (Throwable $e) {
        return;
    }

    $names = $digest['players'];
    if ($names) {
        try {
            $marks = implode(',', array_fill(0, count($names), '?'));
            $statement = $pdo->prepare('SELECT c.name FROM `' . str_replace('`', '', launcher_env('DB_CHARACTERS_NAME', 'acore_characters'))
                . '`.`characters` c JOIN ' . launcher_auth_table('account') . ' a ON a.id = c.account WHERE a.username LIKE ? AND c.name IN (' . $marks . ')');
            $statement->execute(array_merge([launcher_env('COMBATLOG_BOT_ACCOUNTS', 'RNDBOT%')], $names));
            $digest['bots'] = $statement->fetchAll(PDO::FETCH_COLUMN);
            sort($digest['bots']);
        } catch (Throwable $e) {
            // No access to the characters: nobody is marked.
        }
    }

    $entries = [];
    foreach ($digest['fights'] as $fight) {
        foreach ($fight['enemies'] as $enemy) {
            if ($enemy['entry'] > 0) {
                $entries[$enemy['entry']] = true;
            }
        }
    }
    if ($entries) {
        try {
            $world = '`' . str_replace('`', '', launcher_env('DB_WORLD_NAME', 'acore_world')) . '`';
            $list = implode(',', array_map('intval', array_keys($entries)));
            $bosses = $pdo->query('SELECT entry FROM ' . $world . '.`creature_template` WHERE `rank` = 3 AND entry IN (' . $list . ')'
                . ' UNION SELECT creditEntry FROM ' . $world . '.`instance_encounters` WHERE creditType = 0 AND creditEntry IN (' . $list . ')')
                ->fetchAll(PDO::FETCH_COLUMN);
            clog_apply_bosses($digest, array_map('intval', $bosses));
            $digest['bosses'] = 'realm';
        } catch (Throwable $e) {
            // No access to the world database: the digest's own guess stands.
        }
    }
}

/**
 * COMBATLOG_VIEW_KEY: whoever has it may read the logs (combatlogs.php, api/combatlogs.php) and delete
 * them (combatlogs.php). Empty = nobody.
 */
function clog_view_key(): string
{
    return trim(launcher_env('COMBATLOG_VIEW_KEY'));
}

function clog_key_matches(string $given): bool
{
    $key = clog_view_key();
    return strlen($key) >= 16 && hash_equals($key, $given);
}

/** An upload's metadata as the viewers get it. */
function clog_public_meta(array $meta): array
{
    return [
        'id' => $meta['id'],
        'account' => $meta['account'],
        'size' => $meta['size'],
        'raw_size' => $meta['raw_size'] ?? null,
        'logged_until' => $meta['logged_until'],
        'utc_offset_minutes' => $meta['utc_offset_minutes'],
        'uploaded_at' => $meta['completed_at'] ?? null,
        'client' => $meta['client'] ?? '',
        'summary' => $meta['summary'] ?? null,
    ];
}

/**
 * launcher_json for what's read from the logs. It writes non-ASCII names as they're stored, not as
 * \\uXXXX at three times the size, which a digest that fits in memory could then run out on.
 */
function clog_json(array $body): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, CLOG_JSON_FLAGS);
    exit;
}

/**
 * Sends an upload's digest straight from its file and exits, with a 503 while it's being made. Decoding
 * it to encode it again would hold it in memory twice, which is more than a digest that fits can take.
 */
function clog_send_digest(array $meta): never
{
    $id = $meta['id'];
    if (clog_digest_version($id) !== CLOG_DIGEST_VERSION) {
        clog_digest($id);
    }
    $handle = clog_digest_version($id) === CLOG_DIGEST_VERSION ? @fopen(clog_path('logs', $id, '.digest.json'), 'rb') : false;
    if ($handle === false) {
        header('Retry-After: 15');
        launcher_json(503, ['error' => 'The digest is being made. Try again in a moment.']);
    }
    set_time_limit(0);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo '{"log":', json_encode(clog_public_meta($meta), CLOG_JSON_FLAGS), ',"digest":';
    fpassthru($handle);
    echo '}';
    exit;
}

/** Sends the log as the launcher uploaded it (gzip) and exits. */
function clog_send_raw(array $meta): never
{
    $path = clog_path('logs', $meta['id'], '.txt.gz');
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        launcher_json(404, ['error' => 'That log is gone.']);
    }
    set_time_limit(0);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $name = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($meta['summary']['logger'] ?? $meta['account'])) ?: 'log';
    header('Content-Type: application/gzip');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="WoWCombatLog-' . $name . '-' . gmdate('Ymd-Hi', (int)$meta['logged_until']) . '.txt.gz"');
    header('Cache-Control: private, no-store');
    fpassthru($handle);
    exit;
}
