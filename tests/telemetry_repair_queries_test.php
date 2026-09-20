<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/telemetry_repair_queries_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Exercises telemetry repairs using isolated deterministic fixtures.
 *
 * Responsibilities:
 *   - Execute production behavior with isolated inputs
 *   - Fail on privacy, recovery or reporting regressions
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return the fixture PDO without opening any database connection.
     * @return \PDO Result of the documented operation.
     */
    function db(): \PDO { return $GLOBALS['tq_pdo']; }
}
namespace {
    /** Capture SQL execution and supply deliberately empty result sets. */
    final class TelemetryRepairStatement extends PDOStatement {
        /**
         * SQL retained solely by the isolated recording statement.
         * @var string
         */
        private string $sql;
        /**
         * Retain only fixture SQL for assertions.
         * @param string $sql SQL constructed by the production model, retained only in this local fixture.
         * @return void No return value; effects are recorded in the owned state.
         */
        public function __construct(string $sql) { $this->sql = $sql; }
        /**
         * Record binding/execution, rejecting the original escaped-newline defect.
         * @param array<array-key,mixed>|null $params Bound values captured by the fixture, not sent to any database.
         * @return bool Result of the documented operation.
         */
        public function execute(?array $params = null): bool {
            if (str_contains($this->sql, '\n')) { throw new RuntimeException('Literal backslash-n in SQL'); }
            $GLOBALS['tq_queries'][] = ['sql' => $this->sql, 'params' => $params ?? []]; return true;
        }
        /**
         * Return no rows; the fixture tests execution paths, not a SQL engine.
         * @param int $mode PDO fetch mode accepted for signature compatibility.
         * @param scalar|array<array-key,mixed>|object|resource|null $args Arguments accepted for interface compatibility; unused by this fixture.
         * @return list<array<string,mixed>> Result of the documented operation.
         */
        public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }
        /**
         * Return no aggregate row.
         * @param int $mode PDO fetch mode accepted for signature compatibility.
         * @param int $cursorOrientation PDO cursor orientation accepted for signature compatibility.
         * @param int $cursorOffset PDO cursor offset accepted for signature compatibility.
         * @return false Result of the documented operation.
         */
        public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return false; }
        /**
         * Return zero fixture mutations.
         * @return int Result of the documented operation.
         */
        public function rowCount(): int { return 0; }
    }
    /** Never establish a real connection while executing production model code. */
    final class TelemetryRepairPDO extends PDO {
        /**
         * No credentials, extensions or production state are used.
         * @return void No return value; effects are recorded in the owned state.
         */
        public function __construct() {}
        /**
         * Produce a recording statement.
         * @param string $query Model-owned SQL prepared by this fixture.
         * @param array<string,mixed> $options Operation-specific controls; unsupported keys do not change behavior.
         * @return PDOStatement|false Result of the documented operation.
         */
        public function prepare(string $query, array $options = []): PDOStatement|false { return new TelemetryRepairStatement($query); }
    }
    /**
     * Assert a SQL construction/binding contract without claiming live SQL validation.
     * @param bool $ok Whether the asserted invariant holds.
     * @param string $why Diagnostic message emitted if the invariant fails.
     * @return void No return value; effects are recorded in the owned state.
     */
    function tq_assert(bool $ok, string $why): void { if (!$ok) { throw new RuntimeException($why); } }
    $GLOBALS['tq_pdo'] = new TelemetryRepairPDO(); $GLOBALS['tq_queries'] = [];
    require_once dirname(__DIR__) . '/app/models/telemetry.php';
    require_once dirname(__DIR__) . '/app/models/telemetry_diagnostics.php';
    \Gallery\Models\telemetry_model_report_photo_open_origins(7, 'non_bot');
    $origin = end($GLOBALS['tq_queries']);
    tq_assert($origin['params'] === [7] && str_contains($origin['sql'], "IN ('desktop', 'tablet', 'phone')"), 'Origin query must execute with real profiling and strict segment binding.');
    \Gallery\Models\telemetry_model_report_daily_rollup_consistency(7, 'all');
    $ops = array_column(\Gallery\Models\telemetry_model_report_query_profile(), 'operation');
    tq_assert(in_array('rollup_consistency_hourly', $ops, true) && in_array('rollup_consistency_daily', $ops, true), 'Both rollup queries must reach execution through the profiler allowlist.');
    \Gallery\Models\telemetry_model_report_database_fingerprints(30, 999, 'volume');
    $volume = end($GLOBALS['tq_queries'])['sql'];
    tq_assert(str_contains($volume, 'ORDER BY query_count DESC, total_latency_ms DESC') && str_contains($volume, 'LIMIT 100'), 'Volume ranking and bounds must be model-owned.');
    \Gallery\Models\telemetry_model_report_database_fingerprints(30, 30, 'failed');
    tq_assert(str_contains(end($GLOBALS['tq_queries'])['sql'], 'HAVING SUM(failed_count) > 0'), 'Failure evidence must not be hidden by slow-query ordering.');
    \Gallery\Models\telemetry_model_report_cache_phases(7, 'unknown');
    tq_assert(str_contains(end($GLOBALS['tq_queries'])['sql'], "device_type = 'unknown'"), 'Cache detail must keep unknown traffic separate.');
    \Gallery\Models\telemetry_model_report_performance_metrics(30);
    tq_assert(str_contains(end($GLOBALS['tq_queries'])['sql'], 'value_min > 0'), 'Historical invalid page-load buckets must not become zero-ms performance.');
    $count = count($GLOBALS['tq_queries']);
    \Gallery\Models\telemetry_model_delete_older_than('telemetry_hourly_metrics', 'bucket_start', 90);
    tq_assert(count($GLOBALS['tq_queries']) === $count, 'No archival checkpoint means no hourly deletion SQL.');
    \Gallery\Models\telemetry_model_delete_older_than('telemetry_hourly_metrics', 'bucket_start', 90, 3, '2026-06-01');
    $purge = end($GLOBALS['tq_queries']);
    tq_assert($purge['params'] === [90, '2026-06-01'] && str_contains($purge['sql'], 'ORDER BY bucket_start ASC LIMIT 3'), 'Hourly purge must bind its checkpoint and cap oldest-first batches.');
    $bad = false;
    try { \Gallery\Models\telemetry_model_delete_older_than('users', 'created_at', 1); } catch (InvalidArgumentException) { $bad = true; }
    tq_assert($bad, 'Retention must reject arbitrary tables.');
    \Gallery\Models\telemetry_model_report_storage_diagnostics(7, ['telemetry_events' => 7]);
    $overdue = array_filter($GLOBALS['tq_queries'], /**
        * Select captured storage-count queries.
        * @param array{sql:string,params:array<array-key,mixed>} $q Captured execution.
        * @return bool Whether the query counts overdue rows.
        */ static fn(array $q): bool => str_contains($q['sql'], 'AS overdue_rows'));
    tq_assert(count($overdue) === 6, 'Every existing table diagnostic must also measure retention backlog.');
    echo "Telemetry repair query construction/execution fixtures passed (no live SQL engine).\n";
}
