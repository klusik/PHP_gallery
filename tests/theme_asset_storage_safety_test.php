<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/theme_asset_storage_safety_test.php
 * Module Type: Regression Test
 * Purpose: Reject stored Theme paths that escape their owned directories.
 * Responsibilities: Test valid media, traversal, symlink escape and public raster MIME policy.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Read one isolated fixture setting.
     *
     * @param string $key Setting key.
     * @param string $default Fallback fixture path.
     * @return string Stored fixture path or fallback.
     */
    function app_setting(string $key, string $default = ''): string
    {
        return (string) ($GLOBALS['theme_asset_test_settings'][$key] ?? $default);
    }

    /**
     * Return a stable label without loading the translation service.
     *
     * @param string $key Translation key.
     * @param string $fallback Default translation.
     * @return string Fixture label.
     */
    function t(string $key, string $fallback = ''): string
    {
        return $fallback !== '' ? $fallback : $key;
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/helpers_files.php';
    require_once dirname(__DIR__) . '/app/services/gallery_backgrounds.php';
    require_once dirname(__DIR__) . '/app/services/gallery_branding.php';

    /**
     * Enforce one isolated Theme asset regression.
     *
     * @param bool $condition Required invariant.
     * @param string $name Test case name.
     * @return void No result when successful.
     */
    function theme_asset_safety_assert(bool $condition, string $name): void
    {
        if (!$condition) {
            throw new \RuntimeException($name);
        }
    }

    $root = dirname(__DIR__);
    $id = bin2hex(random_bytes(6));
    $dirs = [$root . '/cache', $root . '/cache/theme-background', $root . '/cache/theme-branding'];
    $created = [];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0700, true)) {
                throw new \RuntimeException('Could not create Theme test directory.');
            }
            $created[] = $dir;
        }
    }
    $bg = $dirs[1] . '/fixture-' . $id . '.png';
    $brand = $dirs[2] . '/fixture-' . $id . '.png';
    $outside = $dirs[0] . '/fixture-outside-' . $id . '.png';
    $bgLink = $dirs[1] . '/link-' . $id . '.png';
    $brandLink = $dirs[2] . '/link-' . $id . '.png';
    try {
        foreach ([$bg, $brand, $outside] as $path) {
            if (file_put_contents($path, 'fixture') === false) {
                throw new \RuntimeException('Theme fixture file write failed.');
            }
        }
        $validBg = 'cache/theme-background/fixture-' . $id . '.png';
        $validBrand = 'cache/theme-branding/fixture-' . $id . '.png';
        $other = 'cache/fixture-outside-' . $id . '.png';
        theme_asset_safety_assert(\Gallery\Services\theme_background_existing_path($validBg) === $validBg, 'Valid saved background.');
        theme_asset_safety_assert(\Gallery\Services\theme_background_existing_path($other) === null, 'Reject background outside owned cache.');
        theme_asset_safety_assert(\Gallery\Services\theme_background_existing_path('cache/theme-background/../fixture-outside-' . $id . '.png') === null, 'Reject background traversal.');

        $GLOBALS['theme_asset_test_settings'] = ['theme_branding_banner_path' => $validBrand, 'theme_background_original_path' => $validBg];
        theme_asset_safety_assert(\Gallery\Services\theme_background_original_path() === $validBg, 'Valid persisted original.');
        theme_asset_safety_assert(\Gallery\Services\theme_branding_asset_path('banner') === $validBrand, 'Valid persisted banner.');
        $GLOBALS['theme_asset_test_settings']['theme_branding_banner_path'] = $other;
        theme_asset_safety_assert(\Gallery\Services\theme_branding_asset_path('banner') === null, 'Reject external branding file.');
        $GLOBALS['theme_asset_test_settings']['theme_branding_banner_path'] = 'cache/theme-branding/../fixture-outside-' . $id . '.png';
        theme_asset_safety_assert(\Gallery\Services\theme_branding_asset_path('banner') === null, 'Reject branding traversal.');

        if (function_exists('symlink') && @symlink($outside, $bgLink)) {
            theme_asset_safety_assert(\Gallery\Services\theme_background_existing_path('cache/theme-background/link-' . $id . '.png') === null, 'Reject background symlink escape.');
        }
        if (function_exists('symlink') && @symlink($outside, $brandLink)) {
            $GLOBALS['theme_asset_test_settings']['theme_branding_banner_path'] = 'cache/theme-branding/link-' . $id . '.png';
            theme_asset_safety_assert(\Gallery\Services\theme_branding_asset_path('banner') === null, 'Reject branding symlink escape.');
        }

        theme_asset_safety_assert(\Gallery\Services\gallery_branding_mime_extension('image/svg+xml') === null, 'SVG must not qualify as public Theme raster.');
        $source = (string) file_get_contents($root . '/app/controllers/theme_assets.php');
        $first = strpos($source, 'function cms_theme_background_asset(): void');
        $second = strpos($source, 'function cms_theme_branding_asset(): void');
        $third = strpos($source, 'function cms_favicon_asset(): void');
        $backgroundHandler = $first !== false && $second !== false ? substr($source, $first, $second - $first) : '';
        $brandingHandler = $second !== false && $third !== false ? substr($source, $second, $third - $second) : '';
        theme_asset_safety_assert(
            str_contains($backgroundHandler, 'gallery_branding_mime_extension($mime) === null')
                && str_contains($brandingHandler, 'gallery_branding_mime_extension($mime) === null'),
            'Public Theme controllers must use the supported raster MIME allowlist.'
        );
        echo "Theme asset path and MIME safety passed.\n";
    } finally {
        foreach ([$bgLink, $brandLink, $bg, $brand, $outside] as $path) {
            @unlink($path);
        }
        foreach (array_reverse($created) as $dir) {
            @rmdir($dir);
        }
    }
}
