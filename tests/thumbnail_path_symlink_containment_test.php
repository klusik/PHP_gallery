<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/thumbnail_path_symlink_containment_test.php
 * Module Type: Regression Test
 * Purpose: Prevent thumbnail reads and writes through symlinks outside a gallery.
 * Responsibilities: Validate existing thumbnail files, future cache paths and symlinked ancestors.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Resolve the isolated gallery directory selected by this fixture.
     *
     * @param string $relativePath Test gallery-relative identifier.
     * @return string Absolute path of the owned gallery fixture.
     */
    function gallery_abs_path(string $relativePath): string
    {
        return (string) $GLOBALS['thumbnail_symlink_gallery_root'];
    }

    /**
     * Return a stable error label when the thumbnail boundary rejects a path.
     *
     * @param string $key Error translation identifier.
     * @param string $fallback Optional English fallback.
     * @return string Deterministic fixture translation.
     */
    function t(string $key, string $fallback = ''): string
    {
        return $fallback !== '' ? $fallback : $key;
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/services/thumbnail_sources.php';

    /**
     * Enforce a thumbnail path containment regression.
     *
     * @param bool $condition Expected result of a safety invariant.
     * @param string $label Failure description.
     * @return void No result after successful assertion.
     */
    function thumbnail_symlink_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new RuntimeException($label);
        }
    }

    /**
     * Expect the actual thumbnail resolver to reject a redirected target.
     *
     * @param array<string,mixed> $image Synthetic stored source identity.
     * @param array{folder_path:string} $gallery Synthetic owning gallery row.
     * @param string $label Failure description.
     * @return void No value after expected rejection.
     */
    function thumbnail_symlink_rejected(array $image, array $gallery, string $label): void
    {
        try {
            Gallery\Services\thumbnail_abs_path($image, $gallery, 300, 'jpg');
        } catch (RuntimeException) {
            return;
        }
        throw new RuntimeException($label);
    }

    $root = sys_get_temp_dir() . '/gallery-thumbnail-path-' . bin2hex(random_bytes(8));
    $galleryRoot = $root . '/gallery';
    $thumbnailDir = $galleryRoot . '/thumbs';
    $outsideDir = $root . '/outside';
    if (!mkdir($galleryRoot, 0700, true) || !mkdir($outsideDir, 0700, true)) {
        throw new RuntimeException('Cannot create isolated thumbnail storage.');
    }
    $GLOBALS['thumbnail_symlink_gallery_root'] = $galleryRoot;
    $gallery = ['folder_path' => 'gallery'];
    $image = ['filename' => 'photo.jpg', 'thumbnail_source_identity_version' => 0];
    $photoName = 'photo_thumb300.jpg';
    $outsideFile = $outsideDir . '/' . $photoName;
    $insideFile = $thumbnailDir . '/' . $photoName;
    try {
        file_put_contents($outsideFile, 'outside thumbnail fixture');

        // A future thumbs/ directory and a future file must both be permitted.
        thumbnail_symlink_assert(
            Gallery\Services\gallery_thumbs_dir($gallery, false) === $thumbnailDir,
            'Missing legitimate thumbnail directory must remain usable.'
        );
        thumbnail_symlink_assert(
            Gallery\Services\thumbnail_abs_path($image, $gallery, 300, 'jpg') === $insideFile,
            'Missing legitimate thumbnail destination must remain usable.'
        );

        mkdir($thumbnailDir, 0700);
        file_put_contents($insideFile, 'normal thumbnail fixture');
        thumbnail_symlink_assert(
            Gallery\Services\thumbnail_abs_path($image, $gallery, 300, 'jpg') === $insideFile,
            'Existing normal thumbnail must remain accessible.'
        );
        thumbnail_symlink_assert(
            Gallery\Services\thumbnail_path_inside_existing_gallery($galleryRoot, $insideFile),
            'Existing image must be contained in owning gallery.'
        );

        // An individually symlinked thumbnail can escape even with a valid thumbs/ parent.
        @unlink($insideFile);
        if (function_exists('symlink') && @symlink($outsideFile, $insideFile)) {
            thumbnail_symlink_assert(
                !Gallery\Services\thumbnail_path_inside_existing_gallery($galleryRoot, $insideFile),
                'Final thumbnail symlink escape must be rejected.'
            );
            thumbnail_symlink_rejected($image, $gallery, 'Thumbnail resolver allowed escaped file symlink.');
            @unlink($insideFile);
        }

        // A symlink replacing thumbs/ must not redirect generated or existing files outside.
        @rmdir($thumbnailDir);
        if (function_exists('symlink') && @symlink($outsideDir, $thumbnailDir)) {
            thumbnail_symlink_assert(
                !Gallery\Services\thumbnail_path_inside_existing_gallery($galleryRoot, $thumbnailDir),
                'Escaping thumbnail directory symlink must be rejected.'
            );
            thumbnail_symlink_assert(
                !Gallery\Services\thumbnail_path_inside_existing_gallery($galleryRoot, $thumbnailDir . '/future.jpg'),
                'Future file through an escaping ancestor symlink must be rejected.'
            );
            thumbnail_symlink_rejected($image, $gallery, 'Thumbnail resolver allowed escaped thumbs directory.');
        }

        thumbnail_symlink_assert(
            !Gallery\Services\thumbnail_path_inside_existing_gallery($galleryRoot, $root . '/outside/other.jpg'),
            'Lexically out-of-gallery thumbnail target must be refused.'
        );
        echo "Thumbnail canonical path containment passed.\n";
    } finally {
        @unlink($insideFile);
        @unlink($thumbnailDir);
        @unlink($outsideFile);
        @rmdir($thumbnailDir);
        @rmdir($galleryRoot);
        @rmdir($outsideDir);
        @rmdir($root);
    }
}
