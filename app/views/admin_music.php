<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/views/admin_music.php
 * Module Type: View
 * Purpose: Render the tabbed global Music Manager from prepared data.
 * Responsibilities: Present songs, title popovers, and album hierarchy panels.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Services\t;

/**
 * Stop defensive tree rendering at the catalog's supported album depth.
 * Type: int. Units: nesting levels. Scope: defensive Admin tree rendering.
 * Consumers: album node and breadcrumb renderers.
 * Rationale: match the service's eight-level nesting limit. Value: 8.
 */
const ADMIN_MUSIC_ALBUM_RENDER_MAX_DEPTH = 8;

/**
 * Render the compact catalog and album manager.
 *
 * @param array{query:string,tab:string,return_tab:string,tab_urls:array{songs:string,albums:string},offset:int,limit:int,sort:string,direction:string,sort_urls:array{title:string,files:string,locations:string,tags:string},catalog:array{revision:string,songs:list<array{id:int|string,title:string,track_count:int|string,album_count:int|string,direct_gallery_count:int|string}>,total:int|string},song_details:array<int,array{tracks:list<array{gallery_id:int,track_id:string,filename:string,asset_id:?string}>,repository_assets:list<array{id:string,filename:string,byte_size:int,sha256:string,origin_gallery_id:?int,origin_track_id:?string}>,direct_galleries:list<array{song_id:int,gallery_id:int|string,title:string,track_count:int|string}>,albums:list<array{song_id:int,album_id:int|string,title:string,track_count:int|string}>,live_albums:list<array{song_id:int,gallery_id:int,gallery_title:string,album_id:int,album_title:string,track_count:int|string}>}>,albums:list<array{id:int|string,parent_id:int|string|null,title:string,description:string,sort_order:int|string,revision:int|string,song_count:int|string}>,album_id:int,album_offset:int,album_members:list<array{id:int|string,title:string,custom_title:string,indexed_title:string,filename:string}>,revision:string,extensions:bool,song_tags:array<int,string>,song_tag_labels:array<int,list<string>>,gallery_urls:array<int,string>,reconcile_cursor:int,dirty_status:array{state:string,count:int|null},csrf_html:string,action_url:string,notice:string,error:string,fragment:bool} $model Controller-prepared page data.
 * @return void Emits the page or fragment markup.
 */
function view_render_admin_music(array $model): void
{
    if (empty($model['fragment'])) {
        render_header(t('admin.music.title', 'Music Manager'));
    }
    echo '<section class="admin-music" id="music-manager" data-admin-music data-error="'
        . e(t('admin.music.error_save', 'The music catalog could not be saved.'))
        . '" data-refresh-error="' . e(t('admin.music.error_refresh', 'Saved, but the catalog could not refresh. Reload before making another change.'))
        . '" data-timeout-error="' . e(t('admin.music.error_timeout', 'The request timed out. Its result is unknown. Reload before making another change.'))
        . '" data-search-error="' . e(t('admin.music.error_search', 'Search could not refresh. Try again.'))
        . '" data-merge-confirm="' . e(t('admin.music.confirm_merge', 'Link selected songs?'))
        . '" data-survivor-label="' . e(t('admin.music.survivor', 'Keep metadata from'))
        . '" data-usage-label="' . e(t('admin.music.used', 'Used'))
        . '" data-merge-union="' . e(t('admin.music.merge_union', 'The chosen song keeps its title; tags are combined.'))
        . '" data-merge-survivor="' . e(t('admin.music.merge_survivor', 'The chosen song keeps its title and tags.'))
        . '">';
    if (!empty($model['notice'])) {
        echo '<p class="admin-music-notice" role="status">'
            . e((string) $model['notice']) . '</p>';
    }
    if (!empty($model['error'])) {
        echo '<p class="admin-music-error" role="alert">'
            . e((string) $model['error']) . '</p>';
    }
    echo '<div class="admin-music-header"><h1 class="admin-music-heading">' . view_admin_menu_icon('music')
        . e(t('admin.music.title', 'Music Manager')) . '</h1>';
    view_admin_music_settings($model);
    echo '</div>';
    $tabs = [
        [
            'id' => 'admin-music-tab-songs',
            'label' => t('admin.music.tab_songs', 'Songs'),
            'href' => (string) $model['tab_urls']['songs'],
        ],
        [
            'id' => 'admin-music-tab-albums',
            'label' => t('admin.music.tab_albums', 'Albums & playlists'),
            'href' => (string) $model['tab_urls']['albums'],
        ],
    ];
    ob_start();
    view_render_admin_tabs($tabs, (string) $model['return_tab']);
    $tabNavigation = (string) ob_get_clean();
    echo str_replace(
        'data-admin-tabs ',
        'data-admin-tabs data-admin-tabs-url-mode="href" ',
        str_replace(
            'class="admin-tabs"',
            'class="admin-tabs admin-music-tabs"',
            $tabNavigation,
        ),
    );
    ob_start();
    echo '<section class="admin-music-catalog" aria-label="'
        . e(t('admin.music.catalog', 'Song catalog')) . '">';
    view_admin_music_search($model);
    view_admin_music_songs($model);
    echo '</section>';
    $songsHtml = (string) ob_get_clean();
    view_render_admin_tab_panel(
        'admin-music-tab-songs',
        $songsHtml,
        $model['tab'] === 'songs',
    );
    ob_start();
    echo '<div class="admin-music-albums-layout"><section'
        . ' class="admin-music-album-sidebar" aria-label="'
        . e(t('admin.music.tab_albums', 'Albums & playlists')) . '">';
    view_admin_music_album_tree($model);
    echo '</section><section class="admin-music-album-content" aria-label="'
        . e(t('admin.music.tab_albums', 'Albums & playlists')) . '">';
    if ((int) $model['album_id'] > 0) {
        view_admin_music_album_detail(
            $model,
            (int) $model['album_id'],
            $model['albums'],
        );
    } else {
        echo '<p class="admin-music-album-prompt">'
            . e(t(
                'admin.music.album_prompt',
                'Select an album or create one.',
            )) . '</p>';
    }
    echo '</section></div>';
    $albumsHtml = (string) ob_get_clean();
    view_render_admin_tab_panel(
        'admin-music-tab-albums',
        $albumsHtml,
        $model['tab'] === 'albums',
    );
    echo '</section>';
    if (empty($model['fragment'])) {
        render_footer();
    }
}

/**
 * Render global title and extension preferences.
 *
 * @param array{query:string,tab:string,return_tab:string,tab_urls:array{songs:string,albums:string},offset:int,limit:int,sort:string,direction:string,sort_urls:array{title:string,files:string,locations:string,tags:string},catalog:array{revision:string,songs:list<array{id:int|string,title:string,track_count:int|string,album_count:int|string,direct_gallery_count:int|string}>,total:int|string},song_details:array<int,array{tracks:list<array{gallery_id:int,track_id:string,filename:string,asset_id:?string}>,repository_assets:list<array{id:string,filename:string,byte_size:int,sha256:string,origin_gallery_id:?int,origin_track_id:?string}>,direct_galleries:list<array{song_id:int,gallery_id:int|string,title:string,track_count:int|string}>,albums:list<array{song_id:int,album_id:int|string,title:string,track_count:int|string}>,live_albums:list<array{song_id:int,gallery_id:int,gallery_title:string,album_id:int,album_title:string,track_count:int|string}>}>,albums:list<array{id:int|string,parent_id:int|string|null,title:string,description:string,sort_order:int|string,revision:int|string,song_count:int|string}>,album_id:int,album_offset:int,album_members:list<array{id:int|string,title:string,custom_title:string,indexed_title:string,filename:string}>,revision:string,extensions:bool,song_tags:array<int,string>,song_tag_labels:array<int,list<string>>,gallery_urls:array<int,string>,reconcile_cursor:int,dirty_status:array{state:string,count:int|null},csrf_html:string,action_url:string,notice:string,error:string,fragment:bool} $model Prepared preference values and form data.
 * @return void Emits independent preference forms.
 */
function view_admin_music_settings(array $model): void
{
    echo '<details class="admin-music-preferences"><summary title="'
        . e(t('admin.music.preferences', 'Catalog preferences'))
        . '" aria-label="' . e(t('admin.music.preferences', 'Catalog preferences'))
        . '">' . view_admin_menu_icon('settings') . '</summary>';
    echo '<form method="post" action="' . e((string) $model['action_url'])
        . '" data-admin-music-form>' . $model['csrf_html']
        . '<input type="hidden" name="revision" value="'
        . e((string) $model['revision']) . '"><input type="hidden" name="action"'
        . ' value="extensions"><label class="admin-music-check"><input'
        . ' type="checkbox" name="show_extensions" value="1"'
        . (!empty($model['extensions']) ? ' checked' : '') . '>'
        . e(t('admin.music.show_extensions', 'Show file extensions in song titles'))
        . '</label><button type="submit" title="' . e(t('admin.music.save', 'Save'))
        . '" aria-label="' . e(t('admin.music.save', 'Save')) . '">' . view_admin_menu_icon('update') . '</button></form>';
    echo '</details>';
}

/**
 * Render catalog search and a bounded reconciliation control.
 *
 * @param array{query:string,tab:string,return_tab:string,tab_urls:array{songs:string,albums:string},offset:int,limit:int,sort:string,direction:string,sort_urls:array{title:string,files:string,locations:string,tags:string},catalog:array{revision:string,songs:list<array{id:int|string,title:string,track_count:int|string,album_count:int|string,direct_gallery_count:int|string}>,total:int|string},song_details:array<int,array{tracks:list<array{gallery_id:int,track_id:string,filename:string,asset_id:?string}>,repository_assets:list<array{id:string,filename:string,byte_size:int,sha256:string,origin_gallery_id:?int,origin_track_id:?string}>,direct_galleries:list<array{song_id:int,gallery_id:int|string,title:string,track_count:int|string}>,albums:list<array{song_id:int,album_id:int|string,title:string,track_count:int|string}>,live_albums:list<array{song_id:int,gallery_id:int,gallery_title:string,album_id:int,album_title:string,track_count:int|string}>}>,albums:list<array{id:int|string,parent_id:int|string|null,title:string,description:string,sort_order:int|string,revision:int|string,song_count:int|string}>,album_id:int,album_offset:int,album_members:list<array{id:int|string,title:string,custom_title:string,indexed_title:string,filename:string}>,revision:string,extensions:bool,song_tags:array<int,string>,song_tag_labels:array<int,list<string>>,gallery_urls:array<int,string>,reconcile_cursor:int,dirty_status:array{state:string,count:int|null},csrf_html:string,action_url:string,notice:string,error:string,fragment:bool} $model Prepared query, URL, and revision.
 * @return void Emits search and reconciliation controls.
 */
function view_admin_music_search(array $model): void
{
    echo '<div class="admin-music-toolbar"><form method="get" action="'
        . e((string) $model['action_url']) . '" class="admin-music-search">'
        . '<input type="hidden" name="page" value="admin_music">'
        . '<input type="hidden" name="tab" value="'
        . e((string) $model['tab']) . '">'
        . '<input type="hidden" name="return_tab" value="'
        . e((string) $model['return_tab']) . '">'
        . '<input type="hidden" name="album_id" value="'
        . (int) $model['album_id'] . '">'
        . '<input type="hidden" name="sort" value="'
        . e((string) $model['sort']) . '">'
        . '<input type="hidden" name="direction" value="'
        . e((string) $model['direction']) . '">'
        . '<label class="sr-only" for="admin-music-query">'
        . e(t('admin.music.search', 'Search songs')) . '</label><input id="admin-music-query"'
        . ' name="q" value="' . e((string) $model['query']) . '" type="search"'
        . ' placeholder="' . e(t('admin.music.search_hint', 'Title, filename, or tag'))
        . '"><button type="submit" aria-label="' . e(t('admin.music.search', 'Search'))
        . '" title="' . e(t('admin.music.search', 'Search')) . '">' . view_admin_menu_icon('search') . '</button></form>';
    echo '<form method="post" action="' . e((string) $model['action_url'])
        . '" data-admin-music-form>' . $model['csrf_html']
        . '<input type="hidden" name="revision" value="'
        . e((string) $model['revision']) . '"><input type="hidden" name="action"'
        . ' value="reconcile"><button type="submit" title="'
        . e(t('admin.music.reconcile', 'Import existing tracks'))
        . '" aria-label="' . e(t('admin.music.reconcile', 'Import existing tracks'))
        . '">' . view_admin_menu_icon('update');
    if ((int) ($model['catalog']['total'] ?? 0) === 0) {
        echo '<span>' . e(t('admin.music.reconcile', 'Import existing tracks'))
            . '</span>';
    }
    echo '</button>';
    $dirtyState = (string) ($model['dirty_status']['state'] ?? 'unknown');
    $dirtyCount = $model['dirty_status']['count'] ?? null;
    if ($dirtyState === 'available' && $dirtyCount !== null && $dirtyCount > 0) {
        echo '<span class="admin-music-pending">'
            . e(t('admin.music.reconcile_pending', 'Pending galleries'))
            . ': ' . (int) $dirtyCount . '</span>';
    } elseif ($dirtyState !== 'available') {
        echo '<span class="admin-music-pending" role="status">'
            . e(t('admin.music.reconcile_unavailable', 'Catalog status is unavailable.'))
            . '</span>';
    }
    echo '</form></div>';
}

/**
 * Render song rows, variant disclosures, usage counts, and relationship actions.
 *
 * @param array{query:string,tab:string,return_tab:string,tab_urls:array{songs:string,albums:string},offset:int,limit:int,sort:string,direction:string,sort_urls:array{title:string,files:string,locations:string,tags:string},catalog:array{revision:string,songs:list<array{id:int|string,title:string,track_count:int|string,album_count:int|string,direct_gallery_count:int|string}>,total:int|string},song_details:array<int,array{tracks:list<array{gallery_id:int,track_id:string,filename:string,asset_id:?string}>,repository_assets:list<array{id:string,filename:string,byte_size:int,sha256:string,origin_gallery_id:?int,origin_track_id:?string}>,direct_galleries:list<array{song_id:int,gallery_id:int|string,title:string,track_count:int|string}>,albums:list<array{song_id:int,album_id:int|string,title:string,track_count:int|string}>,live_albums:list<array{song_id:int,gallery_id:int,gallery_title:string,album_id:int,album_title:string,track_count:int|string}>}>,albums:list<array{id:int|string,parent_id:int|string|null,title:string,description:string,sort_order:int|string,revision:int|string,song_count:int|string}>,album_id:int,album_offset:int,album_members:list<array{id:int|string,title:string,custom_title:string,indexed_title:string,filename:string}>,revision:string,extensions:bool,song_tags:array<int,string>,song_tag_labels:array<int,list<string>>,gallery_urls:array<int,string>,reconcile_cursor:int,dirty_status:array{state:string,count:int|null},csrf_html:string,action_url:string,notice:string,error:string,fragment:bool} $model Prepared page of catalog song rows.
 * @return void Emits the compact responsive song table.
 */
function view_admin_music_songs(array $model): void
{
    $songs = is_array($model['catalog']['songs'] ?? null)
        ? $model['catalog']['songs'] : [];
    $showSplitColumn = false;
    foreach ($songs as $song) {
        $songId = (int) ($song['id'] ?? 0);
        $physicalTracks = $model['song_details'][$songId]['tracks'] ?? [];
        if (count($physicalTracks) > 1) {
            $showSplitColumn = true;
            break;
        }
    }
    echo '<details class="admin-music-merge"><summary title="'
        . e(t('admin.music.merge', 'Link as same song'))
        . '" aria-label="' . e(t('admin.music.merge', 'Link as same song'))
        . '">' . view_admin_menu_icon('rename') . '</summary><form id="admin-music-merge" method="post" action="'
        . e((string) $model['action_url'])
        . '" data-admin-music-form class="admin-music-bulk">' . $model['csrf_html']
        . '<input type="hidden" name="revision" value="'
        . e((string) $model['revision']) . '"><input type="hidden" name="action"'
        . ' value="merge"><label>' . e(t('admin.music.survivor', 'Keep metadata from'))
        . '<select name="song_id">';
    foreach ($songs as $song) {
        echo '<option value="' . (int) ($song['id'] ?? 0) . '">'
            . e((string) ($song['title'] ?? '')) . '</option>';
    }
    echo '</select></label><label>' . e(t('admin.music.tag_policy_label', 'Tags'))
        . '<select name="tag_policy"><option value="union">'
        . e(t('admin.music.tag_policy_union', 'Combine selected tags'))
        . '</option><option value="survivor">'
        . e(t('admin.music.tag_policy_survivor', 'Keep survivor tags'))
        . '</option></select></label><button type="submit" data-confirm="'
        . e(t('admin.music.confirm_merge', 'Link selected songs?')) . '" title="'
        . e(t('admin.music.merge', 'Link as same song')) . '" aria-label="'
        . e(t('admin.music.merge', 'Link as same song')) . '">' . view_admin_menu_icon('rename') . '</button>';
    echo '</form></details><div class="admin-music-table-wrap"><table><thead><tr>';
    $headings = [
        'title' => t('admin.music.title_label', 'Title'),
        'files' => t('admin.music.files', 'Files'),
        'locations' => t('admin.music.locations', 'Locations'),
        'tags' => t('admin.music.tags', 'Tags'),
    ];
    foreach ($headings as $column => $label) {
        $active = $model['sort'] === $column;
        $descending = $model['direction'] === 'desc';
        echo '<th scope="col"'
            . ($active ? ' aria-sort="'
                . ($descending ? 'descending' : 'ascending') . '"' : '')
            . '><a href="' . e((string) $model['sort_urls'][$column])
            . '">' . e($label)
            . ($active ? '<span aria-hidden="true">'
                . ($descending ? ' ↓' : ' ↑') . '</span>' : '')
            . '</a></th>';
    }
    if ($showSplitColumn) {
        echo '<th scope="col"><span class="sr-only">'
            . e(t('admin.music.actions', 'Actions')) . '</span></th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($songs as $song) {
        view_admin_music_song_row($model, $song, $showSplitColumn);
    }
    if ($songs === []) {
        echo '<tr><td colspan="4">' . e(t('admin.music.empty', 'No songs found.'))
            . '</td></tr>';
    }
    echo '</tbody></table></div>';
    view_admin_music_pagination($model);
}

/**
 * Render one song row with accessible disclosures and separate mutation forms.
 *
 * @param array{query:string,tab:string,return_tab:string,tab_urls:array{songs:string,albums:string},offset:int,limit:int,sort:string,direction:string,sort_urls:array{title:string,files:string,locations:string,tags:string},catalog:array{revision:string,songs:list<array{id:int|string,title:string,track_count:int|string,album_count:int|string,direct_gallery_count:int|string}>,total:int|string},song_details:array<int,array{tracks:list<array{gallery_id:int,track_id:string,filename:string,asset_id:?string}>,repository_assets:list<array{id:string,filename:string,byte_size:int,sha256:string,origin_gallery_id:?int,origin_track_id:?string}>,direct_galleries:list<array{song_id:int,gallery_id:int|string,title:string,track_count:int|string}>,albums:list<array{song_id:int,album_id:int|string,title:string,track_count:int|string}>,live_albums:list<array{song_id:int,gallery_id:int,gallery_title:string,album_id:int,album_title:string,track_count:int|string}>}>,albums:list<array{id:int|string,parent_id:int|string|null,title:string,description:string,sort_order:int|string,revision:int|string,song_count:int|string}>,album_id:int,album_offset:int,album_members:list<array{id:int|string,title:string,custom_title:string,indexed_title:string,filename:string}>,revision:string,extensions:bool,song_tags:array<int,string>,song_tag_labels:array<int,list<string>>,gallery_urls:array<int,string>,reconcile_cursor:int,dirty_status:array{state:string,count:int|null},csrf_html:string,action_url:string,notice:string,error:string,fragment:bool} $model Page state including URL and revision.
 * @param array{id:int|string,title:string,track_count:int|string,album_count:int|string,direct_gallery_count:int|string} $song One normalized catalog song row.
 * @param bool $showSplitColumn Whether this page needs its compact split column.
 * @return void Emits one table row and expandable details.
 */
function view_admin_music_song_row(
    array $model,
    array $song,
    bool $showSplitColumn,
): void
{
    $id = (int) ($song['id'] ?? 0);
    $title = (string) ($song['title'] ?? '');
    $details = $model['song_details'][$id] ?? [];
    $tracks = is_array($details['tracks'] ?? null) ? $details['tracks'] : [];
    $usage = $details;
    $galleryUsages = [];
    foreach ($usage['direct_galleries'] ?? [] as $gallery) {
        $galleryId = (int) ($gallery['gallery_id'] ?? 0);
        if ($galleryId > 0) {
            $galleryUsages[$galleryId] = true;
        }
    }
    foreach ($usage['live_albums'] ?? [] as $placement) {
        $galleryId = (int) ($placement['gallery_id'] ?? 0);
        if ($galleryId > 0) {
            $galleryUsages[$galleryId] = true;
        }
    }
    $usageCount = count($galleryUsages)
        + count($usage['albums'] ?? []);
    $repositoryAssets = is_array($details['repository_assets'] ?? null)
        ? $details['repository_assets'] : [];
    $filesById = [];
    $promotedOrigins = [];
    foreach ($repositoryAssets as $asset) {
        $assetId = (string) ($asset['id'] ?? '');
        if ($assetId !== '') {
            $filesById['asset:' . $assetId] = [
                'filename' => (string) ($asset['filename'] ?? ''),
                'byte_size' => (int) ($asset['byte_size'] ?? 0),
                'origin_gallery_id' => $asset['origin_gallery_id'] ?? null,
                'origin_track_id' => $asset['origin_track_id'] ?? null,
                'kind' => 'asset',
            ];
        }
        $originGalleryId = (int) ($asset['origin_gallery_id'] ?? 0);
        $originTrackId = (string) ($asset['origin_track_id'] ?? '');
        if ($originGalleryId > 0 && $originTrackId !== '') {
            $promotedOrigins[$originGalleryId . ':' . $originTrackId] = true;
        }
    }
    foreach ($tracks as $track) {
        if (($track['asset_id'] ?? null) !== null) {
            continue;
        }
        $physicalKey = (int) ($track['gallery_id'] ?? 0)
            . ':' . (string) ($track['track_id'] ?? '');
        if (isset($promotedOrigins[$physicalKey])) {
            continue;
        }
        $filesById['legacy:' . $physicalKey] = [
            'filename' => (string) ($track['filename'] ?? ''),
            'byte_size' => 0,
            'origin_gallery_id' => (int) ($track['gallery_id'] ?? 0),
            'origin_track_id' => (string) ($track['track_id'] ?? ''),
            'kind' => 'legacy',
        ];
    }
    $files = array_values($filesById);
    $galleryTitles = [];
    foreach (($usage['direct_galleries'] ?? []) as $gallery) {
        $galleryTitles[(int) ($gallery['gallery_id'] ?? 0)] =
            (string) ($gallery['title'] ?? '');
    }
    $tagLabels = is_array($model['song_tag_labels'][$id] ?? null)
        ? $model['song_tag_labels'][$id]
        : [];
    echo '<tr><td class="admin-music-title"><div class="admin-music-title-line">'
        . '<div class="admin-music-select"><input type="checkbox" name="song_ids[]" value="'
        . $id . '" form="admin-music-merge" aria-label="' . e($title) . '">';
    view_admin_music_title_form($model, $id, $title);
    echo '</div></div></td><td><details class="admin-music-variants">'
        . '<summary title="' . e(t('admin.music.files', 'Files'))
        . '" aria-label="' . e(t('admin.music.files', 'Files'))
        . '"><span class="subgallery-stack-icon" aria-hidden="true">'
        . '<span></span><span></span><span></span></span>'
        . '<span class="subgallery-stack-count">'
        . count($files) . '</span></summary><ul>';
    foreach ($files as $file) {
        $trackGalleryId = (int) ($file['origin_gallery_id'] ?? 0);
        $galleryTitle = e($galleryTitles[$trackGalleryId] ?? ('#' . $trackGalleryId));
        $galleryUrl = (string) ($model['gallery_urls'][$trackGalleryId] ?? '');
        echo '<li>';
        if ($file['kind'] === 'asset') {
            echo '<span>' . e(t(
                'admin.music.shared_file',
                'Shared repository file',
            )) . '</span>';
        } elseif ($galleryUrl !== '') {
            echo '<a href="' . e($galleryUrl) . '">' . $galleryTitle . '</a>';
        } else {
            echo $galleryTitle;
        }
        echo ' · ' . e((string) $file['filename']);
        if ((int) $file['byte_size'] > 0) {
            echo ' <small>' . (int) $file['byte_size'] . ' '
                . e(t('admin.music.bytes', 'bytes')) . '</small>';
        }
        echo '</li>';
    }
    echo '</ul></details></td><td><details class="admin-music-locations"><summary>' . $usageCount . '</summary><ul>';
    foreach (($usage['direct_galleries'] ?? []) as $gallery) {
        $galleryId = (int) ($gallery['gallery_id'] ?? 0);
        $galleryTitle = e((string) ($gallery['title'] ?? ''));
        $galleryUrl = (string) ($model['gallery_urls'][$galleryId] ?? '');
        $filenames = [];
        foreach ($tracks as $track) {
            if ((int) ($track['gallery_id'] ?? 0) === $galleryId) {
                $filenames[] = (string) ($track['filename'] ?? '');
            }
        }
        echo '<li>';
        if ($galleryUrl !== '') {
            echo '<a href="' . e($galleryUrl) . '">' . $galleryTitle . '</a>';
        } else {
            echo $galleryTitle;
        }
        echo ' · ' . (int) ($gallery['track_count'] ?? 0);
        if ($filenames !== []) {
            echo '<small>' . e(implode(', ', $filenames)) . '</small>';
        }
        echo '</li>';
    }
    foreach (($usage['albums'] ?? []) as $album) {
        $usageAlbumId = (int) ($album['album_id'] ?? 0);
        echo '<li><a href="' . e(\Gallery\Core\url_for(
            'admin_music',
            ['tab' => 'albums', 'album_id' => $usageAlbumId],
        ) . '#admin-music-tab-albums') . '">' . view_admin_music_album_path(
            $model['albums'],
            $usageAlbumId,
        ) . '</a> · ' . e(t('admin.music.album', 'Album')) . '</li>';
    }
    foreach (($usage['live_albums'] ?? []) as $placement) {
        $galleryId = (int) ($placement['gallery_id'] ?? 0);
        $galleryTitle = e((string) ($placement['gallery_title'] ?? ''));
        $galleryUrl = (string) ($model['gallery_urls'][$galleryId] ?? '');
        echo '<li>';
        if ($galleryUrl !== '') {
            echo '<a href="' . e($galleryUrl) . '">' . $galleryTitle . '</a>';
        } else {
            echo $galleryTitle;
        }
        echo ' · ' . e((string) ($placement['album_title'] ?? '')) . '</li>';
    }
    echo '</ul></details></td><td>';
    $songTagText = (string) ($model['song_tags'][$id] ?? '');
    if ($tagLabels !== []) {
        echo '<span class="admin-music-tag-list">';
        foreach ($tagLabels as $tagLabel) {
            echo '<span class="admin-music-tag">' . e((string) $tagLabel)
                . '</span>';
        }
        echo '</span>';
    }
    view_admin_music_tag_form(
        $model,
        $id,
        $songTagText,
    );
    echo '</td>';
    if ($showSplitColumn) {
        echo '<td>';
        view_admin_music_split_form($model, $id, $tracks);
        echo '</td>';
    }
    echo '</tr>';
}

/**
 * Build a readable full path for a nested music album.
 *
 * @param list<array{id:int|string,parent_id:int|string|null,title:string,description:string,sort_order:int|string,revision:int|string,song_count:int|string}> $albums Prepared flat album hierarchy.
 * @param int $albumId Album identifier to resolve.
 * @return string Escaped album path joined by a breadcrumb separator.
 */
function view_admin_music_album_path(array $albums, int $albumId): string
{
    $byId = [];
    foreach ($albums as $album) {
        $byId[(int) $album['id']] = $album;
    }
    $parts = [];
    $visited = [];
    while ($albumId > 0 && isset($byId[$albumId]) && !isset($visited[$albumId])) {
        $visited[$albumId] = true;
        array_unshift($parts, e((string) $byId[$albumId]['title']));
        $albumId = (int) ($byId[$albumId]['parent_id'] ?? 0);
        if (count($parts) > ADMIN_MUSIC_ALBUM_RENDER_MAX_DEPTH) {
            break;
        }
    }
    return implode(' › ', $parts);
}

/**
 * Render the exact custom-title editor for one logical song.
 *
 * @param array{query:string,tab:string,return_tab:string,tab_urls:array{songs:string,albums:string},offset:int,limit:int,sort:string,direction:string,sort_urls:array{title:string,files:string,locations:string,tags:string},catalog:array{revision:string,songs:list<array{id:int|string,title:string,track_count:int|string,album_count:int|string,direct_gallery_count:int|string}>,total:int|string},song_details:array<int,array{tracks:list<array{gallery_id:int,track_id:string,filename:string,asset_id:?string}>,repository_assets:list<array{id:string,filename:string,byte_size:int,sha256:string,origin_gallery_id:?int,origin_track_id:?string}>,direct_galleries:list<array{song_id:int,gallery_id:int|string,title:string,track_count:int|string}>,albums:list<array{song_id:int,album_id:int|string,title:string,track_count:int|string}>,live_albums:list<array{song_id:int,gallery_id:int,gallery_title:string,album_id:int,album_title:string,track_count:int|string}>}>,albums:list<array{id:int|string,parent_id:int|string|null,title:string,description:string,sort_order:int|string,revision:int|string,song_count:int|string}>,album_id:int,album_offset:int,album_members:list<array{id:int|string,title:string,custom_title:string,indexed_title:string,filename:string}>,revision:string,extensions:bool,song_tags:array<int,string>,song_tag_labels:array<int,list<string>>,gallery_urls:array<int,string>,reconcile_cursor:int,dirty_status:array{state:string,count:int|null},csrf_html:string,action_url:string,notice:string,error:string,fragment:bool} $model Page state.
 * @param int $songId Logical song identifier.
 * @param string $title Current catalog title.
 * @return void Emits title, album-assignment and row-context controls.
 */
function view_admin_music_title_form(array $model, int $songId, string $title): void
{
    $existingAlbumIds = [];
    foreach (($model['song_details'][$songId]['albums'] ?? []) as $albumUsage) {
        $existingAlbumIds[(int) ($albumUsage['album_id'] ?? 0)] = true;
    }
    echo '<details class="admin-music-edit admin-music-song-edit"><summary title="'
        . e(t('admin.music.save_title', 'Edit title'))
        . '" class="admin-music-song-title" data-admin-music-title-edit><span>'
        . e($title)
        . '</span></summary><div class="admin-music-song-popover"><form method="post" action="'
        . e((string) $model['action_url'])
        . '" data-admin-music-form class="admin-music-inline">' . $model['csrf_html']
        . '<input type="hidden" name="revision" value="'
        . e((string) $model['revision']) . '"><input type="hidden" name="action"'
        . ' value="title"><input type="hidden" name="song_id" value="'
        . $songId . '"><label><span class="sr-only">'
        . e(t('admin.music.title_label', 'Title'))
        . '</span><textarea name="title" rows="1" wrap="soft"'
        . ' maxlength="240">' . e($title) . '</textarea></label>'
        . '<button type="submit" title="' . e(t('admin.music.save_title', 'Save title'))
        . '" aria-label="' . e(t('admin.music.save_title', 'Save title')) . '">' . view_admin_menu_icon('update') . '</button></form>';
    if (($model['albums'] ?? []) !== []) {
        echo '<form method="post" action="' . e((string) $model['action_url'])
            . '" data-admin-music-form class="admin-music-album-add-song">'
            . $model['csrf_html'] . '<input type="hidden" name="revision" value="'
            . e((string) $model['revision']) . '"><input type="hidden" name="action"'
            . ' value="album_add_song"><input type="hidden" name="song_id" value="'
            . $songId . '"><input type="hidden" name="position" value="">'
            . '<label><span class="sr-only">'
            . e(t('admin.music.add_to_album', 'Add to album'))
            . '</span><select name="album_id" required><option value="">'
            . e(t('admin.music.add_to_album', 'Add to album')) . '</option>';
        foreach ($model['albums'] as $album) {
            $albumId = (int) ($album['id'] ?? 0);
            echo '<option value="' . $albumId . '"'
                . (isset($existingAlbumIds[$albumId]) ? ' disabled' : '') . '>'
                . view_admin_music_album_path($model['albums'], $albumId)
                . (isset($existingAlbumIds[$albumId])
                    ? ' · ' . e(t('admin.music.already_in_album', 'Already in album'))
                    : '') . '</option>';
        }
        echo '</select></label><button type="submit" title="'
            . e(t('admin.music.add_to_album', 'Add to album')) . '" aria-label="'
            . e(t('admin.music.add_to_album', 'Add to album')) . '">'
            . view_admin_menu_icon('create') . '</button></form>';
    } else {
        echo '<a class="admin-music-album-create-link" href="'
            . e((string) $model['tab_urls']['albums']) . '">'
            . e(t('admin.music.album_create', 'Add album')) . '</a>';
    }
    echo '<div class="admin-music-context-actions">'
        . '<button type="button" class="admin-music-context-button"'
        . ' data-admin-music-context="tags" title="'
        . e(t('admin.music.tags', 'Tags')) . '" aria-label="'
        . e(t('admin.music.tags', 'Tags')) . '">' . view_admin_menu_icon('tags')
        . '<span>' . e(t('admin.music.tags', 'Tags')) . '</span></button>'
        . '<button type="button" class="admin-music-context-button"'
        . ' data-admin-music-context="files" title="'
        . e(t('admin.music.files', 'Files')) . '" aria-label="'
        . e(t('admin.music.files', 'Files')) . '">'
        . view_admin_menu_icon('galleries') . '<span>'
        . e(t('admin.music.files', 'Files')) . '</span></button>'
        . '<button type="button" class="admin-music-context-button"'
        . ' data-admin-music-context="locations" title="'
        . e(t('admin.music.locations', 'Locations')) . '" aria-label="'
        . e(t('admin.music.locations', 'Locations')) . '">'
        . view_admin_menu_icon('galleries') . '<span>'
        . e(t('admin.music.locations', 'Locations'))
        . '</span></button></div></div></details>';
}

/**
 * Render an editor for this song's music-only shared taxonomy assignments.
 *
 * @param array{query:string,tab:string,return_tab:string,tab_urls:array{songs:string,albums:string},offset:int,limit:int,sort:string,direction:string,sort_urls:array{title:string,files:string,locations:string,tags:string},catalog:array{revision:string,songs:list<array{id:int|string,title:string,track_count:int|string,album_count:int|string,direct_gallery_count:int|string}>,total:int|string},song_details:array<int,array{tracks:list<array{gallery_id:int,track_id:string,filename:string,asset_id:?string}>,repository_assets:list<array{id:string,filename:string,byte_size:int,sha256:string,origin_gallery_id:?int,origin_track_id:?string}>,direct_galleries:list<array{song_id:int,gallery_id:int|string,title:string,track_count:int|string}>,albums:list<array{song_id:int,album_id:int|string,title:string,track_count:int|string}>,live_albums:list<array{song_id:int,gallery_id:int,gallery_title:string,album_id:int,album_title:string,track_count:int|string}>}>,albums:list<array{id:int|string,parent_id:int|string|null,title:string,description:string,sort_order:int|string,revision:int|string,song_count:int|string}>,album_id:int,album_offset:int,album_members:list<array{id:int|string,title:string,custom_title:string,indexed_title:string,filename:string}>,revision:string,extensions:bool,song_tags:array<int,string>,song_tag_labels:array<int,list<string>>,gallery_urls:array<int,string>,reconcile_cursor:int,dirty_status:array{state:string,count:int|null},csrf_html:string,action_url:string,notice:string,error:string,fragment:bool} $model Prepared page state.
 * @param int $songId Logical song identifier.
 * @param string $tags Current comma-separated music tag names.
 * @return void Emits the tag editor disclosure.
 */
function view_admin_music_tag_form(
    array $model,
    int $songId,
    string $tags,
): void {
    echo '<details class="admin-music-edit admin-music-tags"><summary title="'
        . e(t('admin.music.tags', 'Tags')) . '" aria-label="'
        . e(t('admin.music.tags', 'Tags')) . '">' . view_admin_menu_icon('tags') . '</summary><form method="post" action="'
        . e((string) $model['action_url']) . '" data-admin-music-form>'
        . $model['csrf_html'] . '<input type="hidden" name="revision" value="'
        . e((string) $model['revision']) . '"><input type="hidden" name="action"'
        . ' value="tags"><input type="hidden" name="song_id" value="' . $songId
        . '"><label><span class="sr-only">' . e(t('admin.music.tags', 'Tags'))
        . '</span><input name="tags" value="' . e($tags) . '" maxlength="2000"></label>'
        . '<button type="submit" title="' . e(t('admin.music.save', 'Save'))
        . '" aria-label="' . e(t('admin.music.save', 'Save')) . '">' . view_admin_menu_icon('update') . '</button></form></details>';
}

/**
 * Render a split control for a song with more than one physical variant.
 *
 * @param array{query:string,tab:string,return_tab:string,tab_urls:array{songs:string,albums:string},offset:int,limit:int,sort:string,direction:string,sort_urls:array{title:string,files:string,locations:string,tags:string},catalog:array{revision:string,songs:list<array{id:int|string,title:string,track_count:int|string,album_count:int|string,direct_gallery_count:int|string}>,total:int|string},song_details:array<int,array{tracks:list<array{gallery_id:int,track_id:string,filename:string,asset_id:?string}>,repository_assets:list<array{id:string,filename:string,byte_size:int,sha256:string,origin_gallery_id:?int,origin_track_id:?string}>,direct_galleries:list<array{song_id:int,gallery_id:int|string,title:string,track_count:int|string}>,albums:list<array{song_id:int,album_id:int|string,title:string,track_count:int|string}>,live_albums:list<array{song_id:int,gallery_id:int,gallery_title:string,album_id:int,album_title:string,track_count:int|string}>}>,albums:list<array{id:int|string,parent_id:int|string|null,title:string,description:string,sort_order:int|string,revision:int|string,song_count:int|string}>,album_id:int,album_offset:int,album_members:list<array{id:int|string,title:string,custom_title:string,indexed_title:string,filename:string}>,revision:string,extensions:bool,song_tags:array<int,string>,song_tag_labels:array<int,list<string>>,gallery_urls:array<int,string>,reconcile_cursor:int,dirty_status:array{state:string,count:int|null},csrf_html:string,action_url:string,notice:string,error:string,fragment:bool} $model Page state.
 * @param int $songId Logical song identifier.
 * @param list<array{gallery_id:int,track_id:string,filename:string}> $tracks Physical variants.
 * @return void Emits variant checkboxes and a split action when applicable.
 */
function view_admin_music_split_form(array $model, int $songId, array $tracks): void
{
    if (count($tracks) < 2) {
        return;
    }
    echo '<details class="admin-music-split"><summary title="'
        . e(t('admin.music.split', 'Separate variants')) . '" aria-label="'
        . e(t('admin.music.split', 'Separate variants')) . '">' . view_admin_menu_icon('galleries') . '</summary><form method="post"'
        . ' action="' . e((string) $model['action_url']) . '" data-admin-music-form>'
        . $model['csrf_html'] . '<input type="hidden" name="revision" value="'
        . e((string) $model['revision']) . '"><input type="hidden" name="action"'
        . ' value="split"><input type="hidden" name="song_id" value="' . $songId . '">';
    foreach ($tracks as $index => $track) {
        echo '<label><input type="checkbox" name="track_ids[]" value="'
            . (int) ($track['gallery_id'] ?? 0) . ':'
            . e((string) ($track['track_id'] ?? '')) . '"'
            . ($index > 0 ? ' checked' : '') . '>'
            . e((string) ($track['filename'] ?? '')) . '</label>';
    }
    echo '<button type="submit" data-confirm="'
        . e(t('admin.music.confirm_split', 'Separate the selected variants?'))
        . '" title="' . e(t('admin.music.split', 'Separate variants'))
        . '" aria-label="' . e(t('admin.music.split', 'Separate variants'))
        . '">' . view_admin_menu_icon('galleries') . '</button></form></details>';
}

/**
 * Render the flat album data as a nested tree with breadcrumb context.
 *
 * @param array{query:string,tab:string,return_tab:string,tab_urls:array{songs:string,albums:string},offset:int,limit:int,sort:string,direction:string,sort_urls:array{title:string,files:string,locations:string,tags:string},catalog:array{revision:string,songs:list<array{id:int|string,title:string,track_count:int|string,album_count:int|string,direct_gallery_count:int|string}>,total:int|string},song_details:array<int,array{tracks:list<array{gallery_id:int,track_id:string,filename:string,asset_id:?string}>,repository_assets:list<array{id:string,filename:string,byte_size:int,sha256:string,origin_gallery_id:?int,origin_track_id:?string}>,direct_galleries:list<array{song_id:int,gallery_id:int|string,title:string,track_count:int|string}>,albums:list<array{song_id:int,album_id:int|string,title:string,track_count:int|string}>,live_albums:list<array{song_id:int,gallery_id:int,gallery_title:string,album_id:int,album_title:string,track_count:int|string}>}>,albums:list<array{id:int|string,parent_id:int|string|null,title:string,description:string,sort_order:int|string,revision:int|string,song_count:int|string}>,album_id:int,album_offset:int,album_members:list<array{id:int|string,title:string,custom_title:string,indexed_title:string,filename:string}>,revision:string,extensions:bool,song_tags:array<int,string>,song_tag_labels:array<int,list<string>>,gallery_urls:array<int,string>,reconcile_cursor:int,dirty_status:array{state:string,count:int|null},csrf_html:string,action_url:string,notice:string,error:string,fragment:bool} $model Prepared albums and current selection.
 * @return void Emits album navigation and relationship controls.
 */
function view_admin_music_album_tree(array $model): void
{
    $albums = is_array($model['albums'] ?? null) ? $model['albums'] : [];
    $selected = (int) ($model['album_id'] ?? 0);
    echo '<h2>' . e(t('admin.music.tab_albums', 'Albums & playlists')) . '</h2>';
    echo '<form method="post" action="' . e((string) $model['action_url'])
        . '" data-admin-music-form class="admin-music-new-album">' . $model['csrf_html']
        . '<input type="hidden" name="revision" value="'
        . e((string) $model['revision']) . '"><input type="hidden" name="action"'
        . ' value="album_create"><label><span class="sr-only">'
        . e(t('admin.music.album_name', 'Album name')) . '</span><input name="album_title"'
        . ' maxlength="240" required placeholder="'
        . e(t('admin.music.album_name', 'Album name')) . '"></label>'
        . '<details class="admin-music-album-options"><summary title="'
        . e(t('admin.music.parent_album', 'Parent album')) . '" aria-label="'
        . e(t('admin.music.parent_album', 'Parent album')) . '">'
        . view_admin_menu_icon('settings') . '</summary>'
        . '<div class="admin-music-album-fields"><label>'
        . e(t('admin.music.parent_album', 'Parent album'))
        . '<select name="parent_id">'
        . '<option value="0">' . e(t('admin.music.root_album', 'Top level')) . '</option>';
    foreach ($albums as $album) {
        echo '<option value="' . (int) $album['id'] . '">'
            . e((string) $album['title']) . '</option>';
    }
    echo '</select></label><label>'
        . e(t('admin.music.album_description', 'Description'))
        . '<textarea name="album_description" maxlength="4000"></textarea>'
        . '</label></div></details><button type="submit" title="'
        . e(t('admin.music.album_create', 'Add album'))
        . '" aria-label="' . e(t('admin.music.album_create', 'Add album'))
        . '">' . view_admin_menu_icon('create') . '</button></form>';
    echo '<ul class="admin-music-album-tree">';
    foreach ($albums as $album) {
        if ((int) ($album['parent_id'] ?? 0) === 0) {
            view_admin_music_album_node($model, $album, $albums, 0);
        }
    }
    echo '</ul>';
}

/**
 * Render one album and all of its children recursively, opening the selected path.
 *
 * @param array{query:string,tab:string,return_tab:string,tab_urls:array{songs:string,albums:string},offset:int,limit:int,sort:string,direction:string,sort_urls:array{title:string,files:string,locations:string,tags:string},catalog:array{revision:string,songs:list<array{id:int|string,title:string,track_count:int|string,album_count:int|string,direct_gallery_count:int|string}>,total:int|string},song_details:array<int,array{tracks:list<array{gallery_id:int,track_id:string,filename:string,asset_id:?string}>,repository_assets:list<array{id:string,filename:string,byte_size:int,sha256:string,origin_gallery_id:?int,origin_track_id:?string}>,direct_galleries:list<array{song_id:int,gallery_id:int|string,title:string,track_count:int|string}>,albums:list<array{song_id:int,album_id:int|string,title:string,track_count:int|string}>,live_albums:list<array{song_id:int,gallery_id:int,gallery_title:string,album_id:int,album_title:string,track_count:int|string}>}>,albums:list<array{id:int|string,parent_id:int|string|null,title:string,description:string,sort_order:int|string,revision:int|string,song_count:int|string}>,album_id:int,album_offset:int,album_members:list<array{id:int|string,title:string,custom_title:string,indexed_title:string,filename:string}>,revision:string,extensions:bool,song_tags:array<int,string>,song_tag_labels:array<int,list<string>>,gallery_urls:array<int,string>,reconcile_cursor:int,dirty_status:array{state:string,count:int|null},csrf_html:string,action_url:string,notice:string,error:string,fragment:bool} $model Prepared page state.
 * @param array{id:int|string,parent_id:int|string|null,title:string,description:string,sort_order:int|string,revision:int|string,song_count:int|string} $album Album row with direct membership count.
 * @param list<array{id:int|string,parent_id:int|string|null,title:string,description:string,sort_order:int|string,revision:int|string,song_count:int|string}> $albums Complete bounded album tree rows.
 * @param int $depth Current hierarchy depth for indentation.
 * @return void Emits the album node and descendants.
 */
function view_admin_music_album_node(
    array $model,
    array $album,
    array $albums,
    int $depth,
): void {
    if ($depth > ADMIN_MUSIC_ALBUM_RENDER_MAX_DEPTH) {
        return;
    }
    $id = (int) $album['id'];
    $children = array_filter($albums, static fn(array $child): bool =>
        (int) ($child['parent_id'] ?? 0) === $id);
    if ($children !== []) {
        $selectedId = (int) ($model['album_id'] ?? 0);
        $containsSelected = $selectedId > 0
            && view_admin_music_album_contains($selectedId, $id, $albums);
        echo '<li><details class="admin-music-album-branch"'
            . ($containsSelected ? ' open' : '') . '><summary><a href="'
            . e(\Gallery\Core\url_for('admin_music', [
                'tab' => 'albums', 'album_id' => $id,
            ]) . '#admin-music-tab-albums')
            . '"' . ($selectedId === $id ? ' aria-current="page"' : '')
            . '>' . e((string) $album['title']) . '</a><span>'
            . (int) ($album['song_count'] ?? 0) . '</span></summary><ul>';
        foreach ($children as $child) {
            view_admin_music_album_node($model, $child, $albums, $depth + 1);
        }
        echo '</ul></details></li>';
        return;
    }
    echo '<li><a href="' . e(\Gallery\Core\url_for('admin_music', [
        'tab' => 'albums', 'album_id' => $id,
    ]) . '#admin-music-tab-albums')
        . '"' . ((int) ($model['album_id'] ?? 0) === $id ? ' aria-current="page"' : '')
        . '>' . e((string) $album['title']) . '</a><span>'
        . (int) ($album['song_count'] ?? 0) . '</span></li>';
}

/**
 * Determine whether an album is the selected node or one of its ancestors.
 * @param int $selectedId Selected album identifier.
 * @param int $albumId Album identifier to test as an ancestor.
 * @param list<array{id:int|string,parent_id:int|string|null,title:string,description:string,sort_order:int|string,revision:int|string,song_count:int|string}> $albums Complete bounded album tree rows.
 * @return bool True when the selected album is this node or its descendant within the render depth limit.
 */
function view_admin_music_album_contains(
    int $selectedId,
    int $albumId,
    array $albums,
): bool {
    $parents = [];
    foreach ($albums as $album) {
        $parents[(int) $album['id']] = (int) ($album['parent_id'] ?? 0);
    }
    $remainingDepth = ADMIN_MUSIC_ALBUM_RENDER_MAX_DEPTH;
    while (
        $remainingDepth > 0
        && $selectedId > 0
        && isset($parents[$selectedId])
    ) {
        if ($selectedId === $albumId) {
            return true;
        }
        $selectedId = $parents[$selectedId];
        $remainingDepth--;
    }
    return false;
}

/**
 * Render selected album songs and progressively disclosed metadata controls.
 *
 * @param array{query:string,tab:string,return_tab:string,tab_urls:array{songs:string,albums:string},offset:int,limit:int,sort:string,direction:string,sort_urls:array{title:string,files:string,locations:string,tags:string},catalog:array{revision:string,songs:list<array{id:int|string,title:string,track_count:int|string,album_count:int|string,direct_gallery_count:int|string}>,total:int|string},song_details:array<int,array{tracks:list<array{gallery_id:int,track_id:string,filename:string,asset_id:?string}>,repository_assets:list<array{id:string,filename:string,byte_size:int,sha256:string,origin_gallery_id:?int,origin_track_id:?string}>,direct_galleries:list<array{song_id:int,gallery_id:int|string,title:string,track_count:int|string}>,albums:list<array{song_id:int,album_id:int|string,title:string,track_count:int|string}>,live_albums:list<array{song_id:int,gallery_id:int,gallery_title:string,album_id:int,album_title:string,track_count:int|string}>}>,albums:list<array{id:int|string,parent_id:int|string|null,title:string,description:string,sort_order:int|string,revision:int|string,song_count:int|string}>,album_id:int,album_offset:int,album_members:list<array{id:int|string,title:string,custom_title:string,indexed_title:string,filename:string}>,revision:string,extensions:bool,song_tags:array<int,string>,song_tag_labels:array<int,list<string>>,gallery_urls:array<int,string>,reconcile_cursor:int,dirty_status:array{state:string,count:int|null},csrf_html:string,action_url:string,notice:string,error:string,fragment:bool} $model Prepared page state.
 * @param int $albumId Selected album identifier.
 * @param list<array{id:int|string,parent_id:int|string|null,title:string,description:string,sort_order:int|string,revision:int|string,song_count:int|string}> $albums Album rows with membership counts.
 * @return void Emits selected album details.
 */
function view_admin_music_album_detail(
    array $model,
    int $albumId,
    array $albums,
): void {
    $album = null;
    foreach ($albums as $candidate) {
        if ((int) $candidate['id'] === $albumId) {
            $album = $candidate;
            break;
        }
    }
    if ($album === null) {
        return;
    }
    echo '<section class="admin-music-album-detail"><nav aria-label="'
        . e(t('admin.music.album_path', 'Album path')) . '">'
        . view_admin_music_album_breadcrumbs($albums, $albumId)
        . '</nav><header class="admin-music-album-heading"><h3>'
        . e((string) $album['title']) . '</h3><details class="admin-music-album-options">'
        . '<summary title="' . e(t('admin.music.album_settings', 'Album settings'))
        . '" aria-label="' . e(t('admin.music.album_settings', 'Album settings')) . '">'
        . view_admin_menu_icon('settings') . '</summary>'
        . '<div class="admin-music-album-fields"><form method="post" action="'
        . e((string) $model['action_url']) . '" data-admin-music-form>'
        . $model['csrf_html'] . '<input type="hidden" name="revision" value="'
        . e((string) $model['revision']) . '"><input type="hidden" name="album_id" value="'
        . $albumId . '"><input type="hidden" name="action" value="album_update">'
        . '<label><span class="sr-only">' . e(t('admin.music.album_name', 'Album name'))
        . '</span><input name="album_title" value="' . e((string) $album['title'])
        . '" maxlength="240" required></label><label><span class="sr-only">'
        . e(t('admin.music.album_description', 'Description'))
        . '</span><textarea name="album_description" maxlength="4000">'
        . e((string) ($album['description'] ?? ''))
        . '</textarea></label><button type="submit" title="'
        . e(t('admin.music.save', 'Save')) . '" aria-label="'
        . e(t('admin.music.save', 'Save')) . '">' . view_admin_menu_icon('update') . '</button></form>';
    echo '<form method="post" action="' . e((string) $model['action_url'])
        . '" data-admin-music-form class="admin-music-album-move">'
        . $model['csrf_html'] . '<input type="hidden" name="revision" value="'
        . e((string) $model['revision']) . '"><input type="hidden" name="action"'
        . ' value="album_move"><input type="hidden" name="album_id" value="'
        . $albumId . '"><label><span class="sr-only">'
        . e(t('admin.music.parent_album', 'Parent album')) . '</span><select name="parent_id">'
        . '<option value="0">' . e(t('admin.music.root_album', 'Top level')) . '</option>';
    foreach ($albums as $candidate) {
        if ((int) $candidate['id'] === $albumId) {
            continue;
        }
        echo '<option value="' . (int) $candidate['id'] . '"'
            . ((int) $candidate['id'] === (int) ($album['parent_id'] ?? 0)
                ? ' selected' : '') . '>'
            . view_admin_music_album_path($albums, (int) $candidate['id'])
            . '</option>';
    }
    echo '</select></label><label><span class="sr-only">'
        . e(t('admin.music.position', 'Position'))
        . '</span><input name="position" type="number" min="0" value="'
        . (int) ($album['sort_order'] ?? 0) . '"></label><button type="submit" title="'
        . e(t('admin.music.move_album', 'Move album')) . '" aria-label="'
        . e(t('admin.music.move_album', 'Move album')) . '">'
        . view_admin_menu_icon('galleries') . '</button></form>';
    echo '<form method="post" action="' . e((string) $model['action_url'])
        . '" data-admin-music-form>' . $model['csrf_html']
        . '<input type="hidden" name="revision" value="'
        . e((string) $model['revision']) . '"><input type="hidden" name="album_id" value="'
        . $albumId . '"><input type="hidden" name="action" value="album_delete">'
        . '<button type="submit" data-confirm="'
        . e(t('admin.music.confirm_album_delete', 'Delete this album and its subalbums?'))
        . '" title="' . e(t('admin.music.delete_album', 'Delete album'))
        . '" aria-label="' . e(t('admin.music.delete_album', 'Delete album'))
        . '">' . view_admin_menu_icon('trash') . '</button></form></div></details></header>';
    if (trim((string) ($album['description'] ?? '')) !== '') {
        echo '<p class="admin-music-album-description">'
            . e((string) $album['description']) . '</p>';
    }
    echo '<form method="post" action="' . e((string) $model['action_url'])
        . '" data-admin-music-form class="admin-music-album-add-song">'
        . $model['csrf_html'] . '<input type="hidden" name="revision" value="'
        . e((string) $model['revision']) . '"><input type="hidden" name="album_id" value="'
        . $albumId . '"><input type="hidden" name="action" value="album_add_song">'
        . '<input type="hidden" name="position" value="'
        . (int) ($album['song_count'] ?? 0) . '">'
        . '<label><span class="sr-only">' . e(t('admin.music.add_song', 'Add song'))
        . '</span><select name="song_id" required><option value="">'
        . e(t('admin.music.add_song', 'Add song')) . '</option>';
    foreach (($model['catalog']['songs'] ?? []) as $song) {
        $alreadyMember = false;
        foreach (($model['song_details'][(int) $song['id']]['albums'] ?? []) as $usage) {
            if ((int) ($usage['album_id'] ?? 0) === $albumId) {
                $alreadyMember = true;
                break;
            }
        }
        echo '<option value="' . (int) $song['id'] . '"'
            . ($alreadyMember ? ' disabled' : '') . '>'
            . e((string) $song['title'])
            . ($alreadyMember ? ' · '
                . e(t('admin.music.already_in_album', 'Already in album')) : '')
            . '</option>';
    }
    echo '</select></label><button type="submit" title="'
        . e(t('admin.music.add_song', 'Add song')) . '" aria-label="'
        . e(t('admin.music.add_song', 'Add song')) . '">' . view_admin_menu_icon('create')
        . '</button></form><ul class="admin-music-album-members"'
        . ' data-admin-music-album-members data-album-id="' . $albumId
        . '" data-album-offset="' . (int) $model['album_offset'] . '">';
    foreach (($model['album_members'] ?? []) as $position => $member) {
        $songId = (int) $member['id'];
        $absolutePosition = (int) $model['album_offset'] + (int) $position;
        echo '<li data-song-id="' . $songId . '" data-position="'
            . $absolutePosition . '"><button type="button" draggable="true"'
            . ' class="admin-music-album-drag-handle"'
            . ' data-admin-music-album-drag-handle aria-grabbed="false" title="'
            . e(t('music.drag_to_reorder', 'Drag to reorder')) . '" aria-label="'
            . e(t('music.drag_to_reorder', 'Drag to reorder')) . '">'
            . view_admin_menu_icon('drag') . '</button><span class="admin-music-album-member-title">'
            . e((string) $member['title']) . '</span>'
            . '<form method="post" action="' . e((string) $model['action_url'])
            . '" data-admin-music-form>' . $model['csrf_html']
            . '<input type="hidden" name="revision" value="'
            . e((string) $model['revision']) . '"><input type="hidden" name="action"'
            . ' value="album_reorder_song"><input type="hidden" name="album_id" value="'
            . $albumId . '"><input type="hidden" name="song_id" value="' . $songId
            . '"><input type="hidden" name="position" value="'
            . max(0, (int) $model['album_offset'] + $position - 1) . '"><button type="submit"'
            . ((int) $model['album_offset'] + $position === 0 ? ' disabled' : '')
            . ' title="'
            . e(t('music.up', 'Move up')) . '" aria-label="'
            . e(t('music.up', 'Move up')) . '">' . view_admin_menu_icon('up')
            . '</button></form><form method="post" action="'
            . e((string) $model['action_url']) . '" data-admin-music-form>'
            . $model['csrf_html'] . '<input type="hidden" name="revision" value="'
            . e((string) $model['revision']) . '"><input type="hidden" name="action"'
            . ' value="album_reorder_song"><input type="hidden" name="album_id" value="'
            . $albumId . '"><input type="hidden" name="song_id" value="' . $songId
            . '"><input type="hidden" name="position" value="'
            . ((int) $model['album_offset'] + $position + 1) . '"><button type="submit"'
            . ((int) $model['album_offset'] + $position + 1 >= (int) $album['song_count']
                ? ' disabled' : '')
            . ' title="'
            . e(t('music.down', 'Move down')) . '" aria-label="'
            . e(t('music.down', 'Move down')) . '">' . view_admin_menu_icon('down')
            . '</button></form><form method="post" action="'
            . e((string) $model['action_url'])
            . '" data-admin-music-form>' . $model['csrf_html']
            . '<input type="hidden" name="revision" value="'
            . e((string) $model['revision']) . '"><input type="hidden" name="action"'
            . ' value="album_remove_song"><input type="hidden" name="album_id" value="'
            . $albumId . '"><input type="hidden" name="song_id" value="'
            . (int) $songId . '"><button type="submit" title="'
            . e(t('admin.music.remove_song', 'Remove from album')) . '" aria-label="'
            . e(t('admin.music.remove_song', 'Remove from album')) . '" data-confirm="'
            . e(t('admin.music.confirm_remove_song', 'Remove this song from the album?'))
            . '">' . view_admin_menu_icon('trash') . '</button></form></li>';
    }
    echo '</ul><nav class="admin-music-pagination" aria-label="'
        . e(t('admin.music.album_path', 'Album songs')) . '">';
    if ((int) $model['album_offset'] > 0) {
        echo '<a href="' . e(\Gallery\Core\url_for('admin_music', [
            'tab' => 'albums',
            'album_id' => $albumId,
            'album_offset' => max(0, (int) $model['album_offset'] - (int) $model['limit']),
        ]) . '#admin-music-tab-albums') . '">' . e(t('admin.music.previous', 'Previous')) . '</a>';
    }
    if (count($model['album_members']) >= (int) $model['limit']) {
        echo '<a href="' . e(\Gallery\Core\url_for('admin_music', [
            'tab' => 'albums',
            'album_id' => $albumId,
            'album_offset' => (int) $model['album_offset'] + (int) $model['limit'],
        ]) . '#admin-music-tab-albums') . '">' . e(t('admin.music.next', 'Next')) . '</a>';
    }
    echo '</nav></section>';
}

/**
 * Render navigable ancestors without repeating the selected album heading.
 *
 * @param list<array{id:int|string,parent_id:int|string|null,title:string,description:string,sort_order:int|string,revision:int|string,song_count:int|string}> $albums Prepared album hierarchy.
 * @param int $albumId Selected album identifier.
 * @return string Escaped ancestor links separated by arrows, or empty at root.
 */
function view_admin_music_album_breadcrumbs(array $albums, int $albumId): string
{
    $byId = [];
    foreach ($albums as $album) {
        $byId[(int) $album['id']] = $album;
    }
    $chain = [];
    $visited = [];
    while ($albumId > 0 && isset($byId[$albumId]) && !isset($visited[$albumId])) {
        $visited[$albumId] = true;
        $chain[] = $byId[$albumId];
        $albumId = (int) ($byId[$albumId]['parent_id'] ?? 0);
        if (count($chain) > ADMIN_MUSIC_ALBUM_RENDER_MAX_DEPTH) {
            break;
        }
    }
    $parts = [];
    // The first collected node is already named by the selected album heading.
    foreach (array_reverse(array_slice($chain, 1)) as $album) {
        $parts[] = '<a href="' . e(\Gallery\Core\url_for('admin_music', [
            'tab' => 'albums',
            'album_id' => (int) $album['id'],
        ]) . '#admin-music-tab-albums') . '">' . e((string) $album['title']) . '</a>';
    }
    return implode(' › ', $parts);
}

/**
 * Render previous/next controls for a bounded catalog page.
 *
 * @param array{query:string,tab:string,return_tab:string,tab_urls:array{songs:string,albums:string},offset:int,limit:int,sort:string,direction:string,sort_urls:array{title:string,files:string,locations:string,tags:string},catalog:array{revision:string,songs:list<array{id:int|string,title:string,track_count:int|string,album_count:int|string,direct_gallery_count:int|string}>,total:int|string},song_details:array<int,array{tracks:list<array{gallery_id:int,track_id:string,filename:string,asset_id:?string}>,repository_assets:list<array{id:string,filename:string,byte_size:int,sha256:string,origin_gallery_id:?int,origin_track_id:?string}>,direct_galleries:list<array{song_id:int,gallery_id:int|string,title:string,track_count:int|string}>,albums:list<array{song_id:int,album_id:int|string,title:string,track_count:int|string}>,live_albums:list<array{song_id:int,gallery_id:int,gallery_title:string,album_id:int,album_title:string,track_count:int|string}>}>,albums:list<array{id:int|string,parent_id:int|string|null,title:string,description:string,sort_order:int|string,revision:int|string,song_count:int|string}>,album_id:int,album_offset:int,album_members:list<array{id:int|string,title:string,custom_title:string,indexed_title:string,filename:string}>,revision:string,extensions:bool,song_tags:array<int,string>,song_tag_labels:array<int,list<string>>,gallery_urls:array<int,string>,reconcile_cursor:int,dirty_status:array{state:string,count:int|null},csrf_html:string,action_url:string,notice:string,error:string,fragment:bool} $model Prepared query, offset, total, and limit.
 * @return void Emits pagination links where another page exists.
 */
function view_admin_music_pagination(array $model): void
{
    $offset = (int) $model['offset'];
    $limit = (int) $model['limit'];
    $total = (int) ($model['catalog']['total'] ?? 0);
    echo '<nav class="admin-music-pagination" aria-label="'
        . e(t('admin.music.pagination', 'Song pages')) . '">';
    if ($offset > 0) {
        echo '<a href="' . e(\Gallery\Core\url_for('admin_music', [
            'q' => (string) $model['query'], 'offset' => max(0, $offset - $limit),
            'tab' => 'songs',
            'album_id' => (int) $model['album_id'],
            'sort' => (string) $model['sort'],
            'direction' => (string) $model['direction'],
        ])) . '">' . e(t('admin.music.previous', 'Previous')) . '</a>';
    }
    if ($offset + $limit < $total) {
        echo '<a href="' . e(\Gallery\Core\url_for('admin_music', [
            'q' => (string) $model['query'], 'offset' => $offset + $limit,
            'tab' => 'songs',
            'album_id' => (int) $model['album_id'],
            'sort' => (string) $model['sort'],
            'direction' => (string) $model['direction'],
        ])) . '">' . e(t('admin.music.next', 'Next')) . '</a>';
    }
    echo '</nav>';
}


