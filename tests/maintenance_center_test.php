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
    use function Gallery\Services\maintenance_center_exception_diagnostic;
    use function Gallery\Services\maintenance_center_expand_selection;
    use function Gallery\Services\maintenance_center_monotonic_percent;
    use function Gallery\Services\maintenance_center_run_download_cleanup_operation;
    use function Gallery\Services\maintenance_center_registry_order;
    use function Gallery\Services\maintenance_center_task_registry;
    use function Gallery\Services\maintenance_center_task_runtime_modules;
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

    maintenance_center_test_assert(maintenance_center_task_runtime_modules('preflight', 'analysis') === ['site-maintenance-work'], 'Preflight analysis must deferred-load migration inspection through the site-maintenance worker module.');
    maintenance_center_test_assert(maintenance_center_task_runtime_modules('telemetry.retention', 'execution') === ['site-maintenance-work'], 'Site maintenance owners must stay behind the reviewed site-maintenance worker module.');
    maintenance_center_test_assert(maintenance_center_task_runtime_modules('logs.retention', 'analysis') === ['archive-maintenance-work'], 'Admin log retention must use the dedicated archive worker module.');
    maintenance_center_test_assert(maintenance_center_task_runtime_modules('database.logical', 'execution') === ['domain-admin-database-maintenance'], 'Database maintenance tasks must load the existing database-maintenance domain module.');
    maintenance_center_test_assert(maintenance_center_task_runtime_modules('verify', 'analysis') === [] && maintenance_center_task_runtime_modules('verify', 'execution') === ['domain-admin-database-maintenance'], 'Verification must load database maintenance only for post-mutation execution checks.');
    maintenance_center_test_assert(maintenance_center_task_runtime_modules('maintenance.history', 'analysis') === [], 'Maintenance history retention must not load unrelated heavy modules.');

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

    $runtimeDiagnostic = maintenance_center_exception_diagnostic(new RuntimeException('private path must not be logged'));
    maintenance_center_test_assert(($runtimeDiagnostic['exception_class'] ?? '') === RuntimeException::class, 'Maintenance diagnostics must preserve the bounded exception class.');
    maintenance_center_test_assert(($runtimeDiagnostic['error_code'] ?? '') === 'runtime_exception', 'Maintenance diagnostics must classify generic runtime failures without persisting raw exception text.');
    maintenance_center_test_assert(!in_array('private path must not be logged', $runtimeDiagnostic, true), 'Maintenance diagnostics must not contain raw exception messages.');
    $reasonDiagnostic = maintenance_center_exception_diagnostic(new class('safe localized text') extends RuntimeException {
        /** Return a stable synthetic reason for bounded-diagnostic regression coverage. */
        public function reason(): string
        {
            return 'legacy_cache_not_writable';
        }
    });
    maintenance_center_test_assert(($reasonDiagnostic['error_code'] ?? '') === 'legacy_cache_not_writable', 'Stable application exception reasons must survive bounded maintenance diagnostics.');

    $isolatedCleanup = maintenance_center_run_download_cleanup_operation(0, 'test_owner', static function (): array {
        throw new RuntimeException('private cleanup detail');
    });
    maintenance_center_test_assert(empty($isolatedCleanup['ok']), 'Optional download/cache owner failures must be isolated instead of escaping the browser step.');
    maintenance_center_test_assert(($isolatedCleanup['diagnostic']['operation'] ?? '') === 'test_owner', 'Isolated cache failures must identify the exact sub-operation.');
    maintenance_center_test_assert(($isolatedCleanup['diagnostic']['error_code'] ?? '') === 'runtime_exception', 'Isolated cache failures must carry a stable diagnostic code.');

    $migration = maintenance_center_test_source($root, 'database/migrations/202609200004_maintenance_center.php');
    foreach (['plan_json', 'state_json', 'plan_hash', 'registry_revision', 'progress_done', 'progress_total', 'cancel_requested', 'error_category', 'lock_key'] as $field) {
        maintenance_center_test_assert(str_contains($migration, $field), 'Maintenance Center persistence migration must include field: ' . $field);
    }
    maintenance_center_test_assert(str_contains($migration, 'UNIQUE KEY maintenance_jobs_mutation_lock (lock_key)'), 'Persistent central mutation claim must be schema-enforced as unique.');

    $migrationRuntimeSource = maintenance_center_test_source($root, 'app/migrations.php');
    $pendingMigrationOffset = strpos($migrationRuntimeSource, 'function pending_migrations_exist(): bool');
    maintenance_center_test_assert($pendingMigrationOffset !== false, 'Pending-migration inspection must remain available.');
    $pendingMigrationSource = substr($migrationRuntimeSource, (int) $pendingMigrationOffset);
    maintenance_center_test_assert(!str_contains($pendingMigrationSource, 'CREATE TABLE IF NOT EXISTS schema_migrations'), 'Pending-migration inspection used by Analyze must remain read-only and must not create migration storage.');
    maintenance_center_test_assert(str_contains($pendingMigrationSource, "db()->query('SELECT version FROM schema_migrations')"), 'Pending-migration inspection must use a read-only audit-row query and fail closed when storage is unavailable.');

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
    maintenance_center_test_assert(str_contains($analysisSource, "maintenance_center_load_task_dependencies(\$key, 'analysis', \$loadDependencies)"), 'Each read-only analysis step must load its reviewed heavy subsystem dependencies before invoking the dynamic analyzer.');
    maintenance_center_test_assert(str_contains($analysisSource, 'maintenance_center_exception_diagnostic($exception)') && str_contains($analysisSource, "['task' => \$key] + \$diagnostic"), 'Analysis failures must persist bounded exception class/error-code diagnostics without raw exception text.');

    $executionSource = maintenance_center_test_source($root, 'app/services/maintenance_center/execution.php');
    maintenance_center_test_assert(str_contains($executionSource, 'atomic_inflight'), 'Atomic DB operations must checkpoint in-flight state before execution.');
    maintenance_center_test_assert(str_contains($executionSource, 'maintenance_center_model_acquire_step_lock($jobId)'), 'Execute start/step requests must serialize per job to make double-click admission idempotent.');
    maintenance_center_test_assert(str_contains($executionSource, 'database_maintenance_analyze_tables([$table])'), 'ANALYZE orchestration must pass exactly one server-selected table per step.');
    maintenance_center_test_assert(str_contains($executionSource, 'database_maintenance_optimize_tables([$table])'), 'OPTIMIZE orchestration must pass exactly one server-selected table per step.');
    maintenance_center_test_assert(str_contains($executionSource, 'maintenance_center_physical_table_allowed($currentTable)'), 'Physical DB execution must re-check current server-side eligibility immediately before mutation.');
    maintenance_center_test_assert(str_contains($executionSource, "['has_more']"), 'Telemetry/resumable execution must honor subsystem has_more checkpoints.');
    maintenance_center_test_assert(str_contains($executionSource, "['download_manifests', 'legacy_download_artifacts', 'zip_cache']"), 'Download/cache cleanup must keep independent server-owned sub-operations explicit.');
    maintenance_center_test_assert(str_contains($executionSource, 'maintenance_center_run_download_cleanup_operation'), 'Download/cache owner failures must be isolated and logged with bounded diagnostics.');
    maintenance_center_test_assert(str_contains($executionSource, "'maintenance_center.task_suboperation_failed'"), 'Download/cache sub-operation failures must emit a diagnosable Admin log event.');
    maintenance_center_test_assert(str_contains($executionSource, 'current_subtask'), 'Download/cache progress must expose the current bounded sub-operation.');
    maintenance_center_test_assert(str_contains($executionSource, 'cancel_requested'), 'Execution must cooperatively check persisted cancellation.');
    maintenance_center_test_assert(str_contains($executionSource, "maintenance_center_load_task_dependencies(\$key, 'execution', \$loadDependencies)"), 'Each execution slice must load its reviewed heavy subsystem dependencies before invoking the dynamic executor.');

    $siteMaintenanceSource = maintenance_center_test_source($root, 'app/services/site_maintenance.php');
    $centralClaimPosition = strpos($siteMaintenanceSource, 'maintenance_center_model_mutation_owner();');
    $telemetryPosition = strpos($siteMaintenanceSource, 'telemetry_run_scheduled_maintenance()', $centralClaimPosition === false ? 0 : $centralClaimPosition);
    maintenance_center_test_assert($centralClaimPosition !== false && $telemetryPosition !== false && $centralClaimPosition < $telemetryPosition, 'Automatic site maintenance must yield to the central mutation claim before its first mutation slice.');

    $controllerSource = maintenance_center_test_source($root, 'app/controllers/admin_maintenance_center.php');
    foreach (['require_admin()', "request_method() !== 'POST'", 'verify_csrf(', 'admin_mutation_success_envelope(', 'admin_mutation_error_envelope('] as $httpContract) {
        maintenance_center_test_assert(str_contains($controllerSource, $httpContract), 'Maintenance Center mutation controller must preserve Admin HTTP/mutation contract: ' . $httpContract);
    }
    maintenance_center_test_assert(str_contains($controllerSource, "url_for('admin_storage_statistics', ['tab' => 'maintenance'])"), 'Maintenance Center must prepare a direct URL back to Storage > DB maintenance.');
    maintenance_center_test_assert(str_contains($controllerSource, 'cms_runtime_kernel()->load($module)') && str_contains($controllerSource, 'maintenance_center_analysis_step($jobId, $actorId, $loadDependencies)') && str_contains($controllerSource, 'maintenance_center_execution_step($jobId, $actorId, $loadDependencies)'), 'Selective Maintenance Center endpoints must inject the request-local runtime module loader into bounded task steps.');
    maintenance_center_test_assert(!str_contains($controllerSource, 'OPTIMIZE TABLE') && !str_contains($controllerSource, 'ANALYZE TABLE'), 'Controller must never own SQL or physical-table statements.');

    $dynamicDependencySource = maintenance_center_test_source($root, 'scripts/runtime_dynamic_dependencies.php');
    maintenance_center_test_assert(str_contains($dynamicDependencySource, 'Gallery\\Services\\maintenance_center_load_task_dependencies|variable_callable|$loadDependencies()') && str_contains($dynamicDependencySource, 'injected-module-loader:maintenance-center-task-worker'), 'The deferred Maintenance Center worker loader must remain explicitly reviewed by runtime dynamic-dependency policy.');
    $runtimeModulesSource = maintenance_center_test_source($root, 'app/runtime/modules.php');
    foreach (['site-maintenance-work', 'archive-maintenance-work', 'domain-admin-database-maintenance'] as $workerModule) {
        maintenance_center_test_assert(str_contains($runtimeModulesSource, "'" . $workerModule . "' =>"), 'Maintenance Center deferred worker target must exist in the checked-in runtime plan: ' . $workerModule);
    }

    $maintenanceViewSource = maintenance_center_test_source($root, 'app/views/admin_maintenance_center.php');
    maintenance_center_test_assert(str_contains($maintenanceViewSource, "'storage_maintenance_url'") && str_contains($maintenanceViewSource, "'admin.storage.tab_database_maintenance'"), 'Maintenance Center must expose its direct return link to the DB maintenance tab.');
    $storageViewSource = maintenance_center_test_source($root, 'app/views/admin_storage_statistics.php');

    $databaseMaintenanceViewSource = maintenance_center_test_source($root, 'app/views/admin_database_maintenance.php');
    maintenance_center_test_assert(str_contains($databaseMaintenanceViewSource, "url_for('admin_maintenance_center')") && str_contains($databaseMaintenanceViewSource, "'admin.storage.open_maintenance_center'"), 'Storage > DB maintenance must link directly to the Maintenance Center with a localized label.');
    $advancedDisclosureStart = strpos($databaseMaintenanceViewSource, '<details class="admin-database-section-disclosure admin-database-advanced-tools">');
    $cleanupPanelCall = strpos($databaseMaintenanceViewSource, 'view_render_admin_database_cleanup_panel($cleanupState, $mutationsEnabled)');
    $repairPanelCall = strpos($databaseMaintenanceViewSource, 'view_render_admin_database_schema_repair_panel($repairReadiness, $mutationsEnabled)');
    $physicalPanelCall = strpos($databaseMaintenanceViewSource, 'view_render_admin_database_physical_operations_panel($tables, $mutationsEnabled)');
    $advancedDisclosureEnd = strpos($databaseMaintenanceViewSource, "echo '</div></div></details>';", $advancedDisclosureStart === false ? 0 : $advancedDisclosureStart);
    maintenance_center_test_assert($advancedDisclosureStart !== false && $cleanupPanelCall !== false && $repairPanelCall !== false && $physicalPanelCall !== false && $advancedDisclosureEnd !== false && $advancedDisclosureStart < $cleanupPanelCall && $cleanupPanelCall < $repairPanelCall && $repairPanelCall < $physicalPanelCall && $physicalPanelCall < $advancedDisclosureEnd, 'Logical cleanup, schema repair, ANALYZE, and OPTIMIZE panels must remain inside the collapsed native advanced-tools disclosure.');
    maintenance_center_test_assert(str_contains($databaseMaintenanceViewSource, "t('admin.database_maintenance.advanced_tools_help_label'") && str_contains($databaseMaintenanceViewSource, "<details class=\"admin-inline-help\"><summary aria-label=\""), 'Advanced operation explanation must remain inside a keyboard-accessible native question-mark disclosure.');
    maintenance_center_test_assert(str_contains($databaseMaintenanceViewSource, "t('admin.database_maintenance.report_timestamp'") && str_contains($databaseMaintenanceViewSource, "t('admin.database_maintenance.report_duration'"), 'The database report must keep a concise visible timestamp and move inspection duration into report help.');
    maintenance_center_test_assert(str_contains($databaseMaintenanceViewSource, "if (\$candidateCount === 0 && \$legacyFindings === [] && \$inspectionErrorCount === 0)") && str_contains($databaseMaintenanceViewSource, "t('admin.database_maintenance.no_findings'"), 'The no-findings statement must describe only a completed report with no candidates, legacy findings, or inspection errors.');
    foreach ([
        "url_for('admin_database_maintenance_cleanup')",
        "url_for('admin_database_maintenance_repair')",
        "view_render_admin_database_table_selection_form('admin_database_maintenance_analyze', \$tables, false, \$mutationsEnabled)",
        "view_render_admin_database_table_selection_form('admin_database_maintenance_optimize', \$tables, true, \$mutationsEnabled)",
        "name=\"confirmation_text\" autocomplete=\"off\"",
        "name=\"dry_run\" value=\"1\"",
        "t('admin.database_maintenance.type_clean'",
        "t('admin.database_maintenance.type_repair'",
        "t('admin.database_maintenance.type_optimize'",
        "t('admin.database_maintenance.optimize_warning'",
        "t('admin.database_maintenance.cleanup_warning'",
        "t('admin.database_maintenance.repair_ddl_warning'",
        'csrf_field()',
    ] as $advancedFormContract) {
        maintenance_center_test_assert(str_contains($databaseMaintenanceViewSource, $advancedFormContract), 'Advanced database controls must preserve native POST, CSRF, dry-run, destructive confirmation, and visible warning behavior: ' . $advancedFormContract);
    }

    $dispatchSource = maintenance_center_test_source($root, 'app/bootstrap/dispatch.php');
    foreach (['admin_maintenance_center', 'admin_maintenance_center_status', 'admin_maintenance_center_analyze_start', 'admin_maintenance_center_analyze_step', 'admin_maintenance_center_execute_start', 'admin_maintenance_center_execute_step', 'admin_maintenance_center_pause', 'admin_maintenance_center_cancel'] as $route) {
        maintenance_center_test_assert(str_contains($dispatchSource, "'" . $route . "'"), 'Maintenance Center route must remain registered: ' . $route);
    }

    $javascriptSource = maintenance_center_test_source($root, 'public/assets/gallery-modules/admin-maintenance-center.js');
    maintenance_center_test_assert(str_contains($javascriptSource, 'window.setTimeout') && str_contains($javascriptSource, 'endpoints.analyzeStep') && str_contains($javascriptSource, 'endpoints.executeStep'), 'Browser orchestration must drive repeated bounded persisted steps without page navigation.');
    maintenance_center_test_assert(!str_contains($javascriptSource, 'location.reload('), 'Maintenance Center must not reload the page between bounded steps.');
    maintenance_center_test_assert(str_contains($maintenanceViewSource, '<details class="panel maintenance-center-review" data-maintenance-review') && str_contains($maintenanceViewSource, 'data-maintenance-task-groups') && str_contains($maintenanceViewSource, 'data-maintenance-full-optimize'), 'Maintenance Center must keep the plan and full-optimization selection as native, accessible form controls.');
    maintenance_center_test_assert(str_contains($maintenanceViewSource, "\$showAnalysisNote = \$job === null || in_array((string) (\$job['status'] ?? ''), ['analyzing', 'ready'], true)") && str_contains($javascriptSource, "analysisNote.hidden = Boolean(job) && !['analyzing', 'ready'].includes(status)"), 'The Analyze-before-mutation banner must be visible before a plan exists or while it is analyzing/ready, then hide during and after execution.');
    maintenance_center_test_assert(str_contains($javascriptSource, "review.open = !['completed', 'failed', 'cancelled'].includes(status)") && str_contains($javascriptSource, "checkbox.disabled = !task.available || Boolean(task.required) || status !== 'ready'") && str_contains($javascriptSource, "fullOptimize.disabled = status !== 'ready'"), 'Plan selection must remain editable only at Ready and read-only during or after execution.');
    maintenance_center_test_assert(str_contains($javascriptSource, 'task.available === true && !task.required && task.analysis?.has_work === false && !keepDiscoverable'), 'Only explicitly available optional tasks with a strict no-work result may move into the collapsed group.');
    maintenance_center_test_assert(str_contains($javascriptSource, "task.key === 'media.deep_verify'") && str_contains($javascriptSource, "task.key === 'database.optimize' && task.analysis?.full_optimization_available === true"), 'Opt-in deep media verification and currently available full optimization must stay discoverable.');
    maintenance_center_test_assert(str_contains($javascriptSource, "checkbox.checked = task.required || (executionStarted ? selectedPersisted.has(task.key) : Boolean(task.default_selected))") && str_contains($javascriptSource, "const executionStarted = ['running', 'paused', 'completed', 'failed', 'cancelled'].includes(status) && selectedPersisted.size > 0"), 'Redrawing the task groups must preserve required selections, server-persisted choices, and initial defaults.');
    maintenance_center_test_assert(str_contains($javascriptSource, "previouslyExpandedNoWork = Boolean(taskGroups.querySelector('.maintenance-center-no-work')?.open)") && str_contains($javascriptSource, 'noWork.open = previouslyExpandedNoWork') && str_contains($javascriptSource, 'analysisFacts(task, strings)'), 'Rerendering must preserve the no-work disclosure state and retain every task analysis fact inside its group.');
    maintenance_center_test_assert(str_contains($javascriptSource, 'strings.required_automatic') && str_contains($javascriptSource, 'strings.no_work_count'), 'Required automatic steps and collapsed no-work counts must use localized labels.');
    maintenance_center_test_assert(str_contains($javascriptSource, "section.className = 'maintenance-center-report-detail'") && str_contains($javascriptSource, "document.createElement('details')") && str_contains($javascriptSource, 'heading.textContent = `${title} (${formatNumber(entries.length)})`'), 'Completed report breakdowns must remain collapsed count-labeled disclosures.');

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
