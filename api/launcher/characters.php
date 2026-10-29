<?php
/**
 * Character order: the account's characters in the order the character select screen shows them,
 * for Portalkeeper's Characters page, and saving a new order. Every call needs "Authorization:
 * Bearer <token>". Off (404) until CHARACTER_ORDER_ENABLED=true.
 *
 *   GET  characters.php  {"characters": [{"guid", "name", "race", "class", "gender", "level",
 *                        "lastPlayed", "online", "order", "favourite"}]} in the current order;
 *                        503 when the characters database can't be read
 *   POST characters.php  {"order": [guid, ...], "favourites": [guid, ...]} ("favourites" optional)
 *                        -> 200 the new order, like GET; 400 when malformed;
 *                        409 {"error": "duplicate"|"not_yours", "message"}; 429 while the account's
 *                        last save is still going through or after too many in a minute; 503 when
 *                        the order can't be written
 *
 * See application/launcher/characters.php.
 **/

declare(strict_types=1);

require __DIR__ . '/../../application/launcher/characters.php';

launcher_require_enabled();
if (!character_order_enabled()) {
    launcher_json(404, ['error' => 'This realm has no character ordering.']);
}

character_order_handle_request(launcher_require_session(launcher_db()));
