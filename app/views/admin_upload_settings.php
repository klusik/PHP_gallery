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
 *   - Render general upload preferences and experimental browser pipeline settings
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

/**
 * Render the reusable upload format support matrix.
 *
 * @param array<string, bool> $support Browser and server capability flags.
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
 * @param array<string, bool> $support Browser and server capability flags.
 */
function view_render_admin_upload_support_panel(array $support): void
{
    echo '<section class="panel compact-support"><div class="admin-tab-intro admin-cinematic-intro"><div><p class="admin-kicker">' . e(t('admin.upload.support_kicker', 'Upload')) . '</p><h2>' . e(t('admin.upload.support_title', 'Upload support')) . '</h2></div><div class="admin-cinematic-intro-side"><p class="muted">' . e(t('admin.upload.support_settings_hint', 'Format handling and experimental browser pipeline limits are configured on the dedicated upload settings page.')) . '</p><div class="admin-hero-actions"><a class="button secondary" href="' . e(url_for('admin_upload_settings')) . '">' . e(t('admin.upload.open_upload_settings', 'Upload settings')) . '</a></div></div></div>';
    view_render_admin_upload_support_matrix($support);
    echo '</section>';
}

/**
 * Render the dedicated Admin upload settings page.
 *
 * @param array<string, mixed> $model Settings page view model.
 */
function view_render_admin_upload_settings_page(array $model): void
{
    $activeTab = view_admin_upload_settings_normalize_tab((string) ($model['active_tab'] ?? 'general'));
    $notices = is_array($model['notices'] ?? null) ? $model['notices'] : [];
    $support = is_array($model['support'] ?? null) ? $model['support'] : [];
    $experimentalSettings = is_array($model['experimental_settings'] ?? null) ? $model['experimental_settings'] : [];

    render_header(t('admin.upload_settings.title', 'Upload settings'));
    view_render_admin_hero([
        'kicker' => t('admin.upload_settings.kicker', 'Admin settings'),
        'title' => t('admin.upload_settings.title', 'Upload settings'),
        'description' => t('admin.upload_settings.description', 'Configure upload preferences separately from the upload workflow. The experimental browser pipeline remains opt-in per upload.'),
        'actions' => [
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
        ['id' => 'upload-settings-experimental', 'label' => t('admin.upload_settings.experimental_tab', 'Experimental browser pipeline')],
    ];
    $activeId = $activeTab === 'experimental' ? 'upload-settings-experimental' : 'upload-settings-general';
    echo '<div class="admin-subtab-scope admin-upload-settings-scope" data-admin-subtab-scope>';
    view_render_admin_subtabs($tabs, $activeId, t('admin.upload_settings.tabs_aria', 'Upload settings sections'));

    ob_start();
    view_render_admin_upload_general_settings_form($model);
    view_render_admin_subtab_panel('upload-settings-general', (string) ob_get_clean(), $activeTab === 'general');

    ob_start();
    view_render_admin_upload_experimental_settings_form($experimentalSettings);
    view_render_admin_subtab_panel('upload-settings-experimental', (string) ob_get_clean(), $activeTab === 'experimental');
    echo '</div>';

    render_footer();
}

/**
 * Normalize the upload settings tab name used by controllers and views.
 */
function view_admin_upload_settings_normalize_tab(string $tab): string
{
    return $tab === 'experimental' ? 'experimental' : 'general';
}

/**
 * Render the general upload preferences form.
 *
 * @param array<string, mixed> $model Settings page view model.
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
 * Render the experimental browser pipeline settings form.
 *
 * @param array<string, mixed> $settings Normalized experimental settings.
 */
function view_render_admin_upload_experimental_settings_form(array $settings): void
{
    $maxZipMegabytes = number_format(((int) ($settings['max_zip_batch_bytes'] ?? (24 * 1024 * 1024))) / 1048576, 0, '.', '');
    $sourceChunkMegabytes = number_format(((int) ($settings['thumbnail_rebuild_source_chunk_bytes'] ?? (512 * 1024 * 1024))) / 1048576, 0, '.', '');

    view_render_admin_tab_intro([
        'kicker' => t('admin.upload_settings.experimental_kicker', 'Experimental'),
        'title' => t('admin.upload_settings.experimental_title', 'Browser-side preparation limits'),
        'description' => t('admin.upload_settings.experimental_description', 'These settings only control the opt-in browser pipeline. The upload form checkbox remains off by default.'),
    ]);

    echo '<form method="post" action="' . e(url_for('admin_upload_settings')) . '" class="form-grid admin-upload-settings-form">' . csrf_field();
    echo '<input type="hidden" name="update_experimental_upload_settings" value="1">';
    echo '<div class="experimental-upload-settings-grid">';
    echo '<label class="checkbox-label"><input type="checkbox" name="experimental_upload_enabled" value="1"' . (!empty($settings['enabled']) ? ' checked' : '') . '> <span>' . e(t('admin.upload.experimental_enabled', 'Allow experimental browser-side upload option')) . '</span><span class="muted">' . e(t('admin.upload.experimental_enabled_help', 'The upload form still defaults to the normal server-side pipeline. This only allows admins to opt in per upload.')) . '</span></label>';
    echo '<label>' . e(t('admin.upload.experimental_default_workers', 'Default worker count')) . '<input type="number" name="experimental_upload_default_worker_count" min="1" max="32" value="' . (int) ($settings['default_worker_count'] ?? 8) . '"><span class="muted">' . e(t('admin.upload.experimental_default_workers_help', 'Default is 8. The browser will also respect the maximum worker count and hard cap.')) . '</span></label>';
    echo '<label>' . e(t('admin.upload.experimental_max_workers', 'Maximum worker count')) . '<input type="number" name="experimental_upload_max_worker_count" min="1" max="32" value="' . (int) ($settings['max_worker_count'] ?? 32) . '"><span class="muted">' . e(t('admin.upload.experimental_max_workers_help', 'Upper bound for worker pool parallelism.')) . '</span></label>';
    echo '<label>' . e(t('admin.upload.experimental_hard_cap', 'Worker hard cap')) . '<input type="number" name="experimental_upload_hard_worker_cap" min="1" max="32" value="' . (int) ($settings['hard_worker_cap'] ?? 32) . '"><span class="muted">' . e(t('admin.upload.experimental_hard_cap_help', 'Absolute maximum is 32, even if the submitted value is higher.')) . '</span></label>';
    echo '<label>' . e(t('admin.upload.experimental_batch_policy', 'Batch size policy')) . '<select name="experimental_upload_batch_size_policy"><option value="upload_limit_ratio" selected>' . e(t('admin.upload.experimental_batch_policy_ratio', 'Use PHP upload limit ratio')) . '</option></select><span class="muted">' . e(t('admin.upload.experimental_batch_policy_help', 'ZIP packages are split below the detected upload_max_filesize and post_max_size limits.')) . '</span></label>';
    echo '<label>' . e(t('admin.upload.experimental_zip_ratio', 'ZIP size threshold ratio')) . '<input type="number" name="experimental_upload_zip_size_threshold_ratio" min="0.10" max="0.95" step="0.05" value="' . e(number_format((float) ($settings['zip_size_threshold_ratio'] ?? 0.8), 2, '.', '')) . '"><span class="muted">' . e(t('admin.upload.experimental_zip_ratio_help', '0.80 means each store-only ZIP batch targets about 80% of the server upload limit.')) . '</span></label>';
    echo '<label>' . e(t('admin.upload.experimental_max_items_per_batch', 'Maximum images per ZIP batch')) . '<input type="number" name="experimental_upload_max_items_per_batch" min="1" max="64" value="' . (int) ($settings['max_items_per_batch'] ?? 8) . '"><span class="muted">' . e(t('admin.upload.experimental_max_items_per_batch_help', 'Default is 8. This keeps many small uploads from becoming one huge server-side unpacking job.')) . '</span></label>';
    echo '<label>' . e(t('admin.upload.experimental_max_zip_batch_mb', 'Absolute ZIP batch cap, MB')) . '<input type="number" name="experimental_upload_max_zip_batch_megabytes" min="1" max="128" step="1" value="' . e($maxZipMegabytes) . '"><span class="muted">' . e(t('admin.upload.experimental_max_zip_batch_mb_help', 'Default is 24 MB. This cap is applied in addition to the PHP upload limit ratio so shared hosting does not need to parse very large ZIP payloads in one request.')) . '</span></label>';
    echo '<label>' . e(t('admin.upload.experimental_thumbnail_source_chunk_mb', 'Thumbnail rebuild source chunk, MB')) . '<input type="number" name="experimental_thumbnail_rebuild_source_chunk_megabytes" min="16" max="3072" step="16" value="' . e($sourceChunkMegabytes) . '"><span class="muted">' . e(t('admin.upload.experimental_thumbnail_source_chunk_mb_help', 'Default is 512 MB. This controls how many original source files the browser downloads per experimental thumbnail-rebuild chunk. Large values are fast on strong browsers but use more RAM and bandwidth.')) . '</span></label>';
    echo '</div>';
    echo '<button type="submit" class="secondary">' . e(t('admin.upload_settings.save_experimental', 'Save experimental upload settings')) . '</button>';
    echo '</form>';
}
