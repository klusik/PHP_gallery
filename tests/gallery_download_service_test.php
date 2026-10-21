<?php

/**
 * Project: PHP Gallery
 * Module Type: Regression Test
 * Purpose: Verify gallery download service orchestration.
 * Responsibilities:
 *   - Exercise archive policy and capability handling without production storage.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/gallery_download_service_test.php
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap/configuration.php';
require_once dirname(__DIR__) . '/app/models/downloads.php';
require_once dirname(__DIR__) . '/app/services/downloads.php';

use function Gallery\Services\gallery_download_legacy_manifest_is_safe;
use function Gallery\Services\gallery_download_safe_zip_path;
use function Gallery\Services\gallery_download_unique_zip_path;

/**
 * Assert strict equality for a gallery-download expectation.
 *
 * @template TExpected
 * @template TActual
 * @param TExpected $expected Expected value, including its collection shape when applicable.
 * @param TActual $actual Actual value, including its collection shape when applicable.
 * @param string $message Failure context.
 * @return void Writes expected and actual values to stderr and exits on mismatch.
 */
function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            $message
                . "\nExpected: "
                . var_export($expected, true)
                . "\nActual: "
                . var_export($actual, true)
                . "\n",
        );
        exit(1);
    }
}

assert_same(
    'folder/photo.jpg',
    gallery_download_safe_zip_path('/folder/./photo.jpg'),
    'Safe ZIP path should remove empty/current segments.',
);
assert_same(
    'C_/photo.jpg',
    gallery_download_safe_zip_path('C:/photo.jpg'),
    'Drive separators must not survive in ZIP paths.',
);
assert_same(
    'folder/photo.jpg',
    gallery_download_safe_zip_path('folder/../photo.jpg'),
    'Traversal segments must not survive in ZIP paths.',
);
assert_same(
    'photo',
    gallery_download_safe_zip_path('../'),
    'Traversal-only names need a safe fallback.',
);

$used = [];
assert_same(
    'folder/photo.jpg',
    gallery_download_unique_zip_path('folder/photo.jpg', $used),
    'First duplicate candidate should keep its name.',
);
assert_same(
    'folder/PHOTO-2.jpg',
    gallery_download_unique_zip_path('folder/PHOTO.jpg', $used),
    'Duplicate names should be unique case-insensitively.',
);
assert_same(
    'folder/photo-3.jpg',
    gallery_download_unique_zip_path('folder/photo.jpg', $used),
    'Duplicate suffixes should be deterministic.',
);


// Media originals use the same server fallback fingerprint as browser ZIPs.
// Only the validated file identity contributes; rotating capabilities do not.
$pdfUrl = '/index.php?page=download_gallery_file'
    . '&gallery_id=8&source_gallery_id=8'
    . '&asset_type=pdf&asset_id=ofp&v=0123456789abcdef'
    . '&mr=' . str_repeat('a', 64) . '&s=21';
$pdfManifest = [
    'files' => [
        [
            'name' => 'PDF/flight/plan.pdf',
            'size' => 21,
            'url' => $pdfUrl . '&capability=short-lived-token',
        ],
    ],
];
$mediaFingerprint = \Gallery\Services\gallery_download_legacy_manifest_fingerprint_items(
    $pdfManifest,
);
assert_same(
    [
        [
            'image_id' => 0,
            'source_version' => '0123456789abcdef',
            'zip_path' => 'PDF/flight/plan.pdf',
            'source_bytes' => 21,
            'media_type' => 'pdf',
            'media_id' => 'ofp',
            'source_gallery_id' => 8,
        ],
    ],
    $mediaFingerprint,
    'Legacy fallback must preserve the original PDF source and ZIP identity.',
);
$pdfManifest['files'][0]['url'] = $pdfUrl . '&capability=rotated-token';
assert_same(
    $mediaFingerprint,
    \Gallery\Services\gallery_download_legacy_manifest_fingerprint_items(
        $pdfManifest,
    ),
    'Transport capability rotation must not split one valid ZIP cache key.',
);

$musicId = str_repeat('b', 32);
$musicManifest = [
    'files' => [
        [
            'name' => 'Music/flight/track.mp3',
            'size' => 120,
            'url' => '/index.php?page=download_gallery_file'
                . '&gallery_id=8&source_gallery_id=8'
                . '&asset_type=music&asset_id=' . $musicId
                . '&v=0123456789abcdef',
        ],
    ],
];
assert_same(
    $musicId,
    \Gallery\Services\gallery_download_legacy_manifest_fingerprint_items(
        $musicManifest,
    )[0]['media_id'],
    'Music fallback must retain gallery-scoped opaque source identity.',
);

$invalidManifest = $musicManifest;
$invalidManifest['files'][0]['url'] = str_replace(
    $musicId,
    'traversal',
    (string) $invalidManifest['files'][0]['url'],
);
$invalidRejected = false;
try {
    \Gallery\Services\gallery_download_legacy_manifest_fingerprint_items(
        $invalidManifest,
    );
} catch (\Gallery\Services\LegacyDownloadBuildException) {
    $invalidRejected = true;
}
assert_same(
    true,
    $invalidRejected,
    'Malformed media IDs must not enter a legacy ZIP fingerprint.',
);

assert_same(
    true,
    gallery_download_legacy_manifest_is_safe([
        'total_files' => 1000,
        'total_bytes' => 268435456,
    ]),
    'Legacy boundary should remain allowed.',
);
assert_same(
    false,
    gallery_download_legacy_manifest_is_safe([
        'total_files' => 1001,
        'total_bytes' => 1,
    ]),
    'Legacy file count must be bounded.',
);
assert_same(
    false,
    gallery_download_legacy_manifest_is_safe([
        'total_files' => 1,
        'total_bytes' => 268435457,
    ]),
    'Legacy source bytes must be bounded.',
);

echo "gallery_download_service_test: ok\n";
