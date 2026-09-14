<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_edit_views.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Renders gallery editor side panels, branding fields, bulk controls, and reorder behavior.
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
use function Gallery\Services\feature_capability_effective_enabled;
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
 * Render the gallery-level AI metadata reset control.
 *
 * This panel does not run analysis on the shared host. It only clears existing
 * internal result rows and queue rows for direct images in the gallery. Fresh
 * jobs are created lazily when a Windows worker with the desired model/version
 * polls the gallery API again.
 *
 * @param array<string,mixed> $gallery Gallery currently being edited.
 */
function render_admin_gallery_ai_reprocess_panel(array $gallery): void
{
    $enabled = !function_exists('Gallery\Services\feature_capability_effective_enabled') || feature_capability_effective_enabled('ai_image_metadata');
    $galleryId = (int) ($gallery['id'] ?? 0);
    $schemaReady = function_exists('Gallery\Services\ai_image_analysis_schema_ready') && ai_image_analysis_schema_ready();
    $confirmMessage = t('admin.gallery_editor.ai_reprocess_confirm', 'Forget generated AI metadata for this gallery branch and let the Windows worker process these photos again?');
    \Gallery\Views\view_render_admin_gallery_ai_reprocess_panel([
        'enabled' => $enabled,
        'schema_ready' => $schemaReady,
        'gallery_id' => $galleryId,
        'action_url' => url_for('admin_edit_gallery', ['id' => $galleryId]),
        'csrf_html' => csrf_field(),
        'confirm_message' => $confirmMessage,
        'confirm_json' => (string) json_encode($confirmMessage, JSON_UNESCAPED_UNICODE),
    ]);
}

/**
 * Render the admin image bulk toolbar and guided move workflow.
 *
 * The standard select keeps existing bulk behavior intact. Moving photos uses a
 * staged panel so admins first choose whether the target is an existing gallery
 * or a new child gallery, then confirm the exact physical move.
 *
 * @param array $gallery Gallery row or gallery data.
 */
function render_admin_image_bulk_toolbar(array $gallery): void
{
    $galleryId = (int) $gallery['id'];
    $suggestedDestinationId = function_exists('Gallery\Services\likely_gallery_destination_id') ? likely_gallery_destination_id($galleryId) : 0;
    \Gallery\Views\view_render_admin_image_bulk_toolbar([
        'gallery_id' => $galleryId,
        'thumbnail_url' => url_for('admin_create_thumbnails'),
        'destination_picker_html' => render_gallery_search_picker('destination_gallery_id', 0, $galleryId, [
            'id' => 'admin-image-move-destination-' . $galleryId,
            'placeholder' => t('admin.gallery_editor.search_destination_gallery', 'Search destination gallery'),
            'prefill_gallery_id' => $suggestedDestinationId,
        ]),
        'parent_options_html' => gallery_options_for_select($galleryId),
    ]);
}

/**
 * Render upload, replace, and remove controls for optional gallery branding images.
 *
 * Banner replaces the visible public title text, logo is supplementary, and the
 * separator acts as a visual divider below the public title area.
 *
 * @param array $gallery Gallery row or gallery data.
 */
function render_admin_gallery_branding_fields(array $gallery): void
{
    $schemaReady = gallery_branding_schema_ready();
    $assets = [];
    if ($schemaReady) {
        foreach (gallery_branding_asset_types() as $kind => $definition) {
            $assets[] = [
                'kind' => (string) $kind,
                'label' => (string) $definition['label'],
                'description' => (string) $definition['description'],
                'url' => gallery_branding_asset_url($gallery, (string) $kind, false),
            ];
        }
    }
    \Gallery\Views\view_render_admin_gallery_branding_fields([
        'schema_ready' => $schemaReady,
        'assets' => $assets,
    ]);
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
    \Gallery\Views\view_render_admin_image_reorder_script();
}