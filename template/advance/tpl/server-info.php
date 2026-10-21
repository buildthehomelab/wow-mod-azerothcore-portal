<?php
/**
 * Created by Amin.MasterkinG
 * Website : MasterkinG32.CoM
 * Email : lichwow_masterking@yahoo.com
 * Date: 04/02/2020 - 6:55 PM
 */
require_once __DIR__ . '/../../../application/include/changelog.php';

// The homepage's story: the progression journey, the realm at a glance, the feature book and the latest patch notes.
$journey = require __DIR__ . '/../../../application/journey.php';
$features = require __DIR__ . '/../../../application/features.php';
$tplUrl = $antiXss->xss_clean(get_config("baseurl")) . '/template/' . $antiXss->xss_clean(get_config("template"));
$e = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$featureCount = array_sum(array_map(fn($chapter) => count($chapter['entries']), $features));
$phaseCount = array_sum(array_map(fn($chapter) => count($chapter['phases']), $journey));

// Latest patch notes, from the changelog's cache only: the homepage never waits on GitHub.
$latest = null;
if (cl_enabled() && ($clData = cl_cached())) {
    try {
        $clTz = new DateTimeZone(cl_env('CHANGELOG_TIMEZONE', date_default_timezone_get()));
    } catch (Exception $ex) {
        $clTz = new DateTimeZone('UTC');
    }
    $clDays = cl_patch_notes($clData, $clTz);
    if ($clDays) {
        $weekAgo = (new DateTimeImmutable('-7 days', $clTz))->format('Y-m-d');
        $launches = [];
        $weekCount = 0;
        foreach ($clDays as $day => $notes) {
            foreach ($notes['features'] as $note) {
                $launches[] = $note + ['day' => $day];
            }
            if ($day >= $weekAgo) {
                $weekCount += count($notes['features']);
                foreach (['changes', 'fixes'] as $kind) {
                    foreach ($notes[$kind] as $subs) {
                        $weekCount += array_sum(array_map('count', $subs));
                    }
                }
            }
        }
        $latest = [
            'day' => array_key_first($clDays),
            'week' => $weekCount,
            'launches' => array_slice($launches, 0, 4), // newest day first
        ];
    }
}
$longDate = static fn(string $day): string => (new DateTimeImmutable($day))->format('F j, Y');
?>
    <section id="journey" class="journey">
        <div class="container">
            <div class="section-title">
                <h2>From Molten Core to the Frozen Throne</h2>
                <p><?php echo $phaseCount; ?> phases, one character at a time</p>
            </div>
            <p class="journey-lede">Every character starts on launch day of Classic and lives through World of Warcraft in the
                order it happened. Your world only moves on when you do: conquer a phase's raids and the next one opens,
                for you and nobody else. Short on raiders? Bots fill the gaps.</p>
            <?php $phase = 0; foreach ($journey as $chapter) { ?>
            <article class="chapter">
                <header class="chapter-banner" style="background-image: url('<?php echo $tplUrl; ?>/assets/img/wow-bg/<?php echo $e($chapter['art']); ?>.jpg');">
                    <div class="chapter-head">
                        <span class="chapter-levels"><?php echo $e($chapter['levels']); ?></span>
                        <h3><?php echo $e($chapter['name']); ?></h3>
                        <p><?php echo $e($chapter['text']); ?></p>
                    </div>
                </header>
                <ol class="phases" style="--phases: <?php echo count($chapter['phases']); ?>;">
                    <?php foreach ($chapter['phases'] as $step) { $phase++; ?>
                    <li class="phase">
                        <img class="phase-icon" src="<?php echo $tplUrl; ?>/assets/img/icons/<?php echo $e($step['icon']); ?>.jpg" alt="" width="56" height="56" loading="lazy">
                        <div>
                            <span class="phase-num">Phase <?php echo $phase; ?></span>
                            <h4><?php echo $e($step['title']); ?></h4>
                            <p><?php echo $e($step['text']); ?></p>
                            <?php if (!empty($step['event'])) { ?><p class="phase-event">Live event: <?php echo $e($step['event']); ?></p><?php } ?>
                        </div>
                    </li>
                    <?php } ?>
                </ol>
                <?php if (!empty($chapter['gate'])) { $gate = $chapter['gate']; ?>
                <div class="gate">
                    <img class="gate-icon" src="<?php echo $tplUrl; ?>/assets/img/icons/<?php echo $e($gate['icon']); ?>.jpg" alt="" width="64" height="64" loading="lazy">
                    <h4><?php echo $e($gate['title']); ?></h4>
                    <p><?php echo $e($gate['text']); ?></p>
                    <?php if (!empty($gate['event'])) { ?><p class="phase-event">Live event: <?php echo $e($gate['event']); ?></p><?php } ?>
                </div>
                <?php } ?>
            </article>
            <?php } ?>
            <div class="journey-end">
                <h3>The Lich King falls. Now do it again.</h3>
                <p>Every character makes the journey on its own. Your mounts, companions, appearances and Reagent Bank come
                    along to the next one; the world starts over in 2004.</p>
            </div>
            <dl class="glance">
                <div><dt>Experience</dt><dd>Normal rate</dd></div>
                <div><dt>Talents</dt><dd>One every level</dd></div>
                <div><dt>Raids</dt><dd>Reset daily</dd></div>
                <div><dt>Factions</dt><dd>Play together</dd></div>
                <div><dt>Groups</dt><dd>Bots fill the gaps</dd></div>
                <div><dt>Client</dt><dd>Installed by the launcher</dd></div>
            </dl>
        </div>
    </section>

    <section id="features" class="codex section-bg">
        <div class="container">
            <div class="section-title">
                <h2>What's new in Azeroth</h2>
                <p><?php echo $featureCount; ?> changes to the game you know</p>
            </div>
            <div class="codex-book">
                <div class="codex-tabs" role="tablist" aria-label="Feature chapters">
                    <?php $i = 0; foreach ($features as $chapter => $content) { $id = 'codex-' . strtolower($chapter); ?>
                    <button type="button" role="tab" id="<?php echo $id; ?>-tab" aria-controls="<?php echo $id; ?>"
                            aria-selected="<?php echo $i === 0 ? 'true' : 'false'; ?>"><?php echo $e($chapter); ?></button>
                    <?php $i++; } ?>
                </div>
                <?php foreach ($features as $chapter => $content) { $id = 'codex-' . strtolower($chapter); ?>
                <div class="codex-page" role="tabpanel" id="<?php echo $id; ?>" aria-labelledby="<?php echo $id; ?>-tab">
                    <h3 class="codex-chapter"><?php echo $e($chapter); ?></h3>
                    <p class="codex-intro"><?php echo $e($content['intro']); ?></p>
                    <ul class="codex-list">
                        <?php foreach ($content['entries'] as $entry) { ?>
                        <li<?php echo isset($entry['class']) ? ' data-class="' . $e($entry['class']) . '"' : ''; ?>>
                            <img class="codex-icon" src="<?php echo $tplUrl; ?>/assets/img/icons/<?php echo $e($entry['icon']); ?>.jpg" alt="" width="44" height="44" loading="lazy">
                            <div>
                                <h4><?php echo $e($entry['title']); ?></h4>
                                <p><?php echo cl_inline($entry['text']); ?></p>
                            </div>
                        </li>
                        <?php } ?>
                    </ul>
                </div>
                <?php } ?>
            </div>
        </div>
    </section>

    <?php if ($latest || !empty(get_config('rare_tracker_url'))) { ?>
    <section id="latest" class="latest">
        <div class="container">
            <div class="latest-grid">
                <?php if ($latest) { ?>
                <div class="latest-summary">
                    <h2>Updated <?php echo $e($longDate($latest['day'])); ?></h2>
                    <p>The realm changes almost every day.
                        <?php if ($latest['week'] > 0) { ?><b><?php echo $latest['week']; ?> changes</b> landed in the last week.<?php } ?></p>
                    <a class="btn btn-success" href="changelog.php"><?php elang('patch_notes'); ?></a>
                </div>
                <?php if ($latest['launches']) { ?>
                <ul class="latest-launches" aria-label="Recently added">
                    <?php foreach ($latest['launches'] as $note) { ?>
                    <li>
                        <time datetime="<?php echo $e($note['day']); ?>"><?php echo $e((new DateTimeImmutable($note['day']))->format('M j')); ?></time>
                        <h3><?php echo $e($note['title'] ?? ''); ?></h3>
                        <p><?php echo cl_inline($note['notes'][0]); ?></p>
                    </li>
                    <?php } ?>
                </ul>
                <?php } ?>
                <?php } ?>
                <?php if (!empty(get_config('rare_tracker_url'))) { ?>
                <a class="latest-rares" href="rares.php">
                    <img src="<?php echo $tplUrl; ?>/assets/img/icons/inv_misc_map_01.jpg" alt="" width="44" height="44">
                    <span><b><?php elang('rare_map'); ?></b> Which rares are up right now, live from the server.</span>
                </a>
                <?php } ?>
            </div>
        </div>
    </section>
    <?php } ?>
