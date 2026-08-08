<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_duplicate_photos.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles the read-only Admin duplicate photo detector workflow.
 *
 * Responsibilities:
 *   - Validate administrator access and the selected gallery
 *   - Start and continue bounded duplicate-detector session jobs
 *   - Return existing-style JSON responses for AJAX batches
 *   - Render the reusable full-page and side-panel detector report
 *   - Keep browser-supplied scope flags out of continuation requests
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
use function Gallery\Core\current_user;
use function Gallery\Core\flash_message;
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
 * Handle a detector POST action and answer as JSON or normal redirect.
 */
function admin_duplicate_photos_handle_post(): void
{
    verify_csrf();
    $wantsJson = admin_wants_json();
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
        if (!duplicate_photo_ledger_schema_ready()) {
            $message = t('admin.duplicate_photos.ledger_migration_required', 'Run database migrations before using the duplicate review ledger.');
            if ($wantsJson) {
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
            admin_duplicate_photos_json_response(true, $message, [
                'job_token' => $token,
                'panel_html' => admin_duplicate_photos_panel_html($gallery, $job, $page, $adminUserId),
            ]);
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
            admin_duplicate_photos_json_response(true, $message, [
                'deleted_image_id' => $imageId,
                'deleted_gallery_id' => $imageGalleryId,
                'job_token' => $token,
                'panel_html' => admin_duplicate_photos_panel_html($gallery, $updatedJob, $page, $adminUserId),
            ]);
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
