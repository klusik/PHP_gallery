<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_gallery_edit_tabs.php
 * Module Type: View
 *
 * Purpose:
 *   Renders narrow presentation fragments used by the Admin gallery editor tabs.
 *
 * Responsibilities:
 *   - Render the Media tab fields from controller-prepared presentation data
 *   - Render the upload API manager action without leaking markup into controllers
 *   - Keep gallery-editor tab presentation free of request, persistence, and policy lookups
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
 *   - Dynamic HTML fragments passed by controllers must already come from trusted project renderers.
 *   - Do not read request globals or call models/services from this view.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Services\t;

/**
 * Render the editable Media-tab field group.
 *
 * @param array<string, mixed> $viewModel Controller-prepared labels, state, and trusted option/branding fragments.
 */
function view_render_admin_gallery_media_fields(array $viewModel): void
{
    $labels = (array) ($viewModel['labels'] ?? []);
    $coverOptionsHtml = (string) ($viewModel['cover_options_html'] ?? '');
    $brandingFieldsHtml = (string) ($viewModel['branding_fields_html'] ?? '');
    $coverAssetSchemaReady = (bool) ($viewModel['cover_asset_schema_ready'] ?? false);
    $backgroundSourceSchemaReady = (bool) ($viewModel['background_source_schema_ready'] ?? false);
    $backgroundSource = $viewModel['background_source'] ?? null;

    echo '<div class="admin-edit-card-grid">';
    echo '<div class="admin-edit-card is-wide"><label>' . e((string) ($labels['title_picture'] ?? 'Title picture')) . '<select name="cover_image_id"><option value="0">' . e((string) ($labels['automatic'] ?? 'Automatic')) . '</option>' . $coverOptionsHtml . '</select><span class="muted">' . e((string) ($labels['includes_subgallery_images'] ?? 'Includes images from subgalleries.')) . '</span></label>';
    if ($coverAssetSchemaReady) {
        echo '<label>' . e((string) ($labels['upload_gallery_thumbnail'] ?? 'Upload gallery thumbnail')) . '<input type="file" name="cover_upload" accept="image/*"><span class="muted">' . e((string) ($labels['gallery_thumbnail_upload_help'] ?? 'This is stored separately from gallery images.')) . '</span></label>';
    } else {
        echo '<p class="muted">' . e((string) ($labels['gallery_thumbnail_migration_hidden'] ?? 'Uploadable gallery thumbnails will be available after the gallery thumbnail migration is applied.')) . '</p>';
    }
    echo '</div>';

    echo '<div class="admin-edit-card is-wide">' . $brandingFieldsHtml . '</div>';

    echo '<div class="admin-edit-card is-wide">';
    if ($backgroundSourceSchemaReady) {
        echo '<label>' . e((string) ($labels['background_source'] ?? 'Background source')) . '<select name="background_source">';
        echo '<option value=""' . ($backgroundSource === null ? ' selected' : '') . '>' . e((string) ($labels['use_theme_background'] ?? 'Use theme background')) . '</option>';
        echo '<option value="upload"' . ($backgroundSource === 'upload' ? ' selected' : '') . '>' . e((string) ($labels['upload_new_image'] ?? 'Upload new image')) . '</option>';
        echo '<option value="existing"' . ($backgroundSource === 'existing' ? ' selected' : '') . '>' . e((string) ($labels['pick_existing_gallery_images'] ?? 'Pick from existing gallery images')) . '</option>';
        echo '<option value="collage"' . ($backgroundSource === 'collage' ? ' selected' : '') . '>' . e((string) ($labels['generate_collage_public'] ?? 'Generate collage from public galleries')) . '</option>';
        echo '</select><span class="muted">' . e((string) ($labels['background_source_help'] ?? 'If unset, the gallery inherits the Theme background.')) . '</span></label>';
    } else {
        echo '<p class="muted">' . e((string) ($labels['background_migration_hidden'] ?? 'Background source selection will be available after the background migration is applied.')) . '</p>';
    }
    echo '</div></div>';
}

/**
 * Render the Admin upload API manager shortcut.
 *
 * @param array<string, mixed> $viewModel Controller-prepared URL and label.
 */
function view_render_admin_upload_automation_manager_action(array $viewModel): void
{
    echo '<div class="admin-upload-automation-actions"><a class="button secondary" href="' . e((string) ($viewModel['href'] ?? '')) . '">' . e((string) ($viewModel['label'] ?? 'Open API manager')) . '</a></div>';
}

/**
 * Render the editable Access-tab field group.
 *
 * @param array<string, mixed> $viewModel Controller-prepared access state, labels, and trusted option fragments.
 * @return void Render the access fields.
 */
function view_render_admin_gallery_access_fields(array $viewModel): void
{
    $labels = (array) ($viewModel['labels'] ?? []);
    $accessReady = (bool) ($viewModel['access_ready'] ?? false);
    $shareTokenReady = (bool) ($viewModel['share_token_ready'] ?? false);
    $hasPassword = (bool) ($viewModel['has_password'] ?? false);
    $hasShareTokenHash = (bool) ($viewModel['has_share_token_hash'] ?? false);
    $currentAccessType = (string) ($viewModel['current_access_type'] ?? 'normal');
    $shareUrl = $viewModel['share_url'] ?? null;
    $shareLabel = $viewModel['share_label'] ?? null;
    $shareUnavailableMessage = $viewModel['share_unavailable_message'] ?? null;
    $shareStorageMessage = (string) ($viewModel['share_storage_message'] ?? '');
    $accessUnavailableMessage = $viewModel['access_unavailable_message'] ?? null;
    $nsfwState = (string) ($viewModel['nsfw_state'] ?? 'missing');

    echo '<div class="admin-access-compact"><div class="admin-access-main">';
    echo '<label>' . e((string) ($labels['visibility'] ?? 'Visibility')) . '<select name="visibility">' . (string) ($viewModel['visibility_options_html'] ?? '') . '</select></label>';
    if ($accessReady) {
        echo '<label>' . e((string) ($labels['password_lock'] ?? 'Password lock')) . '<select name="access_type"><option value="normal"' . ($currentAccessType === 'normal' ? ' selected' : '') . '>' . e((string) ($labels['no_password'] ?? 'No password')) . '</option><option value="password"' . ($currentAccessType === 'password' ? ' selected' : '') . '>' . e((string) ($labels['require_password'] ?? 'Require password')) . '</option></select></label>';
        echo '<label>' . e((string) ($labels['new_gallery_password'] ?? 'New gallery password')) . '<input name="access_password" type="password" autocomplete="new-password" placeholder="' . e((string) ($labels['keep_password_help'] ?? 'Leave empty to keep the current gallery password.')) . '"></label>';
    }
    echo '</div><div class="admin-access-inline-options">';
    if ($accessReady && $hasPassword) {
        echo '<label class="checkbox-label"><input type="checkbox" name="clear_access_password" value="1"> ' . e((string) ($labels['clear_password'] ?? 'Clear current gallery password')) . '</label>';
    }
    if ($nsfwState === 'available') {
        echo '<input type="hidden" name="nsfw_field_present" value="1"><label class="checkbox-label"><input type="checkbox" name="nsfw_enabled" value="1"' . ((bool) ($viewModel['nsfw_enabled'] ?? false) ? ' checked' : '') . '> ' . e((string) ($labels['mark_nsfw'] ?? 'Mark as NSFW / 18+')) . '</label>';
    }
    echo '<details class="admin-inline-help"><summary aria-label="' . e(t('admin.gallery_editor.access_help_label', 'About visibility and protection')) . '" title="' . e(t('admin.gallery_editor.access_help_label', 'About visibility and protection')) . '"><span aria-hidden="true">?</span></summary><div class="admin-inline-help-content"><p>' . e((string) ($labels['visibility_help'] ?? '')) . '</p><p>' . e((string) ($labels['password_lock_help'] ?? '')) . '</p>';
    if ($nsfwState === 'available') {
        echo '<p>' . e((string) ($labels['nsfw_help'] ?? '')) . '</p>';
    }
    echo '<p>' . e((string) ($labels['non_expiring_link_help'] ?? '')) . '</p>';
    if ($shareStorageMessage !== '') {
        echo '<p>' . e($shareStorageMessage) . '</p>';
    }
    echo '</div></details></div>';
    if (!$accessReady && is_string($accessUnavailableMessage) && $accessUnavailableMessage !== '') {
        echo '<div class="notice">' . e($accessUnavailableMessage) . '</div>';
    }
    if ($nsfwState !== 'available') {
        echo '<div class="notice">' . e((string) ($labels[$nsfwState === 'unknown' ? 'nsfw_inspection_failed' : 'nsfw_migration_hidden'] ?? '')) . '</div>';
    }

    if ($accessReady) {
        echo '<div class="admin-access-share"><div class="admin-access-share-row"><label>' . e((string) ($labels['share_link_expiry'] ?? 'Share link expiry')) . '<input name="access_token_expires_at" type="datetime-local" value="' . e((string) ($viewModel['share_expiry_value'] ?? '')) . '"></label><div class="admin-access-share-actions">';
        if ($shareTokenReady) {
            echo '<button type="submit" class="secondary" name="access_action" value="generate_link">' . e((string) ($labels['generate_regenerate_share_link'] ?? 'Generate/regenerate share link')) . '</button>';
        }
        if ($hasShareTokenHash) {
            echo '<button type="submit" class="secondary" name="access_action" value="revoke_link">' . e((string) ($labels['revoke_share_link'] ?? 'Revoke share link')) . '</button>';
        }
        echo '</div></div>';
        if (is_string($shareUrl) && $shareUrl !== '' && is_string($shareLabel) && $shareLabel !== '') {
            echo '<label class="admin-access-share-url">' . e($shareLabel) . '<input readonly value="' . e($shareUrl) . '"></label>';
        } elseif (is_string($shareUnavailableMessage) && $shareUnavailableMessage !== '') {
            echo '<p class="muted">' . e($shareUnavailableMessage) . '</p>';
        } else {
            echo '<p class="muted admin-access-share-empty">' . e((string) ($labels['no_active_share_link'] ?? 'No share link is active.')) . '</p>';
        }
        if (!$shareTokenReady && $shareStorageMessage !== '') {
            echo '<div class="notice">' . e($shareStorageMessage) . '</div>';
        }
        echo '</div>';
    }
    echo '</div>';
}

/**
 * Render the Identity tab from controller-prepared presentation data.
 *
 * @param array<string, mixed> $viewModel Controller-prepared values and trusted legacy fragments.
 * @return void Emits Identity fields and the controller-prepared parent picker fragment.
 * @author Rudolf Klusal
 */
function view_render_admin_gallery_identity_tab(array $viewModel): void
{
    $labels = (array) ($viewModel['labels'] ?? []);
    $gallery = (array) ($viewModel['gallery'] ?? []);
    $smartAttachments = $viewModel['smart_attachments'] ?? null;

    ob_start();
    echo '<div class="admin-edit-card-grid">';
    echo '<div class="admin-edit-card is-wide"><label>' . e((string) ($labels['title'] ?? 'Title')) . '<input name="title" value="' . e((string) ($gallery['title'] ?? '')) . '" autocomplete="off" required></label>';
    echo '<div class="admin-editor-description-field"><label><span>' . e((string) ($labels['description'] ?? 'Description')) . '</span><textarea name="description" data-gallery-description-textarea data-openai-description-textarea>' . e((string) ($gallery['description'] ?? '')) . '</textarea></label>';
    echo (string) ($viewModel['description_hint_html'] ?? '') . '</div>';
    echo '<div class="admin-editor-date">' . (string) ($viewModel['date_fields_html'] ?? '') . '</div>';
    echo (string) ($viewModel['localization_html'] ?? '');
    echo (string) ($viewModel['simbrief_html'] ?? '');
    echo '<label>' . e((string) ($labels['tags'] ?? 'Tags')) . '<input name="tags" value="' . e((string) ($viewModel['tags'] ?? '')) . '" list="tag-suggestions" data-tag-input' . (string) ($viewModel['tag_suggestions_attribute'] ?? '') . '><span class="muted">' . e((string) ($labels['tags_help'] ?? '')) . '</span></label>';
    echo '</div>';

    $advancedSummary = trim((string) ($gallery['slug'] ?? ''));
    $folderSummary = trim((string) ($viewModel['folder_name'] ?? ''));
    if ($folderSummary !== '' && $folderSummary !== $advancedSummary) {
        $advancedSummary .= ($advancedSummary !== '' ? ' · ' : '') . $folderSummary;
    }
    echo '<details class="admin-edit-card is-wide admin-gallery-advanced-settings"><summary>' . e(t('admin.gallery_editor.advanced_settings', 'Advanced gallery settings')) . ($advancedSummary !== '' ? ' <span class="muted admin-gallery-advanced-summary">' . e($advancedSummary) . '</span>' : '') . '</summary><div class="admin-edit-card-grid">';
    echo (string) ($viewModel['openai_html'] ?? '');
    echo '<div class="admin-edit-card"><label>' . e((string) ($labels['slug'] ?? 'Slug')) . '<input name="slug" value="' . e((string) ($gallery['slug'] ?? '')) . '" autocomplete="off" required><span class="muted">' . e((string) ($labels['slug_help'] ?? '')) . '</span></label><label>' . e((string) ($labels['folder_name'] ?? 'Folder name')) . '<input name="folder_name" value="' . e((string) ($viewModel['folder_name'] ?? '')) . '" autocomplete="off" required><span class="muted">' . e((string) ($labels['folder_rename_help'] ?? '')) . '</span></label></div>';
    echo '<div class="admin-edit-card"><div>' . e((string) ($labels['parent_gallery'] ?? 'Parent gallery')) . (string) ($viewModel['parent_picker_html'] ?? '') . '</div><label>' . e((string) ($labels['sort_order'] ?? 'Sort order')) . '<input name="sort_order" type="number" value="' . (int) ($gallery['sort_order'] ?? 0) . '"></label></div>';
    if (is_array($smartAttachments)) {
        view_render_admin_gallery_smart_attachments($smartAttachments);
    }
    echo '</div></details></div>';
    echo (string) ($viewModel['tag_datalist_html'] ?? '');

    view_render_admin_tab_panel(
        'admin-edit-identity',
        (string) ob_get_clean(),
        (bool) ($viewModel['active'] ?? false)
    );
}

/**
 * Render Smart Gallery attachment controls from controller-prepared rows.
 *
 * @param array<string, mixed> $viewModel Controller-prepared attachment state and labels.
 */
function view_render_admin_gallery_smart_attachments(array $viewModel): void
{
    $labels = (array) ($viewModel['labels'] ?? []);
    $groups = (array) ($viewModel['groups'] ?? []);
    $definitions = (array) ($viewModel['definitions'] ?? []);

    echo '<div class="admin-edit-card is-wide"><h3>' . e((string) ($labels['title'] ?? 'Smart Gallery attachments')) . '</h3><p class="muted">' . e((string) ($labels['help'] ?? '')) . '</p>';
    if (!(bool) ($viewModel['metadata_ready'] ?? false)) {
        echo (string) ($viewModel['migration_notice_html'] ?? '');
    } elseif ($definitions === []) {
        echo '<p class="muted">' . e((string) ($labels['empty'] ?? 'No Smart Galleries exist yet.')) . '</p>';
    } else {
        echo '<input type="hidden" name="smart_gallery_children_present" value="1">';
        echo '<div class="admin-smart-gallery-current-groups" aria-label="' . e((string) ($labels['current_attachments'] ?? 'Current Smart Gallery attachments')) . '">';
        foreach ($groups as $group) {
            $rows = (array) ($group['rows'] ?? []);
            echo '<section class="admin-smart-gallery-current-group"><h4>' . e((string) ($group['label'] ?? '')) . '</h4>';
            if ($rows === []) {
                echo '<p class="muted">' . e((string) ($labels['group_empty'] ?? 'No Smart Galleries in this placement area.')) . '</p>';
            } else {
                echo '<ul class="admin-smart-gallery-current-list">';
                foreach ($rows as $row) {
                    echo '<li><strong>' . e((string) ($row['title'] ?? '')) . '</strong><span>' . e((string) ($row['status'] ?? '')) . '</span></li>';
                }
                echo '</ul>';
            }
            echo '</section>';
        }
        echo '</div>';
        echo '<h4>' . e((string) ($labels['settings'] ?? 'Attachment settings')) . '</h4><div class="admin-smart-gallery-child-list">';
        foreach ($definitions as $definition) {
            $smartId = (int) ($definition['id'] ?? 0);
            $assigned = (bool) ($definition['assigned'] ?? false);
            $placement = (string) ($definition['placement'] ?? 'bottom');
            echo '<div class="admin-smart-gallery-child-row' . ($assigned ? ' is-attached' : '') . '">';
            echo '<label class="checkbox-label"><input type="checkbox" name="smart_gallery_children[' . $smartId . '][enabled]" value="1"' . ($assigned ? ' checked' : '') . '> <span><strong>' . e((string) ($definition['title'] ?? '')) . '</strong><small>' . e((string) ($definition['visibility_state'] ?? '')) . '</small></span></label>';
            echo '<label>' . e((string) ($labels['placement'] ?? 'Placement for this parent')) . '<select name="smart_gallery_children[' . $smartId . '][placement]"><option value="top"' . ($placement === 'top' ? ' selected' : '') . '>' . e((string) ($labels['placement_top'] ?? 'Above gallery content')) . '</option><option value="bottom"' . ($placement === 'bottom' ? ' selected' : '') . '>' . e((string) ($labels['placement_bottom'] ?? 'Below gallery content')) . '</option></select></label>';
            echo '<label>' . e((string) ($labels['order'] ?? 'Order')) . '<input type="number" min="-100000" max="100000" name="smart_gallery_children[' . $smartId . '][placement_order]" value="' . (int) ($definition['placement_order'] ?? 0) . '"></label>';
            if ($assigned && !(bool) ($definition['relationship_valid'] ?? true)) {
                echo '<p class="error">' . e((string) ($labels['relationship_invalid'] ?? '')) . '</p>';
            }
            echo '</div>';
        }
        echo '</div><p class="muted">' . e((string) ($labels['order_help'] ?? '')) . '</p>';
    }
    echo '</div>';
}

/**
 * Render a compact explanation beside a Display field.
 *
 * @param string $label Field label used for the disclosure's accessible name.
 * @param string $help Controller-prepared explanatory text.
 * @return void Emit the help disclosure when text is available.
 */
function view_render_admin_gallery_display_help(string $label, string $help): void
{
    if (trim($help) === '') {
        return;
    }
    $helpLabel = t('admin.gallery_editor.help_for', 'Help for {label}', ['label' => $label]);
    echo '<details class="admin-inline-help"><summary aria-label="' . e($helpLabel) . '" title="' . e($helpLabel) . '"><span aria-hidden="true">?</span></summary><div class="admin-inline-help-content">' . e($help) . '</div></details>';
}

/**
 * Render the Display tab with the grid first and infrequent settings collapsed.
 *
 * @param array<string, mixed> $viewModel Controller-prepared display settings and labels.
 * @return void Render the Display tab and all form controls.
 */
function view_render_admin_gallery_display_tab(array $viewModel): void
{
    ob_start();
    echo '<div class="admin-display-layout">';

    $grid = (array) ($viewModel['grid'] ?? []);
    if ((bool) ($grid['ready'] ?? false)) {
        $gridTitle = (string) ($grid['title'] ?? 'Display grid');
        echo '<section class="admin-display-grid-primary"><div class="admin-display-section-head"><h3>' . e($gridTitle) . '</h3>';
        view_render_admin_gallery_display_help($gridTitle, trim((string) ($grid['source_text'] ?? '') . ' ' . (string) ($grid['help'] ?? '')));
        echo '</div><label class="checkbox-label"><input type="checkbox" name="grid_override_enabled" value="1" data-gallery-grid-override-enabled' . ((bool) ($grid['override_enabled'] ?? false) ? ' checked' : '') . '> ' . e((string) ($grid['override_label'] ?? '')) . '</label>';
        echo '<div class="admin-edit-range-grid"><label>' . e((string) ($grid['columns_label'] ?? '')) . ' <span class="muted" data-gallery-grid-columns-display>' . (int) ($grid['columns'] ?? 1) . '</span><input type="range" name="grid_columns" min="1" max="' . (int) ($grid['max_columns'] ?? 1) . '" value="' . (int) ($grid['columns'] ?? 1) . '" data-gallery-grid-columns></label>';
        echo '<label>' . e((string) ($grid['rows_label'] ?? '')) . ' <span class="muted" data-gallery-grid-rows-display>' . (int) ($grid['rows'] ?? 1) . '</span><input type="range" name="grid_rows" min="1" max="' . (int) ($grid['max_rows'] ?? 1) . '" value="' . (int) ($grid['rows'] ?? 1) . '" data-gallery-grid-rows></label></div>';
        echo '<div class="admin-display-grid-footer"><label class="checkbox-label"><input type="checkbox" name="grid_use_for_subgalleries" value="1"' . ((bool) ($grid['use_for_subgalleries'] ?? true) ? ' checked' : '') . '> ' . e((string) ($grid['recursive_label'] ?? '')) . '</label><span class="muted">' . e((string) ($grid['source_text'] ?? '')) . '</span></div></section>';
    } else {
        echo '<div class="notice">' . e((string) ($grid['migration_message'] ?? '')) . '</div>';
    }

    $voting = (array) ($viewModel['voting'] ?? []);
    $filenames = (array) ($viewModel['filenames'] ?? []);
    if ((bool) ($voting['visible'] ?? false) || (bool) ($filenames['ready'] ?? false)) {
        echo '<div class="admin-display-quick-options">';
        if ((bool) ($voting['visible'] ?? false)) {
            $label = (string) ($voting['label'] ?? '');
            echo '<div class="admin-display-quick-option"><label class="checkbox-label"><input type="checkbox" name="voting_enabled" value="1"' . ((bool) ($voting['checked'] ?? false) ? ' checked' : '') . '> ' . e($label) . '</label>';
            view_render_admin_gallery_display_help($label, (string) ($voting['help'] ?? ''));
            echo '</div>';
        }
        if ((bool) ($filenames['ready'] ?? false)) {
            $label = (string) ($filenames['label'] ?? '');
            echo '<div class="admin-display-quick-option"><label class="checkbox-label"><input type="checkbox" name="show_filenames" value="1"' . ((bool) ($filenames['checked'] ?? false) ? ' checked' : '') . '> ' . e($label) . '</label>';
            view_render_admin_gallery_display_help($label, (string) ($filenames['help'] ?? ''));
            echo '</div>';
        }
        echo '</div>';
    }

    echo '<details class="admin-display-advanced"><summary>' . e((string) ($viewModel['advanced_label'] ?? 'Advanced display settings')) . '</summary><div class="admin-display-advanced-content"><div class="admin-display-advanced-options">';

    $gps = (array) ($viewModel['gps'] ?? []);
    if (($gps['state'] ?? 'hidden') === 'override') {
        $currentMode = (string) ($gps['current_mode'] ?? 'inherit');
        $label = (string) ($gps['label'] ?? '');
        echo '<div class="admin-display-option"><label><span>' . e($label) . '</span><select name="gps_map_enabled">';
        foreach ((array) ($gps['options'] ?? []) as $option) {
            $value = (string) ($option['value'] ?? '');
            echo '<option value="' . e($value) . '"' . ($currentMode === $value ? ' selected' : '') . '>' . e((string) ($option['label'] ?? '')) . '</option>';
        }
        echo '</select></label>';
        view_render_admin_gallery_display_help($label, (string) ($gps['help'] ?? ''));
        echo '</div>';
    } elseif (($gps['state'] ?? 'hidden') === 'legacy') {
        $label = (string) ($gps['legacy_label'] ?? '');
        echo '<div class="admin-display-option admin-display-option-checkbox"><label class="checkbox-label"><input type="checkbox" name="gps_map_enabled" value="1"' . ((bool) ($gps['checked'] ?? false) ? ' checked' : '') . '> ' . e($label) . '</label>';
        view_render_admin_gallery_display_help($label, (string) ($gps['legacy_help'] ?? ''));
        echo '</div>';
    }

    $descriptionLayout = (array) ($viewModel['description_layout'] ?? []);
    if ((bool) ($descriptionLayout['ready'] ?? false)) {
        $current = $descriptionLayout['current'] ?? null;
        $label = (string) ($descriptionLayout['label'] ?? '');
        echo '<div class="admin-display-option"><label><span>' . e($label) . '</span><select name="description_layout"><option value="inherit"' . ($current === null ? ' selected' : '') . '>' . e((string) ($descriptionLayout['inherit_label'] ?? '')) . '</option>';
        foreach ((array) ($descriptionLayout['options'] ?? []) as $option) {
            $value = (string) ($option['value'] ?? '');
            echo '<option value="' . e($value) . '"' . ($current === $value ? ' selected' : '') . '>' . e((string) ($option['label'] ?? '')) . '</option>';
        }
        echo '</select></label>';
        view_render_admin_gallery_display_help($label, (string) ($descriptionLayout['help'] ?? ''));
        echo '</div>';
    }

    $countBadge = (array) ($viewModel['count_badge'] ?? []);
    if ((bool) ($countBadge['ready'] ?? false)) {
        $current = (string) ($countBadge['current'] ?? 'inherit');
        $label = (string) ($countBadge['label'] ?? '');
        echo '<div class="admin-display-option"><label><span>' . e($label) . '</span><select name="count_badge_visibility">';
        foreach ((array) ($countBadge['options'] ?? []) as $option) {
            $value = (string) ($option['value'] ?? '');
            echo '<option value="' . e($value) . '"' . ($current === $value ? ' selected' : '') . '>' . e((string) ($option['label'] ?? '')) . '</option>';
        }
        echo '</select></label>';
        view_render_admin_gallery_display_help($label, (string) ($countBadge['help'] ?? ''));
        echo '</div>';
    }

    $lightbox = (array) ($viewModel['lightbox'] ?? []);
    if (($lightbox['state'] ?? 'hidden') === 'ready') {
        $current = (string) ($lightbox['current'] ?? 'inherit');
        $label = (string) ($lightbox['label'] ?? '');
        echo '<div class="admin-display-option"><label><span>' . e($label) . '</span><select name="lightbox_browsing_mode"><option value="inherit"' . ($current === 'inherit' ? ' selected' : '') . '>' . e((string) ($lightbox['inherit_label'] ?? '')) . '</option>';
        foreach ((array) ($lightbox['options'] ?? []) as $option) {
            $value = (string) ($option['value'] ?? '');
            echo '<option value="' . e($value) . '"' . ($current === $value ? ' selected' : '') . '>' . e((string) ($option['label'] ?? '')) . '</option>';
        }
        echo '</select></label>';
        view_render_admin_gallery_display_help($label, (string) ($lightbox['help'] ?? ''));
        echo '</div>';
    }
    echo '</div>';

    foreach ([$filenames, $descriptionLayout, $countBadge, $lightbox] as $item) {
        $message = (string) ($item['migration_message'] ?? '');
        if ($message !== '' && !(bool) ($item['ready'] ?? false) && ($item['state'] ?? 'migration') === 'migration') {
            echo '<p class="muted admin-display-unavailable">' . e($message) . '</p>';
        }
    }

    $pictureGame = (array) ($viewModel['picture_game'] ?? []);
    if ((bool) ($pictureGame['visible'] ?? false)) {
        echo '<div class="admin-display-feature-row"><label class="checkbox-label"><input type="checkbox" name="picture_game_enabled" value="1"' . ((bool) ($pictureGame['checked'] ?? false) ? ' checked' : '') . '> ' . e((string) ($pictureGame['label'] ?? '')) . '</label></div>';
    }

    $thumbnailBounds = (array) ($viewModel['thumbnail_bounds'] ?? []);
    if ((bool) ($thumbnailBounds['ready'] ?? false)) {
        $title = (string) ($thumbnailBounds['title'] ?? 'Responsive thumbnail quality bounds');
        echo '<details class="admin-display-subsection"><summary>' . e($title) . '</summary><div class="admin-display-subsection-content">';
        view_render_admin_gallery_display_help($title, (string) ($thumbnailBounds['help'] ?? ''));
        echo (string) ($thumbnailBounds['control_html'] ?? '');
        echo '<div class="admin-display-quick-option"><label class="checkbox-label"><input type="checkbox" name="gallery_thumbnail_bounds_recursive" value="1"> ' . e((string) ($thumbnailBounds['recursive_label'] ?? '')) . '</label>';
        view_render_admin_gallery_display_help((string) ($thumbnailBounds['recursive_label'] ?? ''), (string) ($thumbnailBounds['recursive_help'] ?? ''));
        echo '</div></div></details>';
    } elseif (($thumbnailBounds['migration_message'] ?? '') !== '') {
        echo '<p class="muted admin-display-unavailable">' . e((string) $thumbnailBounds['migration_message']) . '</p>';
    }

    $flightMap = (array) ($viewModel['flight_map'] ?? []);
    if (($flightMap['state'] ?? 'hidden') === 'ready') {
        $title = (string) ($flightMap['title'] ?? 'Flight route map');
        echo '<details class="admin-display-subsection"><summary>' . e($title);
        if (trim((string) ($flightMap['route_text'] ?? '')) !== '') {
            echo ' <span class="muted">' . e((string) ($flightMap['status'] ?? '')) . '</span>';
        }
        echo '</summary><div class="admin-display-subsection-content"><div class="admin-display-option admin-display-route-field"><label><span>' . e((string) ($flightMap['label'] ?? '')) . '</span><textarea name="flight_route_text" rows="3" placeholder="LKPR DCT OKL DCT EDDF or LKPR@50.1008,14.2632 DCT EDDF@50.0379,8.5622">' . e((string) ($flightMap['route_text'] ?? '')) . '</textarea></label>';
        view_render_admin_gallery_display_help($title, (string) ($flightMap['help'] ?? ''));
        echo '</div></div></details>';
    } elseif (($flightMap['state'] ?? 'hidden') === 'migration') {
        echo '<p class="muted admin-display-unavailable">' . e((string) ($flightMap['migration_message'] ?? '')) . '</p>';
    }

    echo '</div></details></div>';
    view_render_admin_tab_panel('admin-edit-display', (string) ob_get_clean(), (bool) ($viewModel['active'] ?? false));
}

/**
 * Render the Images tab from controller-prepared rows and URLs.
 *
 * @param array<string, mixed> $viewModel Controller-prepared image table presentation data.
 */
function view_render_admin_gallery_images_tab(array $viewModel): void
{
    $labels = (array) ($viewModel['labels'] ?? []);
    $intro = (array) ($viewModel['intro'] ?? []);
    $scanAction = (array) ($viewModel['scan_action'] ?? []);
    if ((string) ($scanAction['url'] ?? '') !== '') {
        ob_start();
        echo '<form method="post" action="' . e((string) $scanAction['url']) . '" data-admin-panel-scan-images-form>' . (string) ($viewModel['csrf_html'] ?? '');
        echo '<input type="hidden" name="gallery_id" value="' . (int) ($viewModel['gallery_id'] ?? 0) . '"><button type="submit" class="secondary">' . e((string) ($scanAction['label'] ?? '')) . '</button></form>';
        $intro['actions_html'] = (string) ob_get_clean();
    }

    ob_start();
    view_render_admin_tab_intro($intro);
    echo '<form method="post" action="' . e((string) ($viewModel['bulk_action_url'] ?? '')) . '" data-admin-image-bulk-form>' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<input type="hidden" name="gallery_id" value="' . (int) ($viewModel['gallery_id'] ?? 0) . '">';
    echo '<input type="hidden" name="return_tab" value="admin-edit-images">';
    echo (string) ($viewModel['bulk_toolbar_html'] ?? '');
    echo '<div class="admin-image-order-toolbar" data-admin-image-order-toolbar data-reorder-url="' . e((string) ($viewModel['reorder_url'] ?? '')) . '"><p class="muted">' . e((string) ($labels['drag_help'] ?? '')) . '</p><span class="admin-image-order-status" data-admin-image-order-status aria-live="polite">' . e((string) ($labels['order_unchanged'] ?? '')) . '</span></div>';
    echo '<table class="admin-image-order-table" data-admin-image-order-table><thead><tr><th>' . e((string) ($labels['move'] ?? 'Move')) . '</th><th>' . e((string) ($labels['select'] ?? 'Select')) . '</th><th>' . e((string) ($labels['preview'] ?? 'Preview')) . '</th><th aria-sort="none"><button type="button" class="admin-image-name-sort" data-admin-image-name-sort data-sort-direction="asc" aria-label="' . e((string) ($labels['sort_a_z'] ?? '')) . '">' . e((string) ($labels['name'] ?? 'Name')) . ' <span aria-hidden="true">↕</span></button></th><th title="' . e((string) ($labels['file_names_shown'] ?? '')) . '">N</th><th>' . e((string) ($labels['status'] ?? 'Status')) . '</th><th>' . e((string) ($labels['cover'] ?? 'Cover')) . '</th><th>' . e((string) ($labels['actions'] ?? 'Actions')) . '</th></tr></thead><tbody>';
    foreach ((array) ($viewModel['images'] ?? []) as $image) {
        $imageId = (int) ($image['id'] ?? 0);
        $relativePath = (string) ($image['relative_path'] ?? '');
        echo '<tr data-admin-image-order-row data-image-id="' . $imageId . '" data-image-name="' . e($relativePath) . '"><td class="admin-image-order-cell"><span class="admin-image-drag-handle" data-admin-image-drag-handle role="button" tabindex="0" aria-label="' . e((string) ($image['move_aria'] ?? '')) . '" title="' . e((string) ($labels['drag_title'] ?? '')) . '">↕</span></td><td><input type="checkbox" name="image_ids[]" value="' . $imageId . '"></td>';
        echo '<td><img class="admin-thumb" decoding="async" loading="lazy" src="' . e((string) ($image['thumbnail_url'] ?? '')) . '" alt=""></td>';
        echo '<td data-admin-image-name-cell>' . e($relativePath) . '</td><td>' . (string) ($viewModel['filename_flag_html'] ?? '') . '</td><td>' . e((string) ($image['visibility'] ?? '')) . '</td><td data-admin-image-cover-cell>' . e((string) ($image['cover_label'] ?? '')) . '</td><td><a href="' . e((string) ($image['edit_url'] ?? '')) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="image-edit" data-admin-side-panel-kicker="' . e((string) ($labels['photo_editor'] ?? '')) . '" data-admin-side-panel-title="' . e((string) ($labels['edit_photo'] ?? '')) . '" data-gallery-side-panel-url="' . e((string) ($image['panel_url'] ?? '')) . '">' . e((string) ($labels['edit'] ?? 'Edit')) . '</a> <button type="submit" class="secondary danger inline-admin-action" name="action" value="delete:' . $imageId . '" data-admin-image-delete-single data-image-id="' . $imageId . '" data-image-name="' . e($relativePath) . '">' . e((string) ($labels['delete'] ?? 'Delete')) . '</button></td></tr>';
    }
    echo '</tbody></table></form>';
    view_render_admin_tab_panel('admin-edit-images', (string) ob_get_clean(), (bool) ($viewModel['active'] ?? false));
}

/**
 * Render the opening tag and hidden transport fields for the shared gallery editor form.
 *
 * @param array{csrf_html?:string,gallery_id?:int,edit_revision?:string} $viewModel Transport fields from the same snapshot as the rendered editor values.
 * @return void Emits the multipart form opening and hidden identity/revision fields.
 * @author Rudolf Klusal
 */
function view_render_admin_gallery_editor_form_open(array $viewModel): void
{
    echo '<form method="post" enctype="multipart/form-data" class="admin-edit-gallery-form" autocomplete="off" data-admin-gallery-settings-form>' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<input type="hidden" name="id" value="' . (int) ($viewModel['gallery_id'] ?? 0) . '">';
    echo '<input type="hidden" name="edit_revision" value="' . e((string) ($viewModel['edit_revision'] ?? '')) . '">';
    echo '<input type="hidden" name="return_tab" value="admin-edit-identity">';
}

/**
 * Render the save bar and closing tag for the shared gallery editor form.
 *
 * @param array<string, mixed> $viewModel Controller-prepared labels.
 * @return void Emits the persistent save control and closes the shared settings form.
 */
function view_render_admin_gallery_editor_form_close(array $viewModel): void
{
    echo '<div class="admin-edit-gallery-savebar" data-admin-gallery-savebar><button type="submit">' . e((string) ($viewModel['save_label'] ?? 'Save gallery')) . '</button><span class="muted">' . e((string) ($viewModel['help'] ?? '')) . '</span></div>';
    echo '</form>';
}

/**
 * Render a no-JavaScript conflict review without merging or resubmitting stale settings.
 *
 * @param array{message:string,reload_url:string,latest:array,draft:array,labels:array<string,string>} $viewModel Prepared secret-free comparison and entered draft.
 * @return void
 */
function view_render_admin_gallery_edit_conflict(array $viewModel): void
{
    $labels = $viewModel['labels'];
    echo '<section class="admin-edit-card" data-gallery-edit-conflict><p role="alert">' . e($viewModel['message']) . '</p>';
    echo '<p>' . e($labels['help']) . '</p><a class="button" href="' . e($viewModel['reload_url']) . '" target="_blank" rel="noopener">' . e($labels['open']) . '</a>';
    foreach (['draft', 'latest'] as $section) {
        echo '<h2>' . e($labels[$section]) . '</h2>';
        foreach ($viewModel[$section] as $field => $value) {
            $text = is_array($value) ? (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : (string) $value;
            echo '<label>' . e((string) $field) . '<textarea readonly rows="3">' . e($text) . '</textarea></label>';
        }
    }
    echo '</section>';
}

/**
 * Render one-time gallery-editor notices prepared by the controller.
 *
 * @param array<string, mixed> $viewModel Controller-prepared notice messages and trusted migration fragment.
 */
function view_render_admin_gallery_editor_notices(array $viewModel): void
{
    foreach ((array) ($viewModel['messages'] ?? []) as $message) {
        $message = (string) $message;
        if ($message !== '') {
            echo '<div class="notice">' . e($message) . '</div>';
        }
    }
    echo (string) ($viewModel['migration_notice_html'] ?? '');
}
