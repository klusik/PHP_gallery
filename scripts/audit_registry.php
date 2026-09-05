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
            'node-fast',
            'winapp',
            'mutation-contracts',
            'version-audit',
            'php-lint-changed',
            'js-lint-changed',
        ],
        'full' => [
            'php-regression',
            'node-full',
            'winapp',
            'mutation-contracts',
            'version-audit',
            'php-lint',
            'js-lint',
        ],
        'release' => [
            'php-regression',
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
        'thumbnail_format_metadata_consistency_test.php' => [
            'extensions' => ['gd'],
            'missing_status' => 'BLOCKED',
            'reason' => 'The thumbnail metadata fixture requires the PHP GD extension.',
        ],
    ],

    // Explicit registry is intentional. Some Node scripts need arguments and one needs a real browser.
    'node_tests' => [
        'admin_mutation_completion_test.mjs' => [],
        'admin_mutation_stage4_hardening_test.mjs' => [],
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
        'lightbox_zoom_model_test.mjs' => [],
        'progressive_thumbnail_renderer_test.mjs' => [],
    ],
];
