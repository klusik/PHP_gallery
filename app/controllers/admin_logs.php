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
    function admin_log_english_t(string $key, string|array|null $fallback = null, array $parameters = []): string
    {
        if (is_array($fallback)) {
            $parameters = $fallback;
            $fallback = null;
        }

        $text = null;
        if (function_exists('translation_load_language')) {
            $englishStrings = translation_load_language('en');
            if (array_key_exists($key, $englishStrings) && is_string($englishStrings[$key])) {
                $text = $englishStrings[$key];
            }
        }

        if ($text === null) {
            $text = $fallback ?? $key;
        }

        if (function_exists('translation_interpolate')) {
            return translation_interpolate($text, $parameters);
        }

        foreach ($parameters as $name => $value) {
            $text = str_replace('{' . (string) $name . '}', (string) $value, $text);
        }
        return $text;
    }
}

function render_admin_log_row(array $entry, bool $withActions = false): string
{
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
    // Variable $stateLabel stores this steps working value.
    $stateLabel = admin_log_status_label((string) ($entry['status'] ?? 'todo'));
    // Variable $statusForm stores this steps working value.
    $statusForm = '';
    if ($withActions) {
        // $statusForm stores an intermediate value used by the surrounding gallery workflow.
        $statusForm = '<form method="post" action="' . e(url_for('admin_log_update')) . '" class="inline-action-form">' . csrf_field()
            . '<input type="hidden" name="log_id" value="' . (int) $entry['id'] . '">'
            . '<select name="status">';
        foreach (admin_log_status_options() as $status => $label) {
            $statusForm .= '<option value="' . e($status) . '"' . ((string) ($entry['status'] ?? '') === $status ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        $statusForm .= '</select><button type="submit">' . e(admin_log_english_t('admin.logs.update', 'Update')) . '</button></form>';
    }
    return '<tr>'
        . '<td>' . e((string) $entry['created_at']) . '</td>'
        . '<td>' . e($stateLabel) . '</td>'
        . '<td>' . e((string) $entry['event_key']) . '</td>'
        . '<td>' . e((string) $entry['message']) . ($context ? '<div class="muted">' . e(json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</div>' : '') . '</td>'
        . '<td>' . e((string) ($entry['username'] ?? '')) . '</td>'
        . ($withActions ? '<td>' . $statusForm . '</td>' : '')
        . '</tr>';
}

/**
 * Handles render admin feature flag logic for the gallery application.
 * @param mixed $enabled Input used by this operation.
 * @param mixed $symbol Input used by this operation.
 * @param mixed $label Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function render_admin_feature_flag(bool $enabled, string $symbol, string $label): string
{
    if (!$enabled) {
        return '';
    }
    return '<span class="admin-flag is-enabled" title="' . e($label) . '" aria-label="' . e($label) . '">' . e($symbol) . '</span>';
}


/**
 * Return a normalized admin log time order key.
 */
function admin_log_normalize_time_sort(?string $timeSort): string
{
    return strtolower((string) $timeSort) === 'asc' ? 'asc' : 'desc';
}

/**
 * Build the admin log URL while preserving active filters.
 */
function admin_log_filter_url(array $overrides = []): string
{
    // $params stores query parameters that should remain stable across filter and sort clicks.
    $params = [
        'status' => (string) ($_GET['status'] ?? ''),
        'category' => (string) ($_GET['category'] ?? ''),
        'severity' => (string) ($_GET['severity'] ?? ''),
        'q' => trim((string) ($_GET['q'] ?? '')),
        'time_sort' => admin_log_normalize_time_sort((string) ($_GET['time_sort'] ?? 'desc')),
    ];
    foreach ($overrides as $key => $value) {
        $params[(string) $key] = $value;
    }
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }
    return url_for('admin_logs', $params);
}

/**
 * Render the admin log table rows for normal page loads and live search responses.
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
        echo '<tr data-admin-log-row>';
        echo '<td><input type="checkbox" name="log_ids[]" value="' . (int) $entry['id'] . '" form="admin-log-bulk-form"></td>';
        echo '<td data-admin-log-created-at>' . e((string) $entry['created_at']) . '</td>';
        echo '<td data-admin-log-state>' . e(admin_log_status_label((string) ($entry['status'] ?? 'todo'))) . '</td>';
        echo '<td><span class="log-severity log-severity-' . e((string) ($entry['severity'] ?? $entry['level'] ?? 'info')) . '">' . e((string) ($entry['severity'] ?? $entry['level'] ?? 'info')) . '</span></td>';
        echo '<td>' . e((string) ($entry['category'] ?? 'other')) . '</td>';
        echo '<td><details class="log-entry-details"><summary><code>' . e((string) $entry['event_key']) . '</code></summary>';
        echo '<div class="log-detail-actions"><a class="button secondary" href="' . e(url_for('admin_log_export', ['id' => (int) $entry['id']])) . '">' . e(admin_log_english_t('admin.logs.save_details_txt', 'Save details as TXT')) . '</a></div>';
        echo '<dl class="log-detail-list">';
        echo '<dt>' . e(admin_log_english_t('admin.logs.log_id', 'Log ID')) . '</dt><dd>' . (int) $entry['id'] . '</dd>';
        echo '<dt>' . e(admin_log_english_t('admin.logs.created_at', 'Created at')) . '</dt><dd>' . e((string) $entry['created_at']) . '</dd>';
        echo '<dt>' . e(admin_log_english_t('admin.logs.level', 'Level')) . '</dt><dd>' . e((string) ($entry['level'] ?? '')) . '</dd>';
        echo '<dt>' . e(admin_log_english_t('admin.logs.severity', 'Severity')) . '</dt><dd>' . e((string) ($entry['severity'] ?? $entry['level'] ?? 'info')) . '</dd>';
        echo '<dt>' . e(admin_log_english_t('admin.logs.category', 'Category')) . '</dt><dd>' . e((string) ($entry['category'] ?? 'other')) . '</dd>';
        echo '<dt>' . e(admin_log_english_t('admin.logs.route', 'Route')) . '</dt><dd>' . e((string) ($entry['route_name'] ?? '')) . '</dd>';
        echo '<dt>' . e(admin_log_english_t('admin.logs.request_id', 'Request ID')) . '</dt><dd>' . e((string) ($entry['request_id'] ?? '')) . '</dd>';
        echo '</dl>';
        echo '<code>' . e((string) $entry['event_key']) . '</code>';
        if (!empty($entry['subject_type']) || !empty($entry['subject_id'])) {
            echo '<div class="muted">' . e((string) ($entry['subject_type'] ?? '')) . ' #' . e((string) ($entry['subject_id'] ?? '')) . '</div>';
        }
        echo '</details></td>';
        echo '<td>' . e((string) $entry['message']);
        if ($context) {
            echo '<details class="log-context"><summary>' . e(admin_log_english_t('admin.logs.details', 'Details')) . '</summary><pre>' . e(json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre></details>';
        }
        if (!empty($entry['request_id'])) {
            echo '<div class="muted">' . e(admin_log_english_t('admin.logs.request_prefix', 'Request')) . ' ' . e((string) $entry['request_id']) . '</div>';
        }
        echo '</td>';
        echo '<td>' . e((string) ($entry['username'] ?? '')) . '</td>';
        echo '<td><select name="status" data-admin-log-status-select data-log-id="' . (int) $entry['id'] . '" data-update-url="' . e(url_for('admin_log_update')) . '" data-csrf-token="' . e(csrf_token()) . '">';
        foreach (admin_log_status_options() as $value => $label) {
            echo '<option value="' . e($value) . '"' . ((string) ($entry['status'] ?? '') === $value ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        echo '</select></td>';
        echo '</tr>';
    }
    return (string) ob_get_clean();
}

/**
 * Handles cms admin logs logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function cms_admin_logs(): void
{
    require_admin();
    // $status stores the workflow status filter.
    $status = isset($_GET['status']) ? (string) $_GET['status'] : null;
    // $category stores the operational category filter.
    $category = isset($_GET['category']) ? (string) $_GET['category'] : '';
    // $severity stores the severity filter.
    $severity = isset($_GET['severity']) ? (string) $_GET['severity'] : '';
    // $query stores the text search filter.
    $query = trim((string) ($_GET['q'] ?? ''));
    // $timeSort stores the selected chronological order.
    $timeSort = admin_log_normalize_time_sort((string) ($_GET['time_sort'] ?? 'desc'));
    // $logs stores the filtered admin log entries.
    $logs = admin_log_list($status, 150, [
        'category' => $category,
        'severity' => $severity,
        'q' => $query,
        'time_sort' => $timeSort,
    ]);

    if ((string) ($_GET['ajax'] ?? '') === '1') {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'rows_html' => render_admin_log_table_rows($logs),
            'count' => count($logs),
            'time_sort' => $timeSort,
            'empty_html' => '<p>' . e(admin_log_english_t('admin.logs.no_entries_match', 'No log entries match the current filters.')) . '</p>',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    render_header(admin_log_english_t('admin.logs.title', 'Admin log'));
    echo '<section class="hero"><h1>' . e(admin_log_english_t('admin.logs.title', 'Admin log')) . '</h1><p>' . e(admin_log_english_t('admin.logs.intro', 'Operational events, failures, maintenance actions, and workflow states.')) . '</p><nav class="nav">';
    echo '<a class="button secondary" href="' . e(url_for('admin')) . '">' . e(admin_log_english_t('admin.logs.back_to_dashboard', 'Back to dashboard')) . '</a>';
    echo '<a class="button secondary" href="' . e(url_for('admin_telemetry')) . '">' . e(admin_log_english_t('admin.logs.anonymous_telemetry', 'Anonymous telemetry')) . '</a>';
    echo '<a class="button" href="' . e(url_for('admin_logs_export_zip')) . '">' . e(admin_log_english_t('admin.logs.export_all_zip', 'Export all logs ZIP')) . '</a>';
    echo '</nav></section>';

    echo '<section class="panel"><h2>' . e(admin_log_english_t('admin.logs.filters', 'Filters')) . '</h2><form method="get" action="' . e(base_url('index.php')) . '" class="admin-log-filter-grid" data-admin-log-filter-form data-admin-log-live-url="' . e(url_for('admin_logs')) . '" data-admin-log-searching-text="' . e(admin_log_english_t('admin.logs.searching', 'Searching...')) . '" data-admin-log-updated-text="' . e(admin_log_english_t('admin.logs.updated', 'Updated.')) . '" data-admin-log-failed-text="' . e(admin_log_english_t('admin.logs.live_search_failed', 'Live search failed. Use Apply filters.')) . '" data-admin-log-shown-text="' . e(admin_log_english_t('admin.logs.shown_suffix', 'shown')) . '" data-admin-log-when-text="' . e(admin_log_english_t('admin.logs.when', 'When')) . '">';
    echo '<input type="hidden" name="page" value="admin_logs">';
    echo '<label>' . e(admin_log_english_t('admin.logs.status', 'Status')) . '<select name="status" data-admin-log-live-filter><option value="">' . e(admin_log_english_t('admin.logs.all_states', 'All states')) . '</option>';
    foreach (admin_log_status_options() as $value => $label) {
        echo '<option value="' . e($value) . '"' . ($status === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label>' . e(admin_log_english_t('admin.logs.category', 'Category')) . '<select name="category" data-admin-log-live-filter><option value="">' . e(admin_log_english_t('admin.logs.all_categories', 'All categories')) . '</option>';
    foreach (admin_log_category_options() as $value => $label) {
        echo '<option value="' . e($value) . '"' . ($category === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label>' . e(admin_log_english_t('admin.logs.severity', 'Severity')) . '<select name="severity" data-admin-log-live-filter><option value="">' . e(admin_log_english_t('admin.logs.all_severities', 'All severities')) . '</option>';
    foreach (admin_log_severity_options() as $value => $label) {
        echo '<option value="' . e($value) . '"' . ($severity === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label>' . e(admin_log_english_t('admin.logs.time_order', 'Time order')) . '<select name="time_sort" data-admin-log-live-filter><option value="desc"' . ($timeSort === 'desc' ? ' selected' : '') . '>' . e(admin_log_english_t('admin.logs.newest_first', 'Newest first')) . '</option><option value="asc"' . ($timeSort === 'asc' ? ' selected' : '') . '>' . e(admin_log_english_t('admin.logs.oldest_first', 'Oldest first')) . '</option></select></label>';
    echo '<label>' . e(admin_log_english_t('admin.logs.search', 'Search')) . '<input name="q" value="' . e($query) . '" placeholder="' . e(admin_log_english_t('admin.logs.search_placeholder', 'Event key, message, context, request, or route')) . '" autocomplete="off" data-admin-log-live-search></label>';
    echo '<div class="bulk-row"><button type="submit">' . e(admin_log_english_t('admin.logs.apply_filters', 'Apply filters')) . '</button><a class="button secondary" href="' . e(url_for('admin_logs')) . '">' . e(admin_log_english_t('admin.logs.clear', 'Clear')) . '</a><span class="muted" data-admin-log-live-state aria-live="polite"></span></div>';
    echo '</form></section>';

    echo '<section class="panel" data-admin-log-results><h2>' . e(admin_log_english_t('admin.logs.entries', 'Entries')) . ' <span class="muted" data-admin-log-count>(' . count($logs) . ' ' . e(admin_log_english_t('admin.logs.shown_suffix', 'shown')) . ')</span></h2>';
    if (!$logs) {
        echo '<div data-admin-log-empty><p>' . e(admin_log_english_t('admin.logs.no_entries_match', 'No log entries match the current filters.')) . '</p></div>';
    }
    echo '<div class="admin-log-table-wrap">';
    echo '<table class="admin-log-table"><thead><tr><th>' . e(admin_log_english_t('admin.logs.select', 'Select')) . '</th><th><a href="' . e(admin_log_filter_url(['time_sort' => $timeSort === 'desc' ? 'asc' : 'desc'])) . '" data-admin-log-time-sort-link data-next-sort="' . e($timeSort === 'desc' ? 'asc' : 'desc') . '">' . e(admin_log_english_t('admin.logs.when', 'When')) . ' ' . e($timeSort === 'desc' ? '↓' : '↑') . '</a></th><th>' . e(admin_log_english_t('admin.logs.state', 'State')) . '</th><th>' . e(admin_log_english_t('admin.logs.severity', 'Severity')) . '</th><th>' . e(admin_log_english_t('admin.logs.category', 'Category')) . '</th><th>' . e(admin_log_english_t('admin.logs.event', 'Event')) . '</th><th>' . e(admin_log_english_t('admin.logs.message', 'Message')) . '</th><th>' . e(admin_log_english_t('admin.logs.by', 'By')) . '</th><th>' . e(admin_log_english_t('admin.logs.set_state', 'Set state')) . '</th></tr></thead><tbody data-admin-log-tbody>';
    echo render_admin_log_table_rows($logs);
    echo '</tbody></table></div><form id="admin-log-bulk-form" method="post" action="' . e(url_for('admin_log_update')) . '">' . csrf_field();
    echo '<div class="bulk-row"><label>' . e(admin_log_english_t('admin.logs.bulk_set_selected', 'Bulk set selected')) . '<select name="status">';
    foreach (admin_log_status_options() as $value => $label) {
        echo '<option value="' . e($value) . '">' . e($label) . '</option>';
    }
    echo '</select></label><button type="submit" name="action" value="bulk">' . e(admin_log_english_t('admin.logs.apply_to_selected', 'Apply to selected')) . '</button></div></form></section>';
    render_footer();
}

/**
 * Handles cms admin log update logic for the gallery application.
 * @return mixed Result produced by this operation.
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
        foreach (array_map('intval', $_POST['log_ids']) as $logId) {
            try {
                admin_log_update_status($logId, $status);
            } catch (RuntimeException $exception) {
                admin_log_event('error', 'admin_log.bulk_update_failed', 'Bulk admin log status update failed.', [
                    'log_id' => $logId,
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
    // $fileName stores a filesystem-safe diagnostic export name.
    $fileName = 'php-gallery-log-' . $logId . '-' . preg_replace('/[^0-9A-Za-z_-]/', '-', (string) ($entry['event_key'] ?? 'event')) . '.txt';
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('X-Content-Type-Options: nosniff');
    echo admin_log_export_text($entry);
}


/**
 * Export all admin logs as a ZIP containing matching CSV and JSON data files.
 */
function cms_admin_logs_export_zip(): void
{
    require_admin();
    $filePath = '';
    try {
        // $rows stores the complete admin log set so CSV and JSON are built from one database read.
        $rows = admin_log_export_rows();
        $payload = admin_log_export_payload($rows);
        $filePath = admin_log_export_temp_path();
        admin_log_create_export_zip($filePath, $payload);
        if (ob_get_level() > 0) {
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
