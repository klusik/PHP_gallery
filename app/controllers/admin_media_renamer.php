<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_media_renamer.php
 * Module Type: Controller
 *
 * Purpose:
 *   Renders and handles admin UI for deterministic context-aware media renaming.
 *
 * Responsibilities:
 *   - Provide a site-wide media renamer page
 *   - Provide a reusable gallery-level renamer panel
 *   - Render dry-run plans before any physical rename is executed
 *   - Enforce existing admin authentication and CSRF checks
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
 *   2026-06-02
 */

declare(strict_types=1);

/**
 * Handle the site-wide media renamer admin page.
 */
function cms_admin_media_renamer(): void
{
    require_admin();

    $notice = '';
    $plans = [];
    $selectedScope = 'selected';
    $selectedGalleryIds = [];
    $selectedSingleGalleryId = 0;
    $pattern = media_renamer_default_pattern();
    $action = '';
    $lastResult = null;

    if (request_method() === 'POST') {
        if (!admin_media_renamer_verify_csrf_for_ajax()) {
            return;
        }
        $action = (string) ($_POST['renamer_action'] ?? 'preview');
        if ($action === 'client_error') {
            admin_media_renamer_handle_client_error();
            return;
        }
        $selectedScope = admin_media_renamer_scope_from_post();
        $selectedSingleGalleryId = (int) ($_POST['single_gallery_id'] ?? 0);
        $pattern = media_renamer_normalize_pattern((string) ($_POST['renamer_pattern'] ?? ''));
        $selectedGalleryIds = admin_media_renamer_gallery_ids_from_post($selectedScope);

        if (!$selectedGalleryIds) {
            $notice = t('admin.media_renamer.no_galleries_selected', 'Select at least one gallery to preview or rename.');
        } elseif ($action === 'apply') {
            if (empty($_POST['confirm_media_rename'])) {
                $notice = t('admin.media_renamer.confirm_required', 'Confirm that you reviewed the preview before applying physical renames.');
                $plans = media_renamer_plans_for_galleries($selectedGalleryIds, $pattern);
            } else {
                try {
                    $result = media_renamer_execute_galleries($selectedGalleryIds, $pattern);
                    $lastResult = $result;
                    $notice = admin_media_renamer_result_notice($result);
                    admin_media_renamer_log_event('info', 'media_renamer.site_completed', 'Site-wide media rename completed.', [
                        'selected_scope' => $selectedScope,
                        'selected_gallery_ids' => $selectedGalleryIds,
                        'pattern' => $pattern,
                        'result' => admin_media_renamer_loggable_result($result),
                    ], ['category' => 'media', 'severity' => 'info']);
                } catch (Throwable $exception) {
                    $notice = $exception->getMessage();
                    admin_media_renamer_log_exception('media_renamer.site_failed', 'Site-wide media rename failed.', $exception, [
                        'selected_scope' => $selectedScope,
                        'selected_gallery_ids' => $selectedGalleryIds,
                        'pattern' => $pattern,
                    ]);
                }
                $plans = media_renamer_plans_for_galleries($selectedGalleryIds, $pattern);
            }
        } else {
            $plans = media_renamer_plans_for_galleries($selectedGalleryIds, $pattern);
        }
    }

    $galleryRows = media_renamer_gallery_rows();
    if (admin_wants_json()) {
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'message' => $notice,
            'body_html' => admin_media_renamer_render_site_workspace($galleryRows, $selectedScope, $selectedGalleryIds, $selectedSingleGalleryId, $pattern, $plans, $notice, $lastResult),
        ]);
        return;
    }
    render_header(t('admin.media_renamer.page_title', 'Media renamer'), 'admin_media_renamer');

    echo '<section class="admin-dashboard-hero admin-media-renamer-hero">';
    echo '<div><p class="admin-kicker">' . e(t('admin.media_renamer.kicker', 'Maintenance')) . '</p><h1>' . e(t('admin.media_renamer.heading', 'Context-aware file renamer')) . '</h1><p class="muted">' . e(t('admin.media_renamer.intro', 'Preview and physically rename image files from gallery context and photo order. Database rows, generated derivatives, public paths, and stale download ZIP archives are updated after execution.')) . '</p></div>';
    echo '<nav class="admin-hero-actions"><a class="button secondary" href="' . e(url_for('admin')) . '">' . e(t('admin.media_renamer.back_to_admin', 'Back to admin')) . '</a></nav>';
    echo '</section>';

    echo admin_media_renamer_render_site_workspace($galleryRows, $selectedScope, $selectedGalleryIds, $selectedSingleGalleryId, $pattern, $plans, $notice, $lastResult);

    render_footer();
}

/**
 * Render the site-wide renamer workspace for normal and AJAX requests.
 *
 * @param array<int,array<string,mixed>> $galleryRows
 * @param array<int> $selectedGalleryIds
 * @param array<int,array<string,mixed>> $plans
 */
function admin_media_renamer_render_site_workspace(array $galleryRows, string $selectedScope, array $selectedGalleryIds, int $selectedSingleGalleryId, string $pattern, array $plans, string $notice = '', ?array $lastResult = null): string
{
    ob_start();
    echo '<div class="admin-media-renamer-workspace" data-admin-media-renamer-workspace="site" data-media-renamer-log-url="' . e(url_for('admin_media_renamer')) . '">';
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }
    render_admin_media_renamer_scope_form($galleryRows, $selectedScope, $selectedGalleryIds, $selectedSingleGalleryId, $pattern);

    if ($plans) {
        echo '<section class="panel admin-media-renamer-preview">';
        echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.media_renamer.preview_kicker', 'Dry run')) . '</p><h2>' . e(t('admin.media_renamer.preview_title', 'Generated rename plan')) . '</h2></div><p class="muted">' . e(t('admin.media_renamer.preview_help', 'Review every old-to-new filename mapping before applying. Missing files and unsafe collisions are skipped.')) . '</p></div>';
        render_admin_media_renamer_plan_table($plans);
        if ($lastResult !== null) {
            render_admin_media_renamer_execution_details((array) ($lastResult['details'] ?? []));
        }
        render_admin_media_renamer_apply_form($selectedScope, $selectedGalleryIds, $selectedSingleGalleryId, $pattern);
        echo '</section>';
    }
    echo '<div class="thumbnail-progress admin-media-renamer-progress" data-admin-media-renamer-progress hidden><progress class="thumbnail-progress-bar" value="0" max="100" data-admin-media-renamer-progress-bar></progress><p class="muted" data-admin-media-renamer-progress-text></p></div>';
    echo '</div>';
    return (string) ob_get_clean();
}

/**
 * Render the gallery editor panel for the current gallery only.
 */
function render_admin_media_renamer_gallery_panel(array $gallery): void
{
    $pattern = media_renamer_normalize_pattern((string) ($_GET['renamer_pattern'] ?? $_POST['renamer_pattern'] ?? ''));
    echo admin_media_renamer_render_gallery_panel_html($gallery, $pattern);
}

/**
 * Render the gallery-level renamer panel HTML so normal and AJAX requests share one view.
 */
function admin_media_renamer_render_gallery_panel_html(array $gallery, string $pattern, string $notice = '', ?array $result = null): string
{
    ob_start();
    $galleryId = (int) ($gallery['id'] ?? 0);
    $pattern = media_renamer_normalize_pattern($pattern);
    echo '<div class="admin-media-renamer-workspace" data-admin-media-renamer-workspace="gallery" data-media-renamer-log-url="' . e(url_for('admin_media_renamer')) . '">';
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.media_renamer.gallery_kicker', 'File maintenance')) . '</p><h2>' . e(t('admin.media_renamer.gallery_title', 'Rename files in this gallery')) . '</h2></div><p class="muted">' . e(t('admin.media_renamer.gallery_help', 'Generated names use this gallery folder context and the current image order. This physically renames files on disk.')) . '</p></div>';
    render_admin_media_renamer_pattern_preview_form($galleryId, $pattern);

    try {
        $plan = media_renamer_plan_for_gallery($galleryId, $pattern);
    } catch (Throwable $exception) {
        echo '<div class="notice">' . e($exception->getMessage()) . '</div></div>';
        return (string) ob_get_clean();
    }

    render_admin_media_renamer_plan_table([$plan]);
    if ($result !== null) {
        render_admin_media_renamer_execution_details((array) ($result['details'] ?? []));
    }

    $summary = (array) ($plan['summary'] ?? []);
    $renameCount = (int) ($summary['rename'] ?? 0);
    echo '<form method="post" action="' . e(url_for('admin_edit_gallery')) . '" class="admin-inline-form" data-admin-media-renamer-form data-media-renamer-target="#admin-edit-renamer" data-media-renamer-confirm="' . e(t('admin.media_renamer.apply_gallery_confirm', 'Physically rename the planned files in this gallery now?')) . '">' . csrf_field();
    echo '<input type="hidden" name="id" value="' . $galleryId . '">';
    echo '<input type="hidden" name="return_tab" value="admin-edit-renamer">';
    echo '<input type="hidden" name="renamer_pattern" value="' . e($pattern) . '">';
    echo '<label class="checkbox-label"><input type="checkbox" name="confirm_media_rename" value="1"' . ($renameCount > 0 ? '' : ' disabled') . '> ' . e(t('admin.media_renamer.reviewed_checkbox', 'I reviewed the preview and want to rename files on disk.')) . '</label>';
    echo '<button type="submit" name="action" value="rename_files" class="secondary danger"' . ($renameCount > 0 ? '' : ' disabled') . '>' . e(t('admin.media_renamer.apply_gallery_button', 'Apply rename to this gallery')) . '</button>';
    if ($renameCount <= 0) {
        echo '<span class="muted">' . e(t('admin.media_renamer.nothing_to_rename', 'No files currently need renaming.')) . '</span>';
    }
    echo '</form><div class="thumbnail-progress admin-media-renamer-progress" data-admin-media-renamer-progress hidden><progress class="thumbnail-progress-bar" value="0" max="100" data-admin-media-renamer-progress-bar></progress><p class="muted" data-admin-media-renamer-progress-text></p></div>';
    echo '</div>';
    return (string) ob_get_clean();
}

/**
 * Render a gallery-level GET form that changes only the dry-run pattern.
 */
function render_admin_media_renamer_pattern_preview_form(int $galleryId, string $pattern): void
{
    echo '<form method="get" action="' . e(url_for('admin_edit_gallery')) . '" class="admin-edit-card is-wide admin-media-renamer-pattern-form" data-admin-media-renamer-form data-media-renamer-target="#admin-edit-renamer">';
    echo '<input type="hidden" name="page" value="admin_edit_gallery">';
    echo '<input type="hidden" name="id" value="' . $galleryId . '">';
    echo '<input type="hidden" name="tab" value="admin-edit-renamer">';
    echo '<label>' . e(t('admin.media_renamer.pattern_label', 'Filename pattern')) . '<input type="text" name="renamer_pattern" value="' . e($pattern) . '" placeholder="' . e(media_renamer_default_pattern()) . '"><span class="muted">' . e(t('admin.media_renamer.pattern_help', 'Wildcards: {wildcards}', ['wildcards' => media_renamer_pattern_help_text()])) . '</span></label>';
    echo '<button type="submit" class="secondary">' . e(t('admin.media_renamer.update_preview_button', 'Update preview')) . '</button>';
    echo '</form>';
}

/**
 * Render the site-wide gallery selection form.
 *
 * @param array<int,array<string,mixed>> $galleryRows
 * @param array<int> $selectedGalleryIds
 */
function render_admin_media_renamer_scope_form(array $galleryRows, string $selectedScope, array $selectedGalleryIds, int $selectedSingleGalleryId, string $pattern): void
{
    $selectedMap = array_fill_keys(array_map('intval', $selectedGalleryIds), true);

    echo '<section class="panel admin-media-renamer-scope">';
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.media_renamer.scope_kicker', 'Scope')) . '</p><h2>' . e(t('admin.media_renamer.scope_title', 'Choose galleries')) . '</h2></div><p class="muted">' . e(t('admin.media_renamer.scope_help', 'Start with a dry-run preview. Applying a rename requires a second confirmation.')) . '</p></div>';
    echo '<form method="post" action="' . e(url_for('admin_media_renamer')) . '" data-admin-media-renamer-form data-media-renamer-target="[data-admin-media-renamer-workspace=site]">' . csrf_field();
    echo '<div class="admin-edit-card-grid">';
    echo '<div class="admin-edit-card">';
    echo '<label class="checkbox-label"><input type="radio" name="renamer_scope" value="all"' . ($selectedScope === 'all' ? ' checked' : '') . '> ' . e(t('admin.media_renamer.scope_all', 'All galleries')) . '</label>';
    echo '<label class="checkbox-label"><input type="radio" name="renamer_scope" value="single"' . ($selectedScope === 'single' ? ' checked' : '') . '> ' . e(t('admin.media_renamer.scope_single', 'Single gallery')) . '</label>';
    echo '<label class="checkbox-label"><input type="radio" name="renamer_scope" value="selected"' . ($selectedScope === 'selected' ? ' checked' : '') . '> ' . e(t('admin.media_renamer.scope_selected', 'Checked galleries')) . '</label>';
    echo '</div>';
    echo '<div class="admin-edit-card">';
    echo '<label>' . e(t('admin.media_renamer.single_gallery', 'Single gallery')) . '<select name="single_gallery_id"><option value="0">' . e(t('admin.media_renamer.choose_gallery', 'Choose gallery')) . '</option>';
    foreach ($galleryRows as $gallery) {
        $id = (int) ($gallery['id'] ?? 0);
        echo '<option value="' . $id . '"' . ($selectedSingleGalleryId === $id ? ' selected' : '') . '>' . e((string) ($gallery['folder_path'] ?? $gallery['title'] ?? ('#' . $id))) . '</option>';
    }
    echo '</select><span class="muted">' . e(t('admin.media_renamer.single_gallery_help', 'Use this for a focused site-wide operation outside the gallery editor.')) . '</span></label>';
    echo '</div>';
    echo '<div class="admin-edit-card is-wide"><label>' . e(t('admin.media_renamer.pattern_label', 'Filename pattern')) . '<input type="text" name="renamer_pattern" value="' . e($pattern) . '" placeholder="' . e(media_renamer_default_pattern()) . '"><span class="muted">' . e(t('admin.media_renamer.pattern_help', 'Wildcards: {wildcards}', ['wildcards' => media_renamer_pattern_help_text()])) . '</span></label></div></div>';

    echo '<div class="admin-log-table-wrap"><table class="admin-log-table admin-media-renamer-gallery-table"><thead><tr><th>' . e(t('admin.media_renamer.select', 'Select')) . '</th><th>' . e(t('admin.media_renamer.gallery', 'Gallery')) . '</th><th>' . e(t('admin.media_renamer.path', 'Path')) . '</th><th>' . e(t('admin.media_renamer.images', 'Images')) . '</th></tr></thead><tbody>';
    foreach ($galleryRows as $gallery) {
        $id = (int) ($gallery['id'] ?? 0);
        echo '<tr><td><input type="checkbox" name="gallery_ids[]" value="' . $id . '"' . (isset($selectedMap[$id]) ? ' checked' : '') . '></td><td><a href="' . e(url_for('admin_edit_gallery', ['id' => $id, 'tab' => 'admin-edit-renamer']) . '#admin-edit-renamer') . '">' . e((string) ($gallery['title'] ?? ('#' . $id))) . '</a></td><td>' . e((string) ($gallery['folder_path'] ?? '')) . '</td><td>' . (int) ($gallery['direct_image_count'] ?? 0) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<div class="admin-edit-gallery-savebar"><button type="submit" name="renamer_action" value="preview">' . e(t('admin.media_renamer.preview_button', 'Preview rename plan')) . '</button><span class="muted">' . e(t('admin.media_renamer.preview_button_help', 'No file or database changes are made during preview.')) . '</span></div>';
    echo '</form></section>';
}

/**
 * Render the apply form for a previously previewed site-wide plan.
 *
 * @param array<int> $selectedGalleryIds
 */
function render_admin_media_renamer_apply_form(string $selectedScope, array $selectedGalleryIds, int $selectedSingleGalleryId, string $pattern): void
{
    $aggregate = admin_media_renamer_aggregate_plans(media_renamer_plans_for_galleries($selectedGalleryIds, $pattern));
    $renameCount = (int) ($aggregate['rename'] ?? 0);

    echo '<form method="post" action="' . e(url_for('admin_media_renamer')) . '" class="admin-inline-form" data-admin-media-renamer-form data-media-renamer-target="[data-admin-media-renamer-workspace=site]" data-media-renamer-confirm="' . e(t('admin.media_renamer.apply_site_confirm', 'Physically rename the planned files now?')) . '">' . csrf_field();
    echo '<input type="hidden" name="renamer_action" value="apply">';
    echo '<input type="hidden" name="renamer_scope" value="' . e($selectedScope) . '">';
    echo '<input type="hidden" name="single_gallery_id" value="' . (int) $selectedSingleGalleryId . '">';
    echo '<input type="hidden" name="renamer_pattern" value="' . e($pattern) . '">';
    foreach ($selectedGalleryIds as $galleryId) {
        echo '<input type="hidden" name="gallery_ids[]" value="' . (int) $galleryId . '">';
    }
    echo '<label class="checkbox-label"><input type="checkbox" name="confirm_media_rename" value="1"' . ($renameCount > 0 ? '' : ' disabled') . '> ' . e(t('admin.media_renamer.reviewed_checkbox', 'I reviewed the preview and want to rename files on disk.')) . '</label>';
    echo '<button type="submit" class="secondary danger"' . ($renameCount > 0 ? '' : ' disabled') . '>' . e(t('admin.media_renamer.apply_site_button', 'Apply planned renames')) . '</button>';
    if ($renameCount <= 0) {
        echo '<span class="muted">' . e(t('admin.media_renamer.nothing_to_rename', 'No files currently need renaming.')) . '</span>';
    }
    echo '</form>';
}

/**
 * Render dry-run plan tables grouped by gallery.
 *
 * @param array<int,array<string,mixed>> $plans
 */
function render_admin_media_renamer_plan_table(array $plans): void
{
    $aggregate = admin_media_renamer_aggregate_plans($plans);
    echo '<div class="admin-metric-grid admin-media-renamer-summary">';
    echo '<div class="admin-metric-card"><span>' . e(t('admin.media_renamer.metric_total', 'Files')) . '</span><strong>' . (int) ($aggregate['total'] ?? 0) . '</strong><small>' . e(t('admin.media_renamer.metric_total_help', 'Direct images in the selected galleries.')) . '</small></div>';
    echo '<div class="admin-metric-card"><span>' . e(t('admin.media_renamer.metric_rename', 'Will rename')) . '</span><strong>' . (int) ($aggregate['rename'] ?? 0) . '</strong><small>' . e(t('admin.media_renamer.metric_rename_help', 'Physical files and database rows to update.')) . '</small></div>';
    echo '<div class="admin-metric-card"><span>' . e(t('admin.media_renamer.metric_ok', 'Already ok')) . '</span><strong>' . (int) ($aggregate['already_matches'] ?? 0) . '</strong><small>' . e(t('admin.media_renamer.metric_ok_help', 'Files already match the generated name.')) . '</small></div>';
    echo '<div class="admin-metric-card"><span>' . e(t('admin.media_renamer.metric_warnings', 'Warnings')) . '</span><strong>' . (int) (($aggregate['warnings'] ?? 0) + ($aggregate['missing'] ?? 0) + ($aggregate['collision'] ?? 0) + ($aggregate['skipped'] ?? 0)) . '</strong><small>' . e(t('admin.media_renamer.metric_warnings_help', 'Missing files, collisions, suffix adjustments, or skipped rows.')) . '</small></div>';
    echo '</div>';

    foreach ($plans as $plan) {
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
            $warnings = (array) ($item['warnings'] ?? []);
            echo '<tr class="admin-media-renamer-row is-' . e((string) ($item['status'] ?? 'skipped')) . '">';
            echo '<td><code>' . e((string) ($item['old_relative_path'] ?? '')) . '</code></td>';
            echo '<td><code>' . e((string) (($item['new_relative_path'] ?? '') !== '' ? $item['new_relative_path'] : '')) . '</code></td>';
            echo '<td>' . e(admin_media_renamer_status_label((string) ($item['status'] ?? 'skipped'))) . '</td>';
            echo '<td>' . ($warnings ? e(implode(' ', array_map('strval', $warnings))) : '<span class="muted">' . e(t('admin.media_renamer.no_notes', 'No notes.')) . '</span>') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></section>';
    }
}

/**
 * Render the execution detail table after an apply run.
 *
 * @param array<int,array<string,mixed>> $details
 */
function render_admin_media_renamer_execution_details(array $details): void
{
    if (!$details) {
        return;
    }
    echo '<section class="admin-edit-card is-wide admin-media-renamer-process-card">';
    echo '<h3>' . e(t('admin.media_renamer.process_title', 'Last run details')) . '</h3>';
    echo '<p class="muted">' . e(t('admin.media_renamer.process_help', 'This shows exactly which files were renamed, skipped, already matched, or failed safety checks.')) . '</p>';
    echo '<div class="admin-log-table-wrap"><table class="admin-log-table admin-media-renamer-process-table"><thead><tr><th>' . e(t('admin.media_renamer.gallery', 'Gallery')) . '</th><th>' . e(t('admin.media_renamer.old_name', 'Old filename')) . '</th><th>' . e(t('admin.media_renamer.new_name', 'Suggested filename')) . '</th><th>' . e(t('admin.media_renamer.status', 'Status')) . '</th><th>' . e(t('admin.media_renamer.notes', 'Notes')) . '</th></tr></thead><tbody>';
    foreach ($details as $detail) {
        $notes = (array) ($detail['notes'] ?? []);
        echo '<tr class="admin-media-renamer-row is-' . e((string) ($detail['status'] ?? 'skipped')) . '">';
        echo '<td>' . e((string) ($detail['gallery'] ?? '')) . '</td>';
        echo '<td><code>' . e((string) ($detail['old'] ?? '')) . '</code></td>';
        echo '<td><code>' . e((string) ($detail['new'] ?? '')) . '</code></td>';
        echo '<td>' . e(admin_media_renamer_status_label((string) ($detail['status'] ?? 'skipped'))) . '</td>';
        echo '<td>' . e(implode(' ', array_map('strval', $notes))) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div></section>';
}

/**
 * Aggregate summary counters across rendered plans.
 *
 * @param array<int,array<string,mixed>> $plans
 * @return array<string,int>
 */
function admin_media_renamer_aggregate_plans(array $plans): array
{
    $aggregate = [
        'total' => 0,
        'rename' => 0,
        'already_matches' => 0,
        'missing' => 0,
        'collision' => 0,
        'skipped' => 0,
        'warnings' => 0,
    ];

    foreach ($plans as $plan) {
        $summary = (array) ($plan['summary'] ?? []);
        foreach ($aggregate as $key => $value) {
            $aggregate[$key] += (int) ($summary[$key] ?? 0);
        }
    }

    return $aggregate;
}

/**
 * Return a concise UI status label for one plan row.
 */
function admin_media_renamer_status_label(string $status): string
{
    return match ($status) {
        'renamed' => t('admin.media_renamer.status_renamed', 'Renamed'),
        'rename' => t('admin.media_renamer.status_rename', 'Will rename'),
        'already_matches' => t('admin.media_renamer.status_already_matches', 'Already matches'),
        'missing' => t('admin.media_renamer.status_missing', 'Missing file'),
        'collision' => t('admin.media_renamer.status_collision', 'Collision'),
        default => t('admin.media_renamer.status_skipped', 'Skipped'),
    };
}

/**
 * Read the selected site-wide renamer scope from POST data.
 */
function admin_media_renamer_scope_from_post(): string
{
    $scope = (string) ($_POST['renamer_scope'] ?? 'selected');
    return in_array($scope, ['all', 'single', 'selected'], true) ? $scope : 'selected';
}

/**
 * Resolve gallery ids from the submitted site-wide form.
 *
 * @return array<int>
 */
function admin_media_renamer_gallery_ids_from_post(string $scope): array
{
    if ($scope === 'all') {
        return media_renamer_all_gallery_ids();
    }
    if ($scope === 'single') {
        return media_renamer_existing_gallery_ids([(int) ($_POST['single_gallery_id'] ?? 0)]);
    }
    return media_renamer_existing_gallery_ids((array) ($_POST['gallery_ids'] ?? []));
}


/**
 * Verify CSRF for media-renamer AJAX routes and emit JSON/logs on failure.
 */
function admin_media_renamer_verify_csrf_for_ajax(): bool
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    if ($token !== '' && hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        return true;
    }

    if (admin_wants_json()) {
        admin_media_renamer_log_event('warning', 'media_renamer.csrf_failed', 'Media renamer AJAX request failed CSRF validation.', [
            'action' => (string) ($_POST['renamer_action'] ?? $_POST['action'] ?? ''),
            'request' => admin_media_renamer_request_log_context(),
        ], ['category' => 'security', 'severity' => 'warning']);
        admin_media_renamer_json_response([
            'ok' => false,
            'error' => t('admin.media_renamer.csrf_failed', 'Security token expired or invalid. Reload the admin page and try again.'),
        ], 400);
        return false;
    }

    verify_csrf();
    return true;
}

/**
 * Handle a browser-side JSON parsing or non-JSON response diagnostic report.
 */
function admin_media_renamer_handle_client_error(): void
{
    $context = [
        'message' => substr((string) ($_POST['message'] ?? ''), 0, 500),
        'status' => (int) ($_POST['status'] ?? 0),
        'status_text' => substr((string) ($_POST['status_text'] ?? ''), 0, 120),
        'content_type' => substr((string) ($_POST['content_type'] ?? ''), 0, 160),
        'response_url' => substr((string) ($_POST['response_url'] ?? ''), 0, 500),
        'request_url' => substr((string) ($_POST['request_url'] ?? ''), 0, 500),
        'redirected' => !empty($_POST['redirected']),
        'snippet' => substr((string) ($_POST['snippet'] ?? ''), 0, 1500),
        'workspace' => substr((string) ($_POST['workspace'] ?? ''), 0, 40),
        'current_url' => substr((string) ($_POST['current_url'] ?? ''), 0, 500),
        'form_action' => substr((string) ($_POST['form_action'] ?? ''), 0, 500),
        'request' => admin_media_renamer_request_log_context(),
    ];
    admin_media_renamer_log_event('error', 'media_renamer.ajax_non_json_response', 'Media renamer AJAX request returned a non-JSON or invalid JSON response.', $context, [
        'category' => 'media',
        'severity' => 'error',
    ]);
    admin_media_renamer_json_response(['ok' => true]);
}

/**
 * Emit a JSON response and stop the current media-renamer request.
 */
function admin_media_renamer_json_response(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Log a media-renamer exception with bounded context.
 */
function admin_media_renamer_log_exception(string $eventKey, string $message, Throwable $exception, array $context = []): void
{
    $context['exception'] = [
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => admin_media_renamer_compact_trace($exception),
    ];
    $context['request'] = admin_media_renamer_request_log_context();
    admin_media_renamer_log_event('error', $eventKey, $message, $context, ['category' => 'media', 'severity' => 'error']);
}

/**
 * Write to Admin Logs when available and always mirror diagnostics to the PHP error log.
 */
function admin_media_renamer_log_event(string $level, string $eventKey, string $message, array $context = [], array $options = []): void
{
    $written = admin_media_renamer_write_admin_log_direct($level, $eventKey, $message, $context, $options);
    if (!$written && function_exists('admin_log_event')) {
        admin_log_event($level, $eventKey, $message, $context, $options);
    }
    $encodedContext = $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    error_log('[PHP Gallery] ' . $eventKey . ': ' . $message . ($encodedContext !== '' ? ' ' . $encodedContext : ''));
}

/**
 * Insert a media-renamer diagnostic directly into the admin_logs table.
 *
 * This is intentionally independent from admin_log_event() because this feature
 * is used to diagnose routing and AJAX failures. A swallowed exception inside
 * the generic log service would otherwise hide the exact failure we need to see.
 */
function admin_media_renamer_write_admin_log_direct(string $level, string $eventKey, string $message, array $context = [], array $options = []): bool
{
    try {
        $tableExists = db()->query("SHOW TABLES LIKE 'admin_logs'");
        if (!$tableExists || !$tableExists->fetch()) {
            return false;
        }

        $columnsAvailable = [];
        $columnsStmt = db()->query('SHOW COLUMNS FROM admin_logs');
        foreach ($columnsStmt->fetchAll() as $column) {
            $columnsAvailable[(string) ($column['Field'] ?? '')] = true;
        }

        $safeLevel = in_array($level, ['info', 'warning', 'error'], true) ? $level : 'error';
        $severity = (string) ($options['severity'] ?? $safeLevel);
        if (!in_array($severity, ['debug', 'info', 'notice', 'warning', 'error', 'critical'], true)) {
            $severity = $safeLevel;
        }
        if (in_array($severity, ['error', 'critical'], true)) {
            $safeLevel = 'error';
        } elseif ($severity === 'warning') {
            $safeLevel = 'warning';
        }
        $category = (string) ($options['category'] ?? 'media');
        if (!in_array($category, ['system', 'gallery', 'media', 'upload', 'thumbnail', 'update', 'security', 'database', 'telemetry', 'admin', 'other'], true)) {
            $category = 'media';
        }

        $user = function_exists('current_user') ? current_user() : null;
        $insertColumns = [];
        $placeholders = [];
        $params = [];
        $add = static function (string $column, mixed $value) use (&$insertColumns, &$placeholders, &$params, $columnsAvailable): void {
            if (!isset($columnsAvailable[$column])) {
                return;
            }
            $insertColumns[] = $column;
            $placeholders[] = '?';
            $params[] = $value;
        };

        $add('user_id', $user ? (int) $user['id'] : null);
        $add('level', $safeLevel);
        $add('category', $category);
        $add('severity', $severity);
        $add('event_key', substr($eventKey, 0, 160));
        $add('message', substr($message, 0, 1000));
        $add('subject_type', (string) ($options['subject_type'] ?? 'media_renamer'));
        if (isset($options['subject_id'])) {
            $add('subject_id', (int) $options['subject_id']);
        }
        $add('request_id', function_exists('telemetry_request_id') ? telemetry_request_id() : null);
        $add('route_name', substr((string) ($options['route_name'] ?? admin_log_current_route_name()), 0, 80));
        $add('fingerprint', hash('sha256', $eventKey . '|' . $message . '|' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        $add('http_method', substr((string) ($_SERVER['REQUEST_METHOD'] ?? ''), 0, 12));
        $add('is_ajax', admin_wants_json() ? 1 : 0);
        $add('context_json', $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null);
        $add('created_at', function_exists('now_sql') ? now_sql() : date('Y-m-d H:i:s'));

        if (!$insertColumns) {
            return false;
        }
        $stmt = db()->prepare('INSERT INTO admin_logs (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $placeholders) . ')');
        return $stmt->execute($params);
    } catch (Throwable $exception) {
        error_log('[PHP Gallery] media_renamer.direct_admin_log_failed: ' . $exception->getMessage());
        return false;
    }
}

/**
 * Return a bounded exception trace suitable for the admin log context JSON.
 */
function admin_media_renamer_compact_trace(Throwable $exception): array
{
    $frames = [];
    foreach (array_slice($exception->getTrace(), 0, 8) as $frame) {
        $frames[] = [
            'file' => (string) ($frame['file'] ?? ''),
            'line' => (int) ($frame['line'] ?? 0),
            'function' => (string) ($frame['function'] ?? ''),
        ];
    }
    return $frames;
}

/**
 * Return request metadata useful when diagnosing AJAX JSON failures.
 */
function admin_media_renamer_request_log_context(): array
{
    return [
        'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
        'uri' => substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 500),
        'accept' => substr((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 0, 220),
        'requested_with' => substr((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 0, 120),
        'referer' => substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 500),
    ];
}

/**
 * Remove bulky per-image detail rows before writing aggregate results to logs.
 */
function admin_media_renamer_loggable_result(array $result): array
{
    unset($result['details']);
    if (isset($result['failures']) && is_array($result['failures'])) {
        $result['failures'] = array_slice(array_map('strval', $result['failures']), 0, 20);
    }
    return $result;
}

/**
 * Build a visible result notice after a physical rename run.
 */
function admin_media_renamer_result_notice(array $result): string
{
    $message = t('admin.media_renamer.result_notice', 'Processed {galleries} gallery/galleries. Renamed {renamed} file(s), moved {derivatives} generated derivative(s), skipped {skipped} row(s), saw {missing} missing file(s), updated {titles} derived title(s), and removed {archives} stale ZIP archive row(s).', [
        'galleries' => (string) (int) ($result['galleries_processed'] ?? 0),
        'renamed' => (string) (int) ($result['renamed'] ?? 0),
        'derivatives' => (string) (int) ($result['derivatives_moved'] ?? 0),
        'skipped' => (string) ((int) ($result['skipped'] ?? 0) + (int) ($result['collisions'] ?? 0)),
        'missing' => (string) (int) ($result['missing'] ?? 0),
        'archives' => (string) (int) ($result['zip_archives_deleted'] ?? 0),
        'titles' => (string) (int) ($result['titles_updated'] ?? 0),
    ]);

    $failures = (array) ($result['failures'] ?? []);
    if ($failures) {
        $message .= ' ' . t('admin.media_renamer.result_failures', 'Failures: {failures}', ['failures' => implode(' | ', array_map('strval', $failures))]);
    }
    return $message;
}
