<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/thumbnail_compatibility_model_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies pure helper logic for thumbnail compatibility mode handling and
 *   generated legacy JPEG thumbnail cleanup.
 *
 * Responsibilities:
 *   - Cover mode normalization and persistence helpers
 *   - Cover Modern versus Legacy thumbnail format decisions
 *   - Verify legacy JPEG cleanup does not remove originals or WebP derivatives
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
 *   2026-06-08
 */

declare(strict_types=1);

use function Gallery\Services\delete_legacy_jpg_thumbnails_for_image;
use function Gallery\Services\set_thumbnail_compatibility_mode;
use function Gallery\Services\thumbnail_compatibility_mode;
use function Gallery\Services\thumbnail_compatibility_mode_normalize;
use function Gallery\Services\thumbnail_formats_for_compatibility_policy;
use function Gallery\Services\thumbnail_policy_format_allowed;
use function Gallery\Services\thumbnail_policy_requested_formats;

$GLOBALS['thumbnail_compatibility_test_settings'] = [];
$GLOBALS['thumbnail_compatibility_test_root'] = '';
$GLOBALS['thumbnail_compatibility_test_metadata_deletes'] = [];

/**
 * Minimal translation stub used by this standalone test.
 *
 * @param string $key Lookup key.
 * @param string $fallback Fallback value.
 * @param array $parameters Parameters value.
 * @return string Text result for the caller.
 */
function t(string $key, string $fallback = '', array $parameters = []): string
{
    $text = $fallback !== '' ? $fallback : $key;
    foreach ($parameters as $name => $value) {
        $text = str_replace('{' . $name . '}', (string) $value, $text);
    }
    return $text;
}

/**
 * Minimal app setting reader used by this standalone test.
 *
 * @param string $key Lookup key.
 * @param ?string $default Default value when no explicit value is available.
 * @return ?string Text result for the caller.
 */
function app_setting(string $key, ?string $default = null): ?string
{
    return array_key_exists($key, $GLOBALS['thumbnail_compatibility_test_settings'])
        ? (string) $GLOBALS['thumbnail_compatibility_test_settings'][$key]
        : $default;
}

/**
 * Minimal app setting writer used by this standalone test.
 *
 * @param string $key Lookup key.
 * @param string $value Value to process.
 */
function set_app_setting(string $key, string $value): void
{
    $GLOBALS['thumbnail_compatibility_test_settings'][$key] = $value;
}

/**
 * Minimal DNG extension detector used by format policy tests.
 *
 * @param string $path Filesystem path.
 * @return bool True when the condition matches.
 */
function is_dng_image_path(string $path): bool
{
    return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'dng';
}

/**
 * Minimal DNG support stub used by format policy tests.
 *
 * @return bool True when the condition matches.
 */
function dng_derivative_generation_supported(): bool
{
    return true;
}

/**
 * Minimal thumbnail size list used by cleanup tests.
 *
 * @return array<int int>.
 */
function thumbnail_sizes(): array
{
    return [300, 600];
}

/**
 * Minimal gallery-root helper used by cleanup tests.
 *
 * @return string Text result for the caller.
 */
function galleries_root(): string
{
    return (string) $GLOBALS['thumbnail_compatibility_test_root'];
}

/**
 * Minimal safe path containment check used by cleanup tests.
 *
 * @param string $root Root value.
 * @param string $path Filesystem path.
 * @return bool True when the condition matches.
 */
function path_inside(string $root, string $path): bool
{
    $rootReal = realpath($root);
    $pathReal = realpath($path);
    return is_string($rootReal) && is_string($pathReal) && str_starts_with($pathReal, rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
}

/**
 * Minimal thumbnail path resolver used by cleanup tests.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param int $size Size value.
 * @param string $format Format value.
 * @return string Text result for the caller.
 */
function thumbnail_abs_path(array $image, array $gallery, int $size, string $format = 'jpg'): string
{
    $base = pathinfo((string) $image['filename'], PATHINFO_FILENAME);
    return rtrim((string) $gallery['thumbs_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $base . '_thumb' . $size . '.' . $format;
}

require_once __DIR__ . '/support/thumbnail_compatibility_shims.php';
require_once __DIR__ . '/../app/services/thumbnail_compatibility.php';

/**
 * Throw when a thumbnail compatibility expectation fails.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual Actual value.
 * @param string $label Label value.
 */
function assert_thumbnail_compatibility_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

assert_thumbnail_compatibility_same('modern', thumbnail_compatibility_mode_normalize(''), 'empty mode normalizes to modern');
assert_thumbnail_compatibility_same('legacy', thumbnail_compatibility_mode_normalize('LEGACY'), 'legacy mode is accepted case-insensitively');
assert_thumbnail_compatibility_same('modern', thumbnail_compatibility_mode(), 'default stored mode is modern');
assert_thumbnail_compatibility_same(['webp'], thumbnail_policy_requested_formats(), 'default mode requests WebP only');
assert_thumbnail_compatibility_same(false, thumbnail_policy_format_allowed('jpg'), 'default mode rejects generated JPEG variants');

set_thumbnail_compatibility_mode('legacy');
assert_thumbnail_compatibility_same('legacy', thumbnail_compatibility_mode(), 'legacy mode persists');
assert_thumbnail_compatibility_same(true, thumbnail_policy_format_allowed('jpg'), 'legacy mode allows generated JPEG variants');
assert_thumbnail_compatibility_same(['jpg', 'webp'], thumbnail_formats_for_compatibility_policy('/tmp/photo.jpg', 'image/jpeg', true), 'legacy mode keeps JPG and WebP');

set_thumbnail_compatibility_mode('modern');
assert_thumbnail_compatibility_same(['webp'], thumbnail_formats_for_compatibility_policy('/tmp/photo.jpg', 'image/jpeg', true), 'modern mode uses WebP when available');
assert_thumbnail_compatibility_same([], thumbnail_formats_for_compatibility_policy('/tmp/photo.jpg', 'image/jpeg', false), 'modern mode does not silently create legacy JPG thumbnails when WebP is unavailable');
assert_thumbnail_compatibility_same(['webp'], thumbnail_formats_for_compatibility_policy('/tmp/raw.dng', 'image/x-adobe-dng', true), 'modern DNG thumbnails use WebP only');

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'thumbnail-compatibility-' . bin2hex(random_bytes(6));
$galleryDir = $root . DIRECTORY_SEPARATOR . 'gallery';
$thumbsDir = $galleryDir . DIRECTORY_SEPARATOR . 'thumbs';
mkdir($thumbsDir, 0775, true);
$GLOBALS['thumbnail_compatibility_test_root'] = $root;
$image = ['id' => 41, 'filename' => 'photo.jpg'];
$gallery = ['thumbs_dir' => $thumbsDir];
file_put_contents($galleryDir . DIRECTORY_SEPARATOR . 'photo.jpg', 'original');
file_put_contents($thumbsDir . DIRECTORY_SEPARATOR . 'photo_thumb300.jpg', 'legacy-small');
file_put_contents($thumbsDir . DIRECTORY_SEPARATOR . 'photo_thumb600.jpg', 'legacy-large');
file_put_contents($thumbsDir . DIRECTORY_SEPARATOR . 'photo_thumb300.webp', 'modern-small');

$result = delete_legacy_jpg_thumbnails_for_image($image, $gallery);
assert_thumbnail_compatibility_same(2, (int) $result['files_deleted'], 'cleanup deletes only generated JPG thumbnails');
assert_thumbnail_compatibility_same(false, is_file($thumbsDir . DIRECTORY_SEPARATOR . 'photo_thumb300.jpg'), 'small JPG thumbnail was deleted');
assert_thumbnail_compatibility_same(false, is_file($thumbsDir . DIRECTORY_SEPARATOR . 'photo_thumb600.jpg'), 'large JPG thumbnail was deleted');
assert_thumbnail_compatibility_same(true, is_file($thumbsDir . DIRECTORY_SEPARATOR . 'photo_thumb300.webp'), 'WebP thumbnail was kept');
assert_thumbnail_compatibility_same(true, is_file($galleryDir . DIRECTORY_SEPARATOR . 'photo.jpg'), 'original photo was kept');
assert_thumbnail_compatibility_same(2, count($GLOBALS['thumbnail_compatibility_test_metadata_deletes']), 'cleanup removes JPEG metadata when generated files exist');

$result = delete_legacy_jpg_thumbnails_for_image($image, $gallery);
assert_thumbnail_compatibility_same(0, (int) $result['files_deleted'], 'cleanup tolerates already missing JPEG thumbnails');
assert_thumbnail_compatibility_same(4, count($GLOBALS['thumbnail_compatibility_test_metadata_deletes']), 'cleanup still removes stale JPEG metadata when generated files are already missing');
assert_thumbnail_compatibility_same(true, is_file($thumbsDir . DIRECTORY_SEPARATOR . 'photo_thumb300.webp'), 'repeat cleanup keeps WebP thumbnail metadata and file state untouched');

@unlink($thumbsDir . DIRECTORY_SEPARATOR . 'photo_thumb300.webp');
@rmdir($thumbsDir);
@unlink($galleryDir . DIRECTORY_SEPARATOR . 'photo.jpg');
@rmdir($galleryDir);
@rmdir($root);

echo "Thumbnail compatibility helper tests passed.\n";
