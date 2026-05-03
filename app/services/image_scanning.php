<?php

declare(strict_types=1);

/**
 * Image scanning model.
 * 
 * This module discovers image files on disk and reconciles them into database rows. It does not render public pages and does not modify theme or visual settings.
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
    foreach (new DirectoryIterator($root) as $file) {
        if (!$file->isFile() || !is_supported_image_path($file->getFilename())) {
            continue;
        }
        // Variable $relative stores this steps working value.
        $relative = normalize_relative_path(substr($file->getPathname(), strlen($root)));
        // Variable $info stores this steps working value.
        $info = @getimagesize($file->getPathname());
        if ($info === false || empty($info['mime']) || !str_starts_with((string) $info['mime'], 'image/')) {
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
                $stmt = $pdo->prepare('INSERT INTO images (gallery_id, relative_path, relative_path_hash, filename, title, width, height, mime_type, file_size, modified_at, exif_taken_at, exif_camera_make, exif_camera_model, exif_lens_model, exif_focal_length, exif_aperture, exif_exposure_time, exif_iso, gps_lat, gps_lng, gps_altitude, gps_extracted_at, checksum_sha256, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $galleryId,
                    $relative,
                    hash('sha256', $relative),
                    $file->getFilename(),
                    pathinfo($file->getFilename(), PATHINFO_FILENAME),
                    (int) $info[0],
                    (int) $info[1],
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
                    now_sql(),
                ]);
            } else {
                // Variable $stmt stores this steps working value.
                $stmt = $pdo->prepare('INSERT INTO images (gallery_id, relative_path, relative_path_hash, filename, title, width, height, mime_type, file_size, modified_at, checksum_sha256, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $galleryId,
                    $relative,
                    hash('sha256', $relative),
                    $file->getFilename(),
                    pathinfo($file->getFilename(), PATHINFO_FILENAME),
                    (int) $info[0],
                    (int) $info[1],
                    (string) $info['mime'],
                    $file->getSize(),
                    $modifiedAt,
                    hash_file('sha256', $file->getPathname()) ?: null,
                    now_sql(),
                    now_sql(),
                ]);
            }
            $count++;
            continue;
        }
        if ((int) $existing['file_size'] !== $file->getSize() || (string) $existing['modified_at'] !== $modifiedAt || ($exifSchemaReady && ($existing['gps_extracted_at'] ?? null) === null)) {
            if ($exifSchemaReady) {
                // Variable $stmt stores this steps working value.
                $stmt = $pdo->prepare('UPDATE images SET filename = ?, width = ?, height = ?, mime_type = ?, file_size = ?, modified_at = ?, exif_taken_at = ?, exif_camera_make = ?, exif_camera_model = ?, exif_lens_model = ?, exif_focal_length = ?, exif_aperture = ?, exif_exposure_time = ?, exif_iso = ?, gps_lat = ?, gps_lng = ?, gps_altitude = ?, gps_extracted_at = ?, checksum_sha256 = ?, updated_at = ? WHERE id = ?');
                $stmt->execute([
                    $file->getFilename(),
                    (int) $info[0],
                    (int) $info[1],
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
                    (int) $info[0],
                    (int) $info[1],
                    (string) $info['mime'],
                    $file->getSize(),
                    $modifiedAt,
                    hash_file('sha256', $file->getPathname()) ?: null,
                    now_sql(),
                    (int) $existing['id'],
                ]);
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

function scan_all_imported_gallery_images(): array
{
    $scanned = 0;
    $changed = 0;
    $galleryIds = db()->query('SELECT id FROM galleries ORDER BY folder_path')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($galleryIds as $galleryId) {
        $current = scan_gallery_images((int) $galleryId);
        $scanned++;
        $changed += $current;
    }
    return ['galleries' => $scanned, 'images' => $changed];
}

