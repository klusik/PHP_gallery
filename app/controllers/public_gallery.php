<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/public_gallery.php
 * Module Type: Controller
 *
 * Purpose:
 *   Loads focused public gallery controller modules while preserving the legacy include contract.
 *
 * Responsibilities:
 *   - Validate and route incoming request data
 *   - Call service-layer functions where possible
 *   - Return redirects, rendered views, or HTTP responses
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

namespace Gallery\Controllers;


require_once __DIR__ . '/public_gallery_home.php';
require_once __DIR__ . '/public_gallery_page.php';
require_once __DIR__ . '/public_gallery_controls.php';
require_once __DIR__ . '/public_gallery_cards.php';
require_once __DIR__ . '/public_gallery_lightbox.php';