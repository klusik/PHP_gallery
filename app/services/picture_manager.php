<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/picture_manager.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides reusable data and filesystem operations for public-view picture management.
 *
 * Responsibilities:
 *   - Validate public-view photo and physical-gallery selections before mutation
 *   - Reuse existing gallery image movement logic where possible
 *   - Copy selected images and physical gallery trees without removing source files
 *   - Keep file and database changes reversible on failure
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
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;
use function Gallery\Models\gallery_mutation_model_max_image_sort_order;
use function Gallery\Models\picture_manager_model_copy_rows;
use function Gallery\Models\picture_manager_model_image_table_columns;

/**
 * Normalize submitted image IDs into unique positive integers while preserving order.
 *
 * @param array<mixed> $imageIds Raw IDs from a POST body or caller-provided list.
 * @return array<int> Unique positive IDs in submitted order.
 */
function picture_manager_normalize_image_ids(array $imageIds): array
{
    // $normalizedIds stores the final selection sent to service-layer operations.
    $normalizedIds = [];
    // $seen stores IDs already accepted so repeated form values cannot duplicate work.
    $seen = [];
    foreach ($imageIds as $imageId) {
        // $id stores one sanitized image identifier.
        $id = (int) $imageId;
        if ($id <= 0 || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $normalizedIds[] = $id;
    }
    return $normalizedIds;
}

/**
 * Return image rows owned by one source gallery, sorted in their current visual order.
 *
 * @param int $sourceGalleryId Gallery that must own every selected image.
 * @param array<int> $imageIds Normalized image IDs submitted by the UI.
 * @param array<int,string> $failures Mutable validation messages for rejected IDs.
 * @return array<int,array<string,mixed>> Valid image rows in stable source order.
 */
function picture_manager_owned_images_for_selection(int $sourceGalleryId, array $imageIds, array &$failures): array
{
    // $images stores validated database rows for the selected photos.
    $images = [];
    foreach ($imageIds as $imageId) {
        // $image stores one selected database row.
        $image = find_image((int) $imageId);
        if (!$image || (int) ($image['gallery_id'] ?? 0) !== $sourceGalleryId) {
            $failures[] = 'Image #' . (int) $imageId . ' is not part of the source gallery.';
            continue;
        }
        $images[] = $image;
    }

    usort($images, static function (array $left, array $right): int {
        // $sortCompare keeps copied or moved images in the source gallery order visible to the user.
        $sortCompare = (int) ($left['sort_order'] ?? 0) <=> (int) ($right['sort_order'] ?? 0);
        if ($sortCompare !== 0) {
            return $sortCompare;
        }
        // $nameCompare keeps ordering deterministic when sort_order values match.
        $nameCompare = strcmp((string) ($left['filename'] ?? ''), (string) ($right['filename'] ?? ''));
        if ($nameCompare !== 0) {
            return $nameCompare;
        }
        return (int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0);
    });

    return $images;
}

/**
 * Copy selected original files, generated derivatives, image rows, and image tags to another gallery.
 *
 * The source gallery is not changed. The destination receives real physical file
 * copies so later maintenance, downloads, scans, and thumbnail operations keep
 * behaving as if the photos had been uploaded there directly.
 *
 * @param int $sourceGalleryId Gallery that currently owns the selected images.
 * @param int $destinationGalleryId Gallery that will receive the copied images.
 * @param array<int> $imageIds Image IDs selected by the logged-in user.
 * @return array{requested:int,copied:int,skipped:int,originals_copied:int,derivatives_copied:int,failures:array<int,string>,skipped_existing:array<int,string>,created_image_ids:array<int,int>,destination_cover_image_id:int|null} Structured result data for the caller.
 */
function copy_gallery_images(int $sourceGalleryId, int $destinationGalleryId, array $imageIds): array
{
    mutation_schema_assert_available(
        gallery_move_schema_status(),
        'picture_manager.copy_images',
        'Image copies require the current gallery/image ownership schema. Run pending migrations first.',
        'Image copies are temporarily unavailable because the required database schema could not be verified.'
    );

    // $normalizedIds stores unique positive IDs from the browser selection.
    $normalizedIds = picture_manager_normalize_image_ids($imageIds);
    if (!$normalizedIds) {
        return [
            'requested' => 0,
            'copied' => 0,
            'skipped' => 0,
            'originals_copied' => 0,
            'derivatives_copied' => 0,
            'failures' => [],
            'skipped_existing' => [],
            'created_image_ids' => [],
            'destination_cover_image_id' => null,
        ];
    }
    if ($sourceGalleryId === $destinationGalleryId) {
        throw new RuntimeException('Choose a different destination gallery.');
    }

    // $sourceGallery stores the gallery that currently owns the selected rows.
    $sourceGallery = find_gallery($sourceGalleryId, true);
    // $destinationGallery stores the gallery that will receive copies of the selected rows.
    $destinationGallery = find_gallery($destinationGalleryId, true);
    if (!$sourceGallery || !$destinationGallery) {
        throw new RuntimeException('Source or destination gallery was not found.');
    }

    // $sourceRoot stores the filesystem boundary for current originals and derivatives.
    $sourceRoot = gallery_abs_path((string) $sourceGallery['folder_path']);
    // $destinationRoot stores the filesystem boundary for copied originals and derivatives.
    $destinationRoot = gallery_abs_path((string) $destinationGallery['folder_path']);
    if (!is_dir($sourceRoot) || !is_dir($destinationRoot)) {
        throw new RuntimeException('Source or destination gallery folder does not exist on disk.');
    }

    // $failures stores validation messages collected before any file is copied.
    $failures = [];
    // $images stores validated selected rows in visual source order.
    $images = picture_manager_owned_images_for_selection($sourceGalleryId, $normalizedIds, $failures);
    if (!$images) {
        return [
            'requested' => count($normalizedIds),
            'copied' => 0,
            'skipped' => 0,
            'originals_copied' => 0,
            'derivatives_copied' => 0,
            'failures' => $failures,
            'skipped_existing' => [],
            'created_image_ids' => [],
            'destination_cover_image_id' => null,
        ];
    }

    // $manifest stores every physical copy required for originals and generated files.
    $manifest = [];
    // $targetPaths stores target paths so collisions inside the selected set fail before file writes.
    $targetPaths = [];
    // $copyableImages stores selected images that are absent from the destination and can be copied.
    $copyableImages = [];
    // $skippedExisting stores photos skipped because the destination already has the same relative path.
    $skippedExisting = [];
    foreach ($images as $image) {
        // $imageLabel stores a readable name for validation messages.
        $imageLabel = (string) ($image['relative_path'] ?: $image['filename'] ?: ('#' . (int) $image['id']));
        // $relativePath stores the same image path below the destination gallery.
        $relativePath = normalize_relative_path((string) ($image['relative_path'] ?? ''));
        if ($relativePath === '') {
            $failures[] = $imageLabel . ': image relative path is empty.';
            continue;
        }
        if (find_image_by_path($destinationGalleryId, $relativePath)) {
            $skippedExisting[] = $imageLabel . ': destination gallery already has a database record with this path.';
            continue;
        }

        try {
            // $sourceOriginal stores the original file in the source gallery.
            $sourceOriginal = image_abs_path($image, $sourceGallery);
            // $destinationOriginal stores the copied original file path in the destination gallery.
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
        $copyableImages[] = $image;
    }

    if ($failures) {
        return [
            'requested' => count($normalizedIds),
            'copied' => 0,
            'skipped' => count($skippedExisting),
            'originals_copied' => 0,
            'derivatives_copied' => 0,
            'failures' => $failures,
            'skipped_existing' => $skippedExisting,
            'created_image_ids' => [],
            'destination_cover_image_id' => null,
        ];
    }

    if (!$copyableImages) {
        return [
            'requested' => count($normalizedIds),
            'copied' => 0,
            'skipped' => count($skippedExisting),
            'originals_copied' => 0,
            'derivatives_copied' => 0,
            'failures' => [],
            'skipped_existing' => $skippedExisting,
            'created_image_ids' => [],
            'destination_cover_image_id' => null,
        ];
    }

    // $copiedFiles stores successful file copies so they can be removed after a later failure.
    $copiedFiles = [];
    try {
        foreach ($manifest as $entry) {
            // $targetDirectory stores the destination directory that must exist before copy().
            $targetDirectory = dirname((string) $entry['to']);
            if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true)) {
                throw new RuntimeException('Could not create destination directory: ' . $targetDirectory);
            }
            if (!@copy((string) $entry['from'], (string) $entry['to'])) {
                throw new RuntimeException('Could not copy file: ' . basename((string) $entry['from']));
            }
            @chmod((string) $entry['to'], 0664);
            $copiedFiles[] = $entry;
        }
    } catch (Throwable $exception) {
        picture_manager_remove_copied_files($copiedFiles);
        throw $exception;
    }

    // $destinationSortOrders stores append-style order values assigned in the destination gallery.
    $destinationSortOrders = picture_manager_destination_copy_sort_orders($destinationGalleryId, $copyableImages);
    // $rowsBySourceImageId stores INSERT-ready rows while filesystem rollback remains service-owned.
    $rowsBySourceImageId = [];
    foreach ($copyableImages as $image) {
        $sourceImageId = (int) $image['id'];
        $rowsBySourceImageId[$sourceImageId] = picture_manager_image_copy_row(
            $image,
            $destinationGalleryId,
            $destinationSortOrders[$sourceImageId] ?? next_gallery_image_sort_order($destinationGalleryId)
        );
    }
    $copyTags = mutation_schema_optional_table_columns_available(
        'mutation.picture_copy_tags',
        'image_tags',
        ['image_id', 'tag_id'],
        'picture_manager.copy_tags'
    );
    $destinationBranchIds = gallery_subtree_ids($destinationGalleryId);

    try {
        $persistenceResult = picture_manager_model_copy_rows(
            $destinationGalleryId,
            $rowsBySourceImageId,
            $copyTags,
            $destinationBranchIds,
            now_sql()
        );
        $createdImageIds = $persistenceResult['created_image_ids'];
        $destinationCoverImageId = $persistenceResult['destination_cover_image_id'];
    } catch (Throwable $exception) {
        picture_manager_remove_copied_files($copiedFiles);
        throw $exception;
    }

    thumbnail_maintenance_summary_cache_clear();
    if (public_path_schema_ready()) {
        regenerate_public_paths();
    }
    // $updatedDestinationGallery stores the destination row after image copies were inserted.
    $updatedDestinationGallery = find_gallery($destinationGalleryId, true);
    if ($updatedDestinationGallery) {
        write_gallery_sidecar($updatedDestinationGallery);
    }

    // $originalsCopied stores copied original media files.
    $originalsCopied = count(array_filter($copiedFiles, static fn (array $entry): bool => (string) $entry['kind'] === 'original'));
    // $derivativesCopied stores copied generated files.
    $derivativesCopied = count(array_filter($copiedFiles, static fn (array $entry): bool => (string) $entry['kind'] === 'derivative'));

    return [
        'requested' => count($normalizedIds),
        'copied' => count($createdImageIds),
        'skipped' => count($skippedExisting),
        'originals_copied' => $originalsCopied,
        'derivatives_copied' => $derivativesCopied,
        'failures' => [],
        'skipped_existing' => $skippedExisting,
        'created_image_ids' => $createdImageIds,
        'destination_cover_image_id' => $destinationCoverImageId,
    ];
}

/**
 * Remove copied files after a later copy or database step fails.
 *
 * @param array<int,array{from:string,to:string,kind:string}> $copiedFiles File copies completed before failure.
 */
function picture_manager_remove_copied_files(array $copiedFiles): void
{
    for ($index = count($copiedFiles) - 1; $index >= 0; $index--) {
        // $entry stores one copied file that should be removed.
        $entry = $copiedFiles[$index];
        if (is_file((string) $entry['to'])) {
            @unlink((string) $entry['to']);
        }
    }
}

/**
 * Build append-style sort_order values for image copies in the destination gallery.
 *
 * @param int $destinationGalleryId Destination gallery ID.
 * @param array<int,array<string,mixed>> $images Source images in visual order.
 * @return array<int,int> Sort values keyed by source image ID.
 */
function picture_manager_destination_copy_sort_orders(int $destinationGalleryId, array $images): array
{
    // $nextSortOrder stores the first appended order number.
    $nextSortOrder = gallery_mutation_model_max_image_sort_order($destinationGalleryId) + 10;
    // $orders stores sort values keyed by source image ID.
    $orders = [];
    foreach ($images as $image) {
        $orders[(int) $image['id']] = $nextSortOrder;
        $nextSortOrder += 10;
    }
    return $orders;
}

/**
 * Return the current images table columns in database order.
 *
 * @return array<int,string> Column names from the images table.
 */
function picture_manager_image_table_columns(): array
{
    static $columns = null;
    if (is_array($columns)) {
        return $columns;
    }

    $columns = picture_manager_model_image_table_columns();
    return $columns;
}

/**
 * Build an INSERT-ready image row for copying one image to another gallery.
 *
 * @param array<string,mixed> $image Source image database row.
 * @param int $destinationGalleryId Destination gallery ID.
 * @param int $sortOrder Destination sort_order value.
 * @return array<string,mixed> Column values for INSERT.
 */
function picture_manager_image_copy_row(array $image, int $destinationGalleryId, int $sortOrder): array
{
    // $now stores the timestamp used for created_at and updated_at in the copied row.
    $now = now_sql();
    // $relativePath stores the normalized path kept under the destination gallery.
    $relativePath = normalize_relative_path((string) ($image['relative_path'] ?? ''));
    // $row stores INSERT values keyed by image-table column.
    $row = [];
    foreach (picture_manager_image_table_columns() as $column) {
        if ($column === 'id') {
            continue;
        }
        if ($column === 'gallery_id') {
            $row[$column] = $destinationGalleryId;
            continue;
        }
        if ($column === 'relative_path') {
            $row[$column] = $relativePath;
            continue;
        }
        if ($column === 'relative_path_hash') {
            $row[$column] = hash('sha256', $relativePath);
            continue;
        }
        if ($column === 'sort_order') {
            $row[$column] = $sortOrder;
            continue;
        }
        if ($column === 'created_at' || $column === 'updated_at') {
            $row[$column] = $now;
            continue;
        }
        $row[$column] = array_key_exists($column, $image) ? $image[$column] : null;
    }
    return $row;
}

/**
 * Normalize submitted gallery IDs into unique positive integers while preserving order.
 *
 * @param array<mixed> $galleryIds Raw IDs from a POST body or caller-provided list.
 * @return array<int> Unique positive IDs in submitted order.
 */
function picture_manager_normalize_gallery_ids(array $galleryIds): array
{
    // $normalizedIds stores the final physical-gallery selection.
    $normalizedIds = [];
    // $seen stores IDs already accepted so repeated form values cannot duplicate work.
    $seen = [];
    foreach ($galleryIds as $galleryId) {
        // $id stores one sanitized gallery identifier.
        $id = (int) $galleryId;
        if ($id <= 0 || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $normalizedIds[] = $id;
    }
    return $normalizedIds;
}

/**
 * Return selected physical galleries that are direct children of the source gallery.
 *
 * Picture manager selection is deliberately limited to cards rendered on the current
 * public page. Requiring the persisted parent relationship prevents forged requests
 * from mutating arbitrary or nested galleries that were not part of that selection.
 *
 * @param int $sourceGalleryId Parent gallery whose direct children may be selected.
 * @param array<int> $galleryIds Normalized selected gallery IDs.
 * @param array<int,string> $failures Mutable validation messages for rejected IDs.
 * @return array<int,array<string,mixed>> Valid direct child gallery rows in submitted order.
 */
function picture_manager_owned_galleries_for_selection(int $sourceGalleryId, array $galleryIds, array &$failures): array
{
    // $galleries stores validated direct physical subgalleries.
    $galleries = [];
    foreach ($galleryIds as $galleryId) {
        // $gallery stores one selected physical gallery row.
        $gallery = find_gallery((int) $galleryId, true);
        if (!$gallery) {
            $failures[] = 'Selected gallery #' . (int) $galleryId . ' was not found.';
            continue;
        }
        if ((int) ($gallery['parent_id'] ?? 0) !== $sourceGalleryId) {
            $failures[] = 'Selected gallery #' . (int) $galleryId . ' is not a direct child of the source gallery.';
            continue;
        }
        // $folderPath stores the authoritative filesystem location for the selected gallery.
        $folderPath = normalize_relative_path((string) ($gallery['folder_path'] ?? ''));
        if ($folderPath === '' || !is_dir(gallery_abs_path($folderPath))) {
            $failures[] = 'Selected gallery #' . (int) $galleryId . ' does not have an accessible gallery folder.';
            continue;
        }
        $galleries[] = $gallery;
    }
    return $galleries;
}

/**
 * Refuse a destination that sits inside any selected physical gallery subtree.
 *
 * Copying a selected directory into itself or one of its descendants would create
 * recursive filesystem growth. The same guard is used before a new destination
 * gallery is created, because creating that folder first would otherwise make the
 * source subtree contain its own copy target.
 *
 * @param int $destinationGalleryId Existing destination or future parent gallery ID.
 * @param array<int> $selectedGalleryIds Selected direct subgallery IDs.
 */
function picture_manager_assert_destination_outside_gallery_selection(int $destinationGalleryId, array $selectedGalleryIds): void
{
    if (!$selectedGalleryIds) {
        return;
    }
    // $destination stores the existing gallery that will contain copied data.
    $destination = find_gallery($destinationGalleryId, true);
    if (!$destination) {
        throw new RuntimeException('Choose a valid destination gallery.');
    }
    // $destinationPath stores the normalized physical destination directory.
    $destinationPath = normalize_relative_path((string) ($destination['folder_path'] ?? ''));
    foreach ($selectedGalleryIds as $galleryId) {
        // $selectedGallery stores one selected source root.
        $selectedGallery = find_gallery((int) $galleryId, true);
        if (!$selectedGallery) {
            throw new RuntimeException('Selected gallery #' . (int) $galleryId . ' was not found.');
        }
        // $selectedPath stores the selected root used for descendant detection.
        $selectedPath = normalize_relative_path((string) ($selectedGallery['folder_path'] ?? ''));
        if ($selectedPath === '') {
            throw new RuntimeException('Selected gallery #' . (int) $galleryId . ' has an invalid folder path.');
        }
        if ($destinationPath === $selectedPath || str_starts_with($destinationPath . '/', $selectedPath . '/')) {
            throw new RuntimeException('A selected gallery cannot be copied into itself or one of its descendants.');
        }
    }
}

/**
 * Copy selected physical subgallery trees below another existing gallery.
 *
 * The directory tree is copied first, including gallery.json sidecars and generated
 * media files. Database gallery/image rows are then rebuilt from the copied folders,
 * keeping the filesystem as the authoritative source of truth. Any failure rolls
 * back all roots copied by this call.
 *
 * @param int $sourceGalleryId Gallery whose direct children were selected.
 * @param int $destinationGalleryId Existing gallery that will receive copied roots.
 * @param array<int> $galleryIds Selected direct child gallery IDs.
 * @return array{requested:int,copied_roots:int,copied_rows:int,scanned_images:int,created_gallery_ids:array<int>}
 */
function picture_manager_copy_gallery_subtrees(int $sourceGalleryId, int $destinationGalleryId, array $galleryIds): array
{
    // $galleryIds stores a de-duplicated physical gallery selection.
    $galleryIds = picture_manager_normalize_gallery_ids($galleryIds);
    if (!$galleryIds) {
        return ['requested' => 0, 'copied_roots' => 0, 'copied_rows' => 0, 'scanned_images' => 0, 'created_gallery_ids' => []];
    }
    if ($sourceGalleryId <= 0 || $destinationGalleryId <= 0) {
        throw new RuntimeException('Source and destination galleries are required.');
    }

    // $sourceGallery stores the page where the selected subgallery cards were rendered.
    $sourceGallery = find_gallery($sourceGalleryId, true);
    // $destinationGallery stores the physical parent that will receive the clones.
    $destinationGallery = find_gallery($destinationGalleryId, true);
    if (!$sourceGallery || !$destinationGallery) {
        throw new RuntimeException('Source or destination gallery was not found.');
    }

    // $validationFailures stores stale or forged selection failures.
    $validationFailures = [];
    // $selectedRoots stores only direct children of the source gallery.
    $selectedRoots = picture_manager_owned_galleries_for_selection($sourceGalleryId, $galleryIds, $validationFailures);
    if ($validationFailures || count($selectedRoots) !== count($galleryIds)) {
        throw new RuntimeException(implode(' ', $validationFailures ?: ['One or more selected galleries are invalid.']));
    }
    picture_manager_assert_destination_outside_gallery_selection($destinationGalleryId, $galleryIds);

    // $destinationPath stores the normalized folder path used to build cloned root paths.
    $destinationPath = normalize_relative_path((string) $destinationGallery['folder_path']);
    // $plans stores prevalidated source-to-target subtree mappings before any file is written.
    $plans = [];
    foreach ($selectedRoots as $selectedRoot) {
        // Flush current DB-backed gallery metadata to sidecars before the filesystem clone.
        $subtreeRows = gallery_subtree_rows((int) $selectedRoot['id']);
        if (!$subtreeRows) {
            throw new RuntimeException('Selected gallery subtree could not be loaded.');
        }
        foreach ($subtreeRows as $row) {
            write_gallery_sidecar($row);
        }

        // $sourceRootPath stores the selected physical subtree root.
        $sourceRootPath = normalize_relative_path((string) $selectedRoot['folder_path']);
        // $targetRootPath preserves the selected root folder name under the destination.
        $targetRootPath = normalize_relative_path($destinationPath . '/' . basename($sourceRootPath));
        // $targetRootAbs stores the filesystem location that must be entirely unused.
        $targetRootAbs = gallery_abs_path($targetRootPath);
        if (file_exists($targetRootAbs) || find_gallery_by_folder_path($targetRootPath, true)) {
            throw new RuntimeException('Destination already contains a gallery folder named ' . basename($sourceRootPath) . '.');
        }

        // Validate every indexed source-gallery path against the future target before copying.
        foreach ($subtreeRows as $row) {
            // $rowPath stores one indexed gallery path in the selected subtree.
            $rowPath = normalize_relative_path((string) ($row['folder_path'] ?? ''));
            if ($rowPath !== $sourceRootPath && !str_starts_with($rowPath . '/', $sourceRootPath . '/')) {
                throw new RuntimeException('Selected gallery subtree contains an invalid indexed path.');
            }
            // $suffix stores the descendant path below the selected root, including its leading slash.
            $suffix = $rowPath === $sourceRootPath ? '' : substr($rowPath, strlen($sourceRootPath));
            // $targetPath stores the future indexed path for this descendant gallery.
            $targetPath = normalize_relative_path($targetRootPath . $suffix);
            if (find_gallery_by_folder_path($targetPath, true)) {
                throw new RuntimeException('Destination already contains an indexed gallery at /' . $targetPath . '.');
            }
        }

        $plans[] = [
            'source_root_path' => $sourceRootPath,
            'source_root_abs' => gallery_abs_path($sourceRootPath),
            'target_root_path' => $targetRootPath,
            'target_root_abs' => $targetRootAbs,
            'rows' => $subtreeRows,
        ];
    }

    // $createdRootIds stores clone roots that can be removed through the normal gallery deletion service on rollback.
    $createdRootIds = [];
    // $rawCopiedRoots stores copied directories that may exist before a database root row exists.
    $rawCopiedRoots = [];
    // $createdGalleryIds stores every new indexed gallery row for the result payload.
    $createdGalleryIds = [];
    // $scannedImages counts direct image files re-indexed from copied folders.
    $scannedImages = 0;

    try {
        foreach ($plans as $plan) {
            // Reserve the destination root atomically before copying. This prevents a
            // concurrent create/import from turning a copy into an accidental directory merge.
            $targetRootAbs = (string) $plan['target_root_abs'];
            if (!@mkdir($targetRootAbs, 0775, false)) {
                throw new RuntimeException('Destination gallery folder became unavailable before copy: ' . basename($targetRootAbs) . '.');
            }
            // Track the root before the recursive copier starts so a mid-copy failure
            // still removes every partially written file during rollback.
            $rawCopiedRoots[] = $targetRootAbs;
            gallery_trash_copy_directory((string) $plan['source_root_abs'], $targetRootAbs);

            // Parents must be created before children so parent_id discovery follows the copied tree.
            $rows = (array) $plan['rows'];
            usort($rows, static fn (array $left, array $right): int => strlen((string) ($left['folder_path'] ?? '')) <=> strlen((string) ($right['folder_path'] ?? '')));
            foreach ($rows as $row) {
                // $rowPath stores the original indexed gallery path.
                $rowPath = normalize_relative_path((string) $row['folder_path']);
                // $suffix stores the path below the copied selected root.
                $suffix = $rowPath === (string) $plan['source_root_path'] ? '' : substr($rowPath, strlen((string) $plan['source_root_path']));
                // $targetPath stores the corresponding copied gallery path.
                $targetPath = normalize_relative_path((string) $plan['target_root_path'] . $suffix);
                // $created stores the newly indexed gallery row read from the copied gallery.json sidecar.
                $created = create_gallery_row_for_folder($targetPath);
                if (!$created) {
                    throw new RuntimeException('Copied gallery folder /' . $targetPath . ' could not be indexed.');
                }
                $createdGalleryIds[] = (int) $created['id'];
                if ($suffix === '') {
                    $createdRootIds[] = (int) $created['id'];
                }
            }
        }

        sync_gallery_parent_ids();
        if (public_path_schema_ready()) {
            refresh_gallery_public_paths();
        }
        foreach ($createdGalleryIds as $createdGalleryId) {
            $scannedImages += scan_gallery_images($createdGalleryId);
        }
    } catch (Throwable $exception) {
        if ($createdRootIds) {
            try {
                delete_gallery_subtrees($createdRootIds);
            } catch (Throwable) {
                // Continue with raw-folder cleanup below. The original copy error remains authoritative.
            }
        }
        foreach (array_reverse($rawCopiedRoots) as $rawCopiedRoot) {
            if (!is_dir($rawCopiedRoot)) {
                continue;
            }
            try {
                delete_directory_tree($rawCopiedRoot, galleries_root());
            } catch (Throwable) {
                // Preserve the original exception. A leftover copied folder remains discoverable for maintenance.
            }
        }
        throw new RuntimeException('Gallery copy failed: ' . $exception->getMessage(), 0, $exception);
    }

    return [
        'requested' => count($galleryIds),
        'copied_roots' => count($createdRootIds),
        'copied_rows' => count($createdGalleryIds),
        'scanned_images' => $scannedImages,
        'created_gallery_ids' => $createdGalleryIds,
    ];
}
