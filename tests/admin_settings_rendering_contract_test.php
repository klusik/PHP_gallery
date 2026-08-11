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
if (!is_string($view) || !is_string($registry)) {
    throw new RuntimeException('Unable to read Admin Settings source files.');
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
