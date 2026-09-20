<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/admin_gallery_report.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns bounded read-only database operations used by the Admin gallery report.
 *
 * Responsibilities:
 *   - Read gallery, image, tag, vote, feature, and Admin-log report data
 *   - Read bounded image batches with migration-aware optional columns
 *   - Enumerate database tables and exact row counts for maintenance reports
 *   - Keep dynamic identifiers and optional SQL expressions model-owned
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
 *   - Report services pass semantic flags and limits, never SQL fragments.
 */

declare(strict_types=1);

namespace Gallery\Models;

require_once dirname(__DIR__) . '/policy_constants.php';

use PDO;
use Throwable;
use const Gallery\Core\ADMIN_GALLERY_REPORT_MAX_BATCH_SIZE;
use const Gallery\Core\ADMIN_GALLERY_REPORT_ROW_LIMITS;
use function Gallery\Core\db;

/** Execute a model-owned report query and return rows with failure isolation. */
function admin_gallery_report_model_rows(string $sql, array $params = []): array
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

/** Execute a model-owned scalar query with failure isolation. */
function admin_gallery_report_model_scalar_int(string $sql, array $params = []): int
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return (int) ($stmt->fetchColumn() ?: 0);
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Read core gallery structure counts and bounded ranking rows.
 * @param bool $hasDates Whether both gallery date columns were verified available.
 * @param bool $hasGpsMap Whether the GPS-map override column was verified available.
 * @return array<string,mixed> Counts, label/count visibility/access groups and largest gallery rows; optional date/GPS fields are omitted without their capabilities.
 */
function admin_gallery_report_model_gallery_summary(bool $hasDates, bool $hasGpsMap): array
{
    $summary = [
        'total' => admin_gallery_report_model_scalar_int('SELECT COUNT(*) FROM galleries'),
        'root_count' => admin_gallery_report_model_scalar_int('SELECT COUNT(*) FROM galleries WHERE parent_id IS NULL'),
        'nested_count' => admin_gallery_report_model_scalar_int('SELECT COUNT(*) FROM galleries WHERE parent_id IS NOT NULL'),
        // Keep empty_count as a compatibility alias for historical report readers.
        'empty_count' => admin_gallery_report_model_scalar_int('SELECT COUNT(*) FROM galleries g WHERE NOT EXISTS (SELECT 1 FROM images i WHERE i.gallery_id = g.id)'),
        'zero_direct_image_count' => admin_gallery_report_model_scalar_int('SELECT COUNT(*) FROM galleries g WHERE NOT EXISTS (SELECT 1 FROM images i WHERE i.gallery_id = g.id)'),
        'empty_leaf_count' => admin_gallery_report_model_scalar_int('SELECT COUNT(*) FROM galleries g WHERE NOT EXISTS (SELECT 1 FROM images i WHERE i.gallery_id = g.id) AND NOT EXISTS (SELECT 1 FROM galleries c WHERE c.parent_id = g.id)'),
        'structural_container_count' => admin_gallery_report_model_scalar_int('SELECT COUNT(*) FROM galleries g WHERE NOT EXISTS (SELECT 1 FROM images i WHERE i.gallery_id = g.id) AND EXISTS (SELECT 1 FROM galleries c WHERE c.parent_id = g.id)'),
        'visibility_rows' => admin_gallery_report_model_rows('SELECT visibility AS label, COUNT(*) AS count FROM galleries GROUP BY visibility ORDER BY count DESC'),
        'access_rows' => admin_gallery_report_model_rows('SELECT access_mode AS label, COUNT(*) AS count FROM galleries GROUP BY access_mode ORDER BY count DESC'),
        'listing_rows' => admin_gallery_report_model_rows('SELECT access_listing AS label, COUNT(*) AS count FROM galleries GROUP BY access_listing ORDER BY count DESC'),
        'image_visibility_rows' => admin_gallery_report_model_rows('SELECT visibility AS label, COUNT(*) AS count FROM images GROUP BY visibility ORDER BY count DESC'),
        'largest_rows' => admin_gallery_report_model_rows('SELECT g.id, g.title, g.folder_path, g.visibility, COUNT(i.id) AS image_count, COALESCE(SUM(COALESCE(i.file_size, 0)), 0) AS source_bytes FROM galleries g LEFT JOIN images i ON i.gallery_id = g.id GROUP BY g.id, g.title, g.folder_path, g.visibility ORDER BY image_count DESC, source_bytes DESC LIMIT ' . ADMIN_GALLERY_REPORT_ROW_LIMITS['galleries']),
    ];
    if ($hasDates) {
        $summary['dated_gallery_count'] = admin_gallery_report_model_scalar_int('SELECT COUNT(*) FROM galleries WHERE date_start IS NOT NULL OR date_end IS NOT NULL');
    }
    if ($hasGpsMap) {
        $summary['gps_map_override_rows'] = admin_gallery_report_model_rows('SELECT gps_map_enabled AS label, COUNT(*) AS count FROM galleries GROUP BY gps_map_enabled ORDER BY gps_map_enabled ASC');
    }
    return $summary;
}

/** Return gallery id/parent rows used to calculate nesting depth. */
function admin_gallery_report_model_gallery_parent_rows(): array
{
    return admin_gallery_report_model_rows('SELECT id, parent_id FROM galleries');
}

/** Return one operational detail row per gallery. */
function admin_gallery_report_model_gallery_detail_rows(array $capabilities): array
{
    $hasGpsCoordinates = !empty($capabilities['gps_coordinates']);
    $hasDates = !empty($capabilities['dates']);
    $hasGpsMap = !empty($capabilities['gps_map']);
    $hasPictureGame = !empty($capabilities['picture_game']);

    $selects = [
        'g.id', 'g.parent_id', 'g.title', 'g.folder_path', 'g.slug', 'g.visibility', 'g.access_mode', 'g.access_listing',
        'g.sort_order', 'g.created_at', 'g.updated_at',
        'COUNT(i.id) AS image_count',
        'COALESCE(SUM(COALESCE(i.file_size, 0)), 0) AS source_bytes',
        $hasGpsCoordinates ? 'SUM(CASE WHEN i.gps_lat IS NOT NULL AND i.gps_lng IS NOT NULL THEN 1 ELSE 0 END) AS gps_images' : '0 AS gps_images',
        $hasDates ? 'g.date_start AS date_start' : 'NULL AS date_start',
        $hasDates ? 'g.date_end AS date_end' : 'NULL AS date_end',
        $hasGpsMap ? 'g.gps_map_enabled AS gps_map_enabled' : 'NULL AS gps_map_enabled',
        $hasPictureGame ? 'g.picture_game_enabled AS picture_game_enabled' : 'NULL AS picture_game_enabled',
    ];
    $groupBy = [
        'g.id', 'g.parent_id', 'g.title', 'g.folder_path', 'g.slug', 'g.visibility', 'g.access_mode', 'g.access_listing',
        'g.sort_order', 'g.created_at', 'g.updated_at',
    ];
    if ($hasDates) {
        $groupBy[] = 'g.date_start';
        $groupBy[] = 'g.date_end';
    }
    if ($hasGpsMap) {
        $groupBy[] = 'g.gps_map_enabled';
    }
    if ($hasPictureGame) {
        $groupBy[] = 'g.picture_game_enabled';
    }

    return admin_gallery_report_model_rows(
        'SELECT ' . implode(', ', $selects)
        . ' FROM galleries g LEFT JOIN images i ON i.gallery_id = g.id GROUP BY '
        . implode(', ', $groupBy)
        . ' ORDER BY g.folder_path ASC'
    );
}

/**
 * Read only positively available tag sections.
 * @param bool $hasTags Verified tag-table availability.
 * @param bool $hasGalleryTags Verified tag and gallery-tag storage availability.
 * @param bool $hasImageTags Verified tag and image-tag storage availability.
 * @return array{tag_count:int,gallery_tag_rows:list<array<string,mixed>>,image_tag_rows:list<array<string,mixed>>} Total and bounded label/count usage groups, empty where storage is unavailable.
 */
function admin_gallery_report_model_tag_summary(bool $hasTags, bool $hasGalleryTags, bool $hasImageTags): array
{
    return [
        'tag_count' => $hasTags ? admin_gallery_report_model_scalar_int('SELECT COUNT(*) FROM tags') : 0,
        'gallery_tag_rows' => $hasGalleryTags ? admin_gallery_report_model_rows('SELECT t.name AS label, COUNT(gt.gallery_id) AS count FROM tags t INNER JOIN gallery_tags gt ON gt.tag_id = t.id GROUP BY t.id, t.name ORDER BY count DESC, t.name ASC LIMIT ' . ADMIN_GALLERY_REPORT_ROW_LIMITS['tag_usage']) : [],
        'image_tag_rows' => $hasImageTags ? admin_gallery_report_model_rows('SELECT t.name AS label, COUNT(it.image_id) AS count FROM tags t INNER JOIN image_tags it ON it.tag_id = t.id GROUP BY t.id, t.name ORDER BY count DESC, t.name ASC LIMIT ' . ADMIN_GALLERY_REPORT_ROW_LIMITS['tag_usage']) : [],
    ];
}

/** Return image and Picture Game vote group rows. */
function admin_gallery_report_model_vote_summary(bool $hasImageVotes, bool $hasPictureGameVotes): array
{
    return [
        'image_vote_rows' => $hasImageVotes ? admin_gallery_report_model_rows('SELECT vote AS label, COUNT(*) AS count FROM image_votes GROUP BY vote ORDER BY vote DESC') : [],
        'picture_game_vote_rows' => $hasPictureGameVotes ? admin_gallery_report_model_rows("SELECT CASE WHEN winner_image_id IS NULL THEN 'shown_without_vote' ELSE 'completed_vote' END AS label, COUNT(*) AS count FROM picture_game_votes GROUP BY CASE WHEN winner_image_id IS NULL THEN 'shown_without_vote' ELSE 'completed_vote' END ORDER BY label ASC") : [],
    ];
}

/**
 * Read a bounded report snapshot of feature-related persisted settings.
 * @return list<array{setting_key:string,setting_value:string|null,updated_at:string|null}> Settings matching the existing report vocabulary, empty on query failure.
 */
function admin_gallery_report_model_feature_settings(): array
{
    return admin_gallery_report_model_rows("SELECT setting_key, setting_value, updated_at FROM app_settings WHERE setting_key LIKE 'feature_%' OR setting_key LIKE '%enabled%' OR setting_key LIKE '%telemetry%' OR setting_key LIKE '%thumbnail%' ORDER BY setting_key ASC LIMIT " . ADMIN_GALLERY_REPORT_ROW_LIMITS['feature_settings']);
}

/**
 * Read Admin log counts and bounded error/event rankings.
 * @param bool $hasSeverity Verified severity-column availability.
 * @param bool $hasCategory Verified category-column availability.
 * @return array<string,mixed> Total/seven-day counts, label/count groups, recent error details and event rankings; unavailable optional groups are empty.
 */
function admin_gallery_report_model_admin_log_summary(bool $hasSeverity, bool $hasCategory): array
{
    $severitySelect = $hasSeverity ? 'severity' : "'' AS severity";
    $errorPredicate = "level IN ('error','critical')" . ($hasSeverity ? " OR severity IN ('error','critical')" : '');
    return [
        'total' => admin_gallery_report_model_scalar_int('SELECT COUNT(*) FROM admin_logs'),
        'last_7_days' => admin_gallery_report_model_scalar_int('SELECT COUNT(*) FROM admin_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'),
        'level_rows' => admin_gallery_report_model_rows('SELECT level AS label, COUNT(*) AS count FROM admin_logs GROUP BY level ORDER BY count DESC'),
        'severity_rows' => $hasSeverity ? admin_gallery_report_model_rows('SELECT severity AS label, COUNT(*) AS count FROM admin_logs GROUP BY severity ORDER BY count DESC') : [],
        'category_rows' => $hasCategory ? admin_gallery_report_model_rows('SELECT category AS label, COUNT(*) AS count FROM admin_logs GROUP BY category ORDER BY count DESC') : [],
        'recent_errors' => admin_gallery_report_model_rows('SELECT created_at, level, ' . $severitySelect . ', event_key, message, route_name FROM admin_logs WHERE ' . $errorPredicate . ' ORDER BY created_at DESC LIMIT ' . ADMIN_GALLERY_REPORT_ROW_LIMITS['log_errors']),
        'top_events' => admin_gallery_report_model_rows('SELECT event_key AS label, COUNT(*) AS count FROM admin_logs GROUP BY event_key ORDER BY count DESC LIMIT ' . ADMIN_GALLERY_REPORT_ROW_LIMITS['log_events']),
    ];
}

/**
 * Return the bounded largest-source-image section with owning gallery labels.
 * @param int $limit Requested row count, clamped to the centralized top-image ceiling.
 * @return list<array{id:int,filename:string,relative_path:string,mime_type:string|null,file_size:int|null,width:int|null,height:int|null,visibility:string,gallery_title:string,gallery_folder_path:string}> Source-image rows; empty on query failure.
 */
function admin_gallery_report_model_largest_images(int $limit): array
{
    $limit = max(1, min(ADMIN_GALLERY_REPORT_ROW_LIMITS['max_top_images'], $limit));
    return admin_gallery_report_model_rows('SELECT i.id, i.filename, i.relative_path, i.mime_type, i.file_size, i.width, i.height, i.visibility, g.title AS gallery_title, g.folder_path AS gallery_folder_path FROM images i INNER JOIN galleries g ON g.id = i.gallery_id ORDER BY COALESCE(i.file_size, 0) DESC, i.id DESC LIMIT ' . $limit);
}

/** Return total image count. */
function admin_gallery_report_model_image_count(): int
{
    return admin_gallery_report_model_scalar_int('SELECT COUNT(*) FROM images');
}

/**
 * Read the next monotonic image-ID batch with positively verified optional columns.
 * @param int $lastImageId Exclusive lower image-ID bound; negative inputs become zero.
 * @param int $limit Requested batch size, sharing the service's immutable ceiling.
 * @param array<string,bool> $capabilities Verified image-column availability keyed by column identifier.
 * @return list<array<string,mixed>> Image/gallery identity, storage, dimensions, timestamps and optional EXIF/GPS fields; missing optional values are null and query failure returns an empty list.
 */
function admin_gallery_report_model_image_rows_after_id(int $lastImageId, int $limit, array $capabilities): array
{
    $lastImageId = max(0, $lastImageId);
    // Keep this clamp aligned with the report service. A lower model-only cap
    // silently multiplies Ajax requests and can hit shared-hosting request limits.
    $limit = max(1, min(ADMIN_GALLERY_REPORT_MAX_BATCH_SIZE, $limit));
    /**
     * Select a fixed internal optional column or an explicit null projection.
     * @param string $column Internal image-column identifier with verified capability.
     * @param string $alias Internal result-field identifier.
     * @return string Model-owned SELECT expression; neither identifier is caller-supplied SQL.
     */
    $optional = static function (string $column, string $alias) use ($capabilities): string {
        return !empty($capabilities[$column]) ? 'i.' . $column . ' AS ' . $alias : 'NULL AS ' . $alias;
    };
    $derivative = !empty($capabilities['thumbnail_derivative_version']) ? 'COALESCE(i.thumbnail_derivative_version, 1)' : '1';
    $columns = [
        'i.id AS image_id',
        'i.gallery_id AS image_gallery_id',
        'i.relative_path',
        'i.filename',
        'i.mime_type',
        'COALESCE(i.file_size, 0) AS file_size',
        'i.width',
        'i.height',
        'i.visibility AS image_visibility',
        'i.modified_at',
        'i.created_at AS image_created_at',
        'i.updated_at AS image_updated_at',
        $derivative . ' AS thumbnail_derivative_version',
        'g.id AS gallery_id',
        'g.title AS gallery_title',
        'g.folder_path AS gallery_folder_path',
        'g.visibility AS gallery_visibility',
        $optional('exif_taken_at', 'exif_taken_at'),
        $optional('exif_camera_make', 'exif_camera_make'),
        $optional('exif_camera_model', 'exif_camera_model'),
        $optional('exif_lens_model', 'exif_lens_model'),
        $optional('exif_focal_length', 'exif_focal_length'),
        $optional('exif_aperture', 'exif_aperture'),
        $optional('exif_exposure_time', 'exif_exposure_time'),
        $optional('exif_iso', 'exif_iso'),
        $optional('gps_lat', 'gps_lat'),
        $optional('gps_lng', 'gps_lng'),
        $optional('gps_altitude', 'gps_altitude'),
        $optional('gps_extracted_at', 'gps_extracted_at'),
        $optional('exif_orientation', 'exif_orientation'),
    ];
    try {
        $stmt = db()->prepare('SELECT ' . implode(', ', $columns) . ' FROM images i INNER JOIN galleries g ON g.id = i.gallery_id WHERE i.id > ? ORDER BY i.id LIMIT ?');
        $stmt->bindValue(1, $lastImageId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

/** Return MySQL or MariaDB server version. */
function admin_gallery_report_model_mysql_version(): string
{
    try {
        return (string) (db()->query('SELECT VERSION()')->fetchColumn() ?: '');
    } catch (Throwable) {
        return '';
    }
}

/** Return exact base-table row counts for one database. */
function admin_gallery_report_model_exact_database_table_counts(string $databaseName): array
{
    $result = ['available' => false, 'counts' => [], 'errors' => [], 'total_rows' => 0];
    if ($databaseName === '') {
        $result['errors'][] = 'Database name is empty.';
        return $result;
    }
    try {
        $stmt = db()->prepare("SELECT TABLE_NAME AS table_name FROM information_schema.TABLES WHERE TABLE_SCHEMA = :database_name AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME ASC");
        $stmt->execute(['database_name' => $databaseName]);
        $tables = $stmt->fetchAll();
    } catch (Throwable) {
        $result['errors'][] = 'Database table inventory could not be read.';
        return $result;
    }
    $result['available'] = true;
    foreach ($tables as $row) {
        $tableName = trim((string) ($row['table_name'] ?? ''));
        if ($tableName === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tableName) !== 1) {
            continue;
        }
        try {
            $count = (int) (db()->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $tableName) . '`')->fetchColumn() ?: 0);
            $result['counts'][$tableName] = $count;
            $result['total_rows'] += $count;
        } catch (Throwable) {
            $result['errors'][] = $tableName . ': exact row count unavailable.';
        }
    }
    return $result;
}

/** Return exact row counts for a fixed list of known application tables. */
function admin_gallery_report_model_known_table_counts(array $tables): array
{
    $rows = [];
    foreach ($tables as $table) {
        $table = trim((string) $table);
        if ($table === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            continue;
        }
        try {
            $rows[$table] = (int) (db()->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')->fetchColumn() ?: 0);
        } catch (Throwable) {
            $rows[$table] = null;
        }
    }
    return $rows;
}
