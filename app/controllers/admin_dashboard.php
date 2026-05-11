<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_dashboard.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles request-level application logic for the related gallery feature.
 *
 * Responsibilities:
 *   - Validate and route incoming request data
 *   - Call service-layer functions where possible
 *   - Return redirects, rendered views, or HTTP responses
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

/**
 * Admin dashboard controller model.
 * 
 * This module renders the main admin dashboard, development-mode controls, migration notices, and migration execution. Theme customization remains outside this module.
 */

function cms_admin(): void
{
    require_admin();
    admin_render_profile_start('admin_dashboard');
    // Variable $pictureGameReady stores this steps working value.
    $pictureGameReady = admin_render_profile_schema('schema_picture_game', static fn (): bool => picture_game_schema_ready());
    // Variable $gpsMapReady stores this steps working value.
    $gpsMapReady = admin_render_profile_schema('schema_exif_gps', static fn (): bool => exif_gps_schema_ready());
    // Variable $votingReady stores this steps working value.
    $votingReady = admin_render_profile_schema('schema_gallery_voting', static fn (): bool => gallery_voting_schema_ready());
    // Variable $filenameDisplayReady stores this steps working value.
    $filenameDisplayReady = admin_render_profile_schema('schema_filename_display', static fn (): bool => gallery_filename_display_schema_ready());
    // Variable $migrationPending stores this steps working value.
    $migrationPending = admin_render_profile_schema('schema_pending_migrations', static fn (): bool => pending_migrations_exist());
    // Variable $accessReady stores this steps working value.
    $accessReady = admin_render_profile_schema('schema_gallery_access', static fn (): bool => gallery_access_schema_ready());
    // $backgroundSourceReady stores whether gallery background source data can be read without optional-column errors.
    $backgroundSourceReady = admin_render_profile_schema('schema_background_source', static fn (): bool => gallery_background_source_schema_ready());
    // $publicPathReady stores whether clean public URL paths can be read directly from gallery rows.
    $publicPathReady = admin_render_profile_schema('schema_public_paths', static fn (): bool => public_path_schema_ready());
    // $coverAssetReady stores whether uploaded gallery cover assets can be shown in the admin gallery list.
    $coverAssetReady = admin_render_profile_schema('schema_cover_asset', static fn (): bool => gallery_cover_asset_schema_ready());

    if ($pictureGameReady && $votingReady && admin_dashboard_self_heal_due('admin_dashboard_voting_game_sync_last', 300)) {
        // Self-heal voting/game state periodically instead of on every admin navigation.
        $repairedVotingGame = admin_render_profile_span('self_heal_voting_game_sync', static fn (): int => sync_gallery_voting_game_state());
        admin_render_profile_span('self_heal_voting_game_mark', static function (): void { admin_dashboard_mark_self_heal('admin_dashboard_voting_game_sync_last'); });
        if ($repairedVotingGame > 0) {
            admin_log_event('info', 'gallery.voting_game_synced', 'Admin dashboard repaired gallery voting/game settings.', [
                'gallery_count' => $repairedVotingGame,
            ]);
        }
    }

    if (admin_dashboard_parent_sync_needed()) {
        admin_render_profile_span('parent_id_sync', static function (): void { sync_gallery_parent_ids(); });
        admin_render_profile_span('parent_id_sync_store_fingerprint', static function (): void { admin_dashboard_store_parent_sync_fingerprint(); });
    }

    // Variable $galleries stores this steps working value.
    $galleries = admin_render_profile_db('dashboard_gallery_rows', static fn (): array => admin_dashboard_gallery_rows($accessReady, $gpsMapReady, $backgroundSourceReady, $filenameDisplayReady, $votingReady, $pictureGameReady, $publicPathReady, $coverAssetReady));
    admin_render_profile_set_counter('gallery_rows', count($galleries));
    // Variable $galleries stores the admin tree in display order, with manual sibling ordering respected.
    $galleries = admin_render_profile_span('order_gallery_tree', static fn (): array => admin_ordered_gallery_rows($galleries));
    admin_render_profile_set_counter('ordered_gallery_rows', count($galleries));
    // Variable $collapsedIds stores this steps working value.
    $collapsedIds = admin_render_profile_setting_read('collapsed_gallery_ids', static fn (): array => array_flip(collapsed_gallery_ids()));
    admin_render_profile_set_counter('collapsed_gallery_ids', count($collapsedIds));
    // $childrenByParent stores direct child ids once so row rendering does not rescan the full gallery list.
    $childrenByParent = admin_render_profile_span('gallery_children_index', static fn (): array => admin_gallery_children_by_parent($galleries));
    admin_render_profile_set_counter('parent_groups', count($childrenByParent));

    // $updatePending stores an intermediate value used by the surrounding gallery workflow.
    $updatePending = admin_render_profile_span('application_update_pending', static fn (): bool => application_update_pending());
    // $updateButtonClass stores an intermediate value used by the surrounding gallery workflow.
    $updateButtonClass = $updatePending ? 'button secondary is-update-pending' : 'button secondary';
    // $updateLabel stores an intermediate value used by the surrounding gallery workflow.
    $updateLabel = application_update_nav_label($updatePending);
    // $thumbnailSummary stores an intermediate value used by the surrounding gallery workflow.
    admin_render_profile_set_counter('thumbnail_maintenance_sample_limit', 1000);
    $thumbnailSummary = admin_render_profile_span('thumbnail_maintenance_summary_cached_read', static fn (): array => cached_thumbnail_maintenance_summary_if_available(null, 1000));
    // $totalGalleries stores an intermediate value used by the surrounding gallery workflow.
    $totalGalleries = count($galleries);
    // $totalImages stores an intermediate value used by the surrounding gallery workflow.
    $totalImages = 0;
    // $unpublishedGalleries stores an intermediate value used by the surrounding gallery workflow.
    $unpublishedGalleries = 0;
    // $privateGalleries stores an intermediate value used by the surrounding gallery workflow.
    $privateGalleries = 0;
    foreach ($galleries as $gallery) {
        $totalImages += (int) ($gallery['image_count'] ?? 0);
        if (gallery_effective_visibility($gallery) === 'unpublished') {
            $unpublishedGalleries++;
        } elseif (gallery_effective_visibility($gallery) === 'private') {
            $privateGalleries++;
        }
    }
    // $missingThumbnailVariants stores an intermediate value used by the surrounding gallery workflow.
    $missingThumbnailVariants = (int) ($thumbnailSummary['missing_variants'] ?? 0);
    // $adminTabs stores the reusable tab model rendered by the shared helper.
    $adminTabs = [
        ['id' => 'admin-tab-overview', 'label' => t('admin.dashboard.tab_overview', 'Overview')],
        ['id' => 'admin-tab-galleries', 'label' => t('admin.dashboard.tab_galleries', 'Galleries'), 'badge' => $totalGalleries],
        ['id' => 'admin-tab-maintenance', 'label' => t('admin.dashboard.tab_maintenance', 'Maintenance'), 'badge' => $migrationPending ? t('admin.dashboard.badge_action', 'Action') : null],
    ];

    admin_render_profile_set_counter('thumbnail_missing_variants', $missingThumbnailVariants);
    admin_render_profile_set_counter('thumbnail_maintenance_deferred', !empty($thumbnailSummary['deferred']) ? 1 : 0);

    admin_render_profile_span('render_header', static function (): void { render_header(t('admin.dashboard.page_title', 'Admin dashboard')); });
    echo '<section class="hero admin-dashboard-hero"><div><p class="admin-kicker">' . e(t('admin.dashboard.kicker', 'Admin')) . '</p><h1>' . e(t('admin.dashboard.title', 'Dashboard')) . '</h1><p class="muted">' . e(t('admin.dashboard.description', 'A focused workspace for gallery management, media maintenance, and system health.')) . '</p></div>';
    echo '<div class="admin-hero-actions">';
    echo '<a class="button" href="' . e(url_for('admin_new_gallery')) . '">' . e(t('admin.dashboard.create_gallery', 'Create gallery')) . '</a>';
    echo '<a class="button secondary" href="' . e(url_for('admin_upload')) . '">' . e(t('admin.dashboard.upload_photos', 'Upload photos')) . '</a>';
    if ($updatePending) {
        echo '<a class="' . e($updateButtonClass) . '" href="' . e(url_for('admin_update')) . '">' . e($updateLabel) . '</a>';
    }
    echo '</div></section>';

    // $adminNotice stores an intermediate value used by the surrounding gallery workflow.
    $adminNotice = (string) flash_message('admin_notice');
    if ($adminNotice !== '') {
        echo '<div class="notice">' . e($adminNotice) . '</div>';
    }
    if (isset($_GET['deleted_galleries'])) {
        echo '<div class="notice">' . e(t('admin.dashboard.notice_deleted_galleries', 'Deleted {count} gallery folder(s).', ['count' => (int) $_GET['deleted_galleries']])) . '</div>';
    } elseif (isset($_GET['delete_error'])) {
        echo '<div class="notice">' . e(t('admin.dashboard.notice_delete_failed', 'Gallery delete failed:')) . ' ' . e((string) $_GET['delete_error']) . '</div>';
    }
    if (isset($_GET['devmode_saved'])) {
        echo '<div class="notice">' . e(t('admin.dashboard.notice_devmode_saved', 'Dev mode setting saved.')) . '</div>';
    }
    if (isset($_GET['paths_regenerated'])) {
        echo '<div class="notice">' . e(t('admin.dashboard.notice_paths_regenerated', 'Regenerated clean public paths. Updated {gallery_count} gallery path(s) and {image_count} image path(s).', ['gallery_count' => (int) ($_GET['gallery_paths'] ?? 0), 'image_count' => (int) ($_GET['image_paths'] ?? 0)])) . '</div>';
    } elseif (isset($_GET['paths_error'])) {
        echo '<div class="notice">' . e(t('admin.dashboard.notice_paths_failed', 'Path regeneration failed:')) . ' ' . e((string) $_GET['paths_error']) . '</div>';
    }
    if (isset($_GET['migrations_ran'])) {
        echo '<div class="notice">' . e(t('admin.dashboard.notice_migrations_applied', 'Applied migrations:')) . ' ' . e((string) $_GET['migrations_ran']) . '.</div>';
    } elseif (isset($_GET['migrations_current'])) {
        echo '<div class="notice">' . e(t('admin.dashboard.notice_database_current', 'Database is already current.')) . '</div>';
    } elseif (isset($_GET['migration_failed'])) {
        echo '<div class="notice">' . e(t('admin.dashboard.notice_migration_failed', 'Migration failed:')) . ' ' . e((string) $_GET['migration_failed']) . '</div>';
    }
    echo '<div id="admin-dashboard-thumbnail-progress" class="admin-dashboard-progress-slot" aria-live="polite"></div>';

    admin_render_profile_span('render_admin_tabs', static function () use ($adminTabs): void { render_admin_tabs($adminTabs, 'admin-tab-overview'); });

    ob_start();
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.dashboard.overview_kicker', 'Overview')) . '</p><h2>' . e(t('admin.dashboard.overview_title', 'Admin at a glance')) . '</h2></div><p class="muted">' . e(t('admin.dashboard.overview_description', 'Use this page for immediate work. Dedicated tools stay on their own pages.')) . '</p></div>';
    echo '<section class="admin-metric-grid" aria-label="' . e(t('admin.dashboard.admin_summary', 'Admin summary')) . '">';
    echo '<article class="admin-metric-card"><span>' . e(t('admin.dashboard.metric_galleries', 'Galleries')) . '</span><strong>' . (int) $totalGalleries . '</strong><small>' . (int) $unpublishedGalleries . ' ' . e(t('admin.dashboard.metric_unpublished', 'unpublished')) . ', ' . (int) $privateGalleries . ' ' . e(t('admin.dashboard.metric_private', 'private')) . '</small></article>';
    echo '<article class="admin-metric-card"><span>' . e(t('admin.dashboard.metric_top_level_images', 'Top-level images')) . '</span><strong>' . (int) $totalImages . '</strong><small>' . e(t('admin.dashboard.metric_imported_images_hint', 'Imported images shown in gallery lists')) . '</small></article>';
    if (!empty($thumbnailSummary['deferred'])) {
        echo '<article class="admin-metric-card"><span>' . e(t('admin.dashboard.metric_thumbnail_gaps', 'Thumbnail gaps')) . '</span><strong>' . e(t('admin.dashboard.metric_not_checked', 'Not checked')) . '</strong><small>' . e(t('admin.dashboard.metric_thumbnail_check_deferred', 'Open thumbnail maintenance for an exact scan.')) . '</small></article>';
    } else {
        echo '<article class="admin-metric-card"><span>' . e(t('admin.dashboard.metric_thumbnail_gaps', 'Thumbnail gaps')) . '</span><strong>' . (int) $missingThumbnailVariants . '</strong><small>' . (int) ($thumbnailSummary['images_scanned'] ?? 0) . ' ' . e(t('admin.dashboard.metric_images_sampled', 'images sampled')) . '</small></article>';
    }
    echo '<article class="admin-metric-card"><span>' . e(t('admin.dashboard.metric_system_state', 'System state')) . '</span><strong>' . ($migrationPending ? t('admin.dashboard.badge_action', 'Action') : t('admin.dashboard.state_ready', 'Ready')) . '</strong><small>' . ($migrationPending ? t('admin.dashboard.state_migration_pending', 'Database migration pending') : t('admin.dashboard.state_no_migration_warning', 'No migration warning')) . '</small></article>';
    echo '</section>';
    if ($migrationPending) {
        render_admin_migration_notice('' . t('admin.dashboard.migration_notice', 'Some admin features still need database migrations.') . '');
    }
    if (empty($thumbnailSummary['deferred'])) {
        render_admin_thumbnail_maintenance_notice($thumbnailSummary);
    }
    echo '<section class="admin-quick-panel"><div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.dashboard.actions_kicker', 'Actions')) . '</p><h2>' . e(t('admin.dashboard.quick_actions', 'Quick actions')) . '</h2></div></div><div class="admin-action-grid">';
    echo '<form method="post" action="' . e(url_for('admin_discover')) . '" class="admin-action-card" data-refresh-galleries-form>' . csrf_field();
    echo '<strong>' . e(t('admin.dashboard.discover_folders', 'Discover folders')) . '</strong><span>' . e(t('admin.dashboard.discover_folders_hint', 'Scan the galleries directory for new folders.')) . '</span><button type="submit">' . e(t('admin.dashboard.check_new_folders', 'Check for new gallery folders')) . '</button></form>';
    echo '<div class="admin-action-card"><strong>' . e(t('admin.dashboard.gallery_tools', 'Gallery tools')) . '</strong><span>' . e(t('admin.dashboard.gallery_tools_hint', 'Create galleries or upload photos using the existing workflows.')) . '</span><div class="nav"><a class="button secondary" href="' . e(url_for('admin_new_gallery')) . '">' . e(t('admin.dashboard.create_empty_gallery', 'Create empty gallery')) . '</a><a class="button secondary" href="' . e(url_for('admin_upload')) . '">' . e(t('admin.dashboard.upload_photos', 'Upload photos')) . '</a></div></div>';
    echo '<form method="post" action="' . e(url_for('admin_delete_thumbnails')) . '" class="admin-action-card" data-delete-all-thumbnails-form>' . csrf_field();
    echo '<strong>' . e(t('admin.dashboard.media_tools', 'Media tools')) . '</strong><span>' . e(t('admin.dashboard.media_tools_hint', 'Generate thumbnails, delete generated thumbnail cache files, or download the complete gallery archive.')) . '</span>';
    echo '<input type="hidden" name="confirmation_expected" value=""><input type="hidden" name="confirmation_typed" value="">';
    echo '<div class="nav"><button type="button" class="secondary" data-create-all-thumbnails>' . e(t('admin.dashboard.create_all_thumbnails', 'Create all thumbnails')) . '</button><button type="submit" class="secondary danger" data-delete-all-thumbnails data-confirm-words="archive,remove,clean,thumbs,purge,reset,delete,cache,media,confirm">' . e(t('admin.dashboard.delete_all_thumbnails', 'Delete all thumbnails')) . '</button><a class="button secondary" href="' . e(url_for('download_all')) . '">' . e(t('admin.dashboard.download_all_galleries', 'Download all galleries')) . '</a></div></form>';
    echo '<div class="admin-action-card"><strong>' . e(t('admin.dashboard.maintenance', 'Maintenance')) . '</strong><span>' . e(t('admin.dashboard.maintenance_hint', 'Review logs, integrity, telemetry, and updates.')) . '</span><div class="nav"><a class="button secondary" href="' . e(url_for('admin_logs')) . '">' . e(t('admin.dashboard.logs', 'Logs')) . '</a><a class="button secondary" href="' . e(url_for('admin_integrity')) . '">' . e(t('admin.dashboard.integrity', 'Integrity')) . '</a><a class="button secondary" href="' . e(url_for('admin_telemetry')) . '">' . e(t('admin.dashboard.telemetry', 'Telemetry')) . '</a></div></div>';
    echo '<form method="post" action="' . e(url_for('admin_regenerate_paths')) . '" class="admin-action-card" onsubmit="return confirm(\'' . e(t('admin.dashboard.confirm_regenerate_paths', 'Regenerate clean public URLs for all galleries and images?')) . '\');">' . csrf_field();
    echo '<strong>' . e(t('admin.dashboard.public_paths', 'Public paths')) . '</strong><span>' . e(t('admin.dashboard.public_paths_hint', 'Regenerate clean public URLs for galleries and images.')) . '</span><button type="submit" class="secondary">' . e(t('admin.dashboard.regenerate_paths', 'Regenerate paths')) . '</button></form>';
    if ($migrationPending) {
        echo '<form method="post" action="' . e(url_for('admin_run_migrations')) . '" class="admin-action-card is-attention">' . csrf_field();
        echo '<strong>' . e(t('admin.dashboard.database_migrations', 'Database migrations')) . '</strong><span>' . e(t('admin.dashboard.database_migrations_hint', 'Some admin features need database migrations.')) . '</span><button type="submit" class="button is-update-pending">' . e(t('admin.dashboard.run_database_migration', 'Run database migration')) . '</button></form>';
    }
    echo '</div></section>';
    $overviewHtml = (string) ob_get_clean();
    render_admin_tab_panel('admin-tab-overview', $overviewHtml, true);

    ob_start();
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.dashboard.galleries_kicker', 'Galleries')) . '</p><h2>' . e(t('admin.dashboard.all_galleries', 'All galleries')) . '</h2></div><a class="button secondary" href="' . e(url_for('admin_upload')) . '">' . e(t('admin.dashboard.upload_photos', 'Upload photos')) . '</a></div>';
    echo '<form method="post" action="' . e(url_for('admin_bulk_galleries')) . '" data-gallery-bulk-form data-admin-gallery-order-form data-thumbnail-progress-target="#admin-dashboard-thumbnail-progress">' . csrf_field();
    echo '<section class="admin-gallery-workspace" aria-label="' . e(t('admin.dashboard.gallery_management', 'Gallery management')) . '">';
    echo '<div class="admin-gallery-command-panel">';
    echo '<div class="admin-image-order-toolbar admin-gallery-order-toolbar" data-admin-gallery-order-toolbar data-reorder-url="' . e(url_for('admin_reorder_galleries')) . '"><div><strong>' . e(t('admin.dashboard.tree_ordering', 'Tree ordering')) . '</strong><p class="muted">' . e(t('admin.dashboard.tree_ordering_hint', 'Drag a gallery thumbnail or title area to reorder. Move right to nest a gallery, or left to move it back out.')) . '</p></div><span class="admin-image-order-status" data-admin-gallery-order-status aria-live="polite">' . e(t('admin.dashboard.gallery_ordering_ready', 'Gallery ordering ready.')) . '</span></div>';
    echo '<div class="bulk-row admin-gallery-controls">';
    echo '<label>' . e(t('admin.dashboard.filter', 'Filter')) . '<select data-gallery-visibility-filter><option value="all">' . e(t('admin.dashboard.filter_all_statuses', 'All statuses')) . '</option><option value="unpublished">' . e(t('admin.dashboard.filter_only_unpublished', 'Only unpublished')) . '</option><option value="public">' . e(t('admin.dashboard.filter_only_public', 'Only public')) . '</option><option value="private">' . e(t('admin.dashboard.filter_only_private', 'Only private')) . '</option></select></label>';
    echo '<span class="muted admin-gallery-filter-summary" data-gallery-filter-summary></span>';
    echo '<label class="admin-gallery-select-all"><input type="checkbox" data-select-all="gallery_ids[]"> ' . e(t('admin.dashboard.select_displayed', 'Select displayed')) . '</label><label>' . e(t('admin.dashboard.bulk_action', 'Bulk action')) . '<select name="action"><option value="scan">' . e(t('admin.dashboard.bulk_scan_images', 'Scan/import images')) . '</option><option value="thumbs">' . e(t('admin.dashboard.bulk_create_thumbnails', 'Create thumbnails')) . '</option><option value="public">' . e(t('admin.dashboard.bulk_set_public', 'Set public')) . '</option><option value="unpublished">' . e(t('admin.dashboard.bulk_set_unpublished', 'Set unpublished')) . '</option><option value="private">' . e(t('admin.dashboard.bulk_set_private', 'Set private')) . '</option><option value="maps_on">' . e(t('admin.dashboard.bulk_enable_gps_maps', 'Enable GPS maps')) . '</option><option value="maps_off">' . e(t('admin.dashboard.bulk_disable_gps_maps', 'Disable GPS maps')) . '</option><option value="delete">' . e(t('admin.dashboard.bulk_delete_selected', 'Delete selected galleries')) . '</option>';
    if ($filenameDisplayReady) {
        echo '<option value="filenames_on">' . e(t('admin.dashboard.bulk_show_file_names', 'Show file names')) . '</option><option value="filenames_off">' . e(t('admin.dashboard.bulk_hide_file_names', 'Hide file names')) . '</option>';
    }
    if ($votingReady) {
        echo '<option value="vote_on">' . e(t('admin.dashboard.bulk_enable_voting', 'Enable voting')) . '</option><option value="vote_off">' . e(t('admin.dashboard.bulk_disable_voting', 'Disable voting')) . '</option>';
    }
    if ($pictureGameReady) {
        echo '<option value="game_on">' . e(t('admin.dashboard.bulk_enable_picture_game', 'Enable picture game')) . '</option><option value="game_off">' . e(t('admin.dashboard.bulk_disable_picture_game', 'Disable picture game')) . '</option>';
    }
    echo '</select></label><button type="submit">' . e(t('admin.dashboard.apply', 'Apply')) . '</button><button type="button" class="secondary" data-gallery-tree-action="collapse-all">' . e(t('admin.dashboard.collapse_all', 'Collapse all')) . '</button><button type="button" class="secondary" data-gallery-tree-action="expand-all">' . e(t('admin.dashboard.expand_all', 'Expand all')) . '</button></div></div>';
    echo '<div class="admin-gallery-table-shell"><table class="admin-gallery-order-table admin-gallery-tree-table" data-admin-gallery-order-table><thead><tr><th class="admin-gallery-select-heading">' . e(t('admin.dashboard.column_select', 'Select')) . '</th><th>' . e(t('admin.dashboard.column_gallery', 'Gallery')) . '</th><th>' . e(t('admin.dashboard.column_state', 'State')) . '</th><th>' . e(t('admin.dashboard.column_features', 'Features')) . '</th><th class="admin-gallery-count-heading">' . e(t('admin.dashboard.column_images', 'Images')) . '</th><th class="admin-gallery-actions-heading">' . e(t('admin.dashboard.column_actions', 'Actions')) . '</th></tr></thead><tbody>';
    foreach ($galleries as $gallery) {
        // Variable $depth stores this steps working value.
        $depth = substr_count((string) $gallery['folder_path'], '/');
        // Variable $hasChildren stores this steps working value.
        $hasChildren = !empty($childrenByParent[(int) $gallery['id']]);
        // Variable $isCollapsed stores this steps working value.
        $isCollapsed = isset($collapsedIds[(int) $gallery['id']]);
        echo '<tr class="' . ($depth > 0 ? 'is-subgallery' : '') . ($isCollapsed ? ' is-collapsed' : '') . '" data-gallery-row data-gallery-id="' . (int) $gallery['id'] . '" data-parent-id="' . (int) ($gallery['parent_id'] ?? 0) . '" data-depth="' . $depth . '" data-gallery-visibility="' . e(gallery_effective_visibility($gallery)) . '" data-gallery-title="' . e((string) $gallery['title']) . '" data-gallery-url="' . e(gallery_public_url($gallery)) . '" style="--gallery-depth: ' . min($depth, 8) . ';"><td><input type="checkbox" name="gallery_ids[]" value="' . (int) $gallery['id'] . '"></td>';
        // Variable $depthClass stores this steps working value.
        $depthClass = 'tree-depth-' . min($depth, 8);
        // $previewUrl stores a small non-blocking gallery preview image for faster visual scanning.
        $previewUrl = admin_gallery_preview_url($gallery);
        echo '<td class="admin-gallery-title-cell"><div class="admin-gallery-summary" data-admin-gallery-drag-zone title="' . e(t('admin.dashboard.drag_gallery_hint', 'Drag the thumbnail, path text, or empty gallery area to reorder or nest. Click the gallery name to open it.')) . '"><span class="admin-gallery-depth-rail" aria-hidden="true"></span>';
        if ($previewUrl !== '') {
            echo '<span class="admin-gallery-preview" role="img" aria-label="' . e(t('admin.dashboard.preview_for', 'Preview for')) . ' ' . e((string) $gallery['title']) . '"><img src="' . e($previewUrl) . '" alt="" loading="lazy" decoding="async"></span>';
        } else {
            echo '<span class="admin-gallery-preview is-empty" aria-hidden="true"><span>' . e(t('admin.dashboard.empty_gallery_preview', 'Gallery')) . '</span></span>';
        }
        echo '<div class="admin-gallery-summary-text"><span class="tree-title ' . e($depthClass) . '">' . ($hasChildren ? '<button type="button" class="tree-toggle" data-gallery-toggle="' . (int) $gallery['id'] . '" aria-expanded="' . ($isCollapsed ? 'false' : 'true') . '">' . ($isCollapsed ? '+' : '-') . '</button>' : '<span class="tree-spacer" aria-hidden="true"></span>') . ($depth > 0 ? '<span class="tree-branch" aria-hidden="true"></span>' : '') . '<a class="admin-gallery-title-link" href="' . e(gallery_public_url($gallery)) . '">' . e($gallery['title']) . '</a></span><span class="admin-gallery-path">' . e($gallery['folder_path']) . '</span>' . ((string) ($gallery['parent_title'] ?: '') !== '' ? '<span class="admin-gallery-parent">' . e(t('admin.dashboard.parent_label', 'Parent:')) . ' ' . e((string) $gallery['parent_title']) . '</span>' : '') . '</div></div></td>';
        echo '<td class="admin-gallery-state-cell"><span class="admin-gallery-status-pill is-' . e(gallery_effective_visibility($gallery)) . '">' . e(gallery_visibility_label(gallery_effective_visibility($gallery))) . '</span>';
        if ($accessReady) {
            // $accessLabel stores an intermediate value used by the surrounding gallery workflow.
            $accessLabel = (string) ($gallery['access_mode'] ?? 'normal') === 'password' ? (!empty($gallery['access_password_hash']) ? '' . t('admin.dashboard.access_password_locked', 'Password locked') . '' : '' . t('admin.dashboard.access_direct_link_token', 'Direct-link token') . '') : '' . t('admin.dashboard.access_no_password', 'No password') . '';
            echo '<span class="admin-gallery-access-label">' . e($accessLabel) . '</span>';
        }
        echo '</td><td class="admin-gallery-feature-cell"><span class="admin-gallery-feature" title="' . e(t('admin.dashboard.feature_maps', 'Maps')) . '">M ' . render_admin_feature_flag($gpsMapReady && (int) ($gallery['gps_map_enabled'] ?? 0) === 1, '✓', '' . t('admin.dashboard.feature_gps_maps_enabled', 'GPS maps enabled') . '') . '</span>';
        echo '<span class="admin-gallery-feature" title="' . e(t('admin.dashboard.feature_background', 'Background')) . '">B ' . render_admin_feature_flag($backgroundSourceReady && gallery_background_source($gallery) !== null, '✓', '' . t('admin.dashboard.feature_custom_background_set', 'Custom gallery background set') . '') . '</span>';
        if ($filenameDisplayReady) {
            echo '<span class="admin-gallery-feature" title="' . e(t('admin.dashboard.feature_file_names_shown', 'File names shown')) . '">N ' . render_admin_feature_flag((int) ($gallery['show_filenames'] ?? 0) === 1, '✓', '' . t('admin.dashboard.feature_file_names_are_shown', 'File names are shown') . '') . '</span>';
        }
        if ($votingReady) {
            echo '<span class="admin-gallery-feature" title="' . e(t('admin.dashboard.feature_voting', 'Voting')) . '">V ' . render_admin_feature_flag((int) ($gallery['voting_enabled'] ?? 0) === 1, '✓', '' . t('admin.dashboard.feature_voting_enabled', 'Voting enabled') . '') . '</span>';
        }
        if ($pictureGameReady) {
            echo '<span class="admin-gallery-feature" title="' . e(t('admin.dashboard.feature_game', 'Game')) . '">G ' . render_admin_feature_flag((int) ($gallery['picture_game_enabled'] ?? 0) === 1, '✓', '' . t('admin.dashboard.feature_picture_game_enabled', 'Picture game enabled') . '') . '</span>';
        }
        echo '</td><td class="admin-gallery-image-count"><strong>' . (int) $gallery['image_count'] . '</strong></td><td class="nav gallery-row-actions">';
        echo '<div class="gallery-row-action-set" aria-label="' . e(t('admin.dashboard.actions_for', 'Actions for')) . ' ' . e((string) $gallery['title']) . '">';
        echo '<a class="gallery-row-action is-edit-action" href="' . e(url_for('admin_edit_gallery', ['id' => $gallery['id']])) . '" aria-label="' . e(t('admin.dashboard.edit_action', 'Edit')) . ' ' . e((string) $gallery['title']) . '" title="' . e(t('admin.dashboard.edit_gallery', 'Edit gallery')) . '"><span class="gallery-row-action-icon" aria-hidden="true">✎</span><span class="admin-visually-hidden">' . e(t('admin.dashboard.edit', 'Edit')) . '</span></a>';
        echo '<button type="submit" class="secondary gallery-row-action is-thumbnail-action" name="thumbnail_gallery_id" value="' . (int) $gallery['id'] . '" formaction="' . e(url_for('admin_create_thumbnails')) . '" aria-label="' . e(t('admin.dashboard.create_thumbnails_for', 'Create thumbnails for')) . ' ' . e((string) $gallery['title']) . '" title="' . e(t('admin.dashboard.create_thumbnails', 'Create thumbnails')) . '"><span class="gallery-row-action-icon" aria-hidden="true">▧</span><span class="admin-visually-hidden">' . e(t('admin.dashboard.thumbs', 'Thumbs')) . '</span></button>';
        echo '</div></td></tr>';
    }
    echo '</tbody></table></div></section></form>';
    $galleriesHtml = (string) ob_get_clean();
    admin_render_profile_span('render_galleries_tab_panel', static function () use ($galleriesHtml): void { render_admin_tab_panel('admin-tab-galleries', $galleriesHtml, false); });

    ob_start();
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.dashboard.maintenance_kicker', 'Maintenance')) . '</p><h2>' . e(t('admin.dashboard.system_tools', 'System tools')) . '</h2></div><p class="muted">' . e(t('admin.dashboard.system_tools_hint', 'Operational tools remain on their dedicated pages. This tab keeps only useful shortcuts and active maintenance controls.')) . '</p></div>';
    echo '<div class="admin-maintenance-grid">';
    echo '<article class="admin-maintenance-card"><strong>' . e(t('admin.dashboard.logs', 'Logs')) . '</strong><span>' . e(t('admin.dashboard.logs_hint', 'Review operational events, failures, and workflow status.')) . '</span><a class="button secondary" href="' . e(url_for('admin_logs')) . '">' . e(t('admin.dashboard.open_logs', 'Open logs')) . '</a></article>';
    echo '<article class="admin-maintenance-card"><strong>' . e(t('admin.dashboard.telemetry', 'Telemetry')) . '</strong><span>' . e(t('admin.dashboard.telemetry_hint', 'Inspect anonymous usage telemetry without collecting personal data.')) . '</span><a class="button secondary" href="' . e(url_for('admin_telemetry')) . '">' . e(t('admin.dashboard.open_telemetry', 'Open telemetry')) . '</a></article>';
    echo '<article class="admin-maintenance-card"><strong>' . e(t('admin.dashboard.integrity', 'Integrity')) . '</strong><span>' . e(t('admin.dashboard.integrity_hint', 'Check core files and deployment health.')) . '</span><a class="button secondary" href="' . e(url_for('admin_integrity')) . '">' . e(t('admin.dashboard.run_integrity_check', 'Run integrity check')) . '</a></article>';
    echo '<article class="admin-maintenance-card"><strong>' . e(t('admin.dashboard.updates', 'Updates')) . '</strong><span>' . e(t('admin.dashboard.updates_hint', 'Check and apply project updates.')) . '</span><a class="' . e($updateButtonClass) . '" href="' . e(url_for('admin_update')) . '">' . e($updateLabel) . '</a></article>';
    echo '<form method="post" action="' . e(url_for('admin_regenerate_paths')) . '" class="admin-maintenance-card" onsubmit="return confirm(\'' . e(t('admin.dashboard.confirm_regenerate_paths', 'Regenerate clean public URLs for all galleries and images?')) . '\');">' . csrf_field();
    echo '<strong>' . e(t('admin.dashboard.public_paths', 'Public paths')) . '</strong><span>' . e(t('admin.dashboard.public_paths_hint', 'Regenerate clean public URLs for galleries and images.')) . '</span><button type="submit" class="secondary">' . e(t('admin.dashboard.regenerate_paths', 'Regenerate paths')) . '</button></form>';
    echo '<article class="admin-maintenance-card"><strong>' . e(t('admin.dashboard.gallery_archive', 'Gallery archive')) . '</strong><span>' . e(t('admin.dashboard.gallery_archive_hint', 'Download a complete ZIP archive through the existing route.')) . '</span><a class="button secondary" href="' . e(url_for('download_all')) . '">' . e(t('admin.dashboard.download_all_galleries', 'Download all galleries')) . '</a></article>';
    echo '<form method="post" action="' . e(url_for('admin_delete_thumbnails')) . '" class="admin-maintenance-card" data-delete-all-thumbnails-form>' . csrf_field();
    echo '<strong>' . e(t('admin.dashboard.thumbnail_maintenance', 'Thumbnail maintenance')) . '</strong>';
    if (!empty($thumbnailSummary['deferred'])) {
        echo '<span>' . e(t('admin.dashboard.thumbnail_check_deferred', 'Thumbnail status has not been scanned yet on this login. Use Create all thumbnails or the dedicated thumbnail tools when you need a full check.')) . '</span>';
    } else {
        echo '<span>' . (int) $missingThumbnailVariants . ' ' . e(t('admin.dashboard.missing_stale_variants', 'missing or stale variant(s) in the current sample.')) . '</span>';
    }
    echo '<input type="hidden" name="confirmation_expected" value=""><input type="hidden" name="confirmation_typed" value="">';
    echo '<div class="nav"><button type="button" class="secondary" data-create-all-thumbnails>' . e(t('admin.dashboard.create_all_thumbnails', 'Create all thumbnails')) . '</button><button type="submit" class="secondary danger" data-delete-all-thumbnails data-confirm-words="archive,remove,clean,thumbs,purge,reset,delete,cache,media,confirm">' . e(t('admin.dashboard.delete_all_thumbnails', 'Delete all thumbnails')) . '</button></div></form>';
    if ($migrationPending) {
        echo '<form method="post" action="' . e(url_for('admin_run_migrations')) . '" class="admin-maintenance-card is-attention">' . csrf_field();
        echo '<strong>Database migrations</strong><span>' . e(t('admin.dashboard.pending_migrations_hint', 'Pending migrations must be applied before every admin feature is fully available.')) . '</span><button type="submit" class="button is-update-pending">Run database migration</button></form>';
    }
    echo '</div>';
    $maintenanceHtml = (string) ob_get_clean();
    admin_render_profile_span('render_maintenance_tab_panel', static function () use ($maintenanceHtml): void { render_admin_tab_panel('admin-tab-maintenance', $maintenanceHtml, false); });

    render_admin_devmode_panel();
    render_admin_render_profile_panel();
    admin_render_profile_span('render_footer', static function (): void { render_footer(); });
}


/**
 * Return admin dashboard gallery rows with only columns used by the table.
 *
 * Optional columns are selected only when their migrations are present. This
 * keeps partially upgraded installations safe while avoiding SELECT * in the
 * dashboard hot path.
 */
function admin_dashboard_gallery_rows(bool $accessReady, bool $gpsMapReady, bool $backgroundSourceReady, bool $filenameDisplayReady, bool $votingReady, bool $pictureGameReady, bool $publicPathReady, bool $coverAssetReady): array
{
    // $selects stores the explicit gallery columns required by dashboard rendering.
    $selects = [
        'g.id',
        'g.parent_id',
        'g.folder_path',
        'g.slug',
        'g.title',
        'g.sort_order',
        'g.visibility',
        'parent.title AS parent_title',
        'COALESCE(image_counts.image_count, 0) AS image_count',
    ];

    $selects[] = $publicPathReady ? 'g.url_path' : "'' AS url_path";
    $selects[] = $accessReady ? 'g.access_mode' : "'normal' AS access_mode";
    $selects[] = $accessReady ? 'g.access_listing' : "'listed' AS access_listing";
    $selects[] = $gpsMapReady ? 'g.gps_map_enabled' : '0 AS gps_map_enabled';
    $selects[] = $backgroundSourceReady ? 'g.background_source' : 'NULL AS background_source';
    $selects[] = $filenameDisplayReady ? 'g.show_filenames' : '0 AS show_filenames';
    $selects[] = $votingReady ? 'g.voting_enabled' : '0 AS voting_enabled';
    $selects[] = $pictureGameReady ? 'g.picture_game_enabled' : '0 AS picture_game_enabled';
    $selects[] = $coverAssetReady ? 'g.cover_image_path' : 'NULL AS cover_image_path';

    // $sql stores a one-pass gallery query with image counts pre-aggregated by gallery.
    $sql = 'SELECT ' . implode(', ', $selects) . "
        FROM galleries g
        LEFT JOIN galleries parent ON parent.id = g.parent_id
        LEFT JOIN (
            SELECT gallery_id, COUNT(id) AS image_count
            FROM images
            WHERE relative_path NOT LIKE '%/%'
            GROUP BY gallery_id
        ) image_counts ON image_counts.gallery_id = g.id
        ORDER BY COALESCE(g.parent_id, 0), g.sort_order, g.title";

    return db()->query($sql)->fetchAll();
}

/**
 * Return direct child gallery ids indexed by parent id for dashboard rendering.
 *
 * @param array<int, array<string, mixed>> $rows Gallery rows already loaded for the Admin table.
 * @return array<int, array<int, int>>
 */
function admin_gallery_children_by_parent(array $rows): array
{
    // $childrenByParent stores direct child ids by normalized parent id.
    $childrenByParent = [];
    foreach ($rows as $row) {
        // $parentId stores zero for root galleries and the parent id for subgalleries.
        $parentId = (int) ($row['parent_id'] ?? 0);
        $childrenByParent[$parentId][] = (int) ($row['id'] ?? 0);
    }
    return $childrenByParent;
}

/**
 * Return a small gallery preview URL for the admin gallery table.
 *
 * The dashboard uses existing generated thumbnails when available and falls back
 * through the same cover-selection rules used by public gallery cards. Nothing
 * is generated while rendering the table, so repeated admin navigation stays
 * cheap.
 */
function admin_gallery_preview_url(array $gallery): string
{
    admin_render_profile_count('preview_requests');

    return admin_render_profile_span('gallery_preview_url', static function () use ($gallery): string {
        // $coverAssetUrl stores an uploaded gallery-specific cover asset when the optional column exists.
        $coverAssetUrl = gallery_cover_asset_url($gallery, false);
        if ($coverAssetUrl !== '') {
            admin_render_profile_count('preview_cover_asset_hits');
            return $coverAssetUrl;
        }

        // $cover stores the explicit or first direct image for this gallery.
        $cover = admin_render_profile_db('preview_direct_cover_lookup', static fn (): ?array => gallery_cover_image((int) ($gallery['id'] ?? 0), false));
        if ($cover) {
            admin_render_profile_count('preview_direct_cover_hits');
            return admin_render_profile_span('preview_direct_thumbnail_url', static fn (): string => thumbnail_url($cover, 300));
        }

        foreach (admin_render_profile_db('preview_collage_cover_lookup', static fn (): array => gallery_cover_collage_images((int) ($gallery['id'] ?? 0), false, 1)) as $descendantCover) {
            admin_render_profile_count('preview_collage_cover_hits');
            return admin_render_profile_span('preview_collage_thumbnail_url', static fn (): string => thumbnail_url($descendantCover, 300));
        }

        admin_render_profile_count('preview_empty');
        return '';
    });
}

/**
 * Return true when a periodic dashboard repair task may run again.
 */
function admin_dashboard_self_heal_due(string $settingKey, int $ttlSeconds): bool
{
    // $lastRun stores the Unix timestamp for the last successful repair attempt.
    $lastRun = (int) admin_render_profile_setting_read('self_heal_last_run_setting', static fn (): string => app_setting($settingKey, '0'));
    return $lastRun <= 0 || time() - $lastRun >= max(60, $ttlSeconds);
}

/**
 * Remember that a periodic dashboard repair task was attempted.
 */
function admin_dashboard_mark_self_heal(string $settingKey): void
{
    admin_render_profile_setting_write('self_heal_last_run_setting_write', static function () use ($settingKey): void { set_app_setting($settingKey, (string) time()); });
}

/**
 * Build a cheap fingerprint for gallery hierarchy state used by parent-id repair.
 */
function admin_dashboard_parent_sync_fingerprint(): string
{
    try {
        // $row stores aggregate gallery data that changes when indexed gallery rows change.
        $row = admin_render_profile_db('parent_sync_fingerprint_query', static fn (): array => db()->query("SELECT COUNT(*) AS gallery_count, COALESCE(MAX(id), 0) AS newest_id, COALESCE(MAX(updated_at), '') AS newest_updated_at, COALESCE(SUM(CHAR_LENGTH(folder_path)), 0) AS path_length_sum FROM galleries")->fetch() ?: []);
    } catch (Throwable) {
        return '';
    }

    return hash('sha256', implode('|', [
        (string) ($row['gallery_count'] ?? '0'),
        (string) ($row['newest_id'] ?? '0'),
        (string) ($row['newest_updated_at'] ?? ''),
        (string) ($row['path_length_sum'] ?? '0'),
    ]));
}

/**
 * Return true when dashboard parent-id repair should run for current gallery rows.
 */
function admin_dashboard_parent_sync_needed(): bool
{
    // $fingerprint stores the current gallery hierarchy fingerprint.
    $fingerprint = admin_dashboard_parent_sync_fingerprint();
    if ($fingerprint === '') {
        return true;
    }
    return !hash_equals((string) admin_render_profile_setting_read('parent_sync_fingerprint_setting', static fn (): string => app_setting('admin_dashboard_parent_sync_fingerprint', '')), $fingerprint);
}

/**
 * Store the current parent-id repair fingerprint after synchronization.
 */
function admin_dashboard_store_parent_sync_fingerprint(): void
{
    // $fingerprint stores the post-repair gallery hierarchy fingerprint.
    $fingerprint = admin_dashboard_parent_sync_fingerprint();
    if ($fingerprint !== '') {
        admin_render_profile_setting_write('parent_sync_fingerprint_setting_write', static function () use ($fingerprint): void { set_app_setting('admin_dashboard_parent_sync_fingerprint', $fingerprint); });
    }
}

/**
 * Return gallery rows in the same tree order used by the Admin table.
 *
 * SQL can sort direct siblings by sort_order, but it cannot cheaply emit the
 * full nested tree in display order on both MySQL and MariaDB without recursive
 * CTE differences. This helper keeps ordering deterministic in PHP: root rows
 * are sorted by sort_order and title, then each direct child list is sorted the
 * same way before being appended below its parent.
 *
 * @param array $rows Raw gallery rows from the Admin dashboard query.
 * @return array Gallery rows flattened in visible tree order.
 */
function admin_ordered_gallery_rows(array $rows): array
{
    // Variable $childrenByParent stores direct children indexed by their parent id.
    $childrenByParent = [];
    foreach ($rows as $row) {
        // Variable $parentKey stores zero for root galleries and the parent id for subgalleries.
        $parentKey = (int) ($row['parent_id'] ?? 0);
        $childrenByParent[$parentKey][] = $row;
    }

    foreach ($childrenByParent as $parentKey => $children) {
        usort($children, static function (array $left, array $right): int {
            // Variable $sortCompare stores the numeric sibling order comparison.
            $sortCompare = (int) ($left['sort_order'] ?? 0) <=> (int) ($right['sort_order'] ?? 0);
            if ($sortCompare !== 0) {
                return $sortCompare;
            }
            // Variable $titleCompare stores the stable human fallback when sort_order values match.
            $titleCompare = strnatcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
            if ($titleCompare !== 0) {
                return $titleCompare;
            }
            return (int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0);
        });
        $childrenByParent[$parentKey] = $children;
    }

    // Variable $orderedRows stores the final flattened admin tree.
    $orderedRows = [];

    /**
     * Appends descendants for one parent id.
     *
     * @param int $parentId Parent id whose children should be appended.
     * @return void
     */
    $appendChildren = static function (int $parentId) use (&$appendChildren, &$orderedRows, $childrenByParent): void {
        foreach ($childrenByParent[$parentId] ?? [] as $row) {
            $orderedRows[] = $row;
            $appendChildren((int) $row['id']);
        }
    };

    $appendChildren(0);
    return $orderedRows;
}

/**
 * Handles render admin devmode panel logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function render_admin_devmode_panel(): void
{
    // $enabled stores an intermediate value used by the surrounding gallery workflow.
    $enabled = dev_mode_enabled();
    echo '<section class="panel admin-devmode-panel admin-devmode-panel--secondary"><h2>' . e(t('admin.dashboard.devmode_title', 'Dev mode')) . '</h2>';
    echo '<form method="post" action="' . e(url_for('admin_devmode')) . '" class="form-grid">' . csrf_field();
    echo '<p class="muted">' . e(t('admin.dashboard.devmode_description', 'Optional admin-only diagnostics overlay for preload, cache, memory, network and frame-timing tuning in the public viewer and fullscreen viewer.')) . '</p>';
    echo '<label class="admin-checkbox-row"><input type="checkbox" name="dev_mode_enabled" value="1"' . ($enabled ? ' checked' : '') . '> <span>' . e(t('admin.dashboard.devmode_enable_overlay', 'Enable viewer diagnostics overlay')) . '</span></label>';
    echo '<button type="submit" class="secondary">' . e(t('admin.dashboard.devmode_save', 'Save dev mode')) . '</button></form></section>';
}

/**
 * Handles cms admin devmode logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function cms_admin_devmode(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();
    set_dev_mode_enabled(isset($_POST['dev_mode_enabled']));
    flash_message('admin_notice', '' . e(t('admin.dashboard.notice_devmode_saved', 'Dev mode setting saved.')) . '');
    redirect_to(url_for('admin'));
}

/**
 * Handles render admin migration notice logic for the gallery application.
 * @param mixed $message Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function render_admin_migration_notice(string $message): void
{
    echo '<div class="notice is-alert"><form method="post" action="' . e(url_for('admin_run_migrations')) . '" class="inline-action-form">' . csrf_field();
    echo '<span>' . e($message) . '</span> ';
    echo '<button type="submit" class="button is-update-pending">' . e(t('admin.dashboard.run_database_migration', 'Run database migration')) . '</button>';
    echo '</form></div>';
}

/**
 * Handles cms admin run migrations logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function cms_admin_run_migrations(): void
{
    require_admin();
    verify_csrf();
    try {
        // $ran stores an intermediate value used by the surrounding gallery workflow.
        $ran = run_migrations();
        if ($ran) {
            admin_log_event('info', 'migrations.ran', 'Admin ran pending migrations.', ['versions' => $ran]);
            flash_message('admin_notice', '' . e(t('admin.dashboard.notice_migrations_applied', 'Applied migrations:')) . ' ' . implode(', ', $ran) . '.');
            redirect_to(url_for('admin'));
        }
        admin_log_event('info', 'migrations.current', 'Admin checked migrations and database was already current.');
        flash_message('admin_notice', '' . e(t('admin.dashboard.notice_database_current', 'Database is already current.')) . '');
        redirect_to(url_for('admin'));
    } catch (Throwable $exception) {
        admin_log_event('error', 'migrations.failed', 'Admin migration run failed.', ['exception' => $exception->getMessage()]);
        flash_message('admin_notice', '' . e(t('admin.dashboard.notice_migration_failed', 'Migration failed:')) . ' ' . $exception->getMessage());
        redirect_to(url_for('admin'));
    }
}

