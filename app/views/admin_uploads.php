<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_uploads.php
 * Module Type: View
 *
 * Purpose:
 *   Renders Admin upload page, side-panel workflows, and upload form fragments.
 *
 * Responsibilities:
 *   - Render upload page and side-panel shells from controller-prepared state
 *   - Render existing-gallery and create-gallery upload forms
 *   - Render browser-assisted upload controls
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Upload persistence, source validation, gallery lookup, capability detection, CSRF issuance,
 *     option generation, browser config, and mutation response policy remain outside this view.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Services\t;

/** @param array<string,mixed> $viewModel Controller-prepared full upload page. */
function view_render_admin_upload_page(array $viewModel): void
{
    $title = (string) ($viewModel['title'] ?? '');
    render_header($title);
    echo '<section class="hero"><h1>' . e($title) . '</h1><nav class="nav"><a class="button secondary" href="' . e((string) ($viewModel['dashboard_url'] ?? '')) . '">' . e((string) ($viewModel['dashboard_label'] ?? '')) . '</a><a class="button secondary" href="' . e((string) ($viewModel['new_gallery_url'] ?? '')) . '">' . e((string) ($viewModel['new_gallery_label'] ?? '')) . '</a></nav></section>';
    foreach ((array) ($viewModel['notices'] ?? []) as $notice) {
        if ((string) $notice !== '') {
            echo '<div class="notice">' . e((string) $notice) . '</div>';
        }
    }
    echo (string) ($viewModel['support_html'] ?? '');
    foreach ((array) ($viewModel['form_fragments'] ?? []) as $fragment) {
        echo (string) $fragment;
    }
    render_footer();
}

/** @param array<string,mixed> $viewModel Controller-prepared upload side panel. */
function view_render_admin_upload_side_panel(array $viewModel): void
{
    echo '<div class="admin-side-panel-stack" data-admin-upload-panel>';
    if (!empty($viewModel['create_mode'])) {
        echo '<div class="admin-side-panel-copy"><p class="admin-kicker">' . e(t('gallery.workflow', 'Gallery workflow')) . '</p><h2>' . e(t('admin.upload.create_gallery_here', 'Create gallery here')) . '</h2><p class="muted">' . e(t('admin.upload.create_gallery_here_help', 'Create a child gallery and upload photos in the same workflow. Photos are optional, so the gallery can still be created empty when needed.')) . '</p></div>';
    } else {
        echo '<div class="admin-side-panel-copy"><p class="admin-kicker">' . e(t('admin.upload.workflow', 'Upload workflow')) . '</p><h2>' . e(t('admin.upload.title', 'Upload photos')) . '</h2><p class="muted">' . e(t('admin.upload.existing_panel_help', 'Add photos to an existing gallery without leaving the drawer.')) . '</p></div>';
    }
    foreach ((array) ($viewModel['notices'] ?? []) as $notice) {
        if ((string) $notice !== '') {
            echo '<div class="notice">' . e((string) $notice) . '</div>';
        }
    }
    echo (string) ($viewModel['support_html'] ?? '');
    echo (string) ($viewModel['form_html'] ?? '');
    echo '</div>';
}

/** @param array<string,mixed> $viewModel Controller-prepared browser upload toggle. */
function view_render_admin_upload_browser_checkbox(array $viewModel): void
{
    $disabled = !empty($viewModel['disabled']);
    echo '<label class="' . e((string) ($viewModel['class_name'] ?? 'browser-upload-toggle')) . '"><input type="checkbox" name="browser_client_upload" value="1" data-browser-upload-toggle data-browser-upload-config="' . e((string) ($viewModel['encoded_config'] ?? '{}')) . '"' . ($disabled ? '' : ' checked') . ($disabled ? ' disabled' : '') . '> <span>' . e(t('admin.upload.browser_client_upload_label', 'Prepare thumbnails and ZIP batches in this browser')) . '</span><span class="muted">' . e(t('admin.upload.browser_client_upload_help', 'Checked by default. When selected photos are present, browser-side processing is strict: preparation failures stop the upload instead of silently switching thumbnail generation to PHP. Uncheck this option to use the standard server-side path.')) . '</span><span class="muted">' . e(t('admin.upload.browser_zip_help', 'You may also select ZIP archives. Supported images are extracted locally; other entries are skipped. ZIP import never uses the PHP fallback.')) . '</span></label>';
}

/**
 * Render an existing-gallery upload form from prepared URLs, markup and replay identity.
 *
 * @param array{panel_mode?:bool,action_url?:string,csrf_html?:string,operation_key?:string,gallery_id?:int,target_title?:string,gallery_options_html?:string,accept_value?:string,browser_checkbox_html?:string} $viewModel Controller-prepared target context and presentation fragments.
 * @return void Emit the native/AJAX-compatible form without resolving domain policy.
 */
function view_render_admin_upload_existing_gallery_form(array $viewModel): void
{
    $panelMode = !empty($viewModel['panel_mode']);
    if ($panelMode) {
        echo '<section class="admin-side-panel-card admin-side-panel-upload-card"><div class="admin-side-panel-card-heading"><div><p class="admin-kicker">' . e(t('admin.upload.existing_gallery', 'Existing gallery')) . '</p><h3>' . e(t('admin.upload.upload_existing_title', 'Upload into an existing gallery')) . '</h3></div><p class="muted">' . e(t('admin.upload.upload_existing_help', 'Choose a gallery and upload photos without leaving the drawer.')) . '</p></div><form method="post" action="' . e((string) ($viewModel['action_url'] ?? '')) . '" enctype="multipart/form-data" class="admin-side-panel-form" data-gallery-upload-form data-gallery-panel-close-on-success="1">' . (string) ($viewModel['csrf_html'] ?? '');
        echo '<input type="hidden" name="panel" value="1">';
    } else {
        echo '<section class="panel"><h2>' . e(t('admin.upload.upload_existing_title', 'Upload into existing gallery')) . '</h2><form method="post" action="' . e((string) ($viewModel['action_url'] ?? '')) . '" enctype="multipart/form-data" class="form-grid" data-gallery-upload-form>' . (string) ($viewModel['csrf_html'] ?? '');
    }
    echo '<input type="hidden" name="upload_mode" value="existing">';
    echo '<input type="hidden" name="operation_key" value="' . e((string) ($viewModel['operation_key'] ?? '')) . '" data-admin-operation-key>';
    if ($panelMode && (int) ($viewModel['gallery_id'] ?? 0) > 0) {
        echo '<input type="hidden" name="gallery_id" value="' . (int) $viewModel['gallery_id'] . '">';
        echo '<div class="admin-side-panel-target"><span>' . e(t('admin.upload.target_gallery', 'Target gallery')) . '</span><strong>' . e((string) ($viewModel['target_title'] ?? '')) . '</strong></div>';
    } else {
        echo '<label' . ($panelMode ? ' class="admin-side-panel-field admin-side-panel-field-wide"' : '') . '><span>' . e(t('admin.upload.gallery', 'Gallery')) . '</span><select name="gallery_id" required>' . (string) ($viewModel['gallery_options_html'] ?? '') . '</select></label>';
    }
    echo '<label' . ($panelMode ? ' class="admin-side-panel-file-drop"' : '') . '><span class="admin-side-panel-file-title">' . e(t('admin.upload.images', 'Images')) . '</span><input name="images[]" type="file" accept="' . e((string) ($viewModel['accept_value'] ?? '')) . '" multiple required><span class="muted">' . e(t('admin.upload.choose_images_for_gallery', 'Choose one or more images for this gallery.')) . '</span></label>';
    echo '<label' . ($panelMode ? ' class="admin-side-panel-thumbnail-toggle"' : '') . '><input type="checkbox" name="create_thumbnails" value="1" checked> <span>' . e(t('admin.upload.create_thumbnails_after_upload', 'Create optimized thumbnails after upload')) . '</span></label>';
    echo (string) ($viewModel['browser_checkbox_html'] ?? '');
    if ($panelMode) {
        echo '<div class="admin-side-panel-actions"><button type="submit" class="button primary" data-gallery-panel-submit>' . e(t('admin.upload.upload_images', 'Upload images')) . '</button><p class="muted">' . e(t('admin.upload.progress_top_panel', 'Progress appears at the top of this panel.')) . '</p></div>';
    } else {
        echo '<button type="submit">' . e(t('admin.upload.upload_images', 'Upload images')) . '</button>';
    }
    echo '</form></section>';
}

/** @param array<string,mixed> $viewModel Controller-prepared create-gallery upload form. */
function view_render_admin_upload_new_gallery_form(array $viewModel): void
{
    $panelMode = !empty($viewModel['panel_mode']);
    if (!$panelMode) {
        echo '<section class="panel"><h2>' . e(t('admin.upload.create_and_upload_title', 'Create gallery and upload photos')) . '</h2>';
        echo '<form method="post" action="' . e((string) ($viewModel['action_url'] ?? '')) . '" enctype="multipart/form-data" class="form-grid" data-gallery-upload-form>' . (string) ($viewModel['csrf_html'] ?? '');
        echo '<input type="hidden" name="upload_mode" value="new">';
        echo (string) ($viewModel['gallery_fields_html'] ?? '');
        echo '<label>' . e(t('admin.upload.images', 'Images')) . '<input name="images[]" type="file" accept="' . e((string) ($viewModel['accept_value'] ?? '')) . '" multiple required><span class="muted">' . e(t('admin.upload.choose_one_or_more_images', 'Choose one or more images.')) . '</span></label>';
        echo '<label><input type="checkbox" name="create_thumbnails" value="1" checked> ' . e(t('admin.upload.create_thumbnails_after_upload', 'Create optimized thumbnails after upload')) . '</label>';
        echo (string) ($viewModel['browser_checkbox_html'] ?? '');
        echo '<button type="submit">' . e(t('admin.upload.create_gallery_and_upload', 'Create gallery and upload')) . '</button></form></section>';
        return;
    }

    echo '<section class="admin-side-panel-workflow" data-gallery-panel-workflow>';
    echo '<div class="admin-side-panel-progress-anchor" data-gallery-panel-progress-anchor></div>';
    echo '<form method="post" action="' . e((string) ($viewModel['action_url'] ?? '')) . '" enctype="multipart/form-data" class="admin-side-panel-form" data-gallery-upload-form data-gallery-panel-close-on-success="1">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<input type="hidden" name="upload_mode" value="new">';
    echo (string) ($viewModel['gallery_fields_html'] ?? '');
    echo '<div class="admin-side-panel-card admin-side-panel-upload-card">';
    echo '<div class="admin-side-panel-card-heading"><div><p class="admin-kicker">' . e(t('admin.upload.optional_photos', 'Optional photos')) . '</p><h3>' . e(t('admin.upload.upload_now', 'Upload now')) . '</h3></div><p class="muted">' . e(t('admin.upload.optional_photos_help', 'Leave this empty to create only the gallery.')) . '</p></div>';
    echo '<label class="admin-side-panel-file-drop"><span class="admin-side-panel-file-title">' . e(t('admin.upload.choose_images', 'Choose images')) . '</span><input name="images[]" type="file" accept="' . e((string) ($viewModel['accept_value'] ?? '')) . '" multiple><span class="muted">' . e(t('admin.upload.multiple_files_help', 'Multiple files are supported. The existing upload pipeline and thumbnail generation are reused.')) . '</span></label>';
    echo '<label class="admin-side-panel-thumbnail-toggle"><input type="checkbox" name="create_thumbnails" value="1" checked> <span>' . e(t('admin.upload.create_thumbnails_after_upload', 'Create optimized thumbnails after upload')) . '</span></label>';
    echo (string) ($viewModel['browser_checkbox_html'] ?? '');
    echo '</div>';
    echo '<div class="admin-side-panel-actions"><button type="submit" class="button primary" data-gallery-panel-submit>' . e(t('admin.upload.create_gallery', 'Create gallery')) . '</button><p class="muted">' . e(t('admin.upload.progress_top_panel_during_upload', 'Progress appears at the top of this panel during upload.')) . '</p></div>';
    echo '</form></section>';
}
