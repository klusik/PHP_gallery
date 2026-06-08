<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/thumbnail_formats.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Detects thumbnail output formats and WebP support.
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
    // $webpAvailable stores whether the current runtime can write a WebP variant for this source.
    $webpAvailable = $mime !== '' && thumbnail_webp_required_for_source($sourcePath, $mime);

    if (function_exists('thumbnail_formats_for_compatibility_policy')) {
        return thumbnail_formats_for_compatibility_policy($sourcePath, $mime, $webpAvailable);
    }

    if (is_dng_image_path($sourcePath) || $mime === 'image/x-adobe-dng') {
        return dng_derivative_generation_supported() ? ['jpg', 'webp'] : [];
    }

    // $formats stores the concrete variant formats that should exist on disk.
    $formats = ['jpg'];
    if ($webpAvailable) {
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
