<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/admin_logs.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns Admin log schema inspection, persistence, filtering, grouping, and status updates.
 *
 * Responsibilities:
 *   - Inspect legacy and current admin_logs schema capabilities
 *   - Insert only fields supported by the installed schema
 *   - Build and execute filtered/grouped log queries from semantic filter input
 *   - Keep export reads and workflow status mutations inside the data-access layer
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
 *   - Callers supply semantic filters and normalized values, never SQL fragments.
 *
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Models;

use Throwable;
use function Gallery\Core\db;

/**
 * Return all currently available Admin log columns keyed by column name.
 *
 * @return array<string,bool> Available columns.
 */
function admin_log_model_columns(): array
{
    static $columns = null;
    if ($columns !== null) {
        return $columns;
    }
    $columns = [];
    try {
        $stmt = db()->query('SHOW COLUMNS FROM admin_logs');
        foreach ($stmt->fetchAll() as $column) {
            $name = (string) ($column['Field'] ?? '');
            if ($name !== '') {
                $columns[$name] = true;
            }
        }
    } catch (Throwable) {
        $columns = [];
    }
    return $columns;
}

/** Return whether one Admin log column is available. */
function admin_log_model_column_exists(string $columnName): bool
{
    return isset(admin_log_model_columns()[$columnName]);
}

/** Return whether the Admin log workflow schema is ready. */
function admin_log_model_schema_ready(): bool
{
    return admin_log_model_column_exists('status');
}

/**
 * Ensure the historical Admin log status columns/index exist.
 *
 * This compatibility repair remains intentionally explicit because older
 * installations may reach the logs screen before the corresponding migration.
 */
function admin_log_model_ensure_status_schema(): bool
{
    try {
        $tableExists = db()->query("SHOW TABLES LIKE 'admin_logs'");
        if (!$tableExists || !$tableExists->fetch()) {
            return false;
        }
        $statusColumn = db()->query("SHOW COLUMNS FROM admin_logs LIKE 'status'");
        if (!$statusColumn || !$statusColumn->fetch()) {
            db()->exec("ALTER TABLE admin_logs ADD COLUMN status ENUM('todo','doing','done','waiting') NOT NULL DEFAULT 'todo' AFTER level");
        }
        $statusUpdatedAtColumn = db()->query("SHOW COLUMNS FROM admin_logs LIKE 'status_updated_at'");
        if (!$statusUpdatedAtColumn || !$statusUpdatedAtColumn->fetch()) {
            db()->exec("ALTER TABLE admin_logs ADD COLUMN status_updated_at DATETIME NULL AFTER status");
        }
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
 * Best-effort insert of an Admin log row using only available columns.
 *
 * @param array<string,mixed> $values Candidate values keyed by admin_logs column.
 * @return bool True when an insert was executed successfully.
 */
function admin_log_model_insert_available(array $values): bool
{
    try {
        $columnsAvailable = admin_log_model_columns();
        if ($columnsAvailable === []) {
            return false;
        }

        $insertColumns = [];
        $placeholders = [];
        $params = [];
        foreach ($values as $column => $value) {
            if (!isset($columnsAvailable[$column])) {
                continue;
            }
            $insertColumns[] = $column;
            $placeholders[] = '?';
            $params[] = $value;
        }
        if ($insertColumns === []) {
            return false;
        }

        $stmt = db()->prepare('INSERT INTO admin_logs (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $placeholders) . ')');
        return $stmt->execute($params);
    } catch (Throwable) {
        return false;
    }
}

/** Return columns used for grouped Admin log rows. */
function admin_log_model_group_columns(): array
{
    $columns = ['event_key', 'level'];
    foreach (['category', 'severity'] as $optionalColumn) {
        if (admin_log_model_column_exists($optionalColumn)) {
            $columns[] = $optionalColumn;
        }
    }
    return $columns;
}

/** Return the SQL expression used to identify one grouped Admin log bucket. */
function admin_log_model_group_hash_sql(string $tableAlias = 'l'): string
{
    $parts = [];
    foreach (admin_log_model_group_columns() as $column) {
        $parts[] = 'COALESCE(' . $tableAlias . '.' . $column . ", '')";
    }
    return 'SHA2(CONCAT_WS(\'|\', ' . implode(', ', $parts) . '), 256)';
}

/** Return the normalized SQL sort direction for Admin log time ordering. */
function admin_log_model_time_sort(array $filters): string
{
    return strtolower((string) ($filters['time_sort'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
}

/**
 * Build a model-owned filter clause from semantic Admin log filters.
 *
 * @param ?string $status Requested workflow status.
 * @param array<string,mixed> $filters Semantic filter values.
 * @param array<int,string> $validStatuses Allowed workflow statuses.
 * @param array<int,string> $validCategories Allowed categories.
 * @param array<int,string> $validSeverities Allowed severities.
 * @return array{where_sql:string,params:array<int,mixed>}
 */
function admin_log_model_filter_sql(?string $status, array $filters, array $validStatuses, array $validCategories, array $validSeverities): array
{
    $params = [];
    $where = [];
    if ($status !== null && in_array($status, $validStatuses, true)) {
        $where[] = 'l.status = ?';
        $params[] = $status;
    }
    $category = (string) ($filters['category'] ?? '');
    if (admin_log_model_column_exists('category') && $category !== '' && in_array($category, $validCategories, true)) {
        $where[] = 'l.category = ?';
        $params[] = $category;
    }
    if (admin_log_model_column_exists('severity')) {
        $selectedSeverities = [];
        if (isset($filters['severities']) && is_array($filters['severities'])) {
            $selectedSeverities = $filters['severities'];
        } elseif (!empty($filters['severity'])) {
            $selectedSeverities = [(string) $filters['severity']];
        }
        $normalized = [];
        foreach ($validSeverities as $severity) {
            if (in_array($severity, $selectedSeverities, true)) {
                $normalized[] = $severity;
            }
        }
        if ($normalized !== []) {
            $where[] = 'l.severity IN (' . implode(', ', array_fill(0, count($normalized), '?')) . ')';
            array_push($params, ...$normalized);
        }
    }
    $query = trim((string) ($filters['q'] ?? ''));
    if ($query !== '') {
        $searchColumns = ['l.event_key LIKE ?', 'l.message LIKE ?', 'l.context_json LIKE ?'];
        $needle = '%' . $query . '%';
        $params[] = $needle;
        $params[] = $needle;
        $params[] = $needle;
        foreach (['request_id', 'route_name', 'subject_type'] as $column) {
            if (!admin_log_model_column_exists($column)) {
                continue;
            }
            $searchColumns[] = 'l.' . $column . ' LIKE ?';
            $params[] = $needle;
        }
        $where[] = '(' . implode(' OR ', $searchColumns) . ')';
    }
    return [
        'where_sql' => $where ? ' WHERE ' . implode(' AND ', $where) : '',
        'params' => $params,
    ];
}

/** Return recent Admin log rows with usernames. */
function admin_log_model_recent(int $limit): array
{
    $safeLimit = max(1, min(50, $limit));
    $stmt = db()->prepare('SELECT l.*, u.username FROM admin_logs l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.created_at DESC, l.id DESC LIMIT ' . $safeLimit);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Return filtered Admin log rows. */
function admin_log_model_list(?string $status, int $limit, array $filters, int $offset, array $validStatuses, array $validCategories, array $validSeverities): array
{
    $filter = admin_log_model_filter_sql($status, $filters, $validStatuses, $validCategories, $validSeverities);
    $timeSort = admin_log_model_time_sort($filters);
    $idSort = $timeSort === 'ASC' ? 'ASC' : 'DESC';
    $sql = 'SELECT l.*, u.username, 1 AS group_count, ' . admin_log_model_group_hash_sql('l') . ' AS group_hash, l.created_at AS first_created_at, l.created_at AS latest_created_at FROM admin_logs l LEFT JOIN users u ON u.id = l.user_id'
        . $filter['where_sql']
        . ' ORDER BY l.created_at ' . $timeSort . ', l.id ' . $idSort
        . ' LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset);
    $stmt = db()->prepare($sql);
    $stmt->execute($filter['params']);
    return $stmt->fetchAll();
}

/** Return the number of filtered Admin log rows. */
function admin_log_model_count(?string $status, array $filters, array $validStatuses, array $validCategories, array $validSeverities): int
{
    $filter = admin_log_model_filter_sql($status, $filters, $validStatuses, $validCategories, $validSeverities);
    $stmt = db()->prepare('SELECT COUNT(*) FROM admin_logs l' . $filter['where_sql']);
    $stmt->execute($filter['params']);
    return max(0, (int) $stmt->fetchColumn());
}

/** Return grouped Admin log rows. */
function admin_log_model_grouped_list(?string $status, int $limit, array $filters, int $offset, array $validStatuses, array $validCategories, array $validSeverities): array
{
    $filter = admin_log_model_filter_sql($status, $filters, $validStatuses, $validCategories, $validSeverities);
    $timeSort = admin_log_model_time_sort($filters);
    $idSort = $timeSort === 'ASC' ? 'ASC' : 'DESC';
    $groupBy = array_map(static fn (string $column): string => 'l.' . $column, admin_log_model_group_columns());
    $representativeIdSql = $timeSort === 'ASC' ? 'MIN(l.id)' : 'MAX(l.id)';
    $sortColumn = $timeSort === 'ASC' ? 'grouped.first_created_at' : 'grouped.latest_created_at';
    $groupSql = 'SELECT ' . $representativeIdSql . ' AS representative_id, ' . admin_log_model_group_hash_sql('l') . ' AS group_hash, COUNT(*) AS group_count, MIN(l.created_at) AS first_created_at, MAX(l.created_at) AS latest_created_at FROM admin_logs l'
        . $filter['where_sql'] . ' GROUP BY ' . implode(', ', $groupBy);
    $sql = 'SELECT l.*, u.username, grouped.group_count, grouped.group_hash, grouped.first_created_at, grouped.latest_created_at FROM (' . $groupSql . ') grouped'
        . ' INNER JOIN admin_logs l ON l.id = grouped.representative_id'
        . ' LEFT JOIN users u ON u.id = l.user_id'
        . ' ORDER BY ' . $sortColumn . ' ' . $timeSort . ', grouped.representative_id ' . $idSort
        . ' LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset);
    $stmt = db()->prepare($sql);
    $stmt->execute($filter['params']);
    return $stmt->fetchAll();
}

/** Return the number of grouped Admin log rows. */
function admin_log_model_grouped_count(?string $status, array $filters, array $validStatuses, array $validCategories, array $validSeverities): int
{
    $filter = admin_log_model_filter_sql($status, $filters, $validStatuses, $validCategories, $validSeverities);
    $groupBy = array_map(static fn (string $column): string => 'l.' . $column, admin_log_model_group_columns());
    $sql = 'SELECT COUNT(*) FROM (SELECT 1 FROM admin_logs l' . $filter['where_sql'] . ' GROUP BY ' . implode(', ', $groupBy) . ') grouped_count';
    $stmt = db()->prepare($sql);
    $stmt->execute($filter['params']);
    return max(0, (int) $stmt->fetchColumn());
}

/** Build indexed predicates matching the group represented by one log row. */
function admin_log_model_group_member_filter(array $entry, string $tableAlias = 'l'): array
{
    $where = [];
    $params = [];
    foreach (admin_log_model_group_columns() as $column) {
        $where[] = $tableAlias . '.' . $column . ' = ?';
        $params[] = isset($entry[$column]) ? (string) $entry[$column] : '';
    }
    return ['where_sql' => implode(' AND ', $where), 'params' => $params];
}

/** Return one bounded page of raw rows represented by a grouped Admin log entry. */
function admin_log_model_group_member_page(array $entry, int $limit, int $offset): array
{
    $filter = admin_log_model_group_member_filter($entry, 'l');
    $stmt = db()->prepare(
        'SELECT l.*, u.username FROM admin_logs l LEFT JOIN users u ON u.id = l.user_id'
        . ' WHERE ' . $filter['where_sql']
        . ' ORDER BY l.created_at DESC, l.id DESC'
        . ' LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset)
    );
    $stmt->execute($filter['params']);
    return $stmt->fetchAll();
}

/** Return one descending keyset batch of grouped members for streaming exports. */
function admin_log_model_group_member_export_batch(array $entry, ?string $beforeCreatedAt, int $beforeId, int $limit): array
{
    $filter = admin_log_model_group_member_filter($entry, 'l');
    $whereSql = $filter['where_sql'];
    $params = $filter['params'];
    if ($beforeCreatedAt !== null && $beforeCreatedAt !== '' && $beforeId > 0) {
        $whereSql .= ' AND (l.created_at < ? OR (l.created_at = ? AND l.id < ?))';
        $params[] = $beforeCreatedAt;
        $params[] = $beforeCreatedAt;
        $params[] = $beforeId;
    }
    $stmt = db()->prepare(
        'SELECT l.*, u.username FROM admin_logs l LEFT JOIN users u ON u.id = l.user_id'
        . ' WHERE ' . $whereSql
        . ' ORDER BY l.created_at DESC, l.id DESC LIMIT ' . max(50, min(2000, $limit))
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Return aggregate timestamps and count for one grouped Admin log entry. */
function admin_log_model_group_member_summary(array $entry): array
{
    $filter = admin_log_model_group_member_filter($entry, 'l');
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS group_count, MIN(l.created_at) AS first_created_at, MAX(l.created_at) AS latest_created_at'
        . ' FROM admin_logs l WHERE ' . $filter['where_sql']
    );
    $stmt->execute($filter['params']);
    $summary = $stmt->fetch();
    if (!is_array($summary)) {
        return ['group_count' => 0, 'first_created_at' => '', 'latest_created_at' => ''];
    }
    return [
        'group_count' => max(0, (int) ($summary['group_count'] ?? 0)),
        'first_created_at' => (string) ($summary['first_created_at'] ?? ''),
        'latest_created_at' => (string) ($summary['latest_created_at'] ?? ''),
    ];
}

/** Return raw rows belonging to the requested grouped hashes. */
function admin_log_model_group_member_rows(array $groupHashes, int $limit): array
{
    $normalized = [];
    foreach ($groupHashes as $groupHash) {
        $groupHash = trim((string) $groupHash);
        if ($groupHash !== '') $normalized[$groupHash] = true;
    }
    $hashes = array_keys($normalized);
    if ($hashes === []) return [];
    $hashSql = admin_log_model_group_hash_sql('l');
    $sql = 'SELECT l.*, u.username, ' . $hashSql . ' AS group_hash FROM admin_logs l'
        . ' LEFT JOIN users u ON u.id = l.user_id'
        . ' WHERE ' . $hashSql . ' IN (' . implode(', ', array_fill(0, count($hashes), '?')) . ')'
        . ' ORDER BY l.created_at DESC, l.id DESC'
        . ' LIMIT ' . max(1, min(2000, $limit));
    $stmt = db()->prepare($sql);
    $stmt->execute($hashes);
    return $stmt->fetchAll();
}

/** Return every Admin log row for full exports. */
function admin_log_model_export_rows(): array
{
    $stmt = db()->prepare('SELECT l.*, u.username FROM admin_logs l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.created_at ASC, l.id ASC');
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Return one ascending id-based export batch. */
function admin_log_model_export_row_batch(int $afterId, int $limit): array
{
    $stmt = db()->prepare(
        'SELECT l.*, u.username FROM admin_logs l LEFT JOIN users u ON u.id = l.user_id'
        . ' WHERE l.id > ? ORDER BY l.id ASC LIMIT ' . max(50, min(2000, $limit))
    );
    $stmt->execute([max(0, $afterId)]);
    return $stmt->fetchAll();
}

/** Return one Admin log entry with username data. */
function admin_log_model_find(int $logId): ?array
{
    $stmt = db()->prepare('SELECT l.*, u.username FROM admin_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.id = ? LIMIT 1');
    $stmt->execute([$logId]);
    $entry = $stmt->fetch();
    return is_array($entry) ? $entry : null;
}

/** Update one Admin log row status and verify the stored value. */
function admin_log_model_update_status(int $logId, string $status, string $now): bool
{
    if ($status === 'done' && admin_log_model_column_exists('resolved_at')) {
        $stmt = db()->prepare('UPDATE admin_logs SET status = ?, status_updated_at = ?, resolved_at = COALESCE(resolved_at, ?) WHERE id = ?');
        $stmt->execute([$status, $now, $now, $logId]);
    } else {
        $stmt = db()->prepare('UPDATE admin_logs SET status = ?, status_updated_at = ? WHERE id = ?');
        $stmt->execute([$status, $now, $logId]);
    }
    if ((int) $stmt->rowCount() <= 0) {
        return false;
    }
    $check = db()->prepare('SELECT status FROM admin_logs WHERE id = ?');
    $check->execute([$logId]);
    return $check->fetchColumn() === $status;
}

/** Update every Admin log row represented by one grouped hash. */
function admin_log_model_update_group_status(string $groupHash, string $status, string $now): int
{
    $hashSql = admin_log_model_group_hash_sql('admin_logs');
    if ($status === 'done' && admin_log_model_column_exists('resolved_at')) {
        $stmt = db()->prepare('UPDATE admin_logs SET status = ?, status_updated_at = ?, resolved_at = COALESCE(resolved_at, ?) WHERE ' . $hashSql . ' = ?');
        $stmt->execute([$status, $now, $now, $groupHash]);
    } else {
        $stmt = db()->prepare('UPDATE admin_logs SET status = ?, status_updated_at = ? WHERE ' . $hashSql . ' = ?');
        $stmt->execute([$status, $now, $groupHash]);
    }
    return (int) $stmt->rowCount();
}
