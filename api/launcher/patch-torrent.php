<?php
/**
 * GET api/launcher/patch-torrent.php?file=patch-P.MPQ with "Authorization: Bearer <token>"
 *
 * A torrent for one patch MPQ in the realm folder (the files realm.conf points Portalkeeper at), with
 * this player's passkey in the announce URL. The web seed is the patch's normal public URL under
 * /realm/, so Apache serves it and the download works even when nobody else is sharing. Portalkeeper
 * still checks the SHA-256 from realm.conf before installing.
 **/

declare(strict_types=1);

require __DIR__ . '/../../application/launcher/launcher.php';

launcher_require_enabled();
launcher_require_method('GET');

$pdo = launcher_db();
$session = launcher_require_session($pdo);

$file = (string)($_GET['file'] ?? '');
$patch = launcher_patch_torrent($pdo, $file);
if ($patch === null) {
    launcher_json(404, ['error' => 'There is no patch called ' . $file . '.']);
}

$passkey = launcher_passkey($pdo, $session['id']);
$torrent = [
    'announce' => launcher_base_url() . '/api/launcher/announce.php/' . $passkey,
    'created by' => 'Vaultrona portal',
    'info' => new BencodeRaw($patch['raw_info']),
    // BEP 19 single-file form: the file's own URL. (MonoTorrent turns the "folder/" form into "folder/name/".)
    'url-list' => [launcher_patch_url() . rawurlencode($patch['name'])],
];

header('Content-Type: application/x-bittorrent');
header('Content-Disposition: attachment; filename="' . $patch['name'] . '.torrent"');
header('Cache-Control: no-store');
header('X-Info-Hash: ' . bin2hex($patch['info_hash']));
echo bencode($torrent);
