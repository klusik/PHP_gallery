<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/ai_image_analysis.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides server-side queue coordination for client-side image analysis.
 *
 * Responsibilities:
 *   - Keep the gallery database authoritative for AI-analysis work allocation
 *   - Claim exactly one image job for one worker using short database leases
 *   - Store generated internal metadata separately from human descriptions
 *   - Keep expensive image analysis on trusted client machines, not shared hosting
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
 *   2026-05-28
 */

declare(strict_types=1);

namespace Gallery\Services;

use finfo;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;
use function Gallery\Core\db;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;

/**
 * AI image-analysis queue service model.
 *
 * The server only allocates work, validates leases, and stores results. The
 * Windows companion app downloads one assigned image, analyzes it locally, and
 * reports the result through this queue. This prevents two client machines from
 * selecting the same image independently.
 */

const AI_IMAGE_ANALYSIS_DEFAULT_LEASE_SECONDS = 600;
const AI_IMAGE_ANALYSIS_MIN_LEASE_SECONDS = 60;
const AI_IMAGE_ANALYSIS_MAX_LEASE_SECONDS = 3600;
const AI_IMAGE_ANALYSIS_MAX_ATTEMPTS = 5;
const AI_IMAGE_ANALYSIS_ENQUEUE_BATCH_SIZE = 30;
const AI_IMAGE_ANALYSIS_SEARCH_TEXT_LIMIT = 60000;
const AI_IMAGE_ANALYSIS_ERROR_LIMIT = 2000;

/**
 * Return whether the AI image-analysis queue schema is available.
 *
 * @return bool True when the condition matches.
 */
function ai_image_analysis_schema_ready(): bool
{
    return presentation_schema_render_available(presentation_ai_image_analysis_schema_status(), 'ai_image_analysis_render');
}

/**
 * Verify AI-analysis storage before an operation that mutates queue or metadata rows.
 *
 * Confirmed absence remains a legacy disabled state. Unknown inspection state is
 * never converted into "no jobs", because doing so would make a database outage
 * indistinguishable from an idle worker queue.
 *
 * @param string $operation Stable write-operation identifier.
 * @return bool True when the complete AI-analysis schema is verified available.
 */
function ai_image_analysis_write_schema_ready(string $operation): bool
{
    $schemaStatus = presentation_ai_image_analysis_schema_status();
    presentation_schema_assert_known($schemaStatus, $operation, 'AI image-analysis storage could not be verified. No queue or metadata row was changed.');
    return schema_inspection_is_available($schemaStatus);
}

/**
 * Normalize a worker-supplied model name or version.
 *
 * @param string $value Value to process.
 * @param string $fallback Fallback value.
 * @param int $maxLength Max length value.
 * @return string Text result for the caller.
 */
function ai_image_analysis_normalize_label(string $value, string $fallback, int $maxLength = 120): string
{
    // $normalized stores a compact printable value for queue comparisons.
    $normalized = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($normalized === '') {
        $normalized = $fallback;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($normalized, 0, $maxLength);
    }
    return substr($normalized, 0, $maxLength);
}

/**
 * Normalize a worker id used for lease ownership diagnostics.
 *
 * @param string $workerId Worker id identifier.
 * @return string Text result for the caller.
 */
function ai_image_analysis_normalize_worker_id(string $workerId): string
{
    // $workerId is diagnostic. Authorization still comes only from the API key.
    $workerId = trim(preg_replace('/[^A-Za-z0-9_.:@-]/', '_', $workerId) ?? '');
    if ($workerId === '') {
        $workerId = 'unknown-worker';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($workerId, 0, 190);
    }
    return substr($workerId, 0, 190);
}

/**
 * Clamp a requested lease length into the safe supported range.
 *
 * @param int $leaseSeconds Lease seconds value.
 * @return int Integer result for the caller.
 */
function ai_image_analysis_normalize_lease_seconds(int $leaseSeconds): int
{
    if ($leaseSeconds <= 0) {
        $leaseSeconds = AI_IMAGE_ANALYSIS_DEFAULT_LEASE_SECONDS;
    }
    return max(AI_IMAGE_ANALYSIS_MIN_LEASE_SECONDS, min(AI_IMAGE_ANALYSIS_MAX_LEASE_SECONDS, $leaseSeconds));
}

/**
 * Return a SQL datetime string offset from the current PHP process time.
 *
 * @param int $seconds Seconds value.
 * @return string Text result for the caller.
 */
function ai_image_analysis_time_offset(int $seconds): string
{
    return date('Y-m-d H:i:s', time() + $seconds);
}

/**
 * Return a compact hash for a raw worker claim token.
 *
 * @param string $claimToken Claim token value.
 * @return string Text result for the caller.
 */
function ai_image_analysis_claim_token_hash(string $claimToken): string
{
    return hash('sha256', trim($claimToken));
}

/**
 * Insert a small number of missing jobs for one gallery and model.
 *
 * Jobs are created lazily when workers poll. This keeps normal gallery requests
 * cheap and avoids a dedicated scheduler on shared hosting. Source file fields
 * are copied into each job so later image or model changes naturally create new
 * work without modifying older result rows.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $modelName Model name value.
 * @param string $modelVersion Model version value.
 * @param int $limit Maximum number of items.
 * @return int Integer result for the caller.
 */
function ai_image_analysis_enqueue_missing_jobs(int $galleryId, string $modelName, string $modelVersion, int $limit = AI_IMAGE_ANALYSIS_ENQUEUE_BATCH_SIZE): int
{
    if (!ai_image_analysis_write_schema_ready('ai_analysis_enqueue')) {
        return 0;
    }

    $limit = max(1, min(100, $limit));
    $now = now_sql();
    $sql = "INSERT IGNORE INTO image_ai_analysis_jobs (
            gallery_id,
            image_id,
            job_key,
            model_name,
            model_version,
            source_checksum_sha256,
            source_file_size,
            source_modified_at,
            state,
            attempt_count,
            available_at,
            created_at,
            updated_at
        )
        SELECT
            i.gallery_id,
            i.id,
            SHA2(CONCAT_WS('|', i.id, ?, ?, COALESCE(i.checksum_sha256, ''), COALESCE(i.file_size, ''), COALESCE(i.modified_at, '')), 256),
            ?,
            ?,
            i.checksum_sha256,
            i.file_size,
            i.modified_at,
            'queued',
            0,
            ?,
            ?,
            ?
        FROM images i
        LEFT JOIN image_ai_metadata m
               ON m.image_id = i.id
              AND m.model_name = ?
              AND m.model_version = ?
              AND (m.source_checksum_sha256 <=> i.checksum_sha256)
              AND (m.source_file_size <=> i.file_size)
              AND (m.source_modified_at <=> i.modified_at)
        WHERE i.gallery_id = ?
          AND m.id IS NULL
          AND NOT EXISTS (
              SELECT 1
              FROM image_ai_analysis_jobs j
              WHERE j.image_id = i.id
                AND j.model_name = ?
                AND j.model_version = ?
                AND (j.source_checksum_sha256 <=> i.checksum_sha256)
                AND (j.source_file_size <=> i.file_size)
                AND (j.source_modified_at <=> i.modified_at)
                AND j.state IN ('queued', 'claimed', 'failed')
          )
        ORDER BY i.updated_at ASC, i.id ASC
        LIMIT " . $limit;

    $stmt = db()->prepare($sql);
    $stmt->execute([
        $modelName,
        $modelVersion,
        $modelName,
        $modelVersion,
        $now,
        $now,
        $now,
        $modelName,
        $modelVersion,
        $galleryId,
        $modelName,
        $modelVersion,
    ]);

    return max(0, $stmt->rowCount());
}

/**
 * Return expired claimed jobs to the queued state for one gallery.
 *
 * @param int $galleryId Gallery identifier.
 * @return int Integer result for the caller.
 */
function ai_image_analysis_release_expired_claims(int $galleryId): int
{
    if (!ai_image_analysis_write_schema_ready('ai_analysis_release_expired')) {
        return 0;
    }

    $now = now_sql();
    $stmt = db()->prepare("UPDATE image_ai_analysis_jobs
        SET state = 'queued',
            claim_owner = NULL,
            claim_token_hash = NULL,
            claim_expires_at = NULL,
            progress_message = 'Lease expired before completion.',
            updated_at = ?
        WHERE gallery_id = ?
          AND state = 'claimed'
          AND claim_expires_at IS NOT NULL
          AND claim_expires_at < ?");
    $stmt->execute([$now, $galleryId, $now]);
    return max(0, $stmt->rowCount());
}

/**
 * Claim one queued AI-analysis job for a worker.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $workerId Worker id identifier.
 * @param string $modelName Model name value.
 * @param string $modelVersion Model version value.
 * @param int $leaseSeconds Lease seconds value.
 * @return array<string,mixed>|null Claimed job payload, or null when no work exists.
 */
function ai_image_analysis_claim_next_job(int $galleryId, string $workerId, string $modelName, string $modelVersion, int $leaseSeconds): ?array
{
    if (!ai_image_analysis_write_schema_ready('ai_analysis_claim')) {
        return null;
    }

    $workerId = ai_image_analysis_normalize_worker_id($workerId);
    $modelName = ai_image_analysis_normalize_label($modelName, 'local-image-metadata');
    $modelVersion = ai_image_analysis_normalize_label($modelVersion, '1');
    $leaseSeconds = ai_image_analysis_normalize_lease_seconds($leaseSeconds);

    ai_image_analysis_enqueue_missing_jobs($galleryId, $modelName, $modelVersion);

    $pdo = db();
    $now = now_sql();
    $claimUntil = ai_image_analysis_time_offset($leaseSeconds);
    $claimToken = bin2hex(random_bytes(32));
    $claimTokenHash = ai_image_analysis_claim_token_hash($claimToken);

    $pdo->beginTransaction();
    try {
        ai_image_analysis_release_expired_claims($galleryId);

        // $stmt locks exactly one eligible row while this short transaction claims it.
        $stmt = $pdo->prepare("SELECT j.*
            FROM image_ai_analysis_jobs j
            INNER JOIN images i ON i.id = j.image_id AND i.gallery_id = j.gallery_id
            WHERE j.gallery_id = ?
              AND j.model_name = ?
              AND j.model_version = ?
              AND j.state = 'queued'
              AND (j.available_at IS NULL OR j.available_at <= ?)
            ORDER BY j.attempt_count ASC, j.created_at ASC, j.id ASC
            LIMIT 1
            FOR UPDATE");
        $stmt->execute([$galleryId, $modelName, $modelVersion, $now]);
        $job = $stmt->fetch();
        if (!is_array($job)) {
            $pdo->commit();
            return null;
        }

        $update = $pdo->prepare("UPDATE image_ai_analysis_jobs
            SET state = 'claimed',
                claim_owner = ?,
                claim_token_hash = ?,
                claim_expires_at = ?,
                claimed_at = ?,
                heartbeat_at = ?,
                progress_percent = 0,
                progress_message = 'Claimed by worker.',
                attempt_count = attempt_count + 1,
                updated_at = ?
            WHERE id = ? AND state = 'queued'");
        $update->execute([
            $workerId,
            $claimTokenHash,
            $claimUntil,
            $now,
            $now,
            $now,
            (int) $job['id'],
        ]);

        if ($update->rowCount() < 1) {
            $pdo->rollBack();
            return null;
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $claimedJob = ai_image_analysis_find_job((int) $job['id']);
    if (!$claimedJob) {
        return null;
    }

    return ai_image_analysis_job_payload($claimedJob, $claimToken, $leaseSeconds);
}

/**
 * Fetch one AI-analysis job by id.
 *
 * @param int $jobId Job id identifier.
 * @return ?array Structured result data for the caller.
 */
function ai_image_analysis_find_job(int $jobId): ?array
{
    if (!ai_image_analysis_schema_ready()) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM image_ai_analysis_jobs WHERE id = ?');
    $stmt->execute([$jobId]);
    $job = $stmt->fetch();
    return is_array($job) ? $job : null;
}

/**
 * Build the JSON-safe claim payload returned to the worker.
 *
 * @param array<string,mixed> $job Claimed job row.
 * @param string $claimToken Claim token value.
 * @param int $leaseSeconds Lease seconds value.
 * @return array<string,mixed> Structured result data for the caller.
 */
function ai_image_analysis_job_payload(array $job, string $claimToken, int $leaseSeconds): array
{
    $image = find_image((int) ($job['image_id'] ?? 0));
    $gallery = $image ? find_gallery((int) ($image['gallery_id'] ?? 0), true) : null;
    if (!$image || !$gallery) {
        throw new RuntimeException('Claimed AI-analysis job references a missing image or gallery.');
    }

    return [
        'job_id' => (int) $job['id'],
        'claim_token' => $claimToken,
        'lease_seconds' => $leaseSeconds,
        'lease_expires_at' => (string) ($job['claim_expires_at'] ?? ''),
        'model_name' => (string) ($job['model_name'] ?? ''),
        'model_version' => (string) ($job['model_version'] ?? ''),
        'gallery_id' => (int) $gallery['id'],
        'gallery_title' => (string) ($gallery['title'] ?? ''),
        'image' => [
            'id' => (int) $image['id'],
            'filename' => (string) ($image['filename'] ?? ''),
            'title' => (string) ($image['title'] ?? ''),
            'relative_path' => (string) ($image['relative_path'] ?? ''),
            'mime_type' => (string) ($image['mime_type'] ?? ''),
            'file_size' => (int) ($image['file_size'] ?? 0),
            'modified_at' => (string) ($image['modified_at'] ?? ''),
            'checksum_sha256' => (string) ($image['checksum_sha256'] ?? ''),
            'width' => (int) ($image['width'] ?? 0),
            'height' => (int) ($image['height'] ?? 0),
        ],
    ];
}

/**
 * Validate that one worker still owns a live claim.
 *
 * @param int $galleryId Gallery identifier.
 * @param int $jobId Job id identifier.
 * @param string $claimToken Claim token value.
 * @return array<string,mixed>|null Matching job row, or null when the lease is invalid.
 */
function ai_image_analysis_validate_claim(int $galleryId, int $jobId, string $claimToken): ?array
{
    if (!ai_image_analysis_schema_ready() || $jobId <= 0 || trim($claimToken) === '') {
        return null;
    }

    $now = now_sql();
    $stmt = db()->prepare("SELECT *
        FROM image_ai_analysis_jobs
        WHERE id = ?
          AND gallery_id = ?
          AND claim_token_hash = ?
          AND state = 'claimed'
          AND claim_expires_at IS NOT NULL
          AND claim_expires_at >= ?
        LIMIT 1");
    $stmt->execute([$jobId, $galleryId, ai_image_analysis_claim_token_hash($claimToken), $now]);
    $job = $stmt->fetch();
    return is_array($job) ? $job : null;
}

/**
 * Return whether a completed job with the same claim token already exists.
 *
 * @param int $galleryId Gallery identifier.
 * @param int $jobId Job id identifier.
 * @param string $claimToken Claim token value.
 * @return bool True when the condition matches.
 */
function ai_image_analysis_claim_already_completed(int $galleryId, int $jobId, string $claimToken): bool
{
    if (!ai_image_analysis_schema_ready() || $jobId <= 0 || trim($claimToken) === '') {
        return false;
    }

    $stmt = db()->prepare("SELECT id
        FROM image_ai_analysis_jobs
        WHERE id = ?
          AND gallery_id = ?
          AND claim_token_hash = ?
          AND state = 'succeeded'
        LIMIT 1");
    $stmt->execute([$jobId, $galleryId, ai_image_analysis_claim_token_hash($claimToken)]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Record a heartbeat for a long-running AI-analysis job.
 *
 * @param int $galleryId Gallery identifier.
 * @param int $jobId Job id identifier.
 * @param string $claimToken Claim token value.
 * @param int $leaseSeconds Lease seconds value.
 * @param int $progressPercent Progress percent value.
 * @param string $message Message value.
 * @return bool True when the condition matches.
 */
function ai_image_analysis_record_heartbeat(int $galleryId, int $jobId, string $claimToken, int $leaseSeconds, int $progressPercent, string $message): bool
{
    if (!ai_image_analysis_write_schema_ready('ai_analysis_heartbeat')) {
        return false;
    }
    $job = ai_image_analysis_validate_claim($galleryId, $jobId, $claimToken);
    if (!$job) {
        return false;
    }

    $leaseSeconds = ai_image_analysis_normalize_lease_seconds($leaseSeconds);
    $progressPercent = max(0, min(99, $progressPercent));
    $message = ai_image_analysis_limit_text($message, 500);
    $now = now_sql();
    $stmt = db()->prepare("UPDATE image_ai_analysis_jobs
        SET heartbeat_at = ?,
            claim_expires_at = ?,
            progress_percent = ?,
            progress_message = ?,
            updated_at = ?
        WHERE id = ? AND gallery_id = ?");
    $stmt->execute([
        $now,
        ai_image_analysis_time_offset($leaseSeconds),
        $progressPercent,
        $message,
        $now,
        $jobId,
        $galleryId,
    ]);

    return $stmt->rowCount() > 0;
}

/**
 * Store a successful worker result and complete the owning job.
 *
 * @param int $galleryId Gallery identifier.
 * @param int $jobId Job id identifier.
 * @param string $claimToken Claim token value.
 * @param array<string,mixed> $metadata Internal result payload produced by the worker.
 * @param string $searchableText Searchable text value.
 * @return bool True when the condition matches.
 */
function ai_image_analysis_complete_success(int $galleryId, int $jobId, string $claimToken, array $metadata, string $searchableText): bool
{
    if (!ai_image_analysis_write_schema_ready('ai_analysis_complete_success')) {
        return false;
    }
    $job = ai_image_analysis_validate_claim($galleryId, $jobId, $claimToken);
    if (!$job) {
        return ai_image_analysis_claim_already_completed($galleryId, $jobId, $claimToken);
    }

    $image = find_image((int) ($job['image_id'] ?? 0));
    if (!$image) {
        return false;
    }

    $metadataJson = ai_image_analysis_encode_metadata($metadata);
    $searchableText = ai_image_analysis_searchable_text($metadata, $searchableText);
    $now = now_sql();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO image_ai_metadata (
                image_id,
                model_name,
                model_version,
                source_checksum_sha256,
                source_file_size,
                source_modified_at,
                metadata_json,
                searchable_text,
                generated_at,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                source_checksum_sha256 = VALUES(source_checksum_sha256),
                source_file_size = VALUES(source_file_size),
                source_modified_at = VALUES(source_modified_at),
                metadata_json = VALUES(metadata_json),
                searchable_text = VALUES(searchable_text),
                generated_at = VALUES(generated_at),
                updated_at = VALUES(updated_at)");
        $stmt->execute([
            (int) $image['id'],
            (string) $job['model_name'],
            (string) $job['model_version'],
            $image['checksum_sha256'] ?? $job['source_checksum_sha256'] ?? null,
            $image['file_size'] ?? $job['source_file_size'] ?? null,
            $image['modified_at'] ?? $job['source_modified_at'] ?? null,
            $metadataJson,
            $searchableText,
            $now,
            $now,
            $now,
        ]);

        $update = $pdo->prepare("UPDATE image_ai_analysis_jobs
            SET state = 'succeeded',
                heartbeat_at = ?,
                progress_percent = 100,
                progress_message = 'Completed.',
                completed_at = ?,
                last_error = NULL,
                updated_at = ?
            WHERE id = ? AND gallery_id = ?");
        $update->execute([$now, $now, $now, $jobId, $galleryId]);

        $cancel = $pdo->prepare("UPDATE image_ai_analysis_jobs
            SET state = 'cancelled',
                updated_at = ?,
                last_error = 'Superseded by a completed result.'
            WHERE image_id = ?
              AND model_name = ?
              AND model_version = ?
              AND id <> ?
              AND state IN ('queued', 'claimed')");
        $cancel->execute([
            $now,
            (int) $image['id'],
            (string) $job['model_name'],
            (string) $job['model_version'],
            $jobId,
        ]);

        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Mark a worker failure and schedule retry with exponential backoff.
 *
 * @param int $galleryId Gallery identifier.
 * @param int $jobId Job id identifier.
 * @param string $claimToken Claim token value.
 * @param string $errorMessage Error message value.
 * @return bool True when the condition matches.
 */
function ai_image_analysis_complete_failure(int $galleryId, int $jobId, string $claimToken, string $errorMessage): bool
{
    if (!ai_image_analysis_write_schema_ready('ai_analysis_complete_failure')) {
        return false;
    }
    $job = ai_image_analysis_validate_claim($galleryId, $jobId, $claimToken);
    if (!$job) {
        return false;
    }

    $attemptCount = max(1, (int) ($job['attempt_count'] ?? 1));
    $finalFailure = $attemptCount >= AI_IMAGE_ANALYSIS_MAX_ATTEMPTS;
    $backoffSeconds = min(3600, 60 * (2 ** max(0, $attemptCount - 1)));
    $now = now_sql();
    $stmt = db()->prepare("UPDATE image_ai_analysis_jobs
        SET state = ?,
            claim_owner = NULL,
            claim_token_hash = NULL,
            claim_expires_at = NULL,
            heartbeat_at = ?,
            progress_percent = 0,
            progress_message = ?,
            available_at = ?,
            completed_at = CASE WHEN ? = 1 THEN ? ELSE completed_at END,
            last_error = ?,
            updated_at = ?
        WHERE id = ? AND gallery_id = ?");
    $stmt->execute([
        $finalFailure ? 'failed' : 'queued',
        $now,
        $finalFailure ? 'Failed permanently.' : 'Retry scheduled.',
        $finalFailure ? null : ai_image_analysis_time_offset($backoffSeconds),
        $finalFailure ? 1 : 0,
        $now,
        ai_image_analysis_limit_text($errorMessage, AI_IMAGE_ANALYSIS_ERROR_LIMIT),
        $now,
        $jobId,
        $galleryId,
    ]);

    return $stmt->rowCount() > 0;
}

/**
 * Return a local file descriptor for a claimed job asset.
 *
 * @param int $galleryId Gallery identifier.
 * @param int $jobId Job id identifier.
 * @param string $claimToken Claim token value.
 * @return array{path:string,mime:string,filename:string,variant:string}|null Structured result data for the caller.
 */
function ai_image_analysis_claimed_asset(int $galleryId, int $jobId, string $claimToken): ?array
{
    $job = ai_image_analysis_validate_claim($galleryId, $jobId, $claimToken);
    if (!$job) {
        return null;
    }

    $image = find_image((int) ($job['image_id'] ?? 0));
    $gallery = $image ? find_gallery((int) ($image['gallery_id'] ?? 0), true) : null;
    if (!$image || !$gallery || (int) $gallery['id'] !== $galleryId) {
        return null;
    }

    // Prefer an already-generated display master for DNG files, but do not
    // create derivatives here. The shared host must not do expensive analysis or
    // conversion work just because a worker asks for an asset.
    $displayFile = function_exists('Gallery\\Services\\image_public_display_file') ? image_public_display_file($image, $gallery, false) : null;
    if (is_array($displayFile) && is_file((string) ($displayFile['path'] ?? ''))) {
        return $displayFile;
    }

    $path = image_abs_path($image, $gallery);
    if (!is_file($path)) {
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) ($finfo->file($path) ?: mime_content_type($path));
    return [
        'path' => $path,
        'mime' => $mime !== '' ? $mime : 'application/octet-stream',
        'filename' => basename((string) ($image['filename'] ?? basename($path))),
        'variant' => 'original',
    ];
}



/**
 * Remove stored AI results and queue rows for direct images in one gallery.
 *
 * The queue is lazy: after this reset, the next Windows worker poll for its
 * configured model/version will insert fresh jobs for the same gallery images.
 * Deleting old queue rows is intentional because job_key includes the source
 * fingerprint and model fields, so leaving succeeded rows would prevent an
 * identical model/version from being generated again.
 *
 * @param int $galleryId Gallery whose direct images should be regenerated.
 * @return array<string,int> Counts for images, removed metadata rows, and removed jobs.
 */
function ai_image_analysis_force_gallery_reprocess(int $galleryId): array
{
    if ($galleryId <= 0 || !ai_image_analysis_write_schema_ready('ai_analysis_force_reprocess')) {
        return [
            'galleries' => 0,
            'images' => 0,
            'metadata_deleted' => 0,
            'jobs_deleted' => 0,
            'jobs_queued' => 0,
        ];
    }

    $gallery = find_gallery($galleryId, true);
    if (!$gallery) {
        return [
            'galleries' => 0,
            'images' => 0,
            'metadata_deleted' => 0,
            'jobs_deleted' => 0,
            'jobs_queued' => 0,
        ];
    }

    $galleryIds = ai_image_analysis_gallery_branch_ids($gallery);
    $galleryPlaceholders = implode(',', array_fill(0, count($galleryIds), '?'));

    $stmt = db()->prepare('SELECT id FROM images WHERE gallery_id IN (' . $galleryPlaceholders . ') ORDER BY gallery_id ASC, id ASC');
    $stmt->execute($galleryIds);
    $imageIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (!$imageIds) {
        return [
            'galleries' => count($galleryIds),
            'images' => 0,
            'metadata_deleted' => 0,
            'jobs_deleted' => 0,
            'jobs_queued' => 0,
        ];
    }

    $imagePlaceholders = implode(',', array_fill(0, count($imageIds), '?'));
    $modelPairs = ai_image_analysis_reprocess_model_pairs($imageIds);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $deleteMetadata = $pdo->prepare('DELETE FROM image_ai_metadata WHERE image_id IN (' . $imagePlaceholders . ')');
        $deleteMetadata->execute($imageIds);
        $metadataDeleted = max(0, $deleteMetadata->rowCount());

        $deleteJobs = $pdo->prepare('DELETE FROM image_ai_analysis_jobs WHERE image_id IN (' . $imagePlaceholders . ')');
        $deleteJobs->execute($imageIds);
        $jobsDeleted = max(0, $deleteJobs->rowCount());

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $jobsQueued = 0;
    foreach ($galleryIds as $branchGalleryId) {
        foreach ($modelPairs as $modelPair) {
            $jobsQueued += ai_image_analysis_enqueue_missing_jobs(
                (int) $branchGalleryId,
                (string) $modelPair['model_name'],
                (string) $modelPair['model_version'],
                100
            );
        }
    }

    return [
        'galleries' => count($galleryIds),
        'images' => count($imageIds),
        'metadata_deleted' => $metadataDeleted,
        'jobs_deleted' => $jobsDeleted,
        'jobs_queued' => $jobsQueued,
    ];
}


/**
 * Return one gallery id plus all descendant gallery ids for AI regeneration.
 *
 * The admin button is shown on a gallery editor, but parent galleries often act
 * as containers. Including descendant galleries makes the action match what an
 * operator normally expects from a gallery-level regeneration command.
 *
 * @param array<string,mixed> $gallery Gallery row from the galleries table.
 * @return array<int,int> Gallery ids in stable parent-first order.
 */
function ai_image_analysis_gallery_branch_ids(array $gallery): array
{
    $galleryId = (int) ($gallery['id'] ?? 0);
    $folderPath = normalize_relative_path((string) ($gallery['folder_path'] ?? ''));
    if ($galleryId <= 0 || $folderPath === '') {
        return $galleryId > 0 ? [$galleryId] : [];
    }

    $stmt = db()->prepare('SELECT id FROM galleries WHERE folder_path = ? OR folder_path LIKE ? ORDER BY CHAR_LENGTH(folder_path), folder_path, id');
    $stmt->execute([$folderPath, $folderPath . '/%']);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    return $ids !== [] ? $ids : [$galleryId];
}

/**
 * Return model/version pairs that should be queued after a forced reset.
 *
 * Existing metadata and previous jobs tell the server which model generation
 * the operator probably wants to rerun. When no history exists, the default
 * built-in model pair is queued so the next worker poll has immediate work.
 *
 * @param array<int,int> $imageIds Image ids affected by the reset.
 * @return array<int,array{model_name:string,model_version:string}> Model pairs.
 */
function ai_image_analysis_reprocess_model_pairs(array $imageIds): array
{
    $imageIds = array_values(array_filter(array_map('intval', $imageIds), static fn (int $id): bool => $id > 0));
    if (!$imageIds) {
        return [
            ['model_name' => 'local-image-metadata', 'model_version' => '1'],
        ];
    }

    $placeholders = implode(',', array_fill(0, count($imageIds), '?'));
    $pairs = [];

    $metadataStmt = db()->prepare('SELECT DISTINCT model_name, model_version FROM image_ai_metadata WHERE image_id IN (' . $placeholders . ')');
    $metadataStmt->execute($imageIds);
    foreach ($metadataStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (string) $row['model_name'] . "\0" . (string) $row['model_version'];
        $pairs[$key] = [
            'model_name' => ai_image_analysis_normalize_label((string) $row['model_name'], 'local-image-metadata'),
            'model_version' => ai_image_analysis_normalize_label((string) $row['model_version'], '1'),
        ];
    }

    $jobStmt = db()->prepare('SELECT DISTINCT model_name, model_version FROM image_ai_analysis_jobs WHERE image_id IN (' . $placeholders . ')');
    $jobStmt->execute($imageIds);
    foreach ($jobStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (string) $row['model_name'] . "\0" . (string) $row['model_version'];
        $pairs[$key] = [
            'model_name' => ai_image_analysis_normalize_label((string) $row['model_name'], 'local-image-metadata'),
            'model_version' => ai_image_analysis_normalize_label((string) $row['model_version'], '1'),
        ];
    }

    if (!$pairs) {
        $pairs['local-image-metadata' . "\0" . '1'] = [
            'model_name' => 'local-image-metadata',
            'model_version' => '1',
        ];
    }

    return array_values($pairs);
}

/**
 * Return the newest internal AI metadata row for an image.
 *
 * Admin edit screens use this read-only helper so operators can inspect what
 * the Windows worker generated without mixing machine text into the human
 * description field.
 *
 * @param int $imageId Image identifier from the images table.
 * @return array<string,mixed>|null Latest metadata row, or null when unavailable.
 */
function ai_image_analysis_latest_metadata_for_image(int $imageId): ?array
{
    if ($imageId <= 0 || !ai_image_analysis_schema_ready()) {
        return null;
    }

    $stmt = db()->prepare("SELECT *
        FROM image_ai_metadata
        WHERE image_id = ?
        ORDER BY generated_at DESC, id DESC
        LIMIT 1");
    $stmt->execute([$imageId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return $row;
}

/**
 * Decode internal AI metadata JSON for admin inspection.
 *
 * @param array<string,mixed> $metadataRow Row returned by ai_image_analysis_latest_metadata_for_image().
 * @return array<string,mixed> Decoded metadata object, or an empty array when invalid.
 */
function ai_image_analysis_decode_metadata_row(array $metadataRow): array
{
    $raw = (string) ($metadataRow['metadata_json'] ?? '');
    if (trim($raw) === '') {
        return [];
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [];
    }

    return is_array($decoded) ? $decoded : [];
}

/**
 * Pretty-print one metadata row for a read-only admin text area.
 *
 * @param array<string,mixed> $metadataRow Row returned by ai_image_analysis_latest_metadata_for_image().
 * @return string Human-readable JSON for inspection, never for public rendering.
 */
function ai_image_analysis_metadata_pretty_json(array $metadataRow): string
{
    $decoded = ai_image_analysis_decode_metadata_row($metadataRow);
    if (!$decoded) {
        return (string) ($metadataRow['metadata_json'] ?? '');
    }

    try {
        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return (string) ($metadataRow['metadata_json'] ?? '');
    }
}

/**
 * Encode metadata JSON with a controlled failure mode.
 *
 * @param array<string,mixed> $metadata Internal metadata produced by the worker.
 * @return string Text result for the caller.
 */
function ai_image_analysis_encode_metadata(array $metadata): string
{
    try {
        $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('AI metadata could not be encoded as JSON.', 0, $exception);
    }

    return $json;
}

/**
 * Build searchable internal text from explicit worker text and metadata fields.
 *
 * @param array<string,mixed> $metadata Internal metadata produced by the worker.
 * @param string $explicitText Explicit text value.
 * @return string Text result for the caller.
 */
function ai_image_analysis_searchable_text(array $metadata, string $explicitText): string
{
    $parts = [];
    if (trim($explicitText) !== '') {
        $parts[] = $explicitText;
    }
    ai_image_analysis_collect_search_terms($metadata, $parts, 0);

    $text = preg_replace('/\s+/', ' ', implode(' ', array_filter(array_map('strval', $parts)))) ?? '';
    return ai_image_analysis_limit_text(trim($text), AI_IMAGE_ANALYSIS_SEARCH_TEXT_LIMIT);
}

/**
 * Recursively collect simple scalar metadata values for search indexing.
 *
 * @param mixed $value Metadata value from the decoded worker payload.
 * @param array<int,string> $parts Collected text fragments.
 * @param int $depth Depth value.
 */
function ai_image_analysis_collect_search_terms(mixed $value, array &$parts, int $depth): void
{
    if ($depth > 5) {
        return;
    }

    if (is_string($value)) {
        $value = trim($value);
        if ($value !== '') {
            $parts[] = $value;
        }
        return;
    }

    if (is_int($value) || is_float($value)) {
        $parts[] = (string) $value;
        return;
    }

    if (!is_array($value)) {
        return;
    }

    foreach ($value as $key => $child) {
        if (is_string($key) && ai_image_analysis_is_diagnostic_metadata_key($key)) {
            continue;
        }
        ai_image_analysis_collect_search_terms($child, $parts, $depth + 1);
    }
}

/**
 * Decide whether a metadata key is diagnostic and must not become search text.
 *
 * Worker diagnostics are useful in admin JSON inspection, but phrases such as
 * backend unavailable, connection refused, traceback, or pip output must never
 * pollute user-facing gallery search results. This filter is defensive: even if
 * an older worker sends diagnostic fields, the server excludes them from the
 * generated searchable_text column.
 *
 * @param string $key Lookup key.
 * @return bool True when the condition matches.
 */
function ai_image_analysis_is_diagnostic_metadata_key(string $key): bool
{
    $normalized = strtolower(trim($key));
    if ($normalized === '') {
        return false;
    }

    $blockedKeys = [
        'diagnostic',
        'diagnostics',
        'error',
        'errors',
        'exception',
        'exceptions',
        'fallback_notes',
        'last_error',
        'stack_trace',
        'stderr',
        'stdout',
        'traceback',
        'warning',
        'warnings',
    ];
    if (in_array($normalized, $blockedKeys, true)) {
        return true;
    }

    return str_contains($normalized, 'error')
        || str_contains($normalized, 'exception')
        || str_contains($normalized, 'diagnostic')
        || str_contains($normalized, 'traceback');
}

/**
 * Limit one user or worker supplied text field for database storage.
 *
 * @param string $text Text value.
 * @param int $limit Maximum number of items.
 * @return string Text result for the caller.
 */
function ai_image_analysis_limit_text(string $text, int $limit): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $limit);
    }
    return substr($text, 0, $limit);
}
