<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_storage_refresh.php
 * Module Type: Service
 *
 * Purpose:
 *   Orchestrates the explicit Update all workflow for Admin storage reports.
 *
 * Responsibilities:
 *   - Reuse the resumable file-statistics job
 *   - Refresh database metadata through bounded ANALYZE batches
 *   - Run the existing read-only database maintenance inspection
 *   - Persist authoritative workflow progress outside the browser
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
 *   - This workflow never runs cleanup, repair, OPTIMIZE, or Maintenance Center execution.
 *
 * Last Updated:
 *   2026-10-04
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;

/** Define the authoritative order of Update all workflow phases.
 * @var list<string>
 * Units: named workflow phases.
 * Scope: one server-owned storage refresh job.
 * Consumers: admin_storage_refresh_all_step() and admin_storage_refresh_payload().
 * Rationale: persist and report a deterministic progression from files through database inspection.
 */
const ADMIN_STORAGE_REFRESH_PHASES = ['files', 'database', 'inspection'];
/** Set the file-statistics share of total workflow progress.
 * @var float
 * Units: percentage points of overall progress.
 * Scope: the files phase in the combined storage refresh.
 * Consumers: admin_storage_refresh_payload().
 * Rationale: allocate progress according to the relative work of the file inventory stage.
 */
const ADMIN_STORAGE_REFRESH_FILE_WEIGHT = 60.0;
/** Set the database-statistics share of total workflow progress.
 * @var float
 * Units: percentage points of overall progress.
 * Scope: the database metadata phase in the combined storage refresh.
 * Consumers: admin_storage_refresh_payload().
 * Rationale: keep database metadata work bounded while preserving room for the inspection phase.
 */
const ADMIN_STORAGE_REFRESH_DATABASE_WEIGHT = 20.0;
/** Bound resumed file-statistics work performed by one HTTP step.
 * @var int
 * Units: file-statistics records per request.
 * Scope: each files phase step in Update all.
 * Consumers: admin_storage_refresh_step_files().
 * Rationale: keep each request responsive and allow progress to persist between batches.
 */
const ADMIN_STORAGE_REFRESH_FILE_BATCH_SIZE = 20;
/** Bound database metadata work performed by one HTTP step.
 * @var int
 * Units: tables analyzed per request.
 * Scope: each database phase step in Update all.
 * Consumers: admin_storage_refresh_step_database().
 * Rationale: keep ANALYZE work resumable and below the request-time budget.
 */
const ADMIN_STORAGE_REFRESH_DATABASE_BATCH_SIZE = 5;
/** Expire abandoned server-owned Update all workflow state.
 * @var int
 * Units: seconds since the last persisted workflow update.
 * Scope: the shared storage refresh cache record.
 * Consumers: admin_storage_refresh_read_state().
 * Rationale: prevent stale jobs from blocking later refreshes indefinitely.
 */
const ADMIN_STORAGE_REFRESH_JOB_TTL = 3600;

/**
 * Start one server-owned Update all workflow for the given administrator.
 *
 * @param int $actorId Authenticated administrator ID owning this workflow.
 * @return array<string, mixed> Initial workflow identity and authoritative progress.
 */
function admin_storage_refresh_all_start(int $actorId): array
{
    return admin_storage_refresh_with_lock(static function () use ($actorId): array {
        if ($actorId <= 0) {
            return admin_storage_refresh_error_payload();
        }
        $active = admin_storage_refresh_read_state();
        if (is_array($active) && (string) ($active['workflow_status'] ?? '') === 'running') {
            $activeId = (string) ($active['workflow_id'] ?? '');
            $activeOwnerId = (int) ($active['owner_id'] ?? 0);
            $activePhase = (string) ($active['phase'] ?? '');
            $compatible = preg_match('/^[a-f0-9]{32}$/D', $activeId)
                && $activeOwnerId > 0
                && in_array($activePhase, ['files', 'database', 'inspection', 'complete'], true)
                && is_array($active['stages'] ?? null);
            if ($compatible) {
                return $activeOwnerId === $actorId
                    ? admin_storage_refresh_payload($active)
                    : admin_storage_refresh_error_payload();
            }
        }
        $workflowId = bin2hex(random_bytes(16));
        $state = [
            'workflow_id' => $workflowId,
            'owner_id' => $actorId,
            'phase' => 'files',
            'workflow_status' => 'running',
            'database_processed' => 0,
            'database_total' => 0,
            'database_failed' => 0,
            'stages' => [admin_storage_refresh_stage('files', 'running', 0, 0, 0)],
            'updated_at' => time(),
        ];

        try {
            $file = admin_storage_statistics_start_job();
            $fileStatus = (string) ($file['status'] ?? 'error');
            $processed = max(0, (int) ($file['processed'] ?? 0));
            $total = max(0, (int) ($file['total'] ?? 0));
            $stageStatus = $fileStatus === 'running' ? 'running' : ($fileStatus === 'complete' ? 'complete' : 'failed');
            $state['stages'][0] = admin_storage_refresh_stage('files', $stageStatus, $processed, $total, $stageStatus === 'failed' ? 1 : 0);
            if ($stageStatus !== 'running') {
                $state['phase'] = 'database';
            }
        } catch (Throwable $exception) {
            admin_storage_refresh_log_failure('files', $exception);
            $state['stages'][0] = admin_storage_refresh_stage('files', 'failed', 0, 0, 1);
            $state['phase'] = 'database';
        }

        admin_storage_refresh_write_state($state);
        return admin_storage_refresh_payload($state);
    });
}

/**
 * Advance exactly one bounded phase step from persisted workflow state.
 *
 * @param string $workflowId Opaque server-issued workflow identity.
 * @param int $actorId Authenticated administrator ID making the request.
 * @return array<string, mixed> Updated authoritative workflow progress.
 */
function admin_storage_refresh_all_step(string $workflowId, int $actorId): array
{
    return admin_storage_refresh_with_lock(static function () use ($workflowId, $actorId): array {
        $state = admin_storage_refresh_read_state();
        if (!is_array($state)
            || !preg_match('/^[a-f0-9]{32}$/D', $workflowId)
            || !hash_equals((string) ($state['workflow_id'] ?? ''), $workflowId)
            || (int) ($state['owner_id'] ?? 0) !== $actorId
        ) {
            return admin_storage_refresh_error_payload();
        }
        if ((string) ($state['workflow_status'] ?? '') !== 'running') {
            return admin_storage_refresh_payload($state);
        }

        $phase = (string) ($state['phase'] ?? '');
        if (!in_array($phase, ADMIN_STORAGE_REFRESH_PHASES, true)) {
            return admin_storage_refresh_error_payload($workflowId);
        }
        if ($phase === 'files') {
            $state = admin_storage_refresh_step_files($state);
        } elseif ($phase === 'database') {
            $state = admin_storage_refresh_step_database($state);
        } else {
            $state = admin_storage_refresh_step_inspection($state);
        }
        // The ID doubles as a one-step capability, so a stale/replayed POST cannot advance again.
        $state['workflow_id'] = bin2hex(random_bytes(16));
        $state['updated_at'] = time();
        admin_storage_refresh_write_state($state);
        return admin_storage_refresh_payload($state);
    });
}

/**
 * Process one bounded file-statistics batch and advance from its persisted result.
 *
 * @param array<string, mixed> $state Canonical workflow state loaded under lock.
 * @return array<string, mixed> Updated canonical state.
 */
function admin_storage_refresh_step_files(array $state): array
{
    try {
        $file = admin_storage_statistics_process_job(ADMIN_STORAGE_REFRESH_FILE_BATCH_SIZE);
        $status = (string) ($file['status'] ?? 'error');
        $processed = max(0, (int) ($file['processed'] ?? 0));
        $total = max(0, (int) ($file['total'] ?? 0));
        $stageStatus = $status === 'running' ? 'running' : ($status === 'complete' ? 'complete' : 'failed');
    } catch (Throwable $exception) {
        admin_storage_refresh_log_failure('files', $exception);
        $processed = 0;
        $total = 0;
        $stageStatus = 'failed';
    }
    $state['stages'][0] = admin_storage_refresh_stage('files', $stageStatus, $processed, $total, $stageStatus === 'failed' ? 1 : 0);
    if ($stageStatus !== 'running') {
        $state['phase'] = 'database';
        $state['database_processed'] = 0;
        $state['database_total'] = 0;
        $state['database_failed'] = 0;
        $state['stages'][1] = admin_storage_refresh_stage('database', 'running', 0, 0, 0);
    }
    return $state;
}

/**
 * Process one bounded database metadata batch using the persisted cursor.
 *
 * @param array<string, mixed> $state Canonical workflow state loaded under lock.
 * @return array<string, mixed> Updated canonical state.
 */
function admin_storage_refresh_step_database(array $state): array
{
    $processedBefore = max(0, (int) ($state['database_processed'] ?? 0));
    $failedBefore = max(0, (int) ($state['database_failed'] ?? 0));
    try {
        $batch = admin_database_usage_recompute_statistics_batch($processedBefore, ADMIN_STORAGE_REFRESH_DATABASE_BATCH_SIZE);
        $processed = max($processedBefore, (int) ($batch['processed'] ?? $processedBefore));
        $total = max(0, (int) ($batch['total'] ?? 0));
        $failed = $failedBefore + max(0, (int) ($batch['failed_table_count'] ?? 0));
        $complete = !empty($batch['complete']);
        $status = $complete ? ($failed > 0 ? 'partial' : 'complete') : 'running';
    } catch (Throwable $exception) {
        admin_storage_refresh_log_failure('database', $exception);
        $processed = $processedBefore;
        $total = max($processedBefore, (int) ($state['database_total'] ?? 0));
        $failed = $failedBefore + 1;
        $status = 'failed';
    }
    $state['database_processed'] = $processed;
    $state['database_total'] = $total;
    $state['database_failed'] = $failed;
    $state['stages'][1] = admin_storage_refresh_stage('database', $status, $processed, $total, $failed);
    if ($status !== 'running') {
        if (isset($batch)) {
            admin_storage_refresh_log_database_completion($total, $failed);
        }
        $state['phase'] = 'inspection';
        $state['stages'][2] = admin_storage_refresh_stage('inspection', 'running', 0, 1, 0);
    }
    return $state;
}

/**
 * Run the bounded read-only inspection and mark the workflow terminal.
 *
 * @param array<string, mixed> $state Canonical workflow state loaded under lock.
 * @return array<string, mixed> Terminal canonical state.
 */
function admin_storage_refresh_step_inspection(array $state): array
{
    try {
        $report = database_maintenance_inspect();
        $status = !empty($report['ok']) ? 'complete' : 'failed';
    } catch (Throwable $exception) {
        admin_storage_refresh_log_failure('inspection', $exception);
        $status = 'failed';
    }
    $state['stages'][2] = admin_storage_refresh_stage('inspection', $status, 1, 1, $status === 'failed' ? 1 : 0);
    $hasFailures = array_filter((array) $state['stages'], static fn (array $stage): bool => in_array((string) ($stage['status'] ?? ''), ['partial', 'failed'], true)) !== [];
    $state['workflow_status'] = $hasFailures ? 'partial' : 'complete';
    $state['phase'] = 'complete';
    return $state;
}

/**
 * Create the safe progress payload solely from canonical server state.
 *
 * @param array<string, mixed> $state Canonical workflow state.
 * @return array<string, mixed> Browser-safe progress payload.
 */
function admin_storage_refresh_payload(array $state): array
{
    $phase = (string) ($state['phase'] ?? 'complete');
    $stages = array_values(array_slice((array) ($state['stages'] ?? []), 0, 3));
    $current = [];
    foreach ($stages as $stage) {
        if (is_array($stage) && (string) ($stage['phase'] ?? '') === $phase) {
            $current = $stage;
            break;
        }
    }
    $phaseProcessed = max(0, (int) ($current['processed'] ?? 0));
    $phaseTotal = max(0, (int) ($current['total'] ?? 0));
    $phasePercent = $phaseTotal > 0 ? min(100.0, round(($phaseProcessed / $phaseTotal) * 100, 1)) : 0.0;
    if ($phase === 'complete') {
        $phasePercent = 100.0;
        $phaseProcessed = 1;
        $phaseTotal = 1;
    }
    $fileStage = $stages[0] ?? [];
    $databaseStage = $stages[1] ?? [];
    $filePercent = (int) ($fileStage['total'] ?? 0) > 0
        ? min(100.0, ((int) ($fileStage['processed'] ?? 0) / (int) $fileStage['total']) * 100)
        : (in_array((string) ($fileStage['status'] ?? ''), ['complete', 'partial', 'failed'], true) ? 100.0 : 0.0);
    $databasePercent = (int) ($databaseStage['total'] ?? 0) > 0
        ? min(100.0, ((int) ($databaseStage['processed'] ?? 0) / (int) $databaseStage['total']) * 100)
        : (in_array((string) ($databaseStage['status'] ?? ''), ['complete', 'partial', 'failed'], true) ? 100.0 : 0.0);
    $overall = min(100.0, ($filePercent * ADMIN_STORAGE_REFRESH_FILE_WEIGHT / 100)
        + ($databasePercent * ADMIN_STORAGE_REFRESH_DATABASE_WEIGHT / 100)
        + (in_array((string) (($stages[2]['status'] ?? '')), ['complete', 'failed'], true) ? 20.0 : 0.0));
    if ($phase === 'complete') {
        $overall = 100.0;
    }
    $workflowStatus = (string) ($state['workflow_status'] ?? 'running');
    $messageKey = match ($workflowStatus) {
        'complete' => 'admin.storage.refresh_all.status.complete',
        'partial' => 'admin.storage.refresh_all.status.partial',
        'error' => 'admin.storage.refresh_all.status.error',
        default => 'admin.storage.refresh_all.status.running',
    };
    $fallback = match ($workflowStatus) {
        'complete' => 'All storage reports are up to date.',
        'partial' => 'Update finished with one or more report errors.',
        'error' => 'The update could not be started.',
        default => 'Updating storage and database reports.',
    };
    return [
        'ok' => true,
        'workflow_id' => (string) ($state['workflow_id'] ?? ''),
        'workflow_status' => $workflowStatus,
        'phase' => $phase,
        'processed' => $phaseProcessed,
        'total' => $phaseTotal,
        'phase_percent' => $phasePercent,
        'overall_percent' => $overall,
        'message' => t($messageKey, $fallback),
        'stages' => $stages,
    ];
}

/**
 * Build an error response without exposing whether a workflow belongs to another administrator.
 *
 * @param string $workflowId Optional requested workflow identity.
 * @return array<string, mixed> Safe generic error payload.
 */
function admin_storage_refresh_error_payload(string $workflowId = ''): array
{
    return [
        'ok' => false,
        'workflow_id' => preg_match('/^[a-f0-9]{32}$/D', $workflowId) ? $workflowId : '',
        'workflow_status' => 'error',
        'phase' => 'complete',
        'processed' => 0,
        'total' => 0,
        'phase_percent' => 0.0,
        'overall_percent' => 0.0,
        'message' => t('admin.storage.refresh_all.status.error', 'The update could not be started.'),
        'stages' => [],
    ];
}

/**
 * Normalize a workflow stage record for server-side persistence.
 *
 * @param string $phase Known workflow phase.
 * @param string $status Current phase status.
 * @param int $processed Completed work units.
 * @param int $total Total work units.
 * @param int $failed Failed work units.
 * @return array<string, mixed> Normalized stage record.
 */
function admin_storage_refresh_stage(string $phase, string $status, int $processed, int $total, int $failed): array
{
    return [
        'phase' => $phase,
        'status' => $status,
        'processed' => max(0, $processed),
        'total' => max(0, $total),
        'failed' => max(0, $failed),
    ];
}

/**
 * Execute a workflow state operation while holding the shared bounded lock.
 *
 * @param callable():array<string, mixed> $operation State operation to execute.
 * @return array<string, mixed> Operation result or safe busy error.
 */
function admin_storage_refresh_with_lock(callable $operation): array
{
    $directory = admin_storage_refresh_job_directory();
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return admin_storage_refresh_error_payload();
    }
    $handle = @fopen($directory . DIRECTORY_SEPARATOR . 'workflow.lock', 'c');
    if ($handle === false) {
        return admin_storage_refresh_error_payload();
    }
    try {
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            return admin_storage_refresh_error_payload();
        }
        return $operation();
    } catch (Throwable $exception) {
        admin_storage_refresh_log_failure('workflow', $exception);
        return admin_storage_refresh_error_payload();
    } finally {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

/**
 * Return the fixed workflow cache directory, with an isolated test override.
 *
 * @return string Absolute directory used for bounded workflow state.
 */
function admin_storage_refresh_job_directory(): string
{
    if (defined('ADMIN_STORAGE_REFRESH_TEST_CACHE_DIR')) {
        return (string) constant('ADMIN_STORAGE_REFRESH_TEST_CACHE_DIR');
    }
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'admin-storage-refresh';
}

/**
 * Read a fresh canonical workflow record from the private cache directory.
 *
 * @return array<string, mixed>|null Current workflow state, if valid and unexpired.
 */
function admin_storage_refresh_read_state(): ?array
{
    $path = admin_storage_refresh_job_directory() . DIRECTORY_SEPARATOR . 'workflow.json';
    if (!is_file($path) || (int) @filemtime($path) < time() - ADMIN_STORAGE_REFRESH_JOB_TTL) {
        return null;
    }
    $json = @file_get_contents($path);
    $state = is_string($json) ? json_decode($json, true) : null;
    return is_array($state) ? $state : null;
}

/**
 * Atomically persist canonical workflow state while the workflow lock is held.
 *
 * @param array<string, mixed> $state Canonical bounded workflow state.
 * @return void Writes state using a same-directory temporary file and rename.
 */
function admin_storage_refresh_write_state(array $state): void
{
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Storage refresh state could not be encoded.');
    }
    $path = admin_storage_refresh_job_directory() . DIRECTORY_SEPARATOR . 'workflow.json';
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($temporary, $json . "\n", LOCK_EX) === false || !@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Storage refresh state could not be saved.');
    }
}

/**
 * Log one failed workflow phase without exposing exception text to the browser.
 *
 * @param string $phase Failed phase name.
 * @param Throwable $exception Underlying service failure.
 * @return void Writes one bounded diagnostic event when logging is available.
 */
function admin_storage_refresh_log_failure(string $phase, Throwable $exception): void
{
    if (function_exists(__NAMESPACE__ . '\\admin_log_event')) {
        try {
            admin_log_event('error', 'storage.refresh_all_stage_failed', 'An Admin storage refresh stage failed.', [
                'phase' => $phase,
                'exception_class' => $exception::class,
            ], ['category' => 'database', 'severity' => 'error']);
        } catch (Throwable) {
            // Diagnostics must not change the persisted workflow result.
        }
    }
}

/**
 * Log bounded completion counts for database metadata refresh.
 *
 * @param int $tableCount Number of tables considered for ANALYZE.
 * @param int $failedCount Number of table analyses that failed.
 * @return void Writes one summary event when logging is available.
 */
function admin_storage_refresh_log_database_completion(int $tableCount, int $failedCount): void
{
    if (function_exists(__NAMESPACE__ . '\\admin_log_event')) {
        try {
            admin_log_event('info', 'database_usage.recomputed', 'Admin recomputed database table statistics.', [
                'table_count' => max(0, $tableCount),
                'failed_table_count' => max(0, $failedCount),
            ], ['category' => 'database', 'severity' => $failedCount > 0 ? 'warning' : 'notice']);
        } catch (Throwable) {
            // Logging must not change the persisted workflow result.
        }
    }
}
