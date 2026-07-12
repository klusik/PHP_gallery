<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/support/thumbnail_compatibility_shims.php
 * Module Type: Test Support
 *
 * Purpose:
 *   Provides namespaced dependencies for thumbnail compatibility model tests.
 *
 * Responsibilities:
 *   - Persist deterministic app settings in process memory
 *   - Provide controlled thumbnail paths and supported source behavior
 *   - Provide safe path and DNG helpers under current production namespaces
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
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-07-12
 */

declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Return one deterministic app setting for thumbnail compatibility tests.
     *
     * @param string $key Lookup key.
     * @param ?string $default Default value when no explicit value is available.
     * @return ?string Text result for the caller.
     */
    function app_setting(string $key, ?string $default = null): ?string
    {
        $settings = $GLOBALS['thumbnail_compatibility_test_settings'] ?? [];
        return array_key_exists($key, $settings) ? (string) $settings[$key] : $default;
    }

    /**
     * Persist one deterministic app setting for thumbnail compatibility tests.
     *
     * @param string $key Lookup key.
     * @param string $value Value to process.
     */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['thumbnail_compatibility_test_settings'][$key] = $value;
    }

    /**
     * Report DNG derivative support for format policy tests.
     *
     * @return bool True when the condition matches.
     */
    function dng_derivative_generation_supported(): bool
    {
        return true;
    }

    /**
     * Return the thumbnail sizes covered by cleanup tests.
     *
     * @return array<int,int> Thumbnail sizes.
     */
    function thumbnail_sizes(): array
    {
        return [300, 600];
    }

    /**
     * Resolve one deterministic generated thumbnail path.
     *
     * @param array $image Image row or image data.
     * @param array $gallery Gallery row or gallery data.
     * @param int $size Size value.
     * @param string $format Format value.
     * @return string Text result for the caller.
     */
    function thumbnail_abs_path(array $image, array $gallery, int $size, string $format = 'jpg'): string
    {
        $base = pathinfo((string) $image['filename'], PATHINFO_FILENAME);
        return rtrim((string) $gallery['thumbs_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $base . '_thumb' . $size . '.' . $format;
    }
}

namespace Gallery\Core {
    /**
     * Return whether a test source path uses the DNG extension.
     *
     * @param string $path Filesystem path.
     * @return bool True when the condition matches.
     */
    function is_dng_image_path(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'dng';
    }

    /**
     * Return whether a resolved test path remains inside its expected root.
     *
     * @param string $root Root value.
     * @param string $path Filesystem path.
     * @return bool True when the condition matches.
     */
    function path_inside(string $root, string $path): bool
    {
        $rootReal = realpath($root);
        $pathReal = realpath($path);
        return is_string($rootReal)
            && is_string($pathReal)
            && str_starts_with($pathReal, rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }
}
