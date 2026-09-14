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

namespace Gallery\Controllers;

use Throwable;
use function Gallery\Core\csrf_field;
use function Gallery\Core\current_user;
use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\media_renamer_all_gallery_ids;
use function Gallery\Services\media_renamer_availability_for_gallery_ids;
use function Gallery\Services\media_renamer_default_pattern;
use function Gallery\Services\media_renamer_empty_execution_result;
use function Gallery\Services\media_renamer_execute_galleries;
use function Gallery\Services\media_renamer_execute_image_batch;
use function Gallery\Services\media_renamer_existing_gallery_ids;
use function Gallery\Services\media_renamer_gallery_ids_with_pending_renames;
use function Gallery\Services\media_renamer_gallery_rows;
use function Gallery\Services\media_renamer_gallery_rows_with_rename_availability;
use function Gallery\Services\media_renamer_gallery_rows_with_submitted_availability;
use function Gallery\Services\media_renamer_normalize_pattern;
use function Gallery\Services\media_renamer_pattern_help_text;
use function Gallery\Services\media_renamer_plan_for_gallery;
use function Gallery\Services\media_renamer_plans_for_galleries;
use function Gallery\Services\t;
use function Gallery\Services\admin_log_event;
use function Gallery\Services\admin_diagnostic_log_write;
use function Gallery\Services\admin_log_current_route_name;
use function Gallery\Services\telemetry_request_id;

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
    $hideEmptyGalleries = true;
    $hideGalleriesWithoutRenameCandidates = false;
    $renameAvailabilityChecked = false;
    $renameAvailability = [];

    if (request_method() === 'POST') {
        if (!admin_media_renamer_verify_csrf_for_ajax()) {
            return;
        }
        $action = (string) ($_POST['renamer_action'] ?? 'preview');
        if ($action === 'client_error') {
            admin_media_renamer_handle_client_error();
            return;
        }
        $hideEmptyGalleries = admin_media_renamer_hide_empty_from_post();
        $hideGalleriesWithoutRenameCandidates = admin_media_renamer_hide_done_from_post();
        $renameAvailabilityChecked = admin_media_renamer_availability_checked_from_post($action);
        $selectedScope = admin_media_renamer_scope_from_post();
        $selectedSingleGalleryId = (int) ($_POST['single_gallery_id'] ?? 0);
        $pattern = media_renamer_normalize_pattern((string) ($_POST['renamer_pattern'] ?? ''));
        $submittedAvailability = admin_media_renamer_availability_payload_from_post();

        if ($action === 'check_availability_batch') {
            $batchIds = media_renamer_existing_gallery_ids((array) ($_POST['gallery_ids'] ?? []));
            admin_media_renamer_json_response([
                'ok' => true,
                'availability' => media_renamer_availability_for_gallery_ids($batchIds, $pattern),
                'processed' => count($batchIds),
            ]);
        }

        if ($action === 'apply_batch') {
            if (empty($_POST['confirm_media_rename'])) {
                admin_media_renamer_json_response([
                    'ok' => false,
                    'error' => t('admin.media_renamer.confirm_required', 'Confirm that you reviewed the preview before applying physical renames.'),
                ], 400);
            }
            $batchImageIds = admin_media_renamer_image_ids_from_post('batch_image_ids');
            try {
                admin_media_renamer_json_response([
                    'ok' => true,
                    'result' => admin_media_renamer_bounded_result(media_renamer_execute_image_batch($batchImageIds, $pattern)),
                    'processed' => count($batchImageIds),
                ]);
            } catch (Throwable $exception) {
                admin_media_renamer_log_exception('media_renamer.site_batch_failed', 'Site-wide media rename batch failed.', $exception, [
                    'selected_scope' => $selectedScope,
                    'selected_gallery_ids' => $selectedGalleryIds,
                    'batch_image_ids' => $batchImageIds,
                    'pattern' => $pattern,
                ]);
                admin_media_renamer_json_response([
                    'ok' => false,
                    'error' => $exception->getMessage(),
                ], 500);
            }
        }

        if ($action === 'apply_refresh') {
            $lastResult = admin_media_renamer_result_payload_from_post();
            $notice = admin_media_renamer_result_notice($lastResult);
            $completionSeverity = admin_media_renamer_result_log_severity($lastResult);
            admin_media_renamer_log_event($completionSeverity === 'warning' ? 'warning' : 'info', 'media_renamer.site_completed', 'Site-wide media rename completed.', [
                'selected_scope' => $selectedScope,
                'selected_gallery_ids' => admin_media_renamer_gallery_ids_from_post($selectedScope, $hideEmptyGalleries, false, $pattern),
                'pattern' => $pattern,
                'batched' => true,
                'result' => admin_media_renamer_loggable_result($lastResult),
            ], ['category' => 'media', 'severity' => $completionSeverity]);
        }

        $filterSelectedFromSubmittedAvailability = $hideGalleriesWithoutRenameCandidates && $renameAvailabilityChecked && $submittedAvailability;
        $selectedGalleryIds = admin_media_renamer_gallery_ids_from_post($selectedScope, $hideEmptyGalleries, $hideGalleriesWithoutRenameCandidates && $renameAvailabilityChecked && !$submittedAvailability, $pattern);
        if ($filterSelectedFromSubmittedAvailability) {
            $selectedGalleryIds = admin_media_renamer_filter_gallery_ids_by_availability($selectedGalleryIds, $submittedAvailability);
        }

        if ($action === 'check_availability') {
            $notice = t('admin.media_renamer.availability_checked', 'Rename availability was checked for the current list. Galleries with no pending renames can now be hidden.');
        } elseif ($action === 'apply_refresh') {
            $plans = $selectedGalleryIds ? media_renamer_plans_for_galleries($selectedGalleryIds, $pattern) : [];
        } elseif (!$selectedGalleryIds) {
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
                    $completionSeverity = admin_media_renamer_result_log_severity($result);
                    admin_media_renamer_log_event($completionSeverity === 'warning' ? 'warning' : 'info', 'media_renamer.site_completed', 'Site-wide media rename completed.', [
                        'selected_scope' => $selectedScope,
                        'selected_gallery_ids' => $selectedGalleryIds,
                        'pattern' => $pattern,
                        'result' => admin_media_renamer_loggable_result($result),
                    ], ['category' => 'media', 'severity' => $completionSeverity]);
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

    $galleryRows = media_renamer_gallery_rows($hideEmptyGalleries);
    if ($renameAvailabilityChecked) {
        $submittedAvailability = request_method() === 'POST' ? admin_media_renamer_availability_payload_from_post() : [];
        if ($submittedAvailability) {
            $renameAvailability = $submittedAvailability;
            $galleryRows = media_renamer_gallery_rows_with_submitted_availability($galleryRows, $renameAvailability, $hideGalleriesWithoutRenameCandidates);
        } else {
            $availabilityResult = media_renamer_gallery_rows_with_rename_availability($galleryRows, $pattern, $hideGalleriesWithoutRenameCandidates);
            $galleryRows = (array) ($availabilityResult['rows'] ?? []);
            $renameAvailability = (array) ($availabilityResult['availability'] ?? []);
        }
        if ($selectedScope === 'all') {
            $selectedGalleryIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $galleryRows);
        }
    }
    $workspaceHtml = admin_media_renamer_render_site_workspace($galleryRows, $selectedScope, $selectedGalleryIds, $selectedSingleGalleryId, $pattern, $plans, $notice, $lastResult, $hideEmptyGalleries, $hideGalleriesWithoutRenameCandidates, $renameAvailabilityChecked, $renameAvailability);
    if (admin_wants_json()) {
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'message' => $notice,
            'body_html' => $workspaceHtml,
        ]);
        return;
    }

    \Gallery\Views\view_render_admin_media_renamer_page([
        'page_title' => t('admin.media_renamer.page_title', 'Media renamer'),
        'admin_url' => url_for('admin'),
        'workspace_html' => $workspaceHtml,
    ]);
}

/**
 * Render the site-wide renamer workspace for normal and AJAX requests.
 *
 * @param array<int,array<string,mixed>> $galleryRows Gallery rows value.
 * @param string $selectedScope Selected scope value.
 * @param array<int> $selectedGalleryIds Selected gallery ids value.
 * @param int $selectedSingleGalleryId Selected single gallery id identifier.
 * @param string $pattern Pattern value.
 * @param array<int,array<string,mixed>> $plans Plans value.
 * @param string $notice Notice value.
 * @param ?array $lastResult Last result value.
 * @param bool $hideEmptyGalleries Hide empty galleries value.
 * @param bool $hideGalleriesWithoutRenameCandidates Hide galleries without rename candidates value.
 * @param bool $renameAvailabilityChecked Rename availability checked value.
 * @param array $renameAvailability Rename availability value.
 * @return string Text result for the caller.
 */
function admin_media_renamer_render_site_workspace(array $galleryRows, string $selectedScope, array $selectedGalleryIds, int $selectedSingleGalleryId, string $pattern, array $plans, string $notice = '', ?array $lastResult = null, bool $hideEmptyGalleries = true, bool $hideGalleriesWithoutRenameCandidates = false, bool $renameAvailabilityChecked = false, array $renameAvailability = []): string
{
    ob_start();
    render_admin_media_renamer_scope_form($galleryRows, $selectedScope, $selectedGalleryIds, $selectedSingleGalleryId, $pattern, $hideEmptyGalleries, $hideGalleriesWithoutRenameCandidates, $renameAvailabilityChecked, $renameAvailability);
    $scopeFormHtml = (string) ob_get_clean();

    $planTableHtml = '';
    $executionDetailsHtml = '';
    $applyFormHtml = '';
    if ($plans) {
        ob_start();
        render_admin_media_renamer_plan_table($plans);
        $planTableHtml = (string) ob_get_clean();
        if ($lastResult !== null) {
            ob_start();
            render_admin_media_renamer_execution_details((array) ($lastResult['details'] ?? []));
            $executionDetailsHtml = (string) ob_get_clean();
        }
        ob_start();
        render_admin_media_renamer_apply_form($selectedScope, $selectedGalleryIds, $selectedSingleGalleryId, $pattern, $hideEmptyGalleries, $hideGalleriesWithoutRenameCandidates, $renameAvailabilityChecked, $renameAvailability);
        $applyFormHtml = (string) ob_get_clean();
    }

    ob_start();
    \Gallery\Views\view_render_admin_media_renamer_site_workspace([
        'log_url' => url_for('admin_media_renamer'),
        'notice' => $notice,
        'scope_form_html' => $scopeFormHtml,
        'has_plans' => $plans !== [],
        'plan_table_html' => $planTableHtml,
        'execution_details_html' => $executionDetailsHtml,
        'apply_form_html' => $applyFormHtml,
    ]);
    return (string) ob_get_clean();
}

/**
 * Render the gallery editor panel for the current gallery only.
 *
 * @param array $gallery Gallery row or gallery data.
 */
function render_admin_media_renamer_gallery_panel(array $gallery): void
{
    $pattern = media_renamer_normalize_pattern((string) ($_GET['renamer_pattern'] ?? $_POST['renamer_pattern'] ?? ''));
    \Gallery\Views\view_render_admin_media_renamer_fragment(
        admin_media_renamer_render_gallery_panel_html($gallery, $pattern)
    );
}

/**
 * Render the gallery-level renamer panel HTML so normal and AJAX requests share one view.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param string $pattern Pattern value.
 * @param string $notice Notice value.
 * @param ?array $result Result value.
 * @return string Text result for the caller.
 */
function admin_media_renamer_render_gallery_panel_html(array $gallery, string $pattern, string $notice = '', ?array $result = null): string
{
    $galleryId = (int) ($gallery['id'] ?? 0);
    $pattern = media_renamer_normalize_pattern($pattern);

    ob_start();
    render_admin_media_renamer_pattern_preview_form($galleryId, $pattern);
    $patternFormHtml = (string) ob_get_clean();

    try {
        $plan = media_renamer_plan_for_gallery($galleryId, $pattern);
    } catch (Throwable $exception) {
        ob_start();
        \Gallery\Views\view_render_admin_media_renamer_gallery_panel([
            'log_url' => url_for('admin_media_renamer'),
            'notice' => $notice,
            'pattern_form_html' => $patternFormHtml,
            'error' => $exception->getMessage(),
        ]);
        return (string) ob_get_clean();
    }

    ob_start();
    render_admin_media_renamer_plan_table([$plan]);
    $planTableHtml = (string) ob_get_clean();
    $executionDetailsHtml = '';
    if ($result !== null) {
        ob_start();
        render_admin_media_renamer_execution_details((array) ($result['details'] ?? []));
        $executionDetailsHtml = (string) ob_get_clean();
    }

    $summary = (array) ($plan['summary'] ?? []);
    $renameCount = (int) ($summary['rename'] ?? 0);
    ob_start();
    \Gallery\Views\view_render_admin_media_renamer_gallery_panel([
        'log_url' => url_for('admin_media_renamer'),
        'notice' => $notice,
        'pattern_form_html' => $patternFormHtml,
        'error' => '',
        'plan_table_html' => $planTableHtml,
        'execution_details_html' => $executionDetailsHtml,
        'rename_count' => $renameCount,
        'apply_url' => url_for('admin_edit_gallery'),
        'csrf_html' => csrf_field(),
        'gallery_id' => $galleryId,
        'pattern' => $pattern,
    ]);
    return (string) ob_get_clean();
}

/**
 * Render a gallery-level GET form that changes only the dry-run pattern.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $pattern Pattern value.
 */
function render_admin_media_renamer_pattern_preview_form(int $galleryId, string $pattern): void
{
    \Gallery\Views\view_render_admin_media_renamer_pattern_preview_form([
        'action_url' => url_for('admin_edit_gallery'),
        'gallery_id' => $galleryId,
        'pattern' => $pattern,
        'default_pattern' => media_renamer_default_pattern(),
        'pattern_help' => media_renamer_pattern_help_text(),
    ]);
}

/**
 * Render the site-wide gallery selection form.
 *
 * @param array<int,array<string,mixed>> $galleryRows Gallery rows value.
 * @param string $selectedScope Selected scope value.
 * @param array<int> $selectedGalleryIds Selected gallery ids value.
 * @param int $selectedSingleGalleryId Selected single gallery id identifier.
 * @param string $pattern Pattern value.
 * @param bool $hideEmptyGalleries Hide empty galleries value.
 * @param bool $hideGalleriesWithoutRenameCandidates Hide galleries without rename candidates value.
 * @param bool $renameAvailabilityChecked Rename availability checked value.
 * @param array $renameAvailability Rename availability value.
 */
function render_admin_media_renamer_scope_form(array $galleryRows, string $selectedScope, array $selectedGalleryIds, int $selectedSingleGalleryId, string $pattern, bool $hideEmptyGalleries = true, bool $hideGalleriesWithoutRenameCandidates = false, bool $renameAvailabilityChecked = false, array $renameAvailability = []): void
{
    $pendingTotal = array_sum(array_map(static fn (array $row): int => (int) ($row['rename_candidate_count'] ?? 0), $galleryRows));
    $preparedRows = [];
    foreach ($galleryRows as $gallery) {
        $id = (int) ($gallery['id'] ?? 0);
        $gallery['selector_label'] = (string) ($gallery['folder_path'] ?? $gallery['title'] ?? ('#' . $id));
        $gallery['edit_url'] = url_for('admin_edit_gallery', ['id' => $id, 'tab' => 'admin-edit-renamer']) . '#admin-edit-renamer';
        $preparedRows[] = $gallery;
    }

    \Gallery\Views\view_render_admin_media_renamer_scope_form([
        'gallery_rows' => $preparedRows,
        'selected_scope' => $selectedScope,
        'selected_gallery_ids' => $selectedGalleryIds,
        'selected_single_gallery_id' => $selectedSingleGalleryId,
        'pattern' => $pattern,
        'hide_empty_galleries' => $hideEmptyGalleries,
        'hide_done_galleries' => $hideGalleriesWithoutRenameCandidates,
        'rename_availability_checked' => $renameAvailabilityChecked,
        'availability_payload' => admin_media_renamer_encode_availability_payload($renameAvailability),
        'pending_total' => $pendingTotal,
        'action_url' => url_for('admin_media_renamer'),
        'csrf_html' => csrf_field(),
        'default_pattern' => media_renamer_default_pattern(),
        'pattern_help' => media_renamer_pattern_help_text(),
    ]);
}

/**
 * Render the apply form for a previously previewed site-wide plan.
 *
 * @param string $selectedScope Selected scope value.
 * @param array<int> $selectedGalleryIds Selected gallery ids value.
 * @param int $selectedSingleGalleryId Selected single gallery id identifier.
 * @param string $pattern Pattern value.
 * @param bool $hideEmptyGalleries Hide empty galleries value.
 * @param bool $hideGalleriesWithoutRenameCandidates Hide galleries without rename candidates value.
 * @param bool $renameAvailabilityChecked Rename availability checked value.
 * @param array $renameAvailability Rename availability value.
 */
function render_admin_media_renamer_apply_form(string $selectedScope, array $selectedGalleryIds, int $selectedSingleGalleryId, string $pattern, bool $hideEmptyGalleries = true, bool $hideGalleriesWithoutRenameCandidates = false, bool $renameAvailabilityChecked = false, array $renameAvailability = []): void
{
    $plans = media_renamer_plans_for_galleries($selectedGalleryIds, $pattern);
    $aggregate = admin_media_renamer_aggregate_plans($plans);
    $renameCount = (int) ($aggregate['rename'] ?? 0);
    $candidateImageIds = admin_media_renamer_candidate_image_ids_from_plans($plans);

    \Gallery\Views\view_render_admin_media_renamer_apply_form([
        'action_url' => url_for('admin_media_renamer'),
        'csrf_html' => csrf_field(),
        'rename_count' => $renameCount,
        'candidate_image_ids' => $candidateImageIds,
        'selected_scope' => $selectedScope,
        'selected_gallery_ids' => $selectedGalleryIds,
        'selected_single_gallery_id' => $selectedSingleGalleryId,
        'pattern' => $pattern,
        'hide_empty_galleries' => $hideEmptyGalleries,
        'hide_done_galleries' => $hideGalleriesWithoutRenameCandidates,
        'rename_availability_checked' => $renameAvailabilityChecked,
        'availability_payload' => admin_media_renamer_encode_availability_payload($renameAvailability),
    ]);
}

/**
 * Render dry-run plan tables grouped by gallery.
 *
 * @param array<int,array<string,mixed>> $plans Plans value.
 */
function render_admin_media_renamer_plan_table(array $plans): void
{
    $preparedPlans = [];
    foreach ($plans as $plan) {
        $preparedPlan = $plan;
        $preparedItems = [];
        foreach ((array) ($plan['items'] ?? []) as $item) {
            $preparedItem = $item;
            $preparedItem['status_label'] = admin_media_renamer_status_label((string) ($item['status'] ?? 'skipped'));
            $preparedItem['notes_text'] = implode(' ', array_map('strval', (array) ($item['warnings'] ?? [])));
            $preparedItems[] = $preparedItem;
        }
        $preparedPlan['items'] = $preparedItems;
        $preparedPlans[] = $preparedPlan;
    }

    \Gallery\Views\view_render_admin_media_renamer_plan_table([
        'aggregate' => admin_media_renamer_aggregate_plans($plans),
        'plans' => $preparedPlans,
    ]);
}

/**
 * Render the execution detail table after an apply run.
 *
 * @param array<int,array<string,mixed>> $details Details value.
 */
function render_admin_media_renamer_execution_details(array $details): void
{
    $preparedDetails = [];
    foreach ($details as $detail) {
        $preparedDetail = $detail;
        $preparedDetail['status_label'] = admin_media_renamer_status_label((string) ($detail['status'] ?? 'skipped'));
        $preparedDetail['notes_text'] = implode(' ', array_map('strval', (array) ($detail['notes'] ?? [])));
        $preparedDetails[] = $preparedDetail;
    }
    \Gallery\Views\view_render_admin_media_renamer_execution_details([
        'details' => $preparedDetails,
    ]);
}

/**
 * Return image ids that are planned for physical rename.
 *
 * @param array<int,array<string,mixed>> $plans Plans value.
 * @return array<int> Structured result data for the caller.
 */
function admin_media_renamer_candidate_image_ids_from_plans(array $plans): array
{
    $ids = [];
    foreach ($plans as $plan) {
        foreach ((array) ($plan['items'] ?? []) as $item) {
            if (!empty($item['can_rename']) && (string) ($item['status'] ?? '') === 'rename') {
                $ids[] = (int) ($item['image_id'] ?? 0);
            }
        }
    }
    return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
}

/**
 * Read image ids from a posted array field.
 *
 * @param string $field Field value.
 * @return array<int> Structured result data for the caller.
 */
function admin_media_renamer_image_ids_from_post(string $field): array
{
    return array_values(array_unique(array_filter(array_map('intval', (array) ($_POST[$field] ?? [])), static fn (int $id): bool => $id > 0)));
}

/**
 * Trim large batch results before returning them through AJAX.
 *
 * @param array $result Result value.
 * @param int $detailLimit Detail limit value.
 * @return array<string,mixed> Structured result data for the caller.
 */
function admin_media_renamer_bounded_result(array $result, int $detailLimit = 300): array
{
    if (isset($result['details']) && is_array($result['details'])) {
        $result['details'] = array_slice($result['details'], 0, $detailLimit);
    }
    if (isset($result['warnings']) && is_array($result['warnings'])) {
        $result['warnings'] = array_slice(array_map('strval', $result['warnings']), 0, 50);
    }
    if (isset($result['failures']) && is_array($result['failures'])) {
        $result['failures'] = array_slice(array_map('strval', $result['failures']), 0, 50);
    }
    return $result;
}

/**
 * Decode the aggregate result produced by the AJAX batch runner.
 *
 * @return array<string,mixed> Structured result data for the caller.
 */
function admin_media_renamer_result_payload_from_post(): array
{
    $raw = (string) ($_POST['batch_result_payload'] ?? '');
    $decoded = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        return media_renamer_empty_execution_result(0);
    }

    $result = media_renamer_empty_execution_result((int) ($decoded['galleries_requested'] ?? 0));
    foreach (['galleries_processed', 'renamed', 'already_matches', 'missing', 'skipped', 'collisions', 'derivatives_moved', 'derivatives_cleaned', 'derivative_failures', 'zip_archives_deleted', 'titles_updated'] as $key) {
        $result[$key] = max(0, (int) ($decoded[$key] ?? 0));
    }
    $result['details'] = array_slice((array) ($decoded['details'] ?? []), 0, 500);
    $result['warnings'] = array_slice(array_map('strval', (array) ($decoded['warnings'] ?? [])), 0, 50);
    $result['failures'] = array_slice(array_map('strval', (array) ($decoded['failures'] ?? [])), 0, 50);
    return $result;
}

/**
 * Aggregate summary counters across rendered plans.
 *
 * @param array<int,array<string,mixed>> $plans Plans value.
 * @return array<string,int> Structured result data for the caller.
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
 *
 * @param string $status Status value.
 * @return string Text result for the caller.
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
 *
 * @return string Text result for the caller.
 */
function admin_media_renamer_scope_from_post(): string
{
    $scope = (string) ($_POST['renamer_scope'] ?? 'selected');
    return in_array($scope, ['all', 'single', 'selected'], true) ? $scope : 'selected';
}

/**
 * Resolve gallery ids from the submitted site-wide form.
 *
 * @param string $scope Scope value.
 * @param bool $hideEmptyGalleries Hide empty galleries value.
 * @param bool $hideGalleriesWithoutRenameCandidates Hide galleries without rename candidates value.
 * @param string $pattern Pattern value.
 * @return array<int> Structured result data for the caller.
 */
function admin_media_renamer_gallery_ids_from_post(string $scope, bool $hideEmptyGalleries = false, bool $hideGalleriesWithoutRenameCandidates = false, string $pattern = ''): array
{
    if ($scope === 'all') {
        $galleryIds = media_renamer_all_gallery_ids($hideEmptyGalleries);
    } elseif ($scope === 'single') {
        $galleryIds = media_renamer_existing_gallery_ids([(int) ($_POST['single_gallery_id'] ?? 0)]);
    } else {
        $galleryIds = media_renamer_existing_gallery_ids((array) ($_POST['gallery_ids'] ?? []));
    }

    if ($hideGalleriesWithoutRenameCandidates) {
        $galleryIds = media_renamer_gallery_ids_with_pending_renames($galleryIds, $pattern);
    }

    return $galleryIds;
}



/**
 * Read whether zero-image galleries should be hidden from the site-wide selector.
 *
 * @return bool True when the condition matches.
 */
function admin_media_renamer_hide_empty_from_post(): bool
{
    $raw = (string) ($_POST['hide_empty_galleries'] ?? '0');
    return in_array($raw, ['1', 'true', 'on', 'yes'], true);
}


/**
 * Read whether already-renamed galleries should be hidden after availability was checked.
 *
 * @return bool True when the condition matches.
 */
function admin_media_renamer_hide_done_from_post(): bool
{
    $raw = (string) ($_POST['hide_done_galleries'] ?? '0');
    return in_array($raw, ['1', 'true', 'on', 'yes'], true);
}

/**
 * Read whether the expensive rename availability scan has already been requested.
 *
 * @param string $action Action value.
 * @return bool True when the condition matches.
 */
function admin_media_renamer_availability_checked_from_post(string $action): bool
{
    if ($action === 'check_availability') {
        return true;
    }
    $raw = (string) ($_POST['rename_availability_checked'] ?? '0');
    return in_array($raw, ['1', 'true', 'on', 'yes'], true);
}



/**
 * Decode a submitted availability payload produced by the on-demand batch checker.
 *
 * @return array<int,array<string,int>> Structured result data for the caller.
 */
function admin_media_renamer_availability_payload_from_post(): array
{
    $raw = (string) ($_POST['rename_availability_payload'] ?? '');
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $availability = [];
    foreach ($decoded as $galleryId => $counts) {
        $id = (int) $galleryId;
        if ($id <= 0 || !is_array($counts)) {
            continue;
        }
        $availability[$id] = [
            'rename_count' => max(0, (int) ($counts['rename_count'] ?? 0)),
            'warning_count' => max(0, (int) ($counts['warning_count'] ?? 0)),
        ];
    }

    return $availability;
}

/**
 * Encode availability counts for hidden form transport between AJAX refreshes.
 *
 * @param array<int,array<string,int>> $availability Availability value.
 * @return string Text result for the caller.
 */
function admin_media_renamer_encode_availability_payload(array $availability): string
{
    if (!$availability) {
        return '';
    }

    return (string) json_encode($availability, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Keep only gallery ids that still have at least one pending rename candidate.
 *
 * @param array<int|string> $galleryIds Gallery ids value.
 * @param array<int,array<string,int>> $availability Availability value.
 * @return array<int> Structured result data for the caller.
 */
function admin_media_renamer_filter_gallery_ids_by_availability(array $galleryIds, array $availability): array
{
    $filtered = [];
    foreach (media_renamer_existing_gallery_ids($galleryIds) as $galleryId) {
        if ((int) ($availability[$galleryId]['rename_count'] ?? 0) > 0) {
            $filtered[] = $galleryId;
        }
    }

    return $filtered;
}

/**
 * Verify CSRF for media-renamer AJAX routes and emit JSON/logs on failure.
 *
 * @return bool True when the condition matches.
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
 *
 * @param array $payload Payload value.
 * @param int $statusCode Status code value.
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
 *
 * @param string $eventKey Event key value.
 * @param string $message Message value.
 * @param Throwable $exception Exception value.
 * @param array $context Context value.
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
 *
 * @param string $level Level value.
 * @param string $eventKey Event key value.
 * @param string $message Message value.
 * @param array $context Context value.
 * @param array $options Optional behavior flags.
 */
function admin_media_renamer_log_event(string $level, string $eventKey, string $message, array $context = [], array $options = []): void
{
    $written = admin_media_renamer_write_admin_log_direct($level, $eventKey, $message, $context, $options);
    if (!$written && function_exists('Gallery\\Services\\admin_log_event')) {
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
 *
 * @param string $level Level value.
 * @param string $eventKey Event key value.
 * @param string $message Message value.
 * @param array $context Context value.
 * @param array $options Optional behavior flags.
 * @return bool True when the condition matches.
 */
function admin_media_renamer_write_admin_log_direct(string $level, string $eventKey, string $message, array $context = [], array $options = []): bool
{
    try {
        $user = function_exists('Gallery\\Core\\current_user') ? current_user() : null;
        return admin_diagnostic_log_write($level, $eventKey, $message, $context, $options, [
            'user_id' => $user ? (int) $user['id'] : null,
            'request_id' => function_exists('Gallery\\Services\\telemetry_request_id') ? telemetry_request_id() : null,
            'route_name' => function_exists('Gallery\\Services\\admin_log_current_route_name') ? admin_log_current_route_name() : '',
            'http_method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            'is_ajax' => admin_wants_json(),
        ]);
    } catch (Throwable $exception) {
        error_log('[PHP Gallery] media_renamer.direct_admin_log_failed: ' . $exception->getMessage());
        return false;
    }
}

/**
 * Return a bounded exception trace suitable for the admin log context JSON.
 *
 * @param Throwable $exception Exception value.
 * @return array Structured result data for the caller.
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
 *
 * @return array Structured result data for the caller.
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
 *
 * @param array $result Result value.
 * @return array Structured result data for the caller.
 */
function admin_media_renamer_loggable_result(array $result): array
{
    unset($result['details']);
    if (isset($result['warnings']) && is_array($result['warnings'])) {
        $result['warnings'] = array_slice(array_map('strval', $result['warnings']), 0, 20);
    }
    if (isset($result['failures']) && is_array($result['failures'])) {
        $result['failures'] = array_slice(array_map('strval', $result['failures']), 0, 20);
    }
    return $result;
}

/**
 * Build a visible result notice after a physical rename run.
 *
 * @param array $result Result value.
 * @return string Text result for the caller.
 */
function admin_media_renamer_result_notice(array $result): string
{
    $message = t('admin.media_renamer.result_notice', 'Processed {galleries} gallery/galleries. Renamed {renamed} file(s), invalidated {derivatives} generated derivative cache file(s), skipped {skipped} row(s), saw {missing} missing file(s), updated {titles} derived title(s), and removed {archives} stale ZIP archive row(s).', [
        'galleries' => (string) (int) ($result['galleries_processed'] ?? 0),
        'renamed' => (string) (int) ($result['renamed'] ?? 0),
        'derivatives' => (string) ((int) ($result['derivatives_moved'] ?? 0) + (int) ($result['derivatives_cleaned'] ?? 0)),
        'skipped' => (string) ((int) ($result['skipped'] ?? 0) + (int) ($result['collisions'] ?? 0)),
        'missing' => (string) (int) ($result['missing'] ?? 0),
        'archives' => (string) (int) ($result['zip_archives_deleted'] ?? 0),
        'titles' => (string) (int) ($result['titles_updated'] ?? 0),
    ]);

    $derivativeFailures = (int) ($result['derivative_failures'] ?? 0);
    if ($derivativeFailures > 0) {
        $message .= ' ' . t('admin.media_renamer.result_derivative_warnings', 'Generated derivative warnings: {count}.', ['count' => (string) $derivativeFailures]);
    }

    $warnings = (array) ($result['warnings'] ?? []);
    if ($warnings) {
        $message .= ' ' . t('admin.media_renamer.result_warnings', 'Warnings: {warnings}', ['warnings' => implode(' | ', array_map('strval', array_slice($warnings, 0, 8)))]);
    }

    $failures = (array) ($result['failures'] ?? []);
    if ($failures) {
        $message .= ' ' . t('admin.media_renamer.result_failures', 'Failures: {failures}', ['failures' => implode(' | ', array_map('strval', $failures))]);
    }
    return $message;
}

/**
 * Return the Admin Logs severity for a completed media rename run.
 *
 * @param array $result Result value.
 * @return string Text result for the caller.
 */
function admin_media_renamer_result_log_severity(array $result): string
{
    if (!empty($result['failures'])) {
        return 'warning';
    }
    if (!empty($result['warnings']) || (int) ($result['derivative_failures'] ?? 0) > 0) {
        return 'warning';
    }
    return 'info';
}
