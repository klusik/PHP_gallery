<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/navigation_data.php
 * Module Type: Controller Module
 *
 * Purpose:
 *   Provides local navigation-data lookup actions.
 *
 * Responsibilities:
 *   - Return small JSON lookup responses for future route-planning UI
 *   - Keep route visualization usable with local fallback data
 *   - Keep SimBrief-generated maps independent from live navdata lookup
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
 *   2026-05-27
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use Throwable;
use function Gallery\Core\flash_message;
use function Gallery\Core\redirect_to;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Core\admin_mutation_success_envelope;
use function Gallery\Core\admin_mutation_descriptor;
use function Gallery\Core\admin_mutation_public_gallery_context;
use function Gallery\Core\gallery_public_url;
use function Gallery\Services\admin_dashboard_notice_messages;
use function Gallery\Services\flight_map_navdata_status;
use function Gallery\Services\flight_map_navdata_refresh;
use function Gallery\Services\find_gallery;
use function Gallery\Services\navigation_data_navigraph_authorization_url;
use function Gallery\Services\navigation_data_navigraph_disconnect;
use function Gallery\Services\navigation_data_navigraph_exchange_code;
use function Gallery\Services\navigation_data_navigraph_refresh_packages;
use function Gallery\Services\navigation_data_normalize_ident;
use function Gallery\Services\navigation_data_resolve_ident;
use function Gallery\Services\navigation_data_status;
use function Gallery\Services\t;
use function Gallery\Views\view_render_admin_navigation_data;
use function Gallery\Services\admin_log_event;

/**
 * Send a navigation-data JSON response and stop the request.
 *
 * @param array $payload Payload value.
 * @param int $statusCode Status code value.
 */
function navigation_data_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Render the dedicated admin navigation-data diagnostics page.
 *
 * @return void Passes one prepared import/source snapshot to the shared UI.
 */
function cms_admin_navdata(): void
{
    require_admin();
    $navdataStatus = flight_map_navdata_status();
    view_render_admin_navigation_data([
        'status' => $navdataStatus['hybrid'] ?? [],
        'navdata_status' => $navdataStatus,
        'notices' => admin_dashboard_notice_messages($_GET, (string) flash_message('admin_notice')),
    ]);
}

/**
 * Refresh due local navigation data independently of a gallery editor save.
 *
 * @return void Emits canonical completion without replacing the editor or its draft.
 */
function cms_admin_route_navdata_refresh(): void
{
    require_admin();
    header('Cache-Control: private, no-store');
    if (request_method() !== 'POST') {
        navigation_data_json_response(['ok' => false, 'message' => 'POST required.'], 405);
        return;
    }
    verify_csrf();
    $galleryId = max(0, (int) ($_POST['gallery_id'] ?? 0));
    if ($galleryId > 0 && find_gallery($galleryId, true) === null) {
        navigation_data_json_response(['ok' => false, 'message' => 'Gallery not found.'], 404);
        return;
    }
    // Authenticate and validate CSRF first, then release the session for parallel saves.
    session_write_close();
    ignore_user_abort(true);
    try {
        $outcome = flight_map_navdata_refresh(true, $galleryId);
        $contexts = [];
        $gallery = !empty($outcome['route_updated']) ? find_gallery($galleryId, true) : null;
        if (is_array($gallery)) {
            $contexts[] = admin_mutation_public_gallery_context($galleryId, gallery_public_url($gallery));
            $parentId = (int) ($gallery['parent_id'] ?? 0);
            $parent = $parentId > 0 ? find_gallery($parentId, true) : null;
            $contexts[] = admin_mutation_public_gallery_context($parentId, is_array($parent) ? gallery_public_url($parent) : url_for('home'));
        }
        $payload = admin_mutation_success_envelope('',
            is_array($gallery)
                ? admin_mutation_descriptor('gallery.flight_route_resolve', 'gallery', 'update', [$galleryId])
                : admin_mutation_descriptor('navigation_data.refresh', 'navigation_data', 'refresh'),
            null, $contexts, []);
        $payload['state'] = $outcome['state'];
        navigation_data_json_response($payload);
    } catch (Throwable $exception) {
        admin_log_event('warning', 'flight_map.route_navdata_failed', 'Background route navigation-data refresh failed.', [
            'gallery_id' => $galleryId, 'exception_class' => $exception::class,
        ]);
        navigation_data_json_response(['ok' => false, 'message' => t('admin.dashboard.navdata_refresh_unavailable', 'Navigation data could not be refreshed. The gallery can still be saved.')], 503);
    }
}

/**
 * Look up a single nav identifier for route visualization UI.
 *
 * The lookup endpoint is local-only. SimBrief-generated maps already store
 * coordinates from the imported OFP and do not need live provider access.
 */
function cms_navdata_lookup(): void
{
    if (request_method() !== 'GET') {
        navigation_data_json_response([
            'ok' => false,
            'error' => 'GET required.',
        ], 405);
        return;
    }

    $ident = navigation_data_normalize_ident((string) ($_GET['ident'] ?? ''));
    if ($ident === '' || strlen($ident) < 2) {
        navigation_data_json_response([
            'ok' => false,
            'error' => 'Provide an airport, fix, VOR, or NDB identifier.',
        ], 400);
        return;
    }

    $point = navigation_data_resolve_ident($ident, [
        'allow_remote' => false,
    ]);
    if ($point === null) {
        navigation_data_json_response([
            'ok' => false,
            'ident' => $ident,
            'error' => 'Navigation point was not found in available providers.',
        ], 404);
        return;
    }

    navigation_data_json_response([
        'ok' => true,
        'point' => $point,
        'providers' => navigation_data_status(),
    ]);
}

/**
 * Legacy OAuth helper retained for installs that applied the earlier prototype.
 */
function cms_admin_navigraph_connect(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();

    try {
        redirect_to(navigation_data_navigraph_authorization_url());
    } catch (Throwable $exception) {
        admin_log_event('warning', 'navigation_data.navigraph_connect_failed', 'Navigraph connection could not be started.', [
            'exception' => $exception->getMessage(),
        ]);
        flash_message('admin_notice', t('admin.dashboard.notice_navigraph_connect_failed', 'Navigraph connection could not be started: {error}', [
            'error' => $exception->getMessage(),
        ]));
        redirect_to(url_for('admin_navdata'));
    }
}

/**
 * Legacy OAuth callback retained for installs that applied the earlier prototype.
 */
function cms_admin_navigraph_callback(): void
{
    require_admin();

    $error = trim((string) ($_GET['error'] ?? ''));
    if ($error !== '') {
        flash_message('admin_notice', t('admin.dashboard.notice_navigraph_callback_failed', 'Navigraph login failed: {error}', [
            'error' => $error,
        ]));
        redirect_to(url_for('admin_navdata'));
    }

    $code = trim((string) ($_GET['code'] ?? ''));
    $state = trim((string) ($_GET['state'] ?? ''));
    if ($code === '' || $state === '') {
        flash_message('admin_notice', t('admin.dashboard.notice_navigraph_callback_missing', 'Navigraph login did not return the expected code and state.'));
        redirect_to(url_for('admin_navdata'));
    }

    try {
        navigation_data_navigraph_exchange_code($code, $state);
        $packageResult = [];
        try {
            $packageResult = navigation_data_navigraph_refresh_packages();
        } catch (Throwable $packageException) {
            admin_log_event('warning', 'navigation_data.navigraph_package_refresh_failed', 'Navigraph package metadata refresh failed after login.', [
                'exception' => $packageException->getMessage(),
            ]);
        }
        admin_log_event('info', 'navigation_data.navigraph_connected', 'Navigraph account linked for navigation data enhancement.', $packageResult);
        flash_message('admin_notice', t('admin.dashboard.notice_navigraph_connected', 'Navigraph account linked. Route lookup will still fall back to local data when needed.'));
    } catch (Throwable $exception) {
        admin_log_event('warning', 'navigation_data.navigraph_callback_failed', 'Navigraph callback failed.', [
            'exception' => $exception->getMessage(),
        ]);
        flash_message('admin_notice', t('admin.dashboard.notice_navigraph_callback_failed', 'Navigraph login failed: {error}', [
            'error' => $exception->getMessage(),
        ]));
    }

    redirect_to(url_for('admin_navdata'));
}

/**
 * Legacy disconnect helper retained for installs that applied the earlier prototype.
 */
function cms_admin_navigraph_disconnect(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();

    navigation_data_navigraph_disconnect();
    admin_log_event('info', 'navigation_data.navigraph_disconnected', 'Navigraph account disconnected for this admin session.');
    flash_message('admin_notice', t('admin.dashboard.notice_navigraph_disconnected', 'Navigraph disconnected for this admin session. Local navigation data remains available.'));
    redirect_to(url_for('admin_navdata'));
}

/**
 * Legacy metadata refresh helper retained for installs that applied the earlier prototype.
 */
function cms_admin_navigraph_refresh(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();

    try {
        $result = navigation_data_navigraph_refresh_packages();
        admin_log_event('info', 'navigation_data.navigraph_refreshed', 'Navigraph package metadata refreshed.', $result);
        flash_message('admin_notice', t('admin.dashboard.notice_navigraph_refreshed', 'Navigraph package metadata refreshed. AIRAC cycle: {cycle}. Status: {status}.', [
            'cycle' => (string) ($result['cycle'] ?? ''),
            'status' => (string) ($result['status'] ?? ''),
        ]));
    } catch (Throwable $exception) {
        admin_log_event('warning', 'navigation_data.navigraph_refresh_failed', 'Navigraph package metadata refresh failed.', [
            'exception' => $exception->getMessage(),
        ]);
        flash_message('admin_notice', t('admin.dashboard.notice_navigraph_refresh_failed', 'Navigraph refresh failed: {error}', [
            'error' => $exception->getMessage(),
        ]));
    }

    redirect_to(url_for('admin_navdata'));
}
