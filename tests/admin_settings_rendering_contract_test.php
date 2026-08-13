<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_settings_rendering_contract_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies rendering, redaction, and accessibility contracts for centralized Settings.
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

$view = file_get_contents(__DIR__ . '/../app/views/admin_settings.php');
$registry = file_get_contents(__DIR__ . '/../app/services/admin_settings_registry.php');
$search = file_get_contents(__DIR__ . '/../public/assets/gallery-modules/admin-settings-search.js');
$gallery = file_get_contents(__DIR__ . '/../public/assets/gallery.js');
$styles = file_get_contents(__DIR__ . '/../public/assets/styles/admin.css');
$languageSettings = file_get_contents(__DIR__ . '/../app/views/admin_language_settings.php');
if (!is_string($view) || !is_string($registry) || !is_string($search) || !is_string($gallery) || !is_string($styles) || !is_string($languageSettings)) {
    throw new RuntimeException('Unable to read Admin Settings source files.');
}

foreach (['public_language_selector_enabled', 'public_language_selector_languages'] as $languageSettingId) {
    if (!str_contains($registry, "'{$languageSettingId}' => admin_settings_entry") || !str_contains($languageSettings, $languageSettingId)) {
        throw new RuntimeException('Viewer language setting is missing from the registry or shared panel: ' . $languageSettingId);
    }
}

foreach (["only for public viewers", "saved only in that viewer\\'s browser", "does not change the site default"] as $viewerLanguageExplanation) {
    if (!str_contains($languageSettings, $viewerLanguageExplanation)) {
        throw new RuntimeException('Viewer-language browser-local explanation is missing: ' . $viewerLanguageExplanation);
    }
}
if (!str_contains($registry, "stored only in that viewer\\'s browser") || !str_contains($registry, 'site-wide language settings are unchanged')) {
    throw new RuntimeException('Settings search does not explain the browser-local viewer-language scope.');
}

foreach ([
    'data-admin-settings-search',
    'data-admin-settings-search-input',
    'role="combobox"',
    'aria-autocomplete="list"',
    'role="listbox"',
    'role="option"',
    'aria-live="polite"',
    'data-search-section=',
    'data-search-target=',
    'data-admin-setting-target',
    "empty(\$entry['discovery_only'])",
] as $needle) {
    if (!str_contains($view, $needle)) {
        throw new RuntimeException('Settings search rendering contract missing: ' . $needle);
    }
}

foreach (['normalizeSettingsSearchText', "event.key === 'ArrowDown'", "event.key === 'Enter'", "event.key === 'Escape'", 'setupAdminSettingsSearch'] as $needle) {
    if (!str_contains($search, $needle)) {
        throw new RuntimeException('Settings search interaction contract missing: ' . $needle);
    }
}
if (!str_contains($gallery, 'setupAdminSettingsSearch();')) {
    throw new RuntimeException('Settings search is not initialized by the browser entrypoint.');
}
if (!str_contains($styles, '.admin-settings-search-result[hidden] { display: none; }')) {
    throw new RuntimeException('Settings search CSS must keep non-matching result rows hidden.');
}

foreach ([
    'role="tab"',
    'aria-controls=',
    'aria-selected=',
    'role="tabpanel"',
    'data-admin-tab-panel',
    "? '' : ' hidden'",
    '<fieldset class="form-grid">',
    '<legend>',
    'aria-describedby=',
    'aria-invalid="true"',
    'role="alert"',
    'admin-settings-error-summary-title',
] as $needle) {
    if (!str_contains($view, $needle)) {
        throw new RuntimeException('Settings rendering accessibility contract missing: ' . $needle);
    }
}

if (!str_contains($registry, "admin_settings_sensitive_status('password_reset_smtp_password')") || !str_contains($registry, "admin_settings_sensitive_status('site_maintenance_token')")) {
    throw new RuntimeException('Sensitive values are not redacted before rendering.');
}
if (str_contains($view, 'password_reset_smtp_password') || str_contains($view, 'site_maintenance_token')) {
    throw new RuntimeException('Settings view references raw sensitive setting keys directly.');
}

foreach (['general', 'appearance', 'content', 'media', 'uploads', 'privacy', 'advanced'] as $section) {
    if (!str_contains($registry, "'{$section}' => [")) {
        throw new RuntimeException('Settings group heading contract missing for: ' . $section);
    }
}

if (!str_contains($view, "href=\"' . e(admin_settings_url(\$sectionId))")) {
    throw new RuntimeException('Active Settings section is not represented by stable URL links.');
}

echo "Admin Settings rendering contract tests passed.\n";
