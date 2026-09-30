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
 *   2026-09-29
 */

declare(strict_types=1);

namespace Gallery\Services;

const TELEMETRY_SEMANTICS_VERSION = '2';
const TELEMETRY_SEMANTICS_VERSION_KEY = 'telemetry_semantics_version';
const TELEMETRY_SEMANTICS_EFFECTIVE_AT_KEY = 'telemetry_semantics_v2_effective_at';

use InvalidArgumentException;
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
 * Return the strict allowlist and validation metadata for safe telemetry preferences.
 *
 * @return array<string,array{input_type:string,default:string,validation:array<string,int>}> Safe setting definitions.
 */
function telemetry_admin_editable_setting_definitions(): array
{
    return [
        'telemetry_enabled' => ['input_type' => 'checkbox', 'default' => '0', 'validation' => []],
        'telemetry_public_usage_enabled' => ['input_type' => 'checkbox', 'default' => '0', 'validation' => []],
        'telemetry_performance_enabled' => ['input_type' => 'checkbox', 'default' => '0', 'validation' => []],
        'telemetry_cache_enabled' => ['input_type' => 'checkbox', 'default' => '1', 'validation' => []],
        'telemetry_database_enabled' => ['input_type' => 'checkbox', 'default' => '1', 'validation' => []],
        'telemetry_respect_dnt' => ['input_type' => 'checkbox', 'default' => '1', 'validation' => []],
        'telemetry_admin_excluded' => ['input_type' => 'checkbox', 'default' => '1', 'validation' => []],
        'telemetry_max_photo_view_seconds' => ['input_type' => 'number', 'default' => '900', 'validation' => ['min' => 10, 'max' => 3600, 'step' => 1]],
        'telemetry_raw_retention_days' => ['input_type' => 'number', 'default' => '7', 'validation' => ['min' => 1, 'max' => 90, 'step' => 1]],
        'telemetry_hourly_retention_days' => ['input_type' => 'number', 'default' => '90', 'validation' => ['min' => 7, 'max' => 730, 'step' => 1]],
        'telemetry_daily_retention_days' => ['input_type' => 'number', 'default' => '730', 'validation' => ['min' => 30, 'max' => 3650, 'step' => 1]],
    ];
}

/**
 * Return telemetry preferences safe for reuse by guided administrative settings flows.
 *
 * Export, maintenance, sampling, and other operational controls deliberately remain on the
 * dedicated telemetry surface. The availability flag fails closed when schema inspection
 * cannot prove that the telemetry settings table is writable.
 *
 * @return array<string,array{input_type:string,current:string,validation:array<string,int>,available:bool}> Safe setting definitions.
 */
function telemetry_admin_editable_settings(): array
{
    $status = presentation_telemetry_settings_schema_status();
    $available = schema_inspection_is_available($status);
    $stored = [];
    if ($available) {
        try {
            foreach (telemetry_model_all_settings() as $row) {
                $stored[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
            }
        } catch (Throwable) {
            $available = false;
            $stored = [];
        }
    }
    $settings = [];
    foreach (telemetry_admin_editable_setting_definitions() as $id => $definition) {
        $default = (string) $definition['default'];
        $settings[$id] = [
            'input_type' => (string) $definition['input_type'],
            'current' => (string) ($stored[$id] ?? $default),
            'validation' => (array) $definition['validation'],
            'available' => $available,
        ];
    }
    return $settings;
}

/**
 * Strictly normalize one safe telemetry preference.
 *
 * @param string $id Stable telemetry setting id.
 * @param mixed $value Candidate setting value.
 * @return string Canonical persisted scalar.
 */
function telemetry_admin_setting_normalize(string $id, mixed $value): string
{
    $definitions = telemetry_admin_editable_setting_definitions();
    if (!isset($definitions[$id])) {
        throw new InvalidArgumentException('Unsupported telemetry setting.');
    }
    if (($definitions[$id]['input_type'] ?? '') === 'checkbox') {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return (string) $value;
        }
        if (is_string($value) && in_array(trim($value), ['0', '1'], true)) {
            return trim($value);
        }
        throw new InvalidArgumentException('Invalid telemetry checkbox value.');
    }
    if (!is_scalar($value)) {
        throw new InvalidArgumentException('Invalid telemetry numeric value.');
    }
    $candidate = trim((string) $value);
    if ($candidate === '' || !ctype_digit($candidate)) {
        throw new InvalidArgumentException('Invalid telemetry numeric value.');
    }
    $number = (int) $candidate;
    $validation = $definitions[$id]['validation'];
    if ($number < (int) ($validation['min'] ?? 0) || $number > (int) ($validation['max'] ?? PHP_INT_MAX)) {
        throw new InvalidArgumentException('Telemetry value is outside the supported range.');
    }
    return (string) $number;
}

/**
 * Persist one safe telemetry preference through the telemetry settings owner.
 *
 * @param string $id Stable telemetry setting id.
 * @param mixed $value Candidate setting value.
 * @return string Persisted canonical scalar.
 */
function telemetry_admin_setting_save(string $id, mixed $value): string
{
    $normalized = telemetry_admin_setting_normalize($id, $value);
    telemetry_set_setting($id, $normalized);
    return $normalized;
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


/**
 * Return the stored telemetry semantics version.
 *
 * Installations without a marker are treated as legacy version 1 until the
 * first event is accepted by the corrected version 2 producer.
 *
 * @return string Version identifier.
 */
function telemetry_semantics_version(): string
{
    $version = trim((string) telemetry_setting(TELEMETRY_SEMANTICS_VERSION_KEY, '1'));
    return $version !== '' ? $version : '1';
}

/**
 * Return the first recorded timestamp for telemetry semantics version 2.
 *
 * @return ?string SQL timestamp or null when the boundary has not been recorded.
 */
function telemetry_semantics_effective_at(): ?string
{
    $value = trim((string) telemetry_setting(TELEMETRY_SEMANTICS_EFFECTIVE_AT_KEY, ''));
    return $value !== '' ? $value : null;
}

/**
 * Persist the version 2 telemetry semantics boundary once per request.
 *
 * Marker persistence is diagnostic metadata only. Failure to write the marker
 * must never make an otherwise valid anonymous telemetry event fail.
 */
function telemetry_ensure_current_semantics_marker(): void
{
    static $attempted = false;
    if ($attempted || !telemetry_settings_schema_ready()) {
        return;
    }
    $attempted = true;

    try {
        $effectiveAt = telemetry_semantics_effective_at();
        if ($effectiveAt === null) {
            telemetry_set_setting(TELEMETRY_SEMANTICS_EFFECTIVE_AT_KEY, now_sql());
        }
        if (telemetry_semantics_version() !== TELEMETRY_SEMANTICS_VERSION) {
            telemetry_set_setting(TELEMETRY_SEMANTICS_VERSION_KEY, TELEMETRY_SEMANTICS_VERSION);
        }
    } catch (Throwable) {
        // The event ingestion path deliberately continues without the marker.
    }
}
