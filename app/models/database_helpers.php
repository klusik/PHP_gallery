<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/database_helpers.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns legacy table/column existence fallback queries used by schema helpers.
 *
 * Responsibilities:
 *   - Check validated table existence
 *   - Check validated column existence
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
 *   - Callers must validate identifiers before invoking these fallback queries.
 */

declare(strict_types=1);

namespace Gallery\Models;

use function Gallery\Core\db;

/** Return whether one validated table contains one validated column. */
function database_helpers_model_column_exists(string $table, string $column): bool
{
    $safeColumn = str_replace(["\\", "'"], ['', "\\'"], $column);
    $stmt = db()->query("SHOW COLUMNS FROM `{$table}` LIKE '{$safeColumn}'");
    return (bool) ($stmt && $stmt->fetch());
}

/** Return whether one validated table exists. */
function database_helpers_model_table_exists(string $table): bool
{
    $stmt = db()->query('SHOW TABLES LIKE ' . db()->quote($table));
    return (bool) $stmt->fetchColumn();
}
