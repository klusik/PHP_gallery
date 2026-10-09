<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/app_settings.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns application-setting persistence independently of request-local caching.
 *
 * Responsibilities:
 *   - Read all application settings for request-local cache priming
 *   - Read one application setting as a compatibility fallback
 *   - Upsert and delete application settings
 *   - Commit related setting upserts around reversible activation and report unconfirmed rollback
 *   - Keep app_settings SQL out of service orchestration
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
 *   - Request-local cache ownership remains in the service layer.
 *
 * Last Updated:
 *   2026-10-09
 */

declare(strict_types=1);

namespace Gallery\Models;

use function Gallery\Core\db;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

/** Signal that persistence failed and the database did not confirm rollback. */
final class AppSettingsRollbackException extends RuntimeException
{
}

/**
 * Return all application-setting rows.
 *
 * @return array<int,array<string,mixed>> Setting rows.
 */
function app_settings_model_all(): array
{
    $stmt = db()->query('SELECT setting_key, setting_value FROM app_settings');
    return $stmt === false ? [] : ($stmt->fetchAll() ?: []);
}

/**
 * Return one raw application-setting value.
 *
 * @return string|false Database value or false when the key is absent.
 */
function app_settings_model_get(string $key): string|false
{
    $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? false : (string) $value;
}

/**
 * Upsert one application-setting value.
 */
function app_settings_model_set(string $key, string $value, string $now): void
{
    $stmt = db()->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)');
    $stmt->execute([$key, $value, $now]);
}

/**
 * Atomically upsert related settings around one reversible domain activation before committing.
 *
 * @param array<string,string> $settings Non-empty map from setting keys to values committed as one database transaction.
 * @param string $now Shared SQL timestamp recorded for every changed key.
 * @param callable():void $activate Reversible file activation that runs after setting upserts and before database commit; it must not persist settings.
 * @return void Commits rows only after activation succeeds; on failure it attempts to roll back every setting row.
 * @throws InvalidArgumentException When the map is empty or contains invalid keys or values.
 * @throws PDOException When the database refuses the transaction or one of its upserts.
 * @throws AppSettingsRollbackException When the transaction outcome cannot be confirmed after failure.
 * @throws RuntimeException When a transaction boundary reports failure.
 */
function app_settings_model_set_many_with_activation(array $settings, string $now, callable $activate): void
{
    if ($settings === []) {
        throw new InvalidArgumentException('At least one application setting is required.');
    }
    foreach ($settings as $key => $value) {
        if (!is_string($key) || trim($key) === '' || !is_string($value)) {
            throw new InvalidArgumentException('Application setting keys must be non-empty strings and values must be strings.');
        }
    }

    $connection = db();
    if ($connection->inTransaction()) {
        throw new InvalidArgumentException('Atomic application settings cannot join an existing transaction.');
    }
    if (!$connection->beginTransaction()) {
        throw new RuntimeException('The application settings transaction could not start.');
    }
    try {
        $statement = $connection->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)');
        if ($statement === false) {
            throw new RuntimeException('The application settings statement could not be prepared.');
        }
        foreach ($settings as $key => $value) {
            if (!$statement->execute([$key, $value, $now])) {
                throw new RuntimeException('An application setting could not be persisted.');
            }
        }
        $activate();
        if (!$connection->commit()) {
            throw new RuntimeException('The application settings transaction could not commit.');
        }
    } catch (\Throwable $exception) {
        if ($connection->inTransaction()) {
            try {
                $rolledBack = $connection->rollBack();
            } catch (\Throwable $rollbackException) {
                throw new AppSettingsRollbackException('The application settings transaction could not roll back.', 0, $rollbackException);
            }
            if (!$rolledBack || $connection->inTransaction()) {
                throw new AppSettingsRollbackException('The application settings transaction could not roll back.', 0, $exception);
            }
        } else {
            throw new AppSettingsRollbackException('The application settings transaction outcome could not be confirmed.', 0, $exception);
        }
        throw $exception;
    }
}

/**
 * Delete selected application-setting keys.
 *
 * @param array<int,string> $keys Non-empty unique setting keys.
 */
function app_settings_model_delete(array $keys): void
{
    if ($keys === []) {
        return;
    }
    $placeholders = implode(', ', array_fill(0, count($keys), '?'));
    $stmt = db()->prepare('DELETE FROM app_settings WHERE setting_key IN (' . $placeholders . ')');
    $stmt->execute($keys);
}
