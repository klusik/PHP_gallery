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
use function Gallery\Services\feature_capability_effective_enabled;
use function Gallery\Services\set_app_setting;
use function Gallery\Services\t;
use function Gallery\Services\translation_interpolate;
use function Gallery\Services\translation_load_language;
use function Gallery\Views\view_render_admin_feature_flag;
use function Gallery\Views\view_render_admin_log_empty;
use function Gallery\Views\view_render_admin_log_page_heading;
use function Gallery\Views\view_render_admin_log_section_tabs;
use function Gallery\Views\view_render_admin_logs_live;
use function Gallery\Views\view_render_admin_log_group_member_rows;
use function Gallery\Views\view_render_admin_log_table_rows;
use function Gallery\Views\view_render_admin_log_legacy_row;
use function Gallery\Views\view_render_admin_log_pagination;
use function Gallery\Views\view_render_admin_log_archive_pagination;
use function Gallery\Views\view_render_admin_log_archive_panel;
use function Gallery\Services\admin_log_archive_delete_file;
use function Gallery\Services\admin_log_archive_file_name;
use function Gallery\Services\admin_log_archive_list;
use function Gallery\Services\admin_log_archive_maintenance_run;
use function Gallery\Services\admin_log_archive_path;
use function Gallery\Services\admin_log_archive_retention_options;
use function Gallery\Services\admin_log_archive_set_retention_days;
use function Gallery\Services\admin_log_archive_status;
use function Gallery\Services\admin_log_archive_member_chunks;
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
use function Gallery\Services\admin_log_export_zip_descriptor;
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
 * Translate Admin log UI labels using the active language.
 *
 * The explicit English dictionary fallback keeps the logs page alive if only
 * this controller is updated during a partial deployment where the translation
 * service or active language pack is incomplete.
 */
if (!function_exists('admin_log_english_t')) {
    /**
     * Handle admin log translation with a safe partial-deployment fallback.
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

        if (function_exists('Gallery\\Services\\t')) {
            return t($key, $fallback, $parameters);
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
    $contextJson = '';
    if (!empty($entry['context_json'])) {
        $decoded = json_decode((string) $entry['context_json'], true);
        if (is_array($decoded) && $decoded !== []) {
            $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $contextJson = $encoded === false ? '' : $encoded;
        }
    }
    return view_render_admin_log_legacy_row($entry, $contextJson);
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
    return view_render_admin_feature_flag($enabled, e($symbol), $label);
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
    $page = max(1, $page);
    $totalPages = max(1, $totalPages);
    $previousPage = max(1, $page - 1);
    $nextPage = min($totalPages, $page + 1);
    $pages = [1, $page - 1, $page, $page + 1, $totalPages];
    $pages = array_values(array_unique(array_filter($pages, static fn (int $candidate): bool => $candidate >= 1 && $candidate <= $totalPages)));
    sort($pages);
    $links = [];
    $lastPage = 0;
    foreach ($pages as $pageNumber) {
        $links[] = [
            'page' => $pageNumber,
            'current' => $pageNumber === $page,
            'gap_before' => $lastPage > 0 && $pageNumber > $lastPage + 1,
            'url' => $pageNumber === $page ? '' : admin_log_filter_url(['log_page' => $pageNumber]),
        ];
        $lastPage = $pageNumber;
    }
    return view_render_admin_log_pagination([
        'page' => $page,
        'total_pages' => $totalPages,
        'range_label' => admin_log_page_range_text($page, $perPage, $total),
        'aria_label' => admin_log_english_t('pagination.label', 'Pagination'),
        'previous_url' => admin_log_filter_url(['log_page' => $previousPage]),
        'previous_label' => admin_log_english_t('pagination.previous', 'Previous'),
        'next_url' => admin_log_filter_url(['log_page' => $nextPage]),
        'next_label' => admin_log_english_t('pagination.next', 'Next'),
        'status_label' => admin_log_english_t('pagination.status', 'Page {current} of {total}', ['current' => $page, 'total' => $totalPages]),
        'links' => $links,
    ]);
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
 * Prepare one bounded chunk of raw grouped Admin log instances for the view.
 *
 * @param array $members Group member rows.
 * @param int $startIndex Zero-based index of the first rendered member.
 * @return array<int, array<string, mixed>> Presentation-only member rows.
 */
function admin_log_group_member_view_models(array $members, int $startIndex = 0): array
{
    $viewModels = [];
    foreach ($members as $index => $member) {
        if (!is_array($member)) {
            continue;
        }
        $context = admin_log_context_array($member);
        $member['display_index'] = max(0, $startIndex) + $index + 1;
        $member['context_compact_json'] = $context !== []
            ? (string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '';
        $viewModels[] = $member;
    }
    return $viewModels;
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
    return view_render_admin_log_group_member_rows(
        admin_log_group_member_view_models($members, $startIndex),
        admin_log_english_t('admin.logs.request_prefix', 'Request')
    );
}

/**
 * Return labels shared by Admin log table row rendering.
 *
 * @return array<string, string> Localized presentation labels.
 */
function admin_log_table_row_labels(): array
{
    return [
        'group_count_one' => admin_log_english_t('admin.logs.group_count_one', 'entry'),
        'group_count_many' => admin_log_english_t('admin.logs.group_count_many', 'entries'),
        'save_details_txt' => admin_log_english_t('admin.logs.save_details_txt', 'Save details as TXT'),
        'log_id' => admin_log_english_t('admin.logs.log_id', 'Log ID'),
        'group_count' => admin_log_english_t('admin.logs.group_count', 'Grouped entries'),
        'first_seen' => admin_log_english_t('admin.logs.first_seen', 'First seen'),
        'latest_seen' => admin_log_english_t('admin.logs.latest_seen', 'Latest seen'),
        'created_at' => admin_log_english_t('admin.logs.created_at', 'Created at'),
        'level' => admin_log_english_t('admin.logs.level', 'Level'),
        'severity' => admin_log_english_t('admin.logs.severity', 'Severity'),
        'category' => admin_log_english_t('admin.logs.category', 'Category'),
        'route' => admin_log_english_t('admin.logs.route', 'Route'),
        'request_id' => admin_log_english_t('admin.logs.request_id', 'Request ID'),
        'all_instances' => admin_log_english_t('admin.logs.all_instances', 'All grouped instances'),
        'instances_lazy_hint' => admin_log_english_t('admin.logs.instances_lazy_hint', 'Open this section to load raw instances in small batches.'),
        'load_more_instances' => admin_log_english_t('admin.logs.load_more_instances', 'Load more instances'),
        'grouped_row_note' => admin_log_english_t('admin.logs.grouped_row_note', 'Grouped row; showing one representative entry.'),
        'details' => admin_log_english_t('admin.logs.details', 'Details'),
        'request_prefix' => admin_log_english_t('admin.logs.request_prefix', 'Request'),
    ];
}

/**
 * Prepare Admin log entries for presentation without leaking service calls into the view.
 *
 * @param array $logs Raw or grouped Admin log rows.
 * @return array<int, array<string, mixed>> Presentation-only row models.
 */
function admin_log_table_row_view_models(array $logs): array
{
    $viewModels = [];
    foreach ($logs as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $context = [];
        if (!empty($entry['context_json'])) {
            $decoded = json_decode((string) $entry['context_json'], true);
            $context = is_array($decoded) ? $decoded : [];
        }
        $groupCount = max(1, (int) ($entry['group_count'] ?? 1));
        $entry['selection_value'] = $groupCount > 1 && !empty($entry['group_hash'])
            ? 'group:' . (string) $entry['group_hash']
            : (string) ((int) ($entry['id'] ?? 0));
        $exportParams = ['id' => (int) ($entry['id'] ?? 0)];
        if ($groupCount > 1 && !empty($entry['group_hash'])) {
            $exportParams['group'] = (string) $entry['group_hash'];
        }
        $entry['export_url'] = url_for('admin_log_export', $exportParams);
        $entry['members_url'] = $groupCount > 1 && !empty($entry['group_hash'])
            ? url_for('admin_log_group_members', [
                'id' => (int) ($entry['id'] ?? 0),
                'group' => (string) $entry['group_hash'],
            ])
            : '';
        $entry['context_pretty_json'] = $context !== []
            ? (string) json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '';
        $viewModels[] = $entry;
    }
    return $viewModels;
}

/**
 * Render the admin log table rows for normal page loads and live search responses.
 *
 * @param array $logs Logs value.
 * @return string Text result for the caller.
 */
function render_admin_log_table_rows(array $logs): string
{
    return view_render_admin_log_table_rows(admin_log_table_row_view_models($logs), admin_log_table_row_labels());
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
 * Build filesystem archive pagination presentation state.
 *
 * @param int $page Current archive page.
 * @param int $pages Total archive pages.
 * @return array<string, mixed> Presentation-only pagination model.
 */
function admin_log_archive_pagination_view_model(int $page, int $pages): array
{
    $page = max(1, $page);
    $pages = max(1, $pages);
    return [
        'visible' => $pages > 1,
        'previous_url' => $page > 1 ? admin_log_archive_page_url($page - 1) : '',
        'next_url' => $page < $pages ? admin_log_archive_page_url($page + 1) : '',
        'aria_label' => admin_log_english_t('admin.logs.archive.pagination_aria', 'Archived log pages'),
        'previous_label' => admin_log_english_t('pagination.previous', 'Previous'),
        'next_label' => admin_log_english_t('pagination.next', 'Next'),
        'status_label' => admin_log_english_t('pagination.page_of', 'Page {page} of {pages}', [
            'page' => (string) $page,
            'pages' => (string) $pages,
        ]),
    ];
}

/**
 * Render Admin log archive pagination.
 *
 * @param int $page Current archive page.
 * @param int $pages Total archive pages.
 * @return string Rendered pagination HTML.
 */
function render_admin_log_archive_pagination(int $page, int $pages): string
{
    return view_render_admin_log_archive_pagination(admin_log_archive_pagination_view_model($page, $pages));
}

/**
 * Build filesystem-backed Admin log archive presentation state.
 *
 * @param array<string,mixed> $status Archive-maintenance status.
 * @param array<string,mixed> $archiveList Paginated archive listing.
 * @param string $notice Optional controller flash notice.
 * @return array<string, mixed> Presentation-only archive model.
 */
function admin_log_archive_panel_view_model(array $status, array $archiveList, string $notice = ''): array
{
    $retentionDays = max(0, (int) ($status['retention_days'] ?? 30));
    $inventory = is_array($status['inventory'] ?? null) ? $status['inventory'] : [];
    $nextRunAt = max(0, (int) ($status['next_run_at'] ?? 0));
    $lastResult = is_array($status['last_result'] ?? null) ? $status['last_result'] : [];
    $archivePage = max(1, (int) ($archiveList['page'] ?? 1));
    $archivePages = max(1, (int) ($archiveList['pages'] ?? 1));
    $items = is_array($archiveList['items'] ?? null) ? $archiveList['items'] : [];

    $retentionOptions = [];
    foreach (admin_log_archive_retention_options() as $days) {
        $retentionOptions[] = [
            'days' => (int) $days,
            'label' => $days === 0
                ? admin_log_english_t('admin.logs.archive.retention_forever_option', 'Forever, disable automatic archiving')
                : admin_log_english_t('common.days_count', '{count} days', ['count' => (string) $days]),
        ];
    }

    $retentionLabel = $retentionDays === 0
        ? admin_log_english_t('common.forever', 'Forever')
        : admin_log_english_t('common.days_count', '{count} days', ['count' => (string) $retentionDays]);
    $oldestDate = (string) (($inventory['oldest_date'] ?? '') !== '' ? $inventory['oldest_date'] : admin_log_english_t('common.none', 'none'));
    $newestDate = (string) (($inventory['newest_date'] ?? '') !== '' ? $inventory['newest_date'] : admin_log_english_t('common.none', 'none'));
    $nextRunLabel = $retentionDays === 0
        ? admin_log_english_t('common.disabled', 'disabled')
        : ($nextRunAt <= time() ? admin_log_english_t('admin.logs.archive.due_now', 'due now') : date('Y-m-d H:i:s', $nextRunAt));

    $lastSummary = '';
    if ($lastResult !== []) {
        $lastReason = (string) ($lastResult['reason'] ?? '');
        $lastDate = (string) ($lastResult['archive_date'] ?? '');
        $lastRows = max(0, (int) ($lastResult['archived_rows'] ?? 0));
        $lastDeleted = max(0, (int) ($lastResult['deleted_rows'] ?? 0));
        $fallbackState = !empty($lastResult['ok'])
            ? admin_log_english_t('common.completed', 'completed')
            : admin_log_english_t('common.failed', 'failed');
        $lastSummary = admin_log_english_t('admin.logs.archive.last_cycle', 'Last cycle: {state}.', [
            'state' => $lastReason !== '' ? $lastReason : $fallbackState,
        ]);
        if ($lastDate !== '') {
            $lastSummary .= ' ' . admin_log_english_t('admin.logs.archive.last_cycle_archive', 'Archive day {date}, {preserved} rows preserved, {removed} live rows removed.', [
                'date' => $lastDate,
                'preserved' => (string) $lastRows,
                'removed' => (string) $lastDeleted,
            ]);
        }
        if (!empty($lastResult['error'])) {
            $lastSummary .= ' ' . admin_log_english_t('common.error_detail', 'Error: {error}', ['error' => (string) $lastResult['error']]);
        }
    }

    $itemModels = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $date = (string) ($item['date'] ?? '');
        if (!admin_log_archive_valid_date($date)) {
            continue;
        }
        $fileName = (string) ($item['file_name'] ?? admin_log_archive_file_name($date));
        $deleteConfirm = admin_log_english_t('admin.logs.archive.delete_confirm', 'Permanently delete {file}? This archived log data cannot be recovered from PHP Gallery.', [
            'file' => $fileName,
        ]);
        $itemModels[] = [
            'date' => $date,
            'file_name' => $fileName,
            'manifest_value' => !empty($item['manifest_available'])
                ? (string) max(0, (int) ($item['row_count'] ?? 0))
                : admin_log_english_t('admin.logs.archive.manifest_unavailable', 'manifest unavailable'),
            'size_label' => admin_dashboard_format_bytes(max(0, (int) ($item['bytes'] ?? 0))),
            'created_at_label' => (string) (($item['created_at'] ?? '') !== '' ? $item['created_at'] : admin_log_english_t('common.unknown', 'unknown')),
            'html_url' => url_for('admin_log_archive_view', ['date' => $date, 'kind' => 'html']),
            'json_url' => url_for('admin_log_archive_view', ['date' => $date, 'kind' => 'json']),
            'download_url' => url_for('admin_log_archive_download', ['date' => $date]),
            'delete_confirm_json' => (string) json_encode($deleteConfirm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    return [
        'notice' => $notice,
        'zip_available' => !empty($status['zip_available']),
        'retention_days' => $retentionDays,
        'retention_options' => $retentionOptions,
        'maintenance_url' => url_for('admin_log_archive_maintenance'),
        'csrf_html' => csrf_field(),
        'pagination_html' => render_admin_log_archive_pagination($archivePage, $archivePages),
        'metrics' => [
            'retention' => $retentionLabel,
            'count' => (string) max(0, (int) ($inventory['count'] ?? 0)),
            'storage' => admin_dashboard_format_bytes(max(0, (int) ($inventory['total_bytes'] ?? 0))),
            'oldest' => $oldestDate,
            'newest' => $newestDate,
            'next_check' => $nextRunLabel,
        ],
        'last_summary' => $lastSummary,
        'items' => $itemModels,
        'labels' => [
            'maintenance_title' => admin_log_english_t('admin.logs.archive.maintenance_title', 'Planned Admin log maintenance'),
            'maintenance_intro' => admin_log_english_t('admin.logs.archive.maintenance_intro', 'Recent logs stay live in MariaDB. Older completed days are archived as permanent daily ZIP files containing JSON, a fully expanded static HTML report, and a verification manifest. Database rows are deleted only after that ZIP has been verified.'),
            'zip_unavailable' => admin_log_english_t('admin.logs.archive.zip_unavailable', 'PHP ZipArchive is not available. Automatic Admin log archival cannot run safely until the ZIP extension is enabled.'),
            'keep_live_logs' => admin_log_english_t('admin.logs.archive.keep_live_logs', 'Keep live logs'),
            'save_retention' => admin_log_english_t('admin.logs.archive.save_retention', 'Save retention'),
            'run_now' => admin_log_english_t('admin.logs.archive.run_now', 'Run maintenance cycle now'),
            'policy' => admin_log_english_t('admin.logs.archive.policy', 'The lightweight due counter is checked on normal gallery and Admin page loads. When due, one safe daily archive cycle runs after the visible response. A backlog is retried shortly; once caught up, the next normal check is approximately 24 hours later. Archived ZIP files are never deleted automatically.'),
            'live_retention' => admin_log_english_t('admin.logs.archive.live_retention', 'Live retention'),
            'archived_zips' => admin_log_english_t('admin.logs.archive.archived_zips', 'Archived ZIPs'),
            'storage' => admin_log_english_t('admin.logs.archive.storage', 'Archive storage'),
            'oldest' => admin_log_english_t('admin.logs.archive.oldest', 'Oldest archive'),
            'newest' => admin_log_english_t('admin.logs.archive.newest', 'Newest archive'),
            'next_check' => admin_log_english_t('admin.logs.archive.next_check', 'Next automatic check'),
            'files_title' => admin_log_english_t('admin.logs.archive.files_title', 'Archived logs'),
            'files_intro' => admin_log_english_t('admin.logs.archive.files_intro', 'These rows come directly from ZIP files on disk. View the frozen HTML/JSON through authenticated routes, download the original ZIP, or delete a selected archive manually.'),
            'none_yet' => admin_log_english_t('admin.logs.archive.none_yet', 'No Admin log ZIP archives exist yet.'),
            'date' => admin_log_english_t('common.date', 'Date'),
            'records' => admin_log_english_t('admin.logs.archive.records', 'Records'),
            'zip_size' => admin_log_english_t('admin.logs.archive.zip_size', 'ZIP size'),
            'created' => admin_log_english_t('common.created', 'Created'),
            'actions' => admin_log_english_t('common.actions', 'Actions'),
            'view_html' => admin_log_english_t('admin.logs.archive.view_html', 'View HTML'),
            'view_json' => admin_log_english_t('admin.logs.archive.view_json', 'View JSON'),
            'download_zip' => admin_log_english_t('admin.logs.archive.download_zip', 'Download ZIP'),
            'delete' => admin_log_english_t('common.delete', 'Delete'),
        ],
    ];
}

/**
 * Render filesystem-backed Admin log archive controls and archive files.
 *
 * @param array<string,mixed> $status Archive-maintenance status.
 * @param array<string,mixed> $archiveList Paginated archive listing.
 * @param string $notice Optional controller flash notice.
 */
function render_admin_log_archive_panel(array $status, array $archiveList, string $notice = ''): void
{
    view_render_admin_log_archive_panel(admin_log_archive_panel_view_model($status, $archiveList, $notice));
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
 * Build the shared Admin Logs page heading presentation model.
 *
 * The controller owns request-state preservation and capability policy so the
 * view only receives labels and already-resolved navigation URLs.
 *
 * @param string $activeSection Current normalized subsection.
 * @return array<string, mixed> Controller-prepared heading view model.
 */
function admin_log_page_heading_view_model(string $activeSection): array
{
    // $preservedParams keeps live-log filter state when temporarily opening maintenance.
    $preservedParams = $_GET;
    unset($preservedParams['page'], $preservedParams['section'], $preservedParams['ajax'], $preservedParams['archive_page']);

    return [
        'active_section' => $activeSection,
        'telemetry_enabled' => feature_capability_effective_enabled('telemetry'),
        'urls' => [
            'admin' => url_for('admin'),
            'telemetry' => url_for('admin_telemetry'),
            'logs' => url_for('admin_logs', $preservedParams),
            'maintenance' => url_for('admin_logs', array_merge($preservedParams, ['section' => 'maintenance'])),
        ],
        'labels' => [
            'title' => admin_log_english_t('admin.logs.title', 'Admin log'),
            'intro' => admin_log_english_t('admin.logs.intro', 'Operational events, failures, and maintenance actions.'),
            'back_to_dashboard' => admin_log_english_t('admin.logs.back_to_dashboard', 'Back to dashboard'),
            'anonymous_telemetry' => admin_log_english_t('admin.logs.anonymous_telemetry', 'Anonymous telemetry'),
            'sections_aria' => admin_log_english_t('admin.logs.sections_aria', 'Admin log sections'),
            'section_logs' => admin_log_english_t('admin.logs.section_logs', 'Logs'),
            'section_maintenance' => admin_log_english_t('admin.logs.section_maintenance', 'Maintenance & archives'),
        ],
    ];
}

/**
 * Render server-backed Admin Logs subtabs.
 *
 * Compatibility wrapper retained for existing controller call sites.
 *
 * @param string $activeSection Current normalized subsection.
 */
function render_admin_log_section_tabs(string $activeSection): void
{
    view_render_admin_log_section_tabs(admin_log_page_heading_view_model($activeSection));
}

/**
 * Render the shared Admin Logs page heading and subsection navigation.
 *
 * @param string $activeSection Current normalized subsection.
 */
function render_admin_log_page_heading(string $activeSection): void
{
    view_render_admin_log_page_heading(admin_log_page_heading_view_model($activeSection));
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
        render_admin_log_archive_panel($archiveStatus, $archiveList, $notice);
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
            'empty_html' => view_render_admin_log_empty(admin_log_english_t('admin.logs.no_entries_match', 'No log entries match the current filters.')),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    // Prepare the live-log presentation model after all filtering/query work is complete.
    $nextTimeSort = $timeSort === 'desc' ? 'asc' : 'desc';
    $liveViewModel = [
        'notice' => $notice,
        'category' => $category,
        'selected_severities' => $selectedSeverities,
        'query' => $query,
        'time_sort' => $timeSort,
        'next_time_sort' => $nextTimeSort,
        'time_sort_symbol' => $timeSort === 'desc' ? '↓' : '↑',
        'page_size' => $pageSize,
        'page_size_options' => admin_log_page_size_options(),
        'grouped' => $grouped,
        'current_page' => $currentPage,
        'count_text' => $countText,
        'has_logs' => $logs !== [],
        'pagination_html' => $paginationHtml,
        'rows_html' => render_admin_log_table_rows($logs),
        'severity_summary' => admin_log_severity_filter_summary($selectedSeverities),
        'category_options' => admin_log_category_options(),
        'severity_options' => admin_log_severity_options(),
        'status_options' => admin_log_status_options(),
        'csrf_html' => csrf_field(),
        'urls' => [
            'index' => base_url('index.php'),
            'live' => url_for('admin_logs'),
            'export_all_zip' => url_for('admin_logs_export_zip'),
            'reset_severity' => url_for('admin_logs', ['reset_severity' => '1']),
            'time_sort' => admin_log_filter_url(['time_sort' => $nextTimeSort, 'log_page' => 1]),
            'update' => url_for('admin_log_update'),
        ],
        'labels' => [
            'filters' => admin_log_english_t('admin.logs.filters', 'Filters'),
            'filters_intro' => admin_log_english_t('admin.logs.filters_intro', 'Refine the operational log by category, severity, grouping, and row count.'),
            'export_all_zip' => admin_log_english_t('admin.logs.export_all_zip', 'Export all logs ZIP'),
            'searching' => admin_log_english_t('admin.logs.searching', 'Searching...'),
            'updated' => admin_log_english_t('admin.logs.updated', 'Updated.'),
            'live_search_failed' => admin_log_english_t('admin.logs.live_search_failed', 'Live search failed. Use Apply filters.'),
            'shown_suffix' => admin_log_english_t('admin.logs.shown_suffix', 'shown'),
            'when' => admin_log_english_t('admin.logs.when', 'When'),
            'filter_scope' => admin_log_english_t('admin.logs.filter_scope', 'Log scope'),
            'category' => admin_log_english_t('admin.logs.category', 'Category'),
            'all_categories' => admin_log_english_t('admin.logs.all_categories', 'All categories'),
            'time_order' => admin_log_english_t('admin.logs.time_order', 'Time order'),
            'newest_first' => admin_log_english_t('admin.logs.newest_first', 'Newest first'),
            'oldest_first' => admin_log_english_t('admin.logs.oldest_first', 'Oldest first'),
            'grouping' => admin_log_english_t('admin.logs.grouping', 'Grouping'),
            'group_similar' => admin_log_english_t('admin.logs.group_similar', 'Group similar events'),
            'show_individual' => admin_log_english_t('admin.logs.show_individual', 'Show individual rows'),
            'per_page' => admin_log_english_t('admin.logs.per_page', 'Rows per page'),
            'severity_filter_all_summary' => admin_log_english_t('admin.logs.severity_filter_all_summary', 'All severities are shown.'),
            'severity_filter_active_summary' => admin_log_english_t('admin.logs.severity_filter_active_summary', 'Active severities: {values}'),
            'severity' => admin_log_english_t('admin.logs.severity', 'Severity'),
            'severity_filter_hint' => admin_log_english_t('admin.logs.severity_filter_hint', 'Pick one or more severities. Empty means all.'),
            'search' => admin_log_english_t('admin.logs.search', 'Search'),
            'search_placeholder' => admin_log_english_t('admin.logs.search_placeholder', 'Event key, message, context, request, or route'),
            'apply_filters' => admin_log_english_t('admin.logs.apply_filters', 'Apply filters'),
            'reset_severity_filter' => admin_log_english_t('admin.logs.reset_severity_filter', 'Reset severity filter'),
            'entries' => admin_log_english_t('admin.logs.entries', 'Entries'),
            'no_entries_match' => admin_log_english_t('admin.logs.no_entries_match', 'No log entries match the current filters.'),
            'select' => admin_log_english_t('admin.logs.select', 'Select'),
            'instances' => admin_log_english_t('admin.logs.instances', 'Instances'),
            'event' => admin_log_english_t('admin.logs.event', 'Event'),
            'message' => admin_log_english_t('admin.logs.message', 'Message'),
            'by' => admin_log_english_t('admin.logs.by', 'By'),
            'bulk_set_selected' => admin_log_english_t('admin.logs.bulk_set_selected', 'Bulk set selected'),
            'apply_to_selected' => admin_log_english_t('admin.logs.apply_to_selected', 'Apply to selected'),
            'bulk_grouping_hint' => admin_log_english_t('admin.logs.bulk_grouping_hint', 'Selecting a grouped row applies the state to every matching instance in that group.'),
        ],
    ];

    render_header(admin_log_english_t('admin.logs.title', 'Admin log'));
    render_admin_log_page_heading($section);
    view_render_admin_logs_live($liveViewModel);
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
        $label = $days === 0
            ? admin_log_english_t('common.forever', 'Forever')
            : admin_log_english_t('common.days_count', '{count} days', ['count' => (string) $days]);
        flash_message('admin_notice', admin_log_english_t('admin.logs.archive.retention_saved', 'Admin log live retention saved: {retention}. Archived ZIP files are never deleted automatically.', [
            'retention' => $label,
        ]));
        redirect_to(url_for('admin_logs', ['section' => 'maintenance']));
    }

    if ($action === 'run_now') {
        $result = admin_log_archive_maintenance_run([
            'source' => 'admin_manual',
            'force' => true,
        ]);
        if (!empty($result['busy'])) {
            flash_message('admin_notice', admin_log_english_t('admin.logs.archive.already_running', 'Admin log archive maintenance is already running in another request.'));
        } elseif (empty($result['ok'])) {
            flash_message('admin_notice', admin_log_english_t('admin.logs.archive.failed_with_error', 'Admin log archive maintenance failed: {error}', [
                'error' => (string) ($result['error'] ?? $result['reason'] ?? admin_log_english_t('common.unknown_error', 'unknown error')),
            ]));
        } elseif ((string) ($result['reason'] ?? '') === 'retention_forever') {
            flash_message('admin_notice', admin_log_english_t('admin.logs.archive.disabled_forever', 'Admin log archive maintenance is disabled because live retention is set to Forever.'));
        } elseif (!empty($result['archive_date'])) {
            $message = admin_log_english_t('admin.logs.archive.cycle_archived', 'Admin log maintenance archived {date}: {archived} rows preserved in ZIP, {deleted} represented live rows removed.', [
                'date' => (string) $result['archive_date'],
                'archived' => (string) max(0, (int) ($result['archived_rows'] ?? 0)),
                'deleted' => (string) max(0, (int) ($result['deleted_rows'] ?? 0)),
            ]);
            if (!empty($result['has_more'])) {
                $message .= ' ' . admin_log_english_t('admin.logs.archive.more_days', 'More eligible days remain and will continue in later safe cycles.');
            } else {
                $message .= ' ' . admin_log_english_t('admin.logs.archive.backlog_caught_up', 'The archive backlog is caught up.');
            }
            flash_message('admin_notice', $message);
        } else {
            flash_message('admin_notice', admin_log_english_t('admin.logs.archive.nothing_to_archive', 'Admin log maintenance found no completed days old enough to archive.'));
        }
        redirect_to(url_for('admin_logs', ['section' => 'maintenance']));
    }

    if ($action === 'delete_archive') {
        $date = trim((string) ($_POST['date'] ?? ''));
        if (!admin_log_archive_valid_date($date)) {
            flash_message('admin_notice', admin_log_english_t('admin.logs.archive.invalid_date', 'Invalid Admin log archive date.'));
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
                flash_message('admin_notice', admin_log_english_t('admin.logs.archive.deleted_file', 'Deleted archived Admin log file {file}.', [
                    'file' => admin_log_archive_file_name($date),
                ]));
            } else {
                flash_message('admin_notice', admin_log_english_t('admin.logs.archive.file_missing', 'The selected Admin log archive file no longer exists.'));
            }
        } catch (Throwable $exception) {
            flash_message('admin_notice', admin_log_english_t('admin.logs.archive.delete_failed', 'Unable to delete the selected Admin log archive: {error}', [
                'error' => $exception->getMessage(),
            ]));
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
        foreach (admin_log_archive_member_chunks($date, $kind) as $chunk) {
            echo $chunk;
            if (connection_aborted()) {
                break;
            }
        }
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
        $download = admin_log_export_zip_descriptor($filePath, admin_log_export_zip_filename());
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . (string) $download['filename'] . '"');
        header('Content-Length: ' . (int) $download['size']);
        header('X-Content-Type-Options: nosniff');
        readfile((string) $download['path']);
        @unlink((string) $download['path']);
        return;
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
