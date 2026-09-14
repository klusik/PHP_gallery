<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/viewer_tokens.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Provides dormant one-time and persistent viewer token storage primitives.
 *
 * Responsibilities:
 *   - Store email-verification and password-reset authority only as hashes
 *   - Enforce expiry, invalidation, and single-use consumption under row locks
 *   - Prepare selector/verifier persistent login tokens with verifier hashing
 *   - Avoid public responses or routes that could expose viewer account existence
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
 *   - Plaintext reset/verification/remember secrets are returned only to the caller.
 *   - No email is sent and no public endpoint invokes these helpers in Phase 0.
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

namespace Gallery\Services;

use InvalidArgumentException;
use RuntimeException;
use function Gallery\Core\now_sql;
use function Gallery\Core\viewer_identity_request_user_agent;
use function Gallery\Models\viewer_account_model_transaction;
use function Gallery\Models\viewer_account_model_lock_auth;
use function Gallery\Models\viewer_token_model_issue_email_verification;
use function Gallery\Models\viewer_token_model_issue_password_reset;
use function Gallery\Models\viewer_token_model_lock_one_time;
use function Gallery\Models\viewer_token_model_mark_consumed;
use function Gallery\Models\viewer_token_model_remember_cleanup;
use function Gallery\Models\viewer_token_model_remember_enforce_limit;
use function Gallery\Models\viewer_token_model_remember_insert;
use function Gallery\Models\viewer_token_model_remember_verify_row;
use function Gallery\Models\viewer_token_model_remember_revoke;
use function Gallery\Models\viewer_token_model_remember_account_id;
use function Gallery\Models\viewer_token_model_remember_lock;
use function Gallery\Models\viewer_token_model_remember_rotate;

/**
 * Return true only when one allowlisted viewer token table is verifiably available.
 *
 * @param string $table Allowlisted viewer token table.
 * @return bool True only for confirmed available storage.
 */
function viewer_token_table_storage_available(string $table): bool
{
    $allowed = [
        'viewer_email_verification_tokens' => true,
        'viewer_password_reset_tokens' => true,
        'viewer_remember_tokens' => true,
    ];
    if (!isset($allowed[$table])) {
        throw new InvalidArgumentException('Viewer token storage table is not allowlisted.');
    }
    return schema_inspection_is_available(schema_inspection_feature('viewer.token.' . $table, [
        schema_inspection_table('viewer_accounts'),
        schema_inspection_table($table),
    ]));
}

/**
 * Return true when a one-time token row is unexpired, unused, and not invalidated.
 *
 * @param array $row Token database row or equivalent data.
 * @param ?int $now Optional Unix timestamp for deterministic tests.
 * @return bool True only while the token can be consumed.
 */
function viewer_one_time_token_row_is_usable(array $row, ?int $now = null): bool
{
    $now = $now ?? time();
    $expiresAt = strtotime((string) ($row['expires_at'] ?? ''));
    return $expiresAt !== false
        && $expiresAt >= $now
        && empty($row['consumed_at'])
        && empty($row['invalidated_at']);
}

/**
 * Issue a hashed email verification token and invalidate older unused tokens for the account.
 *
 * @param int $viewerAccountId Viewer account identifier.
 * @param string $email Email address being verified.
 * @param int $lifetimeSeconds Token lifetime in seconds.
 * @return string Plaintext opaque token for future email delivery.
 */
function viewer_email_verification_token_issue(int $viewerAccountId, string $email, int $lifetimeSeconds = 86400): string
{
    if (!viewer_accounts_enabled() || !viewer_token_table_storage_available('viewer_email_verification_tokens')) {
        throw new RuntimeException('Viewer token issuance is unavailable.');
    }
    if ($viewerAccountId <= 0 || $lifetimeSeconds < 300 || $lifetimeSeconds > 604800) {
        throw new InvalidArgumentException('Viewer email verification token parameters are invalid.');
    }
    $emailFingerprint = viewer_email_fingerprint($email);
    if ($emailFingerprint === '') {
        throw new InvalidArgumentException('Viewer email verification requires a valid email address.');
    }
    $token = security_opaque_token_generate(32);
    $now = now_sql();
    $expiresAt = date('Y-m-d H:i:s', time() + $lifetimeSeconds);
    viewer_account_model_transaction(static function () use ($viewerAccountId, $token, $emailFingerprint, $now, $expiresAt): void {
        viewer_token_model_issue_email_verification($viewerAccountId, security_authority_token_hash($token), $emailFingerprint, $now, $expiresAt);
    });
    return $token;
}

/**
 * Consume one email verification token exactly once under a database row lock.
 *
 * @param string $token Plaintext opaque token presented by the future verification flow.
 * @return ?array Token row when consumed, otherwise null.
 */
function viewer_email_verification_token_consume(string $token): ?array
{
    return viewer_one_time_token_consume('viewer_email_verification_tokens', $token);
}

/**
 * Issue a hashed password reset token bound to the account security version.
 *
 * @param int $viewerAccountId Viewer account identifier.
 * @param int $securityVersion Current viewer account security version.
 * @param int $lifetimeSeconds Token lifetime in seconds.
 * @return string Plaintext opaque token for future email delivery.
 */
function viewer_password_reset_token_issue(int $viewerAccountId, int $securityVersion, int $lifetimeSeconds = 3600): string
{
    if (!viewer_accounts_enabled() || !viewer_token_table_storage_available('viewer_password_reset_tokens')) {
        throw new RuntimeException('Viewer token issuance is unavailable.');
    }
    if ($viewerAccountId <= 0 || $securityVersion <= 0 || $lifetimeSeconds < 300 || $lifetimeSeconds > 86400) {
        throw new InvalidArgumentException('Viewer password reset token parameters are invalid.');
    }
    $token = security_opaque_token_generate(32);
    $now = now_sql();
    $expiresAt = date('Y-m-d H:i:s', time() + $lifetimeSeconds);
    viewer_account_model_transaction(static function () use ($viewerAccountId, $securityVersion, $token, $now, $expiresAt): void {
        viewer_token_model_issue_password_reset($viewerAccountId, security_authority_token_hash($token), $securityVersion, $now, $expiresAt);
    });
    return $token;
}

/**
 * Consume one password reset token exactly once under a database row lock.
 *
 * The caller must additionally compare the returned security_version to the
 * current viewer_accounts.security_version before accepting a reset.
 *
 * @param string $token Plaintext opaque token presented by the future reset flow.
 * @return ?array Token row when consumed, otherwise null.
 */
function viewer_password_reset_token_consume(string $token): ?array
{
    return viewer_one_time_token_consume('viewer_password_reset_tokens', $token);
}

/**
 * Consume one allowlisted viewer one-time token exactly once.
 *
 * @param string $table Allowlisted one-time token table.
 * @param string $token Plaintext opaque token.
 * @return ?array Consumed row or null when invalid/expired/already used.
 */
function viewer_one_time_token_consume(string $table, string $token): ?array
{
    if (!viewer_accounts_enabled()) {
        return null;
    }
    $allowedTables = [
        'viewer_email_verification_tokens' => true,
        'viewer_password_reset_tokens' => true,
    ];
    if (!isset($allowedTables[$table]) || $token === '' || !viewer_token_table_storage_available($table)) {
        return null;
    }

    return viewer_account_model_transaction(static function () use ($table, $token): ?array {
        $row = viewer_token_model_lock_one_time($table, security_authority_token_hash($token));
        if (!$row || !viewer_one_time_token_row_is_usable($row)) {
            return null;
        }
        $consumedAt = now_sql();
        if (!viewer_token_model_mark_consumed($table, (int) $row['id'], $consumedAt)) {
            return null;
        }
        $row['consumed_at'] = $consumedAt;
        return $row;
    });
}

/**
 * Remove a bounded set of inactive remember-token rows for one locked account.
 *
 * @param int $viewerAccountId Locked viewer account identifier.
 * @param string $now Current SQL timestamp.
 * @param int $limit Maximum inactive rows removed.
 * @return int Number of rows deleted.
 */
function viewer_remember_token_cleanup_account_locked(int $viewerAccountId, string $now, int $limit = 100): int
{
    return viewer_token_model_remember_cleanup($viewerAccountId, $now, $limit);
}

/**
 * Enforce the active remember-token cap while the viewer account row is locked.
 *
 * @param int $viewerAccountId Locked viewer account identifier.
 * @param string $now Current SQL timestamp.
 * @param int $reserveSlots Number of free slots required after enforcement.
 * @param int $keepTokenId Token id that must remain active during restore rotation.
 */
function viewer_remember_token_enforce_limit_locked(
    int $viewerAccountId,
    string $now,
    int $reserveSlots = 0,
    int $keepTokenId = 0
): void {
    viewer_token_model_remember_enforce_limit(
        $viewerAccountId,
        $now,
        (int) viewer_accounts_config()['max_active_viewer_remember_tokens_per_account'],
        $reserveSlots,
        $keepTokenId
    );
}

/**
 * Issue a selector/verifier persistent viewer token with only the verifier hash stored.
 *
 * This creates database state only. Phase 0 intentionally does not set a browser
 * cookie or restore a viewer session from it.
 *
 * @param int $viewerAccountId Viewer account identifier.
 * @param int $securityVersion Current viewer account security version.
 * @return array{selector:string,verifier:string,expires_at:string} Plaintext browser credential parts.
 */
function viewer_remember_token_issue(int $viewerAccountId, int $securityVersion): array
{
    if (!viewer_accounts_enabled() || !viewer_auth_storage_available()) {
        throw new RuntimeException('Viewer remember-token issuance is unavailable.');
    }
    if (!viewer_security_transport_allowed()) {
        throw new RuntimeException('Viewer remember-token issuance requires a trusted secure transport.');
    }
    if ($viewerAccountId <= 0 || $securityVersion <= 0) {
        throw new InvalidArgumentException('Viewer remember token account data is invalid.');
    }

    return viewer_account_model_transaction(static function () use ($viewerAccountId, $securityVersion): array {
        $account = viewer_account_model_lock_auth($viewerAccountId);
        if (!$account
            || !viewer_account_can_authenticate($account)
            || viewer_account_requires_password_change($account)
            || (int) ($account['security_version'] ?? 0) !== $securityVersion) {
            throw new RuntimeException('Viewer remember-token issuance is unavailable.');
        }

        $selector = security_token_selector_generate(18);
        $verifier = security_opaque_token_generate(32);
        $now = now_sql();
        $expiresAt = date('Y-m-d H:i:s', time() + ((int) viewer_accounts_config()['remember_lifetime_days'] * 86400));
        $userAgent = viewer_identity_request_user_agent();
        $userAgentHash = $userAgent === '' ? null : viewer_security_fingerprint('viewer-remember-ua', $userAgent);
        viewer_remember_token_cleanup_account_locked($viewerAccountId, $now);
        viewer_remember_token_enforce_limit_locked($viewerAccountId, $now, 1);
        viewer_token_model_remember_insert(
            $viewerAccountId,
            $selector,
            security_authority_token_hash($verifier),
            $securityVersion,
            $userAgentHash,
            $now,
            $expiresAt
        );
        return ['selector' => $selector, 'verifier' => $verifier, 'expires_at' => $expiresAt];
    });
}

/**
 * Verify one selector/verifier pair without establishing a viewer session.
 *
 * @param string $selector Public remember-token selector.
 * @param string $verifier Secret remember-token verifier.
 * @return ?array Matching unrevoked/unexpired token row, otherwise null.
 */
function viewer_remember_token_verify(string $selector, string $verifier): ?array
{
    if (!viewer_accounts_enabled() || !viewer_auth_storage_available()) {
        return null;
    }
    if (preg_match('/^[a-f0-9]{36}$/', $selector) !== 1 || $verifier === '') {
        return null;
    }
    $row = viewer_token_model_remember_verify_row($selector, now_sql());
    $account = $row ? [
        'status' => $row['account_status'] ?? '',
        'password_hash' => $row['password_hash'] ?? '',
        'email_verified_at' => $row['email_verified_at'] ?? null,
        'must_change_password' => $row['must_change_password'] ?? 0,
    ] : [];
    if (!$row
        || !security_authority_token_verify((string) $row['verifier_hash'], $verifier)
        || !viewer_account_can_authenticate($account)
        || viewer_account_requires_password_change($account)
        || (int) ($row['security_version'] ?? 0) !== (int) ($row['account_security_version'] ?? -1)) {
        return null;
    }
    return $row;
}

/**
 * Revoke one persistent viewer token by selector.
 *
 * @param string $selector Public remember-token selector.
 */
function viewer_remember_token_revoke(string $selector): void
{
    if (preg_match('/^[a-f0-9]{36}$/', $selector) !== 1) {
        throw new InvalidArgumentException('Viewer remember token selector is invalid.');
    }
    if (!viewer_auth_storage_available()) {
        return;
    }
    viewer_token_model_remember_revoke($selector, now_sql());
}

/**
 * Restore viewer authentication from one remember credential and rotate it atomically.
 *
 * The old selector/verifier pair becomes invalid in the same transaction that creates the
 * normal revocable viewer session. No browser cookie is emitted by this function.
 *
 * @param string $selector Public remember-token selector.
 * @param string $verifier Secret remember-token verifier.
 * @return ?array{selector:string,verifier:string,expires_at:string} Rotated browser credential or null.
 */
function viewer_remember_restore_and_rotate(string $selector, string $verifier): ?array
{
    if (!viewer_accounts_enabled() || !viewer_auth_storage_available() || !viewer_security_transport_allowed()) {
        return null;
    }
    if (preg_match('/^[a-f0-9]{36}$/D', $selector) !== 1 || $verifier === '' || strlen($verifier) > 512) {
        return null;
    }
    $viewerAccountId = viewer_token_model_remember_account_id($selector);
    if ($viewerAccountId <= 0) {
        return null;
    }

    try {
        return viewer_account_model_transaction(static function () use ($selector, $verifier, $viewerAccountId): ?array {
            $account = viewer_account_model_lock_auth($viewerAccountId);
            if (!$account || !viewer_account_can_authenticate($account) || viewer_account_requires_password_change($account)) {
                return null;
            }
            $token = viewer_token_model_remember_lock($selector);
            $expiresAtTimestamp = $token ? strtotime((string) ($token['expires_at'] ?? '')) : false;
            if (!$token
                || !empty($token['revoked_at'])
                || $expiresAtTimestamp === false
                || $expiresAtTimestamp < time()
                || (int) ($token['viewer_account_id'] ?? 0) !== $viewerAccountId
                || (int) ($token['security_version'] ?? 0) !== (int) ($account['security_version'] ?? -1)
                || !security_authority_token_verify((string) ($token['verifier_hash'] ?? ''), $verifier)) {
                return null;
            }

            $now = now_sql();
            viewer_remember_token_cleanup_account_locked($viewerAccountId, $now);
            viewer_remember_token_enforce_limit_locked($viewerAccountId, $now, 0, (int) $token['id']);
            $newSelector = security_token_selector_generate(18);
            $newVerifier = security_opaque_token_generate(32);
            $newExpiresAt = date('Y-m-d H:i:s', time() + ((int) viewer_accounts_config()['remember_lifetime_days'] * 86400));
            if (!viewer_token_model_remember_rotate(
                (int) $token['id'],
                $selector,
                $newSelector,
                security_authority_token_hash($newVerifier),
                $now,
                $newExpiresAt
            )) {
                throw new RuntimeException('Viewer remember credential rotation lost a concurrent race.');
            }

            viewer_session_establish($account);
            if (function_exists(__NAMESPACE__ . '\\viewer_security_event_record')) {
                viewer_security_event_record('viewer.remember_restored', $viewerAccountId, 'success', [
                    'security_version' => (int) $account['security_version'],
                ]);
            }
            return ['selector' => $newSelector, 'verifier' => $newVerifier, 'expires_at' => $newExpiresAt];
        });
    } catch (\Throwable $exception) {
        viewer_session_clear();
        throw $exception;
    }
}

/**
 * Return the dormant viewer remember-cookie contract without emitting a cookie.
 *
 * @return array{name:string,httponly:bool,secure:bool,samesite:string,lifetime_seconds:int} Cookie policy metadata.
 */
function viewer_remember_cookie_contract(): array
{
    return [
        'name' => 'php_gallery_viewer_remember',
        'httponly' => true,
        'secure' => true,
        'samesite' => 'Lax',
        'lifetime_seconds' => (int) viewer_accounts_config()['remember_lifetime_days'] * 86400,
    ];
}
