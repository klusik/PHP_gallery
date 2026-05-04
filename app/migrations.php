<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/migrations.php
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

/**
 * Apply all pending database migrations in filename order.
 *
 * MySQL can auto-commit DDL statements such as CREATE TABLE, so migrations are
 * not wrapped in an explicit transaction. Each migration records its version
 * only after every SQL statement in that file has executed successfully.
 */
function run_migrations(): array
{
    // Variable $pdo stores this steps working value.
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(64) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Variable $applied stores this steps working value.
    $applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    // Variable $applied stores this steps working value.
    $applied = array_flip($applied);
    // Variable $files stores this steps working value.
    $files = glob(dirname(__DIR__) . '/database/migrations/*.php') ?: [];
    sort($files);
    // Variable $ran stores this steps working value.
    $ran = [];

    foreach ($files as $file) {
        // Variable $version stores this steps working value.
        $version = basename($file, '.php');
        if (isset($applied[$version])) {
            continue;
        }
        // Variable $statements stores this steps working value.
        $statements = require $file;
        try {
            foreach ($statements as $statement) {
                apply_migration_statement($pdo, $statement);
            }
            // Variable $stmt stores this steps working value.
            $stmt = $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)');
            $stmt->execute([$version, now_sql()]);
            $ran[] = $version;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    return $ran;
}

/**
 * Execute one migration statement, allowing safe replay of already-applied DDL.
 *
 * Shared hosts and interrupted browser installs can leave a database with the
 * table/column/index already present but schema_migrations not yet recorded.
 * MySQL and MariaDB differ on IF NOT EXISTS support for ALTER TABLE, so the
 * portable path is to treat duplicate DDL errors as successful replays.
 */
function apply_migration_statement(PDO $pdo, string $statement): void
{
    try {
        $pdo->exec($statement);
    } catch (PDOException $exception) {
        if (!migration_duplicate_ddl_error($exception)) {
            throw $exception;
        }
    }
}

/**
 * Return true when an exception is a duplicate object error from idempotent DDL.
 */
function migration_duplicate_ddl_error(PDOException $exception): bool
{
    // $driverCode stores an intermediate value used by the surrounding gallery workflow.
    $driverCode = (int) ($exception->errorInfo[1] ?? $exception->getCode());
    if (in_array($driverCode, [1050, 1060, 1061, 1826], true)) {
        return true;
    }

    // $message stores an intermediate value used by the surrounding gallery workflow.
    $message = $exception->getMessage();
    return str_contains($message, 'already exists')
        || str_contains($message, 'Duplicate column name')
        || str_contains($message, 'Duplicate key name')
        || str_contains($message, 'Duplicate foreign key constraint name')
        || str_contains($message, 'errno: 121');
}

/**
 * Return true when at least one migration file has not been recorded yet.
 */
function pending_migrations_exist(): bool
{
    try {
        // $pdo stores an intermediate value used by the surrounding gallery workflow.
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(64) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // $applied stores an intermediate value used by the surrounding gallery workflow.
        $applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        // $applied stores an intermediate value used by the surrounding gallery workflow.
        $applied = array_flip($applied);
        // $files stores an intermediate value used by the surrounding gallery workflow.
        $files = glob(dirname(__DIR__) . '/database/migrations/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            // $version stores an intermediate value used by the surrounding gallery workflow.
            $version = basename($file, '.php');
            if (!isset($applied[$version])) {
                return true;
            }
        }
        return false;
    } catch (Throwable) {
        return true;
    }
}

