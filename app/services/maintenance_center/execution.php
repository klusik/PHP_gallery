<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/maintenance_center/execution.php
 * Module Type: Service Part
 *
 * Purpose:
 *   Executes an approved Maintenance Center plan through bounded persisted steps.
 *
 * Responsibilities:
 *   - Validate plan freshness, task selection, state transitions, and central lock ownership
 *   - Execute one registered task slice per call and persist checkpoints before returning
 *   - Guarantee one-table-per-request physical database maintenance
 *   - Implement cooperative pause/cancel and retry-safe atomic table cursors
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Loaded only by app/services/maintenance_center.php.
 *   - Existing subsystem services remain authoritative for the actual maintenance work.
 *
 * Last Updated:
 *   2026-09-20
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use function Gallery\Core\now_sql;
use function Gallery\Models\maintenance_center_model_claim_mutation_lock;
use function Gallery\Models\maintenance_center_model_cleanup_history;
use function Gallery\Models\maintenance_center_model_finish_job;
use function Gallery\Models\maintenance_center_model_job;
use function Gallery\Models\maintenance_center_model_acquire_step_lock;
use function Gallery\Models\maintenance_center_model_release_step_lock;
use function Gallery\Models\maintenance_center_model_mutation_owner;
use function Gallery\Models\maintenance_center_model_request_cancel;
use function Gallery\Models\maintenance_center_model_schema_revision;
use function Gallery\Models\maintenance_center_model_update_job;

/** Return one plan task by stable key. */
function maintenance_center_plan_task(array $plan, string $key): ?array
{
    foreach ((array) ($plan['tasks'] ?? []) as $task) {
        if (is_array($task) && (string) ($task['key'] ?? '') === $key) {
            return $task;
        }
    }
    return null;
}

/** Check all immutable plan revision bindings without mutating the job. */
function maintenance_center_plan_freshness(array $row, ?array $plan = null): array
{
    $plan ??= maintenance_center_json_decode(isset($row['plan_json']) ? (string) $row['plan_json'] : null);
    $expectedHash = (string) ($row['plan_hash'] ?? '');
    $actualHash = isset($row['plan_json']) && is_string($row['plan_json']) ? hash('sha256', $row['plan_json']) : '';
    $checks = [
        'plan_hash' => $expectedHash !== '' && hash_equals($expectedHash, $actualHash),
        'app_version' => (string) ($row['app_version'] ?? '') === maintenance_center_app_version()
            && (string) ($plan['app_version'] ?? '') === maintenance_center_app_version(),
        'schema_revision' => (string) ($row['schema_revision'] ?? '') !== 'unknown'
            && (string) ($row['schema_revision'] ?? '') === maintenance_center_model_schema_revision()
            && (string) ($plan['schema_revision'] ?? '') === maintenance_center_model_schema_revision(),
        'registry_revision' => (string) ($row['registry_revision'] ?? '') === MAINTENANCE_CENTER_REGISTRY_REVISION
            && (string) ($plan['registry_revision'] ?? '') === MAINTENANCE_CENTER_REGISTRY_REVISION,
    ];
    return [
        'fresh' => !in_array(false, $checks, true),
        'checks' => $checks,
        'analyzed_app_version' => (string) ($row['app_version'] ?? ''),
        'current_app_version' => maintenance_center_app_version(),
        'analyzed_schema_revision' => (string) ($row['schema_revision'] ?? ''),
        'current_schema_revision' => maintenance_center_model_schema_revision(),
        'analyzed_registry_revision' => (string) ($row['registry_revision'] ?? ''),
        'current_registry_revision' => MAINTENANCE_CENTER_REGISTRY_REVISION,
    ];
}

/** Initialize or resume the approved execution plan and acquire the durable mutation claim. */
function maintenance_center_start_execution(int $jobId, int $actorId, array $selectedTaskKeys, array $options = []): array
{
    if (!maintenance_center_model_acquire_step_lock($jobId)) {
        return maintenance_center_job_status($jobId, $actorId);
    }
    try {
        return maintenance_center_start_execution_unlocked($jobId, $actorId, $selectedTaskKeys, $options);
    } finally {
        maintenance_center_model_release_step_lock($jobId);
    }
}

/** Start/resume execution while the per-job single-flight lock is held. */
function maintenance_center_start_execution_unlocked(int $jobId, int $actorId, array $selectedTaskKeys, array $options = []): array
{
    $row = maintenance_center_model_job($jobId);
    if (!is_array($row) || (int) ($row['actor_id'] ?? 0) !== $actorId) {
        throw new RuntimeException('Maintenance Center job was not found.');
    }
    $status = (string) ($row['status'] ?? '');
    if ($status === 'running') {
        // Double-click/retry admission is idempotent. The existing running job is authoritative.
        return maintenance_center_job_status($jobId, $actorId);
    }
    if (!in_array($status, ['ready', 'paused'], true)) {
        throw new RuntimeException('Maintenance Center job is not ready to run or resume.');
    }
    $plan = maintenance_center_json_decode(isset($row['plan_json']) ? (string) $row['plan_json'] : null);
    $freshness = maintenance_center_plan_freshness($row, $plan);
    if (empty($freshness['fresh'])) {
        throw new RuntimeException('Maintenance Center plan is stale. Analyze again before executing maintenance.');
    }

    $state = maintenance_center_json_decode((string) ($row['state_json'] ?? '{}'));
    if ($status === 'ready') {
        $selected = maintenance_center_expand_selection($selectedTaskKeys, (array) ($plan['tasks'] ?? []));
        if ($selected === []) {
            throw new RuntimeException('No runnable Maintenance Center tasks were selected.');
        }
        $total = 0;
        foreach ($selected as $key) {
            $planTask = maintenance_center_plan_task($plan, $key);
            $total += max(1, (int) ($planTask['work_units'] ?? 1));
        }
        $state['execution'] = [
            'selected_tasks' => $selected,
            'task_index' => 0,
            'task_states' => [],
            'completed_units' => 0,
            'options' => [
                'full_physical_optimization' => !empty($options['full_physical_optimization']),
                'deep_media_verification' => in_array('media.deep_verify', $selected, true),
            ],
            'metrics' => [
                'rows_removed' => [],
                'files_removed' => 0,
                'filesystem_bytes_removed' => null,
                'tables_analyzed' => [],
                'tables_optimized' => [],
                'tables_skipped' => [],
                'task_failures' => [],
            ],
            'atomic_inflight' => null,
            'started_at' => now_sql(),
        ];
        $state['before'] = maintenance_center_before_snapshot($plan);
        maintenance_center_activity($state, 'info', maintenance_center_runtime_text('admin.maintenance_center.runtime.execution_started', 'Approved maintenance execution is starting.'), ['selected_tasks' => count($selected)]);
        maintenance_center_model_update_job($jobId, $actorId, [
            'state_json' => maintenance_center_json_encode($state),
            'progress_done' => 0,
            'progress_total' => max(1, $total),
            'progress_percent' => 0,
            'updated_at' => now_sql(),
        ]);
    } else {
        $execution = (array) ($state['execution'] ?? []);
        if (empty($execution['selected_tasks'])) {
            throw new RuntimeException('Paused Maintenance Center job has no persisted execution plan.');
        }
        maintenance_center_activity($state, 'info', maintenance_center_runtime_text('admin.maintenance_center.runtime.execution_resumed', 'Maintenance execution resumed.'));
        maintenance_center_model_update_job($jobId, $actorId, ['state_json' => maintenance_center_json_encode($state), 'updated_at' => now_sql()]);
    }

    if (!maintenance_center_model_claim_mutation_lock($jobId, $actorId, now_sql())) {
        throw new RuntimeException('Another central maintenance job owns the installation mutation lock.');
    }
    maintenance_center_log_lifecycle('info', 'maintenance_center.started', 'Maintenance Center execution started or resumed.', $jobId);
    return maintenance_center_job_status($jobId, $actorId);
}

/** Build the immutable before snapshot from analyzed plan evidence. */
function maintenance_center_before_snapshot(array $plan): array
{
    $inventoryTask = maintenance_center_plan_task($plan, 'database.inventory');
    $analysis = is_array($inventoryTask['analysis'] ?? null) ? $inventoryTask['analysis'] : [];
    return [
        'captured_at' => (string) ($plan['created_at'] ?? now_sql()),
        'database_bytes' => isset($analysis['total_bytes']) ? max(0, (int) $analysis['total_bytes']) : null,
        'database_reclaimable_estimate' => isset($analysis['reclaimable_bytes_estimate']) ? max(0, (int) $analysis['reclaimable_bytes_estimate']) : null,
        'estimated_affected_rows' => max(0, (int) ($plan['estimated_affected_rows'] ?? 0)),
    ];
}

/** Persist a cooperative pause between bounded operations while retaining the central lock. */
function maintenance_center_pause(int $jobId, int $actorId): array
{
    $row = maintenance_center_model_job($jobId);
    if (!is_array($row) || (int) ($row['actor_id'] ?? 0) !== $actorId) {
        throw new RuntimeException('Maintenance Center job was not found.');
    }
    if ((string) ($row['status'] ?? '') !== 'running') {
        return maintenance_center_job_status($jobId, $actorId);
    }
    maintenance_center_assert_transition('running', 'paused');
    $state = maintenance_center_json_decode((string) ($row['state_json'] ?? '{}'));
    maintenance_center_activity($state, 'warning', maintenance_center_runtime_text('admin.maintenance_center.runtime.paused', 'Maintenance paused between bounded operations.'));
    maintenance_center_model_update_job($jobId, $actorId, [
        'status' => 'paused', 'phase' => 'paused', 'state_json' => maintenance_center_json_encode($state), 'updated_at' => now_sql(),
    ]);
    return maintenance_center_job_status($jobId, $actorId);
}

/** Request cooperative cancellation. */
function maintenance_center_cancel(int $jobId, int $actorId): array
{
    $row = maintenance_center_model_job($jobId);
    if (!is_array($row) || (int) ($row['actor_id'] ?? 0) !== $actorId) {
        throw new RuntimeException('Maintenance Center job was not found.');
    }
    $status = (string) ($row['status'] ?? '');
    if (in_array($status, ['completed', 'failed', 'cancelled'], true)) {
        return maintenance_center_job_status($jobId, $actorId);
    }
    maintenance_center_model_request_cancel($jobId, $actorId, now_sql());
    if (in_array($status, ['ready', 'paused', 'analyzing'], true)) {
        $state = maintenance_center_json_decode((string) ($row['state_json'] ?? '{}'));
        maintenance_center_activity($state, 'warning', maintenance_center_runtime_text('admin.maintenance_center.runtime.cancelled_before_next', 'Maintenance cancelled before the next operation. Completed work was not rolled back.'));
        maintenance_center_model_finish_job($jobId, $actorId, 'cancelled', 'cancelled', maintenance_center_json_encode($state), (float) ($row['progress_percent'] ?? 0), now_sql(), 'cancelled', 'Cancelled by administrator.');
        maintenance_center_log_lifecycle('info', 'maintenance_center.cancelled', 'Maintenance Center job was cancelled.', $jobId);
    }
    return maintenance_center_job_status($jobId, $actorId);
}

/** Execute exactly one bounded registered task slice. */
function maintenance_center_execution_step(int $jobId, int $actorId): array
{
    if (!maintenance_center_model_acquire_step_lock($jobId)) {
        return maintenance_center_job_status($jobId, $actorId);
    }
    try {
        return maintenance_center_execution_step_unlocked($jobId, $actorId);
    } finally {
        maintenance_center_model_release_step_lock($jobId);
    }
}

/** Execute one maintenance slice while the per-job single-flight lock is held. */
function maintenance_center_execution_step_unlocked(int $jobId, int $actorId): array
{
    $row = maintenance_center_model_job($jobId);
    if (!is_array($row) || (int) ($row['actor_id'] ?? 0) !== $actorId) {
        throw new RuntimeException('Maintenance Center job was not found.');
    }
    if ((string) ($row['status'] ?? '') === 'paused') {
        return maintenance_center_job_status($jobId, $actorId);
    }
    if ((string) ($row['status'] ?? '') !== 'running') {
        return maintenance_center_job_status($jobId, $actorId);
    }
    $owner = maintenance_center_model_mutation_owner();
    if (!is_array($owner) || (int) ($owner['id'] ?? 0) !== $jobId) {
        return maintenance_center_fail_job($row, 'lock_lost', 'The central mutation claim is no longer owned by this job.');
    }
    $state = maintenance_center_json_decode((string) ($row['state_json'] ?? '{}'));
    if (!empty($row['cancel_requested'])) {
        maintenance_center_activity($state, 'warning', maintenance_center_runtime_text('admin.maintenance_center.runtime.cancelled_between', 'Cancellation took effect between bounded operations. Completed work remains committed.'));
        maintenance_center_model_finish_job($jobId, $actorId, 'cancelled', 'cancelled', maintenance_center_json_encode($state), (float) ($row['progress_percent'] ?? 0), now_sql(), 'cancelled', 'Cancelled between operations.');
        maintenance_center_log_lifecycle('info', 'maintenance_center.cancelled', 'Maintenance Center job was cancelled between operations.', $jobId);
        return maintenance_center_job_status($jobId, $actorId);
    }

    $plan = maintenance_center_json_decode(isset($row['plan_json']) ? (string) $row['plan_json'] : null);
    $freshness = maintenance_center_plan_freshness($row, $plan);
    if (empty($freshness['fresh'])) {
        return maintenance_center_fail_job($row, 'stale_plan_during_execution', 'Plan revision changed during maintenance; execution stopped fail-closed.');
    }
    $execution = (array) ($state['execution'] ?? []);

    // A persisted atomic cursor is advanced before ANALYZE/OPTIMIZE starts. If
    // the previous HTTP response vanished, never replay that same table blindly.
    $inflight = is_array($execution['atomic_inflight'] ?? null) ? $execution['atomic_inflight'] : null;
    if ($inflight !== null) {
        $table = (string) ($inflight['table'] ?? '');
        $operation = (string) ($inflight['operation'] ?? '');
        maintenance_center_warning($state, 'atomic_outcome_unknown.' . $operation . '.' . $table, maintenance_center_runtime_text('admin.maintenance_center.runtime.atomic_outcome_unknown', 'The previous {operation} operation on {table} has an unknown client-visible outcome and will not be repeated automatically.', ['operation' => $operation, 'table' => $table]));
        maintenance_center_activity($state, 'warning', maintenance_center_runtime_text('admin.maintenance_center.runtime.atomic_replay_skipped', 'Skipped automatic replay of an atomic database operation after an interrupted response.'), ['operation' => $operation, 'table' => $table]);
        $execution['atomic_inflight'] = null;
        $state['execution'] = $execution;
        maintenance_center_model_update_job($jobId, $actorId, ['state_json' => maintenance_center_json_encode($state), 'updated_at' => now_sql()]);
        $row = maintenance_center_model_job($jobId) ?? $row;
        $state = maintenance_center_json_decode((string) ($row['state_json'] ?? '{}'));
        $execution = (array) ($state['execution'] ?? []);
    }

    $selected = array_values(array_map('strval', (array) ($execution['selected_tasks'] ?? [])));
    $taskIndex = max(0, (int) ($execution['task_index'] ?? 0));
    if (!isset($selected[$taskIndex])) {
        return maintenance_center_complete_job($row, $state);
    }
    $key = $selected[$taskIndex];
    $registry = maintenance_center_task_registry();
    $task = $registry[$key] ?? null;
    $planTask = maintenance_center_plan_task($plan, $key);
    if (!is_array($task) || !is_array($planTask) || empty($planTask['available'])) {
        return maintenance_center_fail_job($row, 'registry_mismatch', 'Selected task is no longer available in the server registry.');
    }
    if (!maintenance_center_task_feature_available($task)) {
        if (!empty($task['required'])) {
            return maintenance_center_fail_job($row, 'required_feature_disabled', 'A required maintenance capability became unavailable.');
        }
        maintenance_center_warning($state, 'feature_disabled.' . str_replace('.', '_', $key), maintenance_center_runtime_text('admin.maintenance_center.runtime.feature_disabled', 'Task {task} was skipped because its capability is now disabled.', ['task' => $key]));
        $execution['task_states'][$key] = ['done' => true, 'skipped' => true, 'reason' => 'feature_disabled'];
        return maintenance_center_advance_completed_task($row, $state, $execution, $planTask, $key, maintenance_center_runtime_text('admin.maintenance_center.runtime.task_skipped_disabled', 'Skipped disabled task: {task}.', ['task' => $key]));
    }

    $executor = (string) ($task['executor'] ?? '');
    if ($executor === '' || !is_callable($executor)) {
        return maintenance_center_fail_job($row, 'executor_missing', 'Maintenance task executor is unavailable.');
    }
    $taskState = is_array($execution['task_states'][$key] ?? null) ? $execution['task_states'][$key] : [];
    try {
        $result = $executor([
            'job' => $row,
            'state' => $state,
            'execution' => $execution,
            'task_state' => $taskState,
            'task' => $task,
            'plan_task' => $planTask,
            'plan' => $plan,
        ]);
        if (!is_array($result)) {
            throw new RuntimeException('Maintenance task returned invalid progress.');
        }
    } catch (Throwable $exception) {
        if (empty($task['mutates']) && empty($task['required'])) {
            maintenance_center_warning($state, 'task_failed.' . str_replace('.', '_', $key), maintenance_center_runtime_text('admin.maintenance_center.runtime.optional_task_failed', 'Optional read-only task {task} failed and was skipped.', ['task' => $key]));
            $execution['task_states'][$key] = $taskState + ['done' => true, 'skipped' => true, 'reason' => 'task_failed'];
            return maintenance_center_advance_completed_task($row, $state, $execution, $planTask, $key, maintenance_center_runtime_text('admin.maintenance_center.runtime.task_skipped_failed', 'Skipped failed optional task: {task}.', ['task' => $key]));
        }
        // The executor may have persisted an atomic cursor before the failing call.
        // Reload so failure bookkeeping cannot overwrite that newer checkpoint with
        // the stale state captured at the beginning of this HTTP request.
        $failedRow = maintenance_center_model_job($jobId) ?? $row;
        return maintenance_center_fail_job($failedRow, 'task_failed', 'Maintenance task failed: ' . $key . '.', $key);
    }

    $execution['task_states'][$key] = is_array($result['task_state'] ?? null) ? $result['task_state'] : $taskState;
    if (!empty($result['clear_atomic_inflight'])) {
        $execution['atomic_inflight'] = null;
    }
    if (is_array($result['metrics'] ?? null)) {
        $execution['metrics'] = maintenance_center_merge_metrics((array) ($execution['metrics'] ?? []), $result['metrics']);
    }
    $state['execution'] = $execution;
    if (!empty($result['warning'])) {
        maintenance_center_warning($state, 'task_warning.' . str_replace('.', '_', $key) . '.' . max(0, (int) ($execution['task_index'] ?? 0)), (string) $result['warning']);
    }
    $activity = trim((string) ($result['activity'] ?? ''));
    if ($activity !== '') {
        maintenance_center_activity($state, !empty($result['warning']) ? 'warning' : 'info', $activity, ['task' => $key]);
    }
    $state['current_task'] = $key;
    $state['current_subtask'] = (string) ($result['current_subtask'] ?? '');

    if (!empty($result['done'])) {
        return maintenance_center_advance_completed_task($row, $state, $execution, $planTask, $key, $activity !== '' ? $activity : maintenance_center_runtime_text('admin.maintenance_center.runtime.task_completed', 'Completed task: {task}.', ['task' => $key]));
    }

    $weight = max(1, (int) ($planTask['work_units'] ?? 1));
    $fraction = max(0.0, min(0.99, (float) ($result['fraction'] ?? 0.0)));
    $completedUnits = max(0, (int) ($execution['completed_units'] ?? 0));
    $displayDone = $completedUnits + (int) floor($weight * $fraction);
    $total = max(1, (int) ($row['progress_total'] ?? 1));
    $percent = maintenance_center_monotonic_percent((float) ($row['progress_percent'] ?? 0), $displayDone, $total);
    maintenance_center_model_update_job($jobId, $actorId, [
        'phase' => $key,
        'state_json' => maintenance_center_json_encode($state),
        'progress_done' => $displayDone,
        'progress_percent' => $percent,
        'updated_at' => now_sql(),
    ]);
    return maintenance_center_job_status($jobId, $actorId);
}

/** Advance after a task completes and persist its entire analyzed weight. */
function maintenance_center_advance_completed_task(array $row, array $state, array $execution, array $planTask, string $key, string $activity): array
{
    $jobId = (int) $row['id'];
    $actorId = (int) $row['actor_id'];
    $weight = max(1, (int) ($planTask['work_units'] ?? 1));
    $execution['completed_units'] = max(0, (int) ($execution['completed_units'] ?? 0)) + $weight;
    $execution['task_index'] = max(0, (int) ($execution['task_index'] ?? 0)) + 1;
    $execution['task_states'][$key]['done'] = true;
    $execution['task_states'][$key]['completed_at'] = now_sql();
    $state['execution'] = $execution;
    $state['current_task'] = $key;
    $state['current_subtask'] = '';
    maintenance_center_activity($state, 'info', $activity, ['task' => $key]);
    $total = max(1, (int) ($row['progress_total'] ?? 1));
    $done = min($total, max(0, (int) $execution['completed_units']));
    $percent = maintenance_center_monotonic_percent((float) ($row['progress_percent'] ?? 0), $done, $total);
    maintenance_center_model_update_job($jobId, $actorId, [
        'phase' => $key,
        'state_json' => maintenance_center_json_encode($state),
        'progress_done' => $done,
        'progress_percent' => $percent,
        'updated_at' => now_sql(),
    ]);
    maintenance_center_log_lifecycle('info', 'maintenance_center.task_completed', 'Maintenance Center task completed.', $jobId, ['task' => $key]);

    $selected = array_values((array) ($execution['selected_tasks'] ?? []));
    if ((int) $execution['task_index'] >= count($selected)) {
        $freshRow = maintenance_center_model_job($jobId) ?? $row;
        $freshState = maintenance_center_json_decode((string) ($freshRow['state_json'] ?? '{}'));
        return maintenance_center_complete_job($freshRow, $freshState);
    }
    return maintenance_center_job_status($jobId, $actorId);
}

/** Merge task metrics without accepting arbitrary nested unbounded structures. */
function maintenance_center_merge_metrics(array $base, array $delta): array
{
    foreach ($delta as $key => $value) {
        if (is_int($value) || is_float($value)) {
            $base[$key] = (int) ($base[$key] ?? 0) + $value;
            continue;
        }
        if (is_array($value) && in_array((string) $key, ['rows_removed'], true)) {
            $existing = is_array($base[$key] ?? null) ? $base[$key] : [];
            foreach ($value as $subKey => $subValue) {
                if (is_numeric($subValue)) {
                    $existing[(string) $subKey] = (int) ($existing[(string) $subKey] ?? 0) + (int) $subValue;
                }
            }
            $base[$key] = $existing;
            continue;
        }
        if (is_array($value) && in_array((string) $key, ['tables_analyzed', 'tables_optimized', 'tables_skipped', 'task_failures'], true)) {
            $existing = is_array($base[$key] ?? null) ? $base[$key] : [];
            $base[$key] = array_slice(array_values(array_unique(array_merge($existing, array_map('strval', $value)))), -200);
            continue;
        }
        if ($value === null || is_string($value) || is_bool($value)) {
            $base[$key] = $value;
        }
    }
    return $base;
}

/** Recursively sum numeric cleanup counters for bounded security adapters. */
function maintenance_center_numeric_total(mixed $value): int
{
    if (is_int($value) || is_float($value)) {
        return max(0, (int) $value);
    }
    if (!is_array($value)) {
        return 0;
    }
    $total = 0;
    foreach ($value as $child) {
        $total += maintenance_center_numeric_total($child);
    }
    return $total;
}

/** Return true when a nested cleanup result likely exhausted a 1000-row owner batch. */
function maintenance_center_result_has_full_security_batch(mixed $value): bool
{
    if (is_int($value) || is_float($value)) {
        return (int) $value >= 1000;
    }
    if (!is_array($value)) {
        return false;
    }
    foreach ($value as $child) {
        if (maintenance_center_result_has_full_security_batch($child)) {
            return true;
        }
    }
    return false;
}

/** Persist an atomic DB table cursor before issuing the non-divisible statement. */
function maintenance_center_persist_atomic_attempt(array $context, string $operation, string $table, array $taskState): void
{
    $row = (array) $context['job'];
    $state = (array) $context['state'];
    $execution = (array) $context['execution'];
    $key = (string) ($context['plan_task']['key'] ?? $context['task']['key'] ?? '');
    if ($key === '') {
        // Registry entries are keyed outside metadata; callers set explicit task key below when needed.
        $key = strtolower($operation) === 'optimize' ? 'database.optimize' : 'database.analyze';
    }
    $execution['task_states'][$key] = $taskState;
    $execution['atomic_inflight'] = ['operation' => strtoupper($operation), 'table' => $table, 'started_at' => now_sql()];
    $state['execution'] = $execution;
    $state['current_task'] = $key;
    $state['current_subtask'] = $table;
    maintenance_center_model_update_job((int) $row['id'], (int) $row['actor_id'], [
        'phase' => $key,
        'state_json' => maintenance_center_json_encode($state),
        'updated_at' => now_sql(),
    ]);
}

/** Fail a job safely and release the durable central claim. */
function maintenance_center_fail_job(array $row, string $category, string $message, string $task = ''): array
{
    $jobId = (int) ($row['id'] ?? 0);
    $actorId = (int) ($row['actor_id'] ?? 0);
    $state = maintenance_center_json_decode((string) ($row['state_json'] ?? '{}'));
    $displayMessage = maintenance_center_runtime_text(
        'admin.maintenance_center.runtime.failure',
        'Maintenance stopped safely because {category} could not be completed or verified.',
        ['category' => $category]
    );
    maintenance_center_error($state, $category, $displayMessage);
    maintenance_center_activity($state, 'error', $displayMessage, $task !== '' ? ['task' => $task] : []);
    if ($task !== '' && isset($state['execution']['metrics']) && is_array($state['execution']['metrics'])) {
        $state['execution']['metrics'] = maintenance_center_merge_metrics($state['execution']['metrics'], ['task_failures' => [$task]]);
    }
    maintenance_center_model_finish_job($jobId, $actorId, 'failed', 'failed', maintenance_center_json_encode($state), (float) ($row['progress_percent'] ?? 0), now_sql(), $category, $displayMessage);
    maintenance_center_log_lifecycle('error', 'maintenance_center.failed', 'Maintenance Center job failed.', $jobId, ['category' => $category, 'task' => $task]);
    return maintenance_center_job_status($jobId, $actorId);
}

/** Finish a job after all selected tasks, preserving verification report evidence. */
function maintenance_center_complete_job(array $row, array $state): array
{
    $jobId = (int) ($row['id'] ?? 0);
    $actorId = (int) ($row['actor_id'] ?? 0);
    $execution = (array) ($state['execution'] ?? []);
    $selected = array_values((array) ($execution['selected_tasks'] ?? []));
    if (in_array('verify', $selected, true) && empty($execution['task_states']['verify']['done'])) {
        return maintenance_center_fail_job($row, 'verification_missing', 'Required completion verification did not finish.');
    }
    $state['completed_at'] = now_sql();
    maintenance_center_activity($state, 'info', maintenance_center_runtime_text('admin.maintenance_center.runtime.completed', 'Maintenance completed and central mutation claim released.'));
    maintenance_center_model_finish_job($jobId, $actorId, 'completed', 'completed', maintenance_center_json_encode($state), 100.0, now_sql());
    maintenance_center_log_lifecycle('info', 'maintenance_center.completed', 'Maintenance Center job completed.', $jobId, [
        'warnings' => count((array) ($state['warnings'] ?? [])),
        'errors' => count((array) ($state['errors'] ?? [])),
    ]);
    return maintenance_center_job_status($jobId, $actorId);
}

/** No-op execution boundary for server-side preflight rechecks. */
function maintenance_center_execute_preflight(array $context): array
{
    $row = (array) $context['job'];
    $owner = maintenance_center_model_mutation_owner();
    if (!is_array($owner) || (int) ($owner['id'] ?? 0) !== (int) ($row['id'] ?? 0)) {
        throw new RuntimeException('Central mutation claim is unavailable.');
    }
    if (maintenance_center_model_schema_revision() === 'unknown') {
        throw new RuntimeException('Schema revision cannot be verified.');
    }
    return ['done' => true, 'task_state' => ['done' => true], 'activity' => maintenance_center_runtime_text('admin.maintenance_center.runtime.preflight_passed', 'Preflight safety checks passed.')];
}

/** Reconcile stale Trash transitions in bounded slices. */
function maintenance_center_execute_trash_reconcile(array $context): array
{
    $taskState = (array) $context['task_state'];
    $attempts = max(0, (int) ($taskState['attempts'] ?? 0)) + 1;
    $result = reconcile_gallery_trash_transitional_entries(25);
    $summary = gallery_trash_summary();
    $remaining = max(0, (int) ($summary['transitional_count'] ?? 0));
    $done = $remaining === 0 || $attempts >= 10 || !empty($result['skipped']);
    $processed = maintenance_center_numeric_total($result);
    return [
        'done' => $done,
        'fraction' => $done ? 1.0 : min(0.9, $attempts / 10),
        'task_state' => ['attempts' => $attempts, 'last_result' => $result, 'remaining' => $remaining],
        'metrics' => ['rows_removed' => ['trash_reconciled' => (int) ($result['finalized'] ?? 0)]],
        'warning' => $attempts >= 10 && $remaining > 0 ? maintenance_center_runtime_text('admin.maintenance_center.runtime.trash_ambiguous', 'Some Trash transitions remain ambiguous and were not changed speculatively.') : '',
        'activity' => maintenance_center_runtime_text('admin.maintenance_center.runtime.trash_reconciled', 'Trash reconciliation slice processed {processed} outcome(s); {remaining} transitional entries remain.', ['processed' => $processed, 'remaining' => $remaining]),
    ];
}

/** Run one existing resumable telemetry maintenance slice. */
function maintenance_center_execute_telemetry(array $context): array
{
    $result = telemetry_run_maintenance(['force' => true, 'time_budget_seconds' => 4]);
    if (empty($result['ok']) && empty($result['skipped'])) {
        throw new RuntimeException('Telemetry maintenance slice failed.');
    }
    if (!empty($result['skipped']) && (string) ($result['reason'] ?? '') === 'busy') {
        return ['done' => false, 'fraction' => 0.0, 'task_state' => (array) $context['task_state'], 'activity' => maintenance_center_runtime_text('admin.maintenance_center.runtime.telemetry_busy', 'Telemetry maintenance is busy; the job will retry on the next browser step.')];
    }
    $deleted = array_sum(array_map('intval', (array) ($result['deleted'] ?? [])));
    $rolled = max(0, (int) ($result['rolled_up'] ?? 0));
    $taskState = (array) $context['task_state'];
    $taskState['slices'] = max(0, (int) ($taskState['slices'] ?? 0)) + 1;
    $taskState['deleted_rows'] = max(0, (int) ($taskState['deleted_rows'] ?? 0)) + $deleted;
    $taskState['rolled_up'] = max(0, (int) ($taskState['rolled_up'] ?? 0)) + $rolled;
    $done = empty($result['has_more']);
    return [
        'done' => $done,
        'fraction' => $done ? 1.0 : min(0.95, $taskState['slices'] / max(2, $taskState['slices'] + 1)),
        'task_state' => $taskState,
        'metrics' => ['rows_removed' => ['telemetry' => $deleted]],
        'activity' => maintenance_center_runtime_text('admin.maintenance_center.runtime.telemetry_slice', 'Telemetry slice: {deleted} expired row(s) removed, {rolled} rollup item(s) processed.', ['deleted' => $deleted, 'rolled' => $rolled]),
    ];
}

/** Run one existing one-day Admin log archive/retention cycle. */
function maintenance_center_execute_logs(array $context): array
{
    $result = admin_log_archive_maintenance_run(['force' => true, 'source' => 'maintenance_center']);
    if (empty($result['ok']) && empty($result['busy'])) {
        throw new RuntimeException('Admin log archival maintenance failed.');
    }
    $taskState = (array) $context['task_state'];
    $taskState['slices'] = max(0, (int) ($taskState['slices'] ?? 0)) + 1;
    $deleted = max(0, (int) ($result['deleted_rows'] ?? $result['deleted'] ?? 0));
    $taskState['deleted_rows'] = max(0, (int) ($taskState['deleted_rows'] ?? 0)) + $deleted;
    $done = empty($result['has_more']) && empty($result['busy']);
    return [
        'done' => $done,
        'fraction' => $done ? 1.0 : min(0.95, $taskState['slices'] / max(2, $taskState['slices'] + 1)),
        'task_state' => $taskState,
        'metrics' => ['rows_removed' => ['admin_logs' => $deleted]],
        'activity' => !empty($result['archive_created']) ? maintenance_center_runtime_text('admin.maintenance_center.runtime.log_archived', 'Archived one completed Admin log day and applied verified live retention.') : maintenance_center_runtime_text('admin.maintenance_center.runtime.log_retention', 'Admin log retention slice completed.'),
    ];
}

/** Purge only policy-expired Trash entries, never Empty Trash. */
function maintenance_center_execute_trash_retention(array $context): array
{
    $result = purge_expired_gallery_trash(20);
    $summary = gallery_trash_summary();
    $remaining = max(0, (int) ($summary['expired'] ?? 0));
    $purged = max(0, (int) ($result['purged'] ?? 0));
    return [
        'done' => $remaining === 0 || !empty($result['skipped']),
        'fraction' => $remaining === 0 ? 1.0 : 0.5,
        'task_state' => ['last_result' => $result, 'remaining' => $remaining],
        'metrics' => ['rows_removed' => ['trash' => $purged]],
        'warning' => (int) ($result['failed'] ?? 0) > 0 ? maintenance_center_runtime_text('admin.maintenance_center.runtime.trash_purge_partial', 'Some expired Trash entries could not be purged in this slice and remain for a later retry.') : '',
        'activity' => maintenance_center_runtime_text('admin.maintenance_center.runtime.trash_retention', 'Trash retention removed {purged} expired entries; {remaining} remain eligible.', ['purged' => $purged, 'remaining' => $remaining]),
    ];
}

/** Run one bounded viewer/security retention slice through existing owners. */
function maintenance_center_execute_security(array $context): array
{
    $result = viewer_security_maintenance_cleanup();
    if ((string) ($result['storage'] ?? '') === 'unavailable') {
        return ['done' => true, 'task_state' => ['skipped' => true, 'reason' => 'storage_unavailable'], 'warning' => maintenance_center_runtime_text('admin.maintenance_center.runtime.security_unavailable', 'Viewer security storage is unavailable; its cleanup was skipped fail-closed.'), 'activity' => maintenance_center_runtime_text('admin.maintenance_center.runtime.security_skipped', 'Security cleanup skipped because storage could not be verified.')];
    }
    $removed = maintenance_center_numeric_total($result);
    $taskState = (array) $context['task_state'];
    $slices = max(0, (int) ($taskState['slices'] ?? 0)) + 1;
    $hasMore = maintenance_center_result_has_full_security_batch($result) && $slices < 8;
    return [
        'done' => !$hasMore,
        'fraction' => $hasMore ? min(0.9, $slices / 8) : 1.0,
        'task_state' => ['slices' => $slices, 'last_result' => $result],
        'metrics' => ['rows_removed' => ['security' => $removed]],
        'warning' => $slices >= 8 && maintenance_center_result_has_full_security_batch($result) ? maintenance_center_runtime_text('admin.maintenance_center.runtime.security_cap', 'Security cleanup reached its central-run slice cap; remaining policy-expired rows can be handled by the next run.') : '',
        'activity' => maintenance_center_runtime_text('admin.maintenance_center.runtime.security_slice', 'Security/session cleanup removed or reconciled {count} item(s) in this bounded slice.', ['count' => $removed]),
    ];
}

/** Run cache/download cleanup owners. */
function maintenance_center_execute_downloads(array $context): array
{
    $manifest = cleanup_download_manifest_cache();
    $legacy = cleanup_legacy_download_artifact_cache();
    $zip = cleanup_expired_zip_cache();
    $files = max(0, (int) ($manifest['files_deleted'] ?? 0))
        + max(0, (int) ($manifest['partials_deleted'] ?? 0))
        + max(0, (int) ($legacy['artifacts_deleted'] ?? 0))
        + max(0, (int) ($legacy['partials_deleted'] ?? 0))
        + max(0, (int) ($zip['files'] ?? 0));
    $rows = max(0, (int) ($zip['rows'] ?? 0));
    $hasMore = !empty($manifest['scan_truncated']);
    $taskState = (array) $context['task_state'];
    $slices = max(0, (int) ($taskState['slices'] ?? 0)) + 1;
    if ($slices >= 10) {
        $hasMore = false;
    }
    return [
        'done' => !$hasMore,
        'fraction' => $hasMore ? min(0.9, $slices / 10) : 1.0,
        'task_state' => ['slices' => $slices, 'manifest' => $manifest, 'legacy' => $legacy, 'zip' => $zip],
        'metrics' => ['files_removed' => $files, 'rows_removed' => ['download_cache' => $rows]],
        'warning' => $slices >= 10 && !empty($manifest['scan_truncated']) ? maintenance_center_runtime_text('admin.maintenance_center.runtime.cache_cap', 'Manifest cache cleanup remained truncated after ten bounded slices; later maintenance can continue it.') : '',
        'activity' => maintenance_center_runtime_text('admin.maintenance_center.runtime.cache_slice', 'Download/cache cleanup removed {files} file artifact(s) and {rows} cache row(s).', ['files' => $files, 'rows' => $rows]),
    ];
}

/** Delete only strong-FK orphan thumbnail metadata through the existing owner. */
function maintenance_center_execute_thumbnail_metadata(array $context): array
{
    $limit = 250;
    $deleted = site_maintenance_delete_orphan_thumbnail_metadata($limit);
    $remaining = site_maintenance_thumbnail_orphan_analysis();
    if (empty($remaining['available'])) {
        throw new RuntimeException('Thumbnail metadata state could not be re-verified after the bounded cleanup slice.');
    }
    $remainingRows = max(0, (int) ($remaining['total'] ?? 0));
    $done = $remainingRows === 0;
    $taskState = (array) $context['task_state'];
    $taskState['deleted_rows'] = max(0, (int) ($taskState['deleted_rows'] ?? 0)) + $deleted;
    $taskState['remaining_rows'] = $remainingRows;
    return [
        'done' => $done,
        'fraction' => $done ? 1.0 : max(0.05, min(0.95, $taskState['deleted_rows'] / max(1, $taskState['deleted_rows'] + $remainingRows))),
        'task_state' => $taskState,
        'metrics' => ['rows_removed' => ['thumbnail_metadata' => $deleted]],
        'activity' => maintenance_center_runtime_text('admin.maintenance_center.runtime.thumbnail_metadata', 'Removed {deleted} safe orphan thumbnail metadata row(s); {remaining} remain eligible.', ['deleted' => $deleted, 'remaining' => $remainingRows]),
    ];
}

/** Run a bounded full thumbnail verification scan without regeneration. */
function maintenance_center_execute_media_deep(array $context): array
{
    $taskState = (array) $context['task_state'];
    $offset = max(0, (int) ($taskState['offset'] ?? 0));
    $batch = thumbnail_maintenance_check_batch(null, $offset, 150);
    $total = max(0, (int) ($batch['total'] ?? 0));
    $processed = max($offset, (int) ($batch['processed'] ?? $offset));
    $aggregate = is_array($taskState['aggregate'] ?? null) ? $taskState['aggregate'] : thumbnail_maintenance_empty_check_report(null);
    $aggregate = thumbnail_maintenance_merge_check_reports($aggregate, $batch);
    $done = !empty($batch['done']);
    if ($done) {
        $aggregate = thumbnail_maintenance_finalize_check_report($aggregate);
    }
    return [
        'done' => $done,
        'fraction' => $total > 0 ? min(1.0, $processed / $total) : 1.0,
        'task_state' => ['offset' => $processed, 'total' => $total, 'aggregate' => $aggregate],
        'warning' => $done && (int) ($aggregate['missing_variants'] ?? 0) > 0 ? maintenance_center_runtime_text('admin.maintenance_center.runtime.deep_media_findings', 'Deep verification found missing or stale thumbnail variants. Use the dedicated targeted repair workflow to regenerate them.') : '',
        'activity' => maintenance_center_runtime_text('admin.maintenance_center.runtime.deep_media_slice', 'Deep thumbnail verification checked {processed} / {total} image(s).', ['processed' => $processed, 'total' => $total]),
    ];
}

/** Continue one existing resumable safe database logical cleanup operation. */
function maintenance_center_execute_database_logical(array $context): array
{
    $taskState = (array) $context['task_state'];
    $restart = empty($taskState['started']);
    if ($restart) {
        $taskState['started'] = true;
        $taskState['previous_deleted_rows'] = 0;
        $state = (array) $context['state'];
        $execution = (array) $context['execution'];
        $execution['task_states']['database.logical'] = $taskState;
        $state['execution'] = $execution;
        maintenance_center_model_update_job((int) $context['job']['id'], (int) $context['job']['actor_id'], ['state_json' => maintenance_center_json_encode($state), 'updated_at' => now_sql()]);
    }
    $cleanup = database_maintenance_cleanup_step(false, MAINTENANCE_CENTER_DB_CLEANUP_BATCH, $restart);
    if (!empty($cleanup['failed'])) {
        throw new RuntimeException('Safe database logical cleanup failed.');
    }
    $currentDeleted = max(0, (int) ($cleanup['deleted_rows'] ?? 0));
    $previousDeleted = max(0, (int) ($taskState['previous_deleted_rows'] ?? 0));
    $delta = max(0, $currentDeleted - $previousDeleted);
    $taskState['previous_deleted_rows'] = $currentDeleted;
    $taskState['operation_id'] = (string) ($cleanup['operation_id'] ?? '');
    $ruleCount = max(1, count((array) ($cleanup['processed_rules'] ?? [])) + 1);
    $ruleIndex = max(0, (int) ($cleanup['rule_index'] ?? 0));
    return [
        'done' => !empty($cleanup['completed']),
        'fraction' => !empty($cleanup['completed']) ? 1.0 : min(0.95, $ruleIndex / $ruleCount),
        'task_state' => $taskState,
        'metrics' => ['rows_removed' => ['database_logical' => $delta]],
        'current_subtask' => isset($cleanup['processed_rules'][$ruleIndex]['table_name']) ? (string) $cleanup['processed_rules'][$ruleIndex]['table_name'] : '',
        'activity' => maintenance_center_runtime_text('admin.maintenance_center.runtime.database_logical', 'Safe database logical cleanup committed {batch} row deletion(s) in this batch; {total} total for the operation.', ['batch' => $delta, 'total' => $currentDeleted]),
    ];
}

/** Apply bounded retention to the Maintenance Center's own terminal job history. */
function maintenance_center_execute_history(array $context): array
{
    $cutoff = date('Y-m-d H:i:s', time() - MAINTENANCE_CENTER_HISTORY_RETENTION_DAYS * 86400);
    $deleted = maintenance_center_model_cleanup_history($cutoff, 100);
    return [
        'done' => $deleted < 100,
        'fraction' => $deleted < 100 ? 1.0 : 0.5,
        'task_state' => ['last_deleted' => $deleted, 'cutoff' => $cutoff],
        'metrics' => ['rows_removed' => ['maintenance_history' => $deleted]],
        'activity' => maintenance_center_runtime_text('admin.maintenance_center.runtime.history_retention', 'Maintenance Center history retention removed {count} old terminal job(s).', ['count' => $deleted]),
    ];
}

/** Refresh current compact DB inventory after logical cleanup and reclassify physical work. */
function maintenance_center_execute_database_inventory(array $context): array
{
    $inventory = database_maintenance_schema_inventory();
    $compact = maintenance_center_compact_database_inventory($inventory);
    $physical = maintenance_center_classify_physical_tables($compact);
    $taskState = ['compact_inventory' => $compact, 'physical' => $physical, 'captured_at' => now_sql()];
    return [
        'done' => true,
        'task_state' => $taskState,
        'activity' => maintenance_center_runtime_text('admin.maintenance_center.runtime.database_inventory', 'Refreshed database inventory for {count} table(s) after logical cleanup.', ['count' => (int) ($compact['table_count'] ?? 0)]),
        'warning' => !empty($physical['large_count']) ? maintenance_center_runtime_text('admin.maintenance_center.runtime.large_tables', '{count} large table(s) were classified outside normal web physical-maintenance scope.', ['count' => (int) $physical['large_count']]) : '',
    ];
}

/** Resolve runtime physical inventory from the completed inventory task. */
function maintenance_center_runtime_physical(array $context): array
{
    $inventoryState = (array) (($context['execution']['task_states']['database.inventory'] ?? []));
    return (array) ($inventoryState['physical'] ?? []);
}

/** Build runtime ANALYZE targets, excluding tables that will be optimized in the same run. */
function maintenance_center_analyze_targets_for_execution(array $context): array
{
    $physical = maintenance_center_runtime_physical($context);
    $full = !empty($context['execution']['options']['full_physical_optimization']);
    $optimize = $full ? array_keys((array) ($physical['eligible'] ?? [])) : array_keys((array) ($physical['recommended'] ?? []));
    $optimizeSet = array_fill_keys($optimize, true);
    return array_values(array_filter(array_keys((array) ($physical['analyze_candidates'] ?? [])), static fn (string $table): bool => !isset($optimizeSet[$table])));
}

/** Execute at most one ANALYZE TABLE statement in this HTTP step. */
function maintenance_center_execute_database_analyze(array $context): array
{
    return maintenance_center_execute_atomic_table_operation($context, 'ANALYZE');
}

/** Execute at most one OPTIMIZE TABLE statement in this HTTP step. */
function maintenance_center_execute_database_optimize(array $context): array
{
    return maintenance_center_execute_atomic_table_operation($context, 'OPTIMIZE');
}

/** Shared one-table atomic physical-operation adapter. */
function maintenance_center_execute_atomic_table_operation(array $context, string $operation): array
{
    $operation = strtoupper($operation);
    $taskState = (array) $context['task_state'];
    if (!isset($taskState['tables'])) {
        $physical = maintenance_center_runtime_physical($context);
        if ($operation === 'ANALYZE') {
            $tables = maintenance_center_analyze_targets_for_execution($context);
        } else {
            $tables = !empty($context['execution']['options']['full_physical_optimization'])
                ? array_keys((array) ($physical['eligible'] ?? []))
                : array_keys((array) ($physical['recommended'] ?? []));
        }
        $taskState['tables'] = array_values(array_map('strval', $tables));
        $taskState['cursor'] = 0;
        $taskState['outcomes'] = [];
    }
    $tables = array_values((array) ($taskState['tables'] ?? []));
    $cursor = max(0, (int) ($taskState['cursor'] ?? 0));
    if (!isset($tables[$cursor])) {
        return ['done' => true, 'task_state' => $taskState, 'activity' => maintenance_center_runtime_text('admin.maintenance_center.runtime.database_phase_completed', '{operation} TABLE phase completed for {count} selected table(s).', ['operation' => $operation, 'count' => count($tables)])];
    }
    $table = (string) $tables[$cursor];

    // Re-read and classify immediately before mutation. Client values never reach SQL.
    $currentInventory = maintenance_center_compact_database_inventory(database_maintenance_schema_inventory());
    $currentTable = is_array($currentInventory['tables'][$table] ?? null) ? $currentInventory['tables'][$table] : null;
    $allowed = $currentTable !== null && maintenance_center_physical_table_allowed($currentTable)
        && max(0, (int) ($currentTable['total_bytes'] ?? 0)) < MAINTENANCE_CENTER_LARGE_TABLE_BYTES;
    if (!$allowed) {
        $taskState['cursor'] = $cursor + 1;
        $taskState['outcomes'][$table] = ['status' => 'skipped', 'reason' => 'not_eligible_at_execution'];
        return [
            'done' => $taskState['cursor'] >= count($tables),
            'fraction' => count($tables) > 0 ? min(1.0, $taskState['cursor'] / count($tables)) : 1.0,
            'task_state' => $taskState,
            'metrics' => ['tables_skipped' => [$operation . ':' . $table]],
            'warning' => maintenance_center_runtime_text('admin.maintenance_center.runtime.database_table_skipped', 'Skipped {table} because its current physical-maintenance eligibility changed.', ['table' => $table]),
            'current_subtask' => $table,
            'activity' => maintenance_center_runtime_text('admin.maintenance_center.runtime.database_operation_skipped', 'Skipped {operation} TABLE for {table} after the immediate server-side safety recheck.', ['operation' => $operation, 'table' => $table]),
        ];
    }

    $beforeBytes = max(0, (int) ($currentTable['total_bytes'] ?? 0));
    // Advance and persist BEFORE the indivisible SQL statement. A lost response
    // therefore cannot make an automatic browser retry execute it a second time.
    $taskState['cursor'] = $cursor + 1;
    $taskState['attempted'][$table] = ['at' => now_sql(), 'before_bytes' => $beforeBytes];
    maintenance_center_persist_atomic_attempt($context, $operation, $table, $taskState);

    $result = $operation === 'ANALYZE'
        ? database_maintenance_analyze_tables([$table])
        : database_maintenance_optimize_tables([$table]);
    if (empty($result['ok']) || (int) ($result['failed_table_count'] ?? 0) > 0) {
        throw new RuntimeException($operation . ' TABLE failed for the selected server-side table.');
    }
    $afterInventory = maintenance_center_compact_database_inventory(database_maintenance_schema_inventory());
    $afterTable = is_array($afterInventory['tables'][$table] ?? null) ? $afterInventory['tables'][$table] : [];
    $afterBytes = isset($afterTable['total_bytes']) ? max(0, (int) $afterTable['total_bytes']) : null;
    $taskState['outcomes'][$table] = [
        'status' => 'ok',
        'operation' => $operation,
        'before_bytes' => $beforeBytes,
        'after_bytes' => $afterBytes,
        'reported_reclaimed_bytes' => $operation === 'OPTIMIZE' && $afterBytes !== null && $afterBytes < $beforeBytes ? ($beforeBytes - $afterBytes) : null,
    ];
    $done = $taskState['cursor'] >= count($tables);
    return [
        'done' => $done,
        'fraction' => count($tables) > 0 ? min(1.0, $taskState['cursor'] / count($tables)) : 1.0,
        'task_state' => $taskState,
        'clear_atomic_inflight' => true,
        'metrics' => $operation === 'ANALYZE' ? ['tables_analyzed' => [$table]] : ['tables_optimized' => [$table]],
        'current_subtask' => $table,
        'activity' => $afterBytes !== null
            ? maintenance_center_runtime_text('admin.maintenance_center.runtime.database_operation_completed_bytes', '{operation} TABLE completed for {table} ({before} -> {after} bytes reported).', ['operation' => $operation, 'table' => $table, 'before' => $beforeBytes, 'after' => $afterBytes])
            : maintenance_center_runtime_text('admin.maintenance_center.runtime.database_operation_completed', '{operation} TABLE completed for {table}.', ['operation' => $operation, 'table' => $table]),
    ];
}

/** Final read-only postcondition verification and before/after report builder. */
function maintenance_center_execute_verify(array $context): array
{
    $row = (array) $context['job'];
    $state = (array) $context['state'];
    $execution = (array) $context['execution'];
    $owner = maintenance_center_model_mutation_owner();
    if (!is_array($owner) || (int) ($owner['id'] ?? 0) !== (int) ($row['id'] ?? 0)) {
        throw new RuntimeException('Central mutation ownership could not be verified.');
    }
    if (maintenance_center_model_schema_revision() !== (string) ($row['schema_revision'] ?? '')) {
        throw new RuntimeException('Schema revision changed during maintenance.');
    }
    $afterInventory = maintenance_center_compact_database_inventory(database_maintenance_schema_inventory());
    $before = (array) ($state['before'] ?? []);
    $metrics = (array) ($execution['metrics'] ?? []);
    $beforeBytes = isset($before['database_bytes']) && is_int($before['database_bytes']) ? $before['database_bytes'] : (is_numeric($before['database_bytes'] ?? null) ? (int) $before['database_bytes'] : null);
    $afterBytes = isset($afterInventory['total_bytes']) ? max(0, (int) $afterInventory['total_bytes']) : null;
    $physicalDelta = $beforeBytes !== null && $afterBytes !== null && $afterBytes < $beforeBytes ? ($beforeBytes - $afterBytes) : null;
    $report = [
        'started_at' => (string) ($execution['started_at'] ?? ''),
        'verified_at' => now_sql(),
        'database' => [
            'before_bytes' => $beforeBytes,
            'after_bytes' => $afterBytes,
            'reported_reclaimed_bytes' => $physicalDelta,
            'reclaim_note' => $physicalDelta !== null
                ? 'Information-schema allocated bytes were lower after maintenance; this is the hosting-visible physical delta.'
                : 'No hosting-visible physical byte reduction is claimed.',
        ],
        'rows_removed' => (array) ($metrics['rows_removed'] ?? []),
        'files_removed' => max(0, (int) ($metrics['files_removed'] ?? 0)),
        'filesystem_bytes_removed' => $metrics['filesystem_bytes_removed'] ?? null,
        'tables_analyzed' => array_values((array) ($metrics['tables_analyzed'] ?? [])),
        'tables_optimized' => array_values((array) ($metrics['tables_optimized'] ?? [])),
        'tasks_skipped' => array_values((array) ($metrics['tables_skipped'] ?? [])),
        'warnings' => count((array) ($state['warnings'] ?? [])),
        'errors' => count((array) ($state['errors'] ?? [])),
    ];
    $taskState = ['done' => true, 'report' => $report];
    // The outer executor persists the verified report inside this task state in
    // the same checkpoint as task completion, avoiding an intermediate state write.
    return ['done' => true, 'task_state' => $taskState, 'activity' => maintenance_center_runtime_text('admin.maintenance_center.runtime.verify_completed', 'Postcondition verification completed; before/after report is ready.')];
}
