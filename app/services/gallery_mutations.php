<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_mutations.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides reusable application logic for gallery data, media, settings, or maintenance workflows.
 *
 * Responsibilities:
 *   - Keep domain logic reusable outside controllers
 *   - Protect existing behavior with small focused functions
 *   - Return predictable values for callers
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
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-04
 */

declare(strict_types=1);

namespace Gallery\Services;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;
use function Gallery\Core\path_inside;
use function Gallery\Models\gallery_mutation_model_delete_dependency;
use function Gallery\Models\gallery_mutation_model_delete_images;
use function Gallery\Models\gallery_mutation_model_delete_subtree;
use function Gallery\Models\gallery_mutation_model_delete_subtree_in_transaction;
use function Gallery\Models\gallery_mutation_model_first_cover_candidate;
use function Gallery\Models\gallery_mutation_model_hierarchy_rows;
use function Gallery\Models\gallery_mutation_model_image_belongs_to_galleries;
use function Gallery\Models\gallery_mutation_model_image_ids_for_galleries;
use function Gallery\Models\gallery_mutation_model_max_image_sort_order;
use function Gallery\Models\gallery_mutation_model_move_images;
use function Gallery\Models\gallery_mutation_model_null_dependency;
use function Gallery\Models\gallery_mutation_model_subtree_ids;
use function Gallery\Models\gallery_mutation_model_subtree_rows;
use function Gallery\Models\gallery_mutation_model_sync_parent_map;
use function Gallery\Models\gallery_mutation_model_update_gallery_paths;

require_once __DIR__ . '/gallery_image_move_journal.php';

/**
 * Build a literal folder-path descendant pattern for SQL LIKE predicates.
 *
 * Folder names may legally contain SQL wildcard characters such as `_` and `%`.
 * Use `=` as the explicit escape character so subtree queries never interpret
 * gallery-authored path text as a pattern.
 *
 * @param string $folderPath Normalized gallery folder path.
 * @return string Escaped LIKE pattern matching descendants only.
 */
function gallery_folder_path_descendant_like_pattern(string $folderPath): string
{
    // $folderPath stores a normalized literal path before SQL wildcard escaping.
    $folderPath = normalize_relative_path($folderPath);
    // Escape the escape character first, then the two LIKE wildcards.
    $escaped = str_replace(['=', '%', '_'], ['==', '=%', '=_'], $folderPath);
    return $escaped . '/%';
}

/**
 * Read the current physical subtree rooted at an existing catalog identifier.
 *
 * This module owns filesystem-backed gallery changes: subtree deletion, folder moves, imports, ancestor creation, and parent synchronization. It intentionally keeps the filesystem as the source of truth and updates the database to follow it.
 *
 * @param int $galleryId Positive root identifier; bypasses the request-local gallery cache.
 * @return list<array<string,mixed>> Complete schema-dependent gallery rows ordered by folder_path; each row carries id and folder_path. Empty if the root no longer exists.
 */
function gallery_subtree_rows(int $galleryId): array
{
    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = find_gallery($galleryId, true);
    if (!$gallery) {
        return [];
    }
    // $folderPath stores an intermediate value used by the surrounding gallery workflow.
    $folderPath = normalize_relative_path((string) $gallery['folder_path']);
    return gallery_mutation_model_subtree_rows($folderPath, gallery_folder_path_descendant_like_pattern($folderPath));
}

/**
 * Delete explicitly selected physical subtrees while excluding concurrent editors.
 *
 * @param list<int> $galleryIds Selected roots; descendants covered by another root are folded.
 * @return array{root_count:int,row_count:int,missing_folders:int} Deleted physical roots, catalog rows and already-absent folders.
 * @throws RuntimeException When schema or storage cannot safely admit deletion.
 */
function delete_gallery_subtrees(array $galleryIds): array
{
    $writerLock = gallery_edit_writer_begin();
    try {
        return delete_gallery_subtrees_owned($galleryIds);
    } finally {
        gallery_edit_writer_end($writerLock);
    }
}

/**
 * Perform delete gallery subtrees while the caller owns the gallery writer lease.
 *
 * @param array<int,int> $galleryIds Selected subtree roots.
 * @return array{root_count:int,row_count:int,missing_folders:int} Physical/catalog deletion counts.
 */
function delete_gallery_subtrees_owned(array $galleryIds): array
{
    // Variable $rootIds stores this steps working value.
    $rootIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds))));
    if (!$rootIds) {
        return ['root_count' => 0, 'row_count' => 0, 'missing_folders' => 0];
    }

    // Refuse before database or filesystem deletion when core ownership schema cannot be verified.
    mutation_schema_assert_available(
        gallery_deletion_schema_status(),
        'gallery.delete_subtree',
        'Gallery deletion requires the current core gallery/image database schema. Run pending migrations first.',
        'Gallery deletion is temporarily unavailable because the required database schema could not be verified.'
    );

    // Variable $roots stores this steps working value.
    $roots = [];
    foreach ($rootIds as $galleryId) {
        // Variable $gallery stores this steps working value.
        $gallery = find_gallery($galleryId, true);
        if (!$gallery) {
            continue;
        }
        $roots[] = $gallery;
    }
    if (!$roots) {
        return ['root_count' => 0, 'row_count' => 0, 'missing_folders' => 0];
    }

    usort($roots, /**
     * Order ancestors before descendants so overlapping deletion roots are folded.
     * @param array{folder_path:string} $left First physical catalog row.
     * @param array{folder_path:string} $right Second physical catalog row.
     * @return int Comparison of path lengths, not lexical gallery ordering.
     */ static fn (array $left, array $right): int => strlen((string) $left['folder_path']) <=> strlen((string) $right['folder_path']));

    // Variable $keptRoots stores this steps working value.
    $keptRoots = [];
    foreach ($roots as $gallery) {
        // Variable $folderPath stores this steps working value.
        $folderPath = normalize_relative_path((string) $gallery['folder_path']);
        // Variable $isCoveredByEarlierRoot stores this steps working value.
        $isCoveredByEarlierRoot = false;
        foreach ($keptRoots as $keptRoot) {
            // Variable $keptPath stores this steps working value.
            $keptPath = normalize_relative_path((string) $keptRoot['folder_path']);
            if ($folderPath === $keptPath || str_starts_with($folderPath, $keptPath . '/')) {
                // $isCoveredByEarlierRoot stores an intermediate value used by the surrounding gallery workflow.
                $isCoveredByEarlierRoot = true;
                break;
            }
        }
        if (!$isCoveredByEarlierRoot) {
            $keptRoots[] = $gallery;
        }
    }

    // Variable $allRowIds stores this steps working value.
    $allRowIds = [];
    foreach ($keptRoots as $gallery) {
        foreach (gallery_subtree_rows((int) $gallery['id']) as $row) {
            $allRowIds[(int) $row['id']] = (int) $row['id'];
        }
    }

    // Variable $foldersToDelete stores this steps working value.
    $foldersToDelete = [];
    // Variable $missingFolders stores gallery rows whose folders are already absent.
    $missingFolders = 0;
    foreach ($keptRoots as $gallery) {
        // Variable $absolutePath stores this steps working value.
        $absolutePath = gallery_abs_path((string) $gallery['folder_path']);
        if (!is_dir($absolutePath)) {
            $missingFolders++;
            continue;
        }
        if (!path_inside(galleries_root(), $absolutePath)) {
            throw new RuntimeException('Refusing to delete a gallery path outside the gallery root.');
        }
        $foldersToDelete[] = $absolutePath;
    }

    if ($allRowIds) {
        gallery_delete_database_subtree_rows(array_values($allRowIds));
    }

    // Variable $deletedFolders stores this steps working value.
    $deletedFolders = [];
    foreach ($foldersToDelete as $absolutePath) {
        delete_directory_tree($absolutePath, galleries_root());
        $deletedFolders[] = $absolutePath;
    }

    thumbnail_maintenance_summary_cache_clear();
    sync_gallery_parent_ids();
    if (public_path_schema_ready()) {
        refresh_gallery_public_paths();
    }

    return ['root_count' => count($deletedFolders), 'row_count' => count($allRowIds), 'missing_folders' => $missingFolders];
}

/**
 * Delete gallery database rows and all known dependent records.
 *
 * Older shared-hosting installs may miss one or more foreign key constraints
 * from past migrations. This cleanup keeps gallery deletion deterministic even
 * when the database cannot rely on cascades alone.
 *
 * @param array<int> $galleryIds Gallery row ids to remove.
 * @return int Number of gallery rows deleted.
 */
function gallery_delete_database_subtree_rows(array $galleryIds): int
{
    // $galleryIds stores unique positive gallery ids accepted by cleanup.
    $galleryIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $galleryId): bool => $galleryId > 0)));
    if (!$galleryIds) {
        return 0;
    }

    mutation_schema_assert_available(
        gallery_deletion_schema_status(),
        'gallery.delete_database_subtree',
        'Gallery deletion requires the current core gallery/image database schema. Run pending migrations first.',
        'Gallery deletion is temporarily unavailable because the required database schema could not be verified.'
    );

    return gallery_mutation_model_delete_subtree($galleryIds, gallery_mutation_delete_dependency_availability());
}

/**
 * Delete gallery database rows inside an already-open transaction.
 *
 * This transaction-neutral primitive lets compound mutations, notably the
 * gallery trash bin, commit dependent-row deletion and their lifecycle state
 * transition atomically. Callers must verify schema before use and must own an
 * active transaction on the shared PDO connection.
 *
 * @param array<int> $galleryIds Gallery row ids to remove.
 * @return int Number of gallery rows deleted.
 */
function gallery_delete_database_subtree_rows_in_transaction(array $galleryIds): int
{
    // $galleryIds stores unique positive gallery ids accepted by cleanup.
    $galleryIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $galleryId): bool => $galleryId > 0)));
    if (!$galleryIds) {
        return 0;
    }

    return gallery_mutation_model_delete_subtree_in_transaction($galleryIds, gallery_mutation_delete_dependency_availability());
}

/**
 * Remove database rows for a gallery path whose folder is already absent.
 *
 * @param string $folderPath Gallery folder path.
 * @return int Number of gallery rows removed.
 */
function delete_missing_gallery_database_subtree_by_folder_path(string $folderPath): int
{
    // $folderPath stores the normalized requested gallery path.
    $folderPath = normalize_relative_path($folderPath);
    if ($folderPath === '' || is_dir(gallery_abs_path($folderPath))) {
        return 0;
    }

    // $ids stores stale gallery ids that can no longer be reached on disk.
    $ids = gallery_mutation_model_subtree_ids($folderPath, gallery_folder_path_descendant_like_pattern($folderPath), true);
    if (!$ids) {
        return 0;
    }

    return gallery_delete_database_subtree_rows($ids);
}

/**
 * Fetch image ids owned by a group of galleries.
 *
 * @param array<int> $galleryIds Gallery ids used as image owners.
 * @return array<int> Image ids.
 */
function gallery_image_ids_for_gallery_ids(array $galleryIds): array
{
    // $galleryIds stores unique positive ids accepted by the image lookup.
    $galleryIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $galleryId): bool => $galleryId > 0)));
    if (!$galleryIds) {
        return [];
    }
    mutation_schema_assert_available(
        mutation_schema_tables_status('mutation.gallery_delete_image_lookup', ['images' => ['gallery_id', 'id']]),
        'gallery.delete_image_lookup',
        'Gallery image ownership schema is incomplete. Run pending migrations first.',
        'Gallery image ownership schema could not be verified. The deletion was not started.'
    );

    return gallery_mutation_model_image_ids_for_galleries($galleryIds);
}

/**
 * Delete rows matching one id column when the table and column exist.
 *
 * @param string $table Table name.
 * @param string $column Column name.
 * @param array<int> $ids Id values.
 * @return int Deleted row count.
 */
function gallery_delete_rows_by_ids(string $table, string $column, array $ids): int
{
    // $ids stores unique positive ids accepted by this mutation.
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    if (!$ids) {
        return 0;
    }
    if (!mutation_schema_optional_table_column_available('mutation.gallery_delete_dependency', $table, $column, 'gallery.delete_dependency_rows')) {
        return 0;
    }
    return gallery_mutation_model_delete_dependency($table . '.' . $column, $ids);
}

/**
 * Set nullable foreign-key references to NULL when the table and column exist.
 *
 * @param string $table Table name.
 * @param string $column Column name.
 * @param array<int> $ids Id values.
 * @return int Updated row count.
 */
function gallery_null_rows_by_ids(string $table, string $column, array $ids): int
{
    // $ids stores unique positive ids accepted by this mutation.
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    if (!$ids) {
        return 0;
    }
    if (!mutation_schema_optional_table_column_available('mutation.gallery_delete_dependency', $table, $column, 'gallery.null_dependency_rows')) {
        return 0;
    }
    return gallery_mutation_model_null_dependency($table . '.' . $column, $ids);
}

/**
 * Resolve optional historical dependency availability for gallery cleanup models.
 *
 * Core gallery/image ownership columns are validated separately by the mutation
 * schema gate. Optional dependencies may be absent on older installations, so
 * the service owns capability policy while the model owns the fixed SQL targets.
 *
 * @return array<string,bool> Availability keyed by fixed model dependency name.
 */
function gallery_mutation_delete_dependency_availability(): array
{
    $dependencies = [
        'galleries.cover_image_id', 'galleries.parent_id',
        'telemetry_sessions.first_image_id', 'telemetry_sessions.last_image_id',
        'telemetry_events.image_id', 'telemetry_job_runs.image_id',
        'telemetry_sessions.first_gallery_id', 'telemetry_sessions.last_gallery_id',
        'telemetry_events.gallery_id', 'telemetry_job_runs.gallery_id',
        'image_thumbnail_variants.image_id', 'image_thumbnail_variants.gallery_id',
        'image_ai_analysis_jobs.image_id', 'image_ai_analysis_jobs.gallery_id',
        'image_ai_metadata.image_id',
        'picture_game_votes.image_a_id', 'picture_game_votes.image_b_id',
        'picture_game_votes.winner_image_id', 'picture_game_votes.gallery_id',
        'image_tags.image_id', 'image_votes.image_id', 'image_translations.image_id',
        'viewer_favourites.image_id', 'viewer_collection_items.image_id',
        'duplicate_photo_ledger_pairs.image_id_low', 'duplicate_photo_ledger_pairs.image_id_high',
        'gallery_flight_maps.gallery_id', 'gallery_upload_tokens.gallery_id',
        'mobile_webdav_upload_tokens.gallery_id', 'gallery_tags.gallery_id',
        'zip_archives.gallery_id', 'images.gallery_id',
    ];
    $availability = [];
    foreach ($dependencies as $dependency) {
        [$table, $column] = explode('.', $dependency, 2);
        $availability[$dependency] = mutation_schema_optional_table_column_available(
            'mutation.gallery_delete_dependency',
            $table,
            $column,
            'gallery.delete_dependency_rows'
        );
    }
    return $availability;
}

/**
 * Validate a SQL identifier used by fixed internal cleanup statements.
 *
 * @param string $identifier Table or column identifier.
 * @return string Safe SQL identifier.
 */
function gallery_mutation_sql_identifier(string $identifier): string
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
        throw new RuntimeException('Unsafe database identifier.');
    }
    return $identifier;
}

/**
 * Handles delete directory tree logic for the gallery application.
 *
 * @param mixed $directory Input used by this operation.
 * @param mixed $allowedRoot Input used by this operation.
 */
function delete_directory_tree(string $directory, string $allowedRoot): void
{
    // Variable $directory stores this steps working value.
    $directory = rtrim($directory, DIRECTORY_SEPARATOR);
    // Variable $allowedRoot stores this steps working value.
    $allowedRoot = rtrim($allowedRoot, DIRECTORY_SEPARATOR);
    if ($directory === '' || $directory === $allowedRoot || !path_inside($allowedRoot, $directory)) {
        throw new RuntimeException('Refusing to delete an unsafe gallery path.');
    }
    if (!is_dir($directory)) {
        return;
    }

    // Variable $iterator stores this steps working value.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        // Variable $path stores this steps working value.
        $path = $entry->getPathname();
        if ($entry->isLink()) {
            // Never resolve or follow a link target for deletion. The link itself is safe to
            // unlink only when its containing directory is inside the allowed managed root.
            if (!path_inside($allowedRoot, dirname($path))) {
                throw new RuntimeException('Refusing to delete a symbolic link outside the gallery root.');
            }
            if (!@unlink($path)) {
                throw new RuntimeException('Could not remove symbolic link: ' . $path);
            }
            continue;
        }
        if (!path_inside($allowedRoot, $path)) {
            throw new RuntimeException('Refusing to delete a path outside the gallery root.');
        }
        if ($entry->isDir()) {
            if (!@rmdir($path)) {
                throw new RuntimeException('Could not remove directory: ' . $path);
            }
            continue;
        }
        if (!@unlink($path)) {
            throw new RuntimeException('Could not remove file: ' . $path);
        }
    }

    if (!@rmdir($directory)) {
        throw new RuntimeException('Could not remove gallery folder: ' . $directory);
    }
}


/**
 * Return every generated derivative cache file that belongs to one image deletion.
 *
 * Exact current thumbnail paths are included first. The bounded directory scan also
 * catches stale thumbnail sizes and interrupted temporary files left by older
 * configurations. Those files can otherwise make a later auto-rename choose a
 * collision suffix or, worse, become visible again when a filename/public slug is
 * reused after deletion.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param string $galleryRoot Absolute owning gallery directory.
 * @return array<int,string> Absolute derivative paths safe to remove.
 */
function gallery_image_deletion_derivative_paths(array $image, array $gallery, string $galleryRoot): array
{
    // $paths stores normalized unique cache artifacts keyed by absolute path.
    $paths = [];
    foreach (thumbnail_sizes() as $size) {
        foreach (['jpg', 'webp'] as $format) {
            $path = thumbnail_abs_path($image, $gallery, (int) $size, $format);
            if (thumbnail_path_inside_existing_gallery($galleryRoot, $path) && is_file($path)) {
                $paths[$path] = $path;
            }
        }
    }

    if (function_exists('Gallery\\Services\\image_uses_dng_display_derivatives') && image_uses_dng_display_derivatives($image)) {
        $displayMasterPath = dng_display_master_abs_path($image, $gallery, false);
        if (thumbnail_path_inside_existing_gallery($galleryRoot, $displayMasterPath) && is_file($displayMasterPath)) {
            $paths[$displayMasterPath] = $displayMasterPath;
        }
    }

    // $thumbsDir stores the generated-cache directory. A direct scan is bounded to
    // one gallery and avoids assuming that today's thumbnail size list matches the
    // sizes that existed when an older cache file was created.
    $thumbsDir = gallery_thumbs_dir($gallery, false);
    if (!is_dir($thumbsDir) || !thumbnail_path_inside_existing_gallery($galleryRoot, $thumbsDir)) {
        return array_values($paths);
    }

    // $stem stores the exact readable filename prefix used by thumbnail_filename().
    $stem = pathinfo((string) ($image['filename'] ?? ''), PATHINFO_FILENAME);
    if ($stem === '') {
        return array_values($paths);
    }
    // $thumbnailPattern matches current/legacy configured sizes plus interrupted
    // atomic-write temporary files such as name_thumb300.jpg.<token>.tmp.jpg.
    $thumbnailPattern = '/^' . preg_quote($stem, '/') . '_thumb\\d+\\.(?:jpg|webp)(?:\\.[A-Fa-f0-9]+\\.tmp\\.(?:jpg|webp))?$/i';
    // $dngPattern catches a DNG display master for this exact image id even when a
    // future cleanup runs after the source MIME metadata has become incomplete.
    $dngPattern = '/^' . preg_quote($stem, '/') . '_display_' . max(0, (int) ($image['id'] ?? 0)) . '\\.webp$/i';

    try {
        $iterator = new \DirectoryIterator($thumbsDir);
        foreach ($iterator as $entry) {
            if (!$entry->isFile() || $entry->isLink()) {
                continue;
            }
            $filename = $entry->getFilename();
            if (preg_match($thumbnailPattern, $filename) !== 1 && preg_match($dngPattern, $filename) !== 1) {
                continue;
            }
            $path = $entry->getPathname();
            if (thumbnail_path_inside_existing_gallery($galleryRoot, $path)) {
                $paths[$path] = $path;
            }
        }
    } catch (Throwable) {
        // Exact configured paths above remain authoritative when directory
        // enumeration itself is unavailable on a constrained shared host.
    }

    return array_values($paths);
}

/**
 * Stage one live gallery file under a non-media filename before database deletion.
 *
 * Renaming inside the same directory is used as the filesystem transaction step.
 * If a later database mutation fails, the staged file can be restored. If final
 * unlink cleanup fails after commit, the hidden staged filename is no longer a
 * supported image/thumbnail path and therefore cannot be rescanned or served.
 *
 * @param string $path Live file path.
 * @param string $galleryRoot Absolute owning gallery directory.
 * @return array{original:string,staged:string} Staged file mapping.
 */
function gallery_stage_file_for_deletion(string $path, string $galleryRoot): array
{
    if (!thumbnail_path_inside_existing_gallery($galleryRoot, $path) || !is_file($path)) {
        throw new RuntimeException('Refusing to stage a deletion file outside its gallery.');
    }

    $directory = dirname($path);
    $basename = basename($path);
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $staged = $directory . DIRECTORY_SEPARATOR . '.' . $basename . '.delete-' . bin2hex(random_bytes(6));
        if (file_exists($staged)) {
            continue;
        }
        if (@rename($path, $staged)) {
            return ['original' => $path, 'staged' => $staged];
        }
        throw new RuntimeException('Could not stage file for deletion: ' . $basename);
    }

    throw new RuntimeException('Could not allocate a safe deletion staging filename for: ' . $basename);
}

/**
 * Restore staged deletion files after a database failure.
 *
 * @param array<int,array{original:string,staged:string}> $stagedFiles Staged file mappings.
 */
function gallery_restore_staged_deletion_files(array $stagedFiles): void
{
    foreach (array_reverse($stagedFiles) as $entry) {
        $staged = (string) ($entry['staged'] ?? '');
        $original = (string) ($entry['original'] ?? '');
        if ($staged === '' || $original === '' || !is_file($staged)) {
            continue;
        }
        @rename($staged, $original);
    }
}

/**
 * Permanently remove staged deletion files after the database commit.
 *
 * Failed final unlinks are counted but intentionally not restored. Their staged
 * filenames are non-media cache trash and leaving them quarantined is safer than
 * resurrecting a deleted original or stale thumbnail path.
 *
 * @param array<int,array{original:string,staged:string}> $stagedFiles Staged file mappings.
 * @return int Number of quarantine files that could not be physically unlinked.
 */
function gallery_finalize_staged_deletion_files(array $stagedFiles): int
{
    $failed = 0;
    foreach ($stagedFiles as $entry) {
        $staged = (string) ($entry['staged'] ?? '');
        if ($staged === '' || !is_file($staged)) {
            continue;
        }
        if (!@unlink($staged)) {
            $failed++;
        }
    }
    return $failed;
}

/**
 * Delete selected original image files, generated derivatives, and image rows from one gallery.
 *
 * The original media files are removed from disk because the gallery folder is
 * the source of truth. Generated thumbnails and DNG display masters are cleaned
 * at the same time so stale previews do not remain after rescans.
 *
 * @param int $galleryId Gallery that must own every selected image.
 * @param array<int> $imageIds Image ids submitted by the admin UI.
 * @return array{requested:int,deleted:int,files_deleted:int,derivatives_deleted:int,missing_files:int,cleanup_failed:int} Structured result data for the caller.
 */
function delete_gallery_images(int $galleryId, array $imageIds): array
{
    $writerLock = gallery_edit_writer_begin();
    try {
        return delete_gallery_images_owned($galleryId, $imageIds);
    } finally {
        gallery_edit_writer_end($writerLock);
    }
}

/**
 * Perform delete gallery images while the caller owns the gallery writer lease.
 *
 * @param int $galleryId Owning gallery.
 * @param array<int,int> $imageIds Selected image identifiers.
 * @return array{requested:int,deleted:int,files_deleted:int,derivatives_deleted:int,missing_files:int,cleanup_failed:int} Database, original, derivative and retained-quarantine counts.
 */
function delete_gallery_images_owned(int $galleryId, array $imageIds): array
{
    // $normalizedIds stores the unique positive image ids selected by the admin.
    $normalizedIds = array_values(array_unique(array_filter(array_map('intval', $imageIds), /**
     * Exclude nonpositive image identifiers before scoped deletion lookup.
     * @param int $imageId Integer-normalized submitted identifier.
     * @return bool Whether this identifier can name a persisted image.
     */ static fn (int $imageId): bool => $imageId > 0)));
    if (!$normalizedIds) {
        return ['requested' => 0, 'deleted' => 0, 'files_deleted' => 0, 'derivatives_deleted' => 0, 'missing_files' => 0, 'cleanup_failed' => 0];
    }

    mutation_schema_assert_available(
        gallery_deletion_schema_status(),
        'gallery.delete_images',
        'Image deletion requires the current core gallery/image database schema. Run pending migrations first.',
        'Image deletion is temporarily unavailable because the required database schema could not be verified.'
    );

    // $gallery stores the parent gallery row used for path safety and sidecar updates.
    $gallery = find_gallery($galleryId, true);
    if (!$gallery) {
        throw new RuntimeException('Gallery not found.');
    }

    // $images stores only rows owned by the requested gallery.
    $images = [];
    foreach ($normalizedIds as $imageId) {
        // $image stores one selected database row.
        $image = find_image($imageId, true);
        if ($image && (int) $image['gallery_id'] === $galleryId) {
            $images[] = $image;
        }
    }
    if (!$images) {
        return ['requested' => count($normalizedIds), 'deleted' => 0, 'files_deleted' => 0, 'derivatives_deleted' => 0, 'missing_files' => 0, 'cleanup_failed' => 0];
    }

    // $galleryRoot stores the allowed filesystem boundary for originals and derivatives.
    $galleryRoot = gallery_abs_path((string) $gallery['folder_path']);
    if (!is_dir($galleryRoot)) {
        throw new RuntimeException('Gallery folder does not exist on disk.');
    }

    // $originalPaths stores original files that must disappear from their live
    // names before the database rows can be committed as deleted.
    $originalPaths = [];
    // $derivativePaths stores both current and stale generated cache artifacts.
    $derivativePaths = [];
    // $missingFiles stores how many selected original paths are already absent on disk.
    $missingFiles = 0;

    foreach ($images as $image) {
        // $originalPath stores the absolute path for the image source file.
        $originalPath = image_abs_path($image, $gallery);
        if (!thumbnail_path_inside_existing_gallery($galleryRoot, $originalPath)) {
            throw new RuntimeException('Refusing to delete an image outside its gallery.');
        }
        if (is_file($originalPath)) {
            $originalPaths[$originalPath] = $originalPath;
        } else {
            $missingFiles++;
        }

        foreach (gallery_image_deletion_derivative_paths($image, $gallery, $galleryRoot) as $derivativePath) {
            $derivativePaths[$derivativePath] = $derivativePath;
        }
    }

    // $stagedFiles stores every active path moved out of service before SQL changes.
    $stagedFiles = [];
    // $stagedOriginalCount counts originals removed from their live gallery paths.
    $stagedOriginalCount = 0;
    // $stagedDerivativeCount counts generated cache files removed from live paths.
    $stagedDerivativeCount = 0;
    try {
        foreach (array_values($derivativePaths) as $path) {
            if (!is_file($path)) {
                continue;
            }
            $stagedFiles[] = gallery_stage_file_for_deletion($path, $galleryRoot);
            $stagedDerivativeCount++;
        }
        foreach (array_values($originalPaths) as $path) {
            if (!is_file($path)) {
                continue;
            }
            $stagedFiles[] = gallery_stage_file_for_deletion($path, $galleryRoot);
            $stagedOriginalCount++;
        }
    } catch (Throwable $exception) {
        gallery_restore_staged_deletion_files($stagedFiles);
        throw $exception;
    }

    // $imageIdsToDelete stores the actual database rows that will be removed.
    $imageIdsToDelete = array_map(/**
     * Project already scoped image rows into the model's deletion identifiers.
     * @param array{id:int|string} $image Image row verified against the requested gallery.
     * @return int Positive persisted identifier.
     */ static fn (array $image): int => (int) $image['id'], $images);
    try {
        // $deletedRows stores the number of rows removed from images after dependency cleanup.
        $deletedRows = gallery_mutation_model_delete_images(
            $galleryId,
            $imageIdsToDelete,
            gallery_mutation_delete_dependency_availability()
        );
    } catch (Throwable $exception) {
        gallery_restore_staged_deletion_files($stagedFiles);
        throw $exception;
    }

    // $cleanupFailed counts hidden quarantine files that could not be unlinked.
    // They no longer occupy a live original or thumbnail path and cannot be served.
    $cleanupFailed = gallery_finalize_staged_deletion_files($stagedFiles);

    thumbnail_maintenance_summary_cache_clear();
    if (public_path_schema_ready()) {
        regenerate_gallery_image_public_slugs($galleryId);
    }
    // $updatedGallery stores the refreshed row after title-picture cleanup.
    $updatedGallery = find_gallery($galleryId, true);
    if ($updatedGallery) {
        write_gallery_sidecar($updatedGallery);
    }

    return [
        'requested' => count($normalizedIds),
        'deleted' => (int) $deletedRows,
        'files_deleted' => $stagedOriginalCount,
        'derivatives_deleted' => $stagedDerivativeCount,
        'missing_files' => $missingFiles,
        'cleanup_failed' => $cleanupFailed,
    ];
}

/**
 * Move selected original image files, generated thumbnails, and display derivatives to another gallery.
 *
 * Durable intent precedes physical movement. The ownership transaction also
 * writes its commit marker, allowing a later worker to finish or roll back
 * without guessing whether an interrupted request reached COMMIT. Conflicting
 * image moves are serialized; existing destinations are never overwritten.
 *
 * @param int $sourceGalleryId Gallery that currently owns the selected images.
 * @param int $destinationGalleryId Gallery that will receive the selected images.
 * @param array<int> $imageIds Image ids submitted by the admin UI.
 * @param array<string,mixed> $options Internal options; optional checkpoint callbacks are never HTTP input.
 * @return array{requested:int,moved:int,originals_moved:int,derivatives_moved:int,failures:array<int,string>,source_cover_image_id:int|null,destination_cover_image_id:int|null} Structured result data for the caller.
 */
function move_gallery_images(int $sourceGalleryId, int $destinationGalleryId, array $imageIds, array $options = []): array
{
    mutation_schema_assert_available(gallery_image_move_journal_schema_status(), 'gallery.move_images');
    $writerLock = gallery_edit_writer_begin();
    $phase = 'gallery_lock';
    $locks = [];
    try {
        $locks = \Gallery\Models\gallery_image_move_model_lock([$sourceGalleryId, $destinationGalleryId]);
        $phase = 'pending_check';
        \Gallery\Models\gallery_image_move_model_assert_idle($sourceGalleryId, $destinationGalleryId);
        $phase = 'move_preflight';
        return gallery_move_images_locked($sourceGalleryId, $destinationGalleryId, $imageIds, $options);
    } catch (Throwable $exception) {
        $diagnostic = $exception instanceof ImageMoveDiagnosticFailure ? $exception : null;
        $safePhase = $diagnostic?->phase ?? $phase;
        $reason = $diagnostic?->reason ?? match ($exception->getMessage()) {
            'An earlier image move needs reconciliation before another move can use this gallery.' => 'pending_operation',
            'Another image move or recovery is using this gallery.' => 'gallery_busy',
            'Source or destination gallery was not found.' => 'gallery_missing',
            'Source or destination gallery folder does not exist on disk.' => 'gallery_folder_missing',
            'Choose a different destination gallery.' => 'same_gallery',
            default => 'operation_unavailable',
        };
        if (function_exists('Gallery\\Services\\admin_log_event')) {
            try {
                admin_log_event('warning', 'gallery.image_move_failed', 'Image move failed; file and exception details are in this Admin log entry.', [
                    'source_gallery_id' => $sourceGalleryId,
                    'destination_gallery_id' => $destinationGalleryId,
                    'requested_images' => count($imageIds),
                    'phase' => $safePhase,
                    'reason' => $reason,
                    'operation_id' => $diagnostic?->operationId,
                    'file_number' => $diagnostic?->fileNumber,
                    'file_kind' => $diagnostic?->fileKind,
                    'debug' => gallery_image_move_exception_context($exception),
                ], ['category' => 'media', 'severity' => 'warning']);
            } catch (Throwable) {
                // Logging failure must not replace the safe move diagnostic.
            }
        }
        $message = $diagnostic?->getMessage()
            ?? 'Image move failed at ' . str_replace('_', ' ', $safePhase) . ': ' . gallery_image_move_reason_description($reason)
                . '. Inspect pending image-move operations before retrying.';
        throw new RuntimeException($message, 0, $exception);
    } finally {
        try {
            gallery_image_move_release_locks($locks);
        } finally {
            gallery_edit_writer_end($writerLock);
        }
    }
}

/**
 * Validate and execute one image move while its caller owns both gallery locks.
 *
 * @param int $sourceGalleryId Source gallery identifier.
 * @param int $destinationGalleryId Destination gallery identifier.
 * @param array<int,int> $imageIds Submitted image identifiers.
 * @param array<string,mixed> $options Internal orchestration options, including an optional test checkpoint.
 * @return array<string,mixed> Existing mutation result plus its durable operation identifier.
 */
function gallery_move_images_locked(int $sourceGalleryId, int $destinationGalleryId, array $imageIds, array $options = []): array
{
    mutation_schema_assert_available(
        gallery_move_schema_status(),
        'gallery.move_images',
        'Image moves require the current gallery/image ownership schema. Run pending migrations first.',
        'Image moves are temporarily unavailable because the required database schema could not be verified.'
    );

    // $normalizedIds stores the unique positive image ids selected by the admin.
    $normalizedIds = array_values(array_unique(array_filter(array_map('intval', $imageIds), /**
     * Exclude nonpositive image identifiers before preparing a durable move.
     * @param int $imageId Integer-normalized submitted identifier.
     * @return bool Whether the identifier is eligible for scoped lookup.
     */ static fn (int $imageId): bool => $imageId > 0)));
    if (!$normalizedIds) {
        return [
            'requested' => 0,
            'moved' => 0,
            'originals_moved' => 0,
            'derivatives_moved' => 0,
            'failures' => [],
            'source_cover_image_id' => null,
            'destination_cover_image_id' => null,
        ];
    }
    if ($sourceGalleryId === $destinationGalleryId) {
        throw new RuntimeException('Choose a different destination gallery.');
    }

    // $sourceGallery stores the gallery that currently owns the selected rows.
    $sourceGallery = find_gallery($sourceGalleryId, true);
    // $destinationGallery stores the gallery that will receive the selected rows.
    $destinationGallery = find_gallery($destinationGalleryId, true);
    if (!$sourceGallery || !$destinationGallery) {
        throw new RuntimeException('Source or destination gallery was not found.');
    }

    // $sourceRoot stores the filesystem boundary for current originals and derivatives.
    $sourceRoot = gallery_abs_path((string) $sourceGallery['folder_path']);
    // $destinationRoot stores the filesystem boundary for moved originals and derivatives.
    $destinationRoot = gallery_abs_path((string) $destinationGallery['folder_path']);
    if (!is_dir($sourceRoot) || !is_dir($destinationRoot)) {
        throw new RuntimeException('Source or destination gallery folder does not exist on disk.');
    }

    // $images stores validated image rows in the requested visual order.
    $images = [];
    // $failures stores per-image validation failures reported without touching disk.
    $failures = [];
    foreach ($normalizedIds as $imageId) {
        // $image stores one selected database row.
        $image = find_image($imageId, true);
        if (!$image || (int) $image['gallery_id'] !== $sourceGalleryId) {
            $failures[] = 'Image #' . $imageId . ' is not part of the source gallery.';
            continue;
        }
        $images[] = $image;
    }
    if (!$images) {
        return [
            'requested' => count($normalizedIds),
            'moved' => 0,
            'originals_moved' => 0,
            'derivatives_moved' => 0,
            'failures' => $failures,
            'source_cover_image_id' => null,
            'destination_cover_image_id' => null,
        ];
    }

    usort($images, /**
     * Preserve displayed source order, then filename and ID, during destination append.
     * @param array{id?:int|string,sort_order?:int|string,filename?:string} $left First validated image row.
     * @param array{id?:int|string,sort_order?:int|string,filename?:string} $right Second validated image row.
     * @return int Stable comparison using order, filename and persisted identity.
     */ static function (array $left, array $right): int {
        // $sortCompare keeps moved images in the same relative order the admin sees in the source gallery.
        $sortCompare = (int) ($left['sort_order'] ?? 0) <=> (int) ($right['sort_order'] ?? 0);
        if ($sortCompare !== 0) {
            return $sortCompare;
        }
        // $nameCompare gives a stable fallback when several rows share one order value.
        $nameCompare = strcmp((string) ($left['filename'] ?? ''), (string) ($right['filename'] ?? ''));
        if ($nameCompare !== 0) {
            return $nameCompare;
        }
        return (int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0);
    });

    // $manifest stores every physical rename required for originals and generated files.
    $manifest = [];
    // $targetPaths stores target paths so collisions inside the selected set fail before any rename.
    $targetPaths = [];
    foreach ($images as $image) {
        // $imageLabel stores a readable name for failure messages.
        $imageLabel = (string) ($image['relative_path'] ?: $image['filename'] ?: ('#' . (int) $image['id']));
        // $relativePath stores the same path under the destination gallery.
        $relativePath = normalize_relative_path((string) $image['relative_path']);
        if ($relativePath === '') {
            $failures[] = $imageLabel . ': image relative path is empty.';
            continue;
        }
        if (find_image_by_path($destinationGalleryId, $relativePath)) {
            $failures[] = $imageLabel . ': destination gallery already has a database record with this path.';
            continue;
        }

        try {
            // $sourceOriginal stores the current original file location.
            $sourceOriginal = image_abs_path($image, $sourceGallery);
            // $destinationOriginal stores the future original file location.
            $destinationOriginal = gallery_image_target_abs_path($image, $destinationGallery);
        } catch (Throwable $exception) {
            $failures[] = $imageLabel . ': ' . $exception->getMessage();
            continue;
        }

        if (!thumbnail_path_inside_existing_gallery($sourceRoot, $sourceOriginal)) {
            $failures[] = $imageLabel . ': source path is outside its gallery.';
            continue;
        }
        if (!thumbnail_path_inside_existing_gallery($destinationRoot, $destinationOriginal)) {
            $failures[] = $imageLabel . ': destination path is outside its gallery.';
            continue;
        }
        if (!is_file($sourceOriginal)) {
            $failures[] = $imageLabel . ': original file is missing on disk.';
            continue;
        }
        if (file_exists($destinationOriginal)) {
            $failures[] = $imageLabel . ': destination original file already exists.';
            continue;
        }
        gallery_add_image_move_manifest_entry($manifest, $targetPaths, $sourceOriginal, $destinationOriginal, 'original', $imageLabel, $failures);

        try {
            // $derivatives stores generated files already present on disk for this image.
            $derivatives = gallery_image_derivative_move_paths($image, $sourceGallery, $destinationGallery, $sourceRoot, $destinationRoot);
        } catch (Throwable $exception) {
            $failures[] = $imageLabel . ': ' . $exception->getMessage();
            continue;
        }
        foreach ($derivatives as $derivative) {
            gallery_add_image_move_manifest_entry(
                $manifest,
                $targetPaths,
                (string) $derivative['from'],
                (string) $derivative['to'],
                'derivative',
                $imageLabel,
                $failures
            );
        }
    }

    if ($failures) {
        return [
            'requested' => count($normalizedIds),
            'moved' => 0,
            'originals_moved' => 0,
            'derivatives_moved' => 0,
            'failures' => $failures,
            'source_cover_image_id' => null,
            'destination_cover_image_id' => null,
        ];
    }

    $imageIdsToMove = array_map(/**
     * Capture the validated ownership set in the durable intent.
     * @param array{id:int|string} $image Source-scoped image row.
     * @return int Persisted identifier recorded before filesystem changes.
     */ static fn (array $image): int => (int) $image['id'], $images);
    $operationId = gallery_image_move_prepare($sourceGallery, $destinationGallery, $imageIdsToMove, $manifest);
    $checkpoint = isset($options['checkpoint']) && is_callable($options['checkpoint']) ? $options['checkpoint'] : null;
    if ($checkpoint !== null) {
        $checkpoint('prepared', $operationId, 0);
    }
    try {
        $movedFiles = gallery_image_move_execute_files($operationId, $checkpoint);
    } catch (Throwable $exception) {
        gallery_image_move_recover_locked($operationId);
        throw $exception;
    }

    // $imageIdsToMove stores validated row IDs for the database update.
    $imageIdsToMove = array_map(/**
     * Pass the same validated ownership set to the atomic database move.
     * @param array{id:int|string} $image Source-scoped image row retained under the writer lock.
     * @return int Persisted identifier already recorded in the move intent.
     */ static fn (array $image): int => (int) $image['id'], $images);
    // $destinationSortOrders stores append-style order values assigned in the destination gallery.
    try {
        $destinationSortOrders = gallery_destination_sort_orders($destinationGalleryId, $imageIdsToMove);
        // $moveResult stores the atomic ownership and title-picture update result.
        $moveResult = gallery_mutation_model_move_images(
            $sourceGalleryId,
            $destinationGalleryId,
            $imageIdsToMove,
            $destinationSortOrders,
            gallery_subtree_ids($destinationGalleryId),
            now_sql(),
            $operationId
        );
        $updatedRows = (int) $moveResult['moved'];
        $sourceCoverImageId = $moveResult['source_cover_image_id'];
        $destinationCoverImageId = $moveResult['destination_cover_image_id'];
    } catch (Throwable $exception) {
        // Recovery reads the transaction's durable marker; an uncertain COMMIT is never guessed.
        gallery_image_move_recover_locked($operationId);
        throw new ImageMoveDiagnosticFailure('database_commit', 'ownership_update_failed', $operationId, previous: $exception);
    }

    if ($checkpoint !== null) {
        $checkpoint('database_committed', $operationId, count($movedFiles));
    }
    // Finalize metadata even for bulk callers: a killed outer request must remain recoverable.
    gallery_image_move_recover_locked($operationId);

    // $originalsMoved stores moved original media files.
    $originalsMoved = count(array_filter($movedFiles, /**
     * Count completed original-file transfers independently of generated derivatives.
     * @param array{kind:string} $entry Executed manifest entry.
     * @return bool Whether the entry identifies an original.
     */ static fn (array $entry): bool => (string) $entry['kind'] === 'original'));
    // $derivativesMoved stores moved generated files.
    $derivativesMoved = count(array_filter($movedFiles, /**
     * Count completed derivative transfers independently of originals.
     * @param array{kind:string} $entry Executed manifest entry.
     * @return bool Whether the entry identifies a derivative.
     */ static fn (array $entry): bool => (string) $entry['kind'] === 'derivative'));

    return [
        'requested' => count($normalizedIds),
        'moved' => (int) $updatedRows,
        'operation_id' => $operationId,
        'originals_moved' => $originalsMoved,
        'derivatives_moved' => $derivativesMoved,
        'failures' => [],
        'source_cover_image_id' => $sourceCoverImageId,
        'destination_cover_image_id' => $destinationCoverImageId,
    ];
}

/**
 * Resolve a destination image path without requiring nested target directories to exist yet.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @return string Text result for the caller.
 */
function gallery_image_target_abs_path(array $image, array $gallery): string
{
    // $galleryRoot stores the receiving gallery directory.
    $galleryRoot = gallery_abs_path((string) $gallery['folder_path']);
    // $relativePath stores the image path relative to the receiving gallery directory.
    $relativePath = normalize_relative_path((string) $image['relative_path']);
    if ($relativePath === '') {
        throw new RuntimeException('Image path is empty.');
    }
    // $targetPath stores the future absolute path for the moved original.
    $targetPath = $galleryRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!thumbnail_path_inside_existing_gallery($galleryRoot, $targetPath)) {
        throw new RuntimeException('Destination image path is outside its gallery.');
    }
    return $targetPath;
}

/**
 * Add one file rename to a move manifest and report target collisions early.
 *
 * @param array<int,array{from:string,to:string,kind:string}> $manifest Mutable list of file renames.
 * @param array<string,string> $targetPaths Target paths already used by this move.
 * @param string $sourcePath Source filesystem path.
 * @param string $destinationPath Destination path filesystem path.
 * @param string $kind Kind value.
 * @param string $imageLabel Image label value.
 * @param array<int,string> $failures Mutable validation errors.
 */
function gallery_add_image_move_manifest_entry(array &$manifest, array &$targetPaths, string $sourcePath, string $destinationPath, string $kind, string $imageLabel, array &$failures): void
{
    // $normalizedDestination stores a platform-consistent key for duplicate target detection.
    $normalizedDestination = normalize_filesystem_path($destinationPath);
    if (isset($targetPaths[$normalizedDestination])) {
        $failures[] = $imageLabel . ': generated file target conflicts with ' . $targetPaths[$normalizedDestination] . '.';
        return;
    }
    if (file_exists($destinationPath)) {
        $failures[] = $imageLabel . ': destination file already exists: ' . basename($destinationPath) . '.';
        return;
    }
    $targetPaths[$normalizedDestination] = $imageLabel;
    $manifest[] = ['from' => $sourcePath, 'to' => $destinationPath, 'kind' => $kind];
}

/**
 * Return generated files that should move with one source image.
 *
 * @param array $image Image row or image data.
 * @param array $sourceGallery Source gallery value.
 * @param array $destinationGallery Destination gallery value.
 * @param string $sourceRoot Source root value.
 * @param string $destinationRoot Destination root value.
 * @return array<int,array{from:string,to:string}> Structured result data for the caller.
 */
function gallery_image_derivative_move_paths(array $image, array $sourceGallery, array $destinationGallery, string $sourceRoot, string $destinationRoot): array
{
    // $paths stores derivative file renames that are present on disk.
    $paths = [];
    foreach (thumbnail_sizes() as $size) {
        foreach (['jpg', 'webp'] as $format) {
            // $sourceThumbnail stores one generated thumbnail path.
            $sourceThumbnail = thumbnail_abs_path($image, $sourceGallery, (int) $size, $format);
            // $destinationThumbnail stores the matching generated thumbnail path in the receiving gallery.
            $destinationThumbnail = thumbnail_abs_path($image, $destinationGallery, (int) $size, $format);
            if (!thumbnail_path_inside_existing_gallery($destinationRoot, $destinationThumbnail)) {
                throw new RuntimeException('Destination thumbnail path is outside its gallery.');
            }
            if (file_exists($destinationThumbnail)) {
                throw new RuntimeException('Destination generated file already exists: ' . basename($destinationThumbnail) . '.');
            }
            if (!thumbnail_path_inside_existing_gallery($sourceRoot, $sourceThumbnail) || !is_file($sourceThumbnail)) {
                continue;
            }
            $paths[] = ['from' => $sourceThumbnail, 'to' => $destinationThumbnail];
        }
    }

    if (function_exists('Gallery\\Services\\image_uses_dng_display_derivatives') && image_uses_dng_display_derivatives($image)) {
        // $sourceDisplayMaster stores the generated full-size WebP display derivative.
        $sourceDisplayMaster = dng_display_master_abs_path($image, $sourceGallery, false);
        if (thumbnail_path_inside_existing_gallery($sourceRoot, $sourceDisplayMaster) && is_file($sourceDisplayMaster)) {
            // $destinationDisplayMaster stores the matching DNG display derivative in the receiving gallery.
            $destinationDisplayMaster = dng_display_master_abs_path($image, $destinationGallery, false);
            if (!thumbnail_path_inside_existing_gallery($destinationRoot, $destinationDisplayMaster)) {
                throw new RuntimeException('Destination DNG display derivative path is outside its gallery.');
            }
            if (file_exists($destinationDisplayMaster)) {
                throw new RuntimeException('Destination DNG display derivative already exists.');
            }
            $paths[] = ['from' => $sourceDisplayMaster, 'to' => $destinationDisplayMaster];
        }
    }

    return $paths;
}

/**
 * Move already-renamed files back to their original locations after a failed operation.
 *
 * @param array<int,array{from:string,to:string,kind:string}> $movedFiles File moves completed before failure.
 */
function gallery_rollback_image_file_moves(array $movedFiles): void
{
    for ($index = count($movedFiles) - 1; $index >= 0; $index--) {
        // $entry stores one file that should be restored to the source path.
        $entry = $movedFiles[$index];
        if (is_file((string) $entry['to']) && !is_file((string) $entry['from'])) {
            @mkdir(dirname((string) $entry['from']), 0775, true);
            @rename((string) $entry['to'], (string) $entry['from']);
        }
    }
}

/**
 * Build destination sort_order values by appending moved images after current destination images.
 *
 * @param int $destinationGalleryId Destination gallery id identifier.
 * @param array<int> $imageIdsToMove Validated image ids in source order.
 * @return array<int,int> Structured result data for the caller.
 */
function gallery_destination_sort_orders(int $destinationGalleryId, array $imageIdsToMove): array
{
    // $nextSortOrder stores the first appended order number.
    $nextSortOrder = gallery_mutation_model_max_image_sort_order($destinationGalleryId) + 10;
    // $orders stores a sort_order value for each moved image id.
    $orders = [];
    foreach ($imageIdsToMove as $imageId) {
        $orders[(int) $imageId] = $nextSortOrder;
        $nextSortOrder += 10;
    }
    return $orders;
}

/**
 * Choose the source gallery title picture after selected images leave.
 *
 * @param int $sourceGalleryId Source gallery id identifier.
 * @param array<int> $movedImageIds Validated image ids that are being moved away.
 * @return ?int Integer result for the caller.
 */
function gallery_cover_id_after_source_move(int $sourceGalleryId, array $movedImageIds): ?int
{
    // $gallery stores the source row whose current cover determines whether reassignment is needed.
    $gallery = find_gallery($sourceGalleryId, true);
    if (!$gallery || empty($gallery['cover_image_id'])) {
        return null;
    }
    if (!in_array((int) $gallery['cover_image_id'], $movedImageIds, true)) {
        return (int) $gallery['cover_image_id'];
    }
    return gallery_first_cover_candidate_excluding($sourceGalleryId, $movedImageIds);
}

/**
 * Choose a valid destination title picture without overwriting an existing valid one.
 *
 * @param int $destinationGalleryId Destination gallery id identifier.
 * @return ?int Integer result for the caller.
 */
function gallery_cover_id_after_destination_move(int $destinationGalleryId): ?int
{
    // $gallery stores the destination row after image ownership transfer.
    $gallery = find_gallery($destinationGalleryId, true);
    if (!$gallery) {
        return null;
    }
    // $currentCoverId stores the existing title picture value, if any.
    $currentCoverId = (int) ($gallery['cover_image_id'] ?? 0);
    if ($currentCoverId > 0 && gallery_image_belongs_to_gallery_branch($currentCoverId, $destinationGalleryId)) {
        return $currentCoverId;
    }
    return gallery_first_cover_candidate_excluding($destinationGalleryId, []);
}

/**
 * Return the first direct image that can be used as a gallery title picture.
 *
 * @param int $galleryId Gallery identifier.
 * @param array<int> $excludedImageIds Image ids not eligible for the result.
 * @return ?int Integer result for the caller.
 */
function gallery_first_cover_candidate_excluding(int $galleryId, array $excludedImageIds): ?int
{
    return gallery_mutation_model_first_cover_candidate($galleryId, $excludedImageIds);
}

/**
 * Check whether an image currently belongs to a gallery or one of its descendants.
 *
 * @param int $imageId Image identifier.
 * @param int $galleryId Gallery identifier.
 * @return bool True when the condition matches.
 */
function gallery_image_belongs_to_gallery_branch(int $imageId, int $galleryId): bool
{
    // $galleryIds stores the receiving gallery and all descendant galleries accepted by title-picture selection.
    $galleryIds = gallery_subtree_ids($galleryId);
    return gallery_mutation_model_image_belongs_to_galleries($imageId, $galleryIds);
}

/**
 * Relocate a physical subtree under the gallery writer lease and rewrite its catalog paths.
 *
 * @param int $galleryId Existing physical subtree root.
 * @param ?int $parentId New parent, or null/zero for gallery storage root.
 * @param ?string $folderName Optional requested leaf name; null retains the current leaf.
 * @param bool $smartGalleryGraphPrevalidated True only for a caller that already validated the complete final parent map and will run final hierarchy maintenance.
 * @return array{moved:bool,from:string,to:string,galleries:int} Relative source/target paths and rewritten row count; moved=false is a no-op.
 */
function move_gallery_folder_to_parent(int $galleryId, ?int $parentId, ?string $folderName = null, bool $smartGalleryGraphPrevalidated = false): array
{
    $writerLock = gallery_edit_writer_begin();
    try {
        return move_gallery_folder_to_parent_owned($galleryId, $parentId, $folderName, $smartGalleryGraphPrevalidated);
    } finally {
        gallery_edit_writer_end($writerLock);
    }
}

/**
 * Perform move gallery folder to parent while the caller owns the gallery writer lease.
 *
 * @param int $galleryId Gallery subtree to relocate.
 * @param ?int $parentId Destination parent or root.
 * @param ?string $folderName Optional replacement leaf directory name.
 * @param bool $smartGalleryGraphPrevalidated Whether the caller already validated the attachment graph.
 * @return array{moved:bool,from:string,to:string,galleries:int} Physical move result and affected hierarchy count.
 */
function move_gallery_folder_to_parent_owned(int $galleryId, ?int $parentId, ?string $folderName = null, bool $smartGalleryGraphPrevalidated = false): array
{
    mutation_schema_assert_available(
        gallery_move_schema_status(),
        'gallery.move_folder',
        'Gallery folder moves require the current gallery/image ownership schema. Run pending migrations first.',
        'Gallery folder moves are temporarily unavailable because the required database schema could not be verified.'
    );

    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = find_gallery($galleryId, true);
    if (!$gallery) {
        throw new RuntimeException('Gallery not found.');
    }
    // $oldPath stores an intermediate value used by the surrounding gallery workflow.
    $oldPath = normalize_relative_path((string) $gallery['folder_path']);
    // $oldAbs stores an intermediate value used by the surrounding gallery workflow.
    $oldAbs = gallery_abs_path($oldPath);
    if (!is_dir($oldAbs)) {
        throw new RuntimeException('Current gallery folder does not exist on disk.');
    }

    // $parent stores an intermediate value used by the surrounding gallery workflow.
    $parent = $parentId !== null && $parentId > 0 ? find_gallery($parentId, true) : null;
    if ($parentId !== null && $parentId > 0 && !$parent) {
        throw new RuntimeException('Selected parent gallery does not exist.');
    }
    if ($parent && (int) $parent['id'] === $galleryId) {
        throw new RuntimeException('A gallery cannot be moved under itself.');
    }
    if ($parent) {
        // $parentPath stores an intermediate value used by the surrounding gallery workflow.
        $parentPath = normalize_relative_path((string) $parent['folder_path']);
        if ($parentPath === $oldPath || str_starts_with($parentPath . '/', $oldPath . '/')) {
            throw new RuntimeException('A gallery cannot be moved under one of its own subgalleries.');
        }
        if (!is_dir(gallery_abs_path($parentPath))) {
            throw new RuntimeException('Selected parent folder does not exist on disk.');
        }
    }
    if (!$smartGalleryGraphPrevalidated && function_exists(__NAMESPACE__ . '\\smart_gallery_validate_gallery_parent_change')) {
        smart_gallery_validate_gallery_parent_change($galleryId, $parent ? (int) $parent['id'] : null);
    }

    // $currentFolderName stores an intermediate value used by the surrounding gallery workflow.
    $currentFolderName = gallery_folder_name_from_path($oldPath);
    // $targetFolderName stores an intermediate value used by the surrounding gallery workflow.
    $targetFolderName = $folderName !== null && trim($folderName) !== '' ? gallery_folder_segment($folderName) : $currentFolderName;
    if ($targetFolderName === '') {
        throw new RuntimeException('Gallery folder name cannot be empty.');
    }
    // $newPath stores an intermediate value used by the surrounding gallery workflow.
    $newPath = $parent ? normalize_relative_path((string) $parent['folder_path'] . '/' . $targetFolderName) : $targetFolderName;
    if ($newPath === $oldPath) {
        return ['moved' => false, 'from' => $oldPath, 'to' => $newPath, 'galleries' => 0];
    }
    if (find_gallery_by_folder_path($newPath)) {
        throw new RuntimeException('Another gallery already uses the destination folder path.');
    }
    // $newAbs stores an intermediate value used by the surrounding gallery workflow.
    $newAbs = gallery_target_abs_path($newPath);
    if (file_exists($newAbs)) {
        throw new RuntimeException('Destination folder already exists on disk.');
    }

    // $rows stores an intermediate value used by the surrounding gallery workflow.
    $rows = gallery_subtree_rows($galleryId);
    // $pathMap stores an intermediate value used by the surrounding gallery workflow.
    $pathMap = [];
    foreach ($rows as $row) {
        // $rowPath stores an intermediate value used by the surrounding gallery workflow.
        $rowPath = normalize_relative_path((string) $row['folder_path']);
        // $suffix stores an intermediate value used by the surrounding gallery workflow.
        $suffix = $rowPath === $oldPath ? '' : substr($rowPath, strlen($oldPath) + 1);
        $pathMap[(int) $row['id']] = $suffix === '' ? $newPath : normalize_relative_path($newPath . '/' . $suffix);
    }

    // $updates stores the complete path and parent map persisted after the filesystem rename.
    $idsByNewPath = [];
    foreach ($pathMap as $id => $path) {
        $idsByNewPath[$path] = (int) $id;
    }
    $updates = [];
    foreach ($pathMap as $id => $path) {
        if ((int) $id === $galleryId) {
            $rowParentId = $parent ? (int) $parent['id'] : null;
        } else {
            $parentPath = normalize_relative_path(str_replace('\\', '/', dirname($path)));
            $rowParentId = ($parentPath === '' || $parentPath === '.') ? null : ($idsByNewPath[$parentPath] ?? null);
            if ($rowParentId === null && $parentPath !== '' && $parentPath !== '.') {
                $rowParent = find_parent_gallery_for_path($path);
                $rowParentId = $rowParent ? (int) $rowParent['id'] : null;
            }
        }
        $updates[] = [
            'id' => (int) $id,
            'folder_path' => $path,
            'folder_path_hash' => hash('sha256', $path),
            'parent_id' => $rowParentId,
        ];
    }

    // $moved stores whether the filesystem rename must be rolled back after a persistence failure.
    $moved = false;
    try {
        if (!rename($oldAbs, $newAbs)) {
            throw new RuntimeException('Could not move gallery folder on disk.');
        }
        $moved = true;
        gallery_mutation_model_update_gallery_paths($updates, now_sql());
        if (function_exists(__NAMESPACE__ . '\smart_gallery_graph_cache_clear')) {
            smart_gallery_graph_cache_clear();
        }
    } catch (Throwable $exception) {
        if ($moved && is_dir($newAbs) && !is_dir($oldAbs)) {
            @rename($newAbs, $oldAbs);
        }
        throw new RuntimeException('Gallery move failed: ' . $exception->getMessage(), 0, $exception);
    }

    if (!$smartGalleryGraphPrevalidated) {
        sync_gallery_parent_ids();
        if (public_path_schema_ready()) {
            refresh_gallery_public_paths();
        }
    }
    foreach (array_keys($pathMap) as $id) {
        // $updated stores an intermediate value used by the surrounding gallery workflow.
        $updated = find_gallery((int) $id, true);
        if ($updated) {
            write_gallery_sidecar($updated);
        }
    }

    return ['moved' => true, 'from' => $oldPath, 'to' => $newPath, 'galleries' => count($pathMap)];
}

/**
 * Handles ensure gallery ancestors for path logic for the gallery application.
 *
 * @param mixed $folderPath Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function ensure_gallery_ancestors_for_path(string $folderPath): array
{
    // Variable $segments stores this steps working value.
    $segments = explode('/', normalize_relative_path($folderPath));
    // Variable $createdIds stores this steps working value.
    $createdIds = [];
    // Variable $currentSegments stores this steps working value.
    $currentSegments = [];

    while (count($segments) > 1) {
        $currentSegments[] = array_shift($segments);
        // Variable $ancestorPath stores this steps working value.
        $ancestorPath = implode('/', $currentSegments);
        if ($ancestorPath === '' || find_gallery_by_folder_path($ancestorPath)) {
            continue;
        }
        if (!is_dir(gallery_abs_path($ancestorPath))) {
            continue;
        }
        // Variable $gallery stores this steps working value.
        $gallery = create_gallery_row_for_folder($ancestorPath);
        if ($gallery) {
            $createdIds[] = (int) $gallery['id'];
        }
    }

    return $createdIds;
}

/**
 * Import selected discovery folders, scan originals and optionally generate derivatives.
 *
 * @param list<string> $folderPaths Relative discovered folder selections expanded by the discovery owner.
 * @param bool $createThumbnails Whether newly imported galleries receive recursive thumbnail generation.
 * @return array{imported:int,scanned:int,thumbnails:int,gallery_ids:list<int>} New gallery IDs and work counts under one writer lease.
 */
function import_galleries(array $folderPaths, bool $createThumbnails = false): array
{
    $writerLock = gallery_edit_writer_begin();
    try {
        return import_galleries_owned($folderPaths, $createThumbnails);
    } finally {
        gallery_edit_writer_end($writerLock);
    }
}

/**
 * Perform import galleries while the caller owns the gallery writer lease.
 *
 * @param array<int,string> $folderPaths Selected discovery paths.
 * @param bool $createThumbnails Whether ingestion should generate derivatives.
 * @return array{imported:int,scanned:int,thumbnails:int,gallery_ids:list<int>} New catalog identities and ingestion counts.
 */
function import_galleries_owned(array $folderPaths, bool $createThumbnails = false): array
{
    // $folderPaths stores the ordered import queue expanded from the admin selection.
    $folderPaths = admin_gallery_discovery_expand_requested_import_paths($folderPaths);

    // Variable $imported stores this steps working value.
    $imported = 0;
    // Variable $scanned stores this steps working value.
    $scanned = 0;
    // Variable $thumbs stores this steps working value.
    $thumbs = 0;
    // Variable $importedIds stores this steps working value.
    $importedIds = [];

    foreach ($folderPaths as $folderPath) {
        if (find_gallery_by_folder_path($folderPath)) {
            continue;
        }
        // Variable $gallery stores this steps working value.
        $gallery = create_gallery_row_for_folder($folderPath);
        if (!$gallery) {
            continue;
        }
        $importedIds[] = (int) $gallery['id'];
        $imported++;
    }

    sync_gallery_parent_ids();
    if ($importedIds && public_path_schema_ready()) {
        refresh_gallery_public_paths();
    }
    foreach ($importedIds as $galleryId) {
        $scanned += scan_gallery_images($galleryId);
    }
    if ($createThumbnails) {
        foreach ($importedIds as $galleryId) {
            // Thumbnail creation is recursive, so parent folders that only
            // contain subgalleries still produce usable gallery-card covers.
            $thumbs += create_gallery_thumbnails($galleryId);
        }
    }
    return ['imported' => $imported, 'scanned' => $scanned, 'thumbnails' => $thumbs, 'gallery_ids' => $importedIds];
}

/**
 * Import catalog folders and scan originals with deferred derivative generation.
 *
 * @param list<string> $folderPaths Relative discovered folders; already indexed folders are skipped.
 * @return array{imported:int,scanned:int,gallery_ids:list<int>,thumbnails:0} New gallery IDs and scan counts; derivative generation is deferred.
 */
function import_galleries_without_thumbnails(array $folderPaths): array
{
    $writerLock = gallery_edit_writer_begin();
    try {
        return import_galleries_without_thumbnails_owned($folderPaths);
    } finally {
        gallery_edit_writer_end($writerLock);
    }
}

/**
 * Perform import galleries without thumbnails while the caller owns the gallery writer lease.
 *
 * @param array<int,string> $folderPaths Selected discovery paths.
 * @return array{imported:int,scanned:int,gallery_ids:list<int>,thumbnails:0} New catalog identities and scan counts.
 */
function import_galleries_without_thumbnails_owned(array $folderPaths): array
{
    // $folderPaths stores the ordered import queue expanded from the admin selection.
    $folderPaths = admin_gallery_discovery_expand_requested_import_paths($folderPaths);

    // $imported stores an intermediate value used by the surrounding gallery workflow.
    $imported = 0;
    // $scanned stores an intermediate value used by the surrounding gallery workflow.
    $scanned = 0;
    // $importedIds stores an intermediate value used by the surrounding gallery workflow.
    $importedIds = [];
    foreach ($folderPaths as $folderPath) {
        if (find_gallery_by_folder_path($folderPath)) {
            continue;
        }
        // $gallery stores an intermediate value used by the surrounding gallery workflow.
        $gallery = create_gallery_row_for_folder($folderPath);
        if (!$gallery) {
            continue;
        }
        $importedIds[] = (int) $gallery['id'];
        $imported++;
    }
    sync_gallery_parent_ids();
    if ($importedIds && public_path_schema_ready()) {
        refresh_gallery_public_paths();
    }
    foreach ($importedIds as $galleryId) {
        $scanned += scan_gallery_images($galleryId);
    }
    return ['imported' => $imported, 'scanned' => $scanned, 'gallery_ids' => $importedIds, 'thumbnails' => 0];
}

/**
 * Handles sync gallery parent ids logic for the gallery application.
 *
 * @param bool $smartGalleryGraphPrevalidated True only when the caller already validated the complete final parent map.
 */
function sync_gallery_parent_ids(bool $smartGalleryGraphPrevalidated = false): void
{
    // $hierarchyChanged tracks repairs that require clean public paths to be rebuilt.
    $hierarchyChanged = false;
    // $galleries stores the current hierarchy rows before missing ancestors are repaired.
    $galleries = gallery_mutation_model_hierarchy_rows();
    foreach ($galleries as $gallery) {
        // Missing intermediate gallery rows are repaired before parent lookup.
        // This fixes older imports where a deep folder was imported without its
        // parent and therefore appeared on the public homepage as a root gallery.
        if (ensure_gallery_ancestors_for_path((string) $gallery['folder_path']) !== []) {
            $hierarchyChanged = true;
        }
    }

    // New ancestor rows may have been inserted above, so read the final hierarchy once.
    $galleries = gallery_mutation_model_hierarchy_rows();
    // $galleryIdsByPath stores gallery ids by normalized folder path for O(1) parent lookup.
    $galleryIdsByPath = [];
    foreach ($galleries as $gallery) {
        $galleryIdsByPath[normalize_relative_path((string) $gallery['folder_path'])] = (int) $gallery['id'];
    }

    // $desiredParentById stores the complete filesystem-derived parent map before any parent_id write occurs.
    $desiredParentById = [];
    foreach ($galleries as $gallery) {
        $galleryId = (int) $gallery['id'];
        $folderPath = normalize_relative_path((string) $gallery['folder_path']);
        $parentPath = trim(str_replace('\\', '/', dirname($folderPath)), '.');
        $desiredParentById[$galleryId] = ($parentPath === '' || $parentPath === '/')
            ? 0
            : (int) ($galleryIdsByPath[$parentPath] ?? 0);
    }
    if (!$smartGalleryGraphPrevalidated && function_exists(__NAMESPACE__ . '\smart_gallery_validate_gallery_parent_map')) {
        smart_gallery_validate_gallery_parent_map($desiredParentById);
    }

    $hierarchyChanged = gallery_mutation_model_sync_parent_map($galleries, $desiredParentById, now_sql()) || $hierarchyChanged;

    if ($hierarchyChanged && function_exists(__NAMESPACE__ . '\smart_gallery_graph_cache_clear')) {
        smart_gallery_graph_cache_clear();
    }
    if ($hierarchyChanged && public_path_schema_ready()) {
        refresh_gallery_public_paths();
    }
}

/**
 * Read the current catalog IDs of a physical gallery and its folder descendants.
 *
 * @param int $galleryId Root catalog identity, freshly read to avoid stale folder ownership.
 * @return list<int> Subtree identities, or an empty list when the root no longer exists.
 */
function gallery_subtree_ids(int $galleryId): array
{
    // $gallery stores this steps working value.
    $gallery = find_gallery($galleryId, true);
    if (!$gallery) {
        return [];
    }
    // $folderPath stores this steps working value.
    $folderPath = normalize_relative_path((string) $gallery['folder_path']);
    return gallery_mutation_model_subtree_ids($folderPath, gallery_folder_path_descendant_like_pattern($folderPath));
}

