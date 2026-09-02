<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_edit_metadata.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Handles gallery metadata organizer preview/apply actions and organizer panel rendering.
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

namespace Gallery\Controllers;

use RuntimeException;
use Throwable;
use const Gallery\Services\CMS_PAGINATION_DEFAULT_COLUMNS;
use const Gallery\Services\CMS_PAGINATION_DEFAULT_ROWS;
use const Gallery\Services\CMS_PAGINATION_MAX_COLUMNS;
use const Gallery\Services\CMS_PAGINATION_MAX_ROWS;
use function Gallery\Core\admin_mutation_descriptor;
use function Gallery\Core\admin_mutation_panel_metadata;
use function Gallery\Core\admin_mutation_postcondition;
use function Gallery\Core\admin_mutation_public_gallery_context;
use function Gallery\Core\admin_mutation_success_envelope;
use function Gallery\Core\csrf_field;
use function Gallery\Core\csrf_token;
use function Gallery\Core\current_user;
use function Gallery\Core\db;
use function Gallery\Core\e;
use function Gallery\Core\flash_message;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\image_public_url;
use function Gallery\Core\now_sql;
use function Gallery\Core\redirect_to;
use function Gallery\Core\render_admin_tab_panel;
use function Gallery\Core\render_admin_tabs;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\slugify;
use function Gallery\Core\unique_slug;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\ai_image_analysis_force_gallery_reprocess;
use function Gallery\Services\ai_image_analysis_schema_ready;
use function Gallery\Services\delete_gallery_branding_asset;
use function Gallery\Services\exif_gps_override_schema_ready;
use function Gallery\Services\exif_gps_schema_ready;
use function Gallery\Services\feature_flag_enabled;
use function Gallery\Services\find_gallery;
use function Gallery\Services\find_image;
use function Gallery\Services\flight_map_schema_ready;
use function Gallery\Services\gallery_access_schema_ready;
use function Gallery\Services\gallery_access_share_token_schema_ready;
use function Gallery\Services\gallery_background_source;
use function Gallery\Services\gallery_background_source_schema_ready;
use function Gallery\Services\gallery_branding_asset_paths;
use function Gallery\Services\gallery_branding_asset_types;
use function Gallery\Services\gallery_branding_asset_url;
use function Gallery\Services\gallery_branding_schema_ready;
use function Gallery\Services\gallery_count_badge_override_label;
use function Gallery\Services\gallery_count_badge_override_values;
use function Gallery\Services\gallery_count_badge_schema_ready;
use function Gallery\Services\gallery_count_badge_source_label;
use function Gallery\Services\gallery_count_badge_state_label;
use function Gallery\Services\gallery_count_badge_storage_value;
use function Gallery\Services\gallery_cover_asset_schema_ready;
use function Gallery\Services\gallery_cover_path;
use function Gallery\Services\gallery_date_input_value;
use function Gallery\Services\gallery_date_range_schema_ready;
use function Gallery\Services\gallery_date_range_storage_values;
use function Gallery\Services\gallery_date_schema_ready;
use function Gallery\Services\gallery_description_layout_label;
use function Gallery\Services\gallery_description_layout_options;
use function Gallery\Services\gallery_description_layout_schema_ready;
use function Gallery\Services\gallery_description_layout_source_label;
use function Gallery\Services\gallery_description_layout_storage_value;
use function Gallery\Services\gallery_effective_count_badge_enabled;
use function Gallery\Services\gallery_effective_description_layout;
use function Gallery\Services\gallery_effective_gps_map_enabled;
use function Gallery\Services\gallery_effective_grid_settings;
use function Gallery\Services\gallery_effective_lightbox_browsing_mode;
use function Gallery\Services\gallery_filename_display_schema_ready;
use function Gallery\Services\gallery_flight_map_row;
use function Gallery\Services\gallery_flight_map_unresolved_from_row;
use function Gallery\Services\gallery_folder_name_from_path;
use function Gallery\Services\gallery_gps_map_storage_value;
use function Gallery\Services\gallery_grid_form_columns;
use function Gallery\Services\gallery_grid_form_rows;
use function Gallery\Services\gallery_grid_has_explicit_override;
use function Gallery\Services\gallery_grid_schema_ready;
use function Gallery\Services\gallery_images;
use function Gallery\Services\gallery_lightbox_browsing_mode_label;
use function Gallery\Services\gallery_lightbox_browsing_mode_options;
use function Gallery\Services\gallery_lightbox_browsing_mode_override_label;
use function Gallery\Services\gallery_lightbox_browsing_mode_schema_ready;
use function Gallery\Services\gallery_lightbox_browsing_mode_source_label;
use function Gallery\Services\gallery_lightbox_browsing_mode_storage_value;
use function Gallery\Services\gallery_lightbox_total_count;
use function Gallery\Services\gallery_metadata_organizer_apply_date_plan;
use function Gallery\Services\gallery_metadata_organizer_apply_date_plan_batch;
use function Gallery\Services\gallery_metadata_organizer_apply_notice;
use function Gallery\Services\gallery_metadata_organizer_build_date_plan;
use function Gallery\Services\gallery_metadata_organizer_default_max_date;
use function Gallery\Services\gallery_metadata_organizer_default_min_date;
use function Gallery\Services\gallery_metadata_organizer_options;
use function Gallery\Services\gallery_metadata_organizer_schema_ready;
use function Gallery\Services\gallery_share_token_for_admin;
use function Gallery\Services\gallery_shows_filenames;
use function Gallery\Services\gallery_visibility_storage_value;
use function Gallery\Services\gallery_voting_schema_ready;
use function Gallery\Services\likely_gallery_destination_id;
use function Gallery\Services\media_renamer_default_pattern;
use function Gallery\Services\media_renamer_execute_gallery;
use function Gallery\Services\media_renamer_normalize_pattern;
use function Gallery\Services\move_gallery_folder_to_parent;
use function Gallery\Services\normalize_gallery_visibility;
use function Gallery\Services\nsfw_guard_schema_ready;
use function Gallery\Services\pagination_dimension_value;
use function Gallery\Services\picture_game_schema_ready;
use function Gallery\Services\public_path_schema_ready;
use function Gallery\Services\refresh_gallery_public_paths;
use function Gallery\Services\regenerate_gallery_share_token;
use function Gallery\Services\render_admin_thumbnail_bound_slider;
use function Gallery\Services\revoke_gallery_share_token;
use function Gallery\Services\save_gallery_flight_path_route;
use function Gallery\Services\save_gallery_thumbnail_bounds;
use function Gallery\Services\scan_gallery_images;
use function Gallery\Services\store_uploaded_gallery_branding_asset;
use function Gallery\Services\store_uploaded_gallery_cover;
use function Gallery\Services\sync_entity_tags;
use function Gallery\Services\t;
use function Gallery\Services\tag_names_for_entity;
use function Gallery\Services\thumbnail_bound_pair_from_post;
use function Gallery\Services\thumbnail_bounds_schema_ready;
use function Gallery\Services\thumbnail_url;
use function Gallery\Services\upload_error_message;
use function Gallery\Services\write_gallery_sidecar;
use function Gallery\Views\view_render_admin_gallery_date_range_fields;
use function Gallery\Views\view_render_admin_hero;
use function Gallery\Views\view_render_admin_metric_grid;
use function Gallery\Views\view_render_admin_openai_text_assist_tool;
use function Gallery\Views\view_render_admin_simbrief_description_tool;
use function Gallery\Views\view_render_admin_tab_intro;
use function Gallery\Services\admin_log_event;

/**
 * Apply the current gallery branch EXIF date suggestion directly from the gallery editor.
 *
 * @param array $gallery Gallery row or gallery data.
 */
function admin_apply_gallery_date_exif_suggestion(array $gallery): void
{
    // $galleryId stores the gallery whose own images and descendants drive the suggestion.
    $galleryId = (int) ($gallery['id'] ?? 0);
    admin_gallery_date_suggestion_handle_apply($galleryId, admin_edit_gallery_tab_url($galleryId, 'admin-edit-identity'));
}

/**
 * Apply a confirmed metadata-organizer date plan from the gallery editor.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param string $returnTab Return tab value.
 */
function admin_apply_gallery_metadata_organizer_date_plan(array $gallery, string $returnTab): void
{
    // $galleryId stores the gallery whose direct images should be organized.
    $galleryId = (int) ($gallery['id'] ?? 0);
    if (empty($_POST['confirm_metadata_organizer'])) {
        flash_message('admin_notice', t('admin.metadata_organizer.confirm_required', 'Confirm that you reviewed the organizer draft before moving files.'));
        redirect_to(admin_edit_gallery_tab_url($galleryId, 'admin-edit-organizer'));
    }

    try {
        $result = gallery_metadata_organizer_apply_date_plan($galleryId, $_POST);
        flash_message('admin_notice', gallery_metadata_organizer_apply_notice($result));
    } catch (Throwable $exception) {
        flash_message('admin_notice', $exception->getMessage());
    }

    redirect_to(admin_edit_gallery_tab_url($galleryId, $returnTab !== '' ? $returnTab : 'admin-edit-organizer'));
}

/**
 * Send one metadata-organizer JSON response.
 *
 * @param array<string,mixed> $payload Response payload.
 * @param int $statusCode HTTP status code.
 */
function admin_gallery_metadata_organizer_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload);
}

/**
 * Verify admin access for a metadata-organizer JSON route.
 *
 * @return bool True when the current visitor is an admin.
 */
function admin_gallery_metadata_organizer_require_admin_for_json(): bool
{
    $user = current_user();
    if ($user && (string) ($user['role'] ?? '') === 'admin') {
        return true;
    }

    admin_gallery_metadata_organizer_json_response([
        'ok' => false,
        'error' => t('admin.metadata_organizer.auth_required', 'Admin session expired. Reload the admin page and sign in again.'),
    ], 403);
    return false;
}

/**
 * Verify a metadata-organizer AJAX CSRF token and emit JSON on failure.
 *
 * @return bool True when the token is valid.
 */
function admin_gallery_metadata_organizer_verify_csrf_for_ajax(): bool
{
    // $token stores the submitted CSRF token for the organizer batch request.
    $token = (string) ($_POST['csrf_token'] ?? '');
    if ($token !== '' && hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        return true;
    }

    admin_log_event('warning', 'metadata_organizer.csrf_failed', 'Metadata organizer AJAX request failed CSRF validation.', [
        'gallery_id' => (int) ($_POST['id'] ?? $_GET['id'] ?? 0),
        'action' => (string) ($_POST['action'] ?? $_GET['action'] ?? ''),
    ], ['category' => 'security', 'severity' => 'warning']);
    admin_gallery_metadata_organizer_json_response([
        'ok' => false,
        'error' => t('admin.metadata_organizer.csrf_failed', 'Security token expired or invalid. Reload the admin page and try again.'),
    ], 400);
    return false;
}

/**
 * Return the requested gallery for a dedicated metadata-organizer JSON route.
 *
 * @return array|null Gallery row when found.
 */
function admin_gallery_metadata_organizer_route_gallery(): ?array
{
    $gallery = find_gallery((int) ($_GET['id'] ?? $_POST['id'] ?? $_POST['gallery_id'] ?? 0));
    if (!$gallery) {
        admin_gallery_metadata_organizer_json_response([
            'ok' => false,
            'error' => t('admin.metadata_organizer.gallery_missing', 'Gallery was not found.'),
        ], 404);
        return null;
    }

    return $gallery;
}

/**
 * Handle the dedicated metadata-organizer preview batch JSON route.
 */
function cms_admin_metadata_organizer_preview_batch(): void
{
    if (!admin_gallery_metadata_organizer_require_admin_for_json()) {
        return;
    }

    $gallery = admin_gallery_metadata_organizer_route_gallery();
    if (!$gallery) {
        return;
    }

    admin_gallery_metadata_organizer_preview_batch_response($gallery);
}

/**
 * Handle the dedicated metadata-organizer apply batch JSON route.
 */
function cms_admin_metadata_organizer_apply_date_plan_batch(): void
{
    if (!admin_gallery_metadata_organizer_require_admin_for_json()) {
        return;
    }

    if (!admin_gallery_metadata_organizer_verify_csrf_for_ajax()) {
        return;
    }

    $gallery = admin_gallery_metadata_organizer_route_gallery();
    if (!$gallery) {
        return;
    }

    admin_apply_gallery_metadata_organizer_date_plan_batch($gallery);
}

/**
 * Return one AJAX preview batch for the metadata organizer.
 *
 * @param array $gallery Gallery row or gallery data.
 */
function admin_gallery_metadata_organizer_preview_batch_response(array $gallery): void
{
    try {
        $galleryId = (int) ($gallery['id'] ?? 0);
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $limit = max(1, min(500, (int) ($_GET['limit'] ?? 200)));
        $plan = gallery_metadata_organizer_build_date_plan($galleryId, $_GET, $offset, $limit);
        admin_gallery_metadata_organizer_json_response([
            'ok' => true,
            'csrf_token' => csrf_token(),
            'plan' => $plan,
        ]);
    } catch (Throwable $exception) {
        admin_gallery_metadata_organizer_json_response([
            'ok' => false,
            'error' => $exception->getMessage(),
        ], 422);
    }
}

/**
 * Apply one AJAX-sized metadata-organizer move batch.
 *
 * @param array $gallery Gallery row or gallery data.
 */
function admin_apply_gallery_metadata_organizer_date_plan_batch(array $gallery): void
{
    // $galleryId stores the gallery whose direct images should be organized.
    $galleryId = (int) ($gallery['id'] ?? 0);
    if (empty($_POST['confirm_metadata_organizer'])) {
        admin_gallery_metadata_organizer_json_response([
            'ok' => false,
            'error' => t('admin.metadata_organizer.confirm_required', 'Confirm that you reviewed the organizer draft before moving files.'),
        ], 422);
        return;
    }

    try {
        $limit = max(1, min(10, (int) ($_POST['batch_limit'] ?? 1)));
        $result = gallery_metadata_organizer_apply_date_plan_batch($galleryId, $_POST, $limit);
        $ok = empty($result['failures']);
        $message = gallery_metadata_organizer_apply_notice($result);
        $editUrl = admin_edit_gallery_tab_url($galleryId, 'admin-edit-organizer');
        $sourceGallery = find_gallery($galleryId, true) ?: $gallery;
        $galleryUrl = gallery_public_url($sourceGallery);
        // $movedImageIds and $contexts describe every source/destination page touched by this batch.
        $movedImageIds = [];
        $contexts = [admin_mutation_public_gallery_context(
            $galleryId,
            $galleryUrl,
            admin_mutation_postcondition('gallery_image_count', [
                'gallery_id' => $galleryId,
                'count' => gallery_lightbox_total_count($sourceGallery, false, false),
            ])
        )];
        $seenDestinationIds = [];
        foreach ((array) ($result['group_results'] ?? []) as $groupResult) {
            foreach ((array) ($groupResult['image_ids'] ?? []) as $imageId) {
                $imageId = (int) $imageId;
                if ($imageId > 0 && !in_array($imageId, $movedImageIds, true)) {
                    $movedImageIds[] = $imageId;
                }
            }
            $destinationGalleryId = (int) ($groupResult['destination_gallery_id'] ?? 0);
            if ($destinationGalleryId <= 0 || isset($seenDestinationIds[$destinationGalleryId])) {
                continue;
            }
            $destinationGallery = find_gallery($destinationGalleryId, true);
            if (!$destinationGallery) {
                continue;
            }
            $seenDestinationIds[$destinationGalleryId] = true;
            $contexts[] = admin_mutation_public_gallery_context(
                $destinationGalleryId,
                gallery_public_url($destinationGallery),
                admin_mutation_postcondition('gallery_image_count', [
                    'gallery_id' => $destinationGalleryId,
                    'count' => gallery_lightbox_total_count($destinationGallery, false, false),
                ])
            );
        }
        $payload = $ok ? admin_mutation_success_envelope(
            $message,
            admin_mutation_descriptor('image.metadata_organize', 'image', 'move', $movedImageIds),
            admin_mutation_panel_metadata('gallery-edit', $editUrl, true),
            $contexts,
            ['redirect_url' => $editUrl]
        ) : ['ok' => false];
        admin_gallery_metadata_organizer_json_response(array_merge($payload, [
            'ok' => $ok,
            'message' => $message,
            'result' => $result,
            'edit_url' => $editUrl,
            'gallery_url' => $galleryUrl,
        ]), $ok ? 200 : 422);
    } catch (Throwable $exception) {
        admin_gallery_metadata_organizer_json_response([
            'ok' => false,
            'error' => $exception->getMessage(),
        ], 422);
    }
}

/**
 * Return a short sample of filenames from one organizer group.
 *
 * @param array $group Organizer group value.
 * @return string Text result for the caller.
 */
function admin_gallery_metadata_organizer_group_sample(array $group): string
{
    // $names stores readable photo names shown in the preview table.
    $names = [];
    foreach (array_slice((array) ($group['images'] ?? []), 0, 5) as $image) {
        $name = trim((string) ($image['relative_path'] ?? $image['filename'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }
    // $remaining stores how many additional photos are hidden behind the compact sample.
    $remaining = max(0, (int) ($group['image_count'] ?? 0) - count($names));
    $sample = implode(', ', $names);
    if ($remaining > 0) {
        $sample .= ($sample !== '' ? ', ' : '') . t('admin.metadata_organizer.more_files', '+{count} more', ['count' => (string) $remaining]);
    }
    return $sample;
}

/**
 * Render the metadata organizer panel inside the gallery editor.
 *
 * @param array $gallery Gallery row or gallery data.
 */
function render_admin_gallery_metadata_organizer_panel(array $gallery): void
{
    view_render_admin_tab_intro([
        'kicker' => t('admin.metadata_organizer.kicker', 'Metadata organizer'),
        'title' => t('admin.metadata_organizer.title', 'Create subgalleries from EXIF dates'),
        'description' => t('admin.metadata_organizer.description', 'Builds a draft from capture dates already stored in the database. The preview does not scan files or move anything. Applying the draft creates or reuses child galleries and then physically moves the originals and generated derivatives.'),
    ]);

    if (!gallery_metadata_organizer_schema_ready()) {
        echo '<section class="panel"><p class="muted">' . e(t('admin.metadata_organizer.schema_unavailable', 'Metadata organizer requires scanned EXIF capture-date data in the image database.')) . '</p></section>';
        return;
    }

    // $previewRequested stores whether the admin explicitly asked to build the draft table.
    $previewRequested = (string) ($_GET['metadata_organizer_preview'] ?? '') === '1';
    // $plan stores a draft plan only when the admin requested a preview.
    $plan = null;
    // $options stores current form defaults. It is recalculated through the service to keep validation identical.
    try {
        $options = gallery_metadata_organizer_options($_GET + [
            'min_date' => gallery_metadata_organizer_default_min_date(),
            'max_date' => gallery_metadata_organizer_default_max_date(),
        ]);
        if ($previewRequested) {
            $plan = gallery_metadata_organizer_build_date_plan((int) $gallery['id'], $_GET);
            $options = is_array($plan['options'] ?? null) ? $plan['options'] : $options;
        }
    } catch (Throwable $exception) {
        $options = [
            'primary' => 'date',
            'secondary' => 'none',
            'min_date' => gallery_metadata_organizer_default_min_date(),
            'max_date' => gallery_metadata_organizer_default_max_date(),
        ];
        echo '<div class="notice">' . e($exception->getMessage()) . '</div>';
    }

    echo '<div class="admin-metadata-organizer" data-admin-metadata-organizer data-admin-metadata-organizer-gallery-id="' . (int) $gallery['id'] . '">';
    echo '<section class="panel">';
    echo '<form method="get" action="' . e(url_for('admin_edit_gallery')) . '" class="admin-edit-card-grid" data-admin-metadata-organizer-preview-form data-admin-metadata-organizer-batch-size="200" data-admin-metadata-organizer-preview-url="' . e(url_for('admin_metadata_organizer_preview_batch', ['id' => (int) $gallery['id']])) . '" data-admin-metadata-organizer-apply-url="' . e(url_for('admin_metadata_organizer_apply_date_plan_batch', ['id' => (int) $gallery['id']])) . '">' . csrf_field();
    echo '<input type="hidden" name="page" value="admin_edit_gallery">';
    echo '<input type="hidden" name="id" value="' . (int) $gallery['id'] . '">';
    echo '<input type="hidden" name="tab" value="admin-edit-organizer">';
    echo '<input type="hidden" name="metadata_organizer_preview" value="1">';
    echo '<div class="admin-edit-card"><label>' . e(t('admin.metadata_organizer.primary_grouping', 'Primary grouping')) . '<select name="primary_grouping"><option value="date" selected>' . e(t('admin.metadata_organizer.group_by_date', 'Date')) . '</option></select><span class="muted">' . e(t('admin.metadata_organizer.primary_help', 'Phase 1 groups by EXIF capture date. GPS/place grouping will use the same draft model later.')) . '</span></label></div>';
    echo '<div class="admin-edit-card"><label>' . e(t('admin.metadata_organizer.secondary_grouping', 'Secondary grouping')) . '<select name="secondary_grouping"><option value="none" selected>' . e(t('admin.metadata_organizer.secondary_none', 'None')) . '</option><option value="location" disabled>' . e(t('admin.metadata_organizer.secondary_location_future', 'Location, planned')) . '</option></select><span class="muted">' . e(t('admin.metadata_organizer.secondary_help', 'Prepared for future date plus location or location plus date grouping.')) . '</span></label></div>';
    echo '<div class="admin-edit-card"><label>' . e(t('admin.metadata_organizer.min_date', 'Minimum EXIF date')) . '<input type="date" name="min_date" value="' . e((string) ($options['min_date'] ?? gallery_metadata_organizer_default_min_date())) . '"><span class="muted">' . e(t('admin.metadata_organizer.min_date_help', 'Photos before this date are ignored, useful for unset camera clocks.')) . '</span></label></div>';
    echo '<div class="admin-edit-card"><label>' . e(t('admin.metadata_organizer.max_date', 'Maximum EXIF date')) . '<input type="date" name="max_date" value="' . e((string) ($options['max_date'] ?? gallery_metadata_organizer_default_max_date())) . '"><span class="muted">' . e(t('admin.metadata_organizer.max_date_help', 'Photos after this date are ignored.')) . '</span></label></div>';
    echo '<div class="admin-edit-card is-wide"><button type="submit" class="secondary" data-admin-metadata-organizer-preview-button>' . e(t('admin.metadata_organizer.preview_button', 'Preview draft')) . '</button></div>';
    echo '</form>';
    echo '<div class="thumbnail-progress admin-metadata-organizer-progress" data-admin-metadata-organizer-progress hidden><progress class="thumbnail-progress-bar" value="0" max="100" data-admin-metadata-organizer-progress-bar></progress><p class="muted" data-admin-metadata-organizer-progress-text></p></div>';
    echo '<pre class="admin-metadata-organizer-log" data-admin-metadata-organizer-log hidden></pre>';
    echo '</section>';

    if (!$previewRequested || !is_array($plan)) {
        echo '<section class="panel" data-admin-metadata-organizer-results><p class="muted">' . e(t('admin.metadata_organizer.preview_prompt', 'Choose the date boundaries and preview the draft before applying any move.')) . '</p></section>';
        echo '</div>';
        return;
    }

    $groups = (array) ($plan['groups'] ?? []);
    echo '<section class="panel" data-admin-metadata-organizer-results>';
    echo '<h3>' . e(t('admin.metadata_organizer.preview_title', 'Draft structure')) . '</h3>';
    echo '<p class="muted">' . e(t('admin.metadata_organizer.preview_summary', 'Direct photos in this gallery: {total}. Candidate photos: {candidates}. Proposed subgalleries: {groups}. Ignored without EXIF date: {without}. Ignored before minimum: {before}. Ignored after maximum: {after}.', [
        'total' => (string) (int) ($plan['total_images'] ?? 0),
        'candidates' => (string) (int) ($plan['candidate_images'] ?? 0),
        'groups' => (string) count($groups),
        'without' => (string) (int) ($plan['ignored_without_date'] ?? 0),
        'before' => (string) (int) ($plan['ignored_before_min'] ?? 0),
        'after' => (string) (int) ($plan['ignored_after_max'] ?? 0),
    ])) . '</p>';

    if (!$groups) {
        echo '<p class="muted">' . e(t('admin.metadata_organizer.empty_preview', 'No photos match the current date boundaries.')) . '</p>';
        echo '</section></div>';
        return;
    }

    echo '<table><thead><tr><th>' . e(t('admin.metadata_organizer.target_gallery', 'Target subgallery')) . '</th><th>' . e(t('admin.metadata_organizer.status', 'Status')) . '</th><th>' . e(t('admin.metadata_organizer.photos', 'Photos')) . '</th><th>' . e(t('admin.metadata_organizer.sample', 'Sample')) . '</th></tr></thead><tbody>';
    foreach ($groups as $group) {
        $status = (string) ($group['destination_status'] ?? '') === 'existing'
            ? t('admin.metadata_organizer.status_existing', 'Existing gallery, photos will be added')
            : t('admin.metadata_organizer.status_new', 'New gallery will be created');
        echo '<tr><td><strong>' . e((string) ($group['title'] ?? '')) . '</strong><br><span class="muted">' . e((string) ($group['date'] ?? '')) . '</span></td><td>' . e($status) . '</td><td>' . (int) ($group['image_count'] ?? 0) . '</td><td>' . e(admin_gallery_metadata_organizer_group_sample($group)) . '</td></tr>';
    }
    echo '</tbody></table>';

    echo '<form method="post" action="' . e(url_for('admin_edit_gallery', ['id' => $gallery['id']])) . '" data-admin-metadata-organizer-apply-form data-admin-metadata-organizer-batch-size="1">' . csrf_field();
    echo '<input type="hidden" name="id" value="' . (int) $gallery['id'] . '">';
    echo '<input type="hidden" name="return_tab" value="admin-edit-organizer">';
    echo '<input type="hidden" name="action" value="apply_metadata_organizer_date_plan">';
    echo '<input type="hidden" name="primary_grouping" value="date">';
    echo '<input type="hidden" name="secondary_grouping" value="none">';
    echo '<input type="hidden" name="min_date" value="' . e((string) ($options['min_date'] ?? gallery_metadata_organizer_default_min_date())) . '">';
    echo '<input type="hidden" name="max_date" value="' . e((string) ($options['max_date'] ?? gallery_metadata_organizer_default_max_date())) . '">';
    echo '<label class="checkbox-label"><input type="checkbox" name="confirm_metadata_organizer" value="1" required> ' . e(t('admin.metadata_organizer.confirm_label', 'I reviewed the draft and want to create/reuse these subgalleries and move the matching photos now.')) . '</label>';
    echo '<div class="admin-edit-gallery-savebar"><button type="submit" data-admin-metadata-organizer-apply-button>' . e(t('admin.metadata_organizer.apply_button', 'Apply draft and move photos')) . '</button><span class="muted">' . e(t('admin.metadata_organizer.apply_help', 'The operation uses the same physical move path as the existing bulk image move tool.')) . '</span></div>';
    echo '</form>';
    echo '</section></div>';

}
