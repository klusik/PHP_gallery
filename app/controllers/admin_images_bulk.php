<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_images_bulk.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Handles bulk image operations from the gallery editor.
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
 *   2026-09-02
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use RuntimeException;
use Throwable;
use function Gallery\Core\db;
use function Gallery\Core\flash_message;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\now_sql;
use function Gallery\Core\redirect_to;
use function Gallery\Core\require_admin;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\create_image_thumbnails;
use function Gallery\Services\delete_gallery_images;
use function Gallery\Services\delete_gallery_subtrees;
use function Gallery\Services\find_gallery;
use function Gallery\Services\find_image;
use function Gallery\Services\gallery_count_badge_storage_value;
use function Gallery\Services\gallery_shows_filenames;
use function Gallery\Services\gallery_visibility_storage_value;
use function Gallery\Services\move_gallery_images;
use function Gallery\Services\nsfw_guard_schema_ready;
use function Gallery\Services\nsfw_guard_schema_status;
use function Gallery\Services\t;
use function Gallery\Services\thumbnail_maintenance_summary_cache_clear;
use function Gallery\Services\admin_log_event;

/**
 * Handles cms admin bulk images logic for the gallery application.
 */
function cms_admin_bulk_images(): void
{
    require_admin();
    verify_csrf();
    // Variable $galleryId stores this steps working value.
    $galleryId = (int) ($_POST['gallery_id'] ?? 0);
    // $returnTab stores the tab fragment used after bulk image changes.
    $returnTab = admin_return_tab_from_post('admin-edit-images');
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        cms_not_found();
        return;
    }
    // Variable $imageIds stores this steps working value.
    // Variable $submittedImageIds stores checked image ids from the bulk table.
    $submittedImageIds = array_map('intval', $_POST['image_ids'] ?? []);
    // Variable $action stores this steps working value.
    $action = (string) ($_POST['action'] ?? '');
    if ($action === '') {
        if (admin_wants_json()) {
            admin_panel_error_response('Choose a photo action first.');
            return;
        }
        redirect_to(admin_edit_gallery_tab_url($galleryId, $returnTab));
    }
    // Variable $singleDeleteImageId stores the row-level delete button value, when used.
    $singleDeleteImageId = 0;
    if (preg_match('/^delete:(\d+)$/', $action, $deleteMatch) === 1) {
        $singleDeleteImageId = (int) $deleteMatch[1];
        $action = 'delete';
    }
    // Variable $imageIds stores the selected images for this operation.
    $imageIds = $singleDeleteImageId > 0 ? [$singleDeleteImageId] : $submittedImageIds;
    // Variable $count stores this steps working value.
    $count = 0;
    if (!$imageIds) {
        if (admin_wants_json()) {
            admin_panel_error_response('Select at least one photo first.');
            return;
        }
        redirect_to(admin_edit_gallery_tab_url($galleryId, $returnTab));
    }
    // Variable $ownedIds stores this steps working value.
    $ownedIds = [];
    foreach ($imageIds as $imageId) {
        // Variable $image stores this steps working value.
        $image = find_image($imageId);
        if ($image && (int) $image['gallery_id'] === $galleryId) {
            $ownedIds[] = $imageId;
        }
    }
    if (!$ownedIds) {
        if (admin_wants_json()) {
            admin_panel_error_response(t('admin.gallery_editor.selected_photo_unavailable'));
            return;
        }
        redirect_to(admin_edit_gallery_tab_url($galleryId, $returnTab));
    }
    if ($action === 'move_existing' || $action === 'move_new') {
        // $createdGalleryId stores a newly-created destination so it can be removed again when validation fails before any move.
        $createdGalleryId = 0;
        // $moveAttempted stores whether filesystem movement has already been delegated to the service.
        $moveAttempted = false;
        try {
            // $destinationGalleryId stores the target gallery chosen directly or created from selected images.
            $destinationGalleryId = 0;
            if ($action === 'move_existing') {
                $destinationGalleryId = (int) ($_POST['destination_gallery_id'] ?? 0);
                if ($destinationGalleryId <= 0 || !find_gallery($destinationGalleryId)) {
                    throw new RuntimeException('Choose an existing destination gallery.');
                }
            } else {
                // $newGalleryTitle stores the title for the gallery created from selected photos.
                $newGalleryTitle = trim((string) ($_POST['new_gallery_title'] ?? ''));
                if ($newGalleryTitle === '') {
                    throw new RuntimeException('Enter a title for the new gallery.');
                }
                // $newGalleryParentId stores the selected parent for the new destination gallery.
                $newGalleryParentId = array_key_exists('new_gallery_parent_id', $_POST) ? (int) ($_POST['new_gallery_parent_id'] ?? 0) : $galleryId;
                // $newGalleryParent stores the validated parent row for hierarchy and setting inheritance.
                $newGalleryParent = $newGalleryParentId > 0 ? find_gallery($newGalleryParentId) : null;
                if ($newGalleryParentId > 0 && !$newGalleryParent) {
                    throw new RuntimeException('Choose a valid parent gallery for the new gallery.');
                }
                // $newGalleryTemplateGallery stores the gallery whose default settings seed the new destination.
                $newGalleryTemplateGallery = is_array($newGalleryParent) ? $newGalleryParent : $gallery;
                // $newGallerySortOrder stores the next position among the selected parent's children.
                $newGallerySortStmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM galleries WHERE parent_id = ?');
                $newGallerySortStmt->execute([$newGalleryParentId]);
                $newGallerySortOrder = (int) $newGallerySortStmt->fetchColumn();
                // $newGallery stores the newly created destination gallery under the selected parent.
                $newGallery = admin_create_gallery_from_input([
                    'title' => $newGalleryTitle,
                    'folder_name' => trim((string) ($_POST['new_gallery_folder_name'] ?? '')),
                    'description' => '',
                    'visibility' => gallery_visibility_storage_value((string) ($newGalleryTemplateGallery['visibility'] ?? 'unpublished')),
                    'parent_id' => $newGalleryParentId,
                    'sort_order' => $newGallerySortOrder,
                    'voting_enabled' => (int) ($newGalleryTemplateGallery['voting_enabled'] ?? 0) === 1,
                    'show_filenames' => gallery_shows_filenames($newGalleryTemplateGallery),
                    'count_badge_visibility' => gallery_count_badge_storage_value($newGalleryTemplateGallery['count_badge_visibility'] ?? null) ?? 'inherit',
                ]);
                $destinationGalleryId = (int) $newGallery['id'];
                $createdGalleryId = $destinationGalleryId;
            }

            // $moved stores filesystem and database movement details.
            $moveAttempted = true;
            $moved = move_gallery_images($galleryId, $destinationGalleryId, $ownedIds);
            if (!empty($moved['failures'])) {
                if ($createdGalleryId > 0) {
                    delete_gallery_subtrees([$createdGalleryId]);
                }
                admin_log_event('error', 'image.bulk_move_failed', 'Admin image move validation failed.', [
                    'source_gallery_id' => $galleryId,
                    'destination_gallery_id' => $destinationGalleryId,
                    'image_ids' => $ownedIds,
                    'failures' => $moved['failures'],
                ], ['category' => 'other', 'severity' => 'error']);
                $moveFailureNotice = 'Image move failed: ' . implode(' ', array_slice($moved['failures'], 0, 5));
                if (admin_wants_json()) {
                    admin_panel_error_response($moveFailureNotice);
                    return;
                }
                flash_message('admin_notice', $moveFailureNotice);
                redirect_to(admin_edit_gallery_tab_url($galleryId, $returnTab));
            }
            admin_log_event('info', 'image.bulk_moved', 'Admin moved selected images between galleries.', [
                'source_gallery_id' => $galleryId,
                'destination_gallery_id' => $destinationGalleryId,
                'requested' => (int) $moved['requested'],
                'moved' => (int) $moved['moved'],
                'originals_moved' => (int) $moved['originals_moved'],
                'derivatives_moved' => (int) $moved['derivatives_moved'],
                'created_gallery' => $action === 'move_new',
                'source_cover_image_id' => $moved['source_cover_image_id'] ?? null,
                'destination_cover_image_id' => $moved['destination_cover_image_id'] ?? null,
            ], ['category' => 'other', 'severity' => 'info']);
            $notice = 'Moved ' . (int) $moved['moved'] . ' image(s), including ' . (int) $moved['originals_moved'] . ' original file(s) and ' . (int) $moved['derivatives_moved'] . ' derivative file(s).';
            if (admin_wants_json()) {
                $updated = find_gallery($galleryId, true) ?: find_gallery($galleryId) ?: $gallery;
                $destinationGallery = find_gallery($destinationGalleryId, true) ?: find_gallery($destinationGalleryId) ?: null;
                $response = admin_bulk_images_success_response($updated, $notice, $returnTab, $action, $ownedIds, [
                    'destination_gallery' => $destinationGallery,
                ]);
                $response['source_gallery_id'] = $galleryId;
                $response['source_gallery_url'] = gallery_public_url($updated);
                $response['refresh_url'] = gallery_public_url($updated);
                $response['refresh_gallery_id'] = $galleryId;
                $response['destination_gallery_id'] = $destinationGalleryId;
                $response['created_gallery_id'] = $action === 'move_new' ? $destinationGalleryId : 0;
                if (is_array($destinationGallery)) {
                    // $destinationParentId stores where a newly-created gallery belongs in the public hierarchy.
                    $destinationParentId = (int) ($destinationGallery['parent_id'] ?? 0);
                    // $destinationParent stores the parent row so the client does not infer placement from the destination URL.
                    $destinationParent = $destinationParentId > 0 ? find_gallery($destinationParentId, true) : null;
                    $response['destination_gallery_url'] = gallery_public_url($destinationGallery);
                    $response['destination_parent_gallery_id'] = $destinationParentId;
                    $response['destination_parent_gallery_url'] = is_array($destinationParent) ? gallery_public_url($destinationParent) : '';
                }
                header('Content-Type: application/json');
                echo json_encode($response);
                return;
            }
            flash_message('admin_notice', $notice);
        } catch (Throwable $exception) {
            if ($createdGalleryId > 0) {
                try {
                    // $createdGalleryImageCount keeps a successfully populated new gallery from being deleted after a late non-critical failure.
                    $createdGalleryImageCountStmt = db()->prepare('SELECT COUNT(*) FROM images WHERE gallery_id = ?');
                    $createdGalleryImageCountStmt->execute([$createdGalleryId]);
                    $createdGalleryImageCount = (int) $createdGalleryImageCountStmt->fetchColumn();
                } catch (Throwable) {
                    $createdGalleryImageCount = $moveAttempted ? 1 : 0;
                }
                if (!$moveAttempted || $createdGalleryImageCount === 0) {
                    try {
                        delete_gallery_subtrees([$createdGalleryId]);
                    } catch (Throwable) {
                    }
                }
            }
            admin_log_event('error', 'image.bulk_move_failed', 'Admin image move failed.', [
                'source_gallery_id' => $galleryId,
                'image_ids' => $ownedIds,
                'action' => $action,
                'error' => $exception->getMessage(),
            ], ['category' => 'other', 'severity' => 'error']);
            $moveFailureNotice = 'Image move failed: ' . $exception->getMessage();
            if (admin_wants_json()) {
                admin_panel_error_response($moveFailureNotice);
                return;
            }
            flash_message('admin_notice', $moveFailureNotice);
        }
        redirect_to(admin_edit_gallery_tab_url($galleryId, $returnTab));
    }
    if ($action === 'delete') {
        try {
            // Variable $deleted stores the filesystem and database deletion result.
            $deleted = delete_gallery_images($galleryId, $ownedIds);
            // $updated stores the gallery row after deletion so panel JSON reflects the current cover and metadata.
            $updated = find_gallery($galleryId, true) ?: find_gallery($galleryId) ?: $gallery;
            admin_log_event('warning', 'image.bulk_deleted', 'Admin deleted selected gallery images.', [
                'gallery_id' => $galleryId,
                'requested' => (int) $deleted['requested'],
                'deleted' => (int) $deleted['deleted'],
                'files_deleted' => (int) $deleted['files_deleted'],
                'derivatives_deleted' => (int) $deleted['derivatives_deleted'],
                'missing_files' => (int) $deleted['missing_files'],
                'cleanup_failed' => (int) ($deleted['cleanup_failed'] ?? 0),
            ], ['category' => 'other', 'severity' => 'warning']);
            $notice = 'Deleted ' . (int) $deleted['deleted'] . ' image(s), removed ' . (int) $deleted['files_deleted'] . ' original file(s), and cleaned ' . (int) $deleted['derivatives_deleted'] . ' derivative file(s).';
            if ((int) ($deleted['cleanup_failed'] ?? 0) > 0) {
                $notice .= ' ' . (int) $deleted['cleanup_failed'] . ' quarantined deletion file(s) could not be physically removed, but they are no longer live media paths.';
            }
            if (admin_wants_json()) {
                header('Content-Type: application/json');
                echo json_encode(admin_bulk_images_success_response($updated, $notice, $returnTab, 'delete', $ownedIds));
                return;
            }
            flash_message('admin_notice', $notice);
        } catch (Throwable $exception) {
            admin_log_event('error', 'image.bulk_delete_failed', 'Admin image delete failed.', [
                'gallery_id' => $galleryId,
                'image_ids' => $ownedIds,
                'error' => $exception->getMessage(),
            ], ['category' => 'other', 'severity' => 'error']);
            $deleteFailureNotice = 'Image delete failed: ' . $exception->getMessage();
            if (admin_wants_json()) {
                admin_panel_error_response($deleteFailureNotice);
                return;
            }
            flash_message('admin_notice', $deleteFailureNotice);
        }
        redirect_to(admin_edit_gallery_tab_url($galleryId, $returnTab));
    }
    if ($action === 'cover') {
        admin_save_gallery_title_picture($gallery, $ownedIds, $returnTab);
        return;
    }
    if (in_array($action, ['draft', 'public', 'private'], true)) {
        // Variable $placeholders stores this steps working value.
        $placeholders = implode(',', array_fill(0, count($ownedIds), '?'));
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('UPDATE images SET visibility = ?, updated_at = ? WHERE id IN (' . $placeholders . ')');
        $stmt->execute(array_merge([$action, now_sql()], $ownedIds));
        if (admin_wants_json()) {
            $updated = find_gallery($galleryId, true) ?: find_gallery($galleryId) ?: $gallery;
            $notice = 'Updated visibility on ' . count($ownedIds) . ' image(s).';
            header('Content-Type: application/json');
            echo json_encode(admin_bulk_images_success_response($updated, $notice, $returnTab, $action, $ownedIds));
            return;
        }
    }
    if (in_array($action, ['nsfw_on', 'nsfw_off'], true) && !nsfw_guard_schema_ready()) {
        // $nsfwSchemaStatus distinguishes a migration requirement from an inspection outage.
        $nsfwSchemaStatus = nsfw_guard_schema_status();
        $notice = ($nsfwSchemaStatus['state'] ?? '') === 'unknown'
            ? t('admin.gallery_editor.nsfw_change_inspection_failed', 'NSFW Guard was not changed because the required database schema could not be inspected. Check System Health and try again.')
            : t('admin.gallery_editor.nsfw_change_migration_required', 'NSFW Guard was not changed because its database migration has not been applied.');
        if (admin_wants_json()) {
            admin_panel_error_response($notice, 503);
            return;
        }
        flash_message('admin_notice', $notice);
        redirect_to(admin_edit_gallery_tab_url($galleryId, $returnTab));
    }
    if (in_array($action, ['nsfw_on', 'nsfw_off'], true)) {
        // $placeholders stores this steps working value.
        $placeholders = implode(',', array_fill(0, count($ownedIds), '?'));
        // $stmt stores this steps working value.
        $stmt = db()->prepare('UPDATE images SET nsfw_enabled = ?, updated_at = ? WHERE id IN (' . $placeholders . ')');
        $stmt->execute(array_merge([$action === 'nsfw_on' ? 1 : 0, now_sql()], $ownedIds));
        $notice = 'Updated NSFW Guard on ' . count($ownedIds) . ' image(s).';
        if (admin_wants_json()) {
            $updated = find_gallery($galleryId, true) ?: find_gallery($galleryId) ?: $gallery;
            header('Content-Type: application/json');
            echo json_encode(admin_bulk_images_success_response($updated, $notice, $returnTab, $action, $ownedIds));
            return;
        }
        flash_message('admin_notice', $notice);
        redirect_to(admin_edit_gallery_tab_url($galleryId, $returnTab));
    }
    if ($action === 'thumbs') {
        foreach ($ownedIds as $imageId) {
            // Variable $image stores this steps working value.
            $image = find_image($imageId);
            if ($image) {
                $count += create_image_thumbnails($image, $gallery);
            }
        }
        thumbnail_maintenance_summary_cache_clear();
        $notice = t('admin.galleries.thumbnail_result', ['count' => $count]);
        if (admin_wants_json()) {
            $updated = find_gallery($galleryId, true) ?: find_gallery($galleryId) ?: $gallery;
            header('Content-Type: application/json');
            echo json_encode(admin_bulk_images_success_response($updated, $notice, $returnTab, 'thumbs', $ownedIds));
            return;
        }
        flash_message('admin_notice', $notice);
        redirect_to(admin_edit_gallery_tab_url($galleryId, $returnTab));
    }
    if (admin_wants_json()) {
        $updated = find_gallery($galleryId, true) ?: find_gallery($galleryId) ?: $gallery;
        $notice = 'Updated ' . count($ownedIds) . ' image(s).';
        header('Content-Type: application/json');
        echo json_encode(admin_bulk_images_success_response($updated, $notice, $returnTab, $action, $ownedIds));
        return;
    }
    flash_message('admin_notice', 'Updated ' . count($ownedIds) . ' image(s).');
    redirect_to(admin_edit_gallery_tab_url($galleryId, $returnTab));
}
