<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/admin_setup_wizard.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns the database transaction and row-lock boundary for Setup Wizard writes.
 *
 * Responsibilities:
 *   - Start, commit, and roll back the wizard's app-settings transaction
 *   - Lock every affected app_settings row before optimistic comparison
 *   - Invoke a bounded compensation callback if a post-file-write commit fails
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
 *   - Drafts stay in the controller-owned PHP session.
 *   - Domain normalization and canonical save routing stay in the service layer.
 */

declare(strict_types=1);

namespace Gallery\Models;

use RuntimeException;
use Throwable;
use function Gallery\Core\db;

/**
 * Execute one wizard apply operation inside a new database transaction.
 *
 * The operation runs after all named app-settings rows have been locked. The
 * compensation callback is intended only for restoring a file-backed setting
 * changed at the end of the operation if the database transaction cannot commit.
 *
 * @param list<string> $settingKeys Canonical app_settings keys affected by the apply.
 * @param callable():array<string,mixed> $operation Transactional callback returning normalized changes.
 * @param null|callable():void $compensate Best-effort file-setting compensation.
 * @return array<string,mixed> Normalized changes returned by the operation.
 */
function admin_setup_wizard_model_transaction(
    array $settingKeys,
    callable $operation,
    ?callable $compensate = null
): mixed {
    $pdo = db();
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Setup Wizard cannot join an existing database transaction.');
    }

    if (!$pdo->beginTransaction()) {
        throw new RuntimeException('Setup Wizard database transaction could not start.');
    }
    try {
        admin_setup_wizard_model_lock_settings($settingKeys);
        $result = $operation();
        if (!$pdo->commit()) {
            throw new RuntimeException('Setup Wizard database transaction could not commit.');
        }
        return $result;
    } catch (Throwable $exception) {
        $rollbackFailed = false;
        if ($pdo->inTransaction()) {
            try {
                $rollbackFailed = !$pdo->rollBack();
            } catch (Throwable) {
                $rollbackFailed = true;
            }
        }
        if ($compensate !== null) {
            try {
                $compensate();
            } catch (Throwable) {
                throw new RuntimeException('Setup Wizard rollback could not restore every persisted setting.', 0, $exception);
            }
        }
        if ($rollbackFailed) {
            throw new RuntimeException('Setup Wizard database transaction could not roll back.', 0, $exception);
        }
        throw $exception;
    }
}

/**
 * Lock affected app_settings rows in a stable order for optimistic comparison.
 *
 * @param list<string> $settingKeys Canonical non-empty app_settings keys.
 * @return void
 */
function admin_setup_wizard_model_lock_settings(array $settingKeys): void
{
    $keys = array_values(array_unique(array_filter(array_map(
        /**
         * Trim a candidate setting key before filtering empty values.
         *
         * @param string $key Candidate canonical setting key.
         * @return string Trimmed key.
         */
        static fn (mixed $key): string => trim((string) $key),
        $settingKeys
    ),
    /**
     * Keep only non-empty canonical setting keys.
     *
     * @param string $key Candidate canonical setting key.
     * @return bool Whether the key is usable.
     */
    static fn (string $key): bool => $key !== '')));
    sort($keys, SORT_STRING);
    if ($keys === []) {
        return;
    }

    $placeholders = implode(', ', array_fill(0, count($keys), '?'));
    $statement = db()->prepare(
        'SELECT setting_key FROM app_settings WHERE setting_key IN (' . $placeholders . ') ORDER BY setting_key FOR UPDATE'
    );
    $statement->execute($keys);
    $statement->fetchAll();
}
