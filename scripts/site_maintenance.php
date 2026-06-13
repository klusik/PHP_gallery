<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/site_maintenance.php
 * Module Type: CLI Utility
 *
 * Purpose:
 *   Provides a command-line runner for scheduled site maintenance.
 *
 * Responsibilities:
 *   - Run from hosting cron when PHP CLI is available
 *   - Reuse the same resumable maintenance service as the web cron endpoint
 *   - Report JSON results for logs and operator diagnostics
 *   - Keep each invocation bounded so large galleries continue across cron calls
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
 *   2026-06-08
 */

declare(strict_types=1);

use function Gallery\Services\site_maintenance_run;
use function Gallery\Services\site_maintenance_time_budget_seconds;

require __DIR__ . '/../app/bootstrap.php';

/**
 * Return a CLI option value from --name=value arguments.
 *
 * @param array $arguments Arguments value.
 * @param string $name Name value.
 * @param ?string $default Default value when no explicit value is available.
 * @return ?string Text result for the caller.
 */
function site_maintenance_cli_option(array $arguments, string $name, ?string $default = null): ?string
{
    $prefix = '--' . $name . '=';
    foreach ($arguments as $argument) {
        if (str_starts_with((string) $argument, $prefix)) {
            return substr((string) $argument, strlen($prefix));
        }
    }
    return $default;
}

$arguments = $argv ?? [];
$force = in_array('--force', $arguments, true);
$quiet = in_array('--quiet', $arguments, true);
$timeBudget = (int) (site_maintenance_cli_option($arguments, 'time-budget', (string) site_maintenance_time_budget_seconds()) ?? site_maintenance_time_budget_seconds());

try {
    $result = site_maintenance_run([
        'source' => 'cli_cron',
        'force' => $force,
        'time_budget_seconds' => $timeBudget,
    ]);

    if (!$quiet || empty($result['skipped'])) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    exit(!empty($result['ok']) ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
        'exception_class' => get_class($exception),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
