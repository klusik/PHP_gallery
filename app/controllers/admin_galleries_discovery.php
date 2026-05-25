<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_discovery.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Handles admin discovery, import, and create-gallery workflows.
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
        admin_log_event('info', 'galleries.refresh_scanned', t('admin.galleries.log_refreshed_imported'), $refresh);
    }
    // Variable $candidates stores this steps working value.
    $candidates = discover_gallery_candidates();
    render_header(t('admin.galleries.discover_title'));
    echo '<section class="panel"><h1>' . e(t('admin.galleries.discover_title')) . '</h1>';
    echo '<p><a class="button secondary" href="' . e(url_for('admin')) . '">' . e(t('admin.common.back_to_dashboard')) . '</a></p>';
    if ($refresh !== null) {
        echo '<div class="notice">' . e(t('admin.galleries.discover_refresh_notice', 'Scanned {galleries} existing galleries and imported or updated {images} images.', ['galleries' => (int) $refresh['galleries'], 'images' => (int) $refresh['images']])) . '</div>';
    }
    if (!$candidates) {
        echo '<p>' . e(t('admin.galleries.discover_none_found')) . '</p>';
    } else {
        echo '<form method="post" action="' . e(url_for('admin_import')) . '" data-import-galleries-form>' . csrf_field();
        echo '<p><label><input type="checkbox" name="create_thumbnails" value="1" checked> ' . e(t('admin.galleries.discover_create_thumbnails')) . '</label></p>';
        echo '<table><thead><tr><th>' . e(t('admin.galleries.discover_column_import')) . '</th><th>' . e(t('admin.galleries.discover_column_folder')) . '</th><th>' . e(t('admin.galleries.discover_column_title')) . '</th><th>' . e(t('admin.galleries.discover_column_visibility')) . '</th></tr></thead><tbody>';
        foreach ($candidates as $candidate) {
            echo '<tr><td><input type="checkbox" name="folders[]" value="' . e($candidate['folder_path']) . '"></td><td>' . e($candidate['folder_path']) . '</td><td>' . e($candidate['title']) . '</td><td>' . e($candidate['visibility']) . '</td></tr>';
        }
        echo '</tbody></table><button type="submit">' . e(t('admin.galleries.discover_import_selected')) . '</button></form>';
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
    flash_message('admin_notice', t('admin.galleries.import_result', 'Imported {galleries} gallery folder(s) and created {thumbnails} thumbnail(s).', ['galleries' => (int) ($result['imported'] ?? 0), 'thumbnails' => (int) ($result['thumbnails'] ?? 0)]));
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
            $gallery = admin_create_gallery_from_input($_POST);
            if (admin_wants_json()) {
                header('Content-Type: application/json');
                echo json_encode(admin_new_gallery_success_response($gallery));
                return;
            }
            flash_message('admin_notice', t('admin.galleries.folder_created'));
            redirect_to(url_for('admin_edit_gallery', ['id' => $gallery['id'], 'created' => 1]));
        } catch (Throwable $exception) {
            // $error stores an intermediate value used by the surrounding gallery workflow.
            $error = $exception->getMessage();
            admin_log_event('error', 'gallery.folder_create_failed', t('admin.galleries.log_empty_folder_failed'), ['error' => $error]);
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

    render_header(t('admin.galleries.create_empty_title'));
    echo '<section class="hero"><h1>' . e(t('admin.galleries.create_empty_title')) . '</h1><nav class="nav"><a class="button secondary" href="' . e(url_for('admin')) . '">' . e(t('admin.common.back_to_dashboard')) . '</a><a class="button secondary" href="' . e(url_for('admin_upload')) . '">' . e(t('admin.upload.title')) . '</a></nav></section>';
    if ($prefillParentGallery) {
        echo '<div class="notice">' . e(t('admin.galleries.create_inside_notice', 'New gallery will be created inside: {gallery}.', ['gallery' => (string) $prefillParentGallery['title']])) . '</div>';
    }
    if ($error !== '') {
        echo '<div class="notice">' . e(t('admin.galleries.create_failed', ['error' => $error])) . '</div>';
    }
    echo '<section class="panel"><form method="post" action="' . e(url_for('admin_new_gallery')) . '" class="form-grid">' . csrf_field();
    render_admin_new_gallery_fields($prefillParentId, false);
    echo '<button type="submit">' . e(t('admin.galleries.create_folder_button')) . '</button></form></section>';
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
 * Normalize create-gallery input for every admin workflow.
 */
function admin_new_gallery_input_from_array(array $input): array
{
    // $normalized stores the create-gallery input contract used by all admin workflows.
    $normalized = [
        'title' => $input['title'] ?? '',
        'folder_name' => $input['folder_name'] ?? '',
        'description' => $input['description'] ?? '',
        'gallery_date' => $input['gallery_date'] ?? '',
        'visibility' => gallery_visibility_storage_value((string) ($input['visibility'] ?? 'unpublished')),
        'parent_id' => $input['parent_id'] ?? 0,
        'voting_enabled' => $input['voting_enabled'] ?? 0,
        'show_filenames' => $input['show_filenames'] ?? 0,
        'count_badge_visibility' => $input['count_badge_visibility'] ?? 'inherit',
    ];
    if (array_key_exists('sort_order', $input)) {
        $normalized['sort_order'] = $input['sort_order'];
    }
    return $normalized;
}

/**
 * Read create-gallery POST values through the same input contract used by the direct admin page.
 */
function admin_new_gallery_input_from_post(): array
{
    return admin_new_gallery_input_from_array($_POST);
}

/**
 * Create a gallery through the shared admin create implementation.
 */
function admin_create_gallery_from_input(array $input): array
{
    // $gallery stores the persisted gallery returned by the service layer.
    $gallery = create_empty_gallery(admin_new_gallery_input_from_array($input));
    admin_log_event('info', 'gallery.folder_created', t('admin.galleries.log_empty_folder_created'), [
        'gallery_id' => (int) $gallery['id'],
        'folder_path' => (string) $gallery['folder_path'],
    ]);
    return $gallery;
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
        'message' => t('admin.galleries.folder_created'),
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
 * Render compact Markdown formatting guidance for gallery description fields.
 */
function render_gallery_description_formatting_hint(): void
{
    if (function_exists('view_render_gallery_description_formatting_hint')) {
        view_render_gallery_description_formatting_hint();
        return;
    }
    echo '<details class="gallery-description-format-help"><summary><span aria-hidden="true">💡</span><span>' . e(t('admin.gallery_editor.description_format_hints', 'Formatting hints')) . '</span></summary><div class="gallery-description-format-help-popover">';
    echo '<p>' . e(t('admin.gallery_editor.description_format_intro', 'Basic Markdown is supported in public gallery descriptions.')) . '</p>';
    echo '<ul>';
    echo '<li><code>**' . e(t('admin.gallery_editor.description_format_bold_word', 'bold')) . '**</code> ' . e(t('admin.gallery_editor.description_format_bold', 'makes bold text')) . '</li>';
    echo '<li><code>*' . e(t('admin.gallery_editor.description_format_italic_word', 'italic')) . '*</code> ' . e(t('admin.gallery_editor.description_format_italic', 'makes italic text')) . '</li>';
    echo '<li><code>`code`</code> ' . e(t('admin.gallery_editor.description_format_code', 'uses inline code styling')) . '</li>';
    echo '<li><code>[Link](https://example.com)</code> ' . e(t('admin.gallery_editor.description_format_link', 'creates a safe external link')) . '</li>';
    echo '<li>' . e(t('admin.gallery_editor.description_format_newlines', 'A single Enter is preserved as a new line. Empty lines create separate paragraphs.')) . '</li>';
    echo '</ul></div></details>';
}

/**
 * Render create-gallery fields shared by full admin pages and panel fragments.
 */
function render_admin_new_gallery_fields(int $prefillParentId, bool $panelMode, string $workflow = 'create'): void
{
    if (function_exists('view_render_admin_new_gallery_fields')) {
        view_render_admin_new_gallery_fields($prefillParentId, $panelMode, $workflow);
        return;
    }
    // $isUploadWorkflow stores whether the shared create fields are embedded in the upload workflow.
    $isUploadWorkflow = $workflow === 'upload';
    if ($panelMode) {
        echo '<input type="hidden" name="panel" value="1">';
    }
    if ($panelMode) {
        $panelHelp = $isUploadWorkflow ? t('admin.upload.gallery_identity_help', 'Create an empty gallery, or select photos and upload them immediately.') : t('admin.gallery_editor.only_gallery_created_here', 'Only the gallery is created here.');
        $panelKicker = $isUploadWorkflow ? t('admin.upload.new_child_gallery', 'New child gallery') : t('admin.gallery_editor.new_gallery_kicker', 'New gallery');
        echo '<div class="admin-side-panel-card admin-side-panel-primary-card"><div class="admin-side-panel-card-heading"><div><p class="admin-kicker">' . e($panelKicker) . '</p><h3>' . e(t('admin.gallery_editor.gallery_identity', 'Gallery identity')) . '</h3></div><p class="muted">' . e($panelHelp) . '</p></div><div class="admin-side-panel-field-grid">';
        echo '<label class="admin-side-panel-field admin-side-panel-field-wide"><span>' . e(t('admin.gallery_editor.gallery_name', 'Gallery name')) . '</span><input name="title" required></label>';
        echo '<label class="admin-side-panel-field"><span>' . e(t('admin.gallery_editor.folder_name', 'Folder name')) . '</span><input name="folder_name" autocomplete="off"><small>' . e(t('admin.gallery_editor.derive_from_gallery_name', 'Leave empty to derive it from the gallery name.')) . '</small></label>';
        echo '<label class="admin-side-panel-field"><span>' . e(t('admin.gallery_editor.metric_visibility')) . '</span><select name="visibility">' . visibility_options('unpublished') . '</select></label>';
        if (gallery_date_schema_ready()) {
            echo '<label class="admin-side-panel-field"><span>' . e(t('admin.gallery_editor.gallery_date', 'Date')) . '</span><input name="gallery_date" type="date"><small>' . e(t('admin.gallery_editor.gallery_date_help', 'Optional manual gallery date, for example an event, trip, or shooting date.')) . '</small></label>';
        } else {
            echo '<div class="admin-side-panel-field admin-side-panel-field-wide"><span>' . e(t('admin.gallery_editor.gallery_date', 'Date')) . '</span><small>' . e(t('admin.gallery_editor.gallery_date_migration_hidden', 'Gallery date will be available after the database migration is applied.')) . '</small></div>';
        }
        echo '<label class="admin-side-panel-field admin-side-panel-field-wide"><span>' . e(t('admin.gallery_editor.parent_gallery', 'Parent gallery')) . '</span><select name="parent_id"><option value="0"' . ($prefillParentId === 0 ? ' selected' : '') . '>' . e(t('admin.gallery_editor.no_parent', 'No parent')) . '</option>' . gallery_parent_options_for_new($prefillParentId) . '</select></label>';
        echo '<label class="admin-side-panel-field admin-side-panel-field-wide"><span>' . e(t('admin.gallery_editor.description', 'Description')) . '</span><textarea name="description" rows="4"></textarea></label>';
        render_gallery_description_formatting_hint();
        echo '</div><div class="admin-side-panel-toggle-row">';
        echo '<label><input type="checkbox" name="voting_enabled" value="1"> <span>' . e(t('admin.gallery_editor.enable_image_voting_short', 'Enable image voting')) . '</span></label>';
        echo '<label><input type="checkbox" name="show_filenames" value="1"> <span>' . e(t('admin.gallery_editor.show_file_names', 'Show file names')) . '</span></label>';
        echo '</div></div>';
        if (gallery_count_badge_schema_ready()) {
            echo '<div class="admin-side-panel-card"><label class="admin-side-panel-field"><span>' . e(t('admin.gallery_editor.count_badge_title', 'Contained-picture badge')) . '</span><select name="count_badge_visibility">';
            foreach (gallery_count_badge_override_values() as $countBadgeOption) {
                echo '<option value="' . e($countBadgeOption) . '"' . ($countBadgeOption === 'inherit' ? ' selected' : '') . '>' . e(gallery_count_badge_override_label($countBadgeOption)) . '</option>';
            }
            echo '</select><small>' . e(t('admin.gallery_editor.count_badge_new_gallery_help', 'Controls the stacked-picture icon and image count on this gallery card.')) . '</small></label></div>';
        }
        return;
    }
    echo '<label>' . e(t('admin.gallery_editor.gallery_name', 'Gallery name')) . '<input name="title" required></label>';
    echo '<label>' . e(t('admin.gallery_editor.folder_name', 'Folder name')) . '<input name="folder_name" autocomplete="off"><span class="muted">' . e(t('admin.gallery_editor.derive_from_gallery_name', 'Leave empty to derive it from the gallery name.')) . '</span></label>';
    echo '<label>' . e(t('admin.gallery_editor.parent_gallery', 'Parent gallery')) . '<select name="parent_id"><option value="0"' . ($prefillParentId === 0 ? ' selected' : '') . '>' . e(t('admin.gallery_editor.no_parent', 'No parent')) . '</option>' . gallery_parent_options_for_new($prefillParentId) . '</select></label>';
    echo '<label>' . e(t('admin.gallery_editor.visibility', 'Visibility')) . '<select name="visibility">' . visibility_options('unpublished') . '</select></label>';
    if (gallery_date_schema_ready()) {
        echo '<label>' . e(t('admin.gallery_editor.gallery_date', 'Date')) . '<input name="gallery_date" type="date"><span class="muted">' . e(t('admin.gallery_editor.gallery_date_help', 'Optional manual gallery date, for example an event, trip, or shooting date.')) . '</span></label>';
    } else {
        echo '<p class="muted">' . e(t('admin.gallery_editor.gallery_date_migration_hidden', 'Gallery date will be available after the database migration is applied.')) . '</p>';
    }
    echo '<label><input type="checkbox" name="voting_enabled" value="1"> ' . e(t('admin.gallery_editor.enable_image_voting', 'Enable image voting for this gallery')) . '</label>';
    echo '<label><input type="checkbox" name="show_filenames" value="1"> ' . e(t('admin.gallery_editor.show_file_names', 'Show file names')) . '</label>';
    if (gallery_count_badge_schema_ready()) {
        echo '<label>' . e(t('admin.gallery_editor.count_badge_title', 'Contained-picture badge')) . '<select name="count_badge_visibility">';
        foreach (gallery_count_badge_override_values() as $countBadgeOption) {
            echo '<option value="' . e($countBadgeOption) . '"' . ($countBadgeOption === 'inherit' ? ' selected' : '') . '>' . e(gallery_count_badge_override_label($countBadgeOption)) . '</option>';
        }
        echo '</select><span class="muted">' . e(t('admin.gallery_editor.count_badge_new_gallery_help', 'Controls the stacked-picture icon and image count on this gallery card.')) . '</span></label>';
    }
    echo '<label>' . e(t('admin.gallery_editor.description', 'Description')) . '<textarea name="description"></textarea></label>';
    render_gallery_description_formatting_hint();
}

/**
 * Render the focused side-panel create workflow without the normal admin shell.
 */
function render_admin_new_gallery_side_panel(int $prefillParentId, ?array $prefillParentGallery, string $error): void
{
    if (function_exists('view_render_admin_new_gallery_side_panel')) {
        view_render_admin_new_gallery_side_panel($prefillParentId, $prefillParentGallery, $error);
        return;
    }
    echo '<div class="admin-side-panel-stack" data-gallery-create-panel>';
    echo '<div class="admin-side-panel-copy"><p class="admin-kicker">' . e(t('admin.gallery_editor.gallery_workflow', 'Gallery workflow')) . '</p><h2>' . e(t('admin.gallery_editor.create_gallery', 'Create gallery')) . '</h2><p class="muted">' . e(t('admin.gallery_editor.create_gallery_empty_help', 'Create a new empty gallery in the selected parent. Photo upload stays in the separate upload workflow.')) . '</p></div>';
    if ($prefillParentGallery) {
        echo '<div class="notice">' . e(t('admin.gallery_editor.target_parent', 'Target parent: {title}.', ['title' => (string) $prefillParentGallery['title']])) . '</div>';
    }
    if ($error !== '') {
        echo '<div class="notice">' . e(t('admin.galleries.create_failed', ['error' => $error])) . '</div>';
    }
    echo '<section class="admin-side-panel-workflow" data-gallery-panel-workflow>';
    echo '<form method="post" action="' . e(url_for('admin_new_gallery')) . '" class="admin-side-panel-form" data-gallery-panel-create-form>' . csrf_field();
    render_admin_new_gallery_fields($prefillParentId, true);
    echo '<div class="admin-side-panel-actions"><button type="submit" class="button primary" data-gallery-panel-submit>' . e(t('admin.gallery_editor.create_gallery', 'Create gallery')) . '</button><p class="muted">' . e(t('admin.gallery_editor.new_gallery_empty_help', 'The new gallery is created empty. Use Upload photos for media.')) . '</p></div>';
    echo '</form></section>';
    echo '</div>';
}
