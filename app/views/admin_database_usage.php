<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_database_usage.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders Admin database usage statistics near media storage statistics.
 *
 * Responsibilities:
 *   - Keep database usage markup outside controllers
 *   - Display total database storage and gallery-content table storage
 *   - Render table-size charts using the shared Admin storage visual language
 *   - Handle unavailable information_schema metadata without breaking the page
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
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-06-08
 */

declare(strict_types=1);

/**
 * Render database usage statistics for the Admin storage page.
 *
 * @param array<string, mixed>|null $usage
 */
function view_render_admin_database_usage_panel(?array $usage): void
{
    if ($usage === null || $usage === []) {
        return;
    }

    echo '<section class="admin-storage-panel admin-database-usage-panel panel" aria-label="' . e(t('admin.database_usage.panel_aria', 'Database usage')) . '">';
    echo '<div class="admin-panel-heading admin-storage-heading"><div><p class="admin-kicker">' . e(t('admin.database_usage.kicker', 'Database')) . '</p><h2>' . e(t('admin.database_usage.title', 'Database usage')) . '</h2></div><p class="muted">' . e(t('admin.database_usage.description', 'Database table sizes are measured from MySQL/MariaDB table metadata and shown separately from picture files stored on disk.')) . '</p></div>';

    if (empty($usage['available'])) {
        view_render_admin_database_usage_unavailable($usage);
        echo '</section>';
        return;
    }

    $totalBytes = view_admin_database_usage_int($usage, 'total_bytes');
    $galleryBytes = view_admin_database_usage_int($usage, 'gallery_bytes');
    $dataBytes = view_admin_database_usage_int($usage, 'data_bytes');
    $indexBytes = view_admin_database_usage_int($usage, 'index_bytes');
    $galleryPercent = (float) ($usage['gallery_percent_of_database'] ?? 0.0);
    $tableCount = view_admin_database_usage_int($usage, 'table_count');
    $galleryTableCount = view_admin_database_usage_int($usage, 'gallery_table_count');
    $rowEstimate = view_admin_database_usage_int($usage, 'table_rows_estimate');
    $galleryRowEstimate = view_admin_database_usage_int($usage, 'gallery_rows_estimate');
    $largestTableName = (string) ($usage['largest_table_name'] ?? '');
    $largestTableBytes = view_admin_database_usage_int($usage, 'largest_table_bytes');
    $databaseName = (string) ($usage['database_name'] ?? '');

    echo '<div class="admin-storage-summary-grid admin-database-usage-summary-grid">';
    view_render_admin_storage_summary_card(t('admin.database_usage.total_database', 'Total database'), admin_dashboard_format_bytes($totalBytes), t('admin.database_usage.total_database_hint', '{count} table(s), data plus indexes.', ['count' => (string) $tableCount]));
    view_render_admin_storage_summary_card(t('admin.database_usage.gallery_database', 'Gallery DB data'), admin_dashboard_format_bytes($galleryBytes), t('admin.database_usage.gallery_database_hint', '{count} gallery/content table(s), {percent}% of DB.', ['count' => (string) $galleryTableCount, 'percent' => number_format($galleryPercent, 1)]));
    view_render_admin_storage_summary_card(t('admin.database_usage.sql_data_pages', 'SQL data pages'), admin_dashboard_format_bytes($dataBytes), t('admin.database_usage.sql_data_pages_hint', 'Table payload pages reported by the database engine.'));
    view_render_admin_storage_summary_card(t('admin.database_usage.sql_indexes', 'SQL indexes'), admin_dashboard_format_bytes($indexBytes), t('admin.database_usage.sql_indexes_hint', 'Index pages reported by the database engine.'));
    echo '</div>';

    echo '<div class="admin-storage-facts admin-database-usage-facts">';
    if ($databaseName !== '') {
        echo '<span><strong>' . e(t('admin.database_usage.database_name', 'Database')) . '</strong> ' . e($databaseName) . '</span>';
    }
    echo '<span><strong>' . e(t('admin.database_usage.estimated_rows', 'Estimated rows')) . '</strong> ' . e(number_format($rowEstimate)) . '</span>';
    echo '<span><strong>' . e(t('admin.database_usage.gallery_estimated_rows', 'Gallery rows')) . '</strong> ' . e(number_format($galleryRowEstimate)) . '</span>';
    if ($largestTableName !== '') {
        echo '<span><strong>' . e(t('admin.database_usage.largest_table', 'Largest table')) . '</strong> ' . e($largestTableName) . ' <em>' . e(admin_dashboard_format_bytes($largestTableBytes)) . '</em></span>';
    }
    echo '<span><strong>' . e(t('admin.database_usage.method', 'Method')) . '</strong> ' . e(t('admin.database_usage.method_information_schema', 'information_schema estimate')) . '</span>';
    echo '</div>';

    echo '<div class="admin-storage-chart-grid admin-database-usage-chart-grid">';
    view_render_admin_database_usage_table_chart(t('admin.database_usage.all_tables_title', 'Largest DB tables'), t('admin.database_usage.all_tables_hint', 'Top tables by data plus index bytes.'), view_admin_database_usage_array($usage, 'table_rows'), t('admin.database_usage.empty_tables', 'No database tables were reported.'));
    view_render_admin_database_usage_table_chart(t('admin.database_usage.gallery_tables_title', 'Gallery DB tables'), t('admin.database_usage.gallery_tables_hint', 'Only tables classified as gallery content or gallery-derived metadata.'), view_admin_database_usage_array($usage, 'gallery_table_rows'), t('admin.database_usage.empty_gallery_tables', 'No gallery database tables were reported.'));
    echo '</div>';
    echo '</section>';
}

/**
 * Render an unavailable database usage notice.
 *
 * @param array<string, mixed> $usage
 */
function view_render_admin_database_usage_unavailable(array $usage): void
{
    $reason = trim((string) ($usage['error'] ?? ''));
    echo '<div class="admin-storage-empty-panel admin-database-usage-unavailable">';
    echo '<p class="muted">' . e(t('admin.database_usage.unavailable', 'Database table-size metadata is not available on this hosting account. The gallery can continue working; only this capacity panel is missing.')) . '</p>';
    if ($reason !== '') {
        echo '<p class="muted"><strong>' . e(t('admin.database_usage.unavailable_reason', 'Reason')) . ':</strong> ' . e($reason) . '</p>';
    }
    echo '</div>';
}

/**
 * Render one database usage chart.
 *
 * @param array<int, array<string, mixed>> $rows
 */
function view_render_admin_database_usage_table_chart(string $title, string $hint, array $rows, string $emptyText): void
{
    echo '<article class="admin-storage-chart-card admin-database-usage-chart-card"><div class="admin-storage-chart-heading"><strong>' . e($title) . '</strong><small>' . e($hint) . '</small></div>';
    $rows = array_values(array_filter($rows, static fn (array $row): bool => (int) ($row['bytes'] ?? 0) > 0 || (int) ($row['count'] ?? 0) > 0));
    if ($rows === []) {
        echo '<p class="muted">' . e($emptyText) . '</p></article>';
        return;
    }

    echo '<div class="admin-storage-bar-list">';
    foreach ($rows as $row) {
        $label = trim((string) ($row['label'] ?? $row['table_name'] ?? ''));
        if ($label === '') {
            $label = t('admin.storage.unknown_label', 'Unknown');
        }
        $bytes = max(0, (int) ($row['bytes'] ?? 0));
        $rowCount = max(0, (int) ($row['count'] ?? 0));
        $dataBytes = max(0, (int) ($row['data_bytes'] ?? 0));
        $indexBytes = max(0, (int) ($row['index_bytes'] ?? 0));
        $percent = min(100.0, max(0.0, (float) ($row['percent'] ?? 0.0)));
        $engine = trim((string) ($row['engine'] ?? ''));
        $details = t('admin.database_usage.chart_row_details', '{size}, {rows} estimated row(s), data {data}, indexes {indexes}', [
            'size' => admin_dashboard_format_bytes($bytes),
            'rows' => number_format($rowCount),
            'data' => admin_dashboard_format_bytes($dataBytes),
            'indexes' => admin_dashboard_format_bytes($indexBytes),
        ]);
        if ($engine !== '') {
            $details .= ' · ' . $engine;
        }
        echo '<div class="admin-storage-bar-row">';
        echo '<div class="admin-storage-bar-meta"><span>' . e($label) . '</span><small>' . e($details) . '</small></div>';
        echo '<div class="admin-storage-bar-track" aria-hidden="true"><span class="admin-storage-bar-fill" style="--admin-storage-bar: ' . e(number_format($percent, 1, '.', '')) . '%"></span></div>';
        echo '</div>';
    }
    echo '</div></article>';
}

/**
 * Return a safe integer from a database usage array.
 *
 * @param array<string, mixed> $usage
 */
function view_admin_database_usage_int(array $usage, string $key, int $fallback = 0): int
{
    return (int) ($usage[$key] ?? $fallback);
}

/**
 * Return a safe row array from a database usage array.
 *
 * @param array<string, mixed> $usage
 * @return array<int, array<string, mixed>>
 */
function view_admin_database_usage_array(array $usage, string $key): array
{
    return is_array($usage[$key] ?? null) ? $usage[$key] : [];
}
