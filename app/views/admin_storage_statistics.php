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
 *   2026-06-08
 */

declare(strict_types=1);

/**
 * Render the dedicated Admin storage statistics page.
 *
 * @param ?array $statistics Statistics value.
 * @param ?array $databaseUsage Database usage value.
 * @param string $activeTab Active tab value.
 */
function view_render_admin_storage_statistics_page(?array $statistics, ?array $databaseUsage = null, string $activeTab = 'files'): void
{
    $activeTab = view_admin_storage_statistics_normalize_tab($activeTab);
    render_header(t('admin.storage.page_title', 'Storage statistics'));

    echo '<section class="hero admin-dashboard-hero admin-storage-hero"><div><p class="admin-kicker">' . e(t('admin.storage.kicker', 'Storage')) . '</p><h1>' . e(t('admin.storage.page_title', 'Storage statistics')) . '</h1><p class="muted">' . e(t('admin.storage.page_description', 'Detailed media storage statistics are calculated only when you request them. The normal dashboard keeps using the cheap source-file total.')) . '</p></div>';
    echo '<div class="admin-hero-actions"><a class="button secondary" href="' . e(url_for('admin')) . '">' . e(t('admin.storage.back_to_dashboard', 'Back to dashboard')) . '</a></div></section>';

    view_render_admin_storage_statistics_tabs($activeTab);

    if ($activeTab === 'database') {
        if (function_exists('view_render_admin_database_usage_panel')) {
            view_render_admin_database_usage_panel($databaseUsage);
        }
        render_footer();
        return;
    }

    echo '<section class="panel admin-storage-update-shell" data-admin-storage-statistics data-update-url="' . e(url_for('admin_storage_statistics_update')) . '" data-csrf-token="' . e(csrf_token()) . '">';
    echo '<div class="admin-panel-heading admin-storage-heading"><div><p class="admin-kicker">' . e(t('admin.storage.manual_update_kicker', 'Manual scan')) . '</p><h2>' . e(t('admin.storage.manual_update_title', 'Populate detailed statistics')) . '</h2></div><p class="muted">' . e(t('admin.storage.manual_update_hint', 'Click the update button to scan expected generated thumbnail and display-master files. Progress is processed in small browser-driven batches.')) . '</p></div>';
    echo '<div class="admin-storage-update-actions"><button type="button" class="button" data-admin-storage-update-button>' . e(t('admin.storage.update_button', 'Update statistics')) . '</button><span class="muted" data-admin-storage-status aria-live="polite">' . e(view_admin_storage_snapshot_status($statistics)) . '</span></div>';
    echo '<div class="admin-storage-progress" data-admin-storage-progress hidden><div class="admin-storage-progress-bar" aria-hidden="true"><span data-admin-storage-progress-fill style="--admin-storage-progress: 0%"></span></div><div class="admin-storage-progress-meta"><span data-admin-storage-progress-label>' . e(t('admin.storage.progress_waiting', 'Waiting to start.')) . '</span><span data-admin-storage-progress-count></span></div></div>';
    echo '</section>';

    echo '<div data-admin-storage-results>';
    if (is_array($statistics) && $statistics !== []) {
        view_render_admin_storage_statistics_panel($statistics);
    } else {
        view_render_admin_storage_empty_panel();
    }
    echo '</div>';

    render_footer();
}

/**
 * Render the local tab navigation for the dedicated storage statistics page.
 *
 * @param string $activeTab Active tab value.
 */
function view_render_admin_storage_statistics_tabs(string $activeTab): void
{
    $activeTab = view_admin_storage_statistics_normalize_tab($activeTab);
    $tabs = [
        'files' => [
            'label' => t('admin.storage.tab_files', 'Files'),
            'hint' => t('admin.storage.tab_files_hint', 'Source photos, thumbnails, and display masters'),
            'url' => url_for('admin_storage_statistics', ['tab' => 'files']),
        ],
        'database' => [
            'label' => t('admin.storage.tab_database', 'Database'),
            'hint' => t('admin.storage.tab_database_hint', 'MySQL/MariaDB table storage'),
            'url' => url_for('admin_storage_statistics', ['tab' => 'database']),
        ],
    ];

    echo '<nav class="admin-storage-tabs panel" aria-label="' . e(t('admin.storage.tabs_aria', 'Storage statistics sections')) . '">';
    echo '<div class="admin-storage-tab-list" role="tablist">';
    foreach ($tabs as $tabKey => $tab) {
        $isActive = $activeTab === $tabKey;
        $className = $isActive ? 'admin-storage-tab is-active' : 'admin-storage-tab';
        echo '<a class="' . e($className) . '" role="tab" aria-selected="' . ($isActive ? 'true' : 'false') . '" href="' . e((string) $tab['url']) . '">';
        echo '<span>' . e((string) $tab['label']) . '</span><small>' . e((string) $tab['hint']) . '</small>';
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
    return in_array($activeTab, ['files', 'database'], true) ? $activeTab : 'files';
}

/**
 * Render an empty placeholder before the first statistics scan exists.
 */
function view_render_admin_storage_empty_panel(): void
{
    echo '<section class="admin-storage-panel panel admin-storage-empty-panel"><div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.storage.kicker', 'Storage')) . '</p><h2>' . e(t('admin.storage.no_snapshot_title', 'No detailed statistics yet')) . '</h2></div><p class="muted">' . e(t('admin.storage.no_snapshot_hint', 'Use Update statistics to populate source breakdowns, generated thumbnail totals, and charts.')) . '</p></div></section>';
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
 * @param array $statistics Statistics value.
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
    echo '<div class="admin-panel-heading admin-storage-heading"><div><p class="admin-kicker">' . e(t('admin.storage.kicker', 'Storage')) . '</p><h2>' . e(t('admin.storage.title', 'Media storage details')) . '</h2></div><p class="muted">' . e(t('admin.storage.description', 'Source photos are counted from the database. Generated thumbnails and DNG display masters are counted from expected derivative files.')) . '</p></div>';

    echo '<div class="admin-storage-summary-grid">';
    view_render_admin_storage_summary_card(t('admin.storage.source_only', 'Source photos only'), admin_dashboard_format_bytes($originalBytes), t('admin.storage.source_only_hint', '{count} indexed image(s), excluding generated thumbnails.', ['count' => (string) $imageCount]));
    view_render_admin_storage_summary_card(t('admin.storage.generated_thumbnails', 'Generated thumbnails'), admin_dashboard_format_bytes($thumbnailBytes), t('admin.storage.generated_thumbnails_hint', '{count} generated JPG/WebP thumbnail file(s).', ['count' => (string) $thumbnailCount]));
    view_render_admin_storage_summary_card(t('admin.storage.display_masters', 'Display masters'), admin_dashboard_format_bytes($displayMasterBytes), t('admin.storage.display_masters_hint', '{count} generated DNG browser-display master file(s).', ['count' => (string) $displayMasterCount]));
    view_render_admin_storage_summary_card(t('admin.storage.total_with_generated', 'All picture storage'), admin_dashboard_format_bytes($totalPictureBytes), t('admin.storage.total_with_generated_hint', 'Source photos plus generated picture derivatives. Generated media is {percent}% of source size.', ['percent' => number_format($generatedPercent, 1)]));
    echo '</div>';

    echo '<div class="admin-storage-facts">';
    echo '<span><strong>' . e(t('admin.storage.average_source_size', 'Average source size')) . '</strong> ' . e(admin_dashboard_format_bytes($averageOriginalBytes)) . '</span>';
    echo '<span><strong>' . e(t('admin.storage.largest_source', 'Largest source')) . '</strong> ' . e(admin_dashboard_format_bytes($largestOriginalBytes)) . ($largestOriginalName !== '' ? ' <em>' . e($largestOriginalName) . '</em>' : '') . '</span>';
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

    echo '<div class="admin-storage-chart-grid">';
    view_render_admin_storage_bar_chart(t('admin.storage.file_types_title', 'Source file types'), t('admin.storage.file_types_hint', 'Grouped by file extension and weighted by source bytes.'), view_admin_storage_array($statistics, 'type_rows'), t('admin.storage.empty_file_types', 'No indexed images yet.'));
    view_render_admin_storage_bar_chart(t('admin.storage.size_buckets_title', 'Source file sizes'), t('admin.storage.size_buckets_hint', 'Grouped by original file-size range and weighted by source bytes.'), view_admin_storage_array($statistics, 'size_bucket_rows'), t('admin.storage.empty_size_buckets', 'No source-size information yet.'));
    view_render_admin_storage_bar_chart(t('admin.storage.largest_galleries_title', 'Largest galleries'), t('admin.storage.largest_galleries_hint', 'Top galleries by indexed source-photo bytes.'), view_admin_storage_array($statistics, 'largest_gallery_rows'), t('admin.storage.empty_largest_galleries', 'No galleries with indexed images yet.'));
    view_render_admin_storage_bar_chart(t('admin.storage.generated_types_title', 'Generated media types'), t('admin.storage.generated_types_hint', 'Generated thumbnail and display-master files found on disk.'), view_admin_storage_array($statistics, 'generated_type_rows'), t('admin.storage.empty_generated_types', 'No generated picture derivatives were found.'));
    echo '</div>';
    echo '</section>';
}

/**
 * Render one compact storage summary card.
 *
 * @param string $label Label value.
 * @param string $value Value to process.
 * @param string $hint Hint value.
 */
function view_render_admin_storage_summary_card(string $label, string $value, string $hint): void
{
    echo '<article class="admin-storage-summary-card"><span>' . e($label) . '</span><strong>' . e($value) . '</strong><small>' . e($hint) . '</small></article>';
}

/**
 * Render a CSS-based horizontal bar chart.
 *
 * @param string $title Title value.
 * @param string $hint Hint value.
 * @param array $rows Rows to process.
 * @param string $emptyText Empty text value.
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
            'size' => admin_dashboard_format_bytes($bytes),
            'count' => (string) $count,
        ]);
        if ($path !== '') {
            $details .= ' · ' . $path;
        }
        echo '<div class="admin-storage-bar-row">';
        echo '<div class="admin-storage-bar-meta"><span>' . e($label) . '</span><small>' . e($details) . '</small></div>';
        echo '<div class="admin-storage-bar-track" aria-hidden="true"><span class="admin-storage-bar-fill" style="--admin-storage-bar: ' . e(number_format($percent, 1, '.', '')) . '%"></span></div>';
        echo '</div>';
    }
    echo '</div></article>';
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
