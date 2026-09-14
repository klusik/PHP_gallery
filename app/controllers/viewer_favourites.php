<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/viewer_favourites.php
 * Module Type: Controller Module
 *
 * Purpose:
 *   Exposes the narrow Phase 1.1 viewer-favourites HTTP boundary.
 *
 * Responsibilities:
 *   - Render viewer-owned favourite controls on already-authorized source images
 *   - Accept explicit add/remove mutations through viewer CSRF and current viewer identity
 *   - Provide a private favourites landing page without preserving source-gallery permissions
 *   - Keep ordinary public gallery browsing operational when viewer favourite storage is unavailable
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Viewer authentication is not gallery authorization.
 *   - Every favourite mutation re-runs canonical source-image authorization in the service layer.
 *   - Every favourite read re-runs canonical source-image authorization before metadata is rendered.
 *   - Collection ownership and mutation stay in the dedicated Phase 2 service/controller; favourites remain independent.
 *   - Collection sharing is intentionally not implemented.
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Core\image_alt_text;
use function Gallery\Core\image_public_url;
use function Gallery\Core\redirect_to;
use function Gallery\Core\request_method;
use function Gallery\Core\url_for;
use function Gallery\Services\current_viewer;
use function Gallery\Services\public_image_display_title;
use function Gallery\Services\public_thumbnail_render_picture_html;
use function Gallery\Services\t;
use function Gallery\Services\thumbnail_bundle;
use function Gallery\Services\thumbnail_sizes;
use function Gallery\Services\viewer_csrf_token;
use function Gallery\Services\viewer_csrf_verify;
use function Gallery\Services\viewer_favourite_set;
use function Gallery\Services\viewer_favourites_page;
use function Gallery\Services\viewer_favourites_storage_available;
use function Gallery\Services\viewer_collections_for_owner;
use function Gallery\Services\viewer_collections_storage_available;
use function Gallery\Services\viewer_source_image_resolve_authorized;
use function Gallery\Services\viewer_source_image_can_render_reference;

/**
 * Render one server-backed favourite form for an already-authorized source image.
 *
 * @param int $imageId Canonical image identifier.
 * @param bool $isFavourite Current viewer state.
 * @param string $className Optional additional presentation class.
 * @return string HTML form markup.
 */
function render_viewer_favourite_form_html(int $imageId, bool $isFavourite, string $className = ''): string
{
    if ($imageId <= 0 || !viewer_favourites_storage_available()) {
        return '';
    }

    $action = $isFavourite ? 'remove' : 'add';
    $label = $isFavourite
        ? t('viewer.favourites.remove', 'Remove from favourites')
        : t('viewer.favourites.add', 'Add to favourites');
    return \Gallery\Views\view_render_viewer_favourite_form_html([
        'image_id' => $imageId,
        'is_favourite' => $isFavourite,
        'action' => $action,
        'label' => $label,
        'classes' => trim('viewer-favourite-form ' . $className . ($isFavourite ? ' is-favourite' : '')),
        'url' => url_for('viewer_favourite'),
        'csrf_token' => viewer_csrf_token(),
    ]);
}

/**
 * Render the lightbox favourite form host for an authenticated viewer.
 *
 * The form starts hidden with no image authority. Browser code fills the canonical image id
 * only after the lightbox selects an already-authorized source card.
 *
 * @return string HTML form markup or an empty string when viewer favourites are unavailable.
 */
function render_viewer_favourite_lightbox_form_html(): string
{
    if (current_viewer() === null || !viewer_favourites_storage_available()) {
        return '';
    }

    $label = t('viewer.favourites.add', 'Add to favourites');
    return \Gallery\Views\view_render_viewer_favourite_lightbox_form_html([
        'url' => url_for('viewer_favourite'),
        'csrf_token' => viewer_csrf_token(),
        'label' => $label,
    ]);
}

/**
 * Emit one viewer-favourites JSON response.
 *
 * @param array $payload JSON payload.
 * @param int $status HTTP status.
 */
function viewer_favourite_json_response(array $payload, int $status = 200): void
{
    viewer_http_no_store();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Return whether the favourite mutation should use the asynchronous JSON response shape.
 */
function viewer_favourite_wants_json(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
        || (string) ($_POST['ajax'] ?? '') === '1';
}

/**
 * Add or remove one favourite through the current viewer principal.
 */
function cms_viewer_favourite(): void
{
    viewer_http_no_store();
    if (request_method() !== 'POST') {
        if (viewer_favourite_wants_json()) {
            viewer_favourite_json_response(['ok' => false, 'error' => t('viewer.favourites.method_error', 'Favourite changes require a POST request.')], 405);
            return;
        }
        cms_not_found();
        return;
    }

    if (!viewer_http_auth_available()) {
        if (viewer_favourite_wants_json()) {
            viewer_favourite_json_response(['ok' => false, 'error' => t('viewer.favourites.unavailable', 'Favourites are temporarily unavailable.')], 503);
            return;
        }
        viewer_render_unavailable();
        return;
    }

    $viewer = current_viewer();
    if ($viewer === null) {
        if (viewer_favourite_wants_json()) {
            viewer_favourite_json_response([
                'ok' => false,
                'error' => t('viewer.favourites.login_required', 'Sign in as a viewer to use favourites.'),
                'login_url' => url_for('viewer_login'),
            ], 401);
            return;
        }
        redirect_to(url_for('viewer_login'));
    }

    if (!viewer_csrf_verify((string) ($_POST['viewer_csrf_token'] ?? ''))) {
        if (viewer_favourite_wants_json()) {
            viewer_favourite_json_response(['ok' => false, 'error' => t('viewer.common.invalid_request_message', 'The request could not be verified. Please reopen the page and try again.')], 403);
            return;
        }
        http_response_code(403);
        \Gallery\Views\view_render_viewer_favourites_error([
            'title' => t('viewer.favourites.title', 'Favourites'),
            'message' => t('viewer.common.invalid_request_message', 'The request could not be verified. Please reopen the page and try again.'),
            'back_url' => '',
            'back_label' => '',
        ]);
        return;
    }

    $imageId = (int) ($_POST['image_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    if (!in_array($action, ['add', 'remove'], true) || $imageId <= 0) {
        $result = ['ok' => false, 'favourite' => false, 'changed' => false, 'reason' => 'invalid'];
    } else {
        $result = viewer_favourite_set($viewer, $imageId, $action === 'add');
    }

    if (!empty($result['ok'])) {
        if (viewer_favourite_wants_json()) {
            viewer_favourite_json_response([
                'ok' => true,
                'image_id' => $imageId,
                'favourite' => !empty($result['favourite']),
            ]);
            return;
        }
        redirect_to(url_for('viewer_favourites'));
    }

    $reason = (string) ($result['reason'] ?? 'unavailable');
    $message = match ($reason) {
        'invalid' => t('viewer.common.invalid_request_message', 'The request could not be verified. Please reopen the page and try again.'),
        'quota' => t('viewer.favourites.quota_reached', 'Your favourites limit has been reached.'),
        'source_forbidden' => t('viewer.favourites.source_unavailable', 'This photo is not currently available to your viewer session.'),
        default => t('viewer.favourites.unavailable', 'Favourites are temporarily unavailable.'),
    };
    $status = match ($reason) {
        'invalid' => 400,
        'source_forbidden' => 403,
        'quota' => 409,
        default => 503,
    };
    if (viewer_favourite_wants_json()) {
        viewer_favourite_json_response(['ok' => false, 'error' => $message], $status);
        return;
    }

    http_response_code($status);
    \Gallery\Views\view_render_viewer_favourites_error([
        'title' => t('viewer.favourites.title', 'Favourites'),
        'message' => $message,
        'back_url' => url_for('viewer_favourites'),
        'back_label' => t('viewer.favourites.open', 'Open favourites'),
    ]);
}

/**
 * Render the private viewer favourites page.
 */
function cms_viewer_favourites(): void
{
    viewer_http_no_store();
    if (!viewer_http_auth_available()) {
        viewer_render_unavailable();
        return;
    }
    $viewer = current_viewer();
    if ($viewer === null) {
        redirect_to(url_for('viewer_login'));
    }

    $title = t('viewer.favourites.title', 'Favourites');
    $viewModel = [
        'title' => $title,
        'help' => t('viewer.favourites.help', 'Only photos you can currently access are shown. A favourite never preserves access to a protected gallery.'),
        'account_url' => url_for('viewer_account'),
        'account_label' => t('viewer.favourites.back_to_account', 'Account'),
        'storage_available' => viewer_favourites_storage_available(),
        'unavailable_message' => t('viewer.favourites.unavailable', 'Favourites are temporarily unavailable.'),
        'cards' => [],
        'empty_message' => '',
        'hidden_unavailable' => false,
        'hidden_message' => t('viewer.favourites.hidden_unavailable', 'Some saved favourites are hidden because their source gallery is not currently authorized.'),
        'pagination' => ['visible' => false],
    ];
    if (!$viewModel['storage_available']) {
        \Gallery\Views\view_render_viewer_favourites_page($viewModel);
        return;
    }

    $pageData = viewer_favourites_page((int) $viewer['id'], max(1, (int) ($_GET['favourites_page'] ?? 1)), 48);
    $viewerCollectionsAvailable = viewer_collections_storage_available();
    $viewerCollections = $viewerCollectionsAvailable ? viewer_collections_for_owner((int) $viewer['id']) : [];
    $authorized = [];
    $hiddenUnavailable = false;
    foreach ($pageData['rows'] as $row) {
        $imageId = (int) $row['image_id'];
        if (!viewer_source_image_can_render_reference($imageId)) {
            $hiddenUnavailable = true;
            continue;
        }
        $resolved = viewer_source_image_resolve_authorized($imageId);
        if ($resolved === null) {
            $hiddenUnavailable = true;
            continue;
        }
        $authorized[] = $resolved;
    }

    $candidateSizes = array_values(array_filter(thumbnail_sizes(), static fn (int $size): bool => $size <= 960));
    if ($candidateSizes === []) {
        $candidateSizes = [300];
    }
    $cards = [];
    foreach ($authorized as $index => $resolved) {
        $image = $resolved['image'];
        $gallery = $resolved['gallery'];
        $imageId = (int) $image['id'];
        $bundle = thumbnail_bundle($image);
        $cards[] = [
            'image_id' => $imageId,
            'image_url' => image_public_url($image, $gallery),
            'thumbnail_html' => public_thumbnail_render_picture_html(
                $image,
                300,
                $candidateSizes,
                '(min-width: 1100px) 25vw, (min-width: 700px) 33vw, 50vw',
                image_alt_text($image, $gallery, $index + 1),
                $index,
                $bundle
            ),
            'favourite_control_html' => render_viewer_favourite_form_html($imageId, true, 'viewer-favourite-card-overlay'),
            'collection_control_html' => $viewerCollectionsAvailable
                ? render_viewer_collection_add_control_html($imageId, $viewerCollections, 'viewer-collection-card-overlay')
                : '',
            'title' => public_image_display_title($image, $gallery),
        ];
    }

    $viewModel['cards'] = $cards;
    $viewModel['empty_message'] = (int) $pageData['total'] > 0
        ? t('viewer.favourites.none_accessible', 'No favourites on this page are currently accessible.')
        : t('viewer.favourites.empty', 'You have not added any favourites yet.');
    $viewModel['hidden_unavailable'] = $hiddenUnavailable;

    $total = (int) $pageData['total'];
    $perPage = (int) $pageData['per_page'];
    $currentPage = (int) $pageData['page'];
    $pageCount = max(1, (int) ceil($total / max(1, $perPage)));
    $viewModel['pagination'] = [
        'visible' => $pageCount > 1,
        'aria_label' => t('viewer.favourites.pages', 'Favourite pages'),
        'previous_url' => $currentPage > 1 ? url_for('viewer_favourites', ['favourites_page' => $currentPage - 1]) : '',
        'previous_label' => t('pagination.previous', 'Previous'),
        'page_label' => t('pagination.page_of', 'Page {page} of {pages}', ['page' => $currentPage, 'pages' => $pageCount]),
        'next_url' => $currentPage < $pageCount ? url_for('viewer_favourites', ['favourites_page' => $currentPage + 1]) : '',
        'next_label' => t('pagination.next', 'Next'),
    ];

    \Gallery\Views\view_render_viewer_favourites_page($viewModel);
}
