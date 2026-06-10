<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/experimental_upload_settings_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies pure setting normalization for the experimental client-side upload
 *   pipeline without requiring a browser or database connection.
 *
 * Responsibilities:
 *   - Check default worker and ZIP threshold values
 *   - Check bounds enforcement for worker controls
 *   - Check disabled feature normalization
 *   - Check batch target derivation from upload limits
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
 *   2026-06-10
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/services/experimental_uploads.php';
require_once __DIR__ . '/../app/services/experimental_thumbnail_rebuild.php';

function experimental_upload_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$defaults = experimental_upload_normalize_settings([]);
experimental_upload_test_assert($defaults['enabled'] === true, 'experimental upload is available by default but still opt-in per form');
experimental_upload_test_assert($defaults['default_worker_count'] === 8, 'default worker count is 8');
experimental_upload_test_assert($defaults['max_worker_count'] === 32, 'default maximum worker count is 32');
experimental_upload_test_assert($defaults['hard_worker_cap'] === 32, 'default hard worker cap is 32');
experimental_upload_test_assert(abs($defaults['zip_size_threshold_ratio'] - 0.80) < 0.0001, 'default ZIP threshold ratio is 0.80');
experimental_upload_test_assert($defaults['max_items_per_batch'] === 8, 'default ZIP item count cap is 8');
experimental_upload_test_assert($defaults['max_zip_batch_bytes'] === 25165824, 'default absolute ZIP cap is 24 MB');
experimental_upload_test_assert($defaults['thumbnail_rebuild_source_chunk_bytes'] === 536870912, 'default thumbnail rebuild source chunk is 512 MB');

$disabled = experimental_upload_normalize_settings(['enabled' => '0']);
experimental_upload_test_assert($disabled['enabled'] === false, 'disabled setting normalizes to false');

$bounded = experimental_upload_normalize_settings([
    'default_worker_count' => '99',
    'max_worker_count' => '64',
    'hard_worker_cap' => '40',
    'zip_size_threshold_ratio' => '2.0',
    'max_items_per_batch' => '500',
    'max_zip_batch_bytes' => (string) (512 * 1024 * 1024),
    'thumbnail_rebuild_source_chunk_bytes' => (string) (6 * 1024 * 1024 * 1024),
]);
experimental_upload_test_assert($bounded['hard_worker_cap'] === 32, 'hard cap cannot exceed 32');
experimental_upload_test_assert($bounded['max_worker_count'] === 32, 'maximum worker count cannot exceed hard cap');
experimental_upload_test_assert($bounded['default_worker_count'] === 32, 'default worker count cannot exceed maximum worker count');
experimental_upload_test_assert(abs($bounded['zip_size_threshold_ratio'] - 0.95) < 0.0001, 'ZIP ratio upper bound is enforced');
experimental_upload_test_assert($bounded['max_items_per_batch'] === 64, 'ZIP item count upper bound is enforced');
experimental_upload_test_assert($bounded['max_zip_batch_bytes'] === 134217728, 'absolute ZIP cap upper bound is enforced');
experimental_upload_test_assert($bounded['thumbnail_rebuild_source_chunk_bytes'] === 3221225472, 'thumbnail rebuild source chunk upper bound is enforced');

$minimums = experimental_upload_normalize_settings([
    'default_worker_count' => '-5',
    'max_worker_count' => '0',
    'hard_worker_cap' => '-1',
    'zip_size_threshold_ratio' => '0.01',
    'max_items_per_batch' => '-8',
    'max_zip_batch_bytes' => '2048',
    'thumbnail_rebuild_source_chunk_bytes' => '2048',
]);
experimental_upload_test_assert($minimums['hard_worker_cap'] === 1, 'hard cap minimum is 1');
experimental_upload_test_assert($minimums['max_worker_count'] === 1, 'maximum worker minimum is 1');
experimental_upload_test_assert($minimums['default_worker_count'] === 1, 'default worker minimum is 1');
experimental_upload_test_assert(abs($minimums['zip_size_threshold_ratio'] - 0.10) < 0.0001, 'ZIP ratio lower bound is enforced');
experimental_upload_test_assert($minimums['max_items_per_batch'] === 1, 'ZIP item count lower bound is enforced');
experimental_upload_test_assert($minimums['max_zip_batch_bytes'] === 1048576, 'absolute ZIP cap lower bound is enforced');
experimental_upload_test_assert($minimums['thumbnail_rebuild_source_chunk_bytes'] === 16777216, 'thumbnail rebuild source chunk lower bound is enforced');

experimental_upload_test_assert(experimental_upload_php_size_to_bytes('128M') === 134217728, 'PHP megabyte upload shorthand is parsed');
experimental_upload_test_assert(experimental_upload_php_size_to_bytes('2G') === 2147483648, 'PHP gigabyte upload shorthand is parsed');
experimental_upload_test_assert(experimental_upload_batch_target_bytes(1000000, 0.80) === 800000, 'batch target uses configured ratio under limit');
experimental_upload_test_assert(experimental_upload_batch_target_bytes(1000000, 0.99) < 1000000, 'batch target keeps safety reserve under upload limit');
experimental_upload_test_assert(experimental_upload_effective_batch_target_bytes(100000000, 0.80, 8 * 1024 * 1024) === 8388608, 'effective batch target respects absolute ZIP cap');
experimental_upload_test_assert(experimental_upload_megabytes_to_bytes('24', 1) === 25165824, 'megabyte ZIP cap input is converted to bytes');
experimental_upload_test_assert(experimental_thumbnail_rebuild_megabytes_to_bytes('512') === 536870912, 'thumbnail rebuild megabyte input is converted to bytes');
experimental_upload_test_assert(experimental_thumbnail_rebuild_megabytes_to_bytes('3072') === 3221225472, 'thumbnail rebuild megabyte input respects the 3 GB hard cap');
experimental_upload_test_assert(experimental_thumbnail_rebuild_normalized_formats(['WEBP', 'jpg', 'webp', 'png']) === ['webp', 'jpg'], 'thumbnail rebuild format normalization keeps supported unique formats');
experimental_upload_test_assert(experimental_thumbnail_rebuild_normalized_formats([], false) === [], 'thumbnail rebuild format normalization can preserve empty target policy');
experimental_upload_test_assert(experimental_thumbnail_rebuild_expected_variant_count(['webp']) === 6, 'thumbnail rebuild expected variant count follows one-format policy');
experimental_upload_test_assert(experimental_thumbnail_rebuild_expected_variant_count(['jpg', 'webp']) === 12, 'thumbnail rebuild expected variant count follows compatibility policy');

fwrite(STDOUT, "experimental_upload_settings_test passed\n");
