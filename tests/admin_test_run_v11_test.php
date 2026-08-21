<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_test_run_v11_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Verifies Admin Test Run v1.1.3 tracing, bounded analysis, correlation, privacy, and report hardening.
 *
 * Responsibilities:
 *   - Verify ultra-early tracing remains dormant on ordinary requests
 *   - Verify cache inventory is single-pass and bounded
 *   - Verify SQL hotspot, browser cache, maintenance, post-response, and analysis aggregation
 *   - Verify privacy-safe URL/header handling and OPcache capability classification
 *   - Verify report retention/finalization wiring stays bounded and authenticated
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
 * Throw when one Admin Test Run v1.1.3 regression assertion fails.
 */
function assert_admin_test_run_v11(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

require_once __DIR__ . '/../app/diagnostics/admin_test_run_early.php';
require_once __DIR__ . '/../app/services/admin_test_runs.php';
require_once __DIR__ . '/../app/services/admin_test_run_analysis.php';

// Ordinary requests must not allocate the early trace merely because the feature exists.
unset($GLOBALS['gallery_admin_test_run_early']);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/?page=gallery&gallery=example';
$_GET = ['page' => 'gallery', 'gallery' => 'example'];
$_COOKIE = [];
\Gallery\Diagnostics\admin_test_run_early_init(dirname(__DIR__));
assert_admin_test_run_v11(
    !isset($GLOBALS['gallery_admin_test_run_early']),
    'Ultra-early tracing must stay dormant for ordinary requests without an active Test Run cookie.'
);

$earlySource = (string) file_get_contents(__DIR__ . '/../app/diagnostics/admin_test_run_early.php');
$bootstrapSource = (string) file_get_contents(__DIR__ . '/../app/bootstrap.php');
$controllerSource = (string) file_get_contents(__DIR__ . '/../app/controllers/admin_test_runs.php');
$serviceSource = (string) file_get_contents(__DIR__ . '/../app/services/admin_test_runs.php');
$analysisSource = (string) file_get_contents(__DIR__ . '/../app/services/admin_test_run_analysis.php');
$thumbnailMaintenanceSource = (string) file_get_contents(__DIR__ . '/../app/services/thumbnail_maintenance.php');
$browserSource = (string) file_get_contents(__DIR__ . '/../public/assets/gallery-modules/admin-test-run.js');
$gallerySource = (string) file_get_contents(__DIR__ . '/../public/assets/gallery.js');

foreach (['entrypoint', 'configuration', 'helpers', 'database', 'security', 'migrations', 'services', 'views', 'integrity', 'controllers', 'routing_bootstrap', 'session_bootstrap', 'request_bootstrap', 'maintenance_bootstrap', 'dispatch_bootstrap'] as $phase) {
    assert_admin_test_run_v11(
        str_contains($earlySource . $bootstrapSource, "'{$phase}'") || str_contains($bootstrapSource, "'{$phase}' =>"),
        "Missing early bootstrap phase {$phase}."
    );
}
assert_admin_test_run_v11(
    str_contains($controllerSource, "admin_test_run_request_begin_for_token(\$token, 'starter')")
        && str_contains($controllerSource, 'test_run_starter_request_id')
        && str_contains($serviceSource, 'X-Gallery-Test-Request-ID')
        && str_contains($serviceSource, 'Server-Timing: gallery-php'),
    'Starter tracing and browser/PHP request correlation headers must be wired.'
);
assert_admin_test_run_v11(
    str_contains($controllerSource, 'cms_admin_test_run_finalize')
        && str_contains($controllerSource, 'Clearing the cookie here makes the subsequent report assembly request intentionally untraced')
        && str_contains($browserSource, 'await new Promise((resolve) => window.setTimeout(resolve, 250))'),
    'Browser payload storage must complete its traced shutdown before the separate final report assembly request.'
);

assert_admin_test_run_v11(
    \Gallery\Services\ADMIN_TEST_RUN_DIAGNOSTICS_VERSION === '20260821-admin-test-run-v1.1.3'
        && str_contains($browserSource, "diagnostics_version: '20260821-admin-test-run-browser-v1.1.3'")
        && str_contains($gallerySource, "admin-test-run.js?v=20260821-admin-test-run-v1.1.3"),
    'The PHP report, browser payload, and module cache-buster must identify Admin Test Run v1.1.3 consistently.'
);

// Cache inventory must derive all family totals from one bounded traversal.
$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gallery-test-run-v11-' . bin2hex(random_bytes(4));
mkdir($tempRoot . DIRECTORY_SEPARATOR . 'updates', 0777, true);
mkdir($tempRoot . DIRECTORY_SEPARATOR . 'github-api', 0777, true);
for ($i = 0; $i < 130; $i++) {
    $family = $i % 2 === 0 ? 'updates' : 'github-api';
    file_put_contents($tempRoot . DIRECTORY_SEPARATOR . $family . DIRECTORY_SEPARATOR . 'item-' . $i . '.bin', str_repeat('x', 16));
}
$inventory = \Gallery\Services\admin_test_run_cache_inventory_single_pass($tempRoot, 100, 5000.0);
assert_admin_test_run_v11(
    (int) ($inventory['traversal_count'] ?? 0) === 1
        && !empty($inventory['truncated'])
        && ($inventory['truncation_reason'] ?? '') === 'entry_cap'
        && isset($inventory['families']['updates'], $inventory['families']['github-api']),
    'Cache inventory must be one traversal with explicit entry/time bounds and per-subtree totals.'
);
$preflight = \Gallery\Services\admin_test_run_cache_preflight($tempRoot);
assert_admin_test_run_v11(
    ($preflight['mode'] ?? '') === 'non_recursive_preflight'
        && (int) ($preflight['recursive_entries_visited'] ?? -1) === 0,
    'Pre-measurement cache inspection must remain non-recursive.'
);

// Remove the isolated test tree without exercising production retention helpers.
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tempRoot, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iterator as $item) {
    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
}
rmdir($tempRoot);

$queries = [];
for ($i = 0; $i < 14; $i++) {
    $queries[] = [
        'operation' => 'select',
        'table' => 'app_settings',
        'fingerprint' => 'fp-setting',
        'shape' => 'SELECT setting_value FROM app_settings WHERE setting_key = ?',
        'elapsed_ms' => 0.4 + ($i / 100),
        'ok' => true,
        'callsite' => ['file' => 'app/services/example.php', 'line' => 55, 'function' => 'load_setting'],
    ];
}
for ($i = 0; $i < 12; $i++) {
    $queries[] = [
        'operation' => 'select',
        'table' => 'information_schema.COLUMNS',
        'fingerprint' => 'fp-schema',
        'shape' => 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        'elapsed_ms' => 0.7,
        'ok' => true,
        'callsite' => ['file' => 'app/database.php', 'line' => 900, 'function' => 'column_exists'],
    ];
}
$queries[] = [
    'operation' => 'insert',
    'table' => 'image_thumbnail_variants',
    'fingerprint' => 'fp-thumb-upsert',
    'shape' => 'INSERT INTO image_thumbnail_variants (...) VALUES (...) ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)',
    'elapsed_ms' => 1.2,
    'ok' => true,
    'callsite' => ['file' => 'app/services/thumbnails.php', 'line' => 100, 'function' => 'store_variant'],
];
$request = [
    'request_id' => 'req-1',
    'request' => ['uri' => '/?page=thumb&id=1'],
    'db' => [
        'query_count_total' => count($queries),
        'query_total_ms' => array_sum(array_column($queries, 'elapsed_ms')),
        'failed_count' => 0,
        'queries' => $queries,
        'transaction_events' => [],
    ],
    'components' => ['public_render_profile' => ['counters' => ['rendered_images' => 10, 'rendered_subgalleries' => 2]]],
];
$sql = \Gallery\Services\admin_test_run_sql_hotspot_analysis([$request]);
assert_admin_test_run_v11(
    (int) ($sql['query_count_declared'] ?? 0) === count($queries)
        && (int) ($sql['operation_distribution']['schema_inspection'] ?? 0) === 12
        && count((array) ($sql['top_fingerprints_by_count'] ?? [])) >= 2
        && count((array) ($sql['possible_n_plus_one_candidates'] ?? [])) >= 1,
    'SQL aggregation must report counts/timing/fingerprints, schema inspection, and conservative possible N+1 candidates.'
);
$writes = (array) ($sql['write_side_effects'] ?? []);
assert_admin_test_run_v11(
    ($writes[0]['classification'] ?? '') === 'expected_diagnostic_or_application_side_effect',
    'Idempotent thumbnail metadata writes must remain visible but classified as expected side effects.'
);

$distributedRequests = [];
for ($requestIndex = 0; $requestIndex < 2; $requestIndex++) {
    $distributedQueries = [];
    for ($queryIndex = 0; $queryIndex < 6; $queryIndex++) {
        $distributedQueries[] = [
            'operation' => 'select',
            'table' => 'galleries',
            'fingerprint' => 'fp-distributed-gallery',
            'shape' => 'SELECT * FROM galleries WHERE id = ?',
            'elapsed_ms' => 0.25,
            'ok' => true,
            'callsite' => ['file' => 'app/services/gallery.php', 'line' => 50, 'function' => 'gallery_by_id'],
        ];
    }
    $distributedRequests[] = [
        'request_id' => 'distributed-' . $requestIndex,
        'request' => ['uri' => '/?page=gallery&probe=' . $requestIndex],
        'db' => [
            'query_count_total' => count($distributedQueries),
            'failed_count' => 0,
            'queries' => $distributedQueries,
            'transaction_events' => [],
        ],
        'components' => [],
    ];
}
$distributedSql = \Gallery\Services\admin_test_run_sql_hotspot_analysis($distributedRequests);
assert_admin_test_run_v11(
    (int) ($distributedSql['top_fingerprints_by_count'][0]['count'] ?? 0) === 12
        && empty($distributedSql['possible_n_plus_one_candidates']),
    'Whole-run fingerprint repetition must not become a possible N+1 finding when no individual request crosses the per-request threshold.'
);

$browser = [
    'after_probes' => [
        'resources' => [
            ['transfer_size' => 1000, 'encoded_body_size' => 900, 'decoded_body_size' => 1500, 'initiator_type' => 'script'],
            ['transfer_size' => 0, 'encoded_body_size' => 500, 'decoded_body_size' => 700, 'initiator_type' => 'img', 'probable_cache_hit' => true],
            ['transfer_size' => 0, 'encoded_body_size' => 0, 'decoded_body_size' => 0, 'initiator_type' => 'other'],
        ],
    ],
];
$browserSummary = \Gallery\Services\admin_test_run_browser_cache_summary($browser);
assert_admin_test_run_v11(
    (int) ($browserSummary['resource_count'] ?? 0) === 3
        && (int) ($browserSummary['network_count'] ?? 0) === 1
        && (int) ($browserSummary['browser_cache_count'] ?? 0) === 1
        && (int) ($browserSummary['unknown_count'] ?? 0) === 1,
    'Top-level browser cache summary must agree with raw Resource Timing resources.'
);

$cacheControl = \Gallery\Services\admin_test_run_cache_control_analysis([
    'cache-control' => 'public, max-age=31536000, immutable, max-age=600',
]);
assert_admin_test_run_v11(
    !empty($cacheControl['conflict'])
        && in_array('conflicting_duplicate_max-age', (array) ($cacheControl['reasons'] ?? []), true),
    'Browser-observed Cache-Control must flag duplicate directives with conflicting values.'
);

$filtered = \Gallery\Services\admin_test_run_filter_provider_headers([
    'Server' => 'WEDOS',
    'X-Location' => 'node-1',
    'Set-Cookie' => 'secret=value',
    'Authorization' => 'Bearer secret',
    'X-Random-Internal' => 'do-not-store',
]);
assert_admin_test_run_v11(
    ($filtered['server'] ?? '') === 'WEDOS'
        && ($filtered['x-location'] ?? '') === 'node-1'
        && !isset($filtered['set-cookie'], $filtered['authorization'], $filtered['x-random-internal']),
    'Provider metadata must use a bounded allowlist and never retain credential-bearing headers.'
);
$sanitized = \Gallery\Services\admin_test_run_sanitize_url('/?page=gallery&token=abc&csrf_token=def&foo=ok');
assert_admin_test_run_v11(
    str_contains($sanitized, 'foo=ok') && !str_contains($sanitized, 'abc') && !str_contains($sanitized, 'def'),
    'Diagnostic URLs must redact token/CSRF-like query values.'
);

$opaqueToken = str_repeat('a', 32);
$publicRunId = \Gallery\Services\admin_test_run_public_run_id($opaqueToken);
assert_admin_test_run_v11(
    strlen($publicRunId) === 8 && $publicRunId !== substr($opaqueToken, 0, 8),
    'The downloadable report identifier must be derived from the token rather than exposing a token prefix.'
);
$redactedText = \Gallery\Services\admin_test_run_sanitize_text('Location: /?page=gallery&test_run_token=' . $opaqueToken . '&foo=ok');
$redactedReport = \Gallery\Services\admin_test_run_redact_exact_token([
    'starter_header' => 'Location: /?test_run_token=' . $opaqueToken,
    'component' => ['request_uri' => '/?page=gallery&test_run_token=' . $opaqueToken],
    'safe_hash' => hash('sha256', $opaqueToken),
], $opaqueToken);
$redactedJson = json_encode($redactedReport, JSON_UNESCAPED_SLASHES);
assert_admin_test_run_v11(
    !str_contains($redactedText, $opaqueToken)
        && is_string($redactedJson)
        && !str_contains($redactedJson, $opaqueToken)
        && str_contains($redactedJson, '[REDACTED]'),
    'Starter Location headers, nested request URIs, and the final report must never retain the opaque Test Run token.'
);
assert_admin_test_run_v11(
    str_contains($serviceSource, 'admin_test_run_redact_exact_token($report, $token)')
        && str_contains($serviceSource, 'admin_test_run_redact_storage_run_ids($report)'),
    'Final report assembly must redact the current token and all historical opaque Test Run storage identifiers before analysis and artifact publication.'
);
$historicalToken = str_repeat('b', 32);
$historicalPaths = \Gallery\Services\admin_test_run_redact_storage_run_ids([
    'relative' => 'admin-test-runs/' . $historicalToken . '/requests/request.json',
    'absolute_like' => 'cache\\admin-test-runs\\' . $historicalToken . '\\report.json',
]);
$historicalJson = json_encode($historicalPaths, JSON_UNESCAPED_SLASHES);
assert_admin_test_run_v11(
    is_string($historicalJson)
        && !str_contains($historicalJson, $historicalToken)
        && substr_count($historicalJson, '[REDACTED]') === 2,
    'Retained pre-v1.1.2 admin-test-runs directory tokens must never leak through semantic cache artifact paths.'
);
assert_admin_test_run_v11(
    str_contains($thumbnailMaintenanceSource, 'thumbnail_maintenance_summary_cache_clear_diagnostic')
        && str_contains($thumbnailMaintenanceSource, "'set_generation_setting'")
        && str_contains($thumbnailMaintenanceSource, "'delete_last_check_setting'")
        && str_contains($thumbnailMaintenanceSource, "'admin_storage_statistics_cache_clear'")
        && str_contains($thumbnailMaintenanceSource, "'gallery_map_cache_clear_all'")
        && str_contains($serviceSource, "'thumbnail_maintenance_summary' => __NAMESPACE__ . '\\\\thumbnail_maintenance_summary_cache_clear_diagnostic'")
        && str_contains($serviceSource, "\$actions[\$name]['details'] = \$callbackResult"),
    'Test Run safe-cache invalidation must expose nested thumbnail-maintenance timing without adding detailed timing to ordinary cache-clear calls.'
);

$opcache = \Gallery\Services\admin_test_run_opcache_capability();
assert_admin_test_run_v11(
    in_array((string) ($opcache['status_access'] ?? ''), ['available', 'restricted', 'unavailable'], true)
        && array_key_exists('extension_loaded', $opcache),
    'OPcache diagnostics must expose capability state rather than assuming status API access.'
);

$lockAssessment = \Gallery\Services\admin_test_run_lock_assessment([
    'exists' => true,
    'busy' => true,
    'mtime' => time() - 1200,
], 300);
assert_admin_test_run_v11(!empty($lockAssessment['possible_stale']), 'Old busy maintenance locks must receive a stale-lock assessment.');

$maintenanceNow = strtotime('2026-08-21 12:00:00 UTC');
$maintenanceDue = \Gallery\Services\admin_test_run_site_maintenance_due_analysis(
    [
        'due' => true,
        'within_window' => false,
        'date' => '2026-08-21',
        'scheduled_at' => '2026-08-21 02:00:00',
    ],
    ['status' => 'complete', 'cycle_date' => '2026-08-21'],
    true,
    '2026-08-21',
    '2026-08-21 02:09:44',
    is_int($maintenanceNow) ? $maintenanceNow : time()
);
assert_admin_test_run_v11(
    !empty($maintenanceDue['schedule_due_raw'])
        && !empty($maintenanceDue['already_completed_current_cycle'])
        && empty($maintenanceDue['currently_due'])
        && empty($maintenanceDue['is_running'])
        && str_starts_with((string) ($maintenanceDue['next_expected_or_due_time'] ?? ''), '2026-08-22'),
    'A completed daily maintenance cycle must not remain classified as currently due or active merely because the schedule time has passed.'
);
assert_admin_test_run_v11(
    !\Gallery\Services\admin_test_run_maintenance_task_active(['currently_active_job' => ['status' => 'complete']])
        && \Gallery\Services\admin_test_run_maintenance_task_active(['currently_active_job' => ['status' => 'running']]),
    'Active-background counting must ignore completed job state while retaining genuinely running state.'
);

$cron = \Gallery\Services\admin_test_run_cron_and_maintenance_snapshot([]);
assert_admin_test_run_v11(
    !empty($cron['observation_only'])
        && empty($cron['destructive_or_heavy_tasks_executed_by_diagnostics'])
        && isset($cron['tasks']['site_maintenance'], $cron['tasks']['automatic_updater'], $cron['tasks']['admin_log_archive_maintenance'], $cron['tasks']['thumbnail_warmup'], $cron['tasks']['database_maintenance'])
        && !str_contains($analysisSource, 'site_maintenance_run([')
        && !str_contains($analysisSource, 'admin_log_archive_maintenance_run(['),
    'Cron/maintenance inventory must be first-class and observation-only.'
);

$postResponse = \Gallery\Services\admin_test_run_post_response_summary([[
    'request_id' => 'tail-1',
    'response_lifecycle' => [
        'logical_response_finished_at_unix' => 100.0,
        'shutdown_at_unix' => 102.8,
        'response_to_shutdown_ms' => 2800.0,
        'fastcgi_finish_request_called' => true,
    ],
]]);
assert_admin_test_run_v11(
    (float) ($postResponse['max_response_to_shutdown_ms'] ?? 0.0) === 2800.0,
    'Post-response worker-tail aggregation must preserve worker occupancy after browser response detachment.'
);

$sessionRequests = [];
for ($i = 0; $i < 8; $i++) {
    $base = 100.0 + $i;
    $sessionRequests[] = [
        'request_id' => 'thumb-session-' . $i,
        'request' => ['uri' => '/gallery/demo/image-' . $i . '/thumb-320.jpg'],
        'marks' => [
            ['name' => 'session_start_begin', 'at_unix' => $base, 'context' => ['save_handler' => 'files']],
            ['name' => 'session_start_end', 'at_unix' => $base + 0.120, 'context' => []],
            ['name' => 'request_initialize_end', 'at_unix' => $base + 0.121, 'context' => ['page' => 'public_thumb']],
            ['name' => 'read_only_media_session_release_end', 'at_unix' => $base + 0.125, 'context' => ['page' => 'public_thumb']],
        ],
    ];
}
$sessionSummary = \Gallery\Services\admin_test_run_session_lock_contention_summary($sessionRequests);
assert_admin_test_run_v11(
    (int) ($sessionSummary['high_fanout_media_request_count'] ?? 0) === 8
        && (int) ($sessionSummary['thumbnail_request_count'] ?? 0) === 8
        && (int) ($sessionSummary['session_start_ms']['requests_at_or_above_50_ms'] ?? 0) === 8
        && (int) ($sessionSummary['early_session_release']['observed_media_requests'] ?? 0) === 8
        && (float) ($sessionSummary['early_session_release']['session_held_after_start_ms_max'] ?? 0.0) < 10.0
        && !empty($sessionSummary['probable_contention']),
    'Session contention summary must detect clustered slow files-session starts and report early media session-release coverage without claiming exact lock-wait precision.'
);

$flagReport = [
    'browser_php_correlation' => ['rows' => [
        ['estimated_outside_php_wait_ms' => 6000.0, 'source' => 'primary_navigation'],
        ['source' => 'starter_redirect', 'browser_redirect_phase_ms' => 1060.0, 'starter_php_request_ms' => 980.0, 'estimated_outside_php_starter_wait_ms' => 80.0],
    ]],
    'starter' => ['preparation' => ['duration_ms' => 900.0]],
    'request_lifecycle' => ['active_unfinished_count' => 1],
    'request_concurrency' => ['peak_concurrent_php_requests' => 2],
    'session_lock_contention' => $sessionSummary,
    'database_summary' => ['failed_count' => 0, 'prepare_failed_count' => 0, 'transaction_failed_count' => 0, 'all_traced_transactions_closed' => true, 'query_count' => 20],
    'sql_hotspots' => [
        'query_count_declared' => 600,
        'possible_n_plus_one_candidates' => [],
        'operation_distribution' => ['schema_inspection' => 120],
        'write_side_effects' => [],
        'per_request' => [
            'healthy-1' => ['query_count_declared' => 200, 'schema_inspection_count' => 40],
            'healthy-2' => ['query_count_declared' => 200, 'schema_inspection_count' => 40],
            'healthy-3' => ['query_count_declared' => 200, 'schema_inspection_count' => 40],
        ],
    ],
    'post_response_worker_tail' => ['max_response_to_shutdown_ms' => 2800.0],
    'cache' => [
        'clear_result' => [
            'total_ms' => 758.0,
            'actions' => [
                'thumbnail_maintenance_summary' => [
                    'elapsed_ms' => 757.0,
                    'details' => [
                        'steps' => [
                            'set_generation_setting' => ['elapsed_ms' => 750.0],
                        ],
                    ],
                ],
            ],
        ],
        'after_run' => ['families' => ['application_cache' => ['bytes' => 0]], 'truncated' => false],
    ],
    'cron_and_maintenance' => [
        'active_background_subsystem_count' => 0,
        'tasks' => [
            'site_maintenance' => [
                'self_loopback_chain_capable' => true,
                'can_execute_after_response' => true,
                'overdue' => false,
                'current_test_run_attempted_or_scheduled_it' => [],
            ],
        ],
    ],
    'runtime_finalizer' => ['opcache' => ['status_access' => 'restricted']],
    'browser' => [
        'probes' => [[
            'name' => 'first_thumbnail_probe',
            'url' => '/thumb-test',
            'provider_headers' => ['cache-control' => 'public, max-age=31536000, immutable, max-age=600'],
        ]],
    ],
    'requests' => [],
];
$flags = \Gallery\Services\admin_test_run_analysis_flags($flagReport);
$codes = array_column($flags, 'code');
assert_admin_test_run_v11(
    in_array('outside_php_wait_very_large', $codes, true)
        && in_array('unfinished_php_request', $codes, true)
        && in_array('post_response_worker_tail', $codes, true)
        && in_array('opcache_status_restricted', $codes, true)
        && in_array('conflicting_cache_control', $codes, true)
        && in_array('maintenance_self_loopback_capable', $codes, true)
        && in_array('starter_php_slow', $codes, true)
        && in_array('php_session_lock_contention', $codes, true)
        && !in_array('sql_query_count_high', $codes, true)
        && !in_array('schema_inspection_repeated', $codes, true),
    'Automatic analysis flags must identify conservative network/PHP lifecycle/cache/maintenance anomalies without aggregate SQL false positives.'
);
foreach ($flags as $flag) {
    assert_admin_test_run_v11(
        in_array((string) ($flag['severity'] ?? ''), ['info', 'warning', 'critical'], true)
            && (string) ($flag['rationale'] ?? '') !== '',
        'Every automatic analysis flag must carry severity and rationale.'
    );
}

assert_admin_test_run_v11(
    str_contains($serviceSource, 'ADMIN_TEST_RUN_MAX_REPORTS')
        && str_contains($serviceSource, 'ADMIN_TEST_RUN_CLEANUP_ENTRY_CAP')
        && str_contains($serviceSource, 'admin_test_run_cleanup_intermediates')
        && str_contains($serviceSource, 'admin_test_run_owned_by_current_admin'),
    'Test Run storage cleanup/retention and report ownership checks must remain bounded and explicit.'
);

fwrite(STDOUT, "Admin Test Run v1.1.3 regression tests passed.\n");
