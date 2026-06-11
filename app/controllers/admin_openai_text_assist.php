<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_openai_text_assist.php
 * Module Type: Controller Module
 *
 * Purpose:
 *   Provides admin-only OpenAI text-assistance actions used by editors.
 *
 * Responsibilities:
 *   - Validate admin and CSRF access before any OpenAI call
 *   - Enforce the user-level OpenAI feature gate
 *   - Generate reviewable text suggestions without saving gallery content
 *   - Return JSON suitable for the existing gallery-description editor
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
 *   2026-05-29
 */

declare(strict_types=1);

/**
 * Send an OpenAI text-assistance JSON response and stop the request.
 *
 * @param array $payload Payload value.
 * @param int $statusCode HTTP status code.
 */
function admin_openai_text_assist_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}


/**
 * Return true when the current user may run thumbnail-based OpenAI actions.
 *
 * @param int $userId User id identifier.
 * @return bool True when the condition matches.
 */
function admin_openai_text_assist_user_allows_image_input(int $userId): bool
{
    return function_exists('openai_text_assist_image_input_allowed') && openai_text_assist_image_input_allowed($userId);
}

/**
 * Return a validated image row that belongs to the requested gallery.
 *
 * @param int $galleryId Gallery identifier.
 * @param int $imageId Image identifier.
 * @return array<string,mixed> Structured result data for the caller.
 */
function admin_openai_text_assist_owned_image(int $galleryId, int $imageId): array
{
    $image = function_exists('find_image') ? find_image($imageId) : null;
    if (!$image || (int) ($image['gallery_id'] ?? 0) !== $galleryId) {
        throw new RuntimeException(t('admin.openai.error_image_not_in_gallery', 'The selected photo does not belong to this gallery. Reload the editor and try again.'));
    }
    return $image;
}

/**
 * Return direct-gallery photo candidates for the bulk-description confirmation step.
 *
 * @param int $galleryId Gallery identifier.
 */
function admin_openai_text_assist_bulk_count_response(int $galleryId): void
{
    $candidates = function_exists('openai_text_assist_gallery_bulk_image_candidates') ? openai_text_assist_gallery_bulk_image_candidates($galleryId) : [];
    admin_openai_text_assist_json_response([
        'ok' => true,
        'gallery_id' => $galleryId,
        'count' => count($candidates),
        'image_ids' => array_values(array_map(static fn (array $candidate): int => (int) ($candidate['id'] ?? 0), $candidates)),
        'candidates' => $candidates,
    ]);
}

/**
 * Generate and immediately save one photo description during a confirmed bulk run.
 *
 * @param int $userId User id identifier.
 * @param int $galleryId Gallery identifier.
 * @param int $imageId Image identifier.
 * @param string $language Language value.
 */
function admin_openai_text_assist_bulk_generate_one_response(int $userId, int $galleryId, int $imageId, string $language): void
{
    $image = admin_openai_text_assist_owned_image($galleryId, $imageId);
    $context = openai_text_assist_image_context($imageId);
    $context['visual_references'] = [openai_text_assist_thumbnail_reference_for_image($image)];

    $result = openai_text_assist_generate($userId, 'image_visual_description', $context, (string) ($image['description'] ?? ''), $language);
    $updated = openai_text_assist_save_image_description($imageId, (string) ($result['text'] ?? ''));

    if (function_exists('admin_log_event')) {
        admin_log_event('info', 'openai_text_assist.bulk_image_generated', t('admin.openai.log_bulk_image_generated', 'Admin generated and saved one OpenAI photo description in a bulk run.'), [
            'user_id' => $userId,
            'gallery_id' => $galleryId,
            'image_id' => $imageId,
            'task' => 'image_visual_description',
            'model' => (string) ($result['model'] ?? ''),
            'language' => $language,
            'uses_images' => 1,
            'visual_reference_count' => (int) ($result['visual_reference_count'] ?? 0),
        ]);
    }

    admin_openai_text_assist_json_response([
        'ok' => true,
        'gallery_id' => $galleryId,
        'image_id' => $imageId,
        'filename' => (string) ($updated['filename'] ?? $image['filename'] ?? ''),
        'text' => (string) ($updated['description'] ?? ''),
        'model' => (string) ($result['model'] ?? ''),
        'language' => $language,
        'message' => t('admin.openai.bulk_one_saved', 'Photo description generated and saved.'),
    ]);
}

/**
 * Generate one reviewable gallery-description suggestion through OpenAI.
 */
function cms_admin_openai_text_assist(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        admin_openai_text_assist_json_response([
            'ok' => false,
            'error' => t('admin.openai.error_post_required', 'Use the gallery editor form to generate an OpenAI text suggestion.'),
        ], 405);
        return;
    }

    verify_csrf();
    $user = current_user();
    $userId = (int) ($user['id'] ?? 0);
    if ($userId <= 0) {
        admin_openai_text_assist_json_response([
            'ok' => false,
            'error' => t('admin.openai.error_user_missing', 'The current user account could not be resolved.'),
        ], 401);
        return;
    }

    if (!function_exists('openai_text_assist_available') || !openai_text_assist_available($userId)) {
        admin_openai_text_assist_json_response([
            'ok' => false,
            'error' => t('admin.openai.error_not_enabled', 'OpenAI text assistance is not enabled for this account.'),
        ], 403);
        return;
    }

    $galleryId = (int) ($_POST['gallery_id'] ?? 0);
    $imageId = (int) ($_POST['image_id'] ?? 0);
    $bulkAction = trim((string) ($_POST['bulk_action'] ?? ''));
    $task = openai_text_assist_normalize_task((string) ($_POST['task'] ?? 'gallery_description'));
    $language = function_exists('openai_text_assist_normalize_language') ? openai_text_assist_normalize_language((string) ($_POST['language'] ?? 'auto')) : 'auto';
    if ($bulkAction !== '') {
        if ($galleryId <= 0) {
            admin_openai_text_assist_json_response([
                'ok' => false,
                'error' => t('admin.openai.error_target_missing', 'The edited gallery or photo could not be resolved. Reload the editor and try again.'),
            ], 404);
            return;
        }
        if (!admin_openai_text_assist_user_allows_image_input($userId)) {
            admin_openai_text_assist_json_response([
                'ok' => false,
                'error' => t('admin.openai.error_image_input_disabled', 'Sending thumbnails to OpenAI is disabled for this account. Enable it in your profile first.'),
            ], 403);
            return;
        }
        try {
            if ($bulkAction === 'count_gallery_images') {
                admin_openai_text_assist_bulk_count_response($galleryId);
                return;
            }
            if ($bulkAction === 'generate_gallery_image') {
                admin_openai_text_assist_bulk_generate_one_response($userId, $galleryId, $imageId, $language);
                return;
            }
        } catch (Throwable $exception) {
            admin_openai_text_assist_json_response([
                'ok' => false,
                'error' => $exception->getMessage(),
            ], 422);
            return;
        }
        admin_openai_text_assist_json_response([
            'ok' => false,
            'error' => t('admin.openai.error_unknown_bulk_action', 'Unknown OpenAI bulk action.'),
        ], 400);
        return;
    }
    $usesImages = function_exists('openai_text_assist_task_uses_images') && openai_text_assist_task_uses_images($task);
    $targetType = $imageId > 0 ? 'image' : 'gallery';
    if ($imageId > 0 && $task === 'gallery_description') {
        $task = 'image_description';
    }
    if ($usesImages && (!function_exists('openai_text_assist_image_input_allowed') || !openai_text_assist_image_input_allowed($userId))) {
        admin_openai_text_assist_json_response([
            'ok' => false,
            'error' => t('admin.openai.error_image_input_disabled', 'Sending thumbnails to OpenAI is disabled for this account. Enable it in your profile first.'),
        ], 403);
        return;
    }

    if ($galleryId <= 0 && $imageId <= 0) {
        admin_openai_text_assist_json_response([
            'ok' => false,
            'error' => t('admin.openai.error_target_missing', 'The edited gallery or photo could not be resolved. Reload the editor and try again.'),
        ], 404);
        return;
    }

    $existingText = trim((string) ($_POST['text'] ?? ''));
    if (in_array($task, ['cleanup_text', 'expand_text'], true) && $existingText === '') {
        admin_openai_text_assist_json_response([
            'ok' => false,
            'error' => t('admin.openai.error_text_required', 'This action needs existing description text first.'),
        ], 422);
        return;
    }

    try {
        $context = $imageId > 0 ? openai_text_assist_image_context($imageId) : openai_text_assist_gallery_context($galleryId);
        if ($task === 'image_visual_description' && $imageId > 0) {
            $context['visual_references'] = [openai_text_assist_thumbnail_reference_for_image_id($imageId)];
        } elseif ($task === 'gallery_visual_description' && $galleryId > 0) {
            $context['visual_references'] = openai_text_assist_gallery_thumbnail_references($galleryId, OPENAI_TEXT_ASSIST_VISUAL_GALLERY_LIMIT);
        }
        $submittedTitle = trim((string) ($_POST['title'] ?? ''));
        if ($submittedTitle !== '') {
            if ($imageId > 0) {
                $context['image']['title'] = openai_text_assist_text_limit($submittedTitle, 180);
            } else {
                $context['gallery']['title'] = openai_text_assist_text_limit($submittedTitle, 180);
            }
        }

        $result = openai_text_assist_generate($userId, $task, $context, $existingText, $language);
        if (function_exists('admin_log_event')) {
            admin_log_event('info', 'openai_text_assist.generated', t('admin.openai.log_generated', 'Admin generated an OpenAI text-assistance suggestion.'), [
                'user_id' => $userId,
                'gallery_id' => $galleryId,
                'image_id' => $imageId,
                'target_type' => $targetType,
                'task' => $task,
                'model' => (string) ($result['model'] ?? ''),
                'language' => $language,
                'uses_images' => $usesImages ? 1 : 0,
                'visual_reference_count' => (int) ($result['visual_reference_count'] ?? 0),
            ]);
        }

        admin_openai_text_assist_json_response([
            'ok' => true,
            'text' => (string) ($result['text'] ?? ''),
            'model' => (string) ($result['model'] ?? ''),
            'task' => $task,
            'target_type' => $targetType,
            'language' => $language,
            'message' => $task === 'image_visual_description'
                ? t('admin.openai.generated_image_visual', 'OpenAI used a small thumbnail and inserted a photo description. Save the photo to keep it.')
                : ($task === 'gallery_visual_description'
                    ? t('admin.openai.generated_gallery_visual', 'OpenAI used small gallery thumbnails and inserted a gallery description. Save the gallery to keep it.')
                    : ($targetType === 'image'
                        ? t('admin.openai.generated_image', 'OpenAI suggestion inserted into the photo editor. Save the photo to keep it.')
                        : t('admin.openai.generated', 'OpenAI suggestion inserted into the editor. Save the gallery to keep it.'))),
        ]);
    } catch (Throwable $exception) {
        if (function_exists('admin_log_event')) {
            admin_log_event('warning', 'openai_text_assist.failed', t('admin.openai.log_failed', 'OpenAI text-assistance generation failed.'), [
                'user_id' => $userId,
                'gallery_id' => $galleryId,
                'image_id' => $imageId,
                'target_type' => $targetType,
                'task' => $task,
                'language' => $language,
                'error' => $exception->getMessage(),
                'uses_images' => $usesImages ? 1 : 0,
            ]);
        }

        admin_openai_text_assist_json_response([
            'ok' => false,
            'error' => $exception->getMessage(),
        ], 422);
    }
}
