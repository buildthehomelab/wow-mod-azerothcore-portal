<?php
/**
 * Lua errors from players' games, sent by Portalkeeper: each distinct error once, how often it
 * happened and to whom, and its stack and locals.
 *
 * Not linked from the menu. Opens with LUAERROR_VIEW_KEY, else COMBATLOG_VIEW_KEY; 404 without
 * LUAERROR_ENABLED and a key. See application/launcher/luaerrors.php.
 **/

declare(strict_types=1);

require_once __DIR__ . '/application/launcher/luaerrors.php';

$h = static fn(?string $text): string => htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
$siteTitle = getenv('SITE_TITLE') ?: 'Simple Register';
$assets = 'template/advance/assets';

$enabled = luaerr_enabled() && luaerr_view_key() !== '';
$allowed = false;
$keyError = false;
if ($enabled) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict',
        'secure' => str_starts_with(launcher_base_url(), 'https://')]);
    session_start();
    if (isset($_GET['logout'])) {
        unset($_SESSION['luaerrors']);
        header('Location: luaerrors.php');
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (luaerr_key_matches((string)($_POST['key'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['luaerrors'] = true;
            header('Location: luaerrors.php');
            exit;
        }
        sleep(1);
        $keyError = true;
    }
    $allowed = !empty($_SESSION['luaerrors']);
} else {
    http_response_code(404);
}

$asked = isset($_GET['error']);
$groups = [];
$group = null;
if ($allowed) {
    if ($asked) {
        $group = is_string($_GET['error']) ? (luaerr_groups($_GET['error'])[0] ?? null) : null;
        if ($group === null) {
            http_response_code(404);
        }
    } else {
        $groups = luaerr_groups();
    }
}

$when = static fn(?int $time): string => $time === null ? '' : gmdate('M j, H:i', $time);
$who = static function (array $accounts) use ($h): string {
    $parts = [];
    foreach ($accounts as $name => $times) {
        $parts[] = $h((string)$name) . ($times > 1 ? ' &times;' . (int)$times : '');
    }
    return implode(', ', $parts);
};
$firstLine = static function (string $text): string {
    $line = strtok($text, "\n");
    return mb_strlen((string)$line) > 220 ? mb_substr((string)$line, 0, 220) . '…' : (string)$line;
};
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Lua Errors · <?php echo $h($siteTitle); ?></title>
    <link href="favicon.ico" rel="icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Marcellus&family=Open+Sans:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
    <link href="<?php echo $assets; ?>/css/changelog.css" rel="stylesheet">
    <link href="<?php echo $assets; ?>/css/combatlogs.css" rel="stylesheet">
</head>
<body class="cl-body">
<header class="cl-head">
    <a class="pn-home" href="./">&larr; <?php echo $h($siteTitle); ?></a>
    <h1><a href="luaerrors.php">Lua Errors</a></h1>
    <?php if ($allowed) { ?><a class="cl-out" href="luaerrors.php?logout=1">Lock</a><?php } ?>
</header>

<?php if (!$enabled) { ?>
<main class="pn-empty"><p>Lua errors aren't turned on for this realm.</p></main>

<?php } elseif (!$allowed) { ?>
<main class="cl-main cl-narrow">
    <form class="cl-key" method="post" action="luaerrors.php" autocomplete="off">
        <label for="key">View key</label>
        <input id="key" name="key" type="password" required autofocus>
        <button type="submit">Open</button>
        <?php if ($keyError) { ?><p class="cl-error">That isn't the key.</p><?php } ?>
    </form>
</main>

<?php } elseif ($asked && $group === null) { ?>
<main class="pn-empty"><p>No such error. It may have passed the realm's keep time.</p></main>

<?php } elseif ($group !== null) { ?>
<main class="cl-main">
    <section class="cl-block">
        <p class="cl-kicker"><?php echo $group['kind'] === 'blocked' ? 'Blocked action' : 'Lua error'; ?><?php echo $group['addon'] !== '' ? ' &middot; ' . $h($group['addon']) : ''; ?></p>
        <pre class="le-text le-message"><?php echo $h($group['message']); ?></pre>
        <p class="cl-meta"><?php echo number_format($group['count']); ?> times &middot; <?php echo $who($group['accounts']); ?>
            &middot; <?php echo $h($when($group['first'])); ?> to <?php echo $h($when($group['last'])); ?> UTC
            <?php if ($group['versions']) { ?>&middot; addon version <?php echo $h(implode(', ', $group['versions'])); ?><?php } ?></p>
    </section>

    <?php foreach ($group['sightings'] as $sighting) {
        $session = $sighting['session']; ?>
    <section class="cl-block">
        <h3><?php echo $h($sighting['account']); ?>
            <small><?php echo $h(trim(($session['character'] ?? '') . ' ' . ($session['realm'] ?? ''))); ?>
                &middot; <?php echo number_format($sighting['count']); ?> times, last <?php echo $h($when($sighting['last'])); ?> UTC
                &middot; <?php echo $h(trim(($session['build'] ?? '') . ' ' . ($session['locale'] ?? ''))); ?>
                &middot; <?php echo $h($sighting['client']); ?></small></h3>
        <?php if ($sighting['stack'] !== '') { ?>
        <h4>Stack</h4>
        <pre class="le-text"><?php echo $h($sighting['stack']); ?></pre>
        <?php } ?>
        <?php if ($sighting['locals'] !== '') { ?>
        <h4>Locals</h4>
        <pre class="le-text"><?php echo $h($sighting['locals']); ?></pre>
        <?php } ?>
        <?php if (!empty($session['addons'])) { ?>
        <details>
            <summary><?php echo count($session['addons']); ?> addons loaded</summary>
            <p class="cl-meta"><?php echo $h(implode(', ', $session['addons'])); ?></p>
        </details>
        <?php } ?>
    </section>
    <?php } ?>
</main>

<?php } elseif (!$groups) { ?>
<main class="pn-empty"><p>No Lua errors yet. They come in when a player closes the game.</p></main>

<?php } else { ?>
<main class="cl-main">
    <table class="cl-table">
        <thead><tr><th>Last seen (UTC)</th><th>Addon</th><th>Error</th><th class="num">Times</th><th>Who</th></tr></thead>
        <tbody>
        <?php foreach ($groups as $row) { ?>
            <tr>
                <td class="cl-time"><?php echo $h($when($row['last'])); ?></td>
                <td><?php echo $h($row['addon']); ?></td>
                <td class="le-cell"><a href="luaerrors.php?error=<?php echo $h($row['fingerprint']); ?>"><?php echo $h($firstLine($row['message'])); ?></a></td>
                <td class="num"><?php echo number_format($row['count']); ?></td>
                <td><?php echo $who($row['accounts']); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
</main>
<?php } ?>
</body>
</html>
