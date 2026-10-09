<?php
/**
 * Private BitTorrent tracker (BEP 3, compact peers per BEP 23 and BEP 7).
 * Announce URL: api/launcher/announce.php/<passkey>, handed out inside torrent.php's torrents.
 *
 * Only torrents in LAUNCHER_TORRENT_DIR are tracked, and only players with a passkey (a launcher
 * login) on an account that isn't banned get peers. Players on the server's own network are only
 * handed to each other: their private addresses mean nothing to anyone outside.
 **/

declare(strict_types=1);

require __DIR__ . '/../../application/launcher/launcher.php';

const TRACKER_INTERVAL = 1800;
const TRACKER_MIN_INTERVAL = 300;
const TRACKER_DEFAULT_PEERS = 50;
const TRACKER_MAX_PEERS = 100;

function tracker_reply(array $body): never
{
    header('Content-Type: text/plain');
    header('Cache-Control: no-store');
    echo bencode($body);
    exit;
}

function tracker_fail(string $reason): never
{
    tracker_reply(['failure reason' => $reason]);
}

if (!launcher_enabled()) {
    tracker_fail('The launcher service is turned off.');
}

$pdo = launcher_db();
$accountId = launcher_passkey_account($pdo, trim((string)($_SERVER['PATH_INFO'] ?? ''), '/'));
if ($accountId === 0) {
    tracker_fail('Unknown passkey. Log in to Portalkeeper again.');
}

$infoHash = (string)($_GET['info_hash'] ?? '');
$peerId = (string)($_GET['peer_id'] ?? '');
$port = (int)($_GET['port'] ?? 0);
if (strlen($infoHash) !== 20 || strlen($peerId) !== 20 || $port < 1 || $port > 65535) {
    tracker_fail('Malformed announce.');
}

$tracked = false;
foreach (launcher_torrents() as $entry) {
    if (hash_equals($entry['info_hash'], $infoHash)) {
        $tracked = true;
        break;
    }
}
if (!$tracked) {
    tracker_fail('This tracker only serves the realm\'s own torrents.');
}

$ip = launcher_client_ip();
$left = max(0, (int)($_GET['left'] ?? 0));
$now = time();
$event = (string)($_GET['event'] ?? '');

if ($event === 'stopped') {
    $pdo->prepare('DELETE FROM launcher_peer WHERE info_hash = ? AND peer_id = ?')->execute([$infoHash, $peerId]);
    tracker_reply(['interval' => TRACKER_INTERVAL, 'peers' => '']);
}

$pdo->prepare('INSERT INTO launcher_peer (info_hash, peer_id, account_id, ip, port, uploaded, downloaded, bytes_left, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE account_id = VALUES(account_id), ip = VALUES(ip), port = VALUES(port),
            uploaded = VALUES(uploaded), downloaded = VALUES(downloaded), bytes_left = VALUES(bytes_left), updated_at = VALUES(updated_at)')
    ->execute([$infoHash, $peerId, $accountId, $ip, $port,
        max(0, (int)($_GET['uploaded'] ?? 0)), max(0, (int)($_GET['downloaded'] ?? 0)), $left, $now]);

if (random_int(1, 50) === 1) {
    $pdo->prepare('DELETE FROM launcher_peer WHERE updated_at < ?')->execute([$now - 2 * TRACKER_INTERVAL]);
}

$fresh = $now - 2 * TRACKER_INTERVAL;
$counts = $pdo->prepare('SELECT SUM(bytes_left = 0) AS complete, SUM(bytes_left > 0) AS incomplete
        FROM launcher_peer WHERE info_hash = ? AND updated_at > ?');
$counts->execute([$infoHash, $fresh]);
$count = $counts->fetch();

$numwant = (int)($_GET['numwant'] ?? TRACKER_DEFAULT_PEERS);
$numwant = max(0, min(TRACKER_MAX_PEERS, $numwant ?: TRACKER_DEFAULT_PEERS));

// Seeders don't need other seeders.
$peers = $pdo->prepare('SELECT ip, port FROM launcher_peer WHERE info_hash = ? AND peer_id <> ? AND updated_at > ?'
    . ($left === 0 ? ' AND bytes_left > 0' : '') . ' ORDER BY RAND() LIMIT ' . (TRACKER_MAX_PEERS * 2));
$peers->execute([$infoHash, $peerId, $fresh]);

$requesterPrivate = launcher_is_private_ip($ip);
$peers4 = '';
$peers6 = '';
$given = 0;
foreach ($peers as $peer) {
    if ($given >= $numwant) {
        break;
    }
    $peerIp = (string)$peer['ip'];
    if ($peerIp === $ip && (int)$peer['port'] === $port) {
        continue;
    }
    if (!$requesterPrivate && launcher_is_private_ip($peerIp)) {
        continue;
    }
    $packed = @inet_pton($peerIp);
    if ($packed === false) {
        continue;
    }
    $entry = $packed . pack('n', (int)$peer['port']);
    if (strlen($packed) === 4) {
        $peers4 .= $entry;
    } else {
        $peers6 .= $entry;
    }
    $given++;
}

$reply = [
    'interval' => TRACKER_INTERVAL,
    'min interval' => TRACKER_MIN_INTERVAL,
    'complete' => (int)($count['complete'] ?? 0),
    'incomplete' => (int)($count['incomplete'] ?? 0),
    'peers' => $peers4,
];
if ($peers6 !== '') {
    $reply['peers6'] = $peers6;
}
tracker_reply($reply);
