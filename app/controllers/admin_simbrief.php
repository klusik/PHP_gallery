<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_simbrief.php
 * Module Type: Controller Module
 *
 * Purpose:
 *   Provides admin-only SimBrief import actions used by the gallery editor.
 *
 * Responsibilities:
 *   - Validate admin and CSRF access for SimBrief draft generation
 *   - Keep SimBrief import separate from gallery persistence
 *   - Return JSON that can be inserted into the existing description textarea
 *   - Report clear errors without changing saved gallery descriptions
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
 *   2026-05-24
 */

declare(strict_types=1);

/**
 * Send a SimBrief JSON response and stop the request.
 *
 * @param array<string, mixed> $payload Response payload.
 * @param int $statusCode HTTP status code.
 * @return void
 */
function admin_simbrief_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Generate a gallery-description draft from the latest SimBrief OFP.
 *
 * @return void
 */
function cms_admin_simbrief_description(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        admin_simbrief_json_response([
            'ok' => false,
            'error' => t('admin.simbrief.error_post_required', 'Use the gallery editor form to generate a SimBrief description draft.'),
        ], 405);
        return;
    }

    verify_csrf();

    $galleryId = (int) ($_POST['gallery_id'] ?? 0);
    $gallery = $galleryId > 0 ? find_gallery($galleryId) : null;
    if (!$gallery) {
        admin_simbrief_json_response([
            'ok' => false,
            'error' => t('admin.simbrief.error_gallery_missing', 'The gallery could not be found. Reload the editor and try again.'),
        ], 404);
        return;
    }

    try {
        $result = simbrief_description_generate_for_identifier(
            (string) ($_POST['simbrief_pilot_id'] ?? ''),
            (string) ($_POST['simbrief_pilot_name'] ?? '')
        );
        $details = $result['details'] ?? [];
        if (function_exists('admin_log_event')) {
            admin_log_event('info', 'simbrief.description_generated', 'Admin generated a gallery description draft from SimBrief.', [
                'gallery_id' => $galleryId,
                'identifier_type' => (string) ($result['identifier_type'] ?? ''),
                'origin' => (string) ($details['origin_code'] ?? ''),
                'destination' => (string) ($details['destination_code'] ?? ''),
                'aircraft' => (string) ($details['aircraft'] ?? ''),
            ]);
        }

        admin_simbrief_json_response([
            'ok' => true,
            'description' => (string) ($result['description'] ?? ''),
            'message' => t('admin.simbrief.generated', 'SimBrief draft generated. Review it in the description field, then save the gallery.'),
            'details' => $details,
        ]);
    } catch (Throwable $exception) {
        if (function_exists('admin_log_event')) {
            admin_log_event('warning', 'simbrief.description_failed', 'Admin SimBrief description generation failed.', [
                'gallery_id' => $galleryId,
                'error' => $exception->getMessage(),
            ]);
        }

        admin_simbrief_json_response([
            'ok' => false,
            'error' => $exception->getMessage(),
        ], 422);
    }
}
