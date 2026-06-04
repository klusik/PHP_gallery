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
 */
function exif_gps_schema_ready(): bool
{
    try {
        // Variable $galleryColumn stores this steps working value.
        $galleryColumn = db()->query("SHOW COLUMNS FROM galleries LIKE 'gps_map_enabled'");
        if (!$galleryColumn || !$galleryColumn->fetch()) {
            return false;
        }
        // Variable $imageColumn stores this steps working value.
        $imageColumn = db()->query("SHOW COLUMNS FROM images LIKE 'gps_lat'");
        return $imageColumn && (bool) $imageColumn->fetch();
    } catch (PDOException) {
        return false;
    }
}

/**
 * Return whether maps are enabled for one gallery branch.
 *
 * A gallery allows maps when it or any ancestor has GPS maps enabled. This keeps
 * the control recursive without needing to copy settings into every descendant.
 */
function gallery_allows_gps_maps(array $gallery): bool
{
    if (function_exists('feature_flag_enabled') && !feature_flag_enabled('gallery_maps')) {
        return false;
    }
    if (!exif_gps_schema_ready()) {
        return false;
    }
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
 * Convert one EXIF rational value such as 35/10 into a float.
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
 * Extract safe public EXIF/GPS fields from a source image file.
 *
 * Missing EXIF support, unsupported formats, corrupt metadata, and missing GPS
 * fields all return nullable values instead of failing the scan.
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
    if (!function_exists('exif_read_data')) {
        return $empty;
    }
    // Variable $extension stores this steps working value.
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
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
 */
function image_has_gps(array $image): bool
{
    return isset($image['gps_lat'], $image['gps_lng']) && $image['gps_lat'] !== null && $image['gps_lng'] !== null;
}

/**
 * Convert one image record into the map marker shape consumed by JavaScript.
 */
function image_map_point(array $image, array $gallery, bool $includeThumb = true, ?array $thumbnailBundle = null): array
{
    // $point stores the lightweight marker payload consumed by JavaScript maps.
    $point = [
        'id' => (int) $image['id'],
        'lat' => (float) $image['gps_lat'],
        'lng' => (float) $image['gps_lng'],
        'title' => (string) ($image['title'] ?: $image['filename']),
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
        $conditions[] = public_gallery_listing_condition('g');
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
 */
function gallery_map_cache_file(array $gallery, bool $publicOnly, bool $recursive, string $fingerprint): string
{
    // $mode stores whether the payload uses anonymous or logged-in access rules.
    $mode = $publicOnly ? 'public' : 'admin';
    return gallery_map_cache_dir() . DIRECTORY_SEPARATOR . 'gallery-' . (int) $gallery['id'] . '-' . $mode . '-' . ($recursive ? 'recursive' : 'direct') . '-' . $fingerprint . '.json';
}

/**
 * Remove older cache files for one gallery map payload family after writing a fresh payload.
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
 */
function gallery_has_map_payload(array $gallery, bool $publicOnly, bool $recursive = true): bool
{
    if (function_exists('feature_flag_enabled') && feature_flag_enabled('flight_maps') && function_exists('gallery_has_flight_path_map') && gallery_has_flight_path_map($gallery)) {
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
 */
function gallery_map_payload(array $gallery, bool $publicOnly, bool $recursive = true): array
{
    if (function_exists('feature_flag_enabled') && feature_flag_enabled('flight_maps') && function_exists('gallery_flight_map_payload')) {
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

