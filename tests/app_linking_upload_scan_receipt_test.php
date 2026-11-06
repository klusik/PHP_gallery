<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/app_linking_upload_scan_receipt_test.php
 * Module Type: Regression Test
 * Purpose: Verify scanner receipts distinguish inserted rows from old rows.
 * Responsibilities: Exercise the actual scanner with owned filesystem/model seams.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Normalize one synthetic direct filename. @param string $path Fixture path. @return string Normalized relative path. */
    function normalize_relative_path(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }
    /** Admit fixture image names. @param string $path Candidate filename. @return bool True only for a PNG. */
    function is_supported_image_path(string $path): bool
    {
        return str_ends_with($path, '.png');
    }
    /** Return a deterministic fixture timestamp. @return string Fixed SQL time. */
    function now_sql(): string
    {
        return '2026-01-01 00:00:00';
    }
}

namespace Gallery\Models {
    /** Read the current isolated scanner row. @param int $galleryId Fixture owner. @param string $hash Relative filename hash. @return array<string,mixed>|null Existing row or null. */
    function image_model_find_by_path_hash(int $galleryId, string $hash): ?array
    {
        $row = $GLOBALS['scan_receipt_row'];
        return is_array($row) && $row['gallery_id'] === $galleryId
            && $row['relative_path_hash'] === $hash ? $row : null;
    }
    /** Insert only into the fixture row. @param array<string,mixed> $fields Prepared scanner columns. @return int New isolated row ID. */
    function image_model_insert_scan_row(array $fields): int
    {
        $GLOBALS['scan_receipt_row'] = ['id' => 41] + $fields;
        return 41;
    }
}

namespace Gallery\Services {
    /** Supply the fixture's enabled ingestion status. @return array{state:string} Available fixture status. */
    function upload_ingestion_schema_status(): array
    {
        return ['state' => 'available'];
    }
    /** Admit a fixture schema without database I/O. @param array{state:string} $status Fixture schema status. @param string $operation Operation identity. @param string $missing Missing-state message. @param string $unknown Unknown-state message. @return void No-op for this isolated fixture. */
    function mutation_schema_assert_available(
        array $status, string $operation, string $missing, string $unknown,
    ): void {}
    /** Ignore unrelated thumbnail cache state. @return void No-op in this fixture. */
    function thumbnail_legacy_identity_cache_clear(): void {}
}

namespace {
    require_once __DIR__ . '/../app/services/image_scanning.php';

    /**
     * Bound the random name of one isolated scanner directory.
     * Type: int. Units: random bytes. Scope: one fixture directory.
     * Consumers: random_bytes. Rationale: 8 bytes avoid concurrent collisions.
     */
    const SCAN_RECEIPT_FIXTURE_RANDOM_BYTES = 8;

    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'gallery-upload-scan-'
        . bin2hex(random_bytes(SCAN_RECEIPT_FIXTURE_RANDOM_BYTES));
    if (!mkdir($root, 0700)) {
        throw new \RuntimeException('Could not create scanner fixture.');
    }
    $root = realpath($root);
    if (!is_string($root)) {
        throw new \RuntimeException('Scanner fixture path is unavailable.');
    }
    $path = $root . DIRECTORY_SEPARATOR . 'photo.png';
    $GLOBALS['scan_receipt_row'] = null;
    try {
        if (file_put_contents($path, 'fixture-only bytes') === false) {
            throw new \RuntimeException('Could not create scanner source.');
        }
        $order = 10;
        $created = null;
        $metadata = ['width' => 1, 'height' => 1, 'mime' => 'image/png'];
        $count = \Gallery\Services\scan_gallery_image_file_entry(
            ['id' => 7, 'folder_path' => 'fixture'], $root, $path,
            'photo.png', false, $order,
            ['upload_fast_path' => true, 'metadata' => $metadata], $created,
        );
        if ($count !== 1 || $created !== 41) {
            throw new \RuntimeException('A real insert must produce its row receipt.');
        }
        $created = null;
        $count = \Gallery\Services\scan_gallery_image_file_entry(
            ['id' => 7, 'folder_path' => 'fixture'], $root, $path,
            'photo.png', false, $order,
            ['upload_fast_path' => true, 'metadata' => $metadata], $created,
        );
        if ($count !== 0 || $created !== null) {
            throw new \RuntimeException('An existing row must not produce a new receipt.');
        }
        $created = null;
        $count = \Gallery\Services\scan_gallery_image_file_entry(
            ['id' => 7, 'folder_path' => 'fixture'], $root, $path,
            'photo.txt', false, $order,
            ['upload_fast_path' => true, 'metadata' => $metadata], $created,
        );
        if ($count !== 0 || $created !== null) {
            throw new \RuntimeException('A skipped file must not produce a receipt.');
        }
    } finally {
        @unlink($path);
        @rmdir($root);
    }
    echo "PASS: scanner insertion receipts distinguish existing and skipped rows.\n";
}
