<?php
/**
 * Passes mod-rare-tracker's live rare list through to the browser.
 *
 * The list lives in the worldserver's memory and is served on the AzerothCore Docker network
 * (RARE_TRACKER_URL, e.g. http://ac-worldserver:8095/rares.json), which browsers can't reach.
 * X-Server-Time lets the page count respawn timers down against the server's clock.
 **/

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Server-Time: ' . time());

$url = getenv('RARE_TRACKER_URL') ?: '';
if ($url === '') {
    http_response_code(404);
    echo '{"error":"The rare map is turned off."}';
    exit;
}

$context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
$body = @file_get_contents($url, false, $context);

// PHP 8.4 deprecates $http_response_header in favour of http_get_last_response_headers().
$headers = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
$status = 0;
if (isset($headers[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $headers[0], $match)) {
    $status = (int)$match[1];
}

if ($body === false || $status !== 200) {
    http_response_code(502);
    echo '{"error":"The world server isn\'t answering."}';
    exit;
}

echo $body;
