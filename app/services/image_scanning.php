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
 *   2026-06-14
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
        if (function_exists('Gallery\\Services\\admin_log_event')) {
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
 * Return lightweight source metadata for upload-time reconciliation.
 *
 * Browser-prepared uploads already decode the source image before sending it to
 * PHP, so the server can use those dimensions instead of repeating expensive
 * image parsing. The fallback still reads basic dimensions for the default
 * upload path, but it deliberately skips EXIF/GPS extraction and full-file
 * checksums.
 *
 * @param string $path Filesystem path.
 * @param string $filename Filename value.
 * @param ?array $metadata Browser-provided source metadata.
 * @return array{width:int,height:int,mime:string,display_width:int,display_height:int,exif_orientation:int}|null Structured result data for the caller.
 */
function scan_image_upload_fast_metadata(string $path, string $filename, ?array $metadata = null): ?array
{
    if (is_array($metadata)) {
        // $width and $height store orientation-aware dimensions decoded by the browser worker.
        $width = (int) ($metadata['display_width'] ?? $metadata['width'] ?? $metadata['original_width'] ?? 0);
        $height = (int) ($metadata['display_height'] ?? $metadata['height'] ?? $metadata['original_height'] ?? 0);
        $displayWidth = (int) ($metadata['display_width'] ?? $width);
        $displayHeight = (int) ($metadata['display_height'] ?? $height);
        $orientation = scan_image_upload_exif_orientation($metadata);
        $mime = strtolower(trim((string) ($metadata['mime'] ?? $metadata['mime_type'] ?? $metadata['original_mime'] ?? '')));
        if ($mime === '') {
            $mime = scan_image_upload_mime_from_extension($filename);
        }
        if ($width > 0 && $height > 0 && str_starts_with($mime, 'image/')) {
            return [
                'width' => $width,
                'height' => $height,
                'mime' => $mime,
                'display_width' => $displayWidth > 0 ? $displayWidth : $width,
                'display_height' => $displayHeight > 0 ? $displayHeight : $height,
                'exif_orientation' => $orientation,
            ];
        }
    }

    if (is_dng_image_path($filename)) {
        return scan_image_file_metadata($path, $filename);
    }

    // $info stores basic image dimensions without reading EXIF/GPS payloads.
    $info = @getimagesize($path);
    if ($info === false || empty($info['mime']) || !str_starts_with((string) $info['mime'], 'image/')) {
        return null;
    }

    $width = (int) $info[0];
    $height = (int) $info[1];
    $mime = (string) $info['mime'];
    return [
        'width' => $width,
        'height' => $height,
        'mime' => $mime,
        'display_width' => $width,
        'display_height' => $height,
        'exif_orientation' => 1,
    ];
}

/**
 * Normalize client-provided EXIF orientation for upload-time metadata.
 *
 * @param array $metadata Browser-provided source metadata.
 * @return int EXIF orientation from 1 to 8.
 */
function scan_image_upload_exif_orientation(array $metadata): int
{
    $exif = is_array($metadata['exif'] ?? null) ? $metadata['exif'] : [];
    $orientation = (int) ($metadata['exif_orientation'] ?? $metadata['original_exif_orientation'] ?? $exif['exif_orientation'] ?? 1);
    return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
}

/**
 * Normalize client-provided EXIF and GPS metadata for fast upload scans.
 *
 * The browser upload worker parses compact JPEG EXIF metadata in the
 * browser. This helper accepts only fields that map directly to the images table
 * and keeps server-side EXIF parsing out of the upload request.
 *
 * @param ?array $metadata Browser-provided source metadata.
 * @return array<string, mixed> Safe metadata for database writes.
 */
function scan_image_upload_exif_metadata(?array $metadata): array
{
    if (!is_array($metadata)) {
        return [];
    }

    $source = is_array($metadata['exif'] ?? null) ? $metadata['exif'] : $metadata;
    $result = [
        'exif_taken_at' => scan_image_upload_exif_datetime($source['exif_taken_at'] ?? null),
        'exif_camera_make' => scan_image_upload_exif_string($source['exif_camera_make'] ?? null, 128),
        'exif_camera_model' => scan_image_upload_exif_string($source['exif_camera_model'] ?? null, 128),
        'exif_lens_model' => scan_image_upload_exif_string($source['exif_lens_model'] ?? null, 128),
        'exif_focal_length' => scan_image_upload_exif_string($source['exif_focal_length'] ?? null, 64),
        'exif_aperture' => scan_image_upload_exif_string($source['exif_aperture'] ?? null, 64),
        'exif_exposure_time' => scan_image_upload_exif_string($source['exif_exposure_time'] ?? null, 64),
        'exif_iso' => scan_image_upload_exif_iso($source['exif_iso'] ?? null),
        'gps_lat' => scan_image_upload_exif_float($source['gps_lat'] ?? null, -90.0, 90.0, 7),
        'gps_lng' => scan_image_upload_exif_float($source['gps_lng'] ?? null, -180.0, 180.0, 7),
        'gps_altitude' => scan_image_upload_exif_float($source['gps_altitude'] ?? null, -11000.0, 100000.0, 2),
        'gps_extracted_at' => scan_image_upload_exif_datetime($source['gps_extracted_at'] ?? null),
    ];
    if ($result['gps_lat'] !== null && $result['gps_lng'] !== null && $result['gps_extracted_at'] === null) {
        $result['gps_extracted_at'] = now_sql();
    }
    return array_filter($result, static fn ($value): bool => $value !== null && $value !== '');
}

/**
 * Return whether a normalized EXIF metadata array contains useful values.
 *
 * @param array $metadata Normalized EXIF metadata.
 * @return bool True when at least one supported field is available.
 */
function scan_image_upload_exif_metadata_has_values(array $metadata): bool
{
    foreach ($metadata as $value) {
        if ($value !== null && $value !== '') {
            return true;
        }
    }
    return false;
}

/**
 * Normalize an EXIF datetime supplied by the browser worker.
 *
 * @param mixed $value Raw datetime value.
 * @return string|null SQL datetime or null.
 */
function scan_image_upload_exif_datetime(mixed $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/', $value, $match) !== 1) {
        return null;
    }
    return $match[1] . '-' . $match[2] . '-' . $match[3] . ' ' . $match[4] . ':' . $match[5] . ':' . $match[6];
}

/**
 * Normalize a short EXIF string for direct database storage.
 *
 * @param mixed $value Raw string value.
 * @param int $maxLength Maximum stored length.
 * @return string|null Clean string or null.
 */
function scan_image_upload_exif_string(mixed $value, int $maxLength): ?string
{
    $value = trim(str_replace("\0", '', (string) $value));
    if ($value === '') {
        return null;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }
    return substr($value, 0, $maxLength);
}

/**
 * Normalize an EXIF ISO value.
 *
 * @param mixed $value Raw ISO value.
 * @return int|null ISO value or null.
 */
function scan_image_upload_exif_iso(mixed $value): ?int
{
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    $iso = (int) round((float) $value);
    return $iso > 0 && $iso <= 1000000 ? $iso : null;
}

/**
 * Normalize a bounded EXIF float value.
 *
 * @param mixed $value Raw numeric value.
 * @param float $min Minimum accepted value.
 * @param float $max Maximum accepted value.
 * @param int $decimals Decimal precision.
 * @return float|null Normalized value or null.
 */
function scan_image_upload_exif_float(mixed $value, float $min, float $max, int $decimals): ?float
{
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    $number = (float) $value;
    if ($number < $min || $number > $max) {
        return null;
    }
    return round($number, $decimals);
}

/**
 * Infer a safe image MIME value from an upload filename extension.
 *
 * @param string $filename Filename value.
 * @return string MIME value or an empty string when unknown.
 */
function scan_image_upload_mime_from_extension(string $filename): string
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return match ($extension) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        default => '',
    };
}

/**
 * Fetch a scanner-owned image row without using request-local lookup caches.
 *
 * The upload workflow can create files and then scan them in the same request,
 * so scanner reconciliation must not reuse stale negative lookups from filename
 * availability checks that ran before the file was written.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $relativePath Normalized relative image path.
 * @return ?array Structured image row for the scanner, or null when absent.
 */
function scan_gallery_image_row_by_path(int $galleryId, string $relativePath): ?array
{
    // $normalizedPath stores the canonical path used by the images table hash.
    $normalizedPath = normalize_relative_path($relativePath);
    if ($normalizedPath === '') {
        return null;
    }

    // $stmt performs an uncached lookup so newly written files are reconciled accurately.
    $stmt = db()->prepare('SELECT * FROM images WHERE gallery_id = ? AND relative_path_hash = ? LIMIT 1');
    $stmt->execute([$galleryId, hash('sha256', $normalizedPath)]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Reconcile one supported image file into the images table.
 *
 * @param array $gallery Gallery row used to resolve ownership and filesystem context.
 * @param string $root Absolute gallery root path.
 * @param string $filePath Absolute image file path.
 * @param string $filename Basename used for display and file type checks.
 * @param PDO $pdo Database connection used for insert or update operations.
 * @param bool $exifSchemaReady Whether EXIF and GPS columns are available.
 * @param int $nextSortOrder Next append sort order for newly inserted images.
 * @param array $options Scanner options; upload_fast_path skips expensive metadata reads.
 * @return int Number of changed image rows.
 */
function scan_gallery_image_file_entry(array $gallery, string $root, string $filePath, string $filename, PDO $pdo, bool $exifSchemaReady, int &$nextSortOrder, array $options = []): int
{
    $galleryId = (int) ($gallery['id'] ?? 0);
    if ($galleryId <= 0 || !is_file($filePath) || !is_supported_image_path($filename)) {
        return 0;
    }

    // $relative stores the canonical path used by the gallery database row.
    $relative = normalize_relative_path(substr($filePath, strlen($root)));
    if ($relative === '') {
        return 0;
    }

    // $fastUploadPath stores whether this reconciliation should avoid CPU-heavy metadata reads.
    $fastUploadPath = !empty($options['upload_fast_path']);
    // $providedMetadata stores optional browser-decoded source dimensions.
    $providedMetadata = is_array($options['metadata'] ?? null) ? $options['metadata'] : null;
    // $info stores source dimensions, MIME information, and display orientation.
    $info = $fastUploadPath
        ? scan_image_upload_fast_metadata($filePath, $filename, $providedMetadata)
        : scan_image_file_metadata($filePath, $filename);
    if ($info === null) {
        return 0;
    }

    // $modifiedAt stores the filesystem timestamp in database format.
    $modifiedAt = date('Y-m-d H:i:s', (int) (filemtime($filePath) ?: time()));
    // $existing stores any prior row for this exact gallery-relative path.
    $existing = scan_gallery_image_row_by_path($galleryId, $relative);
    // $exifMetadata stores optional camera and GPS metadata when the schema supports it.
    $exifMetadata = [];
    if ($exifSchemaReady) {
        $exifMetadata = $fastUploadPath
            ? scan_image_upload_exif_metadata($providedMetadata)
            : extract_image_exif_metadata($filePath);
    }
    // $hasProvidedExif stores whether the fast upload path can fill EXIF/GPS columns without server parsing.
    $hasProvidedExif = $fastUploadPath && $exifSchemaReady && scan_image_upload_exif_metadata_has_values($exifMetadata);
    // $checksum stores the source hash when the caller requested a full scanner pass.
    $checksum = $fastUploadPath ? ($existing['checksum_sha256'] ?? null) : (hash_file('sha256', $filePath) ?: null);
    if (!$existing) {
        if ($exifSchemaReady) {
            // $stmt inserts a full metadata row including optional EXIF and GPS columns.
            $stmt = $pdo->prepare('INSERT INTO images (gallery_id, relative_path, relative_path_hash, filename, title, width, height, mime_type, file_size, modified_at, exif_taken_at, exif_camera_make, exif_camera_model, exif_lens_model, exif_focal_length, exif_aperture, exif_exposure_time, exif_iso, gps_lat, gps_lng, gps_altitude, gps_extracted_at, checksum_sha256, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $galleryId,
                $relative,
                hash('sha256', $relative),
                $filename,
                pathinfo($filename, PATHINFO_FILENAME),
                (int) $info['width'],
                (int) $info['height'],
                (string) $info['mime'],
                (int) (filesize($filePath) ?: 0),
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
                $checksum,
                $nextSortOrder,
                now_sql(),
                now_sql(),
            ]);
            scan_image_sync_master_display_metadata((int) $pdo->lastInsertId(), $info);
            $nextSortOrder += 10;
        } else {
            // $stmt inserts the compact image metadata row when EXIF columns are unavailable.
            $stmt = $pdo->prepare('INSERT INTO images (gallery_id, relative_path, relative_path_hash, filename, title, width, height, mime_type, file_size, modified_at, checksum_sha256, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $galleryId,
                $relative,
                hash('sha256', $relative),
                $filename,
                pathinfo($filename, PATHINFO_FILENAME),
                (int) $info['width'],
                (int) $info['height'],
                (string) $info['mime'],
                (int) (filesize($filePath) ?: 0),
                $modifiedAt,
                $checksum,
                $nextSortOrder,
                now_sql(),
                now_sql(),
            ]);
            scan_image_sync_master_display_metadata((int) $pdo->lastInsertId(), $info);
            $nextSortOrder += 10;
        }
        return 1;
    }

    $fileSize = (int) (filesize($filePath) ?: 0);
    $sourceChanged = (int) $existing['file_size'] !== $fileSize || (string) $existing['modified_at'] !== $modifiedAt;
    if (!$sourceChanged && (($fastUploadPath && !$hasProvidedExif) || !$exifSchemaReady || (($existing['gps_extracted_at'] ?? null) !== null && !$hasProvidedExif))) {
        return 0;
    }

    if ($exifSchemaReady) {
        // $stmt updates source metadata and optional EXIF columns for an existing image row.
        $stmt = $pdo->prepare('UPDATE images SET filename = ?, width = ?, height = ?, mime_type = ?, file_size = ?, modified_at = ?, exif_taken_at = ?, exif_camera_make = ?, exif_camera_model = ?, exif_lens_model = ?, exif_focal_length = ?, exif_aperture = ?, exif_exposure_time = ?, exif_iso = ?, gps_lat = ?, gps_lng = ?, gps_altitude = ?, gps_extracted_at = ?, checksum_sha256 = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([
            $filename,
            (int) $info['width'],
            (int) $info['height'],
            (string) $info['mime'],
            $fileSize,
            $modifiedAt,
            $fastUploadPath ? ($exifMetadata['exif_taken_at'] ?? $existing['exif_taken_at'] ?? null) : ($exifMetadata['exif_taken_at'] ?? null),
            $fastUploadPath ? ($exifMetadata['exif_camera_make'] ?? $existing['exif_camera_make'] ?? null) : ($exifMetadata['exif_camera_make'] ?? null),
            $fastUploadPath ? ($exifMetadata['exif_camera_model'] ?? $existing['exif_camera_model'] ?? null) : ($exifMetadata['exif_camera_model'] ?? null),
            $fastUploadPath ? ($exifMetadata['exif_lens_model'] ?? $existing['exif_lens_model'] ?? null) : ($exifMetadata['exif_lens_model'] ?? null),
            $fastUploadPath ? ($exifMetadata['exif_focal_length'] ?? $existing['exif_focal_length'] ?? null) : ($exifMetadata['exif_focal_length'] ?? null),
            $fastUploadPath ? ($exifMetadata['exif_aperture'] ?? $existing['exif_aperture'] ?? null) : ($exifMetadata['exif_aperture'] ?? null),
            $fastUploadPath ? ($exifMetadata['exif_exposure_time'] ?? $existing['exif_exposure_time'] ?? null) : ($exifMetadata['exif_exposure_time'] ?? null),
            $fastUploadPath ? ($exifMetadata['exif_iso'] ?? $existing['exif_iso'] ?? null) : ($exifMetadata['exif_iso'] ?? null),
            $fastUploadPath ? ($exifMetadata['gps_lat'] ?? $existing['gps_lat'] ?? null) : ($exifMetadata['gps_lat'] ?? null),
            $fastUploadPath ? ($exifMetadata['gps_lng'] ?? $existing['gps_lng'] ?? null) : ($exifMetadata['gps_lng'] ?? null),
            $fastUploadPath ? ($exifMetadata['gps_altitude'] ?? $existing['gps_altitude'] ?? null) : ($exifMetadata['gps_altitude'] ?? null),
            $fastUploadPath ? ($exifMetadata['gps_extracted_at'] ?? $existing['gps_extracted_at'] ?? null) : ($exifMetadata['gps_extracted_at'] ?? null),
            $checksum,
            now_sql(),
            (int) $existing['id'],
        ]);
    } else {
        // $stmt updates source metadata for an existing image row.
        $stmt = $pdo->prepare('UPDATE images SET filename = ?, width = ?, height = ?, mime_type = ?, file_size = ?, modified_at = ?, checksum_sha256 = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([
            $filename,
            (int) $info['width'],
            (int) $info['height'],
            (string) $info['mime'],
            $fileSize,
            $modifiedAt,
            $checksum,
            now_sql(),
            (int) $existing['id'],
        ]);
    }
    scan_image_sync_master_display_metadata((int) $existing['id'], $info);
    if ($sourceChanged) {
        scan_image_invalidate_thumbnail_derivatives((int) $existing['id']);
    }
    return 1;
}

/**
 * Refresh gallery-level derived data after scanner changes.
 *
 * @param array $gallery Gallery row.
 * @param int $count Number of changed image rows.
 * @param array $options Refresh options for targeted upload workflows.
 */
function scan_gallery_refresh_after_changes(array $gallery, int $count, array $options = []): void
{
    apply_gallery_cover_from_sidecar($gallery);
    ensure_gallery_cover((int) $gallery['id']);
    if ($count > 0 && public_path_schema_ready()) {
        // $publicPathScope stores whether a targeted upload can avoid global URL regeneration.
        $publicPathScope = (string) ($options['public_path_scope'] ?? 'all');
        if ($publicPathScope === 'gallery_images' && function_exists('Gallery\Services\regenerate_gallery_image_public_slugs')) {
            regenerate_gallery_image_public_slugs((int) $gallery['id']);
        } else {
            regenerate_public_paths();
        }
    }
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
        $count += scan_gallery_image_file_entry($gallery, $root, $file->getPathname(), $file->getFilename(), $pdo, $exifSchemaReady, $nextSortOrder);
    }
    scan_gallery_refresh_after_changes($gallery, $count);
    return $count;
}

/**
 * Scan only selected image paths inside one gallery.
 *
 * The browser-prepared upload path already knows which originals were written by
 * the current ZIP package. Scanning only those files avoids re-reading EXIF,
 * dimensions, and SHA-256 hashes for the whole gallery after every batch.
 *
 * @param int $galleryId Gallery identifier.
 * @param array $relativePaths Gallery-relative source image paths.
 * @return int Number of changed image rows.
 */
function scan_gallery_selected_images(int $galleryId, array $relativePaths): int
{
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        return 0;
    }
    $root = gallery_abs_path((string) $gallery['folder_path']);
    $realRoot = realpath($root);
    if (!is_string($realRoot) || !is_dir($realRoot)) {
        return 0;
    }

    $pdo = db();
    $count = 0;
    $exifSchemaReady = exif_gps_schema_ready();
    $nextSortOrder = next_gallery_image_sort_order($galleryId);
    $seen = [];
    foreach ($relativePaths as $relativePath) {
        try {
            $relative = normalize_relative_path((string) $relativePath);
        } catch (Throwable) {
            continue;
        }
        if ($relative === '' || isset($seen[$relative]) || !is_supported_image_path($relative)) {
            continue;
        }
        $seen[$relative] = true;
        $candidate = $realRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $realPath = realpath($candidate);
        if (!is_string($realPath) || !is_file($realPath)) {
            continue;
        }
        if ($realPath !== $realRoot && !str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR)) {
            continue;
        }
        $count += scan_gallery_image_file_entry($gallery, $realRoot, $realPath, basename($relative), $pdo, $exifSchemaReady, $nextSortOrder);
    }

    scan_gallery_refresh_after_changes($gallery, $count, ['public_path_scope' => 'gallery_images']);
    return $count;
}

/**
 * Scan only selected upload paths using lightweight source metadata.
 *
 * Upload handlers already know exactly which files they wrote. This helper keeps
 * the request bounded to those files and skips full checksums and EXIF/GPS reads
 * so slow shared hosting does not spend upload time on maintenance-grade scans.
 *
 * @param int $galleryId Gallery identifier.
 * @param array $relativePaths Gallery-relative source image paths.
 * @param array $metadataByRelativePath Optional source metadata keyed by relative path.
 * @return int Number of changed image rows.
 */
function scan_gallery_selected_uploaded_images(int $galleryId, array $relativePaths, array $metadataByRelativePath = []): int
{
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        return 0;
    }
    $root = gallery_abs_path((string) $gallery['folder_path']);
    $realRoot = realpath($root);
    if (!is_string($realRoot) || !is_dir($realRoot)) {
        return 0;
    }

    $pdo = db();
    $count = 0;
    $exifSchemaReady = exif_gps_schema_ready();
    $nextSortOrder = next_gallery_image_sort_order($galleryId);
    $seen = [];
    foreach ($relativePaths as $relativePath) {
        try {
            $relative = normalize_relative_path((string) $relativePath);
        } catch (Throwable) {
            continue;
        }
        if ($relative === '' || isset($seen[$relative]) || !is_supported_image_path($relative)) {
            continue;
        }
        $seen[$relative] = true;
        $candidate = $realRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $realPath = realpath($candidate);
        if (!is_string($realPath) || !is_file($realPath)) {
            continue;
        }
        if ($realPath !== $realRoot && !str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR)) {
            continue;
        }
        $metadata = is_array($metadataByRelativePath[$relative] ?? null) ? $metadataByRelativePath[$relative] : null;
        $count += scan_gallery_image_file_entry($gallery, $realRoot, $realPath, basename($relative), $pdo, $exifSchemaReady, $nextSortOrder, [
            'upload_fast_path' => true,
            'metadata' => $metadata,
        ]);
    }

    scan_gallery_refresh_after_changes($gallery, $count, ['public_path_scope' => 'gallery_images']);
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
    $stmt = db()->prepare('SELECT id FROM galleries ORDER BY folder_path');
    $stmt->execute();
    $galleryIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($galleryIds as $galleryId) {
        // $current stores an intermediate value used by the surrounding gallery workflow.
        $current = scan_gallery_images((int) $galleryId);
        $scanned++;
        $changed += $current;
    }
    return ['galleries' => $scanned, 'images' => $changed];
}

