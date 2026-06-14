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
 *   - Store one compact database row for every known thumbnail variant
 *   - Keep source image facts on the master image row, not duplicated across derivatives
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
 *   2026-06-13
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use function Gallery\Core\db;
use function Gallery\Core\now_sql;

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

    if (!function_exists('Gallery\\Services\\db_table_exists') || !db_table_exists('image_thumbnail_variants')) {
        return $ready = false;
    }

    // $requiredColumns stores the durable variant columns common to the legacy
    // and compact schemas. Treat partially edited tables as unavailable so
    // optional thumbnail metadata cannot break uploads or thumbnail generation.
    $requiredColumns = [
        'image_id',
        'size_px',
        'format',
        'width',
        'height',
        'file_size',
        'modified_at',
        'status',
        'status_reason',
        'checked_at',
        'created_at',
        'updated_at',
    ];
    $columns = thumbnail_metadata_table_columns('image_thumbnail_variants', true);
    foreach ($requiredColumns as $column) {
        if (!isset($columns[$column])) {
            return $ready = false;
        }
    }

    return $ready = true;
}


/**
 * Return the request-local cache used by renderable thumbnail metadata lookups.
 *
 * @return array<string,array<string,array<int,array<string,mixed>>>> Cached rows keyed by image id and size set.
 */
function &thumbnail_metadata_renderable_rows_request_cache(): array
{
    static $cache = [];
    return $cache;
}

/**
 * Return the request-local cache used by thumbnail metadata row-existence checks.
 *
 * @return array<int,bool> Cached existence flags keyed by image id.
 */
function &thumbnail_metadata_image_has_rows_request_cache(): array
{
    static $cache = [];
    return $cache;
}

/**
 * Return the cache key for a thumbnail metadata size set.
 *
 * @param array $sizes Sizes value.
 * @return string Text result for the caller.
 */
function thumbnail_metadata_size_cache_key(array $sizes): string
{
    // $sizes stores normalized thumbnail sizes represented by this cache key.
    $sizes = array_values(array_unique(array_filter(array_map('intval', $sizes), static fn (int $size): bool => in_array($size, thumbnail_sizes(), true))));
    sort($sizes);
    return implode(',', $sizes);
}

/**
 * Warm renderable thumbnail metadata rows for many images in one database query.
 *
 * Public gallery cards frequently render several collage images at once. This
 * helper keeps the existing per-image bundle API while avoiding one metadata
 * query for every individual image during the same request.
 *
 * @param array $images Image rows keyed by id or sequential index.
 * @param array $sizes Sizes value.
 */
function thumbnail_metadata_preload_renderable_rows(array $images, array $sizes): void
{
    if (!thumbnail_metadata_schema_ready()) {
        return;
    }

    // $sizes stores normalized thumbnail sizes represented by this preload.
    $sizes = array_values(array_unique(array_filter(array_map('intval', $sizes), static fn (int $size): bool => in_array($size, thumbnail_sizes(), true))));
    sort($sizes);
    if (!$sizes) {
        return;
    }

    // $sizeKey stores the cache key shared by this preload batch.
    $sizeKey = thumbnail_metadata_size_cache_key($sizes);
    // $rowsCache stores renderable rows already discovered during this request.
    $rowsCache = &thumbnail_metadata_renderable_rows_request_cache();
    // $hasRowsCache stores metadata existence flags already discovered during this request.
    $hasRowsCache = &thumbnail_metadata_image_has_rows_request_cache();
    // $canCacheExistence stores whether the preload covers every configured thumbnail size.
    $canCacheExistence = $sizeKey === thumbnail_metadata_size_cache_key(thumbnail_sizes());
    // $imageById stores source image rows keyed by image id.
    $imageById = [];
    foreach ($images as $image) {
        // $imageId stores the image identifier used by thumbnail metadata rows.
        $imageId = (int) ($image['id'] ?? 0);
        if ($imageId <= 0 || array_key_exists($imageId, $imageById)) {
            continue;
        }
        $imageById[$imageId] = $image;
    }
    if (!$imageById) {
        return;
    }

    // $missingImageIds stores image ids not yet available in the request-local cache.
    $missingImageIds = [];
    foreach (array_keys($imageById) as $imageId) {
        // $cacheKey stores an intermediate value used by the surrounding gallery workflow.
        $cacheKey = $imageId . ':' . $sizeKey;
        if (!array_key_exists($cacheKey, $rowsCache)) {
            $rowsCache[$cacheKey] = ['jpg' => [], 'webp' => []];
            if ($canCacheExistence) {
                $hasRowsCache[$imageId] = false;
            }
            $missingImageIds[] = $imageId;
        }
    }
    if (!$missingImageIds) {
        return;
    }

    // $imagePlaceholders stores placeholders for image ids.
    $imagePlaceholders = implode(',', array_fill(0, count($missingImageIds), '?'));
    // $sizePlaceholders stores placeholders for thumbnail sizes.
    $sizePlaceholders = implode(',', array_fill(0, count($sizes), '?'));
    // $params stores bound image ids and sizes.
    $params = array_merge($missingImageIds, $sizes);
    try {
        // $stmt stores the batched thumbnail metadata query.
        $stmt = db()->prepare("SELECT * FROM image_thumbnail_variants WHERE image_id IN ($imagePlaceholders) AND size_px IN ($sizePlaceholders) ORDER BY image_id, size_px, format");
        $stmt->execute($params);
        $metadataRows = $stmt->fetchAll();
    } catch (Throwable) {
        return;
    }

    foreach ($metadataRows as $row) {
        // $imageId stores the owner image id for this thumbnail metadata row.
        $imageId = (int) ($row['image_id'] ?? 0);
        if (!isset($imageById[$imageId])) {
            continue;
        }
        if ($canCacheExistence) {
            $hasRowsCache[$imageId] = true;
        }
        // $format stores the thumbnail format for this metadata row.
        $format = (string) ($row['format'] ?? '');
        // $size stores the thumbnail size for this metadata row.
        $size = (int) ($row['size_px'] ?? 0);
        if (!in_array($format, ['jpg', 'webp'], true) || !in_array($size, $sizes, true)) {
            continue;
        }
        if (!thumbnail_metadata_row_is_renderable($row, $imageById[$imageId])) {
            continue;
        }
        $rowsCache[$imageId . ':' . $sizeKey][$format][$size] = $row;
    }

    foreach ($missingImageIds as $imageId) {
        ksort($rowsCache[$imageId . ':' . $sizeKey]['jpg']);
        ksort($rowsCache[$imageId . ':' . $sizeKey]['webp']);
    }
}

/**
 * Return cached column names for one thumbnail-related table.
 *
 * @param string $table Table name.
 * @param bool $refresh Refresh cached schema metadata.
 * @return array<string,bool> Known columns keyed by column name.
 */
function thumbnail_metadata_table_columns(string $table, bool $refresh = false): array
{
    static $cache = [];
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? '';
    if ($safeTable === '') {
        return [];
    }
    if ($refresh) {
        unset($cache[$safeTable]);
    }
    if (array_key_exists($safeTable, $cache)) {
        return $cache[$safeTable];
    }

    $columns = [];
    try {
        $stmt = db()->query('SHOW COLUMNS FROM `' . $safeTable . '`');
        foreach ($stmt->fetchAll() as $column) {
            $columns[(string) ($column['Field'] ?? '')] = true;
        }
    } catch (Throwable) {
        $columns = [];
    }

    return $cache[$safeTable] = $columns;
}

/**
 * Return true when a thumbnail metadata table column exists.
 *
 * @param string $column Column name.
 * @return bool True when the column exists.
 */
function thumbnail_metadata_variant_column_exists(string $column): bool
{
    return isset(thumbnail_metadata_table_columns('image_thumbnail_variants')[$column]);
}

/**
 * Return true when a master image metadata column exists.
 *
 * @param string $column Column name.
 * @return bool True when the column exists.
 */
function thumbnail_metadata_image_column_exists(string $column): bool
{
    return isset(thumbnail_metadata_table_columns('images')[$column]);
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
 * Return the relative thumbnail path for backward-compatible legacy schemas.
 *
 * New compact metadata schemas do not persist this value. The helper remains so
 * interrupted deployments can still write old NOT NULL columns before the
 * compaction migration has run.
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
    $sourceGeometry = $sourceExists && function_exists('Gallery\\Services\\thumbnail_source_geometry_dimensions')
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
    if ($sourceExists && function_exists('Gallery\\Services\\thumbnail_jpeg_exif_orientation')) {
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
    ];
}

/**
 * Return true when a stored metadata row still belongs to the current derivative generation.
 *
 * Compact thumbnail rows no longer duplicate source checksums, source mtimes,
 * or EXIF summaries. The master image row owns those facts. The tiny
 * derivative_version value is the only per-variant staleness marker needed by
 * public rendering.
 *
 * @param array $row Row data.
 * @param array $image Image row or image data.
 * @return bool True when the condition matches.
 */
function thumbnail_metadata_row_matches_image_source(array $row, array $image): bool
{
    $imageVersion = (int) ($image['thumbnail_derivative_version'] ?? 0);
    $rowVersion = (int) ($row['derivative_version'] ?? 0);
    if ($imageVersion > 0 && $rowVersion > 0) {
        return $imageVersion === $rowVersion;
    }

    return true;
}

/**
 * Return orientation-aware display dimensions stored on the master image row.
 *
 * @param array $image Image row or image data.
 * @return array{width:int,height:int}|null Structured result data for the caller.
 */
function thumbnail_metadata_image_display_dimensions(array $image): ?array
{
    $displayWidth = (int) ($image['display_width'] ?? 0);
    $displayHeight = (int) ($image['display_height'] ?? 0);
    if ($displayWidth > 0 && $displayHeight > 0) {
        return ['width' => $displayWidth, 'height' => $displayHeight];
    }

    $width = (int) ($image['width'] ?? 0);
    $height = (int) ($image['height'] ?? 0);
    if ($width > 0 && $height > 0) {
        return ['width' => $width, 'height' => $height];
    }

    return null;
}

/**
 * Return true when a stored thumbnail row preserves the master image aspect ratio.
 *
 * @param array $row Row data.
 * @param array $image Image row or image data.
 * @return bool True when the condition matches.
 */
function thumbnail_metadata_row_has_valid_geometry(array $row, array $image): bool
{
    $sourceGeometry = thumbnail_metadata_image_display_dimensions($image);
    $sourceWidth = is_array($sourceGeometry) ? (int) $sourceGeometry['width'] : 0;
    $sourceHeight = is_array($sourceGeometry) ? (int) $sourceGeometry['height'] : 0;
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
    return thumbnail_metadata_row_has_valid_geometry($row, $image);
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
    sort($sizes);
    if (!$sizes) {
        return ['jpg' => [], 'webp' => []];
    }

    // $imageId stores the image identifier used by thumbnail metadata rows.
    $imageId = (int) ($image['id'] ?? 0);
    if ($imageId <= 0) {
        return ['jpg' => [], 'webp' => []];
    }

    // $sizeKey stores the cache key for this exact requested size set.
    $sizeKey = thumbnail_metadata_size_cache_key($sizes);
    // $rowsCache stores renderable rows already discovered during this request.
    $rowsCache = &thumbnail_metadata_renderable_rows_request_cache();
    // $cacheKey stores an intermediate value used by the surrounding gallery workflow.
    $cacheKey = $imageId . ':' . $sizeKey;
    if (array_key_exists($cacheKey, $rowsCache)) {
        return $rowsCache[$cacheKey];
    }

    thumbnail_metadata_preload_renderable_rows([$image], $sizes);
    return $rowsCache[$cacheKey] ?? ['jpg' => [], 'webp' => []];
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

    // $imageId stores the image identifier used by thumbnail metadata rows.
    $imageId = (int) ($image['id'] ?? 0);
    if ($imageId <= 0) {
        return false;
    }

    // $cache stores row-existence checks already discovered during this request.
    $cache = &thumbnail_metadata_image_has_rows_request_cache();
    if (array_key_exists($imageId, $cache)) {
        return $cache[$imageId];
    }

    try {
        $stmt = db()->prepare('SELECT 1 FROM image_thumbnail_variants WHERE image_id = ? LIMIT 1');
        $stmt->execute([$imageId]);
        return $cache[$imageId] = (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        return $cache[$imageId] = false;
    }
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

    try {
        $stmt = db()->prepare('DELETE FROM image_thumbnail_variants WHERE image_id = ? AND size_px = ? AND format = ?');
        $stmt->execute([$imageId, $size, $format]);
    } catch (Throwable) {
    }
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

    try {
        $stmt = db()->prepare('DELETE FROM image_thumbnail_variants WHERE image_id = ?');
        $stmt->execute([$imageId]);
    } catch (Throwable) {
    }
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
 * Persist orientation-aware source facts on the master image row.
 *
 * Thumbnail rows should not duplicate source EXIF, source paths, checksums,
 * or mtimes. This helper keeps the master image row authoritative and updates
 * it only when the compact source payload has changed.
 *
 * @param array $image Image row or image data.
 * @param array $source Source payload from thumbnail_metadata_source_payload().
 * @return bool True when the master row was updated.
 */
function thumbnail_metadata_sync_image_source_payload(array $image, array $source): bool
{
    static $synced = [];
    $imageId = (int) ($image['id'] ?? 0);
    if ($imageId <= 0) {
        return false;
    }

    $wanted = [
        'display_width' => (int) ($source['width'] ?? 0) > 0 ? (int) $source['width'] : null,
        'display_height' => (int) ($source['height'] ?? 0) > 0 ? (int) $source['height'] : null,
        'exif_orientation' => (int) ($source['exif_orientation'] ?? 1),
        'thumbnail_metadata_refreshed_at' => now_sql(),
    ];

    $available = [];
    foreach ($wanted as $column => $value) {
        if (thumbnail_metadata_image_column_exists($column)) {
            $available[$column] = $value;
        }
    }
    if (!$available) {
        return false;
    }

    $stableAvailable = $available;
    unset($stableAvailable['thumbnail_metadata_refreshed_at']);
    $signature = hash('sha256', json_encode($stableAvailable, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    if (($synced[$imageId] ?? '') === $signature) {
        return false;
    }

    $needsUpdate = false;
    foreach ($available as $column => $value) {
        if ($column === 'thumbnail_metadata_refreshed_at') {
            continue;
        }
        $current = $image[$column] ?? null;
        if ((string) $current !== (string) $value) {
            $needsUpdate = true;
            break;
        }
    }

    if (!$needsUpdate && !empty($image['thumbnail_metadata_refreshed_at'])) {
        $synced[$imageId] = $signature;
        return false;
    }

    $assignments = [];
    $params = [];
    foreach ($available as $column => $value) {
        $assignments[] = '`' . $column . '` = ?';
        $params[] = $value;
    }
    $params[] = $imageId;

    $stmt = db()->prepare('UPDATE images SET ' . implode(', ', $assignments) . ' WHERE id = ?');
    $stmt->execute($params);
    $synced[$imageId] = $signature;
    return true;
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
        return ['status' => 'metadata_unavailable', 'valid' => false, 'deleted' => false, 'metadata_written' => false];
    }
    if (!in_array($format, ['jpg', 'webp'], true) || !in_array($size, thumbnail_sizes(), true)) {
        return ['status' => 'unsupported_variant', 'valid' => false, 'deleted' => false, 'metadata_written' => false];
    }

    if (!is_file($thumbnailPath)) {
        thumbnail_metadata_delete_variant($image, $size, $format);
        return ['status' => 'missing', 'valid' => false, 'deleted' => false, 'metadata_written' => false];
    }

    $actualInfo = @getimagesize($thumbnailPath);
    $actualWidth = is_array($actualInfo) ? (int) ($actualInfo[0] ?? 0) : 0;
    $actualHeight = is_array($actualInfo) ? (int) ($actualInfo[1] ?? 0) : 0;
    $source = thumbnail_metadata_source_payload($image, $gallery, $sourcePath);
    $sourceSynced = false;
    $metadataError = null;
    try {
        $sourceSynced = thumbnail_metadata_sync_image_source_payload($image, $source);
    } catch (Throwable $exception) {
        // $metadataError stores an optional SQL diagnostic without failing the source upload.
        $metadataError = $exception->getMessage();
    }

    if ((int) $source['width'] > 0 && (int) $source['height'] > 0 && function_exists('Gallery\\Services\\thumbnail_file_geometry_status')) {
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
        if (function_exists('Gallery\\Services\\thumbnail_delete_invalid_geometry_file')) {
            thumbnail_delete_invalid_geometry_file($thumbnailPath);
        } elseif (is_file($thumbnailPath)) {
            @unlink($thumbnailPath);
        }
        thumbnail_metadata_delete_variant($image, $size, $format);
        return ['status' => 'invalid', 'valid' => false, 'deleted' => true, 'reason' => $reason, 'metadata_written' => false, 'source_synced' => $sourceSynced];
    }

    $now = now_sql();
    $variantColumns = [
        'image_id' => (int) ($image['id'] ?? 0),
        'size_px' => $size,
        'format' => $format,
        'width' => $actualWidth > 0 ? $actualWidth : null,
        'height' => $actualHeight > 0 ? $actualHeight : null,
        'file_size' => (int) (filesize($thumbnailPath) ?: 0),
        'modified_at' => thumbnail_metadata_datetime_from_timestamp((int) (filemtime($thumbnailPath) ?: time())),
        'status' => $status,
        'status_reason' => $reason,
        'checked_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ];

    if (thumbnail_metadata_variant_column_exists('derivative_version')) {
        $variantColumns = array_merge(
            array_slice($variantColumns, 0, 3, true),
            ['derivative_version' => max(1, (int) ($image['thumbnail_derivative_version'] ?? 1))],
            array_slice($variantColumns, 3, null, true)
        );
    }

    if (thumbnail_metadata_variant_column_exists('gallery_id')) {
        $variantColumns = array_merge(
            array_slice($variantColumns, 0, 1, true),
            ['gallery_id' => (int) ($gallery['id'] ?? ($image['gallery_id'] ?? 0))],
            array_slice($variantColumns, 1, null, true)
        );
    }
    if (thumbnail_metadata_variant_column_exists('thumbnail_rel_path')) {
        $variantColumns = array_merge(
            array_slice($variantColumns, 0, 5, true),
            ['thumbnail_rel_path' => thumbnail_metadata_relative_path($image, $size, $format)],
            array_slice($variantColumns, 5, null, true)
        );
    }

    $columnNames = array_keys($variantColumns);
    $insertColumns = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columnNames));
    $placeholders = implode(', ', array_fill(0, count($columnNames), '?'));
    $updateColumns = array_values(array_filter($columnNames, static fn (string $column): bool => !in_array($column, ['image_id', 'size_px', 'format', 'created_at'], true)));
    $updates = implode(', ', array_map(static fn (string $column): string => '`' . $column . '` = VALUES(`' . $column . '`)', $updateColumns));

    try {
        $stmt = db()->prepare('INSERT INTO image_thumbnail_variants (' . $insertColumns . ') VALUES (' . $placeholders . ') ON DUPLICATE KEY UPDATE ' . $updates);
        $stmt->execute(array_values($variantColumns));
    } catch (Throwable $exception) {
        return [
            'status' => $status,
            'valid' => $valid,
            'deleted' => false,
            'reason' => $reason,
            'metadata_written' => false,
            'source_synced' => $sourceSynced,
            'metadata_error' => $metadataError ?? $exception->getMessage(),
        ];
    }

    $result = ['status' => $status, 'valid' => $valid, 'deleted' => false, 'reason' => $reason, 'metadata_written' => true, 'source_synced' => $sourceSynced];
    if ($metadataError !== null) {
        $result['metadata_error'] = $metadataError;
    }
    return $result;
}

/**
 * Store metadata for one browser-prepared thumbnail without re-decoding the file.
 *
 * The experimental upload worker already decoded the source image and generated
 * the thumbnail dimensions. Re-reading every prepared thumbnail with
 * getimagesize() on shared hosting wastes CPU, so this path records the trusted
 * admin-upload manifest metadata after the file has been written.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param int $size Size value.
 * @param string $format Format value.
 * @param string $thumbnailPath Thumbnail path filesystem path.
 * @param int $width Browser-reported thumbnail width.
 * @param int $height Browser-reported thumbnail height.
 * @return array<string mixed>.
 */
function thumbnail_metadata_record_prepared_variant(array $image, array $gallery, int $size, string $format, string $thumbnailPath, int $width, int $height): array
{
    if (!thumbnail_metadata_schema_ready()) {
        return ['status' => 'metadata_unavailable', 'valid' => false, 'deleted' => false, 'metadata_written' => false];
    }
    if (!in_array($format, ['jpg', 'webp'], true) || !in_array($size, thumbnail_sizes(), true)) {
        return ['status' => 'unsupported_variant', 'valid' => false, 'deleted' => false, 'metadata_written' => false];
    }
    if (!is_file($thumbnailPath)) {
        thumbnail_metadata_delete_variant($image, $size, $format);
        return ['status' => 'missing', 'valid' => false, 'deleted' => false, 'metadata_written' => false];
    }

    $width = max(1, $width);
    $height = max(1, $height);
    $longSide = max($width, $height);
    $valid = $longSide <= max(1, $size);
    $status = $valid ? 'valid' : 'invalid';
    $reason = $valid ? 'ok' : 'manifest_geometry_exceeds_size';
    $now = now_sql();

    $variantColumns = [
        'image_id' => (int) ($image['id'] ?? 0),
        'size_px' => $size,
        'format' => $format,
        'width' => $width,
        'height' => $height,
        'file_size' => (int) (filesize($thumbnailPath) ?: 0),
        'modified_at' => thumbnail_metadata_datetime_from_timestamp((int) (filemtime($thumbnailPath) ?: time())),
        'status' => $status,
        'status_reason' => $reason,
        'checked_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ];

    if (thumbnail_metadata_variant_column_exists('derivative_version')) {
        $variantColumns = array_merge(
            array_slice($variantColumns, 0, 3, true),
            ['derivative_version' => max(1, (int) ($image['thumbnail_derivative_version'] ?? 1))],
            array_slice($variantColumns, 3, null, true)
        );
    }

    if (thumbnail_metadata_variant_column_exists('gallery_id')) {
        $variantColumns = array_merge(
            array_slice($variantColumns, 0, 1, true),
            ['gallery_id' => (int) ($gallery['id'] ?? ($image['gallery_id'] ?? 0))],
            array_slice($variantColumns, 1, null, true)
        );
    }
    if (thumbnail_metadata_variant_column_exists('thumbnail_rel_path')) {
        $variantColumns = array_merge(
            array_slice($variantColumns, 0, 5, true),
            ['thumbnail_rel_path' => thumbnail_metadata_relative_path($image, $size, $format)],
            array_slice($variantColumns, 5, null, true)
        );
    }

    $columnNames = array_keys($variantColumns);
    $insertColumns = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columnNames));
    $placeholders = implode(', ', array_fill(0, count($columnNames), '?'));
    $updateColumns = array_values(array_filter($columnNames, static fn (string $column): bool => !in_array($column, ['image_id', 'size_px', 'format', 'created_at'], true)));
    $updates = implode(', ', array_map(static fn (string $column): string => '`' . $column . '` = VALUES(`' . $column . '`)', $updateColumns));

    try {
        $stmt = db()->prepare('INSERT INTO image_thumbnail_variants (' . $insertColumns . ') VALUES (' . $placeholders . ') ON DUPLICATE KEY UPDATE ' . $updates);
        $stmt->execute(array_values($variantColumns));
    } catch (Throwable $exception) {
        return [
            'status' => $status,
            'valid' => $valid,
            'deleted' => false,
            'reason' => $reason,
            'metadata_written' => false,
            'metadata_error' => $exception->getMessage(),
        ];
    }

    return ['status' => $status, 'valid' => $valid, 'deleted' => false, 'reason' => $reason, 'metadata_written' => true, 'source_synced' => false];
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
    $metadataRowsWritten = 0;
    $metadataSourceSyncs = 0;

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
            if (!empty($result['metadata_written'])) {
                $metadataRowsWritten++;
            }
            if (!empty($result['source_synced'])) {
                $metadataSourceSyncs++;
            }
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
        'metadata_rows_written' => $metadataRowsWritten,
        'metadata_source_syncs' => $metadataSourceSyncs,
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

/**
 * Return a compact diagnostic snapshot of thumbnail metadata storage.
 *
 * The snapshot is intentionally suitable for Admin logs. It avoids source paths
 * and EXIF payloads while exposing the information needed to compare the old
 * duplicated schema with the compact derivative-only schema.
 *
 * @return array<string mixed> Structured diagnostic data.
 */
function thumbnail_metadata_storage_snapshot(): array
{
    if (!thumbnail_metadata_schema_ready()) {
        return ['available' => false, 'reason' => 'image_thumbnail_variants_missing'];
    }

    $columns = thumbnail_metadata_table_columns('image_thumbnail_variants', true);
    $legacyColumns = array_values(array_filter([
        'gallery_id',
        'thumbnail_rel_path',
        'source_width',
        'source_height',
        'source_mime_type',
        'source_file_size',
        'source_modified_at',
        'source_checksum_sha256',
        'source_exif_orientation',
        'source_exif_json',
    ], static fn (string $column): bool => isset($columns[$column])));

    $snapshot = [
        'available' => true,
        'compact_schema' => $legacyColumns === [] && isset($columns['derivative_version']),
        'legacy_columns_present' => $legacyColumns,
        'variant_columns' => array_keys($columns),
        'row_count' => null,
        'status_counts' => [],
        'data_bytes' => null,
        'index_bytes' => null,
        'total_bytes' => null,
    ];

    try {
        $snapshot['row_count'] = (int) db()->query('SELECT COUNT(*) FROM image_thumbnail_variants')->fetchColumn();
        $stmt = db()->query('SELECT status, COUNT(*) AS count_rows FROM image_thumbnail_variants GROUP BY status ORDER BY status');
        foreach ($stmt->fetchAll() as $row) {
            $snapshot['status_counts'][(string) ($row['status'] ?? '')] = (int) ($row['count_rows'] ?? 0);
        }
    } catch (Throwable $exception) {
        $snapshot['row_count_error'] = $exception->getMessage();
    }

    try {
        $stmt = db()->prepare('SELECT data_length, index_length FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
        $stmt->execute(['image_thumbnail_variants']);
        $table = $stmt->fetch() ?: [];
        $dataBytes = (int) ($table['data_length'] ?? 0);
        $indexBytes = (int) ($table['index_length'] ?? 0);
        $snapshot['data_bytes'] = $dataBytes;
        $snapshot['index_bytes'] = $indexBytes;
        $snapshot['total_bytes'] = $dataBytes + $indexBytes;
    } catch (Throwable $exception) {
        $snapshot['size_error'] = $exception->getMessage();
    }

    return $snapshot;
}
