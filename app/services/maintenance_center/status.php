<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/maintenance_center/status.php
 * Module Type: Service Part
 *
 * Purpose:
 *   Prepares Maintenance Center status, dashboard, and browser-facing view models.
 *
 * Responsibilities:
 *   - Decode persisted jobs into bounded presentation data
 *   - Translate registered task labels without moving policy into views
 *   - Summarize unfinished and latest completed central maintenance state
 *   - Emit privacy-safe central lifecycle log events
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Loaded only by app/services/maintenance_center.php.
 *
 * Last Updated:
 *   2026-09-20
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use function Gallery\Models\maintenance_center_model_job;
use function Gallery\Models\maintenance_center_model_latest_actor_job;
use function Gallery\Models\maintenance_center_model_latest_completed_job;
use function Gallery\Models\maintenance_center_model_latest_unfinished_actor_job;
use function Gallery\Models\maintenance_center_model_mutation_owner;
use function Gallery\Models\maintenance_center_model_schema_ready;

/** Log one bounded central lifecycle event without raw exception/request payloads. */
function maintenance_center_log_lifecycle(string $level, string $eventKey, string $message, int $jobId, array $context = []): void
{
    if (!function_exists(__NAMESPACE__ . '\\admin_log_event')) {
        return;
    }
    $safe = ['job_id' => max(0, $jobId)];
    foreach (array_slice($context, 0, 10, true) as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $safe[(string) $key] = is_string($value) ? mb_substr($value, 0, 180) : $value;
        }
    }
    try {
        admin_log_event($level, $eventKey, $message, $safe, ['category' => 'maintenance', 'severity' => $level === 'error' ? 'error' : ($level === 'warning' ? 'warning' : 'notice')]);
    } catch (Throwable) {
        // Logging must never change an already persisted maintenance outcome.
    }
}

/** Return a translated browser-safe plan without changing persisted immutable plan data. */
function maintenance_center_present_plan(array $plan): array
{
    $presented = $plan;
    $tasks = [];
    foreach ((array) ($plan['tasks'] ?? []) as $task) {
        if (!is_array($task)) {
            continue;
        }
        $fallback = (string) ($task['label'] ?? $task['key'] ?? 'Maintenance task');
        $key = (string) ($task['label_key'] ?? '');
        $task['display_label'] = $key !== '' ? t($key, $fallback) : $fallback;
        $tasks[] = $task;
    }
    $presented['tasks'] = $tasks;
    return $presented;
}

/** Extract completed report evidence from persisted state. */
function maintenance_center_report_from_state(array $state): array
{
    if (is_array($state['report'] ?? null)) {
        return $state['report'];
    }
    $report = $state['execution']['task_states']['verify']['report'] ?? null;
    return is_array($report) ? $report : [];
}

/** Return one actor-authorized job status for page/AJAX presentation. */
function maintenance_center_job_status(int $jobId, int $actorId): array
{
    $row = maintenance_center_model_job($jobId);
    if (!is_array($row) || (int) ($row['actor_id'] ?? 0) !== $actorId) {
        throw new RuntimeException('Maintenance Center job was not found.');
    }
    return maintenance_center_present_job($row);
}

/** Convert one persisted row to a bounded presentation model. */
function maintenance_center_present_job(array $row): array
{
    $state = maintenance_center_json_decode(isset($row['state_json']) ? (string) $row['state_json'] : null);
    $plan = maintenance_center_json_decode(isset($row['plan_json']) ? (string) $row['plan_json'] : null);
    $execution = (array) ($state['execution'] ?? []);
    $selected = array_values(array_map('strval', (array) ($execution['selected_tasks'] ?? [])));
    $taskIndex = max(0, (int) ($execution['task_index'] ?? 0));
    $currentTask = (string) ($state['current_task'] ?? ($selected[$taskIndex] ?? ''));
    $freshness = $plan !== [] && in_array((string) ($row['status'] ?? ''), ['ready', 'running', 'paused'], true)
        ? maintenance_center_plan_freshness($row, $plan)
        : ['fresh' => true, 'checks' => []];
    $startedAt = (string) ($row['started_at'] ?? '');
    $finishedAt = (string) ($row['finished_at'] ?? '');
    $duration = null;
    if ($startedAt !== '') {
        $start = strtotime($startedAt);
        $end = $finishedAt !== '' ? strtotime($finishedAt) : time();
        if ($start !== false && $end !== false && $end >= $start) {
            $duration = $end - $start;
        }
    }

    return [
        'id' => max(0, (int) ($row['id'] ?? 0)),
        'actor_id' => max(0, (int) ($row['actor_id'] ?? 0)),
        'status' => (string) ($row['status'] ?? ''),
        'mode' => (string) ($row['mode'] ?? 'standard'),
        'phase' => (string) ($row['phase'] ?? ''),
        'progress_done' => max(0, (int) ($row['progress_done'] ?? 0)),
        'progress_total' => max(0, (int) ($row['progress_total'] ?? 0)),
        'progress_percent' => max(0.0, min(100.0, (float) ($row['progress_percent'] ?? 0))),
        'cancel_requested' => !empty($row['cancel_requested']),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'started_at' => $startedAt,
        'updated_at' => (string) ($row['updated_at'] ?? ''),
        'finished_at' => $finishedAt,
        'duration_seconds' => $duration,
        'error_category' => (string) ($row['error_category'] ?? ''),
        'error_message' => (string) ($row['error_message'] ?? ''),
        'app_version' => (string) ($row['app_version'] ?? ''),
        'schema_revision' => (string) ($row['schema_revision'] ?? ''),
        'registry_revision' => (string) ($row['registry_revision'] ?? ''),
        'plan_hash' => (string) ($row['plan_hash'] ?? ''),
        'plan_freshness' => $freshness,
        'plan' => $plan !== [] ? maintenance_center_present_plan($plan) : [],
        'current_task' => $currentTask,
        'current_subtask' => (string) ($state['current_subtask'] ?? ''),
        'selected_tasks' => $selected,
        'task_index' => $taskIndex,
        'activity' => array_values(array_slice((array) ($state['activity'] ?? []), -40)),
        'warnings' => array_values((array) ($state['warnings'] ?? [])),
        'errors' => array_values((array) ($state['errors'] ?? [])),
        'report' => maintenance_center_report_from_state($state),
    ];
}

/** Return compact installation-wide state for the Dashboard card. */
function maintenance_center_dashboard_status(?int $actorId = null): array
{
    $schemaReady = maintenance_center_model_schema_ready();
    if (!$schemaReady) {
        return [
            'available' => false,
            'schema_ready' => false,
            'active' => false,
            'resumable' => false,
            'active_job_id' => 0,
            'active_status' => '',
            'active_progress_percent' => 0.0,
            'last_completed_at' => '',
            'last_completed_job_id' => 0,
        ];
    }
    $owner = maintenance_center_model_mutation_owner();
    $unfinished = $actorId !== null && $actorId > 0 ? maintenance_center_model_latest_unfinished_actor_job($actorId) : null;
    $lastCompleted = maintenance_center_model_latest_completed_job();
    $candidate = is_array($owner) ? $owner : $unfinished;
    return [
        'available' => true,
        'schema_ready' => true,
        'active' => is_array($owner),
        'resumable' => is_array($unfinished) && in_array((string) ($unfinished['status'] ?? ''), ['analyzing', 'ready', 'running', 'paused'], true),
        'active_job_id' => is_array($candidate) ? max(0, (int) ($candidate['id'] ?? 0)) : 0,
        'active_status' => is_array($candidate) ? (string) ($candidate['status'] ?? '') : '',
        'active_progress_percent' => is_array($candidate) ? max(0.0, min(100.0, (float) ($candidate['progress_percent'] ?? 0))) : 0.0,
        'last_completed_at' => is_array($lastCompleted) ? (string) ($lastCompleted['finished_at'] ?? '') : '',
        'last_completed_job_id' => is_array($lastCompleted) ? max(0, (int) ($lastCompleted['id'] ?? 0)) : 0,
    ];
}

/** Return the complete dedicated-page model for one administrator. */
function maintenance_center_page_model(int $actorId): array
{
    $dashboard = maintenance_center_dashboard_status($actorId);
    $owner = maintenance_center_model_mutation_owner();
    $blockedByOtherJob = is_array($owner) && (int) ($owner['actor_id'] ?? 0) !== $actorId;
    $latest = maintenance_center_model_latest_actor_job($actorId);
    $job = !$blockedByOtherJob && is_array($latest) ? maintenance_center_present_job($latest) : null;
    $installationBlocker = $blockedByOtherJob ? [
        'job_id' => max(0, (int) ($owner['id'] ?? 0)),
        'status' => (string) ($owner['status'] ?? ''),
        'phase' => (string) ($owner['phase'] ?? ''),
        'progress_percent' => max(0.0, min(100.0, (float) ($owner['progress_percent'] ?? 0))),
        'updated_at' => (string) ($owner['updated_at'] ?? ''),
    ] : null;
    return [
        'available' => !empty($dashboard['available']),
        'schema_ready' => !empty($dashboard['schema_ready']),
        'dashboard' => $dashboard,
        'job' => $job,
        'blocked_by_other_job' => $blockedByOtherJob,
        'installation_blocker' => $installationBlocker,
        'registry_revision' => MAINTENANCE_CENTER_REGISTRY_REVISION,
        'history_retention_days' => MAINTENANCE_CENTER_HISTORY_RETENTION_DAYS,
    ];
}
