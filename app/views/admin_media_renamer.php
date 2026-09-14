<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_media_renamer.php
 * Module Type: View
 *
 * Purpose:
 *   Renders the Admin Media Renamer page, gallery panel, forms, plan tables,
 *   and execution details from controller-prepared presentation state.
 *
 * Responsibilities:
 *   - Render the site-wide Media Renamer workspace
 *   - Render the reusable gallery-level Media Renamer panel
 *   - Render dry-run plan and execution-detail tables
 *   - Consume prepared URLs, CSRF fragments, and normalized display state
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
 *   - Translation and escaping are presentation helpers.
 *   - Request parsing, rename planning/execution, feature policy, diagnostics,
 *     URL generation, CSRF generation, and persistence remain outside the view.
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
 * Render the complete site-wide Media Renamer page.
 *
 * @param array<string,mixed> $viewModel Controller-prepared page state.
 */
function view_render_admin_media_renamer_page(array $viewModel): void
{
    render_header((string) ($viewModel['page_title'] ?? ''));
    echo '<section class="admin-dashboard-hero admin-media-renamer-hero">';
    echo '<div><p class="admin-kicker">' . e(t('admin.media_renamer.kicker', 'Maintenance')) . '</p><h1>' . e(t('admin.media_renamer.heading', 'Context-aware file renamer')) . '</h1><p class="muted">' . e(t('admin.media_renamer.intro', 'Preview and physically rename image files from gallery context and photo order. Database rows, generated derivatives, public paths, and stale download ZIP archives are updated after execution.')) . '</p></div>';
    echo '<nav class="admin-hero-actions"><a class="button secondary" href="' . e((string) ($viewModel['admin_url'] ?? '')) . '">' . e(t('admin.media_renamer.back_to_admin', 'Back to admin')) . '</a></nav>';
    echo '</section>';
    echo (string) ($viewModel['workspace_html'] ?? '');
    render_footer();
}

/**
 * Render the site-wide Media Renamer workspace.
 *
 * @param array<string,mixed> $viewModel Controller-prepared workspace state.
 */
function view_render_admin_media_renamer_site_workspace(array $viewModel): void
{
    echo '<div class="admin-media-renamer-workspace" data-admin-media-renamer-workspace="site" data-media-renamer-log-url="' . e((string) ($viewModel['log_url'] ?? '')) . '">';
    $notice = (string) ($viewModel['notice'] ?? '');
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }
    echo (string) ($viewModel['scope_form_html'] ?? '');

    if (!empty($viewModel['has_plans'])) {
        echo '<section class="panel admin-media-renamer-preview">';
        echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.media_renamer.preview_kicker', 'Dry run')) . '</p><h2>' . e(t('admin.media_renamer.preview_title', 'Generated rename plan')) . '</h2></div><p class="muted">' . e(t('admin.media_renamer.preview_help', 'Review every old-to-new filename mapping before applying. Missing files and unsafe collisions are skipped.')) . '</p></div>';
        echo (string) ($viewModel['plan_table_html'] ?? '');
        echo (string) ($viewModel['execution_details_html'] ?? '');
        echo (string) ($viewModel['apply_form_html'] ?? '');
        echo '</section>';
    }
    echo '<div class="thumbnail-progress admin-media-renamer-progress" data-admin-media-renamer-progress hidden><progress class="thumbnail-progress-bar" value="0" max="100" data-admin-media-renamer-progress-bar></progress><p class="muted" data-admin-media-renamer-progress-text></p></div>';
    echo '</div>';
}

/**
 * Render the gallery-level Media Renamer panel.
 *
 * @param array<string,mixed> $viewModel Controller-prepared gallery-panel state.
 */
function view_render_admin_media_renamer_gallery_panel(array $viewModel): void
{
    echo '<div class="admin-media-renamer-workspace" data-admin-media-renamer-workspace="gallery" data-media-renamer-log-url="' . e((string) ($viewModel['log_url'] ?? '')) . '">';
    $notice = (string) ($viewModel['notice'] ?? '');
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.media_renamer.gallery_kicker', 'File maintenance')) . '</p><h2>' . e(t('admin.media_renamer.gallery_title', 'Rename files in this gallery')) . '</h2></div><p class="muted">' . e(t('admin.media_renamer.gallery_help', 'Generated names use this gallery folder context and the current image order. This physically renames files on disk.')) . '</p></div>';
    echo (string) ($viewModel['pattern_form_html'] ?? '');

    $error = (string) ($viewModel['error'] ?? '');
    if ($error !== '') {
        echo '<div class="notice">' . e($error) . '</div></div>';
        return;
    }

    echo (string) ($viewModel['plan_table_html'] ?? '');
    echo (string) ($viewModel['execution_details_html'] ?? '');

    $renameCount = max(0, (int) ($viewModel['rename_count'] ?? 0));
    echo '<form method="post" action="' . e((string) ($viewModel['apply_url'] ?? '')) . '" class="admin-inline-form" data-admin-media-renamer-form data-media-renamer-target="#admin-edit-renamer" data-media-renamer-confirm="' . e(t('admin.media_renamer.apply_gallery_confirm', 'Physically rename the planned files in this gallery now?')) . '">';
    echo (string) ($viewModel['csrf_html'] ?? '');
    echo '<input type="hidden" name="id" value="' . (int) ($viewModel['gallery_id'] ?? 0) . '">';
    echo '<input type="hidden" name="return_tab" value="admin-edit-renamer">';
    echo '<input type="hidden" name="renamer_pattern" value="' . e((string) ($viewModel['pattern'] ?? '')) . '">';
    echo '<label class="checkbox-label"><input type="checkbox" name="confirm_media_rename" value="1"' . ($renameCount > 0 ? '' : ' disabled') . '> ' . e(t('admin.media_renamer.reviewed_checkbox', 'I reviewed the preview and want to rename files on disk.')) . '</label>';
    echo '<button type="submit" name="action" value="rename_files" class="secondary danger"' . ($renameCount > 0 ? '' : ' disabled') . '>' . e(t('admin.media_renamer.apply_gallery_button', 'Apply rename to this gallery')) . '</button>';
    if ($renameCount <= 0) {
        echo '<span class="muted">' . e(t('admin.media_renamer.nothing_to_rename', 'No files currently need renaming.')) . '</span>';
    }
    echo '</form><div class="thumbnail-progress admin-media-renamer-progress" data-admin-media-renamer-progress hidden><progress class="thumbnail-progress-bar" value="0" max="100" data-admin-media-renamer-progress-bar></progress><p class="muted" data-admin-media-renamer-progress-text></p></div>';
    echo '</div>';
}

/**
 * Render the gallery-level GET pattern preview form.
 *
 * @param array<string,mixed> $viewModel Controller-prepared pattern-form state.
 */
function view_render_admin_media_renamer_pattern_preview_form(array $viewModel): void
{
    echo '<form method="get" action="' . e((string) ($viewModel['action_url'] ?? '')) . '" class="admin-edit-card is-wide admin-media-renamer-pattern-form" data-admin-media-renamer-form data-media-renamer-target="#admin-edit-renamer">';
    echo '<input type="hidden" name="page" value="admin_edit_gallery">';
    echo '<input type="hidden" name="id" value="' . (int) ($viewModel['gallery_id'] ?? 0) . '">';
    echo '<input type="hidden" name="tab" value="admin-edit-renamer">';
    echo '<label>' . e(t('admin.media_renamer.pattern_label', 'Filename pattern')) . '<input type="text" name="renamer_pattern" value="' . e((string) ($viewModel['pattern'] ?? '')) . '" placeholder="' . e((string) ($viewModel['default_pattern'] ?? '')) . '"><span class="muted">' . e(t('admin.media_renamer.pattern_help', 'Wildcards: {wildcards}', ['wildcards' => (string) ($viewModel['pattern_help'] ?? '')])) . '</span></label>';
    echo '<button type="submit" class="secondary">' . e(t('admin.media_renamer.update_preview_button', 'Update preview')) . '</button>';
    echo '</form>';
}

/**
 * Render the site-wide gallery selection form.
 *
 * @param array<string,mixed> $viewModel Controller-prepared scope state.
 */
function view_render_admin_media_renamer_scope_form(array $viewModel): void
{
    $galleryRows = (array) ($viewModel['gallery_rows'] ?? []);
    $selectedScope = (string) ($viewModel['selected_scope'] ?? 'selected');
    $selectedMap = array_fill_keys(array_map('intval', (array) ($viewModel['selected_gallery_ids'] ?? [])), true);
    $selectedSingleGalleryId = (int) ($viewModel['selected_single_gallery_id'] ?? 0);
    $renameAvailabilityChecked = !empty($viewModel['rename_availability_checked']);
    $pendingTotal = max(0, (int) ($viewModel['pending_total'] ?? 0));

    echo '<section class="panel admin-media-renamer-scope">';
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.media_renamer.scope_kicker', 'Scope')) . '</p><h2>' . e(t('admin.media_renamer.scope_title', 'Choose galleries')) . '</h2></div><p class="muted">' . e(t('admin.media_renamer.scope_help', 'Start with a dry-run preview. Applying a rename requires a second confirmation.')) . '</p></div>';
    echo '<form method="post" action="' . e((string) ($viewModel['action_url'] ?? '')) . '" data-admin-media-renamer-form data-media-renamer-target="[data-admin-media-renamer-workspace=site]">';
    echo (string) ($viewModel['csrf_html'] ?? '');
    echo '<div class="admin-media-renamer-scope-grid">';
    echo '<fieldset class="admin-edit-card admin-media-renamer-scope-card"><legend>' . e(t('admin.media_renamer.scope_mode_legend', 'Rename scope')) . '</legend>';
    echo '<div class="admin-media-renamer-scope-buttons" role="radiogroup" aria-label="' . e(t('admin.media_renamer.scope_mode_legend', 'Rename scope')) . '">';
    echo '<label class="admin-media-renamer-scope-option"><input type="radio" name="renamer_scope" value="all"' . ($selectedScope === 'all' ? ' checked' : '') . '><span class="admin-media-renamer-scope-button"><strong>' . e(t('admin.media_renamer.scope_all', 'All galleries')) . '</strong><small>' . e(t('admin.media_renamer.scope_all_help', 'Preview every gallery currently visible in this selector.')) . '</small></span></label>';
    echo '<label class="admin-media-renamer-scope-option"><input type="radio" name="renamer_scope" value="single"' . ($selectedScope === 'single' ? ' checked' : '') . '><span class="admin-media-renamer-scope-button"><strong>' . e(t('admin.media_renamer.scope_single', 'Single gallery')) . '</strong><small>' . e(t('admin.media_renamer.scope_single_help', 'Choose exactly one gallery from the dropdown.')) . '</small></span></label>';
    echo '<label class="admin-media-renamer-scope-option"><input type="radio" name="renamer_scope" value="selected"' . ($selectedScope === 'selected' ? ' checked' : '') . '><span class="admin-media-renamer-scope-button"><strong>' . e(t('admin.media_renamer.scope_selected', 'Checked galleries')) . '</strong><small>' . e(t('admin.media_renamer.scope_selected_help', 'Use the checkboxes in the gallery table below.')) . '</small></span></label>';
    echo '</div></fieldset>';

    echo '<div class="admin-edit-card admin-media-renamer-options-card">';
    echo '<label>' . e(t('admin.media_renamer.single_gallery', 'Single gallery')) . '<select name="single_gallery_id"><option value="0">' . e(t('admin.media_renamer.choose_gallery', 'Choose gallery')) . '</option>';
    foreach ($galleryRows as $gallery) {
        $id = (int) ($gallery['id'] ?? 0);
        echo '<option value="' . $id . '"' . ($selectedSingleGalleryId === $id ? ' selected' : '') . '>' . e((string) ($gallery['selector_label'] ?? ('#' . $id))) . '</option>';
    }
    echo '</select><span class="muted">' . e(t('admin.media_renamer.single_gallery_help', 'Use this for a focused site-wide operation outside the gallery editor.')) . '</span></label>';
    echo '<label class="checkbox-label admin-media-renamer-hide-empty"><input type="checkbox" name="hide_empty_galleries" value="1"' . (!empty($viewModel['hide_empty_galleries']) ? ' checked' : '') . '> <span><strong>' . e(t('admin.media_renamer.hide_empty_galleries', 'Hide galleries with 0 pictures')) . '</strong><small>' . e(t('admin.media_renamer.hide_empty_galleries_help', 'Useful for parent folders that only contain subgalleries. When enabled, All galleries also skips them.')) . '</small></span></label>';
    echo '<label class="checkbox-label admin-media-renamer-hide-done"><input type="checkbox" name="hide_done_galleries" value="1"' . (!empty($viewModel['hide_done_galleries']) ? ' checked' : '') . '> <span><strong>' . e(t('admin.media_renamer.hide_done_galleries', 'Hide galleries with 0 pictures to rename')) . '</strong><small>' . e(t('admin.media_renamer.hide_done_galleries_help', 'Uses the latest availability check. Already-renamed galleries stay visible until you run Check availability.')) . '</small></span></label>';
    echo '<input type="hidden" name="rename_availability_checked" value="' . ($renameAvailabilityChecked ? '1' : '0') . '">';
    echo '<input type="hidden" name="rename_availability_payload" value="' . e((string) ($viewModel['availability_payload'] ?? '')) . '">';
    $checkAvailabilityLabel = t('admin.media_renamer.check_availability_button', 'Check availability');
    echo '<button type="submit" class="secondary" name="renamer_action" value="check_availability" data-media-renamer-availability-button data-original-label="' . e($checkAvailabilityLabel) . '">' . e($checkAvailabilityLabel) . '</button>';
    if ($renameAvailabilityChecked) {
        echo '<span class="muted">' . e(t('admin.media_renamer.availability_summary', '{count} files still need renaming in the shown galleries.', ['count' => (string) $pendingTotal])) . '</span>';
    }
    echo '</div>';
    echo '<div class="admin-edit-card admin-media-renamer-pattern-card"><label>' . e(t('admin.media_renamer.pattern_label', 'Filename pattern')) . '<input type="text" name="renamer_pattern" value="' . e((string) ($viewModel['pattern'] ?? '')) . '" placeholder="' . e((string) ($viewModel['default_pattern'] ?? '')) . '"><span class="muted">' . e(t('admin.media_renamer.pattern_help', 'Wildcards: {wildcards}', ['wildcards' => (string) ($viewModel['pattern_help'] ?? '')])) . '</span></label></div></div>';

    echo '<div class="admin-media-renamer-gallery-list-header"><strong>' . e(t('admin.media_renamer.gallery_list_title', 'Gallery list')) . '</strong><span class="muted">' . e(t('admin.media_renamer.gallery_list_count', '{count} galleries shown', ['count' => (string) count($galleryRows)])) . '</span></div>';
    echo '<div class="admin-log-table-wrap"><table class="admin-log-table admin-media-renamer-gallery-table"><thead><tr><th>' . e(t('admin.media_renamer.select', 'Select')) . '</th><th>' . e(t('admin.media_renamer.gallery', 'Gallery')) . '</th><th>' . e(t('admin.media_renamer.path', 'Path')) . '</th><th>' . e(t('admin.media_renamer.images', 'Images')) . '</th><th>' . e(t('admin.media_renamer.to_rename', 'To rename')) . '</th></tr></thead><tbody>';
    foreach ($galleryRows as $gallery) {
        $id = (int) ($gallery['id'] ?? 0);
        echo '<tr><td><input type="checkbox" name="gallery_ids[]" value="' . $id . '"' . (isset($selectedMap[$id]) ? ' checked' : '') . '></td><td><a href="' . e((string) ($gallery['edit_url'] ?? '')) . '">' . e((string) ($gallery['title'] ?? ('#' . $id))) . '</a></td><td>' . e((string) ($gallery['folder_path'] ?? '')) . '</td><td>' . (int) ($gallery['direct_image_count'] ?? 0) . '</td><td>' . ($renameAvailabilityChecked ? (int) ($gallery['rename_candidate_count'] ?? 0) : e(t('admin.media_renamer.not_checked', 'Not checked'))) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<div class="admin-edit-gallery-savebar"><button type="submit" name="renamer_action" value="preview">' . e(t('admin.media_renamer.preview_button', 'Preview rename plan')) . '</button><span class="muted">' . e(t('admin.media_renamer.preview_button_help', 'No file or database changes are made during preview.')) . '</span></div>';
    echo '</form></section>';
}

/**
 * Render the site-wide apply confirmation form.
 *
 * @param array<string,mixed> $viewModel Controller-prepared apply state.
 */
function view_render_admin_media_renamer_apply_form(array $viewModel): void
{
    $renameCount = max(0, (int) ($viewModel['rename_count'] ?? 0));
    $candidateImageIds = array_map('intval', (array) ($viewModel['candidate_image_ids'] ?? []));
    echo '<form method="post" action="' . e((string) ($viewModel['action_url'] ?? '')) . '" class="admin-inline-form" data-admin-media-renamer-form data-media-renamer-target="[data-admin-media-renamer-workspace=site]" data-media-renamer-confirm="' . e(t('admin.media_renamer.apply_site_confirm', 'Physically rename the planned files now?')) . '" data-media-renamer-apply-total="' . count($candidateImageIds) . '">';
    echo (string) ($viewModel['csrf_html'] ?? '');
    echo '<input type="hidden" name="renamer_action" value="apply">';
    echo '<input type="hidden" name="renamer_scope" value="' . e((string) ($viewModel['selected_scope'] ?? 'selected')) . '">';
    echo '<input type="hidden" name="single_gallery_id" value="' . (int) ($viewModel['selected_single_gallery_id'] ?? 0) . '">';
    echo '<input type="hidden" name="renamer_pattern" value="' . e((string) ($viewModel['pattern'] ?? '')) . '">';
    echo '<input type="hidden" name="hide_empty_galleries" value="' . (!empty($viewModel['hide_empty_galleries']) ? '1' : '0') . '">';
    echo '<input type="hidden" name="hide_done_galleries" value="' . (!empty($viewModel['hide_done_galleries']) ? '1' : '0') . '">';
    echo '<input type="hidden" name="rename_availability_checked" value="' . (!empty($viewModel['rename_availability_checked']) ? '1' : '0') . '">';
    echo '<input type="hidden" name="rename_availability_payload" value="' . e((string) ($viewModel['availability_payload'] ?? '')) . '">';
    foreach ((array) ($viewModel['selected_gallery_ids'] ?? []) as $galleryId) {
        echo '<input type="hidden" name="gallery_ids[]" value="' . (int) $galleryId . '">';
    }
    foreach ($candidateImageIds as $imageId) {
        echo '<input type="hidden" name="rename_candidate_image_ids[]" value="' . $imageId . '">';
    }
    echo '<label class="checkbox-label"><input type="checkbox" name="confirm_media_rename" value="1"' . ($renameCount > 0 ? '' : ' disabled') . '> ' . e(t('admin.media_renamer.reviewed_checkbox', 'I reviewed the preview and want to rename files on disk.')) . '</label>';
    $buttonLabel = t('admin.media_renamer.apply_site_button', 'Apply planned renames');
    echo '<button type="submit" class="secondary danger" data-media-renamer-apply-button data-original-label="' . e($buttonLabel) . '"' . ($renameCount > 0 ? '' : ' disabled') . '>' . e($buttonLabel) . '</button>';
    if ($renameCount <= 0) {
        echo '<span class="muted">' . e(t('admin.media_renamer.nothing_to_rename', 'No files currently need renaming.')) . '</span>';
    }
    echo '</form>';
}

/**
 * Render dry-run plan tables grouped by gallery.
 *
 * @param array<string,mixed> $viewModel Controller-prepared plan-table state.
 */
function view_render_admin_media_renamer_plan_table(array $viewModel): void
{
    $aggregate = (array) ($viewModel['aggregate'] ?? []);
    echo '<div class="admin-metric-grid admin-media-renamer-summary">';
    echo '<div class="admin-metric-card"><span>' . e(t('admin.media_renamer.metric_total', 'Files')) . '</span><strong>' . (int) ($aggregate['total'] ?? 0) . '</strong><small>' . e(t('admin.media_renamer.metric_total_help', 'Direct images in the selected galleries.')) . '</small></div>';
    echo '<div class="admin-metric-card"><span>' . e(t('admin.media_renamer.metric_rename', 'Will rename')) . '</span><strong>' . (int) ($aggregate['rename'] ?? 0) . '</strong><small>' . e(t('admin.media_renamer.metric_rename_help', 'Physical files and database rows to update.')) . '</small></div>';
    echo '<div class="admin-metric-card"><span>' . e(t('admin.media_renamer.metric_ok', 'Already ok')) . '</span><strong>' . (int) ($aggregate['already_matches'] ?? 0) . '</strong><small>' . e(t('admin.media_renamer.metric_ok_help', 'Files already match the generated name.')) . '</small></div>';
    echo '<div class="admin-metric-card"><span>' . e(t('admin.media_renamer.metric_warnings', 'Warnings')) . '</span><strong>' . (int) (($aggregate['warnings'] ?? 0) + ($aggregate['missing'] ?? 0) + ($aggregate['collision'] ?? 0) + ($aggregate['skipped'] ?? 0)) . '</strong><small>' . e(t('admin.media_renamer.metric_warnings_help', 'Missing files, collisions, suffix adjustments, or skipped rows.')) . '</small></div>';
    echo '</div>';

    foreach ((array) ($viewModel['plans'] ?? []) as $plan) {
        $gallery = (array) ($plan['gallery'] ?? []);
        $items = (array) ($plan['items'] ?? []);
        echo '<section class="admin-edit-card is-wide admin-media-renamer-plan-card">';
        echo '<h3>' . e((string) ($gallery['title'] ?? t('admin.media_renamer.untitled_gallery', 'Untitled gallery'))) . '</h3>';
        echo '<p class="muted">' . e((string) ($gallery['folder_path'] ?? '')) . '</p>';
        if (!$items) {
            echo '<p class="muted">' . e(t('admin.media_renamer.no_images', 'This gallery has no direct image files to rename.')) . '</p>';
            echo '</section>';
            continue;
        }

        echo '<div class="admin-log-table-wrap"><table class="admin-log-table admin-media-renamer-plan-table"><thead><tr><th>' . e(t('admin.media_renamer.old_name', 'Old filename')) . '</th><th>' . e(t('admin.media_renamer.new_name', 'Suggested filename')) . '</th><th>' . e(t('admin.media_renamer.status', 'Status')) . '</th><th>' . e(t('admin.media_renamer.notes', 'Notes')) . '</th></tr></thead><tbody>';
        foreach ($items as $item) {
            echo '<tr class="admin-media-renamer-row is-' . e((string) ($item['status'] ?? 'skipped')) . '">';
            echo '<td><code>' . e((string) ($item['old_relative_path'] ?? '')) . '</code></td>';
            echo '<td><code>' . e((string) ($item['new_relative_path'] ?? '')) . '</code></td>';
            echo '<td>' . e((string) ($item['status_label'] ?? '')) . '</td>';
            $notes = (string) ($item['notes_text'] ?? '');
            echo '<td>' . ($notes !== '' ? e($notes) : '<span class="muted">' . e(t('admin.media_renamer.no_notes', 'No notes.')) . '</span>') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></section>';
    }
}

/**
 * Render execution details after an apply run.
 *
 * @param array<string,mixed> $viewModel Controller-prepared execution state.
 */
function view_render_admin_media_renamer_execution_details(array $viewModel): void
{
    $details = (array) ($viewModel['details'] ?? []);
    if (!$details) {
        return;
    }
    echo '<section class="admin-edit-card is-wide admin-media-renamer-process-card">';
    echo '<h3>' . e(t('admin.media_renamer.process_title', 'Last run details')) . '</h3>';
    echo '<p class="muted">' . e(t('admin.media_renamer.process_help', 'This shows exactly which files were renamed, skipped, already matched, or failed safety checks.')) . '</p>';
    echo '<div class="admin-log-table-wrap"><table class="admin-log-table admin-media-renamer-process-table"><thead><tr><th>' . e(t('admin.media_renamer.gallery', 'Gallery')) . '</th><th>' . e(t('admin.media_renamer.old_name', 'Old filename')) . '</th><th>' . e(t('admin.media_renamer.new_name', 'Suggested filename')) . '</th><th>' . e(t('admin.media_renamer.status', 'Status')) . '</th><th>' . e(t('admin.media_renamer.notes', 'Notes')) . '</th></tr></thead><tbody>';
    foreach ($details as $detail) {
        echo '<tr class="admin-media-renamer-row is-' . e((string) ($detail['status'] ?? 'skipped')) . '">';
        echo '<td>' . e((string) ($detail['gallery'] ?? '')) . '</td>';
        echo '<td><code>' . e((string) ($detail['old'] ?? '')) . '</code></td>';
        echo '<td><code>' . e((string) ($detail['new'] ?? '')) . '</code></td>';
        echo '<td>' . e((string) ($detail['status_label'] ?? '')) . '</td>';
        echo '<td>' . e((string) ($detail['notes_text'] ?? '')) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div></section>';
}

/**
 * Render one trusted Media Renamer fragment prepared by its controller wrapper.
 *
 * @param string $html Prepared Media Renamer HTML fragment.
 */
function view_render_admin_media_renamer_fragment(string $html): void
{
    echo $html;
}
