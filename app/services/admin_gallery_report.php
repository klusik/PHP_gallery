<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_gallery_report.php
 * Module Type: Service
 *
 * Purpose:
 *   Builds the complete Admin gallery overview report.
 *
 * Responsibilities:
 *   - Collect gallery, image, EXIF, GPS, storage, database, telemetry, and runtime diagnostics
 *   - Process image-heavy checks in browser-driven batches to avoid shared-hosting timeouts
 *   - Render a single self-contained HTML report without saving the generated output on the server
 *   - Keep GPS place clustering approximate and exclude probable simulator/game captures where possible
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
 *   2026-06-15
 */

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;
use function Gallery\Core\cms_config;
use function Gallery\Core\cms_current_version;
use function Gallery\Core\db;

const ADMIN_GALLERY_REPORT_JOB_KEY = 'admin_gallery_report_job_v1';
const ADMIN_GALLERY_REPORT_DEFAULT_BATCH_SIZE = 20;
const ADMIN_GALLERY_REPORT_MAX_BATCH_SIZE = 100;
const ADMIN_GALLERY_REPORT_GPS_AREA_KM = 20.0;
const ADMIN_GALLERY_REPORT_PLACE_MATCH_DEFAULT_RADIUS_KM = 35.0;

/**
 * Start a browser-driven gallery overview report job.
 *
 * @param int $telemetryDays Telemetry window in days.
 * @return array<string, mixed> Structured job state.
 */
function admin_gallery_report_start_job(int $telemetryDays = 30): array
{
    $telemetryDays = max(1, min(3650, $telemetryDays));
    $totalImages = admin_gallery_report_image_count();
    $job = [
        'job_id' => admin_gallery_report_job_id(),
        'status' => 'running',
        'started_at' => time(),
        'updated_at' => time(),
        'telemetry_days' => $telemetryDays,
        'total' => $totalImages,
        'processed' => 0,
        'last_image_id' => 0,
        'site' => admin_gallery_report_site_summary(),
        'runtime' => admin_gallery_report_runtime_summary(),
        'database' => admin_gallery_report_database_section(),
        'data_paths' => admin_gallery_report_data_path_summary(),
        'tables' => admin_gallery_report_table_counts(),
        'galleries' => admin_gallery_report_gallery_summary(),
        'gallery_rows' => admin_gallery_report_gallery_detail_rows(),
        'tags' => admin_gallery_report_tag_summary(),
        'votes' => admin_gallery_report_vote_summary(),
        'features' => admin_gallery_report_feature_summary(),
        'logs' => admin_gallery_report_admin_log_summary(),
        'telemetry' => admin_gallery_report_telemetry_section($telemetryDays),
        'top_images' => admin_gallery_report_largest_images(200),
        'image_summary' => admin_gallery_report_initial_image_summary(),
        'storage_source' => function_exists('Gallery\\Services\\admin_storage_statistics_source_summary') ? admin_storage_statistics_source_summary([]) : [],
        'storage_generated' => function_exists('Gallery\\Services\\admin_storage_statistics_initial_generated_summary') ? admin_storage_statistics_initial_generated_summary() : [],
        'thumbnail_metadata_used' => function_exists('Gallery\\Services\\admin_storage_statistics_thumbnail_metadata_available') && admin_storage_statistics_thumbnail_metadata_available(),
        'errors' => [],
    ];

    if ($totalImages <= 0) {
        return admin_gallery_report_finish_job($job);
    }

    admin_gallery_report_job_write($job);
    return admin_gallery_report_public_state($job);
}

/**
 * Process one bounded report generation batch.
 *
 * @param int $batchSize Number of image rows to inspect.
 * @return array<string, mixed> Structured job state.
 */
function admin_gallery_report_process_job(int $batchSize = ADMIN_GALLERY_REPORT_DEFAULT_BATCH_SIZE): array
{
    $job = admin_gallery_report_job_read();
    if ($job === null || (string) ($job['status'] ?? '') !== 'running') {
        return [
            'ok' => false,
            'status' => 'missing',
            'message' => 'No running gallery report job was found.',
            'processed' => 0,
            'total' => 0,
            'percent' => 0.0,
        ];
    }

    $batchSize = max(1, min(ADMIN_GALLERY_REPORT_MAX_BATCH_SIZE, $batchSize));
    $lastImageId = max(0, (int) ($job['last_image_id'] ?? 0));
    $rows = admin_gallery_report_image_rows_after_id($lastImageId, $batchSize);
    $imageSummary = is_array($job['image_summary'] ?? null) ? $job['image_summary'] : admin_gallery_report_initial_image_summary();
    $sourceSummary = is_array($job['storage_source'] ?? null) ? $job['storage_source'] : (function_exists('Gallery\\Services\\admin_storage_statistics_source_summary') ? admin_storage_statistics_source_summary([]) : []);
    $generatedSummary = is_array($job['storage_generated'] ?? null) ? $job['storage_generated'] : (function_exists('Gallery\\Services\\admin_storage_statistics_initial_generated_summary') ? admin_storage_statistics_initial_generated_summary() : []);
    $thumbnailMetadataUsed = !empty($job['thumbnail_metadata_used']);

    foreach ($rows as $row) {
        admin_gallery_report_accumulate_image_row($imageSummary, $row);
        if (function_exists('Gallery\\Services\\admin_storage_statistics_accumulate_source_row')) {
            admin_storage_statistics_accumulate_source_row($sourceSummary, $row);
        }
        if ($thumbnailMetadataUsed && function_exists('Gallery\\Services\\admin_storage_statistics_accumulate_display_master_media_row')) {
            admin_storage_statistics_accumulate_display_master_media_row($generatedSummary, $row);
        } elseif (function_exists('Gallery\\Services\\admin_storage_statistics_accumulate_generated_media_row')) {
            admin_storage_statistics_accumulate_generated_media_row($generatedSummary, $row);
        }
        $lastImageId = max($lastImageId, (int) ($row['image_id'] ?? 0));
    }

    $processed = min(max(0, (int) ($job['total'] ?? 0)), max(0, (int) ($job['processed'] ?? 0)) + count($rows));
    $job['image_summary'] = $imageSummary;
    $job['storage_source'] = $sourceSummary;
    $job['storage_generated'] = $generatedSummary;
    $job['processed'] = $processed;
    $job['last_image_id'] = $lastImageId;
    $job['updated_at'] = time();

    if ($rows === [] || $processed >= (int) ($job['total'] ?? 0)) {
        return admin_gallery_report_finish_job($job);
    }

    admin_gallery_report_job_write($job);
    return admin_gallery_report_public_state($job);
}

/**
 * Finish a report job and return the final HTML in the response only.
 *
 * @param array $job Job data.
 * @return array<string, mixed> Public state.
 */
function admin_gallery_report_finish_job(array $job): array
{
    $job['status'] = 'complete';
    $job['processed'] = (int) ($job['total'] ?? $job['processed'] ?? 0);
    $job['updated_at'] = time();
    $job['finished_at'] = time();
    $job['duration_seconds'] = max(0, (int) ($job['finished_at'] ?? time()) - (int) ($job['started_at'] ?? time()));
    $job['image_summary'] = admin_gallery_report_finalize_image_summary(is_array($job['image_summary'] ?? null) ? $job['image_summary'] : admin_gallery_report_initial_image_summary());
    $job['storage'] = admin_gallery_report_storage_snapshot($job);

    $html = admin_gallery_report_render_html($job);
    $filename = 'php-gallery-complete-overview-' . gmdate('Ymd-His') . '.html';
    admin_gallery_report_job_clear();

    if (function_exists('Gallery\\Services\\admin_log_event')) {
        admin_log_event('info', 'admin_report.generated', 'Admin generated a complete gallery overview report.', [
            'images' => (int) ($job['total'] ?? 0),
            'duration_seconds' => (int) ($job['duration_seconds'] ?? 0),
            'telemetry_days' => (int) ($job['telemetry_days'] ?? 30),
        ], ['category' => 'admin', 'severity' => 'notice', 'route_name' => 'admin_gallery_report']);
    }

    return admin_gallery_report_public_state($job, [
        'report_html' => $html,
        'filename' => $filename,
        'report_bytes' => strlen($html),
    ]);
}

/**
 * Return compact progress information for the browser.
 *
 * @param array $job Job data.
 * @param array $extra Extra response fields.
 * @return array<string, mixed> Public state.
 */
function admin_gallery_report_public_state(array $job, array $extra = []): array
{
    $total = max(0, (int) ($job['total'] ?? 0));
    $processed = max(0, min($total, (int) ($job['processed'] ?? 0)));
    $percent = $total > 0 ? round(($processed / $total) * 100, 1) : 100.0;
    $state = [
        'ok' => true,
        'status' => (string) ($job['status'] ?? 'running'),
        'processed' => $processed,
        'total' => $total,
        'percent' => $percent,
        'message' => (string) ($job['status'] ?? '') === 'complete'
            ? 'Complete gallery overview report generated.'
            : 'Processing image database rows and generated media metadata.',
    ];
    return array_merge($state, $extra);
}

/**
 * Return a durable random job identifier.
 *
 * @return string Text result for the caller.
 */
function admin_gallery_report_job_id(): string
{
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable) {
        return hash('sha256', uniqid('admin-gallery-report-', true));
    }
}

/**
 * Read the current report job from the PHP session.
 *
 * @return array<string, mixed>|null Job data or null.
 */
function admin_gallery_report_job_read(): ?array
{
    $job = $_SESSION[ADMIN_GALLERY_REPORT_JOB_KEY] ?? null;
    return is_array($job) ? $job : null;
}

/**
 * Store the current report job in the PHP session.
 *
 * @param array $job Job data.
 */
function admin_gallery_report_job_write(array $job): void
{
    $_SESSION[ADMIN_GALLERY_REPORT_JOB_KEY] = $job;
}

/**
 * Remove the transient report job from the PHP session.
 */
function admin_gallery_report_job_clear(): void
{
    unset($_SESSION[ADMIN_GALLERY_REPORT_JOB_KEY]);
}

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
 * Build a safe optional SQL column expression.
 *
 * @param string $table Table name.
 * @param string $column Column name.
 * @param string $alias SQL table alias.
 * @param string $fallback Fallback SQL expression.
 * @param string $outputAlias Output alias.
 * @return string SQL expression.
 */
function admin_gallery_report_column_select(string $table, string $column, string $alias, string $fallback, string $outputAlias): string
{
    if (function_exists('Gallery\\Services\\db_column_exists') && db_column_exists($table, $column)) {
        return $alias . '.' . $column . ' AS ' . $outputAlias;
    }
    return $fallback . ' AS ' . $outputAlias;
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

/**
 * Return whether the image GPS point is probably from a simulator or game capture.
 *
 * @param array $row Image row.
 * @return bool True when the GPS point should not be used for real-world place clustering.
 */
function admin_gallery_report_is_probable_game_gps(array $row): bool
{
    $filename = strtolower((string) ($row['filename'] ?? $row['relative_path'] ?? ''));
    $extension = admin_gallery_report_file_extension($filename);
    $mime = strtolower((string) ($row['mime_type'] ?? ''));
    $camera = trim((string) ($row['exif_camera_make'] ?? '') . ' ' . (string) ($row['exif_camera_model'] ?? '') . ' ' . (string) ($row['exif_lens_model'] ?? ''));
    $hasCameraExif = $camera !== '' || trim((string) ($row['exif_focal_length'] ?? '')) !== '' || trim((string) ($row['exif_aperture'] ?? '')) !== '' || trim((string) ($row['exif_exposure_time'] ?? '')) !== '' || (int) ($row['exif_iso'] ?? 0) > 0;
    $nameLooksLikeCapture = preg_match('/(msfs|flight[ _-]?sim|simconnect|xplane|x-plane|dcs|elite[ _-]?dangerous|screenshot|screen[ _-]?shot|steam|geforce|nvidia|2537590_[0-9]{14})/i', $filename) === 1;
    if ($nameLooksLikeCapture) {
        return true;
    }
    if (!$hasCameraExif && in_array($extension, ['png', 'webp', 'bmp'], true)) {
        return true;
    }
    if (!$hasCameraExif && str_contains($mime, 'png')) {
        return true;
    }
    return false;
}

/**
 * Add a GPS point to an approximate 20 km area cluster.
 *
 * @param array $clusters Cluster accumulator.
 * @param array $row Image row.
 * @param float $lat Latitude.
 * @param float $lng Longitude.
 */
function admin_gallery_report_accumulate_gps_cluster(array &$clusters, array $row, float $lat, float $lng): void
{
    if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
        return;
    }

    $key = admin_gallery_report_find_gps_cluster_key($clusters, $lat, $lng);
    if ($key === '') {
        $key = admin_gallery_report_new_gps_cluster_key($clusters);
        $clusters[$key] = admin_gallery_report_empty_gps_cluster($lat, $lng);
    }

    $cluster = &$clusters[$key];
    $cluster['count'] = (int) ($cluster['count'] ?? 0) + 1;
    $cluster['lat_sum'] = (float) ($cluster['lat_sum'] ?? 0.0) + $lat;
    $cluster['lng_sum'] = (float) ($cluster['lng_sum'] ?? 0.0) + $lng;
    $cluster['lat_min'] = min((float) ($cluster['lat_min'] ?? $lat), $lat);
    $cluster['lat_max'] = max((float) ($cluster['lat_max'] ?? $lat), $lat);
    $cluster['lng_min'] = min((float) ($cluster['lng_min'] ?? $lng), $lng);
    $cluster['lng_max'] = max((float) ($cluster['lng_max'] ?? $lng), $lng);
    $galleryId = (int) ($row['gallery_id'] ?? $row['image_gallery_id'] ?? 0);
    if ($galleryId > 0) {
        $cluster['gallery_ids'][$galleryId] = true;
        $label = trim((string) ($row['gallery_title'] ?? ''));
        if ($label === '') {
            $label = trim((string) ($row['gallery_folder_path'] ?? ''));
        }
        if ($label !== '' && count($cluster['gallery_labels']) < 8) {
            $cluster['gallery_labels'][$label] = true;
        }
    }
    $date = (string) ($row['exif_taken_at'] ?? '');
    if (admin_gallery_report_valid_datetime($date)) {
        admin_gallery_report_update_date_range($cluster, 'first_date', 'last_date', $date);
    }
    if (count($cluster['sample_images']) < 6) {
        $cluster['sample_images'][] = (string) ($row['relative_path'] ?? $row['filename'] ?? '');
    }
}

/**
 * Find an existing GPS cluster whose centroid is close enough for city-scale grouping.
 *
 * @param array $clusters Cluster accumulator.
 * @param float $lat Latitude.
 * @param float $lng Longitude.
 * @return string Existing cluster key or empty string.
 */
function admin_gallery_report_find_gps_cluster_key(array $clusters, float $lat, float $lng): string
{
    $bestKey = '';
    $bestDistance = ADMIN_GALLERY_REPORT_GPS_AREA_KM;
    foreach ($clusters as $key => $cluster) {
        if (!is_array($cluster)) {
            continue;
        }
        $count = max(1, (int) ($cluster['count'] ?? 0));
        $clusterLat = (float) ($cluster['lat_sum'] ?? 0.0) / $count;
        $clusterLng = (float) ($cluster['lng_sum'] ?? 0.0) / $count;
        $distance = admin_gallery_report_haversine_km($lat, $lng, $clusterLat, $clusterLng);
        if ($distance <= ADMIN_GALLERY_REPORT_GPS_AREA_KM && $distance <= $bestDistance) {
            $bestKey = (string) $key;
            $bestDistance = $distance;
        }
    }
    return $bestKey;
}

/**
 * Return a new GPS cluster key.
 *
 * @param array $clusters Cluster accumulator.
 * @return string New cluster key.
 */
function admin_gallery_report_new_gps_cluster_key(array $clusters): string
{
    return 'cluster-' . (count($clusters) + 1);
}

/**
 * Return an empty GPS cluster accumulator.
 *
 * @param float $lat Initial latitude.
 * @param float $lng Initial longitude.
 * @return array<string, mixed> Cluster accumulator.
 */
function admin_gallery_report_empty_gps_cluster(float $lat, float $lng): array
{
    return [
        'count' => 0,
        'lat_sum' => 0.0,
        'lng_sum' => 0.0,
        'lat_min' => $lat,
        'lat_max' => $lat,
        'lng_min' => $lng,
        'lng_max' => $lng,
        'gallery_ids' => [],
        'gallery_labels' => [],
        'first_date' => null,
        'last_date' => null,
        'sample_images' => [],
    ];
}

/**
 * Return finalized GPS cluster rows.
 *
 * @param array $clusters Cluster accumulator.
 * @return array<int, array<string, mixed>> Cluster rows.
 */
function admin_gallery_report_finalize_gps_clusters(array $clusters): array
{
    $rows = [];
    foreach ($clusters as $cluster) {
        if (!is_array($cluster)) {
            continue;
        }
        $count = max(1, (int) ($cluster['count'] ?? 0));
        $lat = (float) ($cluster['lat_sum'] ?? 0.0) / $count;
        $lng = (float) ($cluster['lng_sum'] ?? 0.0) / $count;
        $place = admin_gallery_report_nearest_known_place($lat, $lng);
        $rows[] = [
            'label' => $place['label'],
            'nearest_reference' => $place['nearest_reference'],
            'place_kind' => $place['place_kind'],
            'place_match' => $place['place_match'] ?? '',
            'place_distance_km' => $place['distance_km'],
            'count' => $count,
            'lat' => round($lat, 5),
            'lng' => round($lng, 5),
            'lat_min' => round((float) ($cluster['lat_min'] ?? $lat), 5),
            'lat_max' => round((float) ($cluster['lat_max'] ?? $lat), 5),
            'lng_min' => round((float) ($cluster['lng_min'] ?? $lng), 5),
            'lng_max' => round((float) ($cluster['lng_max'] ?? $lng), 5),
            'gallery_count' => count(is_array($cluster['gallery_ids'] ?? null) ? $cluster['gallery_ids'] : []),
            'gallery_labels' => array_keys(is_array($cluster['gallery_labels'] ?? null) ? $cluster['gallery_labels'] : []),
            'first_date' => $cluster['first_date'] ?? '',
            'last_date' => $cluster['last_date'] ?? '',
            'sample_images' => is_array($cluster['sample_images'] ?? null) ? $cluster['sample_images'] : [],
        ];
    }
    usort($rows, static fn (array $a, array $b): int => ((int) ($b['count'] ?? 0)) <=> ((int) ($a['count'] ?? 0)));
    return array_slice($rows, 0, 120);
}

/**
 * Return nearest known place from an offline reference list.
 *
 * @param float $lat Latitude.
 * @param float $lng Longitude.
 * @return array<string, mixed> Place label and distance.
 */
function admin_gallery_report_nearest_known_place(float $lat, float $lng): array
{
    $places = admin_gallery_report_known_places();
    $best = null;
    $nearest = null;
    foreach ($places as $place) {
        $distance = admin_gallery_report_haversine_km($lat, $lng, (float) $place['lat'], (float) $place['lng']);
        $candidate = [
            'name' => (string) $place['name'],
            'label' => admin_gallery_report_place_display_label($place),
            'kind' => (string) ($place['kind'] ?? 'city'),
            'distance_km' => $distance,
            'priority' => (float) ($place['priority'] ?? 0.0),
        ];

        if ($nearest === null || $distance < (float) $nearest['distance_km']) {
            $nearest = $candidate;
        }

        $radius = (float) ($place['radius_km'] ?? ADMIN_GALLERY_REPORT_PLACE_MATCH_DEFAULT_RADIUS_KM);
        if ($distance > $radius) {
            continue;
        }
        $score = $distance - (float) $candidate['priority'];
        if ($best === null || $score < (float) $best['score']) {
            $best = $candidate + ['score' => $score];
        }
    }

    if ($best !== null) {
        return [
            'label' => (string) $best['label'],
            'nearest_reference' => (string) $best['name'],
            'place_kind' => (string) $best['kind'],
            'place_match' => t('admin.gallery_report.export.within_radius', 'within radius'),
            'distance_km' => round((float) $best['distance_km'], 1),
        ];
    }

    if ($nearest !== null) {
        return [
            'label' => t('admin.gallery_report.export.closest_known_area', 'Closest known area: {area}', ['area' => (string) $nearest['label']]),
            'nearest_reference' => (string) $nearest['name'],
            'place_kind' => (string) $nearest['kind'],
            'place_match' => t('admin.gallery_report.export.nearest_fallback', 'nearest fallback'),
            'distance_km' => round((float) $nearest['distance_km'], 1),
        ];
    }

    return [
        'label' => t('admin.gallery_report.export.area_around_coordinates', 'Area around {lat}, {lng}', ['lat' => number_format($lat, 3, '.', ''), 'lng' => number_format($lng, 3, '.', '')]),
        'nearest_reference' => '',
        'place_kind' => 'coordinate fallback',
        'place_match' => t('admin.gallery_report.export.coordinate_fallback', 'coordinate fallback'),
        'distance_km' => null,
    ];
}

/**
 * Return the public label for an offline place reference.
 *
 * @param array $place Place definition.
 * @return string Human-readable place label.
 */
function admin_gallery_report_place_display_label(array $place): string
{
    $label = trim((string) ($place['label'] ?? ''));
    if ($label !== '') {
        return $label;
    }
    $name = trim((string) ($place['name'] ?? ''));
    if ($name !== '') {
        return t('admin.gallery_report.export.named_area', '{name} area', ['name' => $name]);
    }
    return t('admin.gallery_report.export.known_place_area', 'Known place area');
}

/**
 * Return offline place reference points used for approximate labels.
 *
 * @return array<int, array<string, mixed>> Place rows.
 */
function admin_gallery_report_known_places(): array
{
    return [
        ['name' => 'Friedrichshafen', 'label' => t('admin.gallery_report.export.place_friedrichshafen_bodensee', 'Friedrichshafen / Bodensee area'), 'lat' => 47.6505, 'lng' => 9.4790, 'radius_km' => 45.0, 'kind' => 'regional area', 'priority' => 4.0],
        ['name' => 'Konstanz', 'label' => t('admin.gallery_report.export.place_konstanz_bodensee', 'Konstanz / Bodensee area'), 'lat' => 47.6779, 'lng' => 9.1732, 'radius_km' => 38.0, 'kind' => 'regional area', 'priority' => 3.0],
        ['name' => 'Lindau', 'label' => t('admin.gallery_report.export.place_lindau_bodensee', 'Lindau / Bodensee area'), 'lat' => 47.5460, 'lng' => 9.6830, 'radius_km' => 24.0, 'kind' => 'regional area', 'priority' => 2.0],
        ['name' => 'Ravensburg', 'label' => t('admin.gallery_report.export.place_ravensburg_upper_swabia', 'Ravensburg / Upper Swabia area'), 'lat' => 47.7819, 'lng' => 9.6106, 'radius_km' => 28.0, 'kind' => 'regional area'],
        ['name' => 'Zurich', 'lat' => 47.3769, 'lng' => 8.5417, 'radius_km' => 35.0, 'kind' => 'city'],
        ['name' => 'St. Gallen', 'lat' => 47.4245, 'lng' => 9.3767, 'radius_km' => 25.0, 'kind' => 'city'],

        ['name' => 'Prague', 'lat' => 50.0755, 'lng' => 14.4378, 'radius_km' => 40.0, 'kind' => 'city'],
        ['name' => 'Pilsen', 'lat' => 49.7384, 'lng' => 13.3736, 'radius_km' => 42.0, 'kind' => 'city'],
        ['name' => 'Plasy', 'label' => t('admin.gallery_report.export.place_plasy_lkps', 'Plasy / LKPS area'), 'lat' => 49.9346, 'lng' => 13.3906, 'radius_km' => 16.0, 'kind' => 'local area', 'priority' => 5.0],
        ['name' => 'Klatovy', 'lat' => 49.3956, 'lng' => 13.2951, 'radius_km' => 24.0, 'kind' => 'town'],
        ['name' => 'Domažlice', 'lat' => 49.4405, 'lng' => 12.9298, 'radius_km' => 24.0, 'kind' => 'town'],
        ['name' => 'Rokycany', 'lat' => 49.7427, 'lng' => 13.5946, 'radius_km' => 20.0, 'kind' => 'town'],
        ['name' => 'Karlovy Vary', 'lat' => 50.2319, 'lng' => 12.8710, 'radius_km' => 28.0, 'kind' => 'city'],
        ['name' => 'Mariánské Lázně', 'lat' => 49.9646, 'lng' => 12.7012, 'radius_km' => 22.0, 'kind' => 'town'],
        ['name' => 'České Budějovice', 'lat' => 48.9745, 'lng' => 14.4743, 'radius_km' => 30.0, 'kind' => 'city'],
        ['name' => 'Český Krumlov', 'lat' => 48.8127, 'lng' => 14.3175, 'radius_km' => 18.0, 'kind' => 'town'],
        ['name' => 'Brno', 'lat' => 49.1951, 'lng' => 16.6068, 'radius_km' => 35.0, 'kind' => 'city'],
        ['name' => 'Ostrava', 'lat' => 49.8209, 'lng' => 18.2625, 'radius_km' => 35.0, 'kind' => 'city'],
        ['name' => 'Olomouc', 'lat' => 49.5938, 'lng' => 17.2509, 'radius_km' => 30.0, 'kind' => 'city'],
        ['name' => 'Liberec', 'lat' => 50.7663, 'lng' => 15.0543, 'radius_km' => 28.0, 'kind' => 'city'],
        ['name' => 'Hradec Králové', 'lat' => 50.2092, 'lng' => 15.8328, 'radius_km' => 28.0, 'kind' => 'city'],
        ['name' => 'Pardubice', 'lat' => 50.0343, 'lng' => 15.7812, 'radius_km' => 28.0, 'kind' => 'city'],
        ['name' => 'Ústí nad Labem', 'lat' => 50.6611, 'lng' => 14.0326, 'radius_km' => 26.0, 'kind' => 'city'],
        ['name' => 'Jihlava', 'lat' => 49.3961, 'lng' => 15.5903, 'radius_km' => 26.0, 'kind' => 'city'],
        ['name' => 'Zlín', 'lat' => 49.2244, 'lng' => 17.6628, 'radius_km' => 26.0, 'kind' => 'city'],
        ['name' => 'Teplice', 'lat' => 50.6404, 'lng' => 13.8245, 'radius_km' => 22.0, 'kind' => 'city'],

        ['name' => 'Berlin', 'lat' => 52.5200, 'lng' => 13.4050, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Munich', 'lat' => 48.1351, 'lng' => 11.5820, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Stuttgart', 'lat' => 48.7758, 'lng' => 9.1829, 'radius_km' => 38.0, 'kind' => 'city'],
        ['name' => 'Ulm', 'lat' => 48.4011, 'lng' => 9.9876, 'radius_km' => 28.0, 'kind' => 'city'],
        ['name' => 'Augsburg', 'lat' => 48.3705, 'lng' => 10.8978, 'radius_km' => 28.0, 'kind' => 'city'],
        ['name' => 'Memmingen', 'lat' => 47.9838, 'lng' => 10.1819, 'radius_km' => 24.0, 'kind' => 'city'],
        ['name' => 'Nuremberg', 'lat' => 49.4521, 'lng' => 11.0767, 'radius_km' => 35.0, 'kind' => 'city'],
        ['name' => 'Frankfurt', 'lat' => 50.1109, 'lng' => 8.6821, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Dresden', 'lat' => 51.0504, 'lng' => 13.7373, 'radius_km' => 32.0, 'kind' => 'city'],
        ['name' => 'Leipzig', 'lat' => 51.3397, 'lng' => 12.3731, 'radius_km' => 32.0, 'kind' => 'city'],
        ['name' => 'Hamburg', 'lat' => 53.5511, 'lng' => 9.9937, 'radius_km' => 42.0, 'kind' => 'city'],
        ['name' => 'Cologne', 'lat' => 50.9375, 'lng' => 6.9603, 'radius_km' => 35.0, 'kind' => 'city'],
        ['name' => 'Düsseldorf', 'lat' => 51.2277, 'lng' => 6.7735, 'radius_km' => 32.0, 'kind' => 'city'],

        ['name' => 'Vienna', 'lat' => 48.2082, 'lng' => 16.3738, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Salzburg', 'lat' => 47.8095, 'lng' => 13.0550, 'radius_km' => 30.0, 'kind' => 'city'],
        ['name' => 'Innsbruck', 'lat' => 47.2692, 'lng' => 11.4041, 'radius_km' => 28.0, 'kind' => 'city'],
        ['name' => 'Linz', 'lat' => 48.3069, 'lng' => 14.2858, 'radius_km' => 30.0, 'kind' => 'city'],
        ['name' => 'Graz', 'lat' => 47.0707, 'lng' => 15.4395, 'radius_km' => 30.0, 'kind' => 'city'],
        ['name' => 'Bratislava', 'lat' => 48.1486, 'lng' => 17.1077, 'radius_km' => 35.0, 'kind' => 'city'],
        ['name' => 'Kraków', 'lat' => 50.0647, 'lng' => 19.9450, 'radius_km' => 35.0, 'kind' => 'city'],
        ['name' => 'Warsaw', 'lat' => 52.2297, 'lng' => 21.0122, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Budapest', 'lat' => 47.4979, 'lng' => 19.0402, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Ljubljana', 'lat' => 46.0569, 'lng' => 14.5058, 'radius_km' => 32.0, 'kind' => 'city'],
        ['name' => 'Zagreb', 'lat' => 45.8150, 'lng' => 15.9819, 'radius_km' => 35.0, 'kind' => 'city'],

        ['name' => 'Stockholm', 'lat' => 59.3293, 'lng' => 18.0686, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Gothenburg', 'lat' => 57.7089, 'lng' => 11.9746, 'radius_km' => 38.0, 'kind' => 'city'],
        ['name' => 'Malmö', 'lat' => 55.6050, 'lng' => 13.0038, 'radius_km' => 32.0, 'kind' => 'city'],
        ['name' => 'Copenhagen', 'lat' => 55.6761, 'lng' => 12.5683, 'radius_km' => 40.0, 'kind' => 'city'],
        ['name' => 'Oslo', 'lat' => 59.9139, 'lng' => 10.7522, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Helsinki', 'lat' => 60.1699, 'lng' => 24.9384, 'radius_km' => 42.0, 'kind' => 'city'],

        ['name' => 'London', 'lat' => 51.5072, 'lng' => -0.1276, 'radius_km' => 55.0, 'kind' => 'city'],
        ['name' => 'Bristol', 'lat' => 51.4545, 'lng' => -2.5879, 'radius_km' => 32.0, 'kind' => 'city'],
        ['name' => 'Manchester', 'lat' => 53.4808, 'lng' => -2.2426, 'radius_km' => 40.0, 'kind' => 'city'],
        ['name' => 'Edinburgh', 'lat' => 55.9533, 'lng' => -3.1883, 'radius_km' => 35.0, 'kind' => 'city'],
        ['name' => 'Paris', 'lat' => 48.8566, 'lng' => 2.3522, 'radius_km' => 55.0, 'kind' => 'city'],
        ['name' => 'Marseille', 'lat' => 43.2965, 'lng' => 5.3698, 'radius_km' => 36.0, 'kind' => 'city'],
        ['name' => 'Lyon', 'lat' => 45.7640, 'lng' => 4.8357, 'radius_km' => 36.0, 'kind' => 'city'],
        ['name' => 'Nice', 'lat' => 43.7102, 'lng' => 7.2620, 'radius_km' => 32.0, 'kind' => 'city'],
        ['name' => 'Amsterdam', 'lat' => 52.3676, 'lng' => 4.9041, 'radius_km' => 42.0, 'kind' => 'city'],
        ['name' => 'Brussels', 'lat' => 50.8503, 'lng' => 4.3517, 'radius_km' => 38.0, 'kind' => 'city'],
        ['name' => 'Milan', 'lat' => 45.4642, 'lng' => 9.1900, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Rome', 'lat' => 41.9028, 'lng' => 12.4964, 'radius_km' => 55.0, 'kind' => 'city'],
        ['name' => 'Barcelona', 'lat' => 41.3874, 'lng' => 2.1686, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Madrid', 'lat' => 40.4168, 'lng' => -3.7038, 'radius_km' => 55.0, 'kind' => 'city'],
        ['name' => 'Lisbon', 'lat' => 38.7223, 'lng' => -9.1393, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Athens', 'lat' => 37.9838, 'lng' => 23.7275, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Istanbul', 'lat' => 41.0082, 'lng' => 28.9784, 'radius_km' => 55.0, 'kind' => 'city'],

        ['name' => 'Chicago', 'lat' => 41.8781, 'lng' => -87.6298, 'radius_km' => 55.0, 'kind' => 'city'],
        ['name' => 'New York', 'lat' => 40.7128, 'lng' => -74.0060, 'radius_km' => 55.0, 'kind' => 'city'],
        ['name' => 'Los Angeles', 'lat' => 34.0522, 'lng' => -118.2437, 'radius_km' => 70.0, 'kind' => 'city'],
        ['name' => 'San Francisco', 'lat' => 37.7749, 'lng' => -122.4194, 'radius_km' => 55.0, 'kind' => 'city'],
        ['name' => 'Miami', 'lat' => 25.7617, 'lng' => -80.1918, 'radius_km' => 55.0, 'kind' => 'city'],
        ['name' => 'Toronto', 'lat' => 43.6532, 'lng' => -79.3832, 'radius_km' => 50.0, 'kind' => 'city'],
        ['name' => 'Dubai', 'lat' => 25.2048, 'lng' => 55.2708, 'radius_km' => 55.0, 'kind' => 'city'],
        ['name' => 'Singapore', 'lat' => 1.3521, 'lng' => 103.8198, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Tokyo', 'lat' => 35.6762, 'lng' => 139.6503, 'radius_km' => 65.0, 'kind' => 'city'],
        ['name' => 'Sydney', 'lat' => -33.8688, 'lng' => 151.2093, 'radius_km' => 60.0, 'kind' => 'city'],
        ['name' => 'Melbourne', 'lat' => -37.8136, 'lng' => 144.9631, 'radius_km' => 60.0, 'kind' => 'city'],
    ];
}

/**
 * Compute distance between two coordinates.
 *
 * @param float $lat1 First latitude.
 * @param float $lng1 First longitude.
 * @param float $lat2 Second latitude.
 * @param float $lng2 Second longitude.
 * @return float Distance in kilometers.
 */
function admin_gallery_report_haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthKm = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $earthKm * 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
}

/**
 * Build a storage snapshot from report accumulators.
 *
 * @param array $job Job data.
 * @return array<string, mixed> Storage snapshot.
 */
function admin_gallery_report_storage_snapshot(array $job): array
{
    if (!function_exists('Gallery\\Services\\admin_storage_statistics_compact_source_summary') || !function_exists('Gallery\\Services\\admin_storage_statistics_snapshot_from_summaries')) {
        return ['available' => false, 'error' => 'Storage statistics service is not available.'];
    }
    $source = admin_storage_statistics_compact_source_summary(is_array($job['storage_source'] ?? null) ? $job['storage_source'] : []);
    $generated = is_array($job['storage_generated'] ?? null) ? $job['storage_generated'] : [];
    $snapshot = admin_storage_statistics_snapshot_from_summaries('', $source, $generated);
    $snapshot['available'] = true;
    $snapshot['thumbnail_metadata_used'] = !empty($job['thumbnail_metadata_used']);
    return $snapshot;
}

/**
 * Return site identity and installed version information.
 *
 * @return array<string, mixed> Site summary.
 */
function admin_gallery_report_site_summary(): array
{
    $config = cms_config();
    return [
        'site_name' => function_exists('Gallery\\Services\\site_name') ? site_name() : 'PHP Gallery',
        'version' => function_exists('Gallery\\Core\\cms_current_version') ? cms_current_version() : '',
        'base_url' => (string) ($config['base_url'] ?? ''),
        'language' => function_exists('Gallery\\Services\\translation_active_language') ? translation_active_language() : 'en',
        'generated_at_utc' => gmdate('c'),
        'server_time' => date('c'),
    ];
}

/**
 * Return PHP, server, and extension diagnostics.
 *
 * @return array<string, mixed> Runtime summary.
 */
function admin_gallery_report_runtime_summary(): array
{
    $extensions = ['exif', 'gd', 'imagick', 'pdo_mysql', 'zip', 'json', 'mbstring', 'fileinfo', 'openssl', 'curl'];
    $extensionRows = [];
    foreach ($extensions as $extension) {
        $extensionRows[] = ['extension' => $extension, 'loaded' => extension_loaded($extension) ? 'yes' : 'no'];
    }

    return [
        'php_version' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'os' => PHP_OS_FAMILY . ' / ' . PHP_OS,
        'uname' => php_uname(),
        'server_software' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''),
        'document_root' => (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''),
        'memory_limit' => (string) ini_get('memory_limit'),
        'max_execution_time' => (string) ini_get('max_execution_time'),
        'max_input_vars' => (string) ini_get('max_input_vars'),
        'post_max_size' => (string) ini_get('post_max_size'),
        'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
        'max_file_uploads' => (string) ini_get('max_file_uploads'),
        'timezone' => date_default_timezone_get(),
        'current_memory_usage_bytes' => memory_get_usage(true),
        'peak_memory_usage_bytes' => memory_get_peak_usage(true),
        'server_memory' => admin_gallery_report_server_memory_summary(),
        'load_average' => function_exists('sys_getloadavg') ? (sys_getloadavg() ?: []) : [],
        'extensions' => $extensionRows,
        'gd_info' => function_exists('gd_info') ? gd_info() : [],
        'imagick_version' => class_exists('Imagick') ? (string) (\Imagick::getVersion()['versionString'] ?? '') : '',
        'mysql_version' => admin_gallery_report_mysql_version(),
    ];
}

/**
 * Return physical server memory information when the host exposes it.
 *
 * @return array<string, mixed> Memory summary.
 */
function admin_gallery_report_server_memory_summary(): array
{
    $summary = [
        'available' => false,
        'source' => '',
        'total_bytes' => 0,
        'available_bytes' => 0,
        'free_bytes' => 0,
        'swap_total_bytes' => 0,
        'swap_free_bytes' => 0,
    ];
    if (!is_readable('/proc/meminfo')) {
        return $summary;
    }

    $raw = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($raw)) {
        return $summary;
    }
    $values = [];
    foreach ($raw as $line) {
        if (!preg_match('/^([A-Za-z_()]+):\s+(\d+)\s+kB$/', (string) $line, $matches)) {
            continue;
        }
        $values[$matches[1]] = (int) $matches[2] * 1024;
    }

    $summary['available'] = isset($values['MemTotal']);
    $summary['source'] = '/proc/meminfo';
    $summary['total_bytes'] = (int) ($values['MemTotal'] ?? 0);
    $summary['available_bytes'] = (int) ($values['MemAvailable'] ?? 0);
    $summary['free_bytes'] = (int) ($values['MemFree'] ?? 0);
    $summary['swap_total_bytes'] = (int) ($values['SwapTotal'] ?? 0);
    $summary['swap_free_bytes'] = (int) ($values['SwapFree'] ?? 0);
    return $summary;
}

/**
 * Return MySQL or MariaDB server version.
 *
 * @return string Version string.
 */
function admin_gallery_report_mysql_version(): string
{
    try {
        return (string) (db()->query('SELECT VERSION()')->fetchColumn() ?: '');
    } catch (Throwable) {
        return '';
    }
}

/**
 * Return configured storage paths and disk metrics.
 *
 * @return array<string, mixed> Path summary.
 */
function admin_gallery_report_data_path_summary(): array
{
    $config = cms_config();
    $paths = [
        ['label' => 'Gallery root', 'path' => (string) ($config['galleries_root'] ?? '')],
        ['label' => 'ZIP cache', 'path' => (string) ($config['zip_cache_path'] ?? '')],
        ['label' => 'Application cache', 'path' => dirname(__DIR__, 2) . '/cache'],
        ['label' => 'Navigation data', 'path' => (string) ($config['navigation_data']['bundled_navdata_path'] ?? '')],
    ];
    foreach ($paths as &$path) {
        $path['exists'] = $path['path'] !== '' && file_exists((string) $path['path']) ? 'yes' : 'no';
        $path['readable'] = $path['path'] !== '' && is_readable((string) $path['path']) ? 'yes' : 'no';
        $path['writable'] = $path['path'] !== '' && is_writable((string) $path['path']) ? 'yes' : 'no';
        $path['free_bytes'] = admin_gallery_report_disk_free_bytes((string) $path['path']);
        $path['total_bytes'] = admin_gallery_report_disk_total_bytes((string) $path['path']);
    }
    unset($path);
    return ['paths' => $paths];
}

/**
 * Return free disk bytes for a path when available.
 *
 * @param string $path Filesystem path.
 * @return int Free bytes.
 */
function admin_gallery_report_disk_free_bytes(string $path): int
{
    if ($path === '' || !\function_exists('disk_free_space')) {
        return 0;
    }
    $probe = is_dir($path) ? $path : dirname($path);
    $bytes = @\disk_free_space($probe);
    return is_float($bytes) ? (int) $bytes : 0;
}

/**
 * Return total disk bytes for a path when available.
 *
 * @param string $path Filesystem path.
 * @return int Total bytes.
 */
function admin_gallery_report_disk_total_bytes(string $path): int
{
    if ($path === '' || !\function_exists('disk_total_space')) {
        return 0;
    }
    $probe = is_dir($path) ? $path : dirname($path);
    $bytes = @\disk_total_space($probe);
    return is_float($bytes) ? (int) $bytes : 0;
}

/**
 * Return database usage and table metadata.
 *
 * @return array<string, mixed> Database section.
 */
function admin_gallery_report_database_section(): array
{
    $databaseName = function_exists('Gallery\Services\admin_database_usage_current_database_name') ? admin_database_usage_current_database_name() : '';
    $usage = function_exists('Gallery\Services\admin_database_usage_summary') ? admin_database_usage_summary() : ['available' => false, 'error' => 'Database usage service is not available.'];
    $exactCounts = admin_gallery_report_exact_database_table_counts($databaseName);

    return [
        'usage' => admin_gallery_report_enrich_database_usage_for_report($usage, $exactCounts),
        'database_name' => $databaseName,
        'exact_row_counts_available' => !empty($exactCounts['available']),
        'exact_row_count_errors' => is_array($exactCounts['errors'] ?? null) ? $exactCounts['errors'] : [],
    ];
}

/**
 * Return exact row counts for base tables in the active database.
 *
 * information_schema.TABLES.TABLE_ROWS is only an engine estimate for InnoDB
 * and may be stale or zero on some shared-hosting installations. The complete
 * report uses exact COUNT(*) values because correctness is more important than
 * generation speed for this maintenance export.
 *
 * @param string $databaseName Active database name.
 * @return array<string, mixed> Exact count payload.
 */
function admin_gallery_report_exact_database_table_counts(string $databaseName): array
{
    $result = [
        'available' => false,
        'counts' => [],
        'errors' => [],
        'total_rows' => 0,
    ];
    if ($databaseName === '') {
        $result['errors'][] = 'Database name is empty.';
        return $result;
    }

    try {
        $stmt = db()->prepare("SELECT TABLE_NAME AS table_name FROM information_schema.TABLES WHERE TABLE_SCHEMA = :database_name AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME ASC");
        $stmt->execute(['database_name' => $databaseName]);
        $tables = $stmt->fetchAll();
    } catch (Throwable $exception) {
        $result['errors'][] = $exception->getMessage();
        return $result;
    }

    $result['available'] = true;
    foreach ($tables as $row) {
        $tableName = trim((string) ($row['table_name'] ?? ''));
        if ($tableName === '') {
            continue;
        }
        try {
            $count = (int) (db()->query('SELECT COUNT(*) FROM ' . admin_gallery_report_quote_identifier($tableName))->fetchColumn() ?: 0);
            $result['counts'][$tableName] = $count;
            $result['total_rows'] = (int) ($result['total_rows'] ?? 0) + $count;
        } catch (Throwable $exception) {
            $result['errors'][] = $tableName . ': ' . $exception->getMessage();
        }
    }

    return $result;
}

/**
 * Add exact report-only row counts and compatibility aliases to database usage rows.
 *
 * @param array $usage Database usage payload.
 * @param array $exactCounts Exact count payload.
 * @return array<string, mixed> Enriched usage payload.
 */
function admin_gallery_report_enrich_database_usage_for_report(array $usage, array $exactCounts): array
{
    if (empty($usage['available'])) {
        return $usage;
    }

    $counts = is_array($exactCounts['counts'] ?? null) ? $exactCounts['counts'] : [];
    $usage['table_rows_exact_available'] = !empty($exactCounts['available']);
    $usage['table_rows_exact'] = (int) ($exactCounts['total_rows'] ?? 0);
    $usage['table_rows_exact_counted_tables'] = count($counts);
    $usage['exact_row_count_errors'] = is_array($exactCounts['errors'] ?? null) ? $exactCounts['errors'] : [];
    $usage['table_rows'] = admin_gallery_report_enrich_database_table_rows(is_array($usage['table_rows'] ?? null) ? $usage['table_rows'] : [], $counts);
    $usage['gallery_table_rows'] = admin_gallery_report_enrich_database_table_rows(is_array($usage['gallery_table_rows'] ?? null) ? $usage['gallery_table_rows'] : [], $counts);

    return $usage;
}

/**
 * Add exact row counts and stable byte aliases to rendered database table rows.
 *
 * @param array $rows Table metadata rows.
 * @param array $exactCounts Exact row count lookup by table name.
 * @return array<int, array<string, mixed>> Enriched rows.
 */
function admin_gallery_report_enrich_database_table_rows(array $rows, array $exactCounts): array
{
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $tableName = trim((string) ($row['table_name'] ?? $row['label'] ?? ''));
        $estimatedRows = max(0, (int) ($row['count'] ?? $row['table_rows'] ?? 0));
        $totalBytes = max(0, (int) ($row['bytes'] ?? $row['total_bytes'] ?? 0));
        $rows[$index]['table_name'] = $tableName;
        $rows[$index]['table_rows_estimate'] = $estimatedRows;
        $rows[$index]['total_bytes'] = $totalBytes;
        $rows[$index]['bytes'] = $totalBytes;
        $rows[$index]['row_count_source'] = 'estimate';
        if ($tableName !== '' && array_key_exists($tableName, $exactCounts)) {
            $rows[$index]['rows_exact'] = (int) $exactCounts[$tableName];
            $rows[$index]['rows_display'] = (int) $exactCounts[$tableName];
            $rows[$index]['row_count_source'] = 'COUNT(*)';
        } else {
            $rows[$index]['rows_exact'] = null;
            $rows[$index]['rows_display'] = $estimatedRows;
        }
    }
    return $rows;
}

/**
 * Quote a database identifier for report-only exact count queries.
 *
 * @param string $identifier Identifier value.
 * @return string Quoted identifier.
 */
function admin_gallery_report_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * Return row counts for known application tables.
 *
 * @return array<int, array<string, mixed>> Table count rows.
 */
function admin_gallery_report_table_counts(): array
{
    $tables = [
        'galleries', 'images', 'tags', 'gallery_tags', 'image_tags', 'image_votes', 'picture_game_votes',
        'zip_archives', 'gallery_upload_tokens', 'gallery_flight_maps', 'image_ai_metadata', 'image_ai_analysis_jobs',
        'image_thumbnail_variants', 'admin_logs', 'app_settings', 'users', 'migrations', 'telemetry_events',
        'telemetry_sessions', 'telemetry_hourly_metrics', 'telemetry_daily_metrics', 'telemetry_db_query_metrics',
        'telemetry_job_runs', 'telemetry_settings', 'navigation_data_accounts', 'navigation_data_cache',
    ];
    $rows = [];
    foreach ($tables as $table) {
        if (!function_exists('Gallery\\Services\\db_table_exists') || !db_table_exists($table)) {
            $rows[] = ['table_name' => $table, 'exists' => 'no', 'rows' => null];
            continue;
        }
        try {
            $rows[] = ['table_name' => $table, 'exists' => 'yes', 'rows' => (int) (db()->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')->fetchColumn() ?: 0)];
        } catch (Throwable $exception) {
            $rows[] = ['table_name' => $table, 'exists' => 'yes', 'rows' => null, 'error' => $exception->getMessage()];
        }
    }
    return $rows;
}

/**
 * Return gallery structure and policy summary.
 *
 * @return array<string, mixed> Gallery summary.
 */
function admin_gallery_report_gallery_summary(): array
{
    $summary = [
        'total' => admin_gallery_report_scalar_int('SELECT COUNT(*) FROM galleries'),
        'root_count' => admin_gallery_report_scalar_int('SELECT COUNT(*) FROM galleries WHERE parent_id IS NULL'),
        'nested_count' => admin_gallery_report_scalar_int('SELECT COUNT(*) FROM galleries WHERE parent_id IS NOT NULL'),
        'empty_count' => admin_gallery_report_scalar_int('SELECT COUNT(*) FROM galleries g LEFT JOIN images i ON i.gallery_id = g.id GROUP BY g.id HAVING COUNT(i.id) = 0', [], true),
        'visibility_rows' => admin_gallery_report_group_query('SELECT visibility AS label, COUNT(*) AS count FROM galleries GROUP BY visibility ORDER BY count DESC'),
        'access_rows' => admin_gallery_report_group_query('SELECT access_mode AS label, COUNT(*) AS count FROM galleries GROUP BY access_mode ORDER BY count DESC'),
        'listing_rows' => admin_gallery_report_group_query('SELECT access_listing AS label, COUNT(*) AS count FROM galleries GROUP BY access_listing ORDER BY count DESC'),
        'image_visibility_rows' => admin_gallery_report_group_query('SELECT visibility AS label, COUNT(*) AS count FROM images GROUP BY visibility ORDER BY count DESC'),
        'largest_rows' => admin_gallery_report_rows('SELECT g.id, g.title, g.folder_path, g.visibility, COUNT(i.id) AS image_count, COALESCE(SUM(COALESCE(i.file_size, 0)), 0) AS source_bytes FROM galleries g LEFT JOIN images i ON i.gallery_id = g.id GROUP BY g.id, g.title, g.folder_path, g.visibility ORDER BY image_count DESC, source_bytes DESC LIMIT 80'),
        'deepest_depth' => admin_gallery_report_deepest_gallery_depth(),
    ];
    if (function_exists('Gallery\\Services\\db_column_exists') && db_column_exists('galleries', 'date_start')) {
        $summary['dated_gallery_count'] = admin_gallery_report_scalar_int('SELECT COUNT(*) FROM galleries WHERE date_start IS NOT NULL OR date_end IS NOT NULL');
    }
    if (function_exists('Gallery\\Services\\db_column_exists') && db_column_exists('galleries', 'gps_map_enabled')) {
        $summary['gps_map_override_rows'] = admin_gallery_report_group_query('SELECT gps_map_enabled AS label, COUNT(*) AS count FROM galleries GROUP BY gps_map_enabled ORDER BY gps_map_enabled ASC');
    }
    return $summary;
}

/**
 * Return the approximate deepest gallery nesting level.
 *
 * @return int Maximum depth.
 */
function admin_gallery_report_deepest_gallery_depth(): int
{
    try {
        $rows = db()->query('SELECT id, parent_id FROM galleries')->fetchAll();
    } catch (Throwable) {
        return 0;
    }
    $parentById = [];
    foreach ($rows as $row) {
        $parentById[(int) ($row['id'] ?? 0)] = isset($row['parent_id']) ? (int) $row['parent_id'] : 0;
    }
    $maxDepth = 0;
    foreach (array_keys($parentById) as $id) {
        $depth = 0;
        $guard = 0;
        $cursor = $id;
        while ($cursor > 0 && isset($parentById[$cursor]) && $parentById[$cursor] > 0 && $guard < 200) {
            $depth++;
            $cursor = (int) $parentById[$cursor];
            $guard++;
        }
        $maxDepth = max($maxDepth, $depth);
    }
    return $maxDepth;
}

/**
 * Return one row per gallery with key operational details.
 *
 * @return array<int, array<string, mixed>> Gallery rows.
 */
function admin_gallery_report_gallery_detail_rows(): array
{
    $gpsImageExpression = (db_column_exists('images', 'gps_lat') && db_column_exists('images', 'gps_lng'))
        ? 'SUM(CASE WHEN i.gps_lat IS NOT NULL AND i.gps_lng IS NOT NULL THEN 1 ELSE 0 END) AS gps_images'
        : '0 AS gps_images';
    $selects = [
        'g.id', 'g.parent_id', 'g.title', 'g.folder_path', 'g.slug', 'g.visibility', 'g.access_mode', 'g.access_listing',
        'g.sort_order', 'g.created_at', 'g.updated_at',
        'COUNT(i.id) AS image_count',
        'COALESCE(SUM(COALESCE(i.file_size, 0)), 0) AS source_bytes',
        $gpsImageExpression,
        admin_gallery_report_column_select('galleries', 'date_start', 'g', 'NULL', 'date_start'),
        admin_gallery_report_column_select('galleries', 'date_end', 'g', 'NULL', 'date_end'),
        admin_gallery_report_column_select('galleries', 'gps_map_enabled', 'g', 'NULL', 'gps_map_enabled'),
        admin_gallery_report_column_select('galleries', 'picture_game_enabled', 'g', 'NULL', 'picture_game_enabled'),
    ];
    $groupBy = 'g.id, g.parent_id, g.title, g.folder_path, g.slug, g.visibility, g.access_mode, g.access_listing, g.sort_order, g.created_at, g.updated_at';
    if (function_exists('Gallery\\Services\\db_column_exists') && db_column_exists('galleries', 'date_start')) {
        $groupBy .= ', g.date_start, g.date_end';
    }
    if (function_exists('Gallery\\Services\\db_column_exists') && db_column_exists('galleries', 'gps_map_enabled')) {
        $groupBy .= ', g.gps_map_enabled';
    }
    if (function_exists('Gallery\\Services\\db_column_exists') && db_column_exists('galleries', 'picture_game_enabled')) {
        $groupBy .= ', g.picture_game_enabled';
    }
    return admin_gallery_report_rows('SELECT ' . implode(', ', $selects) . ' FROM galleries g LEFT JOIN images i ON i.gallery_id = g.id GROUP BY ' . $groupBy . ' ORDER BY g.folder_path ASC');
}

/**
 * Return tag statistics.
 *
 * @return array<string, mixed> Tag summary.
 */
function admin_gallery_report_tag_summary(): array
{
    return [
        'tag_count' => admin_gallery_report_table_exists('tags') ? admin_gallery_report_scalar_int('SELECT COUNT(*) FROM tags') : 0,
        'gallery_tag_rows' => admin_gallery_report_table_exists('gallery_tags') ? admin_gallery_report_rows('SELECT t.name AS label, COUNT(gt.gallery_id) AS count FROM tags t INNER JOIN gallery_tags gt ON gt.tag_id = t.id GROUP BY t.id, t.name ORDER BY count DESC, t.name ASC LIMIT 80') : [],
        'image_tag_rows' => admin_gallery_report_table_exists('image_tags') ? admin_gallery_report_rows('SELECT t.name AS label, COUNT(it.image_id) AS count FROM tags t INNER JOIN image_tags it ON it.tag_id = t.id GROUP BY t.id, t.name ORDER BY count DESC, t.name ASC LIMIT 80') : [],
    ];
}

/**
 * Return vote statistics.
 *
 * @return array<string, mixed> Vote summary.
 */
function admin_gallery_report_vote_summary(): array
{
    return [
        'image_vote_rows' => admin_gallery_report_table_exists('image_votes') ? admin_gallery_report_group_query('SELECT vote AS label, COUNT(*) AS count FROM image_votes GROUP BY vote ORDER BY vote DESC') : [],
        'picture_game_vote_rows' => admin_gallery_report_table_exists('picture_game_votes') ? admin_gallery_report_group_query('SELECT vote AS label, COUNT(*) AS count FROM picture_game_votes GROUP BY vote ORDER BY vote DESC') : [],
    ];
}

/**
 * Return app settings and feature visibility information.
 *
 * @return array<string, mixed> Feature summary.
 */
function admin_gallery_report_feature_summary(): array
{
    $settings = [];
    if (admin_gallery_report_table_exists('app_settings')) {
        $settings = admin_gallery_report_rows("SELECT setting_key, setting_value, updated_at FROM app_settings WHERE setting_key LIKE 'feature_%' OR setting_key LIKE '%enabled%' OR setting_key LIKE '%telemetry%' OR setting_key LIKE '%thumbnail%' ORDER BY setting_key ASC LIMIT 250");
    }
    $telemetrySettings = [];
    if (function_exists('Gallery\\Services\\telemetry_settings_schema_ready') && telemetry_settings_schema_ready() && function_exists('Gallery\\Services\\telemetry_all_settings')) {
        $telemetrySettings = telemetry_all_settings();
    }
    return [
        'settings_rows' => $settings,
        'telemetry_settings' => $telemetrySettings,
    ];
}

/**
 * Return operational log summary.
 *
 * @return array<string, mixed> Log summary.
 */
function admin_gallery_report_admin_log_summary(): array
{
    if (!admin_gallery_report_table_exists('admin_logs')) {
        return ['available' => false, 'level_rows' => [], 'severity_rows' => [], 'category_rows' => [], 'recent_errors' => []];
    }
    $hasSeverity = function_exists('Gallery\\Services\\db_column_exists') && db_column_exists('admin_logs', 'severity');
    $hasCategory = function_exists('Gallery\\Services\\db_column_exists') && db_column_exists('admin_logs', 'category');
    return [
        'available' => true,
        'total' => admin_gallery_report_scalar_int('SELECT COUNT(*) FROM admin_logs'),
        'last_7_days' => admin_gallery_report_scalar_int('SELECT COUNT(*) FROM admin_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'),
        'level_rows' => admin_gallery_report_group_query('SELECT level AS label, COUNT(*) AS count FROM admin_logs GROUP BY level ORDER BY count DESC'),
        'severity_rows' => $hasSeverity ? admin_gallery_report_group_query('SELECT severity AS label, COUNT(*) AS count FROM admin_logs GROUP BY severity ORDER BY count DESC') : [],
        'category_rows' => $hasCategory ? admin_gallery_report_group_query('SELECT category AS label, COUNT(*) AS count FROM admin_logs GROUP BY category ORDER BY count DESC') : [],
        'recent_errors' => admin_gallery_report_rows("SELECT created_at, level, " . ($hasSeverity ? 'severity,' : "'' AS severity,") . " event_key, message, route_name FROM admin_logs WHERE level IN ('error','critical')" . ($hasSeverity ? " OR severity IN ('error','critical')" : '') . " ORDER BY created_at DESC LIMIT 80"),
        'top_events' => admin_gallery_report_group_query('SELECT event_key AS label, COUNT(*) AS count FROM admin_logs GROUP BY event_key ORDER BY count DESC LIMIT 80'),
    ];
}

/**
 * Return telemetry summary data.
 *
 * @param int $days Telemetry window in days.
 * @return array<string, mixed> Telemetry section.
 */
function admin_gallery_report_telemetry_section(int $days): array
{
    if (!function_exists('Gallery\\Services\\telemetry_schema_ready') || !telemetry_schema_ready()) {
        return ['available' => false, 'days' => $days, 'message' => 'Telemetry schema is not available.'];
    }
    $windows = [];
    foreach ([7, 30, 90, 365] as $windowDays) {
        $sessions = function_exists('Gallery\\Services\\telemetry_report_session_summary') ? telemetry_report_session_summary($windowDays) : [];
        $databaseTotals = function_exists('Gallery\\Services\\telemetry_report_database_totals') ? telemetry_report_database_totals($windowDays) : [];
        $windows[] = [
            'days' => $windowDays,
            'sessions' => (int) ($sessions['sessions'] ?? 0),
            'page_views' => (int) ($sessions['page_views'] ?? 0),
            'photo_views' => (int) ($sessions['photo_views'] ?? 0),
            'duration_seconds' => (int) ($sessions['duration_seconds'] ?? 0),
            'db_queries' => (int) ($databaseTotals['query_count'] ?? 0),
            'db_slow' => (int) ($databaseTotals['slow_count'] ?? 0),
            'db_failed' => (int) ($databaseTotals['failed_count'] ?? 0),
        ];
    }
    return [
        'available' => true,
        'days' => $days,
        'public_enabled' => function_exists('Gallery\\Services\\telemetry_public_usage_enabled') && telemetry_public_usage_enabled(),
        'windows' => $windows,
        'session_summary' => function_exists('Gallery\\Services\\telemetry_report_session_summary') ? telemetry_report_session_summary($days) : [],
        'daily_trends' => function_exists('Gallery\\Services\\telemetry_report_daily_trends') ? telemetry_report_daily_trends($days) : [],
        'top_galleries' => function_exists('Gallery\\Services\\telemetry_report_top_galleries') ? telemetry_report_top_galleries($days, 60) : [],
        'top_routes' => function_exists('Gallery\\Services\\telemetry_report_top_routes') ? telemetry_report_top_routes($days, 60) : [],
        'page_kinds' => function_exists('Gallery\\Services\\telemetry_report_metric_distribution') ? telemetry_report_metric_distribution('page_kind', $days, 'public.page_views', 20) : [],
        'browsers' => function_exists('Gallery\\Services\\telemetry_report_session_distribution') ? telemetry_report_session_distribution('browser_family', $days, 20) : [],
        'operating_systems' => function_exists('Gallery\\Services\\telemetry_report_session_distribution') ? telemetry_report_session_distribution('os_family', $days, 20) : [],
        'devices' => function_exists('Gallery\\Services\\telemetry_report_session_distribution') ? telemetry_report_session_distribution('device_type', $days, 20) : [],
        'referrers' => function_exists('Gallery\\Services\\telemetry_report_session_distribution') ? telemetry_report_session_distribution('entry_referrer_category', $days, 20) : [],
        'performance' => function_exists('Gallery\\Services\\telemetry_report_performance_metrics') ? telemetry_report_performance_metrics($days) : [],
        'client_errors' => function_exists('Gallery\\Services\\telemetry_report_client_errors') ? telemetry_report_client_errors($days, 60) : [],
        'database_summary' => function_exists('Gallery\\Services\\telemetry_report_database_summary') ? telemetry_report_database_summary($days, 80) : [],
        'database_fingerprints' => function_exists('Gallery\\Services\\telemetry_report_database_fingerprints') ? telemetry_report_database_fingerprints($days, 60) : [],
        'job_runs' => function_exists('Gallery\\Services\\telemetry_report_job_runs') ? telemetry_report_job_runs($days, 80) : [],
        'recent_events' => function_exists('Gallery\\Services\\telemetry_report_recent_events') ? telemetry_report_recent_events($days, 120) : [],
    ];
}

/**
 * Return largest source images.
 *
 * @param int $limit Maximum number of rows.
 * @return array<int, array<string, mixed>> Image rows.
 */
function admin_gallery_report_largest_images(int $limit = 200): array
{
    $limit = max(1, min(500, $limit));
    return admin_gallery_report_rows('SELECT i.id, i.filename, i.relative_path, i.mime_type, i.file_size, i.width, i.height, i.visibility, g.title AS gallery_title, g.folder_path AS gallery_folder_path FROM images i INNER JOIN galleries g ON g.id = i.gallery_id ORDER BY COALESCE(i.file_size, 0) DESC, i.id DESC LIMIT ' . $limit);
}

/**
 * Return whether a table exists.
 *
 * @param string $table Table name.
 * @return bool True when the table exists.
 */
function admin_gallery_report_table_exists(string $table): bool
{
    return function_exists('Gallery\\Services\\db_table_exists') && db_table_exists($table);
}

/**
 * Read rows with failure isolation.
 *
 * @param string $sql SQL query.
 * @param array $params Query parameters.
 * @return array<int, array<string, mixed>> Rows.
 */
function admin_gallery_report_rows(string $sql, array $params = []): array
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

/**
 * Read scalar integer with failure isolation.
 *
 * @param string $sql SQL query.
 * @param array $params Query parameters.
 * @param bool $countRows Count result rows instead of reading first column.
 * @return int Integer value.
 */
function admin_gallery_report_scalar_int(string $sql, array $params = [], bool $countRows = false): int
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        if ($countRows) {
            return count($stmt->fetchAll());
        }
        return (int) ($stmt->fetchColumn() ?: 0);
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Read grouping rows with standard labels and counts.
 *
 * @param string $sql SQL query.
 * @param array $params Query parameters.
 * @return array<int, array<string, mixed>> Group rows.
 */
function admin_gallery_report_group_query(string $sql, array $params = []): array
{
    $rows = admin_gallery_report_rows($sql, $params);
    foreach ($rows as &$row) {
        $row['label'] = (string) ($row['label'] ?? 'unknown');
        $row['count'] = (int) ($row['count'] ?? 0);
    }
    unset($row);
    return $rows;
}

/**
 * Add one value to an aggregate group.
 *
 * @param array $groups Group accumulator.
 * @param string $key Group key.
 * @param string $label Human label.
 * @param int $count Count increment.
 * @param int $bytes Byte increment.
 * @param array $meta Additional fields.
 */
function admin_gallery_report_add_group(array &$groups, string $key, string $label, int $count, int $bytes = 0, array $meta = []): void
{
    if (!isset($groups[$key]) || !is_array($groups[$key])) {
        $groups[$key] = array_merge([
            'key' => $key,
            'label' => $label,
            'count' => 0,
            'bytes' => 0,
        ], $meta);
    }
    $groups[$key]['count'] = (int) ($groups[$key]['count'] ?? 0) + $count;
    $groups[$key]['bytes'] = (int) ($groups[$key]['bytes'] ?? 0) + $bytes;
}

/**
 * Finalize group rows sorted by count or bytes.
 *
 * @param array $groups Group accumulator.
 * @param string $sortKey Sort key.
 * @param int $limit Maximum rows.
 * @return array<int, array<string, mixed>> Final rows.
 */
function admin_gallery_report_finalize_group_rows(array $groups, string $sortKey = 'count', int $limit = 80): array
{
    $rows = array_values(array_filter($groups, static fn ($row): bool => is_array($row)));
    usort($rows, static function (array $a, array $b) use ($sortKey): int {
        $primary = ((int) ($b[$sortKey] ?? 0)) <=> ((int) ($a[$sortKey] ?? 0));
        if ($primary !== 0) {
            return $primary;
        }
        return strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    });
    return array_slice($rows, 0, max(1, $limit));
}

/**
 * Update a string date range.
 *
 * @param array $target Target array.
 * @param string $minKey Minimum key.
 * @param string $maxKey Maximum key.
 * @param string $value Date value.
 */
function admin_gallery_report_update_date_range(array &$target, string $minKey, string $maxKey, string $value): void
{
    if (!admin_gallery_report_valid_datetime($value)) {
        return;
    }
    if (empty($target[$minKey]) || strcmp($value, (string) $target[$minKey]) < 0) {
        $target[$minKey] = $value;
    }
    if (empty($target[$maxKey]) || strcmp($value, (string) $target[$maxKey]) > 0) {
        $target[$maxKey] = $value;
    }
}

/**
 * Update a float range.
 *
 * @param array $target Target array.
 * @param string $minKey Minimum key.
 * @param string $maxKey Maximum key.
 * @param float $value Numeric value.
 */
function admin_gallery_report_update_float_range(array &$target, string $minKey, string $maxKey, float $value): void
{
    if ($target[$minKey] === null || $value < (float) $target[$minKey]) {
        $target[$minKey] = $value;
    }
    if ($target[$maxKey] === null || $value > (float) $target[$maxKey]) {
        $target[$maxKey] = $value;
    }
}

/**
 * Return whether a database datetime looks meaningful.
 *
 * @param string $value Date value.
 * @return bool True for usable values.
 */
function admin_gallery_report_valid_datetime(string $value): bool
{
    $value = trim($value);
    return $value !== '' && $value !== '0000-00-00 00:00:00' && $value > '1000-01-01 00:00:00';
}

/**
 * Return normalized extension for grouping.
 *
 * @param string $filename Filename value.
 * @return string Extension.
 */
function admin_gallery_report_file_extension(string $filename): string
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return $extension !== '' ? $extension : 'unknown';
}

/**
 * Normalize a human label.
 *
 * @param string $label Label value.
 * @return string Compacted label.
 */
function admin_gallery_report_compact_label(string $label): string
{
    return trim((string) preg_replace('/\s+/', ' ', $label));
}

/**
 * Return ISO bucket label.
 *
 * @param int $iso ISO value.
 * @return string Bucket label.
 */
function admin_gallery_report_iso_bucket(int $iso): string
{
    if ($iso <= 100) {
        return t('admin.gallery_report.export.iso_100_or_lower', 'ISO 100 or lower');
    }
    if ($iso <= 400) {
        return t('admin.gallery_report.export.iso_101_400', 'ISO 101-400');
    }
    if ($iso <= 800) {
        return t('admin.gallery_report.export.iso_401_800', 'ISO 401-800');
    }
    if ($iso <= 1600) {
        return t('admin.gallery_report.export.iso_801_1600', 'ISO 801-1600');
    }
    if ($iso <= 3200) {
        return t('admin.gallery_report.export.iso_1601_3200', 'ISO 1601-3200');
    }
    return t('admin.gallery_report.export.iso_3201_plus', 'ISO 3201+');
}

/**
 * Render the complete standalone report HTML.
 *
 * @param array $report Report data.
 * @return string HTML document.
 */
function admin_gallery_report_render_html(array $report): string
{
    $site = is_array($report['site'] ?? null) ? $report['site'] : [];
    $runtime = is_array($report['runtime'] ?? null) ? $report['runtime'] : [];
    $storage = is_array($report['storage'] ?? null) ? $report['storage'] : [];
    $database = is_array($report['database'] ?? null) ? $report['database'] : [];
    $galleries = is_array($report['galleries'] ?? null) ? $report['galleries'] : [];
    $images = is_array($report['image_summary'] ?? null) ? $report['image_summary'] : [];
    $telemetry = is_array($report['telemetry'] ?? null) ? $report['telemetry'] : [];
    $logs = is_array($report['logs'] ?? null) ? $report['logs'] : [];
    $title = t('admin.gallery_report.export.title', 'PHP Gallery complete overview report');

    $html = '<!doctype html><html lang="' . admin_gallery_report_h((string) ($site['language'] ?? 'en')) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . admin_gallery_report_h($title) . '</title><style>' . admin_gallery_report_css() . '</style></head><body><main>';
    $html .= '<header><p class="eyebrow">' . admin_gallery_report_h(t('admin.gallery_report.export.kicker', 'PHP Gallery Admin Report')) . '</p><h1>' . admin_gallery_report_h($title) . '</h1><p class="muted">' . admin_gallery_report_h(t('admin.gallery_report.export.generated_note', 'Generated {time} UTC. This is a single self-contained HTML file. It was generated in browser-driven batches and was not saved as a report file on the server.', ['time' => (string) ($site['generated_at_utc'] ?? gmdate('c'))])) . '</p><div class="print-note">' . admin_gallery_report_h(t('admin.gallery_report.export.print_note', 'Use the browser print dialog to save this HTML as PDF when needed.')) . '</div></header>';

    $html .= '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.executive_overview', 'Executive overview')) . '</h2><div class="grid">'
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.version', 'Version'), (string) ($site['version'] ?? ''), (string) ($site['site_name'] ?? 'PHP Gallery'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.galleries', 'Galleries'), admin_gallery_report_n($galleries['total'] ?? 0), t('admin.gallery_report.export.root_nested', '{root} root, {nested} nested', ['root' => admin_gallery_report_n($galleries['root_count'] ?? 0), 'nested' => admin_gallery_report_n($galleries['nested_count'] ?? 0)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.images', 'Images'), admin_gallery_report_n($images['image_count'] ?? 0), t('admin.gallery_report.export.public_images', '{count} public', ['count' => admin_gallery_report_n($images['public_count'] ?? 0)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.source_photos', 'Source photos'), admin_gallery_report_bytes($storage['original_bytes'] ?? 0), t('admin.gallery_report.export.average_size', 'Average {size}', ['size' => admin_gallery_report_bytes($storage['average_original_bytes'] ?? 0)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.generated_media', 'Generated media'), admin_gallery_report_bytes($storage['generated_bytes'] ?? 0), t('admin.gallery_report.export.thumbnail_count', '{count} thumbnails', ['count' => admin_gallery_report_n($storage['generated_thumbnail_count'] ?? 0)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.database', 'Database'), admin_gallery_report_bytes((int) ($database['usage']['total_bytes'] ?? 0)), (string) ($database['database_name'] ?? ''))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.exif_date', 'EXIF date'), admin_gallery_report_percent($images['exif_date_count'] ?? 0, $images['image_count'] ?? 0), t('admin.gallery_report.export.image_count', '{count} images', ['count' => admin_gallery_report_n($images['exif_date_count'] ?? 0)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.gps', 'GPS'), admin_gallery_report_percent($images['gps_count'] ?? 0, $images['image_count'] ?? 0), t('admin.gallery_report.export.image_count', '{count} images', ['count' => admin_gallery_report_n($images['gps_count'] ?? 0)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.telemetry_window', 'Telemetry window'), t('admin.gallery_report.export.day_count', '{count} days', ['count' => admin_gallery_report_n($telemetry['days'] ?? 0)]), !empty($telemetry['available']) ? t('admin.gallery_report.export.available', 'available') : t('admin.gallery_report.export.not_available', 'not available'))
        . '</div></section>';

    $html .= '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.generation_runtime', 'Generation and runtime')) . '</h2><div class="split"><div>'
        . admin_gallery_report_definition_list([
            t('admin.gallery_report.export.report_duration', 'Report duration') => admin_gallery_report_duration((int) ($report['duration_seconds'] ?? 0)),
            t('admin.gallery_report.export.images_processed', 'Images processed') => admin_gallery_report_n($report['processed'] ?? 0) . ' / ' . admin_gallery_report_n($report['total'] ?? 0),
            'PHP' => (string) ($runtime['php_version'] ?? ''),
            'SAPI' => (string) ($runtime['php_sapi'] ?? ''),
            t('admin.gallery_report.export.server_software', 'Server software') => (string) ($runtime['server_software'] ?? ''),
            'OS' => (string) ($runtime['os'] ?? ''),
            'MySQL/MariaDB' => (string) ($runtime['mysql_version'] ?? ''),
            t('admin.gallery_report.export.memory_limit', 'Memory limit') => (string) ($runtime['memory_limit'] ?? ''),
            t('admin.gallery_report.export.peak_memory_used', 'Peak memory used') => admin_gallery_report_bytes($runtime['peak_memory_usage_bytes'] ?? 0),
            t('admin.gallery_report.export.server_ram', 'Server RAM') => admin_gallery_report_server_memory_label(is_array($runtime['server_memory'] ?? null) ? $runtime['server_memory'] : []),
            t('admin.gallery_report.export.load_average', 'Load average') => admin_gallery_report_load_average_label(is_array($runtime['load_average'] ?? null) ? $runtime['load_average'] : []),
            'GD' => admin_gallery_report_gd_label(is_array($runtime['gd_info'] ?? null) ? $runtime['gd_info'] : []),
            'Imagick' => (string) ($runtime['imagick_version'] ?? ''),
            t('admin.gallery_report.export.max_execution_time', 'Max execution time') => (string) ($runtime['max_execution_time'] ?? '') . ' s',
            t('admin.gallery_report.export.upload_max_filesize', 'Upload max filesize') => (string) ($runtime['upload_max_filesize'] ?? ''),
            t('admin.gallery_report.export.post_max_size', 'Post max size') => (string) ($runtime['post_max_size'] ?? ''),
            t('admin.gallery_report.export.timezone', 'Timezone') => (string) ($runtime['timezone'] ?? ''),
        ]) . '</div><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.php_extensions', 'PHP extensions')) . '</h3>' . admin_gallery_report_table($runtime['extensions'] ?? [], [
            ['key' => 'extension', 'label' => t('admin.gallery_report.export.extension', 'Extension')],
            ['key' => 'loaded', 'label' => t('admin.gallery_report.export.loaded', 'Loaded')],
        ]) . '</div></div></section>';

    $html .= '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.storage_details', 'Storage details')) . '</h2><div class="grid">'
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.original_sources', 'Original sources'), admin_gallery_report_bytes($storage['original_bytes'] ?? 0), t('admin.gallery_report.export.known_sizes', '{count} known sizes', ['count' => admin_gallery_report_n($storage['known_source_size_count'] ?? 0)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.thumbnails', 'Thumbnails'), admin_gallery_report_bytes($storage['generated_thumbnail_bytes'] ?? 0), t('admin.gallery_report.export.file_count', '{count} files', ['count' => admin_gallery_report_n($storage['generated_thumbnail_count'] ?? 0)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.display_masters', 'Display masters'), admin_gallery_report_bytes($storage['display_master_bytes'] ?? 0), t('admin.gallery_report.export.file_count', '{count} files', ['count' => admin_gallery_report_n($storage['display_master_count'] ?? 0)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.total_picture_storage', 'Total picture storage'), admin_gallery_report_bytes($storage['total_picture_bytes'] ?? 0), t('admin.gallery_report.export.generated_original_ratio', 'Generated/original {percent} %', ['percent' => admin_gallery_report_n($storage['generated_to_original_percent'] ?? 0, 1)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.largest_source', 'Largest source'), admin_gallery_report_bytes($storage['largest_original_bytes'] ?? 0), (string) ($storage['largest_original_name'] ?? ''))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.scan_errors', 'Scan errors'), admin_gallery_report_n($storage['thumbnail_scan_errors'] ?? 0), !empty($storage['thumbnail_metadata_used']) ? t('admin.gallery_report.export.thumbnail_metadata_used', 'thumbnail metadata used') : t('admin.gallery_report.export.filesystem_checks_used', 'filesystem checks used'))
        . '</div><div class="split"><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.source_types', 'Source types')) . '</h3>' . admin_gallery_report_bar_table($storage['type_rows'] ?? [], 'count', 'bytes') . '</div><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.generated_media_types', 'Generated media types')) . '</h3>' . admin_gallery_report_bar_table($storage['generated_type_rows'] ?? [], 'count', 'bytes') . '</div></div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.configured_data_paths', 'Configured data paths')) . '</h3>' . admin_gallery_report_table($report['data_paths']['paths'] ?? [], [
            ['key' => 'label', 'label' => t('admin.gallery_report.export.path', 'Path')],
            ['key' => 'path', 'label' => t('admin.gallery_report.export.filesystem_value', 'Filesystem value')],
            ['key' => 'exists', 'label' => t('admin.gallery_report.export.exists', 'Exists')],
            ['key' => 'readable', 'label' => t('admin.gallery_report.export.readable', 'Readable')],
            ['key' => 'writable', 'label' => t('admin.gallery_report.export.writable', 'Writable')],
            ['key' => 'free_bytes', 'label' => t('admin.gallery_report.export.free', 'Free'), 'format' => fn($v): string => admin_gallery_report_bytes($v)],
            ['key' => 'total_bytes', 'label' => t('admin.gallery_report.export.total', 'Total'), 'format' => fn($v): string => admin_gallery_report_bytes($v)],
        ]) . '</section>';

    $html .= '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.database_overview', 'Database overview')) . '</h2><div class="grid">'
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.total_db_size', 'Total DB size'), admin_gallery_report_bytes($database['usage']['total_bytes'] ?? 0), t('admin.gallery_report.export.data_indexes', 'data + indexes'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.gallery_db_size', 'Gallery DB size'), admin_gallery_report_bytes($database['usage']['gallery_bytes'] ?? 0), t('admin.gallery_report.export.content_related_tables', 'content-related tables'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.operational_db_size', 'Operational DB size'), admin_gallery_report_bytes($database['usage']['operational_bytes'] ?? 0), t('admin.gallery_report.export.operational_tables_hint', 'logs, telemetry, settings, users'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.rows_exact', 'Rows exact'), !empty($database['usage']['table_rows_exact_available']) ? admin_gallery_report_n($database['usage']['table_rows_exact'] ?? 0) : admin_gallery_report_n($database['usage']['table_rows_estimate'] ?? 0), !empty($database['usage']['table_rows_exact_available']) ? t('admin.gallery_report.export.count_across_tables', 'COUNT(*) across DB tables') : t('admin.gallery_report.export.engine_estimate', 'engine estimate'))
        . '</div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.database_tables', 'Database tables')) . '</h3>' . admin_gallery_report_table($database['usage']['table_rows'] ?? [], [
            ['key' => 'table_name', 'label' => t('admin.gallery_report.export.table', 'Table')],
            ['key' => 'rows_exact', 'label' => t('admin.gallery_report.export.rows_exact', 'Rows exact'), 'format' => fn($v): string => $v === null ? '' : admin_gallery_report_n($v)],
            ['key' => 'table_rows_estimate', 'label' => t('admin.gallery_report.export.rows_estimate', 'Rows estimate'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'data_bytes', 'label' => t('admin.gallery_report.export.data', 'Data'), 'format' => fn($v): string => admin_gallery_report_bytes($v)],
            ['key' => 'index_bytes', 'label' => t('admin.gallery_report.export.index', 'Index'), 'format' => fn($v): string => admin_gallery_report_bytes($v)],
            ['key' => 'total_bytes', 'label' => t('admin.gallery_report.export.total', 'Total'), 'format' => fn($v): string => admin_gallery_report_bytes($v)],
            ['key' => 'engine', 'label' => t('admin.gallery_report.export.engine', 'Engine')],
            ['key' => 'row_count_source', 'label' => t('admin.gallery_report.export.row_source', 'Row source')],
        ]) . '<h3>' . admin_gallery_report_h(t('admin.gallery_report.export.application_table_counts', 'Application table counts')) . '</h3>' . admin_gallery_report_table($report['tables'] ?? [], [
            ['key' => 'table_name', 'label' => t('admin.gallery_report.export.table', 'Table')],
            ['key' => 'exists', 'label' => t('admin.gallery_report.export.exists', 'Exists')],
            ['key' => 'rows', 'label' => t('admin.gallery_report.export.rows', 'Rows'), 'format' => fn($v): string => $v === null ? '' : admin_gallery_report_n($v)],
            ['key' => 'error', 'label' => t('admin.gallery_report.export.error', 'Error')],
        ]) . '</section>';

    $html .= '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.gallery_structure', 'Gallery structure')) . '</h2><div class="split"><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.visibility', 'Visibility')) . '</h3>' . admin_gallery_report_bar_table($galleries['visibility_rows'] ?? [], 'count') . '</div><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.access_mode', 'Access mode')) . '</h3>' . admin_gallery_report_bar_table($galleries['access_rows'] ?? [], 'count') . '</div></div><div class="grid">'
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.empty_galleries', 'Empty galleries'), admin_gallery_report_n($galleries['empty_count'] ?? 0), t('admin.gallery_report.export.empty_galleries_hint', 'direct image count is zero'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.deepest_nesting', 'Deepest nesting'), admin_gallery_report_n($galleries['deepest_depth'] ?? 0), t('admin.gallery_report.export.parent_chain_depth', 'parent chain depth'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.dated_galleries', 'Dated galleries'), admin_gallery_report_n($galleries['dated_gallery_count'] ?? 0), t('admin.gallery_report.export.manual_date_range_present', 'manual date range present'))
        . '</div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.largest_galleries', 'Largest galleries')) . '</h3>' . admin_gallery_report_table($galleries['largest_rows'] ?? [], [
            ['key' => 'id', 'label' => 'ID'],
            ['key' => 'title', 'label' => t('admin.gallery_report.export.title_column', 'Title')],
            ['key' => 'folder_path', 'label' => t('admin.gallery_report.export.folder', 'Folder')],
            ['key' => 'visibility', 'label' => t('admin.gallery_report.export.visibility', 'Visibility')],
            ['key' => 'image_count', 'label' => t('admin.gallery_report.export.images', 'Images'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'source_bytes', 'label' => t('admin.gallery_report.export.source_bytes', 'Source bytes'), 'format' => fn($v): string => admin_gallery_report_bytes($v)],
        ]) . '<h3>' . admin_gallery_report_h(t('admin.gallery_report.export.complete_gallery_list', 'Complete gallery list')) . '</h3>' . admin_gallery_report_table($report['gallery_rows'] ?? [], [
            ['key' => 'id', 'label' => 'ID'],
            ['key' => 'parent_id', 'label' => t('admin.gallery_report.export.parent', 'Parent')],
            ['key' => 'title', 'label' => t('admin.gallery_report.export.title_column', 'Title')],
            ['key' => 'folder_path', 'label' => t('admin.gallery_report.export.folder', 'Folder')],
            ['key' => 'visibility', 'label' => t('admin.gallery_report.export.visibility', 'Visibility')],
            ['key' => 'access_mode', 'label' => t('admin.gallery_report.export.access', 'Access')],
            ['key' => 'access_listing', 'label' => t('admin.gallery_report.export.listing', 'Listing')],
            ['key' => 'image_count', 'label' => t('admin.gallery_report.export.images', 'Images'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'source_bytes', 'label' => t('admin.gallery_report.export.source', 'Source'), 'format' => fn($v): string => admin_gallery_report_bytes($v)],
            ['key' => 'gps_images', 'label' => t('admin.gallery_report.export.gps_images', 'GPS images'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'date_start', 'label' => t('admin.gallery_report.export.date_from', 'Date from')],
            ['key' => 'date_end', 'label' => t('admin.gallery_report.export.date_to', 'Date to')],
            ['key' => 'gps_map_enabled', 'label' => t('admin.gallery_report.export.gps_map_override', 'GPS map override')],
            ['key' => 'picture_game_enabled', 'label' => t('admin.gallery_report.export.game', 'Game')],
            ['key' => 'updated_at', 'label' => t('admin.gallery_report.export.updated', 'Updated')],
        ]) . '</section>';

    $html .= '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.images_exif_statistics', 'Images and EXIF statistics')) . '</h2><div class="grid">'
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.images_with_exif_date', 'Images with EXIF date'), admin_gallery_report_n($images['exif_date_count'] ?? 0), admin_gallery_report_percent($images['exif_date_count'] ?? 0, $images['image_count'] ?? 0))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.images_with_gps', 'Images with GPS'), admin_gallery_report_n($images['gps_count'] ?? 0), admin_gallery_report_percent($images['gps_count'] ?? 0, $images['image_count'] ?? 0))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.both_date_gps', 'Both date and GPS'), admin_gallery_report_n($images['both_exif_date_and_gps_count'] ?? 0), admin_gallery_report_percent($images['both_exif_date_and_gps_count'] ?? 0, $images['image_count'] ?? 0))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.neither_date_gps', 'Neither date nor GPS'), admin_gallery_report_n($images['neither_exif_date_nor_gps_count'] ?? 0), admin_gallery_report_percent($images['neither_exif_date_nor_gps_count'] ?? 0, $images['image_count'] ?? 0))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.known_camera', 'Known camera'), admin_gallery_report_n($images['camera_known_count'] ?? 0), admin_gallery_report_percent($images['camera_known_count'] ?? 0, $images['image_count'] ?? 0))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.known_lens', 'Known lens'), admin_gallery_report_n($images['lens_known_count'] ?? 0), admin_gallery_report_percent($images['lens_known_count'] ?? 0, $images['image_count'] ?? 0))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.average_megapixels', 'Average megapixels'), admin_gallery_report_n($images['average_megapixels'] ?? 0, 2) . ' MP', t('admin.gallery_report.export.images_with_dimensions', '{count} images with dimensions', ['count' => admin_gallery_report_n($images['known_dimensions_count'] ?? 0)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.exif_date_range', 'EXIF date range'), admin_gallery_report_short_date((string) ($images['exif_date_min'] ?? '')) . ' ' . t('admin.gallery_report.export.to', 'to') . ' ' . admin_gallery_report_short_date((string) ($images['exif_date_max'] ?? '')), t('admin.gallery_report.export.from_imported_metadata', 'from imported metadata'))
        . '</div><div class="split"><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.image_types', 'Image types')) . '</h3>' . admin_gallery_report_bar_table($images['type_rows'] ?? [], 'count', 'bytes') . '</div><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.orientation', 'Orientation')) . '</h3>' . admin_gallery_report_bar_table($images['dimension_rows'] ?? [], 'count') . '</div></div><div class="split"><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.top_cameras', 'Top cameras')) . '</h3>' . admin_gallery_report_bar_table($images['camera_rows'] ?? [], 'count') . '</div><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.top_lenses', 'Top lenses')) . '</h3>' . admin_gallery_report_bar_table($images['lens_rows'] ?? [], 'count') . '</div></div><div class="split"><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.iso_buckets', 'ISO buckets')) . '</h3>' . admin_gallery_report_bar_table($images['iso_rows'] ?? [], 'count') . '</div><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.top_focal_lengths', 'Top focal lengths')) . '</h3>' . admin_gallery_report_bar_table($images['focal_rows'] ?? [], 'count') . '</div></div></section>';

    $html .= '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.gps_place_overview', 'GPS place overview')) . '</h2><p class="muted">' . admin_gallery_report_h(t('admin.gallery_report.export.gps_clustering_note', 'GPS points are clustered into approximate {km} km areas. Probable simulator or game captures are excluded from place clustering using filename, file type, and missing camera EXIF heuristics.', ['km' => admin_gallery_report_n(ADMIN_GALLERY_REPORT_GPS_AREA_KM, 0)])) . '</p><div class="grid">'
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.gps_points_found', 'GPS points found'), admin_gallery_report_n($images['gps_count'] ?? 0), t('admin.gallery_report.export.all_images_coordinates', 'all images with coordinates'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.clustered_real_places', 'Clustered as real places'), admin_gallery_report_n($images['clustered_gps_count'] ?? 0), t('admin.gallery_report.export.used_place_overview', 'used in place overview'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.excluded_probable_game_gps', 'Excluded probable game GPS'), admin_gallery_report_n($images['probable_game_gps_count'] ?? 0), t('admin.gallery_report.export.not_used_real_world_frequency', 'not used for real-world place frequency'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.latitude_range', 'Latitude range'), admin_gallery_report_n($images['gps_lat_min'] ?? 0, 4) . ' ' . t('admin.gallery_report.export.to', 'to') . ' ' . admin_gallery_report_n($images['gps_lat_max'] ?? 0, 4), t('admin.gallery_report.export.coordinate_bounds', 'coordinate bounds'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.longitude_range', 'Longitude range'), admin_gallery_report_n($images['gps_lng_min'] ?? 0, 4) . ' ' . t('admin.gallery_report.export.to', 'to') . ' ' . admin_gallery_report_n($images['gps_lng_max'] ?? 0, 4), t('admin.gallery_report.export.coordinate_bounds', 'coordinate bounds'))
        . '</div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.frequent_gps_areas', 'Frequent GPS areas')) . '</h3>' . admin_gallery_report_table($images['gps_cluster_rows'] ?? [], [
            ['key' => 'label', 'label' => t('admin.gallery_report.export.area', 'Area')],
            ['key' => 'nearest_reference', 'label' => t('admin.gallery_report.export.reference', 'Reference')],
            ['key' => 'place_match', 'label' => t('admin.gallery_report.export.match', 'Match')],
            ['key' => 'place_distance_km', 'label' => t('admin.gallery_report.export.distance', 'Distance'), 'format' => fn($v): string => $v === null || $v === '' ? '' : admin_gallery_report_n($v, 1) . ' km'],
            ['key' => 'count', 'label' => t('admin.gallery_report.export.images', 'Images'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'lat', 'label' => t('admin.gallery_report.export.center_lat', 'Center lat')],
            ['key' => 'lng', 'label' => t('admin.gallery_report.export.center_lng', 'Center lng')],
            ['key' => 'gallery_count', 'label' => t('admin.gallery_report.export.galleries', 'Galleries'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'gallery_labels', 'label' => t('admin.gallery_report.export.example_galleries', 'Example galleries'), 'format' => fn($v): string => is_array($v) ? implode(', ', array_slice($v, 0, 6)) : (string) $v],
            ['key' => 'first_date', 'label' => t('admin.gallery_report.export.first_exif_date', 'First EXIF date'), 'format' => fn($v): string => admin_gallery_report_short_date((string) $v)],
            ['key' => 'last_date', 'label' => t('admin.gallery_report.export.last_exif_date', 'Last EXIF date'), 'format' => fn($v): string => admin_gallery_report_short_date((string) $v)],
            ['key' => 'sample_images', 'label' => t('admin.gallery_report.export.sample_images', 'Sample images'), 'format' => fn($v): string => is_array($v) ? implode(', ', array_slice($v, 0, 4)) : (string) $v],
        ]) . '</section>';

    $html .= admin_gallery_report_render_telemetry_section($telemetry);
    $html .= admin_gallery_report_render_logs_section($logs);

    $html .= '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.tags_votes_largest', 'Tags, votes, and largest images')) . '</h2><div class="split"><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.gallery_tags', 'Gallery tags')) . '</h3>' . admin_gallery_report_bar_table($report['tags']['gallery_tag_rows'] ?? [], 'count') . '</div><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.image_tags', 'Image tags')) . '</h3>' . admin_gallery_report_bar_table($report['tags']['image_tag_rows'] ?? [], 'count') . '</div></div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.largest_source_images', 'Largest source images')) . '</h3>' . admin_gallery_report_table($report['top_images'] ?? [], [
            ['key' => 'id', 'label' => 'ID'],
            ['key' => 'filename', 'label' => t('admin.gallery_report.export.filename', 'Filename')],
            ['key' => 'gallery_title', 'label' => t('admin.gallery_report.export.gallery', 'Gallery')],
            ['key' => 'relative_path', 'label' => t('admin.gallery_report.export.relative_path', 'Relative path')],
            ['key' => 'mime_type', 'label' => 'MIME'],
            ['key' => 'file_size', 'label' => t('admin.gallery_report.export.size', 'Size'), 'format' => fn($v): string => admin_gallery_report_bytes($v)],
            ['key' => 'width', 'label' => t('admin.gallery_report.export.width', 'Width')],
            ['key' => 'height', 'label' => t('admin.gallery_report.export.height', 'Height')],
            ['key' => 'visibility', 'label' => t('admin.gallery_report.export.visibility', 'Visibility')],
        ]) . '</section>';

    $html .= '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.feature_settings_overview', 'Feature and settings overview')) . '</h2><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.selected_application_settings', 'Selected application settings')) . '</h3>' . admin_gallery_report_table($report['features']['settings_rows'] ?? [], [
            ['key' => 'setting_key', 'label' => t('admin.gallery_report.export.key', 'Key')],
            ['key' => 'setting_value', 'label' => t('admin.gallery_report.export.value', 'Value')],
            ['key' => 'updated_at', 'label' => t('admin.gallery_report.export.updated', 'Updated')],
        ]) . '</section>';

    $html .= '<footer class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.report_notes', 'Report notes')) . '</h2><ul><li>' . admin_gallery_report_h(t('admin.gallery_report.export.note_gps_labels', 'GPS area labels use an expanded offline place list with city, town, and regional references. If a cluster is outside every configured place radius, the report still shows the nearest known reference and marks it as a nearest fallback.')) . '</li><li>' . admin_gallery_report_h(t('admin.gallery_report.export.note_game_gps', 'Simulator and game GPS exclusion is heuristic. It avoids obvious PNG/WebP captures without camera EXIF and names containing simulator or screenshot markers.')) . '</li><li>' . admin_gallery_report_h(t('admin.gallery_report.export.note_database_sizes', 'Database sizes come from MySQL/MariaDB table metadata. Row counts use exact COUNT(*) values when the host allows them; engine estimates are shown separately.')) . '</li><li>' . admin_gallery_report_h(t('admin.gallery_report.export.note_no_server_file', 'No report file was written to the server. The browser received this HTML and can save it locally.')) . '</li></ul></footer>';
    $html .= '</main></body></html>';
    return $html;
}

/**
 * Render telemetry section.
 *
 * @param array $telemetry Telemetry data.
 * @return string HTML fragment.
 */
function admin_gallery_report_render_telemetry_section(array $telemetry): string
{
    if (empty($telemetry['available'])) {
        return '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.telemetry', 'Telemetry')) . '</h2><p class="muted">' . admin_gallery_report_h(t('admin.gallery_report.export.telemetry_unavailable', 'Telemetry is not available on this installation or the schema is not migrated yet.')) . '</p></section>';
    }
    $session = is_array($telemetry['session_summary'] ?? null) ? $telemetry['session_summary'] : [];
    $html = '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.telemetry_usage', 'Telemetry and usage')) . '</h2><p class="muted">' . admin_gallery_report_h(t('admin.gallery_report.export.telemetry_window_note', 'Telemetry window: last {days} days. Public telemetry is {state}.', ['days' => (string) ($telemetry['days'] ?? 0), 'state' => !empty($telemetry['public_enabled']) ? t('admin.gallery_report.export.enabled', 'enabled') : t('admin.gallery_report.export.disabled', 'disabled')])) . '</p><div class="grid">'
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.sessions', 'Sessions'), admin_gallery_report_n($session['sessions'] ?? 0), t('admin.gallery_report.export.anonymous_session_hashes', 'anonymous session hashes'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.page_views', 'Page views'), admin_gallery_report_n($session['page_views'] ?? 0), t('admin.gallery_report.export.per_session', '{count} per session', ['count' => admin_gallery_report_n($session['avg_pages_per_session'] ?? 0, 2)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.photo_views', 'Photo views'), admin_gallery_report_n($session['photo_views'] ?? 0), t('admin.gallery_report.export.per_session', '{count} per session', ['count' => admin_gallery_report_n($session['avg_photos_per_session'] ?? 0, 2)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.duration', 'Duration'), admin_gallery_report_duration((int) ($session['duration_seconds'] ?? 0)), t('admin.gallery_report.export.capped_total', 'capped total'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.bounced_sessions', 'Bounced sessions'), admin_gallery_report_n($session['bounced_sessions'] ?? 0), t('admin.gallery_report.export.single_page_sessions', 'single-page sessions'))
        . '</div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.telemetry_windows', 'Telemetry windows')) . '</h3>' . admin_gallery_report_table($telemetry['windows'] ?? [], [
            ['key' => 'days', 'label' => t('admin.gallery_report.export.days', 'Days')],
            ['key' => 'sessions', 'label' => t('admin.gallery_report.export.sessions', 'Sessions'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'page_views', 'label' => t('admin.gallery_report.export.page_views', 'Page views'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'photo_views', 'label' => t('admin.gallery_report.export.photo_views', 'Photo views'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'duration_seconds', 'label' => t('admin.gallery_report.export.duration', 'Duration'), 'format' => fn($v): string => admin_gallery_report_duration((int) $v)],
            ['key' => 'db_queries', 'label' => t('admin.gallery_report.export.db_queries', 'DB queries'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'db_slow', 'label' => t('admin.gallery_report.export.slow_db', 'Slow DB'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'db_failed', 'label' => t('admin.gallery_report.export.failed_db', 'Failed DB'), 'format' => fn($v): string => admin_gallery_report_n($v)],
        ]) . '<div class="split"><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.top_galleries', 'Top galleries')) . '</h3>' . admin_gallery_report_table($telemetry['top_galleries'] ?? [], [
            ['key' => 'id', 'label' => 'ID'],
            ['key' => 'title', 'label' => t('admin.gallery_report.export.title_column', 'Title')],
            ['key' => 'page_views', 'label' => t('admin.gallery_report.export.page_views', 'Page views'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'photo_views', 'label' => t('admin.gallery_report.export.photo_views', 'Photo views'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'photo_seconds', 'label' => t('admin.gallery_report.export.photo_time', 'Photo time'), 'format' => fn($v): string => admin_gallery_report_duration((int) $v)],
            ['key' => 'media_bytes', 'label' => t('admin.gallery_report.export.media_bytes', 'Media bytes'), 'format' => fn($v): string => admin_gallery_report_bytes($v)],
        ]) . '</div><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.top_routes', 'Top routes')) . '</h3>' . admin_gallery_report_table($telemetry['top_routes'] ?? [], [
            ['key' => 'route_name', 'label' => t('admin.gallery_report.export.route', 'Route')],
            ['key' => 'page_views', 'label' => t('admin.gallery_report.export.page_views', 'Page views'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'photo_views', 'label' => t('admin.gallery_report.export.photo_views', 'Photo views'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'client_errors', 'label' => t('admin.gallery_report.export.errors', 'Errors'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'media_bytes', 'label' => t('admin.gallery_report.export.media_bytes', 'Media bytes'), 'format' => fn($v): string => admin_gallery_report_bytes($v)],
        ]) . '</div></div><div class="split"><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.browsers', 'Browsers')) . '</h3>' . admin_gallery_report_bar_table($telemetry['browsers'] ?? [], 'sessions') . '</div><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.devices', 'Devices')) . '</h3>' . admin_gallery_report_bar_table($telemetry['devices'] ?? [], 'sessions') . '</div></div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.performance', 'Performance')) . '</h3>' . admin_gallery_report_table($telemetry['performance'] ?? [], [
            ['key' => 'metric_name', 'label' => t('admin.gallery_report.export.metric', 'Metric')],
            ['key' => 'samples', 'label' => t('admin.gallery_report.export.samples', 'Samples'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'avg_value', 'label' => t('admin.gallery_report.export.average', 'Average'), 'format' => fn($v): string => admin_gallery_report_n($v, 2)],
            ['key' => 'min_value', 'label' => t('admin.gallery_report.export.min', 'Min'), 'format' => fn($v): string => admin_gallery_report_n($v, 2)],
            ['key' => 'max_value', 'label' => t('admin.gallery_report.export.max', 'Max'), 'format' => fn($v): string => admin_gallery_report_n($v, 2)],
        ]) . '<h3>' . admin_gallery_report_h(t('admin.gallery_report.export.client_errors', 'Client errors')) . '</h3>' . admin_gallery_report_table($telemetry['client_errors'] ?? [], [
            ['key' => 'error_kind', 'label' => t('admin.gallery_report.export.error_kind', 'Error kind')],
            ['key' => 'route_name', 'label' => t('admin.gallery_report.export.route', 'Route')],
            ['key' => 'events', 'label' => t('admin.gallery_report.export.events', 'Events'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'last_seen', 'label' => t('admin.gallery_report.export.last_seen', 'Last seen')],
        ]) . '<h3>' . admin_gallery_report_h(t('admin.gallery_report.export.database_hot_spots', 'Database telemetry hot spots')) . '</h3>' . admin_gallery_report_table($telemetry['database_summary'] ?? [], [
            ['key' => 'route_name', 'label' => t('admin.gallery_report.export.route', 'Route')],
            ['key' => 'operation', 'label' => t('admin.gallery_report.export.operation', 'Operation')],
            ['key' => 'table_name', 'label' => t('admin.gallery_report.export.table', 'Table')],
            ['key' => 'query_count', 'label' => t('admin.gallery_report.export.queries', 'Queries'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'failed_count', 'label' => t('admin.gallery_report.export.failed', 'Failed'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'slow_count', 'label' => t('admin.gallery_report.export.slow', 'Slow'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'latency_ms_sum', 'label' => t('admin.gallery_report.export.latency_sum', 'Latency sum'), 'format' => fn($v): string => admin_gallery_report_n($v) . ' ms'],
            ['key' => 'latency_ms_max', 'label' => t('admin.gallery_report.export.max_latency', 'Max latency'), 'format' => fn($v): string => admin_gallery_report_n($v) . ' ms'],
        ]) . '<h3>' . admin_gallery_report_h(t('admin.gallery_report.export.recent_telemetry_events', 'Recent telemetry events')) . '</h3>' . admin_gallery_report_table($telemetry['recent_events'] ?? [], [
            ['key' => 'occurred_at', 'label' => t('admin.gallery_report.export.time', 'Time')],
            ['key' => 'event_name', 'label' => t('admin.gallery_report.export.event', 'Event')],
            ['key' => 'route_name', 'label' => t('admin.gallery_report.export.route', 'Route')],
            ['key' => 'page_kind', 'label' => t('admin.gallery_report.export.kind', 'Kind')],
            ['key' => 'gallery_id', 'label' => t('admin.gallery_report.export.gallery', 'Gallery')],
            ['key' => 'image_id', 'label' => t('admin.gallery_report.export.image', 'Image')],
            ['key' => 'referrer_category', 'label' => t('admin.gallery_report.export.referrer', 'Referrer')],
            ['key' => 'browser_family', 'label' => t('admin.gallery_report.export.browser', 'Browser')],
            ['key' => 'device_type', 'label' => t('admin.gallery_report.export.device', 'Device')],
            ['key' => 'http_status', 'label' => 'HTTP'],
            ['key' => 'error_kind', 'label' => t('admin.gallery_report.export.error', 'Error')],
        ]) . '</section>';
    return $html;
}

/**
 * Render operational log section.
 *
 * @param array $logs Log data.
 * @return string HTML fragment.
 */
function admin_gallery_report_render_logs_section(array $logs): string
{
    if (empty($logs['available'])) {
        return '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.operational_logs', 'Operational logs')) . '</h2><p class="muted">' . admin_gallery_report_h(t('admin.gallery_report.export.admin_log_unavailable', 'Admin log table is not available.')) . '</p></section>';
    }
    return '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.operational_logs', 'Operational logs')) . '</h2><div class="grid">'
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.log_records', 'Log records'), admin_gallery_report_n($logs['total'] ?? 0), t('admin.gallery_report.export.all_time', 'all time'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.last_7_days', 'Last 7 days'), admin_gallery_report_n($logs['last_7_days'] ?? 0), t('admin.gallery_report.export.recent_operational_events', 'recent operational events'))
        . '</div><div class="split"><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.levels', 'Levels')) . '</h3>' . admin_gallery_report_bar_table($logs['level_rows'] ?? [], 'count') . '</div><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.categories', 'Categories')) . '</h3>' . admin_gallery_report_bar_table($logs['category_rows'] ?? [], 'count') . '</div></div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.top_events', 'Top events')) . '</h3>' . admin_gallery_report_bar_table($logs['top_events'] ?? [], 'count') . '<h3>' . admin_gallery_report_h(t('admin.gallery_report.export.recent_errors_critical', 'Recent errors and critical events')) . '</h3>' . admin_gallery_report_table($logs['recent_errors'] ?? [], [
            ['key' => 'created_at', 'label' => t('admin.gallery_report.export.created', 'Created')],
            ['key' => 'level', 'label' => t('admin.gallery_report.export.level', 'Level')],
            ['key' => 'severity', 'label' => t('admin.gallery_report.export.severity', 'Severity')],
            ['key' => 'event_key', 'label' => t('admin.gallery_report.export.event', 'Event')],
            ['key' => 'route_name', 'label' => t('admin.gallery_report.export.route', 'Route')],
            ['key' => 'message', 'label' => t('admin.gallery_report.export.message', 'Message')],
        ]) . '</section>';
}

/**
 * Return a metric card HTML fragment.
 *
 * @param string $label Metric label.
 * @param string $value Metric value.
 * @param string $hint Optional hint.
 * @return string HTML fragment.
 */
function admin_gallery_report_metric_card(string $label, string $value, string $hint = ''): string
{
    return '<article class="metric"><span>' . admin_gallery_report_h($label) . '</span><strong>' . admin_gallery_report_h($value) . '</strong>' . ($hint !== '' ? '<small>' . admin_gallery_report_h($hint) . '</small>' : '') . '</article>';
}

/**
 * Render a key-value list.
 *
 * @param array<string, string> $items Items to render.
 * @return string HTML fragment.
 */
function admin_gallery_report_definition_list(array $items): string
{
    $html = '<dl class="facts">';
    foreach ($items as $key => $value) {
        $html .= '<dt>' . admin_gallery_report_h((string) $key) . '</dt><dd>' . admin_gallery_report_h((string) $value) . '</dd>';
    }
    return $html . '</dl>';
}

/**
 * Render a data table.
 *
 * @param array $rows Data rows.
 * @param array $columns Column definitions.
 * @return string HTML fragment.
 */
function admin_gallery_report_table(array $rows, array $columns): string
{
    if ($rows === []) {
        return '<p class="muted">' . admin_gallery_report_h(t('admin.gallery_report.export.no_rows', 'No rows.')) . '</p>';
    }
    $html = '<div class="table-scroll"><table><thead><tr>';
    foreach ($columns as $column) {
        $html .= '<th>' . admin_gallery_report_h((string) ($column['label'] ?? $column['key'] ?? '')) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $html .= '<tr>';
        foreach ($columns as $column) {
            $key = (string) ($column['key'] ?? '');
            $value = $row[$key] ?? '';
            if (isset($column['format']) && is_callable($column['format'])) {
                $value = $column['format']($value, $row);
            } elseif (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }
            $html .= '<td>' . admin_gallery_report_h((string) $value) . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div>';
}

/**
 * Render a compact bar table.
 *
 * @param array $rows Data rows.
 * @param string $valueKey Numeric value key.
 * @param string $bytesKey Optional byte key.
 * @return string HTML fragment.
 */
function admin_gallery_report_bar_table(array $rows, string $valueKey = 'count', string $bytesKey = ''): string
{
    if ($rows === []) {
        return '<p class="muted">' . admin_gallery_report_h(t('admin.gallery_report.export.no_rows', 'No rows.')) . '</p>';
    }
    $max = 0.0;
    foreach ($rows as $row) {
        if (is_array($row)) {
            $max = max($max, (float) ($row[$valueKey] ?? 0));
        }
    }
    $html = '<div class="bars">';
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $value = (float) ($row[$valueKey] ?? 0);
        $percent = $max > 0 ? ($value / $max) * 100 : 0;
        $detail = admin_gallery_report_n($value, is_float($row[$valueKey] ?? null) ? 2 : 0);
        if ($bytesKey !== '' && isset($row[$bytesKey])) {
            $detail .= ' / ' . admin_gallery_report_bytes($row[$bytesKey]);
        }
        $html .= '<div class="bar-row"><div class="bar-label">' . admin_gallery_report_h((string) ($row['label'] ?? $row['key'] ?? 'unknown')) . '</div><div class="bar-track"><span style="width:' . admin_gallery_report_h(number_format($percent, 1, '.', '')) . '%"></span></div><div class="bar-value">' . admin_gallery_report_h($detail) . '</div></div>';
    }
    return $html . '</div>';
}

/**
 * Escape HTML text.
 *
 * @param mixed $value Raw value.
 * @return string Escaped text.
 */
function admin_gallery_report_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Format a number.
 *
 * @param mixed $value Numeric value.
 * @param int $precision Decimal precision.
 * @return string Formatted number.
 */
function admin_gallery_report_n(mixed $value, int $precision = 0): string
{
    return number_format((float) $value, $precision, '.', ' ');
}

/**
 * Format bytes.
 *
 * @param mixed $bytes Byte value.
 * @return string Human-readable bytes.
 */
function admin_gallery_report_bytes(mixed $bytes): string
{
    $value = max(0.0, (float) $bytes);
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    $index = 0;
    while ($value >= 1024.0 && $index < count($units) - 1) {
        $value /= 1024.0;
        $index++;
    }
    return number_format($value, $index === 0 ? 0 : 1, '.', ' ') . ' ' . $units[$index];
}

/**
 * Format physical server memory information.
 *
 * @param array $memory Memory summary.
 * @return string Human-readable memory status.
 */
function admin_gallery_report_server_memory_label(array $memory): string
{
    if (empty($memory['available'])) {
        return 'not exposed by host';
    }
    $parts = ['total ' . admin_gallery_report_bytes($memory['total_bytes'] ?? 0)];
    if ((int) ($memory['available_bytes'] ?? 0) > 0) {
        $parts[] = 'available ' . admin_gallery_report_bytes($memory['available_bytes']);
    }
    if ((int) ($memory['swap_total_bytes'] ?? 0) > 0) {
        $parts[] = 'swap ' . admin_gallery_report_bytes($memory['swap_total_bytes']) . ' total, ' . admin_gallery_report_bytes($memory['swap_free_bytes'] ?? 0) . ' free';
    }
    return implode(', ', $parts);
}

/**
 * Format server load average information.
 *
 * @param array $load Load average values.
 * @return string Human-readable load average.
 */
function admin_gallery_report_load_average_label(array $load): string
{
    if ($load === []) {
        return 'not exposed by host';
    }
    $values = [];
    foreach (array_slice($load, 0, 3) as $value) {
        $values[] = admin_gallery_report_n($value, 2);
    }
    return implode(' / ', $values);
}

/**
 * Format GD library information.
 *
 * @param array $gdInfo GD information.
 * @return string Human-readable GD label.
 */
function admin_gallery_report_gd_label(array $gdInfo): string
{
    if ($gdInfo === []) {
        return 'not loaded';
    }
    return (string) ($gdInfo['GD Version'] ?? 'loaded');
}

/**
 * Format percentage.
 *
 * @param mixed $part Part value.
 * @param mixed $total Total value.
 * @return string Formatted percent.
 */
function admin_gallery_report_percent(mixed $part, mixed $total): string
{
    $totalValue = (float) $total;
    if ($totalValue <= 0) {
        return '0.0 %';
    }
    return number_format(((float) $part / $totalValue) * 100.0, 1, '.', ' ') . ' %';
}

/**
 * Format duration.
 *
 * @param int $seconds Duration in seconds.
 * @return string Formatted duration.
 */
function admin_gallery_report_duration(int $seconds): string
{
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $remaining = $seconds % 60;
    if ($hours > 0) {
        return $hours . ' h ' . $minutes . ' min ' . $remaining . ' s';
    }
    if ($minutes > 0) {
        return $minutes . ' min ' . $remaining . ' s';
    }
    return $remaining . ' s';
}

/**
 * Return a compact date string.
 *
 * @param string $value Datetime value.
 * @return string Date text.
 */
function admin_gallery_report_short_date(string $value): string
{
    if (!admin_gallery_report_valid_datetime($value)) {
        return '';
    }
    return substr($value, 0, 10);
}

/**
 * Return report CSS.
 *
 * @return string CSS text.
 */
function admin_gallery_report_css(): string
{
    return ':root{color-scheme:light dark;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f3f6fb;color:#172033}*{box-sizing:border-box}body{margin:0;padding:28px}main{max-width:1500px;margin:0 auto}header,.panel{background:rgba(255,255,255,.96);border:1px solid rgba(88,106,140,.20);border-radius:24px;box-shadow:0 18px 55px rgba(24,38,67,.10);padding:24px;margin:0 0 22px}h1{font-size:36px;margin:0 0 10px}h2{font-size:24px;margin:0 0 16px}h3{font-size:17px;margin:22px 0 10px}.eyebrow{margin:0 0 8px;text-transform:uppercase;letter-spacing:.11em;font-size:12px;color:#5e73b8;font-weight:700}.muted,p,li{color:#5b6678;line-height:1.55}.print-note{display:inline-flex;border:1px solid rgba(82,116,180,.25);border-radius:999px;padding:8px 12px;color:#415173;background:rgba(82,116,180,.08);font-size:13px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(185px,1fr));gap:14px}.split{display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:18px}.metric{border:1px solid rgba(88,106,140,.18);border-radius:18px;padding:16px;background:linear-gradient(180deg,rgba(255,255,255,.98),rgba(246,249,253,.95));min-width:0}.metric span{display:block;color:#637088;font-size:13px}.metric strong{display:block;font-size:25px;margin:4px 0;word-break:break-word}.metric small{display:block;color:#6a7487;word-break:break-word}.facts{display:grid;grid-template-columns:minmax(160px,260px) 1fr;gap:8px 14px;margin:0}.facts dt{font-weight:700;color:#35435c}.facts dd{margin:0;color:#5b6678;word-break:break-word}.table-scroll{overflow:auto;border:1px solid rgba(88,106,140,.18);border-radius:16px;margin:10px 0 16px}table{width:100%;border-collapse:collapse;min-width:760px}th,td{text-align:left;padding:10px 12px;border-bottom:1px solid rgba(88,106,140,.16);vertical-align:top;white-space:nowrap}th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;background:rgba(82,116,180,.10);color:#35435c}td{font-size:13px}tr:last-child td{border-bottom:0}.bars{display:grid;gap:9px;margin:10px 0 16px}.bar-row{display:grid;grid-template-columns:minmax(120px,260px) 1fr minmax(80px,auto);gap:10px;align-items:center}.bar-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#35435c;font-weight:600}.bar-track{height:12px;background:rgba(82,116,180,.14);border-radius:999px;overflow:hidden}.bar-track span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#5877e8,#55b783)}.bar-value{text-align:right;color:#5b6678;font-variant-numeric:tabular-nums}@media print{body{padding:0;background:#fff}.panel,header{box-shadow:none;border-color:#d4d9e4;break-inside:avoid}.table-scroll{overflow:visible}table{min-width:0;font-size:10px}th,td{white-space:normal;padding:6px}.print-note{display:none}}@media (prefers-color-scheme:dark){:root{background:#101521;color:#eef2fb}header,.panel{background:#171e2d;border-color:#303a50}.metric{background:#1c2435;border-color:#303a50}.muted,p,li,.metric span,.metric small,.facts dd,.bar-value{color:#aeb8cc}th{background:#222d43;color:#dbe3f5}.table-scroll,td,th{border-color:#303a50}.bar-track{background:#263148}.bar-label,.facts dt{color:#dce5f7}.print-note{background:#202b42;color:#dbe3f5;border-color:#384864}}';
}
