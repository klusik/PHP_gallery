<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/telemetry_daily_rollup_consistency_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects Stage 6 daily-rollup readiness diagnostics used before any future
 *   long-window report switch from hourly to daily aggregate storage.
 *
 * Responsibilities:
 *   - Verify completed-day hourly/daily comparisons classify exact matches
 *   - Verify missing daily rollups and semantic mismatches remain visible
 *   - Verify small floating-point representation differences use a bounded tolerance
 *   - Verify production queries exclude the current partial day
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

namespace Gallery\Models {
    /** @return array{hourly:array<int,array<string,mixed>>,daily:array<int,array<string,mixed>>} */
    function telemetry_model_report_daily_rollup_consistency(int $completedDays, string $trafficSegment = 'all'): array
    {
        return $GLOBALS['telemetry_rollup_consistency_fixture'] ?? ['hourly' => [], 'daily' => []];
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/services/telemetry.php';

    use function Gallery\Services\telemetry_report_daily_rollup_consistency;

    /** Throw when one daily-rollup consistency invariant fails. */
    function telemetry_rollup_consistency_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $GLOBALS['telemetry_rollup_consistency_fixture'] = [
        'hourly' => [
            ['metric_name' => 'public.page_views', 'sample_count' => 120, 'event_count' => 120, 'value_sum' => 120.0],
            ['metric_name' => 'photo.view_seconds', 'sample_count' => 50, 'event_count' => 50, 'value_sum' => 812.1234567],
        ],
        'daily' => [
            ['metric_name' => 'public.page_views', 'sample_count' => 120, 'event_count' => 120, 'value_sum' => 120.0],
            ['metric_name' => 'photo.view_seconds', 'sample_count' => 50, 'event_count' => 50, 'value_sum' => 812.1234568],
        ],
    ];
    $match = telemetry_report_daily_rollup_consistency(7, 'non_bot');
    telemetry_rollup_consistency_assert(($match['state'] ?? '') === 'match', 'Equivalent hourly/daily aggregates must produce a match state.');
    telemetry_rollup_consistency_assert((int) ($match['mismatch_count'] ?? -1) === 0, 'Equivalent aggregates must report zero mismatched metric families.');
    telemetry_rollup_consistency_assert(($match['metrics'][1]['status'] ?? '') === 'match', 'Bounded floating-point representation differences must not create false rollup mismatches.');

    $GLOBALS['telemetry_rollup_consistency_fixture'] = [
        'hourly' => [
            ['metric_name' => 'public.page_views', 'sample_count' => 90, 'event_count' => 90, 'value_sum' => 90.0],
        ],
        'daily' => [],
    ];
    $missing = telemetry_report_daily_rollup_consistency(7, 'all');
    telemetry_rollup_consistency_assert(($missing['state'] ?? '') === 'mismatch', 'Missing daily aggregates for sampled hourly metrics must block rollup readiness.');
    telemetry_rollup_consistency_assert(($missing['metrics'][0]['status'] ?? '') === 'daily_missing', 'Missing persisted daily data must be distinguished from an ordinary value mismatch.');

    $GLOBALS['telemetry_rollup_consistency_fixture'] = [
        'hourly' => [
            ['metric_name' => 'photo.views', 'sample_count' => 15, 'event_count' => 15, 'value_sum' => 15.0],
        ],
        'daily' => [
            ['metric_name' => 'photo.views', 'sample_count' => 15, 'event_count' => 14, 'value_sum' => 14.0],
        ],
    ];
    $mismatch = telemetry_report_daily_rollup_consistency(7, 'bot');
    telemetry_rollup_consistency_assert(($mismatch['metrics'][0]['status'] ?? '') === 'mismatch', 'Semantic count differences must remain visible as a mismatch.');
    telemetry_rollup_consistency_assert((int) ($mismatch['metrics'][0]['event_difference'] ?? 0) === 1, 'Rollup diagnostics must expose the signed event difference.');

    $GLOBALS['telemetry_rollup_consistency_fixture'] = ['hourly' => [], 'daily' => []];
    $empty = telemetry_report_daily_rollup_consistency(7, 'all');
    telemetry_rollup_consistency_assert(($empty['state'] ?? '') === 'no_samples', 'An empty completed-day window must report no samples rather than a false match.');

    $modelSource = (string) file_get_contents(dirname(__DIR__) . '/app/models/telemetry.php');
    telemetry_rollup_consistency_assert(
        str_contains($modelSource, 'function telemetry_model_report_daily_rollup_consistency')
            && substr_count($modelSource, 'AND bucket_start < CURDATE()') >= 1
            && substr_count($modelSource, 'AND bucket_date < CURDATE()') >= 1
            && str_contains($modelSource, "'rollup_consistency_hourly'")
            && str_contains($modelSource, "'rollup_consistency_daily'"),
        'Production rollup validation queries must compare bounded completed days only and remain visible in the request-local report profiler.'
    );

    echo "telemetry_daily_rollup_consistency_test: ok\n";
}
