<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/logs.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides reusable application logic for gallery data, media, settings, or maintenance workflows.
 *
 * Responsibilities:
 *   - Keep domain logic reusable outside controllers
 *   - Protect existing behavior with small focused functions
 *   - Return predictable values for callers
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
 * Administrative log service model.
 *
 * This module owns the persistent admin log data model and status workflow.
 * It was separated from app/services.php so diagnostic storage can evolve without
 * changing gallery discovery, thumbnail generation, theme handling, or public path logic.
 *
 * The functions intentionally keep their original names and signatures. Existing
 * controllers and templates can keep calling admin_log_event(), admin_log_list(),
 * and related helpers without any routing or bootstrap migration.
 */

/**
 * Translate admin-log diagnostics with the English language pack regardless of
 * the selected public or admin UI language. Logs are support artifacts, so their
 * labels must stay stable across installations and screenshots.
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

function admin_log_schema_ready(): bool
{
    try {
        // $stmt stores an intermediate value used by the surrounding gallery workflow.
        $stmt = db()->query("SHOW COLUMNS FROM admin_logs LIKE 'status'");
        return $stmt && (bool) $stmt->fetch();
    } catch (PDOException) {
        return false;
    }
}


/**
 * Return whether an optional admin log column is available.
 */
function admin_log_column_exists(string $columnName): bool
{
    static $columns = null;
    if ($columns === null) {
        $columns = [];
        try {
            // $stmt stores all known admin log columns for this request.
            $stmt = db()->query('SHOW COLUMNS FROM admin_logs');
            foreach ($stmt->fetchAll() as $column) {
                $columns[(string) $column['Field']] = true;
            }
        } catch (Throwable) {
            $columns = [];
        }
    }
    return isset($columns[$columnName]);
}

/**
 * Return all admin log categories exposed by the observability layer.
 */
function admin_log_category_options(): array
{
    return [
        'system' => admin_log_english_t('admin.logs.category.system', 'System'),
        'gallery' => admin_log_english_t('admin.logs.category.gallery', 'Gallery'),
        'media' => admin_log_english_t('admin.logs.category.media', 'Media'),
        'upload' => admin_log_english_t('admin.logs.category.upload', 'Upload'),
        'thumbnail' => admin_log_english_t('admin.logs.category.thumbnail', 'Thumbnails'),
        'update' => admin_log_english_t('admin.logs.category.update', 'Updates'),
        'security' => admin_log_english_t('admin.logs.category.security', 'Security'),
        'database' => admin_log_english_t('admin.logs.category.database', 'Database'),
        'telemetry' => admin_log_english_t('admin.logs.category.telemetry', 'Telemetry'),
        'admin' => admin_log_english_t('admin.logs.category.admin', 'Admin'),
        'other' => admin_log_english_t('admin.logs.category.other', 'Other'),
    ];
}

/**
 * Return all admin log severities exposed by the observability layer.
 */
function admin_log_severity_options(): array
{
    return [
        'debug' => admin_log_english_t('admin.logs.severity.debug', 'Debug'),
        'info' => admin_log_english_t('admin.logs.severity.info', 'Info'),
        'notice' => admin_log_english_t('admin.logs.severity.notice', 'Notice'),
        'warning' => admin_log_english_t('admin.logs.severity.warning', 'Warning'),
        'error' => admin_log_english_t('admin.logs.severity.error', 'Error'),
        'critical' => admin_log_english_t('admin.logs.severity.critical', 'Critical'),
    ];
}

/**
 * Return a safe admin log route name for the current request.
 */
function admin_log_current_route_name(): string
{
    // $page stores the current route key without query parameters.
    $page = (string) ($_GET['page'] ?? 'unknown');
    $page = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $page) ?? 'unknown';
    return substr($page, 0, 80);
}

/**
 * Handles ensure admin log status schema logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function ensure_admin_log_status_schema(): bool
{
    try {
        // $tableExists stores an intermediate value used by the surrounding gallery workflow.
        $tableExists = db()->query("SHOW TABLES LIKE 'admin_logs'");
        if (!$tableExists || !$tableExists->fetch()) {
            return false;
        }
        // $statusColumn stores an intermediate value used by the surrounding gallery workflow.
        $statusColumn = db()->query("SHOW COLUMNS FROM admin_logs LIKE 'status'");
        if (!$statusColumn || !$statusColumn->fetch()) {
            db()->exec("ALTER TABLE admin_logs ADD COLUMN status ENUM('todo','doing','done','waiting') NOT NULL DEFAULT 'todo' AFTER level");
        }
        // $statusUpdatedAtColumn stores an intermediate value used by the surrounding gallery workflow.
        $statusUpdatedAtColumn = db()->query("SHOW COLUMNS FROM admin_logs LIKE 'status_updated_at'");
        if (!$statusUpdatedAtColumn || !$statusUpdatedAtColumn->fetch()) {
            db()->exec("ALTER TABLE admin_logs ADD COLUMN status_updated_at DATETIME NULL AFTER status");
        }
        // $statusIndex stores an intermediate value used by the surrounding gallery workflow.
        $statusIndex = db()->query("SHOW INDEX FROM admin_logs WHERE Key_name = 'admin_logs_status_created_index'");
        if (!$statusIndex || !$statusIndex->fetch()) {
            db()->exec("ALTER TABLE admin_logs ADD KEY admin_logs_status_created_index (status, created_at)");
        }
        return true;
    } catch (PDOException) {
        return false;
    }
}

/**
 * Handles admin log event logic for the gallery application.
 * @param mixed $level Input used by this operation.
 * @param mixed $eventKey Input used by this operation.
 * @param mixed $message Input used by this operation.
 * @param mixed $context Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function admin_log_event(string $level, string $eventKey, string $message, array $context = [], array $options = []): void
{
    if (!admin_log_schema_ready()) {
        return;
    }
    // $allowedLevels stores legacy levels preserved for backward compatibility.
    $allowedLevels = ['info', 'warning', 'error'];
    // $level stores the normalized legacy level.
    $level = in_array($level, $allowedLevels, true) ? $level : 'error';
    // $severity stores the richer severity used by the improved observability UI.
    $severity = (string) ($options['severity'] ?? $level);
    if ($severity === 'warning' && $level === 'info') {
        $level = 'warning';
    }
    if (in_array($severity, ['error', 'critical'], true)) {
        $level = 'error';
    }
    // $category stores the normalized operational category.
    $category = (string) ($options['category'] ?? 'other');
    // $categories stores known category keys.
    $categories = array_keys(admin_log_category_options());
    // $severities stores known severity keys.
    $severities = array_keys(admin_log_severity_options());
    $category = in_array($category, $categories, true) ? $category : 'other';
    $severity = in_array($severity, $severities, true) ? $severity : $level;

    try {
        // $user stores the authenticated admin associated with this operational event.
        $user = current_user();
        // $columns stores the insert column list, expanded only when migrations are present.
        $columns = ['user_id', 'level'];
        // $values stores placeholders matching the insert column list.
        $values = ['?', '?'];
        // $params stores insert values matching the insert column list.
        $params = [$user ? (int) $user['id'] : null, $level];

        if (admin_log_column_exists('category')) {
            $columns[] = 'category';
            $values[] = '?';
            $params[] = $category;
        }
        if (admin_log_column_exists('severity')) {
            $columns[] = 'severity';
            $values[] = '?';
            $params[] = $severity;
        }

        foreach (['event_key' => $eventKey, 'message' => $message] as $column => $value) {
            $columns[] = $column;
            $values[] = '?';
            $params[] = $value;
        }

        foreach (['subject_type', 'subject_id', 'request_id', 'route_name'] as $column) {
            if (!admin_log_column_exists($column)) {
                continue;
            }
            $columns[] = $column;
            $values[] = '?';
            if ($column === 'subject_id') {
                $params[] = isset($options[$column]) ? (int) $options[$column] : null;
            } elseif ($column === 'request_id') {
                $params[] = (string) ($options[$column] ?? (function_exists('telemetry_request_id') ? telemetry_request_id() : null));
            } elseif ($column === 'route_name') {
                $params[] = substr((string) ($options[$column] ?? admin_log_current_route_name()), 0, 80);
            } else {
                $params[] = isset($options[$column]) ? substr((string) $options[$column], 0, 40) : null;
            }
        }

        $columns[] = 'context_json';
        $values[] = '?';
        $params[] = $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $columns[] = 'created_at';
        $values[] = '?';
        $params[] = now_sql();

        // $stmt stores the backward-compatible dynamic admin log insert query.
        $stmt = db()->prepare('INSERT INTO admin_logs (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')');
        $stmt->execute($params);
    } catch (Throwable) {
    }
}

/**
 * Handles admin log status options logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function admin_log_status_options(): array
{
    return [
        'todo' => admin_log_english_t('admin.logs.status.todo', 'To be done'),
        'doing' => admin_log_english_t('admin.logs.status.doing', 'Will be done'),
        'waiting' => admin_log_english_t('admin.logs.status.waiting', 'Waiting'),
        'done' => admin_log_english_t('admin.logs.status.done', 'Done'),
    ];
}

/**
 * Handles admin log status label logic for the gallery application.
 * @param mixed $status Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function admin_log_status_label(string $status): string
{
    // $statuses stores an intermediate value used by the surrounding gallery workflow.
    $statuses = admin_log_status_options();
    return $statuses[$status] ?? $status;
}

/**
 * Handles admin log recent logic for the gallery application.
 * @param mixed $limit Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function admin_log_recent(int $limit = 12): array
{
    if (!admin_log_schema_ready()) {
        return [];
    }
    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = db()->prepare('SELECT l.*, u.username FROM admin_logs l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.created_at DESC, l.id DESC LIMIT ' . max(1, min(50, $limit)));
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Handles admin log list logic for the gallery application.
 * @param mixed $status Input used by this operation.
 * @param mixed $limit Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function admin_log_list(?string $status = null, int $limit = 100, array $filters = []): array
{
    if (!admin_log_schema_ready()) {
        return [];
    }
    // $sql stores the filtered admin log query.
    $sql = 'SELECT l.*, u.username FROM admin_logs l LEFT JOIN users u ON u.id = l.user_id';
    // $params stores query parameters matching the active filters.
    $params = [];
    // $where stores filter fragments that are combined after validation.
    $where = [];
    // $statuses stores the known workflow states.
    $statuses = admin_log_status_options();
    if ($status !== null && isset($statuses[$status])) {
        $where[] = 'l.status = ?';
        $params[] = $status;
    }
    if (admin_log_column_exists('category') && !empty($filters['category']) && isset(admin_log_category_options()[(string) $filters['category']])) {
        $where[] = 'l.category = ?';
        $params[] = (string) $filters['category'];
    }
    if (admin_log_column_exists('severity') && !empty($filters['severity']) && isset(admin_log_severity_options()[(string) $filters['severity']])) {
        $where[] = 'l.severity = ?';
        $params[] = (string) $filters['severity'];
    }
    if (!empty($filters['q'])) {
        // $searchColumns stores searchable text columns that are always present on the legacy table.
        $searchColumns = ['l.event_key LIKE ?', 'l.message LIKE ?', 'l.context_json LIKE ?'];
        $params[] = '%' . (string) $filters['q'] . '%';
        $params[] = '%' . (string) $filters['q'] . '%';
        $params[] = '%' . (string) $filters['q'] . '%';
        foreach (['request_id', 'route_name', 'subject_type'] as $optionalSearchColumn) {
            if (!admin_log_column_exists($optionalSearchColumn)) {
                continue;
            }
            $searchColumns[] = 'l.' . $optionalSearchColumn . ' LIKE ?';
            $params[] = '%' . (string) $filters['q'] . '%';
        }
        $where[] = '(' . implode(' OR ', $searchColumns) . ')';
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    // $timeSort stores the direction used for chronological sorting.
    $timeSort = strtolower((string) ($filters['time_sort'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $idSort = $timeSort === 'ASC' ? 'ASC' : 'DESC';
    $sql .= ' ORDER BY l.created_at ' . $timeSort . ', l.id ' . $idSort . ' LIMIT ' . max(1, min(300, $limit));
    // $stmt stores the prepared filtered admin log query.
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Return every admin log row available to the logs subsystem for full exports.
 */
function admin_log_export_rows(): array
{
    if (!admin_log_schema_ready()) {
        return [];
    }
    // $stmt stores the complete admin log export query. No UI filters or display limits are applied here.
    $stmt = db()->prepare('SELECT l.*, u.username FROM admin_logs l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.created_at ASC, l.id ASC');
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Return the stable admin log export column order used by CSV and JSON metadata.
 */
function admin_log_export_columns(): array
{
    return [
        'id',
        'created_at',
        'status',
        'status_label',
        'status_updated_at',
        'level',
        'severity',
        'category',
        'event_key',
        'message',
        'user_id',
        'username',
        'subject_type',
        'subject_id',
        'request_id',
        'route_name',
        'resolved_at',
        'resolution_note',
        'context',
        'context_json',
    ];
}

/**
 * Normalize one admin log database row for reusable export payloads.
 */
function admin_log_export_normalize_entry(array $entry): array
{
    // $context stores decoded structured data while context_json preserves the original serialized value.
    $context = admin_log_context_array($entry);
    return [
        'id' => isset($entry['id']) ? (int) $entry['id'] : null,
        'created_at' => (string) ($entry['created_at'] ?? ''),
        'status' => (string) ($entry['status'] ?? 'todo'),
        'status_label' => admin_log_status_label((string) ($entry['status'] ?? 'todo')),
        'status_updated_at' => (string) ($entry['status_updated_at'] ?? ''),
        'level' => (string) ($entry['level'] ?? ''),
        'severity' => (string) ($entry['severity'] ?? ($entry['level'] ?? '')),
        'category' => (string) ($entry['category'] ?? 'other'),
        'event_key' => (string) ($entry['event_key'] ?? ''),
        'message' => (string) ($entry['message'] ?? ''),
        'user_id' => isset($entry['user_id']) && $entry['user_id'] !== null ? (int) $entry['user_id'] : null,
        'username' => (string) ($entry['username'] ?? ''),
        'subject_type' => (string) ($entry['subject_type'] ?? ''),
        'subject_id' => isset($entry['subject_id']) && $entry['subject_id'] !== null ? (int) $entry['subject_id'] : null,
        'request_id' => (string) ($entry['request_id'] ?? ''),
        'route_name' => (string) ($entry['route_name'] ?? ''),
        'resolved_at' => (string) ($entry['resolved_at'] ?? ''),
        'resolution_note' => (string) ($entry['resolution_note'] ?? ''),
        'context' => $context,
        'context_json' => isset($entry['context_json']) && $entry['context_json'] !== null ? (string) $entry['context_json'] : '',
    ];
}

/**
 * Build the reusable JSON-ready admin log export payload.
 */
function admin_log_export_payload(?array $rows = null): array
{
    // $rows may be supplied by a ZIP export so the database is read only once.
    $rows = $rows ?? admin_log_export_rows();
    return [
        'schema' => 'php-gallery-admin-logs-v1',
        'generated_at' => now_sql(),
        'row_count' => count($rows),
        'columns' => admin_log_export_columns(),
        'logs' => array_map('admin_log_export_normalize_entry', $rows),
    ];
}

/**
 * Encode the reusable admin log JSON export payload.
 */
function admin_log_export_json(?array $payload = null): string
{
    $payload = $payload ?? admin_log_export_payload();
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Unable to encode admin log JSON export: ' . json_last_error_msg());
    }
    return $json . "\n";
}

/**
 * Build a CSV export from the same normalized payload used for JSON.
 */
function admin_log_export_csv(array $payload): string
{
    // $handle stores CSV content in memory because the export is immediately inserted into a ZIP archive.
    $handle = fopen('php://temp', 'w+');
    if ($handle === false) {
        throw new RuntimeException('Unable to open temporary CSV stream.');
    }
    $columns = admin_log_export_columns();
    fputcsv($handle, $columns, ',', '"', '');
    foreach (($payload['logs'] ?? []) as $entry) {
        $row = [];
        foreach ($columns as $column) {
            $value = $entry[$column] ?? '';
            if (is_array($value)) {
                // CSV cannot hold nested arrays directly, so structured values are faithfully JSON-encoded in one cell.
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $row[] = $encoded === false ? '' : $encoded;
                continue;
            }
            $row[] = $value;
        }
        // Disable PHP's legacy CSV escape character so JSON backslashes remain RFC-4180-safe.
        fputcsv($handle, $row, ',', '"', '');
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);
    if ($csv === false) {
        throw new RuntimeException('Unable to read generated CSV export.');
    }
    return $csv;
}

/**
 * Create a ZIP archive containing CSV and JSON exports of the same admin log payload.
 */
function admin_log_create_export_zip(string $filePath, array $payload): void
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('ZipArchive is not available.');
    }
    $zip = new ZipArchive();
    if ($zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create admin log ZIP export.');
    }
    $zip->addFromString('logs.csv', admin_log_export_csv($payload));
    $zip->addFromString('logs.json', admin_log_export_json($payload));
    $zip->close();
    if (!is_file($filePath)) {
        throw new RuntimeException('Unable to finalize admin log ZIP export.');
    }
}

/**
 * Stream a generated admin log ZIP export to the browser.
 */
function admin_log_send_export_zip(string $filePath, string $downloadName): never
{
    if (!is_file($filePath)) {
        http_response_code(404);
        exit('Admin log export not found.');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
    header('Content-Length: ' . filesize($filePath));
    header('X-Content-Type-Options: nosniff');
    readfile($filePath);
    @unlink($filePath);
    exit;
}

/**
 * Return a temporary path for an admin log ZIP export.
 */
function admin_log_export_temp_path(): string
{
    $filePath = tempnam(sys_get_temp_dir(), 'php-gallery-admin-logs-');
    if ($filePath === false) {
        throw new RuntimeException('Unable to allocate temporary admin log export file.');
    }
    return $filePath;
}

/**
 * Return a safe downloadable filename for a complete admin log export.
 */
function admin_log_export_zip_filename(): string
{
    return 'php-gallery-admin-logs-' . date('Ymd-His') . '.zip';
}
/**
 * Return one admin log entry with user information for detail display or export.
 */
function admin_log_find(int $logId): ?array
{
    if (!admin_log_schema_ready()) {
        return null;
    }
    // $stmt stores the single-entry admin log lookup query.
    $stmt = db()->prepare('SELECT l.*, u.username FROM admin_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.id = ? LIMIT 1');
    $stmt->execute([$logId]);
    // $entry stores the fetched row or false when the identifier no longer exists.
    $entry = $stmt->fetch();
    return is_array($entry) ? $entry : null;
}

/**
 * Decode the structured context stored on an admin log entry.
 */
function admin_log_context_array(array $entry): array
{
    if (empty($entry['context_json'])) {
        return [];
    }
    // $decoded stores the parsed diagnostic context for safe display.
    $decoded = json_decode((string) $entry['context_json'], true);
    return is_array($decoded) ? $decoded : [
        'raw_context_json' => (string) $entry['context_json'],
        'json_error' => json_last_error_msg(),
    ];
}

/**
 * Build a deterministic text export for one admin log entry.
 */
function admin_log_export_text(array $entry): string
{
    // $context stores structured event data, including updater diagnostics when present.
    $context = admin_log_context_array($entry);
    // $lines stores the text report line by line to keep formatting predictable.
    $lines = [
        admin_log_english_t('admin.logs.export.title', 'PHP Gallery admin log event'),
        '',
        'ID: ' . (string) ($entry['id'] ?? ''),
        admin_log_english_t('admin.logs.export.created_at', 'Created at: {value}', ['value' => (string) ($entry['created_at'] ?? '')]),
        admin_log_english_t('admin.logs.export.status', 'Status: {value}', ['value' => admin_log_status_label((string) ($entry['status'] ?? 'todo'))]),
        admin_log_english_t('admin.logs.export.level', 'Level: {value}', ['value' => (string) ($entry['level'] ?? '')]),
        admin_log_english_t('admin.logs.export.severity', 'Severity: {value}', ['value' => (string) ($entry['severity'] ?? ($entry['level'] ?? ''))]),
        admin_log_english_t('admin.logs.export.category', 'Category: {value}', ['value' => (string) ($entry['category'] ?? 'other')]),
        admin_log_english_t('admin.logs.export.event_key', 'Event key: {value}', ['value' => (string) ($entry['event_key'] ?? '')]),
        admin_log_english_t('admin.logs.export.message', 'Message: {value}', ['value' => (string) ($entry['message'] ?? '')]),
        admin_log_english_t('admin.logs.export.admin_user', 'Admin user: {value}', ['value' => (string) ($entry['username'] ?? '')]),
        admin_log_english_t('admin.logs.export.subject', 'Subject: {value}', ['value' => trim((string) ($entry['subject_type'] ?? '') . ' ' . (string) ($entry['subject_id'] ?? ''))]),
        admin_log_english_t('admin.logs.export.request_id', 'Request ID: {value}', ['value' => (string) ($entry['request_id'] ?? '')]),
        admin_log_english_t('admin.logs.export.route', 'Route: {value}', ['value' => (string) ($entry['route_name'] ?? '')]),
        admin_log_english_t('admin.logs.export.resolved_at', 'Resolved at: {value}', ['value' => (string) ($entry['resolved_at'] ?? '')]),
        admin_log_english_t('admin.logs.export.resolution_note', 'Resolution note: {value}', ['value' => (string) ($entry['resolution_note'] ?? '')]),
        '',
        admin_log_english_t('admin.logs.export.context', 'Context:'),
        $context ? json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : admin_log_english_t('admin.logs.export.none', '(none)'),
        '',
    ];
    return implode("\n", $lines);
}

/**
 * Handles admin log update status logic for the gallery application.
 * @param mixed $logId Input used by this operation.
 * @param mixed $status Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function admin_log_update_status(int $logId, string $status): void
{
    // $statuses stores an intermediate value used by the surrounding gallery workflow.
    $statuses = admin_log_status_options();
    if (!isset($statuses[$status])) {
        throw new RuntimeException('Invalid log status.');
    }
    if (!admin_log_schema_ready() && !ensure_admin_log_status_schema()) {
        throw new RuntimeException('Admin log schema is not ready.');
    }
    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    if ($status === 'done' && admin_log_column_exists('resolved_at')) {
        // $stmt stores the workflow status update query including the resolved timestamp.
        $stmt = db()->prepare('UPDATE admin_logs SET status = ?, status_updated_at = ?, resolved_at = COALESCE(resolved_at, ?) WHERE id = ?');
        $stmt->execute([$status, now_sql(), now_sql(), $logId]);
    } else {
        // $stmt stores the workflow status update query.
        $stmt = db()->prepare('UPDATE admin_logs SET status = ?, status_updated_at = ? WHERE id = ?');
        $stmt->execute([$status, now_sql(), $logId]);
    }
    // $check stores an intermediate value used by the surrounding gallery workflow.
    $check = db()->prepare('SELECT status FROM admin_logs WHERE id = ?');
    $check->execute([$logId]);
    if ($check->fetchColumn() !== $status) {
        throw new RuntimeException('Admin log entry was not updated.');
    }
}
