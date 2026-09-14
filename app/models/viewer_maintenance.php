<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/viewer_maintenance.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns bounded Viewer security-retention cleanup persistence.
 *
 * Responsibilities:
 *   - Map semantic cleanup kinds to fixed allowlisted table predicates
 *   - Delete bounded expired/revoked Viewer security rows
 *   - Prevent service callers from supplying table names or SQL predicates
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Cleanup scheduling and retention cutoff policy remain service-owned.
 *
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Models;

use InvalidArgumentException;
use function Gallery\Core\db;

/** Delete one bounded batch for a fixed semantic Viewer maintenance kind. */
function viewer_maintenance_model_delete_batch(string $kind, array $params, int $limit = 1000): int
{
    $definitions = [
        'email_verification_tokens' => ['viewer_email_verification_tokens', '(expires_at < ? OR consumed_at < ? OR invalidated_at < ?)', 3],
        'password_reset_tokens' => ['viewer_password_reset_tokens', '(expires_at < ? OR consumed_at < ? OR invalidated_at < ?)', 3],
        'email_change_requests' => ['viewer_email_change_requests', '(expires_at < ? OR consumed_at < ? OR cancelled_at < ?)', 3],
        'remember_tokens' => ['viewer_remember_tokens', '(expires_at < ? OR revoked_at < ?)', 2],
        'sessions' => ['viewer_sessions', '(expires_at < ? OR revoked_at < ?)', 2],
        'collection_share_tokens' => ['viewer_collection_share_tokens', '(expires_at IS NOT NULL AND expires_at < ?) OR revoked_at < ?', 2],
        'security_events' => ['viewer_security_events', 'retention_until < ?', 1],
    ];
    if (!isset($definitions[$kind])) {
        throw new InvalidArgumentException('Viewer maintenance kind is not allowlisted.');
    }
    [$table, $predicate, $parameterCount] = $definitions[$kind];
    if (count($params) !== $parameterCount) {
        throw new InvalidArgumentException('Viewer maintenance parameter count is invalid.');
    }
    $limit = max(1, min(1000, $limit));
    $stmt = db()->prepare('DELETE FROM ' . $table . ' WHERE ' . $predicate . ' LIMIT ' . $limit);
    $stmt->execute(array_values($params));
    return $stmt->rowCount();
}
