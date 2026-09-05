<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_gallery_migration.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders Admin API-tab gallery migration controls.
 *
 * Responsibilities:
 *   - Keep gallery migration HTML out of controllers and services
 *   - Render source-push and target-pull form fragments
 *   - Preserve the existing server-rendered Admin UI style
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
 *   2026-09-05
 */

declare(strict_types=1);

namespace Gallery\Views;

use const Gallery\Services\GALLERY_MIGRATION_RECONNECT_SECONDS;
use function Gallery\Core\csrf_field;
use function Gallery\Core\e;
use function Gallery\Core\url_for;
use function Gallery\Services\gallery_migration_current_version;
use function Gallery\Services\t;

/**
 * Render the gallery migration controls for the API tab.
 *
 * @param array $gallery Gallery row or gallery data.
 */
function view_render_admin_gallery_migration_panel(array $gallery): void
{
    $galleryId = (int) ($gallery['id'] ?? 0);
    if ($galleryId <= 0) {
        return;
    }

    echo '<section class="panel admin-gallery-migration-panel" data-gallery-migration data-gallery-migration-endpoint="' . e(url_for('admin_gallery_migration')) . '" data-gallery-id="' . $galleryId . '" data-current-version="' . e(gallery_migration_current_version()) . '">';
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('gallery_migration.kicker', 'Migration')) . '</p><h3>' . e(t('gallery_migration.title', 'Migrate gallery via API')) . '</h3></div><p class="muted">' . e(t('gallery_migration.help', 'Move gallery trees between PHP Gallery instances using gallery-scoped API keys. Transfers are staged, version-checked, and use resumable ZIP packages containing originals and existing thumbnails.')) . '</p></div>';
    echo '<div class="admin-gallery-migration-grid">';

    echo '<form class="admin-gallery-migration-card" data-gallery-migration-form data-gallery-migration-mode="source_push">';
    echo csrf_field();
    echo '<input type="hidden" name="gallery_id" value="' . $galleryId . '">';
    echo '<h4>' . e(t('gallery_migration.source_push_title', 'Export this gallery to another instance')) . '</h4>';
    echo '<p class="muted">' . e(t('gallery_migration.source_push_help', 'Use this gallery as the export root. Enter the target instance URL and an API key generated for the receiving parent gallery. The exported root will be created as a new child below that target.')) . '</p>';
    echo '<label>' . e(t('gallery_migration.target_url', 'Target PHP Gallery URL')) . '<input name="target_url" type="url" placeholder="https://example.com/Galerie" autocomplete="off" required></label>';
    echo '<label>' . e(t('gallery_migration.target_api_key', 'Target API key')) . '<input name="target_api_key" type="password" autocomplete="off" required></label>';
    echo '<label class="admin-checkbox-row"><input name="include_subgalleries" type="checkbox" value="1" checked> <span>' . e(t('gallery_migration.include_subgalleries', 'Include subgalleries')) . '</span></label>';
    echo '<p class="muted">' . e(t('gallery_migration.include_subgalleries_help', 'Enabled by default. The complete descendant tree is recreated below the imported root gallery.')) . '</p>';
    echo '<label>' . e(t('gallery_migration.reconnect_seconds', 'Reconnect interval seconds')) . '<input name="reconnect_seconds" type="number" min="5" max="300" step="1" value="' . GALLERY_MIGRATION_RECONNECT_SECONDS . '" inputmode="numeric"></label>';
    echo '<p class="muted">' . e(t('gallery_migration.reconnect_seconds_help', 'Each ZIP transfer request is refreshed after this many seconds. After a timeout or interrupted connection, the active instance asks the target which assets it already received before retrying the package.')) . '</p>';
    echo '<div class="admin-gallery-migration-actions"><button type="submit" class="button secondary">' . e(t('gallery_migration.start_export', 'Export via API')) . '</button><button type="button" class="secondary" data-gallery-migration-cancel hidden>' . e(t('gallery_migration.cancel', 'Cancel')) . '</button></div>';
    echo '<div class="thumbnail-progress admin-gallery-migration-progress" data-gallery-migration-progress hidden><progress class="thumbnail-progress-bar" data-gallery-migration-progress-fill value="0" max="100"></progress><p class="muted" data-gallery-migration-progress-text></p></div>';
    echo '<pre class="admin-gallery-migration-log" data-gallery-migration-log hidden></pre>';
    echo '</form>';

    echo '<form class="admin-gallery-migration-card" data-gallery-migration-form data-gallery-migration-mode="target_pull">';
    echo csrf_field();
    echo '<input type="hidden" name="gallery_id" value="' . $galleryId . '">';
    echo '<h4>' . e(t('gallery_migration.target_pull_title', 'Import into this gallery from another instance')) . '</h4>';
    echo '<p class="muted">' . e(t('gallery_migration.target_pull_help', 'Use this gallery as the receiving parent. Enter the source instance URL and an API key generated for the source gallery. The source root will be created as a new child below this gallery.')) . '</p>';
    echo '<label>' . e(t('gallery_migration.source_url', 'Source PHP Gallery URL')) . '<input name="source_url" type="url" placeholder="https://example.com/Galerie" autocomplete="off" required></label>';
    echo '<label>' . e(t('gallery_migration.source_api_key', 'Source API key')) . '<input name="source_api_key" type="password" autocomplete="off" required></label>';
    echo '<label class="admin-checkbox-row"><input name="include_subgalleries" type="checkbox" value="1" checked> <span>' . e(t('gallery_migration.include_subgalleries', 'Include subgalleries')) . '</span></label>';
    echo '<p class="muted">' . e(t('gallery_migration.include_subgalleries_help', 'Enabled by default. The complete descendant tree is recreated below the imported root gallery.')) . '</p>';
    echo '<label>' . e(t('gallery_migration.reconnect_seconds', 'Reconnect interval seconds')) . '<input name="reconnect_seconds" type="number" min="5" max="300" step="1" value="' . GALLERY_MIGRATION_RECONNECT_SECONDS . '" inputmode="numeric"></label>';
    echo '<p class="muted">' . e(t('gallery_migration.reconnect_seconds_help', 'Each ZIP transfer request is refreshed after this many seconds. After a timeout or interrupted connection, the active instance asks the target which assets it already received before retrying the package.')) . '</p>';
    echo '<div class="admin-gallery-migration-actions"><button type="submit" class="button secondary">' . e(t('gallery_migration.start_import', 'Import from API')) . '</button><button type="button" class="secondary" data-gallery-migration-cancel hidden>' . e(t('gallery_migration.cancel', 'Cancel')) . '</button></div>';
    echo '<div class="thumbnail-progress admin-gallery-migration-progress" data-gallery-migration-progress hidden><progress class="thumbnail-progress-bar" data-gallery-migration-progress-fill value="0" max="100"></progress><p class="muted" data-gallery-migration-progress-text></p></div>';
    echo '<pre class="admin-gallery-migration-log" data-gallery-migration-log hidden></pre>';
    echo '</form>';

    echo '</div>';
    echo '<p class="muted">' . e(t('gallery_migration.version_policy', 'Version policy: migrations are accepted only when both instances run exactly version {version}. Compatibility rules can be expanded later.', ['version' => gallery_migration_current_version()])) . '</p>';
    echo '</section>';
}
