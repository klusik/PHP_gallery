<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/app_linking_render_fixture.php
 * Module Type: Browser Fixture
 * Purpose: Render the actual linking and device pages for loopback transport tests.
 * Responsibilities: Supply fixed presentation data without touching an installation.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Select the view's English fallback without opening a translation catalogue.
     *
     * @param string $key Requested translation key.
     * @param string $fallback View-owned English text.
     * @return string Fixed fixture locale text.
     */
    function t(string $key, string $fallback): string
    {
        return $fallback;
    }
}

namespace {
    use function Gallery\Views\view_render_app_linking_devices;
    use function Gallery\Views\view_render_app_linking_start;

    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        return;
    }
    require_once __DIR__ . '/../../app/helpers_request.php';
    require_once __DIR__ . '/../../app/views/app_linking.php';

    $mount = '/Galerie/index.php?page=';
    $theme = [
        'ink' => '#f8fafc',
        'paper' => '#14232b',
        'panel' => '#263744',
        'accent' => '#80d7eb',
    ];
    $issuer = 'https://gallery.example.test/Galerie';
    if (($argv[1] ?? '') === 'start') {
        view_render_app_linking_start(
            $mount . 'app_linking_start', $mount . 'app_linking_consent',
            'fixture-csrf', 'linking-fixture', 'en', $theme, $issuer, 'Fixture admin',
            ['request_handle' => 'fixture-handle'],
        );
    } elseif (($argv[1] ?? '') === 'devices') {
        view_render_app_linking_devices(
            $mount . 'app_linking_devices', 'fixture-csrf', 'linking-fixture',
            'en', $theme, [[
                'id' => 1,
                'issuer' => $issuer,
                'client' => 'Fixture WinApp',
                'scope' => 'link:identity galleries:list',
                'created_at' => '2026-01-01 12:00:00',
                'expires_at' => null,
                'status' => 'Active',
                'revocable' => true,
            ]], false,
        );
    } else {
        throw new \InvalidArgumentException('Unknown linking fixture view.');
    }
}
