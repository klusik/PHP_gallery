<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/browser_upload_settings_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies pure setting normalization for the browser-side upload
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

use function Gallery\Services\browser_thumbnail_rebuild_expected_variant_count;
use function Gallery\Services\browser_thumbnail_rebuild_megabytes_to_bytes;
use function Gallery\Services\browser_thumbnail_rebuild_normalized_formats;
use function Gallery\Services\browser_upload_batch_target_bytes;
use function Gallery\Services\browser_upload_effective_batch_target_bytes;
use function Gallery\Services\browser_upload_megabytes_to_bytes;
use function Gallery\Services\browser_upload_normalize_settings;
use function Gallery\Services\browser_upload_php_size_to_bytes;

require_once __DIR__ . '/../app/services/browser_uploads.php';
require_once __DIR__ . '/../app/services/browser_thumbnail_rebuild.php';

/**
 * Handle browser upload test assert.
 *
 * Used by the project test harness.
 *
 * @param bool $condition Condition value.
 * @param string $message Message value.
 */
function browser_upload_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$defaults = browser_upload_normalize_settings([]);
browser_upload_test_assert($defaults['enabled'] === true, 'browser upload is enabled by default');
browser_upload_test_assert($defaults['default_worker_count'] === 8, 'default worker count is 8');
browser_upload_test_assert($defaults['max_worker_count'] === 32, 'default maximum worker count is 32');
browser_upload_test_assert($defaults['hard_worker_cap'] === 32, 'default hard worker cap is 32');
browser_upload_test_assert(abs($defaults['zip_size_threshold_ratio'] - 0.80) < 0.0001, 'default ZIP threshold ratio is 0.80');
browser_upload_test_assert($defaults['max_items_per_batch'] === 8, 'default ZIP item count cap is 8');
browser_upload_test_assert($defaults['max_zip_batch_bytes'] === 25165824, 'default absolute ZIP cap is 24 MB');
browser_upload_test_assert($defaults['thumbnail_rebuild_source_chunk_bytes'] === 536870912, 'default thumbnail rebuild source chunk is 512 MB');

$disabled = browser_upload_normalize_settings(['enabled' => '0']);
browser_upload_test_assert($disabled['enabled'] === false, 'disabled setting normalizes to false');

$bounded = browser_upload_normalize_settings([
    'default_worker_count' => '99',
    'max_worker_count' => '64',
    'hard_worker_cap' => '40',
    'zip_size_threshold_ratio' => '2.0',
    'max_items_per_batch' => '500',
    'max_zip_batch_bytes' => (string) (512 * 1024 * 1024),
    'thumbnail_rebuild_source_chunk_bytes' => (string) (6 * 1024 * 1024 * 1024),
]);
browser_upload_test_assert($bounded['hard_worker_cap'] === 32, 'hard cap cannot exceed 32');
browser_upload_test_assert($bounded['max_worker_count'] === 32, 'maximum worker count cannot exceed hard cap');
browser_upload_test_assert($bounded['default_worker_count'] === 32, 'default worker count cannot exceed maximum worker count');
browser_upload_test_assert(abs($bounded['zip_size_threshold_ratio'] - 0.95) < 0.0001, 'ZIP ratio upper bound is enforced');
browser_upload_test_assert($bounded['max_items_per_batch'] === 64, 'ZIP item count upper bound is enforced');
browser_upload_test_assert($bounded['max_zip_batch_bytes'] === 134217728, 'absolute ZIP cap upper bound is enforced');
browser_upload_test_assert($bounded['thumbnail_rebuild_source_chunk_bytes'] === 3221225472, 'thumbnail rebuild source chunk upper bound is enforced');

$minimums = browser_upload_normalize_settings([
    'default_worker_count' => '-5',
    'max_worker_count' => '0',
    'hard_worker_cap' => '-1',
    'zip_size_threshold_ratio' => '0.01',
    'max_items_per_batch' => '-8',
    'max_zip_batch_bytes' => '2048',
    'thumbnail_rebuild_source_chunk_bytes' => '2048',
]);
browser_upload_test_assert($minimums['hard_worker_cap'] === 1, 'hard cap minimum is 1');
browser_upload_test_assert($minimums['max_worker_count'] === 1, 'maximum worker minimum is 1');
browser_upload_test_assert($minimums['default_worker_count'] === 1, 'default worker minimum is 1');
browser_upload_test_assert(abs($minimums['zip_size_threshold_ratio'] - 0.10) < 0.0001, 'ZIP ratio lower bound is enforced');
browser_upload_test_assert($minimums['max_items_per_batch'] === 1, 'ZIP item count lower bound is enforced');
browser_upload_test_assert($minimums['max_zip_batch_bytes'] === 1048576, 'absolute ZIP cap lower bound is enforced');
browser_upload_test_assert($minimums['thumbnail_rebuild_source_chunk_bytes'] === 16777216, 'thumbnail rebuild source chunk lower bound is enforced');

browser_upload_test_assert(browser_upload_php_size_to_bytes('128M') === 134217728, 'PHP megabyte upload shorthand is parsed');
browser_upload_test_assert(browser_upload_php_size_to_bytes('2G') === 2147483648, 'PHP gigabyte upload shorthand is parsed');
browser_upload_test_assert(browser_upload_batch_target_bytes(1000000, 0.80) === 800000, 'batch target uses configured ratio under limit');
browser_upload_test_assert(browser_upload_batch_target_bytes(1000000, 0.99) < 1000000, 'batch target keeps safety reserve under upload limit');
browser_upload_test_assert(browser_upload_effective_batch_target_bytes(100000000, 0.80, 8 * 1024 * 1024) === 8388608, 'effective batch target respects absolute ZIP cap');
browser_upload_test_assert(browser_upload_megabytes_to_bytes('24', 1) === 25165824, 'megabyte ZIP cap input is converted to bytes');
browser_upload_test_assert(browser_thumbnail_rebuild_megabytes_to_bytes('512') === 536870912, 'thumbnail rebuild megabyte input is converted to bytes');
browser_upload_test_assert(browser_thumbnail_rebuild_megabytes_to_bytes('3072') === 3221225472, 'thumbnail rebuild megabyte input respects the 3 GB hard cap');
browser_upload_test_assert(browser_thumbnail_rebuild_normalized_formats(['WEBP', 'jpg', 'webp', 'png']) === ['webp', 'jpg'], 'thumbnail rebuild format normalization keeps supported unique formats');
browser_upload_test_assert(browser_thumbnail_rebuild_normalized_formats([], false) === [], 'thumbnail rebuild format normalization can preserve empty target policy');
browser_upload_test_assert(browser_thumbnail_rebuild_expected_variant_count(['webp']) === 6, 'thumbnail rebuild expected variant count follows one-format policy');
browser_upload_test_assert(browser_thumbnail_rebuild_expected_variant_count(['jpg', 'webp']) === 12, 'thumbnail rebuild expected variant count follows compatibility policy');

$browserUploadSource = file_get_contents(__DIR__ . '/../public/assets/gallery-modules/admin-browser-upload.js');
$browserWorkerSource = file_get_contents(__DIR__ . '/../public/assets/gallery-modules/browser-image-worker.js');
$sidePanelSource = file_get_contents(__DIR__ . '/../public/assets/gallery-modules/admin-side-panel.js');
$uploadControllerSource = file_get_contents(__DIR__ . '/../app/controllers/admin_uploads.php');
browser_upload_test_assert(is_string($browserUploadSource) && str_contains($browserUploadSource, 'expandBrowserUploadArchives') && str_contains($browserUploadSource, "action: 'extractUploadZip'"), 'browser upload expands selected ZIP archives before image preparation');
browser_upload_test_assert(is_string($browserWorkerSource) && str_contains($browserWorkerSource, '0x02014b50') && str_contains($browserWorkerSource, "DecompressionStream('deflate-raw')"), 'browser worker parses central-directory entries and supports normal Deflate ZIP payloads');
browser_upload_test_assert(str_contains((string) $browserWorkerSource, 'safeUploadZipImagePath') && str_contains((string) $browserWorkerSource, 'uncompressedSize > compressedSize * 250'), 'browser ZIP extraction rejects unsafe paths and suspicious expansion ratios');
browser_upload_test_assert(is_string($sidePanelSource) && str_contains($sidePanelSource, 'browserUploadZipSelected(form) && !browserUploadRequested(form)'), 'ZIP selections cannot fall through to classic PHP upload');
browser_upload_test_assert(is_string($uploadControllerSource) && str_contains($uploadControllerSource, "',.zip,application/zip,application/x-zip-compressed'") && str_contains($uploadControllerSource, 'admin_browser_upload_accept_value()'), 'browser-enabled upload inputs advertise ZIP selection');

fwrite(STDOUT, "browser_upload_settings_test passed\n");
