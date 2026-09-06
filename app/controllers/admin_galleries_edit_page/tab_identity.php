<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_edit_page/tab_identity.php
 * Module Type: Controller
 *
 * Purpose:
 *   Renders the Identity tab of the gallery editor.
 *
 * Responsibilities:
 *   - Edit the public title, date range, description, slug, and disk folder name
 *   - Edit the gallery tree position, sort order, and tags
 *   - Present Smart Gallery attachment placement and ordering for this parent
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
 *   - Fields render inside the shared editor form opened by the module entry point.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Core\e;
use function Gallery\Core\render_admin_tab_panel;
use function Gallery\Services\feature_flag_enabled;
use function Gallery\Services\gallery_date_input_value;
use function Gallery\Services\gallery_date_schema_ready;
use function Gallery\Services\gallery_folder_name_from_path;
use function Gallery\Services\smart_galleries_all;
use function Gallery\Services\smart_gallery_attachment_rows_for_gallery;
use function Gallery\Services\smart_gallery_attachment_schema_ready;
use function Gallery\Services\smart_gallery_schema_ready;
use function Gallery\Services\t;
use function Gallery\Services\tag_names_for_entity;
use function Gallery\Views\view_render_admin_gallery_date_range_fields;
use function Gallery\Views\view_render_admin_openai_text_assist_tool;
use function Gallery\Views\view_render_admin_tab_intro;
use function Gallery\Views\view_render_content_localization_fields;

/**
 * Render the Identity tab panel.
 *
 * @param array<string, mixed> $gallery Gallery row being edited.
 * @param string $activeEditTab Currently selected editor tab.
 */
function admin_edit_gallery_render_identity_tab(array $gallery, string $activeEditTab): void
{
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
    view_render_content_localization_fields('gallery', $gallery);
    render_admin_simbrief_description_tool((int) $gallery['id']);
    if ((!function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('openai_text_assist')) && function_exists('Gallery\\Views\\view_render_admin_openai_text_assist_tool')) {
        view_render_admin_openai_text_assist_tool((int) $gallery['id'], 0, 'gallery');
    }
    echo '</div>';
    echo '<div class="admin-edit-card"><label>' . e(t('admin.gallery_editor.slug', 'Slug')) . '<input name="slug" value="' . e($gallery['slug']) . '" autocomplete="off" required><span class="muted">' . e(t('admin.gallery_editor.slug_help', 'Used in the public gallery URL.')) . '</span></label><label>' . e(t('admin.gallery_editor.folder_name', 'Folder name')) . '<input name="folder_name" value="' . e(gallery_folder_name_from_path((string) $gallery['folder_path'])) . '" autocomplete="off" required><span class="muted">' . e(t('admin.gallery_editor.folder_rename_help', 'Changing this renames the folder on disk.')) . '</span></label></div>';
    echo '<div class="admin-edit-card"><label>' . e(t('admin.gallery_editor.parent_gallery', 'Parent gallery')) . '<select name="parent_id"><option value="0">' . e(t('admin.gallery_editor.no_parent', 'No parent')) . '</option>' . gallery_parent_options($gallery) . '</select></label><label>' . e(t('admin.gallery_editor.sort_order', 'Sort order')) . '<input name="sort_order" type="number" value="' . (int) $gallery['sort_order'] . '"></label></div>';
    echo '<div class="admin-edit-card is-wide"><label>' . e(t('admin.gallery_editor.tags', 'Tags')) . '<input name="tags" value="' . e(tag_names_for_entity('gallery', (int) $gallery['id'])) . '" list="tag-suggestions" data-tag-input' . admin_weighted_tag_suggestions_attribute((int) $gallery['id']) . '><span class="muted">' . e(t('admin.gallery_editor.tags_help', 'Separate tags with commas. Suggested tags are ranked by nearby galleries, images, and folder context.')) . '</span></label></div>';
    admin_edit_gallery_render_smart_gallery_attachments($gallery);
    echo '</div>';
    render_tag_datalist();
    render_admin_tab_panel('admin-edit-identity', (string) ob_get_clean(), $activeEditTab === 'admin-edit-identity');
}

/**
 * Render the Smart Gallery attachment card inside the Identity tab.
 *
 * Placement and ordering are stored per parent gallery, so this card only
 * describes attachments as they apply to the gallery being edited.
 *
 * @param array<string, mixed> $gallery Gallery row being edited.
 */
function admin_edit_gallery_render_smart_gallery_attachments(array $gallery): void
{
    if (!smart_gallery_schema_ready()) {
        return;
    }
    $smartGalleryDefinitions = smart_galleries_all();
    $attachmentMetadataReady = smart_gallery_attachment_schema_ready();
    $attachedRows = $attachmentMetadataReady ? smart_gallery_attachment_rows_for_gallery((int) $gallery['id']) : [];
    $attachedById = [];
    foreach ($attachedRows as $attachedRow) $attachedById[(int) $attachedRow['id']] = $attachedRow;
    echo '<div class="admin-edit-card is-wide"><h3>' . e(t('smart_gallery.children_title', 'Smart Gallery attachments')) . '</h3><p class="muted">' . e(t('smart_gallery.children_help', 'Attach virtual galleries above or below this physical gallery content. Placement and order apply only to this parent gallery.')) . '</p>';
    if (!$attachmentMetadataReady) {
        render_admin_migration_notice(t('smart_gallery.attachment_migration_required', 'Run the pending database migration before changing Smart Gallery placement or ordering. Existing attachments continue to render below gallery content until then.'));
    } elseif (!$smartGalleryDefinitions) {
        echo '<p class="muted">' . e(t('smart_gallery.children_empty', 'No Smart Galleries exist yet.')) . '</p>';
    } else {
        echo '<input type="hidden" name="smart_gallery_children_present" value="1">';
        echo '<div class="admin-smart-gallery-current-groups" aria-label="' . e(t('smart_gallery.current_attachments', 'Current Smart Gallery attachments')) . '">';
        foreach (['top' => 'Above gallery content', 'bottom' => 'Below gallery content'] as $groupKey => $fallbackLabel) {
            echo '<section class="admin-smart-gallery-current-group"><h4>' . e(t('smart_gallery.placement_' . $groupKey, $fallbackLabel)) . '</h4>';
            $groupRows = array_values(array_filter($attachedRows, static fn (array $row): bool => ($row['placement'] ?? 'bottom') === $groupKey));
            if ($groupRows === []) {
                echo '<p class="muted">' . e(t('smart_gallery.attachment_group_empty', 'No Smart Galleries in this placement area.')) . '</p>';
            } else {
                echo '<ul class="admin-smart-gallery-current-list">';
                foreach ($groupRows as $attachedRow) {
                    $state = ($attachedRow['visibility'] ?? '') === 'public' && !empty($attachedRow['enabled']) ? t('smart_gallery.public', 'Published') : t('smart_gallery.not_public', 'Not publicly visible');
                    $diagnostic = empty($attachedRow['relationship_valid']) ? ' · ' . t('smart_gallery.relationship_invalid_short', 'relationship needs repair') : '';
                    echo '<li><strong>' . e((string) $attachedRow['title']) . '</strong><span>' . e(t('smart_gallery.order_value', 'order {order}', ['order' => (int) ($attachedRow['placement_order'] ?? 0)])) . ' · ' . e($state . $diagnostic) . '</span></li>';
                }
                echo '</ul>';
            }
            echo '</section>';
        }
        echo '</div>';
        echo '<h4>' . e(t('smart_gallery.attachment_settings', 'Attachment settings')) . '</h4><div class="admin-smart-gallery-child-list">';
        foreach ($smartGalleryDefinitions as $smartDefinition) {
            $smartId = (int) $smartDefinition['id'];
            $current = $attachedById[$smartId] ?? null;
            $assignedHere = is_array($current);
            $placement = $assignedHere ? (string) ($current['placement'] ?? 'bottom') : 'bottom';
            $placementOrder = $assignedHere ? (int) ($current['placement_order'] ?? 0) : 0;
            $visibilityState = ($smartDefinition['visibility'] ?? '') === 'public' && !empty($smartDefinition['enabled']) ? t('smart_gallery.public', 'Published') : t('smart_gallery.not_public', 'Not publicly visible');
            echo '<div class="admin-smart-gallery-child-row' . ($assignedHere ? ' is-attached' : '') . '">';
            echo '<label class="checkbox-label"><input type="checkbox" name="smart_gallery_children[' . $smartId . '][enabled]" value="1"' . ($assignedHere ? ' checked' : '') . '> <span><strong>' . e((string) $smartDefinition['title']) . '</strong><small>' . e($visibilityState) . '</small></span></label>';
            echo '<label>' . e(t('smart_gallery.attachment_placement', 'Placement for this parent')) . '<select name="smart_gallery_children[' . $smartId . '][placement]"><option value="top"' . ($placement === 'top' ? ' selected' : '') . '>' . e(t('smart_gallery.placement_top', 'Above gallery content')) . '</option><option value="bottom"' . ($placement === 'bottom' ? ' selected' : '') . '>' . e(t('smart_gallery.placement_bottom', 'Below gallery content')) . '</option></select></label>';
            echo '<label>' . e(t('smart_gallery.attachment_order', 'Order')) . '<input type="number" min="-100000" max="100000" name="smart_gallery_children[' . $smartId . '][placement_order]" value="' . $placementOrder . '"></label>';
            if ($assignedHere && empty($current['relationship_valid'])) echo '<p class="error">' . e(t('smart_gallery.relationship_invalid', 'This attachment participates in a recursive or malformed Smart Gallery relationship. Detach it or change the referenced gallery rule.')) . '</p>';
            echo '</div>';
        }
        echo '</div><p class="muted">' . e(t('smart_gallery.attachment_order_help', 'Order is evaluated separately within the Above and Below groups. Equal values are resolved deterministically by Smart Gallery ID.')) . '</p>';
    }
    echo '</div>';
}
