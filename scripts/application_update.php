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
 *   - Preserve running manual ownership while repairing failed jobs of every origin
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

require_once __DIR__ . '/cli_guard.php';
gallery_require_cli_sapi();

// Prevent bootstrap's browser-request auto-update hook from treating this CLI process as a GET request.
$_SERVER['REQUEST_METHOD'] = 'CLI';
/**
 * Identify this trusted updater-only CLI bootstrap to the isolation protocol.
 * Type: bool. Units: trusted-entry flag. Scope: this PHP process only.
 * Consumers: early runtime shared-lease admission.
 * Rationale: true exempts the dedicated updater from holding its own read lease;
 * ordinary CLI entry points keep their leases and cannot self-exempt over HTTP.
 */
define('GALLERY_UPDATER_WORKER', true);

require __DIR__ . '/../app/bootstrap_full.php';

use function Gallery\Services\application_autoupdate_enabled;
use function Gallery\Services\application_autoupdate_discovery_due;
use function Gallery\Services\application_update_discovery_last_attempt_at;
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
function application_update_cli_number_option(
    array $arguments,
    string $name,
    float $default,
): float {
    $prefix = '--' . $name . '=';
    foreach ($arguments as $argument) {
        if (str_starts_with((string) $argument, $prefix)) {
            return (float) substr((string) $argument, strlen($prefix));
        }
    }
    return $default;
}

$arguments = array_values(array_map('strval', $argv ?? []));
$budgetSeconds = max(1.0, min(12.0, application_update_cli_number_option(
    $arguments,
    'time-budget',
    8.0,
)));
$forceCheck = in_array('--force-check', $arguments, true);

try {
    $recoveryActive = application_update_active_job();
    if ($recoveryActive !== null && (($recoveryActive['status'] ?? '') === 'failed'
        || !empty($recoveryActive['automatic_rollback'])
        || !empty($recoveryActive['automatic_repair_continuation'])
        || (!empty($recoveryActive['slice_open'])
            && (int) ($recoveryActive['slice_recovery_at'] ?? PHP_INT_MAX) <= time()))) {
        // Repairing an already started operation is independent of permission
        // to discover/install another release or to replace a beta pin.
        $recoveryWasDue = \Gallery\Services\application_update_failed_recovery_due($recoveryActive, time());
        $recoveryJob = application_update_continue_background_job($budgetSeconds)
            ?? application_update_job_public_state($recoveryActive);
        $recoveryFailed = ($recoveryJob['status'] ?? '') === 'failed'
            && empty($recoveryJob['result']['recovery_released']);
        if ($recoveryFailed && !$recoveryWasDue
            && application_autoupdate_enabled() && !application_update_beta_active()
            && ($forceCheck || application_autoupdate_discovery_due(
                time(), application_update_discovery_last_attempt_at(),
            ))) {
            application_autoupdate_run_installing_check($forceCheck, time());
        }
        echo json_encode(['ok' => !$recoveryFailed, 'job' => $recoveryJob],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit($recoveryFailed ? 1 : 0);
    }
    if (!application_autoupdate_enabled()) {
        echo
            json_encode([
                'ok' => true,
                'skipped' => true,
                'reason' => 'automatic_updates_disabled',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }
    if (application_update_beta_active()) {
        echo
            json_encode([
                'ok' => true,
                'skipped' => true,
                'reason' => 'beta_channel_active',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    $active = application_update_active_job();
    if ($active !== null) {
        if ((string) ($active['trigger'] ?? '') !== 'background') {
            if (($active['status'] ?? '') === 'failed'
                && ($forceCheck || application_autoupdate_discovery_due(
                    time(), application_update_discovery_last_attempt_at(),
                ))) {
                application_autoupdate_run_installing_check($forceCheck, time());
            }
            echo
                json_encode([
                    'ok' => true,
                    'skipped' => true,
                    'reason' => 'manual_update_job_active',
                    'job' => application_update_job_public_state($active),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            exit(0);
        }
        if (($active['status'] ?? '') === 'failed'
            && ($forceCheck || application_autoupdate_discovery_due(
                time(), application_update_discovery_last_attempt_at(),
            ))) {
            application_autoupdate_run_installing_check($forceCheck, time());
            $job = application_update_job_public_state(
                application_update_active_job() ?? $active,
            );
        } else {
            $job = application_update_continue_background_job(
                $budgetSeconds,
            ) ?? application_update_job_public_state($active);
        }
        echo
            json_encode([
                'ok' => true,
                'job' => $job,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit((string) ($job['status'] ?? '') === 'failed' ? 1 : 0);
    }

    $status = application_autoupdate_status();
    $lastCheckedAt = application_update_discovery_last_attempt_at();
    if (
        !$forceCheck
        && !application_autoupdate_discovery_due(time(), $lastCheckedAt)
    ) {
        echo
            json_encode([
                'ok' => true,
                'skipped' => true,
                'reason' => 'check_not_due',
                'status' => $status,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    application_autoupdate_run_installing_check($forceCheck, time());
    $active = application_update_active_job();
    if (
        $active !== null
        && (string) ($active['trigger'] ?? '') === 'background'
    ) {
        // Discovery already consumed this invocation's network budget. Report the
        // new durable job and let the next cron invocation perform package work.
        echo
            json_encode([
                'ok' => true,
                'job' => application_update_job_public_state($active),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    echo
        json_encode([
            'ok' => true,
            'status' => application_autoupdate_status(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    $safe = application_update_safe_error($exception);
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => $safe,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        . PHP_EOL);
    exit(1);
}
