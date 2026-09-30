<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_setup_wizard/preferences.php
 * Module Type: Service part
 *
 * Purpose:
 *   Adapts safe operational preference owners into the staged Setup Wizard.
 *
 * Responsibilities:
 *   - Expose ordinary scalar preferences whose canonical owners live outside Admin Settings
 *   - Strictly normalize staged checkbox, integer, and maintenance-time values
 *   - Persist through the existing SEO, thumbnail, Trash, update, and maintenance owners
 *   - Preserve coupled owner semantics instead of writing related app_settings rows ad hoc
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
 *   - Loaded only by app/services/admin_setup_wizard.php.
 *   - Destructive actions themselves are intentionally not adapted here. Gallery Trash
 *     retention preferences are staged configuration; actual purge actions remain specialist.
 */

declare(strict_types=1);

namespace Gallery\Services;

use InvalidArgumentException;

/**
 * Return operational scalar preferences that can safely participate in one staged apply.
 *
 * @return array<string,array{adapter:string,input_type:string,current:string,validation:array<string,mixed>}> Safe operational preferences.
 */
function admin_setup_wizard_operational_safe_settings(): array
{
    $settings = [];
    if (function_exists(__NAMESPACE__ . '\\thumbnail_warmup_enabled')
        && function_exists(__NAMESPACE__ . '\\set_thumbnail_warmup_enabled')) {
        $settings['thumbnail_background_warmup_enabled'] = admin_setup_wizard_operational_definition(
            'checkbox',
            thumbnail_warmup_enabled() ? '1' : '0'
        );
    }
    if (function_exists(__NAMESPACE__ . '\\seo_request_guard_enabled')
        && function_exists(__NAMESPACE__ . '\\seo_request_guard_logging_enabled')
        && function_exists(__NAMESPACE__ . '\\set_seo_request_guard_enabled')
        && function_exists(__NAMESPACE__ . '\\set_seo_request_guard_logging_enabled')) {
        $settings['seo_request_guard_enabled'] = admin_setup_wizard_operational_definition('checkbox', seo_request_guard_enabled() ? '1' : '0');
        $settings['seo_request_guard_logging_enabled'] = admin_setup_wizard_operational_definition('checkbox', seo_request_guard_logging_enabled() ? '1' : '0');
    }
    if (function_exists(__NAMESPACE__ . '\\gallery_trash_enabled')
        && function_exists(__NAMESPACE__ . '\\gallery_trash_auto_purge_enabled')
        && function_exists(__NAMESPACE__ . '\\gallery_trash_retention_days')
        && function_exists(__NAMESPACE__ . '\\gallery_trash_purge_batch_size')
        && function_exists(__NAMESPACE__ . '\\set_gallery_trash_settings')) {
        $settings['gallery_trash_enabled'] = admin_setup_wizard_operational_definition('checkbox', gallery_trash_enabled() ? '1' : '0');
        $settings['gallery_trash_auto_purge_enabled'] = admin_setup_wizard_operational_definition('checkbox', gallery_trash_auto_purge_enabled() ? '1' : '0');
        $settings['gallery_trash_retention_days'] = admin_setup_wizard_operational_definition('number', (string) gallery_trash_retention_days(), [
            'min' => GALLERY_TRASH_MIN_RETENTION_DAYS,
            'max' => GALLERY_TRASH_MAX_RETENTION_DAYS,
            'step' => 1,
        ]);
        $settings['gallery_trash_purge_batch'] = admin_setup_wizard_operational_definition('number', (string) gallery_trash_purge_batch_size(), [
            'min' => GALLERY_TRASH_MIN_PURGE_BATCH,
            'max' => GALLERY_TRASH_MAX_PURGE_BATCH,
            'step' => 1,
        ]);
    }
    if (function_exists(__NAMESPACE__ . '\\application_autoupdate_enabled')
        && function_exists(__NAMESPACE__ . '\\set_application_autoupdate_enabled')) {
        $settings['application_autoupdate_enabled'] = admin_setup_wizard_operational_definition('checkbox', application_autoupdate_enabled() ? '1' : '0');
    }
    if (function_exists(__NAMESPACE__ . '\\site_maintenance_enabled')
        && function_exists(__NAMESPACE__ . '\\site_maintenance_request_trigger_enabled')
        && function_exists(__NAMESPACE__ . '\\site_maintenance_utc_time')
        && function_exists(__NAMESPACE__ . '\\site_maintenance_window_minutes')
        && function_exists(__NAMESPACE__ . '\\site_maintenance_batch_size')
        && function_exists(__NAMESPACE__ . '\\site_maintenance_time_budget_seconds')
        && function_exists(__NAMESPACE__ . '\\set_site_maintenance_settings')) {
        $settings['site_maintenance_enabled'] = admin_setup_wizard_operational_definition('checkbox', site_maintenance_enabled() ? '1' : '0');
        $settings['site_maintenance_request_trigger_enabled'] = admin_setup_wizard_operational_definition('checkbox', site_maintenance_request_trigger_enabled() ? '1' : '0');
        $settings['site_maintenance_utc_time'] = admin_setup_wizard_operational_definition('time', site_maintenance_utc_time(), ['pattern' => '^(?:[01]\\d|2[0-3]):[0-5]\\d$']);
        $settings['site_maintenance_window_minutes'] = admin_setup_wizard_operational_definition('number', (string) site_maintenance_window_minutes(), [
            'min' => SITE_MAINTENANCE_MIN_WINDOW_MINUTES,
            'max' => SITE_MAINTENANCE_MAX_WINDOW_MINUTES,
            'step' => 1,
        ]);
        $settings['site_maintenance_batch_size'] = admin_setup_wizard_operational_definition('number', (string) site_maintenance_batch_size(), ['min' => 1, 'max' => 50, 'step' => 1]);
        $settings['site_maintenance_time_budget_seconds'] = admin_setup_wizard_operational_definition('number', (string) site_maintenance_time_budget_seconds(), ['min' => 3, 'max' => 120, 'step' => 1]);
    }
    return $settings;
}

/**
 * Build one operational adapter definition.
 *
 * @param string $inputType HTML-oriented input type.
 * @param string $current Canonical current value.
 * @param array<string,mixed> $validation Validation metadata.
 * @return array{adapter:string,input_type:string,current:string,validation:array<string,mixed>} Definition.
 */
function admin_setup_wizard_operational_definition(string $inputType, string $current, array $validation = []): array
{
    return [
        'adapter' => 'operational_safe',
        'input_type' => $inputType,
        'current' => $current,
        'validation' => $validation,
    ];
}

/**
 * Strictly normalize one operational preference for draft comparison and apply.
 *
 * @param string $id Stable setting identifier.
 * @param scalar|null $value Submitted scalar value.
 * @return string Canonical scalar value.
 */
function admin_setup_wizard_operational_setting_normalize(string $id, mixed $value): string
{
    if (in_array($id, [
        'thumbnail_background_warmup_enabled',
        'seo_request_guard_enabled',
        'seo_request_guard_logging_enabled',
        'gallery_trash_enabled',
        'gallery_trash_auto_purge_enabled',
        'application_autoupdate_enabled',
        'site_maintenance_enabled',
        'site_maintenance_request_trigger_enabled',
    ], true)) {
        return admin_setup_wizard_operational_bool($value);
    }
    if ($id === 'site_maintenance_utc_time') {
        if (!is_string($value) || preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', trim($value)) !== 1) {
            throw new InvalidArgumentException('Invalid maintenance UTC time.');
        }
        return trim($value);
    }
    $bounds = match ($id) {
        'gallery_trash_retention_days' => [GALLERY_TRASH_MIN_RETENTION_DAYS, GALLERY_TRASH_MAX_RETENTION_DAYS],
        'gallery_trash_purge_batch' => [GALLERY_TRASH_MIN_PURGE_BATCH, GALLERY_TRASH_MAX_PURGE_BATCH],
        'site_maintenance_window_minutes' => [SITE_MAINTENANCE_MIN_WINDOW_MINUTES, SITE_MAINTENANCE_MAX_WINDOW_MINUTES],
        'site_maintenance_batch_size' => [1, 50],
        'site_maintenance_time_budget_seconds' => [3, 120],
        default => null,
    };
    if ($bounds === null) {
        throw new InvalidArgumentException('Unsupported operational Setup Wizard setting.');
    }
    return (string) admin_setup_wizard_operational_integer($value, $bounds[0], $bounds[1]);
}

/**
 * Return the coupled owner group for one operational preference.
 *
 * @param string $id Stable setting identifier.
 * @return string Empty for independent values, otherwise a stable owner-group identifier.
 */
function admin_setup_wizard_operational_group(string $id): string
{
    if (str_starts_with($id, 'gallery_trash_')) return 'gallery_trash';
    if (str_starts_with($id, 'site_maintenance_')) return 'site_maintenance';
    return '';
}

/**
 * Persist all changed members of one coupled operational owner in a single canonical call.
 *
 * @param string $group Stable owner-group identifier.
 * @param array<string,mixed> $changes Normalized changed values for this group.
 * @return void
 */
function admin_setup_wizard_operational_group_save(string $group, array $changes): void
{
    if ($group === 'gallery_trash') {
        $enabled = gallery_trash_enabled();
        $autoPurge = gallery_trash_auto_purge_enabled();
        $retention = gallery_trash_retention_days();
        $batch = gallery_trash_purge_batch_size();
        foreach ($changes as $id => $value) {
            $normalized = admin_setup_wizard_operational_setting_normalize((string) $id, $value);
            match ((string) $id) {
                'gallery_trash_enabled' => $enabled = $normalized === '1',
                'gallery_trash_auto_purge_enabled' => $autoPurge = $normalized === '1',
                'gallery_trash_retention_days' => $retention = (int) $normalized,
                'gallery_trash_purge_batch' => $batch = (int) $normalized,
                default => throw new InvalidArgumentException('Unsupported Gallery Trash preference.'),
            };
        }
        set_gallery_trash_settings($enabled, $autoPurge, $retention, $batch);
        return;
    }
    if ($group === 'site_maintenance') {
        $enabled = site_maintenance_enabled();
        $utcTime = site_maintenance_utc_time();
        $batchSize = site_maintenance_batch_size();
        $timeBudget = site_maintenance_time_budget_seconds();
        $requestTrigger = site_maintenance_request_trigger_enabled();
        $windowMinutes = site_maintenance_window_minutes();
        foreach ($changes as $id => $value) {
            $normalized = admin_setup_wizard_operational_setting_normalize((string) $id, $value);
            match ((string) $id) {
                'site_maintenance_enabled' => $enabled = $normalized === '1',
                'site_maintenance_utc_time' => $utcTime = $normalized,
                'site_maintenance_batch_size' => $batchSize = (int) $normalized,
                'site_maintenance_time_budget_seconds' => $timeBudget = (int) $normalized,
                'site_maintenance_request_trigger_enabled' => $requestTrigger = $normalized === '1',
                'site_maintenance_window_minutes' => $windowMinutes = (int) $normalized,
                default => throw new InvalidArgumentException('Unsupported scheduled maintenance preference.'),
            };
        }
        set_site_maintenance_settings($enabled, $utcTime, $batchSize, $timeBudget, $requestTrigger, $windowMinutes);
        return;
    }
    throw new InvalidArgumentException('Unknown coupled operational preference group.');
}

/**
 * Persist one normalized operational preference through its canonical owner.
 *
 * @param string $id Stable setting identifier.
 * @param scalar|null $value Canonical normalized value.
 * @return void
 */
function admin_setup_wizard_operational_setting_save(string $id, mixed $value): void
{
    $normalized = admin_setup_wizard_operational_setting_normalize($id, $value);
    if ($id === 'thumbnail_background_warmup_enabled') {
        set_thumbnail_warmup_enabled($normalized === '1');
        return;
    }
    if ($id === 'seo_request_guard_enabled') {
        set_seo_request_guard_enabled($normalized === '1');
        return;
    }
    if ($id === 'seo_request_guard_logging_enabled') {
        set_seo_request_guard_logging_enabled($normalized === '1');
        return;
    }
    if ($id === 'application_autoupdate_enabled') {
        set_application_autoupdate_enabled($normalized === '1');
        return;
    }
    $group = admin_setup_wizard_operational_group($id);
    if ($group !== '') {
        admin_setup_wizard_operational_group_save($group, [$id => $normalized]);
        return;
    }
    throw new InvalidArgumentException('Unsupported operational Setup Wizard setting.');
}

/**
 * Normalize a submitted checkbox value without truthy-string coercion.
 *
 * @param scalar|null $value Submitted value.
 * @return string Canonical 1/0 value.
 */
function admin_setup_wizard_operational_bool(mixed $value): string
{
    if ($value === true || $value === 1 || $value === '1') return '1';
    if ($value === false || $value === 0 || $value === '0' || $value === '' || $value === null) return '0';
    throw new InvalidArgumentException('Invalid boolean preference.');
}

/**
 * Strictly parse a bounded integer without silently clamping invalid input.
 *
 * @param scalar|null $value Submitted value.
 * @param int $min Inclusive minimum.
 * @param int $max Inclusive maximum.
 * @return int Validated integer.
 */
function admin_setup_wizard_operational_integer(mixed $value, int $min, int $max): int
{
    if (is_int($value)) {
        $number = $value;
    } elseif (is_string($value) && preg_match('/^-?\\d+$/', trim($value)) === 1) {
        $number = (int) trim($value);
    } else {
        throw new InvalidArgumentException('Invalid integer preference.');
    }
    if ($number < $min || $number > $max) {
        throw new InvalidArgumentException('Preference is outside the allowed range.');
    }
    return $number;
}
