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
 *   - Cover recursive manifest normalization and package planning
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
use function Gallery\Services\gallery_migration_manifest_asset_refs;
use function Gallery\Services\gallery_migration_manifest_galleries;
use function Gallery\Services\gallery_migration_package_assets_from_json;
use function Gallery\Services\gallery_migration_package_plan;
use function Gallery\Services\gallery_migration_validate_manifest;

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

/**
 * Require one migration helper to reject invalid input.
 *
 * @param callable $operation Operation expected to throw.
 * @param string $label Label value.
 */
function assert_gallery_migration_throws(callable $operation, string $label): void
{
    try {
        $operation();
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException($label . ' did not reject invalid input');
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

$original = ['scope' => 'image', 'kind' => 'original', 'source_gallery_id' => 10, 'source_image_id' => 100, 'file_size' => 60, 'relative_path' => 'root.jpg'];
$thumbnail = ['scope' => 'image', 'kind' => 'thumbnail', 'source_gallery_id' => 10, 'source_image_id' => 100, 'size' => 800, 'format' => 'webp', 'file_size' => 20, 'relative_path' => 'root.jpg'];
$childOriginal = ['scope' => 'image', 'kind' => 'original', 'source_gallery_id' => 11, 'source_image_id' => 200, 'file_size' => 50, 'relative_path' => 'child.jpg'];
$branding = ['scope' => 'gallery', 'kind' => 'branding', 'source_gallery_id' => 11, 'file_size' => 10, 'relative_path' => 'branding/logo.png'];
$treeManifest = [
    'protocol_version' => 2,
    'app_version' => '0.96',
    'migration_id' => 'tree-fixture',
    'source_gallery_id' => 10,
    'include_subgalleries' => true,
    'galleries' => [
        ['source_id' => 10, 'parent_source_id' => 0, 'gallery' => ['title' => 'Root'], 'images' => [['source_id' => 100, 'source_gallery_id' => 10, 'relative_path' => 'root.jpg', 'assets' => [$original, $thumbnail]]]],
        ['source_id' => 11, 'parent_source_id' => 10, 'gallery' => ['title' => 'Child'], 'images' => [['source_id' => 200, 'source_gallery_id' => 11, 'relative_path' => 'child.jpg', 'assets' => [$childOriginal]]], 'gallery_assets' => [$branding]],
    ],
];

assert_gallery_migration_same([10, 11], array_map(static fn (array $entry): int => (int) $entry['source_id'], gallery_migration_manifest_galleries($treeManifest)), 'recursive manifest keeps parent-first galleries');
assert_gallery_migration_same([10, 10, 11, 11], array_map(static fn (array $asset): int => (int) $asset['source_gallery_id'], gallery_migration_manifest_asset_refs($treeManifest)), 'recursive assets retain gallery ownership');
gallery_migration_validate_manifest($treeManifest);

$plan = gallery_migration_package_plan($treeManifest, 100, 200);
assert_gallery_migration_same(2, count($plan), 'soft package target creates bounded packages');
assert_gallery_migration_same(2, $plan[0]['asset_count'], 'original and thumbnails remain atomic');
assert_gallery_migration_same(80, $plan[0]['source_bytes'], 'atomic image package byte count');
assert_gallery_migration_same($plan, gallery_migration_package_plan($treeManifest, 100, 200), 'package plan is deterministic');

assert_gallery_migration_same([$original], gallery_migration_package_assets_from_json(json_encode([$original], JSON_THROW_ON_ERROR)), 'package JSON preserves asset descriptors');
assert_gallery_migration_throws(static fn (): array => gallery_migration_package_assets_from_json('[]'), 'empty package JSON');
assert_gallery_migration_throws(static fn (): array => gallery_migration_package_plan($treeManifest, 100, 70), 'atomic package above hard limit');

$invalidTree = $treeManifest;
$invalidTree['galleries'][1]['parent_source_id'] = 999;
assert_gallery_migration_throws(static function () use ($invalidTree): void {
    gallery_migration_validate_manifest($invalidTree);
}, 'orphaned recursive gallery');

$nonRecursiveTree = $treeManifest;
$nonRecursiveTree['include_subgalleries'] = false;
assert_gallery_migration_throws(static function () use ($nonRecursiveTree): void {
    gallery_migration_validate_manifest($nonRecursiveTree);
}, 'non-recursive manifest with descendants');

echo "gallery migration model tests passed\n";
