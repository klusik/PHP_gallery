<?php

declare(strict_types=1);

/**
 * Admin dashboard controller model.
 * 
 * This module renders the main admin dashboard, development-mode controls, migration notices, and migration execution. Theme customization remains outside this module.
 */

function cms_admin(): void
{
    require_admin();
    // Self-heal voting/game state only when the admin dashboard is opened.
    $repairedVotingGame = sync_gallery_voting_game_state();
    if ($repairedVotingGame > 0) {
        admin_log_event('info', 'gallery.voting_game_synced', 'Admin dashboard repaired gallery voting/game settings.', [
            'gallery_count' => $repairedVotingGame,
        ]);
    }
    sync_gallery_parent_ids();
    // Variable $galleries stores this steps working value.
    $galleries = db()->query("SELECT g.*, parent.title AS parent_title, COUNT(i.id) AS image_count FROM galleries g LEFT JOIN galleries parent ON parent.id = g.parent_id LEFT JOIN images i ON i.gallery_id = g.id AND i.relative_path NOT LIKE '%/%' GROUP BY g.id, parent.title ORDER BY g.folder_path")->fetchAll();
    // Variable $collapsedIds stores this steps working value.
    $collapsedIds = array_flip(collapsed_gallery_ids());
    // Variable $pictureGameReady stores this steps working value.

    $pictureGameReady = picture_game_schema_ready();
    // Variable $gpsMapReady stores this steps working value.
    $gpsMapReady = exif_gps_schema_ready();
    // Variable $votingReady stores this steps working value.
    $votingReady = gallery_voting_schema_ready();
    // Variable $migrationPending stores this steps working value.
    $migrationPending = pending_migrations_exist();
    // Variable $accessReady stores this steps working value.
    $accessReady = gallery_access_schema_ready();
    // $updatePending stores an intermediate value used by the surrounding gallery workflow.
    $updatePending = application_update_pending();
    // $updateButtonClass stores an intermediate value used by the surrounding gallery workflow.
    $updateButtonClass = $updatePending ? 'button secondary is-update-pending' : 'button secondary';
    // $updateLabel stores an intermediate value used by the surrounding gallery workflow.
    $updateLabel = application_update_nav_label($updatePending);
    // $thumbnailSummary stores an intermediate value used by the surrounding gallery workflow.
    $thumbnailSummary = thumbnail_maintenance_summary(null, 1000);
    render_header('Admin dashboard');
    echo '<section class="hero"><h1>Admin dashboard</h1><nav class="nav">';
    echo '<form method="post" action="' . e(url_for('admin_discover')) . '" class="inline-action-form" data-refresh-galleries-form>' . csrf_field();
    echo '<button type="submit">Check for new gallery folders</button>';
    echo '</form>';
    echo '<a class="button secondary" href="' . e(url_for('admin_new_gallery')) . '">Create empty gallery</a>';
    echo '<a class="button secondary" href="' . e(url_for('admin_upload')) . '">Upload photos</a>';
    echo '<a class="button secondary" href="' . e(url_for('admin_logs')) . '">View log</a>';
    echo '<a class="' . e($updateButtonClass) . '" href="' . e(url_for('admin_update')) . '">' . e($updateLabel) . '</a>';
    if ($migrationPending) {
        echo '<form method="post" action="' . e(url_for('admin_run_migrations')) . '" class="inline-action-form">' . csrf_field();
        echo '<button type="submit" class="button is-update-pending">Run database migration</button>';
        echo '</form>';
    }
    echo '<a class="button secondary" href="' . e(url_for('download_all')) . '">Download all galleries</a>';
    echo '<button type="button" class="secondary" data-create-all-thumbnails>Create all thumbnails</button>';
    echo '<form method="post" action="' . e(url_for('admin_regenerate_paths')) . '" class="inline-action-form" onsubmit="return confirm(\'Regenerate clean public URLs for all galleries and images?\');">' . csrf_field();
    echo '<button type="submit" class="secondary">Regenerate paths</button>';
    echo '</form>';
    echo '</nav></section>';
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
    if ($migrationPending) {
        render_admin_migration_notice('Some admin features still need database migrations.');
    }
    render_admin_thumbnail_maintenance_notice($thumbnailSummary);
    echo '<section class="panel"><h2>Galleries</h2><form method="post" action="' . e(url_for('admin_bulk_galleries')) . '" data-gallery-bulk-form>' . csrf_field();
    echo '<div class="bulk-row">';
    echo '<label>Filter galleries<select data-gallery-visibility-filter><option value="all">All statuses</option><option value="draft">Only drafts</option><option value="public">Only public</option><option value="private">Only private</option></select></label>';
    echo '<span class="muted" data-gallery-filter-summary></span>';
    echo '<label><input type="checkbox" data-select-all="gallery_ids[]"> Select displayed galleries</label><label>Bulk action<select name="action"><option value="scan">Scan/import images</option><option value="thumbs">Create thumbnails</option><option value="public">Set public</option><option value="draft">Set draft</option><option value="private">Set private</option><option value="maps_on">Enable GPS maps</option><option value="maps_off">Disable GPS maps</option><option value="delete">Delete selected galleries</option>';
    if ($votingReady) {
        echo '<option value="vote_on">Enable voting</option><option value="vote_off">Disable voting</option>';
    }
    if ($pictureGameReady) {
        echo '<option value="game_on">Enable picture game</option><option value="game_off">Disable picture game</option>';
    }
    echo '</select></label><button type="submit">Apply to selected</button><button type="button" class="secondary" data-gallery-tree-action="collapse-all">Collapse all</button><button type="button" class="secondary" data-gallery-tree-action="expand-all">Expand all</button></div>';
    echo '<table><thead><tr><th>Select</th><th>Title</th><th>Parent</th><th>Folder</th><th>Status</th>';
    if ($accessReady) {
        echo '<th>Access</th>';
    }
    echo '<th title="Maps">M</th>';
    echo '<th>B</th>';
    if ($votingReady) {
        echo '<th title="Voting">V</th>';
    }
    if ($pictureGameReady) {
        echo '<th title="Game">G</th>';
    }
    echo '<th>Images</th><th>Actions</th></tr></thead><tbody>';
    foreach ($galleries as $gallery) {
        // Variable $depth stores this steps working value.
        $depth = substr_count((string) $gallery['folder_path'], '/');
        // Variable $hasChildren stores this steps working value.
        $hasChildren = array_filter($galleries, static fn (array $candidate): bool => (int) ($candidate['parent_id'] ?? 0) === (int) $gallery['id']);
        // Variable $isCollapsed stores this steps working value.
        $isCollapsed = isset($collapsedIds[(int) $gallery['id']]);
        echo '<tr class="' . ($depth > 0 ? 'is-subgallery' : '') . ($isCollapsed ? ' is-collapsed' : '') . '" data-gallery-row data-gallery-id="' . (int) $gallery['id'] . '" data-parent-id="' . (int) ($gallery['parent_id'] ?? 0) . '" data-depth="' . $depth . '" data-gallery-visibility="' . e((string) $gallery['visibility']) . '" data-gallery-title="' . e((string) $gallery['title']) . '"><td><input type="checkbox" name="gallery_ids[]" value="' . (int) $gallery['id'] . '"></td>';
        // Variable $depthClass stores this steps working value.
        $depthClass = 'tree-depth-' . min($depth, 8);
        echo '<td><span class="tree-title ' . e($depthClass) . '">' . ($hasChildren ? '<button type="button" class="tree-toggle" data-gallery-toggle="' . (int) $gallery['id'] . '" aria-expanded="' . ($isCollapsed ? 'false' : 'true') . '">' . ($isCollapsed ? '+' : '-') . '</button>' : '<span class="tree-spacer" aria-hidden="true"></span>') . ($depth > 0 ? '<span class="tree-branch" aria-hidden="true"></span>' : '') . '<a href="' . e(gallery_public_url($gallery)) . '">' . e($gallery['title']) . '</a></span></td>';
        echo '<td>' . e($gallery['parent_title'] ?: '') . '</td><td>' . e($gallery['folder_path']) . '</td><td>' . e($gallery['visibility']) . '</td>';
        if ($accessReady) {
            // $accessLabel stores an intermediate value used by the surrounding gallery workflow.
            $accessLabel = (string) ($gallery['access_mode'] ?? 'normal') === 'password' ? 'Protected' . ((string) ($gallery['access_listing'] ?? 'listed') === 'unlisted' ? ', unlisted' : ', listed') : 'Normal';
            echo '<td>' . e($accessLabel) . '</td>';
        }
        echo '<td>' . render_admin_feature_flag(exif_gps_schema_ready() && (int) ($gallery['gps_map_enabled'] ?? 0) === 1, '✓', 'GPS maps enabled') . '</td>';
        echo '<td>' . render_admin_feature_flag(gallery_background_source_schema_ready() && gallery_background_source($gallery) !== null, '✓', 'Custom gallery background set') . '</td>';
        if ($votingReady) {
            echo '<td>' . render_admin_feature_flag((int) ($gallery['voting_enabled'] ?? 0) === 1, '✓', 'Voting enabled') . '</td>';
        }
        if ($pictureGameReady) {
            echo '<td>' . render_admin_feature_flag((int) ($gallery['picture_game_enabled'] ?? 0) === 1, '✓', 'Picture game enabled') . '</td>';
        }
        echo '<td>' . (int) $gallery['image_count'] . '</td><td class="nav gallery-row-actions">';
        echo '<a class="gallery-row-action" href="' . e(url_for('admin_edit_gallery', ['id' => $gallery['id']])) . '">Edit</a>';
        echo '<button type="submit" class="secondary gallery-row-action" name="thumbnail_gallery_id" value="' . (int) $gallery['id'] . '" formaction="' . e(url_for('admin_create_thumbnails')) . '">Thumbs</button>';
        echo '</td></tr>';
    }
    echo '</tbody></table></form></section>';
    render_admin_devmode_panel();
    render_footer();
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

