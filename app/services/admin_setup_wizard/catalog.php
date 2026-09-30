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
 *   - Normalize safe central, Theme, upload, telemetry, and operational values
 *   - Classify entries into compact subsections and progressive-disclosure tiers
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
    $layoutValues = theme_layout_safe_settings();
    $uploadValues = admin_setup_wizard_upload_safe_settings();
    $telemetryValues = admin_setup_wizard_telemetry_safe_settings();
    $operationalValues = admin_setup_wizard_operational_safe_settings();
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
        } elseif (array_key_exists($entryId, $layoutValues)) {
            $entry['current'] = $layoutValues[$entryId];
            $entry['value'] = $layoutValues[$entryId];
            $entry['wizard_adapter'] = 'theme_layout_safe';
            $entry['wizard_available'] = theme_layout_safe_setting_available($entryId);
            $layoutInput = admin_setup_wizard_theme_layout_input_definition($entryId);
            $entry['input_type'] = $layoutInput['input_type'];
            $entry['validation'] = $layoutInput['validation'];
        } elseif (array_key_exists($entryId, $uploadValues)) {
            $uploadInput = $uploadValues[$entryId];
            $entry['current'] = $uploadInput['current'];
            $entry['value'] = $uploadInput['current'];
            $entry['wizard_adapter'] = $uploadInput['adapter'];
            $entry['input_type'] = $uploadInput['input_type'];
            $entry['validation'] = $uploadInput['validation'];
            $entry['migration_required'] = false;
        } elseif (array_key_exists($entryId, $telemetryValues)) {
            $telemetryInput = $telemetryValues[$entryId];
            $entry['current'] = $telemetryInput['current'];
            $entry['value'] = $telemetryInput['current'];
            $entry['wizard_adapter'] = 'telemetry_safe';
            $entry['input_type'] = $telemetryInput['input_type'];
            $entry['validation'] = $telemetryInput['validation'];
            $entry['wizard_available'] = $telemetryInput['available'];
            $entry['migration_required'] = !$telemetryInput['available'];
        } elseif (array_key_exists($entryId, $operationalValues)) {
            $operationalInput = $operationalValues[$entryId];
            $entry['current'] = $operationalInput['current'];
            $entry['value'] = $operationalInput['current'];
            $entry['wizard_adapter'] = 'operational_safe';
            $entry['input_type'] = $operationalInput['input_type'];
            $entry['validation'] = $operationalInput['validation'];
            $entry['migration_required'] = false;
        } elseif ($entryId === 'base_url') {
            $entry['current'] = site_url_current_value();
            $entry['value'] = $entry['current'];
        }
        $unavailable = !empty($entry['migration_required']) || (array_key_exists('wizard_available', $entry) && empty($entry['wizard_available']));
        $adapter = (string) ($entry['wizard_adapter'] ?? '');
        $adapterEditable = in_array($adapter, ['theme_basic_appearance', 'theme_layout_safe', 'admin_upload_safe', 'browser_upload_safe', 'telemetry_safe', 'operational_safe'], true);
        $sensitivity = (string) ($entry['sensitivity'] ?? 'normal');
        $sensitivityEditable = !in_array($sensitivity, ['secret', 'destructive'], true) || $adapter === 'operational_safe';
        $editable = $sensitivityEditable && !$unavailable && (!empty($entry['central_editable']) || $adapterEditable);
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
        $entry = array_replace($entry, admin_setup_wizard_entry_presentation($entryId, $entry));
        $steps[$sectionId]['entries'][$entryId] = $entry;
    }

    return $steps;
}

/**
 * Return wizard-only presentation metadata without changing the canonical Settings owner.
 *
 * The top-level registry sections remain the server navigation contract. Subsections are
 * progressive-enhancement presentation groups inside one staged form, so moving between
 * them can never commit values or lose the current section draft.
 *
 * @param string $id Stable setting identifier.
 * @param array<string,mixed> $entry Prepared registry entry.
 * @return array{wizard_tier:string,wizard_subsection:string,wizard_subsection_key:string,wizard_subsection_label:string} Presentation metadata.
 */
function admin_setup_wizard_entry_presentation(string $id, array $entry): array
{
    $section = admin_settings_section_normalize($entry['group'] ?? 'general');
    $editable = !empty($entry['wizard_editable']);
    $tier = $editable ? 'advanced' : 'status';

    $essential = [
        'site_name', 'public_language', 'base_url', 'url_rewrite_enabled', 'public_home_search_enabled',
        'theme_page_width', 'theme_gallery_description_layout', 'home_gallery_grid_columns', 'home_gallery_grid_rows',
        'tag_page_gallery_grid_columns', 'tag_page_gallery_grid_rows', 'tag_page_gallery_description_layout',
        'theme_hero_tag_visible_limit', 'theme_hero_tag_sort_mode', 'public_thumbnail_rendering_mode',
        'theme_lightbox_browsing_mode', 'exif_gps_maps_default_enabled', 'admin_upload_client_format_mode',
        'admin_upload_auto_rename_enabled', 'browser_upload_enabled', 'browser_upload_default_worker_count',
        'browser_upload_max_items_per_batch', 'telemetry_enabled', 'telemetry_public_usage_enabled',
        'telemetry_respect_dnt', 'seo_request_guard_enabled', 'gallery_trash_enabled',
        'application_autoupdate_enabled', 'site_maintenance_enabled',
    ];
    if ($editable && in_array($id, $essential, true)) {
        $tier = 'essential';
    }
    if ($editable && (str_starts_with($id, 'site_maintenance_')
        || in_array($id, ['gallery_trash_auto_purge_enabled', 'gallery_trash_retention_days', 'gallery_trash_purge_batch'], true))) {
        $tier = 'expert';
    }

    [$subsection, $label] = match ($section) {
        'general' => str_starts_with($id, 'public_language_selector_')
            ? ['viewer_language', 'Viewer language selector']
            : (in_array($id, ['site_name', 'public_language'], true)
                ? ['identity_language', 'Identity and language']
                : ['public_experience', 'Public experience']),
        'site' => ['public_address', 'Public address'],
        'appearance' => str_contains($id, 'gps_pin')
            ? ['map_markers', 'Map markers']
            : ((str_contains($id, 'grid') || str_starts_with($id, 'pagination_') || str_contains($id, 'description_layout') || $id === 'theme_gallery_count_badge_enabled')
                ? ['gallery_layout', 'Gallery layout']
                : ['visual_style', 'Visual style']),
        'content' => str_starts_with($id, 'theme_hero_tag_')
            ? ['hero_tags', 'Hero tags']
            : (str_starts_with($id, 'tag_page_') || $id === 'tag_metadata'
                ? ['tag_pages', 'Tag pages']
                : ['content_tools', 'Content tools']),
        'media' => in_array($id, ['public_thumbnail_rendering_mode', 'theme_lightbox_browsing_mode', 'exif_gps_maps_default_enabled'], true)
            ? ['public_media', 'Public media behavior']
            : ((str_contains($id, 'thumbnail') || $id === 'thumbnail_background_warmup_enabled')
                ? ['thumbnails', 'Thumbnails']
                : ['media_tools', 'Media tools']),
        'uploads' => str_contains($id, 'api_key')
            ? ['upload_api', 'Upload API']
            : (in_array($id, ['admin_upload_client_format_mode', 'admin_upload_auto_rename_enabled', 'browser_upload_enabled', 'browser_upload_default_worker_count', 'browser_upload_max_items_per_batch'], true)
                ? ['upload_basics', 'Upload basics']
                : ['upload_performance', 'Upload performance']),
        'privacy' => str_starts_with($id, 'telemetry_')
            ? ['analytics', 'Analytics and telemetry']
            : (str_starts_with($id, 'gallery_trash_')
                ? ['trash', 'Gallery Trash']
                : (str_starts_with($id, 'site_maintenance_') || $id === 'application_autoupdate_enabled'
                    ? ['maintenance', 'Maintenance and updates']
                    : ['security', 'Security and privacy'])),
        'advanced' => str_starts_with($id, 'database_')
            ? ['database', 'Database operations']
            : ((str_starts_with($id, 'account_') || str_starts_with($id, 'password_reset_') || $id === 'google_account_linking')
                ? ['account', 'Account and recovery']
                : ((str_starts_with($id, 'theme_') || $id === 'custom_css')
                    ? ['theme_tools', 'Theme tools']
                    : (str_starts_with($id, 'site_maintenance_')
                        ? ['maintenance_tools', 'Maintenance tools']
                        : (in_array($id, ['integrity_check', 'runtime_diagnostics', 'admin_logs', 'gallery_report', 'feature_flags'], true)
                            ? ['diagnostics', 'Diagnostics and operations']
                            : ['integrations', 'Integrations and specialist tools'])))),
        default => ['settings', 'Settings'],
    };

    return [
        'wizard_tier' => $tier,
        'wizard_subsection' => $subsection,
        'wizard_subsection_key' => 'admin.setup_wizard.subsection.' . $subsection,
        'wizard_subsection_label' => $label,
    ];
}

/**
 * Return upload preferences whose existing owners expose independent scalar validation.
 *
 * Coupled browser worker caps, byte-size policies, thumbnail rebuild tuning, and API keys
 * remain deferred to their specialized owners.
 *
 * @return array<string,array{adapter:string,input_type:string,current:string,validation:array<string,mixed>}> Safe upload settings.
 */
function admin_setup_wizard_upload_safe_settings(): array
{
    $settings = [];
    if (function_exists(__NAMESPACE__ . '\admin_upload_client_format_mode')) {
        $settings['admin_upload_client_format_mode'] = [
            'adapter' => 'admin_upload_safe',
            'input_type' => 'select',
            'current' => admin_upload_client_format_mode(),
            'validation' => ['allowed' => ['server_supported', 'phone_jpeg']],
        ];
    }
    if (function_exists(__NAMESPACE__ . '\admin_upload_auto_rename_enabled')) {
        $settings['admin_upload_auto_rename_enabled'] = [
            'adapter' => 'admin_upload_safe',
            'input_type' => 'checkbox',
            'current' => admin_upload_auto_rename_enabled() ? '1' : '0',
            'validation' => [],
        ];
    }
    if (function_exists(__NAMESPACE__ . '\browser_upload_safe_scalar_settings')) {
        foreach (browser_upload_safe_scalar_settings() as $id => $definition) {
            $settings[$id] = [
                'adapter' => 'browser_upload_safe',
                'input_type' => (string) ($definition['input_type'] ?? 'text'),
                'current' => (string) ($definition['current'] ?? ''),
                'validation' => (array) ($definition['validation'] ?? []),
            ];
        }
    }
    return $settings;
}

/**
 * Return safe telemetry preferences from the telemetry settings owner when available.
 *
 * @return array<string,array{input_type:string,current:string,validation:array<string,int>,available:bool}> Safe telemetry settings.
 */
function admin_setup_wizard_telemetry_safe_settings(): array
{
    if (!function_exists(__NAMESPACE__ . '\telemetry_admin_editable_settings')) {
        return [];
    }
    return telemetry_admin_editable_settings();
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
 * Return input metadata for safe Theme layout, card, grid, lightbox, and GPS controls.
 *
 * @param string $id Stable Theme setting id.
 * @return array{input_type:string,validation:array<string,mixed>} Input type and bounds.
 */
function admin_setup_wizard_theme_layout_input_definition(string $id): array
{
    if (in_array($id, [
        'theme_gps_pin_enabled',
        'theme_gps_pin_background_enabled',
        'theme_gallery_count_badge_enabled',
        'pagination_enabled',
        'theme_hero_tag_display_all',
        'theme_hero_tag_scrollbar_enabled',
    ], true)) {
        return ['input_type' => 'checkbox', 'validation' => []];
    }
    if ($id === 'theme_hero_tag_visible_limit') {
        return ['input_type' => 'range', 'validation' => ['min' => 1, 'max' => 200, 'step' => 1]];
    }
    if ($id === 'theme_hero_tag_scrollbar_rows') {
        return ['input_type' => 'range', 'validation' => ['min' => 1, 'max' => 12, 'step' => 1]];
    }
    if ($id === 'theme_hero_tag_sort_mode') {
        return ['input_type' => 'select', 'validation' => ['allowed' => ['usage', 'alphabetical']]];
    }
    if ($id === 'theme_gps_pin_size') {
        return ['input_type' => 'range', 'validation' => ['min' => 14, 'max' => 48, 'step' => 1]];
    }
    if ($id === 'theme_gps_pin_background_size') {
        return ['input_type' => 'range', 'validation' => ['min' => 0, 'max' => 48, 'step' => 1]];
    }
    if (in_array($id, ['pagination_columns', 'home_gallery_grid_columns', 'tag_page_gallery_grid_columns'], true)) {
        return ['input_type' => 'range', 'validation' => ['min' => 1, 'max' => CMS_PAGINATION_MAX_COLUMNS, 'step' => 1]];
    }
    if (in_array($id, ['pagination_rows', 'home_gallery_grid_rows', 'tag_page_gallery_grid_rows'], true)) {
        return ['input_type' => 'range', 'validation' => ['min' => 1, 'max' => CMS_PAGINATION_MAX_ROWS, 'step' => 1]];
    }
    if (in_array($id, ['theme_gallery_description_layout', 'tag_page_gallery_description_layout'], true)) {
        return ['input_type' => 'select', 'validation' => ['allowed' => gallery_description_layout_options()]];
    }
    if ($id === 'theme_lightbox_browsing_mode') {
        return ['input_type' => 'select', 'validation' => ['allowed' => gallery_lightbox_browsing_mode_options()]];
    }
    return ['input_type' => 'text', 'validation' => []];
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
    if (in_array($id, ['theme_gallery_description_layout', 'tag_page_gallery_description_layout'], true)) {
        return [
            'vertical' => gallery_description_layout_label('vertical'),
            'horizontal' => gallery_description_layout_label('horizontal'),
        ];
    }
    if ($id === 'theme_lightbox_browsing_mode') {
        $modes = gallery_lightbox_browsing_mode_options();
        return array_combine(
            $modes,
            array_map(static fn (string $mode): string => gallery_lightbox_browsing_mode_label($mode), $modes)
        ) ?: [];
    }
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
        'admin_upload_client_format_mode' => [
            'server_supported' => t('admin.upload.client_format_server_supported', 'Allow all server-supported formats'),
            'phone_jpeg' => t('admin.upload.client_format_phone_jpeg', 'Prefer phone-rendered JPG/PNG/WebP, no RAW/DNG'),
        ],
        'theme_hero_tag_sort_mode' => [
            'usage' => t('admin.theme.appearance.hero_tag_sort_usage', 'Most used first'),
            'alphabetical' => t('admin.theme.appearance.hero_tag_sort_alphabetical', 'Alphabetical'),
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
    if (($entry['wizard_adapter'] ?? '') === 'theme_layout_safe') {
        return theme_layout_safe_normalize($id, $value);
    }
    if (($entry['wizard_adapter'] ?? '') === 'admin_upload_safe') {
        return match ($id) {
            'admin_upload_client_format_mode' => admin_upload_client_format_mode_validate($value),
            'admin_upload_auto_rename_enabled' => admin_upload_auto_rename_setting_normalize($value),
            default => throw new InvalidArgumentException('Unsupported Admin upload setting.'),
        };
    }
    if (($entry['wizard_adapter'] ?? '') === 'browser_upload_safe') {
        return browser_upload_safe_scalar_normalize($id, $value);
    }
    if (($entry['wizard_adapter'] ?? '') === 'telemetry_safe') {
        return telemetry_admin_setting_normalize($id, $value);
    }
    if (($entry['wizard_adapter'] ?? '') === 'operational_safe') {
        return admin_setup_wizard_operational_setting_normalize($id, $value);
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
    $layout = theme_layout_safe_settings();
    $preview = [];
    foreach ($theme as $id => $value) {
        $preview[substr($id, 6)] = $value;
    }
    foreach ($layout as $id => $value) {
        $preview[str_starts_with($id, 'theme_') ? substr($id, 6) : $id] = $value;
    }
    $preview['public_thumbnail_rendering_mode'] = public_thumbnail_rendering_mode();

    foreach ((array) ($draft['changes'] ?? []) as $id => $value) {
        $id = (string) $id;
        if (array_key_exists($id, $theme) || array_key_exists($id, $layout)) {
            $preview[str_starts_with($id, 'theme_') ? substr($id, 6) : $id] = (string) $value;
        } elseif ($id === 'public_thumbnail_rendering_mode') {
            $preview['public_thumbnail_rendering_mode'] = (string) $value;
        }
    }
    $preview['site_name'] = array_key_exists('site_name', $draft['changes'] ?? [])
        ? (string) $draft['changes']['site_name']
        : site_name();
    return $preview;
}
