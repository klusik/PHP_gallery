<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/admin_storage_statistics.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns database reads used by Admin source/generated-media storage statistics.
 *
 * Responsibilities:
 *   - Return source/gallery fingerprint aggregates
 *   - Return image/gallery rows for storage scanning in bounded or full form
 *   - Aggregate durable thumbnail-variant counts and bytes
 *   - Page physical-gallery attachment owners independently of image membership
 *   - Persist and aggregate derived PDF, music and OFP accounting
 *
 * @phpstan-type StorageStatisticsImageRow array{image_id:int|string,
 *     image_gallery_id:int|string,relative_path:string,filename:string,
 *     mime_type:string|null,file_size:int|string,width:int|string|null,
 *     height:int|string|null,thumbnail_derivative_version:int|string,
 *     gallery_id:int|string,gallery_title:string,gallery_folder_path:string}
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
 *   - Filesystem size scanning and presentation grouping remain service responsibilities.
 */

declare(strict_types=1);

namespace Gallery\Models;

use function Gallery\Core\db;
use function Gallery\Core\storage_observer_call;

require_once __DIR__ . '/../runtime/storage_observer.php';

/**
 * Count physical galleries independently of indexed photographs.
 * @return int Number of registered physical galleries, including empty ones.
 */
function admin_storage_statistics_model_gallery_count(): int
{
    $statement = db()->prepare('SELECT COUNT(*) FROM galleries');
    $statement->execute();
    return (int) $statement->fetchColumn();
}

/**
 * Read one keyset batch of physical attachment-storage owners.
 * @param int $lastGalleryId Exclusive cursor from the previous batch.
 * @param int $limit Positive service-owned gallery observation limit.
 * @return list<array{id:int|string,folder_path:string}> Ordered physical owners.
 */
function admin_storage_statistics_model_gallery_rows_after_id(
    int $lastGalleryId,
    int $limit
): array {
    $statement = db()->prepare(
        'SELECT id, folder_path FROM galleries'
        . ' WHERE id > ? ORDER BY id LIMIT ?'
    );
    $statement->bindValue(1, max(0, $lastGalleryId), \PDO::PARAM_INT);
    $statement->bindValue(2, max(1, $limit), \PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll();
}

/** Return aggregate image/gallery values used for the storage cache fingerprint. */
function admin_storage_statistics_model_fingerprint_rows(): array
{
    $stmt = db()->prepare("SELECT COUNT(*) AS image_count, COALESCE(SUM(COALESCE(file_size, 0)), 0) AS original_bytes, COALESCE(MAX(id), 0) AS newest_image_id, COALESCE(MAX(updated_at), '') AS newest_image_update FROM images");
    $stmt->execute();
    $imageRow = $stmt->fetch() ?: [];
    $stmt = db()->prepare("SELECT COUNT(*) AS gallery_count, COALESCE(MAX(updated_at), '') AS newest_gallery_update FROM galleries");
    $stmt->execute();
    $galleryRow = $stmt->fetch() ?: [];
    return [is_array($imageRow) ? $imageRow : [], is_array($galleryRow) ? $galleryRow : []];
}

/** Return image rows required by source/generated-media statistics. */
function admin_storage_statistics_model_image_rows(bool $hasDerivativeVersion): array
{
    $derivative = $hasDerivativeVersion ? 'COALESCE(i.thumbnail_derivative_version, 1)' : '1';
    $stmt = db()->prepare("SELECT i.id AS image_id, i.gallery_id AS image_gallery_id, i.relative_path, i.filename, i.mime_type, COALESCE(i.file_size, 0) AS file_size, i.width, i.height, $derivative AS thumbnail_derivative_version, g.id AS gallery_id, g.title AS gallery_title, g.folder_path AS gallery_folder_path FROM images i INNER JOIN galleries g ON g.id = i.gallery_id ORDER BY g.folder_path, i.relative_path");
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Read one ordered image batch with integer-bound cursor and row limit.
 * @param int $lastImageId Exclusive image ID cursor, normalized to zero or higher.
 * @param int $limit Positive service-owned maximum rows in this batch.
 * @param bool $hasDerivativeVersion Whether the installed derivative column exists.
 * @param bool $displayMastersOnly Select only DNG sources requiring display-master
 *     observation; false preserves the full-image filesystem fallback.
 * @return list<StorageStatisticsImageRow> Rows strictly after the cursor;
 *     an empty array means actual exhaustion, while database failures propagate.
 */
function admin_storage_statistics_model_image_rows_after_id(
    int $lastImageId,
    int $limit,
    bool $hasDerivativeVersion,
    bool $displayMastersOnly = false
): array {
    $derivative = $hasDerivativeVersion ? 'COALESCE(i.thumbnail_derivative_version, 1)' : '1';
    // Stored relative paths are canonical; match the case-insensitive DNG suffix
    // used by image_uses_dng_display_derivatives(), without reading originals.
    $displayPredicate = $displayMastersOnly
        ? " AND LOWER(RIGHT(COALESCE(i.relative_path, i.filename, ''), 4)) = '.dng'"
        : '';
    $stmt = db()->prepare("SELECT i.id AS image_id, i.gallery_id AS image_gallery_id, i.relative_path, i.filename, i.mime_type, COALESCE(i.file_size, 0) AS file_size, i.width, i.height, $derivative AS thumbnail_derivative_version, g.id AS gallery_id, g.title AS gallery_title, g.folder_path AS gallery_folder_path FROM images i INNER JOIN galleries g ON g.id = i.gallery_id WHERE i.id > ?$displayPredicate ORDER BY i.id LIMIT ?");
    // PDO execute(array) binds values as strings; emulated LIMIT needs integers.
    $stmt->bindValue(1, max(0, $lastImageId), \PDO::PARAM_INT);
    $stmt->bindValue(2, max(1, $limit), \PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Return durable thumbnail-variant aggregate rows for configured sizes. */
function admin_storage_statistics_model_thumbnail_summary_rows(array $sizes, bool $matchDerivativeVersion): array
{
    $sizes = array_values(array_unique(array_filter(array_map('intval', $sizes), static fn (int $size): bool => $size > 0)));
    if ($sizes === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($sizes), '?'));
    $derivativePredicate = $matchDerivativeVersion ? ' AND v.derivative_version = GREATEST(1, i.thumbnail_derivative_version)' : '';
    $stmt = db()->prepare("SELECT v.format, COUNT(*) AS variant_count, COALESCE(SUM(COALESCE(v.file_size, 0)), 0) AS total_bytes FROM image_thumbnail_variants v INNER JOIN images i ON i.id = v.image_id WHERE v.status = 'valid' AND v.format IN ('jpg', 'webp') AND v.size_px IN ($placeholders)$derivativePredicate GROUP BY v.format ORDER BY v.format");
    $stmt->execute($sizes);
    return $stmt->fetchAll();
}

/**
 * Count owners requiring their first database accounting import.
 * @return int Registered galleries with at least one missing category.
 */
function gallery_attachment_storage_model_pending_count(): int
{
    return storage_observer_call('db.attachment_pending_count', static function (): int {
        // All three structural category identities must exist, even when empty.
        $statement = db()->query(
            'SELECT COUNT(*) FROM galleries g WHERE'
            . " (SELECT COUNT(*) FROM gallery_attachment_storage s"
            . " WHERE s.gallery_id = g.id AND s.kind IN ('pdf', 'music', 'ofp')) < 3"
        );
        return (int) $statement->fetchColumn();
    });
}

/**
 * Remove a stale category after optional reporting persistence failed.
 * @param int $galleryId Registered physical owner.
 * @param string $kind Validated category to import on the next explicit update.
 * @return void Delete only derived accounting; original media remains intact.
 */
function gallery_attachment_storage_model_forget(int $galleryId, string $kind): void
{
    storage_observer_call('db.attachment_forget', static function () use ($galleryId, $kind): void {
        $statement = db()->prepare(
            'DELETE FROM gallery_attachment_storage WHERE gallery_id = ? AND kind = ?'
        );
        $statement->execute([$galleryId, $kind]);
    });
}

/**
 * Persist one category, preserving newer mutation totals during legacy import.
 * @param int $galleryId Registered physical owner.
 * @param string $kind Validated pdf, music or ofp category.
 * @param int $count Known original count, including zero for deletion.
 * @param int $bytes Known original bytes.
 * @param int $errors Accounting warnings for this category.
 * @param bool $onlyMissing Import only an absent row; default replaces totals.
 * @return void Persist totals or throw when accounting storage is unavailable.
 */
function gallery_attachment_storage_model_store(
    int $galleryId,
    string $kind,
    int $count,
    int $bytes,
    int $errors = 0,
    bool $onlyMissing = false
): void {
    storage_observer_call('db.attachment_store', static function () use (
        $galleryId, $kind, $count, $bytes, $errors, $onlyMissing
    ): void {
        $update = $onlyMissing ? 'gallery_id = VALUES(gallery_id)'
            : 'file_count = VALUES(file_count), file_bytes = VALUES(file_bytes),'
                . ' errors = VALUES(errors)';
        $statement = db()->prepare(
            'INSERT INTO gallery_attachment_storage'
            . ' (gallery_id, kind, file_count, file_bytes, errors) VALUES (?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE ' . $update
        );
        $statement->execute([$galleryId, $kind, $count, $bytes, $errors]);
    });
}

/**
 * Read physical owners with categories not yet imported, without disk access.
 * @param int $limit Maximum owners returned in one migration step.
 * @return list<array{id:int,folder_path:string,missing_kinds:list<string>}>
 *     Pending owners and their absent canonical category identities.
 */
function gallery_attachment_storage_model_pending(int $limit): array
{
    return storage_observer_call('db.attachment_pending_rows', static function () use ($limit): array {
        // Three structural category identities must exist, including empty totals.
        $statement = db()->prepare(
            'SELECT g.id, g.folder_path,'
            . ' (SELECT GROUP_CONCAT(s.kind) FROM gallery_attachment_storage s'
            . ' WHERE s.gallery_id = g.id) AS accounted_kinds'
            . ' FROM galleries g WHERE'
            . " (SELECT COUNT(*) FROM gallery_attachment_storage s"
            . " WHERE s.gallery_id = g.id AND s.kind IN ('pdf', 'music', 'ofp')) < 3"
            . ' ORDER BY g.id LIMIT ?'
        );
        $statement->bindValue(1, $limit, \PDO::PARAM_INT);
        $statement->execute();
        $owners = [];
        foreach ($statement->fetchAll() as $row) {
            $present = explode(',', (string) ($row['accounted_kinds'] ?? ''));
            $owners[] = ['id' => (int) $row['id'],
                'folder_path' => (string) $row['folder_path'],
                'missing_kinds' => array_values(array_diff(['pdf', 'music', 'ofp'], $present))];
        }
        return $owners;
    });
}

/**
 * Aggregate durable category totals without opening any gallery directory.
 * @return array{pdf_count:int,pdf_bytes:int,music_count:int,music_bytes:int,
 *     errors:int} Combined PDF/OFP and separate music totals.
 */
function gallery_attachment_storage_model_summary(): array
{
    return storage_observer_call('db.attachment_summary', static function (): array {
        $statement = db()->query(
            'SELECT s.kind, SUM(s.file_count) AS file_count,'
            . ' SUM(s.file_bytes) AS file_bytes, SUM(s.errors) AS errors'
            . ' FROM gallery_attachment_storage s'
            . ' INNER JOIN galleries g ON g.id = s.gallery_id GROUP BY s.kind'
        );
        $summary = ['pdf_count' => 0, 'pdf_bytes' => 0,
            'music_count' => 0, 'music_bytes' => 0, 'errors' => 0];
        foreach ($statement->fetchAll() as $row) {
            $kind = $row['kind'] === 'ofp' ? 'pdf' : (string) $row['kind'];
            if (!in_array($kind, ['pdf', 'music'], true)) continue;
            $summary[$kind . '_count'] += (int) $row['file_count'];
            $summary[$kind . '_bytes'] += (int) $row['file_bytes'];
            $summary['errors'] += (int) $row['errors'];
        }
        return $summary;
    });
}
