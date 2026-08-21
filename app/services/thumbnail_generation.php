<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/thumbnail_generation.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Generates thumbnail files and resized image variants.
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

use GdImage;
use Imagick;
use PDO;
use RuntimeException;
use Throwable;
use function Gallery\Core\db;

/**
 * Return the JPEG quality used by generated thumbnail files.
 *
 * @return int Integer result for the caller.
 */
function thumbnail_jpeg_quality(): int
{
    return 82;
}

/**
 * Return the WebP quality used by generated thumbnail files.
 *
 * @return int Integer result for the caller.
 */
function thumbnail_webp_quality(): int
{
    return 82;
}

/**
 * Return expected generated thumbnail dimensions for one source image.
 *
 * The gallery thumbnails must preserve the source aspect ratio. A generated
 * derivative with a square canvas around a portrait or landscape image is an
 * invalid cache artifact and should be regenerated.
 *
 * @param int $sourceWidth Source width value.
 * @param int $sourceHeight Source height value.
 * @param int $maxSide Max side value.
 * @return array{width:int,height:int} Structured result data for the caller.
 */
function thumbnail_expected_dimensions(int $sourceWidth, int $sourceHeight, int $maxSide): array
{
    $sourceWidth = max(1, $sourceWidth);
    $sourceHeight = max(1, $sourceHeight);
    $maxSide = max(1, $maxSide);

    // $scale stores the downscale factor while avoiding thumbnail upscaling.
    $scale = min(1.0, $maxSide / max($sourceWidth, $sourceHeight));

    return [
        'width' => max(1, (int) round($sourceWidth * $scale)),
        'height' => max(1, (int) round($sourceHeight * $scale)),
    ];
}

/**
 * Return a JPEG EXIF orientation value when it is available.
 *
 * @param string $sourcePath Source filesystem path.
 * @param string $mime Mime value.
 * @return int Integer result for the caller.
 */
function thumbnail_jpeg_exif_orientation(string $sourcePath, string $mime = ''): int
{
    if ($mime !== '' && $mime !== 'image/jpeg') {
        return 1;
    }
    if (!function_exists('exif_read_data') || !is_file($sourcePath)) {
        return 1;
    }

    try {
        // $exif stores the compact EXIF section needed only for orientation-aware geometry.
        $exif = @exif_read_data($sourcePath, 'IFD0', true, false);
    } catch (Throwable) {
        return 1;
    }

    if (!is_array($exif)) {
        return 1;
    }

    // $orientation stores the normalized EXIF orientation value.
    $orientation = (int) ($exif['IFD0']['Orientation'] ?? $exif['Orientation'] ?? 1);
    return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
}

/**
 * Return true when EXIF orientation swaps visual width and height.
 *
 * @param int $orientation Orientation value.
 * @return bool True when the condition matches.
 */
function thumbnail_orientation_swaps_axes(int $orientation): bool
{
    return in_array($orientation, [5, 6, 7, 8], true);
}

/**
 * Return decoded source dimensions used for thumbnail geometry checks.
 *
 * JPEG phone photos often store landscape pixels with a portrait EXIF
 * orientation flag. Geometry validation must use the displayed orientation,
 * otherwise valid portrait thumbnails are treated as broken and regenerated on
 * every public view.
 *
 * @param string $sourcePath Source filesystem path.
 * @param array $image Image row or image data.
 * @return array{width:int,height:int}|null Structured result data for the caller.
 */
function thumbnail_source_geometry_dimensions(string $sourcePath, array $image = []): ?array
{
    // $info stores dimensions decoded directly from the source file whenever possible.
    $info = @getimagesize($sourcePath);
    if (is_array($info) && (int) ($info[0] ?? 0) > 0 && (int) ($info[1] ?? 0) > 0) {
        // $width stores the raw pixel width reported by the image decoder.
        $width = (int) $info[0];
        // $height stores the raw pixel height reported by the image decoder.
        $height = (int) $info[1];
        // $mime stores the source MIME type used for EXIF orientation checks.
        $mime = (string) ($info['mime'] ?? '');
        // $orientation stores the JPEG display orientation when present.
        $orientation = thumbnail_jpeg_exif_orientation($sourcePath, $mime);
        if (thumbnail_orientation_swaps_axes($orientation)) {
            return ['width' => $height, 'height' => $width];
        }
        return ['width' => $width, 'height' => $height];
    }

    // Some special sources, for example imported RAW rows, may only have DB dimensions.
    $width = (int) ($image['width'] ?? 0);
    $height = (int) ($image['height'] ?? 0);
    if ($width > 0 && $height > 0) {
        return ['width' => $width, 'height' => $height];
    }

    return null;
}

/**
 * Inspect one generated thumbnail and return whether its geometry is still valid.
 *
 * @param string $thumbnailPath Thumbnail path filesystem path.
 * @param int $sourceWidth Source width value.
 * @param int $sourceHeight Source height value.
 * @param int $maxSide Max side value.
 * @return array{valid:bool,reason:string,expected_width:int,expected_height:int,actual_width:int,actual_height:int} Structured result data for the caller.
 */
function thumbnail_file_geometry_status(string $thumbnailPath, int $sourceWidth, int $sourceHeight, int $maxSide): array
{
    $expected = thumbnail_expected_dimensions($sourceWidth, $sourceHeight, $maxSide);
    $actual = @getimagesize($thumbnailPath);
    $actualWidth = is_array($actual) ? (int) ($actual[0] ?? 0) : 0;
    $actualHeight = is_array($actual) ? (int) ($actual[1] ?? 0) : 0;

    $status = [
        'valid' => false,
        'reason' => 'thumbnail_unreadable',
        'expected_width' => (int) $expected['width'],
        'expected_height' => (int) $expected['height'],
        'actual_width' => $actualWidth,
        'actual_height' => $actualHeight,
    ];

    if ($actualWidth <= 0 || $actualHeight <= 0) {
        return $status;
    }

    // $pixelTolerance allows one or two pixels of encoder rounding difference.
    $pixelTolerance = 2;
    if (abs($actualWidth - (int) $expected['width']) <= $pixelTolerance && abs($actualHeight - (int) $expected['height']) <= $pixelTolerance) {
        $status['valid'] = true;
        $status['reason'] = 'geometry_matches_expected_dimensions';
        return $status;
    }

    // $expectedRatio and $actualRatio catch old square-canvas derivatives such as 1600x1600 portrait thumbnails.
    $expectedRatio = (float) $expected['width'] / max(1, (int) $expected['height']);
    $actualRatio = $actualWidth / max(1, $actualHeight);
    if (abs($actualRatio - $expectedRatio) <= 0.015 && max($actualWidth, $actualHeight) <= max(1, $maxSide)) {
        $status['valid'] = true;
        $status['reason'] = 'geometry_matches_expected_ratio';
        return $status;
    }

    $status['reason'] = 'aspect_ratio_mismatch';
    return $status;
}

/**
 * Keep a generated thumbnail timestamp current relative to its source image.
 *
 * Some imported galleries preserve original file mtimes that can be ahead of
 * the server clock or ahead of thumbnail writes in the same request. The
 * maintenance checker uses mtime to detect stale cache files, so every
 * successful generator path must publish thumbnails with an mtime at least as
 * new as the source.
 *
 * @param string $thumbnailPath Thumbnail path filesystem path.
 * @param string $sourcePath Source filesystem path.
 */
function thumbnail_touch_generated_file_for_source(string $thumbnailPath, string $sourcePath): void
{
    if (!is_file($thumbnailPath)) {
        return;
    }

    // $sourceMtime stores the authoritative freshness boundary for this source.
    $sourceMtime = is_file($sourcePath) ? (filemtime($sourcePath) ?: 0) : 0;
    // $targetMtime stores a safe cache timestamp for generated derivatives.
    $targetMtime = max(time(), $sourceMtime);
    if ($targetMtime > 0 && filemtime($thumbnailPath) < $targetMtime) {
        @touch($thumbnailPath, $targetMtime);
    }
}

/**
 * Delete one invalid generated thumbnail after confirming it is inside the thumbnail cache.
 *
 * @param string $thumbnailPath Thumbnail path filesystem path.
 * @return bool True when the condition matches.
 */
function thumbnail_delete_invalid_geometry_file(string $thumbnailPath): bool
{
    if (!is_file($thumbnailPath)) {
        return false;
    }

    return @unlink($thumbnailPath);
}

/**
 * Handles create gallery thumbnails logic for the gallery application.
 *
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
 *
 * @return mixed Result produced by this operation.
 */
function create_all_thumbnails(): int
{
    // Variable $count stores this steps working value.
    $count = 0;
    $stmt = db()->prepare('SELECT id FROM galleries ORDER BY folder_path');
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $galleryId) {
        $count += create_gallery_thumbnails((int) $galleryId);
    }
    return $count;
}

/**
 * Handles create image thumbnails logic for the gallery application.
 *
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
 *
 * @param mixed $image Input used by this operation.
 * @param mixed $gallery Input used by this operation.
 * @param ?array $requestedSizes Requested sizes value.
 * @param array $options Optional behavior flags.
 * @return mixed Result produced by this operation.
 */
function create_image_thumbnails_result(array $image, array $gallery, ?array $requestedSizes = null, array $options = []): array
{
    thumbnail_metadata_preflight_write_schema('thumbnail_generation.create_image_thumbnails');
    // Variable $sourcePath stores this steps working value.
    $sourcePath = image_abs_path($image, $gallery);
    if (!is_file($sourcePath)) {
        if (function_exists('Gallery\\Services\\thumbnail_metadata_delete_image_variants')) {
            thumbnail_metadata_delete_image_variants($image);
        }
        return ['created' => 0, 'skipped' => 0, 'webp_skipped' => 0, 'failed' => 0, 'errors' => [], 'created_files' => [], 'target_formats' => [], 'thumbnail_policy' => null];
    }
    gallery_thumbs_dir($gallery, true);
    if (image_uses_dng_display_derivatives($image)) {
        return create_dng_image_derivatives_result($image, $gallery, $sourcePath, $requestedSizes);
    }
    // Variable $info stores this steps working value.
    $info = @getimagesize($sourcePath);
    if ($info === false || empty($info['mime'])) {
        return ['created' => 0, 'skipped' => 0, 'webp_skipped' => 0, 'failed' => 0, 'errors' => [], 'created_files' => [], 'target_formats' => [], 'thumbnail_policy' => null];
    }
    // $mime stores the source MIME value used by the scanner and generator format decision.
    $mime = (string) $info['mime'];
    // $formats stores the variants this server can actually keep current for this source.
    $formats = thumbnail_target_formats_for_source($sourcePath, $mime);
    // $preferImagickWebpExif stores whether this caller accepts the heavier Imagick WebP writer.
    $preferImagickWebpExif = array_key_exists('prefer_imagick_webp_exif', $options)
        ? (bool) $options['prefer_imagick_webp_exif']
        : true;
    // Variable $sourceMtime stores this steps working value.
    $sourceMtime = filemtime($sourcePath) ?: time();
    // $sourceGeometry stores displayed source dimensions used by validation and generation.
    $sourceGeometry = thumbnail_source_geometry_dimensions($sourcePath, $image) ?: ['width' => (int) $info[0], 'height' => (int) $info[1]];
    // $sourceWidth stores the orientation-aware source width used by all generated thumbnails.
    $sourceWidth = (int) $sourceGeometry['width'];
    // $sourceHeight stores the orientation-aware source height used by all generated thumbnails.
    $sourceHeight = (int) $sourceGeometry['height'];
    // Variable $skipped stores this steps working value.
    $skipped = 0;
    // $invalidGeometryDeleted stores cache files removed because their dimensions no longer match the source aspect ratio.
    $invalidGeometryDeleted = 0;
    // $invalidGeometryFiles stores removed cache filenames for diagnostics.
    $invalidGeometryFiles = [];
    // Variable $webpSkipped stores this steps working value.
    $webpSkipped = thumbnail_intentionally_skipped_webp_count($sourcePath, $mime);
    // $sizes stores the generated thumbnail sizes requested by this operation. Null means the full standard set.
    $sizes = $requestedSizes === null ? thumbnail_sizes() : array_values(array_unique(array_filter(array_map('intval', $requestedSizes), static fn (int $size): bool => in_array($size, thumbnail_sizes(), true))));
    // $thumbnailPolicy stores the exact generation policy for diagnostics and warmup logs.
    $thumbnailPolicy = function_exists('Gallery\\Services\\thumbnail_generation_policy_summary') ? thumbnail_generation_policy_summary($sourcePath, $mime, $sizes) : null;
    if (!$sizes) {
        return ['created' => 0, 'skipped' => $skipped, 'webp_skipped' => $webpSkipped, 'failed' => 0, 'errors' => [], 'created_files' => [], 'target_formats' => $formats, 'thumbnail_policy' => $thumbnailPolicy, 'invalid_geometry_deleted' => $invalidGeometryDeleted, 'invalid_geometry_files' => $invalidGeometryFiles];
    }
    if (!$formats) {
        return ['created' => 0, 'skipped' => $skipped, 'webp_skipped' => $webpSkipped, 'failed' => 0, 'errors' => [], 'created_files' => [], 'target_formats' => [], 'thumbnail_policy' => $thumbnailPolicy, 'invalid_geometry_deleted' => $invalidGeometryDeleted, 'invalid_geometry_files' => $invalidGeometryFiles];
    }
    // Variable $targets stores this steps working value.
    $targets = [];
    foreach ($sizes as $size) {
        foreach ($formats as $format) {
            // Variable $targetPath stores this steps working value.
            $targetPath = thumbnail_abs_path($image, $gallery, $size, $format);
            if (is_file($targetPath) && filemtime($targetPath) >= $sourceMtime) {
                // $geometryStatus stores whether a fresh cache file has the right aspect ratio.
                $geometryStatus = thumbnail_file_geometry_status($targetPath, $sourceWidth, $sourceHeight, $size);
                if (!empty($geometryStatus['valid'])) {
                    if (function_exists('Gallery\\Services\\thumbnail_metadata_record_file')) {
                        thumbnail_metadata_record_file($image, $gallery, (int) $size, $format, $targetPath, $sourcePath, false);
                    }
                    $skipped++;
                    continue;
                }
                $invalidGeometryDeleted++;
                $invalidGeometryFiles[] = basename($targetPath);
                thumbnail_delete_invalid_geometry_file($targetPath);
                if (function_exists('Gallery\\Services\\thumbnail_metadata_delete_variant')) {
                    thumbnail_metadata_delete_variant($image, (int) $size, $format);
                }
            }
            $targets[$size][$format] = $targetPath;
        }
    }
    if (!$targets) {
        return ['created' => 0, 'skipped' => $skipped, 'webp_skipped' => $webpSkipped, 'failed' => 0, 'errors' => [], 'created_files' => [], 'target_formats' => $formats, 'thumbnail_policy' => $thumbnailPolicy, 'invalid_geometry_deleted' => $invalidGeometryDeleted, 'invalid_geometry_files' => $invalidGeometryFiles];
    }
    // $targetCount stores how many files this request still needs to create.
    $targetCount = array_sum(array_map('count', $targets));
    if (!extension_loaded('gd')) {
        return ['created' => 0, 'skipped' => $skipped, 'webp_skipped' => $webpSkipped, 'failed' => $targetCount, 'errors' => ['gd_extension_missing'], 'created_files' => [], 'target_formats' => $formats, 'thumbnail_policy' => $thumbnailPolicy, 'invalid_geometry_deleted' => $invalidGeometryDeleted, 'invalid_geometry_files' => $invalidGeometryFiles];
    }
    // Variable $source stores this steps working value.
    $source = image_create_from_path($sourcePath, (string) $info['mime']);
    if (!$source) {
        return ['created' => 0, 'skipped' => $skipped, 'webp_skipped' => $webpSkipped, 'failed' => $targetCount, 'errors' => ['source_decode_failed'], 'created_files' => [], 'target_formats' => $formats, 'thumbnail_policy' => $thumbnailPolicy, 'invalid_geometry_deleted' => $invalidGeometryDeleted, 'invalid_geometry_files' => $invalidGeometryFiles];
    }
    $source = thumbnail_apply_gd_exif_orientation($sourcePath, $source, $mime);
    // $workingWidth stores the real width after any EXIF orientation transform.
    $workingWidth = imagesx($source);
    // $workingHeight stores the real height after any EXIF orientation transform.
    $workingHeight = imagesy($source);
    // Variable $created stores this steps working value.
    $created = 0;
    // $failed stores target variants that could not be written.
    $failed = 0;
    // $errors stores concise failure reasons for logs and Ajax diagnostics.
    $errors = [];
    // $createdFiles stores generated thumbnail basenames for detailed warmup logging.
    $createdFiles = [];
    foreach ($targets as $size => $formatTargets) {
        if (isset($formatTargets['jpg'])) {
            // $temporaryPath stores the new JPEG file until it can replace any stale derivative.
            $temporaryPath = thumbnail_temporary_target_path($formatTargets['jpg'], 'jpg');
            if (write_resized_jpeg($source, $workingWidth, $workingHeight, (int) $size, $temporaryPath) && thumbnail_publish_temporary_target($temporaryPath, $formatTargets['jpg'])) {
                thumbnail_touch_generated_file_for_source($formatTargets['jpg'], $sourcePath);
                if (function_exists('Gallery\\Services\\thumbnail_metadata_record_file')) {
                    thumbnail_metadata_record_file($image, $gallery, (int) $size, 'jpg', $formatTargets['jpg'], $sourcePath, true);
                }
                $created++;
                $createdFiles[] = basename($formatTargets['jpg']);
            } else {
                thumbnail_remove_partial_file($temporaryPath);
                if (function_exists('Gallery\\Services\\thumbnail_metadata_delete_variant')) {
                    thumbnail_metadata_delete_variant($image, (int) $size, 'jpg');
                }
                $failed++;
                $errors[] = 'jpg_write_failed';
            }
        }
        if (isset($formatTargets['webp'])) {
            // $temporaryPath stores the new WebP file until it can replace any stale derivative.
            $temporaryPath = thumbnail_temporary_target_path($formatTargets['webp'], 'webp');
            // $webpWritten stores an intermediate value used by the surrounding gallery workflow.
            $webpWritten = write_resized_webp_preserving_exif_when_needed($sourcePath, $source, $workingWidth, $workingHeight, (int) $size, $temporaryPath, $mime, $preferImagickWebpExif);
            if ($webpWritten && thumbnail_publish_temporary_target($temporaryPath, $formatTargets['webp'])) {
                thumbnail_touch_generated_file_for_source($formatTargets['webp'], $sourcePath);
                if (function_exists('Gallery\\Services\\thumbnail_metadata_record_file')) {
                    thumbnail_metadata_record_file($image, $gallery, (int) $size, 'webp', $formatTargets['webp'], $sourcePath, true);
                }
                $created++;
                $createdFiles[] = basename($formatTargets['webp']);
            } else {
                thumbnail_remove_partial_file($temporaryPath);
                if (function_exists('Gallery\\Services\\thumbnail_metadata_delete_variant')) {
                    thumbnail_metadata_delete_variant($image, (int) $size, 'webp');
                }
                $webpSkipped++;
                $failed++;
                $errors[] = 'webp_write_failed';
            }
        }
    }
    imagedestroy($source);
    return ['created' => $created, 'skipped' => $skipped, 'webp_skipped' => $webpSkipped, 'failed' => $failed, 'errors' => array_values(array_unique($errors)), 'created_files' => $createdFiles, 'target_formats' => $formats, 'thumbnail_policy' => $thumbnailPolicy, 'invalid_geometry_deleted' => $invalidGeometryDeleted, 'invalid_geometry_files' => $invalidGeometryFiles];
}

/**
 * Return generated thumbnail geometry status for response and maintenance callers.
 *
 * Invalid geometry is handled by the variant resolver before streaming.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param int $size Size value.
 * @param string $path Filesystem path.
 * @return array<string mixed>.
 */
function thumbnail_response_file_geometry_status(array $image, array $gallery, int $size, string $path): array
{
    if (!function_exists('Gallery\\Services\\thumbnail_source_geometry_dimensions') || !function_exists('Gallery\\Services\\thumbnail_file_geometry_status')) {
        return ['valid' => true, 'reason' => 'geometry_validation_unavailable'];
    }

    try {
        // $sourcePath stores the original file path used only for geometry validation.
        $sourcePath = image_abs_path($image, $gallery);
    } catch (Throwable) {
        return ['valid' => true, 'reason' => 'source_path_unavailable'];
    }

    if (!is_file($sourcePath)) {
        return ['valid' => true, 'reason' => 'source_missing'];
    }

    // $sourceGeometry stores source dimensions used to detect stale square-canvas thumbnails.
    $sourceGeometry = thumbnail_source_geometry_dimensions($sourcePath, $image);
    if (!is_array($sourceGeometry)) {
        return ['valid' => true, 'reason' => 'source_geometry_unknown'];
    }

    return thumbnail_file_geometry_status($path, (int) $sourceGeometry['width'], (int) $sourceGeometry['height'], $size);
}

/**
 * Return true when a generated thumbnail file has valid geometry.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param int $size Size value.
 * @param string $path Filesystem path.
 * @return bool True when the condition matches.
 */
function thumbnail_response_file_has_valid_geometry(array $image, array $gallery, int $size, string $path): bool
{
    // $status stores the reusable geometry decision for callers that still need a boolean.
    $status = thumbnail_response_file_geometry_status($image, $gallery, $size, $path);
    return !empty($status['valid']);
}

/**
 * Resolve, validate, repair, and record one concrete thumbnail response file.
 *
 * This is the single direct-view resolver used by public thumbnail streaming. The
 * public gallery page itself still selects variants from DB metadata; this helper
 * may touch files only when the browser requests one concrete thumbnail URL.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param int $size Size value.
 * @param string $format Format value.
 * @return array{path:string,geometry_status:array<string,mixed>}|null Structured result data for the caller.
 */
function thumbnail_ensure_image_thumbnail_variant_file(array $image, array $gallery, int $size, string $format): ?array
{
    if (!in_array($size, thumbnail_sizes(), true) || !in_array($format, ['jpg', 'webp'], true)) {
        return null;
    }

    try {
        // $path stores the concrete derivative requested by the public URL.
        $path = thumbnail_abs_path($image, $gallery, $size, $format);
    } catch (RuntimeException) {
        return null;
    }

    if (!is_file($path) && function_exists('Gallery\\Services\\thumbnail_metadata_delete_variant')) {
        thumbnail_metadata_delete_variant($image, $size, $format);
    }

    if (!is_file($path)) {
        create_image_thumbnails_result($image, $gallery, [$size]);
    }

    if (!is_file($path)) {
        if (function_exists('Gallery\\Services\\thumbnail_metadata_delete_variant')) {
            thumbnail_metadata_delete_variant($image, $size, $format);
        }
        return null;
    }

    // $geometryStatus stores whether the physical file matches the original image ratio.
    $geometryStatus = thumbnail_response_file_geometry_status($image, $gallery, $size, $path);
    if (empty($geometryStatus['valid'])) {
        if (function_exists('Gallery\\Services\\thumbnail_delete_invalid_geometry_file')) {
            thumbnail_delete_invalid_geometry_file($path);
        } elseif (is_file($path)) {
            @unlink($path);
        }
        if (function_exists('Gallery\\Services\\thumbnail_metadata_delete_variant')) {
            thumbnail_metadata_delete_variant($image, $size, $format);
        }

        create_image_thumbnails_result($image, $gallery, [$size]);

        if (!is_file($path)) {
            return null;
        }

        $geometryStatus = thumbnail_response_file_geometry_status($image, $gallery, $size, $path);
        if (empty($geometryStatus['valid'])) {
            if (function_exists('Gallery\\Services\\thumbnail_delete_invalid_geometry_file')) {
                thumbnail_delete_invalid_geometry_file($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
            if (function_exists('Gallery\\Services\\thumbnail_metadata_delete_variant')) {
                thumbnail_metadata_delete_variant($image, $size, $format);
            }
            return null;
        }
    }

    $metadataCurrent = function_exists('Gallery\\Services\\thumbnail_metadata_has_renderable_variant')
        && thumbnail_metadata_has_renderable_variant($image, $size, $format);
    if (!$metadataCurrent && function_exists('Gallery\\Services\\thumbnail_metadata_record_file')) {
        try {
            thumbnail_metadata_record_file($image, $gallery, $size, $format, $path, image_abs_path($image, $gallery), false);
        } catch (Throwable) {
            // Metadata refresh must not break streaming of a valid derivative.
        }
    }

    return ['path' => $path, 'geometry_status' => $geometryStatus];
}

/**
 * Handles image ids for galleries logic for the gallery application.
 *
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
 *
 * @return mixed Result produced by this operation.
 */
function all_image_ids(): array
{
    // Variable $rows stores this steps working value.
    $rows = db()->query("SELECT i.id FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE i.relative_path NOT LIKE '%/%' ORDER BY g.folder_path, i.sort_order, i.filename")->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $rows);
}

/**
 * Apply JPEG EXIF orientation to a GD image resource.
 *
 * The generated thumbnail pixels should match the orientation browsers display
 * for the original file. Without this, warmup can keep regenerating apparently
 * invalid portrait thumbnails from phone images.
 *
 * @param string $sourcePath Source filesystem path.
 * @param GdImage $source Source value.
 * @param string $mime Mime value.
 * @return GdImage Result value for the caller.
 */
function thumbnail_apply_gd_exif_orientation(string $sourcePath, GdImage $source, string $mime): GdImage
{
    if ($mime !== 'image/jpeg') {
        return $source;
    }

    // $orientation stores the display transform requested by the source JPEG.
    $orientation = thumbnail_jpeg_exif_orientation($sourcePath, $mime);
    if ($orientation === 1) {
        return $source;
    }

    // $oriented stores the rotated or flipped image when GD can perform the transform.
    $oriented = null;
    switch ($orientation) {
        case 2:
            if (function_exists('imageflip') && imageflip($source, IMG_FLIP_HORIZONTAL)) {
                return $source;
            }
            break;
        case 3:
            $oriented = imagerotate($source, 180, 0);
            break;
        case 4:
            if (function_exists('imageflip') && imageflip($source, IMG_FLIP_VERTICAL)) {
                return $source;
            }
            break;
        case 5:
            if (function_exists('imageflip')) {
                imageflip($source, IMG_FLIP_HORIZONTAL);
            }
            $oriented = imagerotate($source, 270, 0);
            break;
        case 6:
            $oriented = imagerotate($source, 270, 0);
            break;
        case 7:
            if (function_exists('imageflip')) {
                imageflip($source, IMG_FLIP_HORIZONTAL);
            }
            $oriented = imagerotate($source, 90, 0);
            break;
        case 8:
            $oriented = imagerotate($source, 90, 0);
            break;
    }

    if ($oriented instanceof GdImage) {
        imagedestroy($source);
        return $oriented;
    }

    return $source;
}

/**
 * Build a temporary derivative path next to the final thumbnail file.
 *
 * @param string $targetPath Target filesystem path.
 * @param string $format Format value.
 * @return string Text result for the caller.
 */
function thumbnail_temporary_target_path(string $targetPath, string $format): string
{
    // $suffix keeps the image extension visible for encoders that inspect filenames.
    $suffix = $format === 'webp' ? '.tmp.webp' : '.tmp.jpg';
    return $targetPath . '.' . bin2hex(random_bytes(6)) . $suffix;
}

/**
 * Atomically publish a temporary derivative file where possible.
 *
 * @param string $temporaryPath Temporary path filesystem path.
 * @param string $targetPath Target filesystem path.
 * @return bool True when the condition matches.
 */
function thumbnail_publish_temporary_target(string $temporaryPath, string $targetPath): bool
{
    if (!is_file($temporaryPath)) {
        return false;
    }

    if (@rename($temporaryPath, $targetPath)) {
        return true;
    }

    if (is_file($targetPath) && !@unlink($targetPath)) {
        @unlink($temporaryPath);
        return false;
    }

    if (@rename($temporaryPath, $targetPath)) {
        return true;
    }

    @unlink($temporaryPath);
    return false;
}

/**
 * Handles image create from path logic for the gallery application.
 *
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
 *
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
    $written = imagejpeg($target, $targetPath, thumbnail_jpeg_quality());
    imagedestroy($target);
    return $written;
}

/**
 * Handles image source has exif logic for the gallery application.
 *
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
 *
 * @param mixed $sourcePath Input used by this operation.
 * @param mixed $source Input used by this operation.
 * @param mixed $width Input used by this operation.
 * @param mixed $height Input used by this operation.
 * @param mixed $maxSide Input used by this operation.
 * @param mixed $targetPath Input used by this operation.
 * @param mixed $mime Input used by this operation.
 * @param bool $preferImagickExif Prefer imagick exif value.
 * @return mixed Result produced by this operation.
 */
function write_resized_webp_preserving_exif_when_needed(string $sourcePath, GdImage $source, int $width, int $height, int $maxSide, string $targetPath, string $mime, bool $preferImagickExif = true): bool
{
    if (!function_exists('imagewebp')) {
        return false;
    }
    if ($preferImagickExif && image_source_has_exif($sourcePath, $mime) && thumbnail_imagick_webp_available()) {
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
 *
 * @param string $targetPath Target filesystem path.
 */
function thumbnail_remove_partial_file(string $targetPath): void
{
    if (is_file($targetPath)) {
        @unlink($targetPath);
    }
}

/**
 * Handles write resized webp with gd logic for the gallery application.
 *
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
    $written = imagewebp($target, $targetPath, thumbnail_webp_quality());
    imagedestroy($target);
    return $written;
}

/**
 * Handles write resized webp with imagick exif logic for the gallery application.
 *
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
        if (method_exists($image, 'autoOrient')) {
            $image->autoOrient();
        } elseif (method_exists($image, 'autoOrientImage')) {
            $image->autoOrientImage();
        }
        if (defined('Imagick::ORIENTATION_TOPLEFT')) {
            $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
        }
        $image->setImagePage(0, 0, 0, 0);
        $image->thumbnailImage($maxSide, $maxSide, true, false);
        $image->setImagePage(0, 0, 0, 0);
        $image->setImageFormat('webp');
        $image->setImageCompressionQuality(thumbnail_webp_quality());
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
