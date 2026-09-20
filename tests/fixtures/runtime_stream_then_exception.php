<?php

/**
 * Project: PHP Gallery
 * Module Type: Test Fixture
 * Purpose: Raise an exception after a fixture response has begun streaming.
 * Responsibilities:
 *   - Check that runtime failure handling does not append an HTML error body.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/fixtures/runtime_stream_then_exception.php
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

require dirname(__DIR__, 2) . '/app/early_runtime.php';

\Gallery\EarlyRuntime\register_emergency_handler();
header('Content-Type: application/octet-stream');
header('Content-Length: 4');
echo 'DATA';
flush();
throw new RuntimeException('stream fixture failure');
