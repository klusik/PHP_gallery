<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/upload_automation.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles API-key based upload automation requests and admin key management.
 *
 * Responsibilities:
 *   - Accept authenticated upload automation POST requests
 *   - Reuse the existing gallery upload pipeline
 *   - Render a small gallery-scoped API-key management panel
 *   - Allow admins to rotate or revoke watcher API keys safely
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
 *   2026-05-16
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use Gallery\Services\MutationSchemaUnavailableException;
use Gallery\Services\PresentationSchemaUnavailableException;
use JsonException;
use RuntimeException;
use Throwable;
use const Gallery\Services\AI_IMAGE_ANALYSIS_DEFAULT_LEASE_SECONDS;
use function Gallery\Core\absolute_public_url;
use function Gallery\Core\csrf_field;
use function Gallery\Core\current_user;
use function Gallery\Core\e;
use function Gallery\Core\flash_message;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\redirect_to;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\sanitize_login_return_target;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\admin_render_profile_start;
use function Gallery\Services\ai_image_analysis_claim_next_job;
use function Gallery\Services\ai_image_analysis_claimed_asset;
use function Gallery\Services\ai_image_analysis_complete_failure;
use function Gallery\Services\ai_image_analysis_complete_success;
use function Gallery\Services\ai_image_analysis_limit_text;
use function Gallery\Services\ai_image_analysis_normalize_label;
use function Gallery\Services\ai_image_analysis_normalize_lease_seconds;
use function Gallery\Services\ai_image_analysis_normalize_worker_id;
use function Gallery\Services\ai_image_analysis_record_heartbeat;
use function Gallery\Services\presentation_ai_image_analysis_schema_status;
use function Gallery\Services\presentation_schema_log_degraded;
use function Gallery\Services\schema_inspection_error_code;
use function Gallery\Services\create_gallery_upload_automation_token;
use function Gallery\Services\create_image_thumbnails_result;
use function Gallery\Services\find_gallery;
use function Gallery\Services\find_image;
use function Gallery\Services\find_upload_automation_token;
use function Gallery\Services\gallery_upload_automation_tokens;
use function Gallery\Services\gallery_upload_entries;
use function Gallery\Services\mark_upload_automation_token_used;
use function Gallery\Services\revoke_gallery_upload_automation_token;
use function Gallery\Services\store_uploaded_gallery_images;
use function Gallery\Services\t;
use function Gallery\Services\upload_automation_apply_sim_camera_metadata;
use function Gallery\Services\upload_automation_bool;
use function Gallery\Services\upload_automation_client_thumbnail_entries;
use function Gallery\Services\upload_automation_gallery_inventory_response;
use function Gallery\Services\upload_automation_image_client_ids;
use function Gallery\Services\upload_automation_install_client_thumbnails;
use function Gallery\Services\upload_automation_inventory_candidates;
use function Gallery\Services\upload_automation_request_token;
use function Gallery\Services\upload_automation_schema_ready;
use function Gallery\Services\upload_automation_schema_status;
use function Gallery\Services\schema_inspection_is_missing;
use function Gallery\Services\schema_inspection_is_unknown;
use function Gallery\Services\upload_automation_sim_camera_metadata;
use function Gallery\Services\upload_automation_tokens_for_manager;
use function Gallery\Services\upload_automation_uploaded_files;
use function Gallery\Services\upload_automation_with_gallery_lock;
use function Gallery\Services\admin_log_event;

/**
 * Upload automation controller model.
 *
 * The public upload route is authenticated by a gallery-scoped API key. Browser
 * admin sessions are used only for generating and revoking those keys.
 */

/**
 * Send a JSON response for the upload automation endpoint.
 *
 * @param array $payload Payload value.
 * @param int $status Status value.
 */
function upload_automation_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
}


/**
 * Decode the current upload automation JSON request body when present.
 *
 * Multipart uploads and form-encoded revoke requests keep using $_POST and
 * $_FILES. The inventory handshake uses application/json because it sends file
 * descriptors, not image bytes. Invalid JSON returns an empty payload so the
 * normal action validation can return a controlled JSON error instead of a PHP
 * warning.
 *
 * @return array<string,mixed> Decoded JSON object, or an empty array for non-JSON requests.
 */
function upload_automation_json_request_payload(): array
{
    // $contentType stores the request media type reported by the web server.
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    if (!str_contains($contentType, 'application/json')) {
        return [];
    }

    // $rawBody stores the JSON request body for inventory handshakes.
    $rawBody = file_get_contents('php://input');
    if (!is_string($rawBody) || trim($rawBody) === '') {
        return [];
    }

    try {
        $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [];
    }

    return is_array($payload) ? $payload : [];
}

/**
 * Resolve the upload automation action from form data or JSON payload.
 *
 * @param array<string,mixed> $jsonPayload Decoded JSON object for inventory calls.
 * @return string Normalized action name.
 */
function upload_automation_request_action(array $jsonPayload): string
{
    // $postAction stores the legacy multipart or form-encoded action value.
    $postAction = trim((string) ($_POST['action'] ?? ''));
    if ($postAction !== '') {
        return $postAction;
    }

    // $jsonAction stores the action value used by JSON inventory probes.
    $jsonAction = trim((string) ($jsonPayload['action'] ?? ''));
    return $jsonAction !== '' ? $jsonAction : 'upload';
}

/**
 * Dispatch API-key authenticated AI image-analysis queue actions.
 *
 * @param string $action Normalized action name from form data or JSON.
 * @param int $galleryId Gallery scope authorized by the current API key.
 * @param array<string,mixed> $gallery Gallery row for the authorized scope.
 * @param array<string,mixed> $tokenRow Upload automation token row.
 * @param array<string,mixed> $jsonPayload Decoded JSON request body.
 */
function upload_automation_handle_ai_action(string $action, int $galleryId, array $gallery, array $tokenRow, array $jsonPayload): void
{
    $schemaStatus = presentation_ai_image_analysis_schema_status();
    if (!schema_inspection_is_available($schemaStatus)) {
        if (schema_inspection_is_unknown($schemaStatus)) {
            presentation_schema_log_degraded($schemaStatus, 'ai_worker_request');
            upload_automation_json(['ok' => false, 'error' => 'AI image analysis storage could not be verified. Check Admin System Health and try again.'], 503);
        } else {
            upload_automation_json(['ok' => false, 'error' => 'AI image analysis queue is not installed. Run pending database migrations first.'], 409);
        }
        return;
    }

    try {
        if ($action === 'ai_next_job') {
            upload_automation_handle_ai_next_job($galleryId, $tokenRow, $jsonPayload);
            return;
        }
        if ($action === 'ai_heartbeat') {
            upload_automation_handle_ai_heartbeat($galleryId, $tokenRow, $jsonPayload);
            return;
        }
        if ($action === 'ai_complete') {
            upload_automation_handle_ai_complete($galleryId, $tokenRow, $jsonPayload);
            return;
        }
        if ($action === 'ai_asset') {
            upload_automation_stream_ai_asset($galleryId);
            return;
        }

        upload_automation_json(['ok' => false, 'error' => 'Unknown AI analysis action.'], 400);
    } catch (PresentationSchemaUnavailableException $exception) {
        upload_automation_json(['ok' => false, 'error' => 'AI image analysis storage could not be verified. Check Admin System Health and try again.'], 503);
    } catch (Throwable $exception) {
        admin_log_event('error', 'upload_automation.ai_action_failed', 'AI image-analysis automation request failed.', [
            'token_id' => (int) ($tokenRow['id'] ?? 0),
            'gallery_id' => $galleryId,
            'action' => $action,
            'error_code' => schema_inspection_error_code($exception),
        ]);
        upload_automation_json(['ok' => false, 'error' => 'AI image analysis request could not be completed. Check the server log for the request context.'], 422);
    }
}

/**
 * Claim and return one AI image-analysis job for a worker.
 *
 * @param int $galleryId Gallery identifier.
 * @param array<string,mixed> $tokenRow Upload automation token row.
 * @param array<string,mixed> $jsonPayload Decoded JSON request body.
 */
function upload_automation_handle_ai_next_job(int $galleryId, array $tokenRow, array $jsonPayload): void
{
    $workerId = ai_image_analysis_normalize_worker_id((string) ($jsonPayload['worker_id'] ?? ''));
    $modelName = ai_image_analysis_normalize_label((string) ($jsonPayload['model_name'] ?? ''), 'local-image-metadata');
    $modelVersion = ai_image_analysis_normalize_label((string) ($jsonPayload['model_version'] ?? ''), '1');
    $leaseSeconds = ai_image_analysis_normalize_lease_seconds((int) ($jsonPayload['lease_seconds'] ?? AI_IMAGE_ANALYSIS_DEFAULT_LEASE_SECONDS));

    $job = ai_image_analysis_claim_next_job($galleryId, $workerId, $modelName, $modelVersion, $leaseSeconds);
    mark_upload_automation_token_used((int) $tokenRow['id']);

    if ($job === null) {
        upload_automation_json([
            'ok' => true,
            'job' => null,
            'poll_after_seconds' => 60,
            'message' => 'No AI image-analysis job is currently available.',
        ]);
        return;
    }

    admin_log_event('info', 'upload_automation.ai_job_claimed', 'AI image-analysis job claimed by companion app worker.', [
        'token_id' => (int) ($tokenRow['id'] ?? 0),
        'gallery_id' => $galleryId,
        'job_id' => (int) ($job['job_id'] ?? 0),
        'image_id' => (int) ($job['image']['id'] ?? 0),
        'worker_id' => $workerId,
        'model_name' => $modelName,
        'model_version' => $modelVersion,
    ]);

    upload_automation_json([
        'ok' => true,
        'job' => $job,
        'asset_action' => 'ai_asset',
    ]);
}

/**
 * Extend one active AI job lease for a worker.
 *
 * @param int $galleryId Gallery identifier.
 * @param array<string,mixed> $tokenRow Upload automation token row.
 * @param array<string,mixed> $jsonPayload Decoded JSON request body.
 */
function upload_automation_handle_ai_heartbeat(int $galleryId, array $tokenRow, array $jsonPayload): void
{
    $jobId = (int) ($jsonPayload['job_id'] ?? 0);
    $claimToken = (string) ($jsonPayload['claim_token'] ?? '');
    $leaseSeconds = ai_image_analysis_normalize_lease_seconds((int) ($jsonPayload['lease_seconds'] ?? AI_IMAGE_ANALYSIS_DEFAULT_LEASE_SECONDS));
    $progressPercent = max(0, min(99, (int) ($jsonPayload['progress_percent'] ?? 0)));
    $message = (string) ($jsonPayload['message'] ?? 'Worker heartbeat.');

    if (!ai_image_analysis_record_heartbeat($galleryId, $jobId, $claimToken, $leaseSeconds, $progressPercent, $message)) {
        upload_automation_json(['ok' => false, 'error' => 'AI job claim is invalid or expired.'], 409);
        return;
    }

    mark_upload_automation_token_used((int) $tokenRow['id']);
    upload_automation_json([
        'ok' => true,
        'job_id' => $jobId,
        'message' => 'Heartbeat accepted.',
    ]);
}

/**
 * Complete or fail one active AI image-analysis job.
 *
 * @param int $galleryId Gallery identifier.
 * @param array<string,mixed> $tokenRow Upload automation token row.
 * @param array<string,mixed> $jsonPayload Decoded JSON request body.
 */
function upload_automation_handle_ai_complete(int $galleryId, array $tokenRow, array $jsonPayload): void
{
    $jobId = (int) ($jsonPayload['job_id'] ?? 0);
    $claimToken = (string) ($jsonPayload['claim_token'] ?? '');
    $status = (string) ($jsonPayload['status'] ?? 'succeeded');

    if ($status === 'succeeded') {
        $metadata = $jsonPayload['metadata'] ?? [];
        if (!is_array($metadata)) {
            throw new RuntimeException('AI metadata payload must be a JSON object.');
        }
        $searchableText = (string) ($jsonPayload['searchable_text'] ?? '');
        if (!ai_image_analysis_complete_success($galleryId, $jobId, $claimToken, $metadata, $searchableText)) {
            upload_automation_json(['ok' => false, 'error' => 'AI job claim is invalid or expired.'], 409);
            return;
        }

        mark_upload_automation_token_used((int) $tokenRow['id']);
        admin_log_event('info', 'upload_automation.ai_job_completed', 'AI image-analysis job completed by companion app worker.', [
            'token_id' => (int) ($tokenRow['id'] ?? 0),
            'gallery_id' => $galleryId,
            'job_id' => $jobId,
        ]);
        upload_automation_json([
            'ok' => true,
            'job_id' => $jobId,
            'status' => 'succeeded',
            'message' => 'AI metadata stored.',
        ]);
        return;
    }

    $errorMessage = (string) ($jsonPayload['error'] ?? 'Worker failed without a detailed error.');
    if (!ai_image_analysis_complete_failure($galleryId, $jobId, $claimToken, $errorMessage)) {
        upload_automation_json(['ok' => false, 'error' => 'AI job claim is invalid or expired.'], 409);
        return;
    }

    mark_upload_automation_token_used((int) $tokenRow['id']);
    admin_log_event('warning', 'upload_automation.ai_job_failed', 'AI image-analysis job failed and may be retried later.', [
        'token_id' => (int) ($tokenRow['id'] ?? 0),
        'gallery_id' => $galleryId,
        'job_id' => $jobId,
        'error' => ai_image_analysis_limit_text($errorMessage, 500),
    ]);
    upload_automation_json([
        'ok' => true,
        'job_id' => $jobId,
        'status' => 'failed',
        'message' => 'AI job failure recorded.',
    ]);
}

/**
 * Stream the image asset for one active claimed AI job.
 *
 * @param int $galleryId Gallery identifier.
 */
function upload_automation_stream_ai_asset(int $galleryId): void
{
    $jobId = (int) ($_POST['job_id'] ?? 0);
    $claimToken = (string) ($_POST['claim_token'] ?? '');
    $asset = ai_image_analysis_claimed_asset($galleryId, $jobId, $claimToken);
    if ($asset === null) {
        upload_automation_json(['ok' => false, 'error' => 'AI job asset is not available or the claim expired.'], 409);
        return;
    }

    $path = (string) $asset['path'];
    $mime = (string) $asset['mime'];
    $filename = basename((string) $asset['filename']);
    header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . addcslashes($filename !== '' ? $filename : 'image.bin', "\"\\") . '"');
    header('Cache-Control: no-store, private');
    header('Content-Length: ' . (int) filesize($path));
    readfile($path);
}

/**
 * Handle POST uploads from the Windows folder watcher app.
 */
function cms_upload_automation_upload(): void
{
    if (request_method() !== 'POST') {
        upload_automation_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
        return;
    }

    $automationSchemaStatus = upload_automation_schema_status();
    if (!upload_automation_schema_ready()) {
        $error = schema_inspection_is_missing($automationSchemaStatus)
            ? 'Upload automation is not installed. Run pending database migrations first.'
            : 'Upload automation is temporarily unavailable because its database schema could not be verified.';
        upload_automation_json(['ok' => false, 'error' => $error], 503);
        return;
    }

    // $token stores the raw API key supplied by the watcher app.
    $token = upload_automation_request_token();
    // $tokenRow stores the database row that authorizes exactly one target gallery.
    $tokenRow = find_upload_automation_token($token);
    if (!$tokenRow) {
        admin_log_event('warning', 'upload_automation.unauthorized', 'Upload automation request used a missing or invalid API key.', [
            'remote_addr_present' => isset($_SERVER['REMOTE_ADDR']) && (string) $_SERVER['REMOTE_ADDR'] !== '',
            'user_agent_present' => isset($_SERVER['HTTP_USER_AGENT']) && (string) $_SERVER['HTTP_USER_AGENT'] !== '',
        ]);
        upload_automation_json(['ok' => false, 'error' => 'Invalid or revoked API key.'], 401);
        return;
    }

    // $galleryId stores the gallery scope embedded in the API key.
    $galleryId = (int) ($tokenRow['gallery_id'] ?? 0);
    // $gallery stores the target gallery. The Python app does not choose this independently.
    $gallery = find_gallery($galleryId, true) ?: find_gallery($galleryId);
    if (!$gallery) {
        upload_automation_json(['ok' => false, 'error' => 'The gallery assigned to this API key no longer exists.'], 404);
        return;
    }

    // $jsonPayload stores optional metadata-only API commands such as inventory checks.
    $jsonPayload = upload_automation_json_request_payload();
    // $action stores the requested automation command. Revoke is allowed when the request is authenticated by the current API key.
    $action = upload_automation_request_action($jsonPayload);

    if ($action === 'inventory') {
        // $candidates stores local files the active side wants to compare with this gallery.
        $candidates = upload_automation_inventory_candidates($jsonPayload);
        mark_upload_automation_token_used((int) $tokenRow['id']);
        upload_automation_json(upload_automation_gallery_inventory_response($galleryId, $gallery, $candidates));
        return;
    }

    if ($action === 'revoke') {
        $tokenId = (int) ($tokenRow['id'] ?? 0);
        if ($tokenId <= 0 || !revoke_gallery_upload_automation_token($galleryId, $tokenId)) {
            admin_log_event('error', 'upload_automation.revoke_failed', 'Upload automation API-key revocation failed.', [
                'token_id' => $tokenId,
                'gallery_id' => $galleryId,
            ]);
            upload_automation_json(['ok' => false, 'error' => 'API key could not be revoked.'], 422);
            return;
        }

        admin_log_event('info', 'upload_automation.token_revoked', 'Upload automation API key revoked through the companion app.', [
            'token_id' => $tokenId,
            'gallery_id' => $galleryId,
        ]);
        upload_automation_json([
            'ok' => true,
            'action' => 'revoke',
            'gallery_id' => $galleryId,
            'token_id' => $tokenId,
            'message' => 'API key revoked.',
        ]);
        return;
    }

    if (str_starts_with($action, 'ai_')) {
        upload_automation_handle_ai_action($action, $galleryId, $gallery, $tokenRow, $jsonPayload);
        return;
    }

    // $submittedGalleryId stores an optional client-side assertion, not the source of authority.
    $submittedGalleryId = (int) ($_POST['gallery_id'] ?? 0);
    if ($submittedGalleryId > 0 && $submittedGalleryId !== $galleryId) {
        admin_log_event('warning', 'upload_automation.gallery_mismatch', 'Upload automation request tried to use an API key for a different gallery.', [
            'token_id' => (int) $tokenRow['id'],
            'token_gallery_id' => $galleryId,
            'submitted_gallery_id' => $submittedGalleryId,
        ]);
        upload_automation_json(['ok' => false, 'error' => 'API key is not allowed to upload to the submitted gallery.'], 403);
        return;
    }

    try {
        // $files stores the multipart files in the same shape used by the browser upload form.
        $files = upload_automation_uploaded_files();
        // $imageClientIds stores optional request-local IDs aligned with images[].
        $imageClientIds = upload_automation_image_client_ids();
        // $clientThumbnailEntries stores optional thumbnails generated by the companion app.
        $clientThumbnailEntries = upload_automation_client_thumbnail_entries();
        // $simCameraMetadata stores optional Flight Simulator camera coordinates submitted by the companion app.
        $simCameraMetadata = upload_automation_sim_camera_metadata();
        // $entries stores validated upload entries returned by the existing upload validator.
        $entries = gallery_upload_entries($files);
        // $postUploadErrors stores optional post-store failures that must not poison the original upload response.
        $postUploadErrors = [];
        // $uploadResult stores the gallery mutation result produced under a
        // short gallery-scoped advisory lock. Manual bulk upload can run several
        // HTTP requests in parallel, but the existing scanner reconciles the
        // whole target folder. The lock prevents two PHP workers from inserting
        // the same discovered image row concurrently.
        $uploadResult = upload_automation_with_gallery_lock($galleryId, function () use ($galleryId, $gallery, $entries, $clientThumbnailEntries, $imageClientIds, $simCameraMetadata, &$postUploadErrors): array {
            // $stored stores the existing upload pipeline result after filesystem storage and image scan.
            $stored = store_uploaded_gallery_images($galleryId, $entries);
            // $simCameraResult stores the optional GPS metadata update for accepted screenshot uploads.
            $simCameraResult = ['attached' => 0, 'skipped' => 0, 'error' => ''];
            try {
                $simCameraResult = upload_automation_apply_sim_camera_metadata($galleryId, $stored, $simCameraMetadata);
                if ((string) ($simCameraResult['error'] ?? '') !== '') {
                    $postUploadErrors[] = 'Flight Simulator camera metadata was skipped: ' . (string) $simCameraResult['error'];
                }
            } catch (Throwable $exception) {
                $simCameraResult = [
                    'attached' => 0,
                    'skipped' => count((array) ($stored['image_ids'] ?? [])),
                    'error' => $exception->getMessage(),
                ];
                $postUploadErrors[] = 'Flight Simulator camera metadata failed: ' . $exception->getMessage();
            }

            // $clientThumbnailResult stores the thumbnails installed from the client request.
            $clientThumbnailResult = ['installed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
            try {
                $clientThumbnailResult = upload_automation_install_client_thumbnails($galleryId, $gallery, $clientThumbnailEntries, $imageClientIds, $stored);
            } catch (Throwable $exception) {
                $clientThumbnailResult = [
                    'installed' => 0,
                    'skipped' => 0,
                    'failed' => count($clientThumbnailEntries),
                    'errors' => [$exception->getMessage()],
                ];
                $postUploadErrors[] = 'Client thumbnail installation failed: ' . $exception->getMessage();
            }

            return [$stored, $clientThumbnailResult, $simCameraResult];
        });
        // $stored stores the existing upload pipeline result after filesystem storage and image scan.
        $stored = $uploadResult[0];
        // $clientThumbnailResult stores the thumbnails installed from the client request.
        $clientThumbnailResult = $uploadResult[1];
        // $simCameraResult stores the optional camera-location metadata outcome.
        $simCameraResult = $uploadResult[2];
        // $createThumbnails stores whether the watcher asked the server to create derivatives immediately.
        $createThumbnails = upload_automation_bool($_POST['create_thumbnails'] ?? '1', true);
        // $thumbnails stores the count of generated thumbnails and display derivatives.
        $thumbnails = 0;
        // $thumbnailFailed stores derivative failures reported by the thumbnail service.
        $thumbnailFailed = 0;
        // $thumbnailErrors stores concise thumbnail diagnostics for JSON and logs.
        $thumbnailErrors = [];

        if ($createThumbnails) {
            foreach ((array) ($stored['image_ids'] ?? []) as $imageId) {
                try {
                    // $image stores the image row created or updated by the scan.
                    $image = find_image((int) $imageId);
                    if (!$image) {
                        continue;
                    }
                    // $thumbnailResult stores per-image derivative generation counts.
                    $thumbnailResult = create_image_thumbnails_result($image, $gallery);
                    $thumbnails += (int) ($thumbnailResult['created'] ?? 0);
                    $thumbnailFailed += (int) ($thumbnailResult['failed'] ?? 0);
                    foreach ((array) ($thumbnailResult['errors'] ?? []) as $thumbnailError) {
                        $thumbnailErrors[] = (string) $thumbnailError;
                    }
                } catch (Throwable $exception) {
                    $thumbnailFailed++;
                    $thumbnailErrors[] = $exception->getMessage();
                    $postUploadErrors[] = 'Thumbnail generation failed: ' . $exception->getMessage();
                }
            }
        }

        try {
            mark_upload_automation_token_used((int) $tokenRow['id']);
        } catch (Throwable $exception) {
            $postUploadErrors[] = 'API-key last-used timestamp failed: ' . $exception->getMessage();
        }

        admin_log_event('info', 'upload_automation.images_uploaded', 'Upload automation stored images through the existing gallery upload pipeline.', [
            'token_id' => (int) $tokenRow['id'],
            'gallery_id' => $galleryId,
            'folder_path' => (string) ($gallery['folder_path'] ?? ''),
            'uploaded' => (int) ($stored['uploaded'] ?? 0),
            'scanned' => (int) ($stored['scanned'] ?? 0),
            'thumbnails' => $thumbnails,
            'thumbnail_failed' => $thumbnailFailed,
            'client_thumbnails_installed' => (int) ($clientThumbnailResult['installed'] ?? 0),
            'client_thumbnails_skipped' => (int) ($clientThumbnailResult['skipped'] ?? 0),
            'client_thumbnails_failed' => (int) ($clientThumbnailResult['failed'] ?? 0),
            'sim_camera_metadata_attached' => (int) ($simCameraResult['attached'] ?? 0),
            'sim_camera_metadata_skipped' => (int) ($simCameraResult['skipped'] ?? 0),
            'post_upload_errors' => array_values(array_unique(array_filter($postUploadErrors))),
            'filenames' => array_values((array) ($stored['filenames'] ?? [])),
            'renamed' => (int) ($stored['renamed'] ?? 0),
            'rename_failures' => array_values((array) ($stored['rename_failures'] ?? [])),
        ]);

        upload_automation_json([
            'ok' => true,
            'gallery_id' => $galleryId,
            'gallery_title' => (string) ($gallery['title'] ?? ''),
            'gallery_url' => gallery_public_url($gallery),
            'edit_url' => admin_edit_gallery_tab_url($galleryId, 'admin-edit-images'),
            'uploaded' => (int) ($stored['uploaded'] ?? 0),
            'scanned' => (int) ($stored['scanned'] ?? 0),
            'image_ids' => array_map('intval', (array) ($stored['image_ids'] ?? [])),
            'filenames' => array_values((array) ($stored['filenames'] ?? [])),
            'renamed' => (int) ($stored['renamed'] ?? 0),
            'rename_warnings' => array_values((array) ($stored['rename_warnings'] ?? [])),
            'rename_failures' => array_values((array) ($stored['rename_failures'] ?? [])),
            'thumbnails' => $thumbnails,
            'thumbnail_failed' => $thumbnailFailed,
            'thumbnail_errors' => array_values(array_unique(array_filter($thumbnailErrors))),
            'post_upload_errors' => array_values(array_unique(array_filter($postUploadErrors))),
            'sim_camera_metadata' => [
                'attached' => (int) ($simCameraResult['attached'] ?? 0),
                'skipped' => (int) ($simCameraResult['skipped'] ?? 0),
                'error' => (string) ($simCameraResult['error'] ?? ''),
            ],
            'client_thumbnails' => [
                'installed' => (int) ($clientThumbnailResult['installed'] ?? 0),
                'skipped' => (int) ($clientThumbnailResult['skipped'] ?? 0),
                'failed' => (int) ($clientThumbnailResult['failed'] ?? 0),
                'errors' => array_values((array) ($clientThumbnailResult['errors'] ?? [])),
            ],
        ]);
    } catch (Throwable $exception) {
        admin_log_event('error', 'upload_automation.upload_failed', 'Upload automation request failed.', [
            'token_id' => (int) $tokenRow['id'],
            'gallery_id' => $galleryId,
            'error' => $exception->getMessage(),
        ]);
        upload_automation_json(['ok' => false, 'error' => $exception->getMessage()], 422);
    }
}

/**
 * Generate or revoke a gallery-scoped upload automation API key from the admin UI.
 */
function cms_admin_upload_automation_token(): void
{
    // $wantsJson records side-panel and AJAX requests before any legacy redirect helper can emit HTML.
    $wantsJson = upload_automation_token_request_wants_json();

    if ($wantsJson) {
        // $user stores the current user for JSON requests. Anonymous or expired sessions must return JSON, not a login page.
        $user = current_user();
        if (!$user || (string) ($user['role'] ?? '') !== 'admin') {
            upload_automation_token_json_response([
                'ok' => false,
                'error' => t('auth.admin_required', 'Admin access is required.'),
            ], 403);
            return;
        }

        if (!upload_automation_token_csrf_valid()) {
            upload_automation_token_json_response([
                'ok' => false,
                'error' => t('security.invalid_csrf', 'Invalid CSRF token.'),
            ], 400);
            return;
        }
    } else {
        require_admin();
        verify_csrf();
    }

    // $galleryId stores the gallery whose automation keys are being changed.
    $galleryId = (int) ($_POST['gallery_id'] ?? 0);
    // $gallery stores the gallery being edited.
    $gallery = find_gallery($galleryId, true) ?: find_gallery($galleryId);
    if (!$gallery) {
        if ($wantsJson) {
            upload_automation_token_json_response([
                'ok' => false,
                'error' => t('admin.gallery_not_found', 'Gallery not found.'),
            ], 404);
            return;
        }
        cms_not_found();
        return;
    }

    // $action stores the requested token management action.
    $action = (string) ($_POST['action'] ?? 'create');
    // $tokenId stores the created or revoked database row for JSON callers.
    $tokenId = 0;
    // $notice stores the message returned to the caller after a mutation.
    $notice = '';
    // $actionOk records whether the mutation completed or failed before the response is emitted.
    $actionOk = true;
    // $failureStatus stores the HTTP code for JSON callers without exposing raw database errors.
    $failureStatus = 422;

    try {
        if ($action === 'revoke') {
            // $tokenId stores the database row to revoke.
            $tokenId = (int) ($_POST['token_id'] ?? 0);
            if (!revoke_gallery_upload_automation_token($galleryId, $tokenId)) {
                throw new RuntimeException(t('upload_automation.error.revoke_failed', 'The selected API key could not be revoked.'));
            }
            admin_log_event('info', 'upload_automation.token_revoked', 'Admin revoked a gallery upload automation API key.', [
                'gallery_id' => $galleryId,
                'token_id' => $tokenId,
            ]);
            $notice = t('upload_automation.notice.revoked', 'Upload automation API key revoked.');
            flash_message('admin_notice', $notice);
        } else {
            // $user stores the current admin user that created the key.
            $user = current_user();
            // $created stores the new API key. The raw token is put into the session for one display only.
            $created = create_gallery_upload_automation_token($galleryId, $user ? (int) $user['id'] : null, (string) ($_POST['label'] ?? ''));
            $tokenId = (int) $created['id'];
            $_SESSION['upload_automation_new_token_' . $galleryId] = $created['token'];
            admin_log_event('info', 'upload_automation.token_created', 'Admin created a gallery upload automation API key.', [
                'gallery_id' => $galleryId,
                'token_id' => $tokenId,
                'label' => (string) $created['label'],
            ]);
            $notice = t('upload_automation.notice.created', 'Upload automation API key created. Copy it now. It will not be shown again.');
            flash_message('admin_notice', $notice);
        }
    } catch (Throwable $exception) {
        $actionOk = false;
        $notice = $exception->getMessage();
        if ($exception instanceof MutationSchemaUnavailableException) {
            $failureStatus = $exception->state === 'unknown' ? 503 : 409;
        }
        admin_log_event('error', 'upload_automation.token_action_failed', 'Upload automation API-key management failed.', [
            'gallery_id' => $galleryId,
            'action' => $action === 'revoke' ? 'revoke' : 'create',
            'schema_state' => $exception instanceof MutationSchemaUnavailableException ? $exception->state : 'not_schema_policy',
        ]);
        flash_message('admin_notice', $notice);
    }

    // $returnUrl stores the page or panel route that should be refreshed after the mutation.
    $returnUrl = upload_automation_token_return_url($galleryId);
    if ($wantsJson) {
        upload_automation_token_json_response([
            'ok' => $actionOk,
            'message' => $notice,
            'refresh_url' => $returnUrl,
            'gallery_id' => $galleryId,
            'action' => $action === 'revoke' ? 'revoke' : 'create',
            'token_id' => $tokenId,
        ], $actionOk ? 200 : $failureStatus);
        return;
    }

    redirect_to($returnUrl);
}

/**
 * Return whether the API-key mutation request expects a JSON response.
 *
 * This mirrors the shared admin JSON detector, but it is local to this
 * controller so the token endpoint can decide before legacy helpers have a
 * chance to redirect to an HTML login or error page.
 *
 * @return bool True when the request came from the side panel or another AJAX caller.
 */
function upload_automation_token_request_wants_json(): bool
{
    return !empty($_POST['ajax'])
        || !empty($_GET['ajax'])
        || !empty($_POST['panel'])
        || !empty($_GET['panel'])
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

/**
 * Return whether the submitted API-key form contains the current CSRF token.
 *
 * @return bool True when the submitted token matches the active session token.
 */
function upload_automation_token_csrf_valid(): bool
{
    // $token stores the submitted CSRF value from the API-key create or revoke form.
    $token = (string) ($_POST['csrf_token'] ?? '');
    return $token !== '' && hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token);
}

/**
 * Emit a JSON response for side-panel API-key mutations and stop no further processing.
 *
 * @param array<string,mixed> $payload JSON-safe response payload.
 * @param int $status HTTP status code to send with the response.
 */
function upload_automation_token_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Resolve the post-action URL for upload automation API-key forms.
 *
 * @param int $galleryId Gallery id submitted by the token-management form.
 * @return string Safe same-site admin URL that preserves the API manager context.
 */
function upload_automation_token_return_url(int $galleryId): string
{
    // $fallbackUrl keeps legacy gallery-editor forms working when no explicit return URL was submitted.
    $fallbackUrl = admin_edit_gallery_tab_url($galleryId, 'admin-edit-api');
    // $returnUrl stores the current admin page submitted by newer API-manager forms.
    $returnUrl = trim((string) ($_POST['return_url'] ?? ''));
    if ($returnUrl !== '') {
        // $safeReturnUrl is same-site only and rejects login/setup targets through the existing redirect sanitizer.
        $safeReturnUrl = sanitize_login_return_target($returnUrl, $fallbackUrl);
        if (upload_automation_return_url_allowed($safeReturnUrl, $galleryId)) {
            return $safeReturnUrl;
        }
    }

    // $returnContext stores an explicit stable context for forms that should not map to gallery edit tabs.
    $returnContext = (string) ($_POST['return_context'] ?? '');
    if ($returnContext === 'api_manager') {
        return url_for('admin_api_manager');
    }

    // $returnTab stores the older gallery-editor tab target used by existing forms.
    $returnTab = admin_edit_gallery_tab_id((string) ($_POST['return_tab'] ?? '')) ?: 'admin-edit-api';
    return admin_edit_gallery_tab_url($galleryId, $returnTab);
}

/**
 * Return whether a token-management return URL is valid for API-manager workflows.
 *
 * @param string $url Same-site URL after base redirect sanitization.
 * @param int $galleryId Gallery id submitted by the token-management form.
 * @return bool True when the URL points back to the dedicated API manager or this gallery's API editor tab.
 */
function upload_automation_return_url_allowed(string $url, int $galleryId): bool
{
    // $parts stores the URL components used to validate the route without trusting the raw submitted string.
    $parts = parse_url($url);
    if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host'])) {
        return false;
    }

    // $queryParams stores index.php route parameters when the app is using query-string routing.
    $queryParams = [];
    parse_str((string) ($parts['query'] ?? ''), $queryParams);
    // $page stores the front-controller page name.
    $page = (string) ($queryParams['page'] ?? '');
    if ($page === 'admin_api_manager') {
        return true;
    }

    if ($page !== 'admin_edit_gallery') {
        return false;
    }

    // $postedGalleryId stores the gallery id embedded in the return URL. It must match the form target.
    $postedGalleryId = (int) ($queryParams['id'] ?? 0);
    if ($postedGalleryId !== $galleryId) {
        return false;
    }

    // $tab stores the tab requested by the return URL. Empty is accepted for older URLs, otherwise require a valid tab.
    $tab = (string) ($queryParams['tab'] ?? '');
    return $tab === '' || admin_edit_gallery_tab_id($tab) !== '';
}

/**
 * Render gallery-scoped upload automation controls inside the image editor tab.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param string $returnTab Return tab value.
 */
function render_admin_gallery_upload_automation_panel(array $gallery, string $returnTab = 'admin-edit-api'): void
{
    // $galleryId stores the gallery that owns all shown API keys.
    $galleryId = (int) ($gallery['id'] ?? 0);
    if ($galleryId <= 0) {
        return;
    }

    echo '<section class="panel admin-upload-automation-panel">';
    echo '<div class="admin-upload-automation-head"><div><p class="admin-kicker">' . e(t('upload_automation.kicker', 'Automation')) . '</p><h3>' . e(t('upload_automation.title', 'Watched-folder upload API')) . '</h3><p class="muted">' . e(t('upload_automation.help', 'Generate a gallery-scoped API key for the Windows companion app. The key can upload only into this gallery and can be revoked at any time.')) . '</p></div></div>';

    if (!upload_automation_schema_ready()) {
        $schemaStatus = upload_automation_schema_status();
        $schemaMessage = schema_inspection_is_unknown($schemaStatus)
            ? t('upload_automation.schema_unknown', 'Upload automation is temporarily unavailable because its database schema could not be verified. No API-key changes are allowed until the database inspection succeeds.')
            : t('upload_automation.migration_required', 'Upload automation needs a pending database migration before API keys can be generated.');
        echo '<div class="notice">' . e($schemaMessage) . '</div>';
        echo '</section>';
        return;
    }

    // $newToken stores a raw API key generated in the previous POST request. It is shown once.
    $newToken = (string) ($_SESSION['upload_automation_new_token_' . $galleryId] ?? '');
    unset($_SESSION['upload_automation_new_token_' . $galleryId]);
    // $endpoint stores the absolute upload URL that the companion app can use.
    $endpoint = absolute_public_url(url_for('upload_automation_upload'));

    echo '<div class="admin-upload-automation-grid">';
    echo '<div class="admin-upload-automation-copy">';
    echo '<label><span>' . e(t('upload_automation.endpoint', 'Upload endpoint')) . '</span><input type="text" readonly value="' . e($endpoint) . '"></label>';
    echo '<p class="muted">' . e(t('upload_automation.endpoint_help', 'Use this endpoint with the generated API key in the Python watcher app. The clean URL /api/upload also works when URL rewriting is enabled.')) . '</p>';
    if ($newToken !== '') {
        echo '<label><span>' . e(t('upload_automation.new_key', 'New API key')) . '</span><textarea readonly rows="3" class="admin-upload-automation-key">' . e($newToken) . '</textarea></label>';
        echo '<div class="notice">' . e(t('upload_automation.copy_now', 'Copy this API key now. For security, only its hash is stored and the raw value will not be shown again.')) . '</div>';
    }
    echo '<form method="post" action="' . e(url_for('admin_upload_automation_token')) . '" class="admin-upload-automation-form" data-admin-upload-automation-token-form="1">' . csrf_field();
    echo '<input type="hidden" name="ajax" value="1">';
    echo '<input type="hidden" name="panel" value="1">';
    echo '<input type="hidden" name="gallery_id" value="' . $galleryId . '">';
    echo '<input type="hidden" name="return_tab" value="' . e($returnTab) . '">';
    echo '<input type="hidden" name="return_url" value="' . e(admin_edit_gallery_tab_url($galleryId, $returnTab)) . '">';
    echo '<input type="hidden" name="action" value="create">';
    echo '<label><span>' . e(t('upload_automation.label', 'Label')) . '</span><input type="text" name="label" value="' . e(t('upload_automation.folder_watcher', 'Folder watcher')) . '" maxlength="190"></label>';
    echo '<button type="submit" class="button secondary">' . e(t('upload_automation.generate_key', 'Generate API key')) . '</button>';
    echo '</form>';
    echo '</div>';

    // $tokens stores active API keys that can still upload to this gallery.
    $tokens = gallery_upload_automation_tokens($galleryId);
    echo '<div class="admin-upload-automation-list">';
    echo '<h4>' . e(t('upload_automation.active_keys', 'Active API keys')) . '</h4>';
    if (!$tokens) {
        echo '<p class="muted">' . e(t('upload_automation.no_keys', 'No active upload automation keys exist for this gallery.')) . '</p>';
    } else {
        echo '<table><thead><tr><th>' . e(t('upload_automation.label', 'Label')) . '</th><th>' . e(t('upload_automation.created', 'Created')) . '</th><th>' . e(t('upload_automation.last_used', 'Last used')) . '</th><th>' . e(t('upload_automation.action', 'Action')) . '</th></tr></thead><tbody>';
        foreach ($tokens as $token) {
            echo '<tr>';
            echo '<td>' . e((string) ($token['label'] ?? t('upload_automation.folder_watcher', 'Folder watcher'))) . '</td>';
            echo '<td>' . e((string) ($token['created_at'] ?? '')) . '</td>';
            echo '<td>' . e((string) ($token['last_used_at'] ?? t('upload_automation.never', 'Never'))) . '</td>';
            echo '<td><form method="post" action="' . e(url_for('admin_upload_automation_token')) . '" class="inline-admin-form" data-admin-upload-automation-token-form="1">' . csrf_field();
            echo '<input type="hidden" name="ajax" value="1">';
            echo '<input type="hidden" name="panel" value="1">';
            echo '<input type="hidden" name="gallery_id" value="' . $galleryId . '">';
            echo '<input type="hidden" name="return_tab" value="' . e($returnTab) . '">';
            echo '<input type="hidden" name="return_url" value="' . e(admin_edit_gallery_tab_url($galleryId, $returnTab)) . '">';
            echo '<input type="hidden" name="action" value="revoke">';
            echo '<input type="hidden" name="token_id" value="' . (int) $token['id'] . '">';
            echo '<button type="submit" class="secondary danger inline-admin-action">' . e(t('upload_automation.revoke', 'Revoke')) . '</button>';
            echo '</form></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
    echo '</div>';
    echo '</section>';
}

/**
 * Render the admin-wide upload automation manager.
 */
function cms_admin_api_manager(): void
{
    require_admin();
    admin_render_profile_start('admin_api_manager');

    render_header(t('admin.upload_automation.manager_title', 'API manager'));
    echo '<section class="hero admin-dashboard-hero"><div><p class="admin-kicker">' . e(t('upload_automation.kicker', 'Automation')) . '</p><h1>' . e(t('admin.upload_automation.manager_title', 'API manager')) . '</h1><p class="muted">' . e(t('admin.upload_automation.manager_intro', 'Review every active upload automation API key across galleries. Gallery-scoped keys stay available in each gallery editor, and this page gives you a global view for auditing and revocation.')) . '</p></div><nav class="admin-hero-actions"><a class="button secondary" href="' . e(url_for('admin')) . '">' . e(t('admin.upload_automation.back_to_dashboard', 'Back to dashboard')) . '</a></nav></section>';

    $notice = (string) flash_message('admin_notice');
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }

    echo '<section class="panel admin-upload-automation-manager">';
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.upload_automation.active_keys_kicker', 'Active keys')) . '</p><h2>' . e(t('admin.upload_automation.active_keys_title', 'Active upload API keys')) . '</h2></div><p class="muted">' . e(t('admin.upload_automation.active_keys_help', 'Keys remain scoped to one gallery. Revoke a key here to disable upload access immediately.')) . '</p></div>';

    if (!upload_automation_schema_ready()) {
        $schemaStatus = upload_automation_schema_status();
        $schemaMessage = schema_inspection_is_unknown($schemaStatus)
            ? t('upload_automation.schema_unknown', 'Upload automation is temporarily unavailable because its database schema could not be verified. No API-key changes are allowed until the database inspection succeeds.')
            : t('upload_automation.migration_required', 'Upload automation needs a pending database migration before API keys can be generated.');
        echo '<div class="notice">' . e($schemaMessage) . '</div>';
        echo '</section>';
        render_footer();
        return;
    }

    $tokens = upload_automation_tokens_for_manager();
    if (!$tokens) {
        echo '<p class="muted">' . e(t('upload_automation.no_keys', 'No active upload automation keys exist for this gallery.')) . '</p>';
    } else {
        echo '<table class="admin-upload-automation-table"><thead><tr><th>' . e(t('upload_automation.gallery', 'Gallery')) . '</th><th>' . e(t('upload_automation.label', 'Label')) . '</th><th>' . e(t('upload_automation.created', 'Created')) . '</th><th>' . e(t('upload_automation.last_used', 'Last used')) . '</th><th>' . e(t('upload_automation.action', 'Action')) . '</th></tr></thead><tbody>';
        foreach ($tokens as $token) {
            $galleryId = (int) ($token['gallery_id'] ?? 0);
            $galleryTitle = (string) ($token['gallery_title'] ?? '');
            echo '<tr>';
            echo '<td><a href="' . e(admin_edit_gallery_tab_url($galleryId, 'admin-edit-api')) . '">' . e($galleryTitle !== '' ? $galleryTitle : ('#' . $galleryId)) . '</a></td>';
            echo '<td>' . e((string) ($token['label'] ?? t('upload_automation.folder_watcher', 'Folder watcher'))) . '</td>';
            echo '<td>' . e((string) ($token['created_at'] ?? '')) . '</td>';
            echo '<td>' . e((string) ($token['last_used_at'] ?? t('upload_automation.never', 'Never'))) . '</td>';
            echo '<td><form method="post" action="' . e(url_for('admin_upload_automation_token')) . '" class="inline-admin-form" data-admin-upload-automation-token-form="1">' . csrf_field();
            echo '<input type="hidden" name="gallery_id" value="' . $galleryId . '">';
            echo '<input type="hidden" name="return_context" value="api_manager">';
            echo '<input type="hidden" name="return_url" value="' . e(url_for('admin_api_manager')) . '">';
            echo '<input type="hidden" name="action" value="revoke">';
            echo '<input type="hidden" name="token_id" value="' . (int) $token['id'] . '">';
            echo '<button type="submit" class="secondary danger inline-admin-action">' . e(t('upload_automation.revoke', 'Revoke')) . '</button>';
            echo '</form></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    echo '</section>';
    render_footer();
}
