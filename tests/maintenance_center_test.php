<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/maintenance_center_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects the central Maintenance Center orchestration and safety contracts.
 *
 * Responsibilities:
 *   - Validate the canonical task registry, ordering, and state machine
 *   - Protect optional-task selection and server-authoritative dependency expansion
 *   - Verify read-only analysis and one-table-per-step physical DB boundaries
 *   - Protect persisted resume, lock, HTTP, UI, translation, and migration contracts
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Source-level assertions deliberately avoid requiring a live MySQL/MariaDB server.
 *   - Real physical table rebuild behavior remains environment-dependent and is not claimed here.
 */

declare(strict_types=1);

namespace {
    use function Gallery\Services\maintenance_center_expand_selection;
    use function Gallery\Services\maintenance_center_monotonic_percent;
    use function Gallery\Services\maintenance_center_registry_order;
    use function Gallery\Services\maintenance_center_task_registry;
    use function Gallery\Services\maintenance_center_transition_allowed;

    /** Throw when one Maintenance Center regression contract fails. */
    function maintenance_center_test_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new RuntimeException($label);
        }
    }

    /** Read one repository source file or fail the regression test. */
    function maintenance_center_test_source(string $root, string $relativePath): string
    {
        $source = @file_get_contents($root . '/' . ltrim($relativePath, '/'));
        if (!is_string($source)) {
            throw new RuntimeException('Maintenance Center test could not read ' . $relativePath . '.');
        }
        return $source;
    }

    $root = dirname(__DIR__);
    require_once $root . '/app/services/maintenance_center.php';

    $registry = maintenance_center_task_registry();
    $order = maintenance_center_registry_order($registry);
    $expectedKeys = [
        'preflight', 'trash.reconcile', 'telemetry.retention', 'logs.retention', 'trash.retention',
        'security.cleanup', 'downloads.cache', 'thumbnail.metadata', 'media.deep_verify', 'database.logical',
        'maintenance.history', 'database.inventory', 'database.analyze', 'database.optimize', 'verify',
    ];
    maintenance_center_test_assert(array_keys($registry) === $expectedKeys, 'Maintenance Center registry keys/order must remain explicit and stable.');
    maintenance_center_test_assert($order === $expectedKeys, 'Maintenance Center dependency order must remain deterministic.');
    maintenance_center_test_assert(array_search('database.logical', $order, true) < array_search('database.optimize', $order, true), 'Logical cleanup must precede physical optimization.');
    maintenance_center_test_assert(array_search('database.inventory', $order, true) < array_search('database.analyze', $order, true), 'Fresh DB inventory must precede table statistics maintenance.');
    maintenance_center_test_assert(array_search('database.analyze', $order, true) < array_search('database.optimize', $order, true), 'DB ANALYZE stage must precede OPTIMIZE in the canonical pipeline.');
    maintenance_center_test_assert(!empty($registry['verify']['required']) && ($registry['verify']['dependencies'] ?? []) === ['preflight'], 'Required verification must not force optional deep-media or physical-DB work.');
    maintenance_center_test_assert(empty($registry['media.deep_verify']['default_selected']), 'Deep media verification must remain opt-in by default.');
    maintenance_center_test_assert(!empty($registry['database.optimize']['atomic']), 'Physical DB optimization must remain an explicit atomic operation.');

    foreach ($registry as $key => $task) {
        maintenance_center_test_assert(isset($task['analyzer'], $task['executor']), 'Every Maintenance Center task requires stable analyze/execute adapters: ' . $key);
        maintenance_center_test_assert(is_array($task['dependencies'] ?? null), 'Every Maintenance Center task requires explicit dependencies: ' . $key);
        foreach ((array) $task['dependencies'] as $dependency) {
            maintenance_center_test_assert(array_search((string) $dependency, $order, true) < array_search((string) $key, $order, true), 'Dependency must sort before task: ' . $dependency . ' -> ' . $key);
        }
    }

    maintenance_center_test_assert(maintenance_center_transition_allowed('analyzing', 'ready'), 'Analyze -> ready transition must remain valid.');
    maintenance_center_test_assert(maintenance_center_transition_allowed('ready', 'running'), 'Ready -> running transition must remain valid.');
    maintenance_center_test_assert(maintenance_center_transition_allowed('running', 'paused'), 'Running -> paused transition must remain valid.');
    maintenance_center_test_assert(maintenance_center_transition_allowed('paused', 'running'), 'Paused -> running transition must remain valid.');
    maintenance_center_test_assert(maintenance_center_transition_allowed('running', 'completed'), 'Running -> completed transition must remain valid.');
    maintenance_center_test_assert(!maintenance_center_transition_allowed('completed', 'running'), 'Terminal jobs must never restart.');
    maintenance_center_test_assert(!maintenance_center_transition_allowed('ready', 'completed'), 'Unexecuted plans must not jump directly to completed.');

    $planTasks = [];
    foreach ($registry as $key => $task) {
        $planTasks[] = ['key' => $key, 'available' => true];
    }
    $verifyOnly = maintenance_center_expand_selection([], $planTasks);
    maintenance_center_test_assert($verifyOnly === ['preflight', 'verify'], 'Required verification must expand only its true preflight dependency.');
    $physicalSelection = maintenance_center_expand_selection(['database.optimize'], $planTasks);
    maintenance_center_test_assert(in_array('database.logical', $physicalSelection, true), 'Physical optimization must bring safe logical cleanup into its dependency chain.');
    maintenance_center_test_assert(in_array('database.inventory', $physicalSelection, true) && in_array('database.analyze', $physicalSelection, true), 'Physical optimization must bring inventory/statistics prerequisites into its dependency chain.');
    maintenance_center_test_assert(!in_array('media.deep_verify', $physicalSelection, true), 'Physical optimization must not implicitly enable optional deep-media verification.');
    maintenance_center_test_assert(maintenance_center_monotonic_percent(68.0, 10, 100) === 68.0, 'Progress percentage must never regress.');
    maintenance_center_test_assert(maintenance_center_monotonic_percent(10.0, 75, 100) === 75.0, 'Progress percentage must advance from work units.');

    $migration = maintenance_center_test_source($root, 'database/migrations/202609200004_maintenance_center.php');
    foreach (['plan_json', 'state_json', 'plan_hash', 'registry_revision', 'progress_done', 'progress_total', 'cancel_requested', 'error_category', 'lock_key'] as $field) {
        maintenance_center_test_assert(str_contains($migration, $field), 'Maintenance Center persistence migration must include field: ' . $field);
    }
    maintenance_center_test_assert(str_contains($migration, 'UNIQUE KEY maintenance_jobs_mutation_lock (lock_key)'), 'Persistent central mutation claim must be schema-enforced as unique.');

    $modelSource = maintenance_center_test_source($root, 'app/models/maintenance_center.php');
    maintenance_center_test_assert(str_contains($modelSource, 'GET_LOCK(') && str_contains($modelSource, 'RELEASE_LOCK('), 'Per-job browser-step serialization must use a short-lived database advisory lock.');
    maintenance_center_test_assert(str_contains($modelSource, 'pg_mc_analysis_'), 'Concurrent actor-level Analyze starts must be serialized by a short-lived database advisory lock.');
    maintenance_center_test_assert(str_contains($modelSource, "lock_key = 'central'") || str_contains($modelSource, "'central'"), 'The model must own the durable central mutation claim.');
    maintenance_center_test_assert(str_contains($modelSource, 'DELETE FROM maintenance_jobs') && str_contains($modelSource, 'LIMIT'), 'Maintenance job history retention must remain bounded.');

    $analysisSource = maintenance_center_test_source($root, 'app/services/maintenance_center/analysis.php');
    foreach (['database_maintenance_optimize_tables(', 'database_maintenance_analyze_tables(', 'purge_expired_gallery_trash(', 'telemetry_run_maintenance(', 'viewer_security_maintenance_cleanup(', 'cleanup_expired_zip_cache('] as $mutationBoundary) {
        maintenance_center_test_assert(!str_contains($analysisSource, $mutationBoundary), 'Analyze must remain read-only and must not call mutation owner: ' . $mutationBoundary);
    }
    maintenance_center_test_assert(str_contains($analysisSource, 'maintenance_center_model_schema_revision()'), 'Plans must bind to current schema/migration revision.');
    maintenance_center_test_assert(str_contains($analysisSource, 'MAINTENANCE_CENTER_REGISTRY_REVISION'), 'Plans must bind to task-registry revision.');
    maintenance_center_test_assert(str_contains($analysisSource, 'maintenance_center_model_acquire_analysis_start_lock($actorId)'), 'Repeated Analyze admission must serialize before replacing/creating the persisted actor plan.');

    $executionSource = maintenance_center_test_source($root, 'app/services/maintenance_center/execution.php');
    maintenance_center_test_assert(str_contains($executionSource, 'atomic_inflight'), 'Atomic DB operations must checkpoint in-flight state before execution.');
    maintenance_center_test_assert(str_contains($executionSource, 'maintenance_center_model_acquire_step_lock($jobId)'), 'Execute start/step requests must serialize per job to make double-click admission idempotent.');
    maintenance_center_test_assert(str_contains($executionSource, 'database_maintenance_analyze_tables([$table])'), 'ANALYZE orchestration must pass exactly one server-selected table per step.');
    maintenance_center_test_assert(str_contains($executionSource, 'database_maintenance_optimize_tables([$table])'), 'OPTIMIZE orchestration must pass exactly one server-selected table per step.');
    maintenance_center_test_assert(str_contains($executionSource, 'maintenance_center_physical_table_allowed($currentTable)'), 'Physical DB execution must re-check current server-side eligibility immediately before mutation.');
    maintenance_center_test_assert(str_contains($executionSource, "['has_more']"), 'Telemetry/resumable execution must honor subsystem has_more checkpoints.');
    maintenance_center_test_assert(str_contains($executionSource, 'cancel_requested'), 'Execution must cooperatively check persisted cancellation.');

    $siteMaintenanceSource = maintenance_center_test_source($root, 'app/services/site_maintenance.php');
    $centralClaimPosition = strpos($siteMaintenanceSource, 'maintenance_center_model_mutation_owner();');
    $telemetryPosition = strpos($siteMaintenanceSource, 'telemetry_run_scheduled_maintenance()', $centralClaimPosition === false ? 0 : $centralClaimPosition);
    maintenance_center_test_assert($centralClaimPosition !== false && $telemetryPosition !== false && $centralClaimPosition < $telemetryPosition, 'Automatic site maintenance must yield to the central mutation claim before its first mutation slice.');

    $controllerSource = maintenance_center_test_source($root, 'app/controllers/admin_maintenance_center.php');
    foreach (['require_admin()', "request_method() !== 'POST'", 'verify_csrf(', 'admin_mutation_success_envelope(', 'admin_mutation_error_envelope('] as $httpContract) {
        maintenance_center_test_assert(str_contains($controllerSource, $httpContract), 'Maintenance Center mutation controller must preserve Admin HTTP/mutation contract: ' . $httpContract);
    }
    maintenance_center_test_assert(!str_contains($controllerSource, 'OPTIMIZE TABLE') && !str_contains($controllerSource, 'ANALYZE TABLE'), 'Controller must never own SQL or physical-table statements.');

    $dispatchSource = maintenance_center_test_source($root, 'app/bootstrap/dispatch.php');
    foreach (['admin_maintenance_center', 'admin_maintenance_center_status', 'admin_maintenance_center_analyze_start', 'admin_maintenance_center_analyze_step', 'admin_maintenance_center_execute_start', 'admin_maintenance_center_execute_step', 'admin_maintenance_center_pause', 'admin_maintenance_center_cancel'] as $route) {
        maintenance_center_test_assert(str_contains($dispatchSource, "'" . $route . "'"), 'Maintenance Center route must remain registered: ' . $route);
    }

    $javascriptSource = maintenance_center_test_source($root, 'public/assets/gallery-modules/admin-maintenance-center.js');
    maintenance_center_test_assert(str_contains($javascriptSource, 'window.setTimeout') && str_contains($javascriptSource, 'endpoints.analyzeStep') && str_contains($javascriptSource, 'endpoints.executeStep'), 'Browser orchestration must drive repeated bounded persisted steps without page navigation.');
    maintenance_center_test_assert(!str_contains($javascriptSource, 'location.reload('), 'Maintenance Center must not reload the page between bounded steps.');

    $dashboardSource = maintenance_center_test_source($root, 'app/views/admin_dashboard_sections.php');
    $navigationSource = maintenance_center_test_source($root, 'app/views/admin_chrome.php');
    maintenance_center_test_assert(str_contains($dashboardSource, 'maintenance_center'), 'Admin Dashboard must expose Maintenance Center status/action entry.');
    maintenance_center_test_assert(str_contains($navigationSource, "'admin_maintenance_center'"), 'Maintenance navigation must expose the dedicated Maintenance Center page.');

    $referenceKeys = null;
    foreach (['en', 'cs', 'de', 'sv'] as $language) {
        $catalog = json_decode(maintenance_center_test_source($root, 'app/lang/' . $language . '.json'), true, 512, JSON_THROW_ON_ERROR);
        $keys = array_values(array_filter(array_keys($catalog), static fn (string $key): bool => str_starts_with($key, 'admin.maintenance_center.')));
        sort($keys);
        maintenance_center_test_assert(count($keys) >= 70, 'Every maintained language must contain the Maintenance Center catalog: ' . $language);
        if ($referenceKeys === null) {
            $referenceKeys = $keys;
        } else {
            maintenance_center_test_assert($keys === $referenceKeys, 'Maintenance Center translation keys must remain consistent in ' . $language . '.');
        }
    }

    echo "Maintenance Center regression contracts passed.\n";
}
