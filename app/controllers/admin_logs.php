<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_logs.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles request-level application logic for the related gallery feature.
 *
 * Responsibilities:
 *   - Validate and route incoming request data
 *   - Call service-layer functions where possible
 *   - Return redirects, rendered views, or HTTP responses
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
 *   2026-05-04
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use Throwable;
use RuntimeException;
use function Gallery\Controllers\cms_not_found;
use function Gallery\Core\base_url;
use function Gallery\Core\csrf_field;
use function Gallery\Core\e;
use function Gallery\Core\flash_message;
use function Gallery\Core\redirect_to;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\app_setting;
use function Gallery\Services\delete_app_settings;
use function Gallery\Services\set_app_setting;
use function Gallery\Services\translation_interpolate;
use function Gallery\Services\translation_load_language;
use function Gallery\Views\view_render_admin_feature_flag;
use function Gallery\Services\admin_log_archive_delete_file;
use function Gallery\Services\admin_log_archive_file_name;
use function Gallery\Services\admin_log_archive_list;
use function Gallery\Services\admin_log_archive_maintenance_run;
use function Gallery\Services\admin_log_archive_path;
use function Gallery\Services\admin_log_archive_retention_options;
use function Gallery\Services\admin_log_archive_set_retention_days;
use function Gallery\Services\admin_log_archive_status;
use function Gallery\Services\admin_log_archive_stream_member;
use function Gallery\Services\admin_log_archive_stream_zip;
use function Gallery\Services\admin_log_archive_valid_date;
use function Gallery\Services\admin_dashboard_format_bytes;
use function Gallery\Services\admin_log_event;
use function Gallery\Services\admin_log_category_options;
use function Gallery\Services\admin_log_context_array;
use function Gallery\Services\admin_log_count;
use function Gallery\Services\admin_log_create_export_zip_streamed;
use function Gallery\Services\admin_log_export_group_header_text;
use function Gallery\Services\admin_log_export_temp_path;
use function Gallery\Services\admin_log_export_text;
use function Gallery\Services\admin_log_export_zip_filename;
use function Gallery\Services\admin_log_find;
use function Gallery\Services\admin_log_group_hash_for_entry;
use function Gallery\Services\admin_log_group_member_export_batch;
use function Gallery\Services\admin_log_group_member_page;
use function Gallery\Services\admin_log_group_member_summary;
use function Gallery\Services\admin_log_grouped_count;
use function Gallery\Services\admin_log_grouped_list;
use function Gallery\Services\admin_log_list;
use function Gallery\Services\admin_log_send_export_zip;
use function Gallery\Services\admin_log_severity_options;
use function Gallery\Services\admin_log_status_label;
use function Gallery\Services\admin_log_status_options;
use function Gallery\Services\admin_log_update_group_status;
use function Gallery\Services\admin_log_update_status;
use const Gallery\Services\ADMIN_LOG_GROUP_MEMBER_PAGE_SIZE;

/**
 * Administrative log controller model.
 *
 * This module renders and updates the admin log screen. It is deliberately small
 * and depends on the existing admin_log_* service functions. Keeping it separate
 * lets the diagnostics UI be improved later without touching the main dashboard,
 * public gallery rendering, uploads, or theme customisation code.
 *
 * Function names and request semantics are unchanged. This is a structural split,
 * not a behaviour rewrite.
 */

/**
 * Translate admin-log UI labels in English even when the rest of the admin zone
 * uses another language. This fallback keeps the logs page alive if only this
 * controller is updated during a partial deployment.
 */
if (!function_exists('admin_log_english_t')) {
    /**
     * Handle admin log english t.
     *
     * Used by HTTP controller routing for this workflow.
     *
     * @param string $key Lookup key.
     * @param string|array|null $fallback Fallback value.
     * @param array $parameters Parameters value.
     * @return string Text result for the caller.
     */
    function admin_log_english_t(string $key, string|array|null $fallback = null, array $parameters = []): string
    {
        if (is_array($fallback)) {
            $parameters = $fallback;
            $fallback = null;
        }

        $text = null;
        if (function_exists('Gallery\\Services\\translation_load_language')) {
            $englishStrings = translation_load_language('en');
            if (array_key_exists($key, $englishStrings) && is_string($englishStrings[$key])) {
                $text = $englishStrings[$key];
            }
        }

        if ($text === null) {
            $text = $fallback ?? $key;
        }

        if (function_exists('Gallery\\Services\\translation_interpolate')) {
            return translation_interpolate($text, $parameters);
        }

        foreach ($parameters as $name => $value) {
            $text = str_replace('{' . (string) $name . '}', (string) $value, $text);
        }
        return $text;
    }
}

/**
 * Render admin log row.
 *
 * Used by HTTP controller routing for this workflow.
 *
 * @param array $entry Entry value.
 * @param bool $withActions With actions value.
 * @return string Text result for the caller.
 */
function render_admin_log_row(array $entry, bool $withActions = false): string
{
    unset($withActions);
    // Variable $context stores this steps working value.
    $context = [];
    if (!empty($entry['context_json'])) {
        // $decoded stores an intermediate value used by the surrounding gallery workflow.
        $decoded = json_decode((string) $entry['context_json'], true);
        if (is_array($decoded)) {
            // $context stores an intermediate value used by the surrounding gallery workflow.
            $context = $decoded;
        }
    }
    return '<tr>'
        . '<td>' . e((string) $entry['created_at']) . '</td>'
        . '<td>' . e((string) $entry['event_key']) . '</td>'
        . '<td>' . e((string) $entry['message']) . ($context ? '<div class="muted">' . e(json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</div>' : '') . '</td>'
        . '<td>' . e((string) ($entry['username'] ?? '')) . '</td>'
        . '</tr>';
}

/**
 * Handles render admin feature flag logic for the gallery application.
 *
 * @param mixed $enabled Input used by this operation.
 * @param mixed $symbol Input used by this operation.
 * @param mixed $label Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function render_admin_feature_flag(bool $enabled, string $symbol, string $label): string
{
    if (function_exists('Gallery\\Views\\view_render_admin_feature_flag')) {
        return view_render_admin_feature_flag($enabled, e($symbol), $label);
    }

    if (!$enabled) {
        return '';
    }
    return '<span class="admin-flag is-enabled" title="' . e($label) . '" aria-label="' . e($label) . '">' . e($symbol) . '</span>';
}


/**
 * Return a normalized admin log time order key.
 *
 * @param ?string $timeSort Time sort value.
 * @return string Text result for the caller.
 */
function admin_log_normalize_time_sort(?string $timeSort): string
{
    return strtolower((string) $timeSort) === 'asc' ? 'asc' : 'desc';
}

/**
 * Return the app-settings key used for the persistent admin log severity filter.
 *
 * @return string Text result for the caller.
 */
function admin_log_severity_filter_setting_key(): string
{
    return 'admin_logs_severity_filter_json';
}

/**
 * Return validated severity values while preserving the visible option order.
 *
 * @param mixed $rawValues Raw values value.
 * @return array Structured result data for the caller.
 */
function admin_log_normalize_severity_filter(mixed $rawValues): array
{
    // $values stores the submitted or decoded values before validation.
    $values = is_array($rawValues) ? $rawValues : [$rawValues];
    // $submitted stores normalized string values keyed for quick lookup.
    $submitted = [];
    foreach ($values as $value) {
        if (is_array($value)) {
            foreach ($value as $nestedValue) {
                $submitted[(string) $nestedValue] = true;
            }
            continue;
        }
        // Accept comma-separated values as a defensive fallback for hand-written URLs.
        foreach (explode(',', (string) $value) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $submitted[$part] = true;
            }
        }
    }

    // $normalized stores only severities that are supported by the current schema/UI.
    $normalized = [];
    foreach (array_keys(admin_log_severity_options()) as $severity) {
        if (isset($submitted[$severity])) {
            $normalized[] = $severity;
        }
    }
    return $normalized;
}

/**
 * Decode the persistent severity filter from app settings.
 *
 * @return array Structured result data for the caller.
 */
function admin_log_persisted_severity_filter(): array
{
    // $encoded stores the JSON payload written by the logs filter form.
    $encoded = app_setting(admin_log_severity_filter_setting_key(), '[]');
    // $decoded stores candidate values before option validation.
    $decoded = json_decode((string) $encoded, true);
    return admin_log_normalize_severity_filter(is_array($decoded) ? $decoded : []);
}

/**
 * Persist or clear the severity filter depending on the selected values.
 *
 * @param array $severities Severities value.
 */
function admin_log_save_severity_filter(array $severities): void
{
    // Empty selection is explicit and means the default all-severities state.
    if ($severities === []) {
        delete_app_settings([admin_log_severity_filter_setting_key()]);
        return;
    }
    set_app_setting(admin_log_severity_filter_setting_key(), json_encode(array_values($severities), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * Return true when the current request intentionally changes the severity filter.
 *
 * @return bool True when the condition matches.
 */
function admin_log_request_has_severity_filter_input(): bool
{
    return array_key_exists('severity_filter_submitted', $_GET)
        || array_key_exists('severities', $_GET)
        || array_key_exists('severity', $_GET);
}

/**
 * Resolve selected severities from reset action, request data, or persisted settings.
 *
 * @return array Structured result data for the caller.
 */
function admin_log_resolve_selected_severities(): array
{
    if ((string) ($_GET['reset_severity'] ?? '') === '1') {
        admin_log_save_severity_filter([]);
        return [];
    }

    if (admin_log_request_has_severity_filter_input()) {
        // $rawValues stores the new multi-select field first, then legacy single severity values.
        $rawValues = $_GET['severities'] ?? ($_GET['severity'] ?? []);
        $severities = admin_log_normalize_severity_filter($rawValues);
        admin_log_save_severity_filter($severities);
        return $severities;
    }

    return admin_log_persisted_severity_filter();
}

/**
 * Build compact human-readable text for the active severity filter.
 *
 * @param array $selectedSeverities Selected severities value.
 * @return string Text result for the caller.
 */
function admin_log_severity_filter_summary(array $selectedSeverities): string
{
    if ($selectedSeverities === []) {
        return admin_log_english_t('admin.logs.severity_filter_all_summary', 'All severities are shown.');
    }

    // $labels stores labels in the same order as the checkbox list.
    $labels = [];
    foreach (admin_log_severity_options() as $value => $label) {
        if (in_array($value, $selectedSeverities, true)) {
            $labels[] = $label;
        }
    }
    return admin_log_english_t('admin.logs.severity_filter_active_summary', 'Active severities: {values}', ['values' => implode(', ', $labels)]);
}

/**
 * Return supported admin log page-size choices.
 *
 * @return array Structured result data for the caller.
 */
function admin_log_page_size_options(): array
{
    return [10, 50, 150, 500];
}

/**
 * Return a validated admin log page size.
 *
 * @param mixed $value Value to process.
 * @return int Integer result for the caller.
 */
function admin_log_normalize_page_size(mixed $value): int
{
    // $pageSize stores the requested visible row count.
    $pageSize = (int) $value;
    return in_array($pageSize, admin_log_page_size_options(), true) ? $pageSize : 150;
}

/**
 * Return a validated admin log page number.
 *
 * @param mixed $value Value to process.
 * @return int Integer result for the caller.
 */
function admin_log_normalize_page_number(mixed $value): int
{
    return max(1, (int) $value);
}

/**
 * Return whether similar admin log events should be grouped.
 *
 * @param mixed $value Value to process.
 * @return bool True when the condition matches.
 */
function admin_log_grouping_enabled(mixed $value): bool
{
    return (string) $value !== '0';
}

/**
 * Return the compact result count text used above the admin log table.
 *
 * @param int $shown Shown value.
 * @param int $total Total value.
 * @param bool $grouped Grouped value.
 * @return string Text result for the caller.
 */
function admin_log_result_count_text(int $shown, int $total, bool $grouped): string
{
    // $unit stores the visible item type so grouped counts are not confused with raw log rows.
    $unit = $grouped
        ? admin_log_english_t('admin.logs.grouped_rows', 'groups')
        : admin_log_english_t('admin.logs.raw_rows', 'log rows');
    return admin_log_english_t('admin.logs.result_count', '{shown} shown of {total} {unit}', [
        'shown' => $shown,
        'total' => $total,
        'unit' => $unit,
    ]);
}

/**
 * Return the visible range text for the current admin log page.
 *
 * @param int $page Page number or page data.
 * @param int $perPage Items per page.
 * @param int $total Total value.
 * @return string Text result for the caller.
 */
function admin_log_page_range_text(int $page, int $perPage, int $total): string
{
    if ($total <= 0) {
        return admin_log_english_t('admin.logs.page_range_empty', 'No matching log rows.');
    }
    // $from stores the first one-based visible row number.
    $from = (($page - 1) * $perPage) + 1;
    // $to stores the last one-based visible row number.
    $to = min($total, $from + $perPage - 1);
    return admin_log_english_t('admin.logs.page_range', 'Showing {from}-{to} of {total}', [
        'from' => $from,
        'to' => $to,
        'total' => $total,
    ]);
}

/**
 * Render admin log pagination controls.
 *
 * @param int $page Page number or page data.
 * @param int $totalPages Total pages value.
 * @param int $total Total value.
 * @param int $perPage Items per page.
 * @return string Text result for the caller.
 */
function render_admin_log_pagination(int $page, int $totalPages, int $total, int $perPage): string
{
    // $html stores the rendered pagination control.
    $html = '<nav class="pagination admin-log-pagination" data-admin-log-pagination aria-label="' . e(admin_log_english_t('pagination.label', 'Pagination')) . '">';
    $html .= '<span class="pagination-status">' . e(admin_log_page_range_text($page, $perPage, $total)) . '</span>';
    if ($totalPages > 1) {
        // $previousPage stores the bounded previous page number.
        $previousPage = max(1, $page - 1);
        // $nextPage stores the bounded next page number.
        $nextPage = min($totalPages, $page + 1);
        $html .= '<a class="pagination-link' . ($page <= 1 ? ' is-disabled' : '') . '" href="' . e(admin_log_filter_url(['log_page' => $previousPage])) . '" data-admin-log-page-link="' . $previousPage . '">' . e(admin_log_english_t('pagination.previous', 'Previous')) . '</a>';

        // $pages stores a compact page-number window around the current page.
        $pages = [1, $page - 1, $page, $page + 1, $totalPages];
        $pages = array_values(array_unique(array_filter($pages, static fn (int $candidate): bool => $candidate >= 1 && $candidate <= $totalPages)));
        sort($pages);
        $lastPage = 0;
        foreach ($pages as $pageNumber) {
            if ($lastPage > 0 && $pageNumber > $lastPage + 1) {
                $html .= '<span class="pagination-gap">...</span>';
            }
            if ($pageNumber === $page) {
                $html .= '<span class="pagination-link is-current" aria-current="page">' . e((string) $pageNumber) . '</span>';
            } else {
                $html .= '<a class="pagination-link" href="' . e(admin_log_filter_url(['log_page' => $pageNumber])) . '" data-admin-log-page-link="' . $pageNumber . '">' . e((string) $pageNumber) . '</a>';
            }
            $lastPage = $pageNumber;
        }

        $html .= '<a class="pagination-link' . ($page >= $totalPages ? ' is-disabled' : '') . '" href="' . e(admin_log_filter_url(['log_page' => $nextPage])) . '" data-admin-log-page-link="' . $nextPage . '">' . e(admin_log_english_t('pagination.next', 'Next')) . '</a>';
        $html .= '<span class="pagination-status">' . e(admin_log_english_t('pagination.status', 'Page {current} of {total}', ['current' => $page, 'total' => $totalPages])) . '</span>';
    }
    $html .= '</nav>';
    return $html;
}

/**
 * Build the admin log URL while preserving active filters.
 *
 * @param array $overrides Overrides value.
 * @return string Text result for the caller.
 */
function admin_log_filter_url(array $overrides = []): string
{
    // $params stores query parameters that should remain stable across filter and sort clicks.
    $params = [
        'category' => (string) ($_GET['category'] ?? ''),
        'q' => trim((string) ($_GET['q'] ?? '')),
        'time_sort' => admin_log_normalize_time_sort((string) ($_GET['time_sort'] ?? 'desc')),
        'per_page' => admin_log_normalize_page_size($_GET['per_page'] ?? 150),
        'grouped' => admin_log_grouping_enabled($_GET['grouped'] ?? '1') ? '1' : '0',
        'log_page' => admin_log_normalize_page_number($_GET['log_page'] ?? 1),
    ];

    // Preserve multi-select severities when building sort links. Legacy single severity links
    // are normalized into the new array-shaped query parameter.
    $activeSeverities = admin_log_request_has_severity_filter_input()
        ? admin_log_normalize_severity_filter($_GET['severities'] ?? ($_GET['severity'] ?? []))
        : admin_log_persisted_severity_filter();
    if ($activeSeverities !== []) {
        $params['severities'] = $activeSeverities;
        $params['severity_filter_submitted'] = '1';
    }

    foreach ($overrides as $key => $value) {
        $params[(string) $key] = $value;
    }
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null || $value === []) {
            unset($params[$key]);
        }
    }
    return url_for('admin_logs', $params);
}

/**
 * Render one bounded chunk of raw grouped admin log instances.
 *
 * @param array $members Group member rows.
 * @param int $startIndex Zero-based index of the first rendered member.
 * @return string Text result for the caller.
 */
function render_admin_log_group_member_rows(array $members, int $startIndex = 0): string
{
    ob_start();
    foreach ($members as $index => $member) {
        // $displayIndex stores the stable one-based instance number across lazy pages.
        $displayIndex = max(0, $startIndex) + $index + 1;
        echo '<div class="admin-log-group-instance">';
        echo '<strong>#' . e((string) $displayIndex) . '</strong> ';
        echo e((string) ($member['created_at'] ?? ''));
        echo ' | ID ' . e((string) ($member['id'] ?? '0'));
        echo ' | ' . e((string) ($member['severity'] ?? $member['level'] ?? ''));
        if (!empty($member['username'])) {
            echo ' | ' . e((string) $member['username']);
        }
        if (!empty($member['request_id'])) {
            echo ' | ' . e(admin_log_english_t('admin.logs.request_prefix', 'Request')) . ' ' . e((string) $member['request_id']);
        }
        if (!empty($member['route_name'])) {
            echo ' | ' . e((string) $member['route_name']);
        }
        if (!empty($member['message'])) {
            echo '<pre>' . e((string) $member['message']);
            $memberContext = admin_log_context_array($member);
            if ($memberContext !== []) {
                echo "\n" . e(json_encode($memberContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            echo '</pre>';
        } else {
            $memberContext = admin_log_context_array($member);
            if ($memberContext !== []) {
                echo '<pre>' . e(json_encode($memberContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
            }
        }
        echo '</div>';
    }
    return (string) ob_get_clean();
}

/**
 * Render the admin log table rows for normal page loads and live search responses.
 *
 * @param array $logs Logs value.
 * @return string Text result for the caller.
 */
function render_admin_log_table_rows(array $logs): string
{
    ob_start();
    foreach ($logs as $entry) {
        // $context stores decoded structured context shown under the message.
        $context = [];
        if (!empty($entry['context_json'])) {
            $decoded = json_decode((string) $entry['context_json'], true);
            $context = is_array($decoded) ? $decoded : [];
        }
        // $groupCount stores how many raw log rows this visible row represents.
        $groupCount = max(1, (int) ($entry['group_count'] ?? 1));
        // $firstCreatedAt stores the first timestamp covered by this visible row.
        $firstCreatedAt = (string) ($entry['first_created_at'] ?? $entry['created_at']);
        // $latestCreatedAt stores the latest timestamp covered by this visible row.
        $latestCreatedAt = (string) ($entry['latest_created_at'] ?? $entry['created_at']);
        // $createdAtLabel stores the representative timestamp shown in the main table cell.
        $createdAtLabel = (string) $entry['created_at'];
        // $groupSelectionValue stores the submitted value used by bulk updates.
        $groupSelectionValue = $groupCount > 1 && !empty($entry['group_hash'])
            ? 'group:' . (string) $entry['group_hash']
            : (string) ((int) $entry['id']);
        echo '<tr data-admin-log-row>';
        echo '<td><input type="checkbox" name="log_ids[]" value="' . e($groupSelectionValue) . '" form="admin-log-bulk-form"></td>';
        echo '<td data-admin-log-created-at>' . e($createdAtLabel);
        if ($groupCount > 1 && $firstCreatedAt !== $latestCreatedAt) {
            echo '<div class="muted">' . e($firstCreatedAt) . ' - ' . e($latestCreatedAt) . '</div>';
        }
        echo '</td>';
        echo '<td><strong>' . e((string) $groupCount) . '</strong><div class="muted">' . e($groupCount === 1 ? admin_log_english_t('admin.logs.group_count_one', 'entry') : admin_log_english_t('admin.logs.group_count_many', 'entries')) . '</div></td>';
        echo '<td><span class="log-severity log-severity-' . e((string) ($entry['severity'] ?? $entry['level'] ?? 'info')) . '">' . e((string) ($entry['severity'] ?? $entry['level'] ?? 'info')) . '</span></td>';
        echo '<td>' . e((string) ($entry['category'] ?? 'other')) . '</td>';
        $exportParams = ['id' => (int) $entry['id']];
        if ($groupCount > 1 && !empty($entry['group_hash'])) {
            $exportParams['group'] = (string) $entry['group_hash'];
        }
        echo '<td><details class="log-entry-details"><summary><code>' . e((string) $entry['event_key']) . '</code></summary>';
        echo '<div class="log-detail-actions"><a class="button secondary" href="' . e(url_for('admin_log_export', $exportParams)) . '">' . e(admin_log_english_t('admin.logs.save_details_txt', 'Save details as TXT')) . '</a></div>';
        echo '<dl class="log-detail-list">';
        echo '<dt>' . e(admin_log_english_t('admin.logs.log_id', 'Log ID')) . '</dt><dd>' . (int) $entry['id'] . '</dd>';
        echo '<dt>' . e(admin_log_english_t('admin.logs.group_count', 'Grouped entries')) . '</dt><dd>' . e((string) $groupCount) . '</dd>';
        if ($groupCount > 1) {
            echo '<dt>' . e(admin_log_english_t('admin.logs.first_seen', 'First seen')) . '</dt><dd>' . e($firstCreatedAt) . '</dd>';
            echo '<dt>' . e(admin_log_english_t('admin.logs.latest_seen', 'Latest seen')) . '</dt><dd>' . e($latestCreatedAt) . '</dd>';
        }
        echo '<dt>' . e(admin_log_english_t('admin.logs.created_at', 'Created at')) . '</dt><dd>' . e((string) $entry['created_at']) . '</dd>';
        echo '<dt>' . e(admin_log_english_t('admin.logs.level', 'Level')) . '</dt><dd>' . e((string) ($entry['level'] ?? '')) . '</dd>';
        echo '<dt>' . e(admin_log_english_t('admin.logs.severity', 'Severity')) . '</dt><dd>' . e((string) ($entry['severity'] ?? $entry['level'] ?? 'info')) . '</dd>';
        echo '<dt>' . e(admin_log_english_t('admin.logs.category', 'Category')) . '</dt><dd>' . e((string) ($entry['category'] ?? 'other')) . '</dd>';
        echo '<dt>' . e(admin_log_english_t('admin.logs.route', 'Route')) . '</dt><dd>' . e((string) ($entry['route_name'] ?? '')) . '</dd>';
        echo '<dt>' . e(admin_log_english_t('admin.logs.request_id', 'Request ID')) . '</dt><dd>' . e((string) ($entry['request_id'] ?? '')) . '</dd>';
        echo '</dl>';
        if ($groupCount > 1 && !empty($entry['group_hash'])) {
            // $membersUrl loads raw instances only when the administrator opens this group.
            $membersUrl = url_for('admin_log_group_members', [
                'id' => (int) $entry['id'],
                'group' => (string) $entry['group_hash'],
            ]);
            echo '<details class="log-context admin-log-group-members" data-admin-log-group-members data-admin-log-group-members-url="' . e($membersUrl) . '" data-admin-log-group-total="' . e((string) $groupCount) . '">';
            echo '<summary>' . e(admin_log_english_t('admin.logs.all_instances', 'All grouped instances')) . ' (' . e((string) $groupCount) . ')</summary>';
            echo '<div data-admin-log-group-members-list><p class="muted">' . e(admin_log_english_t('admin.logs.instances_lazy_hint', 'Open this section to load raw instances in small batches.')) . '</p></div>';
            echo '<div class="log-detail-actions"><button type="button" class="button secondary" data-admin-log-group-members-more hidden>' . e(admin_log_english_t('admin.logs.load_more_instances', 'Load more instances')) . '</button><span class="muted" data-admin-log-group-members-state aria-live="polite"></span></div>';
            echo '</details>';
        }
        echo '<code>' . e((string) $entry['event_key']) . '</code>';
        if (!empty($entry['subject_type']) || !empty($entry['subject_id'])) {
            echo '<div class="muted">' . e((string) ($entry['subject_type'] ?? '')) . ' #' . e((string) ($entry['subject_id'] ?? '')) . '</div>';
        }
        echo '</details></td>';
        echo '<td>' . e((string) $entry['message']);
        if ($groupCount > 1) {
            echo '<div class="muted">' . e(admin_log_english_t('admin.logs.grouped_row_note', 'Grouped row; showing one representative entry.')) . '</div>';
        }
        if ($context) {
            echo '<details class="log-context"><summary>' . e(admin_log_english_t('admin.logs.details', 'Details')) . '</summary><pre>' . e(json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre></details>';
        }
        if (!empty($entry['request_id'])) {
            echo '<div class="muted">' . e(admin_log_english_t('admin.logs.request_prefix', 'Request')) . ' ' . e((string) $entry['request_id']) . '</div>';
        }
        echo '</td>';
        echo '<td>' . e((string) ($entry['username'] ?? '')) . '</td>';
        echo '</tr>';
    }
    return (string) ob_get_clean();
}

/**
 * Normalize the filesystem archive browser page number.
 *
 * @param mixed $value Submitted page value.
 * @return int One-based page number.
 */
function admin_log_archive_page_number(mixed $value): int
{
    return max(1, (int) $value);
}

/**
 * Build an Admin Logs URL for one archive browser page while preserving live-log filters.
 *
 * @param int $archivePage Archive browser page number.
 * @return string URL for the caller.
 */
function admin_log_archive_page_url(int $archivePage): string
{
    $params = $_GET;
    unset($params['ajax']);
    $params['archive_page'] = max(1, $archivePage);
    unset($params['page']);
    return url_for('admin_logs', $params);
}

/**
 * Render the compact filesystem archive pagination control.
 *
 * @param int $page Current page.
 * @param int $pages Total pages.
 * @return string HTML fragment.
 */
function render_admin_log_archive_pagination(int $page, int $pages): string
{
    if ($pages <= 1) {
        return '';
    }
    $html = '<nav class="pagination admin-log-archive-pagination" aria-label="Archived log pages">';
    if ($page > 1) {
        $html .= '<a class="pagination-link" href="' . e(admin_log_archive_page_url($page - 1)) . '">Previous</a>';
    }
    $html .= '<span class="pagination-status">Page ' . e((string) $page) . ' of ' . e((string) $pages) . '</span>';
    if ($page < $pages) {
        $html .= '<a class="pagination-link" href="' . e(admin_log_archive_page_url($page + 1)) . '">Next</a>';
    }
    $html .= '</nav>';
    return $html;
}

/**
 * Render filesystem-backed Admin log archive controls and archive files.
 *
 * @param array<string,mixed> $status Archive-maintenance status.
 * @param array<string,mixed> $archiveList Paginated archive listing.
 */
function render_admin_log_archive_panel(array $status, array $archiveList): void
{
    $retentionDays = max(0, (int) ($status['retention_days'] ?? 30));
    $inventory = is_array($status['inventory'] ?? null) ? $status['inventory'] : [];
    $nextRunAt = max(0, (int) ($status['next_run_at'] ?? 0));
    $lastResult = is_array($status['last_result'] ?? null) ? $status['last_result'] : [];
    $archivePage = max(1, (int) ($archiveList['page'] ?? 1));
    $archivePages = max(1, (int) ($archiveList['pages'] ?? 1));
    $items = is_array($archiveList['items'] ?? null) ? $archiveList['items'] : [];

    echo '<section class="panel admin-log-archive-panel">';
    echo '<div class="admin-log-archive-heading"><div><h2>Planned Admin log maintenance</h2><p class="muted">Recent logs stay live in MariaDB. Older completed days are archived as permanent daily ZIP files containing JSON, a fully expanded static HTML report, and a verification manifest. Database rows are deleted only after that ZIP has been verified.</p></div></div>';

    if (empty($status['zip_available'])) {
        echo '<div class="notice">PHP ZipArchive is not available. Automatic Admin log archival cannot run safely until the ZIP extension is enabled.</div>';
    }

    echo '<div class="admin-log-archive-controls">';
    echo '<form method="post" action="' . e(url_for('admin_log_archive_maintenance')) . '" class="admin-log-archive-retention-form">' . csrf_field();
    echo '<input type="hidden" name="action" value="save_retention">';
    echo '<label><span>Keep live logs</span><select name="retention_days">';
    foreach (admin_log_archive_retention_options() as $days) {
        $label = $days === 0 ? 'Forever, disable automatic archiving' : $days . ' days';
        echo '<option value="' . (int) $days . '"' . ($retentionDays === $days ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    echo '</select></label><button type="submit" class="secondary">Save retention</button></form>';

    echo '<form method="post" action="' . e(url_for('admin_log_archive_maintenance')) . '" class="admin-log-archive-run-form">' . csrf_field();
    echo '<input type="hidden" name="action" value="run_now">';
    echo '<button type="submit">Run maintenance cycle now</button>';
    echo '</form>';
    echo '</div>';

    echo '<p class="muted admin-log-archive-policy">The lightweight due counter is checked on normal gallery and Admin page loads. When due, one safe daily archive cycle runs after the visible response. A backlog is retried shortly; once caught up, the next normal check is approximately 24 hours later. Archived ZIP files are never deleted automatically.</p>';

    echo '<dl class="admin-log-archive-metrics">';
    echo '<div><dt>Live retention</dt><dd>' . e($retentionDays === 0 ? 'Forever' : $retentionDays . ' days') . '</dd></div>';
    echo '<div><dt>Archived ZIPs</dt><dd>' . e((string) max(0, (int) ($inventory['count'] ?? 0))) . '</dd></div>';
    echo '<div><dt>Archive storage</dt><dd>' . e(admin_dashboard_format_bytes(max(0, (int) ($inventory['total_bytes'] ?? 0)))) . '</dd></div>';
    echo '<div><dt>Oldest archive</dt><dd>' . e((string) (($inventory['oldest_date'] ?? '') !== '' ? $inventory['oldest_date'] : 'none')) . '</dd></div>';
    echo '<div><dt>Newest archive</dt><dd>' . e((string) (($inventory['newest_date'] ?? '') !== '' ? $inventory['newest_date'] : 'none')) . '</dd></div>';
    $nextRunLabel = $retentionDays === 0
        ? 'disabled'
        : ($nextRunAt <= time() ? 'due now' : date('Y-m-d H:i:s', $nextRunAt));
    echo '<div><dt>Next automatic check</dt><dd>' . e($nextRunLabel) . '</dd></div>';
    echo '</dl>';

    if ($lastResult !== []) {
        $lastReason = (string) ($lastResult['reason'] ?? '');
        $lastDate = (string) ($lastResult['archive_date'] ?? '');
        $lastRows = max(0, (int) ($lastResult['archived_rows'] ?? 0));
        $lastDeleted = max(0, (int) ($lastResult['deleted_rows'] ?? 0));
        $lastSummary = 'Last cycle: ' . ($lastReason !== '' ? $lastReason : (!empty($lastResult['ok']) ? 'completed' : 'failed')) . '.';
        if ($lastDate !== '') {
            $lastSummary .= ' Archive day ' . $lastDate . ', ' . $lastRows . ' rows preserved, ' . $lastDeleted . ' live rows removed.';
        }
        if (!empty($lastResult['error'])) {
            $lastSummary .= ' Error: ' . (string) $lastResult['error'];
        }
        echo '<p class="muted admin-log-archive-last-result">' . e($lastSummary) . '</p>';
    }

    echo '<div class="admin-log-archive-heading admin-log-archive-files-heading"><div><h3>Archived logs</h3><p class="muted">These rows come directly from ZIP files on disk. View the frozen HTML/JSON through authenticated routes, download the original ZIP, or delete a selected archive manually.</p></div></div>';
    echo render_admin_log_archive_pagination($archivePage, $archivePages);
    if ($items === []) {
        echo '<p class="muted">No Admin log ZIP archives exist yet.</p>';
    } else {
        echo '<div class="admin-log-table-wrap"><table class="admin-log-archive-table"><thead><tr><th>Date</th><th>Records</th><th>ZIP size</th><th>Created</th><th>Actions</th></tr></thead><tbody>';
        foreach ($items as $item) {
            $date = (string) ($item['date'] ?? '');
            if (!admin_log_archive_valid_date($date)) {
                continue;
            }
            echo '<tr><td><strong>' . e($date) . '</strong><div class="muted">' . e((string) ($item['file_name'] ?? admin_log_archive_file_name($date))) . '</div></td>';
            echo '<td>' . e(!empty($item['manifest_available']) ? (string) max(0, (int) ($item['row_count'] ?? 0)) : 'manifest unavailable') . '</td>';
            echo '<td>' . e(admin_dashboard_format_bytes(max(0, (int) ($item['bytes'] ?? 0)))) . '</td>';
            echo '<td>' . e((string) (($item['created_at'] ?? '') !== '' ? $item['created_at'] : 'unknown')) . '</td>';
            echo '<td><div class="admin-log-archive-actions">';
            echo '<a class="button secondary" target="_blank" rel="noopener" href="' . e(url_for('admin_log_archive_view', ['date' => $date, 'kind' => 'html'])) . '">View HTML</a>';
            echo '<a class="button secondary" target="_blank" rel="noopener" href="' . e(url_for('admin_log_archive_view', ['date' => $date, 'kind' => 'json'])) . '">View JSON</a>';
            echo '<a class="button secondary" href="' . e(url_for('admin_log_archive_download', ['date' => $date])) . '">Download ZIP</a>';
            echo '<form method="post" action="' . e(url_for('admin_log_archive_maintenance')) . '" class="admin-log-archive-delete-form">' . csrf_field();
            echo '<input type="hidden" name="action" value="delete_archive"><input type="hidden" name="date" value="' . e($date) . '">';
            echo '<button type="submit" class="secondary danger" onclick="return confirm(' . e(json_encode('Permanently delete ' . admin_log_archive_file_name($date) . '? This archived log data cannot be recovered from PHP Gallery.', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . ');">Delete</button>';
            echo '</form></div></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo render_admin_log_archive_pagination($archivePage, $archivePages);
    echo '</section>';
}


/**
 * Normalize the selected Admin Logs subsection.
 *
 * @param mixed $value Submitted subsection value.
 * @return string Stable subsection identifier.
 */
function admin_log_section(mixed $value): string
{
    return strtolower(trim((string) $value)) === 'maintenance' ? 'maintenance' : 'logs';
}

/**
 * Render server-backed Admin Logs subtabs.
 *
 * These deliberately navigate instead of only hiding DOM panels so the inactive
 * subsection does not perform its database or filesystem work in the background.
 *
 * @param string $activeSection Current normalized subsection.
 */
function render_admin_log_section_tabs(string $activeSection): void
{
    // $preservedParams keeps live-log filter state when temporarily opening maintenance.
    $preservedParams = $_GET;
    unset($preservedParams['page'], $preservedParams['section'], $preservedParams['ajax'], $preservedParams['archive_page']);

    $logsUrl = url_for('admin_logs', $preservedParams);
    $maintenanceUrl = url_for('admin_logs', array_merge($preservedParams, ['section' => 'maintenance']));

    echo '<nav class="admin-subtabs admin-log-section-tabs" aria-label="Admin log sections">';
    echo '<div class="admin-subtab-list">';
    echo '<a class="admin-subtab' . ($activeSection === 'logs' ? ' is-active' : '') . '" href="' . e($logsUrl) . '"' . ($activeSection === 'logs' ? ' aria-current="page"' : '') . '>Logs</a>';
    echo '<a class="admin-subtab' . ($activeSection === 'maintenance' ? ' is-active' : '') . '" href="' . e($maintenanceUrl) . '"' . ($activeSection === 'maintenance' ? ' aria-current="page"' : '') . '>Maintenance &amp; archives</a>';
    echo '</div></nav>';
}

/**
 * Render the shared Admin Logs page heading and subsection navigation.
 *
 * @param string $activeSection Current normalized subsection.
 */
function render_admin_log_page_heading(string $activeSection): void
{
    echo '<section class="hero"><h1>' . e(admin_log_english_t('admin.logs.title', 'Admin log')) . '</h1><p>' . e(admin_log_english_t('admin.logs.intro', 'Operational events, failures, and maintenance actions.')) . '</p><nav class="nav">';
    echo '<a class="button secondary" href="' . e(url_for('admin')) . '">' . e(admin_log_english_t('admin.logs.back_to_dashboard', 'Back to dashboard')) . '</a>';
    echo '<a class="button secondary" href="' . e(url_for('admin_telemetry')) . '">' . e(admin_log_english_t('admin.logs.anonymous_telemetry', 'Anonymous telemetry')) . '</a>';
    echo '</nav></section>';
    render_admin_log_section_tabs($activeSection);
}

/**
 * Handles cms admin logs logic for the gallery application.
 */
function cms_admin_logs(): void
{
    require_admin();
    // $section stores the server-backed Admin Logs subsection selected by the user.
    $section = admin_log_section($_GET['section'] ?? 'logs');
    // $notice stores the result of retention, manual maintenance, and archive file actions.
    $notice = (string) flash_message('admin_notice');

    if ($section === 'maintenance') {
        // $archivePage stores the filesystem archive browser page independently of live-log pagination.
        $archivePage = admin_log_archive_page_number($_GET['archive_page'] ?? 1);
        // $archiveStatus stores the lightweight counter state and filesystem inventory.
        $archiveStatus = admin_log_archive_status();
        // $archiveList opens manifests only for the currently visible archive page.
        $archiveList = admin_log_archive_list($archivePage);

        render_header(admin_log_english_t('admin.logs.title', 'Admin log'));
        render_admin_log_page_heading($section);
        if ($notice !== '') {
            echo '<div class="notice">' . e($notice) . '</div>';
        }
        render_admin_log_archive_panel($archiveStatus, $archiveList);
        render_footer();
        return;
    }

    // $status remains available to the service layer but is intentionally hidden from the admin log UI.
    $status = null;
    // $category stores the operational category filter.
    $category = isset($_GET['category']) ? (string) $_GET['category'] : '';
    // $selectedSeverities stores the persistent multi-select severity filter.
    $selectedSeverities = admin_log_resolve_selected_severities();
    // $query stores the text search filter.
    $query = trim((string) ($_GET['q'] ?? ''));
    // $timeSort stores the selected chronological order.
    $timeSort = admin_log_normalize_time_sort((string) ($_GET['time_sort'] ?? 'desc'));
    // $pageSize stores the requested number of visible rows per page.
    $pageSize = admin_log_normalize_page_size($_GET['per_page'] ?? 150);
    // $grouped stores whether repeated log events are collapsed before pagination.
    $grouped = admin_log_grouping_enabled($_GET['grouped'] ?? '1');
    // $currentPage stores the requested admin log pagination page.
    $currentPage = admin_log_normalize_page_number($_GET['log_page'] ?? 1);
    // $filters stores active filters shared by the count and list queries.
    $filters = [
        'category' => $category,
        'severities' => $selectedSeverities,
        'q' => $query,
        'time_sort' => $timeSort,
    ];
    // $totalRows stores the filtered row count after optional grouping.
    $totalRows = $grouped ? admin_log_grouped_count($status, $filters) : admin_log_count($status, $filters);
    // $totalPages stores the bounded number of available pages.
    $totalPages = max(1, (int) ceil($totalRows / $pageSize));
    if ($currentPage > $totalPages) {
        $currentPage = $totalPages;
    }
    // $offset stores the SQL offset for the current page.
    $offset = ($currentPage - 1) * $pageSize;
    // $logs stores the filtered admin log entries.
    $logs = $grouped
        ? admin_log_grouped_list($status, $pageSize, $filters, $offset)
        : admin_log_list($status, $pageSize, $filters, $offset);
    // $countText stores the compact result count shown in the page heading.
    $countText = admin_log_result_count_text(count($logs), $totalRows, $grouped);
    // $paginationHtml stores the current pagination controls for normal and live responses.
    $paginationHtml = render_admin_log_pagination($currentPage, $totalPages, $totalRows, $pageSize);

    if ((string) ($_GET['ajax'] ?? '') === '1') {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'rows_html' => render_admin_log_table_rows($logs),
            'count' => count($logs),
            'total' => $totalRows,
            'count_text' => $countText,
            'pagination_html' => $paginationHtml,
            'log_page' => $currentPage,
            'per_page' => $pageSize,
            'grouped' => $grouped ? 1 : 0,
            'time_sort' => $timeSort,
            'empty_html' => '<p>' . e(admin_log_english_t('admin.logs.no_entries_match', 'No log entries match the current filters.')) . '</p>',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    render_header(admin_log_english_t('admin.logs.title', 'Admin log'));
    render_admin_log_page_heading($section);
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }

    echo '<section class="panel admin-log-filters-panel"><div class="admin-log-filters-header"><div><h2>' . e(admin_log_english_t('admin.logs.filters', 'Filters')) . '</h2><p class="muted">' . e(admin_log_english_t('admin.logs.filters_intro', 'Refine the operational log by category, severity, grouping, and row count.')) . '</p></div><div class="admin-log-filters-header-actions"><a class="button secondary" href="' . e(url_for('admin_logs_export_zip')) . '">' . e(admin_log_english_t('admin.logs.export_all_zip', 'Export all logs ZIP')) . '</a><span class="admin-log-filter-state" data-admin-log-live-state aria-live="polite"></span></div></div><form method="get" action="' . e(base_url('index.php')) . '" class="admin-log-filter-grid" data-admin-log-filter-form data-admin-log-live-url="' . e(url_for('admin_logs')) . '" data-admin-log-searching-text="' . e(admin_log_english_t('admin.logs.searching', 'Searching...')) . '" data-admin-log-updated-text="' . e(admin_log_english_t('admin.logs.updated', 'Updated.')) . '" data-admin-log-failed-text="' . e(admin_log_english_t('admin.logs.live_search_failed', 'Live search failed. Use Apply filters.')) . '" data-admin-log-shown-text="' . e(admin_log_english_t('admin.logs.shown_suffix', 'shown')) . '" data-admin-log-when-text="' . e(admin_log_english_t('admin.logs.when', 'When')) . '">';
    echo '<input type="hidden" name="page" value="admin_logs">';
    echo '<input type="hidden" name="log_page" value="' . (int) $currentPage . '" data-admin-log-page-input>';
    echo '<div class="admin-log-filter-main">';
    echo '<fieldset class="admin-log-filter-group"><legend>' . e(admin_log_english_t('admin.logs.filter_scope', 'Log scope')) . '</legend><div class="admin-log-control-grid">';
    echo '<label class="admin-log-filter-control"><span>' . e(admin_log_english_t('admin.logs.category', 'Category')) . '</span><select name="category" data-admin-log-live-filter><option value="">' . e(admin_log_english_t('admin.logs.all_categories', 'All categories')) . '</option>';
    foreach (admin_log_category_options() as $value => $label) {
        echo '<option value="' . e($value) . '"' . ($category === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label class="admin-log-filter-control"><span>' . e(admin_log_english_t('admin.logs.time_order', 'Time order')) . '</span><select name="time_sort" data-admin-log-live-filter><option value="desc"' . ($timeSort === 'desc' ? ' selected' : '') . '>' . e(admin_log_english_t('admin.logs.newest_first', 'Newest first')) . '</option><option value="asc"' . ($timeSort === 'asc' ? ' selected' : '') . '>' . e(admin_log_english_t('admin.logs.oldest_first', 'Oldest first')) . '</option></select></label>';
    echo '<label class="admin-log-filter-control"><span>' . e(admin_log_english_t('admin.logs.grouping', 'Grouping')) . '</span><select name="grouped" data-admin-log-live-filter><option value="1"' . ($grouped ? ' selected' : '') . '>' . e(admin_log_english_t('admin.logs.group_similar', 'Group similar events')) . '</option><option value="0"' . (!$grouped ? ' selected' : '') . '>' . e(admin_log_english_t('admin.logs.show_individual', 'Show individual rows')) . '</option></select></label>';
    echo '<label class="admin-log-filter-control"><span>' . e(admin_log_english_t('admin.logs.per_page', 'Rows per page')) . '</span><select name="per_page" data-admin-log-live-filter>';
    foreach (admin_log_page_size_options() as $option) {
        echo '<option value="' . (int) $option . '"' . ($pageSize === $option ? ' selected' : '') . '>' . (int) $option . '</option>';
    }
    echo '</select></label>';
    echo '</div></fieldset>';
    echo '<fieldset class="admin-log-severity-filter admin-log-filter-group" data-admin-log-severity-filter data-all-text="' . e(admin_log_english_t('admin.logs.severity_filter_all_summary', 'All severities are shown.')) . '" data-active-template="' . e(admin_log_english_t('admin.logs.severity_filter_active_summary', 'Active severities: {values}')) . '"><legend><span>' . e(admin_log_english_t('admin.logs.severity', 'Severity')) . '</span><span class="admin-log-severity-count">' . e((string) count($selectedSeverities)) . '</span></legend>';
    echo '<input type="hidden" name="severity_filter_submitted" value="1">';
    echo '<p class="admin-log-filter-help">' . e(admin_log_english_t('admin.logs.severity_filter_hint', 'Pick one or more severities. Empty means all.')) . '</p>';
    echo '<div class="admin-log-severity-options">';
    foreach (admin_log_severity_options() as $value => $label) {
        echo '<label class="admin-log-severity-choice is-' . e($value) . '"><input class="admin-log-severity-checkbox" type="checkbox" name="severities[]" value="' . e($value) . '"' . (in_array($value, $selectedSeverities, true) ? ' checked' : '') . ' data-admin-log-live-filter> <span>' . e($label) . '</span></label>';
    }
    echo '</div><p class="admin-log-severity-summary" data-admin-log-severity-summary>' . e(admin_log_severity_filter_summary($selectedSeverities)) . '</p></fieldset>';
    echo '</div>';
    echo '<div class="admin-log-filter-footer">';
    echo '<label class="admin-log-filter-control admin-log-search-control"><span>' . e(admin_log_english_t('admin.logs.search', 'Search')) . '</span><input name="q" value="' . e($query) . '" placeholder="' . e(admin_log_english_t('admin.logs.search_placeholder', 'Event key, message, context, request, or route')) . '" autocomplete="off" data-admin-log-live-search></label>';
    echo '<div class="admin-log-filter-actions"><button type="submit">' . e(admin_log_english_t('admin.logs.apply_filters', 'Apply filters')) . '</button><a class="button secondary" href="' . e(url_for('admin_logs', ['reset_severity' => '1'])) . '">' . e(admin_log_english_t('admin.logs.reset_severity_filter', 'Reset severity filter')) . '</a></div>';
    echo '</div>';
    echo '</form></section>';

    echo '<section class="panel" data-admin-log-results><h2>' . e(admin_log_english_t('admin.logs.entries', 'Entries')) . ' <span class="muted" data-admin-log-count>(' . e($countText) . ')</span></h2>';
    echo $paginationHtml;
    if (!$logs) {
        echo '<div data-admin-log-empty><p>' . e(admin_log_english_t('admin.logs.no_entries_match', 'No log entries match the current filters.')) . '</p></div>';
    }
    echo '<div class="admin-log-table-wrap">';
    echo '<table class="admin-log-table"><thead><tr><th>' . e(admin_log_english_t('admin.logs.select', 'Select')) . '</th><th><a href="' . e(admin_log_filter_url(['time_sort' => $timeSort === 'desc' ? 'asc' : 'desc', 'log_page' => 1])) . '" data-admin-log-time-sort-link data-next-sort="' . e($timeSort === 'desc' ? 'asc' : 'desc') . '">' . e(admin_log_english_t('admin.logs.when', 'When')) . ' ' . e($timeSort === 'desc' ? '↓' : '↑') . '</a></th><th>' . e(admin_log_english_t('admin.logs.instances', 'Instances')) . '</th><th>' . e(admin_log_english_t('admin.logs.severity', 'Severity')) . '</th><th>' . e(admin_log_english_t('admin.logs.category', 'Category')) . '</th><th>' . e(admin_log_english_t('admin.logs.event', 'Event')) . '</th><th>' . e(admin_log_english_t('admin.logs.message', 'Message')) . '</th><th>' . e(admin_log_english_t('admin.logs.by', 'By')) . '</th></tr></thead><tbody data-admin-log-tbody>';
    echo render_admin_log_table_rows($logs);
    echo '</tbody></table></div><form id="admin-log-bulk-form" method="post" action="' . e(url_for('admin_log_update')) . '">' . csrf_field();
    echo '<div class="bulk-row"><label>' . e(admin_log_english_t('admin.logs.bulk_set_selected', 'Bulk set selected')) . '<select name="status">';
    foreach (admin_log_status_options() as $value => $label) {
        echo '<option value="' . e($value) . '">' . e($label) . '</option>';
    }
    echo '</select></label><button type="submit" name="action" value="bulk">' . e(admin_log_english_t('admin.logs.apply_to_selected', 'Apply to selected')) . '</button><span class="muted">' . e(admin_log_english_t('admin.logs.bulk_grouping_hint', 'Selecting a grouped row applies the state to every matching instance in that group.')) . '</span></div></form></section>';
    render_footer();
}

/**
 * Return one bounded lazy-loaded page of raw instances for a grouped Admin log row.
 */
function cms_admin_log_group_members(): void
{
    require_admin();
    // $logId stores the representative grouped row id supplied by the server-rendered table.
    $logId = max(0, (int) ($_GET['id'] ?? 0));
    // $groupHash stores the deterministic group identity supplied by the server-rendered table.
    $groupHash = strtolower(trim((string) ($_GET['group'] ?? '')));
    // $offset stores the next bounded raw-instance position requested by the browser.
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    $entry = $logId > 0 ? admin_log_find($logId) : null;
    if ($entry === null || preg_match('/^[a-f0-9]{64}$/', $groupHash) !== 1 || !hash_equals(admin_log_group_hash_for_entry($entry), $groupHash)) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => admin_log_english_t('admin.logs.group_not_found', 'Grouped log entry not found.')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    // Fetch one extra row so the response can expose has_more without a separate COUNT query.
    $page = admin_log_group_member_page($entry, ADMIN_LOG_GROUP_MEMBER_PAGE_SIZE + 1, $offset);
    $hasMore = count($page) > ADMIN_LOG_GROUP_MEMBER_PAGE_SIZE;
    if ($hasMore) {
        $page = array_slice($page, 0, ADMIN_LOG_GROUP_MEMBER_PAGE_SIZE);
    }
    $nextOffset = $offset + count($page);

    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'ok' => true,
        'html' => render_admin_log_group_member_rows($page, $offset),
        'loaded' => count($page),
        'next_offset' => $nextOffset,
        'has_more' => $hasMore,
        'state_text' => admin_log_english_t('admin.logs.instances_loaded', '{count} raw instances loaded.', ['count' => (string) $nextOffset]),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Save Admin log retention, force one archive cycle, or explicitly delete an archive file.
 */
function cms_admin_log_archive_maintenance(): void
{
    require_admin();
    verify_csrf();
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'save_retention') {
        $days = admin_log_archive_set_retention_days((int) ($_POST['retention_days'] ?? 30));
        $label = $days === 0 ? 'Forever' : $days . ' days';
        flash_message('admin_notice', 'Admin log live retention saved: ' . $label . '. Archived ZIP files are never deleted automatically.');
        redirect_to(url_for('admin_logs', ['section' => 'maintenance']));
    }

    if ($action === 'run_now') {
        $result = admin_log_archive_maintenance_run([
            'source' => 'admin_manual',
            'force' => true,
        ]);
        if (!empty($result['busy'])) {
            flash_message('admin_notice', 'Admin log archive maintenance is already running in another request.');
        } elseif (empty($result['ok'])) {
            flash_message('admin_notice', 'Admin log archive maintenance failed: ' . (string) ($result['error'] ?? $result['reason'] ?? 'unknown error'));
        } elseif ((string) ($result['reason'] ?? '') === 'retention_forever') {
            flash_message('admin_notice', 'Admin log archive maintenance is disabled because live retention is set to Forever.');
        } elseif (!empty($result['archive_date'])) {
            $message = 'Admin log maintenance archived ' . (string) $result['archive_date']
                . ': ' . max(0, (int) ($result['archived_rows'] ?? 0)) . ' rows preserved in ZIP, '
                . max(0, (int) ($result['deleted_rows'] ?? 0)) . ' represented live rows removed.';
            if (!empty($result['has_more'])) {
                $message .= ' More eligible days remain and will continue in later safe cycles.';
            } else {
                $message .= ' The archive backlog is caught up.';
            }
            flash_message('admin_notice', $message);
        } else {
            flash_message('admin_notice', 'Admin log maintenance found no completed days old enough to archive.');
        }
        redirect_to(url_for('admin_logs', ['section' => 'maintenance']));
    }

    if ($action === 'delete_archive') {
        $date = trim((string) ($_POST['date'] ?? ''));
        if (!admin_log_archive_valid_date($date)) {
            flash_message('admin_notice', 'Invalid Admin log archive date.');
            redirect_to(url_for('admin_logs', ['section' => 'maintenance']));
        }
        try {
            $deleted = admin_log_archive_delete_file($date);
            if ($deleted) {
                admin_log_event('warning', 'admin_log.archive_deleted', 'An Admin log archive ZIP was deleted manually.', [
                    'archive_date' => $date,
                    'file_name' => admin_log_archive_file_name($date),
                ], [
                    'severity' => 'warning',
                    'category' => 'admin',
                ]);
                flash_message('admin_notice', 'Deleted archived Admin log file ' . admin_log_archive_file_name($date) . '.');
            } else {
                flash_message('admin_notice', 'The selected Admin log archive file no longer exists.');
            }
        } catch (Throwable $exception) {
            flash_message('admin_notice', 'Unable to delete the selected Admin log archive: ' . $exception->getMessage());
        }
        redirect_to(url_for('admin_logs', ['section' => 'maintenance']));
    }

    cms_not_found();
}

/**
 * Stream the static HTML or canonical JSON member of one archived Admin log day.
 */
function cms_admin_log_archive_view(): void
{
    require_admin();
    $date = trim((string) ($_GET['date'] ?? ''));
    $kind = strtolower(trim((string) ($_GET['kind'] ?? 'html')));
    if (!admin_log_archive_valid_date($date) || !in_array($kind, ['html', 'json'], true)) {
        cms_not_found();
        return;
    }
    $path = admin_log_archive_path($date);
    if (!is_file($path)) {
        cms_not_found();
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . ($kind === 'json' ? 'application/json' : 'text/html') . '; charset=utf-8');
    header('Content-Disposition: inline; filename="admin-logs-' . $date . '.' . $kind . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    if ($kind === 'html') {
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
    }
    try {
        admin_log_archive_stream_member($date, $kind);
    } catch (Throwable) {
        // The file existed when headers were prepared but could have become unavailable concurrently.
    }
}

/**
 * Download one immutable daily Admin log ZIP archive.
 */
function cms_admin_log_archive_download(): void
{
    require_admin();
    $date = trim((string) ($_GET['date'] ?? ''));
    if (!admin_log_archive_valid_date($date)) {
        cms_not_found();
        return;
    }
    $path = admin_log_archive_path($date);
    if (!is_file($path)) {
        cms_not_found();
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . admin_log_archive_file_name($date) . '"');
    header('Content-Length: ' . max(0, (int) filesize($path)));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    admin_log_archive_stream_zip($date);
}

/**
 * Handles cms admin log update logic for the gallery application.
 */
function cms_admin_log_update(): void
{
    require_admin();
    verify_csrf();
    // Variable $wantsJson stores this steps working value.
    $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    // Variable $action stores this steps working value.
    $action = (string) ($_POST['action'] ?? '');
    // Variable $status stores this steps working value.
    $status = (string) ($_POST['status'] ?? '');
    if ($action === 'single') {
        // Variable $logId stores this steps working value.
        $logId = (int) ($_POST['log_id'] ?? 0);
        try {
            admin_log_update_status($logId, $status);
            if ($wantsJson) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'status' => $status, 'label' => admin_log_status_label($status)]);
                return;
            }
        } catch (RuntimeException $exception) {
            admin_log_event('error', 'admin_log.update_failed', 'Admin log status update failed.', [
                'log_id' => $logId,
                'status' => $status,
                'error' => $exception->getMessage(),
            ]);
            if ($wantsJson) {
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                http_response_code(422);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => $exception->getMessage()]);
                return;
            }
        }
        redirect_to(url_for('admin_logs'));
    }
    if ($action === 'bulk' && !empty($_POST['log_ids']) && is_array($_POST['log_ids'])) {
        foreach ($_POST['log_ids'] as $selectedValue) {
            try {
                $selectedValue = trim((string) $selectedValue);
                if (str_starts_with($selectedValue, 'group:')) {
                    admin_log_update_group_status(substr($selectedValue, 6), $status);
                    continue;
                }
                admin_log_update_status((int) $selectedValue, $status);
            } catch (RuntimeException $exception) {
                admin_log_event('error', 'admin_log.bulk_update_failed', 'Bulk admin log status update failed.', [
                    'log_id' => $selectedValue,
                    'status' => $status,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
        redirect_to(url_for('admin_logs'));
    }
    cms_not_found();
    return;
}


/**
 * Export the full details of one admin log event as a plain text diagnostic file.
 */
function cms_admin_log_export(): void
{
    require_admin();
    // $logId stores the requested admin log identifier from the query string.
    $logId = max(0, (int) ($_GET['id'] ?? 0));
    // $groupHash stores the optional grouped admin log hash from the query string.
    $groupHash = strtolower(trim((string) ($_GET['group'] ?? '')));
    if ($logId <= 0) {
        cms_not_found();
        return;
    }
    // $entry stores the exported admin log entry.
    $entry = admin_log_find($logId);
    if ($entry === null) {
        cms_not_found();
        return;
    }

    // $isGroupedExport is true only when the supplied group hash matches the representative row.
    $isGroupedExport = false;
    if ($groupHash !== '') {
        if (preg_match('/^[a-f0-9]{64}$/', $groupHash) !== 1 || !hash_equals(admin_log_group_hash_for_entry($entry), $groupHash)) {
            cms_not_found();
            return;
        }
        $isGroupedExport = true;
    }

    // $fileName stores a filesystem-safe diagnostic export name.
    $fileName = 'php-gallery-log-' . $logId . '-' . preg_replace('/[^0-9A-Za-z_-]/', '-', (string) ($entry['event_key'] ?? 'event')) . '.txt';
    if ($isGroupedExport) {
        $fileName = 'php-gallery-log-group-' . substr($groupHash, 0, 12) . '-' . preg_replace('/[^0-9A-Za-z_-]/', '-', (string) ($entry['event_key'] ?? 'event')) . '.txt';
    }

    // Remove all application output buffers so a very large grouped export can stream directly.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    if (!$isGroupedExport) {
        echo admin_log_export_text($entry);
        return;
    }

    // $summary stores scalar group metadata without materializing raw LONGTEXT contexts.
    $summary = admin_log_group_member_summary($entry);
    $groupCount = max(0, (int) ($summary['group_count'] ?? 0));
    $entry['first_created_at'] = (string) ($summary['first_created_at'] ?? '');
    $entry['latest_created_at'] = (string) ($summary['latest_created_at'] ?? '');
    echo admin_log_export_group_header_text($entry, $groupCount);

    $beforeCreatedAt = null;
    $beforeId = 0;
    $written = 0;
    $batchSize = 250;
    while (true) {
        // $members stores one indexed keyset page, keeping memory bounded regardless of group size.
        $members = admin_log_group_member_export_batch($entry, $beforeCreatedAt, $beforeId, $batchSize);
        if ($members === []) {
            break;
        }
        foreach ($members as $member) {
            $written++;
            echo '[' . $written . '/' . $groupCount . "]\n";
            echo admin_log_export_text($member) . "\n";
        }
        $lastMember = end($members);
        $beforeCreatedAt = is_array($lastMember) ? (string) ($lastMember['created_at'] ?? '') : '';
        $beforeId = is_array($lastMember) ? max(0, (int) ($lastMember['id'] ?? 0)) : 0;
        if (count($members) < $batchSize || $beforeCreatedAt === '' || $beforeId <= 0 || connection_aborted()) {
            break;
        }
        if (function_exists('flush')) {
            flush();
        }
    }
}


/**
 * Export all admin logs as a ZIP containing matching CSV and JSON data files.
 */
function cms_admin_logs_export_zip(): void
{
    require_admin();
    $filePath = '';
    try {
        // $filePath stores only the finished ZIP; CSV and JSON rows are streamed through temporary files.
        $filePath = admin_log_export_temp_path();
        admin_log_create_export_zip_streamed($filePath);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        admin_log_send_export_zip($filePath, admin_log_export_zip_filename());
    } catch (Throwable $exception) {
        if ($filePath !== '' && is_file($filePath)) {
            @unlink($filePath);
        }
        admin_log_event('error', 'admin_log.export_zip_failed', 'Admin log ZIP export failed.', [
            'error' => $exception->getMessage(),
        ], [
            'severity' => 'error',
            'category' => 'admin',
        ]);
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo admin_log_english_t('admin.logs.export_failed_plain', 'Unable to export admin logs.');
    }
}
