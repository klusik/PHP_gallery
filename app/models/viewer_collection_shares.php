<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/viewer_collection_shares.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns durable viewer collection-share persistence.
 *
 * Responsibilities:
 *   - Read and lock hashed collection-share capability rows
 *   - Replace and revoke active share rows under collection locks
 *   - Revalidate session grants against durable owner/share state
 *   - Read shared collection containers and ordered image references
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Plaintext tokens, account authorization policy, PHP session state, and source-image authorization remain outside this model.
 *
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Models;

use RuntimeException;
use function Gallery\Core\db;

/** Return the current active share state for one owned collection. */
function viewer_collection_share_model_state(int $viewerAccountId, int $collectionId, string $now): ?array
{
    $stmt = db()->prepare(
        'SELECT vcs.id, vcs.viewer_collection_id, vcs.created_at, vcs.expires_at '
        . 'FROM viewer_collection_share_tokens vcs '
        . 'INNER JOIN viewer_collections vc ON vc.id = vcs.viewer_collection_id '
        . 'WHERE vcs.viewer_collection_id = ? AND vc.viewer_account_id = ? '
        . 'AND vcs.created_by_viewer_account_id = vc.viewer_account_id '
        . 'AND vcs.revoked_at IS NULL AND vcs.expires_at IS NOT NULL AND vcs.expires_at > ? '
        . 'ORDER BY vcs.id DESC LIMIT 1'
    );
    $stmt->execute([$collectionId, $viewerAccountId, $now]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Lock all currently unrevoked share ids for one collection. */
function viewer_collection_share_model_active_ids_lock(int $collectionId): array
{
    $stmt = db()->prepare(
        'SELECT id FROM viewer_collection_share_tokens '
        . 'WHERE viewer_collection_id = ? AND revoked_at IS NULL ORDER BY id ASC FOR UPDATE'
    );
    $stmt->execute([$collectionId]);
    return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
}

/** Revoke all currently active shares for one collection. */
function viewer_collection_share_model_revoke_active(int $collectionId, string $now): int
{
    $stmt = db()->prepare(
        'UPDATE viewer_collection_share_tokens SET revoked_at = ? '
        . 'WHERE viewer_collection_id = ? AND revoked_at IS NULL'
    );
    $stmt->execute([$now, $collectionId]);
    return $stmt->rowCount();
}

/** Insert one hashed collection-share capability and return its id. */
function viewer_collection_share_model_insert(int $collectionId, int $viewerAccountId, string $tokenHash, string $now, string $expiresAt): int
{
    $stmt = db()->prepare(
        'INSERT INTO viewer_collection_share_tokens '
        . '(viewer_collection_id, created_by_viewer_account_id, token_hash, created_at, expires_at) '
        . 'VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$collectionId, $viewerAccountId, $tokenHash, $now, $expiresAt]);
    $shareId = (int) db()->lastInsertId();
    if ($shareId <= 0) {
        throw new RuntimeException('Viewer collection share insert did not return an identifier.');
    }
    return $shareId;
}

/** Return preliminary lock identifiers for one hashed share token. */
function viewer_collection_share_model_candidate(string $tokenHash): ?array
{
    $stmt = db()->prepare(
        'SELECT id, viewer_collection_id, created_by_viewer_account_id '
        . 'FROM viewer_collection_share_tokens WHERE token_hash = ? LIMIT 1'
    );
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Lock and return one exact share capability. */
function viewer_collection_share_model_lock(int $shareId, int $collectionId, int $ownerId, string $tokenHash): ?array
{
    $stmt = db()->prepare(
        'SELECT id, viewer_collection_id, created_by_viewer_account_id, created_at, expires_at, revoked_at '
        . 'FROM viewer_collection_share_tokens '
        . 'WHERE id = ? AND viewer_collection_id = ? AND created_by_viewer_account_id = ? AND token_hash = ? '
        . 'LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([$shareId, $collectionId, $ownerId, $tokenHash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Best-effort update of operational share usage metadata. */
function viewer_collection_share_model_touch_last_used(int $shareId, string $now): void
{
    $stmt = db()->prepare(
        'UPDATE viewer_collection_share_tokens SET last_used_at = ? '
        . 'WHERE id = ? AND revoked_at IS NULL AND expires_at IS NOT NULL AND expires_at > ?'
    );
    $stmt->execute([$now, $shareId, $now]);
}

/** Return one authoritative share/account/collection row for a session grant. */
function viewer_collection_share_model_authorization_row(int $shareId, int $collectionId): ?array
{
    $stmt = db()->prepare(
        'SELECT vcs.id, vcs.viewer_collection_id, vcs.created_by_viewer_account_id, vcs.created_at, vcs.expires_at, vcs.revoked_at, '
        . 'vc.viewer_account_id, va.password_hash, va.status, va.email_verified_at '
        . 'FROM viewer_collection_share_tokens vcs '
        . 'INNER JOIN viewer_collections vc ON vc.id = vcs.viewer_collection_id '
        . 'INNER JOIN viewer_accounts va ON va.id = vc.viewer_account_id '
        . 'WHERE vcs.id = ? AND vcs.viewer_collection_id = ? '
        . 'AND vcs.created_by_viewer_account_id = vc.viewer_account_id LIMIT 1'
    );
    $stmt->execute([$shareId, $collectionId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Return one shared collection container without source-image metadata. */
function viewer_collection_share_model_collection(int $collectionId): ?array
{
    $stmt = db()->prepare('SELECT id, title, created_at, updated_at FROM viewer_collections WHERE id = ? LIMIT 1');
    $stmt->execute([$collectionId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Return bounded ordered image references for one shared collection. */
function viewer_collection_share_model_references(int $collectionId, int $limit): array
{
    $limit = max(1, min(5000, $limit));
    $stmt = db()->prepare(
        'SELECT image_id, position, created_at FROM viewer_collection_items '
        . 'WHERE viewer_collection_id = ? ORDER BY position ASC, image_id ASC LIMIT ' . $limit
    );
    $stmt->execute([$collectionId]);
    return $stmt->fetchAll() ?: [];
}
