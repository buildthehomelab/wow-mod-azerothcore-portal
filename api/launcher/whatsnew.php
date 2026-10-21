<?php
/**
 * GET api/launcher/whatsnew.php?since=<unix time or ISO 8601>&limit=<1-50>
 *
 * The patch notes that are new since "since" (the last time the player started the game; without it the
 * last 14 days, and never more than 90 days back), newest first. A note counts from when it first
 * showed in the patch notes, which for a server module is when the realm started running it:
 *
 *   {"since": ISO 8601, "url": the patch notes page,
 *    "notes": [{"date", "kind": "feature"|"change"|"fix", "section", "sub", "title", "text": [...], "url"}],
 *    "more": how many more there are past "limit"}
 *
 * 404 when the realm has no patch notes (CHANGELOG_ORG unset), 503 until changelog.php has built them once.
 **/

declare(strict_types=1);

require __DIR__ . '/../../application/launcher/whatsnew.php';

launcher_require_enabled();
launcher_require_method('GET');
if (!cl_enabled()) {
    launcher_json(404, ['error' => 'The realm has no patch notes.']);
}

$data = cl_cached();
if ($data === null) {
    header('Retry-After: 300');
    launcher_json(503, ['error' => 'The patch notes aren\'t ready yet.']);
}

try {
    $tz = new DateTimeZone(cl_env('CHANGELOG_TIMEZONE', date_default_timezone_get()));
} catch (Exception $e) {
    $tz = new DateTimeZone('UTC');
}
$since = whatsnew_since(isset($_GET['since']) ? (string)$_GET['since'] : null);
$limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));

launcher_json(200, ['since' => $since->format(DATE_ATOM), 'url' => launcher_base_url() . '/changelog.php']
    + whatsnew_notes($data, $tz, $since, $limit));
