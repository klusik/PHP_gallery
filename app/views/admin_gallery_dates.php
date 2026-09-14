<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_gallery_dates.php
 * Module Type: View
 *
 * Purpose:
 *   Renders Admin gallery date maintenance and EXIF suggestion controls.
 *
 * Responsibilities:
 *   - Render gallery date maintenance page structure from prepared data
 *   - Render editable EXIF suggestion rows without domain lookups
 *   - Keep schema checks, persistence, request handling, and URL decisions outside the view
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
 *   - This view consumes presentation-ready labels, URLs, and row values only.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\csrf_field;
use function Gallery\Core\e;

/**
 * Render one editable EXIF date suggestion row.
 *
 * @param array<string, mixed> $viewModel Prepared row presentation model.
 */
function view_render_admin_gallery_dates_row(array $viewModel): void
{
    $galleryId = (int) ($viewModel['gallery_id'] ?? 0);
    echo '<tr' . (!empty($viewModel['muted']) ? ' class="is-muted-row"' : '') . '>';
    echo '<td><label class="admin-checkbox-row"><input type="checkbox" name="apply_gallery_ids[]" value="' . $galleryId . '"' . (!empty($viewModel['checked']) ? ' checked' : '') . '> <span>' . e((string) ($viewModel['apply_label'] ?? '')) . '</span></label></td>';
    echo '<td><strong><a href="' . e((string) ($viewModel['edit_url'] ?? '')) . '">' . e((string) ($viewModel['title'] ?? '')) . '</a></strong><small>' . e((string) ($viewModel['folder_path'] ?? '')) . '</small></td>';
    if ((string) ($viewModel['current_label'] ?? '') !== '') {
        echo '<td>' . e((string) $viewModel['current_label']) . '</td>';
    } else {
        echo '<td><span class="muted">' . e((string) ($viewModel['no_current_label'] ?? '')) . '</span></td>';
    }
    echo '<td><strong>' . e((string) ($viewModel['suggested_label'] ?? '')) . '</strong><small>' . e((string) ($viewModel['source_counts_label'] ?? '')) . '</small></td>';
    echo '<td><div class="admin-date-range-inputs admin-date-suggestion-inputs">';
    echo '<label><span>' . e((string) ($viewModel['from_label'] ?? '')) . '</span><input type="date" name="gallery_date[' . $galleryId . ']" value="' . e((string) ($viewModel['suggested_start'] ?? '')) . '"></label>';
    echo '<label><span>' . e((string) ($viewModel['to_label'] ?? '')) . '</span><input type="date" name="gallery_date_end[' . $galleryId . ']" value="' . e((string) ($viewModel['suggested_end'] ?? '')) . '"></label>';
    echo '</div></td>';
    echo '</tr>';
}

/**
 * Render the Admin gallery date maintenance page body.
 *
 * @param array<string, mixed> $viewModel Prepared page presentation model.
 */
function view_render_admin_gallery_dates_page(array $viewModel): void
{
    if ((string) ($viewModel['notice'] ?? '') !== '') {
        echo '<div class="notice">' . e((string) $viewModel['notice']) . '</div>';
    }

    echo '<section class="hero admin-dashboard-hero"><div><p class="admin-kicker">' . e((string) ($viewModel['kicker'] ?? '')) . '</p><h1>' . e((string) ($viewModel['title'] ?? '')) . '</h1><p class="muted">' . e((string) ($viewModel['description'] ?? '')) . '</p></div><div class="admin-hero-actions">';
    foreach ((array) ($viewModel['hero_actions'] ?? []) as $action) {
        echo '<a class="button secondary" href="' . e((string) ($action['url'] ?? '')) . '">' . e((string) ($action['label'] ?? '')) . '</a>';
    }
    echo '</div></section>';

    if (empty($viewModel['exif_schema_ready'])) {
        echo '<section class="panel"><p class="muted">' . e((string) ($viewModel['exif_unavailable_label'] ?? '')) . '</p></section>';
        return;
    }

    $rows = (array) ($viewModel['rows'] ?? []);
    if ($rows === []) {
        echo '<section class="panel"><h2>' . e((string) ($viewModel['empty_title'] ?? '')) . '</h2><p class="muted">' . e((string) ($viewModel['empty_hint'] ?? '')) . '</p></section>';
        return;
    }

    echo '<section class="panel admin-gallery-date-suggestions"><form method="post" action="' . e((string) ($viewModel['form_action'] ?? '')) . '">' . csrf_field();
    if ((int) ($viewModel['scope_gallery_id'] ?? 0) > 0) {
        echo '<input type="hidden" name="scope_gallery_id" value="' . (int) $viewModel['scope_gallery_id'] . '">';
    }
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e((string) ($viewModel['suggestions_kicker'] ?? '')) . '</p><h2>' . e((string) ($viewModel['suggestions_title'] ?? '')) . '</h2></div><p class="muted">' . e((string) ($viewModel['suggestions_help'] ?? '')) . '</p></div>';
    echo '<div class="admin-table-scroll"><table class="admin-table"><thead><tr>';
    foreach ((array) ($viewModel['column_labels'] ?? []) as $label) {
        echo '<th>' . e((string) $label) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        view_render_admin_gallery_dates_row((array) $row);
    }
    echo '</tbody></table></div>';
    echo '<div class="admin-form-actions"><button type="submit">' . e((string) ($viewModel['apply_selected_label'] ?? '')) . '</button><a class="button secondary" href="' . e((string) ($viewModel['cancel_url'] ?? '')) . '">' . e((string) ($viewModel['cancel_label'] ?? '')) . '</a></div>';
    echo '</form></section>';
}
