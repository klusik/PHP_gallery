<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/security_schema_system_health_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies the generic Phase 9 security/authentication System Health model and UI contracts.
 *
 * Responsibilities:
 *   - Preserve available, missing, unknown, and disabled health states
 *   - Keep diagnostics limited to bounded schema object names and request references
 *   - Verify all Phase 9 capabilities are registered in System Health
 *   - Verify Runtime Diagnostics consumes the complete security capability set
 *   - Verify Admin action badges react to missing and unknown security schema state
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - The normalization helper is pure and requires no database connection.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-08-12
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/services/admin_dashboard.php';

use function Gallery\Services\admin_schema_health_model;

/**
 * Assert strict equality for one generic security health expectation.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual Actual value.
 * @param string $label Assertion label.
 */
function security_health_assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$available = admin_schema_health_model([
    'state' => 'available',
    'requirements' => [[
        'state' => 'available',
        'object_type' => 'table',
        'table' => 'admin_remember_tokens',
        'object' => 'admin_remember_tokens',
    ]],
], 'auth_persistent_login', true, 'unused');
security_health_assert_same('available', $available['state'], 'available state');
security_health_assert_same('auth_persistent_login', $available['feature'], 'available feature');
security_health_assert_same([], $available['affected_objects'], 'available affected objects');

$missing = admin_schema_health_model([
    'state' => 'missing',
    'requirements' => [[
        'state' => 'missing',
        'object_type' => 'table',
        'table' => 'password_reset_tokens',
        'object' => 'password_reset_tokens',
    ], [
        'state' => 'missing',
        'object_type' => 'column',
        'table' => 'users',
        'object' => 'email',
    ]],
], 'auth_password_reset', true, 'unused');
security_health_assert_same('missing', $missing['state'], 'missing state');
security_health_assert_same(['password_reset_tokens', 'users.email'], $missing['affected_objects'], 'missing affected objects');
security_health_assert_same(['pending_migrations'], $missing['suggested_checks'], 'missing migration guidance');

$unknown = admin_schema_health_model([
    'state' => 'unknown',
    'requirements' => [[
        'state' => 'unknown',
        'object_type' => 'column',
        'table' => 'galleries',
        'object' => 'access_mode',
        'diagnostic' => 'password=secret SELECT * FROM users',
    ], [
        'state' => 'unknown',
        'object_type' => 'column',
        'table' => 'bad table',
        'object' => '../token',
        'diagnostic' => 'private path',
    ]],
], 'gallery_access', true, 'request-phase9-health');
security_health_assert_same('unknown', $unknown['state'], 'unknown state');
security_health_assert_same('request-phase9-health', $unknown['request_id'], 'unknown request id');
security_health_assert_same(['galleries.access_mode'], $unknown['affected_objects'], 'unknown bounded objects');
security_health_assert_same(['database_connection', 'selected_database', 'schema_inspection_permissions'], $unknown['suggested_checks'], 'unknown operational guidance');
security_health_assert_same(false, str_contains((string) json_encode($unknown), 'secret'), 'unknown raw diagnostic redaction');

$disabled = admin_schema_health_model(['state' => 'unknown', 'requirements' => []], 'auth_external_identity', false, 'request-hidden');
security_health_assert_same('disabled', $disabled['state'], 'disabled state');
security_health_assert_same('', $disabled['request_id'], 'disabled request suppression');

$invalidFeature = admin_schema_health_model(['state' => 'available', 'requirements' => []], 'unsafe feature/password', true, 'unused');
security_health_assert_same('schema_capability', $invalidFeature['feature'], 'invalid feature bounded fallback');

$serviceSource = (string) file_get_contents(__DIR__ . '/../app/services/admin_dashboard.php');
foreach (['gallery_access', 'gallery_visibility', 'gallery_share_token', 'nsfw_guard', 'auth_persistent_login', 'auth_password_reset', 'auth_external_identity'] as $feature) {
    security_health_assert_same(true, str_contains($serviceSource, "'" . $feature . "' =>"), 'System Health registration ' . $feature);
}

$dashboardView = (string) file_get_contents(__DIR__ . '/../app/views/admin_dashboard_sections.php');
security_health_assert_same(true, str_contains($dashboardView, 'view_render_admin_dashboard_security_schema_card'), 'generic security health renderer');
security_health_assert_same(true, str_contains($dashboardView, "['missing', 'unknown']"), 'generic System Health action states');

$diagnosticsSource = (string) file_get_contents(__DIR__ . '/../app/controllers/admin_diagnostics.php');
security_health_assert_same(true, str_contains($diagnosticsSource, 'admin_security_schema_health_statuses()'), 'Runtime Diagnostics generic status source');
security_health_assert_same(true, str_contains($diagnosticsSource, 'Security and authentication database status'), 'Runtime Diagnostics report heading');
security_health_assert_same(true, str_contains($diagnosticsSource, 'schema_inspection_permissions'), 'Runtime Diagnostics bounded operational guidance');
security_health_assert_same(false, str_contains($diagnosticsSource, 'getMessage()'), 'Runtime Diagnostics no raw exception messages');

echo "Security schema System Health checks passed.\n";
