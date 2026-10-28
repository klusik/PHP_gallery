<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_gallery_migration.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders shared Admin API and Media gallery migration controls.
 *
 * Responsibilities:
 *   - Keep gallery migration HTML out of controllers and services
 *   - Render source-push and target-pull form fragments
 *   - Expose a focused Media import form using the existing transfer owner
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

use function Gallery\Core\csrf_field;
use function Gallery\Core\e;
use function Gallery\Core\url_for;
use function Gallery\Services\t;

/**
 * Render the full API tools or the focused Media import workflow.
 *
 * @param array{id:int|string,title?:string,name?:string,public_id?:string}
 *     $gallery Gallery identity and optional display data.
 * @param string $currentVersion Current PHP Gallery version.
 * @param int $reconnectSeconds Default reconnect interval.
 * @param bool $importOnly Whether to omit export and simplify import options.
 * @return void Render migration controls for this gallery.
 */
function view_render_admin_gallery_migration_panel(
    array $gallery,
    string $currentVersion,
    int $reconnectSeconds,
    bool $importOnly = false,
): void {
    $galleryId = (int) ($gallery['id'] ?? 0);
    if ($galleryId <= 0) {
        return;
    }

    $title = $importOnly
        ? t('gallery_migration.import_tab', 'Import gallery')
        : t('gallery_migration.title', 'Migrate gallery via API');
    echo
        '<section class="panel admin-gallery-migration-panel" data-gallery-migration data-gallery-migration-endpoint="'
            . e(url_for('admin_gallery_migration'))
            . '" data-gallery-id="'
            . $galleryId
            . '" data-current-version="'
            . e($currentVersion)
            . '">';
    echo
        '<div class="admin-gallery-api-tool-heading"><h3>'
            . e($title)
            . '</h3><details data-gallery-media-widget class="admin-inline-help"><summary aria-label="'
            . e($title)
            . '" title="'
            . e($title)
            . '"><span aria-hidden="true">?</span></summary><div data-gallery-media-panel class="admin-inline-help-content">'
            . e(t(
                'gallery_migration.help',
                'Move gallery trees between PHP Gallery instances using gallery-scoped API keys. Transfers are staged, version-checked, and use resumable ZIP packages containing originals and existing thumbnails.',
            ))
            . '</div></details></div>';
    echo '<div class="admin-gallery-migration-grid">';

    // Transfer orchestration requires JavaScript. Native submission stays POST
    // so a disabled/failed enhancement cannot expose API keys in a query URL.
    if (!$importOnly) {
        echo
            '<form method="post" action="'
                . e(url_for('admin_gallery_migration'))
                . '" class="admin-gallery-migration-card" data-gallery-migration-form data-gallery-migration-mode="source_push">';
        echo csrf_field();
        echo '<input type="hidden" name="gallery_id" value="' . $galleryId . '">';
        echo
            '<div class="admin-gallery-api-tool-heading"><h4>'
                . e(t(
                    'gallery_migration.source_push_title',
                    'Export this gallery to another instance',
                ))
                . '</h4><details data-gallery-media-widget class="admin-inline-help"><summary aria-label="'
                . e(t(
                    'gallery_migration.source_push_title',
                    'Export this gallery to another instance',
                ))
                . '" title="'
                . e(t(
                    'gallery_migration.source_push_title',
                    'Export this gallery to another instance',
                ))
                . '"><span aria-hidden="true">?</span></summary><div data-gallery-media-panel class="admin-inline-help-content">'
                . e(t(
                    'gallery_migration.source_push_help',
                    'Use this gallery as the export root. Enter the target instance URL and an API key generated for the receiving parent gallery. The exported root will be created as a new child below that target.',
                ))
                . '</div></details></div>';
        echo
            '<label>'
                . e(t('gallery_migration.target_url', 'Target PHP Gallery URL'))
                . '<input name="target_url" type="url" placeholder="https://example.com/Galerie" autocomplete="off" required></label>';
        echo
            '<label>'
                . e(t('gallery_migration.target_api_key', 'Target API key'))
                . '<input name="target_api_key" type="password" autocomplete="off" required></label>';
        echo
            '<div class="admin-gallery-api-checkbox"><label class="admin-checkbox-row"><input name="include_subgalleries" type="checkbox" value="1" checked> <span>'
                . e(t(
                    'gallery_migration.include_subgalleries',
                    'Include subgalleries',
                ))
                . '</span></label><details data-gallery-media-widget class="admin-inline-help"><summary aria-label="'
                . e(t(
                    'gallery_migration.include_subgalleries',
                    'Include subgalleries',
                ))
                . '" title="'
                . e(t(
                    'gallery_migration.include_subgalleries',
                    'Include subgalleries',
                ))
                . '"><span aria-hidden="true">?</span></summary><div data-gallery-media-panel class="admin-inline-help-content">'
                . e(t(
                    'gallery_migration.include_subgalleries_help',
                    'Enabled by default. The complete descendant tree is recreated below the imported root gallery.',
                ))
                . '</div></details></div>';
        echo
            '<div class="admin-gallery-api-field admin-gallery-api-reconnect"><label>'
                . e(t(
                    'gallery_migration.reconnect_seconds',
                    'Reconnect interval seconds',
                ))
                . '<input name="reconnect_seconds" type="number" min="5" max="300" step="1" value="'
                . $reconnectSeconds
                . '" inputmode="numeric"></label><details data-gallery-media-widget class="admin-inline-help admin-gallery-api-field-help"><summary aria-label="'
                . e(t(
                    'gallery_migration.reconnect_seconds',
                    'Reconnect interval seconds',
                ))
                . '" title="'
                . e(t(
                    'gallery_migration.reconnect_seconds',
                    'Reconnect interval seconds',
                ))
                . '"><span aria-hidden="true">?</span></summary><div data-gallery-media-panel class="admin-inline-help-content">'
                . e(t(
                    'gallery_migration.reconnect_seconds_help',
                    'Each ZIP transfer request is refreshed after this many seconds. After a timeout or interrupted connection, the active instance asks the target which assets it already received before retrying the package.',
                ))
                . '</div></details></div>';
        echo
            '<div class="admin-gallery-migration-actions"><button type="submit" class="button secondary">'
                . e(t('gallery_migration.start_export', 'Export via API'))
                . '</button><button type="button" class="secondary" data-gallery-migration-cancel hidden>'
                . e(t('gallery_migration.cancel', 'Cancel'))
                . '</button></div>';
        echo
            '<div class="thumbnail-progress admin-gallery-migration-progress" data-gallery-migration-progress hidden><progress class="thumbnail-progress-bar" data-gallery-migration-progress-fill value="0" max="100"></progress><p class="muted" role="status" aria-live="polite" data-gallery-migration-progress-text></p></div>';
        echo
            '<pre class="admin-gallery-migration-log" data-gallery-migration-log hidden></pre>';
        echo '</form>';
    }

    echo
        '<form method="post" action="'
            . e(url_for('admin_gallery_migration'))
            . '" class="admin-gallery-migration-card" data-gallery-migration-form data-gallery-migration-mode="target_pull">';
    echo csrf_field();
    echo '<input type="hidden" name="gallery_id" value="' . $galleryId . '">';
    if ($importOnly) {
        echo '<p class="admin-gallery-import-instructions">'
            . e(t(
                'gallery_migration.import_instructions',
                'In the source gallery, open API, generate a key and copy the gallery import URL and key here.',
            ))
            . '</p><p class="admin-gallery-import-destination">'
            . e(t(
                'gallery_migration.import_destination',
                'Creates a new subgallery here, including photos and existing thumbnails.',
            ))
            . '</p>';
    } else {
        echo
            '<div class="admin-gallery-api-tool-heading"><h4>'
                . e(t(
                    'gallery_migration.target_pull_title',
                    'Import into this gallery from another instance',
                ))
                . '</h4><details data-gallery-media-widget class="admin-inline-help"><summary aria-label="'
                . e(t(
                    'gallery_migration.target_pull_title',
                    'Import into this gallery from another instance',
                ))
                . '" title="'
                . e(t(
                    'gallery_migration.target_pull_title',
                    'Import into this gallery from another instance',
                ))
                . '"><span aria-hidden="true">?</span></summary><div data-gallery-media-panel class="admin-inline-help-content">'
                . e(t(
                    'gallery_migration.target_pull_help',
                    'Use this gallery as the receiving parent. Enter the source instance URL and an API key generated for the source gallery. The source root will be created as a new child below this gallery.',
                ))
                . '</div></details></div>';
    }
    echo
        '<label>'
            . e(t('gallery_migration.source_url', 'Source PHP Gallery URL'))
            . '<input name="source_url" type="url" placeholder="https://example.com/Galerie" autocomplete="off" required></label>';
    echo
        '<label>'
            . e(t('gallery_migration.source_api_key', 'Source API key'))
            . '<input name="source_api_key" type="password" autocomplete="off" required></label>';
    echo
        '<div class="admin-gallery-api-checkbox"><label class="admin-checkbox-row"><input name="include_subgalleries" type="checkbox" value="1" checked> <span>'
            . e(t(
                'gallery_migration.include_subgalleries',
                'Include subgalleries',
            ))
            . '</span></label><details data-gallery-media-widget class="admin-inline-help"><summary aria-label="'
            . e(t(
                'gallery_migration.include_subgalleries',
                'Include subgalleries',
            ))
            . '" title="'
            . e(t(
                'gallery_migration.include_subgalleries',
                'Include subgalleries',
            ))
            . '"><span aria-hidden="true">?</span></summary><div data-gallery-media-panel class="admin-inline-help-content">'
            . e(t(
                'gallery_migration.include_subgalleries_help',
                'Enabled by default. The complete descendant tree is recreated below the imported root gallery.',
            ))
            . '</div></details></div>';
    echo
        ($importOnly
            ? '<details class="admin-gallery-import-options"><summary>'
                . e(t('gallery_migration.connection_options', 'Connection options'))
                . '</summary>'
            : '')
            . '<div class="admin-gallery-api-field admin-gallery-api-reconnect"><label>'
            . e(t(
                'gallery_migration.reconnect_seconds',
                'Reconnect interval seconds',
            ))
            . '<input name="reconnect_seconds" type="number" min="5" max="300" step="1" value="'
            . $reconnectSeconds
            . '" inputmode="numeric"></label><details data-gallery-media-widget class="admin-inline-help admin-gallery-api-field-help"><summary aria-label="'
            . e(t(
                'gallery_migration.reconnect_seconds',
                'Reconnect interval seconds',
            ))
            . '" title="'
            . e(t(
                'gallery_migration.reconnect_seconds',
                'Reconnect interval seconds',
            ))
            . '"><span aria-hidden="true">?</span></summary><div data-gallery-media-panel class="admin-inline-help-content">'
            . e(t(
                'gallery_migration.reconnect_seconds_help',
                'Each ZIP transfer request is refreshed after this many seconds. After a timeout or interrupted connection, the active instance asks the target which assets it already received before retrying the package.',
            ))
            . '</div></details></div>';
    if ($importOnly) {
        echo '</details>';
    }
    echo
        '<div class="admin-gallery-migration-actions"><button type="submit" class="button secondary">'
            . e(t('gallery_migration.start_import', 'Import from API'))
            . '</button><button type="button" class="secondary" data-gallery-migration-cancel hidden>'
            . e(t('gallery_migration.cancel', 'Cancel'))
            . '</button></div>';
    echo
        '<div class="thumbnail-progress admin-gallery-migration-progress" data-gallery-migration-progress hidden><progress class="thumbnail-progress-bar" data-gallery-migration-progress-fill value="0" max="100"></progress><p class="muted" role="status" aria-live="polite" data-gallery-migration-progress-text></p></div>';
    echo
        '<pre class="admin-gallery-migration-log" data-gallery-migration-log hidden></pre>';
    echo '</form>';

    echo '</div>';
    echo '<noscript><p class="notice">'
        . e(t(
            'gallery_migration.javascript_required',
            'Enable JavaScript to transfer gallery packages and follow progress.',
        ))
        . '</p></noscript>';
    echo
        '<p class="muted">'
            . e(t(
                'gallery_migration.version_policy',
                'Version policy: migrations are accepted only when both instances run exactly version {version}. Compatibility rules can be expanded later.',
                ['version' => $currentVersion],
            ))
            . '</p>';
    echo '</section>';
}
