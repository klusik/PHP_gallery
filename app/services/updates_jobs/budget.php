<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/updates_jobs/budget.php
 * Module Type: Service
 *
 * Purpose:
 *   Resolves the per-request time budget and runtime limits.
 *
 * Responsibilities:
 *   - Derive a bounded slice budget from the configured request time
 *   - Report whether the remaining budget allows another unit of work
 *   - Expose the job root and the effective PHP runtime limits
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
 *   - Loaded by app/services/updates_jobs.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/updates_jobs.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;
use function Gallery\Core\run_migrations_bounded;

/**
 * Return the update workspace used for jobs, archives, extracts, and rollback data.
 *
 * @return string Absolute filesystem path.
 */
function application_update_jobs_root(): string
{
    $root = application_update_project_root() . '/cache/updates';
    application_update_ensure_dir($root);
    application_update_ensure_dir($root . '/jobs');
    return $root;
}

/**
 * Return a conservative wall-clock budget for one update worker request.
 *
 * PHP's max_execution_time is only one possible limit. Reverse proxies, FastCGI,
 * web servers, and hosting control planes can impose shorter independent limits.
 * The updater therefore deliberately uses a much smaller slice and never calls
 * set_time_limit() as a correctness mechanism.
 *
 * @param float $requestedSeconds Preferred upper wall-clock slice.
 * @return array{started_at: float, deadline: float, seconds: float, php_max_execution_time: int, memory_limit: string}
 */
function application_update_time_budget(float $requestedSeconds = 8.0): array
{
    $startedAt = microtime(true);
    $requestedSeconds = max(1.0, min(12.0, $requestedSeconds));
    $phpMax = (int) ini_get('max_execution_time');

    // Leave a five-second guard when PHP itself has a finite request limit.
    if ($phpMax > 0) {
        $requestedSeconds = min($requestedSeconds, max(1.0, (float) $phpMax - 5.0));
    }

    return [
        'started_at' => $startedAt,
        'deadline' => $startedAt + $requestedSeconds,
        'seconds' => $requestedSeconds,
        'php_max_execution_time' => $phpMax,
        'memory_limit' => (string) ini_get('memory_limit'),
    ];
}

/**
 * Return true when enough time remains to begin another normal checkpoint unit.
 *
 * @param array $budget Budget returned by application_update_time_budget().
 * @param float $reserveSeconds Minimum remaining wall-clock reserve.
 * @return bool True when another bounded unit may start.
 */
function application_update_budget_allows(array $budget, float $reserveSeconds = 0.75): bool
{
    return microtime(true) + max(0.05, $reserveSeconds) < (float) ($budget['deadline'] ?? 0.0);
}

/**
 * Return runtime timeout diagnostics without attempting to extend them.
 *
 * @return array<string,mixed> Safe runtime-limit diagnostics.
 */
function application_update_runtime_limits(): array
{
    return [
        'php_max_execution_time' => (int) ini_get('max_execution_time'),
        'php_memory_limit' => (string) ini_get('memory_limit'),
        'set_time_limit_available' => function_exists('set_time_limit'),
        'ignore_user_abort_available' => function_exists('ignore_user_abort'),
        'design_depends_on_timeout_extension' => false,
        'proxy_timeout_detectable' => false,
    ];
}
