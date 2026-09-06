<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_test_run_analysis/maintenance_analysis.php
 * Module Type: Service
 *
 * Purpose:
 *   Assesses scheduled maintenance, locks, and update job progress.
 *
 * Responsibilities:
 *   - Detect stale locks and stuck maintenance state against explicit budgets
 *   - Determine whether scheduled site maintenance is overdue
 *   - Read update job pointers without mutating updater state
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
 *   - Path note: this file lives one directory deeper than the module entry file,
 *     so project-root paths must use dirname(__DIR__, 3), not dirname(__DIR__, 2).
 *   - Loaded by app/services/admin_test_run_analysis.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/admin_test_run_analysis.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;

/**
 * Return a stale-lock assessment from one non-blocking lock snapshot.
 *
 * @param array<string,mixed> $lock Lock snapshot.
 * @return array<string,mixed>
 */
function admin_test_run_lock_assessment(array $lock, int $staleAfterSeconds): array
{
    $mtime = max(0, (int) ($lock['mtime'] ?? 0));
    $age = $mtime > 0 ? max(0, time() - $mtime) : null;
    $busy = $lock['busy'] ?? null;
    $metadata = is_array($lock['metadata'] ?? null) ? $lock['metadata'] : [];
    $expiresAt = max(0, (int) ($metadata['expires_at'] ?? 0));
    $expiredMetadata = $expiresAt > 0 && $expiresAt < time();
    $possibleStale = $busy === true && (($age !== null && $age > max(30, $staleAfterSeconds)) || $expiredMetadata);
    return [
        'age_seconds' => $age,
        'stale_after_seconds' => max(30, $staleAfterSeconds),
        'metadata_expired' => $expiredMetadata,
        'possible_stale' => $possibleStale,
        'assessment' => $possibleStale
            ? 'Lock is still kernel-busy beyond the conservative expected budget/lease. Investigate a long-running or stuck worker.'
            : ($busy === true ? 'Lock is active and not old enough to classify as stale.' : 'No active kernel lock is observed.'),
    ];
}

/**
 * Parse a SQL/ISO timestamp into epoch seconds without throwing.
 */
function admin_test_run_timestamp(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $parsed = strtotime($value . (preg_match('/[zZ]|[+-]\d\d:?\d\d$/', $value) ? '' : ' UTC'));
    return $parsed === false ? 0 : $parsed;
}

/**
 * Resolve site-maintenance due/active semantics against the completed cycle marker.
 *
 * @param array<string,mixed> $schedule Schedule state.
 * @param array<string,mixed> $state Persisted maintenance state.
 * @return array<string,mixed> Diagnostic schedule interpretation.
 */
function admin_test_run_site_maintenance_due_analysis(
    array $schedule,
    array $state,
    ?bool $enabled,
    string $lastCompletedDate,
    string $lastCompletedAt,
    int $now
): array {
    $lastCompletedDate = trim($lastCompletedDate);
    $lastCompletedEpoch = admin_test_run_timestamp($lastCompletedAt);
    $scheduleDate = trim((string) ($schedule['date'] ?? ''));
    $scheduleDueRaw = !empty($schedule['due']);
    $alreadyCompletedCurrentCycle = $scheduleDate !== '' && $lastCompletedDate !== '' && hash_equals($scheduleDate, $lastCompletedDate);
    $isRunning = (string) ($state['status'] ?? '') === 'running';
    $currentlyDue = $enabled === true && $scheduleDueRaw && !$alreadyCompletedCurrentCycle;
    $scheduledEpoch = admin_test_run_timestamp((string) ($schedule['scheduled_at'] ?? ''));
    $nextExpected = (string) ($schedule['scheduled_at'] ?? '');
    if ($alreadyCompletedCurrentCycle && $scheduledEpoch > 0) {
        $nextExpected = gmdate('c', $scheduledEpoch + 86400);
    }
    $overdue = $currentlyDue && empty($schedule['within_window'])
        && $lastCompletedEpoch > 0 && ($now - $lastCompletedEpoch) > 36 * 3600;

    return [
        'schedule_cycle_date' => $scheduleDate,
        'schedule_due_raw' => $scheduleDueRaw,
        'last_completed_cycle_date' => $lastCompletedDate,
        'already_completed_current_cycle' => $alreadyCompletedCurrentCycle,
        'is_running' => $isRunning,
        'currently_due' => $currentlyDue,
        'overdue' => $overdue,
        'next_expected_or_due_time' => $nextExpected,
        'last_completed_epoch' => $lastCompletedEpoch,
    ];
}

/**
 * Return true when one persisted job/state looks excessively old for its configured budget.
 */
function admin_test_run_state_looks_stuck(array $state, int $budgetSeconds, int $minimumSeconds = 300): bool
{
    $status = strtolower((string) ($state['status'] ?? ''));
    if (!in_array($status, ['running', 'active', 'processing'], true)) {
        return false;
    }
    $updated = max(
        (int) ($state['updated_at'] ?? 0),
        admin_test_run_timestamp((string) ($state['updated_at'] ?? '')),
        admin_test_run_timestamp((string) ($state['last_step_at'] ?? ''))
    );
    if ($updated <= 0) {
        return false;
    }
    return time() - $updated > max($minimumSeconds, max(1, $budgetSeconds) * 5);
}

/**
 * Read one updater job pointer and durable state without invoking updater pointer cleanup.
 *
 * @return array<string,mixed>|null
 */
function admin_test_run_update_job_pointer_read_only(string $pointerName): ?array
{
    if (!function_exists(__NAMESPACE__ . '\\application_update_jobs_root')) {
        return null;
    }
    $pointerName = $pointerName === 'last-job.json' ? 'last-job.json' : 'active-job.json';
    $root = application_update_jobs_root();
    $pointer = admin_test_run_read_json($root . DIRECTORY_SEPARATOR . $pointerName);
    $jobId = (string) ($pointer['job_id'] ?? '');
    if (preg_match('/^[0-9]{14}-[a-f0-9]{12}$/D', $jobId) !== 1) {
        return null;
    }
    $job = admin_test_run_read_json($root . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . $jobId . DIRECTORY_SEPARATOR . 'job.json');
    if (!$job || (string) ($job['id'] ?? '') !== $jobId) {
        return null;
    }
    return $job;
}

/**
 * Return whether a maintenance task represents work that is actually active now.
 *
 * @param array<string,mixed> $task Task snapshot.
 */
function admin_test_run_maintenance_task_active(array $task): bool
{
    if (($task['active_lock']['busy'] ?? false) === true) {
        return true;
    }
    foreach ((array) ($task['active_locks'] ?? []) as $lock) {
        if (is_array($lock) && ($lock['busy'] ?? false) === true) {
            return true;
        }
    }
    $job = $task['currently_active_job'] ?? null;
    if (is_bool($job)) {
        return $job;
    }
    if (!is_array($job) || $job === []) {
        return false;
    }
    $status = strtolower(trim((string) ($job['status'] ?? '')));
    if (in_array($status, ['complete', 'completed', 'done', 'failed', 'cancelled', 'canceled'], true)) {
        return false;
    }
    return true;
}

/**
 * Inventory every known scheduled/background/maintenance mechanism without executing it.
 *
 * @param array<int,array<string,mixed>> $requests Request records already observed in this run.
 * @return array<string,mixed>
 */
function admin_test_run_cron_and_maintenance_snapshot(array $requests = []): array
{
    $now = time();
    $tasks = [];
    $warnings = [];
    $eventsBySubsystem = [];
    foreach ($requests as $request) {
        foreach ((array) ($request['maintenance_events'] ?? []) as $event) {
            if (!is_array($event)) {
                continue;
            }
            $subsystem = (string) ($event['subsystem'] ?? 'unknown');
            $eventsBySubsystem[$subsystem][] = $event;
        }
    }

    try {
        $enabled = function_exists(__NAMESPACE__ . '\\site_maintenance_enabled') ? site_maintenance_enabled() : null;
        $schedule = function_exists(__NAMESPACE__ . '\\site_maintenance_schedule_due_state') ? site_maintenance_schedule_due_state($now) : [];
        $state = function_exists(__NAMESPACE__ . '\\site_maintenance_state') ? site_maintenance_state() : [];
        $lastResult = function_exists(__NAMESPACE__ . '\\site_maintenance_last_result') ? site_maintenance_last_result() : [];
        $budget = function_exists(__NAMESPACE__ . '\\site_maintenance_time_budget_seconds') ? site_maintenance_time_budget_seconds() : null;
        $batchSize = function_exists(__NAMESPACE__ . '\\site_maintenance_batch_size') ? site_maintenance_batch_size() : null;
        $triggerEnabled = function_exists(__NAMESPACE__ . '\\site_maintenance_request_trigger_enabled') ? site_maintenance_request_trigger_enabled() : false;
        $detachSupported = function_exists(__NAMESPACE__ . '\\site_maintenance_request_trigger_can_detach_response') ? site_maintenance_request_trigger_can_detach_response() : false;
        $lastCompletedAt = function_exists(__NAMESPACE__ . '\\app_setting') ? (string) app_setting('site_maintenance_last_completed_at', '') : '';
        $lastCompletedDate = function_exists(__NAMESPACE__ . '\\app_setting') ? trim((string) app_setting('site_maintenance_last_completed_date', '')) : '';
        $maintenanceDue = admin_test_run_site_maintenance_due_analysis($schedule, $state, $enabled, $lastCompletedDate, $lastCompletedAt, $now);
        $lastCompletedEpoch = (int) ($maintenanceDue['last_completed_epoch'] ?? 0);
        $scheduleDate = (string) ($maintenanceDue['schedule_cycle_date'] ?? '');
        $scheduleDueRaw = !empty($maintenanceDue['schedule_due_raw']);
        $alreadyCompletedCurrentCycle = !empty($maintenanceDue['already_completed_current_cycle']);
        $isRunning = !empty($maintenanceDue['is_running']);
        $currentlyDue = !empty($maintenanceDue['currently_due']);
        $nextExpected = (string) ($maintenanceDue['next_expected_or_due_time'] ?? '');
        $lock = function_exists(__NAMESPACE__ . '\\site_maintenance_lock_path') ? admin_test_run_lock_snapshot(site_maintenance_lock_path()) : [];
        $lockAssessment = admin_test_run_lock_assessment($lock, is_int($budget) ? max(120, $budget * 5) : 600);
        $overdue = !empty($maintenanceDue['overdue']);
        $tasks['site_maintenance'] = [
            'subsystem' => 'site_maintenance',
            'enabled' => $enabled,
            'configured_schedule_or_cadence' => function_exists(__NAMESPACE__ . '\\site_maintenance_utc_time')
                ? 'daily at ' . site_maintenance_utc_time() . ' UTC within ' . (function_exists(__NAMESPACE__ . '\\site_maintenance_window_minutes') ? site_maintenance_window_minutes() : 0) . ' minute window'
                : 'unknown',
            'external_cron_or_cli_available' => is_file(dirname(__DIR__, 3) . '/scripts/site_maintenance.php'),
            'external_cron_configured' => 'not_observable_from_php',
            'next_expected_or_due_time' => $nextExpected,
            'schedule_cycle_date' => $scheduleDate,
            'schedule_due_raw' => $scheduleDueRaw,
            'last_completed_cycle_date' => $lastCompletedDate,
            'already_completed_current_cycle' => $alreadyCompletedCurrentCycle,
            'last_attempted_time' => (string) ($state['last_step_at'] ?? $state['updated_at'] ?? ''),
            'last_successful_time' => $lastCompletedAt,
            'last_failure' => !empty($lastResult['ok']) ? null : ($lastResult !== [] ? $lastResult : null),
            'time_since_last_run_seconds' => $lastCompletedEpoch > 0 ? max(0, $now - $lastCompletedEpoch) : null,
            'currently_due' => $currentlyDue,
            'overdue' => $overdue,
            'trigger_sources' => ['cron_web_endpoint', 'CLI', 'explicit_Admin_action', 'authenticated_Admin_request_trigger'],
            'active_lock' => $lock,
            'stale_lock_assessment' => $lockAssessment,
            'currently_active_job' => $isRunning ? $state : null,
            'resumable_job_state' => $isRunning,
            'previous_execution_duration_seconds' => isset($lastResult['elapsed_ms']) ? ((float) $lastResult['elapsed_ms'] / 1000.0) : null,
            'configured_execution_budget_seconds' => $budget,
            'configured_work_or_item_budget' => $batchSize,
            'can_execute_after_response' => $triggerEnabled && $detachSupported,
            'response_detach_primitive_available' => $detachSupported,
            'php_worker_remains_occupied_after_response' => $triggerEnabled && $detachSupported,
            'current_test_run_attempted_or_scheduled_it' => $eventsBySubsystem['site_maintenance'] ?? [],
            'public_anonymous_traffic_can_trigger' => false,
            'authenticated_admin_traffic_can_trigger' => $triggerEnabled,
            'self_loopback_chain_capable' => function_exists(__NAMESPACE__ . '\\site_maintenance_fire_web_cron_slice'),
            'warnings' => [],
        ];
        if ($lockAssessment['possible_stale']) {
            $tasks['site_maintenance']['warnings'][] = 'Possible stale/long-running site-maintenance lock.';
        }
        if ($overdue) {
            $tasks['site_maintenance']['warnings'][] = 'Site maintenance appears overdue relative to its daily cadence.';
        }
        if (admin_test_run_state_looks_stuck($state, is_int($budget) ? $budget : 20)) {
            $tasks['site_maintenance']['warnings'][] = 'Resumable maintenance state has not advanced within a conservative multiple of its execution budget.';
        }
    } catch (Throwable $exception) {
        $tasks['site_maintenance'] = ['subsystem' => 'site_maintenance', 'error' => $exception->getMessage(), 'observation_only' => true];
    }

    try {
        $status = function_exists(__NAMESPACE__ . '\\application_autoupdate_status') ? application_autoupdate_status() : [];
        $activeJob = admin_test_run_update_job_pointer_read_only('active-job.json');
        $lastJob = admin_test_run_update_job_pointer_read_only('last-job.json');
        $activePublic = is_array($activeJob) && function_exists(__NAMESPACE__ . '\\application_update_job_public_state') ? application_update_job_public_state($activeJob) : $activeJob;
        $lastPublic = is_array($lastJob) && function_exists(__NAMESPACE__ . '\\application_update_job_public_state') ? application_update_job_public_state($lastJob) : $lastJob;
        $lastChecked = max(0, (int) ($status['last_checked_at'] ?? 0));
        $due = !empty($status['effective']) && ($lastChecked <= 0 || $now - $lastChecked >= 3600);
        $jobsRoot = function_exists(__NAMESPACE__ . '\\application_update_jobs_root') ? application_update_jobs_root() : '';
        $startLock = $jobsRoot !== '' ? admin_test_run_lock_snapshot($jobsRoot . DIRECTORY_SEPARATOR . 'start.lock') : [];
        $workerLock = [];
        if (is_array($activeJob) && !empty($activeJob['id']) && function_exists(__NAMESPACE__ . '\\application_update_job_dir')) {
            $workerLock = admin_test_run_lock_snapshot(application_update_job_dir((string) $activeJob['id']) . DIRECTORY_SEPARATOR . 'worker.lock');
        }
        $workerAssessment = admin_test_run_lock_assessment($workerLock, 120);
        $tasks['automatic_updater'] = [
            'subsystem' => 'automatic_updater',
            'enabled' => (bool) ($status['enabled'] ?? false),
            'effective' => (bool) ($status['effective'] ?? false),
            'configured_schedule_or_cadence' => 'request-time stable update check, minimum 3600 seconds; durable jobs advance in bounded slices',
            'external_cron_or_cli_available' => is_file(dirname(__DIR__, 3) . '/scripts/application_update.php'),
            'external_cron_configured' => 'not_observable_from_php',
            'next_expected_or_due_time' => $lastChecked > 0 ? gmdate('c', $lastChecked + 3600) : null,
            'last_attempted_time' => $lastChecked > 0 ? gmdate('c', $lastChecked) : null,
            'last_successful_time' => is_array($lastPublic) && (int) ($lastPublic['finished_at'] ?? 0) > 0 ? gmdate('c', (int) $lastPublic['finished_at']) : null,
            'last_failure' => is_array($lastPublic) ? ($lastPublic['error'] ?? null) : null,
            'time_since_last_run_seconds' => $lastChecked > 0 ? max(0, $now - $lastChecked) : null,
            'currently_due' => $due,
            'overdue' => $due && $lastChecked > 0 && $now - $lastChecked > 6 * 3600,
            'trigger_sources' => ['normal_GET_or_HEAD_request', 'Admin_update_page', 'CLI_or_cron'],
            'active_locks' => ['start' => $startLock, 'worker' => $workerLock],
            'stale_lock_assessment' => $workerAssessment,
            'currently_active_job' => $activePublic,
            'resumable_job_state' => is_array($activePublic) ? !empty($activePublic['can_resume']) : false,
            'previous_execution_duration_seconds' => is_array($lastPublic) && (int) ($lastPublic['started_at'] ?? 0) > 0 && (int) ($lastPublic['finished_at'] ?? 0) >= (int) ($lastPublic['started_at'] ?? 0)
                ? (int) $lastPublic['finished_at'] - (int) $lastPublic['started_at'] : null,
            'configured_execution_budget_seconds' => ['normal_request_continue' => 3.0, 'admin_continue_or_retry' => 7.0],
            'configured_work_or_item_budget' => is_array($activePublic) ? ($activePublic['runtime_limits'] ?? []) : [],
            'can_execute_after_response' => false,
            'response_detach_primitive_used' => false,
            'php_worker_remains_occupied_after_response' => false,
            'php_worker_can_be_occupied_before_response' => true,
            'current_test_run_attempted_or_scheduled_it' => $eventsBySubsystem['automatic_updater'] ?? [],
            'public_anonymous_traffic_can_trigger' => true,
            'authenticated_admin_traffic_can_trigger' => true,
            'warnings' => [],
        ];
        if (is_array($activePublic) && $activePublic !== []) {
            $tasks['automatic_updater']['warnings'][] = 'An active updater job can consume a bounded PHP slice on an otherwise normal GET/HEAD request before its response.';
        }
        if ($workerAssessment['possible_stale']) {
            $tasks['automatic_updater']['warnings'][] = 'Active updater worker lock is older than the conservative diagnostic threshold.';
        }
    } catch (Throwable $exception) {
        $tasks['automatic_updater'] = ['subsystem' => 'automatic_updater', 'error' => $exception->getMessage(), 'observation_only' => true];
    }

    try {
        $status = function_exists(__NAMESPACE__ . '\\admin_log_archive_status') ? admin_log_archive_status() : [];
        $nextRun = max(0, (int) ($status['next_run_at'] ?? 0));
        $lastSuccessEpoch = admin_test_run_timestamp((string) ($status['last_success_at'] ?? ''));
        $lockPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'admin-log-archive-maintenance.lock';
        $lock = admin_test_run_lock_snapshot($lockPath);
        $lockAssessment = admin_test_run_lock_assessment($lock, 600);
        $tasks['admin_log_archive_maintenance'] = [
            'subsystem' => 'admin_log_archive_maintenance',
            'enabled' => !empty($status['enabled']),
            'configured_schedule_or_cadence' => '24-hour counter; short retry while backlog remains',
            'external_cron_or_cli_available' => is_file(dirname(__DIR__, 3) . '/scripts/telemetry_maintenance.php') || is_file(dirname(__DIR__, 3) . '/scripts/site_maintenance.php'),
            'external_cron_configured' => 'not_observable_from_php',
            'next_expected_or_due_time' => $nextRun > 0 ? gmdate('c', $nextRun) : null,
            'last_attempted_time' => (string) ($status['last_attempt_at'] ?? ''),
            'last_successful_time' => (string) ($status['last_success_at'] ?? ''),
            'last_failure' => is_array($status['last_result'] ?? null) && empty($status['last_result']['ok']) ? $status['last_result'] : null,
            'time_since_last_run_seconds' => $lastSuccessEpoch > 0 ? max(0, $now - $lastSuccessEpoch) : null,
            'currently_due' => !empty($status['enabled']) && ($nextRun <= 0 || $nextRun <= $now),
            'overdue' => !empty($status['enabled']) && $nextRun > 0 && $now - $nextRun > 6 * 3600,
            'trigger_sources' => ['authenticated_Admin_request', 'explicit_Admin_action', 'maintenance_or_CLI'],
            'active_lock' => $lock,
            'stale_lock_assessment' => $lockAssessment,
            'currently_active_job' => is_array($status['last_result'] ?? null) && !empty($status['last_result']['has_more']) ? $status['last_result'] : null,
            'resumable_job_state' => is_array($status['last_result'] ?? null) && !empty($status['last_result']['has_more']),
            'previous_execution_duration_seconds' => isset($status['last_result']['elapsed_ms']) ? ((float) $status['last_result']['elapsed_ms'] / 1000.0) : null,
            'configured_execution_budget_seconds' => 'one historical day per invocation; optional caller deadline',
            'configured_work_or_item_budget' => ['row_batch' => 200, 'delete_batch' => 2000],
            'can_execute_after_response' => function_exists(__NAMESPACE__ . '\\admin_log_archive_request_trigger_can_detach_response') ? admin_log_archive_request_trigger_can_detach_response() : false,
            'php_worker_remains_occupied_after_response' => function_exists(__NAMESPACE__ . '\\admin_log_archive_request_trigger_can_detach_response') ? admin_log_archive_request_trigger_can_detach_response() : false,
            'current_test_run_attempted_or_scheduled_it' => $eventsBySubsystem['admin_log_archive'] ?? [],
            'public_anonymous_traffic_can_trigger' => false,
            'authenticated_admin_traffic_can_trigger' => true,
            'warnings' => $lockAssessment['possible_stale'] ? ['Possible stale/long-running Admin log archive lock.'] : [],
        ];
    } catch (Throwable $exception) {
        $tasks['admin_log_archive_maintenance'] = ['subsystem' => 'admin_log_archive_maintenance', 'error' => $exception->getMessage(), 'observation_only' => true];
    }

    try {
        $enabled = function_exists(__NAMESPACE__ . '\\thumbnail_warmup_enabled') ? thumbnail_warmup_enabled() : null;
        $lock = function_exists(__NAMESPACE__ . '\\thumbnail_warmup_lock_path') ? admin_test_run_lock_snapshot(thumbnail_warmup_lock_path()) : [];
        $lockAssessment = admin_test_run_lock_assessment($lock, 300);
        $tasks['thumbnail_warmup'] = [
            'subsystem' => 'thumbnail_warmup',
            'enabled' => $enabled,
            'configured_schedule_or_cadence' => 'on-demand browser repair only; no periodic cron schedule',
            'external_cron_or_cli_available' => false,
            'external_cron_configured' => false,
            'next_expected_or_due_time' => null,
            'last_attempted_time' => null,
            'last_successful_time' => null,
            'last_failure' => null,
            'time_since_last_run_seconds' => null,
            'currently_due' => false,
            'overdue' => false,
            'trigger_sources' => ['signed_browser_warmup_request'],
            'active_lock' => $lock,
            'stale_lock_assessment' => $lockAssessment,
            'currently_active_job' => $lock['busy'] ?? false,
            'resumable_job_state' => false,
            'previous_execution_duration_seconds' => null,
            'configured_execution_budget_seconds' => null,
            'configured_work_or_item_budget' => ['max_items_per_request' => 24],
            'can_execute_after_response' => false,
            'php_worker_remains_occupied_after_response' => false,
            'current_test_run_attempted_or_scheduled_it' => $eventsBySubsystem['thumbnail_warmup'] ?? [],
            'public_anonymous_traffic_can_trigger' => true,
            'public_trigger_guard' => 'requires server-rendered HMAC token and visitor authorization; bounded to 24 unique images',
            'authenticated_admin_traffic_can_trigger' => true,
            'warnings' => $lockAssessment['possible_stale'] ? ['Thumbnail warmup lock appears unexpectedly long-running.'] : [],
        ];
    } catch (Throwable $exception) {
        $tasks['thumbnail_warmup'] = ['subsystem' => 'thumbnail_warmup', 'error' => $exception->getMessage(), 'observation_only' => true];
    }

    $tasks['database_maintenance'] = [
        'subsystem' => 'database_maintenance',
        'enabled' => true,
        'configured_schedule_or_cadence' => 'manual Admin operation only',
        'external_cron_or_cli_available' => false,
        'external_cron_configured' => false,
        'next_expected_or_due_time' => null,
        'last_attempted_time' => null,
        'last_successful_time' => null,
        'last_failure' => null,
        'time_since_last_run_seconds' => null,
        'currently_due' => false,
        'overdue' => false,
        'trigger_sources' => ['explicit_authenticated_Admin_action'],
        'active_lock' => null,
        'stale_lock_assessment' => null,
        'currently_active_job' => null,
        'resumable_job_state' => function_exists(__NAMESPACE__ . '\\database_maintenance_cleanup_state') ? database_maintenance_cleanup_state() : [],
        'previous_execution_duration_seconds' => null,
        'configured_execution_budget_seconds' => null,
        'configured_work_or_item_budget' => 'explicit bounded cleanup step selected by Admin',
        'can_execute_after_response' => false,
        'php_worker_remains_occupied_after_response' => false,
        'current_test_run_attempted_or_scheduled_it' => $eventsBySubsystem['database_maintenance'] ?? [],
        'public_anonymous_traffic_can_trigger' => false,
        'authenticated_admin_traffic_can_trigger' => false,
        'warnings' => [],
    ];

    $activeBackground = 0;
    foreach ($tasks as $task) {
        if (!is_array($task)) {
            continue;
        }
        if (admin_test_run_maintenance_task_active($task)) {
            $activeBackground++;
        }
        foreach ((array) ($task['warnings'] ?? []) as $warning) {
            $warnings[] = (string) ($task['subsystem'] ?? 'unknown') . ': ' . (string) $warning;
        }
    }
    if ($activeBackground > 1) {
        $warnings[] = 'Multiple background/maintenance subsystems appear active at the same time.';
    }

    return [
        'captured_at' => gmdate('c'),
        'observation_only' => true,
        'destructive_or_heavy_tasks_executed_by_diagnostics' => false,
        'task_count' => count($tasks),
        'tasks' => $tasks,
        'active_background_subsystem_count' => $activeBackground,
        'warnings' => array_values(array_unique($warnings)),
    ];
}
