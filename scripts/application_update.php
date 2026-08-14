<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/application_update.php
 * Module Type: CLI Utility
 *
 * Purpose:
 *   Advances automatic application updates from hosting cron without requiring one long-running PHP process.
 *
 * Responsibilities:
 *   - Continue an existing background update for one bounded worker slice
 *   - Start a due stable automatic update when the site is otherwise idle
 *   - Never advance Admin/manual beta jobs from unattended cron
 *   - Emit only redacted updater state suitable for hosting cron logs
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
 *   - Schedule repeated invocations, for example every five minutes. Each invocation is bounded and resumable.
 */

declare(strict_types=1);

// Prevent bootstrap's browser-request auto-update hook from treating this CLI process as a GET request.
$_SERVER['REQUEST_METHOD'] = 'CLI';

require __DIR__ . '/../app/bootstrap.php';

use function Gallery\Services\application_autoupdate_enabled;
use function Gallery\Services\application_autoupdate_run_installing_check;
use function Gallery\Services\application_autoupdate_status;
use function Gallery\Services\application_update_active_job;
use function Gallery\Services\application_update_beta_active;
use function Gallery\Services\application_update_job_public_state;
use function Gallery\Services\application_update_continue_background_job;
use function Gallery\Services\application_update_safe_error;

/**
 * Read a numeric --name=value CLI option.
 *
 * @param array<int,string> $arguments CLI arguments.
 * @param string $name Option name without leading dashes.
 * @param float $default Default value.
 * @return float Parsed value.
 */
function application_update_cli_number_option(array $arguments, string $name, float $default): float
{
    $prefix = '--' . $name . '=';
    foreach ($arguments as $argument) {
        if (str_starts_with((string) $argument, $prefix)) {
            return (float) substr((string) $argument, strlen($prefix));
        }
    }
    return $default;
}

$arguments = array_values(array_map('strval', $argv ?? []));
$budgetSeconds = max(1.0, min(12.0, application_update_cli_number_option($arguments, 'time-budget', 8.0)));
$forceCheck = in_array('--force-check', $arguments, true);

try {
    if (!application_autoupdate_enabled()) {
        echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'automatic_updates_disabled'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }
    if (application_update_beta_active()) {
        echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'beta_channel_active'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    $active = application_update_active_job();
    if ($active !== null) {
        if ((string) ($active['trigger'] ?? '') !== 'background') {
            echo json_encode([
                'ok' => true,
                'skipped' => true,
                'reason' => 'manual_update_job_active',
                'job' => application_update_job_public_state($active),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            exit(0);
        }
        $job = application_update_continue_background_job($budgetSeconds) ?? application_update_job_public_state($active);
        echo json_encode(['ok' => true, 'job' => $job], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit((string) ($job['status'] ?? '') === 'failed' ? 1 : 0);
    }

    $status = application_autoupdate_status();
    $lastCheckedAt = (int) ($status['last_checked_at'] ?? 0);
    if (!$forceCheck && $lastCheckedAt > 0 && time() - $lastCheckedAt < 18000) {
        echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'check_not_due', 'status' => $status], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    application_autoupdate_run_installing_check($forceCheck, time());
    $active = application_update_active_job();
    if ($active !== null && (string) ($active['trigger'] ?? '') === 'background') {
        // Discovery already consumed this invocation's network budget. Report the
        // new durable job and let the next cron invocation perform package work.
        echo json_encode(['ok' => true, 'job' => application_update_job_public_state($active)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    echo json_encode(['ok' => true, 'status' => application_autoupdate_status()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    $safe = application_update_safe_error($exception);
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $safe], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
