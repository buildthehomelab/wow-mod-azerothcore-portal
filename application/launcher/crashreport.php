<?php
/**
 * Crash reports from Portalkeeper: when World of Warcraft crashes, the launcher sends the error
 * report the game wrote (Errors/*.txt) after the player agreed to it. Read back through
 * api/crashreports.php with the view key.
 *
 * Each report is one file in CRASHREPORT_DIR, <id>.json: the account, when it happened, a one-line
 * summary ("ERROR #132 · ACCESS_VIOLATION at 0081D559") and the text of each error file. Nothing is
 * added to the database.
 **/

declare(strict_types=1);

require_once __DIR__ . '/launcher.php';

const CRASH_MAX_FILES = 4;            // error files per report
const CRASH_MAX_FILE_BYTES = 262144;  // per file; the launcher cuts longer ones to this
const CRASH_MAX_BODY = 1572864;       // the whole request, JSON included
const CRASH_PER_ACCOUNT_DAY = 10;     // reports per account per 24 hours

function crash_enabled(): bool
{
    return launcher_enabled() && filter_var(launcher_env('CRASHREPORT_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
}

function crash_dir(): string
{
    return rtrim(launcher_env('CRASHREPORT_DIR', '/srv/crashreports'), '/');
}

/** All reports together may take this much room (CRASHREPORT_MAX_MB, default 200); past it new ones are refused. */
function crash_max_bytes(): int
{
    return max(1, (int)launcher_env('CRASHREPORT_MAX_MB', '200')) * 1048576;
}

/** Days a report is kept (CRASHREPORT_KEEP_DAYS, default 90; 0 keeps them forever). */
function crash_keep_days(): int
{
    return max(0, (int)launcher_env('CRASHREPORT_KEEP_DAYS', '90'));
}

/** CRASHREPORT_VIEW_KEY, else the combat logs' COMBATLOG_VIEW_KEY. Empty = nobody may read them. */
function crash_view_key(): string
{
    return trim(launcher_env('CRASHREPORT_VIEW_KEY', launcher_env('COMBATLOG_VIEW_KEY')));
}

function crash_key_matches(string $given): bool
{
    $key = crash_view_key();
    return strlen($key) >= 16 && hash_equals($key, $given);
}

function crash_valid_id(string $id): bool
{
    return preg_match('/^[0-9a-f]{32}$/', $id) === 1;
}

function crash_read(string $id): ?array
{
    if (!crash_valid_id($id)) {
        return null;
    }
    $data = json_decode((string)@file_get_contents(crash_dir() . '/' . $id . '.json'), true);
    return is_array($data) ? $data : null;
}

/** Every report, newest first, without the files' text. */
function crash_metas(): array
{
    $metas = [];
    foreach (glob(crash_dir() . '/*.json') ?: [] as $file) {
        $report = json_decode((string)@file_get_contents($file), true);
        if (is_array($report) && crash_valid_id((string)($report['id'] ?? ''))) {
            $metas[] = crash_public_meta($report);
        }
    }
    usort($metas, static fn(array $a, array $b): int => $b['received_at'] <=> $a['received_at']);
    return $metas;
}

function crash_public_meta(array $report): array
{
    return [
        'id' => $report['id'],
        'account' => $report['account'],
        'happened_at' => $report['happened_at'],
        'received_at' => $report['received_at'],
        'client' => $report['client'],
        'summary' => $report['summary'],
        'files' => array_map(static fn(array $f): array => ['name' => $f['name'], 'size' => strlen($f['text'])], $report['files']),
    ];
}

/** Drops reports past CRASHREPORT_KEEP_DAYS. */
function crash_prune(): void
{
    $days = crash_keep_days();
    if ($days === 0) {
        return;
    }
    foreach (glob(crash_dir() . '/*.json') ?: [] as $file) {
        if (time() - (int)@filemtime($file) > $days * 86400) {
            @unlink($file);
        }
    }
}

/**
 * The line that says what went wrong, from the game's error report: "ERROR #132 · ACCESS_VIOLATION at
 * 0081D559", or the first line that names an error. Empty when there's none.
 */
function crash_summary(string $text): string
{
    $parts = [];
    if (preg_match('/ERROR\s+#(\d+)/', $text, $error)) {
        $parts[] = 'ERROR #' . $error[1];
    }
    if (preg_match('/Exception:\s*0x[0-9A-Fa-f]+\s*\(([A-Z_]+)\)\s*at\s*[0-9A-Fa-f]+:([0-9A-Fa-f]+)/', $text, $exception)) {
        $parts[] = $exception[1] . ' at ' . strtoupper($exception[2]);
    }
    if ($parts) {
        return implode(' · ', $parts);
    }
    return preg_match('/^.*\berror\b.*$/mi', $text, $line) ? mb_strcut(trim($line[0]), 0, 160, 'UTF-8') : '';
}

/**
 * Takes the player's Windows name and computer name out of an error report, whatever the launcher
 * already did: the "User:" and "Computer:" lines, and the folder under Users\ in any path.
 */
function crash_redact(string $text): string
{
    $text = preg_replace('/^(\s*(?:User|Computer)\s*:).*$/mi', '$1 (removed)', $text);
    return preg_replace('/([\\\\\/]Users[\\\\\/])[^\\\\\/\r\n"]+/i', '$1(user)', $text);
}

/**
 * POST api/launcher/crashreport.php for a logged-in account: stores the report and answers
 * {"id", "summary"} (also for one already sent). Answers and exits.
 *
 * @param array{id:int, name:string} $session from launcher_require_session()
 */
function crash_receive(array $session): never
{
    $body = file_get_contents('php://input', false, null, 0, CRASH_MAX_BODY + 1);
    if ($body === false || strlen($body) > CRASH_MAX_BODY) {
        launcher_json(413, ['error' => 'That crash report is too big.']);
    }
    $request = json_decode($body, true);
    $files = is_array($request) ? ($request['files'] ?? null) : null;
    if (!is_array($files) || !$files || count($files) > CRASH_MAX_FILES || !array_is_list($files)) {
        launcher_json(400, ['error' => 'Send between 1 and ' . CRASH_MAX_FILES . ' error files.']);
    }

    $clean = [];
    foreach ($files as $file) {
        $name = is_array($file) ? (string)($file['name'] ?? '') : '';
        $text = is_array($file) ? ($file['text'] ?? null) : null;
        if (!preg_match('/^[\w .()\-]{1,100}$/', $name) || !is_string($text) || $text === ''
            || strlen($text) > CRASH_MAX_FILE_BYTES || !mb_check_encoding($text, 'UTF-8')) {
            launcher_json(400, ['error' => 'An error file has a bad name or isn\'t text.']);
        }
        $clean[] = ['name' => $name, 'text' => crash_redact($text)];
    }

    $dir = crash_dir();
    if ((!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) || !is_writable($dir)) {
        error_log('crashreport: CRASHREPORT_DIR ' . $dir . ' can\'t be written');
        launcher_json(503, ['error' => 'The realm can\'t store crash reports right now.']);
    }
    crash_prune();

    // One report at a time, so the daily count and the room left can't be raced past.
    $lock = @fopen($dir . '/.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        launcher_json(503, ['error' => 'The realm can\'t store crash reports right now.']);
    }

    // The same report sent twice (a retry after a lost answer) is stored once.
    $hash = hash('sha256', json_encode($clean));
    $id = substr(hash('sha256', $session['id'] . ':' . $hash), 0, 32);
    if (($existing = crash_read($id)) !== null) {
        launcher_json(200, ['id' => $id, 'summary' => $existing['summary']]);
    }
    $today = 0;
    $used = 0;
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $used += (int)@filesize($file);
        // Only reports from the last day are opened to see whose they are.
        if (time() - (int)@filemtime($file) < 86400
            && (int)(json_decode((string)@file_get_contents($file), true)['account_id'] ?? 0) === (int)$session['id']) {
            $today++;
        }
    }
    if ($today >= CRASH_PER_ACCOUNT_DAY) {
        launcher_json(429, ['error' => 'That\'s enough crash reports for today. Thank you!']);
    }
    if ($used + strlen($body) * 2 > crash_max_bytes()) {
        error_log('crashreport: CRASHREPORT_MAX_MB reached; new reports are refused');
        launcher_json(507, ['error' => 'The realm can\'t store more crash reports right now.']);
    }

    $happened = (int)($request['happened_at'] ?? 0);
    $client = (string)($request['client'] ?? '');
    $report = [
        'id' => $id,
        'account_id' => $session['id'],
        'account' => $session['name'],
        'happened_at' => $happened > 0 && $happened <= time() + 300 ? $happened : null,
        'received_at' => time(),
        'client' => preg_match('/^[\x20-\x7E]{1,64}$/', $client) ? $client : '',
        'summary' => crash_summary(implode("\n", array_column($clean, 'text'))),
        'files' => $clean,
    ];
    $path = $dir . '/' . $id . '.json';
    $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $json = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || @file_put_contents($temp, $json) === false || !@rename($temp, $path)) {
        @unlink($temp);
        launcher_json(507, ['error' => 'The realm can\'t store crash reports right now.']);
    }
    launcher_json(200, ['id' => $id, 'summary' => $report['summary']]);
}
