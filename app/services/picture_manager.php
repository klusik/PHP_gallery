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
 *   - Validate public-view picture selections before mutation
 *   - Reuse existing gallery image movement logic where possible
 *   - Copy selected images into newly created galleries without removing the source files
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
 *   2026-05-19
 */

declare(strict_types=1);

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
 * @return array{requested:int,copied:int,skipped:int,originals_copied:int,derivatives_copied:int,failures:array<int,string>,skipped_existing:array<int,string>,created_image_ids:array<int,int>,destination_cover_image_id:int|null}
 */
function copy_gallery_images(int $sourceGalleryId, int $destinationGalleryId, array $imageIds): array
{
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

    // $createdImageIds stores a source-image-id to destination-image-id lookup for tag copying and JSON responses.
    $createdImageIds = [];
    // $destinationSortOrders stores append-style order values assigned in the destination gallery.
    $destinationSortOrders = picture_manager_destination_copy_sort_orders($destinationGalleryId, $copyableImages);
    // $pdo stores the active database connection used for row copies and tag copies.
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($copyableImages as $image) {
            // $sourceImageId stores the row being cloned.
            $sourceImageId = (int) $image['id'];
            // $row stores an INSERT-ready image row for the destination gallery.
            $row = picture_manager_image_copy_row($image, $destinationGalleryId, $destinationSortOrders[$sourceImageId] ?? next_gallery_image_sort_order($destinationGalleryId));
            // $columns stores the column names written to the image copy row.
            $columns = array_keys($row);
            // $placeholders stores a placeholder for each INSERT value.
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            // $columnSql stores backtick-quoted column names.
            $columnSql = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
            // $stmt stores the insert command for one copied image row.
            $stmt = $pdo->prepare('INSERT INTO images (' . $columnSql . ') VALUES (' . $placeholders . ')');
            $stmt->execute(array_values($row));
            $createdImageIds[$sourceImageId] = (int) $pdo->lastInsertId();
        }

        if (db_table_exists('image_tags') && $createdImageIds) {
            // $tagStmt copies tag assignments without copying votes or visitor interaction data.
            $tagStmt = $pdo->prepare('INSERT IGNORE INTO image_tags (image_id, tag_id) SELECT ?, tag_id FROM image_tags WHERE image_id = ?');
            foreach ($createdImageIds as $sourceImageId => $destinationImageId) {
                $tagStmt->execute([(int) $destinationImageId, (int) $sourceImageId]);
            }
        }

        // $destinationCoverImageId stores the title picture after copied images exist in the destination.
        $destinationCoverImageId = gallery_cover_id_after_destination_move($destinationGalleryId);
        // $coverStmt updates only the destination gallery title-picture field.
        $coverStmt = $pdo->prepare('UPDATE galleries SET cover_image_id = ?, updated_at = ? WHERE id = ?');
        $coverStmt->execute([$destinationCoverImageId, now_sql(), $destinationGalleryId]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
 * @return void
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
    // $stmt stores the current destination tail value.
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM images WHERE gallery_id = ?');
    $stmt->execute([$destinationGalleryId]);
    // $nextSortOrder stores the first appended order number.
    $nextSortOrder = (int) $stmt->fetchColumn() + 10;
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

    // $rows stores SHOW COLUMNS metadata returned by the active database.
    $rows = db()->query('SHOW COLUMNS FROM images')->fetchAll();
    $columns = [];
    foreach ($rows as $row) {
        // $field stores one database column name.
        $field = (string) ($row['Field'] ?? '');
        if ($field !== '') {
            $columns[] = $field;
        }
    }
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
