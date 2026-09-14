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

use function Gallery\Core\render_admin_tab_panel;
use function Gallery\Services\gallery_background_source;
use function Gallery\Services\gallery_background_source_schema_ready;
use function Gallery\Services\gallery_cover_asset_schema_ready;
use function Gallery\Services\t;
use function Gallery\Views\view_render_admin_gallery_media_fields;
use function Gallery\Views\view_render_admin_tab_intro;

/**
 * Render the Media tab panel.
 *
 * @param array<string, mixed> $gallery Gallery row being edited.
 * @param string $activeEditTab Currently selected editor tab.
 */
function admin_edit_gallery_render_media_tab(array $gallery, string $activeEditTab): void
{
    $coverOptionsHtml = gallery_cover_options((int) $gallery['id'], (int) ($gallery['cover_image_id'] ?? 0), true);

    ob_start();
    render_admin_gallery_branding_fields($gallery);
    $brandingFieldsHtml = (string) ob_get_clean();

    $backgroundSourceSchemaReady = gallery_background_source_schema_ready();
    // $backgroundSource stores an intermediate value used by the surrounding gallery workflow.
    $backgroundSource = $backgroundSourceSchemaReady ? gallery_background_source($gallery) : null;

    ob_start();
    view_render_admin_tab_intro([
        'kicker' => t('admin.gallery_editor.media_kicker', 'Media'),
        'title' => t('admin.gallery_editor.media_title', 'Thumbnail, branding, and background'),
        'description' => t('admin.gallery_editor.media_help', 'Optional visual assets override theme fallbacks only for this gallery.'),
    ]);
    view_render_admin_gallery_media_fields([
        'cover_options_html' => $coverOptionsHtml,
        'branding_fields_html' => $brandingFieldsHtml,
        'cover_asset_schema_ready' => gallery_cover_asset_schema_ready(),
        'background_source_schema_ready' => $backgroundSourceSchemaReady,
        'background_source' => $backgroundSource,
        'labels' => [
            'title_picture' => t('admin.gallery_editor.title_picture', t('admin.gallery_editor.title_picture_current', 'Title picture')),
            'automatic' => t('admin.gallery_editor.automatic', 'Automatic'),
            'includes_subgallery_images' => t('admin.gallery_editor.includes_subgallery_images', 'Includes images from subgalleries.'),
            'upload_gallery_thumbnail' => t('admin.gallery_editor.upload_gallery_thumbnail', 'Upload gallery thumbnail'),
            'gallery_thumbnail_upload_help' => t('admin.gallery_editor.gallery_thumbnail_upload_help', 'This is stored separately from gallery images.'),
            'gallery_thumbnail_migration_hidden' => t('admin.gallery_editor.gallery_thumbnail_migration_hidden', 'Uploadable gallery thumbnails will be available after the gallery thumbnail migration is applied.'),
            'background_source' => t('admin.gallery_editor.background_source', 'Background source'),
            'use_theme_background' => t('admin.gallery_editor.use_theme_background', 'Use theme background'),
            'upload_new_image' => t('admin.gallery_editor.upload_new_image', 'Upload new image'),
            'pick_existing_gallery_images' => t('admin.gallery_editor.pick_existing_gallery_images', 'Pick from existing gallery images'),
            'generate_collage_public' => t('admin.gallery_editor.generate_collage_public', 'Generate collage from public galleries'),
            'background_source_help' => t('admin.gallery_editor.background_source_help', 'If unset, the gallery inherits the Theme background.'),
            'background_migration_hidden' => t('admin.gallery_editor.background_migration_hidden', 'Background source selection will be available after the background migration is applied.'),
        ],
    ]);
    render_admin_tab_panel('admin-edit-media', (string) ob_get_clean(), $activeEditTab === 'admin-edit-media');
}
