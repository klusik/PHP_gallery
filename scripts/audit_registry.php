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
 *   2026-09-05
 */

declare(strict_types=1);

$phpRegistry = require __DIR__ . '/audit_php_registry.php';

$registry = [
    'profiles' => [
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
        // Deterministic source/plan gate before any browser, database, or OS matrix.
        // It qualifies the checked-out candidate and never repairs artifacts.
        'candidate-preflight' => [
            // CI checks out a clean commit, so uncommitted-only --changed lint selects
            // zero files. Lint the full source before allowing costly matrix jobs.
            'php-lint',
            'js-lint',
            'source-documentation-changed',
            'source-policy-changed',
            'source-contract-inventory',
            'python-import-policy',
            'mvc-boundaries',
            'mutation-contracts',
            'ci-workflow-contract',
            'manifest',
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

    // Most PHP tests are self-contained. Keep only true environment exceptions here.
    'php_test_requirements' => [
        'runtime_module_plan_test.php' => [
            'timeout' => 240,
            'reason' => 'Compiles the complete source dependency graph and verifies composed/legacy loading in 192 isolated children; 45 seconds does not cover the measured 72-second Windows run.',
        ],
        'deploy_app_packaging_test.php' => [
            'timeout' => 600,
            'reason' => 'Real Bash and PowerShell folder/ZIP proofs copy the complete production and source-review inventory into an owned dirty fixture.',
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
        'breadcrumb_workflow_integration_test.php' => [
            'timeout' => 180,
        ],
        'breadcrumb_workflow_integration_test.php' => [
            'timeout' => 180,
        ],
        'gallery_workflow_browser_test.php' => [
            'timeout' => 180,
        ],
        'thumbnail_format_metadata_consistency_test.php' => [
            'extensions' => ['gd'],
            'missing_status' => 'BLOCKED',
            'reason' => 'The thumbnail metadata fixture requires the PHP GD extension.',
        ],
    ],

    // Explicit registry is intentional. Some Node scripts need arguments or a real browser.
    'node_tests' => [
        'hosted_release_policy_test.mjs' => [],
        'release_reconciliation_test.mjs' => [],
        'release_origin_test.mjs' => [],
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
        'lightbox_zoom_model_test.mjs' => [],
        'progressive_thumbnail_renderer_test.mjs' => [],
        'public_search_progressive_test.mjs' => [],
        'telemetry_image_observability_test.mjs' => [],
        'telemetry_photo_lifecycle_test.mjs' => [],
        'telemetry_navigation_timing_test.mjs' => [],
        'telemetry_cache_accounting_test.mjs' => [],
    ],
];

$registry['php_fast_tests'] = $phpRegistry['quick_tests'];
$registry['performance_probes'] = require __DIR__ . '/audit_performance_registry.php';
$registry['route_performance_probes'] = require __DIR__ . '/audit_route_probe_registry.php';
foreach ($phpRegistry['serial_tests'] as $name => $reason) {
    $registry['php_test_requirements'][$name] = array_replace($registry['php_test_requirements'][$name] ?? [],
        ['serial' => true, 'serial_reason' => $reason]);
}

return $registry;
