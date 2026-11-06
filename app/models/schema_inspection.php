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

/**
 * Read a complete bounded migration ledger without changing database state.
 * @param int $limit Maximum admitted migration records; overflow is refused.
 * @return list<string> Ordered applied migration identities.
 * @throws \RuntimeException When the ledger exceeds its admission budget.
 */
function schema_inspection_model_migration_versions(int $limit): array
{
    $statement = db()->prepare(
        'SELECT version FROM schema_migrations ORDER BY version LIMIT ?',
    );
    // One extra row distinguishes complete evidence from an unsafe truncation.
    $statement->bindValue(1, $limit + 1, PDO::PARAM_INT);
    $statement->execute();
    $versions = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    if (count($versions) > $limit) {
        throw new \RuntimeException('Recovery migration ledger exceeds its bound.');
    }
    return $versions;
}

/**
 * Read all ledger fields used by the versioned updater recovery witness.
 * @param int $limit Maximum admitted rows; an extra row detects truncation.
 * @return list<array{version:string,applied_at:string}> Ordered audit records.
 * @throws \RuntimeException When complete evidence exceeds the bound.
 */
function schema_inspection_model_migration_ledger(int $limit): array
{
    $statement = db()->prepare(
        'SELECT version,applied_at FROM schema_migrations ORDER BY version LIMIT ?',
    );
    $statement->bindValue(1, $limit + 1, PDO::PARAM_INT);
    $statement->execute();
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > $limit) {
        throw new \RuntimeException('Recovery migration ledger exceeds its bound.');
    }
    return array_map(static fn(array $row): array => [
        'version' => (string) $row['version'],
        'applied_at' => (string) $row['applied_at'],
    ], $rows);
}

/**
 * Fingerprint complete bounded column/index definitions for recovery checks.
 * @param int $limit Maximum metadata rows admitted per schema inventory.
 * @return string SHA-256 fingerprint of actual ordered database definitions.
 * @throws \RuntimeException When metadata is incomplete or exceeds the bound.
 */
function schema_inspection_model_recovery_schema(int $limit): string
{
    $definitions = [];
    foreach ([
        'SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,'
            . 'EXTRA,COLLATION_NAME FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION LIMIT ?',
        'SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART '
            . 'FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() '
            . 'ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX LIMIT ?',
    ] as $query) {
        $statement = db()->prepare($query);
        $statement->bindValue(1, $limit + 1, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === [] || count($rows) > $limit) {
            throw new \RuntimeException('Recovery schema inventory is incomplete.');
        }
        $definitions[] = $rows;
    }
    return hash('sha256', json_encode($definitions, JSON_THROW_ON_ERROR));
}

/**
 * Return column metadata rows for a bounded validated table list.
 *
 * @param array<int,string> $tables Table names to inspect.
 * @return array<int,array<string,mixed>> Rows with TABLE_NAME, COLUMN_NAME,
 *     COLUMN_TYPE and IS_NULLABLE fields, or an empty list when no tables are
 *     supplied or no rows are returned.
 */
function schema_inspection_model_table_snapshot_rows(array $tables): array
{
    if ($tables === [])
        return [];
    $placeholders = implode(',', array_fill(0, count($tables), '?'));
    $statement = db()->prepare(
        'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE '
            . 'FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('
            . $placeholders
            . ') '
            . 'ORDER BY TABLE_NAME, ORDINAL_POSITION',
    );
    $statement->execute(array_values($tables));
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

/**
 * Build one normalized prepared metadata query definition.
 *
 * @param string $objectType Supported schema object type: table, column, or
 *     index.
 * @param string $table Table name to inspect.
 * @param string $object Column or index name; unused for table lookups.
 * @return array{sql:string,parameters:array<int,string>} SQL statement and
 *     ordered bound string parameters.
 * @throws InvalidArgumentException When the object type is unsupported.
 */
function schema_inspection_model_query_definition(
    string $objectType,
    string $table,
    string $object,
): array {
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

/**
 * Execute one validated schema metadata existence query.
 *
 * @param string $objectType Supported schema object type: table, column, or
 *     index.
 * @param string $table Table name to inspect.
 * @param string $object Column or index name; unused for table lookups.
 * @return bool True when the requested schema object exists, otherwise false.
 */
function schema_inspection_model_object_exists(
    string $objectType,
    string $table,
    string $object,
): bool {
    $query = schema_inspection_model_query_definition(
        $objectType,
        $table,
        $object,
    );
    $statement = db()->prepare($query['sql']);
    $statement->execute($query['parameters']);
    return (bool) $statement->fetchColumn();
}

/**
 * Observe an exact full-column unique index rather than its name alone.
 *
 * @param string $table Validated table identifier.
 * @param string $index Validated index identifier.
 * @param list<string> $columns Ordered validated full-column identifiers.
 * @return 'available'|'missing'|'unknown' Matching definition, confirmed
 *     absent/incompatible definition, or incomplete metadata.
 * @throws \Throwable When metadata access fails.
 */
function schema_inspection_model_unique_index_state(
    string $table,
    string $index,
    array $columns,
): string {
    return schema_inspection_model_index_definition_state(
        $table,
        $index,
        $columns,
        true,
    );
}

/**
 * Observe an exact full-column nonunique index used for bounded reads.
 *
 * @param string $table Validated table identifier.
 * @param string $index Validated index identifier.
 * @param list<string> $columns Ordered validated full-column identifiers.
 * @return 'available'|'missing'|'unknown' Matching definition, confirmed
 *     absent/incompatible definition, or incomplete metadata.
 * @throws \Throwable When metadata access fails.
 */
function schema_inspection_model_nonunique_index_state(
    string $table,
    string $index,
    array $columns,
): string {
    return schema_inspection_model_index_definition_state(
        $table,
        $index,
        $columns,
        false,
    );
}

/**
 * Compare a complete index definition with the expected ordered columns.
 *
 * @param string $table Validated table identifier.
 * @param string $index Validated index identifier.
 * @param list<string> $columns Ordered validated full-column identifiers.
 * @param bool $unique Whether the required index must be unique.
 * @return 'available'|'missing'|'unknown' Matching definition, confirmed
 *     absent/incompatible definition, or incomplete metadata.
 * @throws \Throwable When metadata access fails.
 */
function schema_inspection_model_index_definition_state(
    string $table,
    string $index,
    array $columns,
    bool $unique,
): string {
    $statement = db()->prepare(
        'SELECT COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE, SUB_PART '
            . 'FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
            . 'AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX LIMIT ?',
    );
    $statement->bindValue(1, $table, PDO::PARAM_STR);
    $statement->bindValue(2, $index, PDO::PARAM_STR);
    // One lookahead row proves that the ordered definition has no extra field.
    $statement->bindValue(3, count($columns) + 1, PDO::PARAM_INT);
    if (!$statement->execute()) {
        return 'unknown';
    }
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return 'unknown';
    }
    if (count($rows) !== count($columns)) {
        return 'missing';
    }
    foreach ($columns as $offset => $column) {
        $row = $rows[$offset];
        if (!is_array($row)) {
            return 'unknown';
        }
        foreach ([
            'COLUMN_NAME', 'SEQ_IN_INDEX', 'NON_UNIQUE', 'SUB_PART',
        ] as $field) {
            if (!array_key_exists($field, $row)) {
                return 'unknown';
            }
        }
        if (
            !in_array($row['NON_UNIQUE'], [0, '0', 1, '1'], true)
            || !(
                is_int($row['SEQ_IN_INDEX'])
                || (is_string($row['SEQ_IN_INDEX'])
                    && preg_match('/\A[0-9]+\z/D', $row['SEQ_IN_INDEX']) === 1)
            )
        ) {
            return 'unknown';
        }
        if (
            $row['COLUMN_NAME'] !== $column
            || (int) $row['SEQ_IN_INDEX'] !== $offset + 1
            || !in_array(
                $row['NON_UNIQUE'],
                $unique ? [0, '0'] : [1, '1'],
                true,
            )
            || $row['SUB_PART'] !== null
        ) {
            return 'missing';
        }
    }
    return 'available';
}

/**
 * Observe the storage engine of one table for transaction guarantees.
 *
 * @param string $table Validated table identifier.
 * @param string $engine Validated expected engine identifier.
 * @return 'available'|'missing'|'unknown' Matching engine, confirmed absent
 *     or different table engine, or incomplete engine metadata.
 * @throws \Throwable When metadata access fails.
 */
function schema_inspection_model_table_engine_state(
    string $table,
    string $engine,
): string {
    $statement = db()->prepare(
        'SELECT ENGINE FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
    );
    if (!$statement->execute([$table])) {
        return 'unknown';
    }
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return 'missing';
    }
    if (!is_array($row) || !is_string($row['ENGINE'] ?? null)) {
        return 'unknown';
    }
    return strcasecmp($row['ENGINE'], $engine) === 0
        ? 'available' : 'missing';
}

/**
 * Return whether one column definition contains the supplied validated token.
 *
 * @param string $table Table name containing the column.
 * @param string $column Column name to inspect.
 * @param string $token Definition token to search for.
 * @return bool True when the column definition contains the token, otherwise
 *     false.
 */
function schema_inspection_model_column_definition_contains(
    string $table,
    string $column,
    string $token,
): bool {
    $statement = db()->prepare(
        'SELECT 1 FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? '
            . 'AND LOCATE(?, COLUMN_TYPE) > 0 LIMIT 1',
    );
    $statement->execute([$table, $column, $token]);
    return (bool) $statement->fetchColumn();
}

/**
 * Return whether one validated column accepts SQL NULL values.
 *
 * @param string $table Table name containing the column.
 * @param string $column Column name to inspect.
 * @return bool True when the column is nullable, otherwise false.
 */
function schema_inspection_model_column_nullable(
    string $table,
    string $column,
): bool {
    $statement = db()->prepare(
        'SELECT 1 FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? '
            . "AND IS_NULLABLE = 'YES' LIMIT 1",
    );
    $statement->execute([$table, $column]);
    return (bool) $statement->fetchColumn();
}

/**
 * Read exact column metadata from the current database only.
 *
 * @param string $table Validated table name.
 * @param string $column Validated column name.
 * @return array{type:string,nullable:string,collation:?string,extra:string}|null Metadata, or null if absent.
 * @throws \Throwable When metadata access fails.
 */
function schema_inspection_model_column_exact_metadata(string $table, string $column): ?array
{
    $query = db()->prepare(
        'SELECT COLUMN_TYPE, IS_NULLABLE, COLLATION_NAME, EXTRA '
            . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() '
            . 'AND TABLE_NAME = ? AND COLUMN_NAME = ?',
    );
    $query->execute([$table, $column]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    return [
        'type' => (string) $row['COLUMN_TYPE'],
        'nullable' => (string) $row['IS_NULLABLE'],
        'collation' => $row['COLLATION_NAME'] === null ? null : (string) $row['COLLATION_NAME'],
        'extra' => (string) $row['EXTRA'],
    ];
}

/**
 * Read all ordered columns of a named foreign-key constraint.
 *
 * @param string $table Validated table name.
 * @param string $constraint Validated constraint name.
 * @return list<array{column:string,referenced_table:string,referenced_column:string,delete_rule:string}> Ordered foreign-key fields.
 * @throws \Throwable When metadata access fails.
 */
function schema_inspection_model_foreign_key_metadata(string $table, string $constraint): array
{
    $query = db()->prepare(
        'SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, '
            . 'r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k '
            . 'INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
            . 'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA '
            . 'AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME '
            . 'WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = ? '
            . 'AND k.CONSTRAINT_NAME = ? ORDER BY k.ORDINAL_POSITION LIMIT 17',
    );
    $query->execute([$table, $constraint]);
    $result = [];
    while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
        $result[] = [
            'column' => (string) $row['COLUMN_NAME'],
            'referenced_table' => (string) $row['REFERENCED_TABLE_NAME'],
            'referenced_column' => (string) $row['REFERENCED_COLUMN_NAME'],
            'delete_rule' => (string) $row['DELETE_RULE'],
        ];
    }
    return $result;
}
