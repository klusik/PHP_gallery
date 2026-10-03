<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/legacy_upload_navigation_test.php
 * Module Type: Regression Test
 * Purpose: Verify optional legacy navigation without disabling existing upload routes.
 * Responsibilities:
 *   - Render production sidebar views from prepared preference and capability state
 *   - Preserve public gallery shortcuts and legacy route registrations
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Prepare predictable route URLs for view assertions.
     *
     * @param string $page Route name.
     * @param array<string,string> $query Optional query parameters.
     * @return string Fixture URL.
     */
    function url_for(string $page, array $query = []): string
    {
        return '/?page=' . $page . ($query === [] ? '' : '&' . http_build_query($query));
    }

    /**
     * Escape presentation strings using the production HTML convention.
     *
     * @param string $value Presentation value.
     * @return string Escaped markup text.
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

namespace Gallery\Services {
    /**
     * Return the untranslated fallback to make label assertions deterministic.
     *
     * @param string $key Translation key.
     * @param string $fallback English presentation text.
     * @return string Fixture translation.
     */
    function t(string $key, string $fallback): string
    {
        return $fallback;
    }
}

namespace {
    require_once __DIR__ . '/../app/views/admin_ui.php';
    require_once __DIR__ . '/../app/views/admin_chrome.php';
    require_once __DIR__ . '/../app/views/admin_dashboard_sections.php';

    /**
     * Assert a navigation contract without installation or database dependencies.
     *
     * @param bool $condition Expected behavior.
     * @param string $message Regression description.
     * @return void Throws when the contract is violated.
     */
    function legacy_navigation_expect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /**
     * Render a production sidebar from a prepared controller model.
     *
     * @param array{admin_legacy_upload_navigation_enabled?:bool,feature_enabled?:array<string,bool>,update_pending?:bool,update_label?:string} $model Sidebar presentation state.
     * @return string Rendered sidebar HTML.
     */
    function legacy_navigation_sidebar(array $model): string
    {
        ob_start();
        \Gallery\Views\view_render_admin_sidebar('admin_upload', $model);
        return (string) ob_get_clean();
    }

    $routes = ['admin_upload', 'admin_upload_settings', 'admin_mobile_uploads'];
    $features = ['mobile_webdav' => true, 'smart_galleries' => true, 'upload_api' => true];
    foreach ([[], ['admin_legacy_upload_navigation_enabled' => false]] as $preference) {
        $html = legacy_navigation_sidebar($preference + ['feature_enabled' => $features]);
        foreach ($routes as $route) {
            legacy_navigation_expect(!str_contains($html, 'page=' . $route . '"'), 'Default sidebar must hide ' . $route);
        }
        foreach (['admin_settings', 'admin_new_gallery', 'admin_smart_galleries', 'admin_api_manager', 'admin_tags'] as $route) {
            legacy_navigation_expect(str_contains($html, 'page=' . $route . '"'), 'Unrelated sidebar route remains available: ' . $route);
        }
    }

    $enabled = ['admin_legacy_upload_navigation_enabled' => true, 'feature_enabled' => $features];
    $html = legacy_navigation_sidebar($enabled);
    foreach ($routes as $route) {
        legacy_navigation_expect(str_contains($html, 'page=' . $route . '"'), 'Opt-in sidebar must expose ' . $route);
    }
    foreach (['Legacy uploads', 'Legacy upload settings', 'Legacy mobile uploads'] as $label) {
        legacy_navigation_expect(str_contains($html, $label), 'Restored navigation identifies legacy workflow: ' . $label);
    }
    legacy_navigation_expect(str_contains($html, 'admin-menu-link is-active'), 'Legacy route preserves active-menu styling');
    $enabled['feature_enabled']['mobile_webdav'] = false;
    $html = legacy_navigation_sidebar($enabled);
    legacy_navigation_expect(!str_contains($html, 'page=admin_mobile_uploads"'), 'Mobile capability OFF keeps legacy mobile navigation hidden');
    legacy_navigation_expect(str_contains($html, 'page=admin_upload"') && str_contains($html, 'page=admin_upload_settings"'), 'Mobile OFF does not hide other opted-in legacy routes');

    ob_start();
    \Gallery\Views\view_render_admin_dashboard_upload_card();
    $card = (string) ob_get_clean();
    legacy_navigation_expect(str_contains($card, 'page=home"') && str_contains($card, 'Open galleries'), 'Dashboard directs uploads through public galleries');
    legacy_navigation_expect(str_contains($card, 'page=admin_new_gallery"'), 'Dashboard keeps empty-gallery creation');
    legacy_navigation_expect(!str_contains($card, 'page=admin_upload'), 'Dashboard card does not expose obsolete upload route');

    $dashboard = (string) file_get_contents(__DIR__ . '/../app/views/admin_dashboard.php');
    legacy_navigation_expect(!str_contains($dashboard, "url_for('admin_upload')"), 'Dashboard toolbar and galleries actions must use public gallery shortcuts');
    legacy_navigation_expect(substr_count($dashboard, "t('admin.dashboard.open_galleries', 'Open galleries')") === 1, 'Primary dashboard shortcut clearly names its gallery destination without a duplicate panel link');
    $dispatch = (string) file_get_contents(__DIR__ . '/../app/bootstrap/dispatch.php');
    foreach (array_merge($routes, ['admin_upload_browser_batch', 'admin_upload_automation_token']) as $route) {
        legacy_navigation_expect(str_contains($dispatch, "'" . $route . "' =>"), 'Hidden legacy routes and their actions remain dispatched: ' . $route);
    }
    $controller = (string) file_get_contents(__DIR__ . '/../app/controllers/shared_layout.php');
    legacy_navigation_expect(str_contains($controller, "'admin_legacy_upload_navigation_enabled' => admin_legacy_upload_navigation_enabled()"), 'Controller prepares central navigation preference before rendering');
    echo "Legacy upload navigation tests passed.\n";
}
