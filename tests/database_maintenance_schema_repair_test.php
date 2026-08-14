<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/database_maintenance_schema_repair_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies conditional database-maintenance schema repair behavior without a
 *   live MySQL or MariaDB server.
 *
 * Responsibilities:
 *   - Cover absent, partially compacted, and already compact schemas
 *   - Confirm every DDL action is guarded by information_schema state
 *   - Confirm source geometry migration precedes destructive legacy-column drops
 *   - Confirm obsolete index and foreign-key cleanup is idempotent
 *   - Confirm no destructive row deletion or filesystem operation occurs
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
 *   2026-07-25
 */

declare(strict_types=1);

use function Gallery\Core\run_database_maintenance_schema_repair;

require_once __DIR__ . '/../app/migration_repairs.php';

/**
 * Throw when a repair expectation fails.
 */
function assert_database_maintenance_repair(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

/**
 * Prepared information_schema statement backed by the fake schema state.
 */
final class DatabaseMaintenanceRepairStatement extends PDOStatement
{
    private DatabaseMaintenanceRepairPdo $pdo;
    private string $sql;

    /** @var array<int|string,mixed> */
    private array $parameters = [];

    /**
     * Create a repair statement double bound to its fake PDO connection.
     */
    public function __construct(DatabaseMaintenanceRepairPdo $pdo, string $sql)
    {
        $this->pdo = $pdo;
        $this->sql = $sql;
    }

    /**
     * Store positional information_schema parameters.
     *
     * @param array<int|string,mixed>|null $params Bound values.
     */
    public function execute(?array $params = null): bool
    {
        $this->parameters = $params ?? [];
        return true;
    }

    /**
     * Return the current fake-schema probe result.
     *
     * @return mixed Probe result.
     */
    public function fetchColumn(int $column = 0): mixed
    {
        return $this->pdo->probe($this->sql, array_values($this->parameters));
    }
}

/**
 * Mutable PDO schema fixture for conditional repair tests.
 */
final class DatabaseMaintenanceRepairPdo extends PDO
{
    /** @var array<string,array<string,bool>> */
    public array $columns;

    /** @var array<string,array<string,bool>> */
    public array $indexes;

    /** @var array<string,array<string,bool>> */
    public array $foreignKeys;

    /** @var array<int,string> */
    public array $executedSql = [];

    /**
     * @param array<string,array<int,string>> $columns Columns keyed by table.
     * @param array<string,array<int,string>> $indexes Indexes keyed by table.
     * @param array<string,array<int,string>> $foreignKeys Foreign keys keyed by table.
     */
    public function __construct(array $columns, array $indexes = [], array $foreignKeys = [])
    {
        $this->columns = $this->normalizeObjects($columns);
        $this->indexes = $this->normalizeObjects($indexes);
        $this->foreignKeys = $this->normalizeObjects($foreignKeys);
    }

    /**
     * Convert table/object lists to lookup maps.
     *
     * @param array<string,array<int,string>> $objects Objects.
     * @return array<string,array<string,bool>> Lookup map.
     */
    private function normalizeObjects(array $objects): array
    {
        $normalized = [];
        foreach ($objects as $table => $names) {
            $normalized[$table] = array_fill_keys(array_map('strval', $names), true);
        }
        return $normalized;
    }

    /**
     * Prepare one information_schema existence probe.
     *
     * @param array<int|string,mixed> $options Driver options.
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new DatabaseMaintenanceRepairStatement($this, $query);
    }

    /**
     * Resolve one information_schema probe against mutable state.
     *
     * @param array<int,mixed> $parameters Positional parameters.
     */
    public function probe(string $sql, array $parameters): bool
    {
        if (str_contains($sql, 'information_schema.TABLES')) {
            return isset($this->columns[(string) ($parameters[0] ?? '')]);
        }
        if (str_contains($sql, 'information_schema.COLUMNS')) {
            return isset($this->columns[(string) ($parameters[0] ?? '')][(string) ($parameters[1] ?? '')]);
        }
        if (str_contains($sql, 'information_schema.STATISTICS')) {
            return isset($this->indexes[(string) ($parameters[0] ?? '')][(string) ($parameters[1] ?? '')]);
        }
        if (str_contains($sql, 'information_schema.TABLE_CONSTRAINTS')) {
            return isset($this->foreignKeys[(string) ($parameters[0] ?? '')][(string) ($parameters[1] ?? '')]);
        }
        throw new RuntimeException('Unexpected schema probe: ' . $sql);
    }

    /**
     * Apply enough DDL semantics to validate idempotent repair decisions.
     */
    public function exec(string $statement): int|false
    {
        $this->executedSql[] = $statement;
        if (preg_match('/CREATE TABLE `?([A-Za-z_][A-Za-z0-9_]*)`?\s*\(([\s\S]*)\) ENGINE=/i', $statement, $match) === 1) {
            $tableName = (string) $match[1];
            $this->columns[$tableName] = [];
            foreach (preg_split('/\R/', (string) $match[2]) ?: [] as $line) {
                if (preg_match('/^\s*`?([A-Za-z_][A-Za-z0-9_]*)`?\s+/', trim((string) $line), $columnMatch) !== 1) {
                    continue;
                }
                $candidate = strtoupper((string) $columnMatch[1]);
                if (!in_array($candidate, ['PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'CONSTRAINT', 'FOREIGN', 'CHECK'], true)) {
                    $this->columns[$tableName][(string) $columnMatch[1]] = true;
                }
            }
            return 0;
        }
        if (preg_match('/ALTER TABLE `([^`]+)` ADD COLUMN `([^`]+)`/i', $statement, $match) === 1) {
            $this->columns[$match[1]][$match[2]] = true;
            return 0;
        }
        if (preg_match('/ALTER TABLE `([^`]+)` DROP COLUMN `([^`]+)`/i', $statement, $match) === 1) {
            unset($this->columns[$match[1]][$match[2]]);
            return 0;
        }
        if (preg_match('/ALTER TABLE `([^`]+)` DROP INDEX `([^`]+)`/i', $statement, $match) === 1) {
            unset($this->indexes[$match[1]][$match[2]]);
            return 0;
        }
        if (preg_match('/ALTER TABLE `([^`]+)` DROP FOREIGN KEY `([^`]+)`/i', $statement, $match) === 1) {
            unset($this->foreignKeys[$match[1]][$match[2]]);
            return 0;
        }
        if (preg_match('/^\s*UPDATE\s+/i', $statement) === 1) {
            return 1;
        }
        throw new RuntimeException('Unexpected repair SQL: ' . $statement);
    }
}

$absentPdo = new DatabaseMaintenanceRepairPdo([]);
run_database_maintenance_schema_repair($absentPdo);
assert_database_maintenance_repair(count($absentPdo->executedSql) === 1 && str_contains($absentPdo->executedSql[0], 'CREATE TABLE database_maintenance_audit_log'), 'The maintenance audit table must be created even when thumbnail tables are absent.');
assert_database_maintenance_repair(isset($absentPdo->columns['database_maintenance_audit_log']['removed_identifiers_json']), 'The audit table must store exact removed identifiers.');

$legacyColumns = [
    'id', 'image_id', 'gallery_id', 'size_px', 'format', 'thumbnail_rel_path',
    'source_width', 'source_height', 'source_mime_type', 'source_file_size',
    'source_modified_at', 'source_checksum_sha256', 'source_exif_orientation',
    'source_exif_json', 'status',
];
$partialPdo = new DatabaseMaintenanceRepairPdo([
    'images' => ['id', 'width', 'height', 'display_width'],
    'image_thumbnail_variants' => $legacyColumns,
], [
    'image_thumbnail_variants' => ['image_thumbnail_variants_gallery_index'],
], [
    'image_thumbnail_variants' => ['image_thumbnail_variants_gallery_id_foreign'],
]);
run_database_maintenance_schema_repair($partialPdo);

foreach (['display_width', 'display_height', 'exif_orientation', 'thumbnail_derivative_version', 'thumbnail_metadata_refreshed_at'] as $columnName) {
    assert_database_maintenance_repair(isset($partialPdo->columns['images'][$columnName]), 'Repair must provide images.' . $columnName . '.');
}
assert_database_maintenance_repair(isset($partialPdo->columns['image_thumbnail_variants']['derivative_version']), 'Repair must add derivative_version.');
foreach (['gallery_id', 'thumbnail_rel_path', 'source_width', 'source_height', 'source_mime_type', 'source_file_size', 'source_modified_at', 'source_checksum_sha256', 'source_exif_orientation', 'source_exif_json'] as $columnName) {
    assert_database_maintenance_repair(!isset($partialPdo->columns['image_thumbnail_variants'][$columnName]), 'Repair must remove proven legacy column ' . $columnName . '.');
}
assert_database_maintenance_repair(!isset($partialPdo->indexes['image_thumbnail_variants']['image_thumbnail_variants_gallery_index']), 'Repair must remove the obsolete gallery index.');
assert_database_maintenance_repair(!isset($partialPdo->foreignKeys['image_thumbnail_variants']['image_thumbnail_variants_gallery_id_foreign']), 'Repair must remove the obsolete gallery foreign key.');

$joinedSql = implode("\n", $partialPdo->executedSql);
$geometryCopyPosition = strpos($joinedSql, 'UPDATE images i');
$sourceDropPosition = strpos($joinedSql, 'DROP COLUMN `source_width`');
assert_database_maintenance_repair($geometryCopyPosition !== false && $sourceDropPosition !== false && $geometryCopyPosition < $sourceDropPosition, 'Source geometry must be copied before source columns are dropped.');
assert_database_maintenance_repair(!str_contains(strtoupper($joinedSql), 'DELETE FROM'), 'Schema repair must not delete application rows.');
assert_database_maintenance_repair(!str_contains($joinedSql, 'unlink') && !str_contains($joinedSql, 'galleries/'), 'Schema repair must not touch filesystem media.');

$compactPdo = new DatabaseMaintenanceRepairPdo([
    'database_maintenance_audit_log' => ['id', 'operation_id', 'rule_key', 'table_name', 'category', 'reason', 'identifier_columns_json', 'removed_identifiers_json', 'deleted_count', 'created_at'],
    'images' => ['id', 'width', 'height', 'display_width', 'display_height', 'exif_orientation', 'thumbnail_derivative_version', 'thumbnail_metadata_refreshed_at'],
    'image_thumbnail_variants' => ['id', 'image_id', 'size_px', 'format', 'derivative_version', 'status'],
]);
run_database_maintenance_schema_repair($compactPdo);
$compactAlterStatements = array_values(array_filter($compactPdo->executedSql, static fn (string $sql): bool => str_starts_with(trim($sql), 'ALTER TABLE')));
assert_database_maintenance_repair($compactAlterStatements === [], 'Already compact schemas must not execute DDL.');
assert_database_maintenance_repair(count($compactPdo->executedSql) === 1 && str_contains($compactPdo->executedSql[0], 'UPDATE image_thumbnail_variants'), 'Already compact schemas may only idempotently synchronize derivative versions.');

$beforeSecondPassCount = count($partialPdo->executedSql);
run_database_maintenance_schema_repair($partialPdo);
$secondPassSql = array_slice($partialPdo->executedSql, $beforeSecondPassCount);
$secondPassAlterStatements = array_values(array_filter($secondPassSql, static fn (string $sql): bool => str_starts_with(trim($sql), 'ALTER TABLE')));
assert_database_maintenance_repair($secondPassAlterStatements === [], 'A repaired schema must remain idempotent on retry.');

echo "Database maintenance schema repair tests passed.\n";
