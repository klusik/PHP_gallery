<?php

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
function image_map_point(array $image, array $gallery): array
{
    return [
        'id' => (int) $image['id'],
        'lat' => (float) $image['gps_lat'],
        'lng' => (float) $image['gps_lng'],
        'title' => (string) ($image['title'] ?: $image['filename']),
        'description' => (string) ($image['description'] ?? ''),
        'thumb' => thumbnail_url($image, 300),
        'image' => url_for('media', ['id' => $image['id']]),
        'gallery' => (string) $gallery['title'],
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
    // Variable $folderPath stores this steps working value.
    $folderPath = normalize_relative_path((string) $gallery['folder_path']);
    // Variable $conditions stores this steps working value.
    $conditions = ["i.gps_lat IS NOT NULL", "i.gps_lng IS NOT NULL"];
    // Variable $params stores this steps working value.
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
    // Variable $sql stores this steps working value.
    $sql = 'SELECT i.*, g.title AS gallery_title, g.id AS gallery_id, g.folder_path AS gallery_folder_path FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE ' . implode(' AND ', $conditions) . ' ORDER BY g.folder_path, i.sort_order, i.filename';
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    // Variable $points stores this steps working value.
    $points = [];
    foreach ($stmt->fetchAll() as $image) {
        // Variable $imageGallery stores this steps working value.
        $imageGallery = find_gallery((int) $image['gallery_id']) ?: $gallery;
        if (!gallery_allows_gps_maps($imageGallery) || ($publicOnly && !visitor_can_access_gallery($imageGallery))) {
            continue;
        }
        $points[] = image_map_point($image, $imageGallery);
    }
    return $points;
}
