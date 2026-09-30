<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_content/admission.php
 * Module Type: Service
 * Purpose: Bound anonymous outbound catalog work using durable group-wide ownership.
 * Responsibilities: Reserve finite network operations, space retries and preserve concurrent consent changes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;

/** Reserve one catalog request before credentials or outbound transport are accessed.
 * @param string $groupId Explicit locally approved group identity.
 * @param array<string,mixed> $group Previously authorized group and security lease.
 * @param string $source Selected directly paired participant.
 * @return array<string,mixed>|null Saved reservation, or a pending admission without network work.
 */
function cooperative_content_admission_reserve(string $groupId, array $group, string $source): ?array
{
    $stored = cooperative_proposal_exchange_load($groupId);
    $current = $stored['group'];
    if (!cooperative_activation_lease_valid($current)
        || ($current['exchange']['lease'] ?? null) !== ($group['exchange']['lease'] ?? null)
        || cooperative_verification_wait_until($current) !== null) { return null; }
    $now = time();
    $operation = $current['exchange']['content_operation'] ?? null;
    $retryAt = $current['exchange']['content_retry_at'] ?? null;
    if ((is_array($operation) && is_int($operation['expires_at'] ?? null) && $operation['expires_at'] > $now)
        || (is_int($retryAt) && $retryAt > $now)) { return null; }
    $operation = ['nonce' => cooperative_id_generate(), 'group_id' => $groupId, 'peer_id' => $source,
        'round_id' => $current['exchange']['lease']['round_id'],
        'expires_at' => min($now + COOPERATIVE_CONTENT_OPERATION_TTL, $current['exchange']['lease']['expires_at'])];
    $current['exchange']['content_operation'] = $operation;
    // A killed process cannot release ownership. Persist its finite recovery
    // boundary now, including cooldown, rather than relying on a finally block.
    $current['exchange']['content_retry_at'] = $operation['expires_at'] + COOPERATIVE_CONSENT_RETRY_DELAY;
    try {
        return cooperative_proposal_exchange_save($stored, $current);
    } catch (CooperativeException $error) {
        if ($error->reason === 'revision_conflict') { return null; }
        throw $error;
    }
}

/** Release only this exact owned operation and establish the next admission boundary.
 * @param array<string,mixed> $reserved Saved aggregate and reservation revision.
 * @param array<string,mixed> $peer Direct peer snapshot used for the request.
 * @param bool $success Whether a bounded validated response was received.
 * @return bool Whether ownership and, for success, current authority remained valid.
 */
function cooperative_content_admission_finish(array $reserved, array $peer, bool $success): bool
{
    $before = $reserved['group'];
    $operation = $before['exchange']['content_operation'];
    $stored = cooperative_proposal_exchange_load($before['group_id']);
    $current = $stored['group'];
    if ($stored['storage_revision'] !== $reserved['storage_revision']
        || ($current['exchange']['content_operation'] ?? null) !== $operation) { return false; }
    if ($success && (time() >= $operation['expires_at'] || !cooperative_activation_lease_valid($current)
        || cooperative_verification_wait_until($current) !== null
        || ($current['exchange']['lease'] ?? null) !== $before['exchange']['lease']
        || cooperative_peer_status($operation['peer_id']) !== $peer)) { return false; }
    unset($current['exchange']['content_operation']);
    $current['exchange']['content_retry_at'] = time()
        + ($success ? COOPERATIVE_CONTENT_SUCCESS_RETRY_DELAY : COOPERATIVE_CONSENT_RETRY_DELAY);
    try {
        cooperative_proposal_exchange_save($stored, $current);
        return true;
    } catch (CooperativeException $error) {
        if ($error->reason === 'revision_conflict') { return false; }
        throw $error;
    }
}
