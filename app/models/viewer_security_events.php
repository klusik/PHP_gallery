<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/viewer_security_events.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns persistence for sanitized viewer security-event records.
 *
 * Responsibilities:
 *   - Insert already-sanitized viewer security events
 *   - Keep request metadata collection and security-context policy in the service layer
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Raw IP addresses, user agents, credentials, and arbitrary request data must never reach this model.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use function Gallery\Core\db;

/**
 * Persist one already-sanitized viewer security event.
 *
 * @param ?string $contextJson Context json used by this operation.
 * @param string $createdAt Timestamp for the created at.
 * @param string $eventKey Event key used by this operation.
 * @param ?string $ipHash Precomputed hash for the ip.
 * @param ?string $outcome Outcome used by this operation.
 * @param ?string $requestId Identifier of the request.
 * @param string $retentionUntil Retention until used by this operation.
 * @param ?string $userAgentHash Precomputed hash for the user agent.
 * @param ?int $viewerAccountId Identifier of the viewer account.
 * @return void No value; performs the documented operation.
 */
function viewer_security_event_model_insert(
    ?int $viewerAccountId,
    string $eventKey,
    ?string $outcome,
    ?string $ipHash,
    ?string $userAgentHash,
    ?string $requestId,
    ?string $contextJson,
    string $createdAt,
    string $retentionUntil,
): void {
    $stmt = db()->prepare(
        'INSERT INTO viewer_security_events (viewer_account_id, event_key, outcome, ip_hash, user_agent_hash, request_id, context_json, created_at, retention_until) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
    );
    $stmt->execute([
        $viewerAccountId,
        $eventKey,
        $outcome,
        $ipHash,
        $userAgentHash,
        $requestId,
        $contextJson,
        $createdAt,
        $retentionUntil,
    ]);
}
