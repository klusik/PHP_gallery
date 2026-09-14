<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/viewer_http.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Provides the small HTTP-facing viewer authentication bridge used by Phase 1.0.
 *
 * Responsibilities:
 *   - Encode, emit, parse, rotate, and clear the dedicated viewer remember-me cookie
 *   - Restore only viewer identity from a valid persistent viewer credential
 *   - Fail closed without allowing viewer storage failures to break anonymous gallery browsing
 *   - Keep browser cookie authority separate from administrator persistent-login authority
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *   - This module never reads or writes the administrator user_id session key.
 *
 * Last Updated:
 *   2026-08-20
 */

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;

/**
 * Return whether the shared viewer registration HTTP lifecycle is available.
 *
 * The global Viewer Accounts feature wrapper, registration policy, transport, and
 * both authentication/registration storage capabilities must all be available.
 *
 * @return bool True only while invite-backed or open registration may progress.
 */
function viewer_http_registration_lifecycle_available(): bool
{
    return viewer_accounts_enabled()
        && in_array(viewer_registration_mode(), ['invite_only', 'open'], true)
        && viewer_security_transport_allowed()
        && viewer_auth_storage_available()
        && viewer_registration_storage_available();
}

/**
 * Return whether anonymous open-origin registration HTTP handling is available.
 *
 * @return bool True only for the effective open registration mode.
 */
function viewer_http_open_registration_available(): bool
{
    return viewer_http_registration_lifecycle_available()
        && viewer_registration_mode() === 'open';
}

/**
 * Return whether administrator invitation registration HTTP handling is available.
 *
 * Invitations remain valid in both invite-only and open registration modes.
 *
 * @return bool True while invitation-backed registration may progress.
 */
function viewer_http_invite_registration_available(): bool
{
    return viewer_http_registration_lifecycle_available();
}

/**
 * Return whether the shared registration verification HTTP route is available.
 *
 * Individual staged requests are still authorized by the registration service at
 * validation, confirmation, and activation time. This helper only gates the route.
 *
 * @return bool True while a registration lifecycle can be verified.
 */
function viewer_http_registration_verification_available(): bool
{
    return viewer_http_registration_lifecycle_available();
}

/**
 * Return whether the explicit verification-resend HTTP surface is available.
 *
 * Route availability is deliberately broader than per-request authority: both invite-only
 * and open modes may show the generic resend form, while the registration service revalidates
 * the current mode against each staged request origin before any message can be sent.
 *
 * @return bool True while the shared registration lifecycle can accept resend requests.
 */
function viewer_http_verification_resend_available(): bool
{
    return viewer_http_registration_lifecycle_available();
}

/**
 * Return the dedicated viewer remember cookie name without exposing admin cookie details.
 *
 * @return string Viewer remember cookie name.
 */
function viewer_remember_cookie_name(): string
{
    return (string) viewer_remember_cookie_contract()['name'];
}

/**
 * Serialize one selector/verifier pair for the dedicated viewer browser cookie.
 *
 * @param array{selector:string,verifier:string,expires_at:string} $credential Viewer persistent credential.
 * @return string Cookie value, or an empty string when malformed.
 */
function viewer_remember_cookie_encode(array $credential): string
{
    $selector = trim((string) ($credential['selector'] ?? ''));
    $verifier = trim((string) ($credential['verifier'] ?? ''));
    if (preg_match('/^[a-f0-9]{36}$/D', $selector) !== 1
        || $verifier === ''
        || strlen($verifier) > 512
        || preg_match('/^[A-Za-z0-9_-]+$/D', $verifier) !== 1) {
        return '';
    }
    return $selector . '.' . $verifier;
}

/**
 * Parse one dedicated viewer remember cookie without touching persistent storage.
 *
 * @param string $value Explicit cookie value supplied by the HTTP adapter.
 * @return ?array{selector:string,verifier:string} Parsed credential or null.
 */
function viewer_remember_cookie_parse(string $value): ?array
{
    if ($value === '' || strlen($value) > 700) {
        return null;
    }
    $parts = explode('.', $value, 2);
    if (count($parts) !== 2
        || preg_match('/^[a-f0-9]{36}$/D', $parts[0]) !== 1
        || $parts[1] === ''
        || strlen($parts[1]) > 512
        || preg_match('/^[A-Za-z0-9_-]+$/D', $parts[1]) !== 1) {
        return null;
    }
    return ['selector' => $parts[0], 'verifier' => $parts[1]];
}

/**
 * Build one dedicated viewer remember-cookie instruction from an issued credential.
 *
 * The service owns credential validation and cookie policy. The Core HTTP adapter owns
 * header emission and the request cookie mirror.
 *
 * @param array{selector:string,verifier:string,expires_at:string} $credential Viewer persistent credential.
 * @return ?array{name:string,value:string,expires_at:int,path:string,secure:bool,httponly:bool,samesite:string}
 */
function viewer_remember_cookie_set(array $credential): ?array
{
    if (!viewer_accounts_enabled() || !viewer_security_transport_allowed()) {
        return null;
    }
    $value = viewer_remember_cookie_encode($credential);
    $expiresAt = strtotime((string) ($credential['expires_at'] ?? ''));
    if ($value === '' || $expiresAt === false || $expiresAt <= time()) {
        return null;
    }
    $contract = viewer_remember_cookie_contract();
    return [
        'name' => (string) $contract['name'],
        'value' => $value,
        'expires_at' => $expiresAt,
        'path' => '/',
        'secure' => true,
        'httponly' => (bool) $contract['httponly'],
        'samesite' => (string) $contract['samesite'],
    ];
}

/**
 * Build the dedicated viewer remember-cookie clearing instruction.
 *
 * @return array{name:string,value:string,expires_at:int,path:string,secure:bool,httponly:bool,samesite:string}
 */
function viewer_remember_cookie_clear(): array
{
    return [
        'name' => viewer_remember_cookie_name(),
        'value' => '',
        'expires_at' => time() - 3600,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/**
 * Revoke one explicitly supplied browser remember credential.
 *
 * Storage failure never preserves HTTP authority because the caller independently emits
 * the clearing instruction returned by viewer_remember_cookie_clear().
 *
 * @param ?array{selector:string,verifier:string} $credential Parsed browser credential.
 * @return bool True when a syntactically valid selector was presented for revocation.
 */
function viewer_remember_revoke_current_cookie(?array $credential): bool
{
    if ($credential === null) {
        return false;
    }
    try {
        viewer_remember_token_revoke($credential['selector']);
    } catch (Throwable) {
        // The HTTP adapter still removes local browser authority.
    }
    return true;
}

/**
 * Restore viewer identity from one explicitly supplied remember credential and rotate it atomically.
 *
 * This service never reads request globals or emits headers. It returns a cookie instruction for
 * the Core request adapter. Malformed, expired, revoked, or operationally unavailable storage
 * simply leaves the request without a viewer principal. Remember restoration deliberately does
 * not establish recent reauthentication.
 *
 * @param ?array{selector:string,verifier:string} $credential Parsed browser credential.
 * @return array{restored:bool,cookie_instruction:?array,rotated_selector:?string}
 */
function viewer_remember_restore_from_cookie(?array $credential): array
{
    if (!viewer_accounts_enabled()) {
        viewer_session_clear();
        if (function_exists(__NAMESPACE__ . '\\viewer_clear_reauthentication')) {
            viewer_clear_reauthentication();
        }
        if (function_exists(__NAMESPACE__ . '\\viewer_registration_activation_clear')) {
            viewer_registration_activation_clear();
        }
        if (function_exists(__NAMESPACE__ . '\\viewer_password_reset_state_clear')) {
            viewer_password_reset_state_clear();
        }
        \Gallery\Core\viewer_identity_session_unset(viewer_csrf_namespace_key());
        return [
            'restored' => false,
            'cookie_instruction' => viewer_remember_cookie_clear(),
            'rotated_selector' => null,
        ];
    }
    if (viewer_session_state() !== null || $credential === null) {
        return ['restored' => false, 'cookie_instruction' => null, 'rotated_selector' => null];
    }
    if (!viewer_security_transport_allowed()) {
        return [
            'restored' => false,
            'cookie_instruction' => viewer_remember_cookie_clear(),
            'rotated_selector' => null,
        ];
    }

    try {
        $rotated = viewer_remember_restore_and_rotate($credential['selector'], $credential['verifier']);
        if ($rotated === null) {
            return [
                'restored' => false,
                'cookie_instruction' => viewer_remember_cookie_clear(),
                'rotated_selector' => null,
            ];
        }
        $instruction = viewer_remember_cookie_set($rotated);
        if ($instruction === null) {
            try {
                viewer_remember_token_revoke((string) $rotated['selector']);
            } catch (Throwable) {
                // Session authority is revoked below regardless of persistent cleanup outcome.
            }
            viewer_session_revoke_current();
            return [
                'restored' => false,
                'cookie_instruction' => viewer_remember_cookie_clear(),
                'rotated_selector' => null,
            ];
        }
        if (function_exists(__NAMESPACE__ . '\\viewer_clear_reauthentication')) {
            viewer_clear_reauthentication();
        }
        return [
            'restored' => true,
            'cookie_instruction' => $instruction,
            'rotated_selector' => (string) $rotated['selector'],
        ];
    } catch (Throwable) {
        viewer_session_clear();
        return [
            'restored' => false,
            'cookie_instruction' => viewer_remember_cookie_clear(),
            'rotated_selector' => null,
        ];
    }
}

