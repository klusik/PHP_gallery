<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_search_diagnostics.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders the Admin progressive public-search diagnostics page.
 *
 * Responsibilities:
 *   - Present query/repeat/EXPLAIN controls for an explicit diagnostic run
 *   - Show phase, subquery, table-size, and optimizer summaries returned by the service layer
 *   - Expose the complete JSON report for copy/download without executing database logic
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
 *   - This view must not issue SQL or read request globals.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\csrf_field;
use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\url_for;
use function Gallery\Services\t;

/**
 * Render the Admin public-search diagnostics page.
 *
 * @param array<string, mixed>|null $report Decoded diagnostics report.
 * @param string $reportJson Pretty JSON report.
 * @param string $query Last entered query.
 * @param int $runs Last selected repeat count.
 * @param bool $includeExplain Last EXPLAIN selection.
 * @param string $notice Optional notice.
 * @param string $error Optional error notice.
 */
function view_render_admin_search_diagnostics_page(
    ?array $report,
    string $reportJson,
    string $query,
    int $runs,
    bool $includeExplain,
    string $notice = '',
    string $error = ''
): void {
    render_header(t('admin.search_diagnostics.page_title', 'Search diagnostics'));

    echo '<section class="hero admin-dashboard-hero"><div><p class="admin-kicker">' . e(t('admin.search_diagnostics.kicker', 'Search performance')) . '</p><h1>' . e(t('admin.search_diagnostics.title', 'Progressive public-search diagnostics')) . '</h1><p class="muted">' . e(t('admin.search_diagnostics.description', 'Run the real progressive search phases against this database, measure every model query, inspect relevant table indexes, and capture optimizer plans for offline analysis.')) . '</p></div><div class="admin-hero-actions"><a class="button secondary" href="' . e(url_for('admin_diagnostics')) . '">' . e(t('admin.search_diagnostics.back_to_diagnostics', 'Back to runtime diagnostics')) . '</a><a class="button secondary" href="' . e(url_for('admin') . '#admin-tab-maintenance') . '">' . e(t('admin.search_diagnostics.back_to_maintenance', 'Back to maintenance')) . '</a></div></section>';

    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }
    if ($error !== '') {
        echo '<div class="notice error">' . e($error) . '</div>';
    }

    echo '<section class="panel"><div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.search_diagnostics.run_kicker', 'Benchmark query')) . '</p><h2>' . e(t('admin.search_diagnostics.run_title', 'Run search diagnostics')) . '</h2></div><p class="muted">' . e(t('admin.search_diagnostics.run_hint', 'The diagnostic executes primary, media, descriptive, and deep phases repeatedly. On a large database the deep phase can take several seconds, so start with three runs.')) . '</p></div>';
    echo '<form method="post" action="' . e(url_for('admin_search_diagnostics')) . '" class="stacked-form">';
    echo csrf_field();
    echo '<input type="hidden" name="action" value="run">';
    echo '<label><span>' . e(t('admin.search_diagnostics.query_label', 'Search query')) . '</span><input type="search" name="query" value="' . e($query) . '" minlength="2" maxlength="120" required autocomplete="off" placeholder="320"></label>';
    echo '<label><span>' . e(t('admin.search_diagnostics.runs_label', 'Repeated runs')) . '</span><select name="runs">';
    foreach ([1, 3, 5] as $option) {
        echo '<option value="' . $option . '"' . ($runs === $option ? ' selected' : '') . '>' . $option . '</option>';
    }
    echo '</select></label>';
    echo '<label class="checkbox-row"><input type="checkbox" name="include_explain" value="1"' . ($includeExplain ? ' checked' : '') . '><span>' . e(t('admin.search_diagnostics.explain_label', 'Include EXPLAIN plans for first-run SQL statements')) . '</span></label>';
    echo '<div class="admin-hero-actions"><button type="submit" class="button">' . e(t('admin.search_diagnostics.run_button', 'Run diagnostics')) . '</button></div>';
    echo '</form></section>';

    if (!is_array($report)) {
        echo '<section class="panel"><p class="muted">' . e(t('admin.search_diagnostics.empty_hint', 'No search diagnostic report has been generated in this Admin session yet.')) . '</p></section>';
        render_footer();
        return;
    }

    view_render_admin_search_diagnostics_phase_summary($report);
    view_render_admin_search_diagnostics_query_summary($report);
    view_render_admin_search_diagnostics_database_summary($report);

    echo '<section class="panel" data-admin-search-diagnostics data-copy-done-label="' . e(t('admin.search_diagnostics.copy_done', 'Copied')) . '"><div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.search_diagnostics.report_kicker', 'Portable report')) . '</p><h2>' . e(t('admin.search_diagnostics.report_title', 'JSON diagnostic log')) . '</h2></div><div class="admin-hero-actions"><button type="button" class="button secondary" data-admin-search-diagnostics-copy>' . e(t('admin.search_diagnostics.copy_button', 'Copy report')) . '</button><a class="button secondary" href="' . e(url_for('admin_search_diagnostics', ['download' => 1])) . '" download>' . e(t('admin.search_diagnostics.download_button', 'Download JSON')) . '</a></div></div>';
    echo '<p class="muted">' . e(t('admin.search_diagnostics.report_hint', 'This is the file to attach or paste when requesting search-performance analysis. It contains the entered query, public result titles, timings, schema metadata, and optimizer plans, but no database password.')) . '</p>';
    echo '<textarea readonly rows="28" spellcheck="false" data-admin-search-diagnostics-report>' . e($reportJson) . '</textarea>';
    echo '<form method="post" action="' . e(url_for('admin_search_diagnostics')) . '" class="admin-hero-actions">' . csrf_field() . '<input type="hidden" name="action" value="clear"><button type="submit" class="button secondary">' . e(t('admin.search_diagnostics.clear_button', 'Clear report')) . '</button></form>';
    echo '</section>';

    render_footer();
}

/**
 * Render phase-level latency statistics.
 *
 * @param array<string, mixed> $report Diagnostic report.
 */
function view_render_admin_search_diagnostics_phase_summary(array $report): void
{
    $summary = is_array($report['phase_summary'] ?? null) ? $report['phase_summary'] : [];
    echo '<section class="panel"><div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.search_diagnostics.phase_kicker', 'Phase timings')) . '</p><h2>' . e(t('admin.search_diagnostics.phase_title', 'Progressive search latency')) . '</h2></div><p class="muted">' . e(t('admin.search_diagnostics.phase_hint', 'Run 1 may include cold-cache effects. Median and later runs are usually more representative of repeated searches.')) . '</p></div>';
    echo '<div class="table-wrap"><table><thead><tr><th>' . e(t('admin.search_diagnostics.phase', 'Phase')) . '</th><th>' . e(t('admin.search_diagnostics.avg_ms', 'Avg ms')) . '</th><th>' . e(t('admin.search_diagnostics.min_ms', 'Min ms')) . '</th><th>' . e(t('admin.search_diagnostics.median_ms', 'Median ms')) . '</th><th>' . e(t('admin.search_diagnostics.max_ms', 'Max ms')) . '</th><th>' . e(t('admin.search_diagnostics.db_avg_ms', 'DB avg ms')) . '</th><th>' . e(t('admin.search_diagnostics.results', 'Results')) . '</th></tr></thead><tbody>';
    foreach ((array) ($report['phase_order'] ?? []) as $phase) {
        $row = is_array($summary[$phase] ?? null) ? $summary[$phase] : [];
        echo '<tr><td><strong>' . e((string) $phase) . '</strong></td><td>' . e(view_search_diag_number($row['avg_ms'] ?? 0)) . '</td><td>' . e(view_search_diag_number($row['min_ms'] ?? 0)) . '</td><td>' . e(view_search_diag_number($row['median_ms'] ?? 0)) . '</td><td>' . e(view_search_diag_number($row['max_ms'] ?? 0)) . '</td><td>' . e(view_search_diag_number($row['db_avg_ms'] ?? 0)) . '</td><td>' . e((string) ((int) ($row['result_min'] ?? 0))) . '–' . e((string) ((int) ($row['result_max'] ?? 0))) . '</td></tr>';
    }
    echo '</tbody></table></div></section>';
}

/**
 * Render per-model-query latency statistics.
 *
 * @param array<string, mixed> $report Diagnostic report.
 */
function view_render_admin_search_diagnostics_query_summary(array $report): void
{
    $summary = is_array($report['query_summary'] ?? null) ? $report['query_summary'] : [];
    echo '<section class="panel"><div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.search_diagnostics.query_kicker', 'SQL breakdown')) . '</p><h2>' . e(t('admin.search_diagnostics.query_title', 'Model query timings')) . '</h2></div><p class="muted">' . e(t('admin.search_diagnostics.query_hint', 'These labels correspond to bounded candidate and hydration queries in the MVC model layer.')) . '</p></div>';
    echo '<div class="table-wrap"><table><thead><tr><th>' . e(t('admin.search_diagnostics.query_name', 'Query')) . '</th><th>' . e(t('admin.search_diagnostics.executions', 'Executions')) . '</th><th>' . e(t('admin.search_diagnostics.avg_ms', 'Avg ms')) . '</th><th>' . e(t('admin.search_diagnostics.median_ms', 'Median ms')) . '</th><th>' . e(t('admin.search_diagnostics.max_ms', 'Max ms')) . '</th><th>' . e(t('admin.search_diagnostics.rows_max', 'Max rows')) . '</th></tr></thead><tbody>';
    foreach ($summary as $label => $row) {
        if (!is_array($row)) {
            continue;
        }
        echo '<tr><td><code>' . e((string) $label) . '</code></td><td>' . e((string) ((int) ($row['executions'] ?? 0))) . '</td><td>' . e(view_search_diag_number($row['avg_ms'] ?? 0)) . '</td><td>' . e(view_search_diag_number($row['median_ms'] ?? 0)) . '</td><td>' . e(view_search_diag_number($row['max_ms'] ?? 0)) . '</td><td>' . e((string) ((int) ($row['rows_max'] ?? 0))) . '</td></tr>';
    }
    echo '</tbody></table></div></section>';
}

/**
 * Render search-related table-size metadata.
 *
 * @param array<string, mixed> $report Diagnostic report.
 */
function view_render_admin_search_diagnostics_database_summary(array $report): void
{
    $database = is_array($report['database'] ?? null) ? $report['database'] : [];
    $tables = is_array($database['tables'] ?? null) ? $database['tables'] : [];
    echo '<section class="panel"><div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.search_diagnostics.database_kicker', 'Database shape')) . '</p><h2>' . e(t('admin.search_diagnostics.database_title', 'Search table sizes')) . '</h2></div><p class="muted">' . e(t('admin.search_diagnostics.database_hint', 'InnoDB row counts are optimizer estimates. The JSON report also contains the full SHOW INDEX inventory and EXPLAIN plans.')) . '</p></div>';
    echo '<p class="muted"><strong>' . e(t('admin.search_diagnostics.server_version', 'Server')) . ':</strong> ' . e((string) ($database['server_version'] ?? '')) . ' ' . e((string) ($database['server_info'] ?? '')) . '</p>';
    echo '<div class="table-wrap"><table><thead><tr><th>' . e(t('admin.search_diagnostics.table', 'Table')) . '</th><th>' . e(t('admin.search_diagnostics.estimated_rows', 'Estimated rows')) . '</th><th>' . e(t('admin.search_diagnostics.data_mb', 'Data MB')) . '</th><th>' . e(t('admin.search_diagnostics.index_mb', 'Index MB')) . '</th></tr></thead><tbody>';
    foreach ($tables as $row) {
        if (!is_array($row)) {
            continue;
        }
        echo '<tr><td><code>' . e((string) ($row['table'] ?? '')) . '</code></td><td>' . e(number_format((int) ($row['estimated_rows'] ?? 0), 0, '.', ' ')) . '</td><td>' . e(view_search_diag_megabytes((int) ($row['data_bytes'] ?? 0))) . '</td><td>' . e(view_search_diag_megabytes((int) ($row['index_bytes'] ?? 0))) . '</td></tr>';
    }
    echo '</tbody></table></div></section>';
}

/**
 * Format one diagnostic millisecond value.
 */
function view_search_diag_number(mixed $value): string
{
    return number_format((float) $value, 3, '.', '');
}

/**
 * Format bytes as decimal-display MiB for compact table output.
 */
function view_search_diag_megabytes(int $bytes): string
{
    return number_format(max(0, $bytes) / 1048576, 2, '.', '');
}
