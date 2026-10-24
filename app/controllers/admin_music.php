<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/admin_music.php
 * Module Type: Controller
 * Purpose: Own authenticated requests for the global music catalog manager.
 * Responsibilities: Validate requests, call catalog services, and prepare the manager view.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use Throwable;
use function Gallery\Core\admin_mutation_descriptor;
use function Gallery\Core\admin_mutation_error_envelope;
use function Gallery\Core\admin_mutation_panel_metadata;
use function Gallery\Core\admin_mutation_public_gallery_context;
use function Gallery\Core\admin_mutation_success_envelope;
use function Gallery\Core\admin_wants_json;
use function Gallery\Core\e;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\flash_message;
use function Gallery\Core\redirect_to;
use function Gallery\Core\require_admin;
use function Gallery\Core\request_method;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\feature_capability_effective_enabled;
use function Gallery\Services\music_catalog_album_create;
use function Gallery\Services\music_catalog_album_delete;
use function Gallery\Services\music_catalog_album_move;
use function Gallery\Services\music_catalog_album_members;
use function Gallery\Services\music_catalog_album_song_add;
use function Gallery\Services\music_catalog_album_song_remove;
use function Gallery\Services\music_catalog_album_tree;
use function Gallery\Services\music_catalog_album_update;
use function Gallery\Services\music_catalog_list;
use function Gallery\Services\music_catalog_dirty_status;
use function Gallery\Services\music_catalog_merge;
use function Gallery\Services\music_catalog_revision;
use function Gallery\Services\music_catalog_song_details;
use function Gallery\Services\music_catalog_set_song_title;
use function Gallery\Services\music_catalog_split;
use function Gallery\Services\translation_active_language;
use function Gallery\Services\tags_for_entities;
use function Gallery\Services\music_catalog_sync_song_tags;
use function Gallery\Services\t;
use function Gallery\Views\view_render_admin_music;

/**
 * Keep one manager page small enough for compact rows and batched details.
 * Type: int. Units: rows. Scope: one Admin Music catalog query.
 * Consumers: cms_admin_music.
 * Rationale: a compact page limits detail lookup and response size.
 * Value: 50 rows per page.
 */
const ADMIN_MUSIC_PAGE_SIZE = 50;

/** Require a real multi-song selection before presenting a merge survivor.
 * Type: int. Units: selected song identities. Scope: one catalog merge.
 * Consumers: cms_admin_music, admin_music_merge_songs.
 * Rationale: merging one row cannot combine catalog identities and provides no
 * meaningful survivor choice. Value: 2 selected songs.
 */
const ADMIN_MUSIC_MERGE_MINIMUM = 2;

/**
 * Bound existing-playlist import work so the administrator can resume it.
 * Type: int. Units: records. Scope: one explicit reconciliation request.
 * Consumers: admin_music_reconcile.
 * Rationale: bounded requests keep large-library import resumable.
 * Value: 20 records per request.
 */
const ADMIN_MUSIC_RECONCILE_BATCH_SIZE = 20;

/** Build a canonical public gallery URL carrying the active visitor language.
 *
 * @param array{id:int|string,url_path:string|null,slug:string|null,folder_path:string|null,title:string|null} $gallery Existing gallery fields consumed by the canonical URL helper.
 * @return string Canonical public URL with the active language query value.
 */
function admin_music_gallery_url(array $gallery): string
{
    $url = gallery_public_url($gallery);
    $fragment = '';
    $fragmentPosition = strpos($url, '#');
    if ($fragmentPosition !== false) {
        $fragment = substr($url, $fragmentPosition);
        $url = substr($url, 0, $fragmentPosition);
    }
    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . http_build_query([
        'lang' => translation_active_language(),
    ], '', '&', PHP_QUERY_RFC3986) . $fragment;
}

/**
 * Handle the global Music Manager page and its revisioned catalog mutations.
 *
 * @return void Emits the manager, a JSON mutation envelope, or a redirect.
 */
function cms_admin_music(): void
{
    require_admin();
    if (!feature_capability_effective_enabled('gallery_music')) {
        http_response_code(404);
        return;
    }

    $query = trim((string) ($_GET['q'] ?? ''));
    $albumId = max(0, (int) ($_GET['album_id'] ?? 0));
    $albumOffset = max(0, (int) ($_GET['album_offset'] ?? 0));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $tab = admin_music_resolve_tab(
        (string) ($_GET['return_tab'] ?? $_GET['tab'] ?? ''),
        $albumId,
    );
    $sort = (string) ($_GET['sort'] ?? 'title');
    if (!in_array($sort, ['title', 'files', 'locations', 'tags'], true)) {
        $sort = 'title';
    }
    $direction = (string) ($_GET['direction'] ?? 'asc');
    if (!in_array($direction, ['asc', 'desc'], true)) {
        $direction = 'asc';
    }
    $notice = (string) flash_message('admin_music_notice');
    $error = (string) flash_message('admin_music_error');
    $revision = '';
    try {
        $revision = music_catalog_revision();
    } catch (Throwable) {
        $error = t('admin.music.error_schema', 'Music catalog storage is unavailable. Apply the required database update and try again.');
    }

    if (request_method() === 'POST') {
        verify_csrf();
        $tab = admin_music_resolve_tab(
            (string) ($_POST['return_tab'] ?? ''),
            $albumId,
        );
        $action = (string) ($_POST['action'] ?? '');
        $revision = (string) ($_POST['revision'] ?? '');
        unset($_SESSION['admin_music_created_album_id']);
        $contexts = in_array($action, ['title', 'merge', 'split'], true)
            ? admin_music_gallery_contexts(admin_music_action_song_ids($action))
            : [];
        try {
            $notice = admin_music_mutate($action, $revision);
            if ($action === 'album_create') {
                $albumId = max(
                    0,
                    (int) ($_SESSION['admin_music_created_album_id'] ?? 0),
                );
                $tab = 'albums';
            } elseif (
                $action === 'album_delete'
                && $albumId === (int) ($_POST['album_id'] ?? 0)
            ) {
                $albumId = 0;
            }
        } catch (Throwable $exception) {
            $error = admin_music_error($exception);
        }
        if (admin_wants_json()) {
            $entityIds = match ($action) {
                'merge' => admin_music_post_song_ids('song_ids'),
                'title', 'tags', 'split' => [max(0, (int) ($_POST['song_id'] ?? 0))],
                'album_update', 'album_delete', 'album_move', 'album_add_song',
                'album_remove_song' => [max(0, (int) ($_POST['album_id'] ?? 0))],
                'album_reorder_song' => [max(0, (int) ($_POST['album_id'] ?? 0))],
                'album_create' => [(int) ($_SESSION['admin_music_created_album_id'] ?? 0)],
                default => [],
            };
            $descriptor = admin_mutation_descriptor(
                'music.' . $action,
                'music_catalog',
                $action,
                $entityIds,
            );
            if ($error !== '') {
                admin_music_json_response(
                    admin_mutation_error_envelope(
                        $error,
                        'music_mutation_failed',
                        $descriptor,
                    ),
                    409,
                );
            }
            $redirectUrl = url_for('admin_music', [
                'q' => $query,
                'tab' => $tab,
                'sort' => $sort,
                'direction' => $direction,
                'album_id' => $albumId,
                'album_offset' => $albumOffset,
                'offset' => $offset,
            ]);
            admin_music_json_response(admin_mutation_success_envelope(
                $notice,
                $descriptor,
                admin_mutation_panel_metadata(
                    'admin-music',
                    url_for('admin_music', [
                        'q' => $query,
                        'tab' => $tab,
                        'sort' => $sort,
                        'direction' => $direction,
                        'album_id' => $albumId,
                        'album_offset' => $albumOffset,
                        'offset' => $offset,
                        'fragment' => '1',
                    ]),
                    true,
                ),
                $error === '' ? $contexts : [],
                ['redirect_url' => $redirectUrl],
            ));
        }
        flash_message($error === '' ? 'admin_music_notice' : 'admin_music_error',
            $error === '' ? $notice : $error);
        redirect_to(url_for('admin_music', [
            'q' => $query,
            'tab' => $tab,
            'sort' => $sort,
            'direction' => $direction,
            'album_id' => $albumId,
            'album_offset' => $albumOffset,
            'offset' => $offset,
        ]));
        return;
    }

    try {
        $catalog = music_catalog_list(
            $query,
            ADMIN_MUSIC_PAGE_SIZE,
            $offset,
            $sort,
            $direction,
        );
        $revision = (string) ($catalog['revision'] ?? $revision);
        $songIds = array_map(
            static fn(array $song): int => (int) ($song['id'] ?? 0),
            $catalog['songs'] ?? [],
        );
        $songDetails = music_catalog_song_details($songIds);
        $tagRowsBySong = tags_for_entities('music_song', $songIds);
        $songTags = [];
        $songTagLabels = [];
        foreach ($tagRowsBySong as $songId => $tagRows) {
            $tagNames = array_values(array_filter(
                array_map(
                    static fn(array $tag): string => (string) ($tag['name'] ?? ''),
                    $tagRows,
                ),
                static fn(string $name): bool => $name !== '',
            ));
            $songTagLabels[(int) $songId] = $tagNames;
            $songTags[(int) $songId] = implode(', ', $tagNames);
        }
        $galleryRows = [];
        foreach ($songDetails as $songDetail) {
            foreach ($songDetail['galleries'] ?? [] as $galleryId => $gallery) {
                if (is_array($gallery) && (int) $galleryId > 0) {
                    $galleryRows[(int) $galleryId] = $gallery;
                }
            }
        }
        $galleryUrls = [];
        foreach ($galleryRows as $galleryId => $gallery) {
            $galleryUrls[$galleryId] = admin_music_gallery_url($gallery);
        }
        $albums = music_catalog_album_tree();
        $dirtyStatus = music_catalog_dirty_status();
        $albumMembers = $albumId > 0
            ? music_catalog_album_members(
                $albumId,
                ADMIN_MUSIC_PAGE_SIZE,
                $albumOffset,
            )
            : [];
    } catch (Throwable $exception) {
        $catalog = ['revision' => $revision, 'songs' => [], 'total' => 0];
        $songDetails = [];
        $songTags = [];
        $songTagLabels = [];
        $galleryUrls = [];
        $albums = [];
        $dirtyStatus = ['state' => 'unavailable', 'count' => null];
        $albumMembers = [];
        $error = $exception->getMessage() === 'music.album_limit'
            ? t('admin.music.error_album_limit', 'The album limit has been reached. Remove unused albums before adding more.')
            : t('admin.music.error_schema', 'Music catalog storage is unavailable. Apply the required database update and try again.');
    }
    $albumExists = false;
    foreach ($albums as $album) {
        if ((int) ($album['id'] ?? 0) === $albumId) {
            $albumExists = true;
            break;
        }
    }
    if ($albumId > 0 && !$albumExists) {
        $albumId = 0;
        $albumOffset = 0;
        $albumMembers = [];
    }
    $extensions = false;
    try {
        $extensions = (string) (\Gallery\Services\app_setting(
            'music_show_file_extensions',
            '0',
        ) ?? '0') === '1';
    } catch (Throwable) {
        $error = t('admin.music.error_schema', 'Music catalog storage is unavailable. Apply the required database update and try again.');
    }
    $sortUrls = [];
    foreach (['title', 'files', 'locations', 'tags'] as $column) {
        $sortUrls[$column] = url_for('admin_music', [
            'q' => $query,
            'tab' => 'songs',
            'album_id' => $albumId,
            'sort' => $column,
            'direction' => $sort === $column && $direction === 'asc'
                ? 'desc' : 'asc',
        ]);
    }
    $tabUrls = [];
    foreach (['songs', 'albums'] as $tabName) {
        $tabUrls[$tabName] = url_for('admin_music', [
            'q' => $query,
            'tab' => $tabName,
            'album_id' => $albumId,
            'album_offset' => $albumOffset,
            'offset' => $offset,
            'sort' => $sort,
            'direction' => $direction,
        ]) . '#admin-music-tab-' . $tabName;
    }
    $model = [
        'query' => $query,
        'tab' => $tab,
        'return_tab' => 'admin-music-tab-' . $tab,
        'tab_urls' => $tabUrls,
        'sort' => $sort,
        'direction' => $direction,
        'sort_urls' => $sortUrls,
        'offset' => $offset,
        'limit' => ADMIN_MUSIC_PAGE_SIZE,
        'merge_minimum' => ADMIN_MUSIC_MERGE_MINIMUM,
        'catalog' => $catalog,
        'song_details' => $songDetails,
        'song_tags' => $songTags,
        'song_tag_labels' => $songTagLabels,
        'gallery_urls' => $galleryUrls,
        'reconcile_cursor' => max(0, (int) ($_SESSION['music_catalog_reconcile_cursor'] ?? 0)),
        'dirty_status' => $dirtyStatus,
        'albums' => $albums,
        'album_members' => $albumMembers,
        'album_id' => $albumId,
        'album_offset' => $albumOffset,
        'revision' => (string) ($catalog['revision'] ?? $revision),
        'extensions' => $extensions,
        'csrf_html' => \Gallery\Core\csrf_field()
            . '<input type="hidden" name="return_tab" value="'
            . e('admin-music-tab-' . $tab) . '">',
        'action_url' => url_for('admin_music', [
            'q' => $query,
            'tab' => $tab,
            'sort' => $sort,
            'direction' => $direction,
            'album_id' => $albumId,
            'album_offset' => $albumOffset,
            'offset' => $offset,
        ]),
        'notice' => $notice,
        'error' => $error,
        'fragment' => (string) ($_GET['fragment'] ?? '') === '1',
    ];
    view_render_admin_music($model);
}

/** Resolve the selected manager section, including legacy album deep links.
 * @param string $requested Tab name or full reusable-panel identifier.
 * @param int $albumId Selected album used as the compatibility fallback.
 * @return string Normalized section name: songs or albums.
 */
function admin_music_resolve_tab(string $requested, int $albumId): string
{
    return match (strtolower(trim($requested))) {
        'songs', 'admin-music-tab-songs' => 'songs',
        'albums', 'admin-music-tab-albums' => 'albums',
        default => $albumId > 0 ? 'albums' : 'songs',
    };
}

/**
 * Return song IDs whose pre-mutation gallery contexts can change.
 *
 * @param string $action Submitted music action.
 * @return list<int> Selected song identities for title, merge, and split.
 */
function admin_music_action_song_ids(string $action): array
{
    return match ($action) {
        'merge' => admin_music_post_song_ids('song_ids'),
        'title', 'split' => [max(0, (int) ($_POST['song_id'] ?? 0))],
        default => [],
    };
}

/**
 * Prepare unique public gallery contexts from one batched pre-mutation read.
 *
 * @param list<int> $songIds Logical identities affected by the pending mutation.
 * @return list<array{type:string,gallery_id:int,render_url:string,render_mode:string,postcondition:null}> Public contexts for currently attached galleries; no postcondition is supplied.
 */
function admin_music_gallery_contexts(array $songIds): array
{
    $validIds = array_values(array_filter(
        array_unique($songIds),
        static fn(int $songId): bool => $songId > 0,
    ));
    if ($validIds === []) {
        return [];
    }
    try {
        $details = music_catalog_song_details($validIds);
    } catch (Throwable) {
        return [];
    }
    $galleryIds = [];
    $galleryRows = [];
    foreach ($details as $songDetail) {
        foreach ($songDetail['galleries'] ?? [] as $galleryId => $galleryRow) {
            if (is_array($galleryRow) && (int) $galleryId > 0) {
                $galleryRows[(int) $galleryId] = $galleryRow;
            }
        }
        foreach ($songDetail['direct_galleries'] ?? [] as $gallery) {
            $galleryId = (int) ($gallery['gallery_id'] ?? 0);
            if ($galleryId > 0) {
                $galleryIds[$galleryId] = $galleryId;
            }
        }
        foreach ($songDetail['live_albums'] ?? [] as $placement) {
            $galleryId = (int) ($placement['gallery_id'] ?? 0);
            if ($galleryId > 0) {
                $galleryIds[$galleryId] = $galleryId;
            }
        }
    }
    $contexts = [];
    foreach ($galleryIds as $galleryId) {
        if (!isset($galleryRows[$galleryId])) {
            continue;
        }
        $contexts[] = admin_mutation_public_gallery_context(
            $galleryId,
            admin_music_gallery_url($galleryRows[$galleryId]),
        );
    }
    return $contexts;
}

/**
 * Apply one validated catalog or album action using the submitted revision.
 *
 * @param string $action Action identifier from the submitted form.
 * @param string $revision Expected catalog revision for optimistic concurrency.
 * @return string Localized success message for the completed action.
 */
function admin_music_mutate(string $action, string $revision): string
{
    $songId = max(0, (int) ($_POST['song_id'] ?? 0));
    $albumId = max(0, (int) ($_POST['album_id'] ?? 0));
    $trackIds = admin_music_post_ids('track_ids');
    $songIds = admin_music_post_song_ids('song_ids');
    return match ($action) {
        'title' => admin_music_save_title($songId, $revision),
        'tags' => admin_music_save_tags($songId, $revision),
        'extensions' => admin_music_save_extensions($revision),
        'reconcile' => admin_music_reconcile(),
        'merge' => admin_music_merge_songs($songIds, $songId, $revision),
        'split' => admin_music_split_song($songId, $trackIds, $revision),
        'album_create' => admin_music_create_album($revision),
        'album_update' => admin_music_update_album($albumId, $revision),
        'album_delete' => admin_music_delete_album($albumId, $revision),
        'album_move' => admin_music_move_album($albumId, $revision),
        'album_add_song' => admin_music_add_album_song($albumId, $songId, $revision),
        'album_reorder_song' => admin_music_reorder_album_song(
            $albumId,
            $songId,
            $revision,
        ),
        'album_remove_song' => admin_music_remove_album_song($albumId, $songId, $revision),
        default => throw new \InvalidArgumentException('invalid_action'),
    };
}

/**
 * Save a song's exact custom title and return its localized confirmation.
 *
 * @param int $songId Stable logical song identifier.
 * @param string $revision Expected catalog revision.
 * @return string Localized confirmation.
 */
function admin_music_save_title(int $songId, string $revision): string
{
    music_catalog_set_song_title(
        $songId,
        (string) ($_POST['title'] ?? ''),
        $revision,
    );
    return t('admin.music.saved_title', 'Song title saved.');
}

/**
 * Save the song's comma-separated music-only tag assignment.
 *
 * @param int $songId Logical song identifier.
 * @param string $revision Expected catalog revision.
 * @return string Localized confirmation.
 */
function admin_music_save_tags(int $songId, string $revision): string
{
    if (!hash_equals(music_catalog_revision(), $revision)) {
        throw new \RuntimeException('music.conflict');
    }
    music_catalog_sync_song_tags(
        $songId,
        (string) ($_POST['tags'] ?? ''),
        $revision,
    );
    return t('admin.music.saved_tags', 'Music tags saved.');
}

/**
 * Persist whether filename-derived song titles show audio extensions.
 *
 * @param string $revision Expected catalog revision.
 * @return string Localized confirmation.
 */
function admin_music_save_extensions(string $revision): string
{
    if (!hash_equals(music_catalog_revision(), $revision)) {
        throw new \RuntimeException('music.conflict');
    }
    \Gallery\Services\music_catalog_set_show_file_extensions(
        ($_POST['show_extensions'] ?? '') === '1' ? '1' : '0',
        $revision,
    );
    return t('admin.music.saved_extensions', 'Extension preference saved.');
}

/**
 * Reconcile a bounded batch of existing physical track references.
 *
 * @return string Localized progress message.
 * @throws \RuntimeException When a source gallery cannot be read; the cursor is retained for repair and retry.
 */
function admin_music_reconcile(): string
{
    $afterGalleryId = max(0, (int) ($_SESSION['music_catalog_reconcile_cursor'] ?? 0));
    $revision = (string) ($_POST['revision'] ?? '');
    $result = \Gallery\Services\music_catalog_reconcile(
        ADMIN_MUSIC_RECONCILE_BATCH_SIZE,
        $afterGalleryId,
        $revision,
    );
    $_SESSION['music_catalog_reconcile_cursor'] =
        (int) ($result['next_gallery_id'] ?? 0);
    if (isset($result['blocked_gallery_id'])) {
        throw new \RuntimeException(
            'music.reconcile_blocked:'
            . max(0, (int) $result['blocked_gallery_id']),
        );
    }
    return (bool) ($result['complete'] ?? false)
        ? t('admin.music.reconcile_done', 'Existing tracks are up to date.')
        : t('admin.music.reconcile_more', 'Catalog updated. Continue importing existing tracks.');
}

/**
 * Merge selected physical track instances into the chosen surviving song.
 *
 * @param list<int> $songIds Selected logical song identifiers.
 * @param int $survivorId Logical song whose metadata survives.
 * @param string $revision Expected catalog revision.
 * @return string Localized confirmation.
 */
function admin_music_merge_songs(
    array $songIds,
    int $survivorId,
    string $revision,
): string {
    if (count($songIds) < ADMIN_MUSIC_MERGE_MINIMUM
        || !in_array($survivorId, $songIds, true)) {
        throw new \InvalidArgumentException('invalid_selection');
    }
    music_catalog_merge(
        $songIds,
        $survivorId,
        $revision,
        (string) ($_POST['tag_policy'] ?? 'union'),
    );
    return t('admin.music.merged', 'Songs linked.');
}

/**
 * Split selected physical instances out of a logical song.
 *
 * @param int $songId Logical song identifier to split.
 * @param list<array{gallery_id:int,track_id:string}> $trackIds Stable physical references.
 * @param string $revision Expected catalog revision.
 * @return string Localized confirmation.
 */
function admin_music_split_song(
    int $songId,
    array $trackIds,
    string $revision,
): string {
    if ($trackIds === []) {
        throw new \InvalidArgumentException('invalid_selection');
    }
    music_catalog_split($songId, $trackIds, $revision);
    return t('admin.music.split', 'Selected variants separated.');
}

/**
 * Create an album under the selected parent album.
 *
 * @param string $revision Expected catalog revision.
 * @return string Localized confirmation.
 */
function admin_music_create_album(string $revision): string
{
    $parent = max(0, (int) ($_POST['parent_id'] ?? 0));
    $result = music_catalog_album_create(
        trim((string) ($_POST['album_title'] ?? '')),
        $parent > 0 ? $parent : null,
        $revision,
        trim((string) ($_POST['album_description'] ?? '')),
    );
    $_SESSION['admin_music_created_album_id'] = (int) $result['album_id'];
    return t('admin.music.album_created', 'Album created.');
}

/**
 * Rename an album.
 *
 * @param int $albumId Album identifier.
 * @param string $revision Expected catalog revision.
 * @return string Localized confirmation.
 */
function admin_music_update_album(int $albumId, string $revision): string
{
    music_catalog_album_update(
        $albumId,
        trim((string) ($_POST['album_title'] ?? '')),
        $revision,
        trim((string) ($_POST['album_description'] ?? '')),
    );
    return t('admin.music.album_saved', 'Album saved.');
}

/**
 * Delete an album and its logical membership only.
 *
 * @param int $albumId Album identifier.
 * @param string $revision Expected catalog revision.
 * @return string Localized confirmation.
 */
function admin_music_delete_album(int $albumId, string $revision): string
{
    music_catalog_album_delete($albumId, $revision);
    return t('admin.music.album_deleted', 'Album deleted.');
}

/**
 * Move an album to a parent and position after service cycle validation.
 *
 * @param int $albumId Album identifier.
 * @param string $revision Expected catalog revision.
 * @return string Localized confirmation.
 */
function admin_music_move_album(int $albumId, string $revision): string
{
    $parent = max(0, (int) ($_POST['parent_id'] ?? 0));
    music_catalog_album_move(
        $albumId,
        $parent > 0 ? $parent : null,
        max(0, (int) ($_POST['position'] ?? 0)),
        $revision,
    );
    return t('admin.music.album_moved', 'Album moved.');
}

/**
 * Add a song to an album without altering physical gallery playlists.
 *
 * @param int $albumId Album identifier.
 * @param int $songId Logical song identifier.
 * @param string $revision Expected catalog revision.
 * @return string Localized confirmation.
 */
function admin_music_add_album_song(
    int $albumId,
    int $songId,
    string $revision,
): string {
    $submittedPosition = trim((string) ($_POST['position'] ?? ''));
    music_catalog_album_song_add(
        $albumId,
        $songId,
        $submittedPosition === ''
            ? null
            : max(0, (int) $submittedPosition),
        $revision,
    );
    return t('admin.music.album_song_added', 'Song added to album.');
}

/**
 * Change one direct song's ordered position inside an album.
 *
 * @param int $albumId Album identifier.
 * @param int $songId Logical song identifier.
 * @param string $revision Expected catalog revision.
 * @return string Localized confirmation.
 */
function admin_music_reorder_album_song(
    int $albumId,
    int $songId,
    string $revision,
): string {
    music_catalog_album_song_add(
        $albumId,
        $songId,
        max(0, (int) ($_POST['position'] ?? 0)),
        $revision,
    );
    return t('admin.music.album_song_moved', 'Song order saved.');
}

/**
 * Remove one direct song membership from an album.
 *
 * @param int $albumId Album identifier.
 * @param int $songId Logical song identifier.
 * @param string $revision Expected catalog revision.
 * @return string Localized confirmation.
 */
function admin_music_remove_album_song(
    int $albumId,
    int $songId,
    string $revision,
): string {
    music_catalog_album_song_remove($albumId, $songId, $revision);
    return t('admin.music.album_song_removed', 'Song removed from album.');
}

/**
 * Return a validated list of positive integer identifiers from POST.
 *
 * @param string $key POST field containing scalar or repeated identifiers.
 * @return list<array{gallery_id:int,track_id:string}> Unique stable track references.
 */
function admin_music_post_ids(string $key): array
{
    $values = $_POST[$key] ?? [];
    if (!is_array($values)) {
        $values = [$values];
    }
    $ids = [];
    foreach ($values as $value) {
        if (
            is_scalar($value)
            && preg_match('/^([1-9][0-9]{0,9}):([a-f0-9]{32})$/i', (string) $value, $matches) === 1
        ) {
            $ids[] = [
                'gallery_id' => (int) $matches[1],
                'track_id' => strtolower($matches[2]),
            ];
        }
    }
    $unique = [];
    foreach ($ids as $reference) {
        $unique[$reference['gallery_id'] . ':' . $reference['track_id']] = $reference;
    }
    return array_values($unique);
}

/**
 * Return validated positive song identifiers from a repeated POST field.
 *
 * @param string $key POST field containing numeric song identifiers.
 * @return list<int> Unique positive song identifiers.
 */
function admin_music_post_song_ids(string $key): array
{
    $values = $_POST[$key] ?? [];
    if (!is_array($values)) {
        $values = [$values];
    }
    $ids = [];
    foreach ($values as $value) {
        if (filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0) {
            $ids[] = (int) $value;
        }
    }
    return array_values(array_unique($ids));
}

/**
 * Convert a caught mutation exception into a localized user message.
 *
 * @param Throwable $exception Failure raised by validation or catalog service.
 * @return string Localized failure message.
 */
function admin_music_error(Throwable $exception): string
{
    if (preg_match('/^music\.reconcile_blocked:([1-9][0-9]{0,9})$/', $exception->getMessage(), $matches) === 1) {
        return t('admin.music.error_reconcile_blocked', [
            'gallery_id' => (int) $matches[1],
        ]);
    }
    return match ($exception->getMessage()) {
        'music.original_unavailable' => t('music.original_unavailable'),
        'music.source_reconcile_required' => t('music.catalog_sync_required'),
        'music.conflict' => t('admin.music.error_stale', 'The catalog changed. Reload before saving.'),
        'invalid_selection' => t('admin.music.error_selection', 'Select at least two valid items.'),
        'music.album_cycle' => t('admin.music.error_cycle', 'An album cannot be moved inside itself.'),
        'music.album_depth' => t('admin.music.error_depth', 'The maximum album nesting depth is reached.'),
        'music.album_missing' => t('admin.music.error_album_missing', 'The album no longer exists.'),
        'music.album_limit' => t('admin.music.error_album_limit', 'The album limit has been reached. Remove unused albums before adding more.'),
        'music.linked_gallery_limit' => t(
            'admin.music.error_linked_gallery_limit',
            'A gallery using this playlist cannot accept another song. '
                . 'Check its music availability and limits.',
        ),
        default => t('admin.music.error_save', 'The music catalog could not be saved.'),
    };
}

/**
 * Send a JSON response and terminate the request.
 *
 * @param array{ok:bool,message:string,error?:string,error_code?:string,mutation:array{type:string,entity:string,action:string,entity_ids:list<int>},panel:array{workflow:string,refresh_url:string,keep_open:bool}|null,contexts:list<array{type:string,gallery_id:int,render_url:string,render_mode:string,postcondition:null}>,fallback:array{redirect_url?:string}} $payload Canonical music mutation success or refusal envelope.
 * @param int $status HTTP response status.
 * @return never Encodes the response and exits.
 */
function admin_music_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}
