<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_galleries/credentials.php
 * Module Type: Service
 * Purpose: Own dedicated peer credentials and pairing state.
 * Responsibilities: Keep inbound hashes separate from context-bound encrypted outbound keys.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 * Notes: Pairing confirmations are trusted adapter facts, never raw request payloads.
 */
declare(strict_types=1);

namespace Gallery\Services;

/** Validate the separate cooperative credential namespace.
 *
 * @param string $token Plaintext cooperative credential; never log or expose in a view model.
 * @return bool Whether the exact validation or authorization contract holds.
 */
function cooperative_credential_valid(string $token): bool
{
    return preg_match('/\Apgc_[A-Za-z0-9_-]{43}\z/', $token) === 1;
}

/** Generate a secret that the ordinary upload API cannot authenticate.
 *
 * @return string Canonical identifier, digest or secret as described above.
 */
function cooperative_credential_generate(): string
{
    return 'pgc_' . security_opaque_token_generate(32);
}

/**
 * Validate configured storage material without rewriting or rotating existing secrets.
 * Reject documented templates and short repeating patterns, not arbitrary passphrases.
 * Valid material is consumed byte-for-byte so existing encrypted credentials remain usable.
 *
 * @param string|int|float|bool|null|array<array-key,mixed>|object|resource $secret Existing configuration value; only usable strings are accepted.
 * @return bool Whether the configured material is usable for cooperative storage.
 */
function cooperative_storage_secret_valid(mixed $secret): bool
{
    if (!is_string($secret) || strlen($secret) < 32 || strlen(trim($secret)) < 32) {
        return false;
    }
    if (in_array(strtolower(trim($secret)), [
        'replace-with-a-long-random-secret',
        'replace-with-a-temporary-setup-key',
    ], true)) {
        return false;
    }
    return preg_match('/\A(.{1,4})\1+\z/s', $secret) !== 1;
}

/** Derive a purpose-specific storage key, refusing absent/weak configuration.
 *
 * @return string Canonical identifier, digest or secret as described above.
 */
function cooperative_storage_key(): string
{
    $config = \Gallery\Core\cms_config();
    $secret = $config['visitor_vote_secret'] ?? '';
    if (!cooperative_storage_secret_valid($secret)) {
        throw new CooperativeException('secret_unavailable');
    }
    return hash_hkdf('sha256', $secret, 32, 'cooperative-galleries-storage-v1');
}

/** Bind encrypted remote authority to both installation identities and direction.
 *
 * @param string $local Stable identity of this installation, which owns the source content.
 * @param string $remote Stable identity of the directly paired remote installation.
 * @return string Canonical identifier, digest or secret as described above.
 */
function cooperative_credential_context(string $local, string $remote): string
{
    return 'cooperative:outbound:v1:' . cooperative_id_validate($local) . ':' . cooperative_id_validate($remote);
}

/** Build a pending peer without granting album access.
 *
 * @param string $local Stable identity of this installation, which owns the source content.
 * @param string $remote Stable identity of the directly paired remote installation.
 * @param string $baseUrl HTTPS installation base address, without secrets or query parameters.
 * @return array<string,mixed> Validated domain state described above.
 */
function cooperative_peer_new(string $local, string $remote, string $baseUrl): array
{
    cooperative_id_validate($local);
    cooperative_id_validate($remote);
    if ($local === $remote) {
        throw new CooperativeException('self_friendship');
    }
    return [
        'instance_id' => $remote,
        'base_url' => cooperative_peer_base_url($baseUrl),
        'state' => 'pending',
        'revision' => 1,
        'incoming_hash' => null,
        'outgoing_cipher' => null,
        'confirmations' => [],
    ];
}

/**
 * Apply one already verified pairing fact to the current credential generation.
 * The future adapter MUST establish administrator consent or authenticated
 * challenge completion before calling this helper. A coordinator assertion is
 * not evidence. Rotating credentials clears every confirmation.
 *
 * @param array<string,mixed> $peer Internal peer record containing pairing state and credential material.
 * @param string $fact Verified pairing event for the current credential generation.
 * @return array<string,mixed> Validated domain state described above.
 */
function cooperative_peer_confirm(array $peer, string $fact): array
{
    if ($peer['state'] !== 'pending' || !in_array($fact, [
        'local_consent', 'remote_consent', 'inbound_verified', 'outbound_verified',
    ], true) || empty($peer['incoming_hash']) || empty($peer['outgoing_cipher'])) {
        throw new CooperativeException('invalid_pairing_confirmation');
    }
    $peer['confirmations'][$fact] = true;
    if (($peer['confirmations']['local_consent'] ?? false) === true
        && ($peer['confirmations']['remote_consent'] ?? false) === true
        && ($peer['confirmations']['inbound_verified'] ?? false) === true
        && ($peer['confirmations']['outbound_verified'] ?? false) === true) {
        $peer['state'] = 'active';
    }
    return $peer;
}

/** Project only nonsecret status for future administrative view models.
 *
 * @param array<string,mixed> $peer Internal peer record containing pairing state and credential material.
 * @return array<string,mixed> Validated domain state described above.
 */
function cooperative_peer_summary(array $peer): array
{
    return array_intersect_key($peer, array_flip(['instance_id', 'base_url', 'state', 'revision']));
}

/** Create an isolated pending peer; address changes require a new pairing workflow.
 *
 * @param string $remote Stable identity of the directly paired remote installation.
 * @param string $baseUrl HTTPS installation base address, without secrets or query parameters.
 * @return array<string,mixed> Validated domain state described above.
 */
function cooperative_peer_register(string $remote, string $baseUrl): array
{
    cooperative_storage_assert('peers');
    $peer = cooperative_peer_new(cooperative_instance_id(), $remote, $baseUrl);
    \Gallery\Models\cooperative_model_peer_insert($peer, gmdate('Y-m-d H:i:s'));
    return cooperative_peer_summary($peer);
}

/**
 * Prepare/rotate both directions and reset pairing proofs before activation.
 * Returns the new inbound secret once for secure delivery to the named partner.
 * Revoked peers cannot be revived through credential rotation.
 *
 * @param string $remote Stable identity of the directly paired remote installation.
 * @param int $expectedRevision Expected current peer revision to prevent stale writes.
 * @param ?string $remoteToken Partner-issued credential, or null when initiating the exchange.
 * @return array<string,mixed> Validated domain state described above.
 */
function cooperative_peer_prepare_credentials(string $remote, int $expectedRevision, ?string $remoteToken = null): array
{
    if ($remoteToken !== null && !cooperative_credential_valid($remoteToken)) {
        throw new CooperativeException('invalid_credential');
    }
    $peer = cooperative_peer_load_for_change($remote, $expectedRevision);
    if ($peer['state'] === 'revoked') {
        throw new CooperativeException('peer_revoked');
    }
    $local = cooperative_instance_id();
    $key = cooperative_storage_key();
    $cipher = $remoteToken === null ? null : security_secret_seal($remoteToken, $key, cooperative_credential_context($local, $remote));
    $token = cooperative_credential_generate();
    $peer['incoming_hash'] = security_authority_token_hash($token);
    $peer['outgoing_cipher'] = $cipher;
    $peer['confirmations'] = [];
    $peer['state'] = 'pending';
    cooperative_peer_save($peer, $expectedRevision);
    return ['peer' => cooperative_peer_summary(array_replace($peer, ['revision' => $expectedRevision + 1])), 'token' => $token];
}

/** Record a trusted handshake fact with optimistic concurrency protection.
 *
 * @param string $remote Stable identity of the directly paired remote installation.
 * @param int $expectedRevision Expected current peer revision to prevent stale writes.
 * @param string $fact Verified pairing event for the current credential generation.
 * @return array<string,mixed> Validated domain state described above.
 */
function cooperative_peer_record_confirmation(string $remote, int $expectedRevision, string $fact): array
{
    $peer = cooperative_peer_confirm(cooperative_peer_load_for_change($remote, $expectedRevision), $fact);
    cooperative_peer_save($peer, $expectedRevision);
    return cooperative_peer_summary(array_replace($peer, ['revision' => $expectedRevision + 1]));
}

/** Suspend without deleting credentials; resumption requires fresh pairing confirmations.
 *
 * @param string $remote Stable identity of the directly paired remote installation.
 * @param int $expectedRevision Expected current peer revision to prevent stale writes.
 * @return void No return value; failure raises an exception.
 */
function cooperative_peer_suspend(string $remote, int $expectedRevision): void
{
    $peer = cooperative_peer_load_for_change($remote, $expectedRevision);
    if ($peer['state'] === 'revoked') {
        throw new CooperativeException('peer_revoked');
    }
    $peer['state'] = 'suspended';
    $peer['confirmations'] = [];
    cooperative_peer_save($peer, $expectedRevision);
}

/** Authenticate identity only; callers must separately authorize the exact album/revision.
 *
 * @param string $remote Stable identity of the directly paired remote installation.
 * @param string $token Plaintext cooperative credential; never log or expose in a view model.
 * @return array<string,mixed>|null Validated domain state, or null when no usable record exists.
 */
function cooperative_peer_authenticate(string $remote, string $token): ?array
{
    cooperative_id_validate($remote);
    if (!cooperative_credential_valid($token)) {
        return null;
    }
    cooperative_storage_assert('peers');
    $peer = \Gallery\Models\cooperative_model_peer_find($remote);
    if ($peer === null || $peer['state'] !== 'active'
        || !security_authority_token_verify((string) $peer['incoming_hash'], $token)) {
        return null;
    }
    return cooperative_peer_summary($peer);
}

/** Decrypt only for a server-side request after exact group/source authorization.
 *
 * @param string $remote Stable identity of the directly paired remote installation.
 * @return string Canonical identifier, digest or secret as described above.
 */
function cooperative_peer_outbound_credential(string $remote): string
{
    cooperative_storage_assert('peers');
    $peer = \Gallery\Models\cooperative_model_peer_find(cooperative_id_validate($remote));
    if ($peer === null || $peer['state'] !== 'active') {
        throw new CooperativeException('peer_inactive');
    }
    $token = security_secret_open((string) $peer['outgoing_cipher'], cooperative_storage_key(),
        cooperative_credential_context(cooperative_instance_id(), $remote));
    if ($token === null || !cooperative_credential_valid($token)) {
        throw new CooperativeException('credential_unavailable');
    }
    return $token;
}

/** Revoke even when outbound-secret or optional pairing storage is unavailable.
 *
 * @param string $remote Stable identity of the directly paired remote installation.
 * @return void No return value; failure raises an exception.
 */
function cooperative_peer_revoke(string $remote): void
{
    cooperative_id_validate($remote);
    cooperative_storage_assert('revoke');
    \Gallery\Models\cooperative_model_peer_revoke($remote);
}
/**
 * Verify an inbound pairing challenge against the current pending credential.
 * The transport must additionally bind the exchange to its invitation and nonce.
 *
 * @param string $remote Stable identity of the directly paired installation.
 * @param int $expectedRevision Current pending peer revision.
 * @param string $token Credential presented in an authenticated pairing exchange.
 * @return array<string,mixed> Nonsecret peer status after recording the inbound proof.
 */
function cooperative_peer_verify_pairing_token(string $remote, int $expectedRevision, string $token): array
{
    $peer = cooperative_peer_load_for_change($remote, $expectedRevision);
    if ($peer['state'] !== 'pending' || !cooperative_credential_valid($token)
        || !security_authority_token_verify((string) $peer['incoming_hash'], $token)) {
        throw new CooperativeException('pairing_credential_invalid');
    }
    return cooperative_peer_record_confirmation($remote, $expectedRevision, 'inbound_verified');
}

/**
 * Retrieve a pending outbound credential for challenge verification only.
 * The future transport must restrict this secret to the verified pairing endpoint;
 * possessing it does not permit album requests while pairing is pending.
 *
 * @param string $remote Stable identity of the directly paired installation.
 * @param int $expectedRevision Exact pending credential generation to verify.
 * @return string Plaintext remote credential for server-side pairing transport only.
 */
function cooperative_peer_pairing_credential(string $remote, int $expectedRevision): string
{
    $peer = cooperative_peer_load_for_change($remote, $expectedRevision);
    if ($peer['state'] !== 'pending') {
        throw new CooperativeException('pairing_unavailable');
    }
    $token = security_secret_open((string) $peer['outgoing_cipher'], cooperative_storage_key(),
        cooperative_credential_context(cooperative_instance_id(), $remote));
    if ($token === null || !cooperative_credential_valid($token)) {
        throw new CooperativeException('credential_unavailable');
    }
    return $token;
}

/**
 * Read a nonsecret peer projection for internal orchestration and view models.
 *
 * @param string $remote Stable identity of the directly paired installation.
 * @return array<string,mixed>|null Nonsecret status, or null when no such peer exists.
 */
function cooperative_peer_status(string $remote): ?array
{
    cooperative_storage_assert('peers');
    $peer = \Gallery\Models\cooperative_model_peer_find(cooperative_id_validate($remote));
    return $peer === null ? null : cooperative_peer_summary($peer);
}

/**
 * Complete the second half of a pending key exchange without replacing the issued key.
 * The transport must independently verify the peer before delivering its credential.
 *
 * @param string $remote Stable identity of the paired installation.
 * @param int $expectedRevision Exact pending peer revision.
 * @param string $remoteToken Verified remote-issued credential for outbound requests.
 * @return array<string,mixed> Nonsecret pending peer projection at the next revision.
 */
function cooperative_peer_accept_remote_credential(string $remote, int $expectedRevision, string $remoteToken): array
{
    if (!cooperative_credential_valid($remoteToken)) {
        throw new CooperativeException('invalid_credential');
    }
    $peer = cooperative_peer_load_for_change($remote, $expectedRevision);
    if ($peer['state'] !== 'pending' || empty($peer['incoming_hash']) || $peer['outgoing_cipher'] !== null) {
        throw new CooperativeException('pairing_unavailable');
    }
    $peer['outgoing_cipher'] = security_secret_seal($remoteToken, cooperative_storage_key(),
        cooperative_credential_context(cooperative_instance_id(), $remote));
    cooperative_peer_save($peer, $expectedRevision);
    return cooperative_peer_summary(array_replace($peer, ['revision' => $expectedRevision + 1]));
}
