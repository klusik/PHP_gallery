<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/admin_gallery_quick_access.php
 * Module Type: Controller
 * Purpose: Provide the authenticated one-gallery password HTTP boundary.
 * Responsibilities:
 *   - Authenticate and normalize one-gallery password requests.
 *   - Return canonical mutation envelopes with a bounded credential-free row state.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Controllers;

use Throwable;
use Gallery\Services\GalleryEditConflict;
use Gallery\Services\GalleryEditUnavailable;
use Gallery\Services\GalleryQuickAccessRefusal;
use function Gallery\Core\admin_mutation_descriptor;
use function Gallery\Core\admin_mutation_error_envelope;
use function Gallery\Core\admin_mutation_success_envelope;
use function Gallery\Core\admin_mutation_public_gallery_context;
use function Gallery\Core\admin_wants_json;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\verify_csrf;
use function Gallery\Core\url_for;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\flash_message;
use function Gallery\Core\redirect_to;
use function Gallery\Services\find_gallery;
use function Gallery\Services\gallery_editor_change_own_password;
use function Gallery\Services\gallery_editor_quick_access_state;
use function Gallery\Services\t;

require_once dirname(__DIR__) . '/services/gallery_editor_quick_access.php';

/**
 * Save or remove a current gallery password through the narrow domain use case.
 *
 * @return void
 */
function cms_admin_gallery_password(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();
    $galleryId = max(0, (int) ($_POST['gallery_id'] ?? 0));
    $descriptor = admin_mutation_descriptor('gallery.password', 'gallery', 'update', [$galleryId]);
    $fallback = url_for('admin', ['dashboard_tab' => 'galleries']);
    $status = 200;
    $bufferLevel = ob_get_level();
    ob_start();
    try {
        $gallery = find_gallery($galleryId, true);
        if (!$gallery) throw new GalleryQuickAccessRefusal(t('admin.gallery_not_found', 'Gallery not found.'));
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        if (!in_array($action, ['enable', 'disable'], true)) throw new GalleryQuickAccessRefusal(t('gallery.password_save_failed', 'Gallery password could not be saved.'));
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $updated = gallery_editor_change_own_password($gallery, $_POST['edit_revision'] ?? null, $action === 'enable', $password);
        $result = admin_mutation_success_envelope(
            t('admin.gallery_editor.gallery_saved', 'Gallery saved.'), $descriptor, null,
            [admin_mutation_public_gallery_context(0, url_for('home')), admin_mutation_public_gallery_context($galleryId, gallery_public_url($updated))], ['redirect_url' => $fallback]
        );
        $result['gallery_state'] = gallery_editor_quick_access_state($updated);
    } catch (Throwable $exception) {
        $status = $exception instanceof GalleryEditConflict ? 409 : 422;
        // Only approved domain messages are exposed; unexpected errors must not leak credentials or storage details.
        $message = $exception instanceof GalleryQuickAccessRefusal || $exception instanceof GalleryEditConflict || $exception instanceof GalleryEditUnavailable
            ? $exception->getMessage() : t('gallery.password_save_failed', 'Gallery password could not be saved.');
        $result = admin_mutation_error_envelope($message, 'gallery_password_failed', $descriptor);
    }
    // Discard incidental service output without logging it: this request carries a credential.
    while (ob_get_level() > $bufferLevel) ob_end_clean();
    if (admin_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        http_response_code($status);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }
    flash_message('admin_notice', (string) $result['message']);
    redirect_to($fallback);
}
