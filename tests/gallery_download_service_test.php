<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap/configuration.php';
require_once dirname(__DIR__) . '/app/services/downloads.php';

use function Gallery\Services\gallery_download_legacy_manifest_is_safe;
use function Gallery\Services\gallery_download_safe_zip_path;
use function Gallery\Services\gallery_download_unique_zip_path;

/** Test double for assert_same(). */
function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

assert_same('folder/photo.jpg', gallery_download_safe_zip_path('/folder/./photo.jpg'), 'Safe ZIP path should remove empty/current segments.');
assert_same('C_/photo.jpg', gallery_download_safe_zip_path('C:/photo.jpg'), 'Drive separators must not survive in ZIP paths.');
assert_same('folder/photo.jpg', gallery_download_safe_zip_path('folder/../photo.jpg'), 'Traversal segments must not survive in ZIP paths.');
assert_same('photo', gallery_download_safe_zip_path('../'), 'Traversal-only names need a safe fallback.');

$used = [];
assert_same('folder/photo.jpg', gallery_download_unique_zip_path('folder/photo.jpg', $used), 'First duplicate candidate should keep its name.');
assert_same('folder/PHOTO-2.jpg', gallery_download_unique_zip_path('folder/PHOTO.jpg', $used), 'Duplicate names should be unique case-insensitively.');
assert_same('folder/photo-3.jpg', gallery_download_unique_zip_path('folder/photo.jpg', $used), 'Duplicate suffixes should be deterministic.');

assert_same(true, gallery_download_legacy_manifest_is_safe(['total_files' => 1000, 'total_bytes' => 268435456]), 'Legacy boundary should remain allowed.');
assert_same(false, gallery_download_legacy_manifest_is_safe(['total_files' => 1001, 'total_bytes' => 1]), 'Legacy file count must be bounded.');
assert_same(false, gallery_download_legacy_manifest_is_safe(['total_files' => 1, 'total_bytes' => 268435457]), 'Legacy source bytes must be bounded.');

echo "gallery_download_service_test: ok\n";
