<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/support/dng_policy_shims.php
 * Module Type: Test Support
 *
 * Purpose:
 *   Provides the namespaced app setting dependency used by DNG policy tests.
 *
 * Responsibilities:
 *   - Read deterministic DNG settings from the test process
 *   - Keep the DNG policy test independent from a database
 *   - Match the namespace used by the current production service
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-07-12
 */

declare(strict_types=1);

namespace Gallery\Services;

/**
 * Return one deterministic app setting for the isolated DNG policy test.
 *
 * @param string $key Lookup key.
 * @param ?string $default Default value when no explicit value is available.
 * @return ?string Text result for the caller.
 */
function app_setting(string $key, ?string $default = null): ?string
{
    $settings = $GLOBALS['dng_policy_test_settings'] ?? [];
    return array_key_exists($key, $settings) ? (string) $settings[$key] : $default;
}
