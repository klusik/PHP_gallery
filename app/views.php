<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views.php
 * Module Type: View Loader
 *
 * Purpose:
 *   Loads presentation modules after services have prepared the domain helpers
 *   and before controllers start handling requests.
 *
 * Responsibilities:
 *   - Keep view rendering code out of controllers and services
 *   - Preserve the existing function-based application style
 *   - Provide a single include point for template and presenter helpers
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
 *   2026-05-24
 */

declare(strict_types=1);

namespace Gallery\Core;

require_once __DIR__ . '/views/seo.php';
require_once __DIR__ . '/views/http.php';
require_once __DIR__ . '/views/pagination.php';
require_once __DIR__ . '/views/gallery_dates.php';
require_once __DIR__ . '/views/public_render_profiler.php';
require_once __DIR__ . '/views/admin_render_profiler.php';
require_once __DIR__ . '/views/admin_test_run_panel.php';
require_once __DIR__ . '/views/public_search.php';
require_once __DIR__ . '/views/votes.php';
require_once __DIR__ . '/views/admin_auth.php';
require_once __DIR__ . '/views/viewer_accounts.php';
require_once __DIR__ . '/views/public_tags.php';
require_once __DIR__ . '/views/public_gallery_controls.php';
require_once __DIR__ . '/views/public_gallery_cards.php';
require_once __DIR__ . '/views/public_gallery_pages.php';
require_once __DIR__ . '/views/public_gallery_lightbox.php';
require_once __DIR__ . '/views/picture_game.php';
require_once __DIR__ . '/views/admin_chrome.php';
require_once __DIR__ . '/views/admin_features.php';
require_once __DIR__ . '/views/admin_diagnostics.php';
require_once __DIR__ . '/views/admin_media_renamer.php';
require_once __DIR__ . '/views/smart_galleries.php';
require_once __DIR__ . '/views/viewer_lifecycle.php';
require_once __DIR__ . '/views/viewer_collections.php';
require_once __DIR__ . '/views/viewer_collection_shares.php';
require_once __DIR__ . '/views/viewer_favourites.php';
require_once __DIR__ . '/views/admin_gallery_dates.php';
require_once __DIR__ . '/views/admin_gallery_edit_tabs.php';
require_once __DIR__ . '/views/admin_gallery_edit_components.php';
require_once __DIR__ . '/views/admin_public_inline.php';
require_once __DIR__ . '/views/admin_gallery_metadata_organizer.php';
require_once __DIR__ . '/views/admin_tags.php';
require_once __DIR__ . '/views/admin_theme.php';
require_once __DIR__ . '/views/admin_updates.php';
require_once __DIR__ . '/views/admin_logs.php';
require_once __DIR__ . '/views/setup.php';
require_once __DIR__ . '/views/downloads.php';
require_once __DIR__ . '/views/admin_ui.php';
require_once __DIR__ . '/views/admin_storage_statistics.php';
require_once __DIR__ . '/views/admin_gallery_report.php';
require_once __DIR__ . '/views/admin_gallery_report_export.php';
require_once __DIR__ . '/views/admin_search_diagnostics.php';
require_once __DIR__ . '/views/admin_upload_settings.php';
require_once __DIR__ . '/views/thumbnail_bounds.php';
require_once __DIR__ . '/views/admin_uploads.php';
require_once __DIR__ . '/views/upload_automation.php';
require_once __DIR__ . '/views/mobile_webdav.php';
require_once __DIR__ . '/views/admin_telemetry.php';
require_once __DIR__ . '/views/admin_integrity.php';
require_once __DIR__ . '/views/admin_database_usage.php';
require_once __DIR__ . '/views/admin_database_maintenance.php';
require_once __DIR__ . '/views/admin_dashboard_sections.php';
require_once __DIR__ . '/views/admin_dashboard.php';
require_once __DIR__ . '/views/admin_trash.php';
require_once __DIR__ . '/views/admin_language_settings.php';
require_once __DIR__ . '/views/admin_settings.php';
require_once __DIR__ . '/views/navigation_data.php';
require_once __DIR__ . '/views/admin_gallery_renderers.php';
require_once __DIR__ . '/views/admin_gallery_forms.php';
require_once __DIR__ . '/views/admin_gallery_discovery.php';
require_once __DIR__ . '/views/admin_duplicate_photos.php';
require_once __DIR__ . '/views/admin_gallery_migration.php';
require_once __DIR__ . '/views/gallery_descriptions.php';
require_once __DIR__ . '/views/simbrief_descriptions.php';
require_once __DIR__ . '/views/layout.php';
