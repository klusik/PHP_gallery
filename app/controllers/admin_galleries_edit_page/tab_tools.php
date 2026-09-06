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

use function Gallery\Core\e;
use function Gallery\Core\render_admin_tab_panel;
use function Gallery\Core\url_for;
use function Gallery\Services\t;
use function Gallery\Views\view_render_admin_tab_intro;

/**
 * Render the metadata Organizer tab panel.
 *
 * @param array<string, mixed> $gallery Gallery row being edited.
 * @param string $activeEditTab Currently selected editor tab.
 */
function admin_edit_gallery_render_organizer_tab(array $gallery, string $activeEditTab): void
{
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
 */
function admin_edit_gallery_render_api_tab(array $gallery, string $activeEditTab, array $capabilities): void
{
    ob_start();
    view_render_admin_tab_intro([
        'kicker' => t('upload_automation.kicker', 'Automation'),
        'title' => t('admin.upload_automation.gallery_tab_title', 'Upload API keys'),
        'description' => t('admin.upload_automation.gallery_tab_help', 'Generate and revoke the API keys used by the Windows companion app. Keys stay scoped to this gallery, and the global API manager shows every active key across the site.'),
    ]);
    if ($capabilities['upload_api_feature_enabled']) {
        render_admin_gallery_upload_automation_panel($gallery, 'admin-edit-api');
    }
    render_admin_gallery_ai_reprocess_panel($gallery);
    if ($capabilities['gallery_migration_feature_enabled']) {
        render_admin_gallery_migration_panel($gallery);
    }
    if ($capabilities['upload_api_feature_enabled']) {
        echo '<div class="admin-upload-automation-actions"><a class="button secondary" href="' . e(url_for('admin_api_manager')) . '">' . e(t('admin.upload_automation.open_manager', 'Open API manager')) . '</a></div>';
    }
    render_admin_tab_panel('admin-edit-api', (string) ob_get_clean(), $activeEditTab === 'admin-edit-api');
}
