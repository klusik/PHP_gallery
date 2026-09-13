<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models.php
 * Module Type: Model Loader
 *
 * Purpose:
 *   Loads database-facing model modules before service-layer orchestration.
 *
 * Responsibilities:
 *   - Keep reusable SQL/data-access code outside controllers and views
 *   - Provide a stable model include point for the plain-PHP MVC structure
 *   - Avoid presentation and HTTP concerns inside model modules
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
 *   - Models may depend on core database helpers but must not render HTML or read request globals.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Core;

require_once __DIR__ . '/models/public_search.php';
// Load optional request-local search query instrumentation before progressive model calls.
require_once __DIR__ . '/models/public_search_diagnostics.php';
require_once __DIR__ . '/models/public_search_progressive.php';
