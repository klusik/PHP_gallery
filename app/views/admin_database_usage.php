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
 *   - Present refreshed database statistics and accessible proportional table bars
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
 *   2026-10-04
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Core\format_bytes;
use function Gallery\Services\t;

/**
 * Render database usage statistics for the Admin storage page.
 *
 * @param ?array<string, mixed> $usage Prepared database usage view model, or null when unavailable.
 * @return void Outputs the database usage panel when data is available.
 */
function view_render_admin_database_usage_panel(?array $usage): void
{
    if ($usage === null || $usage === []) {
        return;
    }

    echo '<section class="admin-storage-panel admin-database-usage-panel panel" aria-label="' . e(t('admin.database_usage.panel_aria', 'Database usage')) . '">';
    echo '<div class="admin-panel-heading admin-storage-heading"><div><p class="admin-kicker">' . e(t('admin.database_usage.kicker', 'Database')) . '</p><h2>' . e(t('admin.database_usage.title', 'Database usage')) . '</h2></div><details class="admin-storage-help"><summary aria-label="' . e(t('admin.storage.chart_help_label', 'Chart explanations')) . '">?</summary><div><p>' . e(t('admin.database_usage.description', 'Database table sizes are measured from MySQL/MariaDB table metadata and shown separately from picture files stored on disk.')) . '</p><ul><li><strong>' . e(t('admin.database_usage.total_database', 'Total database')) . ':</strong> ' . e(t('admin.database_usage.total_database_hint', '{count} table(s), data plus indexes.', ['count' => (string) ($usage['table_count'] ?? 0)])) . '</li><li><strong>' . e(t('admin.database_usage.gallery_database', 'Gallery DB data')) . ':</strong> ' . e(t('admin.database_usage.gallery_database_hint', '{count} gallery/content table(s), {percent}% of DB.', ['count' => (string) ($usage['gallery_table_count'] ?? 0), 'percent' => number_format((float) ($usage['gallery_percent_of_database'] ?? 0.0), 1)])) . '</li><li><strong>' . e(t('admin.database_usage.sql_data_pages', 'SQL data pages')) . ':</strong> ' . e(t('admin.database_usage.sql_data_pages_hint', 'Table payload pages reported by the database engine.')) . '</li><li><strong>' . e(t('admin.database_usage.sql_indexes', 'SQL indexes')) . ':</strong> ' . e(t('admin.database_usage.sql_indexes_hint', 'Index pages reported by the database engine.')) . '</li><li><strong>' . e(t('admin.database_usage.all_tables_title', 'Largest DB tables')) . ':</strong> ' . e(t('admin.database_usage.all_tables_hint', 'Top tables by data plus index bytes.')) . '</li><li><strong>' . e(t('admin.database_usage.gallery_tables_title', 'Gallery DB tables')) . ':</strong> ' . e(t('admin.database_usage.gallery_tables_hint', 'Only tables classified as gallery content or gallery-derived metadata.')) . '</li></ul></div></details></div>';

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
    view_render_admin_storage_summary_card(t('admin.database_usage.total_database', 'Total database'), format_bytes($totalBytes), t('admin.database_usage.total_database_hint', '{count} table(s), data plus indexes.', ['count' => (string) $tableCount]), 'overview');
    view_render_admin_storage_summary_card(t('admin.database_usage.gallery_database', 'Gallery DB data'), format_bytes($galleryBytes), t('admin.database_usage.gallery_database_hint', '{count} gallery/content table(s), {percent}% of DB.', ['count' => (string) $galleryTableCount, 'percent' => number_format($galleryPercent, 1)]), 'galleries');
    view_render_admin_storage_summary_card(t('admin.database_usage.sql_data_pages', 'SQL data pages'), format_bytes($dataBytes), t('admin.database_usage.sql_data_pages_hint', 'Table payload pages reported by the database engine.'), 'report');
    view_render_admin_storage_summary_card(t('admin.database_usage.sql_indexes', 'SQL indexes'), format_bytes($indexBytes), t('admin.database_usage.sql_indexes_hint', 'Index pages reported by the database engine.'), 'settings');
    echo '</div>';

    echo '<div class="admin-storage-facts admin-database-usage-facts">';
    echo '<span><strong>' . e(t('admin.database_usage.table_count', 'Tables')) . '</strong> ' . e(number_format($tableCount)) . '</span>';
    echo '<span><strong>' . e(t('admin.database_usage.gallery_table_count', 'Gallery tables')) . '</strong> ' . e(number_format($galleryTableCount)) . '</span>';
    if ($databaseName !== '') {
        echo '<span><strong>' . e(t('admin.database_usage.database_name', 'Database')) . '</strong> ' . e($databaseName) . '</span>';
    }
    echo '<span><strong>' . e(t('admin.database_usage.estimated_rows', 'Estimated rows')) . '</strong> ' . e(number_format($rowEstimate)) . '</span>';
    echo '<span><strong>' . e(t('admin.database_usage.gallery_estimated_rows', 'Gallery rows')) . '</strong> ' . e(number_format($galleryRowEstimate)) . '</span>';
    if ($largestTableName !== '') {
        echo '<span><strong>' . e(t('admin.database_usage.largest_table', 'Largest table')) . '</strong> ' . e($largestTableName) . ' <em>' . e(format_bytes($largestTableBytes)) . '</em></span>';
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
 * @param array<string, mixed> $usage Database usage model containing a bounded safe unavailability reason.
 * @return void Outputs the unavailable-state message.
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
 * @param string $title Visible chart title and accessible meter label prefix.
 * @param string $hint Concise chart explanation available to the view.
 * @param array<int, array<string, mixed>> $rows Prepared database table rows to render.
 * @param string $emptyText Message displayed when no table rows are available.
 * @return void Outputs a compact proportional database table chart.
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
            'size' => format_bytes($bytes),
            'rows' => number_format($rowCount),
            'data' => format_bytes($dataBytes),
            'indexes' => format_bytes($indexBytes),
        ]);
        if ($engine !== '') {
            $details .= ' · ' . $engine;
        }
        echo '<div class="admin-storage-bar-row">';
        echo '<div class="admin-storage-bar-meta"><span>' . e($label) . '</span><small>' . e($details) . '</small></div>';
        echo '<div class="admin-storage-bar-track" role="meter" aria-label="' . e($title . ': ' . $label) . '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . e(number_format($percent, 1, '.', '')) . '"><span class="admin-storage-bar-fill" aria-hidden="true" style="--admin-storage-bar: ' . e(number_format($percent, 1, '.', '')) . '%"></span></div>';
        echo '</div>';
    }
    echo '</div></article>';
}

/**
 * Return a safe integer from a database usage array.
 *
 * @param array $usage Usage value.
 * @param string $key Lookup key.
 * @param int $fallback Fallback value.
 * @return int Integer result for the caller.
 */
function view_admin_database_usage_int(array $usage, string $key, int $fallback = 0): int
{
    return (int) ($usage[$key] ?? $fallback);
}

/**
 * Return a safe row array from a database usage array.
 *
 * @param array $usage Usage value.
 * @param string $key Lookup key.
 * @return array<int array<string, mixed>>.
 */
function view_admin_database_usage_array(array $usage, string $key): array
{
    return is_array($usage[$key] ?? null) ? $usage[$key] : [];
}
