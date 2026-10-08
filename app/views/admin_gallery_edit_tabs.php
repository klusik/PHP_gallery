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
 * @return void Emit Media controls and their compact help disclosures.
 */
function view_render_admin_gallery_media_fields(array $viewModel): void
{
    $labels = (array) ($viewModel['labels'] ?? []);
    $coverOptionsHtml = (string) ($viewModel['cover_options_html'] ?? '');
    $brandingFieldsHtml = (string) ($viewModel['branding_fields_html'] ?? '');
    $coverAssetSchemaReady = (bool) ($viewModel['cover_asset_schema_ready'] ?? false);
    $backgroundSourceSchemaReady = (bool) ($viewModel['background_source_schema_ready'] ?? false);
    $backgroundSource = $viewModel['background_source'] ?? null;

    $mediaTitle = (string) ($labels['media_title'] ?? t('admin.gallery_editor.media_title', 'Thumbnail, branding, and background'));
    $mediaHelp = (string) ($labels['media_help'] ?? t('admin.gallery_editor.media_help', 'Optional visual assets override theme fallbacks only for this gallery.'));
    echo '<div class="admin-gallery-media-heading"><div><p class="admin-kicker">' . e(t('admin.gallery_editor.media_kicker', 'Media')) . '</p><h2>' . e($mediaTitle) . '</h2></div>';
    view_render_admin_gallery_display_help($mediaTitle, $mediaHelp);
    echo '</div>';

    echo '<div class="admin-edit-card-grid admin-gallery-media-workspace">';
    $coverHelp = (string) ($labels['includes_subgallery_images'] ?? 'Includes images from subgalleries.');
    echo '<section class="admin-edit-card admin-gallery-media-card admin-gallery-media-cover" aria-label="' . e((string) ($labels['title_picture'] ?? 'Title picture')) . '"><div class="admin-gallery-media-field"><label>' . e((string) ($labels['title_picture'] ?? 'Title picture')) . '<select name="cover_image_id"><option value="0">' . e((string) ($labels['automatic'] ?? 'Automatic')) . '</option>' . $coverOptionsHtml . '</select></label><details class="admin-inline-help admin-gallery-media-help"><summary aria-label="' . e($coverHelp) . '" title="' . e($coverHelp) . '"><span aria-hidden="true">?</span></summary><div class="admin-inline-help-content">' . e($coverHelp) . '</div></details></div>';
    if ($coverAssetSchemaReady) {
        echo '<label>' . e((string) ($labels['upload_gallery_thumbnail'] ?? 'Upload gallery thumbnail')) . '<input type="file" name="cover_upload" accept="image/*"><span class="muted">' . e((string) ($labels['gallery_thumbnail_upload_help'] ?? 'This is stored separately from gallery images.')) . '</span></label>';
    } else {
        echo '<p class="muted">' . e((string) ($labels['gallery_thumbnail_migration_hidden'] ?? 'Uploadable gallery thumbnails will be available after the gallery thumbnail migration is applied.')) . '</p>';
    }
    echo '</section>';

    echo '<section class="admin-edit-card admin-gallery-media-card admin-gallery-media-branding" aria-label="' . e(t('admin.gallery_editor.gallery_branding', 'Gallery branding')) . '">' . $brandingFieldsHtml . '</section>';

    echo '<section class="admin-edit-card admin-gallery-media-card admin-gallery-media-background" aria-label="' . e((string) ($labels['background_source'] ?? 'Background source')) . '">';
    if ($backgroundSourceSchemaReady) {
        echo '<div class="admin-gallery-media-field"><label>' . e((string) ($labels['background_source'] ?? 'Background source')) . '<select name="background_source">';
        echo '<option value=""' . ($backgroundSource === null ? ' selected' : '') . '>' . e((string) ($labels['use_theme_background'] ?? 'Use theme background')) . '</option>';
        echo '<option value="upload"' . ($backgroundSource === 'upload' ? ' selected' : '') . '>' . e((string) ($labels['upload_new_image'] ?? 'Upload new image')) . '</option>';
        echo '<option value="existing"' . ($backgroundSource === 'existing' ? ' selected' : '') . '>' . e((string) ($labels['pick_existing_gallery_images'] ?? 'Pick from existing gallery images')) . '</option>';
        echo '<option value="collage"' . ($backgroundSource === 'collage' ? ' selected' : '') . '>' . e((string) ($labels['generate_collage_public'] ?? 'Generate collage from public galleries')) . '</option>';
        $backgroundHelp = (string) ($labels['background_source_help'] ?? 'If unset, the gallery inherits the Theme background.');
        echo '</select></label><details class="admin-inline-help admin-gallery-media-help"><summary aria-label="' . e($backgroundHelp) . '" title="' . e($backgroundHelp) . '"><span aria-hidden="true">?</span></summary><div class="admin-inline-help-content">' . e($backgroundHelp) . '</div></details></div>';
    } else {
        echo '<p class="muted">' . e((string) ($labels['background_migration_hidden'] ?? 'Background source selection will be available after the background migration is applied.')) . '</p>';
    }
    echo '</section></div>';
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

    $helpLabel = t('admin.gallery_editor.access_help_label', 'About visibility and protection');
    echo '<div class="admin-access-compact">';
    echo '<section class="admin-access-protection" aria-label="' . e($helpLabel) . '">';
    echo '<div class="admin-access-main">';
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
    echo '<details class="admin-inline-help"><summary aria-label="' . e($helpLabel) . '" title="' . e($helpLabel) . '"><span aria-hidden="true">?</span></summary><div class="admin-inline-help-content"><p>' . e((string) ($labels['visibility_help'] ?? '')) . '</p><p>' . e((string) ($labels['password_lock_help'] ?? '')) . '</p>';
    if ($nsfwState === 'available') {
        echo '<p>' . e((string) ($labels['nsfw_help'] ?? '')) . '</p>';
    }
    echo '<p>' . e((string) ($labels['non_expiring_link_help'] ?? '')) . '</p>';
    if ($shareStorageMessage !== '') {
        echo '<p>' . e($shareStorageMessage) . '</p>';
    }
    echo '</div></details></div></section>';
    if (!$accessReady && is_string($accessUnavailableMessage) && $accessUnavailableMessage !== '') {
        echo '<div class="notice">' . e($accessUnavailableMessage) . '</div>';
    }
    if ($nsfwState !== 'available') {
        echo '<div class="notice">' . e((string) ($labels[$nsfwState === 'unknown' ? 'nsfw_inspection_failed' : 'nsfw_migration_hidden'] ?? '')) . '</div>';
    }

    if ($accessReady) {
        echo '<section class="admin-access-share" aria-label="' . e((string) ($labels['share_link_expiry'] ?? 'Share link expiry')) . '"><div class="admin-access-share-row"><label>' . e((string) ($labels['share_link_expiry'] ?? 'Share link expiry')) . '<input name="access_token_expires_at" type="datetime-local" value="' . e((string) ($viewModel['share_expiry_value'] ?? '')) . '"></label><div class="admin-access-share-actions">';
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
        echo '</section>';
    }
    echo '</div>';
}

/**
 * Render compact gallery context and secondary shortcuts above the editor tabs.
 *
 * @param array<string, mixed> $viewModel Controller-prepared gallery title, summary cards, labels, and action links.
 * @return void Emits native disclosure menus that also work without JavaScript.
 * @author Rudolf Klusal
 */
function view_render_admin_gallery_overview(array $viewModel): void
{
    $hero = (array) ($viewModel['hero'] ?? []);
    $metrics = (array) ($viewModel['metrics'] ?? []);
    $actions = (array) ($hero['actions'] ?? []);
    $summaryLabel = (string) ($viewModel['summary_label'] ?? 'Gallery summary');
    $actionsLabel = (string) ($hero['actions_aria_label'] ?? 'Gallery actions');

    echo '<section class="admin-edit-gallery-hero admin-gallery-editor-overview"><h1>' . e((string) ($hero['title'] ?? '')) . '</h1><div class="admin-gallery-editor-menus admin-hero-actions">';
    if ($actions !== []) {
        echo '<details class="admin-gallery-editor-menu"><summary>' . e($actionsLabel) . '<span aria-hidden="true">&#9662;</span></summary><nav class="admin-gallery-editor-popover admin-gallery-editor-action-list" aria-label="' . e($actionsLabel) . '">';
        foreach ($actions as $action) {
            if (is_array($action)) {
                echo view_admin_ui_action_link_html($action);
            }
        }
        echo '</nav></details>';
    }
    if ($metrics !== []) {
        echo '<details class="admin-gallery-editor-menu admin-gallery-editor-context"><summary aria-label="' . e($summaryLabel) . '" title="' . e($summaryLabel) . '"><span aria-hidden="true">&#9432;</span></summary><div class="admin-gallery-editor-popover">';
        view_render_admin_metric_grid($metrics, 'admin-metric-grid admin-edit-gallery-summary', $summaryLabel);
        echo '</div></details>';
    }
    echo '</div></section>';
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
    echo '<div class="admin-edit-card-grid admin-gallery-identity-layout">';
    echo '<div class="admin-edit-card is-wide admin-gallery-identity-card" data-content-localization><div class="admin-gallery-identity-text"><div class="admin-gallery-identity-title"><div class="admin-gallery-identity-title-heading"><label for="admin-gallery-title">' . e((string) ($labels['title'] ?? 'Title')) . '</label>' . (string) ($viewModel['source_language_html'] ?? '') . '</div><input id="admin-gallery-title" name="title" value="' . e((string) ($gallery['title'] ?? '')) . '" autocomplete="off" required></div>';
    echo '<div class="admin-editor-description-field"><label><span>' . e((string) ($labels['description'] ?? 'Description')) . '</span><textarea name="description" data-gallery-description-textarea data-openai-description-textarea>' . e((string) ($gallery['description'] ?? '')) . '</textarea></label>';
    echo (string) ($viewModel['description_hint_html'] ?? '') . '</div>';
    echo '<label class="admin-gallery-identity-tags">' . e((string) ($labels['tags'] ?? 'Tags')) . '<input name="tags" value="' . e((string) ($viewModel['tags'] ?? '')) . '" list="tag-suggestions" data-tag-input' . (string) ($viewModel['tag_suggestions_attribute'] ?? '') . '><span class="muted">' . e((string) ($labels['tags_help'] ?? '')) . '</span></label></div>';
    echo '<div class="admin-gallery-identity-tools"><div class="admin-editor-date">' . (string) ($viewModel['date_fields_html'] ?? '') . '</div><div class="admin-gallery-identity-simbrief">' . (string) ($viewModel['simbrief_html'] ?? '');
    echo '</div></div>';
    echo '<div class="admin-gallery-identity-language">' . (string) ($viewModel['localization_html'] ?? '') . '</div></div>';

    $advancedSummary = trim((string) ($gallery['slug'] ?? ''));
    $folderSummary = trim((string) ($viewModel['folder_name'] ?? ''));
    if ($folderSummary !== '' && $folderSummary !== $advancedSummary) {
        $advancedSummary .= ($advancedSummary !== '' ? ' · ' : '') . $folderSummary;
    }
    echo '<details class="admin-edit-card is-wide admin-gallery-advanced-settings"><summary>' . e(t('admin.gallery_editor.advanced_settings', 'Advanced gallery settings')) . ($advancedSummary !== '' ? ' <span class="muted admin-gallery-advanced-summary">' . e($advancedSummary) . '</span>' : '') . '</summary><div class="admin-edit-card-grid">';
    echo (string) ($viewModel['openai_html'] ?? '');
    echo '<div class="admin-edit-card admin-gallery-advanced-identity"><label>' . e((string) ($labels['slug'] ?? 'Slug')) . '<input name="slug" value="' . e((string) ($gallery['slug'] ?? '')) . '" autocomplete="off" required><span class="muted">' . e((string) ($labels['slug_help'] ?? '')) . '</span></label><label>' . e((string) ($labels['folder_name'] ?? 'Folder name')) . '<input name="folder_name" value="' . e((string) ($viewModel['folder_name'] ?? '')) . '" autocomplete="off" required><span class="muted">' . e((string) ($labels['folder_rename_help'] ?? '')) . '</span></label></div>';
    echo '<div class="admin-edit-card admin-gallery-advanced-placement"><div class="admin-gallery-advanced-parent"><span>' . e((string) ($labels['parent_gallery'] ?? 'Parent gallery')) . '</span>' . (string) ($viewModel['parent_picker_html'] ?? '') . '</div><label>' . e((string) ($labels['sort_order'] ?? 'Sort order')) . '<input name="sort_order" type="number" value="' . (int) ($gallery['sort_order'] ?? 0) . '"></label></div>';
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
 * @return void Emit attachment groups and their editable placement controls.
 */
function view_render_admin_gallery_smart_attachments(array $viewModel): void
{
    $labels = (array) ($viewModel['labels'] ?? []);
    $groups = (array) ($viewModel['groups'] ?? []);
    $definitions = (array) ($viewModel['definitions'] ?? []);

    $title = (string) ($labels['title'] ?? 'Smart Gallery attachments');
    echo '<div class="admin-edit-card is-wide admin-gallery-advanced-smart"><div class="admin-gallery-advanced-section-heading"><h3>' . e($title) . '</h3><details class="admin-inline-help"><summary aria-label="' . e($title) . '" title="' . e($title) . '"><span aria-hidden="true">?</span></summary><div class="admin-inline-help-content">' . e((string) ($labels['help'] ?? '')) . '</div></details></div>';
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
        view_render_admin_gallery_display_help($gridTitle, (string) ($grid['help'] ?? ''));
        echo '</div><div class="admin-display-grid-mode" data-gallery-grid-mode data-default-columns="' . (int) ($grid['default_columns'] ?? $grid['columns'] ?? 1) . '" data-default-rows="' . (int) ($grid['default_rows'] ?? $grid['rows'] ?? 1) . '" data-default-label="' . e((string) ($grid['default_status_label'] ?? 'Default settings')) . '" data-custom-label="' . e((string) ($grid['custom_status_label'] ?? 'Custom settings')) . '"><span data-gallery-grid-status aria-live="polite" hidden></span><button type="button" class="button secondary" data-gallery-grid-reset hidden>' . e((string) ($grid['reset_default_label'] ?? 'Reset to default')) . '</button><label class="checkbox-label admin-grid-override-fallback" data-gallery-grid-override-fallback><input type="checkbox" name="grid_override_enabled" value="1" data-gallery-grid-override-enabled' . ((bool) ($grid['override_enabled'] ?? false) ? ' checked' : '') . '> ' . e((string) ($grid['override_label'] ?? '')) . '</label></div>';
        echo '<div class="admin-edit-range-grid"><label>' . e((string) ($grid['columns_label'] ?? '')) . ' <span class="muted" data-gallery-grid-columns-display>' . (int) ($grid['columns'] ?? 1) . '</span><input type="range" name="grid_columns" min="1" max="' . (int) ($grid['max_columns'] ?? 1) . '" value="' . (int) ($grid['columns'] ?? 1) . '" data-gallery-grid-columns></label>';
        echo '<label>' . e((string) ($grid['rows_label'] ?? '')) . ' <span class="muted" data-gallery-grid-rows-display>' . (int) ($grid['rows'] ?? 1) . '</span><input type="range" name="grid_rows" min="1" max="' . (int) ($grid['max_rows'] ?? 1) . '" value="' . (int) ($grid['rows'] ?? 1) . '" data-gallery-grid-rows></label></div>';
        echo '<div class="admin-display-grid-footer"><label class="checkbox-label"><input type="checkbox" name="grid_use_for_subgalleries" value="1"' . ((bool) ($grid['use_for_subgalleries'] ?? true) ? ' checked' : '') . '> ' . e((string) ($grid['recursive_label'] ?? '')) . '</label></div></section>';
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

    $breadcrumbStyle = (array) ($viewModel['breadcrumb_style'] ?? []);
    if ($breadcrumbStyle !== []) {
        $label = (string) ($breadcrumbStyle['label'] ?? 'Breadcrumb style');
        echo '<div class="admin-display-option admin-display-option-breadcrumbs">';
        view_render_breadcrumb_style_picker($breadcrumbStyle);
        view_render_admin_gallery_display_help($label, (string) ($breadcrumbStyle['help'] ?? ''));
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

    echo '</div></details></div>';
    view_render_admin_tab_panel('admin-edit-display', (string) ob_get_clean(), (bool) ($viewModel['active'] ?? false));
}

/**
 * Render the Images tab from controller-prepared rows and URLs.
 *
 * @param array<string, mixed> $viewModel Controller-prepared image table presentation data.
 * @return void Render compact image controls using the shared public-card renderers.
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
    echo '<div class="admin-gallery-images">';
    view_render_admin_tab_intro($intro);
    echo '<form method="post" action="' . e((string) ($viewModel['bulk_action_url'] ?? '')) . '" data-admin-image-bulk-form>' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<input type="hidden" name="gallery_id" value="' . (int) ($viewModel['gallery_id'] ?? 0) . '">';
    echo '<input type="hidden" name="return_tab" value="admin-edit-images">';
    echo (string) ($viewModel['bulk_toolbar_html'] ?? '');
    echo '<div class="admin-image-order-toolbar" data-admin-image-order-toolbar data-reorder-url="' . e((string) ($viewModel['reorder_url'] ?? '')) . '">';
    echo '<div class="admin-image-view-controls"><label class="admin-compact-toggle"><input type="checkbox" data-admin-image-names-toggle checked> <span>' . e((string) ($labels['show_names'] ?? 'Show filenames')) . '</span></label><button type="button" class="secondary admin-image-default-button" data-admin-image-names-default data-saved-message="' . e((string) ($labels['names_default_saved'] ?? '')) . '" data-unavailable-message="' . e((string) ($labels['names_unavailable'] ?? '')) . '">' . e((string) ($labels['save_names_default'] ?? 'Use as default')) . '</button><span class="visually-hidden" data-admin-image-names-status role="status"></span></div>';
    echo '<div class="admin-image-sort-controls"><button type="button" class="admin-image-name-sort" data-admin-image-name-sort data-sort-direction="asc" aria-label="' . e((string) ($labels['sort_a_z'] ?? '')) . '">' . e((string) ($labels['name'] ?? 'Name')) . ' <span aria-hidden="true">↕</span></button><button type="button" class="admin-image-name-sort" data-admin-image-capture-date-sort data-sort-direction="asc" aria-label="' . e((string) ($labels['sort_capture_date'] ?? '')) . '">' . e((string) ($labels['capture_date'] ?? 'Date taken')) . ' <span aria-hidden="true">↕</span></button><span class="admin-image-order-status" data-admin-image-order-status aria-live="polite">' . e((string) ($labels['order_unchanged'] ?? '')) . '</span><details class="admin-inline-help"><summary title="' . e((string) ($labels['drag_help'] ?? '')) . '" aria-label="' . e((string) ($labels['drag_title'] ?? '')) . '">?</summary><div class="admin-inline-help-content">' . e((string) ($labels['drag_help'] ?? '')) . '</div></details></div></div>';
    echo '<table class="admin-image-order-table" data-admin-image-order-table><thead><tr><th class="admin-image-order-cell"><span class="visually-hidden">' . e((string) ($labels['move'] ?? 'Move')) . '</span></th><th class="admin-image-thumbnail-cell">' . e((string) ($labels['preview'] ?? 'Preview')) . '</th><th data-admin-image-name-cell>' . e((string) ($labels['name'] ?? 'Name')) . '</th><th class="admin-image-actions-cell">' . e((string) ($labels['actions'] ?? 'Actions')) . '</th></tr></thead><tbody>';
    foreach ((array) ($viewModel['images'] ?? []) as $image) {
        $imageId = (int) ($image['id'] ?? 0);
        $relativePath = (string) ($image['relative_path'] ?? '');
        $isCover = (string) ($image['cover_label'] ?? '') !== '';
        echo '<tr data-admin-image-order-row data-image-id="' . $imageId . '" data-image-name="' . e($relativePath) . '" data-image-captured-at="' . e((string) ($image['capture_date'] ?? '')) . '"><td class="admin-image-order-cell"><span class="admin-image-drag-handle" data-admin-image-drag-handle role="button" tabindex="0" aria-label="' . e((string) ($image['move_aria'] ?? '')) . '" title="' . e((string) ($labels['drag_title'] ?? '')) . '">↕</span></td>';
        echo '<td class="admin-image-thumbnail-cell"><div class="admin-image-thumbnail"><button type="button" class="admin-image-preview-button" data-admin-image-preview data-preview-src="' . e((string) ($image['preview_url'] ?? '')) . '" data-preview-name="' . e($relativePath) . '" data-preview-close-label="' . e((string) ($labels['close_preview'] ?? 'Close preview')) . '" aria-label="' . e((string) ($labels['preview'] ?? 'Preview') . ' ' . $relativePath) . '"><img class="admin-thumb" decoding="async" loading="lazy" src="' . e((string) ($image['thumbnail_url'] ?? '')) . '" alt=""></button>';
        echo '<label class="picture-manager-select-button admin-image-select" title="' . e((string) ($labels['select'] ?? 'Select') . ' ' . $relativePath) . '"><input type="checkbox" name="image_ids[]" value="' . $imageId . '" aria-label="' . e((string) ($labels['select'] ?? 'Select') . ' ' . $relativePath) . '"><span aria-hidden="true">✓</span></label></div></td>';
        echo '<td data-admin-image-name-cell title="' . e($relativePath) . '"><span class="admin-image-filename">' . e($relativePath) . '</span></td><td class="admin-image-actions-cell"><div class="admin-image-row-actions"><span data-admin-image-visibility-cell>';
        view_render_public_admin_visibility_menu(['image_bulk' => true, 'entity_id' => $imageId, 'kind' => 'image', 'name' => $relativePath, 'visibility' => (string) ($image['visibility'] ?? 'unpublished')]);
        echo '</span><span data-admin-image-cover-cell><button type="submit" class="public-admin-card-action-button admin-image-cover-button' . ($isCover ? ' is-cover' : '') . '" name="action" value="cover:' . $imageId . '" data-admin-image-row-action data-image-id="' . $imageId . '" aria-pressed="' . ($isCover ? 'true' : 'false') . '" title="' . e((string) ($isCover ? ($image['cover_label'] ?? '') : ($labels['set_cover'] ?? 'Set as title picture'))) . '" aria-label="' . e((string) ($labels['set_cover'] ?? 'Set as title picture') . ' ' . $relativePath) . '"><span aria-hidden="true">' . ($isCover ? '★' : '☆') . '</span></button></span>';
        view_render_public_image_admin_edit_link(['name' => $relativePath, 'url' => (string) ($image['edit_url'] ?? ''), 'panel_url' => (string) ($image['panel_url'] ?? '')]);
        view_render_public_image_admin_delete_form(['image_bulk' => true, 'name' => $relativePath, 'image_id' => $imageId]);
        echo '</div></td></tr>';
    }
    echo '</tbody></table></form></div>';
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

/**
 * Render an isolated manual OFP attachment and reviewed SimBrief dispatch card.
 *
 * The card is outside the gallery-settings form, including when rendered inside
 * the admin side panel. No control mutates dates, route geometry, descriptions,
 * the original imported OFP JSON, or gallery images.
 *
 * @param array<string,mixed> $viewModel Source gallery and validated document state.
 * @return void Safe, accessible admin-only document controls.
 */
function view_render_admin_simbrief_legacy_panel(array $viewModel): void
{
    $gallery = (array) ($viewModel['gallery'] ?? []);
    $galleryId = (int) ($gallery['id'] ?? 0);
    $galleryTitle = trim((string) ($gallery['title'] ?? ''));
    $hasPdf = !empty($viewModel['has_pdf']);
    $occupied = !empty($viewModel['occupied']);
    $prefill = (array) ($viewModel['prefill'] ?? []);
    $fields = (array) ($prefill['fields'] ?? []);
    $action = (string) ($viewModel['action_url'] ?? '');
    $csrf = (string) ($viewModel['csrf_html'] ?? '');
    $provenance = (string) ($viewModel['provenance'] ?? '');
    $labels = [
        'orig' => t('admin.legacy_ofp.field_orig', 'Departure ICAO'),
        'dest' => t('admin.legacy_ofp.field_dest', 'Arrival ICAO'),
        'date' => t('admin.legacy_ofp.field_date', 'Departure date'),
        'deph' => t('admin.legacy_ofp.field_deph', 'Departure hour (UTC)'),
        'depm' => t('admin.legacy_ofp.field_depm', 'Departure minute (UTC)'),
        'type' => t('admin.legacy_ofp.field_type', 'Aircraft ICAO type'),
        'airline' => t('admin.legacy_ofp.field_airline', 'Airline ICAO'),
        'fltnum' => t('admin.legacy_ofp.field_fltnum', 'Flight number'),
        'callsign' => t('admin.legacy_ofp.field_callsign', 'Callsign'),
        'reg' => t('admin.legacy_ofp.field_reg', 'Aircraft registration'),
        'route' => t('admin.legacy_ofp.field_route', 'Planned route'),
        'fl' => t('admin.legacy_ofp.field_fl', 'Cruise flight level'),
        'altn' => t('admin.legacy_ofp.field_altn', 'Alternate ICAO'),
        'pax' => t('admin.legacy_ofp.field_pax', 'Passengers'),
    ];
    $sourceLabels = [
        'saved_ofp' => t('admin.legacy_ofp.source_saved_ofp', 'Saved SimBrief snapshot'),
        'route_map' => t('admin.legacy_ofp.source_route_map', 'Stored route map, verify'),
        'gallery_date' => t('admin.legacy_ofp.source_gallery_date', 'Gallery date, confirm it is the departure date'),
        'missing' => t('admin.legacy_ofp.source_missing', 'Unknown, leave blank or enter manually'),
    ];

    echo '<section class="admin-edit-card admin-simbrief-legacy-card" data-simbrief-legacy-panel aria-label="' . e(t('admin.legacy_ofp.title', 'Legacy flight plan (OFP)')) . '">';
    echo '<div class="admin-simbrief-legacy-header"><div><h3>' . e(t('admin.legacy_ofp.title', 'Legacy flight plan (OFP)')) . '</h3>';
    echo '<p class="muted">' . e(t('admin.legacy_ofp.description', 'Attach a saved PDF or prepare a new historical SimBrief dispatch for this gallery only. No bulk requests are made.')) . '</p></div></div>';

    if ($hasPdf) {
        $provenanceLabels = [
            'original_simbrief_import' => t('admin.legacy_ofp.provenance_original', 'Original saved SimBrief import'),
            'retrospective_user_generated' => t('admin.legacy_ofp.provenance_retrospective', 'Newly generated retrospective OFP'),
            'manually_supplied' => t('admin.legacy_ofp.provenance_manual', 'Manually supplied document'),
        ];
        echo '<p class="admin-simbrief-legacy-present">' . e(t('admin.legacy_ofp.pdf_present', 'A valid PDF is attached to this gallery.')) . ' <small>' . e($provenanceLabels[$provenance] ?? t('admin.legacy_ofp.provenance_unspecified', 'Existing attachment, origin not recorded')) . '</small></p>';
        echo '<div class="admin-simbrief-legacy-actions">';
        echo '<a class="button secondary" target="_blank" rel="noopener noreferrer" href="' . e((string) ($viewModel['pdf_view_url'] ?? '')) . '">' . e(t('admin.legacy_ofp.view', 'View OFP')) . '</a>';
        echo '<a class="button secondary" href="' . e((string) ($viewModel['pdf_download_url'] ?? '')) . '" download="simbrief-ofp.pdf">' . e(t('admin.legacy_ofp.download', 'Download PDF')) . '</a>';
        echo '</div>';
    } elseif ($occupied) {
        echo '<p class="admin-simbrief-legacy-warning">' . e(t('admin.legacy_ofp.pdf_invalid', 'A PDF file exists but failed validation. It will not be served; repair requires explicit replacement.')) . '</p>';
    } else {
        echo '<p class="muted">' . e(!empty($prefill['has_snapshot'])
            ? t('admin.legacy_ofp.pdf_missing_snapshot', 'No valid PDF is attached. Saved SimBrief data is available for reviewing the dispatch inputs.')
            : t('admin.legacy_ofp.pdf_missing', 'No OFP PDF or saved SimBrief snapshot is available. Fill in only details you can confirm.')) . '</p>';
    }

    if (!$hasPdf) {
        echo '<div class="admin-simbrief-legacy-actions"><button type="button" class="button secondary" data-simbrief-dispatch-open>' . e(t('admin.legacy_ofp.open_review', 'Create historical OFP / Open prefilled SimBrief')) . '</button></div>';
        echo '<p class="muted">' . e(t('admin.legacy_ofp.dispatch_no_generation', 'You will review the fields here first. SimBrief opens in a new tab, where you must sign in if required and select Generate yourself.')) . '</p>';
    }

    $uploadAction = $occupied ? 'replace_pdf' : 'upload_pdf';
    if ($hasPdf) {
        echo '<details class="admin-simbrief-legacy-replace"><summary>' . e(t('admin.legacy_ofp.replace_toggle', 'Replace existing OFP PDF (separate action)')) . '</summary>';
    } else {
        echo '<h4>' . e($occupied
            ? t('admin.legacy_ofp.replace_toggle', 'Replace existing OFP PDF (separate action)')
            : t('admin.legacy_ofp.upload_title', 'Upload OFP PDF')) . '</h4>';
    }
    echo '<form method="post" enctype="multipart/form-data" action="' . e($action) . '" data-simbrief-ofp-upload-form>';
    echo $csrf . '<input type="hidden" name="id" value="' . $galleryId . '"><input type="hidden" name="simbrief_action" value="' . e($uploadAction) . '">';
    echo '<label>' . e(t('admin.legacy_ofp.choose_pdf', 'Select PDF (maximum 25 MiB)')) . '<input type="file" name="simbrief_ofp_pdf" accept=".pdf,application/pdf" required></label>';
    echo '<label>' . e(t('admin.legacy_ofp.origin_label', 'Document origin')) . '<select name="ofp_provenance">';
    echo '<option value="retrospective_user_generated">' . e(t('admin.legacy_ofp.provenance_retrospective', 'Newly generated retrospective OFP')) . '</option>';
    echo '<option value="manually_supplied">' . e(t('admin.legacy_ofp.provenance_manual', 'Manually supplied document')) . '</option></select></label>';
    echo '<p class="muted">' . e(t('admin.legacy_ofp.not_original', 'A newly generated OFP is not the original flight-day plan. Weather, NOTAMs, AIRAC, fuel and routing may differ.')) . '</p>';
    if ($occupied) {
        echo '<label class="checkbox-label"><input type="checkbox" name="confirm_replace" value="1" required> '
            . e(t('admin.legacy_ofp.confirm_replace', 'I confirm I want to replace the existing PDF. This does not modify the saved SimBrief JSON or gallery details.')) . '</label>';
    }
    echo '<div class="admin-simbrief-legacy-actions"><button type="submit" class="button secondary">'
        . e($occupied ? t('admin.legacy_ofp.replace_button', 'Confirm PDF replacement') : t('admin.legacy_ofp.upload_button', 'Attach PDF to this gallery'))
        . '</button></div><output role="status" aria-live="polite" data-simbrief-ofp-upload-status></output></form>';
    if ($hasPdf) {
        echo '</details>';
    }

    if (!$hasPdf) {
        // Template content is inert until the administrator explicitly opens
        // the modal. JS moves one clone to <body> to escape drawer clipping.
        echo '<template data-simbrief-dispatch-template>';
        echo '<dialog class="admin-simbrief-dispatch-dialog" data-simbrief-dispatch-dialog aria-labelledby="admin-simbrief-dispatch-title-' . $galleryId . '">';
        echo '<form method="dialog" data-simbrief-dispatch-form>';
        echo '<header><h2 id="admin-simbrief-dispatch-title-' . $galleryId . '">' . e(t('admin.legacy_ofp.review_title', 'Review SimBrief dispatch inputs')) . '</h2>';
        echo '<p>' . e(t('admin.legacy_ofp.gallery_identity', 'Gallery')) . ': <strong>' . e($galleryTitle) . '</strong> (#' . $galleryId . ')</p>';
        echo '<p class="admin-simbrief-legacy-warning">' . e(t('admin.legacy_ofp.historical_warning', 'This creates a new retrospective flight plan. Historical dates may be unsupported or adjusted by SimBrief. Current weather, AIRAC, routing, NOTAMs and fuel will not match the original flight day.')) . '</p>';
        echo '<p class="muted">' . e(t('admin.legacy_ofp.review_help', 'Only the edited values below will be sent to the official SimBrief dispatch options page. Fields are not saved to the gallery. Empty optional fields are omitted.')) . '</p></header>';
        echo '<div class="admin-simbrief-dispatch-fields">';
        foreach ($labels as $key => $label) {
            $entry = (array) ($fields[$key] ?? []);
            $value = (string) ($entry['value'] ?? '');
            $source = (string) ($entry['source'] ?? 'missing');
            $inputType = $key === 'date' ? 'date' : (in_array($key, ['deph', 'depm', 'fl', 'pax'], true) ? 'number' : 'text');
            $attributes = '';
            if ($key === 'deph' || $key === 'depm' || $key === 'pax' || $key === 'fl') {
                $attributes = ' min="0" max="' . ($key === 'deph' ? '23' : ($key === 'depm' ? '59' : ($key === 'fl' ? '600' : '999'))) . '" step="1"';
            }
            if ($key === 'route') {
                $attributes = ' maxlength="500"';
            } elseif ($inputType === 'text') {
                $attributes = ' maxlength="32"';
            }
            $required = in_array($key, ['orig', 'dest'], true) ? ' required' : '';
            echo '<label class="admin-simbrief-dispatch-field"><span>' . e($label) . ($required !== '' ? ' *' : '') . '</span>';
            echo '<input name="' . e($key) . '" type="' . $inputType . '" value="' . e($value) . '" autocomplete="off"' . $attributes . $required . '>';
            echo '<small>' . e($sourceLabels[$source] ?? $sourceLabels['missing']) . '</small></label>';
        }
        echo '</div><output class="admin-simbrief-dispatch-error" role="alert" data-simbrief-dispatch-error></output>';
        echo '<footer><button type="button" class="button secondary" data-simbrief-dispatch-cancel>' . e(t('admin.legacy_ofp.cancel', 'Cancel / Back')) . '</button>';
        echo '<button type="submit" class="button" data-simbrief-dispatch-confirm>' . e(t('admin.legacy_ofp.confirm_dispatch', 'Open SimBrief Dispatch')) . '</button></footer>';
        echo '</form></dialog></template>';
    }
    echo '</section>';
}

