<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_pairing/protocol.php
 * Module Type: Service
 * Purpose: Validate bilateral protocol identities, invitations and challenge responses.
 * Responsibilities: Reject unknown fields and bind every message to its intended installations.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

/** Require explicit enablement before schema, identity or network work.
 *
 * @return void No return value; refusals raise an exception.
 */
function cooperative_pairing_require_enabled(): void
{
    cooperative_require_enabled();
}

/** Resolve the configured canonical HTTPS base; never derive authority from the Host header.
 *
 * @return string Result described by the operation above.
 */
function cooperative_pairing_local_base(): string
{
    $base = \Gallery\Core\cms_config()['base_url'] ?? '';
    if (!is_string($base) || $base === '') {
        throw new CooperativeException('canonical_base_required');
    }
    return cooperative_pairing_base_url($base);
}

/** Require an exact JSON object vocabulary and scalar strings except protocol.
 *
 * @param array<string,mixed> $value Value whose declared contract is checked.
 * @param list<string> $fields Exact allowed protocol field names.
 * @return void No return value; refusals raise an exception.
 */
function cooperative_pairing_fields(array $value, array $fields): void
{
    $keys = array_keys($value);
    sort($keys);
    sort($fields);
    if ($keys !== $fields) {
        throw new CooperativeException('invalid_message');
    }
    foreach ($value as $key => $item) {
        if ($key === 'protocol') {
            if ($item !== COOPERATIVE_PROTOCOL_VERSION) {
                throw new CooperativeException('protocol_unsupported');
            }
        } elseif (!is_string($item) || strlen($item) > 2048) {
            throw new CooperativeException('invalid_message');
        }
    }
}

/** Validate a bootstrap secret or challenge without accepting an ordinary API credential.
 *
 * @param string $nonce Per-exchange unpredictable challenge.
 * @return bool Result described by the operation above.
 */
function cooperative_pairing_nonce_valid(string $nonce): bool
{
    return preg_match('/\A[A-Za-z0-9_-]{43}\z/', $nonce) === 1;
}

/** Encode a deliberately shareable invitation, never a long-lived system API key.
 *
 * @param array<string,mixed> $invitation Validated invitation descriptor.
 * @return string Result described by the operation above.
 */
function cooperative_pairing_invitation_code(array $invitation): string
{
    return 'pgci1.' . rtrim(strtr(base64_encode(json_encode($invitation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
}

/** Decode the exact bootstrap descriptor; its source server must still verify it.
 *
 * @param string $code Bounded user-transferred invitation code.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_invitation_decode(string $code): array
{
    if (strlen($code) > COOPERATIVE_PAIRING_CODE_MAX_BYTES || !str_starts_with($code, 'pgci1.')
        || preg_match('/\A[A-Za-z0-9_-]+\z/', substr($code, 6)) !== 1) {
        throw new CooperativeException('invalid_invitation');
    }
    try {
        $json = base64_decode(strtr(substr($code, 6), '-_', '+/'), true);
        $data = json_decode($json === false ? '' : $json, true, 8, JSON_THROW_ON_ERROR);
    } catch (\Throwable) {
        throw new CooperativeException('invalid_invitation');
    }
    if (!is_array($data)) {
        throw new CooperativeException('invalid_invitation');
    }
    cooperative_pairing_fields($data, ['protocol', 'invitation_id', 'source_id', 'target_id', 'source_base', 'target_base', 'secret']);
    foreach (['invitation_id', 'source_id', 'target_id'] as $field) {
        cooperative_id_validate($data[$field]);
    }
    foreach (['source_base', 'target_base'] as $field) {
        if (cooperative_pairing_base_url($data[$field]) !== $data[$field]) {
            throw new CooperativeException('invalid_invitation');
        }
    }
    if (!cooperative_pairing_nonce_valid($data['secret']) || $data['source_id'] === $data['target_id']) {
        throw new CooperativeException('invalid_invitation');
    }
    return $data;
}

/** Build one request scoped to the persisted peer and public invitation identity.
 *
 * @param array<string,mixed> $pair Internal pairing record; encrypted recovery payload stays server-side.
 * @param string $action Allowlisted protocol operation.
 * @param array<string,mixed> $extra Additional allowlisted fields for the message.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_message(array $pair, string $action, array $extra = []): array
{
    return array_merge([
        'protocol' => COOPERATIVE_PROTOCOL_VERSION,
        'action' => $action,
        'invitation_id' => $pair['invitation_id'],
        'sender_id' => cooperative_instance_id(),
        'recipient_id' => $pair['peer_id'],
    ], $extra);
}

/** Verify the response identity before consuming a remote secret or acknowledgement.
 *
 * @param array<string,mixed> $response Remote response to verify against the local pairing.
 * @param array<string,mixed> $pair Internal pairing record; encrypted recovery payload stays server-side.
 * @return void No return value; refusals raise an exception.
 */
function cooperative_pairing_check_response(array $response, array $pair): void
{
    if (($response['ok'] ?? null) !== true || ($response['protocol'] ?? null) !== COOPERATIVE_PROTOCOL_VERSION
        || ($response['invitation_id'] ?? null) !== $pair['invitation_id']
        || ($response['sender_id'] ?? null) !== $pair['peer_id']
        || ($response['recipient_id'] ?? null) !== cooperative_instance_id()) {
        throw new CooperativeException('peer_response_invalid');
    }
}

/** Produce an identity-bound protocol acknowledgement without database fields.
 *
 * @param array<string,mixed> $pair Internal pairing record; encrypted recovery payload stays server-side.
 * @param array<string,mixed> $extra Additional allowlisted fields for the message.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_response(array $pair, array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'protocol' => COOPERATIVE_PROTOCOL_VERSION,
        'invitation_id' => $pair['invitation_id'],
        'sender_id' => cooperative_instance_id(),
        'recipient_id' => $pair['peer_id'],
    ], $extra);
}

/** Bind a challenge acknowledgement to this operation, pair and nonce.
 *
 * @param string $token Directional system credential used to prove possession.
 * @param string $invitationId Stable public invitation identifier.
 * @param string $action Allowlisted protocol operation.
 * @param string $nonce Per-exchange unpredictable challenge.
 * @return string Result described by the operation above.
 */
function cooperative_pairing_proof(string $token, string $invitationId, string $action, string $nonce): string
{
    return hash_hmac('sha256', 'cooperative-v1|' . $invitationId . '|' . $action . '|' . $nonce, $token);
}

/** Bound installation addresses to keep encrypted recovery payloads within their storage envelope.
 *
 * @param string $base Canonical HTTPS installation base.
 * @return string Result described by the operation above.
 */
function cooperative_pairing_base_url(string $base): string
{
    $base = cooperative_peer_base_url($base);
    if (strlen($base) > 1024) {
        throw new CooperativeException('invalid_peer_url');
    }
    return $base;
}
