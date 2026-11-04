<?php
/**
 * Makes the digest of uploaded combat logs from the command line:
 *
 *   php application/launcher/combatlog_cli.php <upload id>    one upload (what a finished upload starts)
 *   php application/launcher/combatlog_cli.php --all          every upload without a current digest
 *
 * And for uploads whose metadata is empty or corrupt, which never show up in the list:
 *
 *   php application/launcher/combatlog_cli.php --damaged          lists them, changes nothing
 *   php application/launcher/combatlog_cli.php --repair-damaged   rebuilds the metadata and the digest
 *   php application/launcher/combatlog_cli.php --remove-damaged   deletes them
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
$failed = 0;
if (count($ids) === 1 && in_array($ids[0], ['--damaged', '--repair-damaged', '--remove-damaged'], true)) {
    $mode = $ids[0];
    $ids = [];
    if (!is_readable(clog_dir() . '/logs')) {
        fwrite(STDERR, clog_dir() . "/logs can't be read. Check COMBATLOG_DIR and the user this runs as.\n");
        exit(1);
    }
    $damaged = clog_damaged_logs();
    if (!$damaged) {
        echo "No damaged uploads.\n";
        exit(0);
    }
    foreach ($damaged as $id) {
        // Repairing a large log takes a while: another run may have got to this one since the list was made.
        if (!clog_meta_damaged($id)) {
            continue;
        }
        $log = clog_path('logs', $id, '.txt.gz');
        if ($mode === '--damaged') {
            printf("%s: %d bytes of gzip, uploaded %s UTC\n", $id, (int)filesize($log), gmdate('Y-m-d H:i', (int)filemtime($log)));
        } elseif ($mode === '--remove-damaged') {
            clog_delete_log($id);
            clearstatcache();
            if (is_file($log) || is_file(clog_path('logs', $id, '.json'))) {
                fwrite(STDERR, $id . ": could not be deleted\n");
                $failed++;
            } else {
                echo $id . ": deleted\n";
            }
        } elseif (clog_repair_meta($id)) {
            $ids[] = $id;
        } else {
            fwrite(STDERR, $id . ": metadata could not be written\n");
            $failed++;
        }
    }
    if (!$ids) {
        exit($failed > 0 ? 1 : 0);
    }
} elseif ($ids === ['--all']) {
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
    fwrite(STDERR, "Usage: combatlog_cli.php <upload id>... | --all | --damaged | --repair-damaged | --remove-damaged\n");
    exit(2);
}

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
