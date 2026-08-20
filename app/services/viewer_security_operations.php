<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/viewer_security_operations.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Provides privacy-safe, read-only administrator operations visibility for Viewer security.
 *
 * Responsibilities:
 *   - Normalize current Viewer registration/security capability state
 *   - Summarize Viewer account and staged-registration capacity without mutating counters
 *   - Aggregate fixed Phase 4 security-event keys over bounded 24-hour and 7-day windows
 *   - Build one seven-calendar-day activity trend from aggregate database queries
 *   - Summarize current Viewer rate-limit pressure without consuming or resetting limits
 *   - Keep public telemetry, visitor identifiers, and security authority outside the operations surface
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
 *   - This service is read-only. It must never call viewer_rate_limit_consume() or Viewer mutation helpers.
 *   - Aggregate output intentionally excludes email, IP, hashes, request ids, user agents, and event context JSON.
 *
 * Last Updated:
 *   2026-08-20
 */

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;
use function Gallery\Core\db;

const VIEWER_SECURITY_OPERATIONS_STATUS_AVAILABLE = 'available';
const VIEWER_SECURITY_OPERATIONS_STATUS_UNAVAILABLE = 'unavailable';
const VIEWER_SECURITY_OPERATIONS_STATUS_UNKNOWN = 'unknown';

/**
 * Return the fixed Phase 4 event categories exposed through administrator operations.
 *
 * @return array<string,string> Metric key to persisted event key.
 */
function viewer_security_operations_event_keys(): array
{
    return [
        'accepted_registrations' => 'viewer.registration_requested',
        'verification_messages_sent' => 'viewer.verification_sent',
        'verification_resend_requests' => 'viewer.verification_resend_requested',
        'verification_resend_messages_sent' => 'viewer.verification_resent',
        'verification_resend_suppressed' => 'viewer.verification_resend_suppressed',
        'automation_challenges_required' => 'viewer.automation_challenge_required',
        'automation_challenges_passed' => 'viewer.automation_challenge_passed',
        'automation_challenges_failed' => 'viewer.automation_challenge_failed',
        'automation_requests_suppressed' => 'viewer.automation_request_suppressed',
    ];
}

/**
 * Return the fixed event subset used by the seven-day daily trend.
 *
 * Anti-automation interventions deliberately mean challenge-required plus request-suppressed.
 * Passed and failed challenge events are not added to that intervention total.
 *
 * @return array<int,string> Persisted event keys used by the daily trend.
 */
function viewer_security_operations_trend_event_keys(): array
{
    return [
        'viewer.registration_requested',
        'viewer.verification_sent',
        'viewer.verification_resent',
        'viewer.automation_challenge_required',
        'viewer.automation_request_suppressed',
    ];
}

/**
 * Return the fixed Phase 4 limiter groups exposed through administrator operations.
 *
 * Bucket names remain application-owned and must also exist in viewer_rate_limit_policies().
 *
 * @return array<string,array<int,string>> Group key to allowlisted bucket names.
 */
function viewer_security_operations_rate_limit_groups(): array
{
    return [
        'registration' => [
            'viewer_register_ip',
            'viewer_register_subnet',
            'viewer_register_identifier',
            'viewer_register_global_day',
        ],
        'verification_resend' => [
            'viewer_resend_verification_identifier',
        ],
        'anti_automation' => [
            'viewer_automation_ip',
            'viewer_automation_subnet',
        ],
        'verification_mail' => [
            'viewer_verify_mail_email_cooldown',
            'viewer_verify_mail_email_hour',
            'viewer_verify_mail_email_day',
            'viewer_verify_mail_ip_hour',
            'viewer_verify_mail_ip_day',
            'viewer_verify_mail_subnet_hour',
            'viewer_verify_mail_subnet_day',
            'viewer_verify_mail_global_day',
        ],
    ];
}

/**
 * Normalize one three-state schema inspection result for operations display.
 *
 * Confirmed missing schema is displayed as unavailable. Inspection failures remain unknown.
 *
 * @param array $schemaStatus Existing schema-inspection feature result.
 * @return string One of available, unavailable, or unknown.
 */
function viewer_security_operations_schema_state(array $schemaStatus): string
{
    $state = (string) ($schemaStatus['state'] ?? '');
    if ($state === SCHEMA_INSPECTION_AVAILABLE) {
        return VIEWER_SECURITY_OPERATIONS_STATUS_AVAILABLE;
    }
    if ($state === SCHEMA_INSPECTION_MISSING) {
        return VIEWER_SECURITY_OPERATIONS_STATUS_UNAVAILABLE;
    }
    return VIEWER_SECURITY_OPERATIONS_STATUS_UNKNOWN;
}

/**
 * Return a bounded current Viewer security/capability snapshot without mutation.
 *
 * @return array<string,mixed> Privacy-safe current state.
 */
function viewer_security_operations_status_snapshot(): array
{
    $config = viewer_accounts_config();

    return [
        'master_feature_enabled' => viewer_accounts_master_feature_enabled(),
        'registration_mode' => viewer_registration_mode(),
        'open_registration_http_available' => viewer_http_open_registration_available(),
        'verification_resend_http_available' => viewer_http_verification_resend_available(),
        'anti_automation' => [
            'enabled' => viewer_anti_automation_enabled(),
            'min_form_age_seconds' => (int) $config['anti_automation_min_form_age_seconds'],
            'form_lifetime_seconds' => (int) $config['anti_automation_form_lifetime_seconds'],
            'pow_min_bits' => (int) $config['anti_automation_pow_min_bits'],
            'pow_max_bits' => (int) $config['anti_automation_pow_max_bits'],
        ],
        'storage' => [
            'viewer_auth' => viewer_security_operations_schema_state(viewer_auth_schema_status()),
            'viewer_registration' => viewer_security_operations_schema_state(viewer_registration_schema_status()),
            'viewer_security_events' => viewer_security_operations_schema_state(viewer_security_event_schema_status()),
            'viewer_rate_limits' => viewer_security_operations_schema_state(viewer_rate_limit_schema_status()),
        ],
    ];
}

/**
 * Return current durable-account and staged-registration capacity from authoritative storage.
 *
 * The persisted singleton counters are read for consistency diagnostics only. Displayed current
 * counts come from the owning tables so a missing lazy counter row is never confused with zero.
 * No reconciliation or cleanup runs from this read path.
 *
 * @return array<string,mixed> Capacity values or explicit unavailable/unknown status.
 */
function viewer_security_operations_capacity_snapshot(): array
{
    $accountsStatus = viewer_security_operations_schema_state(viewer_admin_account_schema_status());
    $registrationsStatus = viewer_security_operations_schema_state(viewer_registration_schema_status());
    $result = [
        'accounts' => [
            'status' => $accountsStatus,
            'current_count' => null,
            'hard_cap' => viewer_account_cap(),
            'capacity_counter_count' => null,
            'capacity_counter_consistent' => null,
        ],
        'registrations' => [
            'status' => $registrationsStatus,
            'current_count' => null,
            'hard_cap' => viewer_registration_request_cap(),
            'open_origin_count' => null,
            'invitation_backed_count' => null,
            'capacity_counter_count' => null,
            'capacity_counter_consistent' => null,
        ],
    ];

    if ($accountsStatus === VIEWER_SECURITY_OPERATIONS_STATUS_AVAILABLE) {
        try {
            $stmt = db()->prepare(
                'SELECT COUNT(*) AS current_count, '
                . '(SELECT account_count FROM viewer_account_state WHERE state_key = ? LIMIT 1) AS capacity_counter_count '
                . 'FROM viewer_accounts'
            );
            $stmt->execute([VIEWER_ACCOUNT_CAPACITY_STATE_KEY]);
            $row = $stmt->fetch() ?: [];
            $currentCount = (int) ($row['current_count'] ?? 0);
            $counterRaw = $row['capacity_counter_count'] ?? null;
            $counterCount = $counterRaw === null ? null : (int) $counterRaw;
            $result['accounts']['current_count'] = $currentCount;
            $result['accounts']['capacity_counter_count'] = $counterCount;
            $result['accounts']['capacity_counter_consistent'] = $counterCount === null
                ? null
                : $counterCount === $currentCount;
        } catch (Throwable) {
            $result['accounts']['status'] = VIEWER_SECURITY_OPERATIONS_STATUS_UNKNOWN;
        }
    }

    if ($registrationsStatus === VIEWER_SECURITY_OPERATIONS_STATUS_AVAILABLE) {
        try {
            $stmt = db()->prepare(
                'SELECT COUNT(*) AS current_count, '
                . 'COALESCE(SUM(CASE WHEN viewer_invitation_id IS NULL THEN 1 ELSE 0 END), 0) AS open_origin_count, '
                . 'COALESCE(SUM(CASE WHEN viewer_invitation_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS invitation_backed_count, '
                . '(SELECT active_request_count FROM viewer_registration_state WHERE state_key = ? LIMIT 1) AS capacity_counter_count '
                . 'FROM viewer_registration_requests'
            );
            $stmt->execute([VIEWER_REGISTRATION_STATE_KEY]);
            $row = $stmt->fetch() ?: [];
            $currentCount = (int) ($row['current_count'] ?? 0);
            $counterRaw = $row['capacity_counter_count'] ?? null;
            $counterCount = $counterRaw === null ? null : (int) $counterRaw;
            $result['registrations']['current_count'] = $currentCount;
            $result['registrations']['open_origin_count'] = (int) ($row['open_origin_count'] ?? 0);
            $result['registrations']['invitation_backed_count'] = (int) ($row['invitation_backed_count'] ?? 0);
            $result['registrations']['capacity_counter_count'] = $counterCount;
            $result['registrations']['capacity_counter_consistent'] = $counterCount === null
                ? null
                : $counterCount === $currentCount;
        } catch (Throwable) {
            $result['registrations']['status'] = VIEWER_SECURITY_OPERATIONS_STATUS_UNKNOWN;
        }
    }

    return $result;
}

/**
 * Return the fixed rolling-window and seven-calendar-day event aggregates.
 *
 * @param ?int $nowTimestamp Optional deterministic current time for focused tests.
 * @return array<string,mixed> Aggregate event metrics only, never individual event rows.
 */
function viewer_security_operations_event_snapshot(?int $nowTimestamp = null): array
{
    $status = viewer_security_operations_schema_state(viewer_security_event_schema_status());
    $eventKeys = viewer_security_operations_event_keys();
    $emptyWindow = array_fill_keys(array_keys($eventKeys), null);
    $result = [
        'status' => $status,
        'last_24_hours' => $emptyWindow,
        'last_7_days' => $emptyWindow,
        'trend' => [],
        'trend_definition' => 'challenge_required_plus_request_suppressed',
    ];
    if ($status !== VIEWER_SECURITY_OPERATIONS_STATUS_AVAILABLE) {
        return $result;
    }

    $now = $nowTimestamp ?? time();
    $cutoff24 = date('Y-m-d H:i:s', $now - 86400);
    $cutoff7 = date('Y-m-d H:i:s', $now - (7 * 86400));
    $trendStartTimestamp = strtotime(date('Y-m-d 00:00:00', $now) . ' -6 days');
    if ($trendStartTimestamp === false) {
        $trendStartTimestamp = $now - (6 * 86400);
    }
    $trendStart = date('Y-m-d H:i:s', $trendStartTimestamp);
    $nowSql = date('Y-m-d H:i:s', $now);

    try {
        $persistedKeys = array_values($eventKeys);
        $placeholders = implode(',', array_fill(0, count($persistedKeys), '?'));
        $stmt = db()->prepare(
            'SELECT event_key, '
            . 'SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS count_24h, '
            . 'COUNT(*) AS count_7d '
            . 'FROM viewer_security_events '
            . 'WHERE event_key IN (' . $placeholders . ') AND created_at >= ? '
            . 'GROUP BY event_key'
        );
        $stmt->execute(array_merge([$cutoff24], $persistedKeys, [$cutoff7]));
        $byEvent = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $eventKey = (string) ($row['event_key'] ?? '');
            if (in_array($eventKey, $persistedKeys, true)) {
                $byEvent[$eventKey] = [
                    'count_24h' => (int) ($row['count_24h'] ?? 0),
                    'count_7d' => (int) ($row['count_7d'] ?? 0),
                ];
            }
        }
        foreach ($eventKeys as $metricKey => $eventKey) {
            $result['last_24_hours'][$metricKey] = (int) ($byEvent[$eventKey]['count_24h'] ?? 0);
            $result['last_7_days'][$metricKey] = (int) ($byEvent[$eventKey]['count_7d'] ?? 0);
        }

        $trendKeys = viewer_security_operations_trend_event_keys();
        $trendPlaceholders = implode(',', array_fill(0, count($trendKeys), '?'));
        $trendStmt = db()->prepare(
            'SELECT DATE(created_at) AS activity_date, event_key, COUNT(*) AS event_count '
            . 'FROM viewer_security_events '
            . 'WHERE event_key IN (' . $trendPlaceholders . ') '
            . 'AND created_at >= ? AND created_at <= ? '
            . 'GROUP BY DATE(created_at), event_key '
            . 'ORDER BY activity_date ASC, event_key ASC'
        );
        $trendStmt->execute(array_merge($trendKeys, [$trendStart, $nowSql]));
        $trendCounts = [];
        foreach ($trendStmt->fetchAll() ?: [] as $row) {
            $date = (string) ($row['activity_date'] ?? '');
            $eventKey = (string) ($row['event_key'] ?? '');
            if ($date !== '' && in_array($eventKey, $trendKeys, true)) {
                $trendCounts[$date][$eventKey] = (int) ($row['event_count'] ?? 0);
            }
        }

        for ($day = 0; $day < 7; $day++) {
            $dayTimestamp = strtotime('+' . $day . ' days', $trendStartTimestamp);
            if ($dayTimestamp === false) {
                continue;
            }
            $date = date('Y-m-d', $dayTimestamp);
            $dayCounts = $trendCounts[$date] ?? [];
            $result['trend'][] = [
                'date' => $date,
                'accepted_registrations' => (int) ($dayCounts['viewer.registration_requested'] ?? 0),
                'verification_messages_sent' => (int) ($dayCounts['viewer.verification_sent'] ?? 0),
                'verification_resend_messages_sent' => (int) ($dayCounts['viewer.verification_resent'] ?? 0),
                'anti_automation_interventions' => (int) ($dayCounts['viewer.automation_challenge_required'] ?? 0)
                    + (int) ($dayCounts['viewer.automation_request_suppressed'] ?? 0),
            ];
        }
    } catch (Throwable) {
        $result['status'] = VIEWER_SECURITY_OPERATIONS_STATUS_UNKNOWN;
        $result['last_24_hours'] = $emptyWindow;
        $result['last_7_days'] = $emptyWindow;
        $result['trend'] = [];
    }

    return $result;
}

/**
 * Return one validated flattened operations limiter allowlist from the authoritative policies.
 *
 * @return array<string,array{max_attempts:int,window_seconds:int,lock_seconds:int}> Fixed policy subset.
 */
function viewer_security_operations_rate_limit_policies(): array
{
    $allPolicies = viewer_rate_limit_policies();
    $selected = [];
    foreach (viewer_security_operations_rate_limit_groups() as $buckets) {
        foreach ($buckets as $bucket) {
            if (isset($allPolicies[$bucket])) {
                $selected[$bucket] = $allPolicies[$bucket];
            }
        }
    }
    return $selected;
}

/**
 * Build the bounded aggregate limiter query for the fixed operations bucket set.
 *
 * Every bucket and cutoff is derived from application-owned policy configuration, not Admin input.
 * The join ignores rows older than the longest selected window unless they remain locked.
 *
 * @param array<string,array{max_attempts:int,window_seconds:int,lock_seconds:int}> $policies Fixed selected policies.
 * @param int $nowTimestamp Deterministic current time.
 * @return string Aggregate SQL returning no subject hashes.
 */
function viewer_security_operations_rate_limit_query(array $policies, int $nowTimestamp): string
{
    $case = [];
    $bucketSql = [];
    $maximumWindow = 0;
    foreach ($policies as $bucket => $policy) {
        if (preg_match('/^[a-z0-9_]{1,64}$/D', $bucket) !== 1) {
            continue;
        }
        $window = max(1, (int) ($policy['window_seconds'] ?? 1));
        $cutoff = date('Y-m-d H:i:s', $nowTimestamp - $window);
        $case[] = "WHEN '{$bucket}' THEN '{$cutoff}'";
        $bucketSql[] = "'{$bucket}'";
        $maximumWindow = max($maximumWindow, $window);
    }
    if ($case === [] || $bucketSql === []) {
        return '';
    }

    $nowSql = date('Y-m-d H:i:s', $nowTimestamp);
    $oldestCutoff = date('Y-m-d H:i:s', $nowTimestamp - $maximumWindow);
    $cutoffCase = 'CASE b.bucket ' . implode(' ', $case) . " ELSE '{$nowSql}' END";

    return 'SELECT b.bucket, b.entry_count, '
        . 'COALESCE(SUM(CASE WHEN r.subject_hash IS NOT NULL '
        . 'AND (r.last_attempt_at >= ' . $cutoffCase . " OR r.locked_until > '{$nowSql}') "
        . 'THEN 1 ELSE 0 END), 0) AS active_subjects, '
        . 'COALESCE(SUM(CASE WHEN r.subject_hash IS NOT NULL '
        . "AND r.locked_until > '{$nowSql}' THEN 1 ELSE 0 END), 0) AS locked_subjects, "
        . 'COALESCE(MAX(CASE WHEN r.subject_hash IS NOT NULL '
        . 'AND r.first_attempt_at >= ' . $cutoffCase . ' THEN r.attempts ELSE 0 END), 0) AS current_window_attempts '
        . 'FROM viewer_rate_limit_buckets b '
        . 'LEFT JOIN viewer_rate_limits r ON r.bucket = b.bucket '
        . "AND (r.last_attempt_at >= '{$oldestCutoff}' OR r.first_attempt_at >= '{$oldestCutoff}' OR r.locked_until > '{$nowSql}') "
        . 'WHERE b.bucket IN (' . implode(',', $bucketSql) . ') '
        . 'GROUP BY b.bucket, b.entry_count';
}

/**
 * Return privacy-safe current pressure for the fixed Phase 4 limiter families.
 *
 * Active subjects are rows whose last activity remains inside that bucket's policy window,
 * plus rows whose lock is still in the future. Locked subjects are rows with locked_until > now.
 * Global current usage is derived only when first_attempt_at remains inside the current window.
 *
 * @param ?int $nowTimestamp Optional deterministic current time for focused tests.
 * @return array<string,mixed> Aggregate limiter state without subject identifiers.
 */
function viewer_security_operations_rate_limit_snapshot(?int $nowTimestamp = null): array
{
    $status = viewer_security_operations_schema_state(viewer_rate_limit_schema_status());
    $policies = viewer_security_operations_rate_limit_policies();
    $groups = viewer_security_operations_rate_limit_groups();
    $buckets = [];
    foreach ($policies as $bucket => $policy) {
        $buckets[$bucket] = [
            'bucket' => $bucket,
            'max_attempts' => (int) $policy['max_attempts'],
            'window_seconds' => (int) $policy['window_seconds'],
            'active_subjects' => null,
            'locked_subjects' => null,
            'stored_subjects' => null,
            'current_window_attempts' => null,
            'is_global' => in_array($bucket, ['viewer_register_global_day', 'viewer_verify_mail_global_day'], true),
        ];
    }

    $result = [
        'status' => $status,
        'groups' => $groups,
        'buckets' => $buckets,
        'global_budgets' => [
            'viewer_register_global_day' => null,
            'viewer_verify_mail_global_day' => null,
        ],
    ];
    if ($status !== VIEWER_SECURITY_OPERATIONS_STATUS_AVAILABLE) {
        return $result;
    }

    $now = $nowTimestamp ?? time();
    $sql = viewer_security_operations_rate_limit_query($policies, $now);
    if ($sql === '') {
        $result['status'] = VIEWER_SECURITY_OPERATIONS_STATUS_UNKNOWN;
        return $result;
    }

    try {
        $rows = db()->query($sql)->fetchAll() ?: [];
        foreach ($buckets as $bucket => $bucketData) {
            $result['buckets'][$bucket]['active_subjects'] = 0;
            $result['buckets'][$bucket]['locked_subjects'] = 0;
            $result['buckets'][$bucket]['stored_subjects'] = 0;
            $result['buckets'][$bucket]['current_window_attempts'] = 0;
        }
        foreach ($rows as $row) {
            $bucket = (string) ($row['bucket'] ?? '');
            if (!isset($result['buckets'][$bucket])) {
                continue;
            }
            $result['buckets'][$bucket]['active_subjects'] = (int) ($row['active_subjects'] ?? 0);
            $result['buckets'][$bucket]['locked_subjects'] = (int) ($row['locked_subjects'] ?? 0);
            $result['buckets'][$bucket]['stored_subjects'] = (int) ($row['entry_count'] ?? 0);
            $result['buckets'][$bucket]['current_window_attempts'] = (int) ($row['current_window_attempts'] ?? 0);
        }

        foreach (array_keys($result['global_budgets']) as $bucket) {
            if (!isset($result['buckets'][$bucket])) {
                continue;
            }
            $result['global_budgets'][$bucket] = [
                'current_attempts' => (int) ($result['buckets'][$bucket]['current_window_attempts'] ?? 0),
                'limit' => (int) ($result['buckets'][$bucket]['max_attempts'] ?? 0),
                'locked_subjects' => (int) ($result['buckets'][$bucket]['locked_subjects'] ?? 0),
            ];
        }
    } catch (Throwable) {
        $result['status'] = VIEWER_SECURITY_OPERATIONS_STATUS_UNKNOWN;
    }

    return $result;
}

/**
 * Return the complete read-only Viewer security operations snapshot for the Admin page.
 *
 * Each storage-backed component handles its own unavailable/unknown state so one failed
 * component does not take down the Viewer account administration surface.
 *
 * @param ?int $nowTimestamp Optional deterministic current time for focused tests.
 * @return array<string,mixed> Aggregate Phase 4 operations snapshot.
 */
function viewer_security_operations_snapshot(?int $nowTimestamp = null): array
{
    $status = viewer_security_operations_status_snapshot();
    $capacity = viewer_security_operations_capacity_snapshot();
    $events = viewer_security_operations_event_snapshot($nowTimestamp);
    $rateLimits = viewer_security_operations_rate_limit_snapshot($nowTimestamp);

    if (($events['status'] ?? '') !== VIEWER_SECURITY_OPERATIONS_STATUS_AVAILABLE) {
        $status['storage']['viewer_security_events'] = (string) ($events['status'] ?? VIEWER_SECURITY_OPERATIONS_STATUS_UNKNOWN);
    }
    if (($rateLimits['status'] ?? '') !== VIEWER_SECURITY_OPERATIONS_STATUS_AVAILABLE) {
        $status['storage']['viewer_rate_limits'] = (string) ($rateLimits['status'] ?? VIEWER_SECURITY_OPERATIONS_STATUS_UNKNOWN);
    }
    if (($capacity['accounts']['status'] ?? '') !== VIEWER_SECURITY_OPERATIONS_STATUS_AVAILABLE) {
        $status['storage']['viewer_auth'] = (string) ($capacity['accounts']['status'] ?? VIEWER_SECURITY_OPERATIONS_STATUS_UNKNOWN);
    }
    if (($capacity['registrations']['status'] ?? '') !== VIEWER_SECURITY_OPERATIONS_STATUS_AVAILABLE) {
        $status['storage']['viewer_registration'] = (string) ($capacity['registrations']['status'] ?? VIEWER_SECURITY_OPERATIONS_STATUS_UNKNOWN);
    }

    return [
        'status' => $status,
        'capacity' => $capacity,
        'events' => $events,
        'rate_limits' => $rateLimits,
    ];
}
