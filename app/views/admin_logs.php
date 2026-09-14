<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_logs.php
 * Module Type: View
 *
 * Purpose:
 *   Renders Admin log page chrome and the live-log filter/results workspace.
 *
 * Responsibilities:
 *   - Render the shared Admin log heading and subsection navigation
 *   - Render controller-prepared live-log filters and result table shell
 *   - Render presentation-only empty state markup for AJAX responses
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
 *   - Do not read request globals from this view.
 *   - Do not call models or domain services from this view.
 *   - Log rows and pagination are trusted fragments prepared by controller-owned compatibility renderers until their dedicated Stage 3 extraction.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;

/**
 * Render the shared Admin log heading and server-backed subsection navigation.
 *
 * @param array<string, mixed> $viewModel Controller-prepared heading state.
 */
function view_render_admin_log_page_heading(array $viewModel): void
{
    $labels = (array) ($viewModel['labels'] ?? []);
    $urls = (array) ($viewModel['urls'] ?? []);
    $activeSection = (string) ($viewModel['active_section'] ?? 'logs');

    echo '<section class="hero"><h1>' . e((string) ($labels['title'] ?? 'Admin log')) . '</h1><p>' . e((string) ($labels['intro'] ?? '')) . '</p><nav class="nav">';
    echo '<a class="button secondary" href="' . e((string) ($urls['admin'] ?? '')) . '">' . e((string) ($labels['back_to_dashboard'] ?? 'Back to dashboard')) . '</a>';
    if (!empty($viewModel['telemetry_enabled'])) {
        echo '<a class="button secondary" href="' . e((string) ($urls['telemetry'] ?? '')) . '">' . e((string) ($labels['anonymous_telemetry'] ?? 'Anonymous telemetry')) . '</a>';
    }
    echo '</nav></section>';
    view_render_admin_log_section_tabs($viewModel);
}

/**
 * Render server-backed Admin Logs subsection navigation.
 *
 * @param array<string, mixed> $viewModel Controller-prepared heading state.
 */
function view_render_admin_log_section_tabs(array $viewModel): void
{
    $labels = (array) ($viewModel['labels'] ?? []);
    $urls = (array) ($viewModel['urls'] ?? []);
    $activeSection = (string) ($viewModel['active_section'] ?? 'logs');

    echo '<nav class="admin-subtabs admin-log-section-tabs" aria-label="' . e((string) ($labels['sections_aria'] ?? 'Admin log sections')) . '">';
    echo '<div class="admin-subtab-list">';
    echo '<a class="admin-subtab' . ($activeSection === 'logs' ? ' is-active' : '') . '" href="' . e((string) ($urls['logs'] ?? '')) . '"' . ($activeSection === 'logs' ? ' aria-current="page"' : '') . '>' . e((string) ($labels['section_logs'] ?? 'Logs')) . '</a>';
    echo '<a class="admin-subtab' . ($activeSection === 'maintenance' ? ' is-active' : '') . '" href="' . e((string) ($urls['maintenance'] ?? '')) . '"' . ($activeSection === 'maintenance' ? ' aria-current="page"' : '') . '>' . e((string) ($labels['section_maintenance'] ?? 'Maintenance & archives')) . '</a>';
    echo '</div></nav>';
}

/**
 * Render the Admin log empty-result fragment shared by full-page and AJAX responses.
 *
 * @param string $message Empty-state message.
 * @return string Rendered empty state markup.
 */
function view_render_admin_log_empty(string $message): string
{
    return '<p>' . e($message) . '</p>';
}



/**
 * Render the compact legacy Admin log row used by compatibility call sites.
 *
 * @param array<string,mixed> $entry Controller-prepared log entry.
 * @param string $contextJson Compact context JSON, or an empty string.
 * @return string Rendered table row.
 */
function view_render_admin_log_legacy_row(array $entry, string $contextJson = ''): string
{
    return '<tr>'
        . '<td>' . e((string) ($entry['created_at'] ?? '')) . '</td>'
        . '<td>' . e((string) ($entry['event_key'] ?? '')) . '</td>'
        . '<td>' . e((string) ($entry['message'] ?? '')) . ($contextJson !== '' ? '<div class="muted">' . e($contextJson) . '</div>' : '') . '</td>'
        . '<td>' . e((string) ($entry['username'] ?? '')) . '</td>'
        . '</tr>';
}

/**
 * Render live Admin log pagination from controller-prepared links.
 *
 * @param array<string,mixed> $viewModel Pagination presentation model.
 * @return string Rendered pagination markup.
 */
function view_render_admin_log_pagination(array $viewModel): string
{
    $page = max(1, (int) ($viewModel['page'] ?? 1));
    $totalPages = max(1, (int) ($viewModel['total_pages'] ?? 1));
    $links = is_array($viewModel['links'] ?? null) ? $viewModel['links'] : [];
    ob_start();
    echo '<nav class="pagination admin-log-pagination" data-admin-log-pagination aria-label="' . e((string) ($viewModel['aria_label'] ?? 'Pagination')) . '">';
    echo '<span class="pagination-status">' . e((string) ($viewModel['range_label'] ?? '')) . '</span>';
    if ($totalPages > 1) {
        echo '<a class="pagination-link' . ($page <= 1 ? ' is-disabled' : '') . '" href="' . e((string) ($viewModel['previous_url'] ?? '')) . '" data-admin-log-page-link="' . max(1, $page - 1) . '">' . e((string) ($viewModel['previous_label'] ?? 'Previous')) . '</a>';
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }
            if (!empty($link['gap_before'])) {
                echo '<span class="pagination-gap">...</span>';
            }
            $number = max(1, (int) ($link['page'] ?? 1));
            if (!empty($link['current'])) {
                echo '<span class="pagination-link is-current" aria-current="page">' . e((string) $number) . '</span>';
            } else {
                echo '<a class="pagination-link" href="' . e((string) ($link['url'] ?? '')) . '" data-admin-log-page-link="' . $number . '">' . e((string) $number) . '</a>';
            }
        }
        echo '<a class="pagination-link' . ($page >= $totalPages ? ' is-disabled' : '') . '" href="' . e((string) ($viewModel['next_url'] ?? '')) . '" data-admin-log-page-link="' . min($totalPages, $page + 1) . '">' . e((string) ($viewModel['next_label'] ?? 'Next')) . '</a>';
        echo '<span class="pagination-status">' . e((string) ($viewModel['status_label'] ?? '')) . '</span>';
    }
    echo '</nav>';
    return (string) ob_get_clean();
}

/**
 * Render the live Admin log filter and results workspace.
 *
 * @param array<string, mixed> $viewModel Controller-prepared presentation state.
 */
function view_render_admin_logs_live(array $viewModel): void
{
    $labels = (array) ($viewModel['labels'] ?? []);
    $urls = (array) ($viewModel['urls'] ?? []);
    $categoryOptions = (array) ($viewModel['category_options'] ?? []);
    $severityOptions = (array) ($viewModel['severity_options'] ?? []);
    $statusOptions = (array) ($viewModel['status_options'] ?? []);
    $pageSizeOptions = (array) ($viewModel['page_size_options'] ?? []);
    $selectedSeverities = array_values((array) ($viewModel['selected_severities'] ?? []));
    $category = (string) ($viewModel['category'] ?? '');
    $timeSort = (string) ($viewModel['time_sort'] ?? 'desc');
    $grouped = !empty($viewModel['grouped']);
    $pageSize = (int) ($viewModel['page_size'] ?? 150);
    $currentPage = (int) ($viewModel['current_page'] ?? 1);
    $query = (string) ($viewModel['query'] ?? '');
    $countText = (string) ($viewModel['count_text'] ?? '');
    $hasLogs = !empty($viewModel['has_logs']);
    $paginationHtml = (string) ($viewModel['pagination_html'] ?? '');
    $rowsHtml = (string) ($viewModel['rows_html'] ?? '');
    $csrfHtml = (string) ($viewModel['csrf_html'] ?? '');
    $notice = (string) ($viewModel['notice'] ?? '');

    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }

    echo '<section class="panel admin-log-filters-panel"><div class="admin-log-filters-header"><div><h2>' . e((string) ($labels['filters'] ?? 'Filters')) . '</h2><p class="muted">' . e((string) ($labels['filters_intro'] ?? '')) . '</p></div><div class="admin-log-filters-header-actions"><a class="button secondary" href="' . e((string) ($urls['export_all_zip'] ?? '')) . '">' . e((string) ($labels['export_all_zip'] ?? 'Export all logs ZIP')) . '</a><span class="admin-log-filter-state" data-admin-log-live-state aria-live="polite"></span></div></div><form method="get" action="' . e((string) ($urls['index'] ?? '')) . '" class="admin-log-filter-grid" data-admin-log-filter-form data-admin-log-live-url="' . e((string) ($urls['live'] ?? '')) . '" data-admin-log-searching-text="' . e((string) ($labels['searching'] ?? 'Searching...')) . '" data-admin-log-updated-text="' . e((string) ($labels['updated'] ?? 'Updated.')) . '" data-admin-log-failed-text="' . e((string) ($labels['live_search_failed'] ?? 'Live search failed. Use Apply filters.')) . '" data-admin-log-shown-text="' . e((string) ($labels['shown_suffix'] ?? 'shown')) . '" data-admin-log-when-text="' . e((string) ($labels['when'] ?? 'When')) . '">';
    echo '<input type="hidden" name="page" value="admin_logs">';
    echo '<input type="hidden" name="log_page" value="' . $currentPage . '" data-admin-log-page-input>';
    echo '<div class="admin-log-filter-main">';
    echo '<fieldset class="admin-log-filter-group"><legend>' . e((string) ($labels['filter_scope'] ?? 'Log scope')) . '</legend><div class="admin-log-control-grid">';
    echo '<label class="admin-log-filter-control"><span>' . e((string) ($labels['category'] ?? 'Category')) . '</span><select name="category" data-admin-log-live-filter><option value="">' . e((string) ($labels['all_categories'] ?? 'All categories')) . '</option>';
    foreach ($categoryOptions as $value => $label) {
        echo '<option value="' . e((string) $value) . '"' . ($category === (string) $value ? ' selected' : '') . '>' . e((string) $label) . '</option>';
    }
    echo '</select></label>';
    echo '<label class="admin-log-filter-control"><span>' . e((string) ($labels['time_order'] ?? 'Time order')) . '</span><select name="time_sort" data-admin-log-live-filter><option value="desc"' . ($timeSort === 'desc' ? ' selected' : '') . '>' . e((string) ($labels['newest_first'] ?? 'Newest first')) . '</option><option value="asc"' . ($timeSort === 'asc' ? ' selected' : '') . '>' . e((string) ($labels['oldest_first'] ?? 'Oldest first')) . '</option></select></label>';
    echo '<label class="admin-log-filter-control"><span>' . e((string) ($labels['grouping'] ?? 'Grouping')) . '</span><select name="grouped" data-admin-log-live-filter><option value="1"' . ($grouped ? ' selected' : '') . '>' . e((string) ($labels['group_similar'] ?? 'Group similar events')) . '</option><option value="0"' . (!$grouped ? ' selected' : '') . '>' . e((string) ($labels['show_individual'] ?? 'Show individual rows')) . '</option></select></label>';
    echo '<label class="admin-log-filter-control"><span>' . e((string) ($labels['per_page'] ?? 'Rows per page')) . '</span><select name="per_page" data-admin-log-live-filter>';
    foreach ($pageSizeOptions as $option) {
        $optionValue = (int) $option;
        echo '<option value="' . $optionValue . '"' . ($pageSize === $optionValue ? ' selected' : '') . '>' . $optionValue . '</option>';
    }
    echo '</select></label>';
    echo '</div></fieldset>';
    echo '<fieldset class="admin-log-severity-filter admin-log-filter-group" data-admin-log-severity-filter data-all-text="' . e((string) ($labels['severity_filter_all_summary'] ?? 'All severities are shown.')) . '" data-active-template="' . e((string) ($labels['severity_filter_active_summary'] ?? 'Active severities: {values}')) . '"><legend><span>' . e((string) ($labels['severity'] ?? 'Severity')) . '</span><span class="admin-log-severity-count">' . e((string) count($selectedSeverities)) . '</span></legend>';
    echo '<input type="hidden" name="severity_filter_submitted" value="1">';
    echo '<p class="admin-log-filter-help">' . e((string) ($labels['severity_filter_hint'] ?? '')) . '</p>';
    echo '<div class="admin-log-severity-options">';
    foreach ($severityOptions as $value => $label) {
        $valueString = (string) $value;
        echo '<label class="admin-log-severity-choice is-' . e($valueString) . '"><input class="admin-log-severity-checkbox" type="checkbox" name="severities[]" value="' . e($valueString) . '"' . (in_array($valueString, $selectedSeverities, true) ? ' checked' : '') . ' data-admin-log-live-filter> <span>' . e((string) $label) . '</span></label>';
    }
    echo '</div><p class="admin-log-severity-summary" data-admin-log-severity-summary>' . e((string) ($viewModel['severity_summary'] ?? '')) . '</p></fieldset>';
    echo '</div>';
    echo '<div class="admin-log-filter-footer">';
    echo '<label class="admin-log-filter-control admin-log-search-control"><span>' . e((string) ($labels['search'] ?? 'Search')) . '</span><input name="q" value="' . e($query) . '" placeholder="' . e((string) ($labels['search_placeholder'] ?? '')) . '" autocomplete="off" data-admin-log-live-search></label>';
    echo '<div class="admin-log-filter-actions"><button type="submit">' . e((string) ($labels['apply_filters'] ?? 'Apply filters')) . '</button><a class="button secondary" href="' . e((string) ($urls['reset_severity'] ?? '')) . '">' . e((string) ($labels['reset_severity_filter'] ?? 'Reset severity filter')) . '</a></div>';
    echo '</div>';
    echo '</form></section>';

    echo '<section class="panel" data-admin-log-results><h2>' . e((string) ($labels['entries'] ?? 'Entries')) . ' <span class="muted" data-admin-log-count>(' . e($countText) . ')</span></h2>';
    echo $paginationHtml;
    if (!$hasLogs) {
        echo '<div data-admin-log-empty>' . view_render_admin_log_empty((string) ($labels['no_entries_match'] ?? 'No log entries match the current filters.')) . '</div>';
    }
    echo '<div class="admin-log-table-wrap">';
    echo '<table class="admin-log-table"><thead><tr><th>' . e((string) ($labels['select'] ?? 'Select')) . '</th><th><a href="' . e((string) ($urls['time_sort'] ?? '')) . '" data-admin-log-time-sort-link data-next-sort="' . e((string) ($viewModel['next_time_sort'] ?? 'asc')) . '">' . e((string) ($labels['when'] ?? 'When')) . ' ' . e((string) ($viewModel['time_sort_symbol'] ?? '↓')) . '</a></th><th>' . e((string) ($labels['instances'] ?? 'Instances')) . '</th><th>' . e((string) ($labels['severity'] ?? 'Severity')) . '</th><th>' . e((string) ($labels['category'] ?? 'Category')) . '</th><th>' . e((string) ($labels['event'] ?? 'Event')) . '</th><th>' . e((string) ($labels['message'] ?? 'Message')) . '</th><th>' . e((string) ($labels['by'] ?? 'By')) . '</th></tr></thead><tbody data-admin-log-tbody>';
    echo $rowsHtml;
    echo '</tbody></table></div><form id="admin-log-bulk-form" method="post" action="' . e((string) ($urls['update'] ?? '')) . '">' . $csrfHtml;
    echo '<div class="bulk-row"><label>' . e((string) ($labels['bulk_set_selected'] ?? 'Bulk set selected')) . '<select name="status">';
    foreach ($statusOptions as $value => $label) {
        echo '<option value="' . e((string) $value) . '">' . e((string) $label) . '</option>';
    }
    echo '</select></label><button type="submit" name="action" value="bulk">' . e((string) ($labels['apply_to_selected'] ?? 'Apply to selected')) . '</button><span class="muted">' . e((string) ($labels['bulk_grouping_hint'] ?? '')) . '</span></div></form></section>';
}

/**
 * Render one bounded chunk of raw grouped Admin log instances.
 *
 * @param array<int, array<string, mixed>> $members Controller-prepared member presentation rows.
 * @param string $requestPrefix Localized request label.
 * @return string Rendered grouped member rows.
 */
function view_render_admin_log_group_member_rows(array $members, string $requestPrefix): string
{
    ob_start();
    foreach ($members as $member) {
        echo '<div class="admin-log-group-instance">';
        echo '<strong>#' . e((string) ($member['display_index'] ?? '')) . '</strong> ';
        echo e((string) ($member['created_at'] ?? ''));
        echo ' | ID ' . e((string) ($member['id'] ?? '0'));
        echo ' | ' . e((string) ($member['severity'] ?? $member['level'] ?? ''));
        if (!empty($member['username'])) {
            echo ' | ' . e((string) $member['username']);
        }
        if (!empty($member['request_id'])) {
            echo ' | ' . e($requestPrefix) . ' ' . e((string) $member['request_id']);
        }
        if (!empty($member['route_name'])) {
            echo ' | ' . e((string) $member['route_name']);
        }
        $message = (string) ($member['message'] ?? '');
        $contextJson = (string) ($member['context_compact_json'] ?? '');
        if ($message !== '') {
            echo '<pre>' . e($message);
            if ($contextJson !== '') {
                echo "\n" . e($contextJson);
            }
            echo '</pre>';
        } elseif ($contextJson !== '') {
            echo '<pre>' . e($contextJson) . '</pre>';
        }
        echo '</div>';
    }
    return (string) ob_get_clean();
}

/**
 * Render Admin log table rows for full-page and live-search responses.
 *
 * @param array<int, array<string, mixed>> $logs Controller-prepared visible log row models.
 * @param array<string, string> $labels Localized presentation labels.
 * @return string Rendered table rows.
 */
function view_render_admin_log_table_rows(array $logs, array $labels): string
{
    ob_start();
    foreach ($logs as $entry) {
        $groupCount = max(1, (int) ($entry['group_count'] ?? 1));
        $firstCreatedAt = (string) ($entry['first_created_at'] ?? $entry['created_at'] ?? '');
        $latestCreatedAt = (string) ($entry['latest_created_at'] ?? $entry['created_at'] ?? '');
        $createdAtLabel = (string) ($entry['created_at'] ?? '');
        $severity = (string) ($entry['severity'] ?? $entry['level'] ?? 'info');

        echo '<tr data-admin-log-row>';
        echo '<td><input type="checkbox" name="log_ids[]" value="' . e((string) ($entry['selection_value'] ?? '')) . '" form="admin-log-bulk-form"></td>';
        echo '<td data-admin-log-created-at>' . e($createdAtLabel);
        if ($groupCount > 1 && $firstCreatedAt !== $latestCreatedAt) {
            echo '<div class="muted">' . e($firstCreatedAt) . ' - ' . e($latestCreatedAt) . '</div>';
        }
        echo '</td>';
        echo '<td><strong>' . e((string) $groupCount) . '</strong><div class="muted">' . e($groupCount === 1 ? (string) ($labels['group_count_one'] ?? 'entry') : (string) ($labels['group_count_many'] ?? 'entries')) . '</div></td>';
        echo '<td><span class="log-severity log-severity-' . e($severity) . '">' . e($severity) . '</span></td>';
        echo '<td>' . e((string) ($entry['category'] ?? 'other')) . '</td>';
        echo '<td><details class="log-entry-details"><summary><code>' . e((string) ($entry['event_key'] ?? '')) . '</code></summary>';
        echo '<div class="log-detail-actions"><a class="button secondary" href="' . e((string) ($entry['export_url'] ?? '')) . '">' . e((string) ($labels['save_details_txt'] ?? 'Save details as TXT')) . '</a></div>';
        echo '<dl class="log-detail-list">';
        echo '<dt>' . e((string) ($labels['log_id'] ?? 'Log ID')) . '</dt><dd>' . (int) ($entry['id'] ?? 0) . '</dd>';
        echo '<dt>' . e((string) ($labels['group_count'] ?? 'Grouped entries')) . '</dt><dd>' . e((string) $groupCount) . '</dd>';
        if ($groupCount > 1) {
            echo '<dt>' . e((string) ($labels['first_seen'] ?? 'First seen')) . '</dt><dd>' . e($firstCreatedAt) . '</dd>';
            echo '<dt>' . e((string) ($labels['latest_seen'] ?? 'Latest seen')) . '</dt><dd>' . e($latestCreatedAt) . '</dd>';
        }
        echo '<dt>' . e((string) ($labels['created_at'] ?? 'Created at')) . '</dt><dd>' . e($createdAtLabel) . '</dd>';
        echo '<dt>' . e((string) ($labels['level'] ?? 'Level')) . '</dt><dd>' . e((string) ($entry['level'] ?? '')) . '</dd>';
        echo '<dt>' . e((string) ($labels['severity'] ?? 'Severity')) . '</dt><dd>' . e($severity) . '</dd>';
        echo '<dt>' . e((string) ($labels['category'] ?? 'Category')) . '</dt><dd>' . e((string) ($entry['category'] ?? 'other')) . '</dd>';
        echo '<dt>' . e((string) ($labels['route'] ?? 'Route')) . '</dt><dd>' . e((string) ($entry['route_name'] ?? '')) . '</dd>';
        echo '<dt>' . e((string) ($labels['request_id'] ?? 'Request ID')) . '</dt><dd>' . e((string) ($entry['request_id'] ?? '')) . '</dd>';
        echo '</dl>';
        if ($groupCount > 1 && !empty($entry['members_url'])) {
            echo '<details class="log-context admin-log-group-members" data-admin-log-group-members data-admin-log-group-members-url="' . e((string) $entry['members_url']) . '" data-admin-log-group-total="' . e((string) $groupCount) . '">';
            echo '<summary>' . e((string) ($labels['all_instances'] ?? 'All grouped instances')) . ' (' . e((string) $groupCount) . ')</summary>';
            echo '<div data-admin-log-group-members-list><p class="muted">' . e((string) ($labels['instances_lazy_hint'] ?? '')) . '</p></div>';
            echo '<div class="log-detail-actions"><button type="button" class="button secondary" data-admin-log-group-members-more hidden>' . e((string) ($labels['load_more_instances'] ?? 'Load more instances')) . '</button><span class="muted" data-admin-log-group-members-state aria-live="polite"></span></div>';
            echo '</details>';
        }
        echo '<code>' . e((string) ($entry['event_key'] ?? '')) . '</code>';
        if (!empty($entry['subject_type']) || !empty($entry['subject_id'])) {
            echo '<div class="muted">' . e((string) ($entry['subject_type'] ?? '')) . ' #' . e((string) ($entry['subject_id'] ?? '')) . '</div>';
        }
        echo '</details></td>';
        echo '<td>' . e((string) ($entry['message'] ?? ''));
        if ($groupCount > 1) {
            echo '<div class="muted">' . e((string) ($labels['grouped_row_note'] ?? '')) . '</div>';
        }
        if (!empty($entry['context_pretty_json'])) {
            echo '<details class="log-context"><summary>' . e((string) ($labels['details'] ?? 'Details')) . '</summary><pre>' . e((string) $entry['context_pretty_json']) . '</pre></details>';
        }
        if (!empty($entry['request_id'])) {
            echo '<div class="muted">' . e((string) ($labels['request_prefix'] ?? 'Request')) . ' ' . e((string) $entry['request_id']) . '</div>';
        }
        echo '</td>';
        echo '<td>' . e((string) ($entry['username'] ?? '')) . '</td>';
        echo '</tr>';
    }
    return (string) ob_get_clean();
}

/**
 * Render Admin log archive pagination from controller-prepared links.
 *
 * @param array<string, mixed> $viewModel Pagination labels and URLs.
 * @return string Rendered pagination markup.
 */
function view_render_admin_log_archive_pagination(array $viewModel): string
{
    if (empty($viewModel['visible'])) {
        return '';
    }

    ob_start();
    echo '<nav class="pagination admin-log-archive-pagination" aria-label="' . e((string) ($viewModel['aria_label'] ?? 'Archived log pages')) . '">';
    if (!empty($viewModel['previous_url'])) {
        echo '<a class="pagination-link" href="' . e((string) $viewModel['previous_url']) . '">' . e((string) ($viewModel['previous_label'] ?? 'Previous')) . '</a>';
    }
    echo '<span class="pagination-status">' . e((string) ($viewModel['status_label'] ?? '')) . '</span>';
    if (!empty($viewModel['next_url'])) {
        echo '<a class="pagination-link" href="' . e((string) $viewModel['next_url']) . '">' . e((string) ($viewModel['next_label'] ?? 'Next')) . '</a>';
    }
    echo '</nav>';
    return (string) ob_get_clean();
}

/**
 * Render filesystem-backed Admin log archive controls and archive files.
 *
 * @param array<string, mixed> $viewModel Controller-prepared archive presentation model.
 */
function view_render_admin_log_archive_panel(array $viewModel): void
{
    $labels = (array) ($viewModel['labels'] ?? []);
    $metrics = (array) ($viewModel['metrics'] ?? []);
    $retentionOptions = (array) ($viewModel['retention_options'] ?? []);
    $items = (array) ($viewModel['items'] ?? []);
    $maintenanceUrl = (string) ($viewModel['maintenance_url'] ?? '');
    $csrfHtml = (string) ($viewModel['csrf_html'] ?? '');
    $paginationHtml = (string) ($viewModel['pagination_html'] ?? '');
    $retentionDays = (int) ($viewModel['retention_days'] ?? 30);
    $notice = (string) ($viewModel['notice'] ?? '');

    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }

    echo '<section class="panel admin-log-archive-panel">';
    echo '<div class="admin-log-archive-heading"><div><h2>' . e((string) ($labels['maintenance_title'] ?? 'Planned Admin log maintenance')) . '</h2><p class="muted">' . e((string) ($labels['maintenance_intro'] ?? '')) . '</p></div></div>';

    if (empty($viewModel['zip_available'])) {
        echo '<div class="notice">' . e((string) ($labels['zip_unavailable'] ?? '')) . '</div>';
    }

    echo '<div class="admin-log-archive-controls">';
    echo '<form method="post" action="' . e($maintenanceUrl) . '" class="admin-log-archive-retention-form">' . $csrfHtml;
    echo '<input type="hidden" name="action" value="save_retention">';
    echo '<label><span>' . e((string) ($labels['keep_live_logs'] ?? 'Keep live logs')) . '</span><select name="retention_days">';
    foreach ($retentionOptions as $option) {
        if (!is_array($option)) {
            continue;
        }
        $days = (int) ($option['days'] ?? 0);
        echo '<option value="' . $days . '"' . ($retentionDays === $days ? ' selected' : '') . '>' . e((string) ($option['label'] ?? '')) . '</option>';
    }
    echo '</select></label><button type="submit" class="secondary">' . e((string) ($labels['save_retention'] ?? 'Save retention')) . '</button></form>';

    echo '<form method="post" action="' . e($maintenanceUrl) . '" class="admin-log-archive-run-form">' . $csrfHtml;
    echo '<input type="hidden" name="action" value="run_now">';
    echo '<button type="submit">' . e((string) ($labels['run_now'] ?? 'Run maintenance cycle now')) . '</button>';
    echo '</form>';
    echo '</div>';

    echo '<p class="muted admin-log-archive-policy">' . e((string) ($labels['policy'] ?? '')) . '</p>';

    echo '<dl class="admin-log-archive-metrics">';
    echo '<div><dt>' . e((string) ($labels['live_retention'] ?? 'Live retention')) . '</dt><dd>' . e((string) ($metrics['retention'] ?? '')) . '</dd></div>';
    echo '<div><dt>' . e((string) ($labels['archived_zips'] ?? 'Archived ZIPs')) . '</dt><dd>' . e((string) ($metrics['count'] ?? '0')) . '</dd></div>';
    echo '<div><dt>' . e((string) ($labels['storage'] ?? 'Archive storage')) . '</dt><dd>' . e((string) ($metrics['storage'] ?? '')) . '</dd></div>';
    echo '<div><dt>' . e((string) ($labels['oldest'] ?? 'Oldest archive')) . '</dt><dd>' . e((string) ($metrics['oldest'] ?? '')) . '</dd></div>';
    echo '<div><dt>' . e((string) ($labels['newest'] ?? 'Newest archive')) . '</dt><dd>' . e((string) ($metrics['newest'] ?? '')) . '</dd></div>';
    echo '<div><dt>' . e((string) ($labels['next_check'] ?? 'Next automatic check')) . '</dt><dd>' . e((string) ($metrics['next_check'] ?? '')) . '</dd></div>';
    echo '</dl>';

    if (!empty($viewModel['last_summary'])) {
        echo '<p class="muted admin-log-archive-last-result">' . e((string) $viewModel['last_summary']) . '</p>';
    }

    echo '<div class="admin-log-archive-heading admin-log-archive-files-heading"><div><h3>' . e((string) ($labels['files_title'] ?? 'Archived logs')) . '</h3><p class="muted">' . e((string) ($labels['files_intro'] ?? '')) . '</p></div></div>';
    echo $paginationHtml;
    if ($items === []) {
        echo '<p class="muted">' . e((string) ($labels['none_yet'] ?? 'No Admin log ZIP archives exist yet.')) . '</p>';
    } else {
        echo '<div class="admin-log-table-wrap"><table class="admin-log-archive-table"><thead><tr>';
        echo '<th>' . e((string) ($labels['date'] ?? 'Date')) . '</th>';
        echo '<th>' . e((string) ($labels['records'] ?? 'Records')) . '</th>';
        echo '<th>' . e((string) ($labels['zip_size'] ?? 'ZIP size')) . '</th>';
        echo '<th>' . e((string) ($labels['created'] ?? 'Created')) . '</th>';
        echo '<th>' . e((string) ($labels['actions'] ?? 'Actions')) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            echo '<tr><td><strong>' . e((string) ($item['date'] ?? '')) . '</strong><div class="muted">' . e((string) ($item['file_name'] ?? '')) . '</div></td>';
            echo '<td>' . e((string) ($item['manifest_value'] ?? '')) . '</td>';
            echo '<td>' . e((string) ($item['size_label'] ?? '')) . '</td>';
            echo '<td>' . e((string) ($item['created_at_label'] ?? '')) . '</td>';
            echo '<td><div class="admin-log-archive-actions">';
            echo '<a class="button secondary" target="_blank" rel="noopener" href="' . e((string) ($item['html_url'] ?? '')) . '">' . e((string) ($labels['view_html'] ?? 'View HTML')) . '</a>';
            echo '<a class="button secondary" target="_blank" rel="noopener" href="' . e((string) ($item['json_url'] ?? '')) . '">' . e((string) ($labels['view_json'] ?? 'View JSON')) . '</a>';
            echo '<a class="button secondary" href="' . e((string) ($item['download_url'] ?? '')) . '">' . e((string) ($labels['download_zip'] ?? 'Download ZIP')) . '</a>';
            echo '<form method="post" action="' . e($maintenanceUrl) . '" class="admin-log-archive-delete-form">' . $csrfHtml;
            echo '<input type="hidden" name="action" value="delete_archive"><input type="hidden" name="date" value="' . e((string) ($item['date'] ?? '')) . '">';
            echo '<button type="submit" class="secondary danger" onclick="return confirm(' . e((string) ($item['delete_confirm_json'] ?? '""')) . ');">' . e((string) ($labels['delete'] ?? 'Delete')) . '</button>';
            echo '</form></div></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo $paginationHtml;
    echo '</section>';
}
