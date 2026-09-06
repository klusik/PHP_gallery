<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_migration/jobs.php
 * Module Type: Service
 *
 * Purpose:
 *   Resumable migration job identity, persistence, and status.
 *
 * Responsibilities:
 *   - Derive and validate job identifiers and their filesystem paths
 *   - Load and save job state so an interrupted transfer stays resumable
 *   - Report bounded job status and complete a finished migration
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
 *   - Loaded by app/services/gallery_migration.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/gallery_migration.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use CURLFile;
use RuntimeException;
use Throwable;
use ZipArchive;
use const Gallery\Core\CMS_VERSION;
use function Gallery\Controllers\admin_edit_gallery_tab_url;
use function Gallery\Core\cms_config;
use function Gallery\Core\cms_current_version;
use function Gallery\Core\db;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\is_supported_image_path;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;
use function Gallery\Core\path_inside;
use function Gallery\Core\unique_slug;

/**
 * Return the private job-state directory.
 *
 * @return string Text result for the caller.
 */
function gallery_migration_job_dir(): string
{
    return dirname(__DIR__, 3) . '/cache/gallery-migrations';
}

/**
 * Ensure the job-state directory exists.
 */
function gallery_migration_ensure_job_dir(): void
{
    $dir = gallery_migration_job_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.job_dir_failed', 'Could not create the gallery migration job directory.'));
    }
}

/**
 * Normalize a job identifier from a request or manifest.
 *
 * @param string $jobId Job id identifier.
 * @return string Text result for the caller.
 */
function gallery_migration_normalize_job_id(string $jobId): string
{
    $jobId = strtolower(trim($jobId));
    return preg_match('/^[a-f0-9]{16,64}$/', $jobId) === 1 ? $jobId : '';
}

/**
 * Build a stable job id for a target gallery and source manifest.
 *
 * @param int $targetGalleryId Target gallery id identifier.
 * @param array $manifest Manifest value.
 * @return string Text result for the caller.
 */
function gallery_migration_job_id(int $targetGalleryId, array $manifest): string
{
    $seed = implode('|', [
        'gallery-migration-v2',
        (string) $targetGalleryId,
        (string) ($manifest['source_instance_id'] ?? ''),
        (string) ($manifest['source_gallery_id'] ?? ''),
        (string) ($manifest['migration_id'] ?? ''),
    ]);

    return substr(hash('sha256', $seed), 0, 32);
}

/**
 * Return the path for one job-state file.
 *
 * @param string $jobId Job id identifier.
 * @return string Text result for the caller.
 */
function gallery_migration_job_path(string $jobId): string
{
    $jobId = gallery_migration_normalize_job_id($jobId);
    if ($jobId === '') {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.invalid_job', 'Invalid migration job id.'));
    }

    return gallery_migration_job_dir() . '/' . $jobId . '.json';
}

/**
 * Persist one migration job state.
 *
 * @param array $job Job value.
 */
function gallery_migration_save_job(array $job): void
{
    gallery_migration_ensure_job_dir();
    $jobId = gallery_migration_normalize_job_id((string) ($job['job_id'] ?? ''));
    if ($jobId === '') {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.invalid_job', 'Invalid migration job id.'));
    }

    $job['updated_at'] = now_sql();
    $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents(gallery_migration_job_path($jobId), $json, LOCK_EX) === false) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.job_write_failed', 'Could not save migration job state.'));
    }
}

/**
 * Load one migration job state.
 *
 * @param string $jobId Job id identifier.
 * @return array Structured result data for the caller.
 */
function gallery_migration_load_job(string $jobId): array
{
    $path = gallery_migration_job_path($jobId);
    if (!is_file($path)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.job_missing', 'Migration job was not found. Start the migration again.'));
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.job_corrupt', 'Migration job state is unreadable.'));
    }

    return $decoded;
}

/**
 * Load a compatible existing job without turning resume misses into hard errors.
 *
 * The migration id is derived from the source gallery content. When the admin
 * restarts the same transfer, the same job id is produced and the target
 * gallery can answer which assets it already accepted.
 *
 * @param string $jobId Stable job id for the target gallery and manifest.
 * @param int $targetGalleryId Target gallery id.
 * @param array $manifest Manifest value.
 * @return array<string mixed>|null Existing job, or null when it cannot be safely reused.
 */
function gallery_migration_load_existing_compatible_job(string $jobId, int $targetGalleryId, array $manifest): ?array
{
    try {
        $job = gallery_migration_load_job($jobId);
    } catch (Throwable) {
        return null;
    }

    if ((int) ($job['target_gallery_id'] ?? 0) !== $targetGalleryId) {
        return null;
    }

    $oldManifest = (array) ($job['manifest'] ?? []);
    if ((string) ($oldManifest['migration_id'] ?? '') !== (string) ($manifest['migration_id'] ?? '')) {
        return null;
    }

    return $job;
}

/**
 * Return a JSON-safe status payload for one target-side migration job.
 *
 * @param array $job Job value.
 * @param ?array $asset Asset value.
 * @return array<string,mixed> Status payload.
 */
function gallery_migration_job_status_payload(array $job, ?array $asset = null): array
{
    $manifest = (array) ($job['manifest'] ?? []);
    $received = (array) ($job['assets_received'] ?? []);
    $assetKeys = array_keys($received);
    $specificKey = $asset === null ? '' : gallery_migration_asset_key($asset);
    $receivedPackages = 0;
    foreach ((array) ($job['packages'] ?? []) as $package) {
        if (!is_array($package)) {
            continue;
        }
        $packageKeys = array_values(array_filter((array) ($package['asset_keys'] ?? []), 'is_string'));
        if ($packageKeys && count(array_filter($packageKeys, static fn (string $key): bool => isset($received[$key]))) === count($packageKeys)) {
            $receivedPackages++;
        }
    }

    return [
        'ok' => true,
        'action' => 'status',
        'job_id' => (string) ($job['job_id'] ?? ''),
        'mode' => (string) ($job['mode'] ?? ''),
        'target_gallery_id' => (int) ($job['target_gallery_id'] ?? 0),
        'imported_root_gallery_id' => (int) ($job['imported_root_gallery_id'] ?? 0),
        'gallery_ids' => array_values(array_map('intval', (array) ($job['gallery_map'] ?? []))),
        'received' => count($received),
        'total_assets' => count(gallery_migration_manifest_asset_refs($manifest)),
        'received_packages' => $receivedPackages,
        'total_packages' => count((array) ($job['packages'] ?? [])),
        'received_asset_keys' => array_values($assetKeys),
        'asset_key' => $specificKey,
        'asset_received' => $specificKey !== '' && isset($received[$specificKey]),
        'updated_at' => (string) ($job['updated_at'] ?? ''),
        'server_time' => now_sql(),
    ];
}

/**
 * Load, synchronize, and summarize one target-side migration job.
 *
 * @param string $jobId Migration job id.
 * @param int $targetGalleryId Target gallery id.
 * @param ?array $request Request data.
 * @return array<string mixed> JSON-safe status payload.
 */
function gallery_migration_job_status_response(string $jobId, int $targetGalleryId, ?array $request = null): array
{
    $job = gallery_migration_load_job($jobId);
    if ((int) ($job['target_gallery_id'] ?? 0) !== $targetGalleryId) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.job_target_mismatch', 'Migration job does not belong to this target gallery.'));
    }

    $job = gallery_migration_sync_received_assets($job);
    $manifest = (array) ($job['manifest'] ?? []);
    $asset = null;
    if (is_array($request)) {
        $asset = gallery_migration_manifest_asset($manifest, $request);
    }

    return gallery_migration_job_status_payload($job, $asset);
}

/**
 * Complete a migration job and refresh derived caches.
 *
 * @param string $jobId Job id identifier.
 * @param int $targetGalleryId Receiving parent gallery id.
 * @return array Structured result data for the caller.
 */
function gallery_migration_complete_job(string $jobId, int $targetGalleryId): array
{
    mutation_schema_assert_available(
        gallery_migration_schema_status(),
        'gallery_migration.complete_job',
        'Gallery migration requires the current gallery/image database schema. Run pending migrations first.',
        'Gallery migration completion is temporarily unavailable because the database schema could not be verified. The resumable job was left intact.'
    );
    $job = gallery_migration_load_job($jobId);
    if ((int) ($job['target_gallery_id'] ?? 0) !== $targetGalleryId) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.job_target_mismatch', 'Migration job does not belong to this target gallery.'));
    }
    $job = gallery_migration_sync_received_assets($job);

    $manifest = (array) ($job['manifest'] ?? []);
    $totalAssets = count(gallery_migration_manifest_asset_refs($manifest));
    $receivedAssets = count((array) ($job['assets_received'] ?? []));
    if ($receivedAssets < $totalAssets) {
        throw new RuntimeException(gallery_migration_t(
            'gallery_migration.error.incomplete_job',
            'Migration is incomplete: {received} of {total} assets are present on the target.',
            ['received' => $receivedAssets, 'total' => $totalAssets]
        ));
    }

    $imageMap = (array) ($job['image_map'] ?? []);
    $galleryIds = [];
    foreach (gallery_migration_manifest_galleries($manifest) as $entry) {
        $sourceGalleryId = (int) ($entry['source_id'] ?? 0);
        $mappedGalleryId = gallery_migration_target_gallery_id($job, $sourceGalleryId);
        $galleryIds[] = $mappedGalleryId;
        $metadata = (array) ($entry['gallery'] ?? []);
        gallery_migration_apply_gallery_metadata($mappedGalleryId, ['gallery' => $metadata], $sourceGalleryId !== (int) ($manifest['source_gallery_id'] ?? 0), true);
        $coverSourceId = (int) ($metadata['cover_source_id'] ?? 0);
        if ($coverSourceId > 0 && !empty($imageMap[(string) $coverSourceId])) {
            db()->prepare('UPDATE galleries SET cover_image_id = ?, updated_at = ? WHERE id = ?')->execute([
                (int) $imageMap[(string) $coverSourceId],
                now_sql(),
                $mappedGalleryId,
            ]);
        }
        $gallery = find_gallery($mappedGalleryId, true) ?: find_gallery($mappedGalleryId);
        if ($gallery) {
            write_gallery_sidecar($gallery);
        }
    }

    if (function_exists('Gallery\\Services\\regenerate_public_paths') && public_path_schema_ready()) {
        regenerate_public_paths();
    }
    if (function_exists('Gallery\\Services\\gallery_map_cache_clear_all')) {
        gallery_map_cache_clear_all();
    }

    $job['completed_at'] = now_sql();
    gallery_migration_save_job($job);

    $importedRootId = (int) ($job['imported_root_gallery_id'] ?? 0);
    $importedRoot = $importedRootId > 0 ? (find_gallery($importedRootId, true) ?: find_gallery($importedRootId)) : null;
    admin_log_event('info', 'gallery_migration.completed', 'Gallery migration job completed.', [
        'job_id' => (string) ($job['job_id'] ?? ''),
        'target_gallery_id' => $targetGalleryId,
        'imported_root_gallery_id' => $importedRootId,
        'gallery_count' => count($galleryIds),
        'assets_received' => $receivedAssets,
        'total_assets' => $totalAssets,
    ]);

    return [
        'ok' => true,
        'job_id' => (string) ($job['job_id'] ?? ''),
        'target_gallery_id' => $targetGalleryId,
        'target_parent_gallery_id' => $targetGalleryId,
        'imported_root_gallery_id' => $importedRootId,
        'gallery_ids' => array_values(array_unique($galleryIds)),
        'assets_received' => $receivedAssets,
        'total_assets' => $totalAssets,
        'gallery_url' => $importedRoot ? gallery_public_url($importedRoot) : '',
        'edit_url' => $importedRootId > 0 ? admin_edit_gallery_tab_url($importedRootId, 'admin-edit-api') : '',
    ];
}
