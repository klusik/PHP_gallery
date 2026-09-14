<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/ai_image_analysis.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns AI image-analysis queue and metadata persistence.
 *
 * Responsibilities:
 *   - Insert missing queue jobs and release expired leases
 *   - Claim one job atomically with row locking and compare-and-set state changes
 *   - Validate and update live worker claims
 *   - Persist successful metadata and retry/failure state atomically
 *   - Support bounded gallery reprocess and read-only metadata inspection
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
 *   - Token generation, hashing, retry policy, provider logic, and filesystem access remain services.
 */

declare(strict_types=1);

namespace Gallery\Models;

use PDO;
use Throwable;
use function Gallery\Core\db;

/**
 * Insert missing queue jobs for one gallery and model generation.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $modelName Model name.
 * @param string $modelVersion Model version.
 * @param int $limit Maximum inserted candidates.
 * @param string $now Current SQL timestamp.
 * @return int Number of inserted jobs.
 */
function ai_image_analysis_model_enqueue_missing_jobs(int $galleryId, string $modelName, string $modelVersion, int $limit, string $now): int
{
    $limit = max(1, min(100, $limit));
    $sql = "INSERT IGNORE INTO image_ai_analysis_jobs (
            gallery_id, image_id, job_key, model_name, model_version,
            source_checksum_sha256, source_file_size, source_modified_at,
            state, attempt_count, available_at, created_at, updated_at
        )
        SELECT
            i.gallery_id,
            i.id,
            SHA2(CONCAT_WS('|', i.id, ?, ?, COALESCE(i.checksum_sha256, ''), COALESCE(i.file_size, ''), COALESCE(i.modified_at, '')), 256),
            ?, ?, i.checksum_sha256, i.file_size, i.modified_at,
            'queued', 0, ?, ?, ?
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
        $modelName, $modelVersion, $modelName, $modelVersion,
        $now, $now, $now,
        $modelName, $modelVersion, $galleryId, $modelName, $modelVersion,
    ]);
    return max(0, (int) $stmt->rowCount());
}

/**
 * Release expired claimed jobs for one gallery.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $now Current SQL timestamp.
 * @return int Number of released jobs.
 */
function ai_image_analysis_model_release_expired_claims(int $galleryId, string $now): int
{
    $stmt = db()->prepare("UPDATE image_ai_analysis_jobs
        SET state = 'queued', claim_owner = NULL, claim_token_hash = NULL,
            claim_expires_at = NULL, progress_message = 'Lease expired before completion.', updated_at = ?
        WHERE gallery_id = ? AND state = 'claimed'
          AND claim_expires_at IS NOT NULL AND claim_expires_at < ?");
    $stmt->execute([$now, $galleryId, $now]);
    return max(0, (int) $stmt->rowCount());
}

/**
 * Atomically claim one eligible queue job.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $modelName Model name.
 * @param string $modelVersion Model version.
 * @param string $workerId Normalized worker identifier.
 * @param string $claimTokenHash Hash of the raw claim token.
 * @param string $now Current SQL timestamp.
 * @param string $claimUntil Lease expiry timestamp.
 * @return ?int Claimed job identifier, or null when no eligible work exists.
 */
function ai_image_analysis_model_claim_next_job(int $galleryId, string $modelName, string $modelVersion, string $workerId, string $claimTokenHash, string $now, string $claimUntil): ?int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $release = $pdo->prepare("UPDATE image_ai_analysis_jobs
            SET state = 'queued', claim_owner = NULL, claim_token_hash = NULL,
                claim_expires_at = NULL, progress_message = 'Lease expired before completion.', updated_at = ?
            WHERE gallery_id = ? AND state = 'claimed'
              AND claim_expires_at IS NOT NULL AND claim_expires_at < ?");
        $release->execute([$now, $galleryId, $now]);

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
            SET state = 'claimed', claim_owner = ?, claim_token_hash = ?, claim_expires_at = ?,
                claimed_at = ?, heartbeat_at = ?, progress_percent = 0,
                progress_message = 'Claimed by worker.', attempt_count = attempt_count + 1, updated_at = ?
            WHERE id = ? AND state = 'queued'");
        $update->execute([$workerId, $claimTokenHash, $claimUntil, $now, $now, $now, (int) $job['id']]);
        if ($update->rowCount() < 1) {
            $pdo->rollBack();
            return null;
        }
        $pdo->commit();
        return (int) $job['id'];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Return one AI-analysis job row.
 *
 * @param int $jobId Job identifier.
 * @return ?array<string,mixed> Job row or null.
 */
function ai_image_analysis_model_find_job(int $jobId): ?array
{
    $stmt = db()->prepare('SELECT * FROM image_ai_analysis_jobs WHERE id = ?');
    $stmt->execute([$jobId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Return one live claimed job matching a hashed claim token.
 *
 * @param int $galleryId Gallery identifier.
 * @param int $jobId Job identifier.
 * @param string $claimTokenHash Hashed claim token.
 * @param string $now Current SQL timestamp.
 * @return ?array<string,mixed> Matching job row or null.
 */
function ai_image_analysis_model_validate_claim(int $galleryId, int $jobId, string $claimTokenHash, string $now): ?array
{
    $stmt = db()->prepare("SELECT * FROM image_ai_analysis_jobs
        WHERE id = ? AND gallery_id = ? AND claim_token_hash = ?
          AND state = 'claimed' AND claim_expires_at IS NOT NULL AND claim_expires_at >= ?
        LIMIT 1");
    $stmt->execute([$jobId, $galleryId, $claimTokenHash, $now]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Return whether a succeeded job still carries the supplied claim-token hash.
 *
 * @param int $galleryId Gallery identifier.
 * @param int $jobId Job identifier.
 * @param string $claimTokenHash Hashed claim token.
 * @return bool True when a matching completed job exists.
 */
function ai_image_analysis_model_claim_completed(int $galleryId, int $jobId, string $claimTokenHash): bool
{
    $stmt = db()->prepare("SELECT id FROM image_ai_analysis_jobs
        WHERE id = ? AND gallery_id = ? AND claim_token_hash = ? AND state = 'succeeded' LIMIT 1");
    $stmt->execute([$jobId, $galleryId, $claimTokenHash]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Extend one live claim heartbeat.
 *
 * @param int $galleryId Gallery identifier.
 * @param int $jobId Job identifier.
 * @param string $now Current SQL timestamp.
 * @param string $claimUntil New lease expiry timestamp.
 * @param int $progressPercent Progress percentage.
 * @param string $message Bounded progress message.
 * @return bool True when one job row was updated.
 */
function ai_image_analysis_model_heartbeat(int $galleryId, int $jobId, string $now, string $claimUntil, int $progressPercent, string $message): bool
{
    $stmt = db()->prepare("UPDATE image_ai_analysis_jobs
        SET heartbeat_at = ?, claim_expires_at = ?, progress_percent = ?, progress_message = ?, updated_at = ?
        WHERE id = ? AND gallery_id = ?");
    $stmt->execute([$now, $claimUntil, $progressPercent, $message, $now, $jobId, $galleryId]);
    return $stmt->rowCount() > 0;
}

/**
 * Persist one successful analysis result and complete its job atomically.
 *
 * @param int $galleryId Gallery identifier.
 * @param int $jobId Job identifier.
 * @param int $imageId Image identifier.
 * @param string $modelName Model name.
 * @param string $modelVersion Model version.
 * @param mixed $checksum Source checksum.
 * @param mixed $fileSize Source file size.
 * @param mixed $modifiedAt Source modified timestamp.
 * @param string $metadataJson Encoded metadata JSON.
 * @param string $searchableText Derived searchable text.
 * @param string $now Current SQL timestamp.
 */
function ai_image_analysis_model_complete_success(int $galleryId, int $jobId, int $imageId, string $modelName, string $modelVersion, mixed $checksum, mixed $fileSize, mixed $modifiedAt, string $metadataJson, string $searchableText, string $now): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO image_ai_metadata (
                image_id, model_name, model_version, source_checksum_sha256, source_file_size,
                source_modified_at, metadata_json, searchable_text, generated_at, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                source_checksum_sha256 = VALUES(source_checksum_sha256),
                source_file_size = VALUES(source_file_size),
                source_modified_at = VALUES(source_modified_at),
                metadata_json = VALUES(metadata_json), searchable_text = VALUES(searchable_text),
                generated_at = VALUES(generated_at), updated_at = VALUES(updated_at)");
        $stmt->execute([$imageId, $modelName, $modelVersion, $checksum, $fileSize, $modifiedAt, $metadataJson, $searchableText, $now, $now, $now]);

        $update = $pdo->prepare("UPDATE image_ai_analysis_jobs
            SET state = 'succeeded', heartbeat_at = ?, progress_percent = 100,
                progress_message = 'Completed.', completed_at = ?, last_error = NULL, updated_at = ?
            WHERE id = ? AND gallery_id = ?");
        $update->execute([$now, $now, $now, $jobId, $galleryId]);

        $cancel = $pdo->prepare("UPDATE image_ai_analysis_jobs
            SET state = 'cancelled', updated_at = ?, last_error = 'Superseded by a completed result.'
            WHERE image_id = ? AND model_name = ? AND model_version = ? AND id <> ?
              AND state IN ('queued', 'claimed')");
        $cancel->execute([$now, $imageId, $modelName, $modelVersion, $jobId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Record one failed attempt and either queue retry or mark permanent failure.
 *
 * @param int $galleryId Gallery identifier.
 * @param int $jobId Job identifier.
 * @param bool $finalFailure Whether maximum attempts were reached.
 * @param string $now Current SQL timestamp.
 * @param ?string $availableAt Next retry timestamp, or null for final failure.
 * @param string $errorMessage Bounded error message.
 * @return bool True when one row was updated.
 */
function ai_image_analysis_model_complete_failure(int $galleryId, int $jobId, bool $finalFailure, string $now, ?string $availableAt, string $errorMessage): bool
{
    $stmt = db()->prepare("UPDATE image_ai_analysis_jobs
        SET state = ?, claim_owner = NULL, claim_token_hash = NULL, claim_expires_at = NULL,
            heartbeat_at = ?, progress_percent = 0, progress_message = ?, available_at = ?,
            completed_at = CASE WHEN ? = 1 THEN ? ELSE completed_at END,
            last_error = ?, updated_at = ?
        WHERE id = ? AND gallery_id = ?");
    $stmt->execute([
        $finalFailure ? 'failed' : 'queued', $now,
        $finalFailure ? 'Failed permanently.' : 'Retry scheduled.', $availableAt,
        $finalFailure ? 1 : 0, $now, $errorMessage, $now, $jobId, $galleryId,
    ]);
    return $stmt->rowCount() > 0;
}

/**
 * Return ordered image identifiers belonging to selected galleries.
 *
 * @param array $galleryIds Gallery identifiers.
 * @return array<int,int> Image identifiers.
 */
function ai_image_analysis_model_image_ids_for_galleries(array $galleryIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare('SELECT id FROM images WHERE gallery_id IN (' . $placeholders . ') ORDER BY gallery_id ASC, id ASC');
    $stmt->execute($ids);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/**
 * Delete metadata and queue jobs for selected images atomically.
 *
 * @param array $imageIds Image identifiers.
 * @return array{metadata_deleted:int,jobs_deleted:int} Deleted row counts.
 */
function ai_image_analysis_model_reset_images(array $imageIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $imageIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return ['metadata_deleted' => 0, 'jobs_deleted' => 0];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $metadata = $pdo->prepare('DELETE FROM image_ai_metadata WHERE image_id IN (' . $placeholders . ')');
        $metadata->execute($ids);
        $jobs = $pdo->prepare('DELETE FROM image_ai_analysis_jobs WHERE image_id IN (' . $placeholders . ')');
        $jobs->execute($ids);
        $result = ['metadata_deleted' => max(0, (int) $metadata->rowCount()), 'jobs_deleted' => max(0, (int) $jobs->rowCount())];
        $pdo->commit();
        return $result;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Return one gallery branch in stable parent-first order.
 *
 * @param string $folderPath Normalized root folder path.
 * @return array<int,int> Gallery identifiers.
 */
function ai_image_analysis_model_gallery_branch_ids(string $folderPath): array
{
    if ($folderPath === '') {
        return [];
    }
    $stmt = db()->prepare('SELECT id FROM galleries WHERE folder_path = ? OR folder_path LIKE ? ORDER BY CHAR_LENGTH(folder_path), folder_path, id');
    $stmt->execute([$folderPath, $folderPath . '/%']);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/**
 * Return distinct historical model/version pairs for selected images.
 *
 * @param array $imageIds Image identifiers.
 * @return array<int,array{model_name:string,model_version:string}> Distinct raw model pairs.
 */
function ai_image_analysis_model_reprocess_pairs(array $imageIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $imageIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pairs = [];
    foreach (['image_ai_metadata', 'image_ai_analysis_jobs'] as $table) {
        $stmt = db()->prepare('SELECT DISTINCT model_name, model_version FROM ' . $table . ' WHERE image_id IN (' . $placeholders . ')');
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = (string) ($row['model_name'] ?? '') . "\0" . (string) ($row['model_version'] ?? '');
            $pairs[$key] = ['model_name' => (string) ($row['model_name'] ?? ''), 'model_version' => (string) ($row['model_version'] ?? '')];
        }
    }
    return array_values($pairs);
}

/**
 * Return the newest internal AI metadata row for one image.
 *
 * @param int $imageId Image identifier.
 * @return ?array<string,mixed> Metadata row or null.
 */
function ai_image_analysis_model_latest_metadata(int $imageId): ?array
{
    $stmt = db()->prepare("SELECT * FROM image_ai_metadata WHERE image_id = ? ORDER BY generated_at DESC, id DESC LIMIT 1");
    $stmt->execute([$imageId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}
