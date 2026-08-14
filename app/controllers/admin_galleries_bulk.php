<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_bulk.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Handles admin bulk gallery mutations and public-path regeneration.
 *
 * Responsibilities:
 *   - Keep behavior compatible with the previous combined implementation
 *   - Expose focused functions for one admin or thumbnail responsibility
 *   - Avoid coupling unrelated workflows into one large source file
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
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-12
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use Throwable;
use function Gallery\Core\db;
use function Gallery\Core\flash_message;
use function Gallery\Core\now_sql;
use function Gallery\Core\redirect_to;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\create_gallery_thumbnails;
use function Gallery\Services\delete_gallery_subtrees;
use function Gallery\Services\exif_gps_override_schema_ready;
use function Gallery\Services\exif_gps_schema_ready;
use function Gallery\Services\find_gallery;
use function Gallery\Services\gallery_filename_display_schema_ready;
use function Gallery\Services\gallery_subtree_ids;
use function Gallery\Services\gallery_visibility_storage_value;
use function Gallery\Services\gallery_visibility_values;
use function Gallery\Services\gallery_voting_schema_ready;
use function Gallery\Services\presentation_picture_game_schema_status;
use function Gallery\Services\presentation_schema_log_degraded;
use function Gallery\Services\schema_inspection_is_available;
use function Gallery\Services\schema_inspection_is_unknown;
use function Gallery\Services\regenerate_public_paths;
use function Gallery\Services\scan_gallery_images;
use function Gallery\Services\set_collapsed_gallery_ids;
use function Gallery\Services\t;
use function Gallery\Services\write_gallery_sidecar;
use function Gallery\Services\admin_log_event;

/**
 * Handles cms admin bulk galleries logic for the gallery application.
 */
function cms_admin_bulk_galleries(): void
{
    require_admin();
    verify_csrf();
    // Variable $galleryIds stores this steps working value.
    $galleryIds = array_map('intval', $_POST['gallery_ids'] ?? []);
    // Variable $action stores this steps working value.
    $action = (string) ($_POST['action'] ?? 'scan');
    // Variable $count stores this steps working value.
    $count = 0;
    if ($action === 'scan') {
        foreach ($galleryIds as $galleryId) {
            $count += scan_gallery_images($galleryId);
        }
        flash_message('admin_notice', t('admin.galleries.scan_result', ['count' => $count]));
        redirect_to(url_for('admin'));
    }
    if ($action === 'thumbs') {
        foreach ($galleryIds as $galleryId) {
            $count += create_gallery_thumbnails($galleryId);
        }
        flash_message('admin_notice', t('admin.galleries.thumbnail_result', ['count' => $count]));
        redirect_to(url_for('admin'));
    }
    if ($action === 'delete' && $galleryIds) {
        try {
            // $deleted stores an intermediate value used by the surrounding gallery workflow.
            $deleted = delete_gallery_subtrees($galleryIds);
            admin_log_event('warning', 'gallery.bulk_deleted', t('admin.galleries.log_bulk_deleted'), [
                'gallery_ids' => $galleryIds,
                'deleted_roots' => (int) $deleted['root_count'],
                'deleted_rows' => (int) $deleted['row_count'],
            ]);
            flash_message('admin_notice', t('admin.galleries.deleted_result', 'Deleted {count} gallery folder(s).', ['count' => (int) $deleted['root_count']]));
            redirect_to(url_for('admin'));
        } catch (Throwable $exception) {
            admin_log_event('error', 'gallery.bulk_delete_failed', t('admin.galleries.log_bulk_delete_failed'), [
                'gallery_ids' => $galleryIds,
                'exception' => $exception->getMessage(),
            ]);
            flash_message('admin_notice', t('admin.galleries.delete_failed', 'Gallery delete failed: {error}', ['error' => $exception->getMessage()]));
            redirect_to(url_for('admin'));
        }
    }
    if (in_array($action, gallery_visibility_values(), true) && $galleryIds) {
        // Variable $placeholders stores this steps working value.
        $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('UPDATE galleries SET visibility = ?, updated_at = ? WHERE id IN (' . $placeholders . ')');
        $stmt->execute(array_merge([gallery_visibility_storage_value($action), now_sql()], $galleryIds));
        foreach ($galleryIds as $galleryId) {
            // Variable $gallery stores this steps working value.
            $gallery = find_gallery($galleryId);
            if ($gallery) {
                write_gallery_sidecar($gallery);
            }
        }
        flash_message('admin_notice', 'Updated ' . count($galleryIds) . ' gallery folder(s).');
        redirect_to(url_for('admin'));
    }
    if (in_array($action, ['maps_on', 'maps_off', 'maps_inherit'], true) && $galleryIds) {
        if (!exif_gps_schema_ready() || ($action === 'maps_inherit' && !exif_gps_override_schema_ready())) {
            admin_log_event('warning', 'gps_maps.schema_missing', t('admin.galleries.log_gps_maps_schema_missing'), [
                'gallery_ids' => $galleryIds,
                'action' => $action,
            ]);
            flash_message('admin_notice', t('admin.galleries.gps_requires_migration'));
            redirect_to(url_for('admin'));
        }
        // Variable $expandedIds stores this steps working value.
        $expandedIds = [];
        foreach ($galleryIds as $galleryId) {
            // $expandedIds stores an intermediate value used by the surrounding gallery workflow.
            $expandedIds = array_merge($expandedIds, gallery_subtree_ids($galleryId));
        }
        // $expandedIds stores an intermediate value used by the surrounding gallery workflow.
        $expandedIds = array_values(array_unique(array_filter($expandedIds)));
        if ($expandedIds) {
            // Variable $placeholders stores this steps working value.
            $placeholders = implode(',', array_fill(0, count($expandedIds), '?'));
            // $gpsMapValue stores the explicit or inherited GPS display value for the selected gallery branches.
            $gpsMapValue = $action === 'maps_inherit' ? null : ($action === 'maps_on' ? 1 : 0);
            // Variable $stmt stores this steps working value.
            $stmt = db()->prepare('UPDATE galleries SET gps_map_enabled = ?, updated_at = ? WHERE id IN (' . $placeholders . ')');
            $stmt->execute(array_merge([$gpsMapValue, now_sql()], $expandedIds));
            foreach ($expandedIds as $expandedId) {
                // Variable $gallery stores this steps working value.
                $gallery = find_gallery((int) $expandedId, true);
                if ($gallery) {
                    write_gallery_sidecar($gallery);
                }
            }
        }
        flash_message('admin_notice', 'Updated ' . count($expandedIds) . ' gallery folder(s).');
        redirect_to(url_for('admin'));
    }
    if (in_array($action, ['vote_on', 'vote_off'], true) && $galleryIds) {
        if (!gallery_voting_schema_ready()) {
            admin_log_event('warning', 'votes.schema_missing', t('admin.galleries.log_voting_schema_missing'), [
                'gallery_ids' => $galleryIds,
                'action' => $action,
            ]);
            flash_message('admin_notice', t('admin.galleries.voting_requires_migration'));
            redirect_to(url_for('admin'));
        }
        // Variable $expandedIds stores this steps working value.
        $expandedIds = [];
        foreach ($galleryIds as $galleryId) {
            // $expandedIds stores an intermediate value used by the surrounding gallery workflow.
            $expandedIds = array_merge($expandedIds, gallery_subtree_ids($galleryId));
        }
        // $expandedIds stores an intermediate value used by the surrounding gallery workflow.
        $expandedIds = array_values(array_unique(array_filter($expandedIds)));
        if ($expandedIds) {
            // Variable $placeholders stores this steps working value.
            $placeholders = implode(',', array_fill(0, count($expandedIds), '?'));
            // Variable $stmt stores this steps working value.
            $stmt = db()->prepare('UPDATE galleries SET voting_enabled = ?, updated_at = ? WHERE id IN (' . $placeholders . ')');
            $stmt->execute(array_merge([$action === 'vote_on' ? 1 : 0, now_sql()], $expandedIds));
            if ($action === 'vote_off') {
                // $stmt stores an intermediate value used by the surrounding gallery workflow.
                $stmt = db()->prepare('UPDATE galleries SET picture_game_enabled = 0, updated_at = ? WHERE id IN (' . $placeholders . ')');
                $stmt->execute(array_merge([now_sql()], $expandedIds));
            }
        }
        flash_message('admin_notice', 'Updated ' . count($expandedIds) . ' gallery folder(s).');
        redirect_to(url_for('admin'));
    }
    if (in_array($action, ['filenames_on', 'filenames_off'], true) && $galleryIds) {
        if (!gallery_filename_display_schema_ready()) {
            admin_log_event('warning', 'gallery_filenames.schema_missing', t('admin.galleries.log_filename_schema_missing'), [
                'gallery_ids' => $galleryIds,
                'action' => $action,
            ]);
            flash_message('admin_notice', t('admin.galleries.filename_requires_migration'));
            redirect_to(url_for('admin'));
        }
        // Variable $expandedIds stores this steps working value.
        $expandedIds = [];
        foreach ($galleryIds as $galleryId) {
            // $expandedIds stores an intermediate value used by the surrounding gallery workflow.
            $expandedIds = array_merge($expandedIds, gallery_subtree_ids($galleryId));
        }
        // $expandedIds stores an intermediate value used by the surrounding gallery workflow.
        $expandedIds = array_values(array_unique(array_filter($expandedIds)));
        if ($expandedIds) {
            // Variable $placeholders stores this steps working value.
            $placeholders = implode(',', array_fill(0, count($expandedIds), '?'));
            // Variable $stmt stores this steps working value.
            $stmt = db()->prepare('UPDATE galleries SET show_filenames = ?, updated_at = ? WHERE id IN (' . $placeholders . ')');
            $stmt->execute(array_merge([$action === 'filenames_on' ? 1 : 0, now_sql()], $expandedIds));
            foreach ($expandedIds as $expandedId) {
                // Variable $gallery stores this steps working value.
                $gallery = find_gallery((int) $expandedId);
                if ($gallery) {
                    write_gallery_sidecar($gallery);
                }
            }
        }
        flash_message('admin_notice', 'Updated filename display for ' . count($expandedIds) . ' gallery folder(s).');
        redirect_to(url_for('admin'));
    }
    if (in_array($action, ['game_on', 'game_off'], true) && $galleryIds) {
        $pictureGameStatus = presentation_picture_game_schema_status();
        if (!schema_inspection_is_available($pictureGameStatus)) {
            if (schema_inspection_is_unknown($pictureGameStatus)) {
                presentation_schema_log_degraded($pictureGameStatus, 'admin_bulk_picture_game');
                flash_message('admin_notice', t('admin.galleries.picture_game_schema_unavailable', 'Picture Game database status could not be verified. No galleries were changed. Check System Health and try again.'));
            } else {
                admin_log_event('warning', 'picture_game.schema_missing', t('admin.galleries.log_picture_game_schema_missing'), [
                    'gallery_ids' => $galleryIds,
                    'action' => $action,
                ]);
                flash_message('admin_notice', t('admin.galleries.picture_game_requires_migration'));
            }
            redirect_to(url_for('admin'));
        }
        // Variable $expandedIds stores this steps working value.
        $expandedIds = [];
        foreach ($galleryIds as $galleryId) {
            // $expandedIds stores an intermediate value used by the surrounding gallery workflow.
            $expandedIds = array_merge($expandedIds, gallery_subtree_ids($galleryId));
        }
        // $expandedIds stores an intermediate value used by the surrounding gallery workflow.
        $expandedIds = array_values(array_unique(array_filter($expandedIds)));
        if ($expandedIds) {
            // Variable $placeholders stores this steps working value.
            $placeholders = implode(',', array_fill(0, count($expandedIds), '?'));
            // Variable $stmt stores this steps working value.
            $stmt = db()->prepare('UPDATE galleries SET picture_game_enabled = ?, updated_at = ? WHERE id IN (' . $placeholders . ')');
            $stmt->execute(array_merge([$action === 'game_on' ? 1 : 0, now_sql()], $expandedIds));
            if ($action === 'game_on') {
                // $stmt stores an intermediate value used by the surrounding gallery workflow.
                $stmt = db()->prepare('UPDATE galleries SET voting_enabled = 1, updated_at = ? WHERE id IN (' . $placeholders . ')');
                $stmt->execute(array_merge([now_sql()], $expandedIds));
            }
        }
        flash_message('admin_notice', 'Updated ' . count($expandedIds) . ' gallery folder(s).');
        redirect_to(url_for('admin'));
    }
    redirect_to(url_for('admin'));
}

/**
 * Handles cms admin regenerate paths logic for the gallery application.
 */
function cms_admin_regenerate_paths(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();
    try {
        // $result stores an intermediate value used by the surrounding gallery workflow.
        $result = regenerate_public_paths();
        flash_message('admin_notice', t('admin.galleries.public_paths_regenerated', 'Regenerated clean public paths. Updated {galleries} gallery path(s) and {images} image path(s).', ['galleries' => (int) $result['galleries'], 'images' => (int) $result['images']]));
        redirect_to(url_for('admin'));
    } catch (Throwable $exception) {
        flash_message('admin_notice', t('admin.galleries.public_paths_failed', 'Path regeneration failed: {error}', ['error' => $exception->getMessage()]));
        redirect_to(url_for('admin'));
    }
}

/**
 * Handles cms admin save gallery collapse logic for the gallery application.
 */
function cms_admin_save_gallery_collapse(): void
{
    require_admin();
    verify_csrf();
    // Variable $ids stores this steps working value.
    $ids = json_decode((string) ($_POST['collapsed_ids'] ?? '[]'), true);
    set_collapsed_gallery_ids(is_array($ids) ? $ids : []);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
}
