<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/public_search_diagnostics.php
 * Module Type: Service
 *
 * Purpose:
 *   Orchestrates admin-only performance diagnostics for progressive public search.
 *
 * Responsibilities:
 *   - Repeat the same public-search query across primary, media, descriptive, and deep phases
 *   - Aggregate phase and model-query latency without changing public relevance behavior
 *   - Request optimizer plans and bounded search-table metadata from the model layer
 *   - Produce one portable JSON report suitable for offline performance analysis
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
 *   - Keep comments and docstrings intact when modifying this file.
 *   - This service must not issue SQL directly. Database inspection belongs to app/models/public_search_diagnostics.php.
 *   - Diagnostics are explicit Admin actions and are never executed during normal public search.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;
use function Gallery\Core\now_sql;
use function Gallery\Models\public_search_diagnostics_database_snapshot;
use function Gallery\Models\public_search_diagnostics_explain_queries;
use function Gallery\Models\public_search_diagnostics_profile_start;
use function Gallery\Models\public_search_diagnostics_profile_stop;

const PUBLIC_SEARCH_DIAGNOSTICS_REPORT_VERSION = 1;
const PUBLIC_SEARCH_DIAGNOSTICS_DEFAULT_RUNS = 3;
const PUBLIC_SEARCH_DIAGNOSTICS_MAX_RUNS = 5;

/**
 * Normalize the requested benchmark repeat count.
 */
function public_search_diagnostics_normalize_runs(int $runs): int
{
    return max(1, min(PUBLIC_SEARCH_DIAGNOSTICS_MAX_RUNS, $runs));
}

/**
 * Run one complete progressive-search diagnostic report.
 *
 * @param string $query Search text entered by the administrator.
 * @param int $runs Number of repeated phase passes.
 * @param bool $includeExplain Whether captured statements should be explained.
 * @return array<string, mixed> Portable diagnostics report.
 */
function public_search_diagnostics_run(string $query, int $runs = PUBLIC_SEARCH_DIAGNOSTICS_DEFAULT_RUNS, bool $includeExplain = true): array
{
    $query = public_search_normalize_query($query);
    if (public_search_query_length($query) < 2) {
        throw new \InvalidArgumentException('Search diagnostics require at least two query characters.');
    }

    $runs = public_search_diagnostics_normalize_runs($runs);
    $phases = [
        PUBLIC_SEARCH_PHASE_PRIMARY,
        PUBLIC_SEARCH_PHASE_MEDIA,
        PUBLIC_SEARCH_PHASE_DESCRIPTIVE,
        PUBLIC_SEARCH_PHASE_DEEP,
    ];
    $reportStartedAt = hrtime(true);
    $phaseRuns = [];
    $allQueryRuns = [];
    $firstRunQueries = [];

    for ($run = 1; $run <= $runs; $run++) {
        foreach ($phases as $phase) {
            public_search_diagnostics_profile_start();
            $startedAt = hrtime(true);
            $results = [];
            $errorClass = '';
            try {
                $results = public_search_phase_results($phase, $query, 8, null);
            } catch (Throwable $exception) {
                $errorClass = get_class($exception);
            } finally {
                $queries = public_search_diagnostics_profile_stop();
            }

            $elapsedMs = max(0.0, (hrtime(true) - $startedAt) / 1_000_000);
            $dbElapsedMs = 0.0;
            foreach ($queries as $queryDiagnostic) {
                $dbElapsedMs += (float) ($queryDiagnostic['elapsed_ms'] ?? 0.0);
                $queryDiagnostic['run'] = $run;
                $queryDiagnostic['phase'] = $phase;
                $allQueryRuns[] = $queryDiagnostic;
                if ($run === 1) {
                    $firstRunQueries[] = $queryDiagnostic;
                }
            }

            $phaseRuns[] = [
                'run' => $run,
                'phase' => $phase,
                'elapsed_ms' => round($elapsedMs, 3),
                'db_elapsed_ms' => round($dbElapsedMs, 3),
                'php_overhead_ms' => round(max(0.0, $elapsedMs - $dbElapsedMs), 3),
                'query_count' => count($queries),
                'result_count' => count($results),
                'error_class' => $errorClass,
                'results' => public_search_diagnostics_result_summary($results),
            ];
        }
    }

    $report = [
        'report_type' => 'php_gallery_public_search_diagnostics',
        'report_version' => PUBLIC_SEARCH_DIAGNOSTICS_REPORT_VERSION,
        'generated_at' => now_sql(),
        'query' => $query,
        'runs' => $runs,
        'phase_order' => $phases,
        'search_state' => [
            'public_home_search_enabled' => public_home_search_enabled(),
            'ai_metadata_ready' => public_search_ai_metadata_ready(),
            'content_localization_enabled' => function_exists(__NAMESPACE__ . '\\content_localization_enabled') ? content_localization_enabled() : false,
            'active_language' => function_exists(__NAMESPACE__ . '\\translation_active_language') ? translation_active_language() : '',
        ],
        'phase_runs' => $phaseRuns,
        'phase_summary' => public_search_diagnostics_phase_summary($phaseRuns, $phases),
        'query_summary' => public_search_diagnostics_query_summary($allQueryRuns),
        'database' => public_search_diagnostics_database_snapshot(),
        'explain' => $includeExplain ? public_search_diagnostics_explain_queries($firstRunQueries) : [],
        'explain_included' => $includeExplain,
        'diagnostic_elapsed_ms' => round(max(0.0, (hrtime(true) - $reportStartedAt) / 1_000_000), 3),
        'notes' => [
            'Run 1 can include cold-cache effects; later runs are useful for warm-cache comparison.',
            'Table row counts come from information_schema and may be estimates for InnoDB.',
            'The report contains search text and matching public result titles but no database credentials.',
            'EXPLAIN measures optimizer planning only and is not EXPLAIN ANALYZE.',
        ],
    ];

    return $report;
}

/**
 * Return compact result identity/relevance details without full URLs or descriptions.
 *
 * @param array<int, array<string, mixed>> $results Public search result models.
 * @return array<int, array<string, mixed>> Bounded result summary.
 */
function public_search_diagnostics_result_summary(array $results): array
{
    $summary = [];
    foreach (array_slice($results, 0, 8) as $result) {
        $summary[] = [
            'key' => (string) ($result['key'] ?? ''),
            'type' => (string) ($result['type'] ?? ''),
            'title' => (string) ($result['title'] ?? ''),
            'rank' => (int) ($result['rank'] ?? 0),
            'match_source' => (string) ($result['match_source'] ?? ''),
        ];
    }
    return $summary;
}

/**
 * Aggregate repeated phase timings.
 *
 * @param array<int, array<string, mixed>> $phaseRuns Raw phase runs.
 * @param array<int, string> $phases Stable phase order.
 * @return array<string, array<string, int|float>> Phase statistics.
 */
function public_search_diagnostics_phase_summary(array $phaseRuns, array $phases): array
{
    $summary = [];
    foreach ($phases as $phase) {
        $elapsed = [];
        $dbElapsed = [];
        $resultCounts = [];
        foreach ($phaseRuns as $row) {
            if ((string) ($row['phase'] ?? '') !== $phase) {
                continue;
            }
            $elapsed[] = (float) ($row['elapsed_ms'] ?? 0.0);
            $dbElapsed[] = (float) ($row['db_elapsed_ms'] ?? 0.0);
            $resultCounts[] = (int) ($row['result_count'] ?? 0);
        }
        $summary[$phase] = array_merge(
            public_search_diagnostics_numeric_stats($elapsed),
            [
                'db_avg_ms' => round(public_search_diagnostics_average($dbElapsed), 3),
                'result_min' => $resultCounts === [] ? 0 : min($resultCounts),
                'result_max' => $resultCounts === [] ? 0 : max($resultCounts),
            ]
        );
    }
    return $summary;
}

/**
 * Aggregate model-query latency by stable query label.
 *
 * @param array<int, array<string, mixed>> $queries Captured query executions.
 * @return array<string, array<string, int|float>> Query statistics.
 */
function public_search_diagnostics_query_summary(array $queries): array
{
    $grouped = [];
    foreach ($queries as $query) {
        $label = (string) ($query['label'] ?? 'query');
        $grouped[$label][] = $query;
    }
    ksort($grouped, SORT_STRING);

    $summary = [];
    foreach ($grouped as $label => $rows) {
        $elapsed = [];
        $returned = [];
        $failures = 0;
        foreach ($rows as $row) {
            $elapsed[] = (float) ($row['elapsed_ms'] ?? 0.0);
            $returned[] = (int) ($row['rows_returned'] ?? 0);
            if (empty($row['ok'])) {
                $failures++;
            }
        }
        $summary[$label] = array_merge(
            ['executions' => count($rows), 'failures' => $failures],
            public_search_diagnostics_numeric_stats($elapsed),
            [
                'rows_min' => $returned === [] ? 0 : min($returned),
                'rows_max' => $returned === [] ? 0 : max($returned),
            ]
        );
    }
    return $summary;
}

/**
 * Return average/min/median/max timing metrics.
 *
 * @param array<int, float> $values Numeric values.
 * @return array<string, float>
 */
function public_search_diagnostics_numeric_stats(array $values): array
{
    if ($values === []) {
        return [
            'avg_ms' => 0.0,
            'min_ms' => 0.0,
            'median_ms' => 0.0,
            'max_ms' => 0.0,
        ];
    }
    sort($values, SORT_NUMERIC);
    $count = count($values);
    $middle = intdiv($count, 2);
    $median = $count % 2 === 1
        ? $values[$middle]
        : (($values[$middle - 1] + $values[$middle]) / 2);
    return [
        'avg_ms' => round(public_search_diagnostics_average($values), 3),
        'min_ms' => round((float) $values[0], 3),
        'median_ms' => round((float) $median, 3),
        'max_ms' => round((float) $values[$count - 1], 3),
    ];
}

/**
 * Return the arithmetic mean for a numeric list.
 *
 * @param array<int, float> $values Numeric values.
 */
function public_search_diagnostics_average(array $values): float
{
    if ($values === []) {
        return 0.0;
    }
    return array_sum($values) / count($values);
}

/**
 * Encode a portable pretty-printed diagnostic report.
 *
 * @param array<string, mixed> $report Diagnostic report.
 */
function public_search_diagnostics_encode_report(array $report): string
{
    $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    return is_string($encoded) ? $encoded : '{}';
}
