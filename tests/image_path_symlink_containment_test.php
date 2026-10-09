<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/image_path_symlink_containment_test.php
 * Module Type: Regression Test
 * Purpose: Reject gallery image symlinks escaping the owning gallery folder.
 * Responsibilities: Test existing images, missing upload targets and symlink escapes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Supply only disposable gallery filesystem configuration.
     *
     * @return array{galleries_root:string} Test-owned gallery storage root.
     */
    function cms_config(): array
    {
        return ['galleries_root' => $GLOBALS['image_path_containment_root']];
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/helpers_files.php';
    require_once dirname(__DIR__) . '/app/services/gallery_paths.php';

    /**
     * Fail a gallery image path safety invariant.
     *
     * @param bool $condition Expected state.
     * @param string $label Case being checked.
     * @return void No value on success.
     */
    function image_path_containment_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new \RuntimeException($label);
        }
    }

    /**
     * Verify rejection from the central existing-image resolver.
     *
     * @param array{relative_path:string} $image Selected stored image identity.
     * @param array{folder_path:string} $gallery Owning physical gallery.
     * @param string $label Rejection case.
     * @return void Raises if the image path was admitted.
     */
    function image_path_containment_refused(array $image, array $gallery, string $label): void
    {
        try {
            \Gallery\Services\image_abs_path($image, $gallery);
        } catch (\RuntimeException) {
            return;
        }
        throw new \RuntimeException($label . ': unsafe image path was allowed.');
    }

    // Compare native physical paths exactly while keeping relative-path/traversal cases unchanged.
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gallery-image-path-' . bin2hex(random_bytes(8));
    $galleryStorage = $root . DIRECTORY_SEPARATOR . 'galleries';
    $galleryDir = $galleryStorage . DIRECTORY_SEPARATOR . 'album';
    $outsideDir = $root . DIRECTORY_SEPARATOR . 'outside';
    if (!mkdir($galleryDir, 0700, true) || !mkdir($outsideDir, 0700, true)) {
        throw new \RuntimeException('Could not prepare isolated image storage.');
    }
    $GLOBALS['image_path_containment_root'] = $galleryStorage;
    $gallery = ['folder_path' => 'album'];
    $valid = $galleryDir . DIRECTORY_SEPARATOR . 'photo.jpg';
    $outside = $outsideDir . DIRECTORY_SEPARATOR . 'other.jpg';
    $fileLink = $galleryDir . DIRECTORY_SEPARATOR . 'escape.jpg';
    $directoryLink = $galleryDir . DIRECTORY_SEPARATOR . 'redirected';
    try {
        file_put_contents($valid, 'ordinary gallery image');
        file_put_contents($outside, 'outside gallery fixture');
        image_path_containment_assert(
            \Gallery\Services\image_abs_path(['relative_path' => 'photo.jpg'], $gallery) === $valid,
            'Existing ordinary image must resolve.'
        );
        image_path_containment_assert(
            \Gallery\Services\image_abs_path(['relative_path' => 'future.jpg'], $gallery) === $galleryDir . DIRECTORY_SEPARATOR . 'future.jpg',
            'Missing target for a safe upload must still resolve.'
        );
        image_path_containment_refused(['relative_path' => '../outside/other.jpg'], $gallery, 'Dot-dot traversal');

        if (function_exists('symlink') && @symlink($outside, $fileLink)) {
            image_path_containment_refused(['relative_path' => 'escape.jpg'], $gallery, 'Escaping file symlink');
        }
        if (function_exists('symlink') && @symlink($outsideDir, $directoryLink)) {
            image_path_containment_refused(['relative_path' => 'redirected/other.jpg'], $gallery, 'Escaping parent symlink');
            image_path_containment_refused(['relative_path' => 'redirected/future.jpg'], $gallery, 'Escaping future target parent');
        }

        echo "Gallery image path symlink containment passed.\n";
    } finally {
        @unlink($fileLink);
        @unlink($directoryLink);
        @unlink($valid);
        @unlink($outside);
        @rmdir($galleryDir);
        @rmdir($galleryStorage);
        @rmdir($outsideDir);
        @rmdir($root);
    }
}
