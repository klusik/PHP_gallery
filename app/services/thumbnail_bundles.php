<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/thumbnail_bundles.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Builds cached thumbnail variant bundles for public rendering.
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
 * Return a stable request-local cache key for one image thumbnail bundle.
 *
 * @param array $image Image row or image data.
 * @return string Text result for the caller.
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
 *
 * @param string $format Format value.
 * @return string Text result for the caller.
 */
function thumbnail_bundle_normalize_format(string $format): string
{
    return $format === 'webp' ? 'webp' : 'jpg';
}

/**
 * Return the safe browser media URL used when no generated thumbnail exists.
 *
 * @param array $image Image row or image data.
 * @param ?array $gallery Gallery row or gallery data.
 * @return string Text result for the caller.
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
 * @param array $image Image row or image data.
 * @return array{ Structured result data for the caller.
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
            'warmup_sizes' => [],
        ];

        if (!$gallery) {
            return $bundle;
        }

        if (function_exists('thumbnail_metadata_schema_ready') && thumbnail_metadata_schema_ready()) {
            // $metadata stores renderable thumbnail variants selected from DB state only.
            $metadata = thumbnail_metadata_bundle_data($image, $gallery, $sizes);
            $bundle['variants'] = $metadata['variants'];
            $bundle['warmup_sizes'] = $metadata['warmup_sizes'];
            foreach (['jpg', 'webp'] as $format) {
                public_render_profile_count('thumbnail_bundle_variant_hits', count($bundle['variants'][$format] ?? []));
            }
            if (!empty($bundle['warmup_sizes'])) {
                public_render_profile_count('thumbnail_bundle_metadata_warmup_sizes', count($bundle['warmup_sizes']));
            }
            return $bundle;
        }

        // Older installations without the metadata migration keep the legacy filesystem lookup path.
        $sourceGeometry = null;
        if (function_exists('thumbnail_source_geometry_dimensions')) {
            try {
                // $sourcePath stores the original file path used only for geometry validation.
                $sourcePath = image_abs_path($image, $gallery);
                if (is_file($sourcePath)) {
                    $sourceGeometry = thumbnail_source_geometry_dimensions($sourcePath, $image);
                }
            } catch (Throwable) {
                $sourceGeometry = null;
            }
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
                    if (is_array($sourceGeometry) && function_exists('thumbnail_file_geometry_status')) {
                        // $geometryStatus stores whether this cache file still matches the source aspect ratio.
                        $geometryStatus = thumbnail_file_geometry_status($path, (int) $sourceGeometry['width'], (int) $sourceGeometry['height'], (int) $size);
                        if (empty($geometryStatus['valid'])) {
                            $bundle['warmup_sizes'][(int) $size] = (int) $size;
                            public_render_profile_count('thumbnail_bundle_invalid_geometry_queued');
                            continue;
                        }
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
 *
 * @param array $bundle Bundle value.
 * @param int $preferredSize Preferred size value.
 * @return int Integer result for the caller.
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
 * @param array $bundle Bundle value.
 * @param int $preferredSize Preferred size value.
 * @param string $preferredFormat Preferred format value.
 * @return array{url:string,size:int,format:string,is_media_fallback:bool,is_exact:bool} Structured result data for the caller.
 */
function thumbnail_bundle_select_variant(array $bundle, int $preferredSize, string $preferredFormat = ''): array
{
    // $preferredFormat stores the caller's preferred browser format.
    $preferredFormat = $preferredFormat !== '' ? $preferredFormat : (function_exists('thumbnail_preferred_browser_format') ? thumbnail_preferred_browser_format() : 'jpg');
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
 *
 * @param array $bundle Bundle value.
 * @param int $preferredSize Preferred size value.
 * @param string $preferredFormat Preferred format value.
 * @return string Text result for the caller.
 */
function thumbnail_bundle_url(array $bundle, int $preferredSize, string $preferredFormat = ''): string
{
    $preferredFormat = $preferredFormat !== '' ? $preferredFormat : (function_exists('thumbnail_preferred_browser_format') ? thumbnail_preferred_browser_format() : 'jpg');
    $format = thumbnail_bundle_normalize_format($preferredFormat);
    public_render_profile_record_thumbnail_purpose(null, $preferredSize, $format, 'bundle');
    $selected = thumbnail_bundle_select_variant($bundle, $preferredSize, $preferredFormat);
    return (string) $selected['url'];
}

/**
 * Return a srcset string using only variants already resolved in a thumbnail bundle.
 *
 * @param array $bundle Bundle value.
 * @param array $sizes Sizes value.
 * @param string $format Format value.
 * @return string Text result for the caller.
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
