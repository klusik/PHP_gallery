<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/experimental_uploads.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides the opt-in client-side upload package settings and server-side
 *   package acceptance workflow.
 *
 * Responsibilities:
 *   - Normalize administrator-controlled experimental upload settings
 *   - Derive safe browser batch sizes from PHP upload limits
 *   - Parse store-only ZIP packages without depending on ZipArchive
 *   - Place browser-prepared originals and thumbnails inside a gallery folder
 *   - Refresh image and thumbnail metadata after a successful batch
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

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use function Gallery\Core\cms_config;
use function Gallery\Core\db;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\is_dng_image_path;
use function Gallery\Core\is_supported_image_path;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\url_for;

const EXPERIMENTAL_UPLOAD_DEFAULT_WORKER_COUNT = 8;
const EXPERIMENTAL_UPLOAD_MIN_WORKER_COUNT = 1;
const EXPERIMENTAL_UPLOAD_HARD_WORKER_CAP = 32;
const EXPERIMENTAL_UPLOAD_DEFAULT_ZIP_RATIO = 0.80;
const EXPERIMENTAL_UPLOAD_MIN_ZIP_RATIO = 0.10;
const EXPERIMENTAL_UPLOAD_MAX_ZIP_RATIO = 0.95;
const EXPERIMENTAL_UPLOAD_BATCH_POLICY_LIMIT_RATIO = 'upload_limit_ratio';
const EXPERIMENTAL_UPLOAD_DEFAULT_MAX_ITEMS_PER_BATCH = 8;
const EXPERIMENTAL_UPLOAD_MIN_ITEMS_PER_BATCH = 1;
const EXPERIMENTAL_UPLOAD_MAX_ITEMS_PER_BATCH = 64;
const EXPERIMENTAL_UPLOAD_DEFAULT_MAX_ZIP_BATCH_BYTES = 24 * 1024 * 1024;
const EXPERIMENTAL_UPLOAD_MIN_MAX_ZIP_BATCH_BYTES = 1 * 1024 * 1024;
const EXPERIMENTAL_UPLOAD_HARD_MAX_ZIP_BATCH_BYTES = 128 * 1024 * 1024;

/**
 * Return default settings for the experimental client-side upload pipeline.
 *
 * @return array<string mixed>.
 */
function experimental_upload_default_settings(): array
{
    return [
        'enabled' => true,
        'default_worker_count' => EXPERIMENTAL_UPLOAD_DEFAULT_WORKER_COUNT,
        'max_worker_count' => EXPERIMENTAL_UPLOAD_HARD_WORKER_CAP,
        'hard_worker_cap' => EXPERIMENTAL_UPLOAD_HARD_WORKER_CAP,
        'batch_size_policy' => EXPERIMENTAL_UPLOAD_BATCH_POLICY_LIMIT_RATIO,
        'zip_size_threshold_ratio' => EXPERIMENTAL_UPLOAD_DEFAULT_ZIP_RATIO,
        'max_items_per_batch' => EXPERIMENTAL_UPLOAD_DEFAULT_MAX_ITEMS_PER_BATCH,
        'max_zip_batch_bytes' => EXPERIMENTAL_UPLOAD_DEFAULT_MAX_ZIP_BATCH_BYTES,
        'thumbnail_rebuild_source_chunk_bytes' => defined('Gallery\\Services\\EXPERIMENTAL_THUMBNAIL_SOURCE_DEFAULT_CHUNK_BYTES') ? EXPERIMENTAL_THUMBNAIL_SOURCE_DEFAULT_CHUNK_BYTES : 512 * 1024 * 1024,
    ];
}

/**
 * Clamp an integer setting while tolerating missing or malformed input.
 *
 * @param mixed $value Value to process.
 * @param int $fallback Fallback value.
 * @param int $minimum Minimum value.
 * @param int $maximum Maximum value.
 * @return int Integer result for the caller.
 */
function experimental_upload_clamped_int(mixed $value, int $fallback, int $minimum, int $maximum): int
{
    if (is_string($value)) {
        $value = trim($value);
    }
    if ($value === '' || !is_numeric($value)) {
        $number = $fallback;
    } else {
        $number = (int) $value;
    }
    return max($minimum, min($maximum, $number));
}

/**
 * Clamp a ratio setting while tolerating missing or malformed input.
 *
 * @param mixed $value Value to process.
 * @param float $fallback Fallback value.
 * @return float Numeric result for the caller.
 */
function experimental_upload_clamped_ratio(mixed $value, float $fallback): float
{
    if (is_string($value)) {
        $value = trim($value);
    }
    if ($value === '' || !is_numeric($value)) {
        $ratio = $fallback;
    } else {
        $ratio = (float) $value;
    }
    return max(EXPERIMENTAL_UPLOAD_MIN_ZIP_RATIO, min(EXPERIMENTAL_UPLOAD_MAX_ZIP_RATIO, $ratio));
}

/**
 * Convert a human-editable megabyte setting into bytes for ZIP batch caps.
 *
 * @param mixed $value Value to process.
 * @param int $fallbackBytes Fallback bytes value.
 * @return int Integer result for the caller.
 */
function experimental_upload_megabytes_to_bytes(mixed $value, int $fallbackBytes): int
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
    return (int) floor($megabytes * 1024 * 1024);
}

/**
 * Normalize raw settings from POST data, migrations, or app_settings rows.
 *
 * @param array $raw Raw value.
 * @return array<string mixed>.
 */
function experimental_upload_normalize_settings(array $raw): array
{
    $defaults = experimental_upload_default_settings();
    $hardCap = experimental_upload_clamped_int(
        $raw['hard_worker_cap'] ?? $defaults['hard_worker_cap'],
        EXPERIMENTAL_UPLOAD_HARD_WORKER_CAP,
        EXPERIMENTAL_UPLOAD_MIN_WORKER_COUNT,
        EXPERIMENTAL_UPLOAD_HARD_WORKER_CAP
    );
    $maxWorkers = experimental_upload_clamped_int(
        $raw['max_worker_count'] ?? $defaults['max_worker_count'],
        EXPERIMENTAL_UPLOAD_HARD_WORKER_CAP,
        EXPERIMENTAL_UPLOAD_MIN_WORKER_COUNT,
        $hardCap
    );
    $defaultWorkers = experimental_upload_clamped_int(
        $raw['default_worker_count'] ?? $defaults['default_worker_count'],
        EXPERIMENTAL_UPLOAD_DEFAULT_WORKER_COUNT,
        EXPERIMENTAL_UPLOAD_MIN_WORKER_COUNT,
        $maxWorkers
    );
    $policy = trim((string) ($raw['batch_size_policy'] ?? EXPERIMENTAL_UPLOAD_BATCH_POLICY_LIMIT_RATIO));
    if ($policy !== EXPERIMENTAL_UPLOAD_BATCH_POLICY_LIMIT_RATIO) {
        $policy = EXPERIMENTAL_UPLOAD_BATCH_POLICY_LIMIT_RATIO;
    }

    return [
        'enabled' => (string) ($raw['enabled'] ?? ($defaults['enabled'] ? '1' : '0')) !== '0',
        'default_worker_count' => $defaultWorkers,
        'max_worker_count' => $maxWorkers,
        'hard_worker_cap' => $hardCap,
        'batch_size_policy' => $policy,
        'zip_size_threshold_ratio' => experimental_upload_clamped_ratio(
            $raw['zip_size_threshold_ratio'] ?? $defaults['zip_size_threshold_ratio'],
            EXPERIMENTAL_UPLOAD_DEFAULT_ZIP_RATIO
        ),
        'max_items_per_batch' => experimental_upload_clamped_int(
            $raw['max_items_per_batch'] ?? $defaults['max_items_per_batch'],
            EXPERIMENTAL_UPLOAD_DEFAULT_MAX_ITEMS_PER_BATCH,
            EXPERIMENTAL_UPLOAD_MIN_ITEMS_PER_BATCH,
            EXPERIMENTAL_UPLOAD_MAX_ITEMS_PER_BATCH
        ),
        'max_zip_batch_bytes' => experimental_upload_clamped_int(
            $raw['max_zip_batch_bytes'] ?? $defaults['max_zip_batch_bytes'],
            EXPERIMENTAL_UPLOAD_DEFAULT_MAX_ZIP_BATCH_BYTES,
            EXPERIMENTAL_UPLOAD_MIN_MAX_ZIP_BATCH_BYTES,
            EXPERIMENTAL_UPLOAD_HARD_MAX_ZIP_BATCH_BYTES
        ),
        'thumbnail_rebuild_source_chunk_bytes' => function_exists('Gallery\\Services\\experimental_thumbnail_rebuild_clamped_source_chunk_bytes')
            ? experimental_thumbnail_rebuild_clamped_source_chunk_bytes($raw['thumbnail_rebuild_source_chunk_bytes'] ?? $defaults['thumbnail_rebuild_source_chunk_bytes'])
            : (int) ($raw['thumbnail_rebuild_source_chunk_bytes'] ?? $defaults['thumbnail_rebuild_source_chunk_bytes']),
    ];
}

/**
 * Read normalized experimental upload settings from app_settings.
 *
 * @return array<string mixed>.
 */
function experimental_upload_settings(): array
{
    return experimental_upload_normalize_settings([
        'enabled' => app_setting('experimental_upload_enabled', '1'),
        'default_worker_count' => app_setting('experimental_upload_default_worker_count', (string) EXPERIMENTAL_UPLOAD_DEFAULT_WORKER_COUNT),
        'max_worker_count' => app_setting('experimental_upload_max_worker_count', (string) EXPERIMENTAL_UPLOAD_HARD_WORKER_CAP),
        'hard_worker_cap' => app_setting('experimental_upload_hard_worker_cap', (string) EXPERIMENTAL_UPLOAD_HARD_WORKER_CAP),
        'batch_size_policy' => app_setting('experimental_upload_batch_size_policy', EXPERIMENTAL_UPLOAD_BATCH_POLICY_LIMIT_RATIO),
        'zip_size_threshold_ratio' => app_setting('experimental_upload_zip_size_threshold_ratio', (string) EXPERIMENTAL_UPLOAD_DEFAULT_ZIP_RATIO),
        'max_items_per_batch' => app_setting('experimental_upload_max_items_per_batch', (string) EXPERIMENTAL_UPLOAD_DEFAULT_MAX_ITEMS_PER_BATCH),
        'max_zip_batch_bytes' => app_setting('experimental_upload_max_zip_batch_bytes', (string) EXPERIMENTAL_UPLOAD_DEFAULT_MAX_ZIP_BATCH_BYTES),
        'thumbnail_rebuild_source_chunk_bytes' => app_setting('experimental_thumbnail_rebuild_source_chunk_bytes', (string) (defined('Gallery\\Services\\EXPERIMENTAL_THUMBNAIL_SOURCE_DEFAULT_CHUNK_BYTES') ? EXPERIMENTAL_THUMBNAIL_SOURCE_DEFAULT_CHUNK_BYTES : 512 * 1024 * 1024)),
    ]);
}

/**
 * Persist experimental upload settings submitted by an administrator.
 *
 * @param array $input Input value.
 * @return array<string mixed>.
 */
function set_experimental_upload_settings(array $input): array
{
    $settings = experimental_upload_normalize_settings([
        'enabled' => !empty($input['experimental_upload_enabled']) ? '1' : '0',
        'default_worker_count' => $input['experimental_upload_default_worker_count'] ?? EXPERIMENTAL_UPLOAD_DEFAULT_WORKER_COUNT,
        'max_worker_count' => $input['experimental_upload_max_worker_count'] ?? EXPERIMENTAL_UPLOAD_HARD_WORKER_CAP,
        'hard_worker_cap' => $input['experimental_upload_hard_worker_cap'] ?? EXPERIMENTAL_UPLOAD_HARD_WORKER_CAP,
        'batch_size_policy' => $input['experimental_upload_batch_size_policy'] ?? EXPERIMENTAL_UPLOAD_BATCH_POLICY_LIMIT_RATIO,
        'zip_size_threshold_ratio' => $input['experimental_upload_zip_size_threshold_ratio'] ?? EXPERIMENTAL_UPLOAD_DEFAULT_ZIP_RATIO,
        'max_items_per_batch' => $input['experimental_upload_max_items_per_batch'] ?? EXPERIMENTAL_UPLOAD_DEFAULT_MAX_ITEMS_PER_BATCH,
        'max_zip_batch_bytes' => experimental_upload_megabytes_to_bytes($input['experimental_upload_max_zip_batch_megabytes'] ?? null, EXPERIMENTAL_UPLOAD_DEFAULT_MAX_ZIP_BATCH_BYTES),
        'thumbnail_rebuild_source_chunk_bytes' => function_exists('Gallery\\Services\\experimental_thumbnail_rebuild_megabytes_to_bytes')
            ? experimental_thumbnail_rebuild_megabytes_to_bytes($input['experimental_thumbnail_rebuild_source_chunk_megabytes'] ?? null)
            : (512 * 1024 * 1024),
    ]);

    set_app_setting('experimental_upload_enabled', $settings['enabled'] ? '1' : '0');
    set_app_setting('experimental_upload_default_worker_count', (string) $settings['default_worker_count']);
    set_app_setting('experimental_upload_max_worker_count', (string) $settings['max_worker_count']);
    set_app_setting('experimental_upload_hard_worker_cap', (string) $settings['hard_worker_cap']);
    set_app_setting('experimental_upload_batch_size_policy', (string) $settings['batch_size_policy']);
    set_app_setting('experimental_upload_zip_size_threshold_ratio', number_format((float) $settings['zip_size_threshold_ratio'], 2, '.', ''));
    set_app_setting('experimental_upload_max_items_per_batch', (string) $settings['max_items_per_batch']);
    set_app_setting('experimental_upload_max_zip_batch_bytes', (string) $settings['max_zip_batch_bytes']);
    set_app_setting('experimental_thumbnail_rebuild_source_chunk_bytes', (string) $settings['thumbnail_rebuild_source_chunk_bytes']);

    return $settings;
}

/**
 * Convert a PHP shorthand byte value, for example 128M, into bytes.
 *
 * @param string $value Value to process.
 * @return int Integer result for the caller.
 */
function experimental_upload_php_size_to_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $number = (float) $value;
    if ($number <= 0) {
        return 0;
    }
    $bytes = match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    };
    return (int) floor($bytes);
}

/**
 * Return the effective PHP request upload ceiling in bytes.
 *
 * @return int Integer result for the caller.
 */
function experimental_upload_server_upload_limit_bytes(): int
{
    $uploadMax = experimental_upload_php_size_to_bytes((string) ini_get('upload_max_filesize'));
    $postMax = experimental_upload_php_size_to_bytes((string) ini_get('post_max_size'));
    $limits = array_values(array_filter([$uploadMax, $postMax], static fn (int $bytes): bool => $bytes > 0));
    if (!$limits) {
        return 8 * 1024 * 1024;
    }
    return min($limits);
}

/**
 * Derive a safe target ZIP size from the PHP upload limit and configured ratio.
 *
 * @param int $uploadLimitBytes Upload limit bytes value.
 * @param float $ratio Ratio value.
 * @return int Integer result for the caller.
 */
function experimental_upload_batch_target_bytes(int $uploadLimitBytes, float $ratio): int
{
    $uploadLimitBytes = max(1, $uploadLimitBytes);
    $ratio = experimental_upload_clamped_ratio($ratio, EXPERIMENTAL_UPLOAD_DEFAULT_ZIP_RATIO);
    $reservedBytes = min(512 * 1024, max(64 * 1024, (int) floor($uploadLimitBytes * 0.05)));
    $target = (int) floor($uploadLimitBytes * $ratio);
    return max(1, min($target, max(1, $uploadLimitBytes - $reservedBytes)));
}

/**
 * Return the final browser ZIP target after PHP limits and the admin absolute cap are both applied.
 *
 * @param int $uploadLimitBytes Upload limit bytes value.
 * @param float $ratio Ratio value.
 * @param int $maxZipBatchBytes Max zip batch bytes value.
 * @return int Integer result for the caller.
 */
function experimental_upload_effective_batch_target_bytes(int $uploadLimitBytes, float $ratio, int $maxZipBatchBytes): int
{
    $ratioTarget = experimental_upload_batch_target_bytes($uploadLimitBytes, $ratio);
    $maxZipBatchBytes = experimental_upload_clamped_int(
        $maxZipBatchBytes,
        EXPERIMENTAL_UPLOAD_DEFAULT_MAX_ZIP_BATCH_BYTES,
        EXPERIMENTAL_UPLOAD_MIN_MAX_ZIP_BATCH_BYTES,
        EXPERIMENTAL_UPLOAD_HARD_MAX_ZIP_BATCH_BYTES
    );
    return max(1, min($ratioTarget, $maxZipBatchBytes));
}

/**
 * Return the current browser-facing experimental upload configuration.
 *
 * @return array<string mixed>.
 */
function experimental_upload_browser_config(): array
{
    $settings = experimental_upload_settings();
    $uploadLimit = experimental_upload_server_upload_limit_bytes();
    $formats = function_exists('Gallery\\Services\\thumbnail_policy_requested_formats') ? thumbnail_policy_requested_formats() : ['jpg', 'webp'];
    $formats = array_values(array_filter(array_map('strval', $formats), static fn (string $format): bool => in_array($format, ['jpg', 'webp'], true)));
    if (!$formats) {
        $formats = ['jpg'];
    }

    return [
        'enabled' => (bool) $settings['enabled'],
        'endpoint' => url_for('admin_upload_experimental_batch'),
        'worker_count' => (int) $settings['default_worker_count'],
        'max_worker_count' => (int) $settings['max_worker_count'],
        'hard_worker_cap' => (int) $settings['hard_worker_cap'],
        'batch_size_policy' => (string) $settings['batch_size_policy'],
        'zip_size_threshold_ratio' => (float) $settings['zip_size_threshold_ratio'],
        'upload_limit_bytes' => $uploadLimit,
        'batch_target_bytes' => experimental_upload_effective_batch_target_bytes($uploadLimit, (float) $settings['zip_size_threshold_ratio'], (int) $settings['max_zip_batch_bytes']),
        'max_items_per_batch' => (int) $settings['max_items_per_batch'],
        'max_zip_batch_bytes' => (int) $settings['max_zip_batch_bytes'],
        'thumbnail_sizes' => function_exists('Gallery\\Services\\thumbnail_sizes') ? array_values(array_map('intval', thumbnail_sizes())) : [300, 600, 800, 960, 1280, 1600],
        'thumbnail_formats' => $formats,
        'jpeg_quality' => function_exists('Gallery\\Services\\thumbnail_jpeg_quality') ? thumbnail_jpeg_quality() : 82,
        'webp_quality' => function_exists('Gallery\\Services\\thumbnail_webp_quality') ? thumbnail_webp_quality() : 82,
        'supported_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
    ];
}

/**
 * Read a little-endian unsigned 16-bit value from binary data.
 *
 * @param string $data Input data.
 * @param int $offset Starting offset.
 * @return int Integer result for the caller.
 */
function experimental_upload_zip_uint16(string $data, int $offset): int
{
    $value = unpack('v', substr($data, $offset, 2));
    return is_array($value) ? (int) $value[1] : 0;
}

/**
 * Read a little-endian unsigned 32-bit value from binary data.
 *
 * @param string $data Input data.
 * @param int $offset Starting offset.
 * @return int Integer result for the caller.
 */
function experimental_upload_zip_uint32(string $data, int $offset): int
{
    $value = unpack('V', substr($data, $offset, 4));
    return is_array($value) ? (int) $value[1] : 0;
}

/**
 * Parse a browser-created store-only ZIP file into safe named entries.
 *
 * @param string $zipPath Zip path filesystem path.
 * @param int $maxBytes Max bytes value.
 * @return array<string string>.
 */
function experimental_upload_parse_store_zip(string $zipPath, int $maxBytes): array
{
    if (!is_file($zipPath)) {
        throw new RuntimeException(t('experimental_upload.error_missing_zip', 'The prepared upload package is missing.'));
    }
    $fileSize = (int) (filesize($zipPath) ?: 0);
    if ($fileSize <= 0) {
        throw new RuntimeException(t('experimental_upload.error_empty_zip', 'The prepared upload package is empty.'));
    }
    if ($maxBytes > 0 && $fileSize > $maxBytes) {
        throw new RuntimeException(t('experimental_upload.error_zip_too_large', 'The prepared upload package is larger than the configured upload limit.'));
    }

    $data = @file_get_contents($zipPath);
    if (!is_string($data) || $data === '') {
        throw new RuntimeException(t('experimental_upload.error_zip_read_failed', 'Could not read the prepared upload package.'));
    }

    $length = strlen($data);
    $offset = 0;
    $entries = [];
    $entryCount = 0;
    while ($offset + 4 <= $length) {
        $signature = substr($data, $offset, 4);
        if ($signature === "\x50\x4b\x01\x02" || $signature === "\x50\x4b\x05\x06") {
            break;
        }
        if ($signature !== "\x50\x4b\x03\x04") {
            throw new RuntimeException(t('experimental_upload.error_zip_structure', 'The prepared upload package is not a supported store-only ZIP.'));
        }
        if ($offset + 30 > $length) {
            throw new RuntimeException(t('experimental_upload.error_zip_truncated', 'The prepared upload package is truncated.'));
        }
        $flags = experimental_upload_zip_uint16($data, $offset + 6);
        $method = experimental_upload_zip_uint16($data, $offset + 8);
        $compressedSize = experimental_upload_zip_uint32($data, $offset + 18);
        $uncompressedSize = experimental_upload_zip_uint32($data, $offset + 22);
        $nameLength = experimental_upload_zip_uint16($data, $offset + 26);
        $extraLength = experimental_upload_zip_uint16($data, $offset + 28);
        if (($flags & 0x08) !== 0 || $method !== 0) {
            throw new RuntimeException(t('experimental_upload.error_zip_store_only', 'Only store-only ZIP upload packages are accepted.'));
        }
        $nameOffset = $offset + 30;
        $dataOffset = $nameOffset + $nameLength + $extraLength;
        if ($nameLength <= 0 || $dataOffset < $nameOffset || $dataOffset + $compressedSize > $length || $compressedSize !== $uncompressedSize) {
            throw new RuntimeException(t('experimental_upload.error_zip_entry_invalid', 'The prepared upload package contains an invalid entry.'));
        }
        $name = normalize_relative_path(substr($data, $nameOffset, $nameLength));
        if ($name !== '' && !str_ends_with($name, '/')) {
            $entries[$name] = substr($data, $dataOffset, $compressedSize);
            $entryCount++;
        }
        if ($entryCount > 20000) {
            throw new RuntimeException(t('experimental_upload.error_zip_entry_count', 'The prepared upload package contains too many files.'));
        }
        $offset = $dataOffset + $compressedSize;
    }

    if (!$entries) {
        throw new RuntimeException(t('experimental_upload.error_zip_no_entries', 'The prepared upload package does not contain any upload entries.'));
    }
    return $entries;
}

/**
 * Return a safe idempotency cache directory for acknowledged batches.
 *
 * @return string Text result for the caller.
 */
function experimental_upload_batch_cache_dir(): string
{
    $configured = (string) (cms_config()['zip_cache_path'] ?? '');
    $base = $configured !== '' ? dirname($configured) : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cache';
    $path = $base . DIRECTORY_SEPARATOR . 'experimental_upload_batches';
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
    return $path;
}

/**
 * Build a cache key for a processed client-side upload batch.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $sessionId Session id identifier.
 * @param int $batchIndex Batch index value.
 * @return string Text result for the caller.
 */
function experimental_upload_batch_cache_key(int $galleryId, string $sessionId, int $batchIndex): string
{
    $sessionId = preg_replace('/[^A-Za-z0-9_.-]/', '', $sessionId) ?: 'session';
    return hash('sha256', $galleryId . '|' . $sessionId . '|' . $batchIndex);
}

/**
 * Read a cached success response for a previously acknowledged batch.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $sessionId Session id identifier.
 * @param int $batchIndex Batch index value.
 * @return array<string mixed>|null.
 */
function experimental_upload_cached_batch_response(int $galleryId, string $sessionId, int $batchIndex): ?array
{
    $path = experimental_upload_batch_cache_dir() . DIRECTORY_SEPARATOR . experimental_upload_batch_cache_key($galleryId, $sessionId, $batchIndex) . '.json';
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : null;
}

/**
 * Cache a success response so client retries do not duplicate stored files.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $sessionId Session id identifier.
 * @param int $batchIndex Batch index value.
 * @param array $response Response data.
 */
function experimental_upload_store_cached_batch_response(int $galleryId, string $sessionId, int $batchIndex, array $response): void
{
    $dir = experimental_upload_batch_cache_dir();
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }
    $path = $dir . DIRECTORY_SEPARATOR . experimental_upload_batch_cache_key($galleryId, $sessionId, $batchIndex) . '.json';
    @file_put_contents($path, json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/**
 * Validate one original image payload before it is placed into a gallery.
 *
 * @param string $filename Filename value.
 * @param string $payload Payload value.
 */
function experimental_upload_validate_original_payload(string $filename, string $payload): void
{
    if (!is_supported_image_path($filename)) {
        throw new RuntimeException(t('experimental_upload.error_unsupported_original', 'The prepared upload package contains an unsupported original image format.'));
    }
    $temporary = tempnam(sys_get_temp_dir(), 'php_gallery_upload_original_');
    if ($temporary === false) {
        throw new RuntimeException(t('experimental_upload.error_temp_file', 'Could not create a temporary validation file.'));
    }
    try {
        @file_put_contents($temporary, $payload);
        if (is_dng_image_path($filename)) {
            throw new RuntimeException(t('experimental_upload.error_browser_dng', 'The experimental browser upload pipeline cannot accept DNG originals. Use the default server-side upload path.'));
        }
        $info = @getimagesize($temporary);
        if ($info === false || empty($info['mime']) || !str_starts_with((string) $info['mime'], 'image/')) {
            throw new RuntimeException(t('experimental_upload.error_invalid_original', 'The prepared upload package contains an invalid original image.'));
        }
    } finally {
        @unlink($temporary);
    }
}

/**
 * Validate one browser-created thumbnail payload.
 *
 * @param string $format Format value.
 * @param string $payload Payload value.
 */
function experimental_upload_validate_thumbnail_payload(string $format, string $payload): void
{
    $info = @getimagesizefromstring($payload);
    if ($info === false || empty($info['mime'])) {
        throw new RuntimeException(t('experimental_upload.error_invalid_thumbnail', 'The prepared upload package contains an invalid thumbnail.'));
    }
    $mime = (string) $info['mime'];
    if ($format === 'jpg' && $mime !== 'image/jpeg') {
        throw new RuntimeException(t('experimental_upload.error_thumbnail_format', 'The prepared upload package contains a thumbnail with the wrong format.'));
    }
    if ($format === 'webp' && $mime !== 'image/webp') {
        throw new RuntimeException(t('experimental_upload.error_thumbnail_format', 'The prepared upload package contains a thumbnail with the wrong format.'));
    }
}

/**
 * Return database image rows keyed by image id.
 *
 * @param array $imageIds Image ids value.
 * @return array<int array<string, mixed>>.
 */
function experimental_upload_image_rows_by_ids(array $imageIds): array
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
 * Decode and validate a browser upload manifest.
 *
 * @param array $entries Entries value.
 * @return array<string mixed>.
 */
function experimental_upload_manifest_from_entries(array $entries): array
{
    $manifestJson = (string) ($entries['manifest.json'] ?? '');
    if ($manifestJson === '') {
        throw new RuntimeException(t('experimental_upload.error_manifest_missing', 'The prepared upload package is missing its manifest.'));
    }
    $manifest = json_decode($manifestJson, true);
    if (!is_array($manifest) || !is_array($manifest['items'] ?? null)) {
        throw new RuntimeException(t('experimental_upload.error_manifest_invalid', 'The prepared upload package manifest is invalid.'));
    }
    return $manifest;
}

/**
 * Store one browser-prepared ZIP package in a target gallery.
 *
 * @param int $galleryId Gallery identifier.
 * @param array $uploadedZip Uploaded zip value.
 * @param string $sessionId Session id identifier.
 * @param int $batchIndex Batch index value.
 * @return array<string mixed>.
 */
function experimental_upload_store_prepared_zip_batch(int $galleryId, array $uploadedZip, string $sessionId, int $batchIndex): array
{
    if ($galleryId <= 0) {
        throw new RuntimeException(t('experimental_upload.error_gallery_required', 'Choose an existing gallery before using the experimental upload pipeline.'));
    }
    if (($cached = experimental_upload_cached_batch_response($galleryId, $sessionId, $batchIndex)) !== null) {
        $cached['cached'] = true;
        return $cached;
    }

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
        throw new RuntimeException(t('experimental_upload.error_choose_zip', 'Choose a prepared upload package.'));
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
    $manifest = experimental_upload_manifest_from_entries($entries);
    $items = array_values((array) ($manifest['items'] ?? []));
    $storedItems = [];
    $storedFilenames = [];

    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            continue;
        }
        $preparedName = safe_uploaded_image_filename((string) ($item['prepared_name'] ?? $item['original_name'] ?? ('image-' . ($index + 1) . '.jpg')));
        $originalPath = normalize_relative_path((string) ($item['original_path'] ?? ('originals/' . $preparedName)));
        if ($originalPath === '' || !isset($entries[$originalPath])) {
            throw new RuntimeException(t('experimental_upload.error_original_missing', 'The prepared upload package is missing an original image.'));
        }
        experimental_upload_validate_original_payload($preparedName, $entries[$originalPath]);
        [$storedFilename, $targetPath] = unique_gallery_upload_target($gallery, $preparedName);
        if (@file_put_contents($targetPath, $entries[$originalPath], LOCK_EX) === false) {
            throw new RuntimeException(t('upload.error.store_image_failed', 'Could not store uploaded image.'));
        }
        $storedFilenames[] = $storedFilename;
        $storedItems[] = [
            'manifest_index' => $index,
            'stored_filename' => $storedFilename,
            'variants' => array_values((array) ($item['variants'] ?? [])),
        ];
    }

    $uploadedCount = count($storedFilenames);
    $changed = $uploadedCount > 0 ? scan_gallery_images($galleryId) : 0;
    $imageIds = uploaded_gallery_image_ids($galleryId, $storedFilenames);
    $scanFailedFilenames = gallery_upload_scan_failed_filenames($galleryId, $storedFilenames);
    $preRenameRowsByPath = [];
    foreach ($storedFilenames as $filename) {
        $row = uploaded_gallery_image_row_by_path($galleryId, $filename);
        if (is_array($row)) {
            $preRenameRowsByPath[$filename] = $row;
        }
    }

    $renameResult = null;
    if (admin_upload_auto_rename_enabled() && $imageIds) {
        $renameResult = gallery_upload_auto_rename_image_ids($galleryId, $imageIds);
        $imageIds = uploaded_gallery_existing_image_ids($galleryId, $imageIds);
    }
    $rowsById = experimental_upload_image_rows_by_ids($imageIds);
    $finalFilenames = uploaded_gallery_filenames_for_image_ids($galleryId, $imageIds);

    $thumbsCreated = 0;
    $thumbnailFailed = 0;
    $thumbnailErrors = [];
    if ($uploadedCount > 0) {
        gallery_thumbs_dir($gallery, true);
    }

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
                experimental_upload_validate_thumbnail_payload($format, $entries[$zipPath]);
                $targetPath = thumbnail_abs_path($image, $gallery, $size, $format);
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    @mkdir($targetDir, 0775, true);
                }
                if (@file_put_contents($targetPath, $entries[$zipPath], LOCK_EX) === false) {
                    throw new RuntimeException(t('experimental_upload.error_thumbnail_store_failed', 'Could not store a prepared thumbnail.'));
                }
                $sourcePath = image_abs_path($image, $gallery);
                if (function_exists('Gallery\\Services\\thumbnail_touch_generated_file_for_source')) {
                    thumbnail_touch_generated_file_for_source($targetPath, $sourcePath);
                }
                if (function_exists('Gallery\\Services\\thumbnail_metadata_record_file') && thumbnail_metadata_schema_ready()) {
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
        'thumbnail_errors' => array_values(array_unique(array_filter($thumbnailErrors))),
        'scan_failed' => count($scanFailedFilenames),
        'scan_failed_filenames' => array_values($scanFailedFilenames),
        'renamed' => $renameResult === null ? 0 : (int) ($renameResult['renamed'] ?? 0),
        'rename_warnings' => $renameResult === null ? [] : array_values((array) ($renameResult['warnings'] ?? [])),
        'rename_failures' => $renameResult === null ? [] : array_values((array) ($renameResult['failures'] ?? [])),
        'redirect_url' => url_for('admin_edit_gallery', ['id' => $galleryId, 'uploaded' => $uploadedCount, 'scanned' => $changed, 'thumbnails' => $thumbsCreated, 'thumbnail_failed' => $thumbnailFailed, 'scan_failed' => count($scanFailedFilenames), 'tab' => 'admin-edit-images']) . '#admin-edit-images',
    ];

    experimental_upload_store_cached_batch_response($galleryId, $sessionId, $batchIndex, $response);
    admin_log_event('info', 'gallery.experimental_upload_batch', 'Admin uploaded a browser-prepared ZIP batch.', [
        'gallery_id' => $galleryId,
        'uploaded' => $uploadedCount,
        'scanned' => $changed,
        'thumbnails' => $thumbsCreated,
        'thumbnail_failed' => $thumbnailFailed,
        'batch_index' => $batchIndex,
    ]);

    return $response;
}
