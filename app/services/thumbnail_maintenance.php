<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/thumbnail_maintenance.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Reports and maintains thumbnail inventory state.
 *
 * Responsibilities:
 *   - Keep behavior compatible with the previous combined implementation
 *   - Expose focused functions for one admin or thumbnail responsibility
 *   - Avoid coupling unrelated workflows into one large source file
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
 *   2026-05-12
 */

declare(strict_types=1);

namespace Gallery\Services;

use FilesystemIterator;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use function Gallery\Core\db;
use function Gallery\Core\now_sql;
use function Gallery\Core\path_inside;

const THUMBNAIL_MAINTENANCE_LAST_CHECK_SETTING = 'thumbnail_maintenance_last_check_report';

/**
 * Return maintenance status for a limited set of thumbnail sizes.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param array $sizes Sizes value.
 * @param bool $mutate Mutate value.
 * @return array<string mixed>.
 */
function thumbnail_maintenance_status_for_sizes(array $image, array $gallery, array $sizes, bool $mutate = true): array
{
    // $sourcePath stores the original image path inspected before any decoding is attempted.
    $sourcePath = image_abs_path($image, $gallery);
    if (!is_file($sourcePath)) {
        return ['required' => 0, 'missing' => 0, 'webp_skipped' => 0, 'target_formats' => [], 'thumbnail_policy' => null];
    }

    // $sourceMtime stores the modification time that generated variants must match.
    $sourceMtime = filemtime($sourcePath) ?: 0;
    // $mime stores the source MIME value used for format selection.
    $mime = image_source_mime_for_derivatives($sourcePath, $image);
    // $formats stores the formats this installation can keep current for this source file.
    $formats = thumbnail_target_formats_for_source($sourcePath, $mime);
    // $thumbnailPolicy stores the exact source-specific policy for warmup diagnostics.
    $thumbnailPolicy = function_exists('Gallery\\Services\\thumbnail_generation_policy_summary') ? thumbnail_generation_policy_summary($sourcePath, $mime, $sizes) : null;
    // $webpSkipped stores intentionally missing WebP variants when metadata preservation is not available.
    $webpSkipped = thumbnail_intentionally_skipped_webp_count($sourcePath, $mime);
    // $sourceGeometry stores dimensions used to detect stale square-canvas thumbnail artifacts.
    $sourceGeometry = function_exists('Gallery\\Services\\thumbnail_source_geometry_dimensions') ? thumbnail_source_geometry_dimensions($sourcePath, $image) : null;
    // $invalidGeometryDeleted stores cache files scheduled for replacement because they did not preserve the source ratio.
    $invalidGeometryDeleted = 0;
    // $invalidGeometryDetected stores invalid cache files found even when the caller requested a dry check.
    $invalidGeometryDetected = 0;
    // $invalidGeometryFiles stores stale cache filenames scheduled for detailed warmup repair logs.
    $invalidGeometryFiles = [];
    // $sizes stores only supported sizes.
    $sizes = array_values(array_unique(array_filter(array_map('intval', $sizes), static fn (int $size): bool => in_array($size, thumbnail_sizes(), true))));
    // $required stores the number of expected variant files.
    $required = 0;
    // $missing stores the number of expected variant files missing or stale.
    $missing = 0;
    // $metadataRowsWritten counts durable thumbnail rows refreshed by this scan.
    $metadataRowsWritten = 0;
    // $metadataSourceSyncs counts master image source metadata refreshes.
    $metadataSourceSyncs = 0;

    foreach ($sizes as $size) {
        foreach ($formats as $format) {
            $required++;
            try {
                // $targetPath stores one generated thumbnail path to inspect.
                $targetPath = thumbnail_abs_path($image, $gallery, $size, $format);
            } catch (RuntimeException) {
                $missing++;
                continue;
            }
            if (!is_file($targetPath) || filemtime($targetPath) < $sourceMtime) {
                $missing++;
                continue;
            }
            if (is_array($sourceGeometry) && function_exists('Gallery\\Services\\thumbnail_file_geometry_status')) {
                // $geometryStatus stores whether a fresh thumbnail cache file has valid dimensions.
                $geometryStatus = thumbnail_file_geometry_status($targetPath, (int) $sourceGeometry['width'], (int) $sourceGeometry['height'], (int) $size);
                if (empty($geometryStatus['valid'])) {
                    $invalidGeometryDetected++;
                    $invalidGeometryFiles[] = basename($targetPath);
                    if ($mutate) {
                        $invalidGeometryDeleted++;
                        thumbnail_delete_invalid_geometry_file($targetPath);
                        if (function_exists('Gallery\\Services\\thumbnail_metadata_delete_variant')) {
                            thumbnail_metadata_delete_variant($image, (int) $size, $format);
                        }
                    }
                    $missing++;
                    continue;
                }
            }
            if ($mutate && function_exists('Gallery\\Services\\thumbnail_metadata_record_file')) {
                $metadataResult = thumbnail_metadata_record_file($image, $gallery, (int) $size, $format, $targetPath, $sourcePath, false);
                if (!empty($metadataResult['metadata_written'])) {
                    $metadataRowsWritten++;
                }
                if (!empty($metadataResult['source_synced'])) {
                    $metadataSourceSyncs++;
                }
            }
        }
    }

    return [
        'required' => $required,
        'missing' => $missing,
        'webp_skipped' => $webpSkipped,
        'target_formats' => $formats,
        'thumbnail_policy' => $thumbnailPolicy,
        'invalid_geometry_deleted' => $invalidGeometryDeleted,
        'invalid_geometry_detected' => $invalidGeometryDetected,
        'invalid_geometry_files' => $invalidGeometryFiles,
        'metadata_rows_written' => $metadataRowsWritten,
        'metadata_source_syncs' => $metadataSourceSyncs,
    ];
}

/**
 * Handles thumbnail maintenance status logic for the gallery application.
 *
 * @param mixed $image Input used by this operation.
 * @param mixed $gallery Input used by this operation.
 * @param bool $mutate Mutate value.
 * @return mixed Result produced by this operation.
 */
function thumbnail_maintenance_status(array $image, array $gallery, bool $mutate = true): array
{
    // $status stores the shared thumbnail variant status used by admin and warmup repair.
    $status = thumbnail_maintenance_status_for_sizes($image, $gallery, thumbnail_sizes(), $mutate);

    if (image_uses_dng_display_derivatives($image) && dng_derivative_generation_supported()) {
        try {
            // $sourcePath stores the original image path inspected before DNG master checks.
            $sourcePath = image_abs_path($image, $gallery);
            // $sourceMtime stores the original DNG timestamp used to detect stale generated files.
            $sourceMtime = is_file($sourcePath) ? (filemtime($sourcePath) ?: 0) : 0;
            // $masterPath stores the generated full-size WebP display master.
            $masterPath = dng_display_master_abs_path($image, $gallery, false);
            $status['required'] = (int) ($status['required'] ?? 0) + 1;
            if (!is_file($masterPath) || ($sourceMtime > 0 && filemtime($masterPath) < $sourceMtime)) {
                $status['missing'] = (int) ($status['missing'] ?? 0) + 1;
            }
        } catch (RuntimeException) {
            $status['required'] = (int) ($status['required'] ?? 0) + 1;
            $status['missing'] = (int) ($status['missing'] ?? 0) + 1;
        }
    }

    return $status;
}

/**
 * Handles thumbnail maintenance summary logic for the gallery application.
 *
 * @param mixed $galleryIds Input used by this operation.
 * @param mixed $maxImagesToScan Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_maintenance_summary(?array $galleryIds = null, int $maxImagesToScan = 1000): array
{
    // Variable $params stores this steps working value.
    $params = [];
    // $where stores an intermediate value used by the surrounding gallery workflow.
    $where = "i.relative_path NOT LIKE '%/%'";
    if ($galleryIds !== null) {
        // $galleryIds stores an intermediate value used by the surrounding gallery workflow.
        $galleryIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
        if (!$galleryIds) {
            return ['images_scanned' => 0, 'images_with_missing' => 0, 'missing_variants' => 0, 'webp_skipped' => 0, 'limited' => false, 'inventory_fingerprint' => thumbnail_inventory_fingerprint($galleryIds)];
        }
        $where .= ' AND i.gallery_id IN (' . implode(',', array_fill(0, count($galleryIds), '?')) . ')';
        // $params stores an intermediate value used by the surrounding gallery workflow.
        $params = $galleryIds;
    }
    // $limitSql stores an optional scan cap. A non-positive value means scan all matching images.
    $limitSql = '';
    if ($maxImagesToScan > 0) {
        $limit = max(1, $maxImagesToScan + 1);
        $limitSql = ' LIMIT ' . $limit;
    }
    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = db()->prepare("SELECT i.*, g.folder_path AS gallery_folder_path FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE $where ORDER BY g.folder_path, i.sort_order, i.filename$limitSql");
    $stmt->execute($params);
    // $rows stores an intermediate value used by the surrounding gallery workflow.
    $rows = $stmt->fetchAll();
    // $limited stores an intermediate value used by the surrounding gallery workflow.
    $limited = $maxImagesToScan > 0 && count($rows) > $maxImagesToScan;
    if ($limited) {
        array_pop($rows);
    }
    // Variable $galleryCache stores this steps working value.
    $galleryCache = [];
    // Variable $imagesWithMissing stores this steps working value.
    $imagesWithMissing = 0;
    // Variable $missingVariants stores this steps working value.
    $missingVariants = 0;
    // Variable $webpSkipped stores this steps working value.
    $webpSkipped = 0;
    foreach ($rows as $image) {
        // $galleryId stores an intermediate value used by the surrounding gallery workflow.
        $galleryId = (int) $image['gallery_id'];
        if (!isset($galleryCache[$galleryId])) {
            $galleryCache[$galleryId] = find_gallery($galleryId);
        }
        if (!$galleryCache[$galleryId]) {
            continue;
        }
        // $status stores an intermediate value used by the surrounding gallery workflow.
        $status = thumbnail_maintenance_status($image, $galleryCache[$galleryId]);
        if ($status['missing'] > 0) {
            $imagesWithMissing++;
            $missingVariants += $status['missing'];
        }
        $webpSkipped += $status['webp_skipped'];
    }
    return [
        'images_scanned' => count($rows),
        'images_with_missing' => $imagesWithMissing,
        'missing_variants' => $missingVariants,
        'webp_skipped' => $webpSkipped,
        'limited' => $limited,
        'inventory_fingerprint' => thumbnail_inventory_fingerprint($galleryIds),
    ];
}

/**
 * Return image IDs that need thumbnail regeneration for the current maintenance warning.
 *
 * This mirrors thumbnail_maintenance_summary() but returns only the images with
 * missing or stale thumbnail files so the admin can rebuild the affected set
 * without scanning or processing every image in the library.
 *
 * @param ?array $galleryIds Gallery ids value.
 * @param int $maxImagesToScan Max images to scan value.
 * @return array<int int>.
 */
function thumbnail_maintenance_image_ids(?array $galleryIds = null, int $maxImagesToScan = 1000): array
{
    // Variable $params stores this steps working value.
    $params = [];
    // $where stores an intermediate value used by the surrounding gallery workflow.
    $where = "i.relative_path NOT LIKE '%/%'";
    if ($galleryIds !== null) {
        // $galleryIds stores this steps working value.
        $galleryIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
        if (!$galleryIds) {
            return [];
        }
        $where .= ' AND i.gallery_id IN (' . implode(',', array_fill(0, count($galleryIds), '?')) . ')';
        // $params stores an intermediate value used by the surrounding gallery workflow.
        $params = $galleryIds;
    }

    // $limitSql stores an optional scan cap. A non-positive value means scan all matching images.
    $limitSql = '';
    if ($maxImagesToScan > 0) {
        $limit = max(1, $maxImagesToScan + 1);
        $limitSql = ' LIMIT ' . $limit;
    }
    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = db()->prepare("SELECT i.*, g.folder_path AS gallery_folder_path FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE $where ORDER BY g.folder_path, i.sort_order, i.filename$limitSql");
    $stmt->execute($params);
    // $rows stores an intermediate value used by the surrounding gallery workflow.
    $rows = $stmt->fetchAll();
    if ($maxImagesToScan > 0 && count($rows) > $maxImagesToScan) {
        array_pop($rows);
    }

    // Variable $galleryCache stores this steps working value.
    $galleryCache = [];
    // Variable $imageIds stores this steps working value.
    $imageIds = [];
    foreach ($rows as $image) {
        // $galleryId stores an intermediate value used by the surrounding gallery workflow.
        $galleryId = (int) $image['gallery_id'];
        if (!isset($galleryCache[$galleryId])) {
            $galleryCache[$galleryId] = find_gallery($galleryId);
        }
        if (!$galleryCache[$galleryId]) {
            continue;
        }
        // $status stores an intermediate value used by the surrounding gallery workflow.
        $status = thumbnail_maintenance_status($image, $galleryCache[$galleryId]);
        if (($status['missing'] ?? 0) > 0) {
            $imageIds[] = (int) $image['id'];
        }
    }

    return array_values(array_unique($imageIds));
}

/**
 * Build a dry-run thumbnail maintenance report grouped by affected gallery.
 *
 * This intentionally uses the same status logic as scheduled maintenance, but
 * asks the checker not to generate, delete, or record files. A non-positive
 * scan limit means every imported direct image is checked.
 *
 * @param ?array $galleryIds Gallery ids value.
 * @param int $maxImagesToScan Max images to scan value.
 * @return array<string mixed>.
 */
function thumbnail_maintenance_check_report(?array $galleryIds = null, int $maxImagesToScan = 0): array
{
    // Variable $params stores this steps working value.
    $params = [];
    // $where stores the same top-level image condition used by generation and nightly maintenance.
    $where = "i.relative_path NOT LIKE '%/%'";
    if ($galleryIds !== null) {
        // $galleryIds stores a normalized optional gallery scope.
        $galleryIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
        if (!$galleryIds) {
            return thumbnail_maintenance_empty_check_report($galleryIds);
        }
        $where .= ' AND i.gallery_id IN (' . implode(',', array_fill(0, count($galleryIds), '?')) . ')';
        $params = $galleryIds;
    }

    // $limitSql stores an optional scan cap. A non-positive value means scan all matching images.
    $limitSql = '';
    if ($maxImagesToScan > 0) {
        $limit = max(1, $maxImagesToScan + 1);
        $limitSql = ' LIMIT ' . $limit;
    }

    // $stmt stores the dry inventory query. Gallery columns are included for the grouped report.
    $stmt = db()->prepare("SELECT i.*, g.title AS gallery_title, g.folder_path AS gallery_folder_path FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE $where ORDER BY g.folder_path, i.sort_order, i.filename$limitSql");
    $stmt->execute($params);
    // $rows stores the image rows that will be inspected on disk.
    $rows = $stmt->fetchAll();
    // $limited stores whether the report intentionally stopped before every matching image.
    $limited = $maxImagesToScan > 0 && count($rows) > $maxImagesToScan;
    if ($limited) {
        array_pop($rows);
    }

    return thumbnail_maintenance_check_report_from_rows($rows, $galleryIds, $limited);
}

/**
 * Build a dry thumbnail maintenance report for a known image ID list.
 *
 * Targeted server-side repair uses this helper after generation so it can report
 * whether the selected images are fixed without rescanning the whole library.
 *
 * @param array $imageIds Image IDs to inspect.
 * @return array<string mixed> Structured report for the selected images.
 */
function thumbnail_maintenance_check_report_for_image_ids(array $imageIds): array
{
    $imageIds = array_values(array_unique(array_filter(array_map('intval', $imageIds), static fn (int $id): bool => $id > 0)));
    if (!$imageIds) {
        return thumbnail_maintenance_empty_check_report(null);
    }

    // $placeholders stores bound parameters for the selected image list.
    $placeholders = implode(',', array_fill(0, count($imageIds), '?'));
    // $stmt stores selected direct image rows with parent gallery data for grouping.
    $stmt = db()->prepare("SELECT i.*, g.title AS gallery_title, g.folder_path AS gallery_folder_path FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE i.id IN ($placeholders) AND i.relative_path NOT LIKE '%/%' ORDER BY g.folder_path, i.sort_order, i.filename");
    $stmt->execute($imageIds);
    // $rows stores only the image rows selected by the completed repair job.
    $rows = $stmt->fetchAll();

    return thumbnail_maintenance_check_report_from_rows($rows, null, false);
}

/**
 * Build one dry-run thumbnail report from already selected image rows.
 *
 * @param array $rows Rows to process.
 * @param ?array $galleryIds Gallery ids value.
 * @param bool $limited Limited value.
 * @param bool $finalize Finalize value.
 * @return array<string mixed>.
 */
function thumbnail_maintenance_check_report_from_rows(array $rows, ?array $galleryIds = null, bool $limited = false, bool $finalize = true): array
{
    // Variable $galleryCache stores galleries only once per scan.
    $galleryCache = [];
    // Variable $imagesWithMissing stores this steps working value.
    $imagesWithMissing = 0;
    // Variable $missingVariants stores this steps working value.
    $missingVariants = 0;
    // Variable $webpSkipped stores this steps working value.
    $webpSkipped = 0;
    // $invalidGeometryDetected stores wrong-ratio variants found without deleting them.
    $invalidGeometryDetected = 0;
    // $affectedGalleries stores grouped dry-run findings by gallery id.
    $affectedGalleries = [];
    // $affectedImageIds stores exact image ids from this dry check for later targeted repair.
    $affectedImageIds = [];

    foreach ($rows as $image) {
        // $galleryId stores the current image gallery identifier.
        $galleryId = (int) ($image['gallery_id'] ?? 0);
        if (!array_key_exists($galleryId, $galleryCache)) {
            $galleryCache[$galleryId] = $galleryId > 0 ? find_gallery($galleryId) : null;
        }
        if (!$galleryCache[$galleryId]) {
            continue;
        }

        // $status stores missing/stale/invalid variant counts without changing files.
        $status = thumbnail_maintenance_status($image, $galleryCache[$galleryId], false);
        $missing = max(0, (int) ($status['missing'] ?? 0));
        $webpSkipped += (int) ($status['webp_skipped'] ?? 0);
        $invalidGeometryDetected += (int) ($status['invalid_geometry_detected'] ?? 0);
        if ($missing <= 0) {
            continue;
        }

        $imagesWithMissing++;
        $missingVariants += $missing;
        $imageId = (int) ($image['id'] ?? 0);
        if ($imageId > 0) {
            $affectedImageIds[] = $imageId;
        }
        if (!isset($affectedGalleries[$galleryId])) {
            $affectedGalleries[$galleryId] = [
                'id' => $galleryId,
                'title' => (string) ($galleryCache[$galleryId]['title'] ?? ($image['gallery_title'] ?? ('#' . $galleryId))),
                'folder_path' => (string) ($galleryCache[$galleryId]['folder_path'] ?? ($image['gallery_folder_path'] ?? '')),
                'images_with_missing' => 0,
                'missing_variants' => 0,
                'sample_images' => [],
            ];
        }
        $affectedGalleries[$galleryId]['images_with_missing'] = (int) $affectedGalleries[$galleryId]['images_with_missing'] + 1;
        $affectedGalleries[$galleryId]['missing_variants'] = (int) $affectedGalleries[$galleryId]['missing_variants'] + $missing;
        if (count($affectedGalleries[$galleryId]['sample_images']) < 5) {
            $affectedGalleries[$galleryId]['sample_images'][] = [
                'id' => (int) ($image['id'] ?? 0),
                'filename' => (string) ($image['filename'] ?? $image['relative_path'] ?? ''),
                'missing_variants' => $missing,
            ];
        }
    }

    $report = [
        'checked_at' => now_sql(),
        'images_scanned' => count($rows),
        'images_with_missing' => $imagesWithMissing,
        'missing_variants' => $missingVariants,
        'webp_skipped' => $webpSkipped,
        'invalid_geometry_detected' => $invalidGeometryDetected,
        'limited' => $limited,
        'affected_gallery_count' => count($affectedGalleries),
        'affected_galleries' => array_values($affectedGalleries),
        'affected_galleries_truncated' => false,
        'affected_image_ids' => array_values(array_unique($affectedImageIds)),
        'affected_image_ids_truncated' => false,
        'inventory_fingerprint' => thumbnail_inventory_fingerprint($galleryIds),
    ];

    return $finalize ? thumbnail_maintenance_finalize_check_report($report) : $report;
}

/**
 * Build one dry-run thumbnail check batch.
 *
 * @param ?array $galleryIds Gallery ids value.
 * @param int $offset Starting offset.
 * @param int $batchSize Batch size value.
 * @return array<string mixed>.
 */
function thumbnail_maintenance_check_batch(?array $galleryIds = null, int $offset = 0, int $batchSize = 150): array
{
    // Variable $params stores this steps working value.
    $params = [];
    // $where stores the same top-level image condition used by generation and nightly maintenance.
    $where = "i.relative_path NOT LIKE '%/%'";
    if ($galleryIds !== null) {
        // $galleryIds stores a normalized optional gallery scope.
        $galleryIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
        if (!$galleryIds) {
            $report = thumbnail_maintenance_empty_check_report($galleryIds);
            $report['total'] = 0;
            $report['processed'] = 0;
            $report['next_offset'] = 0;
            $report['done'] = true;
            return $report;
        }
        $where .= ' AND i.gallery_id IN (' . implode(',', array_fill(0, count($galleryIds), '?')) . ')';
        $params = $galleryIds;
    }

    $offset = max(0, $offset);
    $batchSize = max(1, min(500, $batchSize));

    // $countStmt stores the total matching image count for the progress bar.
    $countStmt = db()->prepare("SELECT COUNT(*) FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE $where");
    $countStmt->execute($params);
    $total = max(0, (int) $countStmt->fetchColumn());

    // $stmt stores the dry inventory batch query. Limit and offset are sanitized integers.
    $stmt = db()->prepare("SELECT i.*, g.title AS gallery_title, g.folder_path AS gallery_folder_path FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE $where ORDER BY g.folder_path, i.sort_order, i.filename LIMIT $batchSize OFFSET $offset");
    $stmt->execute($params);
    // $rows stores the image rows that will be inspected on disk.
    $rows = $stmt->fetchAll();

    $report = thumbnail_maintenance_check_report_from_rows($rows, $galleryIds, false, false);
    $processed = min($total, $offset + count($rows));
    $report['total'] = $total;
    $report['processed'] = $processed;
    $report['next_offset'] = $processed;
    $report['done'] = $processed >= $total;

    return $report;
}

/**
 * Merge two dry-run thumbnail check reports.
 *
 * @param array $base Base value.
 * @param array $addition Addition value.
 * @return array<string mixed>.
 */
function thumbnail_maintenance_merge_check_reports(array $base, array $addition): array
{
    $base['checked_at'] = (string) ($base['checked_at'] ?? now_sql());
    $base['images_scanned'] = (int) ($base['images_scanned'] ?? 0) + (int) ($addition['images_scanned'] ?? 0);
    $base['images_with_missing'] = (int) ($base['images_with_missing'] ?? 0) + (int) ($addition['images_with_missing'] ?? 0);
    $base['missing_variants'] = (int) ($base['missing_variants'] ?? 0) + (int) ($addition['missing_variants'] ?? 0);
    $base['webp_skipped'] = (int) ($base['webp_skipped'] ?? 0) + (int) ($addition['webp_skipped'] ?? 0);
    $base['invalid_geometry_detected'] = (int) ($base['invalid_geometry_detected'] ?? 0) + (int) ($addition['invalid_geometry_detected'] ?? 0);
    $base['limited'] = !empty($base['limited']) || !empty($addition['limited']);
    $base['inventory_fingerprint'] = (string) ($base['inventory_fingerprint'] ?? ($addition['inventory_fingerprint'] ?? thumbnail_inventory_fingerprint(null)));

    // $galleriesById stores affected galleries keyed by id so repeated batches accumulate cleanly.
    $galleriesById = [];
    foreach ([(array) ($base['affected_galleries'] ?? []), (array) ($addition['affected_galleries'] ?? [])] as $galleryRows) {
        foreach ($galleryRows as $galleryRow) {
            if (!is_array($galleryRow)) {
                continue;
            }
            $galleryId = (int) ($galleryRow['id'] ?? 0);
            if ($galleryId <= 0) {
                continue;
            }
            if (!isset($galleriesById[$galleryId])) {
                $galleriesById[$galleryId] = [
                    'id' => $galleryId,
                    'title' => (string) ($galleryRow['title'] ?? ('#' . $galleryId)),
                    'folder_path' => (string) ($galleryRow['folder_path'] ?? ''),
                    'images_with_missing' => 0,
                    'missing_variants' => 0,
                    'sample_images' => [],
                ];
            }
            $galleriesById[$galleryId]['images_with_missing'] = (int) $galleriesById[$galleryId]['images_with_missing'] + (int) ($galleryRow['images_with_missing'] ?? 0);
            $galleriesById[$galleryId]['missing_variants'] = (int) $galleriesById[$galleryId]['missing_variants'] + (int) ($galleryRow['missing_variants'] ?? 0);
            foreach ((array) ($galleryRow['sample_images'] ?? []) as $sampleImage) {
                if (count($galleriesById[$galleryId]['sample_images']) >= 5 || !is_array($sampleImage)) {
                    continue;
                }
                $galleriesById[$galleryId]['sample_images'][] = [
                    'id' => (int) ($sampleImage['id'] ?? 0),
                    'filename' => (string) ($sampleImage['filename'] ?? ''),
                    'missing_variants' => (int) ($sampleImage['missing_variants'] ?? 0),
                ];
            }
        }
    }

    $base['affected_galleries'] = array_values($galleriesById);
    $base['affected_gallery_count'] = count($galleriesById);
    $base['affected_galleries_truncated'] = false;

    // $affectedImageIds stores an exact repair list while keeping app_settings payloads bounded.
    $affectedImageIds = array_values(array_unique(array_filter(array_map('intval', array_merge(
        (array) ($base['affected_image_ids'] ?? []),
        (array) ($addition['affected_image_ids'] ?? [])
    )), static fn (int $id): bool => $id > 0)));
    $affectedImageLimit = 2000;
    $base['affected_image_ids_truncated'] = !empty($base['affected_image_ids_truncated'])
        || !empty($addition['affected_image_ids_truncated'])
        || count($affectedImageIds) > $affectedImageLimit;
    $base['affected_image_ids'] = array_slice($affectedImageIds, 0, $affectedImageLimit);

    return $base;
}

/**
 * Normalize a dry-run thumbnail check report before storing or displaying it.
 *
 * @param array $report Report value.
 * @return array<string mixed>.
 */
function thumbnail_maintenance_finalize_check_report(array $report): array
{
    $affectedGalleryRows = array_values(array_filter((array) ($report['affected_galleries'] ?? []), static fn ($row): bool => is_array($row)));
    $affectedGalleryCount = count($affectedGalleryRows);
    $storedGalleryLimit = 50;
    $affectedImageIds = array_values(array_unique(array_filter(array_map('intval', (array) ($report['affected_image_ids'] ?? [])), static fn (int $id): bool => $id > 0)));
    $storedImageLimit = 2000;

    $report['affected_gallery_count'] = $affectedGalleryCount;
    $report['affected_galleries'] = array_slice($affectedGalleryRows, 0, $storedGalleryLimit);
    $report['affected_galleries_truncated'] = $affectedGalleryCount > $storedGalleryLimit;
    $report['affected_image_ids'] = array_slice($affectedImageIds, 0, $storedImageLimit);
    $report['affected_image_ids_truncated'] = !empty($report['affected_image_ids_truncated']) || count($affectedImageIds) > $storedImageLimit;

    return $report;
}

/**
 * Return an empty dry thumbnail check report.
 *
 * @param ?array $galleryIds Gallery ids value.
 * @return array<string mixed>.
 */
function thumbnail_maintenance_empty_check_report(?array $galleryIds = null): array
{
    return [
        'checked_at' => now_sql(),
        'images_scanned' => 0,
        'images_with_missing' => 0,
        'missing_variants' => 0,
        'webp_skipped' => 0,
        'invalid_geometry_detected' => 0,
        'limited' => false,
        'affected_gallery_count' => 0,
        'affected_galleries' => [],
        'affected_galleries_truncated' => false,
        'affected_image_ids' => [],
        'affected_image_ids_truncated' => false,
        'inventory_fingerprint' => thumbnail_inventory_fingerprint($galleryIds),
    ];
}

/**
 * Persist the latest full dry thumbnail check for the Admin media card.
 *
 * @param array $report Report value.
 */
function thumbnail_maintenance_store_last_check(array $report): void
{
    $report = thumbnail_maintenance_finalize_check_report($report);
    $json = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (is_string($json)) {
        set_app_setting(THUMBNAIL_MAINTENANCE_LAST_CHECK_SETTING, $json);
    }
}

/**
 * Return the latest full dry thumbnail check when it still matches the image inventory.
 *
 * @return array<string mixed>.
 */
function thumbnail_maintenance_last_check(): array
{
    $json = trim((string) app_setting(THUMBNAIL_MAINTENANCE_LAST_CHECK_SETTING, ''));
    if ($json === '') {
        return [];
    }

    $report = json_decode($json, true);
    if (!is_array($report)) {
        delete_app_settings([THUMBNAIL_MAINTENANCE_LAST_CHECK_SETTING]);
        return [];
    }

    $fingerprint = (string) ($report['inventory_fingerprint'] ?? '');
    if ($fingerprint === '' || !hash_equals(thumbnail_inventory_fingerprint(null), $fingerprint)) {
        delete_app_settings([THUMBNAIL_MAINTENANCE_LAST_CHECK_SETTING]);
        return [];
    }

    return $report;
}

/**
 * Return exact image ids from the latest full dry check when they are safe to reuse.
 *
 * @param ?array $galleryIds Optional gallery scope for targeted repair.
 * @return array<int int>.
 */
function thumbnail_maintenance_last_check_image_ids(?array $galleryIds = null): array
{
    $report = thumbnail_maintenance_last_check();
    if (!$report || !empty($report['affected_image_ids_truncated'])) {
        return [];
    }

    // $imageIds stores the exact dry-check repair list saved with the current inventory fingerprint.
    $imageIds = array_values(array_unique(array_filter(array_map('intval', (array) ($report['affected_image_ids'] ?? [])), static fn (int $id): bool => $id > 0)));
    if (!$imageIds) {
        return [];
    }

    if ($galleryIds === null) {
        return $imageIds;
    }

    // $galleryIds stores a normalized optional subset for future scoped maintenance controls.
    $galleryIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
    if (!$galleryIds) {
        return [];
    }

    // $filteredIds stores only saved findings that still belong to the requested gallery subset.
    $filteredIds = [];
    foreach ($imageIds as $imageId) {
        $image = find_image($imageId);
        if ($image && in_array((int) ($image['gallery_id'] ?? 0), $galleryIds, true)) {
            $filteredIds[] = $imageId;
        }
    }

    return $filteredIds;
}

/**
 * Return compact diagnostic data for thumbnail repair logs.
 *
 * @param array $imageIds Image ids value.
 * @return array<int array<string, mixed>>.
 */
function thumbnail_maintenance_debug_image_statuses(array $imageIds): array
{
    // $imageIds stores a short unique list so admin log context stays readable.
    $imageIds = array_slice(array_values(array_unique(array_filter(array_map('intval', $imageIds), static fn (int $id): bool => $id > 0))), 0, 20);
    // $rows stores the diagnostic entries included in the admin log.
    $rows = [];
    foreach ($imageIds as $imageId) {
        // $image stores the database row for this diagnostic entry.
        $image = find_image($imageId);
        if (!$image) {
            $rows[] = ['image_id' => $imageId, 'found' => false];
            continue;
        }

        // $gallery stores the parent gallery needed to resolve source and thumbnail paths.
        $gallery = find_gallery((int) $image['gallery_id']);
        if (!$gallery) {
            $rows[] = ['image_id' => $imageId, 'found' => true, 'gallery_found' => false];
            continue;
        }

        // $sourcePath stores the absolute source path for filesystem checks.
        $sourcePath = image_abs_path($image, $gallery);
        // $mime stores the detected MIME type used for thumbnail format decisions.
        $mime = is_file($sourcePath) ? image_source_mime_for_derivatives($sourcePath, $image) : '';
        // $status stores the same maintenance status used by the dashboard warning.
        $status = thumbnail_maintenance_status($image, $gallery);

        $rows[] = [
            'image_id' => $imageId,
            'found' => true,
            'gallery_found' => true,
            'gallery_id' => (int) $image['gallery_id'],
            'filename' => (string) ($image['filename'] ?? ''),
            'relative_path' => (string) ($image['relative_path'] ?? ''),
            'source_exists' => is_file($sourcePath),
            'mime' => $mime,
            'has_exif' => $mime !== '' && image_source_has_exif($sourcePath, $mime),
            'is_dng' => image_uses_dng_display_derivatives($image),
            'imagewebp_available' => function_exists('imagewebp'),
            'imagick_available' => class_exists('Imagick'),
            'imagick_webp_available' => thumbnail_imagick_webp_available(),
            'dng_conversion_supported' => dng_derivative_generation_supported(),
            'target_formats' => $mime !== '' ? thumbnail_target_formats_for_source($sourcePath, $mime) : [],
            'dng_master_exists' => image_uses_dng_display_derivatives($image) ? is_file(dng_display_master_abs_path($image, $gallery, false)) : null,
            'status' => $status,
        ];
    }

    return $rows;
}

/**
 * Return a short-lived thumbnail maintenance summary for admin dashboards.
 *
 * The expensive part of thumbnail maintenance is checking source files and
 * generated variants on disk. The image inventory fingerprint keeps this cache
 * tied to the current set of indexed direct images, while explicit cache
 * generation invalidation makes thumbnail creation and deletion visible on the
 * next admin load.
 *
 * @param ?array $galleryIds Gallery ids value.
 * @param int $maxImagesToScan Max images to scan value.
 * @param int $ttlSeconds Ttl seconds value.
 * @return array Structured result data for the caller.
 */
function cached_thumbnail_maintenance_summary(?array $galleryIds = null, int $maxImagesToScan = 1000, int $ttlSeconds = 180): array
{
    // $galleryIds stores the normalized optional gallery scope used by both cache keys and summary queries.
    $galleryIds = $galleryIds === null ? null : array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
    if ($galleryIds !== null && $galleryIds === []) {
        return thumbnail_maintenance_summary([], $maxImagesToScan);
    }

    // $scopeKey stores a compact stable key for the dashboard-wide or gallery-scoped summary.
    $scopeKey = $galleryIds === null ? 'all' : implode(',', $galleryIds);
    // $cacheKey stores the DB setting that contains the cached summary payload.
    $cacheKey = 'thumbnail_maintenance_summary_' . substr(hash('sha256', $scopeKey . '|' . $maxImagesToScan), 0, 16);
    // $generation stores the invalidation marker changed after thumbnail creation or deletion.
    $generation = (string) app_setting('thumbnail_maintenance_summary_generation', '0');
    // $fingerprint stores the cheap image inventory state. It changes when images are imported.
    $fingerprint = thumbnail_inventory_fingerprint($galleryIds);
    // $cachedJson stores the previous summary payload, if any.
    $cachedJson = (string) app_setting($cacheKey, '');

    if ($cachedJson !== '') {
        // $cachedPayload stores the decoded summary cache candidate.
        $cachedPayload = json_decode($cachedJson, true);
        if (is_array($cachedPayload)
            && (string) ($cachedPayload['generation'] ?? '') === $generation
            && (string) ($cachedPayload['fingerprint'] ?? '') === $fingerprint
            && time() - (int) ($cachedPayload['created_at'] ?? 0) <= max(30, $ttlSeconds)
            && is_array($cachedPayload['summary'] ?? null)
        ) {
            $cachedPayload['summary']['inventory_fingerprint'] = $fingerprint;
            return $cachedPayload['summary'];
        }
    }

    // $summary stores the fresh filesystem-backed maintenance state.
    $summary = thumbnail_maintenance_summary($galleryIds, $maxImagesToScan);
    set_app_setting($cacheKey, json_encode([
        'created_at' => time(),
        'generation' => $generation,
        'fingerprint' => (string) ($summary['inventory_fingerprint'] ?? $fingerprint),
        'summary' => $summary,
    ], JSON_UNESCAPED_SLASHES));

    return $summary;
}

/**
 * Return a cached thumbnail maintenance summary without warming the cache.
 *
 * The admin dashboard calls this helper so the first page after login does not
 * spend seconds checking thumbnail files on disk. Explicit thumbnail maintenance
 * actions still use thumbnail_maintenance_summary() and can refresh the cache.
 *
 * @param ?array $galleryIds Gallery ids value.
 * @param int $maxImagesToScan Max images to scan value.
 * @param int $ttlSeconds Ttl seconds value.
 * @return array Structured result data for the caller.
 */
function cached_thumbnail_maintenance_summary_if_available(?array $galleryIds = null, int $maxImagesToScan = 1000, int $ttlSeconds = 180): array
{
    // $galleryIds stores the normalized optional gallery scope used by both cache keys and summary queries.
    $galleryIds = $galleryIds === null ? null : array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
    if ($galleryIds !== null && $galleryIds === []) {
        return [
            'images_scanned' => 0,
            'images_with_missing' => 0,
            'missing_variants' => 0,
            'webp_skipped' => 0,
            'limited' => false,
            'deferred' => false,
            'inventory_fingerprint' => thumbnail_inventory_fingerprint($galleryIds),
        ];
    }

    // $scopeKey stores a compact stable key for the dashboard-wide or gallery-scoped summary.
    $scopeKey = $galleryIds === null ? 'all' : implode(',', $galleryIds);
    // $cacheKey stores the DB setting that contains the cached summary payload.
    $cacheKey = 'thumbnail_maintenance_summary_' . substr(hash('sha256', $scopeKey . '|' . $maxImagesToScan), 0, 16);
    // $generation stores the invalidation marker changed after thumbnail creation or deletion.
    $generation = (string) app_setting('thumbnail_maintenance_summary_generation', '0');
    // $fingerprint stores the cheap image inventory state. It changes when images are imported.
    $fingerprint = thumbnail_inventory_fingerprint($galleryIds);
    // $cachedJson stores the previous summary payload, if any.
    $cachedJson = (string) app_setting($cacheKey, '');

    if ($cachedJson !== '') {
        // $cachedPayload stores the decoded summary cache candidate.
        $cachedPayload = json_decode($cachedJson, true);
        if (is_array($cachedPayload)
            && (string) ($cachedPayload['generation'] ?? '') === $generation
            && (string) ($cachedPayload['fingerprint'] ?? '') === $fingerprint
            && time() - (int) ($cachedPayload['created_at'] ?? 0) <= max(30, $ttlSeconds)
            && is_array($cachedPayload['summary'] ?? null)
        ) {
            $cachedPayload['summary']['inventory_fingerprint'] = $fingerprint;
            $cachedPayload['summary']['deferred'] = false;
            return $cachedPayload['summary'];
        }
    }

    return [
        'images_scanned' => 0,
        'images_with_missing' => 0,
        'missing_variants' => 0,
        'webp_skipped' => 0,
        'limited' => false,
        'deferred' => true,
        'inventory_fingerprint' => $fingerprint,
    ];
}

/**
 * Invalidate cached thumbnail maintenance summaries after cache files change.
 */
function thumbnail_maintenance_summary_cache_clear(): void
{
    set_app_setting('thumbnail_maintenance_summary_generation', sprintf('%.6F', microtime(true)));
    delete_app_settings([THUMBNAIL_MAINTENANCE_LAST_CHECK_SETTING]);
    if (function_exists('Gallery\\Services\\admin_storage_statistics_cache_clear')) {
        admin_storage_statistics_cache_clear();
    }
    if (function_exists('Gallery\\Services\\gallery_map_cache_clear_all')) {
        gallery_map_cache_clear_all();
    }
}

/**
 * Build a lightweight fingerprint of the currently indexed image inventory.
 *
 * The dismissal feature for thumbnail maintenance warnings uses this value to
 * distinguish "same warning, temporarily hidden" from "the gallery content
 * changed, show the warning again". The fingerprint only uses aggregate image
 * metadata, not filenames, paths, titles, EXIF data, IP addresses, or visitor
 * information. A newly imported image changes the count, maximum image id, or
 * newest creation timestamp and therefore invalidates the old dismissal.
 *
 * @param ?array $galleryIds Gallery ids value.
 * @return string Text result for the caller.
 */
function thumbnail_inventory_fingerprint(?array $galleryIds = null): string
{
    // $params stores bound gallery ids when the caller wants a scoped inventory check.
    $params = [];
    // $where stores the same top-level image condition used by thumbnail_maintenance_summary().
    $where = "relative_path NOT LIKE '%/%'";

    if ($galleryIds !== null) {
        $galleryIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
        if ($galleryIds === []) {
            return hash('sha256', 'empty-gallery-scope');
        }
        $where .= ' AND gallery_id IN (' . implode(',', array_fill(0, count($galleryIds), '?')) . ')';
        $params = $galleryIds;
    }

    // $stmt reads only aggregate metadata so the check stays cheap even on large galleries.
    $stmt = db()->prepare("SELECT COUNT(*) AS image_count, COALESCE(MAX(id), 0) AS newest_id, COALESCE(MAX(created_at), '') AS newest_created_at FROM images WHERE $where");
    $stmt->execute($params);
    // $row stores the aggregate inventory state that controls warning dismissal.
    $row = $stmt->fetch() ?: [];

    return hash('sha256', implode('|', [
        (string) ($row['image_count'] ?? '0'),
        (string) ($row['newest_id'] ?? '0'),
        (string) ($row['newest_created_at'] ?? ''),
    ]));
}

/**
 * Delete every generated thumbnail cache directory below known gallery folders.
 *
 * The function only targets each gallery's own `thumbs` directory. It does not
 * delete original images, uploaded gallery cover assets, database rows, or any
 * files outside the configured gallery root. The returned counters are used by
 * the admin notice and by the operational log.
 *
 * @return array{files_deleted:int,directories_removed:int,directories_scanned:int} Structured result data for the caller.
 */
function delete_all_thumbnail_files(): array
{
    $metadataSchemaStatus = thumbnail_metadata_mutation_schema_status();
    mutation_schema_assert_known(
        $metadataSchemaStatus,
        'thumbnail_maintenance.delete_all_files',
        'Thumbnail cleanup is temporarily unavailable because thumbnail metadata schema could not be verified. No thumbnail files were deleted.'
    );
    // $filesDeleted counts individual thumbnail files removed from disk.
    $filesDeleted = 0;
    // $directoriesRemoved counts thumbs directories removed after their files are gone.
    $directoriesRemoved = 0;
    // $directoriesScanned counts existing thumbs directories touched by this run.
    $directoriesScanned = 0;
    // $galleryRoot stores the configured root boundary for all filesystem checks.
    $galleryRoot = galleries_root();

    if (schema_inspection_is_available($metadataSchemaStatus)) {
        db()->exec('DELETE FROM image_thumbnail_variants');
    }

    $stmt = db()->prepare('SELECT folder_path FROM galleries ORDER BY folder_path');
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $folderPath) {
        // $gallery stores the minimum shape required by gallery_thumbs_dir().
        $gallery = ['folder_path' => (string) $folderPath];
        // $thumbsDirectory stores the generated thumbnail cache directory for this gallery.
        $thumbsDirectory = gallery_thumbs_dir($gallery, false);

        if (!is_dir($thumbsDirectory)) {
            continue;
        }
        if (!path_inside($galleryRoot, $thumbsDirectory)) {
            throw new RuntimeException('Refusing to delete thumbnails outside the gallery root.');
        }

        $directoriesScanned++;
        $filesDeleted += delete_thumbnail_directory_contents($thumbsDirectory, $galleryRoot);

        if (@rmdir($thumbsDirectory)) {
            $directoriesRemoved++;
        } elseif (is_dir($thumbsDirectory)) {
            throw new RuntimeException('Could not remove thumbnail directory: ' . $thumbsDirectory);
        }
    }

    return [
        'files_deleted' => $filesDeleted,
        'directories_removed' => $directoriesRemoved,
        'directories_scanned' => $directoriesScanned,
    ];
}

/**
 * Delete all files and nested directories inside one thumbnail directory.
 *
 * Generated thumbnail directories should normally contain only flat thumb files,
 * but the recursive iterator keeps cleanup safe and complete if an older or
 * browser version created nested cache folders. The safety boundary remains
 * the configured gallery root and every path is checked before deletion.
 *
 * @param string $thumbsDirectory Thumbs directory value.
 * @param string $allowedRoot Allowed root value.
 * @return int Number of removed files.
 */
function delete_thumbnail_directory_contents(string $thumbsDirectory, string $allowedRoot): int
{
    // $filesDeleted counts all non-directory entries removed from this thumbs directory.
    $filesDeleted = 0;
    // $iterator walks children before parents so nested directories can be removed cleanly.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($thumbsDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $entry) {
        // $path stores the concrete filesystem path currently being removed.
        $path = $entry->getPathname();
        if (!path_inside($allowedRoot, $path)) {
            throw new RuntimeException('Refusing to delete a thumbnail path outside the gallery root.');
        }
        if ($entry->isDir() && !$entry->isLink()) {
            if (!@rmdir($path)) {
                throw new RuntimeException('Could not remove thumbnail subdirectory: ' . $path);
            }
            continue;
        }
        if (!@unlink($path)) {
            throw new RuntimeException('Could not remove thumbnail file: ' . $path);
        }
        $filesDeleted++;
    }

    return $filesDeleted;
}
