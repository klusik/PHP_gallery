<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/gallery_migration_model_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies gallery migration compatibility and URL normalization helpers.
 *
 * Responsibilities:
 *   - Cover exact-version migration compatibility policy
 *   - Cover endpoint generation from app root and index.php URLs
 *   - Remain executable without a live database
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
 *   2026-05-27
 */

declare(strict_types=1);

use function Gallery\Services\gallery_migration_compatibility_result;
use function Gallery\Services\gallery_migration_endpoint_url;

require_once __DIR__ . '/../app/services/gallery_migration.php';

/**
 * Throw when a migration expectation fails.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual Actual value.
 * @param string $label Label value.
 */
function assert_gallery_migration_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$compatible = gallery_migration_compatibility_result('0.71', '0.71');
assert_gallery_migration_same(true, $compatible['ok'], 'identical versions are compatible');
assert_gallery_migration_same('exact', $compatible['policy'], 'compatibility policy is exact version');

$incompatible = gallery_migration_compatibility_result('0.71', '0.72');
assert_gallery_migration_same(false, $incompatible['ok'], 'different versions are rejected');

assert_gallery_migration_same(
    'https://example.test/Galerie/index.php?page=gallery_migration_manifest',
    gallery_migration_endpoint_url('https://example.test/Galerie', 'gallery_migration_manifest'),
    'root app URL endpoint'
);

assert_gallery_migration_same(
    'http://localhost/Galerie/index.php?page=gallery_migration_asset&scope=image&kind=original',
    gallery_migration_endpoint_url('http://localhost/Galerie/index.php?page=upload_automation_upload', 'gallery_migration_asset', ['scope' => 'image', 'kind' => 'original']),
    'existing index route endpoint'
);

assert_gallery_migration_same(
    'http://localhost/Galerie/index.php?page=gallery_migration_manifest',
    gallery_migration_endpoint_url('localhost/Galerie', 'gallery_migration_manifest'),
    'localhost without scheme defaults to http'
);

echo "gallery migration model tests passed\n";
