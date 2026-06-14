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

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;
use RuntimeException;
use ZipArchive;
use function Gallery\Core\current_user;
use function Gallery\Core\db;
use function Gallery\Core\now_sql;
use function Gallery\Services\translation_interpolate;
use function Gallery\Services\translation_load_language;

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
    /**
     * Handle admin log english t.
     *
     * Part of the related application service.
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
 * Handle admin log schema ready.
 *
 * Part of the related application service.
 *
 * @return bool True when the condition matches.
 */
function admin_log_schema_ready(): bool
{
    try {
        // $stmt stores an intermediate value used by the surrounding gallery workflow.
        $stmt = db()->query("SHOW COLUMNS FROM admin_logs LIKE 'status'");
        return $stmt && (bool) $stmt->fetch();
    } catch (Throwable) {
        return false;
    }
}


/**
 * Return whether an optional admin log column is available.
 *
 * @param string $columnName Column name value.
 * @return bool True when the condition matches.
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
 *
 * @return array Structured result data for the caller.
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
 *
 * @return array Structured result data for the caller.
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
 *
 * @return string Text result for the caller.
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
 *
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
    } catch (Throwable) {
        return false;
    }
}

/**
 * Handles admin log event logic for the gallery application.
 *
 * @param mixed $level Input used by this operation.
 * @param mixed $eventKey Input used by this operation.
 * @param mixed $message Input used by this operation.
 * @param mixed $context Input used by this operation.
 * @param array $options Optional behavior flags.
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
 *
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
 *
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
 *
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
 * Return reusable SQL fragments for admin log list filters.
 *
 * @param ?string $status Status value.
 * @param array $filters Filters value.
 * @return array Structured result data for the caller.
 */
function admin_log_filter_sql(?string $status = null, array $filters = []): array
{
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
    if (admin_log_column_exists('severity')) {
        // $selectedSeverities stores the new multi-select filter. The legacy single
        // severity key is still accepted for older links and saved browser history.
        $selectedSeverities = [];
        if (isset($filters['severities']) && is_array($filters['severities'])) {
            $selectedSeverities = $filters['severities'];
        } elseif (!empty($filters['severity'])) {
            $selectedSeverities = [(string) $filters['severity']];
        }

        // $validSeverities stores only supported values in stable option order.
        $validSeverities = [];
        foreach (array_keys(admin_log_severity_options()) as $severity) {
            if (in_array($severity, $selectedSeverities, true)) {
                $validSeverities[] = $severity;
            }
        }

        if ($validSeverities !== []) {
            $where[] = 'l.severity IN (' . implode(', ', array_fill(0, count($validSeverities), '?')) . ')';
            foreach ($validSeverities as $severity) {
                $params[] = $severity;
            }
        }
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

    return [
        'where_sql' => $where ? ' WHERE ' . implode(' AND ', $where) : '',
        'params' => $params,
    ];
}

/**
 * Return the normalized SQL sort direction for admin log time ordering.
 *
 * @param array $filters Filters value.
 * @return string Text result for the caller.
 */
function admin_log_time_sort_sql(array $filters): string
{
    return strtolower((string) ($filters['time_sort'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
}

/**
 * Return columns used for default grouped admin log rows.
 *
 * @return array Structured result data for the caller.
 */
function admin_log_group_columns(): array
{
    // Grouping by event identity plus severity keeps high-volume progress logs compact
    // without merging errors and informational events into the same row.
    $columns = ['event_key', 'level'];
    foreach (['category', 'severity'] as $optionalColumn) {
        if (admin_log_column_exists($optionalColumn)) {
            $columns[] = $optionalColumn;
        }
    }
    return $columns;
}

/**
 * Return the SQL expression used to identify one grouped admin log bucket.
 *
 * @param string $tableAlias Table alias value.
 * @return string Text result for the caller.
 */
function admin_log_group_hash_sql(string $tableAlias = 'l'): string
{
    // $parts stores stable text fragments for the grouped hash expression.
    $parts = [];
    foreach (admin_log_group_columns() as $column) {
        $parts[] = 'COALESCE(' . $tableAlias . '.' . $column . ", '')";
    }
    return 'SHA2(CONCAT_WS(\'|\', ' . implode(', ', $parts) . '), 256)';
}

/**
 * Handles admin log list logic for the gallery application.
 *
 * @param mixed $status Input used by this operation.
 * @param mixed $limit Input used by this operation.
 * @param array $filters Filters value.
 * @param int $offset Starting offset.
 * @return mixed Result produced by this operation.
 */
function admin_log_list(?string $status = null, int $limit = 100, array $filters = [], int $offset = 0): array
{
    if (!admin_log_schema_ready()) {
        return [];
    }
    // $filterSql stores reusable WHERE fragments and bound parameters.
    $filterSql = admin_log_filter_sql($status, $filters);
    // $sql stores the filtered admin log query.
    $sql = 'SELECT l.*, u.username, 1 AS group_count, ' . admin_log_group_hash_sql('l') . ' AS group_hash, l.created_at AS first_created_at, l.created_at AS latest_created_at FROM admin_logs l LEFT JOIN users u ON u.id = l.user_id';
    $sql .= $filterSql['where_sql'];
    // $timeSort stores the direction used for chronological sorting.
    $timeSort = admin_log_time_sort_sql($filters);
    $idSort = $timeSort === 'ASC' ? 'ASC' : 'DESC';
    $sql .= ' ORDER BY l.created_at ' . $timeSort . ', l.id ' . $idSort . ' LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset);
    // $stmt stores the prepared filtered admin log query.
    $stmt = db()->prepare($sql);
    $stmt->execute($filterSql['params']);
    return $stmt->fetchAll();
}

/**
 * Return the number of admin log rows matching the active filters.
 *
 * @param ?string $status Status value.
 * @param array $filters Filters value.
 * @return int Integer result for the caller.
 */
function admin_log_count(?string $status = null, array $filters = []): int
{
    if (!admin_log_schema_ready()) {
        return 0;
    }
    // $filterSql stores reusable WHERE fragments and bound parameters.
    $filterSql = admin_log_filter_sql($status, $filters);
    // $stmt stores the filtered count query.
    $stmt = db()->prepare('SELECT COUNT(*) FROM admin_logs l' . $filterSql['where_sql']);
    $stmt->execute($filterSql['params']);
    return max(0, (int) $stmt->fetchColumn());
}

/**
 * Return grouped admin log rows matching the active filters.
 *
 * @param ?string $status Status value.
 * @param int $limit Maximum number of items.
 * @param array $filters Filters value.
 * @param int $offset Starting offset.
 * @return array Structured result data for the caller.
 */
function admin_log_grouped_list(?string $status = null, int $limit = 100, array $filters = [], int $offset = 0): array
{
    if (!admin_log_schema_ready()) {
        return [];
    }
    // $filterSql stores reusable WHERE fragments and bound parameters.
    $filterSql = admin_log_filter_sql($status, $filters);
    // $timeSort stores the direction used for chronological sorting.
    $timeSort = admin_log_time_sort_sql($filters);
    $idSort = $timeSort === 'ASC' ? 'ASC' : 'DESC';
    // $groupBy stores safe column references used to collapse repeated operational events.
    $groupBy = array_map(static fn (string $column): string => 'l.' . $column, admin_log_group_columns());
    // $representativeIdSql stores the row id shown for the grouped entry.
    $representativeIdSql = $timeSort === 'ASC' ? 'MIN(l.id)' : 'MAX(l.id)';
    // $sortColumn stores the aggregate timestamp matching the selected chronological direction.
    $sortColumn = $timeSort === 'ASC' ? 'grouped.first_created_at' : 'grouped.latest_created_at';
    // $groupSql stores the grouped subquery so pagination applies after grouping.
    $groupSql = 'SELECT ' . $representativeIdSql . ' AS representative_id, ' . admin_log_group_hash_sql('l') . ' AS group_hash, COUNT(*) AS group_count, MIN(l.created_at) AS first_created_at, MAX(l.created_at) AS latest_created_at FROM admin_logs l'
        . $filterSql['where_sql']
        . ' GROUP BY ' . implode(', ', $groupBy);
    // $sql stores the grouped admin log query with the representative row joined back.
    $sql = 'SELECT l.*, u.username, grouped.group_count, grouped.group_hash, grouped.first_created_at, grouped.latest_created_at FROM (' . $groupSql . ') grouped'
        . ' INNER JOIN admin_logs l ON l.id = grouped.representative_id'
        . ' LEFT JOIN users u ON u.id = l.user_id'
        . ' ORDER BY ' . $sortColumn . ' ' . $timeSort . ', grouped.representative_id ' . $idSort
        . ' LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset);
    // $stmt stores the prepared grouped admin log query.
    $stmt = db()->prepare($sql);
    $stmt->execute($filterSql['params']);
    return admin_log_attach_group_members($stmt->fetchAll());
}

/**
 * Return the number of grouped admin log rows matching the active filters.
 *
 * @param ?string $status Status value.
 * @param array $filters Filters value.
 * @return int Integer result for the caller.
 */
function admin_log_grouped_count(?string $status = null, array $filters = []): int
{
    if (!admin_log_schema_ready()) {
        return 0;
    }
    // $filterSql stores reusable WHERE fragments and bound parameters.
    $filterSql = admin_log_filter_sql($status, $filters);
    // $groupBy stores safe column references used to collapse repeated operational events.
    $groupBy = array_map(static fn (string $column): string => 'l.' . $column, admin_log_group_columns());
    // $sql stores a grouped count query. The outer count measures visible grouped rows.
    $sql = 'SELECT COUNT(*) FROM (SELECT 1 FROM admin_logs l' . $filterSql['where_sql'] . ' GROUP BY ' . implode(', ', $groupBy) . ') grouped_count';
    // $stmt stores the prepared grouped count query.
    $stmt = db()->prepare($sql);
    $stmt->execute($filterSql['params']);
    return max(0, (int) $stmt->fetchColumn());
}

/**
 * Return every log row that belongs to the requested grouped hashes.
 *
 * @param array $groupHashes Group hashes value.
 * @return array Structured result data for the caller.
 */
function admin_log_group_member_rows(array $groupHashes): array
{
    if ($groupHashes === [] || !admin_log_schema_ready()) {
        return [];
    }
    // $normalizedHashes stores distinct non-empty hash values.
    $normalizedHashes = [];
    foreach ($groupHashes as $groupHash) {
        $groupHash = trim((string) $groupHash);
        if ($groupHash !== '') {
            $normalizedHashes[$groupHash] = true;
        }
    }
    if ($normalizedHashes === []) {
        return [];
    }
    // $hashes stores the final ordered group hashes used by the query.
    $hashes = array_keys($normalizedHashes);
    // $hashSql stores the grouping expression repeated in the SELECT and WHERE clauses.
    $hashSql = admin_log_group_hash_sql('l');
    // $sql stores the grouped member fetch query for every visible grouped row.
    $sql = 'SELECT l.*, u.username, ' . $hashSql . ' AS group_hash FROM admin_logs l'
        . ' LEFT JOIN users u ON u.id = l.user_id'
        . ' WHERE ' . $hashSql . ' IN (' . implode(', ', array_fill(0, count($hashes), '?')) . ')'
        . ' ORDER BY l.created_at DESC, l.id DESC';
    // $stmt stores the prepared grouped member query.
    $stmt = db()->prepare($sql);
    $stmt->execute($hashes);
    return $stmt->fetchAll();
}

/**
 * Attach grouped member rows to grouped summary entries.
 *
 * @param array $logs Logs value.
 * @return array Structured result data for the caller.
 */
function admin_log_attach_group_members(array $logs): array
{
    // $groupHashes stores visible grouped hashes that need a member listing.
    $groupHashes = [];
    foreach ($logs as $entry) {
        if ((int) ($entry['group_count'] ?? 1) > 1 && !empty($entry['group_hash'])) {
            $groupHashes[] = (string) $entry['group_hash'];
        }
    }
    if ($groupHashes === []) {
        return $logs;
    }
    // $groupMembers stores fetched member rows bucketed by grouped hash.
    $groupMembers = [];
    foreach (admin_log_group_member_rows($groupHashes) as $member) {
        $groupHash = (string) ($member['group_hash'] ?? '');
        if ($groupHash === '') {
            continue;
        }
        if (!isset($groupMembers[$groupHash])) {
            $groupMembers[$groupHash] = [];
        }
        $groupMembers[$groupHash][] = $member;
    }
    foreach ($logs as &$entry) {
        $groupHash = (string) ($entry['group_hash'] ?? '');
        $entry['group_members'] = $groupMembers[$groupHash] ?? [];
    }
    unset($entry);
    return $logs;
}

/**
 * Return every admin log row available to the logs subsystem for full exports.
 *
 * @return array Structured result data for the caller.
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
 *
 * @return array Structured result data for the caller.
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
 *
 * @param array $entry Entry value.
 * @return array Structured result data for the caller.
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
 *
 * @param ?array $rows Rows to process.
 * @return array Structured result data for the caller.
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
 *
 * @param ?array $payload Payload value.
 * @return string Text result for the caller.
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
 *
 * @param array $payload Payload value.
 * @return string Text result for the caller.
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
 *
 * @param string $filePath File path filesystem path.
 * @param array $payload Payload value.
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
 *
 * @param string $filePath File path filesystem path.
 * @param string $downloadName Download name value.
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
 *
 * @return string Text result for the caller.
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
 *
 * @return string Text result for the caller.
 */
function admin_log_export_zip_filename(): string
{
    return 'php-gallery-admin-logs-' . date('Ymd-His') . '.zip';
}
/**
 * Return one admin log entry with user information for detail display or export.
 *
 * @param int $logId Log id identifier.
 * @return ?array Structured result data for the caller.
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
 *
 * @param array $entry Entry value.
 * @return array Structured result data for the caller.
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
 *
 * @param array $entry Entry value.
 * @return string Text result for the caller.
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
 * Build a plain-text diagnostic export for a grouped admin log summary.
 *
 * @param array $entry Entry value.
 * @param array $groupMembers Group members value.
 * @return string Text result for the caller.
 */
function admin_log_export_group_text(array $entry, array $groupMembers): string
{
    $groupCount = count($groupMembers);
    $lines = [
        admin_log_english_t('admin.logs.export.title', 'PHP Gallery admin log event'),
        str_repeat('=', 60),
        admin_log_english_t('admin.logs.export.event_key', 'Event key: {value}', ['value' => (string) ($entry['event_key'] ?? '')]),
        admin_log_english_t('admin.logs.group_count', 'Grouped entries') . ': ' . $groupCount,
        admin_log_english_t('admin.logs.first_seen', 'First seen') . ': ' . (string) ($entry['first_created_at'] ?? $entry['created_at'] ?? ''),
        admin_log_english_t('admin.logs.latest_seen', 'Latest seen') . ': ' . (string) ($entry['latest_created_at'] ?? $entry['created_at'] ?? ''),
        '',
    ];

    foreach ($groupMembers as $index => $member) {
        $lines[] = '[' . ($index + 1) . '/' . $groupCount . ']';
        $lines[] = admin_log_export_text($member);
    }

    return implode("\n", $lines) . "\n";
}

/**
 * Handles admin log update status logic for the gallery application.
 *
 * @param string $whereSql Where sql value.
 * @param array $whereParams Where params value.
 * @param mixed $status Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function admin_log_update_status_where(string $whereSql, array $whereParams, string $status): int
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
        $stmt = db()->prepare('UPDATE admin_logs SET status = ?, status_updated_at = ?, resolved_at = COALESCE(resolved_at, ?) WHERE ' . $whereSql);
        $stmt->execute(array_merge([$status, now_sql(), now_sql()], $whereParams));
    } else {
        // $stmt stores the workflow status update query.
        $stmt = db()->prepare('UPDATE admin_logs SET status = ?, status_updated_at = ? WHERE ' . $whereSql);
        $stmt->execute(array_merge([$status, now_sql()], $whereParams));
    }
    return (int) $stmt->rowCount();
}

/**
 * Handles admin log update status logic for the gallery application.
 *
 * @param mixed $logId Input used by this operation.
 * @param mixed $status Input used by this operation.
 */
function admin_log_update_status(int $logId, string $status): void
{
    if (admin_log_update_status_where('id = ?', [$logId], $status) <= 0) {
        throw new RuntimeException('Admin log entry was not updated.');
    }
    // $check stores an intermediate value used by the surrounding gallery workflow.
    $check = db()->prepare('SELECT status FROM admin_logs WHERE id = ?');
    $check->execute([$logId]);
    if ($check->fetchColumn() !== $status) {
        throw new RuntimeException('Admin log entry was not updated.');
    }
}

/**
 * Update every admin log row that belongs to one grouped hash.
 *
 * @param string $groupHash Group hash value.
 * @param string $status Status value.
 * @return int Integer result for the caller.
 */
function admin_log_update_group_status(string $groupHash, string $status): int
{
    $groupHash = trim($groupHash);
    if ($groupHash === '') {
        throw new RuntimeException('Grouped admin log selection is invalid.');
    }
    $hashSql = admin_log_group_hash_sql('admin_logs');
    $updatedRows = admin_log_update_status_where($hashSql . ' = ?', [$groupHash], $status);
    if ($updatedRows <= 0) {
        throw new RuntimeException('Grouped admin log rows were not updated.');
    }
    return $updatedRows;
}
