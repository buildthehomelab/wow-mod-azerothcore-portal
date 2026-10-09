<?php
/**
 * POST api/launcher/logout.php with "Authorization: Bearer <token>": forgets that token.
 **/

declare(strict_types=1);

require __DIR__ . '/../../application/launcher/launcher.php';

launcher_require_enabled();
launcher_require_method('POST');

$token = launcher_bearer_token();
if ($token !== '') {
    launcher_db()->prepare('DELETE FROM launcher_session WHERE token_hash = ?')->execute([hash('sha256', $token, true)]);
}
http_response_code(204);
header('Cache-Control: no-store');
