<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/telemetry_report_query_plan_contract_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects the sanitized Stage 6 optimizer-plan evidence in telemetry exports.
 *
 * Responsibilities:
 *   - Keep EXPLAIN query definitions fixed and model-owned
 *   - Prevent SQL text, bound values, result payloads, or database errors from entering the export
 *   - Verify plan probing is fail-open when the hosting database cannot return EXPLAIN data
 *   - Keep query-plan probes separate from request-local runtime measurements
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

/** Throw when one query-plan contract fails. */
function telemetry_query_plan_contract_assert(bool $condition, string $message): void
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

telemetry_query_plan_contract_assert(
    str_contains($model, 'function telemetry_model_report_query_plans')
        && str_contains($model, "'session_summary' => [")
        && str_contains($model, "'daily_trends' => [")
        && str_contains($model, "'top_galleries' => [")
        && str_contains($model, "'page_kind_distribution' => ["),
    'Optimizer-plan diagnostics must use a fixed source-owned set of telemetry report query families.'
);

telemetry_query_plan_contract_assert(
    str_contains($model, "'status' => 'available'")
        && str_contains($model, "'status' => 'unavailable'")
        && str_contains($model, "'possible_keys'")
        && str_contains($model, "'key_used'")
        && str_contains($model, "'rows_estimate'")
        && str_contains($model, "'filtered_percent'")
        && str_contains($model, 'catch (\\Throwable)'),
    'EXPLAIN diagnostics must return bounded optimizer metadata and degrade safely when a plan cannot be read.'
);

telemetry_query_plan_contract_assert(
    !str_contains($model, "'sql' => (string) \$plan['sql']")
        && !str_contains($model, "'params' => (array) \$plan['params']")
        && !str_contains($model, 'getMessage()'),
    'Query-plan export rows must not expose SQL text, bound values, or raw database errors.'
);

telemetry_query_plan_contract_assert(
    str_contains($service, 'function telemetry_report_query_plans(int $days, string $trafficSegment = \'all\'): array')
        && str_contains($service, 'telemetry_model_report_query_plans('),
    'The telemetry service must expose sanitized plan evidence using semantic report inputs only.'
);

$profilePosition = strpos($controller, '$reportQueryProfile = telemetry_report_query_profile();');
$planPosition = strpos($controller, '$reportQueryPlans = telemetry_report_query_plans($days, $trafficSegment);');
telemetry_query_plan_contract_assert(
    $profilePosition !== false && $planPosition !== false && $profilePosition < $planPosition,
    'Runtime query timings must be snapshotted before EXPLAIN probes so diagnostic-plan overhead is not mixed into report runtime evidence.'
);

telemetry_query_plan_contract_assert(
    str_contains($view, "'report_query_plans'")
        && str_contains($view, '$reportQueryPlansHtml')
        && str_contains($view, "['key' => 'possible_keys'")
        && str_contains($view, "['key' => 'key_used'")
        && str_contains($view, "['key' => 'rows_estimate'"),
    'The standalone telemetry export must render sanitized optimizer-plan evidence.'
);

echo "telemetry_report_query_plan_contract_test: ok\n";
