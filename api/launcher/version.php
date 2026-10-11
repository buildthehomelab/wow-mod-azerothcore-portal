<?php
/**
 * GET api/launcher/version.php
 *
 * The newest stable Portalkeeper release: {"tag": "v0.5.8", "version": "0.5.8"}. Launchers ask every
 * minute, so a new release reaches them quickly without each one using up GitHub's 60 requests an
 * hour per address (friends on one network share that). No login needed: a launcher that's signed
 * out still has to update. Launchers still download and verify the installer from the GitHub
 * release itself.
 *
 * POST api/launcher/version.php with {"tag": "v0.5.9", "time": <unix time>} and
 * "X-Signature: sha256=<HMAC-SHA256 of the body with LAUNCHER_RELEASE_SECRET>"
 *
 * The launcher's release workflow announces a new release here, so the portal knows right away
 * instead of finding out from GitHub. Off (404) until LAUNCHER_RELEASE_SECRET is set.
 *
 * The portal also asks GitHub itself once per LAUNCHER_VERSION_CACHE_SECONDS: every hour when
 * announcements are on (a safety net for one that didn't arrive), every 2 minutes when they're off.
 * It keeps the last answer when GitHub fails.
 **/

declare(strict_types=1);

require __DIR__ . '/../../application/launcher/launcher.php';
require_once __DIR__ . '/../../application/include/changelog.php'; // cl_github()

launcher_require_enabled();

$repo = launcher_env('LAUNCHER_RELEASE_REPO', 'buildthehomelab/wow-Portalkeeper');
$secret = launcher_env('LAUNCHER_RELEASE_SECRET');
$ttl = max(30, (int)launcher_env('LAUNCHER_VERSION_CACHE_SECONDS', $secret === '' ? '120' : '3600'));
$file = sys_get_temp_dir() . '/portal-launcher-version-' . md5($repo) . '.json';

/** "v1.2.3" → "1.2.3", or null for anything that isn't a plain stable version tag. */
$stableVersion = static function (mixed $tag): ?string {
    return is_string($tag) && preg_match('/\A[vV]?(\d+\.\d+\.\d+(?:\+[0-9A-Za-z.-]+)?)\z/', $tag, $match) ? $match[1] : null;
};

$respond = static function (?array $cached) use ($stableVersion): never {
    $version = $stableVersion($cached['tag'] ?? null);
    if ($version === null) {
        launcher_json(502, ['error' => 'The latest launcher release could not be looked up.']);
    }
    launcher_json(200, ['tag' => $cached['tag'], 'version' => $version]);
};

/** The tag github.com/<repo>/releases/latest redirects to, or null. */
$latestFromWeb = static function () use ($repo): ?string {
    $context = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 10, 'follow_location' => 0,
        'ignore_errors' => true, 'header' => 'User-Agent: azerothcore-portal-launcher']]);
    @file_get_contents('https://github.com/' . $repo . '/releases/latest', false, $context);
    $headers = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    foreach ($headers as $header) {
        if (preg_match('#^Location:\s*https://github\.com/' . preg_quote($repo, '#') . '/releases/tag/([^/\s?]+)\s*$#i', $header, $match)) {
            return rawurldecode($match[1]);
        }
    }
    return null;
};

$read = static fn(): ?array => is_file($file) ? (json_decode((string)@file_get_contents($file), true) ?: null) : null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($secret === '') {
        launcher_json(404, ['error' => 'Release announcements are turned off.']);
    }
    $body = (string)file_get_contents('php://input', false, null, 0, 4096);
    $expected = 'sha256=' . hash_hmac('sha256', $body, $secret);
    if (!hash_equals($expected, (string)($_SERVER['HTTP_X_SIGNATURE'] ?? ''))) {
        launcher_json(401, ['error' => 'The signature does not match.']);
    }
    $input = json_decode($body, true);
    $tag = is_array($input) ? ($input['tag'] ?? null) : null;
    // The time is signed too, so a recorded announcement can't be sent again later to bring back an old release.
    if ($stableVersion($tag) === null || abs(time() - (int)($input['time'] ?? 0)) > 300) {
        launcher_json(400, ['error' => 'Send {"tag": "v1.2.3", "time": <unix time now>}.']);
    }
    // Wait for a GitHub lookup that's under way, so its older answer can't replace this one.
    $lock = fopen($file . '.lock', 'c');
    if ($lock) {
        flock($lock, LOCK_EX);
    }
    $announced = ['tag' => $tag, 'checked' => time()];
    file_put_contents($file, json_encode($announced), LOCK_EX);
    if ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    $respond($announced);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET, POST');
    launcher_json(405, ['error' => 'Use GET or POST.']);
}

$cached = $read();
if ($cached && $cached['checked'] + $ttl > time()) {
    $respond($cached);
}

$lock = fopen($file . '.lock', 'c');
if (!$lock || !flock($lock, $cached ? LOCK_EX | LOCK_NB : LOCK_EX)) {
    $respond($cached); // another request is asking GitHub: use the old answer
}
try {
    $fresh = $read();
    if ($fresh && $fresh['checked'] + $ttl > time()) {
        $cached = $fresh; // the request we waited for already asked GitHub
    } else {
        [$status, $release] = cl_github('repos/' . $repo . '/releases/latest');
        $tag = is_array($release) ? ($release['tag_name'] ?? null) : null;
        if ($status !== 200 || !empty($release['draft']) || !empty($release['prerelease'])) {
            $tag = null;
        }
        if ($stableVersion($tag) === null) {
            // Out of API requests (the portal's network shares GitHub's 60 an hour unless GITHUB_TOKEN is
            // set): the web page's /releases/latest redirect names the same release and isn't counted.
            $tag = $latestFromWeb();
        }
        if ($stableVersion($tag) !== null) {
            $cached = ['tag' => $tag, 'checked' => time()];
        } else {
            error_log('launcher version: GitHub answered ' . $status . ' for ' . $repo);
            // Keep the old answer, or remember the failure, so GitHub is asked again only after the TTL.
            $cached = ['tag' => $cached['tag'] ?? null, 'checked' => time()];
        }
        file_put_contents($file, json_encode($cached), LOCK_EX);
    }
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
$respond($cached);
