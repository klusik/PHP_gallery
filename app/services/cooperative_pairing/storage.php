<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_pairing/storage.php
 * Module Type: Service
 * Purpose: Gate and serialize pairing persistence.
 * Responsibilities: Encrypt recovery material and keep network calls outside local atomic transitions.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

/** Inspect complete pairing storage without collapsing unknown into missing.
 *
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_schema_status(): array
{
    $requirements = [schema_inspection_table('cooperative_pairings')];
    foreach (['id', 'invitation_id', 'peer_id', 'role', 'state', 'revision', 'expires_at', 'secret_hash',
        'payload_cipher', 'attempts', 'next_attempt_at', 'last_error', 'updated_at'] as $column) {
        $requirements[] = schema_inspection_column('cooperative_pairings', $column);
    }
    foreach (['PRIMARY', 'cooperative_pairings_invitation', 'cooperative_pairings_peer'] as $index) {
        $requirements[] = schema_inspection_index('cooperative_pairings', $index);
    }
    return schema_inspection_feature('cooperative.pairing', $requirements);
}

/** Preflight every required store before credential creation or persistent mutation.
 *
 * @return void No return value; refusals raise an exception.
 */
function cooperative_pairing_storage_assert(): void
{
    cooperative_storage_assert('identity');
    cooperative_storage_assert('peers');
    $state = cooperative_pairing_schema_status();
    if (!schema_inspection_is_available($state)) {
        throw new CooperativeException(schema_inspection_is_missing($state) ? 'schema_missing' : 'schema_unknown');
    }
}

/** Encrypt resumable delivery authority for exactly one local invitation.
 *
 * @param array<string,mixed> $pair Internal pairing record; encrypted recovery payload stays server-side.
 * @return string Result described by the operation above.
 */
function cooperative_pairing_pack(array $pair): string
{
    return security_secret_seal(json_encode($pair['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        cooperative_storage_key(), 'cooperative-pairing:' . cooperative_instance_id() . ':' . $pair['invitation_id']);
}

/** Read encrypted local state; no remote body is accepted as a persisted snapshot.
 *
 * @param array<string,mixed> $pair Internal pairing record; encrypted recovery payload stays server-side.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_unpack(array $pair): array
{
    $plain = security_secret_open($pair['payload_cipher'], cooperative_storage_key(),
        'cooperative-pairing:' . cooperative_instance_id() . ':' . $pair['invitation_id']);
    if ($plain === null) {
        throw new CooperativeException('pairing_secret_unavailable');
    }
    try {
        $payload = json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
    } catch (\Throwable) {
        throw new CooperativeException('pairing_secret_unavailable');
    }
    if (!is_array($payload)) {
        throw new CooperativeException('pairing_secret_unavailable');
    }
    $pair['payload'] = $payload;
    return $pair;
}

/** Resolve one current pairing; callers must separately authenticate its intended actor.
 *
 * @param string $invitationId Stable public invitation identifier.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_load(string $invitationId): array
{
    cooperative_pairing_storage_assert();
    $pair = \Gallery\Models\cooperative_pairing_model_find(cooperative_id_validate($invitationId));
    if ($pair === null) {
        throw new CooperativeException('pairing_not_found');
    }
    return cooperative_pairing_unpack($pair);
}

/**
 * Apply a short local transition under row locks and optimistic guards.
 * The callback must not call transport. Peer revisions change only with peer state;
 * delivery retries therefore cannot invalidate established friendship evidence.
 *
 * @param string $invitationId Stable public invitation identifier.
 * @param callable $operation Trusted local callback; never obtained from request data.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_change(string $invitationId, callable $operation): array
{
    cooperative_pairing_storage_assert();
    cooperative_id_validate($invitationId);
    return \Gallery\Models\cooperative_pairing_model_transaction(/** Apply the local transition or bounded response callback.
     * @return array<string,mixed> Transition result or accepted byte count.
     */ static function () use ($invitationId, $operation): array {
        $stored = \Gallery\Models\cooperative_pairing_model_find($invitationId, true);
        if ($stored === null) {
            throw new CooperativeException('pairing_not_found');
        }
        $pair = cooperative_pairing_unpack($stored);
        $peer = \Gallery\Models\cooperative_pairing_model_lock_peer($pair['peer_id']);
        if ($peer === null) {
            throw new CooperativeException('peer_unavailable');
        }
        [$next, $nextPeer] = $operation($pair, $peer);
        $next['payload_cipher'] = cooperative_pairing_pack($next);
        if ($nextPeer !== $peer && !\Gallery\Models\cooperative_model_peer_save($nextPeer, $peer['revision'], gmdate('Y-m-d H:i:s'))) {
            throw new CooperativeException('revision_conflict');
        }
        if (!\Gallery\Models\cooperative_pairing_model_save($next, gmdate('Y-m-d H:i:s'))) {
            throw new CooperativeException('revision_conflict');
        }
        $next['revision']++;
        return $next;
    });
}

/** Insert the invitation and pending peer in one transaction, with no network under locks.
 *
 * @param array<string,mixed> $pair Internal pairing record; encrypted recovery payload stays server-side.
 * @param array<string,mixed> $peer Direct peer credential record.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_store_new(array $pair, array $peer): array
{
    cooperative_pairing_storage_assert();
    $pair['payload_cipher'] = cooperative_pairing_pack($pair);
    return \Gallery\Models\cooperative_pairing_model_transaction(
        /** Start a fresh lifecycle only after old authority has been revoked.
         * @return array<string,mixed> Persisted new invitation with monotonic peer generation.
         */ static function () use ($pair, $peer): array {
            $previous = \Gallery\Models\cooperative_pairing_model_find_peer($peer['instance_id']);
            $previousPeer = \Gallery\Models\cooperative_pairing_model_lock_peer($peer['instance_id']);
            $now = gmdate('Y-m-d H:i:s');
            if ($previousPeer !== null) {
                if ($previousPeer['state'] !== 'revoked' || ($previous['state'] ?? '') === 'revoking') {
                    throw new CooperativeException('peer_already_registered');
                }
                if (!\Gallery\Models\cooperative_model_peer_save($peer, $previousPeer['revision'], $now)) {
                    throw new CooperativeException('revision_conflict');
                }
            }
            if ($previous !== null) {
                return \Gallery\Models\cooperative_pairing_model_replace($pair, $previous, $now);
            }
            $pair['id'] = \Gallery\Models\cooperative_pairing_model_insert($pair, $peer, $now, $previousPeer === null);
            return $pair;
        }
    );
}

/** Reject a fresh authority-bearing action outside the invitation lifetime.
 *
 * @param array<string,mixed> $pair Internal pairing record; encrypted recovery payload stays server-side.
 * @return void No return value; refusals raise an exception.
 */
function cooperative_pairing_assert_live(array $pair): void
{
    if ($pair['expires_at'] <= time()) {
        throw new CooperativeException('invitation_expired');
    }
}
