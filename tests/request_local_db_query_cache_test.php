<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/request_local_db_query_cache_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Verifies request-local coalescing of repeated settings and legacy schema metadata queries.
 *
 * Responsibilities:
 *   - Prove many app_setting() keys use one settings-table preload
 *   - Prove repeated db_column_exists()/db_table_exists() checks hit PDO once per identity
 *   - Prove gallery filename capability checks reuse the shared schema helper cache
 *   - Prove migration-style cache reset makes the next capability check re-query metadata
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return the isolated PDO fixture used by this regression test.
     */
    function db(): \PDO
    {
        return $GLOBALS['query_cache_test_pdo'];
    }
}

namespace {
    require_once __DIR__ . '/../app/services/app_settings.php';
    require_once __DIR__ . '/../app/services/database_helpers.php';
    require_once __DIR__ . '/../app/services/gallery_display.php';

    use Gallery\Services;

    /**
     * Minimal PDOStatement carrying deterministic in-memory rows.
     */
    final class RequestLocalQueryCacheStatement extends PDOStatement
    {
        /** @var array<int,array<string,mixed>> */
        private array $rows;
        private int $cursor = 0;

        /**
         * Construct one deterministic row set without a real database handle.
         *
         * @param array<int,array<string,mixed>> $rows Fixture rows.
         */
        public function __construct(array $rows)
        {
            $this->rows = array_values($rows);
        }

        /**
         * Simulate successful statement execution.
         */
        public function execute(?array $params = null): bool
        {
            return true;
        }

        /**
         * Fetch one fixture row.
         */
        public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
        {
            return $this->rows[$this->cursor++] ?? false;
        }

        /**
         * Fetch every fixture row.
         *
         * @return array<int,array<string,mixed>> Fixture rows.
         */
        public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
        {
            return $this->rows;
        }

        /**
         * Fetch the first value from the next fixture row.
         */
        public function fetchColumn(int $column = 0): mixed
        {
            $row = $this->rows[$this->cursor++] ?? null;
            if (!is_array($row)) {
                return false;
            }
            $values = array_values($row);
            return $values[$column] ?? false;
        }
    }

    /**
     * Minimal PDO fixture that counts metadata/settings queries.
     */
    final class RequestLocalQueryCachePdo extends PDO
    {
        /** @var array<int,string> */
        public array $queries = [];

        /**
         * Construct without opening a database connection.
         */
        public function __construct()
        {
        }

        /**
         * Execute one deterministic query and record its SQL text.
         */
        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
        {
            $this->queries[] = $query;
            if ($query === 'SELECT setting_key, setting_value FROM app_settings') {
                return new RequestLocalQueryCacheStatement([
                    ['setting_key' => 'alpha', 'setting_value' => 'A'],
                    ['setting_key' => 'beta', 'setting_value' => 'B'],
                ]);
            }
            if (preg_match("/SHOW COLUMNS FROM `?([A-Za-z0-9_]+)`? LIKE '([^']+)'/", $query, $match) === 1) {
                return new RequestLocalQueryCacheStatement([
                    ['Field' => $match[2]],
                ]);
            }
            if (str_starts_with($query, 'SHOW TABLES LIKE ')) {
                return new RequestLocalQueryCacheStatement([['table' => 'galleries']]);
            }
            return new RequestLocalQueryCacheStatement([]);
        }

        /**
         * Return a deterministic quoted SQL literal for table-existence checks.
         */
        public function quote(string $string, int $type = PDO::PARAM_STR): string|false
        {
            return "'" . str_replace("'", "''", $string) . "'";
        }
    }

    /**
     * Throw when one request-local query cache expectation fails.
     */
    function request_local_query_cache_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $pdo = new RequestLocalQueryCachePdo();
    $GLOBALS['query_cache_test_pdo'] = $pdo;
    Services\app_settings_reset_request_cache();
    Services\db_schema_helper_reset_request_cache();

    request_local_query_cache_assert(Services\app_setting('alpha', '') === 'A', 'First setting preload returned the wrong value.');
    request_local_query_cache_assert(Services\app_setting('beta', '') === 'B', 'Second setting did not reuse the preloaded request cache.');
    request_local_query_cache_assert(Services\app_setting('missing', 'fallback') === 'fallback', 'Missing preloaded setting did not return its fallback.');
    $settingsQueries = array_values(array_filter($pdo->queries, static fn (string $query): bool => str_contains($query, 'FROM app_settings')));
    request_local_query_cache_assert(count($settingsQueries) === 1, 'Multiple setting keys caused more than one app_settings SELECT in one request.');

    $beforeColumns = count($pdo->queries);
    request_local_query_cache_assert(Services\db_column_exists('galleries', 'show_filenames'), 'Column fixture unexpectedly reported missing.');
    request_local_query_cache_assert(Services\db_column_exists('galleries', 'show_filenames'), 'Cached column fixture unexpectedly changed.');
    request_local_query_cache_assert(count($pdo->queries) === $beforeColumns + 1, 'Repeated identical column capability check re-queried the database.');

    $beforeDisplay = count($pdo->queries);
    request_local_query_cache_assert(Services\gallery_filename_display_schema_ready(), 'Gallery filename capability unexpectedly reported missing.');
    request_local_query_cache_assert(Services\gallery_filename_display_schema_ready(), 'Gallery filename capability cache unexpectedly changed.');
    request_local_query_cache_assert(count($pdo->queries) === $beforeDisplay, 'Gallery filename capability bypassed the already-populated shared column cache.');

    $beforeTable = count($pdo->queries);
    request_local_query_cache_assert(Services\db_table_exists('galleries'), 'Table fixture unexpectedly reported missing.');
    request_local_query_cache_assert(Services\db_table_exists('galleries'), 'Cached table fixture unexpectedly changed.');
    request_local_query_cache_assert(count($pdo->queries) === $beforeTable + 1, 'Repeated identical table capability check re-queried the database.');

    Services\db_schema_helper_reset_request_cache();
    $beforeResetQuery = count($pdo->queries);
    request_local_query_cache_assert(Services\db_column_exists('galleries', 'show_filenames'), 'Column capability failed after request-cache reset.');
    request_local_query_cache_assert(count($pdo->queries) === $beforeResetQuery + 1, 'Schema helper reset did not force a fresh metadata query.');

    echo "Request-local DB query cache tests passed.\n";
}
