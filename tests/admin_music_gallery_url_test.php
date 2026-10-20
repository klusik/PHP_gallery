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
}
