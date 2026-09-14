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

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;
use RuntimeException;
use function Gallery\Core\now_sql;
use function Gallery\Models\telemetry_model_all_settings;
use function Gallery\Models\telemetry_model_set_setting;
use function Gallery\Models\telemetry_model_setting;

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
 *
 * @return bool True when the condition matches.
 */
function telemetry_settings_schema_ready(): bool
{
    return presentation_schema_render_available(presentation_telemetry_settings_schema_status(), 'telemetry_settings_render');
}

/**
 * Read one telemetry setting value, returning a safe default if unavailable.
 *
 * @param string $key Lookup key.
 * @param ?string $default Default value when no explicit value is available.
 * @return ?string Text result for the caller.
 */
function telemetry_setting(string $key, ?string $default = null): ?string
{
    if (!telemetry_settings_schema_ready()) {
        return $default;
    }
    try {
        // $value stores the scalar database value returned by the telemetry model.
        $value = telemetry_model_setting($key);
        return $value === false ? $default : (string) $value;
    } catch (Throwable) {
        return $default;
    }
}

/**
 * Store one telemetry setting value.
 *
 * @param string $key Lookup key.
 * @param string $value Value to process.
 */
function telemetry_set_setting(string $key, string $value): void
{
    presentation_schema_assert_write_available(
        presentation_telemetry_settings_schema_status(),
        'telemetry_setting_save',
        'Telemetry settings schema is not ready. Run migrations first.',
        'Telemetry settings schema could not be verified. No telemetry setting was changed.'
    );
    telemetry_model_set_setting($key, $value, now_sql());
}

/**
 * Return all known telemetry settings as a key-value map.
 *
 * @return array Structured result data for the caller.
 */
function telemetry_all_settings(): array
{
    if (!telemetry_settings_schema_ready()) {
        return [];
    }
    // $settings stores the normalized key-value setting map.
    $settings = [];
    foreach (telemetry_model_all_settings() as $row) {
        $settings[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
    }
    return $settings;
}

/**
 * Return true when a string setting is enabled.
 *
 * @param string $key Lookup key.
 * @param string $default Default value when no explicit value is available.
 * @return bool True when the condition matches.
 */
function telemetry_setting_enabled(string $key, string $default = '0'): bool
{
    return telemetry_setting($key, $default) === '1';
}

/**
 * Return whether anonymous public telemetry may be collected.
 *
 * @return bool True when the condition matches.
 */
function telemetry_public_usage_enabled(): bool
{
    if (function_exists(__NAMESPACE__ . '\\feature_capability_effective_enabled') && !feature_capability_effective_enabled('telemetry')) {
        return false;
    }

    return telemetry_setting_enabled('telemetry_enabled') && telemetry_setting_enabled('telemetry_public_usage_enabled');
}

/**
 * Return the configured maximum photo view duration in milliseconds.
 *
 * @return int Integer result for the caller.
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
 *
 * @param string $key Lookup key.
 * @param int $default Default value when no explicit value is available.
 * @param int $minimum Minimum value.
 * @param int $maximum Maximum value.
 * @return int Integer result for the caller.
 */
function telemetry_retention_days(string $key, int $default, int $minimum, int $maximum): int
{
    // $days stores the bounded retention duration in days.
    $days = (int) telemetry_setting($key, (string) $default);
    return max($minimum, min($maximum, $days));
}
