<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/gallery_branding_model_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies gallery branding asset rules without requiring a live database.
 *
 * Responsibilities:
 *   - Cover supported branding asset kinds and persistence columns
 *   - Cover browser-safe upload MIME and extension validation
 *   - Cover public rendering precedence for banner, title, logo, and separator
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
 *   2026-05-07
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/services/gallery_branding.php';

/**
 * Throw when a branding model expectation fails.
 */
function assert_gallery_branding_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/**
 * Return the public title mode implied by configured branding paths.
 */
function test_gallery_branding_title_mode(array $paths): string
{
    return !empty($paths['banner']) ? 'banner' : 'text';
}

/**
 * Return true when the public header should reserve space for a logo.
 */
function test_gallery_branding_uses_logo(array $paths): bool
{
    return !empty($paths['logo']);
}

/**
 * Return true when a separator should be rendered before gallery content.
 */
function test_gallery_branding_uses_separator(array $paths): bool
{
    return !empty($paths['separator']);
}

assert_gallery_branding_same('banner_image_path', gallery_branding_asset_column('banner'), 'banner persistence column');
assert_gallery_branding_same('logo_image_path', gallery_branding_asset_column('logo'), 'logo persistence column');
assert_gallery_branding_same('separator_image_path', gallery_branding_asset_column('separator'), 'separator persistence column');
assert_gallery_branding_same('theme_branding_banner_path', theme_branding_asset_setting('banner'), 'theme banner fallback setting');
assert_gallery_branding_same('theme_branding_separator_path', theme_branding_asset_setting('separator'), 'theme separator fallback setting');
assert_gallery_branding_same('banner', theme_branding_asset_filename_stem('banner'), 'theme banner fallback filename stem');
assert_gallery_branding_same('separator', theme_branding_asset_filename_stem('separator'), 'theme separator fallback filename stem');

assert_gallery_branding_same('jpg', gallery_branding_mime_extension('image/jpeg'), 'jpeg MIME extension');
assert_gallery_branding_same('png', gallery_branding_mime_extension('image/png'), 'png MIME extension');
assert_gallery_branding_same('gif', gallery_branding_mime_extension('image/gif'), 'gif MIME extension');
assert_gallery_branding_same('webp', gallery_branding_mime_extension('image/webp'), 'webp MIME extension');
assert_gallery_branding_same(null, gallery_branding_mime_extension('image/svg+xml'), 'svg MIME rejected');

assert_gallery_branding_same(true, gallery_branding_upload_extension_allowed('Brand Logo.PNG'), 'uppercase png extension accepted');
assert_gallery_branding_same(true, gallery_branding_upload_extension_allowed('separator.webp'), 'webp extension accepted');
assert_gallery_branding_same(false, gallery_branding_upload_extension_allowed('script.php'), 'php extension rejected');
assert_gallery_branding_same(false, gallery_branding_upload_extension_allowed('vector.svg'), 'svg extension rejected');

$cases = [
    'plain gallery keeps text title' => [
        'paths' => ['banner' => null, 'logo' => null, 'separator' => null],
        'title_mode' => 'text',
        'logo' => false,
        'separator' => false,
    ],
    'banner replaces visible title text' => [
        'paths' => ['banner' => 'branding/banner.jpg', 'logo' => null, 'separator' => null],
        'title_mode' => 'banner',
        'logo' => false,
        'separator' => false,
    ],
    'logo supplements text title' => [
        'paths' => ['banner' => null, 'logo' => 'branding/logo.png', 'separator' => null],
        'title_mode' => 'text',
        'logo' => true,
        'separator' => false,
    ],
    'logo supplements banner' => [
        'paths' => ['banner' => 'branding/banner.webp', 'logo' => 'branding/logo.png', 'separator' => null],
        'title_mode' => 'banner',
        'logo' => true,
        'separator' => false,
    ],
    'separator is independent from title assets' => [
        'paths' => ['banner' => null, 'logo' => null, 'separator' => 'branding/separator.gif'],
        'title_mode' => 'text',
        'logo' => false,
        'separator' => true,
    ],
    'theme fallback banner follows banner title precedence' => [
        'paths' => ['banner' => 'cache/theme-branding/banner.jpg', 'logo' => null, 'separator' => null],
        'title_mode' => 'banner',
        'logo' => false,
        'separator' => false,
    ],
    'theme fallback separator follows separator placement' => [
        'paths' => ['banner' => null, 'logo' => null, 'separator' => 'cache/theme-branding/separator.png'],
        'title_mode' => 'text',
        'logo' => false,
        'separator' => true,
    ],
];

foreach ($cases as $label => $case) {
    assert_gallery_branding_same($case['title_mode'], test_gallery_branding_title_mode($case['paths']), $label . ': title mode');
    assert_gallery_branding_same($case['logo'], test_gallery_branding_uses_logo($case['paths']), $label . ': logo behavior');
    assert_gallery_branding_same($case['separator'], test_gallery_branding_uses_separator($case['paths']), $label . ': separator behavior');
}

echo "Gallery branding model tests passed.\n";
