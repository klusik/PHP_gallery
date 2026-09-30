<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_pairing/inbound.php
 * Module Type: Service
 * Purpose: Authenticate protocol operations and verify reverse challenges.
 * Responsibilities: Require invitation-bound identities, immutable exchange inputs and current credentials.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

/** Validate the exact action vocabulary before any storage or network work.
 *
 * @param array<string,mixed> $message Validated protocol message or diagnostic assertion text.
 * @return void No return value; refusals raise an exception.
 */
function cooperative_pairing_validate_message(array $message): void
{
    $extras = [
        'inspect' => [], 'accept' => ['key', 'nonce'], 'challenge' => ['nonce'],
        'confirm' => ['nonce'], 'revoke' => ['message_id'], 'decline' => ['message_id'],
        'status' => [],
    ];
    $action = $message['action'] ?? null;
    if (!is_string($action) || !isset($extras[$action])) {
        throw new CooperativeException('invalid_message');
    }
    cooperative_pairing_fields($message, array_merge(['protocol', 'action', 'invitation_id', 'sender_id', 'recipient_id'], $extras[$action]));
    foreach (['invitation_id', 'sender_id', 'recipient_id'] as $field) {
        cooperative_id_validate($message[$field]);
    }
    if (isset($message['message_id'])) {
        cooperative_id_validate($message['message_id']);
    }
    if (isset($message['nonce']) && !cooperative_pairing_nonce_valid($message['nonce'])) {
        throw new CooperativeException('invalid_message');
    }
    if (isset($message['key']) && !cooperative_credential_valid($message['key'])) {
        throw new CooperativeException('invalid_message');
    }
}

/** Authenticate one exact invitation or system-key operation without trusting cookies.
 *
 * @param array<string,mixed> $message Validated protocol message or diagnostic assertion text.
 * @param string $bearer Secret presented only in the Authorization header.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_receive(array $message, string $bearer): array
{
    cooperative_pairing_require_enabled();
    cooperative_pairing_validate_message($message);
    if ((!cooperative_credential_valid($bearer) && !cooperative_pairing_nonce_valid($bearer))
        || $message['recipient_id'] !== cooperative_instance_id()) {
        throw new CooperativeException('pairing_unauthorized');
    }
    $pair = cooperative_pairing_load($message['invitation_id']);
    if ($pair['peer_id'] !== $message['sender_id']) {
        throw new CooperativeException('pairing_unauthorized');
    }
    $action = $message['action'];
    if (in_array($action, ['revoke', 'decline'], true)) {
        return cooperative_pairing_receive_revocation($pair, $message, $bearer);
    }
    if (in_array($action, ['inspect', 'accept'], true)) {
        if ($pair['role'] !== 'inviter' || !security_authority_token_verify($pair['secret_hash'], $bearer)) {
            throw new CooperativeException('pairing_unauthorized');
        }
        cooperative_pairing_assert_live($pair);
        if ($action === 'inspect') {
            if ($pair['state'] !== 'invited') {
                throw new CooperativeException('invitation_unavailable');
            }
            return cooperative_pairing_response($pair, ['expires_at' => $pair['expires_at']]);
        }
        return cooperative_pairing_receive_accept($pair, $message);
    }
    $peer = \Gallery\Models\cooperative_model_peer_find($pair['peer_id']);
    if ($peer === null || !in_array($peer['state'], ['pending', 'active'], true)
        || !cooperative_credential_valid($bearer)
        || !security_authority_token_verify((string) $peer['incoming_hash'], $bearer)) {
        throw new CooperativeException('pairing_unauthorized');
    }
    if ($action === 'status') {
        if ($peer['state'] !== 'active' || $pair['state'] !== 'active') {
            throw new CooperativeException('peer_inactive');
        }
        return cooperative_pairing_response($pair, ['state' => 'active', 'peer_revision' => $peer['revision']]);
    }
    if ($action === 'challenge') {
        return cooperative_pairing_receive_challenge($pair, $message, $bearer);
    }
    return cooperative_pairing_receive_confirm($pair, $message, $bearer);
}

/** Bind the peer's issued key and nonce once, then prove the destination over pinned HTTPS.
 *
 * @param array<string,mixed> $pair Internal pairing record; encrypted recovery payload stays server-side.
 * @param array<string,mixed> $message Validated protocol message or diagnostic assertion text.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_receive_accept(array $pair, array $message): array
{
    $digest = hash('sha256', $message['key'] . '|' . $message['nonce']);
    if (!in_array($pair['state'], ['invited', 'offered'], true)) {
        throw new CooperativeException('pairing_unavailable');
    }
    if (isset($pair['payload']['exchange_digest']) && !hash_equals($pair['payload']['exchange_digest'], $digest)) {
        throw new CooperativeException('exchange_conflict');
    }
    if ($pair['state'] === 'invited') {
        // Verify the intended origin BEFORE reserving a key or consuming the invitation.
        // Possession of a stolen invitation alone cannot poison the legitimate exchange.
        $response = cooperative_pairing_request($pair['payload']['remote_base'],
            cooperative_pairing_message($pair, 'challenge', ['nonce' => $message['nonce']]), $message['key']);
        cooperative_pairing_check_response($response, $pair);
        if (!is_string($response['proof'] ?? null)
            || !hash_equals(cooperative_pairing_proof($message['key'], $pair['invitation_id'], 'challenge', $message['nonce']), $response['proof'])) {
            throw new CooperativeException('peer_response_invalid');
        }
        $pair = cooperative_pairing_change($pair['invitation_id'], /** Apply the local transition or bounded response callback.
         * @param array<string,mixed> $current Locked current pairing record.
         * @param array<string,mixed> $peer Direct peer credential record.
         * @return array<string,mixed> Transition result or accepted byte count.
         */ static function (array $current, array $peer) use ($digest, $message): array {
            cooperative_pairing_assert_live($current);
            if (!in_array($current['state'], ['invited', 'offered'], true) || $peer['state'] !== 'pending'
                || (isset($current['payload']['exchange_digest']) && $current['payload']['exchange_digest'] !== $digest)) {
                throw new CooperativeException('pairing_unavailable');
            }
            if ($current['state'] === 'invited') {
                $peer['outgoing_cipher'] = security_secret_seal($message['key'], cooperative_storage_key(),
                    cooperative_credential_context(cooperative_instance_id(), $peer['instance_id']));
                $current['payload']['exchange_digest'] = $digest;
            }
            foreach (['local_consent', 'remote_consent', 'outbound_verified'] as $fact) {
                $peer = cooperative_peer_confirm($peer, $fact);
            }
            $current['state'] = 'offered';
            return [$current, $peer];
        });
    }
    return cooperative_pairing_response($pair, ['key' => $pair['payload']['issued_token'], 'nonce' => $pair['payload']['confirm_nonce']]);
}

/** Prove the accepting installation controls the intended origin and issued credential.
 *
 * @param array<string,mixed> $pair Internal pairing record; encrypted recovery payload stays server-side.
 * @param array<string,mixed> $message Validated protocol message or diagnostic assertion text.
 * @param string $bearer Secret presented only in the Authorization header.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_receive_challenge(array $pair, array $message, string $bearer): array
{
    $pair = cooperative_pairing_change($pair['invitation_id'], /** Apply the local transition or bounded response callback.
     * @param array<string,mixed> $current Locked current pairing record.
     * @param array<string,mixed> $peer Direct peer credential record.
     * @return array<string,mixed> Transition result or accepted byte count.
     */ static function (array $current, array $peer) use ($message, $bearer): array {
        cooperative_pairing_assert_live($current);
        if ($current['role'] !== 'invitee' || !in_array($current['state'], ['accepting', 'confirming'], true)
            || !hash_equals((string) ($current['payload']['challenge_nonce'] ?? ''), $message['nonce'])
            || !security_authority_token_verify((string) $peer['incoming_hash'], $bearer)
            || $peer['state'] !== 'pending') {
            throw new CooperativeException('pairing_unauthorized');
        }
        $current['payload']['challenged'] = true;
        return [$current, $peer];
    });
    return cooperative_pairing_response($pair, ['proof' => cooperative_pairing_proof($bearer, $pair['invitation_id'], 'challenge', $message['nonce'])]);
}

/** Activate the inviter only after the peer proves possession of its issued credential.
 *
 * @param array<string,mixed> $pair Internal pairing record; encrypted recovery payload stays server-side.
 * @param array<string,mixed> $message Validated protocol message or diagnostic assertion text.
 * @param string $bearer Secret presented only in the Authorization header.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_receive_confirm(array $pair, array $message, string $bearer): array
{
    $pair = cooperative_pairing_change($pair['invitation_id'], /** Apply the local transition or bounded response callback.
     * @param array<string,mixed> $current Locked current pairing record.
     * @param array<string,mixed> $peer Direct peer credential record.
     * @return array<string,mixed> Transition result or accepted byte count.
     */ static function (array $current, array $peer) use ($message, $bearer): array {
        if ($current['role'] !== 'inviter' || !in_array($current['state'], ['offered', 'active'], true)
            || !hash_equals((string) ($current['payload']['confirm_nonce'] ?? ''), $message['nonce'])
            || !security_authority_token_verify((string) $peer['incoming_hash'], $bearer)
            || !in_array($peer['state'], ['pending', 'active'], true)) {
            throw new CooperativeException('pairing_unauthorized');
        }
        if ($current['state'] !== 'active') {
            cooperative_pairing_assert_live($current);
            $peer = cooperative_peer_confirm($peer, 'inbound_verified');
            $current['state'] = 'active';
            $current['payload'] = [
                'local_base' => $current['payload']['local_base'], 'remote_base' => $current['payload']['remote_base'],
                'confirm_nonce' => $current['payload']['confirm_nonce'],
            ];
        }
        return [$current, $peer];
    });
    return cooperative_pairing_response($pair, ['proof' => cooperative_pairing_proof($bearer, $pair['invitation_id'], 'confirm', $message['nonce'])]);
}

/** Apply a one-way revocation, retaining only an exact hashed acknowledgement receipt.
 *
 * @param array<string,mixed> $pair Internal pairing record; encrypted recovery payload stays server-side.
 * @param array<string,mixed> $message Validated protocol message or diagnostic assertion text.
 * @param string $bearer Secret presented only in the Authorization header.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_receive_revocation(array $pair, array $message, string $bearer): array
{
    $receipt = hash('sha256', $bearer . '|' . $message['action'] . '|' . $message['message_id']);
    $pair = cooperative_pairing_change($pair['invitation_id'], /** Apply the local transition or bounded response callback.
     * @param array<string,mixed> $current Locked current pairing record.
     * @param array<string,mixed> $peer Direct peer credential record.
     * @return array<string,mixed> Transition result or accepted byte count.
     */ static function (array $current, array $peer) use ($message, $bearer, $receipt): array {
        if ($current['state'] === 'revoked' && hash_equals((string) ($current['payload']['revocation_receipt'] ?? ''), $receipt)) {
            return [$current, $peer];
        }
        $authorized = $message['action'] === 'decline'
            ? $current['role'] === 'inviter' && $current['state'] === 'invited' && security_authority_token_verify($current['secret_hash'], $bearer)
            : in_array($peer['state'], ['pending', 'active', 'suspended'], true) && cooperative_credential_valid($bearer)
                && security_authority_token_verify((string) $peer['incoming_hash'], $bearer);
        // Simultaneous disconnects may authenticate only this terminal operation
        // with a retained hash; normal peer authentication is already revoked.
        if (!$authorized && $message['action'] === 'revoke' && $current['state'] === 'revoking'
            && cooperative_credential_valid($bearer)) {
            $authorized = security_authority_token_verify((string) ($current['payload']['revocation_incoming_hash'] ?? ''), $bearer);
        }
        if (!$authorized) {
            throw new CooperativeException('pairing_unauthorized');
        }
        $peer['state'] = 'revoked';
        $peer['incoming_hash'] = null;
        $peer['outgoing_cipher'] = null;
        $peer['confirmations'] = [];
        $current['state'] = 'revoked';
        $current['payload'] = ['local_base' => $current['payload']['local_base'], 'remote_base' => $current['payload']['remote_base'], 'revocation_receipt' => $receipt];
        return [$current, $peer];
    });
    return cooperative_pairing_response($pair, ['state' => 'revoked', 'message_id' => $message['message_id']]);
}
