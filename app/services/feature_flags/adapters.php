<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/feature_flags/adapters.php
 * Module Type: Service
 *
 * Purpose:
 *   Bridges canonical capability state to existing domain-owned settings without duplicating persistence.
 *
 * Responsibilities:
 *   - Describe stable lazy adapter IDs for existing operational master settings
 *   - Read and write app-setting-backed Boolean capability sources generically
 *   - Delegate compound Gallery Trash writes through the existing Trash lifecycle setter
 *   - Keep feature policy independent from service include order during bootstrap
 *   - Expose explicit persisted-setting keys for fresh-install seeding safety
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
 *   - Loaded by app/services/feature_flags.php; do not require this file directly.
 *   - Adapter functions are intentionally lazy because feature_flags.php loads before several domain services.
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

namespace Gallery\Services;

/**
 * Return metadata for domain adapters that preserve existing setting ownership.
 *
 * Function names are stored as strings instead of eager callables so registry
 * construction remains safe before later service modules have been required.
 *
 * @return array<string,array<string,mixed>> Adapter definitions keyed by stable adapter ID.
 */
function feature_capability_domain_adapters(): array
{
    return [
        'gallery_trash' => [
            'setting_key' => 'gallery_trash_enabled',
            'default_enabled' => true,
        ],
        'thumbnail_warmup' => [
            'setting_key' => 'thumbnail_background_warmup_enabled',
            'default_enabled' => true,
        ],
        'development_diagnostics' => [
            'setting_key' => 'dev_mode_enabled',
            'default_enabled' => false,
        ],
        'automatic_updates' => [
            'setting_key' => 'application_autoupdate_enabled',
            'default_enabled' => true,
        ],
    ];
}

/**
 * Return the persisted app_settings key owned by one capability source, when known.
 *
 * @param string $key Canonical capability key.
 * @param array<string,mixed> $definition Canonical capability definition.
 * @return ?string Persisted setting key or null for a derived/unpersisted source.
 */
function feature_capability_source_setting_key(string $key, array $definition): ?string
{
    $source = $definition['source'] ?? null;
    if (!is_array($source)) {
        return null;
    }

    return match ((string) ($source['type'] ?? '')) {
        'feature_flag' => feature_flag_setting_key($key),
        'app_setting' => trim((string) ($source['key'] ?? '')) ?: null,
        'domain_adapter' => (static function () use ($source): ?string {
            $adapterId = feature_flag_normalize_key((string) ($source['adapter'] ?? ''));
            $settingKey = (string) (feature_capability_domain_adapters()[$adapterId]['setting_key'] ?? '');
            return $settingKey !== '' ? $settingKey : null;
        })(),
        default => null,
    };
}

/**
 * Return whether the canonical persisted setting for one definition already exists.
 *
 * @param string $key Canonical capability key.
 * @param array<string,mixed> $definition Canonical capability definition.
 * @return bool True when an explicit persisted value exists.
 */
function feature_capability_source_has_explicit_value(string $key, array $definition): bool
{
    $settingKey = feature_capability_source_setting_key($key, $definition);
    if ($settingKey === null) {
        return false;
    }
    return app_setting($settingKey, null) !== null;
}

/**
 * Read one domain adapter without requiring its target module during registry construction.
 *
 * @param string $adapterId Stable adapter identifier.
 * @param bool $default Fallback when the target service is not available.
 * @return bool Configured state.
 */
function feature_capability_domain_adapter_read(string $adapterId, bool $default): bool
{
    $adapterId = feature_flag_normalize_key($adapterId);

    return match ($adapterId) {
        'gallery_trash' => function_exists(__NAMESPACE__ . '\\gallery_trash_enabled') ? gallery_trash_enabled() : $default,
        'thumbnail_warmup' => function_exists(__NAMESPACE__ . '\\thumbnail_warmup_enabled') ? thumbnail_warmup_enabled() : $default,
        'development_diagnostics' => function_exists(__NAMESPACE__ . '\\dev_mode_enabled') ? dev_mode_enabled() : $default,
        'automatic_updates' => function_exists(__NAMESPACE__ . '\\application_autoupdate_enabled') ? application_autoupdate_enabled() : $default,
        default => $default,
    };
}

/**
 * Persist one domain adapter through the existing canonical domain workflow.
 *
 * Gallery Trash deliberately uses its compound setter so disabling the master
 * preserves automatic-purge preference, retention days, purge batch, and the
 * existing retention-rearm safety behavior.
 *
 * @param string $adapterId Stable adapter identifier.
 * @param bool $enabled Configured administrator state.
 * @return bool True when the adapter accepted the write.
 */
function feature_capability_domain_adapter_write(string $adapterId, bool $enabled): bool
{
    $adapterId = feature_flag_normalize_key($adapterId);

    if ($adapterId === 'gallery_trash') {
        if (!function_exists(__NAMESPACE__ . '\\set_gallery_trash_settings')) {
            return false;
        }
        set_gallery_trash_settings(
            $enabled,
            gallery_trash_auto_purge_enabled(),
            gallery_trash_retention_days(),
            gallery_trash_purge_batch_size()
        );
        return true;
    }

    if ($adapterId === 'thumbnail_warmup') {
        if (!function_exists(__NAMESPACE__ . '\\set_thumbnail_warmup_enabled')) {
            return false;
        }
        set_thumbnail_warmup_enabled($enabled);
        return true;
    }

    if ($adapterId === 'development_diagnostics') {
        if (!function_exists(__NAMESPACE__ . '\\set_dev_mode_enabled')) {
            return false;
        }
        set_dev_mode_enabled($enabled);
        return true;
    }

    if ($adapterId === 'automatic_updates') {
        if (!function_exists(__NAMESPACE__ . '\\set_application_autoupdate_enabled')) {
            return false;
        }
        set_application_autoupdate_enabled($enabled);
        return true;
    }

    return false;
}

/**
 * Read configured state from one canonical capability definition.
 *
 * @param string $key Canonical capability key.
 * @param array<string,mixed> $definition Canonical capability definition.
 * @return bool Configured administrator state.
 */
function feature_capability_source_read(string $key, array $definition): bool
{
    $source = $definition['source'] ?? null;
    if (!is_array($source)) {
        return false;
    }

    $default = !array_key_exists('default_enabled', $definition) || $definition['default_enabled'] === true;
    $defaultValue = $default ? '1' : '0';
    $type = (string) ($source['type'] ?? '');

    if ($type === 'feature_flag') {
        return app_setting(feature_flag_setting_key($key), $defaultValue) !== '0';
    }

    if ($type === 'app_setting') {
        $settingKey = trim((string) ($source['key'] ?? ''));
        if ($settingKey === '') {
            return false;
        }
        return app_setting($settingKey, $defaultValue) !== '0';
    }

    if ($type === 'domain_adapter') {
        return feature_capability_domain_adapter_read((string) ($source['adapter'] ?? ''), $default);
    }

    if ($type === 'derived') {
        return !empty($source['configured_enabled']);
    }

    return false;
}

/**
 * Persist configured state through one canonical capability definition.
 *
 * @param string $key Canonical capability key.
 * @param array<string,mixed> $definition Canonical capability definition.
 * @param bool $enabled Configured administrator state.
 * @return bool True when the source accepted the write.
 */
function feature_capability_source_write(string $key, array $definition, bool $enabled): bool
{
    $source = $definition['source'] ?? null;
    if (!is_array($source)) {
        return false;
    }

    $type = (string) ($source['type'] ?? '');
    if ($type === 'feature_flag') {
        set_app_setting(feature_flag_setting_key($key), $enabled ? '1' : '0');
        return true;
    }

    if ($type === 'app_setting') {
        $settingKey = trim((string) ($source['key'] ?? ''));
        if ($settingKey === '') {
            return false;
        }
        set_app_setting($settingKey, $enabled ? '1' : '0');
        return true;
    }

    if ($type === 'domain_adapter') {
        return feature_capability_domain_adapter_write((string) ($source['adapter'] ?? ''), $enabled);
    }

    return false;
}
