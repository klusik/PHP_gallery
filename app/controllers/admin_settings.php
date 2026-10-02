<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_settings.php
 * Module Type: Controller
 *
 * Purpose:
 *   Renders and processes the centralized Admin Settings hub.
 *
 * Responsibilities:
 *   - Require administrator authentication and CSRF protection for writes
 *   - Preserve stable section deep links and return the administrator to the edited section
 *   - Delegate normalization and persistence to the Settings registry and existing services
 *   - Reject unknown or specialized-only settings instead of writing arbitrary app_settings keys
 *   - Keep sensitive and destructive workflows on their dedicated Admin pages
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
 *   - Complex Theme, Upload, Telemetry, Account, and maintenance mutations stay specialized.
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use InvalidArgumentException;
use Throwable;
use function Gallery\Core\flash_message;
use function Gallery\Core\current_user;
use function Gallery\Core\redirect_to;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\verify_csrf;
use function Gallery\Core\url_for;
use function Gallery\Core\admin_wants_json;
use function Gallery\Core\admin_mutation_descriptor;
use function Gallery\Core\admin_mutation_success_envelope;
use function Gallery\Core\admin_mutation_error_envelope;
use function Gallery\Core\apply_cookie_intents;
use function Gallery\Services\admin_log_event;
use function Gallery\Services\admin_settings_normalize_editable_value;
use function Gallery\Services\admin_settings_registry;
use function Gallery\Services\admin_settings_save_editable_value;
use function Gallery\Services\admin_settings_section_normalize;
use function Gallery\Services\admin_settings_sections;
use function Gallery\Services\admin_settings_section_id;
use function Gallery\Services\translation_language_presentation;
use function Gallery\Services\translation_public_language_selector_view_data;
use function Gallery\Services\translation_admin_language_cookie_intents;
use function Gallery\Services\admin_settings_url;
use function Gallery\Services\t;
use function Gallery\Views\view_render_admin_settings_page;
use function Gallery\Views\view_render_admin_settings_section;

/**
 * Render and process the centralized Admin Settings hub.
 * @return void
 */
function cms_admin_settings(): void
{
    require_admin();
    $section = admin_settings_section_normalize($_GET['section'] ?? $_POST['return_section'] ?? 'general');
    $errors = [];
    $submittedValues = [];
    $saved = false;
    $newAddress = '';
    $languageChanged = false;
    $isJsonPost = request_method() === 'POST' && admin_wants_json();

    if (request_method() === 'POST') {
        verify_csrf();
        $submittedValues = is_array($_POST['settings'] ?? null) ? $_POST['settings'] : [];
        $registry = admin_settings_registry(true);
        $editableEntries = [];

        foreach ($registry as $id => $entry) {
            $belongsToSection = ($entry['group'] ?? '') === $section
                || ($section === 'general' && ($entry['group'] ?? '') === 'site');
            if ($belongsToSection && !empty($entry['central_editable'])) {
                if ($id === 'admin_language' && !array_key_exists($id, $submittedValues)) {
                    continue;
                }
                // An unchanged address must not rewrite config.php or block ordinary settings saves.
                if ($section === 'general' && $id === 'base_url'
                    && (!array_key_exists($id, $submittedValues)
                        || trim((string) $submittedValues[$id]) === (string) $entry['current'])) {
                    continue;
                }
                $editableEntries[$id] = $entry;
            }
        }

        $normalized = [];
        foreach ($editableEntries as $id => $entry) {
            $inputType = (string) ($entry['input_type'] ?? '');
            if ($inputType === 'checkbox' && !array_key_exists($id, $submittedValues)) {
                $submittedValues[$id] = '';
            }
            $rawValue = $inputType === 'checkbox'
                ? $submittedValues[$id]
                : ($submittedValues[$id] ?? null);

            try {
                $normalized[$id] = admin_settings_normalize_editable_value($entry, $rawValue);
            } catch (InvalidArgumentException $exception) {
                $errors[$id] = $exception->getMessage();
            }
        }

        if ($editableEntries === []) {
            $errors['_page'][] = t('admin.settings.error.no_editable_settings', 'This section contains summary-only settings. Open the specialized page to make changes.');
        }

        if ($errors === []) {
            try {
                // Write the installation address last, after all ordinary settings succeed.
                $saveValues = $normalized;
                if (array_key_exists('base_url', $saveValues)) {
                    unset($saveValues['base_url']);
                    $saveValues['base_url'] = $normalized['base_url'];
                }
                foreach ($saveValues as $id => $value) {
                    admin_settings_save_editable_value($id, $value);
                }
                if (isset($normalized['admin_language']) && $normalized['admin_language'] !== $registry['admin_language']['current']) {
                    apply_cookie_intents(translation_admin_language_cookie_intents((string) $normalized['admin_language']));
                    $languageChanged = true;
                }
                if (isset($normalized['base_url'])) {
                    $newAddress = rtrim((string) $normalized['base_url'], '/') . '/index.php?page=admin_settings&section=general';
                }
                admin_log_event('info', 'settings.central_updated', 'Admin updated centralized settings.', [
                    'section' => $section,
                    'setting_ids' => array_keys($normalized),
                ], [
                    'category' => 'settings',
                    'severity' => 'notice',
                    'route_name' => 'admin_settings',
                ]);
                $saved = true;
                $submittedValues = [];
            } catch (Throwable $exception) {
                $errors['_page'][] = t('admin.settings.workspace.save_failed', 'Settings could not be saved. Your changes are still here; please try again.');
                admin_log_event('error', 'settings.central_update_failed', 'Centralized settings update failed.', [
                    'section' => $section,
                    'setting_ids' => array_keys($normalized),
                    'exception_type' => get_class($exception),
                ], [
                    'category' => 'settings',
                    'severity' => 'error',
                    'route_name' => 'admin_settings',
                ]);
            }
        }
    }

    if ($saved && !$isJsonPost) {
        flash_message('admin_notice', t('admin.settings.notice.saved', 'Settings saved.'));
        if ($newAddress !== '') {
            redirect_to($newAddress);
        }
        redirect_to(admin_settings_url($section, null, false));
    }

    // Keep the historical site route as a fallback while presenting its field in General.
    $activeSection = $section === 'site' ? 'general' : $section;
    $notice = $saved ? t('admin.settings.notice.saved', 'Settings saved.') : (string) flash_message('admin_notice');
    $sections = admin_settings_sections();
    unset($sections['site']);
    $sections['advanced']['label_key'] = 'admin.settings.workspace.operations';
    $sections['advanced']['label'] = 'Operations and integrations';
    foreach ($sections as $sectionId => $definition) {
        $sections[$sectionId]['panel_id'] = admin_settings_section_id($sectionId);
        $sections[$sectionId]['url'] = admin_settings_url($sectionId);
        $sections[$sectionId]['tab_url'] = admin_settings_url($sectionId, null, false);
    }
    $registry = admin_settings_registry(true);
    $entryOrder = array_flip(['base_url', 'site_name', 'admin_language', 'public_language', 'public_language_selector_enabled', 'public_language_selector_languages', 'public_language_selector_design', 'url_rewrite_enabled', 'public_home_search_enabled']);
    uksort($registry, /** Present paired General controls before other registry entries. @param string $left First identifier. @param string $right Second identifier. @return int Presentation order. */ static fn (string $left, string $right): int => ($entryOrder[$left] ?? 100) <=> ($entryOrder[$right] ?? 100));
    $languagePresentations = translation_language_presentation();
    foreach ($registry as $id => $entry) {
        $group = admin_settings_section_normalize($entry['group'] ?? 'general');
        $group = $group === 'site' ? 'general' : $group;
        $registry[$id]['view_group'] = $group;
        $registry[$id]['view_edit_group'] = match ($id) {
            'base_url', 'site_name' => 'website',
            'admin_language', 'public_language', 'public_language_selector_enabled', 'public_language_selector_languages', 'public_language_selector_design' => 'languages',
            'url_rewrite_enabled', 'public_home_search_enabled' => 'navigation',
            default => 'preferences',
        };
        $registry[$id]['view_section_url'] = (string) ($sections[$group]['url'] ?? admin_settings_url('general'));
        $registry[$id]['view_search_url'] = !empty($entry['discovery_only'])
            ? (string) ($entry['specialized_url'] ?? '') : $registry[$id]['view_section_url'];
        if ($id === 'base_url') {
            $registry[$id]['view_description'] = t('admin.settings.workspace.address_hint', 'Public address of this gallery, including its installation folder when needed.');
        }
        if ($saved && $id === 'base_url' && isset($normalized['base_url'])) {
            $registry[$id]['current'] = $normalized['base_url'];
        }
        if (in_array($id, ['admin_language', 'public_language'], true) && is_array($entry['validation']['allowed'] ?? null)) {
            $optionLabels = [];
            foreach ($entry['validation']['allowed'] as $language) {
                $code = (string) $language;
                $name = trim((string) ($languagePresentations[$code]['name'] ?? ''));
                $optionLabels[$code] = $name !== '' ? $name . ' (' . $code . ')' : strtoupper($code);
            }
            $registry[$id]['view_option_labels'] = $optionLabels;
        }
    }
    $model = [
        'active_section' => $activeSection,
        'sections' => $sections,
        'registry' => $registry,
        'errors' => $errors,
        'submitted_values' => $submittedValues,
        'notice' => $notice,
        'language_selector' => translation_public_language_selector_view_data(),
        'setup_wizard' => [
            'url' => url_for('admin_setup_wizard'),
            'resume' => is_array($_SESSION['admin_setup_wizard_draft'] ?? null)
                && (int) ($_SESSION['admin_setup_wizard_draft']['owner'] ?? 0) === (int) (current_user()['id'] ?? 0),
        ],
    ];
    if ($isJsonPost) {
        $fallback = ['redirect_url' => $newAddress !== '' ? $newAddress : admin_settings_url($activeSection)];
        $mutation = admin_mutation_descriptor('settings.update', 'settings', 'update');
        $payload = $saved
            ? admin_mutation_success_envelope($notice, $mutation, null, [], $fallback)
            : admin_mutation_error_envelope(t('admin.settings.workspace.check_fields', 'Check the highlighted settings.'), 'validation_failed', $mutation, $fallback);
        $payload['section'] = $activeSection;
        $payload['errors'] = $errors;
        $payload['address_url'] = $newAddress;
        $payload['language_url'] = $languageChanged ? admin_settings_url($activeSection) : '';
        if ($saved) {
            $entries = array_filter($registry, /** Return only the saved category's visible entries. @param array<string,mixed> $entry Prepared registry entry. @return bool Whether the entry belongs to the fragment. */ static fn (array $entry): bool => ($entry['view_group'] ?? '') === $activeSection && empty($entry['discovery_only']));
            ob_start();
            view_render_admin_settings_section($activeSection, $entries, [], [], $model);
            $payload['html'] = (string) ob_get_clean();
        }
        http_response_code($saved ? 200 : 422);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }
    view_render_admin_settings_page($model);
}
