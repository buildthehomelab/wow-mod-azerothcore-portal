<?php
/**
 * Combat logs from players who turned on "Share combat logs" in Portalkeeper. Every call needs
 * "Authorization: Bearer <token>". Off (404) until COMBATLOG_ENABLED=true.
 *
 *   GET  combatlog.php                               {"enabled", "part_size", "max_size"}
 *   POST combatlog.php?action=begin                  {"size", "sha256", "raw_size", "logged_until",
 *                                                     "utc_offset_minutes", "client"} for the gzip file
 *                                                    -> {"upload", "received", "part_size"}, or
 *                                                       {"upload", "complete": true} when it's here already
 *   POST combatlog.php?action=part&upload=&offset=   the next bytes (at most part_size) -> {"received"};
 *                                                    409 + {"received"} when the offset doesn't follow
 *   POST combatlog.php?action=finish&upload=         -> {"complete": true}; 422 when the size or
 *                                                    checksum is wrong (the upload is dropped)
 *
 * The same file sent again by the same account resumes or answers complete, so the launcher can
 * retry anything. The digest is made in the background once an upload is finished.
 **/

declare(strict_types=1);

require __DIR__ . '/../../application/launcher/combatlog.php';

launcher_require_enabled();
if (!clog_enabled()) {
    launcher_json(404, ['error' => 'This realm doesn\'t collect combat logs.']);
}

clog_handle_request(launcher_require_session(launcher_db()));
