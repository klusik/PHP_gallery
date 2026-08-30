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
 *   2026-08-18
 */

declare(strict_types=1);

namespace Gallery\Core;


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
// Load explicit three-state schema inspection before audited feature consumers migrate to it.
require_once __DIR__ . '/services/schema_inspection.php';
// Load fail-closed mutation schema policy before destructive and ingestion services.
require_once __DIR__ . '/services/mutation_schema_policy.php';
// Load optional presentation schema policy before render/report feature services.
require_once __DIR__ . '/services/presentation_schema_policy.php';
// Load multilingual content resolution before gallery/image readers and public renderers.
require_once __DIR__ . '/services/content_localization.php';
// Load public render profiling helpers before public gallery services can record timings.
require_once __DIR__ . '/services/public_render_profiler.php';
// Load admin render profiling helpers before admin controllers can record dashboard timings.
require_once __DIR__ . '/services/admin_render_profiler.php';
// Load database usage helpers before dashboard and storage pages need capacity metrics.
require_once __DIR__ . '/services/admin_database_usage.php';
// Load explicit database maintenance after shared database usage and schema helpers.
require_once __DIR__ . '/services/database_maintenance.php';
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
// Load reusable gallery ordering helpers before public and admin lists render or persist order.
require_once __DIR__ . '/services/gallery_sorting.php';
// Load gallery-grid inheritance helpers after pagination dimension helpers are available.
require_once __DIR__ . '/services/gallery_grid.php';
// Load favorite gallery shortcuts before public and admin headers render navigation.
require_once __DIR__ . '/services/favorite_galleries.php';
// Load shared gallery picker data helpers before public and admin renderers build destination controls.
require_once __DIR__ . '/services/gallery_picker.php';
// Load separated service modules. These require_once calls preserve the legacy app/services.php include contract.
require_once __DIR__ . '/services/gallery_mutations.php';
require_once __DIR__ . '/services/gallery_metadata_organizer.php';
require_once __DIR__ . '/services/picture_manager.php';
require_once __DIR__ . '/services/image_scanning.php';
// Load persistent duplicate-review ledger helpers before the detector renders filtered findings.
require_once __DIR__ . '/services/duplicate_photo_ledger.php';
require_once __DIR__ . '/services/duplicate_photo_detector.php';
require_once __DIR__ . '/services/uploads.php';
require_once __DIR__ . '/services/upload_automation.php';
require_once __DIR__ . '/services/mobile_webdav.php';
require_once __DIR__ . '/services/thumbnails.php';
// Load public thumbnail renderer policy after responsive/progressive picture helpers are available.
require_once __DIR__ . '/services/public_thumbnail_rendering.php';
// Load browser upload helpers after upload and thumbnail services are available.
require_once __DIR__ . '/services/browser_uploads.php';
// Load browser-assisted thumbnail rebuild helpers after shared browser upload helpers.
require_once __DIR__ . '/services/browser_thumbnail_rebuild.php';
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
// Load persistent gallery-description favicon caching after gallery paths are available.
require_once __DIR__ . '/services/link_favicons.php';
// Load browser-driven discovery after path and sidecar helpers can inspect gallery folders.
require_once __DIR__ . '/services/admin_gallery_discovery.php';
// Load Admin storage statistics after path and derivative helpers are available.
require_once __DIR__ . '/services/admin_storage_statistics.php';
require_once __DIR__ . '/services/gallery_display.php';
require_once __DIR__ . '/services/lightbox_metadata.php';
require_once __DIR__ . '/services/download_signatures.php';
require_once __DIR__ . '/services/downloads.php';
// Load media renaming after downloads so stale ZIP archives can be invalidated.
require_once __DIR__ . '/services/media_renamer.php';
require_once __DIR__ . '/services/logs.php';
// Load filesystem-backed Admin log archiving after log export helpers are available.
require_once __DIR__ . '/services/admin_log_archives.php';
// Load gallery benchmark helpers after logs so benchmark runs can be recorded as support artifacts.
require_once __DIR__ . '/services/gallery_benchmark.php';
// Load opt-in full Admin test-run diagnostics after benchmark/log helpers and before maintenance triggers.
require_once __DIR__ . '/services/admin_test_runs.php';
require_once __DIR__ . '/services/admin_test_run_analysis.php';
// Load scheduled site maintenance after logs, thumbnails, downloads, and cleanup helpers are available.
require_once __DIR__ . '/services/site_maintenance.php';
// Load durable login helpers before authentication controllers restore expired PHP sessions.
require_once __DIR__ . '/services/auth_persistence.php';
// Load Google login helpers after logs and before auth controllers render account linking controls.
require_once __DIR__ . '/services/google_auth.php';
// Load authentication throttling after logs so rate-limit events can be recorded safely.
require_once __DIR__ . '/services/auth_throttle.php';
// Load generic token and trusted-client primitives before the dormant viewer identity domain.
require_once __DIR__ . '/services/security_tokens.php';
require_once __DIR__ . '/services/client_ip.php';
// Load dormant viewer-account foundations. No Phase 0 route exposes these services.
require_once __DIR__ . '/services/viewer_accounts.php';
require_once __DIR__ . '/services/viewer_tokens.php';
require_once __DIR__ . '/services/viewer_rate_limits.php';
require_once __DIR__ . '/services/viewer_security_events.php';
// Load the first-party anonymous anti-automation gate before registration/mail orchestration.
require_once __DIR__ . '/services/viewer_anti_automation.php';
// Load dormant pending-registration and mail-abuse boundaries after viewer identity/rate primitives.
require_once __DIR__ . '/services/viewer_registration.php';
require_once __DIR__ . '/services/viewer_mail.php';
// Load dormant viewer lifecycle/content policy before login orchestration can establish recent credential proof.
require_once __DIR__ . '/services/viewer_lifecycle.php';
require_once __DIR__ . '/services/viewer_content_foundations.php';
// Load the first viewer-owned content service after the canonical source authorization/quota contracts.
require_once __DIR__ . '/services/viewer_favourites.php';
// Load private viewer collections after favourites and shared content-authorization foundations.
require_once __DIR__ . '/services/viewer_collections.php';
// Load Phase 3 collection sharing after private collection ownership/locking helpers.
require_once __DIR__ . '/services/viewer_collection_shares.php';
// Load route-free viewer login/reset orchestration only after token, rate, event, registration, mail, and lifecycle boundaries.
require_once __DIR__ . '/services/viewer_authentication.php';
// Load administrator viewer provisioning only after authentication/lifecycle helpers are available.
require_once __DIR__ . '/services/viewer_admin_accounts.php';
// Load the Phase 1.0 HTTP cookie adapter after the underlying viewer authentication services.
require_once __DIR__ . '/services/viewer_http.php';
// Load read-only Phase 4.4 Viewer security operations after all authoritative Viewer state services.
require_once __DIR__ . '/services/viewer_security_operations.php';
require_once __DIR__ . '/services/viewer_maintenance.php';
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
// Load versioned Smart Gallery rules after tags, search, and gallery access helpers.
require_once __DIR__ . '/services/smart_galleries.php';
require_once __DIR__ . '/services/flight_maps.php';
require_once __DIR__ . '/services/exif.php';
require_once __DIR__ . '/services/admin_gallery_report.php';
require_once __DIR__ . '/services/gallery_migration.php';

// Load the centralized Admin Settings ownership registry after all setting providers.
require_once __DIR__ . '/services/admin_settings_registry.php';
