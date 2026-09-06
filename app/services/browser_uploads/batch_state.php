<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/browser_uploads/batch_state.php
 * Module Type: Service
 *
 * Purpose:
 *   Owns resumable per-batch cache, state, and source ordering.
 *
 * Responsibilities:
 *   - Cache batch responses so a retried batch stays idempotent
 *   - Persist and reload per-batch state keyed by manifest hash
 *   - Preserve the original browser source order across batches
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
 *   - Path note: this file lives one directory deeper than the module entry file,
 *     so project-root paths must use dirname(__DIR__, 3), not dirname(__DIR__, 2).
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
 * Return a safe idempotency cache directory for acknowledged batches.
 *
 * @return string Text result for the caller.
 */
function browser_upload_batch_cache_dir(): string
{
    $configured = (string) (cms_config()['zip_cache_path'] ?? '');
    $base = $configured !== '' ? dirname($configured) : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'cache';
    $path = $base . DIRECTORY_SEPARATOR . 'browser_upload_batches';
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
function browser_upload_batch_cache_key(int $galleryId, string $sessionId, int $batchIndex): string
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
function browser_upload_cached_batch_response(int $galleryId, string $sessionId, int $batchIndex): ?array
{
    $path = browser_upload_batch_cache_dir() . DIRECTORY_SEPARATOR . browser_upload_batch_cache_key($galleryId, $sessionId, $batchIndex) . '.json';
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
function browser_upload_store_cached_batch_response(int $galleryId, string $sessionId, int $batchIndex, array $response): void
{
    $dir = browser_upload_batch_cache_dir();
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }
    $path = $dir . DIRECTORY_SEPARATOR . browser_upload_batch_cache_key($galleryId, $sessionId, $batchIndex) . '.json';
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
function browser_upload_batch_state_path(int $galleryId, string $sessionId, int $batchIndex): string
{
    return browser_upload_batch_cache_dir() . DIRECTORY_SEPARATOR . 'state-' . browser_upload_batch_cache_key($galleryId, $sessionId, $batchIndex) . '.json';
}

/**
 * Return the state path for upload-session source ordering.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $sessionId Session id identifier.
 * @return string Text result for the caller.
 */
function browser_upload_session_order_path(int $galleryId, string $sessionId): string
{
    $sessionId = preg_replace('/[^A-Za-z0-9_.-]/', '', $sessionId) ?: 'session';
    return browser_upload_batch_cache_dir() . DIRECTORY_SEPARATOR . 'order-' . hash('sha256', $galleryId . '|' . $sessionId) . '.json';
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
function browser_upload_session_sort_base(int $galleryId, string $sessionId): int
{
    $path = browser_upload_session_order_path($galleryId, $sessionId);
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
    $dir = browser_upload_batch_cache_dir();
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
function browser_upload_apply_source_sort_order(int $galleryId, array $sourceIndexByRelativePath, int $sortBase): int
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
function browser_upload_empty_batch_state(int $galleryId, string $sessionId, int $batchIndex, string $manifestHash): array
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
function browser_upload_load_batch_state(int $galleryId, string $sessionId, int $batchIndex, string $manifestHash): array
{
    $path = browser_upload_batch_state_path($galleryId, $sessionId, $batchIndex);
    if (!is_file($path)) {
        return browser_upload_empty_batch_state($galleryId, $sessionId, $batchIndex, $manifestHash);
    }
    $raw = @file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || (string) ($decoded['manifest_hash'] ?? '') !== $manifestHash || !is_array($decoded['items'] ?? null)) {
        return browser_upload_empty_batch_state($galleryId, $sessionId, $batchIndex, $manifestHash);
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
function browser_upload_store_batch_state(int $galleryId, string $sessionId, int $batchIndex, array $state): void
{
    $dir = browser_upload_batch_cache_dir();
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }
    $path = browser_upload_batch_state_path($galleryId, $sessionId, $batchIndex);
    @file_put_contents($path, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/**
 * Return the reusable filename from a prior partial batch state item.
 *
 * @param array $gallery Gallery row.
 * @param array $stateItem State item data.
 * @return string|null Text result for the caller.
 */
function browser_upload_reusable_state_filename(array $gallery, array $stateItem): ?string
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
