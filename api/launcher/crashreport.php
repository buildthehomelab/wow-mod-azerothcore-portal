<?php
/**
 * Crash reports from Portalkeeper, with "Authorization: Bearer <token>".
 *
 *   GET  api/launcher/crashreport.php    {"enabled": true, "max_files", "max_file_size"}: the realm takes
 *                                        crash reports (404 when it doesn't; the launcher then never offers)
 *   POST api/launcher/crashreport.php    {"happened_at": unix time, "client": "Portalkeeper/x.y.z",
 *                                         "files": [{"name": "2026-10-13 21-35-12.txt", "text": "..."}]}
 *                                        → {"id", "summary"}; the same report again answers the same id.
 *                                        400 malformed, 413 too big, 429 too many today, 503/507 can't store.
 **/

declare(strict_types=1);

require __DIR__ . '/../../application/launcher/crashreport.php';

launcher_require_enabled();
if (!crash_enabled()) {
    launcher_json(404, ['error' => 'This realm doesn\'t collect crash reports.']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');
    launcher_json(405, ['error' => 'Use GET or POST.']);
}

$session = launcher_require_session(launcher_db());
if ($method === 'GET') {
    launcher_json(200, ['enabled' => true, 'max_files' => CRASH_MAX_FILES, 'max_file_size' => CRASH_MAX_FILE_BYTES]);
}
crash_receive($session);
