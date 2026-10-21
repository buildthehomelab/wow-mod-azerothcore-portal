<?php
/**
 * The patch notes since a player last played, for the launcher's home screen (api/launcher/whatsnew.php).
 * Read from the patch notes cache only (cl_cached()), never from GitHub, so it never waits on a cold
 * rebuild; changelog.php fills that cache.
 **/

declare(strict_types=1);

require_once __DIR__ . '/launcher.php';
require_once __DIR__ . '/../include/changelog.php';

const WHATSNEW_MAX_DAYS = 90;   // a "since" further back is cut to this
const WHATSNEW_DEFAULT_DAYS = 14; // without a "since"

/**
 * Notes that are new since $since, newest first, as {"notes": [{"date", "kind": "feature"|"change"|"fix",
 * "section", "sub", "title", "text": [...], "url"}], "more": how many didn't fit in $limit}. A note's
 * date is when it first showed in the patch notes (whatsnew_first_seen()), which for a server module
 * is when the realm started running it, not when it was merged. Note text is plain: the page's
 * **bold**, `code` and [links] are taken out.
 */
function whatsnew_notes(array $data, DateTimeZone $tz, DateTimeImmutable $since, int $limit): array
{
    $all = [];
    foreach (cl_patch_notes($data, $tz) as $day) {
        $kinds = [['feature', [['' => $day['features']]]], ['change', $day['changes']], ['fix', $day['fixes']]];
        foreach ($kinds as [$kind, $sections]) {
            foreach ($sections as $subs) {
                foreach ($subs as $list) {
                    foreach ($list as $note) {
                        $all[] = ['kind' => $kind] + $note;
                    }
                }
            }
        }
    }

    $seen = whatsnew_first_seen($all);
    $notes = [];
    foreach ($all as $order => $note) {
        $date = max((new DateTimeImmutable($note['date']))->getTimestamp(), $seen[$note['url']] ?? 0);
        if ($date <= $since->getTimestamp()) {
            continue;
        }
        $notes[] = [
            'date' => gmdate(DATE_ATOM, $date),
            'kind' => $note['kind'],
            'section' => $note['section'],
            'sub' => $note['sub'],
            'title' => $note['title'] === null ? null : whatsnew_plain($note['title']),
            'text' => array_map('whatsnew_plain', $note['notes']),
            'url' => $note['url'],
            'order' => $order,
        ];
    }
    // Newest first; within the same moment, the patch notes' own order (features, changes, fixes).
    usort($notes, static fn(array $a, array $b): int => [$b['date'], $a['order']] <=> [$a['date'], $b['order']]);
    $notes = array_map(static function (array $note): array {
        unset($note['order']);
        return $note;
    }, $notes);
    return ['notes' => array_slice($notes, 0, $limit), 'more' => max(0, count($notes) - $limit)];
}

/**
 * When each note (by its URL) first showed in the patch notes, as unix times, kept next to the
 * patch notes cache. Notes not seen before are recorded as of now; the very first time (no record
 * yet) every note counts from its merge date, so a fresh deploy doesn't call everything new.
 */
function whatsnew_first_seen(array $notes): array
{
    $file = cl_cache_dir() . '/launcher-whatsnew-seen.json';
    $lock = @fopen($file . '.lock', 'c');
    if ($lock !== false) {
        flock($lock, LOCK_EX);
    }
    try {
        $known = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
        $first = !is_array($known);
        $known = $first ? [] : $known;
        $added = false;
        foreach ($notes as $note) {
            if (!isset($known[$note['url']])) {
                $known[$note['url']] = $first ? (new DateTimeImmutable($note['date']))->getTimestamp() : time();
                $added = true;
            }
        }
        if ($added) {
            $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($temp, json_encode($known, JSON_UNESCAPED_SLASHES)) !== false) {
                @rename($temp, $file);
            }
        }
        return $known;
    } finally {
        if ($lock !== false) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

function whatsnew_plain(string $text): string
{
    $text = preg_replace('/\*\*(.+?)\*\*/', '$1', $text);
    $text = preg_replace('/`([^`]+)`/', '$1', $text);
    return preg_replace('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', '$1', $text);
}

/** The "since" a launcher sent (unix time or ISO 8601), kept within the last WHATSNEW_MAX_DAYS. */
function whatsnew_since(?string $value): DateTimeImmutable
{
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $oldest = $now->modify('-' . WHATSNEW_MAX_DAYS . ' days');
    $since = null;
    if ($value !== null && $value !== '' && strlen($value) <= 40) {
        try {
            $since = new DateTimeImmutable(ctype_digit($value) ? '@' . $value : $value);
        } catch (Exception $e) {
            $since = null; // not a time, or out of range: the default
        }
    }
    $since ??= $now->modify('-' . WHATSNEW_DEFAULT_DAYS . ' days');
    return $since < $oldest ? $oldest : ($since > $now ? $now : $since);
}
