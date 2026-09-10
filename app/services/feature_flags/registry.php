<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/feature_flags/registry.php
 * Module Type: Service
 *
 * Purpose:
 *   Defines canonical capability metadata and stable legacy registry views.
 *
 * Responsibilities:
 *   - Own the canonical capability definitions, groups, keys, and storage-key normalization
 *   - Keep all existing Admin feature keys and localized labels stable
 *   - Describe dependencies, route ownership, persistence source, editability, and disable-data policy
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
 *   - Loaded by app/services/feature_flags.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/feature_flags.php.
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

namespace Gallery\Services;

/**
 * Return the canonical optional-feature registry.
 *
 * Established features remain enabled when no explicit setting exists. Individual
 * definitions may opt into a disabled default when a staged subsystem must require
 * an explicit administrator decision before becoming reachable.
 *
 * @return array<string,array<string,mixed>> Structured capability definitions.
 */
function feature_capability_definitions(): array
{
    $definitions = [
        'public_search' => [
            'group' => 'public_display',
            'label' => t('admin.features.public_search.label', 'Public live search'),
            'description' => t('admin.features.public_search.description', 'Thin live search bar on the public home page and inside galleries.'),
            'routes' => ['public_search'],
        ],
        'lightbox_modes' => [
            'group' => 'public_display',
            'label' => t('admin.features.lightbox_modes.label', 'Lightbox browsing modes'),
            'description' => t('admin.features.lightbox_modes.description', 'Public lightbox viewer, slideshow, fullscreen controls, picture strip, and 3D carousel mode controls.'),
            'routes' => ['gallery_lightbox_data', 'smart_gallery_lightbox_data'],
        ],
        'picture_manager' => [
            'group' => 'public_display',
            'label' => t('admin.features.picture_manager.label', 'Public picture manager controls'),
            'description' => t('admin.features.picture_manager.description', 'Logged-in admin move/copy controls rendered over public gallery photo cards.'),
            'routes' => ['picture_manager_move', 'picture_manager_copy', 'picture_manager_create_gallery', 'picture_manager_download_selection'],
        ],
        'inline_administration' => [
            'group' => 'public_display',
            'label' => t('admin.features.inline_administration.label', 'Inline administration on public pages'),
            'description' => t('admin.features.inline_administration.description', 'Admin edit, add, delete, and reorder controls rendered directly on otherwise public gallery pages.'),
            'routes' => [
                'admin_public_update_gallery',
                'admin_public_update_image',
                'admin_reorder_public_galleries',
                'admin_sort_public_subgalleries_by_date',
            ],
        ],
        'image_voting' => [
            'group' => 'public_display',
            'label' => t('admin.features.image_voting.label', 'Image voting'),
            'description' => t('admin.features.image_voting.description', 'Vote buttons, vote submissions, and vote indicators on gallery photos.'),
            'routes' => ['vote'],
        ],
        'picture_game' => [
            'group' => 'public_display',
            'label' => t('admin.features.picture_game.label', 'Picture game'),
            'description' => t('admin.features.picture_game.description', 'Public picture-comparison game and its per-gallery enable controls.'),
            'dependencies' => ['image_voting'],
            'routes' => ['picture_game'],
        ],
        'smart_galleries' => [
            'group' => 'public_display',
            'label' => t('admin.features.smart_galleries.label', 'Smart Galleries'),
            'description' => t('admin.features.smart_galleries.description', 'Saved dynamic galleries, their public pages, physical-gallery attachments, and Smart Gallery-specific browsing workflows.'),
            'routes' => ['admin_smart_galleries', 'smart_gallery'],
        ],
        'downloads' => [
            'group' => 'public_display',
            'label' => t('admin.features.downloads.label', 'Gallery ZIP downloads'),
            'description' => t('admin.features.downloads.description', 'Download links and ZIP archive routes for one gallery or all galleries.'),
            'routes' => [
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
        'thumbnail_warmup' => [
            'group' => 'public_display',
            'label' => t('admin.features.thumbnail_warmup.label', 'Public thumbnail self-healing'),
            'description' => t('admin.features.thumbnail_warmup.description', 'Allow authorized public gallery requests to request guarded background repair of missing thumbnails.'),
            'source' => ['type' => 'domain_adapter', 'adapter' => 'thumbnail_warmup'],
            'fresh_default_enabled' => false,
            'routes' => ['thumbnail_warmup'],
        ],
        'multilingual_content' => [
            'group' => 'public_display',
            'label' => t('admin.features.multilingual_content.label', 'Multilingual authored content'),
            'description' => t('admin.features.multilingual_content.description', 'Translated gallery and photo titles/descriptions while keeping interface-language translation independent.'),
        ],
        'public_tag_browsing' => [
            'group' => 'public_display',
            'label' => t('admin.features.public_tag_browsing.label', 'Public tag browsing'),
            'description' => t('admin.features.public_tag_browsing.description', 'Public tag landing pages and clickable tag navigation while retaining tags as internal metadata.'),
            'routes' => ['tag'],
        ],
        'viewer_accounts' => [
            'group' => 'accounts',
            'label' => t('admin.features.viewer_accounts.label', 'Viewer accounts and collections'),
            'description' => t('admin.features.viewer_accounts.description', 'Master switch for viewer login, invitations, favourites, private collections, unlisted collection sharing, and viewer-account administration. Disabled by default.'),
            'default_enabled' => false,
            'routes' => ['admin_viewer_invitations'],
            'route_prefixes' => ['viewer_'],
        ],
        'gallery_maps' => [
            'group' => 'maps_flightsim',
            'label' => t('admin.features.gallery_maps.label', 'EXIF GPS gallery maps'),
            'description' => t('admin.features.gallery_maps.description', 'Map buttons, photo GPS pins, and gallery maps based on image EXIF coordinates.'),
        ],
        'flight_maps' => [
            'group' => 'maps_flightsim',
            'label' => t('admin.features.flight_maps.label', 'Flight route maps'),
            'description' => t('admin.features.flight_maps.description', 'Stored simflying route maps, route text editor fields, and map payloads from resolved flight paths.'),
        ],
        'navigation_data' => [
            'group' => 'maps_flightsim',
            'label' => t('admin.features.navigation_data.label', 'Navigation data maintenance'),
            'description' => t('admin.features.navigation_data.description', 'Admin tools for local airport, navaid, and waypoint lookup data used by route maps.'),
            'routes' => ['admin_navdata', 'admin_update_navdata', 'navdata_lookup'],
        ],
        'simbrief' => [
            'group' => 'maps_flightsim',
            'label' => t('admin.features.simbrief.label', 'SimBrief integration'),
            'description' => t('admin.features.simbrief.description', 'Gallery description and route-map generation from the latest SimBrief OFP.'),
            'routes' => ['admin_simbrief_description'],
        ],
        'openai_text_assist' => [
            'group' => 'ai_automation',
            'label' => t('admin.features.openai_text_assist.label', 'OpenAI text assistance'),
            'description' => t('admin.features.openai_text_assist.description', 'Profile API settings and AI-assisted gallery/photo description generation or cleanup.'),
            'routes' => ['admin_openai_text_assist'],
        ],
        'ai_image_metadata' => [
            'group' => 'ai_automation',
            'label' => t('admin.features.ai_image_metadata.label', 'Local AI image metadata'),
            'description' => t('admin.features.ai_image_metadata.description', 'Locally generated visual metadata used for internal search and admin diagnostics.'),
        ],
        'upload_api' => [
            'group' => 'ai_automation',
            'label' => t('admin.features.upload_api.label', 'API uploader'),
            'description' => t('admin.features.upload_api.description', 'Token-based upload API and the desktop companion upload workflow.'),
            'routes' => ['admin_api_manager', 'admin_upload_automation_token', 'upload_automation_upload'],
        ],
        'mobile_webdav' => [
            'group' => 'ai_automation',
            'label' => t('admin.features.mobile_webdav.label', 'Mobile WebDAV uploads'),
            'description' => t('admin.features.mobile_webdav.description', 'PhotoSync/WebDAV-compatible mobile upload endpoint and setup screen.'),
            'routes' => ['admin_mobile_uploads', 'mobile_webdav'],
        ],
        'gallery_migration' => [
            'group' => 'ai_automation',
            'label' => t('admin.features.gallery_migration.label', 'Gallery migration transfer'),
            'description' => t('admin.features.gallery_migration.description', 'Manifest, asset receive, and status endpoints for gallery-to-gallery transfer workflows.'),
            'routes' => [
                'gallery_migration_manifest',
                'gallery_migration_asset',
                'gallery_migration_package',
                'gallery_migration_receive_manifest',
                'gallery_migration_receive_asset',
                'gallery_migration_receive_package',
                'gallery_migration_receive_complete',
                'gallery_migration_receive_status',
                'admin_gallery_migration',
            ],
        ],
        'gallery_trash' => [
            'group' => 'admin_tools',
            'label' => t('admin.features.gallery_trash.label', 'Gallery Trash'),
            'description' => t('admin.features.gallery_trash.description', 'Recoverable gallery deletion master. Disabling it preserves existing Trash contents and all purge preferences.'),
            'source' => ['type' => 'domain_adapter', 'adapter' => 'gallery_trash'],
        ],
        'duplicate_photo_detector' => [
            'group' => 'admin_tools',
            'label' => t('admin.features.duplicate_photo_detector.label', 'Duplicate Photo Detector'),
            'description' => t('admin.features.duplicate_photo_detector.description', 'Admin duplicate scanning and review workflow while preserving the duplicate-review ledger when disabled.'),
            'routes' => ['admin_duplicate_photos'],
        ],
        'metadata_organizer' => [
            'group' => 'admin_tools',
            'label' => t('admin.features.metadata_organizer.label', 'Metadata Organizer'),
            'description' => t('admin.features.metadata_organizer.description', 'Batch organization workflow driven by stored capture-date metadata without changing ordinary gallery/photo editing.'),
            'routes' => ['admin_metadata_organizer_preview_batch', 'admin_metadata_organizer_apply_date_plan_batch'],
        ],
        'exif_gallery_date_suggestions' => [
            'group' => 'admin_tools',
            'label' => t('admin.features.exif_gallery_date_suggestions.label', 'EXIF Gallery Date Suggestions'),
            'description' => t('admin.features.exif_gallery_date_suggestions.description', 'Automated EXIF-derived gallery date suggestion and review workflow while retaining manual From/To date editing.'),
            'routes' => ['admin_gallery_dates', 'admin_gallery_date_suggestion'],
        ],
        'complete_gallery_report' => [
            'group' => 'admin_tools',
            'label' => t('admin.features.complete_gallery_report.label', 'Complete Gallery Report'),
            'description' => t('admin.features.complete_gallery_report.description', 'Standalone complete gallery report generation without affecting System Health, Logs, Integrity, or other diagnostics.'),
            'routes' => ['admin_gallery_report', 'admin_gallery_report_generate'],
        ],
        'development_diagnostics' => [
            'group' => 'admin_tools',
            'label' => t('admin.features.development_diagnostics.label', 'Development Diagnostics'),
            'description' => t('admin.features.development_diagnostics.description', 'Admin/public render profiling, browser diagnostics, and Gallery benchmark instrumentation without disabling core logs, System Health, or Integrity.'),
            'source' => ['type' => 'domain_adapter', 'adapter' => 'development_diagnostics'],
            'default_enabled' => false,
            'routes' => [
                'admin_gallery_benchmark_start',
                'admin_gallery_benchmark_browser',
                'admin_gallery_benchmark_status',
                'admin_gallery_benchmark_probe',
                'admin_gallery_benchmark_download',
            ],
        ],
        'remote_favicon_discovery' => [
            'group' => 'admin_tools',
            'label' => t('admin.features.remote_favicon_discovery.label', 'Remote Favicon Discovery'),
            'description' => t('admin.features.remote_favicon_discovery.description', 'Allow bounded outbound discovery of unknown-site favicons after Admin gallery saves. Built-in and already cached local icons remain available when disabled.'),
            'source' => ['type' => 'app_setting', 'key' => 'remote_favicon_discovery_enabled'],
            'fresh_default_enabled' => false,
        ],
        'built_in_update_installer' => [
            'group' => 'admin_tools',
            'label' => t('admin.features.built_in_update_installer.label', 'Built-in Update Installer'),
            'description' => t('admin.features.built_in_update_installer.description', 'Allow PHP Gallery to install, reinstall, restore, or resume application update jobs. Read-only update status, checks, patch notes, and Integrity remain available.'),
        ],
        'advanced_database_maintenance' => [
            'group' => 'admin_tools',
            'label' => t('admin.features.advanced_database_maintenance.label', 'Advanced Database Maintenance'),
            'description' => t('admin.features.advanced_database_maintenance.description', 'Allow database cleanup, schema repair, ANALYZE, and OPTIMIZE mutations while retaining read-only database inspection and dry-run planning.'),
        ],
        'media_renamer' => [
            'group' => 'admin_tools',
            'label' => t('admin.features.media_renamer.label', 'Media renamer'),
            'description' => t('admin.features.media_renamer.description', 'Admin tool for planned media filename cleanup and generated derivative movement.'),
            'routes' => ['admin_media_renamer'],
        ],
        'telemetry' => [
            'group' => 'admin_tools',
            'label' => t('admin.features.telemetry.label', 'Anonymous telemetry'),
            'description' => t('admin.features.telemetry.description', 'Anonymous usage collection script, ingestion endpoint, and admin telemetry reports.'),
            'routes' => [
                'admin_telemetry',
                'admin_telemetry_settings',
                'admin_telemetry_maintenance',
                'admin_telemetry_export',
                'telemetry_ingest',
                'usage_collect',
            ],
        ],
        'admin_test_runs' => [
            'group' => 'admin_tools',
            'label' => t('admin.features.admin_test_runs.label', 'Enable test runs for admins'),
            'description' => t('admin.features.admin_test_runs.description', 'Shows an Admin-only Test run control on gallery pages. A run performs bounded deep diagnostics, clears safe caches, traces PHP/database/process/cache/concurrency state, and stores a downloadable report. Disabled by default.'),
            'default_enabled' => false,
            'routes' => [
                'admin_test_run_start',
                'admin_test_run_probe',
                'admin_test_run_finish',
                'admin_test_run_finalize',
                'admin_test_run_download',
            ],
        ],
    ];

    foreach ($definitions as &$definition) {
        $definition += [
            'source' => ['type' => 'feature_flag'],
            'dependencies' => [],
            'routes' => [],
            'route_prefixes' => [],
            'editable_on_features' => true,
            'data_disable_policy' => 'preserve',
            'behavior_tags' => [],
            'settings_route' => '',
            'settings_params' => [],
            'settings_fragment' => '',
        ];
    }
    unset($definition);

    $presentationMetadata = [
        'public_search' => ['behavior_tags' => ['public'], 'settings_route' => 'admin', 'settings_params' => ['maintenance_tab' => 'general'], 'settings_fragment' => 'admin-tab-maintenance'],
        'lightbox_modes' => ['behavior_tags' => ['public']],
        'picture_manager' => ['behavior_tags' => ['public', 'admin-only']],
        'inline_administration' => ['behavior_tags' => ['public', 'admin-only', 'writes-files']],
        'image_voting' => ['behavior_tags' => ['public']],
        'picture_game' => ['behavior_tags' => ['public']],
        'smart_galleries' => ['behavior_tags' => ['public', 'admin-only']],
        'downloads' => ['behavior_tags' => ['public']],
        'thumbnail_warmup' => ['behavior_tags' => ['public', 'writes-files', 'background-work'], 'settings_route' => 'admin', 'settings_params' => ['maintenance_tab' => 'media'], 'settings_fragment' => 'admin-tab-maintenance'],
        'multilingual_content' => ['behavior_tags' => ['public', 'admin-only']],
        'public_tag_browsing' => ['behavior_tags' => ['public']],
        'viewer_accounts' => ['behavior_tags' => ['public', 'privacy'], 'settings_route' => 'admin_viewer_invitations'],
        'gallery_maps' => ['behavior_tags' => ['public']],
        'flight_maps' => ['behavior_tags' => ['public']],
        'navigation_data' => ['behavior_tags' => ['admin-only', 'writes-files']],
        'simbrief' => ['behavior_tags' => ['admin-only', 'outbound-network']],
        'openai_text_assist' => ['behavior_tags' => ['admin-only', 'outbound-network']],
        'ai_image_metadata' => ['behavior_tags' => ['admin-only', 'background-work']],
        'upload_api' => ['behavior_tags' => ['admin-only', 'writes-files']],
        'mobile_webdav' => ['behavior_tags' => ['public', 'writes-files']],
        'gallery_migration' => ['behavior_tags' => ['admin-only', 'writes-files', 'outbound-network']],
        'gallery_trash' => ['behavior_tags' => ['admin-only', 'writes-files', 'destructive-maintenance'], 'settings_route' => 'admin_trash'],
        'duplicate_photo_detector' => ['behavior_tags' => ['admin-only', 'writes-files'], 'settings_route' => 'admin_duplicate_photos'],
        'metadata_organizer' => ['behavior_tags' => ['admin-only', 'writes-files']],
        'exif_gallery_date_suggestions' => ['behavior_tags' => ['admin-only', 'writes-files'], 'settings_route' => 'admin_gallery_dates'],
        'complete_gallery_report' => ['behavior_tags' => ['admin-only', 'diagnostic'], 'settings_route' => 'admin_gallery_report'],
        'development_diagnostics' => ['behavior_tags' => ['admin-only', 'diagnostic', 'writes-files'], 'settings_route' => 'admin_settings', 'settings_params' => ['section' => 'privacy'], 'settings_fragment' => 'settings-privacy'],
        'remote_favicon_discovery' => ['behavior_tags' => ['admin-only', 'outbound-network'], 'settings_route' => 'admin_settings', 'settings_params' => ['section' => 'privacy'], 'settings_fragment' => 'settings-privacy'],
        'built_in_update_installer' => ['behavior_tags' => ['admin-only', 'writes-files', 'outbound-network'], 'settings_route' => 'admin_update'],
        'advanced_database_maintenance' => ['behavior_tags' => ['admin-only', 'writes-files', 'destructive-maintenance'], 'settings_route' => 'admin_storage_statistics', 'settings_params' => ['tab' => 'maintenance']],
        'media_renamer' => ['behavior_tags' => ['admin-only', 'writes-files']],
        'telemetry' => ['behavior_tags' => ['privacy', 'background-work'], 'settings_route' => 'admin_telemetry'],
        'admin_test_runs' => ['behavior_tags' => ['admin-only', 'diagnostic', 'writes-files']],
    ];
    foreach ($presentationMetadata as $key => $metadata) {
        if (isset($definitions[$key])) {
            $definitions[$key] = array_replace($definitions[$key], $metadata);
        }
    }

    return $definitions;
}


/**
 * Return the legacy feature-flag registry view.
 *
 * This compatibility wrapper deliberately exposes the canonical capability
 * definitions so existing Admin and extension code keep the same feature keys
 * while the policy core can add richer metadata.
 *
 * @return array<string,array<string,mixed>> Structured feature definitions.
 */
function feature_flag_definitions(): array
{
    return feature_capability_definitions();
}


/**
 * Return user-facing feature groups in display order.
 *
 * @return array<string array<string, string>>.
 */
function feature_flag_groups(): array
{
    return [
        'public_display' => [
            'label' => t('admin.features.group.public_display', 'Gallery display and visitor features'),
            'description' => t('admin.features.group.public_display_help', 'Public browsing, lightbox, voting, downloads, and logged-in admin controls on gallery pages.'),
        ],
        'accounts' => [
            'label' => t('admin.features.group.accounts', 'Accounts and personalized features'),
            'description' => t('admin.features.group.accounts_help', 'Viewer identities, favourites, private collections, and other account-scoped visitor features.'),
        ],
        'maps_flightsim' => [
            'label' => t('admin.features.group.maps_flightsim', 'Maps, GPS, and flight simulation'),
            'description' => t('admin.features.group.maps_flightsim_help', 'EXIF GPS maps, stored flight routes, navigation data, and SimBrief workflows.'),
        ],
        'ai_automation' => [
            'label' => t('admin.features.group.ai_automation', 'AI, uploads, and automation'),
            'description' => t('admin.features.group.ai_automation_help', 'OpenAI text tools, local AI metadata, upload APIs, mobile WebDAV, and migration transfers.'),
        ],
        'admin_tools' => [
            'label' => t('admin.features.group.admin_tools', 'Admin and maintenance tools'),
            'description' => t('admin.features.group.admin_tools_help', 'Special-purpose admin tools that can be hidden from simpler installations.'),
        ],
    ];
}

/**
 * Normalize an incoming feature key to the registry key format.
 *
 * @param string $key Lookup key.
 * @return string Text result for the caller.
 */
function feature_flag_normalize_key(string $key): string
{
    return strtolower(trim(preg_replace('/[^a-zA-Z0-9_]+/', '_', $key) ?? '', '_'));
}

/**
 * Return the app_settings key used to store one feature toggle.
 *
 * @param string $key Lookup key.
 * @return string Text result for the caller.
 */
function feature_flag_setting_key(string $key): string
{
    return FEATURE_FLAG_SETTING_PREFIX . feature_flag_normalize_key($key) . FEATURE_FLAG_SETTING_SUFFIX;
}


