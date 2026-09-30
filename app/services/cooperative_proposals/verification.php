<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_proposals/verification.php
 * Module Type: Service
 * Purpose: Collect fresh directly authenticated participant evidence in bounded durable rounds.
 * Responsibilities: Bind replies to a nonce, discard failed checks and reject stale local revisions.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

/** Find the finite persisted wait boundary for a verification operation or failed retry.
 * @param array<string,mixed> $group Trusted local aggregate.
 * @return int|null Retry deadline; expired reservations never prevent crash recovery.
 */
function cooperative_verification_wait_until(array $group): ?int
{
    $operation = $group['exchange']['verification']['operation'] ?? null;
    if (is_array($operation) && is_int($operation['expires_at'] ?? null)
        && $operation['expires_at'] > time()) {
        return $operation['expires_at'];
    }
    $retryAt = $group['exchange']['verification_retry_at'] ?? null;
    return is_int($retryAt) && $retryAt > time() ? $retryAt : null;
}

/** Validate a current round and the local consent snapshot that started it.
 * @param array<string,mixed> $group Trusted local aggregate.
 * @param string $roundId Exact opaque verification intent identity.
 * @return array<string,mixed> Current nonexpired round, without interpreting observations as proofs.
 */
function cooperative_verification_round(array $group, string $roundId): array
{
    cooperative_id_validate($roundId);
    $round = $group['exchange']['verification'] ?? null;
    $document = cooperative_proposal_document($group);
    if (!is_array($round) || ($round['round_id'] ?? '') !== $roundId
        || ($round['digest'] ?? '') !== $document['digest']) {
        throw new CooperativeException('verification_unavailable');
    }
    $now = time();
    if (!is_int($round['started_at']) || !is_int($round['expires_at'])
        || $round['expires_at'] !== $round['started_at'] + COOPERATIVE_VERIFICATION_TTL
        || $now < $round['started_at'] || $now >= $round['expires_at']) {
        throw new CooperativeException('verification_expired');
    }
    $own = cooperative_proposal_own_decision($group);
    if ($own['decision'] !== 'approved' || $own['generations'] !== $round['own_generations']) {
        throw new CooperativeException('consent_stale');
    }
    return $round;
}

/** Start a new verification round and pause any previous local authorization lease.
 * @param string $groupId Existing initial collaboration identity.
 * @param int $expected Local storage revision displayed to the administrator.
 * @return array<string,mixed> Admin projection with a fresh round identity and fixed deadline.
 */
function cooperative_verification_start(string $groupId, int $expected): array
{
    $stored = cooperative_proposal_exchange_load($groupId);
    if ($stored['storage_revision'] !== $expected) {
        throw new CooperativeException('revision_conflict');
    }
    $group = $stored['group'];
    if (cooperative_verification_wait_until($group) !== null) {
        return cooperative_proposal_exchange_projection($stored);
    }
    $own = cooperative_proposal_own_decision($group);
    if ($own['decision'] !== 'approved') {
        throw new CooperativeException('consent_missing');
    }
    $document = cooperative_proposal_document($group);
    $now = time();
    $group['exchange']['lease'] = null;
    $group['exchange']['verification'] = ['round_id' => cooperative_id_generate(), 'digest' => $document['digest'],
        'started_at' => $now, 'expires_at' => $now + COOPERATIVE_VERIFICATION_TTL,
        'own_generations' => $own['generations'], 'receipts' => []];
    return cooperative_proposal_exchange_projection(cooperative_proposal_exchange_save($stored, $group));
}

/** Verify exactly one participant directly; failed retries cannot retain an older receipt.
 * @param string $groupId Existing initial collaboration identity.
 * @param int $expected Expected storage revision before reserving this network operation.
 * @param string $roundId Current round nonce, never supplied by the remote server.
 * @param string $remote Direct participant to contact at its stored paired origin.
 * @return array<string,mixed> Updated Admin projection; a negative reply remains non-authorizing.
 */
function cooperative_verification_peer(string $groupId, int $expected, string $roundId, string $remote): array
{
    $stored = cooperative_proposal_exchange_load($groupId);
    if ($stored['storage_revision'] !== $expected) {
        throw new CooperativeException('revision_conflict');
    }
    $group = $stored['group'];
    $round = cooperative_verification_round($group, $roundId);
    cooperative_id_validate($remote);
    $local = cooperative_instance_id();
    if ($remote === $local || !array_key_exists($remote, $round['own_generations'])) {
        throw new CooperativeException('proposal_unauthorized');
    }
    if (cooperative_verification_wait_until($group) !== null) {
        return cooperative_proposal_exchange_projection($stored);
    }
    $peer = cooperative_peer_status($remote);
    if ($peer === null || $peer['state'] !== 'active') {
        throw new CooperativeException('friendship_unavailable');
    }
    $document = cooperative_proposal_document($group);
    // Reserve a new storage revision and erase the old proof before contacting the peer.
    // Failure, timeout or a lost response then leaves this participant unverified.
    unset($group['exchange']['verification']['receipts'][$remote]);
    $group['exchange']['lease'] = null;
    // A group has one owned network operation. Its nonce is local ownership only;
    // the wire challenge remains the fixed round identity and deadline.
    $operation = ['nonce' => cooperative_id_generate(), 'round_id' => $roundId,
        'peer_id' => $remote, 'expires_at' => min(time() + COOPERATIVE_CONSENT_RETRY_DELAY, $round['expires_at'])];
    $group['exchange']['verification']['operation'] = $operation;
    // Persist a finite crash/lost-response cooldown before making the request.
    // This survives round replacement, so polling cannot evade a failed retry.
    $group['exchange']['verification_retry_at'] = $operation['expires_at'] + COOPERATIVE_CONSENT_RETRY_DELAY;
    $stored = cooperative_proposal_exchange_save($stored, $group);
    $message = ['protocol' => COOPERATIVE_PROTOCOL_VERSION, 'action' => 'verify',
        'sender_id' => $local, 'recipient_id' => $remote, 'group_id' => $groupId,
        'digest' => $document['digest'], 'challenge' => $roundId];
    $observedAt = time();
    try {
        $response = cooperative_proposal_request($peer, $message);
        $receipt = cooperative_proposal_response_validate($response, $message, $document['body']);
        $current = cooperative_proposal_exchange_load($groupId);
        if ($current['storage_revision'] !== $stored['storage_revision']
            || ($current['group']['exchange']['verification']['operation'] ?? null) !== $operation
            || time() >= $operation['expires_at'] || cooperative_peer_status($remote) !== $peer) {
            throw new CooperativeException('revision_conflict');
        }
        cooperative_verification_round($current['group'], $roundId);
    } catch (\Throwable $error) {
        // Cleanup may only update the revision and nonce we reserved. A concurrent
        // decline, revocation or newer round wins even when the transport fails.
        $current = cooperative_proposal_exchange_load($groupId);
        if ($current['storage_revision'] === $stored['storage_revision']
            && ($current['group']['exchange']['verification']['operation'] ?? null) === $operation) {
            $failed = $current['group'];
            unset($failed['exchange']['verification']['operation']);
            $failed['exchange']['verification_retry_at'] = time() + COOPERATIVE_CONSENT_RETRY_DELAY;
            try {
                cooperative_proposal_exchange_save($current, $failed);
            } catch (CooperativeException $cleanupError) {
                if ($cleanupError->reason !== 'revision_conflict') { throw $cleanupError; }
            }
        }
        throw $error;
    }
    unset($group['exchange']['verification']['operation'], $group['exchange']['verification_retry_at']);
    $group['exchange']['verification']['receipts'][$remote] = $receipt + [
        'challenge' => $roundId, 'observed_at' => $observedAt, 'peer_revision' => $peer['revision']];
    $group['exchange']['observations'][$remote] = $receipt + ['observed_at' => $observedAt, 'peer_revision' => $peer['revision']];
    return cooperative_proposal_exchange_projection(cooperative_proposal_exchange_save($stored, $group));
}

/** Construct every direct friendship stamp from both independently observed endpoints.
 * @param array<string,mixed> $body Exact canonical proposal body.
 * @param array<string,array<string,int>> $generations One directly verified map per participant.
 * @return array<string,string> Complete sorted pair-to-stamp mapping for this exact membership.
 */
function cooperative_verification_evidence(array $body, array $generations): array
{
    $ids = array_column($body['members'], 'instance_id');
    sort($ids, SORT_STRING);
    $owners = array_keys($generations);
    sort($owners, SORT_STRING);
    if ($owners !== $ids) {
        throw new CooperativeException('consent_missing');
    }
    foreach ($ids as $owner) {
        $expected = array_values(array_diff($ids, [$owner]));
        $map = $generations[$owner];
        $peers = array_keys($map);
        sort($peers, SORT_STRING);
        if ($peers !== $expected) {
            throw new CooperativeException('friendship_unavailable');
        }
        foreach ($map as $revision) {
            if (!is_int($revision) || $revision < 1) {
                throw new CooperativeException('friendship_unavailable');
            }
        }
    }
    $evidence = [];
    foreach (cooperative_friendship_pairs($body['members']) as [$first, $second]) {
        $evidence[$first . ':' . $second] = cooperative_friendship_stamp($first, $generations[$first][$second], $second, $generations[$second][$first]);
    }
    return $evidence;
}
