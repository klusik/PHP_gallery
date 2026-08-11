<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_settings_normalization_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies normalization boundaries used by centralized Admin Settings.
 *
 * Responsibilities:
 *   - Cover safe renderer fallback behavior
 *   - Cover bounded browser-upload numeric normalization
 *   - Cover central site-name normalization
 *   - Ensure unknown central setting identifiers cannot be persisted
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

$GLOBALS['admin_settings_test_store'] = [];

function app_setting(string $key, ?string $default = null): ?string
{
    return array_key_exists($key, $GLOBALS['admin_settings_test_store']) ? (string) $GLOBALS['admin_settings_test_store'][$key] : $default;
}

function set_app_setting(string $key, string $value): void
{
    $GLOBALS['admin_settings_test_store'][$key] = $value;
}

function url_for(string $page, array $params = []): string
{
    return '/index.php?page=' . rawurlencode($page);
}

require_once __DIR__ . '/../app/services/public_thumbnail_rendering.php';
require_once __DIR__ . '/../app/services/browser_uploads.php';
require_once __DIR__ . '/../app/services/admin_settings_registry.php';

use function Gallery\Services\admin_settings_normalize_editable_value;
use function Gallery\Services\admin_settings_save_editable_value;
use function Gallery\Services\browser_upload_normalize_settings;
use function Gallery\Services\public_thumbnail_rendering_mode_save;

function assert_admin_settings_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

assert_admin_settings_same('progressive', public_thumbnail_rendering_mode_save('progressive'), 'valid thumbnail mode persists');
assert_admin_settings_same('responsive', public_thumbnail_rendering_mode_save('invalid'), 'invalid thumbnail mode uses safe fallback');

$browser = browser_upload_normalize_settings([
    'enabled' => '1',
    'default_worker_count' => 999,
    'max_worker_count' => 999,
    'hard_worker_cap' => 999,
    'max_items_per_batch' => 999,
    'max_zip_batch_bytes' => PHP_INT_MAX,
    'zip_size_threshold_ratio' => 50,
]);
assert_admin_settings_same(32, $browser['hard_worker_cap'], 'browser hard worker cap is clamped');
assert_admin_settings_same(32, $browser['max_worker_count'], 'browser max worker count is clamped');
assert_admin_settings_same(32, $browser['default_worker_count'], 'browser default worker count is clamped to max');
assert_admin_settings_same(64, $browser['max_items_per_batch'], 'browser batch item count is clamped');
assert_admin_settings_same(128 * 1024 * 1024, $browser['max_zip_batch_bytes'], 'browser ZIP cap is clamped');
assert_admin_settings_same(0.95, $browser['zip_size_threshold_ratio'], 'browser ZIP ratio is clamped');

$siteEntry = ['id' => 'site_name', 'central_editable' => true];
assert_admin_settings_same('Gallery CMS', admin_settings_normalize_editable_value($siteEntry, '   '), 'empty site name uses existing fallback');
assert_admin_settings_same(str_repeat('x', 120), admin_settings_normalize_editable_value($siteEntry, str_repeat('x', 200)), 'site name preserves existing 120-character clamp');

$unknownEntry = ['id' => 'arbitrary_database_key', 'central_editable' => true];
try {
    admin_settings_normalize_editable_value($unknownEntry, 'value');
    throw new RuntimeException('Unknown central setting normalization was accepted.');
} catch (InvalidArgumentException) {
}

try {
    admin_settings_save_editable_value('arbitrary_database_key', 'value');
    throw new RuntimeException('Unknown central setting write was accepted.');
} catch (InvalidArgumentException) {
}

$controllerSource = file_get_contents(__DIR__ . '/../app/controllers/admin_settings.php');
if (!is_string($controllerSource) || !str_contains($controllerSource, "!empty(\$entry['central_editable'])")) {
    throw new RuntimeException('Central controller does not restrict writes to registry-whitelisted editable settings.');
}

echo "Admin Settings normalization tests passed.\n";
