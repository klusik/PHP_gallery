<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/public_gallery_media_manifest.php
 * Module Type: Service
 *
 * Purpose:
 *   Builds request-local public gallery media data from thumbnail metadata.
 *
 * Responsibilities:
 *   - Prepare visible gallery image thumbnail bundles in one request step
 *   - Reuse durable thumbnail metadata instead of asking each card to discover variants
 *   - Keep legacy thumbnail behavior available when metadata tables are not installed
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
 *   2026-06-18
 */

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;
use function Gallery\Core\db;
use function Gallery\Core\image_public_url;
use function Gallery\Core\url_for;

/**
 * Return unique image rows keyed by their database id.
 *
 * @param array $images Image rows to normalize.
 * @return array<int,array<string,mixed>> Image rows keyed by id.
 */
function public_gallery_media_manifest_images_by_id(array $images): array
{
    // $imagesById stores visible rows once so later manifest work does not repeat duplicate image ids.
    $imagesById = [];
    foreach ($images as $image) {
        // $imageId stores the image identifier used by thumbnail metadata rows.
        $imageId = (int) ($image['id'] ?? 0);
        if ($imageId <= 0 || array_key_exists($imageId, $imagesById)) {
            continue;
        }
        $imagesById[$imageId] = $image;
    }
    return $imagesById;
}

/**
 * Return normalized configured thumbnail sizes once for the current manifest.
 *
 * @return array<int,int> Thumbnail sizes keyed and valued by size.
 */
function public_gallery_media_manifest_configured_sizes(): array
{
    // $sizes stores every configured derivative size as integers.
    $sizes = [];
    foreach (thumbnail_sizes() as $size) {
        // $size stores one configured thumbnail derivative size.
        $size = (int) $size;
        if ($size > 0) {
            $sizes[$size] = $size;
        }
    }
    ksort($sizes);
    return $sizes;
}

/**
 * Load renderable thumbnail rows for all visible images in one narrow query.
 *
 * The generic metadata preload remains available for legacy callers. This
 * manifest uses a slimmer query and groups rows directly because it needs only
 * the already validated row facts required to build public URLs.
 *
 * @param array<int,array<string,mixed>> $imagesById Image rows keyed by id.
 * @param array<int,int> $sizes Sizes keyed and valued by size.
 * @return array<int,array<string,array<int,array<string,mixed>>>> Rows keyed by image id, format, and size.
 */
function public_gallery_media_manifest_renderable_rows(array $imagesById, array $sizes): array
{
    if (!thumbnail_metadata_schema_ready() || !$imagesById || !$sizes) {
        return [];
    }

    // $imageIds stores image ids for the batched metadata lookup.
    $imageIds = array_keys($imagesById);
    // $imagePlaceholders stores placeholders for image ids.
    $imagePlaceholders = implode(',', array_fill(0, count($imageIds), '?'));
    // $sizePlaceholders stores placeholders for thumbnail sizes.
    $sizePlaceholders = implode(',', array_fill(0, count($sizes), '?'));
    // $params stores bound image ids and sizes.
    $params = array_merge($imageIds, array_values($sizes));

    // $derivativeVersionSelect stores a compatible staleness marker for compact and older metadata schemas.
    $derivativeVersionSelect = thumbnail_metadata_variant_column_exists('derivative_version') ? 'derivative_version' : '0 AS derivative_version';

    try {
        // $metadataRows stores the measured database result for this manifest-only lookup.
        $metadataRows = public_render_profile_db('media_manifest_query_metadata', static function () use ($imagePlaceholders, $sizePlaceholders, $derivativeVersionSelect, $params): array {
            $stmt = db()->prepare("SELECT image_id, size_px, format, width, height, status, $derivativeVersionSelect FROM image_thumbnail_variants WHERE image_id IN ($imagePlaceholders) AND size_px IN ($sizePlaceholders) AND format IN ('jpg', 'webp') AND status = 'valid' ORDER BY image_id, size_px, format");
            $stmt->execute($params);
            return $stmt->fetchAll();
        });
    } catch (Throwable) {
        return [];
    }

    public_render_profile_count('thumbnail_manifest_rows_loaded', count($metadataRows));

    return public_render_profile_span('media_manifest_group_rows', static function () use ($imageIds, $imagesById, $sizes, $metadataRows): array {
        // $rowsByImage stores renderable rows grouped in the shape expected by the bundle builder.
        $rowsByImage = [];
        foreach ($imageIds as $imageId) {
            $rowsByImage[(int) $imageId] = ['jpg' => [], 'webp' => []];
        }

        foreach ($metadataRows as $row) {
            // $imageId stores the owner image id for this thumbnail metadata row.
            $imageId = (int) ($row['image_id'] ?? 0);
            if (!isset($imagesById[$imageId])) {
                continue;
            }
            // $format stores the thumbnail format for this metadata row.
            $format = (string) ($row['format'] ?? '');
            // $size stores the generated thumbnail size represented by this metadata row.
            $size = (int) ($row['size_px'] ?? 0);
            if (!isset($sizes[$size]) || !in_array($format, ['jpg', 'webp'], true)) {
                continue;
            }
            if (!thumbnail_metadata_row_is_renderable($row, $imagesById[$imageId])) {
                continue;
            }
            $rowsByImage[$imageId][$format][$size] = $row;
        }

        $imagesWithRows = 0;
        foreach ($rowsByImage as $imageId => $rows) {
            ksort($rowsByImage[$imageId]['jpg']);
            ksort($rowsByImage[$imageId]['webp']);
            if (($rowsByImage[$imageId]['jpg'] ?? []) || ($rowsByImage[$imageId]['webp'] ?? [])) {
                $imagesWithRows++;
            }
        }
        public_render_profile_count('thumbnail_manifest_images_with_rows', $imagesWithRows);

        return $rowsByImage;
    });
}

/**
 * Return the public base URL used to derive media and thumbnail URLs for one image.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @return string Text result for the caller.
 */
function public_gallery_media_manifest_image_base_url(array $image, array $gallery): string
{
    return rtrim(image_public_url($image, $gallery), '/');
}

/**
 * Return a public media URL without repeated generic URL resolution.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param string $imageBaseUrl Public image base URL.
 * @return string Text result for the caller.
 */
function public_gallery_media_manifest_media_url(array $image, array $gallery, string $imageBaseUrl): string
{
    if (public_path_schema_ready()) {
        unset($image, $gallery);
        return $imageBaseUrl . '/media';
    }

    unset($gallery);
    return url_for('media', ['id' => (int) ($image['id'] ?? 0)]);
}

/**
 * Return a public thumbnail URL without repeated generic URL resolution.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param string $imageBaseUrl Public image base URL.
 * @param int $size Thumbnail size.
 * @param string $format Thumbnail format.
 * @return string Text result for the caller.
 */
function public_gallery_media_manifest_variant_url(array $image, array $gallery, string $imageBaseUrl, int $size, string $format): string
{
    // $format stores the normalized generated thumbnail format.
    $format = $format === 'webp' ? 'webp' : 'jpg';
    if (public_path_schema_ready()) {
        unset($image, $gallery);
        return $imageBaseUrl . '/thumb-' . (int) $size . '.' . $format;
    }

    return thumbnail_serving_url($image, $gallery, (int) $size, $format);
}

/**
 * Build one thumbnail bundle shape from already loaded durable metadata rows.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param array<int,int> $allSizes All configured thumbnail sizes.
 * @param array<string,array<int,array<string,mixed>>> $rows Renderable metadata rows for this image.
 * @param string $imageBaseUrl Public image base URL.
 * @return array<string,mixed> Bundle-compatible thumbnail data.
 */
function public_gallery_media_manifest_metadata_bundle(array $image, array $gallery, array $allSizes, array $rows, string $imageBaseUrl): array
{
    // $boundedSizes stores sizes allowed by gallery-specific thumbnail bounds.
    $boundedSizes = array_values($allSizes);
    if (function_exists('Gallery\\Services\\thumbnail_bound_filter_sizes')) {
        $boundedSizes = thumbnail_bound_filter_sizes($boundedSizes, $image, $gallery);
    }
    $boundedSizesBySize = [];
    foreach ($boundedSizes as $size) {
        // $size stores one allowed derivative size.
        $size = (int) $size;
        if ($size > 0) {
            $boundedSizesBySize[$size] = $size;
        }
    }
    ksort($boundedSizesBySize);

    // $bundle stores the same structure consumed by thumbnail_picture_html() and thumbnail_bundle_url().
    $bundle = [
        'image' => $image,
        'gallery' => $gallery,
        'media_url' => public_gallery_media_manifest_media_url($image, $gallery, $imageBaseUrl),
        'sizes' => array_values($boundedSizesBySize),
        'variants' => [
            'jpg' => [],
            'webp' => [],
        ],
        'warmup_sizes' => [],
    ];

    foreach (['jpg', 'webp'] as $format) {
        foreach ($rows[$format] ?? [] as $size => $row) {
            // $size stores the generated thumbnail size represented by this metadata row.
            $size = (int) $size;
            if (!isset($boundedSizesBySize[$size])) {
                continue;
            }
            unset($row);
            $bundle['variants'][$format][$size] = public_gallery_media_manifest_variant_url($image, $gallery, $imageBaseUrl, $size, $format);
            public_render_profile_count('thumbnail_manifest_variant_hits');
            public_render_profile_count('thumbnail_manifest_url_builds');
        }
        ksort($bundle['variants'][$format]);
    }

    foreach ($boundedSizesBySize as $size) {
        // $size stores one allowed derivative size that may need background repair.
        if (!isset($bundle['variants']['jpg'][$size]) && !isset($bundle['variants']['webp'][$size])) {
            $bundle['warmup_sizes'][$size] = $size;
        }
    }

    return $bundle;
}

/**
 * Select one URL from an already prepared manifest bundle using cheap array reads.
 *
 * @param array $bundle Bundle-compatible thumbnail data.
 * @param int $preferredSize Preferred thumbnail size.
 * @param string $preferredFormat Preferred thumbnail format.
 * @param ?array<int,int> $candidateSizes Candidate sizes already sorted for this bundle.
 * @return string Text result for the caller.
 */
function public_gallery_media_manifest_select_url(array $bundle, int $preferredSize, string $preferredFormat = 'webp', ?array $candidateSizes = null): string
{
    public_render_profile_count('thumbnail_manifest_selection_attempts');
    // $preferredFormat stores the normalized caller preference used for URL selection.
    $preferredFormat = $preferredFormat === 'jpg' ? 'jpg' : 'webp';
    // $effectiveSize stores the preferred size after optional gallery bounds.
    $effectiveSize = thumbnail_bundle_effective_size($bundle, (int) $preferredSize);
    // $variants stores prepared generated URLs indexed by format and size.
    $variants = is_array($bundle['variants'] ?? null) ? $bundle['variants'] : ['jpg' => [], 'webp' => []];

    if (isset($variants[$preferredFormat][$effectiveSize])) {
        public_render_profile_count('thumbnail_manifest_exact_selections');
        return (string) $variants[$preferredFormat][$effectiveSize];
    }

    if ($candidateSizes === null) {
        $candidateSizes = [];
        foreach (['jpg', 'webp'] as $format) {
            foreach (array_keys($variants[$format] ?? []) as $size) {
                $candidateSizes[(int) $size] = (int) $size;
            }
        }
    }

    usort($candidateSizes, static function (int $left, int $right) use ($effectiveSize): int {
        return abs($left - $effectiveSize) <=> abs($right - $effectiveSize);
    });

    // $formats stores the same fallback order used by the generic bundle resolver.
    $formats = $preferredFormat === 'webp' ? ['webp', 'jpg'] : ['jpg', 'webp'];
    foreach ($candidateSizes as $size) {
        foreach ($formats as $format) {
            if (isset($variants[$format][$size])) {
                public_render_profile_count('thumbnail_bundle_fallback_hits');
                public_render_profile_count('thumbnail_manifest_fallback_selections');
                return (string) $variants[$format][$size];
            }
        }
    }

    public_render_profile_count('thumbnail_bundle_media_fallbacks');
    public_render_profile_count('thumbnail_manifest_media_fallback_selections');
    return (string) ($bundle['media_url'] ?? url_for('media', ['id' => (int) (($bundle['image']['id'] ?? 0))]));
}

/**
 * Return candidate sizes present in a prepared manifest bundle.
 *
 * @param array $bundle Bundle-compatible thumbnail data.
 * @return array<int,int> Candidate sizes keyed and valued by size.
 */
function public_gallery_media_manifest_candidate_sizes(array $bundle): array
{
    // $candidateSizes stores generated derivative sizes available for this image.
    $candidateSizes = [];
    foreach (['jpg', 'webp'] as $format) {
        foreach (array_keys((array) ($bundle['variants'][$format] ?? [])) as $size) {
            // $size stores one generated derivative size.
            $size = (int) $size;
            if ($size > 0) {
                $candidateSizes[$size] = $size;
            }
        }
    }
    ksort($candidateSizes);
    return $candidateSizes;
}

/**
 * Record one manifest-selected URL under a thumbnail purpose label.
 *
 * @param string $purpose Purpose label.
 * @param int $size Thumbnail size.
 * @param string $format Thumbnail format.
 */
function public_gallery_media_manifest_record_purpose(string $purpose, int $size, string $format): void
{
    public_render_profile_record_thumbnail_purpose($purpose, (int) $size, $format === 'jpg' ? 'jpg' : 'webp', 'bundle');
}

/**
 * Build request-local public media data for visible gallery image cards.
 *
 * The returned manifest lets the public controller render cards without calling
 * thumbnail_bundle() for every image. Durable thumbnail metadata is read once
 * with a narrow batched query, then each visible image receives a compatible
 * bundle value that existing thumbnail HTML helpers can consume unchanged.
 *
 * @param array $images Visible image rows.
 * @param array $gallery Current gallery row.
 * @return array<int,array<string,mixed>> Manifest rows keyed by image id.
 */
function public_gallery_media_manifest(array $images, array $gallery): array
{
    return public_render_profile_span('public_gallery_media_manifest', static function () use ($images, $gallery): array {
        // $imagesById stores unique visible image rows for this gallery render.
        $imagesById = public_render_profile_span('media_manifest_collect_images', static fn (): array => public_gallery_media_manifest_images_by_id($images));
        if (!$imagesById) {
            return [];
        }

        public_render_profile_count('thumbnail_manifest_images', count($imagesById));

        // $allSizes stores every configured derivative size so one preload can serve later per-image selection.
        $allSizes = public_render_profile_span('media_manifest_configured_sizes', static fn (): array => public_gallery_media_manifest_configured_sizes());

        // $metadataReady stores whether DB-backed metadata can build bundles without per-card discovery.
        $metadataReady = public_render_profile_span('media_manifest_schema_check', static fn (): bool => thumbnail_metadata_schema_ready());
        // $rowsByImage stores all renderable variant rows discovered for this request in one narrow query.
        $rowsByImage = $metadataReady ? public_gallery_media_manifest_renderable_rows($imagesById, $allSizes) : [];
        // $previewFormat stores the configured browser thumbnail format used by card preview URLs.
        $previewFormat = public_render_profile_span('media_manifest_preview_format', static function (): string {
            $format = function_exists('Gallery\\Services\\thumbnail_preferred_browser_format') ? thumbnail_preferred_browser_format() : 'jpg';
            return $format === 'webp' ? 'webp' : 'jpg';
        });

        return public_render_profile_span('media_manifest_build_entries', static function () use ($imagesById, $gallery, $allSizes, $metadataReady, $rowsByImage, $previewFormat): array {
            // $manifest stores prepared media data keyed by image id.
            $manifest = [];
            foreach ($imagesById as $imageId => $image) {
                // $imageBaseUrl stores the clean image URL once so thumbnail URLs can be composed cheaply.
                $imageBaseUrl = public_gallery_media_manifest_image_base_url($image, $gallery);
                // $bundle stores thumbnail variants for this image without invoking thumbnail_bundle() when metadata is available.
                $bundle = $metadataReady
                    ? public_render_profile_span('media_manifest_build_bundle', static fn (): array => public_gallery_media_manifest_metadata_bundle($image, $gallery, $allSizes, $rowsByImage[(int) $imageId] ?? ['jpg' => [], 'webp' => []], $imageBaseUrl))
                    : public_render_profile_with_thumbnail_purpose('media manifest legacy bundle fallback', static fn (): array => thumbnail_bundle($image));

                if ($metadataReady) {
                    public_render_profile_count('thumbnail_manifest_metadata_bundles');
                } else {
                    public_render_profile_count('thumbnail_manifest_legacy_bundles');
                }

                // $candidateSizes stores generated derivative sizes once for all URL choices below.
                $candidateSizes = public_gallery_media_manifest_candidate_sizes($bundle);
                public_render_profile_count('thumbnail_manifest_candidate_sizes', count($candidateSizes));
                // $previewUrl stores the lightbox preview URL used by cards, picture manager sharing, and JS metadata.
                $previewUrl = public_gallery_media_manifest_select_url($bundle, 1600, $previewFormat, $candidateSizes);
                public_gallery_media_manifest_record_purpose('media manifest lightbox preview 1600', 1600, $previewFormat);
                // $seoContentUrl stores the crawler content URL selected from the prepared bundle.
                $seoContentUrl = public_gallery_media_manifest_select_url($bundle, 1200, 'webp', $candidateSizes);
                public_gallery_media_manifest_record_purpose('media manifest seo content 1200', 1200, 'webp');
                // $seoThumbnailUrl stores the crawler thumbnail URL selected from the prepared bundle.
                $seoThumbnailUrl = public_gallery_media_manifest_select_url($bundle, 800, 'webp', $candidateSizes);
                public_gallery_media_manifest_record_purpose('media manifest seo thumbnail 800', 800, 'webp');

                $manifest[(int) $imageId] = [
                    'image' => $image,
                    'bundle' => $bundle,
                    'preview_url' => $previewUrl,
                    'seo_content_url' => $seoContentUrl,
                    'seo_thumbnail_url' => $seoThumbnailUrl,
                ];
            }

            return $manifest;
        });
    });
}
