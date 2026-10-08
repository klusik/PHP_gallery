<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_migration_asset_containment_test.php
 * Module Type: Regression Test
 * Purpose: Prevent migration source assets from escaping their physical gallery.
 * Responsibilities: Exercise allowed nested assets and symlink refusal in manifests and API delivery.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Resolve the test's isolated gallery directory.
     *
     * @param string $relativePath Persisted folder identity.
     * @return string Absolute fixture gallery directory.
     */
    function gallery_abs_path(string $relativePath): string
    {
        return (string) $GLOBALS['migration_containment_gallery_root'];
    }

    /**
     * Resolve a gallery from the synthetic migration source.
     *
     * @param int $id Source gallery identity.
     * @param bool $fresh Request a fresh synthetic row.
     * @return array<string,mixed>|null One gallery row or null.
     */
    function find_gallery(int $id, bool $fresh = false): ?array
    {
        return $id === 1 ? $GLOBALS['migration_containment_gallery'] : null;
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/helpers_files.php';
    require_once dirname(__DIR__) . '/app/services/gallery_migration.php';

    /**
     * Assert that migration source lookup obeys filesystem containment.
     *
     * @param bool $condition Expected invariant.
     * @param string $label Regression case label.
     * @return void No result on success.
     */
    function migration_containment_check(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new \RuntimeException($label);
        }
    }

    $root = sys_get_temp_dir() . '/php-gallery-migration-guard-' . bin2hex(random_bytes(8));
    $galleryRoot = $root . '/gallery';
    $assetDir = $galleryRoot . '/branding';
    if (!mkdir($assetDir, 0700, true)) {
        throw new \RuntimeException('Could not create migration containment fixture.');
    }
    $validFile = $assetDir . '/cover.png';
    $outsideFile = $root . '/outside.png';
    $symlinkFile = $assetDir . '/banner.png';
    $gallery = [
        'id' => 1,
        'folder_path' => 'gallery',
        'cover_image_path' => 'branding/cover.png',
        'banner_image_path' => 'branding/banner.png',
        'logo_image_path' => null,
        'separator_image_path' => null,
    ];
    $GLOBALS['migration_containment_gallery'] = $gallery;
    $GLOBALS['migration_containment_gallery_root'] = $galleryRoot;
    try {
        file_put_contents($validFile, 'valid nested image fixture');
        file_put_contents($outsideFile, 'outside gallery fixture');

        $validAssets = \Gallery\Services\gallery_migration_gallery_assets($gallery);
        migration_containment_check(count($validAssets) === 1 && ($validAssets[0]['kind'] ?? '') === 'cover_image_path',
            'Valid nested gallery cover must be included in the export manifest.');

        $valid = \Gallery\Services\gallery_migration_source_asset_descriptor(
            1, ['scope' => 'gallery', 'source_gallery_id' => 1, 'kind' => 'cover_image_path'], false
        );
        migration_containment_check($valid['path'] === $validFile, 'Nested gallery cover must be exportable.');

        if (function_exists('symlink') && @symlink($outsideFile, $symlinkFile)) {
            $assets = \Gallery\Services\gallery_migration_gallery_assets($gallery);
            migration_containment_check(count($assets) === 1,
                'Migration manifest must not include a symlink to a file outside the gallery.');
            $denied = false;
            try {
                \Gallery\Services\gallery_migration_source_asset_descriptor(
                    1, ['scope' => 'gallery', 'source_gallery_id' => 1, 'kind' => 'banner_image_path'], false
                );
            } catch (\RuntimeException) {
                $denied = true;
            }
            migration_containment_check($denied, 'Migration API must refuse a symlink escaping its gallery.');
        }

        echo "Migration asset containment passed.\n";
    } finally {
        @unlink($symlinkFile);
        @unlink($validFile);
        @unlink($outsideFile);
        @rmdir($assetDir);
        @rmdir($galleryRoot);
        @rmdir($root);
    }
}
