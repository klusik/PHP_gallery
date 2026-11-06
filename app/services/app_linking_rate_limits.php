<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/app_linking_rate_limits.php
 * Module Type: Service
 * Purpose: Limit app-linking attempts without enabling Viewer accounts.
 * Responsibilities:
 *   - Admit only fixed linking buckets and privacy-safe normalized subjects.
 *   - Fail closed on unavailable schema, invalid subjects or storage errors.
 *   - Use independently committed, bounded atomic rate-limit persistence.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

use Throwable;
use function Gallery\Models\app_link_model_rate_limit_consume;

/**
 * Bound distinct subjects retained in each isolated linking rate-limit bucket.
 * Type: int. Units: subject rows. Scope: one fixed app-linking bucket.
 * Consumers: app_linking_rate_limit_consume, app_link_model_rate_limit_consume.
 * Rationale: 512 bounds per-bucket work and state without relying on Viewer mode.
 */
const APP_LINKING_RATE_SUBJECT_CAP = 512;

/**
 * Bound stale subject cleanup during one admission to a full linking bucket.
 * Type: int. Units: subject rows. Scope: one linking rate-limit transaction.
 * Consumers: app_linking_rate_limit_consume, app_link_model_rate_limit_consume.
 * Rationale: 128 limits one cleanup while allowing recovery over several calls.
 */
const APP_LINKING_RATE_CLEANUP_BATCH = 128;

/**
 * Retain inactive linking rate-limit subjects beyond all configured locks.
 * Type: int. Units: seconds. Scope: stale linking rate-limit subjects.
 * Consumers: app_linking_rate_limit_consume.
 * Rationale: 86400 seconds exceeds the longest proposed lock and avoids early
 *     removal while allowing a full bucket to recover without a scheduler.
 */
const APP_LINKING_RATE_STALE_SECONDS = 86400;

/**
 * Define independently limited linking transport actions and subject kinds.
 * Type: array<string,array{kind:string,max_attempts:int,window_seconds:int,
 *     lock_seconds:int}>. Units: attempts and seconds. Scope: linking only.
 * Consumers: app_linking_rate_limit_consume.
 * Rationale: Five-minute windows cap public request volume, with tighter
 *     per-IP and per-Admin limits than the shared global circuit breakers.
 *     Identity reads permit a higher count because they are non-mutating.
 */
const APP_LINKING_RATE_POLICIES = [
    'app_link_start_ip' => [
        'kind' => 'ip', 'max_attempts' => 30,
        'window_seconds' => 300, 'lock_seconds' => 300,
    ],
    'app_link_start_global' => [
        'kind' => 'global', 'max_attempts' => 300,
        'window_seconds' => 300, 'lock_seconds' => 300,
    ],
    'app_link_claim_ip' => [
        'kind' => 'ip', 'max_attempts' => 30,
        'window_seconds' => 300, 'lock_seconds' => 300,
    ],
    'app_link_claim_global' => [
        'kind' => 'global', 'max_attempts' => 300,
        'window_seconds' => 300, 'lock_seconds' => 300,
    ],
    'app_link_claim_account' => [
        'kind' => 'account', 'max_attempts' => 20,
        'window_seconds' => 300, 'lock_seconds' => 300,
    ],
    'app_link_approve_account' => [
        'kind' => 'account', 'max_attempts' => 20,
        'window_seconds' => 300, 'lock_seconds' => 300,
    ],
    'app_link_exchange_ip' => [
        'kind' => 'ip', 'max_attempts' => 30,
        'window_seconds' => 300, 'lock_seconds' => 300,
    ],
    'app_link_exchange_global' => [
        'kind' => 'global', 'max_attempts' => 300,
        'window_seconds' => 300, 'lock_seconds' => 300,
    ],
    'app_link_identity_ip' => [
        'kind' => 'ip', 'max_attempts' => 120,
        'window_seconds' => 300, 'lock_seconds' => 300,
    ],
    'app_link_identity_global' => [
        'kind' => 'global', 'max_attempts' => 1200,
        'window_seconds' => 300, 'lock_seconds' => 300,
    ],
    'app_link_galleries_ip' => [
        'kind' => 'ip', 'max_attempts' => 120,
        'window_seconds' => 300, 'lock_seconds' => 300,
    ],
    'app_link_galleries_global' => [
        'kind' => 'global', 'max_attempts' => 1200,
        'window_seconds' => 300, 'lock_seconds' => 300,
    ],
    'app_link_upload_ip' => [
        'kind' => 'ip', 'max_attempts' => 60,
        'window_seconds' => 300, 'lock_seconds' => 300,
    ],
    'app_link_upload_global' => [
        'kind' => 'global', 'max_attempts' => 600,
        'window_seconds' => 300, 'lock_seconds' => 300,
    ],
];

/**
 * Consume one fixed linking rate-limit attempt without Viewer feature policy.
 *
 * Callers must independently consume every applicable IP, account and global
 * bucket before the protected operation. Subject values and installation key
 * material never reach logs or the persistence layer. Revocation intentionally
 * does not require this limiter, preserving its narrower fail-closed boundary.
 *
 * @param string $bucket One exact key from APP_LINKING_RATE_POLICIES.
 * @param string $subject Canonicalizable client IP, positive Admin identifier,
 *     or the literal global for the selected bucket's subject kind.
 * @return array{allowed:bool,retry_after_seconds:int,attempts:int,reason:string}
 *     Atomic admission result; invalid input, unavailable storage and capacity
 *     exhaustion refuse the operation without exposing sensitive diagnostics.
 */
function app_linking_rate_limit_consume(string $bucket, string $subject): array
{
    $refused = [
        'allowed' => false,
        'retry_after_seconds' => 0,
        'attempts' => 0,
        'reason' => 'unavailable',
    ];
    $entry = APP_LINKING_RATE_POLICIES[$bucket] ?? null;
    if (!is_array($entry)) {
        return $refused;
    }
    try {
        $kind = $entry['kind'];
        if ($kind === 'ip') {
            $normalized = request_client_ip_normalize($subject);
        } elseif ($kind === 'account') {
            $normalized = preg_match('/^[1-9][0-9]{0,19}$/D', $subject) === 1
                ? $subject : '';
        } else {
            $normalized = $subject === 'global' ? 'global' : '';
        }
        if ($normalized === '') {
            return $refused;
        }
        // Use a separate derived key; rotation starts fresh limiter subjects
        // without changing the unrelated Visitor Accounts configuration.
        $secret = app_link_push_key('rate');
        if (!schema_inspection_is_available(
            app_linking_rate_limit_schema_status(),
        )) {
            return $refused;
        }
        $subjectHash = hash_hmac(
            'sha256',
            'app-linking-rate' . "\0" . $bucket . "\0" . $normalized,
            $secret,
        );
        $nowTimestamp = time();
        return app_link_model_rate_limit_consume(
            $bucket,
            $subjectHash,
            [
                'window_seconds' => $entry['window_seconds'],
                'max_attempts' => $entry['max_attempts'],
                'lock_seconds' => $entry['lock_seconds'],
            ],
            APP_LINKING_RATE_SUBJECT_CAP,
            APP_LINKING_RATE_CLEANUP_BATCH,
            date('Y-m-d H:i:s', $nowTimestamp - APP_LINKING_RATE_STALE_SECONDS),
            date('Y-m-d H:i:s', $nowTimestamp),
            $nowTimestamp,
        );
    } catch (Throwable) {
        // Missing schema, ambient transactions and storage failures all refuse.
        return $refused;
    }
}
