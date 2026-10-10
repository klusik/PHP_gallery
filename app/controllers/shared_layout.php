<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/shared_layout.php
 * Module Type: Controller
 *
 * Purpose:
 *   Prepares view models for the shared public/admin layout, including preview-safe viewer and test-run state.
 *
 * Responsibilities:
 *   - Resolve shared header, footer, branding, account, and feature-policy state
 *   - Prepare public language-selector presentation data before Views render it
 *   - Prepare browser-i18n dictionaries and cacheable asset URLs
 *   - Prepare authorized visual-preview background ownership without changing ordinary render lookups
 *   - Keep shared Views independent from Service-layer calls
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
 *   - This controller is a presentation-boundary adapter. It must not render HTML.
 *
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Core\admin_anonymous_preview_active;
use function Gallery\Core\asset_url;
use function Gallery\Core\current_login_return_target;
use function Gallery\Core\current_user;
use function Gallery\Core\public_visual_preview_url;
use function Gallery\Core\request_mount_url;
use function Gallery\Core\url_for;
use function Gallery\Services\admin_legacy_upload_navigation_enabled;
use function Gallery\Services\admin_test_run_active;
use function Gallery\Services\admin_test_run_panel_model;
use function Gallery\Services\app_setting;
use function Gallery\Services\application_update_nav_label;
use function Gallery\Services\application_update_pending;
use function Gallery\Services\cms_github_project_url;
use function Gallery\Services\current_viewer;
use function Gallery\Services\custom_css_path;
use function Gallery\Services\custom_css_url;
use function Gallery\Services\custom_css_overrides_state;
use function Gallery\Services\dev_mode_enabled;
use function Gallery\Services\favicon_asset_url;
use function Gallery\Services\feature_capability_effective_enabled;
use function Gallery\Services\gallery_branding_asset_url;
use function Gallery\Services\gallery_branding_schema_ready;
use function Gallery\Services\seo_request_guard_public_canonical_url;
use function Gallery\Services\site_name;
use function Gallery\Services\theme_branding_asset_url;
use function Gallery\Services\theme_favorite_gallery_navigation_items;
use function Gallery\Services\theme_page_width_mode;
use function Gallery\Services\theme_settings;
use function Gallery\Services\theme_background_visual_editor_context;
use function Gallery\Core\theme_cache_key;
use function Gallery\Services\translation_active_language;
use function Gallery\Services\translation_default_language;
use function Gallery\Services\translation_language_allowed;
use function Gallery\Services\translation_language_dir;
use function Gallery\Services\translation_language_presentation;
use function Gallery\Services\translation_load_language;
use function Gallery\Services\translation_normalize_language_code;
use function Gallery\Services\translation_public_language_url;
use function Gallery\Services\translation_public_language_selector_design;
use function Gallery\Services\translation_public_language_selector_design_style;
use function Gallery\Services\translation_public_language_selector_enabled;
use function Gallery\Services\translation_public_language_selector_languages;
use function Gallery\Services\viewer_accounts_enabled;
use function Gallery\Services\viewer_http_open_registration_available;

/**
 * Return the complete stylesheet set used by authenticated and Admin screens.
 * @return list<string> Ordered public-root-relative stylesheet paths.
 */
function shared_layout_admin_stylesheet_files(): array
{
    return [
        'assets/styles/base.css', 'assets/styles/public.css', 'assets/styles/lightbox.css', 'assets/styles/admin.css',
        'assets/styles/admin-layout.css', 'assets/styles/admin-dashboard.css', 'assets/styles/admin-maintenance-center.css',
        'assets/styles/admin-telemetry.css', 'assets/styles/admin-logs.css', 'assets/styles/admin-subtabs.css',
        'assets/styles/admin-theme-preview.css', 'assets/styles/admin-setup-wizard.css', 'assets/styles/admin-reordering.css',
        'assets/styles/admin-public-widgets.css',
        'assets/styles/admin-media-tools.css', 'assets/styles/admin-theme-editor.css', 'assets/styles/admin-theme-media.css',
        'assets/styles/admin-theme-layout.css', 'assets/styles/admin-theme-language.css', 'assets/styles/admin-theme-custom-css.css',
        'assets/styles/admin-gallery-list.css', 'assets/styles/admin-smart-galleries.css', 'assets/styles/admin-gallery-title-completion.css',
        'assets/styles/admin-patch-notes.css', 'assets/styles/admin-update.css', 'assets/styles/admin-tags.css',
        'assets/styles/side-panel.css', 'assets/styles/admin-gallery-create.css', 'assets/styles/admin-duplicate-photo-detector.css',
        'assets/styles/admin-cinematic.css', 'assets/styles/admin-settings.css', 'assets/styles/utilities.css',
        'assets/styles.css', 'assets/styles/admin-gallery-api.css', 'assets/styles/admin-gallery-access.css',
        'assets/styles/admin-gallery-display.css', 'assets/styles/admin-gallery-media.css', 'assets/styles/breadcrumbs.css',
    ];
}

/**
 * Return the public visitor stylesheet set in its established cascade order.
 * @return list<string> Ordered public-root-relative stylesheet paths.
 */
function shared_layout_public_stylesheet_files(): array
{
    return [
        'assets/styles/base.css', 'assets/styles/public.css', 'assets/styles/lightbox.css',
        'assets/styles/public-shared.css', 'assets/styles/utilities.css', 'assets/styles.css', 'assets/styles/breadcrumbs.css',
    ];
}

/**
 * Select the existing stylesheet set for one prepared layout context.
 * @param string $bodyClass Rendered body class for the public or Admin page family.
 * @param array{id?:int|string,username?:string,email?:string|null}|null $user Authenticated application user, or null for an anonymous visitor.
 * @param bool $anonymousPreview Whether an Admin selected the anonymous visitor presentation.
 * @return list<string> Ordered stylesheet paths matching the prior layout cascade.
 */
function shared_layout_stylesheet_files_for_context(string $bodyClass, ?array $user, bool $anonymousPreview): array
{
    if ($bodyClass !== 'admin-page' && ($user === null || $anonymousPreview)) {
        return shared_layout_public_stylesheet_files();
    }
    $files = shared_layout_admin_stylesheet_files();
    if ($bodyClass !== 'admin-page' && $user !== null && !$anonymousPreview && !in_array('assets/styles/public-shared.css', $files, true)) {
        $lightboxIndex = array_search('assets/styles/lightbox.css', $files, true);
        $insertAt = $lightboxIndex === false ? 2 : ((int) $lightboxIndex + 1);
        array_splice($files, $insertAt, 0, ['assets/styles/public-shared.css']);
    }
    return $files;
}

/**
 * Prepare ordered stylesheet hrefs, including preview context before markup reaches the View.
 * @param string $bodyClass Rendered body class for the current page family.
 * @param array{id?:int|string,username?:string,email?:string|null}|null $user Authenticated application user, or null for an anonymous visitor.
 * @param bool $anonymousPreview Whether visitor visibility rules were selected.
 * @param bool $visualPreview Whether same-origin resource requests should carry visual-preview context.
 * @param array<string,string|int|bool> $theme Current theme settings used by the Theme stylesheet cache key.
 * @param string $customCssUrl Optional installed Custom CSS URL prepared by its owner.
 * @param int $customCssVersion Installed Custom CSS file modification time used for cache invalidation.
 * @return list<string> Ordered base, installed Custom CSS, Theme and mobile stylesheet href values.
 */
function shared_layout_stylesheet_urls(
    string $bodyClass,
    ?array $user,
    bool $anonymousPreview,
    bool $visualPreview,
    array $theme,
    string $customCssUrl,
    int $customCssVersion
): array {
    $urls = [];
    foreach (shared_layout_stylesheet_files_for_context($bodyClass, $user, $anonymousPreview) as $styleFile) {
        $stylePath = dirname(__DIR__, 2) . '/public/' . $styleFile;
        if (!is_file($stylePath)) {
            continue;
        }
        $href = $visualPreview
            ? shared_layout_preview_asset_href($styleFile, 'v=' . rawurlencode((string) filemtime($stylePath)), $anonymousPreview)
            : asset_url($styleFile) . '?v=' . filemtime($stylePath);
        $urls[] = $href;
    }
    if ($customCssUrl !== '') {
        $urls[] = $visualPreview
            ? shared_layout_preview_asset_href('assets/custom.css', 'v=' . rawurlencode((string) $customCssVersion), $anonymousPreview)
            : $customCssUrl . '?v=' . rawurlencode((string) $customCssVersion);
    }
    $themeCssUrl = $visualPreview
        ? shared_layout_preview_route_href('theme_css', $anonymousPreview)
        : url_for('theme_css');
    $urls[] = $themeCssUrl . '&v=' . rawurlencode((string) theme_cache_key($theme));
    $mobileCssPath = dirname(__DIR__, 2) . '/public/assets/styles/mobile-gallery.css';
    if (is_file($mobileCssPath)) {
        $urls[] = $visualPreview
            ? shared_layout_preview_asset_href('assets/styles/mobile-gallery.css', 'v=' . rawurlencode((string) filemtime($mobileCssPath)), $anonymousPreview)
            : asset_url('assets/styles/mobile-gallery.css') . '?v=' . filemtime($mobileCssPath);
    }
    return $urls;
}

/**
 * Decorate the canonical app-owned asset URL with visual-preview context.
 * @param string $assetPath Path under the public assets directory.
 * @param string $query Query cache key without a leading question mark.
 * @param bool $anonymous Whether the current visual preview uses anonymous visitor visibility.
 * @return string Same-origin mounted stylesheet URL with inherited preview context.
 */
function shared_layout_preview_asset_href(string $assetPath, string $query, bool $anonymous): string
{
    $url = asset_url($assetPath);
    if ($query !== '') {
        $url .= (str_contains($url, '?') ? '&' : '?') . ltrim($query, '?&');
    }
    return public_visual_preview_url($url, $anonymous);
}

/**
 * Build one app-owned query route from the actual request mount for a preview document.
 * @param string $page Canonical route identifier to preserve.
 * @param bool $anonymous Whether the current visual preview uses anonymous visitor visibility.
 * @return string Same-origin mounted route URL with inherited preview context.
 */
function shared_layout_preview_route_href(string $page, bool $anonymous): string
{
    $path = request_mount_url('index.php?page=' . rawurlencode($page));
    return public_visual_preview_url($path, $anonymous);
}

/**
 * Prepare optional artwork for the shared public header.
 *
 * @param ?array $currentGallery Current gallery row or null.
 * @param bool $publicOnly Whether unpublished/private gallery assets must remain hidden.
 * @param string $bodyClass Resolved page family class.
 * @return array{banner_url:string,logo_url:string,separator_url:string}
 */
function shared_layout_branding_model(?array $currentGallery, bool $publicOnly, string $bodyClass): array
{
    $model = [
        'banner_url' => '',
        'logo_url' => '',
        'separator_url' => '',
    ];
    if ($bodyClass !== 'public-page') {
        return $model;
    }

    if ($currentGallery !== null && gallery_branding_schema_ready()) {
        $model['banner_url'] = gallery_branding_asset_url($currentGallery, 'banner', $publicOnly);
        $model['logo_url'] = gallery_branding_asset_url($currentGallery, 'logo', $publicOnly);
        $model['separator_url'] = gallery_branding_asset_url($currentGallery, 'separator', $publicOnly);
    }
    if ($model['banner_url'] === '') {
        $model['banner_url'] = theme_branding_asset_url('banner');
    }
    if ($model['separator_url'] === '') {
        $model['separator_url'] = theme_branding_asset_url('separator');
    }
    return $model;
}

/**
 * Prepare the public language selector so the View only renders supplied values.
 *
 * @param string $requestUri Current request URI.
 * @param string $scriptName Current front-controller script name.
 * @return array<string, mixed>
 */
function shared_layout_language_selector_model(string $requestUri, string $scriptName): array
{
    if (!translation_public_language_selector_enabled()) {
        return ['enabled' => false, 'items' => []];
    }

    $activeLanguage = translation_active_language();
    $presentations = translation_language_presentation();
    $design = translation_public_language_selector_design();
    $preset = (string) ($design['preset'] ?? 'default');
    $items = [];
    foreach (translation_public_language_selector_languages() as $language) {
        $presentation = $presentations[$language] ?? ['name' => strtoupper($language), 'flag_asset' => ''];
        $items[] = [
            'code' => $language,
            'name' => trim((string) ($presentation['name'] ?? strtoupper($language))),
            'flag_asset' => trim((string) ($presentation['flag_asset'] ?? '')),
            'active' => $language === $activeLanguage,
            'url' => translation_public_language_url($language, $requestUri, $scriptName),
        ];
    }

    return [
        'enabled' => true,
        'classes' => 'public-language-switcher language-preset-' . $preset
            . ' language-orientation-' . (string) ($design['orientation'] ?? 'horizontal')
            . ' language-density-' . (string) ($design['density'] ?? 'normal')
            . ' language-align-' . (string) ($design['alignment'] ?? 'end')
            . ' language-active-' . (string) ($design['active_style'] ?? 'filled'),
        'style' => translation_public_language_selector_design_style($design),
        'show_codes' => !empty($design['show_codes']),
        'show_names' => !empty($design['show_names']),
        'show_flags' => !empty($design['show_flags']),
        'items' => $items,
    ];
}

/**
 * Prepare feature-policy and updater state consumed by the Admin sidebar View.
 *
 * @return array{update_pending:bool,update_label:string,feature_enabled:array<string,bool>,admin_legacy_upload_navigation_enabled:bool} Prepared sidebar state.
 */
function shared_layout_admin_chrome_model(): array
{
    $updatePending = application_update_pending();
    $featureEnabled = [];
    foreach (['smart_galleries', 'mobile_webdav', 'media_renamer', 'upload_api', 'telemetry', 'complete_gallery_report', 'navigation_data', 'viewer_accounts'] as $featureKey) {
        $featureEnabled[$featureKey] = feature_capability_effective_enabled($featureKey);
    }

    return [
        'update_pending' => $updatePending,
        'update_label' => application_update_nav_label($updatePending),
        'feature_enabled' => $featureEnabled,
        'admin_legacy_upload_navigation_enabled' => admin_legacy_upload_navigation_enabled(),
    ];
}

/**
 * Prepare the complete shared header model.
 *
 * @param array<string,scalar|null>|null $currentGallery Current database gallery row, including id/title/visibility and optional branding paths, or null.
 * @param bool $publicOnly Whether public-only gallery visibility rules apply.
 * @param array<string,scalar|array<array-key,scalar|null>|null> $requestQuery Transport query values used for canonical preview authorization and canonical-URL normalization.
 * @param string $requestUri Current request URI.
 * @param string $scriptName Current front-controller script name.
 * @param string $page Current route/page identifier; visual background editor metadata is document-only for Home and Gallery routes.
 * @param string $headExtras Already-buffered trusted head extras.
 * @return array{user?:array{id?:int|string,username?:string,email?:string|null}|null,anonymous_preview?:bool,site_name?:string,theme?:array<string,string|int|bool>,body_class?:string,page_width_class?:string,active_language?:string,favicon_url?:string|null,favicon_version?:string,custom_css_url?:string,custom_css_version?:int,custom_css_overrides_url?:string,stylesheet_urls?:list<string>,head_extras?:string,canonical_url?:string,dev_mode_active?:bool,branding?:array{banner_url:string,logo_url:string,separator_url:string},visual_background?:array{target:'theme'|'none',owner:'theme_image'|'theme_gallery_fallback'|'gallery_override'|'none',mode:'theme_image'|'upload'|'existing'|'collage'|'none',url:string}|null,language_selector?:array{enabled:bool,classes?:string,style?:string,show_codes?:bool,show_names?:bool,show_flags?:bool,items:list<array{code:string,name:string,flag_asset:string,active:bool,url:string}>},favorite_gallery_items?:list<array{id:int|string,title:string,url:string,gallery?:array<string,scalar|null>|null}>,viewer_accounts_enabled?:bool,viewer_logged_in?:bool,viewer_open_registration?:bool,update_pending?:bool,update_label?:string,admin_test_runs_enabled?:bool,admin_test_run_active?:bool,admin_login_return?:string,admin_chrome?:array{update_pending?:bool,update_label?:string,feature_enabled?:array<string,bool>,admin_legacy_upload_navigation_enabled?:bool}} Prepared header assets and policy; unreadable optional overrides use an empty public URL, and preview background metadata is populated only for active, allowed Home/Gallery document requests.
 */
function shared_layout_header_model(
    ?array $currentGallery,
    bool $publicOnly,
    array $requestQuery,
    string $requestUri,
    string $scriptName,
    string $page,
    string $headExtras = ''
): array {
    $user = current_user();
    $anonymousPreview = admin_anonymous_preview_active();
    $siteName = site_name();
    $theme = theme_settings();
    $bodyClass = str_starts_with($page, 'admin') || $page === 'setup' ? 'admin-page' : 'public-page';
    // The shared error layout also renders denied requests, so a raw marker must not activate privileged preview metadata.
    $previewDecision = \Gallery\Services\public_visual_preview_request_decision(
        $page,
        strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
        is_array($user) && (string) ($user['role'] ?? '') === 'admin',
        $requestQuery
    );
    $visualPreview = !empty($previewDecision['active']) && !empty($previewDecision['allowed']);
    // Media authorization failures reuse the public layout but do not load the deferred document-only background resolver.
    $visualPreviewDocument = $visualPreview && in_array($page, ['home', 'gallery'], true);
    $visualBackgroundContext = $visualPreviewDocument && $bodyClass === 'public-page'
        ? theme_background_visual_editor_context($currentGallery, $publicOnly)
        : null;
    $favoritePublicOnly = !$user || $anonymousPreview;
    $viewerAccounts = $bodyClass === 'public-page' && viewer_accounts_enabled();
    $viewerPrincipal = $anonymousPreview ? null : current_viewer();
    $adminTestRunEligible = !\Gallery\Services\public_visual_preview_is_active($requestQuery)
        && $bodyClass === 'public-page'
        && $user
        && !$anonymousPreview
        && in_array($page, ['gallery', 'smart_gallery'], true);
    $updatePending = $user && !$anonymousPreview && $bodyClass === 'public-page' ? application_update_pending() : false;
    $customCssUrl = custom_css_url();
    $overrideCssUrl = '';
    if ($bodyClass === 'public-page') {
        try {
            $overrideCssUrl = custom_css_overrides_state()['url'];
        } catch (\RuntimeException | \InvalidArgumentException) {
            // An unreadable optional asset cannot prevent the visitor or administrator from opening the site.
            $overrideCssUrl = '';
        }
    }
    $customCssVersion = 0;
    if ($customCssUrl) {
        $customCssPath = custom_css_path();
        $customCssVersion = is_file($customCssPath) ? (int) filemtime($customCssPath) : 0;
    }
    if ($visualPreview && $overrideCssUrl !== '') {
        $overrideCssUrl = shared_layout_preview_asset_href(
            'assets/custom-overrides.css',
            (string) (parse_url($overrideCssUrl, PHP_URL_QUERY) ?? ''),
            $anonymousPreview
        );
    }
    $stylesheetUrls = shared_layout_stylesheet_urls(
        $bodyClass,
        is_array($user) ? $user : null,
        $anonymousPreview,
        $visualPreview,
        $theme,
        (string) ($customCssUrl ?: ''),
        $customCssVersion
    );

    return [
        'user' => is_array($user) ? $user : null,
        'anonymous_preview' => $anonymousPreview,
        'site_name' => $siteName,
        'theme' => $theme,
        'body_class' => $bodyClass,
        'page_width_class' => $bodyClass === 'public-page' ? ' page-width-' . theme_page_width_mode((string) ($theme['page_width'] ?? 'default')) : '',
        'active_language' => translation_active_language(),
        'favicon_url' => favicon_asset_url(),
        'favicon_version' => (string) app_setting('favicon_version', '1'),
        'custom_css_url' => (string) ($customCssUrl ?: ''),
        'custom_css_version' => $customCssVersion,
        'custom_css_overrides_url' => $overrideCssUrl,
        'stylesheet_urls' => $stylesheetUrls,
        'head_extras' => $headExtras,
        'canonical_url' => $bodyClass === 'public-page'
            && stripos($headExtras, 'rel="canonical"') === false
            && stripos($headExtras, "rel='canonical'") === false
                ? seo_request_guard_public_canonical_url($page, $currentGallery, $requestQuery)
                : '',
        'dev_mode_active' => (bool) ($user && !$anonymousPreview && dev_mode_enabled()),
        'branding' => shared_layout_branding_model($currentGallery, $publicOnly, $bodyClass),
        'visual_background' => $visualBackgroundContext,
        'language_selector' => $bodyClass === 'public-page' ? shared_layout_language_selector_model($requestUri, $scriptName) : ['enabled' => false, 'items' => []],
        'favorite_gallery_items' => theme_favorite_gallery_navigation_items($favoritePublicOnly),
        'viewer_accounts_enabled' => $viewerAccounts,
        'viewer_logged_in' => $viewerAccounts && $viewerPrincipal !== null,
        'viewer_open_registration' => $viewerAccounts && viewer_http_open_registration_available(),
        'update_pending' => $updatePending,
        'update_label' => $updatePending || ($user && !$anonymousPreview && $bodyClass === 'public-page') ? application_update_nav_label($updatePending) : '',
        'admin_test_runs_enabled' => $adminTestRunEligible && feature_capability_effective_enabled('admin_test_runs'),
        'admin_test_run_active' => $adminTestRunEligible && admin_test_run_active(),
        'admin_login_return' => str_starts_with($page, 'viewer_') ? '' : current_login_return_target(),
        'admin_chrome' => $bodyClass === 'admin-page' && $user ? shared_layout_admin_chrome_model() : [],
    ];
}

/**
 * Resolve a safe language for browser-side translations.
 *
 * @param ?string $language Requested language code.
 * @return string Safe active language code.
 */
function shared_layout_browser_i18n_language(?string $language = null): string
{
    $candidate = translation_normalize_language_code((string) ($language ?? ''));
    if ($candidate !== '' && translation_language_allowed($candidate)) {
        return $candidate;
    }
    return translation_active_language();
}

/**
 * Prepare merged dictionaries consumed by the pure browser-i18n View formatter.
 *
 * @param ?string $language Requested language code.
 * @param ?string $fallbackLanguage Additional fallback catalog, defaulting to the configured site language.
 * @return array{language:string,strings:array<string,mixed>} Resolved language and merged browser translation catalog.
 */
function shared_layout_browser_i18n_model(?string $language = null, ?string $fallbackLanguage = null): array
{
    $resolvedLanguage = shared_layout_browser_i18n_language($language);
    $defaultLanguage = $fallbackLanguage !== null
        ? shared_layout_browser_i18n_language($fallbackLanguage)
        : translation_default_language();
    return [
        'language' => $resolvedLanguage,
        'strings' => array_merge(
            translation_load_language($defaultLanguage),
            translation_load_language($resolvedLanguage)
        ),
    ];
}

/**
 * Return a cache key for the external browser translation asset.
 *
 * @param ?string $language Requested language code.
 * @return string Stable cache key for the selected dictionaries.
 */
function shared_layout_browser_i18n_cache_key(?string $language = null): string
{
    $resolvedLanguage = shared_layout_browser_i18n_language($language);
    $defaultLanguage = translation_default_language();
    $paths = [dirname(__DIR__) . '/views/layout.php'];
    foreach (array_unique([$defaultLanguage, $resolvedLanguage]) as $code) {
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
    return substr(sha1($resolvedLanguage . ':' . (string) $latest), 0, 12);
}

/**
 * Return the cacheable browser-i18n asset URL for one page family.
 *
 * @param bool $isAdminPage Whether the current route renders an admin page.
 * @param ?string $language Requested language code.
 * @return string Route URL for the browser translation asset.
 */
function shared_layout_browser_i18n_asset_url(bool $isAdminPage, ?string $language = null): string
{
    $resolvedLanguage = shared_layout_browser_i18n_language($language);
    return url_for($isAdminPage ? 'admin_browser_i18n' : 'browser_i18n', [
        'scope' => $isAdminPage ? 'admin' : 'public',
        'lang' => $resolvedLanguage,
        'v' => shared_layout_browser_i18n_cache_key($resolvedLanguage),
    ]);
}

/**
 * Prepare shared footer state before the View renders it.
 *
 * @param string $page Current route/page identifier.
 * @return array<string, mixed>
 */
function shared_layout_footer_model(string $page): array
{
    $isAdminPage = str_starts_with($page, 'admin') || $page === 'setup';
    $user = current_user();
    return [
        'has_admin_shell' => $isAdminPage && (bool) $user,
        'github_project_url' => cms_github_project_url(),
        'is_admin_page' => $isAdminPage,
        'user' => is_array($user) ? $user : null,
        'anonymous_preview' => admin_anonymous_preview_active(),
        'browser_i18n_asset_url' => shared_layout_browser_i18n_asset_url($isAdminPage, translation_active_language()),
        'admin_test_run_panel' => admin_test_run_panel_model(),
    ];
}
