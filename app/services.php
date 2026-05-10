<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services.php
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


// Load DB-backed application settings before any feature module reads app_setting().
require_once __DIR__ . '/services/app_settings.php';
// Load schema helpers before feature modules perform optional-column checks.
require_once __DIR__ . '/services/database_helpers.php';
// Load public render profiling helpers before public gallery services can record timings.
require_once __DIR__ . '/services/public_render_profiler.php';
// Load custom CSS helpers before theme rendering needs preset and asset paths.
require_once __DIR__ . '/services/custom_css.php';
// Load theme settings and CSS default helpers after custom CSS paths are available.
require_once __DIR__ . '/services/theme.php';
// Load favicon service helpers. Kept separate only after fixing module-relative paths.
require_once __DIR__ . '/services/favicon.php';
// Load gallery and theme background helpers after their module-relative paths were corrected.
require_once __DIR__ . '/services/gallery_backgrounds.php';
// Load reusable pagination helpers before controllers render public lists.
require_once __DIR__ . '/services/pagination.php';
// Load gallery-grid inheritance helpers after pagination dimension helpers are available.
require_once __DIR__ . '/services/gallery_grid.php';
// Load separated service modules. These require_once calls preserve the legacy app/services.php include contract.
require_once __DIR__ . '/services/gallery_mutations.php';
require_once __DIR__ . '/services/image_scanning.php';
require_once __DIR__ . '/services/uploads.php';
require_once __DIR__ . '/services/thumbnails.php';
require_once __DIR__ . '/services/thumbnail_bounds.php';
require_once __DIR__ . '/services/gallery_covers.php';
// Load gallery branding helpers before sidecar persistence reads branding metadata.
require_once __DIR__ . '/services/gallery_branding.php';
require_once __DIR__ . '/services/gallery_access.php';
require_once __DIR__ . '/services/public_paths.php';
require_once __DIR__ . '/services/gallery_lookup.php';
require_once __DIR__ . '/services/gallery_sidecars.php';
require_once __DIR__ . '/services/gallery_paths.php';
require_once __DIR__ . '/services/gallery_display.php';
require_once __DIR__ . '/services/download_signatures.php';
require_once __DIR__ . '/services/downloads.php';
require_once __DIR__ . '/services/logs.php';
require_once __DIR__ . '/services/telemetry_settings.php';
require_once __DIR__ . '/services/telemetry_privacy.php';
require_once __DIR__ . '/services/telemetry.php';
require_once __DIR__ . '/services/telemetry_rollup.php';
require_once __DIR__ . '/services/database_observer.php';
require_once __DIR__ . '/services/updates.php';
require_once __DIR__ . '/services/picture_game.php';
require_once __DIR__ . '/services/tags.php';
require_once __DIR__ . '/services/exif.php';
