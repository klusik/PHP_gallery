<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/thumbnail_compatibility.php
 * Module Type: Service
 *
 * Purpose:
 *   Controls whether generated thumbnails are stored as modern WebP-only
 *   derivatives or as legacy JPEG plus WebP compatibility pairs.
 *
 * Responsibilities:
 *   - Persist the thumbnail compatibility mode in app_settings
 *   - Keep the thumbnail generator, maintenance scanner, and HTML renderer on
 *     one shared output-format policy
 *   - Remove legacy JPEG thumbnail derivatives without touching original photos
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
 *   2026-06-08
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use function Gallery\Core\is_dng_image_path;
use function Gallery\Core\path_inside;

const THUMBNAIL_COMPATIBILITY_SETTING = 'thumbnail_compatibility_mode';
const THUMBNAIL_COMPATIBILITY_MODERN = 'modern';
const THUMBNAIL_COMPATIBILITY_LEGACY = 'legacy';

/**
 * Return supported thumbnail compatibility modes.
 *
 * @return array<int string>.
 */
function thumbnail_compatibility_modes(): array
{
    return [THUMBNAIL_COMPATIBILITY_MODERN, THUMBNAIL_COMPATIBILITY_LEGACY];
}

/**
 * Normalize a submitted thumbnail compatibility mode.
 *
 * @param ?string $mode Mode value.
 * @return string Text result for the caller.
 */
function thumbnail_compatibility_mode_normalize(?string $mode): string
{
    $mode = strtolower(trim((string) $mode));
    return in_array($mode, thumbnail_compatibility_modes(), true) ? $mode : THUMBNAIL_COMPATIBILITY_MODERN;
}

/**
 * Return the configured thumbnail compatibility mode.
 *
 * @return string Text result for the caller.
 */
function thumbnail_compatibility_mode(): string
{
    $stored = function_exists('Gallery\\Services\\app_setting') ? app_setting(THUMBNAIL_COMPATIBILITY_SETTING, THUMBNAIL_COMPATIBILITY_MODERN) : THUMBNAIL_COMPATIBILITY_MODERN;
    return thumbnail_compatibility_mode_normalize(is_string($stored) ? $stored : THUMBNAIL_COMPATIBILITY_MODERN);
}

/**
 * Persist the thumbnail compatibility mode.
 *
 * @param string $mode Mode value.
 */
function set_thumbnail_compatibility_mode(string $mode): void
{
    if (!function_exists('Gallery\\Services\\set_app_setting')) {
        return;
    }
    set_app_setting(THUMBNAIL_COMPATIBILITY_SETTING, thumbnail_compatibility_mode_normalize($mode));
}

/**
 * Return true when new thumbnails should avoid legacy JPEG variants where WebP can be generated safely.
 *
 * @return bool True when the condition matches.
 */
function thumbnail_compatibility_modern_enabled(): bool
{
    return thumbnail_compatibility_mode() === THUMBNAIL_COMPATIBILITY_MODERN;
}

/**
 * Return the preferred browser thumbnail format for fallback image URLs.
 *
 * @return string Text result for the caller.
 */
function thumbnail_preferred_browser_format(): string
{
    return thumbnail_compatibility_modern_enabled() ? 'webp' : 'jpg';
}

/**
 * Return a stable machine-readable label for the active thumbnail output policy.
 *
 * @param ?string $mode Mode value.
 * @return string Text result for the caller.
 */
function thumbnail_compatibility_mode_log_value(?string $mode = null): string
{
    $mode = thumbnail_compatibility_mode_normalize($mode ?? thumbnail_compatibility_mode());
    return $mode === THUMBNAIL_COMPATIBILITY_LEGACY ? 'jpg_plus_webp' : 'webp_only';
}

/**
 * Return the formats requested by the configured thumbnail output policy.
 *
 * This is the user-facing intent. Runtime capability checks can still reduce
 * the concrete target formats for one source image, but modern mode must never
 * silently ask the generator to create JPEG thumbnails.
 *
 * @param ?string $mode Mode value.
 * @return array<int string>.
 */
function thumbnail_policy_requested_formats(?string $mode = null): array
{
    $mode = thumbnail_compatibility_mode_normalize($mode ?? thumbnail_compatibility_mode());
    return $mode === THUMBNAIL_COMPATIBILITY_LEGACY ? ['jpg', 'webp'] : ['webp'];
}

/**
 * Return whether one generated thumbnail format is allowed by the active policy.
 *
 * @param string $format Format value.
 * @param ?string $mode Optional compatibility mode override.
 * @return bool True when the generated format may be used.
 */
function thumbnail_policy_format_allowed(string $format, ?string $mode = null): bool
{
    return in_array(strtolower(trim($format)), thumbnail_policy_requested_formats($mode), true);
}

/**
 * Return a readable label for one compatibility mode.
 *
 * @param string $mode Mode value.
 * @return string Text result for the caller.
 */
function thumbnail_compatibility_mode_label(string $mode): string
{
    $mode = thumbnail_compatibility_mode_normalize($mode);
    if ($mode === THUMBNAIL_COMPATIBILITY_LEGACY) {
        return t('admin.thumbnails.compatibility_legacy_label', 'Legacy, JPG plus WebP');
    }
    return t('admin.thumbnails.compatibility_modern_label', 'Modern, WebP only');
}

/**
 * Return whether a WebP thumbnail can be written for the current source.
 *
 * @param string $sourcePath Source filesystem path.
 * @param string $mime Mime value.
 * @return bool True when the condition matches.
 */
function thumbnail_source_webp_available_for_policy(string $sourcePath, string $mime): bool
{
    return function_exists('Gallery\\Services\\thumbnail_webp_required_for_source') && thumbnail_webp_required_for_source($sourcePath, $mime);
}

/**
 * Return target thumbnail formats after applying compatibility mode and runtime capability.
 *
 * Modern mode is strict WebP-only output. If the current server cannot write
 * WebP safely for one source, the caller receives no writable target format
 * instead of silently creating legacy JPEG thumbnails.
 *
 * @param string $sourcePath Source filesystem path.
 * @param string $mime Mime value.
 * @param bool $webpAvailable Webp available value.
 * @return array<int string>.
 */
function thumbnail_formats_for_compatibility_policy(string $sourcePath, string $mime, bool $webpAvailable): array
{
    if ($mime === 'image/x-adobe-dng' || (function_exists('Gallery\\Core\\is_dng_image_path') && is_dng_image_path($sourcePath))) {
        if (!function_exists('Gallery\\Services\\dng_derivative_generation_supported') || !dng_derivative_generation_supported()) {
            return [];
        }
        return thumbnail_compatibility_modern_enabled() ? ['webp'] : ['jpg', 'webp'];
    }

    if (thumbnail_compatibility_modern_enabled()) {
        return $webpAvailable ? ['webp'] : [];
    }

    $formats = ['jpg'];
    if ($webpAvailable) {
        $formats[] = 'webp';
    }
    return $formats;
}

/**
 * Return the number of JPEG thumbnail derivatives that may exist for one image.
 *
 * @return int Integer result for the caller.
 */
function thumbnail_legacy_jpg_variant_count(): int
{
    return function_exists('Gallery\\Services\\thumbnail_sizes') ? count(thumbnail_sizes()) : 0;
}

/**
 * Format deleted thumbnail bytes for admin notices.
 *
 * @param int $bytes Bytes value.
 * @return string Text result for the caller.
 */
function thumbnail_compatibility_format_bytes(int $bytes): string
{
    if (function_exists('Gallery\\Services\\admin_dashboard_format_bytes')) {
        return admin_dashboard_format_bytes($bytes);
    }
    if (function_exists('telemetry_format_bytes')) {
        return telemetry_format_bytes($bytes);
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = max(0.0, (float) $bytes);
    $index = 0;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }
    return ($index === 0 ? number_format($value, 0) : number_format($value, 1)) . ' ' . $units[$index];
}

/**
 * Delete legacy JPEG thumbnail files for one indexed image.
 *
 * This removes only generated files named through thumbnail_filename() with the
 * jpg format. It does not delete original images, database rows, WebP thumbs,
 * DNG display masters, gallery cover assets, or any other file in the gallery.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @return array{files_deleted:int,bytes_deleted:int,checked:int} Structured result data for the caller.
 */
function delete_legacy_jpg_thumbnails_for_image(array $image, array $gallery): array
{
    $filesDeleted = 0;
    $bytesDeleted = 0;
    $checked = 0;
    $galleryRoot = galleries_root();

    foreach (thumbnail_sizes() as $size) {
        $checked++;
        try {
            $path = thumbnail_abs_path($image, $gallery, (int) $size, 'jpg');
        } catch (Throwable) {
            continue;
        }
        if (!is_file($path)) {
            if (function_exists('Gallery\\Services\\thumbnail_metadata_delete_variant')) {
                thumbnail_metadata_delete_variant($image, (int) $size, 'jpg');
            }
            continue;
        }
        if (!path_inside($galleryRoot, $path)) {
            throw new RuntimeException('Refusing to delete a legacy thumbnail outside the gallery root.');
        }
        $bytes = @filesize($path);
        if (!@unlink($path)) {
            throw new RuntimeException('Could not delete legacy JPEG thumbnail: ' . $path);
        }
        if (function_exists('Gallery\\Services\\thumbnail_metadata_delete_variant')) {
            thumbnail_metadata_delete_variant($image, (int) $size, 'jpg');
        }
        $filesDeleted++;
        $bytesDeleted += $bytes === false ? 0 : max(0, (int) $bytes);
    }

    return ['files_deleted' => $filesDeleted, 'bytes_deleted' => $bytesDeleted, 'checked' => $checked];
}

/**
 * Delete legacy JPEG thumbnail files for a list of image IDs.
 *
 * @param array $imageIds Image ids value.
 * @return array{files_deleted:int,bytes_deleted:int,checked:int,images_checked:int} Structured result data for the caller.
 */
function delete_legacy_jpg_thumbnails_for_image_ids(array $imageIds): array
{
    $filesDeleted = 0;
    $bytesDeleted = 0;
    $checked = 0;
    $imagesChecked = 0;
    $galleryCache = [];

    foreach (array_values(array_unique(array_filter(array_map('intval', $imageIds), static fn (int $id): bool => $id > 0))) as $imageId) {
        $image = find_image($imageId);
        if (!$image) {
            continue;
        }
        $galleryId = (int) ($image['gallery_id'] ?? 0);
        if (!array_key_exists($galleryId, $galleryCache)) {
            $galleryCache[$galleryId] = $galleryId > 0 ? find_gallery($galleryId) : null;
        }
        if (!$galleryCache[$galleryId]) {
            continue;
        }
        $result = delete_legacy_jpg_thumbnails_for_image($image, $galleryCache[$galleryId]);
        $imagesChecked++;
        $filesDeleted += (int) $result['files_deleted'];
        $bytesDeleted += (int) $result['bytes_deleted'];
        $checked += (int) $result['checked'];
    }

    return [
        'files_deleted' => $filesDeleted,
        'bytes_deleted' => $bytesDeleted,
        'checked' => $checked,
        'images_checked' => $imagesChecked,
    ];
}
