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
 *     Photo bytes have a separate 600 per IP and 3600 global allowance for
 *     five-minute bursts so thumbnails do not lock gallery navigation.
 *     Upload traffic uses independent 60-second windows/locks: 60 requests
 *     per grant/account/gallery, 120 originals per IP/global, and 120 inventory reads
 *     per grant/account/gallery, 240 per IP, 600 global. Account aggregates
 *     are 120 originals/240 inventory reads. This admits two clients uploading
 *     45 images/minute behind one IP while bounding expensive storage at
 *     120 originals/minute globally. The 240/IP and 600/global pre-auth guard
 *     includes both routes; it is not the authenticated image budget.
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
    'app_link_photo_ip' => [
        'kind' => 'ip', 'max_attempts' => 600,
        'window_seconds' => 300, 'lock_seconds' => 300,
    ],
    'app_link_photo_global' => [
        'kind' => 'global', 'max_attempts' => 3600,
        'window_seconds' => 300, 'lock_seconds' => 300,
    ],
    'app_link_upload_ip' => [
        'kind' => 'ip', 'max_attempts' => 120,
        'window_seconds' => 60, 'lock_seconds' => 60,
    ],
    'app_link_upload_global' => [
        'kind' => 'global', 'max_attempts' => 120,
        'window_seconds' => 60, 'lock_seconds' => 60,
    ],
    'app_link_transfer_ip' => [
        'kind' => 'ip', 'max_attempts' => 240,
        'window_seconds' => 60, 'lock_seconds' => 60,
    ],
    'app_link_transfer_global' => [
        'kind' => 'global', 'max_attempts' => 600,
        'window_seconds' => 60, 'lock_seconds' => 60,
    ],
    'app_link_inventory_ip' => [
        'kind' => 'ip', 'max_attempts' => 240,
        'window_seconds' => 60, 'lock_seconds' => 60,
    ],
    'app_link_inventory_global' => [
        'kind' => 'global', 'max_attempts' => 600,
        'window_seconds' => 60, 'lock_seconds' => 60,
    ],
    'app_link_upload_binding' => [
        'kind' => 'binding', 'max_attempts' => 60,
        'window_seconds' => 60, 'lock_seconds' => 60,
    ],
    'app_link_inventory_binding' => [
        'kind' => 'binding', 'max_attempts' => 120,
        'window_seconds' => 60, 'lock_seconds' => 60,
    ],
    'app_link_upload_account' => [
        'kind' => 'account', 'max_attempts' => 120,
        'window_seconds' => 60, 'lock_seconds' => 60,
    ],
    'app_link_inventory_account' => [
        'kind' => 'account', 'max_attempts' => 240,
        'window_seconds' => 60, 'lock_seconds' => 60,
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
 *     literal global, or trusted grant:account:gallery positive identifiers
 *     for the selected bucket's subject kind. Only HMAC digests are persisted.
 * @param int $cost Positive request/original work units; one preserves existing
 *     public endpoint accounting, and the policy ceiling bounds weighted work.
 * @return array{allowed:bool,retry_after_seconds:int,attempts:int,reason:string}
 *     Atomic admission result; invalid input, unavailable storage and capacity
 *     exhaustion refuse the operation without exposing sensitive diagnostics.
 */
function app_linking_rate_limit_consume(
    string $bucket,
    string $subject,
    int $cost = 1,
): array {
    $refused = [
        'allowed' => false,
        'retry_after_seconds' => 0,
        'attempts' => 0,
        'reason' => 'unavailable',
    ];
    $entry = APP_LINKING_RATE_POLICIES[$bucket] ?? null;
    if (!is_array($entry) || $cost < 1 || $cost > $entry['max_attempts']) {
        return $refused;
    }
    try {
        $kind = $entry['kind'];
        if ($kind === 'ip') {
            $normalized = request_client_ip_normalize($subject);
        } elseif ($kind === 'account') {
            $normalized = preg_match('/^[1-9][0-9]{0,19}$/D', $subject) === 1
                ? $subject : '';
        } elseif ($kind === 'binding') {
            $normalized = preg_match(
                '/^[1-9][0-9]{0,19}:[1-9][0-9]{0,19}:[1-9][0-9]{0,19}$/D',
                $subject,
            ) === 1 ? $subject : '';
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
            $cost,
        );
    } catch (Throwable) {
        // Missing schema, ambient transactions and storage failures all refuse.
        return $refused;
    }
}

/**
 * Charge independent authenticated inventory or original-upload budgets.
 *
 * @param string $operation Exact inventory or upload semantic operation.
 * @param int $grantId Currently authenticated delegated grant identifier.
 * @param int $accountId Currently authenticated account identifier.
 * @param int $galleryId Server-verified authorized gallery identifier.
 * @param string $clientIp Request-bound canonicalizable client address.
 * @param int $originalCount Bounded submitted originals for upload; inventory
 *     uses one request unit, regardless of its bounded descriptor collection.
 * @return array{allowed:bool,retry_after_seconds:int,attempts:int,reason:string}
 *     Actual first refusal, or the final successful bounded admission.
 */
function app_linking_upload_rate_admission(
    string $operation,
    int $grantId,
    int $accountId,
    int $galleryId,
    string $clientIp,
    int $originalCount = 1,
): array {
    if (!in_array($operation, ['inventory', 'upload'], true)
        || min($grantId, $accountId, $galleryId, $originalCount) <= 0
        || ($operation === 'inventory' && $originalCount !== 1)) {
        return [
            'allowed' => false, 'retry_after_seconds' => 0,
            'attempts' => 0, 'reason' => 'unavailable',
        ];
    }
    $prefix = 'app_link_' . $operation;
    $subjects = [
        $prefix . '_binding' => $grantId . ':' . $accountId . ':' . $galleryId,
        $prefix . '_account' => (string) $accountId,
        $prefix . '_ip' => $clientIp,
        $prefix . '_global' => 'global',
    ];
    foreach ($subjects as $bucket => $subject) {
        // The binding limits request bursts; existing multipart support must
        // still admit up to 100 originals. Aggregate storage budgets charge
        // every original atomically so multipart cannot multiply their load.
        $cost = $operation === 'upload' && $bucket !== $prefix . '_binding'
            ? $originalCount : 1;
        $decision = app_linking_rate_limit_consume($bucket, $subject, $cost);
        if (!$decision['allowed']) {
            return $decision;
        }
    }
    return $decision;
}
