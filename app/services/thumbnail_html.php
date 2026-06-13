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
 * Handles progressive thumbnail picture html logic for the gallery application.
 *
 * @param mixed $image Input used by this operation.
 * @param mixed $fallbackSize Input used by this operation.
 * @param mixed $srcsetSizes Input used by this operation.
 * @param mixed $initialSizes Input used by this operation.
 * @param mixed $finalSizes Input used by this operation.
 * @param mixed $alt Input used by this operation.
 * @param mixed $extraAttributes Input used by this operation.
 * @param ?array $thumbnailBundle Thumbnail bundle value.
 * @return mixed Result produced by this operation.
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
    // $initialWebpSrcset stores only the small WebP candidate so navigation stays responsive.
    $initialWebpSrcset = thumbnail_bundle_srcset($thumbnailBundle, [$fallbackSize], 'webp');
    // $initialJpegSrcset stores only the small JPEG candidate for browsers without WebP support.
    $initialJpegSrcset = thumbnail_bundle_srcset($thumbnailBundle, [$fallbackSize], 'jpg');
    // $fullWebpSrcset stores larger WebP candidates that JavaScript applies after the first paint.
    $fullWebpSrcset = thumbnail_bundle_srcset($thumbnailBundle, $srcsetSizes, 'webp');
    // $fullJpegSrcset stores larger JPEG candidates that JavaScript applies after the first paint.
    $fullJpegSrcset = thumbnail_bundle_srcset($thumbnailBundle, $srcsetSizes, 'jpg');
    // $warmupSizes stores missing or invalid derivatives that should be repaired after the page has rendered.
    $warmupSizes = array_merge([$fallbackSize], $srcsetSizes, (array) ($thumbnailBundle['warmup_sizes'] ?? []));
    // $warmupAttributes stores self-healing thumbnail metadata when the rendered image used /media or stale derivatives were removed.
    $warmupAttributes = ((!empty($selectedFallback['is_media_fallback']) || !empty($thumbnailBundle['warmup_sizes'])) && is_array($thumbnailBundle['gallery'] ?? null))
        ? thumbnail_warmup_candidate_attributes($image, $thumbnailBundle['gallery'], $warmupSizes)
        : '';
    // $attributes stores caller-provided attributes plus the progressive marker used by the browser module.
    $attributes = trim($extraAttributes . ' data-progressive-thumbnail ' . $warmupAttributes);
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
 *
 * @param mixed $image Input used by this operation.
 * @param mixed $fallbackSize Input used by this operation.
 * @param mixed $srcsetSizes Input used by this operation.
 * @param mixed $sizes Input used by this operation.
 * @param mixed $alt Input used by this operation.
 * @param mixed $extraAttributes Input used by this operation.
 * @param ?array $thumbnailBundle Thumbnail bundle value.
 * @return mixed Result produced by this operation.
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
