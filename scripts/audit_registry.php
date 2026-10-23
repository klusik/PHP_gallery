<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/audit_registry.php
 * Module Type: Audit Configuration
 *
 * Purpose:
 *   Defines the explicit source-tree audit suites and exceptional test invocations.
 *
 * Responsibilities:
 *   - Keep profile composition deterministic and reviewable
 *   - Define shared hosted source, portable, and sharded FULL owners
 *   - Describe Node tests that require temporary output arguments or browser tooling
 *   - Declare per-test environment requirements that cannot be inferred safely
 *   - Avoid blind execution of every file matching a broad glob
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
 *   - Add new exceptional tests here instead of teaching the runner filename-specific hacks.
 *
 * Last Updated:
 *   2026-10-15
 */

declare(strict_types=1);

$phpRegistry = require __DIR__ . '/audit_php_registry.php';

$registry = [
    'profiles' => [
        // Opt-in advice only; never part of BASIC/FULL/release qualification.
        'readability' => [
            'readability-contract',
            'readability-advisory',
        ],
        // Minimal source gate for feature pushes and prepared release deltas.
        'basic' => [
            'php-lint',
            'js-lint',
            'source-documentation-changed',
        ],
        // Source-only authoring and preparation feedback; no release actions or heavy regressions.
        'release-preflight' => [
            'php-lint',
            'js-lint',
            'source-documentation-changed',
            'source-policy-changed',
            'source-contract-inventory',
            'python-import-policy',
            'ci-workflow-contract',
        ],
        // Medium-cost source-only contracts; no generated release artifact prerequisite.
        // Stage D retains the full exact-candidate suite after release preparation.
        'release-stage-b' => [
            'mvc-boundaries',
            'mutation-contracts',
        ],
        // Generated-artifact and workflow-integrity gate before any browser, database, or OS matrix.
        // Whole-tree source contracts have one shared owner in source-contracts.
        'candidate-preflight' => [
            'ci-workflow-contract',
            'manifest',
        ],
        // Shared environment-independent source qualification for split hosted FULL owners.
        'source-contracts' => [
            'php-lint',
            'js-lint',
            'source-documentation-changed',
            'source-policy-changed',
            'source-contract-inventory',
            'python-import-policy',
            'mvc-boundaries',
            'mutation-contracts',
            'ci-workflow-contract',
            'version-audit',
        ],
        // Portable runtime suites have one exact-candidate owner across the hosted matrix.
        'portable' => [
            'node-full',
            'winapp',
        ],
        // Each hosted FULL PHP owner runs the same centrally partitioned PHP regression tree.
        'hosted-full' => [
            'php-regression',
            'runtime-performance',
            'php-lint',
        ],
        'quick' => [
            'php-fast',
            'runtime-performance',
            'mvc-boundaries',
            'python-import-policy',
            'source-documentation-changed',
            'source-policy-changed',
            'node-fast',
            'mutation-contracts',
            'version-audit',
            'php-lint-changed',
            'js-lint-changed',
        ],
        'full' => [
            'php-regression',
            'packaging',
            'runtime-performance',
            'mvc-boundaries',
            'source-contract-inventory',
            'python-import-policy',
            'source-documentation-changed',
            'source-policy-changed',
            'node-full',
            'winapp',
            'mutation-contracts',
            'version-audit',
            'php-lint',
            'js-lint',
            'browser-map',
            'manifest',
        ],
        'release' => [
            'php-regression',
            'packaging',
            'runtime-performance',
            'mvc-boundaries',
            'source-contract-inventory',
            'python-import-policy',
            'source-documentation-changed',
            'source-policy-changed',
            'node-full',
            'winapp',
            'mutation-contracts',
            'version-audit',
            'php-lint',
            'js-lint',
            'browser-map',
            'release-consistency',
            'manifest',
            'git-diff-check',
        ],
    ],

    // Mandatory individual PASS in GitHub-hosted full/release PHP regressions.
    // These are coverage requirements, not an alternate selector or extra execution.
    'hosted_full_required_php_tests' => [
        'runtime_module_plan_test.php',
    ],

    // Database-independent policy and real small-package proofs have one central
    // owner; the required three-platform job executes them on the exact candidate.
    'packaging_tests' => [
        'production_file_policy_test.php',
        'production_package_test.php',
    ],

    // Most PHP tests are self-contained. Keep only true environment exceptions here.
    'php_test_requirements' => [
        'gallery_music_service_test.php' => [
            'extensions' => ['pdo_sqlite'],
            'missing_status' => 'BLOCKED',
            'reason' => 'Physical playlist mutations use the production schema observer against an isolated, confirmed pre-catalog SQLite metadata fixture.',
        ],
        'music_catalog_title_policy_test.php' => [
            'extensions' => ['pdo_sqlite'],
            'missing_status' => 'BLOCKED',
            'reason' => 'Canonical title policy uses the production settings owner with isolated in-memory SQLite persistence.',
        ],
        'runtime_module_plan_test.php' => [
            'timeout' => 240,
            'reason' => 'Compiles the complete source dependency graph and verifies each module in separate composed/legacy children; the actual count is retained in metrics, and 45 seconds does not cover the measured 72-second Windows run.',
        ],
        'production_package_test.php' => [
            'extensions' => ['zip', 'mbstring'],
            'missing_status' => 'BLOCKED',
            'reason' => 'The real compact packaging fixture requires ZIP creation, complete-byte archive verification and portable Unicode case-folding.',
        ],
        'cooperative_galleries_foundations_test.php' => [
            'extensions' => ['openssl'],
            'missing_status' => 'BLOCKED',
            'reason' => 'Cooperative secret contracts require authenticated OpenSSL encryption.',
        ],
        'outbound_http_transport_test.php' => [
            'extensions' => ['curl'],
            'missing_status' => 'BLOCKED',
            'reason' => 'Pinned transport contracts need cURL handles and option constants but perform no networking.',
        ],
        'cooperative_pairing_workflow_test.php' => [
            'extensions' => ['pdo_sqlite', 'openssl'],
            'missing_status' => 'BLOCKED',
            'reason' => 'Bilateral pairing contracts require two isolated SQLite databases and authenticated encryption.',
        ],
        'cooperative_galleries_storage_test.php' => [
            'extensions' => ['pdo_sqlite', 'openssl'],
            'missing_status' => 'BLOCKED',
            'reason' => 'Cooperative persistence contracts use isolated SQLite and authenticated encryption.',
        ],
        'gallery_migration_temporary_files_test.php' => [
            'extensions' => ['zip'],
            'missing_status' => 'BLOCKED',
            'reason' => 'Outgoing migration transfer allocation requires the ZIP extension.',
        ],
        'core_persistence_delegation_test.php' => [
            'extensions' => ['pdo_sqlite'],
            'missing_status' => 'BLOCKED',
            'reason' => 'Core delegation contracts use an isolated in-memory SQLite database.',
        ],
        'session_contention_test.php' => [
            'timeout' => 90,
        ],
        'session_route_contention_test.php' => [
            'timeout' => 180,
        ],
        'gallery_image_move_crash_test.php' => [
            'timeout' => 180,
        ],
        'gallery_edit_concurrency_mysql_test.php' => [
            'timeout' => 180,
        ],
        'admin_operation_keys_http_test.php' => [
            'timeout' => 180,
        ],
        'image_decode_pipeline_test.php' => [
            'extensions' => ['gd'],
            'missing_status' => 'BLOCKED',
            'reason' => 'The image decode pipeline fixture requires PHP GD.',
        ],
        'image_decode_upload_pipeline_test.php' => [
            'extensions' => ['gd'],
            'missing_status' => 'BLOCKED',
            'reason' => 'The classic/prepared server-completion decode fixture requires PHP GD.',
        ],
        'image_decode_imagick_fallback_test.php' => [
            'extensions' => ['gd', 'exif'],
            'missing_status' => 'BLOCKED',
            'reason' => 'The admitted GD/optional-Imagick fallback fixture requires GD and EXIF.',
        ],
        'gallery_workflow_integration_test.php' => [
            'timeout' => 180,
        ],
        // Purpose: Bound the authenticated direct-photo-tag HTTP persistence workflow.
        // Type: integer.
        // Units: seconds.
        // Scope: PHP child deadline for image_quick_tag_workflow_test.php.
        // Consumers: Central audit worker running the shared gallery-workflow fixture suite.
        // Rationale: Allow the disposable fixture bootstrap, three bounded Admin HTTP mutations and teardown while remaining well below the general audit timeout.
        'image_quick_tag_workflow_test.php' => [
            'timeout' => 120,
        ],
        'breadcrumb_workflow_integration_test.php' => [
            'timeout' => 180,
        ],
        // Purpose: Bound the existing full-stack Chromium workflow below the overall audit budget.
        // Type: integer.
        // Units: seconds.
        // Scope: PHP child deadline for the authenticated live Gallery browser regression.
        // Consumers: Central audit worker supervising gallery_workflow_browser_test.php.
        // Rationale: Allow the 240-second browser result window, up to 30 seconds of browser startup, and 30 seconds for shutdown and fixture cleanup.
        'gallery_workflow_browser_test.php' => [
            'timeout' => 300,
        ],
        // Purpose: Bound the isolated public-widget lifecycle browser workflow.
        // Type: integer.
        // Units: seconds.
        // Scope: PHP child deadline for the authenticated public-widget persistence regression.
        // Consumers: Central audit worker supervising public_widget_persistence_browser_test.php.
        // Rationale: Cover 60 seconds for Chromium startup/navigation/DevTools setup, 78 seconds for bounded fullscreen target/input/acknowledgement (including 10 seconds of CDP polling overrun), 35 seconds for the post-interaction result, 20 seconds for both saved Home/Gallery preview checks under one shared deadline, and 30 seconds for shutdown and exact fixture cleanup: 223 seconds, leaving 22 seconds under this 245-second PHP child deadline.
        'public_widget_persistence_browser_test.php' => [
            'timeout' => 245,
        ],
        'thumbnail_format_metadata_consistency_test.php' => [
            'extensions' => ['gd'],
            'missing_status' => 'BLOCKED',
            'reason' => 'The thumbnail metadata fixture requires the PHP GD extension.',
        ],
    ],

    // Explicit registry is intentional. Some Node scripts need arguments or a real browser.
    // Website publication qualifies its own artifact without duplicating CMS regressions.
    'website_tests' => [
        'project_website_test.mjs' => ['browser' => true, 'timeout' => 180],
    ],
    'node_tests' => [
        // Bound Cron Overview browser qualification.
        // Type: int. Units: seconds. Scope: one isolated Chromium fixture.
        // Consumers: central browser suite. Rationale: 90 seconds includes PHP
        // markup rendering, browser startup, deterministic polling and teardown.
        'admin_cron_browser_test.mjs' => ['browser' => true, 'timeout' => 90],
        'gallery_publication_local_time_test.mjs' => [],
        'develop_integration_test.mjs' => [],
        'feature_qualification_test.mjs' => [],
        'integration_status_test.mjs' => [],
        'release_linear_sync_test.mjs' => [],
        'release_delta_test.mjs' => [],
        'coverage_artifacts_test.mjs' => [],
        'release_qualification_record_test.mjs' => [],
        'hosted_release_policy_test.mjs' => [],
        'release_reconciliation_test.mjs' => [],
        'release_origin_test.mjs' => [],
        'linear_release_graph_test.mjs' => [],
        'start_new_release_test.mjs' => [],
        'release_completion_test.mjs' => [],
        'release_completion_handoff_test.mjs' => [],
        'release_draft_test.mjs' => [],
        'release_asset_integrity_test.mjs' => [],
        'release_retirement_test.mjs' => [],
        'admin_upload_clipboard_test.mjs' => [],
        'admin_upload_queue_test.mjs' => [],
        'admin_upload_thumbnail_test.mjs' => [],
        'admin_upload_clipboard_browser_test.mjs' => [
            'browser' => true,
            'timeout' => 60,
        ],
        'simbrief_legacy_dispatch_browser_test.mjs' => [],
        'admin_setup_wizard_browser_test.mjs' => [],
        'cooperative_gallery_browser_test.mjs' => [
            'browser' => true,
            'timeout' => 60,
        ],
        'admin_cooperative_proposals_browser_test.mjs' => [
            'browser' => true,
            'timeout' => 60,
        ],
        'admin_cooperative_galleries_browser_test.mjs' => [
            'browser' => true,
            'timeout' => 60,
        ],
        'admin_panel_lifecycle_browser_test.mjs' => [
            'browser' => true,
            'timeout' => 60,
        ],
        'admin_update_jobs_browser_test.mjs' => [
            'browser' => true,
            'timeout' => 60,
        ],
        'admin_settings_workspace_browser_test.mjs' => [
            'browser' => true,
            'timeout' => 60,
        ],
        'admin_upload_workspace_browser_test.mjs' => [
            'browser' => true,
            'timeout' => 60,
        ],
        'admin_dashboard_workspace_browser_test.mjs' => [
            'browser' => true,
            'timeout' => 60,
        ],
        'admin_trash_confirmation_browser_test.mjs' => [
            'browser' => true,
            'timeout' => 60,
        ],
        'admin_gallery_tree_browser_test.mjs' => [
            'browser' => true,
            'php_argument' => true,
            'timeout' => 60,
        ],
        'admin_gallery_quick_access_browser_test.mjs' => [
            'browser' => true,
            'timeout' => 60,
        ],
        'admin_gallery_features_browser_test.mjs' => [
            'browser' => true,
            'php_argument' => true,
            'timeout' => 60,
        ],
        'theme_appearance_browser_test.mjs' => [
            'browser' => true,
            'php_argument' => true,
            'timeout' => 60,
        ],
        'theme_media_browser_test.mjs' => [
            'browser' => true,
            'php_argument' => true,
            'timeout' => 60,
        ],
        'theme_layout_browser_test.mjs' => [
            'browser' => true,
            'php_argument' => true,
            'timeout' => 60,
        ],
        'theme_language_browser_test.mjs' => [
            'browser' => true,
            'php_argument' => true,
            'timeout' => 60,
        ],
        'theme_custom_css_browser_test.mjs' => [
            'browser' => true,
            'php_argument' => true,
            'timeout' => 60,
        ],
        'theme_visual_editor_browser_test.mjs' => [
            'browser' => true,
            'timeout' => 60,
        ],
        'theme_visual_css_draft_model_test.mjs' => [],
        'theme_visual_css_resize_model_test.mjs' => [],
        'gallery_creation_browser_test.mjs' => [
            'browser' => true,
            'php_argument' => true,
            'timeout' => 60,
        ],
        'gallery_tags_browser_test.mjs' => [
            'browser' => true,
            'php_argument' => true,
            'timeout' => 60,
        ],
        'admin_smart_galleries_browser_test.mjs' => [
            'browser' => true,
            'timeout' => 60,
        ],
        'public_home_creation_browser_test.mjs' => [
            'browser' => true,
            'timeout' => 60,
        ],
        'gallery_picker_browser_test.mjs' => [],
        'frontend_operational_policy_test.mjs' => [],
        'gallery_migration_policy_test.mjs' => [],
        'gallery_report_policy_test.mjs' => [],
        'admin_operation_keys_browser_test.mjs' => [
            'browser' => true,
            'timeout' => 60,
        ],
        'gallery_picker_parent_integration_browser_test.mjs' => [
            'browser' => true,
            'php_argument' => true,
            'timeout' => 60,
        ],
        'admin_mutation_completion_test.mjs' => [],
        'admin_mutation_stage4_hardening_test.mjs' => [],
        'admin_gallery_title_completion_test.mjs' => [],
        'admin_gallery_title_completion_browser_test.mjs' => [
            'browser' => true,
            'timeout' => 60,
        ],
        'public_content_widget_admin_browser_test.mjs' => [
            'browser' => true,
            'timeout' => 60,
        ],
        'public_content_widget_public_browser_test.mjs' => [
            'browser' => true,
            'timeout' => 60,
        ],
        // Purpose: Bound production Home and Gallery Theme-width browser qualification, including the full layout matrix and Home page-three route.
        // Type: integer.
        // Units: seconds.
        // Scope: Complete Node child, including PHP fixture generation, browser startup/navigation, result polling, and cleanup.
        // Consumers: Central audit worker supervising public_theme_width_composition_browser_test.mjs.
        // Rationale: The 60-second result window covers concurrent Home/Gallery iframe loads, all 56 width/mode layouts with capped animation settling, and Home page-three navigation; the 150-second child leaves 90 seconds for three PHP renders, browser/DevTools startup, profile cleanup and loopback server shutdown.
        'public_theme_width_composition_browser_test.mjs' => [
            'browser' => true,
            'php_argument' => true,
            'timeout' => 150,
        ],
        'admin_side_panel_created_gallery_refresh_test.mjs' => [],
        'admin_side_panel_delegation_test.mjs' => [],
        'admin_side_panel_gallery_refresh_test.mjs' => [],
        'browser_upload_zip_worker_test.mjs' => [],
        'gallery_benchmark_runtime_scope_test.mjs' => [],
        'gallery_download_client_test.mjs' => [],
        'gallery_download_zip_test.mjs' => [
            'temporary_output' => 'gallery-download-test.zip',
        ],
        'gallery_download_zip64_test.mjs' => [
            'temporary_output' => 'gallery-download-zip64-test.zip',
            'slow' => true,
            'timeout' => 90,
        ],
        'lightbox_map_browser_test.mjs' => [
            'browser' => true,
            'timeout' => 60,
        ],
        'simbrief_ofp_lightbox_browser_test.mjs' => [
            'browser' => true,
            'timeout' => 60,
        ],
        'lightbox_race_browser_test.mjs' => [
            'browser' => true,
            'timeout' => 60,
        ],
        'lightbox_dev_dashboard_browser_test.mjs' => [
            'browser' => true,
            'timeout' => 60,
        ],
        'breadcrumb_browser_test.mjs' => [
            'browser' => true,
            'php_argument' => true,
            'timeout' => 60,
        ],
        'lightbox_map_navigation_test.mjs' => [],
        'lightbox_preload_lifecycle_test.mjs' => [],
        'lightbox_navigation_lifecycle_test.mjs' => [],
        'lightbox_resource_lifecycle_test.mjs' => [],
        'lightbox_slideshow_speed_test.mjs' => [],
        // Bound the isolated music browser fixture under the established browser
        // child policy. Type: int. Units: seconds. Scope: one hosted child.
        // Consumers: central Node/browser runner. Rationale: 60 seconds retains
        // browser startup/teardown headroom above the shared fixture deadline.
        'gallery_music_browser_test.mjs' => [
            'browser' => true,
            'timeout' => 60,
        ],
        // Bound the production Music Manager renderer and mutation fixture.
        // Type: int. Units: seconds. Scope: one hosted browser child.
        // Consumers: central browser-map runner. Rationale: 90 seconds covers
        // bounded PHP rendering, one Chromium session, both viewports and teardown.
        'admin_music_browser_test.mjs' => [
            'browser' => true,
            'php_argument' => true,
            'timeout' => 90,
        ],
        // Bound the nested Media editor's real-view and canonical-write fixture.
        // Type: int. Units: seconds. Scope: one hosted browser child.
        // Consumers: central browser-map runner. Rationale: 90 seconds leaves
        // startup/teardown headroom around the 60-second browser deadline and
        // the bounded PHP renders performed before browser startup.
        'admin_gallery_music_browser_test.mjs' => [
            'browser' => true,
            'php_argument' => true,
            'timeout' => 90,
        ],
        'lightbox_zoom_model_test.mjs' => [],
        'progressive_thumbnail_renderer_test.mjs' => [],
        'public_content_widget_floating_test.mjs' => [],
        'public_content_widget_admin_position_test.mjs' => [],
        'public_search_progressive_test.mjs' => [],
        'telemetry_image_observability_test.mjs' => [],
        'telemetry_photo_lifecycle_test.mjs' => [],
        'telemetry_navigation_timing_test.mjs' => [],
        'telemetry_cache_accounting_test.mjs' => [],
    ],
];

$registry['php_fast_tests'] = $phpRegistry['quick_tests'];
$registry['hosted_full_php_shard_count'] =
    $phpRegistry['hosted_full_php_shard_count'];
$registry['performance_probes'] = require
    __DIR__ . '/audit_performance_registry.php';
$registry['route_performance_probes'] = require
    __DIR__ . '/audit_route_probe_registry.php';
foreach ($phpRegistry['serial_tests'] as $name => $reason) {
    $registry['php_test_requirements'][$name] = array_replace(
        $registry['php_test_requirements'][$name] ?? [],
        ['serial' => true, 'serial_reason' => $reason],
    );
}

return $registry;
