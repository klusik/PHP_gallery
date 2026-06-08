<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_dashboard.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders the Admin dashboard from a prepared dashboard view model.
 *
 * Responsibilities:
 *   - Keep Admin dashboard markup out of the controller
 *   - Render maintenance cards, notices, tabs, and gallery table rows
 *   - Avoid database reads while rendering dashboard rows
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
 *   2026-05-24
 */

declare(strict_types=1);

/**
 * Render the Admin dashboard page from a controller-provided model.
 *
 * @param array<string, mixed> $model
 */
function view_render_admin_dashboard(array $model): void
{
    $pictureGameReady = !empty($model['picture_game_ready']);
    $gpsMapReady = !empty($model['gps_map_ready']);
    $gpsMapOverrideReady = !empty($model['gps_map_override_ready']);
    $votingReady = !empty($model['voting_ready']);
    $filenameDisplayReady = !empty($model['filename_display_ready']);
    $migrationPending = !empty($model['migration_pending']);
    $accessReady = !empty($model['access_ready']);
    $backgroundSourceReady = !empty($model['background_source_ready']);
    $galleries = is_array($model['galleries'] ?? null) ? $model['galleries'] : [];
    $collapsedIds = is_array($model['collapsed_ids'] ?? null) ? $model['collapsed_ids'] : [];
    $childrenByParent = is_array($model['children_by_parent'] ?? null) ? $model['children_by_parent'] : [];
    $updatePending = !empty($model['update_pending']);
    $updateButtonClass = (string) ($model['update_button_class'] ?? 'button secondary');
    $updateLabel = (string) ($model['update_label'] ?? t('admin.menu.updates', 'Updates'));
    $totalGalleries = (int) ($model['total_galleries'] ?? count($galleries));
    $totalImages = (int) ($model['total_images'] ?? 0);
    $missingThumbnailVariants = (int) ($model['missing_thumbnail_variants'] ?? 0);
    $notices = is_array($model['notices'] ?? null) ? $model['notices'] : [];
    $maintenanceBadge = $migrationPending ? t('admin.dashboard.badge_action', 'Action') : ($missingThumbnailVariants > 0 ? (string) $missingThumbnailVariants : null);

    $adminTabs = [
        ['id' => 'admin-tab-overview', 'label' => t('admin.dashboard.tab_overview', 'Overview')],
        ['id' => 'admin-tab-galleries', 'label' => t('admin.dashboard.tab_galleries', 'Galleries'), 'badge' => $totalGalleries],
        ['id' => 'admin-tab-maintenance', 'label' => t('admin.dashboard.tab_maintenance', 'Maintenance'), 'badge' => $maintenanceBadge],
    ];

    admin_render_profile_span('render_header', static function (): void { render_header(t('admin.dashboard.page_title', 'Admin dashboard')); });
    $heroActions = [
        ['label' => t('admin.dashboard.create_gallery', 'Create gallery'), 'url' => url_for('admin_new_gallery'), 'class' => 'button'],
        ['label' => t('admin.dashboard.upload_photos', 'Upload photos'), 'url' => url_for('admin_upload'), 'class' => 'button secondary'],
    ];
    if ($updatePending) {
        $heroActions[] = ['label' => $updateLabel, 'url' => url_for('admin_update'), 'class' => $updateButtonClass];
    }
    view_render_admin_hero([
        'kicker' => t('admin.dashboard.kicker', 'Admin'),
        'title' => t('admin.dashboard.title', 'Dashboard'),
        'description' => t('admin.dashboard.description', 'A focused workspace for gallery management, media maintenance, and system health.'),
        'actions' => $heroActions,
        'actions_aria_label' => t('admin.dashboard.hero_actions_label', 'Dashboard actions'),
        'meta' => [
            ['value' => (string) $totalGalleries, 'label' => t('admin.dashboard.metric_galleries', 'Galleries')],
            ['value' => (string) $totalImages, 'label' => t('admin.dashboard.metric_top_level_images', 'Top-level images')],
        ],
    ]);

    view_render_admin_dashboard_notices($notices);
    view_render_admin_url_rewrite_warning();
    echo '<div id="admin-dashboard-thumbnail-progress" class="admin-dashboard-progress-slot" aria-live="polite"></div>';

    admin_render_profile_span('render_admin_tabs', static function () use ($adminTabs): void { render_admin_tabs($adminTabs, 'admin-tab-overview'); });

    ob_start();
    view_render_admin_dashboard_overview_panel($model);
    $overviewHtml = (string) ob_get_clean();
    render_admin_tab_panel('admin-tab-overview', $overviewHtml, true);

    ob_start();
    view_render_admin_tab_intro([
        'kicker' => t('admin.dashboard.galleries_kicker', 'Galleries'),
        'title' => t('admin.dashboard.all_galleries', 'All galleries'),
        'actions' => [
            ['label' => t('admin.dashboard.upload_photos', 'Upload photos'), 'url' => url_for('admin_upload'), 'class' => 'button secondary'],
        ],
    ]);
    echo '<form method="post" action="' . e(url_for('admin_bulk_galleries')) . '" data-gallery-bulk-form data-admin-gallery-order-form data-thumbnail-progress-target="#admin-dashboard-thumbnail-progress">' . csrf_field();
    echo '<section class="admin-gallery-workspace" aria-label="' . e(t('admin.dashboard.gallery_management', 'Gallery management')) . '">';
    echo '<div class="admin-gallery-command-panel">';
    echo '<div class="admin-image-order-toolbar admin-gallery-order-toolbar" data-admin-gallery-order-toolbar data-reorder-url="' . e(url_for('admin_reorder_galleries')) . '"><div><strong>' . e(t('admin.dashboard.tree_ordering', 'Tree ordering')) . '</strong><p class="muted">' . e(t('admin.dashboard.tree_ordering_hint', 'Drag a gallery thumbnail or title area to reorder. Move right to nest a gallery, or left to move it back out.')) . '</p></div><span class="admin-image-order-status" data-admin-gallery-order-status aria-live="polite">' . e(t('admin.dashboard.gallery_ordering_ready', 'Gallery ordering ready.')) . '</span></div>';
    echo '<div class="bulk-row admin-gallery-controls">';
    echo '<label>' . e(t('admin.dashboard.filter', 'Filter')) . '<select data-gallery-visibility-filter><option value="all">' . e(t('admin.dashboard.filter_all_statuses', 'All statuses')) . '</option><option value="unpublished">' . e(t('admin.dashboard.filter_only_unpublished', 'Only unpublished')) . '</option><option value="public">' . e(t('admin.dashboard.filter_only_public', 'Only public')) . '</option><option value="private">' . e(t('admin.dashboard.filter_only_private', 'Only private')) . '</option></select></label>';
    echo '<span class="muted admin-gallery-filter-summary" data-gallery-filter-summary></span>';
    echo '<label class="admin-gallery-select-all"><input type="checkbox" data-select-all="gallery_ids[]"> ' . e(t('admin.dashboard.select_displayed', 'Select displayed')) . '</label><label>' . e(t('admin.dashboard.bulk_action', 'Bulk action')) . '<select name="action"><option value="scan">' . e(t('admin.dashboard.bulk_scan_images', 'Scan/import images')) . '</option><option value="thumbs">' . e(t('admin.dashboard.bulk_create_thumbnails', 'Create thumbnails')) . '</option><option value="public">' . e(t('admin.dashboard.bulk_set_public', 'Set public')) . '</option><option value="unpublished">' . e(t('admin.dashboard.bulk_set_unpublished', 'Set unpublished')) . '</option><option value="private">' . e(t('admin.dashboard.bulk_set_private', 'Set private')) . '</option><option value="maps_on">' . e(t('admin.dashboard.bulk_enable_gps_maps', 'Force GPS maps on')) . '</option><option value="maps_off">' . e(t('admin.dashboard.bulk_disable_gps_maps', 'Force GPS maps off')) . '</option>' . ($gpsMapOverrideReady ? '<option value="maps_inherit">' . e(t('admin.dashboard.bulk_inherit_gps_maps', 'Use GPS map default')) . '</option>' : '') . '<option value="delete">' . e(t('admin.dashboard.bulk_delete_selected', 'Delete selected galleries')) . '</option>';
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
        $previewUrl = (string) ($gallery['preview_url'] ?? '');
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
        $galleryGpsMapsEnabled = $gpsMapReady && gallery_effective_gps_map_enabled($gallery);
        echo '</td><td class="admin-gallery-feature-cell"><span class="admin-gallery-feature" title="' . e(t('admin.dashboard.feature_maps', 'Maps')) . '">M ' . view_render_admin_feature_flag($galleryGpsMapsEnabled, '&#10003;', '' . t('admin.dashboard.feature_gps_maps_enabled', 'GPS maps enabled') . '') . '</span>';
        echo '<span class="admin-gallery-feature" title="' . e(t('admin.dashboard.feature_background', 'Background')) . '">B ' . view_render_admin_feature_flag($backgroundSourceReady && gallery_background_source($gallery) !== null, '&#10003;', '' . t('admin.dashboard.feature_custom_background_set', 'Custom gallery background set') . '') . '</span>';
        if ($filenameDisplayReady) {
            echo '<span class="admin-gallery-feature" title="' . e(t('admin.dashboard.feature_file_names_shown', 'File names shown')) . '">N ' . view_render_admin_feature_flag((int) ($gallery['show_filenames'] ?? 0) === 1, '&#10003;', '' . t('admin.dashboard.feature_file_names_are_shown', 'File names are shown') . '') . '</span>';
        }
        if ($votingReady) {
            echo '<span class="admin-gallery-feature" title="' . e(t('admin.dashboard.feature_voting', 'Voting')) . '">V ' . view_render_admin_feature_flag((int) ($gallery['voting_enabled'] ?? 0) === 1, '&#10003;', '' . t('admin.dashboard.feature_voting_enabled', 'Voting enabled') . '') . '</span>';
        }
        if ($pictureGameReady) {
            echo '<span class="admin-gallery-feature" title="' . e(t('admin.dashboard.feature_game', 'Game')) . '">G ' . view_render_admin_feature_flag((int) ($gallery['picture_game_enabled'] ?? 0) === 1, '&#10003;', '' . t('admin.dashboard.feature_picture_game_enabled', 'Picture game enabled') . '') . '</span>';
        }
        echo '</td><td class="admin-gallery-image-count"><strong>' . (int) $gallery['image_count'] . '</strong></td><td class="nav gallery-row-actions">';
        echo '<div class="gallery-row-action-set" aria-label="' . e(t('admin.dashboard.actions_for', 'Actions for')) . ' ' . e((string) $gallery['title']) . '">';
        echo '<a class="gallery-row-action is-edit-action" href="' . e(url_for('admin_edit_gallery', ['id' => $gallery['id']])) . '" aria-label="' . e(t('admin.dashboard.edit_action', 'Edit')) . ' ' . e((string) $gallery['title']) . '" title="' . e(t('admin.dashboard.edit_gallery', 'Edit gallery')) . '"><span class="gallery-row-action-icon" aria-hidden="true">&#9998;</span><span class="admin-visually-hidden">' . e(t('admin.dashboard.edit', 'Edit')) . '</span></a>';
        echo '<button type="submit" class="secondary gallery-row-action is-thumbnail-action" name="thumbnail_gallery_id" value="' . (int) $gallery['id'] . '" formaction="' . e(url_for('admin_create_thumbnails')) . '" aria-label="' . e(t('admin.dashboard.create_thumbnails_for', 'Create thumbnails for')) . ' ' . e((string) $gallery['title']) . '" title="' . e(t('admin.dashboard.create_thumbnails', 'Create thumbnails')) . '"><span class="gallery-row-action-icon" aria-hidden="true">&#9639;</span><span class="admin-visually-hidden">' . e(t('admin.dashboard.thumbs', 'Thumbs')) . '</span></button>';
        echo '</div></td></tr>';
    }
    echo '</tbody></table></div></section></form>';
    $galleriesHtml = (string) ob_get_clean();
    admin_render_profile_span('render_galleries_tab_panel', static function () use ($galleriesHtml): void { render_admin_tab_panel('admin-tab-galleries', $galleriesHtml, false); });

    ob_start();
    view_render_admin_dashboard_maintenance_panel($model);
    $maintenanceHtml = (string) ob_get_clean();
    admin_render_profile_span('render_maintenance_tab_panel', static function () use ($maintenanceHtml): void { render_admin_tab_panel('admin-tab-maintenance', $maintenanceHtml, false); });

    render_admin_render_profile_panel();
    admin_render_profile_span('render_footer', static function (): void { render_footer(); });
}

/**
 * @param array<int, mixed> $notices
 */
function view_render_admin_dashboard_notices(array $notices): void
{
    foreach ($notices as $notice) {
        $noticeText = trim((string) $notice);
        if ($noticeText !== '') {
            echo '<div class="notice">' . e($noticeText) . '</div>';
        }
    }
}

/**
 * Render a non-blocking warning when clean URL generation is enabled but rewrite support looks unavailable.
 */
function view_render_admin_url_rewrite_warning(): void
{
    $compatibility = url_rewrite_compatibility();
    if (!$compatibility['enabled'] || !in_array((string) $compatibility['status'], ['unsupported'], true)) {
        return;
    }

    $reason = (string) ($compatibility['reasons'][0] ?? t('admin.dashboard.url_rewrite_warning_unknown_reason', 'Rewrite support was not detected.'));
    echo '<div class="notice is-alert"><strong>' . e(t('admin.dashboard.url_rewrite_warning_title', 'URL rewrite is enabled, but support was not detected.')) . '</strong> ';
    echo e($reason) . ' ';
    echo e(t('admin.dashboard.url_rewrite_warning_hint', 'Public links will fall back to index.php URLs where possible. Check .htaccess, mod_rewrite, or disable URL rewrite below if this hosting does not support it.'));
    echo '</div>';
}


/**
 * Render the shared EXIF/GPS default display settings card.
 */
function view_render_admin_exif_gps_defaults_card(string $className, bool $defaultEnabled, int $overrideCount): void
{
    echo '<form method="post" action="' . e(url_for('admin_exif_gps_settings')) . '" class="' . e($className) . '">' . csrf_field();
    echo '<strong>' . e(t('admin.dashboard.exif_gps_defaults', 'EXIF / GPS defaults')) . '</strong>';
    echo '<span>' . e(t('admin.dashboard.exif_gps_defaults_hint', 'Global default is used by every gallery that has no explicit EXIF / GPS override.')) . '</span>';
    echo '<label class="checkbox-label"><input type="checkbox" name="exif_gps_default_enabled" value="1"' . ($defaultEnabled ? ' checked' : '') . '> ' . e(t('admin.dashboard.exif_gps_default_enabled_label', 'Show EXIF GPS maps by default for all galleries')) . '</label>';
    echo '<label class="checkbox-label"><input type="checkbox" name="reset_gallery_overrides" value="1"> ' . e(t('admin.dashboard.exif_gps_reset_overrides_label', 'Reset all per-gallery EXIF / GPS display overrides')) . '</label>';
    echo '<span class="muted">' . e(t('admin.dashboard.exif_gps_override_count', 'Gallery override(s): {count}', ['count' => (string) $overrideCount])) . '</span>';
    echo '<button type="submit" class="secondary">' . e(t('admin.dashboard.save_exif_gps_defaults', 'Save EXIF / GPS defaults')) . '</button></form>';
}

/**
 * Render a dashboard card linking to the gallery date suggestion workflow.
 */
function view_render_admin_gallery_dates_card(string $className): void
{
    echo '<article class="' . e($className) . '"><strong>' . e(t('admin.dashboard.gallery_dates', 'Gallery dates')) . '</strong><span>' . e(t('admin.dashboard.gallery_dates_hint', 'Approve editable date ranges suggested from scanned EXIF capture dates, including subgalleries.')) . '</span><a class="button secondary" href="' . e(url_for('admin_gallery_dates')) . '">' . e(t('admin.dashboard.open_gallery_dates', 'Open gallery dates')) . '</a></article>';
}

/**
 * Render the URL rewrite setting and compatibility summary.
 */
function view_render_admin_url_rewrite_card(string $className): void
{
    $enabled = url_rewrite_enabled();
    $compatibility = url_rewrite_compatibility();
    $status = (string) $compatibility['status'];
    $statusLabels = [
        'disabled' => t('admin.dashboard.url_rewrite_status_disabled', 'Disabled intentionally'),
        'supported' => t('admin.dashboard.url_rewrite_status_supported', 'Supported'),
        'likely_supported' => t('admin.dashboard.url_rewrite_status_likely_supported', 'Likely supported'),
        'unsupported' => t('admin.dashboard.url_rewrite_status_unsupported', 'Not detected'),
        'unknown' => t('admin.dashboard.url_rewrite_status_unknown', 'Unknown'),
    ];
    $reason = (string) ($compatibility['reasons'][0] ?? t('admin.dashboard.url_rewrite_reason_unknown', 'No detailed compatibility signal is available for this request.'));

    echo '<form method="post" action="' . e(url_for('admin_url_rewrite')) . '" class="' . e($className) . '">' . csrf_field();
    echo '<strong>' . e(t('admin.dashboard.url_rewrite_title', 'URL rewrite')) . '</strong>';
    echo '<span>' . e(t('admin.dashboard.url_rewrite_hint', 'Clean public URLs are enabled by default. Disable them only when your hosting cannot route rewritten paths.')) . '</span>';
    echo '<label class="admin-checkbox-row"><input type="checkbox" name="url_rewrite_enabled" value="1"' . ($enabled ? ' checked' : '') . '> <span>' . e(t('admin.dashboard.url_rewrite_enable_clean_urls', 'Use clean rewritten public URLs')) . '</span></label>';
    echo '<small><strong>' . e(t('admin.dashboard.url_rewrite_detected_status', 'Detected status:')) . '</strong> ' . e($statusLabels[$status] ?? $statusLabels['unknown']) . ' &middot; ' . e($reason) . '</small>';
    echo '<button type="submit" class="secondary">' . e(t('admin.dashboard.url_rewrite_save', 'Save URL rewrite')) . '</button></form>';
}

/**
 * Render the admin maintenance card that refreshes local flight-map navdata.
 */
function view_render_admin_navdata_maintenance_card(bool $flightNavdataReady, array $flightNavdataStatus): void
{
    $confirmMessage = t('admin.dashboard.confirm_update_navdata', 'Download current OurAirports airports and navaids, then replace the local OurAirports lookup rows?');
    $submittingText = t('admin.dashboard.updating_navdata', 'Updating navdata...');
    $hybridStatus = is_array($flightNavdataStatus['hybrid'] ?? null) ? $flightNavdataStatus['hybrid'] : [];

    echo '<article class="admin-maintenance-card admin-navdata-update-card">';
    echo '<div class="admin-maintenance-card-heading"><strong>' . e(t('admin.dashboard.flight_navdata', 'Flight map navdata')) . '</strong><a class="button secondary" href="' . e(url_for('admin_navdata')) . '">' . e(t('admin.dashboard.open_navdata_manager', 'Open manager')) . '</a></div>';

    if (!$flightNavdataReady) {
        echo '<span>' . e(t('admin.dashboard.flight_navdata_requires_migration', 'Run database migrations before importing flight-map navdata.')) . '</span>';
        echo '<button type="button" class="secondary" disabled>' . e(t('admin.dashboard.update_navdata', 'Update navdata')) . '</button></article>';
        return;
    }

    $lastUpdate = trim((string) ($flightNavdataStatus['last_update'] ?? ''));
    $total = (int) ($flightNavdataStatus['total'] ?? 0);
    $airportCount = (int) ($flightNavdataStatus['last_airports'] ?? 0);
    $navaidCount = (int) ($flightNavdataStatus['last_navaids'] ?? 0);
    $skippedCount = (int) ($flightNavdataStatus['last_skipped'] ?? 0);
    $bundledCount = (int) ($hybridStatus['bundled_count'] ?? 0);

    if ($lastUpdate !== '') {
        echo '<span>' . e(t('admin.dashboard.flight_navdata_status', 'Local lookup rows: {total}. Last update: {updated}. Last import: {airports} airport identifier(s), {navaids} navaid(s), {skipped} skipped row(s).', [
            'total' => $total,
            'updated' => $lastUpdate,
            'airports' => $airportCount,
            'navaids' => $navaidCount,
            'skipped' => $skippedCount,
        ])) . '</span>';
    } else {
        echo '<span>' . e(t('admin.dashboard.flight_navdata_empty', 'No local route lookup data has been imported yet. Route maps can still use manual NAME@latitude,longitude points.')) . '</span>';
    }

    echo '<span class="muted">' . e(t('admin.dashboard.flight_navdata_hybrid_status', 'Bundled fallback points: {bundled}. SimBrief OFPs are stored per gallery when imported.', [
        'bundled' => $bundledCount,
    ])) . '</span>';
    echo '<span class="muted">' . e(t('admin.dashboard.flight_navdata_scope_hint', 'Imports airports and navaids from OurAirports for manual fallback lookup. SimBrief OFP coordinates are preferred for generated flight-route maps.')) . '</span>';

    echo '<form method="post" action="' . e(url_for('admin_update_navdata')) . '" class="admin-navdata-update-card" data-navdata-update-form data-navdata-confirm="' . e($confirmMessage) . '" data-navdata-submitting-text="' . e($submittingText) . '">' . csrf_field();
    echo '<div class="admin-navdata-update-status" data-navdata-update-status role="status" aria-live="polite" hidden><span class="admin-navdata-update-spinner" aria-hidden="true"></span><span>' . e(t('admin.dashboard.navdata_update_in_progress', 'Downloading and importing navdata. Keep this page open until the update completes.')) . '</span></div>';
    echo '<button type="submit" class="secondary" data-navdata-update-submit>' . e(t('admin.dashboard.update_navdata', 'Update navdata')) . '</button></form>';


    echo '</article>';
}


/**
 * Render the reusable admin dev mode settings card.
 */
function view_render_admin_devmode_card(string $className): void
{
    // $enabled stores an intermediate value used by the surrounding gallery workflow.
    $enabled = dev_mode_enabled();
    echo '<form method="post" action="' . e(url_for('admin_devmode')) . '" class="' . e($className) . ' admin-devmode-card">' . csrf_field();
    echo '<strong>' . e(t('admin.dashboard.devmode_title', 'Dev mode')) . '</strong>';
    echo '<span>' . e(t('admin.dashboard.devmode_description', 'Optional admin-only diagnostics overlay for preload, cache, memory, network and frame-timing tuning in the public viewer and fullscreen viewer.')) . '</span>';
    echo '<label class="admin-checkbox-row"><input type="checkbox" name="dev_mode_enabled" value="1"' . ($enabled ? ' checked' : '') . '> <span>' . e(t('admin.dashboard.devmode_enable_overlay', 'Enable viewer diagnostics overlay')) . '</span></label>';
    echo '<button type="submit" class="secondary">' . e(t('admin.dashboard.devmode_save', 'Save dev mode')) . '</button></form>';
}

/**
 * Render the admin dev mode panel.
 */
function view_render_admin_devmode_panel(): void
{
    echo '<section class="panel admin-devmode-panel admin-devmode-panel--secondary">';
    view_render_admin_devmode_card('admin-maintenance-card');
    echo '</section>';
}

/**
 * Render a migration notice with an inline migration action.
 */
function view_render_admin_migration_notice(string $message): void
{
    echo '<div class="notice is-alert"><form method="post" action="' . e(url_for('admin_run_migrations')) . '" class="inline-action-form">' . csrf_field();
    echo '<span>' . e($message) . '</span> ';
    echo '<button type="submit" class="button is-update-pending">' . e(t('admin.dashboard.run_database_migration', 'Run database migration')) . '</button>';
    echo '</form></div>';
}
