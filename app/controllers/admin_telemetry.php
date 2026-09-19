<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_telemetry.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles request-level application logic for the related gallery feature.
 *
 * Responsibilities:
 *   - Validate and route incoming request data
 *   - Call service-layer functions where possible
 *   - Return redirects, rendered views, or HTTP responses
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
 *   2026-05-04
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Core\csrf_field;
use function Gallery\Core\current_user;
use function Gallery\Core\redirect_to;
use function Gallery\Core\request_data;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\admin_settings_url;
use function Gallery\Services\t;
use function Gallery\Services\translation_active_language;
use function Gallery\Services\admin_log_event;
use function Gallery\Services\telemetry_all_settings;
use function Gallery\Services\telemetry_browser_mix;
use function Gallery\Services\telemetry_cache_mix;
use function Gallery\Services\telemetry_format_bytes;
use function Gallery\Services\telemetry_longest_viewed_photos;
use function Gallery\Services\telemetry_metric_events;
use function Gallery\Services\telemetry_metric_sum;
use function Gallery\Services\telemetry_public_usage_enabled;
use function Gallery\Services\telemetry_report_client_errors;
use function Gallery\Services\telemetry_report_consistency;
use function Gallery\Services\telemetry_report_daily_trends;
use function Gallery\Services\telemetry_report_daily_rollup_consistency;
use function Gallery\Services\telemetry_report_database_fingerprints;
use function Gallery\Services\telemetry_report_database_summary;
use function Gallery\Services\telemetry_report_database_totals;
use function Gallery\Services\telemetry_report_job_runs;
use function Gallery\Services\telemetry_report_metric_distribution;
use function Gallery\Services\telemetry_report_photo_open_session_buckets;
use function Gallery\Services\telemetry_report_photo_open_origins;
use function Gallery\Services\telemetry_report_photo_open_anomaly_summary;
use function Gallery\Services\telemetry_report_gallery_photo_opens_per_session;
use function Gallery\Services\telemetry_report_performance_metrics;
use function Gallery\Services\telemetry_report_query_profile;
use function Gallery\Services\telemetry_report_query_plans;
use function Gallery\Services\telemetry_reset_report_query_profile;
use function Gallery\Services\telemetry_report_recent_events;
use function Gallery\Services\telemetry_report_session_distribution;
use function Gallery\Services\telemetry_report_session_summary;
use function Gallery\Services\telemetry_report_storage_diagnostics;
use function Gallery\Services\telemetry_report_top_galleries;
use function Gallery\Services\telemetry_report_top_routes;
use function Gallery\Services\telemetry_retention_days;
use function Gallery\Services\telemetry_run_maintenance;
use function Gallery\Services\telemetry_set_setting;
use function Gallery\Services\telemetry_setting_enabled;
use function Gallery\Services\telemetry_schema_ready;
use function Gallery\Services\presentation_schema_log_degraded;
use function Gallery\Services\presentation_telemetry_schema_status;
use function Gallery\Services\presentation_telemetry_settings_schema_status;
use function Gallery\Services\schema_inspection_is_available;
use function Gallery\Services\schema_inspection_is_missing;
use function Gallery\Services\schema_inspection_is_unknown;
use function Gallery\Services\telemetry_top_photos;
use function Gallery\Services\telemetry_traffic_segment;
use function Gallery\Views\view_render_admin_telemetry_export_document;
use function Gallery\Views\view_telemetry_export_bar_chart;
use function Gallery\Views\view_telemetry_export_metric_card;
use function Gallery\Views\view_telemetry_export_photo_table;
use function Gallery\Views\view_telemetry_export_table;
use function Gallery\Views\view_telemetry_export_trend_chart;
use function Gallery\Views\view_telemetry_report_duration;
use function Gallery\Views\view_telemetry_report_number;

/**
 * Admin telemetry controller.
 *
 * The admin screens expose aggregated anonymous metrics and privacy controls.
 * They avoid raw visitor data and keep the UI focused on tuning the gallery.
 */

/**
 * Render one small metric card for the telemetry dashboard.
 *
 * @param string $label Label value.
 * @param string $value Value to process.
 * @param string $hint Hint value.
 */
function render_telemetry_metric_card(string $label, string $value, string $hint = ''): void
{
    \Gallery\Views\view_render_telemetry_metric_card($label, $value, $hint);
}

/**
 * Render the main anonymous telemetry dashboard.
 */
function cms_admin_telemetry(): void
{
    require_admin();
    $schemaStatus = presentation_telemetry_schema_status();
    $schemaReady = schema_inspection_is_available($schemaStatus);
    $settings = $schemaReady ? telemetry_all_settings() : [];
    $schemaTitle = '';
    $schemaMessage = '';
    if (!$schemaReady) {
        if (schema_inspection_is_unknown($schemaStatus)) {
            presentation_schema_log_degraded($schemaStatus, 'admin_telemetry_dashboard');
            $schemaTitle = t('admin.telemetry.schema_unavailable', 'Telemetry database status unavailable');
            $schemaMessage = t('admin.telemetry.schema_unavailable_text', 'The gallery could not verify the telemetry database structure. No telemetry report or maintenance action was attempted. Check System Health and database connectivity, then try again.');
        } else {
            $schemaTitle = t('admin.telemetry.migrations_required', 'Migrations required');
            $schemaMessage = t('admin.telemetry.migrations_required_text', 'Telemetry tables are not available yet. Run database migrations first.');
        }
    }

    $metrics = [];
    $tables = [];
    if ($schemaReady) {
        $sessionSummary = telemetry_report_session_summary(30);
        $metrics = [
            ['label' => t('admin.telemetry.metric_anonymous_sessions', 'Anonymous sessions'), 'value' => (string) ((int) ($sessionSummary['sessions'] ?? 0))],
            ['label' => t('admin.telemetry.metric_page_views', 'Page views'), 'value' => (string) telemetry_metric_events('public.page_views', 30)],
            ['label' => t('admin.telemetry.metric_photo_opens', 'Photo opens'), 'value' => (string) telemetry_metric_events('photo.views', 30)],
            ['label' => t('admin.telemetry.metric_total_capped_photo_time', 'Total capped photo time'), 'value' => number_format(telemetry_metric_sum('photo.view_seconds', 30), 0) . ' s'],
            ['label' => t('admin.telemetry.metric_client_errors', 'Client errors'), 'value' => (string) telemetry_metric_events('client.errors', 30)],
            ['label' => t('admin.telemetry.metric_image_bytes_measured', 'Image bytes measured'), 'value' => telemetry_format_bytes(telemetry_metric_sum('media.image.bytes', 30) + telemetry_metric_sum('media.thumbnail.bytes', 30), 1)],
        ];
        $tables = [
            'top_photos' => telemetry_top_photos(),
            'longest_photos' => telemetry_longest_viewed_photos(),
            'browser_mix' => telemetry_browser_mix(),
            'cache_mix' => telemetry_cache_mix(),
        ];
    }
    $checkboxLabels = [
        'telemetry_enabled' => t('admin.telemetry.setting_enable_subsystem', 'Enable telemetry subsystem'),
        'telemetry_public_usage_enabled' => t('admin.telemetry.setting_collect_public_usage', 'Collect anonymous public usage telemetry'),
        'telemetry_performance_enabled' => t('admin.telemetry.setting_collect_performance', 'Collect sampled browser performance metrics'),
        'telemetry_cache_enabled' => t('admin.telemetry.setting_collect_cache', 'Collect cache efficiency metrics'),
        'telemetry_database_enabled' => t('admin.telemetry.setting_collect_database', 'Collect database health metrics'),
        'telemetry_respect_dnt' => t('admin.telemetry.setting_respect_dnt', 'Respect Do Not Track'),
        'telemetry_admin_excluded' => t('admin.telemetry.setting_exclude_admins', 'Exclude logged-in admins from public telemetry'),
    ];
    $checkboxes = [];
    foreach ($checkboxLabels as $key => $label) {
        $checkboxes[] = ['key' => $key, 'label' => $label, 'checked' => (($settings[$key] ?? '0') === '1')];
    }

    \Gallery\Views\view_render_admin_telemetry_dashboard([
        'page_title' => t('admin.telemetry.page_title', 'Telemetry'),
        'settings_url' => admin_settings_url('privacy'),
        'logs_url' => url_for('admin_logs'),
        'export_urls' => [
            'all' => url_for('admin_telemetry_export', ['traffic_segment' => 'all']),
            'non_bot' => url_for('admin_telemetry_export', ['traffic_segment' => 'non_bot']),
            'bot' => url_for('admin_telemetry_export', ['traffic_segment' => 'bot']),
        ],
        'dashboard_url' => url_for('admin'),
        'schema_ready' => $schemaReady,
        'schema_title' => $schemaTitle,
        'schema_message' => $schemaMessage,
        'public_enabled' => $schemaReady && telemetry_public_usage_enabled(),
        'raw_retention_days' => $schemaReady ? telemetry_retention_days('telemetry_raw_retention_days', 7, 1, 90) : 7,
        'admin_excluded' => $schemaReady && current_user() && telemetry_setting_enabled('telemetry_admin_excluded', '1'),
        'metrics' => $metrics,
        'settings' => $settings,
        'checkboxes' => $checkboxes,
        'settings_action_url' => url_for('admin_telemetry_settings'),
        'csrf_html' => csrf_field(),
        'maintenance_url' => url_for('admin_telemetry_maintenance'),
        'tables' => $tables,
    ]);
}

/**
 * Render one checkbox setting row.
 *
 * @param string $key Lookup key.
 * @param string $label Label value.
 * @param array $settings Settings used by this workflow.
 */
function render_telemetry_checkbox(string $key, string $label, array $settings): void
{
    \Gallery\Views\view_render_telemetry_checkbox($key, $label, (($settings[$key] ?? '0') === '1'));
}

/**
 * Render telemetry dashboard tables.
 */
function render_telemetry_tables(): void
{
    \Gallery\Views\view_render_telemetry_tables([
        'top_photos' => telemetry_top_photos(),
        'longest_photos' => telemetry_longest_viewed_photos(),
        'browser_mix' => telemetry_browser_mix(),
        'cache_mix' => telemetry_cache_mix(),
    ]);
}

/**
 * Render a photo telemetry table.
 *
 * @param array $rows Rows to process.
 * @param string $valueKey Value key value.
 * @param string $valueLabel Value label value.
 */
function render_telemetry_photo_table(array $rows, string $valueKey, string $valueLabel): void
{
    \Gallery\Views\view_render_telemetry_photo_table($rows, $valueKey, $valueLabel);
}

/**
 * Render a simple key-value telemetry table.
 *
 * @param array $rows Rows to process.
 * @param string $keyColumn Key column value.
 * @param string $valueColumn Value column value.
 * @param string $keyLabel Key label value.
 * @param string $valueLabel Value label value.
 */
function render_telemetry_key_value_table(array $rows, string $keyColumn, string $valueColumn, string $keyLabel, string $valueLabel): void
{
    \Gallery\Views\view_render_telemetry_key_value_table($rows, $keyColumn, $valueColumn, $keyLabel, $valueLabel);
}


/**
 * Render one telemetry report metric card for the standalone HTML export.
 *
 * @param string $label Label value.
 * @param string $value Value to process.
 * @param string $hint Hint value.
 * @return string Text result for the caller.
 */
function telemetry_export_metric_card(string $label, string $value, string $hint = ''): string
{
    return view_telemetry_export_metric_card($label, $value, $hint);
}

/**
 * Format one telemetry number for the standalone report.
 *
 * @param mixed $value Value to process.
 * @param int $decimals Decimals value.
 * @return string Text result for the caller.
 */
function telemetry_report_number(mixed $value, int $decimals = 0): string
{
    return view_telemetry_report_number($value, $decimals);
}

/**
 * Format one duration for the standalone report.
 *
 * @param mixed $seconds Seconds value.
 * @return string Text result for the caller.
 */
function telemetry_report_duration(mixed $seconds): string
{
    return view_telemetry_report_duration($seconds);
}

/**
 * Render one generic telemetry report table.
 *
 * @param array $rows Rows to process.
 * @param array $columns Columns value.
 * @param string $emptyText Empty text value.
 * @return string Text result for the caller.
 */
function telemetry_export_table(array $rows, array $columns, string $emptyText = ''): string
{
    return view_telemetry_export_table($rows, $columns, $emptyText);
}


/**
 * Render a photo engagement table for the standalone report.
 *
 * @param array $rows Rows to process.
 * @param string $valueKey Value key value.
 * @param string $valueLabel Value label value.
 * @return string Text result for the caller.
 */
function telemetry_export_photo_table(array $rows, string $valueKey, string $valueLabel): string
{
    return view_telemetry_export_photo_table($rows, $valueKey, $valueLabel);
}

/**
 * Render one compact bar chart from labeled rows.
 *
 * @param array $rows Rows to process.
 * @param string $labelKey Label key value.
 * @param string $valueKey Value key value.
 * @param string $valueSuffix Value suffix value.
 * @return string Text result for the caller.
 */
function telemetry_export_bar_chart(array $rows, string $labelKey, string $valueKey, string $valueSuffix = ''): string
{
    return view_telemetry_export_bar_chart($rows, $labelKey, $valueKey, $valueSuffix);
}

/**
 * Render a trend chart from daily aggregate rows.
 *
 * @param array $rows Rows to process.
 * @param string $valueKey Value key value.
 * @param string $label Label value.
 * @return string Text result for the caller.
 */
function telemetry_export_trend_chart(array $rows, string $valueKey, string $label): string
{
    return view_telemetry_export_trend_chart($rows, $valueKey, $label);
}

/**
 * Download a standalone anonymous telemetry HTML report.
 */
function cms_admin_telemetry_export(): void
{
    require_admin();
    $schemaStatus = presentation_telemetry_schema_status();
    if (!schema_inspection_is_available($schemaStatus)) {
        header('Content-Type: text/plain; charset=utf-8');
        if (schema_inspection_is_unknown($schemaStatus)) {
            presentation_schema_log_degraded($schemaStatus, 'admin_telemetry_export');
            http_response_code(503);
            echo t('admin.telemetry.schema_unavailable_text', 'The gallery could not verify the telemetry database structure. No telemetry report or maintenance action was attempted. Check System Health and database connectivity, then try again.');
        } else {
            http_response_code(409);
            echo t('admin.telemetry.migrations_required_text', 'Telemetry tables are not available yet. Run database migrations first.');
        }
        return;
    }

    $days = 30;
    $query = request_data('query');
    $trafficSegment = telemetry_traffic_segment($query['traffic_segment'] ?? 'all');
    $trafficSegmentLabel = match ($trafficSegment) {
        'non_bot' => t('admin.telemetry.traffic_segment_non_bot', 'Non-bot-classified traffic'),
        'bot' => t('admin.telemetry.traffic_segment_bot', 'Bot-classified traffic'),
        default => t('admin.telemetry.traffic_segment_all', 'All traffic'),
    };
    $rawRetentionDays = telemetry_retention_days('telemetry_raw_retention_days', 7, 1, 90);
    telemetry_reset_report_query_profile();
    $photoDiagnosticDays = min($days, $rawRetentionDays);
    $photoOpenThreshold = 50;
    $generatedAt = date('Y-m-d H:i:s');
    $fileName = 'php-gallery-telemetry-' . date('Ymd-His') . '.html';
    $sessionSummary = telemetry_report_session_summary($days, $trafficSegment);
    $dailyTrends = telemetry_report_daily_trends($days, $trafficSegment);
    $consistency = telemetry_report_consistency($days, $sessionSummary, $dailyTrends, $trafficSegment);
    $topGalleries = telemetry_report_top_galleries($days, 25, $trafficSegment);
    $topRoutes = telemetry_report_top_routes($days, 25, $trafficSegment);
    $browserSessions = telemetry_report_session_distribution('browser_family', $days, 12, $trafficSegment);
    $osSessions = telemetry_report_session_distribution('os_family', $days, 12, $trafficSegment);
    $deviceSessions = telemetry_report_session_distribution('device_type', $days, 12, $trafficSegment);
    $viewportSessions = telemetry_report_session_distribution('viewport_class', $days, 12, $trafficSegment);
    $entryReferrers = telemetry_report_session_distribution('entry_referrer_category', $days, 12, $trafficSegment);
    $landingRoutes = telemetry_report_session_distribution('first_route_name', $days, 20, $trafficSegment);
    $exitRoutes = telemetry_report_session_distribution('exit_route_name', $days, 20, $trafficSegment);
    $pageKinds = telemetry_report_metric_distribution('page_kind', $days, 'public.page_views', 12, $trafficSegment);
    $mediaVariants = telemetry_report_metric_distribution('media_variant', $days, 'media.thumbnail.bytes', 20, $trafficSegment);
    $imageVariants = telemetry_report_metric_distribution('media_variant', $days, 'media.image.bytes', 20, $trafficSegment);
    $cacheThumbnail = telemetry_report_metric_distribution('cache_result', $days, 'cache.thumbnail.hit', 12, $trafficSegment);
    $cacheMisses = telemetry_report_metric_distribution('cache_result', $days, 'cache.thumbnail.miss', 12, $trafficSegment);
    $performanceMetrics = telemetry_report_performance_metrics($days, $trafficSegment);
    $photoOpenBuckets = telemetry_report_photo_open_session_buckets($days, $trafficSegment);
    $photoOpenAnomalySummary = telemetry_report_photo_open_anomaly_summary($days, $photoOpenThreshold, $trafficSegment);
    $photoOpenGalleryDiagnostics = telemetry_report_gallery_photo_opens_per_session($photoDiagnosticDays, 3, 20, $trafficSegment);
    $photoOpenOrigins = telemetry_report_photo_open_origins($photoDiagnosticDays, $trafficSegment);
    $clientErrors = telemetry_report_client_errors($days, 25, $trafficSegment);
    $recentEvents = telemetry_report_recent_events($days, 80, $trafficSegment);
    $databaseSummary = telemetry_report_database_summary($days, 40);
    $databaseFingerprints = telemetry_report_database_fingerprints($days, 30);
    $jobRuns = telemetry_report_job_runs($days, 40);

    $sessions = (float) ($sessionSummary['sessions'] ?? 0);
    $pageViews = (float) telemetry_metric_events('public.page_views', $days, $trafficSegment);
    $photoViews = (float) ($sessionSummary['photo_views'] ?? 0);
    $durationSeconds = (float) ($sessionSummary['duration_seconds'] ?? 0);
    $bouncedSessions = (float) ($sessionSummary['bounced_sessions'] ?? 0);
    $bounceRate = $sessions > 0 ? ($bouncedSessions / $sessions) * 100 : 0;
    $avgPagesPerSession = $sessions > 0 ? $pageViews / $sessions : 0.0;
    $avgPhotosPerSession = (float) ($sessionSummary['avg_photos_per_session'] ?? 0);
    $avgDurationSeconds = (float) ($sessionSummary['avg_duration_seconds'] ?? 0);
    $mediaBytes = telemetry_metric_sum('media.image.bytes', $days, $trafficSegment) + telemetry_metric_sum('media.thumbnail.bytes', $days, $trafficSegment) + telemetry_metric_sum('media.download.bytes', $days, $trafficSegment);
    $thumbnailBytes = telemetry_metric_sum('media.thumbnail.bytes', $days, $trafficSegment);
    $imageBytes = telemetry_metric_sum('media.image.bytes', $days, $trafficSegment);
    $downloadBytes = telemetry_metric_sum('media.download.bytes', $days, $trafficSegment);
    $clientErrorCount = telemetry_metric_events('client.errors', $days, $trafficSegment);
    $photoSeconds = telemetry_metric_sum('photo.view_seconds', $days, $trafficSegment);
    $cacheHitEvents = telemetry_metric_events('cache.lightbox.hit', $days, $trafficSegment);
    $cacheMissEvents = telemetry_metric_events('cache.lightbox.miss', $days, $trafficSegment);
    $cacheSampleCount = $cacheHitEvents + $cacheMissEvents;
    $telemetryMasterEnabled = telemetry_setting_enabled('telemetry_enabled', '0');
    $cacheTelemetryEnabled = $telemetryMasterEnabled && telemetry_setting_enabled('telemetry_cache_enabled', '1');
    $cacheEmptyText = !$cacheTelemetryEnabled
        ? t('admin.telemetry.export.disabled', 'Disabled')
        : t('admin.telemetry.export.no_cache_samples', 'No cache samples');
    $cacheEfficiency = $cacheSampleCount > 0 ? ($cacheHitEvents / $cacheSampleCount) * 100 : 0;
    $cacheEfficiencyDisplay = $cacheSampleCount > 0
        ? view_telemetry_report_number($cacheEfficiency, 1) . ' %'
        : $cacheEmptyText;
    // $databaseTotals stores aggregate DB telemetry counters prepared by the service layer.
    $databaseTotals = telemetry_report_database_totals($days);
    $dbQueryCount = (float) ($databaseTotals['query_count'] ?? 0);
    $dbSlowCount = (float) ($databaseTotals['slow_count'] ?? 0);
    $dbFailedCount = (float) ($databaseTotals['failed_count'] ?? 0);
    $databaseTelemetryEnabled = $telemetryMasterEnabled && telemetry_setting_enabled('telemetry_database_enabled', '1');
    $dbTelemetryHasSamples = $dbQueryCount > 0;
    $dbEmptyText = !$databaseTelemetryEnabled
        ? t('admin.telemetry.export.disabled', 'Disabled')
        : t('admin.telemetry.export.no_samples', 'No samples');
    $dbQueryCountDisplay = $dbTelemetryHasSamples ? view_telemetry_report_number($dbQueryCount) : $dbEmptyText;
    $dbSlowCountDisplay = $dbTelemetryHasSamples ? view_telemetry_report_number($dbSlowCount) : $dbEmptyText;
    $dbFailedCountDisplay = $dbTelemetryHasSamples ? view_telemetry_report_number($dbFailedCount) : $dbEmptyText;

    $activeLanguage = translation_active_language();
    $publicTelemetryEnabled = telemetry_public_usage_enabled();
    $topPhotos = telemetry_top_photos($days, 25, $trafficSegment);
    $longestPhotos = telemetry_longest_viewed_photos($days, 25, $trafficSegment);
    $storageDiagnostics = telemetry_report_storage_diagnostics($days);
    $rollupConsistency = telemetry_report_daily_rollup_consistency(min(7, max(1, $days - 1)), $trafficSegment);
    $reportQueryProfile = telemetry_report_query_profile();
    $reportQueryPlans = telemetry_report_query_plans($days, $trafficSegment);
    $storedCounts = [];
    foreach ((array) ($storageDiagnostics['tables'] ?? []) as $row) {
        $tableName = (string) ($row['table_name'] ?? '');
        if ($tableName !== '') {
            $storedCounts[$tableName] = max(0, (int) ($row['exact_rows'] ?? 0));
        }
    }
    $html = view_render_admin_telemetry_export_document(get_defined_vars());

    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    echo $html;
}

/**
 * Save telemetry settings from the admin form.
 */
function cms_admin_telemetry_settings(): void
{
    require_admin();
    verify_csrf();
    $schemaStatus = presentation_telemetry_settings_schema_status();
    if (!schema_inspection_is_available($schemaStatus)) {
        if (schema_inspection_is_unknown($schemaStatus)) {
            presentation_schema_log_degraded($schemaStatus, 'admin_telemetry_settings_save');
            flash_message('admin_notice', t('admin.telemetry.schema_unavailable_text', 'The gallery could not verify the telemetry database structure. No telemetry report or maintenance action was attempted. Check System Health and database connectivity, then try again.'));
        } else {
            flash_message('admin_notice', t('admin.telemetry.migrations_required_text', 'Telemetry tables are not available yet. Run database migrations first.'));
        }
        redirect_to(url_for('admin_telemetry'));
    }
    // $checkboxKeys stores boolean setting names handled by the form.
    $checkboxKeys = [
        'telemetry_enabled',
        'telemetry_public_usage_enabled',
        'telemetry_performance_enabled',
        'telemetry_cache_enabled',
        'telemetry_database_enabled',
        'telemetry_respect_dnt',
        'telemetry_admin_excluded',
    ];
    foreach ($checkboxKeys as $key) {
        telemetry_set_setting($key, isset($_POST[$key]) ? '1' : '0');
    }
    foreach (['telemetry_max_photo_view_seconds', 'telemetry_raw_retention_days', 'telemetry_hourly_retention_days', 'telemetry_daily_retention_days'] as $key) {
        // $value stores the bounded numeric setting value.
        $value = max(1, (int) ($_POST[$key] ?? 0));
        telemetry_set_setting($key, (string) $value);
    }
    admin_log_event('info', 'telemetry.settings_updated', 'Telemetry settings were updated.', [], [
        'category' => 'telemetry',
        'severity' => 'notice',
        'route_name' => 'admin_telemetry',
    ]);
    redirect_to(url_for('admin_telemetry'));
}

/**
 * Run telemetry rollup and purge from the admin UI.
 */
function cms_admin_telemetry_maintenance(): void
{
    require_admin();
    $schemaStatus = presentation_telemetry_schema_status();
    if (!schema_inspection_is_available($schemaStatus)) {
        if (schema_inspection_is_unknown($schemaStatus)) {
            presentation_schema_log_degraded($schemaStatus, 'admin_telemetry_maintenance');
            flash_message('admin_notice', t('admin.telemetry.schema_unavailable_text', 'The gallery could not verify the telemetry database structure. No telemetry report or maintenance action was attempted. Check System Health and database connectivity, then try again.'));
        } else {
            flash_message('admin_notice', t('admin.telemetry.migrations_required_text', 'Telemetry tables are not available yet. Run database migrations first.'));
        }
        redirect_to(url_for('admin_telemetry'));
    }
    $result = telemetry_run_maintenance();
    \Gallery\Views\view_render_admin_telemetry_maintenance([
        'title' => t('admin.telemetry.maintenance_title', 'Telemetry maintenance'),
        'back_url' => url_for('admin_telemetry'),
        'result_json' => (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}
