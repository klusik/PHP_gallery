<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/viewer_collection_shares.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Provides the Phase 3.0 unlisted read-only viewer collection sharing boundary.
 *
 * Responsibilities:
 *   - Reuse the dormant viewer_collection_share_tokens storage without storing plaintext secrets
 *   - Enforce one active owner-created share per collection through account/collection row locking
 *   - Create, replace, revoke, expire, and exchange read-only collection bearer capabilities
 *   - Exchange raw bearer tokens into a bounded collection-only PHP-session grant namespace
 *   - Revalidate every clean shared-collection request against current durable share/account state
 *   - Keep collection-container authority separate from viewer identity, administrator identity, and gallery/media access
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - A collection share never grants source image or source gallery authorization.
 *   - The raw bearer token exists only in the immediate creation/delivery path and is never persisted.
 *   - Shared collection rendering must still use viewer_source_images_resolve_authorized() in recipient context.
 *
 * Last Updated:
 *   2026-08-19
 */

declare(strict_types=1);

namespace Gallery\Services;

use PDO;
use Throwable;
use function Gallery\Core\db;
use function Gallery\Core\now_sql;

const VIEWER_COLLECTION_SHARE_SESSION_NAMESPACE = 'viewer_collection_share_grants';
const VIEWER_COLLECTION_SHARE_LIFETIME_DAYS = 30;
const VIEWER_COLLECTION_SHARE_SESSION_MAX_GRANTS = 16;

/**
 * Return the three-state schema capability required only by Phase 3 collection sharing.
 *
 * Private collections deliberately do not depend on this capability.
 *
 * @return array Aggregate schema inspection result.
 */
function viewer_collection_shares_schema_status(): array
{
    return schema_inspection_feature('viewer.collection_shares', [
        schema_inspection_table('viewer_accounts'),
        schema_inspection_table('viewer_collections'),
        schema_inspection_table('viewer_collection_items'),
        schema_inspection_table('viewer_collection_share_tokens'),
        schema_inspection_table('images'),
        schema_inspection_table('galleries'),
    ]);
}

/**
 * Return true only when Phase 3 share storage and the effective viewer domain are available.
 */
function viewer_collection_shares_storage_available(): bool
{
    return viewer_accounts_enabled()
        && schema_inspection_is_available(viewer_collection_shares_schema_status());
}

/**
 * Return the fixed Phase 3.0 collection-share lifetime in seconds.
 */
function viewer_collection_share_lifetime_seconds(): int
{
    return VIEWER_COLLECTION_SHARE_LIFETIME_DAYS * 86400;
}

/**
 * Validate the canonical 32-byte base64url token syntax before any database lookup.
 *
 * security_opaque_token_generate(32) always produces 43 unpadded base64url characters.
 */
function viewer_collection_share_token_syntax_valid(string $token): bool
{
    return strlen($token) === 43 && preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) === 1;
}

/**
 * Return the isolated PHP-session namespace used only for collection-container grants.
 */
function viewer_collection_share_session_namespace_key(): string
{
    return VIEWER_COLLECTION_SHARE_SESSION_NAMESPACE;
}

/**
 * Return true when a database DATETIME is a strictly future Phase 3 expiry.
 */
function viewer_collection_share_expiry_active(?string $expiresAt): bool
{
    if (!is_string($expiresAt) || $expiresAt === '') {
        return false;
    }
    $timestamp = strtotime($expiresAt);
    return $timestamp !== false && $timestamp > time();
}

/**
 * Record one share event without allowing diagnostics to change the authority result.
 */
function viewer_collection_share_security_event_best_effort(
    string $eventKey,
    ?int $viewerAccountId,
    string $outcome,
    int $collectionId,
    int $shareId = 0
): void {
    try {
        $context = ['collection_id' => $collectionId];
        if ($shareId > 0) {
            $context['share_id'] = $shareId;
        }
        viewer_security_event_record($eventKey, $viewerAccountId, $outcome, $context);
    } catch (Throwable) {
        // Security-event storage must never create, preserve, or revoke collection authority.
    }
}

/**
 * Return the current active share state for one collection owned by one viewer.
 *
 * No plaintext token can be returned because no plaintext token exists in storage.
 *
 * @return ?array{id:int,collection_id:int,created_at:string,expires_at:string,state:string}
 */
function viewer_collection_share_state(int $viewerAccountId, int $collectionId): ?array
{
    if ($viewerAccountId <= 0 || $collectionId <= 0 || !viewer_collection_shares_storage_available()) {
        return null;
    }

    try {
        $stmt = db()->prepare(
            'SELECT vcs.id, vcs.viewer_collection_id, vcs.created_at, vcs.expires_at '
            . 'FROM viewer_collection_share_tokens vcs '
            . 'INNER JOIN viewer_collections vc ON vc.id = vcs.viewer_collection_id '
            . 'WHERE vcs.viewer_collection_id = ? AND vc.viewer_account_id = ? '
            . 'AND vcs.created_by_viewer_account_id = vc.viewer_account_id '
            . 'AND vcs.revoked_at IS NULL AND vcs.expires_at IS NOT NULL AND vcs.expires_at > ? '
            . 'ORDER BY vcs.id DESC LIMIT 1'
        );
        $stmt->execute([$collectionId, $viewerAccountId, now_sql()]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'id' => (int) ($row['id'] ?? 0),
            'collection_id' => (int) ($row['viewer_collection_id'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'expires_at' => (string) ($row['expires_at'] ?? ''),
            'state' => 'active',
        ];
    } catch (Throwable) {
        return null;
    }
}

/**
 * Create or atomically replace the one active share for an owned collection.
 *
 * Lock order is viewer account -> collection -> share rows. The collection lock serializes all
 * Phase 3 creation/replacement attempts for this collection even though the dormant schema has
 * no partial unique index for active rows.
 *
 * @param array $viewer Current viewer principal returned by current_viewer().
 * @param int $collectionId Owned collection identifier.
 * @return array{ok:bool,changed:bool,reason:string,token:string,share_id:int,expires_at:string,retry_after_seconds:int,replaced:bool}
 */
function viewer_collection_share_replace(array $viewer, int $collectionId): array
{
    $viewerAccountId = (int) ($viewer['id'] ?? 0);
    $expectedSecurityVersion = (int) ($viewer['security_version'] ?? 0);
    $empty = [
        'ok' => false,
        'changed' => false,
        'reason' => 'invalid',
        'token' => '',
        'share_id' => 0,
        'expires_at' => '',
        'retry_after_seconds' => 0,
        'replaced' => false,
    ];
    if ($viewerAccountId <= 0 || $expectedSecurityVersion <= 0 || $collectionId <= 0) {
        return $empty;
    }
    if (!viewer_collection_shares_storage_available()) {
        $empty['reason'] = 'unavailable';
        return $empty;
    }

    try {
        $rate = viewer_rate_limit_consume('viewer_share_create_account', 'account', (string) $viewerAccountId);
    } catch (Throwable) {
        $empty['reason'] = 'unavailable';
        return $empty;
    }
    if (empty($rate['allowed'])) {
        $empty['reason'] = (string) ($rate['reason'] ?? '') === 'storage_unavailable' ? 'unavailable' : 'rate_limited';
        $empty['retry_after_seconds'] = max(0, (int) ($rate['retry_after_seconds'] ?? 0));
        return $empty;
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        if (viewer_collection_lock_mutation_account($pdo, $viewer) === null) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $empty['reason'] = 'account_unavailable';
            return $empty;
        }
        if (viewer_collection_lock_owned($pdo, $viewerAccountId, $collectionId) === null) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $empty['reason'] = 'not_found';
            return $empty;
        }

        $shareRows = $pdo->prepare(
            'SELECT id FROM viewer_collection_share_tokens '
            . 'WHERE viewer_collection_id = ? AND revoked_at IS NULL ORDER BY id ASC FOR UPDATE'
        );
        $shareRows->execute([$collectionId]);
        $previousIds = array_map('intval', $shareRows->fetchAll(PDO::FETCH_COLUMN));
        $replaced = $previousIds !== [];
        $now = now_sql();
        if ($replaced) {
            $revoke = $pdo->prepare(
                'UPDATE viewer_collection_share_tokens SET revoked_at = ? '
                . 'WHERE viewer_collection_id = ? AND revoked_at IS NULL'
            );
            $revoke->execute([$now, $collectionId]);
        }

        $token = security_opaque_token_generate(32);
        $tokenHash = security_authority_token_hash($token);
        $expiresAt = date('Y-m-d H:i:s', time() + viewer_collection_share_lifetime_seconds());
        $insert = $pdo->prepare(
            'INSERT INTO viewer_collection_share_tokens '
            . '(viewer_collection_id, created_by_viewer_account_id, token_hash, created_at, expires_at) '
            . 'VALUES (?, ?, ?, ?, ?)'
        );
        $insert->execute([$collectionId, $viewerAccountId, $tokenHash, $now, $expiresAt]);
        $shareId = (int) $pdo->lastInsertId();
        if ($shareId <= 0) {
            throw new \RuntimeException('Viewer collection share insert did not return an identifier.');
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
        viewer_collection_share_security_event_best_effort(
            $replaced ? 'viewer.collection_share_replaced' : 'viewer.collection_share_created',
            $viewerAccountId,
            'success',
            $collectionId,
            $shareId
        );
        return [
            'ok' => true,
            'changed' => true,
            'reason' => 'ok',
            'token' => $token,
            'share_id' => $shareId,
            'expires_at' => $expiresAt,
            'retry_after_seconds' => 0,
            'replaced' => $replaced,
        ];
    } catch (Throwable) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $empty['reason'] = 'unavailable';
        return $empty;
    }
}

/**
 * Revoke every currently unrevoked share row for one owned collection.
 *
 * Revocation is intentionally not rate-limited because removing authority must remain easy.
 *
 * @param array $viewer Current viewer principal.
 * @param int $collectionId Owned collection identifier.
 * @return array{ok:bool,changed:bool,reason:string}
 */
function viewer_collection_share_revoke(array $viewer, int $collectionId): array
{
    $viewerAccountId = (int) ($viewer['id'] ?? 0);
    if ($viewerAccountId <= 0 || $collectionId <= 0) {
        return ['ok' => false, 'changed' => false, 'reason' => 'invalid'];
    }
    if (!viewer_collection_shares_storage_available()) {
        return ['ok' => false, 'changed' => false, 'reason' => 'unavailable'];
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        if (viewer_collection_lock_mutation_account($pdo, $viewer) === null) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'changed' => false, 'reason' => 'account_unavailable'];
        }
        if (viewer_collection_lock_owned($pdo, $viewerAccountId, $collectionId) === null) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'changed' => false, 'reason' => 'not_found'];
        }

        $shares = $pdo->prepare(
            'SELECT id FROM viewer_collection_share_tokens '
            . 'WHERE viewer_collection_id = ? AND revoked_at IS NULL ORDER BY id ASC FOR UPDATE'
        );
        $shares->execute([$collectionId]);
        $shareIds = array_map('intval', $shares->fetchAll(PDO::FETCH_COLUMN));
        $changed = $shareIds !== [];
        if ($changed) {
            $revoke = $pdo->prepare(
                'UPDATE viewer_collection_share_tokens SET revoked_at = ? '
                . 'WHERE viewer_collection_id = ? AND revoked_at IS NULL'
            );
            $revoke->execute([now_sql(), $collectionId]);
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }
        if ($changed) {
            viewer_collection_share_security_event_best_effort(
                'viewer.collection_share_revoked',
                $viewerAccountId,
                'success',
                $collectionId,
                (int) end($shareIds)
            );
        }
        return ['ok' => true, 'changed' => $changed, 'reason' => 'ok'];
    } catch (Throwable) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'changed' => false, 'reason' => 'unavailable'];
    }
}

/**
 * Normalize and bound the current collection-share session grants.
 *
 * Expired/malformed entries are removed. Duplicate share/collection entries collapse to the
 * newest grant, then only the newest VIEWER_COLLECTION_SHARE_SESSION_MAX_GRANTS entries remain.
 *
 * @return array<int,array{share_id:int,collection_id:int,expires_at:int,granted_at:int}>
 */
function viewer_collection_share_session_grants_prune(): array
{
    $raw = $_SESSION[viewer_collection_share_session_namespace_key()] ?? [];
    if (!is_array($raw)) {
        $raw = [];
    }
    $now = time();
    $deduped = [];
    foreach ($raw as $grant) {
        if (!is_array($grant)) {
            continue;
        }
        $shareId = (int) ($grant['share_id'] ?? 0);
        $collectionId = (int) ($grant['collection_id'] ?? 0);
        $expiresAt = (int) ($grant['expires_at'] ?? 0);
        $grantedAt = (int) ($grant['granted_at'] ?? 0);
        if ($shareId <= 0 || $collectionId <= 0 || $expiresAt <= $now || $grantedAt <= 0) {
            continue;
        }
        $key = $shareId . ':' . $collectionId;
        if (!isset($deduped[$key]) || $grantedAt >= $deduped[$key]['granted_at']) {
            $deduped[$key] = [
                'share_id' => $shareId,
                'collection_id' => $collectionId,
                'expires_at' => $expiresAt,
                'granted_at' => $grantedAt,
            ];
        }
    }
    $grants = array_values($deduped);
    usort($grants, static fn (array $left, array $right): int => $left['granted_at'] <=> $right['granted_at']);
    if (count($grants) > VIEWER_COLLECTION_SHARE_SESSION_MAX_GRANTS) {
        $grants = array_slice($grants, -VIEWER_COLLECTION_SHARE_SESSION_MAX_GRANTS);
    }
    $_SESSION[viewer_collection_share_session_namespace_key()] = $grants;
    return $grants;
}

/**
 * Store one new narrow collection grant without retaining the raw bearer token.
 */
function viewer_collection_share_session_grant_store(int $shareId, int $collectionId, string $expiresAt): bool
{
    $expiryTimestamp = strtotime($expiresAt);
    if ($shareId <= 0 || $collectionId <= 0 || $expiryTimestamp === false || $expiryTimestamp <= time()) {
        return false;
    }
    $grants = viewer_collection_share_session_grants_prune();
    $filtered = [];
    foreach ($grants as $grant) {
        if ((int) $grant['share_id'] === $shareId || (int) $grant['collection_id'] === $collectionId) {
            continue;
        }
        $filtered[] = $grant;
    }
    $filtered[] = [
        'share_id' => $shareId,
        'collection_id' => $collectionId,
        'expires_at' => $expiryTimestamp,
        'granted_at' => time(),
    ];
    if (count($filtered) > VIEWER_COLLECTION_SHARE_SESSION_MAX_GRANTS) {
        $filtered = array_slice($filtered, -VIEWER_COLLECTION_SHARE_SESSION_MAX_GRANTS);
    }
    $_SESSION[viewer_collection_share_session_namespace_key()] = array_values($filtered);
    return true;
}

/**
 * Remove one session grant reference without touching viewer/Admin/gallery session namespaces.
 */
function viewer_collection_share_session_grant_remove(int $shareId, int $collectionId): void
{
    $grants = viewer_collection_share_session_grants_prune();
    $_SESSION[viewer_collection_share_session_namespace_key()] = array_values(array_filter(
        $grants,
        static fn (array $grant): bool => !(
            (int) $grant['share_id'] === $shareId && (int) $grant['collection_id'] === $collectionId
        )
    ));
}

/**
 * Exchange one valid reusable raw bearer token into a bounded session collection grant.
 *
 * The preliminary token-hash lookup is non-authoritative and exists only to discover lock ids.
 * The transaction then revalidates in account -> collection -> share order before authority is
 * established. A scanner GET therefore creates only its own session grant and never consumes the link.
 *
 * @return ?array{share_id:int,collection_id:int,owner_viewer_account_id:int,expires_at:string}
 */
function viewer_collection_share_exchange(string $token): ?array
{
    if (!viewer_collection_share_token_syntax_valid($token)) {
        return null;
    }
    if (!viewer_collection_shares_storage_available()) {
        return null;
    }
    $tokenHash = security_authority_token_hash($token);
    $pdo = db();

    try {
        $lookup = $pdo->prepare(
            'SELECT id, viewer_collection_id, created_by_viewer_account_id '
            . 'FROM viewer_collection_share_tokens WHERE token_hash = ? LIMIT 1'
        );
        $lookup->execute([$tokenHash]);
        $candidate = $lookup->fetch();
        if (!$candidate) {
            return null;
        }
        $shareId = (int) ($candidate['id'] ?? 0);
        $collectionId = (int) ($candidate['viewer_collection_id'] ?? 0);
        $ownerId = (int) ($candidate['created_by_viewer_account_id'] ?? 0);
        if ($shareId <= 0 || $collectionId <= 0 || $ownerId <= 0) {
            return null;
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $accountStmt = $pdo->prepare(
                'SELECT id, email, normalized_email, password_hash, must_change_password, status, security_version, email_verified_at '
                . 'FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE'
            );
            $accountStmt->execute([$ownerId]);
            $account = $accountStmt->fetch();
            if (!$account || !viewer_account_can_authenticate($account)) {
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return null;
            }

            $collectionStmt = $pdo->prepare(
                'SELECT id, viewer_account_id FROM viewer_collections '
                . 'WHERE id = ? AND viewer_account_id = ? LIMIT 1 FOR UPDATE'
            );
            $collectionStmt->execute([$collectionId, $ownerId]);
            if (!$collectionStmt->fetch()) {
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return null;
            }

            $shareStmt = $pdo->prepare(
                'SELECT id, viewer_collection_id, created_by_viewer_account_id, created_at, expires_at, revoked_at '
                . 'FROM viewer_collection_share_tokens '
                . 'WHERE id = ? AND viewer_collection_id = ? AND created_by_viewer_account_id = ? AND token_hash = ? '
                . 'LIMIT 1 FOR UPDATE'
            );
            $shareStmt->execute([$shareId, $collectionId, $ownerId, $tokenHash]);
            $share = $shareStmt->fetch();
            if (!$share || !empty($share['revoked_at']) || !viewer_collection_share_expiry_active((string) ($share['expires_at'] ?? ''))) {
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return null;
            }
            $expiresAt = (string) $share['expires_at'];
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        if (!session_regenerate_id(true)) {
            return null;
        }
        if (!viewer_collection_share_session_grant_store($shareId, $collectionId, $expiresAt)) {
            return null;
        }

        try {
            $lastUsed = $pdo->prepare(
                'UPDATE viewer_collection_share_tokens SET last_used_at = ? '
                . 'WHERE id = ? AND revoked_at IS NULL AND expires_at IS NOT NULL AND expires_at > ?'
            );
            $now = now_sql();
            $lastUsed->execute([$now, $shareId, $now]);
        } catch (Throwable) {
            // last_used_at is operational telemetry and is not authorization state.
        }
        viewer_collection_share_security_event_best_effort(
            'viewer.collection_share_exchanged',
            $ownerId,
            'success',
            $collectionId,
            $shareId
        );
        return [
            'share_id' => $shareId,
            'collection_id' => $collectionId,
            'owner_viewer_account_id' => $ownerId,
            'expires_at' => $expiresAt,
        ];
    } catch (Throwable) {
        return null;
    }
}

/**
 * Revalidate one matching session collection grant against authoritative durable share state.
 *
 * This query deliberately checks the current collection owner and current active account state.
 * It never sets current_viewer(), current_user(), a gallery password grant, or a gallery share grant.
 *
 * @return ?array{share_id:int,collection_id:int,owner_viewer_account_id:int,created_at:string,expires_at:string}
 */
function viewer_collection_share_session_authorize(int $collectionId): ?array
{
    if ($collectionId <= 0 || !viewer_collection_shares_storage_available()) {
        return null;
    }
    $grants = viewer_collection_share_session_grants_prune();
    $grant = null;
    foreach ($grants as $candidate) {
        if ((int) $candidate['collection_id'] === $collectionId) {
            $grant = $candidate;
            break;
        }
    }
    if ($grant === null) {
        return null;
    }

    $shareId = (int) $grant['share_id'];
    try {
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
        if (!$row
            || !empty($row['revoked_at'])
            || !viewer_collection_share_expiry_active((string) ($row['expires_at'] ?? ''))
            || !viewer_account_can_authenticate($row)) {
            viewer_collection_share_session_grant_remove($shareId, $collectionId);
            return null;
        }
        return [
            'share_id' => (int) $row['id'],
            'collection_id' => (int) $row['viewer_collection_id'],
            'owner_viewer_account_id' => (int) $row['viewer_account_id'],
            'created_at' => (string) ($row['created_at'] ?? ''),
            'expires_at' => (string) ($row['expires_at'] ?? ''),
        ];
    } catch (Throwable) {
        viewer_collection_share_session_grant_remove($shareId, $collectionId);
        return null;
    }
}

/**
 * Return the live shared collection container and ordered image references after grant revalidation.
 *
 * No image/gallery metadata is returned here. The caller must resolve every reference through
 * viewer_source_images_resolve_authorized() in the recipient request context before rendering.
 *
 * @return ?array{collection:array{id:int,title:string,created_at:string,updated_at:string},references:array<int,array{image_id:int,position:int,created_at:string}>}
 */
function viewer_collection_shared_read(int $collectionId): ?array
{
    if (viewer_collection_share_session_authorize($collectionId) === null) {
        return null;
    }

    try {
        $collectionStmt = db()->prepare(
            'SELECT id, title, created_at, updated_at FROM viewer_collections WHERE id = ? LIMIT 1'
        );
        $collectionStmt->execute([$collectionId]);
        $collection = $collectionStmt->fetch();
        if (!$collection) {
            return null;
        }

        $limit = max(1, (int) viewer_content_quota_config()['max_viewer_items_per_collection']);
        $itemsStmt = db()->prepare(
            'SELECT image_id, position, created_at FROM viewer_collection_items '
            . 'WHERE viewer_collection_id = ? ORDER BY position ASC, image_id ASC LIMIT ' . $limit
        );
        $itemsStmt->execute([$collectionId]);
        $references = [];
        foreach ($itemsStmt->fetchAll() as $row) {
            $references[] = [
                'image_id' => (int) ($row['image_id'] ?? 0),
                'position' => (int) ($row['position'] ?? 0),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }
        return [
            'collection' => [
                'id' => (int) ($collection['id'] ?? 0),
                'title' => (string) ($collection['title'] ?? ''),
                'created_at' => (string) ($collection['created_at'] ?? ''),
                'updated_at' => (string) ($collection['updated_at'] ?? ''),
            ],
            'references' => $references,
        ];
    } catch (Throwable) {
        return null;
    }
}
