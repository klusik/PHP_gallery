<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/fixtures/early_runtime_probe.php
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

$projectRoot = (string) getenv('PHP_GALLERY_TEST_PROJECT_ROOT');
if ($projectRoot === '') {
    http_response_code(500);
    exit('Missing test project root.');
}

require dirname(__DIR__, 2) . '/app/early_runtime.php';

\Gallery\EarlyRuntime\register_emergency_handler();
\Gallery\EarlyRuntime\enforce_activation_gate($projectRoot);
header('Content-Type: text/plain; charset=utf-8');
echo "OK\n";
