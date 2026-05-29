<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_gallery_forms.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders reusable admin gallery editor form fragments.
 *
 * Responsibilities:
 *   - Keep form HTML out of gallery controllers
 *   - Preserve existing side-panel and full-page form markup
 *   - Render reusable description-related editor tools
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
 *   2026-05-24
 */

declare(strict_types=1);

function view_render_gallery_description_formatting_hint(): void
{
    echo '<details class="gallery-description-format-help"><summary><span aria-hidden="true">&#128161;</span><span>' . e(t('admin.gallery_editor.description_format_hints', 'Formatting hints')) . '</span></summary><div class="gallery-description-format-help-popover">';
    echo '<p>' . e(t('admin.gallery_editor.description_format_intro', 'Basic Markdown is supported in public gallery descriptions.')) . '</p>';
    echo '<ul>';
    echo '<li><code>**' . e(t('admin.gallery_editor.description_format_bold_word', 'bold')) . '**</code> ' . e(t('admin.gallery_editor.description_format_bold', 'makes bold text')) . '</li>';
    echo '<li><code>*' . e(t('admin.gallery_editor.description_format_italic_word', 'italic')) . '*</code> ' . e(t('admin.gallery_editor.description_format_italic', 'makes italic text')) . '</li>';
    echo '<li><code>`code`</code> ' . e(t('admin.gallery_editor.description_format_code', 'uses inline code styling')) . '</li>';
    echo '<li><code>[Link](https://example.com)</code> ' . e(t('admin.gallery_editor.description_format_link', 'creates a safe external link')) . '</li>';
    echo '<li>' . e(t('admin.gallery_editor.description_format_newlines', 'A single Enter is preserved as a new line. Empty lines create separate paragraphs.')) . '</li>';
    echo '</ul></div></details>';
}

function view_render_admin_new_gallery_fields(int $prefillParentId, bool $panelMode, string $workflow = 'create'): void
{
    if ($panelMode) {
        echo '<input type="hidden" name="panel" value="1">';
        $isUploadWorkflow = $workflow === 'upload';
        $panelHelp = $isUploadWorkflow ? t('admin.upload.gallery_identity_help', 'Create an empty gallery, or select photos and upload them immediately.') : t('admin.gallery_editor.only_gallery_created_here', 'Only the gallery is created here.');
        $panelKicker = $isUploadWorkflow ? t('admin.upload.new_child_gallery', 'New child gallery') : t('admin.gallery_editor.new_gallery_kicker', 'New gallery');
        echo '<div class="admin-side-panel-card admin-side-panel-primary-card"><div class="admin-side-panel-card-heading"><div><p class="admin-kicker">' . e($panelKicker) . '</p><h3>' . e(t('admin.gallery_editor.gallery_identity', 'Gallery identity')) . '</h3></div><p class="muted">' . e($panelHelp) . '</p></div><div class="admin-side-panel-field-grid">';
        echo '<label class="admin-side-panel-field admin-side-panel-field-wide"><span>' . e(t('admin.gallery_editor.gallery_name', 'Gallery name')) . '</span><input name="title" required></label>';
        echo '<label class="admin-side-panel-field"><span>' . e(t('admin.gallery_editor.folder_name', 'Folder name')) . '</span><input name="folder_name" autocomplete="off"><small>' . e(t('admin.gallery_editor.derive_from_gallery_name', 'Leave empty to derive it from the gallery name.')) . '</small></label>';
        echo '<label class="admin-side-panel-field"><span>' . e(t('admin.gallery_editor.metric_visibility')) . '</span><select name="visibility">' . visibility_options('unpublished') . '</select></label>';
        if (gallery_date_schema_ready()) {
            echo '<label class="admin-side-panel-field"><span>' . e(t('admin.gallery_editor.gallery_date', 'Date')) . '</span><input name="gallery_date" type="date"><small>' . e(t('admin.gallery_editor.gallery_date_help', 'Optional manual gallery date, for example an event, trip, or shooting date.')) . '</small></label>';
        } else {
            echo '<div class="admin-side-panel-field admin-side-panel-field-wide"><span>' . e(t('admin.gallery_editor.gallery_date', 'Date')) . '</span><small>' . e(t('admin.gallery_editor.gallery_date_migration_hidden', 'Gallery date will be available after the database migration is applied.')) . '</small></div>';
        }
        echo '<label class="admin-side-panel-field admin-side-panel-field-wide"><span>' . e(t('admin.gallery_editor.parent_gallery', 'Parent gallery')) . '</span><select name="parent_id"><option value="0"' . ($prefillParentId === 0 ? ' selected' : '') . '>' . e(t('admin.gallery_editor.no_parent', 'No parent')) . '</option>' . gallery_parent_options_for_new($prefillParentId) . '</select></label>';
        echo '<label class="admin-side-panel-field admin-side-panel-field-wide"><span>' . e(t('admin.gallery_editor.description', 'Description')) . '</span><textarea name="description" rows="4"></textarea></label>';
        view_render_gallery_description_formatting_hint();
        echo '</div><div class="admin-side-panel-toggle-row">';
        echo '<label><input type="checkbox" name="voting_enabled" value="1"> <span>' . e(t('admin.gallery_editor.enable_image_voting_short', 'Enable image voting')) . '</span></label>';
        echo '<label><input type="checkbox" name="show_filenames" value="1"> <span>' . e(t('admin.gallery_editor.show_file_names', 'Show file names')) . '</span></label>';
        echo '</div></div>';
        if (gallery_count_badge_schema_ready()) {
            echo '<div class="admin-side-panel-card"><label class="admin-side-panel-field"><span>' . e(t('admin.gallery_editor.count_badge_title', 'Contained-picture badge')) . '</span><select name="count_badge_visibility">';
            foreach (gallery_count_badge_override_values() as $countBadgeOption) {
                echo '<option value="' . e($countBadgeOption) . '"' . ($countBadgeOption === 'inherit' ? ' selected' : '') . '>' . e(gallery_count_badge_override_label($countBadgeOption)) . '</option>';
            }
            echo '</select><small>' . e(t('admin.gallery_editor.count_badge_new_gallery_help', 'Controls the stacked-picture icon and image count on this gallery card.')) . '</small></label></div>';
        }
        return;
    }

    echo '<label>' . e(t('admin.gallery_editor.gallery_name', 'Gallery name')) . '<input name="title" required></label>';
    echo '<label>' . e(t('admin.gallery_editor.folder_name', 'Folder name')) . '<input name="folder_name" autocomplete="off"><span class="muted">' . e(t('admin.gallery_editor.derive_from_gallery_name', 'Leave empty to derive it from the gallery name.')) . '</span></label>';
    echo '<label>' . e(t('admin.gallery_editor.parent_gallery', 'Parent gallery')) . '<select name="parent_id"><option value="0"' . ($prefillParentId === 0 ? ' selected' : '') . '>' . e(t('admin.gallery_editor.no_parent', 'No parent')) . '</option>' . gallery_parent_options_for_new($prefillParentId) . '</select></label>';
    echo '<label>' . e(t('admin.gallery_editor.visibility', 'Visibility')) . '<select name="visibility">' . visibility_options('unpublished') . '</select></label>';
    if (gallery_date_schema_ready()) {
        echo '<label>' . e(t('admin.gallery_editor.gallery_date', 'Date')) . '<input name="gallery_date" type="date"><span class="muted">' . e(t('admin.gallery_editor.gallery_date_help', 'Optional manual gallery date, for example an event, trip, or shooting date.')) . '</span></label>';
    } else {
        echo '<p class="muted">' . e(t('admin.gallery_editor.gallery_date_migration_hidden', 'Gallery date will be available after the database migration is applied.')) . '</p>';
    }
    echo '<label><input type="checkbox" name="voting_enabled" value="1"> ' . e(t('admin.gallery_editor.enable_image_voting', 'Enable image voting for this gallery')) . '</label>';
    echo '<label><input type="checkbox" name="show_filenames" value="1"> ' . e(t('admin.gallery_editor.show_file_names', 'Show file names')) . '</label>';
    if (gallery_count_badge_schema_ready()) {
        echo '<label>' . e(t('admin.gallery_editor.count_badge_title', 'Contained-picture badge')) . '<select name="count_badge_visibility">';
        foreach (gallery_count_badge_override_values() as $countBadgeOption) {
            echo '<option value="' . e($countBadgeOption) . '"' . ($countBadgeOption === 'inherit' ? ' selected' : '') . '>' . e(gallery_count_badge_override_label($countBadgeOption)) . '</option>';
        }
        echo '</select><span class="muted">' . e(t('admin.gallery_editor.count_badge_new_gallery_help', 'Controls the stacked-picture icon and image count on this gallery card.')) . '</span></label>';
    }
    echo '<label>' . e(t('admin.gallery_editor.description', 'Description')) . '<textarea name="description"></textarea></label>';
    view_render_gallery_description_formatting_hint();
}

function view_render_admin_new_gallery_side_panel(int $prefillParentId, ?array $prefillParentGallery, string $error): void
{
    echo '<div class="admin-side-panel-stack" data-gallery-create-panel>';
    echo '<div class="admin-side-panel-copy"><p class="admin-kicker">' . e(t('admin.gallery_editor.gallery_workflow', 'Gallery workflow')) . '</p><h2>' . e(t('admin.gallery_editor.create_gallery', 'Create gallery')) . '</h2><p class="muted">' . e(t('admin.gallery_editor.create_gallery_empty_help', 'Create a new empty gallery in the selected parent. Photo upload stays in the separate upload workflow.')) . '</p></div>';
    if ($prefillParentGallery) {
        echo '<div class="notice">' . e(t('admin.gallery_editor.target_parent', 'Target parent: {title}.', ['title' => (string) $prefillParentGallery['title']])) . '</div>';
    }
    if ($error !== '') {
        echo '<div class="notice">' . e(t('admin.galleries.create_failed', ['error' => $error])) . '</div>';
    }
    echo '<section class="admin-side-panel-workflow" data-gallery-panel-workflow>';
    echo '<form method="post" action="' . e(url_for('admin_new_gallery')) . '" class="admin-side-panel-form" data-gallery-panel-create-form>' . csrf_field();
    view_render_admin_new_gallery_fields($prefillParentId, true);
    echo '<div class="admin-side-panel-actions"><button type="submit" class="button primary" data-gallery-panel-submit>' . e(t('admin.gallery_editor.create_gallery', 'Create gallery')) . '</button><p class="muted">' . e(t('admin.gallery_editor.new_gallery_empty_help', 'The new gallery is created empty. Use Upload photos for media.')) . '</p></div>';
    echo '</form></section>';
    echo '</div>';
}

function view_render_admin_simbrief_description_tool(int $galleryId): void
{
    echo '<div class="admin-simbrief-description" data-simbrief-description-tool data-simbrief-endpoint="' . e(url_for('admin_simbrief_description')) . '" data-gallery-id="' . (int) $galleryId . '">';
    echo '<div class="admin-simbrief-description-heading"><div><h3>' . e(t('admin.simbrief.title', 'Generate from SimBrief')) . '</h3><p class="muted">' . e(t('admin.simbrief.help', 'Fetch the latest SimBrief OFP and create an editable gallery-description draft. Nothing is saved until you save the gallery.')) . '</p></div></div>';
    echo '<div class="admin-simbrief-description-grid">';
    echo '<label>' . e(t('admin.simbrief.pilot_id', 'SimBrief Pilot ID')) . '<input name="simbrief_pilot_id" autocomplete="off" inputmode="text" data-simbrief-pilot-id><span class="muted">' . e(t('admin.simbrief.pilot_id_help', 'Pilot ID = the numeric or account identifier used by SimBrief. If both fields are filled, Pilot ID is used first.')) . '</span></label>';
    echo '<label>' . e(t('admin.simbrief.pilot_name', 'SimBrief pilot name')) . '<input name="simbrief_pilot_name" autocomplete="off" data-simbrief-pilot-name><span class="muted">' . e(t('admin.simbrief.pilot_name_help', 'Pilot name = the SimBrief pilot name exactly as it appears in the SimBrief profile.')) . '</span></label>';
    echo '</div>';
    echo '<div class="admin-simbrief-description-actions"><button type="button" class="button secondary" data-simbrief-generate>' . e(t('admin.simbrief.generate_button', 'Generate description draft')) . '</button><span class="muted" data-simbrief-status role="status" aria-live="polite"></span></div>';
    echo '</div>';
}

/**
 * Render optional OpenAI text-assistance controls for a description textarea.
 *
 * @param int $galleryId Gallery id used for gallery-level prompt context, or zero for photo-only editors.
 * @param int $imageId Image id used for photo-level prompt context, or zero for gallery editors.
 * @param string $mode UI mode, either gallery or image.
 * @return void
 */
function view_render_admin_openai_text_assist_tool(int $galleryId, int $imageId = 0, string $mode = 'gallery'): void
{
    $user = function_exists('current_user') ? current_user() : null;
    $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    if ($userId <= 0 || !function_exists('openai_text_assist_available') || !openai_text_assist_available($userId)) {
        return;
    }

    $mode = $mode === 'image' ? 'image' : 'gallery';
    $taskDefault = $mode === 'image' ? 'image_description' : 'gallery_description';
    $title = $mode === 'image'
        ? t('admin.openai.image_title', 'AI photo text')
        : t('admin.openai.gallery_title', 'AI gallery text');
    $help = $mode === 'image'
        ? t('admin.openai.image_help', 'Generate or clean up this public photo description. Nothing is saved until you save the photo.')
        : t('admin.openai.gallery_help', 'Generate or clean up this gallery description. Nothing is saved until you save the gallery.');
    $button = $mode === 'image'
        ? t('admin.openai.generate_image_button', 'Generate photo description')
        : t('admin.openai.generate_gallery_button', 'Generate gallery description');
    $allowImageInput = function_exists('openai_text_assist_image_input_allowed') && openai_text_assist_image_input_allowed($userId);
    $languageCatalog = function_exists('openai_text_assist_language_catalog') ? openai_text_assist_language_catalog() : [];
    $defaultLanguage = function_exists('openai_text_assist_default_language') ? openai_text_assist_default_language() : 'en';

    echo '<div class="admin-openai-text-assist" data-openai-text-assist data-openai-endpoint="' . e(url_for('admin_openai_text_assist')) . '" data-gallery-id="' . (int) $galleryId . '" data-image-id="' . (int) $imageId . '" data-openai-target-selector="[data-openai-description-textarea]">';
    echo '<div class="admin-openai-text-assist-heading"><div><h3>' . e($title) . '</h3><p class="muted">' . e($help) . '</p></div></div>';
    echo '<div class="admin-openai-text-assist-actions"><label><span>' . e(t('admin.openai.action_label', 'Action')) . '</span><select data-openai-task>';
    if ($mode === 'image') {
        echo '<option value="image_description" selected>' . e(t('admin.openai.task_image_description', 'Generate photo description')) . '</option>';
        if ($allowImageInput) {
            echo '<option value="image_visual_description">' . e(t('admin.openai.task_image_visual_description', 'Describe visible content from thumbnail')) . '</option>';
        }
    } else {
        echo '<option value="gallery_description" selected>' . e(t('admin.openai.task_gallery_description', 'Generate leaf-gallery description')) . '</option>';
        echo '<option value="gallery_summary">' . e(t('admin.openai.task_gallery_summary', 'Summarize parent gallery')) . '</option>';
        if ($allowImageInput) {
            echo '<option value="gallery_visual_description">' . e(t('admin.openai.task_gallery_visual_description', 'Generate from gallery thumbnails')) . '</option>';
        }
    }
    echo '<option value="cleanup_text">' . e(t('admin.openai.task_cleanup_text', 'Fix spelling and grammar')) . '</option>';
    echo '<option value="expand_text">' . e(t('admin.openai.task_expand_text', 'Expand existing text')) . '</option>';
    echo '</select></label>';
    if ($languageCatalog !== []) {
        echo '<label class="admin-openai-language-select"><span>' . e(t('admin.openai.language_label', 'Language')) . '</span><select data-openai-language>';
        foreach ($languageCatalog as $languageCode => $languageInfo) {
            $optionLabel = trim((string) ($languageInfo['flag'] ?? '') . ' ' . (string) ($languageInfo['label'] ?? $languageCode));
            echo '<option value="' . e((string) $languageCode) . '"' . ($defaultLanguage === $languageCode ? ' selected' : '') . '>' . e($optionLabel) . '</option>';
        }
        echo '</select></label>';
    }
    echo '<button type="button" class="button secondary" data-openai-generate>' . e($button) . '</button>';
    echo '<span class="muted" data-openai-status role="status" aria-live="polite"></span></div>';
    if ($allowImageInput) {
        echo '<p class="muted admin-openai-text-assist-note">' . e(t('admin.openai.visual_note', 'Image-based actions send only small generated thumbnails, never the original files.')) . '</p>';
        if ($mode === 'gallery') {
            echo '<div class="admin-openai-text-assist-bulk">';
            echo '<button type="button" class="button secondary" data-openai-bulk-generate>' . e(t('admin.openai.bulk_generate_images_button', 'Bulk describe gallery photos')) . '</button>';
            echo '<p class="muted">' . e(t('admin.openai.bulk_generate_images_help', 'Generates and saves photo descriptions one photo at a time. You will confirm the exact number before it starts.')) . '</p>';
            echo '</div>';
        }
    }
    echo '</div>';
}
