<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/viewer_collections.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns private viewer-collection persistence and transaction mechanics.
 *
 * Responsibilities:
 *   - Read owner-scoped collection metadata and ordered image references
 *   - Lock viewer accounts and owned collections in deterministic mutation order
 *   - Enforce durable collection/item ordering primitives under transaction locks
 *   - Persist collection CRUD without storing source-media authorization snapshots
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Source-image authorization, viewer-account policy, title policy, quotas, and security events remain service-owned.
 *
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Models;

use RuntimeException;
use Throwable;
use function Gallery\Core\db;

/** Internal rollback signal carrying a non-exceptional collection workflow result. */
final class ViewerCollectionModelRollback extends RuntimeException
{
    /**
     * Store the workflow result carried across rollback.
     *
     * @template T
     * @param T $result Result value to make available after rollback.
     * @return void Initializes the rollback signal with the supplied result.
     */
    public function __construct(
        public readonly mixed $result,
    ) {
        parent::__construct(
            'Viewer collection transaction rolled back by workflow decision.',
        );
    }
}

/** Abort the current collection-model transaction and return the supplied workflow result. */
function viewer_collection_model_abort(mixed $result): never
{
    throw new ViewerCollectionModelRollback($result);
}

/** Execute one collection persistence unit of work inside the current or a new transaction. */
function viewer_collection_model_transaction(callable $operation): mixed
{
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $result = $operation();
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $result;
    } catch (ViewerCollectionModelRollback $rollback) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return $rollback->result;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Return bounded collection summaries for one owner.
 *
 * @param int $viewerAccountId Owner account identifier.
 * @param int $limit Maximum row count; clamped to 1..1000.
 * @return list<array{id:int|string,title:string,created_at:string,updated_at:string,item_count:int|string}> Collections ordered by latest update and identifier.
 */
function viewer_collection_model_list_for_owner(
    int $viewerAccountId,
    int $limit,
): array {
    $limit = max(1, min(1000, $limit));
    $stmt = db()->prepare(
        'SELECT vc.id, vc.title, vc.created_at, vc.updated_at, COUNT(vci.image_id) AS item_count '
            . 'FROM viewer_collections vc '
            . 'LEFT JOIN viewer_collection_items vci ON vci.viewer_collection_id = vc.id '
            . 'WHERE vc.viewer_account_id = ? '
            . 'GROUP BY vc.id, vc.title, vc.created_at, vc.updated_at '
            . 'ORDER BY vc.updated_at DESC, vc.id DESC LIMIT '
            . $limit,
    );
    $stmt->execute([$viewerAccountId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Return one owner-scoped collection summary.
 *
 * @param int $viewerAccountId Owner account identifier.
 * @param int $collectionId Collection identifier.
 * @return array{id:int|string,title:string,created_at:string,updated_at:string,item_count:int|string}|null Collection summary, or null when it is not owned by the account.
 */
function viewer_collection_model_owned_get(
    int $viewerAccountId,
    int $collectionId,
): ?array {
    $stmt = db()->prepare(
        'SELECT vc.id, vc.title, vc.created_at, vc.updated_at, '
            . '(SELECT COUNT(*) FROM viewer_collection_items vci WHERE vci.viewer_collection_id = vc.id) AS item_count '
            . 'FROM viewer_collections vc WHERE vc.id = ? AND vc.viewer_account_id = ? LIMIT 1',
    );
    $stmt->execute([$collectionId, $viewerAccountId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Return bounded ordered image references for one owned collection.
 *
 * @param int $viewerAccountId Owner account identifier.
 * @param int $collectionId Collection identifier.
 * @param int $limit Maximum row count; clamped to 1..5000.
 * @return list<array{image_id:int|string,position:int|string,created_at:string}> Ordered item references.
 */
function viewer_collection_model_item_references(
    int $viewerAccountId,
    int $collectionId,
    int $limit,
): array {
    $limit = max(1, min(5000, $limit));
    $stmt = db()->prepare(
        'SELECT vci.image_id, vci.position, vci.created_at '
            . 'FROM viewer_collection_items vci '
            . 'INNER JOIN viewer_collections vc ON vc.id = vci.viewer_collection_id '
            . 'WHERE vci.viewer_collection_id = ? AND vc.viewer_account_id = ? '
            . 'ORDER BY vci.position ASC, vci.image_id ASC LIMIT '
            . $limit,
    );
    $stmt->execute([$collectionId, $viewerAccountId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Lock and return the viewer account row used by collection mutations.
 *
 * @param int $viewerAccountId Account identifier.
 * @return array{id:int|string,email:string,normalized_email:string,password_hash:string,must_change_password:int|string,status:string,security_version:int|string,email_verified_at:?string}|null Locked account row, or null when it does not exist.
 */
function viewer_collection_model_account_lock(int $viewerAccountId): ?array
{
    $stmt = db()->prepare(
        'SELECT id, email, normalized_email, password_hash, must_change_password, status, security_version, email_verified_at '
            . 'FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE',
    );
    $stmt->execute([$viewerAccountId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Lock one collection under an explicit owner predicate.
 *
 * @param int $viewerAccountId Owner account identifier.
 * @param int $collectionId Collection identifier.
 * @return array{id:int|string,viewer_account_id:int|string,title:string,created_at:string,updated_at:string}|null Locked collection row, or null when ownership does not match.
 */
function viewer_collection_model_owned_lock(
    int $viewerAccountId,
    int $collectionId,
): ?array {
    $stmt = db()->prepare(
        'SELECT id, viewer_account_id, title, created_at, updated_at '
            . 'FROM viewer_collections WHERE id = ? AND viewer_account_id = ? LIMIT 1 FOR UPDATE',
    );
    $stmt->execute([$collectionId, $viewerAccountId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Return current collection count for a locked viewer account.
 *
 * @param int $viewerAccountId Owner account identifier.
 * @return int Number of collections currently owned by the account.
 */
function viewer_collection_model_owner_count(int $viewerAccountId): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM viewer_collections WHERE viewer_account_id = ?',
    );
    $stmt->execute([$viewerAccountId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Insert one collection and return its identifier.
 *
 * @param int $viewerAccountId Owner account identifier.
 * @param string $title Validated collection title.
 * @param string $now Current SQL timestamp for creation and update fields.
 * @return int Inserted collection identifier.
 */
function viewer_collection_model_insert(
    int $viewerAccountId,
    string $title,
    string $now,
): int {
    $stmt = db()->prepare(
        'INSERT INTO viewer_collections (viewer_account_id, title, created_at, updated_at) VALUES (?, ?, ?, ?)',
    );
    $stmt->execute([$viewerAccountId, $title, $now, $now]);
    $collectionId = (int) db()->lastInsertId();
    if ($collectionId <= 0) {
        throw new RuntimeException(
            'Viewer collection insert did not return an identifier.',
        );
    }
    return $collectionId;
}

/**
 * Rename one owner-scoped collection.
 *
 * @param int $viewerAccountId Owner account identifier.
 * @param int $collectionId Collection identifier.
 * @param string $title Validated replacement title.
 * @param string $now Current SQL timestamp for the update.
 * @return void Updates the owned collection or throws when the ownership row was not changed.
 */
function viewer_collection_model_rename(
    int $viewerAccountId,
    int $collectionId,
    string $title,
    string $now,
): void {
    $stmt = db()->prepare(
        'UPDATE viewer_collections SET title = ?, updated_at = ? WHERE id = ? AND viewer_account_id = ?',
    );
    $stmt->execute([$title, $now, $collectionId, $viewerAccountId]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Viewer collection rename lost ownership.');
    }
}

/**
 * Delete one owner-scoped collection row.
 *
 * @param int $viewerAccountId Owner account identifier.
 * @param int $collectionId Collection identifier.
 * @return void Deletes the owned collection or throws when the ownership row was not changed.
 */
function viewer_collection_model_delete(
    int $viewerAccountId,
    int $collectionId,
): void {
    $stmt = db()->prepare(
        'DELETE FROM viewer_collections WHERE id = ? AND viewer_account_id = ?',
    );
    $stmt->execute([$collectionId, $viewerAccountId]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Viewer collection delete lost ownership.');
    }
}

/**
 * Normalize one locked collection to dense deterministic integer positions.
 *
 * @param int $collectionId Collection identifier.
 * @return int Number of item rows whose positions were examined.
 */
function viewer_collection_model_normalize_positions(int $collectionId): int
{
    $stmt = db()->prepare(
        'SELECT image_id, position FROM viewer_collection_items '
            . 'WHERE viewer_collection_id = ? ORDER BY position ASC, image_id ASC FOR UPDATE',
    );
    $stmt->execute([$collectionId]);
    $rows = $stmt->fetchAll() ?: [];
    $update = null;
    foreach ($rows as $index => $row) {
        $targetPosition = $index + 1;
        if ((int) ($row['position'] ?? 0) === $targetPosition) {
            continue;
        }
        $update ??= db()->prepare(
            'UPDATE viewer_collection_items SET position = ? WHERE viewer_collection_id = ? AND image_id = ?',
        );
        $update->execute([
            $targetPosition,
            $collectionId,
            (int) ($row['image_id'] ?? 0),
        ]);
        if ($update->rowCount() > 1) {
            throw new RuntimeException(
                'Viewer collection position normalization affected multiple rows.',
            );
        }
    }
    return count($rows);
}

/**
 * Return whether one image reference already exists in a collection.
 *
 * @param int $collectionId Collection identifier.
 * @param int $imageId Canonical image identifier.
 * @return bool True when the collection contains the image.
 */
function viewer_collection_model_item_exists(
    int $collectionId,
    int $imageId,
): bool {
    $stmt = db()->prepare(
        'SELECT 1 FROM viewer_collection_items WHERE viewer_collection_id = ? AND image_id = ? LIMIT 1',
    );
    $stmt->execute([$collectionId, $imageId]);
    return $stmt->fetchColumn() !== false;
}

/**
 * Return item count and maximum position for one locked collection.
 *
 * @param int $collectionId Collection identifier.
 * @return array{item_count:int,max_position:int} Current item count and greatest position, or zero for an empty collection.
 */
function viewer_collection_model_item_count_and_max_position(int $collectionId): array
{
    $countStmt = db()->prepare(
        'SELECT COUNT(*) FROM viewer_collection_items WHERE viewer_collection_id = ?',
    );
    $countStmt->execute([$collectionId]);
    $itemCount = (int) $countStmt->fetchColumn();
    $positionStmt = db()->prepare(
        'SELECT COALESCE(MAX(position), 0) FROM viewer_collection_items WHERE viewer_collection_id = ?',
    );
    $positionStmt->execute([$collectionId]);
    return [
        'item_count' => $itemCount,
        'max_position' => (int) $positionStmt->fetchColumn(),
    ];
}

/**
 * Insert one canonical image reference at the supplied collection position.
 *
 * @param int $collectionId Collection identifier.
 * @param int $imageId Canonical image identifier.
 * @param int $position One-based position within the collection.
 * @param string $now Current SQL creation timestamp.
 * @return void Inserts the ordered item reference.
 */
function viewer_collection_model_item_insert(
    int $collectionId,
    int $imageId,
    int $position,
    string $now,
): void {
    $stmt = db()->prepare(
        'INSERT INTO viewer_collection_items (viewer_collection_id, image_id, position, created_at) VALUES (?, ?, ?, ?)',
    );
    $stmt->execute([$collectionId, $imageId, $position, $now]);
}

/**
 * Touch one owner-scoped collection after item/order mutation.
 *
 * @param int $viewerAccountId Owner account identifier.
 * @param int $collectionId Collection identifier.
 * @param string $now Current SQL timestamp stored as the update time.
 * @return void Updates the timestamp for the matching owned collection.
 */
function viewer_collection_model_touch(
    int $viewerAccountId,
    int $collectionId,
    string $now,
): void {
    db()
        ->prepare(
            'UPDATE viewer_collections SET updated_at = ? WHERE id = ? AND viewer_account_id = ?',
        )
        ->execute([$now, $collectionId, $viewerAccountId]);
}

/**
 * Remove one image reference from a collection and return whether a row changed.
 *
 * @param int $collectionId Collection identifier.
 * @param int $imageId Canonical image identifier.
 * @return bool True when a row was deleted.
 */
function viewer_collection_model_item_delete(
    int $collectionId,
    int $imageId,
): bool {
    $stmt = db()->prepare(
        'DELETE FROM viewer_collection_items WHERE viewer_collection_id = ? AND image_id = ?',
    );
    $stmt->execute([$collectionId, $imageId]);
    return $stmt->rowCount() > 0;
}

/**
 * Lock and return the full ordered item state for one collection.
 *
 * @param int $collectionId Collection identifier.
 * @return list<array{image_id:int|string,position:int|string}> Locked item rows in collection order.
 */
function viewer_collection_model_items_lock(int $collectionId): array
{
    $stmt = db()->prepare(
        'SELECT image_id, position FROM viewer_collection_items '
            . 'WHERE viewer_collection_id = ? ORDER BY position ASC, image_id ASC FOR UPDATE',
    );
    $stmt->execute([$collectionId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Persist one complete dense item order for a locked collection.
 *
 * @param int $collectionId Collection identifier.
 * @param list<int> $orderedImageIds Image identifiers in their desired order.
 * @return void Persists each supplied image's one-based position.
 */
function viewer_collection_model_update_positions(
    int $collectionId,
    array $orderedImageIds,
): void {
    $stmt = db()->prepare(
        'UPDATE viewer_collection_items SET position = ? WHERE viewer_collection_id = ? AND image_id = ?',
    );
    foreach ($orderedImageIds as $index => $imageId) {
        $stmt->execute([$index + 1, $collectionId, (int) $imageId]);
        if ($stmt->rowCount() > 1) {
            throw new RuntimeException(
                'Viewer collection reorder affected multiple rows.',
            );
        }
    }
}
