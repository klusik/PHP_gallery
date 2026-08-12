<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/url_rewrite_settings_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies URL rewrite settings, compatibility detection, and fallback URL generation.
 *
 * Responsibilities:
 *   - Cover default-enabled URL rewrite behavior
 *   - Cover manual admin disabling through the stored setting value
 *   - Cover practical compatibility success and failure detection
 *   - Cover query-string fallback URL generation when rewriting is disabled
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
 *   2026-08-11
 */

declare(strict_types=1);

use function Gallery\Core\gallery_public_url;
use function Gallery\Core\image_public_media_url;
use function Gallery\Core\image_public_thumbnail_url;
use function Gallery\Core\image_public_url;
use function Gallery\Core\url_for;
use function Gallery\Services\url_rewrite_compatibility;
use function Gallery\Services\url_rewrite_enabled;
use function Gallery\Services\url_rewrite_should_emit_clean_urls;

// These tests avoid a live database by priming the app setting cache directly.

/**
 * Minimal config shim used by helpers.php in this isolated test process.
 *
 * @return array<string mixed>.
 */
function cms_config(): array
{
    return ['base_url' => 'https://example.test'];
}

require_once __DIR__ . '/../app/services/app_settings.php';
require_once __DIR__ . '/../app/helpers.php';

/**
 * Throw when an expectation fails.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual Actual value.
 * @param string $label Label value.
 */
function assert_url_rewrite_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/**
 * Create a temporary project root with optional rewrite marker files.
 *
 * @param bool $withMarkers With markers value.
 * @return string Text result for the caller.
 */
function make_url_rewrite_test_root(bool $withMarkers): string
{
    $root = sys_get_temp_dir() . '/php-gallery-url-rewrite-test-' . bin2hex(random_bytes(4));
    if (!mkdir($root, 0777, true) && !is_dir($root)) {
        throw new RuntimeException('Unable to create temporary test root.');
    }
    if (!mkdir($root . '/public', 0777, true) && !is_dir($root . '/public')) {
        throw new RuntimeException('Unable to create temporary public test root.');
    }
    if ($withMarkers) {
        $rules = "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^ index.php [L]\n</IfModule>\n";
        file_put_contents($root . '/.htaccess', $rules);
        file_put_contents($root . '/public/.htaccess', $rules);
    }
    return $root;
}

/**
 * Remove a temporary test root recursively.
 *
 * @param string $root Root value.
 */
function remove_url_rewrite_test_root(string $root): void
{
    foreach (array_reverse(iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST))) as $path) {
        $path->isDir() ? rmdir((string) $path) : unlink((string) $path);
    }
    rmdir($root);
}

$GLOBALS['cms_app_settings_cache'] = ['url_rewrite_enabled' => null];
assert_url_rewrite_same(true, url_rewrite_enabled(), 'default rewrite setting');

$supportedRoot = make_url_rewrite_test_root(true);
$unsupportedRoot = make_url_rewrite_test_root(false);

try {
    $GLOBALS['cms_app_settings_cache'] = ['url_rewrite_enabled' => '1'];
    $supported = url_rewrite_compatibility([
        'SERVER_SOFTWARE' => 'Apache/2.4',
        'REQUEST_URI' => '/index.php?page=admin',
        'SCRIPT_NAME' => '/index.php',
    ], $supportedRoot);
    assert_url_rewrite_same('likely_supported', $supported['status'], 'rewrite detection success status');
    assert_url_rewrite_same(true, $supported['supported'], 'rewrite detection success flag');

    $unsupported = url_rewrite_compatibility([
        'SERVER_SOFTWARE' => 'Apache/2.4',
        'REQUEST_URI' => '/index.php?page=admin',
        'SCRIPT_NAME' => '/index.php',
    ], $unsupportedRoot);
    assert_url_rewrite_same('unsupported', $unsupported['status'], 'rewrite detection failure status');
    assert_url_rewrite_same(false, $unsupported['supported'], 'rewrite detection failure flag');

    $GLOBALS['cms_app_settings_cache'] = ['url_rewrite_enabled' => '0'];
    $disabled = url_rewrite_compatibility([
        'SERVER_SOFTWARE' => 'Apache/2.4',
        'REQUEST_URI' => '/index.php?page=admin',
        'SCRIPT_NAME' => '/index.php',
    ], $supportedRoot);
    assert_url_rewrite_same('disabled', $disabled['status'], 'manual disabled status');
    assert_url_rewrite_same(false, url_rewrite_should_emit_clean_urls(), 'manual disabled emit clean URLs');
    assert_url_rewrite_same('https://example.test/index.php?page=tag&slug=friedrichshafen', url_for('tag', ['slug' => 'friedrichshafen']), 'manual disabled tag fallback URL');
    $gallery = ['url_path' => 'Trips/Prague', 'folder_path' => 'Trips/Prague', 'slug' => 'prague'];
    $image = ['url_slug' => 'Evening Flight', 'filename' => 'DSC_0001.JPG'];
    assert_url_rewrite_same('https://example.test/index.php?page=gallery&public_path=Trips%2FPrague', gallery_public_url($gallery), 'manual disabled gallery fallback URL');
    assert_url_rewrite_same('https://example.test/index.php?page=gallery&public_path=Trips%2FPrague%2Fevening-flight', image_public_url($image, $gallery), 'manual disabled image fallback URL');
    assert_url_rewrite_same('https://example.test/index.php?page=public_media&public_path=Trips%2FPrague%2Fevening-flight', image_public_media_url($image, $gallery), 'manual disabled media fallback URL');
    assert_url_rewrite_same('https://example.test/index.php?page=public_thumb&public_path=Trips%2FPrague%2Fevening-flight&size=300&format=webp', image_public_thumbnail_url($image, $gallery, 300, 'webp'), 'manual disabled thumbnail fallback URL');

    $GLOBALS['cms_app_settings_cache'] = ['url_rewrite_enabled' => '1'];
    $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
    $_SERVER['REQUEST_URI'] = '/gallery/Trips/Prague/';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    assert_url_rewrite_same(true, url_rewrite_should_emit_clean_urls(), 'default enabled emit clean URLs');
    assert_url_rewrite_same('https://example.test/tag/friedrichshafen', url_for('tag', ['slug' => 'friedrichshafen']), 'default enabled tag clean URL');
    assert_url_rewrite_same('https://example.test/gallery/Trips/Prague/evening-flight/', image_public_url($image, $gallery), 'default enabled image clean URL');
    assert_url_rewrite_same('https://example.test/gallery/Trips/Prague/evening-flight/media', image_public_media_url($image, $gallery), 'default enabled media clean URL');
    assert_url_rewrite_same('https://example.test/gallery/Trips/Prague/evening-flight/thumb-300.webp', image_public_thumbnail_url($image, $gallery, 300, 'webp'), 'default enabled thumbnail clean URL');
    assert_url_rewrite_same(
        'https://example.test/gallery/testovaci-fotky',
        rtrim(gallery_public_url([
            'url_path' => '',
            'folder_path' => 'Testovací fotky',
            'slug' => 'testovaci-fotky',
            'title' => 'Testovací fotky',
        ]), '/'),
        'missing public path uses safe stored slug instead of physical folder name'
    );
} finally {
    remove_url_rewrite_test_root($supportedRoot);
    remove_url_rewrite_test_root($unsupportedRoot);
}

echo "URL rewrite settings tests passed.\n";
