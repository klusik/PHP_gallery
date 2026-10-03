<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/navdata_background_refresh_test.php
 * Module Type: Regression Test
 * Purpose: Verify bounded atomic navdata persistence and periodic refresh policy offline.
 * Responsibilities: Exercise batching, rollback, weekly freshness, retry backoff and concurrent ownership.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Core {
    /** Return a disposable model connection. @return \NavdataDatabase Fixture connection. */
    function db(): \NavdataDatabase { return $GLOBALS['navdata_database']; }
}
namespace Gallery\Services {
    /** Read confined freshness state. @param string $key Setting identifier. @param string $fallback Default value. @return string Fixture value. */
    function app_setting(string $key, string $fallback): string { return (string) ($GLOBALS['navdata_settings'][$key] ?? $fallback); }
}
namespace {
    /** Capture one model SQL statement without connecting to a database. */
    final class NavdataStatement {
        /** Prepared SQL. @var string */
        public string $sql;
        /** Assign the prepared statement. @param string $sql Model SQL. @return void Initializes the fixture. */
        public function __construct(string $sql) { $this->sql = $sql; }
        /** Capture one atomic import batch. @param list<string|float> $parameters Bound row values. @return bool Successful fixture execution. */
        public function execute(array $parameters): bool {
            $database = $GLOBALS['navdata_database'];
            $database->executions[] = ['sql' => $this->sql, 'parameters' => $parameters];
            if ($database->failAt === count($database->executions)) throw new \RuntimeException('Fixture write unavailable');
            return true;
        }
        /** Report stale rows removed. @return int Fixture deletion count. */
        public function rowCount(): int { return 3; }
    }
    /** Record commit/rollback boundaries of the production model. */
    final class NavdataDatabase {
        /** Executed batches. @var list<array{sql:string,parameters:list<string|float>}> */
        public array $executions = [];
        /** Active transaction. @var bool */
        public bool $transaction = false;
        /** Successful commit. @var bool */
        public bool $committed = false;
        /** Explicit rollback. @var bool */
        public bool $rolledBack = false;
        /** Failing batch index, zero disables failure. @var int */
        public int $failAt = 0;
        /** Begin the snapshot transaction. @return void Records entry. */
        public function beginTransaction(): void { $this->transaction = true; }
        /** Prepare a production statement. @param string $sql Model SQL. @return NavdataStatement Recorded statement. */
        public function prepare(string $sql): NavdataStatement { return new NavdataStatement($sql); }
        /** Complete the atomic snapshot. @return void Records commit. */
        public function commit(): void { $this->committed = true; $this->transaction = false; }
        /** Return current transaction ownership. @return bool Active transaction. */
        public function inTransaction(): bool { return $this->transaction; }
        /** Undo all partial batches. @return void Records rollback. */
        public function rollBack(): void { $this->rolledBack = true; $this->transaction = false; }
    }
    /** Require a persistence/policy behavior. @param bool $value Expected behavior. @param string $message Failure description. @return void Throws on failure. */
    function navdata_assert(bool $value, string $message): void { if (!$value) throw new \RuntimeException($message); }
    require_once __DIR__ . '/../app/models/flight_maps.php';
    require_once __DIR__ . '/../app/services/flight_maps/navdata_update.php';
    $GLOBALS['navdata_database'] = new NavdataDatabase();
    $row = ['ident'=>'LKPR', 'kind'=>'airport', 'region'=>'CZ', 'latitude'=>50.1, 'longitude'=>14.3, 'source'=>'ourairports', 'cycle'=>'fixture', 'created_at'=>'2026-10-02 00:00:00', 'updated_at'=>'2026-10-02 00:00:00'];
    $deleted = \Gallery\Models\flight_maps_model_replace_navdata(array_fill(0, 401, $row), 'ourairports', $row['updated_at']);
    $database = $GLOBALS['navdata_database'];
    navdata_assert(count($database->executions) === 4 && $database->committed && $deleted === 3, '401 rows require three batches and one atomic stale-row removal.');
    navdata_assert(count($database->executions[0]['parameters']) === 1800 && count($database->executions[2]['parameters']) === 9, 'Batch parameters stay bounded and retain the final partial batch.');
    navdata_assert(str_starts_with($database->executions[3]['sql'], 'DELETE') && $database->executions[3]['parameters'] === ['ourairports', $row['updated_at']], 'Only stale rows belonging to this source may be deleted after imports.');
    $GLOBALS['navdata_database'] = new NavdataDatabase();
    $GLOBALS['navdata_database']->failAt = 2;
    try { \Gallery\Models\flight_maps_model_replace_navdata(array_fill(0, 401, $row), 'ourairports', $row['updated_at']); throw new \LogicException('Expected failure'); }
    catch (\RuntimeException $error) { navdata_assert($GLOBALS['navdata_database']->rolledBack && !$GLOBALS['navdata_database']->committed && count($GLOBALS['navdata_database']->executions) === 2, 'Failure must roll back earlier batches before stale rows can be removed.'); }
    $GLOBALS['navdata_settings'] = ['flight_map_navdata_last_update' => date('Y-m-d H:i:s')];
    navdata_assert(!\Gallery\Services\flight_map_navdata_refresh_due(), 'Fresh snapshots do not trigger imports on reload.');
    $GLOBALS['navdata_settings'] = ['flight_map_navdata_last_update' => date('Y-m-d H:i:s', time() - 8 * 86400)];
    navdata_assert(\Gallery\Services\flight_map_navdata_refresh_due(), 'Older snapshots admit weekly asynchronous refresh.');
    $GLOBALS['navdata_settings']['flight_map_navdata_last_attempt'] = (string) time();
    navdata_assert(!\Gallery\Services\flight_map_navdata_refresh_due(), 'Failed or active imports receive hourly retry backoff.');
    navdata_assert(\Gallery\Services\flight_map_navdata_refresh(true)['state'] === 'current', 'Passive freshness checks must skip network and schema work inside backoff.');
    echo "Navdata background refresh: PASS\n";
}
