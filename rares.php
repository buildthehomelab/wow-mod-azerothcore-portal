<?php
/**
 * Live rare map: every open-world rare that's up right now, from mod-rare-tracker.
 *
 * The list comes from api/rares.php (which asks the worldserver) and the map images from
 * WORLDMAP_URL (default realm/worldmap/, built with mod-rare-tracker's tools/build_worldmaps.py).
 * Without the images the page still lists every rare and draws zone maps as plain grids.
 **/

$h = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$siteTitle = getenv('SITE_TITLE') ?: 'Simple Register';
$enabled = (getenv('RARE_TRACKER_URL') ?: '') !== '';
$mapsUrl = rtrim(getenv('WORLDMAP_URL') ?: 'realm/worldmap', '/') . '/';
$assets = 'template/advance/assets';

if (!$enabled) {
    http_response_code(404);
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rare Map · <?php echo $h($siteTitle); ?></title>
    <meta name="description" content="Live map of the rare creatures that are up right now.">
    <link href="favicon.ico" rel="icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Marcellus&family=Open+Sans:wght@400;600&display=swap" rel="stylesheet">
    <link href="<?php echo $assets; ?>/css/rares.css" rel="stylesheet">
</head>
<body>
<header class="rm-top">
    <a class="rm-home" href="./">&larr; <?php echo $h($siteTitle); ?></a>
    <h1>Rare Map</h1>
    <p class="rm-status" id="rm-status" role="status" aria-live="polite">Loading&hellip;</p>
</header>

<?php if (!$enabled) { ?>
<main class="rm-off">
    <p>The rare map isn't turned on for this realm.</p>
</main>
<?php } else { ?>
<main id="rare-map" class="rm-app" data-api="api/rares.php" data-maps="<?php echo $h($mapsUrl); ?>">
    <nav class="rm-tabs" id="rm-tabs" aria-label="Continents"></nav>

    <div class="rm-layout">
        <section class="rm-mapcol" aria-label="Map">
            <div class="rm-crumb">
                <button type="button" class="rm-back" id="rm-back" hidden>&larr; <span></span></button>
                <h2 id="rm-title"></h2>
            </div>
            <div class="rm-map" id="rm-map">
                <img id="rm-art" alt="" hidden>
                <div class="rm-noart" id="rm-noart" hidden></div>
                <div class="rm-pins" id="rm-pins"></div>
                <div class="rm-tip" id="rm-tip" role="tooltip" hidden></div>
            </div>
            <ul class="rm-legend">
                <li><i class="rm-key up"></i>Up</li>
                <li><i class="rm-key up elite"></i>Rare elite</li>
                <li><i class="rm-key up live"></i>Someone is nearby (live position)</li>
                <li><i class="rm-key dead"></i>Dead, respawning</li>
            </ul>
        </section>

        <aside class="rm-side" aria-label="Rares">
            <div class="rm-filters">
                <input type="search" id="rm-search" placeholder="Find a rare or zone" aria-label="Find a rare or zone" autocomplete="off">
                <label class="rm-check"><input type="checkbox" id="rm-dead"> Show dead rares</label>
            </div>
            <ul class="rm-zones" id="rm-zones"></ul>
        </aside>
    </div>
</main>
<script src="<?php echo $assets; ?>/js/rares.js"></script>
<?php } ?>
</body>
</html>
