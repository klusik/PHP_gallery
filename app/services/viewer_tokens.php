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
use function Gallery\Core\db;
use function Gallery\Core\now_sql;

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
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $pdo->prepare('UPDATE viewer_email_verification_tokens SET invalidated_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND invalidated_at IS NULL')
            ->execute([$now, $viewerAccountId]);
        $pdo->prepare('INSERT INTO viewer_email_verification_tokens (viewer_account_id, token_hash, email_fingerprint, created_at, expires_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$viewerAccountId, security_authority_token_hash($token), $emailFingerprint, $now, $expiresAt]);
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $token;
    } catch (\Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
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
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $pdo->prepare('UPDATE viewer_password_reset_tokens SET invalidated_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND invalidated_at IS NULL')
            ->execute([$now, $viewerAccountId]);
        $pdo->prepare('INSERT INTO viewer_password_reset_tokens (viewer_account_id, token_hash, security_version, created_at, expires_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$viewerAccountId, security_authority_token_hash($token), $securityVersion, $now, $expiresAt]);
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $token;
    } catch (\Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
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
    if (!isset($allowedTables[$table]) || $token === '') {
        return null;
    }
    if (!viewer_token_table_storage_available($table)) {
        return null;
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE token_hash = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([security_authority_token_hash($token)]);
        $row = $stmt->fetch();
        if (!$row || !viewer_one_time_token_row_is_usable($row)) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return null;
        }

        $consumedAt = now_sql();
        $update = $pdo->prepare('UPDATE ' . $table . ' SET consumed_at = ? WHERE id = ? AND consumed_at IS NULL AND invalidated_at IS NULL');
        $update->execute([$consumedAt, (int) $row['id']]);
        if ($update->rowCount() !== 1) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return null;
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
        $row['consumed_at'] = $consumedAt;
        return $row;
    } catch (\Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
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
    $limit = max(1, min(100, $limit));
    $stmt = db()->prepare(
        'DELETE FROM viewer_remember_tokens WHERE viewer_account_id = ? '
        . 'AND (revoked_at IS NOT NULL OR expires_at < ?) ORDER BY id ASC LIMIT ' . $limit
    );
    $stmt->execute([$viewerAccountId, $now]);
    return $stmt->rowCount();
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
    $cap = (int) viewer_accounts_config()['max_active_viewer_remember_tokens_per_account'];
    $allowedExisting = max(0, $cap - max(0, $reserveSlots));
    $countStmt = db()->prepare(
        'SELECT COUNT(*) FROM viewer_remember_tokens WHERE viewer_account_id = ? AND revoked_at IS NULL AND expires_at >= ?'
    );
    $countStmt->execute([$viewerAccountId, $now]);
    $activeCount = (int) $countStmt->fetchColumn();
    $revokeCount = max(0, $activeCount - $allowedExisting);
    if ($revokeCount === 0) {
        return;
    }

    $sql = 'SELECT id FROM viewer_remember_tokens WHERE viewer_account_id = ? AND revoked_at IS NULL AND expires_at >= ?';
    $params = [$viewerAccountId, $now];
    if ($keepTokenId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $keepTokenId;
    }
    $sql .= ' ORDER BY created_at ASC, id ASC LIMIT ' . $revokeCount;
    $idsStmt = db()->prepare($sql);
    $idsStmt->execute($params);
    $ids = array_map('intval', $idsStmt->fetchAll(\PDO::FETCH_COLUMN));
    if ($ids === []) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    db()->prepare('UPDATE viewer_remember_tokens SET revoked_at = ? WHERE id IN (' . $placeholders . ') AND revoked_at IS NULL')
        ->execute(array_merge([$now], $ids));
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

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $accountStmt = $pdo->prepare(
            'SELECT id, password_hash, must_change_password, status, security_version, email_verified_at FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $accountStmt->execute([$viewerAccountId]);
        $account = $accountStmt->fetch();
        if (!$account
            || !viewer_account_can_authenticate($account)
            || viewer_account_requires_password_change($account)
            || (int) ($account['security_version'] ?? 0) !== $securityVersion) {
            throw new RuntimeException('Viewer remember-token issuance is unavailable.');
        }

        $selector = security_token_selector_generate(18);
        $verifier = security_opaque_token_generate(32);
        $config = viewer_accounts_config();
        $now = now_sql();
        $expiresAt = date('Y-m-d H:i:s', time() + ((int) $config['remember_lifetime_days'] * 86400));
        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $userAgentHash = $userAgent === '' ? null : viewer_security_fingerprint('viewer-remember-ua', $userAgent);

        viewer_remember_token_cleanup_account_locked($viewerAccountId, $now);
        viewer_remember_token_enforce_limit_locked($viewerAccountId, $now, 1);

        $stmt = $pdo->prepare(
            'INSERT INTO viewer_remember_tokens '
            . '(viewer_account_id, selector, verifier_hash, security_version, user_agent_hash, created_at, expires_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $viewerAccountId,
            $selector,
            security_authority_token_hash($verifier),
            $securityVersion,
            $userAgentHash,
            $now,
            $expiresAt,
        ]);

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return ['selector' => $selector, 'verifier' => $verifier, 'expires_at' => $expiresAt];
    } catch (\Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
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

    $stmt = db()->prepare(
        'SELECT vrt.*, va.password_hash, va.must_change_password, va.status AS account_status, va.email_verified_at, '
        . 'va.security_version AS account_security_version '
        . 'FROM viewer_remember_tokens vrt INNER JOIN viewer_accounts va ON va.id = vrt.viewer_account_id '
        . 'WHERE vrt.selector = ? AND vrt.revoked_at IS NULL AND vrt.expires_at >= ? LIMIT 1'
    );
    $stmt->execute([$selector, now_sql()]);
    $row = $stmt->fetch();
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
    db()->prepare('UPDATE viewer_remember_tokens SET revoked_at = ? WHERE selector = ? AND revoked_at IS NULL')
        ->execute([now_sql(), $selector]);
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

    $lookup = db()->prepare('SELECT viewer_account_id FROM viewer_remember_tokens WHERE selector = ? LIMIT 1');
    $lookup->execute([$selector]);
    $viewerAccountId = (int) $lookup->fetchColumn();
    if ($viewerAccountId <= 0) {
        return null;
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $accountStmt = $pdo->prepare(
            'SELECT id, email, normalized_email, password_hash, must_change_password, status, security_version, email_verified_at '
            . 'FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $accountStmt->execute([$viewerAccountId]);
        $account = $accountStmt->fetch();
        if (!$account
            || !viewer_account_can_authenticate($account)
            || viewer_account_requires_password_change($account)) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return null;
        }

        $tokenStmt = $pdo->prepare('SELECT * FROM viewer_remember_tokens WHERE selector = ? LIMIT 1 FOR UPDATE');
        $tokenStmt->execute([$selector]);
        $token = $tokenStmt->fetch();
        $expiresAtTimestamp = $token ? strtotime((string) ($token['expires_at'] ?? '')) : false;
        if (!$token
            || !empty($token['revoked_at'])
            || $expiresAtTimestamp === false
            || $expiresAtTimestamp < time()
            || (int) ($token['viewer_account_id'] ?? 0) !== $viewerAccountId
            || (int) ($token['security_version'] ?? 0) !== (int) ($account['security_version'] ?? -1)
            || !security_authority_token_verify((string) ($token['verifier_hash'] ?? ''), $verifier)) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return null;
        }

        $now = now_sql();
        viewer_remember_token_cleanup_account_locked($viewerAccountId, $now);
        viewer_remember_token_enforce_limit_locked($viewerAccountId, $now, 0, (int) $token['id']);

        $newSelector = security_token_selector_generate(18);
        $newVerifier = security_opaque_token_generate(32);
        $newExpiresAt = date(
            'Y-m-d H:i:s',
            time() + ((int) viewer_accounts_config()['remember_lifetime_days'] * 86400)
        );
        $rotate = $pdo->prepare(
            'UPDATE viewer_remember_tokens SET selector = ?, verifier_hash = ?, last_used_at = ?, expires_at = ? '
            . 'WHERE id = ? AND selector = ? AND revoked_at IS NULL'
        );
        $rotate->execute([
            $newSelector,
            security_authority_token_hash($newVerifier),
            $now,
            $newExpiresAt,
            (int) $token['id'],
            $selector,
        ]);
        if ($rotate->rowCount() !== 1) {
            throw new RuntimeException('Viewer remember credential rotation lost a concurrent race.');
        }

        viewer_session_establish($account);
        if (function_exists(__NAMESPACE__ . '\\viewer_security_event_record')) {
            viewer_security_event_record('viewer.remember_restored', $viewerAccountId, 'success', [
                'security_version' => (int) $account['security_version'],
            ]);
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return ['selector' => $newSelector, 'verifier' => $newVerifier, 'expires_at' => $newExpiresAt];
    } catch (\Throwable $exception) {
        viewer_session_clear();
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
