<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/public_thumbnail_rendering_model_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies the site-level public thumbnail renderer model without requiring a database or browser.
 *
 * Responsibilities:
 *   - Cover safe mode normalization and responsive default behavior
 *   - Cover app_settings-compatible persistence of supported and unsupported submitted values
 *   - Cover the narrow responsive/progressive picture strategy dispatch boundary
 *   - Lock the existing responsive loading policy and the documented progressive small-image loading policy
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
 *   2026-08-09
 */

declare(strict_types=1);

use const Gallery\Services\PUBLIC_THUMBNAIL_RENDERING_PROGRESSIVE;
use const Gallery\Services\PUBLIC_THUMBNAIL_RENDERING_RESPONSIVE;
use const Gallery\Services\PUBLIC_THUMBNAIL_RENDERING_SETTING_KEY;
use function Gallery\Services\public_progressive_thumbnail_loading_attributes;
use function Gallery\Services\public_responsive_thumbnail_loading_attributes;
use function Gallery\Services\public_thumbnail_render_picture_html;
use function Gallery\Services\public_thumbnail_rendering_mode;
use function Gallery\Services\public_thumbnail_rendering_mode_normalize;
use function Gallery\Services\public_thumbnail_rendering_mode_save;
use function Gallery\Services\public_thumbnail_rendering_mode_save_with_revision;

$GLOBALS['public_thumbnail_rendering_test_settings'] = [];

/**
 * Minimal app_settings reader used by the standalone renderer model test.
 *
 * @param string $key Setting key.
 * @param ?string $default Default value.
 * @return ?string Stored or default value.
 */
function app_setting(string $key, ?string $default = null): ?string
{
    return array_key_exists($key, $GLOBALS['public_thumbnail_rendering_test_settings'])
        ? (string) $GLOBALS['public_thumbnail_rendering_test_settings'][$key]
        : $default;
}

/**
 * Minimal app_settings writer used to verify normalized Admin persistence behavior.
 *
 * @param string $key Setting key.
 * @param string $value Stored value.
 */
function set_app_setting(string $key, string $value): void
{
    $GLOBALS['public_thumbnail_rendering_test_settings'][$key] = $value;
}

/**
 * Responsive picture helper shim used to verify strategy selection and attributes.
 *
 * @param array $image Image row.
 * @param int $fallbackSize Preferred fallback size.
 * @param array $srcsetSizes Requested candidate sizes.
 * @param string $sizes Sizes hint.
 * @param string $alt Alternative text.
 * @param string $extraAttributes Image attributes.
 * @param ?array $thumbnailBundle Thumbnail bundle.
 * @return string Observable test result.
 */
function thumbnail_picture_html(array $image, int $fallbackSize, array $srcsetSizes, string $sizes, string $alt, string $extraAttributes = '', ?array $thumbnailBundle = null): string
{
    return 'responsive|' . $extraAttributes;
}

/**
 * Progressive picture helper shim used to verify strategy selection and attributes.
 *
 * @param array $image Image row.
 * @param int $fallbackSize Preferred fallback size.
 * @param array $srcsetSizes Requested candidate sizes.
 * @param string $initialSizes Initial sizes hint.
 * @param string $finalSizes Final sizes hint.
 * @param string $alt Alternative text.
 * @param string $extraAttributes Image attributes.
 * @param ?array $thumbnailBundle Thumbnail bundle.
 * @return string Observable test result.
 */
function thumbnail_progressive_picture_html(array $image, int $fallbackSize, array $srcsetSizes, string $initialSizes, string $finalSizes, string $alt, string $extraAttributes = '', ?array $thumbnailBundle = null): string
{
    return 'progressive|' . $extraAttributes;
}

require_once __DIR__ . '/../app/services/public_thumbnail_rendering.php';

/**
 * Throw when two renderer-model values are not identical.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual Actual value.
 * @param string $label Assertion label.
 */
function assert_public_thumbnail_rendering_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

assert_public_thumbnail_rendering_same('responsive', public_thumbnail_rendering_mode_normalize(null), 'missing mode normalizes to responsive');
assert_public_thumbnail_rendering_same('responsive', public_thumbnail_rendering_mode_normalize(''), 'empty mode normalizes to responsive');
assert_public_thumbnail_rendering_same('responsive', public_thumbnail_rendering_mode_normalize('responsive'), 'responsive mode is accepted');
assert_public_thumbnail_rendering_same('progressive', public_thumbnail_rendering_mode_normalize('progressive'), 'progressive mode is accepted');
assert_public_thumbnail_rendering_same('responsive', public_thumbnail_rendering_mode_normalize('obsolete'), 'unknown mode normalizes to responsive');
assert_public_thumbnail_rendering_same('responsive', public_thumbnail_rendering_mode_normalize(['progressive']), 'malformed mode normalizes to responsive');
assert_public_thumbnail_rendering_same('responsive', public_thumbnail_rendering_mode(), 'missing persisted setting resolves to responsive');

public_thumbnail_rendering_mode_save('progressive');
assert_public_thumbnail_rendering_same('progressive', $GLOBALS['public_thumbnail_rendering_test_settings'][PUBLIC_THUMBNAIL_RENDERING_SETTING_KEY] ?? null, 'supported progressive value persists');
assert_public_thumbnail_rendering_same('progressive', public_thumbnail_rendering_mode(), 'persisted progressive value resolves to progressive');

public_thumbnail_rendering_mode_save('unsupported');
assert_public_thumbnail_rendering_same('responsive', $GLOBALS['public_thumbnail_rendering_test_settings'][PUBLIC_THUMBNAIL_RENDERING_SETTING_KEY] ?? null, 'unsupported submitted value persists only safe responsive fallback');
assert_public_thumbnail_rendering_same('responsive', public_thumbnail_rendering_mode(), 'invalid saved submission resolves to responsive');

$GLOBALS['public_thumbnail_rendering_test_settings'] = [];
public_thumbnail_rendering_mode_save_with_revision('progressive');
assert_public_thumbnail_rendering_same('progressive', public_thumbnail_rendering_mode(), 'revision-aware save persists the selected mode');
if (trim((string) ($GLOBALS['public_thumbnail_rendering_test_settings']['theme_public_content_revision'] ?? '')) === '') {
    throw new RuntimeException('Revision-aware thumbnail save did not bump theme_public_content_revision.');
}
$revision = $GLOBALS['public_thumbnail_rendering_test_settings']['theme_public_content_revision'];
public_thumbnail_rendering_mode_save_with_revision('progressive');
assert_public_thumbnail_rendering_same($revision, $GLOBALS['public_thumbnail_rendering_test_settings']['theme_public_content_revision'] ?? null, 'unchanged renderer does not bump content revision');

assert_public_thumbnail_rendering_same('loading="eager" fetchpriority="high"', public_responsive_thumbnail_loading_attributes(0), 'responsive first card loading policy unchanged');
assert_public_thumbnail_rendering_same('loading="eager" fetchpriority="high"', public_responsive_thumbnail_loading_attributes(1), 'responsive second card loading policy unchanged');
assert_public_thumbnail_rendering_same('loading="eager" fetchpriority="auto"', public_responsive_thumbnail_loading_attributes(2), 'responsive middle eager policy unchanged');
assert_public_thumbnail_rendering_same('loading="eager" fetchpriority="auto"', public_responsive_thumbnail_loading_attributes(7), 'responsive eighth card remains eager');
assert_public_thumbnail_rendering_same('loading="lazy" fetchpriority="low"', public_responsive_thumbnail_loading_attributes(8), 'responsive later cards remain lazy');

assert_public_thumbnail_rendering_same('loading="eager" fetchpriority="high"', public_progressive_thumbnail_loading_attributes(0), 'progressive first small thumbnail is eager high priority');
assert_public_thumbnail_rendering_same('loading="eager" fetchpriority="auto"', public_progressive_thumbnail_loading_attributes(1), 'progressive second small thumbnail is eager auto priority');
assert_public_thumbnail_rendering_same('loading="lazy" fetchpriority="low"', public_progressive_thumbnail_loading_attributes(2), 'progressive remaining small thumbnails are native lazy low priority');

$testImage = ['id' => 1];
$testBundle = ['image' => $testImage];
assert_public_thumbnail_rendering_same(
    'responsive|data-public-thumbnail-rendering-mode="responsive" data-public-thumbnail-card-index="0" loading="eager" fetchpriority="high"',
    public_thumbnail_render_picture_html($testImage, 300, [300, 600], '50vw', 'Photo', 0, $testBundle, PUBLIC_THUMBNAIL_RENDERING_RESPONSIVE),
    'responsive renderer dispatches the responsive picture helper'
);
assert_public_thumbnail_rendering_same(
    'progressive|data-public-thumbnail-rendering-mode="progressive" data-public-thumbnail-card-index="1" loading="eager" fetchpriority="auto"',
    public_thumbnail_render_picture_html($testImage, 300, [300, 600], '50vw', 'Photo', 1, $testBundle, PUBLIC_THUMBNAIL_RENDERING_PROGRESSIVE),
    'progressive renderer dispatches the progressive picture helper'
);
assert_public_thumbnail_rendering_same(
    'responsive|data-public-thumbnail-rendering-mode="responsive" data-public-thumbnail-card-index="0" loading="eager" fetchpriority="high"',
    public_thumbnail_render_picture_html($testImage, 300, [300, 600], '50vw', 'Photo', 0, $testBundle, 'invalid-mode'),
    'renderer dispatch safely falls back to responsive for an unknown value'
);

// Confirm the Admin controller routes posted values through the centralized validated persistence helper.
$adminThemeSource = file_get_contents(__DIR__ . '/../app/controllers/admin_theme.php');
if (!is_string($adminThemeSource) || !str_contains($adminThemeSource, "public_thumbnail_rendering_mode_save_with_revision(\$_POST['public_thumbnail_rendering_mode'] ?? null)")) {
    throw new RuntimeException('Admin Theme form does not persist the renderer through the shared revision-aware service helper.');
}

assert_public_thumbnail_rendering_same(PUBLIC_THUMBNAIL_RENDERING_RESPONSIVE, 'responsive', 'responsive machine value remains stable');
assert_public_thumbnail_rendering_same(PUBLIC_THUMBNAIL_RENDERING_PROGRESSIVE, 'progressive', 'progressive machine value remains stable');

echo "Public thumbnail rendering model tests passed.\n";
