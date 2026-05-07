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

/**
Gallery discovery and sidecar metadata helpers.
 *
 * These functions keep the filesystem-first model intact. The database remains
 * an index, while gallery.json sidecars provide optional metadata next to the
 * gallery folder. No theme, favicon, or custom CSS settings are handled here.
 */

/**
 * Write gallery metadata into a sidecar before or after a DB row exists.
 */
function write_gallery_sidecar_for_path(string $folderPath, array $data): bool
{
    // $path stores an intermediate value used by the surrounding gallery workflow.
    $path = gallery_abs_path($folderPath) . DIRECTORY_SEPARATOR . 'gallery.json';
    // $directory stores the destination folder where gallery.json should be written.
    $directory = dirname($path);
    if (!is_dir($directory)) {
        return false;
    }

    return @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
}

/**
 * Find folders under galleries_root that can become gallery records.
 *
 * A folder is a candidate when it contains direct images, descendant images, or
 * a gallery.json sidecar. Descendant images allow empty parent folders to become
 * top-level galleries that contain subgalleries.
 */
function discover_gallery_candidates(): array
{
    // Variable $root stores this steps working value.
    $root = galleries_root();
    if (!is_dir($root)) {
        return [];
    }

    // Variable $pdo stores this steps working value.
    $pdo = db();
    // Variable $known stores this steps working value.
    $known = $pdo->query('SELECT folder_path FROM galleries')->fetchAll(PDO::FETCH_COLUMN);
    // Variable $known stores this steps working value.
    $known = array_flip($known);
    // Variable $candidates stores this steps working value.
    $candidates = [];
    // Variable $ignoreNames stores this steps working value.
    $ignoreNames = ['cache', 'thumbs', 'thumbnail', 'thumbnails', 'preview', 'previews'];
    // Variable $iterator stores this steps working value.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            function (SplFileInfo $file) use ($ignoreNames): bool {
                if (!$file->isDir()) {
                    return true;
                }
                // Variable $name stores this steps working value.
                $name = $file->getFilename();
                return !str_starts_with($name, '.') && !in_array(strtolower($name), $ignoreNames, true);
            }
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if (!$item->isDir()) {
            continue;
        }
        // Variable $relative stores this steps working value.
        $relative = normalize_relative_path(substr($item->getPathname(), strlen($root)));
        if ($relative === '' || isset($known[$relative])) {
            continue;
        }
        // Variable $hasImages stores this steps working value.
        $hasImages = false;
        foreach (new DirectoryIterator($item->getPathname()) as $child) {
            if ($child->isFile() && is_supported_image_path($child->getFilename())) {
                // Variable $hasImages stores this steps working value.
                $hasImages = true;
                break;
            }
        }
        // Variable $hasDescendantImages stores this steps working value.
        $hasDescendantImages = false;
        if (!$hasImages) {
            // Variable $descendants stores this steps working value.
            $descendants = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($item->getPathname(), FilesystemIterator::SKIP_DOTS));
            foreach ($descendants as $descendant) {
                if ($descendant->isFile() && is_supported_image_path($descendant->getFilename())) {
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
                'visibility' => gallery_visibility_storage_value((string) ($metadata['visibility'] ?? 'unpublished')),
                'access_mode' => $metadata['access_mode'] ?? 'normal',
                'access_listing' => $metadata['access_listing'] ?? 'listed',
                'sort_order' => (int) ($metadata['sort_order'] ?? 0),
            ];
        }
    }

    return $candidates;
}

/**
 * Read optional gallery metadata from gallery.json.
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
 */
function public_gallery_metadata(array $gallery): array
{
    // Variable $folderPath stores this steps working value.
    $folderPath = normalize_relative_path((string) ($gallery['folder_path'] ?? ''));
    // Variable $sidecar stores this steps working value.
    $sidecar = [];
    try {
        // $sidecar stores an intermediate value used by the surrounding gallery workflow.
        $sidecar = read_gallery_sidecar(gallery_abs_path($folderPath) . DIRECTORY_SEPARATOR . 'gallery.json');
    } catch (Throwable) {
        // $sidecar stores an intermediate value used by the surrounding gallery workflow.
        $sidecar = [];
    }

    // Variable $title stores this steps working value.
    $title = trim((string) ($sidecar['title'] ?? ''));
    if ($title === '') {
        // $title stores an intermediate value used by the surrounding gallery workflow.
        $title = trim((string) ($gallery['title'] ?? ''));
    }
    if ($title === '') {
        // $title stores an intermediate value used by the surrounding gallery workflow.
        $title = gallery_folder_name_from_path($folderPath);
    }

    // Variable $description stores this steps working value.
    $description = trim((string) ($sidecar['description'] ?? ''));
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
    $tagValues = is_array($rawTags) ? $rawTags : (preg_split('/[,;\n]+/', (string) $rawTags) ?: []);
    foreach ($tagValues as $tag) {
        // $tag stores an intermediate value used by the surrounding gallery workflow.
        $tag = trim((string) $tag);
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
 */
function write_gallery_sidecar(array $gallery): void
{
    // Variable $data stores this steps working value.
    $data = [
        'title' => $gallery['title'],
        'description' => $gallery['description'],
        'tags' => implode(', ', array_column(tags_for_entity('gallery', (int) $gallery['id']), 'name')),
        'visibility' => $gallery['visibility'],
        'sort_order' => (int) $gallery['sort_order'],
        'voting_enabled' => (int) ($gallery['voting_enabled'] ?? 0),
        'show_filenames' => (int) ($gallery['show_filenames'] ?? 0),
    ];
    if (gallery_grid_schema_ready() && gallery_grid_has_explicit_override($gallery)) {
        $data['grid_columns'] = (int) $gallery['grid_columns'];
        $data['grid_rows'] = (int) $gallery['grid_rows'];
        $data['grid_use_for_subgalleries'] = (int) ($gallery['grid_use_for_subgalleries'] ?? 1);
    }
    if (gallery_access_schema_ready()) {
        $data['access_mode'] = $gallery['access_mode'] ?? 'normal';
        $data['access_listing'] = $gallery['access_listing'] ?? 'listed';
    }
    if (!empty($gallery['cover_image_id'])) {
        // Variable $cover stores this steps working value.
        $cover = find_image((int) $gallery['cover_image_id']);
        if ($cover) {
            $data['cover'] = $cover['relative_path'];
        }
    }
    if (!empty($gallery['cover_image_path'])) {
        $data['cover_image_path'] = (string) $gallery['cover_image_path'];
    }
    write_gallery_sidecar_for_path((string) $gallery['folder_path'], $data);
}

/**
 * Return lightweight metadata for one gallery folder, using gallery.json when it exists.
 *
 * This helper is intentionally small and filesystem-backed because it is used by
 * the importer and the parent-sync repair path. Empty parent folders can still
 * become real gallery rows when they are needed to preserve a nested gallery
 * hierarchy, even when those parent folders contain no direct photos.
 */
function gallery_folder_candidate_metadata(string $folderPath): array
{
    // Variable $folderPath stores this steps working value.
    $folderPath = normalize_relative_path($folderPath);
    // Variable $jsonPath stores this steps working value.
    $jsonPath = gallery_abs_path($folderPath) . DIRECTORY_SEPARATOR . 'gallery.json';
    // Variable $metadata stores this steps working value.
    $metadata = read_gallery_sidecar($jsonPath);

    return [
        'folder_path' => $folderPath,
        'title' => $metadata['title'] ?? basename($folderPath),
        'description' => $metadata['description'] ?? '',
        'visibility' => gallery_visibility_storage_value((string) ($metadata['visibility'] ?? 'unpublished')),
        'voting_enabled' => (int) ($metadata['voting_enabled'] ?? 0),
        'show_filenames' => (int) ($metadata['show_filenames'] ?? 0),
        'grid_columns' => isset($metadata['grid_columns']) ? (int) $metadata['grid_columns'] : null,
        'grid_rows' => isset($metadata['grid_rows']) ? (int) $metadata['grid_rows'] : null,
        'grid_use_for_subgalleries' => array_key_exists('grid_use_for_subgalleries', $metadata) ? (int) $metadata['grid_use_for_subgalleries'] : 1,
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
 */
function create_gallery_row_for_folder(string $folderPath): ?array
{
    // Variable $folderPath stores this steps working value.
    $folderPath = normalize_relative_path($folderPath);
    if ($folderPath === '' || !is_dir(gallery_abs_path($folderPath))) {
        return null;
    }

    // Variable $existing stores this steps working value.
    $existing = find_gallery_by_folder_path($folderPath);
    if ($existing) {
        return $existing;
    }

    // Variable $candidate stores this steps working value.
    $candidate = gallery_folder_candidate_metadata($folderPath);
    // Variable $visibility stores this steps working value.
    $visibility = gallery_visibility_storage_value((string) ($candidate['visibility'] ?? 'unpublished'));
    // $votingEnabled stores an intermediate value used by the surrounding gallery workflow.
    $votingEnabled = (int) ($candidate['voting_enabled'] ?? 0) === 1 ? 1 : 0;
    // $showFilenames stores an intermediate value used by the surrounding gallery workflow.
    $showFilenames = gallery_filename_display_schema_ready() && (int) ($candidate['show_filenames'] ?? 0) === 1 ? 1 : 0;
    // $accessMode stores an intermediate value used by the surrounding gallery workflow.
    $accessMode = gallery_access_schema_ready() && ($candidate['access_mode'] ?? '') === 'password' ? 'password' : 'normal';
    // $candidateHasGrid stores whether gallery.json defines a complete custom display grid.
    $candidateHasGrid = gallery_grid_schema_ready() && isset($candidate['grid_columns'], $candidate['grid_rows']) && $candidate['grid_columns'] !== null && $candidate['grid_rows'] !== null;
    // $accessListing stores an intermediate value used by the surrounding gallery workflow.
    $accessListing = gallery_access_schema_ready() && ($candidate['access_listing'] ?? '') === 'unlisted' ? 'unlisted' : 'listed';
    // Variable $parent stores this steps working value.
    $parent = find_parent_gallery_for_path($folderPath);
    // Variable $pdo stores this steps working value.
    $pdo = db();
    // Variable $stmt stores this steps working value.
    $columns = ['parent_id', 'folder_path', 'folder_path_hash', 'slug', 'title', 'description', 'sort_order', 'visibility', 'voting_enabled'];
    // $values stores an intermediate value used by the surrounding gallery workflow.
    $values = [
        $parent ? (int) $parent['id'] : null,
        $folderPath,
        hash('sha256', $folderPath),
        unique_slug($pdo, (string) $candidate['title']),
        $candidate['title'],
        $candidate['description'],
        (int) $candidate['sort_order'],
        $visibility,
        $votingEnabled,
    ];
    if (gallery_filename_display_schema_ready()) {
        $columns[] = 'show_filenames';
        $values[] = $showFilenames;
    }
    if (gallery_grid_schema_ready()) {
        $columns[] = 'grid_columns';
        $columns[] = 'grid_rows';
        $columns[] = 'grid_use_for_subgalleries';
        $values[] = $candidateHasGrid ? pagination_dimension_value($candidate['grid_columns'], CMS_PAGINATION_DEFAULT_COLUMNS, CMS_PAGINATION_MAX_COLUMNS) : null;
        $values[] = $candidateHasGrid ? pagination_dimension_value($candidate['grid_rows'], CMS_PAGINATION_DEFAULT_ROWS, CMS_PAGINATION_MAX_ROWS) : null;
        $values[] = !empty($candidate['grid_use_for_subgalleries']) ? 1 : 0;
    }
    if (gallery_access_schema_ready()) {
        $columns[] = 'access_mode';
        $columns[] = 'access_listing';
        $values[] = $accessMode;
        $values[] = $accessMode === 'password' ? $accessListing : 'listed';
    }
    $columns[] = 'created_at';
    $columns[] = 'updated_at';
    $values[] = now_sql();
    $values[] = now_sql();
    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = $pdo->prepare('INSERT INTO galleries (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')');
    $stmt->execute($values);

    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) $pdo->lastInsertId());
    if ($gallery) {
        write_gallery_sidecar($gallery);
    }
    return $gallery;
}

/**
 * Create a real empty folder and immediately index it as a gallery.
 */
function create_empty_gallery(array $input): array
{
    // $title stores an intermediate value used by the surrounding gallery workflow.
    $title = trim((string) ($input['title'] ?? ''));
    if ($title === '') {
        throw new RuntimeException('Gallery title is required.');
    }
    // $description stores an intermediate value used by the surrounding gallery workflow.
    $description = (string) ($input['description'] ?? '');
    // $visibility stores an intermediate value used by the surrounding gallery workflow.
    $visibility = gallery_visibility_storage_value((string) ($input['visibility'] ?? 'unpublished'));
    // $votingEnabled stores an intermediate value used by the surrounding gallery workflow.
    $votingEnabled = !empty($input['voting_enabled']) ? 1 : 0;
    // $showFilenames stores an intermediate value used by the surrounding gallery workflow.
    $showFilenames = !empty($input['show_filenames']) ? 1 : 0;
    // $parentId stores an intermediate value used by the surrounding gallery workflow.
    $parentId = (int) ($input['parent_id'] ?? 0);
    // $parent stores an intermediate value used by the surrounding gallery workflow.
    $parent = $parentId > 0 ? find_gallery($parentId) : null;
    if ($parentId > 0 && !$parent) {
        throw new RuntimeException('Selected parent gallery does not exist.');
    }

    // $folderName stores an intermediate value used by the surrounding gallery workflow.
    $folderName = trim((string) ($input['folder_name'] ?? ''));
    // $folderPath stores an intermediate value used by the surrounding gallery workflow.
    $folderPath = unique_gallery_child_folder_path($parent, $folderName !== '' ? $folderName : $title);
    // $target stores an intermediate value used by the surrounding gallery workflow.
    $target = gallery_target_abs_path($folderPath);
    if (file_exists($target)) {
        throw new RuntimeException('Gallery folder already exists.');
    }
    if (!mkdir($target, 0775, true)) {
        throw new RuntimeException('Could not create gallery folder.');
    }

    // $sidecarWritten stores an intermediate value used by the surrounding gallery workflow.
    $sidecarWritten = write_gallery_sidecar_for_path($folderPath, [
        'title' => $title,
        'description' => $description,
        'visibility' => $visibility,
        'sort_order' => (int) ($input['sort_order'] ?? 0),
        'voting_enabled' => $votingEnabled,
        'show_filenames' => $showFilenames,
    ]);
    if (!$sidecarWritten) {
        throw new RuntimeException('Gallery folder was created, but gallery.json could not be written.');
    }

    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = create_gallery_row_for_folder($folderPath);
    if (!$gallery) {
        throw new RuntimeException('Gallery folder was created, but the database row could not be created.');
    }
    sync_gallery_parent_ids();
    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = find_gallery((int) $gallery['id']) ?: $gallery;
    write_gallery_sidecar($gallery);
    return $gallery;
}
