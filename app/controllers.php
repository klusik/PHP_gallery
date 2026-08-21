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

namespace Gallery\Core;


// Load theme admin and theme asset routes after their service dependencies are available.
require_once __DIR__ . '/controllers/admin_theme.php';
require_once __DIR__ . '/controllers/theme_assets.php';
// Load separated controller modules. These require_once calls preserve the legacy app/controllers.php include contract.
require_once __DIR__ . '/controllers/http_helpers.php';
require_once __DIR__ . '/controllers/public_gallery.php';
require_once __DIR__ . '/controllers/gallery_lightbox.php';
require_once __DIR__ . '/controllers/picture_manager.php';
require_once __DIR__ . '/controllers/public_media.php';
require_once __DIR__ . '/controllers/thumbnail_warmup.php';
require_once __DIR__ . '/controllers/site_maintenance.php';
require_once __DIR__ . '/controllers/admin_auth.php';
// Load the minimal invite-only viewer account HTTP boundary after the shared mail transport.
require_once __DIR__ . '/controllers/viewer_accounts.php';
// Load the narrow viewer account lifecycle HTTP boundary after its shared Phase 1.0 helpers.
require_once __DIR__ . '/controllers/viewer_lifecycle.php';
// Load the first viewer-owned content HTTP boundary after viewer authentication helpers.
require_once __DIR__ . '/controllers/viewer_favourites.php';
// Load the private viewer-collection HTTP boundary after shared viewer content controls.
require_once __DIR__ . '/controllers/viewer_collections.php';
// Load Phase 3 unlisted collection-share routes after the owner collection helpers.
require_once __DIR__ . '/controllers/viewer_collection_shares.php';
require_once __DIR__ . '/controllers/admin_integrity.php';
require_once __DIR__ . '/controllers/upload_automation.php';
require_once __DIR__ . '/controllers/mobile_webdav.php';
require_once __DIR__ . '/controllers/gallery_migration.php';
require_once __DIR__ . '/controllers/admin_media_renamer.php';
require_once __DIR__ . '/controllers/admin_galleries.php';
require_once __DIR__ . '/controllers/admin_gallery_dates.php';
require_once __DIR__ . '/controllers/admin_duplicate_photos.php';
require_once __DIR__ . '/controllers/admin_tags.php';
require_once __DIR__ . '/controllers/admin_uploads.php';
require_once __DIR__ . '/controllers/admin_simbrief.php';
require_once __DIR__ . '/controllers/admin_openai_text_assist.php';
require_once __DIR__ . '/controllers/navigation_data.php';
require_once __DIR__ . '/controllers/admin_thumbnails.php';
require_once __DIR__ . '/controllers/admin_dashboard.php';
// Load the centralized Admin Settings hub after dashboard setting endpoints.
require_once __DIR__ . '/controllers/admin_settings.php';
require_once __DIR__ . '/controllers/admin_database_maintenance.php';
require_once __DIR__ . '/controllers/admin_gallery_report.php';
require_once __DIR__ . '/controllers/admin_gallery_benchmark.php';
require_once __DIR__ . '/controllers/admin_test_runs.php';
require_once __DIR__ . '/controllers/admin_features.php';
require_once __DIR__ . '/controllers/setup.php';
require_once __DIR__ . '/controllers/downloads.php';
require_once __DIR__ . '/controllers/admin_logs.php';
require_once __DIR__ . '/controllers/telemetry.php';
require_once __DIR__ . '/controllers/admin_telemetry.php';
require_once __DIR__ . '/controllers/updates.php';
require_once __DIR__ . '/controllers/admin_diagnostics.php';
require_once __DIR__ . '/controllers/picture_game.php';
require_once __DIR__ . '/controllers/tags.php';
require_once __DIR__ . '/controllers/exif.php';
// Load Smart Gallery Admin and public routes after the shared gallery controllers.
require_once __DIR__ . '/controllers/smart_galleries.php';
