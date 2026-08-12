<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/helpers_page_rendering.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Provides shared header/footer rendering, branding models, head/footer extras, version, and browser i18n helpers.
 *
 * Responsibilities:
 *   - Support shared project infrastructure
 *   - Keep behavior compatible with existing controllers and services
 *   - Avoid unnecessary coupling to presentation code
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
 *   2026-08-11
 */

declare(strict_types=1);

namespace Gallery\Core;

use PDO;
use RuntimeException;
use function Gallery\Services\app_setting;
use function Gallery\Services\application_update_nav_label;
use function Gallery\Services\application_update_pending;
use function Gallery\Services\cms_github_project_url;
use function Gallery\Services\custom_css_path;
use function Gallery\Services\custom_css_url;
use function Gallery\Services\dev_mode_enabled;
use function Gallery\Services\dng_conversion_supported;
use function Gallery\Services\favicon_asset_url;
use function Gallery\Services\feature_flag_enabled;
use function Gallery\Services\find_gallery;
use function Gallery\Services\gallery_branding_asset_url;
use function Gallery\Services\gallery_branding_schema_ready;
use function Gallery\Services\gallery_cover_collage_images;
use function Gallery\Services\gallery_cover_image;
use function Gallery\Services\gallery_nsfw_requirement;
use function Gallery\Services\heic_conversion_supported;
use function Gallery\Services\image_nsfw_restricted;
use function Gallery\Services\public_gallery_metadata;
use function Gallery\Services\public_gallery_sitemap_entries;
use function Gallery\Services\public_render_profile_count;
use function Gallery\Services\public_render_profile_with_thumbnail_purpose;
use function Gallery\Services\public_sitemap_entries;
use function Gallery\Services\public_sitemap_image_last_modified;
use function Gallery\Services\public_sitemap_lastmod;
use function Gallery\Services\site_name;
use function Gallery\Services\t;
use function Gallery\Services\theme_branding_asset_url;
use function Gallery\Services\theme_favorite_gallery_navigation_items;
use function Gallery\Services\theme_page_width_mode;
use function Gallery\Services\theme_settings;
use function Gallery\Services\thumbnail_abs_path;
use function Gallery\Services\thumbnail_bound_filter_sizes;
use function Gallery\Services\thumbnail_existing_fallback;
use function Gallery\Services\thumbnail_metadata_select_renderable_variant;
use function Gallery\Services\thumbnail_serving_url;
use function Gallery\Services\thumbnail_sizes;
use function Gallery\Services\thumbnail_url;
use function Gallery\Services\translation_active_language;
use function Gallery\Services\translation_default_language;
use function Gallery\Services\translation_load_language;
use function Gallery\Services\url_rewrite_should_emit_clean_urls;
use function Gallery\Views\view_admin_menu_item_is_active;
use function Gallery\Views\view_admin_menu_structure;
use function Gallery\Views\view_cms_browser_i18n_strings;
use function Gallery\Views\view_public_header_branding_model;
use function Gallery\Views\view_render_admin_sidebar;
use function Gallery\Views\view_render_admin_subtab_panel;
use function Gallery\Views\view_render_admin_subtabs;
use function Gallery\Views\view_render_admin_tab_panel;
use function Gallery\Views\view_render_admin_tabs;
use function Gallery\Views\view_render_browser_i18n_script;
use function Gallery\Views\view_render_footer;
use function Gallery\Views\view_render_gallery_json_ld;
use function Gallery\Views\view_render_header;
use function Gallery\Views\view_render_link_tag;
use function Gallery\Views\view_render_meta_tag;
use function Gallery\Views\view_render_missing_admin_email_notice;
use function Gallery\Views\view_render_public_seo_tags;

/**
 * Return optional artwork for the shared public header.
 */
function public_header_branding_model(string $siteName, ?array $currentGallery = null, bool $publicOnly = true, string $bodyClass = 'public-page'): array
{
    if (function_exists('Gallery\\Views\\view_public_header_branding_model')) {
        return view_public_header_branding_model($siteName, $currentGallery, $publicOnly, $bodyClass);
    }
    // $model stores URLs used by render_header without forcing callers to know the branding precedence.
    $model = [
        'banner_url' => '',
        'logo_url' => '',
        'separator_url' => '',
    ];
    if ($bodyClass !== 'public-page') {
        return $model;
    }
    if ($currentGallery !== null && function_exists('Gallery\\Services\\gallery_branding_schema_ready') && gallery_branding_schema_ready()) {
        // Per-gallery artwork overrides Theme fallback artwork on that gallery page.
        $model['banner_url'] = gallery_branding_asset_url($currentGallery, 'banner', $publicOnly);
        $model['logo_url'] = gallery_branding_asset_url($currentGallery, 'logo', $publicOnly);
        $model['separator_url'] = gallery_branding_asset_url($currentGallery, 'separator', $publicOnly);
    }
    if ($model['banner_url'] === '' && function_exists('Gallery\\Services\\theme_branding_asset_url')) {
        $model['banner_url'] = theme_branding_asset_url('banner');
    }
    if ($model['separator_url'] === '' && function_exists('Gallery\\Services\\theme_branding_asset_url')) {
        $model['separator_url'] = theme_branding_asset_url('separator');
    }
    return $model;
}

/**
 * Render configured favorite gallery shortcut links for fallback header rendering.
 *
 * @param array<int, array<string, mixed>> $items Resolved favorite gallery navigation items.
 * @return string Favorite gallery anchor markup, or an empty string when none are configured.
 */
function favorite_gallery_nav_html(array $items): string
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

/**
 * Render the shared document header, navigation, theme variables, and CSS links.
 */
function render_header(string $title, ?array $currentGallery = null, bool $publicOnly = true): void
{
    if (function_exists('Gallery\\Views\\view_render_header')) {
        view_render_header($title, $currentGallery, $publicOnly);
        return;
    }
    // Variable $user stores this steps working value.
    $user = current_user();
    // Variable $anonymousPreview stores whether this public request should hide authenticated navigation.
    $anonymousPreview = admin_anonymous_preview_active();
    // Variable $siteName stores this steps working value.
    $siteName = site_name();
    // Variable $theme stores this steps working value.
    $theme = theme_settings();
    // Variable $page stores this steps working value.
    $page = (string) ($_GET['page'] ?? 'home');
    // Variable $bodyClass stores this steps working value.
    $bodyClass = str_starts_with($page, 'admin') || $page === 'setup' ? 'admin-page' : 'public-page';
    // $pageWidthClass stores a public layout class selected in Theme settings.
    // Admin pages intentionally keep their own workspace width so dense tables remain practical.
    $pageWidthClass = $bodyClass === 'public-page' ? ' page-width-' . theme_page_width_mode((string) ($theme['page_width'] ?? 'default')) : '';
    echo '<!doctype html><html lang="' . e(function_exists('Gallery\\Services\\translation_active_language') ? translation_active_language() : 'en') . '" translate="no"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title === $siteName ? $siteName : $title . ' - ' . $siteName) . '</title>';
    // Variable $faviconUrl stores this steps working value.
    $faviconUrl = favicon_asset_url();
    if ($faviconUrl !== '') {
        // $faviconVersion stores an intermediate value used by the surrounding gallery workflow.
        $faviconVersion = (string) app_setting('favicon_version', '1');
        echo '<link rel="icon" type="image/png" sizes="32x32" href="' . e($faviconUrl) . '&s=32&v=' . e($faviconVersion) . '">';
        echo '<link rel="icon" type="image/png" sizes="48x48" href="' . e($faviconUrl) . '&s=48&v=' . e($faviconVersion) . '">';
        echo '<link rel="apple-touch-icon" sizes="180x180" href="' . e($faviconUrl) . '&s=180&v=' . e($faviconVersion) . '">';
    }
    if ($bodyClass === 'admin-page') {
        echo '<meta name="robots" content="noindex,nofollow">';
    }
    // Built-in stylesheets are linked directly with per-file cache keys.
    // This avoids stale browser caches for CSS files that were previously loaded through @import.
    $adminStyleFiles = [
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
        'assets/styles/admin-duplicate-photo-detector.css',
        'assets/styles/admin-cinematic.css',
        'assets/styles/utilities.css',
        'assets/styles.css',
    ];
    $publicStyleFiles = [
        'assets/styles/base.css',
        'assets/styles/public.css',
        'assets/styles/lightbox.css',
        'assets/styles/public-shared.css',
        'assets/styles/utilities.css',
        'assets/styles.css',
    ];
    $styleFiles = ($bodyClass === 'admin-page' || ($user && !$anonymousPreview)) ? $adminStyleFiles : $publicStyleFiles;
    foreach ($styleFiles as $styleFile) {
        $stylePath = dirname(__DIR__) . '/public/' . $styleFile;
        if (!is_file($stylePath)) {
            continue;
        }
        echo '<link rel="stylesheet" href="' . e(asset_url($styleFile)) . '?v=' . filemtime($stylePath) . '">';
    }
    // Variable $customCss stores this steps working value.
    $customCss = custom_css_url();
    if ($customCss) {
        echo '<link rel="stylesheet" href="' . e($customCss) . '?v=' . filemtime(custom_css_path()) . '">';
    }
    echo '<link rel="stylesheet" href="' . e(url_for('theme_css')) . '&v=' . rawurlencode((string) theme_cache_key($theme)) . '">';
    $mobileGalleryStyle = 'assets/styles/mobile-gallery.css';
    $mobileGalleryStylePath = dirname(__DIR__) . '/public/' . $mobileGalleryStyle;
    if (is_file($mobileGalleryStylePath)) {
        echo '<link rel="stylesheet" href="' . e(asset_url($mobileGalleryStyle)) . '?v=' . filemtime($mobileGalleryStylePath) . '">';
    }
    echo cms_head_extras_html();
    // $devModeActive stores an intermediate value used by the surrounding gallery workflow.
    $devModeActive = $user && dev_mode_enabled();
    echo '</head><body class="' . e($bodyClass . $pageWidthClass) . '"' . ($devModeActive ? ' data-dev-mode="1"' : '') . '>';
    if ($bodyClass === 'public-page') {
        echo '<div class="theme-background-shell" aria-hidden="true">';
        echo '<div class="theme-background-base"></div>';
        echo '<div class="theme-background-image"></div>';
        echo '</div>';
    }
    // $headerBranding stores optional artwork that replaces the visible site title.
    $headerBranding = public_header_branding_model($siteName, $currentGallery, $publicOnly, $bodyClass);
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
    $favoriteGalleryItems = function_exists('Gallery\\Services\\theme_favorite_gallery_navigation_items') ? theme_favorite_gallery_navigation_items($favoritePublicOnly) : [];
    echo favorite_gallery_nav_html($favoriteGalleryItems);
    if ($user && !$anonymousPreview) {
        if ($bodyClass === 'public-page') {
            // $updatePending stores an intermediate value used by the surrounding gallery workflow.
            $updatePending = application_update_pending();
            // $updateClass stores an intermediate value used by the surrounding gallery workflow.
            $updateClass = $updatePending ? ' class="is-update-pending"' : '';
            // $updateLabel stores an intermediate value used by the surrounding gallery workflow.
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
        render_admin_sidebar($page);
        echo '<main class="site-main admin-content">';
        render_missing_admin_email_notice($user, $page);
    } else {
        echo '<main class="site-main">';
    }
}

/**
 * Replace extra head HTML for the next rendered page.
 */
function set_cms_head_extras(string $html): void
{
    $GLOBALS['cms_head_extras'] = $html;
}

/**
 * Append extra head HTML for the next rendered page.
 */
function append_cms_head_extras(string $html): void
{
    $GLOBALS['cms_head_extras'] = (string) ($GLOBALS['cms_head_extras'] ?? '') . $html;
}

/**
 * Return buffered head extras and clear them after rendering.
 */
function cms_head_extras_html(): string
{
    // $html stores an intermediate value used by the surrounding gallery workflow.
    $html = (string) ($GLOBALS['cms_head_extras'] ?? '');
    $GLOBALS['cms_head_extras'] = '';
    return $html;
}

/**
 * Append an inline footer script for the next rendered page.
 */
function append_cms_footer_script(string $script): void
{
    $GLOBALS['cms_footer_scripts'] = (array) ($GLOBALS['cms_footer_scripts'] ?? []);
    $GLOBALS['cms_footer_scripts'][] = $script;
}


/**
 * Append raw footer HTML for the next rendered page.
 */
function append_cms_footer_html(string $html): void
{
    $GLOBALS['cms_footer_html'] = (array) ($GLOBALS['cms_footer_html'] ?? []);
    $GLOBALS['cms_footer_html'][] = $html;
}

/**
 * Return buffered footer scripts and clear them after rendering.
 */
function cms_footer_scripts_html(): string
{
    // $scripts stores an intermediate value used by the surrounding gallery workflow.
    $scripts = (array) ($GLOBALS['cms_footer_scripts'] ?? []);
    $GLOBALS['cms_footer_scripts'] = [];
    // $html stores an intermediate value used by the surrounding gallery workflow.
    $html = '';
    foreach ($scripts as $script) {
        $html .= '<script>' . $script . '</script>';
    }
    // $footerHtml stores raw footer HTML snippets prepared by trusted server-side code.
    $footerHtml = (array) ($GLOBALS['cms_footer_html'] ?? []);
    $GLOBALS['cms_footer_html'] = [];
    foreach ($footerHtml as $snippet) {
        $html .= (string) $snippet;
    }
    return $html;
}

/**
 * Read the current application version directly from app/bootstrap.php.
 */
function cms_current_version(): string
{
    static $version = null;
    if ($version !== null) {
        return $version;
    }

    // $bootstrapPath stores an intermediate value used by the surrounding gallery workflow.
    $bootstrapPath = dirname(__DIR__) . '/app/bootstrap.php';
    // $bootstrap stores an intermediate value used by the surrounding gallery workflow.
    $bootstrap = is_file($bootstrapPath) ? (string) file_get_contents($bootstrapPath) : '';
    if (preg_match("/const\s+CMS_VERSION\s*=\s*['\"]([^'\"]+)['\"]\s*;/i", $bootstrap, $match)) {
        // $version stores an intermediate value used by the surrounding gallery workflow.
        $version = trim((string) $match[1]);
        return $version;
    }

    return $version = CMS_VERSION;
}


/**
 * Return translated strings used by browser-side modules.
 */
function cms_browser_i18n_strings(): array
{
    if (function_exists('Gallery\\Views\\view_cms_browser_i18n_strings')) {
        return view_cms_browser_i18n_strings();
    }

    $activeStrings = translation_load_language(translation_active_language());
    $defaultStrings = translation_load_language(translation_default_language());
    $strings = array_merge($defaultStrings, $activeStrings);

    return array_merge($strings, [
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
        'admin.bulk.delete_photo_many' => t('js.admin.bulk.delete_photo_many', 'Delete these photos from the gallery?'),
        'admin.bulk.delete_photo_detail' => t('js.admin.bulk.delete_photo_detail', 'This removes the original file from disk, deletes its database record, and cleans generated thumbnails. This cannot be undone.'),
        'admin.openai.js_missing_form' => t('js.admin.openai.missing_form', 'The editor form could not be found.'),
        'admin.openai.js_missing_textarea' => t('js.admin.openai.missing_textarea', 'The description field could not be found.'),
        'admin.openai.js_requires_text' => t('js.admin.openai.requires_text', 'This action needs existing description text first.'),
        'admin.openai.js_replace_confirm' => t('js.admin.openai.replace_confirm', 'Replace the current description text in the editor? This is not saved until you save the edited item.'),
        'admin.openai.js_visual_confirm' => t('js.admin.openai.visual_confirm', 'This action will send one or more small generated thumbnails, not the original files, to OpenAI. Continue?'),
        'admin.openai.js_not_configured' => t('js.admin.openai.not_configured', 'OpenAI text assistance is not configured correctly on this page.'),
        'admin.openai.js_generating' => t('js.admin.openai.generating', 'Generating OpenAI text suggestion...'),
        'admin.openai.js_failed' => t('js.admin.openai.failed', 'OpenAI text generation failed.'),
        'admin.openai.js_empty' => t('js.admin.openai.empty', 'OpenAI returned an empty suggestion.'),
        'admin.openai.js_generated' => t('js.admin.openai.generated', 'Suggestion inserted. Save the edited item to keep it.'),
        'admin.openai.js_invalid_json' => t('js.admin.openai.invalid_json', 'The server returned an invalid OpenAI response.'),
        'admin.openai.js_html_response' => t('js.admin.openai.html_response', 'The server returned HTML instead of JSON. Check the admin logs or PHP error log.'),
        'admin.openai.js_bulk_counting' => t('js.admin.openai.bulk_counting', 'Counting photos for bulk description...'),
        'admin.openai.js_bulk_no_photos' => t('js.admin.openai.bulk_no_photos', 'This gallery has no photos to describe.'),
        'admin.openai.js_bulk_confirm' => t('js.admin.openai.bulk_confirm', 'This will generate and save descriptions for {count} photo(s), one OpenAI request per photo. Existing descriptions may be replaced. Type {count} to continue.'),
        'admin.openai.js_bulk_cancelled' => t('js.admin.openai.bulk_cancelled', 'Bulk photo description cancelled.'),
        'admin.openai.js_bulk_progress' => t('js.admin.openai.bulk_progress', 'Generating photo descriptions: {done}/{total} complete, {failed} failed.'),
        'admin.openai.js_bulk_done' => t('js.admin.openai.bulk_done', 'Bulk photo descriptions finished: {done}/{total} saved, {failed} failed.'),
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
        'admin.simbrief.js_generated' => t('admin.simbrief.js_generated', 'Draft generated. The latest OFP was saved with the gallery and the route map was updated.'),
        'admin.simbrief.js_ofp_saved' => t('admin.simbrief.js_ofp_saved', 'OFP saved with this gallery.'),
        'admin.simbrief.js_route_saved' => t('admin.simbrief.js_route_saved', 'Route map updated with {points} OFP point(s).'),
        'admin.simbrief.js_invalid_json' => t('admin.simbrief.js_invalid_json', 'The server returned an invalid SimBrief response.'),
        'admin.simbrief.js_html_response' => t('admin.simbrief.js_html_response', 'The server returned HTML instead of JSON. Check the admin logs or PHP error log.'),
        'lightbox.no_gps_title' => t('lightbox.no_gps_title', 'No GPS EXIF data'),
        'lightbox.no_gps_detail' => t('lightbox.no_gps_detail', 'This photo has no coordinates, so the fullscreen map is unavailable for this item.'),
    ]);
}

/**
 * Render translated strings for browser-side modules before the ES module entrypoint loads.
 */
function render_browser_i18n_script(): void
{
    if (function_exists('Gallery\\Views\\view_render_browser_i18n_script')) {
        view_render_browser_i18n_script();
        return;
    }
    $payload = [
        'language' => translation_active_language(),
        'strings' => cms_browser_i18n_strings(),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        $json = '{"language":"en","strings":{}}';
    }
    echo '<script>window.PHP_GALLERY_I18N = ' . $json . ';</script>';
}

/**
 * Render the shared footer and JavaScript include.
 */
function render_footer(): void
{
    if (function_exists('Gallery\\Views\\view_render_footer')) {
        view_render_footer();
        return;
    }
    // $page stores an intermediate value used by the surrounding gallery workflow.
    $page = (string) ($_GET['page'] ?? 'home');
    // $hasAdminShell stores an intermediate value used by the surrounding gallery workflow.
    $hasAdminShell = (str_starts_with($page, 'admin') || $page === 'setup') && current_user();
    echo '</main>' . ($hasAdminShell ? '</div>' : '') . '<footer class="site-footer muted">';
    echo '<a class="site-footer-link" href="' . e(cms_github_project_url()) . '" target="_blank" rel="noopener noreferrer">PHP Gallery (' . e(cms_current_version()) . ')</a>';
    echo '</footer>';
    $isAdminPage = str_starts_with($page, 'admin') || $page === 'setup';
    $user = current_user();
    $anonymousPreview = admin_anonymous_preview_active();
    $scriptAsset = (!$isAdminPage && (!$user || $anonymousPreview)) ? 'assets/public-gallery.js' : 'assets/gallery.js';
    // Variable $scriptPath stores this steps working value.
    $scriptPath = dirname(__DIR__) . '/public/' . $scriptAsset;
    $scriptVersionPaths = $scriptAsset === 'assets/public-gallery.js' ? [
        $scriptPath,
        dirname(__DIR__) . '/public/assets/gallery-modules/lightbox-deferred.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/lightbox.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/admin-core.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/votes.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/public-home-search.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/back-to-top.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/responsive-thumbnails.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/progressive-thumbnail-renderer.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/progressive-thumbnail-upgrade.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/thumbnail-warmup.js',
    ] : [
        $scriptPath,
        dirname(__DIR__) . '/public/assets/gallery-modules/progressive-thumbnail-renderer.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/progressive-thumbnail-upgrade.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/lightbox.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/lightbox-votes.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/tag-suggestions.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/votes.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/admin-operations.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/admin-core.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/admin-nested-tabs.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/admin-side-panel.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/admin-date-picker.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/admin-gallery-date-suggestion.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/admin-duplicate-photo-detector.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/admin-simbrief-description.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/admin-gallery-benchmark.js',
    ];
    $scriptVersion = 0;
    foreach ($scriptVersionPaths as $versionPath) {
        if (is_file($versionPath)) {
            $scriptVersion = max($scriptVersion, filemtime($versionPath));
        }
    }
    render_browser_i18n_script();
    $resolvedScriptVersion = $scriptVersion > 0 ? $scriptVersion : time();
    echo '<script type="module" data-gallery-asset-revision="' . e((string) $resolvedScriptVersion) . '" src="' . e(asset_url($scriptAsset)) . '?v=' . $resolvedScriptVersion . '"></script>';
    echo cms_footer_scripts_html();
    echo '</body></html>';
}
