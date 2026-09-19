<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/telemetry_storage_diagnostics_contract_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects Stage 6 operator-facing telemetry storage and cardinality evidence.
 *
 * Responsibilities:
 *   - Keep dynamic telemetry table/timestamp identifiers model-owned and allowlisted
 *   - Verify storage diagnostics expose exact rows, recent growth, bytes, and retention
 *   - Verify hourly metric diagnostics measure bounded dimension cardinality
 *   - Prevent the export from re-running duplicate table COUNT(*) queries
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

/** Throw when one storage-diagnostic contract fails. */
function telemetry_storage_contract_assert(bool $condition, string $message): void
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

telemetry_storage_contract_assert(
    str_contains($model, 'function telemetry_model_report_storage_diagnostics')
        && str_contains($model, "'telemetry_events' => 'occurred_at'")
        && str_contains($model, "'telemetry_sessions' => 'last_seen_at'")
        && str_contains($model, "'telemetry_hourly_metrics' => 'bucket_start'")
        && str_contains($model, "'telemetry_daily_metrics' => 'bucket_date'")
        && str_contains($model, "'telemetry_db_query_metrics' => 'bucket_start'")
        && str_contains($model, "'telemetry_job_runs' => 'started_at'"),
    'Storage diagnostics must own a fixed telemetry table/timestamp allowlist in the model.'
);

telemetry_storage_contract_assert(
    str_contains($model, 'information_schema.tables')
        && str_contains($model, 'COUNT(*) AS exact_rows')
        && str_contains($model, 'AS recent_rows')
        && str_contains($model, "'total_bytes' => max(0, \$dataBytes + \$indexBytes)"),
    'Storage diagnostics must expose exact rows, recent row growth, and table data/index size.'
);

telemetry_storage_contract_assert(
    str_contains($model, 'function telemetry_model_report_hourly_metric_cardinality')
        && str_contains($model, 'COUNT(DISTINCT route_name) AS route_cardinality')
        && str_contains($model, 'COUNT(DISTINCT image_id) AS image_cardinality')
        && str_contains($model, 'COUNT(DISTINCT device_type) AS device_cardinality')
        && str_contains($model, 'WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)'),
    'Hourly metric cardinality must be bounded to the selected report window and measure the dimensions relevant to Stage 6.'
);

telemetry_storage_contract_assert(
    str_contains($service, 'function telemetry_report_storage_diagnostics')
        && str_contains($service, "'approx_rows_per_day'")
        && str_contains($service, "'retention_days'")
        && str_contains($service, "'hourly_within_retention'")
        && str_contains($service, "'daily_required_for_full_window'"),
    'The service must combine storage measurements with configured retention and explicit long-window source state.'
);

telemetry_storage_contract_assert(
    str_contains($controller, '$storageDiagnostics = telemetry_report_storage_diagnostics($days);')
        && !str_contains($controller, "'telemetry_events' => telemetry_report_table_count('telemetry_events')"),
    'The export must reuse the Stage 6 diagnostic snapshot instead of issuing duplicate per-table COUNT(*) queries.'
);

telemetry_storage_contract_assert(
    str_contains($controller, '$rollupConsistency = telemetry_report_daily_rollup_consistency(')
        && str_contains($service, 'function telemetry_report_daily_rollup_consistency')
        && str_contains($view, '$rollupConsistencyHtml'),
    'The export must expose completed-day hourly/daily rollup consistency before any future long-window source switch.'
);

telemetry_storage_contract_assert(
    str_contains($view, "'storage_diagnostics'")
        && str_contains($view, "'hourly_metric_cardinality'")
        && str_contains($view, '$storageDiagnosticsHtml'),
    'The standalone Admin telemetry export must render the storage and cardinality evidence.'
);

echo "telemetry_storage_diagnostics_contract_test: ok\n";
