<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/admin_database_usage.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns database metadata reads and ANALYZE TABLE execution for Admin usage diagnostics.
 *
 * Responsibilities:
 *   - Return the active database/schema name
 *   - Read information_schema table-size metadata
 *   - Execute bounded ANALYZE TABLE operations for validated table names
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
 */

declare(strict_types=1);

namespace Gallery\Models;

use function Gallery\Core\db;

/** Return the active database/schema name from the shared connection. */
function admin_database_usage_model_current_database_name(): string
{
    $row = db()->query('SELECT DATABASE() AS database_name')->fetch() ?: [];
    return trim((string) ($row['database_name'] ?? ''));
}

/** Return raw table-size rows from information_schema.TABLES. */
function admin_database_usage_model_table_rows(string $databaseName): array
{
    $stmt = db()->prepare(
        'SELECT TABLE_NAME AS table_name,
                COALESCE(TABLE_ROWS, 0) AS table_rows,
                COALESCE(DATA_LENGTH, 0) AS data_bytes,
                COALESCE(INDEX_LENGTH, 0) AS index_bytes,
                COALESCE(ENGINE, "") AS engine
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = :database_name
          ORDER BY (COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0)) DESC, TABLE_NAME ASC'
    );
    $stmt->execute(['database_name' => $databaseName]);
    return $stmt->fetchAll();
}

/** Execute ANALYZE TABLE for one validated table name. */
function admin_database_usage_model_analyze_table(string $tableName): array
{
    $quoted = '`' . str_replace('`', '``', $tableName) . '`';
    $stmt = db()->query('ANALYZE TABLE ' . $quoted);
    $rows = $stmt ? $stmt->fetchAll() : [];
    return is_array($rows) ? $rows : [];
}
