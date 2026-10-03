<?php
/**
 * Patch notes: what changed on the realm, written the way Blizzard writes them.
 *
 * Built from merged pull requests on GitHub (see application/include/changelog.php).
 * Turned on by CHANGELOG_ORG; the menu link only shows when it's set.
 **/

require_once __DIR__ . '/application/include/changelog.php';

$h = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$siteTitle = getenv('SITE_TITLE') ?: 'Simple Register';
$assets = 'template/advance/assets';
$icon = static fn(string $name): string => $assets . '/img/icons/' . $name . '.jpg';
$perPage = 10;

$enabled = cl_enabled();
$days = [];
$data = null;
if ($enabled) {
    try {
        $tz = new DateTimeZone(cl_env('CHANGELOG_TIMEZONE', date_default_timezone_get()));
    } catch (Exception $e) {
        $tz = new DateTimeZone('UTC');
    }
    $data = cl_data();
    $days = $data ? cl_patch_notes($data, $tz) : [];
} else {
    http_response_code(404);
}

$pages = max(1, (int)ceil(count($days) / $perPage));
$page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$shown = array_slice($days, ($page - 1) * $perPage, $perPage, true);

$latest = array_key_first($days);
$longDate = static fn(string $day): string => (new DateTimeImmutable($day))->format('F j, Y');

$counts = array_fill_keys(array_keys(CL_SECTIONS), 0);
foreach ($days as $day) {
    foreach ($day['features'] as $note) {
        $counts[$note['section']]++;
    }
    foreach (['changes', 'fixes'] as $kind) {
        foreach ($day[$kind] as $section => $subs) {
            $counts[$section] += array_sum(array_map('count', $subs));
        }
    }
}

/** One note's bullets (+ developers' note). */
$printNote = static function (array $note) use ($h): void {
    foreach ($note['notes'] as $i => $line) { ?>
        <li><?php echo cl_inline($line); ?><?php if ($i === count($note['notes']) - 1) { ?> <a class="pn-src" href="<?php echo $h($note['url']); ?>" title="Source" rel="noopener" aria-label="Source on GitHub">&#x2197;</a><?php } ?></li>
    <?php }
    if ($note['devnote'] !== '') { ?>
        <li class="pn-dev"><span>Developers&rsquo; notes:</span> <?php echo cl_inline($note['devnote']); ?></li>
    <?php }
};

/** A section block (Classes, Professions, ...) with its sub-headings. */
$printSection = static function (string $section, array $subs) use ($h, $icon, $printNote): void { ?>
    <section class="pn-section" data-section="<?php echo $h($section); ?>">
        <h3><img src="<?php echo $icon(CL_SECTIONS[$section]); ?>" alt="" width="28" height="28"><?php echo $h($section); ?></h3>
        <?php foreach ($subs as $sub => $notes) {
            if ($sub !== '') {
                $classIcon = $section === 'Classes' ? (CL_CLASSES[$sub] ?? null) : null; ?>
                <h4 class="pn-sub"<?php if ($classIcon) { ?> data-class="<?php echo $h(strtolower(str_replace(' ', '-', $sub))); ?>"<?php } ?>>
                    <?php if ($classIcon) { ?><img src="<?php echo $icon($classIcon); ?>" alt="" width="22" height="22"><?php } ?><?php echo $h($sub); ?>
                </h4>
            <?php } ?>
            <ul class="pn-list">
                <?php foreach ($notes as $note) { $printNote($note); } ?>
            </ul>
        <?php } ?>
    </section>
<?php };
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Patch Notes · <?php echo $h($siteTitle); ?></title>
    <meta name="description" content="Everything that changed on <?php echo $h($siteTitle); ?>, newest first.">
    <link href="favicon.ico" rel="icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Marcellus&family=Open+Sans:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
    <link href="<?php echo $assets; ?>/css/changelog.css" rel="stylesheet">
</head>
<body>
<header class="pn-hero" style="background-image: url('<?php echo $assets; ?>/img/wow-bg/2-<?php echo random_int(1, 6); ?>.jpg')">
    <div class="pn-hero-inner">
        <a class="pn-home" href="./">&larr; <?php echo $h($siteTitle); ?></a>
        <p class="pn-eyebrow"><?php echo $h($siteTitle); ?></p>
        <h1>Patch Notes</h1>
        <?php if ($latest) { ?>
            <p class="pn-lede">Latest update <a href="#<?php echo $h($latest); ?>"><?php echo $h($longDate($latest)); ?></a></p>
        <?php } ?>
    </div>
</header>

<?php if (!$enabled) { ?>
<main class="pn-empty"><p>Patch notes aren't turned on for this realm.</p></main>
<?php } elseif (!$days) { ?>
<main class="pn-empty"><p><?php echo $data ? 'No patch notes yet.' : 'The patch notes couldn&rsquo;t be loaded right now. Try again in a few minutes.'; ?></p></main>
<?php } else { ?>
<div class="pn-layout">
    <aside class="pn-side">
        <nav class="pn-filter" aria-label="Filter by category">
            <p class="pn-side-title">Categories</p>
            <button type="button" class="pn-chip is-on" data-filter="">All <span><?php echo array_sum($counts); ?></span></button>
            <?php foreach ($counts as $section => $count) { if (!$count) { continue; } ?>
                <button type="button" class="pn-chip" data-filter="<?php echo $h($section); ?>">
                    <img src="<?php echo $icon(CL_SECTIONS[$section]); ?>" alt="" width="18" height="18"><?php echo $h($section); ?> <span><?php echo $count; ?></span>
                </button>
            <?php } ?>
        </nav>
        <nav class="pn-toc" aria-label="Updates">
            <p class="pn-side-title">Updates</p>
            <?php foreach ($shown as $day => $notes) { ?>
                <a href="#<?php echo $h($day); ?>"><?php echo $h($longDate($day)); ?></a>
            <?php } ?>
        </nav>
    </aside>

    <main class="pn-feed">
        <?php foreach ($shown as $day => $notes) { ?>
        <article class="pn-post" id="<?php echo $h($day); ?>">
            <header class="pn-post-head">
                <p class="pn-kicker"><?php echo $notes['features'] ? 'Realm Update' : 'Hotfixes'; ?></p>
                <h2><?php echo $h($longDate($day)); ?></h2>
            </header>

            <?php if ($notes['features']) { ?>
            <section class="pn-new">
                <h3><img src="<?php echo $icon('achievement_general'); ?>" alt="" width="28" height="28">New Features</h3>
                <?php foreach ($notes['features'] as $feature) { ?>
                <div class="pn-feature" data-section="<?php echo $h($feature['section']); ?>">
                    <h4><img src="<?php echo $icon(($feature['section'] === 'Classes' ? (CL_CLASSES[$feature['sub']] ?? null) : null) ?? CL_SECTIONS[$feature['section']]); ?>" alt="" width="36" height="36"><?php echo $h($feature['title']); ?></h4>
                    <ul class="pn-list"><?php $printNote($feature); ?></ul>
                </div>
                <?php } ?>
            </section>
            <?php } ?>

            <?php foreach ($notes['changes'] as $section => $subs) { $printSection($section, $subs); } ?>

            <?php if ($notes['fixes']) { ?>
            <div class="pn-fixes">
                <h3 class="pn-fixes-title"><img src="<?php echo $icon('inv_misc_wrench_01'); ?>" alt="" width="28" height="28">Bug Fixes</h3>
                <?php foreach ($notes['fixes'] as $section => $subs) { $printSection($section, $subs); } ?>
            </div>
            <?php } ?>
        </article>
        <?php } ?>

        <?php if ($pages > 1) { ?>
        <nav class="pn-pages" aria-label="Older updates">
            <?php if ($page > 1) { ?><a href="?page=<?php echo $page - 1; ?>">&larr; Newer</a><?php } ?>
            <span>Page <?php echo $page; ?> of <?php echo $pages; ?></span>
            <?php if ($page < $pages) { ?><a href="?page=<?php echo $page + 1; ?>">Older &rarr;</a><?php } ?>
        </nav>
        <?php } ?>
    </main>
</div>
<script src="<?php echo $assets; ?>/js/changelog.js"></script>
<?php } ?>
</body>
</html>
