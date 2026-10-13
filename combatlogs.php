<?php
/**
 * Combat logs shared by players (Portalkeeper's "Share combat logs"), digested for tuning the bots:
 * per fight, who died to what, which enemy abilities landed, what was interrupted and dispelled.
 *
 * Not linked from the menu. Opens with COMBATLOG_VIEW_KEY; 404 without COMBATLOG_ENABLED and a key.
 * See application/launcher/combatlog.php.
 **/

declare(strict_types=1);

require_once __DIR__ . '/application/launcher/combatlog.php';

$h = static fn(?string $text): string => htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
$n = static fn(int|float|null $number): string => $number === null ? '' : number_format((float)$number);
$siteTitle = getenv('SITE_TITLE') ?: 'Simple Register';
$assets = 'template/advance/assets';

$enabled = clog_enabled() && clog_view_key() !== '';
$allowed = false;
$keyError = false;
if ($enabled) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict',
        'secure' => str_starts_with(launcher_base_url(), 'https://')]);
    session_start();
    if (isset($_GET['logout'])) {
        unset($_SESSION['combatlogs']);
        header('Location: combatlogs.php');
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (clog_key_matches((string)($_POST['key'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['combatlogs'] = true;
            header('Location: combatlogs.php');
            exit;
        }
        sleep(1);
        $keyError = true;
    }
    $allowed = !empty($_SESSION['combatlogs']);
} else {
    http_response_code(404);
}

$meta = null;
$digest = null;
$logs = [];
if ($allowed) {
    $id = (string)($_GET['log'] ?? '');
    if ($id !== '') {
        $meta = clog_log($id);
        if ($meta === null) {
            http_response_code(404);
        } elseif (!empty($_GET['raw'])) {
            clog_send_raw($meta);
        } else {
            $digest = clog_digest($id);
        }
    } else {
        $logs = clog_metas('logs');
    }
}

/** A moment on the uploader's clock, the one the log's own stamps use. */
$when = static fn(?int $time, array $meta, string $format = 'M j, H:i'): string =>
    $time === null ? '' : gmdate($format, $time + 60 * (int)($meta['utc_offset_minutes'] ?? 0));
$length = static function (int $seconds): string {
    return $seconds >= 3600 ? sprintf('%dh %02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60))
        : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
};
$bytes = static fn(?int $size): string => $size === null ? '' : ($size >= 1048576
    ? number_format($size / 1048576, 1) . ' MB' : number_format($size / 1024) . ' KB');
$who = static function (array $by) use ($h): string {
    $parts = [];
    foreach ($by as $name => $times) {
        $parts[] = $h((string)$name) . ($times > 1 ? ' &times;' . (int)$times : '');
    }
    return implode(', ', $parts);
};
$bots = array_fill_keys($digest['bots'] ?? [], true);
$name = static fn(string $player): string => $h($player) . (isset($bots[$player]) ? ' <span class="cl-bot">bot</span>' : '');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Combat Logs · <?php echo $h($siteTitle); ?></title>
    <link href="favicon.ico" rel="icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Marcellus&family=Open+Sans:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
    <link href="<?php echo $assets; ?>/css/changelog.css" rel="stylesheet">
    <link href="<?php echo $assets; ?>/css/combatlogs.css" rel="stylesheet">
</head>
<body class="cl-body">
<header class="cl-head">
    <a class="pn-home" href="./">&larr; <?php echo $h($siteTitle); ?></a>
    <h1><a href="combatlogs.php">Combat Logs</a></h1>
    <?php if ($allowed) { ?><a class="cl-out" href="combatlogs.php?logout=1">Lock</a><?php } ?>
</header>

<?php if (!$enabled) { ?>
<main class="pn-empty"><p>Combat logs aren't turned on for this realm.</p></main>

<?php } elseif (!$allowed) { ?>
<main class="cl-main cl-narrow">
    <form class="cl-key" method="post" action="combatlogs.php" autocomplete="off">
        <label for="key">View key</label>
        <input id="key" name="key" type="password" required autofocus>
        <button type="submit">Open</button>
        <?php if ($keyError) { ?><p class="cl-error">That isn't the key.</p><?php } ?>
    </form>
</main>

<?php } elseif ($meta === null && isset($_GET['log'])) { ?>
<main class="pn-empty"><p>No such combat log. It may have passed the realm's keep time.</p></main>

<?php } elseif ($meta !== null && $digest === null) { ?>
<main class="pn-empty"><p>This log is being digested. Reload in a moment.</p></main>

<?php } elseif ($meta !== null) {
    $fights = $digest['fights']; ?>
<main class="cl-main">
    <section class="cl-summary">
        <div>
            <p class="cl-kicker"><?php echo $h($when($digest['start'], $meta, 'l, F j')); ?></p>
            <h2><?php echo $h($digest['logger'] ?? $meta['account']); ?>
                <small><?php echo $h($when($digest['start'], $meta, 'H:i')); ?>&ndash;<?php echo $h($when($digest['end'], $meta, 'H:i')); ?></small></h2>
            <p class="cl-meta">Account <?php echo $h($meta['account']); ?> &middot; <?php echo $n($digest['lines']); ?> lines
                &middot; <?php echo count($digest['players']); ?> players (<?php echo count($digest['bots']); ?> bots)
                &middot; <a href="combatlogs.php?log=<?php echo $h($meta['id']); ?>&amp;raw=1">Download the log (<?php echo $bytes((int)$meta['size']); ?> gzip)</a></p>
            <?php if (($digest['bosses'] ?? '') !== 'realm') { ?>
            <p class="cl-meta">Which fights are bosses is a guess from the log: the portal can't read the realm's creature data.</p>
            <?php } ?>
        </div>
        <dl class="cl-stats">
            <div><dt>Fights</dt><dd><?php echo count($fights); ?></dd></div>
            <div><dt>Boss kills</dt><dd><?php echo count(array_filter($fights, static fn($f) => $f['boss'] && $f['outcome'] === 'kill')); ?></dd></div>
            <div><dt>Boss wipes</dt><dd><?php echo count(array_filter($fights, static fn($f) => $f['boss'] && $f['outcome'] !== 'kill')); ?></dd></div>
            <div><dt>Deaths</dt><dd><?php echo $n($digest['deaths']); ?></dd></div>
        </dl>
    </section>

    <?php if ($digest['killers']) { ?>
    <section class="cl-block">
        <h3>What killed players</h3>
        <table class="cl-table">
            <thead><tr><th>Ability</th><th>From</th><th class="num">Deaths</th></tr></thead>
            <tbody>
            <?php foreach ($digest['killers'] as $killer) { ?>
                <tr><td><?php echo $h($killer['spell']); ?></td><td><?php echo $h($killer['src']); ?></td><td class="num"><?php echo (int)$killer['kills']; ?></td></tr>
            <?php } ?>
            </tbody>
        </table>
    </section>
    <?php } ?>

    <?php foreach ($digest['runs'] as $runIndex => $run) {
        $runFights = array_map(static fn(int $index): array => $fights[$index], $run['fights']); ?>
    <section class="cl-block">
        <h3>Run <?php echo $runIndex + 1; ?> <small><?php echo $h(substr($run['clock'], 0, 5)); ?>, <?php echo $length($run['end'] - $run['start']); ?>,
            <?php echo count($runFights); ?> fights, <?php echo array_sum(array_column($runFights, 'deaths')); ?> deaths</small></h3>

        <?php foreach ($run['fights'] as $index) {
            $fight = $fights[$index]; ?>
        <details class="cl-fight<?php echo $fight['boss'] ? ' is-boss' : ''; ?> is-<?php echo $h($fight['outcome']); ?>" id="fight-<?php echo $index; ?>">
            <summary>
                <span class="cl-time"><?php echo $h($fight['clock']); ?></span>
                <span class="cl-name"><?php echo $h($fight['name']); ?><?php if (count($fight['enemies']) > 1) { ?> <small>+<?php echo count($fight['enemies']) - 1; ?> kinds</small><?php } ?></span>
                <span class="cl-tag"><?php echo $fight['boss'] ? 'Boss' : 'Trash'; ?></span>
                <span class="cl-outcome"><?php echo ['kill' => 'Killed', 'wipe' => 'Wipe', 'left' => 'Not killed'][$fight['outcome']] ?? ''; ?></span>
                <span class="cl-len"><?php echo $length((int)$fight['duration']); ?></span>
                <span class="cl-deaths<?php echo $fight['deaths'] ? ' has' : ''; ?>"><?php echo (int)$fight['deaths']; ?> dead</span>
            </summary>

            <?php if ($fight['died']) { ?>
            <h4>Deaths</h4>
            <table class="cl-table">
                <thead><tr><th>At</th><th>Player</th><th>Killed by</th><th>Last hits</th></tr></thead>
                <tbody>
                <?php foreach ($fight['died'] as $death) { ?>
                    <tr<?php echo $death['feign'] ? ' class="is-feign"' : ''; ?>>
                        <td><?php echo $h(substr($death['clock'], 0, 8)); ?></td>
                        <td><?php echo $name($death['name']); ?></td>
                        <td><?php if ($death['feign']) { ?>Feign Death<?php } elseif ($death['by'] === null) { ?><span class="cl-dim">nothing in the last <?php echo (int)CLOG_DEATH_WINDOW; ?> s</span><?php } else { ?>
                            <?php echo $h($death['by']['spell']); ?> <span class="cl-dim"><?php echo $h($death['by']['src']); ?></span><?php } ?></td>
                        <td class="cl-hits"><?php foreach ($death['hits'] as $hit) { ?>
                            <span title="<?php echo $h($hit['src']); ?>"><?php echo $h($hit['spell']); ?> <?php echo $n($hit['amount']); ?> <i>-<?php echo $h((string)$hit['ago']); ?>s</i></span>
                        <?php } ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
            <?php } ?>

            <?php if ($fight['taken']) { ?>
            <h4>Damage players took</h4>
            <table class="cl-table">
                <thead><tr><th>Ability</th><th>From</th><th class="num">Hits</th><th class="num">Players</th><th class="num">Total</th><th class="num">Biggest</th><th class="num">Deaths</th></tr></thead>
                <tbody>
                <?php foreach ($fight['taken'] as $row) { ?>
                    <tr><td><?php echo $h($row['name']); ?></td><td><?php echo $h($row['src']); ?></td>
                        <td class="num"><?php echo $n($row['hits']); ?></td><td class="num"><?php echo (int)$row['victims']; ?></td>
                        <td class="num"><?php echo $n($row['total']); ?></td><td class="num"><?php echo $n($row['max']); ?></td>
                        <td class="num<?php echo $row['kills'] ? ' cl-bad' : ''; ?>"><?php echo $row['kills'] ?: ''; ?></td></tr>
                <?php } ?>
                </tbody>
            </table>
            <?php } ?>

            <?php if ($fight['casts']) { ?>
            <h4>Enemy casts</h4>
            <table class="cl-table">
                <thead><tr><th>Spell</th><th>Caster</th><th class="num" title="Spells with a cast time: the ones that can be interrupted">Started</th><th class="num">Interrupted</th><th>Interrupted by</th></tr></thead>
                <tbody>
                <?php foreach ($fight['casts'] as $cast) { ?>
                    <tr><td><?php echo $h($cast['name']); ?></td><td><?php echo $h($cast['src']); ?></td>
                        <td class="num"><?php echo (int)$cast['start']; ?></td><td class="num"><?php echo (int)$cast['stopped']; ?></td>
                        <td><?php echo $who($cast['by']); ?></td></tr>
                <?php } ?>
                </tbody>
            </table>
            <?php } ?>

            <?php if ($fight['debuffs']) { ?>
            <h4>Debuffs on players</h4>
            <table class="cl-table">
                <thead><tr><th>Debuff</th><th>From</th><th class="num">Applied</th><th class="num">Players</th><th class="num">Dispelled</th><th class="num">After</th><th>Dispelled by</th></tr></thead>
                <tbody>
                <?php foreach ($fight['debuffs'] as $debuff) { ?>
                    <tr><td><?php echo $h($debuff['name']); ?></td><td><?php echo $h($debuff['src']); ?></td>
                        <td class="num"><?php echo (int)$debuff['applied']; ?></td><td class="num"><?php echo (int)$debuff['victims']; ?></td>
                        <td class="num"><?php echo (int)$debuff['dispelled']; ?></td>
                        <td class="num"><?php echo $debuff['wait'] === null ? '' : $h((string)$debuff['wait']) . ' s'; ?></td>
                        <td><?php echo $who($debuff['by']); ?></td></tr>
                <?php } ?>
                </tbody>
            </table>
            <?php } ?>

            <?php if ($fight['buffs']) { ?>
            <h4>Enemy buffs</h4>
            <table class="cl-table">
                <thead><tr><th>Buff</th><th>On</th><th class="num">Applied</th><th class="num">Removed</th><th>Removed by</th></tr></thead>
                <tbody>
                <?php foreach ($fight['buffs'] as $buff) { ?>
                    <tr><td><?php echo $h($buff['name']); ?></td><td><?php echo $h($buff['on']); ?></td>
                        <td class="num"><?php echo (int)$buff['applied']; ?></td><td class="num"><?php echo (int)$buff['removed']; ?></td>
                        <td><?php echo $who($buff['by']); ?></td></tr>
                <?php } ?>
                </tbody>
            </table>
            <?php } ?>

            <?php if ($fight['broken']) { ?>
            <h4>Crowd control broken</h4>
            <table class="cl-table">
                <thead><tr><th>Effect</th><th>Broken by</th><th>With</th><th class="num">Times</th></tr></thead>
                <tbody>
                <?php foreach ($fight['broken'] as $broken) { ?>
                    <tr><td><?php echo $h($broken['aura']); ?></td><td><?php echo $name($broken['by']); ?></td>
                        <td><?php echo $h($broken['with']); ?></td><td class="num"><?php echo (int)$broken['times']; ?></td></tr>
                <?php } ?>
                </tbody>
            </table>
            <?php } ?>

            <h4>Players</h4>
            <table class="cl-table">
                <thead><tr><th>Player</th><th class="num">Damage</th><th class="num">Healing</th><th class="num">Overheal</th><th class="num">Taken</th>
                    <th class="num" title="Share of the fight, in 5-second steps, in which the player did anything">Active</th>
                    <th class="num" title="Seconds into the fight before the player's first action">First action</th></tr></thead>
                <tbody>
                <?php foreach ($fight['players'] as $player) {
                    $healed = $player['healing'] + $player['overheal']; ?>
                    <tr><td><?php echo $name($player['name']); ?></td>
                        <td class="num"><?php echo $player['damage'] ? $n($player['damage']) : ''; ?></td>
                        <td class="num"><?php echo $player['healing'] ? $n($player['healing']) : ''; ?></td>
                        <td class="num"><?php echo $healed ? round(100 * $player['overheal'] / $healed) . '%' : ''; ?></td>
                        <td class="num"><?php echo $player['taken'] ? $n($player['taken']) : ''; ?></td>
                        <td class="num<?php echo $fight['duration'] >= 30 && $player['active'] < 40 ? ' cl-bad' : ''; ?>"><?php echo (int)$player['active']; ?>%</td>
                        <td class="num"><?php echo $player['first'] === null ? '<span class="cl-dim">never</span>' : $h((string)$player['first']) . ' s'; ?></td></tr>
                <?php } ?>
                </tbody>
            </table>

            <h4>Enemies</h4>
            <table class="cl-table">
                <thead><tr><th>Creature</th><th class="num">Entry</th><th class="num">Seen</th><th class="num">Died</th><th class="num">Damage taken</th><th class="num">Damage done</th></tr></thead>
                <tbody>
                <?php foreach ($fight['enemies'] as $enemy) { ?>
                    <tr><td><?php echo $h($enemy['name']); ?></td><td class="num"><?php echo $enemy['entry'] ?: ''; ?></td>
                        <td class="num"><?php echo (int)$enemy['count']; ?></td><td class="num"><?php echo (int)$enemy['died']; ?></td>
                        <td class="num"><?php echo $n($enemy['taken']); ?></td><td class="num"><?php echo $n($enemy['done']); ?></td></tr>
                <?php } ?>
                </tbody>
            </table>
        </details>
        <?php } ?>
    </section>
    <?php } ?>

    <?php if ($digest['stray_deaths']) { ?>
    <section class="cl-block">
        <h3>Deaths outside fights</h3>
        <table class="cl-table">
            <thead><tr><th>At</th><th>Player</th><th>Killed by</th></tr></thead>
            <tbody>
            <?php foreach ($digest['stray_deaths'] as $death) { if ($death['feign']) { continue; } ?>
                <tr><td><?php echo $h(substr($death['clock'], 0, 8)); ?></td><td><?php echo $name($death['name']); ?></td>
                    <td><?php echo $death['by'] === null ? '' : $h($death['by']['spell']) . ' <span class="cl-dim">' . $h($death['by']['src']) . '</span>'; ?></td></tr>
            <?php } ?>
            </tbody>
        </table>
    </section>
    <?php } ?>
</main>

<?php } else { ?>
<main class="cl-main">
    <?php if (!$logs) { ?>
    <p class="cl-none">No combat logs yet. Players turn on <strong>Share combat logs</strong> in the launcher's settings; a log arrives when they close the game after a dungeon or raid.</p>
    <?php } else { ?>
    <table class="cl-table cl-list">
        <thead><tr><th>Logged</th><th>Character</th><th>Account</th><th class="num">Length</th><th class="num">Fights</th><th>Bosses</th><th class="num">Deaths</th><th class="num">Size</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $log) {
            $summary = $log['summary'] ?? null;
            $link = 'combatlogs.php?log=' . $h($log['id']); ?>
            <tr>
                <td><a href="<?php echo $link; ?>"><?php echo $h($when($summary['start'] ?? (int)$log['logged_until'], $log)); ?></a></td>
                <?php if ($summary === null) { ?>
                <td colspan="5" class="cl-dim">Not digested yet (<?php echo $h($log['account']); ?>): open it to digest it now</td>
                <td></td>
                <?php } else { ?>
                <td><?php echo $h($summary['logger']); ?></td>
                <td><?php echo $h($log['account']); ?></td>
                <td class="num"><?php echo $length(max(0, (int)$summary['end'] - (int)$summary['start'])); ?></td>
                <td class="num"><?php echo (int)$summary['fights']; ?></td>
                <td><?php foreach ($summary['kills'] as $boss) { ?><span class="cl-kill"><?php echo $h($boss); ?><?php if (!empty($summary['wipes'][$boss])) { ?> <small>after <?php echo (int)$summary['wipes'][$boss]; ?></small><?php } ?></span> <?php } ?>
                    <?php foreach ($summary['wipes'] as $boss => $times) { if (in_array($boss, $summary['kills'], true)) { continue; } ?><span class="cl-wipe"><?php echo $h((string)$boss); ?> &times;<?php echo (int)$times; ?></span> <?php } ?></td>
                <td class="num"><?php echo $n($summary['deaths']); ?></td>
                <?php } ?>
                <td class="num"><?php echo $bytes($log['raw_size'] ?? null); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    <?php } ?>
</main>
<?php } ?>
</body>
</html>
