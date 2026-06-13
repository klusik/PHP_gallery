<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/auth_throttle.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Provides privacy-safe throttling for admin authentication and password reset requests.
 *
 * Responsibilities:
 *   - Store only hashed request subjects, never raw IP addresses or submitted identifiers
 *   - Limit repeated failed login attempts by visitor and submitted identifier
 *   - Limit password reset request volume by visitor and submitted identifier
 *   - Fail open when the optional migration has not been applied yet so updates remain reachable
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
 *   2026-05-11
 */

declare(strict_types=1);

namespace Gallery\Services;

use function Gallery\Core\cms_config;
use function Gallery\Core\db;
use function Gallery\Core\now_sql;
use function Gallery\Core\visitor_hash;

/**
 * Return true when the optional auth throttling table exists.
 *
 * @return bool True when the condition matches.
 */
function auth_throttle_schema_ready(): bool
{
    return function_exists('Gallery\\Services\\db_table_exists') && db_table_exists('auth_rate_limits');
}

/**
 * Return a secret used only for hashing throttle subjects.
 *
 * @return string Text result for the caller.
 */
function auth_throttle_secret(): string
{
    $config = cms_config();
    return (string) ($config['visitor_vote_secret'] ?? $config['setup_secret'] ?? 'php-gallery-auth-throttle');
}

/**
 * Normalize user-submitted identifiers before hashing them for rate limits.
 *
 * @param string $identifier Identifier value.
 * @return string Text result for the caller.
 */
function auth_throttle_normalize_identifier(string $identifier): string
{
    return trim(strtolower($identifier));
}

/**
 * Hash a throttle subject so raw IP addresses and submitted identifiers are never stored.
 *
 * @param string $subject Subject value.
 * @return string Text result for the caller.
 */
function auth_throttle_subject_hash(string $subject): string
{
    return hash('sha256', $subject . '|' . auth_throttle_secret());
}

/**
 * Return the current anonymous visitor hash used for visitor-level throttling.
 *
 * @return string Text result for the caller.
 */
function auth_throttle_visitor_subject(): string
{
    return function_exists('Gallery\\Core\\visitor_hash') ? visitor_hash() : auth_throttle_subject_hash((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
}

/**
 * Return bucket policy values for a throttled action.
 *
 * @param string $bucket Bucket value.
 * @return array Structured result data for the caller.
 */
function auth_throttle_policy(string $bucket): array
{
    $policies = [
        'admin_login_visitor' => ['max_attempts' => 10, 'window_seconds' => 900, 'lock_seconds' => 900],
        'admin_login_identifier' => ['max_attempts' => 6, 'window_seconds' => 900, 'lock_seconds' => 900],
        'password_reset_visitor' => ['max_attempts' => 5, 'window_seconds' => 1800, 'lock_seconds' => 1800],
        'password_reset_identifier' => ['max_attempts' => 3, 'window_seconds' => 3600, 'lock_seconds' => 3600],
    ];

    return $policies[$bucket] ?? ['max_attempts' => 10, 'window_seconds' => 900, 'lock_seconds' => 900];
}

/**
 * Remove expired throttle rows so the table stays small.
 */
function auth_throttle_cleanup(): void
{
    if (!auth_throttle_schema_ready()) {
        return;
    }

    $stmt = db()->prepare('DELETE FROM auth_rate_limits WHERE locked_until IS NULL AND last_attempt_at < ?');
    $stmt->execute([date('Y-m-d H:i:s', time() - 86400)]);

    $stmt = db()->prepare('DELETE FROM auth_rate_limits WHERE locked_until IS NOT NULL AND locked_until < ? AND last_attempt_at < ?');
    $stmt->execute([now_sql(), date('Y-m-d H:i:s', time() - 86400)]);
}

/**
 * Return the current throttle row for a bucket and hashed subject.
 *
 * @param string $bucket Bucket value.
 * @param string $subjectHash Subject hash value.
 * @return ?array Structured result data for the caller.
 */
function auth_throttle_row(string $bucket, string $subjectHash): ?array
{
    if (!auth_throttle_schema_ready()) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM auth_rate_limits WHERE bucket = ? AND subject_hash = ? LIMIT 1');
    $stmt->execute([$bucket, $subjectHash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Check whether an action is currently allowed for a bucket and subject.
 *
 * @param string $bucket Bucket value.
 * @param string $subject Subject value.
 * @return array Structured result data for the caller.
 */
function auth_throttle_check(string $bucket, string $subject): array
{
    if (!auth_throttle_schema_ready()) {
        return ['allowed' => true, 'retry_after_seconds' => 0, 'attempts' => 0];
    }

    auth_throttle_cleanup();

    $subjectHash = auth_throttle_subject_hash($subject);
    $row = auth_throttle_row($bucket, $subjectHash);
    if (!$row) {
        return ['allowed' => true, 'retry_after_seconds' => 0, 'attempts' => 0];
    }

    $lockedUntil = (string) ($row['locked_until'] ?? '');
    if ($lockedUntil !== '' && strtotime($lockedUntil) > time()) {
        return [
            'allowed' => false,
            'retry_after_seconds' => max(1, strtotime($lockedUntil) - time()),
            'attempts' => (int) $row['attempts'],
        ];
    }

    return ['allowed' => true, 'retry_after_seconds' => 0, 'attempts' => (int) $row['attempts']];
}

/**
 * Record one failed or counted authentication-related attempt.
 *
 * @param string $bucket Bucket value.
 * @param string $subject Subject value.
 */
function auth_throttle_record_attempt(string $bucket, string $subject): void
{
    if (!auth_throttle_schema_ready()) {
        return;
    }

    $policy = auth_throttle_policy($bucket);
    $subjectHash = auth_throttle_subject_hash($subject);
    $row = auth_throttle_row($bucket, $subjectHash);
    $now = now_sql();

    if (!$row || strtotime((string) $row['first_attempt_at']) < time() - (int) $policy['window_seconds']) {
        $stmt = db()->prepare('INSERT INTO auth_rate_limits (bucket, subject_hash, attempts, first_attempt_at, last_attempt_at, locked_until) VALUES (?, ?, 1, ?, ?, NULL) ON DUPLICATE KEY UPDATE attempts = 1, first_attempt_at = VALUES(first_attempt_at), last_attempt_at = VALUES(last_attempt_at), locked_until = NULL');
        $stmt->execute([$bucket, $subjectHash, $now, $now]);
        return;
    }

    $attempts = (int) $row['attempts'] + 1;
    $lockedUntil = $attempts >= (int) $policy['max_attempts']
        ? date('Y-m-d H:i:s', time() + (int) $policy['lock_seconds'])
        : null;

    $stmt = db()->prepare('UPDATE auth_rate_limits SET attempts = ?, last_attempt_at = ?, locked_until = ? WHERE bucket = ? AND subject_hash = ?');
    $stmt->execute([$attempts, $now, $lockedUntil, $bucket, $subjectHash]);
}

/**
 * Clear throttle rows after a successful login.
 *
 * @param string $bucket Bucket value.
 * @param string $subject Subject value.
 */
function auth_throttle_clear(string $bucket, string $subject): void
{
    if (!auth_throttle_schema_ready()) {
        return;
    }

    $stmt = db()->prepare('DELETE FROM auth_rate_limits WHERE bucket = ? AND subject_hash = ?');
    $stmt->execute([$bucket, auth_throttle_subject_hash($subject)]);
}

/**
 * Log a rate-limit event without storing raw IP addresses or submitted identifiers.
 *
 * @param string $eventKey Event key value.
 * @param string $message Message value.
 * @param string $bucket Bucket value.
 * @param string $subject Subject value.
 * @param array $check Check value.
 */
function auth_throttle_log(string $eventKey, string $message, string $bucket, string $subject, array $check): void
{
    if (!function_exists('Gallery\\Services\\admin_log_event')) {
        return;
    }

    admin_log_event('warning', $eventKey, $message, [
        'bucket' => $bucket,
        'subject_sha256' => auth_throttle_subject_hash($subject),
        'attempts' => (int) ($check['attempts'] ?? 0),
        'retry_after_seconds' => (int) ($check['retry_after_seconds'] ?? 0),
        'visitor_hash' => function_exists('Gallery\\Core\\visitor_hash') ? visitor_hash() : '',
        'request_id' => function_exists('telemetry_request_id') ? telemetry_request_id() : '',
    ], ['category' => 'security', 'severity' => 'warning']);
}
