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
    echo '<nav aria-label="' . e(t('admin.setup_wizard.progress', 'Wizard progress')) . '"><ol class="admin-setup-wizard-progress">';
    foreach ($steps as $id => $step) {
        $index = (int) array_search($id, $keys, true);
        $definition = (array) ($step['definition'] ?? []);
        $state = $id === $active ? ' class="is-active" aria-current="step"' : ($index < $activeIndex ? ' class="is-complete"' : '');
        $separator = str_contains($actionUrl, '?') ? '&' : '?';
        $href = $actionUrl . $separator . 'step=' . rawurlencode((string) $id);
        echo '<li' . $state . '><a href="' . e($href) . '" data-wizard-progress-target="' . e((string) $id) . '"><span>' . ($index + 1) . '</span>' . e(t((string) ($definition['label_key'] ?? ''), (string) ($definition['label'] ?? $id))) . '</a></li>';
    }
    echo '<li' . ($active === 'summary' ? ' class="is-active" aria-current="step"' : '') . '><span class="wizard-progress-summary"><span>' . (count($keys) + 1) . '</span>' . e(t('admin.setup_wizard.summary_short', 'Review')) . '</span></li></ol></nav>';
}

/** Render one editable or discovery-only wizard step.
 * @param array<string,mixed> $model Prepared wizard model.
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
    echo '<form method="post" action="' . e($actionUrl) . '" class="admin-setup-wizard-form" data-wizard-form novalidate' . ($active === 'appearance' ? ' data-theme-form' : '') . '>' . csrf_field() . '<input type="hidden" name="wizard_step" value="' . e($active) . '"><input type="hidden" name="revision" value="' . e($revision) . '">';
    echo '<section class="admin-setup-wizard-step"><h2>' . e(t((string) ($definition['label_key'] ?? ''), (string) ($definition['label'] ?? 'Configure settings'))) . '</h2><p class="wizard-help">' . e(t((string) ($definition['description_key'] ?? ''), (string) ($definition['description'] ?? 'Choose the values you want to stage.'))) . '</p>';
    if ($active === 'appearance') echo '<div class="admin-setup-wizard-appearance" data-theme-preview-root data-theme-preview-background-url="' . e((string) ($preview['background_url'] ?? '')) . '"><div class="admin-setup-wizard-fields">';
    $selectorRendered = false;
    foreach ($entries as $id => $field) {
        if (!is_array($field)) continue;
        if (str_starts_with((string) $id, 'public_language_selector_')) {
            if (!$selectorRendered) view_render_admin_setup_wizard_language_selector($model, $entries, $draft, $errors);
            $selectorRendered = true;
            continue;
        }
        view_render_admin_setup_wizard_field((string) $id, $field, $draft, $errors);
    }
    if ($active === 'appearance') {
        echo '</div><div class="admin-setup-wizard-preview">';
        view_render_admin_setup_wizard_theme_fallback_controls($entries, $preview);
        view_render_admin_theme_live_preview($preview);
        echo '</div></div>';
    }
    echo '</section>';
    view_render_admin_setup_wizard_actions(false);
    echo '</form>';
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
    echo '<article class="wizard-field' . (!$editable ? ' is-readonly' : '') . (!$included ? ' is-excluded' : '') . ($error !== '' ? ' has-error' : '') . '" data-wizard-field>';
    if ($editable) {
        echo '<label class="wizard-include" for="' . e($controlId . '-include') . '"><input id="' . e($controlId . '-include') . '" type="checkbox" name="include[' . e($id) . ']" value="1" data-wizard-include' . ($included ? ' checked' : '') . '> <span>' . e($label) . '</span></label><div class="wizard-field-control">';
        view_render_admin_setup_wizard_control($controlId, $id, $field, $value, $current, $included, $error);
        echo '</div>';
    } else {
        echo '<label class="wizard-include wizard-reviewed" for="' . e($controlId . '-include') . '"><input id="' . e($controlId . '-include') . '" type="checkbox" name="include[' . e($id) . ']" value="1" data-wizard-include' . ($included ? ' checked' : '') . '> <span>' . e($label) . '</span></label><p class="wizard-current-value"><span>' . e(t('admin.setup_wizard.current_value', 'Current value')) . ':</span> <strong>' . e(view_admin_setup_wizard_display_value($current, $field)) . '</strong></p>';
    }
    echo '<p class="wizard-field-description">' . e(t((string) ($field['description_key'] ?? ''), (string) ($field['description'] ?? ''))) . '</p>';
    $exampleKey = (string) ($field['example_key'] ?? $field['example'] ?? '');
    if ($exampleKey !== '') echo '<p class="wizard-field-example"><strong>' . e(t('admin.setup_wizard.example', 'Example')) . ':</strong> ' . e(t($exampleKey, (string) ($field['example_fallback'] ?? 'Choose the value that best matches how the gallery will be used.'))) . '</p>';
    echo '<p class="wizard-skip-help">' . e(t('admin.setup_wizard.item_skip_help', 'If this does not apply to you, you are unsure what it means, or you do not want to decide now, skip this item. The original setting will be preserved.')) . '</p>';
    if (!$editable) {
        echo '<p class="wizard-deferred-notice">' . e(t('admin.setup_wizard.deferred_notice', 'This setting stays unchanged here and can be edited safely on its dedicated page.')) . '</p>';
        $url = (string) ($field['deferred_url'] ?? $field['specialized_url'] ?? '');
        if ($url !== '') echo '<a class="button secondary" href="' . e($url) . '" target="_blank" rel="noopener">' . e(t('admin.setup_wizard.open_specialized', 'Open dedicated settings')) . '</a><span class="wizard-external-note">' . e(t('admin.setup_wizard.external_notice', 'Changes made there are separate from this wizard and are not part of this draft.')) . '</span>';
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
    $hooks = ['site_name' => ' data-theme-preview-site-name', 'theme_accent' => ' data-theme-preview-color="accent"', 'theme_accent_dark' => ' data-theme-preview-color="accent_dark"', 'theme_paper' => ' data-theme-preview-color="paper"', 'theme_panel' => ' data-theme-preview-color="panel"', 'theme_gallery_panel' => ' data-theme-preview-color="gallery_panel"', 'theme_header_text' => ' data-theme-preview-color="header_text"', 'theme_hero_text' => ' data-theme-preview-color="hero_text"', 'theme_radius' => ' data-theme-preview-radius', 'theme_font' => ' data-theme-preview-font', 'theme_page_width' => ' data-theme-preview-width', 'theme_page_width_custom' => ' data-theme-preview-custom-width data-theme-custom-width-number'];
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
    $htmlType = in_array($type, ['color', 'number', 'range', 'url'], true) ? $type : 'text';
    $bounds = '';
    foreach (['min', 'max', 'step'] as $bound) if (isset($field['validation'][$bound])) $bounds .= ' ' . $bound . '="' . e((string) $field['validation'][$bound]) . '"';
    echo '<input id="' . e($controlId) . '" type="' . e($htmlType) . '" name="settings[' . e($id) . ']" value="' . e((string) $value) . '"' . $bounds . $attr . '>';
    if ($id === 'theme_radius') echo '<output for="' . e($controlId) . '" data-theme-radius-display>' . e((string) $value) . 'px</output>';
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
        echo '<p class="wizard-skip-help">' . e(t('admin.setup_wizard.item_skip_help', 'If this does not apply to you, you are unsure what it means, or you do not want to decide now, skip this item. The original setting will be preserved.')) . '</p></div>';
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
    echo '<p class="wizard-skip-help">' . e(t('admin.setup_wizard.item_skip_help', 'If this does not apply to you, you are unsure what it means, or you do not want to decide now, skip this item. The original setting will be preserved.')) . '</p></section>';
}

/** Emit hidden saved values only for preview properties without a visible wizard control.
 * @param array<string,array<string,mixed>> $entries Wizard entries.
 * @param array<string,mixed> $preview Prepared preview values.
 * @return void
 */
function view_render_admin_setup_wizard_theme_fallback_controls(array $entries, array $preview): void
{
    $hooks = ['accent' => 'data-theme-preview-color="accent"', 'accent_dark' => 'data-theme-preview-color="accent_dark"', 'paper' => 'data-theme-preview-color="paper"', 'panel' => 'data-theme-preview-color="panel"', 'gallery_panel' => 'data-theme-preview-color="gallery_panel"', 'header_text' => 'data-theme-preview-color="header_text"', 'hero_text' => 'data-theme-preview-color="hero_text"', 'radius' => 'data-theme-preview-radius', 'font' => 'data-theme-preview-font', 'page_width' => 'data-theme-preview-width', 'page_width_custom' => 'data-theme-preview-custom-width data-theme-custom-width-number'];
    foreach ($hooks as $key => $hook) {
        $id = 'theme_' . $key;
        if (isset($entries[$id]) && !empty($entries[$id]['wizard_editable'])) continue;
        echo '<input type="hidden" value="' . e((string) ($preview[$key] ?? '')) . '" ' . $hook . '>';
    }
    if (!isset($entries['site_name'])) echo '<input type="hidden" value="' . e((string) ($preview['site_name'] ?? 'Gallery CMS')) . '" data-theme-preview-site-name>';
}

/** Render before/after changes, skipped values, deferred settings, and explicit approval.
 * @param array<string,mixed> $model Summary model.
 * @param string $actionUrl Form action URL.
 * @param string $revision Draft revision token.
 * @return void
 */
function view_render_admin_setup_wizard_summary(array $model, string $actionUrl, string $revision): void
{
    $steps = (array) ($model['steps'] ?? []);
    echo '<section class="admin-setup-wizard-summary"><h2>' . e(t('admin.setup_wizard.summary_title', 'Review your setup')) . '</h2><p>' . e(t('admin.setup_wizard.summary_help', 'Check every proposed change. Nothing is saved until you select the approval checkbox and apply the setup.')) . '</p>';
    foreach ((array) ($model['summary_groups'] ?? []) as $group) {
        if (!is_array($group)) continue;
        $entries = (array) ($steps[(string) ($group['id'] ?? '')]['entries'] ?? []);
        echo '<article class="wizard-summary-group"><h3>' . e(t((string) ($group['title_key'] ?? ''), (string) ($group['title'] ?? 'Settings'))) . '</h3><dl>';
        foreach ((array) ($group['changes'] ?? []) as $change) {
            $entry = (array) ($entries[(string) ($change['id'] ?? '')] ?? []);
            echo '<div class="wizard-summary-change"><dt>' . e(t((string) ($change['label_key'] ?? ''), (string) ($change['label'] ?? $entry['label'] ?? 'Setting'))) . '</dt><dd><span>' . e(view_admin_setup_wizard_display_value($change['before'] ?? $entry['current'] ?? '', $entry)) . '</span><span aria-hidden="true"> → </span><span class="visually-hidden">' . e(t('admin.setup_wizard.changes_to', 'changes to')) . ' </span><strong>' . e(view_admin_setup_wizard_display_value($change['after'] ?? $change['display'] ?? $change['value'] ?? '', $entry)) . '</strong></dd></div>';
        }
        foreach ((array) ($group['reviewed'] ?? []) as $reviewed) {
            if (!is_array($reviewed)) continue;
            $entry = (array) ($entries[(string) ($reviewed['id'] ?? '')] ?? []);
            echo '<div class="wizard-summary-reviewed"><dt>' . e(t((string) ($reviewed['label_key'] ?? ''), (string) ($reviewed['label'] ?? $entry['label'] ?? 'Setting'))) . '</dt><dd><strong>' . e(view_admin_setup_wizard_display_value($reviewed['value'] ?? $reviewed['display'] ?? '', $entry)) . '</strong> <span class="muted">(' . e(t('admin.setup_wizard.reviewed_unchanged', 'reviewed, unchanged')) . ')</span></dd></div>';
        }
        foreach ((array) ($group['skipped'] ?? []) as $id) {
            $entry = (array) ($entries[(string) $id] ?? []);
            echo '<div class="wizard-summary-skipped"><dt>' . e(t((string) ($entry['label_key'] ?? ''), (string) ($entry['label'] ?? 'Setting'))) . '</dt><dd>' . e(t('admin.setup_wizard.skipped_unchanged', 'Skipped — original value will be preserved')) . '</dd></div>';
        }
        $deferred = (array) ($group['deferred'] ?? []);
        if ($deferred === []) {
            foreach ($entries as $id => $entry) if (is_array($entry) && empty($entry['wizard_editable'])) $deferred[] = ['id' => $id, 'label' => $entry['label'] ?? 'Setting', 'label_key' => $entry['label_key'] ?? '', 'url' => $entry['specialized_url'] ?? ''];
        }
        foreach ($deferred as $item) {
            if (!is_array($item)) continue;
            $entry = (array) ($entries[(string) ($item['id'] ?? '')] ?? []);
            echo '<div class="wizard-summary-deferred"><dt>' . e(t((string) ($item['label_key'] ?? $entry['label_key'] ?? ''), (string) ($item['label'] ?? $entry['label'] ?? 'Setting'))) . '</dt><dd>' . e(t('admin.setup_wizard.deferred_unchanged', 'Deferred to its dedicated settings page — unchanged by this wizard'));
            $url = (string) ($item['url'] ?? '');
            if ($url !== '') echo ' <a href="' . e($url) . '" target="_blank" rel="noopener">' . e(t('admin.setup_wizard.open_specialized', 'Open dedicated settings')) . '</a>';
            echo '</dd></div>';
        }
        echo '</dl></article>';
    }
    view_render_admin_setup_wizard_language_summary($model);
    echo '<form method="post" action="' . e($actionUrl) . '" class="admin-setup-wizard-approval">' . csrf_field() . '<input type="hidden" name="wizard_step" value="summary"><input type="hidden" name="revision" value="' . e($revision) . '"><label><input type="checkbox" name="approval" value="1" required> ' . e(t('admin.setup_wizard.approve', 'I reviewed these proposed changes and approve applying them.')) . '</label>';
    view_render_admin_setup_wizard_actions(true);
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
 * @param bool $summary Whether the apply action should be shown.
 * @return void
 */
function view_render_admin_setup_wizard_actions(bool $summary): void
{
    echo '<nav class="admin-setup-wizard-actions" aria-label="' . e(t('admin.setup_wizard.actions', 'Wizard actions')) . '"><button type="submit" name="wizard_action" value="back" class="secondary" formnovalidate>' . e(t('admin.setup_wizard.back', 'Back')) . '</button>';
    if ($summary) echo '<button type="submit" name="wizard_action" value="apply">' . e(t('admin.setup_wizard.apply', 'Apply setup')) . '</button>';
    else echo '<button type="submit" name="wizard_action" value="skip" class="secondary" formnovalidate>' . e(t('admin.setup_wizard.skip', 'Skip step')) . '</button><button type="submit" name="wizard_action" value="next">' . e(t('admin.setup_wizard.next', 'Save draft and continue')) . '</button>';
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
