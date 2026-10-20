<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/upload_automation.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns upload-automation API-key persistence and database advisory locks.
 *
 * Responsibilities:
 *   - Insert, list, resolve, revoke, and touch gallery-scoped upload API keys
 *   - Keep raw API keys out of persistence by accepting only precomputed hashes
 *   - Acquire and release gallery-scoped MySQL advisory locks used by upload workers
 *   - Keep upload-automation SQL out of service orchestration
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
 *   - Authorization, token generation, hashing, schema policy, and HTTP concerns remain outside this model.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use function Gallery\Core\db;

/**
 * Insert one active upload-automation token hash and return its database id.
 *
 * @param string $createdAt Timestamp for the created at.
 * @param ?int $createdByUserId Identifier of the created by user.
 * @param int $galleryId Identifier of the gallery.
 * @param string $label Label used by this operation.
 * @param string $tokenHash Precomputed hash for the token.
 * @return int Database identifier or count produced by this operation.
 */
function upload_automation_model_create_token(
    int $galleryId,
    string $tokenHash,
    string $label,
    ?int $createdByUserId,
    string $createdAt,
): int {
    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO gallery_upload_tokens (gallery_id, token_hash, label, active, created_by_user_id, created_at) VALUES (?, ?, ?, 1, ?, ?)',
    );
    $stmt->execute([
        $galleryId,
        $tokenHash,
        $label,
        $createdByUserId,
        $createdAt,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Return active upload-automation token rows for one gallery.
 *
 *
 * @param int $galleryId Identifier of the gallery.
 * @return array<int,array{id:int,gallery_id:int,label:string,active:int,created_at:string,last_used_at:?string,revoked_at:?string}> Token rows.
 */
function upload_automation_model_active_tokens_for_gallery(int $galleryId): array
{
    $stmt = db()->prepare(
        'SELECT id, gallery_id, label, active, created_at, last_used_at, revoked_at FROM gallery_upload_tokens WHERE gallery_id = ? AND active = 1 AND revoked_at IS NULL ORDER BY created_at DESC, id DESC',
    );
    $stmt->execute([$galleryId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Return active upload-automation token rows for the cross-gallery manager.
 *
 *
 * @return array<int,array{id:int,gallery_id:int,label:string,active:int,created_at:string,last_used_at:?string,revoked_at:?string,gallery_title:string,gallery_slug:string,created_by_username:?string,created_by_display_name:?string}> Token rows.
 */
function upload_automation_model_active_tokens_for_manager(): array
{
    // The users table in the base schema has username but no display_name column,
    // so both identity aliases intentionally use username for compatibility.
    $stmt = db()->query(
        'SELECT t.id, t.gallery_id, t.label, t.active, t.created_at, t.last_used_at, t.revoked_at, g.title AS gallery_title, g.slug AS gallery_slug, u.username AS created_by_username, u.username AS created_by_display_name
        FROM gallery_upload_tokens t
        INNER JOIN galleries g ON g.id = t.gallery_id
        LEFT JOIN users u ON u.id = t.created_by_user_id
        WHERE t.active = 1 AND t.revoked_at IS NULL
        ORDER BY g.title ASC, t.created_at DESC, t.id DESC',
    );
    return $stmt ? ($stmt->fetchAll() ?: []) : [];
}

/**
 * Revoke one gallery-scoped upload token.
 *
 * @param int $galleryId Identifier of the gallery.
 * @param string $revokedAt Timestamp for the revoked at.
 * @param int $tokenId Identifier of the token.
 * @return bool True when the requested condition is met or the update affects a row.
 */
function upload_automation_model_revoke_token(
    int $galleryId,
    int $tokenId,
    string $revokedAt,
): bool {
    $stmt = db()->prepare(
        'UPDATE gallery_upload_tokens SET active = 0, revoked_at = ? WHERE id = ? AND gallery_id = ?',
    );
    $stmt->execute([$revokedAt, $tokenId, $galleryId]);
    return $stmt->rowCount() > 0;
}

/**
 * Resolve one active upload token by its precomputed hash.
 *
 *
 * @param string $tokenHash Precomputed hash for the token.
 * @return array<string,int|string|null>|null Token row with id, gallery_id, label, active, created_at, and last_used_at fields, or null when absent.
 */
function upload_automation_model_find_active_token_by_hash(string $tokenHash): ?array
{
    $stmt = db()->prepare(
        'SELECT id, gallery_id, label, active, created_at, last_used_at FROM gallery_upload_tokens WHERE token_hash = ? AND active = 1 AND revoked_at IS NULL LIMIT 1',
    );
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Record the most recent successful token use time.
 *
 * @param int $tokenId Identifier of the token.
 * @param string $usedAt Timestamp for the used at.
 * @return void No value; performs the documented operation.
 */
function upload_automation_model_mark_token_used(
    int $tokenId,
    string $usedAt,
): void {
    $stmt = db()->prepare(
        'UPDATE gallery_upload_tokens SET last_used_at = ? WHERE id = ?',
    );
    $stmt->execute([$usedAt, $tokenId]);
}

/**
 * Attempt to acquire one gallery-scoped MySQL advisory lock.
 *
 * @param int $galleryId Identifier of the gallery.
 * @param int $timeoutSeconds Timeout seconds used by this operation.
 * @return bool True when the requested condition is met or the update affects a row.
 */
function upload_automation_model_acquire_gallery_lock(
    int $galleryId,
    int $timeoutSeconds = 10,
): bool {
    $lockName = 'php_gallery_upload_automation_' . $galleryId;
    $stmt = db()->prepare('SELECT GET_LOCK(?, ?)');
    $stmt->execute([$lockName, max(0, $timeoutSeconds)]);
    return (int) $stmt->fetchColumn() === 1;
}

/**
 * Release one gallery-scoped MySQL advisory lock.
 */
function upload_automation_model_release_gallery_lock(int $galleryId): void
{
    $lockName = 'php_gallery_upload_automation_' . $galleryId;
    $stmt = db()->prepare('SELECT RELEASE_LOCK(?)');
    $stmt->execute([$lockName]);
}
