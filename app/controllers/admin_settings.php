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
use function Gallery\Core\redirect_to;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\admin_log_event;
use function Gallery\Services\admin_settings_normalize_editable_value;
use function Gallery\Services\admin_settings_registry;
use function Gallery\Services\admin_settings_save_editable_value;
use function Gallery\Services\admin_settings_section_normalize;
use function Gallery\Services\admin_settings_sections;
use function Gallery\Services\admin_settings_section_id;
use function Gallery\Services\translation_language_presentation;
use function Gallery\Services\translation_public_language_selector_view_data;
use function Gallery\Services\admin_settings_url;
use function Gallery\Services\t;
use function Gallery\Views\view_render_admin_settings_page;

/**
 * Render and process the centralized Admin Settings hub.
 */
function cms_admin_settings(): void
{
    require_admin();
    $section = admin_settings_section_normalize($_GET['section'] ?? $_POST['return_section'] ?? 'general');
    $errors = [];
    $submittedValues = [];

    if (request_method() === 'POST') {
        verify_csrf();
        $submittedValues = is_array($_POST['settings'] ?? null) ? $_POST['settings'] : [];
        $registry = admin_settings_registry();
        $editableEntries = [];

        foreach ($registry as $id => $entry) {
            if (($entry['group'] ?? '') === $section && !empty($entry['central_editable'])) {
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
                foreach ($normalized as $id => $value) {
                    admin_settings_save_editable_value($id, $value);
                }
                admin_log_event('info', 'settings.central_updated', 'Admin updated centralized settings.', [
                    'section' => $section,
                    'setting_ids' => array_keys($normalized),
                ], [
                    'category' => 'settings',
                    'severity' => 'notice',
                    'route_name' => 'admin_settings',
                ]);
                flash_message('admin_notice', t('admin.settings.notice.saved', 'Settings saved.'));
                redirect_to(admin_settings_url($section));
            } catch (Throwable $exception) {
                $errors['_page'][] = t('admin.settings.error.save_failed', 'Settings could not be saved: {error}', ['error' => $exception->getMessage()]);
                admin_log_event('error', 'settings.central_update_failed', 'Centralized settings update failed.', [
                    'section' => $section,
                    'setting_ids' => array_keys($normalized),
                    'exception' => $exception->getMessage(),
                ], [
                    'category' => 'settings',
                    'severity' => 'error',
                    'route_name' => 'admin_settings',
                ]);
            }
        }
    }

    $notice = (string) flash_message('admin_notice');
    $sections = admin_settings_sections();
    foreach ($sections as $sectionId => $definition) {
        $sections[$sectionId]['panel_id'] = admin_settings_section_id($sectionId);
        $sections[$sectionId]['url'] = admin_settings_url($sectionId);
    }
    $registry = admin_settings_registry();
    $languagePresentations = translation_language_presentation();
    foreach ($registry as $id => $entry) {
        $group = admin_settings_section_normalize($entry['group'] ?? 'general');
        $registry[$id]['view_group'] = $group;
        $registry[$id]['view_section_url'] = (string) ($sections[$group]['url'] ?? admin_settings_url('general'));
        if ($id === 'public_language' && is_array($entry['allowed'] ?? null)) {
            $optionLabels = [];
            foreach ($entry['allowed'] as $language) {
                $code = (string) $language;
                $name = trim((string) ($languagePresentations[$code]['name'] ?? ''));
                $optionLabels[$code] = $name !== '' ? $name . ' (' . $code . ')' : strtoupper($code);
            }
            $registry[$id]['view_option_labels'] = $optionLabels;
        }
    }
    view_render_admin_settings_page([
        'active_section' => $section,
        'sections' => $sections,
        'registry' => $registry,
        'errors' => $errors,
        'submitted_values' => $submittedValues,
        'notice' => $notice,
        'language_selector' => translation_public_language_selector_view_data(),
    ]);
}
