<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_edit_page/tab_media.php
 * Module Type: Controller
 *
 * Purpose:
 *   Prepares gallery Artwork controls for the unified Media tab.
 *
 * Responsibilities:
 *   - Select or upload the gallery title picture
 *   - Edit the optional gallery branding assets
 *   - Choose the gallery background source or inherit the theme background
 *   - Associate all artwork fields with the shared settings form
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

use function Gallery\Services\gallery_background_source;
use function Gallery\Services\gallery_background_source_schema_ready;
use function Gallery\Services\gallery_cover_asset_schema_ready;
use function Gallery\Services\gallery_cover_selection_ids;
use function Gallery\Services\gallery_cover_selection_mode;
use function Gallery\Services\gallery_cover_selection_schema_ready;
use function Gallery\Services\t;
use function Gallery\Views\view_render_admin_gallery_media_fields;

/**
 * Prepare the artwork subtab associated with the gallery settings form.
 *
 * @param array{id:int|string,folder_path:string,title?:string,
 *     description?:string,cover_image_id?:int|string|null,
 *     cover_image_ids?:string|null,cover_image_path?:string|null,
 *     background_source?:string|null,visibility?:string|null,
 *     access_listing?:string|null,access_mode?:string|null,
 *     parent_id?:int|string|null,nsfw_enabled?:int|string|null,
 *     banner_image_path?:string|null,logo_image_path?:string|null,
 *     separator_image_path?:string|null} $gallery Gallery identity, inherited
 *     access policy, cover mode, optional assets and media settings.
 * @return string Trusted artwork markup for the unified Media panel.
 */
function admin_edit_gallery_render_media_tab(
    array $gallery,
): string {
    $coverMode = gallery_cover_selection_mode($gallery);
    $coverMultiCount = $coverMode === 'multi'
        ? count(gallery_cover_selection_ids($gallery))
        : 0;
    $coverOptionsHtml = gallery_cover_options(
        (int) $gallery['id'],
        $coverMode === 'multi' || $coverMode === 'automatic'
            ? 0
            : (int) ($gallery['cover_image_id'] ?? 0),
        true,
    );

    ob_start();
    $settingsFormId = 'admin-gallery-settings-' . (int) $gallery['id'];
    render_admin_gallery_branding_fields($gallery, $settingsFormId);
    $brandingFieldsHtml = (string) ob_get_clean();

    $backgroundSourceSchemaReady = gallery_background_source_schema_ready();
    // $backgroundSource stores an intermediate value used by the surrounding gallery workflow.
    $backgroundSource = $backgroundSourceSchemaReady
        ? gallery_background_source($gallery)
        : null;

    ob_start();
    view_render_admin_gallery_media_fields([
        'cover_options_html' => $coverOptionsHtml,
        'cover_multi_count' => $coverMultiCount,
        'cover_automatic_selected' => $coverMode === 'automatic',
        'cover_legacy_selected' => $coverMode === 'legacy',
        'cover_automatic_available' => gallery_cover_selection_schema_ready(),
        'branding_fields_html' => $brandingFieldsHtml,
        'cover_asset_schema_ready' => gallery_cover_asset_schema_ready(),
        'background_source_schema_ready' => $backgroundSourceSchemaReady,
        'background_source' => $backgroundSource,
        'settings_form_id' => $settingsFormId,
        'labels' => [
            'media_title' => t(
                'admin.gallery_editor.media_title',
                'Thumbnail, branding, and background',
            ),
            'media_help' => t(
                'admin.gallery_editor.media_help',
                'Optional visual assets override theme fallbacks only for this gallery.',
            ),
            'title_picture' => t('admin.gallery_editor.title_picture', t(
                'admin.gallery_editor.title_picture_current',
                'Title picture',
            )),
            'automatic' => t(
                'admin.gallery_editor.automatic_two_photos',
                'Automatic (2 photos)',
            ),
            'cover_legacy' => t(
                'admin.gallery_editor.cover_legacy',
                'Default (original gallery behavior)',
            ),
            'cover_multi_selected' => t(
                'admin.gallery_editor.cover_multi_selected',
                '{count} selected photos',
                ['count' => $coverMultiCount],
            ),
            'cover_auto_help' => t(
                'admin.gallery_editor.cover_auto_help',
                'Automatic selects up to two pictures from this gallery or its subgalleries. Manual stars override this choice.',
            ),
            'includes_subgallery_images' => t(
                'admin.gallery_editor.includes_subgallery_images',
                'Includes images from subgalleries.',
            ),
            'upload_gallery_thumbnail' => t(
                'admin.gallery_editor.upload_gallery_thumbnail',
                'Upload gallery thumbnail',
            ),
            'gallery_thumbnail_upload_help' => t(
                'admin.gallery_editor.gallery_thumbnail_upload_help',
                'This is stored separately from gallery images.',
            ),
            'gallery_thumbnail_migration_hidden' => t(
                'admin.gallery_editor.gallery_thumbnail_migration_hidden',
                'Uploadable gallery thumbnails will be available after the gallery thumbnail migration is applied.',
            ),
            'background_source' => t(
                'admin.gallery_editor.background_source',
                'Background source',
            ),
            'use_theme_background' => t(
                'admin.gallery_editor.use_theme_background',
                'Use theme background',
            ),
            'upload_new_image' => t(
                'admin.gallery_editor.upload_new_image',
                'Upload new image',
            ),
            'pick_existing_gallery_images' => t(
                'admin.gallery_editor.pick_existing_gallery_images',
                'Pick from existing gallery images',
            ),
            'generate_collage_public' => t(
                'admin.gallery_editor.generate_collage_public',
                'Generate collage from public galleries',
            ),
            'background_source_help' => t(
                'admin.gallery_editor.background_source_help',
                'If unset, the gallery inherits the Theme background.',
            ),
            'background_migration_hidden' => t(
                'admin.gallery_editor.background_migration_hidden',
                'Background source selection will be available after the background migration is applied.',
            ),
        ],
    ]);
    return (string) ob_get_clean();
}
