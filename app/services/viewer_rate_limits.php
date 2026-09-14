<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/viewer_rate_limits.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Provides bounded server-side abuse throttling foundations for future viewer actions.
 *
 * Responsibilities:
 *   - Rate limit only allowlisted viewer security actions
 *   - Hash normalized IP/identifier/account subjects instead of storing them raw
 *   - Serialize per-bucket row admission so attacker-controlled identifiers cannot grow storage without bound
 *   - Support per-IP, subnet, identifier, account, and global circuit-breaker subjects
 *   - Keep existing admin auth_rate_limits behavior untouched
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
 *   - No viewer route consumes these limits in Phase 0.
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

namespace Gallery\Services;

use InvalidArgumentException;
use function Gallery\Core\now_sql;
use function Gallery\Models\viewer_rate_limit_model_cleanup_bucket;
use function Gallery\Models\viewer_rate_limit_model_cleanup_bucket_locked;
use function Gallery\Models\viewer_rate_limit_model_consume;

/**
 * Return the allowlisted viewer rate-limit policies.
 *
 * @return array<string,array{max_attempts:int,window_seconds:int,lock_seconds:int}> Policy map.
 */
function viewer_rate_limit_policies(): array
{
    $config = viewer_accounts_config();

    return [
        'viewer_register_ip' => ['max_attempts' => 5, 'window_seconds' => 3600, 'lock_seconds' => 3600],
        'viewer_register_subnet' => ['max_attempts' => 20, 'window_seconds' => 3600, 'lock_seconds' => 3600],
        'viewer_register_identifier' => ['max_attempts' => 3, 'window_seconds' => 3600, 'lock_seconds' => 3600],
        'viewer_register_global_day' => [
            'max_attempts' => (int) $config['registration_global_daily_limit'],
            'window_seconds' => 86400,
            'lock_seconds' => 3600,
        ],
        'viewer_login_ip' => ['max_attempts' => 30, 'window_seconds' => 900, 'lock_seconds' => 900],
        'viewer_login_subnet' => ['max_attempts' => 100, 'window_seconds' => 900, 'lock_seconds' => 900],
        'viewer_login_identifier' => ['max_attempts' => 10, 'window_seconds' => 900, 'lock_seconds' => 900],
        'viewer_login_global' => ['max_attempts' => 300, 'window_seconds' => 60, 'lock_seconds' => 60],
        'viewer_verify_email_ip' => ['max_attempts' => 20, 'window_seconds' => 1800, 'lock_seconds' => 1800],
        'viewer_resend_verification_identifier' => ['max_attempts' => 3, 'window_seconds' => 3600, 'lock_seconds' => 3600],
        'viewer_automation_ip' => ['max_attempts' => 8, 'window_seconds' => 600, 'lock_seconds' => 900],
        'viewer_automation_subnet' => ['max_attempts' => 48, 'window_seconds' => 600, 'lock_seconds' => 900],
        'viewer_password_reset_ip' => ['max_attempts' => 10, 'window_seconds' => 3600, 'lock_seconds' => 3600],
        'viewer_password_reset_subnet' => ['max_attempts' => 30, 'window_seconds' => 3600, 'lock_seconds' => 3600],
        'viewer_password_reset_identifier' => ['max_attempts' => 3, 'window_seconds' => 3600, 'lock_seconds' => 3600],
        'viewer_password_reset_global' => ['max_attempts' => 100, 'window_seconds' => 3600, 'lock_seconds' => 3600],
        'viewer_collection_create_account' => ['max_attempts' => 10, 'window_seconds' => 3600, 'lock_seconds' => 3600],
        'viewer_share_create_account' => ['max_attempts' => 30, 'window_seconds' => 3600, 'lock_seconds' => 3600],
        'viewer_verify_mail_email_cooldown' => [
            'max_attempts' => 1,
            'window_seconds' => (int) $config['verification_mail_email_cooldown_seconds'],
            'lock_seconds' => (int) $config['verification_mail_email_cooldown_seconds'],
        ],
        'viewer_verify_mail_email_hour' => [
            'max_attempts' => (int) $config['verification_mail_email_hourly_limit'],
            'window_seconds' => 3600,
            'lock_seconds' => 3600,
        ],
        'viewer_verify_mail_email_day' => [
            'max_attempts' => (int) $config['verification_mail_email_daily_limit'],
            'window_seconds' => 86400,
            'lock_seconds' => 3600,
        ],
        'viewer_verify_mail_ip_hour' => [
            'max_attempts' => (int) $config['verification_mail_ip_hourly_limit'],
            'window_seconds' => 3600,
            'lock_seconds' => 3600,
        ],
        'viewer_verify_mail_ip_day' => [
            'max_attempts' => (int) $config['verification_mail_ip_daily_limit'],
            'window_seconds' => 86400,
            'lock_seconds' => 3600,
        ],
        'viewer_verify_mail_subnet_hour' => [
            'max_attempts' => (int) $config['verification_mail_subnet_hourly_limit'],
            'window_seconds' => 3600,
            'lock_seconds' => 3600,
        ],
        'viewer_verify_mail_subnet_day' => [
            'max_attempts' => (int) $config['verification_mail_subnet_daily_limit'],
            'window_seconds' => 86400,
            'lock_seconds' => 3600,
        ],
        'viewer_verify_mail_global_day' => [
            'max_attempts' => (int) $config['verification_mail_global_daily_limit'],
            'window_seconds' => 86400,
            'lock_seconds' => 3600,
        ],
        'viewer_reset_mail_email_cooldown' => [
            'max_attempts' => 1,
            'window_seconds' => (int) $config['password_reset_mail_email_cooldown_seconds'],
            'lock_seconds' => (int) $config['password_reset_mail_email_cooldown_seconds'],
        ],
        'viewer_reset_mail_email_hour' => [
            'max_attempts' => (int) $config['password_reset_mail_email_hourly_limit'],
            'window_seconds' => 3600,
            'lock_seconds' => 3600,
        ],
        'viewer_reset_mail_email_day' => [
            'max_attempts' => (int) $config['password_reset_mail_email_daily_limit'],
            'window_seconds' => 86400,
            'lock_seconds' => 3600,
        ],
        'viewer_reset_mail_ip_hour' => [
            'max_attempts' => (int) $config['password_reset_mail_ip_hourly_limit'],
            'window_seconds' => 3600,
            'lock_seconds' => 3600,
        ],
        'viewer_reset_mail_ip_day' => [
            'max_attempts' => (int) $config['password_reset_mail_ip_daily_limit'],
            'window_seconds' => 86400,
            'lock_seconds' => 3600,
        ],
        'viewer_reset_mail_subnet_hour' => [
            'max_attempts' => (int) $config['password_reset_mail_subnet_hourly_limit'],
            'window_seconds' => 3600,
            'lock_seconds' => 3600,
        ],
        'viewer_reset_mail_subnet_day' => [
            'max_attempts' => (int) $config['password_reset_mail_subnet_daily_limit'],
            'window_seconds' => 86400,
            'lock_seconds' => 3600,
        ],
        'viewer_reset_mail_global_day' => [
            'max_attempts' => (int) $config['password_reset_mail_global_daily_limit'],
            'window_seconds' => 86400,
            'lock_seconds' => 3600,
        ],
        'viewer_invite_mail_email_day' => [
            'max_attempts' => (int) $config['invitation_mail_email_daily_limit'],
            'window_seconds' => 86400,
            'lock_seconds' => 3600,
        ],
        'viewer_invite_mail_global_day' => [
            'max_attempts' => (int) $config['invitation_mail_global_daily_limit'],
            'window_seconds' => 86400,
            'lock_seconds' => 3600,
        ],
        'viewer_global' => ['max_attempts' => 1000, 'window_seconds' => 60, 'lock_seconds' => 60],
    ];
}
/**
 * Return one allowlisted rate-limit policy or null for an unknown bucket.
 *
 * @param string $bucket Fixed application-owned bucket name.
 * @return ?array{max_attempts:int,window_seconds:int,lock_seconds:int} Policy or null.
 */
function viewer_rate_limit_policy(string $bucket): ?array
{
    $policies = viewer_rate_limit_policies();
    return $policies[$bucket] ?? null;
}

/**
 * Normalize an IPv4/IPv6 address into a coarse subnet subject.
 *
 * IPv4 uses /24 and IPv6 uses /64. This is intentionally a secondary abuse
 * signal rather than a replacement for exact-IP limiting.
 *
 * @param string $ip Candidate client IP.
 * @return string Canonical subnet subject or an empty string when invalid.
 */
function viewer_rate_limit_subnet_subject(string $ip): string
{
    $normalized = request_client_ip_normalize($ip);
    if ($normalized === '') {
        return '';
    }
    $packed = inet_pton($normalized);
    if ($packed === false) {
        return '';
    }

    if (strlen($packed) === 4) {
        $network = substr($packed, 0, 3) . "\0";
        return (string) inet_ntop($network) . '/24';
    }

    $network = substr($packed, 0, 8) . str_repeat("\0", 8);
    return (string) inet_ntop($network) . '/64';
}

/**
 * Normalize one rate-limit subject so trivial case/whitespace changes do not bypass limits.
 *
 * @param string $kind One of ip, subnet, identifier, account, or global.
 * @param string $subject Raw subject value.
 * @return string Normalized subject or an empty string when invalid.
 */
function viewer_rate_limit_normalize_subject(string $kind, string $subject): string
{
    $kind = strtolower(trim($kind));
    if ($kind === 'ip') {
        return request_client_ip_normalize($subject);
    }
    if ($kind === 'subnet') {
        return viewer_rate_limit_subnet_subject($subject);
    }
    if ($kind === 'identifier') {
        $normalized = strtolower(trim($subject));
        return strlen($normalized) <= 512 ? $normalized : substr($normalized, 0, 512);
    }
    if ($kind === 'account') {
        $accountId = (int) trim($subject);
        return $accountId > 0 ? (string) $accountId : '';
    }
    if ($kind === 'global') {
        return 'global';
    }
    return '';
}

/**
 * Hash a normalized viewer rate-limit subject with an installation-specific HMAC key.
 *
 * @param string $kind Subject kind.
 * @param string $subject Raw subject value.
 * @return string HMAC digest or an empty string when the subject is invalid.
 */
function viewer_rate_limit_subject_hash(string $kind, string $subject): string
{
    $normalized = viewer_rate_limit_normalize_subject($kind, $subject);
    return $normalized === '' ? '' : viewer_security_fingerprint('viewer-rate-' . strtolower(trim($kind)), $normalized);
}

/**
 * Return the hard maximum number of distinct subject rows admitted to one viewer bucket.
 *
 * @return int Bounded per-bucket row cap.
 */
function viewer_rate_limit_subject_cap(): int
{
    return (int) viewer_accounts_config()['rate_limit_max_subjects_per_bucket'];
}

/**
 * Return the three-state schema capability for viewer rate-limit storage.
 *
 * @return array Aggregate schema inspection result.
 */
function viewer_rate_limit_schema_status(): array
{
    return schema_inspection_feature('viewer.rate_limits', [
        schema_inspection_table('viewer_rate_limit_buckets'),
        schema_inspection_table('viewer_rate_limits'),
    ]);
}

/**
 * Return true only when viewer rate-limit storage is confirmed available.
 *
 * @return bool True only for confirmed available storage.
 */
function viewer_rate_limit_storage_available(): bool
{
    return schema_inspection_is_available(viewer_rate_limit_schema_status());
}

/**
 * Consume one viewer rate-limit attempt atomically and return the resulting decision.
 *
 * A locked per-bucket counter row serializes creation of new subject rows. This
 * makes the configured subject cap a hard database-growth boundary even when an
 * attacker submits unlimited random identifiers concurrently.
 *
 * max_attempts is the number of attempts that may pass inside one window. The
 * following attempt establishes the temporary lock.
 *
 * @param string $bucket Allowlisted application-owned rate-limit bucket.
 * @param string $subjectKind Subject kind: ip, subnet, identifier, account, or global.
 * @param string $subject Raw subject value.
 * @return array{allowed:bool,retry_after_seconds:int,attempts:int,reason:string} Decision.
 */
function viewer_rate_limit_consume(string $bucket, string $subjectKind, string $subject): array
{
    if (!viewer_accounts_enabled()) {
        return ['allowed' => false, 'retry_after_seconds' => 0, 'attempts' => 0, 'reason' => 'viewer_disabled'];
    }
    if (!viewer_rate_limit_storage_available()) {
        return ['allowed' => false, 'retry_after_seconds' => 0, 'attempts' => 0, 'reason' => 'storage_unavailable'];
    }

    $policy = viewer_rate_limit_policy($bucket);
    $subjectHash = viewer_rate_limit_subject_hash($subjectKind, $subject);
    if ($policy === null || $subjectHash === '') {
        throw new InvalidArgumentException('Viewer rate-limit bucket or subject is invalid.');
    }

    $nowTimestamp = time();
    return viewer_rate_limit_model_consume(
        $bucket,
        $subjectHash,
        $policy,
        viewer_rate_limit_subject_cap(),
        now_sql(),
        $nowTimestamp
    );
}

/**
 * Delete stale subject rows for one already-locked bucket and repair its counter.
 *
 * The caller must hold the viewer_rate_limit_buckets row lock. Rows inactive for
 * one day are no longer useful for current viewer policies and can be reclaimed.
 *
 * @param string $bucket Allowlisted bucket whose counter row is locked.
 * @return int Remaining subject row count.
 */
function viewer_rate_limit_cleanup_bucket_locked(string $bucket): int
{
    if (viewer_rate_limit_policy($bucket) === null) {
        throw new InvalidArgumentException('Unknown viewer rate-limit bucket.');
    }

    $nowTimestamp = time();
    $cutoff = date('Y-m-d H:i:s', $nowTimestamp - 86400);
    return viewer_rate_limit_model_cleanup_bucket_locked($bucket, $cutoff, now_sql());
}

/**
 * Clean stale viewer rate-limit rows for every fixed bucket in bounded maintenance work.
 *
 * @return array<string,int> Remaining row counts by bucket.
 */
function viewer_rate_limit_cleanup(): array
{
    if (!viewer_rate_limit_storage_available()) {
        return [];
    }

    $counts = [];
    $nowTimestamp = time();
    $cutoff = date('Y-m-d H:i:s', $nowTimestamp - 86400);
    foreach (array_keys(viewer_rate_limit_policies()) as $bucket) {
        $counts[$bucket] = viewer_rate_limit_model_cleanup_bucket($bucket, $cutoff, now_sql());
    }
    return $counts;
}
