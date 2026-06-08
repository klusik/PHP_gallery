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
// Load translation helpers early so controllers can use t() for visible text.
require_once __DIR__ . '/services/translations.php';
// Load public SEO request guard helpers before routing enforces query-string safety.
require_once __DIR__ . '/services/seo_request_guard.php';
// Load global feature visibility helpers before optional feature modules initialize.
require_once __DIR__ . '/services/feature_flags.php';
// Load schema helpers before feature modules perform optional-column checks.
require_once __DIR__ . '/services/database_helpers.php';
// Load public render profiling helpers before public gallery services can record timings.
require_once __DIR__ . '/services/public_render_profiler.php';
// Load admin render profiling helpers before admin controllers can record dashboard timings.
require_once __DIR__ . '/services/admin_render_profiler.php';
// Load Admin dashboard data helpers before the dashboard controller is registered.
require_once __DIR__ . '/services/admin_dashboard.php';
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
// Load gallery description layout helpers before public cards are rendered.
require_once __DIR__ . '/services/gallery_description_layout.php';
// Load gallery count badge helpers before public cards are rendered.
require_once __DIR__ . '/services/gallery_count_badges.php';
// Load optional manual gallery-date helpers before admin and public gallery rendering.
require_once __DIR__ . '/services/gallery_dates.php';
// Load gallery-grid inheritance helpers after pagination dimension helpers are available.
require_once __DIR__ . '/services/gallery_grid.php';
// Load favorite gallery shortcuts before public and admin headers render navigation.
require_once __DIR__ . '/services/favorite_galleries.php';
// Load shared gallery picker data helpers before public and admin renderers build destination controls.
require_once __DIR__ . '/services/gallery_picker.php';
// Load separated service modules. These require_once calls preserve the legacy app/services.php include contract.
require_once __DIR__ . '/services/gallery_mutations.php';
require_once __DIR__ . '/services/picture_manager.php';
require_once __DIR__ . '/services/image_scanning.php';
require_once __DIR__ . '/services/uploads.php';
require_once __DIR__ . '/services/upload_automation.php';
require_once __DIR__ . '/services/mobile_webdav.php';
require_once __DIR__ . '/services/thumbnails.php';
// Load AI-analysis queue helpers after media path helpers are available.
require_once __DIR__ . '/services/ai_image_analysis.php';
require_once __DIR__ . '/services/thumbnail_bounds.php';
require_once __DIR__ . '/services/gallery_covers.php';
// Load gallery branding helpers before sidecar persistence reads branding metadata.
require_once __DIR__ . '/services/gallery_branding.php';
require_once __DIR__ . '/services/gallery_access.php';
require_once __DIR__ . '/services/public_paths.php';
require_once __DIR__ . '/services/gallery_lookup.php';
// Load lightbox browsing-mode helpers before sidecar import/export reads gallery.json overrides.
require_once __DIR__ . '/services/gallery_lightbox_mode.php';
require_once __DIR__ . '/services/gallery_sidecars.php';
require_once __DIR__ . '/services/gallery_paths.php';
// Load Admin storage statistics after path and derivative helpers are available.
require_once __DIR__ . '/services/admin_storage_statistics.php';
require_once __DIR__ . '/services/gallery_display.php';
require_once __DIR__ . '/services/lightbox_metadata.php';
require_once __DIR__ . '/services/download_signatures.php';
require_once __DIR__ . '/services/downloads.php';
// Load media renaming after downloads so stale ZIP archives can be invalidated.
require_once __DIR__ . '/services/media_renamer.php';
require_once __DIR__ . '/services/logs.php';
// Load scheduled site maintenance after logs, thumbnails, downloads, and cleanup helpers are available.
require_once __DIR__ . '/services/site_maintenance.php';
// Load durable login helpers before authentication controllers restore expired PHP sessions.
require_once __DIR__ . '/services/auth_persistence.php';
// Load Google login helpers after logs and before auth controllers render account linking controls.
require_once __DIR__ . '/services/google_auth.php';
// Load authentication throttling after logs so rate-limit events can be recorded safely.
require_once __DIR__ . '/services/auth_throttle.php';
require_once __DIR__ . '/services/telemetry_settings.php';
require_once __DIR__ . '/services/telemetry_privacy.php';
require_once __DIR__ . '/services/telemetry.php';
require_once __DIR__ . '/services/telemetry_rollup.php';
require_once __DIR__ . '/services/database_observer.php';
// Load the GitHub API gateway before update services perform remote checks.
require_once __DIR__ . '/services/github.php';
require_once __DIR__ . '/services/updates.php';
// Load SimBrief description helpers after the shared HTTP client is available.
require_once __DIR__ . '/services/simbrief_descriptions.php';
// Load optional OpenAI text assistance after database helpers and gallery lookup helpers are available.
require_once __DIR__ . '/services/openai_text_assist.php';
// Load navigation data helpers before flight maps resolve route identifiers.
require_once __DIR__ . '/services/navigation_data.php';
require_once __DIR__ . '/services/picture_game.php';
require_once __DIR__ . '/services/tags.php';
require_once __DIR__ . '/services/public_search.php';
require_once __DIR__ . '/services/flight_maps.php';
require_once __DIR__ . '/services/exif.php';
require_once __DIR__ . '/services/gallery_migration.php';
