<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_settings_navigation_contract_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Locks the centralized Admin Settings route and deep-link navigation contract.
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

$files = [
    'bootstrap' => file_get_contents(__DIR__ . '/../app/bootstrap.php'),
    'chrome' => file_get_contents(__DIR__ . '/../app/views/admin_chrome.php'),
    'registry' => file_get_contents(__DIR__ . '/../app/services/admin_settings_registry.php'),
    'theme' => file_get_contents(__DIR__ . '/../app/controllers/admin_theme.php'),
    'tags' => file_get_contents(__DIR__ . '/../app/controllers/admin_tags.php'),
    'uploads' => file_get_contents(__DIR__ . '/../app/views/admin_upload_settings.php'),
    'telemetry' => file_get_contents(__DIR__ . '/../app/controllers/admin_telemetry.php'),
    'settings_view' => file_get_contents(__DIR__ . '/../app/views/admin_settings.php'),
    'tabs_js' => file_get_contents(__DIR__ . '/../public/assets/gallery-modules/admin-tabs.js'),
    'account' => file_get_contents(__DIR__ . '/../app/controllers/admin_auth.php'),
    'dashboard' => file_get_contents(__DIR__ . '/../app/views/admin_dashboard.php'),
    'dashboard_sections' => file_get_contents(__DIR__ . '/../app/views/admin_dashboard_sections.php'),
];
foreach ($files as $name => $source) {
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read source: ' . $name);
    }
}

$expectations = [
    ['bootstrap', "'admin_settings' => '\\\\Gallery\\\\Controllers\\\\cms_admin_settings'", 'central route registration'],
    ['chrome', "'page' => 'admin_settings'", 'Admin navigation Settings entry'],
    ['theme', "admin_settings_url('appearance')", 'Theme to central Settings link'],
    ['tags', "admin_settings_url('content')", 'Tags to central Settings link'],
    ['uploads', "admin_settings_url('uploads')", 'Upload settings to central Settings link'],
    ['telemetry', "admin_settings_url('privacy')", 'Telemetry to central Settings link'],
    ['registry', 'function admin_settings_specialized_url(', 'central specialized URL helper'],
    ['registry', "'site_name' => admin_settings_entry('site_name', 'general'", 'central Theme-owned setting registry entry'],
    ['registry', "'appearance_subtab' => 'admin-theme-appearance-subtab-gallery-tags'", 'Gallery tags deep-link query'],
    ['registry', "'admin-theme-tab-appearance'", 'Gallery tags Theme hash'],
    ['registry', "'admin_tags'", 'central Settings to Tags route'],
    ['registry', "return 'settings-' . admin_settings_section_normalize(\$section);", 'stable section DOM ids'],
    ['settings_view', "admin_settings_url(\$sectionId)", 'section query/hash links'],
    ['settings_view', 'data-admin-tabs-url-mode="href"', 'Settings href-history tab mode'],
    ['tabs_js', "urlMode === 'href'", 'opt-in href-history support in reusable Admin tabs'],
    ['account', "admin_settings_url('advanced')", 'Account to central Advanced link'],
    ['dashboard', "admin_settings_url('media')", 'Dashboard EXIF/GPS to central Media link'],
    ['dashboard_sections', "admin_settings_url('general')", 'Dashboard public settings to central General link'],
    ['dashboard_sections', "admin_settings_url('privacy')", 'Dashboard privacy/maintenance to central Privacy link'],
];
foreach ($expectations as [$file, $needle, $label]) {
    if (!str_contains($files[$file], $needle)) {
        throw new RuntimeException('Missing navigation contract: ' . $label);
    }
}

foreach (['general', 'appearance', 'content', 'media', 'uploads', 'privacy', 'advanced'] as $section) {
    if (!str_contains($files['registry'], "'{$section}' => [")) {
        throw new RuntimeException('Stable Settings section missing: ' . $section);
    }
}

echo "Admin Settings navigation contract tests passed.\n";
