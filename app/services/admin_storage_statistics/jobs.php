<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/admin_storage_statistics/jobs.php
 * Module Type: Service Part
 * Purpose: Run explicit resumable photo and attachment accounting phases.
 * Responsibilities:
 *   - Keep physical-gallery cursors independent of photo membership
 *   - Measure bounded attachment batches through their existing runtime owner
 *   - Assemble additive cached snapshots without changing photograph totals
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 * Notes: Loaded only by admin_storage_statistics.php; shared policy constants
 *     and concrete Storage* row shapes belong to that entry point.
 */
declare(strict_types=1);

namespace Gallery\Services;

use function Gallery\Models\admin_storage_statistics_model_gallery_count;
use function Gallery\Models\admin_storage_statistics_model_gallery_rows_after_id;

/**
 * Start a manual, browser-driven storage statistics job.
 *
 * Source-photo statistics are calculated once from database rows. Generated
 * images and registered galleries then use separate bounded keyset phases;
 * galleries with no photos still contribute attachment bytes.
 *
 * @return StorageJobState Running progress, or the completed empty snapshot.
 */
function admin_storage_statistics_start_job(): array
{
    $fingerprint = admin_storage_statistics_fingerprint();
    $rows = admin_storage_statistics_image_rows();
    $source = admin_storage_statistics_source_summary($rows);
    $galleryCount = admin_storage_statistics_model_gallery_count();

    $job = [
        'job_id' => admin_storage_statistics_job_id(),
        'status' => 'running',
        'fingerprint' => $fingerprint,
        'started_at' => time(),
        'updated_at' => time(),
        'total' => count($rows) + $galleryCount,
        'processed' => 0,
        'last_image_id' => 0,
        'image_total' => count($rows),
        'image_processed' => 0,
        'last_gallery_id' => 0,
        'gallery_total' => $galleryCount,
        'gallery_processed' => 0,
        'source' => admin_storage_statistics_compact_source_summary($source),
        'generated' => admin_storage_statistics_initial_generated_summary(),
        'attachments' => admin_storage_statistics_empty_attachments(),
        'thumbnail_metadata_used' => admin_storage_statistics_thumbnail_metadata_available(),
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
 * @param int $batchSize Photo records per step, clamped to the existing limits.
 *     Attachment steps additionally honor the smaller gallery batch ceiling.
 * @return StorageJobState Running/completed progress, stale or missing refusal.
 */
function admin_storage_statistics_process_job(
    int $batchSize = ADMIN_STORAGE_STATISTICS_DEFAULT_BATCH_SIZE
): array {
    $job = admin_storage_statistics_job_read();
    if ($job === null || (string) ($job['status'] ?? '') !== 'running') {
        return [
            'ok' => false,
            'status' => 'missing',
            'message' => 'No running storage statistics job was found.',
        ];
    }

    $currentFingerprint = admin_storage_statistics_fingerprint();
    if ($currentFingerprint !== ''
        && (string) ($job['fingerprint'] ?? '') !== ''
        && !hash_equals((string) $job['fingerprint'], $currentFingerprint)) {
        $job['status'] = 'stale';
        $job['updated_at'] = time();
        admin_storage_statistics_job_write($job);
        return admin_storage_statistics_job_public_state($job, null,
            'Gallery data changed while statistics were being calculated.'
            . ' Start a new update.');
    }

    $batchSize = max(1, min(ADMIN_STORAGE_STATISTICS_MAX_BATCH_SIZE, $batchSize));
    $lastImageId = max(0, (int) ($job['last_image_id'] ?? 0));
    $rows = $job['image_processed'] < $job['image_total']
        ? admin_storage_statistics_image_rows_after_id($lastImageId, $batchSize)
        : [];
    $generated = admin_storage_statistics_normalize_generated_summary(
        is_array($job['generated'] ?? null) ? $job['generated'] : []
    );
    $thumbnailMetadataUsed = !empty($job['thumbnail_metadata_used']);

    foreach ($rows as $row) {
        if ($thumbnailMetadataUsed) {
            admin_storage_statistics_accumulate_display_master_media_row($generated, $row);
        } else {
            admin_storage_statistics_accumulate_generated_media_row($generated, $row);
        }
        $lastImageId = max($lastImageId, (int) ($row['image_id'] ?? 0));
    }

    $job['image_processed'] = $rows === [] ? $job['image_total']
        : min($job['image_total'], $job['image_processed'] + count($rows));
    if ($job['image_processed'] >= $job['image_total']) {
        $attachments = $job['attachments'];
        $batch = admin_storage_statistics_attachment_batch(
            $attachments, (int) $job['last_gallery_id'],
            min($batchSize, ADMIN_STORAGE_STATISTICS_GALLERY_BATCH_SIZE)
        );
        $job['attachments'] = $attachments;
        $job['last_gallery_id'] = $batch['last_id'];
        $job['gallery_processed'] = $batch['processed'] === 0
            ? $job['gallery_total']
            : min($job['gallery_total'],
                $job['gallery_processed'] + $batch['processed']);
    }
    $processed = $job['image_processed'] + $job['gallery_processed'];
    $job['generated'] = $generated;
    $job['processed'] = $processed;
    $job['last_image_id'] = $lastImageId;
    $job['updated_at'] = time();

    if ($processed >= (int) $job['total']) {
        return admin_storage_statistics_finish_job($job);
    }

    admin_storage_statistics_job_write($job);
    return admin_storage_statistics_job_public_state($job);
}

/**
 * Build uncached statistics from database rows and expected generated media paths.
 *
 * @param string $fingerprint Database/attachment revision frozen for this scan.
 * @return StorageSnapshot Source/derivative/attachment bytes from explicit work.
 */
function admin_storage_statistics_build(string $fingerprint): array
{
    $rows = admin_storage_statistics_image_rows();
    $source = admin_storage_statistics_source_summary($rows);
    $generated = admin_storage_statistics_generated_media_summary($rows);
    $attachments = admin_storage_statistics_empty_attachments();
    $lastGalleryId = 0;
    do {
        $batch = admin_storage_statistics_attachment_batch(
            $attachments, $lastGalleryId,
            ADMIN_STORAGE_STATISTICS_GALLERY_BATCH_SIZE
        );
        $lastGalleryId = $batch['last_id'];
    } while ($batch['processed'] > 0);

    return admin_storage_statistics_snapshot_from_summaries(
        $fingerprint, admin_storage_statistics_compact_source_summary($source),
        $generated, $attachments
    );
}

/**
 * Initialize independent PDF/music original accounting.
 * @return StorageAttachmentSummary Zero known originals and warnings.
 */
function admin_storage_statistics_empty_attachments(): array
{
    return ['pdf_count' => 0, 'pdf_bytes' => 0,
        'music_count' => 0, 'music_bytes' => 0, 'errors' => 0];
}

/**
 * Accumulate one bounded gallery batch after loading its attachment owner.
 * @param StorageAttachmentSummary $summary Mutable known byte/count totals.
 * @param int $lastGalleryId Exclusive physical-gallery cursor, initially zero.
 * @param int $limit Requested count, clamped to the gallery observation ceiling.
 * @return array{last_id:int,processed:int} Durable next cursor and observed rows.
 */
function admin_storage_statistics_attachment_batch(
    array &$summary,
    int $lastGalleryId,
    int $limit
): array {
    // Direct CLI consumers explicitly include the attachment entry points.
    // HTTP work loads that same reviewed owner only for an explicit scan.
    if (!function_exists(__NAMESPACE__ . '\\gallery_attachment_storage_summary')) {
        \Gallery\Core\cms_runtime_kernel()->load('gallery-pdf-documents');
    }
    $rows = admin_storage_statistics_model_gallery_rows_after_id(
        $lastGalleryId,
        max(1, min(ADMIN_STORAGE_STATISTICS_GALLERY_BATCH_SIZE, $limit))
    );
    foreach ($rows as $row) {
        $gallery = ['id' => (int) $row['id'],
            'folder_path' => $row['folder_path']];
        $measured = gallery_attachment_storage_summary($gallery);
        foreach ($measured as $key => $value) $summary[$key] += $value;
        $lastGalleryId = $gallery['id'];
    }
    return ['last_id' => $lastGalleryId, 'processed' => count($rows)];
}

/**
 * Combine photograph, derivative and attachment accounting into one snapshot.
 *
 * @param string $fingerprint Frozen database/attachment accounting revision.
 * @param StorageSourceSummary $source Compact indexed-photo totals/charts.
 * @param StorageGeneratedSummary $generated Measured photograph derivatives.
 * @param StorageAttachmentSummary|null $attachments Measured originals;
 *     omitted by legacy pure consumers to retain photograph-only semantics.
 * @return StorageSnapshot Separate categories and additive media total.
 */
function admin_storage_statistics_snapshot_from_summaries(
    string $fingerprint,
    array $source,
    array $generated,
    ?array $attachments = null
): array {
    $generated = admin_storage_statistics_normalize_generated_summary($generated);
    $originalBytes = (int) ($source['original_bytes'] ?? 0);
    $generatedBytes = (int) ($generated['thumbnail_bytes'] ?? 0)
        + (int) ($generated['display_master_bytes'] ?? 0);
    $totalPictureBytes = $originalBytes + $generatedBytes;
    $attachments ??= admin_storage_statistics_empty_attachments();
    $attachmentBytes = $attachments['pdf_bytes'] + $attachments['music_bytes'];

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
        'pdf_count' => $attachments['pdf_count'],
        'pdf_bytes' => $attachments['pdf_bytes'],
        'music_count' => $attachments['music_count'],
        'music_bytes' => $attachments['music_bytes'],
        'attachment_scan_errors' => $attachments['errors'],
        'attachment_bytes' => $attachmentBytes,
        'total_media_bytes' => $totalPictureBytes + $attachmentBytes,
        'generated_to_original_percent' => $originalBytes > 0
            ? round(($generatedBytes / $originalBytes) * 100, 1) : 0.0,
        'thumbnail_scan_errors' => (int) ($generated['scan_errors'] ?? 0),
        'type_rows' => is_array($source['type_rows'] ?? null) ? $source['type_rows'] : [],
        'size_bucket_rows' => is_array($source['size_bucket_rows'] ?? null)
            ? $source['size_bucket_rows'] : [],
        'largest_gallery_rows' => is_array($source['largest_gallery_rows'] ?? null)
            ? $source['largest_gallery_rows'] : [],
        'generated_type_rows' => admin_storage_statistics_finalize_group_rows(
            is_array($generated['generated_type_groups'] ?? null)
                ? $generated['generated_type_groups'] : [], 'bytes'
        ),
    ];
}

/**
 * Finish a manual job and store its statistics snapshot.
 *
 * @param StorageJob $job Completed cursors and measured category accumulators.
 * @return StorageJobState Completed progress and its persisted snapshot.
 */
function admin_storage_statistics_finish_job(array $job): array
{
    $job['status'] = 'complete';
    $job['processed'] = (int) ($job['total'] ?? $job['processed'] ?? 0);
    $job['updated_at'] = time();
    $snapshot = admin_storage_statistics_snapshot_from_summaries(
        $job['fingerprint'], $job['source'], $job['generated'],
        $job['attachments']
    );
    admin_storage_statistics_cache_write($snapshot);
    $job['snapshot'] = $snapshot;
    admin_storage_statistics_job_write($job);
    return admin_storage_statistics_job_public_state($job, $snapshot);
}
