<?php

declare(strict_types=1);

/**
 * Gallery mutation model.
 * 
 * This module owns filesystem-backed gallery changes: subtree deletion, folder moves, imports, ancestor creation, and parent synchronization. It intentionally keeps the filesystem as the source of truth and updates the database to follow it.
 */

function gallery_subtree_rows(int $galleryId): array
{
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        return [];
    }
    $folderPath = normalize_relative_path((string) $gallery['folder_path']);
    $stmt = db()->prepare('SELECT * FROM galleries WHERE folder_path = ? OR folder_path LIKE ? ORDER BY folder_path');
    $stmt->execute([$folderPath, $folderPath . '/%']);
    return $stmt->fetchAll();
}

function delete_gallery_subtrees(array $galleryIds): array
{
    // Variable $rootIds stores this steps working value.
    $rootIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds))));
    if (!$rootIds) {
        return ['root_count' => 0, 'row_count' => 0];
    }

    // Variable $roots stores this steps working value.
    $roots = [];
    foreach ($rootIds as $galleryId) {
        // Variable $gallery stores this steps working value.
        $gallery = find_gallery($galleryId);
        if (!$gallery) {
            continue;
        }
        $roots[] = $gallery;
    }
    if (!$roots) {
        return ['root_count' => 0, 'row_count' => 0];
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

    // Variable $deletedFolders stores this steps working value.
    $deletedFolders = [];
    foreach ($keptRoots as $gallery) {
        // Variable $absolutePath stores this steps working value.
        $absolutePath = gallery_abs_path((string) $gallery['folder_path']);
        if (!is_dir($absolutePath)) {
            throw new RuntimeException('Gallery folder does not exist on disk: ' . (string) $gallery['folder_path']);
        }
        delete_directory_tree($absolutePath, galleries_root());
        $deletedFolders[] = $absolutePath;
    }

    if ($allRowIds) {
        // Variable $pdo stores this steps working value.
        $pdo = db();
        // Variable $placeholders stores this steps working value.
        $placeholders = implode(',', array_fill(0, count($allRowIds), '?'));
        // Variable $stmt stores this steps working value.
        $stmt = $pdo->prepare('DELETE FROM galleries WHERE id IN (' . $placeholders . ')');
        $stmt->execute(array_values($allRowIds));
    }

    sync_gallery_parent_ids();
    if (public_path_schema_ready()) {
        regenerate_public_paths();
    }

    return ['root_count' => count($deletedFolders), 'row_count' => count($allRowIds)];
}

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

function move_gallery_folder_to_parent(int $galleryId, ?int $parentId, ?string $folderName = null): array
{
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        throw new RuntimeException('Gallery not found.');
    }
    $oldPath = normalize_relative_path((string) $gallery['folder_path']);
    $oldAbs = gallery_abs_path($oldPath);
    if (!is_dir($oldAbs)) {
        throw new RuntimeException('Current gallery folder does not exist on disk.');
    }

    $parent = $parentId !== null && $parentId > 0 ? find_gallery($parentId) : null;
    if ($parentId !== null && $parentId > 0 && !$parent) {
        throw new RuntimeException('Selected parent gallery does not exist.');
    }
    if ($parent && (int) $parent['id'] === $galleryId) {
        throw new RuntimeException('A gallery cannot be moved under itself.');
    }
    if ($parent) {
        $parentPath = normalize_relative_path((string) $parent['folder_path']);
        if ($parentPath === $oldPath || str_starts_with($parentPath . '/', $oldPath . '/')) {
            throw new RuntimeException('A gallery cannot be moved under one of its own subgalleries.');
        }
        if (!is_dir(gallery_abs_path($parentPath))) {
            throw new RuntimeException('Selected parent folder does not exist on disk.');
        }
    }

    $currentFolderName = gallery_folder_name_from_path($oldPath);
    $targetFolderName = $folderName !== null && trim($folderName) !== '' ? gallery_folder_segment($folderName) : $currentFolderName;
    if ($targetFolderName === '') {
        throw new RuntimeException('Gallery folder name cannot be empty.');
    }
    $newPath = $parent ? normalize_relative_path((string) $parent['folder_path'] . '/' . $targetFolderName) : $targetFolderName;
    if ($newPath === $oldPath) {
        return ['moved' => false, 'from' => $oldPath, 'to' => $newPath, 'galleries' => 0];
    }
    if (find_gallery_by_folder_path($newPath)) {
        throw new RuntimeException('Another gallery already uses the destination folder path.');
    }
    $newAbs = gallery_target_abs_path($newPath);
    if (file_exists($newAbs)) {
        throw new RuntimeException('Destination folder already exists on disk.');
    }

    $rows = gallery_subtree_rows($galleryId);
    $pathMap = [];
    foreach ($rows as $row) {
        $rowPath = normalize_relative_path((string) $row['folder_path']);
        $suffix = $rowPath === $oldPath ? '' : substr($rowPath, strlen($oldPath) + 1);
        $pathMap[(int) $row['id']] = $suffix === '' ? $newPath : normalize_relative_path($newPath . '/' . $suffix);
    }

    $pdo = db();
    $moved = false;
    try {
        $pdo->beginTransaction();
        if (!rename($oldAbs, $newAbs)) {
            throw new RuntimeException('Could not move gallery folder on disk.');
        }
        $moved = true;
        $stmt = $pdo->prepare('UPDATE galleries SET folder_path = ?, folder_path_hash = ?, parent_id = ?, updated_at = ? WHERE id = ?');
        foreach ($pathMap as $id => $path) {
            $rowParentId = $id === $galleryId ? ($parent ? (int) $parent['id'] : null) : null;
            if ($id !== $galleryId) {
                $rowParent = find_parent_gallery_for_path($path);
                $rowParentId = $rowParent ? (int) $rowParent['id'] : null;
            }
            $stmt->execute([$path, hash('sha256', $path), $rowParentId, now_sql(), $id]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($moved && is_dir($newAbs) && !is_dir($oldAbs)) {
            @rename($newAbs, $oldAbs);
        }
        throw new RuntimeException('Gallery move failed: ' . $exception->getMessage(), 0, $exception);
    }

    sync_gallery_parent_ids();
    foreach (array_keys($pathMap) as $id) {
        $updated = find_gallery((int) $id);
        if ($updated) {
            write_gallery_sidecar($updated);
        }
    }

    return ['moved' => true, 'from' => $oldPath, 'to' => $newPath, 'galleries' => count($pathMap)];
}

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

function import_galleries(array $folderPaths, bool $createThumbnails = false): array
{
    // Variable $candidates stores this steps working value.
    $candidates = [];
    foreach (discover_gallery_candidates() as $candidate) {
        $candidates[$candidate['folder_path']] = $candidate;
    }

    // Variable $requested stores this steps working value.
    $requested = array_map(static fn ($path): string => normalize_relative_path((string) $path), $folderPaths);
    // Variable $folderPaths stores this steps working value.
    $folderPaths = [];
    foreach ($requested as $requestedPath) {
        if ($requestedPath === '') {
            continue;
        }

        // Import missing ancestors first. This is important when the admin
        // selects only a deep child folder from the discovery screen. Without
        // these rows, sync_gallery_parent_ids() has no parent record to attach
        // the child to, so the child appears as a top-level gallery.
        // Variable $segments stores this steps working value.
        $segments = explode('/', $requestedPath);
        // Variable $ancestorSegments stores this steps working value.
        $ancestorSegments = [];
        while (count($segments) > 1) {
            $ancestorSegments[] = array_shift($segments);
            // Variable $ancestorPath stores this steps working value.
            $ancestorPath = implode('/', $ancestorSegments);
            if (isset($candidates[$ancestorPath]) || is_dir(gallery_abs_path($ancestorPath))) {
                $folderPaths[$ancestorPath] = $ancestorPath;
            }
        }

        foreach (array_keys($candidates) as $candidatePath) {
            if ($candidatePath === $requestedPath || str_starts_with($candidatePath, $requestedPath . '/')) {
                $folderPaths[$candidatePath] = $candidatePath;
            }
        }
    }
    usort($folderPaths, static fn ($a, $b): int => substr_count((string) $a, '/') <=> substr_count((string) $b, '/'));

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
    return ['imported' => $imported, 'scanned' => $scanned, 'thumbnails' => $thumbs];
}

function import_galleries_without_thumbnails(array $folderPaths): array
{
    // Variable $candidates stores this steps working value.
    $candidates = [];
    foreach (discover_gallery_candidates() as $candidate) {
        $candidates[$candidate['folder_path']] = $candidate;
    }

    // Variable $requested stores this steps working value.
    $requested = array_map(static fn ($path): string => normalize_relative_path((string) $path), $folderPaths);
    // Variable $folderPaths stores this steps working value.
    $folderPaths = [];
    foreach ($requested as $requestedPath) {
        if ($requestedPath === '') {
            continue;
        }
        $segments = explode('/', $requestedPath);
        $ancestorSegments = [];
        while (count($segments) > 1) {
            $ancestorSegments[] = array_shift($segments);
            $ancestorPath = implode('/', $ancestorSegments);
            if (isset($candidates[$ancestorPath]) || is_dir(gallery_abs_path($ancestorPath))) {
                $folderPaths[$ancestorPath] = $ancestorPath;
            }
        }
        foreach (array_keys($candidates) as $candidatePath) {
            if ($candidatePath === $requestedPath || str_starts_with($candidatePath, $requestedPath . '/')) {
                $folderPaths[$candidatePath] = $candidatePath;
            }
        }
    }
    usort($folderPaths, static fn ($a, $b): int => substr_count((string) $a, '/') <=> substr_count((string) $b, '/'));

    $imported = 0;
    $scanned = 0;
    $importedIds = [];
    foreach ($folderPaths as $folderPath) {
        if (find_gallery_by_folder_path($folderPath)) {
            continue;
        }
        $gallery = create_gallery_row_for_folder($folderPath);
        if (!$gallery) {
            continue;
        }
        $importedIds[] = (int) $gallery['id'];
        $imported++;
    }
    sync_gallery_parent_ids();
    foreach ($importedIds as $galleryId) {
        $scanned += scan_gallery_images($galleryId);
    }
    return ['imported' => $imported, 'scanned' => $scanned, 'gallery_ids' => $importedIds, 'thumbnails' => 0];
}

function sync_gallery_parent_ids(): void
{
    // Variable $galleries stores this steps working value.
    $galleries = db()->query('SELECT id, folder_path FROM galleries ORDER BY folder_path')->fetchAll();
    foreach ($galleries as $gallery) {
        // Missing intermediate gallery rows are repaired before parent lookup.
        // This fixes older imports where a deep folder was imported without its
        // parent and therefore appeared on the public homepage as a root gallery.
        ensure_gallery_ancestors_for_path((string) $gallery['folder_path']);

        // Variable $parent stores this steps working value.
        $parent = find_parent_gallery_for_path((string) $gallery['folder_path']);
        // Variable $parentId stores this steps working value.
        $parentId = $parent ? (int) $parent['id'] : null;
        if ($parentId === null) {
            // Variable $stmt stores this steps working value.
            $stmt = db()->prepare('UPDATE galleries SET parent_id = NULL, updated_at = ? WHERE id = ? AND parent_id IS NOT NULL');
            $stmt->execute([now_sql(), (int) $gallery['id']]);
            continue;
        }
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('UPDATE galleries SET parent_id = ?, updated_at = ? WHERE id = ? AND (parent_id IS NULL OR parent_id <> ?)');
        $stmt->execute([$parentId, now_sql(), (int) $gallery['id'], $parentId]);
    }
}

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

