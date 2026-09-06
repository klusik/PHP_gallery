<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_edit_page/tab_media.php
 * Module Type: Controller
 *
 * Purpose:
 *   Renders the Media tab of the gallery editor.
 *
 * Responsibilities:
 *   - Select or upload the gallery title picture
 *   - Edit the optional gallery branding assets
 *   - Choose the gallery background source or inherit the theme background
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
 *   - Optional assets override theme fallbacks only for this gallery.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Core\e;
use function Gallery\Core\render_admin_tab_panel;
use function Gallery\Services\gallery_background_source;
use function Gallery\Services\gallery_background_source_schema_ready;
use function Gallery\Services\gallery_cover_asset_schema_ready;
use function Gallery\Services\t;
use function Gallery\Views\view_render_admin_tab_intro;

/**
 * Render the Media tab panel.
 *
 * @param array<string, mixed> $gallery Gallery row being edited.
 * @param string $activeEditTab Currently selected editor tab.
 */
function admin_edit_gallery_render_media_tab(array $gallery, string $activeEditTab): void
{
    ob_start();
    view_render_admin_tab_intro([
        'kicker' => t('admin.gallery_editor.media_kicker', 'Media'),
        'title' => t('admin.gallery_editor.media_title', 'Thumbnail, branding, and background'),
        'description' => t('admin.gallery_editor.media_help', 'Optional visual assets override theme fallbacks only for this gallery.'),
    ]);
    echo '<div class="admin-edit-card-grid">';
    echo '<div class="admin-edit-card is-wide"><label>' . e(t('admin.gallery_editor.title_picture', t('admin.gallery_editor.title_picture_current', 'Title picture'))) . '<select name="cover_image_id"><option value="0">' . e(t('admin.gallery_editor.automatic', 'Automatic')) . '</option>' . gallery_cover_options((int) $gallery['id'], (int) ($gallery['cover_image_id'] ?? 0), true) . '</select><span class="muted">' . e(t('admin.gallery_editor.includes_subgallery_images', 'Includes images from subgalleries.')) . '</span></label>';
    if (gallery_cover_asset_schema_ready()) {
        echo '<label>' . e(t('admin.gallery_editor.upload_gallery_thumbnail', 'Upload gallery thumbnail')) . '<input type="file" name="cover_upload" accept="image/*"><span class="muted">' . e(t('admin.gallery_editor.gallery_thumbnail_upload_help', 'This is stored separately from gallery images.')) . '</span></label>';
    } else {
        echo '<p class="muted">' . e(t('admin.gallery_editor.gallery_thumbnail_migration_hidden', 'Uploadable gallery thumbnails will be available after the gallery thumbnail migration is applied.')) . '</p>';
    }
    echo '</div>';
    echo '<div class="admin-edit-card is-wide">';
    render_admin_gallery_branding_fields($gallery);
    echo '</div>';
    echo '<div class="admin-edit-card is-wide">';
    if (gallery_background_source_schema_ready()) {
        // $backgroundSource stores an intermediate value used by the surrounding gallery workflow.
        $backgroundSource = gallery_background_source($gallery);
        echo '<label>' . e(t('admin.gallery_editor.background_source', 'Background source')) . '<select name="background_source"><option value=""' . ($backgroundSource === null ? ' selected' : '') . '>' . e(t('admin.gallery_editor.use_theme_background', 'Use theme background')) . '</option><option value="upload"' . ($backgroundSource === 'upload' ? ' selected' : '') . '>' . e(t('admin.gallery_editor.upload_new_image', 'Upload new image')) . '</option><option value="existing"' . ($backgroundSource === 'existing' ? ' selected' : '') . '>' . e(t('admin.gallery_editor.pick_existing_gallery_images', 'Pick from existing gallery images')) . '</option><option value="collage"' . ($backgroundSource === 'collage' ? ' selected' : '') . '>' . e(t('admin.gallery_editor.generate_collage_public', 'Generate collage from public galleries')) . '</option></select><span class="muted">' . e(t('admin.gallery_editor.background_source_help', 'If unset, the gallery inherits the Theme background.')) . '</span></label>';
    } else {
        echo '<p class="muted">' . e(t('admin.gallery_editor.background_migration_hidden', 'Background source selection will be available after the background migration is applied.')) . '</p>';
    }
    echo '</div></div>';
    render_admin_tab_panel('admin-edit-media', (string) ob_get_clean(), $activeEditTab === 'admin-edit-media');
}
