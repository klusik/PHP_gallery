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
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Core\csrf_field;
use function Gallery\Core\e;
use function Gallery\Core\render_admin_tab_panel;
use function Gallery\Core\url_for;
use function Gallery\Services\gallery_shows_filenames;
use function Gallery\Services\t;
use function Gallery\Services\thumbnail_url;
use function Gallery\Views\view_render_admin_tab_intro;

/**
 * Render the Images tab panel.
 *
 * @param array<string, mixed> $gallery Gallery row being edited.
 * @param array<int, array<string, mixed>> $images Images attached to this gallery.
 * @param string $activeEditTab Currently selected editor tab.
 */
function admin_edit_gallery_render_images_tab(array $gallery, array $images, string $activeEditTab): void
{
    ob_start();
    $scanImagesActionHtml = '<form method="post" action="' . e(url_for('admin_scan_images')) . '" data-admin-panel-scan-images-form>' . csrf_field() . '<input type="hidden" name="gallery_id" value="' . (int) $gallery['id'] . '"><button type="submit" class="secondary">' . e(t('admin.gallery_editor.scan_import_images', 'Scan/import images')) . '</button></form>';
    view_render_admin_tab_intro([
        'kicker' => t('admin.gallery_editor.tab_images', 'Images'),
        'title' => t('admin.gallery_editor.images_title', 'Photos and ordering'),
        'actions' => [
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
            [
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
            ],
        ],
        'actions_html' => $scanImagesActionHtml,
    ]);
    echo '<form method="post" action="' . e(url_for('admin_bulk_images')) . '" data-admin-image-bulk-form>' . csrf_field();
    echo '<input type="hidden" name="gallery_id" value="' . (int) $gallery['id'] . '">';
    echo '<input type="hidden" name="return_tab" value="admin-edit-images">';
    render_admin_image_bulk_toolbar($gallery);
    echo '<div class="admin-image-order-toolbar" data-admin-image-order-toolbar data-reorder-url="' . e(url_for('admin_reorder_images')) . '"><p class="muted">' . e(t('admin.gallery_editor.drag_photos_help', 'Drag photos by the handle to change their gallery order, or click the Name column header to sort the gallery by filename. Each change is saved immediately.')) . '</p><span class="admin-image-order-status" data-admin-image-order-status aria-live="polite">' . e(t('admin.gallery_editor.order_unchanged', 'Order unchanged.')) . '</span></div>';
    echo '<table class="admin-image-order-table" data-admin-image-order-table><thead><tr><th>' . e(t('admin.gallery_editor.move', 'Move')) . '</th><th>' . e(t('admin.gallery_editor.select', 'Select')) . '</th><th>' . e(t('admin.gallery_editor.preview', 'Preview')) . '</th><th aria-sort="none"><button type="button" class="admin-image-name-sort" data-admin-image-name-sort data-sort-direction="asc" aria-label="' . e(t('admin.gallery_editor.sort_photos_a_z', 'Sort photos by name from A to Z')) . '">' . e(t('admin.gallery_editor.name', 'Name')) . ' <span aria-hidden="true">↕</span></button></th><th title="' . e(t('admin.gallery_editor.file_names_shown', 'File names shown')) . '">N</th><th>' . e(t('admin.gallery_editor.status', 'Status')) . '</th><th>' . e(t('admin.gallery_editor.cover', 'Cover')) . '</th><th>' . e(t('admin.gallery_editor.actions', 'Actions')) . '</th></tr></thead><tbody>';
    foreach ($images as $image) {
        // Variable $isCover stores this steps working value.
        $isCover = (int) ($gallery['cover_image_id'] ?? 0) === (int) $image['id'];
        echo '<tr data-admin-image-order-row data-image-id="' . (int) $image['id'] . '" data-image-name="' . e((string) $image['relative_path']) . '"><td class="admin-image-order-cell"><span class="admin-image-drag-handle" data-admin-image-drag-handle role="button" tabindex="0" aria-label="' . e(t('admin.image_order.move_aria', 'Move {file}', ['file' => (string) $image['relative_path']])) . '" title="' . e(t('admin.image_order.drag_title', 'Drag to reorder')) . '">↕</span></td><td><input type="checkbox" name="image_ids[]" value="' . (int) $image['id'] . '"></td>';
        echo '<td><img class="admin-thumb" decoding="async" loading="lazy" src="' . e(thumbnail_url($image, 300)) . '" alt=""></td>';
        echo '<td data-admin-image-name-cell>' . e($image['relative_path']) . '</td><td>' . render_admin_feature_flag(gallery_shows_filenames($gallery), '✓', t('admin.gallery_editor.file_names_shown_for_gallery', 'File names are shown for this gallery')) . '</td><td>' . e($image['visibility']) . '</td><td data-admin-image-cover-cell>' . ($isCover ? t('admin.gallery_editor.title_picture_current', 'Title picture') : '') . '</td><td><a href="' . e(url_for('admin_edit_image', ['id' => $image['id']])) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="image-edit" data-admin-side-panel-kicker="' . e(t('admin.gallery_editor.photo_editor', 'Photo editor')) . '" data-admin-side-panel-title="' . e(t('admin.gallery_editor.edit_photo', 'Edit photo')) . '" data-gallery-side-panel-url="' . e(url_for('admin_edit_image', ['id' => $image['id'], 'panel' => 1])) . '">' . e(t('admin.gallery_editor.edit', 'Edit')) . '</a> <button type="submit" class="secondary danger inline-admin-action" name="action" value="delete:' . (int) $image['id'] . '" data-admin-image-delete-single data-image-id="' . (int) $image['id'] . '" data-image-name="' . e((string) $image['relative_path']) . '">' . e(t('admin.gallery_editor.delete', 'Delete')) . '</button></td></tr>';
    }
    echo '</tbody></table></form>';
    render_admin_tab_panel('admin-edit-images', (string) ob_get_clean(), $activeEditTab === 'admin-edit-images');
}
