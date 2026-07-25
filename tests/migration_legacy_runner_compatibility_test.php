<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/migration_legacy_runner_compatibility_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies that current repair migrations remain safe under the former SQL-only runner.
 *
 * Responsibilities:
 *   - Confirm the current migration loader receives an after callback
 *   - Simulate the former direct-require runner with a PDO variable in scope
 *   - Confirm repair migrations return only SQL-statement lists to the former runner
 *   - Confirm repairs execute before the former runner records their migrations
 *   - Confirm the conditional database-maintenance repair safely no-ops when its table is absent
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

namespace Gallery\Services {
    /**
     * Test shim recording migration-triggered public-path repairs.
     *
     * @param \PDO $pdo Database connection.
     * @return int Number of repaired galleries.
     */
    function regenerate_gallery_public_paths(\PDO $pdo): int
    {
        $GLOBALS['migration_legacy_repair_calls'] = (int) ($GLOBALS['migration_legacy_repair_calls'] ?? 0) + 1;
        return 1;
    }
}

namespace {
    use function Gallery\Core\load_migration_definition;

    require_once __DIR__ . '/../app/migration_definitions.php';

    /**
     * Minimal PDO statement used by the migration compatibility test.
     */
    final class MigrationLegacyRunnerStatement extends PDOStatement
    {
        /** @var mixed */
        private $columnValue;

        /** @var array<int,array<string,mixed>> */
        private array $rows;

        /**
         * @param mixed $columnValue Scalar fetchColumn result.
         * @param array<int,array<string,mixed>> $rows Row set for fetchAll.
         */
        public function __construct($columnValue = null, array $rows = [])
        {
            $this->columnValue = $columnValue;
            $this->rows = $rows;
        }

        /**
         * @param int $column Column index.
         * @return mixed Scalar result.
         */
        public function fetchColumn(int $column = 0): mixed
        {
            return $this->columnValue;
        }

        /**
         * @param int $mode Fetch mode.
         * @param mixed ...$args Additional fetch arguments.
         * @return array<int,array<string,mixed>> Rows.
         */
        public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
        {
            return $this->rows;
        }

        /**
         * Accept parameters for information_schema compatibility probes.
         *
         * @param array<int|string,mixed>|null $params Bound values.
         */
        public function execute(?array $params = null): bool
        {
            return true;
        }
    }

    /**
     * Dynamic prepared statement used for information_schema compatibility probes.
     */
    final class MigrationLegacyRunnerPreparedStatement extends PDOStatement
    {
        private MigrationLegacyRunnerPdo $pdo;
        private string $query;

        /** @var array<int|string,mixed> */
        private array $params = [];

        /**
         * @param MigrationLegacyRunnerPdo $pdo Test database connection.
         * @param string $query Prepared SQL query.
         */
        public function __construct(MigrationLegacyRunnerPdo $pdo, string $query)
        {
            $this->pdo = $pdo;
            $this->query = $query;
        }

        /**
         * Store bound values for a later information_schema probe.
         *
         * @param array<int|string,mixed>|null $params Bound values.
         */
        public function execute(?array $params = null): bool
        {
            $this->params = $params ?? [];
            return true;
        }

        /**
         * Resolve one simulated information_schema result.
         *
         * @param int $column Column index.
         * @return mixed Scalar result.
         */
        public function fetchColumn(int $column = 0): mixed
        {
            return $this->pdo->informationSchemaProbe($this->query, array_values($this->params));
        }
    }

    /**
     * Minimal PDO implementation exercising migration transaction handling.
     */
    final class MigrationLegacyRunnerPdo extends PDO
    {
        private bool $transactionActive = false;
        public int $commitCount = 0;
        public int $rollbackCount = 0;
        public bool $auditTableCreated = false;

        public function __construct()
        {
        }

        /**
         * Prepare a dynamic information_schema compatibility probe.
         *
         * @param string $query SQL query.
         * @param array<int|string,mixed> $options Driver options.
         */
        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            if (str_contains($query, 'information_schema.')) {
                return new MigrationLegacyRunnerPreparedStatement($this, $query);
            }
            throw new RuntimeException('Unexpected migration test prepare: ' . $query);
        }

        /**
         * Resolve one simulated information_schema lookup.
         *
         * @param string $query Prepared SQL query.
         * @param array<int,mixed> $params Bound values.
         * @return bool Whether the requested schema object exists.
         */
        public function informationSchemaProbe(string $query, array $params): bool
        {
            if (str_contains($query, 'information_schema.TABLES')) {
                $tableName = (string) ($params[0] ?? '');
                return $tableName === 'database_maintenance_audit_log' && $this->auditTableCreated;
            }

            if (str_contains($query, 'information_schema.COLUMNS')) {
                $tableName = (string) ($params[0] ?? '');
                $columnName = (string) ($params[1] ?? '');
                $auditColumns = [
                    'id', 'operation_id', 'rule_key', 'table_name', 'category', 'reason',
                    'identifier_columns_json', 'removed_identifiers_json', 'deleted_count', 'created_at',
                ];
                return $tableName === 'database_maintenance_audit_log'
                    && $this->auditTableCreated
                    && in_array($columnName, $auditColumns, true);
            }

            if (str_contains($query, 'information_schema.STATISTICS') || str_contains($query, 'information_schema.TABLE_CONSTRAINTS')) {
                return false;
            }

            throw new RuntimeException('Unexpected information_schema probe: ' . $query);
        }

        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
        {
            if (str_contains($query, 'COUNT(*) FROM galleries')) {
                return new MigrationLegacyRunnerStatement(1);
            }
            if (str_contains($query, "folder_path LIKE '%/%'")) {
                return new MigrationLegacyRunnerStatement(null, [[
                    'id' => 2,
                    'folder_path' => 'Parent/Child',
                    'url_path' => 'parent/child',
                ]]);
            }
            throw new RuntimeException('Unexpected migration test query: ' . $query);
        }

        /**
         * Accept the conditional audit-table CREATE used by the maintenance repair.
         */
        public function exec(string $statement): int|false
        {
            if (str_contains($statement, 'CREATE TABLE database_maintenance_audit_log')) {
                $this->auditTableCreated = true;
                return 0;
            }
            throw new RuntimeException('Unexpected migration test exec: ' . $statement);
        }

        public function beginTransaction(): bool
        {
            $this->transactionActive = true;
            return true;
        }

        public function inTransaction(): bool
        {
            return $this->transactionActive;
        }

        public function commit(): bool
        {
            $this->transactionActive = false;
            $this->commitCount++;
            return true;
        }

        public function rollBack(): bool
        {
            $this->transactionActive = false;
            $this->rollbackCount++;
            return true;
        }
    }

    /**
     * Throw when a compatibility expectation fails.
     *
     * @param bool $condition Condition value.
     * @param string $label Assertion label.
     */
    function assert_migration_legacy_compatibility(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new RuntimeException($label);
        }
    }

    $migrationFiles = [
        __DIR__ . '/../database/migrations/202607120002_harden_gallery_public_paths.php',
        __DIR__ . '/../database/migrations/202607120003_restore_hierarchical_gallery_public_paths.php',
        __DIR__ . '/../database/migrations/202607120004_verify_gallery_public_paths_after_runner_upgrade.php',
    ];

    $GLOBALS['migration_legacy_repair_calls'] = 0;
    foreach ($migrationFiles as $migrationFile) {
        $definition = load_migration_definition($migrationFile);
        assert_migration_legacy_compatibility($definition['statements'] === [], basename($migrationFile) . ' must not expose non-SQL values as statements.');
        assert_migration_legacy_compatibility(is_callable($definition['after']), basename($migrationFile) . ' must expose a current-runner repair callback.');

        $pdo = new MigrationLegacyRunnerPdo();
        $legacyStatements = require $migrationFile;
        assert_migration_legacy_compatibility($legacyStatements === [], basename($migrationFile) . ' must return an empty SQL list to the former runner.');
        assert_migration_legacy_compatibility($pdo->commitCount === 1, basename($migrationFile) . ' must commit its legacy-runner repair.');
        assert_migration_legacy_compatibility($pdo->rollbackCount === 0, basename($migrationFile) . ' must not roll back a successful repair.');
    }

    assert_migration_legacy_compatibility(
        $GLOBALS['migration_legacy_repair_calls'] === count($migrationFiles),
        'Every public-path migration must execute exactly one repair under the former runner.'
    );

    $databaseMaintenanceMigration = __DIR__ . '/../database/migrations/202607250001_database_maintenance_schema_repair.php';
    $definition = load_migration_definition($databaseMaintenanceMigration);
    assert_migration_legacy_compatibility($definition['statements'] === [], 'Database maintenance repair must expose no unconditional SQL statements.');
    assert_migration_legacy_compatibility(is_callable($definition['after']), 'Database maintenance repair must expose a current-runner callback.');
    $pdo = new MigrationLegacyRunnerPdo();
    $legacyStatements = require $databaseMaintenanceMigration;
    assert_migration_legacy_compatibility($legacyStatements === [], 'Database maintenance repair must return an empty SQL list to the former runner.');
    assert_migration_legacy_compatibility($pdo->auditTableCreated, 'Database maintenance repair must create its transactional audit table under the former runner.');
    assert_migration_legacy_compatibility($pdo->commitCount === 0 && $pdo->rollbackCount === 0, 'The conditional repair must not start an unnecessary transaction.');

    echo "Legacy migration runner compatibility tests passed.\n";
}
