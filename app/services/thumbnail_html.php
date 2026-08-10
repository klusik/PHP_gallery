<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/thumbnail_html.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Renders public thumbnail picture markup.
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

namespace Gallery\Services;

use function Gallery\Core\e;

/**
 * Return intrinsic image dimensions for stable public thumbnail layout when metadata is already stored.
 *
 * This helper never opens the original image file. Orientation-aware display dimensions are preferred because
 * they match how public derivatives are shown after EXIF rotation; legacy width/height fields are a safe fallback.
 *
 * @param array $image Image row or image data.
 * @return string Escaped width/height HTML attributes, or an empty string when dimensions are unknown.
 */
function thumbnail_picture_intrinsic_attributes(array $image): string
{
    $dimensions = function_exists('Gallery\\Services\\thumbnail_metadata_image_display_dimensions')
        ? thumbnail_metadata_image_display_dimensions($image)
        : null;
    if (!is_array($dimensions)) {
        $displayWidth = (int) ($image['display_width'] ?? 0);
        $displayHeight = (int) ($image['display_height'] ?? 0);
        $width = $displayWidth > 0 ? $displayWidth : (int) ($image['width'] ?? 0);
        $height = $displayHeight > 0 ? $displayHeight : (int) ($image['height'] ?? 0);
        $dimensions = $width > 0 && $height > 0 ? ['width' => $width, 'height' => $height] : null;
    }

    if (!is_array($dimensions) || (int) ($dimensions['width'] ?? 0) <= 0 || (int) ($dimensions['height'] ?? 0) <= 0) {
        return '';
    }

    return 'width="' . (int) $dimensions['width'] . '" height="' . (int) $dimensions['height'] . '"';
}

/**
 * Render small-first progressive thumbnail picture markup for a public photo card.
 *
 * @param array $image Image row or image data.
 * @param int $fallbackSize Preferred initial thumbnail size in pixels.
 * @param array<int,int> $srcsetSizes Candidate widths requested by the public card.
 * @param string $initialSizes Sizes hint active with the small thumbnail.
 * @param string $finalSizes Sizes hint stored for later progressive activation.
 * @param string $alt Alternative text for the image.
 * @param string $extraAttributes Caller-provided loading, priority, or safe data attributes.
 * @param ?array $thumbnailBundle Request-local preloaded thumbnail bundle.
 * @return string Server-rendered semantic picture markup with larger candidates stored inertly.
 */
function thumbnail_progressive_picture_html(array $image, int $fallbackSize, array $srcsetSizes, string $initialSizes, string $finalSizes, string $alt, string $extraAttributes = '', ?array $thumbnailBundle = null): string
{
    // $thumbnailBundle stores all generated thumbnail variants resolved once for this image during the current request.
    $thumbnailBundle = $thumbnailBundle ?: thumbnail_bundle($image);
    // $preferredFormat stores whether the active cache policy wants WebP or legacy JPEG fallback URLs.
    $preferredFormat = function_exists('Gallery\\Services\\thumbnail_preferred_browser_format') ? thumbnail_preferred_browser_format() : 'jpg';
    // $selectedFallback stores the first image URL and whether it had to fall back to the original media file.
    $selectedFallback = thumbnail_bundle_select_variant($thumbnailBundle, $fallbackSize, $preferredFormat);
    // $fallbackUrl stores the small first image used for the initial responsive paint.
    $fallbackUrl = (string) $selectedFallback['url'];
    // $activeSize stores the real initial derivative width, which may differ from the preferred fallback when that derivative is missing or bounded.
    $activeSize = max(1, (int) ($selectedFallback['size'] ?? $fallbackSize));
    // $progressiveSizes stores only candidates larger than the active image so inert data never advertises a redundant downgrade or same-size replacement.
    $progressiveSizes = array_values(array_filter(array_map('intval', $srcsetSizes), static fn (int $size): bool => $size > $activeSize));
    // $initialWebpSrcset stores only the small WebP candidate so navigation stays responsive.
    $initialWebpSrcset = thumbnail_bundle_srcset($thumbnailBundle, [$activeSize], 'webp');
    // $initialJpegSrcset stores only the small JPEG candidate for browsers without WebP support.
    $initialJpegSrcset = thumbnail_bundle_srcset($thumbnailBundle, [$activeSize], 'jpg');
    // $fullWebpSrcset stores larger WebP candidates that JavaScript applies after the first paint.
    $fullWebpSrcset = thumbnail_bundle_srcset($thumbnailBundle, $progressiveSizes, 'webp');
    // $fullJpegSrcset stores larger JPEG candidates that JavaScript applies after the first paint.
    $fullJpegSrcset = thumbnail_bundle_srcset($thumbnailBundle, $progressiveSizes, 'jpg');
    // $warmupSizes stores missing or invalid derivatives that should be repaired after the page has rendered.
    $warmupSizes = array_merge([$fallbackSize], $srcsetSizes, (array) ($thumbnailBundle['warmup_sizes'] ?? []));
    // $warmupAttributes stores self-healing thumbnail metadata when the rendered image used /media or stale derivatives were removed.
    $warmupAttributes = ((!empty($selectedFallback['is_media_fallback']) || !empty($thumbnailBundle['warmup_sizes'])) && is_array($thumbnailBundle['gallery'] ?? null))
        ? thumbnail_warmup_candidate_attributes($image, $thumbnailBundle['gallery'], $warmupSizes)
        : '';
    // $attributes stores caller-provided attributes plus the progressive marker used by the browser module.
    $attributes = trim($extraAttributes . ' data-progressive-thumbnail ' . $warmupAttributes);
    // $intrinsicAttributes stores database-backed dimensions so the card can reserve its image aspect ratio before decode.
    $intrinsicAttributes = thumbnail_picture_intrinsic_attributes($image);
    // $html stores the generated picture element.
    $html = '<picture>';
    if ($initialWebpSrcset !== '') {
        $html .= '<source type="image/webp" srcset="' . e($initialWebpSrcset) . '" sizes="' . e($initialSizes) . '"';
        if ($fullWebpSrcset !== '') {
            $html .= ' data-progressive-srcset="' . e($fullWebpSrcset) . '" data-progressive-sizes="' . e($finalSizes) . '"';
        }
        $html .= '>';
    }
    $html .= '<img decoding="async" ' . ($attributes === '' ? '' : $attributes . ' ') . ($intrinsicAttributes === '' ? '' : $intrinsicAttributes . ' ') . 'data-progressive-active-width="' . $activeSize . '" src="' . e($fallbackUrl) . '"';
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
 * Render the permanent responsive browser-selection thumbnail picture markup.
 *
 * @param array $image Image row or image data.
 * @param int $fallbackSize Preferred fallback thumbnail size in pixels.
 * @param array<int,int> $srcsetSizes Candidate widths requested by the public card.
 * @param string $sizes Responsive browser sizes hint.
 * @param string $alt Alternative text for the image.
 * @param string $extraAttributes Caller-provided loading, priority, or safe data attributes.
 * @param ?array $thumbnailBundle Request-local preloaded thumbnail bundle.
 * @return string Server-rendered semantic picture markup with complete responsive candidate sets.
 */
function thumbnail_picture_html(array $image, int $fallbackSize, array $srcsetSizes, string $sizes, string $alt, string $extraAttributes = '', ?array $thumbnailBundle = null): string
{
    // $thumbnailBundle stores all generated thumbnail variants resolved once for this image during the current request.
    $thumbnailBundle = $thumbnailBundle ?: thumbnail_bundle($image);
    // $preferredFormat stores whether the active cache policy wants WebP or legacy JPEG fallback URLs.
    $preferredFormat = function_exists('Gallery\\Services\\thumbnail_preferred_browser_format') ? thumbnail_preferred_browser_format() : 'jpg';
    // $selectedFallback stores the first image URL and whether it had to fall back to the original media file.
    $selectedFallback = thumbnail_bundle_select_variant($thumbnailBundle, $fallbackSize, $preferredFormat);
    // $fallbackUrl stores an intermediate value used by the surrounding gallery workflow.
    $fallbackUrl = (string) $selectedFallback['url'];
    // $webpSrcset stores an intermediate value used by the surrounding gallery workflow.
    $webpSrcset = thumbnail_bundle_srcset($thumbnailBundle, $srcsetSizes, 'webp');
    // $jpegSrcset stores an intermediate value used by the surrounding gallery workflow.
    $jpegSrcset = thumbnail_bundle_srcset($thumbnailBundle, $srcsetSizes, 'jpg');
    // $warmupSizes stores missing or invalid derivatives that should be repaired after the page has rendered.
    $warmupSizes = array_merge([$fallbackSize], $srcsetSizes, (array) ($thumbnailBundle['warmup_sizes'] ?? []));
    // $warmupAttributes stores self-healing thumbnail metadata when the rendered image used /media or stale derivatives were removed.
    $warmupAttributes = ((!empty($selectedFallback['is_media_fallback']) || !empty($thumbnailBundle['warmup_sizes'])) && is_array($thumbnailBundle['gallery'] ?? null))
        ? thumbnail_warmup_candidate_attributes($image, $thumbnailBundle['gallery'], $warmupSizes)
        : '';
    // $attributes stores an intermediate value used by the surrounding gallery workflow.
    $attributes = trim($extraAttributes . ' ' . $warmupAttributes);
    // $intrinsicAttributes stores database-backed dimensions so responsive cards reserve layout space before decode.
    $intrinsicAttributes = thumbnail_picture_intrinsic_attributes($image);
    // $html stores an intermediate value used by the surrounding gallery workflow.
    $html = '<picture>';
    if ($webpSrcset !== '') {
        $html .= '<source type="image/webp" srcset="' . e($webpSrcset) . '" sizes="' . e($sizes) . '">';
    }
    $html .= '<img decoding="async" ' . ($attributes === '' ? '' : $attributes . ' ') . ($intrinsicAttributes === '' ? '' : $intrinsicAttributes . ' ') . 'src="' . e($fallbackUrl) . '"';
    if ($jpegSrcset !== '') {
        $html .= ' srcset="' . e($jpegSrcset) . '"';
    }
    $html .= ' sizes="' . e($sizes) . '" alt="' . e($alt) . '"></picture>';
    return $html;
}
