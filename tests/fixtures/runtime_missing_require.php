<?php

/**
 * Project: PHP Gallery
 * Module Type: Test Fixture
 * Purpose: Trigger a missing-include failure in the runtime fixture.
 * Responsibilities:
 *   - Exercise fatal bootstrap failure handling without installation data.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/fixtures/runtime_missing_require.php
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
require __DIR__ . '/definitely-missing-runtime-fixture.php';
