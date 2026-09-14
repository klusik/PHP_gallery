<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/shared_layout.php
 * Module Type: Controller
 *
 * Purpose:
 *   Prepares request-independent view models for the shared public/admin layout.
 *
 * Responsibilities:
 *   - Resolve shared header, footer, branding, account, and feature-policy state
 *   - Prepare public language-selector presentation data before Views render it
 *   - Prepare browser-i18n dictionaries and cacheable asset URLs
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
use function Gallery\Core\current_login_return_target;
use function Gallery\Core\current_user;
use function Gallery\Core\url_for;
use function Gallery\Services\admin_test_run_active;
use function Gallery\Services\admin_test_run_panel_model;
use function Gallery\Services\app_setting;
use function Gallery\Services\application_update_nav_label;
use function Gallery\Services\application_update_pending;
use function Gallery\Services\cms_github_project_url;
use function Gallery\Services\current_viewer;
use function Gallery\Services\custom_css_path;
use function Gallery\Services\custom_css_url;
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
 * @return array<string, mixed>
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
    ];
}

/**
 * Prepare the complete shared header model.
 *
 * @param ?array $currentGallery Current gallery row or null.
 * @param bool $publicOnly Whether public-only gallery visibility rules apply.
 * @param array<string, mixed> $requestQuery Current query parameters.
 * @param string $requestUri Current request URI.
 * @param string $scriptName Current front-controller script name.
 * @param string $page Current route/page identifier.
 * @param string $headExtras Already-buffered trusted head extras.
 * @return array<string, mixed>
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
    $favoritePublicOnly = !$user || $anonymousPreview;
    $viewerAccounts = $bodyClass === 'public-page' && viewer_accounts_enabled();
    $adminTestRunEligible = $bodyClass === 'public-page'
        && $user
        && !$anonymousPreview
        && in_array($page, ['gallery', 'smart_gallery'], true);
    $updatePending = $user && !$anonymousPreview && $bodyClass === 'public-page' ? application_update_pending() : false;
    $customCssUrl = custom_css_url();
    $customCssVersion = 0;
    if ($customCssUrl) {
        $customCssPath = custom_css_path();
        $customCssVersion = is_file($customCssPath) ? (int) filemtime($customCssPath) : 0;
    }

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
        'head_extras' => $headExtras,
        'canonical_url' => $bodyClass === 'public-page'
            && stripos($headExtras, 'rel="canonical"') === false
            && stripos($headExtras, "rel='canonical'") === false
                ? seo_request_guard_public_canonical_url($page, $currentGallery, $requestQuery)
                : '',
        'dev_mode_active' => (bool) ($user && dev_mode_enabled()),
        'branding' => shared_layout_branding_model($currentGallery, $publicOnly, $bodyClass),
        'language_selector' => $bodyClass === 'public-page' ? shared_layout_language_selector_model($requestUri, $scriptName) : ['enabled' => false, 'items' => []],
        'favorite_gallery_items' => theme_favorite_gallery_navigation_items($favoritePublicOnly),
        'viewer_accounts_enabled' => $viewerAccounts,
        'viewer_logged_in' => $viewerAccounts && current_viewer() !== null,
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
 * @return array{language:string,strings:array<string,mixed>}
 */
function shared_layout_browser_i18n_model(?string $language = null): array
{
    $resolvedLanguage = shared_layout_browser_i18n_language($language);
    $defaultLanguage = translation_default_language();
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
