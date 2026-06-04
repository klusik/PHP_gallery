<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/layout.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders the shared HTML document shell.
 *
 * Responsibilities:
 *   - Render the public/admin header and footer
 *   - Emit shared stylesheet and JavaScript includes
 *   - Keep page-shell HTML out of the core helper module
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

function view_public_header_branding_model(string $siteName, ?array $currentGallery = null, bool $publicOnly = true, string $bodyClass = 'public-page'): array
{
    $model = [
        'banner_url' => '',
        'logo_url' => '',
        'separator_url' => '',
    ];
    if ($bodyClass !== 'public-page') {
        return $model;
    }
    if ($currentGallery !== null && function_exists('gallery_branding_schema_ready') && gallery_branding_schema_ready()) {
        $model['banner_url'] = gallery_branding_asset_url($currentGallery, 'banner', $publicOnly);
        $model['logo_url'] = gallery_branding_asset_url($currentGallery, 'logo', $publicOnly);
        $model['separator_url'] = gallery_branding_asset_url($currentGallery, 'separator', $publicOnly);
    }
    if ($model['banner_url'] === '' && function_exists('theme_branding_asset_url')) {
        $model['banner_url'] = theme_branding_asset_url('banner');
    }
    if ($model['separator_url'] === '' && function_exists('theme_branding_asset_url')) {
        $model['separator_url'] = theme_branding_asset_url('separator');
    }
    return $model;
}


/**
 * Render configured favorite gallery shortcut links for the top navigation.
 *
 * @param array<int, array<string, mixed>> $items Resolved favorite gallery navigation items.
 * @return string Favorite gallery anchor markup, or an empty string when none are configured.
 */
function view_favorite_gallery_nav_html(array $items): string
{
    // $html stores the compact anchor list inserted into the shared header nav.
    $html = '';
    foreach ($items as $item) {
        // $url stores the final public gallery URL for one configured shortcut.
        $url = trim((string) ($item['url'] ?? ''));
        // $title stores the button label, normally the gallery title.
        $title = trim((string) ($item['title'] ?? ''));
        if ($url === '' || $title === '') {
            continue;
        }
        $html .= '<a class="nav-favorite-gallery" href="' . e($url) . '">' . e($title) . '</a>';
    }
    return $html;
}

function view_render_header(string $title, ?array $currentGallery = null, bool $publicOnly = true): void
{
    $user = current_user();
    $anonymousPreview = admin_anonymous_preview_active();
    $siteName = site_name();
    $theme = theme_settings();
    $page = (string) ($_GET['page'] ?? 'home');
    $bodyClass = str_starts_with($page, 'admin') || $page === 'setup' ? 'admin-page' : 'public-page';
    $pageWidthClass = $bodyClass === 'public-page' ? ' page-width-' . theme_page_width_mode((string) ($theme['page_width'] ?? 'default')) : '';
    echo '<!doctype html><html lang="' . e(function_exists('translation_active_language') ? translation_active_language() : 'en') . '" translate="no"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title === $siteName ? $siteName : $title . ' - ' . $siteName) . '</title>';
    $faviconUrl = favicon_asset_url();
    if ($faviconUrl !== '') {
        $faviconVersion = (string) app_setting('favicon_version', '1');
        echo '<link rel="icon" type="image/png" sizes="32x32" href="' . e($faviconUrl) . '&s=32&v=' . e($faviconVersion) . '">';
        echo '<link rel="icon" type="image/png" sizes="48x48" href="' . e($faviconUrl) . '&s=48&v=' . e($faviconVersion) . '">';
        echo '<link rel="apple-touch-icon" sizes="180x180" href="' . e($faviconUrl) . '&s=180&v=' . e($faviconVersion) . '">';
    }
    if ($bodyClass === 'admin-page') {
        echo '<meta name="robots" content="noindex,nofollow">';
    }
    $styleFiles = [
        'assets/styles/base.css',
        'assets/styles/public.css',
        'assets/styles/lightbox.css',
        'assets/styles/admin.css',
        'assets/styles/admin-layout.css',
        'assets/styles/admin-dashboard.css',
        'assets/styles/admin-subtabs.css',
        'assets/styles/admin-theme-preview.css',
        'assets/styles/admin-reordering.css',
        'assets/styles/admin-media-tools.css',
        'assets/styles/admin-theme-editor.css',
        'assets/styles/admin-gallery-list.css',
        'assets/styles/admin-patch-notes.css',
        'assets/styles/admin-update.css',
        'assets/styles/admin-tags.css',
        'assets/styles/side-panel.css',
        'assets/styles/utilities.css',
        'assets/styles.css',
    ];
    foreach ($styleFiles as $styleFile) {
        $stylePath = dirname(__DIR__, 2) . '/public/' . $styleFile;
        if (!is_file($stylePath)) {
            continue;
        }
        echo '<link rel="stylesheet" href="' . e(asset_url($styleFile)) . '?v=' . filemtime($stylePath) . '">';
    }
    $customCss = custom_css_url();
    if ($customCss) {
        echo '<link rel="stylesheet" href="' . e($customCss) . '?v=' . filemtime(custom_css_path()) . '">';
    }
    echo '<link rel="stylesheet" href="' . e(url_for('theme_css')) . '&v=' . rawurlencode((string) theme_cache_key($theme)) . '">';
    $mobileGalleryStyle = 'assets/styles/mobile-gallery.css';
    $mobileGalleryStylePath = dirname(__DIR__, 2) . '/public/' . $mobileGalleryStyle;
    if (is_file($mobileGalleryStylePath)) {
        echo '<link rel="stylesheet" href="' . e(asset_url($mobileGalleryStyle)) . '?v=' . filemtime($mobileGalleryStylePath) . '">';
    }
    echo cms_head_extras_html();
    $devModeActive = $user && dev_mode_enabled();
    echo '</head><body class="' . e($bodyClass . $pageWidthClass) . '"' . ($devModeActive ? ' data-dev-mode="1"' : '') . '>';
    if ($bodyClass === 'public-page') {
        echo '<div class="theme-background-shell" aria-hidden="true">';
        echo '<div class="theme-background-base"></div>';
        echo '<div class="theme-background-image"></div>';
        echo '</div>';
    }
    $headerBranding = view_public_header_branding_model($siteName, $currentGallery, $publicOnly, $bodyClass);
    echo '<header class="site-header">';
    echo '<a class="brand' . ($headerBranding['banner_url'] !== '' ? ' brand-with-banner' : '') . '" href="' . e(url_for('home')) . '">';
    if ($headerBranding['logo_url'] !== '') {
        echo '<img class="brand-logo" src="' . e($headerBranding['logo_url']) . '" alt="" aria-hidden="true" decoding="async">';
    }
    if ($headerBranding['banner_url'] !== '') {
        echo '<span class="visually-hidden">' . e($siteName) . '</span><img class="brand-banner" src="' . e($headerBranding['banner_url']) . '" alt="" aria-hidden="true" decoding="async">';
    } else {
        echo e($siteName);
    }
    echo '</a><nav class="nav">';
    // $favoritePublicOnly stores whether shortcuts should be restricted to public listed galleries.
    $favoritePublicOnly = !$user || $anonymousPreview;
    // $favoriteGalleryItems stores resolved gallery shortcuts for the top navigation.
    $favoriteGalleryItems = function_exists('theme_favorite_gallery_navigation_items') ? theme_favorite_gallery_navigation_items($favoritePublicOnly) : [];
    echo view_favorite_gallery_nav_html($favoriteGalleryItems);
    if ($user && !$anonymousPreview) {
        if ($bodyClass === 'public-page') {
            $updatePending = application_update_pending();
            $updateClass = $updatePending ? ' class="is-update-pending"' : '';
            $updateLabel = application_update_nav_label($updatePending);
            echo '<a href="' . e(url_for('admin')) . '">' . e(t('nav.admin', 'Admin')) . '</a>';
            echo '<a' . $updateClass . ' href="' . e(url_for('admin_update')) . '">' . e($updateLabel) . '</a>';
        }
        echo '<a href="' . e(url_for('admin_logout')) . '">' . e(t('nav.logout', 'Logout')) . '</a>';
    } else {
        echo '<a href="' . e(url_for('admin_login', ['return' => current_login_return_target()])) . '">' . e(t('nav.admin_login', 'Admin login')) . '</a>';
    }
    echo '</nav></header>';
    if ($headerBranding['separator_url'] !== '') {
        echo '<div class="site-branding-separator" aria-hidden="true"><img src="' . e($headerBranding['separator_url']) . '" alt="" decoding="async"></div>';
    }
    if ($bodyClass === 'admin-page' && $user) {
        echo '<div class="admin-shell">';
        view_render_admin_sidebar($page);
        echo '<main class="site-main admin-content">';
        view_render_missing_admin_email_notice($user, $page);
    } else {
        echo '<main class="site-main">';
    }
}

function view_cms_browser_i18n_strings(): array
{
    return [
        'admin.bulk.select_gallery_delete' => t('js.admin.bulk.select_gallery_delete', 'Select at least one gallery to delete.'),
        'admin.bulk.delete_galleries_title' => t('js.admin.bulk.delete_galleries_title', 'Delete these gallery folders and all subgalleries?'),
        'admin.bulk.delete_galleries_detail' => t('js.admin.bulk.delete_galleries_detail', 'This removes the folders from disk and deletes their database records. This cannot be undone.'),
        'admin.bulk.gallery_fallback' => t('js.admin.bulk.gallery_fallback', 'Gallery {id}'),
        'admin.bulk.image_fallback' => t('js.admin.bulk.image_fallback', 'Image {id}'),
        'admin.bulk.selected_photo_fallback' => t('js.admin.bulk.selected_photo_fallback', 'Selected photo'),
        'admin.bulk.photo_selected_one' => t('js.admin.bulk.photo_selected_one', '1 photo selected'),
        'admin.bulk.photo_selected_many' => t('js.admin.bulk.photo_selected_many', '{count} photos selected'),
        'admin.bulk.select_photos_first' => t('js.admin.bulk.select_photos_first', 'Select one or more photos first.'),
        'admin.bulk.choose_move_action_summary' => t('js.admin.bulk.choose_move_action_summary', '{count} selected. Choose one of the move actions above.'),
        'admin.bulk.choose_destination_summary' => t('js.admin.bulk.choose_destination_summary', '{count} selected. Choose the destination gallery.'),
        'admin.bulk.enter_new_gallery_summary' => t('js.admin.bulk.enter_new_gallery_summary', '{count} selected. Enter the new gallery title.'),
        'admin.bulk.existing_gallery' => t('js.admin.bulk.existing_gallery', 'existing gallery'),
        'admin.bulk.new_gallery' => t('js.admin.bulk.new_gallery', 'new gallery'),
        'admin.bulk.move_summary' => t('js.admin.bulk.move_summary', '{count} selected. Move originals, thumbnails, and generated display files to the {target_type}: {target}.'),
        'admin.bulk.choose_move_type' => t('js.admin.bulk.choose_move_type', 'Choose whether to move to an existing gallery or a new gallery.'),
        'admin.bulk.select_photo_move' => t('js.admin.bulk.select_photo_move', 'Select at least one photo to move.'),
        'admin.bulk.select_photo_delete' => t('js.admin.bulk.select_photo_delete', 'Select at least one photo to delete.'),
        'admin.bulk.choose_destination' => t('js.admin.bulk.choose_destination', 'Choose the destination gallery.'),
        'admin.bulk.enter_new_gallery' => t('js.admin.bulk.enter_new_gallery', 'Enter the new gallery title.'),
        'admin.bulk.move_photo_one' => t('js.admin.bulk.move_photo_one', 'Move this photo?'),
        'admin.bulk.move_photo_many' => t('js.admin.bulk.move_photo_many', 'Move these photos?'),
        'admin.bulk.move_photo_detail' => t('js.admin.bulk.move_photo_detail', 'This physically moves the original files, generated thumbnails, and display derivatives. The source gallery will no longer contain them.'),
        'admin.bulk.delete_photo_one' => t('js.admin.bulk.delete_photo_one', 'Delete this photo from the gallery?'),
        'admin.bulk.delete_photo_many' => t('js.admin.bulk.delete_photo_many', 'Delete these photos?'),
        'admin.bulk.delete_photo_detail' => t('js.admin.bulk.delete_photo_detail', 'This removes the original file from disk, deletes its database record, and cleans generated thumbnails. This cannot be undone.'),
        'admin.thumbnails.delete_not_configured' => t('js.admin.thumbnails.delete_not_configured', 'Thumbnail deletion is not configured correctly. No files were deleted.'),
        'admin.thumbnails.delete_prompt_intro' => t('js.admin.thumbnails.delete_prompt_intro', 'This will delete all generated thumbnail files for every gallery.'),
        'admin.thumbnails.delete_prompt_originals' => t('js.admin.thumbnails.delete_prompt_originals', 'Original photos and gallery records will not be deleted.'),
        'admin.thumbnails.delete_prompt_regenerate' => t('js.admin.thumbnails.delete_prompt_regenerate', 'The next public/admin view can regenerate thumbnails when needed.'),
        'admin.thumbnails.delete_prompt_confirm' => t('js.admin.thumbnails.delete_prompt_confirm', 'Type {word} to confirm.'),
        'admin.thumbnails.delete_cancelled' => t('js.admin.thumbnails.delete_cancelled', 'Thumbnail deletion cancelled. No thumbnail files were deleted.'),
        'admin.operations.scanning' => t('js.admin.operations.scanning', 'Scanning...'),
        'admin.operations.scan_detail' => t('js.admin.operations.scan_detail', 'Scanning existing galleries and checking for new gallery folders...'),
        'admin.operations.working' => t('js.admin.operations.working', 'Working...'),
        'admin.operations.upload_thumbnail_failed' => t('js.admin.operations.upload_thumbnail_failed', 'Upload finished, but {count} thumbnail or DNG display derivative(s) failed.'),
        'admin.operations.upload_complete' => t('js.admin.operations.upload_complete', 'Upload and thumbnail job complete.'),
        'admin.operations.uploaded_scanning_complete' => t('js.admin.operations.uploaded_scanning_complete', 'Uploaded {count} images. Scanning complete.'),
        'admin.operations.upload_failed' => t('js.admin.operations.upload_failed', 'Upload failed.'),
        'votes.liked' => t('js.votes.liked', 'Liked'),
        'votes.no_like' => t('js.votes.no_like', 'No like'),
        'thumbnail_bounds.auto_min' => t('thumbnail_bounds.auto_min', 'Auto min'),
        'thumbnail_bounds.auto_max' => t('thumbnail_bounds.auto_max', 'Auto max'),
        'admin.date_picker.open' => t('js.admin.date_picker.open', 'Open calendar'),
        'admin.date_picker.today' => t('js.admin.date_picker.today', 'Today'),
        'admin.date_picker.delete' => t('js.admin.date_picker.delete', 'Delete'),
        'admin.simbrief.js_missing_form' => t('admin.simbrief.js_missing_form', 'The gallery form could not be found.'),
        'admin.simbrief.js_missing_textarea' => t('admin.simbrief.js_missing_textarea', 'The description field could not be found.'),
        'admin.simbrief.js_missing_identifier' => t('admin.simbrief.js_missing_identifier', 'Enter a SimBrief Pilot ID or pilot name first.'),
        'admin.simbrief.js_replace_confirm' => t('admin.simbrief.js_replace_confirm', 'Replace the current description text in the editor? This is not saved until you save the gallery.'),
        'admin.simbrief.js_not_configured' => t('admin.simbrief.js_not_configured', 'SimBrief generation is not configured correctly on this page.'),
        'admin.simbrief.js_generating' => t('admin.simbrief.js_generating', 'Fetching SimBrief data and generating draft...'),
        'admin.simbrief.js_failed' => t('admin.simbrief.js_failed', 'SimBrief generation failed.'),
        'admin.simbrief.js_empty' => t('admin.simbrief.js_empty', 'SimBrief returned flight data, but no description could be generated.'),
        'admin.simbrief.js_generated' => t('admin.simbrief.js_generated', 'Draft generated. Review it, then save the gallery.'),
        'admin.simbrief.js_invalid_json' => t('admin.simbrief.js_invalid_json', 'The server returned an invalid SimBrief response.'),
        'admin.simbrief.js_html_response' => t('admin.simbrief.js_html_response', 'The server returned HTML instead of JSON. Check the admin logs or PHP error log.'),
        'lightbox.no_gps_title' => t('lightbox.no_gps_title', 'No GPS EXIF data'),
        'lightbox.no_gps_detail' => t('lightbox.no_gps_detail', 'This photo has no coordinates, so the fullscreen map is unavailable for this item.'),
    ];
}

function view_render_browser_i18n_script(): void
{
    $payload = [
        'language' => translation_active_language(),
        'strings' => view_cms_browser_i18n_strings(),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        $json = '{"language":"en","strings":{}}';
    }
    echo '<script>window.PHP_GALLERY_I18N = ' . $json . ';</script>';
}

function view_render_footer(): void
{
    $page = (string) ($_GET['page'] ?? 'home');
    $hasAdminShell = (str_starts_with($page, 'admin') || $page === 'setup') && current_user();
    echo '</main>' . ($hasAdminShell ? '</div>' : '') . '<footer class="site-footer muted">';
    echo '<a class="site-footer-link" href="' . e(cms_github_project_url()) . '" target="_blank" rel="noopener noreferrer">PHP Gallery (' . e(cms_current_version()) . ')</a>';
    echo '</footer>';
    $scriptPath = dirname(__DIR__, 2) . '/public/assets/gallery.js';
    $scriptVersionPaths = [
        $scriptPath,
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/lightbox.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/lightbox-votes.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/tag-suggestions.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/votes.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-operations.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-core.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-nested-tabs.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-side-panel.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-date-picker.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-simbrief-description.js',
    ];
    $scriptVersion = 0;
    foreach ($scriptVersionPaths as $versionPath) {
        if (is_file($versionPath)) {
            $scriptVersion = max($scriptVersion, filemtime($versionPath));
        }
    }
    view_render_browser_i18n_script();
    echo '<script type="module" src="' . e(asset_url('assets/gallery.js')) . '?v=' . ($scriptVersion > 0 ? $scriptVersion : time()) . '"></script>';
    echo cms_footer_scripts_html();
    echo '</body></html>';
}
