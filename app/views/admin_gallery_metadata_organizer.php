<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_gallery_metadata_organizer.php
 * Module Type: View
 *
 * Purpose:
 *   Renders the EXIF-date metadata organizer from controller-prepared state.
 *
 * Responsibilities:
 *   - Render organizer configuration, progress, preview, and confirmation controls
 *   - Keep organizer page markup independent from request globals and persistence
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Plan construction, validation, URLs, and CSRF issuance remain in the controller.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Services\t;

/** @param array<string,mixed> $viewModel Controller-prepared organizer state. */
function view_render_admin_gallery_metadata_organizer_panel(array $viewModel): void
{
    view_render_admin_tab_intro([
        'kicker' => t('admin.metadata_organizer.kicker', 'Metadata organizer'),
        'title' => t('admin.metadata_organizer.title', 'Create subgalleries from EXIF dates'),
        'description' => t('admin.metadata_organizer.description', 'Builds a draft from capture dates already stored in the database. The preview does not scan files or move anything. Applying the draft creates or reuses child galleries and then physically moves the originals and generated derivatives.'),
    ]);

    if (empty($viewModel['schema_ready'])) {
        echo '<section class="panel"><p class="muted">' . e(t('admin.metadata_organizer.schema_unavailable', 'Metadata organizer requires scanned EXIF capture-date data in the image database.')) . '</p></section>';
        return;
    }

    $galleryId = (int) ($viewModel['gallery_id'] ?? 0);
    $options = (array) ($viewModel['options'] ?? []);
    $plan = is_array($viewModel['plan'] ?? null) ? $viewModel['plan'] : null;
    $previewRequested = !empty($viewModel['preview_requested']);
    $errorMessage = (string) ($viewModel['error_message'] ?? '');

    if ($errorMessage !== '') {
        echo '<div class="notice">' . e($errorMessage) . '</div>';
    }

    echo '<div class="admin-metadata-organizer" data-admin-metadata-organizer data-admin-metadata-organizer-gallery-id="' . $galleryId . '"><section class="panel">';
    echo '<form method="get" action="' . e((string) ($viewModel['preview_action_url'] ?? '')) . '" class="admin-edit-card-grid" data-admin-metadata-organizer-preview-form data-admin-metadata-organizer-batch-size="200" data-admin-metadata-organizer-preview-url="' . e((string) ($viewModel['preview_batch_url'] ?? '')) . '" data-admin-metadata-organizer-apply-url="' . e((string) ($viewModel['apply_batch_url'] ?? '')) . '">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<input type="hidden" name="page" value="admin_edit_gallery"><input type="hidden" name="id" value="' . $galleryId . '"><input type="hidden" name="tab" value="admin-edit-organizer"><input type="hidden" name="metadata_organizer_preview" value="1">';
    echo '<div class="admin-edit-card"><label>' . e(t('admin.metadata_organizer.primary_grouping', 'Primary grouping')) . '<select name="primary_grouping"><option value="date" selected>' . e(t('admin.metadata_organizer.group_by_date', 'Date')) . '</option></select><span class="muted">' . e(t('admin.metadata_organizer.primary_help', 'Phase 1 groups by EXIF capture date. GPS/place grouping will use the same draft model later.')) . '</span></label></div>';
    echo '<div class="admin-edit-card"><label>' . e(t('admin.metadata_organizer.secondary_grouping', 'Secondary grouping')) . '<select name="secondary_grouping"><option value="none" selected>' . e(t('admin.metadata_organizer.secondary_none', 'None')) . '</option><option value="location" disabled>' . e(t('admin.metadata_organizer.secondary_location_future', 'Location, planned')) . '</option></select><span class="muted">' . e(t('admin.metadata_organizer.secondary_help', 'Prepared for future date plus location or location plus date grouping.')) . '</span></label></div>';
    echo '<div class="admin-edit-card"><label>' . e(t('admin.metadata_organizer.min_date', 'Minimum EXIF date')) . '<input type="date" name="min_date" value="' . e((string) ($options['min_date'] ?? $viewModel['default_min_date'] ?? '')) . '"><span class="muted">' . e(t('admin.metadata_organizer.min_date_help', 'Photos before this date are ignored, useful for unset camera clocks.')) . '</span></label></div>';
    echo '<div class="admin-edit-card"><label>' . e(t('admin.metadata_organizer.max_date', 'Maximum EXIF date')) . '<input type="date" name="max_date" value="' . e((string) ($options['max_date'] ?? $viewModel['default_max_date'] ?? '')) . '"><span class="muted">' . e(t('admin.metadata_organizer.max_date_help', 'Photos after this date are ignored.')) . '</span></label></div>';
    echo '<div class="admin-edit-card is-wide"><button type="submit" class="secondary" data-admin-metadata-organizer-preview-button>' . e(t('admin.metadata_organizer.preview_button', 'Preview draft')) . '</button></div></form>';
    echo '<div class="thumbnail-progress admin-metadata-organizer-progress" data-admin-metadata-organizer-progress hidden><progress class="thumbnail-progress-bar" value="0" max="100" data-admin-metadata-organizer-progress-bar></progress><p class="muted" data-admin-metadata-organizer-progress-text></p></div><pre class="admin-metadata-organizer-log" data-admin-metadata-organizer-log hidden></pre></section>';

    if (!$previewRequested || !$plan) {
        echo '<section class="panel" data-admin-metadata-organizer-results><p class="muted">' . e(t('admin.metadata_organizer.preview_prompt', 'Choose the date boundaries and preview the draft before applying any move.')) . '</p></section></div>';
        return;
    }

    $groups = (array) ($plan['groups'] ?? []);
    echo '<section class="panel" data-admin-metadata-organizer-results><h3>' . e(t('admin.metadata_organizer.preview_title', 'Draft structure')) . '</h3>';
    echo '<p class="muted">' . e(t('admin.metadata_organizer.preview_summary', 'Direct photos in this gallery: {total}. Candidate photos: {candidates}. Proposed subgalleries: {groups}. Ignored without EXIF date: {without}. Ignored before minimum: {before}. Ignored after maximum: {after}.', [
        'total' => (string) (int) ($plan['total_images'] ?? 0),
        'candidates' => (string) (int) ($plan['candidate_images'] ?? 0),
        'groups' => (string) count($groups),
        'without' => (string) (int) ($plan['ignored_without_date'] ?? 0),
        'before' => (string) (int) ($plan['ignored_before_min'] ?? 0),
        'after' => (string) (int) ($plan['ignored_after_max'] ?? 0),
    ])) . '</p>';

    if (!$groups) {
        echo '<p class="muted">' . e(t('admin.metadata_organizer.empty_preview', 'No photos match the current date boundaries.')) . '</p></section></div>';
        return;
    }

    echo '<table><thead><tr><th>' . e(t('admin.metadata_organizer.target_gallery', 'Target subgallery')) . '</th><th>' . e(t('admin.metadata_organizer.status', 'Status')) . '</th><th>' . e(t('admin.metadata_organizer.photos', 'Photos')) . '</th><th>' . e(t('admin.metadata_organizer.sample', 'Sample')) . '</th></tr></thead><tbody>';
    foreach ($groups as $group) {
        $status = (string) ($group['destination_status'] ?? '') === 'existing' ? t('admin.metadata_organizer.status_existing', 'Existing gallery, photos will be added') : t('admin.metadata_organizer.status_new', 'New gallery will be created');
        echo '<tr><td><strong>' . e((string) ($group['title'] ?? '')) . '</strong><br><span class="muted">' . e((string) ($group['date'] ?? '')) . '</span></td><td>' . e($status) . '</td><td>' . (int) ($group['image_count'] ?? 0) . '</td><td>' . e((string) ($group['sample'] ?? '')) . '</td></tr>';
    }
    echo '</tbody></table>';

    echo '<form method="post" action="' . e((string) ($viewModel['apply_action_url'] ?? '')) . '" data-admin-metadata-organizer-apply-form data-admin-metadata-organizer-batch-size="1">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<input type="hidden" name="id" value="' . $galleryId . '"><input type="hidden" name="return_tab" value="admin-edit-organizer"><input type="hidden" name="action" value="apply_metadata_organizer_date_plan"><input type="hidden" name="primary_grouping" value="date"><input type="hidden" name="secondary_grouping" value="none">';
    echo '<input type="hidden" name="min_date" value="' . e((string) ($options['min_date'] ?? $viewModel['default_min_date'] ?? '')) . '"><input type="hidden" name="max_date" value="' . e((string) ($options['max_date'] ?? $viewModel['default_max_date'] ?? '')) . '">';
    echo '<label class="checkbox-label"><input type="checkbox" name="confirm_metadata_organizer" value="1" required> ' . e(t('admin.metadata_organizer.confirm_label', 'I reviewed the draft and want to create/reuse these subgalleries and move the matching photos now.')) . '</label>';
    echo '<div class="admin-edit-gallery-savebar"><button type="submit" data-admin-metadata-organizer-apply-button>' . e(t('admin.metadata_organizer.apply_button', 'Apply draft and move photos')) . '</button><span class="muted">' . e(t('admin.metadata_organizer.apply_help', 'The operation uses the same physical move path as the existing bulk image move tool.')) . '</span></div></form></section></div>';
}
