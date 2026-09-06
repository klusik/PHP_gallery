<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/browser_uploads/pipeline.php
 * Module Type: Service
 *
 * Purpose:
 *   Orchestrates storing one prepared browser upload batch.
 *
 * Responsibilities:
 *   - Store a prepared ZIP batch into the target gallery
 *   - Emit bounded progress events for the browser client
 *   - Return the registered image rows for the completed batch
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
 *   - Loaded by app/services/browser_uploads.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/browser_uploads.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use function Gallery\Core\cms_config;
use function Gallery\Core\cms_runtime_limit;
use function Gallery\Core\db;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\is_dng_image_path;
use function Gallery\Core\is_supported_image_path;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;
use function Gallery\Core\url_for;

/**
 * Return a readable byte count for server-side upload progress events.
 *
 * @param int $bytes Byte count value.
 * @return string Text result for the caller.
 */
function browser_upload_format_bytes(int $bytes): string
{
    $bytes = max(0, $bytes);
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB', 'TB'];
    $value = $bytes / 1024;
    $unitIndex = 0;
    while ($value >= 1024 && $unitIndex < count($units) - 1) {
        $value /= 1024;
        $unitIndex++;
    }
    $decimals = $value >= 100 || $unitIndex === 0 ? 0 : 1;
    return number_format($value, $decimals, '.', '') . ' ' . $units[$unitIndex];
}

/**
 * Create one upload progress event for the browser mini log.
 *
 * @param float $startedAt Request start timestamp.
 * @param string $message Event message.
 * @param array $context Event context.
 * @return array<string,mixed> Structured result data for the caller.
 */
function browser_upload_progress_event(float $startedAt, string $message, array $context = []): array
{
    return [
        'time' => date('H:i:s'),
        'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        'message' => $message,
        'context' => $context,
    ];
}

/**
 * Return database image rows keyed by image id.
 *
 * @param array $imageIds Image ids value.
 * @return array<int array<string, mixed>>.
 */
function browser_upload_image_rows_by_ids(array $imageIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $imageIds), static fn (int $id): bool => $id > 0)));
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare('SELECT * FROM images WHERE id IN (' . $placeholders . ')');
    $stmt->execute($ids);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[(int) ($row['id'] ?? 0)] = $row;
    }
    return $rows;
}

/**
 * Store one browser-prepared ZIP package in a target gallery.
 *
 * @param int $galleryId Gallery identifier.
 * @param array $uploadedZip Uploaded zip value.
 * @param string $sessionId Session id identifier.
 * @param int $batchIndex Batch index value.
 * @param bool $preparedThumbnailsRequired Require a complete browser-generated thumbnail matrix.
 * @return array<string mixed>.
 */
function browser_upload_store_prepared_zip_batch(int $galleryId, array $uploadedZip, string $sessionId, int $batchIndex, bool $preparedThumbnailsRequired = false): array
{
    $startedAt = microtime(true);
    $events = [browser_upload_progress_event($startedAt, 'PHP received prepared ZIP request for batch ' . ($batchIndex + 1) . '.')];
    if ($galleryId <= 0) {
        throw new RuntimeException(t('browser_upload.error_gallery_required', 'Choose an existing gallery before using the browser upload pipeline.'));
    }
    if (($cached = browser_upload_cached_batch_response($galleryId, $sessionId, $batchIndex)) !== null) {
        $cached['cached'] = true;
        $cached['upload_events'] = array_merge(
            $events,
            [browser_upload_progress_event($startedAt, 'Reused cached result for this already accepted ZIP batch.')],
            array_values((array) ($cached['upload_events'] ?? []))
        );
        return $cached;
    }

    mutation_schema_assert_available(
        upload_ingestion_schema_status(),
        'browser_upload.store_prepared_batch',
        'Browser upload requires the current gallery/image database schema. Run pending migrations first.',
        'Browser upload is temporarily unavailable because the required database schema could not be verified. The prepared ZIP remains uncommitted.'
    );
    thumbnail_metadata_preflight_write_schema('browser_upload.thumbnail_metadata_preflight');

    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        throw new RuntimeException(t('gallery.error.not_found', 'Gallery not found.'));
    }
    $galleryRoot = gallery_abs_path((string) $gallery['folder_path']);
    if (!is_dir($galleryRoot) || !is_writable($galleryRoot)) {
        throw new RuntimeException(t('gallery.error.folder_not_writable', 'Gallery folder is not writable.'));
    }

    $error = (int) ($uploadedZip['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException(t('browser_upload.error_choose_zip', 'Choose a prepared upload package.'));
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(upload_error_message($error));
    }
    $tmpName = (string) ($uploadedZip['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException(t('upload.error.file_unavailable', 'Uploaded file is not available.'));
    }
    $zipSize = (int) ($uploadedZip['size'] ?? (@filesize($tmpName) ?: 0));
    $events[] = browser_upload_progress_event($startedAt, 'Uploaded ZIP is available in PHP temp storage, ' . browser_upload_format_bytes($zipSize) . '.');

    $uploadLimit = browser_upload_server_upload_limit_bytes();
    // The configured ZIP size remains a client-side packing target. One prepared
    // image plus its thumbnails may legitimately exceed that target because the
    // image package is atomic. PHP's effective request upload limit is therefore
    // the authoritative hard ceiling for an already received browser upload ZIP.
    $entries = browser_upload_parse_store_zip($tmpName, $uploadLimit);
    $events[] = browser_upload_progress_event($startedAt, 'Parsed store-only ZIP with ' . count($entries) . ' file entr' . (count($entries) === 1 ? 'y' : 'ies') . '.');
    $manifest = browser_upload_manifest_from_entries($entries);
    browser_upload_validate_manifest_identity($manifest, $sessionId, $batchIndex);
    $events[] = browser_upload_progress_event($startedAt, 'Validated ZIP manifest for batch ' . ($batchIndex + 1) . '.');
    if ($preparedThumbnailsRequired) {
        browser_upload_validate_required_thumbnail_manifest($manifest, $entries);
        $events[] = browser_upload_progress_event($startedAt, 'Verified complete browser-prepared thumbnail coverage before storing originals.');
    }
    $events[] = browser_upload_progress_event($startedAt, 'Preserving browser source order for accepted images.');
    $sortBase = browser_upload_session_sort_base($galleryId, $sessionId);
    $events[] = browser_upload_progress_event($startedAt, 'Reserved deterministic upload order base ' . $sortBase . ' for this browser session.');
    $manifestHash = hash('sha256', (string) ($entries['manifest.json'] ?? ''));
    $batchState = browser_upload_load_batch_state($galleryId, $sessionId, $batchIndex, $manifestHash);
    $batchStateItems = is_array($batchState['items'] ?? null) ? $batchState['items'] : [];
    $items = browser_upload_manifest_items_in_source_order((array) ($manifest['items'] ?? []));
    $storedItems = [];
    $storedFilenames = [];

    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            continue;
        }
        $manifestOrderIndex = (int) ($item['_manifest_order_index'] ?? $index);
        $sourceIndex = browser_upload_manifest_source_index($item, $manifestOrderIndex);
        $stateKey = browser_upload_manifest_state_key($item, $manifestOrderIndex);
        $existingStateItem = is_array($batchStateItems[$stateKey] ?? null) ? $batchStateItems[$stateKey] : [];
        $storedFilename = browser_upload_reusable_state_filename($gallery, $existingStateItem);
        if ($storedFilename === null) {
            $browserOriginalName = (string) ($item['original_name'] ?? ('image-' . ($index + 1) . '.jpg'));
            $preparedName = safe_uploaded_image_filename((string) ($item['prepared_name'] ?? $browserOriginalName));
            $originalPath = normalize_relative_path((string) ($item['original_path'] ?? ('originals/' . $preparedName)));
            $originalContext = [
                'gallery_id' => $galleryId,
                'batch_index' => $batchIndex,
                'manifest_index' => $manifestOrderIndex,
                'source_index' => $sourceIndex,
                'state_key' => $stateKey,
                'original_name' => $browserOriginalName,
                'prepared_name' => $preparedName,
                'original_path' => $originalPath,
                'manifest_original_mime' => (string) ($item['original_mime'] ?? $item['mime'] ?? ''),
                'manifest_original_detected_format' => (string) ($item['original_detected_format'] ?? ''),
                'manifest_original_size' => (int) ($item['original_size'] ?? 0),
                'manifest_original_width' => (int) ($item['original_width'] ?? $item['width'] ?? 0),
                'manifest_original_height' => (int) ($item['original_height'] ?? $item['height'] ?? 0),
            ];
            if ($originalPath === '' || !isset($entries[$originalPath])) {
                throw new BrowserUploadValidationException(
                    t('browser_upload.error_original_missing_detail', 'The prepared upload package is missing an original image entry: {path}. Source file: {filename}.', [
                        'path' => $originalPath !== '' ? $originalPath : '(empty)',
                        'filename' => $browserOriginalName,
                    ]),
                    $originalContext + ['validation_stage' => 'original_entry_missing']
                );
            }
            $validatedPreparedName = browser_upload_prepare_original_filename($preparedName, $entries[$originalPath], $originalContext);
            if ($validatedPreparedName !== $preparedName) {
                $events[] = browser_upload_progress_event($startedAt, 'Corrected original filename extension from ' . $preparedName . ' to ' . $validatedPreparedName . ' after reading the payload signature.');
                $preparedName = $validatedPreparedName;
            }
            [$storedFilename, $targetPath] = unique_gallery_upload_target($gallery, $preparedName);
            if (@file_put_contents($targetPath, $entries[$originalPath], LOCK_EX) === false) {
                throw new RuntimeException(t('upload.error.store_image_failed', 'Could not store uploaded image.'));
            }
            $batchStateItems[$stateKey] = [
                'manifest_index' => $manifestOrderIndex,
                'source_index' => $sourceIndex,
                'stored_filename' => $storedFilename,
                'image_id' => 0,
            ];
            $batchState['items'] = $batchStateItems;
            browser_upload_store_batch_state($galleryId, $sessionId, $batchIndex, $batchState);
        }
        $storedFilenames[] = $storedFilename;
        $storedItems[] = [
            'manifest_index' => $manifestOrderIndex,
            'source_index' => $sourceIndex,
            'state_key' => $stateKey,
            'stored_filename' => $storedFilename,
            'source_metadata' => [
                'width' => (int) ($item['original_width'] ?? $item['width'] ?? 0),
                'height' => (int) ($item['original_height'] ?? $item['height'] ?? 0),
                'display_width' => (int) ($item['original_display_width'] ?? $item['original_width'] ?? $item['width'] ?? 0),
                'display_height' => (int) ($item['original_display_height'] ?? $item['original_height'] ?? $item['height'] ?? 0),
                'exif_orientation' => (int) ($item['original_exif_orientation'] ?? 1),
                'mime' => (string) ($item['original_mime'] ?? $item['mime'] ?? ''),
                'exif' => is_array($item['client_exif'] ?? null) ? $item['client_exif'] : [],
            ],
            'variants' => array_values((array) ($item['variants'] ?? [])),
        ];
    }

    $uploadedCount = count($storedFilenames);
    $storedOriginalBytes = 0;
    foreach ($storedFilenames as $filename) {
        $path = $galleryRoot . DIRECTORY_SEPARATOR . $filename;
        $storedOriginalBytes += is_file($path) ? (int) (@filesize($path) ?: 0) : 0;
    }
    $events[] = browser_upload_progress_event($startedAt, 'Stored or reused ' . $uploadedCount . ' original image file(s), ' . browser_upload_format_bytes($storedOriginalBytes) . '.');
    $sourceMetadataByFilename = [];
    $sourceIndexByFilename = [];
    foreach ($storedItems as $storedItem) {
        $filename = normalize_relative_path((string) ($storedItem['stored_filename'] ?? ''));
        if ($filename !== '' && is_array($storedItem['source_metadata'] ?? null)) {
            $sourceMetadataByFilename[$filename] = $storedItem['source_metadata'];
        }
        if ($filename !== '') {
            $sourceIndexByFilename[$filename] = browser_upload_manifest_source_index($storedItem, (int) ($storedItem['manifest_index'] ?? 0));
        }
    }
    $clientExifCount = 0;
    $clientGpsCount = 0;
    foreach ($sourceMetadataByFilename as $sourceMetadata) {
        $clientExif = is_array($sourceMetadata['exif'] ?? null) ? $sourceMetadata['exif'] : [];
        if ($clientExif !== []) {
            $clientExifCount++;
        }
        if (isset($clientExif['gps_lat'], $clientExif['gps_lng'])) {
            $clientGpsCount++;
        }
    }
    $changed = $uploadedCount > 0
        ? scan_gallery_selected_uploaded_images($galleryId, $storedFilenames, $sourceMetadataByFilename)
        : 0;
    $events[] = browser_upload_progress_event($startedAt, 'Indexed uploaded originals in the database, changed rows: ' . $changed . '.');
    $orderedRows = browser_upload_apply_source_sort_order($galleryId, $sourceIndexByFilename, $sortBase);
    if ($orderedRows > 0) {
        $events[] = browser_upload_progress_event($startedAt, 'Applied deterministic source order to ' . $orderedRows . ' image row(s).');
    }
    if ($clientExifCount > 0 || $clientGpsCount > 0) {
        $events[] = browser_upload_progress_event($startedAt, 'Stored client-side EXIF metadata for ' . $clientExifCount . ' image(s), including GPS for ' . $clientGpsCount . ' image(s).');
    }
    $imageIds = uploaded_gallery_image_ids($galleryId, $storedFilenames);
    $scanFailedFilenames = gallery_upload_scan_failed_filenames($galleryId, $storedFilenames);
    $preRenameRowsByPath = [];
    foreach ($storedFilenames as $filename) {
        $row = uploaded_gallery_image_row_by_path($galleryId, $filename);
        if (is_array($row)) {
            $preRenameRowsByPath[$filename] = $row;
        }
    }
    foreach ($storedItems as $storedItem) {
        $stateKey = (string) ($storedItem['state_key'] ?? ($storedItem['manifest_index'] ?? ''));
        $filename = (string) ($storedItem['stored_filename'] ?? '');
        $row = $preRenameRowsByPath[$filename] ?? null;
        if ($stateKey !== '' && is_array($row)) {
            $batchStateItems[$stateKey]['image_id'] = (int) ($row['id'] ?? 0);
            $batchStateItems[$stateKey]['stored_filename'] = (string) ($row['relative_path'] ?? $filename);
        }
    }
    $batchState['items'] = $batchStateItems;
    browser_upload_store_batch_state($galleryId, $sessionId, $batchIndex, $batchState);

    $renameResult = null;
    if (admin_upload_auto_rename_enabled() && $imageIds) {
        $events[] = browser_upload_progress_event($startedAt, 'Auto-renaming uploaded image rows.');
        $renameResult = gallery_upload_auto_rename_image_ids($galleryId, $imageIds);
        $imageIds = uploaded_gallery_existing_image_ids($galleryId, $imageIds);
        $events[] = browser_upload_progress_event($startedAt, 'Auto-renaming finished, renamed rows: ' . (int) ($renameResult['renamed'] ?? 0) . '.');
    }
    $rowsById = browser_upload_image_rows_by_ids($imageIds);
    $finalFilenames = uploaded_gallery_filenames_for_image_ids($galleryId, $imageIds);
    foreach ($storedItems as $storedItem) {
        $stateKey = (string) ($storedItem['state_key'] ?? ($storedItem['manifest_index'] ?? ''));
        $storedFilename = (string) ($storedItem['stored_filename'] ?? '');
        $preRenameRow = $preRenameRowsByPath[$storedFilename] ?? null;
        $imageId = is_array($preRenameRow) ? (int) ($preRenameRow['id'] ?? 0) : 0;
        if ($stateKey !== '' && $imageId > 0 && isset($rowsById[$imageId])) {
            $batchStateItems[$stateKey]['image_id'] = $imageId;
            $batchStateItems[$stateKey]['stored_filename'] = (string) ($rowsById[$imageId]['relative_path'] ?? $storedFilename);
        }
    }
    $batchState['items'] = $batchStateItems;
    browser_upload_store_batch_state($galleryId, $sessionId, $batchIndex, $batchState);

    $thumbsCreated = 0;
    $thumbnailFailed = 0;
    $thumbnailErrors = [];
    if ($uploadedCount > 0) {
        gallery_thumbs_dir($gallery, true);
    }

    $events[] = browser_upload_progress_event($startedAt, 'Writing prepared thumbnail files for accepted images.');
    foreach ($storedItems as $storedItem) {
        $storedFilename = (string) $storedItem['stored_filename'];
        $preRenameRow = $preRenameRowsByPath[$storedFilename] ?? null;
        $imageId = is_array($preRenameRow) ? (int) ($preRenameRow['id'] ?? 0) : 0;
        $image = $imageId > 0 && isset($rowsById[$imageId]) ? $rowsById[$imageId] : null;
        if (!is_array($image)) {
            continue;
        }
        foreach ((array) ($storedItem['variants'] ?? []) as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $size = (int) ($variant['size'] ?? 0);
            $format = strtolower((string) ($variant['format'] ?? ''));
            $zipPath = normalize_relative_path((string) ($variant['path'] ?? ''));
            if (!in_array($size, thumbnail_sizes(), true) || !in_array($format, ['jpg', 'webp'], true) || $zipPath === '') {
                continue;
            }
            if (!isset($entries[$zipPath])) {
                $thumbnailFailed++;
                $thumbnailErrors[] = 'Missing prepared thumbnail: ' . $zipPath;
                continue;
            }
            try {
                browser_upload_validate_thumbnail_payload($format, $entries[$zipPath]);
                $targetPath = thumbnail_abs_path($image, $gallery, $size, $format);
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    @mkdir($targetDir, 0775, true);
                }
                if (@file_put_contents($targetPath, $entries[$zipPath], LOCK_EX) === false) {
                    throw new RuntimeException(t('browser_upload.error_thumbnail_store_failed', 'Could not store a prepared thumbnail.'));
                }
                $sourcePath = image_abs_path($image, $gallery);
                if (function_exists('Gallery\\Services\\thumbnail_touch_generated_file_for_source')) {
                    thumbnail_touch_generated_file_for_source($targetPath, $sourcePath);
                }
                if (function_exists('Gallery\\Services\\thumbnail_metadata_record_prepared_variant') && thumbnail_metadata_schema_ready()) {
                    $metadata = thumbnail_metadata_record_prepared_variant(
                        $image,
                        $gallery,
                        $size,
                        $format,
                        $targetPath,
                        (int) ($variant['width'] ?? 0),
                        (int) ($variant['height'] ?? 0)
                    );
                    if (empty($metadata['valid'])) {
                        $thumbnailFailed++;
                        $thumbnailErrors[] = 'Invalid prepared thumbnail: ' . basename($targetPath);
                        continue;
                    }
                } elseif (function_exists('Gallery\\Services\\thumbnail_metadata_record_file') && thumbnail_metadata_schema_ready()) {
                    $metadata = thumbnail_metadata_record_file($image, $gallery, $size, $format, $targetPath, $sourcePath, true);
                    if (empty($metadata['valid'])) {
                        $thumbnailFailed++;
                        $thumbnailErrors[] = 'Invalid prepared thumbnail: ' . basename($targetPath);
                        continue;
                    }
                }
                $thumbsCreated++;
            } catch (Throwable $exception) {
                $thumbnailFailed++;
                $thumbnailErrors[] = $exception->getMessage();
            }
        }
    }

    $events[] = browser_upload_progress_event($startedAt, 'Registered prepared thumbnails, created ' . $thumbsCreated . ', failed ' . $thumbnailFailed . '.');

    $response = [
        'ok' => true,
        'gallery_id' => $galleryId,
        'gallery_ids' => [$galleryId],
        'gallery_title' => (string) ($gallery['title'] ?? ''),
        'gallery_url' => gallery_public_url($gallery),
        'edit_url' => url_for('admin_edit_gallery', ['id' => $galleryId, 'uploaded' => $uploadedCount, 'scanned' => $changed, 'tab' => 'admin-edit-images']) . '#admin-edit-images',
        'parent_gallery_id' => (int) ($gallery['parent_id'] ?? 0),
        'parent_gallery_url' => '',
        'refresh_gallery_id' => $galleryId,
        'refresh_url' => gallery_public_url($gallery),
        'created_gallery' => false,
        'image_ids' => array_values(array_map('intval', $imageIds)),
        'filenames' => array_values($finalFilenames),
        'uploaded' => $uploadedCount,
        'scanned' => $changed,
        'thumbnails' => $thumbsCreated,
        'thumbnail_skipped' => 0,
        'thumbnail_failed' => $thumbnailFailed,
        'thumbnail_processing' => $preparedThumbnailsRequired ? 'browser_prepared' : 'none',
        'thumbnail_errors' => array_values(array_unique(array_filter($thumbnailErrors))),
        'scan_failed' => count($scanFailedFilenames),
        'scan_failed_filenames' => array_values($scanFailedFilenames),
        'renamed' => $renameResult === null ? 0 : (int) ($renameResult['renamed'] ?? 0),
        'rename_warnings' => $renameResult === null ? [] : array_values((array) ($renameResult['warnings'] ?? [])),
        'rename_failures' => $renameResult === null ? [] : array_values((array) ($renameResult['failures'] ?? [])),
        'upload_events' => array_values($events),
        'redirect_url' => url_for('admin_edit_gallery', ['id' => $galleryId, 'uploaded' => $uploadedCount, 'scanned' => $changed, 'thumbnails' => $thumbsCreated, 'thumbnail_failed' => $thumbnailFailed, 'scan_failed' => count($scanFailedFilenames), 'tab' => 'admin-edit-images']) . '#admin-edit-images',
    ];

    browser_upload_store_cached_batch_response($galleryId, $sessionId, $batchIndex, $response);
    admin_log_event('info', 'gallery.browser_upload_batch', 'Admin uploaded a browser-prepared ZIP batch.', [
        'gallery_id' => $galleryId,
        'uploaded' => $uploadedCount,
        'scanned' => $changed,
        'thumbnails' => $thumbsCreated,
        'thumbnail_failed' => $thumbnailFailed,
        'thumbnail_processing' => $preparedThumbnailsRequired ? 'browser_prepared' : 'none',
        'batch_index' => $batchIndex,
    ]);

    return $response;
}
