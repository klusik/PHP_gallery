<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/telemetry_maintenance.php
 * Module Type: CLI Utility
 *
 * Purpose:
 *   Provides a command-line maintenance utility for installation, migration, deployment, or administration.
 *
 * Responsibilities:
 *   - Run from command line or deployment workflow
 *   - Reuse project bootstrap code when needed
 *   - Report failures clearly to the operator
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-04
 */

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
