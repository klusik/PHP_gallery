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
 *   - Move and copy selected pictures through existing gallery image services
 *   - Delete mixed photo and physical-subgallery selections through existing mutation services
 *   - Create physical galleries from mixed selections at a user-selected parent location
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
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use RuntimeException;
use Throwable;
use function Gallery\Core\admin_anonymous_preview_active;
use function Gallery\Core\current_user;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\copy_gallery_images;
use function Gallery\Services\create_empty_gallery;
use function Gallery\Services\delete_gallery_images;
use function Gallery\Services\delete_gallery_subtrees;
use function Gallery\Services\find_gallery;
use function Gallery\Services\gallery_count_badge_storage_value;
use function Gallery\Services\gallery_shows_filenames;
use function Gallery\Services\gallery_trash_available;
use function Gallery\Services\gallery_trash_enabled;
use function Gallery\Services\gallery_trash_schema_status;
use function Gallery\Services\gallery_visibility_storage_value;
use function Gallery\Services\move_gallery_images;
use function Gallery\Services\move_gallery_subtrees_to_trash;
use function Gallery\Services\picture_manager_assert_destination_outside_gallery_selection;
use function Gallery\Services\picture_manager_copy_gallery_subtrees;
use function Gallery\Services\picture_manager_normalize_gallery_ids;
use function Gallery\Services\picture_manager_normalize_image_ids;
use function Gallery\Services\picture_manager_owned_galleries_for_selection;
use function Gallery\Services\picture_manager_owned_images_for_selection;
use function Gallery\Services\t;
use function Gallery\Services\admin_log_event;

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
function picture_manager_image_ids_from_post(bool $required = true): array
{
    // $rawImageIds stores the submitted image_ids[] values.
    $rawImageIds = $_POST['image_ids'] ?? [];
    if (!is_array($rawImageIds)) {
        $rawImageIds = [$rawImageIds];
    }
    // $imageIds stores sanitized selected image IDs.
    $imageIds = picture_manager_normalize_image_ids($rawImageIds);
    if ($required && !$imageIds) {
        throw new RuntimeException('Select at least one photo first.');
    }
    return $imageIds;
}

/**
 * Read selected physical subgallery IDs from the current POST request.
 *
 * @return array<int> Normalized gallery IDs.
 */
function picture_manager_gallery_ids_from_post(): array
{
    // $rawGalleryIds stores submitted gallery_ids[] values from physical subgallery cards.
    $rawGalleryIds = $_POST['gallery_ids'] ?? [];
    if (!is_array($rawGalleryIds)) {
        $rawGalleryIds = [$rawGalleryIds];
    }
    return picture_manager_normalize_gallery_ids($rawGalleryIds);
}

/**
 * Read a mixed Picture manager selection and require at least one physical item.
 *
 * @return array{image_ids:array<int>,gallery_ids:array<int>} Normalized selection.
 */
function picture_manager_selection_from_post(): array
{
    // $imageIds stores direct photos selected in the source gallery.
    $imageIds = picture_manager_image_ids_from_post(false);
    // $galleryIds stores direct physical subgalleries selected in the source gallery.
    $galleryIds = picture_manager_gallery_ids_from_post();
    if (!$imageIds && !$galleryIds) {
        throw new RuntimeException('Select at least one photo or gallery first.');
    }
    return ['image_ids' => $imageIds, 'gallery_ids' => $galleryIds];
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
 * Create a physical gallery from selected photos and physical subgalleries.
 *
 * The caller chooses the parent gallery. Selected photos are copied into the new
 * root and selected subgallery trees are physically cloned below it, preserving
 * file-based source-of-truth semantics rather than creating Smart Gallery rules.
 */
function cms_picture_manager_create_gallery(): void
{
    picture_manager_require_logged_in_user();
    verify_csrf();

    // $createdGalleryId stores the new root so a failed copy can remove the complete partial result.
    $createdGalleryId = 0;
    // $selectionCopied becomes true only after every requested physical copy completed successfully.
    $selectionCopied = false;
    try {
        // $sourceGallery stores the gallery currently shown in the public view.
        $sourceGallery = picture_manager_source_gallery_from_post();
        // $sourceGalleryId stores the source gallery database ID.
        $sourceGalleryId = (int) $sourceGallery['id'];
        // $selection stores normalized photo and direct-subgallery IDs.
        $selection = picture_manager_selection_from_post();
        // $imageIds stores selected direct photos.
        $imageIds = $selection['image_ids'];
        // $galleryIds stores selected direct physical subgalleries.
        $galleryIds = $selection['gallery_ids'];
        // $title stores the requested new gallery title.
        $title = trim((string) ($_POST['new_gallery_title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Enter a title for the new gallery.');
        }

        // $parentGalleryId stores the user-selected location, defaulting to the current gallery for old clients.
        $parentGalleryId = (int) ($_POST['new_gallery_parent_id'] ?? 0);
        if ($parentGalleryId <= 0) {
            $parentGalleryId = $sourceGalleryId;
        }
        // $parentGallery stores the physical gallery whose folder will contain the new root.
        $parentGallery = find_gallery($parentGalleryId, true);
        if (!$parentGallery) {
            throw new RuntimeException('Choose a valid parent gallery for the new gallery.');
        }

        // Validate the entire selection before creating any filesystem content.
        $validationFailures = [];
        if ($imageIds) {
            $validatedImages = picture_manager_owned_images_for_selection($sourceGalleryId, $imageIds, $validationFailures);
            if (count($validatedImages) !== count($imageIds)) {
                throw new RuntimeException(implode(' ', $validationFailures ?: ['One or more selected photos are invalid.']));
            }
        }
        if ($galleryIds) {
            $validatedGalleries = picture_manager_owned_galleries_for_selection($sourceGalleryId, $galleryIds, $validationFailures);
            if (count($validatedGalleries) !== count($galleryIds)) {
                throw new RuntimeException(implode(' ', $validationFailures ?: ['One or more selected galleries are invalid.']));
            }
            picture_manager_assert_destination_outside_gallery_selection($parentGalleryId, $galleryIds);
        }

        // $createdGallery stores the physical destination root at the user-selected location.
        $createdGallery = create_empty_gallery([
            'title' => $title,
            'folder_name' => trim((string) ($_POST['new_gallery_folder_name'] ?? '')),
            'description' => '',
            'visibility' => gallery_visibility_storage_value((string) ($parentGallery['visibility'] ?? 'unpublished')),
            'parent_id' => $parentGalleryId,
            'voting_enabled' => (int) ($parentGallery['voting_enabled'] ?? 0) === 1,
            'show_filenames' => gallery_shows_filenames($parentGallery),
            'count_badge_visibility' => gallery_count_badge_storage_value((string) ($parentGallery['count_badge_visibility'] ?? 'inherit')) ?? 'inherit',
        ]);
        $createdGalleryId = (int) $createdGallery['id'];

        // $copiedImages stores direct-photo copy details. Empty selections need no synthetic DB work.
        $copiedImages = [
            'requested' => 0,
            'copied' => 0,
            'skipped' => 0,
            'originals_copied' => 0,
            'derivatives_copied' => 0,
            'created_image_ids' => [],
            'skipped_existing' => [],
            'failures' => [],
        ];
        if ($imageIds) {
            $copiedImages = copy_gallery_images($sourceGalleryId, $createdGalleryId, $imageIds);
            if (!empty($copiedImages['failures'])) {
                throw new RuntimeException('Image copy failed: ' . implode(' ', array_slice($copiedImages['failures'], 0, 5)));
            }
        }

        // $copiedGalleries stores copied physical subtree details.
        $copiedGalleries = $galleryIds
            ? picture_manager_copy_gallery_subtrees($sourceGalleryId, $createdGalleryId, $galleryIds)
            : ['requested' => 0, 'copied_roots' => 0, 'copied_rows' => 0, 'scanned_images' => 0, 'created_gallery_ids' => []];
        $selectionCopied = true;

        // $updatedSource stores the source gallery after all file operations completed.
        $updatedSource = find_gallery($sourceGalleryId, true) ?: $sourceGallery;
        // $updatedCreatedGallery stores the new gallery after its copied children were indexed.
        $updatedCreatedGallery = find_gallery($createdGalleryId, true) ?: $createdGallery;
        admin_log_event('info', 'picture_manager.gallery_created_from_selection', 'Picture manager created a physical gallery from selected public-view items.', [
            'source_gallery_id' => $sourceGalleryId,
            'parent_gallery_id' => $parentGalleryId,
            'created_gallery_id' => $createdGalleryId,
            'requested_images' => count($imageIds),
            'copied_images' => (int) $copiedImages['copied'],
            'requested_gallery_roots' => count($galleryIds),
            'copied_gallery_roots' => (int) $copiedGalleries['copied_roots'],
            'copied_gallery_rows' => (int) $copiedGalleries['copied_rows'],
        ], ['category' => 'other', 'severity' => 'info']);

        picture_manager_json_response([
            'ok' => true,
            'message' => t('picture_manager.created_mixed_gallery', 'Created gallery with {photos} photo(s) and {galleries} physical subgallery tree(s).', [
                'photos' => (int) $copiedImages['copied'],
                'galleries' => (int) $copiedGalleries['copied_roots'],
            ]),
            'source_gallery_id' => $sourceGalleryId,
            'source_gallery_url' => gallery_public_url($updatedSource),
            'parent_gallery_id' => $parentGalleryId,
            'created_gallery_id' => $createdGalleryId,
            'created_gallery_url' => gallery_public_url($updatedCreatedGallery),
            'created_gallery_title' => (string) ($updatedCreatedGallery['title'] ?? $title),
            'refresh_url' => gallery_public_url($updatedSource),
            'copied_image_ids' => $copiedImages['created_image_ids'],
            'copied_gallery_ids' => $copiedGalleries['created_gallery_ids'],
        ]);
    } catch (Throwable $exception) {
        if ($createdGalleryId > 0 && !$selectionCopied) {
            try {
                // This root was created exclusively for the failed operation, so rollback bypasses trash.
                delete_gallery_subtrees([$createdGalleryId]);
            } catch (Throwable) {
            }
        }
        admin_log_event('error', 'picture_manager.gallery_create_failed', 'Picture manager create-gallery-from-selection failed.', [
            'source_gallery_id' => (int) ($_POST['source_gallery_id'] ?? 0),
            'parent_gallery_id' => (int) ($_POST['new_gallery_parent_id'] ?? 0),
            'created_gallery_id' => $createdGalleryId,
            'error' => $exception->getMessage(),
        ], ['category' => 'other', 'severity' => 'error']);
        picture_manager_json_response([
            'ok' => false,
            'message' => 'Create gallery failed: ' . $exception->getMessage(),
        ], 422);
    }
}

/**
 * Delete selected direct photos and physical subgallery roots from the current gallery.
 *
 * Gallery roots follow the configured trash policy. Photo deletion uses the existing
 * filesystem-backed image deletion service and therefore removes originals plus
 * generated derivatives before the database index is finalized.
 */
function cms_picture_manager_delete(): void
{
    // $user stores the authenticated account used for trash attribution and audit context.
    $user = picture_manager_require_logged_in_user();
    verify_csrf();

    try {
        // $sourceGallery stores the gallery where the mixed selection was made.
        $sourceGallery = picture_manager_source_gallery_from_post();
        // $sourceGalleryId stores its database identifier.
        $sourceGalleryId = (int) $sourceGallery['id'];
        // $selection stores normalized direct photos and direct physical subgalleries.
        $selection = picture_manager_selection_from_post();
        // $imageIds stores selected direct image IDs.
        $imageIds = $selection['image_ids'];
        // $galleryIds stores selected direct physical gallery IDs.
        $galleryIds = $selection['gallery_ids'];

        // Validate every submitted ID before the first destructive mutation.
        $validationFailures = [];
        if ($imageIds) {
            $validatedImages = picture_manager_owned_images_for_selection($sourceGalleryId, $imageIds, $validationFailures);
            if (count($validatedImages) !== count($imageIds)) {
                throw new RuntimeException(implode(' ', $validationFailures ?: ['One or more selected photos are invalid.']));
            }
        }
        if ($galleryIds) {
            $validatedGalleries = picture_manager_owned_galleries_for_selection($sourceGalleryId, $galleryIds, $validationFailures);
            if (count($validatedGalleries) !== count($galleryIds)) {
                throw new RuntimeException(implode(' ', $validationFailures ?: ['One or more selected galleries are invalid.']));
            }
        }

        // Freeze the gallery deletion policy before any image is removed.
        $trashEnabled = $galleryIds && gallery_trash_enabled();
        if ($trashEnabled && !gallery_trash_available()) {
            $trashSchemaStatus = gallery_trash_schema_status();
            throw new RuntimeException((string) ($trashSchemaStatus['state'] ?? 'unknown') === 'missing'
                ? t('admin.galleries.trash_requires_migration', 'The trash bin needs a database migration. Run pending migrations, then try again.')
                : t('admin.galleries.trash_temporarily_unavailable', 'The trash bin is temporarily unavailable because its database schema could not be verified. Nothing was deleted.'));
        }

        // Process selected gallery roots first. Trash mode makes these recoverable and
        // avoids permanently deleting direct photos if a gallery-root mutation fails.
        $galleryResult = ['root_count' => 0, 'row_count' => 0, 'failed_root_count' => 0, 'failures' => []];
        if ($galleryIds) {
            $galleryResult = $trashEnabled
                ? move_gallery_subtrees_to_trash($galleryIds, [
                    'user_id' => (int) ($user['id'] ?? 0),
                    'deleted_from' => 'picture_manager',
                ])
                : delete_gallery_subtrees($galleryIds);
            if ((int) ($galleryResult['failed_root_count'] ?? 0) > 0) {
                throw new RuntimeException('One or more selected galleries could not be deleted. Direct photos were left untouched.');
            }
        }

        // $imageResult stores deletion counts for selected direct photos.
        $imageResult = $imageIds
            ? delete_gallery_images($sourceGalleryId, $imageIds)
            : ['requested' => 0, 'deleted' => 0, 'files_deleted' => 0, 'derivatives_deleted' => 0, 'missing_files' => 0, 'cleanup_failed' => 0];

        // $updatedSource stores the surviving source gallery for the browser refresh URL.
        $updatedSource = find_gallery($sourceGalleryId, true) ?: $sourceGallery;
        admin_log_event('warning', 'picture_manager.selection_deleted', 'Picture manager deleted selected public-view photos and physical galleries.', [
            'source_gallery_id' => $sourceGalleryId,
            'requested_images' => count($imageIds),
            'deleted_images' => (int) $imageResult['deleted'],
            'requested_gallery_roots' => count($galleryIds),
            'deleted_gallery_roots' => (int) ($galleryResult['root_count'] ?? 0),
            'deleted_gallery_rows' => (int) ($galleryResult['row_count'] ?? 0),
            'gallery_trash_enabled' => $trashEnabled,
        ], ['category' => 'other', 'severity' => 'warning']);

        picture_manager_json_response([
            'ok' => true,
            'message' => t('picture_manager.deleted_mixed_selection', 'Deleted {photos} photo(s) and {galleries} physical gallery tree(s).', [
                'photos' => (int) $imageResult['deleted'],
                'galleries' => (int) ($galleryResult['root_count'] ?? 0),
            ]),
            'source_gallery_id' => $sourceGalleryId,
            'refresh_url' => gallery_public_url($updatedSource),
            'deleted_images' => (int) $imageResult['deleted'],
            'deleted_gallery_roots' => (int) ($galleryResult['root_count'] ?? 0),
            'galleries_trashed' => (bool) $trashEnabled,
        ]);
    } catch (Throwable $exception) {
        admin_log_event('error', 'picture_manager.selection_delete_failed', 'Picture manager mixed selection delete failed.', [
            'source_gallery_id' => (int) ($_POST['source_gallery_id'] ?? 0),
            'error' => $exception->getMessage(),
        ], ['category' => 'other', 'severity' => 'error']);
        picture_manager_json_response([
            'ok' => false,
            'message' => 'Delete selection failed: ' . $exception->getMessage(),
        ], 422);
    }
}

