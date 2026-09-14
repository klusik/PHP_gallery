<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/viewer_authentication.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns viewer password-authentication and password-reset persistence.
 *
 * Responsibilities:
 *   - Resolve viewer authentication rows by normalized identifier
 *   - Persist login timestamps and password rehashes
 *   - Apply forced first-login password transitions under account locks
 *   - Resolve and consume password-reset authority with compare-and-set guards
 *   - Revoke superseded authentication authority after password transitions
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Plaintext passwords, hash verification, account policy, and session state remain in services.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use function Gallery\Core\db;

/** Return one account row by normalized login identifier without acquiring a write lock. */
function viewer_authentication_model_account_by_normalized_email(string $normalizedEmail): ?array
{
    $stmt = db()->prepare('SELECT * FROM viewer_accounts WHERE normalized_email = ? LIMIT 1');
    $stmt->execute([$normalizedEmail]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Return the bounded first-login validation columns for one account. */
function viewer_authentication_model_first_login_account(int $viewerAccountId): ?array
{
    $stmt = db()->prepare(
        'SELECT id, email, password_hash, must_change_password, status, security_version, email_verified_at '
        . 'FROM viewer_accounts WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$viewerAccountId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Persist a successful login timestamp and optional password rehash. */
function viewer_authentication_model_record_login(int $viewerAccountId, string $now, ?string $newPasswordHash): void
{
    if ($newPasswordHash !== null) {
        db()->prepare('UPDATE viewer_accounts SET password_hash = ?, last_login_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$newPasswordHash, $now, $now, $viewerAccountId]);
        return;
    }
    db()->prepare('UPDATE viewer_accounts SET last_login_at = ?, updated_at = ? WHERE id = ?')
        ->execute([$now, $now, $viewerAccountId]);
}

/** Compare-and-set the forced first-login password transition. */
function viewer_authentication_model_complete_first_login(
    int $viewerAccountId,
    int $expectedSecurityVersion,
    string $newPasswordHash,
    int $newSecurityVersion,
    string $now
): bool {
    $stmt = db()->prepare(
        'UPDATE viewer_accounts SET password_hash = ?, must_change_password = 0, password_changed_at = ?, '
        . 'security_version = ?, updated_at = ? WHERE id = ? AND security_version = ? AND must_change_password = 1'
    );
    $stmt->execute([
        $newPasswordHash,
        $now,
        $newSecurityVersion,
        $now,
        $viewerAccountId,
        $expectedSecurityVersion,
    ]);
    return $stmt->rowCount() === 1;
}

/** Revoke all authority invalidated by a forced first-login password replacement. */
function viewer_authentication_model_revoke_after_first_login_password_change(int $viewerAccountId, string $now): void
{
    db()->prepare('UPDATE viewer_password_reset_tokens SET invalidated_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND invalidated_at IS NULL')
        ->execute([$now, $viewerAccountId]);
    db()->prepare('UPDATE viewer_email_verification_tokens SET invalidated_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND invalidated_at IS NULL')
        ->execute([$now, $viewerAccountId]);
    db()->prepare('UPDATE viewer_email_change_requests SET cancelled_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND cancelled_at IS NULL')
        ->execute([$now, $viewerAccountId]);
    db()->prepare('UPDATE viewer_sessions SET revoked_at = ? WHERE viewer_account_id = ? AND revoked_at IS NULL')
        ->execute([$now, $viewerAccountId]);
    db()->prepare('UPDATE viewer_remember_tokens SET revoked_at = ? WHERE viewer_account_id = ? AND revoked_at IS NULL')
        ->execute([$now, $viewerAccountId]);
}

/** Resolve a password-reset token joined to the current account security state. */
function viewer_authentication_model_password_reset_inspect(string $tokenHash): ?array
{
    $stmt = db()->prepare(
        'SELECT vprt.id AS token_id, vprt.viewer_account_id, vprt.security_version AS token_security_version, '
        . 'vprt.expires_at, vprt.consumed_at, vprt.invalidated_at, '
        . 'va.password_hash, va.status, va.email_verified_at, va.security_version AS account_security_version '
        . 'FROM viewer_password_reset_tokens vprt INNER JOIN viewer_accounts va ON va.id = vprt.viewer_account_id '
        . 'WHERE vprt.token_hash = ? LIMIT 1'
    );
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Lock one password-reset token row by primary key. */
function viewer_authentication_model_password_reset_lock(int $tokenId): ?array
{
    $stmt = db()->prepare('SELECT * FROM viewer_password_reset_tokens WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$tokenId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Compare-and-set the account password/security-version during final reset. */
function viewer_authentication_model_complete_password_reset(
    int $viewerAccountId,
    int $expectedSecurityVersion,
    string $passwordHash,
    int $newSecurityVersion,
    string $now
): bool {
    $stmt = db()->prepare(
        'UPDATE viewer_accounts SET password_hash = ?, must_change_password = 0, password_changed_at = ?, security_version = ?, updated_at = ? '
        . 'WHERE id = ? AND security_version = ?'
    );
    $stmt->execute([
        $passwordHash,
        $now,
        $newSecurityVersion,
        $now,
        $viewerAccountId,
        $expectedSecurityVersion,
    ]);
    return $stmt->rowCount() === 1;
}

/** Consume the exact locked reset token with a compare-and-set guard. */
function viewer_authentication_model_consume_password_reset(int $tokenId, string $now): bool
{
    $stmt = db()->prepare(
        'UPDATE viewer_password_reset_tokens SET consumed_at = ? '
        . 'WHERE id = ? AND consumed_at IS NULL AND invalidated_at IS NULL'
    );
    $stmt->execute([$now, $tokenId]);
    return $stmt->rowCount() === 1;
}

/** Invalidate every other reset token and revoke active session/remember authority after reset. */
function viewer_authentication_model_revoke_after_password_reset(int $viewerAccountId, int $consumedTokenId, string $now): void
{
    db()->prepare(
        'UPDATE viewer_password_reset_tokens SET invalidated_at = ? '
        . 'WHERE viewer_account_id = ? AND id <> ? AND consumed_at IS NULL AND invalidated_at IS NULL'
    )->execute([$now, $viewerAccountId, $consumedTokenId]);
    db()->prepare('UPDATE viewer_sessions SET revoked_at = ? WHERE viewer_account_id = ? AND revoked_at IS NULL')
        ->execute([$now, $viewerAccountId]);
    db()->prepare('UPDATE viewer_remember_tokens SET revoked_at = ? WHERE viewer_account_id = ? AND revoked_at IS NULL')
        ->execute([$now, $viewerAccountId]);
}
