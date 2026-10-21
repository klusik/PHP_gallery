<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/admin_galleries_edit_page/archive_media.php
 * Module Type: Controller
 * Purpose: Handle independent opt-in media ZIP preferences in the editor.
 * Responsibilities: Validate CSRF-guarded gallery/item identities, delegate
 *   persistence, and return a canonical Admin mutation or safe redirect.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Controllers;

/**
 * Save one gallery or attached original ZIP preference.
 *
 * Invoked only after the editor's require_admin and verify_csrf guards. Item
 * identity is verified against the current physical owner's actual source
 * index, so a forged ID cannot create an arbitrary cross-gallery preference.
 *
 * @param array{id:int|string,folder_path:string} $gallery Authorized gallery.
 * @return void Send canonical JSON feedback or redirect to the Media subtab.
 */
function admin_edit_gallery_handle_archive_media_action(array $gallery): void
{
    require_once __DIR__ . '/../../services/gallery_archive_media.php';
    $galleryId = (int) $gallery['id'];
    $kind = (string) ($_POST['archive_media_kind'] ?? '');
    $scope = (string) ($_POST['archive_media_scope'] ?? '');
    $itemId = (string) ($_POST['archive_media_item_id'] ?? '');
    $allowedText = (string) ($_POST['archive_media_enabled'] ?? '');
    $ajax = admin_wants_json();
    $target = \Gallery\Core\url_for('admin_edit_gallery', [
        'id' => $galleryId,
        'tab' => 'admin-edit-media',
        'media_section' => $kind === 'music' ? 'music' : 'pdf',
    ]);
    $descriptor = \Gallery\Core\admin_mutation_descriptor(
        'gallery.archive_media.' . $scope,
        'gallery',
        'update',
        [$galleryId],
    );
    try {
        if (
            (int) ($_POST['id'] ?? 0) !== $galleryId
            || !in_array($kind, ['pdf', 'music'], true)
            || !in_array($scope, ['gallery', 'item'], true)
            || !in_array($allowedText, ['0', '1'], true)
        ) {
            throw new \InvalidArgumentException('Invalid archive preference.');
        }
        $allowed = $allowedText === '1';
        if ($scope === 'gallery') {
            \Gallery\Services\gallery_archive_media_save_preference(
                $kind,
                'gallery',
                $galleryId,
                $allowed,
            );
        } else {
            $found = false;
            if ($kind === 'pdf') {
                if ($itemId === 'ofp') {
                    $found = \Gallery\Services\simbrief_ofp_local_pdf_path(
                        $gallery,
                    ) !== null;
                } else {
                    $found = \Gallery\Services\gallery_pdf_documents_read(
                        $gallery,
                        $itemId,
                    ) !== null;
                }
            } else {
                $playlist = \Gallery\Services\gallery_music_playlist(
                    $gallery,
                    true,
                );
                foreach ($playlist['tracks'] as $track) {
                    if ((string) $track['id'] === $itemId) {
                        $found = true;
                        break;
                    }
                }
                foreach ($playlist['sources'] ?? [] as $source) {
                    if ((string) $source['id'] === $itemId) {
                        $found = true;
                        break;
                    }
                }
            }
            if (!$found) {
                throw new \InvalidArgumentException(
                    'Original media item is no longer attached.',
                );
            }
            \Gallery\Services\gallery_archive_media_save_item(
                $kind,
                $galleryId,
                $itemId,
                $allowed,
            );
        }
        $message = \Gallery\Services\t(
            'download.archive.saved',
            'Archive inclusion preference saved.',
        );
        $result = \Gallery\Core\admin_mutation_success_envelope(
            $message,
            $descriptor,
            \Gallery\Core\admin_mutation_panel_metadata(
                'gallery-edit',
                $target,
                true,
            ),
            [\Gallery\Core\admin_mutation_public_gallery_context(
                $galleryId,
                \Gallery\Core\gallery_public_url($gallery),
            )],
            ['redirect_url' => $target],
        );
    } catch (\Throwable $error) {
        $message = \Gallery\Services\t(
            'download.archive.save_failed',
            'Archive preference could not be saved. Reload the media editor.',
        );
        $result = \Gallery\Core\admin_mutation_error_envelope(
            $message,
            'archive_preference_failed',
            $descriptor,
        );
        if ($ajax) {
            http_response_code(422);
        }
    }
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo json_encode(
            $result,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        return;
    }
    \Gallery\Core\flash_message('admin_notice', $message);
    \Gallery\Core\redirect_to($target);
}
