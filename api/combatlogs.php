<?php
/**
 * Read access to the uploaded combat logs, for scripts. Needs the header
 * "X-Portal-Key: <COMBATLOG_VIEW_KEY>"; answers 404 without combat logs turned on or without a key set.
 *
 *   GET api/combatlogs.php                       the newest uploads, newest first
 *   GET api/combatlogs.php?log=<id>              one upload's digest (fights, deaths, mechanics)
 *   GET api/combatlogs.php?log=<id>&raw=1        the log itself, gzip
 **/

declare(strict_types=1);

require __DIR__ . '/../application/launcher/combatlog.php';

if (!clog_enabled() || clog_view_key() === '') {
    launcher_json(404, ['error' => 'Combat logs aren\'t turned on for this realm.']);
}
launcher_require_method('GET');
if (!clog_key_matches((string)($_SERVER['HTTP_X_PORTAL_KEY'] ?? ''))) {
    launcher_json(401, ['error' => 'Send the view key as X-Portal-Key.']);
}

$id = (string)($_GET['log'] ?? '');
if ($id === '') {
    clog_json(['logs' => array_map('clog_public_meta', clog_list())]);
}

$meta = clog_log($id);
if ($meta === null) {
    launcher_json(404, ['error' => 'No such combat log.']);
}
if (!empty($_GET['raw'])) {
    clog_send_raw($meta);
}

clog_send_digest($meta);
