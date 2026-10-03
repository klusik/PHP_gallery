<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_settings.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders the centralized Admin Settings overview and its section panels.
 *
 * Responsibilities:
 *   - Present important global settings without exposing sensitive raw values
 *   - Keep each Settings group in a separate scoped form when central editing is allowed
 *   - Provide stable deep links to central sections and specialized Admin pages
 *   - Render accessible labels, descriptions, field errors, and page-level error summaries
 *   - Preserve a useful no-JavaScript fallback by rendering only the selected panel as visible
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
 *   - Registry values are trusted only as data; all visible output and URLs are escaped here.
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\csrf_field;
use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Services\t;

/**
 * Render the centralized Admin Settings page.
 *
 * @param array<string,mixed> $model Page model.
 * @return void
 */
function view_render_admin_settings_page(array $model): void
{
    $activeSection = (string) ($model['active_section'] ?? 'general');
    $registry = is_array($model['registry'] ?? null) ? $model['registry'] : [];
    $errors = is_array($model['errors'] ?? null) ? $model['errors'] : [];
    $submittedValues = is_array($model['submitted_values'] ?? null) ? $model['submitted_values'] : [];
    $notice = trim((string) ($model['notice'] ?? ''));
    $sections = is_array($model['sections'] ?? null) ? $model['sections'] : [];

    render_header(t('admin.settings.page_title', 'Settings'));
    echo '<div class="admin-settings-page" data-admin-settings-workspace>';
    echo '<header class="admin-settings-toolbar"><h1>' . e(t('admin.settings.title', 'Settings')) . '</h1>';
    view_render_admin_settings_search($sections, $registry);
    $wizard = (array) ($model['setup_wizard'] ?? []);
    if ((string) ($wizard['url'] ?? '') !== '') {
        echo '<a class="button secondary admin-settings-wizard" href="' . e((string) $wizard['url']) . '">' . e(!empty($wizard['resume'])
            ? t('admin.setup_wizard.resume', 'Resume setup wizard')
            : t('admin.setup_wizard.title', 'Setup wizard')) . '</a>';
    }
    echo '</header><div class="admin-settings-layout">';

    // Settings owns its navigation so changes never affect other Admin tab groups.
    echo '<nav class="admin-settings-navigation" data-admin-settings-navigation aria-label="' . e(t('admin.settings.sections_aria', 'Settings sections')) . '">';
    echo '<label class="admin-settings-mobile-label" for="admin-settings-category">' . e(t('admin.settings.workspace.category', 'Category')) . '</label>';
    echo '<select class="admin-settings-mobile-select" id="admin-settings-category" data-admin-settings-category>';
    foreach ($sections as $sectionId => $section) {
        echo '<option value="' . e($sectionId) . '"' . ($sectionId === $activeSection ? ' selected' : '') . '>' . e(t((string) $section['label_key'], (string) $section['label'])) . '</option>';
    }
    echo '</select><div class="admin-tab-list" role="tablist" aria-orientation="vertical">';
    foreach ($sections as $sectionId => $section) {
        $panelId = (string) ($section['panel_id'] ?? ('settings-' . $sectionId));
        $isActive = $sectionId === $activeSection;
        echo '<a class="admin-tab' . ($isActive ? ' is-active' : '') . '" id="' . e($panelId . '-control') . '" href="' . e((string) ($section['tab_url'] ?? $section['url'] ?? '')) . '" role="tab" aria-controls="' . e($panelId) . '" aria-selected="' . ($isActive ? 'true' : 'false') . '" tabindex="' . ($isActive ? '0' : '-1') . '" data-admin-tab-target="' . e($panelId) . '">';
        echo '<span>' . e(t((string) $section['label_key'], (string) $section['label'])) . '</span><span class="admin-settings-draft-badge" data-admin-settings-draft-badge="' . e($sectionId) . '" hidden></span></a>';
    }
    echo '</div></nav>';

    echo '<div class="admin-settings-panels">';
    foreach ($sections as $sectionId => $section) {
        $panelId = (string) ($section['panel_id'] ?? ('settings-' . $sectionId));
        $isActive = $sectionId === $activeSection;
        $entries = array_filter($registry, /** Select the current category's visible controls. @param array<string,mixed> $entry Prepared registry entry. @return bool Whether it belongs in this panel. */ static fn (array $entry): bool => ($entry['view_group'] ?? $entry['group'] ?? '') === $sectionId && empty($entry['discovery_only']));
        echo '<section class="panel admin-tab-panel admin-settings-section' . ($isActive ? ' is-active' : '') . '" id="' . e($panelId) . '" role="tabpanel" aria-labelledby="' . e($panelId . '-control') . '" data-admin-tab-panel' . ($isActive ? '' : ' hidden') . '>';
        echo '<div class="admin-settings-section-heading"><h2>' . e(t((string) $section['label_key'], (string) $section['label'])) . '</h2><p>' . e(t((string) $section['description_key'], (string) $section['description'])) . '</p></div>';
        echo '<div class="admin-settings-feedback" role="status" aria-live="polite" data-admin-settings-feedback' . ($sectionId === $activeSection && $notice !== '' ? '' : ' hidden') . '>' . ($sectionId === $activeSection ? e($notice) : '') . '</div>';
        if ($sectionId === $activeSection) {
            view_render_admin_settings_error_summary($errors);
        }
        echo '<div data-admin-settings-content>';
        view_render_admin_settings_section($sectionId, $entries, $errors, $submittedValues, $model);
        echo '</div></section>';
    }
    echo '</div></div></div>';
    render_footer();
}

/**
 * Render the client-side Settings spotlight and its complete searchable index.
 *
 * @param array<string,array<string,string>> $sections Section taxonomy.
 * @param array<string,array<string,mixed>> $registry Settings registry.
 * @return void Emit the searchable index and input.
 */
function view_render_admin_settings_search(array $sections, array $registry): void
{
    echo '<div class="admin-settings-search" data-admin-settings-search data-results-label="' . e(t('admin.settings.search_matches', 'matching settings')) . '" data-empty-label="' . e(t('admin.settings.search_empty', 'No matching settings found.')) . '">';
    echo '<div class="admin-settings-search-shell">';
    echo '<span class="admin-settings-search-icon" aria-hidden="true">&#128269;</span>';
    echo '<input class="admin-settings-search-input" type="search" role="combobox" autocomplete="off" spellcheck="false" placeholder="' . e(t('admin.settings.search_placeholder', 'Search settings and tools...')) . '" aria-label="' . e(t('admin.settings.search_label', 'Search settings')) . '" aria-autocomplete="list" aria-controls="admin-settings-search-results" aria-expanded="false" data-admin-settings-search-input>';
    echo '<button type="button" class="admin-settings-search-clear" aria-label="' . e(t('admin.settings.search_clear', 'Clear settings search')) . '" data-admin-settings-search-clear hidden>&times;</button>';
    echo '</div>';
    echo '<div class="admin-settings-search-results" id="admin-settings-search-results" role="listbox" aria-label="' . e(t('admin.settings.search_results', 'Matching settings')) . '" data-admin-settings-search-results hidden>';
    echo '<p class="admin-settings-search-status" role="status" aria-live="polite" data-admin-settings-search-status></p>';
    echo '<div class="admin-settings-search-list">';
    foreach ($registry as $id => $entry) {
        $sectionId = (string) ($entry['view_group'] ?? 'general');
        $section = $sections[$sectionId] ?? [];
        $label = view_admin_settings_entry_label($entry);
        $description = view_admin_settings_entry_description($entry);
        $sectionLabel = t((string) ($section['label_key'] ?? ''), (string) ($section['label'] ?? $sectionId));
        $targetId = 'admin-setting-result-' . preg_replace('/[^a-z0-9_-]/i', '-', (string) $id);
        $keywords = implode(' ', [(string) $id, str_replace('_', ' ', (string) $id), $label, $description, $sectionLabel, (string) ($entry['sensitivity'] ?? '')]);
        echo '<a class="admin-settings-search-result" id="admin-settings-search-option-' . e((string) $id) . '" href="' . e((string) ($entry['view_search_url'] ?? $entry['view_section_url'] ?? '')) . '"' . (!empty($entry['discovery_only']) ? view_admin_settings_panel_attributes($entry) : '') . ' role="option" aria-selected="false" data-admin-settings-search-result data-search-text="' . e($keywords) . '" data-search-label="' . e($label) . '" data-search-section="' . e($sectionId) . '" data-search-target="' . e($targetId) . '" data-search-external="' . (!empty($entry['discovery_only']) ? '1' : '0') . '" hidden>';
        echo '<span class="admin-settings-search-result-section">' . e($sectionLabel) . '</span>';
        echo '<span class="admin-settings-search-result-copy"><strong>' . e($label) . '</strong><small>' . e($description) . '</small></span>';
        echo '<span class="admin-settings-search-result-arrow" aria-hidden="true">&rarr;</span></a>';
    }
    echo '</div></div></div>';
}

/**
 * Render one Settings section with a small scoped edit form and summary-only rows.
 *
 * @param string $sectionId Section identifier.
 * @param array<string,array<string,mixed>> $entries Registry entries.
 * @param array<string,mixed> $errors Validation errors.
 * @param array<string,mixed> $submittedValues Submitted values retained after validation failure.
 * @param array<string,mixed> $pageModel Prepared section URLs and language selector presentation.
 * @return void Emit the category form and specialized rows.
 */
function view_render_admin_settings_section(string $sectionId, array $entries, array $errors, array $submittedValues, array $pageModel = []): void
{
    $editable = array_filter($entries, static fn (array $entry): bool => !empty($entry['central_editable']));
    $summaryOnly = array_filter($entries, static fn (array $entry): bool => empty($entry['central_editable']));

    if ($sectionId === 'uploads') {
        echo '<div class="admin-settings-upload-intro"><p class="muted">' . e(t('admin.settings.uploads.workflow_hint', 'Add photos from the gallery panel. Manage shared upload preferences and mobile connections here.')) . '</p><a class="button secondary" href="' . e((string) ($pageModel['upload_workflow_url'] ?? '')) . '">' . e(t('admin.dashboard.open_galleries', 'Open galleries')) . '</a></div>';
        if (is_array($pageModel['upload_support'] ?? null)) {
            echo '<details class="admin-settings-upload-details"><summary>' . e(t('admin.upload.support_title', 'Upload support')) . '</summary>';
            view_render_admin_upload_support_matrix($pageModel['upload_support']);
            echo '</details>';
        }
    }

    if ($editable !== []) {
        echo '<form method="post" action="' . e((string) ($pageModel['sections'][$sectionId]['url'] ?? '')) . '" class="form-grid admin-settings-group-form" data-admin-settings-form="' . e($sectionId) . '" data-changes-label="' . e(t('admin.settings.workspace.unsaved', '{count} unsaved changes')) . '" data-clean-label="' . e(t('admin.settings.workspace.clean', 'No unsaved changes')) . '" data-saving-label="' . e(t('admin.settings.workspace.saving', 'Saving…')) . '" data-failed-label="' . e(t('admin.settings.workspace.save_failed', 'Settings could not be saved. Your changes are still here; please try again.')) . '" data-address-label="' . e(t('admin.settings.workspace.new_address', 'Continue at the new website address')) . '" data-language-label="' . e(t('admin.settings.workspace.reload_language', 'Reload the interface in the selected language')) . '">' . csrf_field();
        echo '<input type="hidden" name="return_section" value="' . e($sectionId) . '">';
        echo '<div class="admin-settings-form-errors" role="alert" data-admin-settings-errors hidden></div>';
        $lastGroup = '';
        $groupDisclosure = false;
        foreach ($editable as $id => $entry) {
            $editGroup = (string) ($entry['view_edit_group'] ?? 'preferences');
            if ($editGroup !== $lastGroup) {
                if ($lastGroup !== '') {
                    echo '</fieldset>';
                    if ($groupDisclosure) {
                        echo '</details>';
                    }
                }
                $groupLabel = match ($editGroup) {
                    'website' => 'Website', 'languages' => 'Languages', 'navigation' => 'Navigation and search',
                    'uploads_general' => 'Upload preferences', 'uploads_browser' => 'Browser preparation and limits',
                    'uploads_thumbnail' => 'Thumbnail rebuild limits', 'uploads_legacy' => 'Legacy upload pages', default => 'Preferences',
                };
                $groupDisclosure = in_array($editGroup, ['uploads_browser', 'uploads_thumbnail'], true);
                if ($groupDisclosure) {
                    $groupHasErrors = false;
                    foreach ($entries as $groupId => $groupEntry) {
                        if (($groupEntry['view_edit_group'] ?? '') === $editGroup && isset($errors[$groupId])) {
                            $groupHasErrors = true;
                        }
                    }
                    echo '<details class="admin-settings-upload-details" id="admin-settings-group-' . e($editGroup) . '"' . ($groupHasErrors ? ' open' : '') . '><summary>' . e(t('admin.settings.workspace.group.' . $editGroup, $groupLabel)) . '</summary>';
                }
                echo '<fieldset class="form-grid" data-settings-edit-group="' . e($editGroup) . '"><legend>' . e(t('admin.settings.workspace.group.' . $editGroup, $groupLabel)) . '</legend>';
                $lastGroup = $editGroup;
            }
            if ($id === 'public_language_selector_enabled') {
                $selectorHasError = isset($errors['public_language_selector_enabled']) || isset($errors['public_language_selector_languages']) || isset($errors['public_language_selector_design']);
                echo '<details class="admin-settings-language-details" id="admin-setting-result-public_language_selector_design" data-admin-setting-target tabindex="-1"' . ($selectorHasError ? ' open' : '') . '><summary>' . e(view_admin_settings_entry_label($entry)) . ' <span class="muted" data-settings-language-status data-enabled-label="' . e(t('admin.common.enabled', 'Enabled')) . '" data-disabled-label="' . e(t('admin.common.disabled', 'Disabled')) . '">' . e(view_admin_settings_display_value($entry)) . '</span></summary>';
                view_render_public_language_selector_settings_panel([
                    'id_prefix' => 'admin-setting-result-public_language_selector_enabled',
                    'languages_target_id' => 'admin-setting-result-public_language_selector_languages',
                    'admin_setting_target' => true,
                    'enabled_name' => 'settings[public_language_selector_enabled]',
                    'languages_name' => 'settings[public_language_selector_languages][]',
                    'design_name' => 'settings[public_language_selector_design]',
                    'detailed_design' => false,
                    'compact' => true,
                    'enabled' => array_key_exists('public_language_selector_enabled', $submittedValues)
                        ? !empty($submittedValues['public_language_selector_enabled'])
                        : !empty($pageModel['language_selector']['enabled']),
                    'languages' => array_key_exists('public_language_selector_languages', $submittedValues)
                        ? (array) $submittedValues['public_language_selector_languages']
                        : (array) ($pageModel['language_selector']['languages'] ?? []),
                    'design' => array_key_exists('public_language_selector_design', $submittedValues)
                        ? (array) $submittedValues['public_language_selector_design']
                        : (array) ($pageModel['language_selector']['design'] ?? []),
                    'errors' => [
                        'enabled' => $errors['public_language_selector_enabled'] ?? '',
                        'languages' => $errors['public_language_selector_languages'] ?? '',
                    ],
                    'presentations' => (array) ($pageModel['language_selector']['presentations'] ?? []),
                    'supported_languages' => (array) ($pageModel['language_selector']['supported_languages'] ?? []),
                    'design_defaults' => (array) ($pageModel['language_selector']['design_defaults'] ?? []),
                    'design_bounds' => (array) ($pageModel['language_selector']['design_bounds'] ?? []),
                ]);
                echo '</details>';
                continue;
            }
            if ($id === 'public_language_selector_languages' || $id === 'public_language_selector_design') {
                continue;
            }
            view_render_admin_settings_input((string) $id, $entry, $errors, $submittedValues);
        }
        echo '</fieldset>' . ($groupDisclosure ? '</details>' : '') . '<div class="admin-settings-savebar" data-admin-settings-savebar><span role="status" aria-live="polite" data-admin-settings-changes>' . e(t('admin.settings.workspace.clean', 'No unsaved changes')) . '</span><div><button type="button" class="secondary" data-admin-settings-reset hidden>' . e(t('admin.settings.workspace.reset', 'Revert changes')) . '</button><button type="submit" data-admin-settings-save>' . e(t('admin.settings.workspace.save', 'Save changes')) . '</button></div></div></form>';
    }

    if ($summaryOnly !== []) {
        echo '<div class="admin-settings-summary-list">';
        foreach ($summaryOnly as $entry) {
            view_render_admin_settings_summary_card($entry);
        }
        echo '</div>';
    }
    if ($sectionId === 'uploads') {
        if (is_array($pageModel['mobile_uploads'] ?? null)) {
            view_render_admin_mobile_uploads_settings($pageModel['mobile_uploads']);
        } else {
            echo '<section class="admin-settings-mobile" data-admin-settings-mobile data-mobile-load-url="' . e((string) ($pageModel['upload_mobile_url'] ?? '')) . '"><h3>' . e(t('mobile_webdav.title', 'Mobile uploads')) . '</h3><p class="muted" data-admin-settings-mobile-feedback role="status">' . e(t('admin.settings.uploads.mobile_loading', 'Loading mobile connections…')) . '</p><a href="' . e((string) ($pageModel['sections']['uploads']['url'] ?? '')) . '">' . e(t('admin.settings.uploads.open_mobile', 'Open mobile connection settings')) . '</a></section>';
        }
    }
}

/**
 * Render one centrally editable input with visible label, help text, and field error.
 *
 * @param string $id Stable setting identifier.
 * @param array<string,mixed> $entry Registry entry.
 * @param array<string,mixed> $errors Validation errors.
 * @param array<string,mixed> $submittedValues Submitted values.
 * @return void Emit the labeled input and optional errors.
 */
function view_render_admin_settings_input(string $id, array $entry, array $errors, array $submittedValues): void
{
    $inputId = 'admin-setting-' . preg_replace('/[^a-z0-9_-]/i', '-', $id);
    $helpId = $inputId . '-help';
    $error = trim((string) ($errors[$id] ?? ''));
    $errorId = $inputId . '-error';
    $current = array_key_exists($id, $submittedValues) ? $submittedValues[$id] : ($entry['current'] ?? '');
    $type = (string) ($entry['input_type'] ?? 'text');
    $describedBy = $helpId . ($error !== '' ? ' ' . $errorId : '');

    echo '<div class="admin-settings-field' . ($error !== '' ? ' has-error' : '') . '" id="admin-setting-result-' . e($id) . '" data-admin-setting-target tabindex="-1"><div class="admin-settings-field-copy">';
    echo '<label for="' . e($inputId) . '">' . e(view_admin_settings_entry_label($entry)) . '</label>';
    echo '<details class="admin-settings-help"><summary aria-label="' . e(t('admin.settings.workspace.help_item', 'Help: {label}', ['label' => view_admin_settings_entry_label($entry)])) . '">?</summary><span id="' . e($helpId) . '">' . e(view_admin_settings_entry_description($entry)) . '</span></details>';
    echo '</div><div class="admin-settings-field-control">';
    if ($type === 'checkbox') {
        $checked = array_key_exists($id, $submittedValues) ? !empty($submittedValues[$id]) : ((string) ($entry['current'] ?? '0') === '1');
        if (($entry['group'] ?? '') === 'uploads') {
            echo '<input type="hidden" name="settings[' . e($id) . ']" value="0">';
        }
        echo '<label class="checkbox-label admin-settings-toggle" for="' . e($inputId) . '"><input id="' . e($inputId) . '" type="checkbox" name="settings[' . e($id) . ']" value="1"' . ($checked ? ' checked' : '') . ' aria-describedby="' . e($describedBy) . '"' . ($error !== '' ? ' aria-invalid="true"' : '') . '><span>' . e(t('admin.common.enabled', 'Enabled')) . '</span></label>';
    } elseif ($type === 'number') {
        $bounds = '';
        foreach (['min', 'max', 'step'] as $attribute) {
            if (isset($entry['validation'][$attribute])) {
                $bounds .= ' ' . $attribute . '="' . e((string) $entry['validation'][$attribute]) . '"';
            }
        }
        echo '<input id="' . e($inputId) . '" type="number" name="settings[' . e($id) . ']" value="' . e((string) $current) . '"' . $bounds . ' aria-describedby="' . e($describedBy) . '"' . ($error !== '' ? ' aria-invalid="true"' : '') . '>';
    } elseif ($type === 'select') {
        echo '<select id="' . e($inputId) . '" name="settings[' . e($id) . ']" aria-describedby="' . e($describedBy) . '"' . ($error !== '' ? ' aria-invalid="true"' : '') . '>';
        $allowed = is_array($entry['validation']['allowed'] ?? null) ? $entry['validation']['allowed'] : [];
        foreach ($allowed as $option) {
            $option = (string) $option;
            echo '<option value="' . e($option) . '"' . ((string) $current === $option ? ' selected' : '') . '>' . e((string) ($entry['view_option_labels'][$option] ?? view_admin_settings_option_label($id, $option))) . '</option>';
        }
        echo '</select>';
    } else {
        $maxLength = (int) ($entry['validation']['max_length'] ?? 0);
        echo '<input id="' . e($inputId) . '" type="text" name="settings[' . e($id) . ']" value="' . e((string) $current) . '"' . ($maxLength > 0 ? ' maxlength="' . $maxLength . '"' : '') . ' aria-describedby="' . e($describedBy) . '"' . ($error !== '' ? ' aria-invalid="true"' : '') . '>';
    }
    echo '<span class="error" id="' . e($errorId) . '" data-admin-settings-field-error="' . e($id) . '"' . ($error === '' ? ' hidden' : '') . '>' . e($error) . '</span>';
    echo '</div></div>';
}

/**
 * Render one summary-only setting card.
 *
 * @param array<string,mixed> $entry Registry entry.
 * @return void Emit the card with its prepared navigation metadata.
 */
function view_render_admin_settings_summary_card(array $entry): void
{
    $id = (string) ($entry['id'] ?? '');
    echo '<article class="admin-settings-summary-row" id="admin-setting-result-' . e($id) . '" data-admin-setting-target tabindex="-1"><div class="admin-settings-field-copy">';
    echo '<strong>' . e(view_admin_settings_entry_label($entry)) . '</strong><span class="muted">' . e(view_admin_settings_entry_description($entry)) . '</span>';
    if (in_array((string) ($entry['source'] ?? ''), ['inherited'], true) || !empty($entry['migration_required'])) {
        view_render_admin_settings_source($entry);
    }
    echo '</div><div class="admin-settings-summary-control"><span class="admin-settings-value">' . e(view_admin_settings_display_value($entry)) . '</span>';
    $url = (string) ($entry['specialized_url'] ?? '');
    if ($url !== '') {
        $panelAttributes = view_admin_settings_panel_attributes($entry);
        echo '<a class="admin-settings-edit-link" href="' . e($url) . '"' . $panelAttributes . ' aria-label="' . e(t('admin.settings.workspace.edit_item', 'Edit: {label}', ['label' => view_admin_settings_entry_label($entry)])) . '">' . e(t('admin.settings.workspace.edit', 'Edit')) . ' <span aria-hidden="true">&nearr;</span></a>';
    }
    echo '</div></article>';
}

/**
 * Preserve prepared side-panel navigation on specialized Settings links.
 * @param array<string,mixed> $entry Registry entry with presentation metadata.
 * @return string Escaped link attributes or an empty string.
 */
function view_admin_settings_panel_attributes(array $entry): string
{
    $workflow = (string) ($entry['specialized_panel_workflow'] ?? '');
    $url = (string) ($entry['specialized_panel_url'] ?? '');
    return $workflow !== '' && $url !== ''
        ? ' data-gallery-side-panel-link data-admin-side-panel-workflow="' . e($workflow)
            . '" data-admin-side-panel-title="' . e(view_admin_settings_entry_label($entry))
            . '" data-gallery-side-panel-url="' . e($url) . '"' : '';
}

/**
 * Render configured/default/inherited source status without relying on color alone.
 *
 * @param array<string,mixed> $entry Registry entry.
 */
function view_render_admin_settings_source(array $entry): void
{
    $key = (string) ($entry['key'] ?? '');
    $explicit = !empty($entry['explicit']);
    $inheritable = str_starts_with($key, 'tag_page_') || str_starts_with($key, 'home_gallery_grid_');
    if (!empty($entry['migration_required'])) {
        $label = t('admin.settings.source.migration_required', 'Migration required before this setting is available');
    } elseif ($key === '' || (string) ($entry['input_type'] ?? '') === 'specialized') {
        $label = t('admin.settings.source.specialized', 'Managed on specialized page');
    } elseif ($explicit) {
        $label = t('admin.settings.source.explicit', 'Explicitly configured');
    } elseif ($inheritable) {
        $label = t('admin.settings.source.inherited', 'Inherited from global defaults');
    } else {
        $label = t('admin.settings.source.default', 'Using default');
    }
    echo '<span class="muted admin-settings-source"><strong>' . e(t('admin.settings.source_label', 'Source')) . ':</strong> ' . e($label) . '</span>';
}

/**
 * Return the translated label for one registry entry.
 *
 * @param array<string,mixed> $entry Registry entry.
 * @return string Readable label.
 */
function view_admin_settings_entry_label(array $entry): string
{
    return t((string) ($entry['label_key'] ?? ''), (string) ($entry['label'] ?? ''));
}

/**
 * Return the translated description for one registry entry.
 *
 * @param array<string,mixed> $entry Registry entry.
 * @return string Readable description.
 */
function view_admin_settings_entry_description(array $entry): string
{
    if (isset($entry['view_description'])) {
        return (string) $entry['view_description'];
    }
    return t((string) ($entry['description_key'] ?? ''), (string) ($entry['description'] ?? ''));
}

/**
 * Return a safe readable value for one registry entry.
 *
 * @param array<string,mixed> $entry Registry entry.
 * @return string Human-readable value.
 */
function view_admin_settings_display_value(array $entry): string
{
    if ((string) ($entry['sensitivity'] ?? '') === 'secret') {
        return (string) ($entry['current'] ?? t('admin.settings.status.not_configured', 'Not configured'));
    }
    $value = $entry['current'] ?? '';
    if (isset($entry['view_option_labels'][(string) $value])) {
        return (string) $entry['view_option_labels'][(string) $value];
    }
    if (in_array((string) ($entry['input_type'] ?? ''), ['checkbox'], true) || in_array((string) ($entry['id'] ?? ''), ['pagination_enabled', 'admin_upload_auto_rename_enabled', 'browser_upload_enabled', 'telemetry_enabled', 'telemetry_public_usage_enabled', 'seo_request_guard_enabled', 'seo_request_guard_logging_enabled', 'site_maintenance_enabled'], true)) {
        return (string) $value === '1' ? t('admin.common.enabled', 'Enabled') : t('admin.common.disabled', 'Disabled');
    }
    $labels = [
        'wide' => 'Wide', 'default' => 'Default', 'custom' => 'Custom',
        'horizontal' => 'Horizontal', 'vertical' => 'Vertical',
        'single' => 'One photo', 'strip' => 'Photo strip', 'carousel' => 'Carousel',
        'phone_jpeg' => 'Convert phone photos to JPEG',
        'original' => 'Original format', 'preserve' => 'Preserve the source format',
    ];
    if (isset($labels[(string) $value])) {
        return t('admin.settings.workspace.value.' . (string) $value, $labels[(string) $value]);
    }
    if (in_array((string) $value, ['progressive', 'responsive'], true)) {
        return view_admin_settings_option_label('public_thumbnail_rendering_mode', (string) $value);
    }
    return trim((string) $value) !== '' ? (string) $value : t('admin.settings.status.not_configured', 'Not configured');
}

/**
 * Return a translated label for common select values.
 *
 * @param string $id Setting identifier.
 * @param string $value Machine value.
 * @return string Readable label.
 */
function view_admin_settings_option_label(string $id, string $value): string
{
    if ($id === 'admin_upload_client_format_mode') {
        return $value === 'phone_jpeg'
            ? t('admin.upload.client_format_phone_jpeg', 'Prefer phone-rendered JPG/PNG/WebP, no RAW/DNG')
            : t('admin.upload.client_format_server_supported', 'Allow all server-supported formats');
    }
    if ($id === 'browser_upload_batch_size_policy') {
        return t('admin.upload.browser_batch_policy_ratio', 'Use PHP upload limit ratio');
    }
    if ($id === 'public_thumbnail_rendering_mode') {
        return $value === 'progressive'
            ? t('admin.settings.thumbnail.progressive', 'Progressive (Default)')
            : t('admin.settings.thumbnail.responsive', 'Responsive (Legacy)');
    }
    if ($id === 'public_language') {
        return strtoupper($value);
    }
    return strtoupper($value);
}

/**
 * Render the page-level validation error summary.
 *
 * @param array<string,mixed> $errors Validation errors.
 */
function view_render_admin_settings_error_summary(array $errors): void
{
    if ($errors === []) {
        return;
    }
    $messages = [];
    foreach ($errors as $error) {
        if (is_array($error)) {
            foreach ($error as $message) {
                if (trim((string) $message) !== '') {
                    $messages[] = (string) $message;
                }
            }
        } elseif (trim((string) $error) !== '') {
            $messages[] = (string) $error;
        }
    }
    if ($messages === []) {
        return;
    }
    echo '<section class="panel notice error" role="alert" aria-labelledby="admin-settings-error-summary-title">';
    echo '<h2 id="admin-settings-error-summary-title">' . e(t('admin.settings.error_summary_title', 'There is a problem with these settings')) . '</h2><ul>';
    foreach (array_values(array_unique($messages)) as $message) {
        echo '<li>' . e($message) . '</li>';
    }
    echo '</ul></section>';
}
