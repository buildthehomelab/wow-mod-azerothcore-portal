<?php
/**
 * What is happening on the realm right now, for the launcher's home screen (api/launcher/live.php).
 * Each part has its own source and is null when that source is off or didn't answer:
 *
 *   players  characters online, real players and bots apart (bots = accounts matching LIVE_BOT_ACCOUNTS)
 *   server   uptime and a pending restart, from ".server info" over SOAP (as for the shop)
 *   events   the world events that are on, from ".event activelist" over SOAP; only holidays (and
 *            the ones LIVE_SHOW_EVENTS names) when the world database's game_event can be read
 *   rares    how many rares are up and which world bosses, from the rare tracker (RARE_TRACKER_URL)
 *
 * The answer is kept for LIVE_CACHE_SECONDS in LIVE_CACHE_DIR, so however many launchers ask, the
 * world server runs at most two console commands per period.
 **/

declare(strict_types=1);

require_once __DIR__ . '/launcher.php';

const LIVE_SOAP_TIMEOUT = 4; // seconds per console command

function live_cache_seconds(): int
{
    return max(5, (int)launcher_env('LIVE_CACHE_SECONDS', '30'));
}

/**
 * The cache file: LIVE_CACHE_DIR, else the temp folder. Never none: anyone can call live.php, and
 * without the cache every call would cost the world server two console commands.
 */
function live_cache_file(): string
{
    foreach ([launcher_env('LIVE_CACHE_DIR'), sys_get_temp_dir() . '/portal-live'] as $dir) {
        $dir = rtrim($dir, '/');
        if ($dir !== '' && (is_dir($dir) || @mkdir($dir, 0750, true) || is_dir($dir)) && is_writable($dir)) {
            return $dir . '/live.json';
        }
    }
    return sys_get_temp_dir() . '/portal-live.json';
}

/**
 * The cached answer while it's fresh, else a new one. One request builds at a time; the others get
 * the last answer, however old, instead of waiting on a slow world server or database. Only before
 * the very first answer does a request wait for the build.
 */
function live_info(): array
{
    $file = live_cache_file();
    $read = static function () use ($file): ?array {
        $data = json_decode((string)@file_get_contents($file), true);
        return is_array($data) && isset($data['generated']) ? $data : null;
    };
    $fresh = static fn(?array $data): bool => $data !== null && time() - (int)$data['generated'] < live_cache_seconds();
    $cached = $read();
    if ($fresh($cached)) {
        return $cached;
    }

    $lock = @fopen($file . '.lock', 'c');
    if ($lock === false) {
        return $cached ?? live_unknown();
    }
    try {
        if (!flock($lock, LOCK_NB | LOCK_EX)) {
            if ($cached !== null) {
                return $cached;
            }
            flock($lock, LOCK_EX);
            return $read() ?? live_unknown();
        }
        // Another request may have built it between the first look and the lock.
        if ($fresh($data = $read())) {
            return $data;
        }
        $data = live_build();
        $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temp, json_encode($data, JSON_UNESCAPED_SLASHES)) !== false) {
            @rename($temp, $file);
        }
        return $data;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Nothing known: every part null. */
function live_unknown(): array
{
    return ['generated' => time(), 'players' => null, 'server' => null, 'events' => null, 'rares' => null];
}

function live_build(): array
{
    $soap = launcher_env('SOAP_USER') !== '';
    return [
        'generated' => time(),
        'players' => live_players(),
        'server' => $soap ? live_server(launcher_soap('server info', LIVE_SOAP_TIMEOUT)) : null,
        'events' => $soap ? live_events(launcher_soap('event activelist', LIVE_SOAP_TIMEOUT)) : null,
        'rares' => live_rares(),
    ];
}

/** {"online", "bots"}: characters in the world, players and bots counted apart. */
function live_players(): ?array
{
    try {
        $characters = '`' . str_replace('`', '', launcher_env('DB_CHARACTERS_NAME', 'acore_characters')) . '`.`characters`';
        $statement = launcher_db()->prepare(
            'SELECT a.username LIKE ? AS bot, COUNT(*) AS n FROM ' . $characters . ' c'
            . ' JOIN ' . launcher_auth_table('account') . ' a ON a.id = c.account'
            . ' WHERE c.online = 1 GROUP BY bot');
        $statement->execute([launcher_env('LIVE_BOT_ACCOUNTS', 'RNDBOT%')]);
        $counts = [0 => 0, 1 => 0];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(int)$row['bot']] = (int)$row['n'];
        }
        return ['online' => $counts[0], 'bots' => $counts[1]];
    } catch (Throwable $e) {
        error_log('live: online players: ' . $e->getMessage());
        return null;
    }
}

/**
 * {"uptime", "restart_in"} in seconds from ".server info": restart_in is null unless the world server
 * is counting down to a shutdown or restart ("Time left until shutdown/restart: 9 minute(s) 30 second(s).").
 */
function live_server(?string $answer): ?array
{
    if ($answer === null || !preg_match('/^Server uptime:\s*(.+)$/mi', $answer, $uptime)) {
        return null;
    }
    $restart = preg_match('/^Time left until shutdown\/restart:\s*(.+)$/mi', $answer, $left) ? live_seconds($left[1]) : null;
    return ['uptime' => live_seconds($uptime[1]), 'restart_in' => $restart];
}

/** Seconds in an AzerothCore duration: "1 day(s) 2 hour(s) 3 minute(s) 4 second(s)" or "1d2h3m4s". */
function live_seconds(string $text): int
{
    $units = ['d' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
    preg_match_all('/(\d+)\s*(day|hour|minute|second|d|h|m|s)/i', $text, $parts, PREG_SET_ORDER);
    $seconds = 0;
    foreach ($parts as [, $count, $unit]) {
        $seconds += (int)$count * $units[strtolower($unit[0])];
    }
    return $seconds;
}

/**
 * [{"id", "name"}] from ".event activelist" (console lines "17 - Scourge Invasion  [active]"). Many
 * active events are the realm's bookkeeping (fishing pools, "Building" stages, arena seasons), so
 * when game_event can be read only holidays are kept, plus LIVE_SHOW_EVENTS; LIVE_HIDE_EVENTS always
 * drops one. Both are comma-separated event ids.
 */
function live_events(?string $answer): ?array
{
    if ($answer === null) {
        return null;
    }
    $ids = static fn(string $key): array => array_map('intval', preg_split('/\s*,\s*/', trim(launcher_env($key)), -1, PREG_SPLIT_NO_EMPTY));
    $show = $ids('LIVE_SHOW_EVENTS');
    $hide = $ids('LIVE_HIDE_EVENTS');

    $events = [];
    foreach (preg_split('/\r?\n/', $answer) as $line) {
        if (preg_match('/^\s*(\d+)\s+-\s+(.+?)\s*\[active\]\s*$/', $line, $match) && !in_array((int)$match[1], $hide, true)) {
            $events[(int)$match[1]] = trim($match[2]);
        }
    }

    $holidays = live_holiday_events(array_keys($events));
    $list = [];
    foreach ($events as $id => $name) {
        if ($holidays === null || isset($holidays[$id]) || in_array($id, $show, true)) {
            $list[] = ['id' => $id, 'name' => $name];
        }
    }
    return $list;
}

/**
 * Which of these events are holidays a player would care about ([id => true]): a holiday id, and not
 * one of the "Building" stages before a fair or festival opens. Null when game_event can't be read
 * (the portal's database user needs SELECT on it).
 */
function live_holiday_events(array $ids): ?array
{
    if (!$ids) {
        return [];
    }
    try {
        $table = '`' . str_replace('`', '', launcher_env('DB_WORLD_NAME', 'acore_world')) . '`.`game_event`';
        $statement = launcher_db()->prepare('SELECT eventEntry FROM ' . $table
            . ' WHERE holiday <> 0 AND description NOT LIKE \'%Building%\' AND eventEntry IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')');
        $statement->execute($ids);
        return array_fill_keys(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)), true);
    } catch (Throwable $e) {
        return null;
    }
}

/** {"up", "total", "world_bosses": [{"name", "zone", "up", "respawn_at"}]} from the rare tracker. */
function live_rares(): ?array
{
    $url = launcher_env('RARE_TRACKER_URL');
    if ($url === '') {
        return null;
    }
    $context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => false]]);
    $data = json_decode((string)@file_get_contents($url, false, $context), true);
    if (!is_array($data) || empty($data['generated'])) {
        return null;
    }
    $zones = $data['zones'] ?? [];
    // One row per spawn point: a rare (or world boss) with several counts once, up when any spawn is.
    $up = $all = $bosses = [];
    foreach ($data['rares'] ?? [] as $rare) {
        $entry = (int)($rare['entry'] ?? 0);
        $isUp = ($rare['state'] ?? '') === 'up';
        if (empty($rare['boss'])) {
            $all[$entry] = true;
            if ($isUp) {
                $up[$entry] = true;
            }
            continue;
        }
        $boss = $bosses[$entry] ?? null;
        if ($boss === null || ($isUp && !$boss['up'])) {
            $bosses[$entry] = [
                'name' => (string)($rare['name'] ?? ''),
                'zone' => (string)($zones[(string)($rare['zone'] ?? '')] ?? ''),
                'up' => $isUp,
                'respawn_at' => $isUp || !isset($rare['respawnAt']) ? null : (int)$rare['respawnAt'],
            ];
        }
    }
    return ['up' => count($up), 'total' => count($all), 'world_bosses' => array_values($bosses)];
}
