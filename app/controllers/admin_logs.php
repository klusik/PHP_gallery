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
        $statusForm .= '</select><button type="submit">Update</button></form>';
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
    // $logs stores the filtered admin log entries.
    $logs = admin_log_list($status, 150, [
        'category' => $category,
        'severity' => $severity,
        'q' => $query,
    ]);
    render_header('Admin log');
    echo '<section class="hero"><h1>Admin log</h1><p>Operational events, failures, maintenance actions, and workflow states.</p><nav class="nav">';
    echo '<a class="button secondary" href="' . e(url_for('admin')) . '">Back to dashboard</a>';
    echo '<a class="button secondary" href="' . e(url_for('admin_telemetry')) . '">Anonymous telemetry</a>';
    echo '</nav></section>';

    echo '<section class="panel"><h2>Filters</h2><form method="get" action="' . e(base_url('index.php')) . '" class="admin-log-filter-grid">';
    echo '<input type="hidden" name="page" value="admin_logs">';
    echo '<label>Status<select name="status"><option value="">All states</option>';
    foreach (admin_log_status_options() as $value => $label) {
        echo '<option value="' . e($value) . '"' . ($status === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Category<select name="category"><option value="">All categories</option>';
    foreach (admin_log_category_options() as $value => $label) {
        echo '<option value="' . e($value) . '"' . ($category === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Severity<select name="severity"><option value="">All severities</option>';
    foreach (admin_log_severity_options() as $value => $label) {
        echo '<option value="' . e($value) . '"' . ($severity === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Search<input name="q" value="' . e($query) . '" placeholder="Event key or message"></label>';
    echo '<div class="bulk-row"><button type="submit">Apply filters</button><a class="button secondary" href="' . e(url_for('admin_logs')) . '">Clear</a></div>';
    echo '</form></section>';

    if (!$logs) {
        echo '<section class="panel"><p>No log entries match the current filters.</p></section>';
        render_footer();
        return;
    }
    echo '<section class="panel"><h2>Entries</h2>';
    echo '<table class="admin-log-table"><thead><tr><th>Select</th><th>When</th><th>State</th><th>Severity</th><th>Category</th><th>Event</th><th>Message</th><th>By</th><th>Set state</th></tr></thead><tbody>';
    foreach ($logs as $entry) {
        // $context stores decoded structured context shown under the message.
        $context = [];
        if (!empty($entry['context_json'])) {
            $decoded = json_decode((string) $entry['context_json'], true);
            $context = is_array($decoded) ? $decoded : [];
        }
        echo '<tr data-admin-log-row>';
        echo '<td><input type="checkbox" name="log_ids[]" value="' . (int) $entry['id'] . '" form="admin-log-bulk-form"></td>';
        echo '<td>' . e((string) $entry['created_at']) . '</td>';
        echo '<td data-admin-log-state>' . e(admin_log_status_label((string) ($entry['status'] ?? 'todo'))) . '</td>';
        echo '<td><span class="log-severity log-severity-' . e((string) ($entry['severity'] ?? $entry['level'] ?? 'info')) . '">' . e((string) ($entry['severity'] ?? $entry['level'] ?? 'info')) . '</span></td>';
        echo '<td>' . e((string) ($entry['category'] ?? 'other')) . '</td>';
        echo '<td><code>' . e((string) $entry['event_key']) . '</code>';
        if (!empty($entry['subject_type']) || !empty($entry['subject_id'])) {
            echo '<div class="muted">' . e((string) ($entry['subject_type'] ?? '')) . ' #' . e((string) ($entry['subject_id'] ?? '')) . '</div>';
        }
        echo '</td>';
        echo '<td>' . e((string) $entry['message']);
        if ($context) {
            echo '<details class="log-context"><summary>Details</summary><pre>' . e(json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre></details>';
        }
        if (!empty($entry['request_id'])) {
            echo '<div class="muted">Request ' . e((string) $entry['request_id']) . '</div>';
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
    echo '</tbody></table><form id="admin-log-bulk-form" method="post" action="' . e(url_for('admin_log_update')) . '">' . csrf_field();
    echo '<div class="bulk-row"><label>Bulk set selected<select name="status">';
    foreach (admin_log_status_options() as $value => $label) {
        echo '<option value="' . e($value) . '">' . e($label) . '</option>';
    }
    echo '</select></label><button type="submit" name="action" value="bulk">Apply to selected</button></div></form></section>';
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
