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
 *   - Measure indexed PDF/music originals during explicit gallery batches
 *
 * @phpstan-type StorageChartRow array{key:string,label:string,count:int,
 *     bytes:int,percent?:float,extension?:string,mime_type?:string,
 *     bucket_key?:string,label_key?:string,gallery_id?:int,folder_path?:string,
 *     kind?:string,format?:string}
 * @phpstan-type StorageSourceSummary array{image_count:int,
 *     known_source_size_count:int,unknown_source_size_count:int,
 *     original_bytes:int,average_original_bytes:int,largest_original_bytes:int,
 *     largest_original_name:string,type_rows:list<StorageChartRow>,
 *     size_bucket_rows:list<StorageChartRow>,
 *     largest_gallery_rows:list<StorageChartRow>}
 * @phpstan-type StorageGeneratedSummary array{thumbnail_bytes:int,
 *     thumbnail_count:int,display_master_bytes:int,display_master_count:int,
 *     generated_bytes:int,scan_errors:int,
 *     generated_type_groups:array<string,StorageChartRow>}
 * @phpstan-type StorageAttachmentSummary array{pdf_count:int,pdf_bytes:int,
 *     music_count:int,music_bytes:int,errors:int}
 * @phpstan-type StorageSnapshot array{fingerprint:string,generated_at:int,
 *     image_count:int,known_source_size_count:int,unknown_source_size_count:int,
 *     original_bytes:int,average_original_bytes:int,largest_original_bytes:int,
 *     largest_original_name:string,generated_thumbnail_bytes:int,
 *     generated_thumbnail_count:int,display_master_bytes:int,
 *     display_master_count:int,generated_bytes:int,total_picture_bytes:int,
 *     generated_to_original_percent:float,thumbnail_scan_errors:int,
 *     type_rows:list<StorageChartRow>,size_bucket_rows:list<StorageChartRow>,
 *     largest_gallery_rows:list<StorageChartRow>,
 *     generated_type_rows:list<StorageChartRow>,pdf_count:int,pdf_bytes:int,
 *     music_count:int,music_bytes:int,attachment_scan_errors:int,
 *     attachment_bytes:int,total_media_bytes:int,cache_hit?:bool,
 *     cache_stale?:bool}
 * @phpstan-type StorageJob array{job_id:string,status:string,fingerprint:string,
 *     started_at:int,updated_at:int,total:int,processed:int,last_image_id:int,
 *     image_total:int,image_processed:int,last_gallery_id:int,
 *     gallery_total:int,gallery_processed:int,source:StorageSourceSummary,
 *     generated:StorageGeneratedSummary,attachments:StorageAttachmentSummary,
 *     thumbnail_metadata_used:bool,display_masters_only?:bool,
 *     snapshot?:StorageSnapshot}
 * @phpstan-type StorageJobState array{ok:bool,job_id?:string,status:string,
 *     total?:int,processed?:int,percent?:float,message:string,
 *     snapshot?:StorageSnapshot|null}
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

namespace Gallery\Services;

require_once __DIR__ . '/../runtime/storage_observer.php';

use function Gallery\Core\storage_observer_call;

use function Gallery\Models\admin_storage_statistics_model_fingerprint_rows;
use function Gallery\Models\admin_storage_statistics_model_image_rows;
use function Gallery\Models\admin_storage_statistics_model_image_rows_after_id;
use function Gallery\Models\admin_storage_statistics_model_thumbnail_summary_rows;
use Throwable;

/**
 * Version snapshots so old photo-only totals cannot claim attachment coverage.
 * Type: string. Units: app_settings key. Scope: cached Admin media accounting.
 * Consumers: statistics cache readers, writers and explicit cache invalidation.
 * Rationale: v3 adds PDF/music measurements; preserve old v2 derived bytes and
 *     require a new manual snapshot without rewriting original media.
 */
const ADMIN_STORAGE_STATISTICS_CACHE_KEY = 'admin_storage_statistics_cache_v3';
/**
 * Version resumable jobs to require durable attachment-import progress.
 * Type: string. Units: app_settings key. Scope: one manual statistics job.
 * Consumers: statistics job readers, writers and explicit invalidation.
 * Rationale: v3 imports missing accounting categories only; a v2 physical scan
 *     cannot safely resume with the new database-only repeat-update semantics.
 */
const ADMIN_STORAGE_STATISTICS_JOB_KEY = 'admin_storage_statistics_job_v3';
/**
 * Keep ordinary derivative work responsive without changing its default.
 * Type: int. Units: images per request. Scope: manual statistics photo phase.
 * Consumers: process_job default and the controller's absent-input fallback.
 * Rationale: The established 20-row batch balances progress and shared-host I/O.
 */
const ADMIN_STORAGE_STATISTICS_DEFAULT_BATCH_SIZE = 20;
/**
 * Retain the existing ceiling for administrator-selected photo batch sizes.
 * Type: int. Units: images per request. Scope: manual statistics photo phase.
 * Consumers: controller/service batch normalization and image keyset reads.
 * Rationale: The unchanged 80-row ceiling bounds derivative probes; gallery
 *     attachment work uses its separate smaller ceiling.
 */
const ADMIN_STORAGE_STATISTICS_MAX_BATCH_SIZE = 80;

/**
 * Bound attachment metadata/stat work independently of photo-derivative batches.
 * Type: int. Units: physical galleries per request. Scope: manual storage jobs.
 * Consumers: admin_storage_statistics_process_job and synchronous build.
 * Rationale: Two bounded indexes per gallery keep shared-host requests short;
 *     the existing image batch size does not bound files inside a gallery.
 */
const ADMIN_STORAGE_STATISTICS_GALLERY_BATCH_SIZE = 2;

// Shared constants and row shapes stay here; only this entry loads its parts.
require_once __DIR__ . '/admin_storage_statistics/jobs.php';

/**
 * Return detailed storage statistics for dashboard rendering.
 *
 * Source-file sizes come from the images table. Generated thumbnail and DNG
 * display-master sizes come from bounded filesystem checks against expected
 * derivative paths only, so random cache files are not counted as photos.
 *
 * @param bool $forceRefresh Force refresh value.
 * @return array<string mixed>.
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
 * @param bool $allowStale Allow stale flag.
 * @return array<string mixed>|null.
 */
function admin_storage_statistics_cached_snapshot(bool $allowStale = true): ?array
{
    if (!function_exists('Gallery\\Services\\app_setting')) {
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
 * Combine database summaries and the advisory attachment mutation revision.
 *
 * @return string SHA-256 fingerprint, or empty when database summaries fail.
 */
function admin_storage_statistics_fingerprint(): string
{
    try {
        [$row, $galleryRow] = admin_storage_statistics_model_fingerprint_rows();
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
        (string) app_setting('gallery_attachment_storage_revision', ''),
    ]));
}

/**
 * Read cached storage statistics when they match the current fingerprint.
 *
 * @param string $fingerprint Fingerprint value.
 * @return array<string mixed>|null.
 */
function admin_storage_statistics_cache_read(string $fingerprint): ?array
{
    if ($fingerprint === '' || !function_exists('Gallery\\Services\\app_setting')) {
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
 * @param array $statistics Statistics value.
 */
function admin_storage_statistics_cache_write(array $statistics): void
{
    if (!function_exists('Gallery\\Services\\set_app_setting')) {
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
 * Clear cached storage statistics after generated media files change.
 */
function admin_storage_statistics_cache_clear(): void
{
    if (!function_exists('Gallery\\Services\\delete_app_settings')) {
        return;
    }
    delete_app_settings([ADMIN_STORAGE_STATISTICS_CACHE_KEY, ADMIN_STORAGE_STATISTICS_JOB_KEY]);
}

/**
 * Return image rows required by source and generated-media statistics.
 *
 * @return list<StorageStatisticsImageRow> Indexed photo owners and source sizes.
 * @throws Throwable When the database cannot supply the required source rows.
 */
function admin_storage_statistics_image_rows(): array
{
    $hasDerivativeVersion = function_exists('Gallery\Services\db_column_exists')
        && db_column_exists('images', 'thumbnail_derivative_version');
    return admin_storage_statistics_model_image_rows($hasDerivativeVersion);
}

/**
 * Return a generated-media statistics batch after one image id.
 *
 * @param int $lastImageId Last image id identifier.
 * @param int $limit Maximum number of items.
 * @param bool $displayMastersOnly Restrict to DNG rows needing disk measurement;
 *     false retains the complete fallback and legacy-job behavior.
 * @return list<StorageStatisticsImageRow> Bounded rows, empty only on actual exhaustion.
 * @throws Throwable When the database cannot supply the required derivative batch.
 */
function admin_storage_statistics_image_rows_after_id(
    int $lastImageId,
    int $limit,
    bool $displayMastersOnly = false
): array {
    $lastImageId = max(0, $lastImageId);
    $limit = max(1, min(ADMIN_STORAGE_STATISTICS_MAX_BATCH_SIZE, $limit));
    $hasDerivativeVersion = function_exists('Gallery\Services\db_column_exists')
        && db_column_exists('images', 'thumbnail_derivative_version');
    return admin_storage_statistics_model_image_rows_after_id(
        $lastImageId, $limit, $hasDerivativeVersion, $displayMastersOnly
    );
}

/**
 * Return the SQL expression for the thumbnail derivative version image column.
 *
 * @return string SQL expression.
 */
function admin_storage_statistics_image_derivative_version_select(): string
{
    if (function_exists('Gallery\Services\db_column_exists') && db_column_exists('images', 'thumbnail_derivative_version')) {
        return 'COALESCE(i.thumbnail_derivative_version, 1)';
    }
    return '1';
}

/**
 * Aggregate source-photo statistics from image rows.
 *
 * @param array $rows Rows to process.
 * @return array<string mixed>.
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
 * @param array $summary Summary value.
 * @param array $row Row data.
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
 * @param array $source Source value.
 * @return array<string mixed>.
 */
function admin_storage_statistics_compact_source_summary(array $source): array
{
    $knownSourceSizeCount = (int) ($source['known_source_size_count'] ?? 0);
    $originalBytes = (int) ($source['original_bytes'] ?? 0);
    $averageOriginalBytes = $knownSourceSizeCount > 0 ? (int) floor($originalBytes / $knownSourceSizeCount) : 0;

    return [
        'image_count' => (int) ($source['image_count'] ?? 0),
        'known_source_size_count' => $knownSourceSizeCount,
        'unknown_source_size_count' => (int) ($source['unknown_source_size_count'] ?? 0),
        'original_bytes' => $originalBytes,
        'average_original_bytes' => $averageOriginalBytes,
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
 * @param array $rows Rows to process.
 * @return array<string mixed>.
 */
function admin_storage_statistics_generated_media_summary(array $rows): array
{
    $summary = admin_storage_statistics_initial_generated_summary();
    $thumbnailMetadataUsed = admin_storage_statistics_thumbnail_metadata_available();
    foreach ($rows as $row) {
        if ($thumbnailMetadataUsed) {
            admin_storage_statistics_accumulate_display_master_media_row($summary, $row);
        } else {
            admin_storage_statistics_accumulate_generated_media_row($summary, $row);
        }
    }
    return $summary;
}

/**
 * Return the initial generated-media summary for a statistics run.
 *
 * Valid thumbnail variants are read from durable thumbnail metadata when that
 * schema is available. This keeps storage statistics aligned with the current
 * database-driven public renderer and avoids expensive disk probes for every
 * thumbnail during normal Admin checks.
 *
 * @return StorageGeneratedSummary Empty derivative totals or durable thumbnail totals.
 */
function admin_storage_statistics_initial_generated_summary(): array
{
    $summary = admin_storage_statistics_empty_generated_summary();
    if (!admin_storage_statistics_thumbnail_metadata_available()) {
        return $summary;
    }

    return admin_storage_statistics_merge_generated_summaries($summary,
        storage_observer_call('db.thumbnail_metadata_summary',
            static fn(): array => admin_storage_statistics_thumbnail_metadata_summary()));
}

/**
 * Return true when valid thumbnail metadata can provide thumbnail byte totals.
 *
 * @return bool True when thumbnail metadata is available for statistics.
 */
function admin_storage_statistics_thumbnail_metadata_available(): bool
{
    if (!function_exists('Gallery\\Services\\thumbnail_metadata_schema_ready') || !thumbnail_metadata_schema_ready()) {
        return false;
    }
    if (!function_exists('Gallery\\Services\\db_table_exists') || !db_table_exists('image_thumbnail_variants')) {
        return false;
    }
    if (function_exists('Gallery\\Services\\db_column_exists') && !db_column_exists('image_thumbnail_variants', 'file_size')) {
        return false;
    }
    return true;
}

/**
 * Aggregate generated thumbnail bytes from durable thumbnail metadata.
 *
 * @return StorageGeneratedSummary Durable configured thumbnail totals, or empty
 *     totals when the optional schema/configured sizes are unavailable.
 * @throws Throwable When the available schema's required aggregate fails.
 */
function admin_storage_statistics_thumbnail_metadata_summary(): array
{
    $summary = admin_storage_statistics_empty_generated_summary();
    if (!admin_storage_statistics_thumbnail_metadata_available()) {
        return $summary;
    }

    $sizes = array_values(array_unique(array_filter(array_map('intval', thumbnail_sizes()), static fn (int $size): bool => $size > 0)));
    if ($sizes === []) {
        return $summary;
    }
    $matchDerivativeVersion = function_exists('Gallery\Services\db_column_exists')
        && db_column_exists('image_thumbnail_variants', 'derivative_version')
        && db_column_exists('images', 'thumbnail_derivative_version');
    // Once this schema is available its aggregate is required accounting.
    // A failed query cannot safely authorize skipping thumbnail measurements.
    $rows = admin_storage_statistics_model_thumbnail_summary_rows(
        $sizes, $matchDerivativeVersion
    );

    foreach ($rows as $row) {
        $format = (string) ($row['format'] ?? '');
        if (!in_array($format, ['jpg', 'webp'], true)) {
            continue;
        }
        $count = max(0, (int) ($row['variant_count'] ?? 0));
        $bytes = max(0, (int) ($row['total_bytes'] ?? 0));
        if ($count <= 0 && $bytes <= 0) {
            continue;
        }
        $summary['thumbnail_bytes'] = (int) $summary['thumbnail_bytes'] + $bytes;
        $summary['thumbnail_count'] = (int) $summary['thumbnail_count'] + $count;
        $generatedTypeGroups = is_array($summary['generated_type_groups'] ?? null) ? $summary['generated_type_groups'] : [];
        admin_storage_statistics_add_group_value($generatedTypeGroups, 'thumbnail-' . $format, strtoupper($format) . ' thumbnails', $count, $bytes, [
            'kind' => 'thumbnail',
            'format' => $format,
            'label_key' => 'admin.storage.generated_' . $format . '_thumbnails',
        ]);
        $summary['generated_type_groups'] = $generatedTypeGroups;
    }
    return $summary;
}

/**
 * Merge two generated-media accumulators.
 *
 * @param array $left Left summary.
 * @param array $right Right summary.
 * @return array<string mixed>.
 */
function admin_storage_statistics_merge_generated_summaries(array $left, array $right): array
{
    $left = admin_storage_statistics_normalize_generated_summary($left);
    $right = admin_storage_statistics_normalize_generated_summary($right);
    $merged = $left;
    foreach (['thumbnail_bytes', 'thumbnail_count', 'display_master_bytes', 'display_master_count', 'scan_errors'] as $key) {
        $merged[$key] = (int) ($left[$key] ?? 0) + (int) ($right[$key] ?? 0);
    }

    $groups = is_array($left['generated_type_groups'] ?? null) ? $left['generated_type_groups'] : [];
    $metadataKeys = array_flip(['key', 'label', 'count', 'bytes', 'percent']);
    foreach (is_array($right['generated_type_groups'] ?? null) ? $right['generated_type_groups'] : [] as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        admin_storage_statistics_add_group_value($groups, (string) ($row['key'] ?? $key), (string) ($row['label'] ?? $key), (int) ($row['count'] ?? 0), (int) ($row['bytes'] ?? 0), array_diff_key($row, $metadataKeys));
    }
    $merged['generated_type_groups'] = $groups;
    $merged['generated_bytes'] = (int) $merged['thumbnail_bytes'] + (int) $merged['display_master_bytes'];
    return $merged;
}

/**
 * Return an empty generated-media statistics accumulator.
 *
 * @return array<string mixed>.
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
 * @param array $summary Summary value.
 * @return array<string mixed>.
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
 * @param array $summary Summary value.
 * @param array $row Row data.
 */
function admin_storage_statistics_accumulate_generated_media_row(array &$summary, array $row): void
{
    admin_storage_statistics_accumulate_thumbnail_filesystem_row($summary, $row);
    admin_storage_statistics_accumulate_display_master_media_row($summary, $row);
}

/**
 * Add generated thumbnail files from the filesystem for one image row.
 *
 * @param array $summary Summary value.
 * @param array $row Row data.
 */
function admin_storage_statistics_accumulate_thumbnail_filesystem_row(array &$summary, array $row): void
{
    $summary = admin_storage_statistics_normalize_generated_summary($summary);
    $sizes = function_exists('Gallery\\Services\\thumbnail_sizes') ? thumbnail_sizes() : [300, 600, 800, 960, 1280, 1600];
    $formats = ['jpg', 'webp'];
    $gallery = admin_storage_statistics_gallery_shape($row);
    $image = admin_storage_statistics_image_shape($row);

    foreach ($sizes as $size) {
        foreach ($formats as $format) {
            try {
                $path = function_exists('Gallery\\Services\\thumbnail_abs_path') ? thumbnail_abs_path($image, $gallery, (int) $size, $format) : '';
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

    $summary['generated_bytes'] = (int) $summary['thumbnail_bytes'] + (int) $summary['display_master_bytes'];
}

/**
 * Add DNG display-master bytes from the filesystem for one image row.
 *
 * @param array $summary Summary value.
 * @param array $row Row data.
 */
function admin_storage_statistics_accumulate_display_master_media_row(array &$summary, array $row): void
{
    $summary = admin_storage_statistics_normalize_generated_summary($summary);
    $gallery = admin_storage_statistics_gallery_shape($row);
    $image = admin_storage_statistics_image_shape($row);

    if (function_exists('Gallery\\Services\\image_uses_dng_display_derivatives') && function_exists('Gallery\\Services\\dng_display_master_abs_path') && image_uses_dng_display_derivatives($image)) {
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
 * Return the gallery shape needed by generated-media path helpers.
 *
 * @param array $row Row data.
 * @return array<string mixed>.
 */
function admin_storage_statistics_gallery_shape(array $row): array
{
    return [
        'id' => (int) ($row['gallery_id'] ?? $row['image_gallery_id'] ?? 0),
        'folder_path' => (string) ($row['gallery_folder_path'] ?? ''),
        'title' => (string) ($row['gallery_title'] ?? ''),
    ];
}

/**
 * Return the image shape needed by generated-media path helpers.
 *
 * @param array $row Row data.
 * @return array<string mixed>.
 */
function admin_storage_statistics_image_shape(array $row): array
{
    return [
        'id' => (int) ($row['image_id'] ?? 0),
        'gallery_id' => (int) ($row['image_gallery_id'] ?? $row['gallery_id'] ?? 0),
        'relative_path' => (string) ($row['relative_path'] ?? ''),
        'filename' => (string) ($row['filename'] ?? ''),
        'mime_type' => (string) ($row['mime_type'] ?? ''),
        'thumbnail_derivative_version' => max(1, (int) ($row['thumbnail_derivative_version'] ?? 1)),
    ];
}

/**
 * Return a compact public state for a manual storage statistics job.
 *
 * @param array $job Job value.
 * @param ?array $snapshot Snapshot value.
 * @param string $message Message value.
 * @return array<string mixed>.
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
 * @return array<string mixed>|null.
 */
function admin_storage_statistics_job_read(): ?array
{
    if (!function_exists('Gallery\\Services\\app_setting')) {
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
 * @param array $job Job value.
 */
function admin_storage_statistics_job_write(array $job): void
{
    if (!function_exists('Gallery\\Services\\set_app_setting')) {
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
 *
 * @return string Text result for the caller.
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
 *
 * @param string $path Filesystem path.
 * @return int Existing nonnegative bytes, or zero for empty, missing or unreadable paths.
 */
function admin_storage_statistics_existing_file_size(string $path): int
{
    if ($path === '' || !storage_observer_call('fs.derivative.is_file', static fn(): bool => is_file($path))) {
        return 0;
    }

    $bytes = storage_observer_call('fs.derivative.filesize', static fn(): int|false => @filesize($path));
    return $bytes === false ? 0 : max(0, (int) $bytes);
}

/**
 * Normalize one file extension for grouping.
 *
 * @param string $path Filesystem path.
 * @return string Text result for the caller.
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
 * @return array<string array<string, mixed>>.
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
 * @return array<int string>.
 */
function admin_storage_statistics_size_bucket_order(): array
{
    return ['unknown', 'under_1mb', '1_3mb', '3_8mb', '8_20mb', '20_50mb', 'over_50mb'];
}

/**
 * Return the source-size bucket key for one byte count.
 *
 * @param int $bytes Bytes value.
 * @return string Text result for the caller.
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
 *
 * @param string $bucketKey Bucket key value.
 * @return string Text result for the caller.
 */
function admin_storage_statistics_size_bucket_translation_key(string $bucketKey): string
{
    return 'admin.storage.size_bucket_' . $bucketKey;
}

/**
 * Return an English fallback label for one source-size bucket.
 *
 * @param string $bucketKey Bucket key value.
 * @return string Text result for the caller.
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
 * @param array $groups Groups value.
 * @param string $key Lookup key.
 * @param string $label Label value.
 * @param int $count Count value.
 * @param int $bytes Bytes value.
 * @param array $metadata Metadata value.
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
 * @param array $groups Groups value.
 * @param string $valueKey Value key value.
 * @param int $limit Maximum number of items.
 * @return array<int array<string, mixed>>.
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
