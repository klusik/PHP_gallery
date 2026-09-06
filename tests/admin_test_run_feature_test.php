<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_test_run_feature_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Verifies the opt-in administrator full test-run diagnostics contract.
 *
 * Responsibilities:
 *   - Keep the feature disabled by default
 *   - Require authenticated POST/CSRF start actions and expose the gallery control only when enabled
 *   - Keep diagnostic probes sequential and bounded to concurrency one
 *   - Preserve deep request/database/cache/process/concurrency instrumentation and ZIP reporting
 *   - Cover regular and Smart Gallery diagnostics without turning the runner into a stress test
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

/**
 * Throw when one Admin test-run contract fails.
 */
function assert_admin_test_run_feature(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

require_once __DIR__ . '/support/module_source.php';

$featureSource = (string) file_get_contents(__DIR__ . '/../app/services/feature_flags.php');
$layoutSource = (string) file_get_contents(__DIR__ . '/../app/views/layout.php');
// The test-run service is split into part files; assert against the whole module.
$serviceSource = module_source(__DIR__ . '/../app/services/admin_test_runs.php');
$controllerSource = (string) file_get_contents(__DIR__ . '/../app/controllers/admin_test_runs.php');
$databaseSource = (string) file_get_contents(__DIR__ . '/../app/database.php');
$smartSource = (string) file_get_contents(__DIR__ . '/../app/controllers/smart_galleries.php');
$browserSource = (string) file_get_contents(__DIR__ . '/../public/assets/gallery-modules/admin-test-run.js');

assert_admin_test_run_feature(
    preg_match("/'admin_test_runs'\\s*=>\\s*\\[.*?'default_enabled'\\s*=>\\s*false/s", $featureSource) === 1,
    'Admin test runs must be disabled by default for existing and new installations.'
);
assert_admin_test_run_feature(
    str_contains($featureSource, "'admin_test_run_start' => 'admin_test_runs'")
        && str_contains($featureSource, "'admin_test_run_finish' => 'admin_test_runs'")
        && str_contains($featureSource, "'admin_test_run_finalize' => 'admin_test_runs'")
        && str_contains($featureSource, "'admin_test_run_download' => 'admin_test_runs'"),
    'All Admin test-run routes must be owned by the opt-in feature flag.'
);
assert_admin_test_run_feature(
    str_contains($layoutSource, "in_array(\$page, ['gallery', 'smart_gallery'], true)")
        && str_contains($layoutSource, "feature_flag_enabled('admin_test_runs')")
        && str_contains($layoutSource, 'method="post"')
        && str_contains($layoutSource, 'name="csrf_token"')
        && str_contains($layoutSource, "url_for('admin_test_run_start')"),
    'Logged Admin gallery pages must expose a CSRF-protected POST Test run control only behind the feature flag.'
);
assert_admin_test_run_feature(
    str_contains($controllerSource, 'require_admin();')
        && str_contains($controllerSource, "request_method() !== 'POST'")
        && str_contains($controllerSource, 'verify_csrf();')
        && str_contains($controllerSource, 'admin_test_run_target_with_params'),
    'Starting/finalizing a run must stay authenticated, POST/CSRF protected, and force a cache-busted gallery reload.'
);
assert_admin_test_run_feature(
    str_contains($serviceSource, "'diagnostic_probe_concurrency' => 1")
        && str_contains($serviceSource, "'intentional_sleep_seconds' => 0")
        && str_contains($browserSource, 'runner_observed_max_probe_concurrency: 1')
        && str_contains($browserSource, 'runner_parallel_probe_calls: false')
        && !str_contains($browserSource, 'Promise.all(')
        && !preg_match('/\\bsleep\\s*\\(/', $serviceSource),
    'The diagnostic runner must remain sequential and must not manufacture worker starvation or tarpit delays.'
);
assert_admin_test_run_feature(
    str_contains($serviceSource, 'cache_inventory_before_clear')
        && str_contains($serviceSource, 'cache_inventory_after_clear')
        && str_contains($serviceSource, "'after_run' => \$cacheAfterRun")
        && str_contains($serviceSource, 'admin_test_run_subsystem_snapshot')
        && str_contains($serviceSource, 'admin_test_run_lock_snapshot')
        && str_contains($serviceSource, 'admin_test_run_runtime_snapshot')
        && str_contains($serviceSource, 'admin_test_run_concurrency_summary')
        && str_contains($serviceSource, 'admin_test_run_db_summary'),
    'The final report must retain before/after cache, subsystem, lock, runtime, concurrency, and database evidence.'
);
assert_admin_test_run_feature(
    str_contains($databaseSource, 'class AdminTestRunPDOStatement extends PDOStatement')
        && str_contains($databaseSource, "\\Gallery\\Services\\admin_test_run_active()")
        && str_contains($databaseSource, 'PDO::ATTR_STATEMENT_CLASS')
        && str_contains($databaseSource, 'admin_test_run_record_db_query'),
    'Database tracing must be comprehensive for the opt-in run while normal requests retain ordinary PDO.'
);
assert_admin_test_run_feature(
    str_contains($smartSource, "admin_test_run_record_component('smart_gallery'")
        && str_contains($smartSource, "admin_test_run_mark('smart_gallery_image_query_begin'")
        && str_contains($smartSource, "'query_page_size_cap' => SMART_GALLERY_QUERY_MAX_PAGE_SIZE"),
    'Smart Galleries must expose dynamic query/render and safety-cap diagnostics to the same test-run report.'
);
assert_admin_test_run_feature(
    str_contains($serviceSource, 'class_exists(ZipArchive::class)')
        && str_contains($serviceSource, "'request_lifecycle'")
        && str_contains($serviceSource, "'active_unfinished_count'")
        && str_contains($serviceSource, "'danger_threshold' => 32"),
    'The artifact must be ZIP-capable and explicitly report unfinished requests and dangerous observed concurrency.'
);

require_once __DIR__ . '/../app/services/admin_test_runs.php';

$normalized = \Gallery\Services\admin_test_run_normalize_target('/gallery/example/?foo=1&test_run_token=deadbeef&test_run_phase=x#section');
assert_admin_test_run_feature(
    $normalized === '/gallery/example/?foo=1#section',
    'Test-run control parameters must not become part of the durable target identity.'
);

$sqlShape = \Gallery\Services\admin_test_run_sql_shape("SELECT * FROM images WHERE id = 123 AND title = 'Private value'");
assert_admin_test_run_feature(
    !str_contains($sqlShape, '123') && !str_contains($sqlShape, 'Private value') && str_contains($sqlShape, '?'),
    'SQL diagnostics must retain shape while removing literal parameter values.'
);

$concurrency = \Gallery\Services\admin_test_run_concurrency_summary([
    ['request_id' => 'a', 'request_time_unix' => 10.0, 'finished_at_unix' => 12.0, 'process' => ['pid' => 1]],
    ['request_id' => 'b', 'request_time_unix' => 11.0, 'finished_at_unix' => 13.0, 'process' => ['pid' => 2]],
    ['request_id' => 'c', 'request_time_unix' => 13.0, 'finished_at_unix' => 14.0, 'process' => ['pid' => 2]],
]);
assert_admin_test_run_feature(
    (int) ($concurrency['peak_concurrent_php_requests'] ?? 0) === 2
        && (int) ($concurrency['distinct_pids'] ?? 0) === 2
        && (int) ($concurrency['diagnostic_runner_intended_probe_concurrency'] ?? 0) === 1,
    'Concurrency aggregation must distinguish observed PHP overlap from the runner fixed probe concurrency.'
);

fwrite(STDOUT, "Admin full test-run feature tests passed.\n");
