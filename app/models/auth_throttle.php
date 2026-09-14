<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/auth_throttle.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns persistence for administrator authentication throttle buckets.
 *
 * Responsibilities:
 *   - Read and mutate hashed authentication throttle subjects
 *   - Remove expired throttle rows
 *   - Keep auth-rate-limit SQL outside the service policy layer
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Subjects passed to this model are already privacy-safe hashes.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use function Gallery\Core\db;

/**
 * Remove stale unlocked and expired locked throttle rows.
 *
 * @param string $inactiveBefore Rows older than this timestamp are stale.
 * @param string $now Current SQL timestamp.
 */
function auth_throttle_model_cleanup(string $inactiveBefore, string $now): void
{
    $stmt = db()->prepare('DELETE FROM auth_rate_limits WHERE locked_until IS NULL AND last_attempt_at < ?');
    $stmt->execute([$inactiveBefore]);

    $stmt = db()->prepare('DELETE FROM auth_rate_limits WHERE locked_until IS NOT NULL AND locked_until < ? AND last_attempt_at < ?');
    $stmt->execute([$now, $inactiveBefore]);
}

/**
 * Return one throttle row by bucket and privacy-safe subject hash.
 *
 * @param string $bucket Application-owned bucket identifier.
 * @param string $subjectHash Privacy-safe subject hash.
 * @return ?array<string,mixed> Matching row or null.
 */
function auth_throttle_model_row(string $bucket, string $subjectHash): ?array
{
    $stmt = db()->prepare('SELECT * FROM auth_rate_limits WHERE bucket = ? AND subject_hash = ? LIMIT 1');
    $stmt->execute([$bucket, $subjectHash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Start or reset a throttle window with one attempt.
 *
 * @param string $bucket Application-owned bucket identifier.
 * @param string $subjectHash Privacy-safe subject hash.
 * @param string $now Current SQL timestamp.
 */
function auth_throttle_model_reset_window(string $bucket, string $subjectHash, string $now): void
{
    $stmt = db()->prepare('INSERT INTO auth_rate_limits (bucket, subject_hash, attempts, first_attempt_at, last_attempt_at, locked_until) VALUES (?, ?, 1, ?, ?, NULL) ON DUPLICATE KEY UPDATE attempts = 1, first_attempt_at = VALUES(first_attempt_at), last_attempt_at = VALUES(last_attempt_at), locked_until = NULL');
    $stmt->execute([$bucket, $subjectHash, $now, $now]);
}

/**
 * Persist the current attempt count and optional lock timestamp.
 *
 * @param string $bucket Application-owned bucket identifier.
 * @param string $subjectHash Privacy-safe subject hash.
 * @param int $attempts Current attempt count.
 * @param string $now Current SQL timestamp.
 * @param ?string $lockedUntil Optional lock expiry timestamp.
 */
function auth_throttle_model_update_attempts(string $bucket, string $subjectHash, int $attempts, string $now, ?string $lockedUntil): void
{
    $stmt = db()->prepare('UPDATE auth_rate_limits SET attempts = ?, last_attempt_at = ?, locked_until = ? WHERE bucket = ? AND subject_hash = ?');
    $stmt->execute([$attempts, $now, $lockedUntil, $bucket, $subjectHash]);
}

/**
 * Remove one throttle subject after successful authentication.
 *
 * @param string $bucket Application-owned bucket identifier.
 * @param string $subjectHash Privacy-safe subject hash.
 */
function auth_throttle_model_clear(string $bucket, string $subjectHash): void
{
    $stmt = db()->prepare('DELETE FROM auth_rate_limits WHERE bucket = ? AND subject_hash = ?');
    $stmt->execute([$bucket, $subjectHash]);
}
