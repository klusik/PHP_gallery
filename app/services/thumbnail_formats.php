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
 *
 * @param mixed $sourcePath Input used by this operation.
 * @param mixed $mime Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_webp_required_for_source(string $sourcePath, string $mime): bool
{
    if (!function_exists('imagewebp')) {
        return false;
    }
    if ($mime === 'image/x-adobe-dng' || (function_exists('is_dng_image_path') && is_dng_image_path($sourcePath))) {
        return function_exists('dng_derivative_generation_supported') && dng_derivative_generation_supported();
    }

    return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
}

/**
 * Return whether Imagick can write WebP thumbnails on this server.
 *
 * Some shared hosts expose the Imagick PHP class without the WebP delegate.
 * In that state class_exists('Imagick') is true, but writeImage() still fails
 * for WebP targets. The maintenance scanner must not require WebP variants
 * that the generator will refuse or fail to create.
 *
 * @return bool True when the condition matches.
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
 * The maintenance scanner and the generator must use this same decision so
 * dashboard counts, warmup repair, and upload thumbnail creation agree about
 * which output files should exist.
 *
 * @param string $sourcePath Source filesystem path.
 * @param string $mime Mime value.
 * @return array<int string>.
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
 *
 * @param string $sourcePath Source filesystem path.
 * @param string $mime Mime value.
 * @return int Integer result for the caller.
 */
function thumbnail_intentionally_skipped_webp_count(string $sourcePath, string $mime): int
{
    if (function_exists('imagewebp')) {
        return 0;
    }
    if (function_exists('thumbnail_policy_requested_formats') && in_array('webp', thumbnail_policy_requested_formats(), true)) {
        return count(thumbnail_sizes());
    }
    return 0;
}


/**
 * Return why the current runtime cannot write WebP for one source image.
 *
 * @param string $sourcePath Source filesystem path.
 * @param string $mime Mime value.
 * @return ?string Text result for the caller.
 */
function thumbnail_webp_unavailable_reason_for_source(string $sourcePath, string $mime): ?string
{
    if (thumbnail_webp_required_for_source($sourcePath, $mime)) {
        return null;
    }
    if (!function_exists('imagewebp')) {
        return 'gd_imagewebp_missing';
    }
    if ($mime === '') {
        return 'source_mime_unknown';
    }
    return 'webp_not_supported_for_source';
}

/**
 * Return the complete thumbnail generation policy for logging and diagnostics.
 *
 * @param string $sourcePath Source filesystem path.
 * @param string $mime Mime value.
 * @param ?array $requestedSizes Requested sizes value.
 * @return array<string mixed>.
 */
function thumbnail_generation_policy_summary(string $sourcePath, string $mime, ?array $requestedSizes = null): array
{
    // $enabledSizes stores every thumbnail size supported by this installation.
    $enabledSizes = array_values(array_map('intval', thumbnail_sizes()));
    // $operationSizes stores the effective sizes for this generation request.
    $operationSizes = $requestedSizes === null
        ? $enabledSizes
        : array_values(array_unique(array_filter(array_map('intval', $requestedSizes), static fn (int $size): bool => in_array($size, $enabledSizes, true))));
    // $requestedFormats stores the configured output intent before runtime capability checks.
    $requestedFormats = function_exists('thumbnail_policy_requested_formats') ? thumbnail_policy_requested_formats() : ['jpg', 'webp'];
    // $targetFormats stores the actual formats the generator is allowed to create for this source.
    $targetFormats = thumbnail_target_formats_for_source($sourcePath, $mime);
    // $webpAvailable stores the source-specific WebP capability used to produce target formats.
    $webpAvailable = $mime !== '' && thumbnail_webp_required_for_source($sourcePath, $mime);

    return [
        'mode' => function_exists('thumbnail_compatibility_mode_log_value') ? thumbnail_compatibility_mode_log_value() : 'jpg_plus_webp',
        'compatibility_mode' => function_exists('thumbnail_compatibility_mode') ? thumbnail_compatibility_mode() : 'legacy',
        'formats_requested' => $requestedFormats,
        'target_formats' => $targetFormats,
        'enabled_sizes' => $enabledSizes,
        'requested_sizes' => $operationSizes,
        'jpg_quality' => function_exists('thumbnail_jpeg_quality') ? thumbnail_jpeg_quality() : 82,
        'webp_quality' => function_exists('thumbnail_webp_quality') ? thumbnail_webp_quality() : 82,
        'webp_available_for_source' => $webpAvailable,
        'webp_unavailable_reason' => $webpAvailable ? null : thumbnail_webp_unavailable_reason_for_source($sourcePath, $mime),
    ];
}
