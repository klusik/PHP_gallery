<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_storage_statistics.php
 * Module Type: Service
 *
 * Purpose:
 *   Builds detailed storage statistics for the Admin dashboard.
 *
 * Responsibilities:
 *   - Separate source-photo byte totals from generated thumbnail byte totals
 *   - Aggregate image file types, source-size ranges, and largest galleries
 *   - Cache filesystem-derived thumbnail statistics briefly for shared hosting
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

const ADMIN_STORAGE_STATISTICS_CACHE_KEY = 'admin_storage_statistics_cache_v2';
const ADMIN_STORAGE_STATISTICS_JOB_KEY = 'admin_storage_statistics_job_v1';
const ADMIN_STORAGE_STATISTICS_DEFAULT_BATCH_SIZE = 20;
const ADMIN_STORAGE_STATISTICS_MAX_BATCH_SIZE = 80;

/**
 * Return detailed storage statistics for dashboard rendering.
 *
 * Source-file sizes come from the images table. Generated thumbnail and DNG
 * display-master sizes come from bounded filesystem checks against expected
 * derivative paths only, so random cache files are not counted as photos.
 *
 * @return array<string, mixed>
 */
function admin_storage_statistics(bool $forceRefresh = false): array
{
    $fingerprint = admin_storage_statistics_fingerprint();
    if (!$forceRefresh) {
        $cached = admin_storage_statistics_cache_read($fingerprint);
        if ($cached !== null) {
            $cached['cache_hit'] = true;
            return $cached;
        }
        return [];
    }

    $statistics = admin_storage_statistics_build($fingerprint);
    admin_storage_statistics_cache_write($statistics);
    $statistics['cache_hit'] = false;
    return $statistics;
}

/**
 * Return the most recent cached storage statistics without rebuilding them.
 *
 * @return array<string, mixed>|null
 */
function admin_storage_statistics_cached_snapshot(bool $allowStale = true): ?array
{
    if (!function_exists('app_setting')) {
        return null;
    }

    $raw = app_setting(ADMIN_STORAGE_STATISTICS_CACHE_KEY, '');
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    $fingerprint = admin_storage_statistics_fingerprint();
    $cachedFingerprint = (string) ($decoded['fingerprint'] ?? '');
    $isStale = $fingerprint !== '' && $cachedFingerprint !== '' && !hash_equals($cachedFingerprint, $fingerprint);
    if ($isStale && !$allowStale) {
        return null;
    }

    $decoded['cache_hit'] = true;
    $decoded['cache_stale'] = $isStale;
    return $decoded;
}

/**
 * Start a manual, browser-driven storage statistics job.
 *
 * Source-file statistics are calculated once from database rows. Filesystem
 * checks for generated images are then processed in small Ajax batches so the
 * admin page can display progress and shared hosting requests stay short.
 *
 * @return array<string, mixed>
 */
function admin_storage_statistics_start_job(): array
{
    $fingerprint = admin_storage_statistics_fingerprint();
    $rows = admin_storage_statistics_image_rows();
    $source = admin_storage_statistics_source_summary($rows);

    $job = [
        'job_id' => admin_storage_statistics_job_id(),
        'status' => 'running',
        'fingerprint' => $fingerprint,
        'started_at' => time(),
        'updated_at' => time(),
        'total' => count($rows),
        'processed' => 0,
        'last_image_id' => 0,
        'source' => admin_storage_statistics_compact_source_summary($source),
        'generated' => admin_storage_statistics_empty_generated_summary(),
    ];

    if ((int) $job['total'] <= 0) {
        return admin_storage_statistics_finish_job($job);
    }

    admin_storage_statistics_job_write($job);
    return admin_storage_statistics_job_public_state($job);
}

/**
 * Process one manual storage statistics job batch.
 *
 * @return array<string, mixed>
 */
function admin_storage_statistics_process_job(int $batchSize = ADMIN_STORAGE_STATISTICS_DEFAULT_BATCH_SIZE): array
{
    $job = admin_storage_statistics_job_read();
    if ($job === null || (string) ($job['status'] ?? '') !== 'running') {
        return [
            'ok' => false,
            'status' => 'missing',
            'message' => 'No running storage statistics job was found.',
        ];
    }

    $currentFingerprint = admin_storage_statistics_fingerprint();
    if ($currentFingerprint !== '' && (string) ($job['fingerprint'] ?? '') !== '' && !hash_equals((string) $job['fingerprint'], $currentFingerprint)) {
        $job['status'] = 'stale';
        $job['updated_at'] = time();
        admin_storage_statistics_job_write($job);
        return admin_storage_statistics_job_public_state($job, null, 'Gallery data changed while statistics were being calculated. Start a new update.');
    }

    $batchSize = max(1, min(ADMIN_STORAGE_STATISTICS_MAX_BATCH_SIZE, $batchSize));
    $lastImageId = max(0, (int) ($job['last_image_id'] ?? 0));
    $rows = admin_storage_statistics_image_rows_after_id($lastImageId, $batchSize);
    $generated = admin_storage_statistics_normalize_generated_summary(is_array($job['generated'] ?? null) ? $job['generated'] : []);

    foreach ($rows as $row) {
        admin_storage_statistics_accumulate_generated_media_row($generated, $row);
        $lastImageId = max($lastImageId, (int) ($row['image_id'] ?? 0));
    }

    $processed = min(max(0, (int) ($job['total'] ?? 0)), max(0, (int) ($job['processed'] ?? 0)) + count($rows));
    $job['generated'] = $generated;
    $job['processed'] = $processed;
    $job['last_image_id'] = $lastImageId;
    $job['updated_at'] = time();

    if ($rows === [] || $processed >= (int) ($job['total'] ?? 0)) {
        return admin_storage_statistics_finish_job($job);
    }

    admin_storage_statistics_job_write($job);
    return admin_storage_statistics_job_public_state($job);
}

/**
 * Build a stable database fingerprint used to invalidate cached statistics.
 */
function admin_storage_statistics_fingerprint(): string
{
    try {
        $row = db()->query("SELECT COUNT(*) AS image_count, COALESCE(SUM(COALESCE(file_size, 0)), 0) AS original_bytes, COALESCE(MAX(id), 0) AS newest_image_id, COALESCE(MAX(updated_at), '') AS newest_image_update FROM images")->fetch() ?: [];
        $galleryRow = db()->query("SELECT COUNT(*) AS gallery_count, COALESCE(MAX(updated_at), '') AS newest_gallery_update FROM galleries")->fetch() ?: [];
    } catch (Throwable) {
        return '';
    }

    return hash('sha256', implode('|', [
        (string) ($row['image_count'] ?? '0'),
        (string) ($row['original_bytes'] ?? '0'),
        (string) ($row['newest_image_id'] ?? '0'),
        (string) ($row['newest_image_update'] ?? ''),
        (string) ($galleryRow['gallery_count'] ?? '0'),
        (string) ($galleryRow['newest_gallery_update'] ?? ''),
    ]));
}

/**
 * Read cached storage statistics when they match the current fingerprint.
 *
 * @return array<string, mixed>|null
 */
function admin_storage_statistics_cache_read(string $fingerprint): ?array
{
    if ($fingerprint === '' || !function_exists('app_setting')) {
        return null;
    }

    $raw = app_setting(ADMIN_STORAGE_STATISTICS_CACHE_KEY, '');
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    if ((string) ($decoded['fingerprint'] ?? '') !== $fingerprint) {
        return null;
    }

    $decoded['cache_stale'] = false;
    return $decoded;
}

/**
 * Store cached statistics and ignore cache-write failures.
 *
 * @param array<string, mixed> $statistics
 */
function admin_storage_statistics_cache_write(array $statistics): void
{
    if (!function_exists('set_app_setting')) {
        return;
    }

    $encoded = json_encode($statistics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        return;
    }

    try {
        set_app_setting(ADMIN_STORAGE_STATISTICS_CACHE_KEY, $encoded);
    } catch (Throwable) {
        // Cache persistence must never prevent the Admin dashboard from loading.
    }
}

/**
 * Build uncached statistics from database rows and expected generated media paths.
 *
 * @return array<string, mixed>
 */
function admin_storage_statistics_build(string $fingerprint): array
{
    $rows = admin_storage_statistics_image_rows();
    $source = admin_storage_statistics_source_summary($rows);
    $generated = admin_storage_statistics_generated_media_summary($rows);

    return admin_storage_statistics_snapshot_from_summaries($fingerprint, admin_storage_statistics_compact_source_summary($source), $generated);
}

/**
 * Return image rows required by source and generated-media statistics.
 *
 * @return array<int, array<string, mixed>>
 */
function admin_storage_statistics_image_rows(): array
{
    try {
        return db()->query("SELECT i.id AS image_id, i.gallery_id AS image_gallery_id, i.relative_path, i.filename, i.mime_type, COALESCE(i.file_size, 0) AS file_size, i.width, i.height, g.id AS gallery_id, g.title AS gallery_title, g.folder_path AS gallery_folder_path FROM images i INNER JOIN galleries g ON g.id = i.gallery_id ORDER BY g.folder_path, i.relative_path")->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

/**
 * Return a generated-media statistics batch after one image id.
 *
 * @return array<int, array<string, mixed>>
 */
function admin_storage_statistics_image_rows_after_id(int $lastImageId, int $limit): array
{
    $lastImageId = max(0, $lastImageId);
    $limit = max(1, min(ADMIN_STORAGE_STATISTICS_MAX_BATCH_SIZE, $limit));

    try {
        $stmt = db()->query("SELECT i.id AS image_id, i.gallery_id AS image_gallery_id, i.relative_path, i.filename, i.mime_type, COALESCE(i.file_size, 0) AS file_size, i.width, i.height, g.id AS gallery_id, g.title AS gallery_title, g.folder_path AS gallery_folder_path FROM images i INNER JOIN galleries g ON g.id = i.gallery_id WHERE i.id > " . $lastImageId . " ORDER BY i.id LIMIT " . $limit);
        return $stmt ? $stmt->fetchAll() : [];
    } catch (Throwable) {
        return [];
    }
}

/**
 * Aggregate source-photo statistics from image rows.
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<string, mixed>
 */
function admin_storage_statistics_source_summary(array $rows): array
{
    $summary = [
        'image_count' => 0,
        'known_source_size_count' => 0,
        'unknown_source_size_count' => 0,
        'original_bytes' => 0,
        'largest_original_bytes' => 0,
        'largest_original_name' => '',
        'type_groups' => [],
        'size_bucket_groups' => admin_storage_statistics_empty_size_bucket_groups(),
        'gallery_groups' => [],
    ];

    foreach ($rows as $row) {
        admin_storage_statistics_accumulate_source_row($summary, $row);
    }

    $knownSourceSizeCount = (int) ($summary['known_source_size_count'] ?? 0);
    $summary['average_original_bytes'] = $knownSourceSizeCount > 0 ? (int) floor((int) $summary['original_bytes'] / $knownSourceSizeCount) : 0;
    return $summary;
}

/**
 * Add one image row to the source-photo statistics accumulator.
 *
 * @param array<string, mixed> $summary
 * @param array<string, mixed> $row
 */
function admin_storage_statistics_accumulate_source_row(array &$summary, array $row): void
{
    $bytes = max(0, (int) ($row['file_size'] ?? 0));
    $summary['image_count'] = (int) ($summary['image_count'] ?? 0) + 1;
    $summary['original_bytes'] = (int) ($summary['original_bytes'] ?? 0) + $bytes;
    if ($bytes > 0) {
        $summary['known_source_size_count'] = (int) ($summary['known_source_size_count'] ?? 0) + 1;
    } else {
        $summary['unknown_source_size_count'] = (int) ($summary['unknown_source_size_count'] ?? 0) + 1;
    }
    if ($bytes > (int) ($summary['largest_original_bytes'] ?? 0)) {
        $summary['largest_original_bytes'] = $bytes;
        $summary['largest_original_name'] = (string) ($row['relative_path'] ?? $row['filename'] ?? '');
    }

    $extension = admin_storage_statistics_normalize_file_extension((string) ($row['filename'] ?? $row['relative_path'] ?? ''));
    $typeLabel = strtoupper($extension);
    $typeGroups = is_array($summary['type_groups'] ?? null) ? $summary['type_groups'] : [];
    admin_storage_statistics_add_group_value($typeGroups, 'source-type-' . $extension, $typeLabel, 1, $bytes, [
        'extension' => $extension,
        'mime_type' => (string) ($row['mime_type'] ?? ''),
    ]);
    $summary['type_groups'] = $typeGroups;

    $sizeBucketGroups = is_array($summary['size_bucket_groups'] ?? null) ? $summary['size_bucket_groups'] : admin_storage_statistics_empty_size_bucket_groups();
    $bucketKey = admin_storage_statistics_size_bucket_key($bytes);
    admin_storage_statistics_add_group_value($sizeBucketGroups, $bucketKey, admin_storage_statistics_size_bucket_fallback($bucketKey), 1, $bytes, [
        'bucket_key' => $bucketKey,
        'label_key' => admin_storage_statistics_size_bucket_translation_key($bucketKey),
    ]);
    $summary['size_bucket_groups'] = $sizeBucketGroups;

    $galleryGroups = is_array($summary['gallery_groups'] ?? null) ? $summary['gallery_groups'] : [];
    $galleryId = (int) ($row['gallery_id'] ?? $row['image_gallery_id'] ?? 0);
    $galleryTitle = trim((string) ($row['gallery_title'] ?? ''));
    $galleryPath = trim((string) ($row['gallery_folder_path'] ?? ''));
    $galleryLabel = $galleryTitle !== '' ? $galleryTitle : ($galleryPath !== '' ? $galleryPath : ('#' . $galleryId));
    admin_storage_statistics_add_group_value($galleryGroups, 'gallery-' . $galleryId, $galleryLabel, 1, $bytes, [
        'gallery_id' => $galleryId,
        'folder_path' => $galleryPath,
    ]);
    $summary['gallery_groups'] = $galleryGroups;
}

/**
 * Return compact source statistics suitable for a cache record or job state.
 *
 * @param array<string, mixed> $source
 * @return array<string, mixed>
 */
function admin_storage_statistics_compact_source_summary(array $source): array
{
    return [
        'image_count' => (int) ($source['image_count'] ?? 0),
        'known_source_size_count' => (int) ($source['known_source_size_count'] ?? 0),
        'unknown_source_size_count' => (int) ($source['unknown_source_size_count'] ?? 0),
        'original_bytes' => (int) ($source['original_bytes'] ?? 0),
        'average_original_bytes' => (int) ($source['average_original_bytes'] ?? 0),
        'largest_original_bytes' => (int) ($source['largest_original_bytes'] ?? 0),
        'largest_original_name' => (string) ($source['largest_original_name'] ?? ''),
        'type_rows' => admin_storage_statistics_finalize_group_rows(is_array($source['type_groups'] ?? null) ? $source['type_groups'] : [], 'bytes'),
        'size_bucket_rows' => admin_storage_statistics_finalize_group_rows(is_array($source['size_bucket_groups'] ?? null) ? $source['size_bucket_groups'] : [], 'bytes'),
        'largest_gallery_rows' => admin_storage_statistics_finalize_group_rows(is_array($source['gallery_groups'] ?? null) ? $source['gallery_groups'] : [], 'bytes'),
    ];
}

/**
 * Aggregate generated thumbnail and display-master statistics from expected paths.
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<string, mixed>
 */
function admin_storage_statistics_generated_media_summary(array $rows): array
{
    $summary = admin_storage_statistics_empty_generated_summary();
    foreach ($rows as $row) {
        admin_storage_statistics_accumulate_generated_media_row($summary, $row);
    }
    return $summary;
}

/**
 * Return an empty generated-media statistics accumulator.
 *
 * @return array<string, mixed>
 */
function admin_storage_statistics_empty_generated_summary(): array
{
    return [
        'thumbnail_bytes' => 0,
        'thumbnail_count' => 0,
        'display_master_bytes' => 0,
        'display_master_count' => 0,
        'generated_bytes' => 0,
        'scan_errors' => 0,
        'generated_type_groups' => [],
    ];
}

/**
 * Normalize a generated-media statistics accumulator read from JSON.
 *
 * @param array<string, mixed> $summary
 * @return array<string, mixed>
 */
function admin_storage_statistics_normalize_generated_summary(array $summary): array
{
    return [
        'thumbnail_bytes' => max(0, (int) ($summary['thumbnail_bytes'] ?? 0)),
        'thumbnail_count' => max(0, (int) ($summary['thumbnail_count'] ?? 0)),
        'display_master_bytes' => max(0, (int) ($summary['display_master_bytes'] ?? 0)),
        'display_master_count' => max(0, (int) ($summary['display_master_count'] ?? 0)),
        'generated_bytes' => max(0, (int) ($summary['generated_bytes'] ?? 0)),
        'scan_errors' => max(0, (int) ($summary['scan_errors'] ?? 0)),
        'generated_type_groups' => is_array($summary['generated_type_groups'] ?? null) ? $summary['generated_type_groups'] : [],
    ];
}

/**
 * Add one image row to the generated-media statistics accumulator.
 *
 * @param array<string, mixed> $summary
 * @param array<string, mixed> $row
 */
function admin_storage_statistics_accumulate_generated_media_row(array &$summary, array $row): void
{
    $summary = admin_storage_statistics_normalize_generated_summary($summary);
    $sizes = function_exists('thumbnail_sizes') ? thumbnail_sizes() : [300, 600, 800, 960, 1280, 1600];
    $formats = ['jpg', 'webp'];
    $gallery = [
        'id' => (int) ($row['gallery_id'] ?? $row['image_gallery_id'] ?? 0),
        'folder_path' => (string) ($row['gallery_folder_path'] ?? ''),
        'title' => (string) ($row['gallery_title'] ?? ''),
    ];
    $image = [
        'id' => (int) ($row['image_id'] ?? 0),
        'gallery_id' => (int) ($row['image_gallery_id'] ?? $row['gallery_id'] ?? 0),
        'relative_path' => (string) ($row['relative_path'] ?? ''),
        'filename' => (string) ($row['filename'] ?? ''),
        'mime_type' => (string) ($row['mime_type'] ?? ''),
    ];

    foreach ($sizes as $size) {
        foreach ($formats as $format) {
            try {
                $path = function_exists('thumbnail_abs_path') ? thumbnail_abs_path($image, $gallery, (int) $size, $format) : '';
            } catch (Throwable) {
                $summary['scan_errors'] = (int) $summary['scan_errors'] + 1;
                continue;
            }
            $bytes = admin_storage_statistics_existing_file_size($path);
            if ($bytes <= 0) {
                continue;
            }
            $summary['thumbnail_bytes'] = (int) $summary['thumbnail_bytes'] + $bytes;
            $summary['thumbnail_count'] = (int) $summary['thumbnail_count'] + 1;
            $generatedTypeGroups = is_array($summary['generated_type_groups'] ?? null) ? $summary['generated_type_groups'] : [];
            admin_storage_statistics_add_group_value($generatedTypeGroups, 'thumbnail-' . $format, strtoupper($format) . ' thumbnails', 1, $bytes, [
                'kind' => 'thumbnail',
                'format' => $format,
                'label_key' => 'admin.storage.generated_' . $format . '_thumbnails',
            ]);
            $summary['generated_type_groups'] = $generatedTypeGroups;
        }
    }

    if (function_exists('image_uses_dng_display_derivatives') && function_exists('dng_display_master_abs_path') && image_uses_dng_display_derivatives($image)) {
        try {
            $displayMasterPath = dng_display_master_abs_path($image, $gallery, false);
        } catch (Throwable) {
            $summary['scan_errors'] = (int) $summary['scan_errors'] + 1;
            $summary['generated_bytes'] = (int) $summary['thumbnail_bytes'] + (int) $summary['display_master_bytes'];
            return;
        }
        $bytes = admin_storage_statistics_existing_file_size($displayMasterPath);
        if ($bytes > 0) {
            $summary['display_master_bytes'] = (int) $summary['display_master_bytes'] + $bytes;
            $summary['display_master_count'] = (int) $summary['display_master_count'] + 1;
            $generatedTypeGroups = is_array($summary['generated_type_groups'] ?? null) ? $summary['generated_type_groups'] : [];
            admin_storage_statistics_add_group_value($generatedTypeGroups, 'display-master-webp', 'WEBP display masters', 1, $bytes, [
                'kind' => 'display_master',
                'format' => 'webp',
                'label_key' => 'admin.storage.generated_webp_display_masters',
            ]);
            $summary['generated_type_groups'] = $generatedTypeGroups;
        }
    }

    $summary['generated_bytes'] = (int) $summary['thumbnail_bytes'] + (int) $summary['display_master_bytes'];
}

/**
 * Build the final statistics snapshot from compact source and generated summaries.
 *
 * @param array<string, mixed> $source
 * @param array<string, mixed> $generated
 * @return array<string, mixed>
 */
function admin_storage_statistics_snapshot_from_summaries(string $fingerprint, array $source, array $generated): array
{
    $generated = admin_storage_statistics_normalize_generated_summary($generated);
    $originalBytes = (int) ($source['original_bytes'] ?? 0);
    $generatedBytes = (int) ($generated['thumbnail_bytes'] ?? 0) + (int) ($generated['display_master_bytes'] ?? 0);
    $totalPictureBytes = $originalBytes + $generatedBytes;

    return [
        'fingerprint' => $fingerprint,
        'generated_at' => time(),
        'image_count' => (int) ($source['image_count'] ?? 0),
        'known_source_size_count' => (int) ($source['known_source_size_count'] ?? 0),
        'unknown_source_size_count' => (int) ($source['unknown_source_size_count'] ?? 0),
        'original_bytes' => $originalBytes,
        'average_original_bytes' => (int) ($source['average_original_bytes'] ?? 0),
        'largest_original_bytes' => (int) ($source['largest_original_bytes'] ?? 0),
        'largest_original_name' => (string) ($source['largest_original_name'] ?? ''),
        'generated_thumbnail_bytes' => (int) ($generated['thumbnail_bytes'] ?? 0),
        'generated_thumbnail_count' => (int) ($generated['thumbnail_count'] ?? 0),
        'display_master_bytes' => (int) ($generated['display_master_bytes'] ?? 0),
        'display_master_count' => (int) ($generated['display_master_count'] ?? 0),
        'generated_bytes' => $generatedBytes,
        'total_picture_bytes' => $totalPictureBytes,
        'generated_to_original_percent' => $originalBytes > 0 ? round(($generatedBytes / $originalBytes) * 100, 1) : 0.0,
        'thumbnail_scan_errors' => (int) ($generated['scan_errors'] ?? 0),
        'type_rows' => is_array($source['type_rows'] ?? null) ? $source['type_rows'] : [],
        'size_bucket_rows' => is_array($source['size_bucket_rows'] ?? null) ? $source['size_bucket_rows'] : [],
        'largest_gallery_rows' => is_array($source['largest_gallery_rows'] ?? null) ? $source['largest_gallery_rows'] : [],
        'generated_type_rows' => admin_storage_statistics_finalize_group_rows(is_array($generated['generated_type_groups'] ?? null) ? $generated['generated_type_groups'] : [], 'bytes'),
    ];
}

/**
 * Finish a manual job and store its statistics snapshot.
 *
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function admin_storage_statistics_finish_job(array $job): array
{
    $job['status'] = 'complete';
    $job['processed'] = (int) ($job['total'] ?? $job['processed'] ?? 0);
    $job['updated_at'] = time();
    $snapshot = admin_storage_statistics_snapshot_from_summaries((string) ($job['fingerprint'] ?? ''), is_array($job['source'] ?? null) ? $job['source'] : [], is_array($job['generated'] ?? null) ? $job['generated'] : []);
    admin_storage_statistics_cache_write($snapshot);
    $job['snapshot'] = $snapshot;
    admin_storage_statistics_job_write($job);
    return admin_storage_statistics_job_public_state($job, $snapshot);
}

/**
 * Return a compact public state for a manual storage statistics job.
 *
 * @param array<string, mixed> $job
 * @param array<string, mixed>|null $snapshot
 * @return array<string, mixed>
 */
function admin_storage_statistics_job_public_state(array $job, ?array $snapshot = null, string $message = ''): array
{
    $total = max(0, (int) ($job['total'] ?? 0));
    $processed = max(0, min($total, (int) ($job['processed'] ?? 0)));
    $status = (string) ($job['status'] ?? 'running');
    return [
        'ok' => $status !== 'stale',
        'job_id' => (string) ($job['job_id'] ?? ''),
        'status' => $status,
        'total' => $total,
        'processed' => $processed,
        'percent' => $total > 0 ? round(($processed / $total) * 100, 1) : 100.0,
        'message' => $message,
        'snapshot' => $snapshot,
    ];
}

/**
 * Read a manual storage statistics job state from application settings.
 *
 * @return array<string, mixed>|null
 */
function admin_storage_statistics_job_read(): ?array
{
    if (!function_exists('app_setting')) {
        return null;
    }
    $raw = app_setting(ADMIN_STORAGE_STATISTICS_JOB_KEY, '');
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Store a manual storage statistics job state.
 *
 * @param array<string, mixed> $job
 */
function admin_storage_statistics_job_write(array $job): void
{
    if (!function_exists('set_app_setting')) {
        return;
    }
    $encoded = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        return;
    }
    set_app_setting(ADMIN_STORAGE_STATISTICS_JOB_KEY, $encoded);
}

/**
 * Build a short opaque job id for manual storage statistics runs.
 */
function admin_storage_statistics_job_id(): string
{
    try {
        return bin2hex(random_bytes(8));
    } catch (Throwable) {
        return str_replace('.', '', uniqid('storage', true));
    }
}

/**
 * Return a safe size for an existing file or zero for missing/unreadable paths.
 */
function admin_storage_statistics_existing_file_size(string $path): int
{
    if ($path === '' || !is_file($path)) {
        return 0;
    }

    $bytes = @filesize($path);
    return $bytes === false ? 0 : max(0, (int) $bytes);
}

/**
 * Normalize one file extension for grouping.
 */
function admin_storage_statistics_normalize_file_extension(string $path): string
{
    $extension = strtolower(trim((string) pathinfo($path, PATHINFO_EXTENSION)));
    if ($extension === '') {
        return 'unknown';
    }
    if ($extension === 'jpeg') {
        return 'jpg';
    }
    if ($extension === 'tif') {
        return 'tiff';
    }
    return preg_match('/^[a-z0-9]{1,12}$/', $extension) === 1 ? $extension : 'other';
}

/**
 * Return preseeded source-size bucket groups in dashboard order.
 *
 * @return array<string, array<string, mixed>>
 */
function admin_storage_statistics_empty_size_bucket_groups(): array
{
    $groups = [];
    foreach (admin_storage_statistics_size_bucket_order() as $bucketKey) {
        $groups[$bucketKey] = [
            'key' => $bucketKey,
            'label' => admin_storage_statistics_size_bucket_fallback($bucketKey),
            'count' => 0,
            'bytes' => 0,
            'bucket_key' => $bucketKey,
            'label_key' => admin_storage_statistics_size_bucket_translation_key($bucketKey),
        ];
    }
    return $groups;
}

/**
 * Return source-size bucket keys in display order.
 *
 * @return array<int, string>
 */
function admin_storage_statistics_size_bucket_order(): array
{
    return ['unknown', 'under_1mb', '1_3mb', '3_8mb', '8_20mb', '20_50mb', 'over_50mb'];
}

/**
 * Return the source-size bucket key for one byte count.
 */
function admin_storage_statistics_size_bucket_key(int $bytes): string
{
    if ($bytes <= 0) {
        return 'unknown';
    }
    if ($bytes < 1024 * 1024) {
        return 'under_1mb';
    }
    if ($bytes < 3 * 1024 * 1024) {
        return '1_3mb';
    }
    if ($bytes < 8 * 1024 * 1024) {
        return '3_8mb';
    }
    if ($bytes < 20 * 1024 * 1024) {
        return '8_20mb';
    }
    if ($bytes < 50 * 1024 * 1024) {
        return '20_50mb';
    }
    return 'over_50mb';
}

/**
 * Return a translation key for one source-size bucket.
 */
function admin_storage_statistics_size_bucket_translation_key(string $bucketKey): string
{
    return 'admin.storage.size_bucket_' . $bucketKey;
}

/**
 * Return an English fallback label for one source-size bucket.
 */
function admin_storage_statistics_size_bucket_fallback(string $bucketKey): string
{
    return match ($bucketKey) {
        'under_1mb' => '< 1 MB',
        '1_3mb' => '1-3 MB',
        '3_8mb' => '3-8 MB',
        '8_20mb' => '8-20 MB',
        '20_50mb' => '20-50 MB',
        'over_50mb' => '50+ MB',
        default => 'Unknown',
    };
}

/**
 * Add count and byte values to a grouped statistics row.
 *
 * @param array<string, array<string, mixed>> $groups
 * @param array<string, mixed> $metadata
 */
function admin_storage_statistics_add_group_value(array &$groups, string $key, string $label, int $count, int $bytes, array $metadata = []): void
{
    if (!isset($groups[$key])) {
        $groups[$key] = array_merge([
            'key' => $key,
            'label' => $label,
            'count' => 0,
            'bytes' => 0,
        ], $metadata);
    }

    $groups[$key]['count'] = (int) ($groups[$key]['count'] ?? 0) + max(0, $count);
    $groups[$key]['bytes'] = (int) ($groups[$key]['bytes'] ?? 0) + max(0, $bytes);
}

/**
 * Sort grouped rows and add percentage values for chart rendering.
 *
 * @param array<string, array<string, mixed>> $groups
 * @return array<int, array<string, mixed>>
 */
function admin_storage_statistics_finalize_group_rows(array $groups, string $valueKey, int $limit = 8): array
{
    $rows = array_values($groups);
    $total = 0;
    foreach ($rows as $row) {
        $total += max(0, (int) ($row[$valueKey] ?? 0));
    }

    usort($rows, static function (array $left, array $right) use ($valueKey): int {
        $valueCompare = (int) ($right[$valueKey] ?? 0) <=> (int) ($left[$valueKey] ?? 0);
        if ($valueCompare !== 0) {
            return $valueCompare;
        }
        return strnatcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
    });

    if ($limit > 0 && count($rows) > $limit) {
        $rows = array_slice($rows, 0, $limit);
    }

    foreach ($rows as $index => $row) {
        $value = max(0, (int) ($row[$valueKey] ?? 0));
        $rows[$index]['percent'] = $total > 0 ? round(($value / $total) * 100, 1) : 0.0;
    }

    return $rows;
}
