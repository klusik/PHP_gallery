<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/gallery_mutations.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns persistence primitives and atomic database updates for gallery mutations.
 *
 * Responsibilities:
 *   - Read gallery subtree and image ownership rows used by mutation workflows
 *   - Delete gallery/image dependencies atomically through fixed allowlisted operations
 *   - Persist image moves and title-picture repairs in one transaction
 *   - Persist gallery folder-path and hierarchy updates
 *   - Keep SQL and PDO transaction ownership out of service orchestration
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
 *   - Dynamic cleanup targets are resolved only through fixed internal allowlists.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use PDO;
use RuntimeException;
use Throwable;
use function Gallery\Core\db;

/** @return array<int,array<string,mixed>> */
function gallery_mutation_model_subtree_rows(string $folderPath, string $descendantPattern): array
{
    $stmt = db()->prepare("SELECT * FROM galleries WHERE folder_path = ? OR folder_path LIKE ? ESCAPE '=' ORDER BY folder_path");
    $stmt->execute([$folderPath, $descendantPattern]);
    return $stmt->fetchAll() ?: [];
}

/** @return array<int,int> */
function gallery_mutation_model_subtree_ids(string $folderPath, string $descendantPattern, bool $deepestFirst = false): array
{
    $order = $deepestFirst ? 'DESC' : 'ASC';
    $stmt = db()->prepare("SELECT id FROM galleries WHERE folder_path = ? OR folder_path LIKE ? ESCAPE '=' ORDER BY folder_path " . $order);
    $stmt->execute([$folderPath, $descendantPattern]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/** @param array<int,int> $galleryIds @return array<int,int> */
function gallery_mutation_model_image_ids_for_galleries(array $galleryIds): array
{
    $galleryIds = gallery_mutation_model_positive_ids($galleryIds);
    if ($galleryIds === []) {
        return [];
    }

    $imageIds = [];
    foreach (array_chunk($galleryIds, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = db()->prepare('SELECT id FROM images WHERE gallery_id IN (' . $placeholders . ')');
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $imageId) {
            $imageIds[(int) $imageId] = (int) $imageId;
        }
    }
    return array_values($imageIds);
}

/**
 * Delete one gallery subtree and all enabled historical dependencies atomically.
 *
 * @param array<int,int> $galleryIds Gallery ids being removed.
 * @param array<string,bool> $availableDependencies Optional schema capabilities keyed by fixed dependency name.
 */
function gallery_mutation_model_delete_subtree(array $galleryIds, array $availableDependencies): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $deleted = gallery_mutation_model_delete_subtree_in_transaction($galleryIds, $availableDependencies);
        $pdo->commit();
        return $deleted;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Delete one gallery subtree using an already-open transaction.
 *
 * @param array<int,int> $galleryIds Gallery ids being removed.
 * @param array<string,bool> $availableDependencies Optional schema capabilities keyed by fixed dependency name.
 */
function gallery_mutation_model_delete_subtree_in_transaction(array $galleryIds, array $availableDependencies): int
{
    $galleryIds = gallery_mutation_model_positive_ids($galleryIds);
    if ($galleryIds === []) {
        return 0;
    }

    $pdo = db();
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('Gallery database subtree cleanup requires an active transaction.');
    }

    $imageIds = gallery_mutation_model_image_ids_for_galleries($galleryIds);
    if ($imageIds !== []) {
        foreach (['galleries.cover_image_id', 'telemetry_sessions.first_image_id', 'telemetry_sessions.last_image_id', 'telemetry_events.image_id', 'telemetry_job_runs.image_id'] as $dependency) {
            gallery_mutation_model_apply_null_dependency($dependency, $imageIds, $availableDependencies);
        }
        foreach (['image_thumbnail_variants.image_id', 'image_ai_analysis_jobs.image_id', 'image_ai_metadata.image_id', 'picture_game_votes.image_a_id', 'picture_game_votes.image_b_id', 'picture_game_votes.winner_image_id', 'image_tags.image_id', 'image_votes.image_id'] as $dependency) {
            gallery_mutation_model_apply_delete_dependency($dependency, $imageIds, $availableDependencies);
        }
    }

    foreach (['telemetry_sessions.first_gallery_id', 'telemetry_sessions.last_gallery_id', 'telemetry_events.gallery_id', 'telemetry_job_runs.gallery_id', 'galleries.parent_id'] as $dependency) {
        gallery_mutation_model_apply_null_dependency($dependency, $galleryIds, $availableDependencies);
    }
    foreach (['gallery_flight_maps.gallery_id', 'gallery_upload_tokens.gallery_id', 'mobile_webdav_upload_tokens.gallery_id', 'image_thumbnail_variants.gallery_id', 'image_ai_analysis_jobs.gallery_id', 'picture_game_votes.gallery_id', 'gallery_tags.gallery_id', 'zip_archives.gallery_id', 'images.gallery_id'] as $dependency) {
        gallery_mutation_model_apply_delete_dependency($dependency, $galleryIds, $availableDependencies);
    }

    return gallery_mutation_model_delete_fixed_rows('galleries.id', $galleryIds);
}

/**
 * Delete selected image rows and all enabled historical dependencies atomically.
 *
 * @param array<int,int> $imageIds Image ids expected to belong to the gallery.
 * @param array<string,bool> $availableDependencies Optional schema capabilities keyed by fixed dependency name.
 */
function gallery_mutation_model_delete_images(int $galleryId, array $imageIds, array $availableDependencies): int
{
    $imageIds = gallery_mutation_model_positive_ids($imageIds);
    if ($imageIds === []) {
        return 0;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach (['galleries.cover_image_id', 'telemetry_sessions.first_image_id', 'telemetry_sessions.last_image_id', 'telemetry_events.image_id', 'telemetry_job_runs.image_id'] as $dependency) {
            gallery_mutation_model_apply_null_dependency($dependency, $imageIds, $availableDependencies);
        }
        foreach (['image_thumbnail_variants.image_id', 'image_ai_analysis_jobs.image_id', 'image_ai_metadata.image_id', 'picture_game_votes.image_a_id', 'picture_game_votes.image_b_id', 'picture_game_votes.winner_image_id', 'image_tags.image_id', 'image_votes.image_id', 'image_translations.image_id', 'viewer_favourites.image_id', 'viewer_collection_items.image_id', 'duplicate_photo_ledger_pairs.image_id_low', 'duplicate_photo_ledger_pairs.image_id_high'] as $dependency) {
            gallery_mutation_model_apply_delete_dependency($dependency, $imageIds, $availableDependencies);
        }

        $placeholders = implode(',', array_fill(0, count($imageIds), '?'));
        $stmt = $pdo->prepare('DELETE FROM images WHERE gallery_id = ? AND id IN (' . $placeholders . ')');
        $stmt->execute(array_merge([$galleryId], $imageIds));
        $deletedRows = $stmt->rowCount();
        if ($deletedRows !== count($imageIds)) {
            throw new RuntimeException('Image deletion changed fewer database rows than expected.');
        }
        $pdo->commit();
        return $deletedRows;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** Return the current maximum direct image sort order for one gallery. */
function gallery_mutation_model_max_image_sort_order(int $galleryId): int
{
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM images WHERE gallery_id = ?');
    $stmt->execute([$galleryId]);
    return (int) $stmt->fetchColumn();
}

/** Return one replacement direct-image cover id, excluding selected ids. */
function gallery_mutation_model_first_cover_candidate(int $galleryId, array $excludedImageIds): ?int
{
    $excludedImageIds = gallery_mutation_model_positive_ids($excludedImageIds);
    $params = [$galleryId];
    $sql = "SELECT id FROM images WHERE gallery_id = ? AND relative_path NOT LIKE '%/%'";
    if ($excludedImageIds !== []) {
        $placeholders = implode(',', array_fill(0, count($excludedImageIds), '?'));
        $sql .= ' AND id NOT IN (' . $placeholders . ')';
        $params = array_merge($params, $excludedImageIds);
    }
    $sql .= " ORDER BY CASE WHEN visibility = 'public' THEN 0 ELSE 1 END, sort_order, filename, id LIMIT 1";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $candidate = $stmt->fetchColumn();
    return $candidate ? (int) $candidate : null;
}

/** Return whether one image belongs to any gallery id in the supplied branch. */
function gallery_mutation_model_image_belongs_to_galleries(int $imageId, array $galleryIds): bool
{
    $galleryIds = gallery_mutation_model_positive_ids($galleryIds);
    if ($galleryIds === []) {
        return false;
    }
    $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
    $stmt = db()->prepare('SELECT COUNT(*) FROM images WHERE id = ? AND gallery_id IN (' . $placeholders . ')');
    $stmt->execute(array_merge([$imageId], $galleryIds));
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Persist image ownership, sort orders, and both gallery covers atomically.
 *
 * @param array<int,int> $imageIds Image ids in the intended destination order.
 * @param array<int,int> $destinationSortOrders Sort order keyed by image id.
 * @return array{moved:int,source_cover_image_id:?int,destination_cover_image_id:?int}
 */
function gallery_mutation_model_move_images(int $sourceGalleryId, int $destinationGalleryId, array $imageIds, array $destinationSortOrders, array $destinationBranchIds, string $now): array
{
    $imageIds = gallery_mutation_model_positive_ids($imageIds);
    if ($imageIds === []) {
        return ['moved' => 0, 'source_cover_image_id' => null, 'destination_cover_image_id' => null];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $sourceCoverStmt = $pdo->prepare('SELECT cover_image_id FROM galleries WHERE id = ?');
        $sourceCoverStmt->execute([$sourceGalleryId]);
        $sourceCoverRaw = $sourceCoverStmt->fetchColumn();
        $sourceCoverImageId = $sourceCoverRaw ? (int) $sourceCoverRaw : null;
        if ($sourceCoverImageId !== null && in_array($sourceCoverImageId, $imageIds, true)) {
            $sourceCoverImageId = gallery_mutation_model_first_cover_candidate($sourceGalleryId, $imageIds);
            $placeholders = implode(',', array_fill(0, count($imageIds), '?'));
            $stmt = $pdo->prepare('UPDATE galleries SET cover_image_id = ?, updated_at = ? WHERE id = ? AND cover_image_id IN (' . $placeholders . ')');
            $stmt->execute(array_merge([$sourceCoverImageId, $now, $sourceGalleryId], $imageIds));
        }

        $updateStmt = $pdo->prepare('UPDATE images SET gallery_id = ?, sort_order = ?, updated_at = ? WHERE gallery_id = ? AND id = ?');
        $updatedRows = 0;
        foreach ($imageIds as $imageId) {
            $updateStmt->execute([
                $destinationGalleryId,
                (int) ($destinationSortOrders[$imageId] ?? 0),
                $now,
                $sourceGalleryId,
                $imageId,
            ]);
            $updatedRows += $updateStmt->rowCount();
        }
        if ($updatedRows !== count($imageIds)) {
            throw new RuntimeException('Only ' . $updatedRows . ' of ' . count($imageIds) . ' image records moved.');
        }

        $destinationCoverStmt = $pdo->prepare('SELECT cover_image_id FROM galleries WHERE id = ?');
        $destinationCoverStmt->execute([$destinationGalleryId]);
        $destinationCoverRaw = $destinationCoverStmt->fetchColumn();
        $destinationCoverImageId = $destinationCoverRaw ? (int) $destinationCoverRaw : null;
        if ($destinationCoverImageId === null || !gallery_mutation_model_image_belongs_to_galleries($destinationCoverImageId, $destinationBranchIds)) {
            $destinationCoverImageId = gallery_mutation_model_first_cover_candidate($destinationGalleryId, []);
        }
        $stmt = $pdo->prepare('UPDATE galleries SET cover_image_id = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$destinationCoverImageId, $now, $destinationGalleryId]);

        $pdo->commit();
        return [
            'moved' => $updatedRows,
            'source_cover_image_id' => $sourceCoverImageId,
            'destination_cover_image_id' => $destinationCoverImageId,
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** Persist every moved gallery path/parent tuple atomically. */
function gallery_mutation_model_update_gallery_paths(array $updates, string $now): void
{
    if ($updates === []) {
        return;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE galleries SET folder_path = ?, folder_path_hash = ?, parent_id = ?, updated_at = ? WHERE id = ?');
        foreach ($updates as $update) {
            $stmt->execute([
                (string) $update['folder_path'],
                (string) $update['folder_path_hash'],
                $update['parent_id'] === null ? null : (int) $update['parent_id'],
                $now,
                (int) $update['id'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** @return array<int,array{id:int,folder_path:string,parent_id:mixed}> */
function gallery_mutation_model_hierarchy_rows(): array
{
    $stmt = db()->prepare('SELECT id, folder_path, parent_id FROM galleries ORDER BY folder_path');
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}

/** Persist a prevalidated filesystem-derived parent map and return whether rows changed. */
function gallery_mutation_model_sync_parent_map(array $currentRows, array $desiredParentById, string $now): bool
{
    $pdo = db();
    $clearParent = $pdo->prepare('UPDATE galleries SET parent_id = NULL, updated_at = ? WHERE id = ? AND parent_id IS NOT NULL');
    $setParent = $pdo->prepare('UPDATE galleries SET parent_id = ?, updated_at = ? WHERE id = ? AND (parent_id IS NULL OR parent_id <> ?)');
    $changed = false;
    foreach ($currentRows as $gallery) {
        $galleryId = (int) $gallery['id'];
        $currentParentId = $gallery['parent_id'] === null ? 0 : (int) $gallery['parent_id'];
        $desiredParentId = (int) ($desiredParentById[$galleryId] ?? 0);
        if ($currentParentId === $desiredParentId) {
            continue;
        }
        if ($desiredParentId <= 0) {
            $clearParent->execute([$now, $galleryId]);
            $changed = $changed || $clearParent->rowCount() > 0;
            continue;
        }
        $setParent->execute([$desiredParentId, $now, $galleryId, $desiredParentId]);
        $changed = $changed || $setParent->rowCount() > 0;
    }
    return $changed;
}

/** Delete rows for one fixed cleanup dependency key. */
function gallery_mutation_model_delete_dependency(string $dependency, array $ids): int
{
    return gallery_mutation_model_delete_fixed_rows($dependency, $ids);
}

/** Null rows for one fixed cleanup dependency key. */
function gallery_mutation_model_null_dependency(string $dependency, array $ids): int
{
    return gallery_mutation_model_null_fixed_rows($dependency, $ids);
}

/** @param array<int,int> $ids @return array<int,int> */
function gallery_mutation_model_positive_ids(array $ids): array
{
    return array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
}

/** @return array{0:string,1:string} */
function gallery_mutation_model_dependency_target(string $dependency): array
{
    $targets = [
        'galleries.id' => ['galleries', 'id'],
        'galleries.cover_image_id' => ['galleries', 'cover_image_id'],
        'galleries.parent_id' => ['galleries', 'parent_id'],
        'telemetry_sessions.first_image_id' => ['telemetry_sessions', 'first_image_id'],
        'telemetry_sessions.last_image_id' => ['telemetry_sessions', 'last_image_id'],
        'telemetry_events.image_id' => ['telemetry_events', 'image_id'],
        'telemetry_job_runs.image_id' => ['telemetry_job_runs', 'image_id'],
        'telemetry_sessions.first_gallery_id' => ['telemetry_sessions', 'first_gallery_id'],
        'telemetry_sessions.last_gallery_id' => ['telemetry_sessions', 'last_gallery_id'],
        'telemetry_events.gallery_id' => ['telemetry_events', 'gallery_id'],
        'telemetry_job_runs.gallery_id' => ['telemetry_job_runs', 'gallery_id'],
        'image_thumbnail_variants.image_id' => ['image_thumbnail_variants', 'image_id'],
        'image_thumbnail_variants.gallery_id' => ['image_thumbnail_variants', 'gallery_id'],
        'image_ai_analysis_jobs.image_id' => ['image_ai_analysis_jobs', 'image_id'],
        'image_ai_analysis_jobs.gallery_id' => ['image_ai_analysis_jobs', 'gallery_id'],
        'image_ai_metadata.image_id' => ['image_ai_metadata', 'image_id'],
        'picture_game_votes.image_a_id' => ['picture_game_votes', 'image_a_id'],
        'picture_game_votes.image_b_id' => ['picture_game_votes', 'image_b_id'],
        'picture_game_votes.winner_image_id' => ['picture_game_votes', 'winner_image_id'],
        'picture_game_votes.gallery_id' => ['picture_game_votes', 'gallery_id'],
        'image_tags.image_id' => ['image_tags', 'image_id'],
        'image_votes.image_id' => ['image_votes', 'image_id'],
        'image_translations.image_id' => ['image_translations', 'image_id'],
        'viewer_favourites.image_id' => ['viewer_favourites', 'image_id'],
        'viewer_collection_items.image_id' => ['viewer_collection_items', 'image_id'],
        'duplicate_photo_ledger_pairs.image_id_low' => ['duplicate_photo_ledger_pairs', 'image_id_low'],
        'duplicate_photo_ledger_pairs.image_id_high' => ['duplicate_photo_ledger_pairs', 'image_id_high'],
        'gallery_flight_maps.gallery_id' => ['gallery_flight_maps', 'gallery_id'],
        'gallery_upload_tokens.gallery_id' => ['gallery_upload_tokens', 'gallery_id'],
        'mobile_webdav_upload_tokens.gallery_id' => ['mobile_webdav_upload_tokens', 'gallery_id'],
        'gallery_tags.gallery_id' => ['gallery_tags', 'gallery_id'],
        'zip_archives.gallery_id' => ['zip_archives', 'gallery_id'],
        'images.gallery_id' => ['images', 'gallery_id'],
    ];
    if (!isset($targets[$dependency])) {
        throw new RuntimeException('Unsupported gallery mutation dependency: ' . $dependency);
    }
    return $targets[$dependency];
}

/** Delete one optional dependency only when schema policy confirmed it available. */
function gallery_mutation_model_apply_delete_dependency(string $dependency, array $ids, array $availableDependencies): int
{
    if (empty($availableDependencies[$dependency])) {
        return 0;
    }
    return gallery_mutation_model_delete_fixed_rows($dependency, $ids);
}

/** Null one optional dependency only when schema policy confirmed it available. */
function gallery_mutation_model_apply_null_dependency(string $dependency, array $ids, array $availableDependencies): int
{
    if (empty($availableDependencies[$dependency])) {
        return 0;
    }
    return gallery_mutation_model_null_fixed_rows($dependency, $ids);
}

/** Delete rows for one fixed allowlisted dependency target. */
function gallery_mutation_model_delete_fixed_rows(string $dependency, array $ids): int
{
    $ids = gallery_mutation_model_positive_ids($ids);
    if ($ids === []) {
        return 0;
    }
    [$table, $column] = gallery_mutation_model_dependency_target($dependency);
    $deleted = 0;
    foreach (array_chunk($ids, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = db()->prepare('DELETE FROM `' . $table . '` WHERE `' . $column . '` IN (' . $placeholders . ')');
        $stmt->execute($chunk);
        $deleted += $stmt->rowCount();
    }
    return $deleted;
}

/** Null foreign-key references for one fixed allowlisted dependency target. */
function gallery_mutation_model_null_fixed_rows(string $dependency, array $ids): int
{
    $ids = gallery_mutation_model_positive_ids($ids);
    if ($ids === []) {
        return 0;
    }
    [$table, $column] = gallery_mutation_model_dependency_target($dependency);
    $updated = 0;
    foreach (array_chunk($ids, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = db()->prepare('UPDATE `' . $table . '` SET `' . $column . '` = NULL WHERE `' . $column . '` IN (' . $placeholders . ')');
        $stmt->execute($chunk);
        $updated += $stmt->rowCount();
    }
    return $updated;
}
