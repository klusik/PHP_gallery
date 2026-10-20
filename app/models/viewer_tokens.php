<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/viewer_tokens.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns viewer verification/reset/remember token persistence.
 *
 * Responsibilities:
 *   - Persist only hashed one-time token authority
 *   - Lock one-time and remember-token rows for atomic consumption/rotation
 *   - Enforce bounded active remember-token storage
 *   - Keep plaintext token generation and verification policy in services
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Dynamic token-table selection is restricted to a fixed internal allowlist.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use InvalidArgumentException;
use PDO;
use function Gallery\Core\db;

/**
 * Resolve one allowlisted one-time token table name.
 *
 * @param string $table Allow-listed one-time-token table name.
 * @return string Resolved string value used by the caller.
 */
function viewer_token_model_one_time_table(string $table): string
{
    if (!in_array(
        $table,
        ['viewer_email_verification_tokens', 'viewer_password_reset_tokens'],
        true,
    )) {
        throw new InvalidArgumentException(
            'Viewer one-time token table is not allowlisted.',
        );
    }
    return $table;
}

/**
 * Invalidate old verification tokens and insert one new hashed token.
 *
 * @param string $emailFingerprint Email value used for the email fingerprint.
 * @param string $expiresAt Timestamp for the expires at.
 * @param string $now Now used by this operation.
 * @param string $tokenHash Precomputed hash for the token.
 * @param int $viewerAccountId Identifier of the viewer account.
 * @return void No value; performs the documented operation.
 */
function viewer_token_model_issue_email_verification(
    int $viewerAccountId,
    string $tokenHash,
    string $emailFingerprint,
    string $now,
    string $expiresAt,
): void {
    db()
        ->prepare(
            'UPDATE viewer_email_verification_tokens SET invalidated_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND invalidated_at IS NULL',
        )
        ->execute([$now, $viewerAccountId]);
    db()
        ->prepare(
            'INSERT INTO viewer_email_verification_tokens (viewer_account_id, token_hash, email_fingerprint, created_at, expires_at) VALUES (?, ?, ?, ?, ?)',
        )
        ->execute([
            $viewerAccountId,
            $tokenHash,
            $emailFingerprint,
            $now,
            $expiresAt,
        ]);
}

/**
 * Invalidate old reset tokens and insert one new security-version-bound hashed token.
 *
 * @param string $expiresAt Timestamp for the expires at.
 * @param string $now Now used by this operation.
 * @param int $securityVersion Security version used by this operation.
 * @param string $tokenHash Precomputed hash for the token.
 * @param int $viewerAccountId Identifier of the viewer account.
 * @return void No value; performs the documented operation.
 */
function viewer_token_model_issue_password_reset(
    int $viewerAccountId,
    string $tokenHash,
    int $securityVersion,
    string $now,
    string $expiresAt,
): void {
    db()
        ->prepare(
            'UPDATE viewer_password_reset_tokens SET invalidated_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND invalidated_at IS NULL',
        )
        ->execute([$now, $viewerAccountId]);
    db()
        ->prepare(
            'INSERT INTO viewer_password_reset_tokens (viewer_account_id, token_hash, security_version, created_at, expires_at) VALUES (?, ?, ?, ?, ?)',
        )
        ->execute([
            $viewerAccountId,
            $tokenHash,
            $securityVersion,
            $now,
            $expiresAt,
        ]);
}

/**
 * Lock one one-time token row by hash.
 *
 * @param string $table Allow-listed one-time-token table name.
 * @param string $tokenHash Precomputed hash for the token.
 * @return array<string,int|string|null>|null Matching database row as an associative array, or null when no row matches.
 */
function viewer_token_model_lock_one_time(
    string $table,
    string $tokenHash,
): ?array {
    $table = viewer_token_model_one_time_table($table);
    $stmt = db()->prepare(
        'SELECT * FROM ' . $table . ' WHERE token_hash = ? LIMIT 1 FOR UPDATE',
    );
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Consume one locked one-time token row with a compare-and-set guard.
 *
 * @param string $consumedAt Timestamp for the consumed at.
 * @param string $table Allow-listed one-time-token table name.
 * @param int $tokenId Identifier of the token.
 * @return bool True when the requested condition is met or the update affects a row.
 */
function viewer_token_model_mark_consumed(
    string $table,
    int $tokenId,
    string $consumedAt,
): bool {
    $table = viewer_token_model_one_time_table($table);
    $update = db()->prepare(
        'UPDATE '
            . $table
            . ' SET consumed_at = ? WHERE id = ? AND consumed_at IS NULL AND invalidated_at IS NULL',
    );
    $update->execute([$consumedAt, $tokenId]);
    return $update->rowCount() === 1;
}

/**
 * Remove bounded inactive remember-token rows for one account.
 *
 * @param int $limit Limit used by this operation.
 * @param string $now Now used by this operation.
 * @param int $viewerAccountId Identifier of the viewer account.
 * @return int Database identifier or count produced by this operation.
 */
function viewer_token_model_remember_cleanup(
    int $viewerAccountId,
    string $now,
    int $limit,
): int {
    $limit = max(1, min(100, $limit));
    $stmt = db()->prepare(
        'DELETE FROM viewer_remember_tokens WHERE viewer_account_id = ? '
            . 'AND (revoked_at IS NOT NULL OR expires_at < ?) ORDER BY id ASC LIMIT '
            . $limit,
    );
    $stmt->execute([$viewerAccountId, $now]);
    return $stmt->rowCount();
}

/**
 * Enforce the active remember-token cap deterministically.
 *
 * @param int $cap Cap used by this operation.
 * @param int $keepTokenId Identifier of the keep token.
 * @param string $now Now used by this operation.
 * @param int $reserveSlots Reserve slots used by this operation.
 * @param int $viewerAccountId Identifier of the viewer account.
 * @return void No value; performs the documented operation.
 */
function viewer_token_model_remember_enforce_limit(
    int $viewerAccountId,
    string $now,
    int $cap,
    int $reserveSlots,
    int $keepTokenId,
): void {
    $cap = max(1, min(100, $cap));
    $allowedExisting = max(0, $cap - max(0, $reserveSlots));
    $countStmt = db()->prepare(
        'SELECT COUNT(*) FROM viewer_remember_tokens WHERE viewer_account_id = ? AND revoked_at IS NULL AND expires_at >= ?',
    );
    $countStmt->execute([$viewerAccountId, $now]);
    $revokeCount = max(0, (int) $countStmt->fetchColumn() - $allowedExisting);
    if ($revokeCount === 0)
        return;

    $sql = 'SELECT id FROM viewer_remember_tokens WHERE viewer_account_id = ? AND revoked_at IS NULL AND expires_at >= ?';
    $params = [$viewerAccountId, $now];
    if ($keepTokenId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $keepTokenId;
    }
    $sql .= ' ORDER BY created_at ASC, id ASC LIMIT ' . $revokeCount;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if ($ids === [])
        return;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    db()
        ->prepare(
            'UPDATE viewer_remember_tokens SET revoked_at = ? WHERE id IN ('
                . $placeholders
                . ') AND revoked_at IS NULL',
        )
        ->execute(array_merge([$now], $ids));
}

/**
 * Insert one hashed remember credential.
 *
 * @param string $expiresAt Timestamp for the expires at.
 * @param string $now Now used by this operation.
 * @param int $securityVersion Security version used by this operation.
 * @param string $selector Selector used by this operation.
 * @param ?string $userAgentHash Precomputed hash for the user agent.
 * @param string $verifierHash Precomputed hash for the verifier.
 * @param int $viewerAccountId Identifier of the viewer account.
 * @return void No value; performs the documented operation.
 */
function viewer_token_model_remember_insert(
    int $viewerAccountId,
    string $selector,
    string $verifierHash,
    int $securityVersion,
    ?string $userAgentHash,
    string $now,
    string $expiresAt,
): void {
    $stmt = db()->prepare(
        'INSERT INTO viewer_remember_tokens (viewer_account_id, selector, verifier_hash, security_version, user_agent_hash, created_at, expires_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
    );
    $stmt->execute([
        $viewerAccountId,
        $selector,
        $verifierHash,
        $securityVersion,
        $userAgentHash,
        $now,
        $expiresAt,
    ]);
}

/**
 * Resolve one active remember credential joined to account security state.
 *
 * @param string $now Now used by this operation.
 * @param string $selector Selector used by this operation.
 * @return array<string,int|string|null>|null Matching database row as an associative array, or null when no row matches.
 */
function viewer_token_model_remember_verify_row(
    string $selector,
    string $now,
): ?array {
    $stmt = db()->prepare(
        'SELECT vrt.*, va.password_hash, va.must_change_password, va.status AS account_status, va.email_verified_at, '
            . 'va.security_version AS account_security_version '
            . 'FROM viewer_remember_tokens vrt INNER JOIN viewer_accounts va ON va.id = vrt.viewer_account_id '
            . 'WHERE vrt.selector = ? AND vrt.revoked_at IS NULL AND vrt.expires_at >= ? LIMIT 1',
    );
    $stmt->execute([$selector, $now]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Revoke one active remember credential by selector.
 *
 * @param string $selector Selector used by this operation.
 * @return void No value; performs the documented operation.
  * @param string $now Timestamp used to mark the credential revoked.
*/
function viewer_token_model_remember_revoke(string $selector, string $now): void
{
    db()
        ->prepare(
            'UPDATE viewer_remember_tokens SET revoked_at = ? WHERE selector = ? AND revoked_at IS NULL',
        )
        ->execute([$now, $selector]);
}

/**
 * Resolve the account id addressed by one selector before acquiring the account lock.
 *
 * @param string $selector Selector used by this operation.
 * @return int Database identifier or count produced by this operation.
 */
function viewer_token_model_remember_account_id(string $selector): int
{
    $stmt = db()->prepare(
        'SELECT viewer_account_id FROM viewer_remember_tokens WHERE selector = ? LIMIT 1',
    );
    $stmt->execute([$selector]);
    return (int) $stmt->fetchColumn();
}

/**
 * Lock one remember-token row by selector.
 *
 * @param string $selector Selector used by this operation.
 * @return array<string,int|string|null>|null Matching database row as an associative array, or null when no row matches.
 */
function viewer_token_model_remember_lock(string $selector): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM viewer_remember_tokens WHERE selector = ? LIMIT 1 FOR UPDATE',
    );
    $stmt->execute([$selector]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Rotate one locked remember token using selector compare-and-set semantics.
 *
 * @param string $newExpiresAt Timestamp for the new expires at.
 * @param string $newSelector New selector used by this operation.
 * @param string $newVerifierHash Precomputed hash for the new verifier.
 * @param string $now Now used by this operation.
 * @param string $oldSelector Old selector used by this operation.
 * @param int $tokenId Identifier of the token.
 * @return bool True when the requested condition is met or the update affects a row.
 */
function viewer_token_model_remember_rotate(
    int $tokenId,
    string $oldSelector,
    string $newSelector,
    string $newVerifierHash,
    string $now,
    string $newExpiresAt,
): bool {
    $stmt = db()->prepare(
        'UPDATE viewer_remember_tokens SET selector = ?, verifier_hash = ?, last_used_at = ?, expires_at = ? '
            . 'WHERE id = ? AND selector = ? AND revoked_at IS NULL',
    );
    $stmt->execute([
        $newSelector,
        $newVerifierHash,
        $now,
        $newExpiresAt,
        $tokenId,
        $oldSelector,
    ]);
    return $stmt->rowCount() === 1;
}
