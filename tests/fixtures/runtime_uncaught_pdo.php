<?php

/**
 * Project: PHP Gallery
 * Module Type: Test Fixture
 * Purpose: Expose a synthetic database exception to runtime error handling.
 * Responsibilities:
 *   - Check that database failure details do not leak through the public response.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/fixtures/runtime_uncaught_pdo.php
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
throw new PDOException('SELECT secret FROM private_table');
