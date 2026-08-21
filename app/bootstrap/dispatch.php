<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/bootstrap/dispatch.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Owns the route-to-controller table, feature-gated route checks, and controller dispatch.
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
 *   2026-05-04
 */

declare(strict_types=1);

namespace Gallery\Core;

use Gallery\Services\PublicSchemaPolicyUnavailableException;
use function Gallery\Controllers\cms_public_schema_unavailable;
use function Gallery\Services\feature_flag_render_disabled_route;
use function Gallery\Services\feature_flag_route_enabled;
use function Gallery\Services\gallery_access_assert_public_policy_available;
use function Gallery\Services\gallery_visibility_assert_public_policy_available;
use function Gallery\Services\nsfw_guard_assert_public_policy_available;

/**
 * Dispatch a resolved page identifier to the existing controller route table.
 *
 * @param string $page Resolved page identifier.
 */
function cms_dispatch_page(string $page): void
{
    // Variable $routes stores this steps working value.
    $routes = [
        'home' => '\\Gallery\\Controllers\\cms_home',
        'gallery' => '\\Gallery\\Controllers\\cms_gallery',
        'smart_gallery' => '\\Gallery\\Controllers\\cms_smart_gallery',
        'gallery_access' => '\\Gallery\\Controllers\\cms_gallery_access',
        'share' => '\\Gallery\\Controllers\\cms_share',
        'tag' => '\\Gallery\\Controllers\\cms_tag',
        'robots' => '\\Gallery\\Controllers\\cms_robots_txt',
        'sitemap' => '\\Gallery\\Controllers\\cms_sitemap_xml',
        'picture_game' => '\\Gallery\\Controllers\\cms_picture_game',
        'media' => '\\Gallery\\Controllers\\cms_media',
        'thumb' => '\\Gallery\\Controllers\\cms_thumb',
        'public_media' => '\\Gallery\\Controllers\\cms_public_media',
        'public_thumb' => '\\Gallery\\Controllers\\cms_public_thumb',
        'thumbnail_warmup' => '\\Gallery\\Controllers\\cms_thumbnail_warmup',
        'site_maintenance_cron' => '\\Gallery\\Controllers\\cms_site_maintenance_cron',
        'gallery_cover_asset' => '\\Gallery\\Controllers\\cms_gallery_cover_asset',
        'gallery_branding_asset' => '\\Gallery\\Controllers\\cms_gallery_branding_asset',
        'theme_background_asset' => '\\Gallery\\Controllers\\cms_theme_background_asset',
        'theme_branding_asset' => '\\Gallery\\Controllers\\cms_theme_branding_asset',
        'favicon_asset' => '\\Gallery\\Controllers\\cms_favicon_asset',
        'vote' => '\\Gallery\\Controllers\\cms_vote',
        'theme_css' => '\\Gallery\\Controllers\\cms_theme_css',
        'browser_i18n' => '\\Gallery\\Controllers\\cms_browser_i18n',
        'admin_browser_i18n' => '\\Gallery\\Controllers\\cms_browser_i18n',
        'viewer_login' => '\\Gallery\\Controllers\\cms_viewer_login',
        'viewer_register' => '\\Gallery\\Controllers\\cms_viewer_register',
        'viewer_resend_verification' => '\\Gallery\\Controllers\\cms_viewer_resend_verification',
        'viewer_first_login_password' => '\\Gallery\\Controllers\\cms_viewer_first_login_password',
        'viewer_logout' => '\\Gallery\\Controllers\\cms_viewer_logout',
        'viewer_invite' => '\\Gallery\\Controllers\\cms_viewer_invite',
        'viewer_verify' => '\\Gallery\\Controllers\\cms_viewer_verify',
        'viewer_forgot_password' => '\\Gallery\\Controllers\\cms_viewer_forgot_password',
        'viewer_reset_password' => '\\Gallery\\Controllers\\cms_viewer_reset_password',
        'viewer_account' => '\\Gallery\\Controllers\\cms_viewer_account',
        'viewer_account_reauth' => '\\Gallery\\Controllers\\cms_viewer_account_reauth',
        'viewer_account_password' => '\\Gallery\\Controllers\\cms_viewer_account_password',
        'viewer_account_email' => '\\Gallery\\Controllers\\cms_viewer_account_email',
        'viewer_email_change_verify' => '\\Gallery\\Controllers\\cms_viewer_email_change_verify',
        'viewer_email_change_confirm' => '\\Gallery\\Controllers\\cms_viewer_email_change_confirm',
        'viewer_account_delete' => '\\Gallery\\Controllers\\cms_viewer_account_delete',
        'viewer_favourites' => '\\Gallery\\Controllers\\cms_viewer_favourites',
        'viewer_favourite' => '\\Gallery\\Controllers\\cms_viewer_favourite',
        'viewer_collections' => '\\Gallery\\Controllers\\cms_viewer_collections',
        'viewer_collection' => '\\Gallery\\Controllers\\cms_viewer_collection',
        'viewer_collection_rename' => '\\Gallery\\Controllers\\cms_viewer_collection_rename',
        'viewer_collection_delete' => '\\Gallery\\Controllers\\cms_viewer_collection_delete',
        'viewer_collection_item_add' => '\\Gallery\\Controllers\\cms_viewer_collection_item_add',
        'viewer_collection_item_remove' => '\\Gallery\\Controllers\\cms_viewer_collection_item_remove',
        'viewer_collection_reorder' => '\\Gallery\\Controllers\\cms_viewer_collection_reorder',
        'viewer_collection_share_replace' => '\\Gallery\\Controllers\\cms_viewer_collection_share_replace',
        'viewer_collection_share_revoke' => '\\Gallery\\Controllers\\cms_viewer_collection_share_revoke',
        'viewer_collection_share_exchange' => '\\Gallery\\Controllers\\cms_viewer_collection_share_exchange',
        'viewer_collection_shared' => '\\Gallery\\Controllers\\cms_viewer_collection_shared',
        'gallery_map_data' => '\\Gallery\\Controllers\\cms_gallery_map_data',
        'gallery_lightbox_data' => '\\Gallery\\Controllers\\cms_gallery_lightbox_data',
        'smart_gallery_lightbox_data' => '\\Gallery\\Controllers\\cms_smart_gallery_lightbox_data',
        'public_search' => '\\Gallery\\Controllers\\cms_public_search',
        'navdata_lookup' => '\\Gallery\\Controllers\\cms_navdata_lookup',
        'picture_manager_move' => '\\Gallery\\Controllers\\cms_picture_manager_move',
        'picture_manager_copy' => '\\Gallery\\Controllers\\cms_picture_manager_copy',
        'picture_manager_create_gallery' => '\\Gallery\\Controllers\\cms_picture_manager_create_gallery',
        'picture_manager_download_selection' => '\\Gallery\\Controllers\\cms_picture_manager_download_selection',
        'download_gallery' => '\\Gallery\\Controllers\\cms_download_gallery',
        'download_smart_gallery' => '\\Gallery\\Controllers\\cms_download_smart_gallery',
        'download_all' => '\\Gallery\\Controllers\\cms_download_all',
        'admin' => '\\Gallery\\Controllers\\cms_admin',
        'admin_dashboard_maintenance' => '\\Gallery\\Controllers\\cms_admin_dashboard_maintenance',
        'admin_dashboard_maintenance_client_log' => '\\Gallery\\Controllers\\cms_admin_dashboard_maintenance_client_log',
        'admin_login' => '\\Gallery\\Controllers\\cms_admin_login',
        'admin_forgot_password' => '\\Gallery\\Controllers\\cms_admin_forgot_password',
        'admin_reset_password' => '\\Gallery\\Controllers\\cms_admin_reset_password',
        'admin_google_start' => '\\Gallery\\Controllers\\cms_admin_google_start',
        'admin_google_callback' => '\\Gallery\\Controllers\\cms_admin_google_callback',
        'admin_logout' => '\\Gallery\\Controllers\\cms_admin_logout',
        'admin_theme' => '\\Gallery\\Controllers\\cms_admin_theme',
        'admin_settings' => '\\Gallery\\Controllers\\cms_admin_settings',
        'admin_account' => '\\Gallery\\Controllers\\cms_admin_account',
        'admin_viewer_invitations' => '\\Gallery\\Controllers\\cms_admin_viewer_invitations',
        'admin_update' => '\\Gallery\\Controllers\\cms_admin_update',
        'admin_diagnostics' => '\\Gallery\\Controllers\\cms_admin_diagnostics',
        'admin_features' => '\\Gallery\\Controllers\\cms_admin_features',
        'admin_reset' => '\\Gallery\\Controllers\\cms_admin_reset',
        'admin_devmode' => '\\Gallery\\Controllers\\cms_admin_devmode',
        'admin_url_rewrite' => '\\Gallery\\Controllers\\cms_admin_url_rewrite',
        'admin_storage_statistics' => '\\Gallery\\Controllers\\cms_admin_storage_statistics',
        'admin_storage_statistics_update' => '\\Gallery\\Controllers\\cms_admin_storage_statistics_update',
        'admin_gallery_report' => '\\Gallery\\Controllers\\cms_admin_gallery_report',
        'admin_gallery_report_generate' => '\\Gallery\\Controllers\\cms_admin_gallery_report_generate',
        'admin_gallery_benchmark_start' => '\\Gallery\\Controllers\\cms_admin_gallery_benchmark_start',
        'admin_gallery_benchmark_browser' => '\\Gallery\\Controllers\\cms_admin_gallery_benchmark_browser',
        'admin_gallery_benchmark_status' => '\\Gallery\\Controllers\\cms_admin_gallery_benchmark_status',
        'admin_gallery_benchmark_probe' => '\\Gallery\\Controllers\\cms_admin_gallery_benchmark_probe',
        'admin_gallery_benchmark_download' => '\\Gallery\\Controllers\\cms_admin_gallery_benchmark_download',
        'admin_test_run_start' => '\\Gallery\\Controllers\\cms_admin_test_run_start',
        'admin_test_run_probe' => '\\Gallery\\Controllers\\cms_admin_test_run_probe',
        'admin_test_run_finish' => '\\Gallery\\Controllers\\cms_admin_test_run_finish',
        'admin_test_run_finalize' => '\\Gallery\\Controllers\\cms_admin_test_run_finalize',
        'admin_test_run_download' => '\\Gallery\\Controllers\\cms_admin_test_run_download',
        'admin_database_usage_recompute' => '\\Gallery\\Controllers\\cms_admin_database_usage_recompute',
        'admin_database_maintenance_inspect' => '\\Gallery\\Controllers\\cms_admin_database_maintenance_inspect',
        'admin_database_maintenance_cleanup' => '\\Gallery\\Controllers\\cms_admin_database_maintenance_cleanup',
        'admin_database_maintenance_repair' => '\\Gallery\\Controllers\\cms_admin_database_maintenance_repair',
        'admin_database_maintenance_analyze' => '\\Gallery\\Controllers\\cms_admin_database_maintenance_analyze',
        'admin_database_maintenance_optimize' => '\\Gallery\\Controllers\\cms_admin_database_maintenance_optimize',
        'admin_public_search_settings' => '\\Gallery\\Controllers\\cms_admin_public_search_settings',
        'admin_exif_gps_settings' => '\\Gallery\\Controllers\\cms_admin_exif_gps_settings',
        'admin_seo_guard_settings' => '\\Gallery\\Controllers\\cms_admin_seo_guard_settings',
        'admin_site_maintenance_settings' => '\\Gallery\\Controllers\\cms_admin_site_maintenance_settings',
        'admin_discover' => '\\Gallery\\Controllers\\cms_admin_discover',
        'admin_import' => '\\Gallery\\Controllers\\cms_admin_import',
        'admin_new_gallery' => '\\Gallery\\Controllers\\cms_admin_new_gallery',
        'admin_upload' => '\\Gallery\\Controllers\\cms_admin_upload',
        'admin_upload_settings' => '\\Gallery\\Controllers\\cms_admin_upload_settings',
        'admin_upload_browser_batch' => '\\Gallery\\Controllers\\cms_admin_upload_browser_batch',
        'admin_upload_automation_token' => '\\Gallery\\Controllers\\cms_admin_upload_automation_token',
        'admin_mobile_uploads' => '\\Gallery\\Controllers\\cms_admin_mobile_uploads',
        'mobile_webdav' => '\\Gallery\\Controllers\\cms_mobile_webdav',
        'upload_automation_upload' => '\\Gallery\\Controllers\\cms_upload_automation_upload',
        'admin_api_manager' => '\\Gallery\\Controllers\\cms_admin_api_manager',
        'gallery_migration_manifest' => '\\Gallery\\Controllers\\cms_gallery_migration_manifest',
        'gallery_migration_asset' => '\\Gallery\\Controllers\\cms_gallery_migration_asset',
        'gallery_migration_receive_manifest' => '\\Gallery\\Controllers\\cms_gallery_migration_receive_manifest',
        'gallery_migration_receive_asset' => '\\Gallery\\Controllers\\cms_gallery_migration_receive_asset',
        'gallery_migration_receive_complete' => '\\Gallery\\Controllers\\cms_gallery_migration_receive_complete',
        'gallery_migration_receive_status' => '\\Gallery\\Controllers\\cms_gallery_migration_receive_status',
        'admin_gallery_migration' => '\\Gallery\\Controllers\\cms_admin_gallery_migration',
        'admin_media_renamer' => '\\Gallery\\Controllers\\cms_admin_media_renamer',
        'admin_gallery_dates' => '\\Gallery\\Controllers\\cms_admin_gallery_dates',
        'admin_gallery_date_suggestion' => '\\Gallery\\Controllers\\cms_admin_gallery_date_suggestion',
        'admin_duplicate_photos' => '\\Gallery\\Controllers\\cms_admin_duplicate_photos',
        'admin_bulk_galleries' => '\\Gallery\\Controllers\\cms_admin_bulk_galleries',
        'admin_tags' => '\\Gallery\\Controllers\\cms_admin_tags',
        'admin_smart_galleries' => '\\Gallery\\Controllers\\cms_admin_smart_galleries',
        'admin_run_migrations' => '\\Gallery\\Controllers\\cms_admin_run_migrations',
        'admin_update_navdata' => '\\Gallery\\Controllers\\cms_admin_update_navdata',
        'admin_navdata' => '\\Gallery\\Controllers\\cms_admin_navdata',
        'admin_check_thumbnail_maintenance' => '\\Gallery\\Controllers\\cms_admin_check_thumbnail_maintenance',
        'admin_create_thumbnails' => '\\Gallery\\Controllers\\cms_admin_create_thumbnails',
        'admin_thumbnail_browser_source_chunk' => '\\Gallery\\Controllers\\cms_admin_thumbnail_browser_source_chunk',
        'admin_thumbnail_browser_upload_batch' => '\\Gallery\\Controllers\\cms_admin_thumbnail_browser_upload_batch',
        'admin_thumbnail_compatibility_settings' => '\\Gallery\\Controllers\\cms_admin_thumbnail_compatibility_settings',
        'admin_delete_legacy_jpg_thumbnails' => '\\Gallery\\Controllers\\cms_admin_delete_legacy_jpg_thumbnails',
        'admin_delete_thumbnails' => '\\Gallery\\Controllers\\cms_admin_delete_thumbnails',
        'admin_dismiss_thumbnail_notice' => '\\Gallery\\Controllers\\cms_admin_dismiss_thumbnail_notice',
        'admin_regenerate_paths' => '\\Gallery\\Controllers\\cms_admin_regenerate_paths',
        'admin_save_gallery_collapse' => '\\Gallery\\Controllers\\cms_admin_save_gallery_collapse',
        'admin_reorder_galleries' => '\\Gallery\\Controllers\\cms_admin_reorder_galleries',
        'admin_reorder_public_galleries' => '\\Gallery\\Controllers\\cms_admin_reorder_public_galleries',
        'admin_sort_public_subgalleries_by_date' => '\\Gallery\\Controllers\\cms_admin_sort_public_subgalleries_by_date',
        'admin_scan_images' => '\\Gallery\\Controllers\\cms_admin_scan_images',
        'admin_simbrief_description' => '\\Gallery\\Controllers\\cms_admin_simbrief_description',
        'admin_openai_text_assist' => '\\Gallery\\Controllers\\cms_admin_openai_text_assist',
        'admin_integrity' => '\\Gallery\\Controllers\\cms_admin_integrity',
        'admin_logs' => '\\Gallery\\Controllers\\cms_admin_logs',
        'admin_log_group_members' => '\\Gallery\\Controllers\\cms_admin_log_group_members',
        'admin_log_update' => '\\Gallery\\Controllers\\cms_admin_log_update',
        'admin_log_export' => '\\Gallery\\Controllers\\cms_admin_log_export',
        'admin_logs_export_zip' => '\\Gallery\\Controllers\\cms_admin_logs_export_zip',
        'admin_log_archive_maintenance' => '\\Gallery\\Controllers\\cms_admin_log_archive_maintenance',
        'admin_log_archive_view' => '\\Gallery\\Controllers\\cms_admin_log_archive_view',
        'admin_log_archive_download' => '\\Gallery\\Controllers\\cms_admin_log_archive_download',
        'admin_telemetry' => '\\Gallery\\Controllers\\cms_admin_telemetry',
        'admin_telemetry_settings' => '\\Gallery\\Controllers\\cms_admin_telemetry_settings',
        'admin_telemetry_maintenance' => '\\Gallery\\Controllers\\cms_admin_telemetry_maintenance',
        'admin_telemetry_export' => '\\Gallery\\Controllers\\cms_admin_telemetry_export',
        'telemetry_ingest' => '\\Gallery\\Controllers\\cms_telemetry_ingest',
        'usage_collect' => '\\Gallery\\Controllers\\cms_telemetry_ingest',
        'admin_edit_gallery' => '\\Gallery\\Controllers\\cms_admin_edit_gallery',
        'admin_metadata_organizer_preview_batch' => '\\Gallery\\Controllers\\cms_admin_metadata_organizer_preview_batch',
        'admin_metadata_organizer_apply_date_plan_batch' => '\\Gallery\\Controllers\\cms_admin_metadata_organizer_apply_date_plan_batch',
        'admin_bulk_images' => '\\Gallery\\Controllers\\cms_admin_bulk_images',
        'admin_reorder_images' => '\\Gallery\\Controllers\\cms_admin_reorder_images',
        'admin_edit_image' => '\\Gallery\\Controllers\\cms_admin_edit_image',
        'admin_public_update_gallery' => '\\Gallery\\Controllers\\cms_admin_public_update_gallery',
        'admin_public_update_image' => '\\Gallery\\Controllers\\cms_admin_public_update_image',
        'setup' => '\\Gallery\\Controllers\\cms_setup',
    ];

    if (function_exists('Gallery\\Services\\feature_flag_route_enabled') && !feature_flag_route_enabled($page)) {
        feature_flag_render_disabled_route($page);
        return;
    }

    // Variable $handler stores this steps working value.
    $handler = $routes[$page] ?? '\\Gallery\\Controllers\\cms_not_found';
    try {
        // Verify access/privacy policy before a sensitive controller can emit partial HTML, metadata, archives, or media bytes.
        if (in_array($page, ['home', 'gallery', 'smart_gallery', 'gallery_access', 'share', 'tag', 'sitemap', 'picture_game', 'media', 'thumb', 'public_media', 'public_thumb', 'thumbnail_warmup', 'gallery_cover_asset', 'gallery_branding_asset', 'vote', 'gallery_map_data', 'gallery_lightbox_data', 'smart_gallery_lightbox_data', 'public_search', 'download_gallery', 'download_smart_gallery'], true)) {
            gallery_visibility_assert_public_policy_available();
            gallery_access_assert_public_policy_available();
            nsfw_guard_assert_public_policy_available();
        }
        $handler();
    } catch (PublicSchemaPolicyUnavailableException $exception) {
        cms_public_schema_unavailable($page, $exception->feature(), $exception->schemaState(), $exception->errorCode());
    }

}
