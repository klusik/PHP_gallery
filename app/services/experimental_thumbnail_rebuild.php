<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/experimental_thumbnail_rebuild.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides the experimental browser-assisted thumbnail rebuild workflow.
 *
 * Responsibilities:
 *   - Select original image files into bounded source ZIP chunks
 *   - Stream store-only ZIP chunks without requiring ZipArchive
 *   - Accept browser-prepared thumbnail ZIP batches
 *   - Store thumbnail derivatives in the canonical thumbnail locations
 *   - Refresh thumbnail metadata after successful browser-side generation
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
 *   2026-06-10
 */

declare(strict_types=1);

const EXPERIMENTAL_THUMBNAIL_SOURCE_DEFAULT_CHUNK_BYTES = 512 * 1024 * 1024;
const EXPERIMENTAL_THUMBNAIL_SOURCE_MIN_CHUNK_BYTES = 16 * 1024 * 1024;
const EXPERIMENTAL_THUMBNAIL_SOURCE_HARD_CHUNK_BYTES = 3 * 1024 * 1024 * 1024;
const EXPERIMENTAL_THUMBNAIL_SOURCE_ZIP_MAX_ENTRY_BYTES = 0xffffffff;
const EXPERIMENTAL_THUMBNAIL_SOURCE_DEFAULT_MAX_ITEMS_PER_CHUNK = 96;
const EXPERIMENTAL_THUMBNAIL_SOURCE_HARD_MAX_ITEMS_PER_CHUNK = 512;

/**
 * Clamp a large byte setting used for browser source-download chunks.
 */
function experimental_thumbnail_rebuild_clamped_source_chunk_bytes(mixed $value, int $fallback = EXPERIMENTAL_THUMBNAIL_SOURCE_DEFAULT_CHUNK_BYTES): int
{
    if (is_string($value)) {
        $value = trim($value);
    }
    if ($value === '' || !is_numeric($value)) {
        $bytes = $fallback;
    } else {
        $bytes = (int) $value;
    }
    return max(EXPERIMENTAL_THUMBNAIL_SOURCE_MIN_CHUNK_BYTES, min(EXPERIMENTAL_THUMBNAIL_SOURCE_HARD_CHUNK_BYTES, $bytes));
}

/**
 * Convert an administrator-entered megabyte value into a source chunk byte cap.
 */
function experimental_thumbnail_rebuild_megabytes_to_bytes(mixed $value, int $fallbackBytes = EXPERIMENTAL_THUMBNAIL_SOURCE_DEFAULT_CHUNK_BYTES): int
{
    if (is_string($value)) {
        $value = trim($value);
    }
    if ($value === '' || !is_numeric($value)) {
        return $fallbackBytes;
    }
    $megabytes = (float) $value;
    if ($megabytes <= 0) {
        return $fallbackBytes;
    }
    return experimental_thumbnail_rebuild_clamped_source_chunk_bytes((int) floor($megabytes * 1024 * 1024), $fallbackBytes);
}

/**
 * Return the configured maximum source ZIP chunk size for browser thumbnail rebuilds.
 */
function experimental_thumbnail_rebuild_source_chunk_bytes(): int
{
    return experimental_thumbnail_rebuild_clamped_source_chunk_bytes(
        app_setting('experimental_thumbnail_rebuild_source_chunk_bytes', (string) EXPERIMENTAL_THUMBNAIL_SOURCE_DEFAULT_CHUNK_BYTES)
    );
}

/**
 * Persist the source-download chunk setting from the upload settings form.
 *
 * @param array<string, mixed> $input Submitted admin settings.
 */
function set_experimental_thumbnail_rebuild_settings(array $input): int
{
    $bytes = experimental_thumbnail_rebuild_megabytes_to_bytes($input['experimental_thumbnail_rebuild_source_chunk_megabytes'] ?? null);
    set_app_setting('experimental_thumbnail_rebuild_source_chunk_bytes', (string) $bytes);
    return $bytes;
}


/**
 * Return the maximum number of original images sent in one source ZIP chunk.
 *
 * This is intentionally separate from the byte cap. Many medium photos in one
 * chunk can make the browser queue hundreds of decode jobs at once, and prior
 * versions could complete with random per-image holes when many workers finished
 * while upload batches were being finalized. Keeping source chunks bounded by
 * item count makes the rebuild deterministic and easier to verify.
 */
function experimental_thumbnail_rebuild_source_chunk_item_cap(): int
{
    $configured = app_setting('experimental_thumbnail_rebuild_source_chunk_item_cap', (string) EXPERIMENTAL_THUMBNAIL_SOURCE_DEFAULT_MAX_ITEMS_PER_CHUNK);
    if (is_string($configured)) {
        $configured = trim($configured);
    }
    if ($configured === '' || !is_numeric($configured)) {
        return EXPERIMENTAL_THUMBNAIL_SOURCE_DEFAULT_MAX_ITEMS_PER_CHUNK;
    }
    return max(1, min(EXPERIMENTAL_THUMBNAIL_SOURCE_HARD_MAX_ITEMS_PER_CHUNK, (int) $configured));
}

/**
 * Normalize thumbnail format names for the browser rebuild pipeline.
 *
 * @param array<int|string, mixed> $formats Requested format names.
 * @return array<int, string>
 */
function experimental_thumbnail_rebuild_normalized_formats(array $formats, bool $fallbackToWebp = true): array
{
    // $normalized stores only derivative formats understood by the browser worker and PHP unpacker.
    $normalized = [];
    foreach ($formats as $format) {
        $format = strtolower(trim((string) $format));
        if (!in_array($format, ['jpg', 'webp'], true) || in_array($format, $normalized, true)) {
            continue;
        }
        $normalized[] = $format;
    }

    if ($normalized !== []) {
        return $normalized;
    }

    return $fallbackToWebp ? ['webp'] : [];
}

/**
 * Return target formats for one original sent to a browser thumbnail rebuild.
 *
 * The browser source chunk manifest carries this per-image value so the rebuild
 * follows the active thumbnail compatibility mode at click time, not a stale
 * page-rendered global default. This keeps WebP-only and JPG plus WebP modes in
 * sync with the maintenance checker.
 *
 * @param array<string, mixed> $image Image database row.
 * @return array<int, string>
 */
function experimental_thumbnail_rebuild_target_formats_for_image(string $sourcePath, array $image): array
{
    $mime = function_exists('image_source_mime_for_derivatives') ? image_source_mime_for_derivatives($sourcePath, $image) : '';
    if ($mime !== '' && function_exists('thumbnail_target_formats_for_source')) {
        return experimental_thumbnail_rebuild_normalized_formats(thumbnail_target_formats_for_source($sourcePath, $mime), false);
    }

    return experimental_thumbnail_rebuild_normalized_formats(function_exists('thumbnail_policy_requested_formats') ? thumbnail_policy_requested_formats() : ['jpg', 'webp']);
}

/**
 * Return the number of variants required for one thumbnail rebuild item.
 *
 * @param array<int, string> $formats Target thumbnail formats.
 */
function experimental_thumbnail_rebuild_expected_variant_count(array $formats): int
{
    $sizes = function_exists('thumbnail_sizes') ? thumbnail_sizes() : [300, 600, 800, 960, 1280, 1600];
    return count($sizes) * count(experimental_thumbnail_rebuild_normalized_formats($formats, false));
}

/**
 * Return browser-facing endpoint and limit configuration for thumbnail rebuilds.
 *
 * @return array<string, mixed>
 */
function experimental_thumbnail_rebuild_browser_config(): array
{
    $settings = function_exists('experimental_upload_settings') ? experimental_upload_settings() : ['enabled' => false];
    $uploadLimit = function_exists('experimental_upload_server_upload_limit_bytes') ? experimental_upload_server_upload_limit_bytes() : 8 * 1024 * 1024;
    $formats = experimental_thumbnail_rebuild_normalized_formats(function_exists('thumbnail_policy_requested_formats') ? thumbnail_policy_requested_formats() : ['jpg', 'webp']);

    return [
        'enabled' => (bool) ($settings['enabled'] ?? false),
        'source_endpoint' => url_for('admin_thumbnail_experimental_source_chunk'),
        'upload_endpoint' => url_for('admin_thumbnail_experimental_upload_batch'),
        'worker_count' => (int) ($settings['default_worker_count'] ?? EXPERIMENTAL_UPLOAD_DEFAULT_WORKER_COUNT),
        'max_worker_count' => (int) ($settings['max_worker_count'] ?? EXPERIMENTAL_UPLOAD_HARD_WORKER_CAP),
        'hard_worker_cap' => (int) ($settings['hard_worker_cap'] ?? EXPERIMENTAL_UPLOAD_HARD_WORKER_CAP),
        'upload_limit_bytes' => $uploadLimit,
        'batch_target_bytes' => function_exists('experimental_upload_effective_batch_target_bytes') ? experimental_upload_effective_batch_target_bytes($uploadLimit, (float) ($settings['zip_size_threshold_ratio'] ?? EXPERIMENTAL_UPLOAD_DEFAULT_ZIP_RATIO), (int) ($settings['max_zip_batch_bytes'] ?? EXPERIMENTAL_UPLOAD_DEFAULT_MAX_ZIP_BATCH_BYTES)) : (int) floor($uploadLimit * 0.8),
        'max_items_per_batch' => (int) ($settings['max_items_per_batch'] ?? EXPERIMENTAL_UPLOAD_DEFAULT_MAX_ITEMS_PER_BATCH),
        'source_chunk_bytes' => experimental_thumbnail_rebuild_source_chunk_bytes(),
        'source_chunk_item_cap' => experimental_thumbnail_rebuild_source_chunk_item_cap(),
        'thumbnail_sizes' => function_exists('thumbnail_sizes') ? array_values(array_map('intval', thumbnail_sizes())) : [300, 600, 800, 960, 1280, 1600],
        'thumbnail_formats' => $formats,
        'jpeg_quality' => function_exists('thumbnail_jpeg_quality') ? thumbnail_jpeg_quality() : 82,
        'webp_quality' => function_exists('thumbnail_webp_quality') ? thumbnail_webp_quality() : 82,
        'supported_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
    ];
}

/**
 * Return a safe source chunk size requested by the browser for one rebuild request.
 */
function experimental_thumbnail_rebuild_requested_chunk_bytes(mixed $value): int
{
    $configured = experimental_thumbnail_rebuild_source_chunk_bytes();
    $requested = experimental_thumbnail_rebuild_clamped_source_chunk_bytes($value, $configured);
    return min($configured, $requested);
}

/**
 * Return all source image identifiers for a thumbnail rebuild request.
 *
 * @param array<string, mixed> $input Request fields.
 * @return array<int, int>
 */
function experimental_thumbnail_rebuild_request_image_ids(array $input): array
{
    $scope = trim((string) ($input['scope'] ?? 'all'));
    if ($scope === 'missing') {
        return function_exists('thumbnail_maintenance_image_ids') ? thumbnail_maintenance_image_ids(null, 0) : [];
    }
    if (!empty($input['image_ids']) && is_array($input['image_ids'])) {
        return array_values(array_unique(array_filter(array_map('intval', $input['image_ids']), static fn (int $id): bool => $id > 0)));
    }
    if (!empty($input['gallery_ids']) && is_array($input['gallery_ids']) && function_exists('image_ids_for_galleries')) {
        return image_ids_for_galleries($input['gallery_ids']);
    }
    return function_exists('all_image_ids') ? all_image_ids() : [];
}

/**
 * Build a source ZIP chunk plan for a browser thumbnail rebuild.
 *
 * @param array<string, mixed> $input Request fields.
 * @return array<string, mixed>
 */
function experimental_thumbnail_rebuild_source_chunk_plan(array $input): array
{
    $imageIds = experimental_thumbnail_rebuild_request_image_ids($input);
    $total = count($imageIds);
    $offset = max(0, (int) ($input['offset'] ?? 0));
    $maxBytes = experimental_thumbnail_rebuild_requested_chunk_bytes($input['source_chunk_bytes'] ?? null);
    $items = [];
    $skipped = [];
    $bytes = 0;
    $itemCap = experimental_thumbnail_rebuild_source_chunk_item_cap();
    $index = $offset;
    $galleryCache = [];

    while ($index < $total) {
        if (count($items) >= $itemCap) {
            break;
        }
        $imageId = (int) $imageIds[$index];
        $index++;
        $image = find_image($imageId);
        if (!$image) {
            $skipped[] = ['image_id' => $imageId, 'reason' => 'missing_image_row'];
            continue;
        }
        $galleryId = (int) ($image['gallery_id'] ?? 0);
        if (!array_key_exists($galleryId, $galleryCache)) {
            $galleryCache[$galleryId] = $galleryId > 0 ? find_gallery($galleryId) : null;
        }
        $gallery = $galleryCache[$galleryId];
        if (!$gallery) {
            $skipped[] = ['image_id' => $imageId, 'reason' => 'missing_gallery_row'];
            continue;
        }
        $sourcePath = image_abs_path($image, $gallery);
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            $skipped[] = ['image_id' => $imageId, 'reason' => 'source_file_unreadable'];
            continue;
        }
        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        if (is_dng_image_path($sourcePath) || in_array($extension, ['heic', 'heif'], true)) {
            $skipped[] = ['image_id' => $imageId, 'reason' => 'browser_unsupported_source_format'];
            continue;
        }
        $targetFormats = experimental_thumbnail_rebuild_target_formats_for_image($sourcePath, $image);
        if ($targetFormats === []) {
            $skipped[] = ['image_id' => $imageId, 'reason' => 'no_target_thumbnail_formats'];
            continue;
        }
        $size = (int) (filesize($sourcePath) ?: 0);
        if ($size <= 0 || $size > EXPERIMENTAL_THUMBNAIL_SOURCE_ZIP_MAX_ENTRY_BYTES) {
            $skipped[] = ['image_id' => $imageId, 'reason' => 'source_file_size_unsupported'];
            continue;
        }
        $zipOverhead = 256 + strlen((string) ($image['filename'] ?? 'image'));
        if ($items && ($bytes + $size + $zipOverhead) > $maxBytes) {
            $index--;
            break;
        }
        if (!$items && ($size + $zipOverhead) > $maxBytes) {
            $skipped[] = ['image_id' => $imageId, 'reason' => 'source_file_larger_than_chunk'];
            continue;
        }

        $entryPath = 'originals/' . $imageId . '/' . safe_uploaded_image_filename((string) ($image['filename'] ?? ('image-' . $imageId . '.jpg')));
        $items[] = [
            'image_id' => $imageId,
            'gallery_id' => $galleryId,
            'filename' => (string) ($image['filename'] ?? ''),
            'relative_path' => (string) ($image['relative_path'] ?? ''),
            'entry_path' => $entryPath,
            'size' => $size,
            'target_formats' => $targetFormats,
            'expected_variants' => experimental_thumbnail_rebuild_expected_variant_count($targetFormats),
            'source_path' => $sourcePath,
        ];
        $bytes += $size + $zipOverhead;
    }

    return [
        'version' => 1,
        'kind' => 'thumbnail_rebuild_source_chunk',
        'offset' => $offset,
        'next_offset' => $index,
        'total' => $total,
        'processed' => min($total, $index),
        'done' => $index >= $total,
        'source_chunk_bytes' => $maxBytes,
        'source_payload_bytes' => $bytes,
        'source_item_cap' => $itemCap,
        'items' => $items,
        'skipped' => $skipped,
    ];
}

/**
 * Return a little-endian 16-bit binary value for a ZIP header.
 */
function experimental_thumbnail_rebuild_pack_uint16(int $value): string
{
    return pack('v', $value & 0xffff);
}

/**
 * Return a little-endian 32-bit binary value for a ZIP header.
 */
function experimental_thumbnail_rebuild_pack_uint32(int $value): string
{
    if ($value < 0) {
        $value = $value + 0x100000000;
    }
    return pack('V', $value);
}

/**
 * Return DOS time for ZIP headers.
 */
function experimental_thumbnail_rebuild_zip_dos_time(int $timestamp): int
{
    return ((int) gmdate('G', $timestamp) << 11) | ((int) gmdate('i', $timestamp) << 5) | ((int) floor((int) gmdate('s', $timestamp) / 2));
}

/**
 * Return DOS date for ZIP headers.
 */
function experimental_thumbnail_rebuild_zip_dos_date(int $timestamp): int
{
    $year = max(1980, (int) gmdate('Y', $timestamp));
    return (($year - 1980) << 9) | ((int) gmdate('n', $timestamp) << 5) | (int) gmdate('j', $timestamp);
}

/**
 * Return a CRC32 integer for bytes using the ZIP unsigned representation.
 */
function experimental_thumbnail_rebuild_crc32_data(string $data): int
{
    return (int) hexdec(hash('crc32b', $data));
}

/**
 * Return a CRC32 integer for a file using the ZIP unsigned representation.
 */
function experimental_thumbnail_rebuild_crc32_file(string $path): int
{
    return (int) hexdec(hash_file('crc32b', $path) ?: '0');
}

/**
 * Build one ZIP local file header.
 */
function experimental_thumbnail_rebuild_zip_local_header(string $entryName, int $crc, int $size, int $timestamp): string
{
    $name = $entryName;
    return "\x50\x4b\x03\x04"
        . experimental_thumbnail_rebuild_pack_uint16(20)
        . experimental_thumbnail_rebuild_pack_uint16(0x0800)
        . experimental_thumbnail_rebuild_pack_uint16(0)
        . experimental_thumbnail_rebuild_pack_uint16(experimental_thumbnail_rebuild_zip_dos_time($timestamp))
        . experimental_thumbnail_rebuild_pack_uint16(experimental_thumbnail_rebuild_zip_dos_date($timestamp))
        . experimental_thumbnail_rebuild_pack_uint32($crc)
        . experimental_thumbnail_rebuild_pack_uint32($size)
        . experimental_thumbnail_rebuild_pack_uint32($size)
        . experimental_thumbnail_rebuild_pack_uint16(strlen($name))
        . experimental_thumbnail_rebuild_pack_uint16(0)
        . $name;
}

/**
 * Build one ZIP central directory header.
 */
function experimental_thumbnail_rebuild_zip_central_header(string $entryName, int $crc, int $size, int $timestamp, int $offset): string
{
    $name = $entryName;
    return "\x50\x4b\x01\x02"
        . experimental_thumbnail_rebuild_pack_uint16(20)
        . experimental_thumbnail_rebuild_pack_uint16(20)
        . experimental_thumbnail_rebuild_pack_uint16(0x0800)
        . experimental_thumbnail_rebuild_pack_uint16(0)
        . experimental_thumbnail_rebuild_pack_uint16(experimental_thumbnail_rebuild_zip_dos_time($timestamp))
        . experimental_thumbnail_rebuild_pack_uint16(experimental_thumbnail_rebuild_zip_dos_date($timestamp))
        . experimental_thumbnail_rebuild_pack_uint32($crc)
        . experimental_thumbnail_rebuild_pack_uint32($size)
        . experimental_thumbnail_rebuild_pack_uint32($size)
        . experimental_thumbnail_rebuild_pack_uint16(strlen($name))
        . experimental_thumbnail_rebuild_pack_uint16(0)
        . experimental_thumbnail_rebuild_pack_uint16(0)
        . experimental_thumbnail_rebuild_pack_uint16(0)
        . experimental_thumbnail_rebuild_pack_uint16(0)
        . experimental_thumbnail_rebuild_pack_uint32(0)
        . experimental_thumbnail_rebuild_pack_uint32($offset)
        . $name;
}

/**
 * Stream one file payload into the response body.
 */
function experimental_thumbnail_rebuild_stream_file_payload(string $path): void
{
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        throw new RuntimeException(t('experimental_thumbnail_rebuild.error_source_open', 'Could not open one original image for download.'));
    }
    try {
        while (!feof($handle)) {
            $chunk = fread($handle, 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException(t('experimental_thumbnail_rebuild.error_source_read', 'Could not read one original image for download.'));
            }
            echo $chunk;
            if (function_exists('fastcgi_finish_request')) {
                flush();
            }
        }
    } finally {
        fclose($handle);
    }
}

/**
 * Stream a source chunk plan as a store-only ZIP file.
 *
 * @param array<string, mixed> $plan Source chunk plan.
 */
function experimental_thumbnail_rebuild_stream_source_zip(array $plan): void
{
    @set_time_limit(300);
    $timestamp = time();
    $manifestItems = [];
    $entries = [];
    foreach ((array) ($plan['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $manifestItems[] = [
            'image_id' => (int) ($item['image_id'] ?? 0),
            'gallery_id' => (int) ($item['gallery_id'] ?? 0),
            'filename' => (string) ($item['filename'] ?? ''),
            'relative_path' => (string) ($item['relative_path'] ?? ''),
            'entry_path' => (string) ($item['entry_path'] ?? ''),
            'size' => (int) ($item['size'] ?? 0),
            'target_formats' => experimental_thumbnail_rebuild_normalized_formats((array) ($item['target_formats'] ?? [])),
            'expected_variants' => (int) ($item['expected_variants'] ?? experimental_thumbnail_rebuild_expected_variant_count((array) ($item['target_formats'] ?? []))),
        ];
        $entries[] = $item;
    }

    $manifest = $plan;
    $manifest['items'] = $manifestItems;
    $manifestJson = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($manifestJson)) {
        throw new RuntimeException(t('experimental_thumbnail_rebuild.error_manifest_encode', 'Could not encode the thumbnail rebuild manifest.'));
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="thumbnail-rebuild-source-' . (int) ($plan['offset'] ?? 0) . '.zip"');
    header('X-Content-Type-Options: nosniff');

    $central = [];
    $offset = 0;
    $writeEntry = static function (string $entryName, string $payload, ?string $filePath = null) use (&$central, &$offset, $timestamp): void {
        $size = $filePath === null ? strlen($payload) : (int) (filesize($filePath) ?: 0);
        $crc = $filePath === null ? experimental_thumbnail_rebuild_crc32_data($payload) : experimental_thumbnail_rebuild_crc32_file($filePath);
        $localHeader = experimental_thumbnail_rebuild_zip_local_header($entryName, $crc, $size, $timestamp);
        echo $localHeader;
        if ($filePath === null) {
            echo $payload;
        } else {
            experimental_thumbnail_rebuild_stream_file_payload($filePath);
        }
        $central[] = experimental_thumbnail_rebuild_zip_central_header($entryName, $crc, $size, $timestamp, $offset);
        $offset += strlen($localHeader) + $size;
    };

    $writeEntry('manifest.json', $manifestJson, null);
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $entryName = normalize_relative_path((string) ($entry['entry_path'] ?? ''));
        $sourcePath = (string) ($entry['source_path'] ?? '');
        if ($entryName === '' || !is_file($sourcePath)) {
            continue;
        }
        $writeEntry($entryName, '', $sourcePath);
    }

    $centralOffset = $offset;
    $centralBlob = implode('', $central);
    echo $centralBlob;
    echo "\x50\x4b\x05\x06"
        . experimental_thumbnail_rebuild_pack_uint16(0)
        . experimental_thumbnail_rebuild_pack_uint16(0)
        . experimental_thumbnail_rebuild_pack_uint16(count($central))
        . experimental_thumbnail_rebuild_pack_uint16(count($central))
        . experimental_thumbnail_rebuild_pack_uint32(strlen($centralBlob))
        . experimental_thumbnail_rebuild_pack_uint32($centralOffset)
        . experimental_thumbnail_rebuild_pack_uint16(0);
}

/**
 * Decode and validate a browser-prepared thumbnail rebuild manifest.
 *
 * @param array<string, string> $entries ZIP entries.
 * @return array<string, mixed>
 */
function experimental_thumbnail_rebuild_manifest_from_entries(array $entries): array
{
    $manifestJson = (string) ($entries['manifest.json'] ?? '');
    if ($manifestJson === '') {
        throw new RuntimeException(t('experimental_thumbnail_rebuild.error_manifest_missing', 'The prepared thumbnail package is missing its manifest.'));
    }
    $manifest = json_decode($manifestJson, true);
    if (!is_array($manifest) || !is_array($manifest['items'] ?? null)) {
        throw new RuntimeException(t('experimental_thumbnail_rebuild.error_manifest_invalid', 'The prepared thumbnail package manifest is invalid.'));
    }
    return $manifest;
}

/**
 * Store one browser-prepared thumbnail ZIP batch.
 *
 * @param array<string, mixed> $uploadedZip Uploaded ZIP file entry from $_FILES.
 * @return array<string, mixed>
 */
function experimental_thumbnail_rebuild_store_prepared_zip_batch(array $uploadedZip, string $sessionId, int $batchIndex): array
{
    if (($cached = experimental_upload_cached_batch_response(0, 'thumb-' . $sessionId, $batchIndex)) !== null) {
        $cached['cached'] = true;
        return $cached;
    }

    $error = (int) ($uploadedZip['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException(t('experimental_thumbnail_rebuild.error_choose_zip', 'Choose a prepared thumbnail package.'));
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(upload_error_message($error));
    }
    $tmpName = (string) ($uploadedZip['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException(t('upload.error.file_unavailable', 'Uploaded file is not available.'));
    }

    $uploadLimit = experimental_upload_server_upload_limit_bytes();
    $settings = experimental_upload_settings();
    $maxBatchBytes = experimental_upload_effective_batch_target_bytes($uploadLimit, (float) $settings['zip_size_threshold_ratio'], (int) $settings['max_zip_batch_bytes']);
    $entries = experimental_upload_parse_store_zip($tmpName, $maxBatchBytes);
    $manifest = experimental_thumbnail_rebuild_manifest_from_entries($entries);
    $items = array_values((array) ($manifest['items'] ?? []));

    $created = 0;
    $failed = 0;
    $skipped = 0;
    $errors = [];
    $galleryCache = [];
    $imageIds = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $imageId = (int) ($item['image_id'] ?? 0);
        $image = $imageId > 0 ? find_image($imageId) : null;
        if (!$image) {
            $failed++;
            $errors[] = 'Image row not found: ' . $imageId;
            continue;
        }
        $galleryId = (int) ($image['gallery_id'] ?? 0);
        if (!array_key_exists($galleryId, $galleryCache)) {
            $galleryCache[$galleryId] = $galleryId > 0 ? find_gallery($galleryId) : null;
        }
        $gallery = $galleryCache[$galleryId];
        if (!$gallery) {
            $failed++;
            $errors[] = 'Gallery row not found for image: ' . $imageId;
            continue;
        }
        $sourcePath = image_abs_path($image, $gallery);
        $targetFormats = is_file($sourcePath) ? experimental_thumbnail_rebuild_target_formats_for_image($sourcePath, $image) : [];
        if ($targetFormats === []) {
            $skipped++;
            $errors[] = 'No target thumbnail formats for image: ' . $imageId;
            continue;
        }
        gallery_thumbs_dir($gallery, true);
        $imageIds[] = $imageId;
        $storedVariantKeys = [];
        foreach ((array) ($item['variants'] ?? []) as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $size = (int) ($variant['size'] ?? 0);
            $format = strtolower((string) ($variant['format'] ?? ''));
            $zipPath = normalize_relative_path((string) ($variant['path'] ?? ''));
            if (!in_array($size, thumbnail_sizes(), true) || !in_array($format, ['jpg', 'webp'], true) || $zipPath === '') {
                $skipped++;
                continue;
            }
            if (!in_array($format, $targetFormats, true)) {
                $skipped++;
                continue;
            }
            if (!isset($entries[$zipPath])) {
                $failed++;
                $errors[] = 'Missing prepared thumbnail: ' . $zipPath;
                continue;
            }
            try {
                experimental_upload_validate_thumbnail_payload($format, $entries[$zipPath]);
                $targetPath = thumbnail_abs_path($image, $gallery, $size, $format);
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    @mkdir($targetDir, 0775, true);
                }
                if (@file_put_contents($targetPath, $entries[$zipPath], LOCK_EX) === false) {
                    throw new RuntimeException(t('experimental_thumbnail_rebuild.error_thumbnail_store_failed', 'Could not store a browser-prepared thumbnail.'));
                }
                if (function_exists('thumbnail_touch_generated_file_for_source')) {
                    thumbnail_touch_generated_file_for_source($targetPath, $sourcePath);
                }
                if (function_exists('thumbnail_metadata_record_file') && thumbnail_metadata_schema_ready()) {
                    $metadata = thumbnail_metadata_record_file($image, $gallery, $size, $format, $targetPath, $sourcePath, true);
                    if (empty($metadata['valid'])) {
                        $failed++;
                        $errors[] = 'Invalid prepared thumbnail geometry: ' . basename($targetPath);
                        continue;
                    }
                }
                $storedVariantKeys[$size . ':' . $format] = true;
                $created++;
            } catch (Throwable $exception) {
                $failed++;
                $errors[] = $exception->getMessage();
            }
        }
        foreach (thumbnail_sizes() as $requiredSize) {
            foreach ($targetFormats as $requiredFormat) {
                $requiredKey = (int) $requiredSize . ':' . $requiredFormat;
                if (isset($storedVariantKeys[$requiredKey])) {
                    continue;
                }
                $failed++;
                $errors[] = 'Prepared package did not include required thumbnail variant for image ' . $imageId . ': ' . (int) $requiredSize . ' ' . $requiredFormat;
            }
        }
    }

    if ($created > 0 || $failed > 0 || $skipped > 0) {
        thumbnail_maintenance_summary_cache_clear();
    }

    $response = [
        'ok' => $failed === 0,
        'created' => $created,
        'skipped' => $skipped,
        'failed' => $failed,
        'errors' => array_values(array_unique(array_filter($errors))),
        'error' => $failed > 0 ? t('experimental_thumbnail_rebuild.error_batch_incomplete', 'One or more browser-prepared thumbnail variants were rejected. The batch was not fully accepted.') : null,
        'image_ids' => array_values(array_unique($imageIds)),
    ];
    if ($failed === 0) {
        experimental_upload_store_cached_batch_response(0, 'thumb-' . $sessionId, $batchIndex, $response);
    }
    admin_log_event($failed > 0 ? 'warning' : 'info', 'thumbnail.experimental_rebuild_batch', 'Admin uploaded a browser-prepared thumbnail rebuild ZIP batch.', [
        'created' => $created,
        'skipped' => $skipped,
        'failed' => $failed,
        'batch_index' => $batchIndex,
        'image_count' => count(array_unique($imageIds)),
    ]);

    return $response;
}
