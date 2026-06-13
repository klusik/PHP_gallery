<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/image_scanning.php
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

namespace Gallery\Services;

use DirectoryIterator;
use PDO;
use Throwable;
use function Gallery\Core\db;
use function Gallery\Core\is_dng_image_path;
use function Gallery\Core\is_supported_image_path;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;

/**
 * Image scanning model.
 * 
 * This module discovers image files on disk and reconciles them into database rows. It does not render public pages and does not modify theme or visual settings.
 */

/**
 * Return a JPEG EXIF orientation value for scanner-owned source metadata.
 *
 * @param string $path Filesystem path.
 * @param string $mime MIME value.
 * @return int Integer result for the caller.
 */
function scan_image_exif_orientation(string $path, string $mime): int
{
    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data') || !is_file($path)) {
        return 1;
    }

    try {
        // $exif stores only the EXIF section needed for display orientation.
        $exif = @exif_read_data($path, 'IFD0', true, false);
    } catch (Throwable) {
        return 1;
    }

    if (!is_array($exif)) {
        return 1;
    }

    $orientation = (int) ($exif['IFD0']['Orientation'] ?? $exif['Orientation'] ?? 1);
    return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
}

/**
 * Return true when EXIF orientation swaps the displayed image axes.
 *
 * @param int $orientation Orientation value.
 * @return bool True when the orientation swaps width and height.
 */
function scan_image_orientation_swaps_axes(int $orientation): bool
{
    return in_array($orientation, [5, 6, 7, 8], true);
}

/**
 * Persist master display geometry used by compact thumbnail metadata.
 *
 * @param int $imageId Image identifier.
 * @param array $metadata Metadata returned by scan_image_file_metadata().
 */
function scan_image_sync_master_display_metadata(int $imageId, array $metadata): void
{
    if ($imageId <= 0 || !function_exists('Gallery\\Services\\db_column_exists') || !db_column_exists('images', 'display_width')) {
        return;
    }

    $fields = [
        'display_width' => (int) ($metadata['display_width'] ?? $metadata['width'] ?? 0),
        'display_height' => (int) ($metadata['display_height'] ?? $metadata['height'] ?? 0),
        'exif_orientation' => (int) ($metadata['exif_orientation'] ?? 1),
        'thumbnail_metadata_refreshed_at' => now_sql(),
    ];

    $assignments = [];
    $params = [];
    foreach ($fields as $column => $value) {
        if (!db_column_exists('images', $column)) {
            continue;
        }
        $assignments[] = '`' . $column . '` = ?';
        $params[] = $value;
    }
    if (!$assignments) {
        return;
    }

    $params[] = $imageId;
    db()->prepare('UPDATE images SET ' . implode(', ', $assignments) . ' WHERE id = ?')->execute($params);
}

/**
 * Mark compact thumbnail metadata stale after a source file changed.
 *
 * @param int $imageId Image identifier.
 */
function scan_image_invalidate_thumbnail_derivatives(int $imageId): void
{
    if ($imageId <= 0) {
        return;
    }

    if (function_exists('Gallery\\Services\\db_column_exists') && db_column_exists('images', 'thumbnail_derivative_version')) {
        db()->prepare('UPDATE images SET thumbnail_derivative_version = thumbnail_derivative_version + 1 WHERE id = ?')->execute([$imageId]);
    }
    if (function_exists('Gallery\\Services\\thumbnail_metadata_delete_image_variants')) {
        thumbnail_metadata_delete_image_variants($imageId);
    } elseif (function_exists('Gallery\\Services\\db_table_exists') && db_table_exists('image_thumbnail_variants')) {
        db()->prepare('DELETE FROM image_thumbnail_variants WHERE image_id = ?')->execute([$imageId]);
    }
}


/**
 * Read scan-safe metadata for one supported image file.
 *
 * DNG originals are accepted as source uploads, but PHP getimagesize() is not a
 * reliable DNG decoder. The scanner records DNG dimensions through Imagick and
 * stores an explicit DNG MIME value while leaving the original file untouched.
 *
 * @param string $path Filesystem path.
 * @param string $filename Filename value.
 * @return array{width:int,height:int,mime:string,display_width:int,display_height:int,exif_orientation:int}|null Structured result data for the caller.
 */
function scan_image_file_metadata(string $path, string $filename): ?array
{
    if (is_dng_image_path($filename)) {
        // $metadata stores dimensions reported by the configured RAW decoder when available.
        $metadata = function_exists('Gallery\\Services\\dng_image_metadata') ? dng_image_metadata($path) : null;
        if (is_array($metadata)) {
            $metadata['display_width'] = (int) ($metadata['display_width'] ?? $metadata['width'] ?? 0);
            $metadata['display_height'] = (int) ($metadata['display_height'] ?? $metadata['height'] ?? 0);
            $metadata['exif_orientation'] = (int) ($metadata['exif_orientation'] ?? 1);
            return $metadata;
        }

        // Some hosting ImageMagick builds report DNG support but cannot reliably
        // ping every camera model. Keep the original import visible in admin and
        // let thumbnail generation report the concrete conversion failure.
        if (function_exists('admin_log_event')) {
            admin_log_event('warning', 'image_scan.dng_metadata_unreadable', 'A DNG file was imported with fallback metadata because the server could not read its dimensions.', [
                'filename' => $filename,
                'path' => $path,
            ]);
        }

        return [
            'width' => 1,
            'height' => 1,
            'mime' => 'image/x-adobe-dng',
            'display_width' => 1,
            'display_height' => 1,
            'exif_orientation' => 1,
        ];
    }

    // $info stores metadata returned by PHP for browser-displayable images.
    $info = @getimagesize($path);
    if ($info === false || empty($info['mime']) || !str_starts_with((string) $info['mime'], 'image/')) {
        return null;
    }

    $width = (int) $info[0];
    $height = (int) $info[1];
    $mime = (string) $info['mime'];
    $orientation = scan_image_exif_orientation($path, $mime);

    return [
        'width' => $width,
        'height' => $height,
        'mime' => $mime,
        'display_width' => scan_image_orientation_swaps_axes($orientation) ? $height : $width,
        'display_height' => scan_image_orientation_swaps_axes($orientation) ? $width : $height,
        'exif_orientation' => $orientation,
    ];
}

/**
 * Handle scan gallery images.
 *
 * Part of the related application service.
 *
 * @param int $galleryId Gallery identifier.
 * @return int Integer result for the caller.
 */
function scan_gallery_images(int $galleryId): int
{
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        return 0;
    }
    // Variable $root stores this steps working value.
    $root = gallery_abs_path((string) $gallery['folder_path']);
    if (!is_dir($root)) {
        return 0;
    }

    // Variable $pdo stores this steps working value.
    $pdo = db();
    // Variable $count stores this steps working value.
    $count = 0;
    // Variable $exifSchemaReady stores this steps working value.
    $exifSchemaReady = exif_gps_schema_ready();
    // Variable $nextSortOrder stores the append position for newly discovered images.
    // Existing images keep their current order; new files are placed after the
    // current gallery tail so rescans do not unexpectedly move them above a
    // manually arranged drag-and-drop order.
    $nextSortOrder = next_gallery_image_sort_order($galleryId);
    foreach (new DirectoryIterator($root) as $file) {
        if (!$file->isFile() || !is_supported_image_path($file->getFilename())) {
            continue;
        }
        // Variable $relative stores this steps working value.
        $relative = normalize_relative_path(substr($file->getPathname(), strlen($root)));
        // Variable $info stores this steps working value.
        $info = scan_image_file_metadata($file->getPathname(), $file->getFilename());
        if ($info === null) {
            continue;
        }
        // Variable $modifiedAt stores this steps working value.
        $modifiedAt = date('Y-m-d H:i:s', $file->getMTime());
        // Variable $exifMetadata stores this steps working value.
        $exifMetadata = $exifSchemaReady ? extract_image_exif_metadata($file->getPathname()) : [];
        // Variable $existing stores this steps working value.
        $existing = find_image_by_path($galleryId, $relative);
        if (!$existing) {
            if ($exifSchemaReady) {
                // Variable $stmt stores this steps working value.
                $stmt = $pdo->prepare('INSERT INTO images (gallery_id, relative_path, relative_path_hash, filename, title, width, height, mime_type, file_size, modified_at, exif_taken_at, exif_camera_make, exif_camera_model, exif_lens_model, exif_focal_length, exif_aperture, exif_exposure_time, exif_iso, gps_lat, gps_lng, gps_altitude, gps_extracted_at, checksum_sha256, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $galleryId,
                    $relative,
                    hash('sha256', $relative),
                    $file->getFilename(),
                    pathinfo($file->getFilename(), PATHINFO_FILENAME),
                    (int) $info['width'],
                    (int) $info['height'],
                    (string) $info['mime'],
                    $file->getSize(),
                    $modifiedAt,
                    $exifMetadata['exif_taken_at'] ?? null,
                    $exifMetadata['exif_camera_make'] ?? null,
                    $exifMetadata['exif_camera_model'] ?? null,
                    $exifMetadata['exif_lens_model'] ?? null,
                    $exifMetadata['exif_focal_length'] ?? null,
                    $exifMetadata['exif_aperture'] ?? null,
                    $exifMetadata['exif_exposure_time'] ?? null,
                    $exifMetadata['exif_iso'] ?? null,
                    $exifMetadata['gps_lat'] ?? null,
                    $exifMetadata['gps_lng'] ?? null,
                    $exifMetadata['gps_altitude'] ?? null,
                    $exifMetadata['gps_extracted_at'] ?? null,
                    hash_file('sha256', $file->getPathname()) ?: null,
                    $nextSortOrder,
                    now_sql(),
                    now_sql(),
                ]);
                scan_image_sync_master_display_metadata((int) $pdo->lastInsertId(), $info);
                // $nextSortOrder advances in visible ordering increments for any later new image in this scan.
                $nextSortOrder += 10;
            } else {
                // Variable $stmt stores this steps working value.
                $stmt = $pdo->prepare('INSERT INTO images (gallery_id, relative_path, relative_path_hash, filename, title, width, height, mime_type, file_size, modified_at, checksum_sha256, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $galleryId,
                    $relative,
                    hash('sha256', $relative),
                    $file->getFilename(),
                    pathinfo($file->getFilename(), PATHINFO_FILENAME),
                    (int) $info['width'],
                    (int) $info['height'],
                    (string) $info['mime'],
                    $file->getSize(),
                    $modifiedAt,
                    hash_file('sha256', $file->getPathname()) ?: null,
                    $nextSortOrder,
                    now_sql(),
                    now_sql(),
                ]);
                scan_image_sync_master_display_metadata((int) $pdo->lastInsertId(), $info);
                // $nextSortOrder advances in visible ordering increments for any later new image in this scan.
                $nextSortOrder += 10;
            }
            $count++;
            continue;
        }
        $sourceChanged = (int) $existing['file_size'] !== $file->getSize() || (string) $existing['modified_at'] !== $modifiedAt;
        if ($sourceChanged || ($exifSchemaReady && ($existing['gps_extracted_at'] ?? null) === null)) {
            if ($exifSchemaReady) {
                // Variable $stmt stores this steps working value.
                $stmt = $pdo->prepare('UPDATE images SET filename = ?, width = ?, height = ?, mime_type = ?, file_size = ?, modified_at = ?, exif_taken_at = ?, exif_camera_make = ?, exif_camera_model = ?, exif_lens_model = ?, exif_focal_length = ?, exif_aperture = ?, exif_exposure_time = ?, exif_iso = ?, gps_lat = ?, gps_lng = ?, gps_altitude = ?, gps_extracted_at = ?, checksum_sha256 = ?, updated_at = ? WHERE id = ?');
                $stmt->execute([
                    $file->getFilename(),
                    (int) $info['width'],
                    (int) $info['height'],
                    (string) $info['mime'],
                    $file->getSize(),
                    $modifiedAt,
                    $exifMetadata['exif_taken_at'] ?? null,
                    $exifMetadata['exif_camera_make'] ?? null,
                    $exifMetadata['exif_camera_model'] ?? null,
                    $exifMetadata['exif_lens_model'] ?? null,
                    $exifMetadata['exif_focal_length'] ?? null,
                    $exifMetadata['exif_aperture'] ?? null,
                    $exifMetadata['exif_exposure_time'] ?? null,
                    $exifMetadata['exif_iso'] ?? null,
                    $exifMetadata['gps_lat'] ?? null,
                    $exifMetadata['gps_lng'] ?? null,
                    $exifMetadata['gps_altitude'] ?? null,
                    $exifMetadata['gps_extracted_at'] ?? null,
                    hash_file('sha256', $file->getPathname()) ?: null,
                    now_sql(),
                    (int) $existing['id'],
                ]);
            } else {
                // Variable $stmt stores this steps working value.
                $stmt = $pdo->prepare('UPDATE images SET filename = ?, width = ?, height = ?, mime_type = ?, file_size = ?, modified_at = ?, checksum_sha256 = ?, updated_at = ? WHERE id = ?');
                $stmt->execute([
                    $file->getFilename(),
                    (int) $info['width'],
                    (int) $info['height'],
                    (string) $info['mime'],
                    $file->getSize(),
                    $modifiedAt,
                    hash_file('sha256', $file->getPathname()) ?: null,
                    now_sql(),
                    (int) $existing['id'],
                ]);
            }
            scan_image_sync_master_display_metadata((int) $existing['id'], $info);
            if ($sourceChanged) {
                scan_image_invalidate_thumbnail_derivatives((int) $existing['id']);
            }
            $count++;
        }
    }
    apply_gallery_cover_from_sidecar($gallery);
    ensure_gallery_cover((int) $gallery['id']);
    if ($count > 0 && public_path_schema_ready()) {
        regenerate_public_paths();
    }
    return $count;
}

/**
 * Handles scan all imported gallery images logic for the gallery application.
 * @return mixed Result produced by this operation.
 */

/**
 * Calculates the next image sort_order value for a gallery.
 *
 * The admin reorder UI stores direct gallery images at 10-point intervals. This
 * helper uses the same spacing when the scanner imports newly discovered files,
 * which keeps new images appended after the current manual order instead of
 * falling back to the schema default of zero.
 *
 * @param int $galleryId Gallery id whose image tail should be inspected.
 * @return int Next sort_order value suitable for a newly inserted image row.
 */
function next_gallery_image_sort_order(int $galleryId): int
{
    // Variable $stmt stores the query used to inspect the current largest sort_order value.
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM images WHERE gallery_id = ?');
    $stmt->execute([$galleryId]);
    // Variable $maxSortOrder stores the current tail of the gallery image order.
    $maxSortOrder = (int) $stmt->fetchColumn();
    return $maxSortOrder + 10;
}

/**
 * Handle scan all imported gallery images.
 *
 * Part of the related application service.
 *
 * @return array Structured result data for the caller.
 */
function scan_all_imported_gallery_images(): array
{
    // $scanned stores an intermediate value used by the surrounding gallery workflow.
    $scanned = 0;
    // $changed stores an intermediate value used by the surrounding gallery workflow.
    $changed = 0;
    // $galleryIds stores an intermediate value used by the surrounding gallery workflow.
    $galleryIds = db()->query('SELECT id FROM galleries ORDER BY folder_path')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($galleryIds as $galleryId) {
        // $current stores an intermediate value used by the surrounding gallery workflow.
        $current = scan_gallery_images((int) $galleryId);
        $scanned++;
        $changed += $current;
    }
    return ['galleries' => $scanned, 'images' => $changed];
}

