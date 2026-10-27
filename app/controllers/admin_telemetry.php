<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_telemetry.php
 * Module Type: Controller
 *
 * Purpose:
 *   Owns authenticated telemetry reports, scoped settings and maintenance requests.
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
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\image_public_url;
use function Gallery\Core\image_public_thumbnail_url;
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
use function Gallery\Services\telemetry_report_cache_phases;
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
 * @param string $label Metric title.
 * @param string $value Formatted metric value.
 * @param string $hint Optional supporting detail.
 * @return void Render the metric card.
 */
function render_telemetry_metric_card(
    string $label,
    string $value,
    string $hint = '',
): void {
    \Gallery\Views\view_render_telemetry_metric_card($label, $value, $hint);
}

/**
 * Keep the compact overview on one stable reporting period.
 * Type: integer. Units: days. Scope: main Admin telemetry overview queries.
 * Consumers: cms_admin_telemetry(). Value: 30.
 * Rationale: retain the existing Last 30 days comparison across its metrics.
 */
const ADMIN_TELEMETRY_OVERVIEW_DAYS = 30;

/**
 * Bound the on-demand ranking preview to an ordinary supported thumbnail.
 * Type: integer. Units: pixels. Scope: telemetry ranking hover/focus previews.
 * Consumers: admin_telemetry_photo_rows(). Value: 600.
 * Rationale: the shared preview is at most 400 CSS pixels wide; the ordinary
 * thumbnail endpoint retains authorization, format and image-bound policies.
 */
const ADMIN_TELEMETRY_PREVIEW_SIZE = 600;

/**
 * Prepare canonical public destinations for existing photo ranking rows.
 *
 * @param list<array{id?:int|string,filename?:string,gallery_id?:int|string,
 *     gallery_slug?:string,gallery_title?:string,image_url_slug?:string|null,
 *     gallery_url_path?:string|null,image_modified_at?:string|null,
 *     photo_views?:int|float|string,
 *     avg_view_seconds?:int|float|string,view_count?:int|string}> $rows
 *     Service-provided identities, verified route metadata and ranked metrics.
 * @return list<array{id?:int|string,filename?:string,gallery_id?:int|string,
 *     gallery_slug?:string,gallery_title?:string,image_url_slug?:string|null,
 *     gallery_url_path?:string|null,image_modified_at?:string|null,
 *     photo_views?:int|float|string,
 *     avg_view_seconds?:int|float|string,view_count?:int|string,
 *     photo_url:string,gallery_url:string,preview_url:string}> Ordinary public links
 *     and a bounded thumbnail for the shared gallery-editor preview control;
 *     unavailable route metadata retains readable unlinked names.
 */
function admin_telemetry_photo_rows(array $rows): array
{
    foreach ($rows as &$row) {
        $row['photo_url'] = '';
        $row['gallery_url'] = '';
        $row['preview_url'] = '';
        if (!isset($row['image_url_slug'], $row['gallery_url_path'])) {
            continue;
        }
        $gallery = [
            'url_path' => $row['gallery_url_path'],
            'slug' => $row['gallery_slug'] ?? '',
            'title' => $row['gallery_title'] ?? '',
        ];
        $row['gallery_url'] = gallery_public_url($gallery);
        $image = [
            'id' => $row['id'] ?? null,
            'modified_at' => $row['image_modified_at'] ?? null,
            'url_slug' => $row['image_url_slug'],
            'filename' => $row['filename'] ?? '',
        ];
        $row['photo_url'] = image_public_url($image, $gallery);
        $row['preview_url'] = image_public_thumbnail_url(
            $image, $gallery, ADMIN_TELEMETRY_PREVIEW_SIZE, 'webp',
        );
    }
    unset($row);
    return $rows;
}

/**
 * Prepare the compact telemetry workspace with canonical preference metadata.
 * @return void Render schema status or the navigable reports/settings workspace.
 */
function cms_admin_telemetry(): void
{
    require_admin();
    $schemaStatus = presentation_telemetry_schema_status();
    $schemaReady = schema_inspection_is_available($schemaStatus);
    $schemaTitle = '';
    $schemaMessage = '';
    if (!$schemaReady) {
        if (schema_inspection_is_unknown($schemaStatus)) {
            presentation_schema_log_degraded(
                $schemaStatus,
                'admin_telemetry_dashboard',
            );
            $schemaTitle = t(
                'admin.telemetry.schema_unavailable',
                'Telemetry database status unavailable',
            );
            $schemaMessage = t(
                'admin.telemetry.schema_unavailable_text',
                'The gallery could not verify the telemetry database structure. No telemetry report or maintenance action was attempted. Check System Health and database connectivity, then try again.',
            );
        } else {
            $schemaTitle = t(
                'admin.telemetry.migrations_required',
                'Migrations required',
            );
            $schemaMessage = t(
                'admin.telemetry.migrations_required_text',
                'Telemetry tables are not available yet. Run database migrations first.',
            );
        }
    }

    $metrics = [];
    $tables = [];
    $trendRows = [];
    if ($schemaReady) {
        $sessionSummary = telemetry_report_session_summary(ADMIN_TELEMETRY_OVERVIEW_DAYS);
        $metrics = [
            [
                'label' => t(
                    'admin.telemetry.metric_anonymous_sessions',
                    'Anonymous sessions',
                ),
                'value' => (string) (int) ($sessionSummary['sessions'] ?? 0),
                'trend_key' => 'sessions',
            ],
            [
                'label' => t('admin.telemetry.metric_page_views', 'Page views'),
                'value' => (string) telemetry_metric_events(
                    'public.page_views',
                    ADMIN_TELEMETRY_OVERVIEW_DAYS,
                ),
                'trend_key' => 'page_views',
            ],
            [
                'label' => t(
                    'admin.telemetry.metric_photo_opens',
                    'Photo opens',
                ),
                'value' => (string) telemetry_metric_events('photo.views', ADMIN_TELEMETRY_OVERVIEW_DAYS),
                'trend_key' => 'photo_views',
            ],
            [
                'label' => t(
                    'admin.telemetry.metric_total_capped_photo_time',
                    'Total capped photo time',
                ),
                'value' =>
                    number_format(
                        telemetry_metric_sum('photo.view_seconds', ADMIN_TELEMETRY_OVERVIEW_DAYS),
                        0,
                    ) . ' s',
                'trend_key' => 'photo_seconds',
            ],
            [
                'label' => t(
                    'admin.telemetry.metric_client_errors',
                    'Client errors',
                ),
                'value' => (string) telemetry_metric_events(
                    'client.errors',
                    ADMIN_TELEMETRY_OVERVIEW_DAYS,
                ),
                'trend_key' => 'client_errors',
            ],
            [
                'label' => t(
                    'admin.telemetry.metric_image_bytes_measured',
                    'Image bytes measured',
                ),
                'value' => telemetry_format_bytes(
                    telemetry_metric_sum('media.image.bytes', ADMIN_TELEMETRY_OVERVIEW_DAYS)
                        + telemetry_metric_sum('media.thumbnail.bytes', ADMIN_TELEMETRY_OVERVIEW_DAYS),
                    1,
                ),
                'trend_key' => 'image_bytes',
            ],
        ];
        $daily = telemetry_report_daily_trends(ADMIN_TELEMETRY_OVERVIEW_DAYS);
        foreach ($metrics as &$metric) {
            $metric['trend'] = [];
        }
        unset($metric);
        foreach ($daily as $day) {
            $values = [];
            foreach ($metrics as &$metric) {
                $key = $metric['trend_key'];
                $value = max(0, (float) ($day[$key] ?? 0));
                // A missing session-day row is not a measured zero after retention.
                $observed = $key !== 'sessions' || $value > 0;
                $formatted = match ($key) {
                    'image_bytes' => telemetry_format_bytes($value, 1),
                    'photo_seconds' => number_format($value, 0) . ' s',
                    default => number_format($value, 0),
                };
                $values[] = $observed ? $formatted : '—';
                $metric['trend'][] = [
                    'date' => $day['report_date'],
                    'value' => $observed ? $value : null,
                    'formatted' => $observed ? $formatted : '—',
                ];
            }
            unset($metric);
            $trendRows[] = ['date' => $day['report_date'], 'values' => $values];
        }
        $tables = [
            'top_photos' => admin_telemetry_photo_rows(telemetry_top_photos()),
            'longest_photos' => admin_telemetry_photo_rows(telemetry_longest_viewed_photos()),
            'browser_mix' => telemetry_browser_mix(),
            'cache_mix' => telemetry_cache_mix(),
        ];
    }
    $preferences = $schemaReady
        ? \Gallery\Services\telemetry_admin_editable_settings() : [];
    foreach (\Gallery\Services\telemetry_admin_editable_setting_definitions() as $key => $definition) {
        if (isset($preferences[$key])) {
            $preferences[$key]['default'] = $definition['default'];
        }
    }
    $requestedTab = $_GET['telemetry_tab'] ?? 'overview';
    $activeTab = is_string($requestedTab)
        && in_array($requestedTab, ['overview', 'collection', 'storage', 'reports'], true)
        ? $requestedTab : 'overview';
    $tabUrls = [];
    foreach (['overview', 'collection', 'storage', 'reports'] as $tab) {
        $tabUrls[$tab] = url_for('admin_telemetry', ['telemetry_tab' => $tab]);
    }

    \Gallery\Views\view_render_admin_telemetry_dashboard([
        'page_title' => t('admin.telemetry.page_title', 'Telemetry'),
        'settings_url' => admin_settings_url('privacy'),
        'logs_url' => url_for('admin_logs'),
        'export_urls' => [
            'all' => url_for('admin_telemetry_export', [
                'traffic_segment' => 'all',
            ]),
            'non_bot' => url_for('admin_telemetry_export', [
                'traffic_segment' => 'non_bot',
            ]),
            'bot' => url_for('admin_telemetry_export', [
                'traffic_segment' => 'bot',
            ]),
            'unknown' => url_for('admin_telemetry_export', [
                'traffic_segment' => 'unknown',
            ]),
        ],
        'schema_ready' => $schemaReady,
        'schema_title' => $schemaTitle,
        'schema_message' => $schemaMessage,
        'public_enabled' => $schemaReady && telemetry_public_usage_enabled(),
        'admin_excluded' =>
            $schemaReady
                && current_user()
                && telemetry_setting_enabled('telemetry_admin_excluded', '1'),
        'metrics' => $metrics,
        'trend_rows' => $trendRows,
        'trend_days' => ADMIN_TELEMETRY_OVERVIEW_DAYS,
        'preferences' => $preferences,
        'active_tab' => $activeTab,
        'tab_urls' => $tabUrls,
        'settings_action_url' => url_for('admin_telemetry_settings'),
        'csrf_html' => csrf_field(),
        'maintenance_url' => url_for('admin_telemetry_maintenance'),
        'tables' => $tables,
    ]);
}

/**
 * Render one checkbox setting row.
 *
 * @param string $key Telemetry setting name.
 * @param string $label Checkbox label.
 * @param array<string,string> $settings Telemetry setting values keyed by setting name.
 * @return void Render the checkbox row.
 */
function render_telemetry_checkbox(
    string $key,
    string $label,
    array $settings,
): void {
    \Gallery\Views\view_render_telemetry_checkbox(
        $key,
        $label,
        ($settings[$key] ?? '0') === '1',
    );
}

/**
 * Render telemetry dashboard tables.
 * @return void Emit linked photo rankings and browser/cache summaries.
 */
function render_telemetry_tables(): void
{
    \Gallery\Views\view_render_telemetry_tables([
        'top_photos' => admin_telemetry_photo_rows(telemetry_top_photos()),
        'longest_photos' => admin_telemetry_photo_rows(telemetry_longest_viewed_photos()),
        'browser_mix' => telemetry_browser_mix(),
        'cache_mix' => telemetry_cache_mix(),
    ]);
}

/**
 * Render a photo telemetry table.
 *
 * @param list<array{filename?:string,gallery_title?:string}> $rows Photo rows with filename, gallery title, and metric values.
 * @param string $valueKey Row field used for the displayed value.
 * @param string $valueLabel Column heading for the displayed value.
 * @return void Render the photo telemetry table.
 */
function render_telemetry_photo_table(
    array $rows,
    string $valueKey,
    string $valueLabel,
): void {
    \Gallery\Views\view_render_telemetry_photo_table(
        $rows,
        $valueKey,
        $valueLabel,
    );
}

/**
 * Render a simple key-value telemetry table.
 *
 * @param list<array<string,int|float|string|null>> $rows Aggregate rows keyed by requested columns.
 * @param string $keyColumn Row field used for each key.
 * @param string $valueColumn Row field used for each value.
 * @param string $keyLabel Heading for the key column.
 * @param string $valueLabel Heading for the value column.
 * @return void Render the key-value telemetry table.
 */
function render_telemetry_key_value_table(
    array $rows,
    string $keyColumn,
    string $valueColumn,
    string $keyLabel,
    string $valueLabel,
): void {
    \Gallery\Views\view_render_telemetry_key_value_table(
        $rows,
        $keyColumn,
        $valueColumn,
        $keyLabel,
        $valueLabel,
    );
}

/**
 * Render one telemetry report metric card for the standalone HTML export.
 *
 * @param string $label Label value.
 * @param string $value Value to process.
 * @param string $hint Hint value.
 * @return string Text result for the caller.
 */
function telemetry_export_metric_card(
    string $label,
    string $value,
    string $hint = '',
): string {
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
 * @param list<array<string,mixed>> $rows Telemetry records keyed by their data fields.
 * @param list<array{key:string,label:string,format?:callable(mixed,array<string,mixed>):string}> $columns Column definitions with a row key, heading, and optional formatter.
 * @param string $emptyText Empty text value.
 * @return string Complete escaped table markup.
 */
function telemetry_export_table(
    array $rows,
    array $columns,
    string $emptyText = '',
): string {
    return view_telemetry_export_table($rows, $columns, $emptyText);
}

/**
 * Render a photo engagement table for the standalone report.
 *
 * @param list<array{filename?:string,gallery_title?:string}> $rows Photo engagement records with filename, gallery title, and metric values.
 * @param string $valueKey Row field used for the displayed value.
 * @param string $valueLabel Column heading for the displayed value.
 * @return string Complete escaped table markup.
 */
function telemetry_export_photo_table(
    array $rows,
    string $valueKey,
    string $valueLabel,
): string {
    return view_telemetry_export_photo_table($rows, $valueKey, $valueLabel);
}

/**
 * Render one compact bar chart from labeled rows.
 *
 * @param list<array<string,int|float|string|null>> $rows Labeled metric records to chart.
 * @param string $labelKey Row field containing each bar label.
 * @param string $valueKey Row field containing each numeric bar value.
 * @param string $valueSuffix Text appended to displayed values.
 * @return string Complete escaped bar-chart markup.
 */
function telemetry_export_bar_chart(
    array $rows,
    string $labelKey,
    string $valueKey,
    string $valueSuffix = '',
): string {
    return view_telemetry_export_bar_chart(
        $rows,
        $labelKey,
        $valueKey,
        $valueSuffix,
    );
}

/**
 * Render a trend chart from daily aggregate rows.
 *
 * @param list<array{report_date?:string}> $rows Daily aggregate records with date and numeric metric values.
 * @param string $valueKey Row field containing the numeric trend value.
 * @param string $label Chart series label.
 * @return string Complete escaped trend-chart markup.
 */
function telemetry_export_trend_chart(
    array $rows,
    string $valueKey,
    string $label,
): string {
    return view_telemetry_export_trend_chart($rows, $valueKey, $label);
}

/**
 * Download a standalone anonymous telemetry HTML report.
 * @return void No return value; effects are recorded in the owned state.
 */
function cms_admin_telemetry_export(): void
{
    require_admin();
    $schemaStatus = presentation_telemetry_schema_status();
    if (!schema_inspection_is_available($schemaStatus)) {
        header('Content-Type: text/plain; charset=utf-8');
        if (schema_inspection_is_unknown($schemaStatus)) {
            presentation_schema_log_degraded(
                $schemaStatus,
                'admin_telemetry_export',
            );
            http_response_code(503);
            echo t(
                'admin.telemetry.schema_unavailable_text',
                'The gallery could not verify the telemetry database structure. No telemetry report or maintenance action was attempted. Check System Health and database connectivity, then try again.',
            );
        } else {
            http_response_code(409);
            echo t(
                'admin.telemetry.migrations_required_text',
                'Telemetry tables are not available yet. Run database migrations first.',
            );
        }
        return;
    }

    $days = 30;
    $query = request_data('query');
    $trafficSegment = telemetry_traffic_segment(
        $query['traffic_segment'] ?? 'all',
    );
    $trafficSegmentLabel = match ($trafficSegment) {
        'non_bot' => t(
            'admin.telemetry.traffic_segment_non_bot',
            'Non-bot-classified traffic',
        ),
        'bot' => t(
            'admin.telemetry.traffic_segment_bot',
            'Bot-classified traffic',
        ),
        'unknown' => t(
            'admin.telemetry.traffic_segment_unknown',
            'Unclassified traffic',
        ),
        default => t('admin.telemetry.traffic_segment_all', 'All traffic'),
    };
    $rawRetentionDays = telemetry_retention_days(
        'telemetry_raw_retention_days',
        7,
        1,
        90,
    );
    telemetry_reset_report_query_profile();
    $photoDiagnosticDays = min($days, $rawRetentionDays);
    $photoOpenThreshold = 50;
    $generatedAt = date('Y-m-d H:i:s');
    $fileName = 'php-gallery-telemetry-' . date('Ymd-His') . '.html';
    $sessionSummary = telemetry_report_session_summary($days, $trafficSegment);
    $dailyTrends = telemetry_report_daily_trends($days, $trafficSegment);
    $consistency = telemetry_report_consistency(
        $days,
        $sessionSummary,
        $dailyTrends,
        $trafficSegment,
    );
    $topGalleries = telemetry_report_top_galleries($days, 25, $trafficSegment);
    $topRoutes = telemetry_report_top_routes($days, 25, $trafficSegment);
    $browserSessions = telemetry_report_session_distribution(
        'browser_family',
        $days,
        12,
        $trafficSegment,
    );
    $osSessions = telemetry_report_session_distribution(
        'os_family',
        $days,
        12,
        $trafficSegment,
    );
    $deviceSessions = telemetry_report_session_distribution(
        'device_type',
        $days,
        12,
        $trafficSegment,
    );
    $viewportSessions = telemetry_report_session_distribution(
        'viewport_class',
        $days,
        12,
        $trafficSegment,
    );
    $entryReferrers = telemetry_report_session_distribution(
        'entry_referrer_category',
        $days,
        12,
        $trafficSegment,
    );
    $landingRoutes = telemetry_report_session_distribution(
        'first_route_name',
        $days,
        20,
        $trafficSegment,
    );
    $exitRoutes = telemetry_report_session_distribution(
        'exit_route_name',
        $days,
        20,
        $trafficSegment,
    );
    $pageKinds = telemetry_report_metric_distribution(
        'page_kind',
        $days,
        'public.page_views',
        12,
        $trafficSegment,
    );
    $mediaVariants = telemetry_report_metric_distribution(
        'media_variant',
        $days,
        'media.thumbnail.bytes',
        20,
        $trafficSegment,
    );
    $imageVariants = telemetry_report_metric_distribution(
        'media_variant',
        $days,
        'media.image.bytes',
        20,
        $trafficSegment,
    );
    $cacheThumbnail = telemetry_report_metric_distribution(
        'cache_result',
        $days,
        'cache.thumbnail.hit',
        12,
        $trafficSegment,
    );
    $cacheMisses = telemetry_report_metric_distribution(
        'cache_result',
        $days,
        'cache.thumbnail.miss',
        12,
        $trafficSegment,
    );
    $performanceMetrics = telemetry_report_performance_metrics(
        $days,
        $trafficSegment,
    );
    $photoOpenBuckets = telemetry_report_photo_open_session_buckets(
        $days,
        $trafficSegment,
    );
    $photoOpenAnomalySummary = telemetry_report_photo_open_anomaly_summary(
        $days,
        $photoOpenThreshold,
        $trafficSegment,
    );
    $photoOpenGalleryDiagnostics = telemetry_report_gallery_photo_opens_per_session(
        $photoDiagnosticDays,
        3,
        20,
        $trafficSegment,
    );
    $photoOpenOriginsAvailable = null;
    $photoOpenOrigins = telemetry_report_photo_open_origins(
        $photoDiagnosticDays,
        $trafficSegment,
        $photoOpenOriginsAvailable,
    );
    $cachePhases = telemetry_report_cache_phases(
        $photoDiagnosticDays,
        $trafficSegment,
    );
    $clientErrors = telemetry_report_client_errors($days, 25, $trafficSegment);
    $recentEvents = telemetry_report_recent_events($days, 80, $trafficSegment);
    $databaseSummary = telemetry_report_database_summary($days, 40);
    $databaseFingerprints = telemetry_report_database_fingerprints($days, 30);
    $databaseFingerprintsVolume = telemetry_report_database_fingerprints(
        $days,
        30,
        'volume',
    );
    $databaseFingerprintsFailed = telemetry_report_database_fingerprints(
        $days,
        30,
        'failed',
    );
    $jobRuns = telemetry_report_job_runs($days, 40);

    $sessions = (float) ($sessionSummary['sessions'] ?? 0);
    $pageViews = (float) telemetry_metric_events(
        'public.page_views',
        $days,
        $trafficSegment,
    );
    $photoViews = (float) telemetry_metric_events(
        'photo.views',
        $days,
        $trafficSegment,
    );
    $durationSeconds = (float) ($sessionSummary['duration_seconds'] ?? 0);
    $bouncedSessions = (float) ($sessionSummary['bounced_sessions'] ?? 0);
    $bounceRate = $sessions > 0 ? ($bouncedSessions / $sessions) * 100 : 0;
    $avgPagesPerSession = $sessions > 0 ? $pageViews / $sessions : 0.0;
    $avgPhotosPerSession = $sessions > 0 ? $photoViews / $sessions : 0.0;
    $avgDurationSeconds = (float) (
        $sessionSummary['avg_duration_seconds'] ?? 0
    );
    $mediaBytes =
        telemetry_metric_sum('media.image.bytes', $days, $trafficSegment)
        + telemetry_metric_sum('media.thumbnail.bytes', $days, $trafficSegment)
        + telemetry_metric_sum('media.download.bytes', $days, $trafficSegment);
    $thumbnailBytes = telemetry_metric_sum(
        'media.thumbnail.bytes',
        $days,
        $trafficSegment,
    );
    $imageBytes = telemetry_metric_sum(
        'media.image.bytes',
        $days,
        $trafficSegment,
    );
    $downloadBytes = telemetry_metric_sum(
        'media.download.bytes',
        $days,
        $trafficSegment,
    );
    $clientErrorCount = telemetry_metric_events(
        'client.errors',
        $days,
        $trafficSegment,
    );
    $photoSeconds = telemetry_metric_sum(
        'photo.view_seconds',
        $days,
        $trafficSegment,
    );
    $cacheHitEvents = telemetry_metric_events(
        'cache.lightbox.hit',
        $days,
        $trafficSegment,
    );
    $cacheMissEvents = telemetry_metric_events(
        'cache.lightbox.miss',
        $days,
        $trafficSegment,
    );
    $cacheSampleCount = $cacheHitEvents + $cacheMissEvents;
    $telemetryMasterEnabled = telemetry_setting_enabled(
        'telemetry_enabled',
        '0',
    );
    $cacheTelemetryEnabled =
        $telemetryMasterEnabled
        && telemetry_setting_enabled('telemetry_cache_enabled', '1');
    $cacheEmptyText = !$cacheTelemetryEnabled
        ? t('admin.telemetry.export.disabled', 'Disabled')
        : t('admin.telemetry.export.no_cache_samples', 'No cache samples');
    $cacheEfficiency = $cacheSampleCount > 0
        ? ($cacheHitEvents / $cacheSampleCount) * 100
        : 0;
    $cacheEfficiencyDisplay = $cacheSampleCount > 0
        ? view_telemetry_report_number($cacheEfficiency, 1) . ' %'
        : $cacheEmptyText;
    // $databaseTotals stores aggregate DB telemetry counters prepared by the service layer.
    $databaseTotals = telemetry_report_database_totals($days);
    $dbQueryCount = (float) ($databaseTotals['query_count'] ?? 0);
    $dbSlowCount = (float) ($databaseTotals['slow_count'] ?? 0);
    $dbFailedCount = (float) ($databaseTotals['failed_count'] ?? 0);
    $databaseTelemetryEnabled =
        $telemetryMasterEnabled
        && telemetry_setting_enabled('telemetry_database_enabled', '1');
    $dbTelemetryHasSamples = $dbQueryCount > 0;
    $dbEmptyText = !$databaseTelemetryEnabled
        ? t('admin.telemetry.export.disabled', 'Disabled')
        : t('admin.telemetry.export.no_samples', 'No samples');
    $dbQueryCountDisplay = $dbTelemetryHasSamples
        ? view_telemetry_report_number($dbQueryCount)
        : $dbEmptyText;
    $dbSlowCountDisplay = $dbTelemetryHasSamples
        ? view_telemetry_report_number($dbSlowCount)
        : $dbEmptyText;
    $dbFailedCountDisplay = $dbTelemetryHasSamples
        ? view_telemetry_report_number($dbFailedCount)
        : $dbEmptyText;

    $activeLanguage = translation_active_language();
    $publicTelemetryEnabled = telemetry_public_usage_enabled();
    $topPhotos = telemetry_top_photos($days, 25, $trafficSegment);
    $longestPhotos = telemetry_longest_viewed_photos(
        $days,
        25,
        $trafficSegment,
    );
    $storageDiagnostics = telemetry_report_storage_diagnostics($days);
    $rollupConsistency = telemetry_report_daily_rollup_consistency(
        min(7, max(1, $days - 1)),
        $trafficSegment,
    );
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
 * Save validated telemetry settings from the authenticated admin form.
 * @return void Emit a scoped JSON completion or native redirect; unscoped
 *     requests retain the existing complete-form preference semantics.
 */
function cms_admin_telemetry_settings(): void
{
    require_admin();
    verify_csrf();
    if (array_key_exists('telemetry_preference', $_POST)) {
        admin_telemetry_save_preference();
        return;
    }
    $schemaStatus = presentation_telemetry_settings_schema_status();
    if (!schema_inspection_is_available($schemaStatus)) {
        if (schema_inspection_is_unknown($schemaStatus)) {
            presentation_schema_log_degraded(
                $schemaStatus,
                'admin_telemetry_settings_save',
            );
            flash_message('admin_notice', t(
                'admin.telemetry.schema_unavailable_text',
                'The gallery could not verify the telemetry database structure. No telemetry report or maintenance action was attempted. Check System Health and database connectivity, then try again.',
            ));
        } else {
            flash_message('admin_notice', t(
                'admin.telemetry.migrations_required_text',
                'Telemetry tables are not available yet. Run database migrations first.',
            ));
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
    $values = [];
    try {
        $definitions =
            \Gallery\Services\telemetry_admin_editable_setting_definitions();
        foreach ([
            'telemetry_max_photo_view_seconds',
            'telemetry_raw_retention_days',
            'telemetry_hourly_retention_days',
            'telemetry_daily_retention_days',
            'telemetry_database_retention_days',
        ] as $key) {
            $values[$key] = \Gallery\Services\telemetry_admin_setting_normalize(
                $key,
                $_POST[$key] ?? $definitions[$key]['default'],
            );
        }
    } catch (\InvalidArgumentException) {
        http_response_code(400);
        return;
    }
    foreach ($checkboxKeys as $key) {
        telemetry_set_setting($key, isset($_POST[$key]) ? '1' : '0');
    }
    foreach ($values as $key => $value) {
        telemetry_set_setting($key, $value);
    }
    admin_log_event(
        'info',
        'telemetry.settings_updated',
        'Telemetry settings were updated.',
        [],
        [
            'category' => 'telemetry',
            'severity' => 'notice',
            'route_name' => 'admin_telemetry',
        ],
    );
    redirect_to(url_for('admin_telemetry'));
}

/**
 * Save one allowlisted preference without changing any other telemetry setting.
 *
 * Authentication and CSRF are established by the settings route before entry.
 * A read after writing must confirm the stored scalar before reporting success;
 * unavailable reads and unexpected write failures remain explicitly unverified.
 *
 * @return void Emit a canonical JSON completion or the native scoped redirect.
 */
function admin_telemetry_save_preference(): void
{
    $key = $_POST['telemetry_preference'];
    $definitions = \Gallery\Services\telemetry_admin_editable_setting_definitions();
    $requestedTab = $_POST['telemetry_tab'] ?? 'collection';
    $tab = is_string($requestedTab)
        && in_array($requestedTab, ['collection', 'storage'], true)
        ? $requestedTab : 'collection';
    $returnUrl = url_for('admin_telemetry', ['telemetry_tab' => $tab]);
    $json = \Gallery\Core\admin_wants_json();
    $ok = false;
    $confirmed = false;
    $values = [];
    $message = t('admin.telemetry.preference_invalid', 'Choose a supported value.');
    $status = 422;
    if (is_string($key) && isset($definitions[$key])) {
        $current = \Gallery\Services\telemetry_admin_editable_settings();
        if (!empty($current[$key]['available'])) {
            $confirmed = true;
            $values[$key] = $current[$key]['current'];
            $submitted = $_POST[$key] ?? (
                $definitions[$key]['input_type'] === 'checkbox' ? '0' : null
            );
            try {
                $normalized = \Gallery\Services\telemetry_admin_setting_normalize($key, $submitted);
                // Once the writer starts, an exception cannot prove rollback.
                $confirmed = false;
                $values = [];
                \Gallery\Services\telemetry_admin_setting_save($key, $normalized);
                $stored = \Gallery\Services\telemetry_admin_editable_settings();
                $confirmed = !empty($stored[$key]['available']);
                $values = $confirmed ? [$key => $stored[$key]['current']] : [];
                $ok = $confirmed && $stored[$key]['current'] === $normalized;
                if ($ok) {
                    admin_log_event('info', 'telemetry.settings_updated',
                        'Telemetry settings were updated.', ['setting_key' => $key], [
                            'category' => 'telemetry',
                            'severity' => 'notice',
                            'route_name' => 'admin_telemetry',
                        ]);
                }
                $status = $ok ? 200 : ($confirmed ? 409 : 503);
                $message = $ok
                    ? t('admin.features.saved', 'Saved')
                    : ($confirmed
                        ? t('admin.telemetry.preference_refused', 'The saved value differs from your change. Review it before trying again.')
                        : t('admin.telemetry.preference_unverified', 'Save could not be verified. Reload the saved state before trying again.'));
            } catch (\InvalidArgumentException) {
                // Validation finishes before the writer is admitted.
                $status = 422;
            } catch (\Throwable) {
                $confirmed = false;
                $values = [];
                $status = 503;
                $message = t('admin.telemetry.preference_unverified', 'Save could not be verified. Reload the saved state before trying again.');
            }
        } else {
            $status = 503;
            $message = t('admin.telemetry.schema_unavailable_text', 'The gallery could not verify the telemetry database structure. No telemetry report or maintenance action was attempted. Check System Health and database connectivity, then try again.');
        }
    }
    if (!$json) {
        flash_message('admin_notice', $message);
        redirect_to($returnUrl);
        return;
    }
    $mutation = \Gallery\Core\admin_mutation_descriptor('settings.update', 'settings', 'update');
    $fallback = ['redirect_url' => $returnUrl];
    $payload = $ok
        ? \Gallery\Core\admin_mutation_success_envelope($message, $mutation, null, [], $fallback)
        : \Gallery\Core\admin_mutation_error_envelope($message, 'telemetry_preference_refused', $mutation, $fallback);
    $payload['setting_values'] = $values;
    $payload['confirmed'] = $confirmed;
    $payload['public_enabled'] = $confirmed ? telemetry_public_usage_enabled() : null;
    $payload['admin_excluded'] = $confirmed
        ? (bool) current_user() && telemetry_setting_enabled('telemetry_admin_excluded', '1')
        : null;
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Run telemetry rollup and purge from the admin UI.
 * @return void No return value; effects are recorded in the owned state.
 */
function cms_admin_telemetry_maintenance(): void
{
    require_admin();
    if (
        strtoupper((string) (request_data('server')['REQUEST_METHOD'] ?? 'GET'))
        !== 'POST'
    ) {
        http_response_code(405);
        header('Allow: POST');
        return;
    }
    verify_csrf();
    $schemaStatus = presentation_telemetry_schema_status();
    if (!schema_inspection_is_available($schemaStatus)) {
        if (schema_inspection_is_unknown($schemaStatus)) {
            presentation_schema_log_degraded(
                $schemaStatus,
                'admin_telemetry_maintenance',
            );
            flash_message('admin_notice', t(
                'admin.telemetry.schema_unavailable_text',
                'The gallery could not verify the telemetry database structure. No telemetry report or maintenance action was attempted. Check System Health and database connectivity, then try again.',
            ));
        } else {
            flash_message('admin_notice', t(
                'admin.telemetry.migrations_required_text',
                'Telemetry tables are not available yet. Run database migrations first.',
            ));
        }
        redirect_to(url_for('admin_telemetry'));
    }
    $result = telemetry_run_maintenance();
    $status = empty($result['ok'])
        ? 'failed'
        : (
            !empty($result['skipped'])
                ? 'skipped'
                : (!empty($result['has_more']) ? 'pending' : 'completed')
        );
    $statusMessage = match ($status) {
        'failed' => t(
            'admin.telemetry.maintenance_failed',
            'Maintenance failed. Inspect the bounded result and job log.',
        ),
        'pending' => t(
            'admin.telemetry.maintenance_pending',
            'One bounded maintenance slice completed. More work remains; subsequent scheduled slices will continue.',
        ),
        'skipped' => t(
            'admin.telemetry.maintenance_skipped',
            'No maintenance slice ran. See the reason below.',
        ),
        default => t(
            'admin.telemetry.maintenance_completed',
            'Rollup and retention cleanup completed.',
        ),
    };
    \Gallery\Views\view_render_admin_telemetry_maintenance([
        'status_message' => $statusMessage,
        'title' => t(
            'admin.telemetry.maintenance_title',
            'Telemetry maintenance',
        ),
        'back_url' => url_for('admin_telemetry', ['telemetry_tab' => 'storage']),
        'result_json' => (string) json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ),
    ]);
}
