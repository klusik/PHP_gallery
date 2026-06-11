<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/thumbnail_metadata.php
 * Module Type: Service
 *
 * Purpose:
 *   Persists generated thumbnail variant metadata and validates it without
 *   probing the thumbnail filesystem during normal public gallery rendering.
 *
 * Responsibilities:
 *   - Store one database row for every known thumbnail variant
 *   - Keep source dimensions, source file identity, EXIF summary, derivative dimensions, and validation status together
 *   - Let public renderers select only aspect-ratio-correct thumbnails from database state
 *   - Let generation, warmup, and admin maintenance refresh physical derivative state deliberately
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

/**
 * Return true when durable thumbnail metadata storage is available.
 *
 * @return bool True when the condition matches.
 */
function thumbnail_metadata_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    if (!function_exists('db_table_exists')) {
        return $ready = false;
    }

    return $ready = db_table_exists('image_thumbnail_variants');
}

/**
 * Return an SQL datetime string for a filesystem timestamp.
 *
 * @param int $timestamp Timestamp value.
 * @return string Text result for the caller.
 */
function thumbnail_metadata_datetime_from_timestamp(int $timestamp): string
{
    return date('Y-m-d H:i:s', max(0, $timestamp));
}

/**
 * Return a normalized source modified-at value from an image row.
 *
 * @param array $image Image row or image data.
 * @return string Text result for the caller.
 */
function thumbnail_metadata_image_modified_at(array $image): string
{
    return trim((string) ($image['modified_at'] ?? ''));
}

/**
 * Return the relative thumbnail path stored for metadata diagnostics.
 *
 * @param array $image Image row or image data.
 * @param int $size Size value.
 * @param string $format Format value.
 * @return string Text result for the caller.
 */
function thumbnail_metadata_relative_path(array $image, int $size, string $format): string
{
    return 'thumbs/' . thumbnail_filename($image, $size, $format);
}

/**
 * Return a compact source EXIF and GPS summary already stored on the image row.
 *
 * @param array $image Image row or image data.
 * @return ?string Text result for the caller.
 */
function thumbnail_metadata_source_exif_json(array $image): ?string
{
    $keys = [
        'exif_taken_at',
        'exif_camera_make',
        'exif_camera_model',
        'exif_lens_model',
        'exif_focal_length',
        'exif_aperture',
        'exif_exposure_time',
        'exif_iso',
        'gps_lat',
        'gps_lng',
        'gps_altitude',
        'gps_extracted_at',
    ];

    $summary = [];
    foreach ($keys as $key) {
        if (array_key_exists($key, $image) && $image[$key] !== null && $image[$key] !== '') {
            $summary[$key] = $image[$key];
        }
    }

    if (!$summary) {
        return null;
    }

    $json = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : null;
}

/**
 * Return source metadata used for validating durable thumbnail rows.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param ?string $sourcePath Source filesystem path.
 * @return array<string mixed>.
 */
function thumbnail_metadata_source_payload(array $image, array $gallery, ?string $sourcePath = null): array
{
    $sourcePath = $sourcePath ?: image_abs_path($image, $gallery);
    $sourceExists = is_file($sourcePath);
    $sourceInfo = $sourceExists ? @getimagesize($sourcePath) : false;
    $mime = is_array($sourceInfo) ? (string) ($sourceInfo['mime'] ?? ($image['mime_type'] ?? '')) : (string) ($image['mime_type'] ?? '');
    $sourceGeometry = $sourceExists && function_exists('thumbnail_source_geometry_dimensions')
        ? thumbnail_source_geometry_dimensions($sourcePath, $image)
        : null;

    if (!is_array($sourceGeometry)) {
        $width = (int) ($image['width'] ?? 0);
        $height = (int) ($image['height'] ?? 0);
        $sourceGeometry = $width > 0 && $height > 0 ? ['width' => $width, 'height' => $height] : ['width' => 0, 'height' => 0];
    }

    $sourceMtime = $sourceExists ? (int) (filemtime($sourcePath) ?: 0) : 0;
    $sourceModifiedAt = $sourceMtime > 0 ? thumbnail_metadata_datetime_from_timestamp($sourceMtime) : thumbnail_metadata_image_modified_at($image);
    $orientation = 1;
    if ($sourceExists && function_exists('thumbnail_jpeg_exif_orientation')) {
        $orientation = thumbnail_jpeg_exif_orientation($sourcePath, $mime);
    }

    return [
        'width' => (int) ($sourceGeometry['width'] ?? 0),
        'height' => (int) ($sourceGeometry['height'] ?? 0),
        'mime_type' => $mime !== '' ? $mime : null,
        'file_size' => $sourceExists ? (int) (filesize($sourcePath) ?: 0) : (int) ($image['file_size'] ?? 0),
        'modified_at' => $sourceModifiedAt !== '' ? $sourceModifiedAt : null,
        'checksum_sha256' => trim((string) ($image['checksum_sha256'] ?? '')) !== '' ? trim((string) $image['checksum_sha256']) : null,
        'exif_orientation' => $orientation >= 1 && $orientation <= 8 ? $orientation : 1,
        'exif_json' => thumbnail_metadata_source_exif_json($image),
    ];
}

/**
 * Return true when a stored metadata row still describes the current image row.
 *
 * @param array $row Row data.
 * @param array $image Image row or image data.
 * @return bool True when the condition matches.
 */
function thumbnail_metadata_row_matches_image_source(array $row, array $image): bool
{
    $imageChecksum = trim((string) ($image['checksum_sha256'] ?? ''));
    $rowChecksum = trim((string) ($row['source_checksum_sha256'] ?? ''));
    if ($imageChecksum !== '' && $rowChecksum !== '') {
        return hash_equals($imageChecksum, $rowChecksum);
    }

    $imageFileSize = (int) ($image['file_size'] ?? 0);
    $rowFileSize = (int) ($row['source_file_size'] ?? 0);
    if ($imageFileSize > 0 && $rowFileSize > 0 && $imageFileSize !== $rowFileSize) {
        return false;
    }

    $imageModifiedAt = thumbnail_metadata_image_modified_at($image);
    $rowModifiedAt = trim((string) ($row['source_modified_at'] ?? ''));
    if ($imageModifiedAt !== '' && $rowModifiedAt !== '' && $imageModifiedAt !== $rowModifiedAt) {
        return false;
    }

    return true;
}

/**
 * Return true when a stored thumbnail row preserves the stored source aspect ratio.
 *
 * @param array $row Row data.
 * @return bool True when the condition matches.
 */
function thumbnail_metadata_row_has_valid_geometry(array $row): bool
{
    $sourceWidth = (int) ($row['source_width'] ?? 0);
    $sourceHeight = (int) ($row['source_height'] ?? 0);
    $actualWidth = (int) ($row['width'] ?? 0);
    $actualHeight = (int) ($row['height'] ?? 0);
    $size = (int) ($row['size_px'] ?? 0);

    if ($sourceWidth <= 0 || $sourceHeight <= 0 || $actualWidth <= 0 || $actualHeight <= 0 || $size <= 0) {
        return false;
    }

    $expected = thumbnail_expected_dimensions($sourceWidth, $sourceHeight, $size);
    $pixelTolerance = 2;
    if (abs($actualWidth - (int) $expected['width']) <= $pixelTolerance && abs($actualHeight - (int) $expected['height']) <= $pixelTolerance) {
        return true;
    }

    $expectedRatio = (float) $expected['width'] / max(1, (int) $expected['height']);
    $actualRatio = $actualWidth / max(1, $actualHeight);
    return abs($actualRatio - $expectedRatio) <= 0.015 && max($actualWidth, $actualHeight) <= $size;
}

/**
 * Return true when a metadata row may be used for public rendering.
 *
 * @param array $row Row data.
 * @param array $image Image row or image data.
 * @return bool True when the condition matches.
 */
function thumbnail_metadata_row_is_renderable(array $row, array $image): bool
{
    if ((string) ($row['status'] ?? '') !== 'valid') {
        return false;
    }
    if (!thumbnail_metadata_row_matches_image_source($row, $image)) {
        return false;
    }
    return thumbnail_metadata_row_has_valid_geometry($row);
}

/**
 * Return renderable thumbnail metadata rows grouped by format and size.
 *
 * @param array $image Image row or image data.
 * @param array $sizes Sizes value.
 * @return array<string array<int, array<string, mixed>>>.
 */
function thumbnail_metadata_renderable_rows(array $image, array $sizes): array
{
    if (!thumbnail_metadata_schema_ready()) {
        return ['jpg' => [], 'webp' => []];
    }

    $sizes = array_values(array_unique(array_filter(array_map('intval', $sizes), static fn (int $size): bool => in_array($size, thumbnail_sizes(), true))));
    if (!$sizes) {
        return ['jpg' => [], 'webp' => []];
    }

    $placeholders = implode(',', array_fill(0, count($sizes), '?'));
    $params = array_merge([(int) ($image['id'] ?? 0)], $sizes);
    $stmt = db()->prepare("SELECT * FROM image_thumbnail_variants WHERE image_id = ? AND size_px IN ($placeholders) ORDER BY size_px, format");
    $stmt->execute($params);

    $rows = ['jpg' => [], 'webp' => []];
    foreach ($stmt->fetchAll() as $row) {
        $format = (string) ($row['format'] ?? '');
        $size = (int) ($row['size_px'] ?? 0);
        if (!in_array($format, ['jpg', 'webp'], true) || !in_array($size, $sizes, true)) {
            continue;
        }
        if (!thumbnail_metadata_row_is_renderable($row, $image)) {
            continue;
        }
        $rows[$format][$size] = $row;
    }

    ksort($rows['jpg']);
    ksort($rows['webp']);
    return $rows;
}

/**
 * Return whether any metadata row exists for one image.
 *
 * @param array $image Image row or image data.
 * @return bool True when the condition matches.
 */
function thumbnail_metadata_image_has_rows(array $image): bool
{
    if (!thumbnail_metadata_schema_ready()) {
        return false;
    }

    $stmt = db()->prepare('SELECT 1 FROM image_thumbnail_variants WHERE image_id = ? LIMIT 1');
    $stmt->execute([(int) ($image['id'] ?? 0)]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Remove one thumbnail metadata row.
 *
 * @param array|int $image Image row or image data.
 * @param int $size Size value.
 * @param string $format Format value.
 */
function thumbnail_metadata_delete_variant(array|int $image, int $size, string $format): void
{
    if (!thumbnail_metadata_schema_ready()) {
        return;
    }
    if (!in_array($format, ['jpg', 'webp'], true) || !in_array($size, thumbnail_sizes(), true)) {
        return;
    }

    $imageId = is_array($image) ? (int) ($image['id'] ?? 0) : (int) $image;
    if ($imageId <= 0) {
        return;
    }

    $stmt = db()->prepare('DELETE FROM image_thumbnail_variants WHERE image_id = ? AND size_px = ? AND format = ?');
    $stmt->execute([$imageId, $size, $format]);
}

/**
 * Remove every thumbnail metadata row for one image.
 *
 * @param array|int $image Image row or image data.
 */
function thumbnail_metadata_delete_image_variants(array|int $image): void
{
    if (!thumbnail_metadata_schema_ready()) {
        return;
    }

    $imageId = is_array($image) ? (int) ($image['id'] ?? 0) : (int) $image;
    if ($imageId <= 0) {
        return;
    }

    $stmt = db()->prepare('DELETE FROM image_thumbnail_variants WHERE image_id = ?');
    $stmt->execute([$imageId]);
}

/**
 * Return true when one renderable metadata row exists for a concrete thumbnail variant.
 *
 * @param array $image Image row or image data.
 * @param int $size Size value.
 * @param string $format Format value.
 * @return bool True when the condition matches.
 */
function thumbnail_metadata_has_renderable_variant(array $image, int $size, string $format): bool
{
    if (!thumbnail_metadata_schema_ready() || !in_array($format, ['jpg', 'webp'], true) || !in_array($size, thumbnail_sizes(), true)) {
        return false;
    }

    $rows = thumbnail_metadata_renderable_rows($image, [$size]);
    return isset($rows[$format][$size]);
}

/**
 * Store metadata for one existing generated thumbnail file.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param int $size Size value.
 * @param string $format Format value.
 * @param string $thumbnailPath Thumbnail path filesystem path.
 * @param ?string $sourcePath Source filesystem path.
 * @param bool $deleteInvalid Delete invalid value.
 * @return array<string mixed>.
 */
function thumbnail_metadata_record_file(array $image, array $gallery, int $size, string $format, string $thumbnailPath, ?string $sourcePath = null, bool $deleteInvalid = false): array
{
    if (!thumbnail_metadata_schema_ready()) {
        return ['status' => 'metadata_unavailable', 'valid' => false, 'deleted' => false];
    }
    if (!in_array($format, ['jpg', 'webp'], true) || !in_array($size, thumbnail_sizes(), true)) {
        return ['status' => 'unsupported_variant', 'valid' => false, 'deleted' => false];
    }

    if (!is_file($thumbnailPath)) {
        thumbnail_metadata_delete_variant($image, $size, $format);
        return ['status' => 'missing', 'valid' => false, 'deleted' => false];
    }

    $actualInfo = @getimagesize($thumbnailPath);
    $actualWidth = is_array($actualInfo) ? (int) ($actualInfo[0] ?? 0) : 0;
    $actualHeight = is_array($actualInfo) ? (int) ($actualInfo[1] ?? 0) : 0;
    $source = thumbnail_metadata_source_payload($image, $gallery, $sourcePath);

    if ((int) $source['width'] > 0 && (int) $source['height'] > 0 && function_exists('thumbnail_file_geometry_status')) {
        $geometryStatus = thumbnail_file_geometry_status($thumbnailPath, (int) $source['width'], (int) $source['height'], $size);
    } else {
        $geometryStatus = [
            'valid' => $actualWidth > 0 && $actualHeight > 0,
            'reason' => $actualWidth > 0 && $actualHeight > 0 ? 'source_geometry_unavailable' : 'thumbnail_unreadable',
        ];
    }

    $valid = !empty($geometryStatus['valid']);
    $status = $valid ? 'valid' : 'invalid';
    $reason = substr((string) ($geometryStatus['reason'] ?? ($valid ? 'ok' : 'invalid_geometry')), 0, 100);

    if (!$valid && $deleteInvalid) {
        if (function_exists('thumbnail_delete_invalid_geometry_file')) {
            thumbnail_delete_invalid_geometry_file($thumbnailPath);
        } elseif (is_file($thumbnailPath)) {
            @unlink($thumbnailPath);
        }
        thumbnail_metadata_delete_variant($image, $size, $format);
        return ['status' => 'invalid', 'valid' => false, 'deleted' => true, 'reason' => $reason];
    }

    $now = now_sql();
    $stmt = db()->prepare("INSERT INTO image_thumbnail_variants (
        image_id,
        gallery_id,
        size_px,
        format,
        thumbnail_rel_path,
        width,
        height,
        file_size,
        modified_at,
        source_width,
        source_height,
        source_mime_type,
        source_file_size,
        source_modified_at,
        source_checksum_sha256,
        source_exif_orientation,
        source_exif_json,
        status,
        status_reason,
        checked_at,
        created_at,
        updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        gallery_id = VALUES(gallery_id),
        thumbnail_rel_path = VALUES(thumbnail_rel_path),
        width = VALUES(width),
        height = VALUES(height),
        file_size = VALUES(file_size),
        modified_at = VALUES(modified_at),
        source_width = VALUES(source_width),
        source_height = VALUES(source_height),
        source_mime_type = VALUES(source_mime_type),
        source_file_size = VALUES(source_file_size),
        source_modified_at = VALUES(source_modified_at),
        source_checksum_sha256 = VALUES(source_checksum_sha256),
        source_exif_orientation = VALUES(source_exif_orientation),
        source_exif_json = VALUES(source_exif_json),
        status = VALUES(status),
        status_reason = VALUES(status_reason),
        checked_at = VALUES(checked_at),
        updated_at = VALUES(updated_at)");

    $stmt->execute([
        (int) ($image['id'] ?? 0),
        (int) ($gallery['id'] ?? ($image['gallery_id'] ?? 0)),
        $size,
        $format,
        thumbnail_metadata_relative_path($image, $size, $format),
        $actualWidth > 0 ? $actualWidth : null,
        $actualHeight > 0 ? $actualHeight : null,
        (int) (filesize($thumbnailPath) ?: 0),
        thumbnail_metadata_datetime_from_timestamp((int) (filemtime($thumbnailPath) ?: time())),
        (int) $source['width'] > 0 ? (int) $source['width'] : null,
        (int) $source['height'] > 0 ? (int) $source['height'] : null,
        $source['mime_type'],
        (int) $source['file_size'] > 0 ? (int) $source['file_size'] : null,
        $source['modified_at'],
        $source['checksum_sha256'],
        (int) $source['exif_orientation'],
        $source['exif_json'],
        $status,
        $reason,
        $now,
        $now,
        $now,
    ]);

    return ['status' => $status, 'valid' => $valid, 'deleted' => false, 'reason' => $reason];
}

/**
 * Refresh metadata for all existing thumbnail files belonging to one image.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param ?array $sizes Sizes value.
 * @param bool $deleteInvalid Delete invalid value.
 * @return array<string mixed>.
 */
function thumbnail_metadata_refresh_image(array $image, array $gallery, ?array $sizes = null, bool $deleteInvalid = true): array
{
    $sizes = $sizes === null
        ? thumbnail_sizes()
        : array_values(array_unique(array_filter(array_map('intval', $sizes), static fn (int $size): bool => in_array($size, thumbnail_sizes(), true))));

    $checked = 0;
    $valid = 0;
    $missing = 0;
    $invalidDeleted = 0;
    $invalidFiles = [];

    try {
        $sourcePath = image_abs_path($image, $gallery);
    } catch (Throwable) {
        thumbnail_metadata_delete_image_variants($image);
        return ['checked' => 0, 'valid' => 0, 'missing' => count($sizes) * 2, 'invalid_deleted' => 0, 'invalid_files' => []];
    }

    foreach ($sizes as $size) {
        foreach (['jpg', 'webp'] as $format) {
            $checked++;
            try {
                $thumbnailPath = thumbnail_abs_path($image, $gallery, (int) $size, $format);
            } catch (RuntimeException) {
                $missing++;
                thumbnail_metadata_delete_variant($image, (int) $size, $format);
                continue;
            }

            if (!is_file($thumbnailPath)) {
                $missing++;
                thumbnail_metadata_delete_variant($image, (int) $size, $format);
                continue;
            }

            $result = thumbnail_metadata_record_file($image, $gallery, (int) $size, $format, $thumbnailPath, $sourcePath, $deleteInvalid);
            if (!empty($result['valid'])) {
                $valid++;
                continue;
            }
            if (!empty($result['deleted'])) {
                $invalidDeleted++;
                $invalidFiles[] = basename($thumbnailPath);
            }
        }
    }

    return [
        'checked' => $checked,
        'valid' => $valid,
        'missing' => $missing,
        'invalid_deleted' => $invalidDeleted,
        'invalid_files' => $invalidFiles,
    ];
}

/**
 * Return rows for rendering and the sizes that still need deliberate repair.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param array $sizes Sizes value.
 * @return array{variants:array<string,array<int,string>>,warmup_sizes:array<int,int>,known_from_db:bool} Structured result data for the caller.
 */
function thumbnail_metadata_bundle_data(array $image, array $gallery, array $sizes): array
{
    $variants = ['jpg' => [], 'webp' => []];
    $warmupSizes = [];

    if (!thumbnail_metadata_schema_ready()) {
        return ['variants' => $variants, 'warmup_sizes' => [], 'known_from_db' => false];
    }

    $rows = thumbnail_metadata_renderable_rows($image, $sizes);
    foreach (['jpg', 'webp'] as $format) {
        foreach ($rows[$format] as $size => $row) {
            $variants[$format][(int) $size] = thumbnail_serving_url($image, $gallery, (int) $size, $format);
        }
    }

    foreach ($sizes as $size) {
        $size = (int) $size;
        if (!isset($variants['jpg'][$size]) && !isset($variants['webp'][$size])) {
            $warmupSizes[$size] = $size;
        }
    }

    return [
        'variants' => $variants,
        'warmup_sizes' => $warmupSizes,
        'known_from_db' => thumbnail_metadata_image_has_rows($image),
    ];
}
