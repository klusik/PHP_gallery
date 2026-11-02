<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_music_gallery_url_test.php
 * Module Type: Test Script
 * Purpose: Cover Music Manager canonical gallery URL language preservation.
 * Responsibilities: Exercise clean and query-style URL decoration independently.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core {
    /** Refuse controller dispatch when this isolated request is not an Admin.
     * @return void Allows only the fixture-owned administrator state.
     * @throws \RuntimeException When a caller attempts anonymous dispatch.
     */
    function require_admin(): void
    {
        if (empty($GLOBALS['music_preview_fixture_admin'])) {
            throw new \RuntimeException('Fixture Admin required.');
        }
    }

    /** Read the fixture's explicit HTTP verb without a real web request.
     * @return string Uppercase method selected by the transport boundary test.
     */
    function request_method(): string
    {
        return (string) ($GLOBALS['music_preview_fixture_method'] ?? 'GET');
    }

    /** Resolve anonymous preview only from test-owned request state.
     * @return bool Whether fixture playback must ignore Admin authority.
     */
    function admin_anonymous_preview_active(): bool
    {
        return (bool) ($GLOBALS['music_preview_fixture_anonymous'] ?? false);
    }

    /**
     * Build the isolated fallback route URL for the shared public URL helper.
     *
     * @param string $route Registered route identifier.
     * @param array<string,string> $parameters Route parameters.
     * @return string Query-style fixture route.
     */
    function url_for(string $route, array $parameters = []): string
    {
        return 'https://gallery.example/index.php?'
            . http_build_query(['page' => $route] + $parameters);
    }

    /**
     * Return a safe path segment while preserving gallery hierarchy slashes.
     *
     * @param string $path Gallery path value.
     * @return string Encoded hierarchical path.
     */
    function public_path_segment(string $path): string
    {
        return str_replace('%2F', '/', rawurlencode($path));
    }

    /**
     * Return the configured public base for the URL helper fixture.
     *
     * @return string Fixture public base URL.
     */
    function public_base_url(): string
    {
        return 'https://gallery.example';
    }
}

namespace Gallery\Services {
    /** Resolve the music capability from this isolated boundary's state.
     * @param string $key Capability requested by the production controller.
     * @return bool True only for enabled fixture music.
     */
    function feature_capability_effective_enabled(string $key): bool
    {
        return $key === 'gallery_music'
            && ($GLOBALS['music_preview_fixture_enabled'] ?? false);
    }

    /** Keep the production age boundary observable in the request fixture.
     * @return bool Whether the isolated account is explicitly under eighteen.
     */
    function current_user_is_known_under_18(): bool
    {
        return (bool) ($GLOBALS['music_preview_fixture_minor'] ?? false);
    }

    /** Capture authorized original lookup without reading installed media.
     * @param int $songId Logical identity passed by the production controller.
     * @return null Deliberately absent original for opaque transport tests.
     */
    function music_repository_preview_song(int $songId): ?array
    {
        $GLOBALS['music_preview_fixture_reads'][] = $songId;
        return null;
    }

    /**
     * Return the fixture URL rewrite setting.
     *
     * @return bool Whether clean routes are active.
     */
    function url_rewrite_should_emit_clean_urls(): bool
    {
        return (bool) ($GLOBALS['admin_music_gallery_url_clean_routes'] ?? false);
    }

    /**
     * Return the fixture visitor language.
     *
     * @return string The selected language code.
     */
    function translation_active_language(): string
    {
        return 'cs';
    }
}

namespace {
    require_once __DIR__ . '/../app/helpers_public_urls.php';
    require_once __DIR__ . '/../app/controllers/admin_music.php';
    require_once __DIR__ . '/../app/controllers/http_helpers.php';

    /**
     * Assert one exact URL value.
     *
     * @param string $expected Expected complete URL.
     * @param string $actual Produced complete URL.
     * @return void Throws when the values differ.
     */
    function admin_music_gallery_url_assert_same(
        string $expected,
        string $actual,
    ): void {
        if ($expected !== $actual) {
            throw new RuntimeException(
                'Expected ' . $expected . ', got ' . $actual,
            );
        }
    }

    $gallery = [
        'id' => 41,
        'url_path' => 'highway-set',
        'slug' => 'highway-set',
        'folder_path' => 'Highway Set',
        'title' => 'Highway Set',
    ];
    $GLOBALS['admin_music_gallery_url_clean_routes'] = true;
    admin_music_gallery_url_assert_same(
        'https://gallery.example/gallery/highway-set/?lang=cs',
        \Gallery\Controllers\admin_music_gallery_url($gallery),
    );

    $GLOBALS['admin_music_gallery_url_clean_routes'] = false;
    admin_music_gallery_url_assert_same(
        'https://gallery.example/index.php?page=gallery&public_path=highway-set&lang=cs',
        \Gallery\Controllers\admin_music_gallery_url($gallery),
    );

    $GLOBALS['music_preview_fixture_reads'] = [];
    $GLOBALS['music_preview_fixture_enabled'] = true;
    $_GET = ['action' => 'music_preview', 'song_id' => '17'];
    try {
        \Gallery\Controllers\cms_admin_music();
        throw new \RuntimeException('Anonymous music preview was admitted.');
    } catch (\RuntimeException $error) {
        admin_music_gallery_url_assert_same(
            'Fixture Admin required.', $error->getMessage(),
        );
    }
    $GLOBALS['music_preview_fixture_admin'] = true;
    foreach (['anonymous', 'minor', 'disabled', 'method'] as $refusal) {
        $GLOBALS['music_preview_fixture_anonymous'] = $refusal === 'anonymous';
        $GLOBALS['music_preview_fixture_minor'] = $refusal === 'minor';
        $GLOBALS['music_preview_fixture_enabled'] = $refusal !== 'disabled';
        $GLOBALS['music_preview_fixture_method'] = $refusal === 'method' ? 'POST' : 'GET';
        ob_start();
        \Gallery\Controllers\cms_admin_music();
        ob_end_clean();
        admin_music_gallery_url_assert_same(
            $refusal === 'method' ? '405' : '404', (string) http_response_code(),
        );
        admin_music_gallery_url_assert_same('0',
            (string) count($GLOBALS['music_preview_fixture_reads']));
    }
    $GLOBALS['music_preview_fixture_anonymous'] = false;
    $GLOBALS['music_preview_fixture_minor'] = false;
    $GLOBALS['music_preview_fixture_enabled'] = true;
    foreach (['GET', 'HEAD'] as $method) {
        $GLOBALS['music_preview_fixture_method'] = $method;
        ob_start();
        \Gallery\Controllers\cms_admin_music();
        $body = (string) ob_get_clean();
        admin_music_gallery_url_assert_same('404', (string) http_response_code());
        admin_music_gallery_url_assert_same($method === 'GET' ? 'Not found' : '', $body);
    }
    admin_music_gallery_url_assert_same('17,17',
        implode(',', $GLOBALS['music_preview_fixture_reads']));
    http_response_code(200);
}
