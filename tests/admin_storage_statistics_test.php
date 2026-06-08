<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_storage_statistics_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies pure helper logic for Admin storage statistics.
 *
 * Responsibilities:
 *   - Cover source file extension normalization
 *   - Cover source file-size bucket selection
 *   - Cover chart row sorting and percentage calculation
 *   - Keep the test database-free for plain PHP execution
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

require_once __DIR__ . '/../app/services/admin_storage_statistics.php';

/**
 * Throw when a storage-statistics expectation fails.
 */
function assert_admin_storage_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

assert_admin_storage_same('jpg', admin_storage_statistics_normalize_file_extension('IMG_0001.JPEG'), 'jpeg extension normalizes to jpg');
assert_admin_storage_same('tiff', admin_storage_statistics_normalize_file_extension('scan.TIF'), 'tif extension normalizes to tiff');
assert_admin_storage_same('unknown', admin_storage_statistics_normalize_file_extension('README'), 'missing extension uses unknown bucket');
assert_admin_storage_same('other', admin_storage_statistics_normalize_file_extension('photo.bad-extension-name'), 'long unsafe extension uses other bucket');

assert_admin_storage_same('unknown', admin_storage_statistics_size_bucket_key(0), 'zero-size source uses unknown size bucket');
assert_admin_storage_same('under_1mb', admin_storage_statistics_size_bucket_key(512 * 1024), 'small source uses under 1 MB bucket');
assert_admin_storage_same('1_3mb', admin_storage_statistics_size_bucket_key(2 * 1024 * 1024), '2 MB source uses 1-3 MB bucket');
assert_admin_storage_same('3_8mb', admin_storage_statistics_size_bucket_key(5 * 1024 * 1024), '5 MB source uses 3-8 MB bucket');
assert_admin_storage_same('8_20mb', admin_storage_statistics_size_bucket_key(12 * 1024 * 1024), '12 MB source uses 8-20 MB bucket');
assert_admin_storage_same('20_50mb', admin_storage_statistics_size_bucket_key(30 * 1024 * 1024), '30 MB source uses 20-50 MB bucket');
assert_admin_storage_same('over_50mb', admin_storage_statistics_size_bucket_key(90 * 1024 * 1024), '90 MB source uses 50+ MB bucket');

$groups = [];
admin_storage_statistics_add_group_value($groups, 'jpg', 'JPG', 2, 400, ['extension' => 'jpg']);
admin_storage_statistics_add_group_value($groups, 'webp', 'WEBP', 1, 100, ['extension' => 'webp']);
admin_storage_statistics_add_group_value($groups, 'jpg', 'JPG', 1, 100, ['extension' => 'jpg']);
$rows = admin_storage_statistics_finalize_group_rows($groups, 'bytes');

assert_admin_storage_same('jpg', (string) ($rows[0]['key'] ?? ''), 'largest byte group sorts first');
assert_admin_storage_same(3, (int) ($rows[0]['count'] ?? 0), 'duplicate group counts accumulate');
assert_admin_storage_same(500, (int) ($rows[0]['bytes'] ?? 0), 'duplicate group bytes accumulate');
assert_admin_storage_same(83.3, (float) ($rows[0]['percent'] ?? 0.0), 'percentage calculation uses total grouped bytes');
assert_admin_storage_same(16.7, (float) ($rows[1]['percent'] ?? 0.0), 'smaller row percentage remains stable');

$sourceRows = [
    [
        'image_id' => 1,
        'gallery_id' => 10,
        'image_gallery_id' => 10,
        'relative_path' => 'Trip/IMG_0001.JPG',
        'filename' => 'IMG_0001.JPG',
        'mime_type' => 'image/jpeg',
        'file_size' => 2 * 1024 * 1024,
        'gallery_title' => 'Trip',
        'gallery_folder_path' => 'trip',
    ],
    [
        'image_id' => 2,
        'gallery_id' => 10,
        'image_gallery_id' => 10,
        'relative_path' => 'Trip/RAW_0002.DNG',
        'filename' => 'RAW_0002.DNG',
        'mime_type' => 'image/x-adobe-dng',
        'file_size' => 55 * 1024 * 1024,
        'gallery_title' => 'Trip',
        'gallery_folder_path' => 'trip',
    ],
];
$sourceSummary = admin_storage_statistics_compact_source_summary(admin_storage_statistics_source_summary($sourceRows));
assert_admin_storage_same(2, (int) ($sourceSummary['image_count'] ?? 0), 'source summary counts rows');
assert_admin_storage_same(57 * 1024 * 1024, (int) ($sourceSummary['original_bytes'] ?? 0), 'source summary totals source bytes');
assert_admin_storage_same('Trip/RAW_0002.DNG', (string) ($sourceSummary['largest_original_name'] ?? ''), 'source summary tracks largest source');
assert_admin_storage_same('source-type-dng', (string) (($sourceSummary['type_rows'][0]['key'] ?? '')), 'source type rows sort by bytes');

$generatedSummary = admin_storage_statistics_normalize_generated_summary([
    'thumbnail_bytes' => 300,
    'thumbnail_count' => 2,
    'display_master_bytes' => 700,
    'display_master_count' => 1,
    'scan_errors' => 0,
    'generated_type_groups' => [],
]);
$generatedSummary['generated_bytes'] = 1000;
$snapshot = admin_storage_statistics_snapshot_from_summaries('abc', $sourceSummary, $generatedSummary);
assert_admin_storage_same(57 * 1024 * 1024 + 1000, (int) ($snapshot['total_picture_bytes'] ?? 0), 'snapshot combines source and generated bytes');
assert_admin_storage_same(1000, (int) ($snapshot['generated_bytes'] ?? 0), 'snapshot stores generated bytes');

echo "Admin storage statistics helper tests passed.\n";
