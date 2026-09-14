<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/viewer_accounts.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns durable viewer-account, capacity, and server-side session persistence.
 *
 * Responsibilities:
 *   - Own viewer-account row locks and capacity counters
 *   - Own viewer-session row lifecycle and active-session caps
 *   - Own atomic security-version invalidation and authority revocation
 *   - Provide a narrow transaction unit-of-work for cross-model viewer identity workflows
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Authentication/authorization policy and plaintext credentials never belong here.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use PDO;
use RuntimeException;
use Throwable;
use function Gallery\Core\db;

/**
 * Execute a viewer-identity unit of work inside the current or a new transaction.
 *
 * The callback may call other model functions that share the same PDO connection.
 * The model owns begin/commit/rollback so services never manipulate PDO transactions.
 *
 * @template T
 * @param callable():T $operation
 * @return T
 */
function viewer_account_model_transaction(callable $operation)
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

/** Ensure and lock the singleton durable-account capacity row. */
function viewer_account_model_capacity_lock(string $stateKey, string $now): int
{
    $pdo = db();
    $pdo->prepare(
        'INSERT INTO viewer_account_state (state_key, account_count, updated_at) '
        . 'VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE updated_at = updated_at'
    )->execute([$stateKey, $now]);

    $stmt = $pdo->prepare('SELECT account_count FROM viewer_account_state WHERE state_key = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$stateKey]);
    $count = $stmt->fetchColumn();
    if ($count === false) {
        throw new RuntimeException('Viewer account capacity state could not be locked.');
    }
    return (int) $count;
}

/** Recount viewer accounts while the capacity row is locked. */
function viewer_account_model_capacity_recount_locked(string $stateKey, string $now): int
{
    $count = (int) db()->query('SELECT COUNT(*) FROM viewer_accounts')->fetchColumn();
    db()->prepare('UPDATE viewer_account_state SET account_count = ?, updated_at = ? WHERE state_key = ?')
        ->execute([$count, $now, $stateKey]);
    return $count;
}

/** Return one account row under a write lock. */
function viewer_account_model_lock(int $viewerAccountId): ?array
{
    $stmt = db()->prepare('SELECT * FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$viewerAccountId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Return the authentication columns for one locked viewer account. */
function viewer_account_model_lock_auth(int $viewerAccountId): ?array
{
    $stmt = db()->prepare(
        'SELECT id, email, normalized_email, password_hash, must_change_password, status, security_version, email_verified_at '
        . 'FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([$viewerAccountId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Remove a bounded set of inactive server-side sessions for one account. */
function viewer_account_model_session_cleanup(int $viewerAccountId, string $now, int $limit): int
{
    $limit = max(1, min(100, $limit));
    $stmt = db()->prepare(
        'DELETE FROM viewer_sessions WHERE viewer_account_id = ? '
        . 'AND (revoked_at IS NOT NULL OR expires_at < ?) ORDER BY id ASC LIMIT ' . $limit
    );
    $stmt->execute([$viewerAccountId, $now]);
    return $stmt->rowCount();
}

/** Revoke oldest active sessions so insertion of one new session respects the supplied cap. */
function viewer_account_model_session_enforce_limit(int $viewerAccountId, string $now, int $cap): void
{
    $cap = max(1, min(100, $cap));
    $countStmt = db()->prepare(
        'SELECT COUNT(*) FROM viewer_sessions WHERE viewer_account_id = ? AND revoked_at IS NULL AND expires_at >= ?'
    );
    $countStmt->execute([$viewerAccountId, $now]);
    $activeCount = (int) $countStmt->fetchColumn();
    $revokeCount = max(0, $activeCount - $cap + 1);
    if ($revokeCount === 0) {
        return;
    }

    $idsStmt = db()->prepare(
        'SELECT id FROM viewer_sessions WHERE viewer_account_id = ? AND revoked_at IS NULL AND expires_at >= ? '
        . 'ORDER BY created_at ASC, id ASC LIMIT ' . $revokeCount
    );
    $idsStmt->execute([$viewerAccountId, $now]);
    $ids = array_map('intval', $idsStmt->fetchAll(PDO::FETCH_COLUMN));
    if ($ids === []) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    db()->prepare('UPDATE viewer_sessions SET revoked_at = ? WHERE id IN (' . $placeholders . ') AND revoked_at IS NULL')
        ->execute(array_merge([$now], $ids));
}

/** Insert one hashed server-side viewer session row. */
function viewer_account_model_session_insert(int $viewerAccountId, string $sessionHash, int $securityVersion, ?string $ipHash, ?string $userAgentHash, string $now, string $expiresAt): void
{
    $stmt = db()->prepare(
        'INSERT INTO viewer_sessions (viewer_account_id, session_hash, security_version, ip_hash, user_agent_hash, created_at, expires_at) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$viewerAccountId, $sessionHash, $securityVersion, $ipHash, $userAgentHash, $now, $expiresAt]);
}

/** Resolve the account/session row addressed by one viewer session authority hash. */
function viewer_account_model_session_principal(int $viewerAccountId, string $sessionHash): ?array
{
    $stmt = db()->prepare(
        'SELECT va.id, va.email, va.normalized_email, va.password_hash, va.must_change_password, va.status, va.security_version, va.email_verified_at, '
        . 'vs.id AS viewer_session_id, vs.security_version AS session_security_version, vs.expires_at, vs.revoked_at '
        . 'FROM viewer_sessions vs INNER JOIN viewer_accounts va ON va.id = vs.viewer_account_id '
        . 'WHERE vs.viewer_account_id = ? AND vs.session_hash = ? LIMIT 1'
    );
    $stmt->execute([$viewerAccountId, $sessionHash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Revoke one current hashed viewer session authority. */
function viewer_account_model_session_revoke(int $viewerAccountId, string $sessionHash, string $now): void
{
    $stmt = db()->prepare(
        'UPDATE viewer_sessions SET revoked_at = ? WHERE viewer_account_id = ? AND session_hash = ? AND revoked_at IS NULL'
    );
    $stmt->execute([$now, $viewerAccountId, $sessionHash]);
}

/** Increment security_version and revoke active session/remember/reset authority atomically. */
function viewer_account_model_invalidate_authentication(int $viewerAccountId, string $now): int
{
    return viewer_account_model_transaction(static function () use ($viewerAccountId, $now): int {
        $lock = db()->prepare('SELECT security_version FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE');
        $lock->execute([$viewerAccountId]);
        if ($lock->fetchColumn() === false) {
            throw new RuntimeException('Viewer account was not found.');
        }

        $stmt = db()->prepare('UPDATE viewer_accounts SET security_version = security_version + 1, updated_at = ? WHERE id = ?');
        $stmt->execute([$now, $viewerAccountId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Viewer authentication invalidation did not update the account.');
        }

        $versionStmt = db()->prepare('SELECT security_version FROM viewer_accounts WHERE id = ? LIMIT 1');
        $versionStmt->execute([$viewerAccountId]);
        $newVersion = (int) $versionStmt->fetchColumn();

        db()->prepare('UPDATE viewer_sessions SET revoked_at = ? WHERE viewer_account_id = ? AND revoked_at IS NULL')->execute([$now, $viewerAccountId]);
        db()->prepare('UPDATE viewer_remember_tokens SET revoked_at = ? WHERE viewer_account_id = ? AND revoked_at IS NULL')->execute([$now, $viewerAccountId]);
        db()->prepare('UPDATE viewer_password_reset_tokens SET invalidated_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND invalidated_at IS NULL')->execute([$now, $viewerAccountId]);
        return $newVersion;
    });
}

/** Persist a status/security-version transition for one already locked account. */
function viewer_account_model_update_status(int $viewerAccountId, string $targetStatus, ?string $suspendedAt, ?string $disabledAt, string $now): void
{
    $update = db()->prepare(
        'UPDATE viewer_accounts SET status = ?, security_version = security_version + 1, '
        . 'suspended_at = ?, disabled_at = ?, updated_at = ? WHERE id = ?'
    );
    $update->execute([$targetStatus, $suspendedAt, $disabledAt, $now, $viewerAccountId]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('Viewer account state transition failed.');
    }
}

/** Revoke every security capability invalidated by a viewer account state transition. */
function viewer_account_model_revoke_transition_authority(int $viewerAccountId, string $now): void
{
    db()->prepare('UPDATE viewer_sessions SET revoked_at = ? WHERE viewer_account_id = ? AND revoked_at IS NULL')->execute([$now, $viewerAccountId]);
    db()->prepare('UPDATE viewer_remember_tokens SET revoked_at = ? WHERE viewer_account_id = ? AND revoked_at IS NULL')->execute([$now, $viewerAccountId]);
    db()->prepare('UPDATE viewer_password_reset_tokens SET invalidated_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND invalidated_at IS NULL')->execute([$now, $viewerAccountId]);
    db()->prepare('UPDATE viewer_email_verification_tokens SET invalidated_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND invalidated_at IS NULL')->execute([$now, $viewerAccountId]);
    db()->prepare('UPDATE viewer_email_change_requests SET cancelled_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND cancelled_at IS NULL')->execute([$now, $viewerAccountId]);
    db()->prepare('UPDATE viewer_collection_share_tokens SET revoked_at = ? WHERE created_by_viewer_account_id = ? AND revoked_at IS NULL')->execute([$now, $viewerAccountId]);
}

/** List bounded viewer-account metadata for Admin management. */
function viewer_account_model_admin_list(int $limit): array
{
    $limit = max(1, min(1000, $limit));
    return db()->query(
        'SELECT id, email, status, must_change_password, created_at, last_login_at, password_changed_at '
        . 'FROM viewer_accounts ORDER BY created_at DESC, id DESC LIMIT ' . $limit
    )->fetchAll() ?: [];
}

/**
 * Atomically create one administrator-provisioned viewer account under the durable capacity lock.
 *
 * @return array{created:bool,reason:string,account_id:?int}
 */
function viewer_account_model_admin_create(string $email, string $normalizedEmail, string $passwordHash, string $activeStatus, int $accountCap, string $capacityStateKey, string $now): array
{
    return viewer_account_model_transaction(static function () use ($email, $normalizedEmail, $passwordHash, $activeStatus, $accountCap, $capacityStateKey, $now): array {
        viewer_account_model_capacity_lock($capacityStateKey, $now);
        if (viewer_account_model_capacity_recount_locked($capacityStateKey, $now) >= $accountCap) {
            return ['created' => false, 'reason' => 'account_capacity', 'account_id' => null];
        }

        $existing = db()->prepare('SELECT id FROM viewer_accounts WHERE normalized_email = ? LIMIT 1 FOR UPDATE');
        $existing->execute([$normalizedEmail]);
        if ($existing->fetchColumn() !== false) {
            return ['created' => false, 'reason' => 'account_exists', 'account_id' => null];
        }

        $insert = db()->prepare(
            'INSERT INTO viewer_accounts '
            . '(email, normalized_email, password_hash, must_change_password, status, security_version, email_verified_at, password_changed_at, created_at, updated_at) '
            . 'VALUES (?, ?, ?, 1, ?, 1, ?, NULL, ?, ?)'
        );
        $insert->execute([$email, $normalizedEmail, $passwordHash, $activeStatus, $now, $now, $now]);
        $accountId = (int) db()->lastInsertId();
        if ($accountId <= 0) {
            throw new RuntimeException('Administrator-provisioned viewer account did not receive an id.');
        }
        viewer_account_model_capacity_recount_locked($capacityStateKey, $now);
        return ['created' => true, 'reason' => 'created', 'account_id' => $accountId];
    });
}

/**
 * Atomically invalidate and delete one viewer account under the durable capacity lock.
 *
 * @return array{deleted:bool,reason:string,security_version:?int}
 */
function viewer_account_model_admin_delete(int $viewerAccountId, string $capacityStateKey, string $now): array
{
    return viewer_account_model_transaction(static function () use ($viewerAccountId, $capacityStateKey, $now): array {
        $account = viewer_account_model_lock($viewerAccountId);
        if ($account === null) {
            return ['deleted' => false, 'reason' => 'not_found', 'security_version' => null];
        }

        viewer_account_model_capacity_lock($capacityStateKey, $now);
        viewer_account_model_capacity_recount_locked($capacityStateKey, $now);
        $oldVersion = (int) ($account['security_version'] ?? 0);
        $newVersion = $oldVersion + 1;
        $invalidate = db()->prepare('UPDATE viewer_accounts SET security_version = ?, updated_at = ? WHERE id = ? AND security_version = ?');
        $invalidate->execute([$newVersion, $now, $viewerAccountId, $oldVersion]);
        if ($invalidate->rowCount() !== 1) {
            throw new RuntimeException('Administrator viewer deletion lost the account security-version race.');
        }

        db()->prepare(
            'UPDATE viewer_collection_share_tokens SET revoked_at = ? '
            . 'WHERE created_by_viewer_account_id = ? AND revoked_at IS NULL'
        )->execute([$now, $viewerAccountId]);

        $delete = db()->prepare('DELETE FROM viewer_accounts WHERE id = ?');
        $delete->execute([$viewerAccountId]);
        if ($delete->rowCount() !== 1) {
            throw new RuntimeException('Administrator viewer deletion did not remove the locked account.');
        }
        viewer_account_model_capacity_recount_locked($capacityStateKey, $now);
        return ['deleted' => true, 'reason' => 'account_deleted', 'security_version' => $newVersion];
    });
}
