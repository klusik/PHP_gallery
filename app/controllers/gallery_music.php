<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/gallery_music.php
 * Module Type: Controller
 * Purpose: Prepare gallery music presentation and protected audio responses.
 * Responsibilities: Enforce HTTP boundaries and return canonical Admin
 *     mutations.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Controllers;

require_once __DIR__ . '/../services/gallery_music.php';
require_once __DIR__ . '/../views/gallery_music.php';

/**
 * Prepare an authorized gallery playlist with same-origin protected track URLs.
 * @param array{id:int, folder_path:string} $gallery Already authorized
 *     physical gallery.
 * @param bool $editor Whether to include authenticated attachment management.
 * @return array{
 *     gallery_id:int,
 *     editor:bool,
 *     action_url:string,
 *     csrf_html:string,
 *     revision:string,
 *     enabled:bool,
 *     error:string,
 *     tracks:list<array{
 *         id:string,
 *         title:string,
 *         filename:string,
 *         mime:string,
 *         size:int,
 *         sha256:string,
 *         hidden:bool, downloadable:bool,
 *         url:string,
 *         download_url:string
 *     }>
 * } Escaped-by-view presentation with no local paths.
 */
function gallery_music_view_model(array $gallery, bool $editor = false): array
{
    $state = ['revision' => '', 'enabled' => false, 'tracks' => []];
    $error = '';
    try {
        $state = \Gallery\Services\gallery_music_playlist($gallery);
    } catch (\Throwable) {
        $error = \Gallery\Services\t('music.failed');
    }
    return gallery_music_present_playlist(
        (int) $gallery['id'], $editor, $state, $error
    );
}

/**
 * Prepare protected URLs and existing controls for a physical or merged playlist.
 * @param int $galleryId Physical ID or negative Smart Gallery presentation ID.
 * @param bool $editor Whether authenticated physical-gallery forms are available.
 * @param array{revision:string,enabled:bool,tracks:list<array{id:string,
 *     source_gallery_id?:int,title:string,filename:string,mime:string,size:int,
 *     sha256:string,hidden:bool,downloadable:bool}>} $state Prepared snapshot;
 *     merged records retain their physical source IDs for delivery.
 * @param string $error Localized optional-storage failure, otherwise empty.
 * @return array{
 *     gallery_id:int,
 *     editor:bool,
 *     action_url:string,
 *     csrf_html:string,
 *     revision:string,
 *     enabled:bool,
 *     error:string,
 *     tracks:list<array{
 *         id:string,
 *         title:string,
 *         filename:string,
 *         mime:string,
 *         size:int,
 *         sha256:string,
 *         hidden:bool, downloadable:bool,
 *         url:string,
 *         download_url:string
 *     }>
 * } Escaped-by-view presentation with no local paths.
 */
function gallery_music_present_playlist(
    int $galleryId,
    bool $editor,
    array $state,
    string $error = ''
): array {
    $base = rtrim(\Gallery\Core\request_script_base_path(), '/') . '/index.php';
    $tracks = [];
    $audience = \Gallery\Core\admin_anonymous_preview_active()
        ? ['view_as' => 'anonymous'] : [];
    foreach ($state['tracks'] as $track) {
        if (!$editor && !\Gallery\Services\gallery_music_track_visible($track)) {
            continue;
        }
        $sourceId = $track['source_gallery_id'] ?? $galleryId;
        $originalId = $track['id'];
        if (isset($track['source_gallery_id'])) {
            $track['id'] = $sourceId . ':' . $originalId;
            unset($track['source_gallery_id']);
        }
        $url = $base . '?' . http_build_query([
            'page' => 'gallery_music',
            'id' => $sourceId,
            'track' => $originalId,
        ] + $audience, '', '&', PHP_QUERY_RFC3986);
        $tracks[] = $track + [
            'url' => $url,
            'download_url' => $track['downloadable']
                ? $url . '&download=1' : '',
        ];
    }
    return [
        'gallery_id' => $galleryId,
        'editor' => $editor,
        'action_url' => $galleryId > 0 ? $base . '?' . http_build_query([
            'page' => 'admin_edit_gallery', 'id' => $galleryId,
        ], '', '&', PHP_QUERY_RFC3986) : '',
        'csrf_html' => $editor ? \Gallery\Core\csrf_field() : '',
        'revision' => $state['revision'],
        'enabled' => $state['enabled'],
        'error' => $error,
        'tracks' => $tracks,
    ];
}

/**
 * Prepare an independent Smart Gallery player without creating music storage.
 * @param int $smartGalleryId Authorized positive Smart Gallery identity.
 * @param list<array{id:int,folder_path:string}> $sources Complete authorized
 *     physical sources containing music, supplied by the Smart Gallery service.
 * @param bool $enabled Saved Smart Gallery slideshow preference, initially off.
 * @return array{
 *     gallery_id:int,
 *     editor:bool,
 *     action_url:string,
 *     csrf_html:string,
 *     revision:string,
 *     enabled:bool,
 *     error:string,
 *     tracks:list<array{
 *         id:string,
 *         title:string,
 *         filename:string,
 *         mime:string,
 *         size:int,
 *         sha256:string,
 *         hidden:bool, downloadable:bool,
 *         url:string,
 *         download_url:string
 *     }>
 * } Escaped-by-view presentation with no local paths.
 */
function gallery_music_smart_view_model(
    int $smartGalleryId,
    array $sources,
    bool $enabled
): array {
    $state = \Gallery\Services\gallery_music_merge_playlists($sources);
    $state['enabled'] = $enabled;
    // Physical IDs are positive; a negative presentation ID prevents a Smart
    // Gallery from sharing controls with an unrelated physical gallery.
    return gallery_music_present_playlist(-$smartGalleryId, false, $state);
}

/**
 * Render the independently replaceable gallery music editor card.
 * @param array{id:int,folder_path:string} $gallery Authorized physical gallery.
 * @return string Music manager HTML with CSRF/revision controls, or empty
 *     when the global music capability is off.
 */
function admin_gallery_music_html(array $gallery): string
{
    if (!\Gallery\Services\feature_capability_effective_enabled(
        'gallery_music'
    )) {
        return '';
    }
    ob_start();
    \Gallery\Views\view_render_gallery_music(
        gallery_music_view_model($gallery, true)
    );
    return (string) ob_get_clean();
}

/**
 * Handle a playlist action after the editor's administrator and CSRF guards.
 * @param array{id:int,folder_path:string} $gallery Authorized physical gallery.
 * @return void Emit canonical AJAX completion or the direct-page redirect.
 */
function admin_edit_gallery_handle_music_action(array $gallery): void
{
    $id = (int) $gallery['id'];
    $action = (string) ($_POST['music_action'] ?? '');
    $editUrl = \Gallery\Core\url_for('admin_edit_gallery', [
        'id' => $id,
        'tab' => 'admin-edit-documents',
    ]);
    $descriptor = \Gallery\Core\admin_mutation_descriptor(
        'gallery.music.' . $action, 'gallery', 'update', [$id]
    );
    $ajax = admin_wants_json();
    try {
        if ((int) ($_POST['id'] ?? 0) !== $id) {
            throw new \RuntimeException(\Gallery\Services\t('music.conflict'));
        }
        $source = '';
        $filename = '';
        if ($action === 'upload') {
            $upload = $_FILES['music_file'] ?? null;
            if (!is_array($upload)
                || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_string($upload['tmp_name'] ?? null)
                || !is_uploaded_file($upload['tmp_name'])
                || !is_string($upload['name'] ?? null)) {
                throw new \RuntimeException(
                    \Gallery\Services\t('music.invalid_file')
                );
            }
            $source = $upload['tmp_name'];
            $filename = $upload['name'];
        }
        $outcome = \Gallery\Services\gallery_music_mutate(
            $gallery,
            (string) ($_POST['music_revision'] ?? ''), $action,
            (string) ($_POST['track_id'] ?? ''), $source, $filename,
            (string) ($_POST['music_enabled'] ?? '') === '1',
            (string) ($_POST['music_download_enabled'] ?? '') === '1'
        );
        \Gallery\Services\gallery_attachment_storage_changed();
        $message = \Gallery\Services\t('music.saved');
        $result = \Gallery\Core\admin_mutation_success_envelope(
            $message,
            $descriptor,
            \Gallery\Core\admin_mutation_panel_metadata(
                'gallery-edit', $editUrl, true
            ),
            [\Gallery\Core\admin_mutation_public_gallery_context(
                $id, \Gallery\Core\gallery_public_url($gallery)
            )],
            ['redirect_url' => $editUrl]
        );
        $result['panel_html'] = admin_gallery_music_html($gallery);
        $result['music_revision'] = $outcome['revision'];
        $result['track_id'] = $outcome['track_id'];
    } catch (\Throwable $error) {
        $allowed = ['music.conflict', 'music.invalid_file', 'music.quota'];
        $message = \Gallery\Services\t('music.failed');
        foreach ($allowed as $key) {
            if ($error->getMessage() === \Gallery\Services\t($key)) {
                $message = $error->getMessage();
                break;
            }
        }
        if ($ajax) http_response_code(422);
        $result = \Gallery\Core\admin_mutation_error_envelope(
            $message, 'music_operation_failed', $descriptor
        );
    }
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo json_encode(
            $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        return;
    }
    \Gallery\Core\flash_message('admin_notice', $message);
    \Gallery\Core\redirect_to($editUrl);
}

/**
 * Serve an original audio track after canonical inherited gallery
 *     authorization.
 * @return void Emit protected audio/download bytes, admin revision JSON, or
 *     refusal.
 */
function cms_gallery_music(): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    clear_response_cache_headers();
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: same-origin');
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        http_response_code(405);
        return;
    }
    $gallery = \Gallery\Services\find_gallery((int) ($_GET['id'] ?? 0));
    $user = \Gallery\Core\current_user();
    $admin = is_array($user) && ($user['role'] ?? '') === 'admin'
        && !\Gallery\Core\admin_anonymous_preview_active()
        && !\Gallery\Services\current_user_is_known_under_18();
    if ($gallery && $admin && (string) ($_GET['playlist'] ?? '') === '1') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $state = \Gallery\Services\gallery_music_playlist($gallery);
            echo json_encode([
                'ok' => true,
                'revision' => $state['revision'],
            ], JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            http_response_code(503);
            echo json_encode([
                'ok' => false,
                'message' => \Gallery\Services\t('music.failed'),
            ]);
        }
        return;
    }
    $document = null;
    $allowed = $gallery && ($admin
        || \Gallery\Services\visitor_can_access_gallery_without_admin_bypass(
            $gallery
        ));
    if ($allowed) {
        try {
            $document = \Gallery\Services\gallery_music_track(
                $gallery, (string) ($_GET['track'] ?? '')
            );
        } catch (\Throwable) {
            $document = null;
        }
    }
    if ($document !== null && !$admin
        && !\Gallery\Services\gallery_music_track_visible($document['track'])) {
        $document = null;
    }
    $download = (string) ($_GET['download'] ?? '') === '1';
    if ($download && $document !== null
        && !$document['track']['downloadable']) {
        $document = null;
    }
    $handle = $document !== null ? @fopen($document['path'], 'rb') : false;
    if ($document === null || $handle === false) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        if ($method !== 'HEAD') echo 'Not found';
        return;
    }
    try {
        $size = $document['track']['size'];
        // Without validators, If-Range means a complete representation. HEAD
        // reports GET metadata without interpreting its ignored Range field.
        $rangeHeader = $method === 'GET' && !isset($_SERVER['HTTP_IF_RANGE'])
            ? (string) ($_SERVER['HTTP_RANGE'] ?? '') : '';
        $range = \Gallery\Services\gallery_music_byte_range(
            $rangeHeader, $size
        );
        header('Accept-Ranges: bytes');
        if ($range === null) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            header('Content-Length: 0');
            return;
        }
        header('Content-Type: ' . $document['track']['mime']);
        $disposition = $download ? 'attachment' : 'inline';
        header('Content-Disposition: ' . $disposition
            . '; filename="audio"; filename*=UTF-8\'\''
            . rawurlencode($document['track']['filename']));
        $length = $range['end'] - $range['start'] + 1;
        header('Content-Length: ' . $length);
        if ($range['partial']) {
            http_response_code(206);
            header('Content-Range: bytes ' . $range['start']
                . '-' . $range['end'] . '/' . $size);
        }
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        if ($method === 'GET' && fseek($handle, $range['start']) === 0) {
            $output = fopen('php://output', 'wb');
            if ($output !== false) {
                stream_copy_to_stream($handle, $output, $length);
                fclose($output);
            }
        }
    } finally {
        fclose($handle);
    }
}
