<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/browser_uploads/manifest.php
 * Module Type: Service
 *
 * Purpose:
 *   Builds and validates the per-batch upload manifest.
 *
 * Responsibilities:
 *   - Build a manifest from parsed container entries
 *   - Verify manifest identity against the session and batch index
 *   - Require prepared thumbnails when the batch policy demands them
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
 * Decode and validate a browser upload manifest.
 *
 * @param array $entries Entries value.
 * @return array<string mixed>.
 */
function browser_upload_manifest_from_entries(array $entries): array
{
    $manifestJson = (string) ($entries['manifest.json'] ?? '');
    if ($manifestJson === '') {
        throw new RuntimeException(t('browser_upload.error_manifest_missing', 'The prepared upload package is missing its manifest.'));
    }
    $manifest = json_decode($manifestJson, true);
    if (!is_array($manifest) || !is_array($manifest['items'] ?? null)) {
        throw new RuntimeException(t('browser_upload.error_manifest_invalid', 'The prepared upload package manifest is invalid.'));
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
function browser_upload_validate_manifest_identity(array $manifest, string $sessionId, int $batchIndex): void
{
    $manifestSessionId = (string) ($manifest['upload_session_id'] ?? '');
    $manifestBatchIndex = array_key_exists('batch_index', $manifest) ? (int) $manifest['batch_index'] : -1;
    if ($manifestSessionId === '' || !hash_equals($sessionId, $manifestSessionId) || $manifestBatchIndex !== $batchIndex) {
        throw new RuntimeException(t('browser_upload.error_manifest_mismatch', 'The prepared upload package does not match the posted upload session.'));
    }
}

/**
 * Require a complete browser-prepared thumbnail set before storing originals.
 *
 * When the administrator explicitly selected browser-side processing, the server
 * must never accept a package that would leave missing thumbnails for the later
 * PHP warmup pipeline to generate. Validate the complete expected size/format
 * matrix while the ZIP is still only temporary request data.
 *
 * @param array $manifest Decoded browser package manifest.
 * @param array $entries Parsed ZIP entries keyed by relative path.
 */
function browser_upload_validate_required_thumbnail_manifest(array $manifest, array $entries): void
{
    $sizes = function_exists('Gallery\\Services\\thumbnail_sizes') ? array_values(array_unique(array_map('intval', thumbnail_sizes()))) : [];
    $formats = function_exists('Gallery\\Services\\thumbnail_policy_requested_formats') ? thumbnail_policy_requested_formats() : ['webp'];
    $formats = array_values(array_filter(array_map('strval', $formats), static fn (string $format): bool => in_array($format, ['jpg', 'webp'], true)));
    $items = browser_upload_manifest_items_in_source_order((array) ($manifest['items'] ?? []));

    foreach ($items as $manifestIndex => $item) {
        $variantIndex = [];
        foreach ((array) ($item['variants'] ?? []) as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $size = (int) ($variant['size'] ?? 0);
            $format = strtolower((string) ($variant['format'] ?? ''));
            $path = normalize_relative_path((string) ($variant['path'] ?? ''));
            if ($size <= 0 || !in_array($format, ['jpg', 'webp'], true) || $path === '') {
                continue;
            }
            $variantIndex[$size . ':' . $format] = $path;
        }

        $missing = [];
        foreach ($sizes as $size) {
            foreach ($formats as $format) {
                $key = $size . ':' . $format;
                $path = (string) ($variantIndex[$key] ?? '');
                if ($path === '' || !array_key_exists($path, $entries)) {
                    $missing[] = $key;
                }
            }
        }
        if ($missing !== []) {
            throw new BrowserUploadValidationException(
                t('browser_upload.error_prepared_thumbnails_incomplete', 'The browser-prepared upload package is missing required thumbnail variants. The server-side thumbnail fallback was not started.'),
                [
                    'validation_stage' => 'prepared_thumbnail_manifest_incomplete',
                    'manifest_index' => (int) ($item['_manifest_order_index'] ?? $manifestIndex),
                    'source_index' => browser_upload_manifest_source_index($item, $manifestIndex),
                    'missing_variants' => array_slice($missing, 0, 24),
                ]
            );
        }
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
function browser_upload_manifest_items_in_source_order(array $items): array
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
        $leftSource = browser_upload_manifest_source_index($left, (int) ($left['_manifest_order_index'] ?? 0));
        $rightSource = browser_upload_manifest_source_index($right, (int) ($right['_manifest_order_index'] ?? 0));
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
function browser_upload_manifest_source_index(array $item, int $fallback): int
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
function browser_upload_manifest_state_key(array $item, int $fallback): string
{
    return 'source-' . browser_upload_manifest_source_index($item, $fallback);
}
