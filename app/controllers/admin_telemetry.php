<?php
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
    render_header('Telemetry');
    echo '<section class="hero"><h1>Anonymous telemetry</h1><p>Local, privacy-safe usage and performance statistics for tuning the gallery.</p><nav class="nav">';
    echo '<a class="button secondary" href="' . e(url_for('admin_logs')) . '">Operational logs</a>';
    echo '<a class="button secondary" href="' . e(url_for('admin')) . '">Dashboard</a>';
    echo '</nav></section>';

    if (!$schemaReady) {
        echo '<section class="panel"><h2>Migrations required</h2><p>Telemetry tables are not available yet. Run database migrations first.</p></section>';
        render_footer();
        return;
    }

    echo '<section class="panel"><h2>Privacy status</h2><div class="telemetry-privacy-note">';
    echo '<p>This subsystem does not store raw IP addresses, raw browser user-agent strings, raw referrer URLs, names, email addresses, account identifiers, request bodies, or exact locations.</p>';
    echo '<p>Public telemetry is ' . (telemetry_public_usage_enabled() ? '<strong>enabled</strong>' : '<strong>disabled</strong>') . '. Raw events are retained for ' . e((string) telemetry_retention_days('telemetry_raw_retention_days', 7, 1, 90)) . ' days.</p>';
    echo '</div></section>';

    echo '<section class="panel"><h2>Last 30 days</h2><div class="metric-grid">';
    render_telemetry_metric_card('Anonymous sessions', (string) telemetry_metric_events('public.sessions', 30));
    render_telemetry_metric_card('Page views', (string) telemetry_metric_events('public.page_views', 30));
    render_telemetry_metric_card('Photo opens', (string) telemetry_metric_events('photo.views', 30));
    render_telemetry_metric_card('Total capped photo time', number_format(telemetry_metric_sum('photo.view_seconds', 30), 0) . ' s');
    render_telemetry_metric_card('Client errors', (string) telemetry_metric_events('client.errors', 30));
    render_telemetry_metric_card('Image bytes measured', number_format(telemetry_metric_sum('media.image.bytes', 30) + telemetry_metric_sum('media.thumbnail.bytes', 30), 0) . ' B');
    echo '</div></section>';

    echo '<section class="panel telemetry-settings-panel"><h2>Settings</h2>';
    echo '<form method="post" action="' . e(url_for('admin_telemetry_settings')) . '" class="form-grid">' . csrf_field();
    render_telemetry_checkbox('telemetry_enabled', 'Enable telemetry subsystem', $settings);
    render_telemetry_checkbox('telemetry_public_usage_enabled', 'Collect anonymous public usage telemetry', $settings);
    render_telemetry_checkbox('telemetry_performance_enabled', 'Collect sampled browser performance metrics', $settings);
    render_telemetry_checkbox('telemetry_cache_enabled', 'Collect cache efficiency metrics', $settings);
    render_telemetry_checkbox('telemetry_database_enabled', 'Collect database health metrics', $settings);
    render_telemetry_checkbox('telemetry_respect_dnt', 'Respect Do Not Track', $settings);
    render_telemetry_checkbox('telemetry_admin_excluded', 'Exclude logged-in admins from public telemetry', $settings);
    echo '<label>Maximum photo view time counted, seconds<input type="number" min="10" max="3600" name="telemetry_max_photo_view_seconds" value="' . e($settings['telemetry_max_photo_view_seconds'] ?? '900') . '"></label>';
    echo '<label>Raw event retention, days<input type="number" min="1" max="90" name="telemetry_raw_retention_days" value="' . e($settings['telemetry_raw_retention_days'] ?? '7') . '"></label>';
    echo '<label>Hourly aggregate retention, days<input type="number" min="7" max="730" name="telemetry_hourly_retention_days" value="' . e($settings['telemetry_hourly_retention_days'] ?? '90') . '"></label>';
    echo '<label>Daily aggregate retention, days<input type="number" min="30" max="3650" name="telemetry_daily_retention_days" value="' . e($settings['telemetry_daily_retention_days'] ?? '730') . '"></label>';
    echo '<div class="bulk-row"><button type="submit">Save telemetry settings</button><a class="button secondary" href="' . e(url_for('admin_telemetry_maintenance')) . '">Run rollup and purge now</a></div>';
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
    echo '<section class="panel"><h2>Top viewed photos</h2>';
    render_telemetry_photo_table(telemetry_top_photos(), 'photo_views', 'Views');
    echo '</section>';

    echo '<section class="panel"><h2>Longest viewed photos</h2>';
    render_telemetry_photo_table(telemetry_longest_viewed_photos(), 'avg_view_seconds', 'Average capped seconds');
    echo '</section>';

    echo '<section class="panel telemetry-split"><div><h2>Browser mix</h2>';
    render_telemetry_key_value_table(telemetry_browser_mix(), 'browser_family', 'sessions', 'Browser', 'Sessions');
    echo '</div><div><h2>Cache events</h2>';
    render_telemetry_key_value_table(telemetry_cache_mix(), 'cache_result', 'events', 'Cache result', 'Events');
    echo '</div></section>';
}

/**
 * Render a photo telemetry table.
 */
function render_telemetry_photo_table(array $rows, string $valueKey, string $valueLabel): void
{
    if (!$rows) {
        echo '<p class="muted">No telemetry data yet.</p>';
        return;
    }
    echo '<table><thead><tr><th>Photo</th><th>Gallery</th><th>' . e($valueLabel) . '</th></tr></thead><tbody>';
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
        echo '<p class="muted">No telemetry data yet.</p>';
        return;
    }
    echo '<table><thead><tr><th>' . e($keyLabel) . '</th><th>' . e($valueLabel) . '</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td>' . e((string) $row[$keyColumn]) . '</td><td>' . e((string) $row[$valueColumn]) . '</td></tr>';
    }
    echo '</tbody></table>';
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
    render_header('Telemetry maintenance');
    echo '<section class="hero"><h1>Telemetry maintenance</h1><p>Rollup and retention cleanup completed.</p><nav class="nav"><a class="button" href="' . e(url_for('admin_telemetry')) . '">Back to telemetry</a></nav></section>';
    echo '<section class="panel"><h2>Result</h2><pre>' . e(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre></section>';
    render_footer();
}
