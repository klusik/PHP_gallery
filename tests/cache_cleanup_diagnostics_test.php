<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/cache_cleanup_diagnostics_test.php
 * Module Type: Regression Test
 * Purpose: Verify safe log receipts and installed request-ID compatibility without a live database.
 * Responsibilities: Exercise real log owners with a rejecting database double and bounded diagnostic traces.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Supply the isolated log database double.
     * @return \CacheDiagnosticDatabase Database double with query/prepare methods; never a live connection.
     */
    function db(): \CacheDiagnosticDatabase { return $GLOBALS['cache_diagnostic_db']; }
    /**
     * Use a fixed authenticated administrator in the isolated fixture.
     * @return array{id:int} Fixture administrator identity.
     */
    function current_user(): array { return ['id' => 1]; }
    /**
     * Supply a deterministic log timestamp.
     * @return string SQL-compatible fixture timestamp.
     */
    function now_sql(): string { return '2026-10-14 12:00:00'; }
}

namespace {
    $GLOBALS['cache_diagnostic_rows'] = [];
    $GLOBALS['cache_diagnostic_refuse'] = false;
    /** Supply isolated schema queries and rejecting insert statements to the real log model. */
    class CacheDiagnosticDatabase {
        /**
         * Expose the installed log columns without querying a server.
         * @param string $sql Model-owned schema query.
         * @return CacheDiagnosticColumns Statement double whose fetchAll returns column records.
         */
        public function query(string $sql): CacheDiagnosticColumns {
            return new CacheDiagnosticColumns();
        }
        /**
         * Prepare a log insert double retaining its actual field order.
         * @param string $sql Model-owned insert statement.
         * @return CacheDiagnosticInsert Statement double with an execute method.
         */
        public function prepare(string $sql): CacheDiagnosticInsert {
            $statement = new CacheDiagnosticInsert();
            preg_match('/admin_logs \(([^)]+)\)/', $sql, $match);
            $statement->fields = explode(', ', $match[1]);
            return $statement;
        }
    }
    /** Expose installed field names without contacting a database. */
    class CacheDiagnosticColumns {
        /**
         * Return fields available in the fixture log schema.
         * @return list<array{Field:string}> Installed log field names.
         */
        public function fetchAll(): array {
            return array_map(static fn (string $field): array => ['Field' => $field],
                ['status','user_id','level','category','severity','event_key','message','subject_type','subject_id','request_id','route_name','context_json','created_at']);
        }
    }
    /** Emulate the installed request-ID width and capture successfully persisted rows. */
    class CacheDiagnosticInsert {
        public array $fields = [];
        /**
         * Capture valid rows or emulate the observed MySQL width failure.
         * @param list<int|string|null> $params Values matching the prepared field order.
         * @return bool True for accepted rows; throws PDOException on rejection.
         */
        public function execute(array $params): bool {
            $row = array_combine($this->fields, $params);
            if ($GLOBALS['cache_diagnostic_refuse'] || strlen((string) ($row['request_id'] ?? '')) > 26) {
                $exception = new \PDOException("Data too long for column 'request_id'; PRIVATE_SQL_TOKEN", 22001);
                $exception->errorInfo = ['22001', 1406, 'PRIVATE_SQL_TOKEN'];
                throw $exception;
            }
            $GLOBALS['cache_diagnostic_rows'][] = $row;
            return true;
        }
    }
    $GLOBALS['cache_diagnostic_db'] = new CacheDiagnosticDatabase();
    require_once __DIR__ . '/../app/services/cache_cleanup_diagnostics.php';

    /**
     * Stop the fixture when diagnostic safety or persistence regresses.
     * @param bool $condition Required invariant.
     * @param string $message Failure explanation without runtime secrets.
     * @return void Throws when the required invariant fails.
     */
    function assert_cache_diagnostic(bool $condition, string $message): void {
        if (!$condition) throw new \RuntimeException($message);
    }
    $runId = str_repeat('a', 32);
    $event = \Gallery\Services\cache_cleanup_diagnostic_event($runId, 'request_received', ['secret' => 'PRIVATE_TOKEN']);
    $row = $GLOBALS['cache_diagnostic_rows'][0];
    assert_cache_diagnostic($event['admin_log_written'] === true, 'Compatible log insert was not confirmed.');
    assert_cache_diagnostic(strlen($row['request_id']) === 26 && json_decode($row['context_json'], true)['run_id'] === $runId, 'Full correlation ID or installed column compatibility was lost.');
    $stored = json_decode($row['context_json'], true);
    assert_cache_diagnostic(!array_key_exists('admin_log_written', $stored) && !array_key_exists('log_failure_code', $stored), 'Persisted row contains a provisional insertion failure receipt.');
    assert_cache_diagnostic(!str_contains(json_encode($event), 'PRIVATE_TOKEN'), 'Unsupported secret context escaped.');
    $GLOBALS['cache_diagnostic_refuse'] = true;
    $failed = \Gallery\Services\cache_cleanup_diagnostic_event($runId, 'skipped', ['reason' => 'central_maintenance_active']);
    assert_cache_diagnostic($failed['admin_log_written'] === false && $failed['log_sqlstate'] === '22001' && $failed['log_driver_code'] === 1406 && $failed['log_field'] === 'request_id', 'Log rejection lost its safe diagnostic receipt.');
    assert_cache_diagnostic(!str_contains(json_encode($failed), 'PRIVATE_SQL_TOKEN'), 'Raw database error escaped.');
    $GLOBALS['cache_diagnostic_refuse'] = false;
    for ($index = 0; $index < 30; $index++) {
        \Gallery\Services\cache_cleanup_diagnostic_event($runId, 'progress', ['visited' => $index]);
    }
    $trace = \Gallery\Services\cache_cleanup_diagnostic_buffer($runId);
    assert_cache_diagnostic(count($trace) === 24 && $trace[0]['stage'] === 'request_received' && end($trace)['visited'] === 29, 'Bounded trace lost its start or recent evidence.');
    $safe = \Gallery\Services\cache_cleanup_exception_diagnostic(new \RuntimeException('PRIVATE_PATH_AND_PASSWORD'));
    assert_cache_diagnostic($safe['failure_code'] === 'owner_exception' && $safe['exception_site'] === 'external' && !str_contains(json_encode($safe), 'PRIVATE_PATH'), 'Exception classification exposed private details.');
    echo "PASS safe cache-cleanup diagnostics and installed log schema compatibility\n";
}
