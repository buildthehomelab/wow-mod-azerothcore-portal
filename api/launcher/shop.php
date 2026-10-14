<?php
/**
 * The coin shop: things a logged-in account can buy with the coins in its wallet (mod-boss-coins),
 * for Portalkeeper's Shop window. Every call needs "Authorization: Bearer <token>". Off (404)
 * until SHOP_ENABLED=true.
 *
 *   GET  shop.php    {"balance", "characters": [{"guid", "name", "race", "class", "gender", "level",
 *                    "online"}], "offers": [{"id", "category", "name", "description", "icon",
 *                    "price", "owned": [guids], "cannot": [guids]}], "cached_at"}
 *                    503 when the game server can't be reached or the shop is closed
 *   POST shop.php    {"offer", "character"} -> 200 {"ok": true, "balance", "message"};
 *                    409 {"ok": false, "error", "message"} when refused; 429 while the account's
 *                    last purchase is still going through; 502 when the game server didn't answer
 *
 * See application/launcher/shop.php.
 **/

declare(strict_types=1);

require __DIR__ . '/../../application/launcher/shop.php';

launcher_require_enabled();
if (!shop_enabled()) {
    launcher_json(404, ['error' => 'This realm has no shop.']);
}

shop_handle_request(launcher_require_session(launcher_db()));
