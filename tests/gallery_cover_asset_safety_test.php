<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_cover_asset_safety_test.php
 * Module Type: Regression Test
 * Purpose: Reject unsafe persisted gallery cover paths before public media streaming.
 * Responsibilities: Cover nested images, traversal, missing files, symlink escapes and controller wiring.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Resolve an isolated test gallery root without connecting to the database.
     *
     * @param string $relativePath Persisted gallery folder relative to the fixture root.
     * @return string Absolute gallery path for this fixture.
     */
    function gallery_abs_path(string $relativePath): string
    {
        return $GLOBALS['gallery_cover_fixture_root'] . DIRECTORY_SEPARATOR . trim($relativePath, '/\\');
    }
}

namespace {
    use function Gallery\Services\gallery_cover_asset_abs_path;

    require_once dirname(__DIR__) . '/app/helpers_files.php';
    require_once dirname(__DIR__) . '/app/services/gallery_covers.php';

    /**
     * Fail precisely when one gallery cover path does not match its expected safety result.
     *
     * @param ?string $expected Expected absolute cover file or null for rejection.
     * @param ?string $actual Actual resolved file or null.
     * @param string $label Regression case name.
     * @return void No value on success.
     */
    function gallery_cover_safety_assert(?string $expected, ?string $actual, string $label): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    $fixtureRoot = sys_get_temp_dir() . '/php-gallery-cover-' . bin2hex(random_bytes(8));
    $galleryRoot = $fixtureRoot . '/album';
    $nestedRoot = $galleryRoot . '/nested';
    if (!mkdir($nestedRoot, 0700, true) && !is_dir($nestedRoot)) {
        throw new RuntimeException('Unable to create disposable cover fixture.');
    }
    $GLOBALS['gallery_cover_fixture_root'] = $fixtureRoot;

    $validFile = $nestedRoot . '/cover.png';
    $outsideFile = $fixtureRoot . '/outside.png';
    $linkedFile = $galleryRoot . '/linked.png';
    $linkedDirectory = $galleryRoot . '/escape';
    try {
        file_put_contents($validFile, 'fixture cover');
        file_put_contents($outsideFile, 'outside fixture image');
        $gallery = ['folder_path' => 'album', 'cover_image_path' => 'nested/cover.png'];

        gallery_cover_safety_assert($validFile, gallery_cover_asset_abs_path($gallery), 'Nested gallery cover');
        $gallery['cover_image_path'] = 'nested\\cover.png';
        gallery_cover_safety_assert($validFile, gallery_cover_asset_abs_path($gallery), 'Windows path separator');
        $gallery['cover_image_path'] = '../outside.png';
        gallery_cover_safety_assert(null, gallery_cover_asset_abs_path($gallery), 'Parent traversal');
        $gallery['cover_image_path'] = '..\\outside.png';
        gallery_cover_safety_assert(null, gallery_cover_asset_abs_path($gallery), 'Windows parent traversal');
        $gallery['cover_image_path'] = 'nested/missing.png';
        gallery_cover_safety_assert(null, gallery_cover_asset_abs_path($gallery), 'Missing cover');
        $gallery['cover_image_path'] = 'nested';
        gallery_cover_safety_assert(null, gallery_cover_asset_abs_path($gallery), 'Directory is not a cover');
        $gallery['cover_image_path'] = '';
        gallery_cover_safety_assert(null, gallery_cover_asset_abs_path($gallery), 'Empty cover');

        if (function_exists('symlink') && @symlink($outsideFile, $linkedFile)) {
            $gallery['cover_image_path'] = 'linked.png';
            gallery_cover_safety_assert(null, gallery_cover_asset_abs_path($gallery), 'External file symlink');
        }
        if (function_exists('symlink') && @symlink($fixtureRoot, $linkedDirectory)) {
            $gallery['cover_image_path'] = 'escape/outside.png';
            gallery_cover_safety_assert(null, gallery_cover_asset_abs_path($gallery), 'External directory symlink');
        }

        $controller = (string) file_get_contents(dirname(__DIR__) . '/app/controllers/public_media.php');
        $start = strpos($controller, 'function cms_gallery_cover_asset(): void');
        $end = strpos($controller, 'function cms_gallery_branding_asset(): void', $start === false ? 0 : $start);
        $handler = $start !== false && $end !== false ? substr($controller, $start, $end - $start) : '';
        if (!str_contains($handler, 'gallery_cover_asset_abs_path($gallery)')) {
            throw new RuntimeException('Gallery cover controller bypasses the canonical safe cover resolver.');
        }
        if (!str_contains($handler, 'gallery_branding_mime_extension($mime) === null')
            || str_contains($handler, "str_starts_with(\$mime, 'image/')")) {
            throw new RuntimeException('Gallery cover controller must reject active or unsupported image MIME types.');
        }

        echo "Gallery cover path safety passed.\n";
    } finally {
        @unlink($linkedFile);
        @unlink($linkedDirectory);
        @unlink($validFile);
        @unlink($outsideFile);
        @rmdir($nestedRoot);
        @rmdir($galleryRoot);
        @rmdir($fixtureRoot);
    }
}
