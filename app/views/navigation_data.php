<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/navigation_data.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders the admin route-data diagnostics page.
 *
 * Responsibilities:
 *   - Show the active local fallback lookup state
 *   - Reuse the Maintenance import widget and its in-place update workflow
 *   - Explain that SimBrief OFP coordinates are preferred for generated maps
 *   - Provide a small lookup tester for airports, fixes, VORs, and NDBs
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
 *   2026-05-27
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\url_for;
use function Gallery\Services\t;

/**
 * Render the dedicated admin navigation-data page.
 *
 * @param array<string,mixed> $model Prepared sources, import status and notices.
 * @return void Emits the compact navigation-data workspace.
 */
function view_render_admin_navigation_data(array $model): void
{
    $status = is_array($model['status'] ?? null) ? $model['status'] : [];
    $navdataStatus = is_array($model['navdata_status'] ?? null) ? $model['navdata_status'] : [];
    $notices = is_array($model['notices'] ?? null) ? $model['notices'] : [];

    render_header(t('admin.navdata.page_title', 'Navigation data'));

    echo '<div class="admin-navdata-page">';
    echo '<header class="admin-navdata-toolbar">';
    echo '<h1>' . e(t('admin.navdata.title', 'Navigation data')) . '</h1>';
    echo '<a class="button secondary" href="' . e(url_for('admin', ['dashboard_tab' => 'maintenance']) . '#admin-tab-maintenance') . '">' . e(t('admin.navdata.back_to_maintenance', 'Back to maintenance')) . '</a>';
    echo '</header>';

    view_render_admin_dashboard_notices($notices);

    view_render_admin_navdata_maintenance_card(!empty($navdataStatus['ready']), $navdataStatus, 'admin_navdata');

    echo '<div class="admin-navdata-tools">';
    echo '<section class="panel admin-navdata-panel" aria-labelledby="navdata-sources-title">';
    echo '<h2 id="navdata-sources-title">' . e(t('admin.navdata.status_title', 'Route data sources')) . '</h2>';
    view_render_admin_navigation_data_status_grid($status);
    echo '</section>';


    echo '<section class="panel admin-navdata-panel" aria-labelledby="navdata-lookup-title">';
    echo '<h2 id="navdata-lookup-title">' . e(t('admin.navdata.lookup_title', 'Lookup tester')) . '</h2>';
    echo '<p class="muted admin-navdata-lookup-hint">' . e(t('admin.navdata.lookup_hint', 'Find an airport or navaid in the local data.')) . '</p>';
    view_render_admin_navigation_data_lookup_tester();
    echo '</section></div>';

    echo '<details class="panel admin-navdata-rules-panel">';
    echo '<summary>' . e(t('admin.navdata.rules_title', 'Fallback rules')) . '</summary>';
    echo '<ol class="admin-navdata-rules">';
    echo '<li>' . e(t('admin.navdata.rule_local_first', 'SimBrief imports save the latest OFP and route coordinates with the gallery.')) . '</li>';
    echo '<li>' . e(t('admin.navdata.rule_cache_second', 'Public maps render only stored coordinates and never call live planning services.')) . '</li>';
    echo '<li>' . e(t('admin.navdata.rule_remote_last', 'Manual route text can still use local OurAirports and bundled fallback lookup.')) . '</li>';
    echo '<li>' . e(t('admin.navdata.rule_no_failure', 'If SimBrief is unavailable later, saved OFP files and stored route coordinates remain usable.')) . '</li>';
    echo '</ol>';
    echo '</details></div>';

    render_footer();
}

/**
 * Render compact provider status metrics.
 *
 * @param array<string,mixed> $status Prepared local source status.
 * @return void Emits secondary sources without repeating the import totals.
 */
function view_render_admin_navigation_data_status_grid(array $status): void
{
    $cards = [
        [
            'label' => t('admin.navdata.bundled', 'Bundled fallback'),
            'value' => number_format((int) ($status['bundled_count'] ?? 0)),
            'hint' => t('admin.navdata.bundled_hint', 'Small CSV shipped with the app for offline route rendering.'),
        ],
        [
            'label' => t('admin.navdata.simbrief_ofp', 'SimBrief OFP'),
            'value' => t('admin.navdata.simbrief_ofp_value', 'per gallery'),
            'hint' => t('admin.navdata.simbrief_hint', 'Saved OFP coordinates take priority over lookup data.'),
        ],
    ];

    echo '<dl class="admin-navdata-source-list">';
    foreach ($cards as $card) {
        echo '<div><dt>' . e((string) $card['label']) . '</dt>';
        echo '<dd><strong>' . e((string) $card['value']) . '</strong><small>' . e((string) $card['hint']) . '</small></dd></div>';
    }
    echo '</dl>';


}

/**
 * Render the browser lookup tester.
 *
 * @return void Emits the compact local lookup form and initially hidden feedback.
 */
function view_render_admin_navigation_data_lookup_tester(): void
{
    echo '<form class="admin-navdata-lookup-form" data-admin-navdata-lookup data-navdata-lookup-url="' . e(url_for('navdata_lookup')) . '">';
    echo '<label>' . e(t('admin.navdata.lookup_ident', 'Identifier')) . '<input name="ident" placeholder="LKPR, OKL, ABERU" minlength="2" maxlength="32" autocomplete="off" required></label>';
    echo '<button type="submit" class="secondary">' . e(t('admin.navdata.run_lookup', 'Run lookup')) . '</button>';
    echo '<div class="admin-navdata-lookup-result" data-admin-navdata-lookup-result role="status" aria-live="polite" hidden></div>';
    echo '</form>';
}
