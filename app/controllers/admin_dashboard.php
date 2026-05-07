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
    // Variable $pictureGameReady stores this steps working value.
    $pictureGameReady = picture_game_schema_ready();
    // Variable $gpsMapReady stores this steps working value.
    $gpsMapReady = exif_gps_schema_ready();
    // Variable $votingReady stores this steps working value.
    $votingReady = gallery_voting_schema_ready();
    // Variable $filenameDisplayReady stores this steps working value.
    $filenameDisplayReady = gallery_filename_display_schema_ready();
    // Variable $migrationPending stores this steps working value.
    $migrationPending = pending_migrations_exist();
    // Variable $accessReady stores this steps working value.
    $accessReady = gallery_access_schema_ready();
    // $backgroundSourceReady stores whether gallery background source data can be read without optional-column errors.
    $backgroundSourceReady = gallery_background_source_schema_ready();
    // $publicPathReady stores whether clean public URL paths can be read directly from gallery rows.
    $publicPathReady = public_path_schema_ready();
    // $coverAssetReady stores whether uploaded gallery cover assets can be shown in the admin gallery list.
    $coverAssetReady = gallery_cover_asset_schema_ready();

    if ($pictureGameReady && $votingReady && admin_dashboard_self_heal_due('admin_dashboard_voting_game_sync_last', 300)) {
        // Self-heal voting/game state periodically instead of on every admin navigation.
        $repairedVotingGame = sync_gallery_voting_game_state();
        admin_dashboard_mark_self_heal('admin_dashboard_voting_game_sync_last');
        if ($repairedVotingGame > 0) {
            admin_log_event('info', 'gallery.voting_game_synced', 'Admin dashboard repaired gallery voting/game settings.', [
                'gallery_count' => $repairedVotingGame,
            ]);
        }
    }

    if (admin_dashboard_parent_sync_needed()) {
        sync_gallery_parent_ids();
        admin_dashboard_store_parent_sync_fingerprint();
    }

    // Variable $galleries stores this steps working value.
    $galleries = admin_dashboard_gallery_rows($accessReady, $gpsMapReady, $backgroundSourceReady, $filenameDisplayReady, $votingReady, $pictureGameReady, $publicPathReady, $coverAssetReady);
    // Variable $galleries stores the admin tree in display order, with manual sibling ordering respected.
    $galleries = admin_ordered_gallery_rows($galleries);
    // Variable $collapsedIds stores this steps working value.
    $collapsedIds = array_flip(collapsed_gallery_ids());
    // $childrenByParent stores direct child ids once so row rendering does not rescan the full gallery list.
    $childrenByParent = admin_gallery_children_by_parent($galleries);

    // $updatePending stores an intermediate value used by the surrounding gallery workflow.
    $updatePending = application_update_pending();
    // $updateButtonClass stores an intermediate value used by the surrounding gallery workflow.
    $updateButtonClass = $updatePending ? 'button secondary is-update-pending' : 'button secondary';
    // $updateLabel stores an intermediate value used by the surrounding gallery workflow.
    $updateLabel = application_update_nav_label($updatePending);
    // $thumbnailSummary stores an intermediate value used by the surrounding gallery workflow.
    $thumbnailSummary = cached_thumbnail_maintenance_summary(null, 1000);
    // $totalGalleries stores an intermediate value used by the surrounding gallery workflow.
    $totalGalleries = count($galleries);
    // $totalImages stores an intermediate value used by the surrounding gallery workflow.
    $totalImages = 0;
    // $draftGalleries stores an intermediate value used by the surrounding gallery workflow.
    $draftGalleries = 0;
    // $privateGalleries stores an intermediate value used by the surrounding gallery workflow.
    $privateGalleries = 0;
    foreach ($galleries as $gallery) {
        $totalImages += (int) ($gallery['image_count'] ?? 0);
        if ((string) ($gallery['visibility'] ?? '') === 'draft') {
            $draftGalleries++;
        } elseif ((string) ($gallery['visibility'] ?? '') === 'private') {
            $privateGalleries++;
        }
    }
    // $missingThumbnailVariants stores an intermediate value used by the surrounding gallery workflow.
    $missingThumbnailVariants = (int) ($thumbnailSummary['missing_variants'] ?? 0);
    // $adminTabs stores the reusable tab model rendered by the shared helper.
    $adminTabs = [
        ['id' => 'admin-tab-overview', 'label' => 'Overview'],
        ['id' => 'admin-tab-galleries', 'label' => 'Galleries', 'badge' => $totalGalleries],
        ['id' => 'admin-tab-maintenance', 'label' => 'Maintenance', 'badge' => $migrationPending ? 'Action' : null],
    ];

    render_header('Admin dashboard');
    echo '<section class="hero admin-dashboard-hero"><div><p class="admin-kicker">Admin</p><h1>Dashboard</h1><p class="muted">A focused workspace for gallery management, media maintenance, and system health.</p></div>';
    echo '<div class="admin-hero-actions">';
    echo '<a class="button" href="' . e(url_for('admin_new_gallery')) . '">Create gallery</a>';
    echo '<a class="button secondary" href="' . e(url_for('admin_upload')) . '">Upload photos</a>';
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
        echo '<div class="notice">Deleted ' . (int) $_GET['deleted_galleries'] . ' gallery folder(s).</div>';
    } elseif (isset($_GET['delete_error'])) {
        echo '<div class="notice">Gallery delete failed: ' . e((string) $_GET['delete_error']) . '</div>';
    }
    if (isset($_GET['devmode_saved'])) {
        echo '<div class="notice">Dev mode setting saved.</div>';
    }
    if (isset($_GET['paths_regenerated'])) {
        echo '<div class="notice">Regenerated clean public paths. Updated ' . (int) ($_GET['gallery_paths'] ?? 0) . ' gallery path(s) and ' . (int) ($_GET['image_paths'] ?? 0) . ' image path(s).</div>';
    } elseif (isset($_GET['paths_error'])) {
        echo '<div class="notice">Path regeneration failed: ' . e((string) $_GET['paths_error']) . '</div>';
    }
    if (isset($_GET['migrations_ran'])) {
        echo '<div class="notice">Applied migrations: ' . e((string) $_GET['migrations_ran']) . '.</div>';
    } elseif (isset($_GET['migrations_current'])) {
        echo '<div class="notice">Database is already current.</div>';
    } elseif (isset($_GET['migration_failed'])) {
        echo '<div class="notice">Migration failed: ' . e((string) $_GET['migration_failed']) . '</div>';
    }
    echo '<div id="admin-dashboard-thumbnail-progress" class="admin-dashboard-progress-slot" aria-live="polite"></div>';

    render_admin_tabs($adminTabs, 'admin-tab-overview');

    ob_start();
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">Overview</p><h2>Admin at a glance</h2></div><p class="muted">Use this page for immediate work. Dedicated tools stay on their own pages.</p></div>';
    echo '<section class="admin-metric-grid" aria-label="Admin summary">';
    echo '<article class="admin-metric-card"><span>Galleries</span><strong>' . (int) $totalGalleries . '</strong><small>' . (int) $draftGalleries . ' draft, ' . (int) $privateGalleries . ' private</small></article>';
    echo '<article class="admin-metric-card"><span>Top-level images</span><strong>' . (int) $totalImages . '</strong><small>Imported images shown in gallery lists</small></article>';
    echo '<article class="admin-metric-card"><span>Thumbnail gaps</span><strong>' . (int) $missingThumbnailVariants . '</strong><small>' . (int) ($thumbnailSummary['images_scanned'] ?? 0) . ' images sampled</small></article>';
    echo '<article class="admin-metric-card"><span>System state</span><strong>' . ($migrationPending ? 'Action' : 'Ready') . '</strong><small>' . ($migrationPending ? 'Database migration pending' : 'No migration warning') . '</small></article>';
    echo '</section>';
    if ($migrationPending) {
        render_admin_migration_notice('Some admin features still need database migrations.');
    }
    render_admin_thumbnail_maintenance_notice($thumbnailSummary);
    echo '<section class="admin-quick-panel"><div class="admin-panel-heading"><div><p class="admin-kicker">Actions</p><h2>Quick actions</h2></div></div><div class="admin-action-grid">';
    echo '<form method="post" action="' . e(url_for('admin_discover')) . '" class="admin-action-card" data-refresh-galleries-form>' . csrf_field();
    echo '<strong>Discover folders</strong><span>Scan the galleries directory for new folders.</span><button type="submit">Check for new gallery folders</button></form>';
    echo '<div class="admin-action-card"><strong>Gallery tools</strong><span>Create galleries or upload photos using the existing workflows.</span><div class="nav"><a class="button secondary" href="' . e(url_for('admin_new_gallery')) . '">Create empty gallery</a><a class="button secondary" href="' . e(url_for('admin_upload')) . '">Upload photos</a></div></div>';
    echo '<form method="post" action="' . e(url_for('admin_delete_thumbnails')) . '" class="admin-action-card" data-delete-all-thumbnails-form>' . csrf_field();
    echo '<strong>Media tools</strong><span>Generate thumbnails, delete generated thumbnail cache files, or download the complete gallery archive.</span>';
    echo '<input type="hidden" name="confirmation_expected" value=""><input type="hidden" name="confirmation_typed" value="">';
    echo '<div class="nav"><button type="button" class="secondary" data-create-all-thumbnails>Create all thumbnails</button><button type="submit" class="secondary danger" data-delete-all-thumbnails data-confirm-words="archive,remove,clean,thumbs,purge,reset,delete,cache,media,confirm">Delete all thumbnails</button><a class="button secondary" href="' . e(url_for('download_all')) . '">Download all galleries</a></div></form>';
    echo '<div class="admin-action-card"><strong>Maintenance</strong><span>Review logs, integrity, telemetry, and updates.</span><div class="nav"><a class="button secondary" href="' . e(url_for('admin_logs')) . '">Logs</a><a class="button secondary" href="' . e(url_for('admin_integrity')) . '">Integrity</a><a class="button secondary" href="' . e(url_for('admin_telemetry')) . '">Telemetry</a></div></div>';
    echo '<form method="post" action="' . e(url_for('admin_regenerate_paths')) . '" class="admin-action-card" onsubmit="return confirm(\'Regenerate clean public URLs for all galleries and images?\');">' . csrf_field();
    echo '<strong>Public paths</strong><span>Regenerate clean public URLs for galleries and images.</span><button type="submit" class="secondary">Regenerate paths</button></form>';
    if ($migrationPending) {
        echo '<form method="post" action="' . e(url_for('admin_run_migrations')) . '" class="admin-action-card is-attention">' . csrf_field();
        echo '<strong>Database migrations</strong><span>Some admin features need database migrations.</span><button type="submit" class="button is-update-pending">Run database migration</button></form>';
    }
    echo '</div></section>';
    $overviewHtml = (string) ob_get_clean();
    render_admin_tab_panel('admin-tab-overview', $overviewHtml, true);

    ob_start();
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">Galleries</p><h2>All galleries</h2></div><a class="button secondary" href="' . e(url_for('admin_upload')) . '">Upload photos</a></div>';
    echo '<form method="post" action="' . e(url_for('admin_bulk_galleries')) . '" data-gallery-bulk-form data-admin-gallery-order-form data-thumbnail-progress-target="#admin-dashboard-thumbnail-progress">' . csrf_field();
    echo '<section class="admin-gallery-workspace" aria-label="Gallery management">';
    echo '<div class="admin-gallery-command-panel">';
    echo '<div class="admin-image-order-toolbar admin-gallery-order-toolbar" data-admin-gallery-order-toolbar data-reorder-url="' . e(url_for('admin_reorder_galleries')) . '"><div><strong>Tree ordering</strong><p class="muted">Drag a gallery thumbnail or title area to reorder. Move right to nest a gallery, or left to move it back out.</p></div><span class="admin-image-order-status" data-admin-gallery-order-status aria-live="polite">Gallery ordering ready.</span></div>';
    echo '<div class="bulk-row admin-gallery-controls">';
    echo '<label>Filter<select data-gallery-visibility-filter><option value="all">All statuses</option><option value="draft">Only drafts</option><option value="public">Only public</option><option value="private">Only private</option></select></label>';
    echo '<span class="muted admin-gallery-filter-summary" data-gallery-filter-summary></span>';
    echo '<label class="admin-gallery-select-all"><input type="checkbox" data-select-all="gallery_ids[]"> Select displayed</label><label>Bulk action<select name="action"><option value="scan">Scan/import images</option><option value="thumbs">Create thumbnails</option><option value="public">Set public</option><option value="draft">Set draft</option><option value="private">Set private</option><option value="maps_on">Enable GPS maps</option><option value="maps_off">Disable GPS maps</option><option value="delete">Delete selected galleries</option>';
    if ($filenameDisplayReady) {
        echo '<option value="filenames_on">Show file names</option><option value="filenames_off">Hide file names</option>';
    }
    if ($votingReady) {
        echo '<option value="vote_on">Enable voting</option><option value="vote_off">Disable voting</option>';
    }
    if ($pictureGameReady) {
        echo '<option value="game_on">Enable picture game</option><option value="game_off">Disable picture game</option>';
    }
    echo '</select></label><button type="submit">Apply</button><button type="button" class="secondary" data-gallery-tree-action="collapse-all">Collapse all</button><button type="button" class="secondary" data-gallery-tree-action="expand-all">Expand all</button></div></div>';
    echo '<div class="admin-gallery-table-shell"><table class="admin-gallery-order-table admin-gallery-tree-table" data-admin-gallery-order-table><thead><tr><th class="admin-gallery-select-heading">Select</th><th>Gallery</th><th>State</th><th>Features</th><th class="admin-gallery-count-heading">Images</th><th class="admin-gallery-actions-heading">Actions</th></tr></thead><tbody>';
    foreach ($galleries as $gallery) {
        // Variable $depth stores this steps working value.
        $depth = substr_count((string) $gallery['folder_path'], '/');
        // Variable $hasChildren stores this steps working value.
        $hasChildren = !empty($childrenByParent[(int) $gallery['id']]);
        // Variable $isCollapsed stores this steps working value.
        $isCollapsed = isset($collapsedIds[(int) $gallery['id']]);
        echo '<tr class="' . ($depth > 0 ? 'is-subgallery' : '') . ($isCollapsed ? ' is-collapsed' : '') . '" data-gallery-row data-gallery-id="' . (int) $gallery['id'] . '" data-parent-id="' . (int) ($gallery['parent_id'] ?? 0) . '" data-depth="' . $depth . '" data-gallery-visibility="' . e((string) $gallery['visibility']) . '" data-gallery-title="' . e((string) $gallery['title']) . '" style="--gallery-depth: ' . min($depth, 8) . ';"><td><input type="checkbox" name="gallery_ids[]" value="' . (int) $gallery['id'] . '"></td>';
        // Variable $depthClass stores this steps working value.
        $depthClass = 'tree-depth-' . min($depth, 8);
        // $previewUrl stores a small non-blocking gallery preview image for faster visual scanning.
        $previewUrl = admin_gallery_preview_url($gallery);
        echo '<td class="admin-gallery-title-cell"><div class="admin-gallery-summary" data-admin-gallery-drag-zone title="Drag the thumbnail, path text, or empty gallery area to reorder or nest. Click the gallery name to open it."><span class="admin-gallery-depth-rail" aria-hidden="true"></span>';
        if ($previewUrl !== '') {
            echo '<span class="admin-gallery-preview" role="img" aria-label="Preview for ' . e((string) $gallery['title']) . '"><img src="' . e($previewUrl) . '" alt="" loading="lazy" decoding="async"></span>';
        } else {
            echo '<span class="admin-gallery-preview is-empty" aria-hidden="true"><span>Gallery</span></span>';
        }
        echo '<div class="admin-gallery-summary-text"><span class="tree-title ' . e($depthClass) . '">' . ($hasChildren ? '<button type="button" class="tree-toggle" data-gallery-toggle="' . (int) $gallery['id'] . '" aria-expanded="' . ($isCollapsed ? 'false' : 'true') . '">' . ($isCollapsed ? '+' : '-') . '</button>' : '<span class="tree-spacer" aria-hidden="true"></span>') . ($depth > 0 ? '<span class="tree-branch" aria-hidden="true"></span>' : '') . '<a class="admin-gallery-title-link" href="' . e(gallery_public_url($gallery)) . '">' . e($gallery['title']) . '</a></span><span class="admin-gallery-path">' . e($gallery['folder_path']) . '</span>' . ((string) ($gallery['parent_title'] ?: '') !== '' ? '<span class="admin-gallery-parent">Parent: ' . e((string) $gallery['parent_title']) . '</span>' : '') . '</div></div></td>';
        echo '<td class="admin-gallery-state-cell"><span class="admin-gallery-status-pill is-' . e((string) $gallery['visibility']) . '">' . e($gallery['visibility']) . '</span>';
        if ($accessReady) {
            // $accessLabel stores an intermediate value used by the surrounding gallery workflow.
            $accessLabel = (string) ($gallery['access_mode'] ?? 'normal') === 'password' ? 'Protected' . ((string) ($gallery['access_listing'] ?? 'listed') === 'unlisted' ? ', unlisted' : ', listed') : 'Normal';
            echo '<span class="admin-gallery-access-label">' . e($accessLabel) . '</span>';
        }
        echo '</td><td class="admin-gallery-feature-cell"><span class="admin-gallery-feature" title="Maps">M ' . render_admin_feature_flag($gpsMapReady && (int) ($gallery['gps_map_enabled'] ?? 0) === 1, '✓', 'GPS maps enabled') . '</span>';
        echo '<span class="admin-gallery-feature" title="Background">B ' . render_admin_feature_flag($backgroundSourceReady && gallery_background_source($gallery) !== null, '✓', 'Custom gallery background set') . '</span>';
        if ($filenameDisplayReady) {
            echo '<span class="admin-gallery-feature" title="File names shown">N ' . render_admin_feature_flag((int) ($gallery['show_filenames'] ?? 0) === 1, '✓', 'File names are shown') . '</span>';
        }
        if ($votingReady) {
            echo '<span class="admin-gallery-feature" title="Voting">V ' . render_admin_feature_flag((int) ($gallery['voting_enabled'] ?? 0) === 1, '✓', 'Voting enabled') . '</span>';
        }
        if ($pictureGameReady) {
            echo '<span class="admin-gallery-feature" title="Game">G ' . render_admin_feature_flag((int) ($gallery['picture_game_enabled'] ?? 0) === 1, '✓', 'Picture game enabled') . '</span>';
        }
        echo '</td><td class="admin-gallery-image-count"><strong>' . (int) $gallery['image_count'] . '</strong></td><td class="nav gallery-row-actions">';
        echo '<div class="gallery-row-action-set" aria-label="Actions for ' . e((string) $gallery['title']) . '">';
        echo '<a class="gallery-row-action is-edit-action" href="' . e(url_for('admin_edit_gallery', ['id' => $gallery['id']])) . '" aria-label="Edit ' . e((string) $gallery['title']) . '" title="Edit gallery"><span class="gallery-row-action-icon" aria-hidden="true">✎</span><span class="admin-visually-hidden">Edit</span></a>';
        echo '<button type="submit" class="secondary gallery-row-action is-thumbnail-action" name="thumbnail_gallery_id" value="' . (int) $gallery['id'] . '" formaction="' . e(url_for('admin_create_thumbnails')) . '" aria-label="Create thumbnails for ' . e((string) $gallery['title']) . '" title="Create thumbnails"><span class="gallery-row-action-icon" aria-hidden="true">▧</span><span class="admin-visually-hidden">Thumbs</span></button>';
        echo '</div></td></tr>';
    }
    echo '</tbody></table></div></section></form>';
    $galleriesHtml = (string) ob_get_clean();
    render_admin_tab_panel('admin-tab-galleries', $galleriesHtml, false);

    ob_start();
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">Maintenance</p><h2>System tools</h2></div><p class="muted">Operational tools remain on their dedicated pages. This tab keeps only useful shortcuts and active maintenance controls.</p></div>';
    echo '<div class="admin-maintenance-grid">';
    echo '<article class="admin-maintenance-card"><strong>Logs</strong><span>Review operational events, failures, and workflow status.</span><a class="button secondary" href="' . e(url_for('admin_logs')) . '">Open logs</a></article>';
    echo '<article class="admin-maintenance-card"><strong>Telemetry</strong><span>Inspect anonymous usage telemetry without collecting personal data.</span><a class="button secondary" href="' . e(url_for('admin_telemetry')) . '">Open telemetry</a></article>';
    echo '<article class="admin-maintenance-card"><strong>Integrity</strong><span>Check core files and deployment health.</span><a class="button secondary" href="' . e(url_for('admin_integrity')) . '">Run integrity check</a></article>';
    echo '<article class="admin-maintenance-card"><strong>Updates</strong><span>Check and apply project updates.</span><a class="' . e($updateButtonClass) . '" href="' . e(url_for('admin_update')) . '">' . e($updateLabel) . '</a></article>';
    echo '<form method="post" action="' . e(url_for('admin_regenerate_paths')) . '" class="admin-maintenance-card" onsubmit="return confirm(\'Regenerate clean public URLs for all galleries and images?\');">' . csrf_field();
    echo '<strong>Public paths</strong><span>Regenerate clean public URLs for galleries and images.</span><button type="submit" class="secondary">Regenerate paths</button></form>';
    echo '<article class="admin-maintenance-card"><strong>Gallery archive</strong><span>Download a complete ZIP archive through the existing route.</span><a class="button secondary" href="' . e(url_for('download_all')) . '">Download all galleries</a></article>';
    echo '<form method="post" action="' . e(url_for('admin_delete_thumbnails')) . '" class="admin-maintenance-card" data-delete-all-thumbnails-form>' . csrf_field();
    echo '<strong>Thumbnail maintenance</strong><span>' . (int) $missingThumbnailVariants . ' missing or stale variant(s) in the current sample.</span>';
    echo '<input type="hidden" name="confirmation_expected" value=""><input type="hidden" name="confirmation_typed" value="">';
    echo '<div class="nav"><button type="button" class="secondary" data-create-all-thumbnails>Create all thumbnails</button><button type="submit" class="secondary danger" data-delete-all-thumbnails data-confirm-words="archive,remove,clean,thumbs,purge,reset,delete,cache,media,confirm">Delete all thumbnails</button></div></form>';
    if ($migrationPending) {
        echo '<form method="post" action="' . e(url_for('admin_run_migrations')) . '" class="admin-maintenance-card is-attention">' . csrf_field();
        echo '<strong>Database migrations</strong><span>Pending migrations must be applied before every admin feature is fully available.</span><button type="submit" class="button is-update-pending">Run database migration</button></form>';
    }
    echo '</div>';
    $maintenanceHtml = (string) ob_get_clean();
    render_admin_tab_panel('admin-tab-maintenance', $maintenanceHtml, false);

    render_admin_devmode_panel();
    render_footer();
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
    // $coverAssetUrl stores an uploaded gallery-specific cover asset when the optional column exists.
    $coverAssetUrl = gallery_cover_asset_url($gallery, false);
    if ($coverAssetUrl !== '') {
        return $coverAssetUrl;
    }

    // $cover stores the explicit or first direct image for this gallery.
    $cover = gallery_cover_image((int) ($gallery['id'] ?? 0), false);
    if ($cover) {
        return thumbnail_url($cover, 300);
    }

    foreach (gallery_cover_collage_images((int) ($gallery['id'] ?? 0), false, 1) as $descendantCover) {
        return thumbnail_url($descendantCover, 300);
    }

    return '';
}

/**
 * Return true when a periodic dashboard repair task may run again.
 */
function admin_dashboard_self_heal_due(string $settingKey, int $ttlSeconds): bool
{
    // $lastRun stores the Unix timestamp for the last successful repair attempt.
    $lastRun = (int) app_setting($settingKey, '0');
    return $lastRun <= 0 || time() - $lastRun >= max(60, $ttlSeconds);
}

/**
 * Remember that a periodic dashboard repair task was attempted.
 */
function admin_dashboard_mark_self_heal(string $settingKey): void
{
    set_app_setting($settingKey, (string) time());
}

/**
 * Build a cheap fingerprint for gallery hierarchy state used by parent-id repair.
 */
function admin_dashboard_parent_sync_fingerprint(): string
{
    try {
        // $row stores aggregate gallery data that changes when indexed gallery rows change.
        $row = db()->query("SELECT COUNT(*) AS gallery_count, COALESCE(MAX(id), 0) AS newest_id, COALESCE(MAX(updated_at), '') AS newest_updated_at, COALESCE(SUM(CHAR_LENGTH(folder_path)), 0) AS path_length_sum FROM galleries")->fetch() ?: [];
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
    return !hash_equals((string) app_setting('admin_dashboard_parent_sync_fingerprint', ''), $fingerprint);
}

/**
 * Store the current parent-id repair fingerprint after synchronization.
 */
function admin_dashboard_store_parent_sync_fingerprint(): void
{
    // $fingerprint stores the post-repair gallery hierarchy fingerprint.
    $fingerprint = admin_dashboard_parent_sync_fingerprint();
    if ($fingerprint !== '') {
        set_app_setting('admin_dashboard_parent_sync_fingerprint', $fingerprint);
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
    echo '<section class="panel admin-devmode-panel admin-devmode-panel--secondary"><h2>Dev mode</h2>';
    echo '<form method="post" action="' . e(url_for('admin_devmode')) . '" class="form-grid">' . csrf_field();
    echo '<p class="muted">Optional admin-only diagnostics overlay for preload, cache, memory, network and frame-timing tuning in the public viewer and fullscreen viewer.</p>';
    echo '<label class="admin-checkbox-row"><input type="checkbox" name="dev_mode_enabled" value="1"' . ($enabled ? ' checked' : '') . '> <span>Enable viewer diagnostics overlay</span></label>';
    echo '<button type="submit" class="secondary">Save dev mode</button></form></section>';
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
    flash_message('admin_notice', 'Dev mode setting saved.');
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
    echo '<button type="submit" class="button is-update-pending">Run database migration</button>';
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
            flash_message('admin_notice', 'Applied migrations: ' . implode(', ', $ran) . '.');
            redirect_to(url_for('admin'));
        }
        admin_log_event('info', 'migrations.current', 'Admin checked migrations and database was already current.');
        flash_message('admin_notice', 'Database is already current.');
        redirect_to(url_for('admin'));
    } catch (Throwable $exception) {
        admin_log_event('error', 'migrations.failed', 'Admin migration run failed.', ['exception' => $exception->getMessage()]);
        flash_message('admin_notice', 'Migration failed: ' . $exception->getMessage());
        redirect_to(url_for('admin'));
    }
}

