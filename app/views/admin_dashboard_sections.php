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

namespace Gallery\Views;

use function Gallery\Core\csrf_field;
use function Gallery\Core\csrf_token;
use function Gallery\Core\e;
use function Gallery\Core\format_bytes;
use function Gallery\Core\url_for;
use function Gallery\Services\t;

/**
 * Return true when an optional dashboard feature should be rendered.
 *
 * @param array{feature_enabled?:array<string,bool>,...} $model Dashboard model
 *     with feature flags indexed by the dynamic capability key.
 * @param string $featureKey Feature identifier looked up in that map.
 * @return bool True when the condition matches.
 */
function view_admin_dashboard_feature_enabled(
    array $model,
    string $featureKey,
): bool {
    return !empty($model['feature_enabled'][$featureKey]);
}

/**
 * Return a safe boolean value from the dashboard model.
 *
 * @param array $model Model value.
 * @param string $key Lookup key.
 * @return bool True when the condition matches.
 */
function view_admin_dashboard_bool(array $model, string $key): bool
{
    return !empty($model[$key]);
}

/**
 * Return a safe integer value from the dashboard model.
 *
 * @param array<string,mixed> $model Heterogeneous dashboard model with dynamic
 *     string keys for counts and nested status records; the selected key must
 *     contain a value that can be cast to an integer.
 * @param string $key Dynamic dashboard key whose value is converted to int.
 * @param int $fallback Fallback value.
 * @return int Integer result for the caller.
 */
function view_admin_dashboard_int(
    array $model,
    string $key,
    int $fallback = 0,
): int {
    return (int) ($model[$key] ?? $fallback);
}

/**
 * Return a safe array value from the dashboard model.
 *
 * @param array $model Model value.
 * @param string $key Lookup key.
 * @return array<string,mixed> Model value or an empty array.
 */
function view_admin_dashboard_array(array $model, string $key): array
{
    return is_array($model[$key] ?? null) ? $model[$key] : [];
}

/**
 * Render the focused Overview tab.
 *
 * @param array<string,mixed> $model Dashboard totals, overview_loaded flag and prepared Settings/Maintenance links.
 * @return void Outputs compact actions and a calculated or deferred summary.
 */
function view_render_admin_dashboard_overview_panel(array $model): void
{
    $migrationPending = view_admin_dashboard_bool($model, 'migration_pending');
    if (
        !array_key_exists('overview_loaded', $model)
        || !empty($model['overview_loaded'])
    ) {
        view_render_admin_dashboard_summary($model);
    } else {
        view_render_admin_dashboard_deferred_read('overview');
    }

    if ($migrationPending) {
        view_render_admin_migration_notice(t(
            'admin.dashboard.migration_notice',
            'Some admin features still need database migrations.',
        ));
    }
    echo '<section class="admin-quick-panel">';
    echo
        '<h2>'
            . e(t('admin.dashboard.primary_work_title', 'Primary work'))
            . '</h2>';
    echo '<div class="admin-action-grid">';
    view_render_admin_dashboard_settings_card($model);
    view_render_admin_dashboard_manage_galleries_card();
    view_render_admin_dashboard_discover_card();
    view_render_admin_dashboard_open_maintenance_card($model);
    echo '</div></section>';
}

/**
 * Render calculated Overview cards and any already-known thumbnail warning.
 *
 * @param array<string,mixed> $model Prepared indexed totals, cached thumbnail result and health state.
 * @return void Outputs presentation HTML without inventory scans.
 */
function view_render_admin_dashboard_summary(array $model): void
{
    view_render_admin_dashboard_metric_grid($model);
    $thumbnailSummary = view_admin_dashboard_array($model, 'thumbnail_summary');
    if (empty($thumbnailSummary['deferred'])) {
        view_render_admin_thumbnail_maintenance_notice($thumbnailSummary);
    }
}

/**
 * Render summary metric cards for the dashboard overview.
 *
 * @param array<string,mixed> $model Indexed totals, thumbnail_summary cached result and system_action_required health flag.
 * @return void Outputs four summary cards using prepared values only.
 */
function view_render_admin_dashboard_metric_grid(array $model): void
{
    $galleries = is_array($model['galleries'] ?? null)
        ? $model['galleries']
        : [];
    $thumbnailSummary = view_admin_dashboard_array($model, 'thumbnail_summary');
    $totalGalleries = view_admin_dashboard_int(
        $model,
        'total_galleries',
        count($galleries),
    );
    $totalImages = view_admin_dashboard_int($model, 'total_images');
    $unpublishedGalleries = view_admin_dashboard_int(
        $model,
        'unpublished_galleries',
    );
    $privateGalleries = view_admin_dashboard_int($model, 'private_galleries');
    $missingThumbnailVariants = view_admin_dashboard_int(
        $model,
        'missing_thumbnail_variants',
    );
    $originalStorageLabel = (string) ($model['original_storage_label'] ?? '');
    $galleryDatabaseUsageLabel = (string) (
        $model['gallery_database_usage_label'] ?? ''
    );
    $databaseUsageLabel = (string) ($model['database_usage_label'] ?? '');
    $databaseUsageAvailable = view_admin_dashboard_bool(
        $model,
        'database_usage_available',
    );
    $migrationPending = view_admin_dashboard_bool($model, 'migration_pending');
    $systemActionRequired =
        $migrationPending
        || view_admin_dashboard_bool($model, 'system_action_required');

    $thumbnailValue = !empty($thumbnailSummary['deferred'])
        ? t('admin.dashboard.metric_not_checked', 'Not checked')
        : (string) $missingThumbnailVariants;
    $thumbnailImagesLabel = !empty($thumbnailSummary['full_check'])
        ? t('admin.dashboard.metric_images_checked', 'images checked')
        : t('admin.dashboard.metric_images_sampled', 'images sampled');
    $thumbnailHelp = !empty($thumbnailSummary['deferred'])
        ? e(t(
            'admin.dashboard.thumbnail_scan_hint',
            'Checked only on request.',
        ))
        . '<br><a class="admin-metric-inline-link" href="'
        . e(url_for('admin', ['maintenance_tab' => 'media'])
            . '#admin-tab-maintenance')
        . '">'
        . e(t('admin.dashboard.thumbnail_scan_link', 'Check thumbnails'))
        . '</a>'
        : e(
            (int) ($thumbnailSummary['images_scanned'] ?? 0)
                . ' '
                . $thumbnailImagesLabel,
        );
    $galleryStorageHelp =
        e(
            (int) $unpublishedGalleries
                . ' '
                . t('admin.dashboard.metric_unpublished', 'unpublished')
                . ', '
                . (int) $privateGalleries
                . ' '
                . t('admin.dashboard.metric_private', 'private'),
        )
        . '<br>'
        . e(t(
            'admin.dashboard.metric_original_storage',
            'Original files: {size}',
            ['size' => $originalStorageLabel],
        ));
    if ($databaseUsageAvailable && $galleryDatabaseUsageLabel !== '') {
        $galleryStorageHelp .=
            '<br>'
            . e(t(
                'admin.dashboard.metric_gallery_database_storage',
                'Gallery DB: {size}',
                ['size' => $galleryDatabaseUsageLabel],
            ));
    }
    if (
        $databaseUsageAvailable
        && $databaseUsageLabel !== ''
        && $databaseUsageLabel !== $galleryDatabaseUsageLabel
    ) {
        $galleryStorageHelp .=
            '<br>'
            . e(t(
                'admin.dashboard.metric_total_database_storage',
                'Total DB: {size}',
                ['size' => $databaseUsageLabel],
            ));
    }
    $galleryStorageHelp .=
        '<br><a class="admin-metric-inline-link" href="'
        . e(url_for('admin_storage_statistics'))
        . '">'
        . e(t('admin.storage.open_details', 'Storage details'))
        . '</a>';
    view_render_admin_metric_grid(
        [
            [
                'label' => t('admin.dashboard.metric_galleries', 'Galleries'),
                'value' => (string) $totalGalleries,
                'help_html' => $galleryStorageHelp,
                'state' =>
                    $privateGalleries > 0 || $unpublishedGalleries > 0
                        ? 'care'
                        : 'ready',
            ],
            [
                'label' => t(
                    'admin.dashboard.metric_top_level_images',
                    'Top-level images',
                ),
                'value' => (string) $totalImages,
                'help' => t(
                    'admin.dashboard.metric_imported_images_hint',
                    'Imported images shown in gallery lists',
                ),
                'state' => 'neutral',
            ],
            [
                'label' => t(
                    'admin.dashboard.metric_thumbnail_gaps',
                    'Thumbnail gaps',
                ),
                'value' => $thumbnailValue,
                'help_html' => $thumbnailHelp,
                'state' => !empty($thumbnailSummary['deferred'])
                    ? 'neutral'
                    : ($missingThumbnailVariants > 0 ? 'care' : 'ready'),
            ],
            [
                'label' => t(
                    'admin.dashboard.metric_system_state',
                    'System state',
                ),
                'value' => $systemActionRequired
                    ? t('admin.dashboard.badge_action', 'Action')
                    : t('admin.dashboard.state_ready', 'Ready'),
                'help_html' => $systemActionRequired
                    ? '<a href="' . e(url_for('admin', [
                        'maintenance_tab' => 'system',
                    ])
                        . '#admin-tab-maintenance') . '">' . e(t('admin.dashboard.review_system_health', 'Review system health')) . '</a>'
                    : e(t(
                        'admin.dashboard.state_no_migration_warning',
                        'No migration warning',
                    )),
                'state' => $systemActionRequired ? 'care' : 'ready',
            ],
        ],
        'admin-metric-grid',
        t('admin.dashboard.admin_summary', 'Admin summary'),
    );
}

/**
 * Render the overview card that opens the gallery tree.
 * @return void Outputs a compact gallery-workspace shortcut.
 */
function view_render_admin_dashboard_manage_galleries_card(): void
{
    echo
        '<article class="admin-action-card"><strong>'
            . e(t('admin.dashboard.manage_galleries_title', 'Manage galleries'))
            . '</strong><span>'
            . e(t(
                'admin.dashboard.compact_galleries_hint',
                'Visibility, ordering and bulk actions.',
            ))
            . '</span><div class="nav"><a class="button secondary" href="'
            . e(url_for('admin', ['dashboard_tab' => 'galleries'])
                . '#admin-tab-galleries')
            . '">'
            . e(t('admin.dashboard.open_gallery_tree', 'Open gallery tree'))
            . '</a></div></article>';
}

/**
 * Render the overview card for centralized global settings.
 * @param array<string,mixed> $model Prepared admin_settings_urls map with a default destination.
 * @return void Outputs the Settings shortcut.
 */
function view_render_admin_dashboard_settings_card(array $model = []): void
{
    echo
        '<article class="admin-action-card"><strong>'
            . e(t('admin.settings.title', 'Settings'))
            . '</strong><span>'
            . e(t(
                'admin.dashboard.compact_settings_hint',
                'Languages, appearance and global preferences.',
            ))
            . '</span><div class="nav"><a class="button secondary" href="'
            . e(
                (string) (
                    $model['admin_settings_urls']['default'] ?? url_for(
                        'admin_settings',
                    )
                ),
            )
            . '">'
            . e(t('admin.dashboard.open_settings', 'Open settings'))
            . '</a></div></article>';
}

/**
 * Render the overview card for gallery creation and the public upload workspace.
 *
 * @return void Outputs shortcuts to gallery creation and public galleries.
 */
function view_render_admin_dashboard_upload_card(): void
{
    echo
        '<article class="admin-action-card"><strong>'
            . e(t('admin.dashboard.add_content_title', 'Add content'))
            . '</strong><span>'
            . e(t(
                'admin.dashboard.add_content_public_hint',
                'Create an empty gallery or open galleries to add photos through the public upload panel.',
            ))
            . '</span><div class="nav"><a class="button secondary" href="'
            . e(url_for('admin_new_gallery'))
            . '">'
            . e(t(
                'admin.dashboard.create_empty_gallery',
                'Create empty gallery',
            ))
            . '</a><a class="button secondary" href="'
            . e(url_for('home'))
            . '">'
            . e(t('admin.dashboard.open_galleries', 'Open galleries'))
            . '</a></div></article>';
}

/**
 * Render the overview card for filesystem discovery.
 * @return void Outputs the existing explicit scan form and progress region.
 */
function view_render_admin_dashboard_discover_card(): void
{
    echo
        '<form method="post" action="'
            . e(url_for('admin_discover'))
            . '" class="admin-action-card" data-refresh-galleries-form data-admin-discovery-launch data-discovery-endpoint="'
            . e(url_for('admin_discover'))
            . '" data-csrf-token="'
            . e(csrf_token())
            . '">'
            . csrf_field();
    echo
        '<strong>'
            . e(t('admin.dashboard.discover_folders', 'Discover folders'))
            . '</strong><span>'
            . e(t(
                'admin.dashboard.discover_folders_hint',
                'Scan the galleries directory for new folders.',
            ))
            . '</span><button type="submit">'
            . e(t('admin.dashboard.check_folders', 'Check folders'))
            . '</button>';
    echo
        '<div class="thumbnail-progress" data-admin-discovery-progress hidden><progress class="thumbnail-progress-bar" max="100" value="0" data-admin-discovery-progress-bar></progress><p class="muted" data-admin-discovery-status></p><p class="muted" data-admin-discovery-counts></p></div></form>';
}

/**
 * Render the overview card that points to grouped maintenance tools.
 * @param array<string,mixed> $model Prepared maintenance_center_status availability and resumable-job state.
 * @return void Outputs Maintenance Center actions and the direct Maintenance fallback link.
 */
function view_render_admin_dashboard_open_maintenance_card(array $model): void
{
    $status = is_array($model['maintenance_center_status'] ?? null)
        ? $model['maintenance_center_status']
        : [];
    $available = !empty($status['available']);
    $resumable = !empty($status['resumable']);
    $active = !empty($status['active']);
    $jobStatus = (string) ($status['active_status'] ?? '');
    $percent = max(0.0, min(
        100.0,
        (float) ($status['active_progress_percent'] ?? 0),
    ));
    $lastCompleted = trim((string) ($status['last_completed_at'] ?? ''));
    $actionLabel = $resumable
        ? t('admin.maintenance_center.resume', 'Resume maintenance')
        : (
            $active
                ? t(
                    'admin.maintenance_center.view_running',
                    'View running maintenance',
                )
                : t('admin.maintenance_center.analyze', 'Analyze & optimize')
        );
    echo
        '<article class="admin-action-card admin-maintenance-center-card"><strong>'
            . e(t('admin.maintenance_center.title', 'Maintenance Center'))
            . '</strong>';
    if (!$available) {
        echo
            '<span>'
                . e(t(
                    'admin.maintenance_center.dashboard_schema_missing',
                    'Database migration required before central maintenance can run.',
                ))
                . '</span>';
    } elseif ($resumable || $active) {
        echo
            '<span>'
                . e(t(
                    'admin.maintenance_center.dashboard_active',
                    'Persisted job:',
                ))
                . ' '
                . e(
                    $jobStatus !== ''
                        ? $jobStatus
                        : t('admin.common.unknown', 'Unknown'),
                )
                . ' · '
                . e(number_format($percent, 1))
                . '%</span>';
    } elseif ($lastCompleted !== '') {
        echo
            '<span>'
                . e(t(
                    'admin.maintenance_center.last_completed',
                    'Last completed central maintenance:',
                ))
                . ' '
                . e($lastCompleted)
                . '</span>';
    } else {
        echo
            '<span>'
                . e(t(
                    'admin.maintenance_center.dashboard_idle',
                    'Analyze the installation, review the plan, then run bounded resumable maintenance.',
                ))
                . '</span>';
    }
    echo
        '<div class="nav"><a class="button secondary" href="'
            . e(url_for('admin_maintenance_center'))
            . '">'
            . e($actionLabel)
            . '</a><a class="button secondary" href="'
            . e(url_for('admin', ['dashboard_tab' => 'maintenance'])
                . '#admin-tab-maintenance')
            . '">'
            . e(t('admin.dashboard.open_maintenance', 'Open maintenance'))
            . '</a></div></article>';
}

/**
 * Render the Maintenance tab as nested, logical tool groups.
 *
 * @param array<string,mixed> $model Maintenance-enabled admin_dashboard_view_model() map;
 *   runtime_support_status.policy.action_required and image_move_pending_status.action_required
 *   are booleans; capability-keyed schema records carry the shared three-state diagnosis.
 * @return void Emit the compact heading/report toolbar, tool tabs and prepared health badges.
 * @see \Gallery\Services\admin_dashboard_view_model()
 */
function view_render_admin_dashboard_maintenance_panel(array $model): void
{
    $migrationPending = view_admin_dashboard_bool($model, 'migration_pending');
    $missingThumbnailVariants = view_admin_dashboard_int(
        $model,
        'missing_thumbnail_variants',
    );
    $securitySchemaStatuses = view_admin_dashboard_array(
        $model,
        'security_schema_statuses',
    );
    $runtimeSupport = view_admin_dashboard_array(
        $model,
        'runtime_support_status',
    );
    $systemHealthActionRequired =
        $migrationPending
        || !empty($runtimeSupport['policy']['action_required']);
    $systemHealthActionRequired =
        $systemHealthActionRequired
        || !empty($model['image_move_pending_status']['action_required']);
    foreach (array_merge(
        $securitySchemaStatuses,
        view_admin_dashboard_array($model, 'mutation_schema_statuses'),
        view_admin_dashboard_array($model, 'presentation_schema_statuses'),
    ) as $securityStatus) {
        if (
            is_array($securityStatus)
            && in_array(
                (string) ($securityStatus['state'] ?? 'unknown'),
                ['missing', 'unknown'],
                true,
            )
        ) {
            $systemHealthActionRequired = true;
            break;
        }
    }
    // $trashSummary stores active Trash counters loaded only with the Maintenance model.
    $trashSummary = view_admin_dashboard_array($model, 'gallery_trash_summary');
    // $trashCount stores the normal recoverable count requested by the Trash badge.
    $trashCount = (int) ($trashSummary['trashed_count'] ?? 0);
    // $trashProblemCount keeps crash-recovery/problem rows visible even when no normal trashed item remains.
    $trashProblemCount = (int) ($trashSummary['problem_count'] ?? 0);

    $maintenanceSubtabs = [
        [
            'id' => 'admin-maintenance-content',
            'label' => t(
                'admin.dashboard.maintenance_content_display_tab',
                'Content and display',
            ),
            'icon' => 'settings',
        ],
        [
            'id' => 'admin-maintenance-media',
            'label' => t(
                'admin.dashboard.maintenance_media_cache_tab',
                'Media and cache',
            ),
            'icon' => 'galleries',
            'badge' => $missingThumbnailVariants > 0
                ? (string) $missingThumbnailVariants
                : null,
        ],
        [
            'id' => 'admin-maintenance-navigation',
            'label' => t(
                'admin.dashboard.maintenance_navigation_tab',
                'Maps and navdata',
            ),
            'icon' => 'navigation',
        ],
        [
            'id' => 'admin-maintenance-trash',
            'label' => t('admin.trash.title', 'Trash'),
            'icon' => 'trash',
            'badge' => $trashCount > 0
                ? (string) $trashCount
                : (
                    $trashProblemCount > 0
                        ? t('admin.dashboard.badge_action', 'Action')
                        : null
                ),
        ],
        [
            'id' => 'admin-maintenance-system',
            'label' => t(
                'admin.dashboard.maintenance_system_health_tab',
                'System health',
            ),
            'icon' => 'integrity',
            'badge' => $systemHealthActionRequired
                ? t('admin.dashboard.badge_action', 'Action')
                : null,
        ],
    ];

    echo '<header class="admin-maintenance-compact-toolbar"><h2>'
        . e(t('admin.dashboard.system_tools', 'System tools')) . '</h2>';
    view_render_admin_dashboard_compact_help(
        'admin-maintenance-intro-help',
        t('admin.dashboard.system_tools', 'System tools'),
        [t(
            'admin.dashboard.system_tools_hint',
            'Operational controls are grouped by purpose so display settings, media cache work, map data, and system health do not compete in one long list.',
        )],
    );
    view_render_admin_gallery_report_maintenance_card(
        $model,
        'button secondary admin-maintenance-report-shortcut',
        true,
    );
    echo '</header>';
    echo
        '<div class="admin-subtab-scope admin-dashboard-maintenance-scope" data-admin-subtab-scope>';
    $requestedMaintenanceTab = strtolower(trim(
        (string) ($model['requested_maintenance_tab'] ?? ''),
    ));
    $maintenanceTabMap = [
        'content' => 'admin-maintenance-content',
        'media' => 'admin-maintenance-media',
        'navigation' => 'admin-maintenance-navigation',
        'trash' => 'admin-maintenance-trash',
        'system' => 'admin-maintenance-system',
    ];
    $activeMaintenanceTab = (string) (
        $maintenanceTabMap[$requestedMaintenanceTab]
        ?? 'admin-maintenance-content'
    );
    view_render_admin_subtabs(
        $maintenanceSubtabs,
        $activeMaintenanceTab,
        t(
            'admin.dashboard.maintenance_subtabs_aria',
            'Maintenance tool groups',
        ),
    );

    ob_start();
    view_render_admin_dashboard_content_display_tools($model);
    view_render_admin_subtab_panel(
        'admin-maintenance-content',
        (string) ob_get_clean(),
        $activeMaintenanceTab === 'admin-maintenance-content',
    );

    ob_start();
    view_render_admin_dashboard_media_tools($model);
    view_render_admin_subtab_panel(
        'admin-maintenance-media',
        (string) ob_get_clean(),
        $activeMaintenanceTab === 'admin-maintenance-media',
    );

    ob_start();
    view_render_admin_dashboard_navigation_tools($model);
    view_render_admin_subtab_panel(
        'admin-maintenance-navigation',
        (string) ob_get_clean(),
        $activeMaintenanceTab === 'admin-maintenance-navigation',
    );

    ob_start();
    render_admin_trash_page(
        view_admin_dashboard_array($model, 'gallery_trash_entries'),
        $trashSummary,
        true,
        [
            'enabled' => view_admin_dashboard_bool(
                $model,
                'gallery_trash_enabled',
            ),
            'auto_purge_enabled' => view_admin_dashboard_bool(
                $model,
                'gallery_trash_auto_purge_enabled',
            ),
            'auto_purge_active' => view_admin_dashboard_bool(
                $model,
                'gallery_trash_auto_purge_active',
            ),
            'retention_days' => view_admin_dashboard_int(
                $model,
                'gallery_trash_retention_days',
                30,
            ),
            'purge_batch_size' => view_admin_dashboard_int(
                $model,
                'gallery_trash_purge_batch_size',
                20,
            ),
        ],
    );
    view_render_admin_subtab_panel(
        'admin-maintenance-trash',
        (string) ob_get_clean(),
        $activeMaintenanceTab === 'admin-maintenance-trash',
    );

    ob_start();
    view_render_admin_dashboard_system_tools($model);
    view_render_admin_subtab_panel(
        'admin-maintenance-system',
        (string) ob_get_clean(),
        $activeMaintenanceTab === 'admin-maintenance-system',
    );

    echo '</div>';
}

/**
 * Render maintenance tools that affect public display and metadata policy.
 *
 * @param array<string,mixed> $model Prepared capability/readiness flags, display defaults, override count and Settings links.
 * @return void Emits three compact groups without discovering policy or reading storage.
 */
function view_render_admin_dashboard_content_display_tools(array $model): void
{
    $publicSearchEnabled = view_admin_dashboard_feature_enabled(
        $model,
        'public_search',
    );
    $gpsReady = view_admin_dashboard_bool($model, 'gps_map_override_ready');
    $galleryDatesAvailable =
        view_admin_dashboard_bool($model, 'gallery_date_range_ready')
        && view_admin_dashboard_feature_enabled(
            $model,
            'exif_gallery_date_suggestions',
        );
    // The selected Content tab already names this workspace; avoid a second intro.
    echo '<div class="admin-content-display-workspace" data-dashboard-content-settings'
        . ' data-saving-label="' . e(t('admin.features.saving', 'Saving…'))
        . '" data-saved-label="' . e(t('admin.features.saved', 'Saved'))
        . '" data-failed-label="' . e(t(
            'admin.features.save_unknown',
            'Save could not be verified. Reload state before making another change.',
        )) . '" data-reload-label="'
        . e(t('admin.features.reload_state', 'Reload state')) . '">';
    if ($publicSearchEnabled || $gpsReady) {
        echo
            '<section class="admin-content-group" data-admin-content-group="public-display"><h3>'
                . e(t(
                    'admin.dashboard.content_public_display',
                    'Public display',
                ))
                . '</h3>';
        if ($publicSearchEnabled) {
            view_render_admin_dashboard_public_search_card(
                'admin-content-row',
                $model,
            );
        }
        if ($gpsReady) {
            view_render_admin_exif_gps_defaults_card(
                'admin-content-row',
                view_admin_dashboard_bool($model, 'exif_gps_default_enabled'),
                view_admin_dashboard_int($model, 'exif_gps_override_count'),
                $model,
            );
        }
        echo '</section>';
    }
    if ($galleryDatesAvailable || $gpsReady) {
        echo
            '<section class="admin-content-group" data-admin-content-group="gallery-metadata"><h3>'
                . e(t(
                    'admin.dashboard.content_gallery_metadata',
                    'Gallery metadata',
                ))
                . '</h3>';
        if ($galleryDatesAvailable) {
            view_render_admin_gallery_dates_card('admin-content-row', $model);
        }
        if ($gpsReady) {
            view_render_admin_dashboard_gps_override_reset(view_admin_dashboard_int(
                $model,
                'exif_gps_override_count',
            ));
        }
        echo '</section>';
    }
    echo
        '<section class="admin-content-group" data-admin-content-group="urls-protection"><h3>'
            . e(t(
                'admin.dashboard.content_urls_protection',
                'Public URLs and protection',
            ))
            . '</h3>';
    view_render_admin_url_rewrite_card('admin-content-row', $model, true);
    view_render_admin_dashboard_public_paths_card('admin-content-row');
    view_render_admin_dashboard_seo_guard_card('admin-content-row', $model);
    echo '</section></div>';
}

/**
 * Render an explicit bulk reset independently of the global GPS map preference.
 *
 * @param int $overrideCount Prepared number of individual gallery map overrides.
 * @return void Emits an explanatory disclosure with a reset form only when overrides exist.
 */
function view_render_admin_dashboard_gps_override_reset(int $overrideCount): void
{
    $overrideCount = max(0, $overrideCount);
    echo
        '<article class="admin-content-row"><div class="admin-content-copy"><strong>'
            . e(t(
                'admin.dashboard.content_overrides_title',
                'GPS map overrides',
            ))
            . '</strong><span class="admin-content-override-count" title="'
            . e(t(
                'admin.dashboard.content_overrides_count',
                '{count} individual override(s).',
                ['count' => (string) $overrideCount],
            ))
            . '">' . $overrideCount . '</span>';
    view_render_admin_dashboard_compact_help(
        'admin-content-gps-overrides-help',
        t('admin.dashboard.content_overrides_title', 'GPS map overrides'),
        [t(
            'admin.dashboard.content_overrides_hint',
            'Return every gallery\'s individual map choice to the global default. The global default remains unchanged.',
        )],
    );
    echo '</div>';
    if ($overrideCount > 0) {
        echo
            '<details class="admin-content-details admin-content-reset-details"><summary>'
                . e(t(
                    'admin.dashboard.content_overrides_details',
                    'Reset gallery overrides',
                ))
                . '</summary><p>'
                . e(t(
                    'admin.dashboard.content_overrides_hint',
                    'Return every gallery\'s individual map choice to the global default. The global default remains unchanged.',
                ))
                . '</p>';
        echo
            '<form method="post" action="'
                . e(url_for('admin_exif_gps_settings'))
                . '" class="admin-content-gps-reset">'
                . csrf_field()
                . '<input type="hidden" name="maintenance_return" value="content"><input type="hidden" name="reset_gallery_overrides" value="1"><input type="hidden" name="reset_gallery_overrides_only" value="1"><button type="submit" class="secondary">'
                . e(t(
                    'admin.dashboard.content_overrides_action',
                    'Reset {count} overrides',
                    ['count' => (string) $overrideCount],
                ))
                . '</button></form></details>';
    } else {
        echo
            '<span class="muted admin-content-status">'
                . e(t(
                    'admin.dashboard.content_overrides_none',
                    'All galleries currently inherit the global map default.',
                ))
                . '</span>';
    }
    echo '</article>';
}

/**
 * Render maintenance tools for generated media and archives.
 *
 * @param array<string,mixed> $model Prepared dashboard map: feature_enabled maps
 *     capability IDs to booleans; site_maintenance_status contains cache_cleanup
 *     and cache_lifecycle evidence. Thumbnail/cron records are forwarded intact
 *     to the documented renderers below; this composer does not interpret them.
 * @see view_render_admin_dashboard_thumbnail_card()
 * @see view_render_admin_dashboard_site_maintenance_card()
 * @see view_render_admin_dashboard_cache_lifecycle_card()
 * @return void Emits independent cache/media groups and flat tool shortcuts.
 */
function view_render_admin_dashboard_media_tools(array $model): void
{
    echo
        '<div class="admin-media-cache-workspace" data-dashboard-content-settings'
            . ' data-saving-label="' . e(t('admin.features.saving', 'Saving…'))
            . '" data-saved-label="' . e(t('admin.features.saved', 'Saved'))
            . '" data-failed-label="' . e(t(
                'admin.features.save_unknown',
                'Save could not be verified. Reload state before making another change.',
            )) . '" data-reload-label="'
            . e(t('admin.features.reload_state', 'Reload state')) . '">';
    view_render_admin_dashboard_cache_cleanup_card(view_admin_dashboard_array(
        $model,
        'site_maintenance_status',
    ));
    $maintenance = view_admin_dashboard_array(
        $model,
        'site_maintenance_status',
    );
    view_render_admin_dashboard_cache_lifecycle_card(
        is_array($maintenance['cache_lifecycle'] ?? null)
            ? $maintenance['cache_lifecycle']
            : [],
    );
    view_render_admin_dashboard_thumbnail_card(
        $model,
        'admin-maintenance-card admin-content-group',
    );
    view_render_admin_dashboard_site_maintenance_card(
        $model,
        'admin-maintenance-card admin-content-group',
    );
    $showDownloads = view_admin_dashboard_feature_enabled($model, 'downloads');
    $showRenamer = view_admin_dashboard_feature_enabled($model, 'media_renamer');
    if ($showDownloads || $showRenamer) {
        echo '<section class="admin-content-group admin-media-cache-tools"><h3>'
            . e(t('admin.dashboard.media_tools', 'Media tools')) . '</h3>';
    }
    if ($showDownloads) {
        view_render_admin_dashboard_archive_card(
            'admin-media-shortcut',
        );
    }
    if ($showRenamer) {
        view_render_admin_dashboard_media_renamer_card(
            'admin-media-shortcut',
        );
    }
    if ($showDownloads || $showRenamer) {
        echo '</section>';
    }
    echo '</div>';
}

/**
 * Render the supplied regional sample and a shared inert row template without reading storage.
 * @param list<array{path?:string,phase?:string,round?:int,last_serviced_at?:int,last_completed_at?:int,next_due_at?:int,retry_at?:int,failures?:int,cursor_path?:string,cursor_offset?:int}> $regions Existing passive regional evidence; omitted fields remain explicitly unknown.
 * @return void Emits localized compact rows and the identical template used by AJAX updates.
 */
function view_render_admin_dashboard_cache_regions(array $regions): void
{
    $labels = [];
    foreach ([
        'title' => 'Regional checks (sample)',
        'empty' => 'No regional evidence recorded.',
        'sample' => 'Only the supplied sample is shown; it does not describe every region.',
        'details' => 'Technical details',
        'path' => 'Relative path',
        'phase' => 'Stored state',
        'last_completed_at' => 'Last successful completed check',
        'next' => 'Next check',
        'retry' => 'Retry after',
        'last_serviced_at' => 'Last serviced',
        'next_due_at' => 'Scheduled check',
        'retry_at' => 'Retry after',
        'round' => 'Observation round',
        'failures' => 'Failed attempts',
        'cursor_path' => 'Saved cursor path',
        'cursor_offset' => 'Saved cursor offset',
        'missing' => 'Not recorded',
        'unscheduled' => 'Not scheduled',
        'idle' => 'Idle',
        'unobserved' => 'Not yet observed',
        'inventory' => 'Checking',
        'preview' => 'Check finished; cleanup pending',
        'cleanup' => 'Cleaning',
        'complete' => 'Pass finished',
        'unknown' => 'Other state',
        'discovery' => 'Discover cache areas',
        'files' => 'Files in cache root',
        'history' => 'Confirmed deletion receipts',
    ] as $key => $fallback) {
        $labels[$key] = t('admin.cache_lifecycle.region.' . $key, $fallback);
    }
    echo
        '<div class="admin-cache-regions" data-cache-region-view data-cache-region-labels="'
            . e(json_encode(
                $labels,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ))
            . '"><h3>'
            . e($labels['title'])
            . '</h3><p class="muted">'
            . e($labels['sample'])
            . '</p><p data-cache-region-empty'
            . ($regions === [] ? '' : ' hidden')
            . '>'
            . e($labels['empty'])
            . '</p><ul class="admin-cache-region-list" role="list" data-cache-region-details>';
    foreach ($regions as $region)
        view_render_admin_dashboard_cache_region_row($region, $labels);
    echo '</ul><template data-cache-region-template>';
    view_render_admin_dashboard_cache_region_row([], $labels);
    echo '</template></div>';
}

/**
 * Format supplied regional time evidence as escaped UTC markup without inventing a missing date.
 * @param int $value Stored Unix timestamp in seconds, with zero or negative values meaning no recorded time.
 * @param string $missing Localized missing-evidence or unscheduled label.
 * @return string Time element with an explicit UTC display, or an escaped missing label.
 */
function view_admin_dashboard_cache_region_timestamp(
    int $value,
    string $missing,
): string {
    return $value > 0
        ? '<time datetime="'
        . e(gmdate('Y-m-d\TH:i:s\Z', $value))
        . '">'
        . e(gmdate('Y-m-d H:i:s', $value) . ' UTC')
        . '</time>'
        : e($missing);
}

/**
 * Render one regional evidence row using only supplied values and presentation labels.
 * @param array{path?:string,phase?:string,round?:int,last_serviced_at?:int,last_completed_at?:int,next_due_at?:int,retry_at?:int,failures?:int,cursor_path?:string,cursor_offset?:int} $region Passive regional sample row, or empty template values.
 * @param array<string,string> $labels Localized regional field, phase, identity and missing-value labels.
 * @return void Emits an escaped list item; service time never stands in for a successful completion.
 */
function view_render_admin_dashboard_cache_region_row(
    array $region,
    array $labels,
): void {
    $path = (string) ($region['path'] ?? '');
    $name = match ($path) {
        '@discovery' => $labels['discovery'],
        '@files' => $labels['files'],
        '@history' => $labels['history'],
        default => $path,
    };
    $phase = (string) ($region['phase'] ?? 'unobserved');
    $phaseLabel = in_array(
        $phase,
        ['idle', 'unobserved', 'inventory', 'preview', 'cleanup', 'complete'],
        true,
    )
        ? $labels[$phase]
        : $labels['unknown'];
    if ((int) ($region['failures'] ?? 0) > 0)
        $phaseLabel .=
            ' · ' . $labels['failures'] . ': ' . (string) $region['failures'];
    $due = (int) ($region['next_due_at'] ?? 0);
    $retry = (int) ($region['retry_at'] ?? 0);
    $nextLabel =
        $retry > 0 && $retry >= $due ? $labels['retry'] : $labels['next'];
    echo
        '<li class="admin-cache-region"><div class="admin-cache-region-heading"><strong data-cache-region-field="name">'
            . e($name)
            . '</strong><span data-cache-region-field="state">'
            . e($phaseLabel)
            . '</span></div><dl class="admin-cache-region-times"><div><dt>'
            . e($labels['last_completed_at'])
            . '</dt><dd data-cache-region-field="last_completed_at">'
            . view_admin_dashboard_cache_region_timestamp(
                (int) ($region['last_completed_at'] ?? 0),
                $labels['missing'],
            )
            . '</dd></div><div><dt data-cache-region-next-label>'
            . e($nextLabel)
            . '</dt><dd data-cache-region-field="next">'
            . view_admin_dashboard_cache_region_timestamp(
                max($due, $retry),
                $labels['unscheduled'],
            )
            . '</dd></div></dl><details><summary>'
            . e($labels['details'])
            . '</summary><dl class="admin-cache-region-technical">';
    foreach ([
        'path',
        'phase',
        'round',
        'last_serviced_at',
        'next_due_at',
        'retry_at',
        'failures',
        'cursor_path',
        'cursor_offset',
    ] as $field) {
        $value = $region[$field] ?? null;
        echo
            '<div><dt>'
                . e($labels[$field])
                . '</dt><dd data-cache-region-field="'
                . e($field)
                . '">';
        if (in_array(
            $field,
            ['last_serviced_at', 'next_due_at', 'retry_at'],
            true,
        )) {
            echo view_admin_dashboard_cache_region_timestamp(
                (int) $value,
                $field === 'last_serviced_at'
                    ? $labels['missing']
                    : $labels['unscheduled'],
            );
        } else {
            echo
                '<code>'
                    . e(
                        $value === null || $value === ''
                            ? $labels['missing']
                            : (string) $value,
                    )
                    . '</code>';
        }
        echo '</dd></div>';
    }
    echo '</dl></details></li>';
}

/**
 * Render readable scheduler times and coverage from already supplied evidence.
 * @param array{last_attempt_at?:int,last_progress_at?:int,last_removed_at?:int,oldest_completed_at?:int,next_due?:int,regions_completed?:int,regions_total?:int,regions_working?:int,scheduler_health?:string,scheduler_reason?:string,registry_unavailable?:bool} $status Passive scheduler and regional findings, with absent timestamps left unknown.
 * @return void Emits compact labelled rows with UTC dates and localized status/reason names; raw safe enums remain available in code labels.
 */
function view_render_admin_dashboard_cache_timing(array $status): void
{
    $missing = t('admin.cache_lifecycle.region.missing', 'Not recorded');
    $fields = [
        [
            'field' => 'last_attempt_at',
            'label' => t(
                'admin.cache_lifecycle.timing.attempt',
                'Last worker arrival',
            ),
        ],
        [
            'field' => 'last_progress_at',
            'label' => t(
                'admin.cache_lifecycle.overview.progress',
                'Last worker progress',
            ),
        ],
        [
            'field' => 'next_due',
            'label' => t(
                'admin.cache_lifecycle.timing.oldest_due',
                'Oldest regional due time',
            ),
        ],
        [
            'field' => 'oldest_completed_at',
            'label' => t(
                'admin.cache_lifecycle.overview.oldest',
                'Oldest completed check',
            ),
        ],
        [
            'field' => 'last_removed_at',
            'label' => t(
                'admin.cache_lifecycle.timing.removed',
                'Last confirmed deletion',
            ),
        ],
    ];
    $healthLabels = [
        'arrivals_observed' => t(
            'admin.cache_lifecycle.timing.arrivals',
            'Recent worker arrivals',
        ),
        'single_arrival' => t(
            'admin.cache_lifecycle.timing.single',
            'One worker arrival',
        ),
        'missing' => t(
            'admin.cache_lifecycle.overview.worker_missing',
            'Worker not observed',
        ),
        'unobserved' => $missing,
    ];
    $reasonLabels = [
        'cache_lifecycle_not_due' => t(
            'admin.cache_lifecycle.timing.not_due',
            'Waiting for due work',
        ),
        'manual_cache_preview_pending' => t(
            'admin.cache_lifecycle.timing.manual',
            'Manual preview awaits review',
        ),
        'foreground_requests_active' => t(
            'admin.cache_lifecycle.timing.foreground',
            'Giving priority to visitors',
        ),
        'foreground_request_arrived' => t(
            'admin.cache_lifecycle.timing.foreground',
            'Giving priority to visitors',
        ),
        'automatic_cache_cleanup_disabled' => t(
            'admin.cache_lifecycle.overview.worker_disabled',
            'Automatic cleanup is OFF',
        ),
        'central_maintenance_active' => t(
            'admin.cache_lifecycle.timing.maintenance',
            'Maintenance owns the worker',
        ),
        'maintenance_already_running' => t(
            'admin.cache_lifecycle.timing.maintenance',
            'Maintenance owns the worker',
        ),
    ];
    echo '<dl class="admin-cache-timing" data-cache-timing>';
    foreach ([
        [
            'field' => 'scheduler_health',
            'label' => t('admin.cache_lifecycle.timing.scheduler', 'Scheduler'),
            'labels' => $healthLabels,
        ],
        [
            'field' => 'scheduler_reason',
            'label' => t(
                'admin.cache_lifecycle.timing.reason',
                'Current reason',
            ),
            'labels' => $reasonLabels,
        ],
    ] as $item) {
        $value = (string) ($status[$item['field']] ?? '');
        echo
            '<div><dt>'
                . e($item['label'])
                . '</dt><dd data-cache-status-field="'
                . e($item['field'])
                . '" data-cache-status-format="enum" data-missing="'
                . e($missing)
                . '" data-cache-status-labels="'
                . e(json_encode(
                    $item['labels'],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ))
                . '"><span>'
                . e($item['labels'][$value] ?? $missing)
                . '</span> <code>'
                . e($value)
                . '</code></dd></div>';
    }
    foreach ($fields as $item) {
        echo
            '<div><dt>'
                . e($item['label'])
                . '</dt><dd data-cache-status-field="'
                . e($item['field'])
                . '" data-cache-status-format="time" data-missing="'
                . e($missing)
                . '">'
                . view_admin_dashboard_cache_region_timestamp(
                    (int) ($status[$item['field']] ?? 0),
                    $missing,
                )
                . '</dd></div>';
    }
    echo
        '<div><dt>'
            . e(t('admin.cache_lifecycle.timing.coverage', 'Completed areas'))
            . '</dt><dd><span data-cache-status-field="regions_completed">'
            . e((string) ($status['regions_completed'] ?? 0))
            . '</span> / <span data-cache-status-field="regions_total">'
            . e((string) ($status['regions_total'] ?? 0))
            . '</span></dd></div><div><dt>'
            . e(t('admin.cache_lifecycle.timing.working', 'Working areas'))
            . '</dt><dd data-cache-status-field="regions_working">'
            . e((string) ($status['regions_working'] ?? 0))
            . '</dd></div></dl>';
    if (!empty($status['registry_unavailable']))
        echo
            '<p role="status">'
                . e(t(
                    'admin.cache_lifecycle.unverified',
                    'Registry evidence is unavailable. This is not an empty or successfully checked cache.',
                ))
                . '</p>';
}

/**
 * Format current manual-operation progress independently of retained regional totals.
 * @param array{phase?:string,files?:int,bytes?:int,partial?:bool} $operation Current manual cycle's prepared phase and actual observed counters.
 * @return string Localized plain text for escaped HTML or a JSON text-content sink; no historical or lifetime counters are substituted.
 */
function view_admin_dashboard_cache_operation_label(array $operation): string
{
    $phase = (string) ($operation['phase'] ?? 'inventory');
    $fallback = match ($phase) {
        'preview' => 'Scan ready for review',
        'cleanup' => 'Manual cleanup',
        'complete' => 'Manual cycle finished',
        default => 'Manual scan',
    };
    return (
        t(
            'admin.cache_lifecycle.operation.progress',
            '{phase} · {files} files · {bytes}',
            [
                'phase' => t(
                    'admin.cache_lifecycle.operation.' . $phase,
                    $fallback,
                ),
                'files' => (string) ($operation['files'] ?? 0),
                'bytes' => format_bytes((int) ($operation['bytes'] ?? 0)),
            ],
        )
            . (
                !empty($operation['partial'])
                    ? ' · '
                    . t(
                        'admin.cache_lifecycle.operation.partial',
                        'Incomplete check',
                    )
                    : ''
            )
    );
}

/**
 * Render recorded cache size, confirmed lifetime receipts and worker health from passive evidence.
 * @param array{measurement_available?:bool,bytes?:int,bytes_removed?:int,removed?:int,pending_bytes?:int,pending_candidates?:int,last_removed_at?:int,snapshot_at?:int,last_attempt_at?:int,next_due?:int,chain_health?:string,chain_next_start_at?:int,chain_arrival_at?:int,last_progress_at?:int,oldest_completed_at?:int,registry?:bool,regions_completed?:int,regions_total?:int,partial?:bool,legacy_measurement?:bool,worker_state?:string,regions_unverified?:int,regions_working?:int,unverified_paths?:list<array{path:string,reason:string}>} $status Prepared observations and cumulative receipts; missing evidence never means a measured zero.
 * @return void Emits compact localized totals, explicit incomplete-size warnings and safe relative problem paths.
 */
function view_render_admin_dashboard_cache_overview(array $status): void
{
    $missing = t('admin.cache_lifecycle.region.missing', 'Not recorded');
    $worker = (string) ($status['worker_state'] ?? 'missing');
    $workerLabels = [
        'disabled' => 'Automatic cleanup is OFF',
        'unavailable' => 'Status unavailable',
        'missing' => 'No recent automatic worker arrival',
        'stalled' => 'Worker arriving without recent progress',
        'partial' => 'Working with incomplete coverage',
        'working' => 'Checking or cleaning',
        'waiting' => 'Waiting for the next regional check',
    ];
    $now = (int) ($status['snapshot_at'] ?? 0);
    $regionalDue = (int) ($status['next_due'] ?? 0);
    $chainDue = (int) ($status['chain_next_start_at'] ?? 0);
    $chainAvailable = in_array(
        (string) ($status['chain_health'] ?? ''),
        ['queued', 'waiting'],
        true,
    );
    $inProgress =
        (int) ($status['regions_working'] ?? 0) > 0 || $worker === 'working';
    $due = $chainAvailable && $chainDue > $now
        ? $chainDue
        : ($inProgress ? 0 : $regionalDue);
    $from = $chainAvailable
        ? (int) ($status['chain_arrival_at'] ?? 0)
        : (int) ($status['last_attempt_at'] ?? 0);
    $waitLabel = $chainAvailable
        ? t('admin.cache_lifecycle.overview.next_worker', 'Next worker check')
        : t('admin.cache_lifecycle.overview.next', 'Next check');
    $seconds = max(0, $due - $now);
    $wait = $due <= 0 && $inProgress
        ? t('admin.cache_lifecycle.operation.working', 'Working…')
        : (
            $due <= 0 || $now <= 0
                ? $missing
                : (
                    $seconds === 0
                        ? t('admin.cache_lifecycle.overview.due', 'Due')
                        : intdiv($seconds, 60)
                            . ':'
                            . str_pad(
                                (string) ($seconds % 60),
                                2,
                                '0',
                                STR_PAD_LEFT,
                            )
                )
        );
    $dueTitle = $due > 0 ? gmdate('Y-m-d H:i:s', $due) . ' UTC' : $missing;
    echo
        '<div data-cache-overview><div class="admin-cache-worker-row"><strong data-cache-worker-state="'
            . e($worker)
            . '">'
            . '<span class="admin-cache-idle-label">'
            . e(t(
                'admin.cache_lifecycle.overview.worker_' . $worker,
                $workerLabels[$worker] ?? $missing,
            ))
            . '</span><span class="admin-cache-busy-label">'
            . e(t('admin.cache_lifecycle.operation.working', 'Working…'))
            . '</span></strong>'
            . '<details class="admin-cache-help" data-cache-help><summary aria-label="'
            . e(t('admin.cache_lifecycle.overview.help', 'Cache status help'))
            . '">?</summary><div>';
    echo
        '<section><h3>'
            . e(t(
                'admin.cache_lifecycle.overview.size',
                'Recorded cache payload',
            ))
            . '</h3><p>'
            . e(
                !empty($status['partial'])
                    ? t(
                        'admin.cache_lifecycle.overview.incomplete',
                        'Whole-cache size is unknown: some areas are not fully measured.',
                    )
                    : t(
                        'admin.cache_lifecycle.overview.recorded',
                        'Last-known regional observations; live journals excluded.',
                    ),
            )
            . '</p><p>'
            . e(t(
                'admin.cache_lifecycle.overview.oldest',
                'Oldest completed check',
            ))
            . ': '
            . view_admin_dashboard_cache_region_timestamp(
                (int) ($status['oldest_completed_at'] ?? 0),
                $missing,
            )
            . '</p></section><section><h3>'
            . e(t(
                'admin.cache_lifecycle.overview.removed',
                'Confirmed cumulative removal',
            ))
            . '</h3><p>'
            . e(t(
                'admin.cache_lifecycle.overview.receipts',
                'Persisted removal receipts; older releases may be absent.',
            ))
            . ' '
            . e(t('admin.cache_lifecycle.overview.entries', '{count} entries', [
                'count' => (string) ($status['removed'] ?? 0),
            ]))
            . ' · '
            . view_admin_dashboard_cache_region_timestamp(
                (int) ($status['last_removed_at'] ?? 0),
                $missing,
            )
            . '</p></section><section><h3>'
            . e(t(
                'admin.cache_lifecycle.overview.pending',
                'Verified cleanup queue',
            ))
            . '</h3><p>'
            . e(t(
                'admin.cache_lifecycle.overview.queue_hint',
                'An empty queue does not prove that all cache areas were checked.',
            ))
            . ' '
            . e(t('admin.cache_lifecycle.overview.entries', '{count} entries', [
                'count' => (string) ($status['pending_candidates'] ?? 0),
            ]))
            . '</p><p>'
            . e(t(
                'admin.cache_lifecycle.overview.progress',
                'Last worker progress',
            ))
            . ': '
            . view_admin_dashboard_cache_region_timestamp(
                (int) ($status['last_progress_at'] ?? 0),
                $missing,
            )
            . '</p></section><section><h3>'
            . e(t(
                'admin.cache_lifecycle.overview.help_coverage',
                'Coverage and timing',
            ))
            . '</h3>';
    if (!empty($status['legacy_measurement']))
        echo
            '<p>'
                . e(t(
                    'admin.cache_lifecycle.overview.legacy',
                    'The displayed size is a retained whole-cache snapshot while regional coverage is incomplete.',
                ))
                . '</p>';
    if (!empty($status['unverified_paths'])) {
        echo
            '<details data-cache-unverified><summary>'
                . e(t(
                    'admin.cache_lifecycle.overview.unverified',
                    'Areas needing a complete check (sample)',
                ))
                . '</summary><ul>';
        foreach ($status['unverified_paths'] as $area) {
            echo
                '<li><code>'
                    . e($area['path'])
                    . '</code> · '
                    . e(t(
                        'admin.cache_lifecycle.overview.reason_'
                            . $area['reason'],
                        $area['reason'] !== '' ? $area['reason'] : $missing,
                    ))
                    . '</li>';
        }
        echo '</ul></details>';
    }
    echo
        '<p>'
            . e(t(
                'admin.cache_lifecycle.overview.coverage',
                'Completed areas: {done}/{total}; working: {working}.',
                [
                    'done' => (string) ($status['regions_completed'] ?? 0),
                    'total' => (string) ($status['regions_total'] ?? 0),
                    'working' => (string) ($status['regions_working'] ?? 0),
                ],
            ))
            . '</p>';
    echo
        '<p>'
            . e(t('admin.cache_lifecycle.overview.updated', 'Status read'))
            . ': '
            . view_admin_dashboard_cache_region_timestamp($now, $missing)
            . '</p><p>'
            . e(t(
                'admin.cache_lifecycle.overview.wait_help',
                'The bar measures elapsed waiting time until the scheduled check, not scan completion. Due work can wait for worker admission.',
            ))
            . '</p><p>'
            . e(t('admin.cache_lifecycle.automatic'))
            . '</p></section><section><h3>'
            . e(t(
                'admin.cache_lifecycle.overview.help_safety',
                'What can be removed',
            ))
            . '</h3><p>'
            . e(t('admin.cache_lifecycle.scope'))
            . '</p><p>'
            . e(t(
                'admin.cache_lifecycle.overview.owner_bar',
                'Each owner bar shows measured eligible bytes as a share of its recorded size; it is not scan progress or the current queue.',
            ))
            . '</p></section></div></details></div>';
    echo
        '<dl class="admin-cache-overview-grid" data-cache-metrics><div><dt>'
            . e(t(
                'admin.cache_lifecycle.overview.size',
                'Recorded cache payload',
            ))
            . '</dt><dd><strong>'
            . e(
                !empty($status['measurement_available'])
                    ? format_bytes((int) ($status['bytes'] ?? 0))
                    : $missing,
            )
            . '</strong></dd></div><div><dt>'
            . e(
                !empty($status['registry'])
                    ? t(
                        'admin.cache_lifecycle.overview.removed',
                        'Confirmed cumulative removal',
                    )
                    : t(
                        'admin.cache_lifecycle.overview.cycle_removed',
                        'Confirmed removal in this cycle',
                    ),
            )
            . '</dt><dd><strong>'
            . e(format_bytes((int) ($status['bytes_removed'] ?? 0)))
            . '</strong></dd></div><div><dt>'
            . e(t(
                'admin.cache_lifecycle.overview.pending',
                'Verified cleanup queue',
            ))
            . '</dt><dd><strong>'
            . e(format_bytes((int) ($status['pending_bytes'] ?? 0)))
            . '</strong></dd></div></dl>';
    echo
        '<div class="admin-cache-wait"><span>'
            . e($waitLabel)
            . '</span><progress max="100"'
            . (
                $due > 0
                && $now > 0
                && ($due <= $now || $from > 0 && $due > $from)
                    ? ' value="'
                    . e(
                        (string) (
                            $due <= $now
                                ? 100
                                : (
                                    $due > $from && $from > 0
                                        ? round(
                                            max(0, min(
                                                100,
                                                (100 * ($now - $from))
                                                    / ($due - $from),
                                            )),
                                            2,
                                        )
                                        : 0
                                )
                        ),
                    )
                    . '"'
                    : ''
            )
            . ' data-cache-wait data-start="'
            . e((string) $from)
            . '" data-due="'
            . e((string) $due)
            . '" data-observed="'
            . e((string) $now)
            . '" data-due-label="'
            . e(t('admin.cache_lifecycle.overview.due', 'Due'))
            . '" aria-label="'
            . e(t('admin.cache_lifecycle.overview.next', 'Next check'))
            . '" aria-valuetext="'
            . e($dueTitle)
            . '" title="'
            . e($dueTitle)
            . '"'
            . ($worker === 'disabled' ? ' hidden' : '')
            . '></progress><span data-cache-wait-label title="'
            . e($dueTitle)
            . '">'
            . e($worker === 'disabled' ? '—' : $wait)
            . '</span></div></div>';
}

/**
 * Render measured cache ownership and explicit preview/approval controls from prepared state.
 * @param array{id?:string,phase?:string,scheduled?:bool,registry?:bool,registry_unavailable?:bool,regions_sample?:list<array{path:string,phase:string,round:int,last_serviced_at:int,last_completed_at:int,next_due_at:int,retry_at:int,failures:int,cursor_path:string,cursor_offset:int}>,snapshot_at?:int,worker_state?:string,removed?:int,pending_candidates?:int,regions_unverified?:int,legacy_measurement?:bool,unverified_paths?:list<array{path:string,reason:string}>,regions_completed?:int,regions_total?:int,regions_working?:int,oldest_completed_at?:int,last_removed_at?:int,measurement_available?:bool,bytes?:int,pending_bytes?:int,bytes_removed?:int,partial?:bool,scheduler_health?:string,last_attempt_at?:int,last_progress_at?:int,last_observed_at?:int,next_due?:int,scheduler_reason?:string,stale?:bool,stalled?:bool,superseded_manual_at?:int,operation?:array{phase?:string,files?:int,bytes?:int,partial?:bool},policies?:array<string,array{seconds?:int|null,reason?:string,label?:string}>,totals?:array<string,array{bytes?:int,reclaimable_bytes?:int}>,sample?:list<array{path:string,bytes:int}>} $status Prepared passive lifecycle evidence and retention labels; omitted measurements remain unknown.
 * @return void Emits one compact control row and status line; operational evidence and producer policies stay in native details without domain service calls.
 */
function view_render_admin_dashboard_cache_lifecycle_card(array $status): void
{
    $phase = (string) ($status['phase'] ?? 'idle');
    echo
        '<section class="admin-maintenance-card admin-content-group admin-cache-lifecycle" data-admin-cache-lifecycle data-admin-cache-cleanup data-cache-status-url="'
            . e(url_for('admin_dashboard_fragment', ['surface' => 'cache']))
            . '"><h3>'
            . e(t(
                'admin.cache_lifecycle.title',
                'Cache inventory and retention',
            ))
            . '</h3><div class="admin-cache-actions"><form method="post" action="'
            . e(url_for('admin_site_maintenance_settings'))
            . '" data-cache-lifecycle-form>'
            . csrf_field()
            . '<input type="hidden" name="preview_id" value="'
            . e((string) ($status['id'] ?? ''))
            . '">';
    foreach ([
        'cache_inventory_start' => [
            'Scan cache',
            !in_array($phase, ['inventory', 'cleanup'], true),
        ],
        'cache_inventory_step' => ['Continue scan', $phase === 'inventory'],
        'cache_cleanup_start' => ['Clean eligible cache', $phase === 'preview'],
        'cache_cleanup_step' => ['Continue cleanup', $phase === 'cleanup'],
    ] as $action => [$label, $visible]) {
        if (!empty($status['registry']) && !empty($status['scheduled']))
            $visible = $action === 'cache_inventory_start';
        $actionLabel = t('admin.cache_lifecycle.' . $action, $label);
        echo
            '<button type="submit" class="secondary admin-cache-action-icon" name="site_maintenance_action" value="'
                . e($action)
                . '" aria-label="'
                . e($actionLabel)
                . '" title="'
                . e($actionLabel)
                . '"'
                . ($visible ? '' : ' hidden')
                . '>'
                . '<span class="admin-cache-idle-label" aria-hidden="true">'
                . view_admin_menu_icon(
                    str_starts_with($action, 'cache_inventory')
                        ? 'search'
                        : 'clean',
                )
                . '</span><span class="admin-cache-busy-label visually-hidden">'
                . e(
                    str_starts_with($action, 'cache_inventory')
                        ? t(
                            'admin.cache_lifecycle.operation.scanning',
                            'Scanning…',
                        )
                        : t(
                            'admin.cache_lifecycle.operation.cleaning',
                            'Cleaning…',
                        ),
                )
                . '</span></button> ';
    }
    echo
        '</form><button type="button" class="secondary admin-cache-refresh-icon" data-cache-status-refresh aria-label="'
            . e(t('admin.cache_lifecycle.overview.refresh', 'Refresh status'))
            . '" title="'
            . e(t('admin.cache_lifecycle.overview.refresh', 'Refresh status'))
            . '">'
            . view_admin_menu_icon('update')
            . '</button></div><span role="status" data-cache-status-feedback data-failed-label="'
            . e(t(
                'admin.cache_lifecycle.overview.refresh_failed',
                'Status refresh failed. Use Refresh status to retry.',
            ))
            . '"></span>';
    view_render_admin_dashboard_cache_overview($status);
    $operation = $status['operation'] ?? [];
    echo
        '<p class="admin-cache-operation-status" role="status" data-cache-lifecycle-status data-working-label="'
            . e(t('admin.cache_lifecycle.operation.working', 'Working…'))
            . '"'
            . ($operation === [] ? ' hidden' : '')
            . '>'
            . (
                $operation !== []
                    ? e(view_admin_dashboard_cache_operation_label($operation))
                    : ''
            )
            . '</p><progress data-cache-lifecycle-progress max="100" hidden aria-label="'
            . e(t(
                'admin.cache_lifecycle.progress_label',
                'Cache action progress',
            ))
            . '" style="width:100%;height:4px"></progress>';

    echo
        '<details class="admin-media-details"><summary>'
            . e(t(
                'admin.cache_lifecycle.policies',
                'Sizes and retention by owner',
            ))
            . '</summary>';
    view_render_admin_dashboard_cache_timing($status);
    echo
        '<table class="admin-cache-owners"><thead><tr><th scope="col">'
            . e(t('admin.cache_lifecycle.owner', 'Owner'))
            . '</th><th>'
            . e(t('admin.cache_lifecycle.lifetime', 'Lifetime'))
            . '</th><th>'
            . e(t('admin.cache_lifecycle.observed', 'Observed'))
            . '</th><th>'
            . e(t('admin.cache_lifecycle.eligible', 'Eligible'))
            . '</th></tr></thead><tbody>';
    foreach ((array) ($status['policies'] ?? []) as $owner => $policy) {
        $total = $status['totals'][$owner] ?? [];
        $seconds = $policy['seconds'] ?? null;
        $lifetime = $seconds === null
            ? t('admin.cache_lifecycle.context', 'Context / owner')
            : (
                $seconds >= 86400
                    ? ($seconds / 86400) . ' d'
                    : ($seconds / 60) . ' min'
            );
        $observed = max(0, (int) ($total['bytes'] ?? 0));
        $eligibleShare = $observed > 0
            ? round(
                max(0, min(
                    100,
                    (100 * (int) ($total['reclaimable_bytes'] ?? 0))
                        / $observed,
                )),
                2,
            )
            : 0;
        echo
            '<tr data-cache-owner="'
                . e((string) $owner)
                . '"><td title="'
                . e((string) ($policy['reason'] ?? ''))
                . '">'
                . e((string) ($policy['label'] ?? $owner))
                . '</td><td>'
                . e($lifetime)
                . '</td><td colspan="2" class="admin-cache-owner-values"><span data-cache-owner-meter aria-hidden="true" style="--cache-eligible-share:'
                . e((string) $eligibleShare)
                . '%"></span><div class="admin-cache-owner-numbers"><span><span class="admin-cache-owner-caption">'
                . e(t('admin.cache_lifecycle.observed', 'Observed'))
                . ': </span><span data-cache-owner-bytes>'
                . (
                    isset($total['bytes'])
                        ? e(format_bytes((int) $total['bytes']))
                        : '—'
                )
                . '</span></span><span><span class="admin-cache-owner-caption">'
                . e(t('admin.cache_lifecycle.eligible', 'Eligible'))
                . ': </span><span data-cache-owner-eligible>'
                . (
                    isset($total['reclaimable_bytes'])
                        ? e(format_bytes((int) $total['reclaimable_bytes']))
                        : '—'
                )
                . '</span></span></div></td></tr>';
    }
    echo
        '</tbody></table><p>'
            . e(t(
                'admin.cache_lifecycle.sample',
                'Preview examples (up to 16 candidates; totals above describe the full plan):',
            ))
            . '</p><ul data-cache-lifecycle-sample>';
    foreach ((array) ($status['sample'] ?? []) as $item) {
        echo
            '<li>'
                . e((string) ($item['path'] ?? ''))
                . ' — '
                . e(format_bytes((int) ($item['bytes'] ?? 0)))
                . '</li>';
    }
    echo '</ul>';
    view_render_admin_dashboard_cache_regions(
        (array) ($status['regions_sample'] ?? []),
    );
    if (!empty($status['partial']))
        echo
            '<p role="status">'
                . e(t(
                    'admin.cache_lifecycle.partial',
                    'Partial inventory: blocked or unreadable directories are preserved; observed totals are incomplete.',
                ))
                . '</p>';
    echo
        '</details><details class="admin-media-details" data-cache-diagnostics hidden><summary>'
            . e(t('admin.cache_cleanup.diagnostics', 'Run diagnostics'))
            . '</summary><button type="button" class="secondary" data-cache-diagnostic-copy data-copied-label="'
            . e(t('admin.cache_cleanup.copied', 'Log copied.'))
            . '" data-copy-failed-label="'
            . e(t(
                'admin.cache_cleanup.copy_failed',
                'Copy failed. Select the log and copy it manually.',
            ))
            . '">'
            . e(t('admin.cache_cleanup.copy', 'Copy log'))
            . '</button><span role="status" data-cache-copy-status></span><pre data-cache-diagnostic-events></pre></details></section>';
}

/**
 * Render the manual updater-cache action from prepared evidence, without service calls.
 * @param array{cache_cleanup?:array{available?:bool,pending?:bool,blocked?:bool,
 *     bytes_reclaimed?:int}} $status Prepared updater-trash availability,
 *     pending/blocked state and cumulative confirmed reclaimed bytes.
 * @return void Emits a compact CSRF-protected action/status row with scope and recovery instructions in a native disclosure.
 */
function view_render_admin_dashboard_cache_cleanup_card(array $status): void
{
    $cache = is_array($status['cache_cleanup'] ?? null)
        ? $status['cache_cleanup']
        : [];
    $available = !empty($cache['available']);
    $label = !$available
        ? t(
            'admin.cache_cleanup.unknown',
            'Cache status is unavailable; the action rechecks safety before deleting.',
        )
        : (
            !empty($cache['blocked'])
                ? t(
                    'admin.cache_cleanup.failed',
                    'Cleanup stopped safely. Check maintenance diagnostics before retrying.',
                )
                : t(
                    !empty($cache['pending'])
                        ? 'admin.cache_cleanup.pending'
                        : 'admin.cache_cleanup.empty',
                    !empty($cache['pending'])
                        ? 'Updater trash is waiting for cleanup.'
                        : 'No staged updater trash is waiting.',
                )
        );
    echo
        '<section class="admin-cache-cleanup admin-content-group" data-admin-cache-cleanup><h3>'
            . e(t('admin.cache_cleanup.title', 'Updater cache cleanup'))
            . '</h3>';
    echo
        '<form method="post" action="'
            . e(url_for('admin_site_maintenance_settings'))
            . '" data-admin-cache-cleanup-form>'
            . csrf_field()
            . '<input type="hidden" name="site_maintenance_action" value="cache_cleanup"><button type="submit" class="secondary" data-cache-cleanup-button data-idle-label="'
            . e(t('admin.cache_cleanup.run', 'Clean updater cache now'))
            . '" data-continue-label="'
            . e(t('admin.cache_cleanup.continue', 'Continue cleanup'))
            . '" data-working-label="'
            . e(t('admin.cache_cleanup.working', 'Cleaning…'))
            . '" data-error-label="'
            . e(t(
                'admin.cache_cleanup.failed',
                'Cleanup stopped safely. Check maintenance diagnostics before retrying.',
            ))
            . '" data-diagnostic-label="'
            . e(t('admin.cache_cleanup.diagnostic_id', 'Diagnostic ID'))
            . '" data-log-failure-label="'
            . e(t(
                'admin.cache_cleanup.log_failed',
                'Admin log write failed; see diagnostics / PHP error log.',
            ))
            . '">'
            . e(t('admin.cache_cleanup.run', 'Clean updater cache now'))
            . '</button></form>';
    echo
        '<p class="admin-cache-cleanup-result" role="status" aria-live="polite" data-cache-cleanup-result>'
            . e($label);
    if ($available) {
        echo
            ' '
                . e(t(
                    'admin.cache_cleanup.reclaimed',
                    'Reclaimed so far: {bytes}.',
                    ['bytes' => format_bytes(max(
                        0,
                        (int) ($cache['bytes_reclaimed'] ?? 0),
                    ))],
                ));
    }
    echo
        '</p><progress class="admin-cache-progress" data-cache-progress max="100" hidden aria-label="'
            . e(t('admin.cache_cleanup.progress_label', 'Action progress'))
            . '"></progress><span role="status" data-cache-job-progress></span>';
    echo
        '<form method="post" action="'
            . e(url_for('admin_site_maintenance_settings'))
            . '" data-admin-cache-cleanup-form data-cache-job-stop hidden data-update-status-url="'
            . e(url_for('admin_update', ['update_job_status' => 1]))
            . '" data-status-failed-label="'
            . e(t(
                'admin.cache_cleanup.status_failed',
                'Could not refresh update progress.',
            ))
            . '" data-phase-label="'
            . e(t('admin.cache_cleanup.phase', 'Phase'))
            . '" data-failed-label="'
            . e(t(
                'admin.cache_cleanup.updater_failed',
                'Update failed. Check Admin Updates.',
            ))
            . '" data-worker-active-label="'
            . e(t('admin.cache_cleanup.worker_active', 'Worker lock held'))
            . '" data-worker-idle-label="'
            . e(t(
                'admin.cache_cleanup.worker_idle',
                'Worker activity not confirmed',
            ))
            . '">'
            . csrf_field()
            . '<input type="hidden" name="site_maintenance_action" value="cache_job_stop"><input type="hidden" name="job_id" value="">'
            . '<button type="submit" class="button secondary" data-cache-cleanup-button data-idle-label="'
            . e(t('admin.cache_cleanup.stop', 'Stop update'))
            . '" data-working-label="'
            . e(t('admin.cache_cleanup.stopping', 'Stopping…'))
            . '" data-error-label="'
            . e(t(
                'admin.cache_cleanup.stop_failed',
                'Update could not be stopped safely. Check diagnostics and Admin Updates.',
            ))
            . '" data-diagnostic-label="'
            . e(t('admin.cache_cleanup.diagnostic_id', 'Diagnostic ID'))
            . '">'
            . e(t('admin.cache_cleanup.stop', 'Stop update'))
            . '</button></form>';
    echo
        '<details class="admin-media-details" data-cache-diagnostics hidden><summary>'
            . e(t('admin.cache_cleanup.diagnostics', 'Run diagnostics'))
            . '</summary><button type="button" class="button secondary" data-cache-diagnostic-copy data-copied-label="'
            . e(t('admin.cache_cleanup.copied', 'Log copied.'))
            . '" data-copy-failed-label="'
            . e(t(
                'admin.cache_cleanup.copy_failed',
                'Copy failed. Select the log and copy it manually.',
            ))
            . '">'
            . e(t('admin.cache_cleanup.copy', 'Copy log'))
            . '</button><span role="status" data-cache-copy-status></span><pre data-cache-diagnostic-events></pre><a data-cache-diagnostic-log href="'
            . e(url_for('admin_logs'))
            . '">'
            . e(t('admin.cache_cleanup.open_logs', 'Open this run in logs'))
            . '</a></details>';
    echo
        '<details class="admin-media-details"><summary>'
            . e(t('admin.cache_cleanup.details', 'Scope and automatic cleanup'))
            . '</summary><p>'
            . e(t(
                'admin.cache_cleanup.scope',
                'Removes only already-staged updater trash. Photos, thumbnails, downloads, rollback backups and unknown cache entries are preserved.',
            ))
            . '</p><p>'
            . e(t(
                'admin.cache_cleanup.force_hint',
                'One click runs one bounded step immediately, bypassing the timer but respecting active updates and maintenance locks. Continue for another step when needed.',
            ))
            . '</p><p>'
            . e(t(
                'admin.cache_cleanup.cron_hint',
                'For automatic progress without visitors, run this every minute:',
            ))
            . ' <code>php scripts/site_maintenance.php --cache-only</code></p><a href="'
            . e(url_for('admin_maintenance_center'))
            . '">'
            . e(t(
                'admin.cache_cleanup.other_owners',
                'Open Maintenance Center for download-cache cleanup',
            ))
            . '</a></details></section>';
}

/**
 * Render maintenance tools for maps and flight navigation data.
 *
 * @param array{
 *     feature_enabled?:array<string,bool>,
 *     flight_navdata_ready?:bool,
 *     flight_navdata_status?:array{
 *         refresh_due?:bool,
 *         last_update?:string,
 *         total?:int,
 *         last_airports?:int,
 *         last_navaids?:int,
 *         last_skipped?:int,
 *         last_deleted?:int,
 *         hybrid?:array{bundled_count?:int,...},
 *         ...
 *     },
 *     ...
 * } $model Dashboard model with the navigation capability flag, readiness
 *     and navdata freshness, count and hybrid status fields consumed by this
 *     card.
 * @return void Emits contextual map help and the compact navdata group, or a
 *     visible feature-disabled group without an actionable importer.
 */
function view_render_admin_dashboard_navigation_tools(array $model): void
{
    echo '<div class="admin-maintenance-compact-toolbar"><h2>'
        . e(t(
            'admin.dashboard.navigation_title',
            'GPS maps and navdata',
        )) . '</h2>';
    view_render_admin_dashboard_compact_help(
        'admin-navigation-scope-help',
        t('admin.dashboard.maintenance_navigation_tab', 'Maps and navdata'),
        [t(
            'admin.dashboard.navigation_hint',
            'Flight-map lookup data lives here. Per-gallery EXIF map defaults are grouped under Content and display.',
        )],
    );
    echo '</div><div class="admin-navigation-workspace">';
    if (view_admin_dashboard_feature_enabled($model, 'navigation_data')) {
        view_render_admin_navdata_maintenance_card(
            view_admin_dashboard_bool($model, 'flight_navdata_ready'),
            view_admin_dashboard_array($model, 'flight_navdata_status'),
        );
    } else {
        $featuresUrl = url_for('admin_features');
        echo
            '<article class="admin-content-group"><h3><a href="'
                . e($featuresUrl) . '">'
                . e(t(
                    'admin.dashboard.navigation_data_hidden',
                    'Navigation data',
                ))
                . '</a><a class="admin-navigation-settings-shortcut" href="'
                . e($featuresUrl) . '" aria-label="'
                . e(t('admin.dashboard.open_features', 'Open features'))
                . '">↗</a></h3><p class="admin-navigation-unavailable">'
                . e(t(
                    'admin.dashboard.navigation_data_hidden_hint',
                    'Navigation data maintenance is hidden by Admin > Features.',
                ))
                . '</p></article>';
    }
    echo '</div>';
}

/**
 * Render the complete gallery report maintenance card.
 *
 * @param array{feature_enabled?:array<string,bool>,...} $model Dashboard model
 *     with feature flags indexed by the dynamic capability key.
 * @param string $className CSS class for the wrapper or compact link.
 * @param bool $compact Whether to emit only the named report shortcut.
 * @return void Emit the feature-gated card or compact link; emit nothing when disabled.
 */
function view_render_admin_gallery_report_maintenance_card(
    array $model,
    string $className = 'admin-maintenance-card',
    bool $compact = false,
): void {
    if (!view_admin_dashboard_feature_enabled(
        $model,
        'complete_gallery_report',
    )) {
        return;
    }
    if ($compact) {
        echo '<a class="' . e($className) . '" href="'
            . e(url_for('admin_gallery_report')) . '" title="'
            . e(t(
                'admin.dashboard.gallery_report_hint',
                'Generate one detailed downloadable HTML report with storage, database, telemetry, EXIF, GPS, galleries, and runtime diagnostics.',
            )) . '">' . e(t(
                'admin.dashboard.gallery_report',
                'Complete gallery report',
            )) . '</a>';
        return;
    }
    echo
        '<article class="'
            . e($className)
            . '"><strong>'
            . e(t('admin.dashboard.gallery_report', 'Complete gallery report'))
            . '</strong><span>'
            . e(t(
                'admin.dashboard.gallery_report_hint',
                'Generate one detailed downloadable HTML report with storage, database, telemetry, EXIF, GPS, galleries, and runtime diagnostics.',
            ))
            . '</span><a class="button secondary" href="'
            . e(url_for('admin_gallery_report'))
            . '">'
            . e(t(
                'admin.dashboard.open_gallery_report',
                'Open report generator',
            ))
            . '</a></article>';
}

/**
 * Render maintenance tools for application health and diagnostics.
 *
 * @param array{security_schema_statuses?:array<string,array{state?:string,
 *     request_id?:string,affected_objects?:list<string>}>,
 *     mutation_schema_statuses?:array<string,array{state?:string,request_id?:string,
 *     affected_objects?:list<string>,title?:string,message?:string}>,
 *     presentation_schema_statuses?:array<string,array{state?:string,
 *     request_id?:string,affected_objects?:list<string>}>,
 *     runtime_support_status?:array{policy?:array{state?:string,
 *     action_required?:bool,reference_url?:string},labels?:array<string,string>},
 *     image_move_pending_status?:array{state?:string,action_required?:bool,
 *     request_id?:string,entries?:list<string>,labels?:array<string,string>},
 *     migration_pending?:bool,update_pending?:bool,update_label?:string,
 *     feature_enabled?:array<string,bool>,dev_mode_enabled?:bool} $model
 *     Prepared, localized health records and existing tool/preference state.
 * @return void Emit compact health rows and existing tool controls without resolving service policy.
 * @see \Gallery\Services\admin_dashboard_view_model()
 */
function view_render_admin_dashboard_system_tools(array $model): void
{
    $security = view_admin_dashboard_array($model, 'security_schema_statuses');
    $mutations = view_admin_dashboard_array($model, 'mutation_schema_statuses');
    $presentations = view_admin_dashboard_array(
        $model,
        'presentation_schema_statuses',
    );
    $runtime = view_admin_dashboard_array($model, 'runtime_support_status');
    $pending = view_admin_dashboard_array($model, 'image_move_pending_status');
    $maintenance = [];
    foreach ([
        'mutation_database_maintenance',
        'mutation_application_update',
    ] as $key) {
        if (array_key_exists($key, $mutations)) {
            $maintenance[$key] = $mutations[$key];
            unset($mutations[$key]);
        }
    }
    $maps = [];
    foreach ([
        'presentation_gps_exif',
        'presentation_gps_override',
        'presentation_flight_map',
        'presentation_flight_navdata',
        'presentation_simbrief_route_map',
        'presentation_navigation_cache',
        'presentation_navigation_account',
    ] as $key) {
        if (array_key_exists($key, $presentations)) {
            $maps[$key] = $presentations[$key];
            unset($presentations[$key]);
        }
    }

    echo '<div class="admin-health-toolbar"><strong>'
        . e(t('admin.dashboard.health_overview', 'System health'))
        . '</strong>';
    view_render_admin_dashboard_compact_help(
        'system-health-scope',
        t('admin.dashboard.health_scope_title', 'What do these checks mean?'),
        [
            t(
                'admin.dashboard.health_scope',
                'A checkmark means the required storage was found. It does not test a complete upload, restore, presentation, or connection to an external service. Opening this page does not repair or change data.',
            ),
            t(
                'admin.dashboard.health_legend',
                'A dash means the feature is disabled in configuration. Problems appear first within each group. Open a question mark for the purpose of a check and Details for database identifiers or an error reference.',
            ),
        ],
    );
    echo '</div><div class="admin-system-health-workspace"'
        . ' data-dashboard-content-settings'
        . ' data-saving-label="' . e(t('admin.features.saving', 'Saving…'))
        . '" data-saved-label="' . e(t('admin.features.saved', 'Saved'))
        . '" data-failed-label="' . e(t(
            'admin.features.save_unknown',
            'Save could not be verified. Reload state before making another change.',
        )) . '" data-reload-label="'
        . e(t('admin.features.reload_state', 'Reload state')) . '">';
    echo '<section class="admin-content-group"><h3>'
        . e(t('admin.dashboard.health_group_runtime', 'Runtime and recovery'))
        . '</h3>';
    if (view_admin_dashboard_bool($model, 'migration_pending')) {
        view_render_admin_dashboard_migration_card('admin-health-migration');
    }
    view_render_admin_runtime_support_card(
        $runtime,
        'admin-maintenance-card admin-system-health-row'
            . (!empty($runtime['policy']['action_required']) ? ' is-attention' : ''),
        true,
    );
    view_render_admin_image_move_pending_card(
        $pending,
        'admin-maintenance-card admin-system-health-row'
            . (!empty($pending['action_required']) ? ' is-attention' : ''),
        true,
    );
    view_render_admin_system_health_records('mutation', $maintenance);
    echo '</section>';
    foreach ([
        ['access', 'Access and sign-in', 'security', $security],
        ['files', 'Galleries and files', 'mutation', $mutations],
        ['maps', 'Maps and flight routes', 'presentation', $maps],
        ['public', 'Public features and reports', 'presentation', $presentations],
    ] as [$key, $fallback, $kind, $records]) {
        if ($records === []) {
            continue;
        }
        echo '<section class="admin-content-group"><h3>'
            . e(t('admin.dashboard.health_group_' . $key, $fallback))
            . '</h3>';
        view_render_admin_system_health_records($kind, $records);
        echo '</section>';
    }
    echo '<section class="admin-content-group"><h3>'
        . e(t('admin.dashboard.health_group_tools', 'Tools and diagnostics'))
        . '</h3>';
    $tools = [
        'admin_logs' => [
            url_for('admin_logs'),
            t('admin.dashboard.logs', 'Logs'),
            t('admin.dashboard.logs_hint', 'Review operational events, failures, and workflow status.'),
        ],
        'admin_integrity' => [
            url_for('admin_integrity'),
            t('admin.dashboard.integrity', 'Integrity'),
            t('admin.dashboard.integrity_hint', 'Check core files and deployment health.'),
        ],
        'admin_search_diagnostics' => [
            url_for('admin_search_diagnostics'),
            t('admin.search_diagnostics.dashboard_title', 'Search diagnostics'),
            t('admin.search_diagnostics.dashboard_hint', 'Measure public search against the live database.'),
        ],
        'admin_update' => [
            url_for('admin_update'),
            (string) ($model['update_label'] ?? t('admin.menu.updates', 'Updates')),
            t('admin.dashboard.updates_hint', 'Check and apply project updates.'),
        ],
        'admin_features' => [
            url_for('admin_features'),
            t('admin.dashboard.features', 'Features'),
            t('admin.dashboard.features_hint', 'Enable or hide unfinished, optional, or site-specific feature areas.'),
        ],
    ];
    if (view_admin_dashboard_feature_enabled($model, 'telemetry')) {
        $tools['admin_telemetry'] = [
            url_for('admin_telemetry'),
            t('admin.dashboard.telemetry', 'Telemetry'),
            t('admin.dashboard.telemetry_hint', 'Inspect anonymous usage telemetry without collecting personal data.'),
        ];
    }
    if (view_admin_dashboard_feature_enabled($model, 'complete_gallery_report')) {
        $tools['admin_gallery_report'] = [
            url_for('admin_gallery_report'),
            t('admin.dashboard.gallery_report', 'Complete gallery report'),
            t('admin.dashboard.gallery_report_hint', 'Generate one detailed downloadable HTML report.'),
        ];
    }
    foreach ($tools as $route => [$url, $title, $purpose]) {
        echo '<div class="admin-health-tool"><a href="'
            . e($url) . '">' . e($title) . '</a>';
        view_render_admin_dashboard_compact_help(
            'system-tool-' . $route,
            $title,
            [$purpose],
        );
        if ($route === 'admin_update' && !empty($model['update_pending'])) {
            echo '<span class="admin-tab-badge">'
                . e(t('admin.dashboard.badge_action', 'Action')) . '</span>';
        }
        echo '</div>';
    }
    view_render_admin_devmode_card('admin-content-row admin-health-devmode', $model);
    echo '</section></div>';
}

/**
 * Render prepared capability checks with problems first and stable peer order.
 *
 * @param string $kind Owning renderer: security, mutation, or presentation.
 * @param array<string,array{state?:string,request_id?:string,
 *     affected_objects?:list<string>,title?:string,message?:string}|null|bool|int|float|string>
 *     $records Prepared capability records; malformed scalar/null records are
 *     displayed as unknown rather than silently dropped.
 * @return void Emit existing shared health renderers in their compact mode.
 */
function view_render_admin_system_health_records(string $kind, array $records): void
{
    foreach ($records as $feature => $record) {
        $records[$feature] = is_array($record) ? $record : ['state' => 'unknown'];
    }
    uasort($records, static function (array $left, array $right): int {
        $safe = ['available', 'disabled'];
        return (int) in_array($left['state'] ?? '', $safe, true)
            <=> (int) in_array($right['state'] ?? '', $safe, true);
    });
    foreach ($records as $feature => $status) {
        $className = view_admin_dashboard_health_row_class($status);
        if ($kind === 'security') {
            view_render_admin_dashboard_security_schema_card(
                (string) $feature,
                $status,
                $className,
            );
        } elseif ($kind === 'mutation') {
            view_render_admin_dashboard_mutation_schema_card(
                (string) $feature,
                $status,
                $className,
            );
        } else {
            view_render_admin_dashboard_presentation_schema_card(
                (string) $feature,
                $status,
                $className,
            );
        }
    }
}

/**
 * Present storage readiness without claiming an end-to-end functional test.
 *
 * @param string $feature Prepared, bounded capability identifier.
 * @param string $title Localized capability name from its existing renderer.
 * @param array{state?:string,request_id?:string,affected_objects?:list<string>,
 *     title?:string,message?:string} $status Prepared state, safe identifiers and optional
 *     localized upstream protection message; unexpected states remain unknown.
 * @param string $className Escaped compact wrapper classes.
 * @return void Emit a short state, contextual purpose and actionable problems.
 */
function view_render_admin_system_health_check(
    string $feature,
    string $title,
    array $status,
    string $className,
): void {
    $title = (string) ($status['title'] ?? t(
        'admin.dashboard.health_name_' . $feature,
        $title,
    ));
    $state = (string) ($status['state'] ?? 'unknown');
    if (!in_array($state, ['available', 'missing', 'unknown', 'disabled'], true)) {
        $state = 'unknown';
    }
    $purposes = [
        'gallery_access' => 'access',
        'gallery_visibility' => 'visibility',
        'gallery_share_token' => 'sharing',
        'nsfw_guard' => 'nsfw',
        'auth_persistent_login' => 'login',
        'auth_password_reset' => 'reset',
        'auth_external_identity' => 'identity',
        'mutation_gallery_edit' => 'edit',
        'mutation_gallery_delete' => 'delete',
        'mutation_gallery_trash' => 'trash',
        'mutation_gallery_move' => 'move',
        'mutation_duplicate_photo_ledger' => 'duplicates',
        'mutation_upload_ingestion' => 'upload',
        'mutation_upload_automation' => 'automation',
        'mutation_gallery_migration' => 'migration',
        'mutation_mobile_webdav' => 'webdav',
        'mutation_thumbnail_metadata' => 'thumbnails',
        'mutation_database_maintenance' => 'database',
        'mutation_application_update' => 'updates',
        'presentation_gps_exif' => 'gps',
        'presentation_gps_override' => 'gps',
        'presentation_flight_map' => 'routes',
        'presentation_flight_navdata' => 'navdata',
        'presentation_simbrief_route_map' => 'routes',
        'presentation_navigation_cache' => 'navdata',
        'presentation_navigation_account' => 'navaccount',
        'presentation_openai_text' => 'ai',
        'presentation_openai_image_input' => 'ai',
        'presentation_ai_image_analysis' => 'ai',
        'presentation_image_voting' => 'voting',
        'presentation_picture_game' => 'game',
        'presentation_lightbox_override' => 'viewer',
        'presentation_gallery_settings' => 'slideshow',
        'presentation_links' => 'presentationlinks',
        'presentation_gallery_visit_content' => 'visits',
        'presentation_telemetry_reporting' => 'telemetry',
        'presentation_admin_gallery_report' => 'report',
        'presentation_gallery_attachment_usage' => 'counts',
        'presentation_gallery_attachment_usage_music' => 'counts',
    ];
    $purpose = t(
        'admin.dashboard.health_purpose_' . ($purposes[$feature] ?? 'other'),
        'Checks storage needed by this feature. It does not perform the operation.',
    );
    $stateLabels = [
        'available' => 'Storage ready',
        'missing' => 'Database migration needed',
        'unknown' => 'Could not check',
        'disabled' => 'Disabled in configuration',
    ];
    $stateLabel = t('admin.dashboard.health_state_' . $state, $stateLabels[$state]);
    echo '<article class="' . e($className) . '" data-health-check="'
        . e($feature) . '" data-health-state="' . e($state) . '">'
        . '<div class="admin-health-check-heading"><span class="admin-health-state"'
        . ' role="img" aria-label="' . e($stateLabel) . '" title="'
        . e($stateLabel) . '">'
        . ($state === 'available' ? '✓' : ($state === 'disabled' ? '—' : '!'))
        . '</span><strong>' . e($title) . '</strong>';
    view_render_admin_dashboard_compact_help(
        'health-' . $feature,
        $title,
        [$purpose, $stateLabel],
    );
    echo '</div>';
    $attention = in_array($state, ['missing', 'unknown'], true);
    if ($attention) {
        echo '<div class="admin-health-problem"><span class="admin-tab-badge">'
            . e(t('admin.dashboard.badge_action', 'Action')) . '</span> '
            . '<strong>' . e($stateLabel) . '</strong><p>'
            . e(t('admin.dashboard.health_action_' . $state, $stateLabel))
            . '</p>';
        $family = $feature === 'nsfw_guard'
            ? 'nsfw'
            : (str_starts_with($feature, 'mutation_') ? 'mutation'
                : (str_starts_with($feature, 'presentation_') ? 'presentation' : 'security'));
        echo '<p>' . e((string) ($status['message'] ?? t(
            'admin.dashboard.' . $family . '_schema_' . $state,
            $stateLabel,
        ))) . '</p></div>';
    }
    $objects = array_values(array_filter(array_map(
        'strval',
        (array) ($status['affected_objects'] ?? []),
    )));
    $requestId = trim((string) ($status['request_id'] ?? ''));
    if ($objects !== [] || $requestId !== '' || isset($status['message'])) {
        echo '<details class="admin-system-health-details"><summary>'
            . e(t('admin.dashboard.health_details', 'Details')) . '</summary>';
        if (!$attention && isset($status['message'])) {
            echo '<p>' . e((string) $status['message']) . '</p>';
        }
        if ($objects !== []) {
            echo '<p>' . e(t('admin.dashboard.security_schema_objects',
                'Affected database objects: {objects}',
                ['objects' => implode(', ', $objects)],
            )) . '</p>';
        }
        if ($requestId !== '') {
            echo '<p>' . e(t('public.request_reference', 'Reference: {request_id}',
                ['request_id' => $requestId],
            )) . '</p>';
        }
        echo '</details>';
    }
    echo '</article>';
}

/**
 * Select presentation classes from an already resolved schema state.
 *
 * @param array{state?:string} $status Prepared health state; omitted state displays unknown.
 * @return string Compact row classes with attention styling for missing or unknown state.
 */
function view_admin_dashboard_health_row_class(array $status): string
{
    $state = (string) ($status['state'] ?? 'unknown');
    if (!in_array(
        $state,
        ['available', 'missing', 'unknown', 'disabled'],
        true,
    )) {
        $state = 'unknown';
    }
    return (
        'admin-maintenance-card admin-system-health-row is-state-'
            . $state
            . (
                in_array($state, ['missing', 'unknown'], true)
                    ? ' is-attention'
                    : ''
            )
    );
}

/**
 * Render the shared runtime support card from its already localized health model.
 *
 * @param array{policy?:array<string,mixed>,labels?:array<string,string>} $health
 *   Prepared runtime_support_health_status() model; omitted legacy models render no card.
 * @param string $className Existing presentation class for the owning surface.
 * @param bool $compact Whether ancillary support detail belongs in a disclosure.
 * @return void All policy values, localized strings and the official URL are escaped.
 */
function view_render_admin_runtime_support_card(
    array $health,
    string $className = 'admin-maintenance-card',
    bool $compact = false,
): void {
    $policy = $health['policy'] ?? [];
    $labels = $health['labels'] ?? [];
    if ($policy === [] || $labels === []) {
        return;
    }
    echo
        '<article class="'
            . e($className)
            . '" data-runtime-support data-runtime-state="'
            . e((string) $policy['state'])
            . '">';
    echo '<strong>' . e((string) $labels['title']) . '</strong>';
    if (!empty($policy['action_required'])) {
        echo
            '<span class="admin-tab-badge">'
                . e((string) $labels['action'])
                . '</span>';
    }
    if ($compact) {
        echo '<span>' . e((string) $labels['summary']) . '</span>';
        if (!empty($policy['action_required'])) {
            echo '<span>' . e((string) $labels['guidance']) . '</span>';
        }
        echo
            '<details class="admin-system-health-details"><summary>'
                . e(t('admin.dashboard.health_details', 'Details'))
                . '</summary>';
    }
    foreach ($compact
        ? ['deadline', 'baseline', 'reviewed']
        : [
            'summary',
            'deadline',
            'baseline',
            'guidance',
            'reviewed',
        ] as $label) {
        echo '<span>' . e((string) $labels[$label]) . '</span>';
    }
    if ($compact && empty($policy['action_required'])) {
        echo '<span>' . e((string) $labels['guidance']) . '</span>';
    }
    echo
        '<a href="'
            . e((string) $policy['reference_url'])
            . '" rel="noopener noreferrer" target="_blank">'
            . e((string) $labels['reference'])
            . '</a>';
    echo ($compact ? '</details>' : '') . '</article>';
}

/**
 * Render the same prepared, read-only pending-move snapshot on both Admin surfaces.
 *
 * @param array{state?:string,action_required?:bool,request_id?:string,entries?:list<string>,labels?:array<string,string>} $health
 *   Localized health data; an omitted lazy snapshot renders nothing.
 * @param string $className Existing presentation class for the owning surface.
 * @param bool $compact Whether healthy explanatory guidance belongs in a disclosure.
 * @return void All prepared text and identifiers are HTML-escaped; there are no actions.
 */
function view_render_admin_image_move_pending_card(
    array $health,
    string $className = 'admin-maintenance-card',
    bool $compact = false,
): void {
    if ($health === []) {
        return;
    }
    $labels = $health['labels'] ?? [];
    echo
        '<article class="'
            . e($className)
            . '" data-image-move-pending data-pending-state="'
            . e((string) ($health['state'] ?? 'unknown'))
            . '">';
    echo '<strong>' . e((string) ($labels['title'] ?? '')) . '</strong>';
    if (!empty($health['action_required'])) {
        echo
            '<span class="admin-tab-badge">'
                . e((string) ($labels['action'] ?? ''))
                . '</span>';
    }
    echo '<span>' . e((string) ($labels['summary'] ?? '')) . '</span>';
    $discloseGuidance = $compact && empty($health['action_required']);
    if ($discloseGuidance) {
        echo
            '<details class="admin-system-health-details"><summary>'
                . e(t('admin.dashboard.health_details', 'Details'))
                . '</summary>';
    }
    foreach ($health['entries'] ?? [] as $entry) {
        echo '<span>' . e((string) $entry) . '</span>';
    }
    echo '<span>' . e((string) ($labels['guidance'] ?? '')) . '</span>';
    if ($discloseGuidance) {
        echo '</details>';
    }
    if ((string) ($health['request_id'] ?? '') !== '') {
        echo
            '<span>'
                . e(t('public.request_reference', 'Reference: {request_id}', [
                    'request_id' => $health['request_id'],
                ]))
                . '</span>';
    }
    echo '</article>';
}

/**
 * Render one Phase 9 security/authentication schema diagnosis.
 *
 * @param string $feature Bounded capability key.
 * @param array{state?:string,request_id?:string,affected_objects?:list<string>}
 *     $status Bounded Admin state, safe correlation and database object names.
 * @param string $className CSS class name for the card wrapper.
 * @return void Emit one escaped diagnosis with actionable readiness details.
 */
function view_render_admin_dashboard_security_schema_card(
    string $feature,
    array $status,
    string $className,
): void {
    if ($feature === 'nsfw_guard') {
        view_render_admin_dashboard_nsfw_schema_card($status, $className);
        return;
    }

    $labels = [
        'gallery_access' => 'Gallery password and access policy',
        'gallery_visibility' => 'Gallery visibility compatibility',
        'gallery_share_token' => 'Gallery share-token storage',
        'auth_persistent_login' => 'Persistent administrator login',
        'auth_password_reset' => 'Password reset storage',
        'auth_external_identity' => 'External identity links',
    ];
    $title = t(
        'admin.dashboard.health_title_' . $feature,
        $labels[$feature] ?? $feature,
    );
    if (str_contains($className, 'admin-system-health-row')) {
        view_render_admin_system_health_check($feature, $title, $status, $className);
        return;
    }
    $state = (string) ($status['state'] ?? 'unknown');
    echo
        '<article class="'
            . e($className)
            . '"><strong>'
            . e($title)
            . '</strong>';
    if ($state === 'available') {
        echo
            '<span>'
                . e(t(
                    'admin.dashboard.security_schema_available',
                    'Required database objects are installed and verified.',
                ))
                . '</span>';
    } elseif ($state === 'missing') {
        echo
            '<span>'
                . e(t(
                    'admin.dashboard.security_schema_missing',
                    'Database inspection succeeded and confirmed required objects are missing. Apply pending migrations; only explicitly documented legacy compatibility remains active.',
                ))
                . '</span>';
    } elseif ($state === 'disabled') {
        echo
            '<span>'
                . e(t(
                    'admin.dashboard.security_schema_disabled',
                    'This integration is intentionally disabled by configuration.',
                ))
                . '</span>';
    } else {
        echo
            '<span>'
                . e(t(
                    'admin.dashboard.security_schema_unknown',
                    'Required database schema could not be verified. Security-sensitive operations for this capability are temporarily refused until connectivity, selected database, and metadata permissions are healthy.',
                ))
                . '</span>';
        $requestId = trim((string) ($status['request_id'] ?? ''));
        if ($requestId !== '') {
            echo
                '<span>'
                    . e(t(
                        'public.request_reference',
                        'Reference: {request_id}',
                        ['request_id' => $requestId],
                    ))
                    . '</span>';
        }
    }
    $affectedObjects = array_values(array_filter(array_map(
        'strval',
        (array) ($status['affected_objects'] ?? []),
    )));
    if ($affectedObjects !== []) {
        echo
            '<span>'
                . e(t(
                    'admin.dashboard.security_schema_objects',
                    'Affected database objects: {objects}',
                    ['objects' => implode(', ', $affectedObjects)],
                ))
                . '</span>';
    }
    echo '</article>';
}

/**
 * Render one Phase 10 destructive/ingestion schema diagnosis.
 *
 * @param string $feature Bounded capability key.
 * @param array{state:string,feature:string,request_id:string,affected_objects:array<int,string>,suggested_checks:array<int,string>,title?:string,message?:string} $status
 *   Shared mutation-health record: state is available/missing/unknown/disabled; optional
 *   title/message are localized upstream and affected_objects contains safe identifiers only.
 * @param string $className CSS class name for the card wrapper.
 * @return void Render escaped schema state and any centrally prepared protection message.
 */
function view_render_admin_dashboard_mutation_schema_card(
    string $feature,
    array $status,
    string $className,
): void {
    $labels = [
        'mutation_gallery_delete' => t(
            'admin.dashboard.mutation_schema_feature_gallery_delete',
            'Gallery and image deletion',
        ),
        'mutation_gallery_trash' => t(
            'admin.dashboard.mutation_schema_feature_gallery_trash',
            'Gallery trash bin',
        ),
        'mutation_gallery_move' => t(
            'admin.dashboard.mutation_schema_feature_gallery_move',
            'Gallery and image move/copy',
        ),
        'mutation_duplicate_photo_ledger' => t(
            'admin.dashboard.mutation_schema_feature_duplicate_ledger',
            'Duplicate Photo Detector ledger',
        ),
        'mutation_upload_ingestion' => t(
            'admin.dashboard.mutation_schema_feature_upload_ingestion',
            'Gallery upload ingestion',
        ),
        'mutation_upload_automation' => t(
            'admin.dashboard.mutation_schema_feature_upload_automation',
            'Upload automation tokens',
        ),
        'mutation_gallery_migration' => t(
            'admin.dashboard.mutation_schema_feature_gallery_migration',
            'Gallery migration',
        ),
        'mutation_mobile_webdav' => t(
            'admin.dashboard.mutation_schema_feature_mobile_webdav',
            'Mobile WebDAV uploads',
        ),
        'mutation_thumbnail_metadata' => t(
            'admin.dashboard.mutation_schema_feature_thumbnail_metadata',
            'Thumbnail metadata maintenance',
        ),
        'mutation_database_maintenance' => t(
            'admin.dashboard.mutation_schema_feature_database_maintenance',
            'Database cleanup and repair',
        ),
        'mutation_application_update' => t(
            'admin.dashboard.mutation_schema_feature_application_update',
            'Application update activation',
        ),
    ];
    $title = (string) (
        $status['title']
        ?? $labels[$feature]
        ?? 'Mutation schema capability'
    );
    if (str_contains($className, 'admin-system-health-row')) {
        view_render_admin_system_health_check($feature, $title, $status, $className);
        return;
    }
    $state = (string) ($status['state'] ?? 'unknown');

    echo
        '<article class="'
            . e($className)
            . '"><strong>'
            . e($title)
            . '</strong>';
    if (isset($status['message'])) {
        if (in_array($state, ['missing', 'unknown'], true)) {
            echo
                '<span class="admin-tab-badge">'
                    . e(t('admin.dashboard.badge_action', 'Action'))
                    . '</span>';
        }
        echo '<span>' . e((string) $status['message']) . '</span>';
        if ((string) ($status['request_id'] ?? '') !== '') {
            echo
                '<span>'
                    . e(t(
                        'public.request_reference',
                        'Reference: {request_id}',
                        ['request_id' => (string) $status['request_id']],
                    ))
                    . '</span>';
        }
    } elseif ($state === 'available') {
        echo
            '<span>'
                . e(t(
                    'admin.dashboard.mutation_schema_available',
                    'Required mutation database objects are installed and verified.',
                ))
                . '</span>';
    } elseif ($state === 'missing') {
        echo
            '<span>'
                . e(t(
                    'admin.dashboard.mutation_schema_missing',
                    'Database inspection succeeded and confirmed required mutation objects are missing. Apply pending migrations before using this workflow, except where a documented legacy compatibility path explicitly applies.',
                ))
                . '</span>';
    } else {
        echo
            '<span>'
                . e(t(
                    'admin.dashboard.mutation_schema_unknown',
                    'Required database schema could not be verified. This mutation is temporarily refused so files, rows, credentials, migration state, or active application files are not changed on an indeterminate schema.',
                ))
                . '</span>';
        $requestId = trim((string) ($status['request_id'] ?? ''));
        if ($requestId !== '') {
            echo
                '<span>'
                    . e(t(
                        'public.request_reference',
                        'Reference: {request_id}',
                        ['request_id' => $requestId],
                    ))
                    . '</span>';
        }
    }
    $affectedObjects = array_values(array_filter(array_map(
        'strval',
        (array) ($status['affected_objects'] ?? []),
    )));
    if ($affectedObjects !== []) {
        echo
            '<span>'
                . e(t(
                    'admin.dashboard.security_schema_objects',
                    'Affected database objects: {objects}',
                    ['objects' => implode(', ', $affectedObjects)],
                ))
                . '</span>';
    }
    echo '</article>';
}

/**
 * Render one Phase 11 optional presentation/reporting schema diagnosis.
 *
 * @param string $feature Bounded capability key.
 * @param array{state?:string,request_id?:string,affected_objects?:list<string>}
 *     $status Bounded Admin readiness, correlation and affected object names.
 * @param string $className CSS class name for the card wrapper.
 * @return void Emit an escaped diagnosis with actionable readiness details.
 */
function view_render_admin_dashboard_presentation_schema_card(
    string $feature,
    array $status,
    string $className,
): void {
    $labels = [
        'presentation_gps_exif' => t(
            'admin.dashboard.presentation_schema_feature_gps_exif',
            'GPS and EXIF maps',
        ),
        'presentation_gps_override' => t(
            'admin.dashboard.presentation_schema_feature_gps_override',
            'Per-gallery GPS map overrides',
        ),
        'presentation_flight_map' => t(
            'admin.dashboard.presentation_schema_feature_flight_map',
            'Flight route maps',
        ),
        'presentation_flight_navdata' => t(
            'admin.dashboard.presentation_schema_feature_flight_navdata',
            'Flight-map navigation data',
        ),
        'presentation_image_voting' => t(
            'admin.dashboard.presentation_schema_feature_image_voting',
            'Image voting',
        ),
        'presentation_picture_game' => t(
            'admin.dashboard.presentation_schema_feature_picture_game',
            'Picture game',
        ),
        'presentation_lightbox_override' => t(
            'admin.dashboard.presentation_schema_feature_lightbox_override',
            'Lightbox mode overrides',
        ),
        'presentation_gallery_settings' => t('presentation.settings'),
        'presentation_links' => t(
            'admin.dashboard.health_title_presentation_links',
            'Shared presentation links',
        ),
        'presentation_gallery_visit_content' => t(
            'admin.dashboard.health_title_visits',
            'New gallery content',
        ),
        'presentation_openai_text' => t(
            'admin.dashboard.presentation_schema_feature_openai_text',
            'OpenAI text assistance',
        ),
        'presentation_openai_image_input' => t(
            'admin.dashboard.presentation_schema_feature_openai_image_input',
            'OpenAI image input',
        ),
        'presentation_ai_image_analysis' => t(
            'admin.dashboard.presentation_schema_feature_ai_image_analysis',
            'AI image metadata',
        ),
        'presentation_simbrief_route_map' => t(
            'admin.dashboard.presentation_schema_feature_simbrief_route_map',
            'SimBrief route-map persistence',
        ),
        'presentation_navigation_cache' => t(
            'admin.dashboard.presentation_schema_feature_navigation_cache',
            'Navigation data cache',
        ),
        'presentation_navigation_account' => t(
            'admin.dashboard.presentation_schema_feature_navigation_account',
            'Navigation account storage',
        ),
        'presentation_telemetry_reporting' => t(
            'admin.dashboard.presentation_schema_feature_telemetry',
            'Telemetry reporting',
        ),
        'presentation_admin_gallery_report' => t(
            'admin.dashboard.presentation_schema_feature_admin_report',
            'Complete Admin gallery report',
        ),
        'presentation_gallery_attachment_usage' => t(
            'admin.dashboard.presentation_schema_feature_attachment_usage',
            'Gallery media counts',
        ),
        'presentation_gallery_attachment_usage_music' => t(
            'admin.dashboard.presentation_schema_feature_attachment_usage_music',
            'Music count catalog',
        ),
    ];
    $title = $labels[$feature] ?? t(
        'admin.dashboard.presentation_schema_feature_default',
        'Optional presentation schema capability',
    );
    if (str_contains($className, 'admin-system-health-row')) {
        view_render_admin_system_health_check($feature, $title, $status, $className);
        return;
    }
    $state = (string) ($status['state'] ?? 'unknown');

    echo
        '<article class="'
            . e($className)
            . '"><strong>'
            . e($title)
            . '</strong>';
    if ($state === 'available') {
        echo
            '<span>'
                . e(t(
                    'admin.dashboard.presentation_schema_available',
                    'Required optional database objects are installed and verified.',
                ))
                . '</span>';
    } elseif ($state === 'missing') {
        echo
            '<span>'
                . e(t(
                    'admin.dashboard.presentation_schema_missing',
                    'Database inspection succeeded and confirmed optional objects are missing. The affected presentation/report feature is omitted until its migration is applied.',
                ))
                . '</span>';
    } elseif ($state === 'disabled') {
        echo
            '<span>'
                . e(t(
                    'admin.dashboard.presentation_schema_disabled',
                    'This optional feature is intentionally disabled by configuration.',
                ))
                . '</span>';
    } else {
        echo
            '<span>'
                . e(t(
                    'admin.dashboard.presentation_schema_unknown',
                    'Optional database schema could not be verified. Safe core pages may continue without the affected presentation feature, while writes that depend on it are refused until inspection is healthy.',
                ))
                . '</span>';
        $requestId = trim((string) ($status['request_id'] ?? ''));
        if ($requestId !== '') {
            echo
                '<span>'
                    . e(t(
                        'public.request_reference',
                        'Reference: {request_id}',
                        ['request_id' => $requestId],
                    ))
                    . '</span>';
        }
    }
    $affectedObjects = array_values(array_filter(array_map(
        'strval',
        (array) ($status['affected_objects'] ?? []),
    )));
    if ($affectedObjects !== []) {
        echo
            '<span>'
                . e(t(
                    'admin.dashboard.security_schema_objects',
                    'Affected database objects: {objects}',
                    ['objects' => implode(', ', $affectedObjects)],
                ))
                . '</span>';
    }
    echo '</article>';
}

/**
 * Render the three-state NSFW Guard schema diagnosis.
 *
 * The card provides actionable operational guidance without exposing raw PDO
 * exceptions, SQL statements, connection details, or filesystem paths.
 *
 * @param array{state?:string} $status Aggregate NSFW Guard schema state used
 *     to select the administrator guidance.
 * @param string $className CSS class name for the card wrapper.
 * @return void Outputs the schema state and corresponding safe operational
 *     guidance.
 */
function view_render_admin_dashboard_nsfw_schema_card(
    array $status,
    string $className,
): void {
    if (str_contains($className, 'admin-system-health-row')) {
        view_render_admin_system_health_check(
            'nsfw_guard',
            t('admin.dashboard.health_title_nsfw', 'Sensitive content protection'),
            $status,
            $className,
        );
        return;
    }
    // Preserve the shared domain state for the standalone detailed card.
    $state = (string) ($status['state'] ?? 'unknown');
    echo
        '<article class="'
            . e($className)
            . '"><strong>'
            . e(t(
                'admin.dashboard.nsfw_schema_title',
                'NSFW Guard database status',
            ))
            . '</strong>';
    if ($state === 'available') {
        echo
            '<span>'
                . e(t(
                    'admin.dashboard.nsfw_schema_available',
                    'Required gallery and image protection columns are installed and verified.',
                ))
                . '</span>';
    } elseif ($state === 'missing') {
        echo
            '<span>'
                . e(t(
                    'admin.dashboard.nsfw_schema_missing',
                    'Database inspection succeeded and confirmed that an NSFW Guard column is missing. Apply pending database migrations before enabling this protection.',
                ))
                . '</span>';
    } elseif ($state === 'disabled') {
        echo
            '<span>'
                . e(t(
                    'admin.dashboard.nsfw_schema_disabled',
                    'This feature is intentionally disabled by configuration. Its database readiness does not currently affect public requests.',
                ))
                . '</span>';
    } else {
        echo
            '<span>'
                . e(t(
                    'admin.dashboard.nsfw_schema_unknown',
                    'The application could not inspect the database schema required by NSFW Guard. Check database connectivity, the selected database, and schema-inspection permissions. Public NSFW-sensitive requests are temporarily refused.',
                ))
                . '</span>';
        // $requestId stores a safe correlation value when telemetry initialized one.
        $requestId = trim((string) ($status['request_id'] ?? ''));
        if ($requestId !== '') {
            echo
                '<span>'
                    . e(t(
                        'public.request_reference',
                        'Reference: {request_id}',
                        ['request_id' => $requestId],
                    ))
                    . '</span>';
        }
    }
    // $affectedObjects contains only validated table.column identities from the health model.
    $affectedObjects = array_values(array_filter(array_map(
        'strval',
        (array) ($status['affected_objects'] ?? []),
    )));
    if ($affectedObjects !== []) {
        echo
            '<span>'
                . e(t(
                    'admin.dashboard.nsfw_schema_objects',
                    'Affected database objects: {objects}',
                    ['objects' => implode(', ', $affectedObjects)],
                ))
                . '</span>';
    }
    echo '</article>';
}

/**
 * Render the public search settings card.
 *
 * @param string $className Row presentation class supplied by the owning dashboard group.
 * @param array{public_home_search_enabled?:bool,
 *     admin_settings_urls?:array{general?:string},
 *     content_factory_defaults?:array<string,bool>} $model Prepared search state
 *     and the canonical central Settings destination.
 * @return void Emit the search preference and matching title/shortcut links to
 *     its Settings destination, retaining the owned Content return context.
 */
function view_render_admin_dashboard_public_search_card(
    string $className,
    array $model = [],
): void {
    $settingsUrl = (string) (
        $model['admin_settings_urls']['general'] ?? url_for('admin_settings')
    );

    echo
        '<form method="post" action="'
            . e(url_for('admin_public_search_settings'))
            . '" class="'
            . e($className)
            . ' admin-public-search-settings">'
            . csrf_field();
    echo
        '<input type="hidden" name="maintenance_return" value="content">'
            . '<div class="admin-content-copy"><strong><a href="'
            . e($settingsUrl) . '">'
            . e(t('admin.dashboard.content_search_title', 'Public search'))
            . '</a></strong>';
    view_render_admin_dashboard_compact_help(
        'admin-content-search-help',
        t('admin.dashboard.content_search_title', 'Public search'),
        [t(
            'admin.dashboard.public_search_hint',
            'Show a thin live search bar above the public front-page and gallery content. Gallery pages include a visitor checkbox to limit results to that gallery and its subgalleries.',
        )],
    );
    echo '</div>';
    echo
        '<div class="admin-content-controls"><label class="admin-compact-toggle"><input type="checkbox" name="public_home_search_enabled" value="1" aria-label="'
            . e(t('admin.dashboard.content_search_title', 'Public search'))
            . '"';
    view_render_admin_dashboard_factory_attributes($model, 'public_home_search_enabled');
    echo (!empty($model['public_home_search_enabled']) ? ' checked' : '')
            . '> <span>'
            . e(t('admin.dashboard.content_enabled', 'Enabled'))
            . '</span></label></div>';
    echo
        '<div class="admin-content-actions"><button type="submit" class="secondary" aria-label="'
            . e(
                t('admin.dashboard.content_save', 'Save') . ': '
                    . t(
                        'admin.dashboard.content_search_title',
                        'Public search',
                    ),
            )
            . '">'
            . e(t('admin.dashboard.content_save', 'Save'))
            . '</button><a href="'
            . e($settingsUrl)
            . '">'
            . e(t('admin.dashboard.content_search_settings', 'Search settings'))
            . '</a></div></form>';
}

/**
 * Render the clean public-path regeneration card.
 *
 * @param string $className Row presentation class supplied by the owning dashboard group.
 * @return void Emits stored-path regeneration with its existing explicit confirmation.
 */
function view_render_admin_dashboard_public_paths_card(string $className): void
{
    echo
        '<form method="post" action="'
            . e(url_for('admin_regenerate_paths'))
            . '" class="'
            . e($className)
            . '" onsubmit="return confirm(\''
            . e(t(
                'admin.dashboard.confirm_regenerate_paths',
                'Regenerate clean public URLs for all galleries and images?',
            ))
            . '\');">'
            . csrf_field();
    echo
        '<input type="hidden" name="maintenance_return" value="content"><div class="admin-content-copy"><strong>'
            . e(t('admin.dashboard.content_paths_title', 'Stored public paths'))
            . '</strong>';
    view_render_admin_dashboard_compact_help(
        'admin-content-paths-help',
        t('admin.dashboard.content_paths_title', 'Stored public paths'),
        [t(
            'admin.dashboard.content_paths_hint',
            'Rebuild saved gallery and photo URLs.',
        )],
    );
    echo '</div><div class="admin-content-actions"><button type="submit" class="secondary">'
            . e(t('admin.dashboard.regenerate_paths', 'Regenerate paths'))
            . '</button></div></form>';
}

/**
 * Render public crawler safety settings.
 *
 * @param string $className Row presentation class supplied by the owning dashboard group.
 * @param array{seo_guard_status?:array{enabled?:bool,logging_enabled?:bool,
 *     log_day?:string,log_count?:int,log_limit?:int},
 *     admin_settings_urls?:array{privacy?:string},
 *     content_factory_defaults?:array<string,bool>} $model Prepared protection
 *     preferences, bounded sampling counters and canonical Settings destination.
 * @return void Emit safety/logging switches, bounded diagnostics and matching
 *     title/shortcut links to the prepared privacy Settings destination.
 */
function view_render_admin_dashboard_seo_guard_card(
    string $className,
    array $model = [],
): void {
    $settingsUrl = (string) (
        $model['admin_settings_urls']['privacy'] ?? url_for('admin_settings')
    );
    $status = is_array($model['seo_guard_status'] ?? null)
        ? $model['seo_guard_status']
        : [
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
        ? t(
            'admin.dashboard.seo_guard_log_status',
            'Today logged {count}/{limit} sampled rejection event(s).',
            [
                'count' => (string) min($logCount, $logLimit),
                'limit' => (string) $logLimit,
            ],
        )
        : t(
            'admin.dashboard.seo_guard_log_status_empty',
            'No sampled rejection event has been logged today.',
        );

    echo
        '<form method="post" action="'
            . e(url_for('admin_seo_guard_settings'))
            . '" class="'
            . e($className)
            . ' admin-seo-guard-settings">'
            . csrf_field();
    echo
        '<input type="hidden" name="maintenance_return" value="content">'
            . '<div class="admin-content-copy"><strong><a href="'
            . e($settingsUrl) . '">'
            . e(t('admin.dashboard.seo_guard_title', 'Crawler safety'))
            . '</a></strong>';
    view_render_admin_dashboard_compact_help(
        'admin-content-crawler-help',
        t('admin.dashboard.seo_guard_title', 'Crawler safety'),
        [t(
            'admin.dashboard.seo_guard_hint',
            'Reject public URLs with unknown query parameters before they render as duplicate gallery pages. Suspicious requests return 404 with X-Robots-Tag: noindex, nofollow.',
        ), $logStatus],
    );
    echo '</div>';
    echo
        '<div class="admin-content-controls"><label class="admin-compact-toggle"><input type="checkbox" name="seo_request_guard_enabled" value="1"';
    view_render_admin_dashboard_factory_attributes($model, 'seo_request_guard_enabled');
    echo (!empty($status['enabled']) ? ' checked' : '')
            . '> <span>'
            . e(t(
                'admin.dashboard.content_crawler_enabled',
                'Protection enabled',
            ))
            . '</span></label>';
    echo
        '<label class="admin-compact-toggle"><input type="checkbox" name="seo_request_guard_logging_enabled" value="1"';
    view_render_admin_dashboard_factory_attributes($model, 'seo_request_guard_logging_enabled');
    echo (!empty($status['logging_enabled']) ? ' checked' : '')
            . '> <span>'
            . e(t(
                'admin.dashboard.content_crawler_logging',
                'Sample rejection logs',
            ))
            . '</span></label></div>';
    echo
        '<div class="admin-content-actions"><button type="submit" class="secondary" aria-label="'
            . e(
                t('admin.dashboard.content_save', 'Save') . ': '
                    . t('admin.dashboard.seo_guard_title', 'Crawler safety'),
            )
            . '">'
            . e(t('admin.dashboard.content_save', 'Save'))
            . '</button><a href="'
            . e($settingsUrl)
            . '">'
            . e(t(
                'admin.dashboard.content_crawler_settings',
                'Crawler settings',
            ))
            . '</a></div></form>';
}

/**
 * Render thumbnail cache actions.
 *
 * @param array{thumbnail_summary?:array{deferred?:bool,full_check?:bool},
 *     missing_thumbnail_variants?:int,thumbnail_compatibility_mode?:string,
 *     thumbnail_maintenance_last_check?:array{images_with_missing?:int,
 *     affected_gallery_count?:int,missing_variants?:int,checked_at?:string,
 *     images_scanned?:int,invalid_geometry_detected?:int,
 *     affected_galleries_truncated?:bool,affected_galleries?:list<array{id?:int,
 *     title?:string,folder_path?:string,images_with_missing?:int,
 *     missing_variants?:int}>},thumbnail_legacy_jpg_inventory?:array{
 *     available?:bool,registered_variant_count?:int,affected_image_count?:int,
 *     registered_bytes?:int,cleanup_recommended?:bool},
 *     browser_thumbnail_rebuild_config?:array{enabled:bool,
 *     source_endpoint?:string,upload_endpoint?:string,worker_count?:int,
 *     max_worker_count?:int,hard_worker_cap?:int,upload_limit_bytes?:int,
 *     batch_target_bytes?:int,max_items_per_batch?:int,source_chunk_bytes?:int,
 *     source_chunk_item_cap?:int,thumbnail_sizes?:list<int>,
 *     thumbnail_formats?:list<'jpg'|'webp'>,jpeg_quality?:int,webp_quality?:int,
 *     supported_mime_types?:list<string>}} $model Prepared thumbnail evidence,
 *     compatibility mode and the browser worker's endpoint/limit configuration.
 * @param string $className Card CSS class supplied by the dashboard composer.
 * @return void Emits thumbnail actions, visible scan evidence and contextual help.
 */
function view_render_admin_dashboard_thumbnail_card(
    array $model,
    string $className,
): void {
    $thumbnailSummary = view_admin_dashboard_array($model, 'thumbnail_summary');
    $missingThumbnailVariants = view_admin_dashboard_int(
        $model,
        'missing_thumbnail_variants',
    );
    $compatibilityMode = trim(
        (string) ($model['thumbnail_compatibility_mode'] ?? 'modern'),
    );
    if (!in_array($compatibilityMode, ['modern', 'legacy'], true)) {
        $compatibilityMode = 'modern';
    }
    $lastThumbnailCheck = is_array(
        $model['thumbnail_maintenance_last_check'] ?? null,
    )
        ? $model['thumbnail_maintenance_last_check']
        : [];
    $legacyJpgInventory = is_array(
        $model['thumbnail_legacy_jpg_inventory'] ?? null,
    )
        ? $model['thumbnail_legacy_jpg_inventory']
        : [];

    echo
        '<article class="'
            . e($className)
            . ' admin-thumbnail-maintenance-card">';
    echo
        '<h3>'
            . e(t(
                'admin.dashboard.thumbnail_maintenance',
                'Thumbnail maintenance',
            ))
            . '</h3>';
    if (!empty($thumbnailSummary['deferred'])) {
        echo
            '<div class="admin-media-evidence"><span>'
                . e(t('admin.dashboard.metric_not_checked', 'Not checked'))
                . '</span>';
        view_render_admin_dashboard_compact_help(
            'admin-media-thumbnail-status-help',
            t('admin.dashboard.thumbnail_maintenance', 'Thumbnail maintenance'),
            [t(
                    'admin.dashboard.thumbnail_check_deferred',
                    'Thumbnail status has not been scanned yet on this login. Use Create all thumbnails or the dedicated thumbnail tools when you need a full check.',
            )],
        );
        echo '</div>';
    } else {
        $thumbnailScopeText = !empty($thumbnailSummary['full_check'])
            ? t(
                'admin.dashboard.missing_stale_variants_full_check',
                'missing or stale variant(s) in the last full check.',
            )
            : t(
                'admin.dashboard.missing_stale_variants',
                'missing or stale variant(s) in the current sample.',
            );
        echo
            '<span>'
                . (int) $missingThumbnailVariants
                . ' '
                . e($thumbnailScopeText)
                . '</span>';
    }

    if ($lastThumbnailCheck) {
        $affectedImages = (int) (
            $lastThumbnailCheck['images_with_missing'] ?? 0
        );
        $affectedGalleries = (int) (
            $lastThumbnailCheck['affected_gallery_count'] ?? 0
        );
        $missingVariants = (int) ($lastThumbnailCheck['missing_variants'] ?? 0);
        $checkedAt = (string) ($lastThumbnailCheck['checked_at'] ?? '');
        echo '<div class="admin-thumbnail-check-result">';
        echo
            '<span><strong>'
                . e(t(
                    'admin.thumbnails.last_check_title',
                    'Last full thumbnail check',
                ))
                . '</strong>'
                . ($checkedAt !== '' ? ': ' . e($checkedAt) . ' UTC' : '')
                . '</span>';
        echo
            '<span>'
                . e(t(
                    'admin.thumbnails.last_check_summary',
                    'Checked {images} image(s). {affected_images} image(s) in {galleries} gallery/galleries need {variants} thumbnail variant(s).',
                    [
                        'images' => (string) (int) (
                            $lastThumbnailCheck['images_scanned'] ?? 0
                        ),
                        'affected_images' => (string) $affectedImages,
                        'galleries' => (string) $affectedGalleries,
                        'variants' => (string) $missingVariants,
                    ],
                ))
                . '</span>';
        if ((int) ($lastThumbnailCheck['invalid_geometry_detected'] ?? 0) > 0) {
            echo
                '<span class="muted">'
                    . e(t(
                        'admin.thumbnails.last_check_invalid_geometry',
                        '{count} wrong-ratio thumbnail file(s) were counted as needing regeneration. The check did not delete them.',
                        [
                            'count' => (string) (int) (
                                $lastThumbnailCheck['invalid_geometry_detected']
                                ?? 0
                            ),
                        ],
                    ))
                    . '</span>';
        }
        $affectedGalleryRows = array_values(array_filter(
            (array) ($lastThumbnailCheck['affected_galleries'] ?? []),
            static fn(mixed $row): bool => is_array($row),
        ));
        if ($affectedGalleryRows !== []) {
            echo
                '<details class="admin-thumbnail-check-galleries"><summary>'
                    . e(t(
                        'admin.thumbnails.last_check_galleries',
                        'Affected galleries',
                    ))
                    . '</summary><ul>';
            foreach (array_slice($affectedGalleryRows, 0, 10) as $galleryRow) {
                $galleryLabel = trim((string) ($galleryRow['title'] ?? ''));
                $folderPath = trim((string) ($galleryRow['folder_path'] ?? ''));
                if ($folderPath !== '') {
                    $galleryLabel .= $galleryLabel !== ''
                        ? ' (' . $folderPath . ')'
                        : $folderPath;
                }
                if ($galleryLabel === '') {
                    $galleryLabel = '#' . (int) ($galleryRow['id'] ?? 0);
                }
                echo
                    '<li>'
                        . e(t(
                            'admin.thumbnails.last_check_gallery_value',
                            '{gallery}: {images} image(s), {variants} variant(s).',
                            [
                                'gallery' => $galleryLabel,
                                'images' => (string) (int) (
                                    $galleryRow['images_with_missing'] ?? 0
                                ),
                                'variants' => (string) (int) (
                                    $galleryRow['missing_variants'] ?? 0
                                ),
                            ],
                        ))
                        . '</li>';
            }
            if (
                count($affectedGalleryRows) > 10
                || !empty($lastThumbnailCheck['affected_galleries_truncated'])
            ) {
                echo
                    '<li>'
                        . e(t(
                            'admin.thumbnails.last_check_more_galleries',
                            'More affected galleries were found; see the admin log for the stored check context.',
                        ))
                        . '</li>';
            }
            echo '</ul></details>';
        }
        echo '</div>';
    }

    echo
        '<details class="admin-media-details"><summary>'
            . e(t(
                'admin.cache_cleanup.thumbnail_details',
                'Compatibility, legacy files and metadata',
            ))
            . '</summary>';
    echo
        '<form method="post" action="'
            . e(url_for('admin_thumbnail_compatibility_settings'))
            . '" class="admin-thumbnail-compatibility-form">'
            . csrf_field();
    echo
        '<span class="admin-thumbnail-card-subtitle">'
            . e(t(
                'admin.thumbnails.compatibility_title',
                'Thumbnail compatibility mode',
            ))
            . '</span>';
    echo
        '<label class="admin-compact-toggle"><input type="radio" name="thumbnail_compatibility_mode" value="modern"'
            . ($compatibilityMode === 'modern' ? ' checked' : '')
            . '> <span><strong>'
            . e(t('admin.thumbnails.compatibility_modern_short', 'Modern'))
            . '</strong> '
            . e(t(
                'admin.thumbnails.compatibility_modern_help',
                'Generate WebP thumbnails only where the server can write WebP.',
            ))
            . '</span></label>';
    echo
        '<label class="admin-compact-toggle"><input type="radio" name="thumbnail_compatibility_mode" value="legacy"'
            . ($compatibilityMode === 'legacy' ? ' checked' : '')
            . '> <span><strong>'
            . e(t('admin.thumbnails.compatibility_legacy_short', 'Legacy'))
            . '</strong> '
            . e(t(
                'admin.thumbnails.compatibility_legacy_help',
                'Generate JPG fallback thumbnails plus WebP thumbnails.',
            ))
            . '</span></label>';
    echo
        '<button type="submit" class="secondary">'
            . e(t(
                'admin.thumbnails.save_compatibility_mode',
                'Save thumbnail mode',
            ))
            . '</button></form>';

    if (!empty($legacyJpgInventory['available'])) {
        $legacyVariantCount = max(
            0,
            (int) ($legacyJpgInventory['registered_variant_count'] ?? 0),
        );
        $legacyAffectedImages = max(
            0,
            (int) ($legacyJpgInventory['affected_image_count'] ?? 0),
        );
        $legacyBytes = max(
            0,
            (int) ($legacyJpgInventory['registered_bytes'] ?? 0),
        );
        echo
            '<div class="admin-thumbnail-check-result" data-legacy-jpg-inventory>';
        echo
            '<span><strong>'
                . e(t(
                    'admin.thumbnails.legacy_inventory_title',
                    'Legacy JPEG thumbnail inventory',
                ))
                . '</strong></span>';
        echo
            '<span data-legacy-jpg-inventory-summary>'
                . e(t(
                    'admin.thumbnails.legacy_inventory_summary',
                    '{variants} registered JPEG variant(s) across {images} image(s), approximately {bytes}.',
                    [
                        'variants' => (string) $legacyVariantCount,
                        'images' => (string) $legacyAffectedImages,
                        'bytes' => format_bytes($legacyBytes),
                    ],
                ))
                . '</span>';
        if (
            !empty($legacyJpgInventory['cleanup_recommended'])
            && $legacyVariantCount > 0
        ) {
            echo
                '<span class="muted" data-legacy-jpg-inventory-recommendation>'
                    . e(t(
                        'admin.thumbnails.legacy_inventory_recommendation',
                        'Modern mode is active. The existing manual cleanup can reclaim approximately this registered JPEG thumbnail space; opening this page does not delete anything.',
                    ))
                    . '</span>';
        }
        echo '</div>';
    }

    echo
        '<form method="post" action="'
            . e(url_for('admin_delete_legacy_jpg_thumbnails'))
            . '" class="admin-thumbnail-legacy-cleanup-form" data-delete-legacy-jpg-thumbnails-form>'
            . csrf_field();
    echo
        '<span>'
            . e(t(
                'admin.thumbnails.legacy_cleanup_hint',
                'Remove generated JPG thumbnails after switching to Modern mode. Original photos, WebP thumbnails, and DNG display masters are not touched.',
            ))
            . '</span>';
    echo
        '<button type="submit" class="secondary danger" data-delete-legacy-jpg-thumbnails data-confirm-message="'
            . e(t(
                'admin.thumbnails.legacy_cleanup_confirm',
                'Delete generated legacy JPG thumbnails? Original photos and WebP files will be kept.',
            ))
            . '">'
            . e(t(
                'admin.thumbnails.delete_legacy_jpg_thumbnails',
                'Remove legacy JPG thumbnails',
            ))
            . '</button>';
    echo '</form>';

    echo
        '<form method="post" action="'
            . e(url_for('admin_create_thumbnails'))
            . '" class="admin-thumbnail-metadata-actions-form" data-refresh-thumbnail-metadata-form>'
            . csrf_field();
    echo
        '<span>'
            . e(t(
                'admin.thumbnails.metadata_refresh_hint',
                'Refresh the thumbnail database from existing thumbnail files. Wrong-ratio files are deleted and are not displayed.',
            ))
            . '</span>';
    echo
        '<button type="button" class="secondary" data-refresh-thumbnail-metadata>'
            . e(t(
                'admin.thumbnails.refresh_metadata',
                'Refresh thumbnail database',
            ))
            . '</button>';
    echo '</form>';

    echo '</details>';
    echo
        '<form method="post" action="'
            . e(url_for('admin_check_thumbnail_maintenance'))
            . '" class="admin-thumbnail-check-actions-form" data-thumbnail-check-form data-thumbnail-progress-target="#admin-dashboard-thumbnail-progress">'
            . csrf_field();
    echo
        '<button type="submit" class="secondary" data-check-missing-thumbnails>'
            . e(t('admin.thumbnails.check_missing', 'Check missing thumbnails'))
            . '</button>';
    view_render_admin_dashboard_compact_help(
        'admin-media-thumbnail-check-help',
        t('admin.thumbnails.check_missing', 'Check missing thumbnails'),
        [t(
            'admin.thumbnails.check_missing_hint',
            'Check every imported photo and list galleries that need thumbnail generation. No thumbnails are created.',
        )],
    );
    echo '</form>';

    $browserRebuildConfig = is_array(
        $model['browser_thumbnail_rebuild_config'] ?? null,
    )
        ? $model['browser_thumbnail_rebuild_config']
        : ['enabled' => false];
    $browserRebuildJson = json_encode(
        $browserRebuildConfig,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
    if (!is_string($browserRebuildJson)) {
        $browserRebuildJson = '{}';
    }
    $browserRebuildDisabled = empty($browserRebuildConfig['enabled']);
    $hasUsableThumbnailCheck =
        !empty($lastThumbnailCheck)
        && (int) ($lastThumbnailCheck['images_scanned'] ?? 0) > 0;
    $hasMissingFromLastCheck =
        $hasUsableThumbnailCheck
        && (int) ($lastThumbnailCheck['images_with_missing'] ?? 0) > 0;
    $missingButtonDisabled = !$hasMissingFromLastCheck;
    $missingButtonStatus = $hasUsableThumbnailCheck
        ? (
            $hasMissingFromLastCheck
                ? t(
                    'admin.thumbnails.create_missing_ready_hint',
                    'Targeted repair is ready. Use Create missing thumbnails to process only the images reported by the last full check.',
                )
                : t(
                    'admin.thumbnails.create_missing_none_hint',
                    'The last full check found no missing or stale thumbnails. Run Check missing thumbnails again after importing or changing files.',
                )
        )
        : t(
            'admin.thumbnails.create_missing_requires_check',
            'Run Check missing thumbnails first to populate the targeted repair list.',
        );

    echo
        '<form method="post" action="'
            . e(url_for('admin_create_thumbnails'))
            . '" class="admin-thumbnail-cache-actions-form" data-thumbnail-maintenance-action-form data-thumbnail-progress-target="#admin-dashboard-thumbnail-progress">'
            . csrf_field();
    echo
        '<label class="admin-compact-toggle browser-thumbnail-rebuild-toggle"><input type="checkbox" name="browser_thumbnail_rebuild" value="1" data-browser-thumbnail-rebuild-toggle data-browser-thumbnail-rebuild-config="'
            . e($browserRebuildJson)
            . '"'
            . ($browserRebuildDisabled ? ' disabled' : ' checked')
            . '> <span><strong>'
            . e(t(
                'admin.thumbnails.browser_rebuild_label',
                'Browser-side thumbnail rebuild',
            ))
            . '</strong></span></label>';
    view_render_admin_dashboard_compact_help(
        'admin-media-browser-rebuild-help',
        t('admin.thumbnails.browser_rebuild_label', 'Browser-side thumbnail rebuild'),
        [t(
                'admin.thumbnails.browser_rebuild_help',
                'On by default. The server sends original files in source ZIP chunks, this browser creates thumbnails, then uploads prepared thumbnail ZIP batches back.',
        )],
    );
    echo
        '<div class="nav"><button type="button" class="secondary" data-create-all-thumbnails>'
            . e(t(
                'admin.dashboard.create_all_thumbnails',
                'Create all thumbnails',
            ))
            . '</button><button type="button" class="secondary" data-create-missing-thumbnails'
            . ($missingButtonDisabled ? ' disabled' : '')
            . ' aria-disabled="'
            . ($missingButtonDisabled ? 'true' : 'false')
            . '">'
            . e(t(
                'admin.thumbnails.create_missing',
                'Create missing thumbnails',
            ))
            . '</button></div>';
    echo
        '<span class="muted" data-create-missing-thumbnails-status>'
            . e($missingButtonStatus)
            . '</span></form>';

    echo
        '<form method="post" action="'
            . e(url_for('admin_delete_thumbnails'))
            . '" class="admin-thumbnail-cache-actions-form" data-delete-all-thumbnails-form>'
            . csrf_field();
    echo
        '<input type="hidden" name="confirmation_expected" value=""><input type="hidden" name="confirmation_typed" value="">';
    echo
        '<div class="nav"><button type="submit" class="secondary danger" data-delete-all-thumbnails data-confirm-words="archive,remove,clean,thumbs,purge,reset,delete,cache,media,confirm">'
            . e(t(
                'admin.dashboard.delete_all_thumbnails',
                'Delete all thumbnails',
            ))
            . '</button></div></form>';
    echo '</article>';
}

/**
 * Render the scheduled site-maintenance settings card.
 *
 * @param array{admin_settings_urls?:array{privacy?:string},
 *     site_maintenance_status?:array{enabled?:bool,
 *     request_trigger_enabled?:bool,request_trigger_supported?:bool,
 *     utc_time?:string,batch_size?:int,time_budget_seconds?:int,
 *     window_hours_value?:string,scheduled_at_utc?:string,
 *     window_ends_at_utc?:string,within_window?:bool,total_source_images?:int,
 *     last_completed_at?:string,last_completed_date?:string,
 *     last_success?:array{occurred_at?:string,duration_ms?:int},
 *     last_failure?:array{occurred_at?:string,operation?:string,
 *     error_code?:string,exception_class?:string},last_result?:array{busy?:bool},
 *     state?:array{status?:string,phase?:string,last_step_at?:string,
 *     totals?:array{errors?:list<string>,images_seen?:int,images_processed?:int,
 *     thumbs_created?:int,invalid_geometry_deleted?:int,images_deferred?:int,
 *     failed?:int},last_step_summary?:array{images_checked?:int,
 *     repair_attempts?:int,thumbnails_created?:int,deferred_images?:int}}}}
 *     $model Prepared scheduling values, bounded run evidence and Settings link.
 * @param string $className Card CSS classes from the dashboard composer.
 * @return void Emits linked scheduling controls with contextual scope help and expandable diagnostics.
 */
function view_render_admin_dashboard_site_maintenance_card(
    array $model,
    string $className,
): void {
    $status = view_admin_dashboard_array($model, 'site_maintenance_status');
    $state = is_array($status['state'] ?? null) ? $status['state'] : [];
    $lastResult = is_array($status['last_result'] ?? null)
        ? $status['last_result']
        : [];
    $lastSuccess = is_array($status['last_success'] ?? null)
        ? $status['last_success']
        : [];
    $lastFailure = is_array($status['last_failure'] ?? null)
        ? $status['last_failure']
        : [];
    $totals = is_array($state['totals'] ?? null) ? $state['totals'] : [];
    $lastStep = is_array($state['last_step_summary'] ?? null)
        ? $state['last_step_summary']
        : [];
    $enabled = !empty($status['enabled']);
    $requestTriggerEnabled = !empty($status['request_trigger_enabled']);
    $requestTriggerSupported = !empty($status['request_trigger_supported']);
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
    $errors = array_values(array_filter(array_map(
        'strval',
        (array) ($totals['errors'] ?? []),
    )));

    $settingsUrl = (string) (
        $model['admin_settings_urls']['privacy'] ?? url_for('admin_settings')
    );
    echo '<article class="' . e($className) . ' admin-site-maintenance-card">';
    echo
        '<h3><a href="' . e($settingsUrl) . '">'
            . e(t('admin.site_maintenance.title', 'Scheduled site maintenance'))
            . '</a>';
    view_render_admin_dashboard_compact_help(
        'admin-media-schedule-help',
        t('admin.site_maintenance.title', 'Scheduled site maintenance'),
        [t(
                'admin.site_maintenance.hint',
                'Daily maintenance scans the whole gallery after the configured UTC time. Valid thumbnails are reused. Only missing, stale, wrong-ratio, or metadata-missing variants are repaired. Admin log archiving is managed separately from the Admin Logs page.',
        )],
    );
    echo '<a class="admin-media-settings-shortcut" href="' . e($settingsUrl)
        . '" aria-label="'
        . e(t('admin.settings.open_centralized', 'Open centralized settings'))
        . '">↗</a></h3>';
    echo '<p class="admin-media-schedule-summary"><span data-dashboard-schedule-state'
        . ' data-enabled-label="' . e(t('admin.common.enabled', 'Enabled'))
        . '" data-disabled-label="' . e(t('admin.common.disabled', 'Disabled'))
        . '">'
        . e(t(
            $enabled ? 'admin.common.enabled' : 'admin.common.disabled',
            $enabled ? 'Enabled' : 'Disabled',
        )) . '</span> · <span data-dashboard-schedule-time>'
        . e($utcTime) . '</span> UTC</p>';

    echo
        '<details class="admin-media-details"><summary>'
            . e(t(
                'admin.cache_cleanup.schedule_details',
                'Schedule, results and diagnostics',
            ))
            . '</summary>';

    if ($stateStatus !== '') {
        echo
            '<div class="admin-site-maintenance-statusline"><strong>'
                . e(t('admin.site_maintenance.current_state', 'Current state'))
                . ':</strong> <span>'
                . e($stateStatus)
                . ($phase !== '' ? ' / ' . e($phase) : '')
                . '</span></div>';
        echo '<dl class="admin-site-maintenance-metrics">';
        echo
            '<div><dt>'
                . e(t('admin.site_maintenance.metric_checked', 'Checked'))
                . '</dt><dd>'
                . e((string) (int) ($totals['images_seen'] ?? 0))
                . ' / '
                . e($totalSourceImages > 0 ? (string) $totalSourceImages : '?')
                . '</dd></div>';
        echo
            '<div><dt>'
                . e(t(
                    'admin.site_maintenance.metric_repair_attempts',
                    'Repair attempts',
                ))
                . '</dt><dd>'
                . e((string) (int) ($totals['images_processed'] ?? 0))
                . '</dd></div>';
        echo
            '<div><dt>'
                . e(t('admin.site_maintenance.metric_created', 'Created'))
                . '</dt><dd>'
                . e((string) (int) ($totals['thumbs_created'] ?? 0))
                . '</dd></div>';
        echo
            '<div><dt>'
                . e(t(
                    'admin.site_maintenance.metric_invalid_removed',
                    'Invalid removed',
                ))
                . '</dt><dd>'
                . e((string) (int) ($totals['invalid_geometry_deleted'] ?? 0))
                . '</dd></div>';
        echo
            '<div><dt>'
                . e(t('admin.site_maintenance.metric_deferred', 'Deferred'))
                . '</dt><dd>'
                . e((string) (int) ($totals['images_deferred'] ?? 0))
                . '</dd></div>';
        echo
            '<div><dt>'
                . e(t('admin.site_maintenance.metric_failed', 'Failed'))
                . '</dt><dd>'
                . e((string) (int) ($totals['failed'] ?? 0))
                . '</dd></div>';
        echo '</dl>';
        if ($lastStepAt !== '') {
            echo
                '<span class="admin-site-maintenance-note">'
                    . e(t(
                        'admin.site_maintenance.last_step',
                        'Last safe slice: {time}, checked {checked}, repair attempts {repairs}, created {created}, deferred {deferred}.',
                        [
                            'time' => $lastStepAt . ' UTC',
                            'checked' => (string) (int) (
                                $lastStep['images_checked'] ?? 0
                            ),
                            'repairs' => (string) (int) (
                                $lastStep['repair_attempts'] ?? 0
                            ),
                            'created' => (string) (int) (
                                $lastStep['thumbnails_created'] ?? 0
                            ),
                            'deferred' => (string) (int) (
                                $lastStep['deferred_images'] ?? 0
                            ),
                        ],
                    ))
                    . '</span>';
        }
        if ($errors !== []) {
            echo
                '<span class="admin-site-maintenance-note is-warning">'
                    . e(t(
                        'admin.site_maintenance.last_diagnostic',
                        'Latest diagnostic: {message}',
                        ['message' => (string) end($errors)],
                    ))
                    . '</span>';
        }
    } elseif ($lastCompletedAt !== '') {
        echo
            '<span><strong>'
                . e(t(
                    'admin.site_maintenance.last_completed',
                    'Last completed',
                ))
                . ':</strong> '
                . e($lastCompletedAt)
                . ' UTC</span>';
    } else {
        echo
            '<span>'
                . e(t(
                    'admin.site_maintenance.not_run_yet',
                    'No maintenance cycle has completed yet.',
                ))
                . '</span>';
    }

    if ($lastCompletedDate !== '') {
        echo
            '<span class="admin-site-maintenance-note">'
                . e(t(
                    'admin.site_maintenance.last_completed_date',
                    'Last scheduled UTC date: {date}',
                    ['date' => $lastCompletedDate],
                ))
                . '</span>';
    }

    if (!empty($lastSuccess)) {
        $successAt = (string) ($lastSuccess['occurred_at'] ?? '');
        $successDurationMs = max(0, (int) ($lastSuccess['duration_ms'] ?? 0));
        echo
            '<span class="admin-site-maintenance-note">'
                . e(t(
                    'admin.site_maintenance.last_success_summary',
                    'Most recent successful maintenance: {time} UTC, duration {duration} ms.',
                    [
                        'time' => $successAt !== '' ? $successAt : '?',
                        'duration' => (string) $successDurationMs,
                    ],
                ))
                . '</span>';
    }

    if (!empty($lastFailure)) {
        echo
            '<span class="admin-site-maintenance-note is-warning">'
                . e(t(
                    'admin.site_maintenance.last_failure_summary',
                    'Most recent maintenance failure: {time} UTC, operation {operation}, code {code}, class {class}.',
                    [
                        'time' => (string) ($lastFailure['occurred_at'] ?? '?'),
                        'operation' => (string) (
                            $lastFailure['operation'] ?? 'maintenance'
                        ),
                        'code' => (string) (
                            $lastFailure['error_code'] ?? 'unknown'
                        ),
                        'class' => (string) (
                            $lastFailure['exception_class'] ?? 'unknown'
                        ),
                    ],
                ))
                . '</span>';
    }

    if (!empty($lastResult['busy'])) {
        echo
            '<span class="admin-site-maintenance-note is-warning">'
                . e(t(
                    'admin.site_maintenance.last_busy',
                    'The last maintenance call found another invocation already running.',
                ))
                . '</span>';
    }

    if ($requestTriggerEnabled && !$requestTriggerSupported) {
        echo
            '<span class="admin-site-maintenance-note is-warning">'
                . e(t(
                    'admin.site_maintenance.request_trigger_unsupported',
                    'This PHP runtime cannot detach background work from a visible page response, so request-triggered maintenance is suppressed to protect gallery latency. Use hosting cron or Run one safe check now instead.',
                ))
                . '</span>';
    }

    if ($scheduledAtUtc !== '' && $windowEndsAtUtc !== '') {
        echo
            '<span class="admin-site-maintenance-note">'
                . e(t(
                    'admin.site_maintenance.window_summary',
                    'Active UTC window: {start} to {end}. Current window state: {state}.',
                    [
                        'start' => $scheduledAtUtc,
                        'end' => $windowEndsAtUtc,
                        'state' => $withinWindow
                            ? t(
                                'admin.site_maintenance.window_active',
                                'active',
                            )
                            : t(
                                'admin.site_maintenance.window_inactive',
                                'inactive',
                            ),
                    ],
                ))
                . '</span>';
    }

    echo
        '<form method="post" action="'
            . e(url_for('admin_site_maintenance_settings'))
            . '" class="admin-site-maintenance-settings-form" data-dashboard-schedule-preferences>'
            . csrf_field();
    echo '<input type="hidden" name="site_maintenance_action" value="save">';
    echo
        '<label class="admin-compact-toggle"><input type="checkbox" name="site_maintenance_enabled" value="1"'
            . ($enabled ? ' checked' : '')
            . '> <span>'
            . e(t(
                'admin.site_maintenance.enabled',
                'Enable scheduled maintenance',
            ))
            . '</span></label>';
    echo
        '<label class="admin-compact-toggle"><input type="checkbox" name="site_maintenance_request_trigger_enabled" value="1"'
            . ($requestTriggerEnabled ? ' checked' : '')
            . '> <span>'
            . e(t(
                'admin.site_maintenance.request_trigger_enabled',
                'Let Admin dashboard requests trigger due maintenance',
            ))
            . '</span></label>';
    echo
        '<label><span>'
            . e(t('admin.site_maintenance.utc_time', 'Start time, UTC'))
            . '</span><input type="time" name="site_maintenance_utc_time" value="'
            . e($utcTime)
            . '"></label>';
    echo
        '<label><span>'
            . e(t(
                'admin.site_maintenance.window_hours',
                'Overall maintenance window, hours',
            ))
            . '</span><input type="number" name="site_maintenance_window_hours" min="0.25" max="24" step="0.25" value="'
            . e($windowHours)
            . '"></label>';
    echo
        '<label><span>'
            . e(t(
                'admin.site_maintenance.batch_size',
                'Max images checked per internal batch',
            ))
            . '</span><input type="number" name="site_maintenance_batch_size" min="1" max="50" value="'
            . $batchSize
            . '"></label>';
    echo
        '<label><span>'
            . e(t(
                'admin.site_maintenance.time_budget',
                'Time budget per call, seconds',
            ))
            . '</span><input type="number" name="site_maintenance_time_budget_seconds" min="3" max="120" value="'
            . $timeBudget
            . '"></label>';
    echo
        '<button type="submit" class="secondary">'
            . e(t('admin.site_maintenance.save', 'Save maintenance settings'))
            . '</button>';
    echo '</form>';

    echo '<div class="admin-site-maintenance-cron">';
    echo
        '<span><strong>'
            . e(t(
                'admin.site_maintenance.automatic_trigger',
                'Automatic trigger',
            ))
            . '</strong></span>';
    echo
        '<span>'
            . e(t(
                'admin.site_maintenance.automatic_trigger_hint',
                'When a normal public or Admin page is opened during the configured UTC window, the site starts one safe slice after the page response and then queues the next safe slice directly. Chained slices continue until the cycle finishes or the UTC window ends.',
            ))
            . '</span>';
    echo
        '<span>'
            . e(t(
                'admin.site_maintenance.external_cron_hint',
                'For completely unattended execution on a site with no traffic, use the CLI command from hosting cron: {command}',
                [
                    'command' =>
                        'php '
                            . dirname(dirname(__DIR__))
                            . '/scripts/site_maintenance.php --quiet',
                ],
            ))
            . '</span>';
    echo '</div>';

    echo '</details>';

    echo
        '<form method="post" action="'
            . e(url_for('admin_site_maintenance_settings'))
            . '" class="admin-site-maintenance-actions">'
            . csrf_field();
    echo '<div class="nav">';
    echo
        '<button type="submit" name="site_maintenance_action" value="run_now" class="secondary">'
            . e(t('admin.site_maintenance.run_now', 'Run one safe check now'))
            . '</button>';
    echo
        '<button type="submit" name="site_maintenance_action" value="reset_state" class="secondary">'
            . e(t(
                'admin.site_maintenance.reset_state',
                'Reset interrupted state',
            ))
            . '</button>';
    echo
        '<button type="submit" name="site_maintenance_action" value="rotate_token" class="secondary danger" onclick="return confirm('
            . e(json_encode(
                t(
                    'admin.site_maintenance.rotate_confirm',
                    'Rotate the hidden web-cron token? Existing external web cron URLs will stop working until updated.',
                ),
                JSON_UNESCAPED_UNICODE,
            ))
            . ');">'
            . e(t(
                'admin.site_maintenance.rotate_token',
                'Rotate hidden web-cron token',
            ))
            . '</button>';
    echo '</div>';
    echo '</form>';
    echo '</article>';
}

/**
 * Render the complete gallery archive card.
 *
 * @param string $className Class name value.
 * @return void Emits a linked archive name, separate scope help and matching shortcut.
 */
function view_render_admin_dashboard_archive_card(string $className): void
{
    $archiveUrl = url_for('download_all');
    echo
        '<article class="'
            . e($className)
            . '"><strong><a href="' . e($archiveUrl) . '">'
            . e(t('admin.dashboard.gallery_archive', 'Gallery archive'))
            . '</a></strong>';
    view_render_admin_dashboard_compact_help(
        'admin-media-archive-help',
        t('admin.dashboard.gallery_archive', 'Gallery archive'),
        [t(
                'admin.dashboard.gallery_archive_hint',
                'Download a complete ZIP archive through the existing route.',
        )],
    );
    echo '<a class="admin-media-settings-shortcut" href="'
            . e($archiveUrl) . '" aria-label="'
            . e(t(
                'admin.dashboard.download_all_galleries',
                'Download all galleries',
            ))
            . '">↧</a></article>';
}

/**
 * Render the media renamer shortcut card.
 *
 * @param string $className Class name value.
 * @return void Emits a linked renamer name, independent help and matching shortcut.
 */
function view_render_admin_dashboard_media_renamer_card(string $className): void
{
    $renamerUrl = url_for('admin_media_renamer');
    echo
        '<article class="'
            . e($className)
            . '"><strong><a href="' . e($renamerUrl) . '">'
            . e(t('admin.dashboard.media_renamer_title', 'Media renamer'))
            . '</a></strong>';
    view_render_admin_dashboard_compact_help(
        'admin-media-renamer-help',
        t('admin.dashboard.media_renamer_title', 'Media renamer'),
        [t(
                'admin.dashboard.media_renamer_hint',
                'Preview and apply physical filename cleanup while keeping database rows and generated derivatives aligned.',
        )],
    );
    echo '<a class="admin-media-settings-shortcut" href="'
            . e($renamerUrl) . '" aria-label="'
            . e(t('admin.dashboard.open_media_renamer', 'Open media renamer'))
            . '">↗</a></article>';
}

/**
 * Render the pending database migration card.
 *
 * @param string $className Class name value.
 * @return void Outputs the pending migration form and CSRF field.
 */
function view_render_admin_dashboard_migration_card(string $className): void
{
    echo
        '<form method="post" action="'
            . e(url_for('admin_run_migrations'))
            . '" class="'
            . e($className)
            . ' is-attention">'
            . csrf_field();
    echo
        '<strong>'
            . e(t('admin.dashboard.database_migrations', 'Database migrations'))
            . '</strong><span>'
            . e(t(
                'admin.dashboard.pending_migrations_hint',
                'Pending migrations must be applied before every admin feature is fully available.',
            ))
            . '</span><button type="submit" class="button is-update-pending">'
            . e(t(
                'admin.dashboard.run_database_migration',
                'Run database migration',
            ))
            . '</button></form>';
}
