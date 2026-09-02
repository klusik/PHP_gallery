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
 *   2026-09-02
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use Throwable;
use function Gallery\Core\admin_mutation_descriptor;
use function Gallery\Core\admin_mutation_gallery_is_rendered_in_context;
use function Gallery\Core\admin_mutation_gallery_membership_postcondition;
use function Gallery\Core\admin_mutation_error_envelope;
use function Gallery\Core\admin_mutation_postcondition;
use function Gallery\Core\admin_mutation_public_gallery_context;
use function Gallery\Core\admin_mutation_success_envelope;
use function Gallery\Core\csrf_field;
use function Gallery\Core\db;
use function Gallery\Core\e;
use function Gallery\Core\flash_message;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\now_sql;
use function Gallery\Core\redirect_to;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\admin_log_event;
use function Gallery\Services\ai_image_analysis_latest_metadata_for_image;
use function Gallery\Services\ai_image_analysis_metadata_pretty_json;
use function Gallery\Services\ai_image_analysis_schema_ready;
use function Gallery\Services\delete_gallery_images;
use function Gallery\Services\delete_gallery_subtrees;
use function Gallery\Services\exif_gps_schema_ready;
use function Gallery\Services\feature_flag_enabled;
use function Gallery\Services\find_gallery;
use function Gallery\Services\find_image;
use function Gallery\Services\gallery_visibility_storage_value;
use function Gallery\Services\gallery_visibility_values;
use function Gallery\Services\image_has_gps;
use function Gallery\Services\nsfw_guard_schema_ready;
use function Gallery\Services\public_path_schema_ready;
use function Gallery\Services\regenerate_public_paths;
use function Gallery\Services\render_admin_thumbnail_bound_slider;
use function Gallery\Services\sync_entity_tags;
use function Gallery\Services\smart_gallery_assert_mutation_ready;
use function Gallery\Services\smart_gallery_schema_ready;
use function Gallery\Services\t;
use function Gallery\Services\tag_names_for_entity;
use function Gallery\Services\thumbnail_bound_pair_from_post;
use function Gallery\Services\thumbnail_bounds_schema_ready;
use function Gallery\Services\thumbnail_url;
use function Gallery\Services\content_localization_schema_ready;
use function Gallery\Services\content_save_localizations;
use function Gallery\Views\view_render_admin_openai_text_assist_tool;
use function Gallery\Views\view_render_content_localization_fields;

/**
 * Start a private output buffer for one JSON mutation response.
 *
 * Shared-hosting PHP configurations can print non-fatal warnings as HTML even when
 * the mutation itself is handled correctly. That output would corrupt the JSON body
 * and make the browser report an opaque "HTML instead of JSON" failure. The caller
 * receives the previous buffer level so only buffers opened by this request path are
 * discarded before the canonical JSON payload is emitted.
 *
 * @param bool $wantsJson Whether the current request expects JSON.
 * @return int Previous output-buffer level, or -1 when buffering was not started.
 */
function admin_public_inline_json_buffer_start(bool $wantsJson): int
{
    if (!$wantsJson || headers_sent()) {
        return -1;
    }
    $previousLevel = ob_get_level();
    ob_start();
    return $previousLevel;
}

/**
 * Emit one clean JSON response and record any accidental buffered PHP output.
 *
 * @param array<string,mixed> $payload Canonical JSON payload.
 * @param int $status HTTP response status.
 * @param int $bufferLevel Previous output-buffer level returned by admin_public_inline_json_buffer_start().
 */
function admin_public_inline_json_response(array $payload, int $status = 200, int $bufferLevel = -1): void
{
    $bufferedChunks = [];
    if ($bufferLevel >= 0) {
        while (ob_get_level() > $bufferLevel) {
            $chunk = ob_get_clean();
            if (is_string($chunk) && $chunk !== '') {
                array_unshift($bufferedChunks, $chunk);
            }
        }
    }

    $unexpectedOutput = trim(implode('', $bufferedChunks));
    if ($unexpectedOutput !== '') {
        $plainOutput = trim((string) preg_replace('/\s+/', ' ', strip_tags($unexpectedOutput)));
        $snippet = substr($plainOutput !== '' ? $plainOutput : $unexpectedOutput, 0, 1200);
        error_log('[PHP Gallery] Discarded unexpected output before Admin public mutation JSON response: ' . $snippet);

        // The diagnostic write is intentionally isolated too. A broken optional Admin-log
        // schema must not reintroduce displayed PHP output after the mutation buffer was cleaned.
        $diagnosticBufferLevel = ob_get_level();
        ob_start();
        try {
            admin_log_event('warning', 'gallery.public_json_output_discarded', 'Unexpected PHP output was discarded before an Admin public mutation JSON response.', [
                'output' => $snippet,
            ], ['category' => 'gallery', 'severity' => 'warning']);
        } finally {
            while (ob_get_level() > $diagnosticBufferLevel) {
                ob_end_clean();
            }
        }
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, private, max-age=0');
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo $json === false
        ? '{"ok":false,"error":"The server could not encode the mutation response.","error_code":"json_encode_failed"}'
        : $json;
}

/**
 * Handles cms admin public update gallery logic for the gallery application.
 */
function cms_admin_public_update_gallery(): void
{
    $wantsJson = \Gallery\Core\admin_wants_json();
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) ($_POST['gallery_id'] ?? 0));
    if (!$gallery) {
        if ($wantsJson) {
            admin_public_inline_json_response(admin_mutation_error_envelope(
                t('admin.side_panel.gallery_delete_failed', 'Gallery delete failed.'),
                'gallery_not_found',
                admin_mutation_descriptor('gallery.update', 'gallery', 'update')
            ), 404);
            return;
        }
        cms_not_found();
        return;
    }
    // Variable $action stores this steps working value.
    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'delete') {
        // $jsonBufferLevel isolates accidental PHP warning output from the JSON body.
        $jsonBufferLevel = admin_public_inline_json_buffer_start($wantsJson);
        // Variable $redirect stores this steps working value.
        $redirect = url_for('home');
        // $parentGalleryId stores the public listing that should lose this card after deletion.
        $parentGalleryId = (int) ($gallery['parent_id'] ?? 0);
        if (!empty($gallery['parent_id'])) {
            // Variable $parent stores this steps working value.
            $parent = find_gallery((int) $gallery['parent_id'], true);
            if ($parent) {
                // $redirect stores an intermediate value used by the surrounding gallery workflow.
                $redirect = gallery_public_url($parent);
            }
        }
        try {
            // $deleted stores the filesystem and database deletion result.
            $deleted = delete_gallery_subtrees([(int) $gallery['id']]);
            admin_log_event('warning', 'gallery.public_deleted', 'Admin deleted a gallery from the public page.', [
                'gallery_id' => (int) $gallery['id'],
                'folder_path' => (string) $gallery['folder_path'],
                'deleted_roots' => (int) ($deleted['root_count'] ?? 0),
                'deleted_rows' => (int) ($deleted['row_count'] ?? 0),
                'missing_folders' => (int) ($deleted['missing_folders'] ?? 0),
            ]);
            if ($wantsJson) {
                admin_public_inline_json_response(admin_mutation_success_envelope(
                    t('admin.galleries.deleted_result', 'Deleted {count} gallery folder(s).', ['count' => (int) ($deleted['root_count'] ?? 1)]),
                    admin_mutation_descriptor('gallery.delete', 'gallery', 'delete', [(int) $gallery['id']]),
                    null,
                    [
                        admin_mutation_public_gallery_context(
                            $parentGalleryId,
                            $redirect,
                            admin_mutation_gallery_membership_postcondition((int) $gallery['id'], $parentGalleryId, false)
                        ),
                    ],
                    ['redirect_url' => $redirect]
                ), 200, $jsonBufferLevel);
                return;
            }
        } catch (Throwable $exception) {
            admin_log_event('error', 'gallery.public_delete_failed', 'Public-page gallery delete failed.', [
                'gallery_id' => (int) $gallery['id'],
                'folder_path' => (string) $gallery['folder_path'],
                'error' => $exception->getMessage(),
            ]);
            $deleteFailureMessage = t('admin.galleries.delete_failed', 'Gallery delete failed: {error}', ['error' => $exception->getMessage()]);
            if ($wantsJson) {
                admin_public_inline_json_response(admin_mutation_error_envelope(
                    $deleteFailureMessage,
                    'gallery_delete_failed',
                    admin_mutation_descriptor('gallery.delete', 'gallery', 'delete', [(int) $gallery['id']]),
                    ['redirect_url' => $redirect]
                ), 422, $jsonBufferLevel);
                return;
            }
            flash_message('admin_notice', $deleteFailureMessage);
        }
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
        // $galleryId stores the image owner so inline deletion uses the same
        // filesystem-safe mutation service as bulk and duplicate-detector deletes.
        $galleryId = (int) ($image['gallery_id'] ?? 0);
        // $gallery and $fallbackUrl preserve the owning public context before the image row disappears.
        $gallery = $galleryId > 0 ? (find_gallery($galleryId, true) ?: find_gallery($galleryId)) : null;
        $fallbackUrl = (string) ($_SERVER['HTTP_REFERER'] ?? ($gallery ? gallery_public_url($gallery) : url_for('home')));
        try {
            // $deleted stores the complete database/filesystem cleanup result.
            $deleted = delete_gallery_images($galleryId, [(int) $image['id']]);
            if ((int) ($deleted['deleted'] ?? 0) <= 0) {
                throw new \RuntimeException('Image was not deleted.');
            }
            admin_log_event('warning', 'image.public_inline_deleted', 'Admin deleted an image from the public inline editor.', [
                'gallery_id' => $galleryId,
                'image_id' => (int) $image['id'],
                'files_deleted' => (int) ($deleted['files_deleted'] ?? 0),
                'derivatives_deleted' => (int) ($deleted['derivatives_deleted'] ?? 0),
                'missing_files' => (int) ($deleted['missing_files'] ?? 0),
                'cleanup_failed' => (int) ($deleted['cleanup_failed'] ?? 0),
            ], ['category' => 'other', 'severity' => 'warning']);
            if (\Gallery\Core\admin_wants_json()) {
                $contexts = $gallery ? [
                    admin_mutation_public_gallery_context(
                        $galleryId,
                        gallery_public_url($gallery),
                        admin_mutation_postcondition('image_absent', ['image_id' => (int) $image['id']])
                    ),
                ] : [];
                header('Content-Type: application/json');
                echo json_encode(admin_mutation_success_envelope(
                    t('admin.gallery_editor.image_deleted', 'Image deleted.'),
                    admin_mutation_descriptor('image.delete', 'image', 'delete', [(int) $image['id']]),
                    null,
                    $contexts,
                    ['redirect_url' => $fallbackUrl]
                ));
                return;
            }
        } catch (Throwable $exception) {
            admin_log_event('error', 'image.public_inline_delete_failed', 'Public inline image deletion failed.', [
                'gallery_id' => $galleryId,
                'image_id' => (int) $image['id'],
                'error' => $exception->getMessage(),
            ], ['category' => 'other', 'severity' => 'error']);
            $failureMessage = 'Image delete failed: ' . $exception->getMessage();
            if (\Gallery\Core\admin_wants_json()) {
                http_response_code(422);
                header('Content-Type: application/json');
                echo json_encode(admin_mutation_error_envelope(
                    $failureMessage,
                    'image_delete_failed',
                    admin_mutation_descriptor('image.delete', 'image', 'delete', [(int) $image['id']]),
                    ['redirect_url' => $fallbackUrl]
                ));
                return;
            }
            flash_message('admin_notice', $failureMessage);
        }
        redirect_to($fallbackUrl);
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
    if (!empty($_POST['nsfw_field_present']) && !nsfw_guard_schema_ready()) {
        flash_message('admin_notice', admin_nsfw_guard_mutation_error());
        redirect_to((string) ($_SERVER['HTTP_REFERER'] ?? url_for('home')));
    }
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
 */
function render_admin_image_ai_metadata_panel(array $image): void
{
    if (function_exists('Gallery\\Services\\feature_flag_enabled') && !feature_flag_enabled('ai_image_metadata')) {
        return;
    }
    if (!function_exists('Gallery\\Services\\ai_image_analysis_latest_metadata_for_image')) {
        return;
    }

    if (!function_exists('Gallery\\Services\\ai_image_analysis_schema_ready') || !ai_image_analysis_schema_ready()) {
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
        $shouldUpdateLocalization = array_key_exists('content_language', $_POST) || array_key_exists('translations', $_POST);
        if ($shouldUpdateLocalization && !content_localization_schema_ready('image')) {
            if (admin_wants_json()) {
                admin_panel_error_response(t('admin.content_localization.save_unavailable', 'Multilingual content was not saved because its database migration is unavailable.'), 503);
                return;
            }
            flash_message('admin_notice', t('admin.content_localization.save_unavailable', 'Multilingual content was not saved because its database migration is unavailable.'));
            redirect_to(url_for('admin_edit_image', ['id' => $image['id']]));
        }
        if (isset($_POST['editorial_rating'])) {
            smart_gallery_assert_mutation_ready('image.editorial_rating');
        }
        if (!empty($_POST['nsfw_field_present']) && !nsfw_guard_schema_ready()) {
            if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
                admin_panel_error_response(admin_nsfw_guard_mutation_error(), 503);
                return;
            }
            flash_message('admin_notice', admin_nsfw_guard_mutation_error());
            redirect_to(url_for('admin_edit_image', ['id' => $image['id']]));
        }
        // Variable $visibility stores this steps working value.
        $visibility = in_array($_POST['visibility'] ?? '', ['draft', 'public', 'private'], true) ? (string) $_POST['visibility'] : 'public';
        // Variable $fields stores this steps working value.
        $fields = [
            'title = ?' => (string) $_POST['title'],
            'description = ?' => (string) $_POST['description'],
            'visibility = ?' => $visibility,
            'sort_order = ?' => (int) $_POST['sort_order'],
        ];
        if (isset($_POST['editorial_rating'])) {
            $rating = max(0, min(5, (int) $_POST['editorial_rating']));
            $fields['editorial_rating = ?'] = $rating > 0 ? $rating : null;
        }
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
        if ($shouldUpdateLocalization) {
            content_save_localizations('image', (int) $image['id'], $_POST['content_language'] ?? null, $_POST['translations'] ?? []);
        }
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
    render_header(t('admin.gallery_editor.edit_image', 'Edit image'));
    echo '<section class="panel"><h1>' . e(t('admin.gallery_editor.edit_image', 'Edit image')) . '</h1><p><img decoding="async" loading="lazy" src="' . e(thumbnail_url($image, 800)) . '" alt=""></p>';
    echo '<form method="post" class="form-grid">' . csrf_field();
    echo '<input type="hidden" name="id" value="' . (int) $image['id'] . '">';
    echo '<label>' . e(t('admin.gallery_editor.title', 'Title')) . '<input name="title" value="' . e($image['title']) . '"></label>';
    echo '<label>' . e(t('admin.gallery_editor.description', 'Description')) . '<textarea name="description" data-openai-description-textarea>' . e($image['description']) . '</textarea></label>';
    view_render_content_localization_fields('image', $image);
    if (function_exists('Gallery\\Views\\view_render_admin_openai_text_assist_tool')) {
        view_render_admin_openai_text_assist_tool((int) ($image['gallery_id'] ?? 0), (int) $image['id'], 'image');
    }
    echo '<label>' . e(t('admin.gallery_editor.visibility', 'Visibility')) . '<select name="visibility">' . image_visibility_options((string) $image['visibility']) . '</select></label>';
    if (nsfw_guard_schema_ready()) {
        echo '<input type="hidden" name="nsfw_field_present" value="1"><label><input type="checkbox" name="nsfw_enabled" value="1"' . ((int) ($image['nsfw_enabled'] ?? 0) === 1 ? ' checked' : '') . '> ' . e(t('admin.gallery_editor.mark_photo_nsfw', 'Mark this photo as NSFW / 18+')) . '</label>';
        echo '<p class="muted">' . e(t('admin.gallery_editor.photo_nsfw_help', 'When enabled, anonymous visitors must confirm they are 18+ before this photo, thumbnail, or original media file is served. Before using NSFW content, please verify that your hosting provider or web hosting plan permits it, as adult content may violate their policies.')) . '</p>';
    }
    echo '<label>' . e(t('admin.gallery_editor.sort_order', 'Sort order')) . '<input name="sort_order" type="number" value="' . (int) $image['sort_order'] . '"></label>';
    if (smart_gallery_schema_ready()) {
        echo '<label>' . e(t('smart_gallery.editorial_rating', 'Editorial rating')) . '<select name="editorial_rating"><option value="0">' . e(t('smart_gallery.unrated', 'Unrated')) . '</option>';
        for ($ratingOption = 1; $ratingOption <= 5; $ratingOption++) {
            echo '<option value="' . $ratingOption . '"' . ((int) ($image['editorial_rating'] ?? 0) === $ratingOption ? ' selected' : '') . '>' . e(t('smart_gallery.rating_stars', '{count} stars', ['count' => $ratingOption])) . '</option>';
        }
        echo '</select><span class="muted">' . e(t('smart_gallery.rating_private_help', 'Private editorial metadata used by Smart Gallery rules; unrelated to visitor voting.')) . '</span></label>';
    }
    if (thumbnail_bounds_schema_ready()) {
        render_admin_thumbnail_bound_slider('image_thumbnail', isset($image['thumbnail_min_size']) ? (int) $image['thumbnail_min_size'] : null, isset($image['thumbnail_max_size']) ? (int) $image['thumbnail_max_size'] : null, t('admin.gallery_editor.thumbnail_bounds_title', 'Responsive thumbnail quality bounds'), t('admin.gallery_editor.thumbnail_bounds_help', 'Optional per-photo guardrails. These can override gallery-level guardrails when the public selection logic is wired in the next step.'));
    } else {
        echo '<p class="muted">' . e(t('admin.gallery_editor.thumbnail_bounds_migration_required', 'Thumbnail quality bounds will be available after the database migration is applied.')) . '</p>';
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
        echo '</dl><p class="muted">' . e(t('admin.gallery_editor.exif_refresh_hint', 'EXIF and GPS values are refreshed when the image is scanned again.')) . '</p></div>';
    }
    render_admin_image_ai_metadata_panel($image);
    echo '<button type="submit">' . e(t('admin.gallery_editor.save_image', 'Save image')) . '</button></form></section>';
    render_footer();
}
