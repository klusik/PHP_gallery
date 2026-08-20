<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/viewer_favourites.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Provides the Phase 1.1 viewer-favourites ownership and mutation boundary.
 *
 * Responsibilities:
 *   - Keep favourite ownership scoped to the authenticated viewer account
 *   - Re-evaluate canonical source-image authorization before every mutation
 *   - Enforce the centralized per-account favourite quota atomically
 *   - Return bounded favourite state for public-card/lightbox rendering
 *   - Fail closed without making ordinary gallery browsing depend on viewer storage
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Viewer authentication is not gallery authorization.
 *   - Favourites store image references only and never preserve gallery access.
 *   - Collections and collection sharing are intentionally not implemented here.
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;
use function Gallery\Core\db;
use function Gallery\Core\now_sql;

/**
 * Return the three-state schema capability required by viewer favourites.
 *
 * @return array Aggregate schema inspection result.
 */
function viewer_favourites_schema_status(): array
{
    return schema_inspection_feature('viewer.favourites', [
        schema_inspection_table('viewer_accounts'),
        schema_inspection_table('viewer_favourites'),
        schema_inspection_table('images'),
        schema_inspection_table('galleries'),
    ]);
}

/**
 * Return true only when the existing Phase 0 favourite schema is verifiably available.
 */
function viewer_favourites_storage_available(): bool
{
    return viewer_accounts_enabled()
        && schema_inspection_is_available(viewer_favourites_schema_status());
}

/**
 * Return favourite state for image ids owned by one viewer using bounded SQL chunks.
 *
 * Failures deliberately return an empty map so viewer-schema degradation cannot break
 * ordinary public gallery rendering.
 *
 * @param int $viewerAccountId Viewer account identifier.
 * @param array<int,int|string> $imageIds Candidate image identifiers.
 * @return array<int,bool> Favourite state keyed by image id. Only true entries are returned.
 */
function viewer_favourites_for_image_ids(int $viewerAccountId, array $imageIds): array
{
    if ($viewerAccountId <= 0 || !viewer_favourites_storage_available()) {
        return [];
    }

    $ids = [];
    foreach ($imageIds as $imageId) {
        $imageId = (int) $imageId;
        if ($imageId > 0) {
            $ids[$imageId] = true;
        }
    }
    if ($ids === []) {
        return [];
    }

    try {
        $result = [];
        foreach (array_chunk(array_keys($ids), 200) as $idList) {
            $placeholders = implode(',', array_fill(0, count($idList), '?'));
            $stmt = db()->prepare(
                'SELECT image_id FROM viewer_favourites WHERE viewer_account_id = ? AND image_id IN (' . $placeholders . ')'
            );
            $stmt->execute(array_merge([$viewerAccountId], $idList));
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $imageId) {
                $result[(int) $imageId] = true;
            }
        }
        return $result;
    } catch (Throwable) {
        return [];
    }
}

/**
 * Return a bounded page of favourite references for one account, newest first.
 *
 * Source authorization is intentionally not performed here. The HTTP read path must call
 * viewer_source_image_can_render_reference()/viewer_source_image_resolve_authorized() for
 * every returned reference before exposing any image or gallery metadata.
 *
 * @return array{rows:array<int,array{image_id:int,created_at:string}>,total:int,page:int,per_page:int}
 */
function viewer_favourites_page(int $viewerAccountId, int $page = 1, int $perPage = 48): array
{
    $page = max(1, $page);
    $perPage = max(1, min(96, $perPage));
    if ($viewerAccountId <= 0 || !viewer_favourites_storage_available()) {
        return ['rows' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
    }

    try {
        $countStmt = db()->prepare('SELECT COUNT(*) FROM viewer_favourites WHERE viewer_account_id = ?');
        $countStmt->execute([$viewerAccountId]);
        $total = (int) $countStmt->fetchColumn();
        $maxPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $maxPage);
        $offset = ($page - 1) * $perPage;
        $stmt = db()->prepare(
            'SELECT image_id, created_at FROM viewer_favourites WHERE viewer_account_id = ? '
            . 'ORDER BY created_at DESC, image_id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $stmt->execute([$viewerAccountId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'image_id' => (int) ($row['image_id'] ?? 0),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    } catch (Throwable) {
        return ['rows' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
    }
}

/**
 * Set one favourite to an explicit desired state under the locked viewer account row.
 *
 * The account row serializes quota checks and concurrent writes for this account. Source
 * authorization is checked before any mutation and stored favourite rows never carry a
 * permission snapshot.
 *
 * @param array $viewer Current viewer principal returned by current_viewer().
 * @param int $imageId Canonical images.id value.
 * @param bool $desiredFavourite True to add, false to remove.
 * @return array{ok:bool,favourite:bool,changed:bool,reason:string}
 */
function viewer_favourite_set(array $viewer, int $imageId, bool $desiredFavourite): array
{
    $viewerAccountId = (int) ($viewer['id'] ?? 0);
    $expectedSecurityVersion = (int) ($viewer['security_version'] ?? 0);
    if ($viewerAccountId <= 0 || $expectedSecurityVersion <= 0 || $imageId <= 0) {
        return ['ok' => false, 'favourite' => false, 'changed' => false, 'reason' => 'invalid'];
    }
    if (!viewer_favourites_storage_available()) {
        return ['ok' => false, 'favourite' => false, 'changed' => false, 'reason' => 'unavailable'];
    }
    if (!viewer_source_image_can_reference($imageId)) {
        return ['ok' => false, 'favourite' => false, 'changed' => false, 'reason' => 'source_forbidden'];
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $accountStmt = $pdo->prepare(
            'SELECT id, email, normalized_email, password_hash, status, security_version, email_verified_at '
            . 'FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $accountStmt->execute([$viewerAccountId]);
        $lockedAccount = $accountStmt->fetch();
        if (!$lockedAccount
            || !viewer_account_can_mutate_content($lockedAccount)
            || (int) ($lockedAccount['security_version'] ?? 0) !== $expectedSecurityVersion) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'favourite' => false, 'changed' => false, 'reason' => 'account_unavailable'];
        }

        $existsStmt = $pdo->prepare(
            'SELECT 1 FROM viewer_favourites WHERE viewer_account_id = ? AND image_id = ? LIMIT 1'
        );
        $existsStmt->execute([$viewerAccountId, $imageId]);
        $exists = (bool) $existsStmt->fetchColumn();
        $initialExists = $exists;

        if ($desiredFavourite && !$exists) {
            $quota = viewer_content_quota_config();
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM viewer_favourites WHERE viewer_account_id = ?');
            $countStmt->execute([$viewerAccountId]);
            if ((int) $countStmt->fetchColumn() >= (int) $quota['max_viewer_favourites_per_account']) {
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return ['ok' => false, 'favourite' => false, 'changed' => false, 'reason' => 'quota'];
            }
            $insert = $pdo->prepare(
                'INSERT INTO viewer_favourites (viewer_account_id, image_id, created_at) VALUES (?, ?, ?)'
            );
            $insert->execute([$viewerAccountId, $imageId, now_sql()]);
            $exists = true;
        } elseif (!$desiredFavourite && $exists) {
            $delete = $pdo->prepare('DELETE FROM viewer_favourites WHERE viewer_account_id = ? AND image_id = ?');
            $delete->execute([$viewerAccountId, $imageId]);
            $exists = false;
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return [
            'ok' => true,
            'favourite' => $exists,
            'changed' => $exists !== $initialExists,
            'reason' => 'ok',
        ];
    } catch (Throwable) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'favourite' => false, 'changed' => false, 'reason' => 'unavailable'];
    }
}
