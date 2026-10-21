<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/auth_persistence.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns persistence for durable administrator remember-login tokens.
 *
 * Responsibilities:
 *   - Prune expired and revoked remember tokens
 *   - Persist hashed remember-token credentials
 *   - Resolve, touch, and revoke remember-token rows
 *   - Persist, redeem, revoke and audit temporary support grants atomically
 *   - Keep browser-cookie and credential verification policy outside the model
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Validators are never passed to this model, only one-way hashes.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use PDO;
use function Gallery\Core\db;

/**
 * Remove expired and revoked remember tokens, optionally scoped to one user.
 *
 * @param ?int $userId Optional admin user identifier.
 * @param string $now Current SQL timestamp.
 * @return void Deletes expired or revoked remember-token rows in the requested scope.
 */
function auth_persistence_model_prune(?int $userId, string $now): void
{
    if ($userId !== null) {
        $stmt = db()->prepare(
            'DELETE FROM admin_remember_tokens WHERE user_id = ? AND (expires_at < ? OR revoked_at IS NOT NULL)',
        );
        $stmt->execute([$userId, $now]);
        return;
    }

    db()
        ->prepare(
            'DELETE FROM admin_remember_tokens WHERE expires_at < ? OR revoked_at IS NOT NULL',
        )
        ->execute([$now]);
}

/**
 * Revoke excess active tokens and insert one new hashed remember token.
 *
 * @param int $userId Admin user identifier.
 * @param string $selector Public token selector.
 * @param string $tokenHash One-way validator hash.
 * @param string $userAgentHash Privacy-safe user-agent hash.
 * @param string $now Current SQL timestamp.
 * @param string $expiresAt Token expiry timestamp.
 * @param int $keepNewest Number of existing newest active tokens to retain.
 * @return void Revokes excess active tokens and inserts the new token.
 */
function auth_persistence_model_issue_token(
    int $userId,
    string $selector,
    string $tokenHash,
    string $userAgentHash,
    string $now,
    string $expiresAt,
    int $keepNewest,
): void {
    $keepNewest = max(0, min(100, $keepNewest));
    $stmt = db()->prepare(
        'SELECT id FROM admin_remember_tokens WHERE user_id = ? AND revoked_at IS NULL AND expires_at >= ? ORDER BY created_at DESC, id DESC LIMIT 100 OFFSET '
            . $keepNewest,
    );
    $stmt->execute([$userId, $now]);
    $oldIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if ($oldIds !== []) {
        $placeholders = implode(',', array_fill(0, count($oldIds), '?'));
        db()
            ->prepare(
                'UPDATE admin_remember_tokens SET revoked_at = ? WHERE id IN ('
                    . $placeholders
                    . ')',
            )
            ->execute(array_merge([$now], $oldIds));
    }

    $stmt = db()->prepare(
        'INSERT INTO admin_remember_tokens (user_id, selector, token_hash, user_agent_hash, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)',
    );
    $stmt->execute([
        $userId,
        $selector,
        $tokenHash,
        $userAgentHash,
        $now,
        $expiresAt,
    ]);
}

/**
 * Resolve one active remember token joined with its administrator account.
 *
 * @param string $selector Public token selector.
 * @param string $now Current SQL timestamp.
 * @return ?array<string,mixed> Token and user row or null.
 */
function auth_persistence_model_find_active(
    string $selector,
    string $now,
): ?array {
    $stmt = db()->prepare(
        'SELECT art.*, u.username, u.email, u.role FROM admin_remember_tokens art INNER JOIN users u ON u.id = art.user_id WHERE art.selector = ? AND art.revoked_at IS NULL AND art.expires_at >= ? LIMIT 1',
    );
    $stmt->execute([$selector, $now]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Revoke one remember token by row identifier.
 *
 * @param int $tokenId Remember-token row identifier.
 * @param string $now Current SQL timestamp.
 * @return void Sets the token's revocation timestamp.
 */
function auth_persistence_model_revoke_id(int $tokenId, string $now): void
{
    $stmt = db()->prepare(
        'UPDATE admin_remember_tokens SET revoked_at = ? WHERE id = ?',
    );
    $stmt->execute([$now, $tokenId]);
}

/**
 * Mark one remember token as successfully used.
 *
 * @param int $tokenId Remember-token row identifier.
 * @param string $now Current SQL timestamp.
 * @return void Updates the token's last-used timestamp.
 */
function auth_persistence_model_touch(int $tokenId, string $now): void
{
    $stmt = db()->prepare(
        'UPDATE admin_remember_tokens SET last_used_at = ? WHERE id = ?',
    );
    $stmt->execute([$now, $tokenId]);
}

/**
 * Revoke the remember token addressed by a public selector.
 *
 * @param string $selector Public token selector.
 * @param string $now Current SQL timestamp.
 * @return void Sets the matching token's revocation timestamp.
 */
function auth_persistence_model_revoke_selector(
    string $selector,
    string $now,
): void {
    $stmt = db()->prepare(
        'UPDATE admin_remember_tokens SET revoked_at = ? WHERE selector = ?',
    );
    $stmt->execute([$now, $selector]);
}

/**
 * Revoke all currently active remember tokens for one administrator.
 *
 * @param int $userId Administrator user identifier.
 * @param string $now Current SQL timestamp.
 * @return void Sets the revocation timestamp for active user tokens.
 */
function auth_persistence_model_revoke_user(int $userId, string $now): void
{
    $stmt = db()->prepare(
        'UPDATE admin_remember_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL',
    );
    $stmt->execute([$now, $userId]);
}

// Temporary administrator grants share the established Admin authentication
// persistence owner to avoid expanding ordinary route module closures.

/**
 * Write a sanitized grant lifecycle event inside the caller's transaction.
 *
 * @param int $grantId Persisted grant identifier.
 * @param ?int $actorId Permanent user identifier, or null for an anonymous/system actor.
 * @param string $actorType One of owner, delegate, visitor or system.
 * @param string $eventKey A fixed non-secret lifecycle event key.
 * @return void Persists one audit row without any bearer credential.
 */
function admin_support_model_event(
    int $grantId,
    ?int $actorId,
    string $actorType,
    string $eventKey,
): void {
    $stmt = db()->prepare(
        'INSERT INTO admin_support_grant_events
            (grant_id, actor_user_id, actor_type, event_key, created_at)
         VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6))',
    );
    $stmt->execute([$grantId, $actorId, $actorType, $eventKey]);
}

/**
 * Issue a grant and its audit event in the same database transaction.
 *
 * @param int $ownerUserId Permanent administrator who creates the invitation.
 * @param string $tokenHash One-way SHA-256 verifier, never the invitation.
 * @param int $ttlSeconds Fixed support lifetime measured from issuance, in seconds.
 * @return array{id:int,owner_user_id:int,created_at:string,expires_at:string} New grant metadata.
 */
function admin_support_model_create(
    int $ownerUserId,
    string $tokenHash,
    int $ttlSeconds,
): array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO admin_support_grants
                (owner_user_id, token_hash, created_at, expires_at)
             SELECT u.id, ?, UTC_TIMESTAMP(6),
                DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND)
             FROM users u WHERE u.id = ? AND u.role = ?',
        );
        $stmt->execute([$tokenHash, $ttlSeconds, $ownerUserId, 'admin']);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Permanent administrator required.');
        }
        $id = (int) $pdo->lastInsertId();
        admin_support_model_event($id, $ownerUserId, 'owner', 'issued');
        $lookup = $pdo->prepare(
            'SELECT id, owner_user_id, created_at, expires_at
             FROM admin_support_grants WHERE id = ?',
        );
        $lookup->execute([$id]);
        $row = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('New support grant was not readable.');
        }
        $pdo->commit();
        return [
            'id' => (int) $row['id'],
            'owner_user_id' => (int) $row['owner_user_id'],
            'created_at' => (string) $row['created_at'],
            'expires_at' => (string) $row['expires_at'],
        ];
    } catch (\Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Atomically consume one invitation and bind one independent support session.
 *
 * Competing redemptions cannot both succeed; the UPDATE predicate is the
 * authority, not a preceding SELECT. An audit failure rolls back redemption.
 *
 * @param string $tokenHash SHA-256 of the submitted invitation.
 * @param string $sessionHash SHA-256 of an independently random session verifier.
 * @return array{id:int,owner_user_id:int,expires_at:string}|null Claimed grant, or null when invalid/used/revoked/expired.
 */
function admin_support_model_claim(
    string $tokenHash,
    string $sessionHash,
): ?array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE admin_support_grants g
             INNER JOIN users u ON u.id = g.owner_user_id
             SET g.redeemed_at = UTC_TIMESTAMP(6), g.session_hash = ?
             WHERE g.token_hash = ?
               AND g.redeemed_at IS NULL
               AND g.revoked_at IS NULL
               AND g.expires_at > UTC_TIMESTAMP(6)
               AND u.role = ?',
        );
        $stmt->execute([$sessionHash, $tokenHash, 'admin']);
        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            return null;
        }
        $lookup = $pdo->prepare(
            'SELECT id, owner_user_id, expires_at
             FROM admin_support_grants WHERE token_hash = ?',
        );
        $lookup->execute([$tokenHash]);
        $row = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Claimed grant was not readable.');
        }
        $id = (int) $row['id'];
        admin_support_model_event($id, null, 'visitor', 'redeemed');
        $pdo->commit();
        return [
            'id' => $id,
            'owner_user_id' => (int) $row['owner_user_id'],
            'expires_at' => (string) $row['expires_at'],
        ];
    } catch (\Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Validate a session against the live grant and the granting Admin role.
 *
 * @param int $grantId Expected grant identifier.
 * @param int $ownerUserId Expected granting permanent administrator ID.
 * @param string $sessionHash SHA-256 of the support session's random verifier.
 * @return array{id:int,owner_user_id:int,expires_at:string}|null Active grant only.
 */
function admin_support_model_active_session(
    int $grantId,
    int $ownerUserId,
    string $sessionHash,
): ?array {
    $stmt = db()->prepare(
        'SELECT g.id, g.owner_user_id, g.expires_at
         FROM admin_support_grants g
         INNER JOIN users u ON u.id = g.owner_user_id
         WHERE g.id = ? AND g.owner_user_id = ?
           AND g.session_hash = ? AND g.redeemed_at IS NOT NULL
           AND g.revoked_at IS NULL
           AND g.expires_at > UTC_TIMESTAMP(6)
           AND u.role = ? LIMIT 1',
    );
    $stmt->execute([$grantId, $ownerUserId, $sessionHash, 'admin']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? [
        'id' => (int) $row['id'],
        'owner_user_id' => (int) $row['owner_user_id'],
        'expires_at' => (string) $row['expires_at'],
    ] : null;
}

/**
 * Return only the requesting permanent administrator's recent grants.
 *
 * @param int $ownerUserId Permanent owner identifier.
 * @param int $limit Maximum recent rows to display.
 * @return list<array<string,int|string|null>> Newest grants with lifecycle state, never hashes.
 */
function admin_support_model_list(int $ownerUserId, int $limit): array
{
    $stmt = db()->prepare(
        "SELECT id, owner_user_id, created_at, expires_at,
                redeemed_at, revoked_at, last_used_at,
                CASE
                    WHEN revoked_at IS NOT NULL THEN 'revoked'
                    WHEN expires_at <= UTC_TIMESTAMP(6) THEN 'expired'
                    WHEN redeemed_at IS NOT NULL THEN 'active'
                    ELSE 'unused'
                END AS state
         FROM admin_support_grants
         WHERE owner_user_id = ?
         ORDER BY id DESC LIMIT ?",
    );
    $stmt->bindValue(1, $ownerUserId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Revoke a grant atomically, with its audit event.
 *
 * @param int $ownerUserId Permanent owner identifier.
 * @param int $grantId Grant selected for revocation.
 * @return bool True only when a live or expired grant was newly revoked.
 */
function admin_support_model_revoke(int $ownerUserId, int $grantId): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE admin_support_grants SET revoked_at = UTC_TIMESTAMP(6)
             WHERE id = ? AND owner_user_id = ? AND revoked_at IS NULL',
        );
        $stmt->execute([$grantId, $ownerUserId]);
        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }
        admin_support_model_event($grantId, $ownerUserId, 'owner', 'revoked');
        $pdo->commit();
        return true;
    } catch (\Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Record at most one normal-use event per hour per grant.
 *
 * The atomic update controls event volume even across parallel browser tabs.
 *
 * @param int $grantId Active grant identifier.
 * @return void Persists a bounded use event only after a successful touch.
 */
function admin_support_model_touch(int $grantId): void
{
    $stmt = db()->prepare(
        'UPDATE admin_support_grants
         SET last_used_at = UTC_TIMESTAMP(6)
         WHERE id = ? AND revoked_at IS NULL
           AND expires_at > UTC_TIMESTAMP(6)
           AND (last_used_at IS NULL OR
                last_used_at < UTC_TIMESTAMP(6) - INTERVAL 1 HOUR)',
    );
    $stmt->execute([$grantId]);
    if ($stmt->rowCount() === 1) {
        admin_support_model_event($grantId, null, 'delegate', 'used');
    }
}

/**
 * Persist the expiry audit event exactly once, with no background worker.
 *
 * @param int $grantId Grant identifier from a previously validated session.
 * @return void Marks a lapsed grant and emits its expiry event at most once.
 */
function admin_support_model_mark_expired(int $grantId): void
{
    $stmt = db()->prepare(
        'UPDATE admin_support_grants
         SET expiry_logged_at = UTC_TIMESTAMP(6)
         WHERE id = ? AND expiry_logged_at IS NULL
           AND revoked_at IS NULL
           AND expires_at <= UTC_TIMESTAMP(6)',
    );
    $stmt->execute([$grantId]);
    if ($stmt->rowCount() === 1) {
        admin_support_model_event($grantId, null, 'system', 'expired');
    }
}

/**
 * Remove a bounded batch of expired grant metadata and its related audit.
 *
 * This owner-request cleanup has no cron dependency. A cascading foreign key
 * removes lifecycle events together with their expired grant.
 *
 * @param int $retentionDays Days to preserve expired/revoked audit metadata.
 * @param int $batchSize Maximum grant rows to delete in this invocation.
 * @return int Count of purged grants, bounded by the requested batch size.
 */
function admin_support_model_prune(
    int $retentionDays,
    int $batchSize,
): int {
    $stmt = db()->prepare(
        'DELETE FROM admin_support_grants
         WHERE expires_at < DATE_SUB(
             UTC_TIMESTAMP(6), INTERVAL ? DAY
         )
         ORDER BY expires_at ASC LIMIT ?',
    );
    $stmt->bindValue(1, $retentionDays, PDO::PARAM_INT);
    $stmt->bindValue(2, $batchSize, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->rowCount();
}
