<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles request-level application logic for the related gallery feature.
 *
 * Responsibilities:
 *   - Validate and route incoming request data
 *   - Call service-layer functions where possible
 *   - Return redirects, rendered views, or HTTP responses
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
 *   2026-05-04
 */

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
    // $prefillParentId stores the gallery that should be pre-selected when this form is opened from a public gallery page.
    $prefillParentId = selected_gallery_id_from_query('parent_id');
    // $prefillParentGallery stores the validated parent gallery record used for contextual helper text.
    $prefillParentGallery = $prefillParentId > 0 ? find_gallery($prefillParentId) : null;
    // $isPanelRequest stores whether the create form should render as a reusable side-panel fragment.
    $isPanelRequest = admin_gallery_create_panel_request();
    // $error stores an intermediate value used by the surrounding gallery workflow.
    $error = '';
    if (request_method() === 'POST') {
        verify_csrf();
        try {
            // $gallery stores an intermediate value used by the surrounding gallery workflow.
            $gallery = create_empty_gallery(admin_new_gallery_input_from_post());
            admin_log_event('info', 'gallery.folder_created', 'Admin created an empty gallery folder.', [
                'gallery_id' => (int) $gallery['id'],
                'folder_path' => (string) $gallery['folder_path'],
            ]);
            if (admin_wants_json()) {
                header('Content-Type: application/json');
                echo json_encode(admin_new_gallery_success_response($gallery));
                return;
            }
            flash_message('admin_notice', 'Gallery folder created.');
            redirect_to(url_for('admin_edit_gallery', ['id' => $gallery['id'], 'created' => 1]));
        } catch (Throwable $exception) {
            // $error stores an intermediate value used by the surrounding gallery workflow.
            $error = $exception->getMessage();
            admin_log_event('error', 'gallery.folder_create_failed', 'Admin empty gallery creation failed.', ['error' => $error]);
            if (admin_wants_json()) {
                http_response_code(422);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => $error]);
                return;
            }
        }
    }

    if ($isPanelRequest) {
        header('Content-Type: text/html; charset=UTF-8');
        render_admin_new_gallery_side_panel($prefillParentId, $prefillParentGallery, $error);
        return;
    }

    render_header('Create empty gallery');
    echo '<section class="hero"><h1>Create empty gallery</h1><nav class="nav"><a class="button secondary" href="' . e(url_for('admin')) . '">Back to dashboard</a><a class="button secondary" href="' . e(url_for('admin_upload')) . '">Upload photos</a></nav></section>';
    if ($prefillParentGallery) {
        echo '<div class="notice">New gallery will be created inside: ' . e((string) $prefillParentGallery['title']) . '.</div>';
    }
    if ($error !== '') {
        echo '<div class="notice">Create failed: ' . e($error) . '</div>';
    }
    echo '<section class="panel"><form method="post" action="' . e(url_for('admin_new_gallery')) . '" class="form-grid">' . csrf_field();
    render_admin_new_gallery_fields($prefillParentId, false);
    echo '<button type="submit">Create gallery folder</button></form></section>';
    render_footer();
}

/**
 * Return whether the create-gallery page is being requested as side-panel content.
 */
function admin_gallery_create_panel_request(): bool
{
    return admin_side_panel_request();
}

/**
 * Return whether the current admin route is being requested for side-panel use.
 */
function admin_side_panel_request(): bool
{
    return !empty($_GET['panel']) || !empty($_POST['panel']);
}

/**
 * Read create-gallery POST values through the same input contract used by the direct admin page.
 */
function admin_new_gallery_input_from_post(): array
{
    return [
        'title' => $_POST['title'] ?? '',
        'folder_name' => $_POST['folder_name'] ?? '',
        'description' => $_POST['description'] ?? '',
        'visibility' => gallery_visibility_storage_value((string) ($_POST['visibility'] ?? 'unpublished')),
        'parent_id' => $_POST['parent_id'] ?? 0,
        'voting_enabled' => $_POST['voting_enabled'] ?? 0,
        'show_filenames' => $_POST['show_filenames'] ?? 0,
    ];
}

/**
 * Build the JSON payload consumed by the progressive side-panel workflow.
 */
function admin_new_gallery_success_response(array $gallery): array
{
    // $parentGalleryId stores the persisted parent selected by the admin create form.
    $parentGalleryId = (int) ($gallery['parent_id'] ?? 0);
    // $parentGallery stores the parent row used by the side-panel refresh contract.
    $parentGallery = $parentGalleryId > 0 ? find_gallery($parentGalleryId, true) : null;
    // $parentGalleryUrl stores the public parent URL when the gallery was created below another gallery.
    $parentGalleryUrl = is_array($parentGallery) ? gallery_public_url($parentGallery) : '';

    return [
        'ok' => true,
        'message' => 'Gallery folder created.',
        'gallery_id' => (int) $gallery['id'],
        'gallery_title' => (string) ($gallery['title'] ?? ''),
        'gallery_url' => gallery_public_url($gallery),
        'edit_url' => url_for('admin_edit_gallery', ['id' => $gallery['id'], 'created' => 1]),
        'parent_gallery_id' => $parentGalleryId,
        'parent_gallery_url' => $parentGalleryUrl,
        'refresh_url' => $parentGalleryUrl !== '' ? $parentGalleryUrl : url_for('home'),
        'refresh_gallery_id' => $parentGalleryId,
    ];
}

/**
 * Render create-gallery fields shared by the full admin page and the side-panel fragment.
 */
function render_admin_new_gallery_fields(int $prefillParentId, bool $panelMode): void
{
    if ($panelMode) {
        echo '<input type="hidden" name="panel" value="1">';
    }
    if ($panelMode) {
        echo '<div class="admin-side-panel-card admin-side-panel-primary-card"><div class="admin-side-panel-card-heading"><div><p class="admin-kicker">New gallery</p><h3>Gallery identity</h3></div><p class="muted">Only the gallery is created here.</p></div><div class="admin-side-panel-field-grid">';
        echo '<label class="admin-side-panel-field admin-side-panel-field-wide"><span>Gallery name</span><input name="title" required></label>';
        echo '<label class="admin-side-panel-field"><span>Folder name</span><input name="folder_name" autocomplete="off"><small>Leave empty to derive it from the gallery name.</small></label>';
        echo '<label class="admin-side-panel-field"><span>Visibility</span><select name="visibility">' . visibility_options('unpublished') . '</select></label>';
        echo '<label class="admin-side-panel-field admin-side-panel-field-wide"><span>Parent gallery</span><select name="parent_id"><option value="0"' . ($prefillParentId === 0 ? ' selected' : '') . '>No parent</option>' . gallery_parent_options_for_new($prefillParentId) . '</select></label>';
        echo '<label class="admin-side-panel-field admin-side-panel-field-wide"><span>Description</span><textarea name="description" rows="4"></textarea></label>';
        echo '</div><div class="admin-side-panel-toggle-row">';
        echo '<label><input type="checkbox" name="voting_enabled" value="1"> <span>Enable image voting</span></label>';
        echo '<label><input type="checkbox" name="show_filenames" value="1"> <span>Show file names</span></label>';
        echo '</div></div>';
        return;
    }
    echo '<label>Gallery name<input name="title" required></label>';
    echo '<label>Folder name<input name="folder_name" autocomplete="off"><span class="muted">Leave empty to derive it from the gallery name.</span></label>';
    echo '<label>Parent gallery<select name="parent_id"><option value="0"' . ($prefillParentId === 0 ? ' selected' : '') . '>No parent</option>' . gallery_parent_options_for_new($prefillParentId) . '</select></label>';
    echo '<label>Visibility<select name="visibility">' . visibility_options('unpublished') . '</select></label>';
    echo '<label><input type="checkbox" name="voting_enabled" value="1"> Enable image voting for this gallery</label>';
    echo '<label><input type="checkbox" name="show_filenames" value="1"> Show file names</label>';
    echo '<label>Description<textarea name="description"></textarea></label>';
}

/**
 * Render the focused side-panel create workflow without the normal admin shell.
 */
function render_admin_new_gallery_side_panel(int $prefillParentId, ?array $prefillParentGallery, string $error): void
{
    echo '<div class="admin-side-panel-stack" data-gallery-create-panel>';
    echo '<div class="admin-side-panel-copy"><p class="admin-kicker">Gallery workflow</p><h2>Create gallery</h2><p class="muted">Create a new empty gallery in the selected parent. Photo upload stays in the separate upload workflow.</p></div>';
    if ($prefillParentGallery) {
        echo '<div class="notice">Target parent: ' . e((string) $prefillParentGallery['title']) . '.</div>';
    }
    if ($error !== '') {
        echo '<div class="notice">Create failed: ' . e($error) . '</div>';
    }
    echo '<section class="admin-side-panel-workflow" data-gallery-panel-workflow>';
    echo '<form method="post" action="' . e(url_for('admin_new_gallery')) . '" class="admin-side-panel-form" data-gallery-panel-create-form>' . csrf_field();
    render_admin_new_gallery_fields($prefillParentId, true);
    echo '<div class="admin-side-panel-actions"><button type="submit" class="button primary" data-gallery-panel-submit>Create gallery</button><p class="muted">The new gallery is created empty. Use Upload photos for media.</p></div>';
    echo '</form></section>';
    echo '</div>';
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
 * Handles cms admin gallery reorder logic for the gallery application.
 *
 * The Admin dashboard sends the complete flattened gallery tree after a drag
 * operation. The submitted list is validated as an exact set match against the
 * database before any filesystem move or sort_order update is attempted. Parent
 * changes are delegated to move_gallery_folder_to_parent(), so the gallery
 * folder tree remains the source of truth and database paths follow disk state.
 *
 * @return mixed Result produced by this operation.
 */
function cms_admin_reorder_galleries(): void
{
    // The reorder endpoint must return clean JSON. Some shared-hosting setups
    // print PHP warnings as HTML when display_errors is enabled, so this small
    // response buffer lets the endpoint log and discard accidental diagnostic
    // output before the final JSON payload is emitted.
    $jsonResponseBufferStarted = false;
    if (!headers_sent()) {
        ob_start();
        $jsonResponseBufferStarted = true;
    }

    require_admin();
    verify_csrf();
    // Variable $rawTree stores the JSON payload submitted by the JavaScript nested ordering handler.
    $rawTree = (string) ($_POST['gallery_tree'] ?? '[]');
    // Variable $decodedTree stores the decoded row list before it is normalized.
    $decodedTree = json_decode($rawTree, true);
    if (!is_array($decodedTree)) {
        admin_reorder_galleries_response(false, 'The submitted gallery tree was not valid JSON.', $jsonResponseBufferStarted);
        return;
    }

    // Variable $submittedEntries stores normalized id and parent-id pairs in the exact submitted order.
    $submittedEntries = [];
    foreach ($decodedTree as $entry) {
        if (!is_array($entry)) {
            admin_reorder_galleries_response(false, 'The submitted gallery tree contained an invalid row.', $jsonResponseBufferStarted);
            return;
        }
        // Variable $galleryId stores the gallery id from one submitted tree row.
        $galleryId = (int) ($entry['id'] ?? 0);
        // Variable $parentId stores the requested parent id for one submitted tree row.
        $parentId = (int) ($entry['parent_id'] ?? 0);
        if ($galleryId <= 0 || $parentId < 0) {
            admin_reorder_galleries_response(false, 'The submitted gallery tree contained an invalid gallery id.', $jsonResponseBufferStarted);
            return;
        }
        $submittedEntries[] = ['id' => $galleryId, 'parent_id' => $parentId];
    }
    if (!$submittedEntries) {
        admin_reorder_galleries_response(false, 'No galleries were submitted for reordering.', $jsonResponseBufferStarted);
        return;
    }

    // Variable $submittedIds stores the submitted gallery id list for set validation.
    $submittedIds = array_map(static fn (array $entry): int => (int) $entry['id'], $submittedEntries);
    if (count($submittedIds) !== count(array_unique($submittedIds))) {
        admin_reorder_galleries_response(false, 'The submitted gallery tree contained duplicate galleries.', $jsonResponseBufferStarted);
        return;
    }

    sync_gallery_parent_ids();
    // Variable $currentRows stores current database ids and parent ids for validation and change detection.
    $currentRows = db()->query('SELECT id, parent_id FROM galleries ORDER BY id')->fetchAll();
    // Variable $currentIds stores all gallery ids currently known by the database.
    $currentIds = array_map(static fn (array $row): int => (int) $row['id'], $currentRows);
    // Variable $sortedSubmittedIds stores the submitted id set in sorted order.
    $sortedSubmittedIds = $submittedIds;
    sort($sortedSubmittedIds);
    sort($currentIds);
    if ($sortedSubmittedIds !== $currentIds) {
        admin_reorder_galleries_response(false, 'The gallery list changed while you were reordering. Reload the page and try again.', $jsonResponseBufferStarted);
        return;
    }

    // Variable $validIds stores gallery ids as a lookup table for parent validation.
    $validIds = array_fill_keys($currentIds, true);
    // Variable $seenIds stores ids already encountered in submitted tree order.
    $seenIds = [];
    foreach ($submittedEntries as $entry) {
        // Variable $galleryId stores the current gallery id being validated.
        $galleryId = (int) $entry['id'];
        // Variable $parentId stores the requested parent id being validated.
        $parentId = (int) $entry['parent_id'];
        if ($parentId === $galleryId) {
            admin_reorder_galleries_response(false, 'A gallery cannot be moved under itself.', $jsonResponseBufferStarted);
            return;
        }
        if ($parentId > 0 && !isset($validIds[$parentId])) {
            admin_reorder_galleries_response(false, 'The submitted gallery tree referenced a missing parent gallery.', $jsonResponseBufferStarted);
            return;
        }
        if ($parentId > 0 && !isset($seenIds[$parentId])) {
            admin_reorder_galleries_response(false, 'A subgallery must appear below its parent in the submitted tree.', $jsonResponseBufferStarted);
            return;
        }
        $seenIds[$galleryId] = true;
    }

    // Variable $currentParentById stores current parent ids keyed by gallery id.
    $currentParentById = [];
    foreach ($currentRows as $row) {
        $currentParentById[(int) $row['id']] = (int) ($row['parent_id'] ?? 0);
    }

    // Variable $pdo stores the active database connection used for sibling order updates.
    $pdo = db();
    // Variable $now stores one timestamp shared by all sort_order updates.
    $now = now_sql();
    // Variable $movedCount stores how many gallery folders changed parent.
    $movedCount = 0;
    // Variable $reorderDiagnostics stores filesystem details for moved galleries if saving fails.
    $reorderDiagnostics = [];
    // Variable $activeMoveDiagnostics stores the move currently being processed when an exception is raised.
    $activeMoveDiagnostics = null;
    try {
        foreach ($submittedEntries as $entry) {
            // Variable $galleryId stores the gallery being checked for a parent move.
            $galleryId = (int) $entry['id'];
            // Variable $parentId stores the requested parent id, with zero meaning root.
            $parentId = (int) $entry['parent_id'];
            if (($currentParentById[$galleryId] ?? 0) === $parentId) {
                continue;
            }
            $activeMoveDiagnostics = admin_gallery_reorder_move_diagnostics($galleryId, $parentId > 0 ? $parentId : null);
            $reorderDiagnostics[] = $activeMoveDiagnostics;
            move_gallery_folder_to_parent($galleryId, $parentId > 0 ? $parentId : null);
            $movedCount++;
            $activeMoveDiagnostics = null;
            sync_gallery_parent_ids();
        }

        // Variable $siblingPositionByParent stores the next sort index for each parent id.
        $siblingPositionByParent = [];
        $pdo->beginTransaction();
        // Variable $stmt stores the prepared update reused for each reordered gallery row.
        $stmt = $pdo->prepare('UPDATE galleries SET sort_order = ?, updated_at = ? WHERE id = ?');
        foreach ($submittedEntries as $entry) {
            // Variable $parentId stores the submitted parent group whose sibling order is being assigned.
            $parentId = (int) $entry['parent_id'];
            // Variable $position stores the next sibling position in this parent group.
            $position = ($siblingPositionByParent[$parentId] ?? 0) + 1;
            $siblingPositionByParent[$parentId] = $position;
            // Variable $sortOrder stores a spaced integer so future maintenance can insert between rows if needed.
            $sortOrder = $position * 10;
            $stmt->execute([$sortOrder, $now, (int) $entry['id']]);
        }
        $pdo->commit();

        sync_gallery_parent_ids();

        // Sidecar and clean URL refresh are follow-up maintenance tasks. The
        // visible tree and the database order have already been saved at this
        // point, so a stale or missing folder must not turn a successful move
        // into a red failure message for the admin.
        $maintenanceWarnings = [];
        foreach ($submittedEntries as $entry) {
            try {
                // Variable $gallery stores the refreshed row written to its gallery.json sidecar.
                $gallery = find_gallery((int) $entry['id'], true);
                if ($gallery) {
                    write_gallery_sidecar($gallery);
                }
            } catch (Throwable $sidecarException) {
                $maintenanceWarnings[] = $sidecarException->getMessage();
            }
        }
        if (public_path_schema_ready()) {
            try {
                regenerate_public_paths();
            } catch (Throwable $publicPathException) {
                $maintenanceWarnings[] = $publicPathException->getMessage();
            }
        }

        admin_log_event('info', 'gallery.reordered', 'Admin reordered gallery tree.', [
            'galleries' => count($submittedEntries),
            'moved_folders' => $movedCount,
            'maintenance_warnings' => array_values(array_unique($maintenanceWarnings)),
        ]);

        if ($maintenanceWarnings) {
            admin_log_event('warning', 'gallery.reorder_maintenance_warning', 'Gallery reorder was saved, but a follow-up refresh reported a warning.', [
                'warnings' => array_values(array_unique($maintenanceWarnings)),
            ]);
            admin_reorder_galleries_response(true, 'Gallery moved. The visible order is saved. Some gallery metadata will be refreshed during the next maintenance scan.', $jsonResponseBufferStarted);
            return;
        }

        admin_reorder_galleries_response(true, $movedCount > 0 ? 'Gallery moved and saved.' : 'Gallery order saved.', $jsonResponseBufferStarted);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        admin_log_event('error', 'gallery.reorder_failed', 'Admin gallery reorder failed.', [
            'error' => $exception->getMessage(),
            'exception_class' => get_class($exception),
            'previous_error' => $exception->getPrevious() ? $exception->getPrevious()->getMessage() : null,
            'submitted_entries' => $submittedEntries,
            'moved_before_failure' => $movedCount,
            'active_move' => $activeMoveDiagnostics,
            'move_diagnostics' => $reorderDiagnostics,
            'gallery_root' => gallery_path_diagnostics('', 'configured gallery root'),
        ]);
        admin_reorder_galleries_response(false, admin_reorder_galleries_user_error_message($exception), $jsonResponseBufferStarted);
    }
}



/**
 * Build diagnostic context for one requested gallery hierarchy move.
 *
 * The returned array is written only to the admin log. It intentionally keeps
 * low-level filesystem details out of the red UI message while making the real
 * configured root, source folder, parent folder, and target folder visible for
 * troubleshooting.
 */
function admin_gallery_reorder_move_diagnostics(int $galleryId, ?int $parentId): array
{
    // $gallery stores the gallery row before the filesystem move is attempted.
    $gallery = find_gallery($galleryId);
    // $parent stores the requested parent row before the filesystem move is attempted.
    $parent = $parentId !== null && $parentId > 0 ? find_gallery($parentId) : null;
    // $diagnostics stores the full context used by admin logs.
    $diagnostics = [
        'gallery_id' => $galleryId,
        'requested_parent_id' => $parentId,
        'gallery_found' => $gallery !== null,
        'parent_found' => $parentId === null || $parent !== null,
    ];

    if (!$gallery) {
        return $diagnostics;
    }

    // $oldPath stores the gallery folder path before the move.
    $oldPath = normalize_relative_path((string) $gallery['folder_path']);
    // $folderName stores the final directory segment that should be preserved when the gallery is moved.
    $folderName = gallery_folder_name_from_path($oldPath);
    // $newPath stores the expected destination path based on the submitted parent id.
    $newPath = $parent ? normalize_relative_path((string) $parent['folder_path'] . '/' . $folderName) : $folderName;

    $diagnostics += [
        'gallery_title' => (string) ($gallery['title'] ?? ''),
        'old_parent_id' => isset($gallery['parent_id']) ? (int) $gallery['parent_id'] : null,
        'old_folder_path' => $oldPath,
        'expected_new_folder_path' => $newPath,
        'folder_name' => $folderName,
        'parent_title' => $parent ? (string) ($parent['title'] ?? '') : null,
        'parent_folder_path' => $parent ? normalize_relative_path((string) $parent['folder_path']) : null,
        'source_path' => gallery_path_diagnostics($oldPath, 'move source'),
        'target_path' => gallery_path_diagnostics($newPath, 'move target'),
    ];

    if ($parent) {
        $diagnostics['parent_path'] = gallery_path_diagnostics((string) $parent['folder_path'], 'requested parent');
    }

    return $diagnostics;
}

/**
 * Convert internal gallery reorder exceptions into admin-facing language.
 *
 * @param Throwable $exception Original exception raised while saving the gallery tree.
 * @return string Message safe to show directly in the admin interface.
 */
function admin_reorder_galleries_user_error_message(Throwable $exception): string
{
    // Variable $message stores the technical message used only for mapping.
    $message = $exception->getMessage();

    if (str_contains($message, 'outside the configured root')) {
        return 'This gallery could not be moved because one of its folders is not inside the configured gallery storage folder. The gallery was left in its previous safe location.';
    }
    if (str_contains($message, 'target parent is outside the configured root or does not exist')) {
        return 'This gallery could not be moved because the destination folder is not available. Refresh the page and try again.';
    }
    if (str_contains($message, 'Gallery target path is outside the configured root')) {
        return 'This gallery could not be moved because the requested destination is outside the gallery storage folder. The gallery was left in its previous safe location.';
    }
    if (str_contains($message, 'Current gallery folder does not exist on disk')) {
        return 'This gallery could not be moved because its folder is missing on disk. Run a gallery scan before reordering it.';
    }
    if (str_contains($message, 'Destination folder already exists on disk') || str_contains($message, 'Another gallery already uses the destination folder path')) {
        return 'This gallery could not be moved because another folder already uses that destination. Rename one of the galleries and try again.';
    }
    if (str_contains($message, 'own subgalleries')) {
        return 'This gallery cannot be moved into one of its own subgalleries.';
    }
    if (str_contains($message, 'A subgallery must appear below its parent')) {
        return 'The gallery order was not saved because the submitted tree was incomplete. Refresh the page and try again.';
    }

    return 'Gallery order could not be saved. Refresh the page and try again.';
}

/**
 * Sends a JSON response for Admin gallery reorder requests.
 *
 * @param bool $ok Whether the operation completed successfully.
 * @param string $message Human-readable result message.
 * @return void
 */
function admin_reorder_galleries_response(bool $ok, string $message, bool $cleanBufferedOutput = false): void
{
    // $unexpectedOutput stores accidental HTML warnings or notices generated
    // before the JSON response. This keeps the browser-side fetch parser from
    // seeing "<br /><b>Warning" before the actual JSON object.
    $unexpectedOutput = '';
    if ($cleanBufferedOutput && ob_get_level() > 0) {
        $unexpectedOutput = (string) ob_get_clean();
    }

    if ($unexpectedOutput !== '') {
        admin_log_event($ok ? 'warning' : 'error', 'gallery.reorder_response_output_discarded', 'Gallery reorder generated output before its JSON response.', [
            'operation_saved' => $ok,
            'message' => $message,
            'discarded_output_preview' => mb_substr(trim(strip_tags($unexpectedOutput)), 0, 1000),
            'discarded_output_bytes' => strlen($unexpectedOutput),
        ]);
    }

    header('Content-Type: application/json');
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_THROW_ON_ERROR);
}



/**
 * Calculates a full order after replacing exactly one visible pagination slice.
 *
 * Public gallery page reordering intentionally submits only the cards that are
 * visible on the current pagination page. The server verifies that the posted
 * ids still match the same offset and count in the current database order, then
 * returns the complete sibling order with only that slice rearranged.
 *
 * @param array<int> $currentIds Complete current sibling order from the database.
 * @param array<int> $submittedIds Reordered ids submitted by the browser.
 * @param int $visibleOffset Zero-based offset of the visible pagination page.
 * @param int $visibleCount Number of ids rendered on the visible page.
 * @return array<int>|null Complete order after the visible slice is replaced, or null when validation fails.
 */
function admin_visible_page_reordered_ids(array $currentIds, array $submittedIds, int $visibleOffset, int $visibleCount): ?array
{
    if ($visibleOffset < 0 || $visibleCount < 1 || count($submittedIds) !== $visibleCount) {
        return null;
    }

    // $visibleSlice stores the database ids that belong to this exact pagination page.
    $visibleSlice = array_slice($currentIds, $visibleOffset, $visibleCount);
    if (count($visibleSlice) !== $visibleCount) {
        return null;
    }

    // $expectedIds stores the visible ids sorted for set comparison.
    $expectedIds = $visibleSlice;
    // $actualIds stores the submitted ids sorted for set comparison.
    $actualIds = $submittedIds;
    sort($expectedIds);
    sort($actualIds);
    if ($expectedIds !== $actualIds) {
        return null;
    }

    // $nextIds stores the full order with only the current visible page changed.
    $nextIds = array_values($currentIds);
    foreach ($submittedIds as $index => $submittedId) {
        $nextIds[$visibleOffset + $index] = $submittedId;
    }

    return $nextIds;
}

/**
 * Decodes and validates a JSON id order submitted by JavaScript.
 *
 * @param string $rawOrder JSON encoded id list.
 * @return array<int>|null Positive unique integer ids, or null when malformed.
 */
function admin_decode_reorder_id_list(string $rawOrder): ?array
{
    // $decodedOrder stores the decoded list before integer normalization.
    $decodedOrder = json_decode($rawOrder, true);
    if (!is_array($decodedOrder)) {
        return null;
    }

    // $submittedIds stores the positive ids in their submitted order.
    $submittedIds = array_values(array_filter(array_map('intval', $decodedOrder), static fn (int $id): bool => $id > 0));
    if (!$submittedIds || count($submittedIds) !== count(array_unique($submittedIds))) {
        return null;
    }

    return $submittedIds;
}

/**
 * Handles public gallery page subgallery reordering for logged-in admins.
 *
 * This endpoint is intentionally narrower than the Admin dashboard tree reorder.
 * It never changes parent_id values and never nests galleries. It only reshuffles
 * the direct children of the gallery currently being viewed, and only when the
 * submitted ids match the visible pagination slice rendered into the page.
 *
 * @return mixed Result produced by this operation.
 */
function cms_admin_reorder_public_galleries(): void
{
    require_admin();
    verify_csrf();

    // $parentGalleryId stores the gallery whose direct child order is being changed.
    $parentGalleryId = (int) ($_POST['gallery_id'] ?? 0);
    // $parentGallery stores the parent gallery row used for ownership validation.
    $parentGallery = find_gallery($parentGalleryId);
    if (!$parentGallery) {
        cms_not_found();
        return;
    }

    // $submittedIds stores the visible subgallery ids in their new browser order.
    $submittedIds = admin_decode_reorder_id_list((string) ($_POST['gallery_order'] ?? '[]'));
    if ($submittedIds === null) {
        admin_reorder_public_page_response(false, 'The submitted subgallery order was not valid.');
        return;
    }

    // $visibleOffset stores the first item position rendered on the current pagination page.
    $visibleOffset = (int) ($_POST['visible_offset'] ?? -1);
    // $visibleCount stores the number of items rendered on the current pagination page.
    $visibleCount = (int) ($_POST['visible_count'] ?? 0);
    // $currentRows stores every direct child currently owned by this parent gallery.
    $currentRows = child_galleries($parentGalleryId, false);
    // $currentIds stores the complete direct-child order before the requested change.
    $currentIds = array_map(static fn (array $gallery): int => (int) $gallery['id'], $currentRows);
    // $nextIds stores the full direct-child order with only the current visible page rearranged.
    $nextIds = admin_visible_page_reordered_ids($currentIds, $submittedIds, $visibleOffset, $visibleCount);
    if ($nextIds === null) {
        admin_reorder_public_page_response(false, 'The visible subgallery page changed while you were reordering. Reload the page and try again.');
        return;
    }

    // $pdo stores the active database connection used for the atomic order update.
    $pdo = db();
    // $now stores one timestamp shared by all rows touched by this reorder operation.
    $now = now_sql();
    try {
        $pdo->beginTransaction();
        // $stmt stores the prepared update reused for each direct child gallery.
        $stmt = $pdo->prepare('UPDATE galleries SET sort_order = ?, updated_at = ? WHERE id = ? AND parent_id = ?');
        foreach ($nextIds as $index => $galleryId) {
            // $sortOrder stores a normalized sibling position while preserving every non-visible sibling position.
            $sortOrder = ($index + 1) * 10;
            $stmt->execute([$sortOrder, $now, $galleryId, $parentGalleryId]);
        }
        $pdo->commit();

        admin_log_event('info', 'gallery.public_page_reordered', 'Admin reordered visible public-page subgalleries.', [
            'parent_gallery_id' => $parentGalleryId,
            'visible_offset' => $visibleOffset,
            'visible_count' => $visibleCount,
            'submitted_gallery_ids' => $submittedIds,
        ]);
        admin_reorder_public_page_response(true, 'Visible subgallery order saved.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        admin_log_event('error', 'gallery.public_page_reorder_failed', 'Public-page subgallery reorder failed.', [
            'parent_gallery_id' => $parentGalleryId,
            'error' => $exception->getMessage(),
        ]);
        admin_reorder_public_page_response(false, 'Subgallery order could not be saved: ' . $exception->getMessage());
    }
}

/**
 * Returns a JSON payload for public gallery page ordering requests.
 *
 * @param bool $ok Whether the operation completed successfully.
 * @param string $message Human-readable result for the inline toolbar.
 * @return void
 */
function admin_reorder_public_page_response(bool $ok, string $message): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_THROW_ON_ERROR);
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
            admin_panel_error_response('The selected photo is no longer available in this gallery.');
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
    $notice = 'Gallery title picture saved.';
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
        'message' => 'Image saved.',
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
        // Variable $title stores this steps working value.
        $title = trim((string) $_POST['title']);
        // Variable $slug stores this steps working value.
        $slug = trim((string) $_POST['slug']);
        // Variable $visibility stores this steps working value.
        $visibility = gallery_visibility_storage_value((string) ($_POST['visibility'] ?? 'unpublished'));
        // Variable $pictureGameEnabled stores this steps working value.
        $pictureGameEnabled = $pictureGameReady && !empty($_POST['picture_game_enabled']) ? 1 : 0;
        // Variable $gpsMapEnabled stores this steps working value.
        $gpsMapEnabled = $gpsMapReady && !empty($_POST['gps_map_enabled']) ? 1 : 0;
        // Variable $votingEnabled stores this steps working value.
        $votingEnabled = gallery_voting_schema_ready() && !empty($_POST['voting_enabled']) ? 1 : 0;
        // Variable $showFilenames stores this steps working value.
        $showFilenames = gallery_filename_display_schema_ready() && !empty($_POST['show_filenames']) ? 1 : 0;
        // Variable $nsfwEnabled stores whether this gallery requires the NSFW Guard confirmation.
        $nsfwEnabled = nsfw_guard_schema_ready() && !empty($_POST['nsfw_enabled']) ? 1 : 0;
        if ($pictureGameEnabled) {
            // $votingEnabled stores an intermediate value used by the surrounding gallery workflow.
            $votingEnabled = 1;
        }
        if (!$votingEnabled) {
            // $pictureGameEnabled stores an intermediate value used by the surrounding gallery workflow.
            $pictureGameEnabled = 0;
        }
        // $accessAction stores an intermediate value used by the surrounding gallery workflow.
        $accessAction = $accessReady ? (string) ($_POST['access_action'] ?? 'save') : 'save';
        // Variable $accessType stores this steps working value.
        $accessType = $accessReady && ($_POST['access_type'] ?? '') === 'password' ? 'password' : 'normal';
        // Variable $accessListing stores this steps working value.
        $accessListing = normalize_gallery_visibility((string) ($_POST['visibility'] ?? 'unpublished')) === 'public' ? 'listed' : 'unlisted';
        // Variable $accessPasswordHash stores this steps working value.
        $accessPasswordHash = $accessReady ? ($gallery['access_password_hash'] ?? null) : null;
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
                if (admin_wants_json()) {
                    admin_panel_error_response('Gallery folder move failed: ' . $exception->getMessage());
                    return;
                }
                $_SESSION['admin_gallery_error_' . (int) $gallery['id']] = $exception->getMessage();
                flash_message('admin_notice', 'Gallery folder move failed: ' . $exception->getMessage());
                redirect_to(admin_edit_gallery_tab_url((int) $gallery['id'], $returnTab));
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
        // $brandingAssetPaths stores optional banner, logo, and separator paths before form changes.
        $brandingAssetPaths = gallery_branding_schema_ready() ? gallery_branding_asset_paths($gallery) : [];
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
        if (gallery_branding_schema_ready()) {
            try {
                foreach (array_keys(gallery_branding_asset_types()) as $brandingKind) {
                    // $uploadField stores the file-input name for this gallery branding asset.
                    $uploadField = 'branding_' . $brandingKind . '_upload';
                    // $removeField stores the remove-checkbox name for this gallery branding asset.
                    $removeField = 'remove_branding_' . $brandingKind;
                    // $hasUpload stores whether this asset is being replaced by a new file.
                    $hasUpload = !empty($_FILES[$uploadField]['name'] ?? '');
                    if ($hasUpload) {
                        $brandingAssetPaths[$brandingKind] = store_uploaded_gallery_branding_asset((int) $gallery['id'], $brandingKind, $_FILES[$uploadField]);
                        continue;
                    }
                    if (!empty($_POST[$removeField])) {
                        delete_gallery_branding_asset((int) $gallery['id'], $brandingKind);
                        $brandingAssetPaths[$brandingKind] = null;
                    }
                }
            } catch (RuntimeException $exception) {
                if (admin_wants_json()) {
                    admin_panel_error_response('Gallery branding update failed: ' . $exception->getMessage());
                    return;
                }
                flash_message('admin_notice', 'Gallery branding update failed: ' . $exception->getMessage());
                redirect_to(admin_edit_gallery_tab_url((int) $gallery['id'], $returnTab));
            }
        }
        // Variable $slug stores this steps working value.
        $slug = $slug !== '' ? slugify($slug) : unique_slug(db(), $title, (int) $gallery['id']);
        // $gridUsesCustomSettings stores whether this gallery should stop inheriting the display grid.
        $gridUsesCustomSettings = !empty($_POST['grid_override_enabled']);
        // $gridColumns stores the optional custom column count for public cards/photos in this gallery.
        $gridColumns = $gridUsesCustomSettings ? pagination_dimension_value($_POST['grid_columns'] ?? CMS_PAGINATION_DEFAULT_COLUMNS, CMS_PAGINATION_DEFAULT_COLUMNS, CMS_PAGINATION_MAX_COLUMNS) : null;
        // $gridRows stores the optional custom row count used when pagination slices this gallery.
        $gridRows = $gridUsesCustomSettings ? pagination_dimension_value($_POST['grid_rows'] ?? CMS_PAGINATION_DEFAULT_ROWS, CMS_PAGINATION_DEFAULT_ROWS, CMS_PAGINATION_MAX_ROWS) : null;
        // $gridUseForSubgalleries stores whether descendants may inherit this gallery grid.
        $gridUseForSubgalleries = !empty($_POST['grid_use_for_subgalleries']) ? 1 : 0;
        // $thumbnailBounds stores the optional minimum and maximum responsive thumbnail sizes for this gallery.
        $thumbnailBounds = thumbnail_bounds_schema_ready() ? thumbnail_bound_pair_from_post('gallery_thumbnail') : [null, null];
        // $thumbnailBoundsRecursive stores whether descendants should receive the same saved thumbnail bounds.
        $thumbnailBoundsRecursive = thumbnail_bounds_schema_ready() && !empty($_POST['gallery_thumbnail_bounds_recursive']);
        // $fields stores an intermediate value used by the surrounding gallery workflow.
        $fields = [
            'parent_id = ?' => $parentId,
            'cover_image_id = ?' => $coverImageId,
            'title = ?' => $title,
            'description = ?' => (string) $_POST['description'],
            'slug = ?' => unique_slug_for_value($slug, (int) $gallery['id']),
            'visibility = ?' => gallery_visibility_storage_value($visibility),
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
        if (nsfw_guard_schema_ready()) {
            $fields['nsfw_enabled = ?'] = $nsfwEnabled;
        }
        if (gallery_grid_schema_ready()) {
            $fields['grid_columns = ?'] = $gridColumns;
            $fields['grid_rows = ?'] = $gridRows;
            $fields['grid_use_for_subgalleries = ?'] = $gridUseForSubgalleries;
        }
        if (thumbnail_bounds_schema_ready()) {
            $fields['thumbnail_min_size = ?'] = $thumbnailBounds[0];
            $fields['thumbnail_max_size = ?'] = $thumbnailBounds[1];
        }
        if ($accessReady) {
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
        if (gallery_cover_asset_schema_ready()) {
            $fields['cover_image_path = ?'] = $coverImagePath;
        }
        if (gallery_branding_schema_ready()) {
            foreach (gallery_branding_asset_types() as $brandingKind => $definition) {
                // $column stores an intermediate value used by the surrounding gallery workflow.
                $column = (string) $definition['column'];
                $fields[$column . ' = ?'] = $brandingAssetPaths[$brandingKind] ?? null;
            }
        }
        if (gallery_background_source_schema_ready()) {
            $fields['background_source = ?'] = $backgroundSource;
        }
        $fields['updated_at = ?'] = now_sql();
        // $stmt stores an intermediate value used by the surrounding gallery workflow.
        $stmt = db()->prepare('UPDATE galleries SET ' . implode(', ', array_keys($fields)) . ' WHERE id = ?');
        $stmt->execute(array_merge(array_values($fields), [(int) $gallery['id']]));
        if (thumbnail_bounds_schema_ready() && $thumbnailBoundsRecursive) {
            save_gallery_thumbnail_bounds($gallery, $thumbnailBounds[0], $thumbnailBounds[1], true);
        }
        if ($accessReady) {
            if ($accessAction === 'revoke_link') {
                revoke_gallery_share_token((int) $gallery['id']);
            }
        }
        if ($accessReady && $accessMode === 'password') {
            if ($accessAction === 'generate_link') {
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
        $gallery = find_gallery((int) $gallery['id'], true);
        if ($gallery) {
            write_gallery_sidecar($gallery);
        }
        // $notice stores an intermediate value used by the surrounding gallery workflow.
        $notice = 'Gallery saved.';
        if (!empty($moveResult['moved'])) {
            // $notice stores an intermediate value used by the surrounding gallery workflow.
            $notice = 'Gallery saved and folder moved.';
        }
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
        // $thumbnailFailed stores required derivatives that failed during upload thumbnail generation.
        $thumbnailFailed = (int) ($_GET['thumbnail_failed'] ?? 0);
        // $scanFailed stores files that were stored on disk but not imported into image rows.
        $scanFailed = (int) ($_GET['scan_failed'] ?? 0);
        // $uploadNotice stores the upload result message shown after redirect.
        $uploadNotice = 'Uploaded ' . (int) $_GET['uploaded'] . ' images, scanned or updated ' . (int) ($_GET['scanned'] ?? 0) . ' image records, and created ' . (int) ($_GET['thumbnails'] ?? 0) . ' thumbnails.';
        if ($scanFailed > 0) {
            $uploadNotice .= ' Warning: ' . $scanFailed . ' uploaded file(s) were stored on disk but could not be imported into image records. Check the admin logs for filenames and decoder diagnostics.';
        }
        if ($thumbnailFailed > 0) {
            $uploadNotice .= ' Warning: ' . $thumbnailFailed . ' thumbnail or DNG display derivative(s) failed. Use Create gallery thumbnails or check the admin logs for details.';
        }
        echo '<div class="notice">' . e($uploadNotice) . '</div>';
    } elseif (isset($_GET['moved'])) {
        echo '<div class="notice">Gallery folder moved on disk and database paths were updated.</div>';
    } elseif (isset($_GET['saved'])) {
        echo '<div class="notice">Gallery saved.</div>';
    }
    if (!$pictureGameReady) {
        render_admin_migration_notice('Picture game settings are hidden until the latest database migration is applied.');
    }
    // $imageCount stores the number of images currently attached to this gallery.
    $imageCount = count($images);
    // $activeVisibility stores the normalized gallery visibility label for summary cards.
    $activeVisibility = normalize_gallery_visibility((string) ($gallery['visibility'] ?? 'unpublished'));
    // $activeEditTab stores the tab selected by redirect query state before JavaScript reads the URL hash.
    $activeEditTab = admin_edit_gallery_tab_id((string) ($_GET['tab'] ?? '')) ?: 'admin-edit-identity';
    // $adminTabs stores the edit-gallery sections shown by the shared admin tab controller.
    $adminTabs = [
        ['id' => 'admin-edit-identity', 'label' => 'Identity'],
        ['id' => 'admin-edit-access', 'label' => 'Access'],
        ['id' => 'admin-edit-display', 'label' => 'Display'],
        ['id' => 'admin-edit-media', 'label' => 'Media'],
        ['id' => 'admin-edit-images', 'label' => 'Images', 'badge' => $imageCount],
    ];

    echo '<section class="admin-dashboard-hero admin-edit-gallery-hero">';
    echo '<div><p class="admin-kicker">Gallery editor</p><h1>' . e((string) $gallery['title']) . '</h1><p class="muted">Edit identity, access, presentation, media assets, and photo ordering from one focused workspace.</p></div>';
    echo '<nav class="admin-hero-actions" aria-label="Gallery actions"><a class="button" href="' . e(url_for('admin_upload', ['gallery_id' => $gallery['id']])) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="upload" data-admin-side-panel-kicker="Upload workflow" data-admin-side-panel-title="Upload photos" data-gallery-side-panel-url="' . e(url_for('admin_upload', ['gallery_id' => $gallery['id'], 'panel' => 1])) . '">Upload photos here</a><a class="button secondary" href="' . e(url_for('admin_new_gallery', ['parent_id' => $gallery['id']])) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="create" data-admin-side-panel-kicker="Gallery workflow" data-admin-side-panel-title="Create gallery" data-gallery-side-panel-url="' . e(url_for('admin_new_gallery', ['parent_id' => $gallery['id'], 'panel' => 1])) . '">Create gallery here</a><a class="button secondary" href="' . e(gallery_public_url($gallery)) . '" target="_blank" rel="noopener noreferrer">View gallery</a><a class="button secondary" href="' . e(url_for('admin')) . '">Back to galleries</a></nav>';
    echo '</section>';

    echo '<div class="admin-metric-grid admin-edit-gallery-summary">';
    echo '<div class="admin-metric-card"><span>Visibility</span><strong>' . e(ucfirst($activeVisibility)) . '</strong><small>Listing and direct URL behavior</small></div>';
    echo '<div class="admin-metric-card"><span>Images</span><strong>' . (int) $imageCount . '</strong><small>Photos in this gallery</small></div>';
    echo '<div class="admin-metric-card"><span>Folder</span><strong>' . e(gallery_folder_name_from_path((string) $gallery['folder_path'])) . '</strong><small>Filesystem folder name</small></div>';
    echo '<div class="admin-metric-card"><span>Parent</span><strong>' . ((int) ($gallery['parent_id'] ?? 0) > 0 ? '#' . (int) $gallery['parent_id'] : 'Root') . '</strong><small>Gallery tree position</small></div>';
    echo '</div>';

    render_admin_tabs($adminTabs, $activeEditTab);

    echo '<form method="post" enctype="multipart/form-data" class="admin-edit-gallery-form" autocomplete="off">' . csrf_field();
    echo '<input type="hidden" name="id" value="' . (int) $gallery['id'] . '">';
    echo '<input type="hidden" name="return_tab" value="admin-edit-identity">';

    ob_start();
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">Identity</p><h2>Names and placement</h2></div><p class="muted">Controls the public title, URL slug, disk folder, and gallery tree position.</p></div>';
    echo '<div class="admin-edit-card-grid">';
    echo '<div class="admin-edit-card is-wide"><label>Title<input name="title" value="' . e($gallery['title']) . '" autocomplete="off" required></label><label>Description<textarea name="description">' . e($gallery['description']) . '</textarea></label></div>';
    echo '<div class="admin-edit-card"><label>Slug<input name="slug" value="' . e($gallery['slug']) . '" autocomplete="off" required><span class="muted">Used in the public gallery URL.</span></label><label>Folder name<input name="folder_name" value="' . e(gallery_folder_name_from_path((string) $gallery['folder_path'])) . '" autocomplete="off" required><span class="muted">Changing this renames the folder on disk.</span></label></div>';
    echo '<div class="admin-edit-card"><label>Parent gallery<select name="parent_id"><option value="0">No parent</option>' . gallery_parent_options($gallery) . '</select></label><label>Sort order<input name="sort_order" type="number" value="' . (int) $gallery['sort_order'] . '"></label></div>';
    echo '<div class="admin-edit-card is-wide"><label>Tags<input name="tags" value="' . e(tag_names_for_entity('gallery', (int) $gallery['id'])) . '" list="tag-suggestions" data-tag-input><span class="muted">Separate tags with commas.</span></label></div>';
    echo '</div>';
    render_tag_datalist();
    render_admin_tab_panel('admin-edit-identity', (string) ob_get_clean(), $activeEditTab === 'admin-edit-identity');

    ob_start();
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">Access</p><h2>Visibility and protection</h2></div><p class="muted">Visibility decides discoverability. Passwords and generated links are optional on top of it.</p></div>';
    echo '<div class="admin-edit-card-grid">';
    echo '<div class="admin-edit-card"><label>Visibility<select name="visibility">' . visibility_options((string) $gallery['visibility']) . '</select></label><p class="muted">Public galleries are listed. Unpublished galleries are hidden but open from their normal URL. Private galleries are admin-only except for supported direct-token access.</p></div>';
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
        echo '<div class="admin-edit-card"><label>Password lock<select name="access_type"><option value="normal"' . ($currentAccessType === 'normal' ? ' selected' : '') . '>No password</option><option value="password"' . ($currentAccessType === 'password' ? ' selected' : '') . '>Require password</option></select><span class="muted">Password locking is independent of public, unpublished, or private visibility.</span></label><label>New gallery password<input name="access_password" type="password" autocomplete="new-password"><span class="muted">Leave empty to keep the current gallery password.</span></label>';
        if (!empty($gallery['access_password_hash'])) {
            echo '<label class="checkbox-label"><input type="checkbox" name="clear_access_password" value="1"> Clear current gallery password</label>';
        }
        echo '</div>';
        echo '<div class="admin-edit-card is-wide"><label>Share link expiry<input name="access_token_expires_at" type="datetime-local" value="' . e(!empty($gallery['access_token_expires_at']) ? date('Y-m-d\TH:i', strtotime((string) $gallery['access_token_expires_at'])) : '') . '"><span class="muted">Leave empty for a non-expiring generated link.</span></label>';
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
        echo '<div class="bulk-row"><button type="submit" class="secondary" name="access_action" value="generate_link">Generate/regenerate share link</button><button type="submit" class="secondary" name="access_action" value="revoke_link">Revoke share link</button></div><p class="muted">Generated direct links use the existing hash-token path. They remain useful for private galleries without making them appear in listings.</p></div>';
    } else {
        echo '<div class="notice">Protected gallery settings are hidden until the v0.13 database migration is applied.</div>';
    }
    if (nsfw_guard_schema_ready()) {
        echo '<div class="admin-edit-card is-wide"><label class="checkbox-label"><input type="checkbox" name="nsfw_enabled" value="1"' . ((int) ($gallery['nsfw_enabled'] ?? 0) === 1 ? ' checked' : '') . '> Mark this gallery as NSFW / 18+</label><p class="muted">When enabled, this gallery and all subgalleries require an 18+ confirmation before anonymous visitors can view photos or media files. Before publishing NSFW content, make sure your hosting provider or web hosting terms allow it.</p></div>';
    } else {
        echo '<div class="admin-edit-card is-wide"><p class="muted">NSFW Guard controls will be available after the database migration is applied.</p></div>';
    }
    echo '</div>';
    render_admin_tab_panel('admin-edit-access', (string) ob_get_clean(), $activeEditTab === 'admin-edit-access');

    ob_start();
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">Display</p><h2>Gallery behavior</h2></div><p class="muted">Feature toggles and grid overrides affecting this gallery branch.</p></div>';
    echo '<div class="admin-edit-card-grid">';
    if ($pictureGameReady) {
        echo '<div class="admin-edit-card"><label class="checkbox-label"><input type="checkbox" name="picture_game_enabled" value="1"' . ((int) ($gallery['picture_game_enabled'] ?? 0) === 1 ? ' checked' : '') . '> Enable picture game for this gallery branch</label></div>';
    }
    if (gallery_voting_schema_ready()) {
        echo '<div class="admin-edit-card"><label class="checkbox-label"><input type="checkbox" name="voting_enabled" value="1"' . ((int) ($gallery['voting_enabled'] ?? 0) === 1 ? ' checked' : '') . '> Enable image voting for this gallery</label><p class="muted">When disabled, existing votes remain stored and visible, but vote arrows and vote submissions are blocked.</p></div>';
    }
    if (gallery_filename_display_schema_ready()) {
        echo '<div class="admin-edit-card"><label class="checkbox-label"><input type="checkbox" name="show_filenames" value="1"' . ((int) ($gallery['show_filenames'] ?? 0) === 1 ? ' checked' : '') . '> Show file names</label><p class="muted">Disabled by default. Custom photo titles and descriptions are still shown; raw uploaded file names stay hidden unless this is enabled.</p></div>';
    } else {
        echo '<div class="admin-edit-card"><p class="muted">File name display control will be available after the database migration is applied.</p></div>';
    }
    if ($gpsMapReady) {
        echo '<div class="admin-edit-card"><label class="checkbox-label"><input type="checkbox" name="gps_map_enabled" value="1"' . ((int) ($gallery['gps_map_enabled'] ?? 0) === 1 ? ' checked' : '') . '> Enable EXIF GPS maps for this gallery branch</label><p class="muted">When enabled here, this gallery and its subgalleries may show photo map pins and gallery maps for images with GPS EXIF coordinates.</p></div>';
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
        echo '<div class="admin-edit-card is-wide"><h3>Display grid</h3><label class="checkbox-label"><input type="checkbox" name="grid_override_enabled" value="1" data-gallery-grid-override-enabled' . ($galleryUsesCustomGrid ? ' checked' : '') . '> Use a custom grid for this gallery</label><div class="admin-edit-range-grid"><label>Columns <span class="muted" data-gallery-grid-columns-display>' . (int) $gridColumns . '</span><input type="range" name="grid_columns" min="1" max="' . CMS_PAGINATION_MAX_COLUMNS . '" value="' . (int) $gridColumns . '" data-gallery-grid-columns></label><label>Rows <span class="muted" data-gallery-grid-rows-display>' . (int) $gridRows . '</span><input type="range" name="grid_rows" min="1" max="' . CMS_PAGINATION_MAX_ROWS . '" value="' . (int) $gridRows . '" data-gallery-grid-rows></label></div><label class="checkbox-label"><input type="checkbox" name="grid_use_for_subgalleries" value="1"' . ((int) ($gallery['grid_use_for_subgalleries'] ?? 1) === 1 ? ' checked' : '') . '> Use for subgalleries</label><p class="muted">Current source: ' . e((string) ($effectiveGridSettings['grid_source'] ?? 'global')) . '. If this gallery does not use a custom grid, it inherits the nearest parent grid that allows subgallery inheritance, otherwise it uses the Theme fallback.</p></div>';
    } else {
        echo '<div class="admin-edit-card is-wide"><p class="muted">Gallery display-grid overrides will be available after the database migration is applied.</p></div>';
    }
    if (thumbnail_bounds_schema_ready()) {
        echo '<div class="admin-edit-card is-wide">';
        render_admin_thumbnail_bound_slider('gallery_thumbnail', isset($gallery['thumbnail_min_size']) ? (int) $gallery['thumbnail_min_size'] : null, isset($gallery['thumbnail_max_size']) ? (int) $gallery['thumbnail_max_size'] : null, 'Responsive thumbnail quality bounds', 'Optional guardrails for automatic thumbnail selection. Leave both sides on Auto to keep the current behavior.');
        echo '<label class="checkbox-label"><input type="checkbox" name="gallery_thumbnail_bounds_recursive" value="1"> Save these bounds recursively to subgalleries</label>';
        echo '<p class="muted">Recursive save is intentionally off by default. It copies the selected bounds to every descendant gallery, but does not change individual photo overrides.</p>';
        echo '</div>';
    } else {
        echo '<div class="admin-edit-card is-wide"><p class="muted">Thumbnail quality bounds will be available after the database migration is applied.</p></div>';
    }
    echo '</div>';
    render_admin_tab_panel('admin-edit-display', (string) ob_get_clean(), $activeEditTab === 'admin-edit-display');

    ob_start();
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">Media</p><h2>Thumbnail, branding, and background</h2></div><p class="muted">Optional visual assets override theme fallbacks only for this gallery.</p></div>';
    echo '<div class="admin-edit-card-grid">';
    echo '<div class="admin-edit-card is-wide"><label>Title picture<select name="cover_image_id"><option value="0">Automatic</option>' . gallery_cover_options((int) $gallery['id'], (int) ($gallery['cover_image_id'] ?? 0), true) . '</select><span class="muted">Includes images from subgalleries.</span></label>';
    if (gallery_cover_asset_schema_ready()) {
        echo '<label>Upload gallery thumbnail<input type="file" name="cover_upload" accept="image/*"><span class="muted">This is stored separately from gallery images.</span></label>';
    } else {
        echo '<p class="muted">Uploadable gallery thumbnails will be available after the gallery thumbnail migration is applied.</p>';
    }
    echo '</div>';
    echo '<div class="admin-edit-card is-wide">';
    render_admin_gallery_branding_fields($gallery);
    echo '</div>';
    echo '<div class="admin-edit-card is-wide">';
    if (gallery_background_source_schema_ready()) {
        // $backgroundSource stores an intermediate value used by the surrounding gallery workflow.
        $backgroundSource = gallery_background_source($gallery);
        echo '<label>Background source<select name="background_source"><option value=""' . ($backgroundSource === null ? ' selected' : '') . '>Use theme background</option><option value="upload"' . ($backgroundSource === 'upload' ? ' selected' : '') . '>Upload new image</option><option value="existing"' . ($backgroundSource === 'existing' ? ' selected' : '') . '>Pick from existing gallery images</option><option value="collage"' . ($backgroundSource === 'collage' ? ' selected' : '') . '>Generate collage from public galleries</option></select><span class="muted">If unset, the gallery inherits the Theme background.</span></label>';
    } else {
        echo '<p class="muted">Background source selection will be available after the background migration is applied.</p>';
    }
    echo '</div></div>';
    render_admin_tab_panel('admin-edit-media', (string) ob_get_clean(), $activeEditTab === 'admin-edit-media');

    echo '<div class="admin-edit-gallery-savebar"><button type="submit">Save gallery</button><span class="muted">Saves all settings from Identity, Access, Display, and Media.</span></div>';
    echo '</form>';

    ob_start();
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">Images</p><h2>Photos and ordering</h2></div><div class="admin-hero-actions"><a class="button" href="' . e(url_for('admin_upload', ['gallery_id' => $gallery['id']])) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="upload" data-admin-side-panel-kicker="Upload workflow" data-admin-side-panel-title="Upload photos" data-gallery-side-panel-url="' . e(url_for('admin_upload', ['gallery_id' => $gallery['id'], 'panel' => 1])) . '">Upload photos here</a><form method="post" action="' . e(url_for('admin_scan_images')) . '">' . csrf_field() . '<input type="hidden" name="gallery_id" value="' . (int) $gallery['id'] . '"><button type="submit" class="secondary">Scan/import images</button></form></div></div>';
    echo '<form method="post" action="' . e(url_for('admin_bulk_images')) . '" data-admin-image-bulk-form>' . csrf_field();
    echo '<input type="hidden" name="gallery_id" value="' . (int) $gallery['id'] . '">';
    echo '<input type="hidden" name="return_tab" value="admin-edit-images">';
    render_admin_image_bulk_toolbar($gallery);
    echo '<div class="admin-image-order-toolbar" data-admin-image-order-toolbar data-reorder-url="' . e(url_for('admin_reorder_images')) . '"><p class="muted">Drag photos by the handle to change their gallery order, or click the Name column header to sort the gallery by filename. Each change is saved immediately.</p><span class="admin-image-order-status" data-admin-image-order-status aria-live="polite">Order unchanged.</span></div>';
    echo '<table class="admin-image-order-table" data-admin-image-order-table><thead><tr><th>Move</th><th>Select</th><th>Preview</th><th aria-sort="none"><button type="button" class="admin-image-name-sort" data-admin-image-name-sort data-sort-direction="asc" aria-label="Sort photos by name from A to Z">Name <span aria-hidden="true">↕</span></button></th><th title="File names shown">N</th><th>Status</th><th>Cover</th><th>Actions</th></tr></thead><tbody>';
    foreach ($images as $image) {
        // Variable $isCover stores this steps working value.
        $isCover = (int) ($gallery['cover_image_id'] ?? 0) === (int) $image['id'];
        echo '<tr data-admin-image-order-row data-image-id="' . (int) $image['id'] . '" data-image-name="' . e((string) $image['relative_path']) . '"><td class="admin-image-order-cell"><span class="admin-image-drag-handle" data-admin-image-drag-handle role="button" tabindex="0" aria-label="Move ' . e((string) $image['relative_path']) . '" title="Drag to reorder">↕</span></td><td><input type="checkbox" name="image_ids[]" value="' . (int) $image['id'] . '"></td>';
        echo '<td><img class="admin-thumb" decoding="async" loading="lazy" src="' . e(thumbnail_url($image, 300)) . '" alt=""></td>';
        echo '<td data-admin-image-name-cell>' . e($image['relative_path']) . '</td><td>' . render_admin_feature_flag(gallery_shows_filenames($gallery), '✓', 'File names are shown for this gallery') . '</td><td>' . e($image['visibility']) . '</td><td data-admin-image-cover-cell>' . ($isCover ? 'Title picture' : '') . '</td><td><a href="' . e(url_for('admin_edit_image', ['id' => $image['id']])) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="image-edit" data-admin-side-panel-kicker="Photo editor" data-admin-side-panel-title="Edit photo" data-gallery-side-panel-url="' . e(url_for('admin_edit_image', ['id' => $image['id'], 'panel' => 1])) . '">Edit</a> <button type="submit" class="secondary danger inline-admin-action" name="action" value="delete:' . (int) $image['id'] . '" data-admin-image-delete-single data-image-id="' . (int) $image['id'] . '" data-image-name="' . e((string) $image['relative_path']) . '">Delete</button></td></tr>';
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
    echo '<label class="admin-image-select-all"><input type="checkbox" data-select-all="image_ids[]"> Select all images</label>';
    echo '<span class="admin-image-selection-count" data-admin-image-selected-count>0 selected</span>';
    echo '<label>Bulk action<select name="action" data-admin-image-bulk-action><option value="public">Set public</option><option value="draft">Set draft</option><option value="private">Set private</option><option value="cover">Set as title picture</option><option value="thumbs">Create thumbnails</option><option value="nsfw_on">Mark as NSFW / 18+</option><option value="nsfw_off">Remove NSFW mark</option><option value="delete">Delete selected photos</option><option value="move_existing" hidden>Move to existing gallery</option><option value="move_new" hidden>Move to new gallery</option></select></label>';
    echo '<button type="submit">Apply to selected</button>';
    echo '<button type="button" class="secondary" data-admin-image-move-open>Move selected photos</button>';
    echo '<button type="submit" class="secondary" name="thumbnail_gallery_id" value="' . $galleryId . '" formaction="' . e(url_for('admin_create_thumbnails')) . '">Create gallery thumbnails</button>';
    echo '</div>';

    echo '<section class="admin-image-move-panel" data-admin-image-move-panel hidden aria-label="Move selected photos">';
    echo '<div class="admin-image-move-panel-head"><div class="admin-image-move-title"><span class="admin-image-move-title-icon" aria-hidden="true">⇄</span><div><h3>Move selected photos</h3><span class="admin-image-move-count-pill" data-admin-image-selected-count>0 selected</span></div></div><button type="button" class="admin-image-move-close" data-admin-image-move-cancel aria-label="Close move photos panel">×</button></div>';
    echo '<div class="admin-image-move-steps" aria-label="Move progress">';
    echo '<div class="admin-image-move-step is-active" data-admin-image-move-step="action"><span>1</span><div><strong>Choose action</strong><p>Pick what you want to do</p></div></div>';
    echo '<div class="admin-image-move-step" data-admin-image-move-step="target"><span>2</span><div><strong>Target</strong><p>Choose or create gallery</p></div></div>';
    echo '<div class="admin-image-move-step" data-admin-image-move-step="confirm"><span>3</span><div><strong>Confirm</strong><p>Review and confirm</p></div></div>';
    echo '<div class="admin-image-move-step" data-admin-image-move-step="complete"><span>4</span><div><strong>Complete</strong><p>Move photos</p></div></div>';
    echo '</div>';
    echo '<p class="admin-image-move-lead">Choose where you want to move the selected photos.</p>';
    echo '<div class="admin-image-move-choice-grid" role="group" aria-label="Move action">';
    echo '<button type="button" class="admin-image-move-choice" data-admin-image-move-choice="move_existing" aria-pressed="false"><span class="admin-image-move-choice-icon" aria-hidden="true">▭</span><span class="admin-image-move-choice-copy"><strong>Move to existing gallery</strong><small>Pick a gallery that already exists. Its title picture is kept unless it is missing or invalid.</small></span><span class="admin-image-move-choice-radio" aria-hidden="true"></span></button>';
    echo '<button type="button" class="admin-image-move-choice" data-admin-image-move-choice="move_new" aria-pressed="false"><span class="admin-image-move-choice-icon" aria-hidden="true">▭+</span><span class="admin-image-move-choice-copy"><strong>Move to new gallery</strong><small>Create a new gallery under the selected parent, then move only the selected photos into it.</small></span><span class="admin-image-move-choice-radio" aria-hidden="true"></span></button>';
    echo '</div>';
    echo '<div class="admin-image-move-targets">';
    echo '<label class="admin-image-move-target" data-admin-image-move-existing hidden><span>Destination gallery</span><select name="destination_gallery_id"><option value="0">Choose existing gallery</option>' . $destinationOptions . '</select><small><span aria-hidden="true">ⓘ</span> The selected photos, thumbnails, and generated display files will be moved into this gallery folder.</small></label>';
    echo '<div class="admin-image-move-target admin-image-move-new" data-admin-image-move-new hidden><label><span>Parent gallery</span><select name="new_gallery_parent_id"><option value="0">No parent</option>' . $newGalleryParentOptions . '</select></label><label><span>New gallery title</span><input type="text" name="new_gallery_title" placeholder="Example: Prague evening walk"></label><label><span>Optional folder/slug</span><input type="text" name="new_gallery_folder_name" placeholder="Leave empty to derive it from the title"></label><small><span aria-hidden="true">ⓘ</span> The new gallery is created under the selected parent and receives only the selected photos.</small></div>';
    echo '</div>';
    echo '<div class="admin-image-move-confirm"><button type="button" class="secondary admin-image-move-cancel-bottom" data-admin-image-move-cancel>Cancel</button><div><strong>Move summary</strong><p data-admin-image-move-summary>Select photos and choose a target to continue.</p></div><button type="submit" name="move_images" value="1" data-admin-image-move-submit disabled>Move selected photos now →</button></div>';
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

    echo '<fieldset class="form-grid admin-branding-assets"><legend>Gallery branding</legend>';
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

    // $submittedIds stores the ordered image ids exactly as submitted by the browser.
    $submittedIds = admin_decode_reorder_id_list((string) ($_POST['image_order'] ?? '[]'));
    if ($submittedIds === null) {
        admin_reorder_images_response(false, 'The submitted image order was not valid JSON or contained duplicate images.', $galleryId);
        return;
    }

    // $currentRows stores every direct image currently owned by this gallery.
    $currentRows = gallery_images($galleryId, false);
    // $currentOrderedIds stores the complete direct-image order before the requested change.
    $currentOrderedIds = array_map(static fn (array $image): int => (int) $image['id'], $currentRows);
    // $reorderScope stores whether this request is the full Admin table or the public visible-page path.
    $reorderScope = (string) ($_POST['reorder_scope'] ?? 'full');

    if ($reorderScope === 'visible_page') {
        // $visibleOffset stores the first image position rendered on the current pagination page.
        $visibleOffset = (int) ($_POST['visible_offset'] ?? -1);
        // $visibleCount stores the number of images rendered on the current pagination page.
        $visibleCount = (int) ($_POST['visible_count'] ?? 0);
        // $nextIds stores the full gallery image order with only the current visible page rearranged.
        $nextIds = admin_visible_page_reordered_ids($currentOrderedIds, $submittedIds, $visibleOffset, $visibleCount);
        if ($nextIds === null) {
            admin_reorder_images_response(false, 'The visible photo page changed while you were reordering. Reload the page and try again.', $galleryId);
            return;
        }
        try {
            admin_save_image_order($galleryId, $nextIds, 'image.public_page_reordered', 'Admin reordered visible public-page photos.', [
                'visible_offset' => $visibleOffset,
                'visible_count' => $visibleCount,
                'submitted_image_ids' => $submittedIds,
            ]);
            admin_reorder_images_response(true, 'Visible photo order saved.', $galleryId);
        } catch (Throwable $exception) {
            admin_reorder_images_response(false, 'Image order could not be saved: ' . $exception->getMessage(), $galleryId);
        }
        return;
    }

    // $currentIds stores the complete direct-image set currently visible in the edit-gallery table.
    $currentIds = $currentOrderedIds;
    sort($currentIds);
    // Variable $sortedSubmittedIds stores the submitted id set for exact set comparison with the database state.
    $sortedSubmittedIds = $submittedIds;
    sort($sortedSubmittedIds);
    if ($sortedSubmittedIds !== $currentIds) {
        admin_reorder_images_response(false, 'The image list changed while you were reordering. Reload the page and try again.', $galleryId);
        return;
    }

    try {
        admin_save_image_order($galleryId, $submittedIds, 'image.reordered', 'Admin reordered gallery images.', [
            'images' => count($submittedIds),
        ]);
        admin_reorder_images_response(true, 'Image order saved.', $galleryId);
    } catch (Throwable $exception) {
        admin_reorder_images_response(false, 'Image order could not be saved: ' . $exception->getMessage(), $galleryId);
    }
}

/**
 * Persists a complete image order for one gallery.
 *
 * @param int $galleryId Gallery whose direct image order is being saved.
 * @param array<int> $orderedIds Complete ordered image ids for this gallery.
 * @param string $eventKey Admin log event key.
 * @param string $eventMessage Admin log event message.
 * @param array<string,mixed> $context Additional event context.
 * @return void
 */
function admin_save_image_order(int $galleryId, array $orderedIds, string $eventKey, string $eventMessage, array $context = []): void
{
    // Variable $pdo stores the active database connection used for the atomic sort_order update.
    $pdo = db();
    // Variable $now stores one timestamp shared by all rows touched by this reorder operation.
    $now = now_sql();
    try {
        $pdo->beginTransaction();
        // Variable $stmt stores the prepared update reused for each reordered image row.
        $stmt = $pdo->prepare('UPDATE images SET sort_order = ?, updated_at = ? WHERE id = ? AND gallery_id = ?');
        foreach ($orderedIds as $index => $imageId) {
            // Variable $sortOrder stores a spaced integer so future maintenance can insert between rows if needed.
            $sortOrder = ($index + 1) * 10;
            $stmt->execute([$sortOrder, $now, $imageId, $galleryId]);
        }
        $pdo->commit();
        admin_log_event('info', $eventKey, $eventMessage, array_merge([
            'gallery_id' => $galleryId,
            'images' => count($orderedIds),
        ], $context));
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        admin_log_event('error', 'image.reorder_failed', 'Admin image reorder failed.', [
            'gallery_id' => $galleryId,
            'error' => $exception->getMessage(),
            'event_key' => $eventKey,
        ]);
        throw $exception;
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
    redirect_to(admin_edit_gallery_tab_url($galleryId, 'admin-edit-images'));
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
            admin_panel_error_response('The selected photo is no longer available in this gallery.');
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
                $newGallery = create_empty_gallery([
                    'title' => $newGalleryTitle,
                    'folder_name' => trim((string) ($_POST['new_gallery_folder_name'] ?? '')),
                    'description' => '',
                    'visibility' => gallery_visibility_storage_value((string) ($newGalleryTemplateGallery['visibility'] ?? 'unpublished')),
                    'parent_id' => $newGalleryParentId,
                    'sort_order' => $newGallerySortOrder,
                    'voting_enabled' => (int) ($newGalleryTemplateGallery['voting_enabled'] ?? 0) === 1,
                    'show_filenames' => gallery_shows_filenames($newGalleryTemplateGallery),
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
                flash_message('admin_notice', 'Image move failed: ' . implode(' ', array_slice($moved['failures'], 0, 5)));
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
                $response = admin_bulk_images_success_response($updated, $notice, $returnTab, $action, $ownedIds);
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
            flash_message('admin_notice', 'Image move failed: ' . $exception->getMessage());
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
            ], ['category' => 'other', 'severity' => 'warning']);
            $notice = 'Deleted ' . (int) $deleted['deleted'] . ' image(s), removed ' . (int) $deleted['files_deleted'] . ' original file(s), and cleaned ' . (int) $deleted['derivatives_deleted'] . ' derivative file(s).';
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
            flash_message('admin_notice', 'Image delete failed: ' . $exception->getMessage());
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
    }
    if (in_array($action, ['nsfw_on', 'nsfw_off'], true) && nsfw_guard_schema_ready()) {
        // $placeholders stores this steps working value.
        $placeholders = implode(',', array_fill(0, count($ownedIds), '?'));
        // $stmt stores this steps working value.
        $stmt = db()->prepare('UPDATE images SET nsfw_enabled = ?, updated_at = ? WHERE id IN (' . $placeholders . ')');
        $stmt->execute(array_merge([$action === 'nsfw_on' ? 1 : 0, now_sql()], $ownedIds));
        flash_message('admin_notice', 'Updated NSFW Guard on ' . count($ownedIds) . ' image(s).');
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
        flash_message('admin_notice', 'Created ' . $count . ' thumbnail(s).');
        redirect_to(admin_edit_gallery_tab_url($galleryId, $returnTab));
    }
    flash_message('admin_notice', 'Updated ' . count($ownedIds) . ' image(s).');
    redirect_to(admin_edit_gallery_tab_url($galleryId, $returnTab));
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
    $visibility = gallery_visibility_storage_value((string) ($gallery['visibility'] ?? 'unpublished'));
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
    if ($action === 'unpublish') {
        // $visibility stores an intermediate value used by the surrounding gallery workflow.
        $visibility = 'unpublished';
    }
    if (in_array($action, gallery_visibility_values(), true)) {
        // $visibility stores an intermediate value used by the surrounding gallery workflow.
        $visibility = gallery_visibility_storage_value($action);
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
    if (nsfw_guard_schema_ready()) {
        $fields['nsfw_enabled = ?'] = !empty($_POST['nsfw_enabled']) ? 1 : 0;
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
    // Variable $fields stores this steps working value.
    $fields = [
        'title = ?' => trim((string) ($_POST['title'] ?? '')),
        'description = ?' => (string) ($_POST['description'] ?? ''),
        'visibility = ?' => $visibility,
    ];
    if (nsfw_guard_schema_ready()) {
        $fields['nsfw_enabled = ?'] = !empty($_POST['nsfw_enabled']) ? 1 : 0;
    }
    $fields['updated_at = ?'] = now_sql();
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('UPDATE images SET ' . implode(', ', array_keys($fields)) . ' WHERE id = ?');
    $stmt->execute(array_merge(array_values($fields), [(int) $image['id']]));
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
        // Variable $fields stores this steps working value.
        $fields = [
            'title = ?' => (string) $_POST['title'],
            'description = ?' => (string) $_POST['description'],
            'visibility = ?' => $visibility,
            'sort_order = ?' => (int) $_POST['sort_order'],
        ];
        if (nsfw_guard_schema_ready()) {
            $fields['nsfw_enabled = ?'] = !empty($_POST['nsfw_enabled']) ? 1 : 0;
        }
        if (thumbnail_bounds_schema_ready()) {
            // $thumbnailBounds stores the optional minimum and maximum responsive thumbnail sizes for this image.
            $thumbnailBounds = thumbnail_bound_pair_from_post('image_thumbnail');
            $fields['thumbnail_min_size = ?'] = $thumbnailBounds[0];
            $fields['thumbnail_max_size = ?'] = $thumbnailBounds[1];
        }
        $fields['updated_at = ?'] = now_sql();
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('UPDATE images SET ' . implode(', ', array_keys($fields)) . ' WHERE id = ?');
        $stmt->execute(array_merge(array_values($fields), [(int) $image['id']]));
        sync_entity_tags('image', (int) $image['id'], (string) ($_POST['tags'] ?? ''));
        if (public_path_schema_ready()) {
            regenerate_public_paths();
        }
        // $image stores the freshly saved image metadata returned to side-panel saves.
        $image = find_image((int) $image['id']) ?: $image;
        if (admin_wants_json()) {
            header('Content-Type: application/json');
            echo json_encode(admin_edit_image_success_response($image));
            return;
        }
        redirect_to(url_for('admin_edit_image', ['id' => $image['id'], 'saved' => 1]));
    }
    render_header('Edit image');
    echo '<section class="panel"><h1>Edit image</h1><p><img decoding="async" loading="lazy" src="' . e(thumbnail_url($image, 800)) . '" alt=""></p>';
    echo '<form method="post" class="form-grid">' . csrf_field();
    echo '<input type="hidden" name="id" value="' . (int) $image['id'] . '">';
    echo '<label>Title<input name="title" value="' . e($image['title']) . '"></label>';
    echo '<label>Description<textarea name="description">' . e($image['description']) . '</textarea></label>';
    echo '<label>Visibility<select name="visibility">' . image_visibility_options((string) $image['visibility']) . '</select></label>';
    if (nsfw_guard_schema_ready()) {
        echo '<label><input type="checkbox" name="nsfw_enabled" value="1"' . ((int) ($image['nsfw_enabled'] ?? 0) === 1 ? ' checked' : '') . '> Mark this photo as NSFW / 18+</label>';
        echo '<p class="muted">When enabled, anonymous visitors must confirm they are 18+ before this photo, thumbnail, or original media file is served. Before using NSFW content, please verify that your hosting provider or web hosting plan permits it, as adult content may violate their policies.</p>';
    }
    echo '<label>Sort order<input name="sort_order" type="number" value="' . (int) $image['sort_order'] . '"></label>';
    if (thumbnail_bounds_schema_ready()) {
        render_admin_thumbnail_bound_slider('image_thumbnail', isset($image['thumbnail_min_size']) ? (int) $image['thumbnail_min_size'] : null, isset($image['thumbnail_max_size']) ? (int) $image['thumbnail_max_size'] : null, 'Responsive thumbnail quality bounds', 'Optional per-photo guardrails. These can override gallery-level guardrails when the public selection logic is wired in the next step.');
    } else {
        echo '<p class="muted">Thumbnail quality bounds will be available after the database migration is applied.</p>';
    }
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
    // $selected stores the canonical value shown by the simplified visibility UI.
    $selected = normalize_gallery_visibility($selected);
    foreach (gallery_visibility_values() as $visibility) {
        $html .= '<option value="' . e($visibility) . '"' . ($visibility === $selected ? ' selected' : '') . '>' . e(gallery_visibility_label($visibility)) . '</option>';
    }
    return $html;
}

/**
 * Handles image visibility options logic for the gallery application.
 * @param mixed $selected Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function image_visibility_options(string $selected): string
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
function gallery_parent_options_for_new(int $selectedGalleryId = 0): string
{
    // $html stores an intermediate value used by the surrounding gallery workflow.
    $html = '';
    // $galleries stores an intermediate value used by the surrounding gallery workflow.
    $galleries = db()->query('SELECT id, title, folder_path FROM galleries ORDER BY folder_path')->fetchAll();
    foreach ($galleries as $gallery) {
        // $selected stores the HTML selected marker for contextual admin links opened from a gallery page.
        $selected = (int) $gallery['id'] === $selectedGalleryId ? ' selected' : '';
        $html .= '<option value="' . (int) $gallery['id'] . '"' . $selected . '>' . e($gallery['title'] . ' (' . $gallery['folder_path'] . ')') . '</option>';
    }
    return $html;
}

/**
 * Handles gallery options for select logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function gallery_options_for_select(int $selectedGalleryId = 0, int $excludedGalleryId = 0): string
{
    // $html stores an intermediate value used by the surrounding gallery workflow.
    $html = '';
    // $galleries stores an intermediate value used by the surrounding gallery workflow.
    $galleries = db()->query('SELECT id, title, folder_path FROM galleries ORDER BY folder_path')->fetchAll();
    foreach ($galleries as $gallery) {
        if ($excludedGalleryId > 0 && (int) $gallery['id'] === $excludedGalleryId) {
            continue;
        }
        // $selected stores the HTML selected marker for contextual upload links opened from a gallery page.
        $selected = (int) $gallery['id'] === $selectedGalleryId ? ' selected' : '';
        // $folderPath stores the normalized public folder path used for hierarchy depth.
        $folderPath = trim((string) ($gallery['folder_path'] ?? ''), '/');
        // $depth stores how deeply nested the gallery is in the hierarchy.
        $depth = $folderPath === '' ? 0 : max(0, substr_count($folderPath, '/'));
        // $indent stores visible indentation that survives native select rendering better than CSS padding on options.
        $indent = str_repeat(' ', $depth);
        // $branch stores a compact hierarchy marker for nested galleries.
        $branch = $depth > 0 ? '↳ ' : '';
        // $pathSuffix stores the filesystem-style path hint without making the title hard to scan.
        $pathSuffix = $folderPath !== '' ? '  ·  /' . $folderPath : '';
        // $label stores the formatted select option label.
        $label = $indent . $branch . (string) $gallery['title'] . $pathSuffix;
        $html .= '<option value="' . (int) $gallery['id'] . '"' . $selected . '>' . e($label) . '</option>';
    }
    return $html;
}

/**
 * Read a gallery ID from the query string and only return it when the gallery exists.
 *
 * Contextual admin shortcuts pass gallery IDs through GET parameters. Validating the
 * identifier here keeps form pre-selection defensive and prevents stale or manually
 * edited URLs from selecting a non-existent gallery row.
 */
function selected_gallery_id_from_query(string $parameterName): int
{
    // $galleryId stores the normalized numeric query parameter.
    $galleryId = (int) ($_GET[$parameterName] ?? 0);
    if ($galleryId <= 0) {
        return 0;
    }
    return find_gallery($galleryId) ? $galleryId : 0;
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

