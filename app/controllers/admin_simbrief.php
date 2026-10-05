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
use function Gallery\Services\simbrief_description_extract_route_points;
use function Gallery\Services\simbrief_description_fetch_latest_ofp;
use function Gallery\Services\simbrief_description_identifier;
use function Gallery\Services\simbrief_description_draft_create;
use function Gallery\Services\simbrief_description_localized_drafts;
use function Gallery\Services\simbrief_description_route_text_from_points;
use function Gallery\Services\content_localization_enabled;
use function Gallery\Services\content_localization_schema_ready;
use function Gallery\Services\content_supported_languages;
use function Gallery\Services\translation_public_language;
use function Gallery\Services\t;
use function Gallery\Views\view_simbrief_description_markdown;
use function Gallery\Services\admin_log_event;

/**
 * Send a SimBrief JSON response and stop the request.
 *
 * @param array $payload Payload value.
 * @param int $statusCode HTTP status code.
 * @return void Emit the JSON response with the requested status.
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
 * @return void Send a private draft reference, localized descriptions and a route preview.
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
    if ($galleryId > 0 && !$gallery) {
        admin_simbrief_json_response([
            'ok' => false,
            'error' => t('admin.simbrief.error_gallery_missing', 'The gallery could not be found. Reload the editor and try again.'),
        ], 404);
        return;
    }

    try {
        $simbriefInput = array_key_exists('simbrief_identifier', $_POST)
            ? \Gallery\Services\simbrief_description_expand_identifier_input($_POST)
            : $_POST;
        $identifier = simbrief_description_identifier(
            (string) ($simbriefInput['simbrief_pilot_id'] ?? ''),
            (string) ($simbriefInput['simbrief_pilot_name'] ?? '')
        );
        $payload = simbrief_description_fetch_latest_ofp($identifier);
        $details = simbrief_description_extract_details($payload);
        $routePoints = simbrief_description_extract_route_points($payload, $details);
        $description = function_exists('Gallery\\Views\\view_simbrief_description_markdown')
            ? view_simbrief_description_markdown($details)
            : simbrief_description_build_markdown($details);
        $localizationReady = function_exists('Gallery\\Services\\content_localization_enabled')
            && content_localization_enabled()
            && function_exists('Gallery\\Services\\content_localization_schema_ready')
            && content_localization_schema_ready('gallery');
        $languages = $localizationReady && function_exists('Gallery\\Services\\content_supported_languages')
            ? content_supported_languages()
            : [];
        $supportedLanguages = function_exists('Gallery\\Services\\content_supported_languages')
            ? content_supported_languages()
            : ['en'];
        $publicDefaultLanguage = function_exists('Gallery\\Services\\translation_public_language')
            ? translation_public_language()
            : 'en';
        $requestedSourceLanguage = strtolower(trim((string) ($_POST['content_language'] ?? '')));
        $selectedSourceLanguage = in_array($requestedSourceLanguage, $supportedLanguages, true)
            ? $requestedSourceLanguage
            : $publicDefaultLanguage;
        if (!in_array($selectedSourceLanguage, $supportedLanguages, true)) {
            $selectedSourceLanguage = 'en';
        }
        $localizedDrafts = function_exists('Gallery\\Services\\simbrief_description_localized_drafts')
            ? simbrief_description_localized_drafts($details, $selectedSourceLanguage, $languages)
            : ['description' => $description, 'translations' => [], 'source_language' => 'en'];
        if ($selectedSourceLanguage === 'en') {
            $localizedDrafts['description'] = $description;
        }
        if (in_array('en', $languages, true)) {
            $localizedDrafts['translations']['en'] = $description;
        }
        $user = \Gallery\Core\current_user();
        $draftRef = simbrief_description_draft_create((int) ($user['id'] ?? 0), $payload, $identifier, $details);
        $draftMessage = $languages !== []
            ? t('admin.simbrief.generated', 'Descriptions are ready in every supported language. Review them; nothing is saved until you save the gallery.')
            : t('admin.simbrief.create_draft_ready', 'Flight description is ready. Review it; nothing is saved until you save the gallery.');

        if (function_exists('Gallery\\Services\\admin_log_event')) {
            admin_log_event('info', 'simbrief.description_generated', 'Admin generated a private gallery description draft from SimBrief.', [
                'gallery_id' => $galleryId,
                'identifier_type' => (string) ($identifier['label'] ?? ''),
                'origin' => (string) ($details['origin_code'] ?? ''),
                'destination' => (string) ($details['destination_code'] ?? ''),
                'aircraft' => (string) ($details['aircraft'] ?? ''),
            ]);
        }

        admin_simbrief_json_response([
            'ok' => true,
            'description' => (string) ($localizedDrafts['description'] ?? $description),
            'translations' => (array) ($localizedDrafts['translations'] ?? []),
            'source_language' => (string) ($localizedDrafts['source_language'] ?? 'en'),
            'draft_ref' => $draftRef,
            'route' => [
                'saved' => false,
                'route_text' => simbrief_description_route_text_from_points($routePoints, $details),
                'point_count' => count($routePoints),
            ],
            'message' => $draftMessage,
            'details' => $details,
        ]);
    } catch (Throwable $exception) {
        if (function_exists('Gallery\\Services\\admin_log_event')) {
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
