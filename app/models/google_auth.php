<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/google_auth.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns persistence for administrator Google account links.
 *
 * Responsibilities:
 *   - Read account links by local user id or Google subject
 *   - Upsert normalized Google identity metadata
 *   - Disconnect links and record successful-login timestamps
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
 *   - OAuth transport, claim verification, and authorization policy remain in services.
 *
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Models;

use function Gallery\Core\db;

/** Return the Google account linked to one local user. */
function google_auth_model_linked_account(int $userId): ?array
{
    $stmt = db()->prepare('SELECT * FROM user_google_accounts WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** Return the local admin account linked to one Google subject. */
function google_auth_model_user_by_subject(string $subject): ?array
{
    $stmt = db()->prepare('SELECT u.id, u.username, u.email, u.role, uga.id AS google_account_id FROM user_google_accounts uga INNER JOIN users u ON u.id = uga.user_id WHERE uga.google_sub = ? LIMIT 1');
    $stmt->execute([$subject]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** Upsert one normalized Google account link. */
function google_auth_model_link_account(int $userId, array $identity, string $now): void
{
    $stmt = db()->prepare('INSERT INTO user_google_accounts (user_id, google_sub, email, email_verified, name, picture_url, linked_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE google_sub = VALUES(google_sub), email = VALUES(email), email_verified = VALUES(email_verified), name = VALUES(name), picture_url = VALUES(picture_url), updated_at = VALUES(updated_at)');
    $stmt->execute([
        $userId,
        (string) ($identity['subject'] ?? ''),
        $identity['email'] ?? null,
        !empty($identity['email_verified']) ? 1 : 0,
        $identity['name'] ?? null,
        $identity['picture_url'] ?? null,
        $now,
        $now,
    ]);
}

/** Delete the Google link for one local user. */
function google_auth_model_disconnect_account(int $userId): void
{
    $stmt = db()->prepare('DELETE FROM user_google_accounts WHERE user_id = ?');
    $stmt->execute([$userId]);
}

/** Record the latest successful login timestamp for one Google account row. */
function google_auth_model_touch_login(int $googleAccountId, string $now): void
{
    $stmt = db()->prepare('UPDATE user_google_accounts SET last_login_at = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$now, $now, $googleAccountId]);
}
