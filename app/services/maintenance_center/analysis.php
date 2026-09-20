<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/maintenance_center/analysis.php
 * Module Type: Service Part
 *
 * Purpose:
 *   Builds immutable, reviewable Maintenance Center plans using read-only analysis.
 *
 * Responsibilities:
 *   - Start and advance one persisted analysis job in bounded steps
 *   - Adapt existing maintenance owners to read-only plan summaries
 *   - Classify database physical work without executing ANALYZE/OPTIMIZE
 *   - Bind plans to application, schema, and registry revisions
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Loaded only by app/services/maintenance_center.php.
 *   - Analysis must never perform a mutation or write a subsystem report/cache.
 *
 * Last Updated:
 *   2026-09-20
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use function Gallery\Core\now_sql;
use function Gallery\Core\pending_migrations_exist;
use function Gallery\Models\maintenance_center_model_create_job;
use function Gallery\Models\maintenance_center_model_finish_job;
use function Gallery\Models\maintenance_center_model_job;
use function Gallery\Models\maintenance_center_model_acquire_step_lock;
use function Gallery\Models\maintenance_center_model_acquire_analysis_start_lock;
use function Gallery\Models\maintenance_center_model_release_step_lock;
use function Gallery\Models\maintenance_center_model_release_analysis_start_lock;
use function Gallery\Models\maintenance_center_model_latest_unfinished_actor_job;
use function Gallery\Models\maintenance_center_model_mutation_owner;
use function Gallery\Models\maintenance_center_model_schema_ready;
use function Gallery\Models\maintenance_center_model_schema_revision;
use function Gallery\Models\maintenance_center_model_update_job;

/** Return whether a registry task's optional feature requirement is currently available. */
function maintenance_center_task_feature_available(array $task): bool
{
    $feature = trim((string) ($task['feature_requirement'] ?? ''));
    if ($feature === '') {
        return true;
    }
    return function_exists(__NAMESPACE__ . '\\feature_capability_effective_enabled')
        && feature_capability_effective_enabled($feature);
}

/** Return a compact read-only inventory suitable for bounded plan/state JSON. */
function maintenance_center_compact_database_inventory(array $inventory): array
{
    $tables = [];
    $totalBytes = 0;
    $reclaimableBytes = 0;
    foreach ((array) ($inventory['tables'] ?? []) as $name => $table) {
        if (!is_array($table)) {
            continue;
        }
        $record = [
            'table_name' => (string) $name,
            'engine' => (string) ($table['engine'] ?? ''),
            'estimated_rows' => max(0, (int) ($table['estimated_rows'] ?? 0)),
            'data_bytes' => max(0, (int) ($table['data_bytes'] ?? 0)),
            'index_bytes' => max(0, (int) ($table['index_bytes'] ?? 0)),
            'total_bytes' => max(0, (int) ($table['total_bytes'] ?? 0)),
            'reclaimable_bytes_estimate' => max(0, (int) ($table['reclaimable_bytes_estimate'] ?? 0)),
            'category' => (string) ($table['category'] ?? ''),
            'cleanup_mode' => (string) ($table['policy']['cleanup_mode'] ?? 'disabled'),
            'physical_optimization' => (string) ($table['policy']['physical_optimization'] ?? 'manual'),
        ];
        $totalBytes += $record['total_bytes'];
        $reclaimableBytes += $record['reclaimable_bytes_estimate'];
        $tables[(string) $name] = $record;
    }
    ksort($tables, SORT_NATURAL | SORT_FLAG_CASE);
    return [
        'table_count' => count($tables),
        'total_bytes' => $totalBytes,
        'reclaimable_bytes_estimate' => $reclaimableBytes,
        'tables' => $tables,
    ];
}

/** Return whether one compact inventory row is eligible for central physical maintenance. */
function maintenance_center_physical_table_allowed(array $table): bool
{
    $name = trim((string) ($table['table_name'] ?? ''));
    if ($name === '' || preg_match('/^[A-Za-z0-9_]+$/D', $name) !== 1) {
        return false;
    }
    if ((string) ($table['category'] ?? '') === 'unknown/unclassified') {
        return false;
    }
    if ((string) ($table['physical_optimization'] ?? 'manual') === 'disabled') {
        return false;
    }
    return in_array(strtoupper((string) ($table['engine'] ?? '')), ['INNODB', 'MYISAM', 'ARIA'], true);
}

/**
 * Classify compact table inventory into bounded central physical-maintenance sets.
 *
 * @return array<string,mixed> Eligible/recommended/large/unknown table sets and work units.
 */
function maintenance_center_classify_physical_tables(array $compactInventory): array
{
    $eligible = [];
    $recommended = [];
    $large = [];
    $blocked = [];
    $analyzeCandidates = [];
    $units = 0;
    foreach ((array) ($compactInventory['tables'] ?? []) as $name => $table) {
        if (!is_array($table)) {
            continue;
        }
        $table['table_name'] = (string) $name;
        if (!maintenance_center_physical_table_allowed($table)) {
            $blocked[] = (string) $name;
            continue;
        }
        $bytes = max(0, (int) ($table['total_bytes'] ?? 0));
        $free = max(0, (int) ($table['reclaimable_bytes_estimate'] ?? 0));
        $isLarge = $bytes >= MAINTENANCE_CENTER_LARGE_TABLE_BYTES;
        $entry = $table + [
            'large' => $isLarge,
            'recommended' => false,
            'work_units' => max(1, min(300, (int) ceil(max(1, $bytes) / 1048576))),
        ];
        if ($isLarge) {
            $large[(string) $name] = $entry;
            continue;
        }
        $eligible[(string) $name] = $entry;
        $ratioSignal = $bytes > 0 && ($free / $bytes) >= 0.05;
        if ($free >= MAINTENANCE_CENTER_RECOMMEND_RECLAIM_BYTES && ($ratioSignal || $free >= 8388608)) {
            $entry['recommended'] = true;
            $recommended[(string) $name] = $entry;
            $eligible[(string) $name] = $entry;
            $units += $entry['work_units'];
        } elseif ((int) ($table['estimated_rows'] ?? 0) > 0) {
            $analyzeCandidates[(string) $name] = $entry;
        }
    }
    return [
        'eligible' => $eligible,
        'eligible_count' => count($eligible),
        'recommended' => $recommended,
        'recommended_count' => count($recommended),
        'analyze_candidates' => $analyzeCandidates,
        'analyze_candidate_count' => count($analyzeCandidates),
        'large' => $large,
        'large_count' => count($large),
        'blocked' => $blocked,
        'blocked_count' => count($blocked),
        'recommended_reclaimable_bytes' => array_sum(array_map(static fn (array $table): int => (int) ($table['reclaimable_bytes_estimate'] ?? 0), $recommended)),
        'recommended_work_units' => max(count($recommended), $units),
    ];
}

/** Start a new read-only analysis job, serializing actor-level replacement/create admission. */
function maintenance_center_start_analysis(int $actorId): array
{
    if ($actorId <= 0) {
        throw new RuntimeException('Maintenance Center requires an authenticated administrator.');
    }
    if (!maintenance_center_model_acquire_analysis_start_lock($actorId)) {
        throw new RuntimeException('A Maintenance Center analysis request is already starting for this administrator.');
    }
    try {
        return maintenance_center_start_analysis_unlocked($actorId);
    } finally {
        maintenance_center_model_release_analysis_start_lock($actorId);
    }
}

/** Create/replace one read-only analysis job while the actor start lock is held. */
function maintenance_center_start_analysis_unlocked(int $actorId): array
{
    if ($actorId <= 0) {
        throw new RuntimeException('Maintenance Center requires an authenticated administrator.');
    }
    if (!maintenance_center_model_schema_ready()) {
        throw new RuntimeException('Maintenance Center storage is not installed. Apply pending database migrations first.');
    }
    $owner = maintenance_center_model_mutation_owner();
    if (is_array($owner)) {
        throw new RuntimeException('A central maintenance job is already running or paused. Resume or cancel it first.');
    }

    $existing = maintenance_center_model_latest_unfinished_actor_job($actorId);
    if (is_array($existing)) {
        $status = (string) ($existing['status'] ?? '');
        if (in_array($status, ['running', 'paused'], true)) {
            throw new RuntimeException('Your existing maintenance job must be resumed or cancelled first.');
        }
        if (in_array($status, ['analyzing', 'ready'], true)) {
            $state = maintenance_center_json_decode((string) ($existing['state_json'] ?? '{}'));
            maintenance_center_activity($state, 'warning', maintenance_center_runtime_text('admin.maintenance_center.runtime.analysis_superseded', 'Analysis was superseded by a newer analysis request.'));
            maintenance_center_model_finish_job((int) $existing['id'], $actorId, 'cancelled', 'superseded', maintenance_center_json_encode($state), (float) ($existing['progress_percent'] ?? 0), now_sql(), 'superseded', 'Replaced by a newer analysis.');
        }
    }

    $order = maintenance_center_registry_order();
    $now = now_sql();
    $state = [
        'analysis_index' => 0,
        'analysis_order' => $order,
        'analysis_results' => [],
        'activity' => [],
        'warnings' => [],
        'errors' => [],
        'metrics' => [],
    ];
    maintenance_center_activity($state, 'info', maintenance_center_runtime_text('admin.maintenance_center.runtime.analysis_started', 'Read-only maintenance analysis started.'));
    $jobId = maintenance_center_model_create_job([
        'actor_id' => $actorId,
        'status' => 'analyzing',
        'mode' => 'standard',
        'phase' => 'analyze',
        'state_json' => maintenance_center_json_encode($state),
        'app_version' => maintenance_center_app_version(),
        'schema_revision' => maintenance_center_model_schema_revision(),
        'registry_revision' => MAINTENANCE_CENTER_REGISTRY_REVISION,
        'progress_done' => 0,
        'progress_total' => count($order),
        'progress_percent' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    maintenance_center_log_lifecycle('info', 'maintenance_center.analysis_started', 'Maintenance Center read-only analysis started.', $jobId, ['task_count' => count($order)]);
    return maintenance_center_job_status($jobId, $actorId);
}

/** Run exactly one read-only registry analysis task. */
function maintenance_center_analysis_step(int $jobId, int $actorId): array
{
    if (!maintenance_center_model_acquire_step_lock($jobId)) {
        return maintenance_center_job_status($jobId, $actorId);
    }
    try {
        return maintenance_center_analysis_step_unlocked($jobId, $actorId);
    } finally {
        maintenance_center_model_release_step_lock($jobId);
    }
}

/** Run one analysis task while the per-job single-flight lock is held. */
function maintenance_center_analysis_step_unlocked(int $jobId, int $actorId): array
{
    $row = maintenance_center_model_job($jobId);
    if (!is_array($row) || (int) ($row['actor_id'] ?? 0) !== $actorId) {
        throw new RuntimeException('Maintenance Center job was not found.');
    }
    if ((string) ($row['status'] ?? '') !== 'analyzing') {
        return maintenance_center_job_status($jobId, $actorId);
    }
    $state = maintenance_center_json_decode((string) ($row['state_json'] ?? '{}'));
    if (!empty($row['cancel_requested'])) {
        maintenance_center_activity($state, 'warning', maintenance_center_runtime_text('admin.maintenance_center.runtime.analysis_cancelled', 'Analysis cancelled before the next bounded step.'));
        maintenance_center_model_finish_job($jobId, $actorId, 'cancelled', 'cancelled', maintenance_center_json_encode($state), (float) ($row['progress_percent'] ?? 0), now_sql(), 'cancelled', 'Cancelled by administrator.');
        maintenance_center_log_lifecycle('info', 'maintenance_center.cancelled', 'Maintenance Center analysis was cancelled.', $jobId);
        return maintenance_center_job_status($jobId, $actorId);
    }

    $registry = maintenance_center_task_registry();
    $order = is_array($state['analysis_order'] ?? null) ? $state['analysis_order'] : maintenance_center_registry_order($registry);
    $index = max(0, (int) ($state['analysis_index'] ?? 0));
    if (!isset($order[$index])) {
        return maintenance_center_finalize_analysis($row, $state, $registry, $order);
    }

    $key = (string) $order[$index];
    $task = $registry[$key] ?? null;
    if (!is_array($task)) {
        throw new RuntimeException('Maintenance Center registry changed during analysis.');
    }
    $analyzer = (string) ($task['analyzer'] ?? '');
    if ($analyzer === '' || !is_callable($analyzer)) {
        throw new RuntimeException('Maintenance Center analyzer is unavailable for ' . $key . '.');
    }

    $featureAvailable = maintenance_center_task_feature_available($task);
    try {
        $result = $featureAvailable
            ? $analyzer(['job' => $row, 'state' => $state, 'results' => (array) ($state['analysis_results'] ?? []), 'task' => $task])
            : ['available' => false, 'reason' => 'feature_disabled', 'has_work' => false, 'work_units' => 0];
        if (!is_array($result)) {
            throw new RuntimeException('Maintenance Center analyzer returned invalid data.');
        }
    } catch (Throwable $exception) {
        $result = ['available' => false, 'reason' => 'analysis_failed', 'has_work' => false, 'work_units' => 0, 'warning' => maintenance_center_runtime_text('admin.maintenance_center.runtime.analysis_subsystem_failed', 'Analysis failed for this subsystem.')];
        maintenance_center_warning($state, 'analysis.' . str_replace('.', '_', $key), maintenance_center_runtime_text('admin.maintenance_center.runtime.analysis_task_failed', 'Analysis failed for {task}; the task will not be runnable.', ['task' => $key]));
        if (!empty($task['required'])) {
            maintenance_center_error($state, 'analysis_required_failed', maintenance_center_runtime_text('admin.maintenance_center.runtime.analysis_required_failed', 'Required analysis failed for {task}.', ['task' => $key]));
            maintenance_center_model_finish_job($jobId, $actorId, 'failed', 'analysis_failed', maintenance_center_json_encode($state), (float) ($row['progress_percent'] ?? 0), now_sql(), 'analysis_failed', maintenance_center_runtime_text('admin.maintenance_center.runtime.analysis_required_failed_generic', 'Required read-only analysis failed.'));
            maintenance_center_log_lifecycle('error', 'maintenance_center.failed', 'Maintenance Center analysis failed.', $jobId, ['task' => $key]);
            return maintenance_center_job_status($jobId, $actorId);
        }
    }

    $state['analysis_results'][$key] = $result;
    $state['analysis_index'] = $index + 1;
    maintenance_center_activity($state, 'info', maintenance_center_runtime_text('admin.maintenance_center.runtime.analyzed_task', 'Analyzed: {task}.', ['task' => $key]), ['available' => !empty($result['available']), 'work_units' => (int) ($result['work_units'] ?? 0)]);
    $done = $index + 1;
    $total = max(1, count($order));
    $percent = maintenance_center_monotonic_percent((float) ($row['progress_percent'] ?? 0), $done, $total);
    maintenance_center_model_update_job($jobId, $actorId, [
        'state_json' => maintenance_center_json_encode($state),
        'progress_done' => $done,
        'progress_total' => $total,
        'progress_percent' => $percent,
        'updated_at' => now_sql(),
    ]);

    if ($done >= $total) {
        $row = maintenance_center_model_job($jobId) ?? $row;
        return maintenance_center_finalize_analysis($row, $state, $registry, $order);
    }
    return maintenance_center_job_status($jobId, $actorId);
}

/** Finalize accumulated read-only analysis into an immutable review plan. */
function maintenance_center_finalize_analysis(array $row, array $state, array $registry, array $order): array
{
    $jobId = (int) ($row['id'] ?? 0);
    $actorId = (int) ($row['actor_id'] ?? 0);
    $results = (array) ($state['analysis_results'] ?? []);
    $tasks = [];
    $estimatedRows = 0;
    $estimatedBytes = 0;
    $selectedUnits = 0;
    foreach ($order as $key) {
        $task = $registry[$key];
        $analysis = is_array($results[$key] ?? null) ? $results[$key] : ['available' => false, 'reason' => 'not_analyzed'];
        $available = !array_key_exists('available', $analysis) || !empty($analysis['available']);
        $workUnits = max((int) ($task['weight_floor'] ?? 1), (int) ($analysis['work_units'] ?? 0));
        if (!$available) {
            $workUnits = 0;
        }
        $defaultSelected = $available && !empty($task['default_selected']);
        if ($defaultSelected) {
            $selectedUnits += $workUnits;
        }
        $estimatedRows += max(0, (int) ($analysis['affected_rows'] ?? $analysis['eligible_rows'] ?? 0));
        $estimatedBytes += max(0, (int) ($analysis['estimated_bytes'] ?? $analysis['recommended_reclaimable_bytes'] ?? 0));
        $tasks[] = [
            'key' => $key,
            'group' => (string) ($task['group'] ?? 'system'),
            'label_key' => (string) ($task['label_key'] ?? ''),
            'label' => (string) ($task['label'] ?? $key),
            'risk' => (string) ($task['risk'] ?? 'low'),
            'mutates' => !empty($task['mutates']),
            'resumable' => !empty($task['resumable']),
            'progress_unit' => (string) ($task['progress_unit'] ?? 'units'),
            'dependencies' => array_values((array) ($task['dependencies'] ?? [])),
            'required' => !empty($task['required']),
            'available' => $available,
            'default_selected' => $defaultSelected,
            'work_units' => $workUnits,
            'analysis' => $analysis,
        ];
    }
    $plan = [
        'version' => 1,
        'job_id' => $jobId,
        'created_at' => now_sql(),
        'read_only_analysis' => true,
        'app_version' => (string) ($row['app_version'] ?? maintenance_center_app_version()),
        'schema_revision' => (string) ($row['schema_revision'] ?? maintenance_center_model_schema_revision()),
        'registry_revision' => MAINTENANCE_CENTER_REGISTRY_REVISION,
        'estimated_affected_rows' => $estimatedRows,
        'estimated_reclaimable_bytes' => $estimatedBytes,
        'estimated_work_units' => $selectedUnits,
        'tasks' => $tasks,
        'warnings' => array_values((array) ($state['warnings'] ?? [])),
    ];
    $planJson = maintenance_center_json_encode($plan);
    $planHash = hash('sha256', $planJson);
    maintenance_center_assert_transition('analyzing', 'ready');
    $state['analysis_complete_at'] = now_sql();
    $state['plan_hash'] = $planHash;
    maintenance_center_activity($state, 'info', maintenance_center_runtime_text('admin.maintenance_center.runtime.analysis_completed', 'Read-only analysis completed. Review the plan before execution.'));
    maintenance_center_model_update_job($jobId, $actorId, [
        'status' => 'ready',
        'phase' => 'review',
        'plan_json' => $planJson,
        'plan_hash' => $planHash,
        'state_json' => maintenance_center_json_encode($state),
        'progress_done' => 0,
        'progress_total' => $selectedUnits,
        'progress_percent' => 0,
        'updated_at' => now_sql(),
    ]);
    maintenance_center_log_lifecycle('info', 'maintenance_center.analysis_completed', 'Maintenance Center read-only analysis completed.', $jobId, [
        'task_count' => count($tasks),
        'estimated_affected_rows' => $estimatedRows,
        'estimated_work_units' => $selectedUnits,
    ]);
    return maintenance_center_job_status($jobId, $actorId);
}

/** Read-only preflight analyzer. */
function maintenance_center_analyze_preflight(array $context): array
{
    $schemaRevision = maintenance_center_model_schema_revision();
    $owner = maintenance_center_model_mutation_owner();
    $pending = pending_migrations_exist();
    return [
        'available' => maintenance_center_model_schema_ready() && $schemaRevision !== 'unknown' && !is_array($owner),
        'has_work' => false,
        'work_units' => 5,
        'maintenance_schema_ready' => maintenance_center_model_schema_ready(),
        'schema_revision' => $schemaRevision,
        'pending_migrations' => $pending,
        'central_mutation_owner' => is_array($owner) ? (int) ($owner['id'] ?? 0) : 0,
        'warning' => $pending ? maintenance_center_runtime_text('admin.maintenance_center.runtime.pending_migrations', 'Pending application migrations were detected. They will not be applied by Maintenance Center.') : '',
    ];
}

/** Read-only interrupted Trash analyzer. */
function maintenance_center_analyze_trash_reconcile(array $context): array
{
    $summary = gallery_trash_summary();
    $available = !empty($summary['available']);
    $count = max(0, (int) ($summary['transitional_count'] ?? 0)) + max(0, (int) ($summary['problem_count'] ?? 0));
    return ['available' => $available, 'has_work' => $count > 0, 'affected_rows' => $count, 'work_units' => max(1, $count), 'summary' => $summary];
}

/** Read-only telemetry analyzer owned by telemetry services/models. */
function maintenance_center_analyze_telemetry(array $context): array
{
    $analysis = telemetry_maintenance_analysis();
    return $analysis + [
        'affected_rows' => max(0, (int) ($analysis['eligible_rows'] ?? 0)),
        'work_units' => max(2, min(200, max(0, (int) ($analysis['eligible_rows'] ?? 0)) + max(0, (int) ($analysis['rollup_days'] ?? 0)) * 4)),
    ];
}

/** Read-only Admin log archival analyzer. */
function maintenance_center_analyze_logs(array $context): array
{
    $analysis = admin_log_archive_maintenance_analysis();
    return $analysis + [
        'affected_rows' => max(0, (int) ($analysis['eligible_rows'] ?? 0)),
        'work_units' => max(1, max(0, (int) ($analysis['eligible_days'] ?? 0)) * 4),
    ];
}

/** Read-only normal Trash retention analyzer. */
function maintenance_center_analyze_trash_retention(array $context): array
{
    $summary = gallery_trash_summary();
    $available = !empty($summary['available']);
    $expired = max(0, (int) ($summary['expired'] ?? 0));
    return [
        'available' => $available,
        'has_work' => $expired > 0,
        'affected_rows' => $expired,
        'estimated_bytes' => $expired > 0 ? max(0, (int) ($summary['bytes'] ?? 0)) : 0,
        'work_units' => max(1, $expired),
        'summary' => $summary,
    ];
}

/** Read-only security cleanup analyzer. */
function maintenance_center_analyze_security(array $context): array
{
    $schema = schema_inspection_feature('maintenance_center.security_cleanup', [
        schema_inspection_table('viewer_accounts'),
        schema_inspection_table('viewer_sessions'),
        schema_inspection_table('viewer_rate_limits'),
    ]);
    $available = schema_inspection_is_available($schema) || schema_inspection_is_missing($schema);
    return [
        'available' => $available,
        'has_work' => true,
        'work_units' => 8,
        'candidate_count_known' => false,
        'note' => maintenance_center_runtime_text('admin.maintenance_center.runtime.security_note', 'Existing security owners re-check expiry and schema preconditions immediately before each bounded cleanup slice.'),
    ];
}

/** Read-only cache analyzer without deleting or creating cache entries. */
function maintenance_center_analyze_downloads(array $context): array
{
    return [
        'available' => true,
        'has_work' => true,
        'work_units' => 10,
        'candidate_count_known' => false,
        'note' => maintenance_center_runtime_text('admin.maintenance_center.runtime.cache_note', 'Bounded cache owners determine safe expired/stale entries again at execution time.'),
    ];
}

/** Read-only thumbnail metadata orphan analyzer. */
function maintenance_center_analyze_thumbnail_metadata(array $context): array
{
    $analysis = site_maintenance_thumbnail_orphan_analysis();
    $count = max(0, (int) ($analysis['total'] ?? 0));
    return $analysis + ['has_work' => $count > 0, 'affected_rows' => $count, 'work_units' => max(1, $count)];
}

/** Determine whether expensive deep thumbnail verification is justified. */
function maintenance_center_analyze_media_deep(array $context): array
{
    $summary = cached_thumbnail_maintenance_summary_if_available(null, 1000);
    $recommended = !empty($summary['images_with_missing']) || !empty($summary['missing_variants']) || !empty($summary['limited']);
    return [
        'available' => true,
        'has_work' => $recommended,
        'recommended' => $recommended,
        'default_selected' => false,
        'work_units' => $recommended ? max(20, (int) ($summary['images_scanned'] ?? 0)) : 20,
        'sample' => [
            'images_scanned' => max(0, (int) ($summary['images_scanned'] ?? 0)),
            'images_with_missing' => max(0, (int) ($summary['images_with_missing'] ?? 0)),
            'missing_variants' => max(0, (int) ($summary['missing_variants'] ?? 0)),
            'limited' => !empty($summary['limited']),
        ],
        'note' => maintenance_center_runtime_text('admin.maintenance_center.runtime.deep_media_note', 'This central option performs a bounded full verification only; regeneration remains an explicit targeted thumbnail repair workflow.'),
    ];
}

/** Read-only database logical cleanup analyzer. */
function maintenance_center_analyze_database_logical(array $context): array
{
    $inventory = database_maintenance_schema_inventory();
    $rules = array_values(array_filter(database_maintenance_cleanup_rules($inventory), static fn (array $rule): bool => !empty($rule['automatic']) && (string) ($rule['confidence'] ?? '') === 'high'));
    $candidates = database_maintenance_inspect_cleanup_candidates($rules);
    $count = array_sum(array_map(static fn (array $candidate): int => max(0, (int) ($candidate['candidate_count'] ?? 0)), $candidates));
    return [
        'available' => true,
        'has_work' => $count > 0,
        'affected_rows' => $count,
        'work_units' => max(4, min(300, $count + count($rules) * 2)),
        'rule_count' => count($rules),
        'candidates' => array_map(static fn (array $candidate): array => [
            'key' => (string) ($candidate['key'] ?? ''),
            'table_name' => (string) ($candidate['table_name'] ?? ''),
            'candidate_count' => max(0, (int) ($candidate['candidate_count'] ?? 0)),
            'inspection_error' => (string) ($candidate['inspection_error'] ?? ''),
        ], $candidates),
    ];
}

/** Read-only Maintenance Center history policy analyzer. */
function maintenance_center_analyze_history(array $context): array
{
    return ['available' => true, 'has_work' => true, 'work_units' => 2, 'retention_days' => MAINTENANCE_CENTER_HISTORY_RETENTION_DAYS];
}

/** Read-only database inventory and physical eligibility analyzer. */
function maintenance_center_analyze_database_inventory(array $context): array
{
    $inventory = database_maintenance_schema_inventory();
    $compact = maintenance_center_compact_database_inventory($inventory);
    $physical = maintenance_center_classify_physical_tables($compact);
    return [
        'available' => true,
        'has_work' => true,
        'work_units' => max(3, (int) ($compact['table_count'] ?? 0)),
        'table_count' => (int) ($compact['table_count'] ?? 0),
        'total_bytes' => (int) ($compact['total_bytes'] ?? 0),
        'reclaimable_bytes_estimate' => (int) ($compact['reclaimable_bytes_estimate'] ?? 0),
        'physical' => $physical,
    ];
}

/** Read-only ANALYZE plan analyzer derived from the central physical classification. */
function maintenance_center_analyze_database_analyze(array $context): array
{
    $inventoryResult = (array) (($context['results']['database.inventory'] ?? []));
    $physical = (array) ($inventoryResult['physical'] ?? []);
    $count = max(0, (int) ($physical['analyze_candidate_count'] ?? 0));
    return ['available' => !empty($inventoryResult['available']), 'has_work' => $count > 0, 'table_count' => $count, 'work_units' => max(1, $count * 2), 'tables' => array_keys((array) ($physical['analyze_candidates'] ?? []))];
}

/** Read-only OPTIMIZE plan analyzer derived from the central physical classification. */
function maintenance_center_analyze_database_optimize(array $context): array
{
    $inventoryResult = (array) (($context['results']['database.inventory'] ?? []));
    $physical = (array) ($inventoryResult['physical'] ?? []);
    $count = max(0, (int) ($physical['recommended_count'] ?? 0));
    return [
        'available' => !empty($inventoryResult['available']),
        'has_work' => $count > 0,
        'table_count' => $count,
        'work_units' => max(1, (int) ($physical['recommended_work_units'] ?? $count)),
        'recommended_tables' => array_keys((array) ($physical['recommended'] ?? [])),
        'eligible_tables' => array_keys((array) ($physical['eligible'] ?? [])),
        'large_tables' => array_keys((array) ($physical['large'] ?? [])),
        'recommended_reclaimable_bytes' => max(0, (int) ($physical['recommended_reclaimable_bytes'] ?? 0)),
        'full_optimization_available' => !empty($physical['eligible']),
    ];
}

/** Read-only final verification analyzer. */
function maintenance_center_analyze_verify(array $context): array
{
    return ['available' => true, 'has_work' => true, 'work_units' => 10, 'checks' => ['schema_revision', 'maintenance_lock', 'database_inventory', 'subsystem_postconditions']];
}
