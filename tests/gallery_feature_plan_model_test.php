<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_feature_plan_model_test.php
 * Module Type: Regression Test
 * Purpose: Verify feature-plan isolation, locking and atomic boundaries without a database.
 * Responsibilities: Exercise the production model using a recording PDO with no connection.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Core {
    /** Return the disconnected recording connection used by this model fixture.
     * @return \PDO Test connection that never opens a database.
     */
    function db(): \PDO { return $GLOBALS['feature_model_connection']; }
}
namespace {
    /** Record transaction and SQL ordering without opening a PDO connection. */
    final class FeaturePlanRecordingPdo extends \PDO
    {
        /** @var list<string> Observed model boundaries in exact execution order. */
        public array $events = [];
        /** @var bool Whether the fake connection currently owns a transaction. */
        public bool $active = false;
        /** Construct a disconnected PDO test double.
         * @return void Opens no connection and reads no configuration.
         */
        public function __construct() {}
        /** Observe the current transaction state.
         * @return bool Whether a fixture transaction is active.
         */
        public function inTransaction(): bool { return $this->active; }
        /** Record the model-owned isolation directive.
         * @param string $statement SQL directive selected by the real model.
         * @return int Synthetic affected row count.
         */
        public function exec(string $statement): int|false { $this->events[] = $statement; return 0; }
        /** Record transaction start after isolation selection.
         * @return bool Successful synthetic begin.
         */
        public function beginTransaction(): bool { $this->events[] = 'begin'; $this->active = true; return true; }
        /** Record committed completion.
         * @return bool Successful synthetic commit.
         */
        public function commit(): bool { $this->events[] = 'commit'; $this->active = false; return true; }
        /** Record failure rollback.
         * @return bool Successful synthetic rollback.
         */
        public function rollBack(): bool { $this->events[] = 'rollback'; $this->active = false; return true; }
        /** Capture the real bounded snapshot query without executing SQL.
         * @param string $query Model-selected SQL statement.
         * @param int|null $fetchMode Optional PDO fetch mode.
         * @param mixed ...$fetchModeArgs Optional PDO driver fetch arguments.
         * @source-contract-opaque-param $fetchModeArgs Unused inherited PDO compatibility arguments are deliberately ignored by this disconnected fixture.
         * @return FeaturePlanRecordingStatement|false Disconnected result whose fetchAll() supplies the explicit fixture snapshot row shape.
         */
        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false { $this->events[] = $query; return new FeaturePlanRecordingStatement(); }
    }
    /** Supply deterministic non-secret rows through the native PDOStatement contract. */
    final class FeaturePlanRecordingStatement extends \PDOStatement
    {
        /** Create an isolated result object without native driver resources.
         * @return void Performs no database operation.
         */
        public function __construct() {}
        /** Return the disposable snapshot shape to the real model.
         * @param int $mode Requested fetch mode.
         * @param mixed ...$args Optional PDO fetch arguments.
         * @source-contract-opaque-param $args Unused inherited PDO compatibility arguments are deliberately ignored by this disconnected fixture.
         * @return list<array{id:int,parent_id:null,title:string,folder_path:string,sort_order:int,edit_revision:int,show_filenames:int}> One deterministic non-secret prepared row.
         */
        public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array { return [['id'=>1,'parent_id'=>null,'title'=>'Fixture','folder_path'=>'fixture','sort_order'=>1,'edit_revision'=>1,'show_filenames'=>0]]; }
    }
    /** Require a meaningful model boundary invariant.
     * @param bool $condition Required predicate.
     * @param string $message Safe failure context.
     * @return void Throws if the model contract regresses.
     */
    function feature_model_assert(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }
    require dirname(__DIR__) . '/app/models/gallery_feature_plans.php';
    $connection = new FeaturePlanRecordingPdo(); $GLOBALS['feature_model_connection'] = $connection;
    $rows = \Gallery\Models\gallery_feature_plan_model_transaction(/** Read the applying snapshot while the transaction is owned.
     * @return array<int,array<string,mixed>> Production locked model snapshot.
     */ static fn():array => \Gallery\Models\gallery_feature_plan_model_snapshot(['filenames'], true));
    feature_model_assert($connection->events[0] === 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' && $connection->events[1] === 'begin', 'per-transaction isolation must precede begin');
    feature_model_assert(str_ends_with($connection->events[2], 'ORDER BY id FOR UPDATE') && str_contains($connection->events[2], 'show_filenames'), 'complete applying snapshot must lock the canonical catalog range');
    feature_model_assert(!str_contains($connection->events[2], 'SELECT *') && !str_contains($connection->events[2], 'password') && !str_contains($connection->events[2], 'token'), 'snapshot must not read credential columns');
    feature_model_assert($rows[0]['id'] === 1 && end($connection->events) === 'commit' && !$connection->active, 'successful operation commits and releases ownership');
    $connection->events = [];
    $failure = new \RuntimeException('synthetic write failure');
    try {
        \Gallery\Models\gallery_feature_plan_model_transaction(/** Inject an operation failure after acquiring the real snapshot locks.
         * @return never Refuses the disposable operation before commit.
         */ static function () use ($failure): never { \Gallery\Models\gallery_feature_plan_model_snapshot(['game'], true); throw $failure; });
        throw new \RuntimeException('failed operation unexpectedly committed');
    } catch (\RuntimeException $caught) { feature_model_assert($caught === $failure, 'original operation failure must remain observable'); }
    feature_model_assert(end($connection->events) === 'rollback' && !in_array('commit',$connection->events,true) && !$connection->active, 'failed operation rolls back and never commits');
    $connection->active = true; $connection->events = [];
    $refused = false;
    try { \Gallery\Models\gallery_feature_plan_model_transaction(/** Supply an operation that must never run inside foreign ownership.
     * @return void Performs no fixture write.
     */ static function (): void {}); } catch (\RuntimeException $failure) { $refused = true; }
    feature_model_assert($refused && $connection->events === [] && $connection->active, 'foreign transaction refusal must not alter its isolation or ownership');
    $connection->active = false; $connection->events = [];
    \Gallery\Models\gallery_feature_plan_model_snapshot(['filenames']);
    feature_model_assert(count($connection->events) === 1 && !str_contains($connection->events[0], 'FOR UPDATE'), 'preview snapshot remains a non-locking read');
    echo "PASS gallery feature plan model\n";
}
