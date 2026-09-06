<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_gallery_report/content_summary.php
 * Module Type: Service
 *
 * Purpose:
 *   Aggregates gallery, tag, vote, feature, log, and telemetry sections.
 *
 * Responsibilities:
 *   - Summarize gallery counts, nesting depth, and per-gallery detail rows
 *   - Summarize tags, votes, enabled features, and Admin log activity
 *   - Build the bounded telemetry section for the requested window
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
 *   - Loaded by app/services/admin_gallery_report.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/admin_gallery_report.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;
use function Gallery\Core\cms_config;
use function Gallery\Core\cms_current_version;
use function Gallery\Core\db;

/**
 * Return gallery structure and policy summary.
 *
 * @return array<string, mixed> Gallery summary.
 */
function admin_gallery_report_gallery_summary(): array
{
    $summary = [
        'total' => admin_gallery_report_scalar_int('SELECT COUNT(*) FROM galleries'),
        'root_count' => admin_gallery_report_scalar_int('SELECT COUNT(*) FROM galleries WHERE parent_id IS NULL'),
        'nested_count' => admin_gallery_report_scalar_int('SELECT COUNT(*) FROM galleries WHERE parent_id IS NOT NULL'),
        'empty_count' => admin_gallery_report_scalar_int('SELECT COUNT(*) FROM galleries g LEFT JOIN images i ON i.gallery_id = g.id GROUP BY g.id HAVING COUNT(i.id) = 0', [], true),
        'visibility_rows' => admin_gallery_report_group_query('SELECT visibility AS label, COUNT(*) AS count FROM galleries GROUP BY visibility ORDER BY count DESC'),
        'access_rows' => admin_gallery_report_group_query('SELECT access_mode AS label, COUNT(*) AS count FROM galleries GROUP BY access_mode ORDER BY count DESC'),
        'listing_rows' => admin_gallery_report_group_query('SELECT access_listing AS label, COUNT(*) AS count FROM galleries GROUP BY access_listing ORDER BY count DESC'),
        'image_visibility_rows' => admin_gallery_report_group_query('SELECT visibility AS label, COUNT(*) AS count FROM images GROUP BY visibility ORDER BY count DESC'),
        'largest_rows' => admin_gallery_report_rows('SELECT g.id, g.title, g.folder_path, g.visibility, COUNT(i.id) AS image_count, COALESCE(SUM(COALESCE(i.file_size, 0)), 0) AS source_bytes FROM galleries g LEFT JOIN images i ON i.gallery_id = g.id GROUP BY g.id, g.title, g.folder_path, g.visibility ORDER BY image_count DESC, source_bytes DESC LIMIT 80'),
        'deepest_depth' => admin_gallery_report_deepest_gallery_depth(),
    ];
    if (admin_gallery_report_column_exists('galleries', 'date_start')) {
        $summary['dated_gallery_count'] = admin_gallery_report_scalar_int('SELECT COUNT(*) FROM galleries WHERE date_start IS NOT NULL OR date_end IS NOT NULL');
    }
    if (admin_gallery_report_column_exists('galleries', 'gps_map_enabled')) {
        $summary['gps_map_override_rows'] = admin_gallery_report_group_query('SELECT gps_map_enabled AS label, COUNT(*) AS count FROM galleries GROUP BY gps_map_enabled ORDER BY gps_map_enabled ASC');
    }
    return $summary;
}

/**
 * Return the approximate deepest gallery nesting level.
 *
 * @return int Maximum depth.
 */
function admin_gallery_report_deepest_gallery_depth(): int
{
    try {
        $rows = db()->query('SELECT id, parent_id FROM galleries')->fetchAll();
    } catch (Throwable) {
        return 0;
    }
    $parentById = [];
    foreach ($rows as $row) {
        $parentById[(int) ($row['id'] ?? 0)] = isset($row['parent_id']) ? (int) $row['parent_id'] : 0;
    }
    $maxDepth = 0;
    foreach (array_keys($parentById) as $id) {
        $depth = 0;
        $guard = 0;
        $cursor = $id;
        while ($cursor > 0 && isset($parentById[$cursor]) && $parentById[$cursor] > 0 && $guard < 200) {
            $depth++;
            $cursor = (int) $parentById[$cursor];
            $guard++;
        }
        $maxDepth = max($maxDepth, $depth);
    }
    return $maxDepth;
}

/**
 * Return one row per gallery with key operational details.
 *
 * @return array<int, array<string, mixed>> Gallery rows.
 */
function admin_gallery_report_gallery_detail_rows(): array
{
    $gpsImageExpression = (admin_gallery_report_column_exists('images', 'gps_lat') && admin_gallery_report_column_exists('images', 'gps_lng'))
        ? 'SUM(CASE WHEN i.gps_lat IS NOT NULL AND i.gps_lng IS NOT NULL THEN 1 ELSE 0 END) AS gps_images'
        : '0 AS gps_images';
    $selects = [
        'g.id', 'g.parent_id', 'g.title', 'g.folder_path', 'g.slug', 'g.visibility', 'g.access_mode', 'g.access_listing',
        'g.sort_order', 'g.created_at', 'g.updated_at',
        'COUNT(i.id) AS image_count',
        'COALESCE(SUM(COALESCE(i.file_size, 0)), 0) AS source_bytes',
        $gpsImageExpression,
        admin_gallery_report_column_select('galleries', 'date_start', 'g', 'NULL', 'date_start'),
        admin_gallery_report_column_select('galleries', 'date_end', 'g', 'NULL', 'date_end'),
        admin_gallery_report_column_select('galleries', 'gps_map_enabled', 'g', 'NULL', 'gps_map_enabled'),
        admin_gallery_report_column_select('galleries', 'picture_game_enabled', 'g', 'NULL', 'picture_game_enabled'),
    ];
    $groupBy = 'g.id, g.parent_id, g.title, g.folder_path, g.slug, g.visibility, g.access_mode, g.access_listing, g.sort_order, g.created_at, g.updated_at';
    if (admin_gallery_report_column_exists('galleries', 'date_start')) {
        $groupBy .= ', g.date_start, g.date_end';
    }
    if (admin_gallery_report_column_exists('galleries', 'gps_map_enabled')) {
        $groupBy .= ', g.gps_map_enabled';
    }
    if (admin_gallery_report_column_exists('galleries', 'picture_game_enabled')) {
        $groupBy .= ', g.picture_game_enabled';
    }
    return admin_gallery_report_rows('SELECT ' . implode(', ', $selects) . ' FROM galleries g LEFT JOIN images i ON i.gallery_id = g.id GROUP BY ' . $groupBy . ' ORDER BY g.folder_path ASC');
}

/**
 * Return tag statistics.
 *
 * @return array<string, mixed> Tag summary.
 */
function admin_gallery_report_tag_summary(): array
{
    return [
        'tag_count' => admin_gallery_report_table_exists('tags') ? admin_gallery_report_scalar_int('SELECT COUNT(*) FROM tags') : 0,
        'gallery_tag_rows' => admin_gallery_report_table_exists('gallery_tags') ? admin_gallery_report_rows('SELECT t.name AS label, COUNT(gt.gallery_id) AS count FROM tags t INNER JOIN gallery_tags gt ON gt.tag_id = t.id GROUP BY t.id, t.name ORDER BY count DESC, t.name ASC LIMIT 80') : [],
        'image_tag_rows' => admin_gallery_report_table_exists('image_tags') ? admin_gallery_report_rows('SELECT t.name AS label, COUNT(it.image_id) AS count FROM tags t INNER JOIN image_tags it ON it.tag_id = t.id GROUP BY t.id, t.name ORDER BY count DESC, t.name ASC LIMIT 80') : [],
    ];
}

/**
 * Return vote statistics.
 *
 * @return array<string, mixed> Vote summary.
 */
function admin_gallery_report_vote_summary(): array
{
    return [
        'image_vote_rows' => admin_gallery_report_table_exists('image_votes') ? admin_gallery_report_group_query('SELECT vote AS label, COUNT(*) AS count FROM image_votes GROUP BY vote ORDER BY vote DESC') : [],
        'picture_game_vote_rows' => admin_gallery_report_table_exists('picture_game_votes') ? admin_gallery_report_group_query("SELECT CASE WHEN winner_image_id IS NULL THEN 'shown_without_vote' ELSE 'completed_vote' END AS label, COUNT(*) AS count FROM picture_game_votes GROUP BY CASE WHEN winner_image_id IS NULL THEN 'shown_without_vote' ELSE 'completed_vote' END ORDER BY label ASC") : [],
    ];
}

/**
 * Return app settings and feature visibility information.
 *
 * @return array<string, mixed> Feature summary.
 */
function admin_gallery_report_feature_summary(): array
{
    $settings = [];
    if (admin_gallery_report_table_exists('app_settings')) {
        $settings = admin_gallery_report_rows("SELECT setting_key, setting_value, updated_at FROM app_settings WHERE setting_key LIKE 'feature_%' OR setting_key LIKE '%enabled%' OR setting_key LIKE '%telemetry%' OR setting_key LIKE '%thumbnail%' ORDER BY setting_key ASC LIMIT 250");
    }
    $telemetrySettings = [];
    if (function_exists('Gallery\\Services\\telemetry_settings_schema_ready') && telemetry_settings_schema_ready() && function_exists('Gallery\\Services\\telemetry_all_settings')) {
        $telemetrySettings = telemetry_all_settings();
    }
    return [
        'settings_rows' => $settings,
        'telemetry_settings' => $telemetrySettings,
    ];
}

/**
 * Return operational log summary.
 *
 * @return array<string, mixed> Log summary.
 */
function admin_gallery_report_admin_log_summary(): array
{
    if (!admin_gallery_report_table_exists('admin_logs')) {
        return ['available' => false, 'level_rows' => [], 'severity_rows' => [], 'category_rows' => [], 'recent_errors' => []];
    }
    $hasSeverity = admin_gallery_report_column_exists('admin_logs', 'severity');
    $hasCategory = admin_gallery_report_column_exists('admin_logs', 'category');
    return [
        'available' => true,
        'total' => admin_gallery_report_scalar_int('SELECT COUNT(*) FROM admin_logs'),
        'last_7_days' => admin_gallery_report_scalar_int('SELECT COUNT(*) FROM admin_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'),
        'level_rows' => admin_gallery_report_group_query('SELECT level AS label, COUNT(*) AS count FROM admin_logs GROUP BY level ORDER BY count DESC'),
        'severity_rows' => $hasSeverity ? admin_gallery_report_group_query('SELECT severity AS label, COUNT(*) AS count FROM admin_logs GROUP BY severity ORDER BY count DESC') : [],
        'category_rows' => $hasCategory ? admin_gallery_report_group_query('SELECT category AS label, COUNT(*) AS count FROM admin_logs GROUP BY category ORDER BY count DESC') : [],
        'recent_errors' => admin_gallery_report_rows("SELECT created_at, level, " . ($hasSeverity ? 'severity,' : "'' AS severity,") . " event_key, message, route_name FROM admin_logs WHERE level IN ('error','critical')" . ($hasSeverity ? " OR severity IN ('error','critical')" : '') . " ORDER BY created_at DESC LIMIT 80"),
        'top_events' => admin_gallery_report_group_query('SELECT event_key AS label, COUNT(*) AS count FROM admin_logs GROUP BY event_key ORDER BY count DESC LIMIT 80'),
    ];
}

/**
 * Return telemetry summary data.
 *
 * @param int $days Telemetry window in days.
 * @return array<string, mixed> Telemetry section.
 */
function admin_gallery_report_telemetry_section(int $days): array
{
    if (!function_exists('Gallery\\Services\\telemetry_schema_ready') || !telemetry_schema_ready()) {
        return ['available' => false, 'days' => $days, 'message' => 'Telemetry schema is not available.'];
    }
    $windows = [];
    foreach ([7, 30, 90, 365] as $windowDays) {
        $sessions = function_exists('Gallery\\Services\\telemetry_report_session_summary') ? telemetry_report_session_summary($windowDays) : [];
        $databaseTotals = function_exists('Gallery\\Services\\telemetry_report_database_totals') ? telemetry_report_database_totals($windowDays) : [];
        $windows[] = [
            'days' => $windowDays,
            'sessions' => (int) ($sessions['sessions'] ?? 0),
            'page_views' => (int) ($sessions['page_views'] ?? 0),
            'photo_views' => (int) ($sessions['photo_views'] ?? 0),
            'duration_seconds' => (int) ($sessions['duration_seconds'] ?? 0),
            'db_queries' => (int) ($databaseTotals['query_count'] ?? 0),
            'db_slow' => (int) ($databaseTotals['slow_count'] ?? 0),
            'db_failed' => (int) ($databaseTotals['failed_count'] ?? 0),
        ];
    }
    return [
        'available' => true,
        'days' => $days,
        'public_enabled' => function_exists('Gallery\\Services\\telemetry_public_usage_enabled') && telemetry_public_usage_enabled(),
        'windows' => $windows,
        'session_summary' => function_exists('Gallery\\Services\\telemetry_report_session_summary') ? telemetry_report_session_summary($days) : [],
        'daily_trends' => function_exists('Gallery\\Services\\telemetry_report_daily_trends') ? telemetry_report_daily_trends($days) : [],
        'top_galleries' => function_exists('Gallery\\Services\\telemetry_report_top_galleries') ? telemetry_report_top_galleries($days, 60) : [],
        'top_routes' => function_exists('Gallery\\Services\\telemetry_report_top_routes') ? telemetry_report_top_routes($days, 60) : [],
        'page_kinds' => function_exists('Gallery\\Services\\telemetry_report_metric_distribution') ? telemetry_report_metric_distribution('page_kind', $days, 'public.page_views', 20) : [],
        'browsers' => function_exists('Gallery\\Services\\telemetry_report_session_distribution') ? telemetry_report_session_distribution('browser_family', $days, 20) : [],
        'operating_systems' => function_exists('Gallery\\Services\\telemetry_report_session_distribution') ? telemetry_report_session_distribution('os_family', $days, 20) : [],
        'devices' => function_exists('Gallery\\Services\\telemetry_report_session_distribution') ? telemetry_report_session_distribution('device_type', $days, 20) : [],
        'referrers' => function_exists('Gallery\\Services\\telemetry_report_session_distribution') ? telemetry_report_session_distribution('entry_referrer_category', $days, 20) : [],
        'performance' => function_exists('Gallery\\Services\\telemetry_report_performance_metrics') ? telemetry_report_performance_metrics($days) : [],
        'client_errors' => function_exists('Gallery\\Services\\telemetry_report_client_errors') ? telemetry_report_client_errors($days, 60) : [],
        'database_summary' => function_exists('Gallery\\Services\\telemetry_report_database_summary') ? telemetry_report_database_summary($days, 80) : [],
        'database_fingerprints' => function_exists('Gallery\\Services\\telemetry_report_database_fingerprints') ? telemetry_report_database_fingerprints($days, 60) : [],
        'job_runs' => function_exists('Gallery\\Services\\telemetry_report_job_runs') ? telemetry_report_job_runs($days, 80) : [],
        'recent_events' => function_exists('Gallery\\Services\\telemetry_report_recent_events') ? telemetry_report_recent_events($days, 120) : [],
    ];
}

/**
 * Return largest source images.
 *
 * @param int $limit Maximum number of rows.
 * @return array<int, array<string, mixed>> Image rows.
 */
function admin_gallery_report_largest_images(int $limit = 200): array
{
    $limit = max(1, min(500, $limit));
    return admin_gallery_report_rows('SELECT i.id, i.filename, i.relative_path, i.mime_type, i.file_size, i.width, i.height, i.visibility, g.title AS gallery_title, g.folder_path AS gallery_folder_path FROM images i INNER JOIN galleries g ON g.id = i.gallery_id ORDER BY COALESCE(i.file_size, 0) DESC, i.id DESC LIMIT ' . $limit);
}
