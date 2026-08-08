<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/duplicate_photo_detector_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies duplicate-photo matching, scope resolution, ordering, and read-only behavior.
 *
 * Responsibilities:
 *   - Cover exact SHA-256 duplicate grouping and checksum differences
 *   - Cover conservative file-size and normalized EXIF candidate rules
 *   - Cover missing EXIF handling and deterministic ordering
 *   - Cover selected-gallery branch, global, missing, and inaccessible scopes
 *   - Verify matching helpers do not modify image input data or test files
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
 */

declare(strict_types=1);

use function Gallery\Services\duplicate_photo_detector_exif_fingerprint;
use function Gallery\Services\duplicate_photo_detector_finalize_job;
use function Gallery\Services\duplicate_photo_detector_group_references;
use function Gallery\Services\duplicate_photo_detector_group_signals;
use function Gallery\Services\duplicate_photo_detector_job_allows_gallery;
use function Gallery\Services\duplicate_photo_detector_job_contains_image;
use function Gallery\Services\duplicate_photo_detector_job_contains_pair;
use function Gallery\Services\duplicate_photo_detector_job_gallery_ids;
use function Gallery\Services\duplicate_photo_detector_pair_references;
use function Gallery\Services\duplicate_photo_detector_process_rows;
use function Gallery\Services\duplicate_photo_detector_prune_image_from_job;
use function Gallery\Services\duplicate_photo_detector_resolve_scope;
use function Gallery\Services\duplicate_photo_detector_same_file_size;
use function Gallery\Services\duplicate_photo_ledger_empty_snapshot;
use function Gallery\Services\duplicate_photo_ledger_pair_key;

require_once __DIR__ . '/../app/services/duplicate_photo_ledger.php';
require_once __DIR__ . '/../app/services/duplicate_photo_detector.php';

/**
 * Throw when a duplicate-detector expectation differs from the actual value.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual Actual value.
 * @param string $label Assertion label.
 */
function assert_duplicate_detector_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/**
 * Throw when a duplicate-detector condition is false.
 *
 * @param bool $condition Condition to verify.
 * @param string $label Assertion label.
 */
function assert_duplicate_detector_true(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label . ' expected true.');
    }
}

/**
 * Return an empty detector accumulator suitable for pure matching tests.
 *
 * @return array<string,mixed> Mutable matching job state.
 */
function duplicate_detector_test_job(): array
{
    return [
        'exact_first' => [],
        'exact_groups' => [],
        'possible_first' => [],
        'possible_groups' => [],
    ];
}

/**
 * Return one deterministic 64-character checksum for tests.
 *
 * @param string $character Hex character to repeat.
 * @return string SHA-256-shaped checksum.
 */
function duplicate_detector_test_checksum(string $character): string
{
    return str_repeat($character, 64);
}

$selectedScope = duplicate_photo_detector_resolve_scope(['id' => 42], false, true);
assert_duplicate_detector_same(['gallery_id' => 42, 'search_all' => false], $selectedScope, 'selected-gallery scope remains local');
$globalScope = duplicate_photo_detector_resolve_scope(['id' => 42], true, true);
assert_duplicate_detector_same(['gallery_id' => 42, 'search_all' => true], $globalScope, 'global scope is explicitly enabled for an administrator');

$branchJobScope = duplicate_photo_detector_job_gallery_ids([
    'gallery_id' => 42,
    'gallery_ids' => [44, 42, 43, 43],
]);
assert_duplicate_detector_same([42, 43, 44], $branchJobScope, 'local job keeps the selected gallery and descendant gallery ids');
$legacyLocalJobScope = duplicate_photo_detector_job_gallery_ids(['gallery_id' => 42]);
assert_duplicate_detector_same([42], $legacyLocalJobScope, 'older local jobs safely fall back to the selected gallery only');

$missingRejected = false;
try {
    duplicate_photo_detector_resolve_scope(null, false, true);
} catch (InvalidArgumentException) {
    $missingRejected = true;
}
assert_duplicate_detector_true($missingRejected, 'missing gallery is rejected');

$inaccessibleRejected = false;
try {
    duplicate_photo_detector_resolve_scope(['id' => 42], true, false);
} catch (InvalidArgumentException) {
    $inaccessibleRejected = true;
}
assert_duplicate_detector_true($inaccessibleRejected, 'inaccessible gallery scope is rejected');

$checksumA = duplicate_detector_test_checksum('a');
$checksumB = duplicate_detector_test_checksum('b');
$exactRows = [
    ['id' => 1, 'checksum_sha256' => strtoupper($checksumA), 'file_size' => 1234],
    ['id' => 2, 'checksum_sha256' => $checksumA, 'file_size' => 1234],
];
$job = duplicate_detector_test_job();
duplicate_photo_detector_process_rows($job, $exactRows);
$job = duplicate_photo_detector_finalize_job($job);
assert_duplicate_detector_same([1, 2], $job['exact_groups'][$checksumA] ?? null, 'matching non-empty checksum creates exact group');

$job = duplicate_detector_test_job();
duplicate_photo_detector_process_rows($job, [
    ['id' => 3, 'checksum_sha256' => $checksumA, 'file_size' => 1234],
    ['id' => 4, 'checksum_sha256' => $checksumB, 'file_size' => 1234],
]);
$job = duplicate_photo_detector_finalize_job($job);
assert_duplicate_detector_same([], $job['exact_groups'], 'different checksums are not exact duplicates');

$job = duplicate_detector_test_job();
duplicate_photo_detector_process_rows($job, [
    ['id' => 5, 'file_size' => 98765],
    ['id' => 6, 'file_size' => 98765],
]);
$job = duplicate_photo_detector_finalize_job($job);
assert_duplicate_detector_same([], $job['possible_groups'], 'file size alone does not create a duplicate group');

$job = duplicate_detector_test_job();
duplicate_photo_detector_process_rows($job, [
    ['id' => 7, 'checksum_sha256' => $checksumA, 'file_size' => 4000, 'exif_taken_at' => '2026-08-01 12:00:00'],
    ['id' => 8, 'checksum_sha256' => $checksumB, 'file_size' => 4000, 'exif_taken_at' => '2026-08-01 12:00:00'],
]);
$job = duplicate_photo_detector_finalize_job($job);
assert_duplicate_detector_same([], $job['exact_groups'], 'matching size plus incomplete EXIF does not produce a false exact match');
assert_duplicate_detector_same([], $job['possible_groups'], 'matching size plus incomplete EXIF does not produce a possible group');

$normalizedExifRows = [
    [
        'id' => 9,
        'file_size' => 54321,
        'exif_taken_at' => '2026:08:01 12:34:56',
        'exif_camera_make' => ' Canon ',
        'exif_camera_model' => 'EOS R5',
        'exif_lens_model' => 'RF 24-70mm F2.8 L IS USM',
        'exif_focal_length' => '50 mm',
        'exif_aperture' => '2.80',
        'exif_exposure_time' => '1/250',
        'exif_iso' => '100',
    ],
    [
        'id' => 10,
        'file_size' => 54321,
        'exif_taken_at' => '2026-08-01 12:34:56',
        'exif_camera_make' => 'canon',
        'exif_camera_model' => ' eos   r5 ',
        'exif_lens_model' => 'rf 24-70MM f2.8 l is usm',
        'exif_focal_length' => '50.0mm',
        'exif_aperture' => 2.8,
        'exif_exposure_time' => '0.004',
        'exif_iso' => 100.0,
    ],
];
$fingerprintA = duplicate_photo_detector_exif_fingerprint($normalizedExifRows[0]);
$fingerprintB = duplicate_photo_detector_exif_fingerprint($normalizedExifRows[1]);
assert_duplicate_detector_true(is_array($fingerprintA) && !empty($fingerprintA['complete']), 'first normalized EXIF fingerprint is sufficiently complete');
assert_duplicate_detector_same($fingerprintA['canonical'] ?? null, $fingerprintB['canonical'] ?? null, 'normalized EXIF values have a deterministic matching fingerprint');
$job = duplicate_detector_test_job();
duplicate_photo_detector_process_rows($job, $normalizedExifRows);
$job = duplicate_photo_detector_finalize_job($job);
assert_duplicate_detector_same(1, count($job['possible_groups']), 'matching normalized EXIF creates one possible group');
assert_duplicate_detector_same([9, 10], array_values($job['possible_groups'])[0] ?? null, 'possible group contains the normalized EXIF matches');

$differentSizeExifRows = [
    [
        'id' => 15,
        'file_size' => 50000,
        'exif_taken_at' => '2026-08-02 13:14:15',
        'exif_camera_make' => 'Canon',
        'exif_camera_model' => 'EOS R5',
        'exif_lens_model' => 'RF 24-70mm F2.8 L IS USM',
        'exif_focal_length' => '35 mm',
        'exif_aperture' => '2.8',
        'exif_exposure_time' => '1/2',
        'exif_iso' => 200,
    ],
    [
        'id' => 16,
        'file_size' => 51789,
        'exif_taken_at' => '2026:08:02 13:14:15',
        'exif_camera_make' => ' canon ',
        'exif_camera_model' => 'EOS   R5',
        'exif_lens_model' => 'rf 24-70MM f2.8 l is usm',
        'exif_focal_length' => '35.0mm',
        'exif_aperture' => 'f/2.8',
        'exif_exposure_time' => '0.5 s',
        'exif_iso' => 'ISO 200',
    ],
];
$job = duplicate_detector_test_job();
duplicate_photo_detector_process_rows($job, $differentSizeExifRows);
$job = duplicate_photo_detector_finalize_job($job);
assert_duplicate_detector_same(1, count($job['possible_groups']), 'matching normalized EXIF remains a possible duplicate when file sizes differ');
assert_duplicate_detector_same([15, 16], array_values($job['possible_groups'])[0] ?? null, 'browser/server EXIF formatting differences normalize to the same possible group');
assert_duplicate_detector_same(false, duplicate_photo_detector_same_file_size($differentSizeExifRows), 'different file sizes remain visible as non-matching corroboration');

$sparseExifRows = [
    [
        'id' => 17,
        'file_size' => 61000,
        'exif_taken_at' => '2026-08-03 09:10:11',
        'exif_camera_make' => 'Sony',
        'exif_camera_model' => 'ILCE-7M4',
    ],
    [
        'id' => 18,
        'file_size' => 61234,
        'exif_taken_at' => '2026:08:03 09:10:11',
        'exif_camera_make' => ' sony ',
        'exif_camera_model' => 'ilce-7m4',
    ],
];
$job = duplicate_detector_test_job();
duplicate_photo_detector_process_rows($job, $sparseExifRows);
$job = duplicate_photo_detector_finalize_job($job);
assert_duplicate_detector_same(1, count($job['possible_groups']), 'capture time plus camera make/model is enough for a report-only EXIF candidate');
assert_duplicate_detector_same([17, 18], array_values($job['possible_groups'])[0] ?? null, 'sparse but meaningful matching EXIF is not discarded because file sizes differ');

$strongRows = [
    array_merge($normalizedExifRows[0], ['id' => 13, 'checksum_sha256' => $checksumA, 'width' => 6000, 'height' => 4000]),
    array_merge($normalizedExifRows[1], ['id' => 14, 'checksum_sha256' => $checksumA, 'width' => 6000, 'height' => 4000]),
];
$strongSignals = duplicate_photo_detector_group_signals($strongRows, 'exact');
assert_duplicate_detector_same(true, $strongSignals['strong_corroboration'] ?? false, 'matching checksum, size, dimensions, and meaningful EXIF produce strong corroboration');

$conflictingStrongRows = $strongRows;
$conflictingStrongRows[1]['exif_camera_model'] = 'Different camera model';
$conflictingStrongSignals = duplicate_photo_detector_group_signals($conflictingStrongRows, 'exact');
assert_duplicate_detector_same(false, $conflictingStrongSignals['strong_corroboration'] ?? true, 'conflicting non-empty EXIF prevents strong corroboration');

$missingExifA = duplicate_photo_detector_exif_fingerprint([
    'exif_taken_at' => null,
    'exif_camera_make' => '',
    'exif_camera_model' => null,
    'exif_lens_model' => '   ',
    'gps_lat' => null,
    'gps_lng' => '',
]);
$missingExifB = duplicate_photo_detector_exif_fingerprint([
    'exif_taken_at' => '',
    'exif_camera_make' => null,
    'exif_camera_model' => '',
    'exif_lens_model' => null,
    'gps_lat' => '',
    'gps_lng' => null,
]);
assert_duplicate_detector_same(null, $missingExifA, 'NULL and empty EXIF values produce no meaningful fingerprint evidence');
assert_duplicate_detector_same(null, $missingExifB, 'missing EXIF values do not match because both are missing');

$orderingJob = [
    'exact_groups' => [
        duplicate_detector_test_checksum('d') => [40, 30],
        duplicate_detector_test_checksum('c') => [12, 11],
    ],
    'possible_groups' => [
        '900:z' => [4, 3],
        '800:y' => [22, 21],
    ],
];
$references = duplicate_photo_detector_group_references($orderingJob);
assert_duplicate_detector_same(['exact', 'exact', 'possible', 'possible'], array_column($references, 'confidence'), 'exact groups sort before possible groups');
assert_duplicate_detector_same([11, 30, 3, 21], array_column($references, 'min_id'), 'group ordering is deterministic by confidence and lowest image id');

$pairJob = [
    'exact_groups' => [
        duplicate_detector_test_checksum('e') => [3, 1, 2],
    ],
    'possible_groups' => [],
    'image_gallery_ids' => [
        1 => 10,
        2 => 20,
        3 => 21,
    ],
];
$pairReferences = duplicate_photo_detector_pair_references($pairJob, duplicate_photo_ledger_empty_snapshot());
assert_duplicate_detector_same([[1, 2], [1, 3], [2, 3]], array_column($pairReferences['references'], 'ids'), 'duplicate groups expand into deterministic canonical pair comparisons');
assert_duplicate_detector_same(false, $pairReferences['truncated'], 'small duplicate groups do not hit the bounded pair-reference cap');

$pairLedger = duplicate_photo_ledger_empty_snapshot();
$pairLedger['pairs'][duplicate_photo_ledger_pair_key(1, 2)] = true;
$pairLedger['pair_count'] = 1;
$pairReferences = duplicate_photo_detector_pair_references($pairJob, $pairLedger);
assert_duplicate_detector_same([[1, 3], [2, 3]], array_column($pairReferences['references'], 'ids'), 'one ledgered image pair suppresses only that reviewed relationship');

$galleryLedger = duplicate_photo_ledger_empty_snapshot();
$galleryLedger['galleries'][20] = true;
$galleryLedger['gallery_count'] = 1;
$pairReferences = duplicate_photo_detector_pair_references($pairJob, $galleryLedger);
assert_duplicate_detector_same([[1, 3]], array_column($pairReferences['references'], 'ids'), 'an exact-gallery ledger rule suppresses pairs involving that gallery');
assert_duplicate_detector_same(21, $pairJob['image_gallery_ids'][3], 'child gallery id remains independently represented beside its parent gallery rule');

$largeIgnoredIds = range(1, 150);
$largeIgnoredJob = [
    'exact_groups' => [duplicate_detector_test_checksum('f') => $largeIgnoredIds],
    'possible_groups' => [],
    'image_gallery_ids' => array_fill_keys($largeIgnoredIds, 900),
];
$largeGalleryLedger = duplicate_photo_ledger_empty_snapshot();
$largeGalleryLedger['galleries'][900] = true;
$largeGalleryLedger['gallery_count'] = 1;
$largeIgnoredPairs = duplicate_photo_detector_pair_references($largeIgnoredJob, $largeGalleryLedger);
assert_duplicate_detector_same([], $largeIgnoredPairs['references'], 'gallery ledger can suppress every candidate relationship in a large source group');
assert_duplicate_detector_true($largeIgnoredPairs['truncated'], 'pair expansion remains bounded even when ledger filtering suppresses every considered relationship');

$deleteJob = [
    'gallery_id' => 42,
    'gallery_ids' => [42, 43],
    'search_all' => false,
    'status' => 'complete',
    'exact_groups' => [
        $checksumA => [101, 102, 103],
    ],
    'possible_groups' => [
        'candidate-a' => [102, 104],
        'candidate-b' => [105, 106, 107],
    ],
];
assert_duplicate_detector_true(duplicate_photo_detector_job_contains_image($deleteJob, 102), 'delete validation accepts an image that belongs to a detector result group');
assert_duplicate_detector_same(false, duplicate_photo_detector_job_contains_image($deleteJob, 999), 'delete validation rejects an image outside detector result groups');
assert_duplicate_detector_true(duplicate_photo_detector_job_contains_pair($deleteJob, 101, 103), 'ledger validation accepts two images that coexist in one completed detector group');
assert_duplicate_detector_same(false, duplicate_photo_detector_job_contains_pair($deleteJob, 101, 104), 'ledger validation rejects two images that never formed one detector finding');
assert_duplicate_detector_true(duplicate_photo_detector_job_allows_gallery($deleteJob, 43), 'local delete validation accepts a descendant gallery captured in the immutable job scope');
assert_duplicate_detector_same(false, duplicate_photo_detector_job_allows_gallery($deleteJob, 99), 'local delete validation rejects a gallery outside the immutable job scope');
assert_duplicate_detector_true(duplicate_photo_detector_job_allows_gallery(array_merge($deleteJob, ['search_all' => true]), 99), 'global delete validation accepts another administrator gallery');

$prunedDeleteJob = duplicate_photo_detector_prune_image_from_job($deleteJob, 102);
assert_duplicate_detector_same([101, 103], $prunedDeleteJob['exact_groups'][$checksumA] ?? null, 'deleting one member keeps an exact group that still has two images');
assert_duplicate_detector_same(false, isset($prunedDeleteJob['possible_groups']['candidate-a']), 'deleting one member removes a possible group that falls below two images');
assert_duplicate_detector_same([105, 106, 107], $prunedDeleteJob['possible_groups']['candidate-b'] ?? null, 'deleting one image does not disturb unrelated groups');
assert_duplicate_detector_same(false, duplicate_photo_detector_job_contains_image($prunedDeleteJob, 102), 'deleted image is removed from every detector group');

$sideEffectRows = $normalizedExifRows;
$sideEffectRowsBefore = $sideEffectRows;
$tempPath = tempnam(sys_get_temp_dir(), 'duplicate-photo-detector-');
if ($tempPath === false) {
    throw new RuntimeException('Could not create duplicate detector side-effect test file.');
}
file_put_contents($tempPath, 'read-only detector sentinel');
$fileHashBefore = hash_file('sha256', $tempPath);
$job = duplicate_detector_test_job();
duplicate_photo_detector_process_rows($job, $sideEffectRows);
$fileHashAfter = hash_file('sha256', $tempPath);
assert_duplicate_detector_same($sideEffectRowsBefore, $sideEffectRows, 'matching does not modify image input records');
assert_duplicate_detector_same($fileHashBefore, $fileHashAfter, 'matching does not modify unrelated filesystem content');
assert_duplicate_detector_true(is_file($tempPath), 'matching does not delete filesystem content');
unlink($tempPath);

$serviceSource = file_get_contents(__DIR__ . '/../app/services/duplicate_photo_detector.php');
if (!is_string($serviceSource)) {
    throw new RuntimeException('Could not read duplicate detector service source for read-only SQL assertion.');
}
assert_duplicate_detector_same(false, preg_match('/\b(?:UPDATE|DELETE\s+FROM|INSERT\s+INTO|REPLACE\s+INTO)\s+images\b/i', $serviceSource) === 1, 'detector service contains no image-table mutation SQL');

assert_duplicate_detector_true(str_contains($serviceSource, 'INNER JOIN galleries child'), 'local detector resolves descendant galleries from the selected branch root');
assert_duplicate_detector_true(str_contains($serviceSource, "child.folder_path LIKE CONCAT(root.folder_path, '/%')"), 'local detector includes nested subgallery folder paths');
assert_duplicate_detector_true(str_contains($serviceSource, 'gallery_id IN ($placeholders)'), 'local image batches use the immutable gallery-branch id snapshot');

$viewSource = file_get_contents(__DIR__ . '/../app/views/admin_duplicate_photos.php');
if (!is_string($viewSource)) {
    throw new RuntimeException('Could not read duplicate detector view source for UI safety assertions.');
}
assert_duplicate_detector_true(str_contains($viewSource, 'name="search_all" value="1">'), 'fresh Search all galleries checkbox is rendered without a checked attribute');
assert_duplicate_detector_true(str_contains($viewSource, 'selected gallery and all subgalleries'), 'local detector UI clearly states that subgalleries are included');
assert_duplicate_detector_true(str_contains($viewSource, 'name="action" value="delete"'), 'detector view exposes an explicit per-image delete action');
assert_duplicate_detector_true(str_contains($viewSource, 'data-duplicate-photo-delete-form'), 'detector delete action is marked for in-place JavaScript enhancement');
assert_duplicate_detector_same(false, str_contains($viewSource, 'data-duplicate-photo-delete-confirm'), 'detector delete action does not render a browser-confirmation payload');
assert_duplicate_detector_same(false, str_contains($viewSource, 'name="action" value="move'), 'detector view still exposes no move action');
assert_duplicate_detector_true(str_contains($viewSource, 'gallery_public_url'), 'duplicate result gallery title/path uses the existing public gallery URL helper');
assert_duplicate_detector_true(str_contains($viewSource, 'image_public_url'), 'duplicate result filename/file path uses the existing public image URL helper');
assert_duplicate_detector_true(str_contains($viewSource, 'target="_blank" rel="noopener"'), 'duplicate context links open safely without replacing the Admin panel page');
assert_duplicate_detector_true(str_contains($viewSource, 'name="action" value="ignore_pair"'), 'each pair exposes a persistent ignore-pair ledger action');
assert_duplicate_detector_true(str_contains($viewSource, 'name="action" value="ignore_gallery"'), 'each left/right image card exposes an independent exact-gallery ledger action');
assert_duplicate_detector_true(str_contains($viewSource, 'name="action" value="clear_ledger"'), 'detector exposes a ledger reset action');
assert_duplicate_detector_true(str_contains($viewSource, 'data-duplicate-photo-ledger-form'), 'ledger mutations are marked for delegated side-panel AJAX handling');

$controllerSource = file_get_contents(__DIR__ . '/../app/controllers/admin_duplicate_photos.php');
if (!is_string($controllerSource)) {
    throw new RuntimeException('Could not read duplicate detector controller source for delete integration assertions.');
}
assert_duplicate_detector_true(str_contains($controllerSource, 'delete_gallery_images($imageGalleryId, [$imageId])'), 'duplicate detector deletion reuses the existing gallery image deletion service');
assert_duplicate_detector_true(str_contains($controllerSource, 'duplicate_photo_detector_remove_image_from_job($token, $imageId)'), 'successful deletion prunes the image from the persisted detector job');
assert_duplicate_detector_true(str_contains($controllerSource, 'duplicate_photo_detector_job_allows_gallery($job, $imageGalleryId)'), 'delete controller revalidates current gallery membership against immutable detector scope');
assert_duplicate_detector_true(str_contains($controllerSource, 'duplicate_photo_ledger_add_pair($adminUserId, $leftImageId, $rightImageId)'), 'ignore-pair controller persists the canonical reviewed relationship');
assert_duplicate_detector_true(str_contains($controllerSource, 'duplicate_photo_ledger_add_gallery($adminUserId, $ignoredGalleryId)'), 'ignore-gallery controller persists the server-derived exact gallery id');
assert_duplicate_detector_true(str_contains($controllerSource, 'duplicate_photo_ledger_clear($adminUserId)'), 'clear-ledger controller removes only the authenticated administrator ledger');
assert_duplicate_detector_true(str_contains($controllerSource, '$image = find_image($imageId);'), 'ignore-gallery controller derives gallery ownership from the current image instead of trusting a browser gallery id');
assert_duplicate_detector_true(str_contains($controllerSource, 'duplicate_photo_detector_job_contains_pair($job, $leftImageId, $rightImageId)'), 'ignore-pair controller requires the submitted pair to belong to one completed detector finding');

$javascriptSource = file_get_contents(__DIR__ . '/../public/assets/gallery-modules/admin-duplicate-photo-detector.js');
if (!is_string($javascriptSource)) {
    throw new RuntimeException('Could not read duplicate detector JavaScript source for AJAX delete assertions.');
}
assert_duplicate_detector_true(str_contains($javascriptSource, 'runDuplicatePhotoDelete(root, form)'), 'duplicate result delete uses the detector AJAX workflow');
assert_duplicate_detector_true(str_contains($javascriptSource, 'runDuplicatePhotoLedgerAction(root, form)'), 'pair/gallery/clear ledger actions use the detector AJAX workflow');
assert_duplicate_detector_true(str_contains($javascriptSource, '[data-duplicate-photo-ledger-form]'), 'delegated side-panel interception covers dynamically rendered ledger forms');
assert_duplicate_detector_same(false, str_contains($javascriptSource, 'window.confirm('), 'duplicate result delete does not introduce a browser confirmation dialog');
assert_duplicate_detector_same(false, preg_match('/\b(?:window\.)?location\s*=|location\.href|location\.assign|location\.replace|window\.location|\.reload\s*\(/', $javascriptSource) === 1, 'duplicate result delete JavaScript contains no page navigation or reload path');
assert_duplicate_detector_true(str_contains($javascriptSource, 'root.replaceWith(replacement)'), 'successful delete refreshes detector results in place without a page reload');
assert_duplicate_detector_true(str_contains($javascriptSource, "event.stopImmediatePropagation()"), 'detector form interception prevents fallback Admin form navigation');
assert_duplicate_detector_true(str_contains($javascriptSource, "}, true);"), 'detector form interception runs in capture phase for dynamically injected side-panel forms');

$galleryEntrypointSource = file_get_contents(__DIR__ . '/../public/assets/gallery.js');
if (!is_string($galleryEntrypointSource)) {
    throw new RuntimeException('Could not read gallery JavaScript entrypoint for detector cache-busting assertion.');
}
assert_duplicate_detector_true(str_contains($galleryEntrypointSource, 'admin-duplicate-photo-detector.js?v=20260808-duplicate-photo-detector-ledger-v4'), 'gallery entrypoint cache-busts the detector module after the in-panel review-ledger enhancement');

$editorSource = file_get_contents(__DIR__ . '/../app/controllers/admin_galleries_edit.php');
if (!is_string($editorSource)) {
    throw new RuntimeException('Could not read gallery editor source for side-panel entry assertion.');
}
assert_duplicate_detector_true(str_contains($editorSource, "'data-admin-side-panel-workflow' => 'duplicate-detector'"), 'gallery editor exposes duplicate detector through the existing side-panel link workflow');

echo "Duplicate photo detector tests passed.\n";
