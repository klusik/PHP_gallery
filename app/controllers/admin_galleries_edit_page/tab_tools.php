<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_edit_page/tab_tools.php
 * Module Type: Controller
 *
 * Purpose:
 *   Renders the Organizer, File renamer, and API tabs of the gallery editor.
 *
 * Responsibilities:
 *   - Delegate the metadata organizer and media renamer panels to their owners
 *   - Present upload API keys, AI reprocessing, and gallery transfer tools
 *   - Hide each tool whose global feature flag is disabled
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
 *   - Loaded by app/controllers/admin_galleries_edit_page.php; do not require this file directly.
 *   - These tabs render after the shared editor form is closed.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Core\csrf_field;
use function Gallery\Core\render_admin_tab_panel;
use function Gallery\Core\url_for;
use function Gallery\Services\feature_capability_effective_enabled;
use function Gallery\Services\gallery_flight_map_row;
use function Gallery\Services\t;
use function Gallery\Views\view_render_admin_gallery_advanced_tools;
use function Gallery\Views\view_render_admin_simbrief_legacy_panel;
use function Gallery\Views\view_render_admin_upload_automation_manager_action;

/**
 * Render the metadata Organizer tab panel.
 *
 * @param array<string, mixed> $gallery Gallery row being edited.
 * @param string $activeEditTab Currently selected editor tab.
 */
function admin_edit_gallery_render_organizer_tab(array $gallery, string $activeEditTab): void
{
    if (!feature_capability_effective_enabled('metadata_organizer')) {
        return;
    }
    ob_start();
    render_admin_gallery_metadata_organizer_panel($gallery);
    render_admin_tab_panel('admin-edit-organizer', (string) ob_get_clean(), $activeEditTab === 'admin-edit-organizer');
}

/**
 * Render the File renamer tab panel when the feature is enabled.
 *
 * @param array<string, mixed> $gallery Gallery row being edited.
 * @param string $activeEditTab Currently selected editor tab.
 * @param array<string, mixed> $capabilities Resolved editor capabilities.
 */
function admin_edit_gallery_render_renamer_tab(array $gallery, string $activeEditTab, array $capabilities): void
{
    if (!$capabilities['media_renamer_feature_enabled']) {
        return;
    }
    ob_start();
    if (function_exists('Gallery\\Controllers\\render_admin_media_renamer_gallery_panel')) {
        render_admin_media_renamer_gallery_panel($gallery);
    }
    render_admin_tab_panel('admin-edit-renamer', (string) ob_get_clean(), $activeEditTab === 'admin-edit-renamer');
}

/**
 * Render the API tab panel with upload keys, AI reset, and transfer tools.
 *
 * @param array<string, mixed> $gallery Gallery row being edited.
 * @param string $activeEditTab Currently selected editor tab.
 * @param array<string, mixed> $capabilities Resolved editor capabilities.
 * @return void Render the API tab.
 */
function admin_edit_gallery_render_api_tab(array $gallery, string $activeEditTab, array $capabilities): void
{
    ob_start();
    echo admin_edit_gallery_simbrief_legacy_panel_html($gallery);
    if ($capabilities['upload_api_feature_enabled']) {
        render_admin_gallery_upload_automation_panel($gallery, 'admin-edit-api');
    }
    ob_start();
    render_admin_gallery_ai_reprocess_panel($gallery);
    if ($capabilities['gallery_migration_feature_enabled']) {
        render_admin_gallery_migration_panel($gallery);
    }
    if ($capabilities['upload_api_feature_enabled']) {
        view_render_admin_upload_automation_manager_action([
            'href' => url_for('admin_api_manager'),
            'label' => t('admin.upload_automation.open_manager', 'Open API manager'),
        ]);
    }
    $advancedTools = (string) ob_get_clean();
    if (trim($advancedTools) !== '') {
        view_render_admin_gallery_advanced_tools(t('admin.gallery_editor.advanced_api_tools', 'Advanced API tools'), $advancedTools);
    }
    render_admin_tab_panel('admin-edit-api', (string) ob_get_clean(), $activeEditTab === 'admin-edit-api');
}

/**
 * Render the gallery-specific OFP document and historical dispatch workflow.
 *
 * This is also used after an AJAX upload to replace only its own card in a
 * dynamically mounted side panel, without submitting gallery settings.
 *
 * @param array<string,mixed> $gallery Authorized persisted physical gallery.
 * @return string Complete document-action card, or empty when disabled.
 */
function admin_edit_gallery_simbrief_legacy_panel_html(array $gallery): string
{
    if (!feature_capability_effective_enabled('simbrief')) {
        return '';
    }
    require_once __DIR__ . '/../../services/simbrief_ofp_attachments.php';
    $root = \Gallery\Services\simbrief_ofp_gallery_directory($gallery);
    $hasPdf = \Gallery\Services\simbrief_ofp_local_pdf_path($gallery) !== null;
    $occupied = $root !== null
        && (file_exists($root . '/simbrief-ofp.pdf') || is_link($root . '/simbrief-ofp.pdf'));
    $manifest = \Gallery\Services\simbrief_ofp_gallery_manifest($gallery);
    $flightMap = null;
    if (feature_capability_effective_enabled('flight_maps')
        && function_exists('Gallery\\Services\\gallery_flight_map_row')) {
        $flightMap = gallery_flight_map_row((int) $gallery['id']);
    }
    $prefill = \Gallery\Services\simbrief_ofp_dispatch_prefill($gallery, $flightMap);
    $id = (int) $gallery['id'];
    ob_start();
    view_render_admin_simbrief_legacy_panel([
        'gallery' => $gallery,
        'csrf_html' => csrf_field(),
        'action_url' => url_for('admin_edit_gallery', ['id' => $id]),
        'pdf_view_url' => url_for('gallery_ofp_pdf', ['id' => $id]),
        'pdf_download_url' => url_for('gallery_ofp_pdf', ['id' => $id, 'download' => 1]),
        'has_pdf' => $hasPdf,
        'occupied' => $occupied,
        'provenance' => (string) ($manifest['pdf_provenance'] ?? ($hasPdf ? 'original_simbrief_import' : '')),
        'prefill' => $prefill,
    ]);
    return (string) ob_get_clean();
}
