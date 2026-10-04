<?php
/**
 * Patch notes built from merged pull requests on GitHub.
 *
 * Every merged PR in CHANGELOG_ORG's repos (names matching CHANGELOG_REPOS) becomes a note, and
 * each repo's creation becomes a "new feature" note. The text comes from, in order:
 *   1. application/changelog-notes.json (hand-written notes, keyed "repo#number" / repo name),
 *   2. a "## Patch Notes" section in the PR body (bullets; "None" hides the PR),
 *   3. the PR title (PRs that look like pin bumps, docs or build fixes are skipped).
 *
 * When the server's modules/ folder is mounted (AC_MODULES_DIR) or CHANGELOG_MANIFEST is set,
 * server modules only show changes merged before the commit the realm runs, and modules the realm
 * doesn't run don't show at all. Addons, the launcher and this website aren't gated.
 *
 * GitHub answers are cached in the temp dir for CHANGELOG_CACHE_SECONDS, and the old copy is kept
 * when GitHub fails, so the page never waits on GitHub more than once per period.
 **/

// Where docker-compose.yml mounts the server's modules/ folder (AC_MODULES_DIR).
const CL_MODULES_MOUNT = '/srv/ac-modules';

const CL_SECTIONS = [
    'General' => 'inv_misc_book_09',
    'Classes' => 'ability_dualwield',
    'Races' => 'achievement_character_human_male',
    'Professions' => 'trade_blacksmithing',
    'Auction House' => 'inv_misc_coin_02',
    'Dungeons' => 'achievement_boss_edwinvancleef',
    'World' => 'inv_misc_map_01',
    'Mounts & Travel' => 'ability_mount_ridinghorse',
    'Collections' => 'inv_chest_cloth_17',
    'Playerbots' => 'inv_misc_groupneedmore',
    'User Interface' => 'inv_misc_gear_01',
    'Realm Website' => 'inv_letter_15',
];

const CL_CLASSES = [
    'Death Knight' => 'spell_deathknight_classicon',
    'Druid' => 'classicon_druid',
    'Hunter' => 'classicon_hunter',
    'Mage' => 'classicon_mage',
    'Paladin' => 'classicon_paladin',
    'Priest' => 'classicon_priest',
    'Rogue' => 'classicon_rogue',
    'Shaman' => 'classicon_shaman',
    'Warlock' => 'classicon_warlock',
    'Warrior' => 'classicon_warrior',
    'All Classes' => 'ability_dualwield',
];

// Default section (and sub-heading) for a repo, by name. First match wins.
const CL_REPO_SECTIONS = [
    '/forever-(druid|hunter|mage|paladin|priest|rogue|shaman|warlock|warrior)$/' => ['Classes', '$1'],
    '/druid-/' => ['Classes', 'Druid'],
    '/addon-MinimalHunter$/' => ['User Interface', 'MinimalHunter'],
    '/forever-racials$/' => ['Races', null],
    '/(class-combos|talent-per-level|learn-spells|dualspec|periodic-crit)$/' => ['Classes', 'All Classes'],
    '/(professions|gathering|specializations|tier0-trainers|reagent-bank|mass-|craft-cd|cursor-fishing)/' => ['Professions', null],
    '/retail-ah$|ah-progression$/' => ['Auction House', null],
    '/addon-Auctionator$/' => ['Auction House', 'Auctionator'],
    '/dungeon/' => ['Dungeons', null],
    '/(weather|era-events|holiday-control|rare-tracker|biome-effects|npc-finder|hearthstone-cd|server-mail|stack-size)$/' => ['World', null],
    '/(automount|mount-|flight-paths|accountwide-mounts)/' => ['Mounts & Travel', null],
    '/(transmog|accountwide-pets|pet-loot|gear-vault)/' => ['Collections', null],
    '/(bot-|llm-chatter|world-buff-bots|addon-unbot)/' => ['Playerbots', null],
    '/Portalkeeper/' => ['User Interface', 'Portalkeeper'],
    '/^wow-addon-(.+)$/' => ['User Interface', '$1'],
    '/(azerothcore-portal|realm-armory)$/' => ['Realm Website', null],
];

// PR titles skipped when there are no hand-written notes and no "Patch Notes" section.
const CL_NOISE = '/^(bump|pin|merge|revert|readme|docs?|ci|chore|build|test)\b|\b(IsHeadless|IsBot|core[- ]align|compile|syntax|README|uninstall|conf\.dist)\b/i';

function cl_env(string $key, string $default = ''): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function cl_enabled(): bool
{
    return cl_env('CHANGELOG_ORG') !== '';
}

/** GET a GitHub API path (or full URL). Returns [status, decoded body]. */
function cl_github(string $path): array
{
    $url = str_starts_with($path, 'https://') ? $path : 'https://api.github.com/' . ltrim($path, '/');
    $headers = ['Accept: application/vnd.github+json', 'User-Agent: azerothcore-portal-changelog', 'X-GitHub-Api-Version: 2022-11-28'];
    if (($token = cl_env('GITHUB_TOKEN')) !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $context = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'header' => implode("\r\n", $headers)]]);
    $body = @file_get_contents($url, false, $context);
    $responseHeaders = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    $status = 0;
    if (isset($responseHeaders[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $responseHeaders[0], $match)) {
        $status = (int)$match[1];
    }
    return [$status, $body === false ? null : json_decode($body, true)];
}

/** owner/repo (lowercase) from a git URL, or null when it isn't on GitHub. */
function cl_repo_slug(string $url): ?string
{
    if (!preg_match('#github\.com[/:]([^/]+)/([^/\s]+?)(?:\.git)?/?$#i', trim($url), $match)) {
        return null;
    }
    return strtolower($match[1] . '/' . $match[2]);
}

/** [owner/repo => checked-out commit] for every git checkout in a folder (the server's modules/). */
function cl_scan_modules(string $dir): array
{
    $pins = [];
    foreach (glob(rtrim($dir, '/') . '/*/.git', GLOB_ONLYDIR) ?: [] as $git) {
        $config = (string)@file_get_contents("$git/config");
        $head = trim((string)@file_get_contents("$git/HEAD"));
        if (!preg_match('/\[remote "origin"\][^\[]*?\burl\s*=\s*(\S+)/', $config, $remote) || !($slug = cl_repo_slug($remote[1]))) {
            continue;
        }
        if (str_starts_with($head, 'ref: ')) {
            $ref = substr($head, 5);
            $sha = trim((string)@file_get_contents("$git/$ref"));
            if ($sha === '' && preg_match('/^([0-9a-f]{40}) ' . preg_quote($ref, '/') . '$/m', (string)@file_get_contents("$git/packed-refs"), $packed)) {
                $sha = $packed[1];
            }
            $head = $sha;
        }
        if (preg_match('/^[0-9a-f]{40}$/', $head)) {
            $pins[$slug] = $head;
        }
    }
    return $pins;
}

/**
 * What the realm runs, as [owner/repo => commit], or null when it isn't known (nothing is gated).
 * By default that's the server's modules/ folder, which docker-compose.yml mounts at
 * /srv/ac-modules from AC_MODULES_DIR. CHANGELOG_MANIFEST replaces it with another modules/ folder,
 * a modules.tsv file, or "owner/repo:path/in/repo" for a modules.tsv on GitHub (needs a token for
 * a private repo).
 */
function cl_manifest(): ?array
{
    $source = cl_env('CHANGELOG_MANIFEST');
    if ($source === '') {
        // AC_MODULES_DIR unset: the mount is an empty folder, so show everything that was merged.
        return cl_scan_modules(CL_MODULES_MOUNT) ?: null;
    }
    if (is_dir($source)) {
        $pins = cl_scan_modules($source);
        if (!$pins) {
            throw new RuntimeException('No git checkouts found in CHANGELOG_MANIFEST.');
        }
        return $pins;
    }
    if (str_starts_with($source, '/')) {
        $text = @file_get_contents($source);
    } else {
        [$repo, $path] = array_pad(explode(':', $source, 2), 2, 'manifest/modules.tsv');
        [$status, $file] = cl_github("repos/$repo/contents/$path");
        $text = ($status === 200 && isset($file['content'])) ? base64_decode($file['content']) : false;
    }
    if ($text === false) {
        throw new RuntimeException('Could not read CHANGELOG_MANIFEST.');
    }
    $pins = [];
    foreach (preg_split('/\R/', $text) as $line) {
        $cols = explode("\t", $line);
        if (count($cols) >= 4 && preg_match('/^[0-9a-f]{40}$/', $cols[3]) && ($slug = cl_repo_slug($cols[1]))) {
            $pins[$slug] = $cols[3];
        }
    }
    return $pins;
}

/** Commit date of a pinned commit. Commits never change, so answers are kept forever. */
function cl_commit_date(string $slug, string $sha, array &$known): ?string
{
    if (!isset($known[$sha])) {
        [$status, $commit] = cl_github("repos/$slug/commits/$sha");
        if ($status !== 200 || empty($commit['commit']['committer']['date'])) {
            return null;
        }
        $known[$sha] = $commit['commit']['committer']['date'];
    }
    return $known[$sha];
}

/** Bullets and developers' note from a PR body's "## Patch Notes" section, or null without one. */
function cl_body_notes(string $body): ?array
{
    if (!preg_match('/^#{1,4}\s*patch notes\s*:?\s*$(.*?)(?=^#{1,4}\s|\z)/ims', $body, $match)) {
        return null;
    }
    $notes = [];
    $devnote = [];
    foreach (preg_split('/\R/', trim($match[1])) as $line) {
        $line = trim($line);
        if (preg_match('/^[-*]\s+(.+)$/', $line, $bullet)) {
            $notes[] = $bullet[1];
        } elseif (preg_match('/^>\s*(.*)$/', $line, $quote)) {
            $devnote[] = preg_replace("/^developers?'? notes?:\s*/i", '', $quote[1]);
        } elseif ($line !== '' && !preg_match('/^(none|n\/a|-+)\.?$/i', $line)) {
            $notes[] = $line;
        }
    }
    return ['notes' => $notes, 'devnote' => trim(implode(' ', $devnote))];
}

/** Everything the page needs from GitHub, before hand-written notes are applied. */
function cl_fetch(array &$commitDates): array
{
    $org = cl_env('CHANGELOG_ORG');
    $pattern = '/' . str_replace('/', '\/', cl_env('CHANGELOG_REPOS', '^(wow-|mod-)')) . '/';
    $ungated = array_filter(array_map('trim', explode(',', strtolower(cl_env('CHANGELOG_UNGATED', 'wow-mod-azerothcore-portal')))));

    $repos = [];
    for ($page = 1; $page <= 5; $page++) {
        [$status, $list] = cl_github("orgs/$org/repos?type=all&per_page=100&page=$page");
        if ($status === 404 && $page === 1) {
            [$status, $list] = cl_github("users/$org/repos?type=all&per_page=100&page=$page");
        }
        if ($status !== 200 || !is_array($list)) {
            throw new RuntimeException("GitHub repo list failed ($status).");
        }
        foreach ($list as $repo) {
            if (!$repo['archived'] && preg_match($pattern, $repo['name'])) {
                $repos[$repo['name']] = $repo;
            }
        }
        if (count($list) < 100) {
            break;
        }
    }

    // When the realm's manifest is known: [repo name => pinned commit date, or false = not installed].
    $live = [];
    $pins = cl_manifest();
    if ($pins !== null) {
        foreach ($repos as $name => $repo) {
            $slug = strtolower($repo['full_name']);
            if (isset($pins[$slug])) {
                // Unknown (GitHub hiccup, rate limit): fail the rebuild so the old copy stays, rather than hide the module.
                $live[$name] = cl_commit_date($slug, $pins[$slug], $commitDates)
                    ?? throw new RuntimeException("Could not look up $slug@{$pins[$slug]}.");
            } elseif (preg_match('/(^|-)mod-/', $name) && !in_array(strtolower($name), $ungated, true)) {
                $live[$name] = false;
            }
        }
    }

    $entries = [];
    foreach ($repos as $name => $repo) {
        if (($live[$name] ?? true) === false) {
            continue;
        }
        $entries[] = [
            'id' => $name,
            'repo' => $name,
            'launch' => true,
            'date' => $repo['created_at'],
            'title' => $repo['description'] ?: $name,
            'url' => $repo['html_url'],
        ];
    }

    // The search API stops at 1000 results; that's years of PRs at this pace.
    for ($page = 1; $page <= 10; $page++) {
        $query = rawurlencode("org:$org is:pr is:merged");
        [$status, $result] = cl_github("search/issues?q=$query&sort=created&order=desc&per_page=100&page=$page");
        if ($status !== 200 || !isset($result['items'])) {
            throw new RuntimeException("GitHub search failed ($status).");
        }
        foreach ($result['items'] as $pr) {
            $name = basename($pr['repository_url']);
            $merged = $pr['pull_request']['merged_at'] ?? null;
            $pinned = $live[$name] ?? true;
            if (!isset($repos[$name]) || !$merged || $pinned === false
                || (is_string($pinned) && strtotime($merged) > strtotime($pinned) + 60)) {
                continue;
            }
            $entries[] = [
                'id' => $name . '#' . $pr['number'],
                'repo' => $name,
                'launch' => false,
                'date' => $merged,
                'title' => $pr['title'],
                'url' => $pr['html_url'],
                'body' => cl_body_notes((string)($pr['body'] ?? '')),
            ];
        }
        if (count($result['items']) < 100) {
            break;
        }
    }

    return ['built' => time(), 'gated' => $pins !== null, 'entries' => $entries];
}

/** Cached GitHub data. Rebuilds at most once per CHANGELOG_CACHE_SECONDS; serves the old copy if GitHub fails. */
function cl_data(): ?array
{
    $file = sys_get_temp_dir() . '/portal-changelog.json';
    $datesFile = sys_get_temp_dir() . '/portal-changelog-commits.json';
    $ttl = max(60, (int)cl_env('CHANGELOG_CACHE_SECONDS', '600'));

    $cached = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    if ($cached && $cached['built'] + $ttl > time()) {
        return $cached;
    }

    set_time_limit(120); // the first build looks up every pinned commit
    $lock = fopen($file . '.lock', 'c');
    if (!$lock || !flock($lock, $cached ? LOCK_EX | LOCK_NB : LOCK_EX)) {
        return $cached; // someone else is rebuilding: use the old copy
    }
    try {
        $fresh = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        if ($fresh && $fresh['built'] + $ttl > time()) {
            return $fresh;
        }
        $dates = is_file($datesFile) ? (json_decode((string)file_get_contents($datesFile), true) ?: []) : [];
        try {
            $data = cl_fetch($dates);
        } catch (RuntimeException $e) {
            error_log('changelog: ' . $e->getMessage());
            if ($cached) {
                $cached['built'] = time() - $ttl + 120; // try GitHub again in two minutes
                file_put_contents($file, json_encode($cached), LOCK_EX);
            }
            return $cached;
        }
        file_put_contents($datesFile, json_encode($dates), LOCK_EX);
        file_put_contents($file, json_encode($data), LOCK_EX);
        return $data;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Hand-written notes (application/changelog-notes.json). */
function cl_overrides(): array
{
    $data = json_decode((string)@file_get_contents(__DIR__ . '/../changelog-notes.json'), true);
    return ['prs' => $data['prs'] ?? [], 'launches' => $data['launches'] ?? []];
}

function cl_repo_section(string $repo): array
{
    foreach (CL_REPO_SECTIONS as $pattern => [$section, $sub]) {
        if (preg_match($pattern, $repo, $match)) {
            if ($sub !== null) {
                $sub = preg_replace_callback('/\$(\d)/', fn($m) => ucfirst($match[(int)$m[1]] ?? ''), $sub);
            }
            return [$section, $sub];
        }
    }
    return ['General', null];
}

/**
 * Notes ready to print, newest day first:
 * [date => ['features' => [note...], 'changes' => [section => [sub => [note...]]], 'fixes' => same]].
 * A note is ['title'?, 'notes' => [...], 'devnote', 'url', 'repo'].
 */
function cl_patch_notes(array $data, DateTimeZone $tz): array
{
    $overrides = cl_overrides();
    $days = [];
    foreach ($data['entries'] as $entry) {
        $custom = $entry['launch'] ? ($overrides['launches'][$entry['repo']] ?? null) : ($overrides['prs'][$entry['id']] ?? null);
        if (!empty($custom['hide'])) {
            continue;
        }
        if ($custom) {
            $notes = $custom['notes'] ?? [];
            $devnote = $custom['devnote'] ?? '';
        } elseif (!$entry['launch'] && $entry['body'] !== null) {
            $notes = $entry['body']['notes'];
            $devnote = $entry['body']['devnote'];
        } elseif (!$entry['launch'] && !preg_match(CL_NOISE, $entry['title'])) {
            $notes = [rtrim($entry['title'], '.') . '.'];
            $devnote = '';
        } else {
            continue; // launches only show with hand-written notes; noisy titles never
        }
        if (!$notes) {
            continue;
        }

        [$section, $sub] = cl_repo_section($entry['repo']);
        $section = $custom['section'] ?? $section;
        $sub = array_key_exists('sub', $custom ?? []) ? $custom['sub'] : $sub;
        if (!isset(CL_SECTIONS[$section])) {
            $section = 'General';
        }
        $isFix = $custom['fix'] ?? (preg_match('/^fix/i', $entry['title'])
            || !preg_grep('/^fixed\b/i', $notes, PREG_GREP_INVERT));

        $note = [
            'title' => $entry['launch'] ? ($custom['title'] ?? $entry['title']) : null,
            'notes' => $notes,
            'devnote' => $devnote,
            'url' => $entry['url'],
            'repo' => $entry['repo'],
            'date' => $entry['date'],
            'section' => $section,
            'sub' => $sub,
        ];
        $day = (new DateTimeImmutable($entry['date']))->setTimezone($tz)->format('Y-m-d');
        $days[$day] ??= ['features' => [], 'changes' => [], 'fixes' => []];
        if ($entry['launch']) {
            $days[$day]['features'][] = $note;
        } else {
            $days[$day][$isFix ? 'fixes' : 'changes'][$section][$sub ?? ''][] = $note;
        }
    }

    krsort($days);
    $order = array_flip(array_keys(CL_SECTIONS));
    foreach ($days as &$day) {
        foreach (['changes', 'fixes'] as $kind) {
            uksort($day[$kind], fn($a, $b) => $order[$a] <=> $order[$b]);
            foreach ($day[$kind] as &$subs) {
                ksort($subs); // '' (no sub-heading) first, then alphabetical
                foreach ($subs as &$list) {
                    usort($list, fn($a, $b) => strcmp($a['date'], $b['date']));
                }
            }
            unset($subs, $list);
        }
        usort($day['features'], fn($a, $b) => strcmp($a['date'], $b['date']));
    }
    return $days;
}

/** Note text to HTML: escapes everything, then allows **bold**, `code` and [links](https://...). */
function cl_inline(string $text): string
{
    $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
    $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);
    return preg_replace('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2" rel="noopener">$1</a>', $html);
}
