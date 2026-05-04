<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/telemetry_settings.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides reusable application logic for gallery data, media, settings, or maintenance workflows.
 *
 * Responsibilities:
 *   - Keep domain logic reusable outside controllers
 *   - Protect existing behavior with small focused functions
 *   - Return predictable values for callers
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

/**
 * Telemetry settings service.
 *
 * The telemetry subsystem intentionally stores its settings separately from the
 * general app settings because privacy, retention, and sampling controls need a
 * clear administrative surface. Every helper fails closed so a missing migration
 * disables public telemetry instead of breaking the public gallery.
 */

/**
 * Return whether the telemetry settings table exists.
 */
function telemetry_settings_schema_ready(): bool
{
    try {
        // $stmt stores the metadata lookup result for the telemetry settings table.
        $stmt = db()->query("SHOW TABLES LIKE 'telemetry_settings'");
        return $stmt && (bool) $stmt->fetch();
    } catch (Throwable) {
        return false;
    }
}

/**
 * Read one telemetry setting value, returning a safe default if unavailable.
 */
function telemetry_setting(string $key, ?string $default = null): ?string
{
    if (!telemetry_settings_schema_ready()) {
        return $default;
    }
    try {
        // $stmt stores the prepared read query for the requested setting key.
        $stmt = db()->prepare('SELECT setting_value FROM telemetry_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        // $value stores the scalar database value returned by PDO.
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string) $value;
    } catch (Throwable) {
        return $default;
    }
}

/**
 * Store one telemetry setting value.
 */
function telemetry_set_setting(string $key, string $value): void
{
    if (!telemetry_settings_schema_ready()) {
        throw new RuntimeException('Telemetry settings schema is not ready. Run migrations first.');
    }
    // $stmt stores the upsert query for the setting value.
    $stmt = db()->prepare('INSERT INTO telemetry_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)');
    $stmt->execute([$key, $value, now_sql()]);
}

/**
 * Return all known telemetry settings as a key-value map.
 */
function telemetry_all_settings(): array
{
    if (!telemetry_settings_schema_ready()) {
        return [];
    }
    // $stmt stores the read query for every telemetry setting row.
    $stmt = db()->query('SELECT setting_key, setting_value FROM telemetry_settings ORDER BY setting_key');
    // $settings stores the normalized key-value setting map.
    $settings = [];
    foreach ($stmt->fetchAll() as $row) {
        $settings[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
    }
    return $settings;
}

/**
 * Return true when a string setting is enabled.
 */
function telemetry_setting_enabled(string $key, string $default = '0'): bool
{
    return telemetry_setting($key, $default) === '1';
}

/**
 * Return whether anonymous public telemetry may be collected.
 */
function telemetry_public_usage_enabled(): bool
{
    return telemetry_setting_enabled('telemetry_enabled') && telemetry_setting_enabled('telemetry_public_usage_enabled');
}

/**
 * Return the configured maximum photo view duration in milliseconds.
 */
function telemetry_max_photo_view_ms(): int
{
    // $seconds stores the bounded visible-time cap configured by the admin.
    $seconds = (int) telemetry_setting('telemetry_max_photo_view_seconds', '900');
    $seconds = max(10, min(3600, $seconds));
    return $seconds * 1000;
}

/**
 * Return one bounded integer retention setting.
 */
function telemetry_retention_days(string $key, int $default, int $minimum, int $maximum): int
{
    // $days stores the bounded retention duration in days.
    $days = (int) telemetry_setting($key, (string) $default);
    return max($minimum, min($maximum, $days));
}
