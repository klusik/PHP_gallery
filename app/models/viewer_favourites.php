<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/viewer_favourites.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns persistence for viewer-owned image favourites.
 *
 * Responsibilities:
 *   - Batch-load favourite state for rendered image identifiers
 *   - Page favourite references for one viewer account
 *   - Serialize quota-sensitive add/remove mutations under the viewer account row lock
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Source-image authorization and account mutation policy remain service responsibilities.
 *   - The mutation callback evaluates the already locked viewer account without exposing PDO to services.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use Throwable;
use function Gallery\Core\db;

/**
 * Return favourite image ids for one viewer account using bounded chunks.
 *
 * @param int $viewerAccountId Viewer account identifier.
 * @param array<int,int> $imageIds Positive image identifiers.
 * @param int $chunkSize Maximum ids per prepared statement.
 * @return array<int,bool> True state keyed by image id.
 */
function viewer_favourites_model_for_image_ids(int $viewerAccountId, array $imageIds, int $chunkSize = 200): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $imageIds), static fn (int $id): bool => $id > 0)));
    if ($viewerAccountId <= 0 || $ids === []) return [];
    $result = [];
    foreach (array_chunk($ids, max(1, $chunkSize)) as $idList) {
        $placeholders = implode(',', array_fill(0, count($idList), '?'));
        $stmt = db()->prepare('SELECT image_id FROM viewer_favourites WHERE viewer_account_id = ? AND image_id IN (' . $placeholders . ')');
        $stmt->execute(array_merge([$viewerAccountId], $idList));
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $imageId) $result[(int) $imageId] = true;
    }
    return $result;
}

/** Return total/page rows for one viewer account, newest first. */
function viewer_favourites_model_page(int $viewerAccountId, int $page, int $perPage): array
{
    $countStmt = db()->prepare('SELECT COUNT(*) FROM viewer_favourites WHERE viewer_account_id = ?');
    $countStmt->execute([$viewerAccountId]);
    $total = (int) $countStmt->fetchColumn();
    $maxPage = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, $page), $maxPage);
    $offset = ($page - 1) * $perPage;
    $stmt = db()->prepare(
        'SELECT image_id, created_at FROM viewer_favourites WHERE viewer_account_id = ? '
        . 'ORDER BY created_at DESC, image_id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
    );
    $stmt->execute([$viewerAccountId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = ['image_id' => (int) ($row['image_id'] ?? 0), 'created_at' => (string) ($row['created_at'] ?? '')];
    }
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
}

/**
 * Atomically set one favourite under the locked viewer account row.
 *
 * @param int $viewerAccountId Viewer account identifier.
 * @param int $imageId Image identifier.
 * @param bool $desiredFavourite Desired favourite state.
 * @param int $maxFavourites Per-account quota.
 * @param string $now Shared SQL timestamp.
 * @param callable(array<string,mixed>):bool $lockedAccountAllowed Service-owned account policy callback.
 * @return array{ok:bool,favourite:bool,changed:bool,reason:string}
 */
function viewer_favourite_model_set(int $viewerAccountId, int $imageId, bool $desiredFavourite, int $maxFavourites, string $now, callable $lockedAccountAllowed): array
{
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $accountStmt = $pdo->prepare(
            'SELECT id, email, normalized_email, password_hash, status, security_version, email_verified_at '
            . 'FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $accountStmt->execute([$viewerAccountId]);
        $lockedAccount = $accountStmt->fetch();
        if (!is_array($lockedAccount) || !$lockedAccountAllowed($lockedAccount)) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'favourite' => false, 'changed' => false, 'reason' => 'account_unavailable'];
        }

        $existsStmt = $pdo->prepare('SELECT 1 FROM viewer_favourites WHERE viewer_account_id = ? AND image_id = ? LIMIT 1');
        $existsStmt->execute([$viewerAccountId, $imageId]);
        $exists = (bool) $existsStmt->fetchColumn();
        $initialExists = $exists;

        if ($desiredFavourite && !$exists) {
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM viewer_favourites WHERE viewer_account_id = ?');
            $countStmt->execute([$viewerAccountId]);
            if ((int) $countStmt->fetchColumn() >= max(0, $maxFavourites)) {
                if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
                return ['ok' => false, 'favourite' => false, 'changed' => false, 'reason' => 'quota'];
            }
            $insert = $pdo->prepare('INSERT INTO viewer_favourites (viewer_account_id, image_id, created_at) VALUES (?, ?, ?)');
            $insert->execute([$viewerAccountId, $imageId, $now]);
            $exists = true;
        } elseif (!$desiredFavourite && $exists) {
            $delete = $pdo->prepare('DELETE FROM viewer_favourites WHERE viewer_account_id = ? AND image_id = ?');
            $delete->execute([$viewerAccountId, $imageId]);
            $exists = false;
        }

        if ($ownsTransaction) $pdo->commit();
        return ['ok' => true, 'favourite' => $exists, 'changed' => $exists !== $initialExists, 'reason' => 'ok'];
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}
