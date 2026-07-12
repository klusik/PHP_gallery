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
 *   - Confirm the repair executes before the former runner records the migration
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
 *   2026-07-12
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
    }

    /**
     * Minimal PDO implementation exercising migration transaction handling.
     */
    final class MigrationLegacyRunnerPdo extends PDO
    {
        private bool $transactionActive = false;
        public int $commitCount = 0;
        public int $rollbackCount = 0;

        public function __construct()
        {
        }

        /**
         * @param string $query SQL query.
         * @param int|null $fetchMode Fetch mode.
         * @param mixed ...$fetchModeArgs Fetch arguments.
         * @return PDOStatement|false Statement result.
         */
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
        'Every migration must execute exactly one repair under the former runner.'
    );

    echo "Legacy migration runner compatibility tests passed.\n";
}
