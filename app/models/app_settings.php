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
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use function Gallery\Core\db;

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
