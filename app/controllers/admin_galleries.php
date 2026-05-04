<?php

declare(strict_types=1);

/**
 * Admin gallery management controller model.
 * 
 * This module handles gallery discovery, import, creation, editing, bulk operations, public inline updates, and supporting select-list renderers.
 */

function cms_admin_discover(): void
{
    require_admin();
    // $refresh stores an intermediate value used by the surrounding gallery workflow.
    $refresh = null;
    if (request_method() === 'POST') {
        verify_csrf();
        // $refresh stores an intermediate value used by the surrounding gallery workflow.
        $refresh = scan_all_imported_gallery_images();
        admin_log_event('info', 'galleries.refresh_scanned', 'Admin refreshed imported galleries from filesystem.', $refresh);
    }
    // Variable $candidates stores this steps working value.
    $candidates = discover_gallery_candidates();
    render_header('New gallery folders');
    echo '<section class="panel"><h1>New gallery folders</h1>';
    echo '<p><a class="button secondary" href="' . e(url_for('admin')) . '">Back to admin dashboard</a></p>';
    if ($refresh !== null) {
        echo '<div class="notice">Scanned ' . (int) $refresh['galleries'] . ' existing galleries and imported or updated ' . (int) $refresh['images'] . ' images.</div>';
    }
    if (!$candidates) {
        echo '<p>No new gallery folders found.</p>';
    } else {
        echo '<form method="post" action="' . e(url_for('admin_import')) . '" data-import-galleries-form>' . csrf_field();
        echo '<p><label><input type="checkbox" name="create_thumbnails" value="1" checked> Create optimized thumbnails during import</label></p>';
        echo '<table><thead><tr><th>Import</th><th>Folder</th><th>Title</th><th>Visibility</th></tr></thead><tbody>';
        foreach ($candidates as $candidate) {
            echo '<tr><td><input type="checkbox" name="folders[]" value="' . e($candidate['folder_path']) . '"></td><td>' . e($candidate['folder_path']) . '</td><td>' . e($candidate['title']) . '</td><td>' . e($candidate['visibility']) . '</td></tr>';
        }
        echo '</tbody></table><button type="submit">Import selected detected galleries</button></form>';
    }
    echo '</section>';
    render_footer();
}

/**
 * Handles cms admin import logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function cms_admin_import(): void
{
    require_admin();
    verify_csrf();
    if (!empty($_POST['ajax']) || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        // Variable $result stores this steps working value.
        $result = import_galleries_without_thumbnails($_POST['folders'] ?? []);
        header('Content-Type: application/json');
        echo json_encode($result);
        return;
    }
    // Variable $result stores this steps working value.
    $result = import_galleries($_POST['folders'] ?? [], !empty($_POST['create_thumbnails']));
    flash_message('admin_notice', 'Imported ' . (int) ($result['imported'] ?? 0) . ' gallery folder(s) and created ' . (int) ($result['thumbnails'] ?? 0) . ' thumbnail(s).');
    redirect_to(url_for('admin'));
}

/**
 * Handles cms admin new gallery logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function cms_admin_new_gallery(): void
{
    require_admin();
    // $error stores an intermediate value used by the surrounding gallery workflow.
    $error = '';
    if (request_method() === 'POST') {
        verify_csrf();
        try {
            // $gallery stores an intermediate value used by the surrounding gallery workflow.
            $gallery = create_empty_gallery([
                'title' => $_POST['title'] ?? '',
                'folder_name' => $_POST['folder_name'] ?? '',
                'description' => $_POST['description'] ?? '',
                'visibility' => $_POST['visibility'] ?? 'draft',
                'parent_id' => $_POST['parent_id'] ?? 0,
                'voting_enabled' => $_POST['voting_enabled'] ?? 0,
                'show_filenames' => $_POST['show_filenames'] ?? 0,
            ]);
            admin_log_event('info', 'gallery.folder_created', 'Admin created an empty gallery folder.', [
                'gallery_id' => (int) $gallery['id'],
                'folder_path' => (string) $gallery['folder_path'],
            ]);
            flash_message('admin_notice', 'Gallery folder created.');
            redirect_to(url_for('admin_edit_gallery', ['id' => $gallery['id']]));
        } catch (Throwable $exception) {
            // $error stores an intermediate value used by the surrounding gallery workflow.
            $error = $exception->getMessage();
            admin_log_event('error', 'gallery.folder_create_failed', 'Admin empty gallery creation failed.', ['error' => $error]);
        }
    }

    render_header('Create empty gallery');
    echo '<section class="hero"><h1>Create empty gallery</h1><nav class="nav"><a class="button secondary" href="' . e(url_for('admin')) . '">Back to dashboard</a><a class="button secondary" href="' . e(url_for('admin_upload')) . '">Upload photos</a></nav></section>';
    if ($error !== '') {
        echo '<div class="notice">Create failed: ' . e($error) . '</div>';
    }
    echo '<section class="panel"><form method="post" class="form-grid">' . csrf_field();
    echo '<label>Gallery name<input name="title" required></label>';
    echo '<label>Folder name<input name="folder_name" autocomplete="off"><span class="muted">Leave empty to derive it from the gallery name.</span></label>';
    echo '<label>Parent gallery<select name="parent_id"><option value="0">No parent</option>' . gallery_parent_options_for_new() . '</select></label>';
    echo '<label>Visibility<select name="visibility">' . visibility_options('draft') . '</select></label>';
    echo '<label><input type="checkbox" name="voting_enabled" value="1"> Enable image voting for this gallery</label>';
    echo '<label><input type="checkbox" name="show_filenames" value="1"> Show file names</label>';
    echo '<label>Description<textarea name="description"></textarea></label>';
    echo '<button type="submit">Create gallery folder</button></form></section>';
    render_footer();
}

/**
 * Handles cms admin bulk galleries logic for the gallery application.
 * @return mixed Result produced by this operation.
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
        flash_message('admin_notice', 'Scanned ' . $count . ' image record(s).');
        redirect_to(url_for('admin'));
    }
    if ($action === 'thumbs') {
        foreach ($galleryIds as $galleryId) {
            $count += create_gallery_thumbnails($galleryId);
        }
        flash_message('admin_notice', 'Created ' . $count . ' thumbnail(s).');
        redirect_to(url_for('admin'));
    }
    if ($action === 'delete' && $galleryIds) {
        try {
            // $deleted stores an intermediate value used by the surrounding gallery workflow.
            $deleted = delete_gallery_subtrees($galleryIds);
            admin_log_event('warning', 'gallery.bulk_deleted', 'Admin deleted selected gallery folders.', [
                'gallery_ids' => $galleryIds,
                'deleted_roots' => (int) $deleted['root_count'],
                'deleted_rows' => (int) $deleted['row_count'],
            ]);
            flash_message('admin_notice', 'Deleted ' . (int) $deleted['root_count'] . ' gallery folder(s).');
            redirect_to(url_for('admin'));
        } catch (Throwable $exception) {
            admin_log_event('error', 'gallery.bulk_delete_failed', 'Bulk gallery delete failed.', [
                'gallery_ids' => $galleryIds,
                'exception' => $exception->getMessage(),
            ]);
            flash_message('admin_notice', 'Gallery delete failed: ' . $exception->getMessage());
            redirect_to(url_for('admin'));
        }
    }
    if (in_array($action, ['draft', 'public', 'private'], true) && $galleryIds) {
        // Variable $placeholders stores this steps working value.
        $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('UPDATE galleries SET visibility = ?, updated_at = ? WHERE id IN (' . $placeholders . ')');
        $stmt->execute(array_merge([$action, now_sql()], $galleryIds));
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
    if (in_array($action, ['maps_on', 'maps_off'], true) && $galleryIds) {
        if (!exif_gps_schema_ready()) {
            admin_log_event('warning', 'gps_maps.schema_missing', 'Attempted to change GPS maps before migration was applied.', [
                'gallery_ids' => $galleryIds,
                'action' => $action,
            ]);
            flash_message('admin_notice', 'GPS maps require the latest database migration.');
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
            $stmt = db()->prepare('UPDATE galleries SET gps_map_enabled = ?, updated_at = ? WHERE id IN (' . $placeholders . ')');
            $stmt->execute(array_merge([$action === 'maps_on' ? 1 : 0, now_sql()], $expandedIds));
        }
        flash_message('admin_notice', 'Updated ' . count($expandedIds) . ' gallery folder(s).');
        redirect_to(url_for('admin'));
    }
    if (in_array($action, ['vote_on', 'vote_off'], true) && $galleryIds) {
        if (!gallery_voting_schema_ready()) {
            admin_log_event('warning', 'votes.schema_missing', 'Attempted to change gallery voting before migration was applied.', [
                'gallery_ids' => $galleryIds,
                'action' => $action,
            ]);
            flash_message('admin_notice', 'Voting requires the latest database migration.');
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
            admin_log_event('warning', 'gallery_filenames.schema_missing', 'Attempted to change file name display before migration was applied.', [
                'gallery_ids' => $galleryIds,
                'action' => $action,
            ]);
            flash_message('admin_notice', 'Filename display requires the latest database migration.');
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
        if (!admin_feature_schema_ready()) {
            admin_log_event('warning', 'picture_game.schema_missing', 'Attempted to change picture game before migration was applied.', [
                'gallery_ids' => $galleryIds,
                'action' => $action,
            ]);
            flash_message('admin_notice', 'Picture game requires the latest database migration.');
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
 * @return mixed Result produced by this operation.
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
        flash_message('admin_notice', 'Regenerated clean public paths. Updated ' . (int) $result['galleries'] . ' gallery path(s) and ' . (int) $result['images'] . ' image path(s).');
        redirect_to(url_for('admin'));
    } catch (Throwable $exception) {
        flash_message('admin_notice', 'Path regeneration failed: ' . $exception->getMessage());
        redirect_to(url_for('admin'));
    }
}

/**
 * Handles cms admin save gallery collapse logic for the gallery application.
 * @return mixed Result produced by this operation.
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
    flash_message('admin_notice', 'Scanned ' . $count . ' image record(s).');
    redirect_to(url_for('admin'));
}

/**
 * Handles cms admin edit gallery logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function cms_admin_edit_gallery(): void
{
    require_admin();
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) ($_GET['id'] ?? $_POST['id'] ?? 0));
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
        // Variable $title stores this steps working value.
        $title = trim((string) $_POST['title']);
        // Variable $slug stores this steps working value.
        $slug = trim((string) $_POST['slug']);
        // Variable $visibility stores this steps working value.
        $visibility = in_array($_POST['visibility'] ?? '', ['draft', 'public', 'private'], true) ? (string) $_POST['visibility'] : 'draft';
        // Variable $pictureGameEnabled stores this steps working value.
        $pictureGameEnabled = $pictureGameReady && !empty($_POST['picture_game_enabled']) ? 1 : 0;
        // Variable $gpsMapEnabled stores this steps working value.
        $gpsMapEnabled = $gpsMapReady && !empty($_POST['gps_map_enabled']) ? 1 : 0;
        // Variable $votingEnabled stores this steps working value.
        $votingEnabled = gallery_voting_schema_ready() && !empty($_POST['voting_enabled']) ? 1 : 0;
        // Variable $showFilenames stores this steps working value.
        $showFilenames = gallery_filename_display_schema_ready() && !empty($_POST['show_filenames']) ? 1 : 0;
        if ($pictureGameEnabled) {
            // $votingEnabled stores an intermediate value used by the surrounding gallery workflow.
            $votingEnabled = 1;
        }
        if (!$votingEnabled) {
            // $pictureGameEnabled stores an intermediate value used by the surrounding gallery workflow.
            $pictureGameEnabled = 0;
        }
        // Variable $accessType stores this steps working value.
        $accessType = $accessReady && in_array($_POST['access_type'] ?? '', ['password', 'share'], true) ? (string) $_POST['access_type'] : 'normal';
        // Variable $accessMode stores this steps working value.
        $accessMode = $accessType === 'normal' ? 'normal' : 'password';
        // Variable $accessListing stores this steps working value.
        $accessListing = $accessType === 'share' || ($accessReady && ($_POST['access_listing'] ?? '') === 'unlisted') ? 'unlisted' : 'listed';
        // Variable $accessPasswordHash stores this steps working value.
        $accessPasswordHash = $accessReady ? ($gallery['access_password_hash'] ?? null) : null;
        if ($accessType === 'share') {
            // $accessPasswordHash stores an intermediate value used by the surrounding gallery workflow.
            $accessPasswordHash = null;
        }
        if ($accessReady && !empty($_POST['clear_access_password'])) {
            // $accessPasswordHash stores an intermediate value used by the surrounding gallery workflow.
            $accessPasswordHash = null;
        }
        // Variable $newAccessPassword stores this steps working value.
        $newAccessPassword = trim((string) ($_POST['access_password'] ?? ''));
        if ($accessReady && $accessType === 'password' && $newAccessPassword !== '') {
            // $accessPasswordHash stores an intermediate value used by the surrounding gallery workflow.
            $accessPasswordHash = password_hash($newAccessPassword, PASSWORD_DEFAULT);
        }
        // Variable $parentId stores this steps working value.
        $parentId = (int) ($_POST['parent_id'] ?? 0);
        // Variable $parentId stores this steps working value.
        $parentId = $parentId > 0 && find_gallery($parentId) ? $parentId : null;
        // $currentFolderName stores an intermediate value used by the surrounding gallery workflow.
        $currentFolderName = gallery_folder_name_from_path((string) $gallery['folder_path']);
        // $submittedFolderName stores an intermediate value used by the surrounding gallery workflow.
        $submittedFolderName = trim((string) ($_POST['folder_name'] ?? $currentFolderName));
        // $folderNameChanged stores an intermediate value used by the surrounding gallery workflow.
        $folderNameChanged = $submittedFolderName !== '' && $submittedFolderName !== $currentFolderName;
        // $moveResult stores an intermediate value used by the surrounding gallery workflow.
        $moveResult = null;
        if ((int) ($gallery['parent_id'] ?? 0) !== (int) ($parentId ?? 0) || $folderNameChanged) {
            try {
                // $moveResult stores an intermediate value used by the surrounding gallery workflow.
                $moveResult = move_gallery_folder_to_parent((int) $gallery['id'], $parentId, $folderNameChanged ? $submittedFolderName : null);
                if (!empty($moveResult['moved'])) {
                    admin_log_event('info', 'gallery.folder_moved', 'Admin moved a gallery folder.', [
                        'gallery_id' => (int) $gallery['id'],
                        'from' => (string) $moveResult['from'],
                        'to' => (string) $moveResult['to'],
                        'galleries' => (int) $moveResult['galleries'],
                    ]);
                }
                // $gallery stores an intermediate value used by the surrounding gallery workflow.
                $gallery = find_gallery((int) $gallery['id']) ?: $gallery;
            } catch (Throwable $exception) {
                admin_log_event('error', 'gallery.folder_move_failed', 'Admin gallery folder move failed.', [
                    'gallery_id' => (int) $gallery['id'],
                    'error' => $exception->getMessage(),
                ]);
                $_SESSION['admin_gallery_error_' . (int) $gallery['id']] = $exception->getMessage();
                flash_message('admin_notice', 'Gallery folder move failed: ' . $exception->getMessage());
                redirect_to(url_for('admin_edit_gallery', ['id' => $gallery['id']]));
            }
        }
        // Variable $coverImageId stores this steps working value.
        $coverImageId = (int) ($_POST['cover_image_id'] ?? 0);
        // Variable $coverImage stores this steps working value.
        $coverImage = $coverImageId > 0 ? find_image($coverImageId) : null;
        // Variable $coverImageId stores this steps working value.
        $coverImageId = $coverImage && (int) $coverImage['gallery_id'] === (int) $gallery['id'] ? $coverImageId : null;
        // $coverImagePath stores an intermediate value used by the surrounding gallery workflow.
        $coverImagePath = gallery_cover_asset_schema_ready() ? gallery_cover_path($gallery) : null;
        // $backgroundSource stores an intermediate value used by the surrounding gallery workflow.
        $backgroundSource = null;
        if (gallery_background_source_schema_ready()) {
            // $submittedBackgroundSource stores an intermediate value used by the surrounding gallery workflow.
            $submittedBackgroundSource = (string) ($_POST['background_source'] ?? '');
            if (in_array($submittedBackgroundSource, ['upload', 'existing', 'collage'], true)) {
                // $backgroundSource stores an intermediate value used by the surrounding gallery workflow.
                $backgroundSource = $submittedBackgroundSource;
            }
        }
        if (gallery_cover_asset_schema_ready() && !empty($_FILES['cover_upload']['name'] ?? '')) {
            // $uploadError stores an intermediate value used by the surrounding gallery workflow.
            $uploadError = (int) ($_FILES['cover_upload']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError !== UPLOAD_ERR_NO_FILE) {
                if ($uploadError !== UPLOAD_ERR_OK) {
                    throw new RuntimeException(upload_error_message($uploadError));
                }
                // $tmpName stores an intermediate value used by the surrounding gallery workflow.
                $tmpName = (string) ($_FILES['cover_upload']['tmp_name'] ?? '');
                if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                    throw new RuntimeException('Uploaded thumbnail is not available.');
                }
                // $info stores an intermediate value used by the surrounding gallery workflow.
                $info = @getimagesize($tmpName);
                if ($info === false || empty($info['mime']) || !str_starts_with((string) $info['mime'], 'image/')) {
                    throw new RuntimeException('The uploaded gallery thumbnail is not a valid image.');
                }
                // $coverImagePath stores an intermediate value used by the surrounding gallery workflow.
                $coverImagePath = store_uploaded_gallery_cover((int) $gallery['id'], $_FILES['cover_upload']);
                // $coverImageId stores an intermediate value used by the surrounding gallery workflow.
                $coverImageId = null;
            }
        }
        // Variable $slug stores this steps working value.
        $slug = $slug !== '' ? slugify($slug) : unique_slug(db(), $title, (int) $gallery['id']);
        // $fields stores an intermediate value used by the surrounding gallery workflow.
        $fields = [
            'parent_id = ?' => $parentId,
            'cover_image_id = ?' => $coverImageId,
            'title = ?' => $title,
            'description = ?' => (string) $_POST['description'],
            'slug = ?' => unique_slug_for_value($slug, (int) $gallery['id']),
            'visibility = ?' => $visibility,
            'sort_order = ?' => (int) $_POST['sort_order'],
        ];
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
        if ($accessReady) {
            $fields['access_mode = ?'] = $accessMode;
            $fields['access_listing = ?'] = $accessMode === 'password' ? $accessListing : 'listed';
            $fields['access_password_hash = ?'] = $accessMode === 'password' ? $accessPasswordHash : null;
            if ($accessMode !== 'password') {
                if (gallery_access_share_token_schema_ready()) {
                    $fields['access_share_token = ?'] = null;
                }
                $fields['access_token_hash = ?'] = null;
                $fields['access_token_expires_at = ?'] = null;
            }
        }
        if (gallery_cover_asset_schema_ready()) {
            $fields['cover_image_path = ?'] = $coverImagePath;
        }
        if (gallery_background_source_schema_ready()) {
            $fields['background_source = ?'] = $backgroundSource;
        }
        $fields['updated_at = ?'] = now_sql();
        // $stmt stores an intermediate value used by the surrounding gallery workflow.
        $stmt = db()->prepare('UPDATE galleries SET ' . implode(', ', array_keys($fields)) . ' WHERE id = ?');
        $stmt->execute(array_merge(array_values($fields), [(int) $gallery['id']]));
        if ($accessReady) {
            // $accessAction stores an intermediate value used by the surrounding gallery workflow.
            $accessAction = (string) ($_POST['access_action'] ?? 'save');
            if ($accessAction === 'revoke_link') {
                revoke_gallery_share_token((int) $gallery['id']);
            }
        }
        if ($accessReady && $accessMode === 'password') {
            // $needsShareLink stores an intermediate value used by the surrounding gallery workflow.
            $needsShareLink = $accessType === 'share' && empty($gallery['access_token_hash']);
            if ($accessAction === 'generate_link' || $needsShareLink) {
                // $expires stores an intermediate value used by the surrounding gallery workflow.
                $expires = trim((string) ($_POST['access_token_expires_at'] ?? ''));
                // $expiresTimestamp stores an intermediate value used by the surrounding gallery workflow.
                $expiresTimestamp = $expires !== '' ? strtotime($expires) : false;
                // $expiresAt stores an intermediate value used by the surrounding gallery workflow.
                $expiresAt = $expiresTimestamp !== false ? date('Y-m-d H:i:s', $expiresTimestamp) : null;
                $_SESSION['new_gallery_share_token_' . (int) $gallery['id']] = regenerate_gallery_share_token((int) $gallery['id'], $expiresAt);
            }
        }
        sync_entity_tags('gallery', (int) $gallery['id'], (string) ($_POST['tags'] ?? ''));
        // Variable $gallery stores this steps working value.
        $gallery = find_gallery((int) $gallery['id']);
        if ($gallery) {
            write_gallery_sidecar($gallery);
        }
        // $notice stores an intermediate value used by the surrounding gallery workflow.
        $notice = 'Gallery saved.';
        if (!empty($moveResult['moved'])) {
            // $notice stores an intermediate value used by the surrounding gallery workflow.
            $notice = 'Gallery saved and folder moved.';
        }
        flash_message('admin_notice', $notice);
        redirect_to(url_for('admin_edit_gallery', ['id' => $gallery['id']]));
    }
    // Variable $images stores this steps working value.
    $images = gallery_images((int) $gallery['id'], false);
    render_header('Edit gallery');
    // $galleryError stores an intermediate value used by the surrounding gallery workflow.
    $galleryError = (string) ($_SESSION['admin_gallery_error_' . (int) $gallery['id']] ?? '');
    unset($_SESSION['admin_gallery_error_' . (int) $gallery['id']]);
    if ($galleryError !== '') {
        echo '<div class="notice">Gallery folder move failed: ' . e($galleryError) . '</div>';
    }
    // $galleryNotice stores an intermediate value used by the surrounding gallery workflow.
    $galleryNotice = (string) flash_message('admin_notice');
    if ($galleryNotice !== '') {
        echo '<div class="notice">' . e($galleryNotice) . '</div>';
    }
    if (isset($_GET['created'])) {
        echo '<div class="notice">Gallery folder created.</div>';
    } elseif (isset($_GET['uploaded'])) {
        echo '<div class="notice">Uploaded ' . (int) $_GET['uploaded'] . ' images, scanned or updated ' . (int) ($_GET['scanned'] ?? 0) . ' image records, and created ' . (int) ($_GET['thumbnails'] ?? 0) . ' thumbnails.</div>';
    } elseif (isset($_GET['moved'])) {
        echo '<div class="notice">Gallery folder moved on disk and database paths were updated.</div>';
    } elseif (isset($_GET['saved'])) {
        echo '<div class="notice">Gallery saved.</div>';
    }
    if (!$pictureGameReady) {
        render_admin_migration_notice('Picture game settings are hidden until the latest database migration is applied.');
    }
    echo '<section class="panel"><h1>Edit gallery</h1><form method="post" enctype="multipart/form-data" class="form-grid" autocomplete="off">' . csrf_field();
    echo '<input type="hidden" name="id" value="' . (int) $gallery['id'] . '">';
    echo '<label>Title<input name="title" value="' . e($gallery['title']) . '" autocomplete="off" required></label>';
    echo '<label>Description<textarea name="description">' . e($gallery['description']) . '</textarea></label>';
    echo '<label>Slug<input name="slug" value="' . e($gallery['slug']) . '" autocomplete="off" required></label>';
    echo '<label>Folder name<input name="folder_name" value="' . e(gallery_folder_name_from_path((string) $gallery['folder_path'])) . '" autocomplete="off" required><span class="muted">Changing this renames the folder on disk.</span></label>';
    echo '<label>Parent gallery<select name="parent_id"><option value="0">No parent</option>' . gallery_parent_options($gallery) . '</select></label>';
    echo '<label>Visibility<select name="visibility">' . visibility_options((string) $gallery['visibility']) . '</select></label>';
    if ($accessReady) {
        // $newShareToken stores an intermediate value used by the surrounding gallery workflow.
        $newShareToken = (string) ($_SESSION['new_gallery_share_token_' . (int) $gallery['id']] ?? '');
        unset($_SESSION['new_gallery_share_token_' . (int) $gallery['id']]);
        // $currentAccessType stores an intermediate value used by the surrounding gallery workflow.
        $currentAccessType = 'normal';
        if ((string) ($gallery['access_mode'] ?? 'normal') === 'password') {
            // $currentAccessType stores an intermediate value used by the surrounding gallery workflow.
            $currentAccessType = empty($gallery['access_password_hash']) ? 'share' : 'password';
        }
        echo '<fieldset class="form-grid"><legend>Protected access</legend>';
        echo '<label>Access<select name="access_type"><option value="normal"' . ($currentAccessType === 'normal' ? ' selected' : '') . '>Normal public access</option><option value="password"' . ($currentAccessType === 'password' ? ' selected' : '') . '>Password protected</option><option value="share"' . ($currentAccessType === 'share' ? ' selected' : '') . '>Share link only</option></select></label>';
        echo '<label>Public listing<select name="access_listing"><option value="listed"' . ((string) ($gallery['access_listing'] ?? 'listed') === 'listed' ? ' selected' : '') . '>Listed without thumbnail</option><option value="unlisted"' . ((string) ($gallery['access_listing'] ?? 'listed') === 'unlisted' ? ' selected' : '') . '>Unlisted, direct link only</option></select></label>';
        echo '<label>New gallery password<input name="access_password" type="password" autocomplete="new-password"><span class="muted">Leave empty to keep the current gallery password.</span></label>';
        if (!empty($gallery['access_password_hash'])) {
            echo '<label><input type="checkbox" name="clear_access_password" value="1"> Clear current gallery password</label>';
        }
        echo '<label>Share link expiry<input name="access_token_expires_at" type="datetime-local" value="' . e(!empty($gallery['access_token_expires_at']) ? date('Y-m-d\TH:i', strtotime((string) $gallery['access_token_expires_at'])) : '') . '"><span class="muted">Leave empty for a non-expiring generated link.</span></label>';
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
        echo '<p class="muted">Share-link-only galleries are hidden from public listings and get a link automatically when saved.</p>';
        echo '<div class="bulk-row"><button type="submit" class="secondary" name="access_action" value="generate_link">Generate/regenerate share link</button><button type="submit" class="secondary" name="access_action" value="revoke_link">Revoke share link</button></div>';
        echo '</fieldset>';
    } else {
        echo '<p class="notice">Protected gallery settings are hidden until the v0.13 database migration is applied.</p>';
    }
    if ($pictureGameReady) {
        echo '<label><input type="checkbox" name="picture_game_enabled" value="1"' . ((int) ($gallery['picture_game_enabled'] ?? 0) === 1 ? ' checked' : '') . '> Enable picture game for this gallery branch</label>';
    }
    if (gallery_voting_schema_ready()) {
        echo '<label><input type="checkbox" name="voting_enabled" value="1"' . ((int) ($gallery['voting_enabled'] ?? 0) === 1 ? ' checked' : '') . '> Enable image voting for this gallery</label>';
        echo '<p class="muted">When disabled, existing votes remain stored and visible, but vote arrows and vote submissions are blocked.</p>';
    }
    if (gallery_filename_display_schema_ready()) {
        echo '<label><input type="checkbox" name="show_filenames" value="1"' . ((int) ($gallery['show_filenames'] ?? 0) === 1 ? ' checked' : '') . '> Show file names</label>';
        echo '<p class="muted">Disabled by default. Custom photo titles and descriptions are still shown; raw uploaded file names stay hidden unless this is enabled.</p>';
    } else {
        echo '<p class="muted">File name display control will be available after the database migration is applied.</p>';
    }
    if ($gpsMapReady) {
        echo '<label><input type="checkbox" name="gps_map_enabled" value="1"' . ((int) ($gallery['gps_map_enabled'] ?? 0) === 1 ? ' checked' : '') . '> Enable EXIF GPS maps for this gallery branch</label>';
        echo '<p class="muted">When enabled here, this gallery and its subgalleries may show photo map pins and gallery maps for images with GPS EXIF coordinates.</p>';
    }
    echo '<label>Sort order<input name="sort_order" type="number" value="' . (int) $gallery['sort_order'] . '"></label>';
    echo '<label>Title picture<select name="cover_image_id"><option value="0">Automatic</option>' . gallery_cover_options((int) $gallery['id'], (int) ($gallery['cover_image_id'] ?? 0), true) . '</select><span class="muted">Includes images from subgalleries.</span></label>';
    if (gallery_cover_asset_schema_ready()) {
        echo '<label>Upload gallery thumbnail<input type="file" name="cover_upload" accept="image/*"><span class="muted">This is stored separately from gallery images.</span></label>';
    } else {
        echo '<p class="muted">Uploadable gallery thumbnails will be available after the gallery thumbnail migration is applied.</p>';
    }
    if (gallery_background_source_schema_ready()) {
        // $backgroundSource stores an intermediate value used by the surrounding gallery workflow.
        $backgroundSource = gallery_background_source($gallery);
        echo '<label>Background source<select name="background_source"><option value=""' . ($backgroundSource === null ? ' selected' : '') . '>Use theme background</option><option value="upload"' . ($backgroundSource === 'upload' ? ' selected' : '') . '>Upload new image</option><option value="existing"' . ($backgroundSource === 'existing' ? ' selected' : '') . '>Pick from existing gallery images</option><option value="collage"' . ($backgroundSource === 'collage' ? ' selected' : '') . '>Generate collage from public galleries</option></select><span class="muted">If unset, the gallery inherits the Theme background.</span></label>';
    } else {
        echo '<p class="muted">Background source selection will be available after the background migration is applied.</p>';
    }
    echo '<label>Tags<input name="tags" value="' . e(tag_names_for_entity('gallery', (int) $gallery['id'])) . '" list="tag-suggestions" data-tag-input><span class="muted">Separate tags with commas.</span></label>';
    render_tag_datalist();
    echo '<button type="submit">Save gallery</button></form></section>';
    echo '<section class="panel"><h2>Scan</h2><form method="post" action="' . e(url_for('admin_scan_images')) . '" class="form-grid">' . csrf_field();
    echo '<input type="hidden" name="gallery_id" value="' . (int) $gallery['id'] . '">';
    echo '<button type="submit">Scan/import images in this gallery</button></form></section>';
    echo '<section class="panel"><h2>Images</h2><form method="post" action="' . e(url_for('admin_bulk_images')) . '" data-admin-image-bulk-form>' . csrf_field();
    echo '<input type="hidden" name="gallery_id" value="' . (int) $gallery['id'] . '">';
    echo '<div class="bulk-row"><label><input type="checkbox" data-select-all="image_ids[]"> Select all images</label><label>Bulk action<select name="action"><option value="public">Set public</option><option value="draft">Set draft</option><option value="private">Set private</option><option value="cover">Set as title picture</option><option value="thumbs">Create thumbnails</option></select></label><button type="submit">Apply to selected</button><button type="submit" class="secondary" name="thumbnail_gallery_id" value="' . (int) $gallery['id'] . '" formaction="' . e(url_for('admin_create_thumbnails')) . '">Create gallery thumbnails</button></div>';
    echo '<div class="admin-image-order-toolbar" data-admin-image-order-toolbar data-reorder-url="' . e(url_for('admin_reorder_images')) . '"><p class="muted">Drag photos by the handle to change their gallery order. The new order is saved immediately after each drop.</p><span class="admin-image-order-status" data-admin-image-order-status aria-live="polite">Order unchanged.</span></div>';
    echo '<table class="admin-image-order-table" data-admin-image-order-table><thead><tr><th>Move</th><th>Select</th><th>Preview</th><th>Image</th><th title="File names shown">N</th><th>Status</th><th>Cover</th><th>Actions</th></tr></thead><tbody>';
    foreach ($images as $image) {
        // Variable $isCover stores this steps working value.
        $isCover = (int) ($gallery['cover_image_id'] ?? 0) === (int) $image['id'];
        echo '<tr data-admin-image-order-row data-image-id="' . (int) $image['id'] . '"><td class="admin-image-order-cell"><span class="admin-image-drag-handle" data-admin-image-drag-handle role="button" tabindex="0" aria-label="Move ' . e((string) $image['relative_path']) . '" title="Drag to reorder">↕</span></td><td><input type="checkbox" name="image_ids[]" value="' . (int) $image['id'] . '"></td>';
        echo '<td><img class="admin-thumb" decoding="async" loading="lazy" src="' . e(thumbnail_url($image, 300)) . '" alt=""></td>';
        echo '<td>' . e($image['relative_path']) . '</td><td>' . render_admin_feature_flag(gallery_shows_filenames($gallery), '✓', 'File names are shown for this gallery') . '</td><td>' . e($image['visibility']) . '</td><td>' . ($isCover ? 'Title picture' : '') . '</td><td><a href="' . e(url_for('admin_edit_image', ['id' => $image['id']])) . '">Edit</a></td></tr>';
    }
    echo '</tbody></table></form></section>';
    render_admin_image_reorder_script();
    render_admin_devmode_panel();
    render_footer();
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
        bodyData.set('ajax', '1');
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
    setImageOrderStatus('Drag handles ready.', 'idle');
}());
</script>
HTML;
}

/**
 * Handles cms admin image reorder logic for the gallery application.
 *
 * The edit-gallery image table sends the complete ordered image-id list after a
 * drag-and-drop operation. This endpoint validates that every submitted image
 * belongs to the selected gallery before it touches sort_order values, so a
 * forged request cannot reorder images in another gallery.
 *
 * @return mixed Result produced by this operation.
 */
function cms_admin_reorder_images(): void
{
    require_admin();
    verify_csrf();
    // Variable $galleryId stores the gallery whose direct image order is being changed.
    $galleryId = (int) ($_POST['gallery_id'] ?? 0);
    // Variable $gallery stores the database row for the submitted gallery id.
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        cms_not_found();
        return;
    }
    // Variable $rawOrder stores the JSON payload submitted by the JavaScript drag-and-drop handler.
    $rawOrder = (string) ($_POST['image_order'] ?? '[]');
    // Variable $decodedOrder stores the decoded image-id list before it is normalized to integers.
    $decodedOrder = json_decode($rawOrder, true);
    if (!is_array($decodedOrder)) {
        admin_reorder_images_response(false, 'The submitted image order was not valid JSON.', $galleryId);
        return;
    }
    // Variable $submittedIds stores the ordered ids exactly as integers, with invalid zero values removed.
    $submittedIds = array_values(array_filter(array_map('intval', $decodedOrder), static fn (int $imageId): bool => $imageId > 0));
    if (!$submittedIds) {
        admin_reorder_images_response(false, 'No images were submitted for reordering.', $galleryId);
        return;
    }
    if (count($submittedIds) !== count(array_unique($submittedIds))) {
        admin_reorder_images_response(false, 'The submitted image order contained duplicate images.', $galleryId);
        return;
    }
    // Variable $currentIds stores the complete direct-image set currently visible in the edit-gallery table.
    $currentIds = array_map(static fn (array $image): int => (int) $image['id'], gallery_images($galleryId, false));
    sort($currentIds);
    // Variable $sortedSubmittedIds stores the submitted id set for exact set comparison with the database state.
    $sortedSubmittedIds = $submittedIds;
    sort($sortedSubmittedIds);
    if ($sortedSubmittedIds !== $currentIds) {
        admin_reorder_images_response(false, 'The image list changed while you were reordering. Reload the page and try again.', $galleryId);
        return;
    }
    // Variable $pdo stores the active database connection used for the atomic sort_order update.
    $pdo = db();
    // Variable $now stores one timestamp shared by all rows touched by this reorder operation.
    $now = now_sql();
    try {
        $pdo->beginTransaction();
        // Variable $stmt stores the prepared update reused for each reordered image row.
        $stmt = $pdo->prepare('UPDATE images SET sort_order = ?, updated_at = ? WHERE id = ? AND gallery_id = ?');
        foreach ($submittedIds as $index => $imageId) {
            // Variable $sortOrder stores a spaced integer so future maintenance can insert between rows if needed.
            $sortOrder = ($index + 1) * 10;
            $stmt->execute([$sortOrder, $now, $imageId, $galleryId]);
        }
        $pdo->commit();
        admin_log_event('info', 'image.reordered', 'Admin reordered gallery images.', [
            'gallery_id' => $galleryId,
            'images' => count($submittedIds),
        ]);
        admin_reorder_images_response(true, 'Image order saved.', $galleryId);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        admin_log_event('error', 'image.reorder_failed', 'Admin image reorder failed.', [
            'gallery_id' => $galleryId,
            'error' => $exception->getMessage(),
        ]);
        admin_reorder_images_response(false, 'Image order could not be saved: ' . $exception->getMessage(), $galleryId);
    }
}

/**
 * Returns the image-reorder result as JSON for drag-and-drop requests or as a
 * normal admin redirect for non-JavaScript fallback submissions.
 *
 * @param bool $ok Whether the reorder operation completed successfully.
 * @param string $message Human-readable status message for the admin UI.
 * @param int $galleryId Gallery id used to build the redirect fallback.
 * @return mixed Result produced by this operation.
 */
function admin_reorder_images_response(bool $ok, string $message, int $galleryId): void
{
    // Variable $acceptHeader stores the browser Accept header used to detect the JavaScript request path.
    $acceptHeader = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    // Variable $isJsonRequest stores whether the client explicitly expects a JSON response.
    $isJsonRequest = str_contains($acceptHeader, 'application/json') || (string) ($_POST['ajax'] ?? '') === '1';
    if ($isJsonRequest) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $message], JSON_THROW_ON_ERROR);
        return;
    }
    flash_message('admin_notice', $message);
    redirect_to(url_for('admin_edit_gallery', ['id' => $galleryId]));
}

/**
 * Handles cms admin bulk images logic for the gallery application.
 * @return mixed Result produced by this operation.
 */

function cms_admin_bulk_images(): void
{
    require_admin();
    verify_csrf();
    // Variable $galleryId stores this steps working value.
    $galleryId = (int) ($_POST['gallery_id'] ?? 0);
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        cms_not_found();
        return;
    }
    // Variable $imageIds stores this steps working value.
    $imageIds = array_map('intval', $_POST['image_ids'] ?? []);
    // Variable $action stores this steps working value.
    $action = (string) ($_POST['action'] ?? '');
    // Variable $count stores this steps working value.
    $count = 0;
    if (!$imageIds) {
        redirect_to(url_for('admin_edit_gallery', ['id' => $galleryId]));
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
        redirect_to(url_for('admin_edit_gallery', ['id' => $galleryId]));
    }
    if ($action === 'cover') {
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('UPDATE galleries SET cover_image_id = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$ownedIds[0], now_sql(), $galleryId]);
        // Variable $updated stores this steps working value.
        $updated = find_gallery($galleryId);
        if ($updated) {
            write_gallery_sidecar($updated);
        }
        flash_message('admin_notice', 'Gallery saved.');
        redirect_to(url_for('admin_edit_gallery', ['id' => $galleryId]));
    }
    if (in_array($action, ['draft', 'public', 'private'], true)) {
        // Variable $placeholders stores this steps working value.
        $placeholders = implode(',', array_fill(0, count($ownedIds), '?'));
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('UPDATE images SET visibility = ?, updated_at = ? WHERE id IN (' . $placeholders . ')');
        $stmt->execute(array_merge([$action, now_sql()], $ownedIds));
    }
    if ($action === 'thumbs') {
        foreach ($ownedIds as $imageId) {
            // Variable $image stores this steps working value.
            $image = find_image($imageId);
            if ($image) {
                $count += create_image_thumbnails($image, $gallery);
            }
        }
        flash_message('admin_notice', 'Created ' . $count . ' thumbnail(s).');
        redirect_to(url_for('admin_edit_gallery', ['id' => $galleryId]));
    }
    flash_message('admin_notice', 'Updated ' . count($ownedIds) . ' image(s).');
    redirect_to(url_for('admin_edit_gallery', ['id' => $galleryId]));
}

/**
 * Handles cms admin public update gallery logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function cms_admin_public_update_gallery(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) ($_POST['gallery_id'] ?? 0));
    if (!$gallery) {
        cms_not_found();
        return;
    }
    // Variable $title stores this steps working value.
    $title = trim((string) ($_POST['title'] ?? ''));
    if ($title === '') {
        // $title stores an intermediate value used by the surrounding gallery workflow.
        $title = (string) $gallery['title'];
    }
    // Variable $visibility stores this steps working value.
    $visibility = (string) $gallery['visibility'];
    // Variable $action stores this steps working value.
    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'delete') {
        // Variable $redirect stores this steps working value.
        $redirect = url_for('home');
        if (!empty($gallery['parent_id'])) {
            // Variable $parent stores this steps working value.
            $parent = find_gallery((int) $gallery['parent_id']);
            if ($parent) {
                // $redirect stores an intermediate value used by the surrounding gallery workflow.
                $redirect = gallery_public_url($parent);
            }
        }
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('DELETE FROM galleries WHERE id = ?');
        $stmt->execute([(int) $gallery['id']]);
        redirect_to($redirect);
    }
    if ($action === 'publish') {
        // $visibility stores an intermediate value used by the surrounding gallery workflow.
        $visibility = 'public';
    }
    if ($action === 'hide') {
        // $visibility stores an intermediate value used by the surrounding gallery workflow.
        $visibility = 'private';
    }
    // Variable $fields stores this steps working value.
    $fields = [
        'title = ?' => $title,
        'description = ?' => (string) ($_POST['description'] ?? ''),
        'visibility = ?' => $visibility,
    ];
    if (gallery_filename_display_schema_ready()) {
        $fields['show_filenames = ?'] = !empty($_POST['show_filenames']) ? 1 : 0;
    }
    $fields['updated_at = ?'] = now_sql();
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('UPDATE galleries SET ' . implode(', ', array_keys($fields)) . ' WHERE id = ?');
    $stmt->execute(array_merge(array_values($fields), [(int) $gallery['id']]));
    if (public_path_schema_ready()) {
        regenerate_public_paths();
    }
    // Variable $updated stores this steps working value.
    $updated = find_gallery((int) $gallery['id']);
    if ($updated) {
        write_gallery_sidecar($updated);
    }
    redirect_to((string) ($_SERVER['HTTP_REFERER'] ?? gallery_public_url($gallery)));
}

/**
 * Handles cms admin public update image logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function cms_admin_public_update_image(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();
    // Variable $image stores this steps working value.
    $image = find_image((int) ($_POST['image_id'] ?? 0));
    if (!$image) {
        cms_not_found();
        return;
    }
    // Variable $visibility stores this steps working value.
    $visibility = (string) $image['visibility'];
    // Variable $action stores this steps working value.
    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'delete') {
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('DELETE FROM images WHERE id = ?');
        $stmt->execute([(int) $image['id']]);
        redirect_to((string) ($_SERVER['HTTP_REFERER'] ?? url_for('home')));
    }
    if ($action === 'publish') {
        // $visibility stores an intermediate value used by the surrounding gallery workflow.
        $visibility = 'public';
    }
    if ($action === 'hide') {
        // $visibility stores an intermediate value used by the surrounding gallery workflow.
        $visibility = 'private';
    }
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('UPDATE images SET title = ?, description = ?, visibility = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([trim((string) ($_POST['title'] ?? '')), (string) ($_POST['description'] ?? ''), $visibility, now_sql(), (int) $image['id']]);
    if (public_path_schema_ready()) {
        regenerate_public_paths();
    }
    redirect_to((string) ($_SERVER['HTTP_REFERER'] ?? url_for('home')));
}

/**
 * Handles cms admin edit image logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function cms_admin_edit_image(): void
{
    require_admin();
    // Variable $image stores this steps working value.
    $image = find_image((int) ($_GET['id'] ?? $_POST['id'] ?? 0));
    if (!$image) {
        cms_not_found();
        return;
    }
    if (request_method() === 'POST') {
        verify_csrf();
        // Variable $visibility stores this steps working value.
        $visibility = in_array($_POST['visibility'] ?? '', ['draft', 'public', 'private'], true) ? (string) $_POST['visibility'] : 'public';
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('UPDATE images SET title = ?, description = ?, visibility = ?, sort_order = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([(string) $_POST['title'], (string) $_POST['description'], $visibility, (int) $_POST['sort_order'], now_sql(), (int) $image['id']]);
        sync_entity_tags('image', (int) $image['id'], (string) ($_POST['tags'] ?? ''));
        if (public_path_schema_ready()) {
            regenerate_public_paths();
        }
        redirect_to(url_for('admin_edit_image', ['id' => $image['id'], 'saved' => 1]));
    }
    render_header('Edit image');
    echo '<section class="panel"><h1>Edit image</h1><p><img decoding="async" loading="lazy" src="' . e(thumbnail_url($image, 800)) . '" alt=""></p>';
    echo '<form method="post" class="form-grid">' . csrf_field();
    echo '<input type="hidden" name="id" value="' . (int) $image['id'] . '">';
    echo '<label>Title<input name="title" value="' . e($image['title']) . '"></label>';
    echo '<label>Description<textarea name="description">' . e($image['description']) . '</textarea></label>';
    echo '<label>Visibility<select name="visibility">' . visibility_options((string) $image['visibility']) . '</select></label>';
    echo '<label>Sort order<input name="sort_order" type="number" value="' . (int) $image['sort_order'] . '"></label>';
    echo '<label>Tags<input name="tags" value="' . e(tag_names_for_entity('image', (int) $image['id'])) . '" list="tag-suggestions" data-tag-input><span class="muted">Separate tags with commas.</span></label>';
    render_tag_datalist();
    if (exif_gps_schema_ready()) {
        echo '<div class="exif-admin-summary"><h2>EXIF / GPS</h2><dl>';
        echo '<dt>Taken</dt><dd>' . e((string) ($image['exif_taken_at'] ?? '')) . '</dd>';
        echo '<dt>Camera</dt><dd>' . e(trim((string) ($image['exif_camera_make'] ?? '') . ' ' . (string) ($image['exif_camera_model'] ?? ''))) . '</dd>';
        echo '<dt>Lens</dt><dd>' . e((string) ($image['exif_lens_model'] ?? '')) . '</dd>';
        echo '<dt>Exposure</dt><dd>' . e(trim((string) ($image['exif_focal_length'] ?? '') . ' ' . (string) ($image['exif_aperture'] ?? '') . ' ' . (string) ($image['exif_exposure_time'] ?? '') . ' ISO ' . (string) ($image['exif_iso'] ?? ''))) . '</dd>';
        echo '<dt>GPS</dt><dd>' . (image_has_gps($image) ? e((string) $image['gps_lat'] . ', ' . (string) $image['gps_lng']) : 'No GPS coordinates found') . '</dd>';
        echo '</dl><p class="muted">EXIF and GPS values are refreshed when the image is scanned again.</p></div>';
    }
    echo '<button type="submit">Save image</button></form></section>';
    render_footer();
}

/**
 * Handles visibility options logic for the gallery application.
 * @param mixed $selected Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function visibility_options(string $selected): string
{
    // Variable $html stores this steps working value.
    $html = '';
    foreach (['draft', 'public', 'private'] as $visibility) {
        $html .= '<option value="' . e($visibility) . '"' . ($visibility === $selected ? ' selected' : '') . '>' . e($visibility) . '</option>';
    }
    return $html;
}

/**
 * Handles render tag datalist logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function render_tag_datalist(): void
{
    echo '<datalist id="tag-suggestions">';
    foreach (all_tag_names() as $name) {
        echo '<option value="' . e((string) $name) . '"></option>';
    }
    echo '</datalist>';
}

/**
 * Handles gallery parent options logic for the gallery application.
 * @param mixed $currentGallery Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_parent_options(array $currentGallery): string
{
    // Variable $galleries stores this steps working value.
    $galleries = db()->query('SELECT id, title, folder_path FROM galleries ORDER BY folder_path')->fetchAll();
    // Variable $html stores this steps working value.
    $html = '';
    // Variable $currentPath stores this steps working value.
    $currentPath = rtrim((string) $currentGallery['folder_path'], '/');
    foreach ($galleries as $gallery) {
        if ((int) $gallery['id'] === (int) $currentGallery['id']) {
            continue;
        }
        // Variable $path stores this steps working value.
        $path = (string) $gallery['folder_path'];
        if ($path !== '' && str_starts_with($path . '/', $currentPath . '/')) {
            continue;
        }
        // Variable $selected stores this steps working value.
        $selected = (int) ($currentGallery['parent_id'] ?? 0) === (int) $gallery['id'] ? ' selected' : '';
        $html .= '<option value="' . (int) $gallery['id'] . '"' . $selected . '>' . e($gallery['title'] . ' (' . $gallery['folder_path'] . ')') . '</option>';
    }
    return $html;
}

/**
 * Handles gallery parent options for new logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function gallery_parent_options_for_new(): string
{
    // $html stores an intermediate value used by the surrounding gallery workflow.
    $html = '';
    // $galleries stores an intermediate value used by the surrounding gallery workflow.
    $galleries = db()->query('SELECT id, title, folder_path FROM galleries ORDER BY folder_path')->fetchAll();
    foreach ($galleries as $gallery) {
        $html .= '<option value="' . (int) $gallery['id'] . '">' . e($gallery['title'] . ' (' . $gallery['folder_path'] . ')') . '</option>';
    }
    return $html;
}

/**
 * Handles gallery options for select logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function gallery_options_for_select(): string
{
    // $html stores an intermediate value used by the surrounding gallery workflow.
    $html = '';
    // $galleries stores an intermediate value used by the surrounding gallery workflow.
    $galleries = db()->query('SELECT id, title, folder_path FROM galleries ORDER BY folder_path')->fetchAll();
    foreach ($galleries as $gallery) {
        $html .= '<option value="' . (int) $gallery['id'] . '">' . e($gallery['title'] . ' (' . $gallery['folder_path'] . ')') . '</option>';
    }
    return $html;
}

/**
 * Handles gallery cover options logic for the gallery application.
 * @param mixed $galleryId Input used by this operation.
 * @param mixed $selectedImageId Input used by this operation.
 * @param mixed $includeDescendants Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_cover_options(int $galleryId, int $selectedImageId, bool $includeDescendants = false): string
{
    // $images stores an intermediate value used by the surrounding gallery workflow.
    $images = $includeDescendants ? gallery_cover_choices($galleryId, false) : array_map(static fn (array $image): array => ['image' => $image], gallery_images($galleryId, false));
    // Variable $html stores this steps working value.
    $html = '';
    foreach ($images as $entry) {
        // $image stores an intermediate value used by the surrounding gallery workflow.
        $image = $entry['image'];
        // Variable $selected stores this steps working value.
        $selected = $selectedImageId === (int) $image['id'] ? ' selected' : '';
        // Variable $label stores this steps working value.
        $label = ($image['title'] ?: $image['filename']) . ' (' . $image['relative_path'] . ')';
        if ($includeDescendants && !empty($entry['gallery_title'])) {
            // $label stores an intermediate value used by the surrounding gallery workflow.
            $label = $entry['gallery_title'] . ' - ' . $label;
        }
        $html .= '<option value="' . (int) $image['id'] . '"' . $selected . '>' . e($label) . '</option>';
    }
    return $html;
}

/**
 * Handles unique slug for value logic for the gallery application.
 * @param mixed $slug Input used by this operation.
 * @param mixed $excludeGalleryId Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function unique_slug_for_value(string $slug, int $excludeGalleryId): string
{
    // Variable $pdo stores this steps working value.
    $pdo = db();
    // Variable $base stores this steps working value.
    $base = slugify($slug);
    // Variable $candidate stores this steps working value.
    $candidate = $base;
    // Variable $counter stores this steps working value.
    $counter = 2;
    while (true) {
        // Variable $stmt stores this steps working value.
        $stmt = $pdo->prepare('SELECT id FROM galleries WHERE slug = ? AND id <> ?');
        $stmt->execute([$candidate, $excludeGalleryId]);
        if (!$stmt->fetch()) {
            return $candidate;
        }
        // Variable $candidate stores this steps working value.
        $candidate = $base . '-' . $counter;
        $counter++;
    }
}

