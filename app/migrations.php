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
 *
 * @return array Structured result data for the caller.
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
    // $migrationDiagnostics stores detailed timing data for admin update logs.
    $migrationDiagnostics = [];
    // $migrationStartedAt stores the full migration batch start timestamp.
    $migrationStartedAt = microtime(true);
    // $thumbnailMetadataSnapshotBefore stores a pre-migration storage snapshot when thumbnail metadata helpers are loaded.
    $thumbnailMetadataSnapshotBefore = function_exists('thumbnail_metadata_storage_snapshot') ? thumbnail_metadata_storage_snapshot() : [];

    foreach ($files as $file) {
        // Variable $version stores this steps working value.
        $version = basename($file, '.php');
        if (isset($applied[$version])) {
            continue;
        }
        // Variable $statements stores this steps working value.
        $statements = require $file;
        // $singleMigrationStartedAt stores per-migration duration for diagnostics.
        $singleMigrationStartedAt = microtime(true);
        // $statementDiagnostics stores per-SQL timing and replay data for this migration.
        $statementDiagnostics = [];
        try {
            foreach ($statements as $statementIndex => $statement) {
                $statementDiagnostic = apply_migration_statement($pdo, $statement);
                $statementDiagnostic['statement_number'] = (int) $statementIndex + 1;
                $statementDiagnostics[] = $statementDiagnostic;
            }
            // Variable $stmt stores this steps working value.
            $stmt = $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)');
            $stmt->execute([$version, now_sql()]);
            $ran[] = $version;
            $migrationDiagnostics[] = [
                'version' => $version,
                'statement_count' => count($statements),
                'duration_seconds' => round(microtime(true) - $singleMigrationStartedAt, 4),
                'statements' => $statementDiagnostics,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    if ($ran && function_exists('admin_log_event')) {
        admin_log_event('info', 'migrations.ran_detailed', 'Database migrations completed with timing diagnostics.', [
            'versions' => $ran,
            'migration_count' => count($ran),
            'started_at_utc' => gmdate('c', (int) $migrationStartedAt),
            'finished_at_utc' => gmdate('c'),
            'php_max_execution_time' => ini_get('max_execution_time'),
            'php_memory_limit' => ini_get('memory_limit'),
            'total_duration_seconds' => round(microtime(true) - $migrationStartedAt, 4),
            'migrations' => $migrationDiagnostics,
            'thumbnail_metadata_snapshot_before' => $thumbnailMetadataSnapshotBefore,
            'thumbnail_metadata_snapshot_after' => function_exists('thumbnail_metadata_storage_snapshot') ? thumbnail_metadata_storage_snapshot() : [],
        ], ['category' => 'database', 'severity' => 'notice']);
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
 *
 * @param PDO $pdo Database connection.
 * @param string $statement Statement value.
 * @return array<string mixed> Structured diagnostic data for the caller.
 */
function apply_migration_statement(PDO $pdo, string $statement): array
{
    $startedAt = microtime(true);
    $diagnostic = migration_statement_diagnostic($statement);
    try {
        $pdo->exec($statement);
        $diagnostic['status'] = 'applied';
    } catch (PDOException $exception) {
        if (!migration_duplicate_ddl_error($exception)) {
            $diagnostic['status'] = 'failed';
            $diagnostic['error_code'] = (int) ($exception->errorInfo[1] ?? $exception->getCode());
            $diagnostic['error_message'] = $exception->getMessage();
            $diagnostic['duration_seconds'] = round(microtime(true) - $startedAt, 4);
            throw $exception;
        }

        $diagnostic['status'] = 'duplicate_ddl_replayed';
        $diagnostic['error_code'] = (int) ($exception->errorInfo[1] ?? $exception->getCode());
        $diagnostic['error_message'] = $exception->getMessage();
    }

    $diagnostic['duration_seconds'] = round(microtime(true) - $startedAt, 4);
    return $diagnostic;
}

/**
 * Return a log-safe summary for one migration SQL statement.
 *
 * @param string $statement Statement value.
 * @return array<string mixed> Structured diagnostic data for the caller.
 */
function migration_statement_diagnostic(string $statement): array
{
    $normalized = trim((string) preg_replace('/\s+/', ' ', $statement));
    $operation = 'UNKNOWN';
    if (preg_match('/^([A-Z]+)/i', $normalized, $match) === 1) {
        $operation = strtoupper((string) $match[1]);
    }

    $object = '';
    if (preg_match('/\b(?:TABLE|INTO|FROM|JOIN)\s+`?([a-zA-Z0-9_]+)`?/i', $normalized, $match) === 1) {
        $object = (string) $match[1];
    }

    return [
        'operation' => $operation,
        'object' => $object,
        'length_bytes' => strlen($statement),
        'signature' => substr(hash('sha256', $normalized), 0, 16),
        'preview' => substr($normalized, 0, 240),
    ];
}

/**
 * Return true when an exception is a duplicate object error from idempotent DDL.
 *
 * @param PDOException $exception Exception value.
 * @return bool True when the condition matches.
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
 *
 * @return bool True when the condition matches.
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

