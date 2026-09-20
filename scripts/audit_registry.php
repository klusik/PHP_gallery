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

return [
    'profiles' => [
        'quick' => [
            'php-regression',
            'mvc-boundaries',
            'source-contract-inventory',
            'source-documentation-changed',
            'source-policy-changed',
            'node-fast',
            'winapp',
            'mutation-contracts',
            'version-audit',
            'php-lint-changed',
            'js-lint-changed',
        ],
        'full' => [
            'php-regression',
            'mvc-boundaries',
            'source-contract-inventory',
            'source-documentation-changed',
            'source-policy-changed',
            'node-full',
            'winapp',
            'mutation-contracts',
            'version-audit',
            'php-lint',
            'js-lint',
            'browser-map',
        ],
        'release' => [
            'php-regression',
            'mvc-boundaries',
            'source-contract-inventory',
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
        'admin_panel_lifecycle_browser_test.mjs' => [
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
        'lightbox_map_navigation_test.mjs' => [],
        'lightbox_preload_lifecycle_test.mjs' => [],
        'lightbox_zoom_model_test.mjs' => [],
        'progressive_thumbnail_renderer_test.mjs' => [],
        'public_search_progressive_test.mjs' => [],
        'telemetry_image_observability_test.mjs' => [],
        'telemetry_photo_lifecycle_test.mjs' => [],
    ],
];
