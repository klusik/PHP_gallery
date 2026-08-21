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
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use function Gallery\Core\db;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;
use function Gallery\Core\path_inside;

/**
 * Gallery mutation model.
 *
 * This module owns filesystem-backed gallery changes: subtree deletion, folder moves, imports, ancestor creation, and parent synchronization. It intentionally keeps the filesystem as the source of truth and updates the database to follow it.
 *
 * @param int $galleryId Gallery identifier.
 * @return array Structured result data for the caller.
 */
function gallery_subtree_rows(int $galleryId): array
{
    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        return [];
    }
    // $folderPath stores an intermediate value used by the surrounding gallery workflow.
    $folderPath = normalize_relative_path((string) $gallery['folder_path']);
    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = db()->prepare('SELECT * FROM galleries WHERE folder_path = ? OR folder_path LIKE ? ORDER BY folder_path');
    $stmt->execute([$folderPath, $folderPath . '/%']);
    return $stmt->fetchAll();
}

/**
 * Handles delete gallery subtrees logic for the gallery application.
 *
 * @param mixed $galleryIds Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function delete_gallery_subtrees(array $galleryIds): array
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

    usort($roots, static fn (array $left, array $right): int => strlen((string) $left['folder_path']) <=> strlen((string) $right['folder_path']));

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
    // $galleryIds stores unique positive gallery ids accepted by SQL cleanup.
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

    // $imageIds stores all images that belong to the removed gallery rows.
    $imageIds = gallery_image_ids_for_gallery_ids($galleryIds);
    // $pdo stores the active connection for the atomic database cleanup.
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($imageIds) {
            gallery_null_rows_by_ids('galleries', 'cover_image_id', $imageIds);
            gallery_null_rows_by_ids('telemetry_sessions', 'first_image_id', $imageIds);
            gallery_null_rows_by_ids('telemetry_sessions', 'last_image_id', $imageIds);
            gallery_null_rows_by_ids('telemetry_events', 'image_id', $imageIds);
            gallery_null_rows_by_ids('telemetry_job_runs', 'image_id', $imageIds);

            gallery_delete_rows_by_ids('image_thumbnail_variants', 'image_id', $imageIds);
            gallery_delete_rows_by_ids('image_ai_analysis_jobs', 'image_id', $imageIds);
            gallery_delete_rows_by_ids('image_ai_metadata', 'image_id', $imageIds);
            gallery_delete_rows_by_ids('picture_game_votes', 'image_a_id', $imageIds);
            gallery_delete_rows_by_ids('picture_game_votes', 'image_b_id', $imageIds);
            gallery_delete_rows_by_ids('picture_game_votes', 'winner_image_id', $imageIds);
            gallery_delete_rows_by_ids('image_tags', 'image_id', $imageIds);
            gallery_delete_rows_by_ids('image_votes', 'image_id', $imageIds);
        }

        gallery_null_rows_by_ids('telemetry_sessions', 'first_gallery_id', $galleryIds);
        gallery_null_rows_by_ids('telemetry_sessions', 'last_gallery_id', $galleryIds);
        gallery_null_rows_by_ids('telemetry_events', 'gallery_id', $galleryIds);
        gallery_null_rows_by_ids('telemetry_job_runs', 'gallery_id', $galleryIds);
        gallery_null_rows_by_ids('galleries', 'parent_id', $galleryIds);

        gallery_delete_rows_by_ids('gallery_flight_maps', 'gallery_id', $galleryIds);
        gallery_delete_rows_by_ids('gallery_upload_tokens', 'gallery_id', $galleryIds);
        gallery_delete_rows_by_ids('mobile_webdav_upload_tokens', 'gallery_id', $galleryIds);
        gallery_delete_rows_by_ids('image_thumbnail_variants', 'gallery_id', $galleryIds);
        gallery_delete_rows_by_ids('image_ai_analysis_jobs', 'gallery_id', $galleryIds);
        gallery_delete_rows_by_ids('picture_game_votes', 'gallery_id', $galleryIds);
        gallery_delete_rows_by_ids('gallery_tags', 'gallery_id', $galleryIds);
        gallery_delete_rows_by_ids('zip_archives', 'gallery_id', $galleryIds);
        gallery_delete_rows_by_ids('images', 'gallery_id', $galleryIds);

        // $deletedRows stores the actual number of galleries removed.
        $deletedRows = gallery_delete_rows_by_ids('galleries', 'id', $galleryIds);
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

    // $stmt stores the lookup for stale exact and descendant gallery rows.
    $stmt = db()->prepare('SELECT id FROM galleries WHERE folder_path = ? OR folder_path LIKE ? ORDER BY folder_path DESC');
    $stmt->execute([$folderPath, $folderPath . '/%']);
    // $ids stores stale gallery ids that can no longer be reached on disk.
    $ids = array_values(array_unique(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), static fn (int $galleryId): bool => $galleryId > 0)));
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

    // $imageIds stores the merged image ids from chunked SELECT queries.
    $imageIds = [];
    foreach (array_chunk($galleryIds, 500) as $chunk) {
        // $placeholders stores SQL placeholders for this chunk.
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        // $stmt stores the prepared image-id lookup for this chunk.
        $stmt = db()->prepare('SELECT id FROM images WHERE gallery_id IN (' . $placeholders . ')');
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $imageId) {
            $imageIds[(int) $imageId] = (int) $imageId;
        }
    }
    return array_values($imageIds);
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
    // $ids stores unique positive ids accepted by this SQL mutation.
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    if (!$ids) {
        return 0;
    }
    if (!mutation_schema_optional_table_column_available('mutation.gallery_delete_dependency', $table, $column, 'gallery.delete_dependency_rows')) {
        return 0;
    }

    // $safeTable stores a validated SQL identifier.
    $safeTable = gallery_mutation_sql_identifier($table);
    // $safeColumn stores a validated SQL identifier.
    $safeColumn = gallery_mutation_sql_identifier($column);
    // $deletedRows stores rows affected across chunks.
    $deletedRows = 0;
    foreach (array_chunk($ids, 500) as $chunk) {
        // $placeholders stores SQL placeholders for this chunk.
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        // $stmt stores the prepared delete for this chunk.
        $stmt = db()->prepare('DELETE FROM `' . $safeTable . '` WHERE `' . $safeColumn . '` IN (' . $placeholders . ')');
        $stmt->execute($chunk);
        $deletedRows += $stmt->rowCount();
    }
    return $deletedRows;
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
    // $ids stores unique positive ids accepted by this SQL mutation.
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    if (!$ids) {
        return 0;
    }
    if (!mutation_schema_optional_table_column_available('mutation.gallery_delete_dependency', $table, $column, 'gallery.null_dependency_rows')) {
        return 0;
    }

    // $safeTable stores a validated SQL identifier.
    $safeTable = gallery_mutation_sql_identifier($table);
    // $safeColumn stores a validated SQL identifier.
    $safeColumn = gallery_mutation_sql_identifier($column);
    // $updatedRows stores rows affected across chunks.
    $updatedRows = 0;
    foreach (array_chunk($ids, 500) as $chunk) {
        // $placeholders stores SQL placeholders for this chunk.
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        // $stmt stores the prepared update for this chunk.
        $stmt = db()->prepare('UPDATE `' . $safeTable . '` SET `' . $safeColumn . '` = NULL WHERE `' . $safeColumn . '` IN (' . $placeholders . ')');
        $stmt->execute($chunk);
        $updatedRows += $stmt->rowCount();
    }
    return $updatedRows;
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
        if (!path_inside($allowedRoot, $path)) {
            throw new RuntimeException('Refusing to delete a path outside the gallery root.');
        }
        if ($entry->isDir() && !$entry->isLink()) {
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
    // $normalizedIds stores the unique positive image ids selected by the admin.
    $normalizedIds = array_values(array_unique(array_filter(array_map('intval', $imageIds), static fn (int $imageId): bool => $imageId > 0)));
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
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        throw new RuntimeException('Gallery not found.');
    }

    // $images stores only rows owned by the requested gallery.
    $images = [];
    foreach ($normalizedIds as $imageId) {
        // $image stores one selected database row.
        $image = find_image($imageId);
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
    $imageIdsToDelete = array_map(static fn (array $image): int => (int) $image['id'], $images);
    // $placeholders stores SQL placeholders for the selected image ids.
    $placeholders = implode(',', array_fill(0, count($imageIdsToDelete), '?'));
    // $pdo stores the active database connection used for the image row deletion.
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Clear nullable references explicitly so older shared-hosting databases
        // remain deterministic even if one historical foreign key is missing.
        gallery_null_rows_by_ids('galleries', 'cover_image_id', $imageIdsToDelete);
        gallery_null_rows_by_ids('telemetry_sessions', 'first_image_id', $imageIdsToDelete);
        gallery_null_rows_by_ids('telemetry_sessions', 'last_image_id', $imageIdsToDelete);
        gallery_null_rows_by_ids('telemetry_events', 'image_id', $imageIdsToDelete);
        gallery_null_rows_by_ids('telemetry_job_runs', 'image_id', $imageIdsToDelete);

        // Remove known dependent rows explicitly. Existing ON DELETE CASCADE keys
        // still work, but this mirrors subtree deletion safety on older installs.
        gallery_delete_rows_by_ids('image_thumbnail_variants', 'image_id', $imageIdsToDelete);
        gallery_delete_rows_by_ids('image_ai_analysis_jobs', 'image_id', $imageIdsToDelete);
        gallery_delete_rows_by_ids('image_ai_metadata', 'image_id', $imageIdsToDelete);
        gallery_delete_rows_by_ids('picture_game_votes', 'image_a_id', $imageIdsToDelete);
        gallery_delete_rows_by_ids('picture_game_votes', 'image_b_id', $imageIdsToDelete);
        gallery_delete_rows_by_ids('picture_game_votes', 'winner_image_id', $imageIdsToDelete);
        gallery_delete_rows_by_ids('image_tags', 'image_id', $imageIdsToDelete);
        gallery_delete_rows_by_ids('image_votes', 'image_id', $imageIdsToDelete);
        gallery_delete_rows_by_ids('image_translations', 'image_id', $imageIdsToDelete);
        gallery_delete_rows_by_ids('viewer_favourites', 'image_id', $imageIdsToDelete);
        gallery_delete_rows_by_ids('viewer_collection_items', 'image_id', $imageIdsToDelete);
        gallery_delete_rows_by_ids('duplicate_photo_ledger_pairs', 'image_id_low', $imageIdsToDelete);
        gallery_delete_rows_by_ids('duplicate_photo_ledger_pairs', 'image_id_high', $imageIdsToDelete);

        // $deleteStmt removes the selected image rows after dependencies are safe.
        $deleteStmt = $pdo->prepare('DELETE FROM images WHERE gallery_id = ? AND id IN (' . $placeholders . ')');
        $deleteStmt->execute(array_merge([$galleryId], $imageIdsToDelete));
        // $deletedRows stores the number of rows removed from images.
        $deletedRows = $deleteStmt->rowCount();
        if ($deletedRows !== count($imageIdsToDelete)) {
            throw new RuntimeException('Image deletion changed fewer database rows than expected.');
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
    $updatedGallery = find_gallery($galleryId);
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
 * The filesystem move is attempted before the database ownership update. If a
 * later file or database step fails, successfully moved files are moved back to
 * their original source paths. This keeps the operation a real move while still
 * giving the admin a clean failure report instead of silently copying files.
 *
 * @param int $sourceGalleryId Gallery that currently owns the selected images.
 * @param int $destinationGalleryId Gallery that will receive the selected images.
 * @param array<int> $imageIds Image ids submitted by the admin UI.
 * @return array{requested:int,moved:int,originals_moved:int,derivatives_moved:int,failures:array<int,string>,source_cover_image_id:int|null,destination_cover_image_id:int|null} Structured result data for the caller.
 */
function move_gallery_images(int $sourceGalleryId, int $destinationGalleryId, array $imageIds, array $options = []): array
{
    mutation_schema_assert_available(
        gallery_move_schema_status(),
        'gallery.move_images',
        'Image moves require the current gallery/image ownership schema. Run pending migrations first.',
        'Image moves are temporarily unavailable because the required database schema could not be verified.'
    );

    // $normalizedIds stores the unique positive image ids selected by the admin.
    $normalizedIds = array_values(array_unique(array_filter(array_map('intval', $imageIds), static fn (int $imageId): bool => $imageId > 0)));
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

    // $deferMaintenance stores whether the caller will perform shared post-move maintenance later.
    $deferMaintenance = !empty($options['defer_maintenance']);

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
        $image = find_image($imageId);
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

    usort($images, static function (array $left, array $right): int {
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

    // $movedFiles stores successful renames in reversible order.
    $movedFiles = [];
    try {
        foreach ($manifest as $entry) {
            // $targetDirectory stores the directory that must exist before rename().
            $targetDirectory = dirname((string) $entry['to']);
            if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true)) {
                throw new RuntimeException('Could not create destination directory: ' . $targetDirectory);
            }
            if (!@rename((string) $entry['from'], (string) $entry['to'])) {
                throw new RuntimeException('Could not move file: ' . basename((string) $entry['from']));
            }
            $movedFiles[] = $entry;
        }
    } catch (Throwable $exception) {
        gallery_rollback_image_file_moves($movedFiles);
        throw $exception;
    }

    // $imageIdsToMove stores validated row IDs for the database update.
    $imageIdsToMove = array_map(static fn (array $image): int => (int) $image['id'], $images);
    // $destinationSortOrders stores append-style order values assigned in the destination gallery.
    $destinationSortOrders = gallery_destination_sort_orders($destinationGalleryId, $imageIdsToMove);
    // $placeholders stores SQL placeholders for selected image ids.
    $placeholders = implode(',', array_fill(0, count($imageIdsToMove), '?'));
    // $pdo stores the active database connection used for ownership and cover updates.
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // $sourceCoverImageId stores the title picture after selected images leave the source gallery.
        $sourceCoverImageId = gallery_cover_id_after_source_move($sourceGalleryId, $imageIdsToMove);
        // $sourceCoverStmt keeps the source title-picture field valid before rows change owners.
        $sourceCoverStmt = $pdo->prepare('UPDATE galleries SET cover_image_id = ?, updated_at = ? WHERE id = ? AND cover_image_id IN (' . $placeholders . ')');
        $sourceCoverStmt->execute(array_merge([$sourceCoverImageId, now_sql(), $sourceGalleryId], $imageIdsToMove));

        // $updatedRows counts how many database image rows were transferred.
        $updatedRows = 0;
        foreach ($imageIdsToMove as $imageId) {
            // $updateStmt transfers one image so its destination sort_order can be preserved predictably.
            $updateStmt = $pdo->prepare('UPDATE images SET gallery_id = ?, sort_order = ?, updated_at = ? WHERE gallery_id = ? AND id = ?');
            $updateStmt->execute([
                $destinationGalleryId,
                $destinationSortOrders[$imageId] ?? next_gallery_image_sort_order($destinationGalleryId),
                now_sql(),
                $sourceGalleryId,
                $imageId,
            ]);
            $updatedRows += $updateStmt->rowCount();
        }

        if ((int) $updatedRows !== count($imageIdsToMove)) {
            throw new RuntimeException('Only ' . (int) $updatedRows . ' of ' . count($imageIdsToMove) . ' image records moved.');
        }

        // $destinationCoverImageId stores the title picture after the destination receives the moved images.
        $destinationCoverImageId = gallery_cover_id_after_destination_move($destinationGalleryId);
        // $destinationCoverStmt updates only missing or invalid destination title-picture references.
        $destinationCoverStmt = $pdo->prepare('UPDATE galleries SET cover_image_id = ?, updated_at = ? WHERE id = ?');
        $destinationCoverStmt->execute([$destinationCoverImageId, now_sql(), $destinationGalleryId]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        gallery_rollback_image_file_moves($movedFiles);
        throw $exception;
    }

    if (!$deferMaintenance) {
        thumbnail_maintenance_summary_cache_clear();
        if (public_path_schema_ready()) {
            regenerate_gallery_image_public_slugs($sourceGalleryId);
            regenerate_gallery_image_public_slugs($destinationGalleryId);
        }
        // $updatedSourceGallery stores the source row after title-picture cleanup.
        $updatedSourceGallery = find_gallery($sourceGalleryId, true);
        if ($updatedSourceGallery) {
            write_gallery_sidecar($updatedSourceGallery);
        }
        // $updatedDestinationGallery stores the destination row after image ownership changes.
        $updatedDestinationGallery = find_gallery($destinationGalleryId, true);
        if ($updatedDestinationGallery) {
            write_gallery_sidecar($updatedDestinationGallery);
        }
    }

    // $originalsMoved stores moved original media files.
    $originalsMoved = count(array_filter($movedFiles, static fn (array $entry): bool => (string) $entry['kind'] === 'original'));
    // $derivativesMoved stores moved generated files.
    $derivativesMoved = count(array_filter($movedFiles, static fn (array $entry): bool => (string) $entry['kind'] === 'derivative'));

    return [
        'requested' => count($normalizedIds),
        'moved' => (int) $updatedRows,
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
    // $stmt stores the current destination tail value.
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM images WHERE gallery_id = ?');
    $stmt->execute([$destinationGalleryId]);
    // $nextSortOrder stores the first appended order number.
    $nextSortOrder = (int) $stmt->fetchColumn() + 10;
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
    // $params stores query parameters for the cover candidate lookup.
    $params = [$galleryId];
    // $sql stores the direct-image candidate lookup.
    $sql = "SELECT id FROM images WHERE gallery_id = ? AND relative_path NOT LIKE '%/%'";
    if ($excludedImageIds) {
        // $placeholders stores placeholders for images that are leaving the gallery.
        $placeholders = implode(',', array_fill(0, count($excludedImageIds), '?'));
        $sql .= ' AND id NOT IN (' . $placeholders . ')';
        $params = array_merge($params, $excludedImageIds);
    }
    $sql .= " ORDER BY CASE WHEN visibility = 'public' THEN 0 ELSE 1 END, sort_order, filename, id LIMIT 1";
    // $stmt stores the candidate query.
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    // $candidateId stores the selected replacement or false when the gallery is empty.
    $candidateId = $stmt->fetchColumn();
    return $candidateId ? (int) $candidateId : null;
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
    if (!$galleryIds) {
        return false;
    }
    // $placeholders stores placeholders for the eligible gallery branch.
    $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
    // $stmt stores the ownership query.
    $stmt = db()->prepare('SELECT COUNT(*) FROM images WHERE id = ? AND gallery_id IN (' . $placeholders . ')');
    $stmt->execute(array_merge([$imageId], $galleryIds));
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Handles move gallery folder to parent logic for the gallery application.
 *
 * @param mixed $galleryId Input used by this operation.
 * @param mixed $parentId Input used by this operation.
 * @param mixed $folderName Input used by this operation.
 * @param bool $smartGalleryGraphPrevalidated True only for a caller that already validated the complete final parent map and will run final hierarchy maintenance.
 * @return mixed Result produced by this operation.
 */
function move_gallery_folder_to_parent(int $galleryId, ?int $parentId, ?string $folderName = null, bool $smartGalleryGraphPrevalidated = false): array
{
    mutation_schema_assert_available(
        gallery_move_schema_status(),
        'gallery.move_folder',
        'Gallery folder moves require the current gallery/image ownership schema. Run pending migrations first.',
        'Gallery folder moves are temporarily unavailable because the required database schema could not be verified.'
    );

    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = find_gallery($galleryId);
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
    $parent = $parentId !== null && $parentId > 0 ? find_gallery($parentId) : null;
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

    // $pdo stores an intermediate value used by the surrounding gallery workflow.
    $pdo = db();
    // $moved stores an intermediate value used by the surrounding gallery workflow.
    $moved = false;
    try {
        $pdo->beginTransaction();
        if (!rename($oldAbs, $newAbs)) {
            throw new RuntimeException('Could not move gallery folder on disk.');
        }
        // $moved stores an intermediate value used by the surrounding gallery workflow.
        $moved = true;
        // $stmt stores an intermediate value used by the surrounding gallery workflow.
        $stmt = $pdo->prepare('UPDATE galleries SET folder_path = ?, folder_path_hash = ?, parent_id = ?, updated_at = ? WHERE id = ?');
        foreach ($pathMap as $id => $path) {
            // $rowParentId stores an intermediate value used by the surrounding gallery workflow.
            $rowParentId = $id === $galleryId ? ($parent ? (int) $parent['id'] : null) : null;
            if ($id !== $galleryId) {
                // $rowParent stores an intermediate value used by the surrounding gallery workflow.
                $rowParent = find_parent_gallery_for_path($path);
                // $rowParentId stores an intermediate value used by the surrounding gallery workflow.
                $rowParentId = $rowParent ? (int) $rowParent['id'] : null;
            }
            $stmt->execute([$path, hash('sha256', $path), $rowParentId, now_sql(), $id]);
        }
        $pdo->commit();
        if (function_exists(__NAMESPACE__ . '\smart_gallery_graph_cache_clear')) {
            smart_gallery_graph_cache_clear();
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
 * Handles import galleries logic for the gallery application.
 *
 * @param mixed $folderPaths Input used by this operation.
 * @param mixed $createThumbnails Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function import_galleries(array $folderPaths, bool $createThumbnails = false): array
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
 * Handles import galleries without thumbnails logic for the gallery application.
 *
 * @param mixed $folderPaths Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function import_galleries_without_thumbnails(array $folderPaths): array
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
    // Variable $galleries stores this steps working value.
    $stmt = db()->prepare('SELECT id, folder_path, parent_id FROM galleries ORDER BY folder_path');
    $stmt->execute();
    $galleries = $stmt->fetchAll();
    foreach ($galleries as $gallery) {
        // Missing intermediate gallery rows are repaired before parent lookup.
        // This fixes older imports where a deep folder was imported without its
        // parent and therefore appeared on the public homepage as a root gallery.
        if (ensure_gallery_ancestors_for_path((string) $gallery['folder_path']) !== []) {
            $hierarchyChanged = true;
        }
    }

    // New ancestor rows may have been inserted above, so read the final hierarchy once.
    $stmt = db()->prepare('SELECT id, folder_path, parent_id FROM galleries ORDER BY folder_path');
    $stmt->execute();
    $galleries = $stmt->fetchAll();
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

    // $clearParent stores the reusable statement for root rows that have stale parent ids.
    $clearParent = db()->prepare('UPDATE galleries SET parent_id = NULL, updated_at = ? WHERE id = ? AND parent_id IS NOT NULL');
    // $setParent stores the reusable statement for rows whose filesystem parent changed.
    $setParent = db()->prepare('UPDATE galleries SET parent_id = ?, updated_at = ? WHERE id = ? AND (parent_id IS NULL OR parent_id <> ?)');
    foreach ($galleries as $gallery) {
        $galleryId = (int) $gallery['id'];
        $currentParentId = $gallery['parent_id'] === null ? 0 : (int) $gallery['parent_id'];
        $desiredParentId = (int) ($desiredParentById[$galleryId] ?? 0);
        if ($currentParentId === $desiredParentId) {
            continue;
        }
        if ($desiredParentId <= 0) {
            $clearParent->execute([now_sql(), $galleryId]);
            $hierarchyChanged = $hierarchyChanged || $clearParent->rowCount() > 0;
            continue;
        }
        $setParent->execute([$desiredParentId, now_sql(), $galleryId, $desiredParentId]);
        $hierarchyChanged = $hierarchyChanged || $setParent->rowCount() > 0;
    }

    if ($hierarchyChanged && function_exists(__NAMESPACE__ . '\smart_gallery_graph_cache_clear')) {
        smart_gallery_graph_cache_clear();
    }
    if ($hierarchyChanged && public_path_schema_ready()) {
        refresh_gallery_public_paths();
    }
}

/**
 * Handles gallery subtree ids logic for the gallery application.
 *
 * @param mixed $galleryId Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_subtree_ids(int $galleryId): array
{
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        return [];
    }
    // Variable $folderPath stores this steps working value.
    $folderPath = normalize_relative_path((string) $gallery['folder_path']);
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT id FROM galleries WHERE folder_path = ? OR folder_path LIKE ? ORDER BY folder_path');
    $stmt->execute([$folderPath, $folderPath . '/%']);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

