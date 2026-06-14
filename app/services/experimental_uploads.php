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
use function Gallery\Core\now_sql;
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
 * Return the state path for a partially handled browser upload batch.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $sessionId Session id identifier.
 * @param int $batchIndex Batch index value.
 * @return string Text result for the caller.
 */
function experimental_upload_batch_state_path(int $galleryId, string $sessionId, int $batchIndex): string
{
    return experimental_upload_batch_cache_dir() . DIRECTORY_SEPARATOR . 'state-' . experimental_upload_batch_cache_key($galleryId, $sessionId, $batchIndex) . '.json';
}

/**
 * Return the state path for upload-session source ordering.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $sessionId Session id identifier.
 * @return string Text result for the caller.
 */
function experimental_upload_session_order_path(int $galleryId, string $sessionId): string
{
    $sessionId = preg_replace('/[^A-Za-z0-9_.-]/', '', $sessionId) ?: 'session';
    return experimental_upload_batch_cache_dir() . DIRECTORY_SEPARATOR . 'order-' . hash('sha256', $galleryId . '|' . $sessionId) . '.json';
}

/**
 * Return the stable sort-order base for one browser upload session.
 *
 * The value is calculated once before the first batch is indexed, then reused
 * for all later batches and retries. Source indexes can therefore be mapped
 * directly to gallery sort_order values without depending on worker completion,
 * ZIP packing boundaries, or retry timing.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $sessionId Session id identifier.
 * @return int First sort_order value reserved for this upload session.
 */
function experimental_upload_session_sort_base(int $galleryId, string $sessionId): int
{
    $path = experimental_upload_session_order_path($galleryId, $sessionId);
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $base = is_array($decoded) ? (int) ($decoded['sort_base'] ?? 0) : 0;
        if ($base > 0) {
            return $base;
        }
    }

    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM images WHERE gallery_id = ?');
    $stmt->execute([$galleryId]);
    $base = (int) $stmt->fetchColumn() + 10;
    $dir = experimental_upload_batch_cache_dir();
    if (is_dir($dir) && is_writable($dir)) {
        @file_put_contents($path, json_encode([
            'version' => 1,
            'gallery_id' => $galleryId,
            'session_id' => $sessionId,
            'sort_base' => $base,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
    return $base;
}

/**
 * Apply deterministic upload-session ordering to accepted image rows.
 *
 * @param int $galleryId Gallery identifier.
 * @param array<string,int> $sourceIndexByRelativePath Source indexes keyed by gallery-relative path.
 * @param int $sortBase First sort_order value for source_index zero.
 * @return int Number of rows whose sort_order was touched.
 */
function experimental_upload_apply_source_sort_order(int $galleryId, array $sourceIndexByRelativePath, int $sortBase): int
{
    if ($galleryId <= 0 || !$sourceIndexByRelativePath) {
        return 0;
    }

    $select = db()->prepare('SELECT id FROM images WHERE gallery_id = ? AND relative_path_hash = ? LIMIT 1');
    $update = db()->prepare('UPDATE images SET sort_order = ?, updated_at = ? WHERE id = ?');
    $changed = 0;
    foreach ($sourceIndexByRelativePath as $relativePath => $sourceIndex) {
        $relativePath = normalize_relative_path((string) $relativePath);
        if ($relativePath === '') {
            continue;
        }
        $select->execute([$galleryId, hash('sha256', $relativePath)]);
        $imageId = (int) ($select->fetchColumn() ?: 0);
        if ($imageId <= 0) {
            continue;
        }
        $sortOrder = $sortBase + max(0, (int) $sourceIndex) * 10;
        $update->execute([$sortOrder, now_sql(), $imageId]);
        $changed += $update->rowCount() > 0 ? 1 : 0;
    }
    return $changed;
}

/**
 * Create an empty durable batch state structure.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $sessionId Session id identifier.
 * @param int $batchIndex Batch index value.
 * @param string $manifestHash Manifest hash value.
 * @return array<string,mixed> Structured result data for the caller.
 */
function experimental_upload_empty_batch_state(int $galleryId, string $sessionId, int $batchIndex, string $manifestHash): array
{
    return [
        'version' => 1,
        'gallery_id' => $galleryId,
        'session_id' => $sessionId,
        'batch_index' => $batchIndex,
        'manifest_hash' => $manifestHash,
        'items' => [],
    ];
}

/**
 * Read durable state for a partially handled browser upload batch.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $sessionId Session id identifier.
 * @param int $batchIndex Batch index value.
 * @param string $manifestHash Manifest hash value.
 * @return array<string,mixed> Structured result data for the caller.
 */
function experimental_upload_load_batch_state(int $galleryId, string $sessionId, int $batchIndex, string $manifestHash): array
{
    $path = experimental_upload_batch_state_path($galleryId, $sessionId, $batchIndex);
    if (!is_file($path)) {
        return experimental_upload_empty_batch_state($galleryId, $sessionId, $batchIndex, $manifestHash);
    }
    $raw = @file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || (string) ($decoded['manifest_hash'] ?? '') !== $manifestHash || !is_array($decoded['items'] ?? null)) {
        return experimental_upload_empty_batch_state($galleryId, $sessionId, $batchIndex, $manifestHash);
    }
    return $decoded;
}

/**
 * Persist durable state for a partially handled browser upload batch.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $sessionId Session id identifier.
 * @param int $batchIndex Batch index value.
 * @param array $state State data.
 */
function experimental_upload_store_batch_state(int $galleryId, string $sessionId, int $batchIndex, array $state): void
{
    $dir = experimental_upload_batch_cache_dir();
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }
    $path = experimental_upload_batch_state_path($galleryId, $sessionId, $batchIndex);
    @file_put_contents($path, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/**
 * Return the reusable filename from a prior partial batch state item.
 *
 * @param array $gallery Gallery row.
 * @param array $stateItem State item data.
 * @return string|null Text result for the caller.
 */
function experimental_upload_reusable_state_filename(array $gallery, array $stateItem): ?string
{
    $galleryRoot = gallery_abs_path((string) $gallery['folder_path']);
    $filename = normalize_relative_path((string) ($stateItem['stored_filename'] ?? ''));
    if ($filename !== '' && !str_contains($filename, '/') && is_file($galleryRoot . DIRECTORY_SEPARATOR . $filename)) {
        return $filename;
    }

    $imageId = (int) ($stateItem['image_id'] ?? 0);
    if ($imageId <= 0 || !function_exists('Gallery\Services\find_image')) {
        return null;
    }
    $image = find_image($imageId);
    if (!is_array($image) || (int) ($image['gallery_id'] ?? 0) !== (int) ($gallery['id'] ?? 0)) {
        return null;
    }
    $relativePath = normalize_relative_path((string) ($image['relative_path'] ?? ''));
    if ($relativePath === '' || str_contains($relativePath, '/')) {
        return null;
    }
    return is_file($galleryRoot . DIRECTORY_SEPARATOR . $relativePath) ? $relativePath : null;
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
    if ($payload === '') {
        throw new RuntimeException(t('experimental_upload.error_invalid_original', 'The prepared upload package contains an invalid original image.'));
    }
    if (is_dng_image_path($filename)) {
        throw new RuntimeException(t('experimental_upload.error_browser_dng', 'The experimental browser upload pipeline cannot accept DNG originals. Use the default server-side upload path.'));
    }
    if (!experimental_upload_payload_matches_image_signature($filename, $payload)) {
        throw new RuntimeException(t('experimental_upload.error_invalid_original', 'The prepared upload package contains an invalid original image.'));
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
    if ($payload === '' || !experimental_upload_payload_matches_format($format, $payload)) {
        throw new RuntimeException(t('experimental_upload.error_thumbnail_format', 'The prepared upload package contains a thumbnail with the wrong format.'));
    }
}

/**
 * Return true when an image payload has the expected lightweight file signature.
 *
 * @param string $filename Filename value.
 * @param string $payload Payload value.
 * @return bool True when the extension and bytes are compatible.
 */
function experimental_upload_payload_matches_image_signature(string $filename, string $payload): bool
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return match ($extension) {
        'jpg', 'jpeg' => str_starts_with($payload, "\xff\xd8\xff"),
        'png' => str_starts_with($payload, "\x89PNG\r\n\x1a\n"),
        'gif' => str_starts_with($payload, 'GIF87a') || str_starts_with($payload, 'GIF89a'),
        'webp' => strlen($payload) >= 12 && substr($payload, 0, 4) === 'RIFF' && substr($payload, 8, 4) === 'WEBP',
        default => false,
    };
}

/**
 * Return true when a prepared thumbnail payload matches its manifest format.
 *
 * @param string $format Thumbnail format value.
 * @param string $payload Payload value.
 * @return bool True when the bytes match the expected thumbnail format.
 */
function experimental_upload_payload_matches_format(string $format, string $payload): bool
{
    return match ($format) {
        'jpg' => str_starts_with($payload, "\xff\xd8\xff"),
        'webp' => strlen($payload) >= 12 && substr($payload, 0, 4) === 'RIFF' && substr($payload, 8, 4) === 'WEBP',
        default => false,
    };
}


/**
 * Return a readable byte count for server-side upload progress events.
 *
 * @param int $bytes Byte count value.
 * @return string Text result for the caller.
 */
function experimental_upload_format_bytes(int $bytes): string
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
function experimental_upload_progress_event(float $startedAt, string $message, array $context = []): array
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
 * Validate that a ZIP manifest belongs to the posted upload session and batch.
 *
 * @param array $manifest Manifest data.
 * @param string $sessionId Session id identifier.
 * @param int $batchIndex Batch index value.
 */
function experimental_upload_validate_manifest_identity(array $manifest, string $sessionId, int $batchIndex): void
{
    $manifestSessionId = (string) ($manifest['upload_session_id'] ?? '');
    $manifestBatchIndex = array_key_exists('batch_index', $manifest) ? (int) $manifest['batch_index'] : -1;
    if ($manifestSessionId === '' || !hash_equals($sessionId, $manifestSessionId) || $manifestBatchIndex !== $batchIndex) {
        throw new RuntimeException(t('experimental_upload.error_manifest_mismatch', 'The prepared upload package does not match the posted upload session.'));
    }
}

/**
 * Return manifest items in the original browser source order.
 *
 * The browser can prepare images concurrently, so this server-side safety net
 * never trusts ZIP entry order alone. The source_index value is assigned before
 * workers start and therefore represents the deterministic filename order chosen
 * by the upload coordinator.
 *
 * @param array $items Manifest item values.
 * @return array<int,array<string,mixed>> Items sorted by source_index.
 */
function experimental_upload_manifest_items_in_source_order(array $items): array
{
    $ordered = [];
    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            continue;
        }
        $item['_manifest_order_index'] = (int) $index;
        $ordered[] = $item;
    }

    usort($ordered, static function (array $left, array $right): int {
        $leftSource = experimental_upload_manifest_source_index($left, (int) ($left['_manifest_order_index'] ?? 0));
        $rightSource = experimental_upload_manifest_source_index($right, (int) ($right['_manifest_order_index'] ?? 0));
        if ($leftSource !== $rightSource) {
            return $leftSource <=> $rightSource;
        }
        return (int) ($left['_manifest_order_index'] ?? 0) <=> (int) ($right['_manifest_order_index'] ?? 0);
    });

    return $ordered;
}

/**
 * Return one manifest item source index with a safe fallback.
 *
 * @param array $item Manifest item value.
 * @param int $fallback Fallback order value.
 * @return int Source order value.
 */
function experimental_upload_manifest_source_index(array $item, int $fallback): int
{
    if (array_key_exists('source_index', $item) && is_numeric($item['source_index'])) {
        return max(0, (int) $item['source_index']);
    }
    return max(0, $fallback);
}

/**
 * Return the durable partial-batch state key for one manifest item.
 *
 * @param array $item Manifest item value.
 * @param int $fallback Fallback order value.
 * @return string State key used by retry idempotency.
 */
function experimental_upload_manifest_state_key(array $item, int $fallback): string
{
    return 'source-' . experimental_upload_manifest_source_index($item, $fallback);
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
    $startedAt = microtime(true);
    $events = [experimental_upload_progress_event($startedAt, 'PHP received prepared ZIP request for batch ' . ($batchIndex + 1) . '.')];
    if ($galleryId <= 0) {
        throw new RuntimeException(t('experimental_upload.error_gallery_required', 'Choose an existing gallery before using the experimental upload pipeline.'));
    }
    if (($cached = experimental_upload_cached_batch_response($galleryId, $sessionId, $batchIndex)) !== null) {
        $cached['cached'] = true;
        $cached['upload_events'] = array_merge(
            $events,
            [experimental_upload_progress_event($startedAt, 'Reused cached result for this already accepted ZIP batch.')],
            array_values((array) ($cached['upload_events'] ?? []))
        );
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
    $zipSize = (int) ($uploadedZip['size'] ?? (@filesize($tmpName) ?: 0));
    $events[] = experimental_upload_progress_event($startedAt, 'Uploaded ZIP is available in PHP temp storage, ' . experimental_upload_format_bytes($zipSize) . '.');

    $uploadLimit = experimental_upload_server_upload_limit_bytes();
    $settings = experimental_upload_settings();
    $maxBatchBytes = experimental_upload_effective_batch_target_bytes($uploadLimit, (float) $settings['zip_size_threshold_ratio'], (int) $settings['max_zip_batch_bytes']);
    $entries = experimental_upload_parse_store_zip($tmpName, $maxBatchBytes);
    $events[] = experimental_upload_progress_event($startedAt, 'Parsed store-only ZIP with ' . count($entries) . ' file entr' . (count($entries) === 1 ? 'y' : 'ies') . '.');
    $manifest = experimental_upload_manifest_from_entries($entries);
    experimental_upload_validate_manifest_identity($manifest, $sessionId, $batchIndex);
    $events[] = experimental_upload_progress_event($startedAt, 'Validated ZIP manifest for batch ' . ($batchIndex + 1) . '.');
    $events[] = experimental_upload_progress_event($startedAt, 'Preserving browser source order for accepted images.');
    $sortBase = experimental_upload_session_sort_base($galleryId, $sessionId);
    $events[] = experimental_upload_progress_event($startedAt, 'Reserved deterministic upload order base ' . $sortBase . ' for this browser session.');
    $manifestHash = hash('sha256', (string) ($entries['manifest.json'] ?? ''));
    $batchState = experimental_upload_load_batch_state($galleryId, $sessionId, $batchIndex, $manifestHash);
    $batchStateItems = is_array($batchState['items'] ?? null) ? $batchState['items'] : [];
    $items = experimental_upload_manifest_items_in_source_order((array) ($manifest['items'] ?? []));
    $storedItems = [];
    $storedFilenames = [];

    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            continue;
        }
        $manifestOrderIndex = (int) ($item['_manifest_order_index'] ?? $index);
        $sourceIndex = experimental_upload_manifest_source_index($item, $manifestOrderIndex);
        $stateKey = experimental_upload_manifest_state_key($item, $manifestOrderIndex);
        $existingStateItem = is_array($batchStateItems[$stateKey] ?? null) ? $batchStateItems[$stateKey] : [];
        $storedFilename = experimental_upload_reusable_state_filename($gallery, $existingStateItem);
        if ($storedFilename === null) {
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
            $batchStateItems[$stateKey] = [
                'manifest_index' => $manifestOrderIndex,
                'source_index' => $sourceIndex,
                'stored_filename' => $storedFilename,
                'image_id' => 0,
            ];
            $batchState['items'] = $batchStateItems;
            experimental_upload_store_batch_state($galleryId, $sessionId, $batchIndex, $batchState);
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
    $events[] = experimental_upload_progress_event($startedAt, 'Stored or reused ' . $uploadedCount . ' original image file(s), ' . experimental_upload_format_bytes($storedOriginalBytes) . '.');
    $sourceMetadataByFilename = [];
    $sourceIndexByFilename = [];
    foreach ($storedItems as $storedItem) {
        $filename = normalize_relative_path((string) ($storedItem['stored_filename'] ?? ''));
        if ($filename !== '' && is_array($storedItem['source_metadata'] ?? null)) {
            $sourceMetadataByFilename[$filename] = $storedItem['source_metadata'];
        }
        if ($filename !== '') {
            $sourceIndexByFilename[$filename] = experimental_upload_manifest_source_index($storedItem, (int) ($storedItem['manifest_index'] ?? 0));
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
    $events[] = experimental_upload_progress_event($startedAt, 'Indexed uploaded originals in the database, changed rows: ' . $changed . '.');
    $orderedRows = experimental_upload_apply_source_sort_order($galleryId, $sourceIndexByFilename, $sortBase);
    if ($orderedRows > 0) {
        $events[] = experimental_upload_progress_event($startedAt, 'Applied deterministic source order to ' . $orderedRows . ' image row(s).');
    }
    if ($clientExifCount > 0 || $clientGpsCount > 0) {
        $events[] = experimental_upload_progress_event($startedAt, 'Stored client-side EXIF metadata for ' . $clientExifCount . ' image(s), including GPS for ' . $clientGpsCount . ' image(s).');
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
    experimental_upload_store_batch_state($galleryId, $sessionId, $batchIndex, $batchState);

    $renameResult = null;
    if (admin_upload_auto_rename_enabled() && $imageIds) {
        $events[] = experimental_upload_progress_event($startedAt, 'Auto-renaming uploaded image rows.');
        $renameResult = gallery_upload_auto_rename_image_ids($galleryId, $imageIds);
        $imageIds = uploaded_gallery_existing_image_ids($galleryId, $imageIds);
        $events[] = experimental_upload_progress_event($startedAt, 'Auto-renaming finished, renamed rows: ' . (int) ($renameResult['renamed'] ?? 0) . '.');
    }
    $rowsById = experimental_upload_image_rows_by_ids($imageIds);
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
    experimental_upload_store_batch_state($galleryId, $sessionId, $batchIndex, $batchState);

    $thumbsCreated = 0;
    $thumbnailFailed = 0;
    $thumbnailErrors = [];
    if ($uploadedCount > 0) {
        gallery_thumbs_dir($gallery, true);
    }

    $events[] = experimental_upload_progress_event($startedAt, 'Writing prepared thumbnail files for accepted images.');
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

    $events[] = experimental_upload_progress_event($startedAt, 'Registered prepared thumbnails, created ' . $thumbsCreated . ', failed ' . $thumbnailFailed . '.');

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
        'upload_events' => array_values($events),
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
