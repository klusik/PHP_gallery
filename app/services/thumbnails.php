<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/thumbnails.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides reusable application logic for gallery data, media, settings, or maintenance workflows.
 *
 * Responsibilities:
 *   - Keep domain logic reusable outside controllers
 *   - Protect existing behavior with small focused functions
 *   - Return predictable values for callers
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
 *   2026-05-04
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

/**
 * Return a stable request-local cache key for one image thumbnail bundle.
 */
function thumbnail_bundle_cache_key(array $image): string
{
    return implode(':', [
        (int) ($image['id'] ?? 0),
        (int) ($image['gallery_id'] ?? 0),
        sha1((string) ($image['relative_path'] ?? '') . '|' . (string) ($image['filename'] ?? '')),
    ]);
}

/**
 * Return a normalized thumbnail format name supported by the gallery.
 */
function thumbnail_bundle_normalize_format(string $format): string
{
    return $format === 'webp' ? 'webp' : 'jpg';
}

/**
 * Return the safe browser media URL used when no generated thumbnail exists.
 */
function thumbnail_bundle_media_url(array $image, ?array $gallery): string
{
    if ($gallery) {
        return public_path_schema_ready() ? image_public_media_url($image, $gallery) : url_for('media', ['id' => $image['id']]);
    }
    return url_for('media', ['id' => $image['id']]);
}

/**
 * Resolve all generated thumbnail variants for one image once during this request.
 *
 * This cache is deliberately request-local. It does not persist thumbnail metadata
 * into the database or filesystem, so newly uploaded images, partially generated
 * thumbnails, deleted thumbnails, regenerated thumbnails, and DNG display
 * derivatives continue to use the existing safe fallback behavior on the next
 * request.
 *
 * @return array{
 *     image:array,
 *     gallery:?array,
 *     media_url:string,
 *     sizes:array<int,int>,
 *     variants:array<string,array<int,string>>
 * }
 */
function thumbnail_bundle(array $image): array
{
    static $cache = [];

    public_render_profile_count('thumbnail_bundle_requests');
    // $cacheKey stores the request-local identity for this image and source path.
    $cacheKey = thumbnail_bundle_cache_key($image);
    if (array_key_exists($cacheKey, $cache)) {
        public_render_profile_count('thumbnail_bundle_cache_hits');
        return $cache[$cacheKey];
    }

    public_render_profile_count('thumbnail_bundle_cache_misses');
    return $cache[$cacheKey] = public_render_profile_span('thumbnail_bundle', static function () use ($image): array {
        // $gallery stores the current gallery row used to build safe thumbnail and media URLs.
        $gallery = find_gallery((int) ($image['gallery_id'] ?? 0)) ?: null;
        // $sizes stores generated thumbnail sizes that are valid for this image in this gallery.
        $sizes = thumbnail_sizes();
        if ($gallery && function_exists('thumbnail_bound_filter_sizes')) {
            $sizes = thumbnail_bound_filter_sizes($sizes, $image, $gallery);
        }
        $sizes = array_values(array_unique(array_map('intval', $sizes)));

        // $bundle stores all safe generated thumbnail URLs discovered for this request.
        $bundle = [
            'image' => $image,
            'gallery' => $gallery,
            'media_url' => thumbnail_bundle_media_url($image, $gallery),
            'sizes' => $sizes,
            'variants' => [
                'jpg' => [],
                'webp' => [],
            ],
        ];

        if (!$gallery) {
            return $bundle;
        }

        foreach ($sizes as $size) {
            if (!in_array($size, thumbnail_sizes(), true)) {
                continue;
            }
            foreach (['jpg', 'webp'] as $format) {
                try {
                    // $path stores one concrete generated thumbnail candidate.
                    $path = thumbnail_abs_path($image, $gallery, $size, $format);
                    if (!public_render_profile_is_file($path)) {
                        continue;
                    }
                    public_render_profile_count('thumbnail_bundle_variant_hits');
                    $bundle['variants'][$format][$size] = thumbnail_serving_url($image, $gallery, $size, $format);
                } catch (RuntimeException) {
                    continue;
                }
            }
        }

        ksort($bundle['variants']['jpg']);
        ksort($bundle['variants']['webp']);
        return $bundle;
    });
}

/**
 * Return the effective requested thumbnail size for a bundle URL lookup.
 */
function thumbnail_bundle_effective_size(array $bundle, int $preferredSize): int
{
    $gallery = $bundle['gallery'] ?? null;
    $image = $bundle['image'] ?? [];
    if (is_array($gallery) && function_exists('thumbnail_bound_fallback_size')) {
        return thumbnail_bound_fallback_size($image, $preferredSize, $gallery);
    }
    return $preferredSize;
}

/**
 * Select the best generated thumbnail variant from a precomputed request bundle.
 *
 * @return array{url:string,size:int,format:string,is_media_fallback:bool,is_exact:bool}
 */
function thumbnail_bundle_select_variant(array $bundle, int $preferredSize, string $preferredFormat = 'jpg'): array
{
    // $preferredFormat stores the caller's preferred browser format.
    $preferredFormat = thumbnail_bundle_normalize_format($preferredFormat);
    // $effectiveSize stores the size after per-gallery bounds are applied.
    $effectiveSize = thumbnail_bundle_effective_size($bundle, $preferredSize);
    // $variants stores discovered generated variants indexed by format and size.
    $variants = is_array($bundle['variants'] ?? null) ? $bundle['variants'] : ['jpg' => [], 'webp' => []];

    if (isset($variants[$preferredFormat][$effectiveSize])) {
        return [
            'url' => (string) $variants[$preferredFormat][$effectiveSize],
            'size' => $effectiveSize,
            'format' => $preferredFormat,
            'is_media_fallback' => false,
            'is_exact' => true,
        ];
    }

    // $candidateSizes stores existing generated sizes closest to the preferred size first.
    $candidateSizes = [];
    foreach (['jpg', 'webp'] as $format) {
        foreach (array_keys($variants[$format] ?? []) as $size) {
            $candidateSizes[(int) $size] = (int) $size;
        }
    }
    usort($candidateSizes, static function (int $left, int $right) use ($effectiveSize): int {
        return abs($left - $effectiveSize) <=> abs($right - $effectiveSize);
    });

    // $formats stores the same fallback order used by the legacy resolver.
    $formats = array_values(array_unique([$preferredFormat, 'jpg', 'webp']));
    foreach ($candidateSizes as $size) {
        foreach ($formats as $format) {
            if (isset($variants[$format][$size])) {
                public_render_profile_count('thumbnail_bundle_fallback_hits');
                return [
                    'url' => (string) $variants[$format][$size],
                    'size' => (int) $size,
                    'format' => $format,
                    'is_media_fallback' => false,
                    'is_exact' => false,
                ];
            }
        }
    }

    public_render_profile_count('thumbnail_bundle_media_fallbacks');
    return [
        'url' => (string) ($bundle['media_url'] ?? url_for('media', ['id' => (int) (($bundle['image']['id'] ?? 0))])),
        'size' => $effectiveSize,
        'format' => 'media',
        'is_media_fallback' => true,
        'is_exact' => false,
    ];
}

/**
 * Return one safe thumbnail URL from a request-local thumbnail bundle.
 */
function thumbnail_bundle_url(array $bundle, int $preferredSize, string $preferredFormat = 'jpg'): string
{
    $format = thumbnail_bundle_normalize_format($preferredFormat);
    public_render_profile_record_thumbnail_purpose(null, $preferredSize, $format, 'bundle');
    $selected = thumbnail_bundle_select_variant($bundle, $preferredSize, $preferredFormat);
    return (string) $selected['url'];
}

/**
 * Return a srcset string using only variants already resolved in a thumbnail bundle.
 */
function thumbnail_bundle_srcset(array $bundle, array $sizes, string $format): string
{
    // $format stores the requested image format for this source set.
    $format = thumbnail_bundle_normalize_format($format);
    // $variants stores discovered generated thumbnails for the requested format.
    $variants = is_array($bundle['variants'][$format] ?? null) ? $bundle['variants'][$format] : [];
    if (!$variants) {
        return '';
    }

    // $requestedSizes stores caller-requested candidates after gallery-specific bounds.
    $requestedSizes = array_values(array_unique(array_map('intval', $sizes)));
    $gallery = $bundle['gallery'] ?? null;
    $image = $bundle['image'] ?? [];
    if (is_array($gallery) && function_exists('thumbnail_bound_filter_sizes')) {
        $requestedSizes = thumbnail_bound_filter_sizes($requestedSizes, $image, $gallery);
    }

    // $entries stores srcset candidates that already exist on disk.
    $entries = [];
    foreach ($requestedSizes as $size) {
        $size = (int) $size;
        if (isset($variants[$size])) {
            $entries[] = (string) $variants[$size] . ' ' . $size . 'w';
        }
    }
    return implode(', ', $entries);
}

/**
 * Return whether one image row represents a DNG original that needs display derivatives.
 */
function image_uses_dng_display_derivatives(array $image): bool
{
    return is_dng_image_path((string) ($image['relative_path'] ?? $image['filename'] ?? ''));
}

/**
 * Return whether DNG display derivative generation is available.
 */
function dng_derivative_generation_supported(): bool
{
    if (function_exists('dng_conversion_supported') && dng_conversion_supported()) {
        return true;
    }
    return function_exists('dng_embedded_preview_supported') && dng_embedded_preview_supported();
}

/**
 * Return a readable status explaining whether DNG derivative generation can run.
 */
function dng_derivative_generation_status(): array
{
    if (function_exists('dng_conversion_supported') && dng_conversion_supported()) {
        return ['supported' => true, 'reason' => t('thumbnail.dng_support.imagick_raw')];
    }
    if (function_exists('dng_embedded_preview_supported') && dng_embedded_preview_supported()) {
        return ['supported' => true, 'reason' => t('thumbnail.dng_support.embedded_preview')];
    }
    if (!dng_embedded_preview_supported()) {
        return ['supported' => false, 'reason' => t('thumbnail.dng_support.preview_decode_unavailable')];
    }
    if (!extension_loaded('imagick') || !class_exists(Imagick::class)) {
        return ['supported' => false, 'reason' => t('thumbnail.dng_support.imagick_missing')];
    }
    foreach (['DNG', 'WEBP', 'JPEG'] as $format) {
        if (!imagick_format_supported($format)) {
            return ['supported' => false, 'reason' => t('thumbnail.dng_support.format_missing', ['format' => $format])];
        }
    }
    return ['supported' => false, 'reason' => t('thumbnail.dng_support.no_path')];
}

/**
 * Return the generated WebP master filename for one DNG source.
 */
function dng_display_master_filename(array $image): string
{
    // $base stores the readable part of the derivative filename.
    $base = pathinfo((string) ($image['filename'] ?? 'image'), PATHINFO_FILENAME);
    if ($base === '') {
        $base = 'image';
    }
    return $base . '_display_' . (int) ($image['id'] ?? 0) . '.webp';
}

/**
 * Return the absolute generated WebP master path for one DNG source.
 */
function dng_display_master_abs_path(array $image, array $gallery, bool $create = false): string
{
    return gallery_thumbs_dir($gallery, $create) . DIRECTORY_SEPARATOR . dng_display_master_filename($image);
}

/**
 * Return a stable source MIME value for derivative decisions.
 */
function image_source_mime_for_derivatives(string $sourcePath, array $image = []): string
{
    if (image_uses_dng_display_derivatives($image) || is_dng_image_path($sourcePath)) {
        return 'image/x-adobe-dng';
    }

    // $info stores PHP image metadata for ordinary browser-displayable images.
    $info = @getimagesize($sourcePath);
    return is_array($info) ? (string) ($info['mime'] ?? '') : '';
}

/**
 * Return the file that public media routes are allowed to stream for visible display.
 *
 * @return array{path:string,mime:string,filename:string,variant:string}|null
 */
function image_public_display_file(array $image, array $gallery, bool $createIfMissing = false): ?array
{
    // $sourcePath stores the original uploaded source file.
    $sourcePath = image_abs_path($image, $gallery);
    if (!is_file($sourcePath)) {
        return null;
    }

    if (image_uses_dng_display_derivatives($image)) {
        try {
            // $masterPath stores the generated browser-displayable WebP master.
            $masterPath = dng_display_master_abs_path($image, $gallery, $createIfMissing);
        } catch (RuntimeException) {
            return null;
        }
        // $sourceMtime stores the original DNG timestamp used to refresh stale derivatives.
        $sourceMtime = filemtime($sourcePath) ?: 0;
        if ($createIfMissing && (!is_file($masterPath) || filemtime($masterPath) < $sourceMtime)) {
            create_dng_display_master($sourcePath, $masterPath);
        }
        if (!is_file($masterPath)) {
            return null;
        }
        return [
            'path' => $masterPath,
            'mime' => 'image/webp',
            'filename' => dng_display_master_filename($image),
            'variant' => 'dng_master',
        ];
    }

    // $finfo stores an intermediate value used by the surrounding gallery workflow.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    // $mime stores an intermediate value used by the surrounding gallery workflow.
    $mime = (string) ($finfo->file($sourcePath) ?: mime_content_type($sourcePath));
    if (!str_starts_with($mime, 'image/')) {
        return null;
    }

    return [
        'path' => $sourcePath,
        'mime' => $mime,
        'filename' => basename((string) ($image['filename'] ?? basename($sourcePath))),
        'variant' => 'original',
    ];
}

/**
 * Create or refresh the full-size WebP display master for a DNG source.
 */
function create_dng_display_master(string $sourcePath, string $targetPath): bool
{
    if (write_dng_imagick_derivative($sourcePath, $targetPath, 'webp', null)) {
        return true;
    }
    return write_dng_embedded_preview_derivative($sourcePath, $targetPath, 'webp', null);
}

/**
 * Write one DNG derivative through Imagick.
 */
function write_dng_imagick_derivative(string $sourcePath, string $targetPath, string $format, ?int $maxSide): bool
{
    if (!function_exists('dng_conversion_supported') || !dng_conversion_supported()) {
        return false;
    }
    if (!in_array($format, ['jpg', 'webp'], true)) {
        return false;
    }

    // $image stores the Imagick object so all code paths can release it.
    $image = null;
    try {
        $image = new Imagick($sourcePath);
        if ($image->getNumberImages() > 1) {
            $image->setIteratorIndex(0);
        }
        if (method_exists($image, 'autoOrient')) {
            $image->autoOrient();
        } elseif (method_exists($image, 'autoOrientImage')) {
            $image->autoOrientImage();
        }
        if ($maxSide !== null) {
            $image->thumbnailImage($maxSide, $maxSide, true, true);
        }
        // Apple ProRAW embedded previews may use wide-gamut or grayscale-tagged profiles.
        // Force standard sRGB output so generated browser derivatives preserve expected colors.
        if (method_exists($image, 'transformImageColorspace')) {
            $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        } else {
            $image->setImageColorspace(Imagick::COLORSPACE_SRGB);
        }
        if ($format === 'jpg') {
            $image->setImageBackgroundColor('white');
            $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $image->setImageFormat('jpeg');
        } else {
            $image->setImageFormat('webp');
        }
        $image->setImageCompressionQuality($format === 'jpg' ? 86 : 88);
        // $written stores whether Imagick successfully wrote the derivative file.
        $written = $image->writeImage($targetPath);
        $image->clear();
        $image->destroy();
        return $written && is_file($targetPath);
    } catch (Throwable) {
        thumbnail_remove_partial_file($targetPath);
        if ($image instanceof Imagick) {
            $image->clear();
            $image->destroy();
        }
        return false;
    }
}


/**
 * Write one resized derivative from an extracted DNG JPEG preview through Imagick.
 */
function write_dng_preview_derivative_with_imagick(string $previewPath, string $targetPath, string $format, int $maxSide): bool
{
    if (!class_exists(Imagick::class)) {
        return false;
    }
    if ($format === 'webp' && !thumbnail_imagick_webp_available()) {
        return false;
    }
    if ($format === 'jpg' && !imagick_format_supported('JPEG')) {
        return false;
    }
    // $image stores the preview decoder instance so it can always be released.
    $image = null;
    try {
        $image = new Imagick($previewPath);
        if (method_exists($image, 'autoOrient')) {
            $image->autoOrient();
        } elseif (method_exists($image, 'autoOrientImage')) {
            $image->autoOrientImage();
        }
        $image->thumbnailImage($maxSide, $maxSide, true, true);
        // Apple ProRAW previews may decode with incorrect grayscale-like output unless
        // the derivative is explicitly converted into sRGB before encoding.
        if (method_exists($image, 'transformImageColorspace')) {
            $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        } else {
            $image->setImageColorspace(Imagick::COLORSPACE_SRGB);
        }
        if ($format === 'jpg') {
            $image->setImageBackgroundColor('white');
            $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(86);
        } else {
            $image->setImageFormat('webp');
            $image->setImageCompressionQuality(88);
        }
        // $written stores whether Imagick successfully wrote the preview derivative.
        $written = $image->writeImage($targetPath);
        $image->clear();
        $image->destroy();
        return $written && is_file($targetPath);
    } catch (Throwable) {
        thumbnail_remove_partial_file($targetPath);
        if ($image instanceof Imagick) {
            $image->clear();
            $image->destroy();
        }
        return false;
    }
}

/**
 * Write one DNG derivative from the embedded JPEG preview fallback.
 */
function write_dng_embedded_preview_derivative(string $sourcePath, string $targetPath, string $format, ?int $maxSide): bool
{
    if (!function_exists('dng_extract_embedded_jpeg_preview') || !dng_embedded_preview_supported()) {
        return false;
    }
    if (!in_array($format, ['jpg', 'webp'], true)) {
        return false;
    }

    // $temporaryPath stores the extracted JPEG preview used as the resize source.
    $temporaryPath = tempnam(sys_get_temp_dir(), 'php_gallery_dng_preview_');
    if ($temporaryPath === false) {
        return false;
    }

    try {
        if (!dng_extract_embedded_jpeg_preview($sourcePath, $temporaryPath)) {
            @unlink($temporaryPath);
            return false;
        }
        // $info stores the extracted JPEG preview dimensions.
        $info = @getimagesize($temporaryPath);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            @unlink($temporaryPath);
            return false;
        }
        // $effectiveMaxSide stores the requested thumbnail size or the full preview side for the display master.
        $effectiveMaxSide = $maxSide ?? max((int) $info[0], (int) $info[1]);
        // $written stores whether the strongest preview decoder successfully wrote the derivative.
        $written = write_dng_preview_derivative_with_imagick($temporaryPath, $targetPath, $format, $effectiveMaxSide);
        if (!$written) {
            // $source stores the GD image created from the embedded JPEG preview.
            $source = @imagecreatefromjpeg($temporaryPath);
            if (!$source) {
                @unlink($temporaryPath);
                return false;
            }
            if ($format === 'jpg') {
                $written = write_resized_jpeg($source, (int) $info[0], (int) $info[1], $effectiveMaxSide, $targetPath);
            } else {
                $written = write_resized_webp_with_gd($source, (int) $info[0], (int) $info[1], $effectiveMaxSide, $targetPath);
            }
            imagedestroy($source);
        }
        @unlink($temporaryPath);
        if (!$written || !is_file($targetPath)) {
            thumbnail_remove_partial_file($targetPath);
            return false;
        }
        return true;
    } catch (Throwable) {
        thumbnail_remove_partial_file($targetPath);
        @unlink($temporaryPath);
        return false;
    }
}

/**
 * Write one DNG derivative through the strongest available source path.
 */
function write_dng_derivative(string $sourcePath, string $targetPath, string $format, ?int $maxSide): bool
{
    if (write_dng_imagick_derivative($sourcePath, $targetPath, $format, $maxSide)) {
        return true;
    }
    return write_dng_embedded_preview_derivative($sourcePath, $targetPath, $format, $maxSide);
}

/**
 * Create thumbnails plus the WebP display master for one DNG source.
 */
function create_dng_image_derivatives_result(array $image, array $gallery, string $sourcePath): array
{
    if (!is_file($sourcePath)) {
        return ['created' => 0, 'skipped' => 0, 'webp_skipped' => 0, 'failed' => 1, 'errors' => [t('thumbnails.dng.error_original_missing')]];
    }
    // $generationStatus stores the concrete DNG converter availability state for user-facing diagnostics.
    $generationStatus = dng_derivative_generation_status();
    if (empty($generationStatus['supported'])) {
        return ['created' => 0, 'skipped' => 0, 'webp_skipped' => 0, 'failed' => 1, 'errors' => [(string) $generationStatus['reason']]];
    }
    gallery_thumbs_dir($gallery, true);
    // $sourceMtime stores the original DNG timestamp used to detect stale generated files.
    $sourceMtime = filemtime($sourcePath) ?: time();
    // $created stores the number of generated or refreshed derivatives.
    $created = 0;
    // $skipped stores the number of already fresh derivatives.
    $skipped = 0;
    // $webpSkipped stores the number of WebP derivatives that failed to generate.
    $webpSkipped = 0;
    // $failed stores derivatives that could not be generated and are required for DNG display.
    $failed = 0;
    // $errors stores concise diagnostic messages for the admin upload and thumbnail progress UI.
    $errors = [];

    // $masterPath stores the browser-displayable full-size WebP master.
    $masterPath = dng_display_master_abs_path($image, $gallery, true);
    if (is_file($masterPath) && filemtime($masterPath) >= $sourceMtime) {
        $skipped++;
    } elseif (create_dng_display_master($sourcePath, $masterPath)) {
        $created++;
    } else {
        $webpSkipped++;
        $failed++;
        $errors[] = t('thumbnails.dng.error_master_failed');
    }

    foreach (thumbnail_sizes() as $size) {
        foreach (['jpg', 'webp'] as $format) {
            // $targetPath stores the derivative path for this size and format.
            $targetPath = thumbnail_abs_path($image, $gallery, (int) $size, $format);
            if (is_file($targetPath) && filemtime($targetPath) >= $sourceMtime) {
                $skipped++;
                continue;
            }
            // $written stores whether the DNG derivative was created successfully.
            $written = write_dng_derivative($sourcePath, $targetPath, $format, (int) $size);
            if ($written) {
                $created++;
            } else {
                $failed++;
                if ($format === 'webp') {
                    $webpSkipped++;
                }
            }
        }
    }

    if ($failed > 0 && !$errors) {
        $errors[] = t('thumbnails.dng.error_derivatives_failed');
    }

    return ['created' => $created, 'skipped' => $skipped, 'webp_skipped' => $webpSkipped, 'failed' => $failed, 'errors' => array_values(array_unique($errors))];
}

/**
 * Handles progressive thumbnail picture html logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $fallbackSize Input used by this operation.
 * @param mixed $srcsetSizes Input used by this operation.
 * @param mixed $initialSizes Input used by this operation.
 * @param mixed $finalSizes Input used by this operation.
 * @param mixed $alt Input used by this operation.
 * @param mixed $extraAttributes Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_progressive_picture_html(array $image, int $fallbackSize, array $srcsetSizes, string $initialSizes, string $finalSizes, string $alt, string $extraAttributes = '', ?array $thumbnailBundle = null): string
{
    // $thumbnailBundle stores all generated thumbnail variants resolved once for this image during the current request.
    $thumbnailBundle = $thumbnailBundle ?: thumbnail_bundle($image);
    // $fallbackUrl stores the small first image used for the initial responsive paint.
    $fallbackUrl = thumbnail_bundle_url($thumbnailBundle, $fallbackSize);
    // $initialWebpSrcset stores only the small WebP candidate so navigation stays responsive.
    $initialWebpSrcset = thumbnail_bundle_srcset($thumbnailBundle, [$fallbackSize], 'webp');
    // $initialJpegSrcset stores only the small JPEG candidate for browsers without WebP support.
    $initialJpegSrcset = thumbnail_bundle_srcset($thumbnailBundle, [$fallbackSize], 'jpg');
    // $fullWebpSrcset stores larger WebP candidates that JavaScript applies after the first paint.
    $fullWebpSrcset = thumbnail_bundle_srcset($thumbnailBundle, $srcsetSizes, 'webp');
    // $fullJpegSrcset stores larger JPEG candidates that JavaScript applies after the first paint.
    $fullJpegSrcset = thumbnail_bundle_srcset($thumbnailBundle, $srcsetSizes, 'jpg');
    // $attributes stores caller-provided attributes plus the progressive marker used by the browser module.
    $attributes = trim($extraAttributes . ' data-progressive-thumbnail');
    // $html stores the generated picture element.
    $html = '<picture>';
    if ($initialWebpSrcset !== '') {
        $html .= '<source type="image/webp" srcset="' . e($initialWebpSrcset) . '" sizes="' . e($initialSizes) . '"';
        if ($fullWebpSrcset !== '') {
            $html .= ' data-progressive-srcset="' . e($fullWebpSrcset) . '" data-progressive-sizes="' . e($finalSizes) . '"';
        }
        $html .= '>';
    }
    $html .= '<img decoding="async" ' . ($attributes === '' ? '' : $attributes . ' ') . 'src="' . e($fallbackUrl) . '"';
    if ($initialJpegSrcset !== '') {
        $html .= ' srcset="' . e($initialJpegSrcset) . '"';
    }
    if ($fullJpegSrcset !== '') {
        $html .= ' data-progressive-srcset="' . e($fullJpegSrcset) . '" data-progressive-sizes="' . e($finalSizes) . '"';
    }
    $html .= ' sizes="' . e($initialSizes) . '" alt="' . e($alt) . '"></picture>';
    return $html;
}

/**
 * Handles thumbnail picture html logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $fallbackSize Input used by this operation.
 * @param mixed $srcsetSizes Input used by this operation.
 * @param mixed $sizes Input used by this operation.
 * @param mixed $alt Input used by this operation.
 * @param mixed $extraAttributes Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_picture_html(array $image, int $fallbackSize, array $srcsetSizes, string $sizes, string $alt, string $extraAttributes = '', ?array $thumbnailBundle = null): string
{
    // $thumbnailBundle stores all generated thumbnail variants resolved once for this image during the current request.
    $thumbnailBundle = $thumbnailBundle ?: thumbnail_bundle($image);
    // $fallbackUrl stores an intermediate value used by the surrounding gallery workflow.
    $fallbackUrl = thumbnail_bundle_url($thumbnailBundle, $fallbackSize);
    // $webpSrcset stores an intermediate value used by the surrounding gallery workflow.
    $webpSrcset = thumbnail_bundle_srcset($thumbnailBundle, $srcsetSizes, 'webp');
    // $jpegSrcset stores an intermediate value used by the surrounding gallery workflow.
    $jpegSrcset = thumbnail_bundle_srcset($thumbnailBundle, $srcsetSizes, 'jpg');
    // $attributes stores an intermediate value used by the surrounding gallery workflow.
    $attributes = trim($extraAttributes);
    // $html stores an intermediate value used by the surrounding gallery workflow.
    $html = '<picture>';
    if ($webpSrcset !== '') {
        $html .= '<source type="image/webp" srcset="' . e($webpSrcset) . '" sizes="' . e($sizes) . '">';
    }
    $html .= '<img decoding="async" ' . ($attributes === '' ? '' : $attributes . ' ') . 'src="' . e($fallbackUrl) . '"';
    if ($jpegSrcset !== '') {
        $html .= ' srcset="' . e($jpegSrcset) . '"';
    }
    $html .= ' sizes="' . e($sizes) . '" alt="' . e($alt) . '"></picture>';
    return $html;
}

/**
 * Handles thumbnail webp required for source logic for the gallery application.
 * @param mixed $sourcePath Input used by this operation.
 * @param mixed $mime Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_webp_required_for_source(string $sourcePath, string $mime): bool
{
    if (!function_exists('imagewebp')) {
        return false;
    }
    if (!image_source_has_exif($sourcePath, $mime)) {
        return true;
    }

    return thumbnail_imagick_webp_available();
}

/**
 * Return whether Imagick can write WebP thumbnails on this server.
 *
 * Some shared hosts expose the Imagick PHP class without the WebP delegate.
 * In that state class_exists('Imagick') is true, but writeImage() still fails
 * for WebP targets. The maintenance scanner must not require WebP variants
 * that the generator will refuse or fail to create.
 */
function thumbnail_imagick_webp_available(): bool
{
    if (!class_exists('Imagick')) {
        return false;
    }

    try {
        // $formats stores the concrete formats supported by the installed Imagick delegates.
        $formats = Imagick::queryFormats('WEBP');
        return is_array($formats) && in_array('WEBP', array_map('strtoupper', $formats), true);
    } catch (Throwable) {
        return false;
    }
}

/**
 * Return thumbnail formats that this server can actually keep up to date for one source image.
 *
 * WebP is deliberately excluded for JPEG files with EXIF metadata when Imagick is
 * unavailable, because the WebP writer would reject those variants to avoid
 * silently stripping EXIF metadata. The maintenance scanner and the generator
 * must use this same decision or the dashboard can keep reporting variants that
 * the repair job correctly refuses to create.
 *
 * @return array<int, string>
 */
function thumbnail_target_formats_for_source(string $sourcePath, string $mime): array
{
    if (is_dng_image_path($sourcePath) || $mime === 'image/x-adobe-dng') {
        return dng_derivative_generation_supported() ? ['jpg', 'webp'] : [];
    }

    // $formats stores the concrete variant formats that should exist on disk.
    $formats = ['jpg'];
    if ($mime !== '' && thumbnail_webp_required_for_source($sourcePath, $mime)) {
        $formats[] = 'webp';
    }

    return $formats;
}

/**
 * Return the number of WebP variants intentionally not required for one source image.
 */
function thumbnail_intentionally_skipped_webp_count(string $sourcePath, string $mime): int
{
    if ($mime !== 'image/jpeg' || !function_exists('imagewebp')) {
        return 0;
    }
    if (!image_source_has_exif($sourcePath, $mime) || thumbnail_imagick_webp_available()) {
        return 0;
    }

    return count(thumbnail_sizes());
}

/**
 * Handles thumbnail maintenance status logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $gallery Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_maintenance_status(array $image, array $gallery): array
{
    // Variable $sourcePath stores this steps working value.
    $sourcePath = image_abs_path($image, $gallery);
    if (!is_file($sourcePath)) {
        return ['required' => 0, 'missing' => 0, 'webp_skipped' => 0];
    }
    // Variable $sourceMtime stores this steps working value.
    $sourceMtime = filemtime($sourcePath) ?: 0;
    // $mime stores an intermediate value used by the surrounding gallery workflow.
    $mime = image_source_mime_for_derivatives($sourcePath, $image);
    // $formats stores the variants that should exist for this source on this server.
    $formats = thumbnail_target_formats_for_source($sourcePath, $mime);
    // $webpSkipped stores variants intentionally excluded because this server cannot preserve EXIF in WebP.
    $webpSkipped = thumbnail_intentionally_skipped_webp_count($sourcePath, $mime);
    // Variable $required stores this steps working value.
    $required = 0;
    // Variable $missing stores this steps working value.
    $missing = 0;
    foreach (thumbnail_sizes() as $size) {
        foreach ($formats as $format) {
            $required++;
            try {
                // $targetPath stores an intermediate value used by the surrounding gallery workflow.
                $targetPath = thumbnail_abs_path($image, $gallery, (int) $size, $format);
            } catch (RuntimeException) {
                $missing++;
                continue;
            }
            if (!is_file($targetPath) || filemtime($targetPath) < $sourceMtime) {
                $missing++;
            }
        }
    }
    if (image_uses_dng_display_derivatives($image) && dng_derivative_generation_supported()) {
        $required++;
        try {
            // $masterPath stores the generated full-size WebP display master.
            $masterPath = dng_display_master_abs_path($image, $gallery, false);
            if (!is_file($masterPath) || filemtime($masterPath) < $sourceMtime) {
                $missing++;
            }
        } catch (RuntimeException) {
            $missing++;
        }
    }
    return ['required' => $required, 'missing' => $missing, 'webp_skipped' => $webpSkipped];
}

/**
 * Handles thumbnail maintenance summary logic for the gallery application.
 * @param mixed $galleryIds Input used by this operation.
 * @param mixed $maxImagesToScan Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_maintenance_summary(?array $galleryIds = null, int $maxImagesToScan = 1000): array
{
    // Variable $params stores this steps working value.
    $params = [];
    // $where stores an intermediate value used by the surrounding gallery workflow.
    $where = "i.relative_path NOT LIKE '%/%'";
    if ($galleryIds !== null) {
        // $galleryIds stores an intermediate value used by the surrounding gallery workflow.
        $galleryIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
        if (!$galleryIds) {
            return ['images_scanned' => 0, 'images_with_missing' => 0, 'missing_variants' => 0, 'webp_skipped' => 0, 'limited' => false, 'inventory_fingerprint' => thumbnail_inventory_fingerprint($galleryIds)];
        }
        $where .= ' AND i.gallery_id IN (' . implode(',', array_fill(0, count($galleryIds), '?')) . ')';
        // $params stores an intermediate value used by the surrounding gallery workflow.
        $params = $galleryIds;
    }
    // $limit stores an intermediate value used by the surrounding gallery workflow.
    $limit = max(1, $maxImagesToScan + 1);
    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = db()->prepare("SELECT i.*, g.folder_path AS gallery_folder_path FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE $where ORDER BY g.folder_path, i.sort_order, i.filename LIMIT $limit");
    $stmt->execute($params);
    // $rows stores an intermediate value used by the surrounding gallery workflow.
    $rows = $stmt->fetchAll();
    // $limited stores an intermediate value used by the surrounding gallery workflow.
    $limited = count($rows) > $maxImagesToScan;
    if ($limited) {
        array_pop($rows);
    }
    // Variable $galleryCache stores this steps working value.
    $galleryCache = [];
    // Variable $imagesWithMissing stores this steps working value.
    $imagesWithMissing = 0;
    // Variable $missingVariants stores this steps working value.
    $missingVariants = 0;
    // Variable $webpSkipped stores this steps working value.
    $webpSkipped = 0;
    foreach ($rows as $image) {
        // $galleryId stores an intermediate value used by the surrounding gallery workflow.
        $galleryId = (int) $image['gallery_id'];
        if (!isset($galleryCache[$galleryId])) {
            $galleryCache[$galleryId] = find_gallery($galleryId);
        }
        if (!$galleryCache[$galleryId]) {
            continue;
        }
        // $status stores an intermediate value used by the surrounding gallery workflow.
        $status = thumbnail_maintenance_status($image, $galleryCache[$galleryId]);
        if ($status['missing'] > 0) {
            $imagesWithMissing++;
            $missingVariants += $status['missing'];
        }
        $webpSkipped += $status['webp_skipped'];
    }
    return [
        'images_scanned' => count($rows),
        'images_with_missing' => $imagesWithMissing,
        'missing_variants' => $missingVariants,
        'webp_skipped' => $webpSkipped,
        'limited' => $limited,
        'inventory_fingerprint' => thumbnail_inventory_fingerprint($galleryIds),
    ];
}

/**
 * Return image IDs that need thumbnail regeneration for the current maintenance warning.
 *
 * This mirrors thumbnail_maintenance_summary() but returns only the images with
 * missing or stale thumbnail files so the admin can rebuild the affected set
 * without scanning or processing every image in the library.
 *
 * @param array<int, int>|null $galleryIds Optional gallery filter matching thumbnail_maintenance_summary().
 * @return array<int, int>
 */
function thumbnail_maintenance_image_ids(?array $galleryIds = null, int $maxImagesToScan = 1000): array
{
    // Variable $params stores this steps working value.
    $params = [];
    // $where stores an intermediate value used by the surrounding gallery workflow.
    $where = "i.relative_path NOT LIKE '%/%'";
    if ($galleryIds !== null) {
        // $galleryIds stores this steps working value.
        $galleryIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
        if (!$galleryIds) {
            return [];
        }
        $where .= ' AND i.gallery_id IN (' . implode(',', array_fill(0, count($galleryIds), '?')) . ')';
        // $params stores an intermediate value used by the surrounding gallery workflow.
        $params = $galleryIds;
    }

    // $limit stores an intermediate value used by the surrounding gallery workflow.
    $limit = max(1, $maxImagesToScan + 1);
    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = db()->prepare("SELECT i.*, g.folder_path AS gallery_folder_path FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE $where ORDER BY g.folder_path, i.sort_order, i.filename LIMIT $limit");
    $stmt->execute($params);
    // $rows stores an intermediate value used by the surrounding gallery workflow.
    $rows = $stmt->fetchAll();
    if (count($rows) > $maxImagesToScan) {
        array_pop($rows);
    }

    // Variable $galleryCache stores this steps working value.
    $galleryCache = [];
    // Variable $imageIds stores this steps working value.
    $imageIds = [];
    foreach ($rows as $image) {
        // $galleryId stores an intermediate value used by the surrounding gallery workflow.
        $galleryId = (int) $image['gallery_id'];
        if (!isset($galleryCache[$galleryId])) {
            $galleryCache[$galleryId] = find_gallery($galleryId);
        }
        if (!$galleryCache[$galleryId]) {
            continue;
        }
        // $status stores an intermediate value used by the surrounding gallery workflow.
        $status = thumbnail_maintenance_status($image, $galleryCache[$galleryId]);
        if (($status['missing'] ?? 0) > 0) {
            $imageIds[] = (int) $image['id'];
        }
    }

    return array_values(array_unique($imageIds));
}

/**
 * Return compact diagnostic data for thumbnail repair logs.
 *
 * @param array<int, int> $imageIds Image IDs selected by the maintenance repair scope.
 * @return array<int, array<string, mixed>>
 */
function thumbnail_maintenance_debug_image_statuses(array $imageIds): array
{
    // $imageIds stores a short unique list so admin log context stays readable.
    $imageIds = array_slice(array_values(array_unique(array_filter(array_map('intval', $imageIds), static fn (int $id): bool => $id > 0))), 0, 20);
    // $rows stores the diagnostic entries included in the admin log.
    $rows = [];
    foreach ($imageIds as $imageId) {
        // $image stores the database row for this diagnostic entry.
        $image = find_image($imageId);
        if (!$image) {
            $rows[] = ['image_id' => $imageId, 'found' => false];
            continue;
        }

        // $gallery stores the parent gallery needed to resolve source and thumbnail paths.
        $gallery = find_gallery((int) $image['gallery_id']);
        if (!$gallery) {
            $rows[] = ['image_id' => $imageId, 'found' => true, 'gallery_found' => false];
            continue;
        }

        // $sourcePath stores the absolute source path for filesystem checks.
        $sourcePath = image_abs_path($image, $gallery);
        // $mime stores the detected MIME type used for thumbnail format decisions.
        $mime = is_file($sourcePath) ? image_source_mime_for_derivatives($sourcePath, $image) : '';
        // $status stores the same maintenance status used by the dashboard warning.
        $status = thumbnail_maintenance_status($image, $gallery);

        $rows[] = [
            'image_id' => $imageId,
            'found' => true,
            'gallery_found' => true,
            'gallery_id' => (int) $image['gallery_id'],
            'filename' => (string) ($image['filename'] ?? ''),
            'relative_path' => (string) ($image['relative_path'] ?? ''),
            'source_exists' => is_file($sourcePath),
            'mime' => $mime,
            'has_exif' => $mime !== '' && image_source_has_exif($sourcePath, $mime),
            'is_dng' => image_uses_dng_display_derivatives($image),
            'imagewebp_available' => function_exists('imagewebp'),
            'imagick_available' => class_exists('Imagick'),
            'imagick_webp_available' => thumbnail_imagick_webp_available(),
            'dng_conversion_supported' => dng_derivative_generation_supported(),
            'target_formats' => $mime !== '' ? thumbnail_target_formats_for_source($sourcePath, $mime) : [],
            'dng_master_exists' => image_uses_dng_display_derivatives($image) ? is_file(dng_display_master_abs_path($image, $gallery, false)) : null,
            'status' => $status,
        ];
    }

    return $rows;
}

/**
 * Return a short-lived thumbnail maintenance summary for admin dashboards.
 *
 * The expensive part of thumbnail maintenance is checking source files and
 * generated variants on disk. The image inventory fingerprint keeps this cache
 * tied to the current set of indexed direct images, while explicit cache
 * generation invalidation makes thumbnail creation and deletion visible on the
 * next admin load.
 *
 * @param array<int, int>|null $galleryIds Optional gallery filter matching thumbnail_maintenance_summary().
 */
function cached_thumbnail_maintenance_summary(?array $galleryIds = null, int $maxImagesToScan = 1000, int $ttlSeconds = 180): array
{
    // $galleryIds stores the normalized optional gallery scope used by both cache keys and summary queries.
    $galleryIds = $galleryIds === null ? null : array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
    if ($galleryIds !== null && $galleryIds === []) {
        return thumbnail_maintenance_summary([], $maxImagesToScan);
    }

    // $scopeKey stores a compact stable key for the dashboard-wide or gallery-scoped summary.
    $scopeKey = $galleryIds === null ? 'all' : implode(',', $galleryIds);
    // $cacheKey stores the DB setting that contains the cached summary payload.
    $cacheKey = 'thumbnail_maintenance_summary_' . substr(hash('sha256', $scopeKey . '|' . $maxImagesToScan), 0, 16);
    // $generation stores the invalidation marker changed after thumbnail creation or deletion.
    $generation = (string) app_setting('thumbnail_maintenance_summary_generation', '0');
    // $fingerprint stores the cheap image inventory state. It changes when images are imported.
    $fingerprint = thumbnail_inventory_fingerprint($galleryIds);
    // $cachedJson stores the previous summary payload, if any.
    $cachedJson = (string) app_setting($cacheKey, '');

    if ($cachedJson !== '') {
        // $cachedPayload stores the decoded summary cache candidate.
        $cachedPayload = json_decode($cachedJson, true);
        if (is_array($cachedPayload)
            && (string) ($cachedPayload['generation'] ?? '') === $generation
            && (string) ($cachedPayload['fingerprint'] ?? '') === $fingerprint
            && time() - (int) ($cachedPayload['created_at'] ?? 0) <= max(30, $ttlSeconds)
            && is_array($cachedPayload['summary'] ?? null)
        ) {
            $cachedPayload['summary']['inventory_fingerprint'] = $fingerprint;
            return $cachedPayload['summary'];
        }
    }

    // $summary stores the fresh filesystem-backed maintenance state.
    $summary = thumbnail_maintenance_summary($galleryIds, $maxImagesToScan);
    set_app_setting($cacheKey, json_encode([
        'created_at' => time(),
        'generation' => $generation,
        'fingerprint' => (string) ($summary['inventory_fingerprint'] ?? $fingerprint),
        'summary' => $summary,
    ], JSON_UNESCAPED_SLASHES));

    return $summary;
}


/**
 * Return a cached thumbnail maintenance summary without warming the cache.
 *
 * The admin dashboard calls this helper so the first page after login does not
 * spend seconds checking thumbnail files on disk. Explicit thumbnail maintenance
 * actions still use thumbnail_maintenance_summary() and can refresh the cache.
 *
 * @param array<int, int>|null $galleryIds Optional gallery filter matching thumbnail_maintenance_summary().
 */
function cached_thumbnail_maintenance_summary_if_available(?array $galleryIds = null, int $maxImagesToScan = 1000, int $ttlSeconds = 180): array
{
    // $galleryIds stores the normalized optional gallery scope used by both cache keys and summary queries.
    $galleryIds = $galleryIds === null ? null : array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
    if ($galleryIds !== null && $galleryIds === []) {
        return [
            'images_scanned' => 0,
            'images_with_missing' => 0,
            'missing_variants' => 0,
            'webp_skipped' => 0,
            'limited' => false,
            'deferred' => false,
            'inventory_fingerprint' => thumbnail_inventory_fingerprint($galleryIds),
        ];
    }

    // $scopeKey stores a compact stable key for the dashboard-wide or gallery-scoped summary.
    $scopeKey = $galleryIds === null ? 'all' : implode(',', $galleryIds);
    // $cacheKey stores the DB setting that contains the cached summary payload.
    $cacheKey = 'thumbnail_maintenance_summary_' . substr(hash('sha256', $scopeKey . '|' . $maxImagesToScan), 0, 16);
    // $generation stores the invalidation marker changed after thumbnail creation or deletion.
    $generation = (string) app_setting('thumbnail_maintenance_summary_generation', '0');
    // $fingerprint stores the cheap image inventory state. It changes when images are imported.
    $fingerprint = thumbnail_inventory_fingerprint($galleryIds);
    // $cachedJson stores the previous summary payload, if any.
    $cachedJson = (string) app_setting($cacheKey, '');

    if ($cachedJson !== '') {
        // $cachedPayload stores the decoded summary cache candidate.
        $cachedPayload = json_decode($cachedJson, true);
        if (is_array($cachedPayload)
            && (string) ($cachedPayload['generation'] ?? '') === $generation
            && (string) ($cachedPayload['fingerprint'] ?? '') === $fingerprint
            && time() - (int) ($cachedPayload['created_at'] ?? 0) <= max(30, $ttlSeconds)
            && is_array($cachedPayload['summary'] ?? null)
        ) {
            $cachedPayload['summary']['inventory_fingerprint'] = $fingerprint;
            $cachedPayload['summary']['deferred'] = false;
            return $cachedPayload['summary'];
        }
    }

    return [
        'images_scanned' => 0,
        'images_with_missing' => 0,
        'missing_variants' => 0,
        'webp_skipped' => 0,
        'limited' => false,
        'deferred' => true,
        'inventory_fingerprint' => $fingerprint,
    ];
}

/**
 * Invalidate cached thumbnail maintenance summaries after cache files change.
 */
function thumbnail_maintenance_summary_cache_clear(): void
{
    set_app_setting('thumbnail_maintenance_summary_generation', sprintf('%.6F', microtime(true)));
    if (function_exists('gallery_map_cache_clear_all')) {
        gallery_map_cache_clear_all();
    }
}

/**
 * Build a lightweight fingerprint of the currently indexed image inventory.
 *
 * The dismissal feature for thumbnail maintenance warnings uses this value to
 * distinguish "same warning, temporarily hidden" from "the gallery content
 * changed, show the warning again". The fingerprint only uses aggregate image
 * metadata, not filenames, paths, titles, EXIF data, IP addresses, or visitor
 * information. A newly imported image changes the count, maximum image id, or
 * newest creation timestamp and therefore invalidates the old dismissal.
 *
 * @param array<int, int>|null $galleryIds Optional gallery filter matching thumbnail_maintenance_summary().
 */
function thumbnail_inventory_fingerprint(?array $galleryIds = null): string
{
    // $params stores bound gallery ids when the caller wants a scoped inventory check.
    $params = [];
    // $where stores the same top-level image condition used by thumbnail_maintenance_summary().
    $where = "relative_path NOT LIKE '%/%'";

    if ($galleryIds !== null) {
        $galleryIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
        if ($galleryIds === []) {
            return hash('sha256', 'empty-gallery-scope');
        }
        $where .= ' AND gallery_id IN (' . implode(',', array_fill(0, count($galleryIds), '?')) . ')';
        $params = $galleryIds;
    }

    // $stmt reads only aggregate metadata so the check stays cheap even on large galleries.
    $stmt = db()->prepare("SELECT COUNT(*) AS image_count, COALESCE(MAX(id), 0) AS newest_id, COALESCE(MAX(created_at), '') AS newest_created_at FROM images WHERE $where");
    $stmt->execute($params);
    // $row stores the aggregate inventory state that controls warning dismissal.
    $row = $stmt->fetch() ?: [];

    return hash('sha256', implode('|', [
        (string) ($row['image_count'] ?? '0'),
        (string) ($row['newest_id'] ?? '0'),
        (string) ($row['newest_created_at'] ?? ''),
    ]));
}

/**
 * Delete every generated thumbnail cache directory below known gallery folders.
 *
 * The function only targets each gallery's own `thumbs` directory. It does not
 * delete original images, uploaded gallery cover assets, database rows, or any
 * files outside the configured gallery root. The returned counters are used by
 * the admin notice and by the operational log.
 *
 * @return array{files_deleted:int,directories_removed:int,directories_scanned:int}
 */
function delete_all_thumbnail_files(): array
{
    // $filesDeleted counts individual thumbnail files removed from disk.
    $filesDeleted = 0;
    // $directoriesRemoved counts thumbs directories removed after their files are gone.
    $directoriesRemoved = 0;
    // $directoriesScanned counts existing thumbs directories touched by this run.
    $directoriesScanned = 0;
    // $galleryRoot stores the configured root boundary for all filesystem checks.
    $galleryRoot = galleries_root();

    foreach (db()->query('SELECT folder_path FROM galleries ORDER BY folder_path')->fetchAll(PDO::FETCH_COLUMN) as $folderPath) {
        // $gallery stores the minimum shape required by gallery_thumbs_dir().
        $gallery = ['folder_path' => (string) $folderPath];
        // $thumbsDirectory stores the generated thumbnail cache directory for this gallery.
        $thumbsDirectory = gallery_thumbs_dir($gallery, false);

        if (!is_dir($thumbsDirectory)) {
            continue;
        }
        if (!path_inside($galleryRoot, $thumbsDirectory)) {
            throw new RuntimeException('Refusing to delete thumbnails outside the gallery root.');
        }

        $directoriesScanned++;
        $filesDeleted += delete_thumbnail_directory_contents($thumbsDirectory, $galleryRoot);

        if (@rmdir($thumbsDirectory)) {
            $directoriesRemoved++;
        } elseif (is_dir($thumbsDirectory)) {
            throw new RuntimeException('Could not remove thumbnail directory: ' . $thumbsDirectory);
        }
    }

    return [
        'files_deleted' => $filesDeleted,
        'directories_removed' => $directoriesRemoved,
        'directories_scanned' => $directoriesScanned,
    ];
}

/**
 * Delete all files and nested directories inside one thumbnail directory.
 *
 * Generated thumbnail directories should normally contain only flat thumb files,
 * but the recursive iterator keeps cleanup safe and complete if an older or
 * experimental version created nested cache folders. The safety boundary remains
 * the configured gallery root and every path is checked before deletion.
 *
 * @return int Number of removed files.
 */
function delete_thumbnail_directory_contents(string $thumbsDirectory, string $allowedRoot): int
{
    // $filesDeleted counts all non-directory entries removed from this thumbs directory.
    $filesDeleted = 0;
    // $iterator walks children before parents so nested directories can be removed cleanly.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($thumbsDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $entry) {
        // $path stores the concrete filesystem path currently being removed.
        $path = $entry->getPathname();
        if (!path_inside($allowedRoot, $path)) {
            throw new RuntimeException('Refusing to delete a thumbnail path outside the gallery root.');
        }
        if ($entry->isDir() && !$entry->isLink()) {
            if (!@rmdir($path)) {
                throw new RuntimeException('Could not remove thumbnail subdirectory: ' . $path);
            }
            continue;
        }
        if (!@unlink($path)) {
            throw new RuntimeException('Could not remove thumbnail file: ' . $path);
        }
        $filesDeleted++;
    }

    return $filesDeleted;
}

/**
 * Handles create gallery thumbnails logic for the gallery application.
 * @param mixed $galleryId Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function create_gallery_thumbnails(int $galleryId): int
{
    // Variable $galleryIds stores this steps working value.
    $galleryIds = gallery_subtree_ids($galleryId);
    if (!$galleryIds) {
        return 0;
    }

    // Variable $count stores this steps working value.
    $count = 0;
    foreach ($galleryIds as $currentGalleryId) {
        // Variable $gallery stores this steps working value.
        $gallery = find_gallery((int) $currentGalleryId);
        if (!$gallery) {
            continue;
        }
        foreach (gallery_images((int) $currentGalleryId, false) as $image) {
            $count += create_image_thumbnails($image, $gallery);
        }
    }
    return $count;
}

/**
 * Handles create all thumbnails logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function create_all_thumbnails(): int
{
    // Variable $count stores this steps working value.
    $count = 0;
    foreach (db()->query('SELECT id FROM galleries ORDER BY folder_path')->fetchAll(PDO::FETCH_COLUMN) as $galleryId) {
        $count += create_gallery_thumbnails((int) $galleryId);
    }
    return $count;
}

/**
 * Handles create image thumbnails logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $gallery Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function create_image_thumbnails(array $image, array $gallery): int
{
    return create_image_thumbnails_result($image, $gallery)['created'];
}

/**
 * Handles create image thumbnails result logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $gallery Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function create_image_thumbnails_result(array $image, array $gallery): array
{
    // Variable $sourcePath stores this steps working value.
    $sourcePath = image_abs_path($image, $gallery);
    if (!is_file($sourcePath)) {
        return ['created' => 0, 'skipped' => 0, 'webp_skipped' => 0, 'failed' => 0, 'errors' => []];
    }
    gallery_thumbs_dir($gallery, true);
    if (image_uses_dng_display_derivatives($image)) {
        return create_dng_image_derivatives_result($image, $gallery, $sourcePath);
    }
    // Variable $info stores this steps working value.
    $info = @getimagesize($sourcePath);
    if ($info === false || empty($info['mime'])) {
        return ['created' => 0, 'skipped' => 0, 'webp_skipped' => 0, 'failed' => 0, 'errors' => []];
    }
    // $mime stores the source MIME value used by the scanner and generator format decision.
    $mime = (string) $info['mime'];
    // $formats stores the variants this server can actually keep current for this source.
    $formats = thumbnail_target_formats_for_source($sourcePath, $mime);
    // Variable $sourceMtime stores this steps working value.
    $sourceMtime = filemtime($sourcePath) ?: time();
    // Variable $targets stores this steps working value.
    $targets = [];
    // Variable $skipped stores this steps working value.
    $skipped = 0;
    // Variable $webpSkipped stores this steps working value.
    $webpSkipped = thumbnail_intentionally_skipped_webp_count($sourcePath, $mime);
    foreach (thumbnail_sizes() as $size) {
        foreach ($formats as $format) {
            // Variable $targetPath stores this steps working value.
            $targetPath = thumbnail_abs_path($image, $gallery, $size, $format);
            if (is_file($targetPath) && filemtime($targetPath) >= $sourceMtime) {
                $skipped++;
                continue;
            }
            $targets[$size][$format] = $targetPath;
        }
    }
    if (!$targets) {
        return ['created' => 0, 'skipped' => $skipped, 'webp_skipped' => $webpSkipped, 'failed' => 0, 'errors' => []];
    }
    if (!extension_loaded('gd')) {
        return ['created' => 0, 'skipped' => $skipped, 'webp_skipped' => $webpSkipped, 'failed' => 0, 'errors' => []];
    }
    // Variable $source stores this steps working value.
    $source = image_create_from_path($sourcePath, (string) $info['mime']);
    if (!$source) {
        return ['created' => 0, 'skipped' => $skipped, 'webp_skipped' => $webpSkipped, 'failed' => 0, 'errors' => []];
    }
    // Variable $created stores this steps working value.
    $created = 0;
    foreach ($targets as $size => $formatTargets) {
        if (isset($formatTargets['jpg']) && write_resized_jpeg($source, (int) $info[0], (int) $info[1], (int) $size, $formatTargets['jpg'])) {
            $created++;
        }
        if (isset($formatTargets['webp'])) {
            // $webpWritten stores an intermediate value used by the surrounding gallery workflow.
            $webpWritten = write_resized_webp_preserving_exif_when_needed($sourcePath, $source, (int) $info[0], (int) $info[1], (int) $size, $formatTargets['webp'], $mime);
            if ($webpWritten) {
                $created++;
            } else {
                $webpSkipped++;
            }
        }
    }
    imagedestroy($source);
    return ['created' => $created, 'skipped' => $skipped, 'webp_skipped' => $webpSkipped, 'failed' => 0, 'errors' => []];
}

/**
 * Handles image ids for galleries logic for the gallery application.
 * @param mixed $galleryIds Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function image_ids_for_galleries(array $galleryIds): array
{
    // Variable $galleryIds stores this steps working value.
    $galleryIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
    if (!$galleryIds) {
        return [];
    }
    // Variable $placeholders stores this steps working value.
    $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare("SELECT id FROM images WHERE gallery_id IN ($placeholders) AND relative_path NOT LIKE '%/%' ORDER BY gallery_id, sort_order, filename");
    $stmt->execute($galleryIds);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Handles all image ids logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function all_image_ids(): array
{
    // Variable $rows stores this steps working value.
    $rows = db()->query("SELECT i.id FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE i.relative_path NOT LIKE '%/%' ORDER BY g.folder_path, i.sort_order, i.filename")->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $rows);
}

/**
 * Handles image create from path logic for the gallery application.
 * @param mixed $path Input used by this operation.
 * @param mixed $mime Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function image_create_from_path(string $path, string $mime): GdImage|false
{
    return match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($path),
        'image/png' => imagecreatefrompng($path),
        'image/gif' => imagecreatefromgif($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : false,
        default => false,
    };
}

/**
 * Handles write resized jpeg logic for the gallery application.
 * @param mixed $source Input used by this operation.
 * @param mixed $width Input used by this operation.
 * @param mixed $height Input used by this operation.
 * @param mixed $maxSide Input used by this operation.
 * @param mixed $targetPath Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function write_resized_jpeg(GdImage $source, int $width, int $height, int $maxSide, string $targetPath): bool
{
    // Variable $scale stores this steps working value.
    $scale = min(1.0, $maxSide / max($width, $height));
    // Variable $targetWidth stores this steps working value.
    $targetWidth = max(1, (int) round($width * $scale));
    // Variable $targetHeight stores this steps working value.
    $targetHeight = max(1, (int) round($height * $scale));
    // Variable $target stores this steps working value.
    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    // Variable $white stores this steps working value.
    $white = imagecolorallocate($target, 255, 255, 255);
    imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $white);
    imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
    imageinterlace($target, true);
    // Variable $written stores this steps working value.
    $written = imagejpeg($target, $targetPath, 82);
    imagedestroy($target);
    return $written;
}

/**
 * Handles image source has exif logic for the gallery application.
 * @param mixed $sourcePath Input used by this operation.
 * @param mixed $mime Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function image_source_has_exif(string $sourcePath, string $mime): bool
{
    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
        return false;
    }
    // $exif stores an intermediate value used by the surrounding gallery workflow.
    $exif = @exif_read_data($sourcePath, null, true, false);
    return is_array($exif) && $exif !== [];
}

/**
 * Handles write resized webp preserving exif when needed logic for the gallery application.
 * @param mixed $sourcePath Input used by this operation.
 * @param mixed $source Input used by this operation.
 * @param mixed $width Input used by this operation.
 * @param mixed $height Input used by this operation.
 * @param mixed $maxSide Input used by this operation.
 * @param mixed $targetPath Input used by this operation.
 * @param mixed $mime Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function write_resized_webp_preserving_exif_when_needed(string $sourcePath, GdImage $source, int $width, int $height, int $maxSide, string $targetPath, string $mime): bool
{
    if (!function_exists('imagewebp')) {
        return false;
    }
    if (image_source_has_exif($sourcePath, $mime) && thumbnail_imagick_webp_available()) {
        // $imagickWritten stores whether the preferred metadata-preserving writer succeeded.
        $imagickWritten = write_resized_webp_with_imagick_exif($sourcePath, $maxSide, $targetPath);
        if ($imagickWritten) {
            return true;
        }

        // Some hosts expose WebP through Imagick, but individual panoramic JPEGs can still fail
        // because of pixel-cache or image-policy limits. Falling back to GD keeps the thumbnail
        // cache repairable instead of leaving one image permanently reported as missing.
        thumbnail_remove_partial_file($targetPath);
    }

    return write_resized_webp_with_gd($source, $width, $height, $maxSide, $targetPath);
}

/**
 * Remove a partially written target file after a failed writer attempt.
 */
function thumbnail_remove_partial_file(string $targetPath): void
{
    if (is_file($targetPath)) {
        @unlink($targetPath);
    }
}

/**
 * Handles write resized webp with gd logic for the gallery application.
 * @param mixed $source Input used by this operation.
 * @param mixed $width Input used by this operation.
 * @param mixed $height Input used by this operation.
 * @param mixed $maxSide Input used by this operation.
 * @param mixed $targetPath Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function write_resized_webp_with_gd(GdImage $source, int $width, int $height, int $maxSide, string $targetPath): bool
{
    // Variable $scale stores this steps working value.
    $scale = min(1.0, $maxSide / max($width, $height));
    // Variable $targetWidth stores this steps working value.
    $targetWidth = max(1, (int) round($width * $scale));
    // Variable $targetHeight stores this steps working value.
    $targetHeight = max(1, (int) round($height * $scale));
    // Variable $target stores this steps working value.
    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    imagealphablending($target, true);
    imagesavealpha($target, true);
    // Variable $transparent stores this steps working value.
    $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
    imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
    imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
    // Variable $written stores this steps working value.
    $written = imagewebp($target, $targetPath, 82);
    imagedestroy($target);
    return $written;
}

/**
 * Handles write resized webp with imagick exif logic for the gallery application.
 * @param mixed $sourcePath Input used by this operation.
 * @param mixed $maxSide Input used by this operation.
 * @param mixed $targetPath Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function write_resized_webp_with_imagick_exif(string $sourcePath, int $maxSide, string $targetPath): bool
{
    if (!thumbnail_imagick_webp_available()) {
        return false;
    }

    // $image stores the Imagick instance so it can be cleaned up even after a failed write.
    $image = null;
    try {
        // $image stores an intermediate value used by the surrounding gallery workflow.
        $image = new Imagick($sourcePath);
        // $profiles stores an intermediate value used by the surrounding gallery workflow.
        $profiles = $image->getImageProfiles('exif', true);
        $image->thumbnailImage($maxSide, $maxSide, true, true);
        $image->setImageFormat('webp');
        $image->setImageCompressionQuality(82);
        if (isset($profiles['exif']) && $profiles['exif'] !== '') {
            $image->profileImage('exif', $profiles['exif']);
        }
        // $written stores an intermediate value used by the surrounding gallery workflow.
        $written = $image->writeImage($targetPath);
        $image->clear();
        $image->destroy();
        return $written && is_file($targetPath);
    } catch (Throwable) {
        thumbnail_remove_partial_file($targetPath);
        if ($image instanceof Imagick) {
            $image->clear();
            $image->destroy();
        }
        return false;
    }
}

