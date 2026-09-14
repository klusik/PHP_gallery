<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/schema_inspection.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns information_schema query definitions and execution for structured schema inspection.
 *
 * Responsibilities:
 *   - Prime bounded table/column metadata snapshots
 *   - Execute table, column, and index existence checks
 *   - Inspect column definition tokens and nullability
 *   - Keep information_schema SQL out of service policy/cache orchestration
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
 *   - Identifiers arrive validated and are bound as values wherever possible.
 */

declare(strict_types=1);

namespace Gallery\Models;

use InvalidArgumentException;
use PDO;
use function Gallery\Core\db;

/** Return table/column metadata rows for a bounded validated table list. */
function schema_inspection_model_table_snapshot_rows(array $tables): array
{
    if ($tables === []) return [];
    $placeholders = implode(',', array_fill(0, count($tables), '?'));
    $statement = db()->prepare(
        'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE '
        . 'FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $placeholders . ') '
        . 'ORDER BY TABLE_NAME, ORDINAL_POSITION'
    );
    $statement->execute(array_values($tables));
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

/** Build one normalized prepared metadata query definition. */
function schema_inspection_model_query_definition(string $objectType, string $table, string $object): array
{
    if ($objectType === 'table') {
        return [
            'sql' => 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            'parameters' => [$table],
        ];
    }
    if ($objectType === 'column') {
        return [
            'sql' => 'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            'parameters' => [$table, $object],
        ];
    }
    if ($objectType === 'index') {
        return [
            'sql' => 'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            'parameters' => [$table, $object],
        ];
    }
    throw new InvalidArgumentException('Invalid schema object type.');
}

/** Execute one validated schema metadata existence query. */
function schema_inspection_model_object_exists(string $objectType, string $table, string $object): bool
{
    $query = schema_inspection_model_query_definition($objectType, $table, $object);
    $statement = db()->prepare($query['sql']);
    $statement->execute($query['parameters']);
    return (bool) $statement->fetchColumn();
}

/** Return whether one column definition contains the supplied validated token. */
function schema_inspection_model_column_definition_contains(string $table, string $column, string $token): bool
{
    $statement = db()->prepare(
        'SELECT 1 FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? '
        . 'AND LOCATE(?, COLUMN_TYPE) > 0 LIMIT 1'
    );
    $statement->execute([$table, $column, $token]);
    return (bool) $statement->fetchColumn();
}

/** Return whether one validated column accepts SQL NULL values. */
function schema_inspection_model_column_nullable(string $table, string $column): bool
{
    $statement = db()->prepare(
        'SELECT 1 FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? '
        . "AND IS_NULLABLE = 'YES' LIMIT 1"
    );
    $statement->execute([$table, $column]);
    return (bool) $statement->fetchColumn();
}
