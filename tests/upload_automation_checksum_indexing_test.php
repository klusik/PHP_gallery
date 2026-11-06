<?php

/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Protect watcher deduplication against images missing indexed SHA-256 metadata.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/upload_automation_checksum_indexing_test.php
 * Module Type: Test
 *
 * Purpose:
 *   Verifies that upload-automation images receive authoritative SHA-256 checksums
 *   immediately after storage so routine watcher inventory can deduplicate them.
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
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Normalize the synthetic gallery-relative path used by this isolated service test.
     *
     * @param string $path Relative path value.
     * @return string Normalized relative path.
     */
    function normalize_relative_path(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }

    /**
     * Return a deterministic timestamp for checksum persistence assertions.
     *
     * @return string Fixed SQL timestamp.
     */
    function now_sql(): string
    {
        return '2026-09-14 09:00:00';
    }
}

namespace Gallery\Models {
    /**
     * Resolve one fixture row only within its owning gallery and path hash.
     * @param int $galleryId Synthetic owning gallery ID.
     * @param string $hash SHA-256 of the normalized relative filename.
     * @return array{id:int}|null Matching fixture row or null.
     */
    function image_model_find_by_path_hash(int $galleryId, string $hash): ?array
    {
        $paths = $GLOBALS['upload_automation_checksum_paths'] ?? [];
        if ($galleryId !== 7) {
            return null;
        }
        foreach ($paths as $id => $path) {
            if (hash('sha256', $path) === $hash) {
                return ['id' => (int) $id];
            }
        }
        return null;
    }
    /**
     * Return the synthetic image-path map prepared by the test fixture.
     *
     * @param int $galleryId Gallery identifier.
     * @param array<int,int> $imageIds Image identifiers.
     * @return array<int,string> Relative paths keyed by image id.
     */
    function image_model_relative_paths_for_gallery_ids(
        int $galleryId,
        array $imageIds,
    ): array {
        return $GLOBALS['upload_automation_checksum_paths'] ?? [];
    }

    /**
     * Capture checksum persistence arguments without requiring a live database.
     *
     * @param int $galleryId Gallery identifier.
     * @param array<int,string> $checksumsByImageId Checksums keyed by image id.
     * @param string $now Update timestamp.
     * @return array{updated:int,skipped:int} Synthetic persistence counters.
     */
    function image_model_set_checksums_for_gallery_ids(
        int $galleryId,
        array $checksumsByImageId,
        string $now,
    ): array {
        $GLOBALS['upload_automation_checksum_persisted'] = [
            'gallery_id' => $galleryId,
            'checksums' => $checksumsByImageId,
            'now' => $now,
        ];
        return ['updated' => count($checksumsByImageId), 'skipped' => 0];
    }
}

namespace Gallery\Services {
    /** Resolve the parent of the owned gallery fixture. @return string Fixture root parent. */
    function galleries_root(): string
    {
        return dirname((string) $GLOBALS['upload_automation_checksum_root']);
    }
    /**
     * Resolve the gallery root prepared by the checksum-indexing fixture.
     *
     * @param string $relativePath Gallery-relative folder path.
     * @return string Temporary absolute gallery root.
     */
    function gallery_abs_path(string $relativePath): string
    {
        return (string) ($GLOBALS['upload_automation_checksum_root'] ?? '');
    }

    require_once __DIR__ . '/../app/services/uploads.php';
    require_once __DIR__ . '/../app/services/upload_automation.php';

    /**
     * Emit one standalone checksum-indexing assertion result.
     *
     * @param bool $condition Assertion condition.
     * @param string $label Human-readable assertion label.
     * @return void
     */
    function upload_automation_checksum_assert(
        bool $condition,
        string $label,
    ): void {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$label}\n");
            exit(1);
        }
        fwrite(STDOUT, "PASS: {$label}\n");
    }

    $tempRoot =
        sys_get_temp_dir()
        . DIRECTORY_SEPARATOR
        . 'php-gallery-upload-checksum-'
        . bin2hex(random_bytes(6));
    if (!mkdir($tempRoot, 0775, true) && !is_dir($tempRoot)) {
        fwrite(STDERR, "FAIL: could not create temporary gallery directory\n");
        exit(1);
    }

    try {
        $filename = 'watcher-screenshot.png';
        $content = "deterministic-watcher-screenshot\n";
        file_put_contents(
            $tempRoot . DIRECTORY_SEPARATOR . $filename,
            $content,
        );
        $expected = hash('sha256', $content);
        $GLOBALS['upload_automation_checksum_root'] = $tempRoot;
        $GLOBALS['upload_automation_checksum_paths'] = [42 => $filename];
        $GLOBALS['upload_automation_checksum_persisted'] = null;

        $result = upload_automation_refresh_stored_checksums(
            7,
            ['folder_path' => 'gallery'],
            ['image_ids' => [42]],
        );
        $persisted = $GLOBALS['upload_automation_checksum_persisted'];

        upload_automation_checksum_assert(
            ($result['hashed'] ?? 0) === 1,
            'one stored watcher image is hashed',
        );
        upload_automation_checksum_assert(
            ($result['failed'] ?? 0) === 0,
            'checksum indexing reports no filesystem failure',
        );
        upload_automation_checksum_assert(
            is_array($persisted) && ($persisted['gallery_id'] ?? 0) === 7,
            'checksum persistence remains gallery scoped',
        );
        upload_automation_checksum_assert(
            ($persisted['checksums'][42] ?? '') === $expected,
            'persisted checksum matches the authoritative stored file bytes',
        );

        $galleryPath = basename($tempRoot);
        $proof = gallery_upload_delegated_file_identity($galleryPath, $filename);
        $stored = [
            'uploaded' => 1,
            'filenames' => [$filename],
            'image_ids' => [42],
            'scan_failed_filenames' => [],
            'delegated_receipts' => [42 => $proof],
        ];
        upload_automation_assert_delegated_registration(
            7, ['folder_path' => $galleryPath], $stored, 1,
        );
        // A successful rename keeps the same original identity and DB ID.
        $renamed = 'renamed.png';
        upload_automation_checksum_assert(
            rename($tempRoot . DIRECTORY_SEPARATOR . $filename,
                $tempRoot . DIRECTORY_SEPARATOR . $renamed),
            'fixture source rename succeeds',
        );
        $GLOBALS['upload_automation_checksum_paths'] = [42 => $renamed];
        $stored['filenames'] = [$renamed];
        upload_automation_assert_delegated_registration(
            7, ['folder_path' => $galleryPath], $stored, 1,
        );
        foreach ([
            ['image_ids' => []],
            ['scan_failed_filenames' => [$filename]],
            ['delegated_receipts' => []],
            ['image_ids' => [43]],
        ] as $change) {
            $refused = false;
            try {
                upload_automation_assert_delegated_registration(
                    7, ['folder_path' => $galleryPath], array_replace($stored, $change), 1,
                );
            } catch (\RuntimeException) {
                $refused = true;
            }
            upload_automation_checksum_assert(
                $refused,
                'incomplete or unrelated delegated registration is refused',
            );
        }
        unlink($tempRoot . DIRECTORY_SEPARATOR . $renamed);
        file_put_contents($tempRoot . DIRECTORY_SEPARATOR . $renamed, 'replacement');
        $refused = false;
        try {
            upload_automation_assert_delegated_registration(
                7, ['folder_path' => $galleryPath], $stored, 1,
            );
        } catch (\RuntimeException) {
            $refused = true;
        }
        upload_automation_checksum_assert(
            $refused,
            'replaced source is not accepted as the stored upload',
        );
    } finally {
        @unlink($tempRoot . DIRECTORY_SEPARATOR . 'watcher-screenshot.png');
        @unlink($tempRoot . DIRECTORY_SEPARATOR . 'renamed.png');
        @rmdir($tempRoot);
    }

    fwrite(STDOUT, "Upload automation checksum indexing tests passed.\n");
}
