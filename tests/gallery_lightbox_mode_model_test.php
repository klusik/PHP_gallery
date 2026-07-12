<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/gallery_lightbox_mode_model_test.php
 * Module Type: Test
 *
 * Purpose:
 *   Verifies Theme and gallery override resolution for public lightbox browsing modes.
 *
 * Responsibilities:
 *   - Confirm that invalid or empty values fall back safely
 *   - Confirm that galleries inherit the Theme setting unless they store an override
 *   - Confirm that pre-migration installations ignore unavailable gallery columns
 *   - Keep the model test database-free so it can run in lightweight CI or local checks
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
 *   2026-06-01
 */

declare(strict_types=1);

use function Gallery\Services\gallery_effective_lightbox_browsing_mode;
use function Gallery\Services\gallery_lightbox_browsing_mode_label;
use function Gallery\Services\gallery_lightbox_browsing_mode_normalize;
use function Gallery\Services\gallery_lightbox_browsing_mode_storage_value;
use function Gallery\Services\theme_lightbox_browsing_mode;

require_once __DIR__ . '/../app/services/app_settings.php';

if (!function_exists('t')) {
        /**
     * Minimal translation shim for this database-free service test.
     *
     * @param string $key Lookup key.
     * @param string $fallback Fallback value.
     * @param array $parameters Parameters value.
     * @return string Text result for the caller.
     */
    function t(string $key, string $fallback = '', array $parameters = []): string
    {
        foreach ($parameters as $name => $value) {
            $fallback = str_replace('{' . $name . '}', (string) $value, $fallback);
        }
        return $fallback !== '' ? $fallback : $key;
    }
}

$GLOBALS['gallery_lightbox_mode_test_schema_ready'] = true;

if (!function_exists('db_column_exists')) {
        /**
     * Minimal schema shim used to exercise both migrated and pre-migration flows.
     *
     * @param string $table Table value.
     * @param string $column Column value.
     * @return bool True when the condition matches.
     */
    function db_column_exists(string $table, string $column): bool
    {
        return $table === 'galleries'
            && $column === 'lightbox_browsing_mode'
            && !empty($GLOBALS['gallery_lightbox_mode_test_schema_ready']);
    }
}

require_once __DIR__ . '/../app/services/gallery_lightbox_mode.php';

/**
 * Assert that two values are identical.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual Actual value.
 * @param string $message Message value.
 */
function assert_gallery_lightbox_mode_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, 'Actual:   ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

// The legacy site behavior remains single-image mode until the Theme setting is changed.
$GLOBALS['cms_app_settings_cache'] = ['theme_lightbox_browsing_mode' => null];
assert_gallery_lightbox_mode_same('single', theme_lightbox_browsing_mode(), 'Empty Theme setting must default to single-image mode.');

// Normalization accepts only renderer-supported public modes and upgrades the legacy strip value.
assert_gallery_lightbox_mode_same('picture_strip', gallery_lightbox_browsing_mode_normalize('strip'), 'Legacy strip mode must normalize to picture_strip.');
assert_gallery_lightbox_mode_same('picture_strip', gallery_lightbox_browsing_mode_normalize('picture_strip'), 'Picture-strip mode must normalize as picture_strip.');
assert_gallery_lightbox_mode_same('3d_carousel', gallery_lightbox_browsing_mode_normalize('3d_carousel'), '3D carousel mode must normalize as 3d_carousel.');
assert_gallery_lightbox_mode_same('single', gallery_lightbox_browsing_mode_normalize('invalid'), 'Invalid values must fall back to single-image mode.');
assert_gallery_lightbox_mode_same('picture_strip', gallery_lightbox_browsing_mode_normalize('invalid', 'strip'), 'Invalid values must honor a legacy strip fallback.');
assert_gallery_lightbox_mode_same('3d_carousel', gallery_lightbox_browsing_mode_normalize('invalid', '3d_carousel'), 'Invalid values must honor a valid 3D carousel fallback.');

// Gallery storage uses NULL for inheritance, matching existing override patterns.
assert_gallery_lightbox_mode_same(null, gallery_lightbox_browsing_mode_storage_value('inherit'), 'Inherit must store as NULL.');
assert_gallery_lightbox_mode_same(null, gallery_lightbox_browsing_mode_storage_value(''), 'Empty override must store as NULL.');
assert_gallery_lightbox_mode_same('single', gallery_lightbox_browsing_mode_storage_value('single'), 'Single-image override must be stored explicitly.');
assert_gallery_lightbox_mode_same('picture_strip', gallery_lightbox_browsing_mode_storage_value('strip'), 'Legacy strip override must be stored as picture_strip.');
assert_gallery_lightbox_mode_same('picture_strip', gallery_lightbox_browsing_mode_storage_value('picture_strip'), 'Picture-strip override must be stored explicitly.');
assert_gallery_lightbox_mode_same('3d_carousel', gallery_lightbox_browsing_mode_storage_value('3d_carousel'), '3D carousel override must be stored explicitly.');
assert_gallery_lightbox_mode_same(null, gallery_lightbox_browsing_mode_storage_value('slideshow'), 'Unsupported modes must not be stored.');

// A gallery-level override wins over the Theme default after the migration exists.
$GLOBALS['cms_app_settings_cache'] = ['theme_lightbox_browsing_mode' => '3d_carousel'];
$GLOBALS['gallery_lightbox_mode_test_schema_ready'] = true;
assert_gallery_lightbox_mode_same('single', gallery_effective_lightbox_browsing_mode(['lightbox_browsing_mode' => 'single']), 'Gallery override must win over Theme default.');
assert_gallery_lightbox_mode_same('3d_carousel', gallery_effective_lightbox_browsing_mode(['lightbox_browsing_mode' => null]), 'Null gallery value must inherit Theme default.');
assert_gallery_lightbox_mode_same('3d_carousel', gallery_effective_lightbox_browsing_mode([]), 'Missing gallery value must inherit Theme default.');

// Before the gallery column is migrated in, the public site remains stable and uses Theme only.
$GLOBALS['gallery_lightbox_mode_test_schema_ready'] = false;
assert_gallery_lightbox_mode_same('3d_carousel', gallery_effective_lightbox_browsing_mode(['lightbox_browsing_mode' => 'single']), 'Pre-migration installs must ignore unavailable gallery override columns.');

// Labels are intentionally driven by normalized values so Admin forms cannot display raw invalid data.
assert_gallery_lightbox_mode_same('Picture strip', gallery_lightbox_browsing_mode_label('strip'), 'Legacy strip label must be readable.');
assert_gallery_lightbox_mode_same('Picture strip', gallery_lightbox_browsing_mode_label('picture_strip'), 'Picture-strip label must be readable.');
assert_gallery_lightbox_mode_same('3D carousel', gallery_lightbox_browsing_mode_label('3d_carousel'), '3D carousel label must be readable.');
assert_gallery_lightbox_mode_same('Single image', gallery_lightbox_browsing_mode_label('bad-value'), 'Invalid label values must use single-image fallback.');

echo "Gallery lightbox mode model tests passed.\n";
