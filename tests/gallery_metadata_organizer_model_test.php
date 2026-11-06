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
 *   - Cover structured EXIF capture-date schema readiness without a live database
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

namespace Gallery\Services {
    /**
     * Simulate an image move that can fail after recording an effect.
     *
     * @param int $sourceGalleryId Fixture source identifier.
     * @param int $destinationGalleryId Fixture destination identifier.
     * @param list<int> $imageIds Fixture image identifiers.
     * @param array{defer_maintenance?:bool} $options Fixture maintenance choice.
     * @return array{requested:int,moved:int,originals_moved:int,derivatives_moved:int,failures:list<string>,source_cover_image_id:null,destination_cover_image_id:null} Simulated move result.
     * @throws \RuntimeException When the simulated post-effect callback fails.
     */
    function move_gallery_images(
        int $sourceGalleryId,
        int $destinationGalleryId,
        array $imageIds,
        array $options = [],
    ): array {
        $GLOBALS['organizer_simulated_destination'] = $imageIds;
        if ($GLOBALS['organizer_move_case'] === 'post_effect_exception') {
            throw new \RuntimeException('Fixture maintenance failure.');
        }
        return [
            'requested' => count($imageIds), 'moved' => count($imageIds),
            'originals_moved' => count($imageIds), 'derivatives_moved' => 0,
            'failures' => $GLOBALS['organizer_move_case'] === 'partial'
                ? ['Fixture partial result.'] : [],
            'source_cover_image_id' => null, 'destination_cover_image_id' => null,
        ];
    }
}

namespace {

use function Gallery\Services\gallery_metadata_organizer_apply_notice;
use function Gallery\Services\gallery_metadata_organizer_date_title;
use function Gallery\Services\gallery_metadata_organizer_default_max_date;
use function Gallery\Services\gallery_metadata_organizer_default_min_date;
use function Gallery\Services\gallery_metadata_organizer_group_image_ids;
use function Gallery\Services\gallery_metadata_organizer_options;
use function Gallery\Services\gallery_metadata_organizer_schema_ready;
use function Gallery\Services\schema_inspection_set_query_executor_for_tests;

require_once __DIR__ . '/support/namespaced_shims.php';
require_once __DIR__ . '/../app/services/schema_inspection.php';
require_once __DIR__ . '/../app/services/presentation_schema_policy.php';
require_once __DIR__ . '/../app/services/gallery_dates.php';
require_once __DIR__ . '/../app/services/gallery_metadata_organizer.php';

/**
 * Throw when a metadata-organizer expectation fails.
 *
 * @template TExpected
 * @template TActual
 * @param TExpected $expected Expected value, including its collection shape when applicable.
 * @param TActual $actual Actual value, including its collection shape when applicable.
 * @param string $label Label value.
 * @return void Throws when strict comparison fails.
 */
function assert_metadata_organizer_same(
    mixed $expected,
    mixed $actual,
    string $label,
): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $label . ' expected ' . var_export($expected, true) . ', got '
                . var_export($actual, true),
        );
    }
}

/**
 * Throw when a string does not contain an expected substring.
 *
 * @param string $needle Needle value.
 * @param string $haystack Haystack value.
 * @param string $label Label value.
 * @return void Throws when the expected substring is absent.
 */
function assert_metadata_organizer_contains(
    string $needle,
    string $haystack,
    string $label,
): void {
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException(
            $label
                . ' did not contain '
                . var_export($needle, true)
                . '. Text: '
                . $haystack,
        );
    }
}

schema_inspection_set_query_executor_for_tests(
    static fn(
        string $objectType,
        string $table,
        string $object,
        string $token = '',
    ): bool => true,
);
assert_metadata_organizer_same(
    true,
    gallery_metadata_organizer_schema_ready(),
    'schema readiness sees the EXIF date column through structured inspection',
);

$options = gallery_metadata_organizer_options([]);
assert_metadata_organizer_same(
    'date',
    $options['primary'],
    'default primary grouping is date',
);
assert_metadata_organizer_same(
    'none',
    $options['secondary'],
    'default secondary grouping is none',
);
assert_metadata_organizer_same(
    gallery_metadata_organizer_default_min_date(),
    $options['min_date'],
    'default minimum date is applied',
);
assert_metadata_organizer_same(
    gallery_metadata_organizer_default_max_date(),
    $options['max_date'],
    'default maximum date is applied',
);

$options = gallery_metadata_organizer_options([
    'primary_grouping' => 'location',
    'secondary_grouping' => 'location',
    'min_date' => '2020-01-01',
    'max_date' => '2020-01-31',
]);
assert_metadata_organizer_same(
    'date',
    $options['primary'],
    'unsupported primary grouping falls back to date',
);
assert_metadata_organizer_same(
    'none',
    $options['secondary'],
    'phase-1 location secondary stays disabled',
);
assert_metadata_organizer_same(
    '2020-01-01',
    $options['min_date'],
    'custom minimum date is preserved',
);
assert_metadata_organizer_same(
    '2020-01-31',
    $options['max_date'],
    'custom maximum date is preserved',
);

$invalidRangeRejected = false;
try {
    gallery_metadata_organizer_options([
        'min_date' => '2020-02-01',
        'max_date' => '2020-01-31',
    ]);
} catch (InvalidArgumentException) {
    $invalidRangeRejected = true;
}
assert_metadata_organizer_same(
    true,
    $invalidRangeRejected,
    'maximum date before minimum is rejected',
);

assert_metadata_organizer_same(
    '2. 1. 2020',
    gallery_metadata_organizer_date_title('2020-01-02'),
    'date title uses the shared Czech-style date label',
);
assert_metadata_organizer_same(
    [10, 11],
    gallery_metadata_organizer_group_image_ids([
        'images' => [
            ['id' => 10],
            ['id' => 0],
            ['id' => 11],
            ['id' => 10],
        ],
    ]),
    'image ids are positive and unique',
);

$notice = gallery_metadata_organizer_apply_notice([
    'created_galleries' => 2,
    'reused_galleries' => 1,
    'moved_images' => 7,
    'requested_images' => 8,
    'originals_moved' => 7,
    'derivatives_moved' => 21,
    'failures' => ['2. 1. 2020: destination original file already exists.'],
]);
assert_metadata_organizer_contains(
    'Created 2',
    $notice,
    'notice includes created count',
);
assert_metadata_organizer_contains(
    'moved 7 of 8',
    $notice,
    'notice includes moved count',
);
assert_metadata_organizer_contains(
    'Warnings:',
    $notice,
    'notice includes warnings when failures exist',
);

schema_inspection_set_query_executor_for_tests(null);

// The compound operations retain one shared writer lease. No failure branch
// may delete a destination after a simulated post-effect or partial result.
$organizerSource = (string) file_get_contents(
    __DIR__ . '/../app/services/gallery_metadata_organizer.php',
);
assert_metadata_organizer_same(
    2,
    substr_count($organizerSource, 'gallery_linked_reservation_assert_admin_write();'),
    'Both organizer entrypoints check linked-create reservations',
);
assert_metadata_organizer_same(
    2,
    substr_count($organizerSource, '$moveResult = gallery_metadata_organizer_move_checked('),
    'Both organizer modes use the checked move owner',
);
assert_metadata_organizer_same(
    0,
    substr_count($organizerSource, 'delete_gallery_subtrees([$destinationGalleryId]);'),
    'An uncertain destination must never be deleted by the organizer',
);
foreach (['post_effect_exception', 'partial'] as $case) {
    $GLOBALS['organizer_move_case'] = $case;
    $GLOBALS['organizer_simulated_destination'] = [];
    $refused = false;
    try {
        \Gallery\Services\gallery_metadata_organizer_move_checked(1, 2, [17], true);
    } catch (RuntimeException $exception) {
        $refused = str_contains($exception->getMessage(), 'uncertain');
    }
    assert_metadata_organizer_same(true, $refused,
        'A post-effect exception or partial move must remain uncertain');
    assert_metadata_organizer_same([17], $GLOBALS['organizer_simulated_destination'],
        'The simulated moved original remains in the retained destination');
}
$GLOBALS['organizer_move_case'] = 'success';
assert_metadata_organizer_same(1,
    \Gallery\Services\gallery_metadata_organizer_move_checked(1, 2, [17], false)['moved'],
    'A verified move still reports its completed count');
echo "Gallery metadata organizer model tests passed.\n";
}
