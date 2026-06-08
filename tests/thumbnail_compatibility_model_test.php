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

$GLOBALS['thumbnail_compatibility_test_settings'] = [];
$GLOBALS['thumbnail_compatibility_test_root'] = '';

/**
 * Minimal translation stub used by this standalone test.
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
 */
function app_setting(string $key, ?string $default = null): ?string
{
    return array_key_exists($key, $GLOBALS['thumbnail_compatibility_test_settings'])
        ? (string) $GLOBALS['thumbnail_compatibility_test_settings'][$key]
        : $default;
}

/**
 * Minimal app setting writer used by this standalone test.
 */
function set_app_setting(string $key, string $value): void
{
    $GLOBALS['thumbnail_compatibility_test_settings'][$key] = $value;
}

/**
 * Minimal DNG extension detector used by format policy tests.
 */
function is_dng_image_path(string $path): bool
{
    return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'dng';
}

/**
 * Minimal DNG support stub used by format policy tests.
 */
function dng_derivative_generation_supported(): bool
{
    return true;
}

/**
 * Minimal thumbnail size list used by cleanup tests.
 *
 * @return array<int, int>
 */
function thumbnail_sizes(): array
{
    return [300, 600];
}

/**
 * Minimal gallery-root helper used by cleanup tests.
 */
function galleries_root(): string
{
    return (string) $GLOBALS['thumbnail_compatibility_test_root'];
}

/**
 * Minimal safe path containment check used by cleanup tests.
 */
function path_inside(string $root, string $path): bool
{
    $rootReal = realpath($root);
    $pathReal = realpath($path);
    return is_string($rootReal) && is_string($pathReal) && str_starts_with($pathReal, rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
}

/**
 * Minimal thumbnail path resolver used by cleanup tests.
 */
function thumbnail_abs_path(array $image, array $gallery, int $size, string $format = 'jpg'): string
{
    $base = pathinfo((string) $image['filename'], PATHINFO_FILENAME);
    return rtrim((string) $gallery['thumbs_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $base . '_thumb' . $size . '.' . $format;
}

require_once __DIR__ . '/../app/services/thumbnail_compatibility.php';

/**
 * Throw when a thumbnail compatibility expectation fails.
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

set_thumbnail_compatibility_mode('legacy');
assert_thumbnail_compatibility_same('legacy', thumbnail_compatibility_mode(), 'legacy mode persists');
assert_thumbnail_compatibility_same(['jpg', 'webp'], thumbnail_formats_for_compatibility_policy('/tmp/photo.jpg', 'image/jpeg', true), 'legacy mode keeps JPG and WebP');

set_thumbnail_compatibility_mode('modern');
assert_thumbnail_compatibility_same(['webp'], thumbnail_formats_for_compatibility_policy('/tmp/photo.jpg', 'image/jpeg', true), 'modern mode uses WebP when available');
assert_thumbnail_compatibility_same(['jpg'], thumbnail_formats_for_compatibility_policy('/tmp/photo.jpg', 'image/jpeg', false), 'modern mode falls back to JPG when WebP is unavailable');
assert_thumbnail_compatibility_same(['webp'], thumbnail_formats_for_compatibility_policy('/tmp/raw.dng', 'image/x-adobe-dng', true), 'modern DNG thumbnails use WebP only');

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'thumbnail-compatibility-' . bin2hex(random_bytes(6));
$galleryDir = $root . DIRECTORY_SEPARATOR . 'gallery';
$thumbsDir = $galleryDir . DIRECTORY_SEPARATOR . 'thumbs';
mkdir($thumbsDir, 0775, true);
$GLOBALS['thumbnail_compatibility_test_root'] = $root;
$image = ['filename' => 'photo.jpg'];
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

@unlink($thumbsDir . DIRECTORY_SEPARATOR . 'photo_thumb300.webp');
@rmdir($thumbsDir);
@unlink($galleryDir . DIRECTORY_SEPARATOR . 'photo.jpg');
@rmdir($galleryDir);
@rmdir($root);

echo "Thumbnail compatibility helper tests passed.\n";
