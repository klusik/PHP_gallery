<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_edit.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Handles gallery editor forms and gallery/image save payload helpers.
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

/**
 * Handles cms admin scan images logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function cms_admin_scan_images(): void
{
    require_admin();
    verify_csrf();
    // Variable $galleryIds stores this steps working value.
    $galleryIds = $_POST['gallery_ids'] ?? [];
    if (!$galleryIds && isset($_POST['gallery_id'])) {
        // Variable $galleryIds stores this steps working value.
        $galleryIds = [$_POST['gallery_id']];
    }
    // Variable $count stores this steps working value.
    $count = 0;
    foreach ($galleryIds as $galleryId) {
        $count += scan_gallery_images((int) $galleryId);
    }
    flash_message('admin_notice', t('admin.galleries.scan_result', ['count' => $count]));
    redirect_to(url_for('admin'));
}

/**
 * Normalize an edit-gallery admin tab identifier.
 */
function admin_edit_gallery_tab_id(string $tab): string
{
    // $allowedTabs stores admin edit tab identifiers that may be returned after POST actions.
    $allowedTabs = ['admin-edit-identity', 'admin-edit-access', 'admin-edit-display', 'admin-edit-media', 'admin-edit-images'];
    return in_array($tab, $allowedTabs, true) ? $tab : '';
}

/**
 * Return an edit-gallery admin URL with an optional tab fragment.
 */
function admin_edit_gallery_tab_url(int $galleryId, string $tab = ''): string
{
    // $resolvedTab stores the normalized tab id used in both query and hash navigation.
    $resolvedTab = admin_edit_gallery_tab_id($tab);
    // $params stores the query parameters used for server-rendered tab state.
    $params = ['id' => $galleryId];
    if ($resolvedTab !== '') {
        $params['tab'] = $resolvedTab;
    }
    return url_for('admin_edit_gallery', $params) . ($resolvedTab !== '' ? '#' . $resolvedTab : '');
}

/**
 * Return the admin edit tab requested by a submitted form.
 */
function admin_return_tab_from_post(string $fallback = ''): string
{
    // $tab stores the submitted tab target used to keep admins in the current workspace after save.
    $tab = admin_edit_gallery_tab_id((string) ($_POST['return_tab'] ?? ''));
    return $tab !== '' ? $tab : admin_edit_gallery_tab_id($fallback);
}

/**
 * Build the JSON payload consumed after a gallery is saved in side-panel mode.
 */
function admin_edit_gallery_success_response(array $gallery, string $notice, string $returnTab): array
{
    return [
        'ok' => true,
        'type' => 'gallery',
        'message' => $notice,
        'gallery_id' => (int) $gallery['id'],
        'gallery_title' => (string) ($gallery['title'] ?? ''),
        'gallery_url' => gallery_public_url($gallery),
        'edit_url' => admin_edit_gallery_tab_url((int) $gallery['id'], $returnTab),
        'refresh_url' => gallery_public_url($gallery),
    ];
}

/**
 * Build the JSON payload consumed after a gallery image bulk action runs in side-panel mode.
 */
function admin_bulk_images_success_response(array $gallery, string $notice, string $returnTab, string $action, array $imageIds = []): array
{
    $payload = admin_edit_gallery_success_response($gallery, $notice, $returnTab);
    $payload['type'] = 'gallery_image_bulk';
    $payload['bulk_action'] = $action;
    $payload['image_ids'] = array_values(array_map('intval', $imageIds));
    $payload['cover_image_id'] = (int) ($gallery['cover_image_id'] ?? 0);
    return $payload;
}

/**
 * Persist a gallery title picture from either the bulk image route or a panel-routed edit request.
 */
function admin_save_gallery_title_picture(array $gallery, array $imageIds, string $returnTab): void
{
    // $galleryId stores the gallery being updated by the title-picture action.
    $galleryId = (int) ($gallery['id'] ?? 0);
    // $ownedIds stores selected images that still belong to this gallery.
    $ownedIds = [];
    foreach ($imageIds as $imageId) {
        // $image stores the selected image record used for gallery ownership validation.
        $image = find_image((int) $imageId);
        if ($image && (int) ($image['gallery_id'] ?? 0) === $galleryId) {
            $ownedIds[] = (int) $imageId;
        }
    }
    if (!$ownedIds) {
        if (admin_wants_json()) {
            admin_panel_error_response(t('admin.gallery_editor.selected_photo_unavailable'));
            return;
        }
        redirect_to(admin_edit_gallery_tab_url($galleryId, $returnTab));
    }

    // $coverImageId stores the first selected image because only one title picture can be saved.
    $coverImageId = (int) $ownedIds[0];
    // $stmt stores the database update for the gallery title picture.
    $stmt = db()->prepare('UPDATE galleries SET cover_image_id = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$coverImageId, now_sql(), $galleryId]);
    // $updated stores the reloaded gallery row so JSON reflects the persisted database state.
    $updated = find_gallery($galleryId, true) ?: find_gallery($galleryId) ?: $gallery;
    if ($updated) {
        write_gallery_sidecar($updated);
    }
    // $notice stores the message returned to the direct page or side-panel workflow.
    $notice = t('admin.gallery_editor.title_picture_saved');
    if (admin_wants_json()) {
        header('Content-Type: application/json');
        echo json_encode(admin_bulk_images_success_response($updated, $notice, $returnTab, 'cover', $ownedIds));
        return;
    }
    flash_message('admin_notice', $notice);
    redirect_to(admin_edit_gallery_tab_url($galleryId, $returnTab));
}

/**
 * Build the JSON payload consumed after an image is saved in side-panel mode.
 */
function admin_edit_image_success_response(array $image): array
{
    // $gallery stores the image gallery used to rebuild public context URLs after saving.
    $gallery = find_gallery((int) ($image['gallery_id'] ?? 0));
    return [
        'ok' => true,
        'type' => 'image',
        'message' => t('admin.gallery_editor.image_saved'),
        'image_id' => (int) $image['id'],
        'gallery_id' => (int) ($image['gallery_id'] ?? 0),
        'image_title' => (string) ($image['title'] ?? ''),
        'image_description' => (string) ($image['description'] ?? ''),
        'image_visibility' => (string) ($image['visibility'] ?? ''),
        'image_sort_order' => (int) ($image['sort_order'] ?? 0),
        'image_url' => $gallery ? image_public_url($image, $gallery) : '',
        'gallery_url' => $gallery ? gallery_public_url($gallery) : '',
        'edit_url' => url_for('admin_edit_image', ['id' => (int) $image['id'], 'saved' => 1]),
    ];
}

/**
 * Sends a JSON error response for side-panel save failures.
 */
function admin_panel_error_response(string $message, int $statusCode = 422): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $message]);
}


/**
 * Return true when a partial gallery save contains any value from a field group.
 */
function admin_gallery_input_has_any_key(array $input, array $keys): bool
{
    foreach ($keys as $key) {
        if (array_key_exists((string) $key, $input)) {
            return true;
        }
    }
    return false;
}

/**
 * Read a checkbox value while allowing partial workflows to preserve existing data.
 */
function admin_gallery_checkbox_input(array $input, string $key, bool $defaultWhenMissing): int
{
    if (!array_key_exists($key, $input)) {
        return $defaultWhenMissing ? 1 : 0;
    }
    return !empty($input[$key]) ? 1 : 0;
}

/**
 * Persist gallery edits through the shared admin edit implementation.
 */
function admin_save_gallery_from_input(array $gallery, array $input, array $files, string $returnTab, bool $completeForm = true): array
{
    // $pictureGameReady stores this steps working value.
    $pictureGameReady = picture_game_schema_ready();
    // $gpsMapReady stores this steps working value.
    $gpsMapReady = exif_gps_schema_ready();
    // $accessReady stores this steps working value.
    $accessReady = gallery_access_schema_ready();
    // $galleryId stores the gallery being edited.
    $galleryId = (int) ($gallery['id'] ?? 0);
    // $title stores the submitted or preserved public gallery title.
    $title = trim((string) ($input['title'] ?? $gallery['title'] ?? ''));
    if ($title === '') {
        $title = (string) ($gallery['title'] ?? '');
    }
    // $slug stores the submitted or preserved public slug.
    $slug = trim((string) ($input['slug'] ?? $gallery['slug'] ?? $title));
    // $visibility stores the normalized gallery visibility value.
    $visibility = gallery_visibility_storage_value((string) ($input['visibility'] ?? $gallery['visibility'] ?? 'unpublished'));
    // $galleryDate stores the optional manual date selected by an admin.
    $galleryDate = gallery_date_schema_ready() ? gallery_date_storage_value($input['gallery_date'] ?? ($gallery['gallery_date'] ?? '')) : null;
    // $pictureGameDefault stores the current value for partial update preservation.
    $pictureGameDefault = !$completeForm && (int) ($gallery['picture_game_enabled'] ?? 0) === 1;
    // $gpsMapDefault stores the current value for partial update preservation.
    $gpsMapDefault = !$completeForm && (int) ($gallery['gps_map_enabled'] ?? 0) === 1;
    // $votingDefault stores the current value for partial update preservation.
    $votingDefault = !$completeForm && (int) ($gallery['voting_enabled'] ?? 0) === 1;
    // $showFilenamesDefault stores the current value for partial update preservation.
    $showFilenamesDefault = !$completeForm && (int) ($gallery['show_filenames'] ?? 0) === 1;
    // $nsfwDefault stores the current value for partial update preservation.
    $nsfwDefault = !$completeForm && (int) ($gallery['nsfw_enabled'] ?? 0) === 1;
    // Variable $pictureGameEnabled stores this steps working value.
    $pictureGameEnabled = $pictureGameReady ? admin_gallery_checkbox_input($input, 'picture_game_enabled', $pictureGameDefault) : 0;
    // Variable $gpsMapEnabled stores this steps working value.
    $gpsMapEnabled = $gpsMapReady ? admin_gallery_checkbox_input($input, 'gps_map_enabled', $gpsMapDefault) : 0;
    // Variable $votingEnabled stores this steps working value.
    $votingEnabled = gallery_voting_schema_ready() ? admin_gallery_checkbox_input($input, 'voting_enabled', $votingDefault) : 0;
    // Variable $showFilenames stores this steps working value.
    $showFilenames = gallery_filename_display_schema_ready() ? admin_gallery_checkbox_input($input, 'show_filenames', $showFilenamesDefault) : 0;
    // $countBadgeVisibility stores the optional gallery-card count badge override for this gallery.
    $countBadgeVisibility = gallery_count_badge_schema_ready() ? gallery_count_badge_storage_value($input['count_badge_visibility'] ?? ($gallery['count_badge_visibility'] ?? 'inherit')) : null;
    // Variable $nsfwEnabled stores whether this gallery requires the NSFW Guard confirmation.
    $nsfwEnabled = nsfw_guard_schema_ready() ? admin_gallery_checkbox_input($input, 'nsfw_enabled', $nsfwDefault) : 0;
    if ($pictureGameEnabled) {
        // $votingEnabled stores an intermediate value used by the surrounding gallery workflow.
        $votingEnabled = 1;
    }
    if (!$votingEnabled) {
        // $pictureGameEnabled stores an intermediate value used by the surrounding gallery workflow.
        $pictureGameEnabled = 0;
    }

    // $shouldMoveGallery stores whether placement fields are owned by this save request.
    $shouldMoveGallery = $completeForm || admin_gallery_input_has_any_key($input, ['parent_id', 'folder_name']);
    // Variable $parentId stores this steps working value.
    $parentId = isset($gallery['parent_id']) ? (int) $gallery['parent_id'] : null;
    // $moveResult stores an intermediate value used by the surrounding gallery workflow.
    $moveResult = null;
    if ($shouldMoveGallery) {
        // Variable $parentId stores this steps working value.
        $parentId = (int) ($input['parent_id'] ?? 0);
        // Variable $parentId stores this steps working value.
        $parentId = $parentId > 0 && find_gallery($parentId) ? $parentId : null;
        // $currentFolderName stores an intermediate value used by the surrounding gallery workflow.
        $currentFolderName = gallery_folder_name_from_path((string) $gallery['folder_path']);
        // $submittedFolderName stores an intermediate value used by the surrounding gallery workflow.
        $submittedFolderName = trim((string) ($input['folder_name'] ?? $currentFolderName));
        // $folderNameChanged stores an intermediate value used by the surrounding gallery workflow.
        $folderNameChanged = $submittedFolderName !== '' && $submittedFolderName !== $currentFolderName;
        if ((int) ($gallery['parent_id'] ?? 0) !== (int) ($parentId ?? 0) || $folderNameChanged) {
            try {
                // $moveResult stores an intermediate value used by the surrounding gallery workflow.
                $moveResult = move_gallery_folder_to_parent($galleryId, $parentId, $folderNameChanged ? $submittedFolderName : null);
                if (!empty($moveResult['moved'])) {
                    admin_log_event('info', 'gallery.folder_moved', 'Admin moved a gallery folder.', [
                        'gallery_id' => $galleryId,
                        'from' => (string) $moveResult['from'],
                        'to' => (string) $moveResult['to'],
                        'galleries' => (int) $moveResult['galleries'],
                    ]);
                }
                // $gallery stores an intermediate value used by the surrounding gallery workflow.
                $gallery = find_gallery($galleryId) ?: $gallery;
            } catch (Throwable $exception) {
                admin_log_event('error', 'gallery.folder_move_failed', 'Admin gallery folder move failed.', [
                    'gallery_id' => $galleryId,
                    'error' => $exception->getMessage(),
                ]);
                throw new RuntimeException(t('admin.gallery_editor.folder_move_failed', ['error' => $exception->getMessage()]), 0, $exception);
            }
        }
    }

    // $shouldUpdateCover stores whether the title-picture fields are part of this request.
    $shouldUpdateCover = $completeForm || array_key_exists('cover_image_id', $input) || !empty($files['cover_upload']['name'] ?? '');
    // Variable $coverImageId stores this steps working value.
    $coverImageId = (int) ($gallery['cover_image_id'] ?? 0);
    // $coverImagePath stores an intermediate value used by the surrounding gallery workflow.
    $coverImagePath = gallery_cover_asset_schema_ready() ? gallery_cover_path($gallery) : null;
    if ($shouldUpdateCover) {
        // Variable $coverImageId stores this steps working value.
        $coverImageId = (int) ($input['cover_image_id'] ?? 0);
        // Variable $coverImage stores this steps working value.
        $coverImage = $coverImageId > 0 ? find_image($coverImageId) : null;
        // Variable $coverImageId stores this steps working value.
        $coverImageId = $coverImage && (int) $coverImage['gallery_id'] === $galleryId ? $coverImageId : null;
        if (gallery_cover_asset_schema_ready() && !empty($files['cover_upload']['name'] ?? '')) {
            // $uploadError stores an intermediate value used by the surrounding gallery workflow.
            $uploadError = (int) ($files['cover_upload']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError !== UPLOAD_ERR_NO_FILE) {
                if ($uploadError !== UPLOAD_ERR_OK) {
                    throw new RuntimeException(upload_error_message($uploadError));
                }
                // $tmpName stores an intermediate value used by the surrounding gallery workflow.
                $tmpName = (string) ($files['cover_upload']['tmp_name'] ?? '');
                if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                    throw new RuntimeException(t('admin.gallery_editor.uploaded_thumbnail_unavailable'));
                }
                // $info stores an intermediate value used by the surrounding gallery workflow.
                $info = @getimagesize($tmpName);
                if ($info === false || empty($info['mime']) || !str_starts_with((string) $info['mime'], 'image/')) {
                    throw new RuntimeException(t('admin.gallery_editor.uploaded_thumbnail_invalid'));
                }
                // $coverImagePath stores an intermediate value used by the surrounding gallery workflow.
                $coverImagePath = store_uploaded_gallery_cover($galleryId, $files['cover_upload']);
                // $coverImageId stores an intermediate value used by the surrounding gallery workflow.
                $coverImageId = null;
            }
        }
    }

    // $brandingAssetPaths stores optional banner, logo, and separator paths before form changes.
    $brandingAssetPaths = gallery_branding_schema_ready() ? gallery_branding_asset_paths($gallery) : [];
    // $shouldUpdateBranding stores whether branding fields are part of this request.
    $shouldUpdateBranding = $completeForm;
    if (!$shouldUpdateBranding && gallery_branding_schema_ready()) {
        foreach (array_keys(gallery_branding_asset_types()) as $brandingKind) {
            if (!empty($files['branding_' . $brandingKind . '_upload']['name'] ?? '') || array_key_exists('remove_branding_' . $brandingKind, $input)) {
                $shouldUpdateBranding = true;
                break;
            }
        }
    }
    if (gallery_branding_schema_ready() && $shouldUpdateBranding) {
        try {
            foreach (array_keys(gallery_branding_asset_types()) as $brandingKind) {
                // $uploadField stores the file-input name for this gallery branding asset.
                $uploadField = 'branding_' . $brandingKind . '_upload';
                // $removeField stores the remove-checkbox name for this gallery branding asset.
                $removeField = 'remove_branding_' . $brandingKind;
                // $hasUpload stores whether this asset is being replaced by a new file.
                $hasUpload = !empty($files[$uploadField]['name'] ?? '');
                if ($hasUpload) {
                    $brandingAssetPaths[$brandingKind] = store_uploaded_gallery_branding_asset($galleryId, $brandingKind, $files[$uploadField]);
                    continue;
                }
                if (!empty($input[$removeField])) {
                    delete_gallery_branding_asset($galleryId, $brandingKind);
                    $brandingAssetPaths[$brandingKind] = null;
                }
            }
        } catch (RuntimeException $exception) {
            throw new RuntimeException(t('admin.gallery_editor.branding_update_failed', ['error' => $exception->getMessage()]), 0, $exception);
        }
    }

    // $backgroundSource stores an intermediate value used by the surrounding gallery workflow.
    $backgroundSource = gallery_background_source_schema_ready() ? gallery_background_source($gallery) : null;
    // $shouldUpdateBackgroundSource stores whether the background source selector is part of this request.
    $shouldUpdateBackgroundSource = $completeForm || array_key_exists('background_source', $input);
    if (gallery_background_source_schema_ready() && $shouldUpdateBackgroundSource) {
        // $submittedBackgroundSource stores an intermediate value used by the surrounding gallery workflow.
        $submittedBackgroundSource = (string) ($input['background_source'] ?? '');
        $backgroundSource = in_array($submittedBackgroundSource, ['upload', 'existing', 'collage'], true) ? $submittedBackgroundSource : null;
    }

    // Variable $slug stores this steps working value.
    $slug = $slug !== '' ? slugify($slug) : unique_slug(db(), $title, $galleryId);
    // $descriptionLayoutOverride stores the optional gallery-card layout override for this gallery.
    $descriptionLayoutOverride = gallery_description_layout_schema_ready() ? gallery_description_layout_storage_value($input['description_layout'] ?? ($completeForm ? 'inherit' : ($gallery['description_layout'] ?? 'inherit'))) : null;
    // $shouldUpdateGrid stores whether grid fields are part of this request.
    $shouldUpdateGrid = $completeForm || admin_gallery_input_has_any_key($input, ['grid_override_enabled', 'grid_columns', 'grid_rows', 'grid_use_for_subgalleries']);
    // $gridUsesCustomSettings stores whether this gallery should stop inheriting the display grid.
    $gridUsesCustomSettings = admin_gallery_checkbox_input($input, 'grid_override_enabled', !$completeForm && ((int) ($gallery['grid_columns'] ?? 0) > 0 || (int) ($gallery['grid_rows'] ?? 0) > 0)) === 1;
    // $gridColumns stores the optional custom column count for public cards/photos in this gallery.
    $gridColumns = $gridUsesCustomSettings ? pagination_dimension_value($input['grid_columns'] ?? CMS_PAGINATION_DEFAULT_COLUMNS, CMS_PAGINATION_DEFAULT_COLUMNS, CMS_PAGINATION_MAX_COLUMNS) : null;
    // $gridRows stores the optional custom row count used when pagination slices this gallery.
    $gridRows = $gridUsesCustomSettings ? pagination_dimension_value($input['grid_rows'] ?? CMS_PAGINATION_DEFAULT_ROWS, CMS_PAGINATION_DEFAULT_ROWS, CMS_PAGINATION_MAX_ROWS) : null;
    // $gridUseForSubgalleries stores whether descendants may inherit this gallery grid.
    $gridUseForSubgalleries = admin_gallery_checkbox_input($input, 'grid_use_for_subgalleries', !$completeForm && (int) ($gallery['grid_use_for_subgalleries'] ?? 0) === 1);
    // $shouldUpdateThumbnailBounds stores whether thumbnail-bound fields are part of this request.
    $shouldUpdateThumbnailBounds = $completeForm || admin_gallery_input_has_any_key($input, ['gallery_thumbnail_min_size', 'gallery_thumbnail_max_size', 'gallery_thumbnail_bounds_recursive']);
    // $thumbnailBounds stores the optional minimum and maximum responsive thumbnail sizes for this gallery.
    $thumbnailBounds = thumbnail_bounds_schema_ready() && $shouldUpdateThumbnailBounds ? thumbnail_bound_pair_from_post('gallery_thumbnail') : [($gallery['thumbnail_min_size'] ?? null), ($gallery['thumbnail_max_size'] ?? null)];
    // $thumbnailBoundsRecursive stores whether descendants should receive the same saved thumbnail bounds.
    $thumbnailBoundsRecursive = thumbnail_bounds_schema_ready() && !empty($input['gallery_thumbnail_bounds_recursive']);
    // $shouldUpdateAccess stores whether access controls are part of this request.
    $shouldUpdateAccess = $completeForm || admin_gallery_input_has_any_key($input, ['access_action', 'access_type', 'clear_access_password', 'access_password', 'access_token_expires_at']);
    // $accessAction stores an intermediate value used by the surrounding gallery workflow.
    $accessAction = $accessReady ? (string) ($input['access_action'] ?? 'save') : 'save';
    // Variable $accessType stores this steps working value.
    $accessType = $accessReady && ($input['access_type'] ?? '') === 'password' ? 'password' : 'normal';
    // Variable $accessListing stores this steps working value.
    $accessListing = normalize_gallery_visibility($visibility) === 'public' ? 'listed' : 'unlisted';
    // Variable $accessPasswordHash stores this steps working value.
    $accessPasswordHash = $accessReady ? ($gallery['access_password_hash'] ?? null) : null;
    if ($accessReady && !empty($input['clear_access_password'])) {
        // $accessPasswordHash stores an intermediate value used by the surrounding gallery workflow.
        $accessPasswordHash = null;
    }
    // Variable $newAccessPassword stores this steps working value.
    $newAccessPassword = trim((string) ($input['access_password'] ?? ''));
    if ($accessReady && $accessType === 'password' && $newAccessPassword !== '') {
        // $accessPasswordHash stores an intermediate value used by the surrounding gallery workflow.
        $accessPasswordHash = password_hash($newAccessPassword, PASSWORD_DEFAULT);
    }
    if ($accessType !== 'password') {
        // $accessPasswordHash stores an intermediate value used by the surrounding gallery workflow.
        $accessPasswordHash = null;
    }
    // Variable $accessMode stores this steps working value.
    $accessMode = $accessReady && ($accessType === 'password' || !empty($gallery['access_token_hash']) || $accessAction === 'generate_link') ? 'password' : 'normal';
    if ($accessAction === 'revoke_link' && $accessType !== 'password') {
        // $accessMode stores an intermediate value used by the surrounding gallery workflow.
        $accessMode = 'normal';
    }

    // $fields stores an intermediate value used by the surrounding gallery workflow.
    $fields = [
        'title = ?' => $title,
        'description = ?' => (string) ($input['description'] ?? $gallery['description'] ?? ''),
        'slug = ?' => unique_slug_for_value($slug, $galleryId),
        'visibility = ?' => $visibility,
        'sort_order = ?' => (int) ($input['sort_order'] ?? $gallery['sort_order'] ?? 0),
    ];
    if ($shouldMoveGallery) {
        $fields['parent_id = ?'] = $parentId;
    }
    if ($shouldUpdateCover) {
        $fields['cover_image_id = ?'] = $coverImageId;
    }
    if (gallery_date_schema_ready()) {
        $fields['gallery_date = ?'] = $galleryDate;
    }
    if ($pictureGameReady) {
        $fields['picture_game_enabled = ?'] = $pictureGameEnabled;
    }
    if ($gpsMapReady) {
        $fields['gps_map_enabled = ?'] = $gpsMapEnabled;
    }
    if (gallery_voting_schema_ready()) {
        $fields['voting_enabled = ?'] = $votingEnabled;
    }
    if (gallery_filename_display_schema_ready()) {
        $fields['show_filenames = ?'] = $showFilenames;
    }
    if (gallery_description_layout_schema_ready()) {
        $fields['description_layout = ?'] = $descriptionLayoutOverride;
    }
    if (gallery_count_badge_schema_ready()) {
        $fields['count_badge_visibility = ?'] = $countBadgeVisibility;
    }
    if (nsfw_guard_schema_ready()) {
        $fields['nsfw_enabled = ?'] = $nsfwEnabled;
    }
    if (gallery_grid_schema_ready() && $shouldUpdateGrid) {
        $fields['grid_columns = ?'] = $gridColumns;
        $fields['grid_rows = ?'] = $gridRows;
        $fields['grid_use_for_subgalleries = ?'] = $gridUseForSubgalleries;
    }
    if (thumbnail_bounds_schema_ready() && $shouldUpdateThumbnailBounds) {
        $fields['thumbnail_min_size = ?'] = $thumbnailBounds[0];
        $fields['thumbnail_max_size = ?'] = $thumbnailBounds[1];
    }
    if ($accessReady && $shouldUpdateAccess) {
        $fields['access_mode = ?'] = $accessMode;
        $fields['access_listing = ?'] = $accessListing;
        $fields['access_password_hash = ?'] = $accessMode === 'password' ? $accessPasswordHash : null;
        if ($accessMode !== 'password') {
            if (gallery_access_share_token_schema_ready()) {
                $fields['access_share_token = ?'] = null;
            }
            $fields['access_token_hash = ?'] = null;
            $fields['access_token_expires_at = ?'] = null;
        }
    }
    if (gallery_cover_asset_schema_ready() && $shouldUpdateCover) {
        $fields['cover_image_path = ?'] = $coverImagePath;
    }
    if (gallery_branding_schema_ready() && $shouldUpdateBranding) {
        foreach (gallery_branding_asset_types() as $brandingKind => $definition) {
            // $column stores an intermediate value used by the surrounding gallery workflow.
            $column = (string) $definition['column'];
            $fields[$column . ' = ?'] = $brandingAssetPaths[$brandingKind] ?? null;
        }
    }
    if (gallery_background_source_schema_ready() && $shouldUpdateBackgroundSource) {
        $fields['background_source = ?'] = $backgroundSource;
    }
    $fields['updated_at = ?'] = now_sql();
    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = db()->prepare('UPDATE galleries SET ' . implode(', ', array_keys($fields)) . ' WHERE id = ?');
    $stmt->execute(array_merge(array_values($fields), [$galleryId]));
    if (thumbnail_bounds_schema_ready() && $thumbnailBoundsRecursive && $shouldUpdateThumbnailBounds) {
        save_gallery_thumbnail_bounds($gallery, $thumbnailBounds[0], $thumbnailBounds[1], true);
    }
    if ($accessReady && $shouldUpdateAccess) {
        if ($accessAction === 'revoke_link') {
            revoke_gallery_share_token($galleryId);
        }
    }
    if ($accessReady && $shouldUpdateAccess && $accessMode === 'password') {
        if ($accessAction === 'generate_link') {
            // $expires stores an intermediate value used by the surrounding gallery workflow.
            $expires = trim((string) ($input['access_token_expires_at'] ?? ''));
            // $expiresTimestamp stores an intermediate value used by the surrounding gallery workflow.
            $expiresTimestamp = $expires !== '' ? strtotime($expires) : false;
            // $expiresAt stores an intermediate value used by the surrounding gallery workflow.
            $expiresAt = $expiresTimestamp !== false ? date('Y-m-d H:i:s', $expiresTimestamp) : null;
            $_SESSION['new_gallery_share_token_' . $galleryId] = regenerate_gallery_share_token($galleryId, $expiresAt);
        }
    }
    if ($completeForm || array_key_exists('tags', $input)) {
        sync_entity_tags('gallery', $galleryId, (string) ($input['tags'] ?? ''));
    }
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery($galleryId, true) ?: $gallery;
    if ($gallery) {
        write_gallery_sidecar($gallery);
    }
    // $notice stores an intermediate value used by the surrounding gallery workflow.
    $notice = t('admin.gallery_editor.notice_saved', 'Gallery saved.');
    if (!empty($moveResult['moved'])) {
        // $notice stores an intermediate value used by the surrounding gallery workflow.
        $notice = t('admin.gallery_editor.notice_saved_and_moved', 'Gallery saved and folder moved.');
    }
    return [
        'gallery' => $gallery,
        'notice' => $notice,
        'return_tab' => $returnTab,
        'moved' => !empty($moveResult['moved']),
    ];
}

/**
 * Handles cms admin edit gallery logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function cms_admin_edit_gallery(): void
{
    require_admin();
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) ($_GET['id'] ?? $_POST['id'] ?? $_POST['gallery_id'] ?? 0));
    if (!$gallery) {
        cms_not_found();
        return;
    }
    // Variable $pictureGameReady stores this steps working value.

    $pictureGameReady = picture_game_schema_ready();
    // Variable $gpsMapReady stores this steps working value.
    $gpsMapReady = exif_gps_schema_ready();
    // Variable $accessReady stores this steps working value.
    $accessReady = gallery_access_schema_ready();
    if (request_method() === 'POST') {
        verify_csrf();
        // $returnTab stores the tab fragment used after saving the gallery editor form.
        $returnTab = admin_return_tab_from_post('admin-edit-identity');
        if ((string) ($_POST['action'] ?? '') === 'cover' && isset($_POST['image_ids'])) {
            admin_save_gallery_title_picture($gallery, array_map('intval', $_POST['image_ids'] ?? []), $returnTab);
            return;
        }
        try {
            // $saveResult stores the shared gallery save outcome used by both page and panel workflows.
            $saveResult = admin_save_gallery_from_input($gallery, $_POST, $_FILES, $returnTab, true);
        } catch (Throwable $exception) {
            if (admin_wants_json()) {
                admin_panel_error_response($exception->getMessage());
                return;
            }
            $_SESSION['admin_gallery_error_' . (int) $gallery['id']] = $exception->getMessage();
            flash_message('admin_notice', $exception->getMessage());
            redirect_to(admin_edit_gallery_tab_url((int) $gallery['id'], $returnTab));
        }
        // $gallery stores the saved gallery row from the shared persistence helper.
        $gallery = $saveResult['gallery'] ?? $gallery;
        // $notice stores an intermediate value used by the surrounding gallery workflow.
        $notice = (string) ($saveResult['notice'] ?? t('admin.gallery_editor.notice_saved', 'Gallery saved.'));
        if (admin_wants_json()) {
            header('Content-Type: application/json');
            echo json_encode(admin_edit_gallery_success_response($gallery, $notice, $returnTab));
            return;
        }
        flash_message('admin_notice', $notice);
        redirect_to(admin_edit_gallery_tab_url((int) $gallery['id'], $returnTab));
    }
    // Variable $images stores this steps working value.
    $images = gallery_images((int) $gallery['id'], false);
    render_header(t('admin.gallery_editor.page_title'));
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
    if (!$pictureGameReady) {
        render_admin_migration_notice(t('admin.gallery_editor.picture_game_migration_hidden'));
    }
    // $imageCount stores the number of images currently attached to this gallery.
    $imageCount = count($images);
    // $activeVisibility stores the normalized gallery visibility label for summary cards.
    $activeVisibility = normalize_gallery_visibility((string) ($gallery['visibility'] ?? 'unpublished'));
    // $activeEditTab stores the tab selected by redirect query state before JavaScript reads the URL hash.
    $activeEditTab = admin_edit_gallery_tab_id((string) ($_GET['tab'] ?? '')) ?: 'admin-edit-identity';
    // $adminTabs stores the edit-gallery sections shown by the shared admin tab controller.
    $adminTabs = [
        ['id' => 'admin-edit-identity', 'label' => t('admin.gallery_editor.tab_identity')],
        ['id' => 'admin-edit-access', 'label' => t('admin.gallery_editor.tab_access')],
        ['id' => 'admin-edit-display', 'label' => t('admin.gallery_editor.tab_display')],
        ['id' => 'admin-edit-media', 'label' => t('admin.gallery_editor.tab_media')],
        ['id' => 'admin-edit-images', 'label' => t('admin.gallery_editor.tab_images'), 'badge' => $imageCount],
    ];

    echo '<section class="admin-dashboard-hero admin-edit-gallery-hero">';
    echo '<div><p class="admin-kicker">' . e(t('admin.gallery_editor.kicker')) . '</p><h1>' . e((string) $gallery['title']) . '</h1><p class="muted">' . e(t('admin.gallery_editor.intro')) . '</p></div>';
    echo '<nav class="admin-hero-actions" aria-label="' . e(t('admin.gallery_editor.hero_actions_label')) . '"><a class="button" href="' . e(url_for('admin_upload', ['gallery_id' => $gallery['id']])) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="upload" data-admin-side-panel-kicker="' . e(t('gallery.upload_workflow')) . '" data-admin-side-panel-title="' . e(t('gallery.upload_photos')) . '" data-gallery-side-panel-url="' . e(url_for('admin_upload', ['gallery_id' => $gallery['id'], 'panel' => 1])) . '">' . e(t('admin.gallery_editor.upload_photos_here')) . '</a><a class="button secondary" href="' . e(url_for('admin_upload', ['upload_mode' => 'new', 'parent_id' => $gallery['id']])) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="upload" data-admin-side-panel-kicker="' . e(t('gallery.workflow')) . '" data-admin-side-panel-title="' . e(t('gallery.create_here')) . '" data-gallery-side-panel-url="' . e(url_for('admin_upload', ['upload_mode' => 'new', 'parent_id' => $gallery['id'], 'panel' => 1])) . '">' . e(t('admin.gallery_editor.create_gallery_here')) . '</a><a class="button secondary" href="' . e(gallery_public_url($gallery)) . '" target="_blank" rel="noopener noreferrer">' . e(t('admin.gallery_editor.view_gallery')) . '</a><a class="button secondary" href="' . e(url_for('admin')) . '">' . e(t('admin.gallery_editor.back_to_galleries')) . '</a></nav>';
    echo '</section>';

    echo '<div class="admin-metric-grid admin-edit-gallery-summary">';
    echo '<div class="admin-metric-card"><span>' . e(t('admin.gallery_editor.metric_visibility')) . '</span><strong>' . e(ucfirst($activeVisibility)) . '</strong><small>' . e(t('admin.gallery_editor.metric_visibility_help')) . '</small></div>';
    echo '<div class="admin-metric-card"><span>' . e(t('admin.gallery_editor.metric_images')) . '</span><strong>' . (int) $imageCount . '</strong><small>' . e(t('admin.gallery_editor.metric_images_help')) . '</small></div>';
    echo '<div class="admin-metric-card"><span>' . e(t('admin.gallery_editor.metric_folder')) . '</span><strong>' . e(gallery_folder_name_from_path((string) $gallery['folder_path'])) . '</strong><small>' . e(t('admin.gallery_editor.metric_folder_help')) . '</small></div>';
    echo '<div class="admin-metric-card"><span>' . e(t('admin.gallery_editor.metric_parent')) . '</span><strong>' . ((int) ($gallery['parent_id'] ?? 0) > 0 ? '#' . (int) $gallery['parent_id'] : t('admin.gallery_editor.root_parent')) . '</strong><small>' . e(t('admin.gallery_editor.metric_parent_help')) . '</small></div>';
    echo '</div>';

    render_admin_tabs($adminTabs, $activeEditTab);

    echo '<form method="post" enctype="multipart/form-data" class="admin-edit-gallery-form" autocomplete="off">' . csrf_field();
    echo '<input type="hidden" name="id" value="' . (int) $gallery['id'] . '">';
    echo '<input type="hidden" name="return_tab" value="admin-edit-identity">';

    ob_start();
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.gallery_editor.identity_kicker', 'Identity')) . '</p><h2>' . e(t('admin.gallery_editor.names_and_placement', 'Names and placement')) . '</h2></div><p class="muted">' . e(t('admin.gallery_editor.identity_help', 'Controls the public title, URL slug, disk folder, and gallery tree position.')) . '</p></div>';
    echo '<div class="admin-edit-card-grid">';
    echo '<div class="admin-edit-card is-wide"><label>' . e(t('admin.gallery_editor.title', 'Title')) . '<input name="title" value="' . e($gallery['title']) . '" autocomplete="off" required></label>';
    if (gallery_date_schema_ready()) {
        echo '<label class="admin-date-picker-field">' . e(t('admin.gallery_editor.gallery_date', 'Date')) . '<input name="gallery_date" type="date" value="' . e(gallery_date_input_value($gallery['gallery_date'] ?? null)) . '"><span class="muted">' . e(t('admin.gallery_editor.gallery_date_help', 'Optional manual gallery date, for example an event, trip, or shooting date.')) . '</span></label>';
    } else {
        echo '<p class="muted">' . e(t('admin.gallery_editor.gallery_date_migration_hidden', 'Gallery date will be available after the database migration is applied.')) . '</p>';
    }
    echo '<label>' . e(t('admin.gallery_editor.description', 'Description')) . '<textarea name="description">' . e($gallery['description']) . '</textarea></label>';
    render_gallery_description_formatting_hint();
    echo '</div>';
    echo '<div class="admin-edit-card"><label>' . e(t('admin.gallery_editor.slug', 'Slug')) . '<input name="slug" value="' . e($gallery['slug']) . '" autocomplete="off" required><span class="muted">' . e(t('admin.gallery_editor.slug_help', 'Used in the public gallery URL.')) . '</span></label><label>' . e(t('admin.gallery_editor.folder_name', 'Folder name')) . '<input name="folder_name" value="' . e(gallery_folder_name_from_path((string) $gallery['folder_path'])) . '" autocomplete="off" required><span class="muted">' . e(t('admin.gallery_editor.folder_rename_help', 'Changing this renames the folder on disk.')) . '</span></label></div>';
    echo '<div class="admin-edit-card"><label>' . e(t('admin.gallery_editor.parent_gallery', 'Parent gallery')) . '<select name="parent_id"><option value="0">' . e(t('admin.gallery_editor.no_parent', 'No parent')) . '</option>' . gallery_parent_options($gallery) . '</select></label><label>' . e(t('admin.gallery_editor.sort_order', 'Sort order')) . '<input name="sort_order" type="number" value="' . (int) $gallery['sort_order'] . '"></label></div>';
    echo '<div class="admin-edit-card is-wide"><label>' . e(t('admin.gallery_editor.tags', 'Tags')) . '<input name="tags" value="' . e(tag_names_for_entity('gallery', (int) $gallery['id'])) . '" list="tag-suggestions" data-tag-input><span class="muted">' . e(t('admin.gallery_editor.tags_help', 'Separate tags with commas.')) . '</span></label></div>';
    echo '</div>';
    render_tag_datalist();
    render_admin_tab_panel('admin-edit-identity', (string) ob_get_clean(), $activeEditTab === 'admin-edit-identity');

    ob_start();
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.gallery_editor.access_kicker', 'Access')) . '</p><h2>' . e(t('admin.gallery_editor.visibility_and_protection', 'Visibility and protection')) . '</h2></div><p class="muted">' . e(t('admin.gallery_editor.access_help', 'Visibility decides discoverability. Passwords and generated links are optional on top of it.')) . '</p></div>';
    echo '<div class="admin-edit-card-grid">';
    echo '<div class="admin-edit-card"><label>' . e(t('admin.gallery_editor.visibility', 'Visibility')) . '<select name="visibility">' . visibility_options((string) $gallery['visibility']) . '</select></label><p class="muted">' . e(t('admin.gallery_editor.visibility_help', 'Public galleries are listed. Unpublished galleries are hidden but open from their normal URL. Private galleries are admin-only except for supported direct-token access.')) . '</p></div>';
    if ($accessReady) {
        // $newShareToken stores an intermediate value used by the surrounding gallery workflow.
        $newShareToken = (string) ($_SESSION['new_gallery_share_token_' . (int) $gallery['id']] ?? '');
        unset($_SESSION['new_gallery_share_token_' . (int) $gallery['id']]);
        // $currentAccessType stores an intermediate value used by the surrounding gallery workflow.
        $currentAccessType = 'normal';
        if ((string) ($gallery['access_mode'] ?? 'normal') === 'password' && !empty($gallery['access_password_hash'])) {
            // $currentAccessType stores an intermediate value used by the surrounding gallery workflow.
            $currentAccessType = 'password';
        }
        echo '<div class="admin-edit-card"><label>' . e(t('admin.gallery_editor.password_lock', 'Password lock')) . '<select name="access_type"><option value="normal"' . ($currentAccessType === 'normal' ? ' selected' : '') . '>' . e(t('admin.gallery_editor.no_password', 'No password')) . '</option><option value="password"' . ($currentAccessType === 'password' ? ' selected' : '') . '>' . e(t('admin.gallery_editor.require_password', 'Require password')) . '</option></select><span class="muted">' . e(t('admin.gallery_editor.password_lock_help', 'Password locking is independent of public, unpublished, or private visibility.')) . '</span></label><label>' . e(t('admin.gallery_editor.new_gallery_password', 'New gallery password')) . '<input name="access_password" type="password" autocomplete="new-password"><span class="muted">' . e(t('admin.gallery_editor.keep_password_help', 'Leave empty to keep the current gallery password.')) . '</span></label>';
        if (!empty($gallery['access_password_hash'])) {
            echo '<label class="checkbox-label"><input type="checkbox" name="clear_access_password" value="1"> Clear current gallery password</label>';
        }
        echo '</div>';
        echo '<div class="admin-edit-card is-wide"><label>' . e(t('admin.gallery_editor.share_link_expiry', 'Share link expiry')) . '<input name="access_token_expires_at" type="datetime-local" value="' . e(!empty($gallery['access_token_expires_at']) ? date('Y-m-d\TH:i', strtotime((string) $gallery['access_token_expires_at'])) : '') . '"><span class="muted">' . e(t('admin.gallery_editor.non_expiring_link_help', 'Leave empty for a non-expiring generated link.')) . '</span></label>';
        // $visibleShareToken stores an intermediate value used by the surrounding gallery workflow.
        $visibleShareToken = $newShareToken !== '' ? $newShareToken : gallery_share_token_for_admin($gallery);
        if ($visibleShareToken !== null && $visibleShareToken !== '') {
            // $shareLabel stores an intermediate value used by the surrounding gallery workflow.
            $shareLabel = $newShareToken !== '' ? 'Generated share link' : 'Active share link';
            echo '<label>' . $shareLabel . '<input readonly value="' . e(gallery_share_url((int) $gallery['id'], $visibleShareToken)) . '"></label>';
        } elseif (!empty($gallery['access_token_hash'])) {
            echo '<p class="muted">A share link is active' . (!empty($gallery['access_token_expires_at']) ? ' until ' . e((string) $gallery['access_token_expires_at']) : ' with no expiry') . ', but the original token cannot be displayed because it is stored as hash-only or cannot be decrypted on this server. Regenerate the link once to make a new copyable link visible here.</p>';
        } else {
            echo '<p class="muted">No share link is active.</p>';
        }
        echo '<div class="bulk-row"><button type="submit" class="secondary" name="access_action" value="generate_link">' . e(t('admin.gallery_editor.generate_regenerate_share_link', 'Generate/regenerate share link')) . '</button><button type="submit" class="secondary" name="access_action" value="revoke_link">' . e(t('admin.gallery_editor.revoke_share_link', 'Revoke share link')) . '</button></div><p class="muted">' . e(t('admin.gallery_editor.share_link_help', 'Generated direct links use the existing hash-token path. They remain useful for private galleries without making them appear in listings.')) . '</p></div>';
    } else {
        echo '<div class="notice">' . e(t('admin.gallery_editor.protected_settings_migration_hidden', 'Protected gallery settings are hidden until the v0.13 database migration is applied.')) . '</div>';
    }
    if (nsfw_guard_schema_ready()) {
        echo '<div class="admin-edit-card is-wide"><label class="checkbox-label"><input type="checkbox" name="nsfw_enabled" value="1"' . ((int) ($gallery['nsfw_enabled'] ?? 0) === 1 ? ' checked' : '') . '> ' . e(t('admin.gallery_editor.mark_nsfw', 'Mark as NSFW / 18+')) . '</label><p class="muted">' . e(t('admin.gallery_editor.nsfw_help', 'When enabled, this gallery and all subgalleries require an 18+ confirmation before anonymous visitors can view photos or media files. Before publishing NSFW content, make sure your hosting provider or web hosting terms allow it.')) . '</p></div>';
    } else {
        echo '<div class="admin-edit-card is-wide"><p class="muted">' . e(t('admin.gallery_editor.nsfw_migration_hidden', 'NSFW Guard controls will be available after the database migration is applied.')) . '</p></div>';
    }
    echo '</div>';
    render_admin_tab_panel('admin-edit-access', (string) ob_get_clean(), $activeEditTab === 'admin-edit-access');

    ob_start();
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.gallery_editor.display_kicker', 'Display')) . '</p><h2>' . e(t('admin.gallery_editor.gallery_behavior', 'Gallery behavior')) . '</h2></div><p class="muted">' . e(t('admin.gallery_editor.gallery_behavior_help', 'Feature toggles and grid overrides affecting this gallery branch.')) . '</p></div>';
    echo '<div class="admin-edit-card-grid">';
    if ($pictureGameReady) {
        echo '<div class="admin-edit-card"><label class="checkbox-label"><input type="checkbox" name="picture_game_enabled" value="1"' . ((int) ($gallery['picture_game_enabled'] ?? 0) === 1 ? ' checked' : '') . '> ' . e(t('admin.gallery_editor.enable_picture_game', 'Enable picture game for this gallery branch')) . '</label></div>';
    }
    if (gallery_voting_schema_ready()) {
        echo '<div class="admin-edit-card"><label class="checkbox-label"><input type="checkbox" name="voting_enabled" value="1"' . ((int) ($gallery['voting_enabled'] ?? 0) === 1 ? ' checked' : '') . '> ' . e(t('admin.gallery_editor.enable_image_voting', 'Enable image voting for this gallery')) . '</label><p class="muted">' . e(t('admin.gallery_editor.image_voting_help', 'When disabled, existing votes remain stored and visible, but vote arrows and vote submissions are blocked.')) . '</p></div>';
    }
    if (gallery_filename_display_schema_ready()) {
        echo '<div class="admin-edit-card"><label class="checkbox-label"><input type="checkbox" name="show_filenames" value="1"' . ((int) ($gallery['show_filenames'] ?? 0) === 1 ? ' checked' : '') . '> ' . e(t('admin.gallery_editor.show_file_names', 'Show file names')) . '</label><p class="muted">' . e(t('admin.gallery_editor.show_file_names_help', 'Disabled by default. Custom photo titles and descriptions are still shown; raw uploaded file names stay hidden unless this is enabled.')) . '</p></div>';
    } else {
        echo '<div class="admin-edit-card"><p class="muted">' . e(t('admin.gallery_editor.filename_display_migration_hidden', 'File name display control will be available after the database migration is applied.')) . '</p></div>';
    }
    if ($gpsMapReady) {
        echo '<div class="admin-edit-card"><label class="checkbox-label"><input type="checkbox" name="gps_map_enabled" value="1"' . ((int) ($gallery['gps_map_enabled'] ?? 0) === 1 ? ' checked' : '') . '> ' . e(t('admin.gallery_editor.enable_gps_maps', 'Enable EXIF GPS maps for this gallery branch')) . '</label><p class="muted">' . e(t('admin.gallery_editor.enable_gps_maps_help', 'When enabled here, this gallery and its subgalleries may show photo map pins and gallery maps for images with GPS EXIF coordinates.')) . '</p></div>';
    }
    if (gallery_description_layout_schema_ready()) {
        // $currentDescriptionLayout stores the optional value saved directly on this gallery.
        $currentDescriptionLayout = gallery_description_layout_storage_value($gallery['description_layout'] ?? null);
        // $effectiveDescriptionLayout stores the layout that visitors currently see for this gallery card.
        $effectiveDescriptionLayout = gallery_effective_description_layout($gallery);
        echo '<div class="admin-edit-card"><h3>' . e(t('admin.gallery_editor.description_layout_title', 'Gallery description format')) . '</h3><label>' . e(t('admin.gallery_editor.description_layout_label', 'Card layout')) . '<select name="description_layout"><option value="inherit"' . ($currentDescriptionLayout === null ? ' selected' : '') . '>' . e(t('admin.gallery_editor.description_layout_inherit', 'Inherit from Theme')) . '</option>';
        foreach (gallery_description_layout_options() as $descriptionLayoutOption) {
            echo '<option value="' . e($descriptionLayoutOption) . '"' . ($currentDescriptionLayout === $descriptionLayoutOption ? ' selected' : '') . '>' . e(gallery_description_layout_label($descriptionLayoutOption)) . '</option>';
        }
        echo '</select></label><p class="muted">' . e(t('admin.gallery_editor.description_layout_help', 'Current source: {source}. Effective layout: {layout}. Horizontal cards place the picture at the top, then title, date placeholder, tags, and a shortened Markdown-capable description.', ['source' => gallery_description_layout_source_label($gallery), 'layout' => gallery_description_layout_label($effectiveDescriptionLayout)])) . '</p></div>';
    } else {
        echo '<div class="admin-edit-card"><p class="muted">' . e(t('admin.gallery_editor.description_layout_migration_hidden', 'Gallery description format overrides will be available after the database migration is applied.')) . '</p></div>';
    }
    if (gallery_count_badge_schema_ready()) {
        // $currentCountBadgeVisibility stores the optional value saved directly on this gallery.
        $currentCountBadgeVisibility = gallery_count_badge_storage_value($gallery['count_badge_visibility'] ?? null) ?? 'inherit';
        // $effectiveCountBadgeEnabled stores the visible count badge state before any form edits.
        $effectiveCountBadgeEnabled = gallery_effective_count_badge_enabled($gallery);
        echo '<div class="admin-edit-card"><h3>' . e(t('admin.gallery_editor.count_badge_title', 'Contained-picture badge')) . '</h3><label>' . e(t('admin.gallery_editor.count_badge_label', 'Card badge')) . '<select name="count_badge_visibility">';
        foreach (gallery_count_badge_override_values() as $countBadgeOption) {
            echo '<option value="' . e($countBadgeOption) . '"' . ($currentCountBadgeVisibility === $countBadgeOption ? ' selected' : '') . '>' . e(gallery_count_badge_override_label($countBadgeOption)) . '</option>';
        }
        echo '</select></label><p class="muted">' . e(t('admin.gallery_editor.count_badge_help', 'Current source: {source}. Effective state: {state}. This controls the stacked-picture icon and contained-image number on gallery cards.', ['source' => gallery_count_badge_source_label($gallery), 'state' => gallery_count_badge_state_label($effectiveCountBadgeEnabled)])) . '</p></div>';
    } else {
        echo '<div class="admin-edit-card"><p class="muted">' . e(t('admin.gallery_editor.count_badge_migration_hidden', 'Contained-picture badge overrides will be available after the database migration is applied.')) . '</p></div>';
    }
    if (gallery_grid_schema_ready()) {
        // $galleryUsesCustomGrid stores whether this gallery row has its own display-grid override.
        $galleryUsesCustomGrid = gallery_grid_has_explicit_override($gallery);
        // $effectiveGridSettings stores the grid currently affecting this gallery before any form edits.
        $effectiveGridSettings = gallery_effective_grid_settings($gallery);
        // $gridColumns stores the form value. In inherit mode it previews the currently effective inherited/default value.
        $gridColumns = gallery_grid_form_columns($gallery);
        // $gridRows stores the form value. In inherit mode it previews the currently effective inherited/default value.
        $gridRows = gallery_grid_form_rows($gallery);
        echo '<div class="admin-edit-card is-wide"><h3>' . e(t('admin.gallery_editor.display_grid', 'Display grid')) . '</h3><label class="checkbox-label"><input type="checkbox" name="grid_override_enabled" value="1" data-gallery-grid-override-enabled' . ($galleryUsesCustomGrid ? ' checked' : '') . '> ' . e(t('admin.gallery_editor.use_custom_grid', 'Use a custom grid for this gallery')) . '</label><div class="admin-edit-range-grid"><label>' . e(t('admin.gallery_editor.columns', 'Columns')) . ' <span class="muted" data-gallery-grid-columns-display>' . (int) $gridColumns . '</span><input type="range" name="grid_columns" min="1" max="' . CMS_PAGINATION_MAX_COLUMNS . '" value="' . (int) $gridColumns . '" data-gallery-grid-columns></label><label>' . e(t('admin.gallery_editor.rows', 'Rows')) . ' <span class="muted" data-gallery-grid-rows-display>' . (int) $gridRows . '</span><input type="range" name="grid_rows" min="1" max="' . CMS_PAGINATION_MAX_ROWS . '" value="' . (int) $gridRows . '" data-gallery-grid-rows></label></div><label class="checkbox-label"><input type="checkbox" name="grid_use_for_subgalleries" value="1"' . ((int) ($gallery['grid_use_for_subgalleries'] ?? 1) === 1 ? ' checked' : '') . '> ' . e(t('admin.gallery_editor.use_for_subgalleries', 'Use for subgalleries')) . '</label><p class="muted">' . e(t('admin.gallery_editor.current_source', 'Current source: {source}.', ['source' => (string) ($effectiveGridSettings['grid_source'] ?? 'global')])) . ' ' . e(t('admin.gallery_editor.grid_inheritance_help', 'If this gallery does not use a custom grid, it inherits the nearest parent grid that allows subgallery inheritance, otherwise it uses the Theme fallback.')) . '</p></div>';
    } else {
        echo '<div class="admin-edit-card is-wide"><p class="muted">' . e(t('admin.gallery_editor.grid_migration_hidden', 'Gallery display-grid overrides will be available after the database migration is applied.')) . '</p></div>';
    }
    if (thumbnail_bounds_schema_ready()) {
        echo '<div class="admin-edit-card is-wide">';
        render_admin_thumbnail_bound_slider('gallery_thumbnail', isset($gallery['thumbnail_min_size']) ? (int) $gallery['thumbnail_min_size'] : null, isset($gallery['thumbnail_max_size']) ? (int) $gallery['thumbnail_max_size'] : null, t('admin.gallery_editor.thumbnail_quality_bounds', 'Responsive thumbnail quality bounds'), t('admin.gallery_editor.thumbnail_quality_bounds_help', 'Optional guardrails for automatic thumbnail selection. Leave both sides on Auto to keep the current behavior.'));
        echo '<label class="checkbox-label"><input type="checkbox" name="gallery_thumbnail_bounds_recursive" value="1"> ' . e(t('admin.gallery_editor.save_bounds_recursively', 'Save these bounds recursively to subgalleries')) . '</label>';
        echo '<p class="muted">' . e(t('admin.gallery_editor.recursive_bounds_help', 'Recursive save is intentionally off by default. It copies the selected bounds to every descendant gallery, but does not change individual photo overrides.')) . '</p>';
        echo '</div>';
    } else {
        echo '<div class="admin-edit-card is-wide"><p class="muted">' . e(t('admin.gallery_editor.thumbnail_bounds_migration_hidden', 'Thumbnail quality bounds will be available after the database migration is applied.')) . '</p></div>';
    }
    echo '</div>';
    render_admin_tab_panel('admin-edit-display', (string) ob_get_clean(), $activeEditTab === 'admin-edit-display');

    ob_start();
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.gallery_editor.media_kicker', 'Media')) . '</p><h2>' . e(t('admin.gallery_editor.media_title', 'Thumbnail, branding, and background')) . '</h2></div><p class="muted">' . e(t('admin.gallery_editor.media_help', 'Optional visual assets override theme fallbacks only for this gallery.')) . '</p></div>';
    echo '<div class="admin-edit-card-grid">';
    echo '<div class="admin-edit-card is-wide"><label>' . e(t('admin.gallery_editor.title_picture', t('admin.gallery_editor.title_picture_current', 'Title picture'))) . '<select name="cover_image_id"><option value="0">' . e(t('admin.gallery_editor.automatic', 'Automatic')) . '</option>' . gallery_cover_options((int) $gallery['id'], (int) ($gallery['cover_image_id'] ?? 0), true) . '</select><span class="muted">' . e(t('admin.gallery_editor.includes_subgallery_images', 'Includes images from subgalleries.')) . '</span></label>';
    if (gallery_cover_asset_schema_ready()) {
        echo '<label>' . e(t('admin.gallery_editor.upload_gallery_thumbnail', 'Upload gallery thumbnail')) . '<input type="file" name="cover_upload" accept="image/*"><span class="muted">' . e(t('admin.gallery_editor.gallery_thumbnail_upload_help', 'This is stored separately from gallery images.')) . '</span></label>';
    } else {
        echo '<p class="muted">' . e(t('admin.gallery_editor.gallery_thumbnail_migration_hidden', 'Uploadable gallery thumbnails will be available after the gallery thumbnail migration is applied.')) . '</p>';
    }
    echo '</div>';
    echo '<div class="admin-edit-card is-wide">';
    render_admin_gallery_branding_fields($gallery);
    echo '</div>';
    echo '<div class="admin-edit-card is-wide">';
    if (gallery_background_source_schema_ready()) {
        // $backgroundSource stores an intermediate value used by the surrounding gallery workflow.
        $backgroundSource = gallery_background_source($gallery);
        echo '<label>' . e(t('admin.gallery_editor.background_source', 'Background source')) . '<select name="background_source"><option value=""' . ($backgroundSource === null ? ' selected' : '') . '>' . e(t('admin.gallery_editor.use_theme_background', 'Use theme background')) . '</option><option value="upload"' . ($backgroundSource === 'upload' ? ' selected' : '') . '>' . e(t('admin.gallery_editor.upload_new_image', 'Upload new image')) . '</option><option value="existing"' . ($backgroundSource === 'existing' ? ' selected' : '') . '>' . e(t('admin.gallery_editor.pick_existing_gallery_images', 'Pick from existing gallery images')) . '</option><option value="collage"' . ($backgroundSource === 'collage' ? ' selected' : '') . '>' . e(t('admin.gallery_editor.generate_collage_public', 'Generate collage from public galleries')) . '</option></select><span class="muted">' . e(t('admin.gallery_editor.background_source_help', 'If unset, the gallery inherits the Theme background.')) . '</span></label>';
    } else {
        echo '<p class="muted">' . e(t('admin.gallery_editor.background_migration_hidden', 'Background source selection will be available after the background migration is applied.')) . '</p>';
    }
    echo '</div></div>';
    render_admin_tab_panel('admin-edit-media', (string) ob_get_clean(), $activeEditTab === 'admin-edit-media');

    echo '<div class="admin-edit-gallery-savebar"><button type="submit">' . e(t('admin.gallery_editor.save_gallery', 'Save gallery')) . '</button><span class="muted">' . e(t('admin.gallery_editor.savebar_help', 'Saves all settings from Identity, Access, Display, and Media.')) . '</span></div>';
    echo '</form>';

    ob_start();
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.gallery_editor.tab_images', 'Images')) . '</p><h2>' . e(t('admin.gallery_editor.images_title', 'Photos and ordering')) . '</h2></div><div class="admin-hero-actions"><a class="button" href="' . e(url_for('admin_upload', ['gallery_id' => $gallery['id']])) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="upload" data-admin-side-panel-kicker="' . e(t('admin.gallery_editor.upload_workflow', 'Upload workflow')) . '" data-admin-side-panel-title="' . e(t('admin.gallery_editor.upload_photos', 'Upload photos')) . '" data-gallery-side-panel-url="' . e(url_for('admin_upload', ['gallery_id' => $gallery['id'], 'panel' => 1])) . '">' . e(t('admin.gallery_editor.upload_photos_here', 'Upload photos here')) . '</a><form method="post" action="' . e(url_for('admin_scan_images')) . '">' . csrf_field() . '<input type="hidden" name="gallery_id" value="' . (int) $gallery['id'] . '"><button type="submit" class="secondary">' . e(t('admin.gallery_editor.scan_import_images', 'Scan/import images')) . '</button></form></div></div>';
    echo '<form method="post" action="' . e(url_for('admin_bulk_images')) . '" data-admin-image-bulk-form>' . csrf_field();
    echo '<input type="hidden" name="gallery_id" value="' . (int) $gallery['id'] . '">';
    echo '<input type="hidden" name="return_tab" value="admin-edit-images">';
    render_admin_image_bulk_toolbar($gallery);
    echo '<div class="admin-image-order-toolbar" data-admin-image-order-toolbar data-reorder-url="' . e(url_for('admin_reorder_images')) . '"><p class="muted">' . e(t('admin.gallery_editor.drag_photos_help', 'Drag photos by the handle to change their gallery order, or click the Name column header to sort the gallery by filename. Each change is saved immediately.')) . '</p><span class="admin-image-order-status" data-admin-image-order-status aria-live="polite">' . e(t('admin.gallery_editor.order_unchanged', 'Order unchanged.')) . '</span></div>';
    echo '<table class="admin-image-order-table" data-admin-image-order-table><thead><tr><th>' . e(t('admin.gallery_editor.move', 'Move')) . '</th><th>' . e(t('admin.gallery_editor.select', 'Select')) . '</th><th>' . e(t('admin.gallery_editor.preview', 'Preview')) . '</th><th aria-sort="none"><button type="button" class="admin-image-name-sort" data-admin-image-name-sort data-sort-direction="asc" aria-label="' . e(t('admin.gallery_editor.sort_photos_a_z', 'Sort photos by name from A to Z')) . '">' . e(t('admin.gallery_editor.name', 'Name')) . ' <span aria-hidden="true">↕</span></button></th><th title="' . e(t('admin.gallery_editor.file_names_shown', 'File names shown')) . '">N</th><th>' . e(t('admin.gallery_editor.status', 'Status')) . '</th><th>' . e(t('admin.gallery_editor.cover', 'Cover')) . '</th><th>' . e(t('admin.gallery_editor.actions', 'Actions')) . '</th></tr></thead><tbody>';
    foreach ($images as $image) {
        // Variable $isCover stores this steps working value.
        $isCover = (int) ($gallery['cover_image_id'] ?? 0) === (int) $image['id'];
        echo '<tr data-admin-image-order-row data-image-id="' . (int) $image['id'] . '" data-image-name="' . e((string) $image['relative_path']) . '"><td class="admin-image-order-cell"><span class="admin-image-drag-handle" data-admin-image-drag-handle role="button" tabindex="0" aria-label="Move ' . e((string) $image['relative_path']) . '" title="Drag to reorder">↕</span></td><td><input type="checkbox" name="image_ids[]" value="' . (int) $image['id'] . '"></td>';
        echo '<td><img class="admin-thumb" decoding="async" loading="lazy" src="' . e(thumbnail_url($image, 300)) . '" alt=""></td>';
        echo '<td data-admin-image-name-cell>' . e($image['relative_path']) . '</td><td>' . render_admin_feature_flag(gallery_shows_filenames($gallery), '✓', 'File names are shown for this gallery') . '</td><td>' . e($image['visibility']) . '</td><td data-admin-image-cover-cell>' . ($isCover ? t('admin.gallery_editor.title_picture_current', 'Title picture') : '') . '</td><td><a href="' . e(url_for('admin_edit_image', ['id' => $image['id']])) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="image-edit" data-admin-side-panel-kicker="' . e(t('admin.gallery_editor.photo_editor', 'Photo editor')) . '" data-admin-side-panel-title="' . e(t('admin.gallery_editor.edit_photo', 'Edit photo')) . '" data-gallery-side-panel-url="' . e(url_for('admin_edit_image', ['id' => $image['id'], 'panel' => 1])) . '">' . e(t('admin.gallery_editor.edit', 'Edit')) . '</a> <button type="submit" class="secondary danger inline-admin-action" name="action" value="delete:' . (int) $image['id'] . '" data-admin-image-delete-single data-image-id="' . (int) $image['id'] . '" data-image-name="' . e((string) $image['relative_path']) . '">' . e(t('admin.gallery_editor.delete', 'Delete')) . '</button></td></tr>';
    }
    echo '</tbody></table></form>';
    render_admin_tab_panel('admin-edit-images', (string) ob_get_clean(), $activeEditTab === 'admin-edit-images');
    render_admin_image_reorder_script();
    render_admin_devmode_panel();
    render_footer();
}

/**
 * Render the admin image bulk toolbar and guided move workflow.
 *
 * The standard select keeps existing bulk behavior intact. Moving photos uses a
 * staged panel so admins first choose whether the target is an existing gallery
 * or a new child gallery, then confirm the exact physical move.
 */
function render_admin_image_bulk_toolbar(array $gallery): void
{
    // $galleryId stores the gallery currently being edited.
    $galleryId = (int) $gallery['id'];
    // $destinationOptions stores all galleries except the current source gallery.
    $destinationOptions = gallery_options_for_select(0, $galleryId);
    // $newGalleryParentOptions stores the selectable parent hierarchy for move-to-new-gallery actions.
    $newGalleryParentOptions = gallery_options_for_select($galleryId);

    echo '<div class="bulk-row admin-edit-image-toolbar" data-admin-image-move-toolbar>';
    echo '<div class="admin-image-bulk-primary">';
    echo '<label class="admin-image-select-all"><input type="checkbox" data-select-all="image_ids[]"> ' . e(t('admin.gallery_editor.select_all_images')) . '</label>';
    echo '<span class="admin-image-selection-count" data-admin-image-selected-count>' . e(t('admin.gallery_editor.selected_count_zero')) . '</span>';
    echo '<label>' . e(t('admin.gallery_editor.bulk_action')) . '<select name="action" data-admin-image-bulk-action><option value="public">' . e(t('admin.gallery_editor.set_public')) . '</option><option value="draft">' . e(t('admin.gallery_editor.set_draft')) . '</option><option value="private">' . e(t('admin.gallery_editor.set_private')) . '</option><option value="cover">' . e(t('admin.gallery_editor.set_as_title_picture')) . '</option><option value="thumbs">' . e(t('admin.gallery_editor.create_thumbnails')) . '</option><option value="nsfw_on">' . e(t('admin.gallery_editor.mark_nsfw')) . '</option><option value="nsfw_off">' . e(t('admin.gallery_editor.remove_nsfw')) . '</option><option value="delete">' . e(t('admin.gallery_editor.delete_selected_photos')) . '</option><option value="move_existing" hidden>' . e(t('admin.gallery_editor.move_existing')) . '</option><option value="move_new" hidden>' . e(t('admin.gallery_editor.move_new')) . '</option></select></label>';
    echo '<button type="submit">' . e(t('admin.gallery_editor.apply_to_selected')) . '</button>';
    echo '<button type="button" class="secondary" data-admin-image-move-open>' . e(t('admin.gallery_editor.move_selected_photos')) . '</button>';
    echo '<button type="submit" class="secondary" name="thumbnail_gallery_id" value="' . $galleryId . '" formaction="' . e(url_for('admin_create_thumbnails')) . '">' . e(t('admin.gallery_editor.create_gallery_thumbnails')) . '</button>';
    echo '</div>';

    echo '<section class="admin-image-move-panel" data-admin-image-move-panel hidden aria-label="' . e(t('admin.gallery_editor.move_selected_photos')) . '">';
    echo '<div class="admin-image-move-panel-head"><div class="admin-image-move-title"><span class="admin-image-move-title-icon" aria-hidden="true">⇄</span><div><h3>' . e(t('admin.gallery_editor.move_selected_photos')) . '</h3><span class="admin-image-move-count-pill" data-admin-image-selected-count>' . e(t('admin.gallery_editor.selected_count_zero')) . '</span></div></div><button type="button" class="admin-image-move-close" data-admin-image-move-cancel aria-label="' . e(t('admin.gallery_editor.close_move_panel')) . '">×</button></div>';
    echo '<div class="admin-image-move-steps" aria-label="' . e(t('admin.gallery_editor.move_progress')) . '">';
    echo '<div class="admin-image-move-step is-active" data-admin-image-move-step="action"><span>1</span><div><strong>' . e(t('admin.gallery_editor.step_choose_action')) . '</strong><p>' . e(t('admin.gallery_editor.step_choose_action_help')) . '</p></div></div>';
    echo '<div class="admin-image-move-step" data-admin-image-move-step="target"><span>2</span><div><strong>' . e(t('admin.gallery_editor.step_target')) . '</strong><p>' . e(t('admin.gallery_editor.step_target_help')) . '</p></div></div>';
    echo '<div class="admin-image-move-step" data-admin-image-move-step="confirm"><span>3</span><div><strong>' . e(t('admin.gallery_editor.step_confirm')) . '</strong><p>' . e(t('admin.gallery_editor.step_confirm_help')) . '</p></div></div>';
    echo '<div class="admin-image-move-step" data-admin-image-move-step="complete"><span>4</span><div><strong>' . e(t('admin.gallery_editor.step_complete')) . '</strong><p>' . e(t('admin.gallery_editor.step_complete_help')) . '</p></div></div>';
    echo '</div>';
    echo '<p class="admin-image-move-lead">' . e(t('admin.gallery_editor.move_lead')) . '</p>';
    echo '<div class="admin-image-move-choice-grid" role="group" aria-label="' . e(t('admin.gallery_editor.move_action')) . '">';
    echo '<button type="button" class="admin-image-move-choice" data-admin-image-move-choice="move_existing" aria-pressed="false"><span class="admin-image-move-choice-icon" aria-hidden="true">▭</span><span class="admin-image-move-choice-copy"><strong>' . e(t('admin.gallery_editor.move_existing')) . '</strong><small>' . e(t('admin.gallery_editor.move_existing_help')) . '</small></span><span class="admin-image-move-choice-radio" aria-hidden="true"></span></button>';
    echo '<button type="button" class="admin-image-move-choice" data-admin-image-move-choice="move_new" aria-pressed="false"><span class="admin-image-move-choice-icon" aria-hidden="true">▭+</span><span class="admin-image-move-choice-copy"><strong>' . e(t('admin.gallery_editor.move_new')) . '</strong><small>' . e(t('admin.gallery_editor.move_new_help')) . '</small></span><span class="admin-image-move-choice-radio" aria-hidden="true"></span></button>';
    echo '</div>';
    echo '<div class="admin-image-move-targets">';
    echo '<label class="admin-image-move-target" data-admin-image-move-existing hidden><span>' . e(t('admin.gallery_editor.destination_gallery')) . '</span><select name="destination_gallery_id"><option value="0">' . e(t('admin.gallery_editor.choose_existing_gallery')) . '</option>' . $destinationOptions . '</select><small><span aria-hidden="true">ⓘ</span> ' . e(t('admin.gallery_editor.destination_help')) . '</small></label>';
    echo '<div class="admin-image-move-target admin-image-move-new" data-admin-image-move-new hidden><label><span>' . e(t('admin.gallery_editor.parent_gallery')) . '</span><select name="new_gallery_parent_id"><option value="0">' . e(t('admin.gallery_editor.no_parent')) . '</option>' . $newGalleryParentOptions . '</select></label><label><span>' . e(t('admin.gallery_editor.new_gallery_title')) . '</span><input type="text" name="new_gallery_title" placeholder="' . e(t('admin.gallery_editor.example_gallery_title')) . '"></label><label><span>' . e(t('admin.gallery_editor.optional_folder_slug')) . '</span><input type="text" name="new_gallery_folder_name" placeholder="' . e(t('admin.gallery_editor.derive_from_title')) . '"></label><small><span aria-hidden="true">ⓘ</span> ' . e(t('admin.gallery_editor.new_gallery_move_help')) . '</small></div>';
    echo '</div>';
    echo '<div class="admin-image-move-confirm"><button type="button" class="secondary admin-image-move-cancel-bottom" data-admin-image-move-cancel>' . e(t('admin.gallery_editor.cancel')) . '</button><div><strong>' . e(t('admin.gallery_editor.move_summary')) . '</strong><p data-admin-image-move-summary>' . e(t('admin.gallery_editor.move_summary_empty')) . '</p></div><button type="submit" name="move_images" value="1" data-admin-image-move-submit disabled>' . e(t('admin.gallery_editor.move_selected_now')) . '</button></div>';
    echo '</section>';
    echo '</div>';
}

/**
 * Render upload, replace, and remove controls for optional gallery branding images.
 *
 * Banner replaces the visible public title text, logo is supplementary, and the
 * separator acts as a visual divider below the public title area.
 */
function render_admin_gallery_branding_fields(array $gallery): void
{
    if (!gallery_branding_schema_ready()) {
        echo '<p class="muted">Gallery branding assets will be available after the branding migration is applied.</p>';
        return;
    }

    echo '<fieldset class="form-grid admin-branding-assets"><legend>' . e(t('admin.gallery_editor.gallery_branding', 'Gallery branding')) . '</legend>';
    echo '<p class="muted">All branding images are optional. Existing galleries render exactly as before until one of these assets is uploaded.</p>';
    foreach (gallery_branding_asset_types() as $kind => $definition) {
        // $label stores the user-facing asset label.
        $label = (string) $definition['label'];
        // $description stores concise guidance for the current asset control.
        $description = (string) $definition['description'];
        // $assetUrl stores the currently configured asset preview URL for admins.
        $assetUrl = gallery_branding_asset_url($gallery, (string) $kind, false);
        echo '<div class="admin-branding-asset">';
        echo '<div class="admin-branding-copy"><strong>' . e($label) . '</strong><span class="muted">' . e($description) . '</span></div>';
        if ($assetUrl !== '') {
            echo '<div class="admin-branding-current"><img class="admin-branding-preview admin-branding-preview-' . e((string) $kind) . '" src="' . e($assetUrl) . '" alt=""><label class="checkbox-label"><input type="checkbox" name="remove_branding_' . e((string) $kind) . '" value="1"> Remove current ' . e(strtolower($label)) . '</label></div>';
        } else {
            echo '<p class="muted">No ' . e(strtolower($label)) . ' is configured.</p>';
        }
        echo '<label>Upload or replace ' . e(strtolower($label)) . '<input type="file" name="branding_' . e((string) $kind) . '_upload" accept="image/jpeg,image/png,image/gif,image/webp"><span class="muted">Accepted formats: JPG, PNG, GIF, WebP. Maximum size: 8 MB.</span></label>';
        echo '</div>';
    }
    echo '</fieldset>';
}

/**
 * Renders the Admin edit-gallery image reorder controller directly next to the
 * table it controls.
 *
 * The project-wide gallery.js file still contains public gallery behavior and
 * other Admin helpers, but row sorting is deliberately initialized inline here.
 * This avoids the failure mode seen in Chrome where a table-row drag handle is
 * styled correctly yet the external delegated handler is not the active handler
 * receiving the first mouse movement. The script uses a custom mouse/pointer
 * fallback instead of HTML5 drag-and-drop, so it does not depend on browser
 * drag images, table-row draggable support, or dragover/drop acceptance rules.
 */
function render_admin_image_reorder_script(): void
{
    echo <<<'HTML'
<script>
(function () {
    'use strict';

    /**
     * Finds the single Admin image ordering table on the edit-gallery page.
     *
     * @returns {HTMLTableElement|null} Reorder table, or null on other pages.
     */
    function findImageOrderTable() {
        return document.querySelector('[data-admin-image-order-table]');
    }

    /**
     * Updates the visible reorder status text without throwing on older markup.
     *
     * @param {string} message Message displayed to the gallery administrator.
     * @param {string} state Small state token used by CSS for color feedback.
     * @returns {void}
     */
    function setImageOrderStatus(message, state) {
        var status = document.querySelector('[data-admin-image-order-status]');
        if (!status) {
            return;
        }
        status.textContent = message;
        status.dataset.state = state;
    }

    /**
     * Returns the current visual image id order from the table body.
     *
     * @param {HTMLTableSectionElement} tableBody Body containing image rows.
     * @returns {string[]} Ordered image ids as strings for JSON submission.
     */
    function readImageOrder(tableBody) {
        return Array.prototype.slice.call(tableBody.querySelectorAll('[data-admin-image-order-row]'))
            .map(function (row) {
                return row.dataset.imageId || '';
            })
            .filter(function (imageId) {
                return imageId !== '';
            });
    }

    /**
     * Builds a floating copy of the row so movement is visible immediately.
     *
     * @param {HTMLTableRowElement} sourceRow Row being moved.
     * @returns {HTMLTableElement} Fixed-position table containing cloned row.
     */
    function buildImageOrderGhost(sourceRow) {
        var sourceBox = sourceRow.getBoundingClientRect();
        var ghostTable = document.createElement('table');
        var ghostBody = document.createElement('tbody');
        var ghostRow = sourceRow.cloneNode(true);
        var sourceCells = Array.prototype.slice.call(sourceRow.children);
        var ghostCells = Array.prototype.slice.call(ghostRow.children);

        sourceCells.forEach(function (cell, index) {
            if (ghostCells[index]) {
                ghostCells[index].style.width = cell.getBoundingClientRect().width + 'px';
            }
        });

        ghostRow.removeAttribute('data-admin-image-order-row');
        ghostRow.querySelectorAll('[name]').forEach(function (field) {
            field.removeAttribute('name');
        });

        ghostTable.className = 'admin-image-order-ghost';
        ghostTable.style.left = sourceBox.left + 'px';
        ghostTable.style.width = sourceBox.width + 'px';
        ghostTable.appendChild(ghostBody);
        ghostBody.appendChild(ghostRow);
        document.body.appendChild(ghostTable);
        return ghostTable;
    }

    /**
     * Creates the placeholder row that marks where the real row will be dropped.
     *
     * @param {HTMLTableRowElement} sourceRow Row being moved.
     * @returns {HTMLTableRowElement} Placeholder row with matching height.
     */
    function buildImageOrderPlaceholder(sourceRow) {
        var placeholderRow = document.createElement('tr');
        var placeholderCell = document.createElement('td');
        placeholderRow.className = 'admin-image-order-placeholder';
        placeholderRow.setAttribute('aria-hidden', 'true');
        placeholderCell.colSpan = Math.max(1, sourceRow.children.length);
        placeholderCell.style.height = sourceRow.getBoundingClientRect().height + 'px';
        placeholderRow.appendChild(placeholderCell);
        return placeholderRow;
    }

    /**
     * Locates the table row whose midpoint is below the current pointer.
     *
     * @param {HTMLTableSectionElement} tableBody Body containing sortable rows.
     * @param {number} pointerY Current pointer Y coordinate in viewport space.
     * @returns {HTMLTableRowElement|null} Row to insert before, or null to append.
     */
    function findImageOrderInsertionRow(tableBody, pointerY) {
        var rows = Array.prototype.slice.call(tableBody.querySelectorAll('[data-admin-image-order-row]:not(.is-reorder-hidden)'));
        var closestOffset = Number.NEGATIVE_INFINITY;
        var closestRow = null;

        rows.forEach(function (row) {
            var rowBox = row.getBoundingClientRect();
            var offset = pointerY - rowBox.top - (rowBox.height / 2);
            if (offset < 0 && offset > closestOffset) {
                closestOffset = offset;
                closestRow = row;
            }
        });

        return closestRow;
    }

    /**
     * Persists the current table order through the dedicated PHP endpoint.
     *
     * @param {HTMLTableSectionElement} tableBody Body containing ordered image rows.
     * @param {HTMLFormElement} form Existing bulk form containing CSRF and gallery id.
     * @param {string} reorderUrl Endpoint generated by PHP for image sorting.
     * @returns {Promise<void>} Completes after the request succeeds or fails.
     */
    function saveImageOrder(tableBody, form, reorderUrl) {
        var csrfInput = form.querySelector('input[name="csrf_token"]');
        var galleryInput = form.querySelector('input[name="gallery_id"]');
        var bodyData = new FormData();

        if (!csrfInput || !galleryInput || !reorderUrl) {
            setImageOrderStatus('Image order could not be saved because the form metadata is missing.', 'error');
            return Promise.resolve();
        }

        bodyData.set('csrf_token', csrfInput.value);
        bodyData.set('gallery_id', galleryInput.value);
        bodyData.set('image_order', JSON.stringify(readImageOrder(tableBody)));
        bodyData.set('ajax');
        setImageOrderStatus('Saving new image order...', 'saving');

        return fetch(reorderUrl, {
            method: 'POST',
            body: bodyData,
            headers: {'Accept': 'application/json'}
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('The server rejected the reorder request.');
            }
            return response.json();
        }).then(function (result) {
            if (!result.ok) {
                throw new Error(result.message || 'Image order could not be saved.');
            }
            setImageOrderStatus(result.message || 'Image order saved.', 'saved');
        }).catch(function (error) {
            setImageOrderStatus(error.message || 'Image order could not be saved.', 'error');
        });
    }

    /**
     * Returns a human-comparable image name for a sortable table row.
     *
     * The PHP renderer stores the raw relative path in data-image-name so the
     * sorting logic is not forced to parse visible text. The cell text fallback
     * keeps the feature usable if older cached markup is present during an
     * update or while a browser has a stale admin page open.
     *
     * @param {HTMLTableRowElement} row Image row rendered by the edit-gallery table.
     * @returns {string} Name used for locale-aware filename sorting.
     */
    function readSortableImageName(row) {
        var fallbackCell = row.querySelector('[data-admin-image-name-cell]');
        return (row.dataset.imageName || (fallbackCell ? fallbackCell.textContent : '') || '').trim();
    }

    /**
     * Updates the Name header to describe the next click direction accurately.
     *
     * @param {HTMLButtonElement} sortButton Header button that starts name sorting.
     * @param {string} nextDirection Direction that the next click will apply.
     * @param {string} currentDirection Direction currently represented by the table.
     * @returns {void}
     */
    function updateNameSortHeader(sortButton, nextDirection, currentDirection) {
        var sortHeader = sortButton.closest('th');
        var arrow = sortButton.querySelector('[aria-hidden="true"]');
        sortButton.dataset.sortDirection = nextDirection;
        sortButton.setAttribute('aria-label', nextDirection === 'asc' ? 'Sort photos by name from A to Z' : 'Sort photos by name from Z to A');
        if (sortHeader) {
            sortHeader.setAttribute('aria-sort', currentDirection === 'asc' ? 'ascending' : 'descending');
        }
        if (arrow) {
            arrow.textContent = currentDirection === 'asc' ? '↑' : '↓';
        }
    }

    /**
     * Sorts the current image rows by filename and persists the resulting order.
     *
     * The same reorder endpoint is used as the drag-and-drop path. That keeps
     * all validation in one server-side place: gallery id validation, CSRF
     * verification, exact image-set comparison, transactional sort_order writes,
     * and admin logging are identical for manual and automatic ordering.
     *
     * @param {MouseEvent} clickEvent Click event from the Name header button.
     * @returns {void}
     */
    function handleNameSortClick(clickEvent) {
        var sortButton = clickEvent.target.closest('[data-admin-image-name-sort]');
        var table = findImageOrderTable();
        var toolbar = document.querySelector('[data-admin-image-order-toolbar]');
        var form = document.querySelector('[data-admin-image-bulk-form]');
        var tableBody = table ? table.querySelector('tbody') : null;
        var rows;
        var direction;
        var multiplier;
        var collator;

        if (!sortButton || !table || !toolbar || !form || !tableBody || window.__adminImageOrderDragActive) {
            return;
        }

        clickEvent.preventDefault();
        clickEvent.stopPropagation();

        rows = Array.prototype.slice.call(tableBody.querySelectorAll('[data-admin-image-order-row]'));
        if (rows.length < 2) {
            setImageOrderStatus('There is only one image, so sorting is not needed.', 'idle');
            return;
        }

        direction = sortButton.dataset.sortDirection === 'desc' ? 'desc' : 'asc';
        multiplier = direction === 'asc' ? 1 : -1;
        collator = new Intl.Collator(undefined, {numeric: true, sensitivity: 'base'});

        rows.map(function (row, index) {
            return {row: row, index: index, name: readSortableImageName(row)};
        }).sort(function (left, right) {
            var compared = collator.compare(left.name, right.name);
            if (compared !== 0) {
                return compared * multiplier;
            }
            return left.index - right.index;
        }).forEach(function (entry) {
            tableBody.appendChild(entry.row);
        });

        updateNameSortHeader(sortButton, direction === 'asc' ? 'desc' : 'asc', direction);
        saveImageOrder(tableBody, form, toolbar.dataset.reorderUrl || '');
    }

    /**
     * Starts the fallback sorter from the first captured mouse or pointer press.
     *
     * @param {MouseEvent|PointerEvent} startEvent Original press event on the handle.
     * @returns {void}
     */
    function startImageOrderDrag(startEvent) {
        var handle = startEvent.target.closest('[data-admin-image-drag-handle]');
        var table = findImageOrderTable();
        var toolbar = document.querySelector('[data-admin-image-order-toolbar]');
        var form = document.querySelector('[data-admin-image-bulk-form]');
        var row = handle ? handle.closest('[data-admin-image-order-row]') : null;
        var tableBody = table ? table.querySelector('tbody') : null;
        var originalIndex;
        var pointerOffsetY;
        var ghostTable;
        var placeholderRow;
        var active = true;

        if (!handle || !table || !toolbar || !form || !row || !tableBody || startEvent.button !== 0 || window.__adminImageOrderDragActive) {
            return;
        }

        window.__adminImageOrderDragActive = true;
        startEvent.preventDefault();
        startEvent.stopPropagation();
        if (typeof startEvent.stopImmediatePropagation === 'function') {
            startEvent.stopImmediatePropagation();
        }

        originalIndex = Array.prototype.slice.call(tableBody.querySelectorAll('[data-admin-image-order-row]')).indexOf(row);
        pointerOffsetY = startEvent.clientY - row.getBoundingClientRect().top;
        ghostTable = buildImageOrderGhost(row);
        placeholderRow = buildImageOrderPlaceholder(row);

        tableBody.insertBefore(placeholderRow, row.nextSibling);
        row.classList.add('is-reorder-hidden');
        handle.classList.add('is-dragging');
        document.body.classList.add('admin-image-order-active');
        setImageOrderStatus('Dragging. Release the mouse to save the new position.', 'dragging');

        /**
         * Moves the ghost and placeholder to the pointer position.
         *
         * @param {MouseEvent|PointerEvent} moveEvent Movement event captured on document.
         * @returns {void}
         */
        function moveDrag(moveEvent) {
            var beforeRow;
            if (!active) {
                return;
            }
            moveEvent.preventDefault();
            ghostTable.style.top = (moveEvent.clientY - pointerOffsetY) + 'px';
            beforeRow = findImageOrderInsertionRow(tableBody, moveEvent.clientY);
            if (beforeRow) {
                tableBody.insertBefore(placeholderRow, beforeRow);
            } else {
                tableBody.appendChild(placeholderRow);
            }
        }

        /**
         * Removes all temporary drag state and optionally commits the row move.
         *
         * @param {boolean} commit Whether the row should be inserted at placeholder.
         * @returns {HTMLTableRowElement} The moved row.
         */
        function cleanupDrag(commit) {
            active = false;
            window.__adminImageOrderDragActive = false;
            document.removeEventListener('mousemove', moveDrag, true);
            document.removeEventListener('mouseup', finishDrag, true);
            document.removeEventListener('pointermove', moveDrag, true);
            document.removeEventListener('pointerup', finishDrag, true);
            document.removeEventListener('pointercancel', cancelDrag, true);
            document.removeEventListener('keydown', handleKeydown, true);

            if (commit && placeholderRow.parentNode === tableBody) {
                tableBody.insertBefore(row, placeholderRow);
            }
            row.classList.remove('is-reorder-hidden');
            handle.classList.remove('is-dragging');
            placeholderRow.remove();
            ghostTable.remove();
            document.body.classList.remove('admin-image-order-active');
            return row;
        }

        /**
         * Commits the new position and saves it when the row actually moved.
         *
         * @param {MouseEvent|PointerEvent} finishEvent Release event captured on document.
         * @returns {void}
         */
        function finishDrag(finishEvent) {
            var finalRow;
            var finalIndex;
            if (!active) {
                return;
            }
            finishEvent.preventDefault();
            finishEvent.stopPropagation();
            finalRow = cleanupDrag(true);
            finalIndex = Array.prototype.slice.call(tableBody.querySelectorAll('[data-admin-image-order-row]')).indexOf(finalRow);
            if (finalIndex !== originalIndex) {
                saveImageOrder(tableBody, form, toolbar.dataset.reorderUrl || '');
            } else {
                setImageOrderStatus('Order unchanged.', 'idle');
            }
        }

        /**
         * Cancels the active drag, used by pointer cancellation and Escape.
         *
         * @param {Event} cancelEvent Cancellation event.
         * @returns {void}
         */
        function cancelDrag(cancelEvent) {
            if (!active) {
                return;
            }
            cancelEvent.preventDefault();
            cleanupDrag(false);
            setImageOrderStatus('Order unchanged.', 'idle');
        }

        /**
         * Lets the administrator cancel a drag with Escape.
         *
         * @param {KeyboardEvent} keyEvent Keyboard event captured during drag.
         * @returns {void}
         */
        function handleKeydown(keyEvent) {
            if (keyEvent.key === 'Escape') {
                cancelDrag(keyEvent);
            }
        }

        moveDrag(startEvent);
        document.addEventListener('mousemove', moveDrag, true);
        document.addEventListener('mouseup', finishDrag, true);
        document.addEventListener('pointermove', moveDrag, true);
        document.addEventListener('pointerup', finishDrag, true);
        document.addEventListener('pointercancel', cancelDrag, true);
        document.addEventListener('keydown', handleKeydown, true);
    }

    document.addEventListener('mousedown', startImageOrderDrag, true);
    document.addEventListener('pointerdown', startImageOrderDrag, true);
    document.addEventListener('click', handleNameSortClick, true);
    setImageOrderStatus('Drag handles ready. Click Name to sort by filename.', 'idle');
}());
</script>
HTML;
}
