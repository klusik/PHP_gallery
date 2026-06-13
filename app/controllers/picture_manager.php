<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/picture_manager.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles logged-in public gallery Picture manager actions.
 *
 * Responsibilities:
 *   - Validate CSRF and request ownership for public-view picture management
 *   - Move selected pictures through the existing gallery image movement service
 *   - Create child galleries from selected pictures by copying originals and metadata
 *   - Return JSON responses for the public gallery JavaScript layer
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
 *   2026-05-19
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use RuntimeException;
use Throwable;
use function Gallery\Core\admin_anonymous_preview_active;
use function Gallery\Core\current_user;
use function Gallery\Core\db;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\copy_gallery_images;
use function Gallery\Services\create_empty_gallery;
use function Gallery\Services\delete_gallery_subtrees;
use function Gallery\Services\find_gallery;
use function Gallery\Services\gallery_count_badge_storage_value;
use function Gallery\Services\gallery_shows_filenames;
use function Gallery\Services\gallery_visibility_storage_value;
use function Gallery\Services\move_gallery_images;
use function Gallery\Services\picture_manager_normalize_image_ids;
use function Gallery\Services\t;

/**
 * Send one JSON response for Picture manager endpoints.
 *
 * @param array<string,mixed> $payload JSON-serializable response payload.
 * @param int $status HTTP status code.
 */
function picture_manager_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Stop a Picture manager action unless a normal logged-in user is active.
 *
 * @return array<string,mixed> Current user row.
 */
function picture_manager_require_logged_in_user(): array
{
    // $user stores the authenticated account allowed to use public-view management.
    $user = current_user();
    if (!$user || admin_anonymous_preview_active()) {
        picture_manager_json_response([
            'ok' => false,
            'message' => t('picture_manager.error_login_required', 'Picture manager is available only to logged-in users.'),
        ], 403);
        exit;
    }
    return $user;
}

/**
 * Read and validate a source gallery ID from the current POST request.
 *
 * @return array<string,mixed> Source gallery row.
 */
function picture_manager_source_gallery_from_post(): array
{
    // $sourceGalleryId stores the public gallery where the selection was made.
    $sourceGalleryId = (int) ($_POST['source_gallery_id'] ?? 0);
    // $sourceGallery stores the source gallery row used for validation and refresh URLs.
    $sourceGallery = $sourceGalleryId > 0 ? find_gallery($sourceGalleryId, true) : null;
    if (!$sourceGallery) {
        throw new RuntimeException('Source gallery was not found.');
    }
    return $sourceGallery;
}

/**
 * Read selected image IDs from the current POST request.
 *
 * @return array<int> Normalized image IDs.
 */
function picture_manager_image_ids_from_post(): array
{
    // $rawImageIds stores the submitted image_ids[] values.
    $rawImageIds = $_POST['image_ids'] ?? [];
    if (!is_array($rawImageIds)) {
        $rawImageIds = [$rawImageIds];
    }
    // $imageIds stores sanitized selected image IDs.
    $imageIds = picture_manager_normalize_image_ids($rawImageIds);
    if (!$imageIds) {
        throw new RuntimeException('Select at least one photo first.');
    }
    return $imageIds;
}

/**
 * Build a clear copy-result message for full and partial copy operations.
 *
 * @param int $copied Number of photos copied into the destination.
 * @param int $skipped Number of selected photos skipped because they already existed there.
 * @param string $successTemplate Translation key used when at least one photo was copied.
 * @param string $successFallback English fallback used when the translation key is absent.
 * @return string Human-readable status message.
 */
function picture_manager_copy_result_message(int $copied, int $skipped, string $successTemplate, string $successFallback): string
{
    if ($copied > 0 && $skipped > 0) {
        return t($successTemplate, $successFallback, ['count' => $copied]) . ' ' . t('picture_manager.skipped_existing_count', 'Skipped {count} already-present photo(s).', ['count' => $skipped]);
    }
    if ($copied > 0) {
        return t($successTemplate, $successFallback, ['count' => $copied]);
    }
    if ($skipped > 0) {
        return t('picture_manager.no_new_photos_copied', 'No new photos copied. {count} selected photo(s) already exist in the destination gallery.', ['count' => $skipped]);
    }
    return t('picture_manager.no_photos_copied', 'No photos were copied.');
}

/**
 * Move selected pictures from the current public gallery into another gallery.
 */
function cms_picture_manager_move(): void
{
    picture_manager_require_logged_in_user();
    verify_csrf();

    try {
        // $sourceGallery stores the gallery currently shown in the public view.
        $sourceGallery = picture_manager_source_gallery_from_post();
        // $sourceGalleryId stores the source gallery database ID.
        $sourceGalleryId = (int) $sourceGallery['id'];
        // $destinationGalleryId stores the gallery selected from the toolbar or drop target.
        $destinationGalleryId = (int) ($_POST['destination_gallery_id'] ?? 0);
        // $destinationGallery stores the receiving gallery row.
        $destinationGallery = $destinationGalleryId > 0 ? find_gallery($destinationGalleryId, true) : null;
        if (!$destinationGallery) {
            throw new RuntimeException('Choose a valid destination gallery.');
        }

        // $imageIds stores selected photo IDs from the public grid.
        $imageIds = picture_manager_image_ids_from_post();
        // $moved stores filesystem and database movement details from the existing service.
        $moved = move_gallery_images($sourceGalleryId, $destinationGalleryId, $imageIds);
        if (!empty($moved['failures'])) {
            picture_manager_json_response([
                'ok' => false,
                'message' => 'Image move failed: ' . implode(' ', array_slice($moved['failures'], 0, 5)),
                'failures' => $moved['failures'],
            ], 422);
            return;
        }

        // $updatedSource stores the source gallery after image ownership changed.
        $updatedSource = find_gallery($sourceGalleryId, true) ?: $sourceGallery;
        // $updatedDestination stores the receiving gallery after image ownership changed.
        $updatedDestination = find_gallery($destinationGalleryId, true) ?: $destinationGallery;
        admin_log_event('info', 'picture_manager.images_moved', 'Picture manager moved selected images from the public gallery view.', [
            'source_gallery_id' => $sourceGalleryId,
            'destination_gallery_id' => $destinationGalleryId,
            'requested' => (int) $moved['requested'],
            'moved' => (int) $moved['moved'],
            'originals_moved' => (int) $moved['originals_moved'],
            'derivatives_moved' => (int) $moved['derivatives_moved'],
        ], ['category' => 'other', 'severity' => 'info']);

        picture_manager_json_response([
            'ok' => true,
            'message' => t('picture_manager.moved_count', 'Moved {count} photo(s).', ['count' => (int) $moved['moved']]),
            'source_gallery_id' => $sourceGalleryId,
            'source_gallery_url' => gallery_public_url($updatedSource),
            'destination_gallery_id' => $destinationGalleryId,
            'destination_gallery_url' => gallery_public_url($updatedDestination),
            'refresh_url' => gallery_public_url($updatedSource),
            'moved_image_ids' => $imageIds,
        ]);
    } catch (Throwable $exception) {
        admin_log_event('error', 'picture_manager.images_move_failed', 'Picture manager image move failed.', [
            'source_gallery_id' => (int) ($_POST['source_gallery_id'] ?? 0),
            'destination_gallery_id' => (int) ($_POST['destination_gallery_id'] ?? 0),
            'error' => $exception->getMessage(),
        ], ['category' => 'other', 'severity' => 'error']);
        picture_manager_json_response([
            'ok' => false,
            'message' => 'Image move failed: ' . $exception->getMessage(),
        ], 422);
    }
}

/**
 * Copy selected pictures from the current public gallery into another existing gallery.
 */
function cms_picture_manager_copy(): void
{
    picture_manager_require_logged_in_user();
    verify_csrf();

    try {
        // $sourceGallery stores the gallery currently shown in the public view.
        $sourceGallery = picture_manager_source_gallery_from_post();
        // $sourceGalleryId stores the source gallery database ID.
        $sourceGalleryId = (int) $sourceGallery['id'];
        // $destinationGalleryId stores the existing gallery selected from the toolbar.
        $destinationGalleryId = (int) ($_POST['destination_gallery_id'] ?? 0);
        // $destinationGallery stores the receiving gallery row.
        $destinationGallery = $destinationGalleryId > 0 ? find_gallery($destinationGalleryId, true) : null;
        if (!$destinationGallery) {
            throw new RuntimeException('Choose a valid destination gallery.');
        }

        // $imageIds stores selected photo IDs from the public grid.
        $imageIds = picture_manager_image_ids_from_post();
        // $copied stores filesystem and database copy details from the shared copy service.
        $copied = copy_gallery_images($sourceGalleryId, $destinationGalleryId, $imageIds);
        if (!empty($copied['failures'])) {
            picture_manager_json_response([
                'ok' => false,
                'message' => 'Image copy failed: ' . implode(' ', array_slice($copied['failures'], 0, 5)),
                'failures' => $copied['failures'],
            ], 422);
            return;
        }

        // $updatedSource stores the source gallery after copy side effects were completed.
        $updatedSource = find_gallery($sourceGalleryId, true) ?: $sourceGallery;
        // $updatedDestination stores the receiving gallery after image rows were inserted.
        $updatedDestination = find_gallery($destinationGalleryId, true) ?: $destinationGallery;
        admin_log_event('info', 'picture_manager.images_copied', 'Picture manager copied selected images from the public gallery view.', [
            'source_gallery_id' => $sourceGalleryId,
            'destination_gallery_id' => $destinationGalleryId,
            'requested' => (int) $copied['requested'],
            'copied' => (int) $copied['copied'],
            'skipped' => (int) ($copied['skipped'] ?? 0),
            'originals_copied' => (int) $copied['originals_copied'],
            'derivatives_copied' => (int) $copied['derivatives_copied'],
        ], ['category' => 'other', 'severity' => 'info']);

        picture_manager_json_response([
            'ok' => true,
            'message' => picture_manager_copy_result_message((int) $copied['copied'], (int) ($copied['skipped'] ?? 0), 'picture_manager.copied_count', 'Copied {count} photo(s).'),
            'source_gallery_id' => $sourceGalleryId,
            'source_gallery_url' => gallery_public_url($updatedSource),
            'destination_gallery_id' => $destinationGalleryId,
            'destination_gallery_url' => gallery_public_url($updatedDestination),
            'refresh_url' => gallery_public_url($updatedSource),
            'skipped' => (int) ($copied['skipped'] ?? 0),
            'skipped_existing' => $copied['skipped_existing'] ?? [],
            'copied_image_ids' => $copied['created_image_ids'],
        ]);
    } catch (Throwable $exception) {
        admin_log_event('error', 'picture_manager.images_copy_failed', 'Picture manager image copy failed.', [
            'source_gallery_id' => (int) ($_POST['source_gallery_id'] ?? 0),
            'destination_gallery_id' => (int) ($_POST['destination_gallery_id'] ?? 0),
            'error' => $exception->getMessage(),
        ], ['category' => 'other', 'severity' => 'error']);
        picture_manager_json_response([
            'ok' => false,
            'message' => 'Image copy failed: ' . $exception->getMessage(),
        ], 422);
    }
}

/**
 * Create a child gallery from selected pictures by copying source files and image metadata.
 */
function cms_picture_manager_create_gallery(): void
{
    picture_manager_require_logged_in_user();
    verify_csrf();

    // $createdGalleryId stores a new gallery so it can be removed if copying fails before it receives images.
    $createdGalleryId = 0;
    try {
        // $sourceGallery stores the gallery currently shown in the public view.
        $sourceGallery = picture_manager_source_gallery_from_post();
        // $sourceGalleryId stores the source gallery database ID.
        $sourceGalleryId = (int) $sourceGallery['id'];
        // $imageIds stores selected photo IDs from the public grid.
        $imageIds = picture_manager_image_ids_from_post();
        // $title stores the requested child gallery title.
        $title = trim((string) ($_POST['new_gallery_title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Enter a title for the new gallery.');
        }

        // $createdGallery stores the new child gallery under the current public gallery.
        $createdGallery = create_empty_gallery([
            'title' => $title,
            'folder_name' => trim((string) ($_POST['new_gallery_folder_name'] ?? '')),
            'description' => '',
            'visibility' => gallery_visibility_storage_value((string) ($sourceGallery['visibility'] ?? 'unpublished')),
            'parent_id' => $sourceGalleryId,
            'voting_enabled' => (int) ($sourceGallery['voting_enabled'] ?? 0) === 1,
            'show_filenames' => gallery_shows_filenames($sourceGallery),
            'count_badge_visibility' => gallery_count_badge_storage_value((string) ($sourceGallery['count_badge_visibility'] ?? 'inherit')) ?? 'inherit',
        ]);
        $createdGalleryId = (int) $createdGallery['id'];

        // $copied stores filesystem and database copy details.
        $copied = copy_gallery_images($sourceGalleryId, $createdGalleryId, $imageIds);
        if (!empty($copied['failures'])) {
            delete_gallery_subtrees([$createdGalleryId]);
            picture_manager_json_response([
                'ok' => false,
                'message' => 'Image copy failed: ' . implode(' ', array_slice($copied['failures'], 0, 5)),
                'failures' => $copied['failures'],
            ], 422);
            return;
        }

        // $updatedSource stores the source gallery after the child gallery was created.
        $updatedSource = find_gallery($sourceGalleryId, true) ?: $sourceGallery;
        // $updatedCreatedGallery stores the child gallery after image copies were inserted.
        $updatedCreatedGallery = find_gallery($createdGalleryId, true) ?: $createdGallery;
        admin_log_event('info', 'picture_manager.gallery_created_from_selection', 'Picture manager created a child gallery from selected public-view images.', [
            'source_gallery_id' => $sourceGalleryId,
            'created_gallery_id' => $createdGalleryId,
            'requested' => (int) $copied['requested'],
            'copied' => (int) $copied['copied'],
            'skipped' => (int) ($copied['skipped'] ?? 0),
            'originals_copied' => (int) $copied['originals_copied'],
            'derivatives_copied' => (int) $copied['derivatives_copied'],
        ], ['category' => 'other', 'severity' => 'info']);

        picture_manager_json_response([
            'ok' => true,
            'message' => picture_manager_copy_result_message((int) $copied['copied'], (int) ($copied['skipped'] ?? 0), 'picture_manager.created_gallery_count', 'Created gallery and copied {count} photo(s).'),
            'source_gallery_id' => $sourceGalleryId,
            'source_gallery_url' => gallery_public_url($updatedSource),
            'created_gallery_id' => $createdGalleryId,
            'created_gallery_url' => gallery_public_url($updatedCreatedGallery),
            'created_gallery_title' => (string) ($updatedCreatedGallery['title'] ?? $title),
            'refresh_url' => gallery_public_url($updatedSource),
            'skipped' => (int) ($copied['skipped'] ?? 0),
            'skipped_existing' => $copied['skipped_existing'] ?? [],
            'copied_image_ids' => $copied['created_image_ids'],
        ]);
    } catch (Throwable $exception) {
        if ($createdGalleryId > 0) {
            try {
                // $createdGalleryImageCount keeps a successfully populated gallery from being deleted after a late reporting failure.
                $createdGalleryImageCountStmt = db()->prepare('SELECT COUNT(*) FROM images WHERE gallery_id = ?');
                $createdGalleryImageCountStmt->execute([$createdGalleryId]);
                if ((int) $createdGalleryImageCountStmt->fetchColumn() === 0) {
                    delete_gallery_subtrees([$createdGalleryId]);
                }
            } catch (Throwable) {
            }
        }
        admin_log_event('error', 'picture_manager.gallery_create_failed', 'Picture manager create-gallery-from-selection failed.', [
            'source_gallery_id' => (int) ($_POST['source_gallery_id'] ?? 0),
            'created_gallery_id' => $createdGalleryId,
            'error' => $exception->getMessage(),
        ], ['category' => 'other', 'severity' => 'error']);
        picture_manager_json_response([
            'ok' => false,
            'message' => 'Create gallery failed: ' . $exception->getMessage(),
        ], 422);
    }
}
