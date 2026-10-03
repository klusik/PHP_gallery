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

namespace Gallery\Views;

use function Gallery\Core\csrf_field;
use function Gallery\Core\csrf_token;
use function Gallery\Core\e;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\render_admin_tab_panel;
use function Gallery\Core\render_admin_tabs;
use function Gallery\Core\render_header;
use function Gallery\Core\url_for;
use function Gallery\Services\t;

/**
 * Render the Admin dashboard page from a controller-provided model.
 *
 * Health badges use the prepared runtime policy's action_required boolean and
 * capability-keyed schema states; rendering does not discover runtime or journal state.
 *
 * @param array<string,mixed> $model Dashboard read model from admin_dashboard_view_model(),
 *   including gallery rows/counts, feature booleans and canonical nested health records.
 * @return void Emit the dashboard, action badges and deferred Maintenance placeholder.
 * @see \Gallery\Services\admin_dashboard_view_model()
 */
function view_render_admin_dashboard(array $model): void
{
    $gpsMapOverrideReady = !empty($model['gps_map_override_ready']);
    $migrationPending = !empty($model['migration_pending']);
    $galleries = is_array($model['galleries'] ?? null) ? $model['galleries'] : [];
    $updatePending = !empty($model['update_pending']);
    $updateButtonClass = (string) ($model['update_button_class'] ?? 'button secondary');
    $updateLabel = (string) ($model['update_label'] ?? t('admin.menu.updates', 'Updates'));
    $totalGalleries = (int) ($model['total_galleries'] ?? count($galleries));
    $totalImages = (int) ($model['total_images'] ?? 0);
    $missingThumbnailVariants = (int) ($model['missing_thumbnail_variants'] ?? 0);
    $notices = is_array($model['notices'] ?? null) ? $model['notices'] : [];
    // Security/auth schema health surfaces before the deferred Maintenance panel opens.
    $securitySchemaStatuses = is_array($model['security_schema_statuses'] ?? null) ? $model['security_schema_statuses'] : [];
    // Destructive/ingestion schema health uses the same badge so paused mutations are visible immediately.
    $mutationSchemaStatuses = is_array($model['mutation_schema_statuses'] ?? null) ? $model['mutation_schema_statuses'] : [];
    $runtimeSupport = is_array($model['runtime_support_status'] ?? null) ? $model['runtime_support_status'] : [];
    $systemHealthActionRequired = !empty($runtimeSupport['policy']['action_required']);
    $systemHealthActionRequired = $systemHealthActionRequired || !empty($model['image_move_pending_status']['action_required']);
    foreach (array_merge($securitySchemaStatuses, $mutationSchemaStatuses, (array) ($model['presentation_schema_statuses'] ?? [])) as $schemaStatus) {
        if (is_array($schemaStatus) && in_array((string) ($schemaStatus['state'] ?? 'unknown'), ['missing', 'unknown'], true)) {
            $systemHealthActionRequired = true;
            break;
        }
    }
    $maintenanceBadge = $migrationPending || $systemHealthActionRequired ? t('admin.dashboard.badge_action', 'Action') : ($missingThumbnailVariants > 0 ? (string) $missingThumbnailVariants : null);

    $adminTabs = [
        ['id' => 'admin-tab-overview', 'label' => t('admin.dashboard.tab_overview', 'Overview')],
        ['id' => 'admin-tab-galleries', 'label' => t('admin.dashboard.tab_galleries', 'Galleries'), 'badge' => !empty($model['galleries_loaded']) || !empty($model['overview_loaded']) ? $totalGalleries : null],
        ['id' => 'admin-tab-maintenance', 'label' => t('admin.dashboard.tab_maintenance', 'Maintenance'), 'badge' => $maintenanceBadge],
    ];

    render_header(t('admin.dashboard.page_title', 'Admin dashboard'));
    $heroActions = [
        ['label' => t('admin.dashboard.create_gallery', 'Create gallery'), 'url' => url_for('admin_new_gallery'), 'class' => 'button'],
        ['label' => t('admin.dashboard.open_galleries', 'Open galleries'), 'url' => url_for('home'), 'class' => 'button secondary'],
    ];
    if ($updatePending) {
        $heroActions[] = ['label' => $updateLabel, 'url' => url_for('admin_update'), 'class' => $updateButtonClass];
    }
    echo '<div class="admin-dashboard-page" data-admin-dashboard-workspace>';
    echo '<header class="admin-dashboard-toolbar"><h1>' . e(t('admin.dashboard.tab_overview', 'Overview')) . '</h1><div class="nav" aria-label="' . e(t('admin.dashboard.hero_actions_label', 'Dashboard actions')) . '">';
    foreach ($heroActions as $action) {
        echo '<a class="' . e($action['class']) . '" href="' . e($action['url']) . '">' . e($action['label']) . '</a>';
    }
    echo '</div></header>';

    view_render_admin_dashboard_notices($notices);
    view_render_admin_url_rewrite_warning($model);
    echo '<div id="admin-dashboard-thumbnail-progress" class="admin-dashboard-progress-slot" aria-live="polite"></div>';

    foreach ($adminTabs as &$tab) {
        $tab['href'] = url_for('admin', ['dashboard_tab' => substr($tab['id'], strlen('admin-tab-'))])
            . ($tab['id'] === 'admin-tab-overview' ? '' : '#' . $tab['id']);
    }
    unset($tab);
    $activeTab = 'admin-tab-' . (string) ($model['active_tab'] ?? 'overview');
    ob_start();
    render_admin_tabs($adminTabs, $activeTab);
    // Synchronize the explicit fallback query as well as the hash when tabs change in place.
    echo str_replace('data-admin-tabs ', 'data-admin-tabs data-admin-tabs-url-mode="href" ', (string) ob_get_clean());

    ob_start();
    view_render_admin_dashboard_overview_panel($model);
    $overviewHtml = (string) ob_get_clean();
    render_admin_tab_panel('admin-tab-overview', $overviewHtml, $activeTab === 'admin-tab-overview');

    ob_start();
    view_render_admin_dashboard_deferred_read('galleries', !empty($model['galleries_loaded']) ? $model : null);
    $galleriesHtml = (string) ob_get_clean();
    render_admin_tab_panel('admin-tab-galleries', $galleriesHtml, $activeTab === 'admin-tab-galleries');

    ob_start();
    if (!empty($model['maintenance_loaded'])) {
        view_render_admin_dashboard_maintenance_panel($model);
    } else {
        $requestedMaintenanceTab = strtolower(trim((string) ($model['requested_maintenance_tab'] ?? '')));
        $maintenanceEndpointParams = in_array($requestedMaintenanceTab, ['content', 'media', 'navigation', 'system', 'trash'], true)
            ? ['maintenance_tab' => $requestedMaintenanceTab]
            : [];
        echo '<div class="admin-dashboard-deferred-panel" data-admin-dashboard-maintenance-placeholder data-maintenance-endpoint="' . e(url_for('admin_dashboard_maintenance', $maintenanceEndpointParams)) . '" data-maintenance-log-endpoint="' . e(url_for('admin_dashboard_maintenance_client_log')) . '" data-csrf-token="' . e(csrf_token()) . '" role="status"><p class="muted">' . e(t('admin.dashboard.maintenance_loading', 'Loading maintenance tools…')) . '</p><noscript><a href="' . e(url_for('admin_storage_statistics')) . '">' . e(t('admin.storage.open_details', 'Open storage and maintenance details')) . '</a></noscript></div>';
    }
    $maintenanceHtml = (string) ob_get_clean();
    render_admin_tab_panel('admin-tab-maintenance', $maintenanceHtml, $activeTab === 'admin-tab-maintenance');
    echo '</div>';

}

/**
 * Render the gallery workspace from prepared rows, also used by its deferred endpoint.
 *
 * @param array<string,mixed> $model Gallery rows, feature/schema readiness, collapse map and Trash settings.
 * @return void Outputs the existing filter, bulk-action and reorder controls.
 */
function view_render_admin_dashboard_galleries_panel(array $model): void
{
    $pictureGameReady = !empty($model['picture_game_ready']);
    $gpsMapReady = !empty($model['gps_map_ready']);
    $gpsMapOverrideReady = !empty($model['gps_map_override_ready']);
    $votingReady = !empty($model['voting_ready']);
    $filenameDisplayReady = !empty($model['filename_display_ready']);
    $accessReady = !empty($model['access_ready']);
    $backgroundSourceReady = !empty($model['background_source_ready']);
    $galleries = is_array($model['galleries'] ?? null) ? $model['galleries'] : [];
    $collapsedIds = is_array($model['collapsed_ids'] ?? null) ? $model['collapsed_ids'] : [];
    $childrenByParent = is_array($model['children_by_parent'] ?? null) ? $model['children_by_parent'] : [];
    $galleryTrashEnabled = !empty($model['gallery_trash_enabled']);
    $galleryTrashAutoPurgeEnabled = !empty($model['gallery_trash_auto_purge_enabled']);
    $galleryTrashRetentionDays = max(1, min(365, (int) ($model['gallery_trash_retention_days'] ?? 30)));
    echo '<form method="post" action="' . e(url_for('admin_bulk_galleries')) . '" data-gallery-bulk-form data-gallery-trash-enabled="' . ($galleryTrashEnabled ? '1' : '0') . '" data-gallery-trash-auto-purge-enabled="' . ($galleryTrashAutoPurgeEnabled ? '1' : '0') . '" data-gallery-trash-retention-days="' . $galleryTrashRetentionDays . '" data-admin-gallery-order-form data-thumbnail-progress-target="#admin-dashboard-thumbnail-progress">' . csrf_field();
    $controls = (array) ($model['gallery_controls'] ?? []);
    echo '<section class="admin-gallery-workspace" data-feature-plan-url="' . e((string) ($controls['feature_plan_url'] ?? '')) . '" data-feature-apply-url="' . e((string) ($controls['feature_apply_url'] ?? '')) . '" aria-label="' . e(t('admin.dashboard.gallery_management', 'Gallery management')) . '">';
    echo '<header class="admin-gallery-workspace-heading"><h2>' . e(t('admin.dashboard.all_galleries', 'All galleries')) . '</h2>';
    echo '<div class="admin-image-order-toolbar admin-gallery-order-toolbar" data-admin-gallery-order-toolbar data-reorder-url="' . e(url_for('admin_reorder_galleries')) . '"><details class="admin-gallery-order-help"><summary>' . e(t('admin.dashboard.tree_ordering', 'Tree ordering')) . '</summary><p class="muted">' . e(t('admin.dashboard.tree_ordering_hint', 'Drag a gallery thumbnail or title area to reorder. Move right to nest a gallery, or left to move it back out.')) . '</p></details><span class="admin-image-order-status" data-admin-gallery-order-status aria-live="polite">' . e(t('admin.dashboard.gallery_ordering_ready', 'Gallery ordering ready.')) . '</span></div>';
    echo '</header><div class="admin-gallery-command-panel">';
    echo '<div class="bulk-row admin-gallery-controls">';
    echo '<label>' . e(t('admin.dashboard.filter', 'Filter')) . '<select data-gallery-visibility-filter><option value="all">' . e(t('admin.dashboard.filter_all_statuses', 'All statuses')) . '</option><option value="unpublished">' . e(t('admin.dashboard.filter_only_unpublished', 'Only unpublished')) . '</option><option value="public">' . e(t('admin.dashboard.filter_only_public', 'Only public')) . '</option><option value="private">' . e(t('admin.dashboard.filter_only_private', 'Only private')) . '</option></select></label>';
    echo '<span class="muted admin-gallery-filter-summary" data-gallery-filter-summary></span>';
    echo '<label class="admin-gallery-select-all"><input type="checkbox" data-select-all="gallery_ids[]"> ' . e(t('admin.dashboard.select_displayed', 'Select displayed')) . '</label><label>' . e(t('admin.dashboard.bulk_action', 'Bulk action')) . '<select name="action"><option value="scan">' . e(t('admin.dashboard.bulk_scan_images', 'Scan/import images')) . '</option><option value="thumbs">' . e(t('admin.dashboard.bulk_create_thumbnails', 'Create thumbnails')) . '</option><option value="public">' . e(t('admin.dashboard.bulk_set_public', 'Set public')) . '</option><option value="unpublished">' . e(t('admin.dashboard.bulk_set_unpublished', 'Set unpublished')) . '</option><option value="private">' . e(t('admin.dashboard.bulk_set_private', 'Set private')) . '</option><option value="delete">' . e($galleryTrashEnabled
        ? t('admin.dashboard.bulk_trash_selected', 'Move selected galleries to trash')
        : t('admin.dashboard.bulk_delete_selected', 'Delete selected galleries')) . '</option>';
    if ($gpsMapReady) {
        echo '<option value="maps_on">' . e(t('admin.dashboard.bulk_enable_gps_maps', 'Force GPS maps on')) . '</option><option value="maps_off">' . e(t('admin.dashboard.bulk_disable_gps_maps', 'Force GPS maps off')) . '</option>';
        if ($gpsMapOverrideReady) {
            echo '<option value="maps_inherit">' . e(t('admin.dashboard.bulk_inherit_gps_maps', 'Use GPS map default')) . '</option>';
        }
    }
    if ($filenameDisplayReady) {
        echo '<option value="filenames_on">' . e(t('admin.dashboard.bulk_show_file_names', 'Show file names')) . '</option><option value="filenames_off">' . e(t('admin.dashboard.bulk_hide_file_names', 'Hide file names')) . '</option>';
    }
    if ($votingReady) {
        echo '<option value="vote_on">' . e(t('admin.dashboard.bulk_enable_voting', 'Enable voting')) . '</option><option value="vote_off">' . e(t('admin.dashboard.bulk_disable_voting', 'Disable voting')) . '</option>';
    }
    if ($pictureGameReady) {
        echo '<option value="game_on">' . e(t('admin.dashboard.bulk_enable_picture_game', 'Enable picture game')) . '</option><option value="game_off">' . e(t('admin.dashboard.bulk_disable_picture_game', 'Disable picture game')) . '</option>';
    }
    echo '</select></label><button type="submit">' . e(t('admin.dashboard.apply', 'Apply')) . '</button><span class="admin-gallery-tree-controls"><button type="button" class="secondary" data-gallery-tree-action="collapse-all">' . e(t('admin.dashboard.collapse_all', 'Collapse all')) . '</button><button type="button" class="secondary" data-gallery-tree-action="expand-all">' . e(t('admin.dashboard.expand_all', 'Expand all')) . '</button></span></div></div>';
    echo '<div class="admin-gallery-table-shell"><table class="admin-gallery-order-table admin-gallery-tree-table" data-admin-gallery-order-table><thead><tr><th class="admin-gallery-select-heading">' . e(t('admin.dashboard.column_select', 'Select')) . '</th><th>' . e(t('admin.dashboard.column_gallery', 'Gallery')) . '</th><th>' . e(t('admin.dashboard.column_state', 'State')) . '</th><th>' . e(t('admin.dashboard.column_features', 'Features')) . '</th><th class="admin-gallery-count-heading">' . e(t('admin.dashboard.column_images', 'Images')) . '</th><th class="admin-gallery-actions-heading">' . e(t('admin.dashboard.column_actions', 'Actions')) . '</th></tr></thead><tbody>';
    foreach ($galleries as $gallery) {
        // Variable $depth stores this steps working value.
        $depth = substr_count((string) $gallery['folder_path'], '/');
        // Variable $hasChildren stores this steps working value.
        $hasChildren = !empty($childrenByParent[(int) $gallery['id']]);
        // Variable $isCollapsed stores this steps working value.
        $isCollapsed = isset($collapsedIds[(int) $gallery['id']]);
        echo '<tr class="' . ($depth > 0 ? 'is-subgallery' : '') . ($isCollapsed ? ' is-collapsed' : '') . '" data-gallery-row data-gallery-id="' . (int) $gallery['id'] . '" data-parent-id="' . (int) ($gallery['parent_id'] ?? 0) . '" data-depth="' . $depth . '" data-gallery-visibility="' . e((string) ($gallery['view_visibility'] ?? 'unpublished')) . '" data-gallery-title="' . e((string) $gallery['title']) . '" data-gallery-url="' . e(gallery_public_url($gallery)) . '" style="--gallery-depth: ' . min($depth, 8) . ';"><td><input type="checkbox" name="gallery_ids[]" value="' . (int) $gallery['id'] . '"></td>';
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
        echo '<div class="admin-gallery-summary-text"><span class="tree-title ' . e($depthClass) . '">' . ($hasChildren ? '<button type="button" class="tree-toggle" data-gallery-toggle="' . (int) $gallery['id'] . '" aria-expanded="' . ($isCollapsed ? 'false' : 'true') . '">' . ($isCollapsed ? '+' : '-') . '</button>' : '<span class="tree-spacer" aria-hidden="true"></span>') . ($depth > 0 ? '<span class="tree-branch" aria-hidden="true"></span>' : '') . '<a class="admin-gallery-title-link" href="' . e(gallery_public_url($gallery)) . '">' . e($gallery['title']) . '</a></span><span class="admin-gallery-path" title="' . e((string) $gallery['folder_path'] . ((string) ($gallery['parent_title'] ?? '') !== '' ? ' | ' . t('admin.dashboard.parent_label', 'Parent:') . ' ' . (string) $gallery['parent_title'] : '')) . '">' . e($gallery['folder_path']) . '</span>' . ((string) ($gallery['parent_title'] ?: '') !== '' ? '<span class="admin-gallery-parent">' . e(t('admin.dashboard.parent_label', 'Parent:')) . ' ' . e((string) $gallery['parent_title']) . '</span>' : '') . '</div></div></td>';
        echo '<td class="admin-gallery-state-cell"><span class="admin-gallery-status-pill is-' . e((string) ($gallery['view_visibility'] ?? 'unpublished')) . '">' . e((string) ($gallery['view_visibility_label'] ?? ($gallery['view_visibility'] ?? 'unpublished'))) . '</span>';
        if (is_array($gallery['view_visibility_menu'] ?? null)) {
            view_render_public_admin_visibility_menu($gallery['view_visibility_menu']);
        }
        if ($accessReady) {
            // $accessLabel stores an intermediate value used by the surrounding gallery workflow.
            $accessLabel = (string) ($gallery['access_mode'] ?? 'normal') === 'password' ? (!empty($gallery['view_own_password_enabled']) ? t('admin.dashboard.access_password_locked', 'Password locked') : t('admin.dashboard.access_direct_link_token', 'Direct-link token')) : t('admin.dashboard.access_no_password', 'No password');
            view_render_admin_gallery_password_control((array) ($gallery['view_password_control'] ?? []), $accessLabel);
        }
        $galleryGpsMapsEnabled = $gpsMapReady && !empty($gallery['view_gps_map_enabled']);
        echo '</td><td class="admin-gallery-feature-cell">' . view_render_admin_gallery_feature_control($gallery, 'maps', t('admin.dashboard.feature_maps', 'Maps'), $galleryGpsMapsEnabled, $gpsMapReady && $gpsMapOverrideReady);
        echo view_render_admin_gallery_feature_control($gallery, 'background', t('admin.dashboard.feature_background', 'Background'), !empty($gallery['view_background_source_set']), false);
        if ($filenameDisplayReady) {
            echo view_render_admin_gallery_feature_control($gallery, 'filenames', t('admin.dashboard.feature_file_names_shown', 'File names shown'), (int) ($gallery['show_filenames'] ?? 0) === 1, true);
        }
        if ($votingReady) {
            echo view_render_admin_gallery_feature_control($gallery, 'voting', t('admin.dashboard.feature_voting', 'Voting'), (int) ($gallery['voting_enabled'] ?? 0) === 1, true);
        }
        if ($pictureGameReady) {
            echo view_render_admin_gallery_feature_control($gallery, 'game', t('admin.dashboard.feature_game', 'Game'), (int) ($gallery['picture_game_enabled'] ?? 0) === 1, true);
        }
        echo '</td><td class="admin-gallery-image-count"><strong class="admin-gallery-count-direct">' . e(t('admin.gallery_list.photos_direct', '{count} here', ['count' => (int) $gallery['image_count']])) . '</strong>';
        if ((int) ($gallery['view_subgallery_count'] ?? 0) > 0) {
            echo '<span class="admin-gallery-count-subgalleries">' . e(t('admin.gallery_list.photos_descendants', '{count} in subgalleries', ['count' => (int) ($gallery['view_subgallery_image_count'] ?? 0)])) . '</span>';
        }
        echo '</td><td class="gallery-row-actions">';
        echo '<div class="gallery-row-action-set" aria-label="' . e(t('admin.dashboard.actions_for', 'Actions for')) . ' ' . e((string) $gallery['title']) . '">';
        echo '<a class="gallery-row-action is-edit-action" href="' . e(url_for('admin_edit_gallery', ['id' => $gallery['id']])) . '" aria-label="' . e(t('admin.dashboard.edit_action', 'Edit')) . ' ' . e((string) $gallery['title']) . '" title="' . e(t('admin.dashboard.edit_gallery', 'Edit gallery')) . '"><span class="gallery-row-action-icon" aria-hidden="true">&#9998;</span><span class="admin-visually-hidden">' . e(t('admin.dashboard.edit', 'Edit')) . '</span></a>';
        echo '<button type="submit" class="secondary gallery-row-action is-thumbnail-action" name="thumbnail_gallery_id" value="' . (int) $gallery['id'] . '" formaction="' . e(url_for('admin_create_thumbnails')) . '" aria-label="' . e(t('admin.dashboard.create_thumbnails_for', 'Create thumbnails for')) . ' ' . e((string) $gallery['title']) . '" title="' . e(t('admin.dashboard.create_thumbnails', 'Create thumbnails')) . '"><span class="gallery-row-action-icon" aria-hidden="true">&#9639;</span><span class="admin-visually-hidden">' . e(t('admin.dashboard.thumbs', 'Thumbs')) . '</span></button>';
        echo '</div></td></tr>';
    }
    echo '</tbody></table></div><div class="admin-gallery-feature-pending" data-gallery-feature-pending hidden><span data-gallery-feature-pending-status aria-live="polite"></span><button type="button" data-gallery-feature-review>' . e(t('admin.gallery_list.review_changes', 'Review changes')) . '</button><button type="button" class="secondary" data-gallery-feature-discard>' . e(t('admin.gallery_list.discard_changes', 'Discard')) . '</button></div><dialog class="admin-gallery-feature-dialog" data-gallery-feature-dialog aria-label="' . e(t('admin.gallery_list.review_title', 'Confirm gallery changes')) . '"></dialog></section></form>';
}

/**
 * Render an aggregate feature state and an optional local preparation button.
 * @param array<string,mixed> $gallery Prepared row with subtree states and counts.
 * @param string $key Canonical feature identifier.
 * @param string $label Localized feature name.
 * @param bool $directEnabled Presentation fallback for historical fixtures.
 * @param bool $writable Whether this verified feature supports staged changes.
 * @return string Escaped control; clicking prepares an intent without submitting the bulk form.
 */
function view_render_admin_gallery_feature_control(array $gallery, string $key, string $label, bool $directEnabled, bool $writable): string
{
    $state = (string) ($gallery['view_feature_states'][$key] ?? ($directEnabled ? 'on' : 'off'));
    $state = in_array($state, ['on', 'off', 'mixed'], true) ? $state : 'off';
    $counts = (array) ($gallery['view_feature_counts'][$key] ?? ['on' => $directEnabled ? 1 : 0, 'total' => 1]);
    $total = max(1, (int) ($counts['total'] ?? 1));
    $on = max(0, min($total, (int) ($counts['on'] ?? 0)));
    $description = $state === 'mixed'
        ? t('admin.gallery_list.feature_mixed', 'Enabled in {on} of {total} galleries', ['on' => $on, 'total' => $total])
        : ($state === 'on'
            ? t('admin.gallery_list.feature_all_on', 'Enabled in all {count} galleries', ['count' => $total])
            : t('admin.gallery_list.feature_all_off', 'Disabled in all {count} galleries', ['count' => $total]));
    $tag = $writable ? 'button' : 'span';
    $attributes = $writable ? ' type="button" data-gallery-feature-key="' . e($key) . '" data-gallery-feature-root-id="' . (int) $gallery['id'] . '" data-gallery-feature-state="' . e($state) . '" data-gallery-feature-own-state="' . ($directEnabled ? 'on' : 'off') . '" data-on-count="' . $on . '" data-total-count="' . $total . '" aria-pressed="' . ($state === 'mixed' ? 'mixed' : ($state === 'on' ? 'true' : 'false')) . '"' : '';
    $marker = $state === 'on' ? '&#10003;' : ($state === 'mixed' ? '&#8722;' : '&#9675;');
    return '<' . $tag . ' class="admin-gallery-feature is-' . e($state) . '"' . $attributes . ' title="' . e($label . ': ' . $description) . '"><span>' . e($label) . '</span><span class="admin-gallery-feature-marker" aria-hidden="true">' . $marker . '</span><span class="admin-visually-hidden">: ' . e($description) . '</span></' . $tag . '>';
}

/**
 * Render a single-gallery password control without putting a password in the bulk form.
 * @param array<string,mixed> $control Controller-prepared safe destination and revision.
 * @param string $label Current localized access label.
 * @return void Emits a local editor or the read-only label when storage is unavailable.
 */
function view_render_admin_gallery_password_control(array $control, string $label): void
{
    if ($control === []) {
        echo '<span class="admin-gallery-access-label">' . e($label) . '</span>';
        return;
    }
    echo '<div class="admin-gallery-password-control" data-admin-gallery-password data-action-url="' . e((string) $control['action_url']) . '" data-gallery-id="' . (int) $control['gallery_id'] . '" data-edit-revision="' . (int) $control['edit_revision'] . '" data-password-enabled="' . (!empty($control['enabled']) ? '1' : '0') . '" data-error-message="' . e(t('gallery.password_save_failed', 'Gallery password could not be saved.')) . '" data-password-required="' . e(t('gallery.password_required', 'Enter a gallery password.')) . '"><button type="button" class="admin-gallery-access-label" data-admin-gallery-password-toggle>' . e($label) . '</button>';
    echo '<div class="admin-gallery-password-editor" data-admin-gallery-password-editor hidden><label>' . e(t('admin.gallery_editor.new_gallery_password', 'New gallery password')) . '<input type="password" autocomplete="new-password" data-admin-gallery-password-input></label><button type="button" data-admin-gallery-password-save>' . e(t('admin.common.save', 'Save')) . '</button></div><span class="admin-gallery-quick-status" data-admin-gallery-quick-status aria-live="polite"></span></div>';
}

/**
 * Render a named, accessible feature state from already prepared row values.
 *
 * @param string $label Localized feature name shown in the table.
 * @param bool $enabled Resolved state supplied by the owning gallery view model.
 * @param string $enabledDescription Localized explanation retained for an enabled feature.
 * @return string Escaped label with a visible state marker and an accessible state name.
 */
function view_render_admin_gallery_feature_state(string $label, bool $enabled, string $enabledDescription): string
{
    $stateLabel = $enabled ? t('admin.common.enabled', 'Enabled') : t('admin.common.disabled', 'Disabled');
    return '<span class="admin-gallery-feature ' . ($enabled ? 'is-enabled' : 'is-disabled') . '" title="' . e($enabled ? $enabledDescription : $label . ': ' . $stateLabel) . '"><span>' . e($label) . '</span><span class="admin-flag ' . ($enabled ? 'is-enabled' : 'is-disabled') . '" aria-hidden="true">' . ($enabled ? '&#10003;' : '&ndash;') . '</span><span class="admin-visually-hidden">: ' . e($stateLabel) . '</span></span>';
}

/**
 * Render a retryable read placeholder with a functional direct-page fallback.
 *
 * @param string $surface overview or galleries; supplied by the owning dashboard view.
 * @param array<string,mixed>|null $loadedModel Prepared Galleries model when rendered on the initial request.
 * @return void Outputs a loading region without pretending that uncomputed totals are zero.
 */
function view_render_admin_dashboard_deferred_read(string $surface, ?array $loadedModel = null): void
{
    echo '<div class="admin-dashboard-read" data-dashboard-read="' . e($surface) . '" data-dashboard-loaded="' . ($loadedModel !== null ? '1' : '0') . '" data-endpoint="' . e(url_for('admin_dashboard_fragment', ['surface' => $surface])) . '" data-error="' . e(t('admin.dashboard.load_failed', 'Could not load this section. Try again.')) . '" data-retry="' . e(t('admin.dashboard.retry', 'Try again')) . '">';
    if ($loadedModel !== null) {
        view_render_admin_dashboard_galleries_panel($loadedModel);
        echo '</div>';
        return;
    }
    if ($surface === 'overview') {
        $cards = [];
        foreach (['metric_galleries' => 'Galleries', 'metric_top_level_images' => 'Top-level images', 'metric_thumbnail_gaps' => 'Thumbnail gaps', 'metric_system_state' => 'System state'] as $key => $label) {
            $cards[] = ['label' => t('admin.dashboard.' . $key, $label), 'value' => '…', 'help' => t('admin.dashboard.summary_loading', 'Loading summary…'), 'state' => 'neutral'];
        }
        view_render_admin_metric_grid($cards);
    } else {
        echo '<p class="muted" role="status">' . e(t('admin.dashboard.galleries_loading', 'Loading galleries…')) . '</p>';
    }
    echo '<noscript><a class="button secondary" href="' . e(url_for('admin', ['dashboard_tab' => $surface]) . '#admin-tab-' . $surface) . '">' . e(t('admin.dashboard.load_section', 'Load this section')) . '</a></noscript></div>';
}

/**
 * Handle view render admin dashboard notices.
 *
 * Used by server-rendered view helpers.
 *
 * @param array $notices Notices value.
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
function view_render_admin_url_rewrite_warning(array $model = []): void
{
    $compatibility = is_array($model['url_rewrite_compatibility'] ?? null) ? $model['url_rewrite_compatibility'] : [];
    if (empty($compatibility['enabled']) || !in_array((string) ($compatibility['status'] ?? 'unknown'), ['unsupported'], true)) {
        return;
    }

    $reason = (string) ($compatibility['reasons'][0] ?? t('admin.dashboard.url_rewrite_warning_unknown_reason', 'Rewrite support was not detected.'));
    echo '<div class="notice is-alert"><strong>' . e(t('admin.dashboard.url_rewrite_warning_title', 'URL rewrite is enabled, but support was not detected.')) . '</strong> ';
    echo e($reason) . ' ';
    echo e(t('admin.dashboard.url_rewrite_warning_hint', 'Public links will fall back to index.php URLs where possible. Check .htaccess, mod_rewrite, or disable URL rewrite below if this hosting does not support it.'));
    echo '</div>';
}


/**
 * Render the global GPS map preference separately from bulk override resets.
 *
 * @param string $className Row presentation class from the owning dashboard group.
 * @param bool $defaultEnabled Prepared global map-display preference.
 * @param int $overrideCount Prepared individual override count, retained for caller compatibility.
 * @param array<string,mixed> $model Prepared global Settings URLs.
 * @return void Emits only the global map preference with its owned Content return context.
 */
function view_render_admin_exif_gps_defaults_card(string $className, bool $defaultEnabled, int $overrideCount, array $model = []): void
{
    echo '<form method="post" action="' . e(url_for('admin_exif_gps_settings')) . '" class="' . e($className) . '">' . csrf_field();
    echo '<input type="hidden" name="maintenance_return" value="content"><div class="admin-content-copy"><strong>' . e(t('admin.dashboard.content_gps_title', 'GPS maps')) . '</strong><span>' . e(t('admin.dashboard.content_gps_hint', 'Default for galleries without a map override.')) . '</span></div>';
    echo '<div class="admin-content-controls"><label class="admin-compact-toggle"><input type="checkbox" name="exif_gps_default_enabled" value="1" aria-label="' . e(t('admin.dashboard.content_gps_title', 'GPS maps')) . '"' . ($defaultEnabled ? ' checked' : '') . '> ' . e(t('admin.dashboard.content_enabled', 'Enabled')) . '</label></div>';
    echo '<div class="admin-content-actions"><button type="submit" class="secondary" aria-label="' . e(t('admin.dashboard.content_save', 'Save') . ': ' . t('admin.dashboard.content_gps_title', 'GPS maps')) . '">' . e(t('admin.dashboard.content_save', 'Save')) . '</button><a href="' . e((string) (($model['admin_settings_urls']['media'] ?? url_for('admin_settings')))) . '">' . e(t('admin.dashboard.content_gps_settings', 'Map settings')) . '</a></div></form>';
}

/**
 * Render a dashboard card linking to the gallery date suggestion workflow.
 *
 * @param string $className Row presentation class from the owning dashboard group.
 * @param array<string,mixed> $model Prepared effective gallery-date suggestion capability.
 * @return void Emits the date workflow shortcut only when its capability is available.
 */
function view_render_admin_gallery_dates_card(string $className, array $model = []): void
{
    if (empty($model['feature_enabled']['exif_gallery_date_suggestions'])) {
        return;
    }
    echo '<article class="' . e($className) . '"><div class="admin-content-copy"><strong>' . e(t('admin.dashboard.gallery_dates', 'Gallery dates')) . '</strong><span title="' . e(t('admin.dashboard.gallery_dates_hint', 'Approve editable date ranges suggested from scanned EXIF capture dates, including subgalleries.')) . '">' . e(t('admin.dashboard.content_dates_hint', 'Review date ranges suggested from photo EXIF.')) . '</span></div><div class="admin-content-actions"><a class="button secondary" href="' . e(url_for('admin_gallery_dates')) . '">' . e(t('admin.dashboard.content_dates_action', 'Review dates')) . '</a></div></article>';
}

/**
 * Render the URL rewrite setting and compatibility summary.
 *
 * @param string $className Class name value.
 * @param array<string,mixed> $model Prepared rewrite preference and compatibility diagnosis.
 * @param bool $compact Whether to use the owned Content layout and return context.
 * @return void Emits rewrite controls while retaining the historical layout for other callers.
 */
function view_render_admin_url_rewrite_card(string $className, array $model = [], bool $compact = false): void
{
    $enabled = !empty($model['url_rewrite_enabled']);
    $compatibility = is_array($model['url_rewrite_compatibility'] ?? null) ? $model['url_rewrite_compatibility'] : [];
    $status = (string) ($compatibility['status'] ?? 'unknown');
    $statusLabels = [
        'disabled' => t('admin.dashboard.url_rewrite_status_disabled', 'Disabled intentionally'),
        'supported' => t('admin.dashboard.url_rewrite_status_supported', 'Supported'),
        'likely_supported' => t('admin.dashboard.url_rewrite_status_likely_supported', 'Likely supported'),
        'unsupported' => t('admin.dashboard.url_rewrite_status_unsupported', 'Not detected'),
        'unknown' => t('admin.dashboard.url_rewrite_status_unknown', 'Unknown'),
    ];
    $reason = (string) ($compatibility['reasons'][0] ?? t('admin.dashboard.url_rewrite_reason_unknown', 'No detailed compatibility signal is available for this request.'));

    if ($compact) {
        echo '<form method="post" action="' . e(url_for('admin_url_rewrite')) . '" class="' . e($className) . '">' . csrf_field() . '<input type="hidden" name="maintenance_return" value="content">';
        echo '<div class="admin-content-copy"><div class="admin-content-title"><strong>' . e(t('admin.dashboard.content_rewrite_title', 'Clean public URLs')) . '</strong><small class="admin-content-status' . (in_array($status, ['unsupported', 'unknown'], true) ? ' is-attention' : '') . '"><span class="admin-content-status-label">' . e(t('admin.dashboard.url_rewrite_detected_status', 'Detected status:')) . '</span> ' . e($statusLabels[$status] ?? $statusLabels['unknown']) . '</small></div><span>' . e(t('admin.dashboard.content_rewrite_hint', 'Use readable gallery and photo URLs.')) . '</span>';
        if (in_array($status, ['unsupported', 'unknown'], true)) {
            echo '<span class="admin-content-status is-attention">' . e($reason) . '</span>';
        }
        view_render_admin_url_rewrite_warning($model);
        echo '<details class="admin-content-details"><summary>' . e(t('admin.dashboard.content_details', 'Details')) . '</summary><p>' . e(t('admin.dashboard.url_rewrite_hint', 'Clean public URLs are enabled by default. Disable them only when your hosting cannot route rewritten paths.')) . '</p>';
        if (!in_array($status, ['unsupported', 'unknown'], true)) {
            echo '<p>' . e($reason) . '</p>';
        }
        echo '</details></div><div class="admin-content-controls"><label class="admin-compact-toggle"><input type="checkbox" name="url_rewrite_enabled" value="1" aria-label="' . e(t('admin.dashboard.content_rewrite_title', 'Clean public URLs')) . '"' . ($enabled ? ' checked' : '') . '> ' . e(t('admin.dashboard.content_enabled', 'Enabled')) . '</label></div><div class="admin-content-actions"><button type="submit" class="secondary" aria-label="' . e(t('admin.dashboard.content_save', 'Save') . ': ' . t('admin.dashboard.content_rewrite_title', 'Clean public URLs')) . '">' . e(t('admin.dashboard.content_save', 'Save')) . '</button></div></form>';
        return;
    }

    echo '<form method="post" action="' . e(url_for('admin_url_rewrite')) . '" class="' . e($className) . '">' . csrf_field();
    echo '<strong>' . e(t('admin.dashboard.url_rewrite_title', 'URL rewrite')) . '</strong>';
    echo '<span>' . e(t('admin.dashboard.url_rewrite_hint', 'Clean public URLs are enabled by default. Disable them only when your hosting cannot route rewritten paths.')) . '</span>';
    echo '<label class="admin-checkbox-row"><input type="checkbox" name="url_rewrite_enabled" value="1"' . ($enabled ? ' checked' : '') . '> <span>' . e(t('admin.dashboard.url_rewrite_enable_clean_urls', 'Use clean rewritten public URLs')) . '</span></label>';
    echo '<small><strong>' . e(t('admin.dashboard.url_rewrite_detected_status', 'Detected status:')) . '</strong> ' . e($statusLabels[$status] ?? $statusLabels['unknown']) . ' &middot; ' . e($reason) . '</small>';
    echo '<button type="submit" class="secondary">' . e(t('admin.dashboard.url_rewrite_save', 'Save URL rewrite')) . '</button></form>';
}

/**
 * Render the admin maintenance card that refreshes local flight-map navdata.
 *
 * @param bool $flightNavdataReady Flight navdata ready value.
 * @param array<string,mixed> $flightNavdataStatus Prepared navdata freshness, totals and source status.
 * @param string $returnPage Owned surface to preserve after fragment refresh or ordinary POST.
 * @return void Emits the in-place refresh card.
 */
function view_render_admin_navdata_maintenance_card(bool $flightNavdataReady, array $flightNavdataStatus, string $returnPage = 'admin'): void
{
    $returnPage = $returnPage === 'admin_navdata' ? 'admin_navdata' : 'admin';
    $submittingText = t('admin.dashboard.updating_navdata', 'Updating navdata...');
    $hybridStatus = is_array($flightNavdataStatus['hybrid'] ?? null) ? $flightNavdataStatus['hybrid'] : [];

    echo '<article class="admin-maintenance-card admin-navdata-update-card" data-navdata-card data-navdata-return-page="' . e($returnPage) . '">';
    echo '<div class="admin-maintenance-card-heading"><strong>' . e(t('admin.dashboard.flight_navdata', 'Flight map navdata')) . '</strong>';
    if ($returnPage === 'admin') {
        echo '<a class="button secondary" href="' . e(url_for('admin_navdata')) . '">' . e(t('admin.dashboard.open_navdata_manager', 'Open manager')) . '</a>';
    }
    echo '</div>';

    if (!$flightNavdataReady) {
        echo '<p class="admin-navdata-import-note">' . e(t('admin.dashboard.flight_navdata_requires_migration', 'Run database migrations before importing flight-map navdata.')) . '</p>';
        echo '<button type="button" class="secondary" disabled>' . e(t('admin.dashboard.update_navdata', 'Update navdata')) . '</button></article>';
        return;
    }

    echo '<form method="post" action="' . e(url_for('admin_update_navdata')) . '" class="admin-navdata-import-form" data-navdata-update-form data-navdata-auto-check="' . (!empty($flightNavdataStatus['refresh_due']) ? '1' : '0') . '" data-navdata-submitting-text="' . e($submittingText) . '">' . csrf_field();
    echo '<input type="hidden" name="navdata_return_page" value="' . e($returnPage) . '">';
    echo '<button type="submit" data-navdata-update-submit>' . e(t('admin.dashboard.update_navdata', 'Update navdata')) . '</button>';
    echo '<div class="admin-navdata-update-status" data-navdata-update-status role="status" aria-live="polite" hidden><span class="admin-navdata-update-spinner" aria-hidden="true"></span><span data-navdata-status-text>' . e(t('admin.dashboard.navdata_update_in_progress', 'Downloading and importing navigation data in the background. You can use other pages.')) . '</span></div></form>';

    $lastUpdate = trim((string) ($flightNavdataStatus['last_update'] ?? ''));
    $metrics = [
        [t('admin.navdata.total_points', 'Local points'), number_format((int) ($flightNavdataStatus['total'] ?? 0))],
        [t('admin.navdata.last_update', 'Last update'), $lastUpdate !== '' ? $lastUpdate : t('admin.navdata.never_imported', 'Not imported yet')],
        [t('admin.navdata.last_airports', 'Airport identifiers'), number_format((int) ($flightNavdataStatus['last_airports'] ?? 0))],
        [t('admin.navdata.last_navaids', 'Navaids'), number_format((int) ($flightNavdataStatus['last_navaids'] ?? 0))],
    ];
    echo '<dl class="admin-navdata-import-metrics">';
    foreach ($metrics as [$label, $value]) {
        echo '<div><dt>' . e($label) . '</dt><dd>' . e($value) . '</dd></div>';
    }
    echo '</dl>';
    if ($lastUpdate === '') {
        echo '<p class="muted admin-navdata-import-note">' . e(t('admin.dashboard.flight_navdata_empty', 'No local route lookup data has been imported yet. Route maps can still use manual NAME@latitude,longitude points.')) . '</p>';
    }
    echo '<p class="muted admin-navdata-import-note">' . e(t('admin.dashboard.flight_navdata_scope_hint', 'Imports airports and navaids from OurAirports. It does not include full IFR fixes or SID/STAR procedure geometry.')) . '</p>';
    echo '<details class="admin-navdata-import-details"><summary>' . e(t('admin.navdata.import_details', 'Import details')) . '</summary>';
    echo '<p class="muted">' . e(t('admin.navdata.import_counts', 'Last import: {skipped} skipped rows, {deleted} stale rows removed.', [
        'skipped' => number_format((int) ($flightNavdataStatus['last_skipped'] ?? 0)),
        'deleted' => number_format((int) ($flightNavdataStatus['last_deleted'] ?? 0)),
    ])) . '</p>';
    echo '<p class="muted">' . e(t('admin.dashboard.flight_navdata_hybrid_status', 'Bundled fallback points: {bundled}. SimBrief OFPs are stored per gallery when imported.', [
        'bundled' => (int) ($hybridStatus['bundled_count'] ?? 0),
    ])) . '</p>';
    echo '<p class="muted">' . e(t('admin.navdata.refresh_schedule', 'When this widget is visible, data older than a week refreshes in the background.')) . '</p></details>';
    echo '</article>';
}


/**
 * Render the reusable admin dev mode settings card.
 *
 * @param string $className Class name value.
 */
function view_render_admin_devmode_card(string $className, array $model = []): void
{
    // $enabled stores an intermediate value used by the surrounding gallery workflow.
    $enabled = !empty($model['dev_mode_enabled']);
    echo '<form method="post" action="' . e(url_for('admin_devmode')) . '" class="' . e($className) . ' admin-devmode-card">' . csrf_field();
    echo '<strong>' . e(t('admin.dashboard.devmode_title', 'Dev mode')) . '</strong>';
    echo '<span>' . e(t('admin.dashboard.devmode_description', 'Optional admin-only diagnostics overlay for preload, cache, memory, network and frame-timing tuning in the public viewer and fullscreen viewer.')) . '</span>';
    echo '<label class="admin-checkbox-row"><input type="checkbox" name="dev_mode_enabled" value="1"' . ($enabled ? ' checked' : '') . '> <span>' . e(t('admin.dashboard.devmode_enable_overlay', 'Enable viewer diagnostics overlay')) . '</span></label>';
    echo '<button type="submit" class="secondary">' . e(t('admin.dashboard.devmode_save', 'Save dev mode')) . '</button></form>';
}

/**
 * Render the admin dev mode panel.
 */
function view_render_admin_devmode_panel(array $model = []): void
{
    echo '<section class="panel admin-devmode-panel admin-devmode-panel--secondary">';
    view_render_admin_devmode_card('admin-maintenance-card', $model);
    echo '</section>';
}

/**
 * Render a migration notice with an inline migration action.
 *
 * @param string $message Message value.
 */
function view_render_admin_migration_notice(string $message): void
{
    echo '<div class="notice is-alert"><form method="post" action="' . e(url_for('admin_run_migrations')) . '" class="inline-action-form">' . csrf_field();
    echo '<span>' . e($message) . '</span> ';
    echo '<button type="submit" class="button is-update-pending">' . e(t('admin.dashboard.run_database_migration', 'Run database migration')) . '</button>';
    echo '</form></div>';
}
