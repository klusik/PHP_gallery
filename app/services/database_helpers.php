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

/**
 * Database schema helper service.
 *
 * Contains tiny schema-inspection helpers used by optional migrations and
 * feature gates. These helpers stay generic and must not contain feature-specific
 * rendering or mutation logic.
 */

/**
 * Check whether one database table contains a column.
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

    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = db()->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return (bool) $stmt->fetch();
}

/**
 * Check whether a database table exists.
 */
function db_table_exists(string $table): bool
{
    // $safeTable stores an intermediate value used by the surrounding gallery workflow.
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($safeTable === '') {
        return false;
    }

    try {
        // $stmt stores an intermediate value used by the surrounding gallery workflow.
        $stmt = db()->query("SHOW TABLES LIKE " . db()->quote($safeTable));
        return (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}
