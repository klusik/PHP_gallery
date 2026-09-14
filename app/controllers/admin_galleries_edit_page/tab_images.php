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
use function Gallery\Services\gallery_shows_filenames;
use function Gallery\Services\t;
use function Gallery\Services\thumbnail_url;
use function Gallery\Views\view_render_admin_gallery_images_tab;

/**
 * Render the Images tab panel.
 *
 * @param array<string, mixed> $gallery Gallery row being edited.
 * @param array<int, array<string, mixed>> $images Images attached to this gallery.
 * @param string $activeEditTab Currently selected editor tab.
 */
function admin_edit_gallery_render_images_tab(array $gallery, array $images, string $activeEditTab): void
{
    $actions = [
        [
            'label' => t('admin.gallery_editor.upload_photos_here', 'Upload photos here'),
            'url' => url_for('admin_upload', ['gallery_id' => $gallery['id']]),
            'class' => 'button',
            'attributes' => [
                'data-gallery-side-panel-link' => true,
                'data-admin-side-panel-workflow' => 'upload',
                'data-admin-side-panel-kicker' => t('admin.gallery_editor.upload_workflow', 'Upload workflow'),
                'data-admin-side-panel-title' => t('admin.gallery_editor.upload_photos', 'Upload photos'),
                'data-gallery-side-panel-url' => url_for('admin_upload', ['gallery_id' => $gallery['id'], 'panel' => 1]),
            ],
        ],
    ];
    if (feature_capability_effective_enabled('duplicate_photo_detector')) {
        $actions[] = [
            'label' => t('admin.duplicate_photos.action_label', 'Find duplicate photos'),
            'url' => url_for('admin_duplicate_photos', ['gallery_id' => $gallery['id']]),
            'class' => 'button secondary',
            'attributes' => [
                'data-gallery-side-panel-link' => true,
                'data-admin-side-panel-workflow' => 'duplicate-detector',
                'data-admin-side-panel-kicker' => t('admin.duplicate_photos.kicker', 'Gallery tools'),
                'data-admin-side-panel-title' => t('admin.duplicate_photos.page_title', 'Duplicate Photo Detector'),
                'data-gallery-side-panel-url' => url_for('admin_duplicate_photos', ['gallery_id' => $gallery['id'], 'panel' => 1]),
            ],
        ];
    }

    ob_start();
    render_admin_image_bulk_toolbar($gallery);
    $bulkToolbarHtml = (string) ob_get_clean();

    $filenameFlagHtml = render_admin_feature_flag(
        gallery_shows_filenames($gallery),
        '✓',
        t('admin.gallery_editor.file_names_shown_for_gallery', 'File names are shown for this gallery')
    );

    $imageRows = [];
    foreach ($images as $image) {
        $imageId = (int) $image['id'];
        $relativePath = (string) $image['relative_path'];
        $imageRows[] = [
            'id' => $imageId,
            'relative_path' => $relativePath,
            'visibility' => (string) $image['visibility'],
            'thumbnail_url' => thumbnail_url($image, 300),
            'move_aria' => t('admin.image_order.move_aria', 'Move {file}', ['file' => $relativePath]),
            'cover_label' => (int) ($gallery['cover_image_id'] ?? 0) === $imageId
                ? t('admin.gallery_editor.title_picture_current', 'Title picture')
                : '',
            'edit_url' => url_for('admin_edit_image', ['id' => $imageId]),
            'panel_url' => url_for('admin_edit_image', ['id' => $imageId, 'panel' => 1]),
        ];
    }

    view_render_admin_gallery_images_tab([
        'active' => $activeEditTab === 'admin-edit-images',
        'gallery_id' => (int) $gallery['id'],
        'csrf_html' => csrf_field(),
        'bulk_action_url' => url_for('admin_bulk_images'),
        'reorder_url' => url_for('admin_reorder_images'),
        'bulk_toolbar_html' => $bulkToolbarHtml,
        'filename_flag_html' => $filenameFlagHtml,
        'scan_action' => [
            'url' => url_for('admin_scan_images'),
            'label' => t('admin.gallery_editor.scan_import_images', 'Scan/import images'),
        ],
        'intro' => [
            'kicker' => t('admin.gallery_editor.tab_images', 'Images'),
            'title' => t('admin.gallery_editor.images_title', 'Photos and ordering'),
            'actions' => $actions,
        ],
        'images' => $imageRows,
        'labels' => [
            'drag_help' => t('admin.gallery_editor.drag_photos_help', 'Drag photos by the handle to change their gallery order, or click the Name column header to sort the gallery by filename. Each change is saved immediately.'),
            'order_unchanged' => t('admin.gallery_editor.order_unchanged', 'Order unchanged.'),
            'move' => t('admin.gallery_editor.move', 'Move'),
            'select' => t('admin.gallery_editor.select', 'Select'),
            'preview' => t('admin.gallery_editor.preview', 'Preview'),
            'sort_a_z' => t('admin.gallery_editor.sort_photos_a_z', 'Sort photos by name from A to Z'),
            'name' => t('admin.gallery_editor.name', 'Name'),
            'file_names_shown' => t('admin.gallery_editor.file_names_shown', 'File names shown'),
            'status' => t('admin.gallery_editor.status', 'Status'),
            'cover' => t('admin.gallery_editor.cover', 'Cover'),
            'actions' => t('admin.gallery_editor.actions', 'Actions'),
            'drag_title' => t('admin.image_order.drag_title', 'Drag to reorder'),
            'photo_editor' => t('admin.gallery_editor.photo_editor', 'Photo editor'),
            'edit_photo' => t('admin.gallery_editor.edit_photo', 'Edit photo'),
            'edit' => t('admin.gallery_editor.edit', 'Edit'),
            'delete' => t('admin.gallery_editor.delete', 'Delete'),
        ],
    ]);
}
