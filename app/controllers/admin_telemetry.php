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

/**
 * Admin telemetry controller.
 *
 * The admin screens expose aggregated anonymous metrics and privacy controls.
 * They avoid raw visitor data and keep the UI focused on tuning the gallery.
 */

/**
 * Render one small metric card for the telemetry dashboard.
 */
function render_telemetry_metric_card(string $label, string $value, string $hint = ''): void
{
    echo '<article class="metric-card"><strong>' . e($value) . '</strong><span>' . e($label) . '</span>';
    if ($hint !== '') {
        echo '<small>' . e($hint) . '</small>';
    }
    echo '</article>';
}

/**
 * Render the main anonymous telemetry dashboard.
 */
function cms_admin_telemetry(): void
{
    require_admin();
    // $settings stores the telemetry configuration displayed on the page.
    $settings = telemetry_all_settings();
    // $schemaReady stores whether migrations have created telemetry tables.
    $schemaReady = telemetry_settings_schema_ready();
    render_header(t('admin.telemetry.page_title', 'Telemetry'));
    echo '<section class="hero"><h1>' . e(t('admin.telemetry.title', 'Anonymous telemetry')) . '</h1><p>' . e(t('admin.telemetry.description', 'Local, privacy-safe usage and performance statistics for tuning the gallery.')) . '</p><nav class="nav">';
    echo '<a class="button secondary" href="' . e(url_for('admin_logs')) . '">' . e(t('admin.telemetry.operational_logs', 'Operational logs')) . '</a>';
    echo '<a class="button secondary" href="' . e(url_for('admin_telemetry_export')) . '">' . e(t('admin.telemetry.export_html_report', 'Export HTML report')) . '</a>';
    echo '<a class="button secondary" href="' . e(url_for('admin')) . '">' . e(t('admin.common.dashboard', 'Dashboard')) . '</a>';
    echo '</nav></section>';

    if (!$schemaReady) {
        echo '<section class="panel"><h2>' . e(t('admin.telemetry.migrations_required', 'Migrations required')) . '</h2><p>' . e(t('admin.telemetry.migrations_required_text', 'Telemetry tables are not available yet. Run database migrations first.')) . '</p></section>';
        render_footer();
        return;
    }

    echo '<section class="panel"><h2>' . e(t('admin.telemetry.privacy_status', 'Privacy status')) . '</h2><div class="telemetry-privacy-note">';
    echo '<p>' . e(t('admin.telemetry.privacy_text', 'This subsystem does not store raw IP addresses, raw browser user-agent strings, raw referrer URLs, names, email addresses, account identifiers, request bodies, or exact locations.')) . '</p>';
    echo '<p>' . e(t('admin.telemetry.public_telemetry_is', 'Public telemetry is')) . ' ' . (telemetry_public_usage_enabled() ? '<strong>' . e(t('admin.common.enabled', 'enabled')) . '</strong>' : '<strong>' . e(t('admin.common.disabled', 'disabled')) . '</strong>') . '. ' . e(t('admin.telemetry.raw_events_retained_for', 'Raw events are retained for')) . ' ' . e((string) telemetry_retention_days('telemetry_raw_retention_days', 7, 1, 90)) . ' ' . e(t('admin.common.days', 'days')) . '.</p>';
    if (current_user() && telemetry_setting_enabled('telemetry_admin_excluded', '1')) {
        echo '<p><strong>' . e(t('admin.telemetry.admin_excluded_strong')) . '</strong> ' . e(t('admin.telemetry.admin_excluded_help')) . '</p>';
    }
    echo '<p>' . e(t('admin.telemetry.collector_note', 'The public browser collector uses a neutral first-party endpoint to avoid false positives from privacy filters that block asset or route names containing telemetry.')) . '</p>';
    echo '</div></section>';

    echo '<section class="panel"><h2>' . e(t('admin.telemetry.last_30_days', 'Last 30 days')) . '</h2><div class="metric-grid">';
    render_telemetry_metric_card(t('admin.telemetry.metric_anonymous_sessions', 'Anonymous sessions'), (string) telemetry_metric_events('public.sessions', 30));
    render_telemetry_metric_card(t('admin.telemetry.metric_page_views', 'Page views'), (string) telemetry_metric_events('public.page_views', 30));
    render_telemetry_metric_card(t('admin.telemetry.metric_photo_opens', 'Photo opens'), (string) telemetry_metric_events('photo.views', 30));
    render_telemetry_metric_card(t('admin.telemetry.metric_total_capped_photo_time', 'Total capped photo time'), number_format(telemetry_metric_sum('photo.view_seconds', 30), 0) . ' s');
    render_telemetry_metric_card(t('admin.telemetry.metric_client_errors', 'Client errors'), (string) telemetry_metric_events('client.errors', 30));
    render_telemetry_metric_card(t('admin.telemetry.metric_image_bytes_measured', 'Image bytes measured'), telemetry_format_bytes(telemetry_metric_sum('media.image.bytes', 30) + telemetry_metric_sum('media.thumbnail.bytes', 30), 1));
    echo '</div></section>';

    echo '<section class="panel telemetry-settings-panel"><h2>' . e(t('admin.telemetry.settings', 'Settings')) . '</h2>';
    echo '<form method="post" action="' . e(url_for('admin_telemetry_settings')) . '" class="form-grid">' . csrf_field();
    render_telemetry_checkbox('telemetry_enabled', t('admin.telemetry.setting_enable_subsystem', 'Enable telemetry subsystem'), $settings);
    render_telemetry_checkbox('telemetry_public_usage_enabled', t('admin.telemetry.setting_collect_public_usage', 'Collect anonymous public usage telemetry'), $settings);
    render_telemetry_checkbox('telemetry_performance_enabled', t('admin.telemetry.setting_collect_performance', 'Collect sampled browser performance metrics'), $settings);
    render_telemetry_checkbox('telemetry_cache_enabled', t('admin.telemetry.setting_collect_cache', 'Collect cache efficiency metrics'), $settings);
    render_telemetry_checkbox('telemetry_database_enabled', t('admin.telemetry.setting_collect_database', 'Collect database health metrics'), $settings);
    render_telemetry_checkbox('telemetry_respect_dnt', t('admin.telemetry.setting_respect_dnt', 'Respect Do Not Track'), $settings);
    render_telemetry_checkbox('telemetry_admin_excluded', t('admin.telemetry.setting_exclude_admins', 'Exclude logged-in admins from public telemetry'), $settings);
    echo '<label>' . e(t('admin.telemetry.max_photo_view_time', 'Maximum photo view time counted, seconds')) . '<input type="number" min="10" max="3600" name="telemetry_max_photo_view_seconds" value="' . e($settings['telemetry_max_photo_view_seconds'] ?? '900') . '"><span class="muted">' . e(t('admin.telemetry.max_photo_view_time_hint', 'When someone opens a photo, we only count the first part of that session. If they leave the tab open for a long time, we stop counting after this limit so one forgotten tab does not make the numbers look bigger than real use.')) . '</span></label>';
    echo '<label>' . e(t('admin.telemetry.raw_event_retention', 'Raw event retention, days')) . '<input type="number" min="1" max="90" name="telemetry_raw_retention_days" value="' . e($settings['telemetry_raw_retention_days'] ?? '7') . '"><span class="muted">' . e(t('admin.telemetry.raw_event_retention_hint', 'These are the detailed, line-by-line records. They are useful when you want to inspect exactly what happened, but they take the most space. This setting decides how long we keep the full detail before older entries are removed or condensed.')) . '</span></label>';
    echo '<label>' . e(t('admin.telemetry.hourly_retention', 'Hourly aggregate retention, days')) . '<input type="number" min="7" max="730" name="telemetry_hourly_retention_days" value="' . e($settings['telemetry_hourly_retention_days'] ?? '90') . '"><span class="muted">' . e(t('admin.telemetry.hourly_retention_hint', 'These are the summary totals that say, for example, how many page views happened in each hour. They are much smaller than raw logs and are good for recent history, charts, and quick checks.')) . '</span></label>';
    echo '<label>' . e(t('admin.telemetry.daily_retention', 'Daily aggregate retention, days')) . '<input type="number" min="30" max="3650" name="telemetry_daily_retention_days" value="' . e($settings['telemetry_daily_retention_days'] ?? '730') . '"><span class="muted">' . e(t('admin.telemetry.daily_retention_hint', 'These are the broad day-by-day totals. They are the lightest records we keep and are meant for long-term trends, like comparing this month with last month or last year.')) . '</span></label>';
    echo '<div class="bulk-row"><button type="submit">' . e(t('admin.telemetry.save_settings', 'Save telemetry settings')) . '</button><a class="button secondary" href="' . e(url_for('admin_telemetry_maintenance')) . '">' . e(t('admin.telemetry.run_rollup_purge', 'Run rollup and purge now')) . '</a></div>';
    echo '</form></section>';

    render_telemetry_tables();
    render_footer();
}

/**
 * Render one checkbox setting row.
 */
function render_telemetry_checkbox(string $key, string $label, array $settings): void
{
    // $checked stores the checkbox state for the telemetry setting.
    $checked = (($settings[$key] ?? '0') === '1') ? ' checked' : '';
    echo '<label class="checkbox-row"><input type="checkbox" name="' . e($key) . '" value="1"' . $checked . '> ' . e($label) . '</label>';
}

/**
 * Render telemetry dashboard tables.
 */
function render_telemetry_tables(): void
{
    echo '<section class="panel"><h2>' . e(t('admin.telemetry.top_viewed_photos', 'Top viewed photos')) . '</h2>';
    render_telemetry_photo_table(telemetry_top_photos(), 'photo_views', t('admin.telemetry.views', 'Views'));
    echo '</section>';

    echo '<section class="panel"><h2>' . e(t('admin.telemetry.longest_viewed_photos', 'Longest viewed photos')) . '</h2>';
    render_telemetry_photo_table(telemetry_longest_viewed_photos(), 'avg_view_seconds', t('admin.telemetry.average_capped_seconds', 'Average capped seconds'));
    echo '</section>';

    echo '<section class="panel telemetry-split"><div><h2>' . e(t('admin.telemetry.browser_mix', 'Browser mix')) . '</h2>';
    render_telemetry_key_value_table(telemetry_browser_mix(), 'browser_family', 'sessions', t('admin.telemetry.browser', 'Browser'), t('admin.telemetry.sessions', 'Sessions'));
    echo '</div><div><h2>' . e(t('admin.telemetry.cache_events', 'Cache events')) . '</h2>';
    render_telemetry_key_value_table(telemetry_cache_mix(), 'cache_result', 'events', t('admin.telemetry.cache_result', 'Cache result'), t('admin.telemetry.events', 'Events'));
    echo '</div></section>';
}

/**
 * Render a photo telemetry table.
 */
function render_telemetry_photo_table(array $rows, string $valueKey, string $valueLabel): void
{
    if (!$rows) {
        echo '<p class="muted">' . e(t('admin.telemetry.no_data_yet', 'No telemetry data yet.')) . '</p>';
        return;
    }
    echo '<table><thead><tr><th>' . e(t('admin.telemetry.photo', 'Photo')) . '</th><th>' . e(t('admin.telemetry.gallery', 'Gallery')) . '</th><th>' . e($valueLabel) . '</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td>' . e((string) $row['filename']) . '</td><td>' . e((string) $row['gallery_title']) . '</td><td>' . e(number_format((float) $row[$valueKey], 2)) . '</td></tr>';
    }
    echo '</tbody></table>';
}

/**
 * Render a simple key-value telemetry table.
 */
function render_telemetry_key_value_table(array $rows, string $keyColumn, string $valueColumn, string $keyLabel, string $valueLabel): void
{
    if (!$rows) {
        echo '<p class="muted">' . e(t('admin.telemetry.no_data_yet', 'No telemetry data yet.')) . '</p>';
        return;
    }
    echo '<table><thead><tr><th>' . e($keyLabel) . '</th><th>' . e($valueLabel) . '</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td>' . e((string) $row[$keyColumn]) . '</td><td>' . e((string) $row[$valueColumn]) . '</td></tr>';
    }
    echo '</tbody></table>';
}


/**
 * Render one telemetry report metric card for the standalone HTML export.
 */
function telemetry_export_metric_card(string $label, string $value, string $hint = ''): string
{
    return '<article class="metric"><strong>' . e($value) . '</strong><span>' . e($label) . '</span>' . ($hint !== '' ? '<small>' . e($hint) . '</small>' : '') . '</article>';
}

/**
 * Format one telemetry number for the standalone report.
 */
function telemetry_report_number(mixed $value, int $decimals = 0): string
{
    if ($value === null || $value === '') {
        return '0';
    }
    return number_format((float) $value, $decimals);
}

/**
 * Format one duration for the standalone report.
 */
function telemetry_report_duration(mixed $seconds): string
{
    $seconds = max(0, (int) round((float) ($seconds ?? 0)));
    if ($seconds < 60) {
        return $seconds . ' s';
    }
    if ($seconds < 3600) {
        return floor($seconds / 60) . ' min ' . ($seconds % 60) . ' s';
    }
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    return $hours . ' h ' . $minutes . ' min';
}

/**
 * Render one generic telemetry report table.
 */
function telemetry_export_table(array $rows, array $columns, string $emptyText = ''): string
{
    if (!$rows) {
        return '<p class="muted">' . e($emptyText !== '' ? $emptyText : t('admin.telemetry.no_data_yet', 'No telemetry data yet.')) . '</p>';
    }
    $html = '<div class="table-scroll"><table><thead><tr>';
    foreach ($columns as $column) {
        $html .= '<th>' . e((string) $column['label']) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($columns as $column) {
            $key = (string) $column['key'];
            $value = $row[$key] ?? '';
            if (isset($column['format']) && is_callable($column['format'])) {
                $value = (string) $column['format']($value, $row);
            }
            $html .= '<td>' . e((string) $value) . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div>';
}


/**
 * Render a photo engagement table for the standalone report.
 */
function telemetry_export_photo_table(array $rows, string $valueKey, string $valueLabel): string
{
    return telemetry_export_table($rows, [
        ['key' => 'filename', 'label' => t('admin.telemetry.photo', 'Photo')],
        ['key' => 'gallery_title', 'label' => t('admin.telemetry.gallery', 'Gallery')],
        ['key' => $valueKey, 'label' => $valueLabel, 'format' => static function ($value) use ($valueKey): string {
            if ($valueKey === 'avg_view_seconds') {
                return telemetry_report_number($value, 2) . ' s';
            }
            return telemetry_report_number($value, 0);
        }],
    ]);
}

/**
 * Render one compact bar chart from labeled rows.
 */
function telemetry_export_bar_chart(array $rows, string $labelKey, string $valueKey, string $valueSuffix = ''): string
{
    if (!$rows) {
        return '<p class="muted">' . e(t('admin.telemetry.no_data_yet', 'No telemetry data yet.')) . '</p>';
    }
    $max = 0.0;
    foreach ($rows as $row) {
        $max = max($max, (float) ($row[$valueKey] ?? 0));
    }
    if ($max <= 0) {
        return '<p class="muted">' . e(t('admin.telemetry.no_data_yet', 'No telemetry data yet.')) . '</p>';
    }
    $html = '<div class="bars">';
    foreach ($rows as $row) {
        $label = (string) ($row[$labelKey] ?? 'unknown');
        $value = (float) ($row[$valueKey] ?? 0);
        $width = max(2, min(100, (int) round(($value / $max) * 100)));
        $html .= '<div class="bar-row"><span class="bar-label">' . e($label) . '</span><span class="bar-track"><span class="bar-fill" style="width:' . $width . '%"></span></span><strong>' . e(telemetry_report_number($value, 0) . $valueSuffix) . '</strong></div>';
    }
    return $html . '</div>';
}

/**
 * Render a trend chart from daily aggregate rows.
 */
function telemetry_export_trend_chart(array $rows, string $valueKey, string $label): string
{
    if (!$rows) {
        return '<p class="muted">' . e(t('admin.telemetry.no_data_yet', 'No telemetry data yet.')) . '</p>';
    }
    $max = 0.0;
    foreach ($rows as $row) {
        $max = max($max, (float) ($row[$valueKey] ?? 0));
    }
    if ($max <= 0) {
        return '<p class="muted">' . e(t('admin.telemetry.no_data_yet', 'No telemetry data yet.')) . '</p>';
    }
    $html = '<div class="trend" aria-label="' . e($label) . '">';
    foreach ($rows as $row) {
        $value = (float) ($row[$valueKey] ?? 0);
        $height = max(3, min(100, (int) round(($value / $max) * 100)));
        $title = (string) ($row['report_date'] ?? '') . ': ' . telemetry_report_number($value, 0);
        $html .= '<span class="trend-column" title="' . e($title) . '"><i style="height:' . $height . '%"></i></span>';
    }
    return $html . '</div><p class="chart-caption">' . e($label) . '</p>';
}

/**
 * Download a standalone anonymous telemetry HTML report.
 */
function cms_admin_telemetry_export(): void
{
    require_admin();
    if (!telemetry_settings_schema_ready()) {
        http_response_code(409);
        header('Content-Type: text/plain; charset=utf-8');
        echo t('admin.telemetry.migrations_required_text', 'Telemetry tables are not available yet. Run database migrations first.');
        return;
    }

    $days = 30;
    $generatedAt = date('Y-m-d H:i:s');
    $fileName = 'php-gallery-telemetry-' . date('Ymd-His') . '.html';
    $sessionSummary = telemetry_report_session_summary($days);
    $dailyTrends = telemetry_report_daily_trends($days);
    $topGalleries = telemetry_report_top_galleries($days, 25);
    $topRoutes = telemetry_report_top_routes($days, 25);
    $browserSessions = telemetry_report_session_distribution('browser_family', $days, 12);
    $osSessions = telemetry_report_session_distribution('os_family', $days, 12);
    $deviceSessions = telemetry_report_session_distribution('device_type', $days, 12);
    $viewportSessions = telemetry_report_session_distribution('viewport_class', $days, 12);
    $entryReferrers = telemetry_report_session_distribution('entry_referrer_category', $days, 12);
    $landingRoutes = telemetry_report_session_distribution('first_route_name', $days, 20);
    $exitRoutes = telemetry_report_session_distribution('exit_route_name', $days, 20);
    $pageKinds = telemetry_report_metric_distribution('page_kind', $days, 'public.page_views', 12);
    $mediaVariants = telemetry_report_metric_distribution('media_variant', $days, 'media.thumbnail.bytes', 20);
    $imageVariants = telemetry_report_metric_distribution('media_variant', $days, 'media.image.bytes', 20);
    $cacheThumbnail = telemetry_report_metric_distribution('cache_result', $days, 'cache.thumbnail.hit', 12);
    $cacheMisses = telemetry_report_metric_distribution('cache_result', $days, 'cache.thumbnail.miss', 12);
    $performanceMetrics = telemetry_report_performance_metrics($days);
    $clientErrors = telemetry_report_client_errors($days, 25);
    $recentEvents = telemetry_report_recent_events($days, 80);
    $databaseSummary = telemetry_report_database_summary($days, 40);
    $databaseFingerprints = telemetry_report_database_fingerprints($days, 30);
    $jobRuns = telemetry_report_job_runs($days, 40);

    $sessions = (float) ($sessionSummary['sessions'] ?? 0);
    $pageViews = (float) ($sessionSummary['page_views'] ?? 0);
    $photoViews = (float) ($sessionSummary['photo_views'] ?? 0);
    $durationSeconds = (float) ($sessionSummary['duration_seconds'] ?? 0);
    $bouncedSessions = (float) ($sessionSummary['bounced_sessions'] ?? 0);
    $bounceRate = $sessions > 0 ? ($bouncedSessions / $sessions) * 100 : 0;
    $avgPagesPerSession = (float) ($sessionSummary['avg_pages_per_session'] ?? 0);
    $avgPhotosPerSession = (float) ($sessionSummary['avg_photos_per_session'] ?? 0);
    $avgDurationSeconds = (float) ($sessionSummary['avg_duration_seconds'] ?? 0);
    $mediaBytes = telemetry_metric_sum('media.image.bytes', $days) + telemetry_metric_sum('media.thumbnail.bytes', $days) + telemetry_metric_sum('media.download.bytes', $days);
    $thumbnailBytes = telemetry_metric_sum('media.thumbnail.bytes', $days);
    $imageBytes = telemetry_metric_sum('media.image.bytes', $days);
    $downloadBytes = telemetry_metric_sum('media.download.bytes', $days);
    $clientErrorCount = telemetry_metric_events('client.errors', $days);
    $photoSeconds = telemetry_metric_sum('photo.view_seconds', $days);
    $cacheHitEvents = telemetry_metric_events('cache.thumbnail.hit', $days) + telemetry_metric_events('cache.lightbox.hit', $days);
    $cacheMissEvents = telemetry_metric_events('cache.thumbnail.miss', $days) + telemetry_metric_events('cache.lightbox.miss', $days);
    $cacheEfficiency = ($cacheHitEvents + $cacheMissEvents) > 0 ? ($cacheHitEvents / ($cacheHitEvents + $cacheMissEvents)) * 100 : 0;
    // $databaseTotals stores aggregate DB telemetry counters prepared by the service layer.
    $databaseTotals = telemetry_report_database_totals($days);
    $dbQueryCount = (float) ($databaseTotals['query_count'] ?? 0);
    $dbSlowCount = (float) ($databaseTotals['slow_count'] ?? 0);
    $dbFailedCount = (float) ($databaseTotals['failed_count'] ?? 0);

    $style = ':root{color-scheme:light dark;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f4f6fb;color:#172033}body{margin:0;padding:32px}main{max-width:1320px;margin:0 auto}header,.panel{background:rgba(255,255,255,.94);border:1px solid rgba(90,108,140,.22);border-radius:24px;box-shadow:0 18px 55px rgba(28,43,70,.10);padding:24px;margin-bottom:22px}h1{margin:0 0 8px;font-size:34px}h2{margin:0 0 16px;font-size:22px}h3{margin:18px 0 10px;font-size:16px}.muted,p{color:#5b667a;line-height:1.55}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px}.metric{border:1px solid rgba(90,108,140,.20);border-radius:18px;padding:16px;background:linear-gradient(180deg,rgba(255,255,255,.98),rgba(246,248,252,.94))}.metric strong{display:block;font-size:28px;margin-bottom:4px}.metric span{display:block;color:#5b667a}.metric small{display:block;margin-top:8px;color:#6d778a}.split{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:18px}.table-scroll{overflow:auto;border-radius:16px;border:1px solid rgba(90,108,140,.18)}table{width:100%;border-collapse:collapse;min-width:680px}th,td{text-align:left;padding:11px 12px;border-bottom:1px solid rgba(90,108,140,.18);vertical-align:top;white-space:nowrap}th{background:rgba(77,105,165,.10);font-size:13px;text-transform:uppercase;letter-spacing:.03em}tr:last-child td{border-bottom:0}.privacy{background:#eef7f0;border-color:#b7dfc1}.bars{display:grid;gap:10px}.bar-row{display:grid;grid-template-columns:minmax(90px,160px) 1fr auto;align-items:center;gap:10px}.bar-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.bar-track{height:12px;border-radius:999px;background:rgba(77,105,165,.14);overflow:hidden}.bar-fill{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#5d7df2,#53b987)}.trend-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px}.trend{height:160px;display:flex;align-items:end;gap:4px;border:1px solid rgba(90,108,140,.18);border-radius:18px;padding:12px;background:linear-gradient(180deg,rgba(255,255,255,.7),rgba(77,105,165,.07))}.trend-column{flex:1;min-width:4px;height:100%;display:flex;align-items:end}.trend-column i{display:block;width:100%;border-radius:8px 8px 3px 3px;background:linear-gradient(180deg,#5d7df2,#8aa2ff)}.chart-caption{margin:8px 0 0;font-size:13px}.pill{display:inline-flex;align-items:center;border-radius:999px;padding:3px 8px;background:rgba(77,105,165,.12);font-size:12px;color:#384969}.section-note{margin-top:-6px}.summary-list{display:grid;gap:8px;margin:0;padding:0;list-style:none}.summary-list li{display:flex;justify-content:space-between;gap:14px;border-bottom:1px solid rgba(90,108,140,.14);padding:8px 0}.summary-list li:last-child{border-bottom:0}@media (prefers-color-scheme:dark){:root{background:#101521;color:#eef2fb}header,.panel{background:#171e2d;border-color:#303a50}.metric{background:#1c2435;border-color:#303a50}.muted,p,.metric span,.metric small{color:#aeb8cc}th{background:#222d43}.table-scroll,td,th{border-color:#303a50}.privacy{background:#18291e;border-color:#31563c}.bar-track{background:#263148}.pill{background:#263148;color:#d6dded}.trend{background:#182032;border-color:#303a50}.summary-list li{border-color:#303a50}}';

    $html = '<!doctype html><html lang="' . e(translation_active_language()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e(t('admin.telemetry.export_title', 'PHP Gallery telemetry report')) . '</title><style>' . $style . '</style></head><body><main>'
        . '<header><h1>' . e(t('admin.telemetry.export_heading', 'Anonymous telemetry report')) . '</h1><p>' . e(t('admin.telemetry.generated', 'Generated')) . ' ' . e($generatedAt) . '. ' . e(t('admin.telemetry.export_description', 'Local, privacy-safe usage and performance statistics for PHP Gallery.')) . '</p><p class="section-note">Report window: last ' . e((string) $days) . ' days. Inspired by common analytics reporting patterns: traffic, sessions, content engagement, acquisition source, device mix, performance, errors, cache efficiency, and operational database telemetry.</p></header>'
        . '<section class="panel privacy"><h2>' . e(t('admin.telemetry.privacy_status', 'Privacy status')) . '</h2><p>' . e(t('admin.telemetry.export_privacy_text', 'This export contains aggregated anonymous telemetry only. It does not include raw IP addresses, raw browser user-agent strings, raw referrer URLs, names, email addresses, account identifiers, request bodies, or exact locations.')) . '</p><p>' . e(t('admin.telemetry.public_telemetry_is', 'Public telemetry is')) . ' ' . (telemetry_public_usage_enabled() ? '<strong>' . e(t('admin.common.enabled', 'enabled')) . '</strong>' : '<strong>' . e(t('admin.common.disabled', 'disabled')) . '</strong>') . '. ' . e(t('admin.telemetry.raw_events_retained_for', 'Raw events are retained for')) . ' ' . e((string) telemetry_retention_days('telemetry_raw_retention_days', 7, 1, 90)) . ' ' . e(t('admin.common.days', 'days')) . '.</p></section>'
        . '<section class="panel"><h2>Executive overview</h2><div class="grid">'
        . telemetry_export_metric_card('Anonymous sessions', telemetry_report_number($sessions), 'Session hashes only, no raw visitor identifiers')
        . telemetry_export_metric_card('Page views', telemetry_report_number($pageViews), telemetry_report_number($avgPagesPerSession, 2) . ' per session')
        . telemetry_export_metric_card('Photo opens', telemetry_report_number($photoViews), telemetry_report_number($avgPhotosPerSession, 2) . ' per session')
        . telemetry_export_metric_card('Capped photo time', telemetry_report_duration($photoSeconds), 'Average session duration ' . telemetry_report_duration($avgDurationSeconds))
        . telemetry_export_metric_card('Bounce rate', telemetry_report_number($bounceRate, 1) . ' %', telemetry_report_number($bouncedSessions) . ' single-page sessions')
        . telemetry_export_metric_card('Client errors', telemetry_report_number($clientErrorCount), 'JavaScript error events')
        . telemetry_export_metric_card('Media measured', telemetry_format_bytes($mediaBytes, 1), 'Images, thumbnails, and downloads')
        . telemetry_export_metric_card('Cache efficiency', telemetry_report_number($cacheEfficiency, 1) . ' %', telemetry_report_number($cacheHitEvents) . ' hits, ' . telemetry_report_number($cacheMissEvents) . ' misses')
        . telemetry_export_metric_card('DB queries', telemetry_report_number($dbQueryCount), telemetry_report_number($dbSlowCount) . ' slow, ' . telemetry_report_number($dbFailedCount) . ' failed')
        . '</div></section>'
        . '<section class="panel"><h2>Daily trends</h2><div class="trend-grid">'
        . telemetry_export_trend_chart($dailyTrends, 'sessions', 'Sessions per day')
        . telemetry_export_trend_chart($dailyTrends, 'page_views', 'Page views per day')
        . telemetry_export_trend_chart($dailyTrends, 'photo_views', 'Photo opens per day')
        . telemetry_export_trend_chart($dailyTrends, 'media_bytes', 'Measured media bytes per day')
        . telemetry_export_trend_chart($dailyTrends, 'client_errors', 'Client errors per day')
        . '</div></section>'
        . '<section class="panel split"><div><h2>Traffic sources</h2>' . telemetry_export_bar_chart($entryReferrers, 'label', 'sessions') . '</div><div><h2>Device type</h2>' . telemetry_export_bar_chart($deviceSessions, 'label', 'sessions') . '</div><div><h2>Browser family</h2>' . telemetry_export_bar_chart($browserSessions, 'label', 'sessions') . '</div><div><h2>Operating system</h2>' . telemetry_export_bar_chart($osSessions, 'label', 'sessions') . '</div><div><h2>Viewport class</h2>' . telemetry_export_bar_chart($viewportSessions, 'label', 'sessions') . '</div><div><h2>Page kind</h2>' . telemetry_export_bar_chart($pageKinds, 'label', 'events') . '</div></section>'
        . '<section class="panel"><h2>Top galleries</h2>' . telemetry_export_table($topGalleries, [
            ['key' => 'title', 'label' => 'Gallery'],
            ['key' => 'slug', 'label' => 'Slug'],
            ['key' => 'page_views', 'label' => 'Page views', 'format' => fn($v) => telemetry_report_number($v)],
            ['key' => 'photo_views', 'label' => 'Photo opens', 'format' => fn($v) => telemetry_report_number($v)],
            ['key' => 'photo_seconds', 'label' => 'Photo time', 'format' => fn($v) => telemetry_report_duration($v)],
            ['key' => 'media_bytes', 'label' => 'Media bytes', 'format' => fn($v) => telemetry_format_bytes((float) $v, 1)],
        ]) . '</section>'
        . '<section class="panel"><h2>Top routes</h2>' . telemetry_export_table($topRoutes, [
            ['key' => 'route_name', 'label' => 'Route'],
            ['key' => 'page_views', 'label' => 'Page views', 'format' => fn($v) => telemetry_report_number($v)],
            ['key' => 'photo_views', 'label' => 'Photo opens', 'format' => fn($v) => telemetry_report_number($v)],
            ['key' => 'client_errors', 'label' => 'Client errors', 'format' => fn($v) => telemetry_report_number($v)],
            ['key' => 'media_bytes', 'label' => 'Media bytes', 'format' => fn($v) => telemetry_format_bytes((float) $v, 1)],
        ]) . '</section>'
        . '<section class="panel split"><div><h2>Landing routes</h2>' . telemetry_export_table($landingRoutes, [
            ['key' => 'label', 'label' => 'Route'],
            ['key' => 'sessions', 'label' => 'Sessions', 'format' => fn($v) => telemetry_report_number($v)],
            ['key' => 'page_views', 'label' => 'Page views', 'format' => fn($v) => telemetry_report_number($v)],
            ['key' => 'avg_duration_seconds', 'label' => 'Avg duration', 'format' => fn($v) => telemetry_report_duration($v)],
        ]) . '</div><div><h2>Exit routes</h2>' . telemetry_export_table($exitRoutes, [
            ['key' => 'label', 'label' => 'Route'],
            ['key' => 'sessions', 'label' => 'Sessions', 'format' => fn($v) => telemetry_report_number($v)],
            ['key' => 'page_views', 'label' => 'Page views', 'format' => fn($v) => telemetry_report_number($v)],
            ['key' => 'avg_duration_seconds', 'label' => 'Avg duration', 'format' => fn($v) => telemetry_report_duration($v)],
        ]) . '</div></section>'
        . '<section class="panel"><h2>Photo engagement</h2><div class="split"><div><h3>Top viewed photos</h3>' . telemetry_export_photo_table(telemetry_top_photos($days, 25), 'photo_views', t('admin.telemetry.views', 'Views')) . '</div><div><h3>Longest viewed photos</h3>' . telemetry_export_photo_table(telemetry_longest_viewed_photos($days, 25), 'avg_view_seconds', t('admin.telemetry.average_capped_seconds', 'Average capped seconds')) . '</div></div></section>'
        . '<section class="panel split"><div><h2>Thumbnail bytes by variant</h2>' . telemetry_export_table($mediaVariants, [
            ['key' => 'label', 'label' => 'Variant'],
            ['key' => 'events', 'label' => 'Events', 'format' => fn($v) => telemetry_report_number($v)],
            ['key' => 'value_sum', 'label' => 'Bytes', 'format' => fn($v) => telemetry_format_bytes((float) $v, 1)],
        ]) . '</div><div><h2>Image bytes by variant</h2>' . telemetry_export_table($imageVariants, [
            ['key' => 'label', 'label' => 'Variant'],
            ['key' => 'events', 'label' => 'Events', 'format' => fn($v) => telemetry_report_number($v)],
            ['key' => 'value_sum', 'label' => 'Bytes', 'format' => fn($v) => telemetry_format_bytes((float) $v, 1)],
        ]) . '</div></section>'
        . '<section class="panel"><h2>Media byte split</h2><div class="grid">'
        . telemetry_export_metric_card('Full images', telemetry_format_bytes($imageBytes, 1))
        . telemetry_export_metric_card('Thumbnails', telemetry_format_bytes($thumbnailBytes, 1))
        . telemetry_export_metric_card('Downloads', telemetry_format_bytes($downloadBytes, 1))
        . telemetry_export_metric_card('All measured media', telemetry_format_bytes($mediaBytes, 1))
        . '</div></section>'
        . '<section class="panel split"><div><h2>Thumbnail cache hit events</h2>' . telemetry_export_table($cacheThumbnail, [
            ['key' => 'label', 'label' => 'Cache result'],
            ['key' => 'events', 'label' => 'Events', 'format' => fn($v) => telemetry_report_number($v)],
        ]) . '</div><div><h2>Thumbnail cache miss events</h2>' . telemetry_export_table($cacheMisses, [
            ['key' => 'label', 'label' => 'Cache result'],
            ['key' => 'events', 'label' => 'Events', 'format' => fn($v) => telemetry_report_number($v)],
        ]) . '</div></section>'
        . '<section class="panel"><h2>Browser performance</h2>' . telemetry_export_table($performanceMetrics, [
            ['key' => 'metric_name', 'label' => 'Metric'],
            ['key' => 'samples', 'label' => 'Samples', 'format' => fn($v) => telemetry_report_number($v)],
            ['key' => 'avg_value', 'label' => 'Average ms/value', 'format' => fn($v) => telemetry_report_number($v, 2)],
            ['key' => 'min_value', 'label' => 'Minimum', 'format' => fn($v) => telemetry_report_number($v, 2)],
            ['key' => 'max_value', 'label' => 'Maximum', 'format' => fn($v) => telemetry_report_number($v, 2)],
        ]) . '</section>'
        . '<section class="panel"><h2>Client errors</h2>' . telemetry_export_table($clientErrors, [
            ['key' => 'error_kind', 'label' => 'Error kind'],
            ['key' => 'route_name', 'label' => 'Route'],
            ['key' => 'events', 'label' => 'Events', 'format' => fn($v) => telemetry_report_number($v)],
            ['key' => 'last_seen', 'label' => 'Last seen'],
        ]) . '</section>'
        . '<section class="panel"><h2>Database telemetry</h2><div class="grid">'
        . telemetry_export_metric_card('Queries', telemetry_report_number($dbQueryCount))
        . telemetry_export_metric_card('Slow queries', telemetry_report_number($dbSlowCount))
        . telemetry_export_metric_card('Failed queries', telemetry_report_number($dbFailedCount))
        . '</div><h3>Routes, operations, and tables</h3>' . telemetry_export_table($databaseSummary, [
            ['key' => 'route_name', 'label' => 'Route'],
            ['key' => 'operation', 'label' => 'Operation'],
            ['key' => 'table_name', 'label' => 'Table'],
            ['key' => 'query_count', 'label' => 'Queries', 'format' => fn($v) => telemetry_report_number($v)],
            ['key' => 'failed_count', 'label' => 'Failed', 'format' => fn($v) => telemetry_report_number($v)],
            ['key' => 'slow_count', 'label' => 'Slow', 'format' => fn($v) => telemetry_report_number($v)],
            ['key' => 'latency_ms_sum', 'label' => 'Total latency', 'format' => fn($v) => telemetry_report_number($v) . ' ms'],
            ['key' => 'latency_ms_max', 'label' => 'Max latency', 'format' => fn($v) => telemetry_report_number($v) . ' ms'],
            ['key' => 'rows_returned_sum', 'label' => 'Rows returned', 'format' => fn($v) => telemetry_report_number($v)],
            ['key' => 'rows_affected_sum', 'label' => 'Rows affected', 'format' => fn($v) => telemetry_report_number($v)],
        ]) . '<h3>Query fingerprints</h3>' . telemetry_export_table($databaseFingerprints, [
            ['key' => 'query_fingerprint', 'label' => 'Fingerprint'],
            ['key' => 'route_name', 'label' => 'Route'],
            ['key' => 'operation', 'label' => 'Operation'],
            ['key' => 'table_name', 'label' => 'Table'],
            ['key' => 'query_count', 'label' => 'Queries', 'format' => fn($v) => telemetry_report_number($v)],
            ['key' => 'failed_count', 'label' => 'Failed', 'format' => fn($v) => telemetry_report_number($v)],
            ['key' => 'slow_count', 'label' => 'Slow', 'format' => fn($v) => telemetry_report_number($v)],
            ['key' => 'avg_latency_ms', 'label' => 'Avg latency', 'format' => fn($v) => telemetry_report_number($v, 2) . ' ms'],
            ['key' => 'max_latency_ms', 'label' => 'Max latency', 'format' => fn($v) => telemetry_report_number($v) . ' ms'],
        ]) . '</section>'
        . '<section class="panel"><h2>Telemetry access log</h2><p class="muted">This is an anonymized event log. It shows normalized buckets and object ids, not raw IP addresses, raw user agents, raw referrer URLs, request bodies, or personal identifiers.</p>' . telemetry_export_table($recentEvents, [
            ['key' => 'occurred_at', 'label' => 'Time'],
            ['key' => 'event_name', 'label' => 'Event'],
            ['key' => 'source', 'label' => 'Source'],
            ['key' => 'route_name', 'label' => 'Route'],
            ['key' => 'page_kind', 'label' => 'Kind'],
            ['key' => 'gallery_id', 'label' => 'Gallery'],
            ['key' => 'image_id', 'label' => 'Image'],
            ['key' => 'referrer_category', 'label' => 'Referrer'],
            ['key' => 'browser_family', 'label' => 'Browser'],
            ['key' => 'os_family', 'label' => 'OS'],
            ['key' => 'device_type', 'label' => 'Device'],
            ['key' => 'viewport_class', 'label' => 'Viewport'],
            ['key' => 'media_variant', 'label' => 'Variant'],
            ['key' => 'cache_result', 'label' => 'Cache'],
            ['key' => 'http_status', 'label' => 'Status'],
            ['key' => 'error_kind', 'label' => 'Error'],
            ['key' => 'value_bytes', 'label' => 'Bytes', 'format' => fn($v) => $v === null || $v === '' ? '' : telemetry_format_bytes((float) $v, 1)],
            ['key' => 'value_ms', 'label' => 'Value ms', 'format' => fn($v) => $v === null || $v === '' ? '' : telemetry_report_number($v) . ' ms'],
            ['key' => 'duration_ms_capped', 'label' => 'Duration', 'format' => fn($v) => $v === null || $v === '' ? '' : telemetry_report_number((float) $v / 1000, 2) . ' s'],
        ]) . '</section>'
        . '<section class="panel"><h2>Telemetry job runs</h2>' . telemetry_export_table($jobRuns, [
            ['key' => 'job_name', 'label' => 'Job'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'started_at', 'label' => 'Started'],
            ['key' => 'finished_at', 'label' => 'Finished'],
            ['key' => 'duration_ms', 'label' => 'Duration', 'format' => fn($v) => $v === null || $v === '' ? '' : telemetry_report_number((float) $v / 1000, 2) . ' s'],
            ['key' => 'gallery_id', 'label' => 'Gallery'],
            ['key' => 'image_id', 'label' => 'Image'],
            ['key' => 'item_count', 'label' => 'Items', 'format' => fn($v) => $v === null || $v === '' ? '' : telemetry_report_number($v)],
            ['key' => 'retry_count', 'label' => 'Retries', 'format' => fn($v) => telemetry_report_number($v)],
            ['key' => 'error_kind', 'label' => 'Error'],
        ]) . '</section>'
        . '<section class="panel"><h2>Stored telemetry volume</h2><div class="grid">'
        . telemetry_export_metric_card('Raw events', telemetry_report_number(telemetry_report_table_count('telemetry_events')))
        . telemetry_export_metric_card('Sessions', telemetry_report_number(telemetry_report_table_count('telemetry_sessions')))
        . telemetry_export_metric_card('Hourly metrics', telemetry_report_number(telemetry_report_table_count('telemetry_hourly_metrics')))
        . telemetry_export_metric_card('Daily metrics', telemetry_report_number(telemetry_report_table_count('telemetry_daily_metrics')))
        . telemetry_export_metric_card('DB query metrics', telemetry_report_number(telemetry_report_table_count('telemetry_db_query_metrics')))
        . telemetry_export_metric_card('Job runs', telemetry_report_number(telemetry_report_table_count('telemetry_job_runs')))
        . '</div></section>'
        . '</main></body></html>';

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
    // $result stores the rollup and purge result.
    $result = telemetry_run_maintenance();
    render_header(t('admin.telemetry.maintenance_title', 'Telemetry maintenance'));
    echo '<section class="hero"><h1>' . e(t('admin.telemetry.maintenance_title', 'Telemetry maintenance')) . '</h1><p>' . e(t('admin.telemetry.maintenance_completed', 'Rollup and retention cleanup completed.')) . '</p><nav class="nav"><a class="button" href="' . e(url_for('admin_telemetry')) . '">' . e(t('admin.telemetry.back_to_telemetry', 'Back to telemetry')) . '</a></nav></section>';
    echo '<section class="panel"><h2>' . e(t('admin.telemetry.result', 'Result')) . '</h2><pre>' . e(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre></section>';
    render_footer();
}
