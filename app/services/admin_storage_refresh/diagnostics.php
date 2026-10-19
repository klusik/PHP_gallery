<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/admin_storage_refresh/diagnostics.php
 * Module Type: Service Part
 * Purpose: Persist bounded per-run storage-refresh diagnostics across requests.
 * Responsibilities: Time phase steps and log safe numeric completion summaries.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 * Notes: Loaded only by admin_storage_refresh.php; shared row types live there.
 */
declare(strict_types=1);

namespace Gallery\Services;

/**
 * Create a non-authorizing identity and empty diagnostics for one workflow.
 * @param bool $complete Whether observation includes the start, initially true;
 *     false labels an older workflow resumed after the diagnostics upgrade.
 * @return StorageRefreshDiagnostics Fixed-shape bounded diagnostics.
 */
function admin_storage_refresh_diagnostics_new(bool $complete = true): array
{
    return ['run_id' => bin2hex(random_bytes(16)), 'started_at' => microtime(true),
        'complete_observation' => $complete, 'resume_count' => 0,
        'phase_work_seconds' => 0.0, 'peak_memory_bytes' => 0, 'phases' => [],
        'files' => [], 'inspection' => [],
        'table_timings' => [], 'table_timings_omitted' => 0];
}

/**
 * Observe exactly one phase callback and merge its numeric data into the run.
 * @template T
 * @param StorageRefreshState $state Canonical workflow state held under its lock.
 * @param string $phase Fixed files, database or inspection phase identity.
 * @param callable():T $callback Existing phase work, invoked exactly once.
 * @return T Original callback result; original exceptions propagate after capture.
 */
function admin_storage_refresh_measure(array &$state, string $phase, callable $callback): mixed
{
    $state['diagnostics'] ??= admin_storage_refresh_diagnostics_new(false);
    $processedBefore = 0;
    foreach ($state['stages'] as $stage) {
        if ($stage['phase'] === $phase) $processedBefore = $stage['processed'];
    }
    \Gallery\Core\storage_observer_start();
    $started = hrtime(true);
    try {
        return $callback();
    } finally {
        $metrics = \Gallery\Core\storage_observer_finish();
        // Convert the monotonic nanosecond timer to seconds.
        $seconds = max(0.0, (hrtime(true) - $started) / 1e9);
        $diagnostics = &$state['diagnostics'];
        $phaseData = $diagnostics['phases'][$phase] ?? [
            'steps' => 0, 'seconds' => 0.0, 'max_step_seconds' => 0.0,
            'metrics' => [], 'slowest_steps' => [],
        ];
        $phaseData['steps']++;
        $phaseData['seconds'] += $seconds;
        $phaseData['max_step_seconds'] = max($phaseData['max_step_seconds'], $seconds);
        $phaseData['slowest_steps'][] = ['step' => $phaseData['steps'],
            'seconds' => $seconds, 'processed_before' => $processedBefore];
        usort($phaseData['slowest_steps'], static fn(array $left, array $right): int =>
            $right['seconds'] <=> $left['seconds']);
        $phaseData['slowest_steps'] = array_slice(
            $phaseData['slowest_steps'], 0, ADMIN_STORAGE_REFRESH_SLOW_STEPS_LIMIT
        );
        foreach ($metrics as $key => $value) {
            $previous = $phaseData['metrics'][$key] ?? 0;
            $phaseData['metrics'][$key] = str_ends_with($key, '.max_seconds')
                ? max($previous, $value) : $previous + $value;
        }
        $diagnostics['phases'][$phase] = $phaseData;
        $diagnostics['phase_work_seconds'] += $seconds;
        $diagnostics['peak_memory_bytes'] = max(
            $diagnostics['peak_memory_bytes'], memory_get_peak_usage(true)
        );
    }
}

/**
 * Retain only numeric media accounting, never filenames or chart labels.
 * @param StorageRefreshState $state Canonical workflow state.
 * @param array{snapshot?:StorageRefreshFileTotals|null} $file File-job response;
 *     additional transport/progress fields are not logged here.
 * @return void Copy safe counts/bytes from a completed snapshot when present.
 */
function admin_storage_refresh_record_files(array &$state, array $file): void
{
    foreach (['image_count', 'known_source_size_count', 'unknown_source_size_count',
        'original_bytes', 'generated_thumbnail_count', 'generated_thumbnail_bytes',
        'display_master_count', 'display_master_bytes', 'pdf_count', 'pdf_bytes',
        'music_count', 'music_bytes', 'attachment_scan_errors', 'thumbnail_scan_errors',
        'total_media_bytes'] as $key) {
        if (isset($file['snapshot'][$key]) && is_numeric($file['snapshot'][$key])) {
            $state['diagnostics']['files'][$key] = max(0, (int) $file['snapshot'][$key]);
        }
    }
}

/**
 * Log one lifecycle checkpoint without recording authorization capabilities.
 * @param StorageRefreshState $state Canonical state with optional legacy diagnostics.
 * @param string $event Code-owned lifecycle event suffix.
 * @param string $phase Optional completed phase; empty for whole-run events.
 * @return void Best-effort safe Admin event; logging never changes workflow results.
 */
function admin_storage_refresh_log_run(array $state, string $event, string $phase = ''): void
{
    if (!function_exists(__NAMESPACE__ . '\\admin_log_event')) return;
    $diagnostics = $state['diagnostics'] ?? null;
    if (!is_array($diagnostics)) return;
    $wall = max(0.0, microtime(true) - $diagnostics['started_at']);
    $context = ['diagnostic_version' => 1, 'run_id' => $diagnostics['run_id'],
        'started_at_utc' => gmdate('Y-m-d\TH:i:s\Z', (int) $diagnostics['started_at']),
        'checkpoint_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'workflow_status' => $state['workflow_status'],
        'phase' => $phase !== '' ? $phase : $state['phase'],
        'wall_seconds' => $wall,
        'other_wall_seconds' => max(0.0, $wall - $diagnostics['phase_work_seconds']),
        'stages' => $state['stages'], 'diagnostics' => $diagnostics,
        'runtime' => ['php_version' => PHP_VERSION, 'os_family' => PHP_OS_FAMILY,
            'cms_version' => defined('Gallery\\Core\\CMS_VERSION')
                ? (string) constant('Gallery\\Core\\CMS_VERSION') : 'unknown',
            'memory_limit' => (string) ini_get('memory_limit'),
            'max_execution_seconds' => (int) ini_get('max_execution_time')],
        'batch_sizes' => ['photos' => ADMIN_STORAGE_REFRESH_FILE_BATCH_SIZE,
            'database_tables' => ADMIN_STORAGE_REFRESH_DATABASE_BATCH_SIZE],
        'coverage' => 'Instrumented application calls; not OS physical disk I/O.'
            . ' Phase times exclude workflow cache/lock/log overhead and request gaps.'
            . ' Nested operation times overlap and must not be summed.'
            . ' db.* counts named model/service operations, not all SQL statements.'
            . ' Inspection internals and path-builder internals are not fully instrumented.'];
    try {
        $partial = $state['workflow_status'] === 'partial';
        admin_log_event($partial ? 'warning' : 'info', 'storage.refresh_all.' . $event,
            'Admin storage Update all workflow diagnostic checkpoint.', $context,
            ['category' => 'database', 'severity' => $partial ? 'warning' : 'notice']);
    } catch (\Throwable) {
        // Diagnostic publication must never invalidate completed report work.
    }
}
