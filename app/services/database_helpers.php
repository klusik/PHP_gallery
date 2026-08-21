<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/database_helpers.php
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
use function Gallery\Core\db;

/**
 * Database schema helper service.
 *
 * Contains tiny schema-inspection helpers used by optional migrations and
 * feature gates. These helpers stay generic and must not contain feature-specific
 * rendering or mutation logic.
 */

/**
 * Return request-local legacy schema helper cache by reference.
 *
 * @return array<string,bool> Cached table/column capability answers.
 */
function &db_schema_helper_request_cache(): array
{
    static $cache = [];
    return $cache;
}

/**
 * Clear request-local legacy schema helper answers after same-request DDL.
 */
function db_schema_helper_reset_request_cache(): void
{
    $cache = &db_schema_helper_request_cache();
    $cache = [];
}

/**
 * Check whether one database table contains a column.
 *
 * @param string $table Table value.
 * @param string $column Column value.
 * @return bool True when the condition matches.
 */
function db_column_exists(string $table, string $column): bool
{
    // $safeTable stores an intermediate value used by the surrounding gallery workflow.
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    // $safeColumn stores an intermediate value used by the surrounding gallery workflow.
    $safeColumn = str_replace(["\\", "'"], ['', "\\'"], $column);
    if ($safeTable === '' || $safeColumn === '') {
        return false;
    }

    $cache = &db_schema_helper_request_cache();
    $cacheKey = 'column:' . $safeTable . ':' . $safeColumn;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if (function_exists(__NAMESPACE__ . '\\schema_inspection_snapshot_column_exists')) {
        $snapshotExists = schema_inspection_snapshot_column_exists($safeTable, $safeColumn);
        if ($snapshotExists !== null) {
            return $cache[$cacheKey] = $snapshotExists;
        }
    }

    try {
        // $stmt stores an intermediate value used by the surrounding gallery workflow.
        $stmt = db()->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        return $cache[$cacheKey] = (bool) ($stmt && $stmt->fetch());
    } catch (Throwable) {
        return $cache[$cacheKey] = false;
    }
}

/**
 * Check whether a database table exists.
 *
 * @param string $table Table value.
 * @return bool True when the condition matches.
 */
function db_table_exists(string $table): bool
{
    // $safeTable stores an intermediate value used by the surrounding gallery workflow.
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($safeTable === '') {
        return false;
    }

    $cache = &db_schema_helper_request_cache();
    $cacheKey = 'table:' . $safeTable;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if (function_exists(__NAMESPACE__ . '\\schema_inspection_snapshot_table_exists')) {
        $snapshotExists = schema_inspection_snapshot_table_exists($safeTable);
        if ($snapshotExists !== null) {
            return $cache[$cacheKey] = $snapshotExists;
        }
    }

    try {
        // $stmt stores an intermediate value used by the surrounding gallery workflow.
        $stmt = db()->query("SHOW TABLES LIKE " . db()->quote($safeTable));
        return $cache[$cacheKey] = (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        return $cache[$cacheKey] = false;
    }
}
