<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/gallery_audit_remediation_stage11_contract_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Locks the cross-stage invariants introduced by the gallery audit remediation program.
 *
 * Responsibilities:
 *   - Protect centralized public-media authorization ownership
 *   - Protect corrected telemetry semantics, photo lifecycle, and traffic segmentation
 *   - Protect completed observability and Stage 6 cardinality controls
 *   - Protect legacy ZIP health, legacy JPEG inventory, report semantics, and maintenance diagnostics
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - This is an integration contract, not a replacement for the focused fixtures.
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

/** Throw when one cross-stage remediation invariant is no longer visible in source. */
function gallery_audit_stage11_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$access = (string) file_get_contents($root . '/app/services/gallery_access.php');
$mediaController = (string) file_get_contents($root . '/app/controllers/public_media.php');
$publicSearch = (string) file_get_contents($root . '/app/services/public_search.php');
$publicSearchProgressive = (string) file_get_contents($root . '/app/services/public_search_progressive.php');
$telemetry = (string) file_get_contents($root . '/app/services/telemetry.php');
$telemetrySettings = (string) file_get_contents($root . '/app/services/telemetry_settings.php');
$usage = (string) file_get_contents($root . '/public/assets/usage.js');
$telemetryAsset = (string) file_get_contents($root . '/public/assets/telemetry.js');
$databaseObserver = (string) file_get_contents($root . '/app/services/database_observer.php');
$downloadCache = (string) file_get_contents($root . '/app/services/download_artifact_cache.php');
$thumbnailCompatibility = (string) file_get_contents($root . '/app/services/thumbnail_compatibility.php');
$reportModel = (string) file_get_contents($root . '/app/models/admin_gallery_report.php');
$maintenance = (string) file_get_contents($root . '/app/services/site_maintenance.php');

// Stage 1: public media continues to delegate authorization to the shared gallery-access policy.
gallery_audit_stage11_assert(
    str_contains($access, 'function public_image_visible_to_current_visitor')
        && str_contains($mediaController, 'public_image_visible_to_current_visitor')
        && str_contains($publicSearch, 'function public_search_gallery_visible_to_current_visitor')
        && str_contains($publicSearch, 'function public_search_image_visible_to_current_visitor')
        && str_contains($publicSearch, 'public_search_gallery_visible_to_current_visitor($gallery)')
        && str_contains($publicSearch, 'public_search_image_visible_to_current_visitor($row, $gallery)')
        && str_contains($publicSearchProgressive, 'public_search_gallery_visible_to_current_visitor($gallery)')
        && str_contains($publicSearchProgressive, 'public_search_image_visible_to_current_visitor($row, $gallery)'),
    'Public media and search must continue to use centralized visitor-aware gallery/image access policy.'
);

// Stage 2: corrected semantics remain versioned and page views are explicit events rather than session-start side effects.
gallery_audit_stage11_assert(
    str_contains($telemetrySettings, "const TELEMETRY_SEMANTICS_VERSION = '2';")
        && str_contains($telemetry, "'public.page.viewed'")
        && str_contains($telemetry, "'public.gallery.viewed'"),
    'Telemetry session/page-view semantics must remain explicitly versioned and event-driven.'
);

// Stage 3: the two compatibility assets remain identical and photo-open origin stays low-cardinality.
gallery_audit_stage11_assert(
    hash_equals(hash('sha256', $usage), hash('sha256', $telemetryAsset))
        && str_contains($usage, 'function photoOpenTrigger(trigger)')
        && str_contains($telemetry, "['click', 'keyboard', 'swipe', 'slideshow', 'history', 'direct', 'fallback', 'unknown', 'legacy_unclassified']"),
    'Photo-open telemetry must retain one shared state machine and the bounded activation-origin vocabulary.'
);

// Stage 4/5: traffic segmentation and the completed performance/media/cache/database observability paths stay wired.
gallery_audit_stage11_assert(
    str_contains($telemetry, "['all', 'non_bot', 'bot']")
        && str_contains($telemetry, 'client.image_decode_ms')
        && str_contains($telemetry, 'client.image_display_ms')
        && str_contains($telemetry, "'media.thumbnail.served'")
        && str_contains($telemetry, "'cache.lightbox.evicted'")
        && str_contains($databaseObserver, 'telemetry_database_enabled'),
    'Traffic segmentation and Stage 5 observability producers must remain integrated.'
);

// Stage 6: new hourly aggregates keep explicit metric-specific normalization and operator evidence.
gallery_audit_stage11_assert(
    str_contains($telemetry, 'function telemetry_hourly_metric_dimensions')
        && str_contains($telemetry, 'function telemetry_report_storage_diagnostics')
        && str_contains($telemetry, 'function telemetry_report_query_profile')
        && str_contains($telemetry, 'function telemetry_report_query_plans')
        && str_contains($telemetry, 'function telemetry_report_daily_rollup_consistency')
        && str_contains($telemetry, "'daily_required_for_full_window'"),
    'Telemetry cardinality controls, storage evidence, rollup validation, query runtimes, and sanitized query-plan diagnostics must remain explicit.'
);

// Stage 7: legacy ZIP fallback has a structured capability probe separate from the modern download path.
gallery_audit_stage11_assert(
    str_contains($downloadCache, 'function legacy_download_artifact_cache_status_for_path')
        && str_contains($downloadCache, "'legacy_server_build_capable'"),
    'Legacy server ZIP fallback health must remain an explicit structured capability.'
);

// Stage 8: legacy JPEG inventory remains non-destructive until the explicit cleanup action is invoked.
gallery_audit_stage11_assert(
    str_contains($thumbnailCompatibility, 'function thumbnail_legacy_jpg_inventory')
        && str_contains($thumbnailCompatibility, "'cleanup_recommended'")
        && str_contains($thumbnailCompatibility, 'function delete_legacy_jpg_thumbnails_for_image'),
    'Legacy JPEG inventory and explicit cleanup ownership must remain separate.'
);

// Stage 9: structural containers are distinct from genuinely empty leaf galleries.
gallery_audit_stage11_assert(
    str_contains($reportModel, "'zero_direct_image_count'")
        && str_contains($reportModel, "'empty_leaf_count'")
        && str_contains($reportModel, "'structural_container_count'"),
    'Complete-report gallery semantics must distinguish direct-image absence, empty leaves, and structural containers.'
);

// Stage 10: safe last-success/last-failure maintenance diagnostics remain bounded and operator-visible.
gallery_audit_stage11_assert(
    str_contains($maintenance, 'SITE_MAINTENANCE_LAST_FAILURE_SETTING')
        && str_contains($maintenance, 'function site_maintenance_last_failure')
        && str_contains($maintenance, "'last_failure' => site_maintenance_last_failure()"),
    'Maintenance diagnostics must retain bounded last-failure state alongside successful runs.'
);

echo "gallery_audit_remediation_stage11_contract_test: ok\n";
