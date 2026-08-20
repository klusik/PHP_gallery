<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/viewer_security_events.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Provides privacy-conscious structured security-event storage for future viewer accounts.
 *
 * Responsibilities:
 *   - Record allowlisted security metadata without credential/token material
 *   - Hash client IP and user-agent values instead of storing them raw
 *   - Bound free-form context by an explicit key allowlist and length limits
 *   - Attach indexed retention timestamps for maintenance cleanup
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
 *   - Passwords, tokens, cookies, CSRF values, Authorization headers, and secret URLs are never valid context.
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

namespace Gallery\Services;

use InvalidArgumentException;
use function Gallery\Core\db;
use function Gallery\Core\now_sql;

/**
 * Return the three-state schema capability for viewer security-event storage.
 *
 * @return array Aggregate schema inspection result.
 */
function viewer_security_event_schema_status(): array
{
    return schema_inspection_feature('viewer.security_events', [
        schema_inspection_table('viewer_security_events'),
    ]);
}

/**
 * Return true only when viewer security-event storage is verifiably available.
 *
 * @return bool True only for confirmed available event storage.
 */
function viewer_security_event_storage_available(): bool
{
    return function_exists(__NAMESPACE__ . '\\schema_inspection_table')
        && schema_inspection_is_available(viewer_security_event_schema_status());
}

/**
 * Return the context fields that viewer security events may persist.
 *
 * @return array<int,string> Low-risk structured context keys.
 */
function viewer_security_event_context_keys(): array
{
    return [
        'action',
        'result',
        'reason',
        'method',
        'route_name',
        'status',
        'account_state',
        'security_version',
        'retry_after_seconds',
        'attempts',
        'collection_id',
        'share_id',
    ];
}

/**
 * Normalize viewer security event context to a small credential-free structure.
 *
 * @param array $context Caller-provided context.
 * @return array<string,int|string|bool|null> Sanitized context.
 */
function viewer_security_event_sanitize_context(array $context): array
{
    $allowed = array_fill_keys(viewer_security_event_context_keys(), true);
    $sanitized = [];
    foreach ($context as $key => $value) {
        $key = (string) $key;
        if (!isset($allowed[$key]) || !(is_scalar($value) || $value === null)) {
            continue;
        }
        if (is_string($value)) {
            $value = substr($value, 0, 128);
        }
        $sanitized[$key] = $value;
    }
    return $sanitized;
}

/**
 * Record one structured viewer security event.
 *
 * @param string $eventKey Stable event key such as viewer.login_failure.
 * @param ?int $viewerAccountId Viewer account id when known, otherwise null.
 * @param string $outcome Short outcome category.
 * @param array $context Allowlisted low-risk structured metadata.
 */
function viewer_security_event_record(string $eventKey, ?int $viewerAccountId = null, string $outcome = '', array $context = []): void
{
    if (!viewer_security_event_storage_available()) {
        return;
    }
    if (preg_match('/^viewer\.[a-z0-9_.-]{1,92}$/', $eventKey) !== 1) {
        throw new InvalidArgumentException('Viewer security event key is invalid.');
    }
    if ($viewerAccountId !== null && $viewerAccountId <= 0) {
        throw new InvalidArgumentException('Viewer security event account id is invalid.');
    }

    $outcome = preg_replace('/[^a-z0-9_.-]/', '', strtolower(trim($outcome))) ?? '';
    $outcome = substr($outcome, 0, 32);
    $clientIp = request_client_ip();
    $ipHash = $clientIp === '' ? null : viewer_security_fingerprint('viewer-event-ip', $clientIp);
    $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $userAgentHash = $userAgent === '' ? null : viewer_security_fingerprint('viewer-event-ua', $userAgent);
    $requestId = function_exists('Gallery\\Services\\telemetry_request_id') ? (string) telemetry_request_id() : '';
    $requestId = substr($requestId, 0, 64);
    $safeContext = viewer_security_event_sanitize_context($context);
    $contextJson = $safeContext === [] ? null : json_encode($safeContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($contextJson) || strlen($contextJson) > 2000) {
        $contextJson = null;
    }
    $retentionDays = (int) viewer_accounts_config()['security_event_retention_days'];
    $retentionUntil = date('Y-m-d H:i:s', time() + ($retentionDays * 86400));

    $stmt = db()->prepare('INSERT INTO viewer_security_events (viewer_account_id, event_key, outcome, ip_hash, user_agent_hash, request_id, context_json, created_at, retention_until) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $viewerAccountId,
        $eventKey,
        $outcome === '' ? null : $outcome,
        $ipHash,
        $userAgentHash,
        $requestId === '' ? null : $requestId,
        $contextJson,
        now_sql(),
        $retentionUntil,
    ]);
}
