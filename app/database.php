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
            if (function_exists('Gallery\Services\admin_test_run_record_db_query')) {
                \Gallery\Services\admin_test_run_record_db_query(
                    (string) $this->queryString,
                    (microtime(true) - $startedAt) * 1000,
                    $result,
                    $result ? (int) $this->rowCount() : 0,
                    null
                );
            }
            return $result;
        } catch (Throwable $exception) {
            if (function_exists('Gallery\Services\admin_test_run_record_db_query')) {
                \Gallery\Services\admin_test_run_record_db_query(
                    (string) $this->queryString,
                    (microtime(true) - $startedAt) * 1000,
                    false,
                    0,
                    $exception->getMessage()
                );
            }
            throw $exception;
        }
    }
}

/**
 * PDO subclass used during one Admin test run so direct query()/exec() calls are
 * measured alongside prepared statements. Normal requests continue using PDO.
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
            if (function_exists('Gallery\Services\admin_test_run_record_db_transaction')) {
                \Gallery\Services\admin_test_run_record_db_transaction($name, (microtime(true) - $startedAt) * 1000, $result, null);
            }
            return $result;
        } catch (Throwable $exception) {
            if (function_exists('Gallery\Services\admin_test_run_record_db_transaction')) {
                \Gallery\Services\admin_test_run_record_db_transaction($name, (microtime(true) - $startedAt) * 1000, false, $exception->getMessage());
            }
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
            if (function_exists('Gallery\Services\admin_test_run_record_db_query')) {
                \Gallery\Services\admin_test_run_record_db_query(
                    $query,
                    (microtime(true) - $startedAt) * 1000,
                    $result !== false,
                    $result instanceof PDOStatement ? (int) $result->rowCount() : 0,
                    null
                );
            }
            return $result;
        } catch (Throwable $exception) {
            if (function_exists('Gallery\Services\admin_test_run_record_db_query')) {
                \Gallery\Services\admin_test_run_record_db_query($query, (microtime(true) - $startedAt) * 1000, false, 0, $exception->getMessage());
            }
            throw $exception;
        }
    }

    /** Record one direct SQL exec operation. */
    public function exec(string $statement): int|false
    {
        $startedAt = microtime(true);
        try {
            $result = parent::exec($statement);
            if (function_exists('Gallery\Services\admin_test_run_record_db_query')) {
                \Gallery\Services\admin_test_run_record_db_query($statement, (microtime(true) - $startedAt) * 1000, $result !== false, $result === false ? 0 : (int) $result, null);
            }
            return $result;
        } catch (Throwable $exception) {
            if (function_exists('Gallery\Services\admin_test_run_record_db_query')) {
                \Gallery\Services\admin_test_run_record_db_query($statement, (microtime(true) - $startedAt) * 1000, false, 0, $exception->getMessage());
            }
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
    $pdoClass = $traceActive ? AdminTestRunPDO::class : PDO::class;
    $connectStartedAt = microtime(true);
    try {
        $pdo = new $pdoClass($dsn, $database['user'], $database['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        if ($traceActive) {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [AdminTestRunPDOStatement::class, []]);
        }
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

