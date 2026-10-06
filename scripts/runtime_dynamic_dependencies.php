<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/runtime_dynamic_dependencies.php
 * Module Type: Development Tool
 * Purpose: Review dynamic dependency sites reachable from runtime route and lifecycle roots.
 * Responsibilities: Reject unreviewed callable-site drift and declare composed callback dependencies.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Tools\RuntimeDynamicDependencies;

require_once __DIR__ . '/cli_guard.php';
\gallery_guard_cli_entrypoint(__FILE__);

require_once __DIR__ . '/runtime_dependencies.php';

use function Gallery\Tools\RuntimeDependencies\scan_files;
use function Gallery\Tools\RuntimeDependencies\build_symbol_index;
use function Gallery\Tools\RuntimeDependencies\read_source_file;
use function Gallery\Tools\RuntimeDependencies\collect_symbol_dependencies;
use function Gallery\Tools\RuntimeDependencies\dependency_closure;

/**
 * Return narrow source declaration roots required by statically opaque callbacks.
 *
 * These names are concrete Gallery functions referenced by string-built callbacks.
 * Optional runtime functions that have no source declaration are intentionally omitted.
 *
 * @return array<string,list<string>> Logical runtime module identifiers mapped to required declarations.
 */
function runtime_dynamic_dependency_additional_roots(): array
{
    $targets = runtime_dynamic_dependency_callback_targets();
    return [
        'updater-work' => $targets['Gallery\\Services\\application_update_job_finalize'],
        'domain-admin-test-runs' => $targets['Gallery\\Services\\admin_test_run_clear_safe_caches'],
    ];
}

/**
 * Return concrete declarations hidden behind string-built callback arrays.
 *
 * @return array<string,list<string>> Dynamic call sites mapped to required source symbols.
 */
function runtime_dynamic_dependency_callback_targets(): array
{
    return [
        'Gallery\\Services\\admin_test_run_clear_safe_caches' => [
            'Gallery\\Services\\admin_storage_statistics_cache_clear',
            'Gallery\\Services\\app_settings_reset_request_cache',
            'Gallery\\Services\\content_localization_reset_request_cache',
            'Gallery\\Services\\schema_inspection_reset_request_cache',
            'Gallery\\Services\\smart_gallery_graph_cache_clear',
            'Gallery\\Services\\thumbnail_maintenance_summary_cache_clear_diagnostic',
            'Gallery\\Services\\translation_clear_runtime_cache',
        ],
        'Gallery\\Services\\application_update_job_finalize' => [
            'Gallery\\Services\\admin_storage_statistics_cache_clear',
            'Gallery\\Services\\content_localization_reset_request_cache',
            'Gallery\\Services\\db_schema_helper_reset_request_cache',
            'Gallery\\Services\\schema_inspection_reset_request_cache',
            'Gallery\\Services\\smart_gallery_graph_cache_clear',
            'Gallery\\Services\\thumbnail_maintenance_summary_cache_clear',
            'Gallery\\Services\\translation_clear_runtime_cache',
        ],
    ];
}

/**
 * Return reviewed dynamic site identities, without source line numbers.
 *
 * The caller and expression together are the stable contract. A new expression in
 * an existing caller still needs review; line movement does not change this policy.
 * Ordinary object method dispatch is intentionally excluded: source-owned class
 * declarations and their methods are loaded from the statically resolved class
 * reference, while PDO/exception/library methods are runtime object APIs.
 *
 * @return array<string,string> Site signatures mapped to their reviewed disposition.
 */
function runtime_dynamic_dependency_reviewed_sites(): array
{
    $signatures = <<<'SITES'
Gallery\Controllers\admin_gallery_discovery_capture_html|variable_callable|$renderer()
Gallery\Controllers\admin_maintenance_center_json_action|variable_callable|$callback()
Gallery\Controllers\admin_upload_capture_html|variable_callable|$renderer()
Gallery\Controllers\smart_gallery_capture_html|variable_callable|$renderer()
Gallery\Controllers\viewer_collection_capture_html|variable_callable|$renderer()
Gallery\Controllers\viewer_lifecycle_capture_html|variable_callable|$renderer()
Gallery\Core\db|dynamic_class|new $pdoClass
Gallery\Core\db|variable_callable|$pdoClass()
Gallery\Core\load_migration_definition|dynamic_callback_argument|is_callable($after)
Gallery\Core\run_migrations|variable_callable|$after()
Gallery\Models\admin_gallery_report_model_image_rows_after_id|variable_callable|$optional()
Gallery\Models\admin_setup_wizard_model_transaction|variable_callable|$compensate()
Gallery\Models\admin_setup_wizard_model_transaction|variable_callable|$operation()
Gallery\Models\gallery_feature_plan_model_transaction|variable_callable|$operation()
Gallery\Models\viewer_account_model_transaction|variable_callable|$operation()
Gallery\Models\viewer_collection_model_transaction|variable_callable|$operation()
Gallery\Models\viewer_favourite_model_set|variable_callable|$lockedAccountAllowed()
Gallery\Models\viewer_lifecycle_model_transaction|variable_callable|$operation()
Gallery\Models\viewer_registration_model_independent_transaction|variable_callable|$operation()
Gallery\Models\viewer_registration_model_transaction|variable_callable|$operation()
Gallery\Services\admin_log_archive_register_request_trigger|variable_callable|$loadDependencies()
Gallery\Services\admin_mutation_schema_health_statuses|dynamic_callback_argument|is_callable($resolver)
Gallery\Services\admin_mutation_schema_health_statuses|variable_callable|$resolver()
Gallery\Services\admin_ordered_gallery_rows|variable_callable|$appendChildren()
Gallery\Services\admin_presentation_schema_health_statuses|dynamic_callback_argument|is_callable($resolver)
Gallery\Services\admin_presentation_schema_health_statuses|variable_callable|$resolver()
Gallery\Services\admin_render_profile_db|variable_callable|$callback()
Gallery\Services\admin_render_profile_span|variable_callable|$callback()
Gallery\Services\admin_setup_wizard_apply|dynamic_callback_argument|is_callable($restoreUrl)
Gallery\Services\admin_setup_wizard_apply|variable_callable|$restoreUrl()
Gallery\Services\admin_storage_refresh_with_lock|variable_callable|$operation()
Gallery\Services\admin_test_run_clear_safe_caches|dynamic_function_exists|function_exists(dynamic)
Gallery\Services\admin_test_run_clear_safe_caches|variable_callable|$callback()
Gallery\Services\admin_test_run_create|variable_callable|$callback()
Gallery\Services\admin_test_run_create|variable_callable|$phase()
Gallery\Services\application_autoupdate_maybe_run|variable_callable|$loadDependencies()
Gallery\Services\application_update_job_finalize|dynamic_function_exists|function_exists(dynamic)
Gallery\Services\application_update_job_finalize|variable_callable|$callback()
Gallery\Services\application_update_release_files|variable_callable|$priority()
Gallery\Services\browser_thumbnail_rebuild_stream_file_payload|variable_callable|$emit()
Gallery\Services\browser_thumbnail_rebuild_stream_source_zip|variable_callable|$emit()
Gallery\Services\browser_thumbnail_rebuild_stream_source_zip|variable_callable|$writeEntry()
Gallery\Services\content_translation_rows|dynamic_callback_argument|is_callable($contentLocalizationLoaderForTests)
Gallery\Services\content_translation_rows|variable_callable|$contentLocalizationLoaderForTests()
Gallery\Services\database_maintenance_cleanup_rules|variable_callable|$addOrphan()
Gallery\Services\database_maintenance_migration_audit|variable_callable|$appendObject()
Gallery\Services\database_maintenance_migration_audit|variable_callable|$touchTable()
Gallery\Services\database_maintenance_table_policies|variable_callable|$derived()
Gallery\Services\database_maintenance_table_policies|variable_callable|$protected()
Gallery\Services\flight_map_each_csv_row|variable_callable|$callback()
Gallery\Services\gallery_benchmark_media_request_finish|variable_callable|$duration()
Gallery\Services\gallery_benchmark_request_trace_snapshot|variable_callable|$duration()
Gallery\Services\gallery_benchmark_update_log|variable_callable|$callback()
Gallery\Services\gallery_editor_unique_slug|variable_callable|$collision()
Gallery\Services\gallery_effective_gps_map_enabled|variable_callable|$galleryLookup()
Gallery\Services\gallery_image_move_execute_files|variable_callable|$checkpoint()
Gallery\Services\gallery_legacy_allows_gps_maps|variable_callable|$galleryLookup()
Gallery\Services\gallery_migration_package_plan|variable_callable|$flush()
Gallery\Services\gallery_move_images_locked|dynamic_callback_argument|is_callable($options)
Gallery\Services\gallery_move_images_locked|variable_callable|$checkpoint()
Gallery\Services\gallery_public_path_assignments|variable_callable|$buildPath()
Gallery\Services\image_decode_gd_path_result|dynamic_function_exists|function_exists(dynamic)
Gallery\Services\legacy_download_artifact_cache_status_for_path|variable_callable|$probe()
Gallery\Services\maintenance_center_analysis_step_unlocked|dynamic_callback_argument|is_callable($analyzer)
Gallery\Services\maintenance_center_analysis_step_unlocked|variable_callable|$analyzer()
Gallery\Services\maintenance_center_execution_step_unlocked|dynamic_callback_argument|is_callable($executor)
Gallery\Services\maintenance_center_execution_step_unlocked|variable_callable|$executor()
Gallery\Services\maintenance_center_expand_selection|variable_callable|$expand()
Gallery\Services\maintenance_center_registry_order|variable_callable|$visit()
Gallery\Services\pagination_page_url|variable_callable|$urlBuilder()
Gallery\Services\public_render_profile_db|variable_callable|$callback()
Gallery\Services\public_render_profile_span|variable_callable|$callback()
Gallery\Services\public_render_profile_with_thumbnail_purpose|variable_callable|$callback()
Gallery\Services\schema_inspection_column_definition_contains|dynamic_callback_argument|is_callable($override)
Gallery\Services\schema_inspection_column_definition_contains|variable_callable|$override()
Gallery\Services\schema_inspection_column_nullable|dynamic_callback_argument|is_callable($override)
Gallery\Services\schema_inspection_column_nullable|variable_callable|$override()
Gallery\Services\schema_inspection_execute_query|dynamic_callback_argument|is_callable($override)
Gallery\Services\schema_inspection_execute_query|variable_callable|$override()
Gallery\Services\schema_inspection_prime_table_snapshots|dynamic_callback_argument|is_callable($override)
Gallery\Services\simbrief_description_build_localized_markdown|variable_callable|$replace()
Gallery\Services\site_maintenance_process_cleanup_step|variable_callable|$run()
Gallery\Services\site_maintenance_register_request_trigger|variable_callable|$loadDependencies()
Gallery\Services\site_maintenance_run_cleanup_operation|variable_callable|$callback()
Gallery\Services\site_maintenance_with_lock|variable_callable|$callback()
Gallery\Services\site_url_mutate_config_source|variable_callable|$mutator()
Gallery\Services\smart_gallery_map_query_context|variable_callable|$lookup()
Gallery\Services\smart_gallery_presentation_master_status|variable_callable|$capabilityEnabled()
Gallery\Services\upload_automation_with_gallery_lock|variable_callable|$callback()
Gallery\Services\viewer_authenticate_password|variable_callable|$failure()
Gallery\Services\viewer_email_change_request_start|variable_callable|$failure()
Gallery\Services\viewer_password_reset_request|variable_callable|$result()
Gallery\Services\viewer_reauthentication_status|variable_callable|$failure()
Gallery\Services\viewer_registration_verification_resend_deliver_locked|variable_callable|$deliver()
Gallery\Services\viewer_registration_verification_resend_prepare|variable_callable|$empty()
Gallery\Services\weighted_tag_suggestions_for_gallery|variable_callable|$addRows()
Gallery\Services\navigation_data_navigraph_exchange_code|variable_callable|$postForm()
Gallery\Views\admin_gallery_report_table|dynamic_callback_argument|is_callable($column)
Gallery\Views\view_telemetry_export_table|dynamic_callback_argument|is_callable($column)
SITES;

    $reviewed = [];
    foreach (explode("\n", $signatures) as $signature) {
        $signature = trim($signature);
        if ($signature === '') {
            continue;
        }
        $reviewed[$signature] = match (true) {
            str_contains($signature, '|dynamic_class|'),
            str_starts_with($signature, 'Gallery\\Core\\db|variable_callable|') => 'runtime-class:PDO-driver-from-config',
            str_contains($signature, 'cms_route_from_request|') => 'injected-module-loader:routing-paths',
            str_contains($signature, 'application_autoupdate_maybe_run|'),
            str_contains($signature, 'site_maintenance_register_request_trigger|'),
            str_contains($signature, 'admin_log_archive_register_request_trigger|') => 'injected-module-loader:explicit-module-id',
            str_contains($signature, 'application_update_job_finalize|') => 'string-callbacks:required-updater-cache-roots',
            str_contains($signature, 'admin_test_run_clear_safe_caches|') => 'string-callbacks:required-diagnostic-cache-roots',
            str_contains($signature, 'image_decode_gd_path_result|') => 'runtime-function:optional-gd-decoder',
            str_contains($signature, 'navigation_data_navigraph_exchange_code|') => 'injected-oauth-transport:default-http-post-remains-static',
            default => 'caller-owned-or-local-callback:no-module-edge',
        };
    }
    return $reviewed;
}

/**
 * Build the reachable unresolved dynamic-site inventory for route and lifecycle roots.
 *
 * @param string $root Absolute repository root.
 * @return list<array{caller:string,kind:string,expression:string,line:int}> Stable-site inventory with diagnostic line numbers.
 */
function runtime_dynamic_dependency_sites(string $root): array
{
    $files = array_filter(scan_files($root), static fn(array $file): bool => str_starts_with($file['path'], 'app/'));
    $indexed = build_symbol_index($files);
    $symbols = $indexed['symbols'];
    $policy = require __DIR__ . '/runtime_module_roots.php';
    $dependencies = [];
    foreach ($indexed['perFile'] as $path => $declarations) {
        if ($declarations === []) {
            continue;
        }
        $parsed = read_source_file($root . '/' . $path, $root);
        foreach ($declarations as $symbol) {
            if (!in_array($symbol['kind'], ['function', 'method'], true)) {
                continue;
            }
            $analysis = collect_symbol_dependencies($parsed, $symbol, $symbols);
            $deferred = array_map('strtolower', $policy['deferred_calls'][$symbol['name']] ?? []);
            $edges = array_values(array_filter(
                $analysis['edges'],
                static fn(array $edge): bool => !in_array(strtolower($edge['target']), $deferred, true)
            ));
            $dependencies[$symbol['id']] = [
                'edges' => $edges,
                'unresolved' => $analysis['unresolved'],
            ];
        }
    }

    $dispatch = (string) file_get_contents($root . '/app/bootstrap/dispatch.php');
    if (preg_match('/\$routes\s*=\s*\[(.*?)\n\s*\];/s', $dispatch, $routeBlock) !== 1) {
        throw new \RuntimeException('Canonical route table is unavailable to the dynamic-site review.');
    }
    preg_match_all("/'([a-z][a-z0-9_]*)'\\s*=>\\s*'([^']+)'/", $routeBlock[1], $pairs, PREG_SET_ORDER);
    $roots = [];
    foreach ($pairs as $pair) {
        $roots[] = ltrim(str_replace('\\\\', '\\', $pair[2]), '\\');
    }
    $roots[] = 'Gallery\\Controllers\\cms_not_found';
    foreach ($policy['modules'] as $entrypoints) {
        foreach ($entrypoints as $entrypoint) {
            $roots[] = ltrim($entrypoint, '\\');
        }
    }

    $sites = [];
    foreach (array_unique($roots) as $start) {
        $closure = dependency_closure($start, $symbols, $dependencies, $files);
        foreach ($closure['unresolved'] as $site) {
            $kind = (string) ($site['kind'] ?? '');
            if (!in_array($kind, ['variable_callable', 'dynamic_callback_argument', 'dynamic_function_exists', 'dynamic_class'], true)) {
                continue;
            }
            $caller = (string) ($site['symbol'] ?? '');
            $expression = (string) ($site['expression'] ?? '');
            $signature = $caller . '|' . $kind . '|' . $expression;
            $sites[$signature] = [
                'caller' => $caller,
                'kind' => $kind,
                'expression' => $expression,
                'line' => (int) ($site['line'] ?? 0),
            ];
        }
    }
    ksort($sites, SORT_STRING);
    return array_values($sites);
}

/**
 * Compare reachable dynamic sites against the reviewed signatures.
 *
 * @param list<array{caller:string,kind:string,expression:string,line:int}> $sites Current scanner results.
 * @return array{unexpected:list<string>,stale:list<string>} Added and no-longer-reachable signatures.
 */
function runtime_dynamic_dependency_policy_drift(array $sites): array
{
    $actual = [];
    foreach ($sites as $site) {
        $actual[] = $site['caller'] . '|' . $site['kind'] . '|' . $site['expression'];
    }
    $expected = runtime_dynamic_dependency_reviewed_sites();
    return [
        'unexpected' => array_values(array_diff($actual, array_keys($expected))),
        'stale' => array_values(array_diff(array_keys($expected), $actual)),
    ];
}

/**
 * Find string-built callback targets that have no source declaration.
 *
 * @param string $root Absolute repository root.
 * @return array<string,list<string>> Callers mapped to missing target declarations.
 */
function runtime_dynamic_dependency_missing_targets(string $root): array
{
    $files = array_filter(scan_files($root), static fn(array $file): bool => str_starts_with($file['path'], 'app/'));
    $symbols = build_symbol_index($files)['symbols'];
    $missing = [];
    foreach (runtime_dynamic_dependency_callback_targets() as $caller => $targets) {
        foreach ($targets as $target) {
            if (!isset($symbols[strtolower(ltrim($target, '\\'))])) {
                $missing[$caller][] = $target;
            }
        }
    }
    return $missing;
}

/**
 * Print the reachable dynamic-site review or verify its checked-in signatures.
 *
 * @param list<string> $arguments Optional --discover mode to print current signatures.
 * @return int Exit status for valid policy or newly discovered/stale sites.
 */
function main(array $arguments): int
{
    if ($arguments !== [] && $arguments !== ['--discover']) {
        fwrite(STDERR, "Usage: php scripts/runtime_dynamic_dependencies.php [--discover]\n");
        return 2;
    }
    $sites = runtime_dynamic_dependency_sites(dirname(__DIR__));
    if ($arguments === ['--discover']) {
        foreach ($sites as $site) {
            fwrite(STDOUT, $site['caller'] . '|' . $site['kind'] . '|' . $site['expression'] . "\n");
        }
        return 0;
    }
    $drift = runtime_dynamic_dependency_policy_drift($sites);
    $missingTargets = runtime_dynamic_dependency_missing_targets(dirname(__DIR__));
    if ($drift['unexpected'] !== [] || $drift['stale'] !== [] || $missingTargets !== []) {
        foreach ($drift['unexpected'] as $signature) {
            fwrite(STDERR, 'Unreviewed runtime dynamic dependency: ' . $signature . "\n");
        }
        foreach ($drift['stale'] as $signature) {
            fwrite(STDERR, 'Stale runtime dynamic dependency review: ' . $signature . "\n");
        }
        foreach ($missingTargets as $caller => $targets) {
            foreach ($targets as $target) {
                fwrite(STDERR, 'Missing explicit dynamic callback target: ' . $caller . ' -> ' . $target . "\n");
            }
        }
        return 1;
    }
    fwrite(STDOUT, 'Runtime dynamic dependency review current: ' . count($sites) . " reachable sites.\n");
    return 0;
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    try {
        exit(main(array_slice($argv, 1)));
    } catch (\Throwable $exception) {
        fwrite(STDERR, 'Runtime dynamic dependency review failed: ' . $exception->getMessage() . "\n");
        exit(1);
    }
}
