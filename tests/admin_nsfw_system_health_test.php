<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_nsfw_system_health_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies the bounded four-state Admin health model for NSFW Guard.
 *
 * Responsibilities:
 *   - Distinguish available, missing, unknown, and disabled states
 *   - Preserve request correlation only for operationally unknown state
 *   - Expose safe migration and operational suggested-check identifiers
 *   - Reject malformed object identities and private diagnostic content
 *   - Verify dashboard and Runtime Diagnostics consume the shared model
 *   - Remain executable without a database or web server
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - The tested normalization function is pure and performs no schema query.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-08-12
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/services/admin_dashboard.php';

use function Gallery\Services\admin_nsfw_schema_health_model;

/**
 * Throw when one strict System Health expectation fails.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual Actual value.
 * @param string $label Assertion label.
 */
function nsfw_health_assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$requirement = static fn (string $state, string $table, string $object, string $diagnostic = ''): array => [
    'state' => $state,
    'table' => $table,
    'object' => $object,
    'diagnostic' => $diagnostic,
];

$available = admin_nsfw_schema_health_model(['state' => 'available', 'requirements' => [
    $requirement('available', 'galleries', 'nsfw_enabled'),
    $requirement('available', 'images', 'nsfw_enabled'),
]], true, 'unused-request');
nsfw_health_assert_same('available', $available['state'], 'available state');
nsfw_health_assert_same('', $available['request_id'], 'available request id');
nsfw_health_assert_same([], $available['affected_objects'], 'available affected objects');

$missing = admin_nsfw_schema_health_model(['state' => 'missing', 'requirements' => [
    $requirement('missing', 'galleries', 'nsfw_enabled'),
    $requirement('available', 'images', 'nsfw_enabled'),
]], true, 'unused-request');
nsfw_health_assert_same('missing', $missing['state'], 'missing state');
nsfw_health_assert_same(['galleries.nsfw_enabled'], $missing['affected_objects'], 'missing affected object');
nsfw_health_assert_same(['pending_migrations'], $missing['suggested_checks'], 'missing guidance');

$unknown = admin_nsfw_schema_health_model(['state' => 'unknown', 'requirements' => [
    $requirement('unknown', 'images', 'nsfw_enabled', 'password=secret SELECT * FROM users C:\\private'),
    $requirement('unknown', 'bad table', '../token', 'private diagnostic'),
]], true, 'request-health-123');
nsfw_health_assert_same('unknown', $unknown['state'], 'unknown state');
nsfw_health_assert_same('request-health-123', $unknown['request_id'], 'unknown request correlation');
nsfw_health_assert_same(['images.nsfw_enabled'], $unknown['affected_objects'], 'unknown safe affected object');
nsfw_health_assert_same(['database_connection', 'selected_database', 'schema_inspection_permissions'], $unknown['suggested_checks'], 'unknown guidance');
nsfw_health_assert_same(false, str_contains(json_encode($unknown), 'secret'), 'unknown diagnostic redaction');
nsfw_health_assert_same(false, str_contains(json_encode($unknown), 'private'), 'unknown path redaction');

$disabled = admin_nsfw_schema_health_model(['state' => 'unknown', 'requirements' => []], false, 'request-must-not-leak');
nsfw_health_assert_same('disabled', $disabled['state'], 'disabled state');
nsfw_health_assert_same('', $disabled['request_id'], 'disabled request id');
nsfw_health_assert_same([], $disabled['suggested_checks'], 'disabled guidance');

$malformed = admin_nsfw_schema_health_model(['state' => 'unexpected', 'requirements' => []], true, 'request-malformed');
nsfw_health_assert_same('unknown', $malformed['state'], 'malformed state fails safe');

$dashboardView = (string) file_get_contents(__DIR__ . '/../app/views/admin_dashboard_sections.php');
nsfw_health_assert_same(true, str_contains($dashboardView, "\$state === 'disabled'"), 'dashboard disabled rendering');
nsfw_health_assert_same(true, str_contains($dashboardView, "['missing', 'unknown']"), 'System Health action badge states');
$dashboardPageView = (string) file_get_contents(__DIR__ . '/../app/views/admin_dashboard.php');
nsfw_health_assert_same(true, str_contains($dashboardPageView, "['missing', 'unknown']"), 'Maintenance action badge states');
$diagnosticsController = (string) file_get_contents(__DIR__ . '/../app/controllers/admin_diagnostics.php');
nsfw_health_assert_same(true, str_contains($diagnosticsController, 'admin_nsfw_schema_health_status()'), 'Runtime Diagnostics shared model');
nsfw_health_assert_same(true, str_contains($diagnosticsController, 'Suggested checks:'), 'copy report guidance');
nsfw_health_assert_same(true, str_contains($diagnosticsController, 'verify permission to inspect database metadata'), 'human-readable permission guidance');

echo "Admin NSFW System Health checks passed.\n";
