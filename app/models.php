<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models.php
 * Module Type: Model Loader
 *
 * Purpose:
 *   Loads database-facing model modules before service-layer orchestration.
 *
 * Responsibilities:
 *   - Keep reusable SQL/data-access code outside controllers and views
 *   - Provide a stable model include point for the plain-PHP MVC structure
 *   - Avoid presentation and HTTP concerns inside model modules
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
 *   - Models may depend on core database helpers but must not render HTML or read request globals.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Core;

require_once __DIR__ . '/models/votes.php';
require_once __DIR__ . '/models/auth.php';
require_once __DIR__ . '/models/auth_throttle.php';
require_once __DIR__ . '/models/auth_persistence.php';
require_once __DIR__ . '/models/google_auth.php';
require_once __DIR__ . '/models/admin_logs.php';
require_once __DIR__ . '/models/admin_log_archives.php';
require_once __DIR__ . '/models/admin_dashboard.php';
require_once __DIR__ . '/models/admin_gallery_discovery.php';
require_once __DIR__ . '/models/admin_database_usage.php';
require_once __DIR__ . '/models/admin_storage_statistics.php';
require_once __DIR__ . '/models/admin_gallery_report.php';
require_once __DIR__ . '/models/database_helpers.php';
require_once __DIR__ . '/models/database_maintenance.php';
require_once __DIR__ . '/models/schema_inspection.php';
require_once __DIR__ . '/models/site_maintenance.php';
require_once __DIR__ . '/models/galleries.php';
require_once __DIR__ . '/models/images.php';
require_once __DIR__ . '/models/duplicate_photo_ledger.php';
require_once __DIR__ . '/models/telemetry.php';
require_once __DIR__ . '/models/upload_automation.php';
require_once __DIR__ . '/models/mobile_webdav.php';
require_once __DIR__ . '/models/app_settings.php';
require_once __DIR__ . '/models/openai_text_assist.php';
require_once __DIR__ . '/models/ai_image_analysis.php';
require_once __DIR__ . '/models/tags.php';
require_once __DIR__ . '/models/image_order.php';
require_once __DIR__ . '/models/gallery_order.php';
require_once __DIR__ . '/models/gallery_mutations.php';
require_once __DIR__ . '/models/gallery_trash.php';
require_once __DIR__ . '/models/viewer_rate_limits.php';
require_once __DIR__ . '/models/viewer_accounts.php';
require_once __DIR__ . '/models/viewer_lifecycle.php';
require_once __DIR__ . '/models/viewer_collections.php';
require_once __DIR__ . '/models/viewer_collection_shares.php';
require_once __DIR__ . '/models/viewer_authentication.php';
require_once __DIR__ . '/models/viewer_registration.php';
require_once __DIR__ . '/models/viewer_tokens.php';
require_once __DIR__ . '/models/viewer_security_events.php';
require_once __DIR__ . '/models/viewer_security_operations.php';
require_once __DIR__ . '/models/viewer_maintenance.php';
require_once __DIR__ . '/models/public_paths.php';
require_once __DIR__ . '/models/picture_manager.php';
require_once __DIR__ . '/models/duplicate_photo_detector.php';
require_once __DIR__ . '/models/exif.php';
require_once __DIR__ . '/models/picture_game.php';
require_once __DIR__ . '/models/thumbnail_metadata.php';
require_once __DIR__ . '/models/thumbnail_maintenance.php';
require_once __DIR__ . '/models/lightbox_metadata.php';
require_once __DIR__ . '/models/media_renamer.php';
require_once __DIR__ . '/models/smart_galleries.php';
require_once __DIR__ . '/models/download_signatures.php';
require_once __DIR__ . '/models/downloads.php';
require_once __DIR__ . '/models/flight_maps.php';
require_once __DIR__ . '/models/navigation_data.php';
require_once __DIR__ . '/models/link_favicons.php';
require_once __DIR__ . '/models/content_localization.php';
require_once __DIR__ . '/models/favorite_galleries.php';
require_once __DIR__ . '/models/viewer_favourites.php';
require_once __DIR__ . '/models/public_search.php';
// Load optional request-local search query instrumentation before progressive model calls.
require_once __DIR__ . '/models/public_search_diagnostics.php';
require_once __DIR__ . '/models/public_search_progressive.php';
