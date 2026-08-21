<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/schema_inspection.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides explicit, reusable three-state database schema inspection.
 *
 * Responsibilities:
 *   - Distinguish available schema objects from confirmed missing objects
 *   - Preserve database inspection failures as unknown state
 *   - Validate schema object names before a metadata query is executed
 *   - Cache inspection results for the lifetime of one PHP request
 *   - Combine feature requirements without discarding individual results
 *   - Inspect trusted column-definition tokens and nullability for migration compatibility
 *   - Expose bounded diagnostics that are safe for logs and Admin health data
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
 *   - This service reports observed schema state and contains no feature policy.
 *   - Boolean compatibility helpers may remain only at explicitly audited feature boundaries.
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-08-12
 */

declare(strict_types=1);

namespace Gallery\Services;

use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;
use function Gallery\Core\db;

const SCHEMA_INSPECTION_AVAILABLE = 'available';
const SCHEMA_INSPECTION_MISSING = 'missing';
const SCHEMA_INSPECTION_UNKNOWN = 'unknown';

const SCHEMA_INSPECTION_OBJECT_TABLE = 'table';
const SCHEMA_INSPECTION_OBJECT_COLUMN = 'column';
const SCHEMA_INSPECTION_OBJECT_INDEX = 'index';

const SCHEMA_INSPECTION_ERROR_FAILED = 'inspection_failed';
const SCHEMA_INSPECTION_DIAGNOSTIC_FAILED = 'Database schema inspection failed.';

/**
 * Return the request-local schema inspection cache by reference.
 *
 * The cache deliberately lives in process memory only. It avoids repeated
 * information_schema queries during one request without allowing stale schema
 * state to survive into another PHP request.
 *
 * @return array<string,array<string,string>> Results keyed by object identity.
 */
function &schema_inspection_request_cache(): array
{
    static $cache = [];
    return $cache;
}

/**
 * Return request-local bulk table/column metadata snapshots by reference.
 *
 * Snapshots are optional accelerators for high-fanout read-only requests. A
 * table key exists only after one successful bulk information_schema query, so
 * inspection failures never turn unknown schema state into confirmed absence.
 *
 * @return array<string,array{exists:bool,columns:array<string,array{column_type:string,is_nullable:bool}>}>
 */
function &schema_inspection_table_snapshot_cache(): array
{
    static $cache = [];
    return $cache;
}

/**
 * Return the optional query executor override used by focused tests.
 *
 * Production code leaves this value null and executes metadata queries through
 * the application's shared PDO connection. Keeping the seam inside this small
 * service avoids introducing a repository-wide dependency-injection system.
 *
 * @return mixed Null or a callable accepting object type, table, object, and optional definition token.
 */
function &schema_inspection_query_executor_override(): mixed
{
    static $executor = null;
    return $executor;
}

/**
 * Replace the schema metadata query executor for one isolated test process.
 *
 * Passing null restores production PDO behavior. The request cache is reset so
 * results produced by one executor cannot leak into another test scenario.
 * Application feature code must not use this test seam.
 *
 * @param ?callable $executor Callable returning true when an object or definition token exists.
 */
function schema_inspection_set_query_executor_for_tests(?callable $executor): void
{
    $override = &schema_inspection_query_executor_override();
    $override = $executor;
    schema_inspection_reset_request_cache();
}

/**
 * Clear all request-local schema inspection results.
 *
 * Tests use this function between scenarios. Migration and repair workflows
 * must also call it after successful DDL when they inspect, modify, and verify
 * schema state inside the same PHP process.
 */
function schema_inspection_reset_request_cache(): void
{
    $cache = &schema_inspection_request_cache();
    $cache = [];
    $snapshots = &schema_inspection_table_snapshot_cache();
    $snapshots = [];
}

/**
 * Commit validated bulk table/column metadata to the request-local snapshot.
 *
 * The helper is intentionally separate from PDO execution so focused tests can
 * verify snapshot semantics without requiring MySQL. Requested tables with no
 * returned columns are recorded as confirmed missing only after the surrounding
 * bulk query itself completed successfully.
 *
 * @param array<int,string> $tables Requested table names.
 * @param array<int,array<string,mixed>> $rows information_schema.COLUMNS rows.
 */
function schema_inspection_store_table_snapshots(array $tables, array $rows): void
{
    $validated = [];
    foreach (array_slice(array_values(array_unique($tables)), 0, 24) as $table) {
        $validated[] = schema_inspection_validate_identifier((string) $table, 'table');
    }
    if ($validated === []) {
        return;
    }

    $requested = array_fill_keys($validated, true);
    $built = [];
    foreach ($validated as $table) {
        $built[$table] = ['exists' => false, 'columns' => []];
    }
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $table = (string) ($row['TABLE_NAME'] ?? $row['table_name'] ?? '');
        $column = (string) ($row['COLUMN_NAME'] ?? $row['column_name'] ?? '');
        if (!isset($requested[$table])) {
            continue;
        }
        try {
            $column = schema_inspection_validate_identifier($column, 'column');
        } catch (InvalidArgumentException) {
            continue;
        }
        $built[$table]['exists'] = true;
        $built[$table]['columns'][$column] = [
            'column_type' => (string) ($row['COLUMN_TYPE'] ?? $row['column_type'] ?? ''),
            'is_nullable' => strtoupper((string) ($row['IS_NULLABLE'] ?? $row['is_nullable'] ?? 'NO')) === 'YES',
        ];
    }

    $snapshots = &schema_inspection_table_snapshot_cache();
    foreach ($built as $table => $snapshot) {
        $snapshots[$table] = $snapshot;
    }
}

/**
 * Prime bounded table/column snapshots with one information_schema round-trip.
 *
 * Existing request snapshots are reused. When PDO metadata inspection fails the
 * cache is left untouched and all normal object-level inspection functions keep
 * their original retry/fail-closed behavior.
 *
 * @param array<int,string> $tables Table names used by the current request path.
 * @return bool True when every requested table is represented by a snapshot.
 */
function schema_inspection_prime_table_snapshots(array $tables): bool
{
    $validated = [];
    foreach (array_slice(array_values(array_unique($tables)), 0, 24) as $table) {
        $validated[] = schema_inspection_validate_identifier((string) $table, 'table');
    }
    if ($validated === []) {
        return true;
    }

    $snapshots = &schema_inspection_table_snapshot_cache();
    $missing = array_values(array_filter($validated, static fn (string $table): bool => !array_key_exists($table, $snapshots)));
    if ($missing === []) {
        return true;
    }

    $override = &schema_inspection_query_executor_override();
    if (is_callable($override)) {
        return false;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($missing), '?'));
        $statement = db()->prepare(
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE '
            . 'FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $placeholders . ') '
            . 'ORDER BY TABLE_NAME, ORDINAL_POSITION'
        );
        $statement->execute($missing);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        schema_inspection_store_table_snapshots($missing, is_array($rows) ? $rows : []);
    } catch (Throwable) {
        return false;
    }

    foreach ($validated as $table) {
        if (!array_key_exists($table, $snapshots)) {
            return false;
        }
    }
    return true;
}

/**
 * Return a snapshotted table existence answer, or null when not primed.
 */
function schema_inspection_snapshot_table_exists(string $table): ?bool
{
    $table = schema_inspection_validate_identifier($table, 'table');
    $snapshots = &schema_inspection_table_snapshot_cache();
    if (!array_key_exists($table, $snapshots)) {
        return null;
    }
    return (bool) $snapshots[$table]['exists'];
}

/**
 * Return a snapshotted column existence answer, or null when not primed.
 */
function schema_inspection_snapshot_column_exists(string $table, string $column): ?bool
{
    $metadata = schema_inspection_snapshot_column_metadata($table, $column);
    if ($metadata === null) {
        $tableExists = schema_inspection_snapshot_table_exists($table);
        return $tableExists === null ? null : false;
    }
    return true;
}

/**
 * Return snapshotted column metadata, or null when absent/unprimed.
 *
 * @return ?array{column_type:string,is_nullable:bool}
 */
function schema_inspection_snapshot_column_metadata(string $table, string $column): ?array
{
    $table = schema_inspection_validate_identifier($table, 'table');
    $column = schema_inspection_validate_identifier($column, 'column');
    $snapshots = &schema_inspection_table_snapshot_cache();
    if (!isset($snapshots[$table]['columns'][$column])) {
        return null;
    }
    return $snapshots[$table]['columns'][$column];
}

/**
 * Validate one MySQL or MariaDB schema identifier used as a bound metadata value.
 *
 * Although information_schema queries bind names as values, validation keeps
 * cache keys and diagnostics predictable. The 64-character limit follows the
 * database identifier limit used by tables, columns, and indexes.
 *
 * @param string $name Identifier supplied by application code.
 * @param string $label Human-readable identifier role for exceptions.
 * @return string Validated identifier.
 */
function schema_inspection_validate_identifier(string $name, string $label): string
{
    if ($name === '' || strlen($name) > 64 || preg_match('/^[A-Za-z0-9_]+$/D', $name) !== 1) {
        throw new InvalidArgumentException('Invalid schema ' . $label . ' identifier.');
    }

    return $name;
}

/**
 * Build one normalized schema object result.
 *
 * @param string $state Available, missing, or unknown state.
 * @param string $objectType Table, column, or index.
 * @param string $table Owning table name.
 * @param string $object Inspected object name.
 * @param string $errorCode Bounded safe error category or SQLSTATE.
 * @param string $diagnostic Bounded non-sensitive diagnostic text.
 * @return array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string} Normalized result.
 */
function schema_inspection_result(
    string $state,
    string $objectType,
    string $table,
    string $object,
    string $errorCode = '',
    string $diagnostic = ''
): array {
    return [
        'state' => $state,
        'object_type' => $objectType,
        'table' => $table,
        'object' => $object,
        'error_code' => substr($errorCode, 0, 32),
        'diagnostic' => substr($diagnostic, 0, 200),
    ];
}

/**
 * Extract a bounded non-sensitive error category from an inspection failure.
 *
 * PDO SQLSTATE values are useful to administrators and contain no credentials.
 * Arbitrary exception messages are intentionally ignored because they may
 * contain hosts, database names, DSNs, query text, paths, or fixture secrets.
 *
 * @param Throwable $exception Inspection failure.
 * @return string Safe error category.
 */
function schema_inspection_error_code(Throwable $exception): string
{
    $candidate = '';
    if ($exception instanceof PDOException && is_array($exception->errorInfo ?? null)) {
        $candidate = (string) ($exception->errorInfo[0] ?? '');
    }
    if ($candidate === '') {
        $candidate = (string) $exception->getCode();
    }
    if ($candidate !== '' && $candidate !== '0' && preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $candidate) === 1) {
        return $candidate;
    }

    return SCHEMA_INSPECTION_ERROR_FAILED;
}

/**
 * Build the prepared metadata query for one validated schema object.
 *
 * Keeping query selection in a pure helper makes the production database
 * contract independently testable. Every query is constrained to DATABASE()
 * and every object name remains a bound value rather than SQL syntax.
 *
 * @param string $objectType Table, column, or index.
 * @param string $table Validated owning table name.
 * @param string $object Validated inspected object name.
 * @return array{sql:string,parameters:array<int,string>} Prepared query definition.
 */
function schema_inspection_query_definition(string $objectType, string $table, string $object): array
{
    if ($objectType === SCHEMA_INSPECTION_OBJECT_TABLE) {
        return [
            'sql' => 'SELECT 1 FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            'parameters' => [$table],
        ];
    }

    if ($objectType === SCHEMA_INSPECTION_OBJECT_COLUMN) {
        return [
            'sql' => 'SELECT 1 FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            'parameters' => [$table, $object],
        ];
    }

    if ($objectType === SCHEMA_INSPECTION_OBJECT_INDEX) {
        return [
            'sql' => 'SELECT 1 FROM information_schema.STATISTICS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            'parameters' => [$table, $object],
        ];
    }

    throw new InvalidArgumentException('Invalid schema object type.');
}

/**
 * Execute one schema metadata existence query.
 *
 * Names are validated before this function is called and are still passed as
 * bound values. This function returns true only when information_schema yields
 * a row. Query exceptions are handled by the outer inspection boundary.
 *
 * @param string $objectType Table, column, or index.
 * @param string $table Owning table name.
 * @param string $object Inspected object name.
 * @return bool True when the metadata row exists.
 */
function schema_inspection_execute_query(string $objectType, string $table, string $object): bool
{
    $override = &schema_inspection_query_executor_override();
    if (is_callable($override)) {
        return (bool) $override($objectType, $table, $object);
    }

    $query = schema_inspection_query_definition($objectType, $table, $object);
    $statement = db()->prepare($query['sql']);
    $statement->execute($query['parameters']);
    return (bool) $statement->fetchColumn();
}

/**
 * Inspect whether a column definition contains one trusted schema token.
 *
 * This is used for compatibility checks where column existence alone is not
 * enough, for example deciding whether galleries.visibility accepts the newer
 * unpublished enum value. The returned result keeps the normal column object
 * shape so feature aggregation and Admin diagnostics remain unchanged.
 *
 * The token is an application-owned schema literal, never user input. It is
 * still validated and passed as a bound value. Results share the request-local
 * schema cache and are therefore invalidated by the migration runner together
 * with ordinary table, column, and index inspections.
 *
 * @param string $table Table name.
 * @param string $column Column name.
 * @param string $token Definition token to locate, such as unpublished.
 * @return array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string} Inspection result.
 */
function schema_inspection_column_definition_contains(string $table, string $column, string $token): array
{
    $table = schema_inspection_validate_identifier($table, 'table');
    $column = schema_inspection_validate_identifier($column, 'column');
    if ($token === '' || strlen($token) > 64 || preg_match('/^[A-Za-z0-9_-]+$/D', $token) !== 1) {
        throw new InvalidArgumentException('Invalid schema column-definition token.');
    }

    // A missing or unknown column is already a complete answer and avoids a second query.
    $columnStatus = schema_inspection_column($table, $column);
    if (!schema_inspection_is_available($columnStatus)) {
        return $columnStatus;
    }

    $cacheKey = 'column_definition_contains:' . $table . ':' . $column . ':' . $token;
    $cache = &schema_inspection_request_cache();
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $snapshotMetadata = schema_inspection_snapshot_column_metadata($table, $column);
    if ($snapshotMetadata !== null) {
        $exists = str_contains(strtolower((string) $snapshotMetadata['column_type']), strtolower($token));
        $cache[$cacheKey] = schema_inspection_result(
            $exists ? SCHEMA_INSPECTION_AVAILABLE : SCHEMA_INSPECTION_MISSING,
            SCHEMA_INSPECTION_OBJECT_COLUMN,
            $table,
            $column
        );
        return $cache[$cacheKey];
    }

    try {
        $override = &schema_inspection_query_executor_override();
        if (is_callable($override)) {
            // Existing three-argument test executors remain compatible because PHP
            // ignores surplus arguments for user-defined callables.
            $exists = (bool) $override('column_definition_contains', $table, $column, $token);
        } else {
            $statement = db()->prepare(
                'SELECT 1 FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? '
                . 'AND LOCATE(?, COLUMN_TYPE) > 0 LIMIT 1'
            );
            $statement->execute([$table, $column, $token]);
            $exists = (bool) $statement->fetchColumn();
        }
        $cache[$cacheKey] = schema_inspection_result(
            $exists ? SCHEMA_INSPECTION_AVAILABLE : SCHEMA_INSPECTION_MISSING,
            SCHEMA_INSPECTION_OBJECT_COLUMN,
            $table,
            $column
        );
    } catch (Throwable $exception) {
        $cache[$cacheKey] = schema_inspection_result(
            SCHEMA_INSPECTION_UNKNOWN,
            SCHEMA_INSPECTION_OBJECT_COLUMN,
            $table,
            $column,
            schema_inspection_error_code($exception),
            SCHEMA_INSPECTION_DIAGNOSTIC_FAILED
        );
    }

    return $cache[$cacheKey];
}

/**
 * Inspect whether one verified column accepts SQL NULL values.
 *
 * Optional presentation compatibility sometimes depends on column nullability,
 * not merely existence. This helper preserves the same available/missing/unknown
 * vocabulary and request-local cache as ordinary object inspection. A result of
 * missing means the column exists but is not nullable, or the column itself is
 * confirmed absent. Unknown means information_schema could not be inspected.
 *
 * @param string $table Table name.
 * @param string $column Column name.
 * @return array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string} Inspection result.
 */
function schema_inspection_column_nullable(string $table, string $column): array
{
    $table = schema_inspection_validate_identifier($table, 'table');
    $column = schema_inspection_validate_identifier($column, 'column');

    // A missing or unknown column is already conclusive and avoids a second query.
    $columnStatus = schema_inspection_column($table, $column);
    if (!schema_inspection_is_available($columnStatus)) {
        return $columnStatus;
    }

    $cacheKey = 'column_nullable:' . $table . ':' . $column;
    $cache = &schema_inspection_request_cache();
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $snapshotMetadata = schema_inspection_snapshot_column_metadata($table, $column);
    if ($snapshotMetadata !== null) {
        $cache[$cacheKey] = schema_inspection_result(
            !empty($snapshotMetadata['is_nullable']) ? SCHEMA_INSPECTION_AVAILABLE : SCHEMA_INSPECTION_MISSING,
            SCHEMA_INSPECTION_OBJECT_COLUMN,
            $table,
            $column
        );
        return $cache[$cacheKey];
    }

    try {
        $override = &schema_inspection_query_executor_override();
        if (is_callable($override)) {
            $nullable = (bool) $override('column_nullable', $table, $column, 'YES');
        } else {
            $statement = db()->prepare(
                'SELECT 1 FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? '
                . "AND IS_NULLABLE = 'YES' LIMIT 1"
            );
            $statement->execute([$table, $column]);
            $nullable = (bool) $statement->fetchColumn();
        }
        $cache[$cacheKey] = schema_inspection_result(
            $nullable ? SCHEMA_INSPECTION_AVAILABLE : SCHEMA_INSPECTION_MISSING,
            SCHEMA_INSPECTION_OBJECT_COLUMN,
            $table,
            $column
        );
    } catch (Throwable $exception) {
        $cache[$cacheKey] = schema_inspection_result(
            SCHEMA_INSPECTION_UNKNOWN,
            SCHEMA_INSPECTION_OBJECT_COLUMN,
            $table,
            $column,
            schema_inspection_error_code($exception),
            SCHEMA_INSPECTION_DIAGNOSTIC_FAILED
        );
    }

    return $cache[$cacheKey];
}

/**
 * Inspect one validated schema object and cache the structured result.
 *
 * @param string $objectType Table, column, or index.
 * @param string $table Owning table name.
 * @param string $object Inspected object name.
 * @return array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string} Inspection result.
 */
function schema_inspection_object(string $objectType, string $table, string $object): array
{
    if (!in_array($objectType, [SCHEMA_INSPECTION_OBJECT_TABLE, SCHEMA_INSPECTION_OBJECT_COLUMN, SCHEMA_INSPECTION_OBJECT_INDEX], true)) {
        throw new InvalidArgumentException('Invalid schema object type.');
    }

    $table = schema_inspection_validate_identifier($table, 'table');
    $object = schema_inspection_validate_identifier($object, $objectType);
    $cacheKey = $objectType . ':' . $table . ':' . $object;
    $cache = &schema_inspection_request_cache();
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $snapshotExists = null;
    if ($objectType === SCHEMA_INSPECTION_OBJECT_TABLE) {
        $snapshotExists = schema_inspection_snapshot_table_exists($table);
    } elseif ($objectType === SCHEMA_INSPECTION_OBJECT_COLUMN) {
        $snapshotExists = schema_inspection_snapshot_column_exists($table, $object);
    }
    if ($snapshotExists !== null) {
        $cache[$cacheKey] = schema_inspection_result(
            $snapshotExists ? SCHEMA_INSPECTION_AVAILABLE : SCHEMA_INSPECTION_MISSING,
            $objectType,
            $table,
            $object
        );
        return $cache[$cacheKey];
    }

    try {
        $exists = schema_inspection_execute_query($objectType, $table, $object);
        $cache[$cacheKey] = schema_inspection_result(
            $exists ? SCHEMA_INSPECTION_AVAILABLE : SCHEMA_INSPECTION_MISSING,
            $objectType,
            $table,
            $object
        );
    } catch (Throwable $exception) {
        $cache[$cacheKey] = schema_inspection_result(
            SCHEMA_INSPECTION_UNKNOWN,
            $objectType,
            $table,
            $object,
            schema_inspection_error_code($exception),
            SCHEMA_INSPECTION_DIAGNOSTIC_FAILED
        );
    }

    return $cache[$cacheKey];
}

/**
 * Inspect whether one database table exists.
 *
 * @param string $table Table name.
 * @return array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string} Inspection result.
 */
function schema_inspection_table(string $table): array
{
    return schema_inspection_object(SCHEMA_INSPECTION_OBJECT_TABLE, $table, $table);
}

/**
 * Inspect whether one table contains a column.
 *
 * @param string $table Table name.
 * @param string $column Column name.
 * @return array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string} Inspection result.
 */
function schema_inspection_column(string $table, string $column): array
{
    return schema_inspection_object(SCHEMA_INSPECTION_OBJECT_COLUMN, $table, $column);
}

/**
 * Inspect whether one table contains an index.
 *
 * @param string $table Table name.
 * @param string $index Index name.
 * @return array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string} Inspection result.
 */
function schema_inspection_index(string $table, string $index): array
{
    return schema_inspection_object(SCHEMA_INSPECTION_OBJECT_INDEX, $table, $index);
}

/**
 * Return true when an inspection result confirms object availability.
 *
 * @param array $result Schema inspection result.
 * @return bool True only for available state.
 */
function schema_inspection_is_available(array $result): bool
{
    return ($result['state'] ?? '') === SCHEMA_INSPECTION_AVAILABLE;
}

/**
 * Return true when an inspection result confirms object absence.
 *
 * @param array $result Schema inspection result.
 * @return bool True only for missing state.
 */
function schema_inspection_is_missing(array $result): bool
{
    return ($result['state'] ?? '') === SCHEMA_INSPECTION_MISSING;
}

/**
 * Return true when an inspection could not establish object state.
 *
 * @param array $result Schema inspection result.
 * @return bool True only for unknown state.
 */
function schema_inspection_is_unknown(array $result): bool
{
    return ($result['state'] ?? '') === SCHEMA_INSPECTION_UNKNOWN;
}

/**
 * Combine inspected requirements into one feature capability result.
 *
 * Unknown takes precedence because at least one requirement could not be
 * verified. Missing takes precedence over available only after every
 * requirement has a reliable answer. Individual results remain attached so a
 * caller or Admin diagnostic can identify every affected object.
 *
 * @param string $feature Stable feature key used by diagnostics.
 * @param array $requirements List of results returned by this service.
 * @return array{state:string,feature:string,requirements:array} Aggregate result.
 */
function schema_inspection_feature(string $feature, array $requirements): array
{
    if ($feature === '' || strlen($feature) > 120 || preg_match('/^[A-Za-z0-9_.-]+$/D', $feature) !== 1) {
        throw new InvalidArgumentException('Invalid schema feature identifier.');
    }
    if ($requirements === []) {
        throw new InvalidArgumentException('Schema feature requirements cannot be empty.');
    }

    $state = SCHEMA_INSPECTION_AVAILABLE;
    foreach ($requirements as $requirement) {
        if (
            !is_array($requirement)
            || !in_array($requirement['state'] ?? '', [SCHEMA_INSPECTION_AVAILABLE, SCHEMA_INSPECTION_MISSING, SCHEMA_INSPECTION_UNKNOWN], true)
            || !in_array($requirement['object_type'] ?? '', [SCHEMA_INSPECTION_OBJECT_TABLE, SCHEMA_INSPECTION_OBJECT_COLUMN, SCHEMA_INSPECTION_OBJECT_INDEX], true)
            || !is_string($requirement['table'] ?? null)
            || !is_string($requirement['object'] ?? null)
            || !is_string($requirement['error_code'] ?? null)
            || !is_string($requirement['diagnostic'] ?? null)
        ) {
            throw new InvalidArgumentException('Invalid schema feature requirement.');
        }
        if (schema_inspection_is_unknown($requirement)) {
            $state = SCHEMA_INSPECTION_UNKNOWN;
            continue;
        }
        if ($state !== SCHEMA_INSPECTION_UNKNOWN && schema_inspection_is_missing($requirement)) {
            $state = SCHEMA_INSPECTION_MISSING;
        }
    }

    return [
        'state' => $state,
        'feature' => $feature,
        'requirements' => array_values($requirements),
    ];
}
