<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/image_deletion_cache_safety_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies filesystem cache cleanup primitives used by selected-image deletion.
 *
 * Responsibilities:
 *   - Catch stale thumbnail sizes that are no longer in the configured size list
 *   - Catch interrupted thumbnail temporary files for the deleted image stem
 *   - Leave unrelated thumbnail files untouched
 *   - Prove deletion staging removes live media paths and can be rolled back
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 */

declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Return the small configured size set used by this isolated filesystem test.
     *
     * @return array<int,int> Thumbnail sizes.
     */
    function thumbnail_sizes(): array
    {
        return [300, 600];
    }

    /**
     * Build one test thumbnail path using the production naming convention.
     *
     * @param array $image Image row.
     * @param array $gallery Gallery row.
     * @param int $size Thumbnail size.
     * @param string $format Thumbnail format.
     * @return string Absolute test path.
     */
    function thumbnail_abs_path(array $image, array $gallery, int $size, string $format = 'jpg'): string
    {
        unset($gallery);
        return (string) $GLOBALS['deletion_test_thumbs'] . DIRECTORY_SEPARATOR
            . pathinfo((string) $image['filename'], PATHINFO_FILENAME) . '_thumb' . $size . '.' . $format;
    }

    /**
     * Return the isolated thumbnail directory.
     *
     * @param array $gallery Gallery row.
     * @param bool $create Whether to create the directory.
     * @return string Absolute thumbnail directory.
     */
    function gallery_thumbs_dir(array $gallery, bool $create = false): string
    {
        unset($gallery);
        $path = (string) $GLOBALS['deletion_test_thumbs'];
        if ($create && !is_dir($path)) {
            mkdir($path, 0775, true);
        }
        return $path;
    }

    /**
     * Textually validate that a path stays inside the isolated gallery root.
     *
     * @param string $galleryRoot Gallery root.
     * @param string $thumbnailPath Candidate path.
     * @return bool True for contained paths.
     */
    function thumbnail_path_inside_existing_gallery(string $galleryRoot, string $thumbnailPath): bool
    {
        $root = rtrim(str_replace('\\', '/', $galleryRoot), '/') . '/';
        $path = str_replace('\\', '/', $thumbnailPath);
        return str_starts_with($path, $root);
    }

    /**
     * Disable DNG-specific paths for this JPG-focused isolated test.
     *
     * @param array $image Image row.
     * @return bool Always false.
     */
    function image_uses_dng_display_derivatives(array $image): bool
    {
        unset($image);
        return false;
    }

    require_once __DIR__ . '/../app/services/gallery_mutations.php';
}

namespace {
    use function Gallery\Services\gallery_finalize_staged_deletion_files;
    use function Gallery\Services\gallery_image_deletion_derivative_paths;
    use function Gallery\Services\gallery_restore_staged_deletion_files;
    use function Gallery\Services\gallery_stage_file_for_deletion;

    /**
     * Throw when an isolated deletion-cache expectation fails.
     *
     * @param bool $condition Assertion condition.
     * @param string $message Failure message.
     */
    function assert_deletion_cache_true(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-gallery-delete-cache-' . bin2hex(random_bytes(5));
    $thumbs = $root . DIRECTORY_SEPARATOR . 'thumbs';
    mkdir($thumbs, 0775, true);
    $GLOBALS['deletion_test_thumbs'] = $thumbs;

    try {
        $image = ['id' => 77, 'filename' => 'sample.jpg'];
        $gallery = ['id' => 9, 'folder_path' => 'sample'];
        $current = $thumbs . DIRECTORY_SEPARATOR . 'sample_thumb300.jpg';
        $oldSize = $thumbs . DIRECTORY_SEPARATOR . 'sample_thumb777.webp';
        $temporary = $thumbs . DIRECTORY_SEPARATOR . 'sample_thumb600.jpg.a1b2c3d4e5f6.tmp.jpg';
        $unrelated = $thumbs . DIRECTORY_SEPARATOR . 'other_thumb777.webp';
        foreach ([$current, $oldSize, $temporary, $unrelated] as $path) {
            file_put_contents($path, 'x');
        }

        $paths = gallery_image_deletion_derivative_paths($image, $gallery, $root);
        assert_deletion_cache_true(in_array($current, $paths, true), 'Current configured thumbnail must be deleted.');
        assert_deletion_cache_true(in_array($oldSize, $paths, true), 'Stale old-size thumbnail must be deleted.');
        assert_deletion_cache_true(in_array($temporary, $paths, true), 'Interrupted thumbnail temporary file must be deleted.');
        assert_deletion_cache_true(!in_array($unrelated, $paths, true), 'Unrelated image thumbnail must not be deleted.');

        $original = $root . DIRECTORY_SEPARATOR . 'sample.jpg';
        file_put_contents($original, 'source');
        $staged = gallery_stage_file_for_deletion($original, $root);
        assert_deletion_cache_true(!is_file($original), 'Staging must remove the original live media path.');
        assert_deletion_cache_true(is_file($staged['staged']), 'Staging must preserve rollback bytes under a quarantine path.');
        gallery_restore_staged_deletion_files([$staged]);
        assert_deletion_cache_true(is_file($original), 'Rollback must restore the original live media path.');

        $staged = gallery_stage_file_for_deletion($original, $root);
        assert_deletion_cache_true(gallery_finalize_staged_deletion_files([$staged]) === 0, 'Final staged-file cleanup must succeed.');
        assert_deletion_cache_true(!is_file($original) && !is_file($staged['staged']), 'Committed deletion must leave neither live nor staged source bytes.');
    } finally {
        if (is_dir($root)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                if ($entry->isDir()) {
                    @rmdir($entry->getPathname());
                } else {
                    @unlink($entry->getPathname());
                }
            }
            @rmdir($root);
        }
        unset($GLOBALS['deletion_test_thumbs']);
    }

    echo "Image deletion cache safety tests passed.\n";
}
