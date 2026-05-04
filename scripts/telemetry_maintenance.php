<?php
/**
 * Command-line telemetry maintenance runner.
 *
 * This script can be called from cron on hosts that support PHP CLI. It performs
 * the same rollup and retention cleanup as the Admin telemetry maintenance page.
 */

require __DIR__ . '/../app/bootstrap.php';

try {
    // $result stores the rollup and retention cleanup summary.
    $result = telemetry_run_maintenance();
    echo json_encode(['ok' => true, 'result' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
