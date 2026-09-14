<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/viewer_registration.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns durable viewer-registration and invitation persistence.
 *
 * Responsibilities:
 *   - Own invitation, staged-registration, and verification-token SQL
 *   - Own registration capacity locks/counters and transaction mechanics
 *   - Preserve compare-and-set and row-lock semantics for verification/activation
 *   - Provide bounded persistence operations to the viewer-registration service
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Plaintext tokens, email-policy checks, hashing decisions, registration policy,
 *     password policy, and PHP session state remain outside this model.
 *
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Models;

use RuntimeException;
use Throwable;
use function Gallery\Core\db;


/** Internal rollback signal carrying a non-exceptional workflow result. */
final class ViewerRegistrationModelRollback extends RuntimeException
{
    /** Store the non-exceptional workflow result carried across rollback. */
    public function __construct(public readonly mixed $result)
    {
        parent::__construct('Viewer registration transaction rolled back by workflow decision.');
    }
}

/** Abort the current registration-model transaction and return the supplied workflow result. */
function viewer_registration_model_abort(mixed $result): never
{
    throw new ViewerRegistrationModelRollback($result);
}

/**
 * Execute a viewer-registration unit of work inside the current or a new transaction.
 *
 * @template T
 * @param callable():T $operation
 * @return T
 */
function viewer_registration_model_transaction(callable $operation)
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
    } catch (ViewerRegistrationModelRollback $rollback) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return $rollback->result;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** Execute a registration operation in a new top-level transaction only. */
function viewer_registration_model_independent_transaction(callable $operation)
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Verification resend delivery requires an independent transaction boundary.');
    }
    $pdo->beginTransaction();
    try {
        $result = $operation();
        $pdo->commit();
        return $result;
    } catch (ViewerRegistrationModelRollback $rollback) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return $rollback->result;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** Return one invitation/public-registration join by invitation token hash. */
function viewer_registration_model_invitation_inspect(string $tokenHash): ?array
{
    $stmt = db()->prepare(
        'SELECT vi.id, vi.target_email_fingerprint, vi.expires_at, vi.claimed_at, vi.revoked_at, '
        . 'vrr.status AS registration_status, vrr.expires_at AS registration_expires_at '
        . 'FROM viewer_invitations vi '
        . 'LEFT JOIN viewer_registration_requests vrr ON vrr.viewer_invitation_id = vi.id '
        . 'WHERE vi.token_hash = ? LIMIT 1'
    );
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Return bounded administrator-facing invitation rows. */
function viewer_registration_model_invitation_list(int $limit): array
{
    $limit = max(1, min(500, $limit));
    $stmt = db()->query(
        'SELECT vi.id, vi.target_email, vi.created_by_admin_user_id, vi.created_at, vi.expires_at, vi.claimed_at, vi.revoked_at, '
        . 'CASE WHEN vi.target_email_fingerprint IS NULL OR vi.target_email_fingerprint = \'\' THEN 0 ELSE 1 END AS email_bound, '
        . 'vrr.status AS registration_status, vrr.email AS registration_email '
        . 'FROM viewer_invitations vi '
        . 'LEFT JOIN viewer_registration_requests vrr ON vrr.viewer_invitation_id = vi.id '
        . 'ORDER BY vi.created_at DESC, vi.id DESC LIMIT ' . $limit
    );
    return $stmt ? ($stmt->fetchAll() ?: []) : [];
}

/** Return whether an administrator user id exists. */
function viewer_registration_model_admin_exists(int $adminUserId): bool
{
    $stmt = db()->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$adminUserId]);
    return (int) $stmt->fetchColumn() === $adminUserId;
}

/** Insert one hashed invitation row and return its identifier. */
function viewer_registration_model_invitation_insert(
    string $tokenHash,
    ?string $targetEmail,
    ?string $targetEmailFingerprint,
    int $adminUserId,
    string $createdAt,
    string $expiresAt
): int {
    $stmt = db()->prepare(
        'INSERT INTO viewer_invitations '
        . '(token_hash, target_email, target_email_fingerprint, created_by_admin_user_id, created_at, expires_at) '
        . 'VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$tokenHash, $targetEmail, $targetEmailFingerprint, $adminUserId, $createdAt, $expiresAt]);
    return (int) db()->lastInsertId();
}

/** Return one invitation by token hash. */
function viewer_registration_model_invitation_by_hash(string $tokenHash): ?array
{
    $stmt = db()->prepare('SELECT * FROM viewer_invitations WHERE token_hash = ? LIMIT 1');
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Return one invitation and any attached staged-registration metadata by token hash. */
function viewer_registration_model_invitation_preflight(string $tokenHash): ?array
{
    $stmt = db()->prepare(
        'SELECT vi.*, vrr.normalized_email AS registration_normalized_email, '
        . 'vrr.status AS registration_status, vrr.expires_at AS registration_expires_at '
        . 'FROM viewer_invitations vi '
        . 'LEFT JOIN viewer_registration_requests vrr ON vrr.viewer_invitation_id = vi.id '
        . 'WHERE vi.token_hash = ? LIMIT 1'
    );
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Ensure and lock the singleton staged-registration capacity row. */
function viewer_registration_model_capacity_lock(string $stateKey, string $now): int
{
    $pdo = db();
    $pdo->prepare(
        'INSERT INTO viewer_registration_state (state_key, active_request_count, updated_at) '
        . 'VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE updated_at = updated_at'
    )->execute([$stateKey, $now]);
    $stmt = $pdo->prepare(
        'SELECT active_request_count FROM viewer_registration_state '
        . 'WHERE state_key = ? LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([$stateKey]);
    $count = $stmt->fetchColumn();
    if ($count === false) {
        throw new RuntimeException('Viewer registration capacity state could not be locked.');
    }
    return (int) $count;
}

/** Recount staged registration rows while the capacity row is locked. */
function viewer_registration_model_capacity_recount_locked(string $stateKey, string $now): int
{
    $count = (int) db()->query('SELECT COUNT(*) FROM viewer_registration_requests')->fetchColumn();
    db()->prepare('UPDATE viewer_registration_state SET active_request_count = ?, updated_at = ? WHERE state_key = ?')
        ->execute([$count, $now, $stateKey]);
    return $count;
}

/** Delete a bounded batch of expired staged registrations and return the remaining row count. */
function viewer_registration_model_cleanup_requests_locked(string $stateKey, string $now, int $limit): int
{
    $limit = max(1, min(1000, $limit));
    $stmt = db()->prepare('DELETE FROM viewer_registration_requests WHERE expires_at < ? LIMIT ' . $limit);
    $stmt->execute([$now]);
    return viewer_registration_model_capacity_recount_locked($stateKey, $now);
}

/** Cancel every currently active open-origin staged registration. */
function viewer_registration_model_cancel_open_origin(string $cancelledStatus, string $pendingStatus, string $verifiedStatus, string $now): int
{
    $stmt = db()->prepare(
        'UPDATE viewer_registration_requests SET status = ?, cancelled_at = ?, updated_at = ? '
        . 'WHERE viewer_invitation_id IS NULL AND status IN (?, ?) AND cancelled_at IS NULL'
    );
    $stmt->execute([$cancelledStatus, $now, $now, $pendingStatus, $verifiedStatus]);
    return $stmt->rowCount();
}

/** Return whether a durable viewer account already owns the normalized email. */
function viewer_registration_model_account_exists(string $normalizedEmail): bool
{
    $stmt = db()->prepare('SELECT id FROM viewer_accounts WHERE normalized_email = ? LIMIT 1');
    $stmt->execute([$normalizedEmail]);
    return $stmt->fetchColumn() !== false;
}

/** Lock one staged registration by normalized email. */
function viewer_registration_model_request_lock_by_normalized_email(string $normalizedEmail): ?array
{
    $stmt = db()->prepare('SELECT * FROM viewer_registration_requests WHERE normalized_email = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$normalizedEmail]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Lock one staged registration by primary key. */
function viewer_registration_model_request_lock(int $requestId): ?array
{
    $stmt = db()->prepare('SELECT * FROM viewer_registration_requests WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$requestId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Lock one invitation by token hash. */
function viewer_registration_model_invitation_lock_by_hash(string $tokenHash): ?array
{
    $stmt = db()->prepare('SELECT * FROM viewer_invitations WHERE token_hash = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Lock one invitation by primary key. */
function viewer_registration_model_invitation_lock(int $invitationId): ?array
{
    $stmt = db()->prepare('SELECT * FROM viewer_invitations WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$invitationId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Update one existing staged registration back to pending-verification state. */
function viewer_registration_model_request_update_pending(
    int $requestId,
    string $email,
    string $normalizedEmail,
    string $emailFingerprint,
    ?int $invitationId,
    string $pendingStatus,
    ?string $ipHash,
    string $verificationTokenHash,
    string $verificationTokenExpiresAt,
    string $requestExpiresAt,
    string $now
): void {
    $stmt = db()->prepare(
        'UPDATE viewer_registration_requests SET '
        . 'email = ?, normalized_email = ?, email_fingerprint = ?, viewer_invitation_id = ?, '
        . 'status = ?, request_ip_hash = ?, verification_token_hash = ?, '
        . 'verification_token_expires_at = ?, verification_token_consumed_at = NULL, '
        . 'expires_at = ?, verified_at = NULL, cancelled_at = NULL, updated_at = ? '
        . 'WHERE id = ?'
    );
    $stmt->execute([
        $email, $normalizedEmail, $emailFingerprint, $invitationId, $pendingStatus, $ipHash,
        $verificationTokenHash, $verificationTokenExpiresAt, $requestExpiresAt, $now, $requestId,
    ]);
}

/** Insert one new staged registration and return its identifier. */
function viewer_registration_model_request_insert_pending(
    string $email,
    string $normalizedEmail,
    string $emailFingerprint,
    ?int $invitationId,
    string $pendingStatus,
    ?string $ipHash,
    string $verificationTokenHash,
    string $verificationTokenExpiresAt,
    string $requestExpiresAt,
    string $now
): int {
    $stmt = db()->prepare(
        'INSERT INTO viewer_registration_requests '
        . '(email, normalized_email, email_fingerprint, viewer_invitation_id, status, request_ip_hash, '
        . 'verification_token_hash, verification_token_expires_at, expires_at, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $email, $normalizedEmail, $emailFingerprint, $invitationId, $pendingStatus, $ipHash,
        $verificationTokenHash, $verificationTokenExpiresAt, $requestExpiresAt, $now, $now,
    ]);
    return (int) db()->lastInsertId();
}

/** Increment the singleton staged-registration count after one insert. */
function viewer_registration_model_capacity_increment(string $stateKey, string $now): void
{
    db()->prepare(
        'UPDATE viewer_registration_state SET active_request_count = active_request_count + 1, updated_at = ? WHERE state_key = ?'
    )->execute([$now, $stateKey]);
}

/** Claim one unclaimed invitation with a compare-and-set guard. */
function viewer_registration_model_invitation_claim(int $invitationId, string $now): bool
{
    $stmt = db()->prepare(
        'UPDATE viewer_invitations SET claimed_at = ? WHERE id = ? AND claimed_at IS NULL AND revoked_at IS NULL AND expires_at >= ?'
    );
    $stmt->execute([$now, $invitationId, $now]);
    return $stmt->rowCount() === 1;
}

/** Delete expired resend authorities for one staged request. */
function viewer_registration_model_resend_cleanup(int $requestId, string $now): void
{
    db()->prepare(
        'DELETE FROM viewer_registration_verification_tokens WHERE viewer_registration_request_id = ? AND expires_at < ?'
    )->execute([$requestId, $now]);
}

/** Count resend authorities for one staged request. */
function viewer_registration_model_resend_count(int $requestId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM viewer_registration_verification_tokens WHERE viewer_registration_request_id = ?');
    $stmt->execute([$requestId]);
    return (int) $stmt->fetchColumn();
}

/** Insert one hashed resend authority and return its identifier. */
function viewer_registration_model_resend_insert(int $requestId, string $tokenHash, string $expiresAt, string $now): int
{
    $stmt = db()->prepare(
        'INSERT INTO viewer_registration_verification_tokens '
        . '(viewer_registration_request_id, token_hash, expires_at, created_at, sent_at) VALUES (?, ?, ?, ?, NULL)'
    );
    $stmt->execute([$requestId, $tokenHash, $expiresAt, $now]);
    return (int) db()->lastInsertId();
}

/** Delete one unsent resend authority. */
function viewer_registration_model_resend_delete_unsent(int $requestId, int $authorityId): bool
{
    $stmt = db()->prepare(
        'DELETE FROM viewer_registration_verification_tokens WHERE id = ? AND viewer_registration_request_id = ? AND sent_at IS NULL'
    );
    $stmt->execute([$authorityId, $requestId]);
    return $stmt->rowCount() === 1;
}

/** Lock one staged request joined to one resend authority. */
function viewer_registration_model_resend_lock(int $requestId, int $authorityId): ?array
{
    $stmt = db()->prepare(
        'SELECT vrr.*, vrvt.id AS resend_token_id, vrvt.expires_at AS resend_token_expires_at, '
        . 'vrvt.sent_at AS resend_token_sent_at '
        . 'FROM viewer_registration_verification_tokens vrvt '
        . 'INNER JOIN viewer_registration_requests vrr ON vrr.id = vrvt.viewer_registration_request_id '
        . 'WHERE vrr.id = ? AND vrvt.id = ? LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([$requestId, $authorityId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Mark one resend authority delivered with a compare-and-set guard. */
function viewer_registration_model_resend_mark_sent(int $requestId, int $authorityId, string $now): bool
{
    $stmt = db()->prepare(
        'UPDATE viewer_registration_verification_tokens SET sent_at = ? '
        . 'WHERE id = ? AND viewer_registration_request_id = ? AND sent_at IS NULL AND expires_at >= ?'
    );
    $stmt->execute([$now, $authorityId, $requestId, $now]);
    return $stmt->rowCount() === 1;
}

/** Increment staged-request verification-send metadata with eligibility guards. */
function viewer_registration_model_request_mark_verification_sent(int $requestId, string $pendingStatus, string $now): bool
{
    $stmt = db()->prepare(
        'UPDATE viewer_registration_requests '
        . 'SET verification_send_count = verification_send_count + 1, verification_last_sent_at = ?, updated_at = ? '
        . 'WHERE id = ? AND status = ? AND cancelled_at IS NULL AND expires_at >= ?'
    );
    $stmt->execute([$now, $now, $requestId, $pendingStatus, $now]);
    return $stmt->rowCount() === 1;
}

/** Resolve the primary verification authority, optionally under a write lock. */
function viewer_registration_model_verification_primary(string $tokenHash, bool $forUpdate): ?array
{
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = db()->prepare('SELECT * FROM viewer_registration_requests WHERE verification_token_hash = ? LIMIT 1' . $lock);
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Resolve a child resend verification authority, optionally under a write lock. */
function viewer_registration_model_verification_resend(string $tokenHash, bool $forUpdate): ?array
{
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = db()->prepare(
        'SELECT vrr.*, vrvt.id AS resend_token_id, vrvt.expires_at AS resend_token_expires_at, '
        . 'vrvt.sent_at AS resend_token_sent_at '
        . 'FROM viewer_registration_verification_tokens vrvt '
        . 'INNER JOIN viewer_registration_requests vrr ON vrr.id = vrvt.viewer_registration_request_id '
        . 'WHERE vrvt.token_hash = ? LIMIT 1' . $lock
    );
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Compare-and-set one staged request from pending to email-verified. */
function viewer_registration_model_confirm_request(
    int $requestId,
    string $pendingStatus,
    string $verifiedStatus,
    string $verifiedExpiry,
    string $now
): bool {
    $stmt = db()->prepare(
        'UPDATE viewer_registration_requests SET status = ?, verification_token_consumed_at = ?, '
        . 'verified_at = ?, expires_at = ?, updated_at = ? '
        . 'WHERE id = ? AND status = ? AND verification_token_consumed_at IS NULL '
        . 'AND cancelled_at IS NULL AND expires_at >= ?'
    );
    $stmt->execute([$verifiedStatus, $now, $now, $verifiedExpiry, $now, $requestId, $pendingStatus, $now]);
    return $stmt->rowCount() === 1;
}

/** Delete all child verification authorities for one staged request. */
function viewer_registration_model_verification_delete_for_request(int $requestId): void
{
    db()->prepare('DELETE FROM viewer_registration_verification_tokens WHERE viewer_registration_request_id = ?')
        ->execute([$requestId]);
}

/** Revoke one invitation with a compare-and-set guard. */
function viewer_registration_model_invitation_revoke(int $invitationId, string $now): bool
{
    $stmt = db()->prepare('UPDATE viewer_invitations SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL');
    $stmt->execute([$now, $invitationId]);
    return $stmt->rowCount() === 1;
}

/** Cancel staged requests tied to a revoked invitation. */
function viewer_registration_model_cancel_for_invitation(int $invitationId, string $cancelledStatus, string $pendingStatus, string $verifiedStatus, string $now): void
{
    db()->prepare(
        'UPDATE viewer_registration_requests SET status = ?, cancelled_at = ?, updated_at = ? '
        . 'WHERE viewer_invitation_id = ? AND status IN (?, ?)'
    )->execute([$cancelledStatus, $now, $now, $invitationId, $pendingStatus, $verifiedStatus]);
}

/** Delete one invitation row. */
function viewer_registration_model_invitation_delete(int $invitationId): bool
{
    $stmt = db()->prepare('DELETE FROM viewer_invitations WHERE id = ?');
    $stmt->execute([$invitationId]);
    return $stmt->rowCount() === 1;
}

/** Delete a bounded batch of expired child verification authorities. */
function viewer_registration_model_maintenance_delete_verification(string $now): int
{
    $stmt = db()->prepare('DELETE FROM viewer_registration_verification_tokens WHERE expires_at < ? LIMIT 1000');
    $stmt->execute([$now]);
    return $stmt->rowCount();
}

/** Count all staged registration rows. */
function viewer_registration_model_request_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM viewer_registration_requests')->fetchColumn();
}

/** Delete a bounded batch of old invitation capabilities. */
function viewer_registration_model_maintenance_delete_invitations(string $now, string $oldCutoff): int
{
    $stmt = db()->prepare(
        'DELETE FROM viewer_invitations WHERE expires_at < ? OR revoked_at < ? OR claimed_at < ? LIMIT 1000'
    );
    $stmt->execute([$now, $oldCutoff, $oldCutoff]);
    return $stmt->rowCount();
}

/** Insert one activated durable viewer account and return its identifier. */
function viewer_registration_model_activate_account(
    string $email,
    string $normalizedEmail,
    string $passwordHash,
    string $activeStatus,
    string $verifiedAt,
    string $now
): int {
    $stmt = db()->prepare(
        'INSERT INTO viewer_accounts '
        . '(email, normalized_email, password_hash, status, security_version, email_verified_at, password_changed_at, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)'
    );
    $stmt->execute([$email, $normalizedEmail, $passwordHash, $activeStatus, $verifiedAt, $now, $now, $now]);
    return (int) db()->lastInsertId();
}

/** Persist the durable viewer-account capacity count after activation. */
function viewer_registration_model_account_capacity_set(string $stateKey, int $count, string $now): void
{
    db()->prepare('UPDATE viewer_account_state SET account_count = ?, updated_at = ? WHERE state_key = ?')
        ->execute([$count, $now, $stateKey]);
}

/** Delete the exact staging row consumed by activation. */
function viewer_registration_model_request_delete(int $requestId): bool
{
    $stmt = db()->prepare('DELETE FROM viewer_registration_requests WHERE id = ?');
    $stmt->execute([$requestId]);
    return $stmt->rowCount() === 1;
}

/** Persist the staged-registration capacity count after activation. */
function viewer_registration_model_capacity_set(string $stateKey, int $count, string $now): void
{
    db()->prepare('UPDATE viewer_registration_state SET active_request_count = ?, updated_at = ? WHERE state_key = ?')
        ->execute([$count, $now, $stateKey]);
}
