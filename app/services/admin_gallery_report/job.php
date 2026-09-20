<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_gallery_report/job.php
 * Module Type: Service
 *
 * Purpose:
 *   Owns the browser-driven gallery report job lifecycle and its persisted state.
 *
 * Responsibilities:
 *   - Start, advance, and finish the batched report job
 *   - Read, write, and clear the persisted job record
 *   - Expose a bounded public job state for the Admin progress UI
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
 *   - The entry point loads immutable Core policy before this part.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;
use const Gallery\Core\ADMIN_GALLERY_REPORT_TELEMETRY_DEFAULT_DAYS;
use const Gallery\Core\ADMIN_GALLERY_REPORT_TELEMETRY_MAX_DAYS;
use const Gallery\Core\ADMIN_GALLERY_REPORT_ROW_LIMITS;

use Throwable;
use const Gallery\Core\ADMIN_GALLERY_REPORT_DEFAULT_BATCH_SIZE;
use const Gallery\Core\ADMIN_GALLERY_REPORT_MAX_BATCH_SIZE;
use const Gallery\Core\ADMIN_GALLERY_REPORT_GPS_AREA_KM;
use function Gallery\Core\cms_config;
use function Gallery\Core\cms_current_version;
use function Gallery\Core\db;

/**
 * Start a browser-driven gallery overview report job.
 *
 * @param array<string,mixed>|null $checkpoint Caller-owned current job; replaced only after a complete start snapshot is ready.
 * @param int $telemetryDays Telemetry window in days.
 * @return array<string, mixed> Structured job state.
 */
function admin_gallery_report_start_job(?array &$checkpoint, int $telemetryDays = ADMIN_GALLERY_REPORT_TELEMETRY_DEFAULT_DAYS): array
{
    $telemetryDays = max(1, min(ADMIN_GALLERY_REPORT_TELEMETRY_MAX_DAYS, $telemetryDays));
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
        'thumbnail_legacy_inventory' => function_exists(__NAMESPACE__ . '\\thumbnail_legacy_jpg_inventory') ? thumbnail_legacy_jpg_inventory() : ['available' => false],
        'tables' => admin_gallery_report_table_counts(),
        'galleries' => admin_gallery_report_gallery_summary(),
        'gallery_rows' => admin_gallery_report_gallery_detail_rows(),
        'tags' => admin_gallery_report_tag_summary(),
        'votes' => admin_gallery_report_vote_summary(),
        'features' => admin_gallery_report_feature_summary(),
        'logs' => admin_gallery_report_admin_log_summary(),
        'telemetry' => admin_gallery_report_telemetry_section($telemetryDays),
        'top_images' => admin_gallery_report_largest_images(ADMIN_GALLERY_REPORT_ROW_LIMITS['top_images']),
        'image_summary' => admin_gallery_report_initial_image_summary(),
        'storage_source' => function_exists('Gallery\\Services\\admin_storage_statistics_source_summary') ? admin_storage_statistics_source_summary([]) : [],
        'storage_generated' => function_exists('Gallery\\Services\\admin_storage_statistics_initial_generated_summary') ? admin_storage_statistics_initial_generated_summary() : [],
        'thumbnail_metadata_used' => function_exists('Gallery\\Services\\admin_storage_statistics_thumbnail_metadata_available') && admin_storage_statistics_thumbnail_metadata_available(),
        'errors' => [],
    ];

    if ($totalImages <= 0) {
        return admin_gallery_report_finish_job($job, $checkpoint);
    }

    admin_gallery_report_job_write($checkpoint, $job);
    return admin_gallery_report_public_state($job);
}

/**
 * Process one bounded report generation batch.
 *
 * @param array<string,mixed>|null $checkpoint Caller-owned current job, updated after a completed batch or cleared at finish.
 * @param int $batchSize Number of image rows to inspect.
 * @return array<string, mixed> Structured job state.
 */
function admin_gallery_report_process_job(?array &$checkpoint, int $batchSize = ADMIN_GALLERY_REPORT_DEFAULT_BATCH_SIZE): array
{
    $job = admin_gallery_report_job_read($checkpoint);
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
        return admin_gallery_report_finish_job($job, $checkpoint);
    }

    admin_gallery_report_job_write($checkpoint, $job);
    return admin_gallery_report_public_state($job);
}

/**
 * Finish a report job and return the final HTML in the response only.
 *
 * @param array<string,mixed> $job Accumulated report: counts, timestamps, image/storage summaries and prepared report sections.
 * @param array<string,mixed>|null $checkpoint Caller-owned checkpoint; cleared before final logging and presentation preparation.
 * @return array<string, mixed> Public state.
 */
function admin_gallery_report_finish_job(array $job, ?array &$checkpoint): array
{
    $job['status'] = 'complete';
    $job['processed'] = (int) ($job['total'] ?? $job['processed'] ?? 0);
    $job['updated_at'] = time();
    $job['finished_at'] = time();
    $job['duration_seconds'] = max(0, (int) ($job['finished_at'] ?? time()) - (int) ($job['started_at'] ?? time()));
    $job['image_summary'] = admin_gallery_report_finalize_image_summary(is_array($job['image_summary'] ?? null) ? $job['image_summary'] : admin_gallery_report_initial_image_summary());
    $job['storage'] = admin_gallery_report_storage_snapshot($job);

    $filename = 'php-gallery-complete-overview-' . gmdate('Ymd-His') . '.html';
    admin_gallery_report_job_clear($checkpoint);

    if (function_exists('Gallery\\Services\\admin_log_event')) {
        admin_log_event('info', 'admin_report.generated', 'Admin generated a complete gallery overview report.', [
            'images' => (int) ($job['total'] ?? 0),
            'duration_seconds' => (int) ($job['duration_seconds'] ?? 0),
            'telemetry_days' => (int) ($job['telemetry_days'] ?? ADMIN_GALLERY_REPORT_TELEMETRY_DEFAULT_DAYS),
        ], ['category' => 'admin', 'severity' => 'notice', 'route_name' => 'admin_gallery_report']);
    }

    $job['presentation'] = array_merge(
        is_array($job['presentation'] ?? null) ? $job['presentation'] : [],
        ['gps_area_km' => ADMIN_GALLERY_REPORT_GPS_AREA_KM]
    );

    return admin_gallery_report_public_state($job, [
        '_report_view_model' => $job,
        'filename' => $filename,
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
 * Read a normalized report checkpoint supplied by the request boundary.
 *
 * @param array<string,mixed>|null $checkpoint Caller-owned report snapshot, or no active job.
 * @return array<string,mixed>|null Same snapshot; this service does not read session transport.
 */
function admin_gallery_report_job_read(?array $checkpoint): ?array
{
    return $checkpoint;
}

/**
 * Publish a complete batch snapshot into the caller-owned report checkpoint.
 *
 * @param array<string,mixed>|null $checkpoint Caller-owned current snapshot, replaced by reference.
 * @param array<string,mixed> $job Fully accumulated job including cursor, progress and report sections.
 * @return void The caller remains responsible for its chosen storage transport.
 */
function admin_gallery_report_job_write(?array &$checkpoint, array $job): void
{
    $checkpoint = $job;
}

/**
 * Clear the completed report checkpoint without retaining exported report contents.
 *
 * @param array<string,mixed>|null $checkpoint Caller-owned snapshot, reset by reference.
 * @return void The controller removes the corresponding session entry.
 */
function admin_gallery_report_job_clear(?array &$checkpoint): void
{
    $checkpoint = null;
}
