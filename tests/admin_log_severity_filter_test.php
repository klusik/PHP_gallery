<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_log_severity_filter_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies admin log severity filter parsing and persisted setting decoding.
 *
 * Responsibilities:
 *   - Cover multi-select severity normalization
 *   - Cover legacy single-severity values
 *   - Cover invalid-value rejection
 *   - Cover persisted JSON filter decoding through the app settings cache
 *   - Remain executable with plain PHP on shared-hosting style environments
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
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-14
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/services/app_settings.php';
require_once __DIR__ . '/../app/controllers/admin_logs.php';
require_once __DIR__ . '/../app/services/logs.php';

/**
 * Throw when an expectation fails.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual Actual value.
 * @param string $label Label value.
 */
function assert_admin_log_severity_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

assert_admin_log_severity_same(
    ['info', 'error', 'critical'],
    admin_log_normalize_severity_filter(['critical', 'invalid', 'info', 'error', 'info']),
    'multi-select normalization keeps valid severities in UI order'
);

assert_admin_log_severity_same(
    ['warning'],
    admin_log_normalize_severity_filter('warning'),
    'legacy single severity normalization'
);

assert_admin_log_severity_same(
    ['warning', 'error'],
    admin_log_normalize_severity_filter('warning,error,unknown'),
    'comma-separated defensive normalization'
);

$GLOBALS['cms_app_settings_cache'] = [
    admin_log_severity_filter_setting_key() => json_encode(['debug', 'bogus', 'notice'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
];
assert_admin_log_severity_same(
    ['debug', 'notice'],
    admin_log_persisted_severity_filter(),
    'persisted JSON severity decoding'
);

$GLOBALS['cms_app_settings_cache'] = [admin_log_severity_filter_setting_key() => 'not-json'];
assert_admin_log_severity_same([], admin_log_persisted_severity_filter(), 'invalid persisted JSON resets to default all-severities state');

assert_admin_log_severity_same(
    'All severities are shown.',
    admin_log_severity_filter_summary([]),
    'empty selection summary'
);

assert_admin_log_severity_same(
    'Active severities: Error, Critical',
    admin_log_severity_filter_summary(['error', 'critical']),
    'active selection summary'
);

echo "Admin log severity filter tests passed.\n";
