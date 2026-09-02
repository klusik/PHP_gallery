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
    if (function_exists('Gallery\\Services\\feature_flag_enabled') && !feature_flag_enabled('ai_image_metadata')) {
        return;
    }
    $galleryId = (int) ($gallery['id'] ?? 0);
    echo '<div class="admin-edit-card is-wide admin-ai-reprocess-panel">';
    echo '<h3>' . e(t('admin.gallery_editor.ai_reprocess_title', 'AI metadata regeneration')) . '</h3>';
    echo '<p class="muted">' . e(t('admin.gallery_editor.ai_reprocess_help', 'Use this when photos were already processed with an older local analyzer and you want the Windows worker to generate fresh internal search metadata for this gallery. This resets queue/result rows on the server and immediately creates fresh queue jobs for the same model generation where possible. The heavy analysis still runs on the Windows app.')) . '</p>';

    if (!function_exists('Gallery\\Services\\ai_image_analysis_schema_ready') || !ai_image_analysis_schema_ready()) {
        echo '<p class="muted">' . e(t('admin.gallery_editor.ai_reprocess_migration_hidden', 'AI metadata regeneration will be available after the AI image-analysis migration is applied.')) . '</p>';
        echo '</div>';
        return;
    }

    $confirmMessage = t('admin.gallery_editor.ai_reprocess_confirm', 'Forget generated AI metadata for this gallery branch and let the Windows worker process these photos again?');
    echo '<form method="post" action="' . e(url_for('admin_edit_gallery', ['id' => $galleryId])) . '" class="admin-inline-form" data-admin-panel-ai-reprocess-form data-confirm="' . e($confirmMessage) . '" onsubmit="return confirm(' . e(json_encode($confirmMessage, JSON_UNESCAPED_UNICODE)) . ');">' . csrf_field();
    echo '<input type="hidden" name="id" value="' . $galleryId . '">';
    echo '<input type="hidden" name="return_tab" value="admin-edit-api">';
    echo '<button type="submit" name="action" value="force_ai_reprocess" class="secondary danger">' . e(t('admin.gallery_editor.ai_reprocess_button', 'Force AI metadata regeneration')) . '</button>';
    echo '<span class="muted">' . e(t('admin.gallery_editor.ai_reprocess_note', 'After pressing this, keep or start the AI metadata worker with the backend and model version you want to use.')) . '</span>';
    echo '</form>';
    echo '</div>';
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
    // $galleryId stores the gallery currently being edited.
    $galleryId = (int) $gallery['id'];
    // $suggestedDestinationId stores a likely child gallery destination for the shared searchable picker.
    $suggestedDestinationId = function_exists('Gallery\\Services\\likely_gallery_destination_id') ? likely_gallery_destination_id($galleryId) : 0;
    // $newGalleryParentOptions stores the selectable parent hierarchy for move-to-new-gallery actions.
    $newGalleryParentOptions = gallery_options_for_select($galleryId);

    echo '<div class="bulk-row admin-edit-image-toolbar" data-admin-image-move-toolbar>';
    echo '<div class="admin-image-bulk-primary">';
    echo '<label class="admin-image-select-all"><input type="checkbox" data-select-all="image_ids[]"> ' . e(t('admin.gallery_editor.select_all_images')) . '</label>';
    echo '<span class="admin-image-selection-count" data-admin-image-selected-count>' . e(t('admin.gallery_editor.selected_count_zero')) . '</span>';
    echo '<label>' . e(t('admin.gallery_editor.bulk_action')) . '<select name="action" data-admin-image-bulk-action><option value="public">' . e(t('admin.gallery_editor.set_public')) . '</option><option value="draft">' . e(t('admin.gallery_editor.set_draft')) . '</option><option value="private">' . e(t('admin.gallery_editor.set_private')) . '</option><option value="cover">' . e(t('admin.gallery_editor.set_as_title_picture')) . '</option><option value="thumbs">' . e(t('admin.gallery_editor.create_thumbnails')) . '</option><option value="nsfw_on">' . e(t('admin.gallery_editor.mark_nsfw')) . '</option><option value="nsfw_off">' . e(t('admin.gallery_editor.remove_nsfw')) . '</option><option value="delete">' . e(t('admin.gallery_editor.delete_selected_photos')) . '</option><option value="move_existing" hidden>' . e(t('admin.gallery_editor.move_existing')) . '</option><option value="move_new" hidden>' . e(t('admin.gallery_editor.move_new')) . '</option></select></label>';
    echo '<button type="submit">' . e(t('admin.gallery_editor.apply_to_selected')) . '</button>';
    echo '<button type="button" class="secondary" data-admin-image-move-open>' . e(t('admin.gallery_editor.move_selected_photos')) . '</button>';
    echo '<button type="submit" class="secondary" name="thumbnail_gallery_id" value="' . $galleryId . '" formaction="' . e(url_for('admin_create_thumbnails')) . '">' . e(t('admin.gallery_editor.create_gallery_thumbnails')) . '</button>';
    echo '</div>';

    echo '<section class="admin-image-move-panel" data-admin-image-move-panel hidden aria-label="' . e(t('admin.gallery_editor.move_selected_photos')) . '">';
    echo '<div class="admin-image-move-panel-head"><div class="admin-image-move-title"><span class="admin-image-move-title-icon" aria-hidden="true">⇄</span><div><h3>' . e(t('admin.gallery_editor.move_selected_photos')) . '</h3><span class="admin-image-move-count-pill" data-admin-image-selected-count>' . e(t('admin.gallery_editor.selected_count_zero')) . '</span></div></div><button type="button" class="admin-image-move-close" data-admin-image-move-cancel aria-label="' . e(t('admin.gallery_editor.close_move_panel')) . '">×</button></div>';
    echo '<div class="admin-image-move-steps" aria-label="' . e(t('admin.gallery_editor.move_progress')) . '">';
    echo '<div class="admin-image-move-step is-active" data-admin-image-move-step="action"><span>1</span><div><strong>' . e(t('admin.gallery_editor.step_choose_action')) . '</strong><p>' . e(t('admin.gallery_editor.step_choose_action_help')) . '</p></div></div>';
    echo '<div class="admin-image-move-step" data-admin-image-move-step="target"><span>2</span><div><strong>' . e(t('admin.gallery_editor.step_target')) . '</strong><p>' . e(t('admin.gallery_editor.step_target_help')) . '</p></div></div>';
    echo '<div class="admin-image-move-step" data-admin-image-move-step="confirm"><span>3</span><div><strong>' . e(t('admin.gallery_editor.step_confirm')) . '</strong><p>' . e(t('admin.gallery_editor.step_confirm_help')) . '</p></div></div>';
    echo '<div class="admin-image-move-step" data-admin-image-move-step="complete"><span>4</span><div><strong>' . e(t('admin.gallery_editor.step_complete')) . '</strong><p>' . e(t('admin.gallery_editor.step_complete_help')) . '</p></div></div>';
    echo '</div>';
    echo '<p class="admin-image-move-lead">' . e(t('admin.gallery_editor.move_lead')) . '</p>';
    echo '<div class="admin-image-move-choice-grid" role="group" aria-label="' . e(t('admin.gallery_editor.move_action')) . '">';
    echo '<button type="button" class="admin-image-move-choice" data-admin-image-move-choice="move_existing" aria-pressed="false"><span class="admin-image-move-choice-icon" aria-hidden="true">▭</span><span class="admin-image-move-choice-copy"><strong>' . e(t('admin.gallery_editor.move_existing')) . '</strong><small>' . e(t('admin.gallery_editor.move_existing_help')) . '</small></span><span class="admin-image-move-choice-radio" aria-hidden="true"></span></button>';
    echo '<button type="button" class="admin-image-move-choice" data-admin-image-move-choice="move_new" aria-pressed="false"><span class="admin-image-move-choice-icon" aria-hidden="true">▭+</span><span class="admin-image-move-choice-copy"><strong>' . e(t('admin.gallery_editor.move_new')) . '</strong><small>' . e(t('admin.gallery_editor.move_new_help')) . '</small></span><span class="admin-image-move-choice-radio" aria-hidden="true"></span></button>';
    echo '</div>';
    echo '<div class="admin-image-move-targets">';
    echo '<label class="admin-image-move-target" data-admin-image-move-existing hidden><span>' . e(t('admin.gallery_editor.destination_gallery')) . '</span>' . render_gallery_search_picker('destination_gallery_id', 0, $galleryId, [
        'id' => 'admin-image-move-destination-' . $galleryId,
        'placeholder' => t('admin.gallery_editor.search_destination_gallery', 'Search destination gallery'),
        'prefill_gallery_id' => $suggestedDestinationId,
    ]) . '<small><span aria-hidden="true">ⓘ</span> ' . e(t('admin.gallery_editor.destination_help')) . '</small></label>';
    echo '<div class="admin-image-move-target admin-image-move-new" data-admin-image-move-new hidden><label><span>' . e(t('admin.gallery_editor.parent_gallery')) . '</span><select name="new_gallery_parent_id"><option value="0">' . e(t('admin.gallery_editor.no_parent')) . '</option>' . $newGalleryParentOptions . '</select></label><label><span>' . e(t('admin.gallery_editor.new_gallery_title')) . '</span><input type="text" name="new_gallery_title" placeholder="' . e(t('admin.gallery_editor.example_gallery_title')) . '"></label><label><span>' . e(t('admin.gallery_editor.optional_folder_slug')) . '</span><input type="text" name="new_gallery_folder_name" placeholder="' . e(t('admin.gallery_editor.derive_from_title')) . '"></label><small><span aria-hidden="true">ⓘ</span> ' . e(t('admin.gallery_editor.new_gallery_move_help')) . '</small></div>';
    echo '</div>';
    echo '<div class="admin-image-move-confirm"><button type="button" class="secondary admin-image-move-cancel-bottom" data-admin-image-move-cancel>' . e(t('admin.gallery_editor.cancel')) . '</button><div><strong>' . e(t('admin.gallery_editor.move_summary')) . '</strong><p data-admin-image-move-summary>' . e(t('admin.gallery_editor.move_summary_empty')) . '</p></div><button type="submit" name="move_images" value="1" data-admin-image-move-submit disabled>' . e(t('admin.gallery_editor.move_selected_now')) . '</button></div>';
    echo '</section>';
    echo '</div>';
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
    if (!gallery_branding_schema_ready()) {
        echo '<p class="muted">' . e(t('admin.gallery_editor.branding_migration_required', 'Gallery branding assets will be available after the branding migration is applied.')) . '</p>';
        return;
    }

    echo '<fieldset class="form-grid admin-branding-assets"><legend>' . e(t('admin.gallery_editor.gallery_branding', 'Gallery branding')) . '</legend>';
    echo '<p class="muted">' . e(t('admin.gallery_editor.branding_optional_help', 'All branding images are optional. Existing galleries render exactly as before until one of these assets is uploaded.')) . '</p>';
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
            echo '<div class="admin-branding-current"><img class="admin-branding-preview admin-branding-preview-' . e((string) $kind) . '" src="' . e($assetUrl) . '" alt=""><label class="checkbox-label"><input type="checkbox" name="remove_branding_' . e((string) $kind) . '" value="1"> ' . e(t('admin.gallery_editor.branding_remove_current', 'Remove current {asset}', ['asset' => strtolower($label)])) . '</label></div>';
        } else {
            echo '<p class="muted">' . e(t('admin.gallery_editor.branding_not_configured', 'No {asset} is configured.', ['asset' => strtolower($label)])) . '</p>';
        }
        echo '<label>' . e(t('admin.gallery_editor.branding_upload_replace', 'Upload or replace {asset}', ['asset' => strtolower($label)])) . '<input type="file" name="branding_' . e((string) $kind) . '_upload" accept="image/jpeg,image/png,image/gif,image/webp"><span class="muted">' . e(t('admin.gallery_editor.branding_formats_help', 'Accepted formats: JPG, PNG, GIF, WebP. Maximum size: 8 MB.')) . '</span></label>';
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
     * @return {HTMLTableElement|null} Reorder table, or null on other pages.
     */
    function findImageOrderTable() {
        return document.querySelector('[data-admin-image-order-table]');
    }

    /**
     * Updates the visible reorder status text without throwing on older markup.
     *
     * @param {string} message Message displayed to the gallery administrator.
     * @param {string} state Small state token used by CSS for color feedback.
     * @return {void}
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
     * @return {string[]} Ordered image ids as strings for JSON submission.
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
     * @return {HTMLTableElement} Fixed-position table containing cloned row.
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
     * @return {HTMLTableRowElement} Placeholder row with matching height.
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
     * @return {HTMLTableRowElement|null} Row to insert before, or null to append.
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
     * @return {Promise<void>} Completes after the request succeeds or fails.
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
        bodyData.set('ajax');
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
     * @return {string} Name used for locale-aware filename sorting.
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
     * @return {void}
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
     * @return {void}
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
     * @return {void}
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
         * @return {void}
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
         * @return {HTMLTableRowElement} The moved row.
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
         * @return {void}
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
         * @return {void}
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
         * @return {void}
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