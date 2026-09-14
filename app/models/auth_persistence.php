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
 */
function auth_persistence_model_prune(?int $userId, string $now): void
{
    if ($userId !== null) {
        $stmt = db()->prepare('DELETE FROM admin_remember_tokens WHERE user_id = ? AND (expires_at < ? OR revoked_at IS NOT NULL)');
        $stmt->execute([$userId, $now]);
        return;
    }

    db()->prepare('DELETE FROM admin_remember_tokens WHERE expires_at < ? OR revoked_at IS NOT NULL')->execute([$now]);
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
 */
function auth_persistence_model_issue_token(int $userId, string $selector, string $tokenHash, string $userAgentHash, string $now, string $expiresAt, int $keepNewest): void
{
    $keepNewest = max(0, min(100, $keepNewest));
    $stmt = db()->prepare('SELECT id FROM admin_remember_tokens WHERE user_id = ? AND revoked_at IS NULL AND expires_at >= ? ORDER BY created_at DESC, id DESC LIMIT 100 OFFSET ' . $keepNewest);
    $stmt->execute([$userId, $now]);
    $oldIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if ($oldIds !== []) {
        $placeholders = implode(',', array_fill(0, count($oldIds), '?'));
        db()->prepare('UPDATE admin_remember_tokens SET revoked_at = ? WHERE id IN (' . $placeholders . ')')
            ->execute(array_merge([$now], $oldIds));
    }

    $stmt = db()->prepare('INSERT INTO admin_remember_tokens (user_id, selector, token_hash, user_agent_hash, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $selector, $tokenHash, $userAgentHash, $now, $expiresAt]);
}

/**
 * Resolve one active remember token joined with its administrator account.
 *
 * @param string $selector Public token selector.
 * @param string $now Current SQL timestamp.
 * @return ?array<string,mixed> Token and user row or null.
 */
function auth_persistence_model_find_active(string $selector, string $now): ?array
{
    $stmt = db()->prepare('SELECT art.*, u.username, u.email, u.role FROM admin_remember_tokens art INNER JOIN users u ON u.id = art.user_id WHERE art.selector = ? AND art.revoked_at IS NULL AND art.expires_at >= ? LIMIT 1');
    $stmt->execute([$selector, $now]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Revoke one remember token by row identifier. */
function auth_persistence_model_revoke_id(int $tokenId, string $now): void
{
    $stmt = db()->prepare('UPDATE admin_remember_tokens SET revoked_at = ? WHERE id = ?');
    $stmt->execute([$now, $tokenId]);
}

/** Mark one remember token as successfully used. */
function auth_persistence_model_touch(int $tokenId, string $now): void
{
    $stmt = db()->prepare('UPDATE admin_remember_tokens SET last_used_at = ? WHERE id = ?');
    $stmt->execute([$now, $tokenId]);
}

/** Revoke the remember token addressed by a public selector. */
function auth_persistence_model_revoke_selector(string $selector, string $now): void
{
    $stmt = db()->prepare('UPDATE admin_remember_tokens SET revoked_at = ? WHERE selector = ?');
    $stmt->execute([$now, $selector]);
}

/** Revoke all currently active remember tokens for one administrator. */
function auth_persistence_model_revoke_user(int $userId, string $now): void
{
    $stmt = db()->prepare('UPDATE admin_remember_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL');
    $stmt->execute([$now, $userId]);
}
