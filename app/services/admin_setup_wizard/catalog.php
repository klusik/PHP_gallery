<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_setup_wizard/catalog.php
 * Module Type: Service part
 *
 * Purpose:
 *   Builds the registry-backed Setup Wizard catalog and canonical value adapters.
 *
 * Responsibilities:
 *   - Decorate all canonical registry entries for wizard presentation
 *   - Normalize safe central and basic Theme values
 *   - Prepare staged Theme preview data
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

/**
 * Build all wizard steps from one canonical Settings registry snapshot.
 *
 * @return array<string,array{id:string,definition:array<string,mixed>,entries:array<string,array<string,mixed>>}> Steps keyed by section id.
 */
function admin_setup_wizard_steps(): array
{
    $sections = admin_settings_sections();
    $registry = admin_settings_registry();
    $themeValues = theme_basic_appearance_settings();
    $steps = [];

    foreach ($sections as $sectionId => $definition) {
        $steps[$sectionId] = [
            'id' => $sectionId,
            'definition' => $definition,
            'entries' => [],
        ];
    }

    foreach ($registry as $entryId => $entry) {
        $entryId = (string) $entryId;
        $sectionId = admin_settings_section_normalize($entry['group'] ?? 'general');
        if (!isset($steps[$sectionId])) {
            continue;
        }
        if (array_key_exists($entryId, $themeValues)) {
            $entry['current'] = $themeValues[$entryId];
            $entry['value'] = $themeValues[$entryId];
            $entry['wizard_adapter'] = 'theme_basic_appearance';
            $entry['migration_required'] = false;
            $themeInput = admin_setup_wizard_theme_input_definition($entryId);
            $entry['input_type'] = $themeInput['input_type'];
            $entry['validation'] = $themeInput['validation'];
        } elseif ($entryId === 'base_url') {
            $entry['current'] = site_url_current_value();
            $entry['value'] = $entry['current'];
        }
        $unavailable = !empty($entry['migration_required']);
        $editable = !in_array((string) ($entry['sensitivity'] ?? 'normal'), ['secret', 'destructive'], true);
        $editable = $editable && !$unavailable
            && (!empty($entry['central_editable']) || ($entry['wizard_adapter'] ?? '') === 'theme_basic_appearance');
        $entry['wizard_editable'] = $editable;
        $entry['read_only'] = !$editable;
        $entry['unavailable'] = $unavailable;
        $entry['included'] = $editable;
        $entry['selected'] = $editable;
        $entry['value'] = $entry['current'] ?? '';
        $entry['example_key'] = 'admin.settings.item.' . $entryId . '.example';
        $entry['example'] = $entry['example_key'];
        $entry['deferred_url'] = !$editable ? (string) ($entry['specialized_url'] ?? '') : '';
        $entry['options'] = admin_setup_wizard_entry_options($entryId, $entry);
        $steps[$sectionId]['entries'][$entryId] = $entry;
    }

    return $steps;
}

/**
 * Return input metadata for the safe Theme scalar adapter.
 *
 * @param string $id Stable Theme setting id.
 * @return array{input_type:string,validation:array<string,mixed>} Input type and bounds.
 */
function admin_setup_wizard_theme_input_definition(string $id): array
{
    if (in_array($id, [
        'theme_accent', 'theme_accent_dark', 'theme_paper', 'theme_panel',
        'theme_gallery_panel', 'theme_header_text', 'theme_hero_text',
    ], true)) {
        return ['input_type' => 'color', 'validation' => ['pattern' => '^#[0-9a-fA-F]{6}$']];
    }
    if ($id === 'theme_radius') {
        return ['input_type' => 'range', 'validation' => ['min' => 0, 'max' => 32, 'step' => 1]];
    }
    if ($id === 'theme_page_width_custom') {
        return ['input_type' => 'number', 'validation' => ['min' => 1024, 'max' => 2048, 'step' => 1]];
    }
    if ($id === 'theme_font') {
        return ['input_type' => 'select', 'validation' => ['allowed' => ['serif', 'sans']]];
    }
    return ['input_type' => 'select', 'validation' => ['allowed' => ['default', 'wide', 'custom', 'full']]];
}

/**
 * Return presentation options for a supported select-like wizard entry.
 *
 * @param string $id Stable setting id.
 * @param array<string,mixed> $entry Registry entry.
 * @return array<string,string> Option labels keyed by persisted value.
 */
function admin_setup_wizard_entry_options(string $id, array $entry): array
{
    $fixed = [
        'theme_font' => [
            'serif' => t('admin.theme.appearance.font_serif', 'Classic serif'),
            'sans' => t('admin.theme.appearance.font_sans', 'Clean sans-serif'),
        ],
        'theme_page_width' => [
            'default' => t('admin.theme.appearance.page_width_default', 'Default'),
            'wide' => t('admin.theme.appearance.page_width_wide', 'Wider'),
            'custom' => t('admin.theme.appearance.page_width_custom', 'Custom'),
            'full' => t('admin.theme.appearance.page_width_full', 'Full width'),
        ],
    ];
    if (isset($fixed[$id])) {
        return $fixed[$id];
    }
    $allowed = is_array($entry['validation']['allowed'] ?? null)
        ? $entry['validation']['allowed']
        : (is_array($entry['allowed'] ?? null) ? $entry['allowed'] : []);
    $options = [];
    foreach ($allowed as $key => $value) {
        $option = is_int($key) ? (string) $value : (string) $key;
        $options[$option] = is_int($key) ? (string) $value : (string) $value;
    }
    if ($id === 'public_language' && $options !== []) {
        $presentations = translation_language_presentation();
        foreach ($options as $code => $_label) {
            $name = trim((string) ($presentations[$code]['name'] ?? ''));
            $options[$code] = $name !== '' ? $name . ' (' . $code . ')' : strtoupper($code);
        }
    }
    return $options;
}

/**
 * Return every registry entry from one trusted steps snapshot.
 *
 * @param array<string,array<string,mixed>> $steps Trusted wizard steps.
 * @return array<string,array<string,mixed>> All entries keyed by setting id.
 */
function admin_setup_wizard_all_entries(array $steps): array
{
    $entries = [];
    foreach ($steps as $step) {
        foreach ((array) ($step['entries'] ?? []) as $id => $entry) {
            if (is_array($entry)) {
                $entries[(string) $id] = $entry;
            }
        }
    }
    return $entries;
}

/**
 * Return all editable entries from one trusted steps snapshot.
 *
 * @param array<string,array<string,mixed>> $steps Trusted wizard steps.
 * @return array<string,array<string,mixed>> Editable entries keyed by setting id.
 */
function admin_setup_wizard_editable_entries(array $steps): array
{
    $entries = [];
    foreach ($steps as $step) {
        foreach ((array) ($step['entries'] ?? []) as $id => $entry) {
            if (is_array($entry) && !empty($entry['wizard_editable'])) {
                $entries[(string) $id] = $entry;
            }
        }
    }
    return $entries;
}

/**
 * Normalize one setting through its canonical registry or Theme adapter.
 *
 * @param string $id Stable setting id.
 * @param scalar|array<array-key,mixed>|object|null $value Submitted transport value, including invalid types to reject.
 * @return scalar|array<array-key,mixed>|null Canonical normalized value.
 */
function admin_setup_wizard_normalize_change(string $id, mixed $value): mixed
{
    $entries = admin_setup_wizard_editable_entries(admin_setup_wizard_steps());
    if (!isset($entries[$id])) {
        throw new InvalidArgumentException('Setting is unavailable in the Setup Wizard.');
    }
    return admin_setup_wizard_normalize_entry($entries[$id], $value);
}

/**
 * Normalize one value using a preloaded entry without re-reading the registry.
 *
 * @param array{id?:string,wizard_adapter?:string,central_editable?:bool,unavailable?:bool,current?:mixed} $entry Trusted wizard entry.
 * @param scalar|array<array-key,mixed>|object|null $value Submitted transport value, including invalid types to reject.
 * @return scalar|array<array-key,mixed>|null Canonical normalized value.
 */
function admin_setup_wizard_normalize_entry(array $entry, mixed $value): mixed
{
    $id = (string) ($entry['id'] ?? '');
    if (is_array($value) && !in_array($id, ADMIN_SETUP_WIZARD_STRUCTURED_IDS, true)) {
        throw new InvalidArgumentException('Structured values are not supported for this setting.');
    }
    if (!is_array($value) && !is_scalar($value) && $value !== null) {
        throw new InvalidArgumentException('Unsupported setting value type.');
    }
    if (($entry['wizard_adapter'] ?? '') === 'theme_basic_appearance') {
        return theme_basic_appearance_normalize($id, $value);
    }
    if (empty($entry['central_editable']) || !empty($entry['unavailable'])) {
        throw new InvalidArgumentException('Setting is unavailable in the Setup Wizard.');
    }
    return admin_settings_normalize_editable_value($entry, $value);
}

/**
 * Normalize an original value for comparison, retaining a legacy empty base_url.
 *
 * @param array{id?:string,wizard_adapter?:string,central_editable?:bool,unavailable?:bool,current?:mixed} $entry Trusted wizard entry.
 * @param scalar|array<array-key,mixed>|object|null $value Original transport value, including invalid types to reject.
 * @return scalar|array<array-key,mixed>|null Comparable original value.
 */
function admin_setup_wizard_normalize_original(array $entry, mixed $value): mixed
{
    if ((string) ($entry['id'] ?? '') === 'base_url') {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Invalid original website URL.');
        }
        return rtrim(trim($value), '/');
    }
    return admin_setup_wizard_normalize_entry($entry, $value);
}

/**
 * Compare scalar or structured normalized values without PHP type coercion.
 *
 * @param scalar|array<string,mixed>|list<scalar>|null $left First normalized value.
 * @param scalar|array<string,mixed>|list<scalar>|null $right Second normalized value.
 * @return bool True when values are structurally identical.
 */
function admin_setup_wizard_values_equal(mixed $left, mixed $right): bool
{
    return json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        === json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Return current Theme preview values overlaid with normalized staged changes.
 *
 * @param array<string,mixed> $draft Valid draft.
 * @return array<string,string> Preview values keyed by Theme setting id.
 */
function admin_setup_wizard_theme_preview(array $draft): array
{
    $theme = theme_basic_appearance_settings();
    $preview = [];
    foreach ($theme as $id => $value) {
        $preview[substr($id, 6)] = $value;
    }
    foreach (ADMIN_SETUP_WIZARD_THEME_IDS as $id) {
        if (array_key_exists($id, $draft['changes'] ?? [])) {
            $preview[substr($id, 6)] = (string) $draft['changes'][$id];
        }
    }
    $preview['site_name'] = array_key_exists('site_name', $draft['changes'] ?? [])
        ? (string) $draft['changes']['site_name']
        : site_name();
    return $preview;
}
