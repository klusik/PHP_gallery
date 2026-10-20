<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/admin_log_archives.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns database reads and verified cleanup for immutable Admin log archives.
 *
 * Responsibilities:
 *   - Locate the oldest archive-eligible Admin log day
 *   - Capture bounded day snapshots and keyset export batches
 *   - Count and delete only rows represented by verified archive manifests
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
 *   - Filesystem ZIP creation and verification remain service responsibilities.
 */

declare(strict_types=1);

namespace Gallery\Models;

use function Gallery\Core\db;

/**
 * Return the oldest completed day before one exclusive timestamp boundary.
 * @param string $eligibleBefore Exclusive SQL timestamp; earlier complete days are eligible.
 * @return ?string Oldest eligible SQL timestamp, or null when no earlier row exists.
 */
function admin_log_archive_model_oldest_eligible_created_at(string $eligibleBefore): ?string
{
    $stmt = db()->prepare(
        'SELECT MIN(created_at) FROM admin_logs WHERE created_at < ?',
    );
    $stmt->execute([$eligibleBefore]);
    $value = $stmt->fetchColumn();
    return is_string($value) && $value !== '' ? $value : null;
}

/**
 * Return aggregate metadata for one bounded Admin log archive day.
 * @param string $periodStart Inclusive SQL timestamp for the archive day.
 * @param string $periodEnd Exclusive SQL timestamp ending the archive day.
 * @return array{row_count:int|string,first_log_id:int|string|null,last_log_id:int|string|null,first_created_at:string|null,last_created_at:string|null} Aggregate counts and boundaries for the requested interval; an empty result is an empty array.
 */
function admin_log_archive_model_day_snapshot(
    string $periodStart,
    string $periodEnd,
): array {
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS row_count, MIN(id) AS first_log_id, MAX(id) AS last_log_id,'
            . ' MIN(created_at) AS first_created_at, MAX(created_at) AS last_created_at'
            . ' FROM admin_logs WHERE created_at >= ? AND created_at < ?',
    );
    $stmt->execute([$periodStart, $periodEnd]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : [];
}

/**
 * Return one bounded keyset batch represented by one archive snapshot.
 * @param array{period_start:string,period_end:string,last_log_id:int|string} $snapshot Verified archive-day bounds and final log id.
 * @param int $afterId Exclusive log-id keyset cursor.
 * @param int $limit Maximum batch size before the model safety clamp.
 * @return list<array<string,int|string|null>> Ordered log rows with username fields, or an empty list.
 */
function admin_log_archive_model_row_batch(
    array $snapshot,
    int $afterId,
    int $limit,
): array {
    $stmt = db()->prepare(
        'SELECT l.*, u.username FROM admin_logs l LEFT JOIN users u ON u.id = l.user_id'
            . ' WHERE l.created_at >= ? AND l.created_at < ? AND l.id > ? AND l.id <= ?'
            . ' ORDER BY l.id ASC LIMIT '
            . max(25, min(1000, $limit)),
    );
    $stmt->execute([
        (string) ($snapshot['period_start'] ?? ''),
        (string) ($snapshot['period_end'] ?? ''),
        max(0, $afterId),
        max(0, (int) ($snapshot['last_log_id'] ?? 0)),
    ]);
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

/**
 * Count database rows represented by one verified archive manifest.
 * @param array{period_start:string,period_end:string,first_log_id:int|string,last_log_id:int|string} $manifest Verified archive manifest bounds.
 * @return int Number of database rows still represented by the verified manifest.
 */
function admin_log_archive_model_remaining_rows(array $manifest): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM admin_logs WHERE created_at >= ? AND created_at < ? AND id >= ? AND id <= ?',
    );
    $stmt->execute([
        (string) ($manifest['period_start'] ?? ''),
        (string) ($manifest['period_end'] ?? ''),
        max(0, (int) ($manifest['first_log_id'] ?? 0)),
        max(0, (int) ($manifest['last_log_id'] ?? 0)),
    ]);
    return max(0, (int) $stmt->fetchColumn());
}

/**
 * Delete one bounded batch already represented by a verified archive manifest.
 * @param array{period_start:string,period_end:string,first_log_id:int|string,last_log_id:int|string} $manifest Verified archive manifest bounds.
 * @param int $limit Maximum rows to delete in this batch.
 * @return int Number of verified archive rows deleted in this batch.
 */
function admin_log_archive_model_delete_verified_batch(
    array $manifest,
    int $limit,
): int {
    $stmt = db()->prepare(
        'DELETE FROM admin_logs WHERE created_at >= ? AND created_at < ? AND id >= ? AND id <= ?'
            . ' ORDER BY id ASC LIMIT '
            . max(1, $limit),
    );
    $stmt->execute([
        (string) ($manifest['period_start'] ?? ''),
        (string) ($manifest['period_end'] ?? ''),
        max(0, (int) ($manifest['first_log_id'] ?? 0)),
        max(0, (int) ($manifest['last_log_id'] ?? 0)),
    ]);
    return max(0, (int) $stmt->rowCount());
}

/**
 * Count complete-day Admin log archival backlog before one exclusive boundary.
 *

 * @param string $eligibleBefore Exclusive SQL timestamp; only older rows are counted.
 * @return array{rows:int,days:int,oldest_created_at:?string} Read-only backlog summary.
 */
function admin_log_archive_model_eligible_summary(string $eligibleBefore): array
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS row_count, COUNT(DISTINCT DATE(created_at)) AS day_count, MIN(created_at) AS oldest_created_at'
            . ' FROM admin_logs WHERE created_at < ?',
    );
    $stmt->execute([$eligibleBefore]);
    $row = $stmt->fetch();
    return [
        'rows' => max(0, (int) ($row['row_count'] ?? 0)),
        'days' => max(0, (int) ($row['day_count'] ?? 0)),
        'oldest_created_at' => isset($row['oldest_created_at'])
        && is_string($row['oldest_created_at'])
            ? $row['oldest_created_at']
            : null,
    ];
}
