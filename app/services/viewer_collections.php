<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/viewer_collections.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Provides the Phase 2.0 private viewer-collection ownership and mutation boundary.
 *
 * Responsibilities:
 *   - Keep collection ownership scoped to the authenticated viewer account
 *   - Store ordered canonical image references without copying source authorization state
 *   - Re-check source authorization before image-reference insertion
 *   - Enforce collection and item quotas under viewer/collection row locks
 *   - Apply owner-scoped rename, delete, remove, and transactional reorder operations
 *   - Keep dormant collection-sharing storage completely outside the Phase 2.0 API
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Viewer authentication is not gallery authorization.
 *   - Viewer collection membership is not image authorization.
 *   - Collection rows never store gallery passwords, share grants, paths, or permission snapshots.
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

namespace Gallery\Services;

use PDO;
use Throwable;
use function Gallery\Core\db;
use function Gallery\Core\now_sql;

/**
 * Return the three-state schema capability required by private viewer collections.
 *
 * @return array Aggregate schema inspection result.
 */
function viewer_collections_schema_status(): array
{
    return schema_inspection_feature('viewer.collections', [
        schema_inspection_table('viewer_accounts'),
        schema_inspection_table('viewer_collections'),
        schema_inspection_table('viewer_collection_items'),
        schema_inspection_table('images'),
        schema_inspection_table('galleries'),
    ]);
}

/**
 * Return true only when the existing Phase 0 private collection schema is verifiably available.
 */
function viewer_collections_storage_available(): bool
{
    return viewer_accounts_enabled()
        && schema_inspection_is_available(viewer_collections_schema_status());
}

/**
 * Prepare and validate one collection title under the existing plain-text foundation policy.
 *
 * Only ordinary ASCII spaces are trimmed at the edges. Control characters remain present so the
 * authoritative validator can reject them rather than silently normalizing them away.
 *
 * @param string $rawTitle Submitted collection title.
 * @return array{valid:bool,title:string,reason:string}
 */
function viewer_collection_title_prepare(string $rawTitle): array
{
    $title = trim($rawTitle, ' ');
    $validation = viewer_collection_title_validate($title);
    return [
        'valid' => !empty($validation['valid']),
        'title' => $title,
        'reason' => (string) ($validation['reason'] ?? 'invalid'),
    ];
}

/**
 * Return all private collections owned by one viewer, newest-updated first.
 *
 * The result contains collection metadata owned by the caller only. Item counts include stored
 * references regardless of current source-image authorization; no image metadata is joined here.
 *
 * @param int $viewerAccountId Authenticated viewer account identifier.
 * @return array<int,array{id:int,title:string,created_at:string,updated_at:string,item_count:int}>
 */
function viewer_collections_for_owner(int $viewerAccountId): array
{
    if ($viewerAccountId <= 0 || !viewer_collections_storage_available()) {
        return [];
    }

    try {
        $limit = max(1, (int) viewer_content_quota_config()['max_viewer_collections_per_account']);
        $stmt = db()->prepare(
            'SELECT vc.id, vc.title, vc.created_at, vc.updated_at, COUNT(vci.image_id) AS item_count '
            . 'FROM viewer_collections vc '
            . 'LEFT JOIN viewer_collection_items vci ON vci.viewer_collection_id = vc.id '
            . 'WHERE vc.viewer_account_id = ? '
            . 'GROUP BY vc.id, vc.title, vc.created_at, vc.updated_at '
            . 'ORDER BY vc.updated_at DESC, vc.id DESC LIMIT ' . $limit
        );
        $stmt->execute([$viewerAccountId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'item_count' => (int) ($row['item_count'] ?? 0),
            ];
        }
        return $rows;
    } catch (Throwable) {
        return [];
    }
}

/**
 * Load one private collection only when it belongs to the supplied viewer account.
 *
 * @param int $viewerAccountId Authenticated viewer account identifier.
 * @param int $collectionId Collection identifier.
 * @return ?array{id:int,title:string,created_at:string,updated_at:string,item_count:int}
 */
function viewer_collection_owned_get(int $viewerAccountId, int $collectionId): ?array
{
    if ($viewerAccountId <= 0 || $collectionId <= 0 || !viewer_collections_storage_available()) {
        return null;
    }

    try {
        $stmt = db()->prepare(
            'SELECT vc.id, vc.title, vc.created_at, vc.updated_at, '
            . '(SELECT COUNT(*) FROM viewer_collection_items vci WHERE vci.viewer_collection_id = vc.id) AS item_count '
            . 'FROM viewer_collections vc WHERE vc.id = ? AND vc.viewer_account_id = ? LIMIT 1'
        );
        $stmt->execute([$collectionId, $viewerAccountId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'id' => (int) ($row['id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'item_count' => (int) ($row['item_count'] ?? 0),
        ];
    } catch (Throwable) {
        return null;
    }
}

/**
 * Return ordered image references for one collection only when the supplied viewer owns it.
 *
 * No image/gallery metadata is loaded here. The HTTP read path must pass every returned image id
 * through the live source-authorization resolver before rendering any source information.
 *
 * @param int $viewerAccountId Authenticated viewer account identifier.
 * @param int $collectionId Collection identifier.
 * @return array<int,array{image_id:int,position:int,created_at:string}>
 */
function viewer_collection_item_references(int $viewerAccountId, int $collectionId): array
{
    if ($viewerAccountId <= 0 || $collectionId <= 0 || !viewer_collections_storage_available()) {
        return [];
    }

    try {
        $limit = max(1, (int) viewer_content_quota_config()['max_viewer_items_per_collection']);
        $stmt = db()->prepare(
            'SELECT vci.image_id, vci.position, vci.created_at '
            . 'FROM viewer_collection_items vci '
            . 'INNER JOIN viewer_collections vc ON vc.id = vci.viewer_collection_id '
            . 'WHERE vci.viewer_collection_id = ? AND vc.viewer_account_id = ? '
            . 'ORDER BY vci.position ASC, vci.image_id ASC LIMIT ' . $limit
        );
        $stmt->execute([$collectionId, $viewerAccountId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'image_id' => (int) ($row['image_id'] ?? 0),
                'position' => (int) ($row['position'] ?? 0),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }
        return $rows;
    } catch (Throwable) {
        return [];
    }
}

/**
 * Lock and revalidate the current viewer account before a collection mutation.
 *
 * @param PDO $pdo Active database handle.
 * @param array $viewer Current viewer principal returned by current_viewer().
 * @return ?array Locked account row, or null when authority changed.
 */
function viewer_collection_lock_mutation_account(PDO $pdo, array $viewer): ?array
{
    $viewerAccountId = (int) ($viewer['id'] ?? 0);
    $expectedSecurityVersion = (int) ($viewer['security_version'] ?? 0);
    if ($viewerAccountId <= 0 || $expectedSecurityVersion <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, email, normalized_email, password_hash, must_change_password, status, security_version, email_verified_at '
        . 'FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([$viewerAccountId]);
    $account = $stmt->fetch();
    if (!$account
        || !viewer_account_can_mutate_content($account)
        || (int) ($account['security_version'] ?? 0) !== $expectedSecurityVersion) {
        return null;
    }
    return $account;
}

/**
 * Lock one collection under an explicit owner predicate.
 *
 * @param PDO $pdo Active database handle.
 * @param int $viewerAccountId Authenticated viewer owner id.
 * @param int $collectionId Collection id.
 * @return ?array Locked collection row.
 */
function viewer_collection_lock_owned(PDO $pdo, int $viewerAccountId, int $collectionId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, viewer_account_id, title, created_at, updated_at '
        . 'FROM viewer_collections WHERE id = ? AND viewer_account_id = ? LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([$collectionId, $viewerAccountId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Normalize one locked collection to dense deterministic integer positions.
 *
 * The caller must already hold the owned collection row lock. At the Phase 2 quota this is a
 * bounded update and prevents repeated remove/add churn from causing unbounded position growth.
 *
 * @param PDO $pdo Active database handle.
 * @param int $collectionId Locked collection identifier.
 * @return int Number of collection items after normalization.
 */
function viewer_collection_normalize_positions(PDO $pdo, int $collectionId): int
{
    $stmt = $pdo->prepare(
        'SELECT image_id, position FROM viewer_collection_items '
        . 'WHERE viewer_collection_id = ? ORDER BY position ASC, image_id ASC FOR UPDATE'
    );
    $stmt->execute([$collectionId]);
    $rows = $stmt->fetchAll();
    $update = null;
    foreach ($rows as $index => $row) {
        $targetPosition = $index + 1;
        if ((int) ($row['position'] ?? 0) === $targetPosition) {
            continue;
        }
        if ($update === null) {
            $update = $pdo->prepare(
                'UPDATE viewer_collection_items SET position = ? WHERE viewer_collection_id = ? AND image_id = ?'
            );
        }
        $update->execute([$targetPosition, $collectionId, (int) ($row['image_id'] ?? 0)]);
        if ($update->rowCount() > 1) {
            throw new \RuntimeException('Viewer collection position normalization affected multiple rows.');
        }
    }
    return count($rows);
}

/**
 * Record one low-risk collection security event without making diagnostics authoritative.
 *
 * @param string $eventKey Stable viewer event key.
 * @param int $viewerAccountId Viewer account identifier.
 * @param string $outcome Outcome category.
 * @param int $collectionId Collection identifier.
 */
function viewer_collection_security_event_best_effort(
    string $eventKey,
    int $viewerAccountId,
    string $outcome,
    int $collectionId
): void {
    try {
        viewer_security_event_record($eventKey, $viewerAccountId, $outcome, [
            'collection_id' => $collectionId,
        ]);
    } catch (Throwable) {
        // Diagnostic storage must never create or revoke collection authority.
    }
}

/**
 * Create one private collection owned by the current viewer principal.
 *
 * Collection-count admission is serialized by the locked viewer-account row. A dedicated
 * account rate limit bounds repeated object creation without affecting reads or existing data.
 *
 * @param array $viewer Current viewer principal returned by current_viewer().
 * @param string $rawTitle Submitted title.
 * @return array{ok:bool,collection_id:int,changed:bool,reason:string,retry_after_seconds:int}
 */
function viewer_collection_create(array $viewer, string $rawTitle): array
{
    $viewerAccountId = (int) ($viewer['id'] ?? 0);
    $expectedSecurityVersion = (int) ($viewer['security_version'] ?? 0);
    if ($viewerAccountId <= 0 || $expectedSecurityVersion <= 0) {
        return ['ok' => false, 'collection_id' => 0, 'changed' => false, 'reason' => 'invalid', 'retry_after_seconds' => 0];
    }
    if (!viewer_collections_storage_available()) {
        return ['ok' => false, 'collection_id' => 0, 'changed' => false, 'reason' => 'unavailable', 'retry_after_seconds' => 0];
    }

    $prepared = viewer_collection_title_prepare($rawTitle);
    if (!$prepared['valid']) {
        return ['ok' => false, 'collection_id' => 0, 'changed' => false, 'reason' => 'invalid_title', 'retry_after_seconds' => 0];
    }

    try {
        $rate = viewer_rate_limit_consume('viewer_collection_create_account', 'account', (string) $viewerAccountId);
    } catch (Throwable) {
        return ['ok' => false, 'collection_id' => 0, 'changed' => false, 'reason' => 'unavailable', 'retry_after_seconds' => 0];
    }
    if (empty($rate['allowed'])) {
        return [
            'ok' => false,
            'collection_id' => 0,
            'changed' => false,
            'reason' => (string) ($rate['reason'] ?? '') === 'storage_unavailable' ? 'unavailable' : 'rate_limited',
            'retry_after_seconds' => max(0, (int) ($rate['retry_after_seconds'] ?? 0)),
        ];
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $account = viewer_collection_lock_mutation_account($pdo, $viewer);
        if ($account === null) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'collection_id' => 0, 'changed' => false, 'reason' => 'account_unavailable', 'retry_after_seconds' => 0];
        }

        $quota = viewer_content_quota_config();
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM viewer_collections WHERE viewer_account_id = ?');
        $countStmt->execute([$viewerAccountId]);
        if ((int) $countStmt->fetchColumn() >= (int) $quota['max_viewer_collections_per_account']) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'collection_id' => 0, 'changed' => false, 'reason' => 'quota', 'retry_after_seconds' => 0];
        }

        $now = now_sql();
        $insert = $pdo->prepare(
            'INSERT INTO viewer_collections (viewer_account_id, title, created_at, updated_at) VALUES (?, ?, ?, ?)'
        );
        $insert->execute([$viewerAccountId, $prepared['title'], $now, $now]);
        $collectionId = (int) $pdo->lastInsertId();
        if ($collectionId <= 0) {
            throw new \RuntimeException('Viewer collection insert did not return an identifier.');
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
        viewer_collection_security_event_best_effort('viewer.collection_created', $viewerAccountId, 'success', $collectionId);
        return ['ok' => true, 'collection_id' => $collectionId, 'changed' => true, 'reason' => 'ok', 'retry_after_seconds' => 0];
    } catch (Throwable) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'collection_id' => 0, 'changed' => false, 'reason' => 'unavailable', 'retry_after_seconds' => 0];
    }
}

/**
 * Rename one collection under current-viewer ownership.
 *
 * @param array $viewer Current viewer principal.
 * @param int $collectionId Collection identifier.
 * @param string $rawTitle Submitted title.
 * @return array{ok:bool,changed:bool,reason:string}
 */
function viewer_collection_rename(array $viewer, int $collectionId, string $rawTitle): array
{
    $viewerAccountId = (int) ($viewer['id'] ?? 0);
    if ($viewerAccountId <= 0 || $collectionId <= 0) {
        return ['ok' => false, 'changed' => false, 'reason' => 'invalid'];
    }
    if (!viewer_collections_storage_available()) {
        return ['ok' => false, 'changed' => false, 'reason' => 'unavailable'];
    }
    $prepared = viewer_collection_title_prepare($rawTitle);
    if (!$prepared['valid']) {
        return ['ok' => false, 'changed' => false, 'reason' => 'invalid_title'];
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
        $collection = viewer_collection_lock_owned($pdo, $viewerAccountId, $collectionId);
        if ($collection === null) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'changed' => false, 'reason' => 'not_found'];
        }

        $changed = (string) ($collection['title'] ?? '') !== $prepared['title'];
        if ($changed) {
            $stmt = $pdo->prepare(
                'UPDATE viewer_collections SET title = ?, updated_at = ? WHERE id = ? AND viewer_account_id = ?'
            );
            $stmt->execute([$prepared['title'], now_sql(), $collectionId, $viewerAccountId]);
            if ($stmt->rowCount() !== 1) {
                throw new \RuntimeException('Viewer collection rename lost ownership.');
            }
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }
        if ($changed) {
            viewer_collection_security_event_best_effort('viewer.collection_renamed', $viewerAccountId, 'success', $collectionId);
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
 * Delete one owned collection and only its dependent collection-owned rows.
 *
 * Canonical images, galleries, favourites, Smart Galleries, and gallery share links are not
 * children of viewer_collections and are never touched by this operation.
 *
 * @param array $viewer Current viewer principal.
 * @param int $collectionId Collection identifier.
 * @return array{ok:bool,changed:bool,reason:string}
 */
function viewer_collection_delete(array $viewer, int $collectionId): array
{
    $viewerAccountId = (int) ($viewer['id'] ?? 0);
    if ($viewerAccountId <= 0 || $collectionId <= 0) {
        return ['ok' => false, 'changed' => false, 'reason' => 'invalid'];
    }
    if (!viewer_collections_storage_available()) {
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

        $delete = $pdo->prepare('DELETE FROM viewer_collections WHERE id = ? AND viewer_account_id = ?');
        $delete->execute([$collectionId, $viewerAccountId]);
        if ($delete->rowCount() !== 1) {
            throw new \RuntimeException('Viewer collection delete lost ownership.');
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }
        viewer_collection_security_event_best_effort('viewer.collection_deleted', $viewerAccountId, 'success', $collectionId);
        return ['ok' => true, 'changed' => true, 'reason' => 'ok'];
    } catch (Throwable) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'changed' => false, 'reason' => 'unavailable'];
    }
}

/**
 * Add one currently authorized source image to one owned collection.
 *
 * The source authorization decision is intentionally evaluated before storing the reference and
 * is not copied into the item row. Collection-row locking serializes duplicate/quota/position
 * decisions for this collection; the composite primary key provides an additional race-safe guard.
 *
 * @param array $viewer Current viewer principal.
 * @param int $collectionId Collection identifier.
 * @param int $imageId Canonical image identifier.
 * @return array{ok:bool,changed:bool,reason:string}
 */
function viewer_collection_item_add(array $viewer, int $collectionId, int $imageId): array
{
    $viewerAccountId = (int) ($viewer['id'] ?? 0);
    if ($viewerAccountId <= 0 || $collectionId <= 0 || $imageId <= 0) {
        return ['ok' => false, 'changed' => false, 'reason' => 'invalid'];
    }
    if (!viewer_collections_storage_available()) {
        return ['ok' => false, 'changed' => false, 'reason' => 'unavailable'];
    }
    if (!viewer_source_image_can_reference($imageId)) {
        return ['ok' => false, 'changed' => false, 'reason' => 'source_forbidden'];
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

        $existsStmt = $pdo->prepare(
            'SELECT 1 FROM viewer_collection_items WHERE viewer_collection_id = ? AND image_id = ? LIMIT 1'
        );
        $existsStmt->execute([$collectionId, $imageId]);
        if ($existsStmt->fetchColumn()) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return ['ok' => true, 'changed' => false, 'reason' => 'already_present'];
        }

        $quota = viewer_content_quota_config();
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM viewer_collection_items WHERE viewer_collection_id = ?');
        $countStmt->execute([$collectionId]);
        $itemCount = (int) $countStmt->fetchColumn();
        if ($itemCount >= (int) $quota['max_viewer_items_per_collection']) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'changed' => false, 'reason' => 'quota'];
        }

        $positionStmt = $pdo->prepare('SELECT COALESCE(MAX(position), 0) FROM viewer_collection_items WHERE viewer_collection_id = ?');
        $positionStmt->execute([$collectionId]);
        $maxPosition = (int) $positionStmt->fetchColumn();
        if ($maxPosition !== $itemCount) {
            $itemCount = viewer_collection_normalize_positions($pdo, $collectionId);
        }
        $position = $itemCount + 1;
        if ($position <= 0 || $position > 4294967295) {
            throw new \RuntimeException('Viewer collection position is out of range.');
        }

        $insert = $pdo->prepare(
            'INSERT INTO viewer_collection_items (viewer_collection_id, image_id, position, created_at) VALUES (?, ?, ?, ?)'
        );
        $insert->execute([$collectionId, $imageId, $position, now_sql()]);
        $pdo->prepare('UPDATE viewer_collections SET updated_at = ? WHERE id = ? AND viewer_account_id = ?')
            ->execute([now_sql(), $collectionId, $viewerAccountId]);

        if ($ownsTransaction) {
            $pdo->commit();
        }
        viewer_collection_security_event_best_effort('viewer.collection_item_added', $viewerAccountId, 'success', $collectionId);
        return ['ok' => true, 'changed' => true, 'reason' => 'ok'];
    } catch (Throwable) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'changed' => false, 'reason' => 'unavailable'];
    }
}

/**
 * Remove one image reference from one owned collection without touching source media/favourites.
 *
 * @param array $viewer Current viewer principal.
 * @param int $collectionId Collection identifier.
 * @param int $imageId Canonical image identifier.
 * @return array{ok:bool,changed:bool,reason:string}
 */
function viewer_collection_item_remove(array $viewer, int $collectionId, int $imageId): array
{
    $viewerAccountId = (int) ($viewer['id'] ?? 0);
    if ($viewerAccountId <= 0 || $collectionId <= 0 || $imageId <= 0) {
        return ['ok' => false, 'changed' => false, 'reason' => 'invalid'];
    }
    if (!viewer_collections_storage_available()) {
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

        $delete = $pdo->prepare(
            'DELETE FROM viewer_collection_items WHERE viewer_collection_id = ? AND image_id = ?'
        );
        $delete->execute([$collectionId, $imageId]);
        $changed = $delete->rowCount() > 0;
        if ($changed) {
            viewer_collection_normalize_positions($pdo, $collectionId);
            $pdo->prepare('UPDATE viewer_collections SET updated_at = ? WHERE id = ? AND viewer_account_id = ?')
                ->execute([now_sql(), $collectionId, $viewerAccountId]);
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }
        if ($changed) {
            viewer_collection_security_event_best_effort('viewer.collection_item_removed', $viewerAccountId, 'success', $collectionId);
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
 * Reorder submitted collection items transactionally while leaving omitted item slots intact.
 *
 * The HTTP UI submits only currently rendered/authorized image ids. Hidden/inaccessible references
 * therefore stay in their existing ordinal slots while the visible subset is permuted around them.
 * Every submitted id must belong to this same owned collection. Invalid, duplicate, foreign, or
 * oversized requests are rejected before any position update is issued.
 *
 * @param array $viewer Current viewer principal.
 * @param int $collectionId Collection identifier.
 * @param array<int,mixed> $submittedImageIds Ordered image ids.
 * @return array{ok:bool,changed:bool,reason:string}
 */
function viewer_collection_reorder(array $viewer, int $collectionId, array $submittedImageIds): array
{
    $viewerAccountId = (int) ($viewer['id'] ?? 0);
    if ($viewerAccountId <= 0 || $collectionId <= 0) {
        return ['ok' => false, 'changed' => false, 'reason' => 'invalid'];
    }
    if (!viewer_collections_storage_available()) {
        return ['ok' => false, 'changed' => false, 'reason' => 'unavailable'];
    }

    $quota = viewer_content_quota_config();
    $maxItems = (int) $quota['max_viewer_items_per_collection'];
    if (count($submittedImageIds) > $maxItems) {
        return ['ok' => false, 'changed' => false, 'reason' => 'oversized'];
    }

    $submitted = [];
    $seen = [];
    foreach ($submittedImageIds as $rawImageId) {
        $imageId = filter_var($rawImageId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($imageId === false) {
            return ['ok' => false, 'changed' => false, 'reason' => 'invalid_order'];
        }
        $imageId = (int) $imageId;
        if (isset($seen[$imageId])) {
            return ['ok' => false, 'changed' => false, 'reason' => 'duplicate_item'];
        }
        $seen[$imageId] = true;
        $submitted[] = $imageId;
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

        $itemsStmt = $pdo->prepare(
            'SELECT image_id, position FROM viewer_collection_items '
            . 'WHERE viewer_collection_id = ? ORDER BY position ASC, image_id ASC FOR UPDATE'
        );
        $itemsStmt->execute([$collectionId]);
        $rows = $itemsStmt->fetchAll();
        if (count($rows) > $maxItems) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'changed' => false, 'reason' => 'quota_state_invalid'];
        }

        $currentOrder = [];
        $currentSet = [];
        foreach ($rows as $row) {
            $imageId = (int) ($row['image_id'] ?? 0);
            if ($imageId <= 0) {
                throw new \RuntimeException('Viewer collection contains an invalid image reference.');
            }
            $currentOrder[] = $imageId;
            $currentSet[$imageId] = true;
        }
        if ($submitted === [] && $currentOrder !== []) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'changed' => false, 'reason' => 'invalid_order'];
        }
        foreach ($submitted as $imageId) {
            if (!isset($currentSet[$imageId])) {
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return ['ok' => false, 'changed' => false, 'reason' => 'foreign_item'];
            }
        }

        $newOrder = $currentOrder;
        $submittedIndex = 0;
        foreach ($currentOrder as $index => $imageId) {
            if (isset($seen[$imageId])) {
                $newOrder[$index] = $submitted[$submittedIndex];
                $submittedIndex++;
            }
        }
        if ($submittedIndex !== count($submitted)) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'changed' => false, 'reason' => 'invalid_order'];
        }

        $positionsNormalized = true;
        foreach ($rows as $index => $row) {
            if ((int) ($row['position'] ?? 0) !== $index + 1) {
                $positionsNormalized = false;
                break;
            }
        }
        $changed = $newOrder !== $currentOrder || !$positionsNormalized;
        if ($changed) {
            $update = $pdo->prepare(
                'UPDATE viewer_collection_items SET position = ? WHERE viewer_collection_id = ? AND image_id = ?'
            );
            foreach ($newOrder as $index => $imageId) {
                $update->execute([$index + 1, $collectionId, $imageId]);
                if ($update->rowCount() > 1) {
                    throw new \RuntimeException('Viewer collection reorder affected multiple rows.');
                }
            }
            $pdo->prepare('UPDATE viewer_collections SET updated_at = ? WHERE id = ? AND viewer_account_id = ?')
                ->execute([now_sql(), $collectionId, $viewerAccountId]);
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return ['ok' => true, 'changed' => $changed, 'reason' => 'ok'];
    } catch (Throwable) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'changed' => false, 'reason' => 'unavailable'];
    }
}
