<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/helpers.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Loads focused core helper modules while preserving the legacy app/helpers.php include contract.
 *
 * Responsibilities:
 *   - Support shared project infrastructure
 *   - Keep behavior compatible with existing controllers and services
 *   - Avoid unnecessary coupling to presentation code
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
 *   2026-08-11
 */

declare(strict_types=1);

namespace Gallery\Core;


require_once __DIR__ . '/helpers_request.php';
require_once __DIR__ . '/helpers_public_urls.php';
require_once __DIR__ . '/helpers_runtime.php';
require_once __DIR__ . '/helpers_admin_rendering.php';
require_once __DIR__ . '/helpers_page_rendering.php';
require_once __DIR__ . '/helpers_files.php';