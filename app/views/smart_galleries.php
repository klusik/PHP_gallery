<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/smart_galleries.php
 * Module Type: View
 *
 * Purpose:
 *   Renders Smart Gallery administration and public presentation from controller-prepared view models.
 *
 * Responsibilities:
 *   - Render the Smart Gallery admin list and editor workspace
 *   - Render presentation override controls and placement management
 *   - Render prepared Smart Gallery image cards
 *   - Render the public Smart Gallery page shell
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
 *   - Translation and escaping are presentation dependencies.
 *   - Request parsing, authorization, persistence, media URL resolution,
 *     thumbnail policy, viewer ownership checks, and lightbox policy remain in controllers/services.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Services\t;

/**
 * Render the complete Smart Gallery administration page.
 *
 * @param array<string,mixed> $viewModel Controller-prepared admin page state.
 */
function view_render_admin_smart_galleries(array $viewModel): void
{
    render_header((string) ($viewModel['page_title'] ?? ''));
    echo '<section class="hero"><div><p class="admin-kicker">' . e(t('smart_gallery.kicker', 'Saved dynamic collections')) . '</p><h1>' . e(t('smart_gallery.admin_title', 'Smart Galleries')) . '</h1><p class="muted">' . e(t('smart_gallery.intro', 'Images stay in their physical galleries. Membership changes immediately when metadata changes.')) . '</p></div><a class="button" href="' . e((string) ($viewModel['create_url'] ?? '')) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="smart-gallery" data-admin-side-panel-title="' . e(t('smart_gallery.create', 'Create Smart Gallery')) . '">' . e(t('smart_gallery.create', 'Create Smart Gallery')) . '</a></section>';

    $notice = (string) ($viewModel['notice'] ?? '');
    $error = (string) ($viewModel['error'] ?? '');
    if ($notice !== '') {
        echo '<p class="success">' . e($notice) . '</p>';
    }
    if ($error !== '') {
        echo '<p class="error">' . e($error) . '</p>';
    }

    echo '<div class="admin-smart-gallery-layout"><section class="panel"><h2>' . e(t('smart_gallery.existing', 'Existing Smart Galleries')) . '</h2>';
    $rows = (array) ($viewModel['rows'] ?? []);
    if ($rows === []) {
        echo '<p class="muted">' . e(t('smart_gallery.none', 'No Smart Galleries have been created.')) . '</p>';
    }
    foreach ($rows as $row) {
        echo '<a class="admin-smart-gallery-row" href="' . e((string) ($row['url'] ?? '')) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="smart-gallery" data-admin-side-panel-title="' . e((string) ($row['title'] ?? '')) . '"><strong>' . e((string) ($row['title'] ?? '')) . '</strong><span>' . e((string) ($row['status_label'] ?? '')) . '</span></a>';
    }
    echo '</section><section class="panel" data-smart-gallery-editor-workspace>';
    $editor = $viewModel['editor'] ?? null;
    if (is_array($editor)) {
        view_render_smart_gallery_editor($editor);
    } else {
        echo '<h2>' . e(t('smart_gallery.select', 'Select a Smart Gallery or create a new one.')) . '</h2>';
    }
    echo '</section></div>';
    render_footer();
}

/**
 * Render the Smart Gallery editor workspace.
 *
 * @param array<string,mixed> $viewModel Controller-prepared editor state.
 */
function view_render_smart_gallery_editor(array $viewModel): void
{
    $gallery = (array) ($viewModel['gallery'] ?? []);
    echo '<form method="post" action="' . e((string) ($viewModel['form_action'] ?? '')) . '" data-smart-gallery-editor data-smart-gallery-panel-form data-smart-gallery-catalog="' . e((string) ($viewModel['catalog_json'] ?? '{}')) . '" data-smart-gallery-tags="' . e((string) ($viewModel['tags_json'] ?? '[]')) . '" data-smart-gallery-galleries="' . e((string) ($viewModel['galleries_json'] ?? '[]')) . '">';
    echo (string) ($viewModel['csrf_html'] ?? '');
    echo '<label>' . e(t('smart_gallery.title', 'Title')) . '<input name="title" required value="' . e((string) ($gallery['title'] ?? '')) . '"></label>';
    echo '<label>' . e(t('smart_gallery.slug', 'Public URL slug')) . '<input name="slug" value="' . e((string) ($gallery['slug'] ?? '')) . '"><span class="muted">' . e(t('smart_gallery.slug_help', 'Leave blank to generate it automatically from the title.')) . '</span></label>';
    echo '<label>' . e(t('smart_gallery.description', 'Description')) . '<textarea name="description">' . e((string) ($gallery['description'] ?? '')) . '</textarea></label>';
    echo '<fieldset class="admin-smart-gallery-placement"><legend>' . e(t('smart_gallery.placement', 'Public placement')) . '</legend><label>' . e(t('smart_gallery.placement_mode', 'Show this Smart Gallery as')) . '<select name="placement_mode" data-smart-gallery-placement-mode>';
    foreach ((array) ($viewModel['placement_modes'] ?? []) as $option) {
        echo '<option value="' . e((string) ($option['value'] ?? '')) . '"' . (!empty($option['selected']) ? ' selected' : '') . '>' . e((string) ($option['label'] ?? '')) . '</option>';
    }
    echo '</select></label><p class="muted">' . e(t('smart_gallery.placement_help', 'Root lists the Smart Gallery on the homepage. Subgallery mode lets you attach it beneath any number of physical galleries from each gallery editor.')) . '</p></fieldset>';

    echo '<div class="admin-smart-gallery-options"><label><input type="checkbox" name="enabled" value="1"' . (!empty($viewModel['enabled']) ? ' checked' : '') . '> ' . e(t('smart_gallery.enabled', 'Enabled')) . '</label><label>' . e(t('smart_gallery.visibility', 'Visibility')) . '<select name="visibility"><option value="private">' . e(t('smart_gallery.private', 'Private')) . '</option><option value="public"' . (($gallery['visibility'] ?? '') === 'public' ? ' selected' : '') . '>' . e(t('smart_gallery.public', 'Published')) . '</option></select></label><label>' . e(t('smart_gallery.sort', 'Sort')) . '<select name="sort_mode">';
    foreach ((array) ($viewModel['sort_modes'] ?? []) as $option) {
        echo '<option value="' . e((string) ($option['value'] ?? '')) . '"' . (!empty($option['selected']) ? ' selected' : '') . '>' . e((string) ($option['label'] ?? '')) . '</option>';
    }
    echo '</select></label><label>' . e(t('smart_gallery.direction', 'Direction')) . '<select name="sort_direction"><option value="desc">' . e(t('smart_gallery.desc', 'Descending')) . '</option><option value="asc"' . (($gallery['sort_direction'] ?? '') === 'asc' ? ' selected' : '') . '>' . e(t('smart_gallery.asc', 'Ascending')) . '</option></select></label></div>';

    view_render_smart_gallery_presentation_controls((array) ($viewModel['presentation_controls'] ?? []));

    echo '<input type="hidden" name="rules_json" value="' . e((string) ($viewModel['rules_json'] ?? '')) . '" data-smart-gallery-rules><div class="smart-rule-builder" data-smart-rule-builder></div><noscript><p class="error">' . e(t('smart_gallery.javascript_required', 'JavaScript is required for the visual nested rule editor. Existing rules remain safe and can still be previewed or saved unchanged.')) . '</p></noscript>';
    if (array_key_exists('preview_count', $viewModel) && $viewModel['preview_count'] !== null) {
        echo '<p class="notice">' . e(t('smart_gallery.preview_count', 'This Smart Gallery currently matches {count} images.', ['count' => (int) $viewModel['preview_count']])) . '</p>';
        if (is_array($viewModel['preview_cards'] ?? null)) {
            echo '<div class="admin-smart-gallery-preview"><h3>' . e(t('smart_gallery.preview_cards', 'Presentation preview')) . '</h3><p class="muted">' . e(t('smart_gallery.preview_cards_help', 'The preview uses real matching photos and the effective Smart Gallery presentation settings.')) . '</p>';
            view_render_smart_gallery_image_cards((array) $viewModel['preview_cards']);
            echo '</div>';
        }
    }
    echo '<div class="button-row"><button name="action" value="preview" class="button secondary">' . e(t('smart_gallery.preview', 'Preview')) . '</button><button name="action" value="save" class="button">' . e(t('smart_gallery.save', 'Save Smart Gallery')) . '</button></div></form>';

    if (empty($viewModel['existing'])) {
        return;
    }

    echo '<section class="admin-smart-gallery-placements"><h3>' . e(t('smart_gallery.used_in', 'Used in physical galleries')) . '</h3><p class="muted">' . e(t('smart_gallery.used_in_help', 'These galleries currently show this Smart Gallery as a subgallery. Removing one location does not affect the others.')) . '</p>';
    $placements = (array) ($viewModel['placements'] ?? []);
    if ($placements === []) {
        echo '<p class="muted">' . e(t('smart_gallery.used_nowhere', 'This Smart Gallery is not currently attached beneath a physical gallery.')) . '</p>';
    } else {
        echo '<div class="admin-smart-gallery-placement-list">';
        foreach ($placements as $placement) {
            echo '<div class="admin-smart-gallery-placement-row"><a href="' . e((string) ($placement['edit_url'] ?? '')) . '"><strong>' . e((string) ($placement['title'] ?? '')) . '</strong><small>' . e((string) ($placement['folder_path'] ?? '')) . '</small>';
            if (!empty($placement['parent_restricted'])) {
                echo '<small>' . e(t('smart_gallery.parent_policy_applies', 'Parent access policy still applies to this placement.')) . '</small>';
            }
            if (empty($placement['relationship_valid'])) {
                echo '<small class="error">' . e(t('smart_gallery.relationship_invalid_short', 'Relationship needs repair.')) . '</small>';
            }
            echo '</a><form method="post" action="' . e((string) ($viewModel['form_action'] ?? '')) . '" data-smart-gallery-panel-form>' . (string) ($viewModel['csrf_html'] ?? '') . '<input type="hidden" name="id" value="' . (int) ($viewModel['gallery_id'] ?? 0) . '"><input type="hidden" name="gallery_id" value="' . (int) ($placement['id'] ?? 0) . '"><label>' . e(t('smart_gallery.attachment_placement', 'Placement for this parent')) . '<select name="attachment_placement"><option value="top"' . (($placement['placement'] ?? '') === 'top' ? ' selected' : '') . '>' . e(t('smart_gallery.placement_top', 'Above gallery content')) . '</option><option value="bottom"' . (($placement['placement'] ?? '') !== 'top' ? ' selected' : '') . '>' . e(t('smart_gallery.placement_bottom', 'Below gallery content')) . '</option></select></label><label>' . e(t('smart_gallery.attachment_order', 'Order')) . '<input type="number" min="-100000" max="100000" name="attachment_order" value="' . (int) ($placement['placement_order'] ?? 0) . '"></label><div class="button-row"><button name="action" value="update_placement" class="button secondary">' . e(t('smart_gallery.save_placement', 'Save placement')) . '</button><button name="action" value="remove_placement" class="button secondary">' . e(t('smart_gallery.hide_from_here', 'Detach')) . '</button></div></form></div>';
        }
        echo '</div>';
    }
    echo '</section>';

    if (!empty($viewModel['public_url'])) {
        echo '<p><a class="button secondary" href="' . e((string) $viewModel['public_url']) . '">' . e(t('smart_gallery.open_public', 'Open published Smart Gallery')) . '</a></p>';
    }
    echo '<div class="button-row"><form method="post" action="' . e((string) ($viewModel['form_action'] ?? '')) . '" data-smart-gallery-panel-form>' . (string) ($viewModel['csrf_html'] ?? '') . '<input type="hidden" name="id" value="' . (int) ($viewModel['gallery_id'] ?? 0) . '"><button name="action" value="duplicate" class="button secondary">' . e(t('smart_gallery.duplicate', 'Duplicate')) . '</button></form><form method="post" action="' . e((string) ($viewModel['form_action'] ?? '')) . '" data-smart-gallery-panel-form onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . e(t('smart_gallery.delete_confirm', 'Delete this Smart Gallery definition? Images and files will not be deleted.')) . '">' . (string) ($viewModel['csrf_html'] ?? '') . '<input type="hidden" name="id" value="' . (int) ($viewModel['gallery_id'] ?? 0) . '"><button name="action" value="delete" class="button danger">' . e(t('smart_gallery.delete', 'Delete')) . '</button></form></div>';
}

/**
 * Render canonical Smart Gallery presentation controls.
 *
 * @param array<string,mixed> $viewModel Controller-prepared presentation state.
 */
function view_render_smart_gallery_presentation_controls(array $viewModel): void
{
    $presentation = (array) ($viewModel['presentation'] ?? []);
    $thumbnailBounds = (array) ($viewModel['thumbnail_bounds'] ?? []);
    $masters = (array) ($viewModel['capability_masters'] ?? []);
    $paginationEnabled = !empty($presentation['pagination_enabled']);
    $itemsPerPage = (int) ($viewModel['items_per_page'] ?? 1);

    echo '<fieldset class="admin-smart-gallery-presentation"><legend>' . e(t('smart_gallery.presentation', 'Presentation')) . '</legend>';
    echo '<label class="admin-smart-gallery-presentation-toggle"><input type="checkbox" name="presentation_override_enabled" value="1" data-smart-gallery-presentation-toggle' . (!empty($viewModel['has_override']) ? ' checked' : '') . '> ' . e(t('smart_gallery.presentation_override', 'Override Theme defaults for this Smart Gallery')) . '</label>';
    echo '<p class="muted">' . e(t('smart_gallery.presentation_help', 'When disabled, the Smart Gallery inherits the current Theme and site defaults. Because one Smart Gallery can have multiple placements, presentation never inherits from a physical parent gallery.')) . '</p>';
    echo '<p class="muted"><strong>' . e(t('smart_gallery.presentation_source', 'Presentation source:')) . '</strong> ' . e((string) ($viewModel['source_label'] ?? '')) . '</p>';

    echo '<div class="admin-edit-card-grid admin-smart-gallery-presentation-fields" data-smart-gallery-presentation-fields>';
    echo '<div class="admin-edit-card is-wide">';
    echo '<h3>' . e(t('smart_gallery.display_grid', 'Display grid')) . '</h3>';
    echo '<div class="admin-edit-range-grid">';
    echo '<label>' . e(t('smart_gallery.grid_columns', 'Columns')) . ' <span class="muted" data-gallery-grid-columns-display>' . (int) ($presentation['grid_columns'] ?? 1) . '</span><input type="range" min="1" max="' . (int) ($viewModel['max_columns'] ?? 1) . '" name="presentation_grid_columns" value="' . (int) ($presentation['grid_columns'] ?? 1) . '" data-gallery-grid-columns data-smart-gallery-grid-columns></label>';
    echo '<label class="admin-smart-gallery-pagination-dependent' . ($paginationEnabled ? '' : ' is-inactive') . '" data-smart-gallery-rows-control aria-disabled="' . ($paginationEnabled ? 'false' : 'true') . '">' . e(t('smart_gallery.grid_rows', 'Rows per page')) . ' <span class="muted" data-gallery-grid-rows-display>' . (int) ($presentation['grid_rows'] ?? 1) . '</span><input type="range" min="1" max="' . (int) ($viewModel['max_rows'] ?? 1) . '" name="presentation_grid_rows" value="' . (int) ($presentation['grid_rows'] ?? 1) . '" data-gallery-grid-rows data-smart-gallery-grid-rows></label>';
    echo '</div>';
    echo '<label class="checkbox-label"><input type="checkbox" name="presentation_pagination_enabled" value="1" data-smart-gallery-pagination-toggle' . ($paginationEnabled ? ' checked' : '') . '> ' . e(t('smart_gallery.pagination_enabled', 'Use pagination')) . '</label>';
    echo '<p class="muted" data-smart-gallery-pagination-active-status' . ($paginationEnabled ? '' : ' hidden') . '>' . e(t('smart_gallery.items_per_page', 'Items per page:')) . ' <strong data-smart-gallery-items-per-page>' . $itemsPerPage . '</strong></p>';
    echo '<p class="muted" data-smart-gallery-pagination-inactive-status' . ($paginationEnabled ? ' hidden' : '') . '>' . e(t('smart_gallery.rows_inactive_help', 'Rows per page is stored but does not limit results until pagination is enabled.')) . '</p>';
    echo '<p class="muted">' . e(t('smart_gallery.pagination_safety_help', 'Large result sets are paginated automatically for safety above {limit} matching images.', ['limit' => (string) ($viewModel['pagination_safety_limit'] ?? 200)])) . '</p>';
    echo '</div>';

    echo '<div class="admin-edit-card is-wide">';
    render_admin_thumbnail_bound_slider(
        'presentation_thumbnail',
        (array) ($thumbnailBounds['values'] ?? []),
        (int) ($thumbnailBounds['min_index'] ?? 0),
        (int) ($thumbnailBounds['max_index'] ?? 0),
        t('smart_gallery.thumbnail_quality_bounds', 'Responsive thumbnail quality bounds'),
        t('smart_gallery.thumbnail_quality_bounds_help', 'Optional additional guardrails for automatic thumbnail selection. Leave the bounds at their outer positions to keep the inherited candidate range.')
    );
    echo '<p class="muted">' . e(t('smart_gallery.thumbnail_source_guardrails', 'Smart Gallery bounds can only narrow the available thumbnail candidates. Source-gallery and individual-photo bounds remain authoritative.')) . '</p>';
    echo '</div>';

    echo '<div class="admin-edit-card">';
    echo '<h3>' . e(t('smart_gallery.rendering', 'Rendering')) . '</h3>';
    echo '<label>' . e(t('smart_gallery.thumbnail_renderer', 'Thumbnail renderer')) . '<select name="presentation_thumbnail_rendering_mode">';
    foreach ((array) ($viewModel['thumbnail_modes'] ?? []) as $option) {
        echo '<option value="' . e((string) ($option['value'] ?? '')) . '"' . (!empty($option['selected']) ? ' selected' : '') . '>' . e((string) ($option['label'] ?? '')) . '</option>';
    }
    echo '</select></label>';
    echo '<label>' . e(t('smart_gallery.placed_card_layout', 'Placed Smart Gallery card layout')) . '<select name="presentation_card_layout">';
    foreach ((array) ($viewModel['card_layouts'] ?? []) as $option) {
        echo '<option value="' . e((string) ($option['value'] ?? '')) . '"' . (!empty($option['selected']) ? ' selected' : '') . '>' . e((string) ($option['label'] ?? '')) . '</option>';
    }
    echo '</select></label>';
    echo '<p class="muted">' . e(t('smart_gallery.placed_card_layout_help', 'This layout controls the Smart Gallery card when it is placed on the homepage or beneath a physical gallery. It does not change the result photo cards.')) . '</p>';
    echo '<label class="checkbox-label"><input type="checkbox" name="presentation_metadata_visible" value="1"' . (!empty($presentation['metadata_visible']) ? ' checked' : '') . '> ' . e(t('smart_gallery.metadata_visible', 'Show photo metadata overlays')) . '</label>';
    echo '<label class="checkbox-label"><input type="checkbox" name="presentation_source_gallery_visible" value="1"' . (!empty($presentation['source_gallery_visible']) ? ' checked' : '') . '> ' . e(t('smart_gallery.source_gallery_visible', 'Show source gallery')) . '</label>';
    echo '</div>';

    echo '<div class="admin-edit-card">';
    echo '<h3>' . e(t('smart_gallery.viewer_features', 'Viewer features')) . '</h3>';
    echo '<label class="checkbox-label"><input type="checkbox" name="presentation_map_enabled" value="1"' . (!empty($presentation['map_enabled']) ? ' checked' : '') . '> ' . e(t('smart_gallery.map_enabled', 'Enable aggregate GPS map')) . '</label>';
    if (empty($masters['maps'])) {
        echo '<p class="muted">' . e(t('smart_gallery.map_master_suppressed', 'Currently suppressed by the site-wide EXIF GPS Gallery Maps capability. The Smart Gallery preference is preserved.')) . '</p>';
    }
    echo '<label class="checkbox-label"><input type="checkbox" name="presentation_lightbox_enabled" value="1"' . (!empty($presentation['lightbox_enabled']) ? ' checked' : '') . '> ' . e(t('smart_gallery.lightbox_enabled', 'Enable lightbox')) . '</label>';
    if (empty($masters['lightbox'])) {
        echo '<p class="muted">' . e(t('smart_gallery.lightbox_master_suppressed', 'Currently suppressed by the site-wide Lightbox capability. The Smart Gallery preference is preserved.')) . '</p>';
    }
    echo '<label>' . e(t('smart_gallery.lightbox_mode', 'Lightbox browsing mode')) . '<select name="presentation_lightbox_browsing_mode">';
    foreach ((array) ($viewModel['lightbox_modes'] ?? []) as $option) {
        echo '<option value="' . e((string) ($option['value'] ?? '')) . '"' . (!empty($option['selected']) ? ' selected' : '') . '>' . e((string) ($option['label'] ?? '')) . '</option>';
    }
    echo '</select></label>';
    echo '<label class="checkbox-label"><input type="checkbox" name="presentation_slideshow_enabled" value="1"' . (!empty($presentation['slideshow_enabled']) ? ' checked' : '') . '> ' . e(t('smart_gallery.slideshow_enabled', 'Enable slideshow controls')) . '</label>';
    echo '<label class="checkbox-label"><input type="checkbox" name="presentation_voting_enabled" value="1"' . (!empty($presentation['voting_enabled']) ? ' checked' : '') . '> ' . e(t('smart_gallery.voting_enabled', 'Enable visitor voting where the source gallery allows it')) . '</label>';
    if (empty($masters['voting'])) {
        echo '<p class="muted">' . e(t('smart_gallery.voting_master_suppressed', 'Currently suppressed by the site-wide Image Voting capability. The Smart Gallery preference is preserved.')) . '</p>';
    }
    echo '<label class="checkbox-label"><input type="checkbox" name="presentation_download_enabled" value="1"' . (!empty($presentation['download_enabled']) ? ' checked' : '') . '> ' . e(t('smart_gallery.download_enabled', 'Allow Smart Gallery download when site downloads are enabled')) . '</label>';
    if (empty($masters['downloads'])) {
        echo '<p class="muted">' . e(t('smart_gallery.download_master_suppressed', 'Currently suppressed by the site-wide Downloads capability. The Smart Gallery preference is preserved.')) . '</p>';
    }
    echo '</div>';
    echo '</div></fieldset>';
}

/**
 * Render prepared Smart Gallery image cards.
 *
 * @param array<string,mixed> $viewModel Controller-prepared card state.
 */
function view_render_smart_gallery_image_cards(array $viewModel): void
{
    echo '<section class="grid gallery-image-grid' . e((string) ($viewModel['grid_class'] ?? '')) . '" data-gallery-image-list>';
    foreach ((array) ($viewModel['cards'] ?? []) as $card) {
        echo '<article class="image-card"' . (string) ($card['attributes_html'] ?? '') . (string) ($card['favourite_attribute_html'] ?? '') . '><div class="image-stage"><a class="image-preview-link" href="' . e((string) ($card['url'] ?? '')) . '">' . (string) ($card['thumbnail_html'] ?? '') . '</a>';
        echo (string) ($card['favourite_html'] ?? '');
        echo (string) ($card['collection_html'] ?? '');
        echo (string) ($card['vote_html'] ?? '');
        $sourceGallery = $card['source_gallery'] ?? null;
        if (is_array($sourceGallery) && trim((string) ($sourceGallery['title'] ?? '')) !== '' && trim((string) ($sourceGallery['url'] ?? '')) !== '') {
            $sourceGalleryPath = trim((string) ($sourceGallery['breadcrumb_compact'] ?? $sourceGallery['breadcrumb'] ?? $sourceGallery['title']));
            $sourceGalleryFullPath = trim((string) ($sourceGallery['breadcrumb'] ?? $sourceGalleryPath));
            $sourceGalleryLabel = t('smart_gallery.source_gallery_link', 'Source gallery: {title}', ['title' => $sourceGalleryFullPath]);
            echo '<a class="smart-gallery-source-badge" href="' . e((string) $sourceGallery['url']) . '" data-smart-gallery-source-link aria-label="' . e($sourceGalleryLabel) . '" title="' . e($sourceGalleryFullPath) . '"><span class="smart-gallery-source-badge-icon" aria-hidden="true">&#128193;</span><span class="smart-gallery-source-badge-path">' . e($sourceGalleryPath) . '</span></a>';
        }
        if (!empty($card['metadata_visible'])) {
            echo '<div class="image-meta image-meta-overlay">';
            if ((string) ($card['title'] ?? '') !== '') {
                echo '<h2>' . e((string) $card['title']) . '</h2>';
            }
            if ((string) ($card['description'] ?? '') !== '') {
                echo '<p>' . e((string) $card['description']) . '</p>';
            }
            echo (string) ($card['tags_html'] ?? '');
            echo '</div>';
        }
        echo '</div></article>';
    }
    echo '</section>';
}

/**
 * Render grouped source-gallery provenance and temporary source filtering.
 *
 * @param array<string,mixed> $summary Controller-prepared grouped source state.
 */
function view_render_smart_gallery_source_summary(array $summary): void
{
    $items = array_values(array_filter((array) ($summary['items'] ?? []), 'is_array'));
    $galleryCount = max(0, (int) ($summary['gallery_count'] ?? count($items)));
    $totalImages = max(0, (int) ($summary['total_images'] ?? 0));
    if ($galleryCount <= 0 || $totalImages <= 0 || $items === []) return;

    $selectedGalleryId = max(0, (int) ($summary['selected_gallery_id'] ?? 0));
    $summaryLabel = t(
        'smart_gallery.source_summary',
        '{count} photos from {galleries} source galleries',
        ['count' => $totalImages, 'galleries' => $galleryCount]
    );
    $selectedTitle = '';
    foreach ($items as $item) {
        if (!empty($item['selected'])) {
            $selectedTitle = trim((string) ($item['breadcrumb_compact'] ?? $item['breadcrumb'] ?? $item['title'] ?? ''));
            break;
        }
    }

    echo '<details class="smart-gallery-source-summary"' . ($selectedGalleryId > 0 ? ' open' : '') . '>';
    echo '<summary><span>' . e($summaryLabel) . '</span>';
    if ($selectedTitle !== '') {
        echo '<span class="smart-gallery-source-summary-active">' . e(t('smart_gallery.source_filter_active', 'Source: {title}', ['title' => $selectedTitle])) . '</span>';
    }
    echo '</summary>';
    echo '<nav class="smart-gallery-source-filter-list" aria-label="' . e(t('smart_gallery.source_filter_label', 'Filter Smart Gallery by source gallery')) . '">';

    $clearUrl = trim((string) ($summary['clear_url'] ?? ''));
    if ($clearUrl !== '') {
        echo '<a class="smart-gallery-source-filter-chip' . ($selectedGalleryId <= 0 ? ' is-active' : '') . '" href="' . e($clearUrl) . '"' . ($selectedGalleryId <= 0 ? ' aria-current="page"' : '') . '>';
        echo '<span>' . e(t('smart_gallery.source_filter_all', 'All sources')) . '</span><strong>' . $totalImages . '</strong></a>';
    }

    foreach ($items as $item) {
        $galleryId = max(0, (int) ($item['gallery_id'] ?? 0));
        $title = trim((string) ($item['title'] ?? ''));
        $sourcePath = trim((string) ($item['breadcrumb_compact'] ?? $item['breadcrumb'] ?? $title));
        $sourceFullPath = trim((string) ($item['breadcrumb'] ?? $sourcePath));
        $filterUrl = trim((string) ($item['filter_url'] ?? ''));
        $sourceUrl = trim((string) ($item['source_url'] ?? ''));
        $imageCount = max(0, (int) ($item['image_count'] ?? 0));
        if ($galleryId <= 0 || $sourcePath === '' || $filterUrl === '' || $imageCount <= 0) continue;

        $selected = !empty($item['selected']);
        echo '<span class="smart-gallery-source-filter-entry">';
        echo '<a class="smart-gallery-source-filter-chip' . ($selected ? ' is-active' : '') . '" href="' . e($filterUrl) . '"' . ($selected ? ' aria-current="page"' : '') . ' aria-label="' . e(t('smart_gallery.source_filter_one', 'Filter by source gallery: {title}', ['title' => $sourceFullPath])) . '" title="' . e($sourceFullPath) . '">';
        echo '<span>' . e($sourcePath) . '</span><strong>' . $imageCount . '</strong></a>';
        if ($sourceUrl !== '') {
            echo '<a class="smart-gallery-source-filter-origin" href="' . e($sourceUrl) . '" aria-label="' . e(t('smart_gallery.source_open_gallery', 'Open source gallery: {title}', ['title' => $sourceFullPath])) . '" title="' . e(t('smart_gallery.source_open_gallery', 'Open source gallery: {title}', ['title' => $sourceFullPath])) . '">&#8599;</a>';
        }
        echo '</span>';
    }

    echo '</nav></details>';
}

/**
 * Render a published Smart Gallery page.
 *
 * @param array<string,mixed> $viewModel Controller-prepared public page state.
 */
function view_render_public_smart_gallery(array $viewModel): void
{
    render_header((string) ($viewModel['title'] ?? ''));
    echo '<section class="hero"><div class="hero-topbar"><div class="hero-primary"><div><p class="admin-kicker">' . e(t('smart_gallery.public_kicker', 'Smart Gallery')) . '</p><h1>' . e((string) ($viewModel['title'] ?? '')) . '</h1><p>' . e((string) ($viewModel['description'] ?? '')) . '</p><p class="muted">' . e(t('smart_gallery.dynamic_count', '{count} matching images', ['count' => (int) ($viewModel['total'] ?? 0)])) . '</p></div></div><div class="hero-meta"><div class="hero-actions" aria-label="' . e(t('gallery.actions', 'Gallery actions')) . '">';
    $download = $viewModel['download'] ?? null;
    if (is_array($download)) {
        echo '<form class="public-download-legacy-form" method="post" action="' . e((string) ($download['action_url'] ?? '')) . '"><input type="hidden" name="id" value="' . (int) ($download['id'] ?? 0) . '"><input type="hidden" name="capability" value="' . e((string) ($download['capability'] ?? '')) . '"><button type="submit" class="button hero-icon-button hero-download-button" data-gallery-download data-gallery-download-start-url="' . e((string) ($download['start_url'] ?? '')) . '" aria-label="' . e((string) ($download['label'] ?? '')) . '" title="' . e((string) ($download['label'] ?? '')) . '"><span aria-hidden="true">&#10515;</span><span class="visually-hidden">' . e((string) ($download['label'] ?? '')) . '</span></button></form>';
    }
    if (!empty($viewModel['map_available']) && trim((string) ($viewModel['map_url'] ?? '')) !== '') {
        $mapLabel = t('smart_gallery.show_map', 'Show Smart Gallery map');
        echo '<button type="button" class="button secondary hero-icon-button smart-gallery-map-button" data-gallery-map-url="' . e((string) $viewModel['map_url']) . '" data-gallery-map-title="' . e((string) ($viewModel['title'] ?? '')) . '" aria-label="' . e($mapLabel) . '" title="' . e($mapLabel) . '"><span aria-hidden="true">&#128205;</span><span class="visually-hidden">' . e($mapLabel) . '</span></button>';
    }
    echo '</div></div></div></section>';

    view_render_smart_gallery_source_summary((array) ($viewModel['source_summary'] ?? []));
    echo (string) ($viewModel['pagination_html'] ?? '');
    if (!empty($viewModel['lightbox_enabled'])) {
        echo '<div data-lightbox-config data-lightbox-endpoint="' . e((string) ($viewModel['lightbox_endpoint'] ?? '')) . '" data-lightbox-total="' . (int) ($viewModel['total'] ?? 0) . '" data-lightbox-window-size="60" data-lightbox-browsing-mode="' . e((string) ($viewModel['lightbox_browsing_mode'] ?? '')) . '" data-lightbox-maps-enabled="' . (!empty($viewModel['map_available']) ? '1' : '0') . '" data-lightbox-gallery-map-url="' . e((string) ($viewModel['map_url'] ?? '')) . '" data-lightbox-gallery-map-title="' . e((string) ($viewModel['title'] ?? '')) . '">';
    }
    view_render_smart_gallery_image_cards((array) ($viewModel['cards'] ?? []));
    if (!empty($viewModel['lightbox_enabled'])) {
        echo '</div>';
    }
    echo (string) ($viewModel['pagination_html'] ?? '');
    echo (string) ($viewModel['lightbox_html'] ?? '');
}
