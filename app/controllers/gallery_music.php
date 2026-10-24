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
require_once __DIR__ . '/../services/music_catalog.php';
require_once __DIR__ . '/../views/gallery_music.php';

/** Bound the exception causes retained in a music failure diagnostic.
 * Type: int. Units: exceptions. Scope: one authenticated music failure.
 * Consumers: admin_gallery_music_failure.
 * Rationale: four causes retain wrapped persistence failures without an
 * unbounded trace or any raw exception message.
 */
const GALLERY_MUSIC_DIAGNOSTIC_CAUSES = 4;

/** Size the non-secret reference shared by the response and Admin log.
 * Type: int. Units: random bytes. Scope: one music failure reference.
 * Consumers: admin_gallery_music_failure.
 * Rationale: twelve bytes produce a 24-hex lookup key that fits the existing
 * 26-character Admin request_id column. It grants no access to music or logs.
 */
const GALLERY_MUSIC_DIAGNOSTIC_REFERENCE_BYTES = 12;

/** Explain and record an authenticated music failure without private payloads.
 * @param int $galleryId Authorized physical gallery identity.
 * @param string $action Requested music action; unknown values become unknown.
 * @param string $stage Owner-controlled read, mutation or response stage.
 * @param int $selectionId Selected song/album identity, or zero when unused.
 * @param \Throwable $error Original failure, including bounded nested causes.
 * @param bool $persisted Whether the playlist mutation already returned success.
 * @return array{message:string,reason:string,reference:string} Localized safe
 *     explanation and a reference sent through the existing log pipeline.
 */
function admin_gallery_music_failure(
    int $galleryId,
    string $action,
    string $stage,
    int $selectionId,
    \Throwable $error,
    bool $persisted = false,
): array {
    $reason = 'operation_failed';
    $messageKey = $persisted ? 'music.refresh_failed' : 'music.catalog_failed';
    $known = [
        'music.original_unavailable' => ['original_unavailable', 'music.original_unavailable'],
        'No verified original is available for this song.'
            => ['original_unavailable', 'music.original_unavailable'],
        'music.source_reconcile_required'
            => ['catalog_sync_required', 'music.catalog_sync_required'],
        'Music repository is unavailable.'
            => ['repository_unavailable', 'music.catalog_failed'],
        'Music repository is unsafe.'
            => ['repository_unsafe', 'music.catalog_failed'],
        'Music repository staging failed.'
            => ['repository_write_failed', 'music.catalog_failed'],
        'Music repository publication failed.'
            => ['repository_write_failed', 'music.catalog_failed'],
        'Music repository copy verification failed.'
            => ['original_verification_failed', 'music.original_unavailable'],
    ];
    foreach (['music.conflict', 'music.invalid_file', 'music.quota'] as $key) {
        $known[\Gallery\Services\t($key)] = [$key, $key];
    }
    $causes = [];
    $root = str_replace('\\', '/', dirname(__DIR__, 2)) . '/';
    for ($cause = $error; $cause !== null
        && count($causes) < GALLERY_MUSIC_DIAGNOSTIC_CAUSES;
        $cause = $cause->getPrevious()) {
        if (isset($known[$cause->getMessage()])) {
            [$reason, $key] = $known[$cause->getMessage()];
            if (!$persisted) $messageKey = $key;
        } elseif ($cause instanceof \Gallery\Services\MutationSchemaUnavailableException
            || $cause instanceof \Gallery\Services\PresentationSchemaUnavailableException) {
            $reason = 'schema_unavailable';
            if (!$persisted) $messageKey = 'admin.music.error_schema';
        } elseif ($cause instanceof \PDOException) {
            $reason = 'database_error';
        } elseif ($cause instanceof \Error) {
            $reason = 'php_error';
        }
        $file = str_replace('\\', '/', $cause->getFile());
        $site = str_starts_with($file, $root)
            ? substr($file, strlen($root)) : 'external';
        $entry = [
            'class' => match (true) {
                $cause instanceof \PDOException => 'PDOException',
                $cause instanceof \TypeError => 'TypeError',
                $cause instanceof \Error => 'Error',
                $cause instanceof \RuntimeException => 'RuntimeException',
                default => 'Throwable',
            },
            'site' => preg_match('#^app/[A-Za-z0-9_./-]+\.php$#D', $site) === 1
                ? $site : 'external',
            'line' => max(0, $cause->getLine()),
        ];
        if ($cause instanceof \PDOException
            && preg_match('/^[A-Z0-9]{5}$/D', (string) $cause->getCode()) === 1) {
            $entry['sqlstate'] = (string) $cause->getCode();
        }
        $causes[] = $entry;
    }
    $reference = bin2hex(random_bytes(GALLERY_MUSIC_DIAGNOSTIC_REFERENCE_BYTES));
    $context = [
        'gallery_id' => max(0, $galleryId),
        'selection_id' => max(0, $selectionId),
        'action' => in_array($action, [
            'load', 'choices', 'music_preview', 'music_album_choices',
            'music_selection_status', 'upload', 'add_song', 'add_album',
            'settings', 'download', 'download_all', 'delete', 'previous',
            'next', 'reorder_source',
        ], true) ? $action : 'unknown',
        'stage' => in_array($stage, [
            'playlist_read', 'catalog_read', 'preview_read', 'validation',
            'mutation', 'response',
        ], true) ? $stage : 'unknown',
        'reason' => $reason,
        'persisted' => $persisted,
        'reference' => $reference,
        'causes' => $causes,
    ];
    $logged = false;
    try {
        // The existing logger owns schema readiness and best-effort persistence.
        require_once __DIR__ . '/../services/logs.php';
        $receipt = \Gallery\Services\admin_log_event_result(
            'error', 'gallery.music.operation_failed',
            'Gallery music operation failed.', $context, [
                'category' => 'media', 'subject_type' => 'gallery',
                'subject_id' => max(0, $galleryId),
                'request_id' => $reference, 'route_name' => 'admin_edit_gallery',
            ],
        );
        $logged = $receipt['ok'];
    } catch (\Throwable) {
        // Logging must preserve the original refusal, even on damaged storage.
    }
    if (!$logged) {
        @error_log('[PHP Gallery] gallery.music.operation_failed '
            . json_encode($context, JSON_UNESCAPED_SLASHES));
    }
    return [
        'reason' => $reason,
        'reference' => $reference,
        'message' => \Gallery\Services\t($messageKey) . ' '
            . \Gallery\Services\t('music.failure_reference', '', [
                'reference' => $reference,
            ]),
    ];
}

/**
 * Prepare an authorized gallery playlist with same-origin protected track URLs.
 * @param array{id:int, folder_path:string} $gallery Already authorized
 *     physical gallery.
 * @param bool $editor Whether to include authenticated attachment management.
 * @param string $query Bounded catalog query for the editor's no-JavaScript
 *     chooser fallback.
 * @return array{
 *     gallery_id:int,
 *     editor:bool,
 *     action_url:string,
 *     csrf_html:string,
 *     revision:string,
 *     write_revision:string,
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
 *         download_url:string,
 *         source_id?:string,source_type?:string,source_title?:string,
 *         source_track_id?:string
 *     }>,
 *     sources:list<array{id:string,type:string,song_id:?int,album_id:?int,
 *         title:string,downloadable:bool,hidden:bool,track_count:int,
 *         position:int,path?:string}>,
 *     unavailable:int,omitted:int,partial:bool,over_limit:bool,
 *     choices:array{revision:string,songs:list<array{id:int,title:string,
 *         track_count:int,preview_url:string,already_added:bool}>,albums:list<array{id:int,title:string,
 *         song_count:int,path:string,members_url:string,browse_url:string,already_added?:bool}>},
 *     selection_status_url?:string,query?:string,query_limit?:int,choices_error?:string
 * } Escaped-by-view presentation with no local paths.
 */
function gallery_music_view_model(
    array $gallery,
    bool $editor = false,
    string $query = '',
): array
{
    $state = [
        'revision' => '',
        'write_revision' => '',
        'enabled' => false,
        'tracks' => [],
        'sources' => [],
    ];
    $error = '';
    try {
        $state = \Gallery\Services\gallery_music_playlist(
            $gallery,
            $editor,
        );
    } catch (\Throwable $failure) {
        $error = $editor ? admin_gallery_music_failure(
            (int) $gallery['id'], 'load', 'playlist_read', 0, $failure,
        )['message'] : \Gallery\Services\t('music.failed');
    }
    $model = gallery_music_present_playlist(
        (int) $gallery['id'],
        $editor,
        $state,
        $error,
    );
    if ($editor && $error === '') {
        $model['selection_status_url'] = \Gallery\Core\url_for(
            'admin_edit_gallery', [
                'id' => (int) $gallery['id'],
                'action' => 'music_selection_status',
            ],
        );
        $model['query'] = $query;
        $model['query_limit'] = \Gallery\Services\MUSIC_CATALOG_QUERY_BYTES;
        $model['sources'] = $state['sources'] ?? [];
        require_once __DIR__ . '/../services/gallery_archive_media.php';
        $archiveItems =
            \Gallery\Services\gallery_archive_media_item_preferences(
                'music',
                (int) $gallery['id'],
            );
        $model['archive_global'] =
            \Gallery\Services\gallery_archive_media_global_enabled('music');
        $model['archive_gallery'] =
            \Gallery\Services\gallery_archive_media_gallery_enabled(
                'music',
                (int) $gallery['id'],
            );
        foreach ($model['sources'] as &$source) {
            $source['archive_in_zip'] = isset($archiveItems[$source['id']]);
        }
        unset($source);
        foreach ($model['tracks'] as &$track) {
            $track['archive_in_zip'] = isset($archiveItems[$track['id']])
                || isset($archiveItems[$track['source_id'] ?? '']);
        }
        unset($track);

        $model['unavailable'] = (int) ($state['unavailable'] ?? 0);
        $model['partial'] = (bool) ($state['partial'] ?? false);
        $model['over_limit'] = (bool) ($state['over_limit'] ?? false);
        $model['write_revision'] = (string) (
            $state['write_revision'] ?? $state['revision']
        );
        try {
            $model['choices'] =
                \Gallery\Services\music_catalog_assignment_choices($query);
            $model['choices'] = \Gallery\Services\music_catalog_assignment_availability(
                (int) $gallery['id'],
                $model['sources'],
                $model['choices'],
            );
            $model['choices'] = gallery_music_present_catalog_choices(
                (int) $gallery['id'],
                $model['choices'],
            );
            $model['choices_error'] = '';
        } catch (\Throwable $failure) {
            $model['choices'] = [
                'revision' => '',
                'songs' => [],
                'albums' => [],
            ];
            $model['choices_error'] = admin_gallery_music_failure(
                (int) $gallery['id'], 'choices', 'catalog_read', 0, $failure,
            )['message'];
        }
    }
    return $model;
}

/**
 * Prepare protected URLs and existing controls for a physical or merged playlist.
 * @param int $galleryId Physical ID or negative Smart Gallery presentation ID.
 * @param bool $editor Whether authenticated physical-gallery forms are available.
 * @param array{revision:string,write_revision?:string,enabled:bool,
 *     tracks:list<array{id:string,
 *     source_gallery_id?:int,title:string,filename:string,mime:string,size:int,
 *     sha256:string,hidden:bool,downloadable:bool,source_id?:string,
 *     source_type?:string,source_title?:string,source_track_id?:string}>,
 *     sources?:list<array{id:string,type:string,song_id:?int,album_id:?int,
 *     title:string,downloadable:bool,hidden:bool,track_count:int,
 *     position:int,path?:string}>,unavailable?:int,omitted?:int,
 *     partial?:bool,over_limit?:bool} $state Prepared snapshot;
 *     merged records retain their physical source IDs for delivery.
 * @param string $error Localized optional-storage failure, otherwise empty.
 * @return array{
 *     gallery_id:int,
 *     editor:bool,
 *     action_url:string,
 *     csrf_html:string,
 *     revision:string,
 *     write_revision:string,
 *     enabled:bool,
 *     error:string,
 *     sources:list<array{id:string,type:string,song_id:?int,album_id:?int,
 *         title:string,downloadable:bool,hidden:bool,track_count:int,
 *         position:int,path?:string}>,
 *     unavailable:int,omitted:int,partial:bool,over_limit:bool,
 *     choices:array{revision:string,songs:list<array{id:int,title:string,
 *         track_count:int,preview_url:string,already_added:bool}>,albums:list<array{id:int,title:string,
 *         song_count:int,path:string,members_url:string,browse_url:string,already_added?:bool}>},
 *     tracks:list<array{
 *         id:string,
 *         title:string,
 *         filename:string,
 *         mime:string,
 *         size:int,
 *         sha256:string,
 *         hidden:bool, downloadable:bool,
 *         url:string,
 *         download_url:string,source_id?:string,source_type?:string,
 *         source_title?:string,source_track_id?:string
 *     }>
 * } Escaped-by-view presentation with no local paths.
 */
function gallery_music_present_playlist(
    int $galleryId,
    bool $editor,
    array $state,
    string $error = '',
): array {
    $base = rtrim(\Gallery\Core\request_script_base_path(), '/') . '/index.php';
    $tracks = [];
    $audience = \Gallery\Core\admin_anonymous_preview_active()
        ? ['view_as' => 'anonymous'] : [];
    $presentedTracks = \Gallery\Services\music_catalog_present_tracks(
        $state['tracks'],
        $galleryId,
    );
    foreach ($presentedTracks as $track) {
        if (
            !$editor
            && !\Gallery\Services\gallery_music_track_visible($track)
        ) {
            continue;
        }
        $sourceId = $track['source_gallery_id'] ?? $galleryId;
        $originalId = $track['id'];
        if (isset($track['source_gallery_id'])) {
            $track['id'] = $sourceId . ':' . $originalId;
            unset($track['source_gallery_id']);
        }
        $url =
            $base
            . '?'
            . http_build_query(
                [
                    'page' => 'gallery_music',
                    'id' => $sourceId,
                    'track' => $originalId,
                ] + $audience,
                '',
                '&',
                PHP_QUERY_RFC3986,
            );
        $tracks[] = $track
        + [
            'url' => $url,
            'download_url' => $track['downloadable']
                ? $url . '&download=1'
                : '',
        ];
    }
    return [
        'gallery_id' => $galleryId,
        'editor' => $editor,
        'action_url' => $galleryId > 0
            ? $base
            . '?'
            . http_build_query(
                [
                    'page' => 'admin_edit_gallery',
                    'id' => $galleryId,
                ],
                '',
                '&',
                PHP_QUERY_RFC3986,
            )
            : '',
        'csrf_html' => $editor ? \Gallery\Core\csrf_field() : '',
        'revision' => $state['revision'],
        'write_revision' => (string) ($state['write_revision'] ?? $state['revision']),
        'enabled' => $state['enabled'],
        'error' => $error,
        'tracks' => $tracks,
        'sources' => $state['sources'] ?? [],
        'unavailable' => (int) ($state['unavailable'] ?? 0),
        'omitted' => (int) ($state['omitted'] ?? 0),
        'partial' => (bool) ($state['partial'] ?? false),
        'over_limit' => (bool) ($state['over_limit'] ?? false),
        'choices' => ['revision' => '', 'songs' => [], 'albums' => []],
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
    bool $enabled,
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
 * @param string $query Bounded initial catalog query for direct-page fallback.
 * @return string Music manager HTML with CSRF/revision controls, or empty
 *     when the global music capability is off.
 */
function admin_gallery_music_html(array $gallery, string $query = ''): string
{
    if (!\Gallery\Services\feature_capability_effective_enabled(
        'gallery_music',
    )) {
        return '';
    }
    ob_start();
    \Gallery\Views\view_render_gallery_music(
        gallery_music_view_model($gallery, true, $query),
    );
    return (string) ob_get_clean();
}

/** Prepare complete authenticated preview and album-browsing URLs.
 * @param int $galleryId Current authorized editor identity.
 * @param array{revision:string,songs:list<array{id:int,title:string,
 *     track_count?:int,already_added?:bool}>,albums:list<array{id:int,
 *     title:string,song_count:int,path:string,already_added?:bool}>}
 *     $choices Bounded service catalog rows.
 * @return array{revision:string,songs:list<array{id:int,title:string,
 *     track_count?:int,preview_url:string,already_added?:bool}>,albums:list<array{id:int,
 *     title:string,song_count:int,path:string,already_added?:bool,
 *     members_url:string,browse_url:string}>}
 *     Rows with administrator-only transport URLs.
 */
function gallery_music_present_catalog_choices(
    int $galleryId,
    array $choices,
): array {
    foreach ($choices['songs'] as &$song) {
        $song['preview_url'] = \Gallery\Core\url_for('admin_edit_gallery', [
            'id' => $galleryId,
            'action' => 'music_preview',
            'song_id' => $song['id'],
        ]);
    }
    unset($song);
    foreach ($choices['albums'] as &$album) {
        $album['members_url'] = \Gallery\Core\url_for('admin_edit_gallery', [
            'id' => $galleryId,
            'action' => 'music_album_choices',
            'album_id' => $album['id'],
        ]);
        $album['browse_url'] = \Gallery\Core\url_for('admin_music', [
            'album_id' => $album['id'],
            'tab' => 'albums',
        ]);
    }
    unset($album);
    return $choices;
}

/** Answer lazy catalog reads after the editor's administrator guard.
 * @param array{id:int,folder_path:string} $gallery Authorized physical gallery.
 * @return bool True after a catalog response; false for unrelated requests.
 */
function admin_gallery_music_catalog_response(array $gallery): bool
{
    $action = (string) ($_GET['action'] ?? '');
    if (!in_array($action, [
        'music_preview', 'music_album_choices', 'music_selection_status',
    ], true)) {
        return false;
    }
    $allowed = \Gallery\Services\feature_capability_effective_enabled(
        'gallery_music',
    ) && !\Gallery\Core\admin_anonymous_preview_active()
        && !\Gallery\Services\current_user_is_known_under_18();
    $method = \Gallery\Core\request_method();
    if ($action === 'music_preview') {
        $document = null;
        if ($allowed && in_array($method, ['GET', 'HEAD'], true)) {
            try {
                $document = \Gallery\Services\music_repository_preview_song(
                    (int) ($_GET['song_id'] ?? 0),
                );
                if ($document === null) {
                    throw new \RuntimeException('music.original_unavailable');
                }
            } catch (\Throwable $failure) {
                admin_gallery_music_failure(
                    (int) $gallery['id'], $action, 'preview_read',
                    (int) ($_GET['song_id'] ?? 0), $failure,
                );
                // Protected media responses remain opaque; details are Admin-only.
            }
        }
        gallery_music_stream_document($document);
        return true;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    if (!$allowed || $method !== 'GET') {
        http_response_code($allowed ? 405 : 404);
        echo json_encode(['ok' => false]);
        return true;
    }
    try {
        if ($action === 'music_selection_status') {
            $songId = max(0, (int) ($_GET['song_id'] ?? 0));
            $albumId = max(0, (int) ($_GET['album_id'] ?? 0));
            $state = \Gallery\Services\gallery_music_playlist($gallery, true);
            $choices = \Gallery\Services\music_catalog_assignment_availability(
                (int) $gallery['id'],
                $state['sources'] ?? [],
                [
                    'revision' => '',
                    'songs' => $songId > 0
                        ? [['id' => $songId, 'title' => '']] : [],
                    'albums' => $albumId > 0 ? [[
                        'id' => $albumId, 'title' => '',
                        'song_count' => 0, 'path' => '',
                    ]] : [],
                ],
            );
            echo json_encode([
                'ok' => true,
                'already_added' => (bool) (
                    $choices['songs'][0]['already_added']
                    ?? $choices['albums'][0]['already_added'] ?? false
                ),
            ]);
            return true;
        }
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $members = \Gallery\Services\music_catalog_album_members(
            (int) ($_GET['album_id'] ?? 0),
            \Gallery\Services\MUSIC_CATALOG_PAGE_SIZE,
            $offset,
        );
        $model = gallery_music_view_model($gallery, true);
        $choices = [
            'revision' => \Gallery\Services\music_catalog_revision(),
            'songs' => array_map(static fn(array $member): array => [
                'id' => (int) $member['id'],
                'title' => (string) $member['title'],
            ], $members),
            'albums' => [],
        ];
        $choices = \Gallery\Services\music_catalog_assignment_availability(
            (int) $gallery['id'], $model['sources'], $choices,
        );
        $model['choices'] = gallery_music_present_catalog_choices(
            (int) $gallery['id'], $choices,
        );
        ob_start();
        \Gallery\Views\view_render_gallery_music_choice_rows(
            $model,
            $model['choices'],
        );
        $html = (string) ob_get_clean();
        echo json_encode([
            'ok' => true,
            'choices_html' => $html,
            'next_offset' => count($members)
                === \Gallery\Services\MUSIC_CATALOG_PAGE_SIZE
                    ? $offset + count($members) : null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (\Throwable $failure) {
        $diagnostic = admin_gallery_music_failure(
            (int) $gallery['id'], $action, 'catalog_read',
            (int) ($_GET['song_id'] ?? $_GET['album_id'] ?? 0), $failure,
        );
        http_response_code(503);
        echo json_encode(['ok' => false] + $diagnostic);
    }
    return true;
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
        'tab' => 'admin-edit-media',
        'media_section' => 'music',
    ]);
    $descriptor = \Gallery\Core\admin_mutation_descriptor(
        'gallery.music.' . $action,
        'gallery',
        'update',
        [$id],
    );
    $ajax = admin_wants_json();
    $stage = 'validation';
    $persisted = false;
    try {
        if ((int) ($_POST['id'] ?? 0) !== $id) {
            throw new \RuntimeException(\Gallery\Services\t('music.conflict'));
        }
        $source = '';
        $filename = '';
        if ($action === 'upload') {
            $upload = $_FILES['music_file'] ?? null;
            if (
                !is_array($upload)
                || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_string($upload['tmp_name'] ?? null)
                || !is_uploaded_file($upload['tmp_name'])
                || !is_string($upload['name'] ?? null)
            ) {
                throw new \RuntimeException(
                    \Gallery\Services\t('music.invalid_file'),
                );
            }
            $source = $upload['tmp_name'];
            $filename = $upload['name'];
        }
        if ($action === 'choices') {
            $stage = 'catalog_read';
            $query = trim((string) ($_POST['music_query'] ?? ''));
            $choiceModel = gallery_music_view_model(
                $gallery,
                true,
                $query,
            );
            if (($choiceModel['choices_error'] ?? '') !== '') {
                throw new \RuntimeException(
                    (string) $choiceModel['choices_error'],
                );
            }
            $choices = $choiceModel['choices'];
            ob_start();
            \Gallery\Views\view_render_gallery_music_choice_rows(
                $choiceModel,
                $choices,
            );
            $choicesHtml = (string) ob_get_clean();
            if (!admin_wants_json()) {
                \Gallery\Core\redirect_to(\Gallery\Core\url_for(
                    'admin_edit_gallery',
                    [
                        'id' => $id,
                        'tab' => 'admin-edit-media',
                        'media_section' => 'music',
                        'music_query' => trim(
                            (string) ($_POST['music_query'] ?? ''),
                        ),
                    ],
                ));
            }
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: private, no-store');
            echo json_encode([
                'ok' => true,
                'choices_html' => $choicesHtml,
                'catalog_revision' => $choices['revision'],
                'write_revision' => (string) (
                    \Gallery\Services\gallery_music_state($gallery)['revision']
                ),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }
        $stage = 'mutation';
        $outcome = \Gallery\Services\gallery_music_mutate(
            $gallery,
            (string) ($_POST['music_revision'] ?? ''),
            $action,
            (string) ($_POST['track_id'] ?? ''),
            $source,
            $filename,
            (string) ($_POST['music_enabled'] ?? '') === '1',
            (string) ($_POST['music_download_enabled'] ?? '') === '1',
            (int) ($_POST['selection_id'] ?? 0),
            isset($_POST['music_position'])
                ? (int) $_POST['music_position']
                : null,
            (string) ($_POST['catalog_revision'] ?? ''),
        );
        $persisted = true;
        $stage = 'response';
        \Gallery\Services\gallery_attachment_storage_changed();
        $message = \Gallery\Services\t('music.saved');
        $result = \Gallery\Core\admin_mutation_success_envelope(
            $message,
            $descriptor,
            \Gallery\Core\admin_mutation_panel_metadata(
                'gallery-edit',
                $editUrl,
                true,
            ),
            [\Gallery\Core\admin_mutation_public_gallery_context(
                $id,
                \Gallery\Core\gallery_public_url($gallery),
            )],
            ['redirect_url' => $editUrl],
        );
        $query = trim((string) ($_POST['music_query'] ?? ''));
        if (strlen($query) > \Gallery\Services\MUSIC_CATALOG_QUERY_BYTES) {
            $query = '';
        }
        $result['panel_html'] = admin_gallery_music_html($gallery, $query);
        $result['music_revision'] = $outcome['revision'];
        $result['track_id'] = $outcome['track_id'];
        $result['catalog_sync_pending'] = $outcome['catalog_sync_pending'];
    } catch (\Throwable $error) {
        $diagnostic = admin_gallery_music_failure(
            $id, $action, $stage, (int) ($_POST['selection_id'] ?? 0),
            $error, $persisted,
        );
        $message = $diagnostic['message'];
        if ($ajax)
            http_response_code(422);
        $result = \Gallery\Core\admin_mutation_error_envelope(
            $message,
            'music_operation_failed',
            $descriptor,
        );
        $result['diagnostic_reference'] = $diagnostic['reference'];
        $result['failure_reason'] = $diagnostic['reason'];
        $result['persisted'] = $persisted;
    }
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo json_encode(
            $result,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        return;
    }
    \Gallery\Core\flash_message('admin_notice', $message);
    \Gallery\Core\redirect_to($editUrl);
}

/**
 * Serve an original audio track after canonical inherited gallery
 *     authorization.
 * The administrator playlist probe retains its historical raw write revision
 * for sequential chooser uploads. Live album changes have a separate effective
 * revision used by the player; they never replace the index CAS token.
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
    $admin =
        is_array($user)
        && ($user['role'] ?? '') === 'admin'
        && !\Gallery\Core\admin_anonymous_preview_active()
        && !\Gallery\Services\current_user_is_known_under_18();
    if ($gallery && $admin && (string) ($_GET['playlist'] ?? '') === '1') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $state = \Gallery\Services\gallery_music_playlist($gallery);
            echo json_encode([
                'ok' => true,
                'revision' => $state['write_revision'] ?? $state['revision'],
                'effective_revision' => $state['revision'],
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
    $allowed =
        $gallery
        && (
            $admin
            || \Gallery\Services\visitor_can_access_gallery_without_admin_bypass(
                $gallery,
            )
        );
    if ($allowed) {
        try {
            $document = \Gallery\Services\gallery_music_track(
                $gallery,
                (string) ($_GET['track'] ?? ''),
            );
        } catch (\Throwable) {
            $document = null;
        }
    }
    if (
        $document !== null
        && !$admin
        && !\Gallery\Services\gallery_music_track_visible($document['track'])
    ) {
        $document = null;
    }
    $download = (string) ($_GET['download'] ?? '') === '1';
    if (
        $download
        && $document !== null
        && !$document['track']['downloadable']
    ) {
        $document = null;
    }
    gallery_music_stream_document($document, $download);
}

/** Stream an authorized original using the shared protected music protocol.
 * @param array{path:string,track:array{filename:string,mime:string,size:int}}|null
 *     $document Verified source, or null for an opaque not-found response.
 * @param bool $download Whether attachment disposition was authorized.
 * @return void Emit GET/HEAD byte ranges, or an opaque HTTP refusal.
 */
function gallery_music_stream_document(
    ?array $document,
    bool $download = false,
): void {
    $method = \Gallery\Core\request_method();
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
    $handle = $document !== null ? @fopen($document['path'], 'rb') : false;
    if ($document === null || $handle === false) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        if ($method !== 'HEAD')
            echo 'Not found';
        return;
    }
    try {
        $size = $document['track']['size'];
        // Without validators, If-Range means a complete representation. HEAD
        // reports GET metadata without interpreting its ignored Range field.
        $rangeHeader = $method === 'GET' && !isset($_SERVER['HTTP_IF_RANGE'])
            ? (string) ($_SERVER['HTTP_RANGE'] ?? '')
            : '';
        $range = \Gallery\Services\gallery_music_byte_range(
            $rangeHeader,
            $size,
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
        header(
            'Content-Disposition: '
                . $disposition
                . '; filename="audio"; filename*=UTF-8\'\''
                . rawurlencode($document['track']['filename']),
        );
        $length = $range['end'] - $range['start'] + 1;
        header('Content-Length: ' . $length);
        if ($range['partial']) {
            http_response_code(206);
            header(
                'Content-Range: bytes '
                    . $range['start']
                    . '-'
                    . $range['end']
                    . '/'
                    . $size,
            );
        }
        if (session_status() === PHP_SESSION_ACTIVE)
            session_write_close();
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
