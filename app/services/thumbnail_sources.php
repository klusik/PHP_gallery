<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/thumbnail_sources.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Provides thumbnail size, path, URL, and srcset helpers.
 *
 * Responsibilities:
 *   - Keep behavior compatible with the previous combined implementation
 *   - Expose focused functions for one admin or thumbnail responsibility
 *   - Avoid coupling unrelated workflows into one large source file
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
 *   2026-05-12
 */

declare(strict_types=1);

/**
 * Thumbnail generation model.
 * 
 * This module owns thumbnail naming, thumbnail URLs, srcset generation, maintenance status, and image resize/write operations. It does not change gallery theme, favicon, or custom CSS settings.
 */

function thumbnail_sizes(): array
{
    return [300, 600, 800, 960, 1280, 1600];
}

/**
 * Handles thumbnail srcset logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $sizes Input used by this operation.
 * @param mixed 600 Input used by this operation.
 * @param mixed 800] Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_srcset(array $image, array $sizes = [300, 600, 800]): string
{
    return thumbnail_srcset_for_format($image, $sizes, 'jpg');
}

/**
 * Handles thumbnail webp srcset logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $sizes Input used by this operation.
 * @param mixed 600 Input used by this operation.
 * @param mixed 800] Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_webp_srcset(array $image, array $sizes = [300, 600, 800]): string
{
    return thumbnail_srcset_for_format($image, $sizes, 'webp');
}

/**
 * Handles thumbnail srcset for format logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $sizes Input used by this operation.
 * @param mixed $format Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_srcset_for_format(array $image, array $sizes, string $format): string
{
    // $entries stores an intermediate value used by the surrounding gallery workflow.
    $entries = [];
    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = find_gallery((int) $image['gallery_id']);
    if (!$gallery || !in_array($format, ['jpg', 'webp'], true)) {
        return '';
    }
    if (function_exists('thumbnail_bound_filter_sizes')) {
        $sizes = thumbnail_bound_filter_sizes($sizes, $image, $gallery);
    }
    foreach ($sizes as $size) {
        // $size stores an intermediate value used by the surrounding gallery workflow.
        $size = (int) $size;
        if (!in_array($size, thumbnail_sizes(), true)) {
            continue;
        }
        try {
            if (!is_file(thumbnail_abs_path($image, $gallery, $size, $format))) {
                continue;
            }
        } catch (RuntimeException) {
            continue;
        }
        $entries[] = thumbnail_serving_url($image, $gallery, $size, $format) . ' ' . $size . 'w';
    }
    return implode(', ', $entries);
}

/**
 * Handles gallery thumbs dir logic for the gallery application.
 * @param mixed $gallery Input used by this operation.
 * @param mixed $create Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_thumbs_dir(array $gallery, bool $create = false): string
{
    // $galleryRoot stores the absolute gallery directory once so all later checks compare against the same base path.
    $galleryRoot = gallery_abs_path((string) $gallery['folder_path']);
    // $path stores the direct thumbnail cache directory used by generated responsive thumbnails.
    $path = $galleryRoot . DIRECTORY_SEPARATOR . 'thumbs';

    if ($create && !is_dir($path)) {
        mkdir($path, 0775, true);
    }

    // The old check used path_inside(), which requires the checked path to already exist.
    // That was correct for existing thumbnail folders, but it broke safe maintenance
    // workflows that need to inspect a future/non-existing thumbs directory first.
    if (!thumbnail_path_inside_existing_gallery($galleryRoot, $path)) {
        throw new RuntimeException(t('thumbnails.error_path_outside_gallery'));
    }

    return $path;
}

/**
 * Check whether a thumbnail path is safely contained by an existing gallery path.
 *
 * path_inside() intentionally uses realpath() for both arguments, which is very
 * strict and only works when both paths already exist. Thumbnail maintenance also
 * needs to reason about a `thumbs` directory that may not exist yet, especially
 * before deciding there is nothing to delete. This helper keeps the realpath()
 * protection for the gallery root, then normalizes the candidate path manually
 * so non-existing thumbnail directories can still be validated safely.
 */
function thumbnail_path_inside_existing_gallery(string $galleryRoot, string $thumbnailPath): bool
{
    // $galleryRootReal is the trusted existing directory boundary.
    $galleryRootReal = realpath($galleryRoot);
    if ($galleryRootReal === false || !is_dir($galleryRootReal)) {
        return false;
    }

    // $candidatePath is normalized textually because the thumbnail path may not exist.
    $candidatePath = normalize_filesystem_path($thumbnailPath);
    // $normalizedRoot is normalized the same way so prefix comparison is platform-consistent.
    $normalizedRoot = normalize_filesystem_path($galleryRootReal);

    return $candidatePath === $normalizedRoot || str_starts_with($candidatePath, rtrim($normalizedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
}

/**
 * Normalize a filesystem path string without requiring the target to exist.
 *
 * This is deliberately small and local to thumbnail path safety. It resolves
 * duplicate separators, `.` segments, and `..` segments in the supplied string,
 * but it does not dereference symlinks. The trusted gallery root is still based
 * on realpath(), so symlink boundary protection remains anchored at the root.
 */
function normalize_filesystem_path(string $path): string
{
    // $path uses the current platform separator so later comparisons are consistent.
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    // $isAbsolute records whether the normalized result must keep an absolute root prefix.
    $isAbsolute = str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('~^[A-Za-z]:\\\\~', $path) === 1;
    // $prefix stores the leading root portion for Unix paths or Windows drive paths.
    $prefix = '';

    if (preg_match('~^[A-Za-z]:\\\\~', $path) === 1) {
        $prefix = substr($path, 0, 2);
        $path = substr($path, 2);
    } elseif ($isAbsolute) {
        $prefix = DIRECTORY_SEPARATOR;
    }

    // $segments stores the safe path components after resolving dot navigation.
    $segments = [];
    foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }

    // $normalized stores the path without duplicate separators or dot segments.
    $normalized = implode(DIRECTORY_SEPARATOR, $segments);
    if ($prefix === DIRECTORY_SEPARATOR) {
        return DIRECTORY_SEPARATOR . $normalized;
    }
    if ($prefix !== '') {
        return $prefix . DIRECTORY_SEPARATOR . $normalized;
    }
    return $normalized;
}

/**
 * Handles thumbnail filename logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $size Input used by this operation.
 * @param mixed $format Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_filename(array $image, int $size, string $format = 'jpg'): string
{
    if (!in_array($format, ['jpg', 'webp'], true)) {
        throw new RuntimeException(t('thumbnails.error_unsupported_format'));
    }
    return pathinfo((string) $image['filename'], PATHINFO_FILENAME) . '_thumb' . $size . '.' . $format;
}

/**
 * Handles thumbnail abs path logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $gallery Input used by this operation.
 * @param mixed $size Input used by this operation.
 * @param mixed $format Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_abs_path(array $image, array $gallery, int $size, string $format = 'jpg'): string
{
    if (!in_array($size, thumbnail_sizes(), true)) {
        throw new RuntimeException(t('thumbnails.error_unsupported_size'));
    }
    return gallery_thumbs_dir($gallery, false) . DIRECTORY_SEPARATOR . thumbnail_filename($image, $size, $format);
}

/**
 * Handles thumbnail can use static public url logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $gallery Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_can_use_static_public_url(array $image, array $gallery): bool
{
    return false;
}

/**
 * Handles gallery static file url logic for the gallery application.
 * @param mixed $gallery Input used by this operation.
 * @param mixed $relativeFilePath Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_static_file_url(array $gallery, string $relativeFilePath): string
{
    // $galleryPath stores an intermediate value used by the surrounding gallery workflow.
    $galleryPath = normalize_relative_path((string) $gallery['folder_path']);
    // $filePath stores an intermediate value used by the surrounding gallery workflow.
    $filePath = normalize_relative_path($relativeFilePath);
    // $segments stores an intermediate value used by the surrounding gallery workflow.
    $segments = array_filter(explode('/', trim($galleryPath . '/' . $filePath, '/')), static fn (string $segment): bool => $segment !== '');
    // $encoded stores an intermediate value used by the surrounding gallery workflow.
    $encoded = array_map('rawurlencode', $segments);
    return base_url('galleries/' . implode('/', $encoded));
}

/**
 * Handles thumbnail url logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $size Input used by this operation.
 * @param mixed $format Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_url(array $image, int $size, string $format = 'jpg'): string
{
    static $cache = [];
    // $cacheKey stores repeated thumbnail URL lookups inside one request.
    $normalizedFormat = $format === 'webp' ? 'webp' : 'jpg';
    $purpose = function_exists('public_render_profile_thumbnail_purpose') ? public_render_profile_thumbnail_purpose() : 'unprofiled';
    $cacheKey = (int) ($image['id'] ?? 0) . ':' . (int) $size . ':' . $normalizedFormat;
    if (array_key_exists($cacheKey, $cache)) {
        public_render_profile_count('thumbnail_lookup_cache_hits');
        public_render_profile_record_thumbnail_purpose($purpose, $size, $normalizedFormat, 'cache_hit');
        return $cache[$cacheKey];
    }
    public_render_profile_count('thumbnail_lookups');
    $startedAt = microtime(true);
    try {
        return $cache[$cacheKey] = public_render_profile_span('thumbnail_lookup', static function () use ($image, $size, $format): string {
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) $image['gallery_id']);
    if ($gallery) {
        if (function_exists('thumbnail_bound_fallback_size')) {
            $size = thumbnail_bound_fallback_size($image, $size, $gallery);
        }
        try {
            // $path stores an intermediate value used by the surrounding gallery workflow.
            $path = thumbnail_abs_path($image, $gallery, $size, $format);
            if (public_render_profile_is_file($path)) {
                public_render_profile_count('thumbnail_direct_hits');
                return thumbnail_serving_url($image, $gallery, $size, $format);
            }
            // $fallback stores an intermediate value used by the surrounding gallery workflow.
            $fallback = thumbnail_existing_fallback($image, $gallery, $size, $format);
            if ($fallback !== null) {
                return thumbnail_serving_url($image, $gallery, $fallback['size'], $fallback['format']);
            }
        } catch (RuntimeException) {
            return public_path_schema_ready() ? image_public_media_url($image, $gallery) : url_for('media', ['id' => $image['id']]);
        }
        public_render_profile_count('thumbnail_media_fallbacks');
        return public_path_schema_ready() ? image_public_media_url($image, $gallery) : url_for('media', ['id' => $image['id']]);
    }
    public_render_profile_count('thumbnail_media_fallbacks');
    return url_for('media', ['id' => $image['id']]);
        });
    } finally {
        public_render_profile_record_thumbnail_purpose($purpose, $size, $normalizedFormat, 'lookup', (microtime(true) - $startedAt) * 1000);
    }
}

/**
 * Handles thumbnail serving url logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $gallery Input used by this operation.
 * @param mixed $size Input used by this operation.
 * @param mixed $format Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_serving_url(array $image, array $gallery, int $size, string $format = 'jpg'): string
{
    if (public_path_schema_ready()) {
        return image_public_thumbnail_url($image, $gallery, $size, $format);
    }
    if (thumbnail_can_use_static_public_url($image, $gallery)) {
        return gallery_static_file_url($gallery, 'thumbs/' . thumbnail_filename($image, $size, $format));
    }
    return url_for('thumb', ['id' => $image['id'], 'size' => $size, 'format' => $format]);
}

/**
 * Handles thumbnail existing fallback logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $gallery Input used by this operation.
 * @param mixed $preferredSize Input used by this operation.
 * @param mixed $preferredFormat Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_existing_fallback(array $image, array $gallery, int $preferredSize, string $preferredFormat = 'jpg'): ?array
{
    public_render_profile_count('thumbnail_fallback_searches');
    return public_render_profile_span('thumbnail_fallback_search', static function () use ($image, $gallery, $preferredSize, $preferredFormat): ?array {
    // Variable $sizes stores this steps working value.
    $sizes = thumbnail_sizes();
    if (function_exists('thumbnail_bound_filter_sizes')) {
        $sizes = thumbnail_bound_filter_sizes($sizes, $image, $gallery);
    }
    usort($sizes, static function (int $left, int $right) use ($preferredSize): int {
        return abs($left - $preferredSize) <=> abs($right - $preferredSize);
    });
    // Variable $formats stores this steps working value.
    $formats = array_values(array_unique([$preferredFormat, 'jpg', 'webp']));
    foreach ($sizes as $size) {
        foreach ($formats as $format) {
            if (!in_array($format, ['jpg', 'webp'], true)) {
                continue;
            }
            public_render_profile_count('thumbnail_fallback_checks');
            if (public_render_profile_is_file(thumbnail_abs_path($image, $gallery, (int) $size, $format))) {
                public_render_profile_count('thumbnail_fallback_hits');
                return ['size' => (int) $size, 'format' => $format];
            }
        }
    }
    return null;
    });
}
