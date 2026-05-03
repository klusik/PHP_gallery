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
    $refresh = null;
    if (request_method() === 'POST') {
        verify_csrf();
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

function cms_admin_new_gallery(): void
{
    require_admin();
    $error = '';
    if (request_method() === 'POST') {
        verify_csrf();
        try {
            $gallery = create_empty_gallery([
                'title' => $_POST['title'] ?? '',
                'folder_name' => $_POST['folder_name'] ?? '',
                'description' => $_POST['description'] ?? '',
                'visibility' => $_POST['visibility'] ?? 'draft',
                'parent_id' => $_POST['parent_id'] ?? 0,
                'voting_enabled' => $_POST['voting_enabled'] ?? 0,
            ]);
            admin_log_event('info', 'gallery.folder_created', 'Admin created an empty gallery folder.', [
                'gallery_id' => (int) $gallery['id'],
                'folder_path' => (string) $gallery['folder_path'],
            ]);
            flash_message('admin_notice', 'Gallery folder created.');
            redirect_to(url_for('admin_edit_gallery', ['id' => $gallery['id']]));
        } catch (Throwable $exception) {
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
    echo '<label>Folder name<input name="folder_name"><span class="muted">Leave empty to derive it from the gallery name.</span></label>';
    echo '<label>Parent gallery<select name="parent_id"><option value="0">No parent</option>' . gallery_parent_options_for_new() . '</select></label>';
    echo '<label>Visibility<select name="visibility">' . visibility_options('draft') . '</select></label>';
    echo '<label><input type="checkbox" name="voting_enabled" value="1"> Enable image voting for this gallery</label>';
    echo '<label>Description<textarea name="description"></textarea></label>';
    echo '<button type="submit">Create gallery folder</button></form></section>';
    render_footer();
}

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
            $expandedIds = array_merge($expandedIds, gallery_subtree_ids($galleryId));
        }
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
            $expandedIds = array_merge($expandedIds, gallery_subtree_ids($galleryId));
        }
        $expandedIds = array_values(array_unique(array_filter($expandedIds)));
        if ($expandedIds) {
            // Variable $placeholders stores this steps working value.
            $placeholders = implode(',', array_fill(0, count($expandedIds), '?'));
            // Variable $stmt stores this steps working value.
            $stmt = db()->prepare('UPDATE galleries SET voting_enabled = ?, updated_at = ? WHERE id IN (' . $placeholders . ')');
            $stmt->execute(array_merge([$action === 'vote_on' ? 1 : 0, now_sql()], $expandedIds));
            if ($action === 'vote_off') {
                $stmt = db()->prepare('UPDATE galleries SET picture_game_enabled = 0, updated_at = ? WHERE id IN (' . $placeholders . ')');
                $stmt->execute(array_merge([now_sql()], $expandedIds));
            }
        }
        flash_message('admin_notice', 'Updated ' . count($expandedIds) . ' gallery folder(s).');
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
            $expandedIds = array_merge($expandedIds, gallery_subtree_ids($galleryId));
        }
        $expandedIds = array_values(array_unique(array_filter($expandedIds)));
        if ($expandedIds) {
            // Variable $placeholders stores this steps working value.
            $placeholders = implode(',', array_fill(0, count($expandedIds), '?'));
            // Variable $stmt stores this steps working value.
            $stmt = db()->prepare('UPDATE galleries SET picture_game_enabled = ?, updated_at = ? WHERE id IN (' . $placeholders . ')');
            $stmt->execute(array_merge([$action === 'game_on' ? 1 : 0, now_sql()], $expandedIds));
            if ($action === 'game_on') {
                $stmt = db()->prepare('UPDATE galleries SET voting_enabled = 1, updated_at = ? WHERE id IN (' . $placeholders . ')');
                $stmt->execute(array_merge([now_sql()], $expandedIds));
            }
        }
        flash_message('admin_notice', 'Updated ' . count($expandedIds) . ' gallery folder(s).');
        redirect_to(url_for('admin'));
    }
    redirect_to(url_for('admin'));
}

function cms_admin_regenerate_paths(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();
    try {
        $result = regenerate_public_paths();
        flash_message('admin_notice', 'Regenerated clean public paths. Updated ' . (int) $result['galleries'] . ' gallery path(s) and ' . (int) $result['images'] . ' image path(s).');
        redirect_to(url_for('admin'));
    } catch (Throwable $exception) {
        flash_message('admin_notice', 'Path regeneration failed: ' . $exception->getMessage());
        redirect_to(url_for('admin'));
    }
}

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
        if ($pictureGameEnabled) {
            $votingEnabled = 1;
        }
        if (!$votingEnabled) {
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
            $accessPasswordHash = null;
        }
        if ($accessReady && !empty($_POST['clear_access_password'])) {
            $accessPasswordHash = null;
        }
        // Variable $newAccessPassword stores this steps working value.
        $newAccessPassword = trim((string) ($_POST['access_password'] ?? ''));
        if ($accessReady && $accessType === 'password' && $newAccessPassword !== '') {
            $accessPasswordHash = password_hash($newAccessPassword, PASSWORD_DEFAULT);
        }
        // Variable $parentId stores this steps working value.
        $parentId = (int) ($_POST['parent_id'] ?? 0);
        // Variable $parentId stores this steps working value.
        $parentId = $parentId > 0 && find_gallery($parentId) ? $parentId : null;
        $currentFolderName = gallery_folder_name_from_path((string) $gallery['folder_path']);
        $submittedFolderName = trim((string) ($_POST['folder_name'] ?? $currentFolderName));
        $folderNameChanged = $submittedFolderName !== '' && $submittedFolderName !== $currentFolderName;
        $moveResult = null;
        if ((int) ($gallery['parent_id'] ?? 0) !== (int) ($parentId ?? 0) || $folderNameChanged) {
            try {
                $moveResult = move_gallery_folder_to_parent((int) $gallery['id'], $parentId, $folderNameChanged ? $submittedFolderName : null);
                if (!empty($moveResult['moved'])) {
                    admin_log_event('info', 'gallery.folder_moved', 'Admin moved a gallery folder.', [
                        'gallery_id' => (int) $gallery['id'],
                        'from' => (string) $moveResult['from'],
                        'to' => (string) $moveResult['to'],
                        'galleries' => (int) $moveResult['galleries'],
                    ]);
                }
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
        $coverImagePath = gallery_cover_asset_schema_ready() ? gallery_cover_path($gallery) : null;
        $backgroundSource = null;
        if (gallery_background_source_schema_ready()) {
            $submittedBackgroundSource = (string) ($_POST['background_source'] ?? '');
            if (in_array($submittedBackgroundSource, ['upload', 'existing', 'collage'], true)) {
                $backgroundSource = $submittedBackgroundSource;
            }
        }
        if (gallery_cover_asset_schema_ready() && !empty($_FILES['cover_upload']['name'] ?? '')) {
            $uploadError = (int) ($_FILES['cover_upload']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError !== UPLOAD_ERR_NO_FILE) {
                if ($uploadError !== UPLOAD_ERR_OK) {
                    throw new RuntimeException(upload_error_message($uploadError));
                }
                $tmpName = (string) ($_FILES['cover_upload']['tmp_name'] ?? '');
                if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                    throw new RuntimeException('Uploaded thumbnail is not available.');
                }
                $info = @getimagesize($tmpName);
                if ($info === false || empty($info['mime']) || !str_starts_with((string) $info['mime'], 'image/')) {
                    throw new RuntimeException('The uploaded gallery thumbnail is not a valid image.');
                }
                $coverImagePath = store_uploaded_gallery_cover((int) $gallery['id'], $_FILES['cover_upload']);
                $coverImageId = null;
            }
        }
        // Variable $slug stores this steps working value.
        $slug = $slug !== '' ? slugify($slug) : unique_slug(db(), $title, (int) $gallery['id']);
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
        $stmt = db()->prepare('UPDATE galleries SET ' . implode(', ', array_keys($fields)) . ' WHERE id = ?');
        $stmt->execute(array_merge(array_values($fields), [(int) $gallery['id']]));
        if ($accessReady) {
            $accessAction = (string) ($_POST['access_action'] ?? 'save');
            if ($accessAction === 'revoke_link') {
                revoke_gallery_share_token((int) $gallery['id']);
            }
        }
        if ($accessReady && $accessMode === 'password') {
            $needsShareLink = $accessType === 'share' && empty($gallery['access_token_hash']);
            if ($accessAction === 'generate_link' || $needsShareLink) {
                $expires = trim((string) ($_POST['access_token_expires_at'] ?? ''));
                $expiresTimestamp = $expires !== '' ? strtotime($expires) : false;
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
        $notice = 'Gallery saved.';
        if (!empty($moveResult['moved'])) {
            $notice = 'Gallery saved and folder moved.';
        }
        flash_message('admin_notice', $notice);
        redirect_to(url_for('admin_edit_gallery', ['id' => $gallery['id']]));
    }
    // Variable $images stores this steps working value.
    $images = gallery_images((int) $gallery['id'], false);
    render_header('Edit gallery');
    $galleryError = (string) ($_SESSION['admin_gallery_error_' . (int) $gallery['id']] ?? '');
    unset($_SESSION['admin_gallery_error_' . (int) $gallery['id']]);
    if ($galleryError !== '') {
        echo '<div class="notice">Gallery folder move failed: ' . e($galleryError) . '</div>';
    }
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
    echo '<section class="panel"><h1>Edit gallery</h1><form method="post" enctype="multipart/form-data" class="form-grid">' . csrf_field();
    echo '<input type="hidden" name="id" value="' . (int) $gallery['id'] . '">';
    echo '<label>Title<input name="title" value="' . e($gallery['title']) . '" required></label>';
    echo '<label>Description<textarea name="description">' . e($gallery['description']) . '</textarea></label>';
    echo '<label>Slug<input name="slug" value="' . e($gallery['slug']) . '" required></label>';
    echo '<label>Folder name<input name="folder_name" value="' . e(gallery_folder_name_from_path((string) $gallery['folder_path'])) . '" required><span class="muted">Changing this renames the folder on disk.</span></label>';
    echo '<label>Parent gallery<select name="parent_id"><option value="0">No parent</option>' . gallery_parent_options($gallery) . '</select></label>';
    echo '<label>Visibility<select name="visibility">' . visibility_options((string) $gallery['visibility']) . '</select></label>';
    if ($accessReady) {
        $newShareToken = (string) ($_SESSION['new_gallery_share_token_' . (int) $gallery['id']] ?? '');
        unset($_SESSION['new_gallery_share_token_' . (int) $gallery['id']]);
        $currentAccessType = 'normal';
        if ((string) ($gallery['access_mode'] ?? 'normal') === 'password') {
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
        $visibleShareToken = $newShareToken !== '' ? $newShareToken : gallery_share_token_for_admin($gallery);
        if ($visibleShareToken !== null && $visibleShareToken !== '') {
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
    echo '<section class="panel"><h2>Images</h2><form method="post" action="' . e(url_for('admin_bulk_images')) . '">' . csrf_field();
    echo '<input type="hidden" name="gallery_id" value="' . (int) $gallery['id'] . '">';
    echo '<div class="bulk-row"><label><input type="checkbox" data-select-all="image_ids[]"> Select all images</label><label>Bulk action<select name="action"><option value="public">Set public</option><option value="draft">Set draft</option><option value="private">Set private</option><option value="cover">Set as title picture</option><option value="thumbs">Create thumbnails</option></select></label><button type="submit">Apply to selected</button><button type="submit" class="secondary" name="thumbnail_gallery_id" value="' . (int) $gallery['id'] . '" formaction="' . e(url_for('admin_create_thumbnails')) . '">Create gallery thumbnails</button></div>';
    echo '<table><thead><tr><th>Select</th><th>Preview</th><th>Image</th><th>Status</th><th>Cover</th><th>Actions</th></tr></thead><tbody>';
    foreach ($images as $image) {
        // Variable $isCover stores this steps working value.
        $isCover = (int) ($gallery['cover_image_id'] ?? 0) === (int) $image['id'];
        echo '<tr><td><input type="checkbox" name="image_ids[]" value="' . (int) $image['id'] . '"></td>';
        echo '<td><img class="admin-thumb" decoding="async" loading="lazy" src="' . e(thumbnail_url($image, 300)) . '" alt=""></td>';
        echo '<td>' . e($image['relative_path']) . '</td><td>' . e($image['visibility']) . '</td><td>' . ($isCover ? 'Title picture' : '') . '</td><td><a href="' . e(url_for('admin_edit_image', ['id' => $image['id']])) . '">Edit</a></td></tr>';
    }
    echo '</tbody></table></form></section>';
    render_admin_devmode_panel();
    render_footer();
}

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
                $redirect = gallery_public_url($parent);
            }
        }
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('DELETE FROM galleries WHERE id = ?');
        $stmt->execute([(int) $gallery['id']]);
        redirect_to($redirect);
    }
    if ($action === 'publish') {
        $visibility = 'public';
    }
    if ($action === 'hide') {
        $visibility = 'private';
    }
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('UPDATE galleries SET title = ?, description = ?, visibility = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$title, (string) ($_POST['description'] ?? ''), $visibility, now_sql(), (int) $gallery['id']]);
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
        $visibility = 'public';
    }
    if ($action === 'hide') {
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

function visibility_options(string $selected): string
{
    // Variable $html stores this steps working value.
    $html = '';
    foreach (['draft', 'public', 'private'] as $visibility) {
        $html .= '<option value="' . e($visibility) . '"' . ($visibility === $selected ? ' selected' : '') . '>' . e($visibility) . '</option>';
    }
    return $html;
}

function render_tag_datalist(): void
{
    echo '<datalist id="tag-suggestions">';
    foreach (all_tag_names() as $name) {
        echo '<option value="' . e((string) $name) . '"></option>';
    }
    echo '</datalist>';
}

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

function gallery_parent_options_for_new(): string
{
    $html = '';
    $galleries = db()->query('SELECT id, title, folder_path FROM galleries ORDER BY folder_path')->fetchAll();
    foreach ($galleries as $gallery) {
        $html .= '<option value="' . (int) $gallery['id'] . '">' . e($gallery['title'] . ' (' . $gallery['folder_path'] . ')') . '</option>';
    }
    return $html;
}

function gallery_options_for_select(): string
{
    $html = '';
    $galleries = db()->query('SELECT id, title, folder_path FROM galleries ORDER BY folder_path')->fetchAll();
    foreach ($galleries as $gallery) {
        $html .= '<option value="' . (int) $gallery['id'] . '">' . e($gallery['title'] . ' (' . $gallery['folder_path'] . ')') . '</option>';
    }
    return $html;
}

function gallery_cover_options(int $galleryId, int $selectedImageId, bool $includeDescendants = false): string
{
    $images = $includeDescendants ? gallery_cover_choices($galleryId, false) : array_map(static fn (array $image): array => ['image' => $image], gallery_images($galleryId, false));
    // Variable $html stores this steps working value.
    $html = '';
    foreach ($images as $entry) {
        $image = $entry['image'];
        // Variable $selected stores this steps working value.
        $selected = $selectedImageId === (int) $image['id'] ? ' selected' : '';
        // Variable $label stores this steps working value.
        $label = ($image['title'] ?: $image['filename']) . ' (' . $image['relative_path'] . ')';
        if ($includeDescendants && !empty($entry['gallery_title'])) {
            $label = $entry['gallery_title'] . ' - ' . $label;
        }
        $html .= '<option value="' . (int) $image['id'] . '"' . $selected . '>' . e($label) . '</option>';
    }
    return $html;
}

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

