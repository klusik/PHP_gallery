<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/browser_uploads/settings.php
 * Module Type: Service
 *
 * Purpose:
 *   Normalizes, persists, and publishes browser upload settings.
 *
 * Responsibilities:
 *   - Clamp every configurable value into its supported range
 *   - Derive effective batch targets from PHP and policy limits
 *   - Publish the bounded configuration consumed by browser code
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
 * Return default settings for the browser-side upload pipeline.
 *
 * @return array<string mixed>.
 */
function browser_upload_default_settings(): array
{
    return [
        'enabled' => true,
        'default_worker_count' => (int) cms_runtime_limit('browser_upload.default_worker_count'),
        'max_worker_count' => (int) cms_runtime_limit('browser_upload.hard_worker_cap'),
        'hard_worker_cap' => (int) cms_runtime_limit('browser_upload.hard_worker_cap'),
        'batch_size_policy' => BROWSER_UPLOAD_BATCH_POLICY_LIMIT_RATIO,
        'zip_size_threshold_ratio' => (float) cms_runtime_limit('browser_upload.default_zip_ratio'),
        'max_items_per_batch' => (int) cms_runtime_limit('browser_upload.default_max_items_per_batch'),
        'max_zip_batch_bytes' => (int) cms_runtime_limit('browser_upload.default_max_zip_batch_bytes'),
        'thumbnail_rebuild_source_chunk_bytes' => (int) cms_runtime_limit('browser_thumbnail_rebuild.default_chunk_bytes'),
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
function browser_upload_clamped_int(mixed $value, int $fallback, int $minimum, int $maximum): int
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
function browser_upload_clamped_ratio(mixed $value, float $fallback): float
{
    if (is_string($value)) {
        $value = trim($value);
    }
    if ($value === '' || !is_numeric($value)) {
        $ratio = $fallback;
    } else {
        $ratio = (float) $value;
    }
    return max((float) cms_runtime_limit('browser_upload.min_zip_ratio'), min((float) cms_runtime_limit('browser_upload.max_zip_ratio'), $ratio));
}

/**
 * Convert a human-editable megabyte setting into bytes for ZIP batch packing targets.
 *
 * @param mixed $value Value to process.
 * @param int $fallbackBytes Fallback bytes value.
 * @return int Integer result for the caller.
 */
function browser_upload_megabytes_to_bytes(mixed $value, int $fallbackBytes): int
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
function browser_upload_normalize_settings(array $raw): array
{
    $defaults = browser_upload_default_settings();
    $hardCap = browser_upload_clamped_int(
        $raw['hard_worker_cap'] ?? $defaults['hard_worker_cap'],
        (int) cms_runtime_limit('browser_upload.hard_worker_cap'),
        (int) cms_runtime_limit('browser_upload.min_worker_count'),
        (int) cms_runtime_limit('browser_upload.hard_worker_cap')
    );
    $maxWorkers = browser_upload_clamped_int(
        $raw['max_worker_count'] ?? $defaults['max_worker_count'],
        (int) cms_runtime_limit('browser_upload.hard_worker_cap'),
        (int) cms_runtime_limit('browser_upload.min_worker_count'),
        $hardCap
    );
    $defaultWorkers = browser_upload_clamped_int(
        $raw['default_worker_count'] ?? $defaults['default_worker_count'],
        (int) cms_runtime_limit('browser_upload.default_worker_count'),
        (int) cms_runtime_limit('browser_upload.min_worker_count'),
        $maxWorkers
    );
    $policy = trim((string) ($raw['batch_size_policy'] ?? BROWSER_UPLOAD_BATCH_POLICY_LIMIT_RATIO));
    if ($policy !== BROWSER_UPLOAD_BATCH_POLICY_LIMIT_RATIO) {
        $policy = BROWSER_UPLOAD_BATCH_POLICY_LIMIT_RATIO;
    }

    return [
        'enabled' => (string) ($raw['enabled'] ?? ($defaults['enabled'] ? '1' : '0')) !== '0',
        'default_worker_count' => $defaultWorkers,
        'max_worker_count' => $maxWorkers,
        'hard_worker_cap' => $hardCap,
        'batch_size_policy' => $policy,
        'zip_size_threshold_ratio' => browser_upload_clamped_ratio(
            $raw['zip_size_threshold_ratio'] ?? $defaults['zip_size_threshold_ratio'],
            (float) cms_runtime_limit('browser_upload.default_zip_ratio')
        ),
        'max_items_per_batch' => browser_upload_clamped_int(
            $raw['max_items_per_batch'] ?? $defaults['max_items_per_batch'],
            (int) cms_runtime_limit('browser_upload.default_max_items_per_batch'),
            (int) cms_runtime_limit('browser_upload.min_items_per_batch'),
            (int) cms_runtime_limit('browser_upload.max_items_per_batch')
        ),
        'max_zip_batch_bytes' => browser_upload_clamped_int(
            $raw['max_zip_batch_bytes'] ?? $defaults['max_zip_batch_bytes'],
            (int) cms_runtime_limit('browser_upload.default_max_zip_batch_bytes'),
            (int) cms_runtime_limit('browser_upload.min_max_zip_batch_bytes'),
            (int) cms_runtime_limit('browser_upload.hard_max_zip_batch_bytes')
        ),
        'thumbnail_rebuild_source_chunk_bytes' => function_exists('Gallery\\Services\\browser_thumbnail_rebuild_clamped_source_chunk_bytes')
            ? browser_thumbnail_rebuild_clamped_source_chunk_bytes($raw['thumbnail_rebuild_source_chunk_bytes'] ?? $defaults['thumbnail_rebuild_source_chunk_bytes'])
            : (int) ($raw['thumbnail_rebuild_source_chunk_bytes'] ?? $defaults['thumbnail_rebuild_source_chunk_bytes']),
    ];
}

/**
 * Read normalized browser upload settings from app_settings.
 *
 * @return array<string mixed>.
 */
function browser_upload_settings(): array
{
    return browser_upload_normalize_settings([
        'enabled' => app_setting('browser_upload_enabled', '1'),
        'default_worker_count' => app_setting('browser_upload_default_worker_count', (string) (int) cms_runtime_limit('browser_upload.default_worker_count')),
        'max_worker_count' => app_setting('browser_upload_max_worker_count', (string) (int) cms_runtime_limit('browser_upload.hard_worker_cap')),
        'hard_worker_cap' => app_setting('browser_upload_hard_worker_cap', (string) (int) cms_runtime_limit('browser_upload.hard_worker_cap')),
        'batch_size_policy' => app_setting('browser_upload_batch_size_policy', BROWSER_UPLOAD_BATCH_POLICY_LIMIT_RATIO),
        'zip_size_threshold_ratio' => app_setting('browser_upload_zip_size_threshold_ratio', (string) (float) cms_runtime_limit('browser_upload.default_zip_ratio')),
        'max_items_per_batch' => app_setting('browser_upload_max_items_per_batch', (string) (int) cms_runtime_limit('browser_upload.default_max_items_per_batch')),
        'max_zip_batch_bytes' => app_setting('browser_upload_max_zip_batch_bytes', (string) (int) cms_runtime_limit('browser_upload.default_max_zip_batch_bytes')),
        'thumbnail_rebuild_source_chunk_bytes' => app_setting('browser_thumbnail_rebuild_source_chunk_bytes', (string) ((int) cms_runtime_limit('browser_thumbnail_rebuild.default_chunk_bytes'))),
    ]);
}

/**
 * Persist browser upload settings submitted by an administrator.
 *
 * @param array $input Input value.
 * @return array<string mixed>.
 */
function set_browser_upload_settings(array $input): array
{
    $settings = browser_upload_normalize_settings([
        'enabled' => !empty($input['browser_upload_enabled']) ? '1' : '0',
        'default_worker_count' => $input['browser_upload_default_worker_count'] ?? (int) cms_runtime_limit('browser_upload.default_worker_count'),
        'max_worker_count' => $input['browser_upload_max_worker_count'] ?? (int) cms_runtime_limit('browser_upload.hard_worker_cap'),
        'hard_worker_cap' => $input['browser_upload_hard_worker_cap'] ?? (int) cms_runtime_limit('browser_upload.hard_worker_cap'),
        'batch_size_policy' => $input['browser_upload_batch_size_policy'] ?? BROWSER_UPLOAD_BATCH_POLICY_LIMIT_RATIO,
        'zip_size_threshold_ratio' => $input['browser_upload_zip_size_threshold_ratio'] ?? (float) cms_runtime_limit('browser_upload.default_zip_ratio'),
        'max_items_per_batch' => $input['browser_upload_max_items_per_batch'] ?? (int) cms_runtime_limit('browser_upload.default_max_items_per_batch'),
        'max_zip_batch_bytes' => browser_upload_megabytes_to_bytes($input['browser_upload_max_zip_batch_megabytes'] ?? null, (int) cms_runtime_limit('browser_upload.default_max_zip_batch_bytes')),
        'thumbnail_rebuild_source_chunk_bytes' => function_exists('Gallery\\Services\\browser_thumbnail_rebuild_megabytes_to_bytes')
            ? browser_thumbnail_rebuild_megabytes_to_bytes($input['browser_thumbnail_rebuild_source_chunk_megabytes'] ?? null)
            : (int) cms_runtime_limit('browser_thumbnail_rebuild.default_chunk_bytes'),
    ]);

    set_app_setting('browser_upload_enabled', $settings['enabled'] ? '1' : '0');
    set_app_setting('browser_upload_default_worker_count', (string) $settings['default_worker_count']);
    set_app_setting('browser_upload_max_worker_count', (string) $settings['max_worker_count']);
    set_app_setting('browser_upload_hard_worker_cap', (string) $settings['hard_worker_cap']);
    set_app_setting('browser_upload_batch_size_policy', (string) $settings['batch_size_policy']);
    set_app_setting('browser_upload_zip_size_threshold_ratio', number_format((float) $settings['zip_size_threshold_ratio'], 2, '.', ''));
    set_app_setting('browser_upload_max_items_per_batch', (string) $settings['max_items_per_batch']);
    set_app_setting('browser_upload_max_zip_batch_bytes', (string) $settings['max_zip_batch_bytes']);
    set_app_setting('browser_thumbnail_rebuild_source_chunk_bytes', (string) $settings['thumbnail_rebuild_source_chunk_bytes']);

    return $settings;
}

/**
 * Convert a PHP shorthand byte value, for example 128M, into bytes.
 *
 * @param string $value Value to process.
 * @return int Integer result for the caller.
 */
function browser_upload_php_size_to_bytes(string $value): int
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
function browser_upload_server_upload_limit_bytes(): int
{
    $uploadMax = browser_upload_php_size_to_bytes((string) ini_get('upload_max_filesize'));
    $postMax = browser_upload_php_size_to_bytes((string) ini_get('post_max_size'));
    $limits = array_values(array_filter([$uploadMax, $postMax], static fn (int $bytes): bool => $bytes > 0));
    if (!$limits) {
        return max(1, (int) cms_runtime_limit('browser_upload.fallback_server_upload_limit_bytes'));
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
function browser_upload_batch_target_bytes(int $uploadLimitBytes, float $ratio): int
{
    $uploadLimitBytes = max(1, $uploadLimitBytes);
    $ratio = browser_upload_clamped_ratio($ratio, (float) cms_runtime_limit('browser_upload.default_zip_ratio'));
    $reservedBytes = min(
        max(1, (int) cms_runtime_limit('browser_upload.request_reserve_max_bytes')),
        max(
            max(1, (int) cms_runtime_limit('browser_upload.request_reserve_min_bytes')),
            (int) floor($uploadLimitBytes * (float) cms_runtime_limit('browser_upload.request_reserve_ratio'))
        )
    );
    $target = (int) floor($uploadLimitBytes * $ratio);
    return max(1, min($target, max(1, $uploadLimitBytes - $reservedBytes)));
}

/**
 * Return the normal browser ZIP packing target after PHP limits and the admin target are both applied.
 *
 * @param int $uploadLimitBytes Upload limit bytes value.
 * @param float $ratio Ratio value.
 * @param int $maxZipBatchBytes Max zip batch bytes value.
 * @return int Integer result for the caller.
 */
function browser_upload_effective_batch_target_bytes(int $uploadLimitBytes, float $ratio, int $maxZipBatchBytes): int
{
    $ratioTarget = browser_upload_batch_target_bytes($uploadLimitBytes, $ratio);
    $maxZipBatchBytes = browser_upload_clamped_int(
        $maxZipBatchBytes,
        (int) cms_runtime_limit('browser_upload.default_max_zip_batch_bytes'),
        (int) cms_runtime_limit('browser_upload.min_max_zip_batch_bytes'),
        (int) cms_runtime_limit('browser_upload.hard_max_zip_batch_bytes')
    );
    return max(1, min($ratioTarget, $maxZipBatchBytes));
}

/**
 * Return the current browser-facing upload configuration.
 *
 * @return array<string mixed>.
 */
function browser_upload_browser_config(): array
{
    $settings = browser_upload_settings();
    $uploadLimit = browser_upload_server_upload_limit_bytes();
    $formats = function_exists('Gallery\\Services\\thumbnail_policy_requested_formats') ? thumbnail_policy_requested_formats() : ['webp'];
    $formats = array_values(array_filter(array_map('strval', $formats), static fn (string $format): bool => in_array($format, ['jpg', 'webp'], true)));
    if (!$formats) {
        $formats = ['webp'];
    }

    return [
        'enabled' => (bool) $settings['enabled'],
        'endpoint' => url_for('admin_upload_browser_batch'),
        'worker_count' => (int) $settings['default_worker_count'],
        'max_worker_count' => (int) $settings['max_worker_count'],
        'hard_worker_cap' => (int) $settings['hard_worker_cap'],
        'batch_size_policy' => (string) $settings['batch_size_policy'],
        'zip_size_threshold_ratio' => (float) $settings['zip_size_threshold_ratio'],
        'upload_limit_bytes' => $uploadLimit,
        'batch_target_bytes' => browser_upload_effective_batch_target_bytes($uploadLimit, (float) $settings['zip_size_threshold_ratio'], (int) $settings['max_zip_batch_bytes']),
        'max_items_per_batch' => (int) $settings['max_items_per_batch'],
        'max_zip_batch_bytes' => (int) $settings['max_zip_batch_bytes'],
        'thumbnail_sizes' => function_exists('Gallery\\Services\\thumbnail_sizes') ? array_values(array_map('intval', thumbnail_sizes())) : [300, 600, 800, 960, 1280, 1600],
        'thumbnail_formats' => $formats,
        'jpeg_quality' => function_exists('Gallery\\Services\\thumbnail_jpeg_quality') ? thumbnail_jpeg_quality() : 82,
        'webp_quality' => function_exists('Gallery\\Services\\thumbnail_webp_quality') ? thumbnail_webp_quality() : 82,
        'supported_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
    ];
}
