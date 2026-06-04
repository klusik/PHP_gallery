<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_public_inline.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Handles inline public-page admin updates.
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

    // $input stores the partial edit data accepted by public contextual admin actions.
    $input = $_POST;
    // Variable $title stores this steps working value.
    $title = trim((string) ($input['title'] ?? ''));
    if ($title === '') {
        // $title stores an intermediate value used by the surrounding gallery workflow.
        $title = (string) $gallery['title'];
    }
    $input['title'] = $title;
    if (!array_key_exists('description', $input)) {
        $input['description'] = (string) ($gallery['description'] ?? '');
    }
    // Variable $visibility stores this steps working value.
    $visibility = gallery_visibility_storage_value((string) ($gallery['visibility'] ?? 'unpublished'));
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
    $input['visibility'] = $visibility;
    try {
        admin_save_gallery_from_input($gallery, $input, $_FILES, 'admin-edit-identity', false);
    } catch (Throwable $exception) {
        flash_message('admin_notice', $exception->getMessage());
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
 * Render read-only internal AI metadata for a photo editor screen.
 *
 * The generated text is deliberately not placed into the public description
 * textarea. It is machine-generated search/indexing context and should stay
 * inspectable by admins without becoming authoritative human copy.
 *
 * @param array<string,mixed> $image Image row currently being edited.
 * @return void
 */
function render_admin_image_ai_metadata_panel(array $image): void
{
    if (function_exists('feature_flag_enabled') && !feature_flag_enabled('ai_image_metadata')) {
        return;
    }
    if (!function_exists('ai_image_analysis_latest_metadata_for_image')) {
        return;
    }

    if (!function_exists('ai_image_analysis_schema_ready') || !ai_image_analysis_schema_ready()) {
        echo '<div class="admin-ai-metadata-panel"><h2>' . e(t('admin.gallery_editor.ai_metadata_title', 'Internal AI metadata')) . '</h2><p class="muted">' . e(t('admin.gallery_editor.ai_metadata_migration_hidden', 'AI metadata inspection will be available after the AI image-analysis migration is applied.')) . '</p></div>';
        return;
    }

    // $metadataRow stores the newest worker result for this image and model.
    $metadataRow = ai_image_analysis_latest_metadata_for_image((int) ($image['id'] ?? 0));
    echo '<div class="admin-ai-metadata-panel"><h2>' . e(t('admin.gallery_editor.ai_metadata_title', 'Internal AI metadata')) . '</h2>';
    echo '<p class="muted">' . e(t('admin.gallery_editor.ai_metadata_help', 'Read-only metadata generated by the Windows AI worker. This is used for search and indexing, not as the public photo description.')) . '</p>';

    if (!$metadataRow) {
        echo '<p class="muted">' . e(t('admin.gallery_editor.ai_metadata_empty', 'No AI metadata has been generated for this photo yet.')) . '</p></div>';
        return;
    }

    // $generatedAt stores the metadata creation timestamp shown to admins.
    $generatedAt = (string) ($metadataRow['generated_at'] ?? '');
    // $modelLabel stores the model and version that produced the displayed result.
    $modelLabel = trim((string) ($metadataRow['model_name'] ?? '') . ' ' . (string) ($metadataRow['model_version'] ?? ''));
    // $sourceSummary stores the source image fingerprint used for regeneration checks.
    $sourceSummary = [];
    if (!empty($metadataRow['source_checksum_sha256'])) {
        $sourceSummary[] = 'sha256 ' . (string) $metadataRow['source_checksum_sha256'];
    }
    if (!empty($metadataRow['source_file_size'])) {
        $sourceSummary[] = number_format((float) $metadataRow['source_file_size'], 0, '.', ' ') . ' bytes';
    }
    if (!empty($metadataRow['source_modified_at'])) {
        $sourceSummary[] = 'modified ' . (string) $metadataRow['source_modified_at'];
    }

    echo '<dl class="admin-ai-metadata-facts">';
    echo '<dt>' . e(t('admin.gallery_editor.ai_metadata_model', 'Model')) . '</dt><dd>' . e($modelLabel !== '' ? $modelLabel : t('admin.gallery_editor.unknown', 'Unknown')) . '</dd>';
    echo '<dt>' . e(t('admin.gallery_editor.ai_metadata_generated', 'Generated')) . '</dt><dd>' . e($generatedAt !== '' ? $generatedAt : t('admin.gallery_editor.unknown', 'Unknown')) . '</dd>';
    echo '<dt>' . e(t('admin.gallery_editor.ai_metadata_source', 'Source')) . '</dt><dd>' . e($sourceSummary ? implode(' | ', $sourceSummary) : t('admin.gallery_editor.unknown', 'Unknown')) . '</dd>';
    echo '</dl>';

    echo '<label>' . e(t('admin.gallery_editor.ai_metadata_searchable_text', 'Searchable internal text')) . '<textarea readonly rows="5">' . e((string) ($metadataRow['searchable_text'] ?? '')) . '</textarea></label>';
    echo '<label>' . e(t('admin.gallery_editor.ai_metadata_raw_json', 'Raw metadata JSON')) . '<textarea readonly rows="10">' . e(ai_image_analysis_metadata_pretty_json($metadataRow)) . '</textarea></label>';
    echo '</div>';
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
    echo '<section class="panel"><h1>' . e(t('admin.gallery_editor.edit_image', 'Edit image')) . '</h1><p><img decoding="async" loading="lazy" src="' . e(thumbnail_url($image, 800)) . '" alt=""></p>';
    echo '<form method="post" class="form-grid">' . csrf_field();
    echo '<input type="hidden" name="id" value="' . (int) $image['id'] . '">';
    echo '<label>' . e(t('admin.gallery_editor.title', 'Title')) . '<input name="title" value="' . e($image['title']) . '"></label>';
    echo '<label>' . e(t('admin.gallery_editor.description', 'Description')) . '<textarea name="description" data-openai-description-textarea>' . e($image['description']) . '</textarea></label>';
    if (function_exists('view_render_admin_openai_text_assist_tool')) {
        view_render_admin_openai_text_assist_tool((int) ($image['gallery_id'] ?? 0), (int) $image['id'], 'image');
    }
    echo '<label>' . e(t('admin.gallery_editor.visibility', 'Visibility')) . '<select name="visibility">' . image_visibility_options((string) $image['visibility']) . '</select></label>';
    if (nsfw_guard_schema_ready()) {
        echo '<label><input type="checkbox" name="nsfw_enabled" value="1"' . ((int) ($image['nsfw_enabled'] ?? 0) === 1 ? ' checked' : '') . '> Mark this photo as NSFW / 18+</label>';
        echo '<p class="muted">When enabled, anonymous visitors must confirm they are 18+ before this photo, thumbnail, or original media file is served. Before using NSFW content, please verify that your hosting provider or web hosting plan permits it, as adult content may violate their policies.</p>';
    }
    echo '<label>' . e(t('admin.gallery_editor.sort_order', 'Sort order')) . '<input name="sort_order" type="number" value="' . (int) $image['sort_order'] . '"></label>';
    if (thumbnail_bounds_schema_ready()) {
        render_admin_thumbnail_bound_slider('image_thumbnail', isset($image['thumbnail_min_size']) ? (int) $image['thumbnail_min_size'] : null, isset($image['thumbnail_max_size']) ? (int) $image['thumbnail_max_size'] : null, 'Responsive thumbnail quality bounds', 'Optional per-photo guardrails. These can override gallery-level guardrails when the public selection logic is wired in the next step.');
    } else {
        echo '<p class="muted">Thumbnail quality bounds will be available after the database migration is applied.</p>';
    }
    echo '<label>' . e(t('admin.gallery_editor.tags', 'Tags')) . '<input name="tags" value="' . e(tag_names_for_entity('image', (int) $image['id'])) . '" list="tag-suggestions" data-tag-input><span class="muted">' . e(t('admin.gallery_editor.tags_help', 'Separate tags with commas.')) . '</span></label>';
    render_tag_datalist();
    if (exif_gps_schema_ready()) {
        echo '<div class="exif-admin-summary"><h2>' . e(t('admin.gallery_editor.exif_gps', 'EXIF / GPS')) . '</h2><dl>';
        echo '<dt>' . e(t('admin.gallery_editor.taken', 'Taken')) . '</dt><dd>' . e((string) ($image['exif_taken_at'] ?? '')) . '</dd>';
        echo '<dt>' . e(t('admin.gallery_editor.camera', 'Camera')) . '</dt><dd>' . e(trim((string) ($image['exif_camera_make'] ?? '') . ' ' . (string) ($image['exif_camera_model'] ?? ''))) . '</dd>';
        echo '<dt>' . e(t('admin.gallery_editor.lens', 'Lens')) . '</dt><dd>' . e((string) ($image['exif_lens_model'] ?? '')) . '</dd>';
        echo '<dt>' . e(t('admin.gallery_editor.exposure', 'Exposure')) . '</dt><dd>' . e(trim((string) ($image['exif_focal_length'] ?? '') . ' ' . (string) ($image['exif_aperture'] ?? '') . ' ' . (string) ($image['exif_exposure_time'] ?? '') . ' ISO ' . (string) ($image['exif_iso'] ?? ''))) . '</dd>';
        echo '<dt>' . e(t('admin.gallery_editor.gps', 'GPS')) . '</dt><dd>' . (image_has_gps($image) ? e((string) $image['gps_lat'] . ', ' . (string) $image['gps_lng']) : t('admin.gallery_editor.no_gps', 'No GPS coordinates found')) . '</dd>';
        echo '</dl><p class="muted">EXIF and GPS values are refreshed when the image is scanned again.</p></div>';
    }
    render_admin_image_ai_metadata_panel($image);
    echo '<button type="submit">' . e(t('admin.gallery_editor.save_image', 'Save image')) . '</button></form></section>';
    render_footer();
}
