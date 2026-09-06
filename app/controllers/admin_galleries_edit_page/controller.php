<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_edit_page/controller.php
 * Module Type: Controller
 *
 * Purpose:
 *   Coordinates the gallery edit page request from guard to rendered response.
 *
 * Responsibilities:
 *   - Authorize the request and resolve the gallery being edited
 *   - Answer the read-only JSON panel endpoints before any page rendering
 *   - Hand POST requests to the action handlers and otherwise render the tabs in order
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
 *   - Identity, Access, Display, and Media render inside one shared editor form.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Core\csrf_field;
use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Services\find_gallery;
use function Gallery\Services\gallery_images;
use function Gallery\Services\media_renamer_normalize_pattern;
use function Gallery\Services\t;

/**
 * Handles cms admin edit gallery logic for the gallery application.
 */
function cms_admin_edit_gallery(): void
{
    require_admin();
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) ($_GET['id'] ?? $_POST['id'] ?? $_POST['gallery_id'] ?? 0));
    if (!$gallery) {
        cms_not_found();
        return;
    }
    if (admin_edit_gallery_handle_json_panel_request($gallery)) {
        return;
    }

    // Schema and feature readiness is resolved once so every phase agrees.
    $capabilities = admin_edit_gallery_capabilities();

    if (request_method() === 'POST') {
        admin_edit_gallery_handle_post($gallery, $capabilities);
        return;
    }

    // Variable $images stores this steps working value.
    $images = gallery_images((int) $gallery['id'], false);
    render_header(t('admin.gallery_editor.page_title'));
    admin_edit_gallery_render_notices($gallery, $capabilities);
    // $activeEditTab stores the tab selected by redirect query state before JavaScript reads the URL hash.
    $activeEditTab = admin_edit_gallery_tab_id((string) ($_GET['tab'] ?? '')) ?: 'admin-edit-identity';
    admin_edit_gallery_render_overview($gallery, count($images), $activeEditTab, $capabilities);

    // Identity, Access, Display, and Media share one form saved by the bottom save bar.
    echo '<form method="post" enctype="multipart/form-data" class="admin-edit-gallery-form" autocomplete="off">' . csrf_field();
    echo '<input type="hidden" name="id" value="' . (int) $gallery['id'] . '">';
    echo '<input type="hidden" name="return_tab" value="admin-edit-identity">';
    admin_edit_gallery_render_identity_tab($gallery, $activeEditTab);
    admin_edit_gallery_render_access_tab($gallery, $activeEditTab, $capabilities);
    admin_edit_gallery_render_display_tab($gallery, $activeEditTab, $capabilities);
    admin_edit_gallery_render_media_tab($gallery, $activeEditTab);
    echo '<div class="admin-edit-gallery-savebar"><button type="submit">' . e(t('admin.gallery_editor.save_gallery', 'Save gallery')) . '</button><span class="muted">' . e(t('admin.gallery_editor.savebar_help', 'Saves all settings from Identity, Access, Display, and Media.')) . '</span></div>';
    echo '</form>';

    // The remaining tabs own their own forms and panels outside the editor form.
    admin_edit_gallery_render_images_tab($gallery, $images, $activeEditTab);
    admin_edit_gallery_render_organizer_tab($gallery, $activeEditTab);
    admin_edit_gallery_render_renamer_tab($gallery, $activeEditTab, $capabilities);
    admin_edit_gallery_render_api_tab($gallery, $activeEditTab, $capabilities);
    render_admin_image_reorder_script();
    render_admin_devmode_panel();
    render_footer();
}

/**
 * Answer the read-only JSON panel endpoints served by the gallery editor route.
 *
 * These run before capability resolution and page rendering because they return
 * a panel fragment rather than the editor page.
 *
 * @param array<string, mixed> $gallery Gallery row being edited.
 * @return bool True when a JSON response was sent and the caller must stop.
 */
function admin_edit_gallery_handle_json_panel_request(array $gallery): bool
{
    if (request_method() !== 'GET' || !admin_wants_json()) {
        return false;
    }
    if ((string) ($_GET['tab'] ?? '') === 'admin-edit-renamer' && function_exists('Gallery\\Controllers\\admin_media_renamer_render_gallery_panel_html')) {
        $pattern = media_renamer_normalize_pattern((string) ($_GET['renamer_pattern'] ?? ''));
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'message' => '',
            'panel_html' => admin_media_renamer_render_gallery_panel_html($gallery, $pattern),
        ]);
        return true;
    }
    if ((string) ($_GET['action'] ?? '') === 'metadata_organizer_preview_batch') {
        admin_gallery_metadata_organizer_preview_batch_response($gallery);
        return true;
    }
    return false;
}
