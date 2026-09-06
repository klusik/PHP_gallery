<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_migration/target_setup.php
 * Module Type: Service
 *
 * Purpose:
 *   Prepares the target gallery tree and applies gallery metadata.
 *
 * Responsibilities:
 *   - Create or reuse the target gallery tree before assets arrive
 *   - Map a source gallery identifier to its target row
 *   - Apply gallery metadata and flight-map data to the target
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
 * Create or update a target job from a source manifest.
 *
 * The selected target gallery is a receiving parent. The source root is always
 * created as a new child gallery below it, and source descendants are recreated
 * below that imported root. Persisted source-to-target ids make preparation
 * idempotent when the same migration job is resumed.
 *
 * @param int $targetGalleryId Receiving parent gallery id.
 * @param array $manifest Manifest value.
 * @param string $mode Mode value.
 * @return array Structured result data for the caller.
 */
function gallery_migration_prepare_target_job(int $targetGalleryId, array $manifest, string $mode): array
{
    mutation_schema_assert_available(
        gallery_migration_schema_status(),
        'gallery_migration.prepare_target_job',
        'Gallery migration requires the current gallery/image database schema. Run pending migrations first.',
        'Gallery migration is temporarily unavailable because the required database schema could not be verified. No migration job was started.'
    );
    $targetGallery = find_gallery($targetGalleryId, true) ?: find_gallery($targetGalleryId);
    if (!$targetGallery) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.target_missing', 'Target gallery was not found.'));
    }

    gallery_migration_validate_manifest($manifest);
    $compatibility = gallery_migration_compatibility_result((string) ($manifest['app_version'] ?? ''), gallery_migration_current_version());
    if (!$compatibility['ok']) {
        admin_log_event('warning', 'gallery_migration.version_mismatch', 'Gallery migration rejected because source and target versions differ.', [
            'target_gallery_id' => $targetGalleryId,
            'source_version' => $compatibility['source_version'],
            'target_version' => $compatibility['target_version'],
        ]);
        throw new RuntimeException($compatibility['message']);
    }

    $jobId = gallery_migration_job_id($targetGalleryId, $manifest);
    $existingJob = gallery_migration_load_existing_compatible_job($jobId, $targetGalleryId, $manifest);
    $packages = gallery_migration_package_plan($manifest);
    $job = [
        'job_id' => $jobId,
        'mode' => $mode,
        'target_gallery_id' => $targetGalleryId,
        'manifest' => $manifest,
        'compatibility' => $compatibility,
        'gallery_map' => is_array($existingJob) ? (array) ($existingJob['gallery_map'] ?? []) : [],
        'imported_root_gallery_id' => is_array($existingJob) ? (int) ($existingJob['imported_root_gallery_id'] ?? 0) : 0,
        'image_map' => is_array($existingJob) ? (array) ($existingJob['image_map'] ?? []) : [],
        'assets_received' => is_array($existingJob) ? (array) ($existingJob['assets_received'] ?? []) : [],
        'packages' => $packages,
        'created_at' => is_array($existingJob) ? (string) ($existingJob['created_at'] ?? now_sql()) : now_sql(),
        'updated_at' => now_sql(),
    ];

    // Save the resumable shell before creating folders. If the request ends
    // between two child creations, the next preparation continues from the map.
    gallery_migration_save_job($job);
    $job = gallery_migration_prepare_target_tree($job);
    $job = gallery_migration_sync_received_assets($job);

    $counts = (array) ($manifest['counts'] ?? []);
    $counts['galleries'] = (int) ($counts['galleries'] ?? count(gallery_migration_manifest_galleries($manifest)));
    $counts['images'] = (int) ($counts['images'] ?? array_sum(array_map(
        static fn (array $entry): int => count((array) ($entry['images'] ?? [])),
        gallery_migration_manifest_galleries($manifest)
    )));
    $counts['assets'] = count(gallery_migration_manifest_asset_refs($manifest));
    $counts['packages'] = count($packages);
    $counts['received'] = count((array) ($job['assets_received'] ?? []));

    admin_log_event('info', 'gallery_migration.started', 'Gallery migration job started.', [
        'mode' => $mode,
        'job_id' => $jobId,
        'target_gallery_id' => $targetGalleryId,
        'imported_root_gallery_id' => (int) ($job['imported_root_gallery_id'] ?? 0),
        'source_gallery_id' => (int) ($manifest['source_gallery_id'] ?? 0),
        'gallery_count' => $counts['galleries'],
        'asset_count' => $counts['assets'],
        'package_count' => $counts['packages'],
        'already_received' => $counts['received'],
        'resumed_existing_job' => is_array($existingJob),
    ]);

    return [
        'job_id' => $jobId,
        'compatibility' => $compatibility,
        'target_gallery_id' => $targetGalleryId,
        'imported_root_gallery_id' => (int) ($job['imported_root_gallery_id'] ?? 0),
        'gallery_ids' => array_values(array_map('intval', (array) ($job['gallery_map'] ?? []))),
        'manifest' => $manifest,
        'packages' => $packages,
        'assets' => gallery_migration_manifest_asset_refs_with_keys($manifest),
        'counts' => $counts,
        'status' => gallery_migration_job_status_payload($job),
    ];
}

/**
 * Ensure the target gallery tree exists for one migration job.
 *
 * @param array $job Job value.
 * @return array<string,mixed> Updated job with a durable gallery_map.
 */
function gallery_migration_prepare_target_tree(array $job): array
{
    $manifest = (array) ($job['manifest'] ?? []);
    $targetParentId = (int) ($job['target_gallery_id'] ?? 0);
    $rootSourceId = (int) ($manifest['source_gallery_id'] ?? 0);
    $galleryMap = (array) ($job['gallery_map'] ?? []);

    foreach (gallery_migration_manifest_galleries($manifest) as $entry) {
        $sourceId = (int) ($entry['source_id'] ?? 0);
        $parentSourceId = (int) ($entry['parent_source_id'] ?? 0);
        $parentTargetId = $sourceId === $rootSourceId
            ? $targetParentId
            : (int) ($galleryMap[(string) $parentSourceId] ?? 0);
        if ($parentTargetId <= 0 || !(find_gallery($parentTargetId, true) ?: find_gallery($parentTargetId))) {
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.gallery_tree_invalid', 'Migration gallery tree is invalid or cannot be resumed safely.'));
        }

        $mappedId = (int) ($galleryMap[(string) $sourceId] ?? 0);
        $mappedGallery = $mappedId > 0 ? (find_gallery($mappedId, true) ?: find_gallery($mappedId)) : null;
        if ($mappedGallery && (int) ($mappedGallery['parent_id'] ?? 0) !== $parentTargetId) {
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.gallery_tree_invalid', 'Migration gallery tree is invalid or cannot be resumed safely.'));
        }
        if (!$mappedGallery) {
            $metadata = (array) ($entry['gallery'] ?? []);
            $created = create_empty_gallery([
                'title' => trim((string) ($metadata['title'] ?? '')) !== '' ? (string) $metadata['title'] : (string) ($entry['folder_name'] ?? 'Gallery'),
                'description' => (string) ($metadata['description'] ?? ''),
                'parent_id' => $parentTargetId,
                'folder_name' => (string) ($entry['folder_name'] ?? ''),
                'visibility' => 'unpublished',
            ]);
            $mappedId = (int) ($created['id'] ?? 0);
            if ($mappedId <= 0) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.gallery_create_failed', 'Could not create an imported gallery.'));
            }
            $galleryMap[(string) $sourceId] = $mappedId;
            $job['gallery_map'] = $galleryMap;
            if ($sourceId === $rootSourceId) {
                $job['imported_root_gallery_id'] = $mappedId;
            }
            gallery_migration_save_job($job);
        }

        gallery_migration_apply_gallery_metadata($mappedId, ['gallery' => (array) ($entry['gallery'] ?? [])], $sourceId !== $rootSourceId, false);
        gallery_migration_apply_flight_map($mappedId, ['flight_map' => $entry['flight_map'] ?? null]);
    }

    $job['gallery_map'] = $galleryMap;
    $job['imported_root_gallery_id'] = (int) ($galleryMap[(string) $rootSourceId] ?? $job['imported_root_gallery_id'] ?? 0);
    gallery_migration_save_job($job);
    return $job;
}

/**
 * Resolve the target gallery that corresponds to one source gallery in a job.
 *
 * @param array $job Job value.
 * @param int $sourceGalleryId Source gallery id.
 * @return int Target gallery id.
 */
function gallery_migration_target_gallery_id(array $job, int $sourceGalleryId): int
{
    $manifest = (array) ($job['manifest'] ?? []);
    if ($sourceGalleryId <= 0) {
        $sourceGalleryId = (int) ($manifest['source_gallery_id'] ?? 0);
    }
    $targetId = (int) (((array) ($job['gallery_map'] ?? []))[(string) $sourceGalleryId] ?? 0);
    if ($targetId <= 0 || !(find_gallery($targetId, true) ?: find_gallery($targetId))) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.gallery_target_map_missing', 'Imported gallery mapping is missing from the migration job.'));
    }
    return $targetId;
}

/**
 * Apply imported gallery metadata without moving its target folder or copying secrets.
 *
 * @param int $targetGalleryId Target gallery id identifier.
 * @param array $manifest Manifest or gallery-entry value.
 * @param bool $includeSortOrder Preserve source sibling order value.
 * @param bool $includeVisibility Apply source visibility value.
 */
function gallery_migration_apply_gallery_metadata(int $targetGalleryId, array $manifest, bool $includeSortOrder = true, bool $includeVisibility = true): void
{
    mutation_schema_assert_available(
        gallery_migration_schema_status(),
        'gallery_migration.apply_gallery_metadata',
        'Gallery migration requires the current gallery/image database schema. Run pending migrations first.',
        'Gallery migration metadata could not be applied because the database schema could not be verified.'
    );
    $metadata = (array) ($manifest['gallery'] ?? []);
    $fields = [];
    $values = [];
    $allowed = [
        'title',
        'description',
        'sort_order',
        'visibility',
        'voting_enabled',
        'show_filenames',
        'gallery_date',
        'gallery_date_end',
        'description_layout',
        'count_badge_visibility',
        'picture_game_enabled',
        'gps_map_enabled',
        'grid_columns',
        'grid_rows',
        'grid_use_for_subgalleries',
        'thumbnail_min_size',
        'thumbnail_max_size',
        'background_source',
        'nsfw_enabled',
    ];

    foreach ($allowed as $column) {
        if (($column === 'sort_order' && !$includeSortOrder) || ($column === 'visibility' && !$includeVisibility)) {
            continue;
        }
        if (!array_key_exists($column, $metadata)) {
            continue;
        }
        if (!mutation_schema_optional_column_available('mutation.gallery_migration_gallery_metadata', 'galleries', $column, 'gallery_migration.apply_gallery_metadata')) {
            continue;
        }
        $fields[] = $column . ' = ?';
        $values[] = gallery_migration_gallery_column_value($column, $metadata[$column]);
    }

    if (array_key_exists('slug', $metadata)
        && mutation_schema_optional_column_available('mutation.gallery_migration_gallery_metadata', 'galleries', 'slug', 'gallery_migration.apply_gallery_metadata')) {
        $fields[] = 'slug = ?';
        $values[] = unique_slug(db(), (string) $metadata['slug'], $targetGalleryId);
    }
    if ($fields) {
        $fields[] = 'updated_at = ?';
        $values[] = now_sql();
        $values[] = $targetGalleryId;
        $stmt = db()->prepare('UPDATE galleries SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($values);
    }

    if (function_exists('Gallery\\Services\\sync_entity_tags')) {
        sync_entity_tags('gallery', $targetGalleryId, (string) ($metadata['tags'] ?? ''));
    }
    if (content_localization_schema_ready('gallery') && (array_key_exists('content_language', $metadata) || array_key_exists('translations', $metadata))) {
        content_save_localizations('gallery', $targetGalleryId, $metadata['content_language'] ?? null, $metadata['translations'] ?? []);
    }
}

/**
 * Normalize a gallery metadata value for SQL storage.
 *
 * @param string $column Column value.
 * @param mixed $value Value to process.
 * @return mixed Result value for the caller.
 */
function gallery_migration_gallery_column_value(string $column, mixed $value): mixed
{
    if ($column === 'visibility') {
        return gallery_visibility_storage_value((string) $value);
    }
    if (in_array($column, ['voting_enabled', 'show_filenames', 'picture_game_enabled', 'gps_map_enabled', 'grid_use_for_subgalleries', 'nsfw_enabled'], true)) {
        return !empty($value) ? 1 : 0;
    }
    if (in_array($column, ['sort_order', 'grid_columns', 'grid_rows', 'thumbnail_min_size', 'thumbnail_max_size'], true)) {
        return $value === null || $value === '' ? null : (int) $value;
    }
    if (in_array($column, ['gallery_date', 'gallery_date_end'], true)) {
        return gallery_date_sidecar_value($value);
    }

    return $value;
}

/**
 * Apply stored flight route data without resolving it again on the target.
 *
 * @param int $targetGalleryId Target gallery id identifier.
 * @param array $manifest Manifest value.
 */
function gallery_migration_apply_flight_map(int $targetGalleryId, array $manifest): void
{
    if (!function_exists('Gallery\\Services\\flight_map_schema_ready') || !flight_map_schema_ready()) {
        return;
    }
    $flightMap = $manifest['flight_map'] ?? null;
    if (!is_array($flightMap) || trim((string) ($flightMap['route_text'] ?? '')) === '') {
        return;
    }

    $resolved = json_decode((string) ($flightMap['resolved_points_json'] ?? '[]'), true);
    $unresolved = json_decode((string) ($flightMap['unresolved_points_json'] ?? '[]'), true);
    if (!is_array($resolved)) {
        $resolved = [];
    }
    if (!is_array($unresolved)) {
        $unresolved = [];
    }

    $now = now_sql();
    $stmt = db()->prepare("INSERT INTO gallery_flight_maps (
        gallery_id,
        map_source_type,
        route_text,
        resolved_points_json,
        unresolved_points_json,
        point_count,
        resolved_at,
        created_at,
        updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        map_source_type = VALUES(map_source_type),
        route_text = VALUES(route_text),
        resolved_points_json = VALUES(resolved_points_json),
        unresolved_points_json = VALUES(unresolved_points_json),
        point_count = VALUES(point_count),
        resolved_at = VALUES(resolved_at),
        updated_at = VALUES(updated_at)");
    $stmt->execute([
        $targetGalleryId,
        (string) ($flightMap['map_source_type'] ?? GALLERY_MAP_SOURCE_FLIGHT_PATH),
        (string) ($flightMap['route_text'] ?? ''),
        json_encode($resolved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($unresolved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        count($resolved),
        (string) ($flightMap['resolved_at'] ?? '') !== '' ? (string) $flightMap['resolved_at'] : $now,
        $now,
        $now,
    ]);
    if (function_exists('Gallery\\Services\\flight_map_clear_runtime_cache')) {
        flight_map_clear_runtime_cache();
    }
}
