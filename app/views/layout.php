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
 *   2026-09-14
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
use function Gallery\Core\csrf_token;
use function Gallery\Core\e;
use function Gallery\Core\theme_cache_key;
use function Gallery\Core\url_for;
use function Gallery\Services\t;

/**
 * Render the public visitor language selector in the shared header.
 *
 * The compact links work without JavaScript. Each language keeps the current
 * public route and query state; the following request stores the choice.
 */
function view_render_public_language_selector(array $model = []): void
{
    if (empty($model['enabled'])) {
        return;
    }
    $label = t('public.language.selector_label', 'Language');
    $classes = trim((string) ($model['classes'] ?? 'public-language-switcher'));
    $style = trim((string) ($model['style'] ?? ''));

    echo '<div class="' . e($classes) . '" role="group" aria-label="' . e($label) . '"' . ($style !== '' ? ' style="' . e($style) . '"' : '') . '>';
    foreach ((array) ($model['items'] ?? []) as $item) {
        $language = trim((string) ($item['code'] ?? ''));
        if ($language === '') {
            continue;
        }
        $languageName = trim((string) ($item['name'] ?? strtoupper($language)));
        $flagAsset = trim((string) ($item['flag_asset'] ?? ''));
        $isActive = !empty($item['active']);
        echo '<a class="public-language-button' . ($isActive ? ' is-active' : '') . '" href="' . e((string) ($item['url'] ?? '#')) . '" hreflang="' . e($language) . '" lang="' . e($language) . '" aria-label="' . e($languageName) . '" title="' . e($languageName) . '"' . ($isActive ? ' aria-current="true"' : '') . '>';
        if (!empty($model['show_codes'])) {
            echo '<span class="public-language-code" aria-hidden="true">' . e(strtoupper($language)) . '</span>';
        }
        if (!empty($model['show_names'])) {
            echo '<span class="public-language-name" aria-hidden="true">' . e($languageName) . '</span>';
        }
        if (!empty($model['show_flags']) && $flagAsset !== '') {
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
function view_public_header_branding_model(array $model = []): array
{
    return [
        'banner_url' => trim((string) ($model['banner_url'] ?? '')),
        'logo_url' => trim((string) ($model['logo_url'] ?? '')),
        'separator_url' => trim((string) ($model['separator_url'] ?? '')),
    ];
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
        'assets/styles/admin-maintenance-center.css',
        'assets/styles/admin-subtabs.css',
        'assets/styles/admin-theme-preview.css',
        'assets/styles/admin-reordering.css',
        'assets/styles/admin-media-tools.css',
        'assets/styles/admin-theme-editor.css',
        'assets/styles/admin-gallery-list.css',
        'assets/styles/admin-gallery-title-completion.css',
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
    if (!view_should_load_admin_assets($bodyClass, $user, $anonymousPreview)) {
        return view_public_stylesheet_files();
    }

    $files = view_admin_stylesheet_files();
    if ($bodyClass !== 'admin-page' && $user !== null && !$anonymousPreview && !in_array('assets/styles/public-shared.css', $files, true)) {
        $lightboxIndex = array_search('assets/styles/lightbox.css', $files, true);
        $insertAt = $lightboxIndex === false ? 2 : ((int) $lightboxIndex + 1);
        array_splice($files, $insertAt, 0, ['assets/styles/public-shared.css']);
    }
    return $files;
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
function view_render_header(
    string $title,
    array $model = [],
    string $requestUri = '',
    string $page = 'home'
): void
{
    $user = is_array($model['user'] ?? null) ? $model['user'] : null;
    $anonymousPreview = !empty($model['anonymous_preview']);
    $siteName = (string) ($model['site_name'] ?? 'PHP Gallery');
    $theme = is_array($model['theme'] ?? null) ? $model['theme'] : [];
    $bodyClass = (string) ($model['body_class'] ?? (str_starts_with($page, 'admin') || $page === 'setup' ? 'admin-page' : 'public-page'));
    $pageWidthClass = (string) ($model['page_width_class'] ?? '');
    $activeLanguage = trim((string) ($model['active_language'] ?? 'en')) ?: 'en';
    echo '<!doctype html><html lang="' . e($activeLanguage) . '" translate="no"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title === $siteName ? $siteName : $title . ' - ' . $siteName) . '</title>';
    $faviconUrl = trim((string) ($model['favicon_url'] ?? ''));
    if ($faviconUrl !== '') {
        $faviconVersion = (string) ($model['favicon_version'] ?? '1');
        echo '<link rel="icon" type="image/png" sizes="32x32" href="' . e($faviconUrl) . '&s=32&v=' . e($faviconVersion) . '">';
        echo '<link rel="icon" type="image/png" sizes="48x48" href="' . e($faviconUrl) . '&s=48&v=' . e($faviconVersion) . '">';
        echo '<link rel="apple-touch-icon" sizes="180x180" href="' . e($faviconUrl) . '&s=180&v=' . e($faviconVersion) . '">';
    }
    if ($bodyClass === 'admin-page') {
        echo '<meta name="robots" content="noindex,nofollow">';
    }
    $styleFiles = view_stylesheet_files_for_context($bodyClass, $user, $anonymousPreview);
    foreach ($styleFiles as $styleFile) {
        $stylePath = dirname(__DIR__, 2) . '/public/' . $styleFile;
        if (!is_file($stylePath)) {
            continue;
        }
        echo '<link rel="stylesheet" href="' . e(asset_url($styleFile)) . '?v=' . filemtime($stylePath) . '">';
    }
    $customCss = trim((string) ($model['custom_css_url'] ?? ''));
    if ($customCss !== '') {
        echo '<link rel="stylesheet" href="' . e($customCss) . '?v=' . e((string) ($model['custom_css_version'] ?? 0)) . '">';
    }
    echo '<link rel="stylesheet" href="' . e(url_for('theme_css')) . '&v=' . rawurlencode((string) theme_cache_key($theme)) . '">';
    $mobileGalleryStyle = 'assets/styles/mobile-gallery.css';
    $mobileGalleryStylePath = dirname(__DIR__, 2) . '/public/' . $mobileGalleryStyle;
    if (is_file($mobileGalleryStylePath)) {
        echo '<link rel="stylesheet" href="' . e(asset_url($mobileGalleryStyle)) . '?v=' . filemtime($mobileGalleryStylePath) . '">';
    }
    $canonicalUrl = trim((string) ($model['canonical_url'] ?? ''));
    if ($canonicalUrl !== '') {
        echo '<link rel="canonical" href="' . e($canonicalUrl) . '">' . "\n";
    }
    echo (string) ($model['head_extras'] ?? '');
    echo '</head><body class="' . e($bodyClass . $pageWidthClass) . '"' . (!empty($model['dev_mode_active']) ? ' data-dev-mode="1"' : '') . '>';
    if ($bodyClass === 'public-page') {
        echo '<div class="theme-background-shell" aria-hidden="true">';
        echo '<div class="theme-background-base"></div>';
        echo '<div class="theme-background-image"></div>';
        echo '</div>';
    }
    $headerBranding = view_public_header_branding_model(is_array($model['branding'] ?? null) ? $model['branding'] : []);
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
        view_render_public_language_selector(is_array($model['language_selector'] ?? null) ? $model['language_selector'] : []);
    }
    echo view_favorite_gallery_nav_html((array) ($model['favorite_gallery_items'] ?? []));
    if ($bodyClass === 'public-page' && !empty($model['viewer_accounts_enabled'])) {
        if (!empty($model['viewer_logged_in'])) {
            echo '<a href="' . e(url_for('viewer_account')) . '">' . e(t('viewer.nav.account', 'Account')) . '</a>';
        } else {
            echo '<a href="' . e(url_for('viewer_login')) . '">' . e(t('viewer.nav.login', 'Login')) . '</a>';
            if (!empty($model['viewer_open_registration'])) {
                echo '<a href="' . e(url_for('viewer_register')) . '">' . e(t('viewer.nav.register', 'Register')) . '</a>';
            }
        }
    }
    if ($user && !$anonymousPreview) {
        if ($bodyClass === 'public-page') {
            $updatePending = !empty($model['update_pending']);
            $updateClass = $updatePending ? ' class="is-update-pending"' : '';
            $updateLabel = trim((string) ($model['update_label'] ?? '')) ?: t('admin.menu.updates', 'Updates');
            echo '<a href="' . e(url_for('admin')) . '">' . e(t('nav.admin', 'Admin')) . '</a>';
            if (!empty($model['admin_test_runs_enabled'])) {
                if (!empty($model['admin_test_run_active'])) {
                    echo '<span class="button secondary is-disabled" aria-disabled="true">' . e(t('admin.test_run.running_button', 'Test run running')) . '</span>';
                } else {
                    echo '<form method="post" action="' . e(url_for('admin_test_run_start')) . '" class="nav-inline-form">';
                    echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
                    echo '<input type="hidden" name="target" value="' . e($requestUri !== '' ? $requestUri : '/') . '">';
                    echo '<input type="hidden" name="target_page" value="' . e($page) . '">';
                    echo '<button type="submit" class="button secondary">' . e(t('admin.test_run.button', 'Test run')) . '</button>';
                    echo '</form>';
                }
            }
            echo '<a' . $updateClass . ' href="' . e(url_for('admin_update')) . '">' . e($updateLabel) . '</a>';
        }
        echo '<a href="' . e(url_for('admin_logout')) . '">' . e(t('nav.logout', 'Logout')) . '</a>';
    } else {
        $returnTarget = trim((string) ($model['admin_login_return'] ?? ''));
        $adminLoginParams = $returnTarget !== '' ? ['return' => $returnTarget] : [];
        echo '<a href="' . e(url_for('admin_login', $adminLoginParams)) . '">' . e(t('nav.admin_login', 'Admin login')) . '</a>';
    }
    echo '</nav></header>';
    if ($headerBranding['separator_url'] !== '') {
        echo '<div class="site-branding-separator" aria-hidden="true"><img src="' . e($headerBranding['separator_url']) . '" alt="" decoding="async"></div>';
    }
    if ($bodyClass === 'admin-page' && $user) {
        echo '<div class="admin-shell">';
        view_render_admin_sidebar($page, is_array($model['admin_chrome'] ?? null) ? $model['admin_chrome'] : []);
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
function view_browser_i18n_language(?string $language = null, string $activeLanguage = 'en', array $allowedLanguages = []): string
{
    $candidate = strtolower(trim((string) ($language ?? '')));
    $candidate = str_replace('_', '-', $candidate);
    if ($candidate !== '' && ($allowedLanguages === [] || in_array($candidate, $allowedLanguages, true))) {
        return $candidate;
    }
    return $activeLanguage !== '' ? $activeLanguage : 'en';
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
function view_browser_i18n_cache_key(string $language = 'en', array $paths = []): string
{
    $latest = 0;
    foreach ($paths as $path) {
        if (is_string($path) && is_file($path)) {
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
function view_browser_i18n_javascript(string $language = 'en', array $strings = []): string
{
    $payload = [
        'language' => $language !== '' ? $language : 'en',
        'strings' => view_cms_browser_i18n_strings($strings),
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
function view_browser_i18n_asset_url(bool $isAdminPage, string $language = 'en', string $version = ''): string
{
    return url_for($isAdminPage ? 'admin_browser_i18n' : 'browser_i18n', [
        'scope' => $isAdminPage ? 'admin' : 'public',
        'lang' => $language !== '' ? $language : 'en',
        'v' => $version,
    ]);
}

/**
 * Handle view cms browser i18n strings.
 *
 * Used by server-rendered view helpers.
 *
 * @return array Structured result data for the caller.
 */
function view_cms_browser_i18n_strings(array $strings = []): array
{

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
        'viewer.favourites.add' => view_browser_i18n_string($strings, 'viewer.favourites.add', 'Add to favourites'),
        'viewer.favourites.remove' => view_browser_i18n_string($strings, 'viewer.favourites.remove', 'Remove from favourites'),
        'download.progress.preparing' => view_browser_i18n_string($strings, 'download.progress.preparing', 'Preparing download...'),
        'download.progress.downloading' => view_browser_i18n_string($strings, 'download.progress.downloading', 'Downloading files'),
        'download.progress.finalizing' => view_browser_i18n_string($strings, 'download.progress.finalizing', 'Finalizing archive'),
        'download.progress.saving' => view_browser_i18n_string($strings, 'download.progress.saving', 'Saving'),
        'download.progress.complete' => view_browser_i18n_string($strings, 'download.progress.complete', 'Download complete'),
        'download.progress.cancel' => view_browser_i18n_string($strings, 'download.progress.cancel', 'Cancel'),
        'download.progress.retry' => view_browser_i18n_string($strings, 'download.progress.retry', 'Retry'),
        'download.progress.start' => view_browser_i18n_string($strings, 'download.progress.start', 'Save ZIP and start'),
        'download.progress.close' => view_browser_i18n_string($strings, 'download.progress.close', 'Close'),
        'download.progress.failed' => view_browser_i18n_string($strings, 'download.progress.failed', 'Download failed'),
        'download.progress.authorization_expired' => view_browser_i18n_string($strings, 'download.progress.authorization_expired', 'Download authorization expired. Retry to restart the download safely.'),
        'download.progress.files' => view_browser_i18n_string($strings, 'download.progress.files', '{count} files'),
        'download.progress.file_count' => view_browser_i18n_string($strings, 'download.progress.file_count', '{done} / {total} files'),
        'download.progress.current' => view_browser_i18n_string($strings, 'download.progress.current', 'Current: {name}'),
        'download.progress.memory_warning' => view_browser_i18n_string($strings, 'download.progress.memory_warning', 'This browser cannot stream the ZIP directly to disk, so it must temporarily keep the archive in memory.'),
        'download.progress.memory_too_large' => view_browser_i18n_string($strings, 'download.progress.memory_too_large', 'Your browser cannot efficiently create an archive this large. Use a Chromium-based browser with direct file saving support.'),
        'download.progress.empty' => view_browser_i18n_string($strings, 'download.progress.empty', 'This gallery has no downloadable files.'),
        'download.progress.cancelled' => view_browser_i18n_string($strings, 'download.progress.cancelled', 'Download cancelled.'),
        'download.progress.invalid_manifest' => view_browser_i18n_string($strings, 'download.progress.invalid_manifest', 'The server returned an invalid download manifest.'),
        'download.progress.file_failed' => view_browser_i18n_string($strings, 'download.progress.file_failed', 'Could not download {name}.'),
        'viewer.favourites.unavailable' => view_browser_i18n_string($strings, 'viewer.favourites.unavailable', 'Favourites are temporarily unavailable.'),
    ]);
}

/**
 * Handle view render browser i18n script.
 *
 * Used by server-rendered view helpers.
 */
function view_render_browser_i18n_script(string $assetUrl): void
{
    if ($assetUrl === '') {
        return;
    }
    echo '<script src="' . e($assetUrl) . '"></script>';
}

/**
 * Handle view render footer.
 *
 * Used by server-rendered view helpers.
 */
function view_render_footer(string $page = 'home', array $model = []): void
{
    $hasAdminShell = !empty($model['has_admin_shell']);
    $adminTestRunPanel = is_array($model['admin_test_run_panel'] ?? null) ? $model['admin_test_run_panel'] : null;
    if (!$hasAdminShell) {
        view_render_admin_test_run_panel($adminTestRunPanel);
    }
    echo '</main>' . ($hasAdminShell ? '</div>' : '') . '<footer class="site-footer muted">';
    $githubProjectUrl = trim((string) ($model['github_project_url'] ?? ''));
    if ($githubProjectUrl !== '') {
        echo '<a class="site-footer-link" href="' . e($githubProjectUrl) . '" target="_blank" rel="noopener noreferrer">PHP Gallery (' . e(cms_current_version()) . ')</a>';
    } else {
        echo 'PHP Gallery (' . e(cms_current_version()) . ')';
    }
    echo '</footer>';
    $isAdminPage = !empty($model['is_admin_page']);
    $user = is_array($model['user'] ?? null) ? $model['user'] : null;
    $anonymousPreview = !empty($model['anonymous_preview']);
    $scriptAsset = view_script_asset_for_context($isAdminPage, $user, $anonymousPreview);
    $scriptPath = dirname(__DIR__, 2) . '/public/' . $scriptAsset;
    $scriptVersionPaths = $scriptAsset === 'assets/public-gallery.js' ? [
        $scriptPath,
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/lightbox-deferred.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/lightbox.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/lightbox-preload-lifecycle.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/lightbox-zoom-model.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-core.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/votes.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/viewer-favourites.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/public-home-search.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/back-to-top.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/responsive-thumbnails.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/progressive-thumbnail-renderer.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/progressive-thumbnail-upgrade.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/public-thumbnail-render-diagnostics.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/thumbnail-warmup.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/gallery-download.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/zip-stream-writer.js',
    ] : [
        $scriptPath,
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/progressive-thumbnail-renderer.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/progressive-thumbnail-upgrade.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/public-thumbnail-render-diagnostics.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/lightbox.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/lightbox-preload-lifecycle.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/lightbox-zoom-model.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/lightbox-votes.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/tag-suggestions.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/votes.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/viewer-favourites.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-operations.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-update-jobs.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-core.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-nested-tabs.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-side-panel.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-date-picker.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-gallery-date-suggestion.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-gallery-title-completion.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-duplicate-photo-detector.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-simbrief-description.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-storage-statistics.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-gallery-report.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-maintenance-center.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/admin-test-run.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/gallery-download.js',
        dirname(__DIR__, 2) . '/public/assets/gallery-modules/zip-stream-writer.js',
    ];
    $resolvedScriptVersion = asset_dependency_revision($scriptVersionPaths);
    view_render_browser_i18n_script((string) ($model['browser_i18n_asset_url'] ?? ''));
    echo '<script type="module" data-gallery-asset-revision="' . e((string) $resolvedScriptVersion) . '" src="' . e(asset_url($scriptAsset)) . '?v=' . $resolvedScriptVersion . '"></script>';
    echo cms_footer_scripts_html();
    echo '</body></html>';
}
