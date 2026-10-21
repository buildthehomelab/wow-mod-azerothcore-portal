<?php
/**
 * The coin shop behind api/launcher/shop.php: what a logged-in account can buy with the
 * Tarnished Undermine Reals in its wallet, and buying it. mod-boss-coins on the worldserver
 * keeps the wallet and the catalogue; this asks it over SOAP with two console commands, which
 * answer one line of JSON each:
 *
 *   .coinshop list <account id>                          balance, characters, offers
 *   .coinshop buy <account id> <offer id> <guid>         {"ok", "balance", "message"} or
 *                                                        {"ok": false, "error", "message"}
 *
 * The worldserver runs SOAP commands on its world thread, and both commands read the database
 * there, so a list answer is kept for SHOP_CACHE_SECONDS per account (a purchase drops it), and
 * an account gets one purchase at a time and at most SHOP_BUYS_PER_MINUTE of them (429 past that).
 *
 * Nothing is stored in the database. The cache and the locks are files in SHOP_CACHE_DIR.
 **/

declare(strict_types=1);

require_once __DIR__ . '/launcher.php';

function shop_enabled(): bool
{
    return launcher_enabled() && filter_var(launcher_env('SHOP_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
}

function shop_cache_seconds(): int
{
    return max(0, (int)launcher_env('SHOP_CACHE_SECONDS', '15'));
}

function shop_buys_per_minute(): int
{
    return max(1, (int)launcher_env('SHOP_BUYS_PER_MINUTE', '6'));
}

/** The cache and lock folder, made on first use. Null when it can't be written. */
function shop_dir(): ?string
{
    $dir = rtrim(launcher_env('SHOP_CACHE_DIR', sys_get_temp_dir() . '/portal-shop'), '/');
    if ((!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) || !is_writable($dir)) {
        error_log('shop: SHOP_CACHE_DIR ' . $dir . ' can\'t be written; listings aren\'t cached and purchases are refused');
        return null;
    }
    return $dir;
}

/**
 * Runs a console command on the worldserver over SOAP (launcher_soap()) and returns the JSON line it
 * answered, or null when the worldserver couldn't be reached or answered something else.
 */
function shop_soap(string $command, int $timeout): ?array
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

    error_log('shop: unexpected SOAP answer: ' . substr($answer, 0, 300));
    return null;
}

/**
 * The pictures' folder and its public URL: the realm folder's shop/, served at /realm/shop/.
 * Not the patch folder (LAUNCHER_PATCH_DIR), which a realm may keep in a subfolder.
 */
function shop_preview_dir(): string
{
    return rtrim(launcher_env('SHOP_PREVIEW_DIR', '/var/www/html/realm/shop'), '/');
}

function shop_preview_url(): string
{
    return rtrim(launcher_env('SHOP_PREVIEW_URL', launcher_base_url() . '/realm/shop'), '/') . '/';
}

/**
 * Turntable pictures of the offers, from previews.json in shop_preview_dir(): {"<offer id>": {"file": "201.webp", "frames": 36}, ...}, each file a strip of
 * `frames` pictures side by side, one per angle. Returns {offer id: {"url", "frames"}} for the
 * files that are there; the url carries the file's time and size, so a new picture gets a new url.
 */
function shop_previews(): array
{
    $dir = shop_preview_dir();
    $manifest = @file_get_contents($dir . '/previews.json');
    if ($manifest === false) {
        return [];
    }
    $entries = json_decode($manifest, true);
    if (!is_array($entries) || ($entries !== [] && array_is_list($entries))) {
        error_log('shop: ' . $dir . '/previews.json is not a JSON object');
        return [];
    }

    $previews = [];
    foreach ($entries as $id => $entry) {
        $file = is_array($entry) ? (string)($entry['file'] ?? '') : '';
        $frames = is_array($entry) ? (int)($entry['frames'] ?? 1) : 0;
        if (!ctype_digit((string)$id) || (int)$id < 1 || $frames < 1 || $frames > 360
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.(webp|png|jpe?g)\z/i', $file)) {
            continue;
        }
        $mtime = @filemtime($dir . '/' . $file);
        $size = @filesize($dir . '/' . $file);
        if ($mtime === false || $size === false) {
            continue;
        }
        $previews[(int)$id] = [
            'url' => shop_preview_url() . rawurlencode($file) . '?v=' . $mtime . '-' . $size,
            'frames' => $frames,
        ];
    }
    return $previews;
}

function shop_cache_path(string $dir, int $accountId): string
{
    return $dir . '/list-' . $accountId . '.json';
}

/** When the account last bought something (microtime), so an older listing isn't cached after it. */
function shop_bought_at(string $dir, int $accountId): float
{
    return (float)@file_get_contents($dir . '/bought-' . $accountId);
}

function shop_handle_request(array $session): never
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        shop_list($session);
    }
    if ($method === 'POST') {
        shop_buy($session);
    }

    header('Allow: GET, POST');
    launcher_json(405, ['error' => 'Use GET or POST.']);
}

/**
 * GET: {"balance", "characters": [...], "offers": [...], "cached_at"}, as mod-boss-coins answers
 * .coinshop list, minus its "ok", with a "preview" ({"url", "frames"}) on the offers that have
 * one (shop_previews()).
 */
function shop_list(array $session): never
{
    $dir = shop_dir();
    $accountId = (int)$session['id'];

    if ($dir !== null && shop_cache_seconds() > 0) {
        $path = shop_cache_path($dir, $accountId);
        $mtime = @filemtime($path);
        if ($mtime !== false && time() - $mtime < shop_cache_seconds()) {
            $cached = json_decode((string)@file_get_contents($path), true);
            if (is_array($cached)) {
                launcher_json(200, $cached);
            }
        }
    }

    $started = microtime(true);
    $answer = shop_soap('.coinshop list ' . $accountId, 8);
    if ($answer === null) {
        launcher_json(503, ['error' => 'The shop can\'t reach the game server right now. Try again in a minute.']);
    }
    if (empty($answer['ok'])) {
        launcher_json(503, ['error' => (string)($answer['message'] ?? 'The shop is closed.'), 'code' => (string)($answer['error'] ?? '')]);
    }

    $offers = is_array($answer['offers'] ?? null) ? $answer['offers'] : [];
    $previews = shop_previews();
    foreach ($offers as &$offer) {
        $id = is_array($offer) ? (int)($offer['id'] ?? 0) : 0;
        if ($id > 0 && isset($previews[$id])) {
            $offer['preview'] = $previews[$id];
        }
    }
    unset($offer);

    $body = [
        'balance' => (int)($answer['balance'] ?? 0),
        'characters' => is_array($answer['characters'] ?? null) ? $answer['characters'] : [],
        'offers' => $offers,
        'cached_at' => time(),
    ];

    // A purchase that finished while this listing was asked for has made it old already.
    if ($dir !== null && shop_cache_seconds() > 0 && shop_bought_at($dir, $accountId) < $started) {
        $path = shop_cache_path($dir, $accountId);
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temp, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
            @rename($temp, $path) || @unlink($temp);
        }
    }

    launcher_json(200, $body);
}

/**
 * POST {"offer": <id>, "character": <guid>} (JSON or form fields).
 *   200 {"ok": true, "balance", "message"}
 *   409 {"ok": false, "error", "message"}   refused: closed, no_offer, no_character, busy, owned,
 *                                           cannot, balance (the message says why)
 *   429 another purchase of this account is still on its way, or too many in the last minute
 *   502 the worldserver didn't answer: the purchase may or may not have gone through
 */
function shop_buy(array $session): never
{
    $input = $_POST;
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
        $input = json_decode((string)file_get_contents('php://input'), true);
        $input = is_array($input) ? $input : [];
    }

    $offer = filter_var($input['offer'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
    $character = filter_var($input['character'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
    if ($offer === false || $character === false) {
        launcher_json(400, ['error' => 'Pick something to buy and a character to send it to.']);
    }

    $dir = shop_dir();
    if ($dir === null) {
        launcher_json(503, ['error' => 'The shop is closed right now.']);
    }

    $accountId = (int)$session['id'];
    $lock = @fopen($dir . '/buy-' . $accountId . '.lock', 'c+');
    if ($lock === false) {
        error_log('shop: can\'t open the purchase lock of account ' . $accountId . ' in ' . $dir);
        launcher_json(503, ['error' => 'The shop is closed right now.']);
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        launcher_json(429, ['error' => 'Your last purchase is still going through. Try again in a moment.']);
    }

    // The lock file holds the times of the account's purchases in the last minute. Every one,
    // refused or not, costs the worldserver a look at the database on its main thread.
    $now = time();
    $recent = array_values(array_filter(
        array_map('intval', explode(',', (string)stream_get_contents($lock))),
        static fn(int $at): bool => $at > $now - 60
    ));
    if (count($recent) >= shop_buys_per_minute()) {
        flock($lock, LOCK_UN);
        fclose($lock);
        header('Retry-After: 60');
        launcher_json(429, ['error' => 'That was a lot of purchases at once. Wait a minute and try again.']);
    }
    $recent[] = $now;
    ftruncate($lock, 0);
    rewind($lock);
    fwrite($lock, implode(',', $recent));
    fflush($lock);

    try {
        $answer = shop_soap(sprintf('.coinshop buy %d %d %d', $accountId, $offer, $character), 20);
    } finally {
        // Whatever happened, the balance and what is owned may have changed: drop the listing,
        // and stop a listing asked for meanwhile from being cached.
        @file_put_contents($dir . '/bought-' . $accountId, (string)microtime(true));
        @unlink(shop_cache_path($dir, $accountId));
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    if ($answer === null) {
        launcher_json(502, ['error' => 'The game server didn\'t answer. Look at your balance before you try again: '
            . 'the purchase may have gone through.']);
    }

    if (!empty($answer['ok'])) {
        launcher_json(200, [
            'ok' => true,
            'balance' => (int)($answer['balance'] ?? 0),
            'message' => (string)($answer['message'] ?? ''),
        ]);
    }

    launcher_json(409, [
        'ok' => false,
        'error' => (string)($answer['error'] ?? 'refused'),
        'message' => (string)($answer['message'] ?? 'The purchase was refused.'),
    ]);
}
