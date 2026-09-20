<?php

/**
 * Project: PHP Gallery
 * Module Type: Test Fixture
 * Purpose: Exercise conditional file responses without a database.
 * Responsibilities:
 *   - Expose deterministic media bytes and validators for HTTP fixture requests.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/fixtures/runtime_conditional_file.php
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
/** Exercise the existing conditional file response helper without a database. */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/early_runtime.php';
\Gallery\EarlyRuntime\register_emergency_handler();
require dirname(__DIR__, 2) . '/app/controllers/http_helpers.php';

$path = sys_get_temp_dir() . '/php-gallery-conditional-' . sha1(__FILE__) . '.bin';
file_put_contents($path, 'immutable-media-payload');
register_shutdown_function(static function () use ($path): void {
    @unlink($path);
});

\Gallery\Controllers\send_conditional_file_headers($path, 'public, max-age=31536000, immutable');
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($path));
readfile($path);
