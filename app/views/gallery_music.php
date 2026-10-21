<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/views/gallery_music.php
 * Module Type: View
 * Purpose: Render a compact gallery music table and independent editor forms.
 * Responsibilities: Escape prepared metadata and present playlist controls.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Views;

require_once __DIR__ . '/public_gallery_lightbox.php';

use function Gallery\Core\e;
use function Gallery\Services\t;

/**
 * Render an independent per-song/album archive preference control.
 *
 * The existing music download flag controls standalone downloads only. This
 * control is explicit ZIP opt-in, with higher-level global/gallery overrides
 * preserving the saved item preference. Kept outside music's AJAX forms.
 *
 * @param array{gallery_id:int,editor:bool,action_url:string,csrf_html:string,
 *     archive_gallery?:bool,archive_global?:bool} $model Editor identity.
 * @param string $itemId Stable direct, album or projected track identifier.
 * @param bool $allowed Stored item-level ZIP archive preference.
 * @return void Emit a CSRF-guarded Archive inclusion toggle.
 */
function view_render_gallery_music_archive_toggle(
    array $model,
    string $itemId,
    bool $allowed,
): void {
    if (
        empty($model['editor'])
        || preg_match('/^[a-f0-9]{32}$/D', $itemId) !== 1
    ) {
        return;
    }
    $label = $allowed
        ? t('download.archive.item_exclude', 'Exclude from gallery ZIP')
        : t('download.archive.item_include', 'Include in gallery ZIP');
    $override = !empty($model['archive_global'])
        || !empty($model['archive_gallery']);
    echo '<form method="post" action="'
        . e((string) $model['action_url'])
        . '" class="gallery-archive-item-form">'
        . (string) $model['csrf_html']
        . '<input type="hidden" name="id" value="'
        . (int) $model['gallery_id']
        . '"><input type="hidden" name="archive_media_action" value="save">'
        . '<input type="hidden" name="archive_media_scope" value="item">'
        . '<input type="hidden" name="archive_media_kind" value="music">'
        . '<input type="hidden" name="archive_media_item_id" value="'
        . e($itemId)
        . '"><input type="hidden" name="archive_media_enabled" value="'
        . ($allowed ? '0' : '1')
        . '"><button class="music-icon" type="submit" aria-label="'
        . e($label) . '" title="' . e($label)
        . ($override ? ' (global/gallery override)' : '')
        . '" aria-pressed="' . ($allowed ? 'true' : 'false')
        . '">' . view_music_control_icon($allowed ? 'download' : 'locked')
        . '</button></form>';
}

/**
 * Render a bounded playlist disclosure with the always-visible miniplayer.
 * @param array{gallery_id:int, editor:bool, action_url:string,
 *     csrf_html:string, revision:string, write_revision?:string,
 *     enabled:bool, error:string,
 *     tracks:list<array{id:string, title:string, filename:string,
 *     mime:string, size:int, sha256:string, hidden:bool, downloadable:bool, url:string,
 *     download_url:string,source_id?:string,source_type?:string,
 *     source_title?:string,source_track_id?:string}>,
 *     sources?:list<array{id:string,type:string,song_id:?int,album_id:?int,
 *     title:string,downloadable:bool,hidden:bool,track_count:int,
 *     position:int,path?:string}>,choices?:array{revision:string,
 *     songs:list<array{id:int,title:string,track_count:int}>,
 *     albums:list<array{id:int,title:string,song_count:int,path:string}>},
 *     choices_error?:string,query?:string,unavailable?:int,omitted?:int,
 *     partial?:bool,
 *     over_limit?:bool,query_limit?:int
 * } $model Authorized playlist and optional editor controls.
 * @return void Emit row Play/Stop toggles, player data and independent forms.
 */
function view_render_gallery_music(array $model): void
{
    if (
        !$model['editor']
        && $model['tracks'] === []
        && empty($model['partial'])
        && empty($model['over_limit'])
    ) {
        return;
    }
    echo
        '<section class="gallery-music" data-gallery-music data-music-editor="'
            . ($model['editor'] ? '1' : '0')
            . '" data-music-gallery="'
            . $model['gallery_id']
            . '" data-music-enabled="'
            . ($model['enabled'] ? '1' : '0')
            . '" data-music-error="'
            . e(t('music.failed'))
            . '" data-music-refresh-error="'
            . e(t('music.refresh_failed'))
            . '" data-music-preference-saving="'
            . e(t('music.preference_saving'))
            . '" data-music-preference-saved="'
            . e(t('music.preference_saved'))
            . '" data-music-search-error="'
            . e(t('music.search_failed'))
            . '">';
    if ($model['error'] !== '') {
        if ($model['editor'])
            echo '<p role="alert">' . e($model['error']) . '</p>';
        echo '</section>';
        return;
    }
    $surface = $model['editor'] ? 'editor' : 'public';
    if (!$model['editor']) {
        // The silent metadata reader fills the total even with a closed list.
        echo
            '<dl class="gallery-media-summary">'
                . '<div><dt>'
                . e(t('media_summary.tracks'))
                . '</dt><dd>'
                . count($model['tracks'])
                . '</dd></div><div><dt>'
                . e(t('media_summary.duration'))
                . '</dt><dd data-music-duration-total title="'
                . e(t('music.duration_unknown'))
                . '">…</dd></div></dl>';
    }
    view_media_panel_start(
        'gallery-music-list-' . $model['gallery_id'] . '-' . $surface,
        t('music.title'),
        count($model['tracks']),
        view_music_control_icon('music'),
        $model['editor'],
        $model['editor'] && $model['tracks'] !== []
            ? view_music_player_controls(false)
            : '',
    );
    if (!empty($model['over_limit'])) {
        echo '<p class="gallery-music-warning" role="status">'
            . e(t('music.over_limit_notice')) . '</p>';
    }
    if (!empty($model['partial'])) {
        echo '<p class="gallery-music-warning" role="status">'
            . e(t('music.partial_notice')) . '</p>';
    }
    if ((int) ($model['unavailable'] ?? 0) > 0) {
        echo '<p class="gallery-music-warning" role="status">'
            . e(t('music.unavailable_notice', '{count} assigned item(s) are currently unavailable.', [
                'count' => (int) $model['unavailable'],
            ])) . '</p>';
    }
    if ($model['editor']) {
        view_render_gallery_music_assignment_picker($model);
        echo '<p class="gallery-music-status" data-music-status'
            . ' role="status" aria-live="polite"></p>';
    }
    echo
        '<table class="gallery-music-table"><colgroup>'
            . '<col class="gallery-music-title-column">'
            . '<col class="gallery-music-duration-column">'
            . '<col class="gallery-music-download-column">'
            . ($model['editor']
                ? '<col class="gallery-music-order-column">' : '')
            . '</colgroup><thead><tr><th scope="col">'
            . e(t('music.track'))
            . '</th><th scope="col" class="gallery-music-duration">'
            . e(t('music.duration'))
            . '</th>';
    echo '<th scope="col" class="gallery-music-download">'
        . '<span class="visually-hidden">'
        . e(t('music.download'))
        . '</span>';
    if ($model['editor'] && $model['tracks'] !== []) {
        $allAllowed = !in_array(
            false,
            array_column(
                $model['tracks'],
                'downloadable',
            ),
            true,
        );
        view_render_gallery_music_form_start($model);
        view_render_music_download_toggle($allAllowed, true);
        echo '</form>';
    } else {
        echo
            '<span class="visually-hidden">'
                . e(t('music.download'))
                . '</span>';
    }
    echo '</th>';
    if ($model['editor']) {
        echo '<th scope="col"><span class="visually-hidden">'
            . e(t('music.order')) . '</span></th>';
    }
    echo '</tr></thead><tbody>';
    $sourcePositions = [];
    foreach (($model['sources'] ?? []) as $sourcePosition => $source) {
        $sourcePositions[$source['id']] = $sourcePosition;
    }
    $renderedSources = [];
    foreach ($model['tracks'] as $position => $track) {
        $sourceId = (string) ($track['source_id'] ?? $track['id']);
        $source = null;
        foreach (($model['sources'] ?? []) as $candidate) {
            if ($candidate['id'] === $sourceId) {
                $source = $candidate;
                break;
            }
        }
        if ($model['editor'] && $source !== null
            && $source['type'] === 'album'
            && !isset($renderedSources[$sourceId])) {
            $renderedSources[$sourceId] = true;
            $sourcePosition = (int) ($sourcePositions[$sourceId] ?? 0);
            echo '<tr class="gallery-music-source" data-music-source="'
                . e($sourceId) . '"><th scope="rowgroup" colspan="4">'
                . '<div class="gallery-music-source-line">'
                . '<span>' . e($source['title']) . '</span>'
                . '<span class="gallery-music-source-kind">'
                . e(t($source['type'] === 'album'
                    ? 'music.live_album' : 'music.individual_song'))
                . '</span>';
            view_render_gallery_music_form_start($model);
            echo '<input type="hidden" name="selection_id" value="'
                . ($source['type'] === 'album'
                    ? (int) $source['album_id']
                    : (int) $source['song_id']) . '">'
                . '<input type="hidden" name="track_id" value="'
                . e($sourceId) . '">'
                . '<input type="hidden" name="music_position" value="'
                . $sourcePosition
                . '">';
            foreach ([
                'previous' => ['music.up', 'up'],
                'next' => ['music.down', 'down'],
                'delete' => ['music.remove', 'delete'],
            ] as $action => [$labelKey, $icon]) {
                $disabled = ($action === 'previous' && $sourcePosition === 0)
                    || ($action === 'next'
                        && $sourcePosition === count($sourcePositions) - 1);
                echo '<button type="submit" class="music-icon"'
                    . ' name="music_action" value="' . e($action) . '"'
                    . ' title="' . e(t($labelKey)) . '" aria-label="'
                    . e(t($labelKey)) . '"'
                    . ($disabled ? ' disabled' : '') . '>'
                    . view_music_control_icon($icon) . '</button>';
            }
            view_render_music_download_toggle(
                $source['downloadable'],
                false,
            );
            echo '</form>';
            view_render_gallery_music_archive_toggle(
                $model,
                (string) $sourceId,
                !empty($source['archive_in_zip']),
            );
            echo '</div></th></tr>';
        }
        if ($model['editor'] && $source !== null
            && $source['type'] === 'direct') {
            $renderedSources[$sourceId] = true;
        }
        echo
            '<tr data-music-track="'
                . e($track['id'])
                . ($model['editor'] && $source !== null
                    && $source['type'] === 'direct'
                    ? '" data-music-source="' . e($sourceId)
                    : '')
                . '">'
                . '<td><div class="music-track-line">'
                . '<button type="button" class="music-icon music-track-toggle"'
                . ' data-music-action="toggle" data-music-track-id="'
                . e($track['id'])
                . '" data-music-play-label="'
                . e(t('music.play'))
                . '" data-music-stop-label="'
                . e(t('music.stop'))
                . '" aria-label="'
                . e(t('music.play'))
                . '" title="'
                . e(t('music.play'))
                . '" aria-pressed="false">'
                . '<span data-music-toggle-play>'
                . view_music_control_icon('play')
                . '</span>'
                . '<span data-music-toggle-stop hidden>'
                . view_music_control_icon('stop')
                . '</span></button>'
                . '<span class="music-track-name" data-music-title title="'
                . e($track['title'])
                . '"><span data-music-title-text>'
                . e($track['title'])
                . '</span></span></div></td>'
                . '<td class="gallery-music-duration" data-music-duration'
                . ' data-music-src="'
                . e($track['url'])
                . '" title="'
                . e(t('music.duration_unknown'))
                . '">…';
        echo '</td><td class="gallery-music-download">';
        if ($model['editor'] && $source !== null
            && $source['type'] === 'direct') {
            view_render_gallery_music_form_start($model);
            echo '<input type="hidden" name="selection_id" value="'
                . (int) $source['song_id'] . '">'
                . '<input type="hidden" name="track_id" value="'
                . e($sourceId) . '">';
            view_render_music_download_toggle(
                $source['downloadable'],
                false,
            );
            echo '</form>';
            view_render_gallery_music_archive_toggle(
                $model,
                (string) $track['id'],
                !empty($track['archive_in_zip']),
            );
        } elseif ($model['editor']) {
            view_render_gallery_music_archive_toggle(
                $model,
                (string) $track['id'],
                !empty($track['archive_in_zip']),
            );
            echo '<span class="visually-hidden">'
                . e(t('music.download')) . '</span>';
        } elseif ($track['download_url'] !== '') {
            echo
                '<a class="music-icon" href="'
                    . e($track['download_url'])
                    . '" download title="'
                    . e(t('music.download'))
                    . '" aria-label="'
                    . e(t('music.download'))
                    . '">'
                    . view_music_control_icon('download')
                    . '</a>';
        }
        echo '</td>';
        if ($model['editor']) {
            echo '<td>';
            if ($source !== null && $source['type'] === 'direct') {
                $sourcePosition = (int) ($sourcePositions[$sourceId] ?? 0);
                foreach ([
                    'previous' => ['music.up', 'up'],
                    'next' => ['music.down', 'down'],
                    'delete' => ['music.remove', 'delete'],
                ] as $action => [$labelKey, $icon]) {
                    view_render_gallery_music_form_start($model);
                    echo '<input type="hidden" name="selection_id" value="'
                        . (int) $source['song_id'] . '">'
                        . '<input type="hidden" name="track_id" value="'
                        . e($sourceId) . '">'
                        . '<input type="hidden" name="music_position" value="'
                        . $sourcePosition . '">';
                    $disabled = ($action === 'previous'
                            && $sourcePosition === 0)
                        || ($action === 'next'
                            && $sourcePosition === count($sourcePositions) - 1);
                    echo '<button type="submit" class="music-icon"'
                        . ' name="music_action" value="' . e($action) . '"'
                        . ' title="' . e(t($labelKey))
                        . '" aria-label="' . e(t($labelKey)) . '"'
                        . ($disabled ? ' disabled' : '') . '>'
                        . view_music_control_icon($icon)
                        . '</button></form>';
                }
            } else {
                echo '<span class="visually-hidden">'
                    . e(t('music.order')) . '</span>';
            }
        }
        echo '</tr>';
    }
    if ($model['editor']) {
        foreach (($model['sources'] ?? []) as $sourcePosition => $source) {
            if (isset($renderedSources[$source['id']])) {
                continue;
            }
            $sourceId = (string) $source['id'];
            echo '<tr class="gallery-music-source" data-music-source="'
                . e($sourceId) . '"><th scope="rowgroup" colspan="4">'
                . '<div class="gallery-music-source-line">'
                . '<span>' . e($source['title']) . '</span>'
                . '<span class="gallery-music-source-kind">'
                . e(t($source['type'] === 'album'
                    ? 'music.live_album' : 'music.individual_song'))
                . '</span>';
            view_render_gallery_music_form_start($model);
            echo '<input type="hidden" name="selection_id" value="'
                . ($source['type'] === 'album'
                    ? (int) $source['album_id']
                    : (int) $source['song_id']) . '">'
                . '<input type="hidden" name="track_id" value="'
                . e($sourceId) . '">'
                . '<input type="hidden" name="music_position" value="'
                . (int) $sourcePosition . '">';
            foreach ([
                'previous' => ['music.up', 'up'],
                'next' => ['music.down', 'down'],
                'delete' => ['music.remove', 'delete'],
            ] as $action => [$labelKey, $icon]) {
                $disabled = ($action === 'previous' && $sourcePosition === 0)
                    || ($action === 'next'
                        && $sourcePosition === count($sourcePositions) - 1);
                echo '<button type="submit" class="music-icon"'
                    . ' name="music_action" value="' . e($action) . '"'
                    . ' title="' . e(t($labelKey)) . '" aria-label="'
                    . e(t($labelKey)) . '"'
                    . ($disabled ? ' disabled' : '') . '>'
                    . view_music_control_icon($icon) . '</button>';
            }
            view_render_music_download_toggle(
                $source['downloadable'],
                false,
            );
            echo '</form>';
            view_render_gallery_music_archive_toggle(
                $model,
                (string) $sourceId,
                !empty($source['archive_in_zip']),
            );
            echo '</div></th></tr>';
        }
    }
    echo '</tbody></table>';
    if ($model['editor']) {
        view_render_gallery_music_form_start($model, 'gallery-music-upload');
        echo
            '<label>'
                . e(t('music.upload'))
                . '<input type="file" name="music_file"'
                . ' accept=".mp3,.m4a,.aac,.ogg,.oga,.opus,.wav,.flac,.webm,.weba"'
                . ' required></label>'
                . '<p>'
                . e(t('music.upload_help'))
                . '</p>'
                . '<button type="submit" class="music-icon" name="music_action"'
                . ' value="upload" title="'
                . e(t('music.upload'))
                . '" aria-label="'
                . e(t('music.upload'))
                . '">'
                . view_music_control_icon('upload')
                . '</button><output data-music-status role="status"></output></form>';
        view_render_gallery_music_form_start($model, 'gallery-music-settings');
        echo
            '<label class="gallery-presentation-setting">'
                . '<input type="checkbox" role="switch"'
                . ' data-music-default-switch name="music_enabled" value="1"'
                . ($model['enabled'] ? ' checked' : '')
                . '><span>'
                . e(t('music.default'))
                . '</span></label>'
                . '<output data-music-preference-status role="status"></output>'
                . '<noscript><button type="submit" name="music_action"'
                . ' value="settings">'
                . e(t('music.save_default'))
                . '</button></noscript></form>';
    }
    view_media_panel_end($model['editor']);
    if ($model['tracks'] !== []) {
        $json = json_encode(
            [
                'galleryId' => $model['gallery_id'],
                'revision' => $model['revision'],
                'enabled' => $model['enabled'],
                'tracks' => array_map(
                    static fn(array $track): array => [
                        'id' => $track['id'],
                        'url' => $track['url'],
                        'title' => $track['title'],
                    ],
                    $model['tracks'],
                ),
            ],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );
        echo
            '<script type="application/json" data-gallery-music-playlist'
                . ' data-music-gallery="'
                . $model['gallery_id']
                . '" data-music-editor="'
                . ($model['editor'] ? '1' : '0')
                . '">'
                . $json
                . '</script>';
        if (!$model['editor']) {
            echo view_music_player_controls(false);
        }
    }
    echo '</section>';
}

/**
 * Render a bounded chooser for live albums and independent song assignments.
 * @param array{gallery_id:int,editor:bool,action_url:string,csrf_html:string,
 *     revision:string,write_revision?:string,enabled:bool,error:string,
 *     tracks:list<array{id:string,title:string,filename:string,mime:string,
 *     size:int,sha256:string,hidden:bool,downloadable:bool,url:string,
 *     download_url:string}>,sources?:list<array{id:string,type:string,
 *     song_id:?int,album_id:?int,title:string,downloadable:bool,hidden:bool,
 *     track_count:int,position:int,path?:string}>,choices?:array{revision:string,
 *     songs:list<array{id:int,title:string,track_count:int}>,
 *     albums:list<array{id:int,title:string,song_count:int,path:string}>},
 *     query?:string,query_limit?:int,choices_error?:string}
 *     $model Prepared editor choices and mutation identity.
 * @return void Emit independent search and assignment forms.
 */
function view_render_gallery_music_assignment_picker(array $model): void
{
    $choices = $model['choices'] ?? [
        'revision' => '',
        'songs' => [],
        'albums' => [],
    ];
    echo '<details class="gallery-music-picker"><summary>'
        . e(t('music.add_from_catalog')) . '</summary>'
        . '<form method="post" action="' . e($model['action_url'])
        . '" data-gallery-music-search>' . $model['csrf_html']
        . '<input type="hidden" name="id" value="'
        . $model['gallery_id'] . '">'
        . '<label><span>' . e(t('music.search_catalog')) . '</span>'
        . '<input type="search" name="music_query" maxlength="'
        . (int) ($model['query_limit'] ?? 0) . '" value="'
        . e((string) ($model['query'] ?? '')) . '"'
        . ' autocomplete="off" data-music-catalog-query></label>'
        . '<button type="submit" class="music-icon" name="music_action"'
        . ' value="choices" title="' . e(t('music.search_catalog'))
        . '" aria-label="' . e(t('music.search_catalog')) . '">'
        . view_music_control_icon('search') . '</button></form>'
        . '<div data-music-catalog-results aria-live="polite">';
    if (($model['choices_error'] ?? '') !== '') {
        echo '<p role="status">' . e($model['choices_error']) . '</p>';
    }
    view_render_gallery_music_choice_rows($model, $choices);
    echo '</div></details>';
}

/**
 * Render one server-prepared bounded batch of assignable catalog entries.
 * @param array{gallery_id:int,editor:bool,action_url:string,csrf_html:string,
 *     revision:string,write_revision?:string,enabled:bool,error:string,
 *     tracks:list<array{id:string,title:string,filename:string,mime:string,
 *     size:int,sha256:string,hidden:bool,downloadable:bool,url:string,
 *     download_url:string}>,choices?:array{revision:string,
 *     songs:list<array{id:int,title:string,track_count:int}>,
 *     albums:list<array{id:int,title:string,song_count:int,path:string}>}}
 *     $model Editor identity and form transport.
 * @param array{revision:string,songs:list<array{id:int,title:string,
 *     track_count:int}>,albums:list<array{id:int,title:string,song_count:int,
 *     path:string}>} $choices Bounded catalog results.
 * @return void Emit album and song assignment forms.
 */
function view_render_gallery_music_choice_rows(
    array $model,
    array $choices,
): void {
    foreach ($choices['albums'] as $album) {
        view_render_gallery_music_choice_form(
            $model,
            'add_album',
            (int) $album['id'],
            (string) $album['title'],
            (string) $album['path'],
            (int) $album['song_count'],
        );
    }
    foreach ($choices['songs'] as $song) {
        view_render_gallery_music_choice_form(
            $model,
            'add_song',
            (int) $song['id'],
            (string) $song['title'],
            '',
            (int) $song['track_count'],
        );
    }
}

/**
 * Render one independent assignment form for an album or canonical song.
 * @param array{gallery_id:int,editor:bool,action_url:string,csrf_html:string,
 *     revision:string,write_revision?:string,enabled:bool,error:string,
 *     tracks:list<array{id:string,title:string,filename:string,mime:string,
 *     size:int,sha256:string,hidden:bool,downloadable:bool,url:string,
 *     download_url:string}>} $model Editor form transport.
 * @param string $action Supported assignment action.
 * @param int $selectionId Catalog album or song identifier.
 * @param string $title Display title.
 * @param string $path Full album breadcrumb, empty for songs.
 * @param int $count Current album members or available song variants.
 * @return void Emit one localized button form.
 */
function view_render_gallery_music_choice_form(
    array $model,
    string $action,
    int $selectionId,
    string $title,
    string $path,
    int $count,
): void {
    $label = $action === 'add_album'
        ? t('music.add_live_album')
        : t('music.add_individual_song');
    $detail = $action === 'add_album'
        ? t('music.album_songs', '{count} songs', ['count' => $count])
        : t('music.song_variants', '{count} available file(s)', [
            'count' => $count,
        ]);
    echo '<form method="post" action="' . e($model['action_url'])
        . '" data-gallery-music-form>' . $model['csrf_html']
        . '<input type="hidden" name="id" value="'
        . $model['gallery_id'] . '">'
        . '<input type="hidden" name="music_revision" value="'
        . e((string) ($model['write_revision'] ?? $model['revision'])) . '">'
        . '<input type="hidden" name="selection_id" value="'
        . $selectionId . '">'
        . '<input type="hidden" name="catalog_revision" value="'
        . e((string) ($model['choices']['revision'] ?? '')) . '">'
        . '<button type="submit" name="music_action" value="'
        . e($action) . '" title="' . e($label) . '" aria-label="'
        . e($label . ': ' . $title) . '">'
        . view_music_control_icon('add') . '<span>' . e($title)
        . '</span><small>' . e($label . ' · '
            . ($path !== '' ? $path . ' · ' . $detail : $detail))
        . '</small></button></form>';
}

/**
 * Open a feature-owned form with its prepared endpoint and revision identity.
 * @param array{gallery_id:int, editor:bool, action_url:string,
 *     csrf_html:string, revision:string, write_revision?:string,
 *     enabled:bool, error:string,
 *     tracks:list<array{id:string, title:string, filename:string,
 *     mime:string, size:int, sha256:string, hidden:bool, downloadable:bool, url:string,
 *     download_url:string}>} $model Prepared authenticated editor data.
 * @param string $class Optional presentation class for the upload or preference row.
 * @return void Emit the opening form and hidden transport controls.
 */
function view_render_gallery_music_form_start(
    array $model,
    string $class = '',
): void
{
    echo
        '<form method="post" enctype="multipart/form-data" action="'
            . e($model['action_url'])
            . '" class="' . e($class) . '" data-gallery-music-form>'
            . $model['csrf_html']
            . '<input type="hidden" name="id" value="'
            . $model['gallery_id']
            . '">'
            . '<input type="hidden" name="music_revision" value="'
            . e((string) ($model['write_revision'] ?? $model['revision']))
            . '">';
}

/**
 * Render a compact administrator toggle with an explicit desired permission.
 * @param bool $allowed Whether this track or every existing track allows
 *     download.
 * @param bool $all Whether the action applies to every currently stored track.
 * @return void Emit an icon submitter and desired state inside the owning form.
 */
function view_render_music_download_toggle(bool $allowed, bool $all): void
{
    $key = $all
        ? ($allowed ? 'music.lock_all' : 'music.allow_all')
        : ($allowed ? 'music.lock_download' : 'music.allow_download');
    echo
        '<input type="hidden" name="music_download_enabled" value="'
            . ($allowed ? '0' : '1')
            . '">'
            . '<button type="submit" class="music-icon" name="music_action"'
            . ' value="'
            . ($all ? 'download_all' : 'download')
            . '"'
            . ' aria-pressed="'
            . ($allowed ? 'true' : 'false')
            . '"'
            . ' title="'
            . e(t($key))
            . '" aria-label="'
            . e(t($key))
            . '">'
            . view_music_control_icon($allowed ? 'download' : 'locked')
            . '</button><output data-music-status role="status"></output>';
}
