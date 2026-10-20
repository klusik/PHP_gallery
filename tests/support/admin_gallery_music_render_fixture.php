<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/admin_gallery_music_render_fixture.php
 * Module Type: Browser Render Fixture
 * Purpose: Render the production gallery media tabs and local playlist editor.
 * Responsibilities: Provide bounded deterministic controller-prepared view models.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Escape one prepared fixture value at the HTML presentation boundary.
     * @param string $value Fixture text or attribute value.
     * @return string HTML-safe value.
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Build the same-origin gallery editor route used by production forms.
     * @param string $route Registered route name.
     * @param array<string,int|string> $parameters Route parameters.
     * @return string Local route URL.
     */
    function url_for(string $route, array $parameters = []): string
    {
        return '/index.php?' . http_build_query(
            ['page' => $route] + $parameters,
        );
    }

    /** Format a small prepared attachment size for the production PDF view.
     * @param int $bytes Non-negative fixture size.
     * @return string Localized fixture size.
     */
    function format_bytes(int $bytes): string
    {
        return $bytes . ' B';
    }
}

namespace Gallery\Services {
    /** Return stable English labels and interpolate prepared numeric values.
     * @param string $key Translation key used by the production view.
     * @param string $fallback Optional localized fallback label.
     * @param array<string,string|int> $parameters Interpolation values.
     * @return string Deterministic fixture translation.
     */
    function t(
        string $key,
        string $fallback = '',
        array $parameters = [],
    ): string {
        $labels = [
            'admin.music.title' => 'Music',
            'admin.gallery_editor.pdf_documents' => 'PDF documents',
            'presentation.settings' => 'Presentation',
            'music.title' => 'Music',
            'music.track' => 'Track',
            'music.duration' => 'Duration',
            'music.download' => 'Download',
            'music.order' => 'Order',
            'music.live_album' => 'Live album',
            'music.individual_song' => 'Individual song',
            'music.add_from_catalog' => 'Add from music catalog',
            'music.search_catalog' => 'Search songs and albums',
            'music.add_live_album' => 'Add live album',
            'music.add_individual_song' => 'Add individual song',
            'music.album_songs' => '{count} songs',
            'music.song_variants' => '{count} available files',
            'music.failed' => 'Music operation failed.',
            'music.refresh_failed' => 'Saved, but the playlist could not be refreshed.',
            'music.preference_saving' => 'Saving music preference…',
            'music.preference_saved' => 'Music preference saved.',
            'music.search_failed' => 'Catalog results could not be refreshed.',
            'music.play' => 'Play',
            'music.stop' => 'Stop',
            'music.duration_unknown' => 'Duration unavailable',
            'music.upload' => 'Upload music',
            'music.upload_help' => 'Choose a supported audio file.',
            'music.default' => 'Play by default',
            'music.save_default' => 'Save default playback',
            'music.allow_all' => 'Allow all downloads',
            'music.lock_all' => 'Disable all downloads',
            'music.allow_download' => 'Allow download',
            'music.lock_download' => 'Disable download',
            'music.up' => 'Move up',
            'music.down' => 'Move down',
            'music.remove' => 'Remove source',
            'lightbox.close' => 'Close',
            'presentation.visible' => 'Show presentation',
            'presentation.autoplay' => 'Start automatically',
            'presentation.music' => 'Play music',
            'presentation.link_help' => 'Open a prepared presentation preview.',
            'presentation.save' => 'Save presentation settings',
            'presentation.preview' => 'Preview presentation',
            'lightbox.slideshow_photo_speed' => 'Photo interval',
            'lightbox.slideshow_transition_speed' => 'Transition',
            'lightbox.slideshow_speed_slow' => 'Slow',
            'lightbox.slideshow_speed_medium' => 'Medium',
            'lightbox.slideshow_speed_fast' => 'Fast',
            'pdf.documents' => 'Documents / PDFs',
            'pdf.title' => 'Document title',
            'pdf.size' => 'Size',
            'pdf.pages' => 'Pages',
            'pdf.view' => 'View PDF',
            'pdf.pages_unavailable' => 'Page count unavailable',
            'common.actions' => 'Actions',
            'admin.gallery_editor.media_sections' => 'Gallery media sections',
        ];
        $text = $labels[$key] ?? ($fallback !== '' ? $fallback : $key);
        foreach ($parameters as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }
}

namespace {
    require_once dirname(__DIR__, 2) . '/app/views/admin_chrome.php';
    require_once dirname(__DIR__, 2) . '/app/views/public_gallery_lightbox.php';
    require_once dirname(__DIR__, 2) . '/app/views/admin_gallery_edit_components.php';
    require_once dirname(__DIR__, 2) . '/app/views/admin_gallery_edit_tabs.php';
    require_once dirname(__DIR__, 2) . '/app/views/gallery_music.php';
    require_once dirname(__DIR__, 2) . '/app/views/gallery_pdf_documents.php';

    /** Match the server-prepared bound used by the catalog query control.
     * Type: integer. Units: UTF-8 query bytes. Scope: this prepared browser model.
     * Consumers: the production Music view maxlength and browser bound assertion.
     * Rationale: preserve the bounded chooser contract without inventing a wider fixture allowance.
     * Value: 120 bytes.
     */
    const MUSIC_FIXTURE_QUERY_LIMIT = 120;

    /** Build stable editor and chooser rows for one bounded fixture query.
     * @param string $query Search text supplied by the mocked catalog endpoint.
     * @return array{
     *     gallery_id:int,editor:bool,action_url:string,csrf_html:string,
     *     revision:string,write_revision:string,enabled:bool,error:string,
     *     tracks:list<array{id:string,title:string,filename:string,
     *         mime:string,size:int,sha256:string,hidden:bool,downloadable:bool,
     *         url:string,download_url:string,source_id:string,source_type:string,
     *         source_title:string,source_track_id:string}>,
     *     sources:list<array{id:string,type:string,song_id:int|null,
     *         album_id:int|null,title:string,downloadable:bool,hidden:bool,
     *         track_count:int,position:int,path?:string}>,
     *     choices:array{revision:string,
     *         songs:list<array{id:int,title:string,track_count:int}>,
     *         albums:list<array{id:int,title:string,song_count:int,path:string}>},
     *     query:string,query_limit:int,choices_error:string,
     *     unavailable:int,omitted:int,partial:bool,over_limit:bool
     * } Prepared presentation-only Music view model.
     */
    function fixtureMusicModel(string $query = ''): array
    {
        $query = trim($query);
        $songs = [
            ['id' => 901, 'title' => 'Quiet Morning', 'track_count' => 1],
            ['id' => 902, 'title' => 'Night Drive Theme', 'track_count' => 2],
        ];
        $albums = [[
            'id' => 801,
            'title' => 'Road Trip Live Set',
            'song_count' => 2,
            'path' => 'Live albums / Road Trip Live Set',
        ], [
            'id' => 802,
            'title' => 'Empty Weekend Set',
            'song_count' => 0,
            'path' => 'Live albums / Empty Weekend Set',
        ]];
        if ($query !== '') {
            $songs = array_values(array_filter(
                $songs,
                static fn(array $song): bool => stripos(
                    $song['title'],
                    $query,
                ) !== false,
            ));
            $albums = array_values(array_filter(
                $albums,
                static fn(array $album): bool => stripos(
                    $album['title'] . ' ' . $album['path'],
                    $query,
                ) !== false,
            ));
        }
        $actionUrl = '/index.php?page=admin_edit_gallery&id=42';
        $csrf = '<input type="hidden" name="csrf_token" value="fixture-csrf">';
        return [
            'gallery_id' => 42,
            'editor' => true,
            'action_url' => $actionUrl,
            'csrf_html' => $csrf,
            'revision' => 'write:0007',
            'write_revision' => 'write:0007',
            'enabled' => true,
            'error' => '',
            'tracks' => [
                [
                    'id' => '42:album-track-1', 'title' => 'Road Trip Live Set',
                    'filename' => 'live-set-one.mp3', 'mime' => 'audio/mpeg',
                    'size' => 3000,
                    'sha256' => hash('sha256', 'fixture-album-track-one'),
                    'hidden' => false, 'downloadable' => true,
                    'url' => '/index.php?page=gallery_music&id=42&track=101',
                    'download_url' => '/index.php?page=gallery_music&id=42&track=101&download=1',
                    'source_id' => 'album:801', 'source_type' => 'album',
                    'source_title' => 'Road Trip Live Set',
                    'source_track_id' => 'album-track-1',
                ],
                [
                    'id' => '42:album-track-2', 'title' => 'Road Trip Live Set',
                    'filename' => 'live-set-two.mp3', 'mime' => 'audio/mpeg',
                    'size' => 3100,
                    'sha256' => hash('sha256', 'fixture-album-track-two'),
                    'hidden' => false, 'downloadable' => true,
                    'url' => '/index.php?page=gallery_music&id=42&track=102',
                    'download_url' => '/index.php?page=gallery_music&id=42&track=102&download=1',
                    'source_id' => 'album:801', 'source_type' => 'album',
                    'source_title' => 'Road Trip Live Set',
                    'source_track_id' => 'album-track-2',
                ],
                [
                    'id' => '42:song-901', 'title' => 'Quiet Morning',
                    'filename' => 'quiet-morning.mp3', 'mime' => 'audio/mpeg',
                    'size' => 1200,
                    'sha256' => hash('sha256', 'fixture-individual-song'),
                    'hidden' => false, 'downloadable' => true,
                    'url' => '/index.php?page=gallery_music&id=42&track=901',
                    'download_url' => '/index.php?page=gallery_music&id=42&track=901&download=1',
                    'source_id' => 'song:901', 'source_type' => 'direct',
                    'source_title' => 'Quiet Morning',
                    'source_track_id' => 'song-901',
                ],
            ],
            'sources' => [
                [
                    'id' => 'album:801', 'type' => 'album', 'song_id' => null,
                    'album_id' => 801, 'title' => 'Road Trip Live Set',
                    'downloadable' => true, 'hidden' => false,
                    'track_count' => 2, 'position' => 0,
                    'path' => 'Live albums / Road Trip Live Set',
                ],
                [
                    'id' => 'song:901', 'type' => 'direct', 'song_id' => 901,
                    'album_id' => null, 'title' => 'Quiet Morning',
                    'downloadable' => true, 'hidden' => false,
                    'track_count' => 1, 'position' => 1,
                ],
                [
                    'id' => 'album:802', 'type' => 'album', 'song_id' => null,
                    'album_id' => 802, 'title' => 'Empty Weekend Set',
                    'downloadable' => true, 'hidden' => false,
                    'track_count' => 0, 'position' => 2,
                    'path' => 'Live albums / Empty Weekend Set',
                ],
            ],
            'choices' => [
                'revision' => 'catalog:raw/0009',
                'songs' => $songs,
                'albums' => $albums,
            ],
            'query' => $query,
            'query_limit' => MUSIC_FIXTURE_QUERY_LIMIT,
            'choices_error' => '',
            'unavailable' => 0,
            'omitted' => 0,
            'partial' => false,
            'over_limit' => false,
        ];
    }

    $mode = (string) ($argv[1] ?? 'page');
    $query = (string) ($argv[2] ?? '');
    $model = fixtureMusicModel($query);
    if ($mode === 'choices') {
        \Gallery\Views\view_render_gallery_music_choice_rows(
            $model,
            $model['choices'],
        );
        return;
    }
    if ($mode === 'music-card') {
        \Gallery\Views\view_render_gallery_music($model);
        return;
    }
    $musicHtml = '';
    ob_start();
    \Gallery\Views\view_render_gallery_music($model);
    $musicHtml = (string) ob_get_clean();
    $pdfHtml = '';
    ob_start();
    \Gallery\Views\view_render_gallery_pdf_documents([
        'gallery_id' => 42,
        'admin' => true,
        'editor' => true,
        'action_url' => '/index.php?page=admin_edit_gallery&id=42',
        'csrf_html' => '<input type="hidden" name="csrf_token" value="fixture-csrf">',
        'operation_key' => 'fixture-operation',
        'dpi' => 150,
        'browser_config' => '{}',
        'language' => 'en',
        'documents' => [[
            'id' => 'fixture-admin-pdf',
            'title' => 'Admin fixture PDF',
            'filename' => 'admin-fixture.pdf',
            'size' => 8192,
            'hidden' => false,
            'version' => 'admin-fixture-v1',
            'view_url' => '/gallery/admin-fixture.pdf',
            'download_url' => '/gallery/admin-fixture.pdf?download=1',
        ]],
    ]);
    $pdfHtml = (string) ob_get_clean();
    ob_start();
    \Gallery\Views\view_render_admin_simbrief_legacy_panel([
        'gallery' => ['id' => 42, 'title' => 'Fixture gallery'],
        'csrf_html' => '<input type="hidden" name="csrf_token" value="fixture-csrf">',
        'action_url' => '/index.php?page=admin_edit_gallery&id=42&tab=admin-edit-media&media_section=pdf',
        'pdf_view_url' => '/index.php?page=gallery_ofp_pdf&id=42',
        'pdf_download_url' => '/index.php?page=gallery_ofp_pdf&id=42&download=1',
        'has_pdf' => false,
        'occupied' => false,
        'provenance' => '',
        'prefill' => ['fields' => []],
    ]);
    $pdfHtml .= (string) ob_get_clean();
    $presentationHtml = '';
    ob_start();
    \Gallery\Views\view_render_admin_gallery_presentation([
        'gallery_id' => 42,
        'ready' => true,
        'settings' => [
            'version' => 1, 'enabled' => true, 'autoplay' => false,
            'music' => false, 'interval' => 'medium',
            'transition' => 'medium',
        ],
        'url' => '/gallery/trips?lang=en',
    ]);
    $presentationHtml = (string) ob_get_clean();
    if ($mode === 'without-presentation') {
        $presentationHtml = '';
    }
    ob_start();
    \Gallery\Views\view_render_admin_gallery_branding_fields([
        'schema_ready' => true,
        'form_id' => 'admin-gallery-settings-42',
        'assets' => [[
            'kind' => 'logo',
            'label' => 'Gallery logo',
            'description' => 'Optional gallery logo.',
            'url' => '/gallery-assets/fixture-logo.png',
        ]],
    ]);
    $brandingFieldsHtml = (string) ob_get_clean();
    ob_start();
    \Gallery\Views\view_render_admin_gallery_media_fields([
        'labels' => [
            'title_picture' => 'Title picture',
            'cover_legacy' => 'Default title picture',
        ],
        'cover_options_html' => '<option value="17">Fixture image</option>',
        'cover_multi_count' => 0,
        'cover_automatic_selected' => false,
        'cover_legacy_selected' => true,
        'cover_automatic_available' => true,
        'branding_fields_html' => $brandingFieldsHtml,
        'cover_asset_schema_ready' => false,
        'background_source_schema_ready' => true,
        'background_source' => null,
        'settings_form_id' => 'admin-gallery-settings-42',
    ]);
    $artworkHtml = (string) ob_get_clean();
    echo '<form id="admin-gallery-settings-42" method="post"></form>';
    echo '<div data-admin-side-panel data-open="1">'
        . '<main data-admin-side-panel-body>';
    \Gallery\Views\view_render_admin_gallery_media_tab([
        'active' => true,
        'active_section' => 'music',
        'presentation_html' => $presentationHtml,
        'documents_html' => $pdfHtml,
        'music_html' => $musicHtml,
        'artwork_html' => $artworkHtml,
        'settings_form_id' => 'admin-gallery-settings-42',
    ]);
    echo '</main></div>';

    // Public empty playlist behavior remains a warning surface without controls.
    \Gallery\Views\view_render_gallery_music([
        'gallery_id' => 42,
        'editor' => false,
        'action_url' => '',
        'csrf_html' => '',
        'revision' => 'public-read',
        'enabled' => false,
        'error' => '',
        'tracks' => [],
        'sources' => [],
        'unavailable' => 1,
        'omitted' => 0,
        'partial' => true,
        'over_limit' => false,
    ]);
    \Gallery\Views\view_render_gallery_pdf_documents([
        'gallery_id' => 42,
        'admin' => false,
        'editor' => false,
        'action_url' => '',
        'csrf_html' => '',
        'operation_key' => '',
        'dpi' => 150,
        'browser_config' => '{}',
        'language' => 'en',
        'documents' => [[
            'id' => 'public-fixture-pdf',
            'title' => 'Public travel notes',
            'filename' => 'travel-notes.pdf',
            'size' => 4096,
            'hidden' => false,
            'version' => 'public-v1',
            'view_url' => '/gallery/travel-notes.pdf',
            'download_url' => '/gallery/travel-notes.pdf?download=1',
        ]],
    ]);
}
