<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_language_settings.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders the reusable public viewer-language selector settings panel.
 *
 * Responsibilities:
 *   - Keep Theme and centralized Settings language controls structurally identical
 *   - Render accessible feature and per-language checkboxes
 *   - Reuse canonical language presentation metadata and locally bundled flags
 *   - Preserve submitted values and validation errors
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\asset_url;
use function Gallery\Core\e;
use function Gallery\Core\url_for;
use function Gallery\Services\t;
use function Gallery\Services\translation_language_presentation;
use function Gallery\Services\translation_public_language_selector_enabled;
use function Gallery\Services\translation_public_language_selector_languages;
use function Gallery\Services\translation_public_language_selector_design;
use function Gallery\Services\translation_public_language_selector_design_defaults;
use function Gallery\Services\translation_public_language_selector_design_normalize;
use function Gallery\Services\translation_public_language_selector_design_numeric_bounds;
use function Gallery\Services\translation_supported_languages;

/**
 * Render a resettable select field in the selector design editor.
 *
 * @param array<string,string> $options Allowed values and labels.
 */
function view_render_language_design_select(string $id, string $name, string $label, string $value, string $default, array $options): void
{
    echo '<div class="admin-language-design-field"><label for="' . e($id) . '">' . e($label) . '</label><div class="admin-language-design-control">';
    echo '<select id="' . e($id) . '" name="' . e($name) . '" data-language-design-field data-default-value="' . e($default) . '">';
    foreach ($options as $option => $optionLabel) {
        echo '<option value="' . e($option) . '"' . ($value === $option ? ' selected' : '') . '>' . e($optionLabel) . '</option>';
    }
    echo '</select><button type="button" class="secondary" data-language-design-reset-field aria-label="' . e(t('admin.theme.language.design_reset_value', 'Reset {field}', ['field' => $label])) . '">' . e(t('admin.theme.language.design_reset_short', 'Reset')) . '</button></div></div>';
}

/**
 * Render a resettable checkbox field in the selector design editor.
 */
function view_render_language_design_checkbox(string $id, string $name, string $label, bool $value, bool $default): void
{
    echo '<div class="admin-language-design-field"><div class="admin-language-design-control"><input type="hidden" name="' . e($name) . '" value="0">';
    echo '<label class="checkbox-label" for="' . e($id) . '"><input id="' . e($id) . '" type="checkbox" name="' . e($name) . '" value="1" data-language-design-field data-default-value="' . ($default ? '1' : '0') . '"' . ($value ? ' checked' : '') . '> ' . e($label) . '</label>';
    echo '<button type="button" class="secondary" data-language-design-reset-field aria-label="' . e(t('admin.theme.language.design_reset_value', 'Reset {field}', ['field' => $label])) . '">' . e(t('admin.theme.language.design_reset_short', 'Reset')) . '</button></div></div>';
}

/**
 * Render one resettable color or range field for a preset.
 */
function view_render_language_design_value(string $id, string $name, string $label, string $type, mixed $value, mixed $default, ?array $bounds = null): void
{
    echo '<div class="admin-language-design-field"><label for="' . e($id) . '">' . e($label) . '</label><div class="admin-language-design-control">';
    $range = $bounds !== null ? ' min="' . (int) $bounds[0] . '" max="' . (int) $bounds[1] . '" step="1"' : '';
    $inputValue = $type === 'color' && (string) $value === 'transparent' ? (string) $default : (string) $value;
    echo '<input id="' . e($id) . '" type="' . e($type) . '" name="' . e($name) . '" value="' . e($inputValue) . '"' . $range . ' data-language-design-field data-default-value="' . e((string) $default) . '">';
    if ($type === 'color') {
        $transparentId = $id . '-transparent';
        $transparentName = str_ends_with($name, ']') ? substr($name, 0, -1) . '_transparent]' : $name . '_transparent';
        echo '<input type="hidden" name="' . e($transparentName) . '" value="0"><label class="admin-language-design-transparent" for="' . e($transparentId) . '"><input id="' . e($transparentId) . '" type="checkbox" name="' . e($transparentName) . '" value="1" data-language-design-field data-language-design-transparent data-default-value="' . ((string) $default === 'transparent' ? '1' : '0') . '"' . ((string) $value === 'transparent' ? ' checked' : '') . '> ' . e(t('admin.theme.language.design_transparent', 'Transparent')) . '</label>';
    }
    if ($type === 'range') {
        echo '<output for="' . e($id) . '" data-language-design-output>' . e((string) $value) . ' px</output>';
    }
    echo '<button type="button" class="secondary" data-language-design-reset-field aria-label="' . e(t('admin.theme.language.design_reset_value', 'Reset {field}', ['field' => $label])) . '">' . e(t('admin.theme.language.design_reset_short', 'Reset')) . '</button></div></div>';
}

/**
 * Render the shared viewer-language selector settings panel.
 *
 * @param array<string,mixed> $model Field names, values, errors, and id prefix.
 */
function view_render_public_language_selector_settings_panel(array $model = []): void
{
    $idPrefix = preg_replace('/[^a-z0-9_-]/i', '-', (string) ($model['id_prefix'] ?? 'public-language-selector')) ?: 'public-language-selector';
    $enabledName = (string) ($model['enabled_name'] ?? 'public_language_selector_enabled');
    $languagesName = (string) ($model['languages_name'] ?? 'public_language_selector_languages[]');
    $designName = (string) ($model['design_name'] ?? 'public_language_selector_design');
    $markerName = trim((string) ($model['marker_name'] ?? ''));
    $languagesTargetId = preg_replace('/[^a-z0-9_-]/i', '-', (string) ($model['languages_target_id'] ?? '')) ?: '';
    $enabled = array_key_exists('enabled', $model)
        ? !empty($model['enabled'])
        : translation_public_language_selector_enabled();
    $languages = array_key_exists('languages', $model) && is_array($model['languages'])
        ? array_values($model['languages'])
        : translation_public_language_selector_languages();
    $errors = is_array($model['errors'] ?? null) ? $model['errors'] : [];
    $enabledError = trim((string) ($errors['enabled'] ?? ''));
    $languagesError = trim((string) ($errors['languages'] ?? ''));
    $presentations = translation_language_presentation();
    $detailedDesign = !array_key_exists('detailed_design', $model) || !empty($model['detailed_design']);
    $designDefaults = translation_public_language_selector_design_defaults();
    $design = translation_public_language_selector_design_normalize($model['design'] ?? translation_public_language_selector_design());

    echo '<section class="admin-language-selector-settings" id="' . e($idPrefix) . '" data-public-language-selector-settings' . (!empty($model['admin_setting_target']) ? ' data-admin-setting-target tabindex="-1"' : '') . '>';
    echo '<div class="admin-language-selector-settings-copy"><strong>' . e(t('admin.theme.language.viewer_selector_title', 'Viewer language selector')) . '</strong>';
    echo '<p class="muted">' . e(t('admin.theme.language.viewer_selector_hint', 'This selector is only for public viewers. Each viewer\'s personal language is saved only in that viewer\'s browser; it does not change the site default, the Admin language, or any other viewer.')) . '</p></div>';
    if ($markerName !== '') {
        echo '<input type="hidden" name="' . e($markerName) . '" value="1">';
    }

    $enabledId = $idPrefix . '-enabled';
    echo '<label class="checkbox-label admin-language-selector-enabled" for="' . e($enabledId) . '"><input id="' . e($enabledId) . '" type="checkbox" name="' . e($enabledName) . '" value="1"' . ($enabled ? ' checked' : '') . ($enabledError !== '' ? ' aria-invalid="true"' : '') . '> ' . e(t('admin.theme.language.viewer_selector_enabled', 'Allow each public viewer to choose a browser-only interface language')) . '</label>';
    if ($enabledError !== '') {
        echo '<span class="error">' . e($enabledError) . '</span>';
    }

    echo '<fieldset class="admin-language-selector-language-list"' . ($languagesTargetId !== '' ? ' id="' . e($languagesTargetId) . '" data-admin-setting-target tabindex="-1"' : '') . ($languagesError !== '' ? ' aria-invalid="true"' : '') . '><legend>' . e(t('admin.theme.language.viewer_languages_legend', 'Languages available to viewers')) . '</legend>';
    echo '<p class="muted">' . e(t('admin.theme.language.viewer_languages_hint', 'Select at least one language to offer. This list affects only the public viewer selector. A viewer\'s selection is stored in that viewer\'s browser, never as an account or site-wide language setting.')) . '</p>';
    echo '<div class="admin-language-selector-language-grid">';
    foreach (translation_supported_languages() as $language) {
        $presentation = $presentations[$language] ?? ['name' => strtoupper($language), 'flag_asset' => ''];
        $languageName = trim((string) ($presentation['name'] ?? strtoupper($language)));
        $flagAsset = trim((string) ($presentation['flag_asset'] ?? ''));
        $languageId = $idPrefix . '-language-' . $language;
        echo '<label class="admin-language-selector-language" for="' . e($languageId) . '"><input id="' . e($languageId) . '" type="checkbox" name="' . e($languagesName) . '" value="' . e($language) . '"' . (in_array($language, $languages, true) ? ' checked' : '') . '>';
        if ($flagAsset !== '') {
            echo '<img src="' . e(asset_url($flagAsset)) . '" alt="" aria-hidden="true" width="24" height="18" decoding="async">';
        }
        echo '<span><strong>' . e($languageName) . '</strong><small>' . e(strtoupper($language)) . '</small></span></label>';
    }
    echo '</div>';
    if ($languagesError !== '') {
        echo '<span class="error">' . e($languagesError) . '</span>';
    }
    echo '</fieldset>';

    echo '<fieldset class="admin-language-selector-design" data-language-design-editor data-defaults="' . e((string) json_encode($designDefaults, JSON_UNESCAPED_SLASHES)) . '"><legend>' . e(t('admin.theme.language.design_legend', 'Viewer language selector design')) . '</legend>';
    echo '<p class="muted">' . e(t('admin.theme.language.design_hint', 'Customize only the public viewer selector. The Admin language, site default, and each viewer\'s browser-local choice remain unchanged.')) . '</p>';
    if (!$detailedDesign) {
        echo '<input type="hidden" name="' . e($designName . '[basic_only]') . '" value="1">';
    }
    view_render_language_design_select($idPrefix . '-design-preset', $designName . '[preset]', t('admin.theme.language.design_preset', 'Design preset'), (string) $design['preset'], 'classic', [
        'classic' => t('admin.theme.language.preset_classic', 'Classic'), 'solid_pills' => t('admin.theme.language.preset_solid_pills', 'Solid pills'),
        'outline' => t('admin.theme.language.preset_outline', 'Outline'), 'soft_cards' => t('admin.theme.language.preset_soft_cards', 'Soft cards'),
        'minimal' => t('admin.theme.language.preset_minimal', 'Minimal'),
    ]);
    echo '<div class="admin-language-design-global">';
    view_render_language_design_checkbox($idPrefix . '-show-flags', $designName . '[show_flags]', t('admin.theme.language.design_show_flags', 'Show flags'), (bool) $design['show_flags'], true);
    if (!$detailedDesign) {
        echo '</div><p class="muted admin-language-design-details-link">' . e(t('admin.theme.language.design_detailed_elsewhere', 'For colors, spacing, borders, sizing, preview, and reset controls, open Theme > Language.')) . ' <a href="' . e(url_for('admin_theme') . '#admin-theme-tab-language') . '">' . e(t('admin.theme.language.design_open_detailed', 'Open detailed language design settings')) . '</a></p></fieldset></section>';
        return;
    }
    view_render_language_design_checkbox($idPrefix . '-show-codes', $designName . '[show_codes]', t('admin.theme.language.design_show_codes', 'Show language codes'), (bool) $design['show_codes'], true);
    view_render_language_design_checkbox($idPrefix . '-show-names', $designName . '[show_names]', t('admin.theme.language.design_show_names', 'Show native language names'), (bool) $design['show_names'], false);
    view_render_language_design_select($idPrefix . '-orientation', $designName . '[orientation]', t('admin.theme.language.design_orientation', 'Orientation'), (string) $design['orientation'], 'wrap', ['horizontal' => t('admin.theme.language.design_horizontal', 'Horizontal'), 'wrap' => t('admin.theme.language.design_wrap', 'Wrapped'), 'vertical' => t('admin.theme.language.design_vertical', 'Vertical')]);
    view_render_language_design_select($idPrefix . '-density', $designName . '[density]', t('admin.theme.language.design_density', 'Density'), (string) $design['density'], 'comfortable', ['comfortable' => t('admin.theme.language.design_comfortable', 'Comfortable'), 'compact' => t('admin.theme.language.design_compact', 'Compact')]);
    view_render_language_design_select($idPrefix . '-alignment', $designName . '[alignment]', t('admin.theme.language.design_alignment', 'Alignment'), (string) $design['alignment'], 'start', ['start' => t('admin.theme.language.design_start', 'Start'), 'center' => t('admin.theme.language.design_center', 'Center'), 'end' => t('admin.theme.language.design_end', 'End')]);
    view_render_language_design_select($idPrefix . '-active-style', $designName . '[active_style]', t('admin.theme.language.design_active_style', 'Active emphasis'), (string) $design['active_style'], 'filled', ['filled' => t('admin.theme.language.design_filled', 'Filled'), 'outline' => t('admin.theme.language.design_outline', 'Outline'), 'underline' => t('admin.theme.language.design_underline', 'Underline')]);
    echo '</div>';
    echo '<div class="admin-language-design-preview-shell"><strong>' . e(t('admin.theme.language.design_preview', 'Live preview')) . '</strong><div class="admin-language-design-preview" data-language-design-preview></div></div>';

    $colorLabels = ['container_bg' => 'Background', 'text_color' => 'Text', 'border_color' => 'Border', 'active_bg' => 'Active background', 'active_text' => 'Active text', 'hover_bg' => 'Hover background', 'focus_color' => 'Focus outline'];
    $numericLabels = ['selector_padding_x' => 'Selector horizontal padding', 'selector_padding_y' => 'Selector vertical padding', 'selector_margin' => 'Selector margin', 'gap' => 'Item gap', 'button_padding_x' => 'Button horizontal padding', 'button_padding_y' => 'Button vertical padding', 'border_width' => 'Border width', 'selector_radius' => 'Selector radius', 'button_radius' => 'Button radius', 'flag_width' => 'Flag width', 'flag_height' => 'Flag height', 'font_size' => 'Text size'];
    $bounds = translation_public_language_selector_design_numeric_bounds();
    foreach ($design['presets'] as $presetId => $presetValues) {
        $presetDefaults = is_array($designDefaults['presets'][$presetId] ?? null) ? $designDefaults['presets'][$presetId] : [];
        $presetDefaults['use_theme_colors'] = (bool) ($presetDefaults['use_theme_colors'] ?? false);
        $presetValues = array_replace($presetDefaults, is_array($presetValues) ? $presetValues : []);
        echo '<section class="admin-language-design-preset" data-language-design-preset="' . e($presetId) . '"' . ($presetId !== $design['preset'] ? ' hidden' : '') . '><h4>' . e((string) ($presetValues['label'] ?? ucfirst(str_replace('_', ' ', $presetId)))) . '</h4>';
        view_render_language_design_checkbox($idPrefix . '-' . $presetId . '-theme-colors', $designName . '[presets][' . $presetId . '][use_theme_colors]', t('admin.theme.language.design_theme_colors', 'Use theme colors'), (bool) ($presetValues['use_theme_colors'] ?? false), (bool) $presetDefaults['use_theme_colors']);
        echo '<div class="admin-language-design-grid">';
        foreach ($colorLabels as $field => $fallbackLabel) {
            $defaultValue = (string) ($presetDefaults[$field] ?? '#000000');
            view_render_language_design_value($idPrefix . '-' . $presetId . '-' . $field, $designName . '[presets][' . $presetId . '][' . $field . ']', t('admin.theme.language.design_' . $field, $fallbackLabel), 'color', $presetValues[$field] ?? $defaultValue, $defaultValue);
        }
        foreach ($numericLabels as $field => $fallbackLabel) {
            $defaultValue = (int) ($presetDefaults[$field] ?? ($bounds[$field][0] ?? 0));
            view_render_language_design_value($idPrefix . '-' . $presetId . '-' . $field, $designName . '[presets][' . $presetId . '][' . $field . ']', t('admin.theme.language.design_' . $field, $fallbackLabel), 'range', $presetValues[$field] ?? $defaultValue, $defaultValue, $bounds[$field]);
        }
        $defaultBorderStyle = (string) ($presetDefaults['border_style'] ?? 'solid');
        view_render_language_design_select($idPrefix . '-' . $presetId . '-border-style', $designName . '[presets][' . $presetId . '][border_style]', t('admin.theme.language.design_border_style', 'Border style'), (string) ($presetValues['border_style'] ?? $defaultBorderStyle), $defaultBorderStyle, ['solid' => t('admin.theme.language.design_solid', 'Solid'), 'dashed' => t('admin.theme.language.design_dashed', 'Dashed'), 'dotted' => t('admin.theme.language.design_dotted', 'Dotted'), 'double' => t('admin.theme.language.design_double', 'Double')]);
        echo '</div><button type="button" class="secondary" data-language-design-reset-preset>' . e(t('admin.theme.language.design_reset_preset', 'Reset this preset')) . '</button></section>';
    }
    echo '<button type="button" class="secondary danger" data-language-design-reset-all>' . e(t('admin.theme.language.design_reset_all', 'Reset all language selector settings')) . '</button>';
    echo '</fieldset></section>';
}
