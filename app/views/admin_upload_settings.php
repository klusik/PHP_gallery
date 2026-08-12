<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_upload_settings.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders dedicated Admin upload settings pages and shared upload capability
 *   panels used by the upload workflow.
 *
 * Responsibilities:
 *   - Keep upload settings form markup outside the upload controller
 *   - Render general upload preferences and browser pipeline settings
 *   - Reuse the same upload support matrix on the settings and upload screens
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
 *   2026-06-10
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\csrf_field;
use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\url_for;
use function Gallery\Services\admin_settings_url;
use function Gallery\Services\media_renamer_default_pattern;
use function Gallery\Services\t;

/**
 * Render the reusable upload format support matrix.
 *
 * @param array $support Support value.
 */
function view_render_admin_upload_support_matrix(array $support): void
{
    $heicSupported = !empty($support['heic']);
    $rawSupported = !empty($support['raw']);

    echo '<table class="support-matrix"><thead><tr><th>' . e(t('admin.upload.type', 'Type')) . '</th><th>JPG</th><th>PNG</th><th>GIF</th><th>WebP</th><th>HEIC</th><th>DNG</th></tr></thead><tbody><tr>';
    echo '<th scope="row">' . e(t('admin.upload.available', 'Available')) . '</th>';
    echo '<td class="support-yes">✓</td>';
    echo '<td class="support-yes">✓</td>';
    echo '<td class="support-yes">✓</td>';
    echo '<td class="support-yes">✓</td>';
    echo '<td class="' . ($heicSupported ? 'support-yes' : 'support-no') . '">' . ($heicSupported ? '✓' : '✕') . '</td>';
    echo '<td class="' . ($rawSupported ? 'support-yes' : 'support-no') . '">' . ($rawSupported ? '✓' : '✕') . '</td>';
    echo '</tr></tbody></table>';
}

/**
 * Render a compact upload support panel for the normal upload page.
 *
 * @param array $support Support value.
 */
function view_render_admin_upload_support_panel(array $support): void
{
    echo '<section class="panel compact-support"><div class="admin-tab-intro admin-cinematic-intro"><div><p class="admin-kicker">' . e(t('admin.upload.support_kicker', 'Upload')) . '</p><h2>' . e(t('admin.upload.support_title', 'Upload support')) . '</h2></div><div class="admin-cinematic-intro-side"><p class="muted">' . e(t('admin.upload.support_settings_hint', 'Format handling and browser pipeline limits are configured on the dedicated upload settings page.')) . '</p><div class="admin-hero-actions"><a class="button secondary" href="' . e(url_for('admin_upload_settings')) . '">' . e(t('admin.upload.open_upload_settings', 'Upload settings')) . '</a></div></div></div>';
    view_render_admin_upload_support_matrix($support);
    echo '</section>';
}

/**
 * Render the dedicated Admin upload settings page.
 *
 * @param array $model Model value.
 */
function view_render_admin_upload_settings_page(array $model): void
{
    $activeTab = view_admin_upload_settings_normalize_tab((string) ($model['active_tab'] ?? 'general'));
    $notices = is_array($model['notices'] ?? null) ? $model['notices'] : [];
    $support = is_array($model['support'] ?? null) ? $model['support'] : [];
    $browserSettings = is_array($model['browser_settings'] ?? null) ? $model['browser_settings'] : [];

    render_header(t('admin.upload_settings.title', 'Upload settings'));
    view_render_admin_hero([
        'kicker' => t('admin.upload_settings.kicker', 'Admin settings'),
        'title' => t('admin.upload_settings.title', 'Upload settings'),
        'description' => t('admin.upload_settings.description', 'Configure upload preferences separately from the upload workflow. Browser-side preparation is the default when enabled, and the upload form can still be unchecked to use the normal server fallback.'),
        'actions' => [
            ['label' => t('admin.settings.open_centralized', 'Open centralized settings'), 'url' => admin_settings_url('uploads'), 'class' => 'button secondary'],
            ['label' => t('admin.upload_settings.back_to_upload', 'Upload photos'), 'url' => url_for('admin_upload'), 'class' => 'button secondary'],
            ['label' => t('admin.common.back_to_dashboard', 'Back to dashboard'), 'url' => url_for('admin'), 'class' => 'button secondary'],
        ],
        'actions_aria_label' => t('admin.upload_settings.actions_label', 'Upload settings actions'),
    ]);

    foreach ($notices as $notice) {
        $kind = (string) ($notice['kind'] ?? 'success');
        $message = trim((string) ($notice['message'] ?? ''));
        if ($message === '') {
            continue;
        }
        echo '<div class="notice ' . e($kind) . '">' . e($message) . '</div>';
    }

    echo '<section class="panel compact-support"><h2>' . e(t('admin.upload.support_title', 'Upload support')) . '</h2>';
    view_render_admin_upload_support_matrix($support);
    echo '</section>';

    $tabs = [
        ['id' => 'upload-settings-general', 'label' => t('admin.upload_settings.general_tab', 'General')],
        ['id' => 'upload-settings-browser', 'label' => t('admin.upload_settings.browser_tab', 'Browser pipeline')],
    ];
    $activeId = $activeTab === 'browser' ? 'upload-settings-browser' : 'upload-settings-general';
    echo '<div class="admin-subtab-scope admin-upload-settings-scope" data-admin-subtab-scope>';
    view_render_admin_subtabs($tabs, $activeId, t('admin.upload_settings.tabs_aria', 'Upload settings sections'));

    ob_start();
    view_render_admin_upload_general_settings_form($model);
    view_render_admin_subtab_panel('upload-settings-general', (string) ob_get_clean(), $activeTab === 'general');

    ob_start();
    view_render_admin_upload_browser_settings_form($browserSettings);
    view_render_admin_subtab_panel('upload-settings-browser', (string) ob_get_clean(), $activeTab === 'browser');
    echo '</div>';

    render_footer();
}

/**
 * Normalize the upload settings tab name used by controllers and views.
 *
 * @param string $tab Tab value.
 * @return string Text result for the caller.
 */
function view_admin_upload_settings_normalize_tab(string $tab): string
{
    return $tab === 'browser' ? 'browser' : 'general';
}

/**
 * Render the general upload preferences form.
 *
 * @param array $model Model value.
 */
function view_render_admin_upload_general_settings_form(array $model): void
{
    $clientFormatMode = (string) ($model['client_format_mode'] ?? 'server_supported');
    $autoRenameEnabled = !empty($model['auto_rename_enabled']);

    view_render_admin_tab_intro([
        'kicker' => t('admin.upload_settings.general_kicker', 'Normal upload path'),
        'title' => t('admin.upload_settings.general_title', 'General upload preferences'),
        'description' => t('admin.upload_settings.general_description', 'These settings affect the default server-side upload path and companion upload tools.'),
    ]);

    echo '<form method="post" action="' . e(url_for('admin_upload_settings')) . '" class="form-grid admin-upload-settings-form">' . csrf_field();
    echo '<input type="hidden" name="update_upload_general_settings" value="1">';
    echo '<label>' . e(t('admin.upload.client_format_mode', 'Phone upload format')) . '<select name="admin_upload_client_format_mode"><option value="server_supported"' . ($clientFormatMode === 'server_supported' ? ' selected' : '') . '>' . e(t('admin.upload.client_format_server_supported', 'Allow all server-supported formats')) . '</option><option value="phone_jpeg"' . ($clientFormatMode === 'phone_jpeg' ? ' selected' : '') . '>' . e(t('admin.upload.client_format_phone_jpeg', 'Prefer phone-rendered JPG/PNG/WebP, no RAW/DNG')) . '</option></select><span class="muted">' . e(t('admin.upload.client_format_help', 'Use the phone-rendered mode when iPhone ProRAW/DNG uploads produce poor color. Browsers treat this as a picker request, not an absolute conversion guarantee.')) . '</span></label>';
    echo '<label class="checkbox-label"><input type="checkbox" name="admin_upload_auto_rename_enabled" value="1"' . ($autoRenameEnabled ? ' checked' : '') . '> <span>' . e(t('admin.upload.auto_rename_enabled', 'Rename uploaded photos automatically')) . '</span><span class="muted">' . e(t('admin.upload.auto_rename_help', 'When enabled, browser, API, and WebDAV uploads are renamed after scan with the same default media-renamer template: {pattern}.', ['pattern' => media_renamer_default_pattern()])) . '</span></label>';
    echo '<button type="submit" class="secondary">' . e(t('admin.upload_settings.save_general', 'Save general upload settings')) . '</button>';
    echo '</form>';
}

/**
 * Render the browser pipeline settings form.
 *
 * @param array $settings Settings used by this workflow.
 */
function view_render_admin_upload_browser_settings_form(array $settings): void
{
    $maxZipMegabytes = number_format(((int) ($settings['max_zip_batch_bytes'] ?? (24 * 1024 * 1024))) / 1048576, 0, '.', '');
    $sourceChunkMegabytes = number_format(((int) ($settings['thumbnail_rebuild_source_chunk_bytes'] ?? (512 * 1024 * 1024))) / 1048576, 0, '.', '');

    view_render_admin_tab_intro([
        'kicker' => t('admin.upload_settings.browser_kicker', 'Browser'),
        'title' => t('admin.upload_settings.browser_title', 'Browser-side preparation limits'),
        'description' => t('admin.upload_settings.browser_description', 'These settings control the browser-side preparation pipeline. When enabled, upload forms keep it checked by default and can be unchecked per upload to use the normal server fallback.'),
    ]);

    echo '<form method="post" action="' . e(url_for('admin_upload_settings')) . '" class="form-grid admin-upload-settings-form">' . csrf_field();
    echo '<input type="hidden" name="update_browser_upload_settings" value="1">';
    echo '<div class="browser-upload-settings-grid">';
    echo '<label class="checkbox-label"><input type="checkbox" name="browser_upload_enabled" value="1"' . (!empty($settings['enabled']) ? ' checked' : '') . '> <span>' . e(t('admin.upload.browser_enabled', 'Enable browser-side upload preparation')) . '</span><span class="muted">' . e(t('admin.upload.browser_enabled_help', 'When enabled, upload forms use browser-side preparation by default. Unchecking the upload-form option uses the normal server-side fallback.')) . '</span></label>';
    echo '<label>' . e(t('admin.upload.browser_default_workers', 'Default worker count')) . '<input type="number" name="browser_upload_default_worker_count" min="1" max="32" value="' . (int) ($settings['default_worker_count'] ?? 8) . '"><span class="muted">' . e(t('admin.upload.browser_default_workers_help', 'Default is 8. The browser will also respect the maximum worker count and hard cap.')) . '</span></label>';
    echo '<label>' . e(t('admin.upload.browser_max_workers', 'Maximum worker count')) . '<input type="number" name="browser_upload_max_worker_count" min="1" max="32" value="' . (int) ($settings['max_worker_count'] ?? 32) . '"><span class="muted">' . e(t('admin.upload.browser_max_workers_help', 'Upper bound for worker pool parallelism.')) . '</span></label>';
    echo '<label>' . e(t('admin.upload.browser_hard_cap', 'Worker hard cap')) . '<input type="number" name="browser_upload_hard_worker_cap" min="1" max="32" value="' . (int) ($settings['hard_worker_cap'] ?? 32) . '"><span class="muted">' . e(t('admin.upload.browser_hard_cap_help', 'Absolute maximum is 32, even if the submitted value is higher.')) . '</span></label>';
    echo '<label>' . e(t('admin.upload.browser_batch_policy', 'Batch size policy')) . '<select name="browser_upload_batch_size_policy"><option value="upload_limit_ratio" selected>' . e(t('admin.upload.browser_batch_policy_ratio', 'Use PHP upload limit ratio')) . '</option></select><span class="muted">' . e(t('admin.upload.browser_batch_policy_help', 'ZIP packages are split below the detected upload_max_filesize and post_max_size limits.')) . '</span></label>';
    echo '<label>' . e(t('admin.upload.browser_zip_ratio', 'ZIP size threshold ratio')) . '<input type="number" name="browser_upload_zip_size_threshold_ratio" min="0.10" max="0.95" step="0.05" value="' . e(number_format((float) ($settings['zip_size_threshold_ratio'] ?? 0.8), 2, '.', '')) . '"><span class="muted">' . e(t('admin.upload.browser_zip_ratio_help', '0.80 means each store-only ZIP batch targets about 80% of the server upload limit.')) . '</span></label>';
    echo '<label>' . e(t('admin.upload.browser_max_items_per_batch', 'Maximum images per ZIP batch')) . '<input type="number" name="browser_upload_max_items_per_batch" min="1" max="64" value="' . (int) ($settings['max_items_per_batch'] ?? 8) . '"><span class="muted">' . e(t('admin.upload.browser_max_items_per_batch_help', 'Default is 8. This keeps many small uploads from becoming one huge server-side unpacking job.')) . '</span></label>';
    echo '<label>' . e(t('admin.upload.browser_max_zip_batch_mb', 'Absolute ZIP batch cap, MB')) . '<input type="number" name="browser_upload_max_zip_batch_megabytes" min="1" max="128" step="1" value="' . e($maxZipMegabytes) . '"><span class="muted">' . e(t('admin.upload.browser_max_zip_batch_mb_help', 'Default is 24 MB. This cap is applied in addition to the PHP upload limit ratio so shared hosting does not need to parse very large ZIP payloads in one request.')) . '</span></label>';
    echo '<label>' . e(t('admin.upload.browser_thumbnail_source_chunk_mb', 'Thumbnail rebuild source chunk, MB')) . '<input type="number" name="browser_thumbnail_rebuild_source_chunk_megabytes" min="16" max="3072" step="16" value="' . e($sourceChunkMegabytes) . '"><span class="muted">' . e(t('admin.upload.browser_thumbnail_source_chunk_mb_help', 'Default is 512 MB. This controls how many original source files the browser downloads per browser thumbnail rebuild chunk. Large values are fast on strong browsers but use more RAM and bandwidth.')) . '</span></label>';
    echo '</div>';
    echo '<button type="submit" class="secondary">' . e(t('admin.upload_settings.save_browser', 'Save browser upload settings')) . '</button>';
    echo '</form>';
}
