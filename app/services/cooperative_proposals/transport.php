<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_proposals/transport.php
 * Module Type: Service
 * Purpose: Deliver one proposal or inspect one directly paired participant per Admin action.
 * Responsibilities: Use pinned HTTPS, stable persisted bodies and local CAS without SQL transactions across network calls.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

/** Expose a test-only transport seam that cannot be selected from HTTP or configuration.
 * @return callable|null Trusted isolated test adapter, or null for pinned HTTPS.
 */
function &cooperative_proposal_transport_override(): mixed
{
    static $transport = null;
    return $transport;
}

/** Contact the fixed proposal endpoint using the current stored direct friendship.
 * @param array<string,mixed> $peer Local nonsecret active peer snapshot.
 * @param array<string,mixed> $message Exact bounded request.
 * @return array<string,mixed> Decoded but not yet semantically trusted peer response.
 */
function cooperative_proposal_request(array $peer, array $message): array
{
    cooperative_require_enabled();
    cooperative_proposal_message_validate($message);
    $base = cooperative_peer_base_url($peer['base_url']);
    $token = cooperative_peer_outbound_credential($peer['instance_id']);
    $transport = &cooperative_proposal_transport_override();
    try {
        $result = is_callable($transport) ? $transport($base, $message, $token)
            : outbound_http_json_request($base . '/index.php?page=cooperative_proposal_api', $message, $token);
        if (!is_array($result) || strlen(json_encode($result, JSON_THROW_ON_ERROR)) > COOPERATIVE_PAIRING_MAX_BYTES) {
            throw new \RuntimeException('Invalid bounded reply.');
        }
    } catch (\Throwable) {
        throw new CooperativeException('peer_unavailable');
    }
    return $result;
}

/** Deliver or refresh exactly one participant, preserving immutable replay identity.
 * A lost reply leaves the local proposal unchanged; retry sends the same digest/body.
 * Observations are diagnostic snapshots only and never populate remote approvals.
 * @param string $groupId Persisted initial proposal group.
 * @param int $expected Expected local storage revision before starting the network operation.
 * @param string $remote Direct member to contact; no arbitrary URL is accepted.
 * @param string $action Offer from the local coordinator, or decision query from any member.
 * @return array<string,mixed> Updated Admin projection with one direct observation.
 */
function cooperative_proposal_exchange_contact(string $groupId, int $expected, string $remote, string $action): array
{
    $stored = cooperative_proposal_exchange_load($groupId);
    if ($stored['storage_revision'] !== $expected) {
        throw new CooperativeException('revision_conflict');
    }
    if (!in_array($action, ['offer', 'decision'], true)) {
        throw new CooperativeException('invalid_action');
    }
    $group = $stored['group'];
    $pending = cooperative_proposal_document($group);
    if ($group['state'] === 'pending') {
        cooperative_group_pending($group, $pending['digest'], time());
    }
    $local = cooperative_instance_id();
    cooperative_id_validate($remote);
    if ($group['exchange']['decision'] === 'declined') {
        throw new CooperativeException('proposal_declined');
    }
    if ($remote === $local || !in_array($remote, array_column($pending['body']['members'], 'instance_id'), true)
        || ($action === 'offer' && $group['coordinator_id'] !== $local)) {
        throw new CooperativeException('proposal_unauthorized');
    }
    $peer = cooperative_peer_status($remote);
    if ($peer === null || $peer['state'] !== 'active') {
        throw new CooperativeException('friendship_unavailable');
    }
    $message = ['protocol' => COOPERATIVE_PROTOCOL_VERSION, 'action' => $action,
        'sender_id' => $local, 'recipient_id' => $remote, 'group_id' => $groupId, 'digest' => $pending['digest']];
    if ($action === 'offer') {
        cooperative_proposal_source_assert($pending['body']);
        $message['body'] = $pending['body'];
    }
    $response = cooperative_proposal_request($peer, $message);
    $observation = cooperative_proposal_response_validate($response, $message, $pending['body']);
    if (cooperative_peer_status($remote) !== $peer) {
        throw new CooperativeException('revision_conflict');
    }
    $group['exchange']['observations'][$remote] = $observation + ['observed_at' => time(), 'peer_revision' => $peer['revision']];
    $verifiedGenerations = $group['exchange']['lease']['generations'][$remote]
        ?? $group['exchange']['verification']['receipts'][$remote]['generations'] ?? null;
    if ($observation['decision'] !== 'approved'
        || ($verifiedGenerations !== null && $verifiedGenerations !== $observation['generations'])) {
        $group['exchange']['lease'] = null;
        $group['exchange']['verification'] = null;
    }
    // Save against the pre-network revision: a concurrent local decline cannot be overwritten.
    return cooperative_proposal_exchange_projection(cooperative_proposal_exchange_save($stored, $group));
}
