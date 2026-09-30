<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/views/admin_setup_wizard.php
 * Module Type: View
 * Purpose: Render the staged Admin setup wizard from prepared view data.
 * Responsibilities:
 *   - Render server-owned wizard navigation, fields, previews, and review state.
 *   - Preserve accessibility, escaping, and no-JavaScript form behavior.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT
 * Notes: The controller supplies presentation data; this view never persists settings.
 * Last Updated: 2026-09-29
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\csrf_field;
use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Services\t;

/** Render the staged Admin setup wizard from controller-prepared presentation data.
 * @param array<string,mixed> $model Prepared steps, draft, URLs, errors, and preview data.
 * @return void
 */
function view_render_admin_setup_wizard_page(array $model): void
{
    $steps = is_array($model['steps'] ?? null) ? $model['steps'] : [];
    $draft = is_array($model['draft'] ?? null) ? $model['draft'] : [];
    $active = (string) ($model['active_step'] ?? ($draft['step'] ?? 'general'));
    $actionUrl = (string) (($model['urls']['action'] ?? null) ?: ($model['urls']['restart'] ?? ''));
    $revision = (string) ($draft['revision'] ?? $model['revision'] ?? '');
    render_header(t('admin.setup_wizard.title', 'Setup wizard'));
    echo '<main class="admin-setup-wizard" data-admin-setup-wizard data-step="' . e($active) . '">';
    echo '<header class="admin-setup-wizard-header"><p class="kicker">' . e(t('admin.setup_wizard.kicker', 'Administration')) . '</p><h1>' . e(t('admin.setup_wizard.title', 'Setup wizard')) . '</h1><p>' . e(t('admin.setup_wizard.intro', 'Review your settings step by step. Nothing is saved until you approve the summary.')) . '</p></header>';
    view_render_admin_setup_wizard_errors((array) ($model['errors'] ?? []));
    view_render_admin_setup_wizard_progress($steps, $active, $actionUrl, $revision);
    if ($active === 'summary') {
        view_render_admin_setup_wizard_summary($model, $actionUrl, $revision);
    } else {
        view_render_admin_setup_wizard_step($model, $active, $actionUrl, $revision);
    }
    echo '</main>';
    render_footer();
}

/** Render the page-level validation summary.
 * @param array<string,mixed> $errors Field and page validation message identifiers.
 * @return void
 */
function view_render_admin_setup_wizard_errors(array $errors): void
{
    $pageErrors = $errors['_page'] ?? [];
    $pageErrors = is_array($pageErrors) ? $pageErrors : [$pageErrors];
    foreach ($errors as $field => $error) {
        if ($field === '_page') continue;
        foreach (is_array($error) ? $error : [$error] as $fieldError) $pageErrors[] = $fieldError;
    }
    if ($pageErrors === []) return;
    echo '<section class="notice error admin-setup-wizard-errors" role="alert" aria-labelledby="wizard-errors-title" tabindex="-1" data-wizard-error-summary><h2 id="wizard-errors-title">' . e(t('admin.setup_wizard.errors_title', 'Please review the highlighted settings')) . '</h2><ul>';
    foreach ($pageErrors as $key) echo '<li>' . e(t((string) $key, t('admin.setup_wizard.error.invalid_value', 'One or more values could not be accepted.'))) . '</li>';
    echo '</ul></section>';
}

/** Render server-backed step navigation that remains usable without JavaScript.
 * @param array<string,array<string,mixed>> $steps Prepared wizard steps keyed by identifier.
 * @param string $active Active step identifier.
 * @param string $actionUrl Form action URL.
 * @param string $revision Draft revision token.
 * @return void
 */
function view_render_admin_setup_wizard_progress(array $steps, string $active, string $actionUrl, string $revision): void
{
    $keys = array_keys($steps);
    $activeIndex = $active === 'summary' ? count($keys) : array_search($active, $keys, true);
    $activeIndex = is_int($activeIndex) ? $activeIndex : 0;
    echo '<nav class="admin-setup-wizard-progress-nav" aria-label="' . e(t('admin.setup_wizard.progress', 'Wizard progress')) . '"><ol class="admin-setup-wizard-progress">';
    foreach ($steps as $id => $step) {
        $index = (int) array_search($id, $keys, true);
        $definition = (array) ($step['definition'] ?? []);
        $state = $id === $active ? ' class="is-active" aria-current="step"' : ($index < $activeIndex ? ' class="is-complete"' : '');
        $separator = str_contains($actionUrl, '?') ? '&' : '?';
        $href = $actionUrl . $separator . 'step=' . rawurlencode((string) $id);
        $label = t((string) ($definition['label_key'] ?? ''), (string) ($definition['label'] ?? $id));
        echo '<li' . $state . '><a href="' . e($href) . '" data-wizard-progress-target="' . e((string) $id) . '" aria-label="' . e(($index + 1) . '. ' . $label) . '"><span class="wizard-progress-marker" aria-hidden="true">' . ($index + 1) . '</span><span class="wizard-progress-label">' . e($label) . '</span></a></li>';
    }
    $summaryLabel = t('admin.setup_wizard.summary_short', 'Review');
    echo '<li' . ($active === 'summary' ? ' class="is-active" aria-current="step"' : '') . '><span class="wizard-progress-summary" aria-label="' . e((count($keys) + 1) . '. ' . $summaryLabel) . '"><span class="wizard-progress-marker" aria-hidden="true">' . (count($keys) + 1) . '</span><span class="wizard-progress-label">' . e($summaryLabel) . '</span></span></li></ol></nav>';
}

/** Render one wizard step with in-step subsections and progressive disclosure.
 * @param array<string,mixed> $model Complete wizard view model.
 * @param string $active Active step identifier.
 * @param string $actionUrl Form action URL.
 * @param string $revision Draft revision token.
 * @return void
 */
function view_render_admin_setup_wizard_step(array $model, string $active, string $actionUrl, string $revision): void
{
    $step = (array) (($model['steps'] ?? [])[$active] ?? []);
    $definition = (array) ($step['definition'] ?? []);
    $entries = (array) ($step['entries'] ?? []);
    $draft = (array) ($model['draft'] ?? []);
    $errors = (array) ($model['errors'] ?? []);
    $preview = (array) ($model['theme_preview'] ?? []);
    $themePreviewStep = in_array($active, ['appearance', 'content', 'media'], true);
    $subsections = view_admin_setup_wizard_subsections($entries);

    echo '<form method="post" action="' . e($actionUrl) . '" class="admin-setup-wizard-form" data-wizard-form novalidate' . ($themePreviewStep ? ' data-theme-form' : '') . '>' . csrf_field() . '<input type="hidden" name="wizard_step" value="' . e($active) . '"><input type="hidden" name="revision" value="' . e($revision) . '">';
    view_render_admin_setup_wizard_actions(false, 'top');
    echo '<section class="admin-setup-wizard-step"><h2>' . e(t((string) ($definition['label_key'] ?? ''), (string) ($definition['label'] ?? 'Configure settings'))) . '</h2><p class="wizard-help">' . e(t((string) ($definition['description_key'] ?? ''), (string) ($definition['description'] ?? 'Choose the values you want to stage.'))) . '</p>';
    echo '<p class="wizard-step-skip-help">' . e(t('admin.setup_wizard.item_skip_help', 'If this does not apply to you, you are unsure what it means, or you do not want to decide now, skip this item. The original setting will be preserved.')) . '</p>';
    if (count($subsections) > 1) {
        echo '<nav class="wizard-subsection-nav" aria-label="' . e(t('admin.setup_wizard.subsection_navigation', 'Settings in this step')) . '">';
        $index = 0;
        foreach ($subsections as $subsection) {
            $id = (string) ($subsection['id'] ?? 'settings');
            $label = t((string) ($subsection['label_key'] ?? ''), (string) ($subsection['label'] ?? 'Settings'));
            echo '<button type="button" class="wizard-subsection-button' . ($index === 0 ? ' is-active' : '') . '" data-wizard-subsection-target="' . e($id) . '" aria-controls="wizard-subsection-' . e($id) . '" aria-selected="' . ($index === 0 ? 'true' : 'false') . '">' . e($label) . '</button>';
            $index++;
        }
        echo '</nav>';
    }
    if ($themePreviewStep) echo '<div class="admin-setup-wizard-appearance" data-theme-preview-root data-theme-preview-background-url="' . e((string) ($preview['background_url'] ?? '')) . '"><div class="admin-setup-wizard-fields">';
    $index = 0;
    foreach ($subsections as $subsection) {
        $id = (string) ($subsection['id'] ?? 'settings');
        $label = t((string) ($subsection['label_key'] ?? ''), (string) ($subsection['label'] ?? 'Settings'));
        echo '<section id="wizard-subsection-' . e($id) . '" class="wizard-subsection-panel' . ($index === 0 ? ' is-active' : '') . '" data-wizard-subsection-panel="' . e($id) . '"><h3>' . e($label) . '</h3>';
        view_render_admin_setup_wizard_subsection_entries($model, (array) ($subsection['entries'] ?? []), $draft, $errors);
        echo '</section>';
        $index++;
    }
    if ($themePreviewStep) {
        echo '</div><div class="admin-setup-wizard-preview">';
        view_render_admin_setup_wizard_theme_fallback_controls($entries, $preview);
        view_render_admin_theme_live_preview($preview);
        echo '</div></div>';
    }
    echo '</section>';
    view_render_admin_setup_wizard_actions(false, 'bottom');
    echo '</form>';
}

/** Group prepared entries into compact presentation subsections while preserving registry order.
 * @param array<string,array<string,mixed>> $entries Prepared wizard entries.
 * @return list<array{id:string,label:string,label_key:string,entries:array<string,array<string,mixed>>}> Ordered subsections.
 */
function view_admin_setup_wizard_subsections(array $entries): array
{
    $groups = [];
    foreach ($entries as $id => $field) {
        if (!is_array($field)) continue;
        $subsection = (string) ($field['wizard_subsection'] ?? 'settings');
        if ($subsection === '') $subsection = 'settings';
        if (!isset($groups[$subsection])) {
            $groups[$subsection] = [
                'id' => $subsection,
                'label' => (string) ($field['wizard_subsection_label'] ?? 'Settings'),
                'label_key' => (string) ($field['wizard_subsection_key'] ?? ''),
                'entries' => [],
            ];
        }
        $groups[$subsection]['entries'][(string) $id] = $field;
    }
    return array_values($groups);
}

/** Render one subsection, keeping common choices visible and advanced/specialist material collapsed.
 * @param array<string,mixed> $model Complete wizard model for shared controls.
 * @param array<string,array<string,mixed>> $entries Entries in this subsection.
 * @param array<string,mixed> $draft Current staged draft.
 * @param array<string,mixed> $errors Validation errors keyed by setting.
 * @return void
 */
function view_render_admin_setup_wizard_subsection_entries(array $model, array $entries, array $draft, array $errors): void
{
    $tiers = ['essential' => [], 'advanced' => [], 'expert' => [], 'status' => []];
    $languageEntries = [];
    foreach ($entries as $id => $field) {
        if (!is_array($field)) continue;
        if (str_starts_with((string) $id, 'public_language_selector_')) {
            $languageEntries[(string) $id] = $field;
            continue;
        }
        $tier = (string) ($field['wizard_tier'] ?? (!empty($field['wizard_editable']) ? 'essential' : 'status'));
        if (!isset($tiers[$tier])) $tier = !empty($field['wizard_editable']) ? 'advanced' : 'status';
        $tiers[$tier][(string) $id] = $field;
    }
    if ($languageEntries !== []) {
        view_render_admin_setup_wizard_language_selector($model, $languageEntries, $draft, $errors);
    }
    foreach ($tiers['essential'] as $id => $field) view_render_admin_setup_wizard_field($id, $field, $draft, $errors);
    if ($tiers['advanced'] !== []) {
        echo '<details class="wizard-disclosure"><summary>' . e(t('admin.setup_wizard.advanced_settings', 'Advanced settings')) . ' <span class="wizard-disclosure-count">(' . count($tiers['advanced']) . ')</span></summary><div class="wizard-disclosure-body">';
        foreach ($tiers['advanced'] as $id => $field) view_render_admin_setup_wizard_field($id, $field, $draft, $errors);
        echo '</div></details>';
    }
    $expert = $tiers['expert'] + $tiers['status'];
    if ($expert !== []) {
        echo '<details class="wizard-disclosure wizard-expert-disclosure"><summary>' . e(t('admin.setup_wizard.expert_settings', 'Expert and specialist settings')) . ' <span class="wizard-disclosure-count">(' . count($expert) . ')</span></summary><div class="wizard-disclosure-body"><p class="wizard-disclosure-intro">' . e(t('admin.setup_wizard.expert_intro', 'These settings are uncommon, operational, sensitive, or action-oriented. Review them only when you need their specific behavior.')) . '</p>';
        foreach ($expert as $id => $field) view_render_admin_setup_wizard_field($id, $field, $draft, $errors);
        echo '</div></details>';
    }
}

/** Render one registry entry without exposing its internal identifier.
 * @param string $id Canonical setting identifier.
 * @param array<string,mixed> $field Prepared registry entry.
 * @param array<string,mixed> $draft Current staged draft.
 * @param array<string,mixed> $errors Validation errors keyed by setting.
 * @return void
 */
function view_render_admin_setup_wizard_field(string $id, array $field, array $draft, array $errors): void
{
    $editable = !empty($field['wizard_editable']) && empty($field['unavailable']);
    $included = array_key_exists($id, (array) ($draft['changes'] ?? [])) || (!array_key_exists($id, (array) ($draft['skips'] ?? [])) && !empty($field['included']));
    $value = array_key_exists($id, (array) ($draft['changes'] ?? [])) ? $draft['changes'][$id] : ($field['value'] ?? $field['current'] ?? '');
    $current = $field['current'] ?? '';
    $label = t((string) ($field['label_key'] ?? ''), (string) ($field['label'] ?? 'Setting'));
    $error = isset($errors[$id]) ? t((string) $errors[$id], t('admin.setup_wizard.error.invalid_value', 'This value could not be accepted.')) : '';
    $controlId = 'wizard-' . (preg_replace('/[^a-z0-9_-]/i', '-', $id) ?: 'setting');
    echo '<article class="wizard-field' . (!$editable ? ' is-readonly is-status' : '') . (!$included && $editable ? ' is-excluded' : '') . ($error !== '' ? ' has-error' : '') . '" data-wizard-field>';
    if ($editable) {
        echo '<label class="wizard-include" for="' . e($controlId . '-include') . '"><input id="' . e($controlId . '-include') . '" type="checkbox" name="include[' . e($id) . ']" value="1" data-wizard-include' . ($included ? ' checked' : '') . '> <span>' . e($label) . '</span></label><div class="wizard-field-control">';
        view_render_admin_setup_wizard_control($controlId, $id, $field, $value, $current, $included, $error);
        echo '</div>';
    } else {
        echo '<h4 class="wizard-status-title">' . e($label) . '</h4><p class="wizard-current-value"><span>' . e(t('admin.setup_wizard.current_value', 'Current value')) . ':</span> <strong>' . e(view_admin_setup_wizard_display_value($current, $field)) . '</strong></p>';
    }
    echo '<p class="wizard-field-description">' . e(t((string) ($field['description_key'] ?? ''), (string) ($field['description'] ?? ''))) . '</p>';
    $exampleKey = (string) ($field['example_key'] ?? $field['example'] ?? '');
    if ($editable && $exampleKey !== '') echo '<p class="wizard-field-example"><strong>' . e(t('admin.setup_wizard.example', 'Example')) . ':</strong> ' . e(t($exampleKey, (string) ($field['example_fallback'] ?? 'Choose the value that best matches how the gallery will be used.'))) . '</p>';
    if (!$editable) {
        echo '<p class="wizard-deferred-notice">' . e(t('admin.setup_wizard.status_only_notice', 'Shown here for completeness. This specialist item is not changed by the staged wizard and no separate settings page is opened from this draft.')) . '</p>';
    }
    if ($error !== '') echo '<p class="error" id="' . e($controlId . '-error') . '">' . e($error) . '</p>';
    echo '</article>';
}

/** Render one editable value control with Theme preview hooks where applicable.
 * @param string $controlId HTML control identifier.
 * @param string $id Canonical setting identifier.
 * @param array<string,mixed> $field Prepared field metadata.
 * @param string|int|float|bool|list<string|int|float|bool>|array<string,mixed>|null $value Staged value.
 * @param string|int|float|bool|list<string|int|float|bool>|array<string,mixed>|null $current Persisted value.
 * @param bool $included Whether the value participates in submission.
 * @param string $error Localized validation message, if any.
 * @return void
 */
function view_render_admin_setup_wizard_control(string $controlId, string $id, array $field, mixed $value, mixed $current, bool $included, string $error): void
{
    $type = (string) ($field['input_type'] ?? 'text');
    $currentString = is_array($current) ? (string) json_encode($current, JSON_UNESCAPED_SLASHES) : (string) $current;
    $attr = ' data-wizard-value data-current-value="' . e($currentString) . '"' . (!$included ? ' disabled' : '') . ($error !== '' ? ' aria-invalid="true" aria-describedby="' . e($controlId . '-error') . '"' : '');
    $hooks = [
        'site_name' => ' data-theme-preview-site-name',
        'theme_accent' => ' data-theme-preview-color="accent"',
        'theme_accent_dark' => ' data-theme-preview-color="accent_dark"',
        'theme_paper' => ' data-theme-preview-color="paper"',
        'theme_panel' => ' data-theme-preview-color="panel"',
        'theme_gallery_panel' => ' data-theme-preview-color="gallery_panel"',
        'theme_header_text' => ' data-theme-preview-color="header_text"',
        'theme_hero_text' => ' data-theme-preview-color="hero_text"',
        'theme_radius' => ' data-theme-preview-radius',
        'theme_font' => ' data-theme-preview-font',
        'theme_page_width' => ' data-theme-preview-width',
        'theme_page_width_custom' => ' data-theme-preview-custom-width data-theme-custom-width-number',
        'theme_gps_pin_enabled' => ' data-theme-gps-pin-enabled',
        'theme_gps_pin_background_enabled' => ' data-theme-gps-pin-background-enabled',
        'theme_gps_pin_size' => ' data-theme-gps-pin-size',
        'theme_gps_pin_background_size' => ' data-theme-gps-pin-background-size',
        'theme_gallery_description_layout' => ' data-theme-preview-description-layout',
        'theme_gallery_count_badge_enabled' => ' data-theme-preview-count-badge',
        'pagination_enabled' => ' data-theme-preview-pagination-enabled',
        'pagination_columns' => ' data-theme-preview-grid-columns',
        'pagination_rows' => ' data-theme-preview-grid-rows',
        'home_gallery_grid_columns' => ' data-theme-preview-home-grid-columns',
        'home_gallery_grid_rows' => ' data-theme-preview-home-grid-rows',
        'tag_page_gallery_grid_columns' => ' data-theme-preview-tag-grid-columns',
        'tag_page_gallery_grid_rows' => ' data-theme-preview-tag-grid-rows',
        'tag_page_gallery_description_layout' => ' data-theme-preview-tag-description-layout',
        'theme_lightbox_browsing_mode' => ' data-theme-preview-lightbox-mode',
        'public_thumbnail_rendering_mode' => ' data-theme-preview-thumbnail-mode',
    ];
    $attr .= $hooks[$id] ?? '';
    if ($type === 'checkbox') {
        echo '<input type="hidden" name="settings[' . e($id) . ']" value="0" data-wizard-value-fallback' . (!$included ? ' disabled' : '') . '><label class="checkbox-label"><input id="' . e($controlId) . '" type="checkbox" name="settings[' . e($id) . ']" value="1"' . (!empty($value) && (string) $value !== '0' ? ' checked' : '') . $attr . '> ' . e(t('admin.setup_wizard.enabled', 'Enabled')) . '</label>';
        return;
    }
    if ($type === 'select') {
        echo '<select id="' . e($controlId) . '" name="settings[' . e($id) . ']"' . $attr . '>';
        foreach ((array) ($field['options'] ?? $field['validation']['allowed'] ?? []) as $option => $optionLabel) {
            if (is_int($option)) { $option = (string) $optionLabel; $optionLabel = ucfirst(str_replace('_', ' ', $option)); }
            echo '<option value="' . e((string) $option) . '"' . ((string) $value === (string) $option ? ' selected' : '') . '>' . e(t((string) ($field['option_keys'][$option] ?? ''), (string) $optionLabel)) . '</option>';
        }
        echo '</select>';
        return;
    }
    if ($type === 'textarea') { echo '<textarea id="' . e($controlId) . '" name="settings[' . e($id) . ']" rows="3"' . $attr . '>' . e((string) $value) . '</textarea>'; return; }
    $htmlType = in_array($type, ['color', 'number', 'range', 'url', 'time'], true) ? $type : 'text';
    $bounds = '';
    foreach (['min', 'max', 'step'] as $bound) if (isset($field['validation'][$bound])) $bounds .= ' ' . $bound . '="' . e((string) $field['validation'][$bound]) . '"';
    echo '<input id="' . e($controlId) . '" type="' . e($htmlType) . '" name="settings[' . e($id) . ']" value="' . e((string) $value) . '"' . $bounds . $attr . '>';
    if ($id === 'theme_radius') echo '<output for="' . e($controlId) . '" data-theme-radius-display>' . e((string) $value) . 'px</output>';
    if ($id === 'theme_gps_pin_size') echo '<output for="' . e($controlId) . '" data-theme-gps-pin-size-display>' . e((string) $value) . 'px</output>';
    if ($id === 'theme_gps_pin_background_size') echo '<output for="' . e($controlId) . '" data-theme-gps-pin-background-size-display>' . e((string) $value) . 'px</output>';
    if (in_array($id, ['pagination_columns', 'home_gallery_grid_columns', 'tag_page_gallery_grid_columns'], true)) echo '<output for="' . e($controlId) . '" data-theme-grid-columns-display>' . e((string) $value) . '</output>';
    if (in_array($id, ['pagination_rows', 'home_gallery_grid_rows', 'tag_page_gallery_grid_rows'], true)) echo '<output for="' . e($controlId) . '" data-theme-grid-rows-display>' . e((string) $value) . '</output>';
}

/** Render the canonical public viewer-language selector inside wizard inclusion choices.
 * @param array<string,mixed> $model Wizard model containing selector state.
 * @param array<string,array<string,mixed>> $entries Selector registry entries.
 * @param array<string,mixed> $draft Current staged draft.
 * @param array<string,mixed> $errors Validation errors keyed by setting.
 * @return void
 */
function view_render_admin_setup_wizard_language_selector(array $model, array $entries, array $draft, array $errors): void
{
    $selector = (array) ($model['language_selector'] ?? []);
    echo '<section class="wizard-field wizard-language-selector"><h3>' . e(t('admin.setup_wizard.language_selector_group', 'Viewer language selector')) . '</h3><div class="wizard-language-includes">';
    foreach (['public_language_selector_enabled', 'public_language_selector_languages', 'public_language_selector_design'] as $id) {
        $entry = (array) ($entries[$id] ?? []);
        if ($entry === []) continue;
        $included = array_key_exists($id, (array) ($draft['changes'] ?? [])) || (!array_key_exists($id, (array) ($draft['skips'] ?? [])) && !empty($entry['included']));
        echo '<div class="wizard-language-choice"><label><input type="checkbox" name="include[' . e($id) . ']" value="1" data-wizard-include data-wizard-include-target="' . e($id) . '"' . ($included ? ' checked' : '') . '> ' . e(t((string) ($entry['label_key'] ?? ''), (string) ($entry['label'] ?? 'Setting'))) . '</label>';
        echo '<p class="wizard-field-description">' . e(t((string) ($entry['description_key'] ?? ''), (string) ($entry['description'] ?? ''))) . '</p>';
        $exampleKey = (string) ($entry['example_key'] ?? $entry['example'] ?? '');
        if ($exampleKey !== '') echo '<p class="wizard-field-example"><strong>' . e(t('admin.setup_wizard.example', 'Example')) . ':</strong> ' . e(t($exampleKey, (string) ($entry['example_fallback'] ?? 'Choose the value that matches the languages your visitors use.'))) . '</p>';
        echo '</div>';
        if (array_key_exists($id, (array) ($draft['changes'] ?? []))) {
            if ($id === 'public_language_selector_enabled') $selector['enabled'] = (string) $draft['changes'][$id] === '1';
            elseif ($id === 'public_language_selector_languages') $selector['languages'] = (array) $draft['changes'][$id];
            else $selector['design'] = (array) $draft['changes'][$id];
        }
    }
    echo '</div>';
    $selector = array_replace($selector, ['id_prefix' => 'wizard-public-language-selector', 'enabled_name' => 'settings[public_language_selector_enabled]', 'languages_name' => 'settings[public_language_selector_languages][]', 'design_name' => 'settings[public_language_selector_design]', 'detailed_design' => false]);
    $selector['errors'] = ['enabled' => isset($errors['public_language_selector_enabled']) ? t((string) $errors['public_language_selector_enabled'], '') : '', 'languages' => isset($errors['public_language_selector_languages']) ? t((string) $errors['public_language_selector_languages'], '') : ''];
    view_render_public_language_selector_settings_panel($selector);
    echo '</section>';
}

/** Emit hidden saved values only for preview properties without a visible wizard control.
 * @param array<string,array<string,mixed>> $entries Wizard entries.
 * @param array<string,mixed> $preview Prepared preview values.
 * @return void
 */
function view_render_admin_setup_wizard_theme_fallback_controls(array $entries, array $preview): void
{
    $fallbacks = [
        'theme_accent' => ['accent', 'data-theme-preview-color="accent"'],
        'theme_accent_dark' => ['accent_dark', 'data-theme-preview-color="accent_dark"'],
        'theme_paper' => ['paper', 'data-theme-preview-color="paper"'],
        'theme_panel' => ['panel', 'data-theme-preview-color="panel"'],
        'theme_gallery_panel' => ['gallery_panel', 'data-theme-preview-color="gallery_panel"'],
        'theme_header_text' => ['header_text', 'data-theme-preview-color="header_text"'],
        'theme_hero_text' => ['hero_text', 'data-theme-preview-color="hero_text"'],
        'theme_radius' => ['radius', 'data-theme-preview-radius'],
        'theme_font' => ['font', 'data-theme-preview-font'],
        'theme_page_width' => ['page_width', 'data-theme-preview-width'],
        'theme_page_width_custom' => ['page_width_custom', 'data-theme-preview-custom-width data-theme-custom-width-number'],
        'theme_gps_pin_enabled' => ['gps_pin_enabled', 'data-theme-gps-pin-enabled'],
        'theme_gps_pin_background_enabled' => ['gps_pin_background_enabled', 'data-theme-gps-pin-background-enabled'],
        'theme_gps_pin_size' => ['gps_pin_size', 'data-theme-gps-pin-size'],
        'theme_gps_pin_background_size' => ['gps_pin_background_size', 'data-theme-gps-pin-background-size'],
        'theme_gallery_description_layout' => ['gallery_description_layout', 'data-theme-preview-description-layout'],
        'theme_gallery_count_badge_enabled' => ['gallery_count_badge_enabled', 'data-theme-preview-count-badge'],
        'pagination_enabled' => ['pagination_enabled', 'data-theme-preview-pagination-enabled'],
        'pagination_columns' => ['pagination_columns', 'data-theme-preview-grid-columns'],
        'pagination_rows' => ['pagination_rows', 'data-theme-preview-grid-rows'],
        'home_gallery_grid_columns' => ['home_gallery_grid_columns', 'data-theme-preview-home-grid-columns'],
        'home_gallery_grid_rows' => ['home_gallery_grid_rows', 'data-theme-preview-home-grid-rows'],
        'tag_page_gallery_grid_columns' => ['tag_page_gallery_grid_columns', 'data-theme-preview-tag-grid-columns'],
        'tag_page_gallery_grid_rows' => ['tag_page_gallery_grid_rows', 'data-theme-preview-tag-grid-rows'],
        'tag_page_gallery_description_layout' => ['tag_page_gallery_description_layout', 'data-theme-preview-tag-description-layout'],
        'theme_lightbox_browsing_mode' => ['lightbox_browsing_mode', 'data-theme-preview-lightbox-mode'],
        'public_thumbnail_rendering_mode' => ['public_thumbnail_rendering_mode', 'data-theme-preview-thumbnail-mode'],
    ];
    foreach ($fallbacks as $id => [$previewKey, $hook]) {
        if (isset($entries[$id]) && !empty($entries[$id]['wizard_editable'])) {
            continue;
        }
        echo '<input type="hidden" value="' . e((string) ($preview[$previewKey] ?? '')) . '" ' . $hook . '>';
    }
    if (!isset($entries['site_name'])) {
        echo '<input type="hidden" value="' . e((string) ($preview['site_name'] ?? 'Gallery CMS')) . '" data-theme-preview-site-name>';
    }
}

/** Render changed values first and keep unchanged/status values collapsed by default.
 * @param array<string,mixed> $model Summary model.
 * @param string $actionUrl Form action URL.
 * @param string $revision Draft revision token.
 * @return void
 */
function view_render_admin_setup_wizard_summary(array $model, string $actionUrl, string $revision): void
{
    $steps = (array) ($model['steps'] ?? []);
    $groups = (array) ($model['summary_groups'] ?? []);
    $hiddenCount = 0;
    foreach ($groups as $group) {
        if (!is_array($group)) continue;
        $hiddenCount += count((array) ($group['reviewed'] ?? []));
        $hiddenCount += count((array) ($group['skipped'] ?? []));
        $hiddenCount += count((array) ($group['deferred'] ?? []));
    }

    echo '<section class="admin-setup-wizard-summary"><form method="post" action="' . e($actionUrl) . '" class="admin-setup-wizard-approval">' . csrf_field() . '<input type="hidden" name="wizard_step" value="summary"><input type="hidden" name="revision" value="' . e($revision) . '">';
    view_render_admin_setup_wizard_actions(true, 'top');
    echo '<h2>' . e(t('admin.setup_wizard.summary_title', 'Review your setup')) . '</h2><p>' . e(t('admin.setup_wizard.summary_help', 'Check every proposed change. Nothing is saved until you select the approval checkbox and apply the setup.')) . '</p>';
    if ($hiddenCount > 0) {
        echo '<label class="wizard-summary-show-all"><input type="checkbox" data-wizard-summary-show-all> <span>' . e(t('admin.setup_wizard.show_unchanged', 'Show unchanged and informational settings')) . ' (' . $hiddenCount . ')</span></label>';
    }
    foreach ($groups as $group) {
        if (!is_array($group)) continue;
        $entries = (array) ($steps[(string) ($group['id'] ?? '')]['entries'] ?? []);
        $changes = (array) ($group['changes'] ?? []);
        $reviewed = (array) ($group['reviewed'] ?? []);
        $skipped = (array) ($group['skipped'] ?? []);
        $deferred = (array) ($group['deferred'] ?? []);
        if ($deferred === []) {
            foreach ($entries as $id => $entry) {
                if (is_array($entry) && empty($entry['wizard_editable'])) {
                    $deferred[] = ['id' => $id, 'label' => $entry['label'] ?? 'Setting', 'label_key' => $entry['label_key'] ?? ''];
                }
            }
        }
        $unchangedCount = count($reviewed) + count($skipped) + count($deferred);
        if ($changes === [] && $unchangedCount === 0) continue;

        $groupClass = 'wizard-summary-group' . ($changes === [] ? ' is-unchanged-only' : '');
        $groupAttribute = $changes === [] ? ' data-wizard-summary-unchanged-group' : '';
        echo '<article class="' . $groupClass . '"' . $groupAttribute . '><h3>' . e(t((string) ($group['title_key'] ?? ''), (string) ($group['title'] ?? 'Settings'))) . '</h3>';
        if ($changes !== []) {
            echo '<dl class="wizard-summary-changes">';
            foreach ($changes as $change) {
                if (!is_array($change)) continue;
                $entry = (array) ($entries[(string) ($change['id'] ?? '')] ?? []);
                echo '<div class="wizard-summary-change"><dt>' . e(t((string) ($change['label_key'] ?? ''), (string) ($change['label'] ?? $entry['label'] ?? 'Setting'))) . '</dt><dd><span>' . e(view_admin_setup_wizard_display_value($change['before'] ?? $entry['current'] ?? '', $entry)) . '</span><span aria-hidden="true"> → </span><span class="visually-hidden">' . e(t('admin.setup_wizard.changes_to', 'changes to')) . ' </span><strong>' . e(view_admin_setup_wizard_display_value($change['after'] ?? $change['display'] ?? $change['value'] ?? '', $entry)) . '</strong></dd></div>';
            }
            echo '</dl>';
        } else {
            echo '<p class="wizard-summary-no-changes">' . e(t('admin.setup_wizard.no_changes', 'No settings in this section will be changed.')) . '</p>';
        }
        if ($unchangedCount > 0) {
            echo '<details class="wizard-summary-unchanged" data-wizard-summary-unchanged><summary>' . e(t('admin.setup_wizard.unchanged_details', 'Unchanged and informational settings')) . ' <span>(' . $unchangedCount . ')</span></summary><dl>';
            foreach ($reviewed as $item) {
                if (!is_array($item)) continue;
                $entry = (array) ($entries[(string) ($item['id'] ?? '')] ?? []);
                echo '<div class="wizard-summary-reviewed"><dt>' . e(t((string) ($item['label_key'] ?? ''), (string) ($item['label'] ?? $entry['label'] ?? 'Setting'))) . '</dt><dd><strong>' . e(view_admin_setup_wizard_display_value($item['value'] ?? $item['display'] ?? '', $entry)) . '</strong> <span class="muted">(' . e(t('admin.setup_wizard.reviewed_unchanged', 'reviewed, unchanged')) . ')</span></dd></div>';
            }
            foreach ($skipped as $id) {
                $entry = (array) ($entries[(string) $id] ?? []);
                echo '<div class="wizard-summary-skipped"><dt>' . e(t((string) ($entry['label_key'] ?? ''), (string) ($entry['label'] ?? 'Setting'))) . '</dt><dd>' . e(t('admin.setup_wizard.skipped_unchanged', 'Skipped, original value will be preserved')) . '</dd></div>';
            }
            foreach ($deferred as $item) {
                if (!is_array($item)) continue;
                $entry = (array) ($entries[(string) ($item['id'] ?? '')] ?? []);
                echo '<div class="wizard-summary-deferred"><dt>' . e(t((string) ($item['label_key'] ?? $entry['label_key'] ?? ''), (string) ($item['label'] ?? $entry['label'] ?? 'Setting'))) . '</dt><dd>' . e(t('admin.setup_wizard.deferred_unchanged', 'Specialist or informational item, unchanged by this wizard')) . '</dd></div>';
            }
            echo '</dl></details>';
        }
        echo '</article>';
    }
    view_render_admin_setup_wizard_language_summary($model);
    echo '<label><input type="checkbox" name="approval" value="1" required> ' . e(t('admin.setup_wizard.approve', 'I reviewed these proposed changes and approve applying them.')) . '</label>';
    view_render_admin_setup_wizard_actions(true, 'bottom');
    echo '</form></section>';
}

/** Render the staged language-selector design through its canonical renderer for detailed review.
 * @param array<string,mixed> $model Wizard model containing language summary data.
 * @return void
 */
function view_render_admin_setup_wizard_language_summary(array $model): void
{
    $selector = (array) ($model['language_selector'] ?? []);
    $changes = (array) (($model['draft'] ?? [])['changes'] ?? []);
    $selectorChanged = array_key_exists('public_language_selector_enabled', $changes)
        || array_key_exists('public_language_selector_languages', $changes)
        || array_key_exists('public_language_selector_design', $changes);
    if (!$selectorChanged) return;
    if (array_key_exists('public_language_selector_enabled', $changes)) $selector['enabled'] = (string) $changes['public_language_selector_enabled'] === '1';
    if (array_key_exists('public_language_selector_languages', $changes)) $selector['languages'] = (array) $changes['public_language_selector_languages'];
    if (array_key_exists('public_language_selector_design', $changes)) $selector['design'] = (array) $changes['public_language_selector_design'];
    if ($selector === []) return;
    $selector['id_prefix'] = 'wizard-summary-public-language-selector';
    $selector['detailed_design'] = true;
    echo '<details class="wizard-language-summary"><summary>' . e(t('admin.setup_wizard.language_summary_details', 'Review viewer language selector details')) . '</summary><fieldset disabled>';
    view_render_public_language_selector_settings_panel($selector);
    echo '</fieldset></details>';
}

/** Render server-owned navigation actions for a step or the summary.
 * @param bool $summary Whether the current form is the final summary.
 * @param string $position Visual placement, top or bottom.
 * @return void
 */
function view_render_admin_setup_wizard_actions(bool $summary, string $position = 'bottom'): void
{
    $position = $position === 'top' ? 'top' : 'bottom';
    echo '<nav class="admin-setup-wizard-actions is-' . e($position) . '" aria-label="' . e(t('admin.setup_wizard.actions', 'Wizard actions')) . '"><button type="submit" name="wizard_action" value="back" class="secondary" formnovalidate>' . e(t('admin.setup_wizard.back', 'Back')) . '</button>';
    if ($summary) {
        if ($position === 'bottom') echo '<button type="submit" name="wizard_action" value="apply">' . e(t('admin.setup_wizard.apply', 'Apply setup')) . '</button>';
    } else {
        echo '<button type="submit" name="wizard_action" value="skip" class="secondary" formnovalidate>' . e(t('admin.setup_wizard.skip', 'Skip step')) . '</button><button type="submit" name="wizard_action" value="next">' . e(t('admin.setup_wizard.next', 'Save draft and continue')) . '</button>';
    }
    echo '<button type="submit" name="wizard_action" value="restart" class="link-button" formnovalidate>' . e(t('admin.setup_wizard.restart', 'Restart')) . '</button><button type="submit" name="wizard_action" value="cancel" class="link-button" formnovalidate>' . e(t('admin.setup_wizard.cancel', 'Cancel')) . '</button></nav>';
}

/** Format a prepared setting value for review without exposing machine identifiers.
 * @param string|int|float|bool|list<string|int|float|bool>|array<string,mixed>|null $value Prepared setting value.
 * @param array<string,mixed> $field Field metadata used to choose formatting.
 * @return string Human-readable review value.
 */
function view_admin_setup_wizard_display_value(mixed $value, array $field): string
{
    if (($field['input_type'] ?? '') === 'checkbox') return (string) $value === '1' ? t('admin.setup_wizard.value_enabled', 'Enabled') : t('admin.setup_wizard.value_disabled', 'Disabled');
    if (is_array($value)) {
        foreach ($value as $item) if (is_array($item)) return t('admin.setup_wizard.value_customized', 'Customized choices');
        /**
         * Convert one list item to display text.
         * @param string|int|float|bool|null $item Value from the prepared list.
         * @return string Display text.
         */
        $formatItem = static fn (string|int|float|bool|null $item): string => (string) $item;
        return implode(', ', array_map($formatItem, $value));
    }
    $display = trim((string) $value);
    return $display !== '' ? $display : t('admin.setup_wizard.value_empty', 'Not set');
}
