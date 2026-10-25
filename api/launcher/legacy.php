<?php
/**
 * Legacy: the points an account earns with challenges and each character's perks bought with
 * them (mod-legacy), for Portalkeeper's Legacy page. Every call needs "Authorization: Bearer
 * <token>". Off (404) until LEGACY_ENABLED=true.
 *
 *   GET  legacy.php  {"cap", "earned": {"total", "hardcore"}, "characters": [{"guid", "name", ...,
 *                    "hardcore", "points", "spent", "ranks": {"<perk id>": rank}}], "trees": [...],
 *                    "challenges": [...], "cachedAt"}
 *                    503 when the game server can't be reached or Legacy is closed
 *   POST legacy.php  {"character", "ranks": {"<perk id>": rank}} -> 200 {"character", "message"};
 *                    400 when the build is malformed; 409 {"error", "message"} when refused; 429
 *                    while the account's last save is still going through; 502 when the game
 *                    server didn't answer
 *
 * See application/launcher/legacy.php.
 **/

declare(strict_types=1);

require __DIR__ . '/../../application/launcher/legacy.php';

launcher_require_enabled();
if (!legacy_enabled()) {
    launcher_json(404, ['error' => 'This realm has no Legacy.']);
}

legacy_handle_request(launcher_require_session(launcher_db()));
