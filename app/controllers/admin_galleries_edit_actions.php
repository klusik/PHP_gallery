<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_edit_actions.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Handles gallery/image save actions, shared edit responses, and gallery mutation input processing.
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

use RuntimeException;
use Throwable;
use const Gallery\Services\CMS_PAGINATION_DEFAULT_COLUMNS;
use const Gallery\Services\CMS_PAGINATION_DEFAULT_ROWS;
use const Gallery\Services\CMS_PAGINATION_MAX_COLUMNS;
use const Gallery\Services\CMS_PAGINATION_MAX_ROWS;
use function Gallery\Core\admin_mutation_descriptor;
use function Gallery\Core\admin_mutation_gallery_is_rendered_in_context;
use function Gallery\Core\admin_mutation_gallery_membership_postcondition;
use function Gallery\Core\admin_mutation_error_envelope;
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
use function Gallery\Services\schema_inspection_is_available;
use function Gallery\Services\content_localization_schema_ready;
use function Gallery\Services\content_save_localizations;
use function Gallery\Services\gallery_share_token_assert_mutation_available;
use function Gallery\Services\gallery_access_schema_is_confirmed_legacy;
use function Gallery\Services\gallery_access_schema_status;
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
use function Gallery\Services\gallery_effective_visibility;
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
use function Gallery\Services\gallery_lightbox_state_summary;
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
use function Gallery\Services\gallery_visibility_label;
use function Gallery\Services\gallery_visibility_storage_value;
use function Gallery\Services\gallery_voting_schema_ready;
use function Gallery\Services\likely_gallery_destination_id;
use function Gallery\Services\link_favicon_gallery_descriptions;
use function Gallery\Services\link_favicon_refresh_gallery;
use function Gallery\Services\media_renamer_default_pattern;
use function Gallery\Services\media_renamer_execute_gallery;
use function Gallery\Services\media_renamer_normalize_pattern;
use function Gallery\Services\move_gallery_folder_to_parent;
use function Gallery\Services\normalize_gallery_visibility;
use function Gallery\Services\nsfw_guard_schema_ready;
use function Gallery\Services\nsfw_guard_schema_status;
use function Gallery\Services\pagination_dimension_value;
use function Gallery\Services\picture_game_schema_ready;
use function Gallery\Services\presentation_lightbox_override_schema_status;
use function Gallery\Services\presentation_flight_map_schema_status;
use function Gallery\Services\presentation_gps_override_schema_status;
use function Gallery\Services\presentation_voting_schema_status;
use function Gallery\Services\presentation_picture_game_schema_status;
use function Gallery\Services\presentation_schema_assert_known;
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
 * Handles cms admin scan images logic for the gallery application.
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
    $message = t('admin.galleries.scan_result', ['count' => $count]);
    if (admin_wants_json()) {
        $contexts = [];
        $normalizedGalleryIds = [];
        foreach ($galleryIds as $galleryId) {
            $normalizedGalleryId = (int) $galleryId;
            $gallery = $normalizedGalleryId > 0 ? find_gallery($normalizedGalleryId, true) : null;
            if (!is_array($gallery)) {
                continue;
            }
            $normalizedGalleryIds[] = $normalizedGalleryId;
            $contexts[] = admin_mutation_public_gallery_context(
                $normalizedGalleryId,
                gallery_public_url($gallery),
                admin_mutation_postcondition('gallery_image_count', [
                    'gallery_id' => $normalizedGalleryId,
                    'count' => gallery_lightbox_total_count($gallery, false, false),
                ])
            );
        }
        $primaryGalleryId = (int) ($normalizedGalleryIds[0] ?? 0);
        $editUrl = $primaryGalleryId > 0
            ? admin_edit_gallery_tab_url($primaryGalleryId, 'admin-edit-images')
            : url_for('admin');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(
            admin_mutation_success_envelope(
                $message,
                admin_mutation_descriptor('image.scan_import', 'image', 'import', $normalizedGalleryIds),
                admin_mutation_panel_metadata('gallery-edit', $editUrl, true),
                $contexts,
                ['redirect_url' => url_for('admin')]
            ),
            ['imported_count' => $count, 'edit_url' => $editUrl]
        ), JSON_THROW_ON_ERROR);
        return;
    }
    flash_message('admin_notice', $message);
    redirect_to(url_for('admin'));
}

/**
 * Normalize an edit-gallery admin tab identifier.
 *
 * @param string $tab Tab value.
 * @return string Text result for the caller.
 */
function admin_edit_gallery_tab_id(string $tab): string
{
    // $allowedTabs stores admin edit tab identifiers that may be returned after POST actions.
    $allowedTabs = ['admin-edit-identity', 'admin-edit-access', 'admin-edit-display', 'admin-edit-media', 'admin-edit-api', 'admin-edit-images', 'admin-edit-organizer', 'admin-edit-renamer'];
    return in_array($tab, $allowedTabs, true) ? $tab : '';
}

/**
 * Return an edit-gallery admin URL with an optional tab fragment.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $tab Tab value.
 * @return string Text result for the caller.
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
 * Build the gallery editor renamer URL while preserving a custom pattern.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $pattern Pattern value.
 * @return string Text result for the caller.
 */
function admin_edit_gallery_tab_url_with_renamer_pattern(int $galleryId, string $pattern): string
{
    $params = ['id' => $galleryId, 'tab' => 'admin-edit-renamer'];
    if ($pattern !== media_renamer_default_pattern()) {
        $params['renamer_pattern'] = $pattern;
    }
    return url_for('admin_edit_gallery', $params) . '#admin-edit-renamer';
}

/**
 * Return the admin edit tab requested by a submitted form.
 *
 * @param string $fallback Fallback value.
 * @return string Text result for the caller.
 */
function admin_return_tab_from_post(string $fallback = ''): string
{
    // $tab stores the submitted tab target used to keep admins in the current workspace after save.
    $tab = admin_edit_gallery_tab_id((string) ($_POST['return_tab'] ?? ''));
    return $tab !== '' ? $tab : admin_edit_gallery_tab_id($fallback);
}

/**
 * Build the JSON payload consumed after a gallery is saved in side-panel mode.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param string $notice Notice value.
 * @param string $returnTab Return tab value.
 * @return array Structured result data for the caller.
 */
function admin_edit_gallery_success_response(array $gallery, string $notice, string $returnTab, array $saveResult = []): array
{
    // $galleryId stores the stable gallery identity used across path and parent changes.
    $galleryId = (int) ($gallery['id'] ?? 0);
    // $originalGallery stores the pre-mutation row so source-parent and route identity are never inferred after save.
    $originalGallery = is_array($saveResult['original_gallery'] ?? null) ? $saveResult['original_gallery'] : $gallery;
    // $oldParentId and $newParentId describe the hierarchy edge before and after persistence.
    $oldParentId = (int) ($saveResult['old_parent_id'] ?? $originalGallery['parent_id'] ?? 0);
    $newParentId = (int) ($gallery['parent_id'] ?? 0);
    // $oldGalleryUrl stores the public route that represented this stable id before the mutation.
    $oldGalleryUrl = (string) ($saveResult['original_gallery_url'] ?? gallery_public_url($originalGallery));
    // $galleryUrl stores the authoritative route after the mutation.
    $galleryUrl = gallery_public_url($gallery);
    // $identityChanged forces a canonical fetch when the currently visible old route can no longer render this gallery.
    $identityChanged = $oldGalleryUrl !== $galleryUrl;
    // $galleryUpdatedAt verifies ordinary metadata saves without trusting stable identity alone.
    $galleryUpdatedAt = trim((string) ($gallery['updated_at'] ?? ''));
    // Visibility changes have a stronger observable invariant than updated_at: the freshly
    // rendered gallery hero must expose the exact persisted effective visibility. Using the
    // timestamp for this case made a successful Published/Unpublished save depend on an
    // indirect database/render timestamp comparison instead of the state the admin changed.
    $originalVisibility = gallery_effective_visibility($originalGallery);
    $galleryVisibility = gallery_effective_visibility($gallery);
    $visibilityChanged = $originalVisibility !== $galleryVisibility;
    $galleryPostcondition = $visibilityChanged
        ? admin_mutation_postcondition('gallery_visibility', [
            'gallery_id' => $galleryId,
            'visibility' => $galleryVisibility,
        ])
        : ($galleryUpdatedAt !== ''
            ? admin_mutation_postcondition('gallery_updated_at', [
                'gallery_id' => $galleryId,
                'updated_at' => $galleryUpdatedAt,
            ])
            : admin_mutation_postcondition('gallery_identity', ['gallery_id' => $galleryId]));
    // $editUrl keeps the active editor tab while the side panel remains mounted.
    $editUrl = admin_edit_gallery_tab_url($galleryId, $returnTab);

    // $contexts stores every public render context whose server-rendered state can have changed.
    $contexts = [
        admin_mutation_public_gallery_context(
            $galleryId,
            $galleryUrl,
            $galleryPostcondition,
            $identityChanged ? 'canonical' : 'preserve_view'
        ),
    ];

    // $oldParent and $newParent are loaded after persistence only for authoritative render URLs.
    $oldParent = $oldParentId > 0 ? find_gallery($oldParentId, true) : null;
    $newParent = $newParentId > 0 ? find_gallery($newParentId, true) : null;
    $oldParentUrl = is_array($oldParent) ? gallery_public_url($oldParent) : url_for('home');
    $newParentUrl = is_array($newParent) ? gallery_public_url($newParent) : url_for('home');

    if ($oldParentId === $newParentId) {
        $galleryRenderedInParent = admin_mutation_gallery_is_rendered_in_context($gallery, $newParentId);
        $contexts[] = admin_mutation_public_gallery_context(
            $newParentId,
            $newParentUrl,
            $galleryRenderedInParent
                ? ($visibilityChanged
                    ? admin_mutation_postcondition('gallery_visibility', [
                        'gallery_id' => $galleryId,
                        'visibility' => $galleryVisibility,
                    ])
                    : ($galleryUpdatedAt !== ''
                        ? admin_mutation_postcondition('gallery_updated_at', [
                            'gallery_id' => $galleryId,
                            'updated_at' => $galleryUpdatedAt,
                        ])
                        : admin_mutation_postcondition('gallery_identity', ['gallery_id' => $galleryId])))
                : admin_mutation_gallery_membership_postcondition($galleryId, $newParentId, false)
        );
    } else {
        $contexts[] = admin_mutation_public_gallery_context(
            $oldParentId,
            $oldParentUrl,
            admin_mutation_gallery_membership_postcondition($galleryId, $oldParentId, false)
        );
        $contexts[] = admin_mutation_public_gallery_context(
            $newParentId,
            $newParentUrl,
            admin_mutation_gallery_membership_postcondition(
                $galleryId,
                $newParentId,
                admin_mutation_gallery_is_rendered_in_context($gallery, $newParentId)
            )
        );
    }

    // $envelope stores the Stage 2 canonical completion result used by the shared coordinator.
    $envelope = admin_mutation_success_envelope(
        $notice,
        admin_mutation_descriptor('gallery.update', 'gallery', 'update', [$galleryId]),
        admin_mutation_panel_metadata('gallery-edit', $editUrl, true),
        $contexts,
        ['redirect_url' => $editUrl]
    );

    // Workflow-specific result fields remain available to the editor UI; completion semantics live only in the canonical envelope.
    return array_merge($envelope, [
        'type' => 'gallery',
        'gallery_id' => $galleryId,
        'gallery_title' => (string) ($gallery['title'] ?? ''),
        'gallery_url' => $galleryUrl,
        'edit_url' => $editUrl,
        'refresh_url' => $galleryUrl,
        'old_gallery_url' => $oldGalleryUrl,
        'identity_changed' => $identityChanged,
        'old_parent_gallery_id' => $oldParentId,
        'old_parent_gallery_url' => $oldParentUrl,
        'parent_gallery_id' => $newParentId,
        'parent_gallery_url' => $newParentUrl,
        'gallery_visibility' => $galleryVisibility,
        'gallery_visibility_changed' => $visibilityChanged,
        'gallery_visibility_label' => gallery_visibility_label($galleryVisibility),
        'gallery_visibility_hint' => $galleryVisibility === 'unpublished'
            ? t('gallery.card.unpublished_admin_hint', 'Only logged-in admins can see this gallery in listings.')
            : '',
    ]);
}

/**
 * Build the JSON payload consumed after a gallery image bulk action runs in side-panel mode.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param string $notice Notice value.
 * @param string $returnTab Return tab value.
 * @param string $action Action value.
 * @param array $imageIds Image ids value.
 * @return array Structured result data for the caller.
 */
function admin_bulk_images_success_response(array $gallery, string $notice, string $returnTab, string $action, array $imageIds = [], array $details = []): array
{
    // $galleryId stores the source gallery for every bulk image mutation.
    $galleryId = (int) ($gallery['id'] ?? 0);
    // $normalizedImageIds stores stable image identities without duplicates or invalid values.
    $normalizedImageIds = [];
    foreach ($imageIds as $imageId) {
        $normalizedImageId = (int) $imageId;
        if ($normalizedImageId > 0 && !in_array($normalizedImageId, $normalizedImageIds, true)) {
            $normalizedImageIds[] = $normalizedImageId;
        }
    }
    // $galleryUrl and $editUrl are authoritative server render sources for public and panel refreshes.
    $galleryUrl = gallery_public_url($gallery);
    $editUrl = admin_edit_gallery_tab_url($galleryId, $returnTab);
    // $contexts stores observable server-rendered postconditions for the affected public galleries.
    $contexts = [];
    // $imageStateRevision verifies image mutations even when the affected card is on another pagination page.
    $imageStateRevision = trim((string) (gallery_lightbox_state_summary($gallery, false, false)['revision'] ?? ''));
    // $mutationType and $mutationAction describe the persisted operation independently from the submitted bulk-action value.
    $mutationType = 'image.bulk_update';
    $mutationAction = 'update';

    if ($action === 'delete') {
        $mutationType = 'image.delete';
        $mutationAction = 'delete';
        $contexts[] = admin_mutation_public_gallery_context(
            $galleryId,
            $galleryUrl,
            admin_mutation_postcondition('gallery_image_count', [
                'gallery_id' => $galleryId,
                'count' => gallery_lightbox_total_count($gallery, false, false),
            ])
        );
    } elseif ($action === 'move_existing' || $action === 'move_new') {
        $mutationType = 'image.move';
        $mutationAction = 'move';
        $contexts[] = admin_mutation_public_gallery_context(
            $galleryId,
            $galleryUrl,
            admin_mutation_postcondition('gallery_image_count', [
                'gallery_id' => $galleryId,
                'count' => gallery_lightbox_total_count($gallery, false, false),
            ])
        );
        $destinationGallery = is_array($details['destination_gallery'] ?? null) ? $details['destination_gallery'] : null;
        if ($destinationGallery !== null) {
            $destinationGalleryId = (int) ($destinationGallery['id'] ?? 0);
            $contexts[] = admin_mutation_public_gallery_context(
                $destinationGalleryId,
                gallery_public_url($destinationGallery),
                admin_mutation_postcondition('gallery_image_count', [
                    'gallery_id' => $destinationGalleryId,
                    'count' => gallery_lightbox_total_count($destinationGallery, false, false),
                ])
            );
            if ($action === 'move_new') {
                $destinationParentId = (int) ($destinationGallery['parent_id'] ?? 0);
                $destinationParent = $destinationParentId > 0 ? find_gallery($destinationParentId, true) : null;
                $contexts[] = admin_mutation_public_gallery_context(
                    $destinationParentId,
                    is_array($destinationParent) ? gallery_public_url($destinationParent) : url_for('home'),
                    admin_mutation_gallery_membership_postcondition(
                        $destinationGalleryId,
                        $destinationParentId,
                        admin_mutation_gallery_is_rendered_in_context($destinationGallery, $destinationParentId)
                    )
                );
            }
        }
    } elseif (in_array($action, ['draft', 'public', 'private'], true)) {
        $mutationType = 'image.visibility';
        $mutationAction = 'visibility';
        $contexts[] = admin_mutation_public_gallery_context(
            $galleryId,
            $galleryUrl,
            admin_mutation_postcondition('image_visibility', [
                'image_ids' => $normalizedImageIds,
                'visibility' => $action,
                'revision' => $imageStateRevision,
            ])
        );
    } elseif (in_array($action, ['nsfw_on', 'nsfw_off'], true)) {
        $mutationType = 'image.nsfw';
        $mutationAction = 'nsfw';
        $contexts[] = admin_mutation_public_gallery_context(
            $galleryId,
            $galleryUrl,
            admin_mutation_postcondition('image_nsfw', [
                'image_ids' => $normalizedImageIds,
                'enabled' => $action === 'nsfw_on',
                'revision' => $imageStateRevision,
            ])
        );
    } elseif ($action === 'cover') {
        $mutationType = 'gallery.cover';
        $mutationAction = 'cover';
        $contexts[] = admin_mutation_public_gallery_context(
            $galleryId,
            $galleryUrl,
            admin_mutation_postcondition('cover_image', ['image_id' => (int) ($gallery['cover_image_id'] ?? 0)])
        );
    } elseif ($action === 'thumbs') {
        $mutationType = 'image.thumbnails';
        $mutationAction = 'generate';
    }

    // $envelope stores the canonical Stage 2 result consumed by the shared completion coordinator.
    $envelope = admin_mutation_success_envelope(
        $notice,
        admin_mutation_descriptor($mutationType, $action === 'cover' ? 'gallery' : 'image', $mutationAction, $action === 'cover' ? [$galleryId] : $normalizedImageIds),
        admin_mutation_panel_metadata('gallery-edit', $editUrl, true),
        $contexts,
        ['redirect_url' => $editUrl]
    );

    // Workflow-specific result fields remain available to the editor UI; completion semantics live only in the canonical envelope.
    return array_merge($envelope, [
        'type' => 'gallery_image_bulk',
        'gallery_id' => $galleryId,
        'gallery_title' => (string) ($gallery['title'] ?? ''),
        'gallery_url' => $galleryUrl,
        'edit_url' => $editUrl,
        'refresh_url' => $galleryUrl,
        'bulk_action' => $action,
        'image_ids' => $normalizedImageIds,
        'cover_image_id' => (int) ($gallery['cover_image_id'] ?? 0),
    ]);
}

/**
 * Persist a gallery title picture from either the bulk image route or a panel-routed edit request.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param array $imageIds Image ids value.
 * @param string $returnTab Return tab value.
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
 *
 * @param array $image Image row or image data.
 * @return array Structured result data for the caller.
 */
function admin_edit_image_success_response(array $image): array
{
    // $gallery stores the image gallery used to rebuild public context URLs after saving.
    $gallery = find_gallery((int) ($image['gallery_id'] ?? 0));
    // $galleryUrl stores the authoritative public render source for this image owner.
    $galleryUrl = $gallery ? gallery_public_url($gallery) : '';
    // $editUrl keeps the mounted side-panel editor on the freshly persisted image.
    $editUrl = url_for('admin_edit_image', ['id' => (int) $image['id'], 'panel' => 1]);
    // $imageStateRevision lets off-page image edits verify against the full gallery state.
    $imageStateRevision = $gallery ? trim((string) (gallery_lightbox_state_summary($gallery, false, false)['revision'] ?? '')) : '';
    // $contexts tells the shared coordinator which public gallery render may now be stale.
    $contexts = $gallery ? [
        admin_mutation_public_gallery_context(
            (int) $gallery['id'],
            $galleryUrl,
            admin_mutation_postcondition('image_visibility', [
                'image_id' => (int) $image['id'],
                'visibility' => (string) ($image['visibility'] ?? ''),
                'revision' => $imageStateRevision,
            ])
        ),
    ] : [];
    // $payload is canonical; the additional top-level fields are workflow-specific editor data, not mutation completion metadata.
    $payload = admin_mutation_success_envelope(
        t('admin.gallery_editor.image_saved'),
        admin_mutation_descriptor('image.update', 'image', 'update', [(int) $image['id']]),
        admin_mutation_panel_metadata('image-edit', $editUrl, true),
        $contexts,
        ['redirect_url' => url_for('admin_edit_image', ['id' => (int) $image['id'], 'saved' => 1])]
    );
    return array_merge($payload, [
        'type' => 'image',
        'image_id' => (int) $image['id'],
        'gallery_id' => (int) ($image['gallery_id'] ?? 0),
        'image_title' => (string) ($image['title'] ?? ''),
        'image_description' => (string) ($image['description'] ?? ''),
        'image_visibility' => (string) ($image['visibility'] ?? ''),
        'image_sort_order' => (int) ($image['sort_order'] ?? 0),
        'image_url' => $gallery ? image_public_url($image, $gallery) : '',
        'gallery_url' => $galleryUrl,
        'edit_url' => $editUrl,
    ]);
}

/**
 * Sends a JSON error response for side-panel save failures.
 *
 * @param string $message Message value.
 * @param int $statusCode Status code value.
 */
function admin_panel_error_response(string $message, int $statusCode = 422): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode(admin_mutation_error_envelope($message, 'admin_mutation_failed'));
}



/**
 * Render the SimBrief draft generator for the existing description textarea.
 *
 * @param int $galleryId Gallery edited by the current form.
 */
function render_admin_simbrief_description_tool(int $galleryId): void
{
    if (function_exists('Gallery\\Services\\feature_flag_enabled') && !feature_flag_enabled('simbrief')) {
        return;
    }
    if (function_exists('Gallery\\Views\\view_render_admin_simbrief_description_tool')) {
        view_render_admin_simbrief_description_tool($galleryId);
        return;
    }
    echo '<div class="admin-simbrief-description" data-simbrief-description-tool data-simbrief-endpoint="' . e(url_for('admin_simbrief_description')) . '" data-gallery-id="' . (int) $galleryId . '">';
    echo '<div class="admin-simbrief-description-heading"><div><h3>' . e(t('admin.simbrief.title', 'Generate from SimBrief')) . '</h3><p class="muted">' . e(t('admin.simbrief.help', 'Fetch the latest SimBrief OFP, save it with this gallery, generate an editable description draft, and update the flight route map from OFP coordinates.')) . '</p></div></div>';
    echo '<div class="admin-simbrief-description-grid">';
    echo '<label>' . e(t('admin.simbrief.pilot_id', 'SimBrief Pilot ID')) . '<input name="simbrief_pilot_id" autocomplete="off" inputmode="text" data-simbrief-pilot-id><span class="muted">' . e(t('admin.simbrief.pilot_id_help', 'Pilot ID = the numeric or account identifier used by SimBrief. If both fields are filled, Pilot ID is used first.')) . '</span></label>';
    echo '<label>' . e(t('admin.simbrief.pilot_name', 'SimBrief pilot name')) . '<input name="simbrief_pilot_name" autocomplete="off" data-simbrief-pilot-name><span class="muted">' . e(t('admin.simbrief.pilot_name_help', 'Pilot name = the SimBrief pilot name exactly as it appears in the SimBrief profile.')) . '</span></label>';
    echo '</div>';
    echo '<div class="admin-simbrief-description-actions"><button type="button" class="button secondary" data-simbrief-generate>' . e(t('admin.simbrief.generate_button', 'Generate description and route map')) . '</button><span class="muted" data-simbrief-status role="status" aria-live="polite"></span></div>';
    echo '<p class="muted" data-simbrief-route-status role="status" aria-live="polite" hidden></p>';
    echo '</div>';
}

/**
 * Return true when a partial gallery save contains any value from a field group.
 *
 * @param array $input Input value.
 * @param array $keys Keys value.
 * @return bool True when the condition matches.
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
 *
 * @param array $input Input value.
 * @param string $key Lookup key.
 * @param bool $defaultWhenMissing Default when missing value.
 * @return int Integer result for the caller.
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
 *
 * @param array $gallery Gallery row or gallery data.
 * @param array $input Input value.
 * @param array $files Files value.
 * @param string $returnTab Return tab value.
 * @param bool $completeForm Complete form value.
 * @return array Structured result data for the caller.
 */
function admin_save_gallery_from_input(array $gallery, array $input, array $files, string $returnTab, bool $completeForm = true): array
{
    $shouldUpdateLocalization = array_key_exists('content_language', $input) || array_key_exists('translations', $input);
    if ($shouldUpdateLocalization && !content_localization_schema_ready('gallery')) {
        throw new RuntimeException(t('admin.content_localization.save_unavailable', 'Multilingual content was not saved because its database migration is unavailable.'));
    }
    if (!empty($input['nsfw_field_present']) && !nsfw_guard_schema_ready()) {
        throw new RuntimeException(admin_nsfw_guard_mutation_error());
    }
    // Stale admin forms must not turn a Phase 11 inspection outage into an implicit
    // field omission. Only fields that were actually submitted are preflighted so
    // unrelated gallery edits can continue when an optional presentation feature
    // is not part of the request.
    if (array_key_exists('picture_game_enabled', $input)) {
        presentation_schema_assert_known(presentation_picture_game_schema_status(), 'gallery_picture_game_setting_save');
    }
    if (array_key_exists('voting_enabled', $input)) {
        presentation_schema_assert_known(presentation_voting_schema_status(), 'gallery_voting_setting_save');
    }
    if (array_key_exists('gps_map_enabled', $input)) {
        presentation_schema_assert_known(presentation_gps_override_schema_status(), 'gallery_gps_override_save');
    }
    if (array_key_exists('lightbox_browsing_mode', $input)) {
        presentation_schema_assert_known(presentation_lightbox_override_schema_status(), 'gallery_lightbox_override_save');
    }
    if (array_key_exists('flight_route_text', $input)) {
        presentation_schema_assert_known(presentation_flight_map_schema_status(), 'gallery_flight_map_setting_save');
    }
    // $pictureGameReady stores this steps working value.
    $pictureGameReady = picture_game_schema_ready() && (!function_exists('Gallery\\Services\\feature_flag_enabled') || (feature_flag_enabled('picture_game') && feature_flag_enabled('image_voting')));
    // $gpsMapReady stores this steps working value.
    $gpsMapReady = exif_gps_schema_ready() && (!function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('gallery_maps'));
    // $gpsMapOverrideReady stores whether GPS display supports inherited per-gallery overrides.
    $gpsMapOverrideReady = $gpsMapReady && exif_gps_override_schema_ready();
    // $flightMapReady stores this steps working value.
    $flightMapReady = flight_map_schema_ready() && (!function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('flight_maps'));
    // $votingReady stores this steps working value.
    $votingReady = gallery_voting_schema_ready() && (!function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('image_voting'));
    // $lightboxModeReady stores this steps working value.
    $lightboxModeReady = gallery_lightbox_browsing_mode_schema_ready() && (!function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('lightbox_modes'));
    // Structured gallery-access state prevents partial/unknown migrations from becoming permissive saves.
    $accessSchemaStatus = gallery_access_schema_status();
    $accessReady = schema_inspection_is_available($accessSchemaStatus);
    $accessLegacy = gallery_access_schema_is_confirmed_legacy($accessSchemaStatus);
    // $galleryId stores the gallery being edited.
    $galleryId = (int) ($gallery['id'] ?? 0);
    // $originalGallery preserves stable pre-mutation placement and route metadata for the completion contract.
    $originalGallery = $gallery;
    // $originalGalleryUrl stores the route that represented this gallery before any folder/slug mutation.
    $originalGalleryUrl = gallery_public_url($gallery);
    // $oldParentId stores the hierarchy source before a possible reparent operation.
    $oldParentId = (int) ($gallery['parent_id'] ?? 0);
    // $faviconDescriptionsBeforeSave snapshots only persisted description content. Remote favicon discovery must not run for unrelated edits such as visibility changes.
    $faviconDescriptionsBeforeSave = link_favicon_gallery_descriptions($galleryId);
    sort($faviconDescriptionsBeforeSave, SORT_STRING);
    // $title stores the submitted or preserved public gallery title.
    $title = trim((string) ($input['title'] ?? $gallery['title'] ?? ''));
    if ($title === '') {
        $title = (string) ($gallery['title'] ?? '');
    }
    // $slug stores the submitted or preserved public slug.
    $slug = trim((string) ($input['slug'] ?? $gallery['slug'] ?? $title));
    // $visibility stores the normalized gallery visibility value.
    $visibility = gallery_visibility_storage_value((string) ($input['visibility'] ?? $gallery['visibility'] ?? 'unpublished'));
    // $galleryDateRange stores the optional manual date range selected by an admin.
    $galleryDateRange = gallery_date_schema_ready()
        ? gallery_date_range_storage_values(
            $input['gallery_date'] ?? ($gallery['gallery_date'] ?? ''),
            $input['gallery_date_end'] ?? ($gallery['gallery_date_end'] ?? '')
        )
        : ['start' => null, 'end' => null];
    // $galleryDate stores the optional manual date or range start selected by an admin.
    $galleryDate = $galleryDateRange['start'];
    // $galleryDateEnd stores the optional manual range end selected by an admin.
    $galleryDateEnd = $galleryDateRange['end'];
    // $pictureGameDefault stores the current value for partial update preservation.
    $pictureGameDefault = !$completeForm && (int) ($gallery['picture_game_enabled'] ?? 0) === 1;
    // $gpsMapDefault stores the current value for partial update preservation.
    $gpsMapDefault = !$completeForm && (int) ($gallery['gps_map_enabled'] ?? 0) === 1;
    // $gpsMapOverride stores the inherited or explicit EXIF/GPS display state for nullable override installs.
    $gpsMapOverride = $gpsMapOverrideReady ? gallery_gps_map_storage_value($input['gps_map_enabled'] ?? ($completeForm ? 'inherit' : ($gallery['gps_map_enabled'] ?? null))) : null;
    // $votingDefault stores the current value for partial update preservation.
    $votingDefault = !$completeForm && (int) ($gallery['voting_enabled'] ?? 0) === 1;
    // $showFilenamesDefault stores the current value for partial update preservation.
    $showFilenamesDefault = !$completeForm && (int) ($gallery['show_filenames'] ?? 0) === 1;
    // $nsfwDefault stores the current value for partial update preservation.
    $nsfwDefault = !$completeForm && (int) ($gallery['nsfw_enabled'] ?? 0) === 1;
    // Variable $pictureGameEnabled stores this steps working value.
    $pictureGameEnabled = $pictureGameReady ? admin_gallery_checkbox_input($input, 'picture_game_enabled', $pictureGameDefault) : 0;
    // Variable $gpsMapEnabled stores this steps working value.
    $gpsMapEnabled = $gpsMapReady && !$gpsMapOverrideReady ? admin_gallery_checkbox_input($input, 'gps_map_enabled', $gpsMapDefault) : 0;
    // Variable $votingEnabled stores this steps working value.
    $votingEnabled = $votingReady ? admin_gallery_checkbox_input($input, 'voting_enabled', $votingDefault) : (int) ($gallery['voting_enabled'] ?? 0);
    // Variable $showFilenames stores this steps working value.
    $showFilenames = gallery_filename_display_schema_ready() ? admin_gallery_checkbox_input($input, 'show_filenames', $showFilenamesDefault) : 0;
    // $countBadgeVisibility stores the optional gallery-card count badge override for this gallery.
    $countBadgeVisibility = gallery_count_badge_schema_ready() ? gallery_count_badge_storage_value($input['count_badge_visibility'] ?? ($gallery['count_badge_visibility'] ?? 'inherit')) : null;
    // $lightboxBrowsingMode stores the optional gallery-level lightbox browsing-mode override.
    $lightboxBrowsingMode = $lightboxModeReady ? gallery_lightbox_browsing_mode_storage_value($input['lightbox_browsing_mode'] ?? ($completeForm ? 'inherit' : ($gallery['lightbox_browsing_mode'] ?? 'inherit'))) : null;
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
    // $shouldUpdateFlightMap stores whether this request owns route-map input.
    $shouldUpdateFlightMap = $flightMapReady && ($completeForm || array_key_exists('flight_route_text', $input));
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
    $accessInputPresent = admin_gallery_input_has_any_key($input, ['access_action', 'access_type', 'clear_access_password', 'access_password', 'access_token_expires_at']);
    $shouldUpdateAccess = $accessInputPresent || ($completeForm && $accessReady);
    if (!$accessReady && !$accessLegacy && ($completeForm || $accessInputPresent)) {
        throw new RuntimeException(t('admin.gallery_editor.access_schema_save_refused', 'Gallery save was refused because password/access schema is incomplete or could not be inspected. Check System Health before changing gallery visibility or protection.'));
    }
    // $accessAction stores an intermediate value used by the surrounding gallery workflow.
    $accessAction = $accessReady ? (string) ($input['access_action'] ?? 'save') : 'save';
    if ($accessReady && $shouldUpdateAccess && $accessAction === 'generate_link') {
        // Refuse before the gallery UPDATE so a missing/unknown token column cannot
        // leave the rest of the form saved while link generation fails afterwards.
        gallery_share_token_assert_mutation_available();
    }
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
    if (gallery_date_range_schema_ready()) {
        $fields['gallery_date_end = ?'] = $galleryDateEnd;
    }
    if ($pictureGameReady) {
        $fields['picture_game_enabled = ?'] = $pictureGameEnabled;
    }
    if ($gpsMapOverrideReady) {
        $fields['gps_map_enabled = ?'] = $gpsMapOverride;
    } elseif ($gpsMapReady) {
        $fields['gps_map_enabled = ?'] = $gpsMapEnabled;
    }
    if ($votingReady) {
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
    if ($lightboxModeReady) {
        $fields['lightbox_browsing_mode = ?'] = $lightboxBrowsingMode;
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
    if ($shouldUpdateLocalization) {
        content_save_localizations('gallery', $galleryId, $input['content_language'] ?? null, $input['translations'] ?? []);
    }
    if (public_path_schema_ready()) {
        refresh_gallery_public_paths();
    }
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
    // $flightMapResult stores the route resolver summary for the saved gallery.
    $flightMapResult = null;
    if ($shouldUpdateFlightMap) {
        $flightMapResult = save_gallery_flight_path_route($galleryId, (string) ($input['flight_route_text'] ?? ''));
    }
    if ($completeForm || array_key_exists('tags', $input)) {
        sync_entity_tags('gallery', $galleryId, (string) ($input['tags'] ?? ''));
    }
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery($galleryId, true) ?: $gallery;
    if ($gallery) {
        write_gallery_sidecar($gallery);
    }
    // Compare persisted descriptions before starting any outbound favicon work. A full
    // gallery form contains many fields even when the admin changed only visibility,
    // so an unconditional favicon refresh would put unrelated remote HTTP latency on
    // the critical save-response path after the database mutation had already succeeded.
    $faviconDescriptionsAfterSave = link_favicon_gallery_descriptions($galleryId);
    sort($faviconDescriptionsAfterSave, SORT_STRING);
    if ($faviconDescriptionsBeforeSave !== $faviconDescriptionsAfterSave) {
        // Cache unknown-site favicons only when link-bearing content actually changed.
        // This best-effort cosmetic fetch must never make the gallery mutation fail.
        try {
            link_favicon_refresh_gallery($galleryId);
        } catch (Throwable) {
        }
    }
    // $notice stores an intermediate value used by the surrounding gallery workflow.
    $notice = t('admin.gallery_editor.notice_saved', 'Gallery saved.');
    if ($flightMapResult !== null && (int) ($flightMapResult['point_count'] ?? 0) > 0) {
        $notice .= ' ' . t('admin.gallery_editor.flight_route_saved_notice', 'Flight route saved with {points} resolved points; {unresolved} unresolved points were skipped.', [
            'points' => (string) (int) ($flightMapResult['point_count'] ?? 0),
            'unresolved' => (string) (int) ($flightMapResult['unresolved_count'] ?? 0),
        ]);
    }
    if (!empty($moveResult['moved'])) {
        // $notice stores an intermediate value used by the surrounding gallery workflow.
        $notice = t('admin.gallery_editor.notice_saved_and_moved', 'Gallery saved and folder moved.');
    }
    return [
        'gallery' => $gallery,
        'notice' => $notice,
        'return_tab' => $returnTab,
        'moved' => !empty($moveResult['moved']),
        'original_gallery' => $originalGallery,
        'original_gallery_url' => $originalGalleryUrl,
        'old_parent_id' => $oldParentId,
    ];
}

/**
 * Return a safe Admin message explaining why an NSFW mutation was refused.
 *
 * The wording distinguishes a confirmed migration requirement from an
 * operational schema-inspection failure without exposing database internals.
 *
 * @return string Translated refusal message.
 */
function admin_nsfw_guard_mutation_error(): string
{
    // $status stores the complete NSFW schema state used for actionable wording.
    $status = nsfw_guard_schema_status();
    return ($status['state'] ?? '') === 'unknown'
        ? t('admin.gallery_editor.nsfw_change_inspection_failed', 'NSFW Guard was not changed because the required database schema could not be inspected. Check System Health and try again.')
        : t('admin.gallery_editor.nsfw_change_migration_required', 'NSFW Guard was not changed because its database migration has not been applied.');
}
