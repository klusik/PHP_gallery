<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/viewer_lifecycle.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns viewer account-lifecycle persistence and transaction mechanics.
 *
 * Responsibilities:
 *   - Lock viewer accounts and staged email-change requests for lifecycle mutations
 *   - Persist password and verified-email security-version transitions
 *   - Revoke lifecycle-sensitive viewer credentials atomically
 *   - Delete viewer accounts while preserving durable account-capacity accounting
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Password/token verification, lifecycle policy, session state, and security events remain service-owned.
 *
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Models;

use PDOException;
use RuntimeException;
use Throwable;
use function Gallery\Core\db;

/** Domain-level signal for a uniqueness conflict while changing viewer email. */
final class ViewerLifecycleEmailConflict extends RuntimeException
{
}

/** Execute one lifecycle persistence unit of work inside the current or a new transaction. */
function viewer_lifecycle_model_transaction(callable $operation): mixed
{
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $result = $operation();
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** Return one viewer account without acquiring a row lock. */
function viewer_lifecycle_model_account_get(int $viewerAccountId): ?array
{
    $stmt = db()->prepare('SELECT * FROM viewer_accounts WHERE id = ? LIMIT 1');
    $stmt->execute([$viewerAccountId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Lock and return one viewer account. */
function viewer_lifecycle_model_account_lock(int $viewerAccountId): ?array
{
    $stmt = db()->prepare('SELECT * FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$viewerAccountId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Return whether another viewer account already owns the normalized email. */
function viewer_lifecycle_model_email_conflict_exists(string $normalizedEmail, int $excludeViewerAccountId): bool
{
    $stmt = db()->prepare('SELECT id FROM viewer_accounts WHERE normalized_email = ? AND id <> ? LIMIT 1');
    $stmt->execute([$normalizedEmail, $excludeViewerAccountId]);
    return $stmt->fetchColumn() !== false;
}

/** Persist a password/security-version transition using compare-and-set semantics. */
function viewer_lifecycle_model_password_update(
    int $viewerAccountId,
    int $expectedSecurityVersion,
    int $newSecurityVersion,
    string $passwordHash,
    string $now
): void {
    $stmt = db()->prepare(
        'UPDATE viewer_accounts SET password_hash = ?, must_change_password = 0, password_changed_at = ?, security_version = ?, updated_at = ? '
        . 'WHERE id = ? AND security_version = ?'
    );
    $stmt->execute([$passwordHash, $now, $newSecurityVersion, $now, $viewerAccountId, $expectedSecurityVersion]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Viewer password change lost the account security-version race.');
    }
}

/** Revoke every active authority invalidated by a password change. */
function viewer_lifecycle_model_revoke_after_password_change(int $viewerAccountId, string $now): void
{
    db()->prepare('UPDATE viewer_password_reset_tokens SET invalidated_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND invalidated_at IS NULL')->execute([$now, $viewerAccountId]);
    db()->prepare('UPDATE viewer_email_verification_tokens SET invalidated_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND invalidated_at IS NULL')->execute([$now, $viewerAccountId]);
    db()->prepare('UPDATE viewer_email_change_requests SET cancelled_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND cancelled_at IS NULL')->execute([$now, $viewerAccountId]);
    db()->prepare('UPDATE viewer_sessions SET revoked_at = ? WHERE viewer_account_id = ? AND revoked_at IS NULL')->execute([$now, $viewerAccountId]);
    db()->prepare('UPDATE viewer_remember_tokens SET revoked_at = ? WHERE viewer_account_id = ? AND revoked_at IS NULL')->execute([$now, $viewerAccountId]);
}

/** Cancel all currently active email-change requests for one account. */
function viewer_lifecycle_model_email_change_cancel_active(int $viewerAccountId, string $now, ?int $excludeRequestId = null): void
{
    if ($excludeRequestId !== null && $excludeRequestId > 0) {
        db()->prepare(
            'UPDATE viewer_email_change_requests SET cancelled_at = ? '
            . 'WHERE viewer_account_id = ? AND id <> ? AND consumed_at IS NULL AND cancelled_at IS NULL'
        )->execute([$now, $viewerAccountId, $excludeRequestId]);
        return;
    }
    db()->prepare(
        'UPDATE viewer_email_change_requests SET cancelled_at = ? '
        . 'WHERE viewer_account_id = ? AND consumed_at IS NULL AND cancelled_at IS NULL'
    )->execute([$now, $viewerAccountId]);
}

/** Insert one hashed staged email-change request and return its id. */
function viewer_lifecycle_model_email_change_insert(
    int $viewerAccountId,
    string $newEmail,
    string $normalizedNewEmail,
    string $selector,
    string $verificationTokenHash,
    int $securityVersion,
    string $createdAt,
    string $expiresAt
): int {
    $stmt = db()->prepare(
        'INSERT INTO viewer_email_change_requests '
        . '(viewer_account_id, new_email, normalized_new_email, selector, verification_token_hash, security_version, created_at, expires_at) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$viewerAccountId, $newEmail, $normalizedNewEmail, $selector, $verificationTokenHash, $securityVersion, $createdAt, $expiresAt]);
    $requestId = (int) db()->lastInsertId();
    if ($requestId <= 0) {
        throw new RuntimeException('Viewer email-change request was not created.');
    }
    return $requestId;
}

/** Return one active-candidate email-change request joined with account authentication state. */
function viewer_lifecycle_model_email_change_inspect(string $verificationTokenHash): ?array
{
    $stmt = db()->prepare(
        'SELECT vecr.*, va.status AS account_status, va.password_hash, va.email_verified_at, '
        . 'va.security_version AS account_security_version '
        . 'FROM viewer_email_change_requests vecr INNER JOIN viewer_accounts va ON va.id = vecr.viewer_account_id '
        . 'WHERE vecr.verification_token_hash = ? LIMIT 1'
    );
    $stmt->execute([$verificationTokenHash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Lock one staged email-change request. */
function viewer_lifecycle_model_email_change_lock(int $requestId): ?array
{
    $stmt = db()->prepare('SELECT * FROM viewer_email_change_requests WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$requestId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Persist the verified email/security-version transition with duplicate-email normalization. */
function viewer_lifecycle_model_email_update(
    int $viewerAccountId,
    int $expectedSecurityVersion,
    int $newSecurityVersion,
    string $newEmail,
    string $normalizedNewEmail,
    string $now
): void {
    try {
        $stmt = db()->prepare(
            'UPDATE viewer_accounts SET email = ?, normalized_email = ?, email_verified_at = ?, security_version = ?, updated_at = ? '
            . 'WHERE id = ? AND security_version = ?'
        );
        $stmt->execute([$newEmail, $normalizedNewEmail, $now, $newSecurityVersion, $now, $viewerAccountId, $expectedSecurityVersion]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Viewer email change lost the account security-version race.');
        }
    } catch (PDOException $exception) {
        if ((string) $exception->getCode() === '23000') {
            throw new ViewerLifecycleEmailConflict('Viewer email is already in use.', 0, $exception);
        }
        throw $exception;
    }
}

/** Consume exactly one still-active staged email-change request. */
function viewer_lifecycle_model_email_change_consume(int $requestId, string $now): void
{
    $stmt = db()->prepare(
        'UPDATE viewer_email_change_requests SET consumed_at = ? '
        . 'WHERE id = ? AND consumed_at IS NULL AND cancelled_at IS NULL'
    );
    $stmt->execute([$now, $requestId]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Viewer email-change request consumption lost a concurrent race.');
    }
}

/** Revoke authentication authority invalidated by a verified email change. */
function viewer_lifecycle_model_revoke_after_email_change(int $viewerAccountId, string $now): void
{
    db()->prepare('UPDATE viewer_password_reset_tokens SET invalidated_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND invalidated_at IS NULL')->execute([$now, $viewerAccountId]);
    db()->prepare('UPDATE viewer_email_verification_tokens SET invalidated_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND invalidated_at IS NULL')->execute([$now, $viewerAccountId]);
    db()->prepare('UPDATE viewer_sessions SET revoked_at = ? WHERE viewer_account_id = ? AND revoked_at IS NULL')->execute([$now, $viewerAccountId]);
    db()->prepare('UPDATE viewer_remember_tokens SET revoked_at = ? WHERE viewer_account_id = ? AND revoked_at IS NULL')->execute([$now, $viewerAccountId]);
}

/** Invalidate security version before deleting one locked account. */
function viewer_lifecycle_model_account_mark_deleting(int $viewerAccountId, int $expectedSecurityVersion, int $newSecurityVersion, string $now): void
{
    $stmt = db()->prepare('UPDATE viewer_accounts SET security_version = ?, updated_at = ? WHERE id = ? AND security_version = ?');
    $stmt->execute([$newSecurityVersion, $now, $viewerAccountId, $expectedSecurityVersion]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Viewer deletion lost the account security-version race.');
    }
}

/** Revoke collection shares created by an account before account deletion nulls creator ownership. */
function viewer_lifecycle_model_revoke_created_shares(int $viewerAccountId, string $now): void
{
    db()->prepare(
        'UPDATE viewer_collection_share_tokens SET revoked_at = ? '
        . 'WHERE created_by_viewer_account_id = ? AND revoked_at IS NULL'
    )->execute([$now, $viewerAccountId]);
}

/** Delete exactly one viewer account. */
function viewer_lifecycle_model_account_delete(int $viewerAccountId): void
{
    $stmt = db()->prepare('DELETE FROM viewer_accounts WHERE id = ?');
    $stmt->execute([$viewerAccountId]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Viewer account deletion did not remove the locked account.');
    }
}
