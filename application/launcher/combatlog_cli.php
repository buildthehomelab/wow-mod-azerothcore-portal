<?php
/**
 * Makes the digest of uploaded combat logs from the command line:
 *
 *   php application/launcher/combatlog_cli.php <upload id>    one upload (what a finished upload starts)
 *   php application/launcher/combatlog_cli.php --all          every upload without a current digest
 *
 * In Docker: docker exec -u www-data wow-register php application/launcher/combatlog_cli.php --all
 **/

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/combatlog.php';

$ids = array_slice($argv, 1);
if ($ids === ['--all']) {
    $ids = [];
    foreach (clog_metas('logs') as $meta) {
        $digest = clog_read_json(clog_path('logs', $meta['id'], '.digest.json'));
        if ((int)($digest['version'] ?? 0) !== CLOG_DIGEST_VERSION) {
            $ids[] = $meta['id'];
        }
    }
    if (!$ids) {
        echo "Every upload has a current digest.\n";
        exit(0);
    }
}
if (!$ids) {
    fwrite(STDERR, "Usage: combatlog_cli.php <upload id>... | --all\n");
    exit(2);
}

$failed = 0;
foreach ($ids as $id) {
    $digest = clog_digest((string)$id);
    if ($digest === null) {
        fwrite(STDERR, $id . ": no digest (unknown upload, unreadable log, or another process is on it)\n");
        $failed++;
        continue;
    }
    printf("%s: %d lines, %d fights, %d deaths\n", $id, $digest['lines'], count($digest['fights']), $digest['deaths']);
}
exit($failed > 0 ? 1 : 0);
