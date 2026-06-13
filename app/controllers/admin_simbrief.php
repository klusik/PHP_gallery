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

namespace Gallery\Controllers;

use Throwable;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\find_gallery;
use function Gallery\Services\simbrief_description_build_markdown;
use function Gallery\Services\simbrief_description_extract_details;
use function Gallery\Services\simbrief_description_fetch_latest_ofp;
use function Gallery\Services\simbrief_description_identifier;
use function Gallery\Services\simbrief_description_save_ofp_for_gallery;
use function Gallery\Services\simbrief_description_save_route_map_from_ofp;
use function Gallery\Services\t;
use function Gallery\Views\view_simbrief_description_markdown;

/**
 * Send a SimBrief JSON response and stop the request.
 *
 * @param array $payload Payload value.
 * @param int $statusCode HTTP status code.
 */
function admin_simbrief_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Generate a gallery-description draft from the latest SimBrief OFP.
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
        $identifier = simbrief_description_identifier(
            (string) ($_POST['simbrief_pilot_id'] ?? ''),
            (string) ($_POST['simbrief_pilot_name'] ?? '')
        );
        $payload = simbrief_description_fetch_latest_ofp($identifier);
        $details = simbrief_description_extract_details($payload);
        $description = function_exists('Gallery\\Views\\view_simbrief_description_markdown')
            ? view_simbrief_description_markdown($details)
            : simbrief_description_build_markdown($details);
        $routeResult = function_exists('Gallery\\Services\\simbrief_description_save_route_map_from_ofp')
            ? simbrief_description_save_route_map_from_ofp($galleryId, $payload, $details)
            : ['saved' => false, 'route_text' => '', 'point_count' => 0, 'unresolved_count' => 0, 'points' => [], 'unresolved' => []];
        $ofpResult = function_exists('Gallery\\Services\\simbrief_description_save_ofp_for_gallery')
            ? simbrief_description_save_ofp_for_gallery($gallery, $payload, $identifier, $details, $routeResult)
            : ['saved' => false, 'path' => '', 'manifest_path' => '', 'filename' => 'simbrief-ofp.json', 'error' => 'OFP storage helper is unavailable.'];

        if (function_exists('admin_log_event')) {
            admin_log_event('info', 'simbrief.description_generated', 'Admin generated a gallery description draft from SimBrief and stored OFP route data.', [
                'gallery_id' => $galleryId,
                'identifier_type' => (string) ($identifier['label'] ?? ''),
                'origin' => (string) ($details['origin_code'] ?? ''),
                'destination' => (string) ($details['destination_code'] ?? ''),
                'aircraft' => (string) ($details['aircraft'] ?? ''),
                'ofp_saved' => !empty($ofpResult['saved']),
                'ofp_pdf_saved' => !empty($ofpResult['pdf_saved']),
                'route_saved' => !empty($routeResult['saved']),
                'route_points' => (int) ($routeResult['point_count'] ?? 0),
            ]);
        }

        $message = t('admin.simbrief.generated', 'SimBrief draft generated. The latest OFP was saved with the gallery and the route map was updated when coordinates were available.');
        if (empty($ofpResult['saved'])) {
            $message .= ' ' . t('admin.simbrief.ofp_save_failed_short', 'The OFP could not be saved: {error}', [
                'error' => (string) ($ofpResult['error'] ?? ''),
            ]);
        } elseif (!empty($ofpResult['pdf_url']) && empty($ofpResult['pdf_saved'])) {
            $message .= ' ' . t('admin.simbrief.ofp_pdf_save_failed_short', 'The original OFP PDF could not be saved: {error}', [
                'error' => (string) ($ofpResult['pdf_error'] ?? ''),
            ]);
        }
        if (empty($routeResult['saved'])) {
            $message .= ' ' . t('admin.simbrief.route_save_failed_short', 'The route map could not be updated from OFP coordinates.');
        }

        admin_simbrief_json_response([
            'ok' => true,
            'description' => $description,
            'message' => $message,
            'details' => $details,
            'ofp' => $ofpResult,
            'route' => $routeResult,
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
