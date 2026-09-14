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
 * Author:
 *   Rudolf Klusal
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

use Throwable;
use function Gallery\Models\viewer_collection_model_abort;
use function Gallery\Models\viewer_collection_model_account_lock;
use function Gallery\Models\viewer_collection_model_delete;
use function Gallery\Models\viewer_collection_model_insert;
use function Gallery\Models\viewer_collection_model_item_count_and_max_position;
use function Gallery\Models\viewer_collection_model_item_delete;
use function Gallery\Models\viewer_collection_model_item_exists;
use function Gallery\Models\viewer_collection_model_item_insert;
use function Gallery\Models\viewer_collection_model_item_references;
use function Gallery\Models\viewer_collection_model_items_lock;
use function Gallery\Models\viewer_collection_model_list_for_owner;
use function Gallery\Models\viewer_collection_model_normalize_positions;
use function Gallery\Models\viewer_collection_model_owned_get;
use function Gallery\Models\viewer_collection_model_owned_lock;
use function Gallery\Models\viewer_collection_model_owner_count;
use function Gallery\Models\viewer_collection_model_rename;
use function Gallery\Models\viewer_collection_model_touch;
use function Gallery\Models\viewer_collection_model_transaction;
use function Gallery\Models\viewer_collection_model_update_positions;
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
        $rows = [];
        foreach (viewer_collection_model_list_for_owner($viewerAccountId, $limit) as $row) {
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
        $row = viewer_collection_model_owned_get($viewerAccountId, $collectionId);
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
        $rows = [];
        foreach (viewer_collection_model_item_references($viewerAccountId, $collectionId, $limit) as $row) {
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
 * @param mixed $transactionContextOrViewer Viewer principal, or legacy transaction context followed by viewer principal.
 * @param ?array $viewer Optional viewer principal for legacy internal callers.
 * @return ?array Locked account row, or null when authority changed.
 */
function viewer_collection_lock_mutation_account(mixed $transactionContextOrViewer, ?array $viewer = null): ?array
{
    $viewer = $viewer ?? (is_array($transactionContextOrViewer) ? $transactionContextOrViewer : []);
    $viewerAccountId = (int) ($viewer['id'] ?? 0);
    $expectedSecurityVersion = (int) ($viewer['security_version'] ?? 0);
    if ($viewerAccountId <= 0 || $expectedSecurityVersion <= 0) {
        return null;
    }

    $account = viewer_collection_model_account_lock($viewerAccountId);
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
 * @param mixed $transactionContextOrViewerAccountId Viewer account id, or legacy transaction context.
 * @param int $viewerAccountIdOrCollectionId Viewer account id or collection id depending on call shape.
 * @param ?int $collectionId Optional collection id for legacy internal callers.
 * @return ?array Locked collection row.
 */
function viewer_collection_lock_owned(mixed $transactionContextOrViewerAccountId, int $viewerAccountIdOrCollectionId, ?int $collectionId = null): ?array
{
    if ($collectionId === null) {
        return viewer_collection_model_owned_lock((int) $transactionContextOrViewerAccountId, $viewerAccountIdOrCollectionId);
    }
    return viewer_collection_model_owned_lock($viewerAccountIdOrCollectionId, $collectionId);
}

/**
 * Normalize one locked collection to dense deterministic integer positions.
 *
 * The caller must already hold the owned collection row lock. At the Phase 2 quota this is a
 * bounded update and prevents repeated remove/add churn from causing unbounded position growth.
 *
 * @param mixed $transactionContextOrCollectionId Collection id, or legacy transaction context.
 * @param ?int $collectionId Optional locked collection id for legacy internal callers.
 * @return int Number of collection items after normalization.
 */
function viewer_collection_normalize_positions(mixed $transactionContextOrCollectionId, ?int $collectionId = null): int
{
    return viewer_collection_model_normalize_positions($collectionId ?? (int) $transactionContextOrCollectionId);
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

    try {
        $result = viewer_collection_model_transaction(static function () use ($viewer, $viewerAccountId, $prepared): array {
            if (viewer_collection_lock_mutation_account($viewer) === null) {
                viewer_collection_model_abort(['ok' => false, 'collection_id' => 0, 'changed' => false, 'reason' => 'account_unavailable', 'retry_after_seconds' => 0]);
            }
            $quota = viewer_content_quota_config();
            if (viewer_collection_model_owner_count($viewerAccountId) >= (int) $quota['max_viewer_collections_per_account']) {
                viewer_collection_model_abort(['ok' => false, 'collection_id' => 0, 'changed' => false, 'reason' => 'quota', 'retry_after_seconds' => 0]);
            }
            $collectionId = viewer_collection_model_insert($viewerAccountId, $prepared['title'], now_sql());
            return ['ok' => true, 'collection_id' => $collectionId, 'changed' => true, 'reason' => 'ok', 'retry_after_seconds' => 0];
        });
        if ($result['ok']) {
            viewer_collection_security_event_best_effort('viewer.collection_created', $viewerAccountId, 'success', (int) $result['collection_id']);
        }
        return $result;
    } catch (Throwable) {
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

    try {
        $result = viewer_collection_model_transaction(static function () use ($viewer, $viewerAccountId, $collectionId, $prepared): array {
            if (viewer_collection_lock_mutation_account($viewer) === null) {
                viewer_collection_model_abort(['ok' => false, 'changed' => false, 'reason' => 'account_unavailable']);
            }
            $collection = viewer_collection_lock_owned($viewerAccountId, $collectionId);
            if ($collection === null) {
                viewer_collection_model_abort(['ok' => false, 'changed' => false, 'reason' => 'not_found']);
            }
            $changed = (string) ($collection['title'] ?? '') !== $prepared['title'];
            if ($changed) {
                viewer_collection_model_rename($viewerAccountId, $collectionId, $prepared['title'], now_sql());
            }
            return ['ok' => true, 'changed' => $changed, 'reason' => 'ok'];
        });
        if ($result['changed']) {
            viewer_collection_security_event_best_effort('viewer.collection_renamed', $viewerAccountId, 'success', $collectionId);
        }
        return $result;
    } catch (Throwable) {
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

    try {
        $result = viewer_collection_model_transaction(static function () use ($viewer, $viewerAccountId, $collectionId): array {
            if (viewer_collection_lock_mutation_account($viewer) === null) {
                viewer_collection_model_abort(['ok' => false, 'changed' => false, 'reason' => 'account_unavailable']);
            }
            if (viewer_collection_lock_owned($viewerAccountId, $collectionId) === null) {
                viewer_collection_model_abort(['ok' => false, 'changed' => false, 'reason' => 'not_found']);
            }
            viewer_collection_model_delete($viewerAccountId, $collectionId);
            return ['ok' => true, 'changed' => true, 'reason' => 'ok'];
        });
        if ($result['changed']) {
            viewer_collection_security_event_best_effort('viewer.collection_deleted', $viewerAccountId, 'success', $collectionId);
        }
        return $result;
    } catch (Throwable) {
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

    try {
        $result = viewer_collection_model_transaction(static function () use ($viewer, $viewerAccountId, $collectionId, $imageId): array {
            if (viewer_collection_lock_mutation_account($viewer) === null) {
                viewer_collection_model_abort(['ok' => false, 'changed' => false, 'reason' => 'account_unavailable']);
            }
            if (viewer_collection_lock_owned($viewerAccountId, $collectionId) === null) {
                viewer_collection_model_abort(['ok' => false, 'changed' => false, 'reason' => 'not_found']);
            }
            if (viewer_collection_model_item_exists($collectionId, $imageId)) {
                return ['ok' => true, 'changed' => false, 'reason' => 'already_present'];
            }
            $quota = viewer_content_quota_config();
            $state = viewer_collection_model_item_count_and_max_position($collectionId);
            $itemCount = (int) $state['item_count'];
            if ($itemCount >= (int) $quota['max_viewer_items_per_collection']) {
                viewer_collection_model_abort(['ok' => false, 'changed' => false, 'reason' => 'quota']);
            }
            if ((int) $state['max_position'] !== $itemCount) {
                $itemCount = viewer_collection_normalize_positions($collectionId);
            }
            $position = $itemCount + 1;
            if ($position <= 0 || $position > 4294967295) {
                throw new \RuntimeException('Viewer collection position is out of range.');
            }
            $now = now_sql();
            viewer_collection_model_item_insert($collectionId, $imageId, $position, $now);
            viewer_collection_model_touch($viewerAccountId, $collectionId, $now);
            return ['ok' => true, 'changed' => true, 'reason' => 'ok'];
        });
        if ($result['changed']) {
            viewer_collection_security_event_best_effort('viewer.collection_item_added', $viewerAccountId, 'success', $collectionId);
        }
        return $result;
    } catch (Throwable) {
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

    try {
        $result = viewer_collection_model_transaction(static function () use ($viewer, $viewerAccountId, $collectionId, $imageId): array {
            if (viewer_collection_lock_mutation_account($viewer) === null) {
                viewer_collection_model_abort(['ok' => false, 'changed' => false, 'reason' => 'account_unavailable']);
            }
            if (viewer_collection_lock_owned($viewerAccountId, $collectionId) === null) {
                viewer_collection_model_abort(['ok' => false, 'changed' => false, 'reason' => 'not_found']);
            }
            $changed = viewer_collection_model_item_delete($collectionId, $imageId);
            if ($changed) {
                viewer_collection_normalize_positions($collectionId);
                viewer_collection_model_touch($viewerAccountId, $collectionId, now_sql());
            }
            return ['ok' => true, 'changed' => $changed, 'reason' => 'ok'];
        });
        if ($result['changed']) {
            viewer_collection_security_event_best_effort('viewer.collection_item_removed', $viewerAccountId, 'success', $collectionId);
        }
        return $result;
    } catch (Throwable) {
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

    $maxItems = (int) viewer_content_quota_config()['max_viewer_items_per_collection'];
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

    try {
        return viewer_collection_model_transaction(static function () use ($viewer, $viewerAccountId, $collectionId, $maxItems, $submitted, $seen): array {
            if (viewer_collection_lock_mutation_account($viewer) === null) {
                viewer_collection_model_abort(['ok' => false, 'changed' => false, 'reason' => 'account_unavailable']);
            }
            if (viewer_collection_lock_owned($viewerAccountId, $collectionId) === null) {
                viewer_collection_model_abort(['ok' => false, 'changed' => false, 'reason' => 'not_found']);
            }
            $rows = viewer_collection_model_items_lock($collectionId);
            if (count($rows) > $maxItems) {
                viewer_collection_model_abort(['ok' => false, 'changed' => false, 'reason' => 'quota_state_invalid']);
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
                viewer_collection_model_abort(['ok' => false, 'changed' => false, 'reason' => 'invalid_order']);
            }
            foreach ($submitted as $imageId) {
                if (!isset($currentSet[$imageId])) {
                    viewer_collection_model_abort(['ok' => false, 'changed' => false, 'reason' => 'foreign_item']);
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
                viewer_collection_model_abort(['ok' => false, 'changed' => false, 'reason' => 'invalid_order']);
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
                viewer_collection_model_update_positions($collectionId, $newOrder);
                viewer_collection_model_touch($viewerAccountId, $collectionId, now_sql());
            }
            return ['ok' => true, 'changed' => $changed, 'reason' => 'ok'];
        });
    } catch (Throwable) {
        return ['ok' => false, 'changed' => false, 'reason' => 'unavailable'];
    }
}
