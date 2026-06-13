<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_gallery_discovery.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides browser-driven filesystem gallery discovery for the Admin dashboard.
 *
 * Responsibilities:
 *   - Scan gallery folders in small Ajax batches
 *   - Keep long filesystem discovery away from initial Admin page rendering
 *   - Return actionable candidate metadata for discovered gallery folders
 *   - Move or delete unmanaged discovered folders when the admin requests it
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
 *   2026-06-11
 */

declare(strict_types=1);

namespace Gallery\Services;

use DirectoryIterator;
use FilesystemIterator;
use PDO;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use function Gallery\Core\db;
use function Gallery\Core\is_supported_image_path;
use function Gallery\Core\normalize_relative_path;

const ADMIN_GALLERY_DISCOVERY_DEFAULT_BATCH_SIZE = 80;
const ADMIN_GALLERY_DISCOVERY_MAX_BATCH_SIZE = 300;
const ADMIN_GALLERY_DISCOVERY_JOB_TTL_SECONDS = 7200;

/**
 * Start a browser-driven Admin gallery discovery job.
 *
 * The job records traversal state in the admin session so every request only
 * scans a bounded number of folders. Imported gallery rows are not rescanned
 * here, because the Discover folders action is a folder inventory check.
 *
 * @return array<string, mixed> Public job state for the Ajax caller.
 */
function admin_gallery_discovery_start_job(): array
{
    admin_gallery_discovery_cleanup_jobs();

    $token = bin2hex(random_bytes(12));
    $known = admin_gallery_discovery_known_gallery_paths();
    $job = admin_gallery_discovery_known_path_job_state($known);
    $job = array_merge($job, [
        'token' => $token,
        'status' => 'running',
        'started_at' => time(),
        'updated_at' => time(),
        'queue' => [''],
        'queued_paths' => ['' => true],
        'queue_index' => 0,
        'known_paths' => $known,
        'candidate_paths' => [],
        'candidates' => [],
        'direct_image_counts' => [],
        'branch_image_counts' => [],
        'sidecar_paths' => [],
        'processed_directories' => 0,
        'discovered_directories' => 1,
        'metadata_only_paths' => [],
        'errors' => [],
    ]);

    admin_gallery_discovery_write_job($job);
    return admin_gallery_discovery_public_state($job);
}

/**
 * Process one browser-driven folder discovery batch.
 *
 * @param string $token Session job token supplied by the browser.
 * @param int $batchSize Maximum number of directories to inspect.
 * @return array<string, mixed> Public job state for the Ajax caller.
 */
function admin_gallery_discovery_process_job(string $token, int $batchSize = ADMIN_GALLERY_DISCOVERY_DEFAULT_BATCH_SIZE): array
{
    $job = admin_gallery_discovery_read_job($token);
    if ($job === null) {
        return admin_gallery_discovery_missing_state();
    }

    if ((string) ($job['status'] ?? '') === 'complete') {
        return admin_gallery_discovery_public_state($job, true);
    }

    $root = galleries_root();
    if (!is_dir($root)) {
        $job['status'] = 'error';
        $job['errors'][] = 'The galleries directory does not exist.';
        $job['updated_at'] = time();
        admin_gallery_discovery_write_job($job);
        return admin_gallery_discovery_public_state($job, true);
    }

    $batchSize = max(1, min(ADMIN_GALLERY_DISCOVERY_MAX_BATCH_SIZE, $batchSize));
    $processedThisBatch = 0;

    while ($processedThisBatch < $batchSize && admin_gallery_discovery_has_pending_directory($job)) {
        $relativePath = (string) $job['queue'][(int) $job['queue_index']];
        $job['queue_index'] = (int) $job['queue_index'] + 1;
        admin_gallery_discovery_scan_directory($job, $relativePath);
        $processedThisBatch++;
    }

    $job['processed_directories'] = (int) ($job['queue_index'] ?? 0);
    $job['discovered_directories'] = count((array) ($job['queue'] ?? []));
    $job['updated_at'] = time();

    if (!admin_gallery_discovery_has_pending_directory($job)) {
        $job = admin_gallery_discovery_finish_job($job);
    }

    admin_gallery_discovery_write_job($job);
    return admin_gallery_discovery_public_state($job, (string) ($job['status'] ?? '') === 'complete');
}

/**
 * Return the current public state for an existing discovery job.
 *
 * @param string $token Session job token supplied by the browser.
 * @return array<string, mixed> Public job state for the Ajax caller.
 */
function admin_gallery_discovery_job_status(string $token): array
{
    $job = admin_gallery_discovery_read_job($token);
    if ($job === null) {
        return admin_gallery_discovery_missing_state();
    }

    return admin_gallery_discovery_public_state($job, (string) ($job['status'] ?? '') === 'complete');
}

/**
 * Expand selected folders into an ordered import list without scanning the full gallery root.
 *
 * Import actions use this helper instead of the original full discovery pass.
 * It still preserves the old behavior of importing missing ancestors and valid
 * descendant gallery folders under each selected path.
 *
 * @param array<int, mixed> $folderPaths Folder paths posted by the admin form.
 * @return array<int, string> Ordered normalized folder paths to import.
 */
function admin_gallery_discovery_expand_requested_import_paths(array $folderPaths): array
{
    $knownJob = admin_gallery_discovery_known_path_job_state(admin_gallery_discovery_known_gallery_paths());
    $expanded = [];
    foreach ($folderPaths as $folderPath) {
        $requestedPath = normalize_relative_path((string) $folderPath);
        if ($requestedPath === '' || !is_dir(gallery_abs_path($requestedPath))) {
            continue;
        }

        foreach (admin_gallery_discovery_ancestor_paths($requestedPath) as $ancestorPath) {
            if (!is_dir(gallery_abs_path($ancestorPath))) {
                continue;
            }
            if (admin_gallery_discovery_is_known_gallery_path($ancestorPath, $knownJob)) {
                continue;
            }
            if (!admin_gallery_discovery_path_has_branch_images($ancestorPath)) {
                continue;
            }
            if (admin_gallery_discovery_has_existing_sibling_title($ancestorPath)) {
                continue;
            }
            $expanded[$ancestorPath] = $ancestorPath;
        }

        foreach (admin_gallery_discovery_collect_subtree_candidate_paths($requestedPath) as $candidatePath) {
            if (admin_gallery_discovery_is_known_gallery_path($candidatePath, $knownJob)) {
                continue;
            }
            if (!admin_gallery_discovery_path_has_branch_images($candidatePath)) {
                continue;
            }
            if (admin_gallery_discovery_has_existing_sibling_title($candidatePath)) {
                continue;
            }
            $expanded[$candidatePath] = $candidatePath;
        }
    }

    $paths = array_values($expanded);
    usort($paths, static function (string $left, string $right): int {
        $depth = substr_count($left, '/') <=> substr_count($right, '/');
        return $depth !== 0 ? $depth : strnatcasecmp($left, $right);
    });

    return $paths;
}

/**
 * Move supported photos from discovered folders into an existing gallery folder.
 *
 * The source folders must be unmanaged filesystem folders. The operation moves
 * supported photo files into the selected destination gallery, avoids overwriting
 * existing names, scans the destination gallery, and removes source directories
 * only when they become empty.
 *
 * @param array<int, mixed> $folderPaths Folder paths posted by the admin form.
 * @param int $targetGalleryId Destination gallery identifier.
 * @return array<string, mixed> Move result for the controller or Ajax caller.
 */
function admin_gallery_discovery_move_requested_photos(array $folderPaths, int $targetGalleryId): array
{
    $targetGallery = $targetGalleryId > 0 ? find_gallery($targetGalleryId) : null;
    if (!$targetGallery) {
        return [
            'ok' => false,
            'action' => 'move_photos',
            'error' => t('admin.galleries.discover_move_target_required', 'Choose the existing gallery where the photos should be moved.'),
            'moved' => 0,
            'scanned' => 0,
            'gallery_ids' => [],
            'thumbnails' => 0,
        ];
    }

    $targetRoot = gallery_abs_path((string) $targetGallery['folder_path']);
    if (!is_dir($targetRoot) || !is_writable($targetRoot)) {
        return [
            'ok' => false,
            'action' => 'move_photos',
            'error' => t('admin.galleries.discover_move_target_not_writable', 'The selected destination gallery folder is not writable.'),
            'moved' => 0,
            'scanned' => 0,
            'gallery_ids' => [],
            'thumbnails' => 0,
        ];
    }

    $knownJob = admin_gallery_discovery_known_path_job_state(admin_gallery_discovery_known_gallery_paths());
    $selectedPaths = admin_gallery_discovery_selected_unmanaged_paths($folderPaths, $knownJob);
    $moved = 0;
    $skipped = 0;
    $sourceFoldersCleaned = 0;
    $errors = [];

    foreach ($selectedPaths as $sourcePath) {
        foreach (admin_gallery_discovery_collect_supported_image_files($sourcePath) as $sourceFile) {
            $sourceRealPath = realpath($sourceFile);
            if ($sourceRealPath === false || !is_file($sourceRealPath)) {
                $skipped++;
                continue;
            }
            if (admin_gallery_discovery_is_path_inside_directory($sourceRealPath, $targetRoot)) {
                $skipped++;
                continue;
            }

            $targetPath = admin_gallery_discovery_unique_target_file_path($targetRoot, basename($sourceRealPath));
            if (!@rename($sourceRealPath, $targetPath)) {
                $errors[] = t('admin.galleries.discover_move_file_failed', 'Could not move {file}.', ['file' => basename($sourceRealPath)]);
                $skipped++;
                continue;
            }
            $moved++;
        }

        $sourceFoldersCleaned += admin_gallery_discovery_remove_empty_directories($sourcePath);
    }

    clearstatcache();
    $scanned = $moved > 0 ? scan_gallery_images((int) $targetGallery['id']) : 0;

    if ($moved > 0) {
        admin_log_event('info', 'gallery.discovery_photos_moved', 'Admin moved photos from discovered folders into an existing gallery.', [
            'target_gallery_id' => (int) $targetGallery['id'],
            'target_folder_path' => (string) $targetGallery['folder_path'],
            'source_folders' => $selectedPaths,
            'moved' => $moved,
            'scanned' => $scanned,
            'skipped' => $skipped,
        ]);
    }

    return [
        'ok' => $errors === [],
        'action' => 'move_photos',
        'moved' => $moved,
        'imported' => 0,
        'scanned' => $scanned,
        'thumbnails' => 0,
        'skipped' => $skipped,
        'source_folders_cleaned' => $sourceFoldersCleaned,
        'gallery_ids' => $moved > 0 ? [(int) $targetGallery['id']] : [],
        'errors' => array_values(array_unique($errors)),
        'error' => $errors !== [] ? implode(' ', array_values(array_unique($errors))) : '',
    ];
}

/**
 * Delete selected unmanaged discovered folders from disk.
 *
 * Known database gallery folders are refused here. The action is meant for stale
 * discovery folders or accidental duplicate source folders, not for deleting
 * imported galleries from the CMS.
 *
 * @param array<int, mixed> $folderPaths Folder paths posted by the admin form.
 * @return array<string, mixed> Delete result for the controller or Ajax caller.
 */
function admin_gallery_discovery_delete_requested_paths(array $folderPaths): array
{
    $knownJob = admin_gallery_discovery_known_path_job_state(admin_gallery_discovery_known_gallery_paths());
    $selectedPaths = admin_gallery_discovery_selected_unmanaged_paths($folderPaths, $knownJob);
    $foldersDeleted = 0;
    $filesDeleted = 0;
    $skipped = 0;
    $errors = [];

    foreach ($selectedPaths as $sourcePath) {
        $absolutePath = gallery_abs_path($sourcePath);
        if (!is_dir($absolutePath)) {
            $skipped++;
            continue;
        }

        $result = admin_gallery_discovery_delete_directory_tree($absolutePath);
        $foldersDeleted += (int) ($result['folders_deleted'] ?? 0);
        $filesDeleted += (int) ($result['files_deleted'] ?? 0);
        $skipped += (int) ($result['skipped'] ?? 0);
        foreach ((array) ($result['errors'] ?? []) as $error) {
            $errors[] = (string) $error;
        }
    }

    if ($foldersDeleted > 0 || $filesDeleted > 0) {
        admin_log_event('warning', 'gallery.discovery_folders_deleted', 'Admin deleted unmanaged discovered folders from disk.', [
            'source_folders' => $selectedPaths,
            'folders_deleted' => $foldersDeleted,
            'files_deleted' => $filesDeleted,
            'skipped' => $skipped,
        ], ['category' => 'other', 'severity' => 'warning']);
    }

    return [
        'ok' => $errors === [],
        'action' => 'delete_from_disk',
        'deleted_folders' => $foldersDeleted,
        'deleted_files' => $filesDeleted,
        'skipped' => $skipped,
        'imported' => 0,
        'scanned' => 0,
        'thumbnails' => 0,
        'gallery_ids' => [],
        'errors' => array_values(array_unique($errors)),
        'error' => $errors !== [] ? implode(' ', array_values(array_unique($errors))) : '',
    ];
}

/**
 * Return selected unmanaged folder paths that are safe for disk actions.
 *
 * @param array<int, mixed> $folderPaths Folder paths posted by the admin form.
 * @param array<string, mixed> $knownJob Known gallery path lookup state.
 * @return array<int, string> Normalized unmanaged folder paths.
 */
function admin_gallery_discovery_selected_unmanaged_paths(array $folderPaths, array $knownJob): array
{
    $selected = [];
    foreach ($folderPaths as $folderPath) {
        $relativePath = normalize_relative_path((string) $folderPath);
        if ($relativePath === '' || isset($selected[$relativePath])) {
            continue;
        }
        if (admin_gallery_discovery_is_known_gallery_path($relativePath, $knownJob)) {
            continue;
        }
        if (!admin_gallery_discovery_is_safe_gallery_subdirectory($relativePath)) {
            continue;
        }
        $selected[$relativePath] = $relativePath;
    }

    return array_values($selected);
}

/**
 * Return whether a folder is a real non-root gallery subdirectory.
 *
 * @param string $relativePath Directory path relative to the gallery root.
 * @return bool True when the path resolves under the gallery root.
 */
function admin_gallery_discovery_is_safe_gallery_subdirectory(string $relativePath): bool
{
    $relativePath = normalize_relative_path($relativePath);
    if ($relativePath === '') {
        return false;
    }

    $root = realpath(galleries_root());
    $candidate = realpath(gallery_abs_path($relativePath));
    if ($root === false || $candidate === false || !is_dir($candidate)) {
        return false;
    }

    return admin_gallery_discovery_is_path_inside_directory($candidate, $root) && admin_gallery_discovery_path_key($candidate) !== admin_gallery_discovery_path_key($root);
}

/**
 * Return whether a filesystem path is inside a directory.
 *
 * @param string $path Absolute filesystem path.
 * @param string $directory Absolute directory path.
 * @return bool True when the path is inside the directory.
 */
function admin_gallery_discovery_is_path_inside_directory(string $path, string $directory): bool
{
    $path = admin_gallery_discovery_path_key(realpath($path) ?: $path);
    $directory = rtrim(admin_gallery_discovery_path_key(realpath($directory) ?: $directory), '/') . '/';
    return str_starts_with($path, $directory);
}

/**
 * Collect supported image files below one discovered folder.
 *
 * @param string $relativePath Directory path relative to the gallery root.
 * @return array<int, string> Absolute source image paths.
 */
function admin_gallery_discovery_collect_supported_image_files(string $relativePath): array
{
    $relativePath = normalize_relative_path($relativePath);
    if ($relativePath === '' || !is_dir(gallery_abs_path($relativePath))) {
        return [];
    }

    try {
        $directory = new RecursiveDirectoryIterator(gallery_abs_path($relativePath), FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator($directory, static function (SplFileInfo $entry): bool {
            if ($entry->isDir()) {
                return !$entry->isLink() && !admin_gallery_discovery_should_skip_directory($entry);
            }
            return true;
        });
        $iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY);
    } catch (Throwable) {
        return [];
    }

    $files = [];
    foreach ($iterator as $entry) {
        if ($entry instanceof SplFileInfo && $entry->isFile() && is_supported_image_path($entry->getFilename())) {
            $files[] = $entry->getPathname();
        }
    }

    natsort($files);
    return array_values($files);
}

/**
 * Return an unused destination path for a moved photo.
 *
 * @param string $targetRoot Existing destination gallery directory.
 * @param string $filename Original source filename.
 * @return string Absolute target path.
 */
function admin_gallery_discovery_unique_target_file_path(string $targetRoot, string $filename): string
{
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $suffix = $extension !== '' ? '.' . $extension : '';
    $candidate = rtrim($targetRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    $index = 2;

    while (file_exists($candidate)) {
        $candidate = rtrim($targetRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name . '-' . $index . $suffix;
        $index++;
    }

    return $candidate;
}

/**
 * Remove empty source directories below a moved discovered folder.
 *
 * @param string $relativePath Directory path relative to the gallery root.
 * @return int Number of empty directories removed.
 */
function admin_gallery_discovery_remove_empty_directories(string $relativePath): int
{
    $absolutePath = gallery_abs_path($relativePath);
    if (!is_dir($absolutePath)) {
        return 0;
    }

    $removed = 0;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolutePath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isDir() && !admin_gallery_discovery_directory_has_entries($entry->getPathname())) {
                if (@rmdir($entry->getPathname())) {
                    $removed++;
                }
            }
        }
    } catch (Throwable) {
        return $removed;
    }

    if (is_dir($absolutePath) && !admin_gallery_discovery_directory_has_entries($absolutePath) && @rmdir($absolutePath)) {
        $removed++;
    }

    return $removed;
}

/**
 * Return whether a directory currently has any entries.
 *
 * @param string $absolutePath Absolute directory path.
 * @return bool True when at least one entry remains.
 */
function admin_gallery_discovery_directory_has_entries(string $absolutePath): bool
{
    try {
        $iterator = new FilesystemIterator($absolutePath, FilesystemIterator::SKIP_DOTS);
        return $iterator->valid();
    } catch (Throwable) {
        return true;
    }
}

/**
 * Delete a directory tree and return bounded counters.
 *
 * @param string $absolutePath Absolute directory path to delete.
 * @return array<string, mixed> Deletion counters and errors.
 */
function admin_gallery_discovery_delete_directory_tree(string $absolutePath): array
{
    $filesDeleted = 0;
    $foldersDeleted = 0;
    $skipped = 0;
    $errors = [];

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolutePath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }
            $path = $entry->getPathname();
            if ($entry->isDir() && !$entry->isLink()) {
                if (@rmdir($path)) {
                    $foldersDeleted++;
                } else {
                    $skipped++;
                    $errors[] = t('admin.galleries.discover_delete_folder_failed', 'Could not delete folder {folder}.', ['folder' => basename($path)]);
                }
                continue;
            }
            if (@unlink($path)) {
                $filesDeleted++;
            } else {
                $skipped++;
                $errors[] = t('admin.galleries.discover_delete_file_failed', 'Could not delete file {file}.', ['file' => basename($path)]);
            }
        }
        if (@rmdir($absolutePath)) {
            $foldersDeleted++;
        } else {
            $skipped++;
            $errors[] = t('admin.galleries.discover_delete_folder_failed', 'Could not delete folder {folder}.', ['folder' => basename($absolutePath)]);
        }
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
    }

    return [
        'files_deleted' => $filesDeleted,
        'folders_deleted' => $foldersDeleted,
        'skipped' => $skipped,
        'errors' => $errors,
    ];
}


/**
 * Return known gallery folder paths keyed by normalized path.
 *
 * @return array<string, bool> Known gallery path lookup.
 */
function admin_gallery_discovery_known_gallery_paths(): array
{
    try {
        $paths = db()->query('SELECT folder_path FROM galleries')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable) {
        return [];
    }

    $known = [];
    foreach ($paths as $path) {
        $normalized = normalize_relative_path((string) $path);
        if ($normalized !== '') {
            $known[$normalized] = true;
        }
    }

    return $known;
}


/**
 * Build lookup indexes used to suppress already imported gallery folders.
 *
 * @param array<string, bool> $knownPaths Known gallery paths keyed by normalized folder path.
 * @return array<string, mixed> Discovery job fragment with exact, case-folded, and realpath lookups.
 */
function admin_gallery_discovery_known_path_job_state(array $knownPaths): array
{
    $pathKeys = [];
    $realPaths = [];
    foreach (array_keys($knownPaths) as $knownPath) {
        $normalized = normalize_relative_path((string) $knownPath);
        if ($normalized === '') {
            continue;
        }
        $pathKeys[admin_gallery_discovery_path_key($normalized)] = true;
        $realPathKey = admin_gallery_discovery_real_path_key($normalized);
        if ($realPathKey !== '') {
            $realPaths[$realPathKey] = true;
        }
    }

    return [
        'known_paths' => $knownPaths,
        'known_path_keys' => $pathKeys,
        'known_real_paths' => $realPaths,
    ];
}

/**
 * Return whether a relative path already belongs to an imported gallery.
 *
 * @param string $relativePath Directory path relative to the gallery root.
 * @param array<string, mixed> $job Discovery job state with known path indexes.
 * @return bool True when the path is already represented in the database.
 */
function admin_gallery_discovery_is_known_gallery_path(string $relativePath, array $job): bool
{
    $normalized = normalize_relative_path($relativePath);
    if ($normalized === '') {
        return false;
    }

    $knownPaths = is_array($job['known_paths'] ?? null) ? $job['known_paths'] : [];
    if (isset($knownPaths[$normalized])) {
        return true;
    }

    $knownPathKeys = is_array($job['known_path_keys'] ?? null) ? $job['known_path_keys'] : [];
    if (isset($knownPathKeys[admin_gallery_discovery_path_key($normalized)])) {
        return true;
    }

    $realPathKey = admin_gallery_discovery_real_path_key($normalized);
    $knownRealPaths = is_array($job['known_real_paths'] ?? null) ? $job['known_real_paths'] : [];
    return $realPathKey !== '' && isset($knownRealPaths[$realPathKey]);
}

/**
 * Build a stable case-folded lookup key for a filesystem path.
 *
 * @param string $path Relative or absolute filesystem path.
 * @return string Normalized lookup key.
 */
function admin_gallery_discovery_path_key(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    return function_exists('mb_strtolower') ? mb_strtolower($path, 'UTF-8') : strtolower($path);
}

/**
 * Return a case-folded realpath lookup key for an existing gallery folder.
 *
 * @param string $relativePath Directory path relative to the gallery root.
 * @return string Realpath lookup key, or an empty string when unavailable.
 */
function admin_gallery_discovery_real_path_key(string $relativePath): string
{
    try {
        $absolutePath = gallery_abs_path($relativePath);
    } catch (Throwable) {
        return '';
    }

    $realPath = realpath($absolutePath);
    if ($realPath === false || $realPath === '') {
        return '';
    }

    return admin_gallery_discovery_path_key($realPath);
}

/**
 * Return whether the job still has a directory waiting in its queue.
 *
 * @param array<string, mixed> $job Discovery job state.
 * @return bool True when another directory can be scanned.
 */
function admin_gallery_discovery_has_pending_directory(array $job): bool
{
    return (int) ($job['queue_index'] ?? 0) < count((array) ($job['queue'] ?? []));
}

/**
 * Scan one directory and update the discovery job state.
 *
 * @param array<string, mixed> $job Discovery job state, updated in place.
 * @param string $relativePath Directory path relative to the gallery root.
 */
function admin_gallery_discovery_scan_directory(array &$job, string $relativePath): void
{
    $absolutePath = $relativePath === '' ? galleries_root() : gallery_abs_path($relativePath);
    if (!is_dir($absolutePath)) {
        return;
    }

    $directImageCount = 0;
    $hasSidecar = false;

    try {
        $iterator = new DirectoryIterator($absolutePath);
    } catch (Throwable $exception) {
        admin_gallery_discovery_record_error($job, $relativePath, $exception->getMessage());
        return;
    }

    foreach ($iterator as $entry) {
        if (!$entry instanceof DirectoryIterator || $entry->isDot()) {
            continue;
        }

        if ($entry->isDir()) {
            if ($entry->isLink() || admin_gallery_discovery_should_skip_directory($entry)) {
                continue;
            }
            $childPath = normalize_relative_path(($relativePath === '' ? '' : $relativePath . '/') . $entry->getFilename());
            if ($childPath !== '' && empty($job['queued_paths'][$childPath])) {
                $job['queue'][] = $childPath;
                $job['queued_paths'][$childPath] = true;
            }
            continue;
        }

        if (!$entry->isFile()) {
            continue;
        }

        if ($entry->getFilename() === 'gallery.json') {
            $hasSidecar = true;
        }
        if (is_supported_image_path($entry->getFilename())) {
            $directImageCount++;
        }
    }

    if ($directImageCount > 0) {
        admin_gallery_discovery_record_image_count($job, $relativePath, $directImageCount);
        admin_gallery_discovery_mark_image_candidate_path($job, $relativePath);
    }
    if ($hasSidecar && $relativePath !== '') {
        if (!isset($job['sidecar_paths']) || !is_array($job['sidecar_paths'])) {
            $job['sidecar_paths'] = [];
        }
        $job['sidecar_paths'][normalize_relative_path($relativePath)] = true;
    }
}

/**
 * Return whether a directory should be ignored during discovery.
 *
 * @param SplFileInfo $entry Directory entry inspected by the scanner.
 * @return bool True when the directory is internal, hidden, or generated.
 */
function admin_gallery_discovery_should_skip_directory(SplFileInfo $entry): bool
{
    $name = $entry->getFilename();
    if ($name === '' || str_starts_with($name, '.')) {
        return true;
    }

    return in_array(strtolower($name), admin_gallery_discovery_ignored_directory_names(), true);
}

/**
 * Return directory names ignored by gallery discovery.
 *
 * @return array<int, string> Lowercase directory names.
 */
function admin_gallery_discovery_ignored_directory_names(): array
{
    return ['cache', 'thumbs', 'thumbnail', 'thumbnails', 'preview', 'previews'];
}

/**
 * Record direct and branch image totals for a scanned directory.
 *
 * @param array<string, mixed> $job Discovery job state, updated in place.
 * @param string $relativePath Directory path relative to the gallery root.
 * @param int $imageCount Number of supported images found directly in the directory.
 */
function admin_gallery_discovery_record_image_count(array &$job, string $relativePath, int $imageCount): void
{
    $normalized = normalize_relative_path($relativePath);
    if ($normalized === '' || $imageCount <= 0) {
        return;
    }

    if (!isset($job['direct_image_counts']) || !is_array($job['direct_image_counts'])) {
        $job['direct_image_counts'] = [];
    }
    if (!isset($job['branch_image_counts']) || !is_array($job['branch_image_counts'])) {
        $job['branch_image_counts'] = [];
    }

    $job['direct_image_counts'][$normalized] = (int) ($job['direct_image_counts'][$normalized] ?? 0) + $imageCount;
    foreach (admin_gallery_discovery_ancestor_paths($normalized, true) as $candidatePath) {
        $job['branch_image_counts'][$candidatePath] = (int) ($job['branch_image_counts'][$candidatePath] ?? 0) + $imageCount;
    }
}

/**
 * Mark a directory and its ancestors as candidates because an image was found below them.
 *
 * @param array<string, mixed> $job Discovery job state, updated in place.
 * @param string $relativePath Directory path relative to the gallery root.
 */
function admin_gallery_discovery_mark_image_candidate_path(array &$job, string $relativePath): void
{
    if ($relativePath === '') {
        return;
    }

    foreach (admin_gallery_discovery_ancestor_paths($relativePath, true) as $candidatePath) {
        admin_gallery_discovery_mark_candidate_path($job, $candidatePath);
    }
}

/**
 * Mark one normalized directory as a potential candidate.
 *
 * @param array<string, mixed> $job Discovery job state, updated in place.
 * @param string $relativePath Directory path relative to the gallery root.
 */
function admin_gallery_discovery_mark_candidate_path(array &$job, string $relativePath): void
{
    $normalized = normalize_relative_path($relativePath);
    if ($normalized === '') {
        return;
    }

    if (!isset($job['candidate_paths']) || !is_array($job['candidate_paths'])) {
        $job['candidate_paths'] = [];
    }
    $job['candidate_paths'][$normalized] = true;
}

/**
 * Return ancestor paths for a relative gallery path.
 *
 * @param string $relativePath Directory path relative to the gallery root.
 * @param bool $includeSelf Include the supplied path in the returned list.
 * @return array<int, string> Ancestor paths in root-to-leaf order.
 */
function admin_gallery_discovery_ancestor_paths(string $relativePath, bool $includeSelf = false): array
{
    $segments = array_values(array_filter(explode('/', normalize_relative_path($relativePath)), static fn (string $segment): bool => $segment !== ''));
    $limit = $includeSelf ? count($segments) : max(0, count($segments) - 1);
    $paths = [];
    $current = [];

    for ($index = 0; $index < $limit; $index++) {
        $current[] = $segments[$index];
        $paths[] = implode('/', $current);
    }

    return $paths;
}

/**
 * Complete a discovery job by building import-ready candidate rows.
 *
 * @param array<string, mixed> $job Discovery job state.
 * @return array<string, mixed> Completed job state.
 */
function admin_gallery_discovery_finish_job(array $job): array
{
    $candidatePaths = array_keys(is_array($job['candidate_paths'] ?? null) ? $job['candidate_paths'] : []);
    usort($candidatePaths, static function (string $left, string $right): int {
        $depth = substr_count($left, '/') <=> substr_count($right, '/');
        return $depth !== 0 ? $depth : strnatcasecmp($left, $right);
    });

    $branchImageCounts = is_array($job['branch_image_counts'] ?? null) ? $job['branch_image_counts'] : [];
    $candidates = [];
    foreach ($candidatePaths as $candidatePath) {
        if (admin_gallery_discovery_is_known_gallery_path($candidatePath, $job) || !is_dir(gallery_abs_path($candidatePath))) {
            continue;
        }
        if ((int) ($branchImageCounts[$candidatePath] ?? 0) <= 0) {
            continue;
        }
        $candidate = admin_gallery_discovery_candidate_from_path($candidatePath, $job);
        if ($candidate !== null) {
            $candidates[] = $candidate;
        }
    }

    $job['status'] = 'complete';
    $job['candidates'] = $candidates;
    $job['metadata_only_paths'] = admin_gallery_discovery_metadata_only_paths($job);
    $job['updated_at'] = time();
    return $job;
}

/**
 * Build import-ready candidate metadata for one folder.
 *
 * @param string $relativePath Directory path relative to the gallery root.
 * @param array<string, mixed> $job Discovery job state used for import-preview counts.
 * @return array<string, mixed>|null Candidate row, or null when the folder vanished.
 */
function admin_gallery_discovery_candidate_from_path(string $relativePath, array $job = []): ?array
{
    $relativePath = normalize_relative_path($relativePath);
    if ($relativePath === '' || !is_dir(gallery_abs_path($relativePath))) {
        return null;
    }

    $jsonPath = gallery_abs_path($relativePath) . DIRECTORY_SEPARATOR . 'gallery.json';
    $metadata = read_gallery_sidecar($jsonPath);

    $directImageCounts = is_array($job['direct_image_counts'] ?? null) ? $job['direct_image_counts'] : [];
    $branchImageCounts = is_array($job['branch_image_counts'] ?? null) ? $job['branch_image_counts'] : [];
    $sidecarPaths = is_array($job['sidecar_paths'] ?? null) ? $job['sidecar_paths'] : [];
    $candidatePaths = array_keys(is_array($job['candidate_paths'] ?? null) ? $job['candidate_paths'] : []);
    $parentPath = admin_gallery_discovery_parent_path($relativePath);

    $title = (string) ($metadata['title'] ?? basename($relativePath));
    $titleConflict = admin_gallery_discovery_existing_sibling_title_conflict($relativePath, $title);

    return [
        'folder_path' => $relativePath,
        'title' => $title,
        'description' => $metadata['description'] ?? '',
        'visibility' => gallery_visibility_storage_value((string) ($metadata['visibility'] ?? 'unpublished')),
        'access_mode' => $metadata['access_mode'] ?? 'normal',
        'access_listing' => $metadata['access_listing'] ?? 'listed',
        'banner_image_path' => $metadata['banner_image_path'] ?? null,
        'logo_image_path' => $metadata['logo_image_path'] ?? null,
        'separator_image_path' => $metadata['separator_image_path'] ?? null,
        'sort_order' => (int) ($metadata['sort_order'] ?? 0),
        'parent_folder_path' => $parentPath,
        'parent_title' => admin_gallery_discovery_parent_title($parentPath),
        'direct_image_count' => (int) ($directImageCounts[$relativePath] ?? 0),
        'branch_image_count' => (int) ($branchImageCounts[$relativePath] ?? $directImageCounts[$relativePath] ?? 0),
        'descendant_candidate_count' => admin_gallery_discovery_descendant_candidate_count($relativePath, $candidatePaths),
        'has_sidecar' => isset($sidecarPaths[$relativePath]),
        'existing_title_conflict' => $titleConflict !== null,
        'existing_title_conflict_title' => $titleConflict['title'] ?? '',
        'existing_title_conflict_path' => $titleConflict['folder_path'] ?? '',
    ];
}

/**
 * Return whether importing a path would duplicate an existing sibling gallery title.
 *
 * @param string $relativePath Directory path relative to the gallery root.
 * @return bool True when a sibling gallery already has the same display title.
 */
function admin_gallery_discovery_has_existing_sibling_title(string $relativePath): bool
{
    $candidate = admin_gallery_discovery_candidate_from_path($relativePath);
    if ($candidate === null) {
        return false;
    }

    return !empty($candidate['existing_title_conflict']);
}

/**
 * Find an existing sibling gallery with the same normalized title.
 *
 * @param string $relativePath Candidate folder path relative to the gallery root.
 * @param string $title Candidate gallery title.
 * @return array<string, mixed>|null Matching existing gallery row summary.
 */
function admin_gallery_discovery_existing_sibling_title_conflict(string $relativePath, string $title): ?array
{
    $relativePath = normalize_relative_path($relativePath);
    $parentPath = admin_gallery_discovery_parent_path($relativePath);
    $titleKey = admin_gallery_discovery_title_key($title);
    if ($relativePath === '' || $titleKey === '') {
        return null;
    }

    foreach (admin_gallery_discovery_existing_gallery_rows() as $gallery) {
        $existingPath = normalize_relative_path((string) ($gallery['folder_path'] ?? ''));
        if ($existingPath === '' || $existingPath === $relativePath) {
            continue;
        }
        if (admin_gallery_discovery_parent_path($existingPath) !== $parentPath) {
            continue;
        }
        if (admin_gallery_discovery_title_key((string) ($gallery['title'] ?? '')) !== $titleKey) {
            continue;
        }
        return [
            'id' => (int) ($gallery['id'] ?? 0),
            'title' => (string) ($gallery['title'] ?? ''),
            'folder_path' => $existingPath,
        ];
    }

    return null;
}

/**
 * Return normalized title text for duplicate detection.
 *
 * @param string $title Gallery display title.
 * @return string Case-folded title key.
 */
function admin_gallery_discovery_title_key(string $title): string
{
    $title = trim($title);
    $title = strtr($title, [
        'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
        'Á' => 'A', 'Č' => 'C', 'Ď' => 'D', 'É' => 'E', 'Ě' => 'E', 'Í' => 'I', 'Ň' => 'N', 'Ó' => 'O', 'Ř' => 'R', 'Š' => 'S', 'Ť' => 'T', 'Ú' => 'U', 'Ů' => 'U', 'Ý' => 'Y', 'Ž' => 'Z',
    ]);
    $title = function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
    $title = preg_replace('/[^\p{L}\p{N}]+/u', '', $title) ?: '';
    return $title;
}

/**
 * Return existing gallery rows used by duplicate detection.
 *
 * @return array<int, array<string, mixed>> Existing gallery rows.
 */
function admin_gallery_discovery_existing_gallery_rows(): array
{
    static $rows = null;
    if (is_array($rows)) {
        return $rows;
    }

    try {
        $rows = db()->query('SELECT id, title, folder_path FROM galleries')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $rows = [];
    }

    return $rows;
}

/**
 * Return the parent folder path for a discovered gallery candidate.
 *
 * @param string $relativePath Directory path relative to the gallery root.
 * @return string Parent folder path, or an empty string for the gallery root.
 */
function admin_gallery_discovery_parent_path(string $relativePath): string
{
    $relativePath = normalize_relative_path($relativePath);
    if ($relativePath === '' || !str_contains($relativePath, '/')) {
        return '';
    }

    return dirname($relativePath) === '.' ? '' : normalize_relative_path(dirname($relativePath));
}

/**
 * Return a readable parent label for a discovered gallery candidate.
 *
 * @param string $parentPath Parent folder path relative to the gallery root.
 * @return string Parent label shown in the import preview.
 */
function admin_gallery_discovery_parent_title(string $parentPath): string
{
    $parentPath = normalize_relative_path($parentPath);
    if ($parentPath === '') {
        return t('admin.galleries.discover_parent_root', 'Gallery root');
    }

    $gallery = find_gallery_by_folder_path($parentPath);
    if ($gallery && isset($gallery['title']) && trim((string) $gallery['title']) !== '') {
        return (string) $gallery['title'];
    }

    return basename($parentPath);
}

/**
 * Count candidate descendants that would be imported with a selected folder.
 *
 * @param string $relativePath Directory path relative to the gallery root.
 * @param array<int, string> $candidatePaths Candidate folder paths from the completed scan.
 * @return int Number of descendant candidates.
 */
function admin_gallery_discovery_descendant_candidate_count(string $relativePath, array $candidatePaths): int
{
    $relativePath = normalize_relative_path($relativePath);
    if ($relativePath === '') {
        return 0;
    }

    $prefix = $relativePath . '/';
    $count = 0;
    foreach ($candidatePaths as $candidatePath) {
        $candidatePath = normalize_relative_path((string) $candidatePath);
        if ($candidatePath !== '' && str_starts_with($candidatePath, $prefix)) {
            $count++;
        }
    }

    return $count;
}


/**
 * Return metadata-only folders that were found but are not import candidates.
 *
 * @param array<string, mixed> $job Discovery job state.
 * @return array<int, string> Sidecar folders without supported images in their branch.
 */
function admin_gallery_discovery_metadata_only_paths(array $job): array
{
    $sidecarPaths = array_keys(is_array($job['sidecar_paths'] ?? null) ? $job['sidecar_paths'] : []);
    $branchImageCounts = is_array($job['branch_image_counts'] ?? null) ? $job['branch_image_counts'] : [];
    $metadataOnlyPaths = [];

    foreach ($sidecarPaths as $sidecarPath) {
        $normalized = normalize_relative_path((string) $sidecarPath);
        if ($normalized === '' || admin_gallery_discovery_is_known_gallery_path($normalized, $job)) {
            continue;
        }
        if ((int) ($branchImageCounts[$normalized] ?? 0) > 0) {
            continue;
        }
        $metadataOnlyPaths[] = $normalized;
    }

    usort($metadataOnlyPaths, 'strnatcasecmp');
    return $metadataOnlyPaths;
}

/**
 * Return whether a directory branch contains at least one supported image file.
 *
 * @param string $relativePath Directory path relative to the gallery root.
 * @return bool True when an image exists anywhere in the branch.
 */
function admin_gallery_discovery_path_has_branch_images(string $relativePath): bool
{
    $relativePath = normalize_relative_path($relativePath);
    if ($relativePath === '' || !is_dir(gallery_abs_path($relativePath))) {
        return false;
    }

    try {
        $directory = new RecursiveDirectoryIterator(gallery_abs_path($relativePath), FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator($directory, static function (SplFileInfo $entry): bool {
            if ($entry->isDir()) {
                return !$entry->isLink() && !admin_gallery_discovery_should_skip_directory($entry);
            }
            return true;
        });
        $iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY);
    } catch (Throwable) {
        return false;
    }

    foreach ($iterator as $entry) {
        if ($entry instanceof SplFileInfo && $entry->isFile() && is_supported_image_path($entry->getFilename())) {
            return true;
        }
    }

    return false;
}

/**
 * Collect valid candidate paths under one selected subtree.
 *
 * @param string $relativePath Directory path relative to the gallery root.
 * @return array<int, string> Candidate paths in the selected subtree.
 */
function admin_gallery_discovery_collect_subtree_candidate_paths(string $relativePath): array
{
    $relativePath = normalize_relative_path($relativePath);
    if ($relativePath === '' || !is_dir(gallery_abs_path($relativePath))) {
        return [];
    }

    $job = [
        'queue' => [$relativePath],
        'queued_paths' => [$relativePath => true],
        'queue_index' => 0,
        'candidate_paths' => [],
        'errors' => [],
    ];

    while (admin_gallery_discovery_has_pending_directory($job)) {
        $currentPath = (string) $job['queue'][(int) $job['queue_index']];
        $job['queue_index'] = (int) $job['queue_index'] + 1;
        admin_gallery_discovery_scan_directory($job, $currentPath);
    }

    $paths = array_keys(is_array($job['candidate_paths'] ?? null) ? $job['candidate_paths'] : []);
    usort($paths, static function (string $left, string $right): int {
        $depth = substr_count($left, '/') <=> substr_count($right, '/');
        return $depth !== 0 ? $depth : strnatcasecmp($left, $right);
    });

    return $paths;
}

/**
 * Record a bounded discovery warning for later display and logging.
 *
 * @param array<string, mixed> $job Discovery job state, updated in place.
 * @param string $relativePath Directory path relative to the gallery root.
 * @param string $message Error message from the filesystem operation.
 */
function admin_gallery_discovery_record_error(array &$job, string $relativePath, string $message): void
{
    if (!isset($job['errors']) || !is_array($job['errors'])) {
        $job['errors'] = [];
    }
    if (count($job['errors']) >= 10) {
        return;
    }

    $job['errors'][] = trim(($relativePath === '' ? '[root]' : $relativePath) . ': ' . $message);
}

/**
 * Return public state for a discovery job.
 *
 * @param array<string, mixed> $job Discovery job state.
 * @param bool $includeCandidates Include candidate rows in the payload.
 * @return array<string, mixed> JSON-safe public state.
 */
function admin_gallery_discovery_public_state(array $job, bool $includeCandidates = false): array
{
    $processed = (int) ($job['processed_directories'] ?? $job['queue_index'] ?? 0);
    $total = max($processed, (int) ($job['discovered_directories'] ?? count((array) ($job['queue'] ?? []))));
    $candidatePaths = is_array($job['candidate_paths'] ?? null) ? $job['candidate_paths'] : [];
    $candidates = is_array($job['candidates'] ?? null) ? $job['candidates'] : [];
    $status = (string) ($job['status'] ?? 'running');
    $done = $status === 'complete' || $status === 'error';
    $candidateCount = $status === 'complete' ? count($candidates) : count($candidatePaths);
    $metadataOnlyPaths = is_array($job['metadata_only_paths'] ?? null) ? $job['metadata_only_paths'] : [];

    $state = [
        'ok' => $status !== 'error',
        'status' => $status,
        'done' => $done,
        'job_token' => (string) ($job['token'] ?? ''),
        'processed_directories' => $processed,
        'discovered_directories' => $total,
        'queued_directories' => max(0, $total - $processed),
        'candidate_count' => $candidateCount,
        'metadata_only_count' => count($metadataOnlyPaths),
        'percent' => $total > 0 ? min(100.0, ($processed / $total) * 100.0) : 0.0,
        'errors' => is_array($job['errors'] ?? null) ? $job['errors'] : [],
    ];

    if ($includeCandidates || $status === 'complete') {
        $state['candidates'] = $candidates;
    }

    return $state;
}

/**
 * Return a standard missing-job response for expired discovery state.
 *
 * @return array<string, mixed> JSON-safe public state.
 */
function admin_gallery_discovery_missing_state(): array
{
    return [
        'ok' => false,
        'status' => 'missing',
        'done' => true,
        'job_token' => '',
        'processed_directories' => 0,
        'discovered_directories' => 0,
        'queued_directories' => 0,
        'candidate_count' => 0,
        'metadata_only_count' => 0,
        'percent' => 0.0,
        'errors' => [],
    ];
}

/**
 * Read one discovery job from the admin session.
 *
 * @param string $token Session job token supplied by the browser.
 * @return array<string, mixed>|null Discovery job state, or null when missing.
 */
function admin_gallery_discovery_read_job(string $token): ?array
{
    $token = preg_replace('/[^A-Fa-f0-9]/', '', $token) ?: '';
    if ($token === '' || empty($_SESSION['admin_gallery_discovery_jobs'][$token]) || !is_array($_SESSION['admin_gallery_discovery_jobs'][$token])) {
        return null;
    }

    $job = $_SESSION['admin_gallery_discovery_jobs'][$token];
    if (time() - (int) ($job['updated_at'] ?? $job['started_at'] ?? 0) > ADMIN_GALLERY_DISCOVERY_JOB_TTL_SECONDS) {
        unset($_SESSION['admin_gallery_discovery_jobs'][$token]);
        return null;
    }

    return $job;
}

/**
 * Write one discovery job into the admin session.
 *
 * @param array<string, mixed> $job Discovery job state.
 */
function admin_gallery_discovery_write_job(array $job): void
{
    $token = preg_replace('/[^A-Fa-f0-9]/', '', (string) ($job['token'] ?? '')) ?: '';
    if ($token === '') {
        return;
    }

    if (!isset($_SESSION['admin_gallery_discovery_jobs']) || !is_array($_SESSION['admin_gallery_discovery_jobs'])) {
        $_SESSION['admin_gallery_discovery_jobs'] = [];
    }
    $_SESSION['admin_gallery_discovery_jobs'][$token] = $job;
}

/**
 * Remove stale discovery jobs from the admin session.
 */
function admin_gallery_discovery_cleanup_jobs(): void
{
    if (empty($_SESSION['admin_gallery_discovery_jobs']) || !is_array($_SESSION['admin_gallery_discovery_jobs'])) {
        $_SESSION['admin_gallery_discovery_jobs'] = [];
        return;
    }

    foreach ($_SESSION['admin_gallery_discovery_jobs'] as $token => $job) {
        if (!is_array($job) || time() - (int) ($job['updated_at'] ?? $job['started_at'] ?? 0) > ADMIN_GALLERY_DISCOVERY_JOB_TTL_SECONDS) {
            unset($_SESSION['admin_gallery_discovery_jobs'][$token]);
        }
    }

    if (count($_SESSION['admin_gallery_discovery_jobs']) <= 5) {
        return;
    }

    uasort($_SESSION['admin_gallery_discovery_jobs'], static function (array $left, array $right): int {
        return (int) ($right['updated_at'] ?? 0) <=> (int) ($left['updated_at'] ?? 0);
    });
    $_SESSION['admin_gallery_discovery_jobs'] = array_slice($_SESSION['admin_gallery_discovery_jobs'], 0, 5, true);
}
