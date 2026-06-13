<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_dashboard.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides database-backed read helpers for the Admin dashboard.
 *
 * Responsibilities:
 *   - Keep Admin dashboard SQL outside the controller
 *   - Return normalized model data for dashboard rendering
 *   - Preserve partially migrated installation safety checks
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
 *   2026-05-18
 */

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;
use function Gallery\Core\db;
use function Gallery\Core\pending_migrations_exist;

/**
 * Return the total byte size of imported original gallery files.
 *
 * The dashboard intentionally reads the image metadata table here instead of
 * scanning the filesystem. The value therefore represents source files already
 * imported into the gallery index and excludes generated thumbnails, DNG display
 * masters, caches, and any other derivative files stored beside the gallery.
 *
 * @return int Integer result for the caller.
 */
function admin_dashboard_original_storage_bytes(): int
{
    try {
        // $row stores the aggregate as a scalar-compatible result from the images table.
        $row = db()->query('SELECT COALESCE(SUM(file_size), 0) AS original_bytes FROM images')->fetch();
        return max(0, (int) ($row['original_bytes'] ?? 0));
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Return admin dashboard gallery rows with only columns used by the table.
 *
 * Optional columns are selected only when their migrations are present. This
 * keeps partially upgraded installations safe while avoiding SELECT * in the
 * dashboard hot path.
 *
 * @param bool $accessReady Access ready value.
 * @param bool $gpsMapReady Gps map ready value.
 * @param bool $backgroundSourceReady Background source ready value.
 * @param bool $filenameDisplayReady Filename display ready value.
 * @param bool $votingReady Voting ready value.
 * @param bool $pictureGameReady Picture game ready value.
 * @param bool $publicPathReady Public path ready filesystem path.
 * @param bool $coverAssetReady Cover asset ready value.
 * @return array Structured result data for the caller.
 */
function admin_dashboard_gallery_rows(bool $accessReady, bool $gpsMapReady, bool $backgroundSourceReady, bool $filenameDisplayReady, bool $votingReady, bool $pictureGameReady, bool $publicPathReady, bool $coverAssetReady): array
{
    // $selects stores the explicit gallery columns required by dashboard rendering.
    $selects = [
        'g.id',
        'g.parent_id',
        'g.folder_path',
        'g.slug',
        'g.title',
        'g.sort_order',
        'g.visibility',
        'parent.title AS parent_title',
        'COALESCE(image_counts.image_count, 0) AS image_count',
    ];

    $selects[] = $publicPathReady ? 'g.url_path' : "'' AS url_path";
    $selects[] = $accessReady ? 'g.access_mode' : "'normal' AS access_mode";
    $selects[] = $accessReady ? 'g.access_listing' : "'listed' AS access_listing";
    $selects[] = $gpsMapReady ? 'g.gps_map_enabled' : '0 AS gps_map_enabled';
    $selects[] = $backgroundSourceReady ? 'g.background_source' : 'NULL AS background_source';
    $selects[] = $filenameDisplayReady ? 'g.show_filenames' : '0 AS show_filenames';
    $selects[] = $votingReady ? 'g.voting_enabled' : '0 AS voting_enabled';
    $selects[] = $pictureGameReady ? 'g.picture_game_enabled' : '0 AS picture_game_enabled';
    $selects[] = $coverAssetReady ? 'g.cover_image_path' : 'NULL AS cover_image_path';

    // $sql stores a one-pass gallery query with image counts pre-aggregated by gallery.
    $sql = 'SELECT ' . implode(', ', $selects) . "
        FROM galleries g
        LEFT JOIN galleries parent ON parent.id = g.parent_id
        LEFT JOIN (
            SELECT gallery_id, COUNT(id) AS image_count
            FROM images
            WHERE relative_path NOT LIKE '%/%'
            GROUP BY gallery_id
        ) image_counts ON image_counts.gallery_id = g.id
        ORDER BY COALESCE(g.parent_id, 0), g.sort_order, g.title";

    return db()->query($sql)->fetchAll();
}

/**
 * Build a cheap fingerprint for gallery hierarchy state used by parent-id repair.
 *
 * @return string Text result for the caller.
 */
function admin_dashboard_parent_sync_fingerprint(): string
{
    try {
        // $row stores aggregate gallery data that changes when indexed gallery rows change.
        $row = admin_render_profile_db('parent_sync_fingerprint_query', static fn (): array => db()->query("SELECT COUNT(*) AS gallery_count, COALESCE(MAX(id), 0) AS newest_id, COALESCE(MAX(updated_at), '') AS newest_updated_at, COALESCE(SUM(CHAR_LENGTH(folder_path)), 0) AS path_length_sum FROM galleries")->fetch() ?: []);
    } catch (Throwable) {
        return '';
    }

    return hash('sha256', implode('|', [
        (string) ($row['gallery_count'] ?? '0'),
        (string) ($row['newest_id'] ?? '0'),
        (string) ($row['newest_updated_at'] ?? ''),
        (string) ($row['path_length_sum'] ?? '0'),
    ]));
}

/**
 * Build the full read model consumed by the Admin dashboard view.
 *
 * @return array<string mixed>.
 */
function admin_dashboard_view_model(): array
{
    // Variable $pictureGameReady stores this steps working value.
    $pictureGameReady = admin_render_profile_schema('schema_picture_game', static fn (): bool => picture_game_schema_ready()) && (!function_exists('Gallery\\Services\\feature_flag_enabled') || (feature_flag_enabled('picture_game') && feature_flag_enabled('image_voting')));
    // Variable $gpsMapReady stores this steps working value.
    $gpsMapReady = admin_render_profile_schema('schema_exif_gps', static fn (): bool => exif_gps_schema_ready()) && (!function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('gallery_maps'));
    // $gpsMapOverrideReady stores whether EXIF/GPS display supports inherited per-gallery overrides.
    $gpsMapOverrideReady = $gpsMapReady && admin_render_profile_schema('schema_exif_gps_overrides', static fn (): bool => exif_gps_override_schema_ready());
    // Variable $votingReady stores this steps working value.
    $votingReady = admin_render_profile_schema('schema_gallery_voting', static fn (): bool => gallery_voting_schema_ready()) && (!function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('image_voting'));
    // Variable $filenameDisplayReady stores this steps working value.
    $filenameDisplayReady = admin_render_profile_schema('schema_filename_display', static fn (): bool => gallery_filename_display_schema_ready());
    // $galleryDateRangeReady stores whether gallery rows can store range end dates.
    $galleryDateRangeReady = admin_render_profile_schema('schema_gallery_date_ranges', static fn (): bool => gallery_date_range_schema_ready());
    // Variable $migrationPending stores this steps working value.
    $migrationPending = admin_render_profile_schema('schema_pending_migrations', static fn (): bool => pending_migrations_exist());
    // Variable $accessReady stores this steps working value.
    $accessReady = admin_render_profile_schema('schema_gallery_access', static fn (): bool => gallery_access_schema_ready());
    // $backgroundSourceReady stores whether gallery background source data can be read without optional-column errors.
    $backgroundSourceReady = admin_render_profile_schema('schema_background_source', static fn (): bool => gallery_background_source_schema_ready());
    // $publicPathReady stores whether clean public URL paths can be read directly from gallery rows.
    $publicPathReady = admin_render_profile_schema('schema_public_paths', static fn (): bool => public_path_schema_ready());
    // $coverAssetReady stores whether uploaded gallery cover assets can be shown in the admin gallery list.
    $coverAssetReady = admin_render_profile_schema('schema_cover_asset', static fn (): bool => gallery_cover_asset_schema_ready());
    // $flightNavdataReady stores whether route lookup data can be imported and read from the DB.
    $flightNavdataReady = admin_render_profile_schema('schema_flight_navdata', static fn (): bool => flight_map_navdata_schema_ready()) && (!function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('navigation_data'));
    // $flightNavdataStatus stores maintenance information for the admin navdata card.
    $flightNavdataStatus = $flightNavdataReady ? admin_render_profile_db('flight_navdata_status', static fn (): array => flight_map_navdata_status()) : [];
    // $exifGpsDefaultEnabled stores the global display default for galleries without explicit overrides.
    $exifGpsDefaultEnabled = $gpsMapOverrideReady ? exif_gps_default_enabled() : false;
    // $exifGpsOverrideCount stores the number of gallery rows with explicit EXIF/GPS overrides.
    $exifGpsOverrideCount = $gpsMapOverrideReady ? admin_render_profile_db('exif_gps_override_count', static fn (): int => exif_gps_gallery_override_count()) : 0;

    if ($pictureGameReady && $votingReady && admin_dashboard_self_heal_due('admin_dashboard_voting_game_sync_last', 300)) {
        // Self-heal voting/game state periodically instead of on every admin navigation.
        $repairedVotingGame = admin_render_profile_span('self_heal_voting_game_sync', static fn (): int => sync_gallery_voting_game_state());
        admin_render_profile_span('self_heal_voting_game_mark', static function (): void { admin_dashboard_mark_self_heal('admin_dashboard_voting_game_sync_last'); });
        if ($repairedVotingGame > 0) {
            admin_log_event('info', 'gallery.voting_game_synced', 'Admin dashboard repaired gallery voting/game settings.', [
                'gallery_count' => $repairedVotingGame,
            ]);
        }
    }

    if (admin_dashboard_parent_sync_needed()) {
        admin_render_profile_span('parent_id_sync', static function (): void { sync_gallery_parent_ids(); });
        admin_render_profile_span('parent_id_sync_store_fingerprint', static function (): void { admin_dashboard_store_parent_sync_fingerprint(); });
    }

    // Variable $galleries stores this steps working value.
    $galleries = admin_render_profile_db('dashboard_gallery_rows', static fn (): array => admin_dashboard_gallery_rows($accessReady, $gpsMapReady, $backgroundSourceReady, $filenameDisplayReady, $votingReady, $pictureGameReady, $publicPathReady, $coverAssetReady));
    admin_render_profile_set_counter('gallery_rows', count($galleries));
    // Variable $galleries stores the admin tree in display order, with manual sibling ordering respected.
    $galleries = admin_render_profile_span('order_gallery_tree', static fn (): array => admin_ordered_gallery_rows($galleries));
    admin_render_profile_set_counter('ordered_gallery_rows', count($galleries));
    // Variable $collapsedIds stores this steps working value.
    $collapsedIds = admin_render_profile_setting_read('collapsed_gallery_ids', static fn (): array => array_flip(collapsed_gallery_ids()));
    admin_render_profile_set_counter('collapsed_gallery_ids', count($collapsedIds));
    // $childrenByParent stores direct child ids once so row rendering does not rescan the full gallery list.
    $childrenByParent = admin_render_profile_span('gallery_children_index', static fn (): array => admin_gallery_children_by_parent($galleries));
    admin_render_profile_set_counter('parent_groups', count($childrenByParent));

    // Preview URLs may hit cover lookup helpers, so resolve them before handing rows to the view.
    foreach ($galleries as $index => $gallery) {
        $galleries[$index]['preview_url'] = admin_gallery_preview_url($gallery);
    }

    // $updatePending stores an intermediate value used by the surrounding gallery workflow.
    $updatePending = admin_render_profile_span('application_update_pending', static fn (): bool => application_update_pending());
    // $updateButtonClass stores an intermediate value used by the surrounding gallery workflow.
    $updateButtonClass = $updatePending ? 'button secondary is-update-pending' : 'button secondary';
    // $updateLabel stores an intermediate value used by the surrounding gallery workflow.
    $updateLabel = application_update_nav_label($updatePending);
    // $siteMaintenanceStatus stores the persisted cron-safe maintenance state for the media maintenance card.
    $siteMaintenanceStatus = admin_render_profile_setting_read('site_maintenance_status', static fn (): array => function_exists('Gallery\\Services\\site_maintenance_status') ? site_maintenance_status() : []);
    // $thumbnailSummary stores an intermediate value used by the surrounding gallery workflow.
    admin_render_profile_set_counter('thumbnail_maintenance_sample_limit', 1000);
    $thumbnailSummary = admin_render_profile_span('thumbnail_maintenance_summary_cached_read', static fn (): array => cached_thumbnail_maintenance_summary_if_available(null, 1000));
    // $lastThumbnailCheck stores an explicit full dry-run result when an admin requested one.
    $lastThumbnailCheck = function_exists('Gallery\\Services\\thumbnail_maintenance_last_check') ? admin_render_profile_setting_read('thumbnail_maintenance_last_check', static fn (): array => thumbnail_maintenance_last_check()) : [];
    if ($lastThumbnailCheck) {
        $thumbnailSummary = [
            'images_scanned' => (int) ($lastThumbnailCheck['images_scanned'] ?? 0),
            'images_with_missing' => (int) ($lastThumbnailCheck['images_with_missing'] ?? 0),
            'missing_variants' => (int) ($lastThumbnailCheck['missing_variants'] ?? 0),
            'webp_skipped' => (int) ($lastThumbnailCheck['webp_skipped'] ?? 0),
            'limited' => !empty($lastThumbnailCheck['limited']),
            'deferred' => false,
            'full_check' => true,
            'inventory_fingerprint' => (string) ($lastThumbnailCheck['inventory_fingerprint'] ?? ''),
        ];
    }
    // $originalStorageBytes stores the cheap database-only size of imported source files. Generated thumbnails and display derivatives are intentionally not scanned during normal dashboard rendering.
    $originalStorageBytes = admin_render_profile_db('dashboard_original_storage_bytes', static fn (): int => admin_dashboard_original_storage_bytes());
    // $originalStorageLabel stores a human-readable storage amount for the dashboard summary card.
    $originalStorageLabel = admin_dashboard_format_bytes($originalStorageBytes);
    // $databaseUsage stores a cheap information_schema estimate for DB capacity display.
    $databaseUsage = function_exists('Gallery\\Services\\admin_database_usage_summary') ? admin_render_profile_db('dashboard_database_usage', static fn (): array => admin_database_usage_summary()) : [];
    // $galleryDatabaseUsageBytes stores table-level DB storage for gallery/content metadata.
    $galleryDatabaseUsageBytes = !empty($databaseUsage['available']) ? max(0, (int) ($databaseUsage['gallery_bytes'] ?? 0)) : 0;
    // $databaseUsageBytes stores table-level DB storage for the whole app database.
    $databaseUsageBytes = !empty($databaseUsage['available']) ? max(0, (int) ($databaseUsage['total_bytes'] ?? 0)) : 0;
    // $galleryDatabaseUsageLabel stores a human-readable database storage amount for the dashboard summary card.
    $galleryDatabaseUsageLabel = !empty($databaseUsage['available']) ? admin_dashboard_format_bytes($galleryDatabaseUsageBytes) : '';
    // $databaseUsageLabel stores a human-readable database storage amount for all database tables.
    $databaseUsageLabel = !empty($databaseUsage['available']) ? admin_dashboard_format_bytes($databaseUsageBytes) : '';
    // $totalGalleries stores an intermediate value used by the surrounding gallery workflow.
    $totalGalleries = count($galleries);
    // $totalImages stores an intermediate value used by the surrounding gallery workflow.
    $totalImages = 0;
    // $unpublishedGalleries stores an intermediate value used by the surrounding gallery workflow.
    $unpublishedGalleries = 0;
    // $privateGalleries stores an intermediate value used by the surrounding gallery workflow.
    $privateGalleries = 0;
    foreach ($galleries as $gallery) {
        $totalImages += (int) ($gallery['image_count'] ?? 0);
        if (gallery_effective_visibility($gallery) === 'unpublished') {
            $unpublishedGalleries++;
        } elseif (gallery_effective_visibility($gallery) === 'private') {
            $privateGalleries++;
        }
    }
    // $missingThumbnailVariants stores an intermediate value used by the surrounding gallery workflow.
    $missingThumbnailVariants = (int) ($thumbnailSummary['missing_variants'] ?? 0);

    admin_render_profile_set_counter('thumbnail_missing_variants', $missingThumbnailVariants);
    admin_render_profile_set_counter('thumbnail_maintenance_deferred', !empty($thumbnailSummary['deferred']) ? 1 : 0);

    return [
        'picture_game_ready' => $pictureGameReady,
        'gps_map_ready' => $gpsMapReady,
        'gps_map_override_ready' => $gpsMapOverrideReady,
        'exif_gps_default_enabled' => $exifGpsDefaultEnabled,
        'exif_gps_override_count' => $exifGpsOverrideCount,
        'voting_ready' => $votingReady,
        'filename_display_ready' => $filenameDisplayReady,
        'gallery_date_range_ready' => $galleryDateRangeReady,
        'migration_pending' => $migrationPending,
        'access_ready' => $accessReady,
        'background_source_ready' => $backgroundSourceReady,
        'flight_navdata_ready' => $flightNavdataReady,
        'flight_navdata_status' => $flightNavdataStatus,
        'galleries' => $galleries,
        'collapsed_ids' => $collapsedIds,
        'children_by_parent' => $childrenByParent,
        'update_pending' => $updatePending,
        'update_button_class' => $updateButtonClass,
        'update_label' => $updateLabel,
        'thumbnail_summary' => $thumbnailSummary,
        'site_maintenance_status' => $siteMaintenanceStatus,
        'original_storage_label' => $originalStorageLabel,
        'gallery_database_usage_label' => $galleryDatabaseUsageLabel,
        'database_usage_label' => $databaseUsageLabel,
        'database_usage_available' => !empty($databaseUsage['available']),
        'total_galleries' => $totalGalleries,
        'total_images' => $totalImages,
        'unpublished_galleries' => $unpublishedGalleries,
        'private_galleries' => $privateGalleries,
        'missing_thumbnail_variants' => $missingThumbnailVariants,
    ];
}

/**
 * Build dashboard notice text from request flags.
 *
 * @param array $query Query value.
 * @param string $adminNotice Admin notice value.
 * @return array<int string>.
 */
function admin_dashboard_notice_messages(array $query, string $adminNotice): array
{
    $notices = [];
    if ($adminNotice !== '') {
        $notices[] = $adminNotice;
    }
    if (isset($query['deleted_galleries'])) {
        $notices[] = t('admin.dashboard.notice_deleted_galleries', 'Deleted {count} gallery folder(s).', ['count' => (int) $query['deleted_galleries']]);
    } elseif (isset($query['delete_error'])) {
        $notices[] = t('admin.dashboard.notice_delete_failed', 'Gallery delete failed:') . ' ' . (string) $query['delete_error'];
    }
    if (isset($query['devmode_saved'])) {
        $notices[] = t('admin.dashboard.notice_devmode_saved', 'Dev mode setting saved.');
    }
    if (isset($query['url_rewrite_saved'])) {
        $notices[] = t('admin.dashboard.notice_url_rewrite_saved', 'URL rewrite setting saved.');
    }
    if (isset($query['paths_regenerated'])) {
        $notices[] = t('admin.dashboard.notice_paths_regenerated', 'Regenerated clean public paths. Updated {gallery_count} gallery path(s) and {image_count} image path(s).', [
            'gallery_count' => (int) ($query['gallery_paths'] ?? 0),
            'image_count' => (int) ($query['image_paths'] ?? 0),
        ]);
    } elseif (isset($query['paths_error'])) {
        $notices[] = t('admin.dashboard.notice_paths_failed', 'Path regeneration failed:') . ' ' . (string) $query['paths_error'];
    }
    if (isset($query['migrations_ran'])) {
        $notices[] = t('admin.dashboard.notice_migrations_applied', 'Applied migrations:') . ' ' . (string) $query['migrations_ran'] . '.';
    } elseif (isset($query['migrations_current'])) {
        $notices[] = t('admin.dashboard.notice_database_current', 'Database is already current.');
    } elseif (isset($query['migration_failed'])) {
        $notices[] = t('admin.dashboard.notice_migration_failed', 'Migration failed:') . ' ' . (string) $query['migration_failed'];
    }

    return $notices;
}

/**
 * Format a byte count for compact dashboard display.
 *
 * @param int|float $bytes Bytes value.
 * @param int $precision Precision value.
 * @return string Text result for the caller.
 */
function admin_dashboard_format_bytes(int|float $bytes, int $precision = 1): string
{
    // Reuse the telemetry formatter when it is already available so byte labels
    // stay consistent across admin reports and the dashboard.
    if (function_exists('telemetry_format_bytes')) {
        return telemetry_format_bytes($bytes, $precision);
    }

    // $normalizedBytes stores a non-negative float so invalid values never leak
    // into the rendered dashboard.
    $normalizedBytes = max(0.0, (float) $bytes);
    // $units stores the compact units used by the admin dashboard metric cards.
    $units = ['B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB'];
    // $unitIndex stores the selected unit position after scaling by powers of 1024.
    $unitIndex = 0;

    while ($normalizedBytes >= 1024 && $unitIndex < count($units) - 1) {
        $normalizedBytes /= 1024;
        $unitIndex++;
    }

    if ($unitIndex === 0) {
        return number_format($normalizedBytes, 0) . ' ' . $units[$unitIndex];
    }

    return number_format($normalizedBytes, $precision) . ' ' . $units[$unitIndex];
}

/**
 * Return direct child gallery ids indexed by parent id for dashboard rendering.
 *
 * @param array $rows Rows to process.
 * @return array<int array<int, int>>.
 */
function admin_gallery_children_by_parent(array $rows): array
{
    // $childrenByParent stores direct child ids by normalized parent id.
    $childrenByParent = [];
    foreach ($rows as $row) {
        // $parentId stores zero for root galleries and the parent id for subgalleries.
        $parentId = (int) ($row['parent_id'] ?? 0);
        $childrenByParent[$parentId][] = (int) ($row['id'] ?? 0);
    }
    return $childrenByParent;
}

/**
 * Return a small gallery preview URL for the admin gallery table.
 *
 * The dashboard uses existing generated thumbnails when available and falls back
 * through the same cover-selection rules used by public gallery cards. Nothing
 * is generated while rendering the table, so repeated admin navigation stays
 * cheap.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return string Text result for the caller.
 */
function admin_gallery_preview_url(array $gallery): string
{
    admin_render_profile_count('preview_requests');

    return admin_render_profile_span('gallery_preview_url', static function () use ($gallery): string {
        // $coverAssetUrl stores an uploaded gallery-specific cover asset when the optional column exists.
        $coverAssetUrl = gallery_cover_asset_url($gallery, false);
        if ($coverAssetUrl !== '') {
            admin_render_profile_count('preview_cover_asset_hits');
            return $coverAssetUrl;
        }

        // $cover stores the explicit or first direct image for this gallery.
        $cover = admin_render_profile_db('preview_direct_cover_lookup', static fn (): ?array => gallery_cover_image((int) ($gallery['id'] ?? 0), false));
        if ($cover) {
            admin_render_profile_count('preview_direct_cover_hits');
            return admin_render_profile_span('preview_direct_thumbnail_url', static fn (): string => thumbnail_url($cover, 300));
        }

        foreach (admin_render_profile_db('preview_collage_cover_lookup', static fn (): array => gallery_cover_collage_images((int) ($gallery['id'] ?? 0), false, 1)) as $descendantCover) {
            admin_render_profile_count('preview_collage_cover_hits');
            return admin_render_profile_span('preview_collage_thumbnail_url', static fn (): string => thumbnail_url($descendantCover, 300));
        }

        admin_render_profile_count('preview_empty');
        return '';
    });
}

/**
 * Return true when a periodic dashboard repair task may run again.
 *
 * @param string $settingKey Setting key value.
 * @param int $ttlSeconds Ttl seconds value.
 * @return bool True when the condition matches.
 */
function admin_dashboard_self_heal_due(string $settingKey, int $ttlSeconds): bool
{
    // $lastRun stores the Unix timestamp for the last successful repair attempt.
    $lastRun = (int) admin_render_profile_setting_read('self_heal_last_run_setting', static fn (): string => app_setting($settingKey, '0'));
    return $lastRun <= 0 || time() - $lastRun >= max(60, $ttlSeconds);
}

/**
 * Remember that a periodic dashboard repair task was attempted.
 *
 * @param string $settingKey Setting key value.
 */
function admin_dashboard_mark_self_heal(string $settingKey): void
{
    admin_render_profile_setting_write('self_heal_last_run_setting_write', static function () use ($settingKey): void { set_app_setting($settingKey, (string) time()); });
}

/**
 * Return true when dashboard parent-id repair should run for current gallery rows.
 *
 * @return bool True when the condition matches.
 */
function admin_dashboard_parent_sync_needed(): bool
{
    // $fingerprint stores the current gallery hierarchy fingerprint.
    $fingerprint = admin_dashboard_parent_sync_fingerprint();
    if ($fingerprint === '') {
        return true;
    }
    return !hash_equals((string) admin_render_profile_setting_read('parent_sync_fingerprint_setting', static fn (): string => app_setting('admin_dashboard_parent_sync_fingerprint', '')), $fingerprint);
}

/**
 * Store the current parent-id repair fingerprint after synchronization.
 */
function admin_dashboard_store_parent_sync_fingerprint(): void
{
    // $fingerprint stores the post-repair gallery hierarchy fingerprint.
    $fingerprint = admin_dashboard_parent_sync_fingerprint();
    if ($fingerprint !== '') {
        admin_render_profile_setting_write('parent_sync_fingerprint_setting_write', static function () use ($fingerprint): void { set_app_setting('admin_dashboard_parent_sync_fingerprint', $fingerprint); });
    }
}

/**
 * Return gallery rows in the same tree order used by the Admin table.
 *
 * SQL can sort direct siblings by sort_order, but it cannot cheaply emit the
 * full nested tree in display order on both MySQL and MariaDB without recursive
 * CTE differences. This helper keeps ordering deterministic in PHP: root rows
 * are sorted by sort_order and title, then each direct child list is sorted the
 * same way before being appended below its parent.
 *
 * @param array $rows Raw gallery rows from the Admin dashboard query.
 * @return array Gallery rows flattened in visible tree order.
 */
function admin_ordered_gallery_rows(array $rows): array
{
    // Variable $childrenByParent stores direct children indexed by their parent id.
    $childrenByParent = [];
    foreach ($rows as $row) {
        // Variable $parentKey stores zero for root galleries and the parent id for subgalleries.
        $parentKey = (int) ($row['parent_id'] ?? 0);
        $childrenByParent[$parentKey][] = $row;
    }

    foreach ($childrenByParent as $parentKey => $children) {
        usort($children, static function (array $left, array $right): int {
            // Variable $sortCompare stores the numeric sibling order comparison.
            $sortCompare = (int) ($left['sort_order'] ?? 0) <=> (int) ($right['sort_order'] ?? 0);
            if ($sortCompare !== 0) {
                return $sortCompare;
            }
            // Variable $titleCompare stores the stable human fallback when sort_order values match.
            $titleCompare = strnatcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
            if ($titleCompare !== 0) {
                return $titleCompare;
            }
            return (int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0);
        });
        $childrenByParent[$parentKey] = $children;
    }

    // Variable $orderedRows stores the final flattened admin tree.
    $orderedRows = [];

    /**
     * Appends descendants for one parent id.
     *
     * @param int $parentId Parent id whose children should be appended.
     * @return void
     */
    $appendChildren = static function (int $parentId) use (&$appendChildren, &$orderedRows, $childrenByParent): void {
        foreach ($childrenByParent[$parentId] ?? [] as $row) {
            $orderedRows[] = $row;
            $appendChildren((int) $row['id']);
        }
    };

    $appendChildren(0);
    return $orderedRows;
}
