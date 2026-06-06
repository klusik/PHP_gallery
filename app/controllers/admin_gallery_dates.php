<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_gallery_dates.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles gallery date-range maintenance and EXIF-derived suggestions.
 *
 * Responsibilities:
 *   - Render editable gallery date suggestions built from scanned EXIF capture dates
 *   - Persist admin-approved date ranges to gallery rows and sidecar metadata
 *   - Keep the workflow safe for partially migrated installations
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
 *   2026-06-07
 */

declare(strict_types=1);

/**
 * Send a JSON response for the reusable gallery EXIF date suggestion workflow.
 *
 * @param array<string, mixed> $payload Additional response values for the browser.
 */
function admin_gallery_date_suggestion_json_response(bool $ok, string $message, array $payload = []): void
{
    header('Content-Type: application/json');
    echo json_encode(array_merge([
        'ok' => $ok,
        'message' => $message,
    ], $payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Render the refreshed per-gallery EXIF date suggestion panel for AJAX responses.
 */
function admin_gallery_date_suggestion_panel_html(array $gallery): string
{
    if (!function_exists('view_render_admin_gallery_date_exif_suggestion')) {
        return '';
    }

    ob_start();
    view_render_admin_gallery_date_exif_suggestion($gallery);
    return (string) ob_get_clean();
}

/**
 * Apply the current gallery branch EXIF date suggestion and answer as JSON or redirect.
 */
function admin_gallery_date_suggestion_handle_apply(int $galleryId, string $returnUrl): void
{
    // $wantsJson stores whether JavaScript owns the in-place editor refresh.
    $wantsJson = admin_wants_json();
    // $gallery stores the existing row for logging and redirects.
    $gallery = find_gallery($galleryId, true);
    if (!$gallery) {
        $message = t('admin.gallery_dates.error_gallery_missing', 'Gallery #{id} no longer exists.', ['id' => (string) $galleryId]);
        if ($wantsJson) {
            admin_gallery_date_suggestion_json_response(false, $message, ['error' => $message]);
            return;
        }
        flash_message('admin_notice', $message);
        redirect_to($returnUrl);
    }

    try {
        // $applyResult stores the persisted range and refreshed gallery row.
        $applyResult = gallery_date_apply_exif_suggestion_to_gallery($galleryId);
        // $updatedGallery stores the persisted row used to refresh the suggestion panel.
        $updatedGallery = is_array($applyResult['gallery'] ?? null) ? $applyResult['gallery'] : $gallery;
        // $suggestion stores the exact EXIF aggregate that was approved.
        $suggestion = is_array($applyResult['suggestion'] ?? null) ? $applyResult['suggestion'] : [];
        // $rangeLabel stores the persisted range for the admin notification.
        $rangeLabel = (string) ($applyResult['range_label'] ?? '');
        admin_log_event('info', 'gallery_dates.suggestion_applied_to_gallery', 'Admin applied an EXIF-derived date suggestion to one gallery.', [
            'gallery_id' => $galleryId,
            'gallery_path' => (string) ($gallery['folder_path'] ?? ''),
            'suggested_start' => (string) ($suggestion['suggested_start'] ?? ''),
            'suggested_end' => (string) ($suggestion['suggested_end'] ?? ''),
            'exif_image_count' => (int) ($suggestion['exif_image_count'] ?? 0),
        ], ['category' => 'gallery']);
        $message = t('admin.gallery_editor.exif_date_applied_notice', 'Applied EXIF date range {range} to this gallery.', ['range' => $rangeLabel]);
        if ($wantsJson) {
            admin_gallery_date_suggestion_json_response(true, $message, [
                'gallery_date' => (string) ($applyResult['start'] ?? ''),
                'gallery_date_end' => (string) ($applyResult['end'] ?? ''),
                'range_label' => $rangeLabel,
                'suggestion_html' => admin_gallery_date_suggestion_panel_html($updatedGallery),
            ]);
            return;
        }
        flash_message('admin_notice', $message);
    } catch (Throwable $exception) {
        admin_log_event('error', 'gallery_dates.suggestion_apply_failed', 'Admin failed to apply an EXIF-derived date suggestion to one gallery.', [
            'gallery_id' => $galleryId,
            'error' => $exception->getMessage(),
        ], ['category' => 'gallery']);
        if ($wantsJson) {
            admin_gallery_date_suggestion_json_response(false, $exception->getMessage(), ['error' => $exception->getMessage()]);
            return;
        }
        flash_message('admin_notice', $exception->getMessage());
    }

    redirect_to($returnUrl);
}

/**
 * Handles the focused per-gallery EXIF date suggestion endpoint.
 */
function cms_admin_gallery_date_suggestion(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        redirect_to(url_for('admin'));
    }

    verify_csrf();
    // $galleryId stores the target gallery supplied by the editor component.
    $galleryId = (int) ($_POST['gallery_id'] ?? $_POST['id'] ?? 0);
    if ($galleryId <= 0) {
        $message = t('admin.gallery_dates.error_gallery_missing', 'Gallery #{id} no longer exists.', ['id' => '0']);
        if (admin_wants_json()) {
            admin_gallery_date_suggestion_json_response(false, $message, ['error' => $message]);
            return;
        }
        flash_message('admin_notice', $message);
        redirect_to(url_for('admin'));
    }

    admin_gallery_date_suggestion_handle_apply($galleryId, admin_edit_gallery_tab_url($galleryId, 'admin-edit-identity'));
}

/**
 * Apply selected gallery date-range rows submitted by the EXIF suggestion form.
 *
 * @return array{updated:int,errors:array<int,string>}
 */
function admin_gallery_dates_apply_selected(array $post): array
{
    // $selectedIds stores gallery ids explicitly approved by the admin.
    $selectedIds = array_values(array_unique(array_map('intval', (array) ($post['apply_gallery_ids'] ?? []))));
    // $startValues stores submitted start dates keyed by gallery id.
    $startValues = is_array($post['gallery_date'] ?? null) ? $post['gallery_date'] : [];
    // $endValues stores submitted end dates keyed by gallery id.
    $endValues = is_array($post['gallery_date_end'] ?? null) ? $post['gallery_date_end'] : [];
    $updated = 0;
    $errors = [];

    foreach ($selectedIds as $galleryId) {
        if ($galleryId <= 0) {
            continue;
        }
        try {
            gallery_date_save_range($galleryId, $startValues[$galleryId] ?? '', $endValues[$galleryId] ?? '');
        } catch (Throwable $exception) {
            $gallery = find_gallery($galleryId, true);
            $galleryTitle = is_array($gallery) ? (string) ($gallery['title'] ?? ('#' . $galleryId)) : '#' . $galleryId;
            $errors[] = t('admin.gallery_dates.error_gallery_invalid', '{gallery}: {error}', [
                'gallery' => $galleryTitle,
                'error' => $exception->getMessage(),
            ]);
            continue;
        }
        $updated++;
    }

    return ['updated' => $updated, 'errors' => $errors];
}

/**
 * Render one editable EXIF date suggestion row.
 *
 * @param array<string, mixed> $row
 */
function admin_gallery_dates_render_row(array $row): void
{
    $galleryId = (int) ($row['id'] ?? 0);
    $suggestedStart = (string) ($row['suggested_start'] ?? '');
    $suggestedEnd = (string) ($row['suggested_end'] ?? '');
    $currentLabel = gallery_date_range_storage_label($row['current_start'] ?? null, $row['current_end'] ?? null);
    $suggestedLabel = gallery_date_range_storage_label($suggestedStart, $suggestedEnd);
    $matchesCurrent = !empty($row['matches_current']);
    $hasCurrentRange = !empty($row['has_current_range']);
    // $checked stores the safe default: approve empty galleries, leave existing manual dates untouched unless selected.
    $checked = !$matchesCurrent && !$hasCurrentRange;

    echo '<tr' . ($matchesCurrent ? ' class="is-muted-row"' : '') . '>';
    echo '<td><label class="admin-checkbox-row"><input type="checkbox" name="apply_gallery_ids[]" value="' . $galleryId . '"' . ($checked ? ' checked' : '') . '> <span>' . e($matchesCurrent ? t('admin.gallery_dates.status_current', 'current') : t('admin.gallery_dates.apply', 'Apply')) . '</span></label></td>';
    echo '<td><strong><a href="' . e(admin_edit_gallery_tab_url($galleryId, 'admin-edit-identity')) . '">' . e((string) ($row['title'] ?? ('#' . $galleryId))) . '</a></strong><small>' . e((string) ($row['folder_path'] ?? '')) . '</small></td>';
    echo '<td>' . ($currentLabel !== '' ? e($currentLabel) : '<span class="muted">' . e(t('admin.gallery_dates.no_current_date', 'No manual date')) . '</span>') . '</td>';
    echo '<td><strong>' . e($suggestedLabel) . '</strong><small>' . e(t('admin.gallery_dates.suggestion_source_counts', '{images} EXIF photo(s), {galleries} gallery node(s)', [
        'images' => (string) (int) ($row['exif_image_count'] ?? 0),
        'galleries' => (string) (int) ($row['source_gallery_count'] ?? 0),
    ])) . '</small></td>';
    echo '<td><div class="admin-date-range-inputs admin-date-suggestion-inputs">';
    echo '<label><span>' . e(t('admin.gallery_editor.gallery_date_from', 'From')) . '</span><input type="date" name="gallery_date[' . $galleryId . ']" value="' . e($suggestedStart) . '"></label>';
    echo '<label><span>' . e(t('admin.gallery_editor.gallery_date_to', 'To')) . '</span><input type="date" name="gallery_date_end[' . $galleryId . ']" value="' . e($suggestedEnd) . '"></label>';
    echo '</div></td>';
    echo '</tr>';
}

/**
 * Handles the Admin gallery date suggestion page.
 */
function cms_admin_gallery_dates(): void
{
    require_admin();
    if (!gallery_date_range_schema_ready()) {
        flash_message('admin_notice', t('admin.gallery_dates.requires_migration', 'Gallery date maintenance will be available after the database migration is applied.'));
        redirect_to(url_for('admin'));
    }

    // $scopeGalleryId limits the review table to one gallery branch when opened from a gallery editor.
    $scopeGalleryId = max(0, (int) ($_GET['gallery_id'] ?? $_POST['scope_gallery_id'] ?? 0));
    // $scopeGallery stores the optional branch root used by scoped reviews.
    $scopeGallery = $scopeGalleryId > 0 ? find_gallery($scopeGalleryId, true) : null;
    if ($scopeGalleryId > 0 && !$scopeGallery) {
        flash_message('admin_notice', t('admin.gallery_dates.error_gallery_missing', 'Gallery #{id} no longer exists.', ['id' => (string) $scopeGalleryId]));
        redirect_to(url_for('admin_gallery_dates'));
    }

    if (request_method() === 'POST') {
        verify_csrf();
        $result = admin_gallery_dates_apply_selected($_POST);
        admin_log_event('info', 'gallery_dates.suggestions_applied', 'Admin applied EXIF-derived gallery date suggestions.', [
            'updated' => (int) ($result['updated'] ?? 0),
            'errors' => (array) ($result['errors'] ?? []),
        ], ['category' => 'gallery']);

        $notice = t('admin.gallery_dates.notice_applied', 'Updated {count} gallery date range(s).', ['count' => (string) (int) ($result['updated'] ?? 0)]);
        $errors = array_slice(array_map('strval', (array) ($result['errors'] ?? [])), 0, 5);
        if ($errors) {
            $notice .= ' ' . t('admin.gallery_dates.notice_errors', 'Errors: {errors}', ['errors' => implode(' | ', $errors)]);
        }
        flash_message('admin_notice', $notice);
        $redirectParams = $scopeGalleryId > 0 ? ['gallery_id' => $scopeGalleryId] : [];
        redirect_to(url_for('admin_gallery_dates', $redirectParams));
    }

    $rows = gallery_date_exif_suggestion_rows($scopeGalleryId > 0 ? $scopeGalleryId : null);
    render_header(t('admin.gallery_dates.page_title', 'Gallery dates'));
    $notice = (string) flash_message('admin_notice');
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }

    $pageDescription = $scopeGallery
        ? t('admin.gallery_dates.scoped_description', 'Review EXIF-derived date range suggestions for {gallery} and its subgalleries.', ['gallery' => (string) ($scopeGallery['title'] ?? '')])
        : t('admin.gallery_dates.description', 'Review EXIF-derived date range suggestions. Each suggestion uses scanned original photo metadata from the gallery and all of its subgalleries.');
    echo '<section class="hero admin-dashboard-hero"><div><p class="admin-kicker">' . e(t('admin.gallery_dates.kicker', 'Gallery maintenance')) . '</p><h1>' . e($scopeGallery ? t('admin.gallery_dates.scoped_title', 'Gallery dates for {gallery}', ['gallery' => (string) ($scopeGallery['title'] ?? '')]) : t('admin.gallery_dates.title', 'Gallery dates')) . '</h1><p class="muted">' . e($pageDescription) . '</p></div><div class="admin-hero-actions">';
    if ($scopeGallery) {
        echo '<a class="button secondary" href="' . e(admin_edit_gallery_tab_url((int) $scopeGallery['id'], 'admin-edit-identity')) . '">' . e(t('admin.gallery_dates.back_to_gallery', 'Back to gallery')) . '</a>';
        echo '<a class="button secondary" href="' . e(url_for('admin_gallery_dates')) . '">' . e(t('admin.gallery_dates.review_all', 'Review all galleries')) . '</a>';
    }
    echo '<a class="button secondary" href="' . e(url_for('admin')) . '">' . e(t('admin.gallery_dates.back_to_admin', 'Back to Admin')) . '</a></div></section>';

    if (!gallery_date_exif_suggestions_schema_ready()) {
        echo '<section class="panel"><p class="muted">' . e(t('admin.gallery_dates.exif_unavailable', 'EXIF capture-date suggestions require the EXIF/GPS image metadata migration and scanned image rows.')) . '</p></section>';
        render_footer();
        return;
    }

    if (!$rows) {
        echo '<section class="panel"><h2>' . e(t('admin.gallery_dates.no_suggestions_title', 'No EXIF dates found')) . '</h2><p class="muted">' . e(t('admin.gallery_dates.no_suggestions_hint', 'No scanned original photo currently has an EXIF capture date. Run Scan/import images for galleries that were imported before EXIF extraction existed.')) . '</p></section>';
        render_footer();
        return;
    }

    $formParams = $scopeGalleryId > 0 ? ['gallery_id' => $scopeGalleryId] : [];
    echo '<section class="panel admin-gallery-date-suggestions"><form method="post" action="' . e(url_for('admin_gallery_dates', $formParams)) . '">' . csrf_field();
    if ($scopeGalleryId > 0) {
        echo '<input type="hidden" name="scope_gallery_id" value="' . (int) $scopeGalleryId . '">';
    }
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.gallery_dates.suggestions_kicker', 'EXIF suggestions')) . '</p><h2>' . e(t('admin.gallery_dates.suggestions_title', 'Approve, edit, or ignore suggestions')) . '</h2></div><p class="muted">' . e(t('admin.gallery_dates.suggestions_help', 'Checked rows will be saved. Unchecked rows are ignored. Date inputs are editable before applying. Existing manual dates are not checked by default.')) . '</p></div>';
    echo '<div class="admin-table-scroll"><table class="admin-table"><thead><tr>';
    echo '<th>' . e(t('admin.gallery_dates.column_apply', 'Apply')) . '</th>';
    echo '<th>' . e(t('admin.gallery_dates.column_gallery', 'Gallery')) . '</th>';
    echo '<th>' . e(t('admin.gallery_dates.column_current', 'Current')) . '</th>';
    echo '<th>' . e(t('admin.gallery_dates.column_suggested', 'Suggested')) . '</th>';
    echo '<th>' . e(t('admin.gallery_dates.column_edit', 'Edit before saving')) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        admin_gallery_dates_render_row($row);
    }
    echo '</tbody></table></div>';
    $cancelUrl = $scopeGallery ? admin_edit_gallery_tab_url((int) $scopeGallery['id'], 'admin-edit-identity') : url_for('admin');
    echo '<div class="admin-form-actions"><button type="submit">' . e(t('admin.gallery_dates.apply_selected', 'Apply selected date ranges')) . '</button><a class="button secondary" href="' . e($cancelUrl) . '">' . e(t('admin.gallery_dates.cancel', 'Cancel')) . '</a></div>';
    echo '</form></section>';

    render_footer();
}
