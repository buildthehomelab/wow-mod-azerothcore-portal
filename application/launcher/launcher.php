<?php
/**
 * Shared code for the launcher API (api/launcher/*): game-account login for Portalkeeper, plus the
 * private BitTorrent tracker and web seed that hand out the WoW client to logged-in players.
 *
 * Its tables live in their own database (LAUNCHER_DB_NAME, default acore_launcher) and are created on
 * first use. The game accounts are read from DB_AUTH_NAME on the same connection.
 **/

declare(strict_types=1);

require_once __DIR__ . '/../include/functions.php'; // verifySRP6()
require_once __DIR__ . '/bencode.php';

const LAUNCHER_SCHEMA_VERSION = 2;

function launcher_env(string $key, string $default = ''): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function launcher_enabled(): bool
{
    return filter_var(launcher_env('LAUNCHER_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
}

function launcher_base_url(): string
{
    return rtrim(launcher_env('BASE_URL', 'http://localhost:8080'), '/');
}

function launcher_token_days(): int
{
    return max(1, (int)launcher_env('LAUNCHER_TOKEN_DAYS', '30'));
}

function launcher_json(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

/** JSON endpoints answer 404 while the launcher service is off. */
function launcher_require_enabled(): void
{
    if (!launcher_enabled()) {
        launcher_json(404, ['error' => 'The launcher service is turned off.']);
    }
}

function launcher_require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        header('Allow: ' . $method);
        launcher_json(405, ['error' => 'Use ' . $method . '.']);
    }
}

function launcher_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            launcher_env('DB_HOST', 'ac-database'),
            launcher_env('DB_PORT', '3306'),
            launcher_env('LAUNCHER_DB_NAME', 'acore_launcher')
        );
        $pdo = new PDO($dsn, launcher_env('DB_USER', 'wow_register'), launcher_env('DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        launcher_schema($pdo);
    }
    return $pdo;
}

/** `acore_auth`.`account` and friends, read through the launcher connection. */
function launcher_auth_table(string $table): string
{
    return '`' . str_replace('`', '', launcher_env('DB_AUTH_NAME', 'acore_auth')) . '`.`' . $table . '`';
}

/**
 * Creates the tables once per container: a flag file in the temp folder remembers that this
 * schema version is in place, so normal requests don't pay for the CREATE TABLE checks.
 */
function launcher_schema(PDO $pdo): void
{
    $flag = sys_get_temp_dir() . '/portal-launcher-schema-' . LAUNCHER_SCHEMA_VERSION . '-'
        . md5(launcher_env('DB_HOST') . '/' . launcher_env('LAUNCHER_DB_NAME', 'acore_launcher'));
    if (is_file($flag)) {
        return;
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS launcher_session (
        token_hash BINARY(32) NOT NULL PRIMARY KEY,
        account_id INT UNSIGNED NOT NULL,
        created_at INT UNSIGNED NOT NULL,
        expires_at INT UNSIGNED NOT NULL,
        last_ip VARCHAR(45) NOT NULL,
        KEY account (account_id),
        KEY expires (expires_at)
    ) ENGINE=InnoDB');

    $pdo->exec('CREATE TABLE IF NOT EXISTS launcher_login_failure (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(32) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        at INT UNSIGNED NOT NULL,
        KEY username_at (username, at),
        KEY ip_at (ip, at)
    ) ENGINE=InnoDB');

    $pdo->exec('CREATE TABLE IF NOT EXISTS launcher_passkey (
        account_id INT UNSIGNED NOT NULL PRIMARY KEY,
        passkey CHAR(32) NOT NULL,
        created_at INT UNSIGNED NOT NULL,
        UNIQUE KEY passkey (passkey)
    ) ENGINE=InnoDB');

    $pdo->exec('CREATE TABLE IF NOT EXISTS launcher_peer (
        info_hash BINARY(20) NOT NULL,
        peer_id BINARY(20) NOT NULL,
        account_id INT UNSIGNED NOT NULL,
        ip VARCHAR(45) NOT NULL,
        lan_ip VARCHAR(45) NULL,
        port SMALLINT UNSIGNED NOT NULL,
        uploaded BIGINT UNSIGNED NOT NULL DEFAULT 0,
        downloaded BIGINT UNSIGNED NOT NULL DEFAULT 0,
        bytes_left BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at INT UNSIGNED NOT NULL,
        PRIMARY KEY (info_hash, peer_id),
        KEY updated (updated_at)
    ) ENGINE=InnoDB');

    $pdo->exec('CREATE TABLE IF NOT EXISTS launcher_patch_torrent (
        name VARCHAR(255) NOT NULL PRIMARY KEY,
        size BIGINT UNSIGNED NOT NULL,
        mtime BIGINT NOT NULL,
        info_hash BINARY(20) NOT NULL,
        raw_info MEDIUMBLOB NOT NULL,
        created_at INT UNSIGNED NOT NULL,
        KEY info_hash (info_hash)
    ) ENGINE=InnoDB');

    @touch($flag);
}

function launcher_is_private_ip(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP) !== false
        && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/**
 * The player's address. The container publishes no ports, so requests arrive from Traefik on a
 * private Docker address, and Traefik appends the real client address as the LAST X-Forwarded-For
 * entry. Earlier entries come from the client and can be forged, so they're ignored; the header is
 * only read at all when the request came from a private address.
 */
function launcher_client_ip(): string
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $forwarded = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($forwarded !== '' && launcher_is_private_ip($remote)) {
        $parts = array_map('trim', explode(',', $forwarded));
        $last = (string)end($parts);
        if (filter_var($last, FILTER_VALIDATE_IP) !== false) {
            return $last;
        }
    }
    return $remote;
}

function launcher_is_banned(PDO $pdo, int $accountId): bool
{
    // AzerothCore marks a permanent ban with unbandate = bandate.
    $statement = $pdo->prepare('SELECT 1 FROM ' . launcher_auth_table('account_banned')
        . ' WHERE id = ? AND active = 1 AND (unbandate > UNIX_TIMESTAMP() OR unbandate = bandate) LIMIT 1');
    $statement->execute([$accountId]);
    return $statement->fetchColumn() !== false;
}

function launcher_bearer_token(): string
{
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = (string)$value;
            }
        }
    }
    return preg_match('/^Bearer\s+([A-Za-z0-9_-]{20,100})$/', trim($header), $match) ? $match[1] : '';
}

function launcher_new_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

/**
 * The logged-in account for this request's bearer token, or a 401/403 answer. Tokens slide: each use
 * pushes the expiry back to LAUNCHER_TOKEN_DAYS from now (at most one write a day).
 *
 * @return array{id:int, name:string, expires_at:int}
 */
function launcher_require_session(PDO $pdo): array
{
    $token = launcher_bearer_token();
    if ($token === '') {
        launcher_json(401, ['error' => 'Log in first.']);
    }

    $hash = hash('sha256', $token, true);
    $statement = $pdo->prepare('SELECT s.account_id, s.expires_at, a.username FROM launcher_session s JOIN '
        . launcher_auth_table('account') . ' a ON a.id = s.account_id WHERE s.token_hash = ? AND s.expires_at > ?');
    $statement->execute([$hash, time()]);
    $row = $statement->fetch();
    if ($row === false) {
        launcher_json(401, ['error' => 'Your login has expired. Log in again.']);
    }

    $accountId = (int)$row['account_id'];
    if (launcher_is_banned($pdo, $accountId)) {
        launcher_json(403, ['error' => 'This account is banned.']);
    }

    $lifetime = launcher_token_days() * 86400;
    $expires = (int)$row['expires_at'];
    if ($expires - time() < $lifetime - 86400) {
        $expires = time() + $lifetime;
        $pdo->prepare('UPDATE launcher_session SET expires_at = ?, last_ip = ? WHERE token_hash = ?')
            ->execute([$expires, launcher_client_ip(), $hash]);
    }

    return ['id' => $accountId, 'name' => (string)$row['username'], 'expires_at' => $expires];
}

/** The account's tracker passkey, made on first use. It goes into announce and web seed URLs. */
function launcher_passkey(PDO $pdo, int $accountId): string
{
    $statement = $pdo->prepare('SELECT passkey FROM launcher_passkey WHERE account_id = ?');
    $statement->execute([$accountId]);
    $passkey = $statement->fetchColumn();
    if ($passkey !== false) {
        return (string)$passkey;
    }

    $passkey = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT IGNORE INTO launcher_passkey (account_id, passkey, created_at) VALUES (?, ?, ?)')
        ->execute([$accountId, $passkey, time()]);
    $statement->execute([$accountId]); // another request may have won the race
    return (string)$statement->fetchColumn();
}

/** The account behind a passkey, or 0 when it's unknown or the account is banned. */
function launcher_passkey_account(PDO $pdo, string $passkey): int
{
    if (!preg_match('/^[0-9a-f]{32}$/', $passkey)) {
        return 0;
    }
    $statement = $pdo->prepare('SELECT account_id FROM launcher_passkey WHERE passkey = ?');
    $statement->execute([$passkey]);
    $accountId = (int)$statement->fetchColumn();
    return ($accountId > 0 && !launcher_is_banned($pdo, $accountId)) ? $accountId : 0;
}

function launcher_torrent_dir(): string
{
    return rtrim(launcher_env('LAUNCHER_TORRENT_DIR', '/srv/launcher/torrents'), '/');
}

function launcher_client_dir(): string
{
    return rtrim(launcher_env('LAUNCHER_CLIENT_DIR', '/srv/launcher/client'), '/');
}

/** The realm folder (mod-realm-config's output, with the patch MPQs) and its public URL. */
function launcher_patch_dir(): string
{
    return rtrim(launcher_env('LAUNCHER_PATCH_DIR', '/var/www/html/realm'), '/');
}

function launcher_patch_url(): string
{
    return rtrim(launcher_env('LAUNCHER_PATCH_URL', launcher_base_url() . '/realm'), '/') . '/';
}

/** Lock files for patch hashing (only coordinates concurrent requests; the torrents live in the database). */
function launcher_patch_lock_dir(): string
{
    return sys_get_temp_dir() . '/portal-launcher-patches';
}

/**
 * A single-file torrent for one patch MPQ in the realm folder, so players share patches with each
 * other. The realm folder stays the source of truth: the torrent is made from whatever file is there
 * now, stored in launcher_patch_torrent per file size and modification time (so it survives
 * restarts and the tracker keeps accepting it), and a replaced patch simply gets a new torrent.
 * Hashing a 2 GB patch takes a few seconds, once per version.
 *
 * @return array{name:string, length:int, info_hash:string, raw_info:string}|null null when there's no such patch
 */
function launcher_patch_torrent(PDO $pdo, string $name): ?array
{
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.(?i:mpq)$/', $name)) {
        return null;
    }
    $path = launcher_patch_dir() . '/' . $name;
    clearstatcache(true, $path);
    if (!is_file($path)) {
        return null;
    }
    $size = filesize($path);
    $mtime = filemtime($path);

    $find = $pdo->prepare('SELECT info_hash, raw_info FROM launcher_patch_torrent WHERE name = ? AND size = ? AND mtime = ?');
    $find->execute([$name, $size, $mtime]);
    if (($row = $find->fetch()) !== false) {
        return ['name' => $name, 'length' => $size, 'info_hash' => (string)$row['info_hash'], 'raw_info' => (string)$row['raw_info']];
    }

    // One request hashes a new version; the others wait for it.
    $locks = launcher_patch_lock_dir();
    if (!is_dir($locks)) {
        @mkdir($locks, 0700, true);
    }
    $lock = @fopen($locks . '/' . $name . '.lock', 'c');
    if ($lock !== false) {
        flock($lock, LOCK_EX);
    }
    try {
        $find->execute([$name, $size, $mtime]);
        if (($row = $find->fetch()) !== false) {
            return ['name' => $name, 'length' => $size, 'info_hash' => (string)$row['info_hash'], 'raw_info' => (string)$row['raw_info']];
        }

        // About 2000 pieces, 256 KiB to 16 MiB each.
        $pieceLength = 262144;
        while ($pieceLength < 16777216 && $size / $pieceLength > 2000) {
            $pieceLength *= 2;
        }

        set_time_limit(0);
        $pieces = '';
        $handle = fopen($path, 'rb');
        while (!feof($handle)) {
            $piece = '';
            while (strlen($piece) < $pieceLength && !feof($handle)) {
                $piece .= (string)fread($handle, $pieceLength - strlen($piece));
            }
            if ($piece !== '') {
                $pieces .= sha1($piece, true);
            }
        }
        fclose($handle);

        $rawInfo = bencode(['length' => $size, 'name' => $name, 'piece length' => $pieceLength, 'pieces' => $pieces, 'private' => 1]);
        $infoHash = sha1($rawInfo, true);
        // Older versions of this patch stop being tracked: their files are gone from the realm folder.
        $pdo->prepare('DELETE FROM launcher_patch_torrent WHERE name = ?')->execute([$name]);
        $pdo->prepare('INSERT INTO launcher_patch_torrent (name, size, mtime, info_hash, raw_info, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$name, $size, $mtime, $infoHash, $rawInfo, time()]);
        return ['name' => $name, 'length' => $size, 'info_hash' => $infoHash, 'raw_info' => $rawInfo];
    } finally {
        if ($lock !== false) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/** Info hashes the tracker accepts: the torrent files plus the current patch torrents. */
function launcher_tracked_hashes(PDO $pdo): array
{
    $hashes = array_map(static fn($entry) => $entry['info_hash'], array_values(launcher_torrents()));
    foreach ($pdo->query('SELECT info_hash FROM launcher_patch_torrent') as $row) {
        $hashes[] = (string)$row['info_hash'];
    }
    return $hashes;
}

/**
 * Every torrent the tracker serves, from the .torrent files in LAUNCHER_TORRENT_DIR.
 *
 * @return array<string, array{file:string, info_hash:string, torrent:array, raw_info:string}> keyed by name
 */
function launcher_torrents(): array
{
    $torrents = [];
    foreach (glob(launcher_torrent_dir() . '/*.torrent') ?: [] as $file) {
        $name = basename($file, '.torrent');
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $name)) {
            continue;
        }
        $data = @file_get_contents($file);
        $parsed = $data === false ? null : bdecode_torrent($data);
        if ($parsed === null) {
            continue;
        }
        $torrents[$name] = [
            'file' => $file,
            'info_hash' => sha1($parsed['raw_info'], true),
            'torrent' => $parsed['torrent'],
            'raw_info' => $parsed['raw_info'],
        ];
    }
    return $torrents;
}
