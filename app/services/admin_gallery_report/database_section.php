<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_gallery_report/database_section.php
 * Module Type: Service
 *
 * Purpose:
 *   Builds the database usage section from enumerated base tables.
 *
 * Responsibilities:
 *   - Enumerate base tables and resolve exact row counts
 *   - Enrich shared database usage rows with report-specific detail
 *   - Keep table identifiers validated and quoted before use
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
 *   - Loaded by app/services/admin_gallery_report.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/admin_gallery_report.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;
use function Gallery\Core\cms_config;
use function Gallery\Core\cms_current_version;
use function Gallery\Core\db;

/**
 * Return database usage and table metadata.
 *
 * @return array<string, mixed> Database section.
 */
function admin_gallery_report_database_section(): array
{
    $databaseName = function_exists('Gallery\Services\admin_database_usage_current_database_name') ? admin_database_usage_current_database_name() : '';
    $usage = function_exists('Gallery\Services\admin_database_usage_summary') ? admin_database_usage_summary() : ['available' => false, 'error' => 'Database usage service is not available.'];
    $exactCounts = admin_gallery_report_exact_database_table_counts($databaseName);

    return [
        'usage' => admin_gallery_report_enrich_database_usage_for_report($usage, $exactCounts),
        'database_name' => $databaseName,
        'exact_row_counts_available' => !empty($exactCounts['available']),
        'exact_row_count_errors' => is_array($exactCounts['errors'] ?? null) ? $exactCounts['errors'] : [],
    ];
}

/**
 * Return exact row counts for base tables in the active database.
 *
 * information_schema.TABLES.TABLE_ROWS is only an engine estimate for InnoDB
 * and may be stale or zero on some shared-hosting installations. The complete
 * report uses exact COUNT(*) values because correctness is more important than
 * generation speed for this maintenance export.
 *
 * @param string $databaseName Active database name.
 * @return array<string, mixed> Exact count payload.
 */
function admin_gallery_report_exact_database_table_counts(string $databaseName): array
{
    $result = [
        'available' => false,
        'counts' => [],
        'errors' => [],
        'total_rows' => 0,
    ];
    if ($databaseName === '') {
        $result['errors'][] = 'Database name is empty.';
        return $result;
    }

    try {
        $stmt = db()->prepare("SELECT TABLE_NAME AS table_name FROM information_schema.TABLES WHERE TABLE_SCHEMA = :database_name AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME ASC");
        $stmt->execute(['database_name' => $databaseName]);
        $tables = $stmt->fetchAll();
    } catch (Throwable $exception) {
        $result['errors'][] = 'Database table inventory could not be read.';
        return $result;
    }

    $result['available'] = true;
    foreach ($tables as $row) {
        $tableName = trim((string) ($row['table_name'] ?? ''));
        if ($tableName === '') {
            continue;
        }
        try {
            $count = (int) (db()->query('SELECT COUNT(*) FROM ' . admin_gallery_report_quote_identifier($tableName))->fetchColumn() ?: 0);
            $result['counts'][$tableName] = $count;
            $result['total_rows'] = (int) ($result['total_rows'] ?? 0) + $count;
        } catch (Throwable $exception) {
            $result['errors'][] = $tableName . ': exact row count unavailable.';
        }
    }

    return $result;
}

/**
 * Add exact report-only row counts and compatibility aliases to database usage rows.
 *
 * @param array $usage Database usage payload.
 * @param array $exactCounts Exact count payload.
 * @return array<string, mixed> Enriched usage payload.
 */
function admin_gallery_report_enrich_database_usage_for_report(array $usage, array $exactCounts): array
{
    if (empty($usage['available'])) {
        return $usage;
    }

    $counts = is_array($exactCounts['counts'] ?? null) ? $exactCounts['counts'] : [];
    $usage['table_rows_exact_available'] = !empty($exactCounts['available']);
    $usage['table_rows_exact'] = (int) ($exactCounts['total_rows'] ?? 0);
    $usage['table_rows_exact_counted_tables'] = count($counts);
    $usage['exact_row_count_errors'] = is_array($exactCounts['errors'] ?? null) ? $exactCounts['errors'] : [];
    $usage['table_rows'] = admin_gallery_report_enrich_database_table_rows(is_array($usage['table_rows'] ?? null) ? $usage['table_rows'] : [], $counts);
    $usage['gallery_table_rows'] = admin_gallery_report_enrich_database_table_rows(is_array($usage['gallery_table_rows'] ?? null) ? $usage['gallery_table_rows'] : [], $counts);

    return $usage;
}

/**
 * Add exact row counts and stable byte aliases to rendered database table rows.
 *
 * @param array $rows Table metadata rows.
 * @param array $exactCounts Exact row count lookup by table name.
 * @return array<int, array<string, mixed>> Enriched rows.
 */
function admin_gallery_report_enrich_database_table_rows(array $rows, array $exactCounts): array
{
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $tableName = trim((string) ($row['table_name'] ?? $row['label'] ?? ''));
        $estimatedRows = max(0, (int) ($row['count'] ?? $row['table_rows'] ?? 0));
        $totalBytes = max(0, (int) ($row['bytes'] ?? $row['total_bytes'] ?? 0));
        $rows[$index]['table_name'] = $tableName;
        $rows[$index]['table_rows_estimate'] = $estimatedRows;
        $rows[$index]['total_bytes'] = $totalBytes;
        $rows[$index]['bytes'] = $totalBytes;
        $rows[$index]['row_count_source'] = 'estimate';
        if ($tableName !== '' && array_key_exists($tableName, $exactCounts)) {
            $rows[$index]['rows_exact'] = (int) $exactCounts[$tableName];
            $rows[$index]['rows_display'] = (int) $exactCounts[$tableName];
            $rows[$index]['row_count_source'] = 'COUNT(*)';
        } else {
            $rows[$index]['rows_exact'] = null;
            $rows[$index]['rows_display'] = $estimatedRows;
        }
    }
    return $rows;
}

/**
 * Quote a database identifier for report-only exact count queries.
 *
 * @param string $identifier Identifier value.
 * @return string Quoted identifier.
 */
function admin_gallery_report_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * Return row counts for known application tables.
 *
 * @return array<int, array<string, mixed>> Table count rows.
 */
function admin_gallery_report_table_counts(): array
{
    $tables = [
        'galleries', 'images', 'tags', 'gallery_tags', 'image_tags', 'image_votes', 'picture_game_votes',
        'zip_archives', 'gallery_upload_tokens', 'gallery_flight_maps', 'image_ai_metadata', 'image_ai_analysis_jobs',
        'image_thumbnail_variants', 'admin_logs', 'app_settings', 'users', 'migrations', 'telemetry_events',
        'telemetry_sessions', 'telemetry_hourly_metrics', 'telemetry_daily_metrics', 'telemetry_db_query_metrics',
        'telemetry_job_runs', 'telemetry_settings', 'navigation_data_accounts', 'navigation_data_cache',
    ];
    $rows = [];
    foreach ($tables as $table) {
        if (!admin_gallery_report_table_exists($table)) {
            $rows[] = ['table_name' => $table, 'exists' => 'no', 'rows' => null];
            continue;
        }
        try {
            $rows[] = ['table_name' => $table, 'exists' => 'yes', 'rows' => (int) (db()->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')->fetchColumn() ?: 0)];
        } catch (Throwable $exception) {
            $rows[] = ['table_name' => $table, 'exists' => 'yes', 'rows' => null, 'error' => 'Exact row count unavailable.'];
        }
    }
    return $rows;
}
