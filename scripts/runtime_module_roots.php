<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/runtime_module_roots.php
 * Module Type: Development Configuration
 * Purpose: Describe logical lifecycle/domain roots and reviewed deferred dependencies.
 * Responsibilities: Keep loading policy explicit while the compiler owns mechanical file closures.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

return [
    'modules' => [
        'request-policy' => [
            'Gallery\\Core\\current_user',
            'Gallery\\Services\\auth_admin_session_lifetime_seconds',
            'Gallery\\Services\\seo_request_guard_enforcement_decision',
            'Gallery\\Services\\translation_bootstrap_request',
            'Gallery\\Core\\send_security_headers',
            'Gallery\\Services\\schema_inspection_prime_table_snapshots',
            'Gallery\\Services\\feature_flag_route_enabled',
            'Gallery\\Services\\seo_request_guard_route_robots_header_value',
        ],
        'routing-paths' => [
            'Gallery\\Services\\find_gallery_by_public_path',
            'Gallery\\Services\\resolve_public_gallery_path',
        ],
        'public-policy' => [
            'Gallery\\Services\\gallery_visibility_assert_public_policy_available',
            'Gallery\\Services\\gallery_access_assert_public_policy_available',
            'Gallery\\Services\\nsfw_guard_assert_public_policy_available',
        ],
        'schema-unavailable-response' => ['Gallery\\Controllers\\cms_public_schema_unavailable'],
        // Settings-only and rendering callers share each owner independently.
        'breadcrumb-policy' => [
            'Gallery\\Services\\breadcrumb_view_model',
            'Gallery\\Services\\breadcrumb_style_picker_options',
        ],
        // Authored overlays are shared by public cards/navigation and Admin
        // presentation; keep their storage files out of duplicated route owners.
        'content-localization' => [
            'Gallery\\Services\\content_localize_entity',
            'Gallery\\Services\\content_localize_entities',
        ],
        'breadcrumb-presentation' => [
            'Gallery\\Services\\breadcrumb_view_model',
            'Gallery\\Views\\view_render_breadcrumbs',
            'Gallery\\Views\\view_render_breadcrumb_style_picker',
        ],
        'feature-disabled-response' => ['Gallery\\Core\\cms_apply_feature_disabled_route_response'],
        'viewer-identity' => ['Gallery\\Core\\viewer_identity_remember_restore_request'],
        'database-observer' => ['Gallery\\Services\\telemetry_observe_db_query'],
        'request-maintenance' => ['Gallery\\Services\\application_autoupdate_maybe_run'],
        'archive-request-trigger' => ['Gallery\\Services\\admin_log_archive_register_request_trigger'],
        'site-request-trigger' => ['Gallery\\Services\\site_maintenance_register_request_trigger'],
        'site-maintenance-work' => ['Gallery\\Services\\site_maintenance_run'],
        'archive-maintenance-work' => ['Gallery\\Services\\admin_log_archive_maintenance_run'],
        'updater-work' => [
            'Gallery\\Services\\application_update_continue_background_job',
            'Gallery\\Services\\application_autoupdate_dry_run',
            'Gallery\\Services\\application_autoupdate_run_installing_check',
        ],
        'request-diagnostics' => [
            'Gallery\\Services\\admin_test_run_bind_request_transport_context',
            'Gallery\\Services\\admin_test_run_request_begin',
            'Gallery\\Services\\admin_test_run_request_header_intents',
            'Gallery\\Services\\admin_test_run_register_final_shutdown_observer',
            'Gallery\\Services\\admin_test_run_response_logical_finish',
            'Gallery\\Services\\admin_test_run_active',
            'Gallery\\Services\\admin_test_run_record_db_query',
            'Gallery\\Services\\admin_test_run_record_db_connection',
            'Gallery\\Services\\admin_test_run_record_db_prepare',
            'Gallery\\Services\\admin_test_run_record_db_transaction',
            'Gallery\\Services\\admin_test_run_record_maintenance_event',
        ],
        'benchmark-diagnostics' => [
            'Gallery\\Services\\gallery_benchmark_trace_mark',
            'Gallery\\Services\\gallery_benchmark_record_request_completion',
        ],
    ],
    // Distinct public surfaces share some declarations but need different domain closures.
    'handler_modules' => [
        'Gallery\\Controllers\\cms_home' => 'public-home',
        'Gallery\\Controllers\\cms_gallery' => 'public-gallery',
        'Gallery\\Controllers\\cms_robots_txt' => 'public-robots',
        'Gallery\\Controllers\\cms_sitemap_xml' => 'public-sitemap',
        'Gallery\\Controllers\\cms_media' => 'public-media',
        'Gallery\\Controllers\\cms_public_media' => 'public-media',
        'Gallery\\Controllers\\cms_thumb' => 'public-thumbnails',
        'Gallery\\Controllers\\cms_public_thumb' => 'public-thumbnails',
        'Gallery\\Controllers\\cms_not_found' => 'http-not-found',
    ],
    // These existing roots have stable lifecycle/domain ownership. The compiler
    // creates an edge only when the owner's complete historic closure is present.
    'shared_modules' => ['database-observer', 'request-policy', 'routing-paths', 'breadcrumb-policy', 'breadcrumb-presentation', 'content-localization'],
    'module_dependencies' => [
        'updater-work' => ['request-maintenance'],
        // Rendering follows view-model preparation and reuses its shared Core dependencies.
        'breadcrumb-presentation' => ['breadcrumb-policy'],
    ],
    // Dispatch loads public policy before only these sensitive route families.
    'route_dependencies' => [
        'public-policy' => [
            'home', 'gallery', 'smart_gallery', 'gallery_access', 'share', 'tag', 'sitemap',
            'picture_game', 'media', 'thumb', 'public_media', 'public_thumb', 'thumbnail_warmup',
            'gallery_cover_asset', 'gallery_branding_asset', 'vote', 'gallery_map_data',
            'gallery_lightbox_data', 'smart_gallery_lightbox_data', 'smart_gallery_map_data',
            'public_search', 'download_gallery_start', 'download_gallery', 'download_gallery_manifest',
            'download_gallery_file', 'download_smart_gallery_start', 'download_smart_gallery',
            'download_smart_gallery_manifest', 'download_smart_gallery_file',
        ],
    ],
    // Injected loaders execute before deferred worker calls. Guarded diagnostics
    // hooks are supplied by the opt-in request-diagnostics lifecycle module.
    'deferred_calls' => [
        // The database boundary always reports central telemetry. Admin test-run
        // instrumentation is optional and is loaded only when the opted-in
        // request-diagnostics lifecycle module is selected before the first query.
        'Gallery\\Core\\db' => [
            'Gallery\\Services\\admin_test_run_active',
            'Gallery\\Core\\AdminTestRunPDO',
            'Gallery\\Core\\AdminTestRunPDOStatement',
            'Gallery\\Services\\admin_test_run_record_db_connection',
        ],
        'Gallery\\Core\\database_dispatch_query_observation' => [
            'Gallery\\Services\\admin_test_run_record_db_query',
        ],
        'Gallery\\Core\\AdminTestRunPDOStatement' => [
            'Gallery\\Core\\database_dispatch_query_observation',
        ],
        'Gallery\\Core\\AdminTestRunPDO' => [
            'Gallery\\Core\\database_dispatch_query_observation',
            'Gallery\\Services\\admin_test_run_record_db_prepare',
            'Gallery\\Services\\admin_test_run_record_db_transaction',
        ],
        'Gallery\\Services\\application_autoupdate_maybe_run' => [
            'Gallery\\Services\\application_update_continue_background_job',
            'Gallery\\Services\\application_autoupdate_dry_run',
            'Gallery\\Services\\application_autoupdate_run_installing_check',
            'Gallery\\Services\\admin_test_run_record_maintenance_event',
        ],
        'Gallery\\Services\\site_maintenance_register_request_trigger' => [
            'Gallery\\Services\\site_maintenance_run',
            'Gallery\\Services\\admin_test_run_record_maintenance_event',
        ],
        'Gallery\\Services\\admin_log_archive_register_request_trigger' => [
            'Gallery\\Services\\admin_log_archive_maintenance_run',
            'Gallery\\Services\\admin_test_run_record_maintenance_event',
        ],
    ],
];
