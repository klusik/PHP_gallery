<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_edit_page/tab_documents.php
 * Module Type: Controller
 *
 * Purpose:
 *   Prepares the gallery editor's capability-scoped Media subtabs.
 *
 * Responsibilities:
 *   - Keep general gallery PDF attachments outside the API configuration tab
 *   - Present optional historical SimBrief OFP PDF attachment and dispatch actions
 *   - Keep document upload/conversion forms outside the shared gallery-settings form
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Loaded by app/controllers/admin_galleries_edit_page.php; do not require directly.
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Core\csrf_field;
use function Gallery\Core\url_for;
use function Gallery\Services\feature_capability_effective_enabled;
use function Gallery\Services\gallery_flight_map_row;
use function Gallery\Views\view_render_admin_simbrief_legacy_panel;

/**
 * Render all available gallery media sections inside the unified Media tab.
 *
 * Both the gallery PDF manager and optional SimBrief legacy panel own independent
 * multipart forms and JavaScript delegates. They must not be nested inside the
 * editor's shared settings form or displayed with the upload API configuration.
 *
 * @param array{id:int|string,folder_path:string,title?:string,
 *     presentation_json?:string|null,cover_image_id?:int|string|null,
 *     cover_image_ids?:string|null,cover_image_path?:string|null,
 *     background_source?:string|null,visibility?:string|null,
 *     access_listing?:string|null,access_mode?:string|null,
 *     parent_id?:int|string|null,nsfw_enabled?:int|string|null,
 *     banner_image_path?:string|null,logo_image_path?:string|null,
 *     separator_image_path?:string|null} $gallery Authorized gallery data
 *     for attachments, presentation and Artwork preparation.
 * @param string $activeEditTab Currently selected main editor tab.
 * @return void Emit the unified Media tab with capability-scoped subtabs.
 */
function admin_edit_gallery_render_documents_tab(
    array $gallery,
    string $activeEditTab,
): void {
    $mediaSection = (string) ($_GET['media_section'] ?? '');
    if ((string) ($_GET['tab'] ?? '') === 'admin-edit-documents') {
        $mediaSection = 'pdf';
    }
    $presentationHtml = '';
    if (feature_capability_effective_enabled('lightbox_modes')) {
        $settings = \Gallery\Services\gallery_presentation_settings($gallery);
        $url = \Gallery\Core\gallery_public_url($gallery);
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query([
            'lang' => \Gallery\Services\translation_active_language(),
        ] + \Gallery\Services\gallery_presentation_query($settings));
        ob_start();
        \Gallery\Views\view_render_admin_gallery_presentation([
            'gallery_id' => (int) $gallery['id'],
            'settings' => $settings,
            'ready' => \Gallery\Services\gallery_presentation_schema_ready(),
            'url' => $url,
        ]);
        $presentationHtml = (string) ob_get_clean();
    }
    $musicHtml = '';
    if (feature_capability_effective_enabled('gallery_music')) {
        $musicHtml = admin_gallery_music_html(
            $gallery,
            trim((string) ($_GET['music_query'] ?? '')),
        );
    }
    $documentsHtml = '';
    if (feature_capability_effective_enabled('gallery_pdf_documents')) {
        $documentsHtml = admin_gallery_pdf_documents_html($gallery)
            . admin_edit_gallery_simbrief_legacy_panel_html($gallery);
    }
    \Gallery\Views\view_render_admin_gallery_media_tab([
        'active' => $activeEditTab === 'admin-edit-media',
        'active_section' => $mediaSection,
        'presentation_html' => $presentationHtml,
        'documents_html' => $documentsHtml,
        'music_html' => $musicHtml,
        'artwork_html' => admin_edit_gallery_render_media_tab($gallery),
        'settings_form_id' => 'admin-gallery-settings-'
            . (int) $gallery['id'],
    ]);
}

/**
 * Render the gallery-specific OFP document and historical dispatch workflow.
 *
 * This is also used after an AJAX upload to replace only its own card in a
 * dynamically mounted side panel, without submitting gallery settings.
 *
 * @param array{id:int,folder_path:string} $gallery Authorized physical gallery.
 * @return string Complete document-action card, or empty when disabled.
 */
function admin_edit_gallery_simbrief_legacy_panel_html(array $gallery): string
{
    if (
        !feature_capability_effective_enabled('simbrief')
        || !feature_capability_effective_enabled('gallery_pdf_documents')
    ) {
        return '';
    }
    require_once __DIR__ . '/../../services/simbrief_ofp_attachments.php';
    $root = \Gallery\Services\simbrief_ofp_gallery_directory($gallery);
    $hasPdf = \Gallery\Services\simbrief_ofp_local_pdf_path($gallery) !== null;
    $occupied =
        $root !== null
        && (
            file_exists($root . '/simbrief-ofp.pdf')
            || is_link($root . '/simbrief-ofp.pdf')
        );
    $manifest = \Gallery\Services\simbrief_ofp_gallery_manifest($gallery);
    $flightMap = null;
    if (
        feature_capability_effective_enabled('flight_maps')
        && function_exists('Gallery\\Services\\gallery_flight_map_row')
    ) {
        $flightMap = gallery_flight_map_row((int) $gallery['id']);
    }
    $prefill = \Gallery\Services\simbrief_ofp_dispatch_prefill(
        $gallery,
        $flightMap,
    );
    $id = (int) $gallery['id'];
    ob_start();
    view_render_admin_simbrief_legacy_panel([
        'gallery' => $gallery,
        'csrf_html' => csrf_field(),
        'action_url' => url_for('admin_edit_gallery', [
            'id' => $id,
            'tab' => 'admin-edit-media',
            'media_section' => 'pdf',
        ]),
        'pdf_view_url' => url_for('gallery_ofp_pdf', ['id' => $id]),
        'pdf_download_url' => url_for('gallery_ofp_pdf', [
            'id' => $id,
            'download' => 1,
        ]),
        'has_pdf' => $hasPdf,
        'occupied' => $occupied,
        'provenance' => (string) (
            $manifest['pdf_provenance']
            ?? ($hasPdf ? 'original_simbrief_import' : '')
        ),
        'prefill' => $prefill,
    ]);
    return (string) ob_get_clean();
}
