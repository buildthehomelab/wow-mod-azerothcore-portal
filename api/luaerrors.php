<?php
/**
 * Read access to the Lua errors players' launchers sent, for scripts. Needs the header
 * "X-Portal-Key: <LUAERROR_VIEW_KEY, else COMBATLOG_VIEW_KEY>"; answers 404 without Lua errors turned
 * on or without a key set.
 *
 *   GET api/luaerrors.php                       each distinct error, most recently seen first
 *   GET api/luaerrors.php?error=<fingerprint>   one error, with every sighting's stack, locals and login
 *   GET api/luaerrors.php?batches=1             the batches as they came in, newest first
 *   GET api/luaerrors.php?batch=<id>            one batch as it came in
 **/

declare(strict_types=1);

require __DIR__ . '/../application/launcher/luaerrors.php';

if (!luaerr_enabled() || luaerr_view_key() === '') {
    launcher_json(404, ['error' => 'Lua errors aren\'t turned on for this realm.']);
}
launcher_require_method('GET');
if (!luaerr_key_matches((string)($_SERVER['HTTP_X_PORTAL_KEY'] ?? ''))) {
    launcher_json(401, ['error' => 'Send the view key as X-Portal-Key.']);
}

if (isset($_GET['batch'])) {
    $batch = is_string($_GET['batch']) ? luaerr_read($_GET['batch']) : null;
    if ($batch === null) {
        launcher_json(404, ['error' => 'No such batch.']);
    }
    unset($batch['account_id']);
    launcher_json(200, ['batch' => $batch]);
}
if (!empty($_GET['batches'])) {
    launcher_json(200, ['batches' => luaerr_batch_metas()]);
}
if (isset($_GET['error'])) {
    $group = is_string($_GET['error']) ? (luaerr_groups($_GET['error'])[0] ?? null) : null;
    if ($group === null) {
        launcher_json(404, ['error' => 'No such error.']);
    }
    launcher_json(200, ['error' => $group]);
}
launcher_json(200, ['errors' => luaerr_groups()]);
