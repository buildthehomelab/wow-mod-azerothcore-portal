<?php
/**
 * GET api/launcher/live.php
 *
 * What is happening on the realm now, for the launcher's home screen (no login needed: it's what the
 * homepage shows anyone). Every part is null when its source is off or didn't answer:
 *
 *   {"generated": unix time,
 *    "players": {"online", "bots"},
 *    "server": {"uptime": seconds, "restart_in": seconds or null},
 *    "events": [{"id", "name"}],
 *    "rares": {"up", "total", "world_bosses": [{"name", "zone", "up", "respawn_at": unix time or null}]}}
 *
 * The answer is up to LIVE_CACHE_SECONDS old: "restart_in" counts from "generated".
 **/

declare(strict_types=1);

require __DIR__ . '/../../application/launcher/live.php';

launcher_require_enabled();
launcher_require_method('GET');

launcher_json(200, live_info());
