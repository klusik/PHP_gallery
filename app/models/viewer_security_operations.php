<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/viewer_security_operations.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns read-only Viewer security-operations persistence and aggregate query construction.
 *
 * Responsibilities:
 *   - Read durable viewer-account and staged-registration capacity diagnostics
 *   - Aggregate allowlisted Viewer security events over fixed time windows
 *   - Build and execute privacy-safe rate-limit pressure aggregates
 *   - Return aggregate rows only, never subject hashes or security-event context
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Operations policy, metric naming, and display-state normalization remain service-owned.
 *
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Models;

use function Gallery\Core\db;

/** Return durable account-count and persisted capacity-counter diagnostics. */
function viewer_security_operations_model_account_capacity(string $stateKey): array
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS current_count, '
        . '(SELECT account_count FROM viewer_account_state WHERE state_key = ? LIMIT 1) AS capacity_counter_count '
        . 'FROM viewer_accounts'
    );
    $stmt->execute([$stateKey]);
    return $stmt->fetch() ?: [];
}

/** Return staged-registration count breakdown and persisted capacity-counter diagnostics. */
function viewer_security_operations_model_registration_capacity(string $stateKey): array
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS current_count, '
        . 'COALESCE(SUM(CASE WHEN viewer_invitation_id IS NULL THEN 1 ELSE 0 END), 0) AS open_origin_count, '
        . 'COALESCE(SUM(CASE WHEN viewer_invitation_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS invitation_backed_count, '
        . '(SELECT active_request_count FROM viewer_registration_state WHERE state_key = ? LIMIT 1) AS capacity_counter_count '
        . 'FROM viewer_registration_requests'
    );
    $stmt->execute([$stateKey]);
    return $stmt->fetch() ?: [];
}

/** Return allowlisted rolling-window security-event aggregate rows. */
function viewer_security_operations_model_event_aggregates(array $eventKeys, string $cutoff24, string $cutoff7): array
{
    if ($eventKeys === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($eventKeys), '?'));
    $stmt = db()->prepare(
        'SELECT event_key, '
        . 'SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS count_24h, '
        . 'COUNT(*) AS count_7d '
        . 'FROM viewer_security_events '
        . 'WHERE event_key IN (' . $placeholders . ') AND created_at >= ? '
        . 'GROUP BY event_key'
    );
    $stmt->execute(array_merge([$cutoff24], array_values($eventKeys), [$cutoff7]));
    return $stmt->fetchAll() ?: [];
}

/** Return allowlisted daily security-event trend rows. */
function viewer_security_operations_model_event_trend(array $eventKeys, string $trendStart, string $nowSql): array
{
    if ($eventKeys === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($eventKeys), '?'));
    $stmt = db()->prepare(
        'SELECT DATE(created_at) AS activity_date, event_key, COUNT(*) AS event_count '
        . 'FROM viewer_security_events '
        . 'WHERE event_key IN (' . $placeholders . ') '
        . 'AND created_at >= ? AND created_at <= ? '
        . 'GROUP BY DATE(created_at), event_key '
        . 'ORDER BY activity_date ASC, event_key ASC'
    );
    $stmt->execute(array_merge(array_values($eventKeys), [$trendStart, $nowSql]));
    return $stmt->fetchAll() ?: [];
}

/**
 * Build the bounded aggregate limiter query for an application-owned bucket/policy set.
 *
 * Every identifier is validated before interpolation and every cutoff is derived from integer policy windows.
 */
function viewer_security_operations_model_rate_limit_query(array $policies, int $nowTimestamp): string
{
    $case = [];
    $bucketSql = [];
    $maximumWindow = 0;
    foreach ($policies as $bucket => $policy) {
        if (preg_match('/^[a-z0-9_]{1,64}$/D', (string) $bucket) !== 1) {
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

/** Execute and return aggregate rate-limit pressure rows for the allowlisted policy set. */
function viewer_security_operations_model_rate_limit_rows(array $policies, int $nowTimestamp): array
{
    $sql = viewer_security_operations_model_rate_limit_query($policies, $nowTimestamp);
    if ($sql === '') {
        return [];
    }
    $stmt = db()->query($sql);
    return $stmt ? ($stmt->fetchAll() ?: []) : [];
}
