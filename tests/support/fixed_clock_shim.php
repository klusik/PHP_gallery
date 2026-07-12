<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/support/fixed_clock_shim.php
 * Module Type: Test Support
 *
 * Purpose:
 *   Provides a deterministic namespaced clock for metadata extraction tests.
 *
 * Responsibilities:
 *   - Match the current Gallery Core clock namespace
 *   - Keep timestamp assertions stable across test runs
 *   - Avoid loading the full application bootstrap
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

namespace Gallery\Core;

/**
 * Return the fixed SQL timestamp used by isolated metadata tests.
 *
 * @return string Text result for the caller.
 */
function now_sql(): string
{
    return '2026-06-04 12:00:00';
}
