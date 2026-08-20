<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/viewer_maintenance.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Provides bounded cleanup for dormant viewer security and registration records.
 *
 * Responsibilities:
 *   - Remove expired one-time tokens, remember tokens, sessions, and share tokens in small batches
 *   - Remove viewer security events only after their retention deadline
 *   - Reconcile bounded viewer rate-limit and pending-registration storage through dedicated services
 *   - Continue expiry/data-retention cleanup even while viewer capabilities are disabled
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
 *   - Cleanup is intended for existing scheduled maintenance, never every normal request.
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

namespace Gallery\Services;

use function Gallery\Core\db;
use function Gallery\Core\now_sql;

/**
 * Delete at most one bounded batch from an allowlisted viewer security table.
 *
 * @param string $table Allowlisted table name.
 * @param string $predicate Fixed SQL predicate owned by this service.
 * @param array<int,mixed> $params Predicate parameters.
 * @param int $limit Maximum rows deleted in this maintenance slice.
 * @return int Number of rows removed.
 */
function viewer_maintenance_delete_batch(string $table, string $predicate, array $params, int $limit = 1000): int
{
    $allowedTables = [
        'viewer_email_verification_tokens' => true,
        'viewer_password_reset_tokens' => true,
        'viewer_email_change_requests' => true,
        'viewer_remember_tokens' => true,
        'viewer_sessions' => true,
        'viewer_collection_share_tokens' => true,
        'viewer_security_events' => true,
    ];
    if (!isset($allowedTables[$table])) {
        throw new \InvalidArgumentException('Viewer maintenance table is not allowlisted.');
    }

    $limit = max(1, min(1000, $limit));
    $stmt = db()->prepare('DELETE FROM ' . $table . ' WHERE ' . $predicate . ' LIMIT ' . $limit);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * Run one bounded viewer security cleanup slice.
 *
 * @return array<string,mixed> Cleanup counts or a fail-closed storage status.
 */
function viewer_security_maintenance_cleanup(): array
{
    $schema = schema_inspection_feature('viewer.security_maintenance', [
        schema_inspection_table('viewer_accounts'),
        schema_inspection_table('viewer_account_state'),
        schema_inspection_table('viewer_email_verification_tokens'),
        schema_inspection_table('viewer_password_reset_tokens'),
        schema_inspection_table('viewer_email_change_requests'),
        schema_inspection_table('viewer_remember_tokens'),
        schema_inspection_table('viewer_sessions'),
        schema_inspection_table('viewer_collection_share_tokens'),
        schema_inspection_table('viewer_security_events'),
        schema_inspection_table('viewer_rate_limit_buckets'),
        schema_inspection_table('viewer_rate_limits'),
        schema_inspection_table('viewer_invitations'),
        schema_inspection_table('viewer_registration_state'),
        schema_inspection_table('viewer_registration_requests'),
    ]);
    if (!schema_inspection_is_available($schema)) {
        return ['storage' => 'unavailable'];
    }

    $now = now_sql();
    $oldConsumedCutoff = date('Y-m-d H:i:s', time() - 604800);
    $result = [];
    $result['email_verification_tokens'] = viewer_maintenance_delete_batch(
        'viewer_email_verification_tokens',
        '(expires_at < ? OR consumed_at < ? OR invalidated_at < ?)',
        [$now, $oldConsumedCutoff, $oldConsumedCutoff]
    );
    $result['password_reset_tokens'] = viewer_maintenance_delete_batch(
        'viewer_password_reset_tokens',
        '(expires_at < ? OR consumed_at < ? OR invalidated_at < ?)',
        [$now, $oldConsumedCutoff, $oldConsumedCutoff]
    );
    $result['email_change_requests'] = viewer_maintenance_delete_batch(
        'viewer_email_change_requests',
        '(expires_at < ? OR consumed_at < ? OR cancelled_at < ?)',
        [$now, $oldConsumedCutoff, $oldConsumedCutoff]
    );
    $result['remember_tokens'] = viewer_maintenance_delete_batch(
        'viewer_remember_tokens',
        '(expires_at < ? OR revoked_at < ?)',
        [$now, $oldConsumedCutoff]
    );
    $result['sessions'] = viewer_maintenance_delete_batch(
        'viewer_sessions',
        '(expires_at < ? OR revoked_at < ?)',
        [$now, $oldConsumedCutoff]
    );
    $result['collection_share_tokens'] = viewer_maintenance_delete_batch(
        'viewer_collection_share_tokens',
        '(expires_at IS NOT NULL AND expires_at < ?) OR revoked_at < ?',
        [$now, $oldConsumedCutoff]
    );
    $result['security_events'] = viewer_maintenance_delete_batch(
        'viewer_security_events',
        'retention_until < ?',
        [$now]
    );
    $result['rate_limits'] = viewer_rate_limit_cleanup();
    $result['registration'] = viewer_registration_maintenance_cleanup();
    $result['account_capacity'] = viewer_account_capacity_reconcile();
    return $result;
}
