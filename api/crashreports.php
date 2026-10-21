<?php
/**
 * Read access to the crash reports players sent from Portalkeeper, for scripts. Needs the header
 * "X-Portal-Key: <CRASHREPORT_VIEW_KEY, else COMBATLOG_VIEW_KEY>"; answers 404 without crash reports
 * turned on or without a key set.
 *
 *   GET api/crashreports.php                  the reports, newest first (summary, files' names and sizes)
 *   GET api/crashreports.php?report=<id>      one report, with each error file's text
 **/

declare(strict_types=1);

require __DIR__ . '/../application/launcher/crashreport.php';

if (!crash_enabled() || crash_view_key() === '') {
    launcher_json(404, ['error' => 'Crash reports aren\'t turned on for this realm.']);
}
launcher_require_method('GET');
if (!crash_key_matches((string)($_SERVER['HTTP_X_PORTAL_KEY'] ?? ''))) {
    launcher_json(401, ['error' => 'Send the view key as X-Portal-Key.']);
}

$id = (string)($_GET['report'] ?? '');
if ($id === '') {
    launcher_json(200, ['reports' => crash_metas()]);
}
$report = crash_read($id);
if ($report === null) {
    launcher_json(404, ['error' => 'No such crash report.']);
}
launcher_json(200, ['report' => crash_public_meta($report), 'files' => $report['files']]);
