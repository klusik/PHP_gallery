<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/gallery_metadata_organizer_model_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies metadata-organizer normalization and preview helper behavior without a live database.
 *
 * Responsibilities:
 *   - Cover date-boundary defaults and validation
 *   - Cover phase-1 grouping option normalization
 *   - Cover daily title formatting and image-id extraction
 *   - Cover admin result notice rendering
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
 *   2026-06-16
 */

declare(strict_types=1);

use function Gallery\Services\gallery_metadata_organizer_apply_notice;
use function Gallery\Services\gallery_metadata_organizer_date_title;
use function Gallery\Services\gallery_metadata_organizer_default_max_date;
use function Gallery\Services\gallery_metadata_organizer_default_min_date;
use function Gallery\Services\gallery_metadata_organizer_group_image_ids;
use function Gallery\Services\gallery_metadata_organizer_options;
use function Gallery\Services\gallery_metadata_organizer_schema_ready;

require_once __DIR__ . '/support/namespaced_shims.php';
require_once __DIR__ . '/../app/services/gallery_dates.php';
require_once __DIR__ . '/../app/services/gallery_metadata_organizer.php';

/**
 * Throw when a metadata-organizer expectation fails.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual Actual value.
 * @param string $label Label value.
 */
function assert_metadata_organizer_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/**
 * Throw when a string does not contain an expected substring.
 *
 * @param string $needle Needle value.
 * @param string $haystack Haystack value.
 * @param string $label Label value.
 */
function assert_metadata_organizer_contains(string $needle, string $haystack, string $label): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($label . ' did not contain ' . var_export($needle, true) . '. Text: ' . $haystack);
    }
}

assert_metadata_organizer_same(true, gallery_metadata_organizer_schema_ready(), 'schema readiness sees EXIF date column through the shim');

$options = gallery_metadata_organizer_options([]);
assert_metadata_organizer_same('date', $options['primary'], 'default primary grouping is date');
assert_metadata_organizer_same('none', $options['secondary'], 'default secondary grouping is none');
assert_metadata_organizer_same(gallery_metadata_organizer_default_min_date(), $options['min_date'], 'default minimum date is applied');
assert_metadata_organizer_same(gallery_metadata_organizer_default_max_date(), $options['max_date'], 'default maximum date is applied');

$options = gallery_metadata_organizer_options([
    'primary_grouping' => 'location',
    'secondary_grouping' => 'location',
    'min_date' => '2020-01-01',
    'max_date' => '2020-01-31',
]);
assert_metadata_organizer_same('date', $options['primary'], 'unsupported primary grouping falls back to date');
assert_metadata_organizer_same('none', $options['secondary'], 'phase-1 location secondary stays disabled');
assert_metadata_organizer_same('2020-01-01', $options['min_date'], 'custom minimum date is preserved');
assert_metadata_organizer_same('2020-01-31', $options['max_date'], 'custom maximum date is preserved');

$invalidRangeRejected = false;
try {
    gallery_metadata_organizer_options(['min_date' => '2020-02-01', 'max_date' => '2020-01-31']);
} catch (InvalidArgumentException) {
    $invalidRangeRejected = true;
}
assert_metadata_organizer_same(true, $invalidRangeRejected, 'maximum date before minimum is rejected');

assert_metadata_organizer_same('2. 1. 2020', gallery_metadata_organizer_date_title('2020-01-02'), 'date title uses the shared Czech-style date label');
assert_metadata_organizer_same([10, 11], gallery_metadata_organizer_group_image_ids([
    'images' => [
        ['id' => 10],
        ['id' => 0],
        ['id' => 11],
        ['id' => 10],
    ],
]), 'image ids are positive and unique');

$notice = gallery_metadata_organizer_apply_notice([
    'created_galleries' => 2,
    'reused_galleries' => 1,
    'moved_images' => 7,
    'requested_images' => 8,
    'originals_moved' => 7,
    'derivatives_moved' => 21,
    'failures' => ['2. 1. 2020: destination original file already exists.'],
]);
assert_metadata_organizer_contains('Created 2', $notice, 'notice includes created count');
assert_metadata_organizer_contains('moved 7 of 8', $notice, 'notice includes moved count');
assert_metadata_organizer_contains('Warnings:', $notice, 'notice includes warnings when failures exist');

echo "Gallery metadata organizer model tests passed.\n";
