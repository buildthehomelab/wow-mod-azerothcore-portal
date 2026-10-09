<?php
/**
 * POST api/launcher/login.php {"username": "...", "password": "..."} (JSON or form fields)
 *
 * Checks a game account's password against its SRP6 verifier and answers
 * {"token", "expires_at", "account": {"id", "name"}}. Portalkeeper sends the token back as
 * "Authorization: Bearer <token>". Only the token's SHA-256 is stored.
 *
 * Failed logins are throttled per account name and per address, so this can't be used to guess
 * passwords.
 **/

declare(strict_types=1);

require __DIR__ . '/../../application/launcher/launcher.php';

launcher_require_enabled();
launcher_require_method('POST');

const LAUNCHER_FAILURE_WINDOW = 900;   // seconds
const LAUNCHER_FAILURES_PER_NAME = 5;  // per account name within the window
const LAUNCHER_FAILURES_PER_IP = 20;   // per address within the window

$input = $_POST;
if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
    $input = json_decode((string)file_get_contents('php://input'), true);
    $input = is_array($input) ? $input : [];
}

$username = strtoupper(trim((string)($input['username'] ?? '')));
$password = (string)($input['password'] ?? '');
if ($username === '' || $password === '' || strlen($username) > 32 || strlen($password) > 128) {
    launcher_json(400, ['error' => 'Enter your account name and password.']);
}

$pdo = launcher_db();
$ip = launcher_client_ip();
$now = time();

$failures = $pdo->prepare('SELECT
        (SELECT COUNT(*) FROM launcher_login_failure WHERE username = ? AND at > ?) AS by_name,
        (SELECT COUNT(*) FROM launcher_login_failure WHERE ip = ? AND at > ?) AS by_ip');
$failures->execute([$username, $now - LAUNCHER_FAILURE_WINDOW, $ip, $now - LAUNCHER_FAILURE_WINDOW]);
$count = $failures->fetch();
if ((int)$count['by_name'] >= LAUNCHER_FAILURES_PER_NAME || (int)$count['by_ip'] >= LAUNCHER_FAILURES_PER_IP) {
    header('Retry-After: ' . LAUNCHER_FAILURE_WINDOW);
    launcher_json(429, ['error' => 'Too many failed logins. Try again in 15 minutes.']);
}

$statement = $pdo->prepare('SELECT id, username, salt, verifier FROM ' . launcher_auth_table('account') . ' WHERE username = ?');
$statement->execute([$username]);
$account = $statement->fetch();

if ($account === false) {
    // Spend the same time as a real check so response times don't reveal which names exist.
    verifySRP6($username, $password, random_bytes(32), random_bytes(32));
}

if ($account === false || !verifySRP6((string)$account['username'], $password, (string)$account['salt'], (string)$account['verifier'])) {
    $pdo->prepare('INSERT INTO launcher_login_failure (username, ip, at) VALUES (?, ?, ?)')->execute([$username, $ip, $now]);
    launcher_json(401, ['error' => 'Wrong account name or password.']);
}

$accountId = (int)$account['id'];
if (launcher_is_banned($pdo, $accountId)) {
    launcher_json(403, ['error' => 'This account is banned.']);
}

$token = launcher_new_token();
$expires = $now + launcher_token_days() * 86400;
$pdo->prepare('INSERT INTO launcher_session (token_hash, account_id, created_at, expires_at, last_ip) VALUES (?, ?, ?, ?, ?)')
    ->execute([hash('sha256', $token, true), $accountId, $now, $expires, $ip]);

// Housekeeping now and then: drop expired sessions and old failures.
if (random_int(1, 20) === 1) {
    $pdo->prepare('DELETE FROM launcher_session WHERE expires_at < ?')->execute([$now]);
    $pdo->prepare('DELETE FROM launcher_login_failure WHERE at < ?')->execute([$now - 86400]);
}

launcher_json(200, [
    'token' => $token,
    'expires_at' => $expires,
    'account' => ['id' => $accountId, 'name' => (string)$account['username']],
]);
