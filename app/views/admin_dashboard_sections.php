<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_dashboard_sections.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders reusable Admin dashboard section groups from the dashboard model.
 *
 * Responsibilities:
 *   - Keep the dashboard overview focused on status and primary work
 *   - Group maintenance tools into reusable, logical subtab panels
 *   - Avoid duplicating the same operational controls in multiple dashboard areas
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
 *   2026-06-08
 */

declare(strict_types=1);

/**
 * Return true when an optional dashboard feature should be rendered.
 */
function view_admin_dashboard_feature_enabled(string $featureKey): bool
{
    return !function_exists('feature_flag_enabled') || feature_flag_enabled($featureKey);
}

/**
 * Return a safe boolean value from the dashboard model.
 *
 * @param array<string, mixed> $model
 */
function view_admin_dashboard_bool(array $model, string $key): bool
{
    return !empty($model[$key]);
}

/**
 * Return a safe integer value from the dashboard model.
 *
 * @param array<string, mixed> $model
 */
function view_admin_dashboard_int(array $model, string $key, int $fallback = 0): int
{
    return (int) ($model[$key] ?? $fallback);
}

/**
 * Return a safe array value from the dashboard model.
 *
 * @param array<string, mixed> $model
 * @return array<string, mixed>
 */
function view_admin_dashboard_array(array $model, string $key): array
{
    return is_array($model[$key] ?? null) ? $model[$key] : [];
}

/**
 * Render the focused Overview tab.
 *
 * @param array<string, mixed> $model
 */
function view_render_admin_dashboard_overview_panel(array $model): void
{
    $migrationPending = view_admin_dashboard_bool($model, 'migration_pending');
    $thumbnailSummary = view_admin_dashboard_array($model, 'thumbnail_summary');

    view_render_admin_tab_intro([
        'kicker' => t('admin.dashboard.overview_kicker', 'Overview'),
        'title' => t('admin.dashboard.overview_title', 'Admin at a glance'),
        'description' => t('admin.dashboard.overview_description', 'Status and primary entry points only. Detailed tools are grouped under Maintenance.'),
    ]);
    view_render_admin_dashboard_metric_grid($model);

    if ($migrationPending) {
        view_render_admin_migration_notice(t('admin.dashboard.migration_notice', 'Some admin features still need database migrations.'));
    }
    if (empty($thumbnailSummary['deferred'])) {
        view_render_admin_thumbnail_maintenance_notice($thumbnailSummary);
    }

    echo '<section class="admin-quick-panel">';
    view_render_admin_tab_intro([
        'kicker' => t('admin.dashboard.primary_work_kicker', 'Start'),
        'title' => t('admin.dashboard.primary_work_title', 'Primary work'),
        'description' => t('admin.dashboard.primary_work_hint', 'Use these shortcuts for normal gallery work. Settings and repair tools stay in Maintenance.'),
        'class' => 'admin-panel-heading',
    ]);
    echo '<div class="admin-action-grid">';
    view_render_admin_dashboard_manage_galleries_card();
    view_render_admin_dashboard_upload_card();
    view_render_admin_dashboard_discover_card();
    view_render_admin_dashboard_open_maintenance_card();
    echo '</div></section>';
    view_render_admin_design_spec_panel();
}

/**
 * Render summary metric cards for the dashboard overview.
 *
 * @param array<string, mixed> $model
 */
function view_render_admin_dashboard_metric_grid(array $model): void
{
    $galleries = is_array($model['galleries'] ?? null) ? $model['galleries'] : [];
    $thumbnailSummary = view_admin_dashboard_array($model, 'thumbnail_summary');
    $totalGalleries = view_admin_dashboard_int($model, 'total_galleries', count($galleries));
    $totalImages = view_admin_dashboard_int($model, 'total_images');
    $unpublishedGalleries = view_admin_dashboard_int($model, 'unpublished_galleries');
    $privateGalleries = view_admin_dashboard_int($model, 'private_galleries');
    $missingThumbnailVariants = view_admin_dashboard_int($model, 'missing_thumbnail_variants');
    $originalStorageLabel = (string) ($model['original_storage_label'] ?? '');
    $galleryDatabaseUsageLabel = (string) ($model['gallery_database_usage_label'] ?? '');
    $databaseUsageLabel = (string) ($model['database_usage_label'] ?? '');
    $databaseUsageAvailable = view_admin_dashboard_bool($model, 'database_usage_available');
    $migrationPending = view_admin_dashboard_bool($model, 'migration_pending');

    $thumbnailValue = !empty($thumbnailSummary['deferred']) ? t('admin.dashboard.metric_not_checked', 'Not checked') : (string) $missingThumbnailVariants;
    $thumbnailHelp = !empty($thumbnailSummary['deferred'])
        ? t('admin.dashboard.metric_thumbnail_check_deferred', 'Open thumbnail maintenance for an exact scan.')
        : (int) ($thumbnailSummary['images_scanned'] ?? 0) . ' ' . t('admin.dashboard.metric_images_sampled', 'images sampled');
    $galleryStorageHelp = e((int) $unpublishedGalleries . ' ' . t('admin.dashboard.metric_unpublished', 'unpublished') . ', ' . (int) $privateGalleries . ' ' . t('admin.dashboard.metric_private', 'private')) . '<br>' . e(t('admin.dashboard.metric_original_storage', 'Original files: {size}', ['size' => $originalStorageLabel]));
    if ($databaseUsageAvailable && $galleryDatabaseUsageLabel !== '') {
        $galleryStorageHelp .= '<br>' . e(t('admin.dashboard.metric_gallery_database_storage', 'Gallery DB: {size}', ['size' => $galleryDatabaseUsageLabel]));
    }
    if ($databaseUsageAvailable && $databaseUsageLabel !== '' && $databaseUsageLabel !== $galleryDatabaseUsageLabel) {
        $galleryStorageHelp .= '<br>' . e(t('admin.dashboard.metric_total_database_storage', 'Total DB: {size}', ['size' => $databaseUsageLabel]));
    }
    $galleryStorageHelp .= '<br><a class="admin-metric-inline-link" href="' . e(url_for('admin_storage_statistics')) . '">' . e(t('admin.storage.open_details', 'Storage details')) . '</a>';
    view_render_admin_metric_grid([
        [
            'label' => t('admin.dashboard.metric_galleries', 'Galleries'),
            'value' => (string) $totalGalleries,
            'help_html' => $galleryStorageHelp,
            'state' => $privateGalleries > 0 || $unpublishedGalleries > 0 ? 'care' : 'ready',
        ],
        [
            'label' => t('admin.dashboard.metric_top_level_images', 'Top-level images'),
            'value' => (string) $totalImages,
            'help' => t('admin.dashboard.metric_imported_images_hint', 'Imported images shown in gallery lists'),
            'state' => 'neutral',
        ],
        [
            'label' => t('admin.dashboard.metric_thumbnail_gaps', 'Thumbnail gaps'),
            'value' => $thumbnailValue,
            'help' => $thumbnailHelp,
            'state' => $missingThumbnailVariants > 0 ? 'care' : 'ready',
        ],
        [
            'label' => t('admin.dashboard.metric_system_state', 'System state'),
            'value' => $migrationPending ? t('admin.dashboard.badge_action', 'Action') : t('admin.dashboard.state_ready', 'Ready'),
            'help' => $migrationPending ? t('admin.dashboard.state_migration_pending', 'Database migration pending') : t('admin.dashboard.state_no_migration_warning', 'No migration warning'),
            'state' => $migrationPending ? 'care' : 'ready',
        ],
    ], 'admin-metric-grid', t('admin.dashboard.admin_summary', 'Admin summary'));
}

/**
 * Render the overview card that opens the gallery tree.
 */
function view_render_admin_dashboard_manage_galleries_card(): void
{
    echo '<article class="admin-action-card"><strong>' . e(t('admin.dashboard.manage_galleries_title', 'Manage galleries')) . '</strong><span>' . e(t('admin.dashboard.manage_galleries_hint', 'Open the gallery tree for visibility changes, ordering, bulk actions, and per-gallery editing.')) . '</span><div class="nav"><a class="button secondary" href="' . e(url_for('admin') . '#admin-tab-galleries') . '">' . e(t('admin.dashboard.open_gallery_tree', 'Open gallery tree')) . '</a></div></article>';
}

/**
 * Render the overview card for normal gallery creation and uploads.
 */
function view_render_admin_dashboard_upload_card(): void
{
    echo '<article class="admin-action-card"><strong>' . e(t('admin.dashboard.add_content_title', 'Add content')) . '</strong><span>' . e(t('admin.dashboard.add_content_hint', 'Create an empty gallery or upload photos through the existing upload workflow.')) . '</span><div class="nav"><a class="button secondary" href="' . e(url_for('admin_new_gallery')) . '">' . e(t('admin.dashboard.create_empty_gallery', 'Create empty gallery')) . '</a><a class="button secondary" href="' . e(url_for('admin_upload')) . '">' . e(t('admin.dashboard.upload_photos', 'Upload photos')) . '</a></div></article>';
}

/**
 * Render the overview card for filesystem discovery.
 */
function view_render_admin_dashboard_discover_card(): void
{
    echo '<form method="post" action="' . e(url_for('admin_discover')) . '" class="admin-action-card" data-refresh-galleries-form>' . csrf_field();
    echo '<strong>' . e(t('admin.dashboard.discover_folders', 'Discover folders')) . '</strong><span>' . e(t('admin.dashboard.discover_folders_hint', 'Scan the galleries directory for new folders.')) . '</span><button type="submit">' . e(t('admin.dashboard.check_new_folders', 'Check for new gallery folders')) . '</button></form>';
}

/**
 * Render the overview card that points to grouped maintenance tools.
 */
function view_render_admin_dashboard_open_maintenance_card(): void
{
    echo '<article class="admin-action-card"><strong>' . e(t('admin.dashboard.open_maintenance_title', 'Maintenance tools')) . '</strong><span>' . e(t('admin.dashboard.open_maintenance_hint', 'Open grouped settings, cache tools, navdata, logs, updates, and diagnostics.')) . '</span><div class="nav"><a class="button secondary" href="' . e(url_for('admin') . '#admin-tab-maintenance') . '">' . e(t('admin.dashboard.open_maintenance', 'Open maintenance')) . '</a></div></article>';
}

/**
 * Render the Maintenance tab as nested, logical tool groups.
 *
 * @param array<string, mixed> $model
 */
function view_render_admin_dashboard_maintenance_panel(array $model): void
{
    $migrationPending = view_admin_dashboard_bool($model, 'migration_pending');
    $missingThumbnailVariants = view_admin_dashboard_int($model, 'missing_thumbnail_variants');

    $maintenanceSubtabs = [
        ['id' => 'admin-maintenance-content', 'label' => t('admin.dashboard.maintenance_content_display_tab', 'Content and display')],
        ['id' => 'admin-maintenance-media', 'label' => t('admin.dashboard.maintenance_media_cache_tab', 'Media and cache'), 'badge' => $missingThumbnailVariants > 0 ? (string) $missingThumbnailVariants : null],
        ['id' => 'admin-maintenance-navigation', 'label' => t('admin.dashboard.maintenance_navigation_tab', 'Maps and navdata')],
        ['id' => 'admin-maintenance-system', 'label' => t('admin.dashboard.maintenance_system_health_tab', 'System health'), 'badge' => $migrationPending ? t('admin.dashboard.badge_action', 'Action') : null],
    ];

    view_render_admin_tab_intro([
        'kicker' => t('admin.dashboard.maintenance_kicker', 'Maintenance'),
        'title' => t('admin.dashboard.system_tools', 'System tools'),
        'description' => t('admin.dashboard.system_tools_hint', 'Operational controls are grouped by purpose so display settings, media cache work, map data, and system health do not compete in one long list.'),
    ]);
    echo '<div class="admin-subtab-scope admin-dashboard-maintenance-scope" data-admin-subtab-scope>';
    view_render_admin_subtabs($maintenanceSubtabs, 'admin-maintenance-content', t('admin.dashboard.maintenance_subtabs_aria', 'Maintenance tool groups'));

    ob_start();
    view_render_admin_dashboard_content_display_tools($model);
    view_render_admin_subtab_panel('admin-maintenance-content', (string) ob_get_clean(), true);

    ob_start();
    view_render_admin_dashboard_media_tools($model);
    view_render_admin_subtab_panel('admin-maintenance-media', (string) ob_get_clean(), false);

    ob_start();
    view_render_admin_dashboard_navigation_tools($model);
    view_render_admin_subtab_panel('admin-maintenance-navigation', (string) ob_get_clean(), false);

    ob_start();
    view_render_admin_dashboard_system_tools($model);
    view_render_admin_subtab_panel('admin-maintenance-system', (string) ob_get_clean(), false);

    echo '</div>';
}

/**
 * Render maintenance tools that affect public display and metadata policy.
 *
 * @param array<string, mixed> $model
 */
function view_render_admin_dashboard_content_display_tools(array $model): void
{
    view_render_admin_tab_intro([
        'kicker' => t('admin.dashboard.content_display_kicker', 'Content policy'),
        'title' => t('admin.dashboard.content_display_title', 'Display, dates, and public URLs'),
        'description' => t('admin.dashboard.content_display_hint', 'Controls that change what visitors see or how galleries resolve public metadata.'),
        'class' => 'admin-dashboard-subtab-heading',
    ]);
    echo '<div class="admin-maintenance-grid">';
    if (view_admin_dashboard_feature_enabled('public_search')) {
        view_render_admin_dashboard_public_search_card('admin-maintenance-card');
    }
    if (view_admin_dashboard_bool($model, 'gps_map_override_ready')) {
        view_render_admin_exif_gps_defaults_card('admin-maintenance-card', view_admin_dashboard_bool($model, 'exif_gps_default_enabled'), view_admin_dashboard_int($model, 'exif_gps_override_count'));
    }
    if (view_admin_dashboard_bool($model, 'gallery_date_range_ready')) {
        view_render_admin_gallery_dates_card('admin-maintenance-card');
    }
    view_render_admin_dashboard_public_paths_card('admin-maintenance-card');
    view_render_admin_dashboard_seo_guard_card('admin-maintenance-card');
    view_render_admin_url_rewrite_card('admin-maintenance-card');
    echo '</div>';
}

/**
 * Render maintenance tools for generated media and archives.
 *
 * @param array<string, mixed> $model
 */
function view_render_admin_dashboard_media_tools(array $model): void
{
    view_render_admin_tab_intro([
        'kicker' => t('admin.dashboard.media_cache_kicker', 'Media'),
        'title' => t('admin.dashboard.media_cache_title', 'Generated files and archives'),
        'description' => t('admin.dashboard.media_cache_hint', 'Thumbnail cache, archive downloads, and physical filename maintenance.'),
        'class' => 'admin-dashboard-subtab-heading',
    ]);
    echo '<div class="admin-maintenance-grid">';
    view_render_admin_dashboard_thumbnail_card($model, 'admin-maintenance-card');
    view_render_admin_dashboard_site_maintenance_card($model, 'admin-maintenance-card');
    if (view_admin_dashboard_feature_enabled('downloads')) {
        view_render_admin_dashboard_archive_card('admin-maintenance-card');
    }
    if (view_admin_dashboard_feature_enabled('media_renamer')) {
        view_render_admin_dashboard_media_renamer_card('admin-maintenance-card');
    }
    echo '</div>';
}

/**
 * Render maintenance tools for maps and flight navigation data.
 *
 * @param array<string, mixed> $model
 */
function view_render_admin_dashboard_navigation_tools(array $model): void
{
    view_render_admin_tab_intro([
        'kicker' => t('admin.dashboard.navigation_kicker', 'Maps'),
        'title' => t('admin.dashboard.navigation_title', 'GPS maps and navdata'),
        'description' => t('admin.dashboard.navigation_hint', 'Flight-map lookup data lives here. Per-gallery EXIF map defaults are grouped under Content and display.'),
        'class' => 'admin-dashboard-subtab-heading',
    ]);
    echo '<div class="admin-maintenance-grid">';
    if (view_admin_dashboard_feature_enabled('navigation_data')) {
        view_render_admin_navdata_maintenance_card(view_admin_dashboard_bool($model, 'flight_navdata_ready'), view_admin_dashboard_array($model, 'flight_navdata_status'));
    } else {
        echo '<article class="admin-maintenance-card"><strong>' . e(t('admin.dashboard.navigation_data_hidden', 'Navigation data')) . '</strong><span>' . e(t('admin.dashboard.navigation_data_hidden_hint', 'Navigation data maintenance is hidden by Admin > Features.')) . '</span><a class="button secondary" href="' . e(url_for('admin_features')) . '">' . e(t('admin.dashboard.open_features', 'Open features')) . '</a></article>';
    }
    echo '</div>';
}

/**
 * Render maintenance tools for application health and diagnostics.
 *
 * @param array<string, mixed> $model
 */
function view_render_admin_dashboard_system_tools(array $model): void
{
    $updatePending = view_admin_dashboard_bool($model, 'update_pending');
    $updateButtonClass = (string) ($model['update_button_class'] ?? 'button secondary');
    $updateLabel = (string) ($model['update_label'] ?? t('admin.menu.updates', 'Updates'));
    $migrationPending = view_admin_dashboard_bool($model, 'migration_pending');

    view_render_admin_tab_intro([
        'kicker' => t('admin.dashboard.system_health_kicker', 'System'),
        'title' => t('admin.dashboard.system_health_title', 'Logs, updates, and diagnostics'),
        'description' => t('admin.dashboard.system_health_hint', 'Operational status, deployment checks, feature visibility, and developer diagnostics.'),
        'class' => 'admin-dashboard-subtab-heading',
    ]);
    echo '<div class="admin-maintenance-grid">';
    echo '<article class="admin-maintenance-card"><strong>' . e(t('admin.dashboard.logs', 'Logs')) . '</strong><span>' . e(t('admin.dashboard.logs_hint', 'Review operational events, failures, and workflow status.')) . '</span><a class="button secondary" href="' . e(url_for('admin_logs')) . '">' . e(t('admin.dashboard.open_logs', 'Open logs')) . '</a></article>';
    if (view_admin_dashboard_feature_enabled('telemetry')) {
        echo '<article class="admin-maintenance-card"><strong>' . e(t('admin.dashboard.telemetry', 'Telemetry')) . '</strong><span>' . e(t('admin.dashboard.telemetry_hint', 'Inspect anonymous usage telemetry without collecting personal data.')) . '</span><a class="button secondary" href="' . e(url_for('admin_telemetry')) . '">' . e(t('admin.dashboard.open_telemetry', 'Open telemetry')) . '</a></article>';
    }
    echo '<article class="admin-maintenance-card"><strong>' . e(t('admin.dashboard.integrity', 'Integrity')) . '</strong><span>' . e(t('admin.dashboard.integrity_hint', 'Check core files and deployment health.')) . '</span><a class="button secondary" href="' . e(url_for('admin_integrity')) . '">' . e(t('admin.dashboard.run_integrity_check', 'Run integrity check')) . '</a></article>';
    echo '<article class="admin-maintenance-card"><strong>' . e(t('admin.dashboard.updates', 'Updates')) . '</strong><span>' . e(t('admin.dashboard.updates_hint', 'Check and apply project updates.')) . '</span><a class="' . e($updateButtonClass) . '" href="' . e(url_for('admin_update')) . '">' . e($updateLabel) . '</a></article>';
    echo '<article class="admin-maintenance-card"><strong>' . e(t('admin.dashboard.features', 'Features')) . '</strong><span>' . e(t('admin.dashboard.features_hint', 'Enable or hide unfinished, optional, or site-specific feature areas.')) . '</span><a class="button secondary" href="' . e(url_for('admin_features')) . '">' . e(t('admin.dashboard.open_features', 'Open features')) . '</a></article>';
    view_render_admin_devmode_card('admin-maintenance-card');
    if ($migrationPending) {
        view_render_admin_dashboard_migration_card('admin-maintenance-card');
    }
    if (!$updatePending && !$migrationPending) {
        echo '<article class="admin-maintenance-card"><strong>' . e(t('admin.dashboard.system_ready_title', 'System ready')) . '</strong><span>' . e(t('admin.dashboard.system_ready_hint', 'No update or migration warning is currently active on the dashboard.')) . '</span></article>';
    }
    echo '</div>';
}

/**
 * Render the public search settings card.
 */
function view_render_admin_dashboard_public_search_card(string $className): void
{
    echo '<form method="post" action="' . e(url_for('admin_public_search_settings')) . '" class="' . e($className) . ' admin-public-search-settings">' . csrf_field();
    echo '<strong>' . e(t('admin.dashboard.public_search_title', 'Public search')) . '</strong><span>' . e(t('admin.dashboard.public_search_hint', 'Show a thin live search bar above the public front-page and gallery content. Gallery pages include a visitor checkbox to limit results to that gallery and its subgalleries.')) . '</span>';
    echo '<label class="admin-compact-toggle"><input type="checkbox" name="public_home_search_enabled" value="1"' . (public_home_search_enabled() ? ' checked' : '') . '> <span>' . e(t('admin.dashboard.public_search_enable', 'Enable public search bar')) . '</span></label>';
    echo '<button type="submit" class="secondary">' . e(t('admin.dashboard.save_public_search', 'Save search setting')) . '</button></form>';
}

/**
 * Render the clean public-path regeneration card.
 */
function view_render_admin_dashboard_public_paths_card(string $className): void
{
    echo '<form method="post" action="' . e(url_for('admin_regenerate_paths')) . '" class="' . e($className) . '" onsubmit="return confirm(\'' . e(t('admin.dashboard.confirm_regenerate_paths', 'Regenerate clean public URLs for all galleries and images?')) . '\');">' . csrf_field();
    echo '<strong>' . e(t('admin.dashboard.public_paths', 'Public paths')) . '</strong><span>' . e(t('admin.dashboard.public_paths_hint', 'Regenerate clean public URLs for galleries and images.')) . '</span><button type="submit" class="secondary">' . e(t('admin.dashboard.regenerate_paths', 'Regenerate paths')) . '</button></form>';
}

/**
 * Render public crawler safety settings.
 */
function view_render_admin_dashboard_seo_guard_card(string $className): void
{
    $status = function_exists('seo_request_guard_status') ? seo_request_guard_status() : [
        'enabled' => true,
        'logging_enabled' => true,
        'log_day' => '',
        'log_count' => 0,
        'log_limit' => 25,
    ];
    $logDay = trim((string) ($status['log_day'] ?? ''));
    $logCount = max(0, (int) ($status['log_count'] ?? 0));
    $logLimit = max(1, (int) ($status['log_limit'] ?? 25));
    $logStatus = $logDay !== ''
        ? t('admin.dashboard.seo_guard_log_status', 'Today logged {count}/{limit} sampled rejection event(s).', ['count' => (string) min($logCount, $logLimit), 'limit' => (string) $logLimit])
        : t('admin.dashboard.seo_guard_log_status_empty', 'No sampled rejection event has been logged today.');

    echo '<form method="post" action="' . e(url_for('admin_seo_guard_settings')) . '" class="' . e($className) . ' admin-seo-guard-settings">' . csrf_field();
    echo '<strong>' . e(t('admin.dashboard.seo_guard_title', 'Crawler safety')) . '</strong>';
    echo '<span>' . e(t('admin.dashboard.seo_guard_hint', 'Reject public URLs with unknown query parameters before they render as duplicate gallery pages. Suspicious requests return 404 with X-Robots-Tag: noindex, nofollow.')) . '</span>';
    echo '<label class="admin-compact-toggle"><input type="checkbox" name="seo_request_guard_enabled" value="1"' . (!empty($status['enabled']) ? ' checked' : '') . '> <span>' . e(t('admin.dashboard.seo_guard_enable', 'Reject suspicious public query strings')) . '</span></label>';
    echo '<label class="admin-compact-toggle"><input type="checkbox" name="seo_request_guard_logging_enabled" value="1"' . (!empty($status['logging_enabled']) ? ' checked' : '') . '> <span>' . e(t('admin.dashboard.seo_guard_logging_enable', 'Log sampled rejected requests')) . '</span></label>';
    echo '<small class="muted">' . e($logStatus) . '</small>';
    echo '<button type="submit" class="secondary">' . e(t('admin.dashboard.save_seo_guard', 'Save crawler safety')) . '</button></form>';
}

/**
 * Render thumbnail cache actions.
 *
 * @param array<string, mixed> $model
 */
function view_render_admin_dashboard_thumbnail_card(array $model, string $className): void
{
    $thumbnailSummary = view_admin_dashboard_array($model, 'thumbnail_summary');
    $missingThumbnailVariants = view_admin_dashboard_int($model, 'missing_thumbnail_variants');
    $compatibilityMode = function_exists('thumbnail_compatibility_mode') ? thumbnail_compatibility_mode() : 'modern';

    echo '<article class="' . e($className) . ' admin-thumbnail-maintenance-card">';
    echo '<strong>' . e(t('admin.dashboard.thumbnail_maintenance', 'Thumbnail maintenance')) . '</strong>';
    if (!empty($thumbnailSummary['deferred'])) {
        echo '<span>' . e(t('admin.dashboard.thumbnail_check_deferred', 'Thumbnail status has not been scanned yet on this login. Use Create all thumbnails or the dedicated thumbnail tools when you need a full check.')) . '</span>';
    } else {
        echo '<span>' . (int) $missingThumbnailVariants . ' ' . e(t('admin.dashboard.missing_stale_variants', 'missing or stale variant(s) in the current sample.')) . '</span>';
    }

    echo '<form method="post" action="' . e(url_for('admin_thumbnail_compatibility_settings')) . '" class="admin-thumbnail-compatibility-form">' . csrf_field();
    echo '<span class="admin-thumbnail-card-subtitle">' . e(t('admin.thumbnails.compatibility_title', 'Thumbnail compatibility mode')) . '</span>';
    echo '<label class="admin-compact-toggle"><input type="radio" name="thumbnail_compatibility_mode" value="modern"' . ($compatibilityMode === 'modern' ? ' checked' : '') . '> <span><strong>' . e(t('admin.thumbnails.compatibility_modern_short', 'Modern')) . '</strong> ' . e(t('admin.thumbnails.compatibility_modern_help', 'Generate WebP thumbnails only where the server can write WebP.')) . '</span></label>';
    echo '<label class="admin-compact-toggle"><input type="radio" name="thumbnail_compatibility_mode" value="legacy"' . ($compatibilityMode === 'legacy' ? ' checked' : '') . '> <span><strong>' . e(t('admin.thumbnails.compatibility_legacy_short', 'Legacy')) . '</strong> ' . e(t('admin.thumbnails.compatibility_legacy_help', 'Generate JPG fallback thumbnails plus WebP thumbnails.')) . '</span></label>';
    echo '<button type="submit" class="secondary">' . e(t('admin.thumbnails.save_compatibility_mode', 'Save thumbnail mode')) . '</button></form>';

    echo '<form method="post" action="' . e(url_for('admin_delete_legacy_jpg_thumbnails')) . '" class="admin-thumbnail-legacy-cleanup-form" data-delete-legacy-jpg-thumbnails-form>' . csrf_field();
    echo '<span>' . e(t('admin.thumbnails.legacy_cleanup_hint', 'Remove generated JPG thumbnails after switching to Modern mode. Original photos, WebP thumbnails, and DNG display masters are not touched.')) . '</span>';
    echo '<button type="submit" class="secondary danger" data-delete-legacy-jpg-thumbnails data-confirm-message="' . e(t('admin.thumbnails.legacy_cleanup_confirm', 'Delete generated legacy JPG thumbnails? Original photos and WebP files will be kept.')) . '">' . e(t('admin.thumbnails.delete_legacy_jpg_thumbnails', 'Remove legacy JPG thumbnails')) . '</button>';
    echo '</form>';

    echo '<form method="post" action="' . e(url_for('admin_create_thumbnails')) . '" class="admin-thumbnail-metadata-actions-form" data-refresh-thumbnail-metadata-form>' . csrf_field();
    echo '<span>' . e(t('admin.thumbnails.metadata_refresh_hint', 'Refresh the thumbnail database from existing thumbnail files. Wrong-ratio files are deleted and are not displayed.')) . '</span>';
    echo '<button type="button" class="secondary" data-refresh-thumbnail-metadata>' . e(t('admin.thumbnails.refresh_metadata', 'Refresh thumbnail database')) . '</button>';
    echo '</form>';

    echo '<form method="post" action="' . e(url_for('admin_delete_thumbnails')) . '" class="admin-thumbnail-cache-actions-form" data-delete-all-thumbnails-form>' . csrf_field();
    echo '<input type="hidden" name="confirmation_expected" value=""><input type="hidden" name="confirmation_typed" value="">';
    echo '<div class="nav"><button type="button" class="secondary" data-create-all-thumbnails>' . e(t('admin.dashboard.create_all_thumbnails', 'Create all thumbnails')) . '</button><button type="submit" class="secondary danger" data-delete-all-thumbnails data-confirm-words="archive,remove,clean,thumbs,purge,reset,delete,cache,media,confirm">' . e(t('admin.dashboard.delete_all_thumbnails', 'Delete all thumbnails')) . '</button></div></form>';
    echo '</article>';
}


/**
 * Render the scheduled site-maintenance settings card.
 *
 * @param array<string, mixed> $model
 */
function view_render_admin_dashboard_site_maintenance_card(array $model, string $className): void
{
    $status = view_admin_dashboard_array($model, 'site_maintenance_status');
    $state = is_array($status['state'] ?? null) ? $status['state'] : [];
    $lastResult = is_array($status['last_result'] ?? null) ? $status['last_result'] : [];
    $totals = is_array($state['totals'] ?? null) ? $state['totals'] : [];
    $lastStep = is_array($state['last_step_summary'] ?? null) ? $state['last_step_summary'] : [];
    $enabled = !empty($status['enabled']);
    $requestTriggerEnabled = !empty($status['request_trigger_enabled']);
    $utcTime = (string) ($status['utc_time'] ?? '00:00');
    $batchSize = (int) ($status['batch_size'] ?? 20);
    $timeBudget = (int) ($status['time_budget_seconds'] ?? 20);
    $windowHours = (string) ($status['window_hours_value'] ?? '3');
    $scheduledAtUtc = (string) ($status['scheduled_at_utc'] ?? '');
    $windowEndsAtUtc = (string) ($status['window_ends_at_utc'] ?? '');
    $withinWindow = !empty($status['within_window']);
    $totalSourceImages = max(0, (int) ($status['total_source_images'] ?? 0));
    $stateStatus = (string) ($state['status'] ?? '');
    $phase = (string) ($state['phase'] ?? '');
    $lastCompletedAt = (string) ($status['last_completed_at'] ?? '');
    $lastCompletedDate = (string) ($status['last_completed_date'] ?? '');
    $lastStepAt = (string) ($state['last_step_at'] ?? '');
    $errors = array_values(array_filter(array_map('strval', (array) ($totals['errors'] ?? []))));

    echo '<article class="' . e($className) . ' admin-site-maintenance-card">';
    echo '<strong>' . e(t('admin.site_maintenance.title', 'Scheduled site maintenance')) . '</strong>';
    echo '<p class="admin-site-maintenance-copy">' . e(t('admin.site_maintenance.hint', 'Daily maintenance scans the whole gallery after the configured UTC time. Valid thumbnails are reused. Only missing, stale, wrong-ratio, or metadata-missing variants are repaired.')) . '</p>';

    if ($stateStatus !== '') {
        echo '<div class="admin-site-maintenance-statusline"><strong>' . e(t('admin.site_maintenance.current_state', 'Current state')) . ':</strong> <span>' . e($stateStatus) . ($phase !== '' ? ' / ' . e($phase) : '') . '</span></div>';
        echo '<dl class="admin-site-maintenance-metrics">';
        echo '<div><dt>' . e(t('admin.site_maintenance.metric_checked', 'Checked')) . '</dt><dd>' . e((string) (int) ($totals['images_seen'] ?? 0)) . ' / ' . e($totalSourceImages > 0 ? (string) $totalSourceImages : '?') . '</dd></div>';
        echo '<div><dt>' . e(t('admin.site_maintenance.metric_repair_attempts', 'Repair attempts')) . '</dt><dd>' . e((string) (int) ($totals['images_processed'] ?? 0)) . '</dd></div>';
        echo '<div><dt>' . e(t('admin.site_maintenance.metric_created', 'Created')) . '</dt><dd>' . e((string) (int) ($totals['thumbs_created'] ?? 0)) . '</dd></div>';
        echo '<div><dt>' . e(t('admin.site_maintenance.metric_invalid_removed', 'Invalid removed')) . '</dt><dd>' . e((string) (int) ($totals['invalid_geometry_deleted'] ?? 0)) . '</dd></div>';
        echo '<div><dt>' . e(t('admin.site_maintenance.metric_deferred', 'Deferred')) . '</dt><dd>' . e((string) (int) ($totals['images_deferred'] ?? 0)) . '</dd></div>';
        echo '<div><dt>' . e(t('admin.site_maintenance.metric_failed', 'Failed')) . '</dt><dd>' . e((string) (int) ($totals['failed'] ?? 0)) . '</dd></div>';
        echo '</dl>';
        if ($lastStepAt !== '') {
            echo '<span class="admin-site-maintenance-note">' . e(t('admin.site_maintenance.last_step', 'Last safe slice: {time}, checked {checked}, repair attempts {repairs}, created {created}, deferred {deferred}.', [
                'time' => $lastStepAt . ' UTC',
                'checked' => (string) (int) ($lastStep['images_checked'] ?? 0),
                'repairs' => (string) (int) ($lastStep['repair_attempts'] ?? 0),
                'created' => (string) (int) ($lastStep['thumbnails_created'] ?? 0),
                'deferred' => (string) (int) ($lastStep['deferred_images'] ?? 0),
            ])) . '</span>';
        }
        if ($errors !== []) {
            echo '<span class="admin-site-maintenance-note is-warning">' . e(t('admin.site_maintenance.last_diagnostic', 'Latest diagnostic: {message}', ['message' => (string) end($errors)])) . '</span>';
        }
    } elseif ($lastCompletedAt !== '') {
        echo '<span><strong>' . e(t('admin.site_maintenance.last_completed', 'Last completed')) . ':</strong> ' . e($lastCompletedAt) . ' UTC</span>';
    } else {
        echo '<span>' . e(t('admin.site_maintenance.not_run_yet', 'No maintenance cycle has completed yet.')) . '</span>';
    }

    if ($lastCompletedDate !== '') {
        echo '<span class="admin-site-maintenance-note">' . e(t('admin.site_maintenance.last_completed_date', 'Last scheduled UTC date: {date}', ['date' => $lastCompletedDate])) . '</span>';
    }

    if (!empty($lastResult['busy'])) {
        echo '<span class="admin-site-maintenance-note is-warning">' . e(t('admin.site_maintenance.last_busy', 'The last maintenance call found another invocation already running.')) . '</span>';
    }

    if ($scheduledAtUtc !== '' && $windowEndsAtUtc !== '') {
        echo '<span class="admin-site-maintenance-note">' . e(t('admin.site_maintenance.window_summary', 'Active UTC window: {start} to {end}. Current window state: {state}.', [
            'start' => $scheduledAtUtc,
            'end' => $windowEndsAtUtc,
            'state' => $withinWindow ? t('admin.site_maintenance.window_active', 'active') : t('admin.site_maintenance.window_inactive', 'inactive'),
        ])) . '</span>';
    }

    echo '<form method="post" action="' . e(url_for('admin_site_maintenance_settings')) . '" class="admin-site-maintenance-settings-form">' . csrf_field();
    echo '<input type="hidden" name="site_maintenance_action" value="save">';
    echo '<label class="admin-compact-toggle"><input type="checkbox" name="site_maintenance_enabled" value="1"' . ($enabled ? ' checked' : '') . '> <span>' . e(t('admin.site_maintenance.enabled', 'Enable scheduled maintenance')) . '</span></label>';
    echo '<label class="admin-compact-toggle"><input type="checkbox" name="site_maintenance_request_trigger_enabled" value="1"' . ($requestTriggerEnabled ? ' checked' : '') . '> <span>' . e(t('admin.site_maintenance.request_trigger_enabled', 'Let normal page requests trigger due maintenance')) . '</span></label>';
    echo '<label><span>' . e(t('admin.site_maintenance.utc_time', 'Start time, UTC')) . '</span><input type="time" name="site_maintenance_utc_time" value="' . e($utcTime) . '"></label>';
    echo '<label><span>' . e(t('admin.site_maintenance.window_hours', 'Overall maintenance window, hours')) . '</span><input type="number" name="site_maintenance_window_hours" min="0.25" max="24" step="0.25" value="' . e($windowHours) . '"></label>';
    echo '<label><span>' . e(t('admin.site_maintenance.batch_size', 'Max images checked per internal batch')) . '</span><input type="number" name="site_maintenance_batch_size" min="1" max="50" value="' . $batchSize . '"></label>';
    echo '<label><span>' . e(t('admin.site_maintenance.time_budget', 'Time budget per call, seconds')) . '</span><input type="number" name="site_maintenance_time_budget_seconds" min="3" max="120" value="' . $timeBudget . '"></label>';
    echo '<button type="submit" class="secondary">' . e(t('admin.site_maintenance.save', 'Save maintenance settings')) . '</button>';
    echo '</form>';

    echo '<div class="admin-site-maintenance-cron">';
    echo '<span><strong>' . e(t('admin.site_maintenance.automatic_trigger', 'Automatic trigger')) . '</strong></span>';
    echo '<span>' . e(t('admin.site_maintenance.automatic_trigger_hint', 'When a normal public or Admin page is opened during the configured UTC window, the site starts one safe slice after the page response and then queues the next safe slice directly. Chained slices continue until the cycle finishes or the UTC window ends.')) . '</span>';
    echo '<span>' . e(t('admin.site_maintenance.external_cron_hint', 'For completely unattended execution on a site with no traffic, use the CLI command from hosting cron: {command}', [
        'command' => 'php ' . dirname(dirname(__DIR__)) . '/scripts/site_maintenance.php --quiet',
    ])) . '</span>';
    echo '</div>';

    echo '<form method="post" action="' . e(url_for('admin_site_maintenance_settings')) . '" class="admin-site-maintenance-actions">' . csrf_field();
    echo '<div class="nav">';
    echo '<button type="submit" name="site_maintenance_action" value="run_now" class="secondary">' . e(t('admin.site_maintenance.run_now', 'Run one safe check now')) . '</button>';
    echo '<button type="submit" name="site_maintenance_action" value="reset_state" class="secondary">' . e(t('admin.site_maintenance.reset_state', 'Reset interrupted state')) . '</button>';
    echo '<button type="submit" name="site_maintenance_action" value="rotate_token" class="secondary danger" onclick="return confirm(' . e(json_encode(t('admin.site_maintenance.rotate_confirm', 'Rotate the hidden web-cron token? Existing external web cron URLs will stop working until updated.'), JSON_UNESCAPED_UNICODE)) . ');">' . e(t('admin.site_maintenance.rotate_token', 'Rotate hidden web-cron token')) . '</button>';
    echo '</div>';
    echo '</form>';

    echo '</article>';
}


/**
 * Render the complete gallery archive card.
 */
function view_render_admin_dashboard_archive_card(string $className): void
{
    echo '<article class="' . e($className) . '"><strong>' . e(t('admin.dashboard.gallery_archive', 'Gallery archive')) . '</strong><span>' . e(t('admin.dashboard.gallery_archive_hint', 'Download a complete ZIP archive through the existing route.')) . '</span><a class="button secondary" href="' . e(url_for('download_all')) . '">' . e(t('admin.dashboard.download_all_galleries', 'Download all galleries')) . '</a></article>';
}

/**
 * Render the media renamer shortcut card.
 */
function view_render_admin_dashboard_media_renamer_card(string $className): void
{
    echo '<article class="' . e($className) . '"><strong>' . e(t('admin.dashboard.media_renamer_title', 'Media renamer')) . '</strong><span>' . e(t('admin.dashboard.media_renamer_hint', 'Preview and apply physical filename cleanup while keeping database rows and generated derivatives aligned.')) . '</span><a class="button secondary" href="' . e(url_for('admin_media_renamer')) . '">' . e(t('admin.dashboard.open_media_renamer', 'Open media renamer')) . '</a></article>';
}

/**
 * Render the pending database migration card.
 */
function view_render_admin_dashboard_migration_card(string $className): void
{
    echo '<form method="post" action="' . e(url_for('admin_run_migrations')) . '" class="' . e($className) . ' is-attention">' . csrf_field();
    echo '<strong>' . e(t('admin.dashboard.database_migrations', 'Database migrations')) . '</strong><span>' . e(t('admin.dashboard.pending_migrations_hint', 'Pending migrations must be applied before every admin feature is fully available.')) . '</span><button type="submit" class="button is-update-pending">' . e(t('admin.dashboard.run_database_migration', 'Run database migration')) . '</button></form>';
}
