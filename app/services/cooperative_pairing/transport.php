<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_pairing/transport.php
 * Module Type: Service
 * Purpose: Adapt pairing messages to the shared pinned HTTPS transport.
 * Responsibilities: Keep one fixed endpoint and a test-only in-process transport seam.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

/** Return the optional trusted in-process test transport; never set from HTTP/configuration.
 *
 * @return callable|null Result described by the operation above.
 */
function &cooperative_pairing_transport_override(): mixed
{
    static $transport = null;
    return $transport;
}

/** Contact only the fixed pairing endpoint at a canonical validated installation base.
 *
 * @param string $base Canonical HTTPS installation base.
 * @param array<string,mixed>|null $message Validated protocol message or diagnostic assertion text.
 * @param string $bearer Secret presented only in the Authorization header.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_request(string $base, ?array $message = null, string $bearer = ''): array
{
    cooperative_pairing_require_enabled();
    $base = cooperative_pairing_base_url($base);
    $transport = &cooperative_pairing_transport_override();
    try {
        if (is_callable($transport)) {
            $result = $transport($base, $message, $bearer);
        } else {
            $result = outbound_http_json_request($base . '/index.php?page=cooperative_peer_api', $message, $bearer);
        }
    } catch (\Throwable) {
        throw new CooperativeException('peer_unavailable');
    }
    if (!is_array($result) || ($result['ok'] ?? null) !== true
        || ($result['protocol'] ?? null) !== COOPERATIVE_PROTOCOL_VERSION
        || strlen(json_encode($result, JSON_THROW_ON_ERROR)) > COOPERATIVE_PAIRING_MAX_BYTES) {
        throw new CooperativeException('peer_response_invalid');
    }
    return $result;
}

/** Discover identity at a user-approved HTTPS origin without forwarding credentials.
 *
 * @param string $base Canonical HTTPS installation base.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_discover(string $base): array
{
    $base = cooperative_pairing_base_url($base);
    $response = cooperative_pairing_request($base);
    if (!is_string($response['instance_id'] ?? null) || ($response['base_url'] ?? null) !== $base) {
        throw new CooperativeException('peer_identity_invalid');
    }
    return ['instance_id' => cooperative_id_validate($response['instance_id']), 'base_url' => $base];
}

/** Return minimal protocol discovery data; no peer list or capability secrets are public.
 *
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_identity(): array
{
    cooperative_pairing_require_enabled();
    $base = cooperative_pairing_local_base();
    cooperative_pairing_storage_assert();
    return ['ok' => true, 'protocol' => COOPERATIVE_PROTOCOL_VERSION, 'instance_id' => cooperative_instance_id(), 'base_url' => $base];
}
