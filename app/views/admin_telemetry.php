<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_telemetry.php
 * Module Type: View
 *
 * Purpose:
 *   Renders Admin telemetry dashboard and maintenance-result presentation.
 *
 * Responsibilities:
 *   - Render privacy status, metrics, telemetry settings, and aggregate tables
 *   - Render maintenance result output
 *   - Render reusable metric, checkbox, photo-table, and key/value fragments
 *
 * Author:
 *   Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Schema inspection, telemetry reads/writes, retention policy, maintenance execution,
 *     URL generation, CSRF issuance, and admin identity checks remain outside this view.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Core\format_bytes;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Services\t;

/**
 * Render one telemetry metric card.
 *
 * @param string $label Metric label.
 * @param string $value Formatted metric value.
 * @param string $hint Optional explanatory hint.
 * @param list<array{date:string,value:?float,formatted:string}> $trend Observed
 *     daily values, preserving gaps and the metric's own unit.
 * @param ?int $trendDays Rolling chart window, or null for the legacy value-only card.
 * @return void Emit the labeled value, optional hint and optional daily chart.
 */
function view_render_telemetry_metric_card(
    string $label,
    string $value,
    string $hint = '',
    array $trend = [],
    ?int $trendDays = null,
): void {
    echo
        '<article class="metric-card"><strong>'
            . e($value)
            . '</strong><span>'
            . e($label)
            . '</span>';
    if ($hint !== '') {
        echo '<small>' . e($hint) . '</small>';
    }
    if ($trendDays !== null) {
        view_render_telemetry_sparkline($trend, $label, $trendDays);
    }
    echo '</article>';
}

/**
 * Render a compact daily bar chart with native exact-value tooltips.
 *
 * @param list<array{date:string,value:?float,formatted:string}> $points Ordered
 *     daily pairs; null values and absent dates remain gaps in the date axis.
 * @param string $label Localized metric name used as the accessible image title.
 * @param int $days Rolling reporting window; partial boundary days share its axis.
 * @return void Emit a Theme-colored SVG or an explicit unavailable-detail note.
 */
function view_render_telemetry_sparkline(
    array $points,
    string $label,
    int $days,
): void {
    $observed = array_filter(
        $points, static fn(array $point): bool => $point['value'] !== null,
    );
    if ($observed === []) {
        $unavailable = t('admin.telemetry.daily_unavailable', 'No daily detail');
        echo '<small class="telemetry-trend-empty" title="' . e($unavailable)
            . '" aria-label="' . e($unavailable) . '">—</small>';
        return;
    }
    $maximum = max(array_column($observed, 'value'));
    $last = new \DateTimeImmutable($points[array_key_last($points)]['date']);
    // Normalize drawing geometry to a 160 by 32 unit SVG. One extra slot
    // represents the partial first calendar day of a rolling day window.
    $step = 160 / ($days + 1);
    echo '<svg class="telemetry-sparkline" viewBox="0 0 160 32" role="img"'
        . ' aria-label="' . e($label . ': '
            . t('admin.telemetry.daily_detail', 'Daily detail')) . '">'
        . '<title>' . e($label) . '</title>'
        . '<path class="telemetry-trend-baseline" d="M0 31H160"/>';
    foreach ($points as $point) {
        if ($point['value'] === null) {
            continue;
        }
        $date = new \DateTimeImmutable($point['date']);
        $offset = (int) $last->diff($date)->days;
        $x = 160 - ($offset + 1) * $step;
        $height = $maximum > 0 ? 28 * $point['value'] / $maximum : 0;
        echo '<g><title>' . e($point['date'] . ': ' . $point['formatted'])
            . '</title><rect class="telemetry-trend-hit" x="' . e((string) $x)
            . '" y="0" width="' . e((string) $step) . '" height="32"/>'
            . '<rect class="telemetry-trend-bar" x="'
            . e((string) ($x + $step * .15)) . '" y="'
            . e((string) (30 - $height)) . '" width="'
            . e((string) ($step * .7)) . '" height="'
            . e((string) $height) . '" rx="1"/></g>';
    }
    echo '</svg>';
}

/**
 * Render one telemetry checkbox setting row.
 *
 * @param string $key Setting key.
 * @param string $label Translated setting label.
 * @param bool $checked Whether the setting is enabled.
 * @return void Emit the labeled telemetry setting checkbox.
 */
function view_render_telemetry_checkbox(
    string $key,
    string $label,
    bool $checked,
): void {
    echo
        '<label class="checkbox-row"><input type="checkbox" name="'
            . e($key)
            . '" value="1"'
            . ($checked ? ' checked' : '')
            . '> '
            . e($label)
            . '</label>';
}

/**
 * Render a photo telemetry table for one selected metric.
 *
 * @param list<array<string,int|float|string|null>> $rows Photo rows with filename,
 *     gallery_title, optional prepared photo_url/gallery_url/preview_url and
 *     the selected numeric metric field.
 * @param string $valueKey Metric field selected for display.
 * @param string $valueLabel Heading for the displayed metric.
 * @return void Emit the photo table or its empty-state message.
 */
function view_render_telemetry_photo_table(
    array $rows,
    string $valueKey,
    string $valueLabel,
): void {
    if ($rows === []) {
        echo
            '<p class="muted">'
                . e(t('admin.telemetry.no_data_yet', 'No telemetry data yet.'))
                . '</p>';
        return;
    }
    echo
        '<table><thead><tr><th>'
            . e(t('admin.telemetry.photo', 'Photo'))
            . '</th><th>'
            . e(t('admin.telemetry.gallery', 'Gallery'))
            . '</th><th>'
            . e($valueLabel)
            . '</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $photo = e((string) ($row['filename'] ?? ''));
        $gallery = e((string) ($row['gallery_title'] ?? ''));
        if (!empty($row['photo_url'])) {
            $photo = '<a class="telemetry-entity-link" href="'
                . e((string) $row['photo_url']) . '">' . $photo . '</a>';
        }
        if (!empty($row['preview_url'])) {
            $previewLabel = t('admin.gallery_editor.preview', 'Preview')
                . ' ' . (string) ($row['filename'] ?? '');
            $photo = '<span class="telemetry-photo-target"><button type="button" class="telemetry-photo-preview"'
                . ' data-admin-image-preview data-preview-src="'
                . e((string) $row['preview_url']) . '" data-preview-name="'
                . e((string) ($row['filename'] ?? ''))
                . '" data-preview-close-label="'
                . e(t('admin.gallery_editor.images_close_preview', 'Close preview'))
                . '" aria-label="' . e($previewLabel) . '" title="'
                . e($previewLabel) . '">' . view_admin_menu_icon('galleries')
                . '</button>' . $photo . '</span>';
        }
        if (!empty($row['gallery_url'])) {
            $gallery = '<a class="telemetry-entity-link" href="'
                . e((string) $row['gallery_url']) . '">' . $gallery . '</a>';
        }
        echo
            '<tr><td>'
                . $photo
                . '</td><td>'
                . $gallery
                . '</td><td>'
                // Opens are discrete counts; time averages retain two decimals.
                . e(number_format((float) ($row[$valueKey] ?? 0), $valueKey === 'photo_views' ? 0 : 2))
                . '</td></tr>';
    }
    echo '</tbody></table>';
}

/**
 * Render a telemetry table using selected row fields as its key and value.
 *
 * @param array<int,array<string,mixed>> $rows Prepared telemetry rows.
 * @param string $keyColumn Row key used for the first column.
 * @param string $valueColumn Row key used for the second column.
 * @param string $keyLabel Heading for the first column.
 * @param string $valueLabel Heading for the second column.
 * @return void Emit the key/value table or its empty-state message.
 */
function view_render_telemetry_key_value_table(
    array $rows,
    string $keyColumn,
    string $valueColumn,
    string $keyLabel,
    string $valueLabel,
): void {
    if ($rows === []) {
        echo
            '<p class="muted">'
                . e(t('admin.telemetry.no_data_yet', 'No telemetry data yet.'))
                . '</p>';
        return;
    }
    echo
        '<table><thead><tr><th>'
            . e($keyLabel)
            . '</th><th>'
            . e($valueLabel)
            . '</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo
            '<tr><td>'
                . e((string) ($row[$keyColumn] ?? ''))
                . '</td><td>'
                . e((string) ($row[$valueColumn] ?? ''))
                . '</td></tr>';
    }
    echo '</tbody></table>';
}

/**
 * Render one isolated telemetry preference using service-prepared field metadata.
 *
 * @param array{preferences:array<string,array{input_type:string,current:string,
 *     default:string,validation:array<string,int>,available:bool}>,
 *     settings_action_url:string,csrf_html:string} $model Prepared controls,
 *     canonical defaults, action URL and authenticated native CSRF field.
 * @param string $key Allowlisted telemetry preference identifier.
 * @param string $label Localized short field label.
 * @param string $hint Localized explanation retained in contextual help.
 * @param string $tab Owning collection/storage navigation panel.
 * @return void Emit one native scoped form with accessible persistence feedback.
 */
function view_render_telemetry_preference(
    array $model,
    string $key,
    string $label,
    string $hint,
    string $tab,
): void {
    $field = $model['preferences'][$key];
    $checkbox = $field['input_type'] === 'checkbox';
    $default = $checkbox
        ? ($field['default'] === '1'
            ? t('admin.common.enabled', 'Enabled')
            : t('admin.common.disabled', 'Disabled'))
        : $field['default'];
    $factory = t('admin.dashboard.content_factory_tooltip',
        'Factory default: {value}', ['value' => $default]);
    $id = 'telemetry-' . $key;
    echo '<form class="telemetry-preference" method="post" action="'
        . e($model['settings_action_url']) . '" data-telemetry-preference>'
        . $model['csrf_html']
        . '<input type="hidden" name="telemetry_preference" value="' . e($key) . '">'
        . '<input type="hidden" name="telemetry_tab" value="' . e($tab) . '">'
        . '<label for="' . e($id) . '" title="' . e($factory) . '">';
    if (!$checkbox) {
        echo '<span>' . e($label) . '</span>';
    }
    echo '<input id="' . e($id) . '" data-telemetry-input name="' . e($key)
        . '" type="' . ($checkbox ? 'checkbox' : 'number')
        . '" title="' . e($factory) . '" aria-description="' . e($factory) . '"';
    if ($checkbox) {
        echo ' value="1"' . ($field['current'] === '1' ? ' checked' : '');
    } else {
        echo ' value="' . e($field['current']) . '"';
        foreach ($field['validation'] as $attribute => $value) {
            echo ' ' . e($attribute) . '="' . e((string) $value) . '"';
        }
        echo ' required';
    }
    echo empty($field['available']) ? ' disabled' : '';
    echo '>';
    if ($checkbox) {
        echo '<span>' . e($label) . '</span>';
    }
    echo '</label>';
    view_render_admin_dashboard_compact_help($id . '-help', $label, [$hint]);
    echo '<button type="submit" data-telemetry-save>'
        . e(t('admin.common.save', 'Save')) . '</button>'
        . '<span data-telemetry-feedback role="status" aria-live="polite">'
        . (empty($field['available'])
            ? e(t('admin.telemetry.settings_unavailable', 'Stored preferences are unavailable.')) : '')
        . '</span>'
        . '</form>';
}

/**
 * Render the compact telemetry workspace from prepared values and destinations.
 *
 * @param array{page_title:string,settings_url:string,logs_url:string,
 *     export_urls:array<string,string>,schema_ready:bool,
 *     schema_title:string,schema_message:string,public_enabled:bool,
 *     admin_excluded:bool,active_tab:string,
 *     tab_urls:array<string,string>,metrics:list<array{label:string,value:string,
 *     trend?:list<array{date:string,value:?float,formatted:string}>}>,
 *     trend_days?:int,trend_rows?:list<array{date:string,values:list<string>}>,
 *     preferences:array<string,array{input_type:string,current:string,
 *     default:string,validation:array<string,int>,available:bool}>,
 *     settings_action_url:string,csrf_html:string,maintenance_url:string,
 *     tables:array<string,list<array<string,int|float|string|null>>>} $viewModel
 *     Prepared status, preference metadata, safe links and aggregate tables.
 * @return void Emit native navigable panels, scoped forms and read-only reports.
 */
function view_render_admin_telemetry_dashboard(array $viewModel): void
{
    render_header($viewModel['page_title']);
    echo '<div class="admin-telemetry-page" data-telemetry-workspace'
        . ' data-saving-label="' . e(t('admin.features.saving', 'Saving…')) . '"'
        . ' data-failed-label="' . e(t('admin.telemetry.preference_unverified',
            'Save could not be verified. Reload the saved state before trying again.')) . '"'
        . ' data-reload-label="' . e(t('admin.telemetry.reload_saved', 'Reload saved state')) . '"'
        . ' data-enabled-label="' . e(t('admin.common.enabled', 'Enabled')) . '"'
        . ' data-disabled-label="' . e(t('admin.common.disabled', 'Disabled')) . '">';
    echo '<header class="telemetry-toolbar"><h1>'
        . e(t('admin.telemetry.title', 'Anonymous telemetry')) . '</h1>';
    view_render_admin_dashboard_compact_help('telemetry-about',
        t('admin.telemetry.title', 'Anonymous telemetry'), [
            t('admin.telemetry.description', 'Local, privacy-safe usage and performance statistics for tuning the gallery.'),
            t('admin.telemetry.collector_note', 'The public browser collector uses a neutral first-party endpoint to avoid false positives from privacy filters that block asset or route names containing telemetry.'),
        ]);
    echo '<span class="telemetry-collection-state">'
        . e(t('admin.telemetry.public_telemetry_is', 'Public telemetry is')) . ' <strong data-telemetry-public-status>'
        . e(!$viewModel['schema_ready'] ? t('admin.common.unknown', 'Unknown') : ($viewModel['public_enabled']
            ? t('admin.common.enabled', 'Enabled')
            : t('admin.common.disabled', 'Disabled'))) . '</strong></span></header>';
    if (!$viewModel['schema_ready']) {
        echo '<section class="telemetry-group"><h2>'
            . e($viewModel['schema_title']) . '</h2><p>'
            . e($viewModel['schema_message']) . '</p></section></div>';
        render_footer();
        return;
    }
    $tabs = [
        'overview' => t('admin.telemetry.tab_overview', 'Overview'),
        'collection' => t('admin.telemetry.tab_collection', 'Collection and privacy'),
        'storage' => t('admin.telemetry.tab_storage', 'History and maintenance'),
        'reports' => t('admin.telemetry.tab_reports', 'Reports'),
    ];
    echo '<nav class="telemetry-tabs" aria-label="' . e($viewModel['page_title']) . '">';
    foreach ($tabs as $tab => $label) {
        echo '<a href="' . e($viewModel['tab_urls'][$tab])
            . '" data-telemetry-tab="' . e($tab)
            . '" id="telemetry-tab-' . e($tab) . '"'
            . ($viewModel['active_tab'] === $tab ? ' aria-current="page"' : '')
            . '>' . e($label) . '</a>';
    }
    echo '</nav>';
    foreach ($tabs as $tab => $label) {
        echo '<section id="telemetry-panel-' . e($tab)
            . '" data-telemetry-panel="' . e($tab)
            . '" aria-labelledby="telemetry-tab-' . e($tab) . '"'
            . ($viewModel['active_tab'] !== $tab ? ' hidden' : '') . '>';
        if ($tab === 'overview') {
            echo '<div class="telemetry-section-heading"><h2>'
                . e(t('admin.telemetry.last_30_days', 'Last 30 days')) . '</h2>';
            view_render_admin_dashboard_compact_help('telemetry-overview-help', $label, [
                t('admin.telemetry.overview_help', 'Historical totals remain available when collection is paused. A session is anonymous; photo time is capped by the configured viewing limit.'),
                t('admin.telemetry.daily_help', 'Bars show retained daily observations, with a separate scale for each metric. Hover for exact values or open Daily detail. Missing or expired days are not filled with zeros. Image bytes exclude downloads.'),
            ]);
            if (!empty($viewModel['trend_rows'])) {
                $dates = $viewModel['trend_rows'];
                echo '<span class="telemetry-date-range"><time datetime="'
                    . e($dates[0]['date']) . '">' . e($dates[0]['date'])
                    . '</time> – <time datetime="'
                    . e($dates[array_key_last($dates)]['date']) . '">'
                    . e($dates[array_key_last($dates)]['date']) . '</time></span>';
            }
            echo '</div><div class="metric-grid">';
            foreach ($viewModel['metrics'] as $metric) {
                view_render_telemetry_metric_card(
                    $metric['label'], $metric['value'], '',
                    $metric['trend'] ?? [], $viewModel['trend_days'] ?? null,
                );
            }
            echo '</div>';
            if (!empty($viewModel['trend_rows'])) {
                echo '<details class="telemetry-daily-details"><summary>'
                    . e(t('admin.telemetry.daily_detail', 'Daily detail'))
                    . '</summary><table class="telemetry-daily-table"><caption>'
                    . e(t('admin.telemetry.daily_help', 'Bars show retained daily observations, with a separate scale for each metric. Hover for exact values or open Daily detail. Missing or expired days are not filled with zeros. Image bytes exclude downloads.'))
                    . '</caption><thead><tr><th scope="col">'
                    . e(t('admin.telemetry.date', 'Date')) . '</th>';
                foreach ($viewModel['metrics'] as $metric) {
                    echo '<th scope="col">' . e($metric['label']) . '</th>';
                }
                echo '</tr></thead><tbody>';
                foreach ($viewModel['trend_rows'] as $row) {
                    echo '<tr><th scope="row">' . e($row['date']) . '</th>';
                    foreach ($row['values'] as $index => $value) {
                        echo '<td data-label="'
                            . e($viewModel['metrics'][$index]['label']) . '">'
                            . e($value) . '</td>';
                    }
                    echo '</tr>';
                }
                echo '</tbody></table></details>';
            }
            view_render_telemetry_tables($viewModel['tables']);
        } elseif ($tab === 'collection') {
            echo '<div class="telemetry-groups"><section class="telemetry-group"><h2>'
                . e(t('admin.telemetry.group_collection', 'Collection')) . '</h2>';
            foreach ([
                ['telemetry_enabled', 'collect_master', 'Telemetry subsystem', 'help_master', 'Pause collection without deleting history or changing the other preferences.'],
                ['telemetry_public_usage_enabled', 'collect_usage', 'Public usage', 'help_usage', 'Count anonymous visits, page views and photo opens.'],
                ['telemetry_performance_enabled', 'collect_performance', 'Browser performance', 'help_performance', 'Collect sampled browser loading and media timings. These samples are not a complete trace of every visit.'],
                ['telemetry_cache_enabled', 'collect_cache', 'Cache efficiency', 'help_cache', 'Record measured cache hits and misses. This switch does not clear the cache.'],
                ['telemetry_database_enabled', 'collect_database', 'Database health', 'help_database', 'Collect normalized query fingerprints, durations and errors without storing raw query parameters.'],
            ] as [$key, $labelKey, $fallback, $hintKey, $hint]) {
                view_render_telemetry_preference($viewModel, $key,
                    t('admin.telemetry.' . $labelKey, $fallback),
                    t('admin.telemetry.' . $hintKey, $hint), $tab);
            }
            echo '</section><section class="telemetry-group"><div class="telemetry-group-heading"><h2>'
                . e(t('admin.telemetry.group_privacy', 'Privacy')) . '</h2>';
            view_render_admin_dashboard_compact_help('telemetry-privacy-help',
                t('admin.telemetry.privacy_status', 'Privacy status'), [
                    t('admin.telemetry.privacy_text', 'This subsystem does not store raw IP addresses, raw browser user-agent strings, raw referrer URLs, names, email addresses, account identifiers, request bodies, or exact locations.'),
                ]);
            echo '</div>';
            view_render_telemetry_preference($viewModel, 'telemetry_respect_dnt',
                t('admin.telemetry.collect_dnt', 'Respect Do Not Track'),
                t('admin.telemetry.dnt_help', 'Honor the visitor browser’s Do Not Track preference when collecting public usage.'), $tab);
            view_render_telemetry_preference($viewModel, 'telemetry_admin_excluded',
                t('admin.telemetry.collect_exclude_admins', 'Exclude signed-in admins'),
                t('admin.telemetry.admin_excluded_help'), $tab);
            echo '<p class="telemetry-note" data-telemetry-admin-status'
                . (!$viewModel['admin_excluded'] ? ' hidden' : '') . '>'
                . e(t('admin.telemetry.admin_excluded_strong')) . '</p>';
            echo '<a class="telemetry-related-link" href="' . e($viewModel['settings_url']) . '">'
                . e(t('admin.telemetry.other_privacy_settings', 'Other site privacy settings')) . ' ↗</a>'
                . '</section><section class="telemetry-group"><h2>'
                . e(t('admin.telemetry.group_view_time', 'Photo viewing time')) . '</h2>';
            view_render_telemetry_preference($viewModel, 'telemetry_max_photo_view_seconds',
                t('admin.telemetry.view_limit', 'Counted seconds per photo'),
                t('admin.telemetry.max_photo_view_time_hint', 'When someone opens a photo, we only count the first part of that session. If they leave the tab open for a long time, we stop counting after this limit so one forgotten tab does not make the numbers look bigger than real use.'), $tab);
            echo '</section></div>';
        } elseif ($tab === 'storage') {
            echo '<div class="telemetry-groups"><section class="telemetry-group"><h2>'
                . e(t('admin.telemetry.group_history', 'Usage history')) . '</h2>';
            foreach ([
                ['telemetry_raw_retention_days', 'retention_raw', 'Detailed events, days', 'raw_event_retention_hint', 'Detailed events are the largest records and support recent troubleshooting.'],
                ['telemetry_hourly_retention_days', 'retention_hourly', 'Hourly totals, days', 'hourly_retention_hint', 'Hourly totals are smaller summaries for recent trends.'],
                ['telemetry_daily_retention_days', 'retention_daily', 'Daily totals, days', 'daily_retention_hint', 'Daily totals support long-term trends.'],
            ] as [$key, $labelKey, $fallback, $hintKey, $hint]) {
                view_render_telemetry_preference($viewModel, $key,
                    t('admin.telemetry.' . $labelKey, $fallback),
                    t('admin.telemetry.' . $hintKey, $hint), $tab);
            }
            echo '</section><section class="telemetry-group"><h2>'
                . e(t('admin.telemetry.group_database', 'Database diagnostics')) . '</h2>';
            view_render_telemetry_preference($viewModel, 'telemetry_database_retention_days',
                t('admin.telemetry.retention_database', 'SQL diagnostics, days'),
                t('admin.telemetry.database_retention_hint', 'SQL query fingerprints are short-lived diagnostics. This independent retention does not shorten usage history.'), $tab);
            echo '</section><section class="telemetry-group"><div class="telemetry-group-heading"><h2>'
                . e(t('admin.telemetry.group_maintenance', 'Maintenance')) . '</h2>';
            view_render_admin_dashboard_compact_help('telemetry-maintenance-help',
                t('admin.telemetry.group_maintenance', 'Maintenance'), [
                    t('admin.telemetry.maintenance_help', 'Combine detailed events into summaries and remove records past their retention limits. This action changes stored telemetry; opening this tab does not run it.'),
                ]);
            echo '</div><form method="post" action="' . e($viewModel['maintenance_url'])
                . '">' . $viewModel['csrf_html'] . '<button type="submit">'
                . e(t('admin.telemetry.run_rollup_purge', 'Run rollup and purge now'))
                . '</button></form></section></div>';
        } else {
            echo '<div class="telemetry-groups"><section class="telemetry-group"><h2>'
                . e(t('admin.telemetry.group_reports', 'HTML reports')) . '</h2>'
                . '<p class="telemetry-note">'
                . e(t('admin.telemetry.report_help', 'Open a standalone report in a new tab. Classification describes observed traffic; unclassified traffic stays separate.'))
                . '</p>';
            foreach ([
                'all' => t('admin.telemetry.export_all_traffic', 'Export all traffic'),
                'non_bot' => t('admin.telemetry.export_non_bot_traffic', 'Export non-bot-classified'),
                'bot' => t('admin.telemetry.export_bot_traffic', 'Export bot-classified'),
                'unknown' => t('admin.telemetry.export_unknown_traffic', 'Export unclassified'),
            ] as $segment => $reportLabel) {
                echo '<a class="telemetry-report-link" target="_blank" rel="noopener" href="'
                    . e($viewModel['export_urls'][$segment]) . '">'
                    . e($reportLabel) . ' <span aria-hidden="true">↗</span></a>';
            }
            echo '</section><section class="telemetry-group"><h2>'
                . e(t('admin.telemetry.group_tools', 'Related tools')) . '</h2>'
                . '<a class="telemetry-report-link" href="' . e($viewModel['logs_url']) . '">'
                . e(t('admin.telemetry.operational_logs', 'Operational logs')) . ' ↗</a>'
                . '<p class="telemetry-note">'
                . e(t('admin.telemetry.logs_help', 'Open the separate Logs page for operational events and maintenance failures.'))
                . '</p></section></div>';
        }
        echo '</section>';
    }
    echo '</div>';
    render_footer();
}

/**
 * Render photo rankings and browser/cache summaries as independent compact groups.
 *
 * @param array{top_photos?:list<array{filename?:string,gallery_title?:string,
 *     photo_views?:int|float|string,photo_url?:string,gallery_url?:string,
 *     preview_url?:string}>,
 *     longest_photos?:list<array{filename?:string,gallery_title?:string,
 *     avg_view_seconds?:int|float|string,photo_url?:string,gallery_url?:string,
 *     preview_url?:string}>,
 *     browser_mix?:list<array{browser_family?:string,sessions?:int|string}>,
 *     cache_mix?:list<array{cache_result?:string|null,events?:int|string}>} $tables
 *     Controller-prepared ranking and summary rows; photo rows may also
 *     contain photo_url and gallery_url strings for native public navigation.
 * @return void Emit four independently flowing groups with native table semantics.
 */
function view_render_telemetry_tables(array $tables): void
{
    echo '<div class="telemetry-groups telemetry-report-groups">';
    foreach ([
        ['top_photos', 'top_viewed_photos', 'Top viewed photos', 'photo_views', 'views', 'Views'],
        ['longest_photos', 'longest_viewed_photos', 'Longest viewed photos', 'avg_view_seconds', 'average_capped_seconds', 'Average capped seconds'],
    ] as [$key, $headingKey, $heading, $metric, $metricKey, $metricLabel]) {
        echo '<section class="telemetry-group"><h2>'
            . e(t('admin.telemetry.' . $headingKey, $heading)) . '</h2>';
        view_render_telemetry_photo_table($tables[$key] ?? [], $metric,
            t('admin.telemetry.' . $metricKey, $metricLabel));
        echo '</section>';
    }
    foreach ([
        ['browser_mix', 'browser_family', 'sessions', 'browser_mix', 'Browser mix', 'browser', 'Browser', 'sessions', 'Sessions'],
        ['cache_mix', 'cache_result', 'events', 'cache_events', 'Cache events', 'cache_result', 'Cache result', 'events', 'Events'],
    ] as [$key, $keyColumn, $valueColumn, $headingKey, $heading, $keyLabel, $keyFallback, $valueLabel, $valueFallback]) {
        echo '<section class="telemetry-group"><h2>'
            . e(t('admin.telemetry.' . $headingKey, $heading)) . '</h2>';
        view_render_telemetry_key_value_table($tables[$key] ?? [],
            $keyColumn, $valueColumn, t('admin.telemetry.' . $keyLabel, $keyFallback),
            t('admin.telemetry.' . $valueLabel, $valueFallback));
        echo '</section>';
    }
    echo '</div>';
}

/**
 * Render the explicit CSRF-protected POST maintenance action.
 * @param array{title:string,status_message:string,back_url:string,
 *     result_json:string} $viewModel Prepared maintenance outcome and return URL.
 * @return void Render the outcome and optional technical result without running work.
 */
function view_render_admin_telemetry_maintenance(array $viewModel): void
{
    $title = (string) ($viewModel['title'] ?? '');
    render_header($title);
    echo
        '<div class="admin-telemetry-page" data-telemetry-workspace><header class="telemetry-toolbar"><h1>'
            . e($title)
            . '</h1><a class="telemetry-related-link" href="'
            . e($viewModel['back_url']) . '">'
            . e(t('admin.telemetry.back_to_telemetry', 'Back to telemetry'))
            . '</a></header><p class="telemetry-note">'
            . e(
                (string) (
                    $viewModel['status_message'] ?? t(
                        'admin.telemetry.maintenance_skipped',
                        'No maintenance slice ran. See the reason below.',
                    )
                ),
            )
            . '</p>';
    echo
        '<details class="telemetry-group"><summary>'
            . e(t('admin.telemetry.result', 'Result'))
            . '</summary><pre class="telemetry-maintenance-result">'
            . e((string) ($viewModel['result_json'] ?? ''))
            . '</pre></details></div>';
    render_footer();
}

/**
 * Render one metric card in the standalone telemetry export.
 *
 * @param string $label Metric label.
 * @param string $value Formatted metric value.
 * @param string $hint Optional explanatory hint.
 * @return string Rendered metric card markup.
 */
function view_telemetry_export_metric_card(
    string $label,
    string $value,
    string $hint = '',
): string {
    return (
        '<article class="metric"><strong>'
            . e($value)
            . '</strong><span>'
            . e($label)
            . '</span>'
            . ($hint !== '' ? '<small>' . e($hint) . '</small>' : '')
            . '</article>'
    );
}

/**
 * Format one numeric telemetry value for the standalone report.
 *
 * @param mixed $value Numeric value.
 * @param int $decimals Decimal places.
 * @return string Formatted number.
 */
function view_telemetry_report_number(mixed $value, int $decimals = 0): string
{
    if ($value === null || $value === '') {
        return '0';
    }
    return number_format((float) $value, $decimals);
}

/**
 * Format one duration for the standalone telemetry report.
 *
 * @param mixed $seconds Duration in seconds.
 * @return string Human-readable duration.
 */
function view_telemetry_report_duration(mixed $seconds): string
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
 * Render one generic standalone telemetry table.
 *
 * @param array<int,array<string,mixed>> $rows Table rows.
 * @param array<int,array<string,mixed>> $columns Column descriptors.
 * @param string $emptyText Optional empty-state text.
 * @return string Rendered table markup.
 */
function view_telemetry_export_table(
    array $rows,
    array $columns,
    string $emptyText = '',
): string {
    if ($rows === []) {
        return (
            '<p class="muted">'
                . e(
                    $emptyText !== ''
                        ? $emptyText
                        : t(
                            'admin.telemetry.no_data_yet',
                            'No telemetry data yet.',
                        ),
                )
                . '</p>'
        );
    }
    $html = '<div class="table-scroll"><table><thead><tr>';
    foreach ($columns as $column) {
        $html .= '<th>' . e((string) ($column['label'] ?? '')) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($columns as $column) {
            $key = (string) ($column['key'] ?? '');
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
 * Render a photo engagement table in the standalone report.
 *
 * @param array<int,array<string,mixed>> $rows Photo telemetry rows.
 * @param string $valueKey Metric field.
 * @param string $valueLabel Metric heading.
 * @return string Rendered table markup.
 */
function view_telemetry_export_photo_table(
    array $rows,
    string $valueKey,
    string $valueLabel,
): string {
    return view_telemetry_export_table($rows, [
        ['key' => 'filename', 'label' => t('admin.telemetry.photo', 'Photo')],
        [
            'key' => 'gallery_title',
            'label' => t('admin.telemetry.gallery', 'Gallery'),
        ],
        [
            'key' => $valueKey,
            'label' => $valueLabel,
                    'format' => static function (
                        int|float|string|null $value,
                    ) use ($valueKey): string {
                if ($valueKey === 'avg_view_seconds') {
                    return view_telemetry_report_number($value, 2) . ' s';
                }
                return view_telemetry_report_number($value, 0);
            },
        ],
    ]);
}

/**
 * Render one compact standalone telemetry bar chart.
 *
 * @param array<int,array<string,mixed>> $rows Chart rows.
 * @param string $labelKey Label field.
 * @param string $valueKey Value field.
 * @param string $valueSuffix Optional rendered suffix.
 * @return string Rendered chart markup.
 */
function view_telemetry_export_bar_chart(
    array $rows,
    string $labelKey,
    string $valueKey,
    string $valueSuffix = '',
): string {
    if ($rows === []) {
        return (
            '<p class="muted">'
                . e(t('admin.telemetry.no_data_yet', 'No telemetry data yet.'))
                . '</p>'
        );
    }
    $max = 0.0;
    foreach ($rows as $row) {
        $max = max($max, (float) ($row[$valueKey] ?? 0));
    }
    if ($max <= 0) {
        return (
            '<p class="muted">'
                . e(t('admin.telemetry.no_data_yet', 'No telemetry data yet.'))
                . '</p>'
        );
    }
    $html = '<div class="bars">';
    foreach ($rows as $row) {
        $label = (string) ($row[$labelKey] ?? 'unknown');
        $value = (float) ($row[$valueKey] ?? 0);
        $width = max(2, min(100, (int) round(($value / $max) * 100)));
        $html .=
            '<div class="bar-row"><span class="bar-label">'
            . e($label)
            . '</span><span class="bar-track"><span class="bar-fill" style="width:'
            . $width
            . '%"></span></span><strong>'
            . e(view_telemetry_report_number($value, 0) . $valueSuffix)
            . '</strong></div>';
    }
    return $html . '</div>';
}

/**
 * Render one daily trend chart in the standalone telemetry report.
 *
 * @param array<int,array<string,mixed>> $rows Daily rows.
 * @param string $valueKey Value field.
 * @param string $label Accessible chart label.
 * @return string Rendered chart markup.
 */
function view_telemetry_export_trend_chart(
    array $rows,
    string $valueKey,
    string $label,
): string {
    if ($rows === []) {
        return (
            '<p class="muted">'
                . e(t('admin.telemetry.no_data_yet', 'No telemetry data yet.'))
                . '</p>'
        );
    }
    $max = 0.0;
    foreach ($rows as $row) {
        $max = max($max, (float) ($row[$valueKey] ?? 0));
    }
    if ($max <= 0) {
        return (
            '<p class="muted">'
                . e(t('admin.telemetry.no_data_yet', 'No telemetry data yet.'))
                . '</p>'
        );
    }
    $html = '<div class="trend" aria-label="' . e($label) . '">';
    foreach ($rows as $row) {
        $value = (float) ($row[$valueKey] ?? 0);
        $height = max(3, min(100, (int) round(($value / $max) * 100)));
        $title =
            (string) ($row['report_date'] ?? '')
            . ': '
            . view_telemetry_report_number($value, 0);
        $html .=
            '<span class="trend-column" title="'
            . e($title)
            . '"><i style="height:'
            . $height
            . '%"></i></span>';
    }
    return $html . '</div><p class="chart-caption">' . e($label) . '</p>';
}

/**
 * Return a human-readable label for one browser performance aggregate.
 *
 * @param string $metricName Aggregate metric identifier.
 * @return string Localized presentation label.
 */
function view_telemetry_performance_metric_label(string $metricName): string
{
    return match ($metricName) {
        'client.page_load_ms' => t(
            'admin.telemetry.performance.page_load',
            'Page load',
        ),
        'client.image_decode_ms' => t(
            'admin.telemetry.performance.image_decode',
            'Visible image decode',
        ),
        'client.image_display_ms' => t(
            'admin.telemetry.performance.image_display',
            'Visible image display',
        ),
        'web_vital.lcp' => t(
            'admin.telemetry.performance.lcp',
            'Largest Contentful Paint',
        ),
        'web_vital.cls' => t(
            'admin.telemetry.performance.cls',
            'Cumulative Layout Shift',
        ),
        'web_vital.inp' => t(
            'admin.telemetry.performance.inp',
            'Interaction to Next Paint',
        ),
        'web_vital.fcp' => t(
            'admin.telemetry.performance.fcp',
            'First Contentful Paint',
        ),
        'web_vital.ttfb' => t(
            'admin.telemetry.performance.ttfb',
            'Time to First Byte',
        ),
        default => $metricName,
    };
}

/**
 * Return the display unit for one browser performance aggregate.
 *
 * @param string $metricName Aggregate metric identifier.
 * @return string Unit label.
 */
function view_telemetry_performance_metric_unit(string $metricName): string
{
    return $metricName === 'web_vital.cls'
        ? t('admin.telemetry.performance.score_unit', 'score')
        : 'ms';
}

/**
 * Render the standalone anonymous telemetry HTML export from controller-prepared data.
 *
 * @param array<string,mixed> $viewModel Complete export presentation state.
 * @return string Standalone HTML document.
 */
function view_render_admin_telemetry_export_document(array $viewModel): string
{
    extract($viewModel, EXTR_SKIP);
    $cachePhases = is_array($cachePhases ?? null)
        ? $cachePhases
        : ['available' => false, 'rows' => []];
    $photoOpenOriginsAvailable = $photoOpenOriginsAvailable ?? true;
    $style = ':root{color-scheme:light dark;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f4f6fb;color:#172033}body{margin:0;padding:32px}main{max-width:1320px;margin:0 auto}header,.panel{background:rgba(255,255,255,.94);border:1px solid rgba(90,108,140,.22);border-radius:24px;box-shadow:0 18px 55px rgba(28,43,70,.10);padding:24px;margin-bottom:22px}h1{margin:0 0 8px;font-size:34px}h2{margin:0 0 16px;font-size:22px}h3{margin:18px 0 10px;font-size:16px}.muted,p{color:#5b667a;line-height:1.55}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px}.metric{border:1px solid rgba(90,108,140,.20);border-radius:18px;padding:16px;background:linear-gradient(180deg,rgba(255,255,255,.98),rgba(246,248,252,.94))}.metric strong{display:block;font-size:28px;margin-bottom:4px}.metric span{display:block;color:#5b667a}.metric small{display:block;margin-top:8px;color:#6d778a}.split{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:18px}.table-scroll{overflow:auto;border-radius:16px;border:1px solid rgba(90,108,140,.18)}table{width:100%;border-collapse:collapse;min-width:680px}th,td{text-align:left;padding:11px 12px;border-bottom:1px solid rgba(90,108,140,.18);vertical-align:top;white-space:nowrap}th{background:rgba(77,105,165,.10);font-size:13px;text-transform:uppercase;letter-spacing:.03em}tr:last-child td{border-bottom:0}.privacy{background:#eef7f0;border-color:#b7dfc1}.bars{display:grid;gap:10px}.bar-row{display:grid;grid-template-columns:minmax(90px,160px) 1fr auto;align-items:center;gap:10px}.bar-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.bar-track{height:12px;border-radius:999px;background:rgba(77,105,165,.14);overflow:hidden}.bar-fill{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#5d7df2,#53b987)}.trend-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px}.trend{height:160px;display:flex;align-items:end;gap:4px;border:1px solid rgba(90,108,140,.18);border-radius:18px;padding:12px;background:linear-gradient(180deg,rgba(255,255,255,.7),rgba(77,105,165,.07))}.trend-column{flex:1;min-width:4px;height:100%;display:flex;align-items:end}.trend-column i{display:block;width:100%;border-radius:8px 8px 3px 3px;background:linear-gradient(180deg,#5d7df2,#8aa2ff)}.chart-caption{margin:8px 0 0;font-size:13px}.pill{display:inline-flex;align-items:center;border-radius:999px;padding:3px 8px;background:rgba(77,105,165,.12);font-size:12px;color:#384969}.section-note{margin-top:-6px}.summary-list{display:grid;gap:8px;margin:0;padding:0;list-style:none}.summary-list li{display:flex;justify-content:space-between;gap:14px;border-bottom:1px solid rgba(90,108,140,.14);padding:8px 0}.summary-list li:last-child{border-bottom:0}@media (prefers-color-scheme:dark){:root{background:#101521;color:#eef2fb}header,.panel{background:#171e2d;border-color:#303a50}.metric{background:#1c2435;border-color:#303a50}.muted,p,.metric span,.metric small{color:#aeb8cc}th{background:#222d43}.table-scroll,td,th{border-color:#303a50}.privacy{background:#18291e;border-color:#31563c}.bar-track{background:#263148}.pill{background:#263148;color:#d6dded}.trend{background:#182032;border-color:#303a50}.summary-list li{border-color:#303a50}}';

    $labels = [
        'executive_overview' => t(
            'admin.telemetry.export.executive_overview',
            'Executive overview',
        ),
        'anonymous_sessions' => t(
            'admin.telemetry.export.anonymous_sessions',
            'Anonymous sessions',
        ),
        'session_hashes_only' => t(
            'admin.telemetry.export.session_hashes_only',
            'Session hashes only, no raw visitor identifiers',
        ),
        'page_views' => t('admin.telemetry.export.page_views', 'Page views'),
        'photo_opens' => t('admin.telemetry.export.photo_opens', 'Photo opens'),
        'capped_photo_time' => t(
            'admin.telemetry.export.capped_photo_time',
            'Capped photo time',
        ),
        'bounce_rate' => t('admin.telemetry.export.bounce_rate', 'Bounce rate'),
        'client_errors' => t(
            'admin.telemetry.export.client_errors',
            'Client errors',
        ),
        'javascript_error_events' => t(
            'admin.telemetry.export.javascript_error_events',
            'JavaScript error events',
        ),
        'media_measured' => t(
            'admin.telemetry.export.media_measured',
            'Media measured',
        ),
        'images_thumbnails_downloads' => t(
            'admin.telemetry.export.images_thumbnails_downloads',
            'Images, thumbnails, and downloads',
        ),
        'cache_efficiency' => t(
            'admin.telemetry.export.cache_efficiency',
            'Mixed cache hit share',
        ),
        'db_queries' => t('admin.telemetry.export.db_queries', 'DB queries'),
        'daily_trends' => t(
            'admin.telemetry.export.daily_trends',
            'Daily trends',
        ),
        'consistency' => t(
            'admin.telemetry.export.consistency',
            'Telemetry consistency',
        ),
        'consistency_sessions' => t(
            'admin.telemetry.export.consistency_sessions',
            'Session summary vs daily sessions',
        ),
        'consistency_page_views' => t(
            'admin.telemetry.export.consistency_page_views',
            'Hourly page views vs daily page views',
        ),
        'consistency_session_rows' => t(
            'admin.telemetry.export.consistency_session_rows',
            'Session-row page views vs canonical page views',
        ),
        'sessions_per_day' => t(
            'admin.telemetry.export.sessions_per_day',
            'Sessions per day',
        ),
        'page_views_per_day' => t(
            'admin.telemetry.export.page_views_per_day',
            'Page views per day',
        ),
        'photo_opens_per_day' => t(
            'admin.telemetry.export.photo_opens_per_day',
            'Photo opens per day',
        ),
        'media_bytes_per_day' => t(
            'admin.telemetry.export.media_bytes_per_day',
            'Measured media bytes per day',
        ),
        'client_errors_per_day' => t(
            'admin.telemetry.export.client_errors_per_day',
            'Client errors per day',
        ),
        'traffic_sources' => t(
            'admin.telemetry.export.traffic_sources',
            'Traffic sources',
        ),
        'device_type' => t('admin.telemetry.export.device_type', 'Device type'),
        'browser_family' => t(
            'admin.telemetry.export.browser_family',
            'Browser family',
        ),
        'operating_system' => t(
            'admin.telemetry.export.operating_system',
            'Operating system',
        ),
        'viewport_class' => t(
            'admin.telemetry.export.viewport_class',
            'Viewport class',
        ),
        'page_kind' => t('admin.telemetry.export.page_kind', 'Page kind'),
        'top_galleries' => t(
            'admin.telemetry.export.top_galleries',
            'Top galleries',
        ),
        'gallery' => t('admin.telemetry.export.gallery', 'Gallery'),
        'slug' => t('admin.telemetry.export.slug', 'Slug'),
        'photo_time' => t('admin.telemetry.export.photo_time', 'Photo time'),
        'media_bytes' => t('admin.telemetry.export.media_bytes', 'Media bytes'),
        'top_routes' => t('admin.telemetry.export.top_routes', 'Top routes'),
        'route' => t('admin.telemetry.export.route', 'Route'),
        'landing_routes' => t(
            'admin.telemetry.export.landing_routes',
            'Landing routes',
        ),
        'exit_routes' => t('admin.telemetry.export.exit_routes', 'Exit routes'),
        'sessions' => t('admin.telemetry.export.sessions', 'Sessions'),
        'avg_duration' => t(
            'admin.telemetry.export.avg_duration',
            'Avg duration',
        ),
        'photo_engagement' => t(
            'admin.telemetry.export.photo_engagement',
            'Photo engagement',
        ),
        'top_viewed_photos' => t(
            'admin.telemetry.export.top_viewed_photos',
            'Top viewed photos',
        ),
        'longest_viewed_photos' => t(
            'admin.telemetry.export.longest_viewed_photos',
            'Longest viewed photos',
        ),
        'photo_open_diagnostics' => t(
            'admin.telemetry.export.photo_open_diagnostics',
            'Photo-open diagnostics',
        ),
        'photo_open_diagnostics_note' => t(
            'admin.telemetry.export.photo_open_diagnostics_note',
            'Session distribution uses the selected report window. Gallery and activation-origin diagnostics use only the retained raw-event window ({days} days). Historical events without the new trigger context are labelled legacy/unclassified.',
        ),
        'photo_open_distribution' => t(
            'admin.telemetry.export.photo_open_distribution',
            'Photo opens per session',
        ),
        'photo_open_bucket' => t(
            'admin.telemetry.export.photo_open_bucket',
            'Opens/session bucket',
        ),
        'photo_open_sessions_above' => t(
            'admin.telemetry.export.photo_open_sessions_above',
            'Sessions above {count} opens',
        ),
        'photo_open_max_session' => t(
            'admin.telemetry.export.photo_open_max_session',
            'Maximum opens in one session',
        ),
        'average_opens' => t(
            'admin.telemetry.export.average_opens',
            'Average opens',
        ),
        'maximum_opens' => t(
            'admin.telemetry.export.maximum_opens',
            'Maximum opens',
        ),
        'galleries_by_opens_per_session' => t(
            'admin.telemetry.export.galleries_by_opens_per_session',
            'Galleries by opens per session',
        ),
        'activation_origins' => t(
            'admin.telemetry.export.activation_origins',
            'Photo-open activation origins',
        ),
        'thumbnail_bytes_by_variant' => t(
            'admin.telemetry.export.thumbnail_bytes_by_variant',
            'Thumbnail bytes by variant',
        ),
        'image_bytes_by_variant' => t(
            'admin.telemetry.export.image_bytes_by_variant',
            'Image bytes by variant',
        ),
        'variant' => t('admin.telemetry.export.variant', 'Variant'),
        'events' => t('admin.telemetry.export.events', 'Events'),
        'bytes' => t('admin.telemetry.export.bytes', 'Bytes'),
        'media_byte_split' => t(
            'admin.telemetry.export.media_byte_split',
            'Media byte split',
        ),
        'full_images' => t('admin.telemetry.export.full_images', 'Full images'),
        'thumbnails' => t('admin.telemetry.export.thumbnails', 'Thumbnails'),
        'downloads' => t('admin.telemetry.export.downloads', 'Downloads'),
        'all_measured_media' => t(
            'admin.telemetry.export.all_measured_media',
            'All measured media',
        ),
        'thumbnail_cache_hit_events' => t(
            'admin.telemetry.export.thumbnail_cache_hit_events',
            'Thumbnail cache hit events',
        ),
        'thumbnail_cache_miss_events' => t(
            'admin.telemetry.export.thumbnail_cache_miss_events',
            'Thumbnail cache miss events',
        ),
        'cache_result' => t(
            'admin.telemetry.export.cache_result',
            'Cache result',
        ),
        'browser_performance' => t(
            'admin.telemetry.export.browser_performance',
            'Browser performance',
        ),
        'metric' => t('admin.telemetry.export.metric', 'Metric'),
        'unit' => t('admin.telemetry.export.unit', 'Unit'),
        'average_value' => t('admin.telemetry.export.average_value', 'Average'),
        'samples' => t('admin.telemetry.export.samples', 'Samples'),
        'average_ms_value' => t(
            'admin.telemetry.export.average_ms_value',
            'Average ms/value',
        ),
        'minimum' => t('admin.telemetry.export.minimum', 'Minimum'),
        'maximum' => t('admin.telemetry.export.maximum', 'Maximum'),
        'error_kind' => t('admin.telemetry.export.error_kind', 'Error kind'),
        'last_seen' => t('admin.telemetry.export.last_seen', 'Last seen'),
        'database_telemetry' => t(
            'admin.telemetry.export.database_telemetry',
            'Database telemetry',
        ),
        'database_scope_note' => t(
            'admin.telemetry.export.database_scope_note',
            'Database telemetry is observed centrally at the PDO execution boundary. Query text and bound values are not stored; only bounded operation, table, route, latency, row-count, and fingerprint aggregates are retained.',
        ),
        'media_scope_note' => t(
            'admin.telemetry.export.media_scope_note',
            'Media byte telemetry covers PHP-observed image, thumbnail, and source-download response paths. Browser/CDN bytes served outside those PHP paths are not measured here.',
        ),
        'cache_scope_note' => t(
            'admin.telemetry.export.cache_scope_note',
            'Cache efficiency describes only the decoded-lightbox in-memory application cache. It is not HTTP, browser, proxy, or CDN cache telemetry.',
        ),
        'queries' => t('admin.telemetry.export.queries', 'Queries'),
        'slow_queries' => t(
            'admin.telemetry.export.slow_queries',
            'Slow queries',
        ),
        'failed_queries' => t(
            'admin.telemetry.export.failed_queries',
            'Failed queries',
        ),
        'routes_operations_tables' => t(
            'admin.telemetry.export.routes_operations_tables',
            'Routes, operations, and tables',
        ),
        'operation' => t('admin.telemetry.export.operation', 'Operation'),
        'table' => t('admin.telemetry.export.table', 'Table'),
        'failed' => t('admin.telemetry.export.failed', 'Failed'),
        'slow' => t('admin.telemetry.export.slow', 'Slow'),
        'total_latency' => t(
            'admin.telemetry.export.total_latency',
            'Total latency',
        ),
        'max_latency' => t('admin.telemetry.export.max_latency', 'Max latency'),
        'rows_returned' => t(
            'admin.telemetry.export.rows_returned',
            'Rows returned',
        ),
        'rows_affected' => t(
            'admin.telemetry.export.rows_affected',
            'Rows affected',
        ),
        'query_fingerprints' => t(
            'admin.telemetry.export.query_fingerprints',
            'Query fingerprints',
        ),
        'fingerprint' => t('admin.telemetry.export.fingerprint', 'Fingerprint'),
        'avg_latency' => t('admin.telemetry.export.avg_latency', 'Avg latency'),
        'telemetry_access_log' => t(
            'admin.telemetry.export.telemetry_access_log',
            'Telemetry access log',
        ),
        'time' => t('admin.telemetry.export.time', 'Time'),
        'event' => t('admin.telemetry.export.event', 'Event'),
        'source' => t('admin.telemetry.export.source', 'Source'),
        'kind' => t('admin.telemetry.export.kind', 'Kind'),
        'image' => t('admin.telemetry.export.image', 'Image'),
        'referrer' => t('admin.telemetry.export.referrer', 'Referrer'),
        'browser' => t('admin.telemetry.export.browser', 'Browser'),
        'os' => t('admin.telemetry.export.os', 'OS'),
        'device' => t('admin.telemetry.export.device', 'Device'),
        'viewport' => t('admin.telemetry.export.viewport', 'Viewport'),
        'cache' => t('admin.telemetry.export.cache', 'Cache'),
        'status' => t('admin.telemetry.export.status', 'Status'),
        'error' => t('admin.telemetry.export.error', 'Error'),
        'value_ms' => t('admin.telemetry.export.value_ms', 'Value ms'),
        'duration' => t('admin.telemetry.export.duration', 'Duration'),
        'telemetry_job_runs' => t(
            'admin.telemetry.export.telemetry_job_runs',
            'Telemetry job runs',
        ),
        'job' => t('admin.telemetry.export.job', 'Job'),
        'started' => t('admin.telemetry.export.started', 'Started'),
        'finished' => t('admin.telemetry.export.finished', 'Finished'),
        'items' => t('admin.telemetry.export.items', 'Items'),
        'retries' => t('admin.telemetry.export.retries', 'Retries'),
        'stored_telemetry_volume' => t(
            'admin.telemetry.export.stored_telemetry_volume',
            'Stored telemetry volume',
        ),
        'storage_diagnostics' => t(
            'admin.telemetry.export.storage_diagnostics',
            'Telemetry storage diagnostics',
        ),
        'report_query_profile' => t(
            'admin.telemetry.export.report_query_profile',
            'Telemetry report query runtime',
        ),
        'report_query_plans' => t(
            'admin.telemetry.export.report_query_plans',
            'Telemetry report query plans',
        ),
        'report_query_plans_note' => t(
            'admin.telemetry.export.report_query_plans_note',
            'Sanitized EXPLAIN output for a fixed set of telemetry report queries. SQL text, bound values, result data, and database error messages are not included. Unavailable means the hosting database did not return a usable plan.',
        ),
        'plan_status' => t('admin.telemetry.export.plan_status', 'Plan status'),
        'select_id' => t('admin.telemetry.export.select_id', 'Select ID'),
        'select_type' => t('admin.telemetry.export.select_type', 'Select type'),
        'access_type' => t('admin.telemetry.export.access_type', 'Access type'),
        'possible_keys' => t(
            'admin.telemetry.export.possible_keys',
            'Possible keys',
        ),
        'key_used' => t('admin.telemetry.export.key_used', 'Key used'),
        'rows_estimate' => t(
            'admin.telemetry.export.rows_estimate',
            'Rows estimate',
        ),
        'filtered_percent' => t(
            'admin.telemetry.export.filtered_percent',
            'Filtered %',
        ),
        'extra' => t('admin.telemetry.export.extra', 'Extra'),
        'report_query_profile_note' => t(
            'admin.telemetry.export.report_query_profile_note',
            'Request-local SQL execution timing for bounded telemetry report query families. These measurements are not persisted and do not include SQL text, bound values, result data, view rendering, or network latency.',
        ),
        'query_family' => t(
            'admin.telemetry.export.query_family',
            'Query family',
        ),
        'calls' => t('admin.telemetry.export.calls', 'Calls'),
        'failed_calls' => t(
            'admin.telemetry.export.failed_calls',
            'Failed calls',
        ),
        'total_ms' => t('admin.telemetry.export.total_ms', 'Total ms'),
        'avg_ms' => t('admin.telemetry.export.avg_ms', 'Avg ms'),
        'max_ms' => t('admin.telemetry.export.max_ms', 'Max ms'),
        'storage_diagnostics_note' => t(
            'admin.telemetry.export.storage_diagnostics_note',
            'Operator-only evidence for Stage 6 storage tuning. Row growth is an approximate seven-day average; no index or retention change is implied by these numbers.',
        ),
        'storage_source_hourly' => t(
            'admin.telemetry.export.storage_source_hourly',
            'The selected report window is fully inside hourly retention, so current report queries do not require the daily rollup for completeness.',
        ),
        'storage_source_daily_required' => t(
            'admin.telemetry.export.storage_source_daily_required',
            'The selected report window exceeds hourly retention. A complete long-window implementation must use validated daily rollups for the older portion before hourly retention is shortened.',
        ),
        'daily_rollup_consistency' => t(
            'admin.telemetry.export.daily_rollup_consistency',
            'Daily rollup consistency',
        ),
        'daily_rollup_consistency_note' => t(
            'admin.telemetry.export.daily_rollup_consistency_note',
            'Read-only comparison of hourly aggregates with persisted daily rollups for recent completed calendar days. The current partial day is excluded. A clean match is required before long-window reports can safely rely on daily rollups.',
        ),
        'rollup_state' => t(
            'admin.telemetry.export.rollup_state',
            'Rollup state',
        ),
        'hourly_samples' => t(
            'admin.telemetry.export.hourly_samples',
            'Hourly samples',
        ),
        'daily_samples' => t(
            'admin.telemetry.export.daily_samples',
            'Daily samples',
        ),
        'sample_difference' => t(
            'admin.telemetry.export.sample_difference',
            'Sample difference',
        ),
        'hourly_events' => t(
            'admin.telemetry.export.hourly_events',
            'Hourly events',
        ),
        'daily_events' => t(
            'admin.telemetry.export.daily_events',
            'Daily events',
        ),
        'event_difference' => t(
            'admin.telemetry.export.event_difference',
            'Event difference',
        ),
        'hourly_value_sum' => t(
            'admin.telemetry.export.hourly_value_sum',
            'Hourly value sum',
        ),
        'daily_value_sum' => t(
            'admin.telemetry.export.daily_value_sum',
            'Daily value sum',
        ),
        'value_difference' => t(
            'admin.telemetry.export.value_difference',
            'Value difference',
        ),
        'exact_rows' => t('admin.telemetry.export.exact_rows', 'Exact rows'),
        'approx_rows_per_day' => t(
            'admin.telemetry.export.approx_rows_per_day',
            'Approx rows/day',
        ),
        'data_size' => t('admin.telemetry.export.data_size', 'Data size'),
        'index_size' => t('admin.telemetry.export.index_size', 'Index size'),
        'total_size' => t('admin.telemetry.export.total_size', 'Total size'),
        'oldest_row' => t('admin.telemetry.export.oldest_row', 'Oldest row'),
        'newest_row' => t('admin.telemetry.export.newest_row', 'Newest row'),
        'retention_days' => t(
            'admin.telemetry.export.retention_days',
            'Retention days',
        ),
        'hourly_metric_cardinality' => t(
            'admin.telemetry.export.hourly_metric_cardinality',
            'Hourly metric cardinality',
        ),
        'row_count' => t('admin.telemetry.export.row_count', 'Rows'),
        'route_cardinality' => t(
            'admin.telemetry.export.route_cardinality',
            'Routes',
        ),
        'gallery_cardinality' => t(
            'admin.telemetry.export.gallery_cardinality',
            'Galleries',
        ),
        'image_cardinality' => t(
            'admin.telemetry.export.image_cardinality',
            'Images',
        ),
        'device_cardinality' => t(
            'admin.telemetry.export.device_cardinality',
            'Devices',
        ),
        'variant_cardinality' => t(
            'admin.telemetry.export.variant_cardinality',
            'Variants',
        ),
        'cache_cardinality' => t(
            'admin.telemetry.export.cache_cardinality',
            'Cache states',
        ),
        'raw_events' => t('admin.telemetry.export.raw_events', 'Raw events'),
        'hourly_metrics' => t(
            'admin.telemetry.export.hourly_metrics',
            'Hourly metrics',
        ),
        'daily_metrics' => t(
            'admin.telemetry.export.daily_metrics',
            'Daily metrics',
        ),
        'db_query_metrics' => t(
            'admin.telemetry.export.db_query_metrics',
            'DB query metrics',
        ),
        'job_runs' => t('admin.telemetry.export.job_runs', 'Job runs'),
    ];

    $consistency = is_array($consistency ?? null) ? $consistency : [];
    $semanticsVersion = (string) ($consistency['semantics_version'] ?? '1');
    $semanticsEffectiveAt = trim(
        (string) ($consistency['semantics_effective_at'] ?? ''),
    );
    $semanticsBoundaryText = $semanticsEffectiveAt !== ''
        ? t(
            'admin.telemetry.export.semantics_boundary_recorded',
            'Telemetry semantics version {version} is recorded as effective from {time}. Session-row page counters before this boundary used legacy semantics and may remain inflated until those sessions age out.',
            [
                'version' => $semanticsVersion,
                'time' => $semanticsEffectiveAt,
            ],
        )
        : t(
            'admin.telemetry.export.semantics_boundary_unrecorded',
            'Telemetry semantics version {version} is active in this codebase, but its first-event boundary has not been recorded yet. Existing session-row page counters may still contain legacy semantics.',
            [
                'version' => $semanticsVersion,
            ],
        );
    $consistencyRows = [
        [
            'label' => $labels['consistency_sessions'],
            'left' => (int) ($consistency['session_summary'] ?? 0),
            'right' => (int) ($consistency['daily_sessions'] ?? 0),
            'difference' => (int) ($consistency['session_difference'] ?? 0),
        ],
        [
            'label' => $labels['consistency_page_views'],
            'left' => (int) ($consistency['hourly_page_views'] ?? 0),
            'right' => (int) ($consistency['daily_page_views'] ?? 0),
            'difference' => (int) ($consistency['page_view_difference'] ?? 0),
        ],
        [
            'label' => $labels['consistency_session_rows'],
            'left' => (int) ($consistency['session_row_page_views'] ?? 0),
            'right' => (int) ($consistency['hourly_page_views'] ?? 0),
            'difference' => (int) (
                $consistency['session_row_page_view_difference'] ?? 0
            ),
        ],
    ];
    $consistencyHtml =
        '<section class="panel"><h2>'
        . e($labels['consistency'])
        . '</h2><div class="table-scroll"><table><thead><tr>'
        . '<th>'
        . e(t('admin.telemetry.export.check', 'Check'))
        . '</th>'
        . '<th>'
        . e(t('admin.telemetry.export.left_value', 'Primary value'))
        . '</th>'
        . '<th>'
        . e(t('admin.telemetry.export.right_value', 'Comparison value'))
        . '</th>'
        . '<th>'
        . e(t('admin.telemetry.export.difference', 'Difference'))
        . '</th>'
        . '</tr></thead><tbody>';
    foreach ($consistencyRows as $row) {
        $consistencyHtml .=
            '<tr><td>'
            . e((string) $row['label'])
            . '</td>'
            . '<td>'
            . e(view_telemetry_report_number($row['left']))
            . '</td>'
            . '<td>'
            . e(view_telemetry_report_number($row['right']))
            . '</td>'
            . '<td>'
            . e(view_telemetry_report_number($row['difference']))
            . '</td></tr>';
    }
    $consistencyHtml .=
        '</tbody></table></div><p class="muted">'
        . e($semanticsBoundaryText)
        . '</p></section>';

    $storageDiagnostics = is_array($storageDiagnostics ?? null)
        ? $storageDiagnostics
        : [];
    $storageRows = is_array($storageDiagnostics['tables'] ?? null)
        ? $storageDiagnostics['tables']
        : [];
    $metricCardinalityRows = is_array(
        $storageDiagnostics['hourly_metric_cardinality'] ?? null,
    )
        ? $storageDiagnostics['hourly_metric_cardinality']
        : [];
    $storageSourceText = match (
        (string) (
            $storageDiagnostics['aggregate_source_state'] ?? 'unavailable'
        )
    ) {
        'hourly_within_retention' => $labels['storage_source_hourly'],
        'daily_required_for_full_window'
            => $labels['storage_source_daily_required'],
        default => t(
            'admin.telemetry.export.storage_diagnostics_unavailable',
            'Storage diagnostics are unavailable for this export.',
        ),
    };
    $storageDiagnosticsHtml =
        '<section class="panel"><h2>'
        . e($labels['storage_diagnostics'])
        . '</h2><p class="muted">'
        . e($labels['storage_diagnostics_note'])
        . ' '
        . e($storageSourceText)
        . '</p><p class="muted">'
        . e(t('admin.telemetry.export.retention_note'))
        . '</p>'
        . view_telemetry_export_table(
            $storageRows,
            [
                ['key' => 'table_name', 'label' => $labels['table']],
                [
                    'key' => 'exact_rows',
                    'label' => $labels['exact_rows'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'approx_rows_per_day',
                    'label' => $labels['approx_rows_per_day'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v, 1),
                ],
                [
                    'key' => 'data_bytes',
                    'label' => $labels['data_size'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => format_bytes((float) $v, 1),
                ],
                [
                    'key' => 'index_bytes',
                    'label' => $labels['index_size'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => format_bytes((float) $v, 1),
                ],
                [
                    'key' => 'total_bytes',
                    'label' => $labels['total_size'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => format_bytes((float) $v, 1),
                ],
                ['key' => 'oldest_row', 'label' => $labels['oldest_row']],
                ['key' => 'newest_row', 'label' => $labels['newest_row']],
                [
                    'key' => 'retention_days',
                    'label' => $labels['retention_days'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'overdue_rows',
                    'label' => t(
                        'admin.telemetry.export.overdue_rows',
                        'Overdue rows',
                    ),
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
            ],
            t(
                'admin.telemetry.export.storage_diagnostics_unavailable',
                'Storage diagnostics are unavailable for this export.',
            ),
        )
        . '<h3>'
        . e($labels['hourly_metric_cardinality'])
        . '</h3>'
        . view_telemetry_export_table(
            $metricCardinalityRows,
            [
                ['key' => 'metric_name', 'label' => $labels['metric']],
                [
                    'key' => 'row_count',
                    'label' => $labels['row_count'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'route_cardinality',
                    'label' => $labels['route_cardinality'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'gallery_cardinality',
                    'label' => $labels['gallery_cardinality'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'image_cardinality',
                    'label' => $labels['image_cardinality'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'device_cardinality',
                    'label' => $labels['device_cardinality'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'media_variant_cardinality',
                    'label' => $labels['variant_cardinality'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'cache_cardinality',
                    'label' => $labels['cache_cardinality'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
            ],
            t('admin.telemetry.export.no_samples', 'No samples'),
        )
        . '</section>';

    $rollupConsistency = is_array($rollupConsistency ?? null)
        ? $rollupConsistency
        : [];
    $rollupRows = is_array($rollupConsistency['metrics'] ?? null)
        ? $rollupConsistency['metrics']
        : [];
    foreach ($rollupRows as &$rollupRow) {
        $rollupRow['status_label'] = match (
            (string) ($rollupRow['status'] ?? 'unavailable')
        ) {
            'match' => t('admin.telemetry.export.rollup_match', 'Match'),
            'mismatch' => t(
                'admin.telemetry.export.rollup_mismatch',
                'Mismatch',
            ),
            'daily_missing' => t(
                'admin.telemetry.export.rollup_daily_missing',
                'Daily rollup missing',
            ),
            'no_samples' => t(
                'admin.telemetry.export.rollup_no_samples',
                'No samples',
            ),
            default => t(
                'admin.telemetry.export.rollup_unavailable',
                'Unavailable',
            ),
        };
    }
    unset($rollupRow);
    $rollupState = (string) ($rollupConsistency['state'] ?? 'unavailable');
    $rollupStateText = match ($rollupState) {
        'match' => t(
            'admin.telemetry.export.rollup_summary_match',
            'All compared metrics match between hourly and daily aggregates for the completed-day validation window.',
        ),
        'mismatch' => t(
            'admin.telemetry.export.rollup_summary_mismatch',
            '{count} metric families differ or are missing from the daily rollup. Do not switch long-window reports to daily aggregates until the cause is resolved.',
            [
                'count' => view_telemetry_report_number(
                    $rollupConsistency['mismatch_count'] ?? 0,
                ),
            ],
        ),
        'no_samples' => t(
            'admin.telemetry.export.rollup_summary_no_samples',
            'No comparable completed-day aggregate samples are available yet.',
        ),
        default => t(
            'admin.telemetry.export.rollup_summary_unavailable',
            'Daily rollup consistency could not be measured on this host.',
        ),
    };
    $rollupConsistencyHtml =
        '<section class="panel"><h2>'
        . e($labels['daily_rollup_consistency'])
        . '</h2><p class="muted">'
        . e($labels['daily_rollup_consistency_note'])
        . ' '
        . e($rollupStateText)
        . '</p>'
        . view_telemetry_export_table(
            $rollupRows,
            [
                ['key' => 'metric_name', 'label' => $labels['metric']],
                ['key' => 'status_label', 'label' => $labels['rollup_state']],
                [
                    'key' => 'hourly_samples',
                    'label' => $labels['hourly_samples'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'daily_samples',
                    'label' => $labels['daily_samples'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'sample_difference',
                    'label' => $labels['sample_difference'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'hourly_events',
                    'label' => $labels['hourly_events'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'daily_events',
                    'label' => $labels['daily_events'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'event_difference',
                    'label' => $labels['event_difference'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'hourly_value_sum',
                    'label' => $labels['hourly_value_sum'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v, 4),
                ],
                [
                    'key' => 'daily_value_sum',
                    'label' => $labels['daily_value_sum'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v, 4),
                ],
                [
                    'key' => 'value_difference',
                    'label' => $labels['value_difference'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v, 4),
                ],
            ],
            t('admin.telemetry.export.rollup_no_samples', 'No samples'),
        )
        . '</section>';

    $reportQueryProfile = is_array($reportQueryProfile ?? null)
        ? $reportQueryProfile
        : [];
    $reportQueryProfileHtml =
        '<section class="panel"><h2>'
        . e($labels['report_query_profile'])
        . '</h2><p class="muted">'
        . e($labels['report_query_profile_note'])
        . '</p>'
        . view_telemetry_export_table(
            $reportQueryProfile,
            [
                ['key' => 'operation', 'label' => $labels['query_family']],
                [
                    'key' => 'calls',
                    'label' => $labels['calls'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'failed_calls',
                    'label' => $labels['failed_calls'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'total_ms',
                    'label' => $labels['total_ms'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v, 2),
                ],
                [
                    'key' => 'avg_ms',
                    'label' => $labels['avg_ms'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v, 2),
                ],
                [
                    'key' => 'max_ms',
                    'label' => $labels['max_ms'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v, 2),
                ],
            ],
            t('admin.telemetry.export.no_samples', 'No samples'),
        )
        . '</section>';

    $reportQueryPlans = is_array($reportQueryPlans ?? null)
        ? $reportQueryPlans
        : [];
    $reportQueryPlansHtml =
        '<section class="panel"><h2>'
        . e($labels['report_query_plans'])
        . '</h2><p class="muted">'
        . e($labels['report_query_plans_note'])
        . '</p>'
        . view_telemetry_export_table(
            $reportQueryPlans,
            [
                ['key' => 'operation', 'label' => $labels['query_family']],
                ['key' => 'status', 'label' => $labels['plan_status']],
                [
                    'key' => 'select_id',
                    'label' => $labels['select_id'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => $v === null || $v === ''
                        ? ''
                        : view_telemetry_report_number($v),
                ],
                ['key' => 'select_type', 'label' => $labels['select_type']],
                ['key' => 'table_name', 'label' => $labels['table']],
                ['key' => 'access_type', 'label' => $labels['access_type']],
                ['key' => 'possible_keys', 'label' => $labels['possible_keys']],
                ['key' => 'key_used', 'label' => $labels['key_used']],
                [
                    'key' => 'rows_estimate',
                    'label' => $labels['rows_estimate'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => $v === null || $v === ''
                        ? ''
                        : view_telemetry_report_number($v),
                ],
                [
                    'key' => 'filtered_percent',
                    'label' => $labels['filtered_percent'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => $v === null || $v === ''
                        ? ''
                        : view_telemetry_report_number($v, 1),
                ],
                ['key' => 'extra', 'label' => $labels['extra']],
            ],
            t(
                'admin.telemetry.export.storage_diagnostics_unavailable',
                'Storage diagnostics are unavailable for this export.',
            ),
        )
        . '</section>';

    $repairNotesHtml =
        '<section class="panel"><p class="muted">'
        . e(t('admin.telemetry.export.segment_scope'))
        . '</p><p class="muted">'
        . e(t('admin.telemetry.export.historical_note'))
        . '</p></section>';
    $cachePhasesHtml =
        '<section class="panel"><h2>'
        . e(t(
            'admin.telemetry.export.cache_phases',
            'Decoded cache by lookup phase',
        ))
        . '</h2><p class="muted">'
        . e(t('admin.telemetry.export.cache_phase_note', '', [
            'days' => (string) ($cachePhases['days'] ?? $photoDiagnosticDays),
        ]))
        . '</p>'
        . view_telemetry_export_table(
            $cachePhases['rows'] ?? [],
            [
                [
                    'key' => 'phase',
                    'label' => t('admin.telemetry.export.phase', 'Phase'),
                ],
                [
                    'key' => 'hits',
                    'label' => t('admin.telemetry.export.hits', 'Hits'),
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'misses',
                    'label' => t('admin.telemetry.export.misses', 'Misses'),
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'hit_percent',
                    'label' => t(
                        'admin.telemetry.export.hit_share',
                        'Hit share %',
                    ),
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v, 1),
                ],
            ],
            !empty($cachePhases['available'])
                ? t('admin.telemetry.export.no_samples', 'No samples')
                : t('admin.telemetry.export.diagnostic_unavailable'),
        )
        . '</section>';
    $fingerprintColumns = [
        ['key' => 'query_fingerprint', 'label' => $labels['fingerprint']],
        ['key' => 'route_name', 'label' => $labels['route']],
        ['key' => 'operation', 'label' => $labels['operation']],
        ['key' => 'table_name', 'label' => $labels['table']],
        [
            'key' => 'query_count',
            'label' => $labels['queries'],
            'format' => /** Format a bounded report cell.
             * @param int|float|string|null $v Value supplied by the prepared report row.
             * @return string Formatted display value.
             */ fn($v) => view_telemetry_report_number($v),
        ],
        [
            'key' => 'failed_count',
            'label' => $labels['failed'],
            'format' => /** Format a bounded report cell.
             * @param int|float|string|null $v Value supplied by the prepared report row.
             * @return string Formatted display value.
             */ fn($v) => view_telemetry_report_number($v),
        ],
        [
            'key' => 'total_latency_ms',
            'label' => $labels['total_latency'],
            'format' => /** Format a bounded report cell.
             * @param int|float|string|null $v Value supplied by the prepared report row.
             * @return string Formatted display value.
             */ fn($v) => view_telemetry_report_number($v) . ' ms',
        ],
        [
            'key' => 'avg_latency_ms',
            'label' => $labels['avg_latency'],
            'format' => /** Format a bounded report cell.
             * @param int|float|string|null $v Value supplied by the prepared report row.
             * @return string Formatted display value.
             */ fn($v) => view_telemetry_report_number($v, 2) . ' ms',
        ],
    ];
    $fingerprintDiagnosticsHtml =
        '<section class="panel"><h2>'
        . e(t(
            'admin.telemetry.export.fingerprints_volume',
            'Query fingerprints by volume',
        ))
        . '</h2>'
        . view_telemetry_export_table(
            $databaseFingerprintsVolume ?? [],
            $fingerprintColumns,
        )
        . '<h2>'
        . e(t(
            'admin.telemetry.export.fingerprints_failed',
            'Failed query fingerprints',
        ))
        . '</h2>'
        . view_telemetry_export_table(
            $databaseFingerprintsFailed ?? [],
            $fingerprintColumns,
        )
        . '</section>';

    return (
        '<!doctype html><html lang="'
            . e($activeLanguage)
            . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>'
            . e(t(
                'admin.telemetry.export_title',
                'PHP Gallery telemetry report',
            ))
            . '</title><style>'
            . $style
            . '</style></head><body><main>'
            . '<header><h1>'
            . e(t(
                'admin.telemetry.export_heading',
                'Anonymous telemetry report',
            ))
            . '</h1><p>'
            . e(t('admin.telemetry.generated', 'Generated'))
            . ' '
            . e($generatedAt)
            . '. '
            . e(t(
                'admin.telemetry.export_description',
                'Local, privacy-safe usage and performance statistics for PHP Gallery.',
            ))
            . '</p><p class="section-note">'
            . e(t(
                'admin.telemetry.export.report_window_note',
                'Report window: last {days} days. Inspired by common analytics reporting patterns: traffic, sessions, content engagement, acquisition source, device mix, performance, errors, cache efficiency, and operational database telemetry.',
                ['days' => (string) $days],
            ))
            . ' '
            . e(t(
                'admin.telemetry.export.traffic_segment_note',
                'Traffic segment: {segment}. Bot classification is a technical device bucket and does not identify a person.',
                ['segment' => (string) $trafficSegmentLabel],
            ))
            . '</p></header>'
            . '<section class="panel privacy"><h2>'
            . e(t('admin.telemetry.privacy_status', 'Privacy status'))
            . '</h2><p>'
            . e(t(
                'admin.telemetry.export_privacy_text',
                'This export contains aggregated anonymous telemetry only. It does not include raw IP addresses, raw browser user-agent strings, raw referrer URLs, names, email addresses, account identifiers, request bodies, or exact locations.',
            ))
            . '</p><p>'
            . e(t('admin.telemetry.public_telemetry_is', 'Public telemetry is'))
            . ' '
            . (
                $publicTelemetryEnabled
                    ? '<strong>'
                    . e(t('admin.common.enabled', 'enabled'))
                    . '</strong>'
                    : '<strong>'
                    . e(t('admin.common.disabled', 'disabled'))
                    . '</strong>'
            )
            . '. '
            . e(t(
                'admin.telemetry.raw_events_retained_for',
                'Configured raw-event retention:',
            ))
            . ' '
            . e((string) $rawRetentionDays)
            . ' '
            . e(t('admin.common.days', 'days'))
            . '.</p></section>'
            . $repairNotesHtml
            . $consistencyHtml
            . '<section class="panel"><h2>'
            . e($labels['executive_overview'])
            . '</h2><div class="grid">'
            . view_telemetry_export_metric_card(
                $labels['anonymous_sessions'],
                view_telemetry_report_number($sessions),
                $labels['session_hashes_only'],
            )
            . view_telemetry_export_metric_card(
                $labels['page_views'],
                view_telemetry_report_number($pageViews),
                t(
                    'admin.telemetry.export.per_session',
                    '{count} per session',
                    ['count' => view_telemetry_report_number(
                        $avgPagesPerSession,
                        2,
                    )],
                ),
            )
            . view_telemetry_export_metric_card(
                $labels['photo_opens'],
                view_telemetry_report_number($photoViews),
                t(
                    'admin.telemetry.export.per_session',
                    '{count} per session',
                    ['count' => view_telemetry_report_number(
                        $avgPhotosPerSession,
                        2,
                    )],
                ),
            )
            . view_telemetry_export_metric_card(
                $labels['capped_photo_time'],
                view_telemetry_report_duration($photoSeconds),
                t(
                    'admin.telemetry.export.average_session_duration',
                    'Average session duration {duration}',
                    [
                        'duration' =>
                            view_telemetry_report_duration($avgDurationSeconds),
                    ],
                ),
            )
            . view_telemetry_export_metric_card(
                $labels['bounce_rate'],
                view_telemetry_report_number($bounceRate, 1) . ' %',
                t(
                    'admin.telemetry.export.single_page_sessions',
                    '{count} single-page sessions',
                    ['count' => view_telemetry_report_number($bouncedSessions)],
                ),
            )
            . view_telemetry_export_metric_card(
                $labels['client_errors'],
                view_telemetry_report_number($clientErrorCount),
                $labels['javascript_error_events'],
            )
            . view_telemetry_export_metric_card(
                $labels['media_measured'],
                format_bytes($mediaBytes, 1),
                $labels['images_thumbnails_downloads'],
            )
            . view_telemetry_export_metric_card(
                $labels['cache_efficiency'],
                $cacheEfficiencyDisplay,
                t(
                    'admin.telemetry.export.cache_hits_misses',
                    '{hits} hits, {misses} misses',
                    [
                        'hits' => view_telemetry_report_number($cacheHitEvents),
                        'misses' =>
                            view_telemetry_report_number($cacheMissEvents),
                    ],
                ),
            )
            . view_telemetry_export_metric_card(
                $labels['db_queries'],
                $dbQueryCountDisplay,
                t(
                    'admin.telemetry.export.db_slow_failed',
                    '{slow} slow, {failed} failed',
                    [
                        'slow' => view_telemetry_report_number($dbSlowCount),
                        'failed' =>
                            view_telemetry_report_number($dbFailedCount),
                    ],
                ),
            )
            . '</div></section>'
            . '<section class="panel"><h2>'
            . e($labels['daily_trends'])
            . '</h2><div class="trend-grid">'
            . view_telemetry_export_trend_chart(
                $dailyTrends,
                'sessions',
                $labels['sessions_per_day'],
            )
            . view_telemetry_export_trend_chart(
                $dailyTrends,
                'page_views',
                $labels['page_views_per_day'],
            )
            . view_telemetry_export_trend_chart(
                $dailyTrends,
                'photo_views',
                $labels['photo_opens_per_day'],
            )
            . view_telemetry_export_trend_chart(
                $dailyTrends,
                'media_bytes',
                $labels['media_bytes_per_day'],
            )
            . view_telemetry_export_trend_chart(
                $dailyTrends,
                'client_errors',
                $labels['client_errors_per_day'],
            )
            . '</div></section>'
            . '<section class="panel split"><div><h2>'
            . e($labels['traffic_sources'])
            . '</h2>'
            . view_telemetry_export_bar_chart(
                $entryReferrers,
                'label',
                'sessions',
            )
            . '</div><div><h2>'
            . e($labels['device_type'])
            . '</h2>'
            . view_telemetry_export_bar_chart(
                $deviceSessions,
                'label',
                'sessions',
            )
            . '</div><div><h2>'
            . e($labels['browser_family'])
            . '</h2>'
            . view_telemetry_export_bar_chart(
                $browserSessions,
                'label',
                'sessions',
            )
            . '</div><div><h2>'
            . e($labels['operating_system'])
            . '</h2>'
            . view_telemetry_export_bar_chart($osSessions, 'label', 'sessions')
            . '</div><div><h2>'
            . e($labels['viewport_class'])
            . '</h2>'
            . view_telemetry_export_bar_chart(
                $viewportSessions,
                'label',
                'sessions',
            )
            . '</div><div><h2>'
            . e($labels['page_kind'])
            . '</h2>'
            . view_telemetry_export_bar_chart($pageKinds, 'label', 'events')
            . '</div></section>'
            . '<section class="panel"><h2>'
            . e($labels['top_galleries'])
            . '</h2>'
            . view_telemetry_export_table($topGalleries, [
                ['key' => 'title', 'label' => $labels['gallery']],
                ['key' => 'slug', 'label' => $labels['slug']],
                [
                    'key' => 'page_views',
                    'label' => $labels['page_views'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'photo_views',
                    'label' => $labels['photo_opens'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'photo_seconds',
                    'label' => $labels['photo_time'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_duration($v),
                ],
                [
                    'key' => 'media_bytes',
                    'label' => $labels['media_bytes'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => format_bytes((float) $v, 1),
                ],
            ])
            . '</section>'
            . '<section class="panel"><h2>'
            . e($labels['top_routes'])
            . '</h2>'
            . view_telemetry_export_table($topRoutes, [
                ['key' => 'route_name', 'label' => $labels['route']],
                [
                    'key' => 'page_views',
                    'label' => $labels['page_views'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'photo_views',
                    'label' => $labels['photo_opens'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'client_errors',
                    'label' => $labels['client_errors'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'media_bytes',
                    'label' => $labels['media_bytes'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => format_bytes((float) $v, 1),
                ],
            ])
            . '</section>'
            . '<section class="panel split"><div><h2>'
            . e($labels['landing_routes'])
            . '</h2>'
            . view_telemetry_export_table($landingRoutes, [
                ['key' => 'label', 'label' => $labels['route']],
                [
                    'key' => 'sessions',
                    'label' => $labels['sessions'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'page_views',
                    'label' => $labels['page_views'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'avg_duration_seconds',
                    'label' => $labels['avg_duration'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_duration($v),
                ],
            ])
            . '</div><div><h2>'
            . e($labels['exit_routes'])
            . '</h2>'
            . view_telemetry_export_table($exitRoutes, [
                ['key' => 'label', 'label' => $labels['route']],
                [
                    'key' => 'sessions',
                    'label' => $labels['sessions'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'page_views',
                    'label' => $labels['page_views'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'avg_duration_seconds',
                    'label' => $labels['avg_duration'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_duration($v),
                ],
            ])
            . '</div></section>'
            . '<section class="panel"><h2>'
            . e($labels['photo_engagement'])
            . '</h2><div class="split"><div><h3>'
            . e($labels['top_viewed_photos'])
            . '</h3>'
            . view_telemetry_export_photo_table(
                $topPhotos,
                'photo_views',
                t('admin.telemetry.views', 'Views'),
            )
            . '</div><div><h3>'
            . e($labels['longest_viewed_photos'])
            . '</h3>'
            . view_telemetry_export_photo_table(
                $longestPhotos,
                'avg_view_seconds',
                t(
                    'admin.telemetry.average_capped_seconds',
                    'Average capped seconds',
                ),
            )
            . '</div></div></section>'
            . '<section class="panel"><h2>'
            . e($labels['photo_open_diagnostics'])
            . '</h2><p class="muted">'
            . e(t(
                'admin.telemetry.export.photo_open_diagnostics_note',
                'Session distribution uses the selected report window. Gallery and activation-origin diagnostics use only the retained raw-event window ({days} days). Historical events without the new trigger context are labelled legacy/unclassified.',
                ['days' => (string) $photoDiagnosticDays],
            ))
            . '</p><div class="grid">'
            . view_telemetry_export_metric_card(t(
                'admin.telemetry.export.photo_open_sessions_above',
                'Sessions above {count} opens',
                ['count' => (string) $photoOpenThreshold],
            ), view_telemetry_report_number($photoOpenAnomalySummary['sessions_above_threshold'] ?? 0))
            . view_telemetry_export_metric_card(
                $labels['photo_open_max_session'],
                view_telemetry_report_number(
                    $photoOpenAnomalySummary['max_opens_per_session'] ?? 0,
                ),
            )
            . view_telemetry_export_metric_card(
                t(
                    'admin.telemetry.export.largest_session_share',
                    'Largest session share of opens',
                ),
                view_telemetry_report_number(
                    $photoOpenAnomalySummary['largest_session_share_percent']
                        ?? 0,
                    1,
                )
                    . ' %',
            )
            . '</div><div class="split"><div><h3>'
            . e($labels['photo_open_distribution'])
            . '</h3>'
            . view_telemetry_export_table($photoOpenBuckets, [
                [
                    'key' => 'bucket',
                    'label' => $labels['photo_open_bucket'],
                    'format' => static fn($v) => match ((string) $v) {
                        '0' => '0',
                        '1' => '1',
                        '2_5' => '2–5',
                        '6_10' => '6–10',
                        '11_25' => '11–25',
                        '26_50' => '26–50',
                        '51_100' => '51–100',
                        '101_plus' => '101+',
                        default => (string) $v,
                    },
                ],
                [
                    'key' => 'sessions',
                    'label' => $labels['sessions'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'photo_opens',
                    'label' => $labels['photo_opens'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'avg_opens',
                    'label' => $labels['average_opens'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v, 2),
                ],
                [
                    'key' => 'max_opens',
                    'label' => $labels['maximum_opens'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
            ])
            . '</div><div><h3>'
            . e($labels['activation_origins'])
            . '</h3>'
            . view_telemetry_export_table(
                $photoOpenOrigins,
                [
                    ['key' => 'label', 'label' => $labels['source']],
                    [
                        'key' => 'events',
                        'label' => $labels['events'],
                        'format' => /** Format a bounded report cell.
                         * @param int|float|string|null $v Value supplied by the prepared report row.
                         * @return string Formatted display value.
                         */ fn($v) => view_telemetry_report_number($v),
                    ],
                ],
                $photoOpenOriginsAvailable
                    ? t('admin.telemetry.no_data_yet', 'No telemetry data yet.')
                    : t('admin.telemetry.export.diagnostic_unavailable'),
            )
            . '</div></div><h3>'
            . e($labels['galleries_by_opens_per_session'])
            . '</h3>'
            . view_telemetry_export_table($photoOpenGalleryDiagnostics, [
                ['key' => 'title', 'label' => $labels['gallery']],
                [
                    'key' => 'sessions',
                    'label' => $labels['sessions'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'photo_opens',
                    'label' => $labels['photo_opens'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'avg_opens_per_session',
                    'label' => $labels['average_opens'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v, 2),
                ],
                [
                    'key' => 'max_opens_per_session',
                    'label' => $labels['maximum_opens'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
            ])
            . '</section>'
            . '<section class="panel split"><div><h2>'
            . e($labels['thumbnail_bytes_by_variant'])
            . '</h2>'
            . view_telemetry_export_table($mediaVariants, [
                ['key' => 'label', 'label' => $labels['variant']],
                [
                    'key' => 'events',
                    'label' => $labels['events'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'value_sum',
                    'label' => $labels['bytes'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => format_bytes((float) $v, 1),
                ],
            ])
            . '</div><div><h2>'
            . e($labels['image_bytes_by_variant'])
            . '</h2>'
            . view_telemetry_export_table($imageVariants, [
                ['key' => 'label', 'label' => $labels['variant']],
                [
                    'key' => 'events',
                    'label' => $labels['events'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'value_sum',
                    'label' => $labels['bytes'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => format_bytes((float) $v, 1),
                ],
            ])
            . '</div></section>'
            . '<section class="panel"><h2>'
            . e($labels['media_byte_split'])
            . '</h2><p class="muted">'
            . e(t(
                'admin.telemetry.export.media_scope_note',
                $labels['media_scope_note'],
            ))
            . '</p><div class="grid">'
            . view_telemetry_export_metric_card($labels['full_images'], format_bytes(
                $imageBytes,
                1,
            ))
            . view_telemetry_export_metric_card($labels['thumbnails'], format_bytes(
                $thumbnailBytes,
                1,
            ))
            . view_telemetry_export_metric_card($labels['downloads'], format_bytes(
                $downloadBytes,
                1,
            ))
            . view_telemetry_export_metric_card($labels['all_measured_media'], format_bytes(
                $mediaBytes,
                1,
            ))
            . '</div></section>'
            . '<section class="panel"><p class="muted">'
            . e(t(
                'admin.telemetry.export.cache_scope_note',
                $labels['cache_scope_note'],
            ))
            . '</p></section>'
            . $cachePhasesHtml
            . '<section class="panel split"><div><h2>'
            . e($labels['thumbnail_cache_hit_events'])
            . '</h2>'
            . view_telemetry_export_table($cacheThumbnail, [
                ['key' => 'label', 'label' => $labels['cache_result']],
                [
                    'key' => 'events',
                    'label' => $labels['events'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
            ])
            . '</div><div><h2>'
            . e($labels['thumbnail_cache_miss_events'])
            . '</h2>'
            . view_telemetry_export_table($cacheMisses, [
                ['key' => 'label', 'label' => $labels['cache_result']],
                [
                    'key' => 'events',
                    'label' => $labels['events'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
            ])
            . '</div></section>'
            . '<section class="panel"><h2>'
            . e($labels['browser_performance'])
            . '</h2><p class="muted">'
            . e(t('admin.telemetry.export.performance_valid_note'))
            . '</p>'
            . view_telemetry_export_table($performanceMetrics, [
                [
                    'key' => 'metric_name',
                    'label' => $labels['metric'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn(int|float|string|null $v): string => view_telemetry_performance_metric_label(
                        (string) $v,
                    ),
                ],
                [
                    'key' => 'metric_name',
                    'label' => $labels['unit'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn(int|float|string|null $v): string => view_telemetry_performance_metric_unit(
                        (string) $v,
                    ),
                ],
                [
                    'key' => 'samples',
                    'label' => $labels['samples'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'avg_value',
                    'label' => $labels['average_value'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v, 2),
                ],
                [
                    'key' => 'min_value',
                    'label' => $labels['minimum'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v, 2),
                ],
                [
                    'key' => 'max_value',
                    'label' => $labels['maximum'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v, 2),
                ],
            ])
            . '</section>'
            . '<section class="panel"><h2>'
            . e($labels['client_errors'])
            . '</h2>'
            . view_telemetry_export_table($clientErrors, [
                ['key' => 'error_kind', 'label' => $labels['error_kind']],
                ['key' => 'route_name', 'label' => $labels['route']],
                [
                    'key' => 'events',
                    'label' => $labels['events'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                ['key' => 'last_seen', 'label' => $labels['last_seen']],
            ])
            . '</section>'
            . '<section class="panel"><h2>'
            . e($labels['database_telemetry'])
            . '</h2><p class="muted">'
            . e($labels['database_scope_note'])
            . '</p><p class="muted">'
            . e(t('admin.telemetry.export.db_coverage', '', [
                'from' => (string) ($databaseTotals['first_bucket'] ?? ''),
                'to' => (string) ($databaseTotals['last_bucket'] ?? ''),
            ]))
            . '</p><p class="muted">'
            . e(t('admin.telemetry.export.db_rows_note'))
            . '</p><div class="grid">'
            . view_telemetry_export_metric_card(
                $labels['queries'],
                $dbQueryCountDisplay,
            )
            . view_telemetry_export_metric_card(
                $labels['slow_queries'],
                $dbSlowCountDisplay,
            )
            . view_telemetry_export_metric_card(
                $labels['failed_queries'],
                $dbFailedCountDisplay,
            )
            . '</div><h3>'
            . e($labels['routes_operations_tables'])
            . '</h3>'
            . view_telemetry_export_table($databaseSummary, [
                ['key' => 'route_name', 'label' => $labels['route']],
                ['key' => 'operation', 'label' => $labels['operation']],
                ['key' => 'table_name', 'label' => $labels['table']],
                [
                    'key' => 'query_count',
                    'label' => $labels['queries'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'failed_count',
                    'label' => $labels['failed'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'slow_count',
                    'label' => $labels['slow'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'latency_ms_sum',
                    'label' => $labels['total_latency'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v) . ' ms',
                ],
                [
                    'key' => 'latency_ms_max',
                    'label' => $labels['max_latency'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v) . ' ms',
                ],
                [
                    'key' => 'rows_returned_sum',
                    'label' => $labels['rows_returned'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn(int|float|string|null $v): string => t(
                        'admin.telemetry.export.not_measured',
                        'Not measured',
                    ),
                ],
                [
                    'key' => 'rows_affected_sum',
                    'label' => $labels['rows_affected'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
            ])
            . '<h3>'
            . e($labels['query_fingerprints'])
            . '</h3>'
            . view_telemetry_export_table($databaseFingerprints, [
                [
                    'key' => 'query_fingerprint',
                    'label' => $labels['fingerprint'],
                ],
                ['key' => 'route_name', 'label' => $labels['route']],
                ['key' => 'operation', 'label' => $labels['operation']],
                ['key' => 'table_name', 'label' => $labels['table']],
                [
                    'key' => 'query_count',
                    'label' => $labels['queries'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'failed_count',
                    'label' => $labels['failed'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'slow_count',
                    'label' => $labels['slow'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                [
                    'key' => 'avg_latency_ms',
                    'label' => $labels['avg_latency'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v, 2) . ' ms',
                ],
                [
                    'key' => 'max_latency_ms',
                    'label' => $labels['max_latency'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v) . ' ms',
                ],
            ])
            . '</section>'
            . $fingerprintDiagnosticsHtml
            . '<section class="panel"><h2>'
            . e($labels['telemetry_access_log'])
            . '</h2><p class="muted">'
            . e(t(
                'admin.telemetry.export.access_log_privacy',
                'This is an anonymized event log. It shows normalized buckets and object ids, not raw IP addresses, raw user agents, raw referrer URLs, request bodies, or personal identifiers.',
            ))
            . '</p>'
            . view_telemetry_export_table($recentEvents, [
                ['key' => 'occurred_at', 'label' => $labels['time']],
                ['key' => 'event_name', 'label' => $labels['event']],
                ['key' => 'source', 'label' => $labels['source']],
                ['key' => 'route_name', 'label' => $labels['route']],
                ['key' => 'page_kind', 'label' => $labels['kind']],
                ['key' => 'gallery_id', 'label' => $labels['gallery']],
                ['key' => 'image_id', 'label' => $labels['image']],
                ['key' => 'referrer_category', 'label' => $labels['referrer']],
                ['key' => 'browser_family', 'label' => $labels['browser']],
                ['key' => 'os_family', 'label' => $labels['os']],
                ['key' => 'device_type', 'label' => $labels['device']],
                ['key' => 'viewport_class', 'label' => $labels['viewport']],
                ['key' => 'media_variant', 'label' => $labels['variant']],
                ['key' => 'cache_result', 'label' => $labels['cache']],
                ['key' => 'http_status', 'label' => $labels['status']],
                ['key' => 'error_kind', 'label' => $labels['error']],
                [
                    'key' => 'value_bytes',
                    'label' => $labels['bytes'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => $v === null || $v === ''
                        ? ''
                        : format_bytes((float) $v, 1),
                ],
                [
                    'key' => 'value_ms',
                    'label' => $labels['value_ms'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => $v === null || $v === ''
                        ? ''
                        : view_telemetry_report_number($v) . ' ms',
                ],
                [
                    'key' => 'duration_ms_capped',
                    'label' => $labels['duration'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => $v === null || $v === ''
                        ? ''
                        : view_telemetry_report_number((float) $v / 1000, 2)
                            . ' s',
                ],
            ])
            . '</section>'
            . '<section class="panel"><h2>'
            . e($labels['telemetry_job_runs'])
            . '</h2>'
            . view_telemetry_export_table($jobRuns, [
                ['key' => 'job_name', 'label' => $labels['job']],
                ['key' => 'status', 'label' => $labels['status']],
                ['key' => 'started_at', 'label' => $labels['started']],
                ['key' => 'finished_at', 'label' => $labels['finished']],
                [
                    'key' => 'duration_ms',
                    'label' => $labels['duration'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => $v === null || $v === ''
                        ? ''
                        : view_telemetry_report_number((float) $v / 1000, 2)
                            . ' s',
                ],
                ['key' => 'gallery_id', 'label' => $labels['gallery']],
                ['key' => 'image_id', 'label' => $labels['image']],
                [
                    'key' => 'item_count',
                    'label' => $labels['items'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => $v === null || $v === ''
                        ? ''
                        : view_telemetry_report_number($v),
                ],
                [
                    'key' => 'retry_count',
                    'label' => $labels['retries'],
                    'format' => /** Format a bounded report cell.
                     * @param int|float|string|null $v Value supplied by the prepared report row.
                     * @return string Formatted display value.
                     */ fn($v) => view_telemetry_report_number($v),
                ],
                ['key' => 'error_kind', 'label' => $labels['error']],
            ])
            . '</section>'
            . $storageDiagnosticsHtml
            . $rollupConsistencyHtml
            . $reportQueryProfileHtml
            . $reportQueryPlansHtml
            . '<section class="panel"><h2>'
            . e($labels['stored_telemetry_volume'])
            . '</h2><div class="grid">'
            . view_telemetry_export_metric_card(
                $labels['raw_events'],
                view_telemetry_report_number(
                    $storedCounts['telemetry_events'] ?? 0,
                ),
            )
            . view_telemetry_export_metric_card(
                $labels['sessions'],
                view_telemetry_report_number(
                    $storedCounts['telemetry_sessions'] ?? 0,
                ),
            )
            . view_telemetry_export_metric_card(
                $labels['hourly_metrics'],
                view_telemetry_report_number(
                    $storedCounts['telemetry_hourly_metrics'] ?? 0,
                ),
            )
            . view_telemetry_export_metric_card(
                $labels['daily_metrics'],
                view_telemetry_report_number(
                    $storedCounts['telemetry_daily_metrics'] ?? 0,
                ),
            )
            . view_telemetry_export_metric_card(
                $labels['db_query_metrics'],
                view_telemetry_report_number(
                    $storedCounts['telemetry_db_query_metrics'] ?? 0,
                ),
            )
            . view_telemetry_export_metric_card(
                $labels['job_runs'],
                view_telemetry_report_number(
                    $storedCounts['telemetry_job_runs'] ?? 0,
                ),
            )
            . '</div></section>'
            . '</main></body></html>'
    );
}
