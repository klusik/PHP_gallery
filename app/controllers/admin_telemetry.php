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
 * Return one aggregate metric sum from hourly metrics.
 */
function telemetry_metric_sum(string $metricName, int $days = 30): float
{
    if (!telemetry_settings_schema_ready()) {
        return 0.0;
    }
    // $stmt stores the aggregate read query for one metric name.
    $stmt = db()->prepare('SELECT COALESCE(SUM(value_sum), 0) FROM telemetry_hourly_metrics WHERE metric_name = ? AND bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)');
    $stmt->execute([$metricName, $days]);
    return (float) $stmt->fetchColumn();
}

/**
 * Return one aggregate event count from hourly metrics.
 */
function telemetry_metric_events(string $metricName, int $days = 30): int
{
    if (!telemetry_settings_schema_ready()) {
        return 0;
    }
    // $stmt stores the aggregate count query for one metric name.
    $stmt = db()->prepare('SELECT COALESCE(SUM(event_count), 0) FROM telemetry_hourly_metrics WHERE metric_name = ? AND bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)');
    $stmt->execute([$metricName, $days]);
    return (int) $stmt->fetchColumn();
}

/**
 * Return top viewed photos using hourly aggregates.
 */
function telemetry_top_photos(int $days = 30, int $limit = 15): array
{
    if (!telemetry_settings_schema_ready()) {
        return [];
    }
    // $stmt stores the top photo view query.
    $stmt = db()->prepare('SELECT i.id, i.filename, g.title AS gallery_title, SUM(m.event_count) AS photo_views
        FROM telemetry_hourly_metrics m
        JOIN images i ON i.id = m.image_id
        JOIN galleries g ON g.id = i.gallery_id
        WHERE m.metric_name = ? AND m.bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY) AND m.image_id > 0
        GROUP BY i.id, i.filename, g.title
        ORDER BY photo_views DESC
        LIMIT ' . max(1, min(50, $limit)));
    $stmt->execute(['photo.views', $days]);
    return $stmt->fetchAll();
}

/**
 * Return longest viewed photos using capped view-time aggregates.
 */
function telemetry_longest_viewed_photos(int $days = 30, int $limit = 15): array
{
    if (!telemetry_settings_schema_ready()) {
        return [];
    }
    // $stmt stores the average capped view-time query.
    $stmt = db()->prepare('SELECT i.id, i.filename, g.title AS gallery_title, SUM(m.value_sum) / NULLIF(SUM(m.event_count), 0) AS avg_view_seconds, SUM(m.event_count) AS view_count
        FROM telemetry_hourly_metrics m
        JOIN images i ON i.id = m.image_id
        JOIN galleries g ON g.id = i.gallery_id
        WHERE m.metric_name = ? AND m.bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY) AND m.image_id > 0
        GROUP BY i.id, i.filename, g.title
        HAVING view_count > 0
        ORDER BY avg_view_seconds DESC
        LIMIT ' . max(1, min(50, $limit)));
    $stmt->execute(['photo.view_seconds', $days]);
    return $stmt->fetchAll();
}

/**
 * Return browser family mix using anonymous session aggregates.
 */
function telemetry_browser_mix(int $days = 30): array
{
    if (!telemetry_settings_schema_ready()) {
        return [];
    }
    // $stmt stores the browser mix query.
    $stmt = db()->prepare('SELECT browser_family, COUNT(*) AS sessions FROM telemetry_sessions WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY browser_family ORDER BY sessions DESC');
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

/**
 * Return cache result distribution from hourly metrics.
 */
function telemetry_cache_mix(int $days = 30): array
{
    if (!telemetry_settings_schema_ready()) {
        return [];
    }
    // $stmt stores the cache mix query.
    $stmt = db()->prepare('SELECT cache_result, SUM(event_count) AS events FROM telemetry_hourly_metrics WHERE metric_name LIKE ? AND bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY cache_result ORDER BY events DESC');
    $stmt->execute(['cache.%', $days]);
    return $stmt->fetchAll();
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
function telemetry_export_metric_card(string $label, string $value): string
{
    return '<article class="metric"><strong>' . e($value) . '</strong><span>' . e($label) . '</span></article>';
}

/**
 * Render one telemetry export key-value table.
 */
function telemetry_export_key_value_table(array $rows, string $keyColumn, string $valueColumn, string $keyLabel, string $valueLabel): string
{
    if (!$rows) {
        return '<p class="muted">' . e(t('admin.telemetry.no_data_yet', 'No telemetry data yet.')) . '</p>';
    }
    // $html stores the generated standalone table markup.
    $html = '<table><thead><tr><th>' . e($keyLabel) . '</th><th>' . e($valueLabel) . '</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr><td>' . e((string) $row[$keyColumn]) . '</td><td>' . e((string) $row[$valueColumn]) . '</td></tr>';
    }
    return $html . '</tbody></table>';
}

/**
 * Render one telemetry export photo table.
 */
function telemetry_export_photo_table(array $rows, string $valueKey, string $valueLabel): string
{
    if (!$rows) {
        return '<p class="muted">' . e(t('admin.telemetry.no_data_yet', 'No telemetry data yet.')) . '</p>';
    }
    // $html stores the generated standalone photo table markup.
    $html = '<table><thead><tr><th>' . e(t('admin.telemetry.photo', 'Photo')) . '</th><th>' . e(t('admin.telemetry.gallery', 'Gallery')) . '</th><th>' . e($valueLabel) . '</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr><td>' . e((string) $row['filename']) . '</td><td>' . e((string) $row['gallery_title']) . '</td><td>' . e(number_format((float) $row[$valueKey], 2)) . '</td></tr>';
    }
    return $html . '</tbody></table>';
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
    // $generatedAt stores the report timestamp shown in the static export.
    $generatedAt = date('Y-m-d H:i:s');
    // $fileName stores a safe local filename for the browser download.
    $fileName = 'php-gallery-telemetry-' . date('Ymd-His') . '.html';
    // $html stores the full standalone report document.
    $html = '<!doctype html><html lang="' . e(translation_active_language()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e(t('admin.telemetry.export_title', 'PHP Gallery telemetry report')) . '</title><style>'
        . ':root{color-scheme:light dark;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f4f6fb;color:#172033;}body{margin:0;padding:32px;}main{max-width:1180px;margin:0 auto;}header,.panel{background:rgba(255,255,255,.92);border:1px solid rgba(90,108,140,.22);border-radius:24px;box-shadow:0 18px 55px rgba(28,43,70,.10);padding:24px;margin-bottom:22px;}h1{margin:0 0 8px;font-size:34px;}h2{margin:0 0 16px;font-size:22px}.muted,p{color:#5b667a}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px}.metric{border:1px solid rgba(90,108,140,.20);border-radius:18px;padding:16px;background:linear-gradient(180deg,rgba(255,255,255,.96),rgba(246,248,252,.94));}.metric strong{display:block;font-size:28px;margin-bottom:4px}.metric span{color:#5b667a}.split{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px}table{width:100%;border-collapse:collapse;border-radius:16px;overflow:hidden}th,td{text-align:left;padding:11px 12px;border-bottom:1px solid rgba(90,108,140,.18)}th{background:rgba(77,105,165,.10)}tr:last-child td{border-bottom:0}.privacy{background:#eef7f0;border-color:#b7dfc1}@media (prefers-color-scheme:dark){:root{background:#101521;color:#eef2fb}header,.panel{background:#171e2d;border-color:#303a50}.metric{background:#1c2435;border-color:#303a50}.muted,p{color:#aeb8cc}th{background:#222d43}.privacy{background:#18291e;border-color:#31563c}}</style></head><body><main>'
        . '<header><h1>' . e(t('admin.telemetry.export_heading', 'Anonymous telemetry report')) . '</h1><p>' . e(t('admin.telemetry.generated', 'Generated')) . ' ' . e($generatedAt) . '. ' . e(t('admin.telemetry.export_description', 'Local, privacy-safe usage and performance statistics for PHP Gallery.')) . '</p></header>'
        . '<section class="panel privacy"><h2>' . e(t('admin.telemetry.privacy_status', 'Privacy status')) . '</h2><p>' . e(t('admin.telemetry.export_privacy_text', 'This export contains aggregated anonymous telemetry only. It does not include raw IP addresses, raw browser user-agent strings, raw referrer URLs, names, email addresses, account identifiers, request bodies, or exact locations.')) . '</p><p>' . e(t('admin.telemetry.public_telemetry_is', 'Public telemetry is')) . ' ' . (telemetry_public_usage_enabled() ? '<strong>' . e(t('admin.common.enabled', 'enabled')) . '</strong>' : '<strong>' . e(t('admin.common.disabled', 'disabled')) . '</strong>') . '. ' . e(t('admin.telemetry.raw_events_retained_for', 'Raw events are retained for')) . ' ' . e((string) telemetry_retention_days('telemetry_raw_retention_days', 7, 1, 90)) . ' ' . e(t('admin.common.days', 'days')) . '.</p></section>'
        . '<section class="panel"><h2>' . e(t('admin.telemetry.last_30_days', 'Last 30 days')) . '</h2><div class="grid">'
        . telemetry_export_metric_card(t('admin.telemetry.metric_anonymous_sessions', 'Anonymous sessions'), (string) telemetry_metric_events('public.sessions', 30))
        . telemetry_export_metric_card(t('admin.telemetry.metric_page_views', 'Page views'), (string) telemetry_metric_events('public.page_views', 30))
        . telemetry_export_metric_card(t('admin.telemetry.metric_photo_opens', 'Photo opens'), (string) telemetry_metric_events('photo.views', 30))
        . telemetry_export_metric_card(t('admin.telemetry.metric_total_capped_photo_time', 'Total capped photo time'), number_format(telemetry_metric_sum('photo.view_seconds', 30), 0) . ' s')
        . telemetry_export_metric_card(t('admin.telemetry.metric_client_errors', 'Client errors'), (string) telemetry_metric_events('client.errors', 30))
        . telemetry_export_metric_card(t('admin.telemetry.metric_image_bytes_measured', 'Image bytes measured'), telemetry_format_bytes(telemetry_metric_sum('media.image.bytes', 30) + telemetry_metric_sum('media.thumbnail.bytes', 30), 1))
        . '</div></section>'
        . '<section class="panel"><h2>' . e(t('admin.telemetry.top_viewed_photos', 'Top viewed photos')) . '</h2>' . telemetry_export_photo_table(telemetry_top_photos(30, 25), 'photo_views', t('admin.telemetry.views', 'Views')) . '</section>'
        . '<section class="panel"><h2>' . e(t('admin.telemetry.longest_viewed_photos', 'Longest viewed photos')) . '</h2>' . telemetry_export_photo_table(telemetry_longest_viewed_photos(30, 25), 'avg_view_seconds', t('admin.telemetry.average_capped_seconds', 'Average capped seconds')) . '</section>'
        . '<section class="panel split"><div><h2>' . e(t('admin.telemetry.browser_mix', 'Browser mix')) . '</h2>' . telemetry_export_key_value_table(telemetry_browser_mix(30), 'browser_family', 'sessions', t('admin.telemetry.browser', 'Browser'), t('admin.telemetry.sessions', 'Sessions')) . '</div><div><h2>' . e(t('admin.telemetry.cache_events', 'Cache events')) . '</h2>' . telemetry_export_key_value_table(telemetry_cache_mix(30), 'cache_result', 'events', t('admin.telemetry.cache_result', 'Cache result'), t('admin.telemetry.events', 'Events')) . '</div></section>'
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
