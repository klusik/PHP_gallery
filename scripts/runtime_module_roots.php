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
            'Gallery\\Services\\public_visual_preview_is_active',
            'Gallery\\Services\\public_visual_preview_request_decision',
            'Gallery\\Services\\public_visual_preview_inherit_referrer_context',
        ],
        // One preview-only document root; dispatch inspects CSS before the renderer calls the background-context function.
        'visual-preview-document' => [
            'Gallery\\Services\\custom_css_visual_preview_inspection_decision',
            'Gallery\\Services\\theme_background_visual_editor_context',
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
        // PDF and audio attachments share this established attachment owner.
        // Retain its historical machine name and full routing/access closure;
        // a separate music root would exceed the consumed module allowance.
        // Music-tag titles and Trash catalog snapshots load it only on demand.
        'gallery-pdf-documents' => [
            'Gallery\\Services\\find_gallery_by_public_path',
            'Gallery\\Services\\resolve_public_gallery_path',
            'Gallery\\Services\\simbrief_ofp_local_pdf_path',
            'Gallery\\Services\\gallery_pdf_documents_read',
            'Gallery\\Services\\gallery_pdf_documents_read_index_state',
            'Gallery\\Services\\gallery_attachment_storage_summary',
            'Gallery\\Services\\gallery_pdf_publication_forces_private',
            'Gallery\\Controllers\\gallery_pdf_documents_view_model',
            'Gallery\\Controllers\\gallery_pdf_documents_smart_view_model',
            'Gallery\\Views\\view_render_gallery_pdf_documents',
            'Gallery\\Controllers\\cms_gallery_music',
            'Gallery\\Controllers\\gallery_music_view_model',
            'Gallery\\Controllers\\gallery_music_smart_view_model',
            'Gallery\\Views\\view_render_gallery_music',
            'Gallery\\Services\\gallery_attachment_usage_reconcile_batch',
            'Gallery\\Services\\gallery_pdf_documents_usage_source_lock',
            'Gallery\\Services\\gallery_pdf_documents_usage_begin',
            'Gallery\\Services\\gallery_pdf_documents_usage_publish',
        ],
        'schema-unavailable-response' => [
            'Gallery\\Controllers\\cms_public_schema_unavailable',
        ],
        // Authenticated document actions load the uploader only after the public attachment list is prepared.
        'domain-admin-uploads' => [
            'Gallery\\Controllers\\gallery_pdf_documents_admin_controls',
        ],
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
        'feature-disabled-response' => [
            'Gallery\\Core\\cms_apply_feature_disabled_route_response',
        ],
        'viewer-identity' => [
            'Gallery\\Core\\viewer_identity_remember_restore_request',
        ],
        'database-observer' => [
            'Gallery\\Services\\telemetry_observe_db_query',
        ],
        'request-maintenance' => [
            'Gallery\\Services\\application_autoupdate_maybe_run',
        ],
        'archive-request-trigger' => [
            'Gallery\\Services\\admin_log_archive_register_request_trigger',
        ],
        'site-request-trigger' => [
            'Gallery\\Services\\site_maintenance_register_request_trigger',
        ],
        'site-maintenance-work' => [
            // Reuse the established private-worker closure for durable media
            // admission; ordinary attachment/public rendering stays unchanged.
            'Gallery\\Services\\gallery_attachment_usage_background_start',
            'Gallery\\Services\\gallery_attachment_usage_background_run',
            'Gallery\\Services\\cache_lifecycle_chain_runtime_available',
            'Gallery\\Services\\cache_lifecycle_chain_start',
            'Gallery\\Services\\cache_lifecycle_chain_run',
            'Gallery\\Services\\cache_lifecycle_visitor_run',
            'Gallery\\Services\\cache_lifecycle_scheduled_run',
            'Gallery\\Services\\cache_lifecycle_scheduled_slice',
            'Gallery\\Services\\cache_lifecycle_status',
            'Gallery\\Services\\site_maintenance_cache_lifecycle_action',
            'Gallery\\Services\\site_maintenance_run',
            'Gallery\\Services\\application_update_run_scheduled_cache_cleanup',
            'Gallery\\Services\\application_update_cache_cleanup_status',
        ],
        'archive-maintenance-work' => [
            'Gallery\\Services\\admin_log_archive_maintenance_run',
        ],
        'updater-work' => [
            'Gallery\\Services\\application_update_background_chain_start',
            'Gallery\\Services\\application_update_background_chain_run',
            'Gallery\\Services\\application_update_job_worker_diagnostic',
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
        'Gallery\\Controllers\\cms_gallery_ofp_pdf' => 'gallery-pdf-documents',
        'Gallery\\Controllers\\cms_gallery_music' => 'gallery-pdf-documents',
        // Music identities reuse the shared taxonomy administration owner.
        // The 98-module allowance is already consumed; this keeps one catalog
        // administration root without adding a transitive dependency edge.
        'Gallery\\Controllers\\cms_admin_music' => 'domain-admin-tags',
        // Support-grant routes share the already established Admin authentication owner.
        'Gallery\\Controllers\\cms_admin_support' => 'domain-admin-auth',
        'Gallery\\Controllers\\cms_admin_support_redeem' => 'domain-admin-auth',
        'Gallery\\Controllers\\cms_public_media' => 'public-media',
        'Gallery\\Controllers\\cms_thumb' => 'public-thumbnails',
        'Gallery\\Controllers\\cms_public_thumb' => 'public-thumbnails',
        'Gallery\\Controllers\\cms_not_found' => 'http-not-found',
    ],
    // These existing roots have stable lifecycle/domain ownership. The compiler
    // creates an edge only when the owner's complete historic closure is present.
    'shared_modules' => [
        'database-observer',
        'request-policy',
        'routing-paths',
        'breadcrumb-policy',
        'breadcrumb-presentation',
        'content-localization',
        'gallery-pdf-documents',
    ],
    'module_dependencies' => [
        // The full gallery editor already contains the complete metadata owner
        // closure, including cover selection. Reuse that existing domain owner
        // without adding a module, widening PHP closures or duplicating its files.
        'domain-admin-galleries-edit-page' => [
            'domain-admin-galleries-edit-metadata',
        ],
        // Reuse the existing maintenance owner only where its complete historical
        // closure is already present, including Admin authentication. The edge
        // replaces four duplicated files without broadening any route's closure.
        'domain-admin-auth' => ['request-maintenance'],
        'domain-admin-test-runs' => ['request-maintenance'],
        'domain-site-maintenance' => ['request-maintenance'],
        'domain-updates' => ['request-maintenance'],
        'site-maintenance-work' => ['request-maintenance'],
        'updater-work' => ['request-maintenance'],
        // Rendering follows view-model preparation and reuses its shared Core dependencies.
        'breadcrumb-presentation' => ['breadcrumb-policy'],
    ],
    // Dispatch loads public policy before only these sensitive route families.
    'route_dependencies' => [
        'public-policy' => [
            'home',
            'gallery',
            'smart_gallery',
            'gallery_access',
            'share',
            'tag',
            'sitemap',
            'picture_game',
            'media',
            'gallery_ofp_pdf',
            'gallery_music',
            'thumb',
            'public_media',
            'public_thumb',
            'thumbnail_warmup',
            'gallery_cover_asset',
            'gallery_branding_asset',
            'vote',
            'gallery_map_data',
            'gallery_lightbox_data',
            'smart_gallery_lightbox_data',
            'smart_gallery_map_data',
            'public_search',
            'download_gallery_start',
            'download_gallery',
            'download_gallery_manifest',
            'download_gallery_file',
            'download_smart_gallery_start',
            'download_smart_gallery',
            'download_smart_gallery_manifest',
            'download_smart_gallery_file',
        ],
    ],
    // Injected loaders execute before deferred worker calls. Guarded diagnostics
    // hooks are supplied by the opt-in request-diagnostics lifecycle module.
    'deferred_calls' => [
        // The Maintenance task loader selects the complete attachment owner
        // before either callback. Passive maintenance pages do not load it.
        'Gallery\\Services\\maintenance_center_analyze_music_repository' => [
            'Gallery\\Services\\music_repository_schema_status',
        ],
        'Gallery\\Services\\maintenance_center_execute_music_repository' => [
            'Gallery\\Services\\gallery_music_migrate_repository',
        ],
        'Gallery\\Services\\maintenance_center_execute_attachment_usage' => [
            'Gallery\\Services\\gallery_attachment_usage_reconcile_batch',
        ],
        // Public registration reads only the durable setting. Its successful
        // response callback loads the existing private-worker owner for kickoff.
        'Gallery\\Services\\site_maintenance_register_attachment_usage_trigger' => [
            'Gallery\\Services\\gallery_attachment_usage_background_start',
        ],
        'Gallery\\Services\\gallery_attachment_usage_background_run' => [
            // The strict controller loads the attachment owner before this
            // bounded source reader; admission alone never loads its closure.
            'Gallery\\Services\\gallery_attachment_usage_reconcile_batch',
        ],
        'Gallery\\Services\\simbrief_description_save_ofp_for_gallery' => [
            // The injected request bridge obtains the kernel before loading the
            // attachment owner for this save path.
            'Gallery\\Core\\cms_runtime_kernel',
            'Gallery\\Services\\gallery_pdf_documents_usage_source_lock',
            'Gallery\\Services\\gallery_pdf_documents_usage_begin',
            'Gallery\\Services\\gallery_pdf_documents_read_index_state',
            'Gallery\\Services\\gallery_pdf_documents_usage_publish',
        ],
        // The updater-only transport branch explicitly requires the lightweight
        // cache runtime policy before this shared single-worker admission check.
        'Gallery\\Services\\site_maintenance_fire_background_worker' => [
            'Gallery\\Services\\cache_lifecycle_chain_runtime_available',
        ],
        'Gallery\\Core\\cms_register_update_background_continuation' => [
            'Gallery\\Services\\application_update_background_chain_start',
        ],
        'Gallery\\Controllers\\cms_site_maintenance_cron' => [
            'Gallery\\Services\\application_update_background_chain_run',
            // The exclusive token-authenticated media branch loads this owner;
            // it does not enter the scheduled site/cache maintenance worker.
            'Gallery\\Services\\gallery_attachment_usage_background_run',
        ],
        // Explicit storage jobs load the existing attachment owner. Cached
        // Admin reads and ordinary public routes do not acquire its closure.
        'Gallery\\Services\\admin_storage_statistics_attachment_batch' => [
            'Gallery\\Services\\gallery_attachment_storage_summary',
        ],
        // GitHub writes explicitly load the producer-lease owner immediately before
        // acquisition; read-only public routes do not need that storage module.
        'Gallery\\Services\\cms_github_api_write_cache' => [
            'Gallery\\Services\\cache_lifecycle_producer_lease',
        ],
        // Historical media&ofp=1 links select the PDF route module before its handler runs.
        'Gallery\\Controllers\\cms_media' => [
            'Gallery\\Controllers\\cms_gallery_ofp_pdf',
        ],
        // The request bridge is loaded before route dispatch; its request-local kernel
        // explicitly loads the attachment owner at the authenticated report boundary.
        'Gallery\\Controllers\\upload_automation_handle_flight_report_action' =>
            [
                'Gallery\\Core\\cms_runtime_kernel',
            ],
        // The authenticated flight-report handler loads the existing attachment module
        // immediately before either service action; defer these edges to avoid duplicating
        // gallery_pdf_documents.php in the upload route closure.
        'Gallery\\Services\\upload_flight_report_status' => [
            'Gallery\\Services\\gallery_pdf_documents_list',
            'Gallery\\Services\\gallery_pdf_documents_read',
        ],
        'Gallery\\Services\\upload_flight_report_attach' => [
            'Gallery\\Services\\gallery_pdf_documents_validate_pdf_source',
            'Gallery\\Services\\gallery_pdf_documents_attach',
        ],
        // Reserved PDF children and attached documents load these modules before use.
        'Gallery\\Services\\gallery_effective_visibility' => [
            'Gallery\\Services\\gallery_pdf_publication_forces_private',
        ],
        // Music title policy is needed for music-tag usage only; Trash requires
        // physical playlist/catalog readers only while capturing or restoring music.
        // All deferred callers load the established gallery-pdf-documents root.
        'Gallery\\Services\\admin_tag_usage_rows' => [
            'Gallery\\Services\\music_catalog_display_title',
        ],
        'Gallery\\Services\\gallery_trash_music_catalog_write_mode' => [
            'Gallery\\Services\\music_catalog_physical_write_mode',
        ],
        'Gallery\\Services\\gallery_trash_capture_snapshot' => [
            'Gallery\\Services\\gallery_music_state',
            'Gallery\\Models\\music_catalog_model_trash_track_refs',
        ],
        'Gallery\\Services\\restore_gallery_trash_entry_owned' => [
            'Gallery\\Services\\gallery_music_state',
            'Gallery\\Services\\music_catalog_restore_trash_snapshot',
        ],
        'Gallery\\Controllers\\render_public_gallery_branding_header' => [
            'Gallery\\Controllers\\gallery_music_view_model',
            'Gallery\\Views\\view_render_gallery_music',
            'Gallery\\Controllers\\gallery_pdf_documents_view_model',
            'Gallery\\Views\\view_render_gallery_pdf_documents',
        ],
        // Smart media uses the same complete attachment owner only after
        // canonical source membership and PDF/music presence are resolved.
        'Gallery\\Controllers\\cms_smart_gallery' => [
            'Gallery\\Controllers\\gallery_music_smart_view_model',
            'Gallery\\Views\\view_render_gallery_music',
            'Gallery\\Controllers\\gallery_pdf_documents_smart_view_model',
            'Gallery\\Views\\view_render_gallery_pdf_documents',
        ],
        'Gallery\\Controllers\\gallery_pdf_documents_view_model' => [
            'Gallery\\Controllers\\gallery_pdf_documents_admin_controls',
        ],
        // Dispatch loads the preview-document module before inspection; shared layout invokes the context function only after inspection passes.
        'Gallery\\Controllers\\shared_layout_header_model' => [
            'Gallery\\Services\\theme_background_visual_editor_context',
        ],
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
        'Gallery\\Services\\site_maintenance_register_cache_trigger' => [
            // The callback explicitly loads lightweight storage before this
            // interval; workers follow only after the reviewed work loader.
            'Gallery\\Services\\CACHE_LIFECYCLE_REFRESH_SECONDS',
            'Gallery\\Services\\cache_lifecycle_visitor_run',
            'Gallery\\Services\\cache_lifecycle_chain_start',
        ],
        'Gallery\\Services\\admin_log_archive_register_request_trigger' => [
            'Gallery\\Services\\admin_log_archive_maintenance_run',
            'Gallery\\Services\\admin_test_run_record_maintenance_event',
        ],
    ],
];
