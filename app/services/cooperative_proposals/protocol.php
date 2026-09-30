<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_proposals/protocol.php
 * Module Type: Service
 * Purpose: Authenticate bounded proposal delivery and participant-only decision reads.
 * Responsibilities: Reject forwarded approvals, coordinator impersonation and unrelated participant queries.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

/** Validate an exact proposal control message independently of peer authority.
 * @param array<string,mixed> $message Untrusted bounded JSON object.
 * @return void Refuse unknown fields, types or protocol variants.
 */
function cooperative_proposal_message_validate(array $message): void
{
    $action = $message['action'] ?? null;
    if (!in_array($action, ['offer', 'decision', 'verify', 'invalidate'], true)) {
        throw new CooperativeException('invalid_action');
    }
    $expected = ['protocol', 'action', 'sender_id', 'recipient_id', 'group_id', 'digest'];
    if ($action === 'offer') {
        $expected[] = 'body';
    }
    if ($action === 'verify') {
        $expected[] = 'challenge';
        if (!is_string($message['challenge'] ?? null)) {
            throw new CooperativeException('invalid_message');
        }
        cooperative_id_validate($message['challenge']);
    }
    $keys = array_keys($message);
    sort($keys);
    sort($expected);
    if ($keys !== $expected || $message['protocol'] !== COOPERATIVE_PROTOCOL_VERSION) {
        throw new CooperativeException('invalid_message');
    }
    foreach (['sender_id', 'recipient_id', 'group_id'] as $key) {
        if (!is_string($message[$key])) {
            throw new CooperativeException('invalid_message');
        }
        cooperative_id_validate($message[$key]);
    }
    if (!is_string($message['digest']) || preg_match('/\A[a-f0-9]{64}\z/', $message['digest']) !== 1
        || ($action === 'offer' && !is_array($message['body']))) {
        throw new CooperativeException('invalid_message');
    }
    if (strlen(json_encode($message, JSON_THROW_ON_ERROR)) > COOPERATIVE_PAIRING_MAX_BYTES) {
        throw new CooperativeException('message_too_large');
    }
}

/** Serve one authenticated peer operation, never accepting a remotely claimed vote.
 * @param array<string,mixed> $message Bounded protocol message.
 * @param string $bearer Direct system credential from the Authorization header.
 * @return array<string,mixed> This installation's own decision bound to requester and exact digest.
 */
function cooperative_proposal_receive(array $message, string $bearer): array
{
    cooperative_require_enabled();
    cooperative_proposal_message_validate($message);
    $principal = cooperative_peer_authenticate($message['sender_id'], $bearer);
    if ($principal === null || $message['recipient_id'] !== cooperative_instance_id()
        || $message['sender_id'] === $message['recipient_id']) {
        throw new CooperativeException('proposal_unauthorized');
    }
    if ($message['action'] === 'offer') {
        if (($message['body']['group_id'] ?? null) !== $message['group_id']) {
            throw new CooperativeException('proposal_mismatch');
        }
        $stored = cooperative_proposal_exchange_import($message['body'], $principal['instance_id'], $message['digest']);
    } else {
        $stored = cooperative_proposal_exchange_load($message['group_id']);
    }
    $group = $stored['group'];
    $document = cooperative_proposal_document($group);
    if (!hash_equals($document['digest'], $message['digest'])
        || !in_array($principal['instance_id'], array_column($document['body']['members'], 'instance_id'), true)) {
        throw new CooperativeException('proposal_unauthorized');
    }
    if ($message['action'] === 'invalidate') {
        $group['exchange']['lease'] = null;
        $group['exchange']['verification'] = null;
        unset($group['exchange']['observations'][$principal['instance_id']]);
        cooperative_proposal_exchange_save($stored, $group);
    }
    $own = cooperative_proposal_own_decision($group);
    $response = ['ok' => true, 'protocol' => COOPERATIVE_PROTOCOL_VERSION,
        'sender_id' => $message['recipient_id'], 'recipient_id' => $principal['instance_id'],
        'group_id' => $group['group_id'], 'digest' => $document['digest'],
        'decision' => $own['decision'], 'generations' => $own['generations']];
    if ($message['action'] === 'verify') {
        $response['challenge'] = $message['challenge'];
    }
    return $response;
}

/** Validate a direct endpoint's own reply; forwarded votes and extra fields are rejected.
 * @param array<string,mixed> $response Untrusted decoded HTTPS reply from the pinned peer origin.
 * @param array<string,mixed> $message Exact request we sent to that directly paired peer.
 * @param array<string,mixed> $body Locally persisted proposal membership.
 * @return array{decision:string,generations:array<string,int>} Validated own observation, not an activation grant.
 */
function cooperative_proposal_response_validate(array $response, array $message, array $body): array
{
    if (($message['action'] ?? '') === 'verify') {
        if (($response['challenge'] ?? null) !== $message['challenge']) {
            throw new CooperativeException('peer_response_invalid');
        }
        unset($response['challenge']);
    }
    $keys = array_keys($response);
    sort($keys);
    if ($keys !== ['decision', 'digest', 'generations', 'group_id', 'ok', 'protocol', 'recipient_id', 'sender_id']
        || $response['ok'] !== true || $response['protocol'] !== COOPERATIVE_PROTOCOL_VERSION
        || $response['sender_id'] !== $message['recipient_id'] || $response['recipient_id'] !== $message['sender_id']
        || $response['group_id'] !== $message['group_id'] || $response['digest'] !== $message['digest']
        || !in_array($response['decision'], ['pending', 'approved', 'declined', 'expired', 'consent_stale'], true)
        || !is_array($response['generations'])) {
        throw new CooperativeException('peer_response_invalid');
    }
    $generations = $response['generations'];
    if ($response['decision'] !== 'approved') {
        if ($generations !== []) {
            throw new CooperativeException('peer_response_invalid');
        }
    } else {
        $expected = [];
        foreach ($body['members'] as $member) {
            if ($member['instance_id'] !== $message['recipient_id']) {
                $expected[] = $member['instance_id'];
            }
        }
        sort($expected, SORT_STRING);
        $ids = array_keys($generations);
        sort($ids, SORT_STRING);
        if ($ids !== $expected) {
            throw new CooperativeException('peer_response_invalid');
        }
        foreach ($generations as $revision) {
            if (!is_int($revision) || $revision < 1) {
                throw new CooperativeException('peer_response_invalid');
            }
        }
        ksort($generations, SORT_STRING);
    }
    return ['decision' => $response['decision'], 'generations' => $generations];
}
