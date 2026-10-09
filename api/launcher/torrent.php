<?php
/**
 * GET api/launcher/torrent.php?name=client with "Authorization: Bearer <token>"
 *
 * The named torrent from LAUNCHER_TORRENT_DIR (client.torrent → name=client), rewritten for this
 * player: its announce URL and web seed carry the player's passkey. Those keys sit outside the info
 * dictionary, which is copied byte for byte, so every player's copy has the same info hash and they
 * all share one swarm.
 *
 * GET without a name lists what's available: {"torrents": [{"name", "info_hash", "size"}]}.
 **/

declare(strict_types=1);

require __DIR__ . '/../../application/launcher/launcher.php';

launcher_require_enabled();
launcher_require_method('GET');

$pdo = launcher_db();
$session = launcher_require_session($pdo);
$torrents = launcher_torrents();

$name = (string)($_GET['name'] ?? '');
if ($name === '') {
    $list = [];
    foreach ($torrents as $torrentName => $entry) {
        $info = $entry['torrent']['info'];
        $size = isset($info['files']) && is_array($info['files'])
            ? array_sum(array_map(static fn($file) => (int)($file['length'] ?? 0), $info['files']))
            : (int)($info['length'] ?? 0);
        $list[] = ['name' => $torrentName, 'info_hash' => bin2hex($entry['info_hash']), 'size' => $size];
    }
    launcher_json(200, ['torrents' => $list]);
}

if (!isset($torrents[$name])) {
    launcher_json(404, ['error' => 'There is no torrent called ' . $name . '.']);
}

$entry = $torrents[$name];
$passkey = launcher_passkey($pdo, $session['id']);
$base = launcher_seed_url() . '/api/launcher';

$torrent = $entry['torrent'];
unset($torrent['announce-list']);
$torrent['announce'] = $base . '/announce.php/' . $passkey;
// BEP 19: for a multi-file torrent the client appends "<name>/<path>" to a URL ending in "/".
$torrent['url-list'] = [$base . '/seed.php/' . $passkey . '/'];
$torrent['info'] = new BencodeRaw($entry['raw_info']);

header('Content-Type: application/x-bittorrent');
header('Content-Disposition: attachment; filename="' . $name . '.torrent"');
header('Cache-Control: no-store');
header('X-Info-Hash: ' . bin2hex($entry['info_hash']));
echo bencode($torrent);
