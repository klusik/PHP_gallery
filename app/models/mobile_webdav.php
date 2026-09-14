<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/mobile_webdav.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns durable Mobile WebDAV credential persistence.
 *
 * Responsibilities:
 *   - List gallery-scoped mobile upload credentials for Admin views\n *   - Create, revoke, resolve, and touch credential rows\n *   - Keep credential SQL and PDO operations out of WebDAV service orchestration\n *
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
 *   - HTTP transport, authorization policy, and filesystem workflows remain outside this model.
 */

declare(strict_types=1);

namespace Gallery\Models;

use function Gallery\Core\db;

/** @return array<int,array<string,mixed>> */
function mobile_webdav_model_tokens(): array
{
    $stmt = db()->query("SELECT t.*, g.title AS gallery_title, g.folder_path AS gallery_folder_path
        FROM mobile_webdav_upload_tokens t
        INNER JOIN galleries g ON g.id = t.gallery_id
        ORDER BY t.created_at DESC, t.id DESC");
    return $stmt ? ($stmt->fetchAll() ?: []) : [];
}

/**
 * Create one WebDAV upload token.
 *
 * @param int $userId Admin user identifier.
 * @param int $galleryId Gallery identifier.
 * @param string $label Human-readable token label.
 * @param string $username WebDAV username.
 * @param string $passwordHash Password hash stored for authentication.
 * @param string $pathToken Opaque URL path token.
 * @param string $now Current SQL timestamp.
 * @return int Created token identifier.
 */
function mobile_webdav_model_create_token(int $userId, int $galleryId, string $label, string $username, string $passwordHash, string $pathToken, string $now): int
{
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO mobile_webdav_upload_tokens (user_id, gallery_id, label, username, password_hash, path_token, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)');
    $stmt->execute([$userId, $galleryId, $label, $username, $passwordHash, $pathToken, $now, $now]);
    return (int) $pdo->lastInsertId();
}

/**
 * Delete one WebDAV upload token.
 *
 * @param int $tokenId WebDAV token identifier.
 */
function mobile_webdav_model_delete_token(int $tokenId): void
{
    $stmt = db()->prepare('DELETE FROM mobile_webdav_upload_tokens WHERE id = ?');
    $stmt->execute([$tokenId]);
}

/** @return array<string,mixed>|null */
function mobile_webdav_model_find_active_by_path_token(string $pathToken): ?array
{
    $stmt = db()->prepare("SELECT t.*, g.folder_path, g.title AS gallery_title
        FROM mobile_webdav_upload_tokens t
        INNER JOIN galleries g ON g.id = t.gallery_id
        WHERE t.path_token = ? AND t.enabled = 1
        LIMIT 1");
    $stmt->execute([$pathToken]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Mark one WebDAV upload token as used.
 *
 * @param int $tokenId WebDAV token identifier.
 * @param string $now Current SQL timestamp.
 */
function mobile_webdav_model_mark_used(int $tokenId, string $now): void
{
    $stmt = db()->prepare('UPDATE mobile_webdav_upload_tokens SET last_used_at = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$now, $now, $tokenId]);
}
