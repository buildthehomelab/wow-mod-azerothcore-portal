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

const LAUNCHER_SCHEMA_VERSION = 4;

// Failed password checks (launcher login and the website's Change Password form) share one counter.
const LAUNCHER_FAILURE_WINDOW = 900;   // seconds
const LAUNCHER_FAILURES_PER_NAME = 5;  // per account name within the window
const LAUNCHER_FAILURES_PER_IP = 20;   // per address within the window

// Website sign-ups that get as far as the "name taken?" check, per address.
const WEB_SIGNUP_WINDOW = 3600;        // seconds
const WEB_SIGNUPS_PER_IP = 10;         // per address within the window

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

/**
 * Where torrents point the tracker and web seeds. LAUNCHER_SEED_HOST names a host that reaches
 * Traefik directly (DNS only, not behind Cloudflare or another proxy), so the tracker sees players'
 * real addresses and the downloads skip the proxy. Without it, everything uses BASE_URL.
 */
function launcher_seed_url(): string
{
    $host = trim(launcher_env('LAUNCHER_SEED_HOST'), "/ \t");
    return $host === '' ? launcher_base_url() : 'https://' . $host;
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

    $pdo->exec('CREATE TABLE IF NOT EXISTS web_signup_attempt (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        at INT UNSIGNED NOT NULL,
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
        sha256 BINARY(32) NULL,
        created_at INT UNSIGNED NOT NULL,
        KEY info_hash (info_hash)
    ) ENGINE=InnoDB');
    // Version 4: the file's SHA-256, checked against realm.conf (rows from before have none and are
    // made again once).
    try {
        $pdo->exec('ALTER TABLE launcher_patch_torrent ADD COLUMN sha256 BINARY(32) NULL AFTER raw_info');
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) !== 1060) { // 1060: the column is there already
            throw $e;
        }
    }

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
 *
 * Behind Cloudflare (proxy or cloudflared tunnel) that last entry is Cloudflare's or the tunnel's
 * address, so LAUNCHER_CLIENT_IP_HEADER can name the header that carries the player instead
 * (CF-Connecting-IP). Only set it when every outside request passes through that proxy, because it
 * overwrites the header; otherwise a client could send its own.
 */
function launcher_client_ip(): string
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $header = trim(launcher_env('LAUNCHER_CLIENT_IP_HEADER'));
    if ($header !== '' && launcher_is_private_ip($remote)) {
        $value = trim((string)($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $header))] ?? ''));
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return $value;
        }
    }

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

/**
 * The address the throttles count by: IPv4 as is, IPv6 by its /64, since one home connection or
 * server usually gets a whole /64 and could otherwise use a fresh address for every try.
 */
function launcher_throttle_ip(string $ip): string
{
    $packed = @inet_pton($ip);
    if ($packed === false || strlen($packed) !== 16) {
        return $ip;
    }
    return (string)inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
}

/**
 * Runs a password check under the failed-login throttle (5 per account name and 20 per address in
 * 15 minutes, shared by the launcher login and the website's Change Password form). Answers null
 * when throttled (the check isn't run), otherwise what $check returned.
 *
 * $username should be the account's own name from acore_auth when it exists, so spellings that the
 * auth database matches to the same account (trailing spaces and the like) share one counter.
 *
 * The try is counted as a failure before the check and the row removed again on success, so
 * parallel requests can't all pass the limit before any failure is written, and a check that
 * throws still counts.
 */
function launcher_guarded_check(PDO $pdo, string $username, string $ip, callable $check): ?bool
{
    $username = substr($username, 0, 32);
    $ip = launcher_throttle_ip($ip);
    $now = time();
    $pdo->prepare('INSERT INTO launcher_login_failure (username, ip, at) VALUES (?, ?, ?)')->execute([$username, $ip, $now]);
    $id = (int)$pdo->lastInsertId();
    $forget = $pdo->prepare('DELETE FROM launcher_login_failure WHERE id = ?');

    $since = $now - LAUNCHER_FAILURE_WINDOW;
    $failures = $pdo->prepare('SELECT
            (SELECT COUNT(*) FROM launcher_login_failure WHERE username = ? AND at > ?) AS by_name,
            (SELECT COUNT(*) FROM launcher_login_failure WHERE ip = ? AND at > ?) AS by_ip');
    $failures->execute([$username, $since, $ip, $since]);
    $count = $failures->fetch();
    // The counts include this try.
    if ((int)$count['by_name'] > LAUNCHER_FAILURES_PER_NAME || (int)$count['by_ip'] > LAUNCHER_FAILURES_PER_IP) {
        $forget->execute([$id]);
        return null;
    }

    if ($check()) {
        $forget->execute([$id]);
        return true;
    }
    return false;
}

/** Counts a website sign-up attempt and says whether this address is still under the limit. */
function launcher_signup_attempt(PDO $pdo, string $ip): bool
{
    $ip = launcher_throttle_ip($ip);
    $now = time();
    // Insert first and count after (including this one), so parallel requests can't all slip under.
    $pdo->prepare('INSERT INTO web_signup_attempt (ip, at) VALUES (?, ?)')->execute([$ip, $now]);
    $id = (int)$pdo->lastInsertId();
    $count = $pdo->prepare('SELECT COUNT(*) FROM web_signup_attempt WHERE ip = ? AND at > ?');
    $count->execute([$ip, $now - WEB_SIGNUP_WINDOW]);
    if ((int)$count->fetchColumn() > WEB_SIGNUPS_PER_IP) {
        $pdo->prepare('DELETE FROM web_signup_attempt WHERE id = ?')->execute([$id]);
        return false;
    }
    return true;
}

/** Drops old failure and sign-up rows; callers run it now and then. */
function launcher_prune_throttles(PDO $pdo): void
{
    $now = time();
    $pdo->prepare('DELETE FROM launcher_login_failure WHERE at < ?')->execute([$now - 86400]);
    $pdo->prepare('DELETE FROM web_signup_attempt WHERE at < ?')->execute([$now - 86400]);
}

/**
 * The public address of the server's own network (LAUNCHER_PUBLIC_ADDRESS, an IP or a host name
 * such as the realmlist), for handing players on that network to outside peers. Null when unset,
 * unresolvable or private.
 */
function launcher_public_ip(): ?string
{
    $address = trim(launcher_env('LAUNCHER_PUBLIC_ADDRESS'));
    if ($address === '') {
        return null;
    }
    $ip = filter_var($address, FILTER_VALIDATE_IP) !== false ? $address : gethostbyname($address);
    return filter_var($ip, FILTER_VALIDATE_IP) !== false && !launcher_is_private_ip($ip) ? $ip : null;
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

/**
 * How many web seed addresses each torrent lists (LAUNCHER_WEB_SEEDS, default 4). Torrent clients
 * open one connection per address and ask each for a couple of MB at a time, one request after the
 * other, so a single address leaves most of the line idle; several run side by side.
 */
function launcher_web_seed_count(): int
{
    return min(16, max(1, (int)launcher_env('LAUNCHER_WEB_SEEDS', '4')));
}

/**
 * The client torrent's web seeds: seed.php/<passkey>/ and seed.php/<passkey>.2/ ... .N/, all the
 * same files (the suffix only makes the addresses differ). They end in "/", so clients append
 * "<name>/<path>" (BEP 19).
 */
function launcher_client_web_seeds(string $passkey): array
{
    $base = launcher_seed_url() . '/api/launcher/seed.php/' . $passkey;
    $urls = [$base . '/'];
    for ($n = 2; $n <= launcher_web_seed_count(); $n++) {
        $urls[] = $base . '.' . $n . '/';
    }
    return $urls;
}

/** A patch torrent's web seeds: the patch's public URL, then the same URL with ?seed=2 ... N. */
function launcher_patch_web_seeds(string $url): array
{
    $urls = [$url];
    for ($n = 2; $n <= launcher_web_seed_count(); $n++) {
        $urls[] = $url . '?seed=' . $n;
    }
    return $urls;
}

/** The passkey in a web seed path segment: "<passkey>" or "<passkey>.<n>" (launcher_client_web_seeds). */
function launcher_seed_passkey(string $segment): string
{
    return (string)preg_replace('/\.[0-9]{1,2}$/', '', $segment);
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
    return rtrim(launcher_env('LAUNCHER_PATCH_URL', launcher_seed_url() . '/realm'), '/') . '/';
}

/** The folders searched for realm.conf files (mod-realm-config's output): the realm folder and the patch folder. */
function launcher_realm_conf_dirs(): array
{
    $dirs = [rtrim(launcher_env('LAUNCHER_REALM_CONF_DIR', '/var/www/html/realm'), '/'), launcher_patch_dir()];
    return array_values(array_unique($dirs));
}

/**
 * The SHA-256s (lowercase hex) the realm.conf files give a patch file: every [Patch.*] section whose
 * FileName, or the file name at the end of its SourceURL, is $name. Empty when no realm.conf names it.
 */
function launcher_patch_expected_sha256(string $name): array
{
    $hashes = [];
    foreach (launcher_realm_conf_dirs() as $dir) {
        foreach (glob($dir . '/*.realm.conf') ?: [] as $file) {
            $section = null;
            $fields = [];
            $lines = @file($file, FILE_IGNORE_NEW_LINES) ?: [];
            $lines[] = '[end]';
            foreach ($lines as $line) {
                $line = trim($line);
                if (preg_match('/^\[(.+)\]$/', $line, $match)) {
                    if ($section !== null && strncmp($section, 'Patch.', 6) === 0
                        && (($fields['FileName'] ?? '') === $name
                            || basename(rawurldecode((string)parse_url($fields['SourceURL'] ?? '', PHP_URL_PATH))) === $name)
                        && preg_match('/^[0-9a-fA-F]{64}$/', $fields['SHA256'] ?? '')) {
                        $hashes[strtolower($fields['SHA256'])] = true;
                    }
                    $section = $match[1];
                    $fields = [];
                    continue;
                }
                $pos = strpos($line, '=');
                if ($line !== '' && $line[0] !== '#' && $line[0] !== ';' && $pos !== false) {
                    $fields[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
                }
            }
        }
    }
    return array_keys($hashes);
}

/** A patch that can't get a torrent right now: still being copied in, or not the file realm.conf names (yet). */
class LauncherPatchNotReady extends RuntimeException
{
}

/** Seconds a patch file must sit unchanged before it is hashed: a copy still being written isn't. */
const LAUNCHER_PATCH_SETTLE = 60;

/** Lock files for patch hashing (only coordinates concurrent requests; the torrents live in the database). */
function launcher_patch_lock_dir(): string
{
    return sys_get_temp_dir() . '/portal-launcher-patches';
}

/**
 * A single-file torrent for one patch MPQ in the realm folder, so players share patches with each
 * other. The realm folder stays the source of truth: the torrent is made from the file there now,
 * stored in launcher_patch_torrent per file size and modification time (so it survives restarts
 * and the tracker keeps accepting it), and a replaced patch simply gets a new torrent. Hashing a
 * 2 GB patch takes a few seconds, once per version.
 *
 * A torrent is only made from a finished file, and only kept when the file's SHA-256 is the one a
 * realm.conf gives it: one hashed while a new patch was still being copied in described bytes that
 * never existed, and every launcher that got it failed the SHA-256 check until the file was
 * touched. A patch no realm.conf names gets its torrent unchecked.
 *
 * @return array{name:string, length:int, info_hash:string, raw_info:string}|null null when there's no such patch
 * @throws LauncherPatchNotReady while the file is being written, or isn't the one realm.conf names
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
    $expected = launcher_patch_expected_sha256($name);

    $find = $pdo->prepare('SELECT info_hash, raw_info, sha256 FROM launcher_patch_torrent WHERE name = ? AND size = ? AND mtime = ?');
    $usable = static fn($row) => $row !== false && ($expected === []
        || ($row['sha256'] !== null && in_array(bin2hex((string)$row['sha256']), $expected, true)));
    $find->execute([$name, $size, $mtime]);
    if ($usable($row = $find->fetch())) {
        return ['name' => $name, 'length' => $size, 'info_hash' => (string)$row['info_hash'], 'raw_info' => (string)$row['raw_info']];
    }
    if (time() - $mtime < LAUNCHER_PATCH_SETTLE) {
        throw new LauncherPatchNotReady($name . ' is still being copied in.');
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
        // Another request may have hashed it while this one waited, or the file changed meanwhile.
        clearstatcache(true, $path);
        if (!is_file($path) || filesize($path) !== $size || filemtime($path) !== $mtime) {
            throw new LauncherPatchNotReady($name . ' is being replaced.');
        }
        $find->execute([$name, $size, $mtime]);
        if ($usable($row = $find->fetch())) {
            return ['name' => $name, 'length' => $size, 'info_hash' => (string)$row['info_hash'], 'raw_info' => (string)$row['raw_info']];
        }

        // About 2000 pieces, 256 KiB to 16 MiB each.
        $pieceLength = 262144;
        while ($pieceLength < 16777216 && $size / $pieceLength > 2000) {
            $pieceLength *= 2;
        }

        set_time_limit(0);
        $pieces = '';
        $whole = hash_init('sha256');
        $read = 0;
        $handle = fopen($path, 'rb');
        while ($read < $size && !feof($handle)) {
            $piece = '';
            while (strlen($piece) < $pieceLength && $read + strlen($piece) < $size && !feof($handle)) {
                $piece .= (string)fread($handle, min($pieceLength - strlen($piece), $size - $read - strlen($piece)));
            }
            if ($piece !== '') {
                $pieces .= sha1($piece, true);
                hash_update($whole, $piece);
                $read += strlen($piece);
            }
        }
        fclose($handle);
        $sha256 = hash_final($whole, true);

        clearstatcache(true, $path);
        if ($read !== $size || !is_file($path) || filesize($path) !== $size || filemtime($path) !== $mtime) {
            throw new LauncherPatchNotReady($name . ' changed while it was being hashed.');
        }
        if ($expected !== [] && !in_array(bin2hex($sha256), $expected, true)) {
            // A new file before its realm.conf row, or a broken copy: no torrent until they agree.
            throw new LauncherPatchNotReady($name . ' is not the file realm.conf names.');
        }

        $rawInfo = bencode(['length' => $size, 'name' => $name, 'piece length' => $pieceLength, 'pieces' => $pieces, 'private' => 1]);
        $infoHash = sha1($rawInfo, true);
        // Older versions of this patch stop being tracked: their files are gone from the realm folder.
        $pdo->prepare('DELETE FROM launcher_patch_torrent WHERE name = ?')->execute([$name]);
        $pdo->prepare('INSERT INTO launcher_patch_torrent (name, size, mtime, info_hash, raw_info, sha256, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$name, $size, $mtime, $infoHash, $rawInfo, $sha256, time()]);
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

/**
 * Runs a console command on the worldserver over SOAP (SOAP_URL, SOAP_USER, SOAP_PASSWORD) and returns
 * what it printed, or null when SOAP isn't set up or the worldserver couldn't be reached. The
 * worldserver takes SOAP calls one at a time, each waiting for its next world update: `$timeout` keeps
 * a slow server from holding this request (and its Apache worker) for long.
 */
function launcher_soap(string $command, int $timeout): ?string
{
    $user = launcher_env('SOAP_USER');
    if ($user === '') {
        error_log('launcher: SOAP_USER is not set');
        return null;
    }

    $previousTimeout = ini_set('default_socket_timeout', (string)$timeout);
    try {
        $client = new SoapClient(null, [
            'location' => launcher_env('SOAP_URL', 'http://ac-worldserver:7878/'),
            'uri' => 'urn:AC',
            'style' => SOAP_RPC,
            'login' => $user,
            'password' => launcher_env('SOAP_PASSWORD'),
            'connection_timeout' => 5,
            'exceptions' => true,
            'cache_wsdl' => WSDL_CACHE_NONE,
        ]);
        return (string)$client->executeCommand(new SoapParam($command, 'command'));
    } catch (Throwable $e) {
        error_log('launcher: SOAP command failed: ' . $e->getMessage());
        return null;
    } finally {
        if ($previousTimeout !== false) {
            ini_set('default_socket_timeout', $previousTimeout);
        }
    }
}

/**
 * Runs a console command that answers one line of JSON with an "ok" field (mod-boss-coins'
 * .coinshop, mod-legacy's .legacy) and returns that line decoded, or null when the worldserver
 * couldn't be reached or answered something else.
 */
function launcher_soap_json(string $command, int $timeout): ?array
{
    $answer = launcher_soap($command, $timeout);
    if ($answer === null) {
        return null;
    }

    // The command prints one JSON line; anything around it (an empty line) is skipped.
    foreach (array_reverse(preg_split('/\r?\n/', $answer)) as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] === '{') {
            $data = json_decode($line, true);
            if (is_array($data) && isset($data['ok'])) {
                return $data;
            }
        }
    }

    $name = implode(' ', array_slice(explode(' ', $command), 0, 2));
    error_log('launcher: unexpected SOAP answer to ' . $name . ': ' . substr($answer, 0, 300));
    return null;
}
