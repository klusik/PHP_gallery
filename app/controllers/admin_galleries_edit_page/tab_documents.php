<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_edit_page/tab_documents.php
 * Module Type: Controller
 *
 * Purpose:
 *   Renders the gallery editor's Documents / PDFs tab.
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
use function Gallery\Core\render_admin_tab_panel;
use function Gallery\Core\url_for;
use function Gallery\Services\feature_capability_effective_enabled;
use function Gallery\Services\gallery_flight_map_row;
use function Gallery\Views\view_render_admin_simbrief_legacy_panel;

/**
 * Render the document manager as a standalone editor tab.
 *
 * Both the gallery PDF manager and optional SimBrief legacy panel own independent
 * multipart forms and JavaScript delegates. They must not be nested inside the
 * editor's shared settings form or displayed with the upload API configuration.
 *
 * @param array{id:int,folder_path:string,presentation_json?:string|null}
 *     $gallery Authorized identity, attachment path and presentation preferences.
 * @param string $activeEditTab Currently selected editor tab.
 * @return void Emit independent attachment forms and presentation controls
 *     associated with the existing guarded gallery settings form.
 */
function admin_edit_gallery_render_documents_tab(
    array $gallery,
    string $activeEditTab,
): void {
    if (
        !feature_capability_effective_enabled('gallery_pdf_documents')
        && !feature_capability_effective_enabled('gallery_music')
        && !feature_capability_effective_enabled('lightbox_modes')
    ) {
        return;
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
    \Gallery\Views\view_render_admin_gallery_media_tab([
        'active' => $activeEditTab === 'admin-edit-documents',
        'presentation_html' => $presentationHtml,
        'documents_html' => admin_gallery_pdf_documents_html($gallery),
        'music_html' => admin_gallery_music_html($gallery),
        'simbrief_html' =>
            admin_edit_gallery_simbrief_legacy_panel_html($gallery),
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
        'action_url' => url_for('admin_edit_gallery', ['id' => $id]),
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
