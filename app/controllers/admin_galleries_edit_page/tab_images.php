<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_edit_page/tab_images.php
 * Module Type: Controller
 *
 * Purpose:
 *   Renders the Images tab of the gallery editor.
 *
 * Responsibilities:
 *   - List gallery photos with drag-to-reorder handles and name sorting
 *   - Expose the bulk image toolbar and per-row edit and delete actions
 *   - Offer the upload, duplicate-detector, and scan/import entry points
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
 *   - This tab posts to the bulk image controller, not to the shared editor form.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Core\csrf_field;
use function Gallery\Core\url_for;
use function Gallery\Services\feature_capability_effective_enabled;
use function Gallery\Services\normalize_gallery_visibility;
use function Gallery\Services\gallery_cover_selection_ids;
use function Gallery\Services\gallery_cover_selection_mode;
use function Gallery\Services\gallery_cover_selection_revision;
use function Gallery\Services\gallery_cover_selection_schema_ready;
use function Gallery\Services\t;
use function Gallery\Services\thumbnail_url;
use function Gallery\Services\thumbnail_editor_preload_url;
use function Gallery\Services\thumbnail_editor_preview_url;
use function Gallery\Views\view_render_admin_gallery_images_tab;
use function Gallery\Views\view_render_admin_gallery_image_rows;

/**
 * Render the Images tab panel.
 *
 * @param array{id:int|string,folder_path:string,title?:string,cover_image_id?:int|string|null,cover_image_ids?:string|null,cover_image_path?:string|null,edit_revision?:int|string|null,visibility?:string,access_mode?:string,access_listing?:int|string|null,url_path?:string|null,thumbnail_min_size?:int|string|null,thumbnail_max_size?:int|string|null} $gallery Stored editor identity, three-state cover choice, revision and thumbnail URL policy inputs.
 * @param list<array{id:int|string,gallery_id:int|string,relative_path:string,filename:string,visibility:string,sort_order?:int|string,exif_taken_at?:string|null,title?:string|null,width?:int|string|null,height?:int|string|null,updated_at?:string|null,modified_at?:string|null,thumbnail_source_identity_version?:int|string|null,thumbnail_derivative_version?:int|string|null,thumbnail_min_size?:int|string|null,thumbnail_max_size?:int|string|null}> $images Ordered direct rows mapped to canonical presentation fields.
 * @param string $activeEditTab Currently selected editor tab.
 * @param bool $rowsOnly Whether only canonical table rows are requested.
 * @param bool $deferred Whether thumbnail requests are reserved for the preload queue.
 * @return void Render prepared compact image rows and their panel actions.
 */
function admin_edit_gallery_render_images_tab(
    array $gallery,
    array $images,
    string $activeEditTab,
    bool $rowsOnly = false,
    bool $deferred = false,
): void {
    $actions = [];
    if (feature_capability_effective_enabled('duplicate_photo_detector')) {
        $actions[] = [
            'label' => t(
                'admin.duplicate_photos.action_label',
                'Find duplicate photos',
            ),
            'url' => url_for('admin_duplicate_photos', [
                'gallery_id' => $gallery['id'],
            ]),
            'class' => 'button secondary',
            'attributes' => [
                'data-gallery-side-panel-link' => true,
                'data-admin-side-panel-workflow' => 'duplicate-detector',
                'data-admin-side-panel-kicker' => t(
                    'admin.duplicate_photos.kicker',
                    'Gallery tools',
                ),
                'data-admin-side-panel-title' => t(
                    'admin.duplicate_photos.page_title',
                    'Duplicate Photo Detector',
                ),
                'data-gallery-side-panel-url' => url_for('admin_duplicate_photos', [
                    'gallery_id' => $gallery['id'],
                    'panel' => 1,
                ]),
            ],
        ];
    }

    ob_start();
    if (!$rowsOnly) {
        render_admin_image_bulk_toolbar($gallery);
    }
    $bulkToolbarHtml = (string) ob_get_clean();

    $coverMode = gallery_cover_selection_mode($gallery);
    $coverMultiSupported = gallery_cover_selection_schema_ready();
    $coverSelectionIds = $coverMode === 'multi'
        ? gallery_cover_selection_ids($gallery)
        : [];
    $imageRows = [];
    foreach ($images as $image) {
        $imageId = (int) $image['id'];
        $relativePath = (string) $image['relative_path'];
        $coverState = $coverMode === 'multi'
        && in_array($imageId, $coverSelectionIds, true)
            ? 'multi'
            : (
                $coverMode !== 'multi'
                    && $coverMode !== 'automatic'
                    && (int) ($gallery['cover_image_id'] ?? 0) === $imageId
                        ? 'single'
                        : 'off'
            );
        $imageRows[] = [
            'id' => $imageId,
            'relative_path' => $relativePath,
            'visibility' => normalize_gallery_visibility(
                (string) $image['visibility'],
            ),
            'thumbnail_url' => $deferred
                ? thumbnail_editor_preload_url($image)
                : thumbnail_url($image, 300),
            'preview_url' => $deferred
                ? thumbnail_editor_preview_url($image, $gallery)
                : thumbnail_url($image, 900),
            'capture_date' => (string) ($image['exif_taken_at'] ?? ''),
            'move_aria' => t('admin.image_order.move_aria', 'Move {file}', [
                'file' => $relativePath,
            ]),
            'cover_state' => $coverState,
            'cover_mode' => $coverMode,
            'cover_multi_supported' => $coverMultiSupported,
            'cover_label' => $coverState !== 'off'
                ? t(
                    'admin.gallery_editor.title_picture_current',
                    'Title picture',
                )
                : '',
            'edit_url' => url_for('admin_edit_image', ['id' => $imageId]),
            'panel_url' => url_for('admin_edit_image', [
                'id' => $imageId,
                'panel' => 1,
            ]),
        ];
    }

    $viewModel = [
        'deferred' => $deferred,
        'active' => $activeEditTab === 'admin-edit-images',
        'gallery_id' => (int) $gallery['id'],
        'csrf_html' => csrf_field(),
        'bulk_action_url' => url_for('admin_bulk_images'),
        'reorder_url' => url_for('admin_reorder_images'),
        'bulk_toolbar_html' => $bulkToolbarHtml,
        'scan_action' => [
            'url' => url_for('admin_scan_images'),
            'label' => t(
                'admin.gallery_editor.scan_import_images',
                'Scan/import images',
            ),
        ],
        'intro' => [
            'kicker' => t('admin.gallery_editor.tab_images', 'Images'),
            'title' => t(
                'admin.gallery_editor.images_title',
                'Photos and ordering',
            ),
            'actions' => $actions,
        ],
        'images' => $imageRows,
        'cover_selection_mode' => $coverMode,
        'cover_selection_revision' =>
            gallery_cover_selection_revision($gallery),
        'cover_multi_supported' => $coverMultiSupported,
        'labels' => [
            'drag_help' => t(
                'admin.gallery_editor.drag_photos_help',
                'Drag to reorder, or sort by filename or EXIF capture date. Photos without a capture date stay last. Each change is saved immediately.',
            ),
            'order_unchanged' => t(
                'admin.gallery_editor.order_unchanged',
                'Order unchanged.',
            ),
            'move' => t('admin.gallery_editor.move', 'Move'),
            'select' => t('admin.gallery_editor.select', 'Select'),
            'preview' => t('admin.gallery_editor.preview', 'Preview'),
            'sort_a_z' => t(
                'admin.gallery_editor.sort_photos_a_z',
                'Sort photos by name from A to Z',
            ),
            'name' => t('admin.gallery_editor.name', 'Name'),
            'file_names_shown' => t(
                'admin.gallery_editor.file_names_shown',
                'File names shown',
            ),
            'status' => t('admin.gallery_editor.status', 'Status'),
            'cover' => t('admin.gallery_editor.cover', 'Cover'),
            'actions' => t('admin.gallery_editor.actions', 'Actions'),
            'drag_title' => t(
                'admin.image_order.drag_title',
                'Drag to reorder',
            ),
            'photo_editor' => t(
                'admin.gallery_editor.photo_editor',
                'Photo editor',
            ),
            'edit_photo' => t('admin.gallery_editor.edit_photo', 'Edit photo'),
            'edit' => t('admin.gallery_editor.edit', 'Edit'),
            'delete' => t('admin.gallery_editor.delete', 'Delete'),
            'show_names' => t(
                'admin.gallery_editor.images_show_names',
                'Show filenames',
            ),
            'save_names_default' => t(
                'admin.gallery_editor.images_save_names_default',
                'Use as default',
            ),
            'names_default_saved' => t(
                'admin.gallery_editor.images_names_default_saved',
                'Default saved for other gallery editors.',
            ),
            'names_unavailable' => t(
                'admin.gallery_editor.images_names_unavailable',
                'Browser storage is unavailable; this choice applies only to this editor session.',
            ),
            'capture_date' => t(
                'admin.gallery_editor.images_capture_date',
                'Date taken',
            ),
            'sort_capture_date' => t(
                'admin.image_order.sort_capture_date_asc_aria',
                'Sort photos by capture date from oldest to newest',
            ),
            'set_cover' => t(
                'admin.gallery_editor.set_as_title_picture',
                'Set as title picture',
            ),
            'cover_arm' => t(
                'admin.gallery_editor.cover_arm',
                'Also select other photos',
            ),
            'cover_add' => t(
                'admin.gallery_editor.cover_add',
                'Add to title pictures',
            ),
            'cover_reset' => t(
                'admin.gallery_editor.cover_reset',
                'Use only this photo',
            ),
            'cover_limit' => t(
                'admin.gallery_editor.cover_selection_limit',
                'Choose at most four title-picture photos.',
            ),
            'cover_multi_label' => t(
                'admin.gallery_editor.cover_multi_selected',
                '{count} selected photos (blue stars)',
                ['count' => '{count}'],
            ),
            'close_preview' => t(
                'admin.gallery_editor.images_close_preview',
                'Close preview',
            ),
        ],
    ];
    if ($rowsOnly) {
        view_render_admin_gallery_image_rows(
            $imageRows,
            $viewModel['labels'],
            $deferred,
        );
        return;
    }
    view_render_admin_gallery_images_tab($viewModel);
}
