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

use Gallery\Services\MediaRenamerCandidatePlanUnavailable;
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
use function Gallery\Services\media_renamer_confirmed_candidate_page;
use function Gallery\Services\media_renamer_default_pattern;
use function Gallery\Services\media_renamer_empty_execution_result;
use function Gallery\Services\media_renamer_execute_candidate_batch;
use function Gallery\Services\media_renamer_execute_galleries;
use function Gallery\Services\media_renamer_existing_gallery_ids;
use function Gallery\Services\media_renamer_gallery_ids_with_pending_renames;
use function Gallery\Services\media_renamer_gallery_rows;
use function Gallery\Services\media_renamer_gallery_rows_with_rename_availability
;
use function Gallery\Services\media_renamer_gallery_rows_with_submitted_availability
;
use function Gallery\Services\media_renamer_normalize_pattern;
use function Gallery\Services\media_renamer_pattern_help_text;
use function Gallery\Services\media_renamer_plan_for_gallery;
use function Gallery\Services\media_renamer_plans_for_galleries;
use function Gallery\Services\media_renamer_database_preview;
use function Gallery\Services\media_renamer_report_append;
use function Gallery\Services\media_renamer_report_load;
use function Gallery\Services\media_renamer_report_page;
use const Gallery\Services\MEDIA_RENAMER_DETAIL_PAGE_SIZE;
use const Gallery\Core\MEDIA_RENAMER_PHYSICAL_BATCH_SIZE;
use function Gallery\Services\t;
use function Gallery\Services\admin_log_event;
use function Gallery\Services\admin_diagnostic_log_write;
use function Gallery\Services\admin_log_current_route_name;
use function Gallery\Services\telemetry_request_id;

/**
 * Handle the site-wide media renamer page and explicit list, preview, and apply requests.
 * @return void Emits the page or the requested AJAX response.
 */
function cms_admin_media_renamer(): void
{
    require_admin();
    if (request_method() === 'GET'
        && in_array($_GET['renamer_action'] ?? '', ['preview_details', 'report_details'], true)) {
        admin_media_renamer_handle_detail_read((string) $_GET['renamer_action'],
            media_renamer_normalize_pattern((string) ($_GET['renamer_pattern'] ?? '')));
        return;
    }

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
        $hideGalleriesWithoutRenameCandidates =
            admin_media_renamer_hide_done_from_post();
        $renameAvailabilityChecked =
            admin_media_renamer_availability_checked_from_post($action);
        $selectedScope = admin_media_renamer_scope_from_post();
        $selectedSingleGalleryId = (int) ($_POST['single_gallery_id'] ?? 0);
        $pattern = media_renamer_normalize_pattern(
            (string) ($_POST['renamer_pattern'] ?? ''),
        );
        if (in_array($action, ['preview_details', 'candidate_page', 'report_details'], true)) {
            admin_media_renamer_handle_detail_read($action, $pattern);
            return;
        }
        $submittedAvailability =
            admin_media_renamer_availability_payload_from_post();
        if ($action === 'filter' && !$submittedAvailability) {
            $renameAvailabilityChecked = false;
        }

        if ($action === 'check_availability_batch') {
            $batchIds = media_renamer_existing_gallery_ids(
                (array) ($_POST['gallery_ids'] ?? []),
            );
            admin_media_renamer_json_response([
                'ok' => true,
                'availability' => media_renamer_availability_for_gallery_ids(
                    $batchIds,
                    $pattern,
                ),
                'processed' => count($batchIds),
            ]);
        }

        if ($action === 'apply_batch') {
            if (empty($_POST['confirm_media_rename'])) {
                admin_media_renamer_json_response([
                    'ok' => false,
                    'error' => t(
                        'admin.media_renamer.confirm_required',
                        'Confirm that you want to rename physical files using this naming pattern.',
                    ),
                ], 400);
            }
            try {
                $batch = admin_media_renamer_execute_posted_candidate_batch($pattern);
                $result = $batch['result'];
                $result['galleries_requested'] = 1;
                $result['galleries_processed'] = 1;
                $renamedIds = array_values(array_map(
                    static fn(array $row): int => (int) $row['image_id'],
                    array_filter($result['details'],
                        static fn(array $row): bool => $row['status'] === 'renamed'),
                ));
                admin_media_renamer_json_response(array_merge(
                    \Gallery\Core\admin_mutation_success_envelope('',
                        \Gallery\Core\admin_mutation_descriptor(
                            'image.media_rename', 'image', 'rename', $renamedIds,
                        ), null, [], ['redirect_url' => url_for('admin_media_renamer')]),
                    ['result' => admin_media_renamer_attach_report($result),
                    'processed' => $batch['processed'],
                ]));
            } catch (MediaRenamerCandidatePlanUnavailable) {
                admin_media_renamer_unavailable_plan();
            } catch (Throwable $exception) {
                admin_media_renamer_log_exception(
                    'media_renamer.site_batch_failed',
                    'Site-wide media rename batch failed.',
                    $exception,
                    [
                        'selected_scope' => $selectedScope,
                        'selected_gallery_ids' => $selectedGalleryIds,
                        'pattern' => $pattern,
                    ],
                );
                admin_media_renamer_json_response([
                    'ok' => false,
                    'error' => $exception->getMessage(),
                ], 500);
            }
        }

        if ($action === 'apply_refresh') {
            $lastResult = admin_media_renamer_result_payload_from_post();
            $notice = admin_media_renamer_result_notice($lastResult);
            $completionSeverity =
                admin_media_renamer_result_log_severity($lastResult);
            admin_media_renamer_log_event(
                $completionSeverity === 'warning' ? 'warning' : 'info',
                'media_renamer.site_completed',
                'Site-wide media rename completed.',
                [
                    'selected_scope' => $selectedScope,
                    'selected_gallery_ids' => admin_media_renamer_gallery_ids_from_post(
                        $selectedScope,
                        $hideEmptyGalleries,
                        false,
                        $pattern,
                    ),
                    'pattern' => $pattern,
                    'batched' => true,
                    'result' =>
                        admin_media_renamer_loggable_result($lastResult),
                ],
                ['category' => 'media', 'severity' => $completionSeverity],
            );
        }

        $filterSelectedFromSubmittedAvailability =
            $hideGalleriesWithoutRenameCandidates
            && $renameAvailabilityChecked
            && $submittedAvailability;
        $selectedGalleryIds = admin_media_renamer_gallery_ids_from_post(
            $selectedScope,
            $hideEmptyGalleries,
            $hideGalleriesWithoutRenameCandidates
                && $renameAvailabilityChecked
                && !$submittedAvailability
                && $action !== 'filter',
            $pattern,
        );
        if ($filterSelectedFromSubmittedAvailability) {
            $selectedGalleryIds = admin_media_renamer_filter_gallery_ids_by_availability(
                $selectedGalleryIds,
                $submittedAvailability,
            );
        }

        if ($action === 'filter') {
            // Visibility changes refresh selector rows only; planning stays an explicit admin action.
            $plans = [];
        } elseif ($action === 'check_availability') {
            $notice = t(
                'admin.media_renamer.availability_checked',
                'Rename availability was checked for the current list. Galleries with no pending renames can now be hidden.',
            );
        } elseif ($action === 'apply_refresh') {
            // A completed run replaces its proposals. Never re-analyze all
            // files merely to render the already-confirmed result summary.
            $plans = [];
        } elseif (!$selectedGalleryIds) {
            $notice = t(
                'admin.media_renamer.no_galleries_selected',
                'Select at least one gallery to preview or rename.',
            );
        } elseif ($action === 'apply') {
            if (empty($_POST['confirm_media_rename'])) {
                $notice = t(
                    'admin.media_renamer.confirm_required',
                    'Confirm that you want to rename physical files using this naming pattern.',
                );
                $plans = media_renamer_plans_for_galleries(
                    $selectedGalleryIds,
                    $pattern,
                );
            } else {
                try {
                    $result = media_renamer_execute_galleries(
                        $selectedGalleryIds,
                        $pattern,
                    );
                    $lastResult = admin_media_renamer_attach_report($result);
                    $notice = admin_media_renamer_result_notice($result);
                    $completionSeverity =
                        admin_media_renamer_result_log_severity($result);
                    admin_media_renamer_log_event(
                        $completionSeverity === 'warning' ? 'warning' : 'info',
                        'media_renamer.site_completed',
                        'Site-wide media rename completed.',
                        [
                            'selected_scope' => $selectedScope,
                            'selected_gallery_ids' => $selectedGalleryIds,
                            'pattern' => $pattern,
                            'result' =>
                                admin_media_renamer_loggable_result($result),
                        ],
                        [
                            'category' => 'media',
                            'severity' => $completionSeverity,
                        ],
                    );
                } catch (Throwable $exception) {
                    $notice = $exception->getMessage();
                    admin_media_renamer_log_exception(
                        'media_renamer.site_failed',
                        'Site-wide media rename failed.',
                        $exception,
                        [
                            'selected_scope' => $selectedScope,
                            'selected_gallery_ids' => $selectedGalleryIds,
                            'pattern' => $pattern,
                        ],
                    );
                }
                $plans = [];
            }
        } else {
            $plans = media_renamer_plans_for_galleries(
                $selectedGalleryIds,
                $pattern,
            );
        }
    }

    $galleryRows = media_renamer_gallery_rows($hideEmptyGalleries);
    if ($renameAvailabilityChecked) {
        $submittedAvailability = request_method() === 'POST'
            ? admin_media_renamer_availability_payload_from_post()
            : [];
        if ($submittedAvailability) {
            $renameAvailability = $submittedAvailability;
            $galleryRows = media_renamer_gallery_rows_with_submitted_availability(
                $galleryRows,
                $renameAvailability,
                $hideGalleriesWithoutRenameCandidates,
            );
        } else {
            $availabilityResult = media_renamer_gallery_rows_with_rename_availability(
                $galleryRows,
                $pattern,
                $hideGalleriesWithoutRenameCandidates,
            );
            $galleryRows = (array) ($availabilityResult['rows'] ?? []);
            $renameAvailability = (array) (
                $availabilityResult['availability'] ?? []
            );
        }
        if ($selectedScope === 'all') {
            $selectedGalleryIds = array_map(
                static fn(array $row): int => (int) ($row['id'] ?? 0),
                $galleryRows,
            );
        }
    }
    $workspaceHtml = admin_media_renamer_render_site_workspace(
        $galleryRows,
        $selectedScope,
        $selectedGalleryIds,
        $selectedSingleGalleryId,
        $pattern,
        $plans,
        $notice,
        $lastResult,
        $hideEmptyGalleries,
        $hideGalleriesWithoutRenameCandidates,
        $renameAvailabilityChecked,
        $renameAvailability,
    );
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
 * @param list<array{id:int,title?:string,folder_path?:string,direct_image_count?:int,rename_candidate_count?:int,rename_warning_count?:int}> $galleryRows Gallery rows used to render the site-wide selector.
 * @param string $selectedScope Selected site-wide scope: all, single, or selected.
 * @param list<int> $selectedGalleryIds Gallery IDs selected for preview or apply.
 * @param int $selectedSingleGalleryId Gallery ID selected by the single-gallery scope.
 * @param string $pattern Normalized filename pattern shown in the forms.
 * @param list<array{gallery:array{id:int|string,title?:string,folder_path?:string,...},items:list<array{gallery_id:int,image_id:int,old_relative_path:string,new_relative_path:string,status:string,warnings:list<string>,can_rename:bool,...}>,summary:array{total:int,rename:int,already_matches:int,missing:int,collision:int,skipped:int,warnings:int},context_base:string,pattern:string,selected_image_ids:list<int>,preview_only?:bool,candidate_image_ids?:list<int>,next_offset?:?int}> $plans Database-only gallery summaries to display, with optional explicitly requested rows.
 * @param string $notice Optional result notice shown in the workspace.
 * @param ?array{galleries_requested:int,galleries_processed:int,renamed:int,already_matches:int,missing:int,skipped:int,collisions:int,derivatives_moved:int,derivatives_cleaned:int,derivative_failures:int,zip_archives_deleted:int,titles_updated:int,details:list<array{gallery_id:int,gallery:string,image_id:int,old:string,new:string,status:string,notes:list<string>}>,warnings:list<string>,failures:list<string>} $lastResult Most recent normalized execution summary, when available.
 * @param bool $hideEmptyGalleries Whether empty galleries are hidden from scope choices.
 * @param bool $hideGalleriesWithoutRenameCandidates Whether galleries without pending renames are hidden.
 * @param bool $renameAvailabilityChecked Whether current availability counts were submitted.
 * @param array<int,array{rename_count:int,warning_count:int}> $renameAvailability Counts keyed by gallery ID.
 * @return string Complete workspace HTML for the page or AJAX response.
 */
function admin_media_renamer_render_site_workspace(
    array $galleryRows,
    string $selectedScope,
    array $selectedGalleryIds,
    int $selectedSingleGalleryId,
    string $pattern,
    array $plans,
    string $notice = '',
    ?array $lastResult = null,
    bool $hideEmptyGalleries = true,
    bool $hideGalleriesWithoutRenameCandidates = false,
    bool $renameAvailabilityChecked = false,
    array $renameAvailability = [],
): string {
    ob_start();
    render_admin_media_renamer_scope_form(
        $galleryRows,
        $selectedScope,
        $selectedGalleryIds,
        $selectedSingleGalleryId,
        $pattern,
        $hideEmptyGalleries,
        $hideGalleriesWithoutRenameCandidates,
        $renameAvailabilityChecked,
        $renameAvailability,
    );
    $scopeFormHtml = (string) ob_get_clean();

    $planTableHtml = '';
    $executionDetailsHtml = '';
    $applyFormHtml = '';
    if ($lastResult !== null) {
        ob_start();
        render_admin_media_renamer_execution_details(
            [], (string) ($lastResult['report_token'] ?? ''),
        );
        $executionDetailsHtml = (string) ob_get_clean();
    } elseif ($plans) {
        ob_start();
        render_admin_media_renamer_plan_table($plans);
        $planTableHtml = (string) ob_get_clean();
        ob_start();
        render_admin_media_renamer_apply_form(
            $selectedScope,
            $selectedGalleryIds,
            $selectedSingleGalleryId,
            $pattern,
            $hideEmptyGalleries,
            $hideGalleriesWithoutRenameCandidates,
            $renameAvailabilityChecked,
            $renameAvailability,
            (int) admin_media_renamer_aggregate_plans($plans)['rename'],
        );
        $applyFormHtml = (string) ob_get_clean();
    }

    ob_start();
    \Gallery\Views\view_render_admin_media_renamer_site_workspace([
        'log_url' => url_for('admin_media_renamer'),
        'detail_url' => url_for('admin_media_renamer'),
        'csrf_html' => csrf_field(),
        'notice' => $notice,
        'scope_form_html' => $scopeFormHtml,
        'has_plans' => $lastResult === null && $plans !== [],
        'plan_table_html' => $planTableHtml,
        'execution_details_html' => $executionDetailsHtml,
        'apply_form_html' => $applyFormHtml,
    ]);
    return (string) ob_get_clean();
}

/**
 * Render the gallery editor panel for the current gallery only.
 *
 * @param array{id:int,title?:string,folder_path?:string} $gallery Gallery row rendered in the editor panel.
 * @return void Renders the gallery-level renamer panel fragment.
 */
function render_admin_media_renamer_gallery_panel(array $gallery): void
{
    $pattern = media_renamer_normalize_pattern(
        (string) ($_GET['renamer_pattern'] ?? $_POST['renamer_pattern'] ?? ''),
    );
    $report = $_GET['renamer_report'] ?? '';
    $result = is_string($report) && preg_match('/\A[a-f0-9]{32}\z/D', $report) === 1
        ? ['report_token' => $report] : null;
    \Gallery\Views\view_render_admin_media_renamer_fragment(
        admin_media_renamer_render_gallery_panel_html($gallery, $pattern, '', $result),
    );
}

/**
 * Render the gallery-level renamer panel HTML so normal and AJAX requests share one view.
 *
 * @param array{id:int,title?:string,folder_path?:string} $gallery Gallery row used to build the plan and panel.
 * @param string $pattern Pattern value.
 * @param string $notice Notice value.
 * @param ?array{report_token?:string,details?:list<array{gallery_id:int,gallery:string,image_id:int,old:string,new:string,status:string,notes:list<string>}>,...} $result Previous execution result with optional server-side detail identity.
 * @return string Text result for the caller.
 */
function admin_media_renamer_render_gallery_panel_html(
    array $gallery,
    string $pattern,
    string $notice = '',
    ?array $result = null,
): string {
    $galleryId = (int) ($gallery['id'] ?? 0);
    $pattern = media_renamer_normalize_pattern($pattern);

    ob_start();
    render_admin_media_renamer_pattern_preview_form($galleryId, $pattern);
    $patternFormHtml = (string) ob_get_clean();

    try {
        $plan = $result === null ? media_renamer_database_preview($galleryId, $pattern) : null;
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
    if ($plan !== null) {
        render_admin_media_renamer_plan_table([$plan]);
    }
    $planTableHtml = (string) ob_get_clean();
    $executionDetailsHtml = '';
    if ($result !== null) {
        ob_start();
        render_admin_media_renamer_execution_details(
            [], (string) ($result['report_token'] ?? ''),
        );
        $executionDetailsHtml = (string) ob_get_clean();
    }

    $summary = (array) ($plan['summary'] ?? []);
    $renameCount = (int) ($summary['rename'] ?? 0);
    ob_start();
    \Gallery\Views\view_render_admin_media_renamer_gallery_panel([
        'log_url' => url_for('admin_media_renamer'),
        'detail_url' => url_for('admin_media_renamer'),
        'notice' => $notice,
        'pattern_form_html' => $patternFormHtml,
        'error' => '',
        'plan_table_html' => $planTableHtml,
        'execution_details_html' => $executionDetailsHtml,
        'rename_count' => $renameCount,
        'rename_image_ids' => [],
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
 * @return void Renders the gallery-level pattern preview form.
 */
function render_admin_media_renamer_pattern_preview_form(
    int $galleryId,
    string $pattern,
): void {
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
 * @param list<array{id:int,title?:string,folder_path?:string,direct_image_count?:int,rename_candidate_count?:int,rename_warning_count?:int}> $galleryRows Gallery rows displayed in the scope selector.
 * @param string $selectedScope Selected scope value.
 * @param list<int> $selectedGalleryIds Gallery IDs selected for the current scope.
 * @param int $selectedSingleGalleryId Selected single gallery id identifier.
 * @param string $pattern Pattern value.
 * @param bool $hideEmptyGalleries Hide empty galleries value.
 * @param bool $hideGalleriesWithoutRenameCandidates Hide galleries without rename candidates value.
 * @param bool $renameAvailabilityChecked Rename availability checked value.
 * @param array<int,array{rename_count:int,warning_count:int}> $renameAvailability Counts keyed by gallery ID.
 * @return void Emits the site-wide gallery selection form.
 */
function render_admin_media_renamer_scope_form(
    array $galleryRows,
    string $selectedScope,
    array $selectedGalleryIds,
    int $selectedSingleGalleryId,
    string $pattern,
    bool $hideEmptyGalleries = true,
    bool $hideGalleriesWithoutRenameCandidates = false,
    bool $renameAvailabilityChecked = false,
    array $renameAvailability = [],
): void {
    $pendingTotal = array_sum(array_map(
        static fn(array $row): int => (int) (
            $row['rename_candidate_count'] ?? 0
        ),
        $galleryRows,
    ));
    $preparedRows = [];
    foreach ($galleryRows as $gallery) {
        $id = (int) ($gallery['id'] ?? 0);
        $gallery['selector_label'] = (string) (
            $gallery['folder_path']
            ?? $gallery['title']
            ?? '#' . $id
        );
        $gallery['edit_url'] =
            url_for('admin_edit_gallery', [
                'id' => $id,
                'tab' => 'admin-edit-renamer',
            ]) . '#admin-edit-renamer';
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
        'availability_payload' =>
            admin_media_renamer_encode_availability_payload(
                $renameAvailability,
            ),
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
 * @param list<int> $selectedGalleryIds Gallery IDs included in the reviewed plan.
 * @param int $selectedSingleGalleryId Selected single gallery id identifier.
 * @param string $pattern Pattern value.
 * @param bool $hideEmptyGalleries Hide empty galleries value.
 * @param bool $hideGalleriesWithoutRenameCandidates Hide galleries without rename candidates value.
 * @param bool $renameAvailabilityChecked Rename availability checked value.
 * @param array<int,array{rename_count:int,warning_count:int}> $renameAvailability Counts keyed by gallery ID.
 * @param ?int $renameCount Already computed analysis count; null performs a DB-only summary.
 * @return void Emits the reviewed rename-plan submission form.
 */
function render_admin_media_renamer_apply_form(
    string $selectedScope,
    array $selectedGalleryIds,
    int $selectedSingleGalleryId,
    string $pattern,
    bool $hideEmptyGalleries = true,
    bool $hideGalleriesWithoutRenameCandidates = false,
    bool $renameAvailabilityChecked = false,
    array $renameAvailability = [],
    ?int $renameCount = null,
): void {
    if ($renameCount === null) {
        $renameCount = (int) admin_media_renamer_aggregate_plans(
            media_renamer_plans_for_galleries($selectedGalleryIds, $pattern),
        )['rename'];
    }

    \Gallery\Views\view_render_admin_media_renamer_apply_form([
        'action_url' => url_for('admin_media_renamer'),
        'detail_url' => url_for('admin_media_renamer'),
        'csrf_html' => csrf_field(),
        'rename_count' => $renameCount,
        'candidate_image_ids' => [],
        'selected_scope' => $selectedScope,
        'selected_gallery_ids' => $selectedGalleryIds,
        'selected_single_gallery_id' => $selectedSingleGalleryId,
        'pattern' => $pattern,
        'hide_empty_galleries' => $hideEmptyGalleries,
        'hide_done_galleries' => $hideGalleriesWithoutRenameCandidates,
        'rename_availability_checked' => $renameAvailabilityChecked,
        'availability_payload' =>
            admin_media_renamer_encode_availability_payload(
                $renameAvailability,
            ),
    ]);
}

/**
 * Render dry-run plan tables grouped by gallery.
 *
 * @param list<array{gallery:array{id:int|string,title?:string,folder_path?:string,...},items:list<array{gallery_id:int,image_id:int,old_relative_path:string,new_relative_path:string,status:string,warnings:list<string>,can_rename:bool,...}>,summary:array{total:int,rename:int,already_matches:int,missing:int,collision:int,skipped:int,warnings:int},context_base:string,pattern:string,selected_image_ids:list<int>,preview_only?:bool,candidate_image_ids?:list<int>,next_offset?:?int}> $plans Database summary plans grouped for presentation, retaining compatibility with richer explicit rows.
 * @return void Renders the prepared plan table.
 */
function render_admin_media_renamer_plan_table(array $plans): void
{
    $preparedPlans = [];
    foreach ($plans as $plan) {
        $preparedPlan = $plan;
        $preparedItems = [];
        foreach ((array) ($plan['items'] ?? []) as $item) {
            $preparedItem = $item;
            $preparedItem['status_label'] = admin_media_renamer_status_label(
                (string) ($item['status'] ?? 'skipped'),
            );
            $preparedItem['notes_text'] = implode(' ', array_map(
                'strval',
                (array) ($item['warnings'] ?? []),
            ));
            $preparedItems[] = $preparedItem;
        }
        $preparedPlan['items'] = $preparedItems;
        $preparedPlan['detail_url'] = url_for('admin_media_renamer', [
            'renamer_action' => 'preview_details',
            'detail_gallery_id' => (int) $plan['gallery']['id'],
            'renamer_pattern' => (string) ($plan['pattern'] ?? ''),
        ]);
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
 * @param list<array{gallery_id:int,gallery:string,image_id:int,old:string,new:string,status:string,notes:list<string>}> $details Per-image execution outcomes to render.
 * @param string $reportToken Server-side report identity; details load only after expansion.
 * @return void Renders the prepared execution detail table.
 */
function render_admin_media_renamer_execution_details(array $details, string $reportToken = ''): void
{
    $galleries = [];
    $reportError = '';
    if ($reportToken !== '') {
        try {
            $galleries = array_values(media_renamer_report_load(
                $reportToken, admin_media_renamer_report_owner(),
            )['galleries']);
            foreach ($galleries as &$gallery) {
                unset($gallery['pages']);
                $gallery['detail_url'] = url_for('admin_media_renamer', [
                    'renamer_action' => 'report_details',
                    'detail_gallery_id' => $gallery['gallery_id'], 'report_token' => $reportToken,
                ]);
            }
            unset($gallery);
        } catch (Throwable) {
            // Detail-storage failure never invents a rollback of confirmed files.
            $galleries = [];
            $reportError = t('admin.media_renamer.report_unsaved',
                'Renaming completed, but some detail records could not be saved.');
        }
    }
    $preparedDetails = [];
    foreach ($details as $detail) {
        $preparedDetail = $detail;
        $preparedDetail['status_label'] = admin_media_renamer_status_label(
            (string) ($detail['status'] ?? 'skipped'),
        );
        $preparedDetail['notes_text'] = implode(' ', array_map(
            'strval',
            (array) ($detail['notes'] ?? []),
        ));
        $preparedDetails[] = $preparedDetail;
    }
    \Gallery\Views\view_render_admin_media_renamer_execution_details([
        'details' => $preparedDetails,
        'galleries' => $galleries,
        'report_token' => $reportToken,
        'report_error' => $reportError,
    ]);
}

/**
 * Return image ids that are planned for physical rename.
 *
 * @param list<array{items:list<array{image_id:int,status:string,can_rename:bool}>}> $plans Plans containing candidate image outcomes.
 * @return array<int> Structured result data for the caller.
 */
function admin_media_renamer_candidate_image_ids_from_plans(array $plans): array
{
    $ids = [];
    foreach ($plans as $plan) {
        foreach ((array) ($plan['items'] ?? []) as $item) {
            if (
                !empty($item['can_rename'])
                && (string) ($item['status'] ?? '') === 'rename'
            ) {
                $ids[] = (int) ($item['image_id'] ?? 0);
            }
        }
    }
    return array_values(array_unique(array_filter(
        $ids,
        static fn(int $id): bool => $id > 0,
    )));
}

/**
 * Admit the next plan-owned physical batch for an authorized Admin request.
 *
 * @param string $pattern Normalized pattern for this confirmation.
 * @param int|null $expectedGalleryId Authorized gallery in the editor, or null
 *     on the site-wide route where the plan binds the submitted gallery.
 * @return array{processed:int,result:array{gallery_id:int,renamed:int,already_matches:int,missing:int,skipped:int,collisions:int,derivatives_moved:int,derivatives_cleaned:int,derivative_failures:int,zip_archives_deleted:int,titles_updated:int,details:list<array{gallery_id:int,gallery:string,image_id:int,old:string,new:string,status:string,notes:list<string>}>,warnings:list<string>,failures:list<string>}} Confirmed processed count and executor evidence.
 * @throws MediaRenamerCandidatePlanUnavailable When the plan cannot be used.
 */
function admin_media_renamer_execute_posted_candidate_batch(
    string $pattern, ?int $expectedGalleryId = null,
): array {
    $token = $_POST['plan_token'] ?? '';
    $galleryId = filter_var($_POST['detail_gallery_id'] ?? null, FILTER_VALIDATE_INT);
    $offset = filter_var($_POST['batch_offset'] ?? null, FILTER_VALIDATE_INT);
    if (!is_string($token) || $token === '' || !is_int($galleryId) || $galleryId <= 0
        || !is_int($offset) || $offset < 0
        || ($expectedGalleryId !== null && $expectedGalleryId !== $galleryId)) {
        throw new MediaRenamerCandidatePlanUnavailable('Candidate batch is unavailable.');
    }
    $submitted = $_POST['batch_image_ids'] ?? [];
    $ids = [];
    if (is_array($submitted) && array_is_list($submitted)
        && count($submitted) <= MEDIA_RENAMER_PHYSICAL_BATCH_SIZE) {
        foreach ($submitted as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if ($id === false || $id <= 0) {
                break;
            }
            $ids[] = $id;
        }
    }
    if ($ids === [] || count($ids) !== count($submitted)) {
        admin_media_renamer_json_response(['ok' => false, 'error' => t(
            'admin.media_renamer.progress_failed', 'Rename request failed.',
        )], 400);
    }

    return ['processed' => count($ids), 'result' => media_renamer_execute_candidate_batch(
        admin_media_renamer_report_owner(), $token, $galleryId, $pattern, $offset, $ids,
    )];
}

/**
 * Trim large batch results before returning them through AJAX.
 *
 * @param array{galleries_requested?:int,galleries_processed?:int,renamed?:int,already_matches?:int,missing?:int,skipped?:int,collisions?:int,derivatives_moved?:int,derivatives_cleaned?:int,derivative_failures?:int,zip_archives_deleted?:int,titles_updated?:int,details?:list<array{gallery_id:int,gallery:string,image_id:int,old:string,new:string,status:string,notes:list<string>}>,warnings?:list<string>,failures?:list<string>} $result Aggregate execution fields to bound before returning them to the browser.
 * @param int $detailLimit Detail limit value.
 * @return array{galleries_requested?:int,galleries_processed?:int,renamed?:int,already_matches?:int,missing?:int,skipped?:int,collisions?:int,derivatives_moved?:int,derivatives_cleaned?:int,derivative_failures?:int,zip_archives_deleted?:int,titles_updated?:int,details?:list<array{gallery_id:int,gallery:string,image_id:int,old:string,new:string,status:string,notes:list<string>}>,warnings?:list<string>,failures?:list<string>} Result with detail, warning, and failure lists truncated to their response limits.
 */
function admin_media_renamer_bounded_result(
    array $result,
    int $detailLimit = 300,
): array {
    if (isset($result['details']) && is_array($result['details'])) {
        $result['details'] = array_slice($result['details'], 0, $detailLimit);
    }
    if (isset($result['warnings']) && is_array($result['warnings'])) {
        $result['warnings'] = array_slice(
            array_map('strval', $result['warnings']),
            0,
            50,
        );
    }
    if (isset($result['failures']) && is_array($result['failures'])) {
        $result['failures'] = array_slice(
            array_map('strval', $result['failures']),
            0,
            50,
        );
    }
    return $result;
}

/**
 * Decode the aggregate result produced by the AJAX batch runner.
 *
 * @return array{galleries_requested:int,galleries_processed:int,renamed:int,already_matches:int,missing:int,skipped:int,collisions:int,derivatives_moved:int,derivatives_cleaned:int,derivative_failures:int,zip_archives_deleted:int,titles_updated:int,details:list<array{gallery_id:int,gallery:string,image_id:int,old:string,new:string,status:string,notes:list<string>}>,warnings:list<string>,failures:list<string>,report_token?:string} Bounded aggregate counters and optional opaque detail-report identity.
 */
function admin_media_renamer_result_payload_from_post(): array
{
    $raw = (string) ($_POST['batch_result_payload'] ?? '');
    $decoded = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        return media_renamer_empty_execution_result(0);
    }

    $result = media_renamer_empty_execution_result(
        (int) ($decoded['galleries_requested'] ?? 0),
    );
    foreach ([
        'galleries_processed',
        'renamed',
        'already_matches',
        'missing',
        'skipped',
        'collisions',
        'derivatives_moved',
        'derivatives_cleaned',
        'derivative_failures',
        'zip_archives_deleted',
        'titles_updated',
    ] as $key) {
        $result[$key] = max(0, (int) ($decoded[$key] ?? 0));
    }
    $result['details'] = array_slice(
        (array) ($decoded['details'] ?? []),
        0,
        500,
    );
    $result['warnings'] = array_slice(
        array_map('strval', (array) ($decoded['warnings'] ?? [])),
        0,
        50,
    );
    $result['failures'] = array_slice(
        array_map('strval', (array) ($decoded['failures'] ?? [])),
        0,
        50,
    );
    $token = $decoded['report_token'] ?? '';
    $result['report_token'] = is_string($token)
        && preg_match('/\A[a-f0-9]{32}\z/D', $token) === 1 ? $token : '';
    // New clients transmit counters and the report identity only. Historical
    // file details remain bounded for compatibility, never eagerly rendered.
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
        'already_matches' => t(
            'admin.media_renamer.status_already_matches',
            'Already matches',
        ),
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
    return in_array($scope, ['all', 'single', 'selected'], true)
        ? $scope
        : 'selected';
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
function admin_media_renamer_gallery_ids_from_post(
    string $scope,
    bool $hideEmptyGalleries = false,
    bool $hideGalleriesWithoutRenameCandidates = false,
    string $pattern = '',
): array {
    if ($scope === 'all') {
        $galleryIds = media_renamer_all_gallery_ids($hideEmptyGalleries);
    } elseif ($scope === 'single') {
        $galleryIds = media_renamer_existing_gallery_ids([
            (int) ($_POST['single_gallery_id'] ?? 0),
        ]);
    } else {
        $galleryIds = media_renamer_existing_gallery_ids(
            (array) ($_POST['gallery_ids'] ?? []),
        );
    }

    if ($hideGalleriesWithoutRenameCandidates) {
        $galleryIds = media_renamer_gallery_ids_with_pending_renames(
            $galleryIds,
            $pattern,
        );
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

    return (string) json_encode(
        $availability,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
}

/**
 * Keep only gallery ids that still have at least one pending rename candidate.
 *
 * @param array<int|string> $galleryIds Gallery ids value.
 * @param array<int,array<string,int>> $availability Availability value.
 * @return array<int> Structured result data for the caller.
 */
function admin_media_renamer_filter_gallery_ids_by_availability(
    array $galleryIds,
    array $availability,
): array {
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
    if (
        $token !== ''
        && hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)
    ) {
        return true;
    }

    if (admin_wants_json()) {
        admin_media_renamer_log_event(
            'warning',
            'media_renamer.csrf_failed',
            'Media renamer AJAX request failed CSRF validation.',
            [
                'action' => (string) (
                    $_POST['renamer_action']
                    ?? $_POST['action']
                    ?? ''
                ),
                'request' => admin_media_renamer_request_log_context(),
            ],
            ['category' => 'security', 'severity' => 'warning'],
        );
        admin_media_renamer_json_response([
            'ok' => false,
            'error' => t(
                'admin.media_renamer.csrf_failed',
                'Security token expired or invalid. Reload the admin page and try again.',
            ),
        ], 400);
        return false;
    }

    verify_csrf();
    return true;
}

/**
 * Handle a browser-side JSON parsing or non-JSON response diagnostic report.
 *
 * @return void Logs bounded request diagnostics and emits a successful JSON acknowledgement.
 */
function admin_media_renamer_handle_client_error(): void
{
    $context = [
        'message' => substr((string) ($_POST['message'] ?? ''), 0, 500),
        'status' => (int) ($_POST['status'] ?? 0),
        'status_text' => substr((string) ($_POST['status_text'] ?? ''), 0, 120),
        'content_type' => substr(
            (string) ($_POST['content_type'] ?? ''),
            0,
            160,
        ),
        'response_url' => substr(
            (string) ($_POST['response_url'] ?? ''),
            0,
            500,
        ),
        'request_url' => substr((string) ($_POST['request_url'] ?? ''), 0, 500),
        'redirected' => !empty($_POST['redirected']),
        'snippet' => substr((string) ($_POST['snippet'] ?? ''), 0, 1500),
        'workspace' => substr((string) ($_POST['workspace'] ?? ''), 0, 40),
        'current_url' => substr((string) ($_POST['current_url'] ?? ''), 0, 500),
        'form_action' => substr((string) ($_POST['form_action'] ?? ''), 0, 500),
        'request' => admin_media_renamer_request_log_context(),
    ];
    admin_media_renamer_log_event(
        'error',
        'media_renamer.ajax_non_json_response',
        'Media renamer AJAX request returned a non-JSON or invalid JSON response.',
        $context,
        [
            'category' => 'media',
            'severity' => 'error',
        ],
    );
    admin_media_renamer_json_response(['ok' => true]);
}

/**
 * Emit a JSON response and stop the current media-renamer request.
 *
 * @param array{ok:bool,error?:string,code?:string,processed?:int,
 *     plan_token?:string,gallery_id?:int,candidate_ids?:list<int>,
 *     candidate_sequences?:list<int>,next_offset?:int|null,detail_html?:string,
 *     availability?:array<int,array{rename_count:int,warning_count:int}>,
 *     result?:array{galleries_requested?:int,galleries_processed?:int,renamed:int,
 *     already_matches:int,missing:int,skipped:int,collisions:int,
 *     derivatives_moved:int,derivatives_cleaned:int,derivative_failures:int,
 *     zip_archives_deleted:int,titles_updated:int,report_token?:string,
 *     details:list<array{gallery_id:int,gallery:string,image_id:int,old:string,
 *     new:string,status:string,notes:list<string>}>,warnings:list<string>,
 *     failures:list<string>},...} $payload Supported media-renamer data plus
 *     the existing canonical Admin mutation envelope on physical success.
 * @param int $statusCode HTTP response status code.
 * @return never Emits the JSON response and terminates the request.
 */
function admin_media_renamer_json_response(
    array $payload,
    int $statusCode = 200,
): never {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

/**
 * Log a media-renamer exception with bounded context.
 *
 * @param string $eventKey Event key value.
 * @param string $message Message value.
 * @param Throwable $exception Exception value.
 * @param array{selected_scope?:string,selected_gallery_ids?:list<int>,batch_image_ids?:list<int>,pattern?:string,action?:string,batched?:bool,message?:string,status?:int,status_text?:string,content_type?:string,response_url?:string,request_url?:string,redirected?:bool,snippet?:string,workspace?:string,current_url?:string,form_action?:string,request?:array{method:string,uri:string,accept:string,requested_with:string,referer:string},result?:array{galleries_requested?:int,galleries_processed?:int,renamed?:int,already_matches?:int,missing?:int,skipped?:int,collisions?:int,derivatives_moved?:int,derivatives_cleaned?:int,derivative_failures?:int,zip_archives_deleted?:int,titles_updated?:int,warnings?:list<string>,failures?:list<string>}} $context Diagnostic context fields supplied by the workflow.
 * @return void Records a bounded exception diagnostic.
 */
function admin_media_renamer_log_exception(
    string $eventKey,
    string $message,
    Throwable $exception,
    array $context = [],
): void {
    $context['exception'] = [
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => admin_media_renamer_compact_trace($exception),
    ];
    $context['request'] = admin_media_renamer_request_log_context();
    admin_media_renamer_log_event('error', $eventKey, $message, $context, [
        'category' => 'media',
        'severity' => 'error',
    ]);
}

/**
 * Write to Admin Logs when available and always mirror diagnostics to the PHP error log.
 *
 * @param string $level Level value.
 * @param string $eventKey Event key value.
 * @param string $message Message value.
 * @param array{selected_scope?:string,selected_gallery_ids?:list<int>,batch_image_ids?:list<int>,pattern?:string,action?:string,batched?:bool,message?:string,status?:int,status_text?:string,content_type?:string,response_url?:string,request_url?:string,redirected?:bool,snippet?:string,workspace?:string,current_url?:string,form_action?:string,request?:array{method:string,uri:string,accept:string,requested_with:string,referer:string},exception?:array{class:string,message:string,file:string,line:int,trace:list<array{file:string,line:int,function:string}>},result?:array{galleries_requested?:int,galleries_processed?:int,renamed?:int,already_matches?:int,missing?:int,skipped?:int,collisions?:int,derivatives_moved?:int,derivatives_cleaned?:int,derivative_failures?:int,zip_archives_deleted?:int,titles_updated?:int,warnings?:list<string>,failures?:list<string>}} $context Diagnostic event context fields.
 * @param array{category?:string,severity?:string} $options Optional Admin log category and severity.
 * @return void Writes a bounded Admin event and local diagnostic.
 */
function admin_media_renamer_log_event(
    string $level,
    string $eventKey,
    string $message,
    array $context = [],
    array $options = [],
): void {
    $written = admin_media_renamer_write_admin_log_direct(
        $level,
        $eventKey,
        $message,
        $context,
        $options,
    );
    if (!$written && function_exists('Gallery\\Services\\admin_log_event')) {
        admin_log_event($level, $eventKey, $message, $context, $options);
    }
    $encodedContext = $context
        ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : '';
    error_log(
        '[PHP Gallery] '
            . $eventKey
            . ': '
            . $message
            . ($encodedContext !== '' ? ' ' . $encodedContext : ''),
    );
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
 * @param array{selected_scope?:string,selected_gallery_ids?:list<int>,batch_image_ids?:list<int>,pattern?:string,action?:string,batched?:bool,message?:string,status?:int,status_text?:string,content_type?:string,response_url?:string,request_url?:string,redirected?:bool,snippet?:string,workspace?:string,current_url?:string,form_action?:string,request?:array{method:string,uri:string,accept:string,requested_with:string,referer:string},exception?:array{class:string,message:string,file:string,line:int,trace:list<array{file:string,line:int,function:string}>},result?:array{galleries_requested?:int,galleries_processed?:int,renamed?:int,already_matches?:int,missing?:int,skipped?:int,collisions?:int,derivatives_moved?:int,derivatives_cleaned?:int,derivative_failures?:int,zip_archives_deleted?:int,titles_updated?:int,warnings?:list<string>,failures?:list<string>}} $context Diagnostic event context fields.
 * @param array{category?:string,severity?:string} $options Optional Admin log category and severity.
 * @return bool True when the condition matches.
 */
function admin_media_renamer_write_admin_log_direct(
    string $level,
    string $eventKey,
    string $message,
    array $context = [],
    array $options = [],
): bool {
    try {
        $user = function_exists('Gallery\\Core\\current_user')
            ? current_user()
            : null;
        return admin_diagnostic_log_write(
            $level,
            $eventKey,
            $message,
            $context,
            $options,
            [
                'user_id' => $user ? (int) $user['id'] : null,
                'request_id' => function_exists(
                    'Gallery\\Services\\telemetry_request_id',
                )
                    ? telemetry_request_id()
                    : null,
                'route_name' => function_exists(
                    'Gallery\\Services\\admin_log_current_route_name',
                )
                    ? admin_log_current_route_name()
                    : '',
                'http_method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
                'is_ajax' => admin_wants_json(),
            ],
        );
    } catch (Throwable $exception) {
        error_log(
            '[PHP Gallery] media_renamer.direct_admin_log_failed: '
                . $exception->getMessage(),
        );
        return false;
    }
}

/**
 * Return a bounded exception trace suitable for the admin log context JSON.
 *
 * @param Throwable $exception Exception value.
 * @return list<array{file:string,line:int,function:string}> Trace frames limited to eight entries.
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
 * @return array{method:string,uri:string,accept:string,requested_with:string,referer:string} Bounded request metadata for diagnostic events.
 */
function admin_media_renamer_request_log_context(): array
{
    return [
        'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
        'uri' => substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 500),
        'accept' => substr((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 0, 220),
        'requested_with' => substr(
            (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''),
            0,
            120,
        ),
        'referer' => substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 500),
    ];
}

/**
 * Remove bulky per-image detail rows before writing aggregate results to logs.
 *
 * @param array{galleries_requested?:int,galleries_processed?:int,renamed?:int,already_matches?:int,missing?:int,skipped?:int,collisions?:int,derivatives_moved?:int,derivatives_cleaned?:int,derivative_failures?:int,zip_archives_deleted?:int,titles_updated?:int,details?:list<array{gallery_id:int,gallery:string,image_id:int,old:string,new:string,status:string,notes:list<string>}>,warnings?:list<string>,failures?:list<string>} $result Execution result fields whose large details list is excluded from logs.
 * @return array{galleries_requested?:int,galleries_processed?:int,renamed?:int,already_matches?:int,missing?:int,skipped?:int,collisions?:int,derivatives_moved?:int,derivatives_cleaned?:int,derivative_failures?:int,zip_archives_deleted?:int,titles_updated?:int,warnings?:list<string>,failures?:list<string>} Aggregate result with bounded warning and failure lists and no per-image details.
 */
function admin_media_renamer_loggable_result(array $result): array
{
    unset($result['details']);
    if (isset($result['warnings']) && is_array($result['warnings'])) {
        $result['warnings'] = array_slice(
            array_map('strval', $result['warnings']),
            0,
            20,
        );
    }
    if (isset($result['failures']) && is_array($result['failures'])) {
        $result['failures'] = array_slice(
            array_map('strval', $result['failures']),
            0,
            20,
        );
    }
    return $result;
}

/**
 * Build a visible result notice after a physical rename run.
 *
 * @param array{galleries_requested?:int,galleries_processed?:int,renamed?:int,already_matches?:int,missing?:int,skipped?:int,collisions?:int,derivatives_moved?:int,derivatives_cleaned?:int,derivative_failures?:int,zip_archives_deleted?:int,titles_updated?:int,warnings?:list<string>,failures?:list<string>} $result Aggregate execution counters used to create the visible notice.
 * @return string Visible notice with separate counts for matching names, name
 *     conflicts and other skipped rows, followed by any warnings or failures.
 */
function admin_media_renamer_result_notice(array $result): string
{
    $message = t(
        'admin.media_renamer.result_notice',
        'Processed {galleries} gallery/galleries. Renamed {renamed} file(s). '
            . 'Already matching the pattern ({matching}). Filename conflicts ({collisions}). '
            . 'Preserved {moved} thumbnails, removed {cleaned} stale derivative '
            . 'cache file(s), skipped {skipped} row(s), saw {missing} missing '
            . 'file(s), updated {titles} derived title(s), and removed '
            . '{archives} stale ZIP archive row(s).',
        [
            'galleries' => (string) (int) ($result['galleries_processed'] ?? 0),
            'renamed' => (string) (int) ($result['renamed'] ?? 0),
            'matching' => (string) (int) ($result['already_matches'] ?? 0),
            'collisions' => (string) (int) ($result['collisions'] ?? 0),
            'moved' => (string) (int) ($result['derivatives_moved'] ?? 0),
            'cleaned' => (string) (int) ($result['derivatives_cleaned'] ?? 0),
            'skipped' => (string) (int) ($result['skipped'] ?? 0),
            'missing' => (string) (int) ($result['missing'] ?? 0),
            'archives' => (string) (int) ($result['zip_archives_deleted'] ?? 0),
            'titles' => (string) (int) ($result['titles_updated'] ?? 0),
        ],
    );

    $derivativeFailures = (int) ($result['derivative_failures'] ?? 0);
    if ($derivativeFailures > 0) {
        $message .= ' '
        . t(
            'admin.media_renamer.result_derivative_warnings',
            'Generated derivative warnings: {count}.',
            ['count' => (string) $derivativeFailures],
        );
    }

    $warnings = (array) ($result['warnings'] ?? []);
    if ($warnings) {
        $message .= ' '
        . t(
            'admin.media_renamer.result_warnings',
            'Warnings: {warnings}',
            ['warnings' => implode(' | ', array_map('strval', array_slice(
                $warnings,
                0,
                8,
            )))],
        );
    }

    $failures = (array) ($result['failures'] ?? []);
    if ($failures) {
        $message .= ' '
        . t(
            'admin.media_renamer.result_failures',
            'Failures: {failures}',
            ['failures' => implode(' | ', array_map('strval', $failures))],
        );
    }
    return $message;
}

/**
 * Return the Admin Logs severity for a completed media rename run.
 *
 * @param array{failures?:list<string>,warnings?:list<string>,derivative_failures?:int,collisions?:int,missing?:int,...} $result Completed rename counters and diagnostics.
 * @return string Warning for unresolved outcomes, otherwise info.
 */
function admin_media_renamer_result_log_severity(array $result): string
{
    if (!empty($result['failures'])) {
        return 'warning';
    }
    if (
        !empty($result['warnings'])
        || (int) ($result['derivative_failures'] ?? 0) > 0
        || (int) ($result['collisions'] ?? 0) > 0
        || (int) ($result['missing'] ?? 0) > 0
    ) {
        return 'warning';
    }
    return 'info';
}

/**
 * Bind an optional report to the authenticated account and current PHP session.
 * @return string Non-secret SHA-256 owner binding; never a bearer or session ID.
 */
function admin_media_renamer_report_owner(): string
{
    $user = current_user();
    if (!is_array($user) || (int) ($user['id'] ?? 0) <= 0 || session_id() === '') {
        throw new \RuntimeException('Rename report owner is unavailable.');
    }
    return hash('sha256', (string) $user['id'] . "\0" . session_id());
}

/**
 * Refuse a lost or stale candidate plan before starting a physical write.
 * @return never Emits a recoverable conflict requiring fresh analysis.
 */
function admin_media_renamer_unavailable_plan(): never
{
    admin_media_renamer_json_response([
        'ok' => false,
        'code' => 'rename_plan_unavailable',
        'error' => t('admin.media_renamer.plan_unavailable',
            'The rename plan is unavailable or the gallery changed. Preview the rename plan again before applying.'),
    ], 409);
}

/**
 * Retain actual outcomes server-side while returning compact batch counters.
 * @param array{gallery_id?:int,galleries_requested?:int,galleries_processed?:int,renamed?:int,already_matches?:int,missing?:int,skipped?:int,collisions?:int,derivatives_moved?:int,derivatives_cleaned?:int,derivative_failures?:int,zip_archives_deleted?:int,titles_updated?:int,details?:list<array{gallery_id:int,gallery:string,image_id:int,old:string,new:string,status:string,notes:list<string>}>,warnings?:list<string>,failures?:list<string>} $result Confirmed executor result before any response trimming.
 * @return array{gallery_id?:int,galleries_requested?:int,galleries_processed?:int,renamed?:int,already_matches?:int,missing?:int,skipped?:int,collisions?:int,derivatives_moved?:int,derivatives_cleaned?:int,derivative_failures?:int,zip_archives_deleted?:int,titles_updated?:int,details:list<array{gallery_id:int,gallery:string,image_id:int,old:string,new:string,status:string,notes:list<string>}>,warnings?:list<string>,failures?:list<string>,report_token:string} Bounded counters and report identity; no eager file list.
 */
function admin_media_renamer_attach_report(array $result): array
{
    $token = is_string($_POST['report_token'] ?? null) ? $_POST['report_token'] : '';
    if (!empty($result['details'])) {
        try {
            $token = media_renamer_report_append(admin_media_renamer_report_owner(),
                $token, $result['details']);
        } catch (Throwable) {
            // Reporting is optional after a confirmed physical mutation. Keep
            // success/progress truthful and never replay the completed batch.
            $result['warnings'][] = t('admin.media_renamer.report_unsaved',
                'Renaming completed, but some detail records could not be saved.');
        }
    }
    $result['details'] = [];
    $result['report_token'] = $token;
    return admin_media_renamer_bounded_result($result, 0);
}

/**
 * Serve one explicit read without entering the physical rename executor.
 * @param string $action One of preview_details, candidate_page or report_details.
 * @param string $pattern Already normalized filename pattern for a DB preview.
 * @return void Emits a bounded JSON response or a safe unavailable response.
 */
function admin_media_renamer_handle_detail_read(string $action, string $pattern): void
{
    header('Cache-Control: private, no-store');
    $input = request_method() === 'GET' ? $_GET : $_POST;
    $galleryId = filter_var($input['detail_gallery_id'] ?? null, FILTER_VALIDATE_INT);
    $offset = filter_var($input['detail_offset'] ?? 0, FILTER_VALIDATE_INT);
    if ($galleryId === false || $galleryId === null || $galleryId <= 0
        || $offset === false || $offset === null || $offset < 0) {
        admin_media_renamer_json_response(['ok' => false, 'error' => t(
            'admin.media_renamer.detail_failed', 'File details could not be loaded. Try again.',
        )], 400);
        return;
    }
    try {
        if ($action === 'candidate_page') {
            $token = $input['plan_token'] ?? '';
            if (empty($input['confirm_media_rename']) || !is_string($token)) {
                admin_media_renamer_unavailable_plan();
            }
            $page = media_renamer_confirmed_candidate_page(
                admin_media_renamer_report_owner(), $token, $galleryId, $pattern, $offset,
            );
            admin_media_renamer_json_response(['ok' => true,
                'plan_token' => $page['plan_token'], 'gallery_id' => $galleryId,
                'candidate_ids' => $page['candidate_image_ids'],
                'candidate_sequences' => $page['candidate_sequences'],
                'next_offset' => $page['next_offset']]);
            return;
        }
        if ($action === 'report_details') {
            $token = is_string($input['report_token'] ?? null) ? $input['report_token'] : '';
            $page = media_renamer_report_page(admin_media_renamer_report_owner(),
                $token, $galleryId, $offset);
            $rows = $page['rows'];
        } else {
            $page = media_renamer_database_preview($galleryId, $pattern, $offset,
                MEDIA_RENAMER_DETAIL_PAGE_SIZE);
            $rows = array_map(static fn(array $item): array => [
                'gallery_id' => $galleryId, 'gallery' => '',
                'image_id' => $item['image_id'], 'old' => $item['old_relative_path'],
                'new' => $item['new_relative_path'], 'status' => $item['status'],
                'notes' => $item['warnings'],
            ], $page['items']);
        }
        ob_start();
        \Gallery\Views\view_render_admin_media_renamer_file_page([
            'rows' => array_map(static fn(array $row): array => [
                'old' => $row['old'], 'new' => $row['new'],
                'status_label' => admin_media_renamer_status_label($row['status']),
                'notes_text' => implode(' ', $row['notes']),
            ], $rows),
        ]);
        $html = (string) ob_get_clean();
        if (!admin_wants_json()) {
            ob_start();
            \Gallery\Views\view_render_admin_media_renamer_detail_navigation([
                'return_url' => url_for('admin_media_renamer'),
                'next_url' => $page['next_offset'] === null ? '' : url_for('admin_media_renamer', [
                    'renamer_action' => $action, 'detail_gallery_id' => $galleryId,
                    'detail_offset' => $page['next_offset'], 'renamer_pattern' => $pattern,
                    'report_token' => (string) ($token ?? ''),
                ]),
            ]);
            $navigation = (string) ob_get_clean();
            \Gallery\Views\view_render_admin_media_renamer_page([
                'page_title' => t('admin.media_renamer.page_title', 'Media renamer'),
                'admin_url' => url_for('admin'), 'workspace_html' => $html . $navigation,
            ]);
            return;
        }
        admin_media_renamer_json_response(['ok' => true, 'detail_html' => $html,
            'next_offset' => $page['next_offset']]);
    } catch (MediaRenamerCandidatePlanUnavailable) {
        admin_media_renamer_unavailable_plan();
    } catch (Throwable) {
        admin_media_renamer_json_response(['ok' => false, 'error' => t(
            'admin.media_renamer.detail_failed', 'File details could not be loaded. Try again.',
        )], 404);
    }
}
