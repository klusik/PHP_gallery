<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/admin_music_render_fixture.php
 * Module Type: Test Fixture
 * Purpose: Render the production global Music Manager with disposable prepared data.
 * Responsibilities: Supply view-only helpers and a deterministic localized model.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Escape a presentation value.
     * @param string $value Untrusted fixture text.
     * @return string Safe HTML text.
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Build deterministic local route URLs for the rendered form.
     * @param string $route Registered route identifier.
     * @param array<string,int|string> $params Route parameter values.
     * @return string Same-origin fixture URL.
     */
    function url_for(string $route, array $params = []): string
    {
        return '/index.php?' . http_build_query(['page' => $route] + $params);
    }

    /** Supply inert fixture page chrome.
     * @param string $title Page title.
     * @return void No output; the browser fixture owns the document shell.
     */
    function render_header(string $title): void
    {
    }

    /** Supply inert fixture page chrome.
     * @return void No output; the browser fixture owns the document shell.
     */
    function render_footer(): void
    {
    }
}

namespace Gallery\Services {
    /** Return the view's English fallback label without application services.
     * @param string $key Translation key.
     * @param string $fallback English fixture label.
     * @param array<string,string|int> $parameters Optional interpolation values.
     * @return string Deterministic fallback label.
     */
    function t(string $key, string $fallback = '', array $parameters = []): string
    {
        return $fallback;
    }
}

namespace {
    require_once dirname(__DIR__, 2) . '/app/views/admin_chrome.php';
    require_once dirname(__DIR__, 2) . '/app/views/admin_ui.php';
    require_once dirname(__DIR__, 2) . '/app/views/admin_music.php';

    $searchParameters = [];
    parse_str((string) ($argv[1] ?? ''), $searchParameters);
    $searchQuery = trim((string) ($searchParameters['q'] ?? ''));
    $albumId = (int) ($searchParameters['album_id'] ?? 8);
    $sort = (string) ($searchParameters['sort'] ?? 'files');
    $direction = (string) ($searchParameters['direction'] ?? 'desc');
    $tab = ($searchParameters['tab'] ?? 'songs') === 'albums'
        ? 'albums' : 'songs';

    // Pair 7/8 is the selected ancestry; pair 9/10 is an unrelated branch
    // that must remain natively collapsed until the browser activates it.
    $albums = [
        ['id' => 7, 'parent_id' => null, 'title' => 'Road Trips', 'description' => '', 'sort_order' => 0, 'revision' => 1, 'song_count' => 1],
        ['id' => 8, 'parent_id' => 7, 'title' => 'Night Drives', 'description' => '', 'sort_order' => 0, 'revision' => 1, 'song_count' => 2],
        ['id' => 9, 'parent_id' => null, 'title' => 'Bonus Sessions', 'description' => '', 'sort_order' => 1, 'revision' => 1, 'song_count' => 0],
        ['id' => 10, 'parent_id' => 9, 'title' => 'Studio Notes', 'description' => '', 'sort_order' => 0, 'revision' => 1, 'song_count' => 0],
    ];
    $songs = [
        ['id' => 101, 'title' => 'Midnight Drive', 'track_count' => 2, 'album_count' => 1, 'direct_gallery_count' => 1],
        ['id' => 102, 'title' => 'Quiet Morning', 'track_count' => 1, 'album_count' => 1, 'direct_gallery_count' => 0],
    ];
    $songs = array_values(array_filter(
        $songs,
        static fn(array $song): bool => $searchQuery === ''
            || stripos($song['title'], $searchQuery) !== false,
    ));
    $model = [
        'query' => $searchQuery,
        'tab' => $tab,
        'return_tab' => 'admin-music-tab-' . $tab,
        'tab_urls' => [
            'songs' => \Gallery\Core\url_for('admin_music', [
                'q' => $searchQuery, 'tab' => 'songs', 'album_id' => $albumId,
                'album_offset' => 0, 'offset' => 0, 'sort' => $sort,
                'direction' => $direction,
            ]) . '#admin-music-tab-songs',
            'albums' => \Gallery\Core\url_for('admin_music', [
                'q' => $searchQuery, 'tab' => 'albums', 'album_id' => $albumId,
                'album_offset' => 0, 'offset' => 0, 'sort' => $sort,
                'direction' => $direction,
            ]) . '#admin-music-tab-albums',
        ],
        'offset' => (int) ($searchParameters['offset'] ?? 0),
        'limit' => 20,
        'sort' => $sort,
        'direction' => $direction,
        'sort_urls' => [
            'title' => \Gallery\Core\url_for('admin_music', [
                'q' => $searchQuery, 'offset' => 0, 'album_id' => $albumId,
                'sort' => 'title', 'direction' => 'asc', 'tab' => $tab,
            ]),
            'files' => \Gallery\Core\url_for('admin_music', [
                'q' => $searchQuery, 'offset' => 0, 'album_id' => $albumId,
                'sort' => 'files', 'direction' => 'asc', 'tab' => $tab,
            ]),
            'locations' => \Gallery\Core\url_for('admin_music', [
                'q' => $searchQuery, 'offset' => 0, 'album_id' => $albumId,
                'sort' => 'locations', 'direction' => 'asc', 'tab' => $tab,
            ]),
            'tags' => \Gallery\Core\url_for('admin_music', [
                'q' => $searchQuery, 'offset' => 0, 'album_id' => $albumId,
                'sort' => 'tags', 'direction' => 'asc', 'tab' => $tab,
            ]),
        ],
        'catalog' => ['revision' => 'fixture-rev', 'songs' => $songs, 'total' => 2],
        'song_details' => [
            101 => [
                'tracks' => [
                    ['gallery_id' => 41, 'track_id' => 'a1', 'filename' => 'midnight-live.mp3'],
                    ['gallery_id' => 42, 'track_id' => 'a2', 'filename' => 'midnight-studio.mp3'],
                ],
                'repository_assets' => [
                    [
                        'id' => 'asset-promoted-a1',
                        'filename' => 'midnight-live.mp3',
                        'byte_size' => 8192,
                        'sha256' => str_repeat('a', 64),
                        'origin_gallery_id' => 41,
                        'origin_track_id' => 'a1',
                    ],
                    [
                        'id' => 'asset-repository-only',
                        'filename' => 'repository-only.wav',
                        'byte_size' => 4096,
                        'sha256' => str_repeat('b', 64),
                        'origin_gallery_id' => null,
                        'origin_track_id' => null,
                    ],
                ],
                'direct_galleries' => [['gallery_id' => 41, 'title' => 'Highway Set', 'track_count' => 1]],
                'albums' => [['album_id' => 8, 'title' => 'Night Drives', 'track_count' => 1]],
                'live_albums' => [[
                    'song_id' => 101,
                    'gallery_id' => 44,
                    'gallery_title' => 'Road Mix Live',
                    'album_id' => 12,
                    'album_title' => 'Festival Set',
                    'track_count' => 1,
                ]],
            ],
            102 => [
                'tracks' => [['gallery_id' => 43, 'track_id' => 'b1', 'filename' => 'quiet-morning.mp3']],
                'direct_galleries' => [],
                'albums' => [['album_id' => 8, 'title' => 'Night Drives', 'track_count' => 1]],
            ],
        ],
        'song_tag_labels' => [101 => ['ambient', 'road']],
        'gallery_urls' => [
            41 => '/gallery/highway-set',
            42 => '/gallery/studio-set',
            43 => '/gallery/quiet-morning',
            44 => '/gallery/road-mix-live',
        ],
        'albums' => $albums, 'album_id' => $albumId, 'album_offset' => 0,
        'album_members' => [
            ['id' => 101, 'title' => 'Midnight Drive', 'custom_title' => '', 'indexed_title' => 'Midnight Drive', 'filename' => 'midnight-live.mp3'],
            ['id' => 102, 'title' => 'Quiet Morning', 'custom_title' => '', 'indexed_title' => 'Quiet Morning', 'filename' => 'quiet-morning.mp3'],
        ],
        'revision' => 'fixture-rev', 'extensions' => false,
        'song_tags' => [101 => 'ambient, road'], 'reconcile_cursor' => 0,
        'dirty_status' => ['state' => 'available', 'count' => 0],
        'csrf_html' => '<input type="hidden" name="csrf_token" value="music-fixture">'
            . '<input type="hidden" name="return_tab" value="'
            . \Gallery\Core\e('admin-music-tab-' . $tab) . '">',
        'action_url' => '/index.php?page=admin_music',
        'notice' => '', 'error' => '', 'fragment' => isset($argv[1]),
    ];
    $model['catalog']['songs'] = $songs;
    $model['catalog']['total'] = count($songs);
    ob_start();
    \Gallery\Views\view_render_admin_music($model);
    echo (string) ob_get_clean();
}
