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
 * @param array $model Model value.
 */
function view_render_admin_navigation_data(array $model): void
{
    $status = is_array($model['status'] ?? null) ? $model['status'] : [];
    $notices = is_array($model['notices'] ?? null) ? $model['notices'] : [];

    render_header(t('admin.navdata.page_title', 'Navigation data'));

    echo '<section class="hero admin-dashboard-hero admin-navdata-hero"><div>';
    echo '<p class="admin-kicker">' . e(t('admin.navdata.kicker', 'Flight maps')) . '</p>';
    echo '<h1>' . e(t('admin.navdata.title', 'Navigation data')) . '</h1>';
    echo '<p class="muted">' . e(t('admin.navdata.description', 'Manage the local fallback lookup layer used when a route is entered manually. SimBrief-generated maps use saved OFP coordinates first.')) . '</p>';
    echo '</div><div class="admin-hero-actions">';
    echo '<a class="button secondary" href="' . e(url_for('admin') . '#admin-tab-maintenance') . '">' . e(t('admin.navdata.back_to_maintenance', 'Back to maintenance')) . '</a>';
    echo '</div></section>';

    view_render_admin_dashboard_notices($notices);

    echo '<section class="panel admin-navdata-panel">';
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.navdata.status_kicker', 'Status')) . '</p><h2>' . e(t('admin.navdata.status_title', 'Route data sources')) . '</h2></div><p class="muted">' . e(t('admin.navdata.status_description', 'SimBrief OFP coordinates are preferred for gallery flight maps. Local data remains available for manual route fallback lookup.')) . '</p></div>';
    view_render_admin_navigation_data_status_grid($status);
    echo '</section>';


    echo '<section class="panel admin-navdata-panel">';
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.navdata.lookup_kicker', 'Diagnostics')) . '</p><h2>' . e(t('admin.navdata.lookup_title', 'Lookup tester')) . '</h2></div><p class="muted">' . e(t('admin.navdata.lookup_description', 'Test the local fallback resolver used for manually entered route identifiers. SimBrief-imported OFPs do not need this lookup when coordinates are present.')) . '</p></div>';
    view_render_admin_navigation_data_lookup_tester();
    echo '</section>';

    echo '<section class="panel admin-navdata-panel">';
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.navdata.rules_kicker', 'Behavior')) . '</p><h2>' . e(t('admin.navdata.rules_title', 'Fallback rules')) . '</h2></div></div>';
    echo '<ol class="admin-navdata-rules">';
    echo '<li>' . e(t('admin.navdata.rule_local_first', 'SimBrief imports save the latest OFP and route coordinates with the gallery.')) . '</li>';
    echo '<li>' . e(t('admin.navdata.rule_cache_second', 'Public maps render only stored coordinates and never call live planning services.')) . '</li>';
    echo '<li>' . e(t('admin.navdata.rule_remote_last', 'Manual route text can still use local OurAirports and bundled fallback lookup.')) . '</li>';
    echo '<li>' . e(t('admin.navdata.rule_no_failure', 'If SimBrief is unavailable later, saved OFP files and stored route coordinates remain usable.')) . '</li>';
    echo '</ol>';
    echo '</section>';

    render_footer();
}

/**
 * Render compact provider status metrics.
 *
 * @param array $status Status value.
 */
function view_render_admin_navigation_data_status_grid(array $status): void
{
    $cards = [
        [
            'label' => t('admin.navdata.local_db', 'Local DB'),
            'value' => !empty($status['local_db_ready']) ? number_format((int) ($status['local_db_count'] ?? 0)) : t('admin.navdata.not_ready', 'not ready'),
            'hint' => t('admin.navdata.local_db_hint', 'OurAirports import table used for airports and navaids.'),
        ],
        [
            'label' => t('admin.navdata.bundled', 'Bundled fallback'),
            'value' => number_format((int) ($status['bundled_count'] ?? 0)),
            'hint' => t('admin.navdata.bundled_hint', 'Small CSV shipped with the app for offline route rendering.'),
        ],
        [
            'label' => t('admin.navdata.simbrief_ofp', 'SimBrief OFP'),
            'value' => t('admin.navdata.simbrief_ofp_value', 'per gallery'),
            'hint' => t('admin.navdata.simbrief_ofp_hint', 'Generated descriptions save simbrief-ofp.json and route coordinates with the gallery.'),
        ],
    ];

    echo '<div class="admin-navdata-status-grid">';
    foreach ($cards as $card) {
        echo '<article class="admin-metric-card admin-navdata-status-card">';
        echo '<span>' . e((string) $card['label']) . '</span>';
        echo '<strong>' . e((string) $card['value']) . '</strong>';
        echo '<small>' . e((string) $card['hint']) . '</small>';
        echo '</article>';
    }
    echo '</div>';


}

/**
 * Render the browser lookup tester.
 */
function view_render_admin_navigation_data_lookup_tester(): void
{
    echo '<form class="admin-navdata-lookup-form" data-admin-navdata-lookup data-navdata-lookup-url="' . e(url_for('navdata_lookup')) . '">';
    echo '<label>' . e(t('admin.navdata.lookup_ident', 'Identifier')) . '<input name="ident" placeholder="LKPR, OKL, ABERU" autocomplete="off" required></label>';
    echo '<button type="submit" class="secondary">' . e(t('admin.navdata.run_lookup', 'Run lookup')) . '</button>';
    echo '<div class="admin-navdata-lookup-result" data-admin-navdata-lookup-result role="status" aria-live="polite"></div>';
    echo '</form>';
}
