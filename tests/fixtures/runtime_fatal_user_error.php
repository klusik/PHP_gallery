<?php

/**
 * Project: PHP Gallery
 * Module Type: Test Fixture
 * Purpose: Trigger a controlled fatal user error for runtime response checks.
 * Responsibilities:
 *   - Exercise shutdown error handling inside the disposable HTTP fixture.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/fixtures/runtime_fatal_user_error.php
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
trigger_error('fatal fixture secret /srv/private/config.php', E_USER_ERROR);
