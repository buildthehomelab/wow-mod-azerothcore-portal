<?php
/**
 * Nemesis board: the weekly champion and the race for next week, who is being hunted, the longest
 * streaks, and who finished their nemesis.
 *
 * Reads mod-nemesis' tables in the characters database, which hold the nemesis's name themselves,
 * so no world database is needed. Turned on by NEMESIS_BOARD; the menu link only shows when it's set.
 **/

$h = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$siteTitle = getenv('SITE_TITLE') ?: 'Simple Register';
$enabled = (getenv('NEMESIS_BOARD') ?: '') !== '';
$assets = 'template/advance/assets';
$rankNames = [1 => 'Marked', 2 => 'Hated', 3 => 'Relentless', 4 => 'Legendary', 5 => 'Mythic'];

$champion = null;
$thisWeek = [];
$hunted = [];
$streaks = [];
$finished = [];
$failed = false;

if ($enabled) {
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST') ?: 'ac-database',
            getenv('DB_PORT') ?: '3306',
            getenv('DB_CHARACTERS_NAME') ?: 'acore_characters'
        );
        $pdo = new PDO($dsn, getenv('DB_USER') ?: 'wow_register', getenv('DB_PASS') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 3,
        ]);

        // The week runs from the last one settled. Before the first, that's the last seven days.
        $last = $pdo->query(
            "SELECT m.week_end, m.kills, c.name AS player
             FROM mod_nemesis_champion m LEFT JOIN characters c ON c.guid = m.guid AND c.deleteDate IS NULL
             ORDER BY m.week_end DESC LIMIT 1"
        )->fetch();
        $weekStart = $last ? (int)$last['week_end'] : time() - 7 * 86400;
        if ($last && !empty($last['player'])) {
            $champion = $last;
        }

        // Counted as the module counts it: kills with no bot's part in the fight.
        $week = $pdo->prepare(
            "SELECT c.name AS player, COUNT(*) AS kills, MAX(e.at) AS last_at
             FROM mod_nemesis_event e JOIN characters c ON c.guid = e.guid
             WHERE c.deleteDate IS NULL AND e.event IN (3, 4) AND e.bots = 0 AND e.at >= ?
             GROUP BY e.guid, c.name
             ORDER BY kills DESC, last_at LIMIT 10"
        );
        $week->execute([$weekStart]);
        $thisWeek = $week->fetchAll();

        // Not a character the owner deleted: their name is gone from the page with them.
        $hunted = $pdo->query(
            "SELECT c.name AS player, h.name AS nemesis, h.`rank` AS rnk, h.kills
             FROM mod_nemesis_hunt h JOIN characters c ON c.guid = h.guid
             WHERE c.deleteDate IS NULL AND h.name <> ''
             ORDER BY h.`rank` DESC, h.kills DESC, c.name LIMIT 20"
        )->fetchAll();

        $streaks = $pdo->query(
            "SELECT c.name AS player, MAX(e.streak) AS best
             FROM mod_nemesis_event e JOIN characters c ON c.guid = e.guid
             WHERE c.deleteDate IS NULL AND e.event IN (3, 4, 5)
             GROUP BY e.guid, c.name HAVING best > 0
             ORDER BY best DESC, c.name LIMIT 10"
        )->fetchAll();

        $finished = $pdo->query(
            "SELECT c.name AS player, COUNT(*) AS total, MIN(e.at) AS first_at
             FROM mod_nemesis_event e JOIN characters c ON c.guid = e.guid
             WHERE c.deleteDate IS NULL AND e.event = 4
             GROUP BY e.guid, c.name
             ORDER BY total DESC, first_at LIMIT 10"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('nemesis board: ' . $e->getMessage());
        $failed = true;
    }
} else {
    http_response_code(404);
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nemesis Board · <?php echo $h($siteTitle); ?></title>
    <meta name="description" content="This week's Nemesis Hunter, who is being hunted right now, the longest streaks, and who has finished their nemesis.">
    <link href="favicon.ico" rel="icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Marcellus&family=Open+Sans:wght@400;600&display=swap" rel="stylesheet">
    <link href="<?php echo $assets; ?>/css/nemesis.css" rel="stylesheet">
</head>
<body>
<header class="nb-top">
    <a class="nb-home" href="./">&larr; <?php echo $h($siteTitle); ?></a>
    <h1>Nemesis Board</h1>
    <p>Kill a rare and it may come back for you. Each kill makes it stronger, and if it kills you, the hunt is over.</p>
</header>

<?php if (!$enabled) { ?>
<main class="nb-off"><p>The nemesis board isn't turned on for this realm.</p></main>
<?php } elseif ($failed) { ?>
<main class="nb-off"><p>The board can't be read right now. Please try again later.</p></main>
<?php } else { ?>
<main class="nb-boards">
    <?php if ($champion) { ?>
    <section class="nb-board nb-wide nb-champion">
        <p class="nb-label">This week's Nemesis Hunter</p>
        <p class="nb-name"><?php echo $h($champion['player']); ?></p>
        <p class="nb-empty"><?php echo (int)$champion['kills']; ?> kills last week, and the title until the next weekly reset.</p>
    </section>
    <?php } ?>

    <section class="nb-board nb-wide">
        <h2>This week's race</h2>
        <?php if (!$thisWeek) { ?>
        <p class="nb-empty">Nobody has killed their nemesis this week. The most kills without bots by the weekly reset earns the title the Nemesis Hunter.</p>
        <?php } else { ?>
        <p class="nb-empty nb-note">The most kills without bots by the weekly reset earns the title the Nemesis Hunter.</p>
        <table>
            <thead><tr><th>Character</th><th class="nb-num">Kills</th></tr></thead>
            <tbody>
            <?php foreach ($thisWeek as $row) { ?>
                <tr><td><?php echo $h($row['player']); ?></td><td class="nb-num"><?php echo (int)$row['kills']; ?></td></tr>
            <?php } ?>
            </tbody>
        </table>
        <?php } ?>
    </section>

    <section class="nb-board nb-wide">
        <h2>Being hunted now</h2>
        <?php if (!$hunted) { ?>
        <p class="nb-empty">Nobody is being hunted right now.</p>
        <?php } else { ?>
        <table>
            <thead><tr><th>Character</th><th>Nemesis</th><th>Rank</th><th class="nb-num">Kills</th></tr></thead>
            <tbody>
            <?php foreach ($hunted as $row) { $rank = (int)$row['rnk']; ?>
                <tr>
                    <td><?php echo $h($row['player']); ?></td>
                    <td><?php echo $h($row['nemesis']); ?></td>
                    <td class="nb-rank nb-rank-<?php echo $rank; ?>"><?php echo $h($rankNames[$rank] ?? (string)$rank); ?></td>
                    <td class="nb-num"><?php echo (int)$row['kills']; ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <?php } ?>
    </section>

    <section class="nb-board">
        <h2>Longest streaks</h2>
        <?php if (!$streaks) { ?>
        <p class="nb-empty">No nemesis has been killed yet.</p>
        <?php } else { ?>
        <table>
            <thead><tr><th>Character</th><th class="nb-num">Kills in one hunt</th></tr></thead>
            <tbody>
            <?php foreach ($streaks as $row) { ?>
                <tr><td><?php echo $h($row['player']); ?></td><td class="nb-num"><?php echo (int)$row['best']; ?></td></tr>
            <?php } ?>
            </tbody>
        </table>
        <?php } ?>
    </section>

    <section class="nb-board">
        <h2>Bane of the Undying</h2>
        <?php if (!$finished) { ?>
        <p class="nb-empty">Nobody has finished their nemesis yet.</p>
        <?php } else { ?>
        <table>
            <thead><tr><th>Character</th><th class="nb-num">Finished</th></tr></thead>
            <tbody>
            <?php foreach ($finished as $row) { ?>
                <tr><td><?php echo $h($row['player']); ?></td><td class="nb-num"><?php echo (int)$row['total']; ?></td></tr>
            <?php } ?>
            </tbody>
        </table>
        <?php } ?>
    </section>
</main>
<?php } ?>
</body>
</html>
