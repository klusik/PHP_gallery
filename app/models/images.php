<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/images.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns reusable image-row persistence that is independent of HTTP and presentation.
 *
 * Responsibilities:
 *   - Persist bulk image visibility and NSFW state
 *   - Count direct image rows belonging to one gallery
 *   - Keep image-table SQL out of controllers and service orchestration
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
 *   - Callers must pass already-normalized positive image identifiers.
 *
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Models;

use InvalidArgumentException;
use function Gallery\Core\db;

/**
 * Update one explicitly supported scalar image column for a set of rows.
 *
 * @param array<int,int> $imageIds Positive image identifiers.
 * @param string $column Supported images column name.
 * @param mixed $value Scalar value written to every selected image.
 * @param string $now Shared SQL timestamp for every touched row.
 * @return int Number of rows reported as changed by the database driver.
 */
function image_model_update_scalar_for_ids(array $imageIds, string $column, mixed $value, string $now): int
{
    if ($imageIds === []) {
        return 0;
    }

    if (!in_array($column, ['visibility', 'nsfw_enabled'], true)) {
        throw new InvalidArgumentException('Unsupported image bulk-update column.');
    }

    $placeholders = implode(',', array_fill(0, count($imageIds), '?'));
    $stmt = db()->prepare('UPDATE images SET ' . $column . ' = ?, updated_at = ? WHERE id IN (' . $placeholders . ')');
    $stmt->execute(array_merge([$value, $now], $imageIds));
    return $stmt->rowCount();
}

/**
 * Persist one visibility value for selected images.
 *
 * @param array<int,int> $imageIds Positive image identifiers.
 * @param string $visibility Storage visibility value.
 * @param string $now Shared SQL timestamp.
 * @return int Number of rows reported as changed.
 */
function image_model_set_visibility(array $imageIds, string $visibility, string $now): int
{
    return image_model_update_scalar_for_ids($imageIds, 'visibility', $visibility, $now);
}

/**
 * Persist NSFW Guard state for selected images.
 *
 * @param array<int,int> $imageIds Positive image identifiers.
 * @param bool $enabled Whether the selected images require NSFW access.
 * @param string $now Shared SQL timestamp.
 * @return int Number of rows reported as changed.
 */
function image_model_set_nsfw_enabled(array $imageIds, bool $enabled, string $now): int
{
    return image_model_update_scalar_for_ids($imageIds, 'nsfw_enabled', $enabled ? 1 : 0, $now);
}

/**
 * Count direct image rows stored in one gallery.
 *
 * @param int $galleryId Gallery identifier.
 * @return int Number of direct image rows.
 */
function image_model_count_for_gallery(int $galleryId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM images WHERE gallery_id = ?');
    $stmt->execute([$galleryId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Persist a semantic set of editable image fields for one image row.
 *
 * @param int $imageId Image identifier.
 * @param array<string,mixed> $fields Column-value map prepared by service/controller orchestration.
 * @param string $now SQL timestamp written to updated_at.
 */
function image_model_update_fields(int $imageId, array $fields, string $now): void
{
    if ($imageId <= 0 || $fields === []) {
        return;
    }

    $allowedColumns = [
        'title',
        'description',
        'visibility',
        'sort_order',
        'editorial_rating',
        'nsfw_enabled',
        'thumbnail_min_size',
        'thumbnail_max_size',
    ];

    $assignments = [];
    $params = [];
    foreach ($fields as $column => $value) {
        if (!is_string($column) || !in_array($column, $allowedColumns, true)) {
            throw new InvalidArgumentException('Unsupported image update field.');
        }
        $assignments[] = $column . ' = ?';
        $params[] = $value;
    }
    $assignments[] = 'updated_at = ?';
    $params[] = $now;
    $params[] = $imageId;

    $stmt = db()->prepare('UPDATE images SET ' . implode(', ', $assignments) . ' WHERE id = ?');
    $stmt->execute($params);
}

/**
 * Return grouped direct image counts for selected galleries.
 *
 * @param array<int,int> $galleryIds Positive gallery identifiers.
 * @param bool $publicOnly Whether only public image rows are counted.
 * @return array<int,int> Direct image counts keyed by gallery id.
 */
function image_model_counts_for_galleries(array $galleryIds, bool $publicOnly): array
{
    if ($galleryIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
    $sql = 'SELECT gallery_id, COUNT(*) AS image_count FROM images WHERE gallery_id IN (' . $placeholders . ')';
    $params = $galleryIds;
    if ($publicOnly) {
        $sql .= ' AND visibility = ?';
        $params[] = 'public';
    }
    $sql .= ' GROUP BY gallery_id';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        $galleryId = (int) ($row['gallery_id'] ?? 0);
        if ($galleryId > 0) {
            $counts[$galleryId] = (int) ($row['image_count'] ?? 0);
        }
    }
    return $counts;
}

/**
 * Fetch one image row by numeric identifier.
 *
 * @param int $imageId Image identifier.
 * @return array<string,mixed>|null Matching row.
 */
function image_model_find_by_id(int $imageId): ?array
{
    $stmt = db()->prepare('SELECT * FROM images WHERE id = ?');
    $stmt->execute([$imageId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Fetch one image row by gallery id and normalized relative-path hash.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $relativePathHash SHA-256 normalized relative path hash.
 * @return array<string,mixed>|null Matching row.
 */
function image_model_find_by_path_hash(int $galleryId, string $relativePathHash): ?array
{
    $stmt = db()->prepare('SELECT * FROM images WHERE gallery_id = ? AND relative_path_hash = ?');
    $stmt->execute([$galleryId, $relativePathHash]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Return direct image rows for one gallery in display order.
 *
 * @param int $galleryId Gallery identifier.
 * @param bool $publicOnly Whether only public image rows are returned.
 * @return array<int,array<string,mixed>> Direct image rows.
 */
function image_model_rows_for_gallery(int $galleryId, bool $publicOnly): array
{
    $sql = "SELECT * FROM images WHERE gallery_id = ? AND relative_path NOT LIKE '%/%'";
    if ($publicOnly) {
        $sql .= " AND visibility = 'public'";
    }
    $sql .= ' ORDER BY sort_order, filename';
    $stmt = db()->prepare($sql);
    $stmt->execute([$galleryId]);
    return $stmt->fetchAll();
}

/**
 * Return the preferred direct cover candidate for one gallery.
 *
 * @param int $galleryId Gallery identifier.
 * @param bool $publicOnly Whether the candidate must be public.
 * @return array<string,mixed>|null Preferred direct image row.
 */
function image_model_first_direct_cover_candidate(int $galleryId, bool $publicOnly): ?array
{
    $sql = "SELECT * FROM images WHERE gallery_id = ? AND relative_path NOT LIKE '%/%'";
    if ($publicOnly) {
        $sql .= " AND visibility = 'public'";
    }
    $sql .= " ORDER BY CASE WHEN visibility = 'public' THEN 0 ELSE 1 END, sort_order, filename LIMIT 1";
    $stmt = db()->prepare($sql);
    $stmt->execute([$galleryId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Return direct EXIF date-range aggregates grouped by gallery.
 *
 * @return array<int,array<string,mixed>> Gallery-level EXIF date aggregates.
 */
function image_model_gallery_exif_date_ranges(): array
{
    $sql = "SELECT gallery_id, MIN(DATE(exif_taken_at)) AS suggested_start, MAX(DATE(exif_taken_at)) AS suggested_end, COUNT(*) AS image_count
        FROM images
        WHERE exif_taken_at IS NOT NULL AND exif_taken_at > '1000-01-01 00:00:00'
        GROUP BY gallery_id";
    return db()->query($sql)->fetchAll();
}
/**
 * Persist scanner-owned display metadata fields for one image row.
 *
 * The caller performs schema capability checks before passing fields so this
 * primitive can remain independent of migration and feature policy services.
 *
 * @param int $imageId Image identifier.
 * @param array<string,mixed> $fields Scanner display metadata values.
 */
function image_model_update_scan_display_metadata(int $imageId, array $fields): void
{
    if ($imageId <= 0 || $fields === []) {
        return;
    }

    $allowedColumns = [
        'display_width',
        'display_height',
        'exif_orientation',
        'thumbnail_metadata_refreshed_at',
    ];
    $assignments = [];
    $params = [];
    foreach ($fields as $column => $value) {
        if (!is_string($column) || !in_array($column, $allowedColumns, true)) {
            throw new InvalidArgumentException('Unsupported image scan display field.');
        }
        $assignments[] = '`' . $column . '` = ?';
        $params[] = $value;
    }
    $params[] = $imageId;

    $stmt = db()->prepare('UPDATE images SET ' . implode(', ', $assignments) . ' WHERE id = ?');
    $stmt->execute($params);
}

/**
 * Increment the scanner-owned thumbnail derivative generation for one image.
 *
 * @param int $imageId Image identifier.
 */
function image_model_increment_thumbnail_derivative_version(int $imageId): void
{
    $stmt = db()->prepare('UPDATE images SET thumbnail_derivative_version = thumbnail_derivative_version + 1 WHERE id = ?');
    $stmt->execute([$imageId]);
}

/**
 * Delete compact thumbnail variant metadata for one image.
 *
 * @param int $imageId Image identifier.
 */
function image_model_delete_thumbnail_variants(int $imageId): void
{
    $stmt = db()->prepare('DELETE FROM image_thumbnail_variants WHERE image_id = ?');
    $stmt->execute([$imageId]);
}

/**
 * Insert one scanner-discovered image row.
 *
 * @param array<string,mixed> $fields Schema-aware scanner persistence values.
 * @return int Newly created image identifier.
 */
function image_model_insert_scan_row(array $fields): int
{
    if ($fields === []) {
        throw new InvalidArgumentException('Image scan insert fields cannot be empty.');
    }

    $allowedColumns = [
        'gallery_id',
        'relative_path',
        'relative_path_hash',
        'filename',
        'title',
        'width',
        'height',
        'mime_type',
        'file_size',
        'modified_at',
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
        'checksum_sha256',
        'sort_order',
        'created_at',
        'updated_at',
    ];
    $columns = [];
    $values = [];
    foreach ($fields as $column => $value) {
        if (!is_string($column) || !in_array($column, $allowedColumns, true)) {
            throw new InvalidArgumentException('Unsupported image scan insert field.');
        }
        $columns[] = $column;
        $values[] = $value;
    }

    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO images (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')');
    $stmt->execute($values);
    return (int) $pdo->lastInsertId();
}

/**
 * Update scanner-owned source and EXIF metadata for one image row.
 *
 * @param int $imageId Image identifier.
 * @param array<string,mixed> $fields Schema-aware scanner persistence values.
 * @param string $now SQL timestamp written to updated_at.
 */
function image_model_update_scan_row(int $imageId, array $fields, string $now): void
{
    if ($imageId <= 0 || $fields === []) {
        return;
    }

    $allowedColumns = [
        'filename',
        'width',
        'height',
        'mime_type',
        'file_size',
        'modified_at',
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
        'checksum_sha256',
    ];
    $assignments = [];
    $params = [];
    foreach ($fields as $column => $value) {
        if (!is_string($column) || !in_array($column, $allowedColumns, true)) {
            throw new InvalidArgumentException('Unsupported image scan update field.');
        }
        $assignments[] = $column . ' = ?';
        $params[] = $value;
    }
    $assignments[] = 'updated_at = ?';
    $params[] = $now;
    $params[] = $imageId;

    $stmt = db()->prepare('UPDATE images SET ' . implode(', ', $assignments) . ' WHERE id = ?');
    $stmt->execute($params);
}

/**
 * Calculate the append sort order for a newly discovered image.
 *
 * @param int $galleryId Gallery identifier.
 * @return int Next sort order spaced by ten.
 */
function image_model_next_sort_order(int $galleryId): int
{
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM images WHERE gallery_id = ?');
    $stmt->execute([$galleryId]);
    return (int) $stmt->fetchColumn() + 10;
}


/**
 * Return metadata-organizer image rows for one gallery in deterministic order.
 *
 * @param int $galleryId Gallery identifier.
 * @param int $offset Row offset.
 * @param int $limit Maximum rows, or zero for all rows.
 * @return array<int,array<string,mixed>> Organizer source image rows.
 */
function image_model_metadata_organizer_rows(int $galleryId, int $offset = 0, int $limit = 0): array
{
    $offset = max(0, $offset);
    $limit = max(0, $limit);
    $sql = "SELECT id, gallery_id, relative_path, filename, title, exif_taken_at, sort_order, visibility
        FROM images
        WHERE gallery_id = ?
        ORDER BY exif_taken_at, sort_order, filename, id";
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute([$galleryId]);
    return $stmt->fetchAll();
}

/**
 * Return the next metadata-organizer candidate rows within a capture-date range.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $minDate Inclusive lower YYYY-MM-DD boundary.
 * @param string $maxDate Inclusive upper YYYY-MM-DD boundary.
 * @param int $limit Maximum candidate rows.
 * @return array<int,array<string,mixed>> Candidate image rows.
 */
function image_model_metadata_organizer_candidates(int $galleryId, string $minDate, string $maxDate, int $limit): array
{
    $limit = max(1, min(100, $limit));
    $stmt = db()->prepare("SELECT id, gallery_id, relative_path, filename, title, exif_taken_at, sort_order, visibility
        FROM images
        WHERE gallery_id = ?
          AND exif_taken_at IS NOT NULL
          AND TRIM(exif_taken_at) <> ''
          AND SUBSTR(exif_taken_at, 1, 10) >= ?
          AND SUBSTR(exif_taken_at, 1, 10) <= ?
        ORDER BY exif_taken_at, sort_order, filename, id
        LIMIT " . $limit);
    $stmt->execute([$galleryId, $minDate, $maxDate]);
    return $stmt->fetchAll();
}

/**
 * Count metadata-organizer candidate rows within a capture-date range.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $minDate Inclusive lower YYYY-MM-DD boundary.
 * @param string $maxDate Inclusive upper YYYY-MM-DD boundary.
 * @return int Number of remaining candidate rows.
 */
function image_model_metadata_organizer_candidate_count(int $galleryId, string $minDate, string $maxDate): int
{
    $stmt = db()->prepare("SELECT COUNT(*)
        FROM images
        WHERE gallery_id = ?
          AND exif_taken_at IS NOT NULL
          AND TRIM(exif_taken_at) <> ''
          AND SUBSTR(exif_taken_at, 1, 10) >= ?
          AND SUBSTR(exif_taken_at, 1, 10) <= ?");
    $stmt->execute([$galleryId, $minDate, $maxDate]);
    return (int) $stmt->fetchColumn();
}

/**
 * Return a compact summary for direct images in one gallery.
 *
 * @param int $galleryId Gallery identifier.
 * @return array{image_count:int,newest_update:string,total_size:string} Direct-image inventory summary.
 */
function image_model_direct_inventory_summary(int $galleryId): array
{
    $stmt = db()->prepare("SELECT COUNT(*) AS image_count, COALESCE(MAX(updated_at), '') AS newest_update, COALESCE(SUM(COALESCE(file_size, 0)), 0) AS total_size FROM images WHERE gallery_id = ? AND relative_path NOT LIKE '%/%'");
    $stmt->execute([$galleryId]);
    $row = $stmt->fetch() ?: [];
    return [
        'image_count' => (int) ($row['image_count'] ?? 0),
        'newest_update' => (string) ($row['newest_update'] ?? ''),
        'total_size' => (string) ($row['total_size'] ?? '0'),
    ];
}

/**
 * Return direct image rows matching a bounded set of SHA-256 checksums.
 *
 * @param int $galleryId Gallery identifier.
 * @param array<int,string> $hashes Lowercase SHA-256 checksums.
 * @return array<int,array<string,mixed>> Matching image rows.
 */
function image_model_direct_rows_by_checksums(int $galleryId, array $hashes): array
{
    if ($hashes === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($hashes), '?'));
    $stmt = db()->prepare("SELECT id, filename, relative_path, file_size, checksum_sha256 FROM images WHERE gallery_id = ? AND relative_path NOT LIKE '%/%' AND checksum_sha256 IN ($placeholders)");
    $stmt->execute(array_merge([$galleryId], $hashes));
    return $stmt->fetchAll() ?: [];
}

/**
 * Persist authoritative SHA-256 checksums for selected images in one gallery.
 *
 * @param int $galleryId Gallery identifier.
 * @param array<int,string> $checksumsByImageId Lowercase or uppercase SHA-256 values keyed by image id.
 * @param string $now Shared update timestamp.
 * @return array{updated:int,skipped:int} Persistence counters.
 */
function image_model_set_checksums_for_gallery_ids(int $galleryId, array $checksumsByImageId, string $now): array
{
    if ($galleryId <= 0 || $checksumsByImageId === []) {
        return ['updated' => 0, 'skipped' => count($checksumsByImageId)];
    }

    $stmt = db()->prepare('UPDATE images SET checksum_sha256 = ?, updated_at = ? WHERE id = ? AND gallery_id = ?');
    $updated = 0;
    $skipped = 0;
    foreach ($checksumsByImageId as $imageId => $checksum) {
        $imageId = (int) $imageId;
        $checksum = strtolower(trim((string) $checksum));
        if ($imageId <= 0 || preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1) {
            $skipped++;
            continue;
        }

        $stmt->execute([$checksum, $now, $imageId, $galleryId]);
        if ($stmt->rowCount() > 0) {
            $updated++;
        } else {
            $skipped++;
        }
    }

    return ['updated' => $updated, 'skipped' => $skipped];
}

/**
 * Persist one GPS metadata tuple for selected images that belong to one gallery.
 *
 * @param int $galleryId Gallery identifier.
 * @param array<int,int> $imageIds Positive image identifiers.
 * @param float $latitude Latitude value.
 * @param float $longitude Longitude value.
 * @param ?float $altitude Optional altitude value.
 * @param string $now Shared extraction/update timestamp.
 * @return array{updated:int,skipped:int} Persistence counters.
 */
function image_model_set_gps_metadata_for_ids(int $galleryId, array $imageIds, float $latitude, float $longitude, ?float $altitude, string $now): array
{
    if ($imageIds === []) {
        return ['updated' => 0, 'skipped' => 0];
    }
    $stmt = db()->prepare('UPDATE images SET gps_lat = ?, gps_lng = ?, gps_altitude = ?, gps_extracted_at = ?, updated_at = ? WHERE id = ? AND gallery_id = ?');
    $updated = 0;
    $skipped = 0;
    foreach ($imageIds as $imageId) {
        $stmt->execute([$latitude, $longitude, $altitude, $now, $now, $imageId, $galleryId]);
        if ($stmt->rowCount() > 0) {
            $updated++;
        } else {
            $skipped++;
        }
    }
    return ['updated' => $updated, 'skipped' => $skipped];
}

/**
 * Load public image rows for a bounded set of canonical image identifiers.
 *
 * @param array<int,int> $imageIds Positive image identifiers.
 * @return array<int,array<string,mixed>> Public image rows keyed by image id.
 */
function image_model_public_rows_by_ids(array $imageIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $imageIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }
    $rows = [];
    foreach (array_chunk($ids, 200) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = db()->prepare('SELECT * FROM images WHERE id IN (' . $placeholders . ') AND visibility = ?');
        $stmt->execute(array_merge($chunk, ['public']));
        foreach ($stmt->fetchAll() as $row) {
            $imageId = (int) ($row['id'] ?? 0);
            if ($imageId > 0) {
                $rows[$imageId] = $row;
            }
        }
    }
    return $rows;
}


/**
 * Return existing image identifiers constrained to one gallery while preserving caller order.
 *
 * @param array<int,int> $imageIds Candidate image identifiers.
 * @return array<int,int> Existing identifiers in the same order as the input set.
 */
function image_model_existing_ids_for_gallery(int $galleryId, array $imageIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $imageIds), static fn (int $id): bool => $id > 0)));
    if ($galleryId <= 0 || $ids === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare('SELECT id FROM images WHERE gallery_id = ? AND id IN (' . $placeholders . ')');
    $stmt->execute(array_merge([$galleryId], $ids));
    $existingMap = array_fill_keys(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)), true);
    return array_values(array_filter($ids, static fn (int $id): bool => isset($existingMap[$id])));
}

/**
 * Return relative paths keyed by image id for rows constrained to one gallery.
 *
 * @param array<int,int> $imageIds Candidate image identifiers.
 * @return array<int,string> Relative paths keyed by image id.
 */
function image_model_relative_paths_for_gallery_ids(int $galleryId, array $imageIds): array
{
    $ids = image_model_existing_ids_for_gallery($galleryId, $imageIds);
    if ($ids === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare('SELECT id, relative_path FROM images WHERE gallery_id = ? AND id IN (' . $placeholders . ')');
    $stmt->execute(array_merge([$galleryId], $ids));
    $paths = [];
    foreach ($stmt->fetchAll() as $row) {
        $imageId = (int) ($row['id'] ?? 0);
        if ($imageId > 0) {
            $paths[$imageId] = (string) ($row['relative_path'] ?? '');
        }
    }
    return $paths;
}

/**
 * Return arbitrary image rows keyed by id for one bounded identifier set.
 *
 * @param array<int,int> $imageIds Candidate image identifiers.
 * @return array<int,array<string,mixed>> Image rows keyed by id.
 */
function image_model_rows_by_ids(array $imageIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $imageIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }

    $rows = [];
    foreach (array_chunk($ids, 200) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = db()->prepare('SELECT * FROM images WHERE id IN (' . $placeholders . ')');
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll() as $row) {
            $imageId = (int) ($row['id'] ?? 0);
            if ($imageId > 0) {
                $rows[$imageId] = $row;
            }
        }
    }
    return $rows;
}

/**
 * Apply deterministic upload-session sort orders using precomputed relative-path hashes.
 *
 * @param array<string,int> $sourceIndexByPathHash Source indexes keyed by SHA-256 relative-path hash.
 * @return int Number of rows reported as changed.
 */
function image_model_apply_upload_sort_order(int $galleryId, array $sourceIndexByPathHash, int $sortBase, string $now): int
{
    if ($galleryId <= 0 || $sourceIndexByPathHash === []) {
        return 0;
    }

    $select = db()->prepare('SELECT id FROM images WHERE gallery_id = ? AND relative_path_hash = ? LIMIT 1');
    $update = db()->prepare('UPDATE images SET sort_order = ?, updated_at = ? WHERE id = ?');
    $changed = 0;
    foreach ($sourceIndexByPathHash as $pathHash => $sourceIndex) {
        if (!is_string($pathHash) || preg_match('/^[a-f0-9]{64}$/', $pathHash) !== 1) {
            continue;
        }
        $select->execute([$galleryId, $pathHash]);
        $imageId = (int) ($select->fetchColumn() ?: 0);
        if ($imageId <= 0) {
            continue;
        }
        $sortOrder = $sortBase + max(0, (int) $sourceIndex) * 10;
        $update->execute([$sortOrder, $now, $imageId]);
        $changed += $update->rowCount() > 0 ? 1 : 0;
    }
    return $changed;
}

/**
 * Return direct image identifiers for selected galleries in stable gallery/image order.
 *
 * @param array<int,int> $galleryIds Gallery identifiers.
 * @return array<int,int> Image identifiers.
 */
function image_model_direct_ids_for_galleries(array $galleryIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT id FROM images WHERE gallery_id IN ($placeholders) AND relative_path NOT LIKE '%/%' ORDER BY gallery_id, sort_order, filename");
    $stmt->execute($ids);
    return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
}

/** Return every direct image identifier in stable gallery-folder/image order. */
function image_model_all_direct_ids_ordered(): array
{
    $rows = db()->query("SELECT i.id FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE i.relative_path NOT LIKE '%/%' ORDER BY g.folder_path, i.sort_order, i.filename")->fetchAll(\PDO::FETCH_COLUMN);
    return array_map('intval', $rows);
}

/**
 * Insert or update one image row during a trusted gallery migration import.
 *
 * The service has already filtered optional columns through schema policy. This
 * model still validates every column name against the migration persistence
 * allowlist before composing SQL.
 *
 * @param int $galleryId Target gallery identifier.
 * @param ?int $existingImageId Existing image identifier, or null for insert.
 * @param array<string,mixed> $fields Schema-filtered image metadata.
 * @param string $now Shared SQL timestamp.
 * @return int Persisted image identifier.
 */
function image_model_migration_upsert(int $galleryId, ?int $existingImageId, array $fields, string $now): int
{
    $allowedColumns = [
        'relative_path',
        'relative_path_hash',
        'filename',
        'title',
        'description',
        'content_language',
        'width',
        'height',
        'mime_type',
        'file_size',
        'modified_at',
        'checksum_sha256',
        'sort_order',
        'visibility',
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
        'nsfw_enabled',
        'thumbnail_min_size',
        'thumbnail_max_size',
    ];

    foreach (array_keys($fields) as $column) {
        if (!is_string($column) || !in_array($column, $allowedColumns, true)) {
            throw new InvalidArgumentException('Unsupported gallery migration image field.');
        }
    }

    if ($existingImageId !== null && $existingImageId > 0) {
        $updateFields = $fields;
        unset($updateFields['relative_path'], $updateFields['relative_path_hash']);
        if ($updateFields !== []) {
            $assignments = [];
            $values = [];
            foreach ($updateFields as $column => $value) {
                $assignments[] = $column . ' = ?';
                $values[] = $value;
            }
            $assignments[] = 'updated_at = ?';
            $values[] = $now;
            $values[] = $existingImageId;
            $stmt = db()->prepare('UPDATE images SET ' . implode(', ', $assignments) . ' WHERE id = ?');
            $stmt->execute($values);
        }
        return $existingImageId;
    }

    $insertFields = $fields;
    $insertFields['gallery_id'] = $galleryId;
    $insertFields['created_at'] = $now;
    $insertFields['updated_at'] = $now;
    $columns = array_keys($insertFields);
    $stmt = db()->prepare('INSERT INTO images (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')');
    $stmt->execute(array_values($insertFields));
    return (int) db()->lastInsertId();
}
