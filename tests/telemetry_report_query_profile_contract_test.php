<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/telemetry_report_query_profile_contract_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects request-local Stage 6 telemetry report SQL runtime diagnostics.
 *
 * Responsibilities:
 *   - Keep report-query profiling model-owned and bounded to source-defined operation names
 *   - Prevent query profiling from persisting SQL text, bound values, result data, or errors
 *   - Verify the Admin telemetry export receives and renders request-local timing evidence
 *   - Keep the profiler independent from persistent database telemetry and its recursion guard
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

/** Throw when one report-query profiling contract fails. */
function telemetry_query_profile_contract_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$model = (string) file_get_contents($root . '/app/models/telemetry.php');
$service = (string) file_get_contents($root . '/app/services/telemetry.php');
$controller = (string) file_get_contents($root . '/app/controllers/admin_telemetry.php');
$view = (string) file_get_contents($root . '/app/views/admin_telemetry.php');

telemetry_query_profile_contract_assert(
    str_contains($model, 'function telemetry_model_profiled_report_execute')
        && str_contains($model, "'session_summary'")
        && str_contains($model, "'daily_trends'")
        && str_contains($model, "'top_galleries'")
        && str_contains($model, "'metric_distribution'")
        && str_contains($model, "'storage_table_scan'"),
    'Telemetry report SQL timings must use a source-owned bounded operation allowlist in the model.'
);

telemetry_query_profile_contract_assert(
    str_contains($model, "\$GLOBALS['cms_telemetry_report_query_profile']")
        && str_contains($model, "'failed_calls'")
        && str_contains($model, "'total_ms'")
        && str_contains($model, "'avg_ms'")
        && str_contains($model, "'max_ms'")
        && !str_contains($model, "'sql' => \$sql")
        && !str_contains($model, "'params' => \$params")
        && !str_contains($model, "'error' => \$error"),
    'Report-query profiling must remain request-local and must not retain SQL, parameters, result data, or error text.'
);

telemetry_query_profile_contract_assert(
    str_contains($model, "telemetry_model_profiled_report_execute(\$stmt, [\$days], 'session_summary');")
        && str_contains($model, "telemetry_model_profiled_report_execute(\$stmt, [\$days], 'daily_trends');")
        && str_contains($model, "telemetry_model_profiled_report_execute(\$stmt, [\$days], 'top_galleries');")
        && str_contains($model, "telemetry_model_profiled_report_execute(\$stmt, [\$days, \$metricName], 'metric_distribution');"),
    'Representative report queries must be routed through the request-local profiler.'
);

telemetry_query_profile_contract_assert(
    str_contains($model, 'function telemetry_model_reset_report_query_profile(): void')
        && str_contains($service, 'function telemetry_reset_report_query_profile(): void')
        && str_contains($service, 'function telemetry_report_query_profile(): array')
        && str_contains($service, 'return telemetry_model_report_query_profile();'),
    'The service must reset and expose bounded query timing evidence without adding persistence behavior.'
);

$resetPosition = strpos($controller, 'telemetry_reset_report_query_profile();');
$firstReportPosition = strpos($controller, '$sessionSummary = telemetry_report_session_summary($days, $trafficSegment);');
$profilePosition = strpos($controller, '$reportQueryProfile = telemetry_report_query_profile();');
telemetry_query_profile_contract_assert(
    $resetPosition !== false
        && $firstReportPosition !== false
        && $profilePosition !== false
        && $resetPosition < $firstReportPosition
        && $firstReportPosition < $profilePosition
        && str_contains($controller, '$html = view_render_admin_telemetry_export_document(get_defined_vars());'),
    'The export controller must reset timings before report queries and snapshot them only after the report data has been collected.'
);

telemetry_query_profile_contract_assert(
    str_contains($view, "'report_query_profile'")
        && str_contains($view, '$reportQueryProfileHtml')
        && str_contains($view, "['key' => 'failed_calls'")
        && str_contains($view, "['key' => 'avg_ms'")
        && str_contains($view, "['key' => 'max_ms'"),
    'The standalone Admin telemetry export must render calls, failures, and timing aggregates for report query families.'
);

echo "telemetry_report_query_profile_contract_test: ok\n";
