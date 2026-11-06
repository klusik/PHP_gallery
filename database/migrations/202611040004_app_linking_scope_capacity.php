<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202611040004_app_linking_scope_capacity.php
 * Module Type: Database Migration
 * Purpose: Expand exact app-linking scope storage without rewriting grants.
 * Responsibilities:
 *   - Verify every owned scope column before and after a replay-safe expansion.
 *   - Preserve all existing scope bytes and refuse unexpected schema shapes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

if (!defined('GALLERY_APP_LINK_SCOPE_MIGRATION_LOCK_WAIT_SECONDS')) {
    /**
     * Bound each metadata lock acquisition during scope expansion.
     * Type: int. Units: seconds. Scope: the migration's PDO session.
     * Consumers: SESSION lock_wait_timeout in this migration.
     * Rationale: Five seconds bounds DDL admission and preserves stricter
     *     session limits.
     */
    define('GALLERY_APP_LINK_SCOPE_MIGRATION_LOCK_WAIT_SECONDS', 5);
}

return [
    'statements' => [],
    'after' => static function (\PDO $pdo): void {
        // Identifiers are fixed here, never supplied by the request or database.
        $tables = [
            'app_link_requests', 'app_link_codes', 'app_link_grants',
            'app_link_refresh_families',
        ];
        $lockName = null;
        $originalLockWait = null;
        $restoreLockWait = false;
        $failed = false;
        $cleanupFailed = false;
        $readLockWait = static function (\PDO $connection): int {
            $query = $connection->query('SELECT @@SESSION.lock_wait_timeout');
            $value = $query === false ? false : $query->fetchColumn();
            if ((!is_string($value) && !is_int($value))
                || preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', (string) $value) !== 1
                || (string) (int) $value !== (string) $value) {
                throw new \RuntimeException('Scope migration session timeout is unavailable.');
            }
            return (int) $value;
        };
        try {
            $database = $pdo->query('SELECT DATABASE()');
            $databaseName = $database === false ? false : $database->fetchColumn();
            if (!is_string($databaseName) || $databaseName === '') {
                throw new \RuntimeException('Scope migration database is unavailable.');
            }
            // Zero wait refuses concurrent migration rather than stealing work.
            $lockName = hash('sha256', 'app-link-scope-capacity:' . $databaseName);
            $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
            if (!$lock || !$lock->execute([$lockName]) || (int) $lock->fetchColumn() !== 1) {
                $lockName = null;
                throw new \RuntimeException('Scope migration is busy.');
            }
            $originalLockWait = $readLockWait($pdo);
            $metadataLockWaitSeconds = GALLERY_APP_LINK_SCOPE_MIGRATION_LOCK_WAIT_SECONDS;
            if ($originalLockWait > $metadataLockWaitSeconds) {
                // Restore even when a failed SET may have changed the session.
                $restoreLockWait = true;
                if ($pdo->exec('SET SESSION lock_wait_timeout = ' . $metadataLockWaitSeconds) === false
                    || $readLockWait($pdo) !== $metadataLockWaitSeconds) {
                    throw new \RuntimeException('Scope migration session timeout could not be set.');
                }
            }
            $inspect = $pdo->prepare(
                'SELECT COLUMN_TYPE, IS_NULLABLE, COLLATION_NAME, COLUMN_DEFAULT, '
                    . 'EXTRA, COLUMN_KEY FROM information_schema.COLUMNS '
                    . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
                    . "AND COLUMN_NAME = 'scope'",
            );
            if (!$inspect) {
                throw new \RuntimeException('Scope migration metadata is unavailable.');
            }
            $readType = static function (string $table) use ($inspect): string {
                if (!$inspect->execute([$table])) {
                    throw new \RuntimeException('Scope migration metadata is unavailable.');
                }
                $row = $inspect->fetch(\PDO::FETCH_ASSOC);
                if (!is_array($row)
                    || !in_array(strtolower((string) ($row['COLUMN_TYPE'] ?? '')), [
                        'varbinary(64)', 'varbinary(255)',
                    ], true)
                    || ($row['IS_NULLABLE'] ?? null) !== 'NO'
                    || !array_key_exists('COLLATION_NAME', $row) || $row['COLLATION_NAME'] !== null
                    || !array_key_exists('COLUMN_DEFAULT', $row) || $row['COLUMN_DEFAULT'] !== null
                    || ($row['EXTRA'] ?? null) !== '' || ($row['COLUMN_KEY'] ?? null) !== '') {
                    throw new \RuntimeException('Scope migration encountered an incompatible column.');
                }
                return strtolower((string) $row['COLUMN_TYPE']);
            };
            // Preflight all owners before changing any of them.
            foreach ($tables as $table) {
                $readType($table);
            }
            foreach ($tables as $table) {
                if ($readType($table) === 'varbinary(64)') {
                    if ($pdo->exec(
                        'ALTER TABLE `' . $table . '` MODIFY COLUMN scope VARBINARY(255) NOT NULL',
                    ) === false) {
                        throw new \RuntimeException('Scope migration could not expand a column.');
                    }
                }
                if ($readType($table) !== 'varbinary(255)') {
                    throw new \RuntimeException('Scope migration could not verify a column.');
                }
            }
        } catch (\Throwable) {
            // Metadata, driver and DDL diagnostics can contain connection
            // details; callers receive only a bounded recovery instruction.
            $failed = true;
        } finally {
            if ($restoreLockWait && $originalLockWait !== null) {
                try {
                    if ($pdo->exec('SET SESSION lock_wait_timeout = ' . $originalLockWait) === false
                        || $readLockWait($pdo) !== $originalLockWait) {
                        throw new \RuntimeException('Scope migration session timeout was not restored.');
                    }
                } catch (\Throwable) {
                    $cleanupFailed = true;
                }
            }
            if ($lockName !== null) {
                try {
                    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                    if (!$release || !$release->execute([$lockName])
                        || (int) $release->fetchColumn() !== 1) {
                        throw new \RuntimeException('Scope migration lock release failed.');
                    }
                } catch (\Throwable) {
                    $cleanupFailed = true;
                }
            }
        }
        if ($failed && $cleanupFailed) {
            throw new \RuntimeException('App-link scope migration failed and session cleanup could not be verified.');
        }
        if ($failed) {
            throw new \RuntimeException('App-link scope migration could not be completed safely.');
        }
        if ($cleanupFailed) {
            throw new \RuntimeException('App-link scope migration session cleanup could not be verified.');
        }
    },
];
