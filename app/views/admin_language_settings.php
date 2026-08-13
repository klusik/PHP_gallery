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
use function Gallery\Services\t;
use function Gallery\Services\translation_language_presentation;
use function Gallery\Services\translation_public_language_selector_enabled;
use function Gallery\Services\translation_public_language_selector_languages;
use function Gallery\Services\translation_supported_languages;

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

    echo '<section class="admin-language-selector-settings" id="' . e($idPrefix) . '" data-public-language-selector-settings' . (!empty($model['admin_setting_target']) ? ' data-admin-setting-target tabindex="-1"' : '') . '>';
    echo '<div class="admin-language-selector-settings-copy"><strong>' . e(t('admin.theme.language.viewer_selector_title', 'Viewer language selector')) . '</strong>';
    echo '<p class="muted">' . e(t('admin.theme.language.viewer_selector_hint', 'Show the language buttons on public pages and choose which maintained languages visitors may select.')) . '</p></div>';
    if ($markerName !== '') {
        echo '<input type="hidden" name="' . e($markerName) . '" value="1">';
    }

    $enabledId = $idPrefix . '-enabled';
    echo '<label class="checkbox-label admin-language-selector-enabled" for="' . e($enabledId) . '"><input id="' . e($enabledId) . '" type="checkbox" name="' . e($enabledName) . '" value="1"' . ($enabled ? ' checked' : '') . ($enabledError !== '' ? ' aria-invalid="true"' : '') . '> ' . e(t('admin.theme.language.viewer_selector_enabled', 'Allow visitors to choose their interface language')) . '</label>';
    if ($enabledError !== '') {
        echo '<span class="error">' . e($enabledError) . '</span>';
    }

    echo '<fieldset class="admin-language-selector-language-list"' . ($languagesTargetId !== '' ? ' id="' . e($languagesTargetId) . '" data-admin-setting-target tabindex="-1"' : '') . ($languagesError !== '' ? ' aria-invalid="true"' : '') . '><legend>' . e(t('admin.theme.language.viewer_languages_legend', 'Languages available to viewers')) . '</legend>';
    echo '<p class="muted">' . e(t('admin.theme.language.viewer_languages_hint', 'Select at least one language. These choices affect only the public viewer selector; all maintained languages remain available to administrators and translation tools.')) . '</p>';
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
    echo '</fieldset></section>';
}
