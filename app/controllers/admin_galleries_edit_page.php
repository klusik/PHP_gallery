<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_edit_page.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Coordinates and renders the main gallery edit page.
 *
 * Responsibilities:
 *   - Keep behavior compatible with the previous combined implementation
 *   - Expose focused functions for one admin or thumbnail responsibility
 *   - Avoid coupling unrelated workflows into one large source file
 *   - Load the edit-page part files below in dependency order
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
 *   - Implementation lives in app/controllers/admin_galleries_edit_page/; this file is the module entry point.
 *   - The require_once list preserves the historical app/controllers/admin_galleries_edit.php include contract.
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Controllers;

// This module is split into focused part files under app/controllers/admin_galleries_edit_page/.
// Schema and feature readiness shared by every phase below.
require_once __DIR__ . '/admin_galleries_edit_page/capabilities.php';
// POST action dispatch, media rename, AI reprocess, and the generic gallery save.
require_once __DIR__ . '/admin_galleries_edit_page/post_actions.php';
// One-time notices, gallery hero, summary metrics, and the tab strip.
require_once __DIR__ . '/admin_galleries_edit_page/overview.php';
// Tab panels rendered inside the shared editor form.
require_once __DIR__ . '/admin_galleries_edit_page/tab_identity.php';
require_once __DIR__ . '/admin_galleries_edit_page/tab_access.php';
require_once __DIR__ . '/admin_galleries_edit_page/tab_display.php';
require_once __DIR__ . '/admin_galleries_edit_page/tab_media.php';
// Tab panels that own their own forms and panels outside the editor form.
require_once __DIR__ . '/admin_galleries_edit_page/tab_images.php';
require_once __DIR__ . '/admin_galleries_edit_page/tab_tools.php';
// Request orchestration for the whole edit page.
require_once __DIR__ . '/admin_galleries_edit_page/controller.php';
