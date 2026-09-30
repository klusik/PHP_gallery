<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_setup_wizard/apply.php
 * Module Type: Service part
 *
 * Purpose:
 *   Owns Setup Wizard preflight, conflict checks, and canonical apply orchestration.
 *
 * Responsibilities:
 *   - Preflight capability and schema requirements
 *   - Compare originals inside the model transaction
 *   - Persist through canonical owners with base_url compensation
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
 *   - Do not register this part in app/services.php.
 */

declare(strict_types=1);

namespace Gallery\Services;

use InvalidArgumentException;
use function Gallery\Models\admin_setup_wizard_model_transaction;

/**
 * Apply normalized approved changes atomically through their canonical owners.
 *
 * @param array<string,mixed> $changes Normalized changed values keyed by id.
 * @param array<string,mixed> $original Original values required for every changed id.
 * @return array<string,mixed> Applied normalized values keyed by id.
 */
function admin_setup_wizard_apply(array $changes, array $original): array
{
    if ($changes === []) {
        return [];
    }
    $initialSteps = admin_setup_wizard_steps();
    $initialEntries = admin_setup_wizard_editable_entries($initialSteps);
    $normalized = admin_setup_wizard_validate_apply_payload($changes, $original, $initialEntries);
    admin_setup_wizard_preflight($normalized, $initialEntries);
    $settingKeys = admin_setup_wizard_storage_keys($normalized, $initialEntries);
    $telemetrySettingKeys = admin_setup_wizard_telemetry_storage_keys($normalized, $initialEntries);
    $urlChanged = false;
    $restoreUrl = null;

    try {
        return admin_setup_wizard_model_transaction(
            $settingKeys,
            /** Revalidate and persist the normalized changes under row locks. @return array<string,mixed> Applied values. */
            static function () use ($normalized, $original, &$urlChanged, &$restoreUrl): array {
                app_settings_reset_request_cache();
                $freshEntries = admin_setup_wizard_editable_entries(admin_setup_wizard_steps());
                $freshNormalized = admin_setup_wizard_validate_apply_payload($normalized, $original, $freshEntries);
                admin_setup_wizard_preflight($freshNormalized, $freshEntries);
                foreach ($freshNormalized as $id => $value) {
                    $entry = $freshEntries[$id];
                    $expected = admin_setup_wizard_normalize_original($entry, $original[$id]);
                    $current = admin_setup_wizard_normalize_original($entry, $entry['current'] ?? null);
                    if (!admin_setup_wizard_values_equal($expected, $current)) {
                        throw new AdminSetupWizardException('admin.setup_wizard.error.conflict');
                    }
                }
                $operationalGroups = [];
                foreach ($freshNormalized as $id => $value) {
                    if ($id === 'base_url') {
                        continue;
                    }
                    $entry = $freshEntries[$id];
                    if (($entry['wizard_adapter'] ?? '') === 'operational_safe') {
                        $group = admin_setup_wizard_operational_group((string) $id);
                        if ($group !== '') {
                            $operationalGroups[$group][(string) $id] = $value;
                            continue;
                        }
                    }
                    admin_setup_wizard_save_entry($entry, $value);
                }
                foreach ($operationalGroups as $group => $groupChanges) {
                    admin_setup_wizard_operational_group_save((string) $group, $groupChanges);
                }
                if (array_key_exists('base_url', $freshNormalized)) {
                    $restoreUrl = site_url_save_reversible((string) $freshNormalized['base_url']);
                    $urlChanged = true;
                }
                return $freshNormalized;
            },
            /** Restore a file-backed URL when the transaction cannot commit. @return void */
            static function () use (&$urlChanged, &$restoreUrl): void {
                if ($urlChanged && is_callable($restoreUrl)) {
                    $restoreUrl();
                }
            },
            $telemetrySettingKeys
        );
    } finally {
        app_settings_reset_request_cache();
    }
}

/**
 * Validate an apply payload against one trusted registry snapshot.
 *
 * @param array<string,mixed> $changes Candidate changes.
 * @param array<string,mixed> $original Original values.
 * @param array<string,array<string,mixed>> $entries Editable entries.
 * @return array<string,mixed> Normalized changes.
 */
function admin_setup_wizard_validate_apply_payload(array $changes, array $original, array $entries): array
{
    $normalized = [];
    foreach ($changes as $id => $value) {
        $id = (string) $id;
        if (!isset($entries[$id]) || !array_key_exists($id, $original)) {
            throw new AdminSetupWizardException('admin.setup_wizard.error.invalid_draft');
        }
        try {
            $normalized[$id] = admin_setup_wizard_normalize_entry($entries[$id], $value);
            admin_setup_wizard_normalize_original($entries[$id], $original[$id]);
        } catch (InvalidArgumentException) {
            throw new AdminSetupWizardException('admin.setup_wizard.error.invalid_value');
        }
    }
    ksort($normalized, SORT_STRING);
    return $normalized;
}

/**
 * Refuse unavailable capability or schema states before the first write.
 *
 * @param array<string,mixed> $changes Normalized changes.
 * @param array<string,array<string,mixed>> $entries Editable entries.
 * @return void
 */
function admin_setup_wizard_preflight(array $changes, array $entries): void
{
    foreach ($changes as $id => $_value) {
        if (!isset($entries[$id]) || !empty($entries[$id]['unavailable']) || empty($entries[$id]['wizard_editable'])) {
            throw new AdminSetupWizardException('admin.setup_wizard.error.unavailable');
        }
    }
    foreach (array_keys($changes) as $id) {
        if (($entries[$id]['wizard_adapter'] ?? '') === 'theme_layout_safe'
            && !theme_layout_safe_setting_available((string) $id)) {
            throw new AdminSetupWizardException('admin.setup_wizard.error.unavailable');
        }
    }
    if (array_key_exists('public_home_search_enabled', $changes)
        && function_exists(__NAMESPACE__ . '\\feature_capability_effective_enabled')
        && !feature_capability_effective_enabled('public_search')) {
        throw new AdminSetupWizardException('admin.setup_wizard.error.unavailable');
    }
    if (array_key_exists('exif_gps_maps_default_enabled', $changes)) {
        $status = presentation_gps_override_schema_status();
        if (!schema_inspection_is_available($status)) {
            throw new AdminSetupWizardException('admin.setup_wizard.error.unavailable');
        }
    }
    $telemetryChanges = array_filter(
        array_keys($changes),
        static fn (string $id): bool => (($entries[$id]['wizard_adapter'] ?? '') === 'telemetry_safe')
    );
    if ($telemetryChanges !== []) {
        $status = presentation_telemetry_settings_schema_status();
        if (!schema_inspection_is_available($status)) {
            throw new AdminSetupWizardException('admin.setup_wizard.error.unavailable');
        }
    }
    $appSettingChanges = array_filter(
        array_keys($changes),
        static fn (string $id): bool => $id !== 'base_url' && (($entries[$id]['wizard_adapter'] ?? '') !== 'telemetry_safe')
    );
    if ($appSettingChanges !== []) {
        $status = schema_inspection_table('app_settings');
        if (!schema_inspection_is_available($status)) {
            throw new AdminSetupWizardException('admin.setup_wizard.error.unavailable');
        }
    }
}

/**
 * Return canonical app_settings keys that must be locked before comparison.
 *
 * @param array<string,mixed> $changes Normalized changes.
 * @param array<string,array<string,mixed>> $entries Editable entries.
 * @return list<string> Unique storage keys.
 */
function admin_setup_wizard_storage_keys(array $changes, array $entries): array
{
    $keys = [];
    foreach (array_keys($changes) as $id) {
        if ($id === 'base_url') {
            continue;
        }
        $entry = $entries[$id] ?? [];
        $adapter = (string) ($entry['wizard_adapter'] ?? '');
        if ($adapter === 'telemetry_safe') {
            continue;
        }
        $key = in_array($adapter, ['theme_basic_appearance', 'theme_layout_safe', 'admin_upload_safe', 'browser_upload_safe', 'operational_safe'], true)
            ? $id
            : (string) ($entry['key'] ?? '');
        if ($key !== '') {
            $keys[] = $key;
        }
    }
    if (array_key_exists('public_language_selector_enabled', $changes)
        || array_key_exists('public_language_selector_languages', $changes)) {
        $keys[] = 'public_language_selector_enabled';
        $keys[] = 'public_language_selector_languages';
    }
    if (array_key_exists('browser_upload_default_worker_count', $changes)) {
        // Validation depends on the separately configurable worker ceilings, so lock those
        // rows as read dependencies before rebuilding the fresh catalog under the transaction.
        $keys[] = 'browser_upload_max_worker_count';
        $keys[] = 'browser_upload_hard_worker_cap';
    }
    if (array_intersect(array_keys($changes), ['gallery_trash_enabled', 'gallery_trash_auto_purge_enabled', 'gallery_trash_retention_days', 'gallery_trash_purge_batch']) !== []) {
        // The canonical Trash owner persists the coupled preference set in one call. Lock every
        // member so a concurrent Admin update cannot be overwritten by a preserved sibling value.
        array_push($keys, 'gallery_trash_enabled', 'gallery_trash_auto_purge_enabled', 'gallery_trash_retention_days', 'gallery_trash_purge_batch');
    }
    if (array_intersect(array_keys($changes), ['site_maintenance_enabled', 'site_maintenance_request_trigger_enabled', 'site_maintenance_utc_time', 'site_maintenance_window_minutes', 'site_maintenance_batch_size', 'site_maintenance_time_budget_seconds']) !== []) {
        // Scheduled maintenance is likewise persisted as a coherent owner-level preference set.
        array_push($keys, 'site_maintenance_enabled', 'site_maintenance_request_trigger_enabled', 'site_maintenance_utc_time', 'site_maintenance_window_minutes', 'site_maintenance_batch_size', 'site_maintenance_time_budget_seconds');
    }
    return array_values(array_unique($keys));
}

/**
 * Return telemetry_settings keys that must be locked before comparison.
 *
 * @param array<string,mixed> $changes Normalized changes.
 * @param array<string,array<string,mixed>> $entries Editable entries.
 * @return list<string> Unique telemetry setting keys.
 */
function admin_setup_wizard_telemetry_storage_keys(array $changes, array $entries): array
{
    $keys = [];
    foreach (array_keys($changes) as $id) {
        if (($entries[$id]['wizard_adapter'] ?? '') === 'telemetry_safe') {
            $keys[] = (string) $id;
        }
    }
    sort($keys, SORT_STRING);
    return array_values(array_unique($keys));
}

/**
 * Persist one non-file setting through its canonical domain owner.
 *
 * @param array{id?:string,wizard_adapter?:string,central_editable?:bool,unavailable?:bool,current?:mixed,storage_key?:string} $entry Trusted wizard entry.
 * @param scalar|array<array-key,mixed>|null $value Canonical normalized value.
 * @return void
 */
function admin_setup_wizard_save_entry(array $entry, mixed $value): void
{
    $id = (string) ($entry['id'] ?? '');
    if (($entry['wizard_adapter'] ?? '') === 'theme_basic_appearance') {
        theme_basic_appearance_save($id, $value);
        return;
    }
    if (($entry['wizard_adapter'] ?? '') === 'theme_layout_safe') {
        theme_layout_safe_save($id, $value);
        return;
    }
    if (($entry['wizard_adapter'] ?? '') === 'admin_upload_safe') {
        match ($id) {
            'admin_upload_client_format_mode' => save_admin_upload_client_format_mode($value),
            'admin_upload_auto_rename_enabled' => save_admin_upload_auto_rename_setting($value),
            default => throw new InvalidArgumentException('Unsupported Admin upload setting.'),
        };
        return;
    }
    if (($entry['wizard_adapter'] ?? '') === 'browser_upload_safe') {
        browser_upload_safe_scalar_save($id, $value);
        return;
    }
    if (($entry['wizard_adapter'] ?? '') === 'telemetry_safe') {
        telemetry_admin_setting_save($id, $value);
        return;
    }
    if (($entry['wizard_adapter'] ?? '') === 'operational_safe') {
        admin_setup_wizard_operational_setting_save($id, $value);
        return;
    }
    admin_settings_save_editable_value($id, $value);
}
