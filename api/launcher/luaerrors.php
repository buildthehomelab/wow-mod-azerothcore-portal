<?php
/**
 * Lua errors from Portalkeeper, with "Authorization: Bearer <token>".
 *
 *   GET  api/launcher/luaerrors.php    {"enabled": true}: the realm takes Lua errors (404 when it doesn't;
 *                                      the launcher then doesn't record any)
 *   POST api/launcher/luaerrors.php    {"client": "Portalkeeper/x.y.z", "dropped": 0, "suppressed": 0,
 *                                       "sessions": [{"character", "realm", "build", "locale", "started", "addons": ["Name 1.2"]}],
 *                                       "errors": [{"kind": "error"|"blocked", "message", "stack", "locals",
 *                                                   "count", "first", "last", "session": index in sessions}]}
 *                                      → {"id", "errors"}; the same batch again answers the same id.
 *                                      400 malformed, 413 too big, 429 too many today, 503/507 can't store.
 **/

declare(strict_types=1);

require __DIR__ . '/../../application/launcher/luaerrors.php';

launcher_require_enabled();
if (!luaerr_enabled()) {
    launcher_json(404, ['error' => 'This realm doesn\'t collect Lua errors.']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');
    launcher_json(405, ['error' => 'Use GET or POST.']);
}

$session = launcher_require_session(launcher_db());
if ($method === 'GET') {
    launcher_json(200, ['enabled' => true]);
}
luaerr_receive($session);
