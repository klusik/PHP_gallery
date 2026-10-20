<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_edit_page/controller.php
 * Module Type: Controller
 *
 * Purpose:
 *   Coordinates the gallery edit page request from guard to rendered response.
 *
 * Responsibilities:
 *   - Authorize the request and resolve the gallery being edited
 *   - Answer the read-only JSON panel endpoints before any page rendering
 *   - Hand POST requests to the action handlers and otherwise render the tabs in order
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
 *   - Loaded by app/controllers/admin_galleries_edit_page.php; do not require this file directly.
 *   - Identity, Access, and Display share the guarded settings form; Media is
 *     rendered after it with explicit native form ownership where needed.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Core\csrf_field;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Services\feature_capability_effective_enabled;
use function Gallery\Services\find_gallery;
use function Gallery\Services\gallery_images;
use function Gallery\Services\gallery_editor_image_inventory;
use function Gallery\Services\gallery_editor_image_page;
use function Gallery\Core\url_for;
use function Gallery\Views\view_render_admin_gallery_images_loading;
use function Gallery\Views\view_render_admin_gallery_images_placeholder;
use function Gallery\Services\gallery_edit_revision;
use function Gallery\Services\media_renamer_normalize_pattern;
use function Gallery\Services\t;
use function Gallery\Views\view_render_admin_gallery_editor_form_close;
use function Gallery\Views\view_render_admin_gallery_editor_form_open;

/**
 * Authenticate the editor request and render fields and revision from the same gallery snapshot.
 *
 * @return void Emits a complete editor or delegates its POST/panel response.
 * @author Rudolf Klusal
 */
function cms_admin_edit_gallery(): void
{
    require_admin();
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery(
        (int) ($_GET['id'] ?? $_POST['id'] ?? $_POST['gallery_id'] ?? 0),
    );
    if (!$gallery) {
        cms_not_found();
        return;
    }
    if (admin_edit_gallery_handle_json_panel_request($gallery)) {
        return;
    }

    // Schema and feature readiness is resolved once so every phase agrees.
    $capabilities = admin_edit_gallery_capabilities();

    if (request_method() === 'POST') {
        admin_edit_gallery_handle_post($gallery, $capabilities);
        return;
    }
    if ((string) ($_GET['tab'] ?? '') === 'admin-edit-documents') {
        $redirectParams = [
            'id' => (int) $gallery['id'],
            'tab' => 'admin-edit-media',
            'media_section' => 'pdf',
        ];
        if ((string) ($_GET['panel'] ?? '') === '1') {
            $redirectParams['panel'] = '1';
        }
        $language = trim((string) ($_GET['lang'] ?? ''));
        if ($language !== '') {
            $redirectParams['lang'] = $language;
        }
        \Gallery\Core\redirect_to(url_for(
            'admin_edit_gallery',
            $redirectParams,
        ));
        return;
    }

    // Drawer requests opt into deferred image work; ordinary navigation retains
    // the supported complete/no-JavaScript response without changing its URL.
    $deferredImages = (string) ($_GET['panel'] ?? '') === '1';
    $images = $deferredImages
        ? []
        : gallery_images((int) $gallery['id'], false);
    $imageCount = $deferredImages
        ? gallery_editor_image_inventory((int) $gallery['id'])['total']
        : count($images);
    render_header(t('admin.gallery_editor.page_title'));
    admin_edit_gallery_render_notices($gallery, $capabilities);
    // $activeEditTab stores the tab selected by redirect query state before JavaScript reads the URL hash.
    $activeEditTab = admin_edit_gallery_tab_id((string) ($_GET['tab'] ?? ''))
    ?: 'admin-edit-identity';
    admin_edit_gallery_render_overview(
        $gallery,
        $imageCount,
        $activeEditTab,
        $capabilities,
        $deferredImages,
    );
    if ($deferredImages) {
        view_render_admin_gallery_images_loading([
            'gallery_id' => (int) $gallery['id'],
            'total' => $imageCount,
            'requested' => $activeEditTab === 'admin-edit-images',
            'endpoint' => url_for('admin_edit_gallery', [
                'id' => (int) $gallery['id'],
                'action' => 'images_batch',
            ]),
            'labels' => [
                'preparing' => t(
                    'admin.gallery_editor.images_preparing',
                    'Preparing photos…',
                ),
                'progress' => t(
                    'admin.gallery_editor.images_progress',
                    'Photos: {current} / {total}',
                ),
                'ready' => t(
                    'admin.gallery_editor.images_ready',
                    'Photos ready.',
                ),
                'failed' => t(
                    'admin.gallery_editor.images_failed',
                    '{count} thumbnails failed. Retry images.',
                ),
                'error' => t(
                    'admin.gallery_editor.images_load_error',
                    'Photos could not be loaded. Other settings remain available.',
                ),
                'retry' => t(
                    'admin.gallery_editor.images_retry',
                    'Retry images',
                ),
            ],
        ]);
    }

    // Identity, Access, and Display share one guarded gallery-settings form.
    view_render_admin_gallery_editor_form_open([
        'csrf_html' => csrf_field(),
        'gallery_id' => (int) $gallery['id'],
        'edit_revision' => gallery_edit_revision($gallery),
    ]);
    admin_edit_gallery_render_identity_tab(
        $gallery,
        $activeEditTab,
        $capabilities,
    );
    admin_edit_gallery_render_access_tab(
        $gallery,
        $activeEditTab,
        $capabilities,
    );
    admin_edit_gallery_render_display_tab(
        $gallery,
        $activeEditTab,
        $capabilities,
    );
    view_render_admin_gallery_editor_form_close([
        'save_label' => t('admin.gallery_editor.save_gallery', 'Save gallery'),
        'help' => t(
            'admin.gallery_editor.savebar_help',
            'Saves gallery settings, including presentation defaults.',
        ),
    ]);

    // Media owns independent forms; its Artwork inputs associate with settings
    // using their native form attribute after the shared form closes.
    if ($deferredImages && $imageCount > 0) {
        view_render_admin_gallery_images_placeholder([
            'fallback_url' => url_for('admin_edit_gallery', [
                'id' => (int) $gallery['id'],
                'tab' => 'admin-edit-images',
            ]),
            'label' => t(
                'admin.gallery_editor.images_preparing',
                'Preparing photos…',
            ),
        ]);
    } else {
        admin_edit_gallery_render_images_tab($gallery, $images, $activeEditTab);
    }
    admin_edit_gallery_render_documents_tab($gallery, $activeEditTab);
    admin_edit_gallery_render_organizer_tab($gallery, $activeEditTab);
    admin_edit_gallery_render_renamer_tab(
        $gallery,
        $activeEditTab,
        $capabilities,
        $deferredImages,
    );
    admin_edit_gallery_render_api_tab($gallery, $activeEditTab, $capabilities);
    render_admin_image_reorder_script();
    render_admin_devmode_panel();
    render_footer();
}

/**
 * Answer the read-only JSON panel endpoints served by the gallery editor route.
 *
 * These run before capability resolution and page rendering because they return
 * a panel fragment rather than the editor page.
 *
 * @param array<string, mixed> $gallery Gallery row being edited.
 * @return bool True when a JSON response was sent and the caller must stop.
 */
function admin_edit_gallery_handle_json_panel_request(array $gallery): bool
{
    if (request_method() !== 'GET' || !admin_wants_json()) {
        return false;
    }
    if ((string) ($_GET['action'] ?? '') === 'images_batch') {
        admin_edit_gallery_images_batch_response($gallery);
        return true;
    }
    if (
        (string) ($_GET['tab'] ?? '') === 'admin-edit-renamer'
        && function_exists(
            'Gallery\\Controllers\\admin_media_renamer_render_gallery_panel_html',
        )
    ) {
        csrf_field();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $pattern = media_renamer_normalize_pattern(
            (string) ($_GET['renamer_pattern'] ?? ''),
        );
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'message' => '',
            'panel_html' => admin_media_renamer_render_gallery_panel_html(
                $gallery,
                $pattern,
            ),
        ]);
        return true;
    }
    if (
        (string) ($_GET['action'] ?? '') === 'metadata_organizer_preview_batch'
    ) {
        if (!feature_capability_effective_enabled('metadata_organizer')) {
            admin_gallery_metadata_organizer_json_response([
                'ok' => false,
                'error' => t(
                    'admin.features.disabled_route_message',
                    'This feature is disabled in Admin > Features: {feature}',
                    [
                        'feature' => t(
                            'admin.features.metadata_organizer.label',
                            'Metadata Organizer',
                        ),
                    ],
                ),
            ], 403);
            return true;
        }
        admin_gallery_metadata_organizer_preview_batch_response($gallery);
        return true;
    }
    return false;
}

/**
 * Send a read-only authenticated direct-image batch through the existing editor route.
 * Each request validates inventory and gallery revision before and after rendering.
 * Session writes are finished after CSRF initialization, so photo preparation
 * cannot hold the administrator's session lock while settings are saved.
 * @param array{id:int|string,folder_path:string,title?:string,cover_image_id?:int|string|null,edit_revision?:int|string|null,visibility?:string,access_mode?:string,access_listing?:int|string|null,url_path?:string|null,thumbnail_min_size?:int|string|null,thumbnail_max_size?:int|string|null} $gallery Authorized editor identity, revision, cover choice and inherited thumbnail policy.
 * @return void Emit a bounded JSON fragment or a safe retryable failure.
 */
function admin_edit_gallery_images_batch_response(array $gallery): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $offset = filter_var(
        $_GET['offset'] ?? '0',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]],
    );
    if ($offset === false) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => t('admin.gallery_editor.images_load_error'),
        ]);
        return;
    }
    csrf_field();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $expectedRevision = $_GET['revision'] ?? '';
    if (
        !is_string($expectedRevision)
        || (
            $expectedRevision !== ''
            && preg_match('/^[a-f0-9]{64}$/D', $expectedRevision) !== 1
        )
    ) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => t('admin.gallery_editor.images_load_error'),
        ]);
        return;
    }
    $bufferLevel = ob_get_level();
    try {
        $inventory = gallery_editor_image_inventory((int) $gallery['id']);
        $revision = hash(
            'sha256',
            gallery_edit_revision($gallery) . $inventory['revision'],
        );
        $expected = $expectedRevision;
        if (
            (
                $offset > 0
                && $expected === ''
            )
            || (
                $expected !== ''
                && !hash_equals($revision, $expected)
            )
            || $offset > $inventory['total']
        ) {
            http_response_code(409);
            echo json_encode([
                'ok' => false,
                'message' => t('admin.gallery_editor.images_load_error'),
            ]);
            return;
        }
        $images = gallery_editor_image_page((int) $gallery['id'], $offset);
        ob_start();
        admin_edit_gallery_render_images_tab($gallery, $images, '', true, true);
        $rowsHtml = (string) ob_get_clean();
        $shellHtml = '';
        if ($offset === 0) {
            ob_start();
            admin_edit_gallery_render_images_tab($gallery, [], '', false, true);
            $shellHtml = (string) ob_get_clean();
        }
        $latest = find_gallery((int) $gallery['id'], true);
        $after = gallery_editor_image_inventory((int) $gallery['id']);
        if (
            !$latest
            || !hash_equals($revision, hash(
                'sha256',
                gallery_edit_revision($latest) . $after['revision'],
            ))
        ) {
            http_response_code(409);
            echo json_encode([
                'ok' => false,
                'message' => t('admin.gallery_editor.images_load_error'),
            ]);
            return;
        }
        $next = $offset + count($images);
        if ($next < $inventory['total'] && $images === []) {
            throw new \RuntimeException('Incomplete editor page.');
        }
        echo json_encode([
            'ok' => true,
            'gallery_id' => (int) $gallery['id'],
            'offset' => $offset,
            'next_offset' => $next,
            'total' => $inventory['total'],
            'done' => $next === $inventory['total'],
            'revision' => $revision,
            'rows_html' => $rowsHtml,
            'shell_html' => $shellHtml,
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (\Throwable) {
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'message' => t('admin.gallery_editor.images_load_error'),
        ]);
    }
}
