<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/thumbnails.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Loads focused thumbnail service modules while preserving the legacy include contract.
 *
 * Responsibilities:
 *   - Keep behavior compatible with the previous combined implementation
 *   - Expose focused functions for one admin or thumbnail responsibility
 *   - Avoid coupling unrelated workflows into one large source file
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
 *   2026-05-12
 */

declare(strict_types=1);

namespace Gallery\Services;

require_once __DIR__ . '/thumbnail_sources.php';
require_once __DIR__ . '/thumbnail_metadata.php';
require_once __DIR__ . '/thumbnail_bundles.php';
require_once __DIR__ . '/dng_derivatives.php';
require_once __DIR__ . '/thumbnail_compatibility.php';
require_once __DIR__ . '/thumbnail_html.php';
require_once __DIR__ . '/thumbnail_formats.php';
require_once __DIR__ . '/thumbnail_maintenance.php';
require_once __DIR__ . '/thumbnail_generation.php';
require_once __DIR__ . '/thumbnail_warmup.php';
