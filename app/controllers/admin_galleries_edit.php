<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_edit.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Loads the focused gallery editor controller modules while preserving the legacy include contract.
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

namespace Gallery\Controllers;


require_once __DIR__ . '/admin_galleries_edit_actions.php';
require_once __DIR__ . '/admin_galleries_edit_metadata.php';
require_once __DIR__ . '/admin_galleries_edit_page.php';
require_once __DIR__ . '/admin_galleries_edit_views.php';