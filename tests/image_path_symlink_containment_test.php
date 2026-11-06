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
     * @return array{galleries_root:string,gallery_path:string} Test-owned storage roots.
     */
    function cms_config(): array
    {
        return [
            'galleries_root' => $GLOBALS['image_path_containment_root'],
            'gallery_path' => $GLOBALS['image_path_containment_root'],
        ];
    }
}

namespace Gallery\Services {
    /**
     * Change only the isolated tag fixture immediately before its write-open.
     *
     * @param string $filename Requested fixture or production sidecar path.
     * @param string $mode Native stream open mode.
     * @return resource|false Native stream or failure.
     */
    function fopen(string $filename, string $mode): mixed
    {
        $swap = $GLOBALS['tag_sidecar_open_swap'] ?? null;
        if (is_array($swap) && $mode === 'r+b' && $filename === $swap['path']) {
            unset($GLOBALS['tag_sidecar_open_swap']);
            if (isset($swap['contents'])) {
                if (\file_put_contents($filename, $swap['contents']) === false) {
                    throw new \RuntimeException('Could not alter the tag fixture.');
                }
            } elseif (!unlink($filename) || !symlink($swap['target'], $filename)) {
                throw new \RuntimeException('Could not substitute the tag fixture target.');
            }
        }
        return \fopen($filename, $mode);
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/helpers_files.php';
    require_once dirname(__DIR__) . '/app/helpers_runtime.php';
    require_once dirname(__DIR__) . '/app/services/gallery_paths.php';
    require_once dirname(__DIR__) . '/app/services/gallery_creation_safety.php';
    require_once dirname(__DIR__) . '/app/services/app_linking_create.php';
    require_once dirname(__DIR__) . '/app/services/tag_metadata.php';

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
    function image_path_containment_refused(
        array $image,
        array $gallery,
        string $label,
    ): void {
        try {
            \Gallery\Services\image_abs_path($image, $gallery);
        } catch (\RuntimeException) {
            return;
        }
        throw new \RuntimeException($label
            . ': unsafe image path was allowed.');
    }

    // Compare native physical paths exactly while keeping relative-path/traversal cases unchanged.
    $root =
        sys_get_temp_dir()
        . DIRECTORY_SEPARATOR
        . 'gallery-image-path-'
        . bin2hex(random_bytes(8));
    $galleryStorage = $root . DIRECTORY_SEPARATOR . 'galleries';
    $galleryDir = $galleryStorage . DIRECTORY_SEPARATOR . 'album';
    $outsideDir = $root . DIRECTORY_SEPARATOR . 'outside';
    if (!mkdir($galleryDir, 0700, true) || !mkdir($outsideDir, 0700, true)) {
        throw new \RuntimeException(
            'Could not prepare isolated image storage.',
        );
    }
    $GLOBALS['image_path_containment_root'] = $galleryStorage;
    $gallery = ['folder_path' => 'album'];
    $valid = $galleryDir . DIRECTORY_SEPARATOR . 'photo.jpg';
    $outside = $outsideDir . DIRECTORY_SEPARATOR . 'other.jpg';
    $fileLink = $galleryDir . DIRECTORY_SEPARATOR . 'escape.jpg';
    $directoryLink = $galleryDir . DIRECTORY_SEPARATOR . 'redirected';
    $danglingLink = $galleryDir . DIRECTORY_SEPARATOR . 'dangling';
    $rootAlias = $root . DIRECTORY_SEPARATOR . 'gallery-alias';
    $innerAlias = $galleryStorage . DIRECTORY_SEPARATOR . 'album-alias';
    $sidecar = $galleryDir . DIRECTORY_SEPARATOR . 'gallery.json';
    $sidecarBackup = $galleryDir . DIRECTORY_SEPARATOR . 'gallery.fixture-backup';
    $tagFolder = $galleryStorage . DIRECTORY_SEPARATOR . 'tag-fixture';
    $tagSidecar = $tagFolder . DIRECTORY_SEPARATOR . 'gallery.json';
    $tagTarget = $galleryDir . DIRECTORY_SEPARATOR . 'tag-target.json';
    $outsideTagTarget = $outsideDir . DIRECTORY_SEPARATOR . 'tag-target.json';
    try {
        file_put_contents($valid, 'ordinary gallery image');
        file_put_contents($outside, 'outside gallery fixture');
        file_put_contents($sidecar, '{"visibility":"public","title":"Changed"}');
        image_path_containment_assert(
            \Gallery\Services\app_linking_create_receipt_storage_current($gallery),
            'A live gallery with a regular JSON sidecar must be observable.',
        );
        image_path_containment_assert(
            !\Gallery\Services\app_linking_create_receipt_storage_current([
                'folder_path' => 'missing-gallery',
            ])
                && !\Gallery\Services\app_linking_create_receipt_storage_current([
                    'folder_path' => '../outside',
                ]),
            'A missing or traversing gallery must not confirm a receipt.',
        );
        file_put_contents($sidecar, '["not an object"]');
        image_path_containment_assert(
            !\Gallery\Services\app_linking_create_receipt_storage_current($gallery),
            'A non-object sidecar must not confirm a receipt.',
        );
        file_put_contents($sidecar, '{"visibility":"private","title":"Another"}');
        image_path_containment_assert(
            \Gallery\Services\app_linking_create_receipt_storage_current($gallery),
            'Legitimate changes to sidecar settings must not invalidate storage.',
        );
        image_path_containment_assert(
            rename($sidecar, $sidecarBackup),
            'Could not isolate the sidecar link fixture.',
        );
        try {
            image_path_containment_assert(
                !\Gallery\Services\app_linking_create_receipt_storage_current($gallery),
                'A missing sidecar must not confirm a receipt.',
            );
            if (function_exists('symlink') && @symlink($outside, $sidecar)) {
                image_path_containment_assert(
                    !\Gallery\Services\app_linking_create_receipt_storage_current($gallery),
                    'A redirected sidecar must not confirm a receipt.',
                );
                unlink($sidecar);
            }
            if (function_exists('link') && @link($sidecarBackup, $sidecar)) {
                image_path_containment_assert(
                    !\Gallery\Services\app_linking_create_receipt_storage_current($gallery),
                    'A multiply linked sidecar must not confirm a receipt.',
                );
                unlink($sidecar);
            }
        } finally {
            if (is_link($sidecar)) {
                unlink($sidecar);
            }
            rename($sidecarBackup, $sidecar);
        }
        image_path_containment_assert(
            \Gallery\Services\image_abs_path([
                'relative_path' => 'photo.jpg',
            ], $gallery) === $valid,
            'Existing ordinary image must resolve.',
        );
        image_path_containment_assert(
            \Gallery\Services\image_abs_path([
                'relative_path' => 'future.jpg',
            ], $gallery)
                === $galleryDir . DIRECTORY_SEPARATOR . 'future.jpg',
            'Missing target for a safe upload must still resolve.',
        );
        image_path_containment_refused(
            ['relative_path' => '../outside/other.jpg'],
            $gallery,
            'Dot-dot traversal',
        );

        if (function_exists('symlink') && @symlink($outside, $fileLink)) {
            image_path_containment_refused(
                ['relative_path' => 'escape.jpg'],
                $gallery,
                'Escaping file symlink',
            );
        }
        if (
            function_exists('symlink')
            && @symlink($outsideDir, $directoryLink)
        ) {
            image_path_containment_refused(
                ['relative_path' => 'redirected/other.jpg'],
                $gallery,
                'Escaping parent symlink',
            );
            image_path_containment_refused(
                ['relative_path' => 'redirected/future.jpg'],
                $gallery,
                'Escaping future target parent',
            );
            image_path_containment_assert(
                !\Gallery\Services\gallery_filesystem_path_inside_root(
                    $directoryLink . DIRECTORY_SEPARATOR . 'future-gallery',
                ),
                'Future gallery behind an escaping parent must be rejected.',
            );
        }
        if (
            function_exists('symlink')
            && @symlink($outsideDir . DIRECTORY_SEPARATOR . 'absent', $danglingLink)
        ) {
            image_path_containment_assert(
                !\Gallery\Services\gallery_filesystem_path_inside_root($danglingLink),
                'An unresolved existing link must not be treated as a future target.',
            );
            image_path_containment_assert(
                \Gallery\Services\gallery_creation_target_observation('album/dangling')
                    === \Gallery\Core\FILESYSTEM_OBSERVATION_UNKNOWN,
                'Creation must refuse an unresolved existing target.',
            );
        }
        if (function_exists('symlink') && @symlink($galleryStorage, $rootAlias)) {
            $GLOBALS['image_path_containment_root'] = $rootAlias;
            image_path_containment_assert(
                \Gallery\Services\app_linking_create_receipt_storage_current($gallery),
                'A trusted root alias must preserve a valid receipt observation.',
            );
            image_path_containment_assert(
                \Gallery\Services\gallery_filesystem_path_inside_root(
                    $rootAlias . DIRECTORY_SEPARATOR . 'new-gallery',
                ),
                'A missing child of an aliased storage root must resolve.',
            );
            image_path_containment_assert(
                \Gallery\Services\gallery_creation_target_observation('new-gallery')
                    === \Gallery\Core\FILESYSTEM_OBSERVATION_MISSING,
                'Creation must recognize absence through a trusted root alias.',
            );
            image_path_containment_assert(
                !\Gallery\Services\gallery_filesystem_path_inside_root(
                    $rootAlias . DIRECTORY_SEPARATOR . '..'
                        . DIRECTORY_SEPARATOR . 'outside'
                        . DIRECTORY_SEPARATOR . 'future-gallery',
                ),
                'A resolved parent outside an aliased root must be rejected.',
            );
            $GLOBALS['image_path_containment_root'] = $galleryStorage;
        }
        if (function_exists('symlink') && @symlink($galleryDir, $innerAlias)) {
            image_path_containment_assert(
                \Gallery\Services\gallery_filesystem_path_inside_root(
                    $innerAlias . DIRECTORY_SEPARATOR . 'future-gallery',
                ),
                'A missing child through an in-root parent alias must resolve.',
            );
            image_path_containment_assert(
                !\Gallery\Services\app_linking_create_receipt_storage_current([
                    'folder_path' => 'album-alias',
                ]),
                'A redirected gallery folder must not confirm a receipt.',
            );
        }
        image_path_containment_assert(
            !\Gallery\Services\gallery_filesystem_path_inside_root(
                $galleryStorage . DIRECTORY_SEPARATOR . '..'
                    . DIRECTORY_SEPARATOR . 'outside'
                    . DIRECTORY_SEPARATOR . 'future-gallery',
            ),
            'A parent reached through dot-dot must remain inside the root.',
        );
        image_path_containment_assert(
            !\Gallery\Services\gallery_filesystem_path_inside_root(
                $galleryStorage . DIRECTORY_SEPARATOR . '..\\outside\\future-gallery',
            ),
            'Mixed separators must not admit traversal beyond the root.',
        );
        $previousDirectory = getcwd();
        image_path_containment_assert(
            is_string($previousDirectory) && chdir($root),
            'Could not enter the private fixture.',
        );
        try {
            image_path_containment_assert(
                \Gallery\Services\gallery_filesystem_path_inside_root(
                    'galleries' . DIRECTORY_SEPARATOR . 'relative-future',
                ),
                'A relative future target must resolve from its actual parent.',
            );
        } finally {
            chdir($previousDirectory);
        }

        image_path_containment_assert(
            mkdir($tagFolder, 0700),
            'Could not prepare the isolated tag fixture.',
        );
        $tagBytes = '{"tags":"Mixed Case"}';
        file_put_contents($tagTarget, $tagBytes);
        file_put_contents($outsideTagTarget, $tagBytes);
        if (function_exists('symlink')) {
            if (@symlink($tagTarget, $tagSidecar)) {
                image_path_containment_assert(
                    \Gallery\Services\normalize_gallery_sidecar_tags_recursively() === 1
                        && json_decode((string) file_get_contents($tagTarget), true)['tags']
                            === 'mixed-case',
                    'Tag normalization must retain an in-root sidecar link.',
                );
                file_put_contents($tagTarget, $tagBytes);
                $concurrentBytes = '{"tags":"Concurrent edit"}';
                $GLOBALS['tag_sidecar_open_swap'] = [
                    'path' => $tagSidecar,
                    'contents' => $concurrentBytes,
                ];
                $refused = false;
                try {
                    \Gallery\Services\normalize_gallery_sidecar_tags_recursively();
                } catch (\RuntimeException) {
                    $refused = true;
                }
                image_path_containment_assert(
                    $refused && file_get_contents($tagTarget) === $concurrentBytes,
                    'Changed sidecar bytes must be retained without truncation.',
                );
                file_put_contents($tagTarget, $tagBytes);
                $GLOBALS['tag_sidecar_open_swap'] = [
                    'path' => $tagSidecar,
                    'target' => $outsideTagTarget,
                ];
                $refused = false;
                try {
                    \Gallery\Services\normalize_gallery_sidecar_tags_recursively();
                } catch (\RuntimeException) {
                    $refused = true;
                }
                image_path_containment_assert(
                    $refused
                        && file_get_contents($tagTarget) === $tagBytes
                        && file_get_contents($outsideTagTarget) === $tagBytes,
                    'A swapped target must be refused before either file is truncated.',
                );
                unlink($tagSidecar);
                image_path_containment_assert(
                    symlink($outsideTagTarget, $tagSidecar),
                    'Could not prepare the escaping tag link.',
                );
                $refused = false;
                try {
                    \Gallery\Services\normalize_gallery_sidecar_tags_recursively();
                } catch (\RuntimeException) {
                    $refused = true;
                }
                image_path_containment_assert(
                    $refused && file_get_contents($outsideTagTarget) === $tagBytes,
                    'Tag normalization must refuse an escaping sidecar link.',
                );
                unlink($tagSidecar);
                image_path_containment_assert(
                    symlink($outsideDir . DIRECTORY_SEPARATOR . 'absent', $tagSidecar),
                    'Could not prepare the unresolved tag link.',
                );
                $refused = false;
                try {
                    \Gallery\Services\normalize_gallery_sidecar_tags_recursively();
                } catch (\RuntimeException) {
                    $refused = true;
                }
                image_path_containment_assert(
                    $refused && !file_exists($outsideDir . DIRECTORY_SEPARATOR . 'absent'),
                    'Tag normalization must refuse an unresolved sidecar link.',
                );
                unlink($tagSidecar);
            }
        }
        if (function_exists('link') && @link($outsideTagTarget, $tagSidecar)) {
            $refused = false;
            try {
                \Gallery\Services\normalize_gallery_sidecar_tags_recursively();
            } catch (\RuntimeException) {
                $refused = true;
            }
            image_path_containment_assert(
                $refused && file_get_contents($outsideTagTarget) === $tagBytes,
                'Tag normalization must refuse a multiply linked outside inode.',
            );
        } else {
            echo "SKIP: hard-link sidecar fixture is unavailable.\n";
        }

        echo "Gallery image path symlink containment passed.\n";
    } finally {
        unset($GLOBALS['tag_sidecar_open_swap']);
        @unlink($tagSidecar);
        @unlink($tagTarget);
        @unlink($outsideTagTarget);
        @rmdir($tagFolder);
        @unlink($fileLink);
        @unlink($directoryLink);
        @unlink($danglingLink);
        @unlink($rootAlias);
        @unlink($innerAlias);
        @unlink($sidecar);
        @unlink($sidecarBackup);
        @unlink($valid);
        @unlink($outside);
        @rmdir($galleryDir);
        @rmdir($galleryStorage);
        @rmdir($outsideDir);
        @rmdir($root);
    }
}
