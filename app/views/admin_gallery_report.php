<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_gallery_report.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders the Admin complete gallery overview report generator.
 *
 * Responsibilities:
 *   - Present report scope and generation controls
 *   - Provide browser-side progress targets for Ajax generation
 *   - Keep the generated report download transient and browser-owned
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
 *   2026-06-15
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\csrf_token;
use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\url_for;
use function Gallery\Services\t;

/**
 * Render the Admin complete gallery overview report page.
 *
 * @param string $notice Notice text.
 */
function view_render_admin_gallery_report_page(string $notice = ''): void
{
    render_header(t('admin.gallery_report.page_title', 'Complete gallery overview'));

    echo '<section class="hero admin-dashboard-hero admin-gallery-report-hero"><div><p class="admin-kicker">' . e(t('admin.gallery_report.kicker', 'Maintenance report')) . '</p><h1>' . e(t('admin.gallery_report.page_title', 'Complete gallery overview')) . '</h1><p class="muted">' . e(t('admin.gallery_report.page_description', 'Generate one self-contained HTML report with storage, database, gallery, EXIF, GPS, telemetry, logs, feature, and runtime diagnostics. The finished report is returned to the browser only and is not saved on the server.')) . '</p></div>';
    echo '<div class="admin-hero-actions"><a class="button secondary" href="' . e(url_for('admin') . '#admin-tab-maintenance') . '">' . e(t('admin.gallery_report.back_to_maintenance', 'Back to maintenance')) . '</a></div></section>';

    $notice = trim($notice);
    if ($notice !== '') {
        echo '<div class="notice admin-gallery-report-notice">' . e($notice) . '</div>';
    }

    echo '<section class="panel admin-gallery-report-shell" data-admin-gallery-report data-generate-url="' . e(url_for('admin_gallery_report_generate')) . '" data-csrf-token="' . e(csrf_token()) . '">';
    echo '<div class="admin-panel-heading admin-gallery-report-heading"><div><p class="admin-kicker">' . e(t('admin.gallery_report.generator_kicker', 'Report generator')) . '</p><h2>' . e(t('admin.gallery_report.generator_title', 'Build detailed export')) . '</h2></div><p class="muted">' . e(t('admin.gallery_report.generator_hint', 'Image database rows are processed in small browser-driven Ajax batches, so large galleries should avoid PHP request timeouts. Generated-media data uses durable database metadata when available and falls back to bounded filesystem checks only when needed.')) . '</p></div>';

    echo '<div class="admin-gallery-report-scope">';
    view_render_admin_gallery_report_scope_card(t('admin.gallery_report.scope_storage', 'Storage and database'), t('admin.gallery_report.scope_storage_hint', 'Source media, generated thumbnails, display masters, data paths, table sizes, exact row counts, and largest source records.'));
    view_render_admin_gallery_report_scope_card(t('admin.gallery_report.scope_media', 'Images and EXIF'), t('admin.gallery_report.scope_media_hint', 'EXIF date, GPS, camera metadata, file extensions, MIME types, dimensions, visibility, galleries, and source-size distribution.'));
    view_render_admin_gallery_report_scope_card(t('admin.gallery_report.scope_places', 'Approximate places'), t('admin.gallery_report.scope_places_hint', 'GPS photos are grouped into approximately 20 km areas. Probable game and simulator screenshots are excluded from place clusters where possible.'));
    view_render_admin_gallery_report_scope_card(t('admin.gallery_report.scope_ops', 'Operations'), t('admin.gallery_report.scope_ops_hint', 'Telemetry, logs, feature switches, runtime, PHP extensions, image libraries, and server environment.'));
    echo '</div>';

    echo '<div class="admin-gallery-report-controls">';
    echo '<label><span>' . e(t('admin.gallery_report.telemetry_days', 'Telemetry window')) . '</span><select data-admin-gallery-report-telemetry-days><option value="7">7 ' . e(t('admin.gallery_report.days', 'days')) . '</option><option value="30" selected>30 ' . e(t('admin.gallery_report.days', 'days')) . '</option><option value="90">90 ' . e(t('admin.gallery_report.days', 'days')) . '</option><option value="365">365 ' . e(t('admin.gallery_report.days', 'days')) . '</option><option value="3650">10 ' . e(t('admin.gallery_report.years', 'years')) . '</option></select></label>';
    echo '<button type="button" class="button" data-admin-gallery-report-button>' . e(t('admin.gallery_report.generate_button', 'Generate complete report')) . '</button>';
    echo '<span class="muted" data-admin-gallery-report-status aria-live="polite">' . e(t('admin.gallery_report.idle_status', 'Ready to generate.')) . '</span>';
    echo '</div>';

    echo '<div class="admin-storage-progress admin-gallery-report-progress" data-admin-gallery-report-progress hidden><div class="admin-storage-progress-bar" aria-hidden="true"><span data-admin-gallery-report-progress-fill style="--admin-storage-progress: 0%"></span></div><div class="admin-storage-progress-meta"><span data-admin-gallery-report-progress-label>' . e(t('admin.gallery_report.progress_waiting', 'Waiting to start.')) . '</span><span data-admin-gallery-report-progress-count></span></div></div>';
    echo '<div class="admin-gallery-report-result" data-admin-gallery-report-result hidden></div>';
    echo '</section>';

    echo '<section class="panel admin-gallery-report-notes"><div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.gallery_report.notes_kicker', 'Output behavior')) . '</p><h2>' . e(t('admin.gallery_report.notes_title', 'What happens after generation')) . '</h2></div></div>';
    echo '<ul class="admin-gallery-report-note-list">';
    echo '<li>' . e(t('admin.gallery_report.note_transient', 'The final HTML is generated server-side, returned in the Ajax response, converted to a browser Blob, and downloaded from the browser.')) . '</li>';
    echo '<li>' . e(t('admin.gallery_report.note_pdf', 'For PDF, open the generated HTML and use the browser print dialog with Save as PDF. The report contains print CSS.')) . '</li>';
    echo '<li>' . e(t('admin.gallery_report.note_private', 'GPS clustering is approximate and offline. It avoids external geocoding services and does not send coordinates outside the installation.')) . '</li>';
    echo '</ul></section>';

    render_footer();
}

/**
 * Render one scope card for the report generator.
 *
 * @param string $title Card title.
 * @param string $description Card description.
 */
function view_render_admin_gallery_report_scope_card(string $title, string $description): void
{
    echo '<article class="admin-gallery-report-scope-card"><strong>' . e($title) . '</strong><span>' . e($description) . '</span></article>';
}
