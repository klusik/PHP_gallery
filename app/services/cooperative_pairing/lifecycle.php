<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_pairing/lifecycle.php
 * Module Type: Service
 * Purpose: Run invitation creation, acceptance and resumable bilateral exchange.
 * Responsibilities: Persist every outgoing authority before network delivery and verify every returned proof.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

/** Construct the persistent invitation envelope before any remote delivery.
 *
 * @param string $id Stable public invitation identifier.
 * @param string $peerId Stable remote installation identifier.
 * @param string $role Local inviter or invitee role.
 * @param string $state Persisted pairing lifecycle state.
 * @param string $secret Short-lived invitation authority.
 * @param array<string,mixed> $payload Internal structured payload; null selects discovery where supported.
 * @param int $expires Invitation expiration as Unix seconds.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_new_record(string $id, string $peerId, string $role, string $state, string $secret, array $payload, int $expires): array
{
    return [
        'invitation_id' => $id, 'peer_id' => $peerId, 'role' => $role, 'state' => $state,
        'revision' => 1, 'expires_at' => $expires, 'secret_hash' => security_authority_token_hash($secret),
        'payload' => $payload, 'attempts' => 0, 'next_attempt_at' => 0, 'last_error' => '',
    ];
}

/** Return a shareable bootstrap descriptor from an unconsumed local invitation.
 *
 * @param array<string,mixed> $pair Internal pairing record; encrypted recovery payload stays server-side.
 * @return string Result described by the operation above.
 */
function cooperative_pairing_code_for_record(array $pair): string
{
    cooperative_pairing_assert_live($pair);
    if ($pair['role'] !== 'inviter' || $pair['state'] !== 'invited') {
        throw new CooperativeException('invitation_unavailable');
    }
    return cooperative_pairing_invitation_code([
        'protocol' => COOPERATIVE_PROTOCOL_VERSION, 'invitation_id' => $pair['invitation_id'],
        'source_id' => cooperative_instance_id(), 'target_id' => $pair['peer_id'],
        'source_base' => $pair['payload']['local_base'], 'target_base' => $pair['payload']['remote_base'],
        'secret' => $pair['payload']['invite_secret'],
    ]);
}

/** Create a targeted invitation, idempotently keyed by an administrator's request ID.
 *
 * @param string $targetBase Explicit intended remote installation base.
 * @param string $requestId Client-generated opaque idempotency identifier.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_create(string $targetBase, string $requestId): array
{
    cooperative_pairing_require_enabled();
    cooperative_pairing_storage_assert();
    $localBase = cooperative_pairing_local_base();
    $targetBase = cooperative_pairing_base_url($targetBase);
    cooperative_id_validate($requestId);
    if ($localBase === $targetBase) {
        throw new CooperativeException('self_friendship');
    }
    $existing = \Gallery\Models\cooperative_pairing_model_find($requestId);
    if ($existing !== null) {
        $pair = cooperative_pairing_unpack($existing);
        if ($pair['role'] !== 'inviter' || ($pair['payload']['remote_base'] ?? '') !== $targetBase) {
            throw new CooperativeException('request_conflict');
        }
        return ['pairing' => cooperative_pairing_projection($pair), 'invitation_code' => cooperative_pairing_code_for_record($pair)];
    }
    $remote = cooperative_pairing_discover($targetBase);
    $peer = cooperative_peer_new(cooperative_instance_id(), $remote['instance_id'], $targetBase);
    $secret = security_opaque_token_generate();
    $issued = cooperative_credential_generate();
    $peer['incoming_hash'] = security_authority_token_hash($issued);
    $pair = cooperative_pairing_new_record($requestId, $peer['instance_id'], 'inviter', 'invited', $secret, [
        'local_base' => $localBase, 'remote_base' => $targetBase, 'invite_secret' => $secret,
        'issued_token' => $issued, 'confirm_nonce' => security_opaque_token_generate(),
    ], time() + COOPERATIVE_PAIRING_LIFETIME);
    $pair = cooperative_pairing_store_new($pair, $peer);
    return ['pairing' => cooperative_pairing_projection($pair), 'invitation_code' => cooperative_pairing_code_for_record($pair)];
}

/** Import a targeted invitation without accepting friendship or issuing a system credential.
 *
 * @param string $code Bounded user-transferred invitation code.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_import(string $code): array
{
    cooperative_pairing_require_enabled();
    cooperative_pairing_storage_assert();
    $descriptor = cooperative_pairing_invitation_decode($code);
    if ($descriptor['target_id'] !== cooperative_instance_id() || $descriptor['target_base'] !== cooperative_pairing_local_base()) {
        throw new CooperativeException('wrong_invitation_recipient');
    }
    $existing = \Gallery\Models\cooperative_pairing_model_find($descriptor['invitation_id']);
    if ($existing !== null) {
        $peer = cooperative_peer_status($descriptor['source_id']);
        if ($existing['role'] !== 'invitee' || $existing['peer_id'] !== $descriptor['source_id']
            || ($peer['base_url'] ?? '') !== $descriptor['source_base']
            || !security_authority_token_verify($existing['secret_hash'], $descriptor['secret'])) {
            throw new CooperativeException('request_conflict');
        }
        return cooperative_pairing_projection(cooperative_pairing_unpack($existing));
    }
    $remote = cooperative_pairing_discover($descriptor['source_base']);
    if ($remote['instance_id'] !== $descriptor['source_id']) {
        throw new CooperativeException('peer_identity_invalid');
    }
    $peer = cooperative_peer_new(cooperative_instance_id(), $remote['instance_id'], $remote['base_url']);
    $pair = cooperative_pairing_new_record($descriptor['invitation_id'], $peer['instance_id'], 'invitee', 'received', $descriptor['secret'], [
        'local_base' => $descriptor['target_base'], 'remote_base' => $descriptor['source_base'],
        'invite_secret' => $descriptor['secret'],
    ], 0);
    $response = cooperative_pairing_request($remote['base_url'], cooperative_pairing_message($pair, 'inspect'), $descriptor['secret']);
    cooperative_pairing_check_response($response, $pair);
    if (!is_int($response['expires_at'] ?? null) || $response['expires_at'] <= time()
        || $response['expires_at'] > time() + COOPERATIVE_PAIRING_LIFETIME) {
        throw new CooperativeException('invitation_expired');
    }
    $pair['expires_at'] = $response['expires_at'];
    return cooperative_pairing_projection(cooperative_pairing_store_new($pair, $peer));
}

/** Record local administrator consent and issue the invitee credential exactly once.
 *
 * @param string $invitationId Stable public invitation identifier.
 * @param int $expectedRevision Expected local optimistic revision from the administrator snapshot.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_accept(string $invitationId, int $expectedRevision): array
{
    cooperative_pairing_require_enabled();
    cooperative_pairing_change($invitationId, /** Apply the local transition or bounded response callback.
     * @param array<string,mixed> $pair Internal pairing record; encrypted recovery payload stays server-side.
     * @param array<string,mixed> $peer Direct peer credential record.
     * @return array<string,mixed> Transition result or accepted byte count.
     */ static function (array $pair, array $peer) use ($expectedRevision): array {
        cooperative_pairing_assert_live($pair);
        if ($pair['revision'] !== $expectedRevision || $pair['role'] !== 'invitee' || $pair['state'] !== 'received'
            || $peer['state'] !== 'pending') {
            throw new CooperativeException('revision_conflict');
        }
        $pair['payload']['issued_token'] = cooperative_credential_generate();
        $pair['payload']['challenge_nonce'] = security_opaque_token_generate();
        $peer['incoming_hash'] = security_authority_token_hash($pair['payload']['issued_token']);
        $pair['state'] = 'accepting';
        return [$pair, $peer];
    });
    return cooperative_pairing_resume($invitationId);
}

/** Advance an accepted invitation without regenerating secrets after a lost response.
 *
 * @param array<string,mixed> $pair Internal pairing record; encrypted recovery payload stays server-side.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_exchange(array $pair): array
{
    if ($pair['state'] === 'accepting') {
        cooperative_pairing_assert_live($pair);
        $response = cooperative_pairing_request($pair['payload']['remote_base'], cooperative_pairing_message($pair, 'accept', [
            'key' => $pair['payload']['issued_token'], 'nonce' => $pair['payload']['challenge_nonce'],
        ]), $pair['payload']['invite_secret']);
        cooperative_pairing_check_response($response, $pair);
        if (!is_string($response['key'] ?? null) || !cooperative_credential_valid($response['key'])
            || !is_string($response['nonce'] ?? null) || !cooperative_pairing_nonce_valid($response['nonce'])) {
            throw new CooperativeException('peer_response_invalid');
        }
        $pair = cooperative_pairing_change($pair['invitation_id'], /** Apply the local transition or bounded response callback.
         * @param array<string,mixed> $current Locked current pairing record.
         * @param array<string,mixed> $peer Direct peer credential record.
         * @return array<string,mixed> Transition result or accepted byte count.
         */ static function (array $current, array $peer) use ($response): array {
            if ($current['state'] === 'confirming' || $current['state'] === 'active') {
                return [$current, $peer];
            }
            cooperative_pairing_assert_live($current);
            if ($current['state'] !== 'accepting' || ($current['payload']['challenged'] ?? false) !== true || $peer['state'] !== 'pending') {
                throw new CooperativeException('pairing_unavailable');
            }
            $peer['outgoing_cipher'] = security_secret_seal($response['key'], cooperative_storage_key(),
                cooperative_credential_context(cooperative_instance_id(), $peer['instance_id']));
            foreach (['local_consent', 'remote_consent', 'inbound_verified'] as $fact) {
                $peer = cooperative_peer_confirm($peer, $fact);
            }
            $current['payload']['confirm_nonce'] = $response['nonce'];
            $current['state'] = 'confirming';
            return [$current, $peer];
        });
    }
    if ($pair['state'] === 'confirming') {
        // A repeated confirmation may acknowledge an activation completed before expiry.
        // The inviter refuses a first activation after expiry.
        $peer = \Gallery\Models\cooperative_model_peer_find($pair['peer_id']);
        if ($peer === null || $peer['state'] !== 'pending') {
            throw new CooperativeException('peer_inactive');
        }
        $token = security_secret_open((string) $peer['outgoing_cipher'], cooperative_storage_key(),
            cooperative_credential_context(cooperative_instance_id(), $pair['peer_id']));
        if ($token === null) {
            throw new CooperativeException('credential_unavailable');
        }
        $nonce = $pair['payload']['confirm_nonce'];
        $response = cooperative_pairing_request($pair['payload']['remote_base'],
            cooperative_pairing_message($pair, 'confirm', ['nonce' => $nonce]), $token);
        cooperative_pairing_check_response($response, $pair);
        if (!is_string($response['proof'] ?? null)
            || !hash_equals(cooperative_pairing_proof($token, $pair['invitation_id'], 'confirm', $nonce), $response['proof'])) {
            throw new CooperativeException('peer_response_invalid');
        }
        $pair = cooperative_pairing_change($pair['invitation_id'], /** Apply the local transition or bounded response callback.
         * @param array<string,mixed> $current Locked current pairing record.
         * @param array<string,mixed> $peer Direct peer credential record.
         * @return array<string,mixed> Transition result or accepted byte count.
         */ static function (array $current, array $peer): array {
            if ($current['state'] === 'active') {
                return [$current, $peer];
            }
            if ($current['state'] !== 'confirming' || $peer['state'] !== 'pending') {
                throw new CooperativeException('pairing_unavailable');
            }
            $peer = cooperative_peer_confirm($peer, 'outbound_verified');
            $current['state'] = 'active';
            $current['payload'] = ['local_base' => $current['payload']['local_base'], 'remote_base' => $current['payload']['remote_base']];
            $current['attempts'] = 0;
            $current['next_attempt_at'] = 0;
            $current['last_error'] = '';
            return [$current, $peer];
        });
    }
    return $pair;
}

/** Resume a bounded exchange/delivery; expected network failures become recoverable state.
 *
 * @param string $invitationId Stable public invitation identifier.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_resume(string $invitationId): array
{
    cooperative_pairing_require_enabled();
    $pair = cooperative_pairing_load($invitationId);
    if (!in_array($pair['state'], ['accepting', 'confirming', 'revoking'], true)) {
        return cooperative_pairing_projection($pair);
    }
    if ($pair['next_attempt_at'] > time()) {
        throw new CooperativeException('retry_not_due');
    }
    try {
        $pair = $pair['state'] === 'revoking' ? cooperative_pairing_deliver_revocation($pair) : cooperative_pairing_exchange($pair);
    } catch (CooperativeException $error) {
        if (!in_array($error->reason, ['peer_unavailable', 'peer_response_invalid'], true)) {
            throw $error;
        }
        $pair = cooperative_pairing_change($invitationId, /** Apply the local transition or bounded response callback.
         * @param array<string,mixed> $current Locked current pairing record.
         * @param array<string,mixed> $peer Direct peer credential record.
         * @return array<string,mixed> Transition result or accepted byte count.
         */ static function (array $current, array $peer) use ($error): array {
            if (!in_array($current['state'], ['accepting', 'confirming', 'revoking'], true)) {
                return [$current, $peer];
            }
            $current['attempts'] = min(100000, $current['attempts'] + 1);
            $current['next_attempt_at'] = time() + min(300, 5 * (2 ** min(6, $current['attempts'] - 1)));
            $current['last_error'] = $error->reason;
            return [$current, $peer];
        });
    }
    return cooperative_pairing_projection($pair);
}
