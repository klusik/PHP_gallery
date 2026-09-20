<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/gallery_trash.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns gallery trash persistence and lifecycle state transitions.
 *
 * Responsibilities:
 *   - Persist trash lifecycle rows and retention deadlines
 *   - Provide restore/reconciliation lookups
 *   - Apply restorable gallery/image metadata through fixed column allowlists
 *   - Coordinate atomic live-row deletion with PREPARING -> TRASHED state changes
 *   - Keep SQL and PDO transaction ownership out of trash service orchestration
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
 *   - Status and metadata identifiers are constrained by explicit allowlists.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use PDO;
use RuntimeException;
use Throwable;
use function Gallery\Core\db;

/** Signals that a failed COMMIT left the server outcome unprovable to the client. */
final class GalleryTrashCommitOutcomeUnknownException extends RuntimeException
{
}

/**
 * Refresh purge deadlines for entries that may return to the trashed state.
 *
 * @param string $deadline SQL timestamp used as the new retention deadline.
 * @param string $now SQL timestamp recording when the lifecycle rows changed.
 * @return void
 */
function gallery_trash_model_rearm_retention_deadlines(string $deadline, string $now): void
{
    $stmt = db()->prepare(
        "UPDATE gallery_trash_entries
            SET purge_after = ?, updated_at = ?
          WHERE status IN ('trashed','preparing','restoring')"
    );
    $stmt->execute([$deadline, $now]);
}

/** @return array<int,array<string,mixed>> */
function gallery_trash_model_image_rows(int $galleryId): array
{
    $stmt = db()->prepare('SELECT * FROM images WHERE gallery_id = ? ORDER BY sort_order, filename, id');
    $stmt->execute([$galleryId]);
    return $stmt->fetchAll() ?: [];
}

/** Resolve an image relative path by id. */
function gallery_trash_model_image_relative_path(int $imageId): ?string
{
    $stmt = db()->prepare('SELECT relative_path FROM images WHERE id = ?');
    $stmt->execute([$imageId]);
    $value = $stmt->fetchColumn();
    return is_string($value) && $value !== '' ? $value : null;
}

/**
 * Insert one fully prepared trash lifecycle record before filesystem movement.
 *
 * @param array<string,mixed> $row Validated persistence fields for the new preparing entry.
 * @return void
 */
function gallery_trash_model_insert_preparing(array $row): void
{
    $stmt = db()->prepare(
        'INSERT INTO gallery_trash_entries
            (trash_token, status, original_folder_path, original_parent_folder_path,
             title, subtree_gallery_count, image_count, byte_size, snapshot_version, snapshot_json, trash_relative_path,
             deleted_by_user_id, deleted_from, deleted_at, purge_after, operation_started_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $row['trash_token'],
        'preparing',
        $row['original_folder_path'],
        $row['original_parent_folder_path'],
        $row['title'],
        $row['subtree_gallery_count'],
        $row['image_count'],
        $row['byte_size'],
        $row['snapshot_version'],
        $row['snapshot_json'],
        $row['trash_relative_path'],
        $row['deleted_by_user_id'],
        $row['deleted_from'],
        $row['deleted_at'],
        $row['purge_after'],
        $row['operation_started_at'],
        $row['created_at'],
        $row['updated_at'],
    ]);
}

/** Delete an abandoned PREPARING row. */
function gallery_trash_model_delete_preparing(string $trashToken): void
{
    $stmt = db()->prepare("DELETE FROM gallery_trash_entries WHERE trash_token = ? AND status = 'preparing'");
    $stmt->execute([$trashToken]);
}

/**
 * Transition one trash lifecycle row inside an existing transaction.
 *
 * @param string $trashToken Opaque identity of the lifecycle entry.
 * @param string $fromStatus Required current lifecycle state.
 * @param string $toStatus Destination lifecycle state.
 * @param bool $clearSnapshot Whether stored recovery snapshot fields are cleared.
 * @param string|null $errorCode Bounded failure identity retained on the entry.
 * @param string $timestamp Shared SQL timestamp for transition fields.
 * @return void
 * @throws RuntimeException When no transaction is active or the compare-and-swap transition loses.
 */
function gallery_trash_model_transition_in_transaction(string $trashToken, string $fromStatus, string $toStatus, bool $clearSnapshot, ?string $errorCode, string $timestamp): void
{
    $pdo = db();
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('Trash lifecycle transition requires an active database transaction.');
    }
    $stmt = $pdo->prepare(
        'UPDATE gallery_trash_entries
            SET status = ?,
                operation_started_at = NULL,
                restored_at = CASE WHEN ? = \'restored\' THEN ? ELSE restored_at END,
                purged_at = CASE WHEN ? = \'purged\' THEN ? ELSE purged_at END,
                snapshot_json = CASE WHEN ? = 1 THEN NULL ELSE snapshot_json END,
                last_error_code = ?,
                updated_at = ?
          WHERE trash_token = ? AND status = ?'
    );
    $stmt->execute([
        $toStatus,
        $toStatus,
        $timestamp,
        $toStatus,
        $timestamp,
        $clearSnapshot ? 1 : 0,
        $errorCode,
        $timestamp,
        $trashToken,
        $fromStatus,
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('The trash entry lifecycle changed concurrently.');
    }
}

/** Perform one lifecycle transition in its own transaction. */
function gallery_trash_model_transition(string $trashToken, string $fromStatus, string $toStatus, bool $clearSnapshot, ?string $errorCode, string $timestamp): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        gallery_trash_model_transition_in_transaction($trashToken, $fromStatus, $toStatus, $clearSnapshot, $errorCode, $timestamp);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Perform one lifecycle transition while distinguishing an unprovable COMMIT outcome.
 *
 * Used where compensating filesystem actions would be destructive if the server
 * actually committed before the client connection failed.
 */
function gallery_trash_model_transition_commit_aware(string $trashToken, string $fromStatus, string $toStatus, bool $clearSnapshot, ?string $errorCode, string $timestamp): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        gallery_trash_model_transition_in_transaction($trashToken, $fromStatus, $toStatus, $clearSnapshot, $errorCode, $timestamp);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    try {
        $pdo->commit();
    } catch (Throwable $exception) {
        $rollbackConfirmed = false;
        if ($pdo->inTransaction()) {
            try {
                $pdo->rollBack();
                $rollbackConfirmed = true;
            } catch (Throwable) {
                $rollbackConfirmed = false;
            }
        }
        if (!$rollbackConfirmed) {
            throw new GalleryTrashCommitOutcomeUnknownException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }
        throw $exception;
    }
}

/**
 * Atomically remove live subtree rows and finalize PREPARING -> TRASHED.
 *
 * @param array<int,int> $galleryIds Live subtree ids.
 * @param array<string,bool> $availableDependencies Optional cleanup capabilities.
 */
function gallery_trash_model_finalize_preparing_delete(string $trashToken, array $galleryIds, array $availableDependencies, string $timestamp): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $deletedRows = $galleryIds !== []
            ? gallery_mutation_model_delete_subtree_in_transaction($galleryIds, $availableDependencies)
            : 0;
        gallery_trash_model_transition_in_transaction($trashToken, 'preparing', 'trashed', false, null, $timestamp);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    try {
        $pdo->commit();
    } catch (Throwable $exception) {
        $rollbackConfirmed = false;
        if ($pdo->inTransaction()) {
            try {
                $pdo->rollBack();
                $rollbackConfirmed = true;
            } catch (Throwable) {
                $rollbackConfirmed = false;
            }
        }
        if (!$rollbackConfirmed) {
            throw new GalleryTrashCommitOutcomeUnknownException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }
        throw $exception;
    }
    return $deletedRows;
}

/**
 * Compare-and-swap one recoverable/problem entry into a transitional state.
 *
 * @param string $trashToken Opaque identity of the lifecycle entry.
 * @param string $targetStatus Transitional state to claim.
 * @param string $fromStatus Required recoverable current state.
 * @param string $timestamp SQL timestamp used for claim and update fields.
 * @return bool True only when this request claimed exactly one entry.
 */
function gallery_trash_model_claim(string $trashToken, string $targetStatus, string $fromStatus, string $timestamp): bool
{
    $stmt = db()->prepare(
        "UPDATE gallery_trash_entries
            SET status = ?, operation_started_at = ?, last_error_code = NULL, updated_at = ?
          WHERE trash_token = ? AND status = ?"
    );
    $stmt->execute([$targetStatus, $timestamp, $timestamp, $trashToken, $fromStatus]);
    return $stmt->rowCount() === 1;
}

/**
 * Release one transitional claim through an exact-state compare-and-swap.
 *
 * @param string $trashToken Opaque identity of the lifecycle entry.
 * @param string $claimedStatus Required transitional state owned by the caller.
 * @param string|null $errorCode Bounded failure identity, or null after successful recovery.
 * @param string $returnStatus Stable state restored after releasing the claim.
 * @param string $timestamp SQL timestamp recording the release.
 * @return bool True only when the claimed row was released.
 */
function gallery_trash_model_release_claim(string $trashToken, string $claimedStatus, ?string $errorCode, string $returnStatus, string $timestamp): bool
{
    $stmt = db()->prepare(
        "UPDATE gallery_trash_entries
            SET status = ?, operation_started_at = NULL, last_error_code = ?, updated_at = ?
          WHERE trash_token = ? AND status = ?"
    );
    $stmt->execute([$returnStatus, $errorCode, $timestamp, $trashToken, $claimedStatus]);
    return $stmt->rowCount() === 1;
}

/** Persist a diagnostic code on a PURGING entry after filesystem deletion fails. */
function gallery_trash_model_set_purge_error(string $trashToken, string $errorCode, string $timestamp): void
{
    $stmt = db()->prepare("UPDATE gallery_trash_entries SET last_error_code = ?, updated_at = ? WHERE trash_token = ? AND status = 'purging'");
    $stmt->execute([$errorCode, $timestamp, $trashToken]);
}

/** Return the folder path currently owning one gallery slug. */
function gallery_trash_model_folder_path_by_slug(string $slug): ?string
{
    $stmt = db()->prepare('SELECT folder_path FROM galleries WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $value = $stmt->fetchColumn();
    return is_string($value) && $value !== '' ? $value : null;
}

/**
 * Return the newest active trash entry for an exact original folder path.
 *
 * @param string $folderPath Normalized original gallery-relative folder path.
 * @param string $excludeToken Trash identity omitted from collision detection.
 * @return array{trash_token:string,title:string,original_folder_path:string,status:string}|null Matching bounded entry, or null.
 */
function gallery_trash_model_active_entry_for_path(string $folderPath, string $excludeToken): ?array
{
    $stmt = db()->prepare(
        "SELECT trash_token, title, original_folder_path, status
           FROM gallery_trash_entries
          WHERE original_folder_path = ?
            AND trash_token <> ?
            AND status IN ('preparing','trashed','restoring','purging','broken')
          ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$folderPath, $excludeToken]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Return live gallery row IDs at or below one normalized folder path.
 *
 * @param string $folderPath Normalized gallery-relative root path.
 * @return array<int,int> Unique positive IDs ordered deepest-first by the query.
 */
function gallery_trash_model_live_row_ids_for_path(string $folderPath): array
{
    $prefix = $folderPath . '/';
    $stmt = db()->prepare(
        'SELECT id FROM galleries
          WHERE folder_path = ? OR LEFT(folder_path, CHAR_LENGTH(?)) = ?
          ORDER BY LENGTH(folder_path) DESC, id DESC'
    );
    $stmt->execute([$folderPath, $prefix, $prefix]);
    return array_values(array_unique(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []), static fn (int $id): bool => $id > 0)));
}

/** Apply restore-safe gallery metadata through a fixed column allowlist. */
function gallery_trash_model_update_gallery_metadata(int $galleryId, array $record, array $confirmedColumns, string $timestamp): void
{
    $allowed = [
        'title','description','slug','sort_order','visibility','access_mode','access_listing','access_password_hash',
        'access_share_token','access_token_hash','access_token_expires_at','voting_enabled','picture_game_enabled',
        'show_filenames','gps_map_enabled','content_language','gallery_date','gallery_date_end','description_layout',
        'count_badge_visibility','lightbox_browsing_mode','grid_columns','grid_rows','grid_use_for_subgalleries',
        'thumbnail_min_size','thumbnail_max_size','background_source','banner_image_path','logo_image_path',
        'separator_image_path','cover_image_path','nsfw_enabled',
    ];
    gallery_trash_model_update_metadata_row('galleries', $galleryId, $record, $confirmedColumns, $allowed, $timestamp);
}

/** Apply restore-safe image metadata through a fixed column allowlist. */
function gallery_trash_model_update_image_metadata(int $imageId, array $record, array $confirmedColumns, string $timestamp): void
{
    $allowed = ['title','description','sort_order','visibility','editorial_rating','content_language','thumbnail_min_size','thumbnail_max_size','nsfw_enabled'];
    gallery_trash_model_update_metadata_row('images', $imageId, $record, $confirmedColumns, $allowed, $timestamp);
}

/**
 * Set one restored gallery title-picture reference and advance its edit revision.
 *
 * @param int $galleryId Positive restored gallery identifier.
 * @param int $imageId Positive image identifier already validated for the gallery.
 * @param string $timestamp SQL timestamp recording the restored cover assignment.
 * @return void
 */
function gallery_trash_model_set_cover_image(int $galleryId, int $imageId, string $timestamp): void
{
    $stmt = db()->prepare('UPDATE galleries SET cover_image_id = ?, updated_at = ?, edit_revision = edit_revision + 1 WHERE id = ?');
    $stmt->execute([$imageId, $timestamp, $galleryId]);
}

/** @return array<int,string> */
function gallery_trash_model_tokens_by_status(string $status, int $limit): array
{
    if ($status !== 'trashed') {
        throw new RuntimeException('Unsupported trash token status query.');
    }
    $limit = max(1, min(100, $limit));
    $stmt = db()->prepare("SELECT trash_token FROM gallery_trash_entries WHERE status = 'trashed' ORDER BY deleted_at LIMIT " . $limit);
    $stmt->execute();
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/** Return how many rows currently have one fixed lifecycle status. */
function gallery_trash_model_count_by_status(string $status): int
{
    if ($status !== 'trashed') {
        throw new RuntimeException('Unsupported trash count status query.');
    }
    $stmt = db()->query("SELECT COUNT(*) FROM gallery_trash_entries WHERE status = 'trashed'");
    return $stmt === false ? 0 : (int) $stmt->fetchColumn();
}

/** @return array<int,string> */
function gallery_trash_model_expired_tokens(string $now, int $limit): array
{
    $limit = max(1, min(100, $limit));
    $stmt = db()->prepare("SELECT trash_token FROM gallery_trash_entries WHERE status = 'trashed' AND purge_after <= ? ORDER BY purge_after LIMIT " . $limit);
    $stmt->execute([$now]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/**
 * Return bounded stale transitional entries eligible for recovery inspection.
 *
 * @param string $cutoff SQL timestamp at or before which an operation is stale.
 * @param int $limit Requested result ceiling; normalized to the model safety range.
 * @return array<int,array<string,mixed>> Persistence rows ordered by operation age.
 */
function gallery_trash_model_stale_transitional_entries(string $cutoff, int $limit): array
{
    $limit = max(1, min(100, $limit));
    $stmt = db()->prepare(
        "SELECT * FROM gallery_trash_entries
          WHERE status IN ('preparing','restoring','purging')
            AND operation_started_at IS NOT NULL
            AND operation_started_at <= ?
          ORDER BY operation_started_at, id
          LIMIT " . $limit
    );
    $stmt->execute([$cutoff]);
    return $stmt->fetchAll() ?: [];
}

/** Load one trash row by token. */
function gallery_trash_model_entry(string $trashToken): ?array
{
    $stmt = db()->prepare('SELECT * FROM gallery_trash_entries WHERE trash_token = ?');
    $stmt->execute([$trashToken]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** @return array<int,array<string,mixed>> */
function gallery_trash_model_entries(string $status, int $limit): array
{
    $limit = max(1, min(500, $limit));
    $select = 'SELECT e.*, u.username AS deleted_by_username FROM gallery_trash_entries e LEFT JOIN users u ON u.id = e.deleted_by_user_id';
    if ($status === 'all') {
        $stmt = db()->prepare($select . ' ORDER BY e.deleted_at DESC LIMIT ' . $limit);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }
    if ($status === 'active') {
        $stmt = db()->prepare($select . " WHERE e.status IN ('trashed','broken','preparing','restoring','purging') ORDER BY e.deleted_at DESC LIMIT " . $limit);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }
    if (!in_array($status, ['trashed','restored','purged','broken'], true)) {
        throw new RuntimeException('Unsupported trash listing status.');
    }
    $stmt = db()->prepare($select . ' WHERE e.status = ? ORDER BY e.deleted_at DESC LIMIT ' . $limit);
    $stmt->execute([$status]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Return the raw aggregate used to prepare bounded Trash health and count summaries.
 *
 * @param string $now SQL timestamp separating expired from retained entries.
 * @return array<string,int|string|null> Aggregate counts, bytes and next purge timestamp.
 */
function gallery_trash_model_summary(string $now): array
{
    $stmt = db()->prepare(
        "SELECT
                SUM(CASE WHEN status = 'trashed' THEN 1 ELSE 0 END) AS trashed_count,
                SUM(CASE WHEN status = 'broken' THEN 1 ELSE 0 END) AS broken_count,
                SUM(CASE WHEN status IN ('preparing','restoring','purging') THEN 1 ELSE 0 END) AS transitional_count,
                SUM(CASE WHEN status IN ('broken','preparing','restoring','purging') THEN 1 ELSE 0 END) AS problem_count,
                SUM(CASE WHEN status = 'trashed' THEN 1 ELSE 0 END) AS purgeable_count,
                COUNT(*) AS active_count,
                COALESCE(SUM(byte_size), 0) AS total_bytes,
                COALESCE(MIN(CASE WHEN status = 'trashed' THEN purge_after ELSE NULL END), '') AS next_purge_at,
                SUM(CASE WHEN status = 'trashed' AND purge_after <= ? THEN 1 ELSE 0 END) AS expired_count
           FROM gallery_trash_entries
          WHERE status IN ('trashed','broken','preparing','restoring','purging')"
    );
    $stmt->execute([$now]);
    return $stmt->fetch() ?: [];
}

/**
 * Apply schema-confirmed, allowlisted restore metadata to one gallery or image row.
 *
 * @param string $table Fixed target table name: galleries or images.
 * @param int $id Positive target row identifier.
 * @param array<string,mixed> $record Snapshot values keyed by candidate column name.
 * @param array<int,string> $confirmedColumns Columns confirmed available by schema policy.
 * @param array<int,string> $allowedColumns Domain allowlist for this restore target.
 * @param string $timestamp SQL timestamp appended to the restored row.
 * @return void
 * @throws RuntimeException When the fixed table owner is unsupported.
 */
function gallery_trash_model_update_metadata_row(string $table, int $id, array $record, array $confirmedColumns, array $allowedColumns, string $timestamp): void
{
    if (!in_array($table, ['galleries','images'], true)) {
        throw new RuntimeException('Unsupported trash restore metadata table.');
    }
    $allowedLookup = array_fill_keys($allowedColumns, true);
    $confirmedLookup = array_fill_keys($confirmedColumns, true);
    $assignments = [];
    $values = [];
    foreach ($record as $column => $value) {
        if (!isset($allowedLookup[$column], $confirmedLookup[$column])) {
            continue;
        }
        $assignments[] = '`' . $column . '` = ?';
        $values[] = $value;
    }
    if ($assignments === []) {
        return;
    }
    $assignments[] = '`updated_at` = ?';
    $values[] = $timestamp;
    if ($table === 'galleries') {
        $assignments[] = '`edit_revision` = `edit_revision` + 1';
    }
    $values[] = $id;
    $stmt = db()->prepare('UPDATE `' . $table . '` SET ' . implode(', ', $assignments) . ' WHERE id = ?');
    $stmt->execute($values);
}
