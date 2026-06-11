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
 *   2026-05-27
 */

declare(strict_types=1);

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
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('gallery_migration.kicker', 'Migration')) . '</p><h3>' . e(t('gallery_migration.title', 'Migrate gallery via API')) . '</h3></div><p class="muted">' . e(t('gallery_migration.help', 'Move this gallery between PHP Gallery instances using gallery-scoped API keys. Transfers are staged, version-checked, and send one asset per request.')) . '</p></div>';
    echo '<div class="admin-gallery-migration-grid">';

    echo '<form class="admin-gallery-migration-card" data-gallery-migration-form data-gallery-migration-mode="source_push">';
    echo csrf_field();
    echo '<input type="hidden" name="gallery_id" value="' . $galleryId . '">';
    echo '<h4>' . e(t('gallery_migration.source_push_title', 'Export this gallery to another instance')) . '</h4>';
    echo '<p class="muted">' . e(t('gallery_migration.source_push_help', 'Use this instance as the source. Enter the target gallery URL and a target API key generated for the receiving gallery.')) . '</p>';
    echo '<label>' . e(t('gallery_migration.target_url', 'Target PHP Gallery URL')) . '<input name="target_url" type="url" placeholder="https://example.com/Galerie" autocomplete="off" required></label>';
    echo '<label>' . e(t('gallery_migration.target_api_key', 'Target API key')) . '<input name="target_api_key" type="password" autocomplete="off" required></label>';
    echo '<label>' . e(t('gallery_migration.reconnect_seconds', 'Reconnect interval seconds')) . '<input name="reconnect_seconds" type="number" min="5" max="300" step="1" value="' . GALLERY_MIGRATION_RECONNECT_SECONDS . '" inputmode="numeric"></label>';
    echo '<p class="muted">' . e(t('gallery_migration.reconnect_seconds_help', 'Each transfer request is refreshed after this many seconds. After a timeout or interrupted connection, the active gallery asks the target gallery which assets it already received before retrying.')) . '</p>';
    echo '<div class="admin-gallery-migration-actions"><button type="submit" class="button secondary">' . e(t('gallery_migration.start_export', 'Export via API')) . '</button><button type="button" class="secondary" data-gallery-migration-cancel hidden>' . e(t('gallery_migration.cancel', 'Cancel')) . '</button></div>';
    echo '<div class="thumbnail-progress admin-gallery-migration-progress" data-gallery-migration-progress hidden><progress class="thumbnail-progress-bar" data-gallery-migration-progress-fill value="0" max="100"></progress><p class="muted" data-gallery-migration-progress-text></p></div>';
    echo '<pre class="admin-gallery-migration-log" data-gallery-migration-log hidden></pre>';
    echo '</form>';

    echo '<form class="admin-gallery-migration-card" data-gallery-migration-form data-gallery-migration-mode="target_pull">';
    echo csrf_field();
    echo '<input type="hidden" name="gallery_id" value="' . $galleryId . '">';
    echo '<h4>' . e(t('gallery_migration.target_pull_title', 'Import into this gallery from another instance')) . '</h4>';
    echo '<p class="muted">' . e(t('gallery_migration.target_pull_help', 'Use this gallery as the target. Enter the source gallery URL and an API key generated for the source gallery. The current target folder is filled without changing its location.')) . '</p>';
    echo '<label>' . e(t('gallery_migration.source_url', 'Source PHP Gallery URL')) . '<input name="source_url" type="url" placeholder="https://example.com/Galerie" autocomplete="off" required></label>';
    echo '<label>' . e(t('gallery_migration.source_api_key', 'Source API key')) . '<input name="source_api_key" type="password" autocomplete="off" required></label>';
    echo '<label>' . e(t('gallery_migration.reconnect_seconds', 'Reconnect interval seconds')) . '<input name="reconnect_seconds" type="number" min="5" max="300" step="1" value="' . GALLERY_MIGRATION_RECONNECT_SECONDS . '" inputmode="numeric"></label>';
    echo '<p class="muted">' . e(t('gallery_migration.reconnect_seconds_help', 'Each transfer request is refreshed after this many seconds. After a timeout or interrupted connection, the active gallery asks the target gallery which assets it already received before retrying.')) . '</p>';
    echo '<div class="admin-gallery-migration-actions"><button type="submit" class="button secondary">' . e(t('gallery_migration.start_import', 'Import from API')) . '</button><button type="button" class="secondary" data-gallery-migration-cancel hidden>' . e(t('gallery_migration.cancel', 'Cancel')) . '</button></div>';
    echo '<div class="thumbnail-progress admin-gallery-migration-progress" data-gallery-migration-progress hidden><progress class="thumbnail-progress-bar" data-gallery-migration-progress-fill value="0" max="100"></progress><p class="muted" data-gallery-migration-progress-text></p></div>';
    echo '<pre class="admin-gallery-migration-log" data-gallery-migration-log hidden></pre>';
    echo '</form>';

    echo '</div>';
    echo '<p class="muted">' . e(t('gallery_migration.version_policy', 'Version policy: migrations are accepted only when both instances run exactly version {version}. Compatibility rules can be expanded later.', ['version' => gallery_migration_current_version()])) . '</p>';
    echo '</section>';
}
