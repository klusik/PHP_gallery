<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/viewer_rate_limits.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns atomic persistence for viewer anti-automation rate-limit buckets.
 *
 * Responsibilities:
 *   - Serialize new-subject creation through per-bucket counter row locks
 *   - Enforce bounded subject storage while consuming attempts atomically
 *   - Reclaim stale subjects and repair bucket counters
 *   - Keep SQL/PDO transaction ownership out of viewer rate-limit services
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
 *   - Bucket names are application-owned and validated by the service before model calls.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use RuntimeException;
use Throwable;
use function Gallery\Core\db;

/**
 * Consume one rate-limit attempt atomically.
 *
 * @param array{window_seconds:int,max_attempts:int,lock_seconds:int} $policy
 * @return array{allowed:bool,retry_after_seconds:int,attempts:int,reason:string}
 */
function viewer_rate_limit_model_consume(string $bucket, string $subjectHash, array $policy, int $subjectCap, string $now, int $nowTimestamp): array
{
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $pdo->prepare('INSERT INTO viewer_rate_limit_buckets (bucket, entry_count, updated_at) VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE updated_at = updated_at')
            ->execute([$bucket, $now]);

        $bucketStmt = $pdo->prepare('SELECT entry_count FROM viewer_rate_limit_buckets WHERE bucket = ? LIMIT 1 FOR UPDATE');
        $bucketStmt->execute([$bucket]);
        $entryCount = (int) $bucketStmt->fetchColumn();

        $rowStmt = $pdo->prepare('SELECT * FROM viewer_rate_limits WHERE bucket = ? AND subject_hash = ? LIMIT 1');
        $rowStmt->execute([$bucket, $subjectHash]);
        $row = $rowStmt->fetch();

        if (!$row && $entryCount >= $subjectCap) {
            $entryCount = viewer_rate_limit_model_cleanup_bucket_locked($bucket, date('Y-m-d H:i:s', $nowTimestamp - 86400), $now);
            if ($entryCount >= $subjectCap) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return ['allowed' => false, 'retry_after_seconds' => 60, 'attempts' => 0, 'reason' => 'storage_cap'];
            }
        }

        if (!$row) {
            $pdo->prepare('INSERT INTO viewer_rate_limits (bucket, subject_hash, attempts, first_attempt_at, last_attempt_at, locked_until) VALUES (?, ?, 1, ?, ?, NULL)')
                ->execute([$bucket, $subjectHash, $now, $now]);
            $pdo->prepare('UPDATE viewer_rate_limit_buckets SET entry_count = entry_count + 1, updated_at = ? WHERE bucket = ?')
                ->execute([$now, $bucket]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return ['allowed' => true, 'retry_after_seconds' => 0, 'attempts' => 1, 'reason' => 'ok'];
        }

        $lockedUntilTimestamp = !empty($row['locked_until']) ? strtotime((string) $row['locked_until']) : false;
        if ($lockedUntilTimestamp !== false && $lockedUntilTimestamp > $nowTimestamp) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return [
                'allowed' => false,
                'retry_after_seconds' => max(1, $lockedUntilTimestamp - $nowTimestamp),
                'attempts' => (int) $row['attempts'],
                'reason' => 'locked',
            ];
        }

        $firstAttemptTimestamp = strtotime((string) $row['first_attempt_at']);
        if ($firstAttemptTimestamp === false || $firstAttemptTimestamp < $nowTimestamp - (int) $policy['window_seconds']) {
            $pdo->prepare('UPDATE viewer_rate_limits SET attempts = 1, first_attempt_at = ?, last_attempt_at = ?, locked_until = NULL WHERE bucket = ? AND subject_hash = ?')
                ->execute([$now, $now, $bucket, $subjectHash]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return ['allowed' => true, 'retry_after_seconds' => 0, 'attempts' => 1, 'reason' => 'ok'];
        }

        $attempts = (int) $row['attempts'] + 1;
        $lockedUntil = $attempts > (int) $policy['max_attempts']
            ? date('Y-m-d H:i:s', $nowTimestamp + (int) $policy['lock_seconds'])
            : null;
        $pdo->prepare('UPDATE viewer_rate_limits SET attempts = ?, last_attempt_at = ?, locked_until = ? WHERE bucket = ? AND subject_hash = ?')
            ->execute([$attempts, $now, $lockedUntil, $bucket, $subjectHash]);

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return [
            'allowed' => $lockedUntil === null,
            'retry_after_seconds' => $lockedUntil === null ? 0 : (int) $policy['lock_seconds'],
            'attempts' => $attempts,
            'reason' => $lockedUntil === null ? 'ok' : 'locked',
        ];
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** Delete stale subjects from an already locked bucket and repair its counter. */
function viewer_rate_limit_model_cleanup_bucket_locked(string $bucket, string $cutoff, string $now): int
{
    $pdo = db();
    $pdo->prepare('DELETE FROM viewer_rate_limits WHERE bucket = ? AND last_attempt_at < ? AND (locked_until IS NULL OR locked_until < ?)')
        ->execute([$bucket, $cutoff, $now]);
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM viewer_rate_limits WHERE bucket = ?');
    $countStmt->execute([$bucket]);
    $count = (int) $countStmt->fetchColumn();
    $pdo->prepare('UPDATE viewer_rate_limit_buckets SET entry_count = ?, updated_at = ? WHERE bucket = ?')
        ->execute([$count, $now, $bucket]);
    return $count;
}

/** Lock and clean one fixed bucket in a self-owned transaction. */
function viewer_rate_limit_model_cleanup_bucket(string $bucket, string $cutoff, string $now): int
{
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare('INSERT INTO viewer_rate_limit_buckets (bucket, entry_count, updated_at) VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE updated_at = updated_at')
            ->execute([$bucket, $now]);
        $stmt = $pdo->prepare('SELECT entry_count FROM viewer_rate_limit_buckets WHERE bucket = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$bucket]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException('Viewer rate-limit bucket could not be locked.');
        }
        $count = viewer_rate_limit_model_cleanup_bucket_locked($bucket, $cutoff, $now);
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $count;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
