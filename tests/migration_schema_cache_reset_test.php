<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/migration_schema_cache_reset_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies migration-driven invalidation of request-local schema inspection state.
 *
 * Responsibilities:
 *   - Prove cached capability answers survive unrelated data statements
 *   - Prove successful DDL invalidates cached schema inspection results
 *   - Prove duplicate-DDL replay also invalidates potentially stale results
 *   - Protect cache invalidation after migration repair callbacks
 *   - Remain executable without a live MySQL or MariaDB server
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - A minimal PDO subclass exercises the real migration statement boundary.
 *   - The schema inspection executor seam supplies deterministic metadata state.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-08-12
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/services/schema_inspection.php';
require_once __DIR__ . '/../app/migrations.php';

use function Gallery\Core\apply_migration_statement;
use function Gallery\Services\schema_inspection_column;
use function Gallery\Services\schema_inspection_set_query_executor_for_tests;

/**
 * Minimal PDO double for migration statement execution.
 */
final class MigrationSchemaCacheResetPdo extends PDO
{
    public bool $duplicateNext = false;

    /**
     * Construct without opening a real database connection.
     */
    public function __construct()
    {
    }

    /**
     * Execute a simulated migration statement.
     *
     * @param string $statement SQL statement.
     * @return int|false Simulated affected-row count.
     */
    public function exec(string $statement): int|false
    {
        if ($this->duplicateNext) {
            $this->duplicateNext = false;
            $exception = new PDOException('Duplicate column name');
            $exception->errorInfo = ['42S21', 1060, 'Duplicate column name'];
            throw $exception;
        }

        return 0;
    }
}

/**
 * Throw when one migration cache expectation fails.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual Actual value.
 * @param string $label Assertion label.
 */
function migration_cache_assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

$metadataExists = false;
$queryCount = 0;
schema_inspection_set_query_executor_for_tests(
    static function (string $objectType, string $table, string $object) use (&$metadataExists, &$queryCount): bool {
        $queryCount++;
        return $metadataExists;
    }
);

$pdo = new MigrationSchemaCacheResetPdo();

// Establish a cached "missing" result, then change the simulated metadata source.
$first = schema_inspection_column('images', 'nsfw_enabled');
migration_cache_assert_same('missing', $first['state'] ?? null, 'initial missing state');
migration_cache_assert_same(1, $queryCount, 'initial inspection query count');

$metadataExists = true;
$cached = schema_inspection_column('images', 'nsfw_enabled');
migration_cache_assert_same('missing', $cached['state'] ?? null, 'request-local cache reuse before DDL');
migration_cache_assert_same(1, $queryCount, 'cached inspection query count');

// Data-only statements must not invalidate schema capability answers.
apply_migration_statement($pdo, "UPDATE app_settings SET setting_value = '1' WHERE setting_key = 'example'");
$dataOnlyCached = schema_inspection_column('images', 'nsfw_enabled');
migration_cache_assert_same('missing', $dataOnlyCached['state'] ?? null, 'data-only statement keeps cache');
migration_cache_assert_same(1, $queryCount, 'data-only statement query count');

// Successful DDL must invalidate the cache so same-process validation sees the new schema.
$ddlDiagnostic = apply_migration_statement(
    $pdo,
    'ALTER TABLE images ADD COLUMN nsfw_enabled TINYINT(1) NOT NULL DEFAULT 0'
);
migration_cache_assert_same('applied', $ddlDiagnostic['status'] ?? null, 'successful DDL status');
$afterDdl = schema_inspection_column('images', 'nsfw_enabled');
migration_cache_assert_same('available', $afterDdl['state'] ?? null, 'post-DDL reinspection state');
migration_cache_assert_same(2, $queryCount, 'post-DDL reinspection query count');

// Duplicate-DDL replay is also a schema boundary and must clear potentially stale capability state.
$metadataExists = false;
$stillCached = schema_inspection_column('images', 'nsfw_enabled');
migration_cache_assert_same('available', $stillCached['state'] ?? null, 'cached available state before duplicate replay');
migration_cache_assert_same(2, $queryCount, 'cached available query count');

$pdo->duplicateNext = true;
$duplicateDiagnostic = apply_migration_statement(
    $pdo,
    'ALTER TABLE images ADD COLUMN nsfw_enabled TINYINT(1) NOT NULL DEFAULT 0'
);
migration_cache_assert_same('duplicate_ddl_replayed', $duplicateDiagnostic['status'] ?? null, 'duplicate DDL replay status');
$afterDuplicateReplay = schema_inspection_column('images', 'nsfw_enabled');
migration_cache_assert_same('missing', $afterDuplicateReplay['state'] ?? null, 'duplicate replay reinspection state');
migration_cache_assert_same(3, $queryCount, 'duplicate replay reinspection query count');

// Keep the migration-runner callback integration explicit. Repair callbacks may execute DDL themselves.
$migrationSource = (string) file_get_contents(__DIR__ . '/../app/migrations.php');
migration_cache_assert_same(
    1,
    preg_match('/\$after\(\$pdo\);\s*migration_reset_schema_inspection_cache\(\);/s', $migrationSource),
    'repair callback cache invalidation'
);
migration_cache_assert_same(
    1,
    preg_match('/CREATE TABLE IF NOT EXISTS schema_migrations.*?migration_reset_schema_inspection_cache\(\);/s', $migrationSource),
    'migration table bootstrap cache invalidation'
);
migration_cache_assert_same(
    1,
    preg_match('/db_schema_helper_reset_request_cache/', $migrationSource),
    'legacy schema helper cache invalidation'
);
migration_cache_assert_same(
    1,
    preg_match('/migration_reset_app_settings_cache/', $migrationSource),
    'application settings cache invalidation hook'
);
migration_cache_assert_same(
    1,
    preg_match("/object.*app_settings.*INSERT.*UPDATE.*DELETE.*REPLACE.*TRUNCATE/s", $migrationSource),
    'application settings DML cache invalidation'
);

schema_inspection_set_query_executor_for_tests(null);
echo "Migration schema cache reset tests passed.\n";
