<?php

/**
 * Project: PHP Gallery
 * Module Type: Regression Test
 * Purpose: Protect SEO lightbox target resolution.
 * Responsibilities:
 *   - Reject invalid target navigation without bypassing gallery access checks.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/seo_lightbox_target_guard_test.php
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */
declare(strict_types=1);

/** Execute the real request guard in isolated public/Admin requests, without a database. */
namespace Gallery\Core {
    /** Return the fixture's authentication principal. */
    function current_user(): ?array
    {
        return ($GLOBALS['guardAdmin'] ?? false) ? ['id' => 1] : null;
    }

    /** Keep the request at the public GET enforcement boundary. */
    function request_method(): string
    {
        return 'GET';
    }
}

namespace Gallery\Services {
    /** Enable the production guard without persistent logging in this fixture. */
    function app_setting(string $key, string $default = ''): string
    {
        return $key === 'seo_request_guard_logging_enabled' ? '0' : $default;
    }
}

namespace {
    if (($argv[1] ?? '') === '--probe') {
        require dirname(__DIR__) . '/app/services/seo_request_guard.php';
        $GLOBALS['guardAdmin'] = ($argv[2] ?? '') === 'admin';
        $route = $argv[3];
        $_GET = ['page' => $route, 'id' => '1', 'limit' => '60', 'offset' => '0'];
        if ($route === 'gallery') {
            $_GET = ['page' => $route, 'public_path' => 'album', 'lang' => 'en'];
        } elseif ($route === 'smart_gallery') {
            $_GET = ['page' => $route, 'slug' => 'album', 'lang' => 'en'];
        }
        if (($argv[4] ?? '') !== 'none') {
            $_GET[$argv[4]] = $argv[4] === 'presentation' ? '1' : '123';
        }
        $reached = false;
        http_response_code(200);
        ob_start();
        register_shutdown_function(static function () use (&$reached): void {
            $body = ob_get_clean();
            echo json_encode(['status' => http_response_code(), 'reached' => $reached, 'body' => $body], JSON_THROW_ON_ERROR);
        });
        $decision = \Gallery\Services\seo_request_guard_enforcement_decision(
            $route,
            'GET',
            $_GET,
            $GLOBALS['guardAdmin'],
            '/index.php?page=' . rawurlencode($route),
            '127.0.0.1',
            'fixture'
        );
        if (($decision['action'] ?? 'allow') === 'reject') {
            http_response_code((int) ($decision['status'] ?? 404));
            echo (string) ($decision['body'] ?? '');
            exit;
        }
        $reached = true;
        exit;
    }

    $cases = [
        ['public', 'gallery_lightbox_data', 'target_image_id', 200],
        ['public', 'gallery_lightbox_data', 'none', 200],
        ['public', 'gallery_lightbox_data', 'unexpected', 404],
        ['public', 'smart_gallery_lightbox_data', 'target_image_id', 200],
        ['public', 'smart_gallery_lightbox_data', 'source_gallery_id', 200],
        ['admin', 'gallery_lightbox_data', 'target_image_id', 200],
        ['admin', 'gallery_lightbox_data', 'unexpected', 200],
        ['public', 'gallery', 'presentation', 200],
        ['admin', 'gallery', 'presentation', 200],
        ['public', 'gallery', 'unexpected', 404],
        ['public', 'gallery_lightbox_data', 'presentation', 404],
        ['public', 'gallery', 'autoplay', 200],
        ['public', 'gallery', 'presentation_music', 200],
        ['public', 'gallery', 'presentation_interval', 200],
        ['public', 'gallery', 'presentation_transition', 200],
        ['public', 'gallery', 'presentation_fullscreen', 200],
        ['public', 'gallery', 'presentation_exclusive', 200],
        ['public', 'gallery_lightbox_data', 'presentation_music', 404],
        ['public', 'smart_gallery', 'presentation', 200],
        ['admin', 'smart_gallery', 'presentation', 200],
        ['public', 'smart_gallery', 'autoplay', 200],
        ['public', 'smart_gallery', 'presentation_music', 200],
        ['public', 'smart_gallery', 'presentation_interval', 200],
        ['public', 'smart_gallery', 'presentation_transition', 200],
        ['public', 'smart_gallery', 'presentation_fullscreen', 200],
        ['public', 'smart_gallery', 'presentation_exclusive', 200],
        ['public', 'smart_gallery', 'unexpected', 404],
        ['public', 'smart_gallery_lightbox_data', 'presentation', 404],
    ];
    foreach ($cases as [$principal, $route, $parameter, $expectedStatus]) {
        $process = proc_open([PHP_BINARY, __FILE__, '--probe', $principal, $route, $parameter],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not launch request-guard fixture.');
        }
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new RuntimeException('Request-guard fixture failed: ' . $error);
        }
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        if ($result['status'] !== $expectedStatus || $result['reached'] !== ($expectedStatus === 200)
            || ($expectedStatus === 404 && $result['body'] !== "Not found.\n")) {
            throw new RuntimeException("Unexpected guard result for $principal/$route/$parameter: $output");
        }
    }
    echo 'Lightbox target request guard: ' . count($cases)
        . " public/Admin scenarios passed.\n";
}
