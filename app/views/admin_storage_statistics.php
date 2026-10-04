<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_storage_statistics.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders detailed Admin dashboard storage statistics.
 *
 * Responsibilities:
 *   - Keep storage statistic markup outside the dashboard controller
 *   - Render compact summary cards and CSS-based charts without JavaScript
 *   - Format byte counts consistently with the Admin dashboard
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
 *   2026-06-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\csrf_token;
use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\url_for;
use function Gallery\Core\format_bytes;
use function Gallery\Services\t;

/**
 * Render the dedicated Admin storage statistics page.
 *
 * @param ?array $statistics Statistics value.
 * @param ?array $databaseUsage Database usage value.
 * @param string $activeTab Active tab value.
 * @param string $notice Notice value.
 * @param array<string, mixed> $databaseMaintenance Explicit maintenance view model.
 * @return void
 */
function view_render_admin_storage_statistics_page(?array $statistics, ?array $databaseUsage = null, string $activeTab = 'files', string $notice = '', array $databaseMaintenance = []): void
{
    $activeTab = view_admin_storage_statistics_normalize_tab($activeTab);
    render_header(t('admin.storage.page_title', 'Storage statistics'));

    echo '<section class="hero admin-dashboard-hero admin-storage-hero"><div><p class="admin-kicker">' . e(t('admin.storage.kicker', 'Storage')) . '</p><h1>' . e(t('admin.storage.page_title', 'Storage statistics')) . '</h1></div>';
    echo '<div class="admin-hero-actions"><a class="button secondary" href="' . e(url_for('admin')) . '">' . e(t('admin.storage.back_to_dashboard', 'Back to dashboard')) . '</a></div></section>';

    view_render_admin_storage_statistics_notice($notice);
    echo '<section class="panel admin-storage-controls">';
    view_render_admin_storage_statistics_tabs($activeTab);
    echo '<div class="admin-storage-update-all" data-admin-storage-statistics data-update-url="' . e(url_for('admin_storage_statistics_update')) . '" data-csrf-token="' . e(csrf_token()) . '">';
    echo '<div class="admin-storage-update-actions"><button type="button" class="button" data-admin-storage-update-button>' . view_admin_menu_icon('update') . '<span data-admin-storage-update-button-label>' . e(t('admin.storage.update_all_button', 'Update all')) . '</span></button><span class="muted" data-admin-storage-status aria-live="polite">' . e(t('admin.storage.update_all_ready', 'Ready')) . '</span><details class="admin-storage-help"><summary aria-label="' . e(t('admin.storage.update_all_help_label', 'Storage update help')) . '">?</summary><div>' . e(t('admin.storage.update_all_help', 'Update file statistics, refresh database estimates with ANALYZE TABLE, and run the read-only database inspection. This does not clean data, repair schema, or optimize tables.')) . '</div></details></div>';
    echo '<div class="admin-storage-progress" data-admin-storage-progress hidden><div class="admin-storage-progress-bar" role="progressbar" aria-label="' . e(t('admin.storage.update_all_progress_label', 'Storage update progress')) . '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span data-admin-storage-progress-fill style="--admin-storage-progress: 0%"></span></div><div class="admin-storage-progress-meta"><span data-admin-storage-progress-label>' . e(t('admin.storage.update_all_starting', 'Preparing the storage update.')) . '</span><span data-admin-storage-progress-count></span></div></div>';
    echo '</div></section>';
    echo '<div class="admin-storage-content" data-admin-storage-content>';

    if ($activeTab === 'database') {
        if (function_exists('Gallery\\Views\\view_render_admin_database_usage_panel')) {
            view_render_admin_database_usage_panel($databaseUsage);
        }
        echo '</div>';
        render_footer();
        return;
    }
    if ($activeTab === 'maintenance') {
        if (function_exists('Gallery\\Views\\view_render_admin_database_maintenance_panel')) {
            view_render_admin_database_maintenance_panel(
                is_array($databaseMaintenance['report'] ?? null) ? $databaseMaintenance['report'] : null,
                is_array($databaseMaintenance['cleanup_state'] ?? null) ? $databaseMaintenance['cleanup_state'] : [],
                is_array($databaseMaintenance['repair_readiness'] ?? null) ? $databaseMaintenance['repair_readiness'] : [],
                !empty($databaseMaintenance['mutations_enabled'])
            );
        }
        echo '</div>';
        render_footer();
        return;
    }

    echo '<div data-admin-storage-results>';
    if (is_array($statistics) && $statistics !== []) {
        view_render_admin_storage_statistics_panel($statistics);
    } else {
        view_render_admin_storage_empty_panel();
    }
    echo '</div>';

    echo '</div>';
    render_footer();
}

/**
 * Render the local tab navigation for the dedicated storage statistics page.
 *
 * @param string $activeTab Active tab value.
 * @return void
 */
function view_render_admin_storage_statistics_tabs(string $activeTab): void
{
    $activeTab = view_admin_storage_statistics_normalize_tab($activeTab);
    $tabs = [
        'files' => [
            'label' => t('admin.storage.tab_files', 'Files'),
            'url' => url_for('admin_storage_statistics', ['tab' => 'files']),
            'icon' => 'galleries',
        ],
        'database' => [
            'label' => t('admin.storage.tab_database', 'Database'),
            'url' => url_for('admin_storage_statistics', ['tab' => 'database']),
            'icon' => 'report',
        ],
        'maintenance' => [
            'label' => t('admin.storage.tab_database_maintenance', 'DB maintenance'),
            'url' => url_for('admin_storage_statistics', ['tab' => 'maintenance']),
            'icon' => 'maintenance',
        ],
    ];

    echo '<nav class="admin-storage-tabs" aria-label="' . e(t('admin.storage.tabs_aria', 'Storage statistics sections')) . '">';
    echo '<div class="admin-storage-tab-list" role="tablist">';
    foreach ($tabs as $tabKey => $tab) {
        $isActive = $activeTab === $tabKey;
        $className = $isActive ? 'admin-storage-tab is-active' : 'admin-storage-tab';
        echo '<a class="' . e($className) . '" role="tab" aria-selected="' . ($isActive ? 'true' : 'false') . '" href="' . e((string) $tab['url']) . '">';
        echo '<span class="admin-storage-tab-label">' . view_admin_menu_icon((string) $tab['icon']) . e((string) $tab['label']) . '</span>';
        echo '</a>';
    }
    echo '</div></nav>';
}

/**
 * Normalize the requested storage statistics tab.
 *
 * @param string $activeTab Active tab value.
 * @return string Text result for the caller.
 */
function view_admin_storage_statistics_normalize_tab(string $activeTab): string
{
    return in_array($activeTab, ['files', 'database', 'maintenance'], true) ? $activeTab : 'files';
}

/**
 * Render a storage-page notice when a controller action sets one.
 *
 * @param string $notice Notice text.
 */
function view_render_admin_storage_statistics_notice(string $notice): void
{
    $notice = trim($notice);
    if ($notice === '') {
        return;
    }
    echo '<div class="notice admin-storage-notice">' . e($notice) . '</div>';
}

/**
 * Render an empty placeholder before the first statistics scan exists.
 *
 * @return void Outputs the empty-state storage panel.
 */
function view_render_admin_storage_empty_panel(): void
{
    echo '<section class="admin-storage-panel panel admin-storage-empty-panel"><div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.storage.kicker', 'Storage')) . '</p><h2>' . e(t('admin.storage.no_snapshot_title', 'No detailed statistics yet')) . '</h2></div><details class="admin-storage-help"><summary aria-label="' . e(t('admin.storage.chart_help_label', 'Chart explanations')) . '">?</summary><div>' . e(t('admin.storage.no_snapshot_hint', 'Use Update all to populate source breakdowns, generated thumbnail totals, and charts.')) . '</div></details></div></section>';
}

/**
 * Return a short status label for the cached statistics snapshot.
 *
 * @param ?array $statistics Statistics value.
 * @return string Text result for the caller.
 */
function view_admin_storage_snapshot_status(?array $statistics): string
{
    if (!is_array($statistics) || $statistics === []) {
        return t('admin.storage.no_snapshot_status', 'No cached detailed statistics yet.');
    }
    $generatedAt = view_admin_storage_int($statistics, 'generated_at');
    $status = $generatedAt > 0
        ? t('admin.storage.cached_snapshot_status', 'Last calculated {time}.', ['time' => date('Y-m-d H:i', $generatedAt)])
        : t('admin.storage.cached_snapshot_status_unknown_time', 'Cached statistics are available.');
    if (!empty($statistics['cache_stale'])) {
        $status .= ' ' . t('admin.storage.cached_snapshot_stale', 'Gallery data changed since then, update is recommended.');
    }
    return $status;
}

/**
 * Render the detailed storage statistics panel on the dashboard overview.
 *
 * @param array<string, mixed> $statistics Cached storage totals, counts, status, and chart row view model.
 * @return void Outputs the detailed storage statistics panel when a snapshot exists.
 */
function view_render_admin_storage_statistics_panel(array $statistics): void
{
    if ($statistics === []) {
        return;
    }

    $originalBytes = view_admin_storage_int($statistics, 'original_bytes');
    $thumbnailBytes = view_admin_storage_int($statistics, 'generated_thumbnail_bytes');
    $displayMasterBytes = view_admin_storage_int($statistics, 'display_master_bytes');
    $totalPictureBytes = view_admin_storage_int($statistics, 'total_picture_bytes');
    $imageCount = view_admin_storage_int($statistics, 'image_count');
    $thumbnailCount = view_admin_storage_int($statistics, 'generated_thumbnail_count');
    $displayMasterCount = view_admin_storage_int($statistics, 'display_master_count');
    $averageOriginalBytes = view_admin_storage_int($statistics, 'average_original_bytes');
    $largestOriginalBytes = view_admin_storage_int($statistics, 'largest_original_bytes');
    $largestOriginalName = (string) ($statistics['largest_original_name'] ?? '');
    $unknownSourceSizeCount = view_admin_storage_int($statistics, 'unknown_source_size_count');
    $scanErrors = view_admin_storage_int($statistics, 'thumbnail_scan_errors');
    $generatedPercent = (float) ($statistics['generated_to_original_percent'] ?? 0.0);
    $generatedAt = view_admin_storage_int($statistics, 'generated_at');

    echo '<section class="admin-storage-panel panel" aria-label="' . e(t('admin.storage.panel_aria', 'Storage statistics')) . '">';
    echo '<div class="admin-panel-heading admin-storage-heading"><div><p class="admin-kicker">' . e(t('admin.storage.kicker', 'Storage')) . '</p><h2>' . e(t('admin.storage.title', 'Media storage details')) . '</h2></div>';
    view_render_admin_storage_chart_help([
        [t('admin.storage.title', 'Media storage'), t('admin.storage.description', 'Source photos are counted from the database. Generated thumbnails and DNG display masters are counted from expected derivative files.')],
        [t('admin.storage.source_only', 'Source photos only'), t('admin.storage.source_only_hint', '{count} indexed image(s), excluding generated thumbnails.', ['count' => (string) $imageCount])],
        [t('admin.storage.generated_thumbnails', 'Generated thumbnails'), t('admin.storage.generated_thumbnails_hint', '{count} generated JPG/WebP thumbnail file(s).', ['count' => (string) $thumbnailCount])],
        [t('admin.storage.display_masters', 'Display masters'), t('admin.storage.display_masters_hint', '{count} generated DNG browser-display master file(s).', ['count' => (string) $displayMasterCount])],
        [t('admin.storage.total_with_generated', 'All picture storage'), t('admin.storage.total_with_generated_hint', 'Source photos plus generated picture derivatives. Generated media is {percent}% of source size.', ['percent' => number_format($generatedPercent, 1)])],
        [t('admin.storage.file_types_title', 'Source file types'), t('admin.storage.file_types_hint', 'Grouped by file extension and weighted by source bytes.')],
        [t('admin.storage.size_buckets_title', 'Source file sizes'), t('admin.storage.size_buckets_hint', 'Grouped by original file-size range and weighted by source bytes.')],
        [t('admin.storage.largest_galleries_title', 'Largest galleries'), t('admin.storage.largest_galleries_hint', 'Top galleries by indexed source-photo bytes.')],
        [t('admin.storage.generated_types_title', 'Generated media types'), t('admin.storage.generated_types_hint', 'Generated thumbnail and display-master files found on disk.')],
    ]);
    echo '</div>';

    echo '<div class="admin-storage-summary-grid">';
    view_render_admin_storage_summary_card(t('admin.storage.source_only', 'Source photos only'), format_bytes($originalBytes), t('admin.storage.source_only_hint', '{count} indexed image(s), excluding generated thumbnails.', ['count' => (string) $imageCount]), 'galleries');
    view_render_admin_storage_summary_card(t('admin.storage.generated_thumbnails', 'Generated thumbnails'), format_bytes($thumbnailBytes), t('admin.storage.generated_thumbnails_hint', '{count} generated JPG/WebP thumbnail file(s).', ['count' => (string) $thumbnailCount]), 'smart');
    view_render_admin_storage_summary_card(t('admin.storage.display_masters', 'Display masters'), format_bytes($displayMasterBytes), t('admin.storage.display_masters_hint', '{count} generated DNG browser-display master file(s).', ['count' => (string) $displayMasterCount]), 'report');
    view_render_admin_storage_summary_card(t('admin.storage.total_with_generated', 'All picture storage'), format_bytes($totalPictureBytes), t('admin.storage.total_with_generated_hint', 'Source photos plus generated picture derivatives. Generated media is {percent}% of source size.', ['percent' => number_format($generatedPercent, 1)]), 'overview');
    echo '</div>';

    echo '<div class="admin-storage-facts">';
    echo '<span><strong>' . e(t('admin.storage.image_count', 'Indexed images')) . '</strong> ' . e(number_format($imageCount)) . '</span>';
    echo '<span><strong>' . e(t('admin.storage.generated_thumbnail_count', 'Generated thumbnails')) . '</strong> ' . e(number_format($thumbnailCount)) . '</span>';
    echo '<span><strong>' . e(t('admin.storage.display_master_count', 'Display masters')) . '</strong> ' . e(number_format($displayMasterCount)) . '</span>';
    echo '<span><strong>' . e(t('admin.storage.average_source_size', 'Average source size')) . '</strong> ' . e(format_bytes($averageOriginalBytes)) . '</span>';
    echo '<span><strong>' . e(t('admin.storage.largest_source', 'Largest source')) . '</strong> ' . e(format_bytes($largestOriginalBytes)) . ($largestOriginalName !== '' ? ' <em>' . e($largestOriginalName) . '</em>' : '') . '</span>';
    if ($unknownSourceSizeCount > 0) {
        echo '<span><strong>' . e(t('admin.storage.unknown_source_sizes', 'Unknown source sizes')) . '</strong> ' . (int) $unknownSourceSizeCount . '</span>';
    }
    if ($scanErrors > 0) {
        echo '<span><strong>' . e(t('admin.storage.scan_warnings', 'Scan warnings')) . '</strong> ' . (int) $scanErrors . '</span>';
    }
    if ($generatedAt > 0) {
        echo '<span><strong>' . e(t('admin.storage.calculated', 'Calculated')) . '</strong> ' . e(date('Y-m-d H:i', $generatedAt)) . '</span>';
    }
    if (!empty($statistics['cache_stale'])) {
        echo '<span class="admin-storage-stale"><strong>' . e(t('admin.storage.stale_badge', 'Stale')) . '</strong> ' . e(t('admin.storage.stale_hint', 'Update recommended')) . '</span>';
    }
    echo '</div>';

    echo '<div class="admin-storage-chart-columns"><div class="admin-storage-chart-column">';
    view_render_admin_storage_bar_chart(t('admin.storage.file_types_title', 'Source file types'), t('admin.storage.file_types_hint', 'Grouped by file extension and weighted by source bytes.'), view_admin_storage_array($statistics, 'type_rows'), t('admin.storage.empty_file_types', 'No indexed images yet.'));
    view_render_admin_storage_bar_chart(t('admin.storage.largest_galleries_title', 'Largest galleries'), t('admin.storage.largest_galleries_hint', 'Top galleries by indexed source-photo bytes.'), view_admin_storage_array($statistics, 'largest_gallery_rows'), t('admin.storage.empty_largest_galleries', 'No galleries with indexed images yet.'));
    echo '</div><div class="admin-storage-chart-column">';
    view_render_admin_storage_bar_chart(t('admin.storage.size_buckets_title', 'Source file sizes'), t('admin.storage.size_buckets_hint', 'Grouped by original file-size range and weighted by source bytes.'), view_admin_storage_array($statistics, 'size_bucket_rows'), t('admin.storage.empty_size_buckets', 'No source-size information yet.'));
    view_render_admin_storage_bar_chart(t('admin.storage.generated_types_title', 'Generated media types'), t('admin.storage.generated_types_hint', 'Generated thumbnail and display-master files found on disk.'), view_admin_storage_array($statistics, 'generated_type_rows'), t('admin.storage.empty_generated_types', 'No generated picture derivatives were found.'));
    echo '</div></div>';
    echo '</section>';
}

/**
 * Render one compact storage summary card.
 *
 * @param string $label Label value.
 * @param string $value Value to process.
 * @param string $hint Hint value.
 * @param string $icon Optional icon identifier supported by the Admin SVG helper.
 * @return void Outputs one labeled storage summary card.
 */
function view_render_admin_storage_summary_card(string $label, string $value, string $hint, string $icon = ''): void
{
    echo '<article class="admin-storage-summary-card"><span class="admin-storage-summary-label">' . ($icon !== '' ? view_admin_menu_icon($icon) : '') . e($label) . '</span><strong>' . e($value) . '</strong><small>' . e($hint) . '</small></article>';
}

/**
 * Render a CSS-based horizontal bar chart.
 *
 * @param string $title Title value.
 * @param string $hint Hint value.
 * @param array<int, array{label?: string, label_key?: string, count?: int|string, bytes?: int|string, percent?: float|int|string, folder_path?: string, ...}> $rows Prepared storage chart rows, including localized labels and proportions.
 * @param string $emptyText Message displayed when no positive count or byte totals exist.
 * @return void Outputs the storage chart markup.
 */
function view_render_admin_storage_bar_chart(string $title, string $hint, array $rows, string $emptyText): void
{
    echo '<article class="admin-storage-chart-card"><div class="admin-storage-chart-heading"><strong>' . e($title) . '</strong><small>' . e($hint) . '</small></div>';
    $rows = array_values(array_filter($rows, static fn (array $row): bool => (int) ($row['count'] ?? 0) > 0 || (int) ($row['bytes'] ?? 0) > 0));
    if ($rows === []) {
        echo '<p class="muted">' . e($emptyText) . '</p></article>';
        return;
    }

    echo '<div class="admin-storage-bar-list">';
    foreach ($rows as $row) {
        $label = view_admin_storage_row_label($row);
        $bytes = max(0, (int) ($row['bytes'] ?? 0));
        $count = max(0, (int) ($row['count'] ?? 0));
        $percent = min(100.0, max(0.0, (float) ($row['percent'] ?? 0.0)));
        $path = trim((string) ($row['folder_path'] ?? ''));
        $details = t('admin.storage.chart_row_details', '{size}, {count} file(s)', [
            'size' => format_bytes($bytes),
            'count' => (string) $count,
        ]);
        if ($path !== '') {
            $details .= ' · ' . $path;
        }
        echo '<div class="admin-storage-bar-row">';
        echo '<div class="admin-storage-bar-meta"><span>' . e($label) . '</span><small>' . e($details) . '</small></div>';
        echo '<div class="admin-storage-bar-track" role="meter" aria-label="' . e($title . ': ' . $label) . '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . e(number_format($percent, 1, '.', '')) . '"><span class="admin-storage-bar-fill" aria-hidden="true" style="--admin-storage-bar: ' . e(number_format($percent, 1, '.', '')) . '%"></span></div>';
        echo '</div>';
    }
    echo '</div></article>';
}

/**
 * Render one optional explanation disclosure for a group of storage charts.
 *
 * @param array<int, array{0: string, 1: string}> $items Chart titles and concise explanations.
 * @return void
 */
function view_render_admin_storage_chart_help(array $items): void
{
    echo '<details class="admin-storage-help"><summary aria-label="' . e(t('admin.storage.chart_help_label', 'Chart explanations')) . '">?</summary><div><ul>';
    foreach ($items as $item) {
        echo '<li><strong>' . e((string) ($item[0] ?? '')) . ':</strong> ' . e((string) ($item[1] ?? '')) . '</li>';
    }
    echo '</ul></div></details>';
}

/**
 * Return a translated label for one chart row.
 *
 * @param array $row Row data.
 * @return string Text result for the caller.
 */
function view_admin_storage_row_label(array $row): string
{
    $fallback = trim((string) ($row['label'] ?? ''));
    $labelKey = trim((string) ($row['label_key'] ?? ''));
    if ($labelKey !== '') {
        return t($labelKey, $fallback !== '' ? $fallback : $labelKey);
    }
    return $fallback !== '' ? $fallback : t('admin.storage.unknown_label', 'Unknown');
}

/**
 * Return a safe integer from a storage statistics array.
 *
 * @param array $statistics Statistics value.
 * @param string $key Lookup key.
 * @param int $fallback Fallback value.
 * @return int Integer result for the caller.
 */
function view_admin_storage_int(array $statistics, string $key, int $fallback = 0): int
{
    return (int) ($statistics[$key] ?? $fallback);
}

/**
 * Return a safe array from a storage statistics array.
 *
 * @param array $statistics Statistics value.
 * @param string $key Lookup key.
 * @return array<int array<string, mixed>>.
 */
function view_admin_storage_array(array $statistics, string $key): array
{
    return is_array($statistics[$key] ?? null) ? $statistics[$key] : [];
}
