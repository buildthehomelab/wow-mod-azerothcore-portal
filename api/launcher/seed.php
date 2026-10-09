<?php
/**
 * HTTP web seed (BEP 19): api/launcher/seed.php/<passkey>/<torrent name>/<path in the torrent>
 *
 * Serves the client files in LAUNCHER_CLIENT_DIR with byte ranges, so downloads keep going when no
 * other player is sharing. Torrent clients ask for one range per request; a request for several
 * ranges gets the whole file.
 **/

declare(strict_types=1);

require __DIR__ . '/../../application/launcher/launcher.php';

const SEED_CHUNK = 1048576;

function seed_fail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message . "\n";
    exit;
}

if (!launcher_enabled()) {
    seed_fail(404, 'The launcher service is turned off.');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    seed_fail(405, 'Use GET.');
}

$parts = explode('/', ltrim((string)($_SERVER['PATH_INFO'] ?? ''), '/'));
$passkey = (string)array_shift($parts);
if (launcher_passkey_account(launcher_db(), $passkey) === 0) {
    seed_fail(403, 'Unknown passkey. Log in to Portalkeeper again.');
}

foreach ($parts as $part) {
    if ($part === '' || $part === '.' || $part === '..' || str_contains($part, "\0") || str_contains($part, '\\')) {
        seed_fail(404, 'No such file.');
    }
}

$root = realpath(launcher_client_dir());
$path = $root === false ? false : realpath($root . '/' . implode('/', $parts));
if ($root === false || $path === false || !str_starts_with($path, $root . '/') || !is_file($path)) {
    seed_fail(404, 'No such file.');
}

$size = filesize($path);
$start = 0;
$end = $size - 1;
$status = 200;

$range = (string)($_SERVER['HTTP_RANGE'] ?? '');
if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $match) && ($match[1] !== '' || $match[2] !== '')) {
    if ($match[1] === '') {          // bytes=-N: the last N bytes
        $start = max(0, $size - (int)$match[2]);
    } else {
        $start = (int)$match[1];
        if ($match[2] !== '') {
            $end = min($end, (int)$match[2]);
        }
    }
    if ($start > $end || $start >= $size) {
        header('Content-Range: bytes */' . $size);
        seed_fail(416, 'Range not satisfiable.');
    }
    $status = 206;
}

set_time_limit(0);
while (ob_get_level() > 0) {
    ob_end_clean();
}

http_response_code($status);
header('Content-Type: application/octet-stream');
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . ($end - $start + 1));
if ($status === 206) {
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}
if ($method === 'HEAD') {
    exit;
}

$handle = fopen($path, 'rb');
if ($handle === false) {
    exit;
}
fseek($handle, $start);
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($handle) && !connection_aborted()) {
    $chunk = fread($handle, min(SEED_CHUNK, $remaining));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    flush();
    $remaining -= strlen($chunk);
}
fclose($handle);
