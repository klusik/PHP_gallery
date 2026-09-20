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
 *   - The module entry point loads immutable Core policy; consuming parts import their required definitions.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;
use const Gallery\Core\ADMIN_GALLERY_REPORT_TELEMETRY_COMPARISON_WINDOWS;
use const Gallery\Core\ADMIN_GALLERY_REPORT_ROW_LIMITS;

use function Gallery\Core\cms_config;
use function Gallery\Core\cms_current_version;
use function Gallery\Models\admin_gallery_report_model_admin_log_summary;
use function Gallery\Models\admin_gallery_report_model_feature_settings;
use function Gallery\Models\admin_gallery_report_model_gallery_detail_rows;
use function Gallery\Models\admin_gallery_report_model_gallery_parent_rows;
use function Gallery\Models\admin_gallery_report_model_gallery_summary;
use function Gallery\Models\admin_gallery_report_model_largest_images;
use function Gallery\Models\admin_gallery_report_model_tag_summary;
use function Gallery\Models\admin_gallery_report_model_vote_summary;

/**
 * Return gallery structure and policy summary.
 *
 * @return array<string, mixed> Gallery summary.
 */
function admin_gallery_report_gallery_summary(): array
{
    $hasDates = admin_gallery_report_column_exists('galleries', 'date_start');
    $hasGpsMap = admin_gallery_report_column_exists('galleries', 'gps_map_enabled');
    $summary = admin_gallery_report_model_gallery_summary($hasDates, $hasGpsMap);
    foreach (['visibility_rows', 'access_rows', 'listing_rows', 'image_visibility_rows', 'gps_map_override_rows'] as $key) {
        if (isset($summary[$key]) && is_array($summary[$key])) {
            $summary[$key] = admin_gallery_report_normalize_group_rows($summary[$key]);
        }
    }
    $summary['deepest_depth'] = admin_gallery_report_deepest_gallery_depth();
    return $summary;
}

/**
 * Return the approximate deepest gallery nesting level.
 *
 * @return int Maximum depth.
 */
function admin_gallery_report_deepest_gallery_depth(): int
{
    $rows = admin_gallery_report_model_gallery_parent_rows();
    if (!$rows) {
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
    return admin_gallery_report_model_gallery_detail_rows([
        'gps_coordinates' => admin_gallery_report_column_exists('images', 'gps_lat') && admin_gallery_report_column_exists('images', 'gps_lng'),
        'dates' => admin_gallery_report_column_exists('galleries', 'date_start'),
        'gps_map' => admin_gallery_report_column_exists('galleries', 'gps_map_enabled'),
        'picture_game' => admin_gallery_report_column_exists('galleries', 'picture_game_enabled'),
    ]);
}

/**
 * Return tag statistics.
 *
 * @return array<string, mixed> Tag summary.
 */
function admin_gallery_report_tag_summary(): array
{
    return admin_gallery_report_model_tag_summary(
        admin_gallery_report_table_exists('tags'),
        admin_gallery_report_table_exists('gallery_tags'),
        admin_gallery_report_table_exists('image_tags')
    );
}

/**
 * Return vote statistics.
 *
 * @return array<string, mixed> Vote summary.
 */
function admin_gallery_report_vote_summary(): array
{
    $summary = admin_gallery_report_model_vote_summary(
        admin_gallery_report_table_exists('image_votes'),
        admin_gallery_report_table_exists('picture_game_votes')
    );
    $summary['image_vote_rows'] = admin_gallery_report_normalize_group_rows($summary['image_vote_rows'] ?? []);
    $summary['picture_game_vote_rows'] = admin_gallery_report_normalize_group_rows($summary['picture_game_vote_rows'] ?? []);
    return $summary;
}

/**
 * Return app settings and feature visibility information.
 *
 * @return array<string, mixed> Feature summary.
 */
function admin_gallery_report_feature_summary(): array
{
    $settings = admin_gallery_report_table_exists('app_settings')
        ? admin_gallery_report_model_feature_settings()
        : [];
    return [
        'settings_rows' => $settings,
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
    $summary = admin_gallery_report_model_admin_log_summary($hasSeverity, $hasCategory);
    $summary['available'] = true;
    foreach (['level_rows', 'severity_rows', 'category_rows', 'top_events'] as $key) {
        $summary[$key] = admin_gallery_report_normalize_group_rows($summary[$key] ?? []);
    }
    return $summary;
}

/**
 * Return telemetry summary data.
 *
 * @param int $days Telemetry window in days.
 * @return array<string, mixed> Telemetry section.
 */
function admin_gallery_report_telemetry_section(int $days): array
{
    if (function_exists(__NAMESPACE__ . '\\feature_capability_effective_enabled') && !feature_capability_effective_enabled('telemetry')) {
        return ['available' => false, 'disabled' => true, 'days' => $days];
    }
    if (!function_exists('Gallery\\Services\\telemetry_schema_ready') || !telemetry_schema_ready()) {
        return ['available' => false, 'days' => $days, 'message' => 'Telemetry schema is not available.'];
    }
    $windows = [];
    foreach (ADMIN_GALLERY_REPORT_TELEMETRY_COMPARISON_WINDOWS as $windowDays) {
        $sessions = function_exists('Gallery\\Services\\telemetry_report_canonical_summary') ? telemetry_report_canonical_summary($windowDays) : [];
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
        'session_summary' => function_exists('Gallery\\Services\\telemetry_report_canonical_summary') ? telemetry_report_canonical_summary($days) : [],
        'daily_trends' => function_exists('Gallery\\Services\\telemetry_report_daily_trends') ? telemetry_report_daily_trends($days) : [],
        'top_galleries' => function_exists('Gallery\\Services\\telemetry_report_top_galleries') ? telemetry_report_top_galleries($days, ADMIN_GALLERY_REPORT_ROW_LIMITS['telemetry_top']) : [],
        'top_routes' => function_exists('Gallery\\Services\\telemetry_report_top_routes') ? telemetry_report_top_routes($days, ADMIN_GALLERY_REPORT_ROW_LIMITS['telemetry_top']) : [],
        'page_kinds' => function_exists('Gallery\\Services\\telemetry_report_metric_distribution') ? telemetry_report_metric_distribution('page_kind', $days, 'public.page_views', ADMIN_GALLERY_REPORT_ROW_LIMITS['telemetry_distribution']) : [],
        'browsers' => function_exists('Gallery\\Services\\telemetry_report_session_distribution') ? telemetry_report_session_distribution('browser_family', $days, ADMIN_GALLERY_REPORT_ROW_LIMITS['telemetry_distribution']) : [],
        'operating_systems' => function_exists('Gallery\\Services\\telemetry_report_session_distribution') ? telemetry_report_session_distribution('os_family', $days, ADMIN_GALLERY_REPORT_ROW_LIMITS['telemetry_distribution']) : [],
        'devices' => function_exists('Gallery\\Services\\telemetry_report_session_distribution') ? telemetry_report_session_distribution('device_type', $days, ADMIN_GALLERY_REPORT_ROW_LIMITS['telemetry_distribution']) : [],
        'referrers' => function_exists('Gallery\\Services\\telemetry_report_session_distribution') ? telemetry_report_session_distribution('entry_referrer_category', $days, ADMIN_GALLERY_REPORT_ROW_LIMITS['telemetry_distribution']) : [],
        'performance' => function_exists('Gallery\\Services\\telemetry_report_performance_metrics') ? telemetry_report_performance_metrics($days) : [],
        'client_errors' => function_exists('Gallery\\Services\\telemetry_report_client_errors') ? telemetry_report_client_errors($days, ADMIN_GALLERY_REPORT_ROW_LIMITS['telemetry_top']) : [],
        'database_summary' => function_exists('Gallery\\Services\\telemetry_report_database_summary') ? telemetry_report_database_summary($days, ADMIN_GALLERY_REPORT_ROW_LIMITS['telemetry_database']) : [],
        'database_fingerprints' => function_exists('Gallery\\Services\\telemetry_report_database_fingerprints') ? telemetry_report_database_fingerprints($days, ADMIN_GALLERY_REPORT_ROW_LIMITS['telemetry_top']) : [],
        'job_runs' => function_exists('Gallery\\Services\\telemetry_report_job_runs') ? telemetry_report_job_runs($days, ADMIN_GALLERY_REPORT_ROW_LIMITS['telemetry_jobs']) : [],
        'recent_events' => function_exists('Gallery\\Services\\telemetry_report_recent_events') ? telemetry_report_recent_events($days, ADMIN_GALLERY_REPORT_ROW_LIMITS['telemetry_events']) : [],
    ];
}

/**
 * Return largest source images.
 *
 * @param int $limit Maximum number of rows.
 * @return array<int, array<string, mixed>> Image rows.
 */
function admin_gallery_report_largest_images(int $limit = ADMIN_GALLERY_REPORT_ROW_LIMITS['top_images']): array
{
    return admin_gallery_report_model_largest_images($limit);
}
