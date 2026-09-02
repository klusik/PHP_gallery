<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_duplicate_photos.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles Admin duplicate scanning, review-ledger actions, and explicit result deletion.
 *
 * Responsibilities:
 *   - Validate administrator access and the selected gallery
 *   - Start and continue bounded duplicate-detector session jobs
 *   - Return existing-style JSON responses for AJAX batches
 *   - Render the reusable full-page and side-panel detector report
 *   - Keep browser-supplied scope flags out of continuation requests
 *   - Validate and persist per-administrator review-ledger actions
 *   - Delegate explicit result deletion to the normal gallery image-deletion service
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
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use InvalidArgumentException;
use Throwable;
use function Gallery\Core\admin_mutation_descriptor;
use function Gallery\Core\admin_mutation_error_envelope;
use function Gallery\Core\admin_mutation_panel_metadata;
use function Gallery\Core\admin_mutation_postcondition;
use function Gallery\Core\admin_mutation_public_gallery_context;
use function Gallery\Core\admin_mutation_success_envelope;
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
use function Gallery\Services\duplicate_photo_detector_job_allows_gallery;
use function Gallery\Services\duplicate_photo_detector_job_contains_image;
use function Gallery\Services\duplicate_photo_detector_job_contains_pair;
use function Gallery\Services\duplicate_photo_detector_process_job;
use function Gallery\Services\duplicate_photo_detector_remove_image_from_job;
use function Gallery\Services\duplicate_photo_detector_read_job;
use function Gallery\Services\duplicate_photo_detector_resolve_scope;
use function Gallery\Services\duplicate_photo_detector_result_page;
use function Gallery\Services\duplicate_photo_detector_start_job;
use function Gallery\Services\duplicate_photo_ledger_add_gallery;
use function Gallery\Services\duplicate_photo_ledger_add_pair;
use function Gallery\Services\duplicate_photo_ledger_clear;
use function Gallery\Services\duplicate_photo_ledger_schema_ready;
use function Gallery\Services\duplicate_photo_ledger_schema_status;
use function Gallery\Services\schema_inspection_is_unknown;
use function Gallery\Services\duplicate_photo_ledger_snapshot;
use function Gallery\Services\delete_gallery_images;
use function Gallery\Services\find_gallery;
use function Gallery\Services\find_image;
use function Gallery\Services\admin_log_event;
use function Gallery\Services\t;
use function Gallery\Views\view_render_admin_duplicate_photo_detector;

/**
 * Send one duplicate detector JSON response using the Admin AJAX convention.
 *
 * @param bool $ok Whether the request succeeded.
 * @param string $message Human-readable response message.
 * @param array<string,mixed> $payload Additional response values.
 */
function admin_duplicate_photos_json_response(bool $ok, string $message, array $payload = []): void
{
    if (!$ok && !array_key_exists('contexts', $payload)) {
        // Expected AJAX failures use the same canonical error shape as the
        // persistent mutation paths. Existing detector callers can continue to
        // read `error`/`message`, while the side-panel pipeline never receives
        // an ad-hoc failure object for authentication, CSRF, or validation.
        $payload = array_merge(
            admin_mutation_error_envelope(
                $message,
                'duplicate_photo_detector.request_failed',
                admin_duplicate_photos_post_mutation_descriptor()
            ),
            $payload
        );
    }
    header('Content-Type: application/json');
    echo json_encode(array_merge([
        'ok' => $ok,
        'message' => $message,
    ], $payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Build the reusable detector HTML fragment for AJAX completion responses.
 *
 * @param array<string,mixed> $gallery Selected gallery row.
 * @param array<string,mixed>|null $job Detector job state.
 * @param int $page One-based result page.
 * @param int $adminUserId Authenticated administrator id used for ledger filtering.
 * @return string Rendered detector fragment.
 */
function admin_duplicate_photos_panel_html(array $gallery, ?array $job, int $page = 1, int $adminUserId = 0): string
{
    $ledger = duplicate_photo_ledger_snapshot($adminUserId);
    $resultPage = null;
    if (is_array($job) && (string) ($job['status'] ?? '') === 'complete') {
        $resultPage = duplicate_photo_detector_result_page($job, $page, $ledger);
    }

    ob_start();
    view_render_admin_duplicate_photo_detector($gallery, $job, $resultPage, $ledger);
    return (string) ob_get_clean();
}

/**
 * Return the authenticated administrator id for ledger ownership.
 *
 * @return int Positive current administrator id.
 */
function admin_duplicate_photos_current_admin_id(): int
{
    $user = current_user();
    return is_array($user) ? max(0, (int) ($user['id'] ?? 0)) : 0;
}

/**
 * Return the selected gallery persisted in one detector job.
 *
 * @param array<string,mixed> $job Detector job state.
 * @return array<string,mixed>|null Current gallery row, or null when it disappeared.
 */
function admin_duplicate_photos_gallery_from_job(array $job): ?array
{
    $galleryId = (int) ($job['gallery_id'] ?? 0);
    return $galleryId > 0 ? find_gallery($galleryId, true) : null;
}

/**
 * Build the normal-page detector URL for one selected gallery and optional job.
 *
 * @param int $galleryId Selected gallery identifier.
 * @param string $jobToken Optional server-side job token.
 * @param int $page Optional one-based result page.
 * @return string Relative application URL.
 */
function admin_duplicate_photos_url(int $galleryId, string $jobToken = '', int $page = 1): string
{
    $parameters = ['gallery_id' => $galleryId];
    if ($jobToken !== '') {
        $parameters['job_token'] = $jobToken;
    }
    if ($page > 1) {
        $parameters['results_page'] = $page;
    }
    return url_for('admin_duplicate_photos', $parameters);
}

/**
 * Return a translated missing-gallery message.
 *
 * @param int $galleryId Requested gallery identifier.
 * @return string User-facing error text.
 */
function admin_duplicate_photos_missing_gallery_message(int $galleryId): string
{
    return t('admin.duplicate_photos.error_gallery_missing', 'Gallery #{id} no longer exists or is not available.', [
        'id' => (string) max(0, $galleryId),
    ]);
}

/**
 * Return a canonical mutation descriptor for the persistent detector POST action.
 *
 * Scan start/continuation requests only mutate bounded session job state, so they
 * intentionally return null. Ledger and delete requests describe the durable CMS
 * mutation without trusting a browser-supplied gallery id for ignore-gallery.
 *
 * @return array{type:string,entity:string,action:string,entity_ids:array<int,int>}|null
 */
function admin_duplicate_photos_post_mutation_descriptor(): ?array
{
    $action = (string) ($_POST['action'] ?? 'start');
    if ($action === 'delete') {
        return admin_mutation_descriptor(
            'image.duplicate_delete',
            'image',
            'delete',
            [(int) ($_POST['image_id'] ?? 0)]
        );
    }
    if ($action === 'ignore_pair') {
        return admin_mutation_descriptor(
            'duplicate_photo_ledger.ignore_pair',
            'image',
            'ignore_pair',
            [
                (int) ($_POST['left_image_id'] ?? 0),
                (int) ($_POST['right_image_id'] ?? 0),
            ]
        );
    }
    if ($action === 'clear_ledger') {
        return admin_mutation_descriptor(
            'duplicate_photo_ledger.clear',
            'duplicate_photo_ledger',
            'clear',
            []
        );
    }
    if ($action === 'ignore_gallery') {
        return admin_mutation_descriptor(
            'duplicate_photo_ledger.ignore_gallery',
            'gallery',
            'ignore_gallery',
            []
        );
    }

    return null;
}

/**
 * Return whether the current detector POST contains the active Admin CSRF token.
 *
 * Enhanced JSON requests must validate CSRF before the classic verify_csrf()
 * helper can emit plain text. Direct-page POSTs keep the existing helper and
 * redirect behavior unchanged.
 *
 * @return bool True when the submitted CSRF token matches the active session.
 */
function admin_duplicate_photos_csrf_valid(): bool
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    return $token !== '' && hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token);
}

/**
 * Handle a detector POST action and answer as JSON or normal redirect.
 *
 * @param bool $csrfVerified Whether the JSON entrypoint already validated CSRF.
 */
function admin_duplicate_photos_handle_post(bool $csrfVerified = false): void
{
    if (!$csrfVerified) {
        verify_csrf();
    }
    $wantsJson = \Gallery\Core\admin_wants_json();
    $action = (string) ($_POST['action'] ?? 'start');
    $adminUserId = admin_duplicate_photos_current_admin_id();

    if (in_array($action, ['ignore_pair', 'ignore_gallery', 'clear_ledger'], true)) {
        $token = (string) ($_POST['job_token'] ?? '');
        $job = $token !== '' ? duplicate_photo_detector_read_job($token) : null;
        $page = max(1, (int) ($_POST['results_page'] ?? 1));
        $galleryId = $job !== null
            ? (int) ($job['gallery_id'] ?? 0)
            : (int) ($_POST['gallery_id'] ?? $_POST['id'] ?? 0);
        $gallery = $job !== null
            ? admin_duplicate_photos_gallery_from_job($job)
            : ($galleryId > 0 ? find_gallery($galleryId, true) : null);

        if ($gallery === null) {
            $message = admin_duplicate_photos_missing_gallery_message($galleryId);
            if ($wantsJson) {
                admin_duplicate_photos_json_response(false, $message, ['error' => $message]);
                return;
            }
            flash_message('admin_notice', $message);
            redirect_to(url_for('admin'));
        }
        if ($adminUserId <= 0) {
            $message = t('admin.duplicate_photos.ledger_admin_required', 'The duplicate ledger requires an authenticated administrator.');
            if ($wantsJson) {
                admin_duplicate_photos_json_response(false, $message, ['error' => $message]);
                return;
            }
            flash_message('admin_notice', $message);
            redirect_to(admin_duplicate_photos_url((int) $gallery['id'], $token, $page));
        }
        $ledgerSchemaStatus = duplicate_photo_ledger_schema_status();
        if (!duplicate_photo_ledger_schema_ready()) {
            $ledgerSchemaUnknown = schema_inspection_is_unknown($ledgerSchemaStatus);
            $message = $ledgerSchemaUnknown
                ? t('admin.duplicate_photos.ledger_schema_unknown', 'The duplicate review ledger is temporarily unavailable because its database schema could not be verified. No ledger change was made.')
                : t('admin.duplicate_photos.ledger_migration_required', 'Run database migrations before using the duplicate review ledger.');
            if ($wantsJson) {
                http_response_code($ledgerSchemaUnknown ? 503 : 409);
                admin_duplicate_photos_json_response(false, $message, ['error' => $message]);
                return;
            }
            flash_message('admin_notice', $message);
            redirect_to(admin_duplicate_photos_url((int) $gallery['id'], $token, $page));
        }

        try {
            if ($action === 'clear_ledger') {
                $cleared = duplicate_photo_ledger_clear($adminUserId);
                $message = t('admin.duplicate_photos.ledger_cleared', 'Duplicate review ledger cleared.');
                admin_log_event('info', 'image.duplicate_detector_ledger_cleared', 'Admin cleared the Duplicate Photo Detector review ledger.', [
                    'selected_gallery_id' => (int) ($gallery['id'] ?? 0),
                    'pair_rules_deleted' => (int) ($cleared['pairs'] ?? 0),
                    'gallery_rules_deleted' => (int) ($cleared['galleries'] ?? 0),
                ], ['category' => 'other', 'severity' => 'notice']);
            } elseif ($action === 'ignore_pair') {
                if ($job === null || (string) ($job['status'] ?? '') !== 'complete') {
                    throw new InvalidArgumentException(t('admin.duplicate_photos.ledger_pair_unavailable', 'This duplicate pair is no longer available. Start a new scan.'));
                }
                $leftImageId = (int) ($_POST['left_image_id'] ?? 0);
                $rightImageId = (int) ($_POST['right_image_id'] ?? 0);
                if (!duplicate_photo_detector_job_contains_pair($job, $leftImageId, $rightImageId)) {
                    throw new InvalidArgumentException(t('admin.duplicate_photos.ledger_pair_unavailable', 'This duplicate pair is no longer available. Start a new scan.'));
                }

                $leftImage = find_image($leftImageId);
                $rightImage = find_image($rightImageId);
                if ($leftImage === null || $rightImage === null) {
                    throw new InvalidArgumentException(t('admin.duplicate_photos.ledger_pair_unavailable', 'This duplicate pair is no longer available. Start a new scan.'));
                }
                if (!duplicate_photo_detector_job_allows_gallery($job, (int) ($leftImage['gallery_id'] ?? 0))
                    || !duplicate_photo_detector_job_allows_gallery($job, (int) ($rightImage['gallery_id'] ?? 0))) {
                    throw new InvalidArgumentException(t('admin.duplicate_photos.ledger_scope_changed', 'This duplicate finding is no longer inside the detector scope. Start a new scan.'));
                }

                duplicate_photo_ledger_add_pair($adminUserId, $leftImageId, $rightImageId);
                $message = t('admin.duplicate_photos.ledger_pair_added', 'This pair will be ignored in future duplicate searches.');
                admin_log_event('info', 'image.duplicate_detector_pair_ignored', 'Admin added a reviewed duplicate pair to the Duplicate Photo Detector ledger.', [
                    'selected_gallery_id' => (int) ($gallery['id'] ?? 0),
                    'left_image_id' => $leftImageId,
                    'right_image_id' => $rightImageId,
                ], ['category' => 'other', 'severity' => 'notice']);
            } else {
                if ($job === null || (string) ($job['status'] ?? '') !== 'complete') {
                    throw new InvalidArgumentException(t('admin.duplicate_photos.ledger_gallery_unavailable', 'This gallery result is no longer available. Start a new scan.'));
                }
                $imageId = (int) ($_POST['image_id'] ?? 0);
                if (!duplicate_photo_detector_job_contains_image($job, $imageId)) {
                    throw new InvalidArgumentException(t('admin.duplicate_photos.ledger_gallery_unavailable', 'This gallery result is no longer available. Start a new scan.'));
                }

                $image = find_image($imageId);
                if ($image === null) {
                    throw new InvalidArgumentException(t('admin.duplicate_photos.ledger_gallery_unavailable', 'This gallery result is no longer available. Start a new scan.'));
                }
                $ignoredGalleryId = (int) ($image['gallery_id'] ?? 0);
                if (!duplicate_photo_detector_job_allows_gallery($job, $ignoredGalleryId)) {
                    throw new InvalidArgumentException(t('admin.duplicate_photos.ledger_scope_changed', 'This duplicate finding is no longer inside the detector scope. Start a new scan.'));
                }

                duplicate_photo_ledger_add_gallery($adminUserId, $ignoredGalleryId);
                $message = t('admin.duplicate_photos.ledger_gallery_added', 'This gallery will be ignored in future duplicate searches. Subgalleries remain independent.');
                admin_log_event('info', 'image.duplicate_detector_gallery_ignored', 'Admin added one exact gallery to the Duplicate Photo Detector ledger.', [
                    'selected_gallery_id' => (int) ($gallery['id'] ?? 0),
                    'ignored_gallery_id' => $ignoredGalleryId,
                    'source_image_id' => $imageId,
                ], ['category' => 'other', 'severity' => 'notice']);
            }
        } catch (Throwable $exception) {
            $message = t('admin.duplicate_photos.ledger_update_failed', 'Duplicate ledger update failed: {error}', ['error' => $exception->getMessage()]);
            if ($wantsJson) {
                admin_duplicate_photos_json_response(false, $message, ['error' => $message]);
                return;
            }
            flash_message('admin_notice', $message);
            redirect_to(admin_duplicate_photos_url((int) $gallery['id'], $token, $page));
        }

        if ($wantsJson) {
            $panelUrl = admin_duplicate_photos_url((int) $gallery['id'], $token, $page);
            if ($action === 'ignore_pair') {
                $mutationDescriptor = admin_mutation_descriptor(
                    'duplicate_photo_ledger.ignore_pair',
                    'image',
                    'ignore_pair',
                    [
                        (int) ($_POST['left_image_id'] ?? 0),
                        (int) ($_POST['right_image_id'] ?? 0),
                    ]
                );
            } elseif ($action === 'ignore_gallery') {
                $mutationDescriptor = admin_mutation_descriptor(
                    'duplicate_photo_ledger.ignore_gallery',
                    'gallery',
                    'ignore_gallery',
                    [$ignoredGalleryId ?? 0]
                );
            } else {
                $mutationDescriptor = admin_mutation_descriptor(
                    'duplicate_photo_ledger.clear',
                    'duplicate_photo_ledger',
                    'clear',
                    []
                );
            }
            $mutationEnvelope = admin_mutation_success_envelope(
                $message,
                $mutationDescriptor,
                admin_mutation_panel_metadata('duplicate-photo-detector', $panelUrl, true),
                [],
                ['redirect_url' => $panelUrl]
            );
            admin_duplicate_photos_json_response(true, $message, array_merge($mutationEnvelope, [
                'job_token' => $token,
                'panel_html' => admin_duplicate_photos_panel_html($gallery, $job, $page, $adminUserId),
            ]));
            return;
        }

        flash_message('admin_notice', $message);
        redirect_to(admin_duplicate_photos_url((int) $gallery['id'], $token, $page));
    }

    if ($action === 'delete') {
        $token = (string) ($_POST['job_token'] ?? '');
        $job = duplicate_photo_detector_read_job($token);
        if ($job === null) {
            $message = t('admin.duplicate_photos.error_job_missing', 'The duplicate detector session expired. Start a new scan.');
            if ($wantsJson) {
                admin_duplicate_photos_json_response(false, $message, ['error' => $message]);
                return;
            }
            flash_message('admin_notice', $message);
            redirect_to(url_for('admin'));
        }

        $gallery = admin_duplicate_photos_gallery_from_job($job);
        if ($gallery === null) {
            $message = admin_duplicate_photos_missing_gallery_message((int) ($job['gallery_id'] ?? 0));
            if ($wantsJson) {
                admin_duplicate_photos_json_response(false, $message, ['error' => $message]);
                return;
            }
            flash_message('admin_notice', $message);
            redirect_to(url_for('admin'));
        }

        $imageId = (int) ($_POST['image_id'] ?? 0);
        $page = max(1, (int) ($_POST['results_page'] ?? 1));
        if ((string) ($job['status'] ?? '') !== 'complete' || !duplicate_photo_detector_job_contains_image($job, $imageId)) {
            $message = t('admin.duplicate_photos.delete_unavailable', 'This photo is no longer available in the duplicate detector results.');
            if ($wantsJson) {
                admin_duplicate_photos_json_response(false, $message, ['error' => $message]);
                return;
            }
            flash_message('admin_notice', $message);
            redirect_to(admin_duplicate_photos_url((int) $gallery['id'], $token, $page));
        }

        $image = find_image($imageId);
        if ($image === null) {
            $updatedJob = duplicate_photo_detector_remove_image_from_job($token, $imageId);
            $message = t('admin.duplicate_photos.delete_already_removed', 'The photo was already removed. The duplicate results were refreshed.');
            if ($wantsJson) {
                admin_duplicate_photos_json_response(true, $message, [
                    'deleted_image_id' => $imageId,
                    'deleted_gallery_id' => 0,
                    'job_token' => $token,
                    'panel_html' => admin_duplicate_photos_panel_html($gallery, $updatedJob, $page, $adminUserId),
                ]);
                return;
            }
            flash_message('admin_notice', $message);
            redirect_to(admin_duplicate_photos_url((int) $gallery['id'], $token, $page));
        }

        $imageGalleryId = (int) ($image['gallery_id'] ?? 0);
        if (!duplicate_photo_detector_job_allows_gallery($job, $imageGalleryId)) {
            $message = t('admin.duplicate_photos.delete_scope_changed', 'The photo is no longer inside this detector scope. Start a new scan before deleting it here.');
            if ($wantsJson) {
                admin_duplicate_photos_json_response(false, $message, ['error' => $message]);
                return;
            }
            flash_message('admin_notice', $message);
            redirect_to(admin_duplicate_photos_url((int) $gallery['id'], $token, $page));
        }

        try {
            $deleted = delete_gallery_images($imageGalleryId, [$imageId]);
            if ((int) ($deleted['deleted'] ?? 0) <= 0) {
                throw new InvalidArgumentException(t('admin.duplicate_photos.delete_unavailable', 'This photo is no longer available in the duplicate detector results.'));
            }

            $updatedJob = duplicate_photo_detector_remove_image_from_job($token, $imageId);
            if ($updatedJob === null) {
                $updatedJob = $job;
            }

            $displayName = trim((string) ($image['filename'] ?? $image['relative_path'] ?? ''));
            if ($displayName === '') {
                $displayName = '#' . $imageId;
            }
            $message = t('admin.duplicate_photos.delete_success', 'Deleted {file}.', ['file' => $displayName]);
            admin_log_event('warning', 'image.duplicate_detector_deleted', 'Admin deleted an image from Duplicate Photo Detector results.', [
                'selected_gallery_id' => (int) ($gallery['id'] ?? 0),
                'image_gallery_id' => $imageGalleryId,
                'image_id' => $imageId,
                'requested' => (int) ($deleted['requested'] ?? 0),
                'deleted' => (int) ($deleted['deleted'] ?? 0),
                'files_deleted' => (int) ($deleted['files_deleted'] ?? 0),
                'derivatives_deleted' => (int) ($deleted['derivatives_deleted'] ?? 0),
                'missing_files' => (int) ($deleted['missing_files'] ?? 0),
            ], ['category' => 'other', 'severity' => 'warning']);
        } catch (Throwable $exception) {
            $message = t('admin.duplicate_photos.delete_failed', 'Photo delete failed: {error}', ['error' => $exception->getMessage()]);
            if ($wantsJson) {
                admin_duplicate_photos_json_response(false, $message, ['error' => $message]);
                return;
            }
            flash_message('admin_notice', $message);
            redirect_to(admin_duplicate_photos_url((int) $gallery['id'], $token, $page));
        }

        if ($wantsJson) {
            $panelUrl = admin_duplicate_photos_url((int) $gallery['id'], $token, $page);
            $imageGallery = find_gallery($imageGalleryId, true);
            $mutationEnvelope = admin_mutation_success_envelope(
                $message,
                admin_mutation_descriptor('image.duplicate_delete', 'image', 'delete', [$imageId]),
                admin_mutation_panel_metadata('duplicate-photo-detector', $panelUrl, true),
                [admin_mutation_public_gallery_context(
                    $imageGalleryId,
                    gallery_public_url(is_array($imageGallery) ? $imageGallery : $gallery),
                    admin_mutation_postcondition('image_absent', ['image_id' => $imageId])
                )],
                ['redirect_url' => $panelUrl]
            );
            admin_duplicate_photos_json_response(true, $message, array_merge($mutationEnvelope, [
                'deleted_image_id' => $imageId,
                'deleted_gallery_id' => $imageGalleryId,
                'job_token' => $token,
                'panel_html' => admin_duplicate_photos_panel_html($gallery, $updatedJob, $page, $adminUserId),
            ]));
            return;
        }

        flash_message('admin_notice', $message);
        redirect_to(admin_duplicate_photos_url((int) $gallery['id'], $token, $page));
    }

    if ($action === 'step') {
        $token = (string) ($_POST['job_token'] ?? '');
        $job = duplicate_photo_detector_read_job($token);
        if ($job === null) {
            $message = t('admin.duplicate_photos.error_job_missing', 'The duplicate detector session expired. Start a new scan.');
            if ($wantsJson) {
                admin_duplicate_photos_json_response(false, $message, ['error' => $message]);
                return;
            }
            flash_message('admin_notice', $message);
            redirect_to(url_for('admin'));
        }

        $gallery = admin_duplicate_photos_gallery_from_job($job);
        if ($gallery === null) {
            $message = admin_duplicate_photos_missing_gallery_message((int) ($job['gallery_id'] ?? 0));
            if ($wantsJson) {
                admin_duplicate_photos_json_response(false, $message, ['error' => $message]);
                return;
            }
            flash_message('admin_notice', $message);
            redirect_to(url_for('admin'));
        }

        $batchSize = (int) ($_POST['batch_size'] ?? 0);
        try {
            $state = duplicate_photo_detector_process_job($token, $batchSize > 0 ? $batchSize : 200);
        } catch (Throwable $exception) {
            $message = t('admin.duplicate_photos.error_scan_failed', 'Duplicate scan failed: {error}', ['error' => $exception->getMessage()]);
            if ($wantsJson) {
                admin_duplicate_photos_json_response(false, $message, ['error' => $message]);
                return;
            }
            flash_message('admin_notice', $message);
            redirect_to(admin_duplicate_photos_url((int) $gallery['id'], $token));
        }

        if ($wantsJson) {
            $payload = $state;
            if (!empty($state['done'])) {
                $completedJob = duplicate_photo_detector_read_job((string) ($state['job_token'] ?? $token));
                $payload['panel_html'] = admin_duplicate_photos_panel_html($gallery, $completedJob, 1, $adminUserId);
            }
            admin_duplicate_photos_json_response(true, t('admin.duplicate_photos.scan_progress_message', 'Duplicate scan progress updated.'), $payload);
            return;
        }

        redirect_to(admin_duplicate_photos_url((int) $gallery['id'], (string) ($state['job_token'] ?? $token)));
    }

    if ($action !== 'start') {
        $message = t('admin.duplicate_photos.error_invalid_action', 'Unknown duplicate detector action.');
        if ($wantsJson) {
            admin_duplicate_photos_json_response(false, $message, ['error' => $message]);
            return;
        }
        flash_message('admin_notice', $message);
        redirect_to(url_for('admin'));
    }

    $galleryId = (int) ($_POST['gallery_id'] ?? $_POST['id'] ?? 0);
    $gallery = $galleryId > 0 ? find_gallery($galleryId, true) : null;
    try {
        $scope = duplicate_photo_detector_resolve_scope(
            $gallery,
            isset($_POST['search_all']) && (string) $_POST['search_all'] === '1',
            true
        );
    } catch (InvalidArgumentException) {
        $message = admin_duplicate_photos_missing_gallery_message($galleryId);
        if ($wantsJson) {
            admin_duplicate_photos_json_response(false, $message, ['error' => $message]);
            return;
        }
        flash_message('admin_notice', $message);
        redirect_to(url_for('admin'));
    }

    try {
        $state = duplicate_photo_detector_start_job($scope);
    } catch (Throwable $exception) {
        $message = t('admin.duplicate_photos.error_scan_failed', 'Duplicate scan failed: {error}', ['error' => $exception->getMessage()]);
        if ($wantsJson) {
            admin_duplicate_photos_json_response(false, $message, ['error' => $message]);
            return;
        }
        flash_message('admin_notice', $message);
        redirect_to(admin_duplicate_photos_url((int) $scope['gallery_id']));
    }

    if ($wantsJson) {
        $payload = $state;
        if (!empty($state['done']) && is_array($gallery)) {
            $completedJob = duplicate_photo_detector_read_job((string) ($state['job_token'] ?? ''));
            $payload['panel_html'] = admin_duplicate_photos_panel_html($gallery, $completedJob, 1, $adminUserId);
        }
        admin_duplicate_photos_json_response(true, t('admin.duplicate_photos.scan_started', 'Duplicate scan started.'), $payload);
        return;
    }

    redirect_to(admin_duplicate_photos_url((int) $scope['gallery_id'], (string) ($state['job_token'] ?? '')));
}

/**
 * Handle the Admin duplicate photo detector page and side-panel fragment source.
 */
function cms_admin_duplicate_photos(): void
{
    // JSON POSTs validate authentication and CSRF without triggering the
    // classic login redirect or plain-text CSRF abort. This preserves the
    // detector's side-panel JSON contract even when the Admin session expires.
    $isJsonPost = request_method() === 'POST' && \Gallery\Core\admin_wants_json();
    if ($isJsonPost) {
        $user = current_user();
        if (!$user || (string) ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            admin_duplicate_photos_json_response(false, t('auth.admin_required', 'Admin access is required.'), admin_mutation_error_envelope(
                t('auth.admin_required', 'Admin access is required.'),
                'auth.admin_required',
                admin_duplicate_photos_post_mutation_descriptor()
            ));
            return;
        }
        if (!admin_duplicate_photos_csrf_valid()) {
            http_response_code(400);
            admin_duplicate_photos_json_response(false, t('security.invalid_csrf', 'Invalid CSRF token.'), admin_mutation_error_envelope(
                t('security.invalid_csrf', 'Invalid CSRF token.'),
                'security.invalid_csrf',
                admin_duplicate_photos_post_mutation_descriptor()
            ));
            return;
        }
        admin_duplicate_photos_handle_post(true);
        return;
    }

    require_admin();

    if (request_method() === 'POST') {
        admin_duplicate_photos_handle_post();
        return;
    }

    $jobToken = (string) ($_GET['job_token'] ?? '');
    $job = $jobToken !== '' ? duplicate_photo_detector_read_job($jobToken) : null;
    $galleryId = $job !== null ? (int) ($job['gallery_id'] ?? 0) : (int) ($_GET['gallery_id'] ?? $_GET['id'] ?? 0);
    $gallery = $galleryId > 0 ? find_gallery($galleryId, true) : null;
    if ($gallery === null) {
        flash_message('admin_notice', admin_duplicate_photos_missing_gallery_message($galleryId));
        redirect_to(url_for('admin'));
    }

    if ($jobToken !== '' && $job === null) {
        flash_message('admin_notice', t('admin.duplicate_photos.error_job_missing', 'The duplicate detector session expired. Start a new scan.'));
    }

    $page = max(1, (int) ($_GET['results_page'] ?? 1));
    $adminUserId = admin_duplicate_photos_current_admin_id();
    $ledger = duplicate_photo_ledger_snapshot($adminUserId);
    $resultPage = $job !== null && (string) ($job['status'] ?? '') === 'complete'
        ? duplicate_photo_detector_result_page($job, $page, $ledger)
        : null;

    render_header(t('admin.duplicate_photos.page_title', 'Duplicate Photo Detector'));
    view_render_admin_duplicate_photo_detector($gallery, $job, $resultPage, $ledger);
    render_footer();
}
