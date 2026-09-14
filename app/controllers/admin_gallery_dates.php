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
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use Throwable;
use function Gallery\Core\admin_mutation_descriptor;
use function Gallery\Core\admin_mutation_gallery_is_rendered_in_context;
use function Gallery\Core\admin_mutation_gallery_membership_postcondition;
use function Gallery\Core\admin_mutation_error_envelope;
use function Gallery\Core\admin_mutation_panel_metadata;
use function Gallery\Core\admin_mutation_postcondition;
use function Gallery\Core\admin_mutation_public_gallery_context;
use function Gallery\Core\admin_mutation_success_envelope;
use function Gallery\Core\admin_wants_json;
use function Gallery\Core\current_user;
use function Gallery\Core\flash_message;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\redirect_to;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\find_gallery;
use function Gallery\Services\gallery_date_apply_exif_suggestion_to_gallery;
use function Gallery\Services\gallery_date_exif_suggestion_rows;
use function Gallery\Services\gallery_date_exif_suggestions_schema_ready;
use function Gallery\Services\gallery_date_range_schema_ready;
use function Gallery\Services\gallery_date_range_storage_label;
use function Gallery\Services\gallery_date_save_range;
use function Gallery\Services\t;
use function Gallery\Views\view_render_admin_gallery_date_exif_suggestion;
use function Gallery\Views\view_render_admin_gallery_dates_page;
use function Gallery\Views\view_render_admin_gallery_dates_row;
use function Gallery\Services\admin_log_event;


/**
 * Return the canonical mutation descriptor for one EXIF-derived gallery date update.
 *
 * @param int $galleryId Gallery identifier.
 * @return array<string,mixed> Canonical mutation descriptor.
 */
function admin_gallery_date_suggestion_mutation_descriptor(int $galleryId): array
{
    return admin_mutation_descriptor(
        'gallery.date_range_update',
        'gallery',
        'update',
        $galleryId > 0 ? [$galleryId] : []
    );
}

/**
 * Return whether the current POST contains the active Admin CSRF token.
 *
 * JSON callers use this check before verify_csrf() so an expired token remains
 * a JSON error instead of falling through to the classic plain-text abort.
 *
 * @return bool True when the submitted token is valid.
 */
function admin_gallery_date_suggestion_csrf_valid(): bool
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    return $token !== '' && hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token);
}

/**
 * Build a canonical expected-error payload for the focused date suggestion mutation.
 *
 * @param string $message Human-readable failure message.
 * @param string $errorCode Stable machine-readable error code.
 * @param int $galleryId Gallery identifier when known.
 * @param string $returnUrl Direct-page fallback URL when known.
 * @return array<string,mixed> Canonical error envelope.
 */
function admin_gallery_date_suggestion_error_payload(string $message, string $errorCode, int $galleryId = 0, string $returnUrl = ''): array
{
    return admin_mutation_error_envelope(
        $message,
        $errorCode,
        admin_gallery_date_suggestion_mutation_descriptor($galleryId),
        $returnUrl !== '' ? ['redirect_url' => $returnUrl] : []
    );
}

/**
 * Send a JSON response for the reusable gallery EXIF date suggestion workflow.
 *
 * @param bool $ok Ok value.
 * @param string $message Message value.
 * @param array $payload Payload value.
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
 *
 * @param array $gallery Gallery row or gallery data.
 * @return string Text result for the caller.
 */
function admin_gallery_date_suggestion_panel_html(array $gallery): string
{
    if (!function_exists('Gallery\\Views\\view_render_admin_gallery_date_exif_suggestion')) {
        return '';
    }

    ob_start();
    view_render_admin_gallery_date_exif_suggestion($gallery, admin_gallery_form_view_model('gallery', $gallery));
    return (string) ob_get_clean();
}

/**
 * Apply the current gallery branch EXIF date suggestion and answer as JSON or redirect.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $returnUrl Return url URL.
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
            admin_gallery_date_suggestion_json_response(false, $message, admin_gallery_date_suggestion_error_payload(
                $message,
                'gallery.date_suggestion.gallery_missing',
                $galleryId,
                $returnUrl
            ));
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
            // $updatedAt verifies both the current hero and the owning parent/root card against the persisted row.
            $updatedAt = trim((string) ($updatedGallery['updated_at'] ?? ''));
            // $galleryUrl is the authoritative public render source for the edited gallery.
            $galleryUrl = gallery_public_url($updatedGallery);
            // $parentId and $parentUrl identify the gallery card context whose displayed date range also changed.
            $parentId = (int) ($updatedGallery['parent_id'] ?? 0);
            $parent = $parentId > 0 ? find_gallery($parentId, true) : null;
            $parentUrl = is_array($parent) ? gallery_public_url($parent) : url_for('home');
            // $postcondition requires the server-rendered row timestamp when available; identity/presence remain safe fallbacks.
            $galleryPostcondition = $updatedAt !== ''
                ? admin_mutation_postcondition('gallery_updated_at', ['gallery_id' => $galleryId, 'updated_at' => $updatedAt])
                : admin_mutation_postcondition('gallery_identity', ['gallery_id' => $galleryId]);
            $parentPostcondition = admin_mutation_gallery_is_rendered_in_context($updatedGallery, $parentId)
                ? ($updatedAt !== ''
                    ? admin_mutation_postcondition('gallery_updated_at', ['gallery_id' => $galleryId, 'updated_at' => $updatedAt])
                    : admin_mutation_postcondition('gallery_visibility', [
                        'gallery_id' => $galleryId,
                        'visibility' => (string) ($updatedGallery['visibility'] ?? ''),
                    ]))
                : admin_mutation_gallery_membership_postcondition($galleryId, $parentId, false);
            // $editUrl identifies the owning editor fragment while this tool keeps its own small suggestion markup in sync locally.
            $editUrl = admin_edit_gallery_tab_url($galleryId, 'admin-edit-identity');
            // $envelope moves public invalidation onto the Stage 3 shared completion coordinator.
            $envelope = admin_mutation_success_envelope(
                $message,
                admin_gallery_date_suggestion_mutation_descriptor($galleryId),
                admin_mutation_panel_metadata('gallery-edit', $editUrl, true),
                [
                    admin_mutation_public_gallery_context($galleryId, $galleryUrl, $galleryPostcondition),
                    admin_mutation_public_gallery_context($parentId, $parentUrl, $parentPostcondition),
                ],
                ['redirect_url' => $returnUrl]
            );
            admin_gallery_date_suggestion_json_response(true, $message, array_merge($envelope, [
                // Temporary tool-specific fields remain for the in-place date-input and suggestion-fragment update.
                'gallery_date' => (string) ($applyResult['start'] ?? ''),
                'gallery_date_end' => (string) ($applyResult['end'] ?? ''),
                'range_label' => $rangeLabel,
                'suggestion_html' => admin_gallery_date_suggestion_panel_html($updatedGallery),
            ]));
            return;
        }
        flash_message('admin_notice', $message);
    } catch (Throwable $exception) {
        admin_log_event('error', 'gallery_dates.suggestion_apply_failed', 'Admin failed to apply an EXIF-derived date suggestion to one gallery.', [
            'gallery_id' => $galleryId,
            'error' => $exception->getMessage(),
        ], ['category' => 'gallery']);
        if ($wantsJson) {
            admin_gallery_date_suggestion_json_response(false, $exception->getMessage(), admin_gallery_date_suggestion_error_payload(
                $exception->getMessage(),
                'gallery.date_suggestion.apply_failed',
                $galleryId,
                $returnUrl
            ));
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
    // JSON POSTs validate auth and CSRF before classic redirect/plain-text helpers so
    // the side-panel mutation contract remains JSON-only even after session expiry.
    $isJsonPost = request_method() === 'POST' && admin_wants_json();
    if ($isJsonPost) {
        $galleryId = (int) ($_POST['gallery_id'] ?? $_POST['id'] ?? 0);
        $returnUrl = $galleryId > 0
            ? admin_edit_gallery_tab_url($galleryId, 'admin-edit-identity')
            : url_for('admin');
        $user = current_user();
        if (!$user || (string) ($user['role'] ?? '') !== 'admin') {
            $message = t('auth.admin_required', 'Admin access is required.');
            http_response_code(403);
            admin_gallery_date_suggestion_json_response(false, $message, admin_gallery_date_suggestion_error_payload(
                $message,
                'auth.admin_required',
                $galleryId,
                $returnUrl
            ));
            return;
        }
        if (!admin_gallery_date_suggestion_csrf_valid()) {
            $message = t('security.invalid_csrf', 'Invalid CSRF token.');
            http_response_code(400);
            admin_gallery_date_suggestion_json_response(false, $message, admin_gallery_date_suggestion_error_payload(
                $message,
                'security.invalid_csrf',
                $galleryId,
                $returnUrl
            ));
            return;
        }
        if ($galleryId <= 0) {
            $message = t('admin.gallery_dates.error_gallery_missing', 'Gallery #{id} no longer exists.', ['id' => '0']);
            http_response_code(400);
            admin_gallery_date_suggestion_json_response(false, $message, admin_gallery_date_suggestion_error_payload(
                $message,
                'gallery.date_suggestion.gallery_missing',
                0,
                $returnUrl
            ));
            return;
        }
        admin_gallery_date_suggestion_handle_apply($galleryId, $returnUrl);
        return;
    }

    require_admin();
    if (request_method() !== 'POST') {
        redirect_to(url_for('admin'));
    }

    verify_csrf();
    // $galleryId stores the target gallery supplied by the editor component.
    $galleryId = (int) ($_POST['gallery_id'] ?? $_POST['id'] ?? 0);
    if ($galleryId <= 0) {
        $message = t('admin.gallery_dates.error_gallery_missing', 'Gallery #{id} no longer exists.', ['id' => '0']);
        flash_message('admin_notice', $message);
        redirect_to(url_for('admin'));
    }

    admin_gallery_date_suggestion_handle_apply($galleryId, admin_edit_gallery_tab_url($galleryId, 'admin-edit-identity'));
}

/**
 * Apply selected gallery date-range rows submitted by the EXIF suggestion form.
 *
 * @param array $post Post value.
 * @return array{updated:int,errors:array<int,string>} Structured result data for the caller.
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
 * @param array $row Row data.
 */
function admin_gallery_dates_render_row(array $row): void
{
    view_render_admin_gallery_dates_row(admin_gallery_dates_row_view_model($row));
}

/**
 * Prepare one editable EXIF date suggestion row for the presentation layer.
 *
 * @param array $row Row data.
 * @return array<string, mixed> Prepared row presentation model.
 */
function admin_gallery_dates_row_view_model(array $row): array
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

    return [
        'gallery_id' => $galleryId,
        'suggested_start' => $suggestedStart,
        'suggested_end' => $suggestedEnd,
        'current_label' => $currentLabel,
        'suggested_label' => $suggestedLabel,
        'muted' => $matchesCurrent,
        'checked' => $checked,
        'apply_label' => $matchesCurrent ? t('admin.gallery_dates.status_current', 'current') : t('admin.gallery_dates.apply', 'Apply'),
        'edit_url' => admin_edit_gallery_tab_url($galleryId, 'admin-edit-identity'),
        'title' => (string) ($row['title'] ?? ('#' . $galleryId)),
        'folder_path' => (string) ($row['folder_path'] ?? ''),
        'no_current_label' => t('admin.gallery_dates.no_current_date', 'No manual date'),
        'source_counts_label' => t('admin.gallery_dates.suggestion_source_counts', '{images} EXIF photo(s), {galleries} gallery node(s)', [
            'images' => (string) (int) ($row['exif_image_count'] ?? 0),
            'galleries' => (string) (int) ($row['source_gallery_count'] ?? 0),
        ]),
        'from_label' => t('admin.gallery_editor.gallery_date_from', 'From'),
        'to_label' => t('admin.gallery_editor.gallery_date_to', 'To'),
    ];
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
    $pageDescription = $scopeGallery
        ? t('admin.gallery_dates.scoped_description', 'Review EXIF-derived date range suggestions for {gallery} and its subgalleries.', ['gallery' => (string) ($scopeGallery['title'] ?? '')])
        : t('admin.gallery_dates.description', 'Review EXIF-derived date range suggestions. Each suggestion uses scanned original photo metadata from the gallery and all of its subgalleries.');
    $heroActions = [];
    if ($scopeGallery) {
        $heroActions[] = [
            'url' => admin_edit_gallery_tab_url((int) $scopeGallery['id'], 'admin-edit-identity'),
            'label' => t('admin.gallery_dates.back_to_gallery', 'Back to gallery'),
        ];
        $heroActions[] = [
            'url' => url_for('admin_gallery_dates'),
            'label' => t('admin.gallery_dates.review_all', 'Review all galleries'),
        ];
    }
    $heroActions[] = ['url' => url_for('admin'), 'label' => t('admin.gallery_dates.back_to_admin', 'Back to Admin')];
    $formParams = $scopeGalleryId > 0 ? ['gallery_id' => $scopeGalleryId] : [];
    $viewModel = [
        'notice' => (string) flash_message('admin_notice'),
        'kicker' => t('admin.gallery_dates.kicker', 'Gallery maintenance'),
        'title' => $scopeGallery
            ? t('admin.gallery_dates.scoped_title', 'Gallery dates for {gallery}', ['gallery' => (string) ($scopeGallery['title'] ?? '')])
            : t('admin.gallery_dates.title', 'Gallery dates'),
        'description' => $pageDescription,
        'hero_actions' => $heroActions,
        'exif_schema_ready' => gallery_date_exif_suggestions_schema_ready(),
        'exif_unavailable_label' => t('admin.gallery_dates.exif_unavailable', 'EXIF capture-date suggestions require the EXIF/GPS image metadata migration and scanned image rows.'),
        'rows' => array_map('Gallery\Controllers\admin_gallery_dates_row_view_model', $rows),
        'empty_title' => t('admin.gallery_dates.no_suggestions_title', 'No EXIF dates found'),
        'empty_hint' => t('admin.gallery_dates.no_suggestions_hint', 'No scanned original photo currently has an EXIF capture date. Run Scan/import images for galleries that were imported before EXIF extraction existed.'),
        'form_action' => url_for('admin_gallery_dates', $formParams),
        'scope_gallery_id' => $scopeGalleryId,
        'suggestions_kicker' => t('admin.gallery_dates.suggestions_kicker', 'EXIF suggestions'),
        'suggestions_title' => t('admin.gallery_dates.suggestions_title', 'Approve, edit, or ignore suggestions'),
        'suggestions_help' => t('admin.gallery_dates.suggestions_help', 'Checked rows will be saved. Unchecked rows are ignored. Date inputs are editable before applying. Existing manual dates are not checked by default.'),
        'column_labels' => [
            t('admin.gallery_dates.column_apply', 'Apply'),
            t('admin.gallery_dates.column_gallery', 'Gallery'),
            t('admin.gallery_dates.column_current', 'Current'),
            t('admin.gallery_dates.column_suggested', 'Suggested'),
            t('admin.gallery_dates.column_edit', 'Edit before saving'),
        ],
        'cancel_url' => $scopeGallery ? admin_edit_gallery_tab_url((int) $scopeGallery['id'], 'admin-edit-identity') : url_for('admin'),
        'apply_selected_label' => t('admin.gallery_dates.apply_selected', 'Apply selected date ranges'),
        'cancel_label' => t('admin.gallery_dates.cancel', 'Cancel'),
    ];

    render_header(t('admin.gallery_dates.page_title', 'Gallery dates'));
    view_render_admin_gallery_dates_page($viewModel);
    render_footer();
}
