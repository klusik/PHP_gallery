<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_edit_page/overview.php
 * Module Type: Controller
 *
 * Purpose:
 *   Renders the gallery editor notices, hero, summary metrics, and tab strip.
 *
 * Responsibilities:
 *   - Surface saved, uploaded, moved, and created result notices once
 *   - Show the gallery hero with its workflow shortcuts and summary metrics
 *   - Build the tab strip that the tab panels below attach to
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
 *   - Notices are consumed here so a later render phase cannot repeat them.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Core\e;
use function Gallery\Core\flash_message;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\render_admin_tabs;
use function Gallery\Core\url_for;
use function Gallery\Services\gallery_folder_name_from_path;
use function Gallery\Services\normalize_gallery_visibility;
use function Gallery\Services\t;
use function Gallery\Views\view_render_admin_hero;
use function Gallery\Views\view_render_admin_metric_grid;

/**
 * Render the one-time notices shown above the gallery editor.
 *
 * @param array<string, mixed> $gallery Gallery row being edited.
 * @param array<string, mixed> $capabilities Resolved editor capabilities.
 */
function admin_edit_gallery_render_notices(array $gallery, array $capabilities): void
{
    // $galleryError stores an intermediate value used by the surrounding gallery workflow.
    $galleryError = (string) ($_SESSION['admin_gallery_error_' . (int) $gallery['id']] ?? '');
    unset($_SESSION['admin_gallery_error_' . (int) $gallery['id']]);
    if ($galleryError !== '') {
        echo '<div class="notice">' . e(t('admin.gallery_editor.folder_move_failed', ['error' => $galleryError])) . '</div>';
    }
    // $galleryNotice stores an intermediate value used by the surrounding gallery workflow.
    $galleryNotice = (string) flash_message('admin_notice');
    if ($galleryNotice !== '') {
        echo '<div class="notice">' . e($galleryNotice) . '</div>';
    }
    if (isset($_GET['created'])) {
        echo '<div class="notice">' . e(t('admin.gallery_editor.folder_created')) . '</div>';
    } elseif (isset($_GET['uploaded'])) {
        // $thumbnailFailed stores required derivatives that failed during upload thumbnail generation.
        $thumbnailFailed = (int) ($_GET['thumbnail_failed'] ?? 0);
        // $scanFailed stores files that were stored on disk but not imported into image rows.
        $scanFailed = (int) ($_GET['scan_failed'] ?? 0);
        // $uploadNotice stores the upload result message shown after redirect.
        $uploadNotice = t('admin.gallery_editor.upload_notice', 'Uploaded {uploaded} images, scanned or updated {scanned} image records, and created {thumbnails} thumbnails.', ['uploaded' => (int) $_GET['uploaded'], 'scanned' => (int) ($_GET['scanned'] ?? 0), 'thumbnails' => (int) ($_GET['thumbnails'] ?? 0)]);
        if ($scanFailed > 0) {
            $uploadNotice .= ' ' . t('admin.gallery_editor.upload_scan_warning', 'Warning: {count} uploaded file(s) were stored on disk but could not be imported into image records. Check the admin logs for filenames and decoder diagnostics.', ['count' => $scanFailed]);
        }
        if ($thumbnailFailed > 0) {
            $uploadNotice .= ' ' . t('admin.gallery_editor.upload_thumbnail_warning', 'Warning: {count} thumbnail or DNG display derivative(s) failed. Use Create gallery thumbnails or check the admin logs for details.', ['count' => $thumbnailFailed]);
        }
        echo '<div class="notice">' . e($uploadNotice) . '</div>';
    } elseif (isset($_GET['moved'])) {
        echo '<div class="notice">' . e(t('admin.gallery_editor.folder_moved')) . '</div>';
    } elseif (isset($_GET['saved'])) {
        echo '<div class="notice">' . e(t('admin.gallery_editor.gallery_saved')) . '</div>';
    }
    if (!$capabilities['picture_game_ready'] && $capabilities['picture_game_feature_enabled'] && $capabilities['image_voting_feature_enabled']) {
        render_admin_migration_notice(t('admin.gallery_editor.picture_game_migration_hidden'));
    }
}

/**
 * Render the gallery hero, summary metric grid, and editor tab strip.
 *
 * @param array<string, mixed> $gallery Gallery row being edited.
 * @param int $imageCount Number of images attached to this gallery.
 * @param string $activeEditTab Tab selected by redirect query state.
 * @param array<string, mixed> $capabilities Resolved editor capabilities.
 */
function admin_edit_gallery_render_overview(array $gallery, int $imageCount, string $activeEditTab, array $capabilities): void
{
    // $activeVisibility stores the normalized gallery visibility label for summary cards.
    $activeVisibility = normalize_gallery_visibility((string) ($gallery['visibility'] ?? 'unpublished'));
    // $adminTabs stores the edit-gallery sections shown by the shared admin tab controller.
    $adminTabs = [
        ['id' => 'admin-edit-identity', 'label' => t('admin.gallery_editor.tab_identity')],
        ['id' => 'admin-edit-access', 'label' => t('admin.gallery_editor.tab_access')],
        ['id' => 'admin-edit-display', 'label' => t('admin.gallery_editor.tab_display')],
        ['id' => 'admin-edit-media', 'label' => t('admin.gallery_editor.tab_media')],
        ['id' => 'admin-edit-api', 'label' => t('admin.gallery_editor.tab_api', 'API')],
        ['id' => 'admin-edit-images', 'label' => t('admin.gallery_editor.tab_images'), 'badge' => $imageCount],
        ['id' => 'admin-edit-organizer', 'label' => t('admin.metadata_organizer.tab_label', 'Organizer')],
    ];
    if ($capabilities['media_renamer_feature_enabled']) {
        $adminTabs[] = ['id' => 'admin-edit-renamer', 'label' => t('admin.media_renamer.tab_label', 'File renamer')];
    }

    view_render_admin_hero([
        'class' => 'admin-edit-gallery-hero',
        'kicker' => t('admin.gallery_editor.kicker'),
        'title' => (string) $gallery['title'],
        'description' => t('admin.gallery_editor.intro'),
        'actions_aria_label' => t('admin.gallery_editor.hero_actions_label'),
        'actions' => [
            [
                'label' => t('admin.gallery_editor.upload_photos_here'),
                'url' => url_for('admin_upload', ['gallery_id' => $gallery['id']]),
                'class' => 'button',
                'attributes' => [
                    'data-gallery-side-panel-link' => true,
                    'data-admin-side-panel-workflow' => 'upload',
                    'data-admin-side-panel-kicker' => t('gallery.upload_workflow'),
                    'data-admin-side-panel-title' => t('gallery.upload_photos'),
                    'data-gallery-side-panel-url' => url_for('admin_upload', ['gallery_id' => $gallery['id'], 'panel' => 1]),
                ],
            ],
            [
                'label' => t('admin.gallery_editor.create_gallery_here'),
                'url' => url_for('admin_upload', ['upload_mode' => 'new', 'parent_id' => $gallery['id']]),
                'class' => 'button secondary',
                'attributes' => [
                    'data-gallery-side-panel-link' => true,
                    'data-admin-side-panel-workflow' => 'upload',
                    'data-admin-side-panel-kicker' => t('gallery.workflow'),
                    'data-admin-side-panel-title' => t('gallery.create_here'),
                    'data-gallery-side-panel-url' => url_for('admin_upload', ['upload_mode' => 'new', 'parent_id' => $gallery['id'], 'panel' => 1]),
                ],
            ],
            ['label' => t('admin.gallery_editor.view_gallery'), 'url' => gallery_public_url($gallery), 'class' => 'button secondary', 'target' => '_blank'],
            ['label' => t('admin.gallery_editor.back_to_galleries'), 'url' => url_for('admin'), 'class' => 'button secondary'],
        ],
        'meta' => [
            ['value' => (string) $imageCount, 'label' => t('admin.gallery_editor.metric_images')],
            ['value' => ucfirst($activeVisibility), 'label' => t('admin.gallery_editor.metric_visibility')],
        ],
    ]);

    view_render_admin_metric_grid([
        [
            'label' => t('admin.gallery_editor.metric_visibility'),
            'value' => ucfirst($activeVisibility),
            'help' => t('admin.gallery_editor.metric_visibility_help'),
            'state' => $activeVisibility === 'public' ? 'ready' : 'care',
        ],
        [
            'label' => t('admin.gallery_editor.metric_images'),
            'value' => (string) $imageCount,
            'help' => t('admin.gallery_editor.metric_images_help'),
            'state' => $imageCount > 0 ? 'ready' : 'neutral',
        ],
        [
            'label' => t('admin.gallery_editor.metric_folder'),
            'value' => gallery_folder_name_from_path((string) $gallery['folder_path']),
            'help' => t('admin.gallery_editor.metric_folder_help'),
            'state' => 'neutral',
        ],
        [
            'label' => t('admin.gallery_editor.metric_parent'),
            'value' => ((int) ($gallery['parent_id'] ?? 0) > 0 ? '#' . (int) $gallery['parent_id'] : t('admin.gallery_editor.root_parent')),
            'help' => t('admin.gallery_editor.metric_parent_help'),
            'state' => 'neutral',
        ],
    ], 'admin-metric-grid admin-edit-gallery-summary', t('admin.gallery_editor.summary_aria', 'Gallery summary'));

    render_admin_tabs($adminTabs, $activeEditTab);
}
