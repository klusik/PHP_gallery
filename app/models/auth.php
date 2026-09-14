<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/auth.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns Admin user and password-reset persistence used by authentication workflows.
 *
 * Responsibilities:
 *   - Read and update Admin account rows without exposing PDO to controllers
 *   - Persist password-reset token lifecycle state
 *   - Preserve legacy optional-email schema behavior through semantic model arguments
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
 *   - Password hashing, token generation, and HTTP/session behavior remain outside the model layer.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use function Gallery\Core\db;

/** @return array<string,mixed>|null */
function auth_model_find_user_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email IS NOT NULL AND LOWER(email) = LOWER(?) LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** @return array<string,mixed>|null */
function auth_model_find_user_by_username(string $username): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Delete expired or already-consumed password-reset tokens.
 *
 * @param string $now Current SQL timestamp.
 */
function auth_model_cleanup_password_reset_tokens(string $now): void
{
    $stmt = db()->prepare('DELETE FROM password_reset_tokens WHERE expires_at < ? OR used_at IS NOT NULL');
    $stmt->execute([$now]);
}

/**
 * Invalidate prior unused reset tokens for one user and insert the replacement token.
 *
 * @param int $userId User identifier.
 * @param string $selector Public token selector.
 * @param string $tokenHash SHA-256 hash of the secret token.
 * @param string $requestedAt Token creation timestamp.
 * @param string $expiresAt Token expiration timestamp.
 * @param string $requestHash Privacy-safe request fingerprint.
 */
function auth_model_replace_password_reset_token(int $userId, string $selector, string $tokenHash, string $requestedAt, string $expiresAt, string $requestHash): void
{
    $stmt = db()->prepare('UPDATE password_reset_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL');
    $stmt->execute([$requestedAt, $userId]);
    $stmt = db()->prepare('INSERT INTO password_reset_tokens (user_id, selector, token_hash, requested_at, expires_at, request_hash) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $selector, $tokenHash, $requestedAt, $expiresAt, $requestHash]);
}

/** @return array<string,mixed>|null */
function auth_model_find_valid_password_reset(string $selector, string $now): ?array
{
    $stmt = db()->prepare('SELECT prt.*, u.username, u.email FROM password_reset_tokens prt INNER JOIN users u ON u.id = prt.user_id WHERE prt.selector = ? AND prt.used_at IS NULL AND prt.expires_at >= ? LIMIT 1');
    $stmt->execute([$selector, $now]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Replace one user's password hash and update timestamp.
 *
 * @param int $userId User identifier.
 * @param string $passwordHash Password hash created by the service layer.
 * @param string $now Current SQL timestamp.
 */
function auth_model_update_user_password(int $userId, string $passwordHash, string $now): void
{
    $stmt = db()->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$passwordHash, $now, $userId]);
}

/**
 * Mark one password-reset token as consumed.
 *
 * @param int $resetTokenId Password-reset token identifier.
 * @param string $now Current SQL timestamp.
 */
function auth_model_mark_password_reset_used(int $resetTokenId, string $now): void
{
    $stmt = db()->prepare('UPDATE password_reset_tokens SET used_at = ? WHERE id = ?');
    $stmt->execute([$now, $resetTokenId]);
}

/**
 * Return the stored password hash for one user.
 *
 * @param int $userId User identifier.
 * @return ?string Stored password hash when available.
 */
function auth_model_user_password_hash(int $userId): ?string
{
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $value = $stmt->fetchColumn();
    return is_string($value) && $value !== '' ? $value : null;
}

/** @return array<string,mixed>|null */
function auth_model_account_row(int $userId, bool $emailSchemaAvailable): ?array
{
    $sql = $emailSchemaAvailable
        ? 'SELECT username, email, password_hash FROM users WHERE id = ?'
        : 'SELECT username, password_hash FROM users WHERE id = ?';
    $stmt = db()->prepare($sql);
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Return whether a username belongs to another user.
 *
 * @param string $username Candidate username.
 * @param int $excludeUserId User identifier excluded from the uniqueness check.
 * @return bool True when another user already owns the username.
 */
function auth_model_username_taken(string $username, int $excludeUserId): bool
{
    $stmt = db()->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
    $stmt->execute([$username, $excludeUserId]);
    return (bool) $stmt->fetch();
}

/**
 * Return whether a normalized email belongs to another user.
 *
 * @param string $email Candidate recovery email.
 * @param int $excludeUserId User identifier excluded from the uniqueness check.
 * @return bool True when another user already owns the email.
 */
function auth_model_email_taken(string $email, int $excludeUserId): bool
{
    $stmt = db()->prepare('SELECT id FROM users WHERE email IS NOT NULL AND LOWER(email) = LOWER(?) AND id <> ?');
    $stmt->execute([$email, $excludeUserId]);
    return (bool) $stmt->fetch();
}

/**
 * Persist editable account fields while respecting the optional email schema.
 *
 * @param int $userId User identifier.
 * @param string $username Normalized username.
 * @param ?string $email Optional normalized recovery email.
 * @param bool $emailSchemaAvailable Whether the installed schema supports email.
 * @param ?string $passwordHash Optional replacement password hash.
 * @param string $now Current SQL timestamp.
 */
function auth_model_update_account(int $userId, string $username, ?string $email, bool $emailSchemaAvailable, ?string $passwordHash, string $now): void
{
    $fields = ['username = ?'];
    $params = [$username];
    if ($emailSchemaAvailable) {
        $fields[] = 'email = ?';
        $params[] = $email;
    }
    $fields[] = 'updated_at = ?';
    $params[] = $now;
    if ($passwordHash !== null) {
        $fields[] = 'password_hash = ?';
        $params[] = $passwordHash;
    }
    $params[] = $userId;
    $stmt = db()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?');
    $stmt->execute($params);
}

/**
 * Create or update the initial Admin account during setup.
 *
 * @param string $username Admin username.
 * @param ?string $email Optional recovery email.
 * @param string $passwordHash Password hash created by the service layer.
 * @param string $now Current SQL timestamp.
 */
function auth_model_upsert_setup_admin(string $username, ?string $email, string $passwordHash, string $now): void
{
    $stmt = db()->prepare('INSERT INTO users (username, email, password_hash, role, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE email = VALUES(email), password_hash = VALUES(password_hash), updated_at = VALUES(updated_at)');
    $stmt->execute([$username, $email, $passwordHash, 'admin', $now, $now]);
}
