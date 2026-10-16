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
 * @param array{page_title?:string,admin_url?:string,workspace_html?:string} $viewModel Controller-prepared page state.
 * @return void Writes escaped presentation markup.
 */
function view_render_admin_media_renamer_page(array $viewModel): void
{
    render_header((string) ($viewModel['page_title'] ?? ''));
    echo '<section class="admin-dashboard-hero admin-media-renamer-hero">';
    echo '<div><p class="admin-kicker">' . e(t('admin.media_renamer.kicker', 'Maintenance')) . '</p><h1>' . e(t('admin.media_renamer.heading', 'Context-aware file renamer')) . '</h1><details class="admin-inline-help"><summary aria-label="' . e(t('admin.media_renamer.help', 'Help')) . '"><span aria-hidden="true">?</span></summary><div class="admin-inline-help-content">' . e(t('admin.media_renamer.intro', 'Preview and physically rename image files from gallery context and photo order. Database rows, generated derivatives, public paths, and stale download ZIP archives are updated after execution.')) . '</div></details></div>';
    echo '<nav class="admin-hero-actions"><a class="button secondary" href="' . e((string) ($viewModel['admin_url'] ?? '')) . '" aria-label="' . e(t('admin.media_renamer.back_to_admin', 'Back to admin')) . '" title="' . e(t('admin.media_renamer.back_to_admin', 'Back to admin')) . '"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m12 5-7 7 7 7M5 12h15"/></svg></a></nav>';
    echo '</section>';
    echo (string) ($viewModel['workspace_html'] ?? '');
    render_footer();
}

/**
 * Render the site-wide Media Renamer workspace.
 *
 * @param array{log_url?:string,notice?:string,scope_form_html?:string,has_plans?:bool,plan_table_html?:string,execution_details_html?:string,apply_form_html?:string} $viewModel Controller-prepared workspace state.
 * @return void Writes escaped presentation markup.
 */
function view_render_admin_media_renamer_site_workspace(array $viewModel): void
{
    echo '<div class="admin-media-renamer-workspace" data-admin-media-renamer-workspace="site" data-media-renamer-log-url="' . e((string) ($viewModel['log_url'] ?? '')) . '">';
    echo '<div class="thumbnail-progress admin-media-renamer-progress" data-admin-media-renamer-progress role="status" aria-live="polite" hidden><progress class="thumbnail-progress-bar" value="0" max="100" data-admin-media-renamer-progress-bar></progress><p class="muted" data-admin-media-renamer-progress-text></p></div>';
    $notice = (string) ($viewModel['notice'] ?? '');
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }
    echo (string) ($viewModel['scope_form_html'] ?? '');

    if (!empty($viewModel['has_plans'])) {
        echo '<section class="panel admin-media-renamer-preview">';
        echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.media_renamer.preview_kicker', 'Dry run')) . '</p><h2>' . e(t('admin.media_renamer.preview_title', 'Generated rename plan')) . '</h2></div><details class="admin-inline-help"><summary aria-label="' . e(t('admin.media_renamer.help', 'Help')) . '"><span aria-hidden="true">?</span></summary><div class="admin-inline-help-content">' . e(t('admin.media_renamer.preview_help', 'Review every old-to-new filename mapping before applying. Missing files and unsafe collisions are skipped.')) . '</div></details></div>';
        echo (string) ($viewModel['plan_table_html'] ?? '');
        echo (string) ($viewModel['execution_details_html'] ?? '');
        echo (string) ($viewModel['apply_form_html'] ?? '');
        echo '</section>';
    }
    echo '</div>';
}

/**
 * Render the gallery-level Media Renamer panel.
 *
 * @param array<string,mixed> $viewModel Controller-prepared gallery-panel state.
 * @return void Render the gallery-level Media Renamer panel.
 */
function view_render_admin_media_renamer_gallery_panel(array $viewModel): void
{
    echo '<div class="admin-media-renamer-workspace" data-admin-media-renamer-workspace="gallery" data-media-renamer-log-url="' . e((string) ($viewModel['log_url'] ?? '')) . '">';
    $notice = (string) ($viewModel['notice'] ?? '');
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }
    echo '<div class="admin-media-renamer-heading"><p class="admin-kicker">' . e(t('admin.media_renamer.gallery_kicker', 'File maintenance')) . '</p><h2>' . e(t('admin.media_renamer.gallery_title', 'Rename files in this gallery')) . '</h2><details class="admin-inline-help"><summary aria-label="' . e(t('admin.media_renamer.gallery_title', 'Rename files in this gallery')) . '" title="' . e(t('admin.media_renamer.gallery_title', 'Rename files in this gallery')) . '"><span aria-hidden="true">?</span></summary><div class="admin-inline-help-content">' . e(t('admin.media_renamer.gallery_help', 'Generated names use this gallery folder context and the current image order. This physically renames files on disk.')) . '</div></details></div>';
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
    echo '<label class="checkbox-label admin-media-renamer-confirm"><input type="checkbox" name="confirm_media_rename" value="1"' . ($renameCount > 0 ? '' : ' disabled') . '> ' . e(t('admin.media_renamer.reviewed_checkbox', 'I reviewed the preview and want to rename files on disk.')) . '</label>';
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
 * @return void Render the gallery-level GET pattern preview form.
 */
function view_render_admin_media_renamer_pattern_preview_form(array $viewModel): void
{
    echo '<form method="get" action="' . e((string) ($viewModel['action_url'] ?? '')) . '" class="admin-edit-card is-wide admin-media-renamer-pattern-form" data-admin-media-renamer-form data-media-renamer-target="#admin-edit-renamer">';
    echo '<input type="hidden" name="page" value="admin_edit_gallery">';
    echo '<input type="hidden" name="id" value="' . (int) ($viewModel['gallery_id'] ?? 0) . '">';
    echo '<input type="hidden" name="tab" value="admin-edit-renamer">';
    echo '<label>' . e(t('admin.media_renamer.pattern_label', 'Filename pattern')) . '<input type="text" name="renamer_pattern" value="' . e((string) ($viewModel['pattern'] ?? '')) . '" placeholder="' . e((string) ($viewModel['default_pattern'] ?? '')) . '"></label>';
    echo '<details class="admin-inline-help admin-media-renamer-pattern-help"><summary aria-label="' . e(t('admin.media_renamer.pattern_label', 'Filename pattern')) . '" title="' . e(t('admin.media_renamer.pattern_label', 'Filename pattern')) . '"><span aria-hidden="true">?</span></summary><div class="admin-inline-help-content">' . e(t('admin.media_renamer.pattern_help', 'Wildcards: {wildcards}', ['wildcards' => (string) ($viewModel['pattern_help'] ?? '')])) . '</div></details>';
    echo '<button type="submit" class="secondary">' . e(t('admin.media_renamer.update_preview_button', 'Update preview')) . '</button>';
    echo '</form>';
}

/**
 * Render the site-wide gallery selection form.
 *
 * @param array{gallery_rows?:list<array{id:int,title:string,selector_label:string,edit_url:string,folder_path:string,direct_image_count:int,rename_candidate_count?:int}>,selected_scope?:string,selected_gallery_ids?:list<int>,selected_single_gallery_id?:int,rename_availability_checked?:bool,pending_total?:int,action_url?:string,csrf_html?:string,hide_empty_galleries?:bool,hide_done_galleries?:bool,availability_payload?:string,pattern?:string,default_pattern?:string,pattern_help?:string} $viewModel Controller-prepared scope state.
 * @return void Writes escaped presentation markup.
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
    echo '<div class="admin-tab-intro"><div><h2>' . e(t('admin.media_renamer.scope_title', 'Choose galleries')) . '</h2></div><details class="admin-inline-help"><summary aria-label="' . e(t('admin.media_renamer.help', 'Help')) . '"><span aria-hidden="true">?</span></summary><div class="admin-inline-help-content">' . e(t('admin.media_renamer.scope_help', 'Start with a dry-run preview. Applying a rename requires a second confirmation.')) . '</div></details></div>';
    echo '<form method="post" action="' . e((string) ($viewModel['action_url'] ?? '')) . '" data-admin-media-renamer-form data-media-renamer-target="[data-admin-media-renamer-workspace=site]">';
    echo (string) ($viewModel['csrf_html'] ?? '');
    echo '<div class="admin-media-renamer-scope-grid">';
    echo '<fieldset class="admin-edit-card admin-media-renamer-scope-card"><legend>' . e(t('admin.media_renamer.scope_mode_legend', 'Rename scope')) . '</legend>';
    echo '<div class="admin-media-renamer-scope-buttons" role="radiogroup" aria-label="' . e(t('admin.media_renamer.scope_mode_legend', 'Rename scope')) . '">';
    echo '<div class="admin-media-renamer-scope-option"><label><input type="radio" name="renamer_scope" value="all"' . ($selectedScope === 'all' ? ' checked' : '') . '><span class="admin-media-renamer-scope-button"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg><strong>' . e(t('admin.media_renamer.scope_all', 'All galleries')) . '</strong></span></label><details class="admin-inline-help"><summary aria-label="' . e(t('admin.media_renamer.help', 'Help')) . '">?</summary><div class="admin-inline-help-content">' . e(t('admin.media_renamer.scope_all_help', 'Preview every gallery currently visible in this selector.')) . '</div></details></div>';
    echo '<div class="admin-media-renamer-scope-option"><label><input type="radio" name="renamer_scope" value="single"' . ($selectedScope === 'single' ? ' checked' : '') . '><span class="admin-media-renamer-scope-button"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="4" y="5" width="16" height="14" rx="2"/><path d="m7 15 3-3 3 3 3-4 3 4"/></svg><strong>' . e(t('admin.media_renamer.scope_single', 'Single gallery')) . '</strong></span></label><details class="admin-inline-help"><summary aria-label="' . e(t('admin.media_renamer.help', 'Help')) . '">?</summary><div class="admin-inline-help-content">' . e(t('admin.media_renamer.scope_single_help', 'Choose exactly one gallery from the dropdown.')) . '</div></details></div>';
    echo '<div class="admin-media-renamer-scope-option"><label><input type="radio" name="renamer_scope" value="selected"' . ($selectedScope === 'selected' ? ' checked' : '') . '><span class="admin-media-renamer-scope-button"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m3 6 2 2 4-4M12 6h9M3 12l2 2 4-4M12 12h9M3 18l2 2 4-4M12 18h9"/></svg><strong>' . e(t('admin.media_renamer.scope_selected', 'Checked galleries')) . '</strong></span></label><details class="admin-inline-help"><summary aria-label="' . e(t('admin.media_renamer.help', 'Help')) . '">?</summary><div class="admin-inline-help-content">' . e(t('admin.media_renamer.scope_selected_help', 'Use the checkboxes in the gallery list below.')) . '</div></details></div>';
    echo '</div></fieldset>';

    echo '<div class="admin-edit-card admin-media-renamer-options-card">';
    echo '<label class="admin-media-renamer-single">' . e(t('admin.media_renamer.single_gallery', 'Single gallery')) . '<select name="single_gallery_id"><option value="0">' . e(t('admin.media_renamer.choose_gallery', 'Choose gallery')) . '</option>';
    foreach ($galleryRows as $gallery) {
        $id = (int) ($gallery['id'] ?? 0);
        echo '<option value="' . $id . '"' . ($selectedSingleGalleryId === $id ? ' selected' : '') . '>' . e((string) ($gallery['selector_label'] ?? ('#' . $id))) . '</option>';
    }
    echo '</select></label>';
    echo '<label class="checkbox-label admin-media-renamer-hide-empty"><input type="checkbox" name="hide_empty_galleries" value="1"' . (!empty($viewModel['hide_empty_galleries']) ? ' checked' : '') . '> <span><strong>' . e(t('admin.media_renamer.hide_empty_galleries', 'Hide galleries with 0 pictures')) . '</strong></span></label><details class="admin-inline-help"><summary aria-label="' . e(t('admin.media_renamer.help', 'Help')) . '">?</summary><div class="admin-inline-help-content">' . e(t('admin.media_renamer.hide_empty_galleries_help', 'Useful for parent folders that only contain subgalleries. When enabled, All galleries also skips them.')) . '</div></details>';
    echo '<label class="checkbox-label admin-media-renamer-hide-done"><input type="checkbox" name="hide_done_galleries" value="1"' . (!empty($viewModel['hide_done_galleries']) ? ' checked' : '') . '> <span><strong>' . e(t('admin.media_renamer.hide_done_galleries', 'Hide galleries with 0 pictures to rename')) . '</strong></span></label><details class="admin-inline-help"><summary aria-label="' . e(t('admin.media_renamer.help', 'Help')) . '">?</summary><div class="admin-inline-help-content">' . e(t('admin.media_renamer.hide_done_galleries_help', 'Uses the latest availability check. Already-renamed galleries stay visible until you run Check availability.')) . '</div></details>';
    echo '<input type="hidden" name="rename_availability_checked" value="' . ($renameAvailabilityChecked ? '1' : '0') . '">';
    echo '<input type="hidden" name="rename_availability_payload" value="' . e((string) ($viewModel['availability_payload'] ?? '')) . '">';
    $checkAvailabilityLabel = t('admin.media_renamer.check_availability_button', 'Check availability');
    echo '<button type="submit" class="secondary" name="renamer_action" value="check_availability" data-media-renamer-availability-button data-original-label="' . e($checkAvailabilityLabel) . '">' . e($checkAvailabilityLabel) . '</button>';
    if ($renameAvailabilityChecked) {
        echo '<span class="muted">' . e(t('admin.media_renamer.availability_summary', '{count} files still need renaming in the shown galleries.', ['count' => (string) $pendingTotal])) . '</span>';
    }
    echo '</div>';
    echo '<div class="admin-edit-card admin-media-renamer-pattern-card"><label>' . e(t('admin.media_renamer.pattern_label', 'Filename pattern')) . '<input type="text" name="renamer_pattern" value="' . e((string) ($viewModel['pattern'] ?? '')) . '" placeholder="' . e((string) ($viewModel['default_pattern'] ?? '')) . '"></label><details class="admin-inline-help"><summary aria-label="' . e(t('admin.media_renamer.help', 'Help')) . '">?</summary><div class="admin-inline-help-content">' . e(t('admin.media_renamer.pattern_help', 'Wildcards: {wildcards}', ['wildcards' => (string) ($viewModel['pattern_help'] ?? '')])) . '</div></details></div></div>';

    echo '<div class="admin-media-renamer-gallery-list-header"><strong>' . e(t('admin.media_renamer.gallery_list_title', 'Gallery list')) . '</strong><span class="muted">' . e(t('admin.media_renamer.gallery_list_count', '{count} galleries shown', ['count' => (string) count($galleryRows)])) . '</span></div>';
    echo '<label class="admin-media-renamer-search">' . e(t('admin.media_renamer.find_gallery', 'Find gallery')) . '<input type="search" data-media-renamer-search aria-controls="media-renamer-gallery-list" placeholder="' . e(t('admin.media_renamer.find_gallery', 'Find gallery')) . '"><output data-media-renamer-search-status aria-live="polite"></output></label>';
    echo '<div class="admin-media-renamer-gallery-picker" id="media-renamer-gallery-list" tabindex="0">';
    foreach ($galleryRows as $gallery) {
        $id = (int) ($gallery['id'] ?? 0);
        $title = (string) ($gallery['title'] ?? ('#' . $id));
        echo '<div class="admin-media-renamer-gallery-choice"><label><input type="checkbox" name="gallery_ids[]" value="' . $id . '"' . (isset($selectedMap[$id]) ? ' checked' : '') . '><span><strong>' . e($title) . '</strong><small title="' . e((string) ($gallery['folder_path'] ?? '')) . '">' . e((string) ($gallery['folder_path'] ?? '')) . '</small></span></label>';
        echo '<span class="admin-media-renamer-gallery-count" title="' . e(t('admin.media_renamer.images', 'Images')) . '">' . '<span class="subgallery-stack-icon" aria-hidden="true"><span></span><span></span><span></span></span>' . (int) ($gallery['direct_image_count'] ?? 0) . '</span>';
        echo '<span class="admin-media-renamer-gallery-count" title="' . e(t('admin.media_renamer.to_rename', 'To rename')) . '">' . '<span aria-hidden="true">→ </span>' . ($renameAvailabilityChecked ? '<span class="admin-media-renamer-status is-checked" role="img" aria-label="' . e(t('admin.media_renamer.checked', 'Checked')) . '" title="' . e(t('admin.media_renamer.checked', 'Checked')) . '">✓</span> ' . (int) ($gallery['rename_candidate_count'] ?? 0) : '<span class="admin-media-renamer-status" role="img" aria-label="' . e(t('admin.media_renamer.not_checked', 'Not checked')) . '" title="' . e(t('admin.media_renamer.not_checked', 'Not checked')) . '">○</span>') . '</span>';
        echo '<a class="button secondary" href="' . e((string) ($gallery['edit_url'] ?? '')) . '" aria-label="' . e($title . ' — ' . t('admin.media_renamer.open_gallery', 'Open gallery')) . '" title="' . e(t('admin.media_renamer.open_gallery', 'Open gallery')) . '"><span aria-hidden="true">↗</span></a></div>';
    }
    echo '</div>';
    echo '<div class="admin-edit-gallery-savebar"><button type="submit" name="renamer_action" value="preview">' . e(t('admin.media_renamer.preview_button', 'Preview rename plan')) . '</button><span class="muted">' . e(t('admin.media_renamer.preview_button_help', 'No file or database changes are made during preview.')) . '</span></div>';
    echo '<button type="submit" name="renamer_action" value="filter" data-media-renamer-filter-refresh hidden></button>';
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
 * @param array{aggregate?:array{total?:int,rename?:int,already_matches?:int,warnings?:int,missing?:int,collision?:int,skipped?:int},plans?:list<array{gallery:array{title?:string,folder_path?:string},items:list<array{status?:string,old_relative_path?:string,new_relative_path?:string,status_label?:string,notes_text?:string}>}>} $viewModel Controller-prepared plan-table state.
 * @return void Writes escaped presentation markup.
 */
function view_render_admin_media_renamer_plan_table(array $viewModel): void
{
    $aggregate = (array) ($viewModel['aggregate'] ?? []);
    echo '<div class="admin-metric-grid admin-media-renamer-summary">';
    echo '<div class="admin-metric-card"><span>' . e(t('admin.media_renamer.metric_total', 'Files')) . '</span><strong>' . (int) ($aggregate['total'] ?? 0) . '</strong><details class="admin-inline-help"><summary aria-label="' . e(t('admin.media_renamer.help', 'Help')) . '">?</summary><div class="admin-inline-help-content">' . e(t('admin.media_renamer.metric_total_help', 'Direct images in the selected galleries.')) . '</div></details></div>';
    echo '<div class="admin-metric-card"><span>' . e(t('admin.media_renamer.metric_rename', 'Will rename')) . '</span><strong>' . (int) ($aggregate['rename'] ?? 0) . '</strong><details class="admin-inline-help"><summary aria-label="' . e(t('admin.media_renamer.help', 'Help')) . '">?</summary><div class="admin-inline-help-content">' . e(t('admin.media_renamer.metric_rename_help', 'Physical files and database rows to update.')) . '</div></details></div>';
    echo '<div class="admin-metric-card"><span>' . e(t('admin.media_renamer.metric_ok', 'Already ok')) . '</span><strong>' . (int) ($aggregate['already_matches'] ?? 0) . '</strong><details class="admin-inline-help"><summary aria-label="' . e(t('admin.media_renamer.help', 'Help')) . '">?</summary><div class="admin-inline-help-content">' . e(t('admin.media_renamer.metric_ok_help', 'Files already match the generated name.')) . '</div></details></div>';
    echo '<div class="admin-metric-card"><span>' . e(t('admin.media_renamer.metric_warnings', 'Warnings')) . '</span><strong>' . (int) (($aggregate['warnings'] ?? 0) + ($aggregate['missing'] ?? 0) + ($aggregate['collision'] ?? 0) + ($aggregate['skipped'] ?? 0)) . '</strong><details class="admin-inline-help"><summary aria-label="' . e(t('admin.media_renamer.help', 'Help')) . '">?</summary><div class="admin-inline-help-content">' . e(t('admin.media_renamer.metric_warnings_help', 'Missing files, collisions, suffix adjustments, or skipped rows.')) . '</div></details></div>';
    echo '</div>';

    echo '<div class="admin-media-renamer-plan-list">';
    foreach ((array) ($viewModel['plans'] ?? []) as $plan) {
        $gallery = (array) ($plan['gallery'] ?? []);
        $items = (array) ($plan['items'] ?? []);
        $title = (string) ($gallery['title'] ?? t('admin.media_renamer.untitled_gallery', 'Untitled gallery'));
        $path = (string) ($gallery['folder_path'] ?? '');
        if (!$items) {
            echo '<div class="admin-media-renamer-empty-plan" title="' . e($path . ' — ' . t('admin.media_renamer.no_images', 'This gallery has no direct image files to rename.')) . '"><strong>' . e($title) . '</strong><span>' . e($path) . '</span><small>0 · ' . e(t('admin.media_renamer.metric_total', 'Files')) . '</small></div>';
            continue;
        }
        echo '<details class="admin-edit-card is-wide admin-media-renamer-plan-card">';
        echo '<summary><strong>' . e($title) . '</strong><code title="' . e($path) . '">' . e($path) . '</code><span>' . count($items) . ' · ' . e(t('admin.media_renamer.metric_total', 'Files')) . '</span></summary>';
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
        echo '</tbody></table></div></details>';
    }
    echo '</div>';
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
