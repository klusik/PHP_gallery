<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_pairing/admin.php
 * Module Type: Service
 * Purpose: Prepare administrator state and revoke local authority before remote delivery.
 * Responsibilities: Expose only allowlisted projections and durable retry controls.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

/** Project invitation state for UI without keys, hashes, challenge values or encrypted payloads.
 *
 * @param array<string,mixed> $pair Internal pairing record; encrypted recovery payload stays server-side.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_projection(array $pair): array
{
    $peer = isset($pair['peer_state'], $pair['base_url'])
        ? ['state' => $pair['peer_state'], 'base_url' => $pair['base_url']]
        : cooperative_peer_status($pair['peer_id']);
    $state = ($peer['state'] ?? '') === 'revoked' && $pair['state'] !== 'revoking' ? 'revoked' : $pair['state'];
    $expired = $pair['expires_at'] <= time() && in_array($state, ['invited', 'received', 'accepting', 'exchanging', 'offered'], true);
    $actions = match ($state) {
        'received' => $expired ? ['revoke'] : ['accept', 'revoke'],
        'invited', 'exchanging', 'offered' => ['revoke'],
        'accepting', 'confirming' => $expired ? ['revoke'] : ['retry', 'revoke'],
        'active' => ['revoke'],
        'revoking' => ['retry'],
        default => [],
    };
    return [
        'id' => (int) $pair['id'], 'invitation_id' => $pair['invitation_id'], 'peer_id' => $pair['peer_id'],
        'base_url' => (string) ($peer['base_url'] ?? ''), 'role' => $pair['role'],
        'state' => $state, 'peer_state' => (string) ($peer['state'] ?? 'unknown'),
        'revision' => (int) $pair['revision'], 'expires_at' => (int) $pair['expires_at'],
        'expired' => $expired, 'next_attempt_at' => (int) $pair['next_attempt_at'],
        'attempts' => (int) $pair['attempts'], 'last_error' => (string) $pair['last_error'],
        'actions' => $actions,
    ];
}

/** Prepare bounded read-only list data without decrypting recovery secrets.
 *
 * @param int $afterId Exclusive local numeric pagination cursor.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_admin_state(int $afterId = 0): array
{
    cooperative_pairing_require_enabled();
    cooperative_pairing_storage_assert();
    $rows = \Gallery\Models\cooperative_pairing_model_list($afterId, 50);
    $items = [];
    foreach ($rows as $row) {
        $items[] = cooperative_pairing_projection($row);
    }
    return [
        'protocol' => COOPERATIVE_PROTOCOL_VERSION, 'items' => $items,
        'next_cursor' => count($items) === 50 ? (int) $items[count($items) - 1]['id'] : null,
    ];
}

/** Revoke local authority atomically with the remote notification, never waiting for the network.
 *
 * @param string $invitationId Stable public invitation identifier.
 * @param int $expectedRevision Expected local optimistic revision from the administrator snapshot.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_revoke_with_delivery(string $invitationId, int $expectedRevision): array
{
    cooperative_pairing_require_enabled();
    $pair = cooperative_pairing_change($invitationId, /** Apply the local transition or bounded response callback.
     * @param array<string,mixed> $pair Internal pairing record; encrypted recovery payload stays server-side.
     * @param array<string,mixed> $peer Direct peer credential record.
     * @return array<string,mixed> Transition result or accepted byte count.
     */ static function (array $pair, array $peer) use ($expectedRevision): array {
        if ($pair['revision'] !== $expectedRevision) {
            throw new CooperativeException('revision_conflict');
        }
        if (in_array($pair['state'], ['revoked', 'revoking'], true)) {
            return [$pair, $peer];
        }
        $authority = null;
        $action = 'revoke';
        if (is_string($peer['outgoing_cipher']) && $peer['outgoing_cipher'] !== '') {
            $authority = security_secret_open($peer['outgoing_cipher'], cooperative_storage_key(),
                cooperative_credential_context(cooperative_instance_id(), $pair['peer_id']));
        } elseif ($pair['role'] === 'invitee' && $pair['state'] === 'received') {
            $authority = $pair['payload']['invite_secret'];
            $action = 'decline';
        }
        $pair['payload'] = [
            'local_base' => $pair['payload']['local_base'], 'remote_base' => $pair['payload']['remote_base'],
        ];
        $pair['state'] = 'revoked';
        if ($authority !== null) {
            $pair['payload']['revocation_incoming_hash'] = $peer['incoming_hash'];
            $pair['payload']['delivery_token'] = $authority;
            $pair['payload']['delivery_action'] = $action;
            $pair['payload']['delivery_id'] = cooperative_id_generate();
            $pair['state'] = 'revoking';
        }
        $pair['attempts'] = 0;
        $pair['next_attempt_at'] = 0;
        $pair['last_error'] = '';
        $peer['state'] = 'revoked';
        $peer['incoming_hash'] = null;
        $peer['outgoing_cipher'] = null;
        $peer['confirmations'] = [];
        return [$pair, $peer];
    });
    return $pair['state'] === 'revoking' ? cooperative_pairing_resume($invitationId) : cooperative_pairing_projection($pair);
}

/** Deliver a stored revocation idempotently; a lost response retains the same message and secret.
 *
 * @param array<string,mixed> $pair Internal pairing record; encrypted recovery payload stays server-side.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_deliver_revocation(array $pair): array
{
    $messageId = $pair['payload']['delivery_id'];
    $response = cooperative_pairing_request($pair['payload']['remote_base'],
        cooperative_pairing_message($pair, $pair['payload']['delivery_action'], ['message_id' => $messageId]),
        $pair['payload']['delivery_token']);
    cooperative_pairing_check_response($response, $pair);
    if (($response['state'] ?? null) !== 'revoked' || ($response['message_id'] ?? null) !== $messageId) {
        throw new CooperativeException('peer_response_invalid');
    }
    return cooperative_pairing_change($pair['invitation_id'], /** Apply the local transition or bounded response callback.
     * @param array<string,mixed> $current Locked current pairing record.
     * @param array<string,mixed> $peer Direct peer credential record.
     * @return array<string,mixed> Transition result or accepted byte count.
     */ static function (array $current, array $peer) use ($messageId): array {
        if ($current['state'] === 'revoked') {
            return [$current, $peer];
        }
        if ($current['state'] !== 'revoking' || ($current['payload']['delivery_id'] ?? '') !== $messageId) {
            throw new CooperativeException('revision_conflict');
        }
        $current['state'] = 'revoked';
        $current['payload'] = ['local_base' => $current['payload']['local_base'], 'remote_base' => $current['payload']['remote_base']];
        $current['attempts'] = 0;
        $current['next_attempt_at'] = 0;
        $current['last_error'] = '';
        return [$current, $peer];
    });
}

/** Revoke with no decryption or outbound dependency when the verified recovery store is unavailable.
 *
 * @param string $invitationId Stable public invitation identifier.
 * @param int $expectedRevision Expected local optimistic revision from the administrator snapshot.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_revoke_local(string $invitationId, int $expectedRevision): array
{
    cooperative_id_validate($invitationId);
    cooperative_storage_assert('revoke');
    $requirements = [schema_inspection_table('cooperative_pairings'),
        schema_inspection_index('cooperative_pairings', 'PRIMARY'),
        schema_inspection_index('cooperative_pairings', 'cooperative_pairings_invitation')];
    foreach (['id', 'peer_id', 'invitation_id', 'revision'] as $column) {
        $requirements[] = schema_inspection_column('cooperative_pairings', $column);
    }
    $status = schema_inspection_feature('cooperative.pairing_revoke', $requirements);
    if (!schema_inspection_is_available($status)) {
        throw new CooperativeException(schema_inspection_is_missing($status) ? 'schema_missing' : 'schema_unknown');
    }
    $row = \Gallery\Models\cooperative_pairing_model_local_revoke($invitationId, $expectedRevision);
    if ($row === null) {
        throw new CooperativeException('revision_conflict');
    }
    return array_merge($row, ['invitation_id' => $invitationId, 'state' => 'revoked',
        'peer_state' => 'revoked', 'delivery_status' => 'unavailable', 'actions' => []]);
}

/** Prefer durable notification, falling back only to the narrower local revocation authority.
 *
 * @param string $invitationId Stable public invitation identifier.
 * @param int $expectedRevision Expected local optimistic revision from the administrator snapshot.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_revoke(string $invitationId, int $expectedRevision): array
{
    cooperative_pairing_require_enabled();
    try {
        return cooperative_pairing_revoke_with_delivery($invitationId, $expectedRevision);
    } catch (CooperativeException $error) {
        if (!in_array($error->reason, ['schema_missing', 'schema_unknown', 'pairing_secret_unavailable', 'secret_unavailable'], true)) {
            throw $error;
        }
        return cooperative_pairing_revoke_local($invitationId, $expectedRevision);
    }
}
