<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_test_run_analysis/flags.php
 * Module Type: Service
 *
 * Purpose:
 *   Aggregates every analysis pass into ranked diagnostic flags.
 *
 * Responsibilities:
 *   - Run the analysis passes over a finished report
 *   - Emit severity-ranked flags with bounded evidence and rationale
 *   - Keep flag codes stable so Admin presentation can rely on them
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
 *   - Loaded by app/services/admin_test_run_analysis.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/admin_test_run_analysis.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;

/**
 * Add one structured analysis flag.
 *
 * @param array<int,array<string,mixed>> $flags Flag accumulator.
 * @param array<string,mixed> $evidence Evidence payload.
 */
function admin_test_run_add_analysis_flag(array &$flags, string $severity, string $code, string $message, array $evidence = [], string $rationale = ''): void
{
    $flags[] = [
        'severity' => in_array($severity, ['info', 'warning', 'critical'], true) ? $severity : 'info',
        'code' => $code,
        'message' => $message,
        'evidence' => $evidence,
        'rationale' => $rationale,
    ];
}

/**
 * Build conservative automatic analysis flags from a nearly-final report.
 *
 * @param array<string,mixed> $report Report payload.
 * @return array<int,array<string,mixed>>
 */
function admin_test_run_analysis_flags(array $report): array
{
    $flags = [];
    $correlation = is_array($report['browser_php_correlation']['rows'] ?? null) ? $report['browser_php_correlation']['rows'] : [];
    foreach ($correlation as $row) {
        if (!is_array($row)) continue;
        $outside = $row['estimated_outside_php_wait_ms'] ?? $row['estimated_outside_php_starter_wait_ms'] ?? null;
        $php = $row['php_before_response_ms'] ?? $row['starter_php_request_ms'] ?? null;
        if (is_numeric($outside) && (float) $outside >= 5000.0) {
            admin_test_run_add_analysis_flag($flags, 'critical', 'outside_php_wait_very_large', 'Browser-observed latency contains a very large interval not explained by measured PHP execution.', [
                'source' => $row['source'] ?? '', 'estimated_outside_php_wait_ms' => (float) $outside, 'php_ms' => is_numeric($php) ? (float) $php : null,
            ], '5 seconds outside measured PHP is far beyond normal application execution jitter and is consistent with origin/proxy/CDN/network queueing or connection delay.');
        } elseif (is_numeric($outside) && (float) $outside >= 1000.0 && (!is_numeric($php) || (float) $outside > (float) $php)) {
            admin_test_run_add_analysis_flag($flags, 'warning', 'outside_php_wait_large', 'Browser TTFB contains at least about one second not explained by measured PHP execution.', [
                'source' => $row['source'] ?? '', 'estimated_outside_php_wait_ms' => (float) $outside, 'php_ms' => is_numeric($php) ? (float) $php : null,
            ], 'The estimate is intentionally conservative and includes network/proxy clock-domain uncertainty.');
        }
    }

    $probes = is_array($report['browser']['probes'] ?? null) ? $report['browser']['probes'] : [];
    $staticTtfb = null;
    $phpTtfb = null;
    foreach ($probes as $probe) {
        if (!is_array($probe)) continue;
        if (($probe['name'] ?? '') === 'static_asset_probe') $staticTtfb = (float) ($probe['ttfb_like_ms'] ?? 0.0);
        if (($probe['name'] ?? '') === 'php_probe') $phpTtfb = (float) ($probe['ttfb_like_ms'] ?? 0.0);
    }
    if ($staticTtfb !== null && $phpTtfb !== null && $staticTtfb >= 1000.0 && $staticTtfb > max($phpTtfb * 2.0, $phpTtfb + 750.0)) {
        admin_test_run_add_analysis_flag($flags, 'warning', 'static_resource_slower_than_php', 'A cache-busted static resource was substantially slower than the minimal PHP probe.', ['static_ttfb_ms' => $staticTtfb, 'php_ttfb_ms' => $phpTtfb], 'Static-file latency that materially exceeds minimal PHP latency points away from Gallery controller/database execution as the sole cause.');
    }

    $starter = null;
    foreach ($correlation as $row) {
        if (is_array($row) && ($row['source'] ?? '') === 'starter_redirect') {
            $starter = $row;
            break;
        }
    }
    if (is_array($starter) && (float) ($starter['browser_redirect_phase_ms'] ?? 0.0) >= 2000.0) {
        admin_test_run_add_analysis_flag($flags, (float) ($starter['browser_redirect_phase_ms'] ?? 0.0) >= 5000.0 ? 'critical' : 'warning', 'starter_redirect_slow', 'The Test Run starter/redirect phase was slow.', $starter, 'The starter is now traced separately so cache preparation, PHP execution, and outside-PHP delay can be distinguished.');
    }
    $starterPhpMs = is_array($starter) ? (float) ($starter['starter_php_request_ms'] ?? 0.0) : 0.0;
    if ($starterPhpMs >= 750.0) {
        $clearResult = is_array($report['cache']['clear_result'] ?? null) ? $report['cache']['clear_result'] : [];
        admin_test_run_add_analysis_flag(
            $flags,
            $starterPhpMs >= 2000.0 ? 'critical' : 'warning',
            'starter_php_slow',
            'The Test Run starter itself spent substantial time inside measured PHP execution.',
            [
                'starter_php_request_ms' => $starterPhpMs,
                'starter_preparation_ms' => (float) ($report['starter']['preparation']['duration_ms'] ?? 0.0),
                'safe_cache_invalidation_ms' => (float) ($clearResult['total_ms'] ?? 0.0),
                'safe_cache_invalidation_actions' => $clearResult['actions'] ?? [],
            ],
            '750 ms is a conservative warning threshold because the starter intentionally performs only bounded context creation, light cache preflight, and safe metadata-cache invalidation before redirect. Nested invalidation timings identify which existing cache-clear operation consumed the time.'
        );
    }

    $lifecycle = is_array($report['request_lifecycle'] ?? null) ? $report['request_lifecycle'] : [];
    if ((int) ($lifecycle['active_unfinished_count'] ?? 0) > 0) {
        admin_test_run_add_analysis_flag($flags, 'critical', 'unfinished_php_request', 'One or more traced PHP requests had not closed when the report was finalized.', ['count' => (int) $lifecycle['active_unfinished_count']], 'A finalized diagnostic run should normally have no active sidecars.');
    }

    $tail = is_array($report['post_response_worker_tail'] ?? null) ? $report['post_response_worker_tail'] : [];
    $maxTail = (float) ($tail['max_response_to_shutdown_ms'] ?? 0.0);
    if ($maxTail >= 3000.0) {
        admin_test_run_add_analysis_flag($flags, 'critical', 'post_response_worker_tail_large', 'A PHP worker remained occupied for multiple seconds after the response was logically finished/detached.', ['max_response_to_shutdown_ms' => $maxTail], 'fastcgi_finish_request()/LiteSpeed detachment can release the browser while the PHP/FPM worker remains busy until shutdown work ends.');
    } elseif ($maxTail >= 1000.0) {
        admin_test_run_add_analysis_flag($flags, 'warning', 'post_response_worker_tail', 'A PHP worker remained occupied for at least one second after the response was logically finished/detached.', ['max_response_to_shutdown_ms' => $maxTail], 'Long post-response tails can reduce shared-host worker availability even when browser TTFB appears good.');
    } elseif ($maxTail >= 250.0) {
        admin_test_run_add_analysis_flag($flags, 'info', 'post_response_worker_tail_noticeable', 'Noticeable post-response PHP work was observed.', ['max_response_to_shutdown_ms' => $maxTail], 'This is not necessarily harmful, but it is useful when diagnosing shared-host worker contention.');
    }

    $concurrency = (int) ($report['request_concurrency']['peak_concurrent_php_requests'] ?? 0);
    if ($concurrency >= 16) {
        admin_test_run_add_analysis_flag($flags, 'critical', 'php_concurrency_very_high', 'Very high overlapping PHP request concurrency was observed.', ['peak' => $concurrency], 'The Test Run probe runner itself remains sequential, so high PHP overlap comes from real page/resource behavior rather than the verification probe loop.');
    } elseif ($concurrency >= 8) {
        admin_test_run_add_analysis_flag($flags, 'warning', 'php_concurrency_high', 'High overlapping PHP request concurrency was observed.', ['peak' => $concurrency], 'A peak of 8+ PHP requests is conservative for shared hosting and materially above previously observed normal Test Run behavior.');
    }

    $sessionContention = is_array($report['session_lock_contention'] ?? null) ? $report['session_lock_contention'] : [];
    if (!empty($sessionContention['probable_contention'])) {
        $sessionTiming = is_array($sessionContention['session_start_ms'] ?? null) ? $sessionContention['session_start_ms'] : [];
        $severity = (int) ($sessionTiming['requests_at_or_above_200_ms'] ?? 0) >= 10 && $concurrency >= 16 ? 'critical' : 'warning';
        admin_test_run_add_analysis_flag(
            $flags,
            $severity,
            'php_session_lock_contention',
            'High-fanout media requests show probable PHP session-lock or session-storage contention.',
            [
                'media_request_count' => (int) ($sessionContention['high_fanout_media_request_count'] ?? 0),
                'thumbnail_request_count' => (int) ($sessionContention['thumbnail_request_count'] ?? 0),
                'session_start_ms' => $sessionTiming,
                'early_session_release' => $sessionContention['early_session_release'] ?? [],
                'peak_php_concurrency' => $concurrency,
                'save_handler_distribution' => $sessionContention['save_handler_distribution'] ?? [],
            ],
            'PHP does not expose pure session-lock wait time, but many overlapping media requests using the files handler with clustered slow session_start durations are a strong contention signal. Early session-release coverage shows whether read-only media requests stop holding the lock before authorization/path/derivative work.'
        );
    }
    $thumbnailFanout = (int) ($sessionContention['thumbnail_request_count'] ?? 0);
    if ($thumbnailFanout >= 32 && $concurrency >= 8) {
        admin_test_run_add_analysis_flag(
            $flags,
            $concurrency >= 16 ? 'warning' : 'info',
            'thumbnail_php_fanout',
            'One gallery load generated a large fanout of PHP-routed thumbnail requests.',
            [
                'thumbnail_request_count' => $thumbnailFanout,
                'peak_php_concurrency' => $concurrency,
                'session_release_coverage' => $sessionContention['early_session_release']['coverage_ratio'] ?? null,
            ],
            'Protected thumbnails intentionally remain PHP-authorized, but large browser fanout can consume scarce shared-host PHP workers. Early session release and reduced per-thumbnail metadata work limit the impact without bypassing access policy.'
        );
    }

    $db = is_array($report['database_summary'] ?? null) ? $report['database_summary'] : [];
    if ((int) ($db['failed_count'] ?? 0) > 0 || (int) ($db['prepare_failed_count'] ?? 0) > 0 || (int) ($db['transaction_failed_count'] ?? 0) > 0) {
        admin_test_run_add_analysis_flag($flags, 'critical', 'database_failure', 'One or more traced database operations failed.', [
            'query_failures' => (int) ($db['failed_count'] ?? 0), 'prepare_failures' => (int) ($db['prepare_failed_count'] ?? 0), 'transaction_failures' => (int) ($db['transaction_failed_count'] ?? 0),
        ], 'Database failures are never treated as normal performance variation.');
    }
    if (isset($db['all_traced_transactions_closed']) && !$db['all_traced_transactions_closed']) {
        admin_test_run_add_analysis_flag($flags, 'critical', 'unclosed_transaction', 'At least one traced database transaction did not record a matching commit or rollback.', ['balances' => $db['unclosed_transaction_balance_by_request'] ?? []], 'Open transactions can retain locks/resources and indicate interrupted request logic.');
    }

    $sql = is_array($report['sql_hotspots'] ?? null) ? $report['sql_hotspots'] : [];
    $highQueryRequests = [];
    $schemaHeavyRequests = [];
    foreach ((array) ($sql['per_request'] ?? []) as $requestId => $requestSql) {
        if (!is_array($requestSql)) {
            continue;
        }
        $requestQueryCount = (int) ($requestSql['query_count_declared'] ?? 0);
        if ($requestQueryCount >= 300) {
            $highQueryRequests[] = ['request_id' => (string) $requestId, 'query_count' => $requestQueryCount];
        }
        $requestSchemaCount = (int) ($requestSql['schema_inspection_count'] ?? 0);
        if ($requestSchemaCount >= 50) {
            $schemaHeavyRequests[] = ['request_id' => (string) $requestId, 'schema_inspection_count' => $requestSchemaCount];
        }
    }
    if ($highQueryRequests !== []) {
        admin_test_run_add_analysis_flag($flags, 'warning', 'sql_query_count_high', 'At least one PHP request executed a high number of SQL statements.', ['requests' => array_slice($highQueryRequests, 0, 10)], 'The threshold is applied per request, not to the multi-request Test Run aggregate. 300 queries is deliberately above the recent optimized 150-200 query Gallery range.');
    }
    if ($schemaHeavyRequests !== []) {
        admin_test_run_add_analysis_flag($flags, 'warning', 'schema_inspection_repeated', 'Schema-inspection SQL remains heavily repeated inside at least one PHP request.', ['requests' => array_slice($schemaHeavyRequests, 0, 10)], 'Per-request analysis avoids falsely inflating the finding merely because a Test Run contains several healthy requests.');
    }
    if (!empty($sql['possible_n_plus_one_candidates'])) {
        admin_test_run_add_analysis_flag($flags, 'info', 'possible_n_plus_one', 'A repeated SELECT fingerprint is concentrated inside one PHP request and may represent N+1 behavior.', ['candidate_count' => count((array) $sql['possible_n_plus_one_candidates']), 'top_candidates' => array_slice((array) $sql['possible_n_plus_one_candidates'], 0, 5)], 'Candidates are derived per request. Parameter values are not stored, so this remains a conservative possible-N+1 classification rather than a definitive error.');
    }
    $unexpectedWrites = array_values(array_filter((array) ($sql['write_side_effects'] ?? []), static fn ($row): bool => is_array($row) && ($row['classification'] ?? '') === 'unexpected_write'));
    if ($unexpectedWrites !== []) {
        admin_test_run_add_analysis_flag($flags, 'critical', 'unexpected_database_write', 'Test Run observed a write to content/identity/permission/vote/tag data.', ['count' => count($unexpectedWrites), 'writes' => array_slice($unexpectedWrites, 0, 20)], 'A diagnostic run must not intentionally mutate Gallery content, users, permissions, votes, tags, or galleries.');
    }

    $cache = is_array($report['cache']['after_run']['families']['application_cache'] ?? null) ? $report['cache']['after_run']['families']['application_cache'] : [];
    $cacheBytes = (int) ($cache['bytes'] ?? 0);
    if ($cacheBytes >= 1024 * 1024 * 1024) {
        admin_test_run_add_analysis_flag($flags, 'info', 'cache_large', 'Application cache is at least about 1 GiB.', ['bytes' => $cacheBytes], 'Large cache size is not automatically an error; updater artifacts may legitimately dominate. Semantic family totals should be reviewed before cleanup decisions.');
    }
    if (!empty($report['cache']['after_run']['truncated'])) {
        admin_test_run_add_analysis_flag($flags, 'warning', 'cache_inventory_truncated', 'Detailed cache inventory hit its explicit entry/time bound.', [
            'reason' => $report['cache']['after_run']['truncation_reason'] ?? '',
            'entries_visited' => $report['cache']['after_run']['entries_visited'] ?? 0,
            'scan_elapsed_ms' => $report['cache']['after_run']['scan_elapsed_ms'] ?? 0,
        ], 'The diagnostic intentionally stops rather than recursively scanning an unbounded cache tree.');
    }

    $maintenance = is_array($report['cron_and_maintenance'] ?? null) ? $report['cron_and_maintenance'] : [];
    foreach ((array) ($maintenance['tasks'] ?? []) as $name => $task) {
        if (!is_array($task)) continue;
        if (!empty($task['stale_lock_assessment']['possible_stale'])) {
            admin_test_run_add_analysis_flag($flags, 'warning', 'maintenance_stale_lock', 'A maintenance/background lock may be stale or abnormally long-running.', ['subsystem' => $name, 'assessment' => $task['stale_lock_assessment']], 'Kernel-busy locks are only called possibly stale after exceeding a conservative budget/lease threshold.');
        }
        if (!empty($task['overdue'])) {
            admin_test_run_add_analysis_flag($flags, 'warning', 'maintenance_overdue', 'A scheduled/background subsystem appears substantially overdue.', ['subsystem' => $name], 'Overdue classification uses the subsystem cadence and a conservative grace interval.');
        }
        if ($name === 'site_maintenance' && !empty($task['self_loopback_chain_capable'])) {
            admin_test_run_add_analysis_flag($flags, 'warning', 'maintenance_self_loopback_capable', 'Site maintenance still exposes a self-loopback web-cron continuation capability.', ['subsystem' => $name, 'can_execute_after_response' => $task['can_execute_after_response'] ?? null], 'The capability is reported even when it did not run. Self-HTTP continuation can consume scarce shared-host PHP workers and was previously identified as a hosting-risk pattern.');
        }
        if (!empty($task['public_anonymous_traffic_can_trigger']) && !empty($task['currently_active_job']) && $name === 'automatic_updater') {
            admin_test_run_add_analysis_flag($flags, 'warning', 'public_request_can_progress_active_updater', 'An active updater job can be progressed by normal public GET/HEAD traffic before response completion.', ['subsystem' => $name, 'budget' => $task['configured_execution_budget_seconds'] ?? null], 'This is bounded existing updater behavior, but on constrained shared hosting it can add worker occupancy to an otherwise normal public request.');
        }
        if (!empty($task['current_test_run_attempted_or_scheduled_it'])) {
            admin_test_run_add_analysis_flag($flags, 'info', 'maintenance_activity_during_test_run', 'A maintenance/background subsystem was actually considered, scheduled, or run during this Test Run.', ['subsystem' => $name, 'events' => $task['current_test_run_attempted_or_scheduled_it']], 'The diagnostic does not force maintenance; this records normal application behavior that happened to coincide with the run.');
        }
    }
    if ((int) ($maintenance['active_background_subsystem_count'] ?? 0) > 1) {
        admin_test_run_add_analysis_flag($flags, 'warning', 'multiple_background_subsystems_active', 'Multiple maintenance/background subsystems appear active concurrently.', ['count' => (int) $maintenance['active_background_subsystem_count']], 'Overlapping bounded jobs may still contend for a small shared-host PHP worker pool.');
    }

    foreach ((array) ($report['browser']['probes'] ?? []) as $probe) {
        if (!is_array($probe)) {
            continue;
        }
        $browserCacheControl = admin_test_run_cache_control_analysis((array) ($probe['provider_headers'] ?? []));
        if (!empty($browserCacheControl['conflict'])) {
            admin_test_run_add_analysis_flag($flags, 'warning', 'conflicting_cache_control', 'A browser-observed response contained conflicting Cache-Control directives.', [
                'source' => 'browser_probe',
                'probe' => (string) ($probe['name'] ?? ''),
                'url' => (string) ($probe['url'] ?? ''),
                'cache_control' => $browserCacheControl,
            ], 'Browser-observed headers include provider/CDN/proxy mutations that are not necessarily visible in PHP headers. Conflicting duplicate directive values, such as two different max-age values, are flagged.');
            break;
        }
    }

    foreach ((array) ($report['requests'] ?? []) as $request) {
        if (!is_array($request)) continue;
        $phpCacheControl = admin_test_run_cache_control_analysis((array) ($request['response']['headers'] ?? []));
        if (!empty($phpCacheControl['conflict'])) {
            admin_test_run_add_analysis_flag($flags, 'warning', 'conflicting_cache_control', 'A traced PHP response emitted conflicting Cache-Control semantics.', ['source' => 'php_headers', 'request_id' => $request['request_id'] ?? '', 'uri' => $request['request']['uri'] ?? '', 'cache_control' => $phpCacheControl], 'Conflicting public/private, no-store/cacheable, or duplicate directive values make cache behavior difficult to reason about.');
            break;
        }
        $startMemory = (int) ($request['process']['memory_usage_bytes'] ?? 0);
        $endMemory = (int) ($request['process_end']['memory_usage_bytes'] ?? 0);
        $delta = $endMemory - $startMemory;
        if ($delta >= 64 * 1024 * 1024) {
            admin_test_run_add_analysis_flag($flags, 'warning', 'request_memory_delta_large', 'A traced request retained/allocated an unusually large amount of PHP memory between instrumentation start and shutdown.', ['request_id' => $request['request_id'] ?? '', 'memory_delta_bytes' => $delta], '64 MiB is deliberately conservative relative to recent stable Gallery request memory around 20-22 MiB.');
        }
        $duration = (float) ($request['duration_from_request_ms'] ?? 0.0);
        if ($duration >= 5000.0) {
            admin_test_run_add_analysis_flag($flags, 'warning', 'php_request_duration_abnormal', 'A traced PHP request itself took at least five seconds from server request timestamp to shutdown.', ['request_id' => $request['request_id'] ?? '', 'duration_ms' => $duration, 'uri' => $request['request']['uri'] ?? ''], 'This flag applies to measured PHP request lifetime, unlike outside-PHP TTFB flags.');
        }
    }

    $opcache = is_array($report['runtime_finalizer']['opcache'] ?? null) ? $report['runtime_finalizer']['opcache'] : [];
    if (($opcache['status_access'] ?? '') === 'restricted') {
        admin_test_run_add_analysis_flag($flags, 'info', 'opcache_status_restricted', 'Zend OPcache is present but host policy restricts status introspection.', [], 'This is a diagnostics capability limitation, not an application PHP last_error.');
    }

    usort($flags, static function (array $a, array $b): int {
        $order = ['critical' => 3, 'warning' => 2, 'info' => 1];
        return ($order[$b['severity']] ?? 0) <=> ($order[$a['severity']] ?? 0);
    });
    return $flags;
}
