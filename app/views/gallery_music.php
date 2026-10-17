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
 * Render a bounded playlist disclosure with the always-visible miniplayer.
 * @param array{gallery_id:int, editor:bool, action_url:string,
 *     csrf_html:string, revision:string, enabled:bool, error:string,
 *     tracks:list<array{id:string, title:string, filename:string,
 *     mime:string, size:int, sha256:string, hidden:bool, downloadable:bool, url:string,
 *     download_url:string}>} $model Authorized playlist and optional editor
 *     controls.
 * @return void Emit row Play/Stop toggles, player data and independent forms.
 */
function view_render_gallery_music(array $model): void
{
    if (!$model['editor'] && $model['tracks'] === []) return;
    echo '<section class="gallery-music" data-gallery-music data-music-editor="'
        . ($model['editor'] ? '1' : '0') . '" data-music-gallery="'
        . $model['gallery_id'] . '" data-music-error="'
        . e(t('music.failed')) . '">';
    if ($model['error'] !== '') {
        if ($model['editor']) echo '<p role="alert">' . e($model['error']) . '</p>';
        echo '</section>';
        return;
    }
    $surface = $model['editor'] ? 'editor' : 'public';
    if (!$model['editor']) {
        // The silent metadata reader fills the total even with a closed list.
        echo '<dl class="gallery-media-summary">'
            . '<div><dt>' . e(t('media_summary.tracks')) . '</dt><dd>'
            . count($model['tracks']) . '</dd></div><div><dt>'
            . e(t('media_summary.duration'))
            . '</dt><dd data-music-duration-total title="'
            . e(t('music.duration_unknown')) . '">…</dd></div></dl>';
    }
    view_media_panel_start(
        'gallery-music-list-' . $model['gallery_id'] . '-' . $surface,
        t('music.title'),
        count($model['tracks']),
        view_music_control_icon('music')
    );
    echo '<table class="gallery-music-table"><thead><tr><th scope="col">'
        . e(t('music.track'))
        . '</th><th scope="col" class="gallery-music-duration">'
        . e(t('music.duration')) . '</th>';
    echo '<th scope="col" class="gallery-music-download">';
    if ($model['editor'] && $model['tracks'] !== []) {
        $allAllowed = !in_array(false, array_column(
            $model['tracks'], 'downloadable'
        ), true);
        view_render_gallery_music_form_start($model);
        view_render_music_download_toggle($allAllowed, true);
        echo '</form>';
    } else {
        echo '<span class="visually-hidden">' . e(t('music.download')) . '</span>';
    }
    echo '</th>';
    if ($model['editor']) {
        echo '<th scope="col">' . e(t('music.order')) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($model['tracks'] as $position => $track) {
        echo '<tr data-music-track="' . e($track['id']) . '">'
            . '<td><div class="music-track-line">'
            . '<button type="button" class="music-icon music-track-toggle"'
            . ' data-music-action="toggle" data-music-track-id="'
            . e($track['id']) . '" data-music-play-label="'
            . e(t('music.play')) . '" data-music-stop-label="'
            . e(t('music.stop')) . '" aria-label="' . e(t('music.play'))
            . '" title="' . e(t('music.play')) . '" aria-pressed="false">'
            . '<span data-music-toggle-play>'
            . view_music_control_icon('play') . '</span>'
            . '<span data-music-toggle-stop hidden>'
            . view_music_control_icon('stop') . '</span></button>'
            . '<span class="music-track-name" data-music-title title="'
            . e($track['title']) . '"><span data-music-title-text>'
            . e($track['title']) . '</span></span></div></td>'
            . '<td class="gallery-music-duration" data-music-duration'
            . ' data-music-src="' . e($track['url']) . '" title="'
            . e(t('music.duration_unknown')) . '">…';
        echo '</td><td class="gallery-music-download">';
        if ($model['editor']) {
            view_render_gallery_music_form_start($model);
            echo '<input type="hidden" name="track_id" value="'
                . e($track['id']) . '">';
            view_render_music_download_toggle($track['downloadable'], false);
            echo '</form>';
        } elseif ($track['download_url'] !== '') {
            echo '<a class="music-icon" href="' . e($track['download_url'])
                . '" download title="' . e(t('music.download'))
                . '" aria-label="' . e(t('music.download')) . '">'
                . view_music_control_icon('download') . '</a>';
        }
        echo '</td>';
        if ($model['editor']) {
            echo '<td>';
            view_render_gallery_music_form_start($model);
            echo '<input type="hidden" name="track_id" value="'
                . e($track['id']) . '">';
            foreach ([
                'previous' => 'music.up',
                'next' => 'music.down',
                'delete' => 'music.remove',
            ] as $action => $key) {
                $disabled = ($action === 'previous' && $position === 0)
                    || ($action === 'next' && $position === count($model['tracks']) - 1);
                $icon = match ($action) {
                    'previous' => 'up', 'next' => 'down', default => 'delete',
                };
                echo '<button type="submit" class="music-icon"'
                    . ' name="music_action" value="' . e($action)
                    . '" title="' . e(t($key)) . '" aria-label="' . e(t($key))
                    . '"' . ($disabled ? ' disabled' : '') . '>'
                    . view_music_control_icon($icon) . '</button>';
            }
            echo '<output data-music-status role="status"></output></form></td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
    if ($model['editor']) {
        view_render_gallery_music_form_start($model);
        echo '<label>' . e(t('music.upload'))
            . '<input type="file" name="music_file"'
            . ' accept=".mp3,.m4a,.aac,.ogg,.oga,.opus,.wav,.flac,.webm,.weba"'
            . ' required></label>' . '<p>' . e(t('music.upload_help')) . '</p>'
            . '<button type="submit" name="music_action" value="upload">'
            . e(t('music.upload'))
            . '</button><output data-music-status role="status"></output></form>';
        view_render_gallery_music_form_start($model);
        echo '<label><input type="checkbox" name="music_enabled" value="1"'
            . ($model['enabled'] ? ' checked' : '')
            . '> ' . e(t('music.default')) . '</label>'
            . '<button type="submit" name="music_action" value="settings">'
            . e(t('music.save_default'))
            . '</button><output data-music-status role="status"></output></form>';
    }
    view_media_panel_end();
    if ($model['tracks'] !== []) {
        $json = json_encode([
            'galleryId' => $model['gallery_id'],
            'revision' => $model['revision'],
            'enabled' => $model['enabled'],
            'tracks' => array_map(
                static fn(array $track): array => [
                    'id' => $track['id'],
                    'url' => $track['url'],
                    'title' => $track['title'],
                ],
                $model['tracks']
            ),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        echo '<script type="application/json" data-gallery-music-playlist'
            . ' data-music-gallery="'
            . $model['gallery_id'] . '" data-music-editor="'
            . ($model['editor'] ? '1' : '0') . '">' . $json . '</script>';
        echo view_music_player_controls(false);
    }
    echo '</section>';
}

/**
 * Open a feature-owned form with its prepared endpoint and revision identity.
 * @param array{gallery_id:int, editor:bool, action_url:string,
 *     csrf_html:string, revision:string, enabled:bool, error:string,
 *     tracks:list<array{id:string, title:string, filename:string,
 *     mime:string, size:int, sha256:string, hidden:bool, downloadable:bool, url:string,
 *     download_url:string}>} $model Prepared authenticated editor data.
 * @return void Emit the opening form and hidden transport controls.
 */
function view_render_gallery_music_form_start(array $model): void
{
    echo '<form method="post" enctype="multipart/form-data" action="'
        . e($model['action_url'])
        . '" data-gallery-music-form>' . $model['csrf_html']
        . '<input type="hidden" name="id" value="' . $model['gallery_id'] . '">'
        . '<input type="hidden" name="music_revision" value="'
        . e($model['revision']) . '">';
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
    echo '<input type="hidden" name="music_download_enabled" value="'
        . ($allowed ? '0' : '1') . '">'
        . '<button type="submit" class="music-icon" name="music_action"'
        . ' value="' . ($all ? 'download_all' : 'download') . '"'
        . ' aria-pressed="' . ($allowed ? 'true' : 'false') . '"'
        . ' title="' . e(t($key)) . '" aria-label="' . e(t($key)) . '">'
        . view_music_control_icon($allowed ? 'download' : 'locked')
        . '</button><output data-music-status role="status"></output>';
}
