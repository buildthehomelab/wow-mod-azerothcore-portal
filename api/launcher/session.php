<?php
/**
 * GET api/launcher/session.php with "Authorization: Bearer <token>"
 *
 * Who the token belongs to: {"account": {"id", "name"}, "expires_at"}, or 401 when it has expired
 * (Portalkeeper then shows its login screen again) and 403 for banned accounts.
 **/

declare(strict_types=1);

require __DIR__ . '/../../application/launcher/launcher.php';

launcher_require_enabled();
launcher_require_method('GET');

$session = launcher_require_session(launcher_db());
launcher_json(200, [
    'account' => ['id' => $session['id'], 'name' => $session['name']],
    'expires_at' => $session['expires_at'],
]);
