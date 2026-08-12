<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_edit_page.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Coordinates and renders the main gallery edit page.
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
 * Handles cms admin edit gallery logic for the gallery application.
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
    if (request_method() === 'GET' && admin_wants_json() && (string) ($_GET['tab'] ?? '') === 'admin-edit-renamer' && function_exists('Gallery\\Controllers\\admin_media_renamer_render_gallery_panel_html')) {
        $pattern = media_renamer_normalize_pattern((string) ($_GET['renamer_pattern'] ?? ''));
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'message' => '',
            'panel_html' => admin_media_renamer_render_gallery_panel_html($gallery, $pattern),
        ]);
        return;
    }

    if (request_method() === 'GET' && admin_wants_json() && (string) ($_GET['action'] ?? '') === 'metadata_organizer_preview_batch') {
        admin_gallery_metadata_organizer_preview_batch_response($gallery);
        return;
    }

    // Variable $pictureGameReady stores this steps working value.

    $pictureGameReady = picture_game_schema_ready() && (!function_exists('Gallery\\Services\\feature_flag_enabled') || (feature_flag_enabled('picture_game') && feature_flag_enabled('image_voting')));
    // Variable $gpsMapReady stores this steps working value.
    $gpsMapReady = exif_gps_schema_ready() && (!function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('gallery_maps'));
    // $gpsMapOverrideReady stores whether GPS display supports inherited per-gallery overrides.
    $gpsMapOverrideReady = $gpsMapReady && exif_gps_override_schema_ready();
    // Variable $flightMapReady stores this steps working value.
    $flightMapReady = flight_map_schema_ready() && (!function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('flight_maps'));
    // Variable $votingReady stores this steps working value.
    $votingReady = gallery_voting_schema_ready() && (!function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('image_voting'));
    // Variable $lightboxModeReady stores this steps working value.
    $lightboxModeReady = gallery_lightbox_browsing_mode_schema_ready() && (!function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('lightbox_modes'));
    // $pictureGameFeatureEnabled stores whether picture-game controls should be surfaced at all.
    $pictureGameFeatureEnabled = !function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('picture_game');
    // $flightMapFeatureEnabled stores whether flight-route map controls should be surfaced at all.
    $flightMapFeatureEnabled = !function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('flight_maps');
    // $lightboxModeFeatureEnabled stores whether lightbox browsing controls should be surfaced at all.
    $lightboxModeFeatureEnabled = !function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('lightbox_modes');
    // $mediaRenamerFeatureEnabled stores whether the file-renamer tab should be visible.
    $mediaRenamerFeatureEnabled = !function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('media_renamer');
    // $uploadApiFeatureEnabled stores whether upload-token tools should be visible.
    $uploadApiFeatureEnabled = !function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('upload_api');
    // $galleryMigrationFeatureEnabled stores whether gallery transfer controls should be visible.
    $galleryMigrationFeatureEnabled = !function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('gallery_migration');
    // Variable $accessReady stores this steps working value.
    $accessReady = gallery_access_schema_ready();
    if (request_method() === 'POST') {
        // Media-renamer AJAX requests need JSON CSRF failures and diagnostics.
        // The normal verifier exits with plain text, which makes fetch() report
        // a confusing non-JSON response and leaves the admin without a log row.
        $isMediaRenamerPost = (string) ($_POST['action'] ?? '') === 'rename_files';
        if ($isMediaRenamerPost && function_exists('Gallery\\Controllers\\admin_media_renamer_verify_csrf_for_ajax')) {
            if (!admin_media_renamer_verify_csrf_for_ajax()) {
                return;
            }
        } else {
            verify_csrf();
        }
        // $returnTab stores the tab fragment used after saving the gallery editor form.
        $returnTab = admin_return_tab_from_post('admin-edit-identity');
        if ((string) ($_POST['action'] ?? '') === 'apply_exif_date_suggestion') {
            admin_apply_gallery_date_exif_suggestion($gallery);
            return;
        }
        if ((string) ($_POST['action'] ?? '') === 'apply_metadata_organizer_date_plan_batch') {
            admin_apply_gallery_metadata_organizer_date_plan_batch($gallery);
            return;
        }
        if ((string) ($_POST['action'] ?? '') === 'apply_metadata_organizer_date_plan') {
            admin_apply_gallery_metadata_organizer_date_plan($gallery, $returnTab);
            return;
        }
        if ((string) ($_POST['action'] ?? '') === 'cover' && isset($_POST['image_ids'])) {
            admin_save_gallery_title_picture($gallery, array_map('intval', $_POST['image_ids'] ?? []), $returnTab);
            return;
        }
        if ((string) ($_POST['action'] ?? '') === 'force_ai_reprocess') {
            if (function_exists('Gallery\\Services\\feature_flag_enabled') && !feature_flag_enabled('ai_image_metadata')) {
                flash_message('admin_notice', t('admin.gallery_editor.ai_reprocess_disabled', 'AI metadata is disabled in Admin > Features.'));
                redirect_to(admin_edit_gallery_tab_url((int) $gallery['id'], 'admin-edit-api'));
            }
            if (!function_exists('Gallery\\Services\\ai_image_analysis_force_gallery_reprocess') || !ai_image_analysis_schema_ready()) {
                flash_message('admin_notice', t('admin.gallery_editor.ai_reprocess_unavailable', 'AI metadata reset will be available after the AI image-analysis migration is applied.'));
                redirect_to(admin_edit_gallery_tab_url((int) $gallery['id'], 'admin-edit-api'));
            }
            $resetResult = ai_image_analysis_force_gallery_reprocess((int) $gallery['id']);
            flash_message('admin_notice', t('admin.gallery_editor.ai_reprocess_queued', 'AI metadata reset for {images} photo(s) across {galleries} gallery node(s). Removed {metadata} metadata row(s), removed {jobs} old queue row(s), and queued {queued} fresh job(s). The next AI worker poll will claim them.', [
                'images' => (int) ($resetResult['images'] ?? 0),
                'galleries' => (int) ($resetResult['galleries'] ?? 0),
                'metadata' => (int) ($resetResult['metadata_deleted'] ?? 0),
                'jobs' => (int) ($resetResult['jobs_deleted'] ?? 0),
                'queued' => (int) ($resetResult['jobs_queued'] ?? 0),
            ]));
            redirect_to(admin_edit_gallery_tab_url((int) $gallery['id'], 'admin-edit-api'));
        }
        if ((string) ($_POST['action'] ?? '') === 'rename_files') {
            if (!$mediaRenamerFeatureEnabled) {
                flash_message('admin_notice', t('admin.media_renamer.feature_disabled', 'Media renamer is disabled in Admin > Features.'));
                redirect_to(admin_edit_gallery_tab_url((int) $gallery['id'], 'admin-edit-media'));
            }
            $renamerPattern = media_renamer_normalize_pattern((string) ($_POST['renamer_pattern'] ?? ''));
            $renamerReturnUrl = admin_edit_gallery_tab_url_with_renamer_pattern((int) $gallery['id'], $renamerPattern);
            $renameResult = null;
            if (empty($_POST['confirm_media_rename'])) {
                $notice = t('admin.media_renamer.confirm_required', 'Confirm that you reviewed the preview before applying physical renames.');
                if (admin_wants_json() && function_exists('Gallery\\Controllers\\admin_media_renamer_render_gallery_panel_html')) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'ok' => false,
                        'error' => $notice,
                        'panel_html' => admin_media_renamer_render_gallery_panel_html($gallery, $renamerPattern, $notice),
                    ]);
                    return;
                }
                flash_message('admin_notice', $notice);
                redirect_to($renamerReturnUrl);
            }
            try {
                $renameResult = media_renamer_execute_gallery((int) $gallery['id'], $renamerPattern);
                if (function_exists('Gallery\\Controllers\\admin_media_renamer_log_event')) {
                    $completionSeverity = function_exists('Gallery\\Controllers\\admin_media_renamer_result_log_severity') ? admin_media_renamer_result_log_severity($renameResult) : 'info';
                    admin_media_renamer_log_event($completionSeverity === 'warning' ? 'warning' : 'info', 'media_renamer.gallery_completed', 'Gallery media rename completed.', [
                        'gallery_id' => (int) $gallery['id'],
                        'gallery_path' => (string) ($gallery['folder_path'] ?? ''),
                        'pattern' => $renamerPattern,
                        'result' => function_exists('Gallery\\Controllers\\admin_media_renamer_loggable_result') ? admin_media_renamer_loggable_result($renameResult) : $renameResult,
                    ], ['category' => 'media', 'severity' => $completionSeverity]);
                }
                $notice = t('admin.media_renamer.gallery_result_notice', 'Renamed {renamed} file(s), invalidated {derivatives} generated derivative cache file(s), saw {missing} missing file(s), skipped {skipped} row(s), updated {titles} derived title(s), and removed {archives} stale ZIP archive row(s).', [
                    'renamed' => (string) (int) ($renameResult['renamed'] ?? 0),
                    'derivatives' => (string) ((int) ($renameResult['derivatives_moved'] ?? 0) + (int) ($renameResult['derivatives_cleaned'] ?? 0)),
                    'missing' => (string) (int) ($renameResult['missing'] ?? 0),
                    'skipped' => (string) ((int) ($renameResult['skipped'] ?? 0) + (int) ($renameResult['collisions'] ?? 0)),
                    'archives' => (string) (int) ($renameResult['zip_archives_deleted'] ?? 0),
                    'titles' => (string) (int) ($renameResult['titles_updated'] ?? 0),
                ]);
                if ((int) ($renameResult['derivative_failures'] ?? 0) > 0) {
                    $notice .= ' ' . t('admin.media_renamer.gallery_derivative_warnings', 'Generated derivative warnings: {count}.', [
                        'count' => (string) (int) ($renameResult['derivative_failures'] ?? 0),
                    ]);
                }
                $renameWarnings = array_slice(array_map('strval', (array) ($renameResult['warnings'] ?? [])), 0, 5);
                if ($renameWarnings) {
                    $notice .= ' ' . t('admin.media_renamer.gallery_warnings', 'Warnings: {warnings}', [
                        'warnings' => implode(' | ', $renameWarnings),
                    ]);
                }
            } catch (Throwable $exception) {
                $notice = $exception->getMessage();
                if (function_exists('Gallery\\Controllers\\admin_media_renamer_log_exception')) {
                    admin_media_renamer_log_exception('media_renamer.gallery_failed', 'Gallery media rename failed.', $exception, [
                        'gallery_id' => (int) $gallery['id'],
                        'gallery_path' => (string) ($gallery['folder_path'] ?? ''),
                        'pattern' => $renamerPattern,
                    ]);
                } elseif (function_exists('Gallery\\Services\\admin_log_event')) {
                    admin_log_event('error', 'media_renamer.gallery_failed', 'Gallery media rename failed.', [
                        'gallery_id' => (int) $gallery['id'],
                        'error' => $exception->getMessage(),
                    ], ['category' => 'media', 'severity' => 'error']);
                }
            }
            $updatedGallery = find_gallery((int) $gallery['id'], true) ?: $gallery;
            if (admin_wants_json() && function_exists('Gallery\\Controllers\\admin_media_renamer_render_gallery_panel_html')) {
                header('Content-Type: application/json');
                echo json_encode([
                    'ok' => $renameResult !== null,
                    'message' => $notice,
                    'panel_html' => admin_media_renamer_render_gallery_panel_html($updatedGallery, $renamerPattern, $notice, $renameResult),
                ]);
                return;
            }
            flash_message('admin_notice', $notice);
            redirect_to($renamerReturnUrl);
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
    if (!$pictureGameReady && $pictureGameFeatureEnabled && (!function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('image_voting'))) {
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
        ['id' => 'admin-edit-api', 'label' => t('admin.gallery_editor.tab_api', 'API')],
        ['id' => 'admin-edit-images', 'label' => t('admin.gallery_editor.tab_images'), 'badge' => $imageCount],
        ['id' => 'admin-edit-organizer', 'label' => t('admin.metadata_organizer.tab_label', 'Organizer')],
    ];
    if ($mediaRenamerFeatureEnabled) {
        $adminTabs[] = ['id' => 'admin-edit-renamer', 'label' => t('admin.media_renamer.tab_label', 'File renamer')];
    }

    view_render_admin_hero([
        'class' => 'admin-edit-gallery-hero',
        'kicker' => t('admin.gallery_editor.kicker'),
        'title' => (string) $gallery['title'],
        'description' => t('admin.gallery_editor.intro'),
        'actions_aria_label' => t('admin.gallery_editor.hero_actions_label'),
        'actions' => [
            [
                'label' => t('admin.gallery_editor.upload_photos_here'),
                'url' => url_for('admin_upload', ['gallery_id' => $gallery['id']]),
                'class' => 'button',
                'attributes' => [
                    'data-gallery-side-panel-link' => true,
                    'data-admin-side-panel-workflow' => 'upload',
                    'data-admin-side-panel-kicker' => t('gallery.upload_workflow'),
                    'data-admin-side-panel-title' => t('gallery.upload_photos'),
                    'data-gallery-side-panel-url' => url_for('admin_upload', ['gallery_id' => $gallery['id'], 'panel' => 1]),
                ],
            ],
            [
                'label' => t('admin.gallery_editor.create_gallery_here'),
                'url' => url_for('admin_upload', ['upload_mode' => 'new', 'parent_id' => $gallery['id']]),
                'class' => 'button secondary',
                'attributes' => [
                    'data-gallery-side-panel-link' => true,
                    'data-admin-side-panel-workflow' => 'upload',
                    'data-admin-side-panel-kicker' => t('gallery.workflow'),
                    'data-admin-side-panel-title' => t('gallery.create_here'),
                    'data-gallery-side-panel-url' => url_for('admin_upload', ['upload_mode' => 'new', 'parent_id' => $gallery['id'], 'panel' => 1]),
                ],
            ],
            ['label' => t('admin.gallery_editor.view_gallery'), 'url' => gallery_public_url($gallery), 'class' => 'button secondary', 'target' => '_blank'],
            ['label' => t('admin.gallery_editor.back_to_galleries'), 'url' => url_for('admin'), 'class' => 'button secondary'],
        ],
        'meta' => [
            ['value' => (string) $imageCount, 'label' => t('admin.gallery_editor.metric_images')],
            ['value' => ucfirst($activeVisibility), 'label' => t('admin.gallery_editor.metric_visibility')],
        ],
    ]);

    view_render_admin_metric_grid([
        [
            'label' => t('admin.gallery_editor.metric_visibility'),
            'value' => ucfirst($activeVisibility),
            'help' => t('admin.gallery_editor.metric_visibility_help'),
            'state' => $activeVisibility === 'public' ? 'ready' : 'care',
        ],
        [
            'label' => t('admin.gallery_editor.metric_images'),
            'value' => (string) $imageCount,
            'help' => t('admin.gallery_editor.metric_images_help'),
            'state' => $imageCount > 0 ? 'ready' : 'neutral',
        ],
        [
            'label' => t('admin.gallery_editor.metric_folder'),
            'value' => gallery_folder_name_from_path((string) $gallery['folder_path']),
            'help' => t('admin.gallery_editor.metric_folder_help'),
            'state' => 'neutral',
        ],
        [
            'label' => t('admin.gallery_editor.metric_parent'),
            'value' => ((int) ($gallery['parent_id'] ?? 0) > 0 ? '#' . (int) $gallery['parent_id'] : t('admin.gallery_editor.root_parent')),
            'help' => t('admin.gallery_editor.metric_parent_help'),
            'state' => 'neutral',
        ],
    ], 'admin-metric-grid admin-edit-gallery-summary', t('admin.gallery_editor.summary_aria', 'Gallery summary'));

    render_admin_tabs($adminTabs, $activeEditTab);

    echo '<form method="post" enctype="multipart/form-data" class="admin-edit-gallery-form" autocomplete="off">' . csrf_field();
    echo '<input type="hidden" name="id" value="' . (int) $gallery['id'] . '">';
    echo '<input type="hidden" name="return_tab" value="admin-edit-identity">';

    ob_start();
    view_render_admin_tab_intro([
        'kicker' => t('admin.gallery_editor.identity_kicker', 'Identity'),
        'title' => t('admin.gallery_editor.names_and_placement', 'Names and placement'),
        'description' => t('admin.gallery_editor.identity_help', 'Controls the public title, URL slug, disk folder, and gallery tree position.'),
    ]);
    echo '<div class="admin-edit-card-grid">';
    echo '<div class="admin-edit-card is-wide"><label>' . e(t('admin.gallery_editor.title', 'Title')) . '<input name="title" value="' . e($gallery['title']) . '" autocomplete="off" required></label>';
    if (function_exists('Gallery\\Views\\view_render_admin_gallery_date_range_fields')) {
        view_render_admin_gallery_date_range_fields($gallery, false);
    } elseif (gallery_date_schema_ready()) {
        echo '<label class="admin-date-picker-field">' . e(t('admin.gallery_editor.gallery_date', 'Date')) . '<input name="gallery_date" type="date" value="' . e(gallery_date_input_value($gallery['gallery_date'] ?? null)) . '"><span class="muted">' . e(t('admin.gallery_editor.gallery_date_help', 'Optional manual gallery date, for example an event, trip, or shooting date.')) . '</span></label>';
    } else {
        echo '<p class="muted">' . e(t('admin.gallery_editor.gallery_date_migration_hidden', 'Gallery date will be available after the database migration is applied.')) . '</p>';
    }
    echo '<label>' . e(t('admin.gallery_editor.description', 'Description')) . '<textarea name="description" data-gallery-description-textarea data-openai-description-textarea>' . e($gallery['description']) . '</textarea></label>';
    render_gallery_description_formatting_hint();
    render_admin_simbrief_description_tool((int) $gallery['id']);
    if ((!function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('openai_text_assist')) && function_exists('Gallery\\Views\\view_render_admin_openai_text_assist_tool')) {
        view_render_admin_openai_text_assist_tool((int) $gallery['id'], 0, 'gallery');
    }
    echo '</div>';
    echo '<div class="admin-edit-card"><label>' . e(t('admin.gallery_editor.slug', 'Slug')) . '<input name="slug" value="' . e($gallery['slug']) . '" autocomplete="off" required><span class="muted">' . e(t('admin.gallery_editor.slug_help', 'Used in the public gallery URL.')) . '</span></label><label>' . e(t('admin.gallery_editor.folder_name', 'Folder name')) . '<input name="folder_name" value="' . e(gallery_folder_name_from_path((string) $gallery['folder_path'])) . '" autocomplete="off" required><span class="muted">' . e(t('admin.gallery_editor.folder_rename_help', 'Changing this renames the folder on disk.')) . '</span></label></div>';
    echo '<div class="admin-edit-card"><label>' . e(t('admin.gallery_editor.parent_gallery', 'Parent gallery')) . '<select name="parent_id"><option value="0">' . e(t('admin.gallery_editor.no_parent', 'No parent')) . '</option>' . gallery_parent_options($gallery) . '</select></label><label>' . e(t('admin.gallery_editor.sort_order', 'Sort order')) . '<input name="sort_order" type="number" value="' . (int) $gallery['sort_order'] . '"></label></div>';
    echo '<div class="admin-edit-card is-wide"><label>' . e(t('admin.gallery_editor.tags', 'Tags')) . '<input name="tags" value="' . e(tag_names_for_entity('gallery', (int) $gallery['id'])) . '" list="tag-suggestions" data-tag-input' . admin_weighted_tag_suggestions_attribute((int) $gallery['id']) . '><span class="muted">' . e(t('admin.gallery_editor.tags_help', 'Separate tags with commas. Suggested tags are ranked by nearby galleries, images, and folder context.')) . '</span></label></div>';
    echo '</div>';
    render_tag_datalist();
    render_admin_tab_panel('admin-edit-identity', (string) ob_get_clean(), $activeEditTab === 'admin-edit-identity');

    ob_start();
    view_render_admin_tab_intro([
        'kicker' => t('admin.gallery_editor.access_kicker', 'Access'),
        'title' => t('admin.gallery_editor.visibility_and_protection', 'Visibility and protection'),
        'description' => t('admin.gallery_editor.access_help', 'Visibility decides discoverability. Passwords and generated links are optional on top of it.'),
    ]);
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
            echo '<label class="checkbox-label"><input type="checkbox" name="clear_access_password" value="1"> ' . e(t('admin.gallery_editor.clear_password', 'Clear current gallery password')) . '</label>';
        }
        echo '</div>';
        echo '<div class="admin-edit-card is-wide"><label>' . e(t('admin.gallery_editor.share_link_expiry', 'Share link expiry')) . '<input name="access_token_expires_at" type="datetime-local" value="' . e(!empty($gallery['access_token_expires_at']) ? date('Y-m-d\TH:i', strtotime((string) $gallery['access_token_expires_at'])) : '') . '"><span class="muted">' . e(t('admin.gallery_editor.non_expiring_link_help', 'Leave empty for a non-expiring generated link.')) . '</span></label>';
        // $visibleShareToken stores an intermediate value used by the surrounding gallery workflow.
        $visibleShareToken = $newShareToken !== '' ? $newShareToken : gallery_share_token_for_admin($gallery);
        if ($visibleShareToken !== null && $visibleShareToken !== '') {
            // $shareLabel stores an intermediate value used by the surrounding gallery workflow.
            $shareLabel = $newShareToken !== '' ? t('admin.gallery_editor.generated_share_link', 'Generated share link') : t('admin.gallery_editor.active_share_link', 'Active share link');
            echo '<label>' . $shareLabel . '<input readonly value="' . e(gallery_share_url((int) $gallery['id'], $visibleShareToken)) . '"></label>';
        } elseif (!empty($gallery['access_token_hash'])) {
            $shareExpiry = !empty($gallery['access_token_expires_at'])
                ? t('admin.gallery_editor.share_link_until', 'until {time}', ['time' => (string) $gallery['access_token_expires_at']])
                : t('admin.gallery_editor.share_link_no_expiry', 'with no expiry');
            echo '<p class="muted">' . e(t('admin.gallery_editor.share_link_hidden_token', 'A share link is active {expiry}, but the original token cannot be displayed because it is stored as hash-only or cannot be decrypted on this server. Regenerate the link once to make a new copyable link visible here.', ['expiry' => $shareExpiry])) . '</p>';
        } else {
            echo '<p class="muted">' . e(t('admin.gallery_editor.no_active_share_link', 'No share link is active.')) . '</p>';
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
    view_render_admin_tab_intro([
        'kicker' => t('admin.gallery_editor.display_kicker', 'Display'),
        'title' => t('admin.gallery_editor.gallery_behavior', 'Gallery behavior'),
        'description' => t('admin.gallery_editor.gallery_behavior_help', 'Feature toggles and grid overrides affecting this gallery branch.'),
    ]);
    echo '<div class="admin-edit-card-grid">';
    if ($pictureGameReady) {
        echo '<div class="admin-edit-card"><label class="checkbox-label"><input type="checkbox" name="picture_game_enabled" value="1"' . ((int) ($gallery['picture_game_enabled'] ?? 0) === 1 ? ' checked' : '') . '> ' . e(t('admin.gallery_editor.enable_picture_game', 'Enable picture game for this gallery branch')) . '</label></div>';
    }
    if ($votingReady) {
        echo '<div class="admin-edit-card"><label class="checkbox-label"><input type="checkbox" name="voting_enabled" value="1"' . ((int) ($gallery['voting_enabled'] ?? 0) === 1 ? ' checked' : '') . '> ' . e(t('admin.gallery_editor.enable_image_voting', 'Enable image voting for this gallery')) . '</label><p class="muted">' . e(t('admin.gallery_editor.image_voting_help', 'When disabled, existing votes remain stored and visible, but vote arrows and vote submissions are blocked.')) . '</p></div>';
    }
    if (gallery_filename_display_schema_ready()) {
        echo '<div class="admin-edit-card"><label class="checkbox-label"><input type="checkbox" name="show_filenames" value="1"' . ((int) ($gallery['show_filenames'] ?? 0) === 1 ? ' checked' : '') . '> ' . e(t('admin.gallery_editor.show_file_names', 'Show file names')) . '</label><p class="muted">' . e(t('admin.gallery_editor.show_file_names_help', 'Disabled by default. Custom photo titles and descriptions are still shown; raw uploaded file names stay hidden unless this is enabled.')) . '</p></div>';
    } else {
        echo '<div class="admin-edit-card"><p class="muted">' . e(t('admin.gallery_editor.filename_display_migration_hidden', 'File name display control will be available after the database migration is applied.')) . '</p></div>';
    }
    if ($flightMapReady) {
        // $flightMapRow stores the existing route-map data shown in the editor.
        $flightMapRow = gallery_flight_map_row((int) $gallery['id']);
        // $flightRouteText stores the raw route text that will be resolved during save.
        $flightRouteText = (string) ($flightMapRow['route_text'] ?? '');
        // $flightPointCount stores how many route points are ready for display.
        $flightPointCount = (int) ($flightMapRow['point_count'] ?? 0);
        // $flightUnresolved stores unresolved diagnostics from the last save.
        $flightUnresolved = $flightMapRow ? gallery_flight_map_unresolved_from_row($flightMapRow) : [];
        echo '<div class="admin-edit-card is-wide"><h3>' . e(t('admin.gallery_editor.flight_route_map', 'Flight route map')) . '</h3>';
        echo '<label>' . e(t('admin.gallery_editor.flight_route_label', 'Route text')) . '<textarea name="flight_route_text" rows="5" placeholder="LKPR DCT OKL DCT EDDF or LKPR@50.1008,14.2632 DCT EDDF@50.0379,8.5622">' . e($flightRouteText) . '</textarea></label>';
        echo '<p class="muted">' . e(t('admin.gallery_editor.flight_route_help', 'For simflying galleries, this gallery stores one resolved route map. The SimBrief generator saves the latest OFP with the gallery and writes OFP coordinates here automatically. Manual routes still support local lookup and NAME@latitude,longitude entries.')) . '</p>';
        echo '<p class="muted">' . e(t('admin.gallery_editor.flight_route_status', 'Resolved points: {points}. Unresolved skipped: {unresolved}.', ['points' => (string) $flightPointCount, 'unresolved' => (string) count($flightUnresolved)])) . '</p>';
        echo '</div>';
    } elseif ($flightMapFeatureEnabled) {
        echo '<div class="admin-edit-card is-wide"><p class="muted">' . e(t('admin.gallery_editor.flight_route_migration_hidden', 'Flight route map controls will be available after the database migration is applied.')) . '</p></div>';
    }
    if ($gpsMapOverrideReady) {
        // $currentGpsMapOverride stores the explicit override saved on this gallery, or null for inherited behavior.
        $currentGpsMapOverride = gallery_gps_map_storage_value($gallery['gps_map_enabled'] ?? null);
        // $currentGpsMapMode stores the selected form value for the gallery override dropdown.
        $currentGpsMapMode = $currentGpsMapOverride === null ? 'inherit' : ($currentGpsMapOverride === 1 ? 'enabled' : 'disabled');
        // $effectiveGpsMapEnabled stores the state visitors currently receive after inheritance.
        $effectiveGpsMapEnabled = gallery_effective_gps_map_enabled($gallery);
        echo '<div class="admin-edit-card"><h3>' . e(t('admin.gallery_editor.exif_gps_display_title', 'EXIF / GPS display')) . '</h3><label>' . e(t('admin.gallery_editor.exif_gps_display_label', 'EXIF / GPS display mode')) . '<select name="gps_map_enabled">';
        echo '<option value="inherit"' . ($currentGpsMapMode === 'inherit' ? ' selected' : '') . '>' . e(t('admin.gallery_editor.exif_gps_inherit', 'Use global default')) . '</option>';
        echo '<option value="enabled"' . ($currentGpsMapMode === 'enabled' ? ' selected' : '') . '>' . e(t('admin.gallery_editor.exif_gps_force_on', 'Force on')) . '</option>';
        echo '<option value="disabled"' . ($currentGpsMapMode === 'disabled' ? ' selected' : '') . '>' . e(t('admin.gallery_editor.exif_gps_force_off', 'Force off')) . '</option>';
        echo '</select></label><p class="muted">' . e(t('admin.gallery_editor.exif_gps_display_help', 'Default is on. Use Force off when this gallery branch should hide photo map pins, gallery EXIF maps, and GPS coordinates from public display. Current effective state: {state}.', ['state' => $effectiveGpsMapEnabled ? t('admin.common.on', 'On') : t('admin.common.off', 'Off')])) . '</p></div>';
    } elseif ($gpsMapReady) {
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
    if ($lightboxModeReady) {
        // $currentLightboxBrowsingMode stores the optional value saved directly on this gallery.
        $currentLightboxBrowsingMode = gallery_lightbox_browsing_mode_storage_value($gallery['lightbox_browsing_mode'] ?? null) ?? 'inherit';
        // $effectiveLightboxBrowsingMode stores the public mode before any form edits.
        $effectiveLightboxBrowsingMode = gallery_effective_lightbox_browsing_mode($gallery);
        echo '<div class="admin-edit-card"><h3>' . e(t('admin.gallery_editor.lightbox_mode_title', 'Lightbox browsing mode')) . '</h3><label>' . e(t('admin.gallery_editor.lightbox_mode_label', 'Gallery lightbox')) . '<select name="lightbox_browsing_mode"><option value="inherit"' . ($currentLightboxBrowsingMode === 'inherit' ? ' selected' : '') . '>' . e(gallery_lightbox_browsing_mode_override_label('inherit')) . '</option>';
        foreach (gallery_lightbox_browsing_mode_options() as $lightboxModeOption) {
            echo '<option value="' . e($lightboxModeOption) . '"' . ($currentLightboxBrowsingMode === $lightboxModeOption ? ' selected' : '') . '>' . e(gallery_lightbox_browsing_mode_label($lightboxModeOption)) . '</option>';
        }
        echo '</select></label><p class="muted">' . e(t('admin.gallery_editor.lightbox_mode_help', 'Current source: {source}. Effective mode: {mode}. Single image keeps the classic viewer, picture strip adds nearby thumbnails below the photo, and 3D carousel places a small set of neighboring photos behind the active image. Fullscreen and slideshow keep their existing behavior.', ['source' => gallery_lightbox_browsing_mode_source_label($gallery), 'mode' => gallery_lightbox_browsing_mode_label($effectiveLightboxBrowsingMode)])) . '</p></div>';
    } elseif ($lightboxModeFeatureEnabled) {
        echo '<div class="admin-edit-card"><p class="muted">' . e(t('admin.gallery_editor.lightbox_mode_migration_hidden', 'Lightbox browsing-mode overrides will be available after the database migration is applied.')) . '</p></div>';
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
    view_render_admin_tab_intro([
        'kicker' => t('admin.gallery_editor.media_kicker', 'Media'),
        'title' => t('admin.gallery_editor.media_title', 'Thumbnail, branding, and background'),
        'description' => t('admin.gallery_editor.media_help', 'Optional visual assets override theme fallbacks only for this gallery.'),
    ]);
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
    $scanImagesActionHtml = '<form method="post" action="' . e(url_for('admin_scan_images')) . '">' . csrf_field() . '<input type="hidden" name="gallery_id" value="' . (int) $gallery['id'] . '"><button type="submit" class="secondary">' . e(t('admin.gallery_editor.scan_import_images', 'Scan/import images')) . '</button></form>';
    view_render_admin_tab_intro([
        'kicker' => t('admin.gallery_editor.tab_images', 'Images'),
        'title' => t('admin.gallery_editor.images_title', 'Photos and ordering'),
        'actions' => [
            [
                'label' => t('admin.gallery_editor.upload_photos_here', 'Upload photos here'),
                'url' => url_for('admin_upload', ['gallery_id' => $gallery['id']]),
                'class' => 'button',
                'attributes' => [
                    'data-gallery-side-panel-link' => true,
                    'data-admin-side-panel-workflow' => 'upload',
                    'data-admin-side-panel-kicker' => t('admin.gallery_editor.upload_workflow', 'Upload workflow'),
                    'data-admin-side-panel-title' => t('admin.gallery_editor.upload_photos', 'Upload photos'),
                    'data-gallery-side-panel-url' => url_for('admin_upload', ['gallery_id' => $gallery['id'], 'panel' => 1]),
                ],
            ],
            [
                'label' => t('admin.duplicate_photos.action_label', 'Find duplicate photos'),
                'url' => url_for('admin_duplicate_photos', ['gallery_id' => $gallery['id']]),
                'class' => 'button secondary',
                'attributes' => [
                    'data-gallery-side-panel-link' => true,
                    'data-admin-side-panel-workflow' => 'duplicate-detector',
                    'data-admin-side-panel-kicker' => t('admin.duplicate_photos.kicker', 'Gallery tools'),
                    'data-admin-side-panel-title' => t('admin.duplicate_photos.page_title', 'Duplicate Photo Detector'),
                    'data-gallery-side-panel-url' => url_for('admin_duplicate_photos', ['gallery_id' => $gallery['id'], 'panel' => 1]),
                ],
            ],
        ],
        'actions_html' => $scanImagesActionHtml,
    ]);
    echo '<form method="post" action="' . e(url_for('admin_bulk_images')) . '" data-admin-image-bulk-form>' . csrf_field();
    echo '<input type="hidden" name="gallery_id" value="' . (int) $gallery['id'] . '">';
    echo '<input type="hidden" name="return_tab" value="admin-edit-images">';
    render_admin_image_bulk_toolbar($gallery);
    echo '<div class="admin-image-order-toolbar" data-admin-image-order-toolbar data-reorder-url="' . e(url_for('admin_reorder_images')) . '"><p class="muted">' . e(t('admin.gallery_editor.drag_photos_help', 'Drag photos by the handle to change their gallery order, or click the Name column header to sort the gallery by filename. Each change is saved immediately.')) . '</p><span class="admin-image-order-status" data-admin-image-order-status aria-live="polite">' . e(t('admin.gallery_editor.order_unchanged', 'Order unchanged.')) . '</span></div>';
    echo '<table class="admin-image-order-table" data-admin-image-order-table><thead><tr><th>' . e(t('admin.gallery_editor.move', 'Move')) . '</th><th>' . e(t('admin.gallery_editor.select', 'Select')) . '</th><th>' . e(t('admin.gallery_editor.preview', 'Preview')) . '</th><th aria-sort="none"><button type="button" class="admin-image-name-sort" data-admin-image-name-sort data-sort-direction="asc" aria-label="' . e(t('admin.gallery_editor.sort_photos_a_z', 'Sort photos by name from A to Z')) . '">' . e(t('admin.gallery_editor.name', 'Name')) . ' <span aria-hidden="true">↕</span></button></th><th title="' . e(t('admin.gallery_editor.file_names_shown', 'File names shown')) . '">N</th><th>' . e(t('admin.gallery_editor.status', 'Status')) . '</th><th>' . e(t('admin.gallery_editor.cover', 'Cover')) . '</th><th>' . e(t('admin.gallery_editor.actions', 'Actions')) . '</th></tr></thead><tbody>';
    foreach ($images as $image) {
        // Variable $isCover stores this steps working value.
        $isCover = (int) ($gallery['cover_image_id'] ?? 0) === (int) $image['id'];
        echo '<tr data-admin-image-order-row data-image-id="' . (int) $image['id'] . '" data-image-name="' . e((string) $image['relative_path']) . '"><td class="admin-image-order-cell"><span class="admin-image-drag-handle" data-admin-image-drag-handle role="button" tabindex="0" aria-label="' . e(t('admin.image_order.move_aria', 'Move {file}', ['file' => (string) $image['relative_path']])) . '" title="' . e(t('admin.image_order.drag_title', 'Drag to reorder')) . '">↕</span></td><td><input type="checkbox" name="image_ids[]" value="' . (int) $image['id'] . '"></td>';
        echo '<td><img class="admin-thumb" decoding="async" loading="lazy" src="' . e(thumbnail_url($image, 300)) . '" alt=""></td>';
        echo '<td data-admin-image-name-cell>' . e($image['relative_path']) . '</td><td>' . render_admin_feature_flag(gallery_shows_filenames($gallery), '✓', t('admin.gallery_editor.file_names_shown_for_gallery', 'File names are shown for this gallery')) . '</td><td>' . e($image['visibility']) . '</td><td data-admin-image-cover-cell>' . ($isCover ? t('admin.gallery_editor.title_picture_current', 'Title picture') : '') . '</td><td><a href="' . e(url_for('admin_edit_image', ['id' => $image['id']])) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="image-edit" data-admin-side-panel-kicker="' . e(t('admin.gallery_editor.photo_editor', 'Photo editor')) . '" data-admin-side-panel-title="' . e(t('admin.gallery_editor.edit_photo', 'Edit photo')) . '" data-gallery-side-panel-url="' . e(url_for('admin_edit_image', ['id' => $image['id'], 'panel' => 1])) . '">' . e(t('admin.gallery_editor.edit', 'Edit')) . '</a> <button type="submit" class="secondary danger inline-admin-action" name="action" value="delete:' . (int) $image['id'] . '" data-admin-image-delete-single data-image-id="' . (int) $image['id'] . '" data-image-name="' . e((string) $image['relative_path']) . '">' . e(t('admin.gallery_editor.delete', 'Delete')) . '</button></td></tr>';
    }
    echo '</tbody></table></form>';
    render_admin_tab_panel('admin-edit-images', (string) ob_get_clean(), $activeEditTab === 'admin-edit-images');

    ob_start();
    render_admin_gallery_metadata_organizer_panel($gallery);
    render_admin_tab_panel('admin-edit-organizer', (string) ob_get_clean(), $activeEditTab === 'admin-edit-organizer');

    if ($mediaRenamerFeatureEnabled) {
        ob_start();
        if (function_exists('Gallery\\Controllers\\render_admin_media_renamer_gallery_panel')) {
            render_admin_media_renamer_gallery_panel($gallery);
        }
        render_admin_tab_panel('admin-edit-renamer', (string) ob_get_clean(), $activeEditTab === 'admin-edit-renamer');
    }

    ob_start();
    view_render_admin_tab_intro([
        'kicker' => t('upload_automation.kicker', 'Automation'),
        'title' => t('admin.upload_automation.gallery_tab_title', 'Upload API keys'),
        'description' => t('admin.upload_automation.gallery_tab_help', 'Generate and revoke the API keys used by the Windows companion app. Keys stay scoped to this gallery, and the global API manager shows every active key across the site.'),
    ]);
    if ($uploadApiFeatureEnabled) {
        render_admin_gallery_upload_automation_panel($gallery, 'admin-edit-api');
    }
    render_admin_gallery_ai_reprocess_panel($gallery);
    if ($galleryMigrationFeatureEnabled) {
        render_admin_gallery_migration_panel($gallery);
    }
    if ($uploadApiFeatureEnabled) {
        echo '<div class="admin-upload-automation-actions"><a class="button secondary" href="' . e(url_for('admin_api_manager')) . '">' . e(t('admin.upload_automation.open_manager', 'Open API manager')) . '</a></div>';
    }
    render_admin_tab_panel('admin-edit-api', (string) ob_get_clean(), $activeEditTab === 'admin-edit-api');
    render_admin_image_reorder_script();
    render_admin_devmode_panel();
    render_footer();
}

