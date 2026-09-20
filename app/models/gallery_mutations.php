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
 *   - These primitives perform database work only. Service callers own
 *     authorization, three-state schema preflight, current-row selection,
 *     gallery writer ownership, filesystem journaling and sidecar/cache updates.
 *   - Transaction-opening functions require an idle shared PDO connection;
 *     no savepoints/nested transactions are provided. SQL exceptions propagate.
 *     Rollback is attempted only while PDO reports an active transaction, so a
 *     connection/commit failure is not proof that persistence was rolled back.
 *   - Direct gallery UPDATE statements increment edit_revision in model SQL; an
 *     installed compatibility trigger may enforce the same OLD + 1 value or
 *     refuse a competing connection. Foreign-key cascades/SET NULL do not run
 *     application SQL or triggers, so the service writer lease must cover the
 *     whole operation, including indirect gallery-cover changes.
 *
 * Cleanup capability contract:
 *   GalleryMutationDependencyMap is a service-resolved map of fixed target
 *   keys. True permits the corresponding statement; false or an absent key
 *   skips it. The service must reject unknown inspection results beforehand.
 *   The model does not probe schema, distinguish unknown from missing, or
 *   promise to clean unlisted relationships when historical foreign keys are
 *   absent. GalleryDatabaseRow refers to the raw, sensitive schema shape
 *   documented in app/models/galleries.php, not an authorization/response DTO.
 *
 * @phpstan-type GalleryMutationDependencyMap array{
 *   'galleries.cover_image_id'?:bool,'galleries.parent_id'?:bool,
 *   'telemetry_sessions.first_image_id'?:bool,'telemetry_sessions.last_image_id'?:bool,
 *   'telemetry_events.image_id'?:bool,'telemetry_job_runs.image_id'?:bool,
 *   'telemetry_sessions.first_gallery_id'?:bool,'telemetry_sessions.last_gallery_id'?:bool,
 *   'telemetry_events.gallery_id'?:bool,'telemetry_job_runs.gallery_id'?:bool,
 *   'image_thumbnail_variants.image_id'?:bool,'image_thumbnail_variants.gallery_id'?:bool,
 *   'image_ai_analysis_jobs.image_id'?:bool,'image_ai_analysis_jobs.gallery_id'?:bool,
 *   'image_ai_metadata.image_id'?:bool,'picture_game_votes.image_a_id'?:bool,
 *   'picture_game_votes.image_b_id'?:bool,'picture_game_votes.winner_image_id'?:bool,
 *   'picture_game_votes.gallery_id'?:bool,'image_tags.image_id'?:bool,'image_votes.image_id'?:bool,
 *   'image_translations.image_id'?:bool,'viewer_favourites.image_id'?:bool,
 *   'viewer_collection_items.image_id'?:bool,'duplicate_photo_ledger_pairs.image_id_low'?:bool,
 *   'duplicate_photo_ledger_pairs.image_id_high'?:bool,'gallery_flight_maps.gallery_id'?:bool,
 *   'gallery_upload_tokens.gallery_id'?:bool,'mobile_webdav_upload_tokens.gallery_id'?:bool,
 *   'gallery_tags.gallery_id'?:bool,'zip_archives.gallery_id'?:bool,'images.gallery_id'?:bool
 * }
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

require_once __DIR__ . '/gallery_image_move_journal.php';

/**
 * Read a physical root and its folder-path descendants without traversing parent links.
 *
 * The service supplies an '='-escaped LIKE pattern; this method binds it
 * unchanged and does not verify that it actually corresponds to folderPath.
 * No visibility, password, NSFW, locking or filesystem checks are performed.
 *
 * @param string $folderPath Normalized literal root path used by the equality branch.
 * @param string $descendantPattern Root path with '=', '%' and '_' escaped using '=', followed by '/%'.
 * @return list<GalleryDatabaseRow> Full sensitive gallery rows ordered lexically by folder_path; empty when no rows match.
 * @see gallery_model_find_by_id() Canonical raw gallery-row shape and sensitivity.
 * @author Rudolf Klusal
 */
function gallery_mutation_model_subtree_rows(string $folderPath, string $descendantPattern): array
{
    $stmt = db()->prepare("SELECT * FROM galleries WHERE folder_path = ? OR folder_path LIKE ? ESCAPE '=' ORDER BY folder_path");
    $stmt->execute([$folderPath, $descendantPattern]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Read physical subtree IDs in forward or reverse folder-path order.
 *
 * Includes the literal root as well as pattern matches. Reverse lexical order
 * places a descendant before its own path prefix; this is not a global
 * depth-first/length ordering across unrelated branches.
 *
 * @param string $folderPath Normalized literal root path.
 * @param string $descendantPattern Service-prepared descendant LIKE pattern using '=' as its escape character.
 * @param bool $deepestFirst True selects folder_path DESC; false selects ASC.
 * @return list<int> Matching IDs cast to integers; no access filtering or row locks.
 * @author Rudolf Klusal
 */
function gallery_mutation_model_subtree_ids(string $folderPath, string $descendantPattern, bool $deepestFirst = false): array
{
    $order = $deepestFirst ? 'DESC' : 'ASC';
    $stmt = db()->prepare("SELECT id FROM galleries WHERE folder_path = ? OR folder_path LIKE ? ESCAPE '=' ORDER BY folder_path " . $order);
    $stmt->execute([$folderPath, $descendantPattern]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/**
 * Collect image IDs owned by an explicit gallery selection in 500-gallery query chunks.
 *
 * Includes every image visibility and nested relative path. Normalization
 * removes nonpositive/duplicate gallery IDs; result IDs are deduplicated in
 * encounter order, but each chunk's SQL supplies no stable ordering.
 *
 * @param list<int|string> $galleryIds Candidate gallery IDs; no descendant expansion is performed.
 * @return list<int> Unique image IDs, or an empty list without SQL for an empty normalized selection.
 * @author Rudolf Klusal
 */
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
 * Delete explicit gallery IDs and enabled dependencies in a new database transaction.
 *
 * Does not discover descendants or touch their files. Even an empty selection
 * opens/commits a transaction here. Requires no pre-existing transaction;
 * compound Trash operations must use the in-transaction sibling instead.
 *
 * @param list<int|string> $galleryIds Complete service-selected subtree IDs to remove.
 * @param GalleryMutationDependencyMap $availableDependencies Conclusively resolved cleanup availability; never encode unknown as false.
 * @return int Gallery rows reported deleted, excluding dependent-row counts; normal return follows commit.
 * @throws Throwable On persistence/transaction failure; an uncertain commit requires caller-owned reconciliation.
 * @author Rudolf Klusal
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
 * Clears enabled cover/parent/telemetry references, deletes the explicitly
 * enumerated image/gallery dependencies, then deletes the selected galleries.
 * Other relationships depend on installed foreign keys; this is not a dynamic
 * schema-wide cleanup. Counts are not checked against requested gallery IDs.
 * Starts/commits/rolls back nothing: the owning transaction handles failures.
 *
 * @param list<int|string> $galleryIds Complete explicit subtree selection; normalized empty input returns before checking the transaction.
 * @param GalleryMutationDependencyMap $availableDependencies Service-resolved availability for the fixed dependencies used in this routine.
 * @return int Gallery rows reported deleted, excluding dependencies and foreign-key effects.
 * @throws RuntimeException For a nonempty selection without an active shared-PDO transaction.
 * @author Rudolf Klusal
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
 * Opens its own transaction after selection normalization. Reference cleanup
 * is scoped by image IDs, then the final DELETE additionally checks gallery_id.
 * A count mismatch throws and attempts rollback of that cleanup. Callers must
 * prevalidate ownership before staging files, retain writer ownership and
 * handle uncertain commit outcomes; this method does not restore files.
 *
 * @param int $galleryId Expected owner of every selected image row.
 * @param list<int|string> $imageIds Explicit service-validated selection; nonpositive/duplicate IDs are removed.
 * @param GalleryMutationDependencyMap $availableDependencies Verified optional cleanup targets, including translations/viewer/duplicate-ledger references.
 * @return int Deleted image count equal to the normalized selection on success; zero performs no SQL.
 * @throws RuntimeException If not every selected image was deleted from the expected owner.
 * @author Rudolf Klusal
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

/**
 * Read the maximum sort_order among all image rows owned by one gallery.
 *
 * Despite use by direct-image moves, this query includes nested relative paths
 * and every visibility state. It does not reserve the returned ordering value.
 *
 * @param int $galleryId Gallery whose existing image order supplies the append base.
 * @return int Current maximum sort_order, or zero if the gallery has no image rows.
 * @author Rudolf Klusal
 */
function gallery_mutation_model_max_image_sort_order(int $galleryId): int
{
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM images WHERE gallery_id = ?');
    $stmt->execute([$galleryId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Choose an existing direct-image cover candidate while excluding an explicit selection.
 *
 * Public images sort first, followed by other visibility states; ties use
 * sort_order, filename and id. No file-existence, NSFW or password check is
 * performed, and descendant galleries are not searched.
 *
 * @param int $galleryId Exact owning gallery.
 * @param list<int|string> $excludedImageIds IDs about to move/delete; normalized positive IDs are excluded.
 * @return int|null Candidate ID, or null when no direct image survives the exclusions.
 * @author Rudolf Klusal
 */
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

/**
 * Check image ownership against an explicit gallery-ID set without expanding its hierarchy.
 *
 * This is a relational existence check, not authorization or a physical-file check.
 *
 * @param int $imageId Candidate cover/image identity.
 * @param list<int|string> $galleryIds Service-selected branch IDs, normalized to unique positives.
 * @return bool True when the image exists under any supplied owner; empty scope returns false without SQL.
 * @author Rudolf Klusal
 */
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
 * Requires no existing transaction. The service holds writer/pair locks, has
 * already staged the physical move, and supplies a current destination branch.
 * Every normalized image must update under sourceGalleryId; a count mismatch
 * throws. Only a source cover in the moved selection is replaced. A destination
 * cover outside the supplied branch is replaced by a direct-image candidate.
 * No image relative paths/hashes, files, sidecars or caches are changed here.
 * With operationId, the journal commit marker joins the same transaction.
 * On a commit/connection exception, callers must reconcile the durable marker
 * rather than infer that files should be rolled back from the exception alone.
 *
 * @param int $sourceGalleryId Current owner of all requested image rows.
 * @param int $destinationGalleryId Existing receiving gallery, service-validated as distinct from the source.
 * @param list<int|string> $imageIds Image IDs in intended destination order; first occurrence survives normalization.
 * @param array<int,int> $destinationSortOrders Destination sort_order keyed by image ID; missing entries are written as zero.
 * @param list<int|string> $destinationBranchIds Current destination subtree IDs used only to validate its existing cover.
 * @param string $now SQL timestamp shared by ownership, cover and journal writes.
 * @param string $operationId Moving journal operation belonging to this source/destination; empty preserves the legacy unjournaled model call.
 * @return array{moved:int,source_cover_image_id:?int,destination_cover_image_id:?int} Committed row count and resolved cover IDs; empty selection returns zero/nulls without querying existing covers.
 * @throws RuntimeException For an incomplete ownership update or a journal marker that no longer owns the transaction.
 * @author Rudolf Klusal
 */
function gallery_mutation_model_move_images(int $sourceGalleryId, int $destinationGalleryId, array $imageIds, array $destinationSortOrders, array $destinationBranchIds, string $now, string $operationId = ''): array
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
            $stmt = $pdo->prepare('UPDATE galleries SET cover_image_id = ?, updated_at = ?, edit_revision = edit_revision + 1 WHERE id = ? AND cover_image_id IN (' . $placeholders . ')');
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
        $stmt = $pdo->prepare('UPDATE galleries SET cover_image_id = ?, updated_at = ?, edit_revision = edit_revision + 1 WHERE id = ?');
        $stmt->execute([$destinationCoverImageId, $now, $destinationGalleryId]);

        if ($operationId !== '') {
            gallery_image_move_model_commit_marker($operationId, $sourceGalleryId, $destinationGalleryId, $now);
        }
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

/**
 * Persist an explicit moved-gallery path/parent map in one new transaction.
 *
 * Paths, SHA-256 hashes, parent graph and row existence must be validated by the
 * service while holding writer ownership. This method neither renames folders
 * nor regenerates public paths; absent IDs are not detected by row-count checks.
 * Normal return commits all statements. An exception attempts rollback only
 * while PDO still reports an active transaction and does not prove filesystem state.
 *
 * @param list<array{id:int,folder_path:string,folder_path_hash:string,parent_id:int|null}> $updates New gallery-relative paths, hashes and nullable parent IDs for the complete moved subtree.
 * @param string $now Shared SQL timestamp for every row in the map.
 * @return void Empty input does nothing; otherwise starts/commits its own transaction.
 * @author Rudolf Klusal
 */
function gallery_mutation_model_update_gallery_paths(array $updates, string $now): void
{
    if ($updates === []) {
        return;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE galleries SET folder_path = ?, folder_path_hash = ?, parent_id = ?, updated_at = ?, edit_revision = edit_revision + 1 WHERE id = ?');
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

/**
 * Read the full physical hierarchy projection used to reconstruct parent links.
 *
 * No visibility filtering, locks or filesystem inspection are applied.
 *
 * @return list<array{id:int|string,folder_path:string,parent_id:int|string|null}> Raw associative rows in folder-path order; numeric PDO values are not cast.
 * @author Rudolf Klusal
 */
function gallery_mutation_model_hierarchy_rows(): array
{
    $stmt = db()->prepare('SELECT id, folder_path, parent_id FROM galleries ORDER BY folder_path');
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}

/**
 * Apply parent-link differences from a service-validated physical hierarchy snapshot.
 *
 * Missing/nonpositive desired parent IDs clear the relation to SQL NULL.
 * Rows whose snapshot already equals the desired parent are skipped; this is
 * not compare-and-swap against the stored old parent. Caller must supply fresh
 * rows under writer ownership and prevalidate cycles/Smart Gallery relationships.
 * Each executed UPDATE timestamps its row. No transaction is opened, so without
 * an outer transaction an error may follow earlier committed parent changes.
 *
 * @param list<array{id:int|string,parent_id:int|string|null}> $currentRows Current database hierarchy; extra folder-path fields are ignored.
 * @param array<int,int> $desiredParentById Complete parent map keyed by gallery ID; zero denotes a physical root.
 * @param string $now Shared SQL timestamp for parent updates.
 * @return bool Whether any executed UPDATE reported a changed row; empty input returns false.
 * @author Rudolf Klusal
 */
function gallery_mutation_model_sync_parent_map(array $currentRows, array $desiredParentById, string $now): bool
{
    $pdo = db();
    $clearParent = $pdo->prepare('UPDATE galleries SET parent_id = NULL, updated_at = ?, edit_revision = edit_revision + 1 WHERE id = ? AND parent_id IS NOT NULL');
    $setParent = $pdo->prepare('UPDATE galleries SET parent_id = ?, updated_at = ?, edit_revision = edit_revision + 1 WHERE id = ? AND (parent_id IS NULL OR parent_id <> ?)');
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

/**
 * Delete a fixed dependency's rows by referenced gallery/image identifiers.
 *
 * Convenience entry to chunked deletion, with no schema gate or transaction
 * ownership. Services must validate availability and retain the writer lease
 * where direct deletes or foreign-key effects can affect protected galleries.
 *
 * @param string $dependency Exact allowlisted table.column key resolved by dependency_target().
 * @param list<int|string> $ids Semantic IDs in that target column, not necessarily the deleted rows' own primary keys.
 * @return int Sum of driver-reported deleted rows, excluding foreign-key cascade counts.
 * @author Rudolf Klusal
 */
function gallery_mutation_model_delete_dependency(string $dependency, array $ids): int
{
    return gallery_mutation_model_delete_fixed_rows($dependency, $ids);
}

/**
 * Clear a fixed dependency's references to selected gallery/image identifiers.
 *
 * Delegates to chunked UPDATE without probing schema or opening a transaction.
 * The caller must select a nullable target and retain required writer ownership.
 *
 * @param string $dependency Exact allowlisted table.column key resolved by dependency_target().
 * @param list<int|string> $ids Referenced IDs whose matching column values should become SQL NULL.
 * @return int Sum of driver-reported updated rows; rows themselves are not deleted.
 * @author Rudolf Klusal
 */
function gallery_mutation_model_null_dependency(string $dependency, array $ids): int
{
    return gallery_mutation_model_null_fixed_rows($dependency, $ids);
}

/**
 * Integer-normalize, deduplicate and compact an explicit gallery/image selection.
 *
 * Uses PHP intval conversion, not strict decimal validation; callers authorize
 * semantic IDs before destructive use. First occurrence order is retained.
 *
 * @param list<int|string> $ids Candidate database identifiers.
 * @return list<int> Unique strictly positive IDs with contiguous numeric keys.
 * @author Rudolf Klusal
 */
function gallery_mutation_model_positive_ids(array $ids): array
{
    return array_values(array_unique(array_filter(array_map('intval', $ids), /**
     * Remove nonpositive IDs after the selection's integer conversion.
     * @param int $id Converted candidate gallery/image identifier.
     * @return bool Whether the ID is eligible for the explicit persistence selection.
     * @author Rudolf Klusal
     */ static fn (int $id): bool => $id > 0)));
}

/**
 * Resolve one exact cleanup dependency key to a hardcoded SQL table/column pair.
 *
 * This allowlist prevents arbitrary identifiers, not arbitrary destructive
 * intent. It includes galleries.id for final row deletion as well as dependent
 * references; availability, nullable-column choice and authorization are external.
 *
 * @param string $dependency Exact case-sensitive table.column key from the fixed targets map.
 * @return array{0:string,1:string} Trusted table name followed by its trusted matching column name.
 * @throws RuntimeException For an unregistered dependency key; caller-supplied text in this exception is not a safe public diagnostic.
 * @author Rudolf Klusal
 */
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

/**
 * Apply a dependency DELETE only when its service-resolved availability is truthy.
 *
 * Missing/false keys return before target validation. This does not distinguish
 * absent schema from inspection failure; the caller must refuse unknown state.
 *
 * @param string $dependency Fixed target key used both for capability lookup and SQL resolution.
 * @param list<int|string> $ids Referenced gallery/image IDs to remove from the dependency.
 * @param GalleryMutationDependencyMap $availableDependencies Complete conclusively resolved cleanup capabilities.
 * @return int Deleted row count, or zero when the dependency/selection is skipped.
 * @author Rudolf Klusal
 */
function gallery_mutation_model_apply_delete_dependency(string $dependency, array $ids, array $availableDependencies): int
{
    if (empty($availableDependencies[$dependency])) {
        return 0;
    }
    return gallery_mutation_model_delete_fixed_rows($dependency, $ids);
}

/**
 * Apply a reference-clearing UPDATE only for a service-confirmed available dependency.
 *
 * Missing/false keys skip before target validation; no schema probe or transaction
 * is opened. Unknown availability must have been rejected by the caller.
 *
 * @param string $dependency Fixed nullable target key used for capability lookup and SQL resolution.
 * @param list<int|string> $ids Referenced gallery/image IDs whose links should be cleared.
 * @param GalleryMutationDependencyMap $availableDependencies Complete conclusively resolved cleanup capabilities.
 * @return int Updated row count, or zero when the dependency/selection is skipped.
 * @author Rudolf Klusal
 */
function gallery_mutation_model_apply_null_dependency(string $dependency, array $ids, array $availableDependencies): int
{
    if (empty($availableDependencies[$dependency])) {
        return 0;
    }
    return gallery_mutation_model_null_fixed_rows($dependency, $ids);
}

/**
 * Delete matching dependency rows in chunks of at most 500 normalized IDs.
 *
 * Uses only the fixed identifier map and bound values. Empty input returns before
 * resolving the key. No transaction/schema gate is provided; without an outer
 * transaction, a later chunk failure can leave earlier deletions committed.
 * Installed foreign keys can cause additional cascades/SET NULL changes.
 *
 * @param string $dependency Exact allowlisted target; galleries.id also supports final gallery deletion.
 * @param list<int|string> $ids IDs matched against the target column; duplicate/nonpositive values are removed.
 * @return int Sum of direct DELETE rowCounts, not the count of selected IDs or cascaded rows.
 * @throws RuntimeException For an unsupported target with a nonempty normalized selection.
 * @author Rudolf Klusal
 */
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

/**
 * Clear matching reference columns in chunks of at most 500 normalized IDs.
 *
 * Writes SQL NULL without updating audit timestamps. When the fixed target is
 * galleries, the statement explicitly advances edit_revision; FK/caller policy
 * is not inferred.
 * Empty input bypasses target resolution. No transaction is opened, so partial
 * progress is possible after a later chunk failure unless the caller owns one.
 *
 * @param string $dependency Fixed target key; caller must choose a column that permits NULL.
 * @param list<int|string> $ids Referenced IDs to clear, normalized to unique positives.
 * @return int Sum of direct UPDATE rowCounts, excluding indirect foreign-key effects.
 * @throws RuntimeException For an unsupported target with a nonempty normalized selection.
 * @author Rudolf Klusal
 */
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
        $revisionAssignment = $table === 'galleries' ? ', `edit_revision` = `edit_revision` + 1' : '';
        $stmt = db()->prepare('UPDATE `' . $table . '` SET `' . $column . '` = NULL' . $revisionAssignment . ' WHERE `' . $column . '` IN (' . $placeholders . ')');
        $stmt->execute($chunk);
        $updated += $stmt->rowCount();
    }
    return $updated;
}
