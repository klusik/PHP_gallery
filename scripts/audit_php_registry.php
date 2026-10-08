<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/audit_php_registry.php
 * Module Type: Audit Configuration
 * Purpose: Defines curated PHP feedback and exclusive regression exceptions.
 * Responsibilities:
 *   - Keep development coverage explicit and independent of historical timings
 *   - Document shared-resource and timing-sensitive process isolation
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 * Last Updated: 2026-10-05
 */

declare(strict_types=1);

return [
    // Exercise orchestration, fail-closed storage, mutation and authorized media paths.
    // Full/release still discover every standalone PHP regression test.
    'quick_tests' => [
        'audit_runner_test.php',
        'cli_http_boundary_test.php',
        'production_file_policy_test.php',
        'updates_path_safety_test.php',
        'database_engine_contract_test.php',
        'mvc_architecture_boundaries_test.php',
        'mvc_pdo_provenance_test.php',
        'session_context_test.php',
        'request_session_helpers_test.php',
        'request_https_proxy_test.php',
        'source_contract_debt_ratchet_test.php',
        'policy_constants_changes_test.php',
        'policy_constants_cli_base_test.php',
        'runtime_kernel_test.php',
        'runtime_dependencies_test.php',
        'runtime_route_probe_test.php',
        // Complete compilation and both loaders for every module belong to full/release.
        'runtime_plan_ratchet_test.php',
        'module_split_path_resolution_test.php',
        'feature_policy_core_test.php',
        'feature_policy_adapters_test.php',
        'auth_schema_policy_test.php',
        'gallery_access_schema_policy_test.php',
        'nsfw_schema_policy_test.php',
        'mutation_schema_policy_test.php',
        'presentation_schema_policy_test.php',
        'mutation_response_contract_test.php',
        'admin_operation_keys_test.php',
        'admin_side_panel_gallery_mutation_test.php',
        'gallery_public_paths_test.php',
        'gallery_description_links_render_test.php',
        'upload_writer_ownership_test.php',
        'image_decode_upload_pipeline_test.php',
        'public_thumbnail_markup_test.php',
        'public_thumbnail_rendering_model_test.php',
        'breadcrumb_component_test.php',
        'breadcrumb_gallery_settings_test.php',
        'breadcrumb_theme_settings_test.php',
        'thumbnail_source_identity_test.php',
        'thumbnail_format_metadata_consistency_test.php',
        'simbrief_ofp_public_http_test.php',
        'lightbox_zoom_integration_test.php',
        'lightbox_zoom_lifecycle_test.php',
        'lightbox_zoom_quality_candidates_test.php',
        'lightbox_zoom_quality_indicator_test.php',
        'lightbox_zoom_quality_lifecycle_test.php',
        'lightbox_zoom_quality_rendering_test.php',
        'lightbox_zoom_translation_test.php',
        'updater_safety_model_test.php',
    ],
    // Exclusive means drain active jobs, run alone, then resume parallel dispatch.
    'serial_tests' => [
        'cli_http_boundary_test.php' => 'Starts bounded isolated PHP and Apache HTTP children; exclusive execution prevents nested server/process contention.',
        'simbrief_ofp_public_http_test.php' => 'Starts a disposable loopback PHP HTTP server; run alone to avoid nested HTTP-child contention.',
        'installer_first_install_test.php' => 'Starts a config-free HTTP installation and runs the complete migration chain in its own disposable database; exclusive execution prevents nested server contention.',
        'deploy_app_packaging_test.php' => 'Starts real Bash and PowerShell packaging children against an owned dirty fixture; exclusive execution avoids nested child-process contention.',
        'database_engine_contract_test.php' => 'Checks a migrated vote constraint inside a rolled-back transaction in the externally supplied disposable database; exclusive execution prevents fixture mutation overlap.',
        'runtime_module_plan_test.php' => 'Compiles the dependency inventory and runs its own bounded four-worker module fixtures; exclusive execution avoids nested pool contention.',
        'audit_runner_test.php' => 'Runs its own bounded scheduler fixtures and timeout children; isolation prevents nested-pool oversubscription.',
        'patch_notes_ai_evidence_test.php' => 'Runs nested Git evidence children and forced timeout cleanup against an owned repository; isolation prevents nested-process contention.',
        'session_contention_test.php' => 'Measures lock acquisition and request timing; concurrent regression CPU load changes its control measurements.',
        'session_route_contention_test.php' => 'Measures actual-route session contention and mutates the shared disposable workflow fixture.',
        'route_navdata_background_test.php' => 'Acquires the repository cache/navdata-update.lock shared with the navdata importer.',
        'admin_updates_ui_test.php' => 'Optional preview output uses the fixed repository cache/admin-updates-ui-preview.html path.',
        'updater_resumable_state_machine_test.php' => 'Exercises real repository cache/updates/jobs paths and cleanup alongside updater fixture state.',
        'admin_operation_keys_http_test.php' => 'Mutates the externally supplied shared disposable HTTP/database workflow fixture.',
        'gallery_edit_concurrency_mysql_test.php' => 'Runs competing writers against the externally supplied shared disposable HTTP/database fixture.',
        'gallery_image_move_crash_test.php' => 'Kills its own mutation workers and repairs shared disposable database and gallery state.',
        'gallery_workflow_browser_test.php' => 'Browser journeys mutate the externally supplied shared disposable HTTP/database fixture.',
        'gallery_workflow_integration_test.php' => 'Authenticated workflows mutate the externally supplied shared disposable HTTP/database fixture.',
        'breadcrumb_workflow_integration_test.php' => 'Breadcrumb Theme/gallery saves and Trash restore mutate the shared disposable HTTP/database fixture.',
        'content_language_workflow_integration_test.php' => 'Language, translation, and favorite-navigation assertions temporarily mutate the shared disposable HTTP/database fixture.',
        'viewer_phase07_mysql_concurrency_test.php' => 'Runs nested writers against the common explicitly supplied MySQL test database.',
    ],
];
