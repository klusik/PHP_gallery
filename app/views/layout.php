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
 *   2026-08-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\admin_anonymous_preview_active;
use function Gallery\Core\asset_dependency_revision;
use function Gallery\Core\asset_url;
use function Gallery\Core\cms_current_version;
use function Gallery\Core\cms_footer_scripts_html;
use function Gallery\Core\cms_head_extras_html;
use function Gallery\Core\current_login_return_target;
use function Gallery\Core\current_user;
use function Gallery\Core\e;
use function Gallery\Core\theme_cache_key;
use function Gallery\Core\url_for;
use function Gallery\Services\app_setting;
use function Gallery\Services\application_update_nav_label;
use function Gallery\Services\application_update_pending;
use function Gallery\Services\cms_github_project_url;
use function Gallery\Services\custom_css_path;
use function Gallery\Services\custom_css_url;
use function Gallery\Services\dev_mode_enabled;
use function Gallery\Services\favicon_asset_url;
use function Gallery\Services\gallery_branding_asset_url;
use function Gallery\Services\gallery_branding_schema_ready;
use function Gallery\Services\seo_request_guard_canonical_head_html;
use function Gallery\Services\site_name;
use function Gallery\Services\t;
use function Gallery\Services\theme_branding_asset_url;
use function Gallery\Services\theme_favorite_gallery_navigation_items;
use function Gallery\Services\theme_page_width_mode;
use function Gallery\Services\theme_settings;
use function Gallery\Services\translation_active_language;
use function Gallery\Services\translation_default_language;
use function Gallery\Services\translation_language_allowed;
use function Gallery\Services\translation_language_dir;
use function Gallery\Services\translation_language_presentation;
use function Gallery\Services\translation_load_language;
use function Gallery\Services\translation_normalize_language_code;
use function Gallery\Services\translation_public_language_url;
use function Gallery\Services\translation_public_language_selector_enabled;
use function Gallery\Services\translation_public_language_selector_languages;
use function Gallery\Services\translation_public_language_selector_design;
use function Gallery\Services\translation_public_language_selector_design_style;

/**
 * Render the public visitor language selector in the shared header.
 *
 * The compact links work without JavaScript. Each language keeps the current
 * public route and query state; the following request stores the choice.
 */
function view_render_public_language_selector(): void
{
    if (!translation_public_language_selector_enabled()) {
        return;
    }
    $activeLanguage = translation_active_language();
    $presentations = translation_language_presentation();
    $label = t('public.language.selector_label', 'Language');
    $design = translation_public_language_selector_design();
    $preset = (string) $design['preset'];
    $classes = 'public-language-switcher language-preset-' . $preset
        . ' language-orientation-' . $design['orientation']
        . ' language-density-' . $design['density']
        . ' language-align-' . $design['alignment']
        . ' language-active-' . $design['active_style'];

    echo '<div class="' . e($classes) . '" role="group" aria-label="' . e($label) . '" style="' . e(translation_public_language_selector_design_style($design)) . '">';
    foreach (translation_public_language_selector_languages() as $language) {
        $presentation = $presentations[$language] ?? ['name' => strtoupper($language), 'flag_asset' => ''];
        $isActive = $language === $activeLanguage;
        $languageName = trim((string) ($presentation['name'] ?? strtoupper($language)));
        $flagAsset = trim((string) ($presentation['flag_asset'] ?? ''));
        echo '<a class="public-language-button' . ($isActive ? ' is-active' : '') . '" href="' . e(translation_public_language_url($language)) . '" hreflang="' . e($language) . '" lang="' . e($language) . '" aria-label="' . e($languageName) . '" title="' . e($languageName) . '"' . ($isActive ? ' aria-current="true"' : '') . '>';
        if (!empty($design['show_codes'])) {
            echo '<span class="public-language-code" aria-hidden="true">' . e(strtoupper($language)) . '</span>';
        }
        if (!empty($design['show_names'])) {
            echo '<span class="public-language-name" aria-hidden="true">' . e($languageName) . '</span>';
        }
        if (!empty($design['show_flags']) && $flagAsset !== '') {
            echo '<img class="public-language-flag" src="' . e(asset_url($flagAsset)) . '" alt="" aria-hidden="true" width="20" height="15" decoding="async">';
        }
        echo '</a>';
    }
    echo '</div>';
}

/**
 * Handle view public header branding model.
 *
 * Used by server-rendered view helpers.
 *
 * @param string $siteName Site name value.
 * @param ?array $currentGallery Current gallery value.
 * @param bool $publicOnly Public only value.
 * @param string $bodyClass Body class value.
 * @return array Structured result data for the caller.
 */
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
    if ($currentGallery !== null && function_exists('Gallery\\Services\\gallery_branding_schema_ready') && gallery_branding_schema_ready()) {
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
 * Render configured favorite gallery shortcut links for the top navigation.
 *
 * @param array $items Items value.
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


/**
 * Return the full legacy stylesheet set required by admin screens and logged-in public tools.
 *
 * @return array<int string> Stylesheet paths relative to the public web root.
 */
function view_admin_stylesheet_files(): array
{
    return [
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
}

/**
 * Return the anonymous public stylesheet set.
 *
 * The shared public file contains only visitor-facing rules extracted from
 * mixed legacy admin stylesheets after visual verification.
 *
 * @return array<int string> Stylesheet paths relative to the public web root.
 */
function view_public_stylesheet_files(): array
{
    return [
        'assets/styles/base.css',
        'assets/styles/public.css',
        'assets/styles/lightbox.css',
        'assets/styles/public-shared.css',
        'assets/styles/utilities.css',
        'assets/styles.css',
    ];
}

/**
 * Return whether the current request needs the full admin asset set.
 *
 * @param string $bodyClass Rendered body class for the current page family.
 * @param ?array $user User value.
 * @param bool $anonymousPreview Whether an admin explicitly requested anonymous preview mode.
 * @return bool True when admin or logged-in public tooling must stay available.
 */
function view_should_load_admin_assets(string $bodyClass, ?array $user, bool $anonymousPreview): bool
{
    return $bodyClass === 'admin-page' || ($user !== null && !$anonymousPreview);
}

/**
 * Return stylesheet files for the current page context.
 *
 * @param string $bodyClass Rendered body class for the current page family.
 * @param ?array $user User value.
 * @param bool $anonymousPreview Whether an admin explicitly requested anonymous preview mode.
 * @return array<int string> Stylesheet paths relative to the public web root.
 */
function view_stylesheet_files_for_context(string $bodyClass, ?array $user, bool $anonymousPreview): array
{
    return view_should_load_admin_assets($bodyClass, $user, $anonymousPreview)
        ? view_admin_stylesheet_files()
        : view_public_stylesheet_files();
}

/**
 * Return the browser entrypoint for the current page context.
 *
 * @param bool $isAdminPage Whether the current route renders an admin or setup page.
 * @param ?array $user User value.
 * @param bool $anonymousPreview Whether an admin explicitly requested anonymous preview mode.
 * @return string Script path relative to the public web root.
 */
function view_script_asset_for_context(bool $isAdminPage, ?array $user, bool $anonymousPreview): string
{
    return (!$isAdminPage && ($user === null || $anonymousPreview)) ? 'assets/public-gallery.js' : 'assets/gallery.js';
}

/**
 * Handle view render header.
 *
 * Used by server-rendered view helpers.
 *
 * @param string $title Title value.
 * @param ?array $currentGallery Current gallery value.
 * @param bool $publicOnly Public only value.
 */
function view_render_header(string $title, ?array $currentGallery = null, bool $publicOnly = true): void
{
    $user = current_user();
    $anonymousPreview = admin_anonymous_preview_active();
    $siteName = site_name();
    $theme = theme_settings();
    $page = (string) ($_GET['page'] ?? 'home');
    $bodyClass = str_starts_with($page, 'admin') || $page === 'setup' ? 'admin-page' : 'public-page';
    $pageWidthClass = $bodyClass === 'public-page' ? ' page-width-' . theme_page_width_mode((string) ($theme['page_width'] ?? 'default')) : '';
    echo '<!doctype html><html lang="' . e(function_exists('Gallery\\Services\\translation_active_language') ? translation_active_language() : 'en') . '" translate="no"><head><meta charset="utf-8">';
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
    $styleFiles = view_stylesheet_files_for_context($bodyClass, is_array($user) ? $user : null, $anonymousPreview);
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
    $headExtras = cms_head_extras_html();
    if ($bodyClass === 'public-page' && function_exists('Gallery\\Services\\seo_request_guard_canonical_head_html')) {
        echo seo_request_guard_canonical_head_html($page, $currentGallery, $headExtras);
    }
    echo $headExtras;
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
    if ($bodyClass === 'public-page') {
        view_render_public_language_selector();
    }
    // $favoritePublicOnly stores whether shortcuts should be restricted to public listed galleries.
    $favoritePublicOnly = !$user || $anonymousPreview;
    // $favoriteGalleryItems stores resolved gallery shortcuts for the top navigation.
    $favoriteGalleryItems = function_exists('Gallery\\Services\\theme_favorite_gallery_navigation_items') ? theme_favorite_gallery_navigation_items($favoritePublicOnly) : [];
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

/**
 * Resolve the language used for browser-side translations.
 *
 * @param ?string $language Requested language code.
 * @return string Safe active language code.
 */
function view_browser_i18n_language(?string $language = null): string
{
    $candidate = translation_normalize_language_code((string) ($language ?? ''));
    if ($candidate !== '' && translation_language_allowed($candidate)) {
        return $candidate;
    }
    return translation_active_language();
}

/**
 * Return one browser translation string from the resolved language dictionaries.
 *
 * @param array<string mixed> $strings Merged default and active language strings.
 * @param string $key Translation key.
 * @param string $fallback Fallback string.
 * @return string Browser-facing translation text.
 */
function view_browser_i18n_string(array $strings, string $key, string $fallback): string
{
    $value = $strings[$key] ?? null;
    return is_string($value) && $value !== '' ? $value : $fallback;
}

/**
 * Return a cache key for the external browser translation asset.
 *
 * @param ?string $language Requested language code.
 * @return string Stable cache key for the selected dictionaries.
 */
function view_browser_i18n_cache_key(?string $language = null): string
{
    $language = view_browser_i18n_language($language);
    $default = translation_default_language();
    $paths = [__FILE__];
    foreach (array_unique([$default, $language]) as $code) {
        foreach (['json', 'php'] as $extension) {
            $path = translation_language_dir() . '/' . $code . '.' . $extension;
            if (is_file($path)) {
                $paths[] = $path;
            }
        }
    }

    $latest = 0;
    foreach ($paths as $path) {
        if (is_file($path)) {
            $latest = max($latest, (int) filemtime($path));
        }
    }
    return substr(sha1($language . ':' . (string) $latest), 0, 12);
}

/**
 * Return JavaScript that installs the browser translation payload.
 *
 * @param ?string $language Requested language code.
 * @return string JavaScript asset content.
 */
function view_browser_i18n_javascript(?string $language = null): string
{
    $language = view_browser_i18n_language($language);
    $payload = [
        'language' => $language,
        'strings' => view_cms_browser_i18n_strings($language),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        $json = '{"language":"en","strings":{}}';
    }
    return 'window.PHP_GALLERY_I18N = ' . $json . ';';
}

/**
 * Return the external browser translation asset URL for the current page context.
 *
 * @param bool $isAdminPage Whether the current route renders an admin page.
 * @param ?string $language Requested language code.
 * @return string URL for the cacheable translation asset.
 */
function view_browser_i18n_asset_url(bool $isAdminPage, ?string $language = null): string
{
    $language = view_browser_i18n_language($language);
    return url_for($isAdminPage ? 'admin_browser_i18n' : 'browser_i18n', [
        'scope' => $isAdminPage ? 'admin' : 'public',
        'lang' => $language,
        'v' => view_browser_i18n_cache_key($language),
    ]);
}

/**
 * Handle view cms browser i18n strings.
 *
 * Used by server-rendered view helpers.
 *
 * @return array Structured result data for the caller.
 */
function view_cms_browser_i18n_strings(?string $language = null): array
{
    $language = view_browser_i18n_language($language);
    $activeStrings = translation_load_language($language);
    $defaultStrings = translation_load_language(translation_default_language());
    $strings = array_merge($defaultStrings, $activeStrings);

    return array_merge($strings, [
        'admin.bulk.select_gallery_delete' => view_browser_i18n_string($strings, 'js.admin.bulk.select_gallery_delete', 'Select at least one gallery to delete.'),
        'admin.bulk.delete_galleries_title' => view_browser_i18n_string($strings, 'js.admin.bulk.delete_galleries_title', 'Delete these gallery folders and all subgalleries?'),
        'admin.bulk.delete_galleries_detail' => view_browser_i18n_string($strings, 'js.admin.bulk.delete_galleries_detail', 'This removes the folders from disk and deletes their database records. This cannot be undone.'),
        'admin.bulk.gallery_fallback' => view_browser_i18n_string($strings, 'js.admin.bulk.gallery_fallback', 'Gallery {id}'),
        'admin.bulk.image_fallback' => view_browser_i18n_string($strings, 'js.admin.bulk.image_fallback', 'Image {id}'),
        'admin.bulk.selected_photo_fallback' => view_browser_i18n_string($strings, 'js.admin.bulk.selected_photo_fallback', 'Selected photo'),
        'admin.bulk.photo_selected_one' => view_browser_i18n_string($strings, 'js.admin.bulk.photo_selected_one', '1 photo selected'),
        'admin.bulk.photo_selected_many' => view_browser_i18n_string($strings, 'js.admin.bulk.photo_selected_many', '{count} photos selected'),
        'admin.bulk.select_photos_first' => view_browser_i18n_string($strings, 'js.admin.bulk.select_photos_first', 'Select one or more photos first.'),
        'admin.bulk.choose_move_action_summary' => view_browser_i18n_string($strings, 'js.admin.bulk.choose_move_action_summary', '{count} selected. Choose one of the move actions above.'),
        'admin.bulk.choose_destination_summary' => view_browser_i18n_string($strings, 'js.admin.bulk.choose_destination_summary', '{count} selected. Choose the destination gallery.'),
        'admin.bulk.enter_new_gallery_summary' => view_browser_i18n_string($strings, 'js.admin.bulk.enter_new_gallery_summary', '{count} selected. Enter the new gallery title.'),
        'admin.bulk.existing_gallery' => view_browser_i18n_string($strings, 'js.admin.bulk.existing_gallery', 'existing gallery'),
        'admin.bulk.new_gallery' => view_browser_i18n_string($strings, 'js.admin.bulk.new_gallery', 'new gallery'),
        'admin.bulk.move_summary' => view_browser_i18n_string($strings, 'js.admin.bulk.move_summary', '{count} selected. Move originals, thumbnails, and generated display files to the {target_type}: {target}.'),
        'admin.bulk.choose_move_type' => view_browser_i18n_string($strings, 'js.admin.bulk.choose_move_type', 'Choose whether to move to an existing gallery or a new gallery.'),
        'admin.bulk.select_photo_move' => view_browser_i18n_string($strings, 'js.admin.bulk.select_photo_move', 'Select at least one photo to move.'),
        'admin.bulk.select_photo_delete' => view_browser_i18n_string($strings, 'js.admin.bulk.select_photo_delete', 'Select at least one photo to delete.'),
        'admin.bulk.choose_destination' => view_browser_i18n_string($strings, 'js.admin.bulk.choose_destination', 'Choose the destination gallery.'),
        'admin.bulk.enter_new_gallery' => view_browser_i18n_string($strings, 'js.admin.bulk.enter_new_gallery', 'Enter the new gallery title.'),
        'admin.bulk.move_photo_one' => view_browser_i18n_string($strings, 'js.admin.bulk.move_photo_one', 'Move this photo?'),
        'admin.bulk.move_photo_many' => view_browser_i18n_string($strings, 'js.admin.bulk.move_photo_many', 'Move these photos?'),
        'admin.bulk.move_photo_detail' => view_browser_i18n_string($strings, 'js.admin.bulk.move_photo_detail', 'This physically moves the original files, generated thumbnails, and display derivatives. The source gallery will no longer contain them.'),
        'admin.bulk.delete_photo_one' => view_browser_i18n_string($strings, 'js.admin.bulk.delete_photo_one', 'Delete this photo from the gallery?'),
        'admin.bulk.delete_photo_many' => view_browser_i18n_string($strings, 'js.admin.bulk.delete_photo_many', 'Delete these photos?'),
        'admin.bulk.delete_photo_detail' => view_browser_i18n_string($strings, 'js.admin.bulk.delete_photo_detail', 'This removes the original file from disk, deletes its database record, and cleans generated thumbnails. This cannot be undone.'),
        'admin.thumbnails.delete_not_configured' => view_browser_i18n_string($strings, 'js.admin.thumbnails.delete_not_configured', 'Thumbnail deletion is not configured correctly. No files were deleted.'),
        'admin.thumbnails.delete_prompt_intro' => view_browser_i18n_string($strings, 'js.admin.thumbnails.delete_prompt_intro', 'This will delete all generated thumbnail files for every gallery.'),
        'admin.thumbnails.delete_prompt_originals' => view_browser_i18n_string($strings, 'js.admin.thumbnails.delete_prompt_originals', 'Original photos and gallery records will not be deleted.'),
        'admin.thumbnails.delete_prompt_regenerate' => view_browser_i18n_string($strings, 'js.admin.thumbnails.delete_prompt_regenerate', 'The next public/admin view can regenerate thumbnails when needed.'),
        'admin.thumbnails.delete_prompt_confirm' => view_browser_i18n_string($strings, 'js.admin.thumbnails.delete_prompt_confirm', 'Type {word} to confirm.'),
        'admin.thumbnails.delete_cancelled' => view_browser_i18n_string($strings, 'js.admin.thumbnails.delete_cancelled', 'Thumbnail deletion cancelled. No thumbnail files were deleted.'),
        'admin.operations.scanning' => view_browser_i18n_string($strings, 'js.admin.operations.scanning', 'Scanning...'),
        'admin.operations.scan_detail' => view_browser_i18n_string($strings, 'js.admin.operations.scan_detail', 'Scanning existing galleries and checking for new gallery folders...'),
        'admin.operations.working' => view_browser_i18n_string($strings, 'js.admin.operations.working', 'Working...'),
        'admin.operations.upload_thumbnail_failed' => view_browser_i18n_string($strings, 'js.admin.operations.upload_thumbnail_failed', 'Upload finished, but {count} thumbnail or DNG display derivative(s) failed.'),
        'admin.operations.upload_complete' => view_browser_i18n_string($strings, 'js.admin.operations.upload_complete', 'Upload and thumbnail job complete.'),
        'admin.operations.uploaded_scanning_complete' => view_browser_i18n_string($strings, 'js.admin.operations.uploaded_scanning_complete', 'Uploaded {count} images. Scanning complete.'),
        'admin.operations.upload_failed' => view_browser_i18n_string($strings, 'js.admin.operations.upload_failed', 'Upload failed.'),
        'votes.liked' => view_browser_i18n_string($strings, 'js.votes.liked', 'Liked'),
        'votes.no_like' => view_browser_i18n_string($strings, 'js.votes.no_like', 'No like'),
        'thumbnail_bounds.auto_min' => view_browser_i18n_string($strings, 'thumbnail_bounds.auto_min', 'Auto min'),
        'thumbnail_bounds.auto_max' => view_browser_i18n_string($strings, 'thumbnail_bounds.auto_max', 'Auto max'),
        'admin.date_picker.open' => view_browser_i18n_string($strings, 'js.admin.date_picker.open', 'Open calendar'),
        'admin.date_picker.today' => view_browser_i18n_string($strings, 'js.admin.date_picker.today', 'Today'),
        'admin.date_picker.delete' => view_browser_i18n_string($strings, 'js.admin.date_picker.delete', 'Delete'),
        'admin.simbrief.js_missing_form' => view_browser_i18n_string($strings, 'admin.simbrief.js_missing_form', 'The gallery form could not be found.'),
        'admin.simbrief.js_missing_textarea' => view_browser_i18n_string($strings, 'admin.simbrief.js_missing_textarea', 'The description field could not be found.'),
        'admin.simbrief.js_missing_identifier' => view_browser_i18n_string($strings, 'admin.simbrief.js_missing_identifier', 'Enter a SimBrief Pilot ID or pilot name first.'),
        'admin.simbrief.js_replace_confirm' => view_browser_i18n_string($strings, 'admin.simbrief.js_replace_confirm', 'Replace the current description text in the editor? This is not saved until you save the gallery.'),
        'admin.simbrief.js_not_configured' => view_browser_i18n_string($strings, 'admin.simbrief.js_not_configured', 'SimBrief generation is not configured correctly on this page.'),
        'admin.simbrief.js_generating' => view_browser_i18n_string($strings, 'admin.simbrief.js_generating', 'Fetching SimBrief data and generating draft...'),
        'admin.simbrief.js_failed' => view_browser_i18n_string($strings, 'admin.simbrief.js_failed', 'SimBrief generation failed.'),
        'admin.simbrief.js_empty' => view_browser_i18n_string($strings, 'admin.simbrief.js_empty', 'SimBrief returned flight data, but no description could be generated.'),
        'admin.simbrief.js_generated' => view_browser_i18n_string($strings, 'admin.simbrief.js_generated', 'Draft generated. Review it, then save the gallery.'),
        'admin.simbrief.js_invalid_json' => view_browser_i18n_string($strings, 'admin.simbrief.js_invalid_json', 'The server returned an invalid SimBrief response.'),
        'admin.simbrief.js_html_response' => view_browser_i18n_string($strings, 'admin.simbrief.js_html_response', 'The server returned HTML instead of JSON. Check the admin logs or PHP error log.'),
        'lightbox.no_gps_title' => view_browser_i18n_string($strings, 'lightbox.no_gps_title', 'No GPS EXIF data'),
        'lightbox.no_gps_detail' => view_browser_i18n_string($strings, 'lightbox.no_gps_detail', 'This photo has no coordinates, so the fullscreen map is unavailable for this item.'),
    ]);
}

/**
 * Handle view render browser i18n script.
 *
 * Used by server-rendered view helpers.
 */
function view_render_browser_i18n_script(): void
{
    $page = (string) ($_GET['page'] ?? 'home');
    $isAdminPage = str_starts_with($page, 'admin') || $page === 'setup';
    echo '<script src="' . e(view_browser_i18n_asset_url($isAdminPage, translation_active_language())) . '"></script>';
}

/**
 * Handle view render footer.
 *
 * Used by server-rendered view helpers.
 */
function view_render_footer(): void
{
    $page = (string) ($_GET['page'] ?? 'home');
    $hasAdminShell = (str_starts_with($page, 'admin') || $page === 'setup') && current_user();
    echo '</main>' . ($hasAdminShell ? '</div>' : '') . '<footer class="site-footer muted">';
    echo '<a class="site-footer-link" href="' . e(cms_github_project_url()) . '" target="_blank" rel="noopener noreferrer">PHP Gallery (' . e(cms_current_version()) . ')</a>';
    echo '</footer>';
    $page = (string) ($_GET['page'] ?? 'home');
    $isAdminPage = str_starts_with($page, 'admin') || $page === 'setup';
    $user = current_user();
    $anonymousPreview = admin_anonymous_preview_active();
    $scriptAsset = view_script_asset_for_context($isAdminPage, is_array($user) ? $user : null, $anonymousPreview);
    $scriptPath = dirname(__DIR__, 2) . '/public/' . $scriptAsset;
    $scriptVersionPaths = $scriptAsset === 'assets/public-gallery.js' ? [
        $scriptPath,
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/lightbox-deferred.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/lightbox.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-core.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/votes.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/public-home-search.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/back-to-top.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/responsive-thumbnails.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/progressive-thumbnail-renderer.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/progressive-thumbnail-upgrade.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/public-thumbnail-render-diagnostics.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/thumbnail-warmup.js',
    ] : [
        $scriptPath,
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/progressive-thumbnail-renderer.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/progressive-thumbnail-upgrade.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/public-thumbnail-render-diagnostics.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/lightbox.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/lightbox-votes.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/tag-suggestions.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/votes.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-operations.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-update-jobs.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-core.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-nested-tabs.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-side-panel.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-date-picker.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-gallery-date-suggestion.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-duplicate-photo-detector.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-simbrief-description.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-storage-statistics.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-gallery-report.js',
    ];
    $resolvedScriptVersion = asset_dependency_revision($scriptVersionPaths);
    view_render_browser_i18n_script();
    echo '<script type="module" data-gallery-asset-revision="' . e((string) $resolvedScriptVersion) . '" src="' . e(asset_url($scriptAsset)) . '?v=' . $resolvedScriptVersion . '"></script>';
    echo cms_footer_scripts_html();
    echo '</body></html>';
}
