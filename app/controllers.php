<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Provides core bootstrap, configuration, helper, security, database, or routing functionality.
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
 *   2026-05-04
 */

declare(strict_types=1);


// Load theme admin and theme asset routes after their service dependencies are available.
require_once __DIR__ . '/controllers/admin_theme.php';
require_once __DIR__ . '/controllers/theme_assets.php';
// Load separated controller modules. These require_once calls preserve the legacy app/controllers.php include contract.
require_once __DIR__ . '/controllers/http_helpers.php';
require_once __DIR__ . '/controllers/public_gallery.php';
require_once __DIR__ . '/controllers/gallery_lightbox.php';
require_once __DIR__ . '/controllers/picture_manager.php';
require_once __DIR__ . '/controllers/public_media.php';
require_once __DIR__ . '/controllers/admin_auth.php';
require_once __DIR__ . '/controllers/admin_integrity.php';
require_once __DIR__ . '/controllers/upload_automation.php';
require_once __DIR__ . '/controllers/mobile_webdav.php';
require_once __DIR__ . '/controllers/gallery_migration.php';
require_once __DIR__ . '/controllers/admin_media_renamer.php';
require_once __DIR__ . '/controllers/admin_galleries.php';
require_once __DIR__ . '/controllers/admin_tags.php';
require_once __DIR__ . '/controllers/admin_uploads.php';
require_once __DIR__ . '/controllers/admin_simbrief.php';
require_once __DIR__ . '/controllers/admin_openai_text_assist.php';
require_once __DIR__ . '/controllers/navigation_data.php';
require_once __DIR__ . '/controllers/admin_thumbnails.php';
require_once __DIR__ . '/controllers/admin_dashboard.php';
require_once __DIR__ . '/controllers/admin_features.php';
require_once __DIR__ . '/controllers/setup.php';
require_once __DIR__ . '/controllers/downloads.php';
require_once __DIR__ . '/controllers/admin_logs.php';
require_once __DIR__ . '/controllers/telemetry.php';
require_once __DIR__ . '/controllers/admin_telemetry.php';
require_once __DIR__ . '/controllers/updates.php';
require_once __DIR__ . '/controllers/picture_game.php';
require_once __DIR__ . '/controllers/tags.php';
require_once __DIR__ . '/controllers/exif.php';
