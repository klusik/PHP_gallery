<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/audit_runtime_probe.php
 * Module Type: Audit Measurement Child
 * Purpose: Measure real include-phase costs in a fresh PHP process without invoking installation workflows.
 * Responsibilities: Capture include count, elapsed time and peak memory as a machine-readable child result.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

require_once __DIR__ . '/cli_guard.php';
gallery_require_cli_sapi();

$probes = require __DIR__ . '/audit_performance_registry.php';
$probeId = (string) ($argv[1] ?? '');
if (!isset($probes[$probeId]) || count($argv) !== 2) {
    fwrite(STDERR, "Unknown bootstrap probe.\n");
    exit(2);
}
$root = dirname(__DIR__);
$before = get_included_files();
ob_start();
$started = hrtime(true);
try {
    foreach ($probes[$probeId]['entry_files'] as $entry) {
        require_once $root . '/' . $entry;
    }
    $wallMs = (hrtime(true) - $started) / 1e6;
    $peakMemory = memory_get_peak_usage(true);
    $output = ob_get_clean();
    if ($output !== '') {
        throw new RuntimeException('Bootstrap include unexpectedly emitted output.');
    }
    $included = array_values(array_diff(get_included_files(), $before));
    $included = array_map(static fn(string $path): string => str_replace('\\', '/', substr($path, strlen($root) + 1)), $included);
    sort($included, SORT_STRING);
    echo json_encode(['schema_version' => 1, 'probe' => $probeId, 'scope' => 'include-only-before-cms_run',
        'php_version' => PHP_VERSION, 'php_int_size' => PHP_INT_SIZE,
        'included_php_files' => count($included), 'included_paths' => $included,
        'bootstrap_wall_ms' => round($wallMs, 4), 'peak_memory_bytes' => $peakMemory], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $exception) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    fwrite(STDERR, 'Bootstrap probe failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
