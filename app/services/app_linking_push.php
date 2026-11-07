<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/app_linking_push.php
 * Module Type: Service
 * Purpose: Protect pushed browser-linking requests and their session binding.
 * Responsibilities:
 *   - Generate and hash one-time copyable claim codes.
 *   - Encrypt state with authenticated request context.
 *   - Bind claim and explicit consent to a live ordinary Admin session.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use function Gallery\Core\cms_config;
use function Gallery\Models\app_link_push_model_create;
use function Gallery\Models\app_link_push_model_claim;
use function Gallery\Models\app_link_push_model_read;
use function Gallery\Models\app_link_push_model_consume;
use function Gallery\Models\app_link_push_model_installation_key;

/**
 * Bound time available for claiming and consenting to a request.
 * Type: int. Units: seconds. Scope: a pushed request.
 * Consumers: app_link_push_create. Rationale: 300 permits copy/paste with limited exposure.
 */
const APP_LINK_PUSH_TTL_SECONDS = 300;
/**
 * Bound storage and per-admission scan work.
 * Type: int. Units: rows. Scope: all pushed requests.
 * Consumers: app_link_push_model_create. Rationale: 1024 caps unauthenticated storage.
 */
const APP_LINK_PUSH_CAP = 1024;
/**
 * Bound expired-row work in one admission transaction.
 * Type: int. Units: rows. Scope: one admission.
 * Consumers: app_link_push_model_create. Rationale: 64 allows gradual recovery.
 */
const APP_LINK_PUSH_CLEANUP_BATCH = 64;
/**
 * Provide sufficient entropy for a copyable claim code.
 * Type: int. Units: random bytes. Scope: one claim code.
 * Consumers: app_link_push_code_generate. Rationale: 16 supplies 128 bits.
 */
const APP_LINK_PUSH_CLAIM_BYTES = 16;
/**
 * Require a full-size independent AES/HMAC key.
 * Type: int. Units: bytes. Scope: one installation and each derived key.
 * Consumers: app_link_push_key. Rationale: 32 supplies a 256-bit key.
 */
const APP_LINK_PUSH_KEY_BYTES = 32;
/**
 * Set the nonce size required by AES-GCM for independent encryptions.
 * Type: int. Units: bytes. Scope: one encrypted state.
 * Consumers: app_link_push_create. Rationale: 12 is the standard GCM nonce size.
 */
const APP_LINK_PUSH_NONCE_BYTES = 12;
/**
 * Set the full authentication tag size for encrypted state.
 * Type: int. Units: bytes. Scope: one encrypted state.
 * Consumers: app_link_push_create. Rationale: 16 preserves the full GCM tag.
 */
const APP_LINK_PUSH_TAG_BYTES = 16;

/**
 * Derive an independent key from the installation's durable secret.
 *
 * A legacy explicit override keeps existing requests and rate-limit bindings
 * valid. Otherwise read the key provisioned by install/update. Requests never
 * generate or replace key material, including public readiness probes.
 *
 * @param string $purpose Domain-separated key purpose.
 * @return string Binary 256-bit key.
 * @throws Throwable When cryptography, the secret or its storage is unavailable.
 */
function app_link_push_key(string $purpose): string
{
    $encoded = cms_config()['app_linking_secret'] ?? null;
    if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
        throw new RuntimeException('Push request cryptography is unavailable.');
    }
    if ($encoded === null || $encoded === '') {
        app_linking_require_schema(app_linking_schema_status(
            'app_linking.installation_key',
            ['app_link_installation_key' => ['id', 'secret']],
            ['app_link_installation_key' => ['PRIMARY' => ['id']]],
            ['app_link_installation_key'],
        ));
        $secret = app_link_push_model_installation_key();
    } elseif (is_string($encoded) && preg_match('/\A[a-f0-9]{64}\z/D', $encoded) === 1) {
        $secret = hex2bin($encoded);
    } else {
        throw new RuntimeException('Push request key is invalid.');
    }
    if (!is_string($secret) || strlen($secret) !== APP_LINK_PUSH_KEY_BYTES) {
        throw new RuntimeException('Push request key is invalid.');
    }
    return hash_hkdf(
        'sha256', $secret, APP_LINK_PUSH_KEY_BYTES,
        'gallery-app-link-push:' . $purpose,
    );
}

/**
 * Generate a canonical uppercase unpadded Base32 representation of 128 bits.
 *
 * @return string Exactly 26 case-sensitive ASCII characters.
 * @throws Throwable When secure randomness is unavailable.
 */
function app_link_push_code_generate(): string
{
    $bytes = random_bytes(APP_LINK_PUSH_CLAIM_BYTES);
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buffer = 0;
    $bits = 0;
    $encoded = '';
    foreach (unpack('C*', $bytes) as $byte) {
        $buffer = ($buffer << 8) | $byte;
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $encoded .= $alphabet[($buffer >> $bits) & 31];
            $buffer &= (1 << $bits) - 1;
        }
    }
    if ($bits > 0) {
        $encoded .= $alphabet[($buffer << (5 - $bits)) & 31];
    }
    return $encoded;
}

/**
 * Encode all security-relevant request bindings as authenticated GCM context.
 *
 * @param array{claim_hash:string,issuer:string,client_id:string,scope:string,redirect_uri:string,pkce_s256:string} $fields
 *     Stored claim hash and the exact authorization bindings.
 * @return string Unambiguous serialized context.
 * @throws Throwable When serialization fails.
 */
function app_link_push_aad(array $fields): string
{
    return json_encode([
        'gallery-app-link-push-v1', $fields['claim_hash'], $fields['issuer'],
        $fields['client_id'], $fields['scope'], $fields['redirect_uri'],
        $fields['pkce_s256'],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/**
 * Check the fixed public-client policy and exact request bindings.
 *
 * @param array{issuer?:string,client_id?:string,scope?:string,redirect_uri?:string,pkce_s256?:string,claim_hash?:string,state_nonce?:string,state_cipher?:string,state_tag?:string} $fields
 *     Request bindings and optional encrypted storage fields to validate.
 * @return bool True only when issuer, client, scope, redirect and PKCE are valid.
 */
function app_link_push_fields_valid(array $fields): bool
{
    return isset($fields['issuer'], $fields['client_id'], $fields['scope'],
        $fields['redirect_uri'], $fields['pkce_s256'])
        && app_linking_issuer_valid($fields['issuer'])
        && app_linking_scope_transport_allowed($fields['issuer'], $fields['scope'])
        && $fields['client_id'] === APP_LINKING_WINAPP_CLIENT_ID
        && app_linking_scope_allowed($fields['client_id'], $fields['scope'])
        && app_linking_redirect_uri_valid($fields['client_id'], $fields['redirect_uri'])
        && app_linking_pkce_s256_challenge_valid($fields['pkce_s256']);
}

/**
 * Encrypt and persist a public-client request before any browser interaction.
 *
 * @param string $clientId Registered public client identifier.
 * @param string $issuer Trusted exact installation issuer.
 * @param string $scope Exact requested scope.
 * @param string $redirectUri Registered loopback redirect URI.
 * @param string $challenge Canonical S256 PKCE challenge.
 * @param string $state Opaque client state for the later loopback callback.
 * @return array{claim_code:string,expires_in:int}|null Code and lifetime or refusal.
 */
function app_link_push_create(
    string $clientId,
    string $issuer,
    string $scope,
    string $redirectUri,
    string $challenge,
    string $state,
): ?array {
    try {
        $fields = [
            'client_id' => $clientId, 'issuer' => $issuer, 'scope' => $scope,
            'redirect_uri' => $redirectUri, 'pkce_s256' => $challenge,
        ];
        if (!app_link_push_fields_valid($fields)
            || !app_linking_canonical_256bit_value_valid($state)) {
            return null;
        }
        app_linking_require_schema(app_linking_push_schema_status());
        if (app_linking_scope_requests_refresh($scope)) {
            app_linking_require_schema(app_link_refresh_schema_status());
        }
        $code = app_link_push_code_generate();
        $fields['claim_hash'] = hash('sha256', $code);
        $fields['state_nonce'] = random_bytes(APP_LINK_PUSH_NONCE_BYTES);
        $tag = '';
        $cipher = openssl_encrypt(
            $state, 'aes-256-gcm', app_link_push_key('state'), OPENSSL_RAW_DATA,
            $fields['state_nonce'], $tag, app_link_push_aad($fields),
            APP_LINK_PUSH_TAG_BYTES,
        );
        if (!is_string($cipher) || strlen($tag) !== APP_LINK_PUSH_TAG_BYTES) {
            return null;
        }
        $fields['state_cipher'] = $cipher;
        $fields['state_tag'] = $tag;
        app_linking_recheck_wide_scope($clientId, $scope);
        if (!app_link_push_model_create(
            $fields, APP_LINK_PUSH_TTL_SECONDS, APP_LINK_PUSH_CAP,
            APP_LINK_PUSH_CLEANUP_BATCH,
        )) {
            return null;
        }
        return ['claim_code' => $code, 'expires_in' => APP_LINK_PUSH_TTL_SECONDS];
    } catch (Throwable) {
        return null;
    }
}

/**
 * Bind a session to an issuer and account without persisting its raw ID.
 *
 * @param string $issuer Trusted exact installation issuer.
 * @param int $userId Authenticated ordinary Admin identifier.
 * @param string $sessionId Identifier from the exclusively held browser session.
 * @return string Lowercase HMAC digest of the session binding.
 */
function app_link_push_session_hash(
    string $issuer,
    int $userId,
    string $sessionId,
): string
{
    return hash_hmac('sha256', json_encode(
        [$issuer, $userId, $sessionId], JSON_THROW_ON_ERROR,
    ), app_link_push_key('session'));
}

/**
 * Consume a copyable code once and bind its request to this browser session.
 *
 * @param string $code Canonical uppercase Base32 claim code.
 * @param string $issuer Trusted exact installation issuer.
 * @param int $userId Authenticated ordinary Admin identifier.
 * @param string $sessionId Identifier from the exclusively held browser session.
 * @return int|null Claimed request ID, or null on refusal.
 */
function app_link_push_claim_request(
    string $code,
    string $issuer,
    int $userId,
    string $sessionId,
): ?int
{
    try {
        if ($userId <= 0 || $sessionId === '' || !app_linking_issuer_valid($issuer)
            || preg_match('/\A[A-Z2-7]{25}[AEIMQUY4]\z/D', $code) !== 1) {
            return null;
        }
        app_linking_require_schema(app_linking_push_schema_status());
        return app_link_push_model_claim(
            hash('sha256', $code), $issuer, $userId,
            app_link_push_session_hash($issuer, $userId, $sessionId),
        );
    } catch (Throwable) {
        return null;
    }
}

/**
 * Read and authenticate this session's pending request, optionally consuming it.
 *
 * @param string $issuer Trusted exact installation issuer.
 * @param int $userId Authenticated ordinary Admin identifier.
 * @param string $sessionId Identifier from the exclusively held browser session.
 * @param int $requestId Positive claimed request ID from that session.
 * @param bool $consume Whether to atomically consume the consent opportunity.
 * @return array{client_id:string,issuer:string,scope:string,redirect_uri:string,pkce_s256:string,state?:string}|null
 *     Validated bindings; callback state is included only when consumed.
 */
function app_link_push_session_request(
    string $issuer,
    int $userId,
    string $sessionId,
    int $requestId,
    bool $consume,
): ?array
{
    if ($userId <= 0 || $sessionId === '' || $requestId <= 0
        || !app_linking_issuer_valid($issuer)) {
        return null;
    }
    app_linking_require_schema(app_linking_push_schema_status());
    $sessionHash = app_link_push_session_hash($issuer, $userId, $sessionId);
    $fields = $consume
        ? app_link_push_model_consume($requestId, $issuer, $userId, $sessionHash)
        : app_link_push_model_read($requestId, $issuer, $userId, $sessionHash);
    if (!is_array($fields) || !app_link_push_fields_valid($fields)
        || !hash_equals($issuer, $fields['issuer'])) {
        return null;
    }
    $state = openssl_decrypt(
        $fields['state_cipher'], 'aes-256-gcm', app_link_push_key('state'),
        OPENSSL_RAW_DATA, $fields['state_nonce'], $fields['state_tag'],
        app_link_push_aad($fields),
    );
    if (!is_string($state) || !app_linking_canonical_256bit_value_valid($state)) {
        return null;
    }
    $result = [
        'client_id' => $fields['client_id'], 'issuer' => $fields['issuer'],
        'scope' => $fields['scope'], 'redirect_uri' => $fields['redirect_uri'],
        'pkce_s256' => $fields['pkce_s256'],
    ];
    if ($consume) {
        $result['state'] = $state;
    }
    return $result;
}
