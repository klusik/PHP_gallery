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
use PDOException;
use Throwable;
use function Gallery\Models\schema_inspection_model_column_definition_contains;
use function Gallery\Models\schema_inspection_model_column_nullable;
use function Gallery\Models\schema_inspection_model_nonunique_index_state;
use function Gallery\Models\schema_inspection_model_object_exists;
use function Gallery\Models\schema_inspection_model_query_definition;
use function Gallery\Models\schema_inspection_model_table_engine_state;
use function Gallery\Models\schema_inspection_model_table_snapshot_rows;
use function Gallery\Models\schema_inspection_model_unique_index_state;

const SCHEMA_INSPECTION_AVAILABLE = 'available';

const SCHEMA_INSPECTION_MISSING = 'missing';

const SCHEMA_INSPECTION_UNKNOWN = 'unknown';

const SCHEMA_INSPECTION_OBJECT_TABLE = 'table';

const SCHEMA_INSPECTION_OBJECT_COLUMN = 'column';

const SCHEMA_INSPECTION_OBJECT_INDEX = 'index';

/**
 * Identify foreign keys in structured schema inspection results.
 * Type: string. Units: object kind. Scope: one foreign-key requirement.
 * Consumers: schema_inspection_foreign_key_exact, schema_inspection_object.
 * Rationale: foreign_key lets capability checks report the exact missing
 *     constraint separately from its table, columns and indexes.
 */
const SCHEMA_INSPECTION_OBJECT_FOREIGN_KEY = 'foreign_key';

const SCHEMA_INSPECTION_ERROR_FAILED = 'inspection_failed';

const SCHEMA_INSPECTION_DIAGNOSTIC_FAILED = 'Database schema inspection failed.';

/**
 * Bound the expected columns inspected for one security-critical index.
 * Type: int. Units: columns. Scope: one exact index definition.
 * Consumers: schema_inspection_unique_index_exact.
 * Rationale: 16 columns admits normal composite keys while bounding metadata.
 */
const SCHEMA_INSPECTION_INDEX_COLUMN_LIMIT = 16;

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
 * @param array<int,array<string,mixed>> $rows information_schema.COLUMNS rows, using either driver column-name casing.
 * @return void Replaces request-local snapshots for validated requested tables.
 */
function schema_inspection_store_table_snapshots(
    array $tables,
    array $rows,
): void {
    $validated = [];
    foreach (array_slice(
        array_values(array_unique($tables)),
        0,
        24,
    ) as $table) {
        $validated[] = schema_inspection_validate_identifier(
            (string) $table,
            'table',
        );
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
            'column_type' => (string) (
                $row['COLUMN_TYPE']
                ?? $row['column_type']
                ?? ''
            ),
            'is_nullable' =>
                strtoupper(
                    (string) (
                        $row['IS_NULLABLE']
                        ?? $row['is_nullable']
                        ?? 'NO'
                    ),
                ) === 'YES',
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
    foreach (array_slice(
        array_values(array_unique($tables)),
        0,
        24,
    ) as $table) {
        $validated[] = schema_inspection_validate_identifier(
            (string) $table,
            'table',
        );
    }
    if ($validated === []) {
        return true;
    }

    $snapshots = &schema_inspection_table_snapshot_cache();
    $missing = array_values(array_filter(
        $validated,
        static fn(string $table): bool => !array_key_exists($table, $snapshots),
    ));
    if ($missing === []) {
        return true;
    }

    $override = &schema_inspection_query_executor_override();
    if (is_callable($override)) {
        return false;
    }

    try {
        $rows = schema_inspection_model_table_snapshot_rows($missing);
        schema_inspection_store_table_snapshots($missing, $rows);
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
 * @param string $table Validated table identifier represented in the request snapshot.
 * @param string $column Validated column identifier to inspect.
 * @return bool|null True/false for a primed table snapshot, or null when table metadata is not primed.
 */
function schema_inspection_snapshot_column_exists(
    string $table,
    string $column,
): ?bool {
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
 * @param string $table Validated table identifier represented in the request snapshot.
 * @param string $column Validated column identifier to inspect.
 * @return array{column_type:string,is_nullable:bool}|null Cached column type and nullability, or null when absent/unprimed.
 */
function schema_inspection_snapshot_column_metadata(
    string $table,
    string $column,
): ?array {
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
function schema_inspection_validate_identifier(
    string $name,
    string $label,
): string {
    if (
        $name === ''
        || strlen($name) > 64
        || preg_match('/^[A-Za-z0-9_]+$/D', $name) !== 1
    ) {
        throw new InvalidArgumentException(
            'Invalid schema ' . $label . ' identifier.',
        );
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
    string $diagnostic = '',
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
    if (
        $exception instanceof PDOException
        && is_array($exception->errorInfo ?? null)
    ) {
        $candidate = (string) ($exception->errorInfo[0] ?? '');
    }
    if ($candidate === '') {
        $candidate = (string) $exception->getCode();
    }
    if (
        $candidate !== ''
        && $candidate !== '0'
        && preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $candidate) === 1
    ) {
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
function schema_inspection_query_definition(
    string $objectType,
    string $table,
    string $object,
): array {
    return schema_inspection_model_query_definition(
        $objectType,
        $table,
        $object,
    );
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
function schema_inspection_execute_query(
    string $objectType,
    string $table,
    string $object,
): bool {
    $override = &schema_inspection_query_executor_override();
    if (is_callable($override)) {
        return (bool) $override($objectType, $table, $object);
    }
    return schema_inspection_model_object_exists($objectType, $table, $object);
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
function schema_inspection_column_definition_contains(
    string $table,
    string $column,
    string $token,
): array {
    $table = schema_inspection_validate_identifier($table, 'table');
    $column = schema_inspection_validate_identifier($column, 'column');
    if (
        $token === ''
        || strlen($token) > 64
        || preg_match('/^[A-Za-z0-9_-]+$/D', $token) !== 1
    ) {
        throw new InvalidArgumentException(
            'Invalid schema column-definition token.',
        );
    }

    // A missing or unknown column is already a complete answer and avoids a second query.
    $columnStatus = schema_inspection_column($table, $column);
    if (!schema_inspection_is_available($columnStatus)) {
        return $columnStatus;
    }

    $cacheKey =
        'column_definition_contains:' . $table . ':' . $column . ':' . $token;
    $cache = &schema_inspection_request_cache();
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $snapshotMetadata = schema_inspection_snapshot_column_metadata(
        $table,
        $column,
    );
    if ($snapshotMetadata !== null) {
        $exists = str_contains(
            strtolower((string) $snapshotMetadata['column_type']),
            strtolower($token),
        );
        $cache[$cacheKey] = schema_inspection_result(
            $exists ? SCHEMA_INSPECTION_AVAILABLE : SCHEMA_INSPECTION_MISSING,
            SCHEMA_INSPECTION_OBJECT_COLUMN,
            $table,
            $column,
        );
        return $cache[$cacheKey];
    }

    try {
        $override = &schema_inspection_query_executor_override();
        if (is_callable($override)) {
            // Existing three-argument test executors remain compatible because PHP
            // ignores surplus arguments for user-defined callables.
            $exists = (bool) $override(
                'column_definition_contains',
                $table,
                $column,
                $token,
            );
        } else {
            $exists = schema_inspection_model_column_definition_contains(
                $table,
                $column,
                $token,
            );
        }
        $cache[$cacheKey] = schema_inspection_result(
            $exists ? SCHEMA_INSPECTION_AVAILABLE : SCHEMA_INSPECTION_MISSING,
            SCHEMA_INSPECTION_OBJECT_COLUMN,
            $table,
            $column,
        );
    } catch (Throwable $exception) {
        $cache[$cacheKey] = schema_inspection_result(
            SCHEMA_INSPECTION_UNKNOWN,
            SCHEMA_INSPECTION_OBJECT_COLUMN,
            $table,
            $column,
            schema_inspection_error_code($exception),
            SCHEMA_INSPECTION_DIAGNOSTIC_FAILED,
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

    $snapshotMetadata = schema_inspection_snapshot_column_metadata(
        $table,
        $column,
    );
    if ($snapshotMetadata !== null) {
        $cache[$cacheKey] = schema_inspection_result(
            !empty($snapshotMetadata['is_nullable'])
                ? SCHEMA_INSPECTION_AVAILABLE
                : SCHEMA_INSPECTION_MISSING,
            SCHEMA_INSPECTION_OBJECT_COLUMN,
            $table,
            $column,
        );
        return $cache[$cacheKey];
    }

    try {
        $override = &schema_inspection_query_executor_override();
        if (is_callable($override)) {
            $nullable = (bool) $override(
                'column_nullable',
                $table,
                $column,
                'YES',
            );
        } else {
            $nullable = schema_inspection_model_column_nullable(
                $table,
                $column,
            );
        }
        $cache[$cacheKey] = schema_inspection_result(
            $nullable ? SCHEMA_INSPECTION_AVAILABLE : SCHEMA_INSPECTION_MISSING,
            SCHEMA_INSPECTION_OBJECT_COLUMN,
            $table,
            $column,
        );
    } catch (Throwable $exception) {
        $cache[$cacheKey] = schema_inspection_result(
            SCHEMA_INSPECTION_UNKNOWN,
            SCHEMA_INSPECTION_OBJECT_COLUMN,
            $table,
            $column,
            schema_inspection_error_code($exception),
            SCHEMA_INSPECTION_DIAGNOSTIC_FAILED,
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
function schema_inspection_object(
    string $objectType,
    string $table,
    string $object,
): array {
    if (!in_array(
        $objectType,
        [
            SCHEMA_INSPECTION_OBJECT_TABLE,
            SCHEMA_INSPECTION_OBJECT_COLUMN,
            SCHEMA_INSPECTION_OBJECT_INDEX,
        ],
        true,
    )) {
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
        $snapshotExists = schema_inspection_snapshot_column_exists(
            $table,
            $object,
        );
    }
    if ($snapshotExists !== null) {
        $cache[$cacheKey] = schema_inspection_result(
            $snapshotExists
                ? SCHEMA_INSPECTION_AVAILABLE
                : SCHEMA_INSPECTION_MISSING,
            $objectType,
            $table,
            $object,
        );
        return $cache[$cacheKey];
    }

    try {
        $exists = schema_inspection_execute_query($objectType, $table, $object);
        $cache[$cacheKey] = schema_inspection_result(
            $exists ? SCHEMA_INSPECTION_AVAILABLE : SCHEMA_INSPECTION_MISSING,
            $objectType,
            $table,
            $object,
        );
    } catch (Throwable $exception) {
        $cache[$cacheKey] = schema_inspection_result(
            SCHEMA_INSPECTION_UNKNOWN,
            $objectType,
            $table,
            $object,
            schema_inspection_error_code($exception),
            SCHEMA_INSPECTION_DIAGNOSTIC_FAILED,
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
    return schema_inspection_object(
        SCHEMA_INSPECTION_OBJECT_TABLE,
        $table,
        $table,
    );
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
    return schema_inspection_object(
        SCHEMA_INSPECTION_OBJECT_COLUMN,
        $table,
        $column,
    );
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
    return schema_inspection_object(
        SCHEMA_INSPECTION_OBJECT_INDEX,
        $table,
        $index,
    );
}

/**
 * Inspect a named index's uniqueness and exact ordered full-column definition.
 *
 * @param string $table Owning table identifier.
 * @param string $index Required index identifier.
 * @param list<string> $columns Exact ordered full-column identifiers.
 * @return array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string} Structured index-definition result.
 * @throws InvalidArgumentException For an invalid or oversized requirement.
 */
function schema_inspection_unique_index_exact(
    string $table,
    string $index,
    array $columns,
): array {
    return schema_inspection_index_exact($table, $index, $columns, true);
}

/**
 * Inspect a named nonunique index's exact ordered full-column definition.
 *
 * @param string $table Owning table identifier.
 * @param string $index Required index identifier.
 * @param list<string> $columns Exact ordered full-column identifiers.
 * @return array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string} Structured index-definition result.
 * @throws InvalidArgumentException For an invalid or oversized requirement.
 */
function schema_inspection_nonunique_index_exact(
    string $table,
    string $index,
    array $columns,
): array {
    return schema_inspection_index_exact($table, $index, $columns, false);
}

/**
 * Inspect one expected index definition with explicit uniqueness policy.
 *
 * @param string $table Owning table identifier.
 * @param string $index Required index identifier.
 * @param list<string> $columns Exact ordered full-column identifiers.
 * @param bool $unique Whether this index must be unique.
 * @return array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string} Structured index-definition result.
 * @throws InvalidArgumentException For an invalid or oversized requirement.
 */
function schema_inspection_index_exact(
    string $table,
    string $index,
    array $columns,
    bool $unique,
): array {
    $table = schema_inspection_validate_identifier($table, 'table');
    $index = schema_inspection_validate_identifier($index, 'index');
    if (
        !array_is_list($columns)
        || $columns === []
        || count($columns) > SCHEMA_INSPECTION_INDEX_COLUMN_LIMIT
    ) {
        throw new InvalidArgumentException('Invalid index column requirement.');
    }
    foreach ($columns as $column) {
        if (!is_string($column)) {
            throw new InvalidArgumentException(
                'Invalid index column requirement.',
            );
        }
        schema_inspection_validate_identifier($column, 'column');
    }
    $indexStatus = schema_inspection_index($table, $index);
    if (!schema_inspection_is_available($indexStatus)) {
        return $indexStatus;
    }
    $kind = $unique ? 'index_unique_exact' : 'index_nonunique_exact';
    $cacheKey = $kind . ':' . $table . ':' . $index . ':'
        . implode(',', $columns);
    $cache = &schema_inspection_request_cache();
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    try {
        $override = &schema_inspection_query_executor_override();
        $state = is_callable($override)
            ? ($override(
                $kind,
                $table,
                $index,
                implode(',', $columns),
            )
                ? SCHEMA_INSPECTION_AVAILABLE : SCHEMA_INSPECTION_MISSING)
            : ($unique
                ? schema_inspection_model_unique_index_state(
                    $table,
                    $index,
                    $columns,
                )
                : schema_inspection_model_nonunique_index_state(
                    $table,
                    $index,
                    $columns,
                ));
        $cache[$cacheKey] = schema_inspection_result(
            $state,
            SCHEMA_INSPECTION_OBJECT_INDEX,
            $table,
            $index,
            $state === SCHEMA_INSPECTION_UNKNOWN
                ? SCHEMA_INSPECTION_ERROR_FAILED : '',
            $state === SCHEMA_INSPECTION_UNKNOWN
                ? SCHEMA_INSPECTION_DIAGNOSTIC_FAILED : '',
        );
    } catch (Throwable $exception) {
        $cache[$cacheKey] = schema_inspection_result(
            SCHEMA_INSPECTION_UNKNOWN,
            SCHEMA_INSPECTION_OBJECT_INDEX,
            $table,
            $index,
            schema_inspection_error_code($exception),
            SCHEMA_INSPECTION_DIAGNOSTIC_FAILED,
        );
    }
    return $cache[$cacheKey];
}

/**
 * Inspect whether a table uses the requested transactional storage engine.
 *
 * @param string $table Owning table identifier.
 * @param string $engine Expected storage engine identifier.
 * @return array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string} Structured table-engine result.
 * @throws InvalidArgumentException For an invalid table or engine identifier.
 */
function schema_inspection_table_engine(string $table, string $engine): array
{
    $table = schema_inspection_validate_identifier($table, 'table');
    $engine = schema_inspection_validate_identifier($engine, 'engine');
    $tableStatus = schema_inspection_table($table);
    if (!schema_inspection_is_available($tableStatus)) {
        return $tableStatus;
    }
    $cacheKey = 'table_engine:' . $table . ':' . $engine;
    $cache = &schema_inspection_request_cache();
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    try {
        $override = &schema_inspection_query_executor_override();
        $state = is_callable($override)
            ? ($override('table_engine', $table, $table, $engine)
                ? SCHEMA_INSPECTION_AVAILABLE : SCHEMA_INSPECTION_MISSING)
            : schema_inspection_model_table_engine_state($table, $engine);
        $cache[$cacheKey] = schema_inspection_result(
            $state,
            SCHEMA_INSPECTION_OBJECT_TABLE,
            $table,
            $table,
            $state === SCHEMA_INSPECTION_UNKNOWN
                ? SCHEMA_INSPECTION_ERROR_FAILED : '',
            $state === SCHEMA_INSPECTION_UNKNOWN
                ? SCHEMA_INSPECTION_DIAGNOSTIC_FAILED : '',
        );
    } catch (Throwable $exception) {
        $cache[$cacheKey] = schema_inspection_result(
            SCHEMA_INSPECTION_UNKNOWN,
            SCHEMA_INSPECTION_OBJECT_TABLE,
            $table,
            $table,
            schema_inspection_error_code($exception),
            SCHEMA_INSPECTION_DIAGNOSTIC_FAILED,
        );
    }
    return $cache[$cacheKey];
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
 * @param list<array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string}> $requirements Non-empty inspection results for required schema objects.
 * @return array{state:string,feature:string,requirements:list<array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string}>} Aggregate state with each inspected object retained.
 */
function schema_inspection_feature(string $feature, array $requirements): array
{
    if (
        $feature === ''
        || strlen($feature) > 120
        || preg_match('/^[A-Za-z0-9_.-]+$/D', $feature) !== 1
    ) {
        throw new InvalidArgumentException(
            'Invalid schema feature identifier.',
        );
    }
    if ($requirements === []) {
        throw new InvalidArgumentException(
            'Schema feature requirements cannot be empty.',
        );
    }

    $state = SCHEMA_INSPECTION_AVAILABLE;
    foreach ($requirements as $requirement) {
        if (
            !is_array($requirement)
            || !in_array(
                $requirement['state'] ?? '',
                [
                    SCHEMA_INSPECTION_AVAILABLE,
                    SCHEMA_INSPECTION_MISSING,
                    SCHEMA_INSPECTION_UNKNOWN,
                ],
                true,
            )
            || !in_array(
                $requirement['object_type'] ?? '',
                [
                    SCHEMA_INSPECTION_OBJECT_TABLE,
                    SCHEMA_INSPECTION_OBJECT_COLUMN,
                    SCHEMA_INSPECTION_OBJECT_INDEX,
                    SCHEMA_INSPECTION_OBJECT_FOREIGN_KEY,
                ],
                true,
            )
            || !is_string($requirement['table'] ?? null)
            || !is_string($requirement['object'] ?? null)
            || !is_string($requirement['error_code'] ?? null)
            || !is_string($requirement['diagnostic'] ?? null)
        ) {
            throw new InvalidArgumentException(
                'Invalid schema feature requirement.',
            );
        }
        if (schema_inspection_is_unknown($requirement)) {
            $state = SCHEMA_INSPECTION_UNKNOWN;
            continue;
        }
        if (
            $state !== SCHEMA_INSPECTION_UNKNOWN
            && schema_inspection_is_missing($requirement)
        ) {
            $state = SCHEMA_INSPECTION_MISSING;
        }
    }

    return [
        'state' => $state,
        'feature' => $feature,
        'requirements' => array_values($requirements),
    ];
}

/**
 * Compare optional EXTRA attributes without changing legacy exact checks.
 *
 * @param string $extra Observed information_schema EXTRA value.
 * @param bool $autoIncrement Whether auto_increment must be present.
 * @param bool $requirePlain Require no EXTRA attributes at all.
 * @return bool True only when the requested attribute policy matches.
 */
function schema_inspection_extra_matches(
    string $extra,
    bool $autoIncrement,
    bool $requirePlain,
): bool {
    return str_contains(strtolower($extra), 'auto_increment') === $autoIncrement
        && (!$requirePlain || $extra === '');
}

/**
 * Check a column's exact type, nullability, collation and auto-increment flag.
 *
 * Integer display widths do not affect storage and differ across supported
 * MySQL/MariaDB versions; all other type modifiers remain significant.
 *
 * @param string $table Table name.
 * @param string $column Column name.
 * @param string $type Expected lowercase SQL COLUMN_TYPE.
 * @param bool $nullable Whether NULL is permitted.
 * @param string|null $collation Exact collation, or null for binary/numeric types.
 * @param bool $autoIncrement Whether auto_increment must be present.
 * @param bool $requirePlain Require empty EXTRA, refusing generated or modified columns.
 * @return array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string} Three-state result.
 */
function schema_inspection_column_exact(
    string $table,
    string $column,
    string $type,
    bool $nullable,
    ?string $collation,
    bool $autoIncrement = false,
    bool $requirePlain = false,
): array {
    $table = schema_inspection_validate_identifier($table, 'table');
    $column = schema_inspection_validate_identifier($column, 'column');
    $definition = [$type, $nullable, $collation, $autoIncrement];
    if ($requirePlain) {
        $definition[] = true;
    }
    $cacheKey = 'column_exact:' . $table . ':' . $column . ':'
        . hash('sha256', serialize($definition));
    $cache = &schema_inspection_request_cache();
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    try {
        $override = &schema_inspection_query_executor_override();
        if (is_callable($override)) {
            $matches = (bool) $override('column_exact', $table, $column, $definition);
        } else {
            $metadata = \Gallery\Models\schema_inspection_model_column_exact_metadata($table, $column);
            $observed = is_array($metadata) ? strtolower($metadata['type']) : '';
            // Only integer display width is discarded, never length or precision.
            $observed = preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/', '$1', $observed);
            $matches = is_array($metadata) && $observed === $type
                && $metadata['nullable'] === ($nullable ? 'YES' : 'NO')
                && $metadata['collation'] === $collation
                && schema_inspection_extra_matches(
                    $metadata['extra'], $autoIncrement, $requirePlain,
                );
        }
        return $cache[$cacheKey] = schema_inspection_result(
            $matches ? SCHEMA_INSPECTION_AVAILABLE : SCHEMA_INSPECTION_MISSING,
            SCHEMA_INSPECTION_OBJECT_COLUMN, $table, $column,
        );
    } catch (Throwable $exception) {
        return $cache[$cacheKey] = schema_inspection_result(
            SCHEMA_INSPECTION_UNKNOWN, SCHEMA_INSPECTION_OBJECT_COLUMN, $table, $column,
            schema_inspection_error_code($exception), SCHEMA_INSPECTION_DIAGNOSTIC_FAILED,
        );
    }
}

/**
 * Check one named single-column foreign key and its delete rule exactly.
 *
 * @param string $table Local table name.
 * @param string $constraint Foreign-key constraint name.
 * @param string $column Local column name.
 * @param string $referencedTable Referenced table name.
 * @param string $referencedColumn Referenced column name.
 * @param string $deleteRule Required delete rule, such as CASCADE.
 * @return array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string} Three-state result.
 */
function schema_inspection_foreign_key_exact(
    string $table,
    string $constraint,
    string $column,
    string $referencedTable,
    string $referencedColumn,
    string $deleteRule,
): array {
    $table = schema_inspection_validate_identifier($table, 'table');
    $constraint = schema_inspection_validate_identifier($constraint, 'constraint');
    $column = schema_inspection_validate_identifier($column, 'column');
    $referencedTable = schema_inspection_validate_identifier($referencedTable, 'table');
    $referencedColumn = schema_inspection_validate_identifier($referencedColumn, 'column');
    $cacheKey = 'foreign_key_exact:' . $table . ':' . $constraint . ':'
        . hash('sha256', serialize([$column, $referencedTable, $referencedColumn, $deleteRule]));
    $cache = &schema_inspection_request_cache();
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    try {
        $expected = [[
            'column' => $column, 'referenced_table' => $referencedTable,
            'referenced_column' => $referencedColumn, 'delete_rule' => $deleteRule,
        ]];
        $override = &schema_inspection_query_executor_override();
        $matches = is_callable($override)
            ? (bool) $override('foreign_key_exact', $table, $constraint, $expected)
            : \Gallery\Models\schema_inspection_model_foreign_key_metadata($table, $constraint) === $expected;
        return $cache[$cacheKey] = schema_inspection_result(
            $matches ? SCHEMA_INSPECTION_AVAILABLE : SCHEMA_INSPECTION_MISSING,
            SCHEMA_INSPECTION_OBJECT_FOREIGN_KEY, $table, $constraint,
        );
    } catch (Throwable $exception) {
        return $cache[$cacheKey] = schema_inspection_result(
            SCHEMA_INSPECTION_UNKNOWN, SCHEMA_INSPECTION_OBJECT_FOREIGN_KEY, $table, $constraint,
            schema_inspection_error_code($exception), SCHEMA_INSPECTION_DIAGNOSTIC_FAILED,
        );
    }
}
