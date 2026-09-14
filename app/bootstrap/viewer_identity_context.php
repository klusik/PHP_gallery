<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/bootstrap/viewer_identity_context.php
 * Module Type: Request/Session Adapter
 *
 * Purpose:
 *   Provides a narrow HTTP/session boundary for viewer identity services.
 *
 * Responsibilities:
 *   - Read and mutate viewer-owned PHP session namespaces
 *   - Rotate the PHP session id when viewer authority is established
 *   - Expose request user-agent and remember-cookie values to service orchestration
 *   - Emit viewer remember-cookie instructions returned by the service layer
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - This adapter never reads or writes the administrator user_id identity key.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Core;

/** Return one viewer-owned session namespace value. */
function viewer_identity_session_get(string $key): mixed
{
    return $_SESSION[$key] ?? null;
}

/** Store one viewer-owned session namespace value. */
function viewer_identity_session_set(string $key, mixed $value): void
{
    $_SESSION[$key] = $value;
}

/** Remove one or more viewer-owned session namespace values. */
function viewer_identity_session_unset(string ...$keys): void
{
    foreach ($keys as $key) {
        unset($_SESSION[$key]);
    }
}

/** Return true when the PHP session is active for server-side viewer authority. */
function viewer_identity_session_active(): bool
{
    return session_status() === PHP_SESSION_ACTIVE;
}

/** Rotate the PHP session id while preserving all current namespaced state. */
function viewer_identity_session_regenerate(): bool
{
    return session_status() !== PHP_SESSION_ACTIVE || session_regenerate_id(true);
}

/** Return the current request user-agent value. */
function viewer_identity_request_user_agent(): string
{
    return (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
}

/** Return one raw request cookie value by an application-owned cookie name. */
function viewer_identity_request_cookie(string $name): string
{
    return (string) ($_COOKIE[$name] ?? '');
}

/**
 * Emit one viewer-cookie instruction returned by a service.
 *
 * @param array{name:string,value:string,expires_at:int,path?:string,secure?:bool,httponly?:bool,samesite?:string} $instruction
 * @return bool True when the cookie header was emitted.
 */
function viewer_identity_apply_cookie_instruction(array $instruction): bool
{
    if (headers_sent()) {
        return false;
    }
    $name = (string) ($instruction['name'] ?? '');
    $expiresAt = (int) ($instruction['expires_at'] ?? 0);
    if ($name === '' || preg_match('/^[A-Za-z0-9_]+$/D', $name) !== 1 || $expiresAt <= 0) {
        return false;
    }
    $value = (string) ($instruction['value'] ?? '');
    $ok = setcookie($name, $value, [
        'expires' => $expiresAt,
        'path' => (string) ($instruction['path'] ?? '/'),
        'secure' => (bool) ($instruction['secure'] ?? true),
        'httponly' => (bool) ($instruction['httponly'] ?? true),
        'samesite' => (string) ($instruction['samesite'] ?? 'Lax'),
    ]);
    if ($ok) {
        if ($value === '' || $expiresAt <= time()) {
            unset($_COOKIE[$name]);
        } else {
            $_COOKIE[$name] = $value;
        }
    }
    return $ok;
}

/** Return the current request's parsed viewer remember credential. */
function viewer_identity_remember_cookie_parse_request(): ?array
{
    $name = \Gallery\Services\viewer_remember_cookie_name();
    return \Gallery\Services\viewer_remember_cookie_parse(viewer_identity_request_cookie($name));
}

/** Emit one issued viewer remember credential through the Core HTTP boundary. */
function viewer_identity_remember_cookie_set(array $credential): bool
{
    $instruction = \Gallery\Services\viewer_remember_cookie_set($credential);
    return is_array($instruction) && viewer_identity_apply_cookie_instruction($instruction);
}

/** Clear only the dedicated viewer remember cookie. */
function viewer_identity_remember_cookie_clear(): void
{
    viewer_identity_apply_cookie_instruction(\Gallery\Services\viewer_remember_cookie_clear());
}

/** Revoke the current request's viewer remember credential and clear local browser authority. */
function viewer_identity_remember_revoke_current_cookie(): bool
{
    $credential = viewer_identity_remember_cookie_parse_request();
    $revoked = \Gallery\Services\viewer_remember_revoke_current_cookie($credential);
    viewer_identity_remember_cookie_clear();
    return $revoked;
}

/**
 * Restore viewer identity from the current request cookie and emit any returned rotation/clear instruction.
 *
 * Failed cookie emission revokes the freshly rotated persistent token and session so browser and server
 * authority cannot diverge.
 */
function viewer_identity_remember_restore_request(): bool
{
    $credential = viewer_identity_remember_cookie_parse_request();
    $result = \Gallery\Services\viewer_remember_restore_from_cookie($credential);
    $instruction = $result['cookie_instruction'] ?? null;
    if (is_array($instruction) && !viewer_identity_apply_cookie_instruction($instruction)) {
        $selector = trim((string) ($result['rotated_selector'] ?? ''));
        if ($selector !== '') {
            try {
                \Gallery\Services\viewer_remember_token_revoke($selector);
            } catch (\Throwable) {
                // Local session authority is revoked below regardless of persistent cleanup outcome.
            }
            \Gallery\Services\viewer_session_revoke_current();
        }
        return false;
    }
    return !empty($result['restored']);
}
