<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/database.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Provides core bootstrap, configuration, helper, security, database, or routing functionality.
 *
 * Responsibilities:
 *   - Support shared project infrastructure
 *   - Keep behavior compatible with existing controllers and services
 *   - Avoid unnecessary coupling to presentation code
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
 *   2026-05-04
 */

declare(strict_types=1);

namespace Gallery\Core;

use PDO;
use PDOStatement;
use Throwable;


/**
 * Dispatch one executed SQL observation to the anonymous database telemetry observer.
 *
 * The observer is loaded later in the service bootstrap, so this core boundary
 * deliberately uses a dynamic function check instead of introducing a reverse
 * dependency from Core to Services.
 */
function database_dispatch_telemetry_query_observation(string $sql, float $elapsedMs, bool $ok, int $rowCount = 0, ?string $error = null): void
{
    if (function_exists('Gallery\\Services\\telemetry_observe_db_query')) {
        \Gallery\Services\telemetry_observe_db_query($sql, $elapsedMs, $ok, $rowCount, $error);
    }
}

/** Dispatch one executed SQL observation to every active request-local observer. */
function database_dispatch_query_observation(string $sql, float $elapsedMs, bool $ok, int $rowCount = 0, ?string $error = null): void
{
    if (function_exists('Gallery\\Services\\admin_test_run_record_db_query')) {
        \Gallery\Services\admin_test_run_record_db_query($sql, $elapsedMs, $ok, $rowCount, $error);
    }
    database_dispatch_telemetry_query_observation($sql, $elapsedMs, $ok, $rowCount, $error);
}

/**
 * PDOStatement subclass used by normal requests for central executed-query telemetry.
 *
 * prepare() itself is not counted. Only execute() represents one logical prepared
 * query execution, and bound parameter values are never passed to the observer.
 */
class TelemetryPDOStatement extends PDOStatement
{
    /** PDO constructs statement subclasses internally. */
    protected function __construct()
    {
    }

    /** Execute the prepared statement and dispatch its privacy-safe observation. */
    public function execute(?array $params = null): bool
    {
        $startedAt = microtime(true);
        try {
            $result = parent::execute($params);
            database_dispatch_telemetry_query_observation(
                (string) $this->queryString,
                (microtime(true) - $startedAt) * 1000,
                $result,
                $result ? (int) $this->rowCount() : 0,
                null
            );
            return $result;
        } catch (Throwable $exception) {
            database_dispatch_telemetry_query_observation(
                (string) $this->queryString,
                (microtime(true) - $startedAt) * 1000,
                false,
                0,
                $exception->getMessage()
            );
            throw $exception;
        }
    }
}

/**
 * PDO subclass used by normal requests to observe direct query()/exec() calls.
 *
 * Prepared executions are observed by TelemetryPDOStatement. Transaction methods
 * are emitted as bounded operation buckets without raw parameter data.
 */
class TelemetryPDO extends PDO
{
    /** Record one direct SQL query. */
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $startedAt = microtime(true);
        try {
            $result = $fetchMode === null
                ? parent::query($query)
                : parent::query($query, $fetchMode, ...$fetchModeArgs);
            database_dispatch_telemetry_query_observation(
                $query,
                (microtime(true) - $startedAt) * 1000,
                $result !== false,
                $result instanceof PDOStatement ? (int) $result->rowCount() : 0,
                null
            );
            return $result;
        } catch (Throwable $exception) {
            database_dispatch_telemetry_query_observation($query, (microtime(true) - $startedAt) * 1000, false, 0, $exception->getMessage());
            throw $exception;
        }
    }

    /** Record one direct SQL exec operation. */
    public function exec(string $statement): int|false
    {
        $startedAt = microtime(true);
        try {
            $result = parent::exec($statement);
            database_dispatch_telemetry_query_observation(
                $statement,
                (microtime(true) - $startedAt) * 1000,
                $result !== false,
                $result === false ? 0 : (int) $result,
                null
            );
            return $result;
        } catch (Throwable $exception) {
            database_dispatch_telemetry_query_observation($statement, (microtime(true) - $startedAt) * 1000, false, 0, $exception->getMessage());
            throw $exception;
        }
    }

    /** Record transaction start latency and outcome for telemetry only. */
    public function beginTransaction(): bool
    {
        return $this->recordTelemetryTransactionOperation('begin');
    }

    /** Record transaction commit latency and outcome for telemetry only. */
    public function commit(): bool
    {
        return $this->recordTelemetryTransactionOperation('commit');
    }

    /** Record transaction rollback latency and outcome for telemetry only. */
    public function rollBack(): bool
    {
        return $this->recordTelemetryTransactionOperation('rollback');
    }

    /** Execute one concrete parent transaction operation without virtual recursion. */
    private function recordTelemetryTransactionOperation(string $name): bool
    {
        $startedAt = microtime(true);
        try {
            $result = match ($name) {
                'begin' => parent::beginTransaction(),
                'commit' => parent::commit(),
                'rollback' => parent::rollBack(),
                default => false,
            };
            database_dispatch_telemetry_query_observation(strtoupper($name), (microtime(true) - $startedAt) * 1000, $result, 0, null);
            return $result;
        } catch (Throwable $exception) {
            database_dispatch_telemetry_query_observation(strtoupper($name), (microtime(true) - $startedAt) * 1000, false, 0, $exception->getMessage());
            throw $exception;
        }
    }
}

/**
 * PDOStatement subclass used only while an opt-in Admin test run is active.
 *
 * Prepared statements are the dominant database path in PHP Gallery. Recording
 * execute() here captures their duration without changing call sites or storing
 * raw bound parameter values.
 */
class AdminTestRunPDOStatement extends PDOStatement
{
    /** PDO constructs statement subclasses internally. */
    protected function __construct()
    {
    }

    /**
     * Execute the prepared statement and report its privacy-safe query shape.
     *
     * @param ?array $params Bound parameters supplied by the caller.
     */
    public function execute(?array $params = null): bool
    {
        $startedAt = microtime(true);
        try {
            $result = parent::execute($params);
            database_dispatch_query_observation(
                (string) $this->queryString,
                (microtime(true) - $startedAt) * 1000,
                $result,
                $result ? (int) $this->rowCount() : 0,
                null
            );
            return $result;
        } catch (Throwable $exception) {
            database_dispatch_query_observation(
                (string) $this->queryString,
                (microtime(true) - $startedAt) * 1000,
                false,
                0,
                $exception->getMessage()
            );
            throw $exception;
        }
    }
}

/**
 * PDO subclass used during one Admin test run so direct query()/exec() calls are
 * measured alongside prepared statements. Normal requests use the lightweight
 * TelemetryPDO boundary while the opt-in Admin run adds its diagnostic observer.
 */
class AdminTestRunPDO extends PDO
{
    /** Record one prepared-statement creation before its later execute() timing. */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $startedAt = microtime(true);
        try {
            $result = parent::prepare($query, $options);
            if (function_exists('Gallery\Services\admin_test_run_record_db_prepare')) {
                \Gallery\Services\admin_test_run_record_db_prepare($query, (microtime(true) - $startedAt) * 1000, $result !== false, null);
            }
            return $result;
        } catch (Throwable $exception) {
            if (function_exists('Gallery\Services\admin_test_run_record_db_prepare')) {
                \Gallery\Services\admin_test_run_record_db_prepare($query, (microtime(true) - $startedAt) * 1000, false, $exception->getMessage());
            }
            throw $exception;
        }
    }

    /** Record transaction start latency and outcome. */
    public function beginTransaction(): bool
    {
        return $this->recordTransactionOperation('begin');
    }

    /** Record transaction commit latency and outcome. */
    public function commit(): bool
    {
        return $this->recordTransactionOperation('commit');
    }

    /** Record transaction rollback latency and outcome. */
    public function rollBack(): bool
    {
        return $this->recordTransactionOperation('rollback');
    }

    /**
     * Execute one parent PDO transaction operation without recursively calling this subclass override.
     *
     */
    private function recordTransactionOperation(string $name): bool
    {
        $startedAt = microtime(true);
        try {
            // Bind the parent PDO method through Closure::call would still dispatch virtually; invoke the concrete parent explicitly.
            $result = match ($name) {
                'begin' => parent::beginTransaction(),
                'commit' => parent::commit(),
                'rollback' => parent::rollBack(),
                default => false,
            };
            $elapsedMs = (microtime(true) - $startedAt) * 1000;
            if (function_exists('Gallery\Services\admin_test_run_record_db_transaction')) {
                \Gallery\Services\admin_test_run_record_db_transaction($name, $elapsedMs, $result, null);
            }
            database_dispatch_telemetry_query_observation(strtoupper($name), $elapsedMs, $result, 0, null);
            return $result;
        } catch (Throwable $exception) {
            $elapsedMs = (microtime(true) - $startedAt) * 1000;
            if (function_exists('Gallery\Services\admin_test_run_record_db_transaction')) {
                \Gallery\Services\admin_test_run_record_db_transaction($name, $elapsedMs, false, $exception->getMessage());
            }
            database_dispatch_telemetry_query_observation(strtoupper($name), $elapsedMs, false, 0, $exception->getMessage());
            throw $exception;
        }
    }

    /** Record one direct SQL query. */
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $startedAt = microtime(true);
        try {
            $result = $fetchMode === null
                ? parent::query($query)
                : parent::query($query, $fetchMode, ...$fetchModeArgs);
            database_dispatch_query_observation(
                $query,
                (microtime(true) - $startedAt) * 1000,
                $result !== false,
                $result instanceof PDOStatement ? (int) $result->rowCount() : 0,
                null
            );
            return $result;
        } catch (Throwable $exception) {
            database_dispatch_query_observation($query, (microtime(true) - $startedAt) * 1000, false, 0, $exception->getMessage());
            throw $exception;
        }
    }

    /** Record one direct SQL exec operation. */
    public function exec(string $statement): int|false
    {
        $startedAt = microtime(true);
        try {
            $result = parent::exec($statement);
            database_dispatch_query_observation($statement, (microtime(true) - $startedAt) * 1000, $result !== false, $result === false ? 0 : (int) $result, null);
            return $result;
        } catch (Throwable $exception) {
            database_dispatch_query_observation($statement, (microtime(true) - $startedAt) * 1000, false, 0, $exception->getMessage());
            throw $exception;
        }
    }
}

/**
 * Return the shared PDO connection for the current request.
 *
 * The connection is cached in a static variable because controllers and services
 * call db() frequently while rendering one page. The optional port field is used
 * by the browser installer and local stacks such as Laragon/XAMPP.
 *
 * @return PDO Result value for the caller.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Variable $database stores this steps working value.
    $database = cms_config()['database'];
    // Variable $dsn stores this steps working value.
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $database['host'], $database['name'], $database['charset'] ?? 'utf8mb4');
    if (!empty($database['port'])) {
        $dsn .= ';port=' . (int) $database['port'];
    }
    // Variable $pdo stores this steps working value.
    $traceActive = function_exists('Gallery\Services\admin_test_run_active') && \Gallery\Services\admin_test_run_active();
    $pdoClass = $traceActive ? AdminTestRunPDO::class : TelemetryPDO::class;
    $connectStartedAt = microtime(true);
    try {
        $pdo = new $pdoClass($dsn, $database['user'], $database['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $statementClass = $traceActive ? AdminTestRunPDOStatement::class : TelemetryPDOStatement::class;
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [$statementClass, []]);
        if (function_exists('Gallery\Services\admin_test_run_record_db_connection')) {
            \Gallery\Services\admin_test_run_record_db_connection((microtime(true) - $connectStartedAt) * 1000, true, 'mysql', null);
        }
    } catch (Throwable $exception) {
        if (function_exists('Gallery\Services\admin_test_run_record_db_connection')) {
            \Gallery\Services\admin_test_run_record_db_connection((microtime(true) - $connectStartedAt) * 1000, false, 'mysql', $exception->getMessage());
        }
        throw $exception;
    }
    return $pdo;
}

