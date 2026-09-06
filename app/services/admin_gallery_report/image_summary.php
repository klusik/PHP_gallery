<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_gallery_report/image_summary.php
 * Module Type: Service
 *
 * Purpose:
 *   Accumulates per-image statistics across the batched report scan.
 *
 * Responsibilities:
 *   - Count images and page through image rows by ascending identifier
 *   - Accumulate camera, lens, format, and size statistics per batch
 *   - Finalize the accumulated image summary for rendering
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
 *   - Loaded by app/services/admin_gallery_report.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/admin_gallery_report.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;
use function Gallery\Core\cms_config;
use function Gallery\Core\cms_current_version;
use function Gallery\Core\db;

/**
 * Return total image count.
 *
 * @return int Image count.
 */
function admin_gallery_report_image_count(): int
{
    try {
        return (int) (db()->query('SELECT COUNT(*) FROM images')->fetchColumn() ?: 0);
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Return image rows after one id for incremental inspection.
 *
 * @param int $lastImageId Last image id.
 * @param int $limit Maximum number of rows.
 * @return array<int, array<string, mixed>> Image rows.
 */
function admin_gallery_report_image_rows_after_id(int $lastImageId, int $limit): array
{
    $lastImageId = max(0, $lastImageId);
    $limit = max(1, min(ADMIN_GALLERY_REPORT_MAX_BATCH_SIZE, $limit));
    $derivativeVersionSelect = function_exists('Gallery\\Services\\admin_storage_statistics_image_derivative_version_select') ? admin_storage_statistics_image_derivative_version_select() : '1';
    $columns = [
        'i.id AS image_id',
        'i.gallery_id AS image_gallery_id',
        'i.relative_path',
        'i.filename',
        'i.mime_type',
        'COALESCE(i.file_size, 0) AS file_size',
        'i.width',
        'i.height',
        'i.visibility AS image_visibility',
        'i.modified_at',
        'i.created_at AS image_created_at',
        'i.updated_at AS image_updated_at',
        $derivativeVersionSelect . ' AS thumbnail_derivative_version',
        'g.id AS gallery_id',
        'g.title AS gallery_title',
        'g.folder_path AS gallery_folder_path',
        'g.visibility AS gallery_visibility',
        admin_gallery_report_column_select('images', 'exif_taken_at', 'i', 'NULL', 'exif_taken_at'),
        admin_gallery_report_column_select('images', 'exif_camera_make', 'i', 'NULL', 'exif_camera_make'),
        admin_gallery_report_column_select('images', 'exif_camera_model', 'i', 'NULL', 'exif_camera_model'),
        admin_gallery_report_column_select('images', 'exif_lens_model', 'i', 'NULL', 'exif_lens_model'),
        admin_gallery_report_column_select('images', 'exif_focal_length', 'i', 'NULL', 'exif_focal_length'),
        admin_gallery_report_column_select('images', 'exif_aperture', 'i', 'NULL', 'exif_aperture'),
        admin_gallery_report_column_select('images', 'exif_exposure_time', 'i', 'NULL', 'exif_exposure_time'),
        admin_gallery_report_column_select('images', 'exif_iso', 'i', 'NULL', 'exif_iso'),
        admin_gallery_report_column_select('images', 'gps_lat', 'i', 'NULL', 'gps_lat'),
        admin_gallery_report_column_select('images', 'gps_lng', 'i', 'NULL', 'gps_lng'),
        admin_gallery_report_column_select('images', 'gps_altitude', 'i', 'NULL', 'gps_altitude'),
        admin_gallery_report_column_select('images', 'gps_extracted_at', 'i', 'NULL', 'gps_extracted_at'),
        admin_gallery_report_column_select('images', 'exif_orientation', 'i', 'NULL', 'exif_orientation'),
    ];

    try {
        $stmt = db()->prepare('SELECT ' . implode(', ', $columns) . ' FROM images i INNER JOIN galleries g ON g.id = i.gallery_id WHERE i.id > ? ORDER BY i.id LIMIT ' . $limit);
        $stmt->execute([$lastImageId]);
        return $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

/**
 * Return initial image and EXIF accumulator data.
 *
 * @return array<string, mixed> Accumulator data.
 */
function admin_gallery_report_initial_image_summary(): array
{
    return [
        'image_count' => 0,
        'public_count' => 0,
        'draft_count' => 0,
        'private_count' => 0,
        'known_dimensions_count' => 0,
        'unknown_dimensions_count' => 0,
        'pixel_count_total' => 0,
        'landscape_count' => 0,
        'portrait_count' => 0,
        'square_count' => 0,
        'panorama_count' => 0,
        'exif_date_count' => 0,
        'gps_count' => 0,
        'both_exif_date_and_gps_count' => 0,
        'neither_exif_date_nor_gps_count' => 0,
        'camera_known_count' => 0,
        'lens_known_count' => 0,
        'iso_known_count' => 0,
        'aperture_known_count' => 0,
        'exposure_known_count' => 0,
        'focal_known_count' => 0,
        'gps_altitude_known_count' => 0,
        'probable_game_gps_count' => 0,
        'clustered_gps_count' => 0,
        'type_groups' => [],
        'visibility_groups' => [],
        'dimension_groups' => [],
        'camera_groups' => [],
        'lens_groups' => [],
        'iso_groups' => [],
        'aperture_groups' => [],
        'focal_groups' => [],
        'gps_clusters' => [],
        'exif_date_min' => null,
        'exif_date_max' => null,
        'image_created_min' => null,
        'image_created_max' => null,
        'gps_lat_min' => null,
        'gps_lat_max' => null,
        'gps_lng_min' => null,
        'gps_lng_max' => null,
        'gps_altitude_min' => null,
        'gps_altitude_max' => null,
    ];
}

/**
 * Add one image row to the report accumulators.
 *
 * @param array $summary Image summary accumulator.
 * @param array $row Image row.
 */
function admin_gallery_report_accumulate_image_row(array &$summary, array $row): void
{
    $summary['image_count'] = (int) ($summary['image_count'] ?? 0) + 1;
    $visibility = (string) ($row['image_visibility'] ?? 'unknown');
    if ($visibility === 'public') {
        $summary['public_count'] = (int) ($summary['public_count'] ?? 0) + 1;
    } elseif ($visibility === 'draft') {
        $summary['draft_count'] = (int) ($summary['draft_count'] ?? 0) + 1;
    } elseif ($visibility === 'private') {
        $summary['private_count'] = (int) ($summary['private_count'] ?? 0) + 1;
    }
    admin_gallery_report_add_group($summary['visibility_groups'], 'visibility-' . $visibility, $visibility, 1, 0, []);

    $extension = admin_gallery_report_file_extension((string) ($row['filename'] ?? $row['relative_path'] ?? ''));
    admin_gallery_report_add_group($summary['type_groups'], 'type-' . $extension, strtoupper($extension), 1, (int) ($row['file_size'] ?? 0), [
        'extension' => $extension,
        'mime_type' => (string) ($row['mime_type'] ?? ''),
    ]);

    $width = max(0, (int) ($row['width'] ?? 0));
    $height = max(0, (int) ($row['height'] ?? 0));
    if ($width > 0 && $height > 0) {
        $summary['known_dimensions_count'] = (int) ($summary['known_dimensions_count'] ?? 0) + 1;
        $summary['pixel_count_total'] = (int) ($summary['pixel_count_total'] ?? 0) + ($width * $height);
        $ratio = $height > 0 ? $width / $height : 0.0;
        if (abs($ratio - 1.0) < 0.05) {
            $orientation = 'square';
            $summary['square_count'] = (int) ($summary['square_count'] ?? 0) + 1;
        } elseif ($ratio >= 2.0) {
            $orientation = 'panorama';
            $summary['panorama_count'] = (int) ($summary['panorama_count'] ?? 0) + 1;
        } elseif ($ratio > 1.0) {
            $orientation = 'landscape';
            $summary['landscape_count'] = (int) ($summary['landscape_count'] ?? 0) + 1;
        } else {
            $orientation = 'portrait';
            $summary['portrait_count'] = (int) ($summary['portrait_count'] ?? 0) + 1;
        }
        admin_gallery_report_add_group($summary['dimension_groups'], 'orientation-' . $orientation, ucfirst($orientation), 1, 0, []);
    } else {
        $summary['unknown_dimensions_count'] = (int) ($summary['unknown_dimensions_count'] ?? 0) + 1;
    }

    admin_gallery_report_update_date_range($summary, 'image_created_min', 'image_created_max', (string) ($row['image_created_at'] ?? ''));
    $hasExifDate = admin_gallery_report_valid_datetime((string) ($row['exif_taken_at'] ?? ''));
    $hasGps = is_numeric($row['gps_lat'] ?? null) && is_numeric($row['gps_lng'] ?? null);
    if ($hasExifDate) {
        $summary['exif_date_count'] = (int) ($summary['exif_date_count'] ?? 0) + 1;
        admin_gallery_report_update_date_range($summary, 'exif_date_min', 'exif_date_max', (string) ($row['exif_taken_at'] ?? ''));
    }
    if ($hasGps) {
        $summary['gps_count'] = (int) ($summary['gps_count'] ?? 0) + 1;
        $lat = (float) $row['gps_lat'];
        $lng = (float) $row['gps_lng'];
        admin_gallery_report_update_float_range($summary, 'gps_lat_min', 'gps_lat_max', $lat);
        admin_gallery_report_update_float_range($summary, 'gps_lng_min', 'gps_lng_max', $lng);
        if (is_numeric($row['gps_altitude'] ?? null)) {
            $summary['gps_altitude_known_count'] = (int) ($summary['gps_altitude_known_count'] ?? 0) + 1;
            admin_gallery_report_update_float_range($summary, 'gps_altitude_min', 'gps_altitude_max', (float) $row['gps_altitude']);
        }
        if (admin_gallery_report_is_probable_game_gps($row)) {
            $summary['probable_game_gps_count'] = (int) ($summary['probable_game_gps_count'] ?? 0) + 1;
        } else {
            $summary['clustered_gps_count'] = (int) ($summary['clustered_gps_count'] ?? 0) + 1;
            admin_gallery_report_accumulate_gps_cluster($summary['gps_clusters'], $row, $lat, $lng);
        }
    }
    if ($hasExifDate && $hasGps) {
        $summary['both_exif_date_and_gps_count'] = (int) ($summary['both_exif_date_and_gps_count'] ?? 0) + 1;
    }
    if (!$hasExifDate && !$hasGps) {
        $summary['neither_exif_date_nor_gps_count'] = (int) ($summary['neither_exif_date_nor_gps_count'] ?? 0) + 1;
    }

    $camera = admin_gallery_report_compact_label(trim((string) ($row['exif_camera_make'] ?? '')) . ' ' . trim((string) ($row['exif_camera_model'] ?? '')));
    if ($camera !== '') {
        $summary['camera_known_count'] = (int) ($summary['camera_known_count'] ?? 0) + 1;
        admin_gallery_report_add_group($summary['camera_groups'], 'camera-' . hash('sha1', $camera), $camera, 1, 0, []);
    }
    $lens = admin_gallery_report_compact_label((string) ($row['exif_lens_model'] ?? ''));
    if ($lens !== '') {
        $summary['lens_known_count'] = (int) ($summary['lens_known_count'] ?? 0) + 1;
        admin_gallery_report_add_group($summary['lens_groups'], 'lens-' . hash('sha1', $lens), $lens, 1, 0, []);
    }
    $iso = max(0, (int) ($row['exif_iso'] ?? 0));
    if ($iso > 0) {
        $summary['iso_known_count'] = (int) ($summary['iso_known_count'] ?? 0) + 1;
        $isoBucket = admin_gallery_report_iso_bucket($iso);
        admin_gallery_report_add_group($summary['iso_groups'], 'iso-' . $isoBucket, $isoBucket, 1, 0, []);
    }
    $aperture = admin_gallery_report_compact_label((string) ($row['exif_aperture'] ?? ''));
    if ($aperture !== '') {
        $summary['aperture_known_count'] = (int) ($summary['aperture_known_count'] ?? 0) + 1;
        admin_gallery_report_add_group($summary['aperture_groups'], 'aperture-' . hash('sha1', $aperture), $aperture, 1, 0, []);
    }
    $exposure = admin_gallery_report_compact_label((string) ($row['exif_exposure_time'] ?? ''));
    if ($exposure !== '') {
        $summary['exposure_known_count'] = (int) ($summary['exposure_known_count'] ?? 0) + 1;
    }
    $focal = admin_gallery_report_compact_label((string) ($row['exif_focal_length'] ?? ''));
    if ($focal !== '') {
        $summary['focal_known_count'] = (int) ($summary['focal_known_count'] ?? 0) + 1;
        admin_gallery_report_add_group($summary['focal_groups'], 'focal-' . hash('sha1', $focal), $focal, 1, 0, []);
    }
}

/**
 * Finalize image summary rows for rendering.
 *
 * @param array $summary Image summary accumulator.
 * @return array<string, mixed> Finalized summary.
 */
function admin_gallery_report_finalize_image_summary(array $summary): array
{
    $summary['type_rows'] = admin_gallery_report_finalize_group_rows(is_array($summary['type_groups'] ?? null) ? $summary['type_groups'] : [], 'count', 50);
    $summary['visibility_rows'] = admin_gallery_report_finalize_group_rows(is_array($summary['visibility_groups'] ?? null) ? $summary['visibility_groups'] : [], 'count', 10);
    $summary['dimension_rows'] = admin_gallery_report_finalize_group_rows(is_array($summary['dimension_groups'] ?? null) ? $summary['dimension_groups'] : [], 'count', 10);
    $summary['camera_rows'] = admin_gallery_report_finalize_group_rows(is_array($summary['camera_groups'] ?? null) ? $summary['camera_groups'] : [], 'count', 80);
    $summary['lens_rows'] = admin_gallery_report_finalize_group_rows(is_array($summary['lens_groups'] ?? null) ? $summary['lens_groups'] : [], 'count', 80);
    $summary['iso_rows'] = admin_gallery_report_finalize_group_rows(is_array($summary['iso_groups'] ?? null) ? $summary['iso_groups'] : [], 'count', 20);
    $summary['aperture_rows'] = admin_gallery_report_finalize_group_rows(is_array($summary['aperture_groups'] ?? null) ? $summary['aperture_groups'] : [], 'count', 40);
    $summary['focal_rows'] = admin_gallery_report_finalize_group_rows(is_array($summary['focal_groups'] ?? null) ? $summary['focal_groups'] : [], 'count', 50);
    $summary['gps_cluster_rows'] = admin_gallery_report_finalize_gps_clusters(is_array($summary['gps_clusters'] ?? null) ? $summary['gps_clusters'] : []);
    unset($summary['type_groups'], $summary['visibility_groups'], $summary['dimension_groups'], $summary['camera_groups'], $summary['lens_groups'], $summary['iso_groups'], $summary['aperture_groups'], $summary['focal_groups'], $summary['gps_clusters']);
    $knownDimensions = (int) ($summary['known_dimensions_count'] ?? 0);
    $summary['average_megapixels'] = $knownDimensions > 0 ? round(((int) ($summary['pixel_count_total'] ?? 0) / $knownDimensions) / 1000000, 2) : 0.0;
    return $summary;
}
