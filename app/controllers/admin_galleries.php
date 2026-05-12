<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Loads the focused admin gallery controller modules while preserving the legacy include contract.
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

require_once __DIR__ . '/admin_gallery_renderers.php';
require_once __DIR__ . '/admin_galleries_discovery.php';
require_once __DIR__ . '/admin_galleries_bulk.php';
require_once __DIR__ . '/admin_galleries_reorder.php';
require_once __DIR__ . '/admin_galleries_edit.php';
require_once __DIR__ . '/admin_images_reorder.php';
require_once __DIR__ . '/admin_images_bulk.php';
require_once __DIR__ . '/admin_public_inline.php';
