<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_sidecars.php
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

require_once __DIR__ . '/gallery_description_layout_compatibility.php';

use DirectoryIterator;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use function Gallery\Core\is_supported_image_path;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;
use function Gallery\Models\gallery_model_folder_paths;
use function Gallery\Models\gallery_model_insert;
use function Gallery\Models\gallery_model_insert_linked_owned;
use function Gallery\Models\gallery_model_assert_linked_creation_transaction;
use function Gallery\Models\gallery_model_linked_reservation_assert_pending;
use function Gallery\Models\gallery_model_prepend_sort_order;
use function Gallery\Models\gallery_model_unique_slug;
use function Gallery\Models\gallery_model_attachment_usage_seed_empty;
use function Gallery\Models\public_path_model_linked_creation_rows;
use function Gallery\Models\public_path_model_assign_linked_creation;

require_once __DIR__ . '/gallery_creation_safety.php';
require_once __DIR__ . '/gallery_edit_concurrency.php';

/**
 * Bound linked gallery folder and URL paths before database insertion.
 * Type: int. Units: UTF-8 bytes. Scope: one linked gallery path.
 * Consumers: create_empty_gallery_linked_owned.
 * Rationale: 1024 bytes conservatively fit the persisted VARCHAR(1024).
 */
const LINKED_GALLERY_CREATE_PATH_MAX_BYTES = 1024;

/**
 * Bound linked gallery legacy and public slugs before database insertion.
 * Type: int. Units: UTF-8 bytes. Scope: one linked gallery slug.
 * Consumers: create_empty_gallery_linked_owned.
 * Rationale: 255 bytes conservatively fit the persisted VARCHAR(255).
 */
const LINKED_GALLERY_CREATE_SLUG_MAX_BYTES = 255;

/**
 * Bound catalog rows considered before linked gallery creation.
 * Type: int. Units: gallery rows. Scope: one delegated create request.
 * Consumers: create_empty_gallery_linked_owned.
 * Rationale: 10000 bounds shared-host memory; larger catalogs fail closed.
 */
const LINKED_GALLERY_CREATE_CATALOG_MAX_ROWS = 10000;

/**
 * Bound indexed slug lookups during delegated gallery creation.
 * Type: int. Units: candidate slugs. Scope: one linked creation request.
 * Consumers: create_empty_gallery_linked_owned.
 * Rationale: 128 indexed candidates bound lock time without allowing collisions.
 */
const LINKED_GALLERY_CREATE_SLUG_MAX_ATTEMPTS = 128;

/**
 * Bound a sidecar read and its prepared replacement for linked visibility.
 * Type: int. Units: bytes. Scope: one existing gallery.json file.
 * Consumers: gallery_sidecar_visibility_prepare.
 * Rationale: 1048576 bytes bounds memory and lock time for delegated changes.
 */
const LINKED_VISIBILITY_SIDECAR_MAX_BYTES = 1048576;

/**
 * Bound a randomly named private sidecar staging file.
 * Type: int. Units: random bytes. Scope: one staging filename.
 * Consumers: gallery_sidecar_visibility_prepare.
 * Rationale: 16 bytes make accidental staging-name collisions negligible.
 */
const LINKED_VISIBILITY_SIDECAR_RANDOM_BYTES = 16;

/**
 * Identify the permanent visibility recovery marker in a gallery folder.
 * Type: string. Units: relative filename. Scope: one gallery directory.
 * Consumers: gallery_visibility_marker_prepare,
 *     gallery_visibility_marker_assert_importable.
 * Rationale: .gallery-visibility-pending.json gives recovery and writer
 *     guards the same marker to find after interrupted visibility changes.
 */
const LINKED_VISIBILITY_PENDING_MARKER = '.gallery-visibility-pending.json';

/**
 * Bound physical directories inspected for pending visibility work.
 * Type: int. Units: directories. Scope: one physical subtree observation.
 * Consumers: gallery_visibility_marker_assert_tree_clear.
 * Rationale: 10000 bounds memory and work; larger trees remain unavailable.
 */
const LINKED_VISIBILITY_TREE_MAX_DIRECTORIES = 10000;

/**
 * Bound physical entries inspected for pending visibility work.
 * Type: int. Units: directory entries. Scope: one physical subtree observation.
 * Consumers: gallery_visibility_marker_assert_tree_clear.
 * Rationale: 100000 bounds traversal work without silently skipping entries.
 */
const LINKED_VISIBILITY_TREE_MAX_ENTRIES = 100000;

/**
 * Require proven absence of a visibility marker in a folder and its ancestors.
 *
 * @param string $relativePath Canonical gallery-root-relative directory path.
 * @return void Returns only when every relevant directory proves marker absence.
 * @throws RuntimeException When a marker exists or any observation is uncertain.
 */
function gallery_visibility_marker_assert_importable(string $relativePath): void
{
    $normalized = normalize_relative_path($relativePath);
    if ($normalized === '' || $normalized !== str_replace('\\', '/', $relativePath)) {
        throw new RuntimeException('Gallery visibility recovery state is unavailable.');
    }
    $path = '';
    foreach (explode('/', $normalized) as $segment) {
        $path = $path === '' ? $segment : $path . '/' . $segment;
        if (gallery_creation_target_observation(
            $path . '/' . LINKED_VISIBILITY_PENDING_MARKER,
        ) !== \Gallery\Core\FILESYSTEM_OBSERVATION_MISSING) {
            throw new RuntimeException('Gallery visibility recovery state is unavailable.');
        }
    }
}

/**
 * Observe a directory and optionally collect its real child directories.
 *
 * @param string $directory Canonical directory beneath the trusted root.
 * @param string $root Canonical independently trusted filesystem root.
 * @param bool $subtree True to reject links/special entries and collect children.
 * @param int $entryCount Running bounded count, updated for every entry.
 * @return list<string> Canonical child directories when subtree traversal is selected.
 * @throws RuntimeException When observation, identity or limits are uncertain.
 */
function gallery_visibility_marker_scan_directory(
    string $directory,
    string $root,
    bool $subtree,
    int &$entryCount,
): array {
    clearstatcache(true, $directory);
    $before = @lstat($directory);
    if (!is_array($before) || is_link($directory) || !is_dir($directory)
        || !is_readable($directory) || realpath($directory) !== $directory
        || !gallery_normalized_path_inside_root($root, $directory)) {
        throw new RuntimeException('Gallery visibility recovery state is unavailable.');
    }
    $children = [];
    set_error_handler(static function (int $severity, string $message): never {
        throw new RuntimeException('Gallery visibility tree observation failed.');
    });
    try {
        $iterator = new DirectoryIterator($directory);
        foreach ($iterator as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $entryCount++;
            if ($entryCount > LINKED_VISIBILITY_TREE_MAX_ENTRIES) {
                throw new RuntimeException('Gallery visibility observation limit reached.');
            }
            $name = $entry->getFilename();
            if ($name === LINKED_VISIBILITY_PENDING_MARKER
                || (PHP_OS_FAMILY === 'Windows'
                    && strcasecmp($name, LINKED_VISIBILITY_PENDING_MARKER) === 0)) {
                throw new RuntimeException('Gallery visibility recovery state is unavailable.');
            }
            if (!$subtree) {
                continue;
            }
            if ($entry->isLink()) {
                throw new RuntimeException('Gallery visibility tree is redirected.');
            }
            if ($entry->isDir()) {
                $children[] = $entry->getPathname();
            } elseif (!$entry->isFile()) {
                throw new RuntimeException('Gallery visibility tree is unavailable.');
            }
        }
    } catch (Throwable $exception) {
        throw new RuntimeException('Gallery visibility tree is unavailable.', 0, $exception);
    } finally {
        restore_error_handler();
    }
    clearstatcache(true, $directory);
    $after = @lstat($directory);
    if (!is_array($after) || is_link($directory)
        || $before['dev'] !== $after['dev'] || $before['ino'] !== $after['ino']
        || realpath($directory) !== $directory) {
        throw new RuntimeException('Gallery visibility tree changed during observation.');
    }
    return $children;
}

/**
 * Refuse pending visibility markers under a caller-verified physical root.
 *
 * @param string $absolutePath Canonical existing directory within the trusted root.
 * @param string $trustedRoot Canonical owner root independently verified by the caller.
 * @return void Returns only after bounded ancestor and complete subtree observation.
 * @throws RuntimeException When the root, tree or marker state is uncertain.
 */
function gallery_visibility_marker_assert_tree_clear(string $absolutePath, string $trustedRoot): void
{
    clearstatcache(true);
    if (realpath($trustedRoot) !== $trustedRoot || is_link($trustedRoot)
        || realpath($absolutePath) !== $absolutePath || is_link($absolutePath)
        || !is_dir($trustedRoot) || !is_dir($absolutePath)
        || !gallery_normalized_path_inside_root($trustedRoot, $absolutePath)) {
        throw new RuntimeException('Gallery visibility tree is unavailable.');
    }
    $rootStat = @lstat($trustedRoot);
    if (!is_array($rootStat)) {
        throw new RuntimeException('Gallery visibility tree is unavailable.');
    }
    $entries = 0;
    $directories = 0;
    $current = $absolutePath;
    while ($current !== $trustedRoot) {
        $parent = dirname($current);
        if ($parent === $current) {
            throw new RuntimeException('Gallery visibility tree is unavailable.');
        }
        $current = $parent;
        $directories++;
        if ($directories > LINKED_VISIBILITY_TREE_MAX_DIRECTORIES) {
            throw new RuntimeException('Gallery visibility observation limit reached.');
        }
        if (!gallery_normalized_path_inside_root($trustedRoot, $current)) {
            throw new RuntimeException('Gallery visibility tree is unavailable.');
        }
        gallery_visibility_marker_scan_directory($current, $trustedRoot, false, $entries);
    }
    $pending = [$absolutePath];
    while ($pending !== []) {
        $directory = array_pop($pending);
        $directories++;
        if ($directories > LINKED_VISIBILITY_TREE_MAX_DIRECTORIES) {
            throw new RuntimeException('Gallery visibility observation limit reached.');
        }
        foreach (gallery_visibility_marker_scan_directory(
            $directory, $trustedRoot, true, $entries,
        ) as $child) {
            $pending[] = $child;
        }
    }
    clearstatcache(true, $trustedRoot);
    $rootAfter = @lstat($trustedRoot);
    if (!is_array($rootAfter) || is_link($trustedRoot)
        || $rootAfter['dev'] !== $rootStat['dev'] || $rootAfter['ino'] !== $rootStat['ino']) {
        throw new RuntimeException('Gallery visibility tree changed during observation.');
    }
}

/**
 * Inspect one live gallery subtree after checking its marker-bearing ancestors.
 *
 * @param string $relativePath Canonical gallery-root-relative directory.
 * @return void Returns only after complete bounded physical observation.
 * @throws RuntimeException When the tree or any required observation is unsafe.
 */
function gallery_visibility_marker_assert_subtree_clear(string $relativePath): void
{
    gallery_visibility_marker_assert_importable($relativePath);
    $root = realpath(galleries_root());
    $target = gallery_abs_path($relativePath);
    $current = galleries_root();
    foreach (explode('/', $relativePath) as $part) {
        $current .= DIRECTORY_SEPARATOR . $part;
        if (is_link($current)) {
            throw new RuntimeException('Gallery visibility tree is redirected.');
        }
    }
    $targetReal = realpath($target);
    if (!is_string($root) || !is_string($targetReal)) {
        throw new RuntimeException('Gallery visibility tree is unavailable.');
    }
    gallery_visibility_marker_assert_tree_clear($targetReal, $root);
}

/**
 * Gallery discovery and sidecar metadata helpers.
 *
 * These functions keep the filesystem-first model intact. The database remains
 * an index, while gallery.json sidecars provide optional metadata next to the
 * gallery folder. No theme, favicon, or custom CSS settings are handled here.
 */

/**
 * Write gallery metadata into a sidecar before or after a DB row exists.
 *
 * @param string $folderPath Folder path filesystem path.
 * @param array<string,string|int|float|bool|null|list<string>|array<string,string>> $data Sidecar metadata fields to encode as JSON.
 * @return bool True when the condition matches.
 */
function write_gallery_sidecar_for_path(string $folderPath, array $data): bool
{
    // $path stores an intermediate value used by the surrounding gallery workflow.
    $path =
        gallery_abs_path($folderPath) . DIRECTORY_SEPARATOR . 'gallery.json';
    // $directory stores the destination folder where gallery.json should be written.
    $directory = dirname($path);
    if (!is_dir($directory)) {
        return false;
    }

    return (
        @file_put_contents($path, json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        )) !== false
    );
}

/**
 * Replace one top-level visibility string while retaining all other JSON bytes.
 *
 * @param string $json Complete bounded JSON object from an existing sidecar.
 * @param string $before Exact currently stored visibility.
 * @param string $after Validated destination visibility.
 * @return string Replacement JSON with only the value bytes changed.
 * @throws Throwable When the object is malformed, ambiguous or already inconsistent.
 */
function gallery_sidecar_visibility_replacement(string $json, string $before, string $after): string
{
    $object = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    if (!$object instanceof \stdClass || !property_exists($object, 'visibility')
        || !is_string($object->visibility) || $object->visibility !== $before) {
        throw new RuntimeException('Gallery sidecar is unavailable.');
    }
    $length = strlen($json);
    $depth = 0;
    $previous = '';
    $start = null;
    $end = null;
    for ($index = 0; $index < $length; $index++) {
        $character = $json[$index];
        if ($character === '"') {
            $stringStart = $index;
            for ($index++; $index < $length; $index++) {
                if ($json[$index] === '\\') {
                    $index++;
                } elseif ($json[$index] === '"') {
                    break;
                }
            }
            if ($depth === 1 && ($previous === '{' || $previous === ',')) {
                $key = json_decode(substr($json, $stringStart, $index - $stringStart + 1),
                    true, 512, JSON_THROW_ON_ERROR);
                if ($key === 'visibility') {
                    if ($start !== null) {
                        throw new RuntimeException('Gallery sidecar is ambiguous.');
                    }
                    $cursor = $index + 1;
                    while ($cursor < $length && ctype_space($json[$cursor])) {
                        $cursor++;
                    }
                    if (($json[$cursor] ?? '') !== ':') {
                        throw new RuntimeException('Gallery sidecar is invalid.');
                    }
                    $cursor++;
                    while ($cursor < $length && ctype_space($json[$cursor])) {
                        $cursor++;
                    }
                    if (($json[$cursor] ?? '') !== '"') {
                        throw new RuntimeException('Gallery sidecar is invalid.');
                    }
                    $start = $cursor;
                    for ($cursor++; $cursor < $length; $cursor++) {
                        if ($json[$cursor] === '\\') {
                            $cursor++;
                        } elseif ($json[$cursor] === '"') {
                            $end = $cursor + 1;
                            break;
                        }
                    }
                }
            }
            if ($depth === 1) {
                $previous = '"';
            }
        } elseif ($character === '{' || $character === '[') {
            $depth++;
            if ($depth === 1) {
                $previous = $character;
            }
        } elseif ($character === '}' || $character === ']') {
            $depth--;
            if ($depth === 1) {
                $previous = $character;
            }
        } elseif ($depth === 1 && !ctype_space($character)) {
            $previous = $character;
        }
    }
    if ($start === null || $end === null) {
        throw new RuntimeException('Gallery sidecar is unavailable.');
    }
    return substr($json, 0, $start)
        . json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
        . substr($json, $end);
}

/**
 * Synchronize one verified directory or fail closed on unsupported platforms.
 *
 * @param string $directory Resolved gallery directory under the trusted root.
 * @param int $device Expected directory device.
 * @param int $inode Expected directory inode.
 * @return void Returns only after the verified directory handle reports fsync success.
 * @throws RuntimeException When directory identity or synchronization is unavailable.
 */
function gallery_visibility_sync_directory(string $directory, int $device, int $inode): void
{
    if (!function_exists('fsync') || !in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true)
        || is_link($directory) || realpath($directory) !== $directory
        || !gallery_filesystem_path_inside_root($directory)) {
        throw new RuntimeException('Gallery directory synchronization is unavailable.');
    }
    $handle = @fopen($directory, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Gallery directory synchronization is unavailable.');
    }
    try {
        $before = fstat($handle);
        clearstatcache(true, $directory);
        $path = @lstat($directory);
        if (!is_array($before) || !is_array($path) || !is_dir($directory)
            || $before['dev'] !== $device || $before['ino'] !== $inode
            || $path['dev'] !== $device || $path['ino'] !== $inode
            || !@fsync($handle)) {
            throw new RuntimeException('Gallery directory synchronization is unavailable.');
        }
        clearstatcache(true, $directory);
        $after = @lstat($directory);
        if (!is_array($after) || is_link($directory)
            || $after['dev'] !== $device || $after['ino'] !== $inode) {
            throw new RuntimeException('Gallery directory changed during synchronization.');
        }
    } finally {
        fclose($handle);
    }
}

/**
 * Synchronize an identified regular file without accepting a redirected path.
 *
 * @param string $path Exact already-created file path.
 * @param int $device Expected device.
 * @param int $inode Expected inode.
 * @return void Returns only after a matching regular file reports fsync success.
 * @throws RuntimeException When file identity or synchronization is unavailable.
 */
function gallery_visibility_sync_file(string $path, int $device, int $inode): void
{
    if (!function_exists('fsync') || is_link($path)) {
        throw new RuntimeException('Gallery file synchronization is unavailable.');
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Gallery file synchronization is unavailable.');
    }
    try {
        $opened = fstat($handle);
        clearstatcache(true, $path);
        $current = @lstat($path);
        if (!is_array($opened) || !is_array($current) || is_link($path)
            || !is_file($path) || ($current['nlink'] ?? 0) !== 1
            || $opened['dev'] !== $device || $opened['ino'] !== $inode
            || $current['dev'] !== $device || $current['ino'] !== $inode
            || !@fsync($handle)) {
            throw new RuntimeException('Gallery file synchronization is unavailable.');
        }
    } finally {
        fclose($handle);
    }
}

/**
 * Prepare and verify a private same-directory visibility-only sidecar replacement.
 *
 * @param array{folder_path:string,visibility:string,...} $gallery Locked current row.
 * @param string $visibility Validated destination storage visibility.
 * @return array{path:string,stage:string,original:string,replacement:string,dev:int,ino:int,stage_dev:int,stage_ino:int,folder_dev:int,folder_ino:int,mode:int}
 *     Private staging identity retained under the cooperating writer lease.
 * @throws Throwable When existing content, ownership or staging is uncertain.
 */
function gallery_sidecar_visibility_prepare(array $gallery, string $visibility): array
{
    $folder = gallery_abs_path($gallery['folder_path']);
    $realFolder = realpath($folder);
    if ($realFolder === false || !is_dir($realFolder) || is_link($folder)
        || !gallery_filesystem_path_inside_root($realFolder)) {
        throw new RuntimeException('Gallery sidecar directory is unavailable.');
    }
    $path = $realFolder . DIRECTORY_SEPARATOR . 'gallery.json';
    $folderStat = @stat($realFolder);
    if (!is_array($folderStat)) {
        throw new RuntimeException('Gallery sidecar directory is unavailable.');
    }
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if (!is_array($stat) || is_link($path) || !is_file($path) || !is_readable($path)
        || ($stat['nlink'] ?? 0) !== 1 || ($stat['size'] ?? -1) < 0
        || $stat['size'] > LINKED_VISIBILITY_SIDECAR_MAX_BYTES) {
        throw new RuntimeException('Gallery sidecar is unavailable.');
    }
    $original = @file_get_contents($path, false, null, 0, LINKED_VISIBILITY_SIDECAR_MAX_BYTES + 1);
    clearstatcache(true, $path);
    $afterRead = @lstat($path);
    if (!is_string($original) || strlen($original) !== $stat['size']
        || !is_array($afterRead) || $afterRead['dev'] !== $stat['dev']
        || $afterRead['ino'] !== $stat['ino'] || is_link($path)) {
        throw new RuntimeException('Gallery sidecar changed during observation.');
    }
    $replacement = gallery_sidecar_visibility_replacement(
        $original, $gallery['visibility'], $visibility,
    );
    $stage = $realFolder . DIRECTORY_SEPARATOR . '.gallery-visibility-'
        . bin2hex(random_bytes(LINKED_VISIBILITY_SIDECAR_RANDOM_BYTES));
    $handle = @fopen($stage, 'x+b');
    if ($handle === false) {
        throw new RuntimeException('Gallery sidecar staging is unavailable.');
    }
    try {
        if (!@chmod($stage, 0600)) {
            throw new RuntimeException('Gallery sidecar staging is unavailable.');
        }
        $offset = 0;
        $length = strlen($replacement);
        while ($offset < $length) {
            $written = fwrite($handle, substr($replacement, $offset));
            if (!is_int($written) || $written <= 0) {
                throw new RuntimeException('Gallery sidecar staging is incomplete.');
            }
            $offset += $written;
        }
        if (!fflush($handle) || !function_exists('fsync') || !@fsync($handle)
            || !rewind($handle)
            || stream_get_contents($handle) !== $replacement) {
            throw new RuntimeException('Gallery sidecar staging is incomplete.');
        }
        $stageStat = fstat($handle);
        if (!is_array($stageStat) || $stageStat['uid'] !== $stat['uid']
            || $stageStat['gid'] !== $stat['gid']) {
            throw new RuntimeException('Gallery sidecar permissions cannot be preserved.');
        }
    } catch (Throwable $exception) {
        fclose($handle);
        // Preserve even incomplete staging evidence; a pathname alone does not
        // prove that a concurrently replaced object still belongs to us.
        throw $exception;
    }
    fclose($handle);
    return ['path' => $path, 'stage' => $stage, 'original' => $original,
        'replacement' => $replacement, 'dev' => $stat['dev'], 'ino' => $stat['ino'],
        'stage_dev' => $stageStat['dev'], 'stage_ino' => $stageStat['ino'],
        'folder_dev' => $folderStat['dev'], 'folder_ino' => $folderStat['ino'],
        'mode' => $stat['mode'] & 0777];
}

/**
 * Publish and read back a prepared replacement after the database commit.
 *
 * @param array{path:string,stage:string,original:string,replacement:string,dev:int,ino:int,stage_dev:int,stage_ino:int,folder_dev:int,folder_ino:int,mode:int} $prepared
 *     Private staging identity from the same cooperating writer lease.
 * @return void Publishes exactly the prepared content or raises uncertainty.
 * @throws RuntimeException If either file changed or publication failed.
 */
function gallery_sidecar_visibility_publish(array $prepared): void
{
    clearstatcache(true, $prepared['path']);
    $stat = @lstat($prepared['path']);
    $stageStat = @lstat($prepared['stage']);
    $folder = dirname($prepared['path']);
    $folderStat = @stat($folder);
    if (!is_array($stat) || is_link($prepared['path'])
        || ($stat['nlink'] ?? 0) !== 1
        || $stat['dev'] !== $prepared['dev'] || $stat['ino'] !== $prepared['ino']
        || !is_array($folderStat) || realpath($folder) !== $folder
        || $folderStat['dev'] !== $prepared['folder_dev']
        || $folderStat['ino'] !== $prepared['folder_ino']
        || !gallery_filesystem_path_inside_root($folder)
        || @file_get_contents($prepared['path'], false, null, 0,
            strlen($prepared['original']) + 1) !== $prepared['original']
        || !is_array($stageStat) || is_link($prepared['stage'])
        || ($stageStat['nlink'] ?? 0) !== 1
        || $stageStat['dev'] !== $prepared['stage_dev']
        || $stageStat['ino'] !== $prepared['stage_ino']
        || @file_get_contents($prepared['stage'], false, null, 0,
            strlen($prepared['replacement']) + 1) !== $prepared['replacement']
        || !@chmod($prepared['stage'], $prepared['mode'])) {
        throw new RuntimeException('Gallery sidecar publication is uncertain.');
    }
    gallery_visibility_sync_file(
        $prepared['stage'], $prepared['stage_dev'], $prepared['stage_ino'],
    );
    if (!@rename($prepared['stage'], $prepared['path'])) {
        throw new RuntimeException('Gallery sidecar publication is uncertain.');
    }
    clearstatcache(true, $prepared['path']);
    $published = @lstat($prepared['path']);
    if (!is_array($published) || is_link($prepared['path'])
        || ($published['nlink'] ?? 0) !== 1
        || $published['dev'] !== $prepared['stage_dev']
        || $published['ino'] !== $prepared['stage_ino']
        || @file_get_contents($prepared['path'], false, null, 0,
            strlen($prepared['replacement']) + 1) !== $prepared['replacement']) {
        throw new RuntimeException('Gallery sidecar publication is uncertain.');
    }
    gallery_visibility_sync_directory(
        $folder, $prepared['folder_dev'], $prepared['folder_ino'],
    );
}

/**
 * Verify the persistent marker for the current visibility transition.
 *
 * @param array{path:string,dev:int,ino:int,folder_dev:int,folder_ino:int,content:string} $marker Previously observed marker, directory identities and exact bytes.
 * @return void Returns only when the same single-link regular marker is intact.
 * @throws RuntimeException When the marker is missing, redirected or changed.
 */
function gallery_visibility_marker_assert_owned(array $marker): void
{
    clearstatcache(true, $marker['path']);
    $folder = dirname($marker['path']);
    $folderStat = @lstat($folder);
    $before = @lstat($marker['path']);
    if (!is_array($folderStat) || is_link($folder) || !is_dir($folder)
        || realpath($folder) !== $folder || !gallery_filesystem_path_inside_root($folder)
        || $folderStat['dev'] !== $marker['folder_dev']
        || $folderStat['ino'] !== $marker['folder_ino']
        || !is_array($before) || is_link($marker['path']) || !is_file($marker['path'])
        || ($before['nlink'] ?? 0) !== 1 || $before['dev'] !== $marker['dev']
        || $before['ino'] !== $marker['ino']
        || @file_get_contents($marker['path'], false, null, 0,
            strlen($marker['content']) + 1) !== $marker['content']) {
        throw new RuntimeException('Gallery visibility marker is unavailable.');
    }
    clearstatcache(true, $marker['path']);
    $after = @lstat($marker['path']);
    if (!is_array($after) || is_link($marker['path'])
        || ($after['nlink'] ?? 0) !== 1 || $after['dev'] !== $marker['dev']
        || $after['ino'] !== $marker['ino']) {
        throw new RuntimeException('Gallery visibility marker changed.');
    }
}

/**
 * Bind recovery to unchanged gallery fields outside the visibility update.
 *
 * @param array<string,int|float|string|bool|null> $row Locked gallery columns.
 * @return string Lowercase SHA-256 of the remaining columns and their types.
 */
function gallery_visibility_row_hash(array $row): string
{
    unset($row['visibility'], $row['edit_revision'], $row['updated_at']);
    ksort($row, SORT_STRING);
    return hash('sha256', serialize($row));
}

/**
 * Persist a recovery marker and private sidecar before any visibility update.
 *
 * The marker survives an interrupted write and records which exact sidecar
 * bytes and gallery row can be reconciled under the shared writer lock.
 *
 * @param array{id:int|string,folder_path:string,visibility:string,edit_revision:int|string,...} $gallery Locked current gallery row.
 * @param string $visibility Requested storage visibility.
 * @return array{sidecar:array<string,mixed>,marker:array{path:string,dev:int,ino:int,folder_dev:int,folder_ino:int,content:string}} Prepared target sidecar and retained marker proof.
 * @throws Throwable When synchronization, identity or sidecar state is uncertain.
 */
function gallery_visibility_marker_prepare(array $gallery, string $visibility): array
{
    if ((int) ($gallery['id'] ?? 0) <= 0
        || gallery_edit_revision($gallery) === ''
        || !in_array($visibility, ['public', 'private', 'unpublished', 'draft'], true)) {
        throw new RuntimeException('Gallery visibility recovery input is unavailable.');
    }
    gallery_visibility_marker_assert_importable($gallery['folder_path']);
    $cursor = galleries_root();
    foreach (explode('/', $gallery['folder_path']) as $part) {
        $cursor .= DIRECTORY_SEPARATOR . $part;
        clearstatcache(true, $cursor);
        if (is_link($cursor)) {
            throw new RuntimeException('Gallery visibility directory is redirected.');
        }
    }
    $folder = realpath(gallery_abs_path($gallery['folder_path']));
    $folderStat = $folder === false ? false : @lstat($folder);
    if (!is_string($folder) || !is_array($folderStat)
        || ($folderStat['ino'] ?? 0) <= 0) {
        throw new RuntimeException('Gallery visibility directory is unavailable.');
    }
    // Probe the actual platform and directory before creating any marker.
    gallery_visibility_sync_directory($folder, $folderStat['dev'], $folderStat['ino']);
    $existingSidecar = $folder . DIRECTORY_SEPARATOR . 'gallery.json';
    clearstatcache(true, $existingSidecar);
    $existingStat = @lstat($existingSidecar);
    if (!is_array($existingStat) || is_link($existingSidecar)
        || !is_file($existingSidecar) || ($existingStat['nlink'] ?? 0) !== 1) {
        throw new RuntimeException('Gallery sidecar is unavailable.');
    }
    gallery_visibility_sync_file(
        $existingSidecar, $existingStat['dev'], $existingStat['ino'],
    );
    $private = gallery_sidecar_visibility_prepare($gallery, 'private');
    $target = gallery_sidecar_visibility_replacement(
        $private['replacement'], 'private', $visibility,
    );
    $content = json_encode([
        'format' => 'gallery_visibility_pending_v2',
        'gallery_id' => (int) $gallery['id'],
        'revision' => (string) $gallery['edit_revision'],
        'folder_hash' => hash('sha256', $gallery['folder_path']),
        'original_hash' => hash('sha256', $private['original']),
        'private_hash' => hash('sha256', $private['replacement']),
        'target_hash' => hash('sha256', $target),
        'row_hash' => gallery_visibility_row_hash($gallery),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $path = $folder . DIRECTORY_SEPARATOR . LINKED_VISIBILITY_PENDING_MARKER;
    $handle = @fopen($path, 'x+b');
    if ($handle === false) {
        throw new RuntimeException('Gallery visibility marker cannot be created.');
    }
    try {
        $stat = fstat($handle);
        clearstatcache(true, $path);
        $entry = @lstat($path);
        if (!is_array($stat) || !is_array($entry) || is_link($path)
            || ($stat['ino'] ?? 0) <= 0 || ($stat['nlink'] ?? 0) !== 1
            || $entry['dev'] !== $stat['dev'] || $entry['ino'] !== $stat['ino']
            || !@chmod($path, 0600)) {
            throw new RuntimeException('Gallery visibility marker is unavailable.');
        }
        $offset = 0;
        while ($offset < strlen($content)) {
            $written = fwrite($handle, substr($content, $offset));
            if (!is_int($written) || $written <= 0) {
                throw new RuntimeException('Gallery visibility marker is incomplete.');
            }
            $offset += $written;
        }
        if (!fflush($handle) || !@fsync($handle) || !rewind($handle)
            || stream_get_contents($handle) !== $content) {
            throw new RuntimeException('Gallery visibility marker is incomplete.');
        }
    } finally {
        fclose($handle);
    }
    $marker = ['path' => $path, 'dev' => $stat['dev'],
        'ino' => $stat['ino'], 'folder_dev' => $folderStat['dev'],
        'folder_ino' => $folderStat['ino'], 'content' => $content];
    gallery_visibility_marker_assert_owned($marker);
    gallery_visibility_sync_directory($folder, $folderStat['dev'], $folderStat['ino']);
    gallery_sidecar_visibility_publish($private);
    gallery_visibility_marker_assert_owned($marker);
    $privateGallery = $gallery;
    $privateGallery['visibility'] = 'private';
    return ['sidecar' => gallery_sidecar_visibility_prepare($privateGallery, $visibility),
        'marker' => $marker];
}

/**
 * Find folders under galleries_root that can become gallery records.
 *
 * A folder is a candidate when it contains direct images, descendant images, or
 * a gallery.json sidecar. Descendant images allow empty parent folders to become
 * top-level galleries that contain subgalleries.
 *
 * @return list<array{folder_path:string,title:mixed,description:mixed,visibility:string,access_mode:mixed,access_listing:mixed,banner_image_path:mixed,logo_image_path:mixed,separator_image_path:mixed,sort_order:int}> Candidate gallery metadata derived from folders and sidecars.
 */
function discover_gallery_candidates(): array
{
    // Variable $root stores this steps working value.
    $root = galleries_root();
    if (!is_dir($root)) {
        return [];
    }

    // Variable $known stores this steps working value.
    $known = gallery_model_folder_paths();
    // Variable $known stores this steps working value.
    $known = array_flip($known);
    // Variable $candidates stores this steps working value.
    $candidates = [];
    // Variable $ignoreNames stores this steps working value.
    $ignoreNames = [
        'cache',
        'thumbs',
        'thumbnail',
        'thumbnails',
        'preview',
        'previews',
        '_php-gallery-internal',
    ];
    // Variable $iterator stores this steps working value.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS,
            ),
            function (SplFileInfo $file) use ($ignoreNames): bool {
                if (!$file->isDir()) {
                    return true;
                }
                // Variable $name stores this steps working value.
                $name = $file->getFilename();
                return (
                    !str_starts_with($name, '.')
                        && !in_array(strtolower($name), $ignoreNames, true)
                );
            },
        ),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $item) {
        if (!$item->isDir()) {
            continue;
        }
        // Variable $relative stores this steps working value.
        $relative = normalize_relative_path(substr(
            $item->getPathname(),
            strlen($root),
        ));
        if ($relative === '' || isset($known[$relative])) {
            continue;
        }
        // Variable $hasImages stores this steps working value.
        $hasImages = false;
        foreach (new DirectoryIterator($item->getPathname()) as $child) {
            if (
                $child->isFile()
                && is_supported_image_path($child->getFilename())
            ) {
                // Variable $hasImages stores this steps working value.
                $hasImages = true;
                break;
            }
        }
        // Variable $hasDescendantImages stores this steps working value.
        $hasDescendantImages = false;
        if (!$hasImages) {
            // Variable $descendants stores this steps working value.
            $descendants = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $item->getPathname(),
                    FilesystemIterator::SKIP_DOTS,
                ),
            );
            foreach ($descendants as $descendant) {
                if (
                    $descendant->isFile()
                    && is_supported_image_path($descendant->getFilename())
                ) {
                    // Variable $hasDescendantImages stores this steps working value.
                    $hasDescendantImages = true;
                    break;
                }
            }
        }
        // Variable $jsonPath stores this steps working value.
        $jsonPath = $item->getPathname() . DIRECTORY_SEPARATOR . 'gallery.json';
        if ($hasImages || $hasDescendantImages || is_file($jsonPath)) {
            // Variable $metadata stores this steps working value.
            $metadata = read_gallery_sidecar($jsonPath);
            $candidates[] = [
                'folder_path' => $relative,
                'title' => $metadata['title'] ?? basename($relative),
                'description' => $metadata['description'] ?? '',
                'visibility' => gallery_visibility_storage_value(
                    (string) ($metadata['visibility'] ?? 'unpublished'),
                ),
                'access_mode' => $metadata['access_mode'] ?? 'normal',
                'access_listing' => $metadata['access_listing'] ?? 'listed',
                'banner_image_path' => $metadata['banner_image_path'] ?? null,
                'logo_image_path' => $metadata['logo_image_path'] ?? null,
                'separator_image_path' =>
                    $metadata['separator_image_path'] ?? null,
                'sort_order' => (int) ($metadata['sort_order'] ?? 0),
            ];
        }
    }

    return $candidates;
}

/**
 * Normalize tag values inside every gallery.json sidecar under the configured gallery root.
 *
 * @return void
 */
function normalize_gallery_sidecar_tags_recursive(): void
{
    $writerLock = gallery_edit_writer_begin();
    try {
        normalize_gallery_sidecar_tags_recursive_owned();
    } finally {
        gallery_edit_writer_end($writerLock);
    }
}

/**
 * Normalize gallery sidecar tags under stable catalog ownership.
 *
 * Internal implementation: enter through normalize_gallery_sidecar_tags_recursive() so
 * reads, early returns and failure cleanup remain inside the same writer lease.
 *
 * @return void
 * @author Rudolf Klusal
 */
function normalize_gallery_sidecar_tags_recursive_owned(): void
{
    if (
        !function_exists('Gallery\\Services\\galleries_root')
        || !function_exists('normalize_tag_name')
    ) {
        return;
    }
    // Variable $root stores this steps working value.
    $root = galleries_root();
    if (!is_dir($root)) {
        return;
    }
    try {
        // Variable $iterator stores this steps working value.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator(
                    $root,
                    FilesystemIterator::SKIP_DOTS,
                ),
                /**
                 * Traverse source galleries while excluding hidden/internal and derivative directories.
                 * @param SplFileInfo $file Candidate file or directory below the gallery root.
                 * @return bool Whether the iterator may yield or descend into this entry.
                 * @author Rudolf Klusal
                 */
                static function (SplFileInfo $file): bool {
                    if (!$file->isDir()) {
                        return true;
                    }
                    // Variable $name stores this steps working value.
                    $name = strtolower($file->getFilename());
                    return (
                        !str_starts_with($name, '.')
                            && !in_array(
                                $name,
                                [
                                    'cache',
                                    'thumbs',
                                    'thumbnail',
                                    'thumbnails',
                                    'preview',
                                    'previews',
                                    '_php-gallery-internal',
                                ],
                                true,
                            )
                    );
                },
            ),
        );
        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->getFilename() !== 'gallery.json') {
                continue;
            }
            // Variable $path stores this steps working value.
            $path = $item->getPathname();
            // Variable $data stores this steps working value.
            $data = json_decode((string) file_get_contents($path), true);
            if (!is_array($data) || !array_key_exists('tags', $data)) {
                continue;
            }
            // Variable $rawTags stores this steps working value.
            $rawTags = is_array($data['tags'])
                ? $data['tags']
                : (preg_split('/[,;\n]+/', (string) $data['tags']) ?: []);
            // Variable $tags stores this steps working value.
            $tags = [];
            foreach ($rawTags as $tag) {
                // Variable $name stores this steps working value.
                $name = normalize_tag_name((string) $tag);
                if ($name !== '') {
                    $tags[$name] = $name;
                }
            }
            // Variable $normalizedTags stores this steps working value.
            $normalizedTags = implode(', ', array_values($tags));
            if ((string) $data['tags'] === $normalizedTags) {
                continue;
            }
            $data['tags'] = $normalizedTags;
            @file_put_contents($path, json_encode(
                $data,
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES,
            ));
        }
    } catch (Throwable) {
        return;
    }
}

/**
 * Read optional gallery metadata from gallery.json.
 *
 * @param string $path Filesystem path.
 * @return array Structured result data for the caller.
 */
function read_gallery_sidecar(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    // Variable $data stores this steps working value.
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

/**
 * Return public SEO metadata for one gallery, combining gallery.json and DB values.
 *
 * @param array{folder_path?:string,title?:string,description?:string,_content_localization?:array{title?:string,description?:string}} $gallery Gallery row fields used to resolve public metadata.
 * @return array{title:string,description:string,tags:list<string>} Resolved public title, description, and normalized tags.
 */
function public_gallery_metadata(array $gallery): array
{
    // Variable $folderPath stores this steps working value.
    $folderPath = normalize_relative_path(
        (string) ($gallery['folder_path'] ?? ''),
    );
    // Variable $sidecar stores this steps working value.
    $sidecar = [];
    try {
        // $sidecar stores an intermediate value used by the surrounding gallery workflow.
        $sidecar = read_gallery_sidecar(
            gallery_abs_path($folderPath)
                . DIRECTORY_SEPARATOR
                . 'gallery.json',
        );
    } catch (Throwable) {
        // $sidecar stores an intermediate value used by the surrounding gallery workflow.
        $sidecar = [];
    }

    // Variable $title stores this steps working value.
    $localized =
        isset($gallery['_content_localization'])
        && is_array($gallery['_content_localization']);
    $title = $localized
        ? trim((string) ($gallery['title'] ?? ''))
        : trim((string) ($sidecar['title'] ?? ''));
    if ($title === '') {
        // $title stores an intermediate value used by the surrounding gallery workflow.
        $title = trim((string) ($gallery['title'] ?? ''));
    }
    if ($title === '') {
        // $title stores an intermediate value used by the surrounding gallery workflow.
        $title = gallery_folder_name_from_path($folderPath);
    }

    // Variable $description stores this steps working value.
    $description = $localized
        ? trim((string) ($gallery['description'] ?? ''))
        : trim((string) ($sidecar['description'] ?? ''));
    if ($description === '') {
        // $description stores an intermediate value used by the surrounding gallery workflow.
        $description = trim((string) ($gallery['description'] ?? ''));
    }
    if ($description === '') {
        // $description stores an intermediate value used by the surrounding gallery workflow.
        $description = $title;
    }

    // Variable $tags stores this steps working value.
    $tags = [];
    // $rawTags stores an intermediate value used by the surrounding gallery workflow.
    $rawTags = $sidecar['tags'] ?? '';
    // $tagValues stores an intermediate value used by the surrounding gallery workflow.
    $tagValues = is_array($rawTags)
        ? $rawTags
        : (preg_split('/[,;\n]+/', (string) $rawTags) ?: []);
    foreach ($tagValues as $tag) {
        // $tag stores an intermediate value used by the surrounding gallery workflow.
        $tag = function_exists('normalize_tag_name')
            ? normalize_tag_name((string) $tag)
            : strtolower(trim((string) $tag));
        if ($tag !== '') {
            $tags[] = $tag;
        }
    }

    return [
        'title' => $title,
        'description' => $description,
        'tags' => array_values(array_unique($tags)),
    ];
}

/**
 * Persist editable gallery metadata back into gallery.json.
 * An explicit cover selection is stored as owned gallery-relative filenames in
 * cover_images so another installation can remap it through its image index.
 *
 * @param array{id:int|string,folder_path:string,title:string,description:string,visibility:string,sort_order:int|string,newest_uploads_first?:int|string,cover_image_id?:int|string|null,cover_image_ids?:string|null,cover_image_path?:string|null,content_language?:string|null,parent_id?:int|string|null} $gallery Persisted identity, ordering preference, required fields and optional cover metadata.
 * @return bool Whether the sidecar was written; existing callers may ignore the result.
 */
function write_gallery_sidecar(array $gallery): bool
{
    // Variable $data stores this steps working value.
    $data = [
        'description_layout_semantics_version' =>
            GALLERY_DESCRIPTION_LAYOUT_SEMANTICS_VERSION,
        'title' => $gallery['title'],
        'description' => $gallery['description'],
        'tags' => implode(', ', array_column(
            tags_for_entity('gallery', (int) $gallery['id']),
            'name',
        )),
        'visibility' => $gallery['visibility'],
        'sort_order' => (int) $gallery['sort_order'],
        'voting_enabled' => (int) ($gallery['voting_enabled'] ?? 0),
        'show_filenames' => (int) ($gallery['show_filenames'] ?? 0),
    ];
    if (array_key_exists('newest_uploads_first', $gallery)) {
        $data['newest_uploads_first'] =
            (int) $gallery['newest_uploads_first'];
    }
    if (content_localization_schema_ready('gallery')) {
        $data['content_language'] = content_language_normalize(
            $gallery['content_language'] ?? null,
        );
        $translationRows = content_translation_rows('gallery', [
            (int) $gallery['id'],
        ]);
        $data['translations'] = $translationRows[(int) $gallery['id']] ?? [];
    }
    if (gallery_date_schema_ready() && !empty($gallery['gallery_date'])) {
        $data['gallery_date'] = gallery_date_storage_value(
            $gallery['gallery_date'],
        );
    }
    if (
        gallery_date_range_schema_ready()
        && !empty($gallery['gallery_date_end'])
    ) {
        $data['gallery_date_end'] = gallery_date_storage_value(
            $gallery['gallery_date_end'],
        );
    }
    if (gallery_description_layout_schema_ready()) {
        // $descriptionLayout stores a per-gallery card layout override, when this gallery has one.
        $descriptionLayout = gallery_description_layout_storage_value(
            $gallery['description_layout'] ?? null,
        );
        if ($descriptionLayout !== null) {
            $data['description_layout'] = $descriptionLayout;
        }
    }
    if (gallery_count_badge_schema_ready()) {
        // $countBadgeVisibility stores a per-gallery count badge override, when this gallery has one.
        $countBadgeVisibility = gallery_count_badge_storage_value(
            $gallery['count_badge_visibility'] ?? null,
        );
        if ($countBadgeVisibility !== null) {
            $data['count_badge_visibility'] = $countBadgeVisibility;
        }
    }
    if (gallery_lightbox_browsing_mode_schema_ready()) {
        // $lightboxBrowsingMode stores a per-gallery lightbox mode override, when this gallery has one.
        $lightboxBrowsingMode = gallery_lightbox_browsing_mode_storage_value(
            $gallery['lightbox_browsing_mode'] ?? null,
        );
        if ($lightboxBrowsingMode !== null) {
            $data['lightbox_browsing_mode'] = $lightboxBrowsingMode;
        }
    }
    if (($gallery['presentation_json'] ?? null) !== null) {
        $data['presentation'] = gallery_presentation_settings($gallery);
    }
    $breadcrumbStyle = gallery_breadcrumb_style_override((int) $gallery['id']);
    $photoCardRatio = gallery_photo_card_ratio((int) $gallery['id']);
    if ($photoCardRatio !== 'default') {
        $data['photo_card_ratio'] = $photoCardRatio;
    }
    if ($breadcrumbStyle !== BREADCRUMB_STYLE_INHERIT) {
        $data['breadcrumb_style'] = $breadcrumbStyle;
    }
    if (
        gallery_grid_schema_ready()
        && gallery_grid_has_explicit_override($gallery)
    ) {
        $data['grid_columns'] = (int) $gallery['grid_columns'];
        $data['grid_rows'] = (int) $gallery['grid_rows'];
        $data['grid_use_for_subgalleries'] = (int) (
            $gallery['grid_use_for_subgalleries'] ?? 1
        );
    }
    if (thumbnail_bounds_schema_ready()) {
        if (
            isset($gallery['thumbnail_min_size'])
            && $gallery['thumbnail_min_size'] !== null
        ) {
            $data['thumbnail_min_size'] = (int) $gallery['thumbnail_min_size'];
        }
        if (
            isset($gallery['thumbnail_max_size'])
            && $gallery['thumbnail_max_size'] !== null
        ) {
            $data['thumbnail_max_size'] = (int) $gallery['thumbnail_max_size'];
        }
    }
    gallery_access_assert_public_policy_available();
    if (gallery_access_schema_ready()) {
        $data['access_mode'] = $gallery['access_mode'] ?? 'normal';
        $data['access_listing'] = $gallery['access_listing'] ?? 'listed';
    }
    if (!empty($gallery['cover_image_id'])) {
        // Variable $cover stores this steps working value.
        $cover = find_image((int) $gallery['cover_image_id'], true);
        if (
            $cover
            && (int) ($cover['gallery_id'] ?? 0) === (int) $gallery['id']
            && !str_contains((string) ($cover['relative_path'] ?? ''), '/')
        ) {
            $data['cover'] = $cover['relative_path'];
        }
    }
    if (
        array_key_exists('cover_image_ids', $gallery)
        && $gallery['cover_image_ids'] !== null
    ) {
        if (
            !gallery_cover_selection_schema_ready()
            || !gallery_cover_selection_storage_valid($gallery)
        ) {
            return false;
        }
        // Paths survive backups/imports where database image IDs change.
        $data['cover_images'] = [];
        foreach (gallery_cover_selection_ids($gallery) as $coverId) {
            $selectedCover = find_image($coverId, true);
            if (
                $selectedCover
                && (int) ($selectedCover['gallery_id'] ?? 0)
                    === (int) $gallery['id']
                && !str_contains(
                    (string) ($selectedCover['relative_path'] ?? ''),
                    '/',
                )
            ) {
                $data['cover_images'][] =
                    (string) $selectedCover['relative_path'];
            }
        }
    }
    if (!empty($gallery['cover_image_path'])) {
        $data['cover_image_path'] = (string) $gallery['cover_image_path'];
    }
    if (
        function_exists('Gallery\\Services\\gallery_branding_schema_ready')
        && gallery_branding_schema_ready()
    ) {
        foreach (gallery_branding_asset_types() as $kind => $definition) {
            // $column stores an intermediate value used by the surrounding gallery workflow.
            $column = (string) $definition['column'];
            if (!empty($gallery[$column])) {
                $data[$column] = (string) $gallery[$column];
            }
        }
    }
    return write_gallery_sidecar_for_path(
        (string) $gallery['folder_path'],
        $data,
    );
}

/**
 * Return lightweight metadata for one gallery folder, using gallery.json when it exists.
 *
 * This helper is intentionally small and filesystem-backed because it is used by
 * the importer and the parent-sync repair path. Empty parent folders can still
 * become real gallery rows when they are needed to preserve a nested gallery
 * hierarchy, even when those parent folders contain no direct photos.
 *
 * @param string $folderPath Folder path filesystem path.
 * @return array{folder_path:string,title:mixed,description:mixed,
 *     content_language:?string,translations:array<array-key,mixed>,
 *     gallery_date:?string,gallery_date_end:?string,visibility:string,
 *     voting_enabled:int,show_filenames:int,newest_uploads_first:?int,description_layout:?string,
 *     count_badge_visibility:?string,lightbox_browsing_mode:?string,
 *     presentation_json:?string,breadcrumb_style:string,photo_card_ratio:string,
 *     grid_columns:?int,
 *     grid_rows:?int,grid_use_for_subgalleries:int,thumbnail_min_size:?int,
 *     thumbnail_max_size:?int,banner_image_path:mixed,logo_image_path:mixed,
 *     separator_image_path:mixed,access_mode:mixed,access_listing:mixed,
 *     sort_order:int} Normalized metadata for one candidate gallery folder.
 */
function gallery_folder_candidate_metadata(string $folderPath): array
{
    // Variable $folderPath stores this steps working value.
    $folderPath = normalize_relative_path($folderPath);
    // Variable $jsonPath stores this steps working value.
    $jsonPath =
        gallery_abs_path($folderPath) . DIRECTORY_SEPARATOR . 'gallery.json';
    // Variable $metadata stores this steps working value.
    $metadata = read_gallery_sidecar($jsonPath);

    return [
        'folder_path' => $folderPath,
        'title' => gallery_disk_source_title(
            $folderPath,
            (string) ($metadata['title'] ?? ''),
        ),
        'description' => $metadata['description'] ?? '',
        'content_language' => content_language_normalize(
            $metadata['content_language'] ?? null,
        ),
        'translations' => is_array($metadata['translations'] ?? null)
            ? $metadata['translations']
            : [],
        'gallery_date' => gallery_date_schema_ready()
            ? gallery_date_sidecar_value($metadata['gallery_date'] ?? '')
            : null,
        'gallery_date_end' => gallery_date_range_schema_ready()
            ? gallery_date_sidecar_range_values(
                $metadata['gallery_date'] ?? '',
                $metadata['gallery_date_end'] ?? '',
            )['end']
            : null,
        'visibility' => gallery_visibility_storage_value(
            (string) ($metadata['visibility'] ?? 'unpublished'),
        ),
        'voting_enabled' => (int) ($metadata['voting_enabled'] ?? 0),
        'show_filenames' => (int) ($metadata['show_filenames'] ?? 0),
        'newest_uploads_first' => array_key_exists('newest_uploads_first', $metadata)
            ? (int) !empty($metadata['newest_uploads_first'])
            : null,
        'description_layout' => gallery_description_layout_storage_value(
            $metadata['description_layout'] ?? null,
        ),
        'count_badge_visibility' => gallery_count_badge_storage_value(
            $metadata['count_badge_visibility'] ?? null,
        ),
        'lightbox_browsing_mode' => gallery_lightbox_browsing_mode_storage_value(
            $metadata['lightbox_browsing_mode'] ?? null,
        ),
        'presentation_json' => is_array($metadata['presentation'] ?? null)
            ? json_encode(
                gallery_presentation_normalize($metadata['presentation']),
                JSON_THROW_ON_ERROR,
            )
            : null,
        'breadcrumb_style' => breadcrumb_style_normalize(
            $metadata['breadcrumb_style'] ?? BREADCRUMB_STYLE_INHERIT,
            true,
        ),
        'photo_card_ratio' => gallery_photo_card_ratio_normalize(
            $metadata['photo_card_ratio'] ?? null,
        ),
        'grid_columns' => isset($metadata['grid_columns'])
            ? (int) $metadata['grid_columns']
            : null,
        'grid_rows' => isset($metadata['grid_rows'])
            ? (int) $metadata['grid_rows']
            : null,
        'grid_use_for_subgalleries' => array_key_exists(
            'grid_use_for_subgalleries',
            $metadata,
        )
            ? (int) $metadata['grid_use_for_subgalleries']
            : 1,
        'thumbnail_min_size' => isset($metadata['thumbnail_min_size'])
            ? (int) $metadata['thumbnail_min_size']
            : null,
        'thumbnail_max_size' => isset($metadata['thumbnail_max_size'])
            ? (int) $metadata['thumbnail_max_size']
            : null,
        'banner_image_path' => $metadata['banner_image_path'] ?? null,
        'logo_image_path' => $metadata['logo_image_path'] ?? null,
        'separator_image_path' => $metadata['separator_image_path'] ?? null,
        'access_mode' => $metadata['access_mode'] ?? 'normal',
        'access_listing' => $metadata['access_listing'] ?? 'listed',
        'sort_order' => (int) ($metadata['sort_order'] ?? 0),
    ];
}

/**
 * Create one gallery row for a real filesystem folder when it is missing.
 *
 * The function is used for two related cases:
 * 1. normal imports selected from the discovery screen;
 * 2. automatic repair of missing parent rows for already-imported deep folders.
 *
 * The created row is deliberately conservative: visibility defaults to unpublished
 * unless gallery.json says otherwise, and images are scanned only by the caller.
 *
 * @param string $folderPath Folder path filesystem path.
 * @return array{id:int|string,title:string,folder_path:string,
 *     slug:string,parent_id?:int|string|null,visibility?:string,
 *     presentation_json?:string|null}|null Indexed gallery identity with other
 *     persisted schema fields, or null for an absent/empty path. Generated slugs
 *     reserve presentation codes without changing the imported display title.
 */
function create_gallery_row_for_folder(string $folderPath): ?array
{
    $writerLock = gallery_edit_writer_begin();
    try {
        return create_gallery_row_for_folder_owned($folderPath);
    } finally {
        gallery_edit_writer_end($writerLock);
    }
}

/**
 * Perform create gallery row for folder while the caller owns the gallery writer lease.
 *
 * @param string $folderPath Normalized relative folder to index.
 * @return array{id:int|string,title:string,folder_path:string,slug:string,
 *     parent_id?:int|string|null,visibility?:string,
 *     presentation_json?:string|null}|null Indexed gallery identity plus
 *     persisted schema fields, or null when the folder cannot be indexed.
 *     New URL slugs reserve presentation names; imported titles are preserved.
 */
function create_gallery_row_for_folder_owned(string $folderPath): ?array
{
    // Variable $folderPath stores this steps working value.
    $folderPath = normalize_relative_path($folderPath);
    if ($folderPath === '' || !is_dir(gallery_abs_path($folderPath))) {
        return null;
    }

    // Variable $existing stores this steps working value.
    $existing = find_gallery_by_folder_path($folderPath, true);
    if ($existing) {
        return $existing;
    }

    // Variable $candidate stores this steps working value.
    $candidate = gallery_folder_candidate_metadata($folderPath);
    $newestOrderAvailable = false;
    if ($candidate['newest_uploads_first'] !== null) {
        $newestOrderAvailable = mutation_schema_optional_column_available(
            'mutation.gallery_sidecar_import',
            'galleries',
            'newest_uploads_first',
            'gallery_sidecar_import.newest_uploads_first',
        );
        if (!$newestOrderAvailable && $candidate['newest_uploads_first'] === 1) {
            throw new \RuntimeException(
                'Importing automatic image ordering requires the current gallery schema.',
            );
        }
    }
    if (($candidate['presentation_json'] ?? null) !== null) {
        gallery_presentation_assert_writable(
            'gallery_sidecar_import.presentation',
        );
    }
    if ((int) ($candidate['voting_enabled'] ?? 0) === 1) {
        presentation_schema_assert_write_available(
            presentation_voting_schema_status(),
            'gallery_sidecar_import.voting_enabled',
            'Image voting requires the current database migration before it can be enabled for an imported gallery.',
        );
    }
    if (($candidate['lightbox_browsing_mode'] ?? null) !== null) {
        presentation_schema_assert_known(
            presentation_lightbox_override_schema_status(),
            'gallery_sidecar_import.lightbox_override',
        );
    }
    // Variable $visibility stores this steps working value.
    $visibility = gallery_visibility_storage_value(
        (string) ($candidate['visibility'] ?? 'unpublished'),
    );
    // $votingEnabled stores an intermediate value used by the surrounding gallery workflow.
    $votingEnabled = (int) ($candidate['voting_enabled'] ?? 0) === 1 ? 1 : 0;
    // $showFilenames stores an intermediate value used by the surrounding gallery workflow.
    $showFilenames = gallery_filename_display_schema_ready()
    && (int) ($candidate['show_filenames'] ?? 0) === 1
        ? 1
        : 0;
    // $descriptionLayout stores a per-gallery card layout override read from gallery.json.
    $descriptionLayout = gallery_description_layout_schema_ready()
        ? gallery_description_layout_storage_value(
            $candidate['description_layout'] ?? null,
        )
        : null;
    // $countBadgeVisibility stores a per-gallery count badge override read from gallery.json.
    $countBadgeVisibility = gallery_count_badge_schema_ready()
        ? gallery_count_badge_storage_value(
            $candidate['count_badge_visibility'] ?? null,
        )
        : null;
    // $lightboxBrowsingMode stores a per-gallery lightbox mode override read from gallery.json.
    $lightboxBrowsingMode = gallery_lightbox_browsing_mode_schema_ready()
        ? gallery_lightbox_browsing_mode_storage_value(
            $candidate['lightbox_browsing_mode'] ?? null,
        )
        : null;
    // $galleryDateRange stores the optional manual gallery date range read from gallery.json.
    $galleryDateRange = gallery_date_schema_ready()
        ? gallery_date_sidecar_range_values(
            $candidate['gallery_date'] ?? '',
            $candidate['gallery_date_end'] ?? '',
        )
        : ['start' => null, 'end' => null];
    // $galleryDate stores the optional manual gallery date or range start read from gallery.json.
    $galleryDate = $galleryDateRange['start'];
    // $galleryDateEnd stores the optional manual gallery date range end read from gallery.json.
    $galleryDateEnd = $galleryDateRange['end'];
    // Access policy must not interpret partial/unknown schema as an unprotected gallery.
    gallery_access_assert_public_policy_available();
    // $accessMode stores an intermediate value used by the surrounding gallery workflow.
    $accessMode = gallery_access_schema_ready()
    && ($candidate['access_mode'] ?? '') === 'password'
        ? 'password'
        : 'normal';
    // $candidateHasGrid stores whether gallery.json defines a complete custom display grid.
    $candidateHasGrid =
        gallery_grid_schema_ready()
        && isset($candidate['grid_columns'], $candidate['grid_rows'])
        && $candidate['grid_columns'] !== null
        && $candidate['grid_rows'] !== null;
    // $candidateHasThumbnailBounds stores whether gallery.json defines responsive thumbnail size guardrails.
    $candidateHasThumbnailBounds =
        thumbnail_bounds_schema_ready()
        && (
            $candidate['thumbnail_min_size'] !== null
            || $candidate['thumbnail_max_size'] !== null
        );
    // $accessListing stores an intermediate value used by the surrounding gallery workflow.
    $accessListing = gallery_access_schema_ready()
    && ($candidate['access_listing'] ?? '') === 'unlisted'
        ? 'unlisted'
        : 'listed';
    // Variable $parent stores this steps working value.
    $parent = find_parent_gallery_for_path($folderPath);
    // $fields stores the schema-aware persistence values for the new gallery row.
    $fields = [
        'parent_id' => $parent ? (int) $parent['id'] : null,
        'folder_path' => $folderPath,
        'folder_path_hash' => hash('sha256', $folderPath),
        'slug' => gallery_model_unique_slug(gallery_url_slug_base(
            (string) $candidate['title'],
        )),
        'title' => $candidate['title'],
        'description' => $candidate['description'],
        'sort_order' => (int) $candidate['sort_order'],
        'visibility' => $visibility,
        'voting_enabled' => $votingEnabled,
    ];
    if (gallery_filename_display_schema_ready()) {
        $fields['show_filenames'] = $showFilenames;
    }
    if ($newestOrderAvailable) {
        $fields['newest_uploads_first'] = $candidate['newest_uploads_first'];
    }
    if (content_localization_schema_ready('gallery')) {
        $fields['content_language'] = content_language_normalize(
            $candidate['content_language'] ?? null,
        );
    }
    if (gallery_description_layout_schema_ready()) {
        $fields['description_layout'] = $descriptionLayout;
    }
    if (gallery_count_badge_schema_ready()) {
        $fields['count_badge_visibility'] = $countBadgeVisibility;
    }
    if (gallery_lightbox_browsing_mode_schema_ready()) {
        $fields['lightbox_browsing_mode'] = $lightboxBrowsingMode;
    }
    if (($candidate['presentation_json'] ?? null) !== null) {
        $fields['presentation_json'] = $candidate['presentation_json'];
    }
    if (gallery_date_schema_ready()) {
        $fields['gallery_date'] = $galleryDate;
    }
    if (gallery_date_range_schema_ready()) {
        $fields['gallery_date_end'] = $galleryDateEnd;
    }
    if (gallery_grid_schema_ready()) {
        $fields['grid_columns'] = $candidateHasGrid
            ? pagination_dimension_value(
                $candidate['grid_columns'],
                CMS_PAGINATION_DEFAULT_COLUMNS,
                CMS_PAGINATION_MAX_COLUMNS,
            )
            : null;
        $fields['grid_rows'] = $candidateHasGrid
            ? pagination_dimension_value(
                $candidate['grid_rows'],
                CMS_PAGINATION_DEFAULT_ROWS,
                CMS_PAGINATION_MAX_ROWS,
            )
            : null;
        $fields['grid_use_for_subgalleries'] =
            !empty($candidate['grid_use_for_subgalleries']) ? 1 : 0;
    }
    if (thumbnail_bounds_schema_ready()) {
        $fields['thumbnail_min_size'] = $candidateHasThumbnailBounds
            ? thumbnail_bound_post_value($candidate['thumbnail_min_size'])
            : null;
        $fields['thumbnail_max_size'] = $candidateHasThumbnailBounds
            ? thumbnail_bound_post_value($candidate['thumbnail_max_size'])
            : null;
    }
    if (gallery_access_schema_ready()) {
        $fields['access_mode'] = $accessMode;
        $fields['access_listing'] = $accessMode === 'password'
            ? $accessListing
            : 'listed';
    }
    if (
        function_exists('Gallery\Services\gallery_branding_schema_ready')
        && gallery_branding_schema_ready()
    ) {
        foreach (gallery_branding_asset_types() as $kind => $definition) {
            // $column stores an intermediate value used by the surrounding gallery workflow.
            $column = (string) $definition['column'];
            $fields[$column] = !empty($candidate[$column])
                ? normalize_relative_path((string) $candidate[$column])
                : null;
        }
    }
    $fields['created_at'] = now_sql();
    $fields['updated_at'] = now_sql();
    $createdGalleryId = gallery_model_insert($fields);
    if ($candidate['photo_card_ratio'] !== 'default') {
        gallery_photo_card_ratio_save(
            $createdGalleryId,
            $candidate['photo_card_ratio'],
        );
    }
    if ($candidate['breadcrumb_style'] !== BREADCRUMB_STYLE_INHERIT) {
        gallery_breadcrumb_style_save(
            $createdGalleryId,
            $candidate['breadcrumb_style'],
        );
    }
    if (content_localization_schema_ready('gallery')) {
        content_save_localizations(
            'gallery',
            $createdGalleryId,
            $candidate['content_language'] ?? null,
            $candidate['translations'] ?? [],
        );
    }

    // Variable $gallery stores this steps working value.
    $gallery = find_gallery($createdGalleryId, true);
    if ($gallery) {
        write_gallery_sidecar($gallery);
    }
    gallery_visit_content_update([$createdGalleryId]);
    return $gallery;
}

/**
 * Create a real empty folder and immediately index it as a gallery.
 */
/**
 * Calculate a sort order that places a newly created gallery before its siblings.
 *
 * Existing reorder behavior still uses explicit sort_order values. This helper is
 * only used when the creation flow did not provide a manual order value, so the
 * most recently added child appears first after the next server render.
 *
 * @param int $parentId Parent id identifier.
 * @return int Integer result for the caller.
 */
function next_gallery_prepend_sort_order(int $parentId): int
{
    return gallery_model_prepend_sort_order($parentId);
}

/**
 * Create an empty physical gallery without erasing existing catalog ownership.
 *
 * @param array<string,mixed> $input Prepared title, folder_name, parent_id and optional editorial/display fields.
 * @return array<string,mixed> Created gallery row including its stable id and relative folder_path.
 * @throws GalleryCatalogConflict When missing/unknown storage is still owned by an existing catalog.
 */
function create_empty_gallery(array $input): array
{
    $writerLock = gallery_edit_writer_begin();
    try {
        return create_empty_gallery_owned($input);
    } finally {
        gallery_edit_writer_end($writerLock);
    }
}

/**
 * Exclusively create and read back the new gallery's sidecar.
 *
 * Existing files and final-component links are never opened or removed. A
 * partial owned file is retained as evidence after failure.
 *
 * @param string $path Validated path for the new gallery.json sidecar.
 * @param string $content Exact encoded metadata for the new gallery.
 * @return array{dev:int,ino:int} Created regular file's physical identity.
 * @throws RuntimeException When creation, ownership, writing or readback fails.
 */
function gallery_linked_creation_write_sidecar(string $path, string $content): array
{
    gallery_model_assert_linked_creation_transaction();
    // Exclusive create rejects existing files and final-component symlinks.
    $handle = @fopen($path, 'x+b');
    if ($handle === false) {
        throw new RuntimeException('Gallery metadata could not be written.');
    }
    try {
        $stat = fstat($handle);
        if (!is_array($stat) || ($stat['ino'] ?? 0) <= 0
            || ($stat['nlink'] ?? 0) !== 1) {
            throw new RuntimeException('Gallery metadata ownership is unavailable.');
        }
        $offset = 0;
        $length = strlen($content);
        while ($offset < $length) {
            $written = fwrite($handle, substr($content, $offset));
            if (!is_int($written) || $written <= 0) {
                throw new RuntimeException('Gallery metadata could not be written.');
            }
            $offset += $written;
        }
        if (!fflush($handle) || !rewind($handle)
            || stream_get_contents($handle) !== $content) {
            throw new RuntimeException('Gallery metadata could not be verified.');
        }
        return ['dev' => $stat['dev'], 'ino' => $stat['ino']];
    } finally {
        fclose($handle);
    }
}

/**
 * Recheck the new linked gallery and sidecar without following a final link.
 *
 * This is an observation, not a lock on external filesystem writers. The
 * caller must retain its database transaction and cooperating writer lease.
 *
 * @param array{root:string,parent:string,folder:string,sidecar:string,root_dev:int,root_ino:int,parent_dev:int,parent_ino:int,folder_dev:int,folder_ino:int,sidecar_dev:int,sidecar_ino:int,content:string} $proof
 *     Exact paths, physical identities and bytes observed at creation.
 * @return void Returns only while all observed identities and content match.
 * @throws RuntimeException When a path, identity or sidecar cannot be verified.
 */
function gallery_linked_creation_assert_filesystem(array $proof): void
{
    gallery_model_assert_linked_creation_transaction();
    clearstatcache(true);
    $root = realpath(galleries_root());
    $parent = realpath(dirname($proof['folder']));
    $folder = realpath($proof['folder']);
    $rootStat = $root === false ? false : @stat($root);
    $parentStat = $parent === false ? false : @stat($parent);
    $folderStat = @lstat($proof['folder']);
    $sidecarStat = @lstat($proof['sidecar']);
    if ($root !== $proof['root'] || $parent !== $proof['parent']
        || $folder !== rtrim($proof['parent'], DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . basename($proof['folder'])
        || $proof['sidecar'] !== $proof['folder'] . DIRECTORY_SEPARATOR . 'gallery.json'
        || !is_array($rootStat) || !is_array($parentStat)
        || !is_array($folderStat) || !is_array($sidecarStat)
        || !is_dir($root) || !is_dir($parent) || !is_dir($proof['folder'])
        || is_link($proof['folder']) || is_link($proof['sidecar'])
        || !is_file($proof['sidecar']) || ($sidecarStat['nlink'] ?? 0) !== 1
        || $rootStat['dev'] !== $proof['root_dev'] || $rootStat['ino'] !== $proof['root_ino']
        || $parentStat['dev'] !== $proof['parent_dev'] || $parentStat['ino'] !== $proof['parent_ino']
        || $folderStat['dev'] !== $proof['folder_dev'] || $folderStat['ino'] !== $proof['folder_ino']
        || $sidecarStat['dev'] !== $proof['sidecar_dev'] || $sidecarStat['ino'] !== $proof['sidecar_ino']
        || !gallery_filesystem_path_inside_root($parent)
        || !gallery_filesystem_path_inside_root($folder)
        || @file_get_contents($proof['sidecar'], false, null, 0,
            strlen($proof['content']) + 1) !== $proof['content']) {
        throw new RuntimeException('Linked gallery filesystem ownership is unavailable.');
    }
    clearstatcache(true, $proof['sidecar']);
    $after = @lstat($proof['sidecar']);
    if (!is_array($after) || is_link($proof['sidecar'])
        || $after['dev'] !== $proof['sidecar_dev'] || $after['ino'] !== $proof['sidecar_ino']) {
        throw new RuntimeException('Linked gallery sidecar changed during verification.');
    }
}

/**
 * Create only one linked gallery under the caller's writer and database leases.
 *
 * Unlike the general Admin workflow this narrow operation never repairs the
 * global hierarchy or seeds best-effort database indexes. It uses the shared
 * naming and URL policies and refuses creation when their canonical result
 * would alter any existing gallery. Filesystem changes cannot be rolled back;
 * its caller retains a durable pending claim for every uncertain outcome.
 *
 * @param array{title:string,parent_id:int} $input Validated title and parent ID,
 *     with zero denoting the gallery root.
 * @param array{folder:string,actor_id:int,key_hash:string,payload_hash:string} $reservation Durable claim and normalized target identity.
 * @param bool $filesystemStarted Monotonic caller-owned marker set before the first mkdir attempt.
 * @return array{id:int,title:string,parent_id:int|null,_linked_creation_fs:array{root:string,parent:string,folder:string,sidecar:string,root_dev:int,root_ino:int,parent_dev:int,parent_ino:int,folder_dev:int,folder_ino:int,sidecar_dev:int,sidecar_ino:int,content:string}}
 *     Created uncommitted identity and private filesystem proof for pre-commit verification.
 * @throws RuntimeException When ownership, storage, hierarchy or URL is unsafe.
 */
function create_empty_gallery_linked_owned(
    array $input, array $reservation, bool &$filesystemStarted,
): array
{
    if ($filesystemStarted) {
        throw new RuntimeException('Linked creation filesystem state is unavailable.');
    }
    gallery_model_assert_linked_creation_transaction();
    $title = $input['title'];
    $parentId = $input['parent_id'];
    if ($title === '' || $parentId < 0) {
        throw new RuntimeException('Linked gallery input is unavailable.');
    }
    $parent = $parentId > 0 ? find_gallery($parentId, true) : null;
    if ($parentId > 0 && $parent === null) {
        throw new RuntimeException('Selected parent gallery is unavailable.');
    }
    // Unknown or partially migrated access storage must never become an
    // implicit permission or publication change for the newly created row.
    gallery_access_assert_public_policy_available();
    $hasAccess = gallery_access_schema_ready();
    $hasSchedule = gallery_publication_manual_storage_available();
    $folder = gallery_child_folder_path($parent, gallery_folder_segment($title));
    if ($folder === '' || strlen($folder) > LINKED_GALLERY_CREATE_PATH_MAX_BYTES) {
        throw new RuntimeException('Gallery folder name is unavailable.');
    }
    if ($folder !== $reservation['folder']) {
        throw new RuntimeException('Linked gallery reservation target changed.');
    }
    gallery_model_linked_reservation_assert_pending(
        $folder, $reservation['actor_id'], $reservation['key_hash'], $reservation['payload_hash'],
    );
    gallery_creation_assert_catalog_preserved($folder);
    $target = gallery_target_abs_path($folder);
    clearstatcache(true, $target);
    if (file_exists($target) || is_link($target)) {
        throw new RuntimeException('Gallery folder is already occupied.');
    }
    $visibility = gallery_visibility_storage_value('unpublished');
    if (!in_array($visibility, ['unpublished', 'draft'], true)) {
        throw new RuntimeException('Unpublished gallery storage is unavailable.');
    }
    if ($visibility === 'draft' && !schema_inspection_is_available(
        schema_inspection_column_definition_contains('galleries', 'visibility', 'draft'),
    )) {
        throw new RuntimeException('Unpublished gallery storage is unavailable.');
    }
    $sortOrder = next_gallery_prepend_sort_order($parentId);
    $now = now_sql();
    $legacySlug = gallery_model_unique_slug(
        gallery_url_slug_base($title),
        0,
        LINKED_GALLERY_CREATE_SLUG_MAX_ATTEMPTS,
    );
    if ($legacySlug === '' || strlen($legacySlug) > LINKED_GALLERY_CREATE_SLUG_MAX_BYTES) {
        throw new RuntimeException('Gallery slug is unavailable.');
    }
    $fields = [
        'parent_id' => $parentId > 0 ? $parentId : null,
        'folder_path' => $folder,
        'folder_path_hash' => hash('sha256', $folder),
        'slug' => $legacySlug,
        'title' => $title,
        'description' => '',
        'sort_order' => $sortOrder,
        'visibility' => $visibility,
        'voting_enabled' => 0,
        'show_filenames' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ];
    if ($hasAccess) {
        $fields['access_mode'] = 'normal';
        $fields['access_listing'] = 'listed';
    }
    if ($hasSchedule) {
        $fields['scheduled_publish_at'] = null;
    }
    $id = gallery_model_insert_linked_owned($fields);
    if ($id <= 0) {
        throw new RuntimeException('Linked gallery identity is unavailable.');
    }

    // One lookahead row distinguishes a full catalog from a truncated scan.
    $rows = public_path_model_linked_creation_rows(
        LINKED_GALLERY_CREATE_CATALOG_MAX_ROWS + 1,
        $hasAccess,
        $hasSchedule,
    );
    if (count($rows) > LINKED_GALLERY_CREATE_CATALOG_MAX_ROWS) {
        throw new RuntimeException('Gallery catalog exceeds the creation limit.');
    }
    $paths = gallery_public_path_assignments($rows);
    $parents = gallery_parent_id_assignments_from_folder_paths($rows);
    $newPath = null;
    foreach ($rows as $row) {
        $rowId = (int) $row['id'];
        if ($rowId <= 0 || (string) $rowId !== (string) $row['id']
            || !isset($paths[$rowId]) || !array_key_exists($rowId, $parents)) {
            throw new RuntimeException('Gallery catalog is unavailable.');
        }
        $assignment = $paths[$rowId];
        if ($rowId === $id) {
            if ((int) ($parents[$rowId] ?? 0) !== $parentId
                || (int) ($row['parent_id'] ?? 0) !== $parentId
                || $row['title'] !== $title || (int) $row['description_is_empty'] !== 1
                || $row['folder_path'] !== $folder
                || $row['folder_path_hash'] !== hash('sha256', $folder)
                || $row['slug'] !== $legacySlug
                || $row['visibility'] !== $visibility || (int) $row['sort_order'] !== $sortOrder
                || (int) $row['voting_enabled'] !== 0 || (int) $row['show_filenames'] !== 0
                || ($hasAccess && (($row['access_mode'] ?? null) !== 'normal'
                    || ($row['access_listing'] ?? null) !== 'listed'))
                || ($hasSchedule && (!array_key_exists('scheduled_publish_at', $row)
                    || $row['scheduled_publish_at'] !== null))
                || !preg_match('/\A[1-9][0-9]*\z/D', (string) $row['edit_revision'])
                || $row['url_slug'] !== null || $row['url_path'] !== null
                || $row['url_path_hash'] !== null) {
                throw new RuntimeException('Gallery storage could not be verified.');
            }
            $newPath = $assignment;
            continue;
        }
        // The ordinary global repair rewrites existing identities. This
        // operation instead refuses when it would change any of them.
        if ((int) ($row['parent_id'] ?? 0) !== (int) ($parents[$rowId] ?? 0)
            || $row['url_slug'] !== $assignment['slug']
            || $row['url_path'] !== $assignment['path']
            || !is_string($row['url_path_hash'])
            || !hash_equals($row['url_path_hash'], hash('sha256', $assignment['path']))) {
            throw new RuntimeException('Gallery catalog requires reconciliation.');
        }
    }
    if ($newPath === null || $newPath['slug'] === '' || $newPath['path'] === ''
        || strlen($newPath['slug']) > LINKED_GALLERY_CREATE_SLUG_MAX_BYTES
        || strlen($newPath['path']) > LINKED_GALLERY_CREATE_PATH_MAX_BYTES) {
        throw new RuntimeException('Linked gallery path is unavailable.');
    }

    // No filesystem or database write follows a swallowed rollback without a
    // fresh model-owned transaction check. The marker survives any failure.
    gallery_model_assert_linked_creation_transaction();
    clearstatcache(true);
    $rootReal = realpath(galleries_root());
    $parentReal = realpath(dirname($target));
    $rootStat = $rootReal === false ? false : @stat($rootReal);
    $parentStat = $parentReal === false ? false : @stat($parentReal);
    if (!is_string($rootReal) || !is_string($parentReal)
        || !is_array($rootStat) || !is_array($parentStat)
        || !is_dir($rootReal) || !is_dir($parentReal)
        || ($rootStat['ino'] ?? 0) <= 0 || ($parentStat['ino'] ?? 0) <= 0
        || !gallery_filesystem_path_inside_root($parentReal)) {
        throw new RuntimeException('Gallery parent ownership is unavailable.');
    }
    gallery_model_linked_reservation_assert_pending(
        $folder, $reservation['actor_id'], $reservation['key_hash'], $reservation['payload_hash'],
    );
    $filesystemStarted = true;
    if (!mkdir($target, \Gallery\Core\GALLERY_DIRECTORY_PERMISSIONS)) {
        throw new RuntimeException('Gallery folder could not be created.');
    }
    clearstatcache(true);
    $folderReal = realpath($target);
    $folderStat = @lstat($target);
    $rootAfter = @stat($rootReal);
    $parentAfter = @stat($parentReal);
    if ($folderReal !== rtrim($parentReal, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . basename($target)
        || realpath(galleries_root()) !== $rootReal
        || realpath(dirname($target)) !== $parentReal
        || !is_array($folderStat) || !is_array($rootAfter) || !is_array($parentAfter)
        || !is_dir($target) || is_link($target) || ($folderStat['ino'] ?? 0) <= 0
        || $rootAfter['dev'] !== $rootStat['dev'] || $rootAfter['ino'] !== $rootStat['ino']
        || $parentAfter['dev'] !== $parentStat['dev'] || $parentAfter['ino'] !== $parentStat['ino']
        || !gallery_filesystem_path_inside_root($folderReal)) {
        throw new RuntimeException('Gallery folder ownership is unavailable.');
    }
    $sidecar = [
        'title' => $title,
        'description' => '',
        'visibility' => $visibility,
        'sort_order' => $sortOrder,
        'voting_enabled' => 0,
        'show_filenames' => 0,
    ];
    $encoded = json_encode(
        $sidecar,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
    gallery_model_assert_linked_creation_transaction();
    $sidecarPath = $target . DIRECTORY_SEPARATOR . 'gallery.json';
    $sidecarStat = gallery_linked_creation_write_sidecar($sidecarPath, $encoded);
    $proof = [
        'root' => $rootReal, 'parent' => $parentReal,
        'folder' => $target, 'sidecar' => $sidecarPath,
        'root_dev' => $rootStat['dev'], 'root_ino' => $rootStat['ino'],
        'parent_dev' => $parentStat['dev'], 'parent_ino' => $parentStat['ino'],
        'folder_dev' => $folderStat['dev'], 'folder_ino' => $folderStat['ino'],
        'sidecar_dev' => $sidecarStat['dev'], 'sidecar_ino' => $sidecarStat['ino'],
        'content' => $encoded,
    ];
    gallery_linked_creation_assert_filesystem($proof);
    public_path_model_assign_linked_creation(
        $id,
        $newPath['slug'],
        $newPath['path'],
        $visibility,
        [
            'title' => $title,
            'parent_id' => $parentId > 0 ? $parentId : null,
            'folder_path' => $folder,
            'legacy_slug' => $legacySlug,
            'sort_order' => $sortOrder,
        ],
        $hasAccess,
        $hasSchedule,
    );
    gallery_linked_creation_assert_filesystem($proof);
    return [
        'id' => $id,
        'title' => $title,
        'parent_id' => $parentId > 0 ? $parentId : null,
        '_linked_creation_fs' => $proof,
    ];
}

/**
 * Create an empty gallery while the caller owns the gallery writer lease.
 * When its authoritative attachment sidecars are absent, best-effort INSERT
 * IGNORE snapshots let public branch counts include this known-empty gallery;
 * unknown schemas and seed failures leave optional counts absent without
 * affecting gallery creation.
 *
 * @param array{title?:string, description?:string, tags?:string,
 *     folder_name?:string, parent_id?:int|string, visibility?:string,
 *     voting_enabled?:bool|int|string, show_filenames?:bool|int|string,
 *     sort_order?:int|string, content_language?:string,
 *     translations?:array<string, array{title?:string, description?:string}>,
 *     gallery_date?:string|null, gallery_date_end?:string|null,
 *     count_badge_visibility?:string, lightbox_browsing_mode?:string,
 *     presentation?:array<string, int|string|bool|null>|null} $input Creation
 *     metadata; legacy folder_name is ignored because the sanitized title owns
 *     the directory name.
 * @return array{id:int|string, title:string, folder_path:string,
 *     parent_id?:int|string|null, ...} Created physical gallery and its
 *     supported persisted metadata.
 */
function create_empty_gallery_owned(array $input): array
{
    $presentation = array_key_exists('presentation', $input)
        ? gallery_presentation_normalize(
            is_array($input['presentation']) ? $input['presentation'] : null,
        )
        : null;
    if ($presentation !== null) {
        gallery_presentation_assert_writable('gallery_create.presentation');
    }
    // $title stores an intermediate value used by the surrounding gallery workflow.
    $title = trim((string) ($input['title'] ?? ''));
    if ($title === '') {
        throw new RuntimeException('Gallery title is required.');
    }
    // $description stores an intermediate value used by the surrounding gallery workflow.
    $description = (string) ($input['description'] ?? '');
    $tags = trim((string) ($input['tags'] ?? ''));
    if (
        $tags !== ''
        && !schema_inspection_is_available(schema_inspection_feature('gallery_create_tags', [
            schema_inspection_table('tags'),
            schema_inspection_column('tags', 'id'),
            schema_inspection_column('tags', 'name'),
            schema_inspection_column('tags', 'slug'),
            schema_inspection_column('tags', 'created_at'),
            schema_inspection_column('tags', 'updated_at'),
            schema_inspection_table('gallery_tags'),
            schema_inspection_column('gallery_tags', 'gallery_id'),
            schema_inspection_column('gallery_tags', 'tag_id'),
        ]))
    ) {
        throw new RuntimeException(
            'Gallery tags are unavailable. Run pending database migrations.',
        );
    }
    $contentLanguage = (string) ($input['content_language'] ?? '');
    $translations = is_array($input['translations'] ?? null)
        ? $input['translations']
        : [];
    if ($contentLanguage !== '' || $translations !== []) {
        if (
            !content_localization_enabled()
            || (
                $contentLanguage !== ''
                && !in_array(
                    $contentLanguage,
                    content_supported_languages(),
                    true,
                )
            )
            || !schema_inspection_is_available(content_localization_schema_status(
                'gallery',
            ))
        ) {
            throw new RuntimeException(
                'The selected description language is unavailable.',
            );
        }
    }
    if (!empty($input['voting_enabled'])) {
        presentation_schema_assert_write_available(
            presentation_voting_schema_status(),
            'gallery_create.voting_enabled',
            'Image voting requires the current database migration before it can be enabled for a new gallery.',
        );
    }
    if (array_key_exists('lightbox_browsing_mode', $input)) {
        presentation_schema_assert_known(
            presentation_lightbox_override_schema_status(),
            'gallery_create.lightbox_override',
        );
    }
    // $galleryDateRange stores the optional manual date range for this gallery, independent from upload dates.
    $galleryDateRange = gallery_date_schema_ready()
        ? gallery_date_range_storage_values(
            $input['gallery_date'] ?? '',
            $input['gallery_date_end'] ?? '',
        )
        : ['start' => null, 'end' => null];
    // $galleryDate stores the optional manual date or range start for this gallery.
    $galleryDate = $galleryDateRange['start'];
    // $galleryDateEnd stores the optional manual date range end for this gallery.
    $galleryDateEnd = $galleryDateRange['end'];
    // $visibility stores an intermediate value used by the surrounding gallery workflow.
    $visibility = gallery_visibility_storage_value(
        (string) ($input['visibility'] ?? 'unpublished'),
    );
    // $votingEnabled stores an intermediate value used by the surrounding gallery workflow.
    $votingEnabled = !empty($input['voting_enabled']) ? 1 : 0;
    // $showFilenames stores an intermediate value used by the surrounding gallery workflow.
    $showFilenames = !empty($input['show_filenames']) ? 1 : 0;
    // $countBadgeVisibility stores the optional per-gallery count badge override.
    $countBadgeVisibility = gallery_count_badge_schema_ready()
        ? gallery_count_badge_storage_value(
            $input['count_badge_visibility'] ?? 'inherit',
        )
        : null;
    // $lightboxBrowsingMode stores the optional per-gallery lightbox browsing-mode override.
    $lightboxBrowsingMode = gallery_lightbox_browsing_mode_schema_ready()
        ? gallery_lightbox_browsing_mode_storage_value(
            $input['lightbox_browsing_mode'] ?? 'inherit',
        )
        : null;
    // $parentId stores an intermediate value used by the surrounding gallery workflow.
    $parentId = (int) ($input['parent_id'] ?? 0);
    // $parent stores an intermediate value used by the surrounding gallery workflow.
    $parent = $parentId > 0 ? find_gallery($parentId, true) : null;
    if ($parentId > 0 && !$parent) {
        throw new RuntimeException('Selected parent gallery does not exist.');
    }

    // $folderName stores an intermediate value used by the surrounding gallery workflow.
    // The title owns the physical leaf; stale clients cannot create an
    // independently named folder or silently acquire a suffixed identity.
    $folderName = gallery_folder_segment($title);
    // $requestedFolderPath stores the preferred folder path before suffix fallback is applied.
    $requestedFolderPath = gallery_child_folder_path(
        $parent,
        $folderName,
    );
    gallery_linked_reservation_assert_admin_write();
    gallery_creation_assert_catalog_preserved($requestedFolderPath);
    // $folderPath stores an intermediate value used by the surrounding gallery workflow.
    $folderPath = $requestedFolderPath;
    // $target stores an intermediate value used by the surrounding gallery workflow.
    $target = gallery_target_abs_path($folderPath);
    gallery_creation_assert_catalog_preserved($folderPath);
    if (file_exists($target)) {
        throw new RuntimeException('Gallery folder already exists.');
    }
    // The verified parent must still exist; never recreate missing ancestors here.
    gallery_linked_reservation_assert_admin_write();
    if (!mkdir($target, \Gallery\Core\GALLERY_DIRECTORY_PERMISSIONS)) {
        throw new RuntimeException('Could not create gallery folder.');
    }

    // $sortOrder stores the persisted sibling order for the new gallery.
    $sortOrder = array_key_exists('sort_order', $input)
        ? (int) $input['sort_order']
        : next_gallery_prepend_sort_order($parentId);

    // $sidecarData stores the metadata persisted before the gallery row exists.
    $sidecarData = [
        'title' => $title,
        'description' => $description,
        'gallery_date' => $galleryDate,
        'gallery_date_end' => gallery_date_range_schema_ready()
            ? $galleryDateEnd
            : null,
        'visibility' => $visibility,
        'sort_order' => $sortOrder,
        'voting_enabled' => $votingEnabled,
        'show_filenames' => $showFilenames,
    ];
    if ($countBadgeVisibility !== null) {
        $sidecarData['count_badge_visibility'] = $countBadgeVisibility;
    }
    if ($lightboxBrowsingMode !== null) {
        $sidecarData['lightbox_browsing_mode'] = $lightboxBrowsingMode;
    }
    if ($presentation !== null) {
        $sidecarData['presentation'] = $presentation;
    }
    // $sidecarWritten stores an intermediate value used by the surrounding gallery workflow.
    $sidecarWritten = write_gallery_sidecar_for_path($folderPath, $sidecarData);
    if (!$sidecarWritten) {
        throw new RuntimeException(
            'Gallery folder was created, but gallery.json could not be written.',
        );
    }

    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = create_gallery_row_for_folder($folderPath);
    if (!$gallery) {
        throw new RuntimeException(
            'Gallery folder was created, but the database row could not be created.',
        );
    }
    sync_gallery_parent_ids();
    if (public_path_schema_ready()) {
        refresh_gallery_public_paths();
    }
    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = find_gallery((int) $gallery['id'], true) ?: $gallery;
    if ($contentLanguage !== '' || $translations !== []) {
        content_save_localizations(
            'gallery',
            (int) $gallery['id'],
            $contentLanguage,
            $translations,
        );
        $gallery = find_gallery((int) $gallery['id'], true) ?: $gallery;
    }
    if ($tags !== '') {
        sync_entity_tags('gallery', (int) $gallery['id'], $tags);
    }
    write_gallery_sidecar($gallery);
    gallery_attachment_usage_seed_known_empty($gallery);
    gallery_visit_content_update([(int) $gallery['id']]);
    return $gallery;
}

/** Seed known-zero public attachment snapshots for an application-created empty gallery.
 * @param array{id:int|string,folder_path:string} $gallery Newly created physical gallery.
 * @return void Best-effort INSERT IGNORE never supersedes a concurrent dirty or ready source generation.
 */
function gallery_attachment_usage_seed_known_empty(array $gallery): void
{
    try {
        if (
            !function_exists('Gallery\\Services\\presentation_gallery_attachment_usage_schema_status')
            || !function_exists('Gallery\\Services\\presentation_schema_render_available')
        ) {
            return;
        }
        $folder = normalize_relative_path((string) $gallery['folder_path']);
        $root = gallery_abs_path($folder);
        $knownSourcePaths = [
            $root . '/.gallery-pdf-documents/index.json',
            $root . '/.gallery-music/index.json',
            $root . '/simbrief-ofp-manifest.json',
            $root . '/simbrief-ofp.pdf',
        ];
        foreach ($knownSourcePaths as $sourcePath) {
            if (file_exists($sourcePath) || is_link($sourcePath)) {
                return;
            }
        }
        if (!presentation_schema_render_available(
            presentation_gallery_attachment_usage_schema_status(),
            'gallery.create_empty_attachment_usage',
        )) {
            return;
        }
        foreach (['pdf', 'music'] as $kind) {
            gallery_model_attachment_usage_seed_empty(
                (int) $gallery['id'],
                $kind,
            );
        }
    } catch (Throwable) {
        // Optional counts remain absent; gallery creation itself is independent.
    }
}
