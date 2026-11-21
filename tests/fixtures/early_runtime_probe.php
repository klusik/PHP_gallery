<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/fixtures/early_runtime_probe.php
 * Module Type: Test Fixture
 * Purpose: Provide a minimal public route behind the real front controller.
 * Responsibilities: Read installed PHP and media bytes from an isolated site.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core;

// This fixture represents the new version already unpacked over an older job.
const CMS_VERSION = '1.5.0';

/**
 * Serve the disposable gallery, JSON and media used by the online update test.
 *
 * @return void Sends the response for the fixture route.
 */
function cms_run(): void
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if ($path === '/photo') {
        header('Content-Type: image/gif');
        readfile(dirname(__DIR__) . '/galleries/photo.gif');
        return;
    }
    $title = require __DIR__ . '/gallery-label.php';
    $description = require __DIR__ . '/gallery-summary.php';
    if ($path === '/api') {
        header('Content-Type: application/json');
        echo json_encode(['title' => $title, 'description' => $description], JSON_THROW_ON_ERROR);
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<main><h1>' . $title . '</h1><p>' . $description . '</p></main>';
}
