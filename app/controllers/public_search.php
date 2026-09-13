<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/public_search.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles the HTTP boundary for optional progressive public search requests.
 *
 * Responsibilities:
 *   - Validate browser-supplied search and phase parameters
 *   - Resolve optional gallery-branch context without exposing inaccessible galleries
 *   - Delegate search execution to service-layer orchestration
 *   - Return bounded JSON responses and HTTP error status codes
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
 *   - Database access and search ranking belong in model/service layers, not here.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use Throwable;
use function Gallery\Core\current_user;
use function Gallery\Core\url_for;
use function Gallery\Services\admin_log_event;
use function Gallery\Services\feature_capability_effective_enabled;
use function Gallery\Services\find_gallery;
use function Gallery\Services\gallery_allows_direct_public_request;
use function Gallery\Services\public_home_search_enabled;
use function Gallery\Services\public_search_normalize_query;
use function Gallery\Services\public_search_phase_results;
use function Gallery\Services\public_search_phase_supported;
use function Gallery\Services\public_search_query_length;
use function Gallery\Services\public_search_results;
use function Gallery\Services\t;

/**
 * Return JSON results for the optional public live search.
 */
function cms_public_search(): void
{
    header('Content-Type: application/json; charset=utf-8');
    if (!public_home_search_enabled()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'disabled']);
        return;
    }

    $query = public_search_normalize_query((string) ($_GET['q'] ?? ''));
    if (public_search_query_length($query) < 2) {
        echo json_encode(['ok' => true, 'query' => $query, 'results' => []]);
        return;
    }

    try {
        $phase = trim((string) ($_GET['phase'] ?? ''));
        $contextGallery = public_search_context_from_request();
        $saveSmartGalleryUrl = current_user() && feature_capability_effective_enabled('smart_galleries') ? url_for('admin_smart_galleries', ['from_search' => $query]) : null;
        $saveSmartGalleryLabel = current_user() && feature_capability_effective_enabled('smart_galleries') ? t('smart_gallery.save_search', 'Save search as Smart Gallery') : null;

        if ($phase !== '') {
            if (!public_search_phase_supported($phase)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'invalid_phase']);
                return;
            }

            echo json_encode([
                'ok' => true,
                'query' => $query,
                'phase' => $phase,
                'phase_complete' => true,
                'results' => public_search_phase_results($phase, $query, 14, $contextGallery),
                'save_smart_gallery_url' => $saveSmartGalleryUrl,
                'save_smart_gallery_label' => $saveSmartGalleryLabel,
            ]);
            return;
        }

        echo json_encode([
            'ok' => true,
            'query' => $query,
            'results' => public_search_results($query, 14, $contextGallery),
            'save_smart_gallery_url' => $saveSmartGalleryUrl,
            'save_smart_gallery_label' => $saveSmartGalleryLabel,
        ]);
    } catch (Throwable $exception) {
        admin_log_event('warning', 'public_search.failed', 'Public search request failed.', [
            'exception' => $exception->getMessage(),
        ]);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'search_failed']);
    }
}

/**
 * Return a public search context model from the current request.
 *
 * @return ?array Structured result data for the caller.
 */
function public_search_context_from_request(): ?array
{
    $contextOnly = (string) ($_GET['context_only'] ?? '') === '1';
    $galleryId = (int) ($_GET['gallery_id'] ?? 0);
    if (!$contextOnly || $galleryId <= 0) {
        return null;
    }

    $gallery = find_gallery($galleryId, true);
    if (!$gallery || !gallery_allows_direct_public_request($gallery)) {
        return null;
    }

    return $gallery;
}
