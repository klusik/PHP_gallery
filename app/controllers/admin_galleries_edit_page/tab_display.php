<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_edit_page/tab_display.php
 * Module Type: Controller
 *
 * Purpose:
 *   Renders the Display tab of the gallery editor.
 *
 * Responsibilities:
 *   - Toggle picture game, voting, filename display, and EXIF/GPS presentation
 *   - Edit inherited overrides for description layout, count badge, and lightbox mode
 *   - Edit the display grid override and responsive thumbnail quality bounds
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
 *   - Loaded by app/controllers/admin_galleries_edit_page.php; do not require this file directly.
 *   - Each override control also reports its current inheritance source.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use const Gallery\Services\CMS_PAGINATION_MAX_COLUMNS;
use const Gallery\Services\CMS_PAGINATION_MAX_ROWS;
use function Gallery\Core\e;
use function Gallery\Core\render_admin_tab_panel;
use function Gallery\Services\gallery_count_badge_override_label;
use function Gallery\Services\gallery_count_badge_override_values;
use function Gallery\Services\gallery_count_badge_schema_ready;
use function Gallery\Services\gallery_count_badge_source_label;
use function Gallery\Services\gallery_count_badge_state_label;
use function Gallery\Services\gallery_count_badge_storage_value;
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
use function Gallery\Services\gallery_gps_map_storage_value;
use function Gallery\Services\gallery_grid_form_columns;
use function Gallery\Services\gallery_grid_form_rows;
use function Gallery\Services\gallery_grid_has_explicit_override;
use function Gallery\Services\gallery_grid_schema_ready;
use function Gallery\Services\gallery_lightbox_browsing_mode_label;
use function Gallery\Services\gallery_lightbox_browsing_mode_options;
use function Gallery\Services\gallery_lightbox_browsing_mode_override_label;
use function Gallery\Services\gallery_lightbox_browsing_mode_source_label;
use function Gallery\Services\gallery_lightbox_browsing_mode_storage_value;
use function Gallery\Services\render_admin_thumbnail_bound_slider;
use function Gallery\Services\t;
use function Gallery\Services\thumbnail_bounds_schema_ready;
use function Gallery\Views\view_render_admin_tab_intro;

/**
 * Render the Display tab panel.
 *
 * @param array<string, mixed> $gallery Gallery row being edited.
 * @param string $activeEditTab Currently selected editor tab.
 * @param array<string, mixed> $capabilities Resolved editor capabilities.
 */
function admin_edit_gallery_render_display_tab(array $gallery, string $activeEditTab, array $capabilities): void
{
    ob_start();
    view_render_admin_tab_intro([
        'kicker' => t('admin.gallery_editor.display_kicker', 'Display'),
        'title' => t('admin.gallery_editor.gallery_behavior', 'Gallery behavior'),
        'description' => t('admin.gallery_editor.gallery_behavior_help', 'Feature toggles and grid overrides affecting this gallery branch.'),
    ]);
    echo '<div class="admin-edit-card-grid">';
    if ($capabilities['picture_game_ready']) {
        echo '<div class="admin-edit-card"><label class="checkbox-label"><input type="checkbox" name="picture_game_enabled" value="1"' . ((int) ($gallery['picture_game_enabled'] ?? 0) === 1 ? ' checked' : '') . '> ' . e(t('admin.gallery_editor.enable_picture_game', 'Enable picture game for this gallery branch')) . '</label></div>';
    }
    if ($capabilities['voting_ready']) {
        echo '<div class="admin-edit-card"><label class="checkbox-label"><input type="checkbox" name="voting_enabled" value="1"' . ((int) ($gallery['voting_enabled'] ?? 0) === 1 ? ' checked' : '') . '> ' . e(t('admin.gallery_editor.enable_image_voting', 'Enable image voting for this gallery')) . '</label><p class="muted">' . e(t('admin.gallery_editor.image_voting_help', 'When disabled, existing votes remain stored and visible, but vote arrows and vote submissions are blocked.')) . '</p></div>';
    }
    if (gallery_filename_display_schema_ready()) {
        echo '<div class="admin-edit-card"><label class="checkbox-label"><input type="checkbox" name="show_filenames" value="1"' . ((int) ($gallery['show_filenames'] ?? 0) === 1 ? ' checked' : '') . '> ' . e(t('admin.gallery_editor.show_file_names', 'Show file names')) . '</label><p class="muted">' . e(t('admin.gallery_editor.show_file_names_help', 'Disabled by default. Custom photo titles and descriptions are still shown; raw uploaded file names stay hidden unless this is enabled.')) . '</p></div>';
    } else {
        echo '<div class="admin-edit-card"><p class="muted">' . e(t('admin.gallery_editor.filename_display_migration_hidden', 'File name display control will be available after the database migration is applied.')) . '</p></div>';
    }
    if ($capabilities['flight_map_ready']) {
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
    } elseif ($capabilities['flight_map_feature_enabled']) {
        echo '<div class="admin-edit-card is-wide"><p class="muted">' . e(t('admin.gallery_editor.flight_route_migration_hidden', 'Flight route map controls will be available after the database migration is applied.')) . '</p></div>';
    }
    if ($capabilities['gps_map_override_ready']) {
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
    } elseif ($capabilities['gps_map_ready']) {
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
        echo '</select></label><p class="muted">' . e(t('admin.gallery_editor.count_badge_help', 'Current source: {source}. Effective state: {state}. This controls the stacked-picture branch image count on gallery cards and in this gallery hero.', ['source' => gallery_count_badge_source_label($gallery), 'state' => gallery_count_badge_state_label($effectiveCountBadgeEnabled)])) . '</p></div>';
    } else {
        echo '<div class="admin-edit-card"><p class="muted">' . e(t('admin.gallery_editor.count_badge_migration_hidden', 'Contained-picture badge overrides will be available after the database migration is applied.')) . '</p></div>';
    }
    if ($capabilities['lightbox_mode_ready']) {
        // $currentLightboxBrowsingMode stores the optional value saved directly on this gallery.
        $currentLightboxBrowsingMode = gallery_lightbox_browsing_mode_storage_value($gallery['lightbox_browsing_mode'] ?? null) ?? 'inherit';
        // $effectiveLightboxBrowsingMode stores the public mode before any form edits.
        $effectiveLightboxBrowsingMode = gallery_effective_lightbox_browsing_mode($gallery);
        echo '<div class="admin-edit-card"><h3>' . e(t('admin.gallery_editor.lightbox_mode_title', 'Lightbox browsing mode')) . '</h3><label>' . e(t('admin.gallery_editor.lightbox_mode_label', 'Gallery lightbox')) . '<select name="lightbox_browsing_mode"><option value="inherit"' . ($currentLightboxBrowsingMode === 'inherit' ? ' selected' : '') . '>' . e(gallery_lightbox_browsing_mode_override_label('inherit')) . '</option>';
        foreach (gallery_lightbox_browsing_mode_options() as $lightboxModeOption) {
            echo '<option value="' . e($lightboxModeOption) . '"' . ($currentLightboxBrowsingMode === $lightboxModeOption ? ' selected' : '') . '>' . e(gallery_lightbox_browsing_mode_label($lightboxModeOption)) . '</option>';
        }
        echo '</select></label><p class="muted">' . e(t('admin.gallery_editor.lightbox_mode_help', 'Current source: {source}. Effective mode: {mode}. Single image keeps the classic viewer, picture strip adds nearby thumbnails below the photo, and 3D carousel places a small set of neighboring photos behind the active image. Fullscreen and slideshow keep their existing behavior.', ['source' => gallery_lightbox_browsing_mode_source_label($gallery), 'mode' => gallery_lightbox_browsing_mode_label($effectiveLightboxBrowsingMode)])) . '</p></div>';
    } elseif ($capabilities['lightbox_mode_feature_enabled']) {
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
}
