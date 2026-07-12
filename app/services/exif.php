<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/exif.php
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

use PDOException;
use Throwable;
use function Gallery\Core\db;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;
use function Gallery\Core\url_for;

/**
 * EXIF and GPS metadata service module.
 *
 * This module owns the database capability checks, EXIF parsing helpers,
 * coordinate conversion routines, and gallery map point builders. Keeping the
 * metadata workflow together makes GPS map behavior easier to audit while
 * preserving the legacy public function names used by existing routes.
 */

/**
 * Return whether the database has the EXIF/GPS map migration applied.
 *
 * The application keeps this as a runtime check so the public site still works
 * before the administrator runs pending migrations after uploading new files.
 *
 * @return bool True when the condition matches.
 */
function exif_gps_schema_ready(): bool
{
    try {
        if (!function_exists('Gallery\Services\db_column_exists') || !db_column_exists('galleries', 'gps_map_enabled')) {
            return false;
        }

        // $requiredImageColumns stores every EXIF/GPS column used by scanner inserts and updates.
        $requiredImageColumns = [
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
        foreach ($requiredImageColumns as $column) {
            if (!db_column_exists('images', $column)) {
                return false;
            }
        }

        return true;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Return whether the gallery GPS flag supports inherited, explicit on, and explicit off values.
 *
 * Older installations have a NOT NULL boolean column. The new display-default
 * workflow requires NULL for inherited settings, so write paths use this guard
 * until the administrator applies the migration.
 *
 * @return bool True when the condition matches.
 */
function exif_gps_override_schema_ready(): bool
{
    try {
        // $galleryColumn stores the database metadata for the per-gallery GPS override.
        $galleryColumn = db()->query("SHOW COLUMNS FROM galleries LIKE 'gps_map_enabled'");
        $row = $galleryColumn ? $galleryColumn->fetch() : null;
        return is_array($row) && strtoupper((string) ($row['Null'] ?? '')) === 'YES';
    } catch (Throwable) {
        return false;
    }
}

/**
 * Return the app_settings key for the global EXIF/GPS display default.
 *
 * @return string Text result for the caller.
 */
function exif_gps_default_enabled_setting_key(): string
{
    return 'exif_gps_maps_default_enabled';
}

/**
 * Return whether galleries inherit public EXIF/GPS map display as enabled by default.
 *
 * @return bool True when the condition matches.
 */
function exif_gps_default_enabled(): bool
{
    return app_setting(exif_gps_default_enabled_setting_key(), '1') !== '0';
}

/**
 * Persist the global EXIF/GPS display default used by galleries without overrides.
 *
 * @param bool $enabled Enabled flag.
 */
function set_exif_gps_default_enabled(bool $enabled): void
{
    set_app_setting(exif_gps_default_enabled_setting_key(), $enabled ? '1' : '0');
}

/**
 * Normalize one gallery GPS map override value from database or form input.
 *
 * Returns null for inherited/default behavior, 1 for explicit enabled, and 0 for
 * explicit disabled.
 *
 * @param mixed $value Value to process.
 * @return ?int Integer result for the caller.
 */
function gallery_gps_map_storage_value(mixed $value): ?int
{
    if ($value === null) {
        return null;
    }

    // $text stores a normalized representation of the submitted value.
    $text = strtolower(trim((string) $value));
    if ($text === '' || $text === 'inherit' || $text === 'default' || $text === 'null') {
        return null;
    }
    if (in_array($text, ['1', 'on', 'enabled', 'enable', 'yes', 'true'], true)) {
        return 1;
    }
    if (in_array($text, ['0', 'off', 'disabled', 'disable', 'no', 'false'], true)) {
        return 0;
    }

    return null;
}

/**
 * Return the legacy recursive GPS map behavior used before inherited overrides existed.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return bool True when the condition matches.
 */
function gallery_legacy_allows_gps_maps(array $gallery): bool
{
    // Variable $current stores this steps working value.
    $current = $gallery;
    while ($current) {
        if ((int) ($current['gps_map_enabled'] ?? 0) === 1) {
            return true;
        }
        if (empty($current['parent_id'])) {
            return false;
        }
        // Variable $current stores this steps working value.
        $current = find_gallery((int) $current['parent_id']);
    }
    return false;
}

/**
 * Resolve the effective EXIF/GPS display state for one gallery branch.
 *
 * The closest explicit gallery override wins. Without any override, the global
 * default controls display. Before the nullable-column migration is applied, the
 * legacy boolean branch behavior is preserved to avoid unsafe NULL writes.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return bool True when the condition matches.
 */
function gallery_effective_gps_map_enabled(array $gallery): bool
{
    if (function_exists('Gallery\\Services\\feature_flag_enabled') && !feature_flag_enabled('gallery_maps')) {
        return false;
    }
    if (!exif_gps_schema_ready()) {
        return false;
    }
    if (!exif_gps_override_schema_ready()) {
        return gallery_legacy_allows_gps_maps($gallery);
    }

    // Variable $current stores this steps working value.
    $current = $gallery;
    while ($current) {
        // $override stores an inherited GPS display override read from the gallery row.
        $override = gallery_gps_map_storage_value($current['gps_map_enabled'] ?? null);
        if ($override !== null) {
            return $override === 1;
        }
        if (empty($current['parent_id'])) {
            break;
        }
        // Variable $current stores this steps working value.
        $current = find_gallery((int) $current['parent_id']);
    }

    return exif_gps_default_enabled();
}

/**
 * Return whether maps are enabled for one gallery branch.
 *
 * This wrapper preserves the public function name used by routes and renderers.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return bool True when the condition matches.
 */
function gallery_allows_gps_maps(array $gallery): bool
{
    return gallery_effective_gps_map_enabled($gallery);
}

/**
 * Count galleries that currently have an explicit EXIF/GPS display override.
 *
 * @return int Integer result for the caller.
 */
function exif_gps_gallery_override_count(): int
{
    if (!exif_gps_override_schema_ready()) {
        return 0;
    }

    try {
        return (int) db()->query('SELECT COUNT(*) FROM galleries WHERE gps_map_enabled IS NOT NULL')->fetchColumn();
    } catch (PDOException) {
        return 0;
    }
}

/**
 * Reset every per-gallery EXIF/GPS display override so galleries inherit defaults.
 *
 * @return int Integer result for the caller.
 */
function reset_all_gallery_gps_map_overrides(): int
{
    if (!exif_gps_override_schema_ready()) {
        return 0;
    }

    // $rows stores galleries that need their sidecars refreshed after the reset.
    $rows = db()->query('SELECT * FROM galleries WHERE gps_map_enabled IS NOT NULL ORDER BY folder_path')->fetchAll();
    if (!$rows) {
        return 0;
    }

    db()->exec('UPDATE galleries SET gps_map_enabled = NULL, updated_at = ' . db()->quote(now_sql()) . ' WHERE gps_map_enabled IS NOT NULL');

    foreach ($rows as $gallery) {
        $gallery['gps_map_enabled'] = null;
        $gallery['updated_at'] = now_sql();
        if (function_exists('Gallery\\Services\\write_gallery_sidecar')) {
            write_gallery_sidecar($gallery);
        }
    }

    return count($rows);
}

/**
 * Convert one EXIF rational value such as 35/10 into a float.
 *
 * @param mixed $value Value to process.
 * @return ?float Numeric result for the caller.
 */
function exif_rational_to_float(mixed $value): ?float
{
    if (is_array($value)) {
        // $value stores an intermediate value used by the surrounding gallery workflow.
        $value = reset($value);
    }
    // Variable $text stores this steps working value.
    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }
    if (str_contains($text, '/')) {
        [$numerator, $denominator] = array_pad(explode('/', $text, 2), 2, '0');
        // Variable $denominatorFloat stores this steps working value.
        $denominatorFloat = (float) $denominator;
        if (abs($denominatorFloat) < 0.0000001) {
            return null;
        }
        return (float) $numerator / $denominatorFloat;
    }
    return is_numeric($text) ? (float) $text : null;
}

/**
 * Convert EXIF GPS degree/minute/second triplets into signed decimal degrees.
 *
 * @param mixed $coordinate Coordinate value.
 * @param mixed $reference Reference value.
 * @return ?float Numeric result for the caller.
 */
function exif_gps_coordinate_to_decimal(mixed $coordinate, mixed $reference): ?float
{
    if (!is_array($coordinate) || count($coordinate) < 3) {
        return null;
    }
    // Variable $degrees stores this steps working value.
    $degrees = exif_rational_to_float($coordinate[0] ?? null);
    // Variable $minutes stores this steps working value.
    $minutes = exif_rational_to_float($coordinate[1] ?? null);
    // Variable $seconds stores this steps working value.
    $seconds = exif_rational_to_float($coordinate[2] ?? null);
    if ($degrees === null || $minutes === null || $seconds === null) {
        return null;
    }
    // Variable $decimal stores this steps working value.
    $decimal = $degrees + ($minutes / 60.0) + ($seconds / 3600.0);
    // Variable $referenceText stores this steps working value.
    $referenceText = strtoupper(trim((string) $reference));
    if ($referenceText === 'S' || $referenceText === 'W') {
        $decimal *= -1;
    }
    return round($decimal, 7);
}

/**
 * Normalize common EXIF date strings into MySQL DATETIME format.
 *
 * @param mixed $value Value to process.
 * @return ?string Text result for the caller.
 */
function exif_datetime_to_sql(mixed $value): ?string
{
    // Variable $text stores this steps working value.
    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }
    // Variable $fixed stores this steps working value.
    $fixed = preg_replace('/^(\d{4}):(\d{2}):(\d{2})/', '$1-$2-$3', $text);
    // Variable $timestamp stores this steps working value.
    $timestamp = strtotime((string) $fixed);
    return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
}

/**
 * Format a rational EXIF value with a suffix while preserving useful precision.
 *
 * @param mixed $value Value to process.
 * @param string $suffix Suffix value.
 * @return ?string Text result for the caller.
 */
function exif_format_rational(mixed $value, string $suffix = ''): ?string
{
    // Variable $float stores this steps working value.
    $float = exif_rational_to_float($value);
    if ($float === null) {
        return null;
    }
    // Variable $rounded stores this steps working value.
    $rounded = round($float, 2);
    // Variable $text stores this steps working value.
    $text = rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');
    return $text . $suffix;
}


/**
 * Read one TIFF/DNG field value list for EXIF extraction.
 *
 * @param string $data Input data.
 * @param int $entryOffset Entry offset value.
 * @param string $endian Endian value.
 * @param int $type Type value.
 * @param int $count Count value.
 * @param int $valueOffset Value offset value.
 * @return array<int mixed>.
 */
function exif_dng_tiff_entry_values(string $data, int $entryOffset, string $endian, int $type, int $count, int $valueOffset): array
{
    // $typeSize stores the byte length of one TIFF field item.
    $typeSize = dng_tiff_type_size($type);
    if ($typeSize <= 0 || $count <= 0 || $count > 64) {
        return [];
    }
    // $totalSize stores the byte length occupied by this TIFF entry value.
    $totalSize = $typeSize * $count;
    // $dataOffset stores either the inline value area or the referenced value area.
    $dataOffset = $totalSize <= 4 ? $entryOffset + 8 : $valueOffset;
    if ($dataOffset < 0 || $dataOffset + $totalSize > strlen($data)) {
        return [];
    }
    if ($type === 2) {
        return [rtrim(substr($data, $dataOffset, $count), " ")];
    }

    // $values stores parsed TIFF values as PHP scalars.
    $values = [];
    for ($index = 0; $index < $count; $index++) {
        // $offset stores the byte position of the current value.
        $offset = $dataOffset + ($index * $typeSize);
        if ($type === 1) {
            $values[] = ord($data[$offset]);
        } elseif ($type === 3) {
            $value = dng_tiff_uint16($data, $offset, $endian);
            if ($value !== null) {
                $values[] = $value;
            }
        } elseif ($type === 4) {
            $value = dng_tiff_uint32($data, $offset, $endian);
            if ($value !== null) {
                $values[] = $value;
            }
        } elseif ($type === 5) {
            $numerator = dng_tiff_uint32($data, $offset, $endian);
            $denominator = dng_tiff_uint32($data, $offset + 4, $endian);
            if ($numerator !== null && $denominator !== null && $denominator !== 0) {
                $values[] = $numerator . '/' . $denominator;
            }
        }
    }
    return $values;
}

/**
 * Read all entries from one TIFF/DNG image file directory.
 *
 * @param string $data Input data.
 * @param int $ifdOffset Ifd offset value.
 * @param string $endian Endian value.
 * @return array<int array<int, mixed>>.
 */
function exif_dng_tiff_ifd_entries(string $data, int $ifdOffset, string $endian): array
{
    if ($ifdOffset <= 0 || $ifdOffset + 2 > strlen($data)) {
        return [];
    }
    // $entryCount stores how many TIFF entries the directory contains.
    $entryCount = dng_tiff_uint16($data, $ifdOffset, $endian);
    if ($entryCount === null || $entryCount <= 0 || $entryCount > 2048) {
        return [];
    }
    // $entries stores tag-id keyed parsed TIFF field values.
    $entries = [];
    for ($index = 0; $index < $entryCount; $index++) {
        // $entryOffset stores the byte offset of this 12-byte TIFF entry.
        $entryOffset = $ifdOffset + 2 + ($index * 12);
        if ($entryOffset + 12 > strlen($data)) {
            break;
        }
        $tag = dng_tiff_uint16($data, $entryOffset, $endian);
        $type = dng_tiff_uint16($data, $entryOffset + 2, $endian);
        $count = dng_tiff_uint32($data, $entryOffset + 4, $endian);
        $valueOffset = dng_tiff_uint32($data, $entryOffset + 8, $endian);
        if ($tag === null || $type === null || $count === null || $valueOffset === null) {
            continue;
        }
        $entries[$tag] = exif_dng_tiff_entry_values($data, $entryOffset, $endian, $type, $count, $valueOffset);
    }
    return $entries;
}

/**
 * Extract GPS fields from a DNG/TIFF source without requiring PHP exif_read_data DNG support.
 *
 * @param string $path Filesystem path.
 * @return array{gps_lat:?float,gps_lng:?float,gps_altitude:?float,gps_extracted_at:?string} Structured result data for the caller.
 */
function extract_dng_gps_metadata(string $path): array
{
    $empty = ['gps_lat' => null, 'gps_lng' => null, 'gps_altitude' => null, 'gps_extracted_at' => null];
    if (!is_file($path) || filesize($path) === false || (int) filesize($path) > 220 * 1024 * 1024) {
        return $empty;
    }
    $data = @file_get_contents($path);
    if (!is_string($data) || strlen($data) < 8) {
        return $empty;
    }
    $endian = substr($data, 0, 2);
    if (!in_array($endian, ['II', 'MM'], true) || dng_tiff_uint16($data, 2, $endian) !== 42) {
        return $empty;
    }
    $firstIfdOffset = dng_tiff_uint32($data, 4, $endian);
    if ($firstIfdOffset === null) {
        return $empty;
    }
    $ifd0 = exif_dng_tiff_ifd_entries($data, $firstIfdOffset, $endian);
    $gpsIfdOffset = (int) ($ifd0[0x8825][0] ?? 0);
    if ($gpsIfdOffset <= 0) {
        return $empty;
    }
    $gps = exif_dng_tiff_ifd_entries($data, $gpsIfdOffset, $endian);
    $latitude = exif_gps_coordinate_to_decimal($gps[2] ?? null, $gps[1][0] ?? null);
    $longitude = exif_gps_coordinate_to_decimal($gps[4] ?? null, $gps[3][0] ?? null);
    $altitude = exif_rational_to_float($gps[6][0] ?? null);
    if ((int) ($gps[5][0] ?? 0) === 1 && $altitude !== null) {
        $altitude *= -1;
    }
    return [
        'gps_lat' => $latitude,
        'gps_lng' => $longitude,
        'gps_altitude' => $altitude === null ? null : round($altitude, 2),
        'gps_extracted_at' => ($latitude !== null && $longitude !== null) ? now_sql() : null,
    ];
}

/**
 * Extract safe public EXIF/GPS fields from a source image file.
 *
 * Missing EXIF support, unsupported formats, corrupt metadata, and missing GPS
 * fields all return nullable values instead of failing the scan.
 *
 * @param string $path Filesystem path.
 * @return array Structured result data for the caller.
 */
function extract_image_exif_metadata(string $path): array
{
    // Variable $empty stores this steps working value.
    $empty = [
        'exif_taken_at' => null,
        'exif_camera_make' => null,
        'exif_camera_model' => null,
        'exif_lens_model' => null,
        'exif_focal_length' => null,
        'exif_aperture' => null,
        'exif_exposure_time' => null,
        'exif_iso' => null,
        'gps_lat' => null,
        'gps_lng' => null,
        'gps_altitude' => null,
        'gps_extracted_at' => null,
    ];
    // Variable $extension stores this steps working value.
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($extension === 'dng') {
        return array_merge($empty, extract_dng_gps_metadata($path));
    }
    if (!function_exists('exif_read_data')) {
        return $empty;
    }
    if (!in_array($extension, ['jpg', 'jpeg', 'tif', 'tiff'], true)) {
        return $empty;
    }
    try {
        // Variable $exif stores this steps working value.
        $exif = @exif_read_data($path, null, true, false);
    } catch (Throwable) {
        return $empty;
    }
    if (!is_array($exif)) {
        return $empty;
    }
    // Variable $ifd0 stores this steps working value.
    $ifd0 = is_array($exif['IFD0'] ?? null) ? $exif['IFD0'] : [];
    // Variable $exifData stores this steps working value.
    $exifData = is_array($exif['EXIF'] ?? null) ? $exif['EXIF'] : [];
    // Variable $gpsData stores this steps working value.
    $gpsData = is_array($exif['GPS'] ?? null) ? $exif['GPS'] : [];
    // Variable $latitude stores this steps working value.
    $latitude = exif_gps_coordinate_to_decimal($gpsData['GPSLatitude'] ?? null, $gpsData['GPSLatitudeRef'] ?? null);
    // Variable $longitude stores this steps working value.
    $longitude = exif_gps_coordinate_to_decimal($gpsData['GPSLongitude'] ?? null, $gpsData['GPSLongitudeRef'] ?? null);
    // Variable $altitude stores this steps working value.
    $altitude = exif_rational_to_float($gpsData['GPSAltitude'] ?? null);
    if ((string) ($gpsData['GPSAltitudeRef'] ?? '') === '1' && $altitude !== null) {
        $altitude *= -1;
    }
    return [
        'exif_taken_at' => exif_datetime_to_sql($exifData['DateTimeOriginal'] ?? $exifData['DateTimeDigitized'] ?? $ifd0['DateTime'] ?? null),
        'exif_camera_make' => isset($ifd0['Make']) ? substr(trim((string) $ifd0['Make']), 0, 128) : null,
        'exif_camera_model' => isset($ifd0['Model']) ? substr(trim((string) $ifd0['Model']), 0, 128) : null,
        'exif_lens_model' => isset($exifData['LensModel']) ? substr(trim((string) $exifData['LensModel']), 0, 128) : null,
        'exif_focal_length' => exif_format_rational($exifData['FocalLength'] ?? null, ' mm'),
        'exif_aperture' => exif_format_rational($exifData['FNumber'] ?? null, ''),
        'exif_exposure_time' => isset($exifData['ExposureTime']) ? substr(trim((string) $exifData['ExposureTime']), 0, 64) : null,
        'exif_iso' => isset($exifData['ISOSpeedRatings']) ? (int) (is_array($exifData['ISOSpeedRatings']) ? reset($exifData['ISOSpeedRatings']) : $exifData['ISOSpeedRatings']) : null,
        'gps_lat' => $latitude,
        'gps_lng' => $longitude,
        'gps_altitude' => $altitude === null ? null : round($altitude, 2),
        'gps_extracted_at' => ($latitude !== null && $longitude !== null) ? now_sql() : null,
    ];
}

/**
 * Return true when an image record contains usable GPS coordinates.
 *
 * @param array $image Image row or image data.
 * @return bool True when the condition matches.
 */
function image_has_gps(array $image): bool
{
    return isset($image['gps_lat'], $image['gps_lng']) && $image['gps_lat'] !== null && $image['gps_lng'] !== null;
}

/**
 * Convert one image record into the map marker shape consumed by JavaScript.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param bool $includeThumb Include thumb value.
 * @param ?array $thumbnailBundle Thumbnail bundle value.
 * @return array Structured result data for the caller.
 */
function image_map_point(array $image, array $gallery, bool $includeThumb = true, ?array $thumbnailBundle = null): array
{
    // $displayTitle stores the public-facing label used by map popups and dialogs.
    $displayTitle = trim(public_image_display_title($image, $gallery));
    // $filename stores the raw uploaded filename so public maps can avoid showing it as a title.
    $filename = trim((string) ($image['filename'] ?? ''));
    // $filenameStem stores the raw filename without an extension for legacy imported titles.
    $filenameStem = trim((string) pathinfo($filename, PATHINFO_FILENAME));
    if ($displayTitle === $filename || $displayTitle === $filenameStem) {
        $displayTitle = '';
    }
    if ($displayTitle === '') {
        $displayTitle = trim((string) ($gallery['title'] ?? ''));
    }
    if ($displayTitle === '') {
        $displayTitle = $filenameStem;
    }
    // $point stores the lightweight marker payload consumed by JavaScript maps.
    $point = [
        'id' => (int) $image['id'],
        'lat' => (float) $image['gps_lat'],
        'lng' => (float) $image['gps_lng'],
        'title' => $displayTitle,
        'description' => (string) ($image['description'] ?? ''),
        'image' => url_for('media', ['id' => $image['id']]),
        'gallery' => (string) $gallery['title'],
        'type' => 'photo_point',
        'point_type' => 'photo_point',
        'source_type' => GALLERY_MAP_SOURCE_EXIF_POINT,
        'map_source_type' => GALLERY_MAP_SOURCE_EXIF_POINT,
    ];
    if ($includeThumb) {
        $thumbnailBundle = $thumbnailBundle ?: public_render_profile_with_thumbnail_purpose('map point bundle discovery', static fn (): array => thumbnail_bundle($image));
        $point['thumb'] = public_render_profile_with_thumbnail_purpose('map point thumb 300', static fn (): string => thumbnail_bundle_url($thumbnailBundle, 300));
    }
    return $point;
}

/**
 * Return the cache directory used for lazily generated gallery map point payloads.
 *
 * @return string Text result for the caller.
 */
function gallery_map_cache_dir(): string
{
    // $basePath stores the writable application cache directory used by generated map metadata.
    $basePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'gallery-maps';
    if (!is_dir($basePath)) {
        @mkdir($basePath, 0775, true);
    }
    return rtrim($basePath, DIRECTORY_SEPARATOR);
}

/**
 * Build the SQL WHERE parts and parameters shared by map availability and map payload generation.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param bool $publicOnly Public only value.
 * @param bool $recursive Recursive value.
 * @return array Structured result data for the caller.
 */
function gallery_map_query_parts(array $gallery, bool $publicOnly, bool $recursive): array
{
    // $folderPath stores the normalized branch root used by recursive map queries.
    $folderPath = normalize_relative_path((string) $gallery['folder_path']);
    // $conditions stores the SQL filters for images that can produce map markers.
    $conditions = ["i.gps_lat IS NOT NULL", "i.gps_lng IS NOT NULL"];
    // $params stores positional query parameters matching the generated conditions.
    $params = [];
    if ($recursive) {
        $conditions[] = '(g.folder_path = ? OR g.folder_path LIKE ?)';
        $params[] = $folderPath;
        $params[] = $folderPath . '/%';
    } else {
        $conditions[] = 'g.id = ?';
        $params[] = (int) $gallery['id'];
    }
    if ($publicOnly) {
        $conditions[] = public_gallery_listing_sql_fragment('g');
        $conditions[] = "i.visibility = 'public'";
    }
    return ['conditions' => $conditions, 'params' => $params];
}

/**
 * Return a cheap fingerprint for one gallery map payload.
 *
 * The fingerprint changes when GPS-capable images or their containing galleries
 * change. This gives the map cache deterministic invalidation without requiring
 * every upload, edit, delete, and move workflow to remember a separate cache call.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param bool $publicOnly Public only value.
 * @param bool $recursive Recursive value.
 * @return string Text result for the caller.
 */
function gallery_map_cache_fingerprint(array $gallery, bool $publicOnly, bool $recursive): string
{
    if (!gallery_allows_gps_maps($gallery)) {
        return 'disabled';
    }
    $parts = gallery_map_query_parts($gallery, $publicOnly, $recursive);
    $sql = 'SELECT COUNT(*) AS point_count, MAX(i.updated_at) AS image_updated_at, MAX(i.gps_extracted_at) AS gps_extracted_at, MAX(g.updated_at) AS gallery_updated_at FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE ' . implode(' AND ', $parts['conditions']);
    $row = public_render_profile_db('gallery_map_fingerprint', static function () use ($sql, $parts): array {
        $stmt = db()->prepare($sql);
        $stmt->execute($parts['params']);
        return $stmt->fetch() ?: [];
    });
    return hash('sha256', json_encode([
        'gallery_id' => (int) $gallery['id'],
        'payload_version' => 2,
        'public_only' => $publicOnly,
        'recursive' => $recursive,
        'point_count' => (int) ($row['point_count'] ?? 0),
        'image_updated_at' => (string) ($row['image_updated_at'] ?? ''),
        'gps_extracted_at' => (string) ($row['gps_extracted_at'] ?? ''),
        'gallery_updated_at' => (string) ($row['gallery_updated_at'] ?? ''),
    ], JSON_UNESCAPED_SLASHES));
}

/**
 * Return the cache file path for one gallery map point payload.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param bool $publicOnly Public only value.
 * @param bool $recursive Recursive value.
 * @param string $fingerprint Fingerprint value.
 * @return string Text result for the caller.
 */
function gallery_map_cache_file(array $gallery, bool $publicOnly, bool $recursive, string $fingerprint): string
{
    // $mode stores whether the payload uses anonymous or logged-in access rules.
    $mode = $publicOnly ? 'public' : 'admin';
    return gallery_map_cache_dir() . DIRECTORY_SEPARATOR . 'gallery-' . (int) $gallery['id'] . '-' . $mode . '-' . ($recursive ? 'recursive' : 'direct') . '-' . $fingerprint . '.json';
}

/**
 * Remove older cache files for one gallery map payload family after writing a fresh payload.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param bool $publicOnly Public only value.
 * @param bool $recursive Recursive value.
 * @param string $keepFile Keep file value.
 */
function gallery_map_cache_prune(array $gallery, bool $publicOnly, bool $recursive, string $keepFile): void
{
    // $mode stores whether the payload uses anonymous or logged-in access rules.
    $mode = $publicOnly ? 'public' : 'admin';
    // $pattern stores all previous fingerprints for this gallery and access mode.
    $pattern = gallery_map_cache_dir() . DIRECTORY_SEPARATOR . 'gallery-' . (int) $gallery['id'] . '-' . $mode . '-' . ($recursive ? 'recursive' : 'direct') . '-*.json';
    foreach (glob($pattern) ?: [] as $filePath) {
        if ($filePath !== $keepFile && is_file($filePath)) {
            @unlink($filePath);
        }
    }
}

/**
 * Clear generated gallery map payload cache files.
 *
 * Thumbnail maintenance calls this because cached marker popup thumbnails can
 * otherwise keep pointing at a fallback URL after thumbnails are regenerated.
 */
function gallery_map_cache_clear_all(): void
{
    foreach (glob(gallery_map_cache_dir() . DIRECTORY_SEPARATOR . 'gallery-*.json') ?: [] as $filePath) {
        if (is_file($filePath)) {
            @unlink($filePath);
        }
    }
}

/**
 * Return true when a gallery branch has at least one map point without building the full marker payload.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param bool $publicOnly Public only value.
 * @param bool $recursive Recursive value.
 * @return bool True when the condition matches.
 */
function gallery_has_map_points(array $gallery, bool $publicOnly, bool $recursive = true): bool
{
    if (!gallery_allows_gps_maps($gallery)) {
        return false;
    }
    return public_render_profile_span('gallery_map_availability', static function () use ($gallery, $publicOnly, $recursive): bool {
        $parts = gallery_map_query_parts($gallery, $publicOnly, $recursive);
        $sql = 'SELECT 1 FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE ' . implode(' AND ', $parts['conditions']) . ' LIMIT 1';
        return public_render_profile_db('gallery_map_availability_db', static function () use ($sql, $parts): bool {
            $stmt = db()->prepare($sql);
            $stmt->execute($parts['params']);
            return (bool) $stmt->fetchColumn();
        });
    });
}

/**
 * Return true when a gallery can open the shared map viewer from any source.
 *
 * EXIF GPS keeps the existing photo-point behavior. A stored flight path belongs
 * to the gallery container itself and is already resolved before display.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param bool $publicOnly Public only value.
 * @param bool $recursive Recursive value.
 * @return bool True when the condition matches.
 */
function gallery_has_map_payload(array $gallery, bool $publicOnly, bool $recursive = true): bool
{
    if (function_exists('Gallery\\Services\\feature_flag_enabled') && feature_flag_enabled('flight_maps') && function_exists('Gallery\\Services\\gallery_has_flight_path_map') && gallery_has_flight_path_map($gallery)) {
        return true;
    }

    return gallery_has_map_points($gallery, $publicOnly, $recursive);
}

/**
 * Return the unified map payload consumed by the browser Leaflet renderer.
 *
 * A saved flight path takes priority for the gallery-level map because it
 * represents the whole simflying gallery. When GPS photo points are available,
 * they are layered onto the route without changing the stored route geometry.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param bool $publicOnly Public only value.
 * @param bool $recursive Recursive value.
 * @return array Structured result data for the caller.
 */
function gallery_map_payload(array $gallery, bool $publicOnly, bool $recursive = true): array
{
    if (function_exists('Gallery\\Services\\feature_flag_enabled') && feature_flag_enabled('flight_maps') && function_exists('Gallery\\Services\\gallery_flight_map_payload')) {
        $flightPayload = gallery_flight_map_payload($gallery);
        if (is_array($flightPayload) && !empty($flightPayload['points'])) {
            $photoPoints = gallery_map_points($gallery, $publicOnly, $recursive);
            if ($photoPoints) {
                $flightPayload['route_points'] = $flightPayload['geometry']['points'] ?? $flightPayload['points'];
                $flightPayload['photo_points'] = $photoPoints;
                $flightPayload['points'] = array_merge($flightPayload['points'], $photoPoints);
                $flightPayload['map_source_type'] = 'mixed';
            }
            return $flightPayload;
        }
    }

    $points = gallery_map_points($gallery, $publicOnly, $recursive);
    return [
        'gallery_id' => (int) $gallery['id'],
        'title' => (string) $gallery['title'],
        'source_type' => GALLERY_MAP_SOURCE_EXIF_POINT,
        'map_source_type' => GALLERY_MAP_SOURCE_EXIF_POINT,
        'points' => $points,
        'geometry' => null,
    ];
}

/**
 * Return GPS map points for one gallery, optionally including subgalleries.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param bool $publicOnly Public only value.
 * @param bool $recursive Recursive value.
 * @return array Structured result data for the caller.
 */
function gallery_map_points(array $gallery, bool $publicOnly, bool $recursive = true): array
{
    if (!gallery_allows_gps_maps($gallery)) {
        return [];
    }

    return public_render_profile_span('gallery_map_points', static function () use ($gallery, $publicOnly, $recursive): array {
        // $fingerprint stores the cache identity derived from current image and gallery metadata.
        $fingerprint = gallery_map_cache_fingerprint($gallery, $publicOnly, $recursive);
        // $cacheFile stores the concrete JSON payload path for this gallery map.
        $cacheFile = gallery_map_cache_file($gallery, $publicOnly, $recursive, $fingerprint);
        if (is_file($cacheFile)) {
            public_render_profile_count('gallery_map_cache_hits');
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        public_render_profile_count('gallery_map_cache_misses');
        $parts = gallery_map_query_parts($gallery, $publicOnly, $recursive);
        $sql = 'SELECT i.*, g.title AS gallery_title, g.id AS gallery_id, g.folder_path AS gallery_folder_path FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE ' . implode(' AND ', $parts['conditions']) . ' ORDER BY g.folder_path, i.sort_order, i.filename';
        $rows = public_render_profile_db('gallery_map_points_db', static function () use ($sql, $parts): array {
            $stmt = db()->prepare($sql);
            $stmt->execute($parts['params']);
            return $stmt->fetchAll();
        });

        // $galleryCache stores looked-up gallery records while building this map payload.
        $galleryCache = [(int) $gallery['id'] => $gallery];
        // $points stores the marker payload consumed by the browser map overlay.
        $points = [];
        foreach ($rows as $image) {
            $imageGalleryId = (int) $image['gallery_id'];
            if (!array_key_exists($imageGalleryId, $galleryCache)) {
                $galleryCache[$imageGalleryId] = find_gallery($imageGalleryId) ?: $gallery;
            }
            $imageGallery = $galleryCache[$imageGalleryId];
            if (!gallery_allows_gps_maps($imageGallery) || ($publicOnly && !public_image_visible_to_current_visitor($image, $imageGallery))) {
                continue;
            }
            $points[] = image_map_point($image, $imageGallery, true);
        }

        if (is_dir(gallery_map_cache_dir())) {
            @file_put_contents($cacheFile, json_encode($points, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            gallery_map_cache_prune($gallery, $publicOnly, $recursive, $cacheFile);
        }
        return $points;
    });
}

