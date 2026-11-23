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
 * Limit the display fingerprint needed to join events for one request.
 * Type: int. Units: hexadecimal characters. Scope: one Admin log reference.
 * Consumers: app_linking_log_reference, app_linking_log_attempt.
 * Rationale: Twelve characters separate the bounded diagnostic events while
 *     keeping the underlying random handle and full digest out of the log.
 */
const APP_LINKING_LOG_REFERENCE_LENGTH = 12;

/**
 * Derive a short log-only reference from a canonical handle or public proof.
 *
 * @param string $kind Either request for the handle or exchange for the PKCE challenge.
 * @param string $value Canonical Base32 handle or unpadded S256 challenge.
 * @return string|null Short digest for Admin Logs or null for unrecognized input.
 */
function app_linking_log_reference(string $kind, string $value): ?string
{
    $valid = match ($kind) {
        'request' => preg_match('/\A[A-Z2-7]{25}[AEIMQUY4]\z/D', $value) === 1,
        'exchange' => app_linking_pkce_s256_challenge_valid($value),
        default => false,
    };
    return $valid ? substr(
        hash('sha256', "app-linking-log\0" . $kind . "\0" . $value),
        0,
        APP_LINKING_LOG_REFERENCE_LENGTH,
    ) : null;
}

/**
 * Record one bounded outcome of a browser-approved WinApp connection.
 *
 * @param string $stage Fixed request, browser, claim, consent or exchange phase.
 * @param string $outcome Fixed policy result listed below, never input or exception text.
 * @param int $status HTTP response status prepared by the route.
 * @param string|null $reference Log-only fingerprint of the browser request.
 * @param int|null $adminId Ordinary Admin already resolved by the controller.
 * @param array{exchange_ref?:?string,scope?:?string,callback_family?:?string} $details
 *     Optional diagnostic proof reference, registered scope and loopback IP family.
 * @return void Attempts to persist safe evidence without affecting the linking response.
 */
function app_linking_log_attempt(
    string $stage,
    string $outcome,
    int $status,
    ?string $reference = null,
    ?int $adminId = null,
    array $details = [],
): void {
    $route = match ($stage) {
        'request' => 'app_linking_push',
        'browser', 'claim' => 'app_linking_start',
        'consent' => 'app_linking_consent',
        'exchange' => 'app_linking_token',
        default => null,
    };
    $level = match ($outcome) {
        'created', 'handle_received', 'login_required', 'claim_page_rendered',
        'consent_page_restored', 'claim_accepted', 'callback_prepared',
        'declined', 'code_exchanged' => 'info',
        'issuer_unavailable', 'route_rejected', 'method_rejected', 'rate_limited',
        'form_rejected', 'push_parameters_rejected', 'state_invalid',
        'scope_unavailable', 'request_capacity_reached', 'admin_required',
        'session_required', 'browser_request_missing', 'origin_missing',
        'origin_null', 'origin_mismatch', 'fetch_site_mismatch', 'csrf_rejected',
        'browser_request_changed', 'claim_parameters_rejected',
        'request_not_claimable', 'claim_unavailable', 'attempt_changed',
        'decision_rejected', 'claim_consumption_rejected', 'code_issue_rejected',
        'exchange_proof_invalid', 'code_exchange_rejected' => 'warning',
        'request_schema_unavailable', 'state_protection_failed',
        'request_storage_failed', 'claim_storage_failed', 'server_error' => 'error',
        default => null,
    };
    if ($route === null || $level === null) {
        return;
    }
    try {
        if (!app_linking_rate_limit_consume('app_link_diagnostic_global', 'global')['allowed']) {
            return;
        }
        $context = ['stage' => $stage, 'outcome' => $outcome, 'http_status' => $status];
        $pattern = '/\A[0-9a-f]{' . APP_LINKING_LOG_REFERENCE_LENGTH . '}\z/D';
        if ($reference !== null && preg_match($pattern, $reference) === 1) {
            $context['attempt_ref'] = $reference;
        }
        $exchangeReference = $details['exchange_ref'] ?? null;
        if ($exchangeReference !== null && preg_match($pattern, $exchangeReference) === 1) {
            $context['exchange_ref'] = $exchangeReference;
        }
        $scope = $details['scope'] ?? null;
        if ($scope !== null && in_array(
            $scope, app_linking_public_client(APP_LINKING_WINAPP_CLIENT_ID)['scopes'] ?? [], true,
        )) {
            $context['scope'] = $scope;
        }
        $family = $details['callback_family'] ?? null;
        if ($family === 'ipv4' || $family === 'ipv6') {
            $context['callback_family'] = $family;
        }
        admin_log_event(
            $level, 'app_linking.' . $stage . '.' . $outcome,
            'WinApp linking ' . $stage . ': ' . str_replace('_', ' ', $outcome) . '.',
            $context,
            ['category' => 'security', 'route_name' => $route, 'actor_user_id' => $adminId],
        );
    } catch (Throwable) {
        // Logging is optional and must leave the original authentication response intact.
    }
}

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
 * @param string|null $failure Receives a fixed diagnostic on refusal, or null on success.
 * @return array{claim_code:string,expires_in:int}|null Code and lifetime or refusal.
 */
function app_link_push_create(
    string $clientId,
    string $issuer,
    string $scope,
    string $redirectUri,
    string $challenge,
    string $state,
    ?string &$failure = null,
): ?array {
    $failure = 'push_parameters_rejected';
    try {
        $fields = [
            'client_id' => $clientId, 'issuer' => $issuer, 'scope' => $scope,
            'redirect_uri' => $redirectUri, 'pkce_s256' => $challenge,
        ];
        if (!app_link_push_fields_valid($fields)) {
            return null;
        }
        if (!app_linking_canonical_256bit_value_valid($state)) {
            $failure = 'state_invalid';
            return null;
        }
        $failure = 'request_schema_unavailable';
        app_linking_require_schema(app_linking_push_schema_status());
        if (app_linking_scope_requests_refresh($scope)) {
            app_linking_require_schema(app_link_refresh_schema_status());
        }
        $failure = 'state_protection_failed';
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
        $failure = 'scope_unavailable';
        app_linking_recheck_wide_scope($clientId, $scope);
        $failure = 'request_storage_failed';
        if (!app_link_push_model_create(
            $fields, APP_LINK_PUSH_TTL_SECONDS, APP_LINK_PUSH_CAP,
            APP_LINK_PUSH_CLEANUP_BATCH,
        )) {
            $failure = 'request_capacity_reached';
            return null;
        }
        $failure = null;
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
 * @param string|null $failure Receives a fixed diagnostic on refusal, or null on success.
 * @return int|null Claimed request ID, or null on refusal.
 */
function app_link_push_claim_request(
    string $code,
    string $issuer,
    int $userId,
    string $sessionId,
    ?string &$failure = null,
): ?int
{
    $failure = 'claim_parameters_rejected';
    try {
        if ($userId <= 0 || $sessionId === '' || !app_linking_issuer_valid($issuer)
            || preg_match('/\A[A-Z2-7]{25}[AEIMQUY4]\z/D', $code) !== 1) {
            return null;
        }
        $failure = 'request_schema_unavailable';
        app_linking_require_schema(app_linking_push_schema_status());
        $failure = 'claim_storage_failed';
        $id = app_link_push_model_claim(
            hash('sha256', $code), $issuer, $userId,
            app_link_push_session_hash($issuer, $userId, $sessionId),
        );
        $failure = $id === null ? 'request_not_claimable' : null;
        return $id;
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
