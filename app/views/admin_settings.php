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
use function Gallery\Services\admin_settings_bool_label;
use function Gallery\Services\admin_settings_section_id;
use function Gallery\Services\admin_settings_section_normalize;
use function Gallery\Services\admin_settings_sections;
use function Gallery\Services\admin_settings_url;
use function Gallery\Services\t;
use function Gallery\Services\translation_load_language;
use function Gallery\Services\translation_public_language_selector_enabled;
use function Gallery\Services\translation_public_language_selector_languages;
use function Gallery\Services\translation_public_language_selector_design;

/**
 * Render the centralized Admin Settings page.
 *
 * @param array<string,mixed> $model Page model.
 */
function view_render_admin_settings_page(array $model): void
{
    $activeSection = admin_settings_section_normalize($model['active_section'] ?? 'general');
    $registry = is_array($model['registry'] ?? null) ? $model['registry'] : [];
    $errors = is_array($model['errors'] ?? null) ? $model['errors'] : [];
    $submittedValues = is_array($model['submitted_values'] ?? null) ? $model['submitted_values'] : [];
    $notice = trim((string) ($model['notice'] ?? ''));
    $sections = admin_settings_sections();

    render_header(t('admin.settings.page_title', 'Settings'));
    view_render_admin_hero([
        'kicker' => t('admin.settings.kicker', 'Administration'),
        'title' => t('admin.settings.title', 'Settings'),
        'description' => t('admin.settings.description', 'Central overview of important global settings. Complex, sensitive, and destructive controls remain on their specialized pages.'),
    ]);
    view_render_admin_settings_search($sections, $registry);

    if ($notice !== '') {
        echo '<section class="panel notice" role="status"><p>' . e($notice) . '</p></section>';
    }
    view_render_admin_settings_error_summary($errors);
    view_render_admin_settings_overview($sections, $registry, $activeSection);

    echo '<nav class="admin-tabs admin-settings-tabs" data-admin-tabs data-admin-tabs-url-mode="href" aria-label="' . e(t('admin.settings.sections_aria', 'Settings sections')) . '">';
    echo '<div class="admin-tab-list" role="tablist">';
    foreach ($sections as $sectionId => $section) {
        $panelId = admin_settings_section_id($sectionId);
        $isActive = $sectionId === $activeSection;
        echo '<a class="admin-tab' . ($isActive ? ' is-active' : '') . '" id="' . e($panelId . '-control') . '" href="' . e(admin_settings_url($sectionId)) . '" role="tab" aria-controls="' . e($panelId) . '" aria-selected="' . ($isActive ? 'true' : 'false') . '" tabindex="' . ($isActive ? '0' : '-1') . '" data-admin-tab-target="' . e($panelId) . '">';
        echo '<span>' . e(t((string) $section['label_key'], (string) $section['label'])) . '</span></a>';
    }
    echo '</div></nav>';

    foreach ($sections as $sectionId => $section) {
        $panelId = admin_settings_section_id($sectionId);
        $isActive = $sectionId === $activeSection;
        $entries = array_filter($registry, static fn (array $entry): bool => ($entry['group'] ?? '') === $sectionId && empty($entry['discovery_only']));
        echo '<section class="panel admin-tab-panel admin-settings-section' . ($isActive ? ' is-active' : '') . '" id="' . e($panelId) . '" role="tabpanel" aria-labelledby="' . e($panelId . '-control') . '" data-admin-tab-panel' . ($isActive ? '' : ' hidden') . '>';
        echo '<div class="admin-tab-intro"><div><h2>' . e(t((string) $section['label_key'], (string) $section['label'])) . '</h2><p>' . e(t((string) $section['description_key'], (string) $section['description'])) . '</p></div></div>';
        view_render_admin_settings_section($sectionId, $entries, $errors, $submittedValues);
        echo '</section>';
    }

    render_footer();
}

/**
 * Render the client-side Settings spotlight and its complete searchable index.
 *
 * @param array<string,array<string,string>> $sections Section taxonomy.
 * @param array<string,array<string,mixed>> $registry Settings registry.
 */
function view_render_admin_settings_search(array $sections, array $registry): void
{
    echo '<section class="panel admin-settings-search" data-admin-settings-search data-results-label="' . e(t('admin.settings.search_matches', 'matching settings')) . '" data-empty-label="' . e(t('admin.settings.search_empty', 'No matching settings found.')) . '">';
    echo '<div class="admin-settings-search-shell">';
    echo '<span class="admin-settings-search-icon" aria-hidden="true">&#128269;</span>';
    echo '<input class="admin-settings-search-input" type="search" role="combobox" autocomplete="off" spellcheck="false" placeholder="' . e(t('admin.settings.search_placeholder', 'Search settings and tools...')) . '" aria-label="' . e(t('admin.settings.search_label', 'Search settings')) . '" aria-autocomplete="list" aria-controls="admin-settings-search-results" aria-expanded="false" data-admin-settings-search-input>';
    echo '<button type="button" class="admin-settings-search-clear" aria-label="' . e(t('admin.settings.search_clear', 'Clear settings search')) . '" data-admin-settings-search-clear hidden>&times;</button>';
    echo '</div>';
    echo '<div class="admin-settings-search-results" id="admin-settings-search-results" role="listbox" aria-label="' . e(t('admin.settings.search_results', 'Matching settings')) . '" data-admin-settings-search-results hidden>';
    echo '<p class="admin-settings-search-status" role="status" aria-live="polite" data-admin-settings-search-status></p>';
    echo '<div class="admin-settings-search-list">';
    foreach ($registry as $id => $entry) {
        $sectionId = admin_settings_section_normalize($entry['group'] ?? 'general');
        $section = $sections[$sectionId] ?? [];
        $label = view_admin_settings_entry_label($entry);
        $description = view_admin_settings_entry_description($entry);
        $sectionLabel = t((string) ($section['label_key'] ?? ''), (string) ($section['label'] ?? $sectionId));
        $targetId = 'admin-setting-result-' . preg_replace('/[^a-z0-9_-]/i', '-', (string) $id);
        $keywords = implode(' ', [(string) $id, str_replace('_', ' ', (string) $id), $label, $description, $sectionLabel, (string) ($entry['sensitivity'] ?? '')]);
        echo '<a class="admin-settings-search-result" id="admin-settings-search-option-' . e((string) $id) . '" href="' . e(admin_settings_url($sectionId)) . '" role="option" aria-selected="false" data-admin-settings-search-result data-search-text="' . e($keywords) . '" data-search-label="' . e($label) . '" data-search-section="' . e($sectionId) . '" data-search-target="' . e($targetId) . '" hidden>';
        echo '<span class="admin-settings-search-result-section">' . e($sectionLabel) . '</span>';
        echo '<span class="admin-settings-search-result-copy"><strong>' . e($label) . '</strong><small>' . e($description) . '</small></span>';
        echo '<span class="admin-settings-search-result-arrow" aria-hidden="true">&rarr;</span></a>';
    }
    echo '</div></div></section>';
}

/**
 * Render the cross-section overview before the focused section controls.
 *
 * @param array<string,array<string,string>> $sections Section taxonomy.
 * @param array<string,array<string,mixed>> $registry Settings registry.
 * @param string $activeSection Selected section.
 */
function view_render_admin_settings_overview(array $sections, array $registry, string $activeSection): void
{
    echo '<section class="panel"><h2>' . e(t('admin.settings.overview_title', 'Settings overview')) . '</h2>';
    echo '<p>' . e(t('admin.settings.overview_hint', 'Each category shows representative current values and links to the authoritative specialized page when more context is required.')) . '</p>';
    echo '<div class="admin-maintenance-grid">';
    foreach ($sections as $sectionId => $section) {
        $entries = array_values(array_filter($registry, static fn (array $entry): bool => ($entry['group'] ?? '') === $sectionId));
        echo '<article class="admin-maintenance-card' . ($sectionId === $activeSection ? ' is-active' : '') . '">';
        echo '<strong>' . e(t((string) $section['label_key'], (string) $section['label'])) . '</strong>';
        echo '<span>' . e(t((string) $section['description_key'], (string) $section['description'])) . '</span>';
        $summaries = array_slice($entries, 0, 3);
        if ($summaries !== []) {
            echo '<dl class="admin-settings-overview-values">';
            foreach ($summaries as $entry) {
                echo '<div><dt>' . e(view_admin_settings_entry_label($entry)) . '</dt><dd>' . e(view_admin_settings_display_value($entry)) . '</dd></div>';
            }
            echo '</dl>';
        }
        echo '<div class="admin-settings-overview-actions">';
        echo '<a class="button secondary" href="' . e(admin_settings_url($sectionId)) . '">' . e(t('admin.settings.configure_section', 'Configure')) . '</a>';
        $specializedEntry = null;
        foreach ($entries as $entry) {
            if ((string) ($entry['specialized_url'] ?? '') !== '') {
                $specializedEntry = $entry;
                break;
            }
        }
        if (is_array($specializedEntry)) {
            echo '<a class="button secondary" href="' . e((string) $specializedEntry['specialized_url']) . '">' . e(t('admin.settings.open_specialized', 'Open specialized page')) . '</a>';
        }
        echo '</div>';
        echo '</article>';
    }
    echo '</div></section>';
}

/**
 * Render one Settings section with a small scoped edit form and summary-only rows.
 *
 * @param string $sectionId Section identifier.
 * @param array<string,array<string,mixed>> $entries Registry entries.
 * @param array<string,mixed> $errors Validation errors.
 * @param array<string,mixed> $submittedValues Submitted values retained after validation failure.
 */
function view_render_admin_settings_section(string $sectionId, array $entries, array $errors, array $submittedValues): void
{
    $editable = array_filter($entries, static fn (array $entry): bool => !empty($entry['central_editable']));
    $summaryOnly = array_filter($entries, static fn (array $entry): bool => empty($entry['central_editable']));

    if ($editable !== []) {
        echo '<form method="post" action="' . e(admin_settings_url($sectionId)) . '" class="form-grid admin-settings-group-form">' . csrf_field();
        echo '<input type="hidden" name="return_section" value="' . e($sectionId) . '">';
        echo '<fieldset class="form-grid"><legend>' . e(t('admin.settings.quick_settings', 'Central settings')) . '</legend>';
        echo '<p class="muted">' . e(t('admin.settings.quick_settings_hint', 'Only settings with a shared canonical normalizer and safe service-level save path are editable here.')) . '</p>';
        foreach ($editable as $id => $entry) {
            if ($id === 'public_language_selector_enabled') {
                view_render_public_language_selector_settings_panel([
                    'id_prefix' => 'admin-setting-result-public_language_selector_enabled',
                    'languages_target_id' => 'admin-setting-result-public_language_selector_languages',
                    'admin_setting_target' => true,
                    'enabled_name' => 'settings[public_language_selector_enabled]',
                    'languages_name' => 'settings[public_language_selector_languages][]',
                    'design_name' => 'settings[public_language_selector_design]',
                    'detailed_design' => false,
                    'enabled' => array_key_exists('public_language_selector_enabled', $submittedValues)
                        ? !empty($submittedValues['public_language_selector_enabled'])
                        : translation_public_language_selector_enabled(),
                    'languages' => array_key_exists('public_language_selector_languages', $submittedValues)
                        ? (array) $submittedValues['public_language_selector_languages']
                        : translation_public_language_selector_languages(),
                    'design' => array_key_exists('public_language_selector_design', $submittedValues)
                        ? (array) $submittedValues['public_language_selector_design']
                        : translation_public_language_selector_design(),
                    'errors' => [
                        'enabled' => $errors['public_language_selector_enabled'] ?? '',
                        'languages' => $errors['public_language_selector_languages'] ?? '',
                    ],
                ]);
                continue;
            }
            if ($id === 'public_language_selector_languages' || $id === 'public_language_selector_design') {
                continue;
            }
            view_render_admin_settings_input((string) $id, $entry, $errors, $submittedValues);
        }
        echo '<div class="bulk-row"><button type="submit">' . e(t('admin.settings.save_section', 'Save this section')) . '</button></div>';
        echo '</fieldset></form>';
    }

    if ($summaryOnly !== []) {
        echo '<div class="admin-maintenance-grid admin-settings-summary-grid">';
        foreach ($summaryOnly as $entry) {
            view_render_admin_settings_summary_card($entry);
        }
        echo '</div>';
    }
}

/**
 * Render one centrally editable input with visible label, help text, and field error.
 *
 * @param string $id Stable setting identifier.
 * @param array<string,mixed> $entry Registry entry.
 * @param array<string,mixed> $errors Validation errors.
 * @param array<string,mixed> $submittedValues Submitted values.
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

    echo '<div class="admin-settings-field' . ($error !== '' ? ' has-error' : '') . '" id="admin-setting-result-' . e($id) . '" data-admin-setting-target tabindex="-1">';
    if ($type === 'checkbox') {
        $checked = array_key_exists($id, $submittedValues) ? !empty($submittedValues[$id]) : ((string) ($entry['current'] ?? '0') === '1');
        echo '<label class="checkbox-label" for="' . e($inputId) . '"><input id="' . e($inputId) . '" type="checkbox" name="settings[' . e($id) . ']" value="1"' . ($checked ? ' checked' : '') . ' aria-describedby="' . e($describedBy) . '"' . ($error !== '' ? ' aria-invalid="true"' : '') . '> ' . e(view_admin_settings_entry_label($entry)) . '</label>';
    } elseif ($type === 'select') {
        echo '<label for="' . e($inputId) . '">' . e(view_admin_settings_entry_label($entry)) . '</label>';
        echo '<select id="' . e($inputId) . '" name="settings[' . e($id) . ']" aria-describedby="' . e($describedBy) . '"' . ($error !== '' ? ' aria-invalid="true"' : '') . '>';
        $allowed = is_array($entry['validation']['allowed'] ?? null) ? $entry['validation']['allowed'] : [];
        foreach ($allowed as $option) {
            $option = (string) $option;
            echo '<option value="' . e($option) . '"' . ((string) $current === $option ? ' selected' : '') . '>' . e(view_admin_settings_option_label($id, $option)) . '</option>';
        }
        echo '</select>';
    } else {
        $maxLength = (int) ($entry['validation']['max_length'] ?? 0);
        echo '<label for="' . e($inputId) . '">' . e(view_admin_settings_entry_label($entry)) . '</label>';
        echo '<input id="' . e($inputId) . '" type="text" name="settings[' . e($id) . ']" value="' . e((string) $current) . '"' . ($maxLength > 0 ? ' maxlength="' . $maxLength . '"' : '') . ' aria-describedby="' . e($describedBy) . '"' . ($error !== '' ? ' aria-invalid="true"' : '') . '>';
    }
    echo '<span class="muted" id="' . e($helpId) . '">' . e(view_admin_settings_entry_description($entry)) . '</span>';
    if ($error !== '') {
        echo '<span class="error" id="' . e($errorId) . '">' . e($error) . '</span>';
    }
    view_render_admin_settings_source($entry);
    if ((string) ($entry['specialized_url'] ?? '') !== '') {
        echo '<a class="button secondary" href="' . e((string) $entry['specialized_url']) . '">' . e(t('admin.settings.open_specialized', 'Open specialized page')) . '</a>';
    }
    echo '</div>';
}

/**
 * Render one summary-only setting card.
 *
 * @param array<string,mixed> $entry Registry entry.
 */
function view_render_admin_settings_summary_card(array $entry): void
{
    $id = (string) ($entry['id'] ?? '');
    echo '<article class="admin-maintenance-card admin-settings-card" id="admin-setting-result-' . e($id) . '" data-admin-setting-target tabindex="-1">';
    echo '<strong>' . e(view_admin_settings_entry_label($entry)) . '</strong>';
    echo '<span>' . e(view_admin_settings_entry_description($entry)) . '</span>';
    echo '<dl><div><dt>' . e(t('admin.settings.current_value', 'Current value')) . '</dt><dd>' . e(view_admin_settings_display_value($entry)) . '</dd></div></dl>';
    view_render_admin_settings_source($entry);
    $url = (string) ($entry['specialized_url'] ?? '');
    if ($url !== '') {
        echo '<a class="button secondary" href="' . e($url) . '">' . e(t('admin.settings.open_specialized', 'Open specialized page')) . '</a>';
    }
    echo '</article>';
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
    if (in_array((string) ($entry['input_type'] ?? ''), ['checkbox'], true) || in_array((string) ($entry['id'] ?? ''), ['pagination_enabled', 'admin_upload_auto_rename_enabled', 'browser_upload_enabled', 'telemetry_enabled', 'telemetry_public_usage_enabled', 'seo_request_guard_enabled', 'seo_request_guard_logging_enabled', 'site_maintenance_enabled'], true)) {
        return admin_settings_bool_label((string) $value === '1');
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
    if ($id === 'public_thumbnail_rendering_mode') {
        return $value === 'progressive'
            ? t('admin.settings.thumbnail.progressive', 'Progressive (Default)')
            : t('admin.settings.thumbnail.responsive', 'Responsive (Legacy)');
    }
    if ($id === 'public_language') {
        // $languageStrings stores pack metadata so the central selector matches the Theme language selector.
        $languageStrings = translation_load_language($value);
        // $languageName stores the native human-readable name declared by the language pack.
        $languageName = trim((string) ($languageStrings['_language_name'] ?? ''));
        return $languageName !== '' ? $languageName . ' (' . $value . ')' : strtoupper($value);
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
