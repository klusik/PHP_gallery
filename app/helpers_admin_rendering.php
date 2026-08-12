<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/helpers_admin_rendering.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Provides Admin menu, tab, subtab, sidebar, and missing-email rendering helpers.
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
 * Return the canonical admin menu model used by the dashboard and admin shell.
 */
function admin_menu_structure(): array
{
    if (function_exists('Gallery\\Views\\view_admin_menu_structure')) {
        return view_admin_menu_structure();
    }
    // $updatePending stores an intermediate value used by the surrounding gallery workflow.
    $updatePending = function_exists('Gallery\\Services\\application_update_pending') ? application_update_pending() : false;
    // $updateLabel stores an intermediate value used by the surrounding gallery workflow.
    $updateLabel = function_exists('Gallery\\Services\\application_update_nav_label') ? application_update_nav_label($updatePending) : t('admin.menu.updates', 'Updates');
    return [
        [
            'label' => t('admin.menu.dashboard', 'Dashboard'),
            'items' => [
                ['label' => t('admin.menu.overview', 'Overview'), 'page' => 'admin', 'url' => url_for('admin')],
            ],
        ],
        [
            'label' => t('admin.menu.galleries', 'Galleries'),
            'items' => [
                ['label' => t('admin.menu.all_galleries', 'All galleries'), 'page' => 'admin', 'url' => url_for('admin') . '#admin-tab-galleries'],
                ['label' => t('admin.menu.create_gallery', 'Create gallery'), 'page' => 'admin_new_gallery', 'url' => url_for('admin_new_gallery')],
                ['label' => t('admin.menu.upload_photos', 'Upload photos'), 'page' => 'admin_upload', 'url' => url_for('admin_upload')],
                ['label' => t('admin.menu.api_manager', 'API manager'), 'page' => 'admin_api_manager', 'url' => url_for('admin_api_manager'), 'feature' => 'upload_api'],
                ['label' => t('admin.menu.edit_tags', 'Edit tags'), 'page' => 'admin_tags', 'url' => url_for('admin_tags')],
            ],
        ],
        [
            'label' => t('admin.menu.appearance', 'Appearance'),
            'items' => [
                ['label' => t('admin.menu.theme', 'Theme'), 'page' => 'admin_theme', 'url' => url_for('admin_theme')],
                ['label' => t('admin.menu.features', 'Features'), 'page' => 'admin_features', 'url' => url_for('admin_features')],
            ],
        ],
        [
            'label' => t('admin.menu.maintenance', 'Maintenance'),
            'items' => [
                ['label' => t('admin.menu.logs', 'Logs'), 'page' => 'admin_logs', 'url' => url_for('admin_logs')],
                ['label' => t('admin.menu.telemetry', 'Telemetry'), 'page' => 'admin_telemetry', 'url' => url_for('admin_telemetry'), 'feature' => 'telemetry'],
                ['label' => t('admin.menu.gallery_report', 'Complete report'), 'page' => 'admin_gallery_report', 'url' => url_for('admin_gallery_report')],
                ['label' => t('admin.menu.integrity', 'Integrity'), 'page' => 'admin_integrity', 'url' => url_for('admin_integrity')],
                ['label' => $updateLabel, 'page' => 'admin_update', 'url' => url_for('admin_update'), 'highlight' => $updatePending],
            ],
        ],
        [
            'label' => t('admin.menu.account', 'Account'),
            'items' => [
                ['label' => t('admin.menu.profile', 'Profile'), 'page' => 'admin_account', 'url' => url_for('admin_account')],
                ['label' => t('admin.menu.logout', 'Logout'), 'page' => 'admin_logout', 'url' => url_for('admin_logout')],
            ],
        ],
    ];
}
/**
 * Return true when one admin menu item should be marked as active.
 */
function admin_menu_item_is_active(array $item, string $currentPage): bool
{
    if (function_exists('Gallery\\Views\\view_admin_menu_item_is_active')) {
        return view_admin_menu_item_is_active($item, $currentPage);
    }
    // $itemPage stores an intermediate value used by the surrounding gallery workflow.
    $itemPage = (string) ($item['page'] ?? '');
    if ($itemPage === '') {
        return false;
    }
    if ($currentPage === $itemPage) {
        if ($itemPage === 'admin') {
            return !str_contains((string) ($item['url'] ?? ''), '#');
        }
        return true;
    }
    if ($itemPage === 'admin' && in_array($currentPage, ['admin_edit_gallery', 'admin_edit_image'], true)) {
        return str_contains((string) ($item['url'] ?? ''), '#admin-tab-galleries');
    }
    return false;
}


/**
 * Render a reusable admin tab list.
 *
 * Each tab accepts id, label, optional badge, optional href, and optional active.
 * The generated anchors keep normal hash navigation available when JavaScript is
 * unavailable, while the browser module upgrades them to in-page tab controls.
 *
 * @param array<int, array<string, mixed>> $tabs Tab definitions.
 * @param string $activeId Preferred active tab id. The first tab is used when empty.
 * @return void
 */
function render_admin_tabs(array $tabs, string $activeId = ''): void
{
    if (function_exists('Gallery\\Views\\view_render_admin_tabs')) {
        view_render_admin_tabs($tabs, $activeId);
        return;
    }
    // $resolvedActiveId stores the tab id that should be announced as selected.
    $resolvedActiveId = $activeId;
    if ($resolvedActiveId === '') {
        foreach ($tabs as $tab) {
            if (!empty($tab['active']) && !empty($tab['id'])) {
                $resolvedActiveId = (string) $tab['id'];
                break;
            }
        }
    }
    if ($resolvedActiveId === '' && isset($tabs[0]['id'])) {
        $resolvedActiveId = (string) $tabs[0]['id'];
    }

    echo '<nav class="admin-tabs" data-admin-tabs aria-label="' . e(t('admin.tabs.aria_sections', 'Admin sections')) . '">';
    echo '<div class="admin-tab-list" role="tablist">';
    foreach ($tabs as $tab) {
        // $tabId stores the panel id controlled by this tab.
        $tabId = trim((string) ($tab['id'] ?? ''));
        if ($tabId === '') {
            continue;
        }
        // $tabLabel stores the visible tab label.
        $tabLabel = (string) ($tab['label'] ?? $tabId);
        // $tabHref stores the normal link target used without JavaScript.
        $tabHref = (string) ($tab['href'] ?? ('#' . $tabId));
        // $isActive stores whether this tab is selected in server-rendered markup.
        $isActive = $tabId === $resolvedActiveId;
        // $controlId stores the accessible id for the tab control.
        $controlId = $tabId . '-control';
        echo '<a class="admin-tab' . ($isActive ? ' is-active' : '') . '" id="' . e($controlId) . '" href="' . e($tabHref) . '" role="tab" aria-controls="' . e($tabId) . '" aria-selected="' . ($isActive ? 'true' : 'false') . '" tabindex="' . ($isActive ? '0' : '-1') . '" data-admin-tab-target="' . e($tabId) . '">';
        echo '<span>' . e($tabLabel) . '</span>';
        if (array_key_exists('badge', $tab) && $tab['badge'] !== null && $tab['badge'] !== '') {
            echo '<span class="admin-tab-badge">' . e((string) $tab['badge']) . '</span>';
        }
        echo '</a>';
    }
    echo '</div></nav>';
}

/**
 * Render one reusable admin tab panel.
 *
 * Panels are intentionally visible in the raw server response. JavaScript hides
 * inactive panels after it reads the current hash, so the page remains usable
 * when scripting is unavailable.
 *
 * @param string $id Panel id referenced by the matching tab.
 * @param string $contentHtml Trusted admin HTML rendered by the caller.
 * @param bool $active Whether the panel should start selected.
 * @return void
 */
function render_admin_tab_panel(string $id, string $contentHtml, bool $active = false): void
{
    if (function_exists('Gallery\\Views\\view_render_admin_tab_panel')) {
        view_render_admin_tab_panel($id, $contentHtml, $active);
        return;
    }
    // $controlId stores the generated tab id used by aria-labelledby.
    $controlId = $id . '-control';
    echo '<section class="panel admin-tab-panel' . ($active ? ' is-active' : '') . '" id="' . e($id) . '" role="tabpanel" aria-labelledby="' . e($controlId) . '" data-admin-tab-panel>';
    echo $contentHtml;
    echo '</section>';
}



/**
 * Render one reusable admin subtab control row.
 *
 * Subtabs are a lower-level navigation primitive for long admin panels. They are
 * designed to live inside a normal admin tab panel and are intentionally local
 * to their containing area instead of controlling the browser URL hash.
 *
 * @param array<int, array<string, mixed>> $tabs Subtab definitions.
 * @param string $activeId Preferred active subtab id. The first subtab is used when empty.
 * @param string $ariaLabel Accessible label for this subtab group.
 * @return void
 */
function render_admin_subtabs(array $tabs, string $activeId = '', string $ariaLabel = ''): void
{
    if (function_exists('Gallery\\Views\\view_render_admin_subtabs')) {
        view_render_admin_subtabs($tabs, $activeId, $ariaLabel);
        return;
    }

    // $resolvedActiveId stores the selected subtab id for the server-rendered state.
    $resolvedActiveId = $activeId;
    if ($resolvedActiveId === '') {
        foreach ($tabs as $tab) {
            if (!empty($tab['active']) && !empty($tab['id'])) {
                $resolvedActiveId = (string) $tab['id'];
                break;
            }
        }
    }
    if ($resolvedActiveId === '' && isset($tabs[0]['id'])) {
        $resolvedActiveId = (string) $tabs[0]['id'];
    }

    $resolvedAriaLabel = $ariaLabel !== '' ? $ariaLabel : t('admin.subtabs.aria_sections', 'Admin subsection tabs');
    echo '<nav class="admin-subtabs" data-admin-subtabs aria-label="' . e($resolvedAriaLabel) . '">';
    echo '<div class="admin-subtab-list" role="tablist">';
    foreach ($tabs as $tab) {
        // $tabId stores the panel id controlled by this subtab.
        $tabId = trim((string) ($tab['id'] ?? ''));
        if ($tabId === '') {
            continue;
        }
        // $tabLabel stores the visible subtab label.
        $tabLabel = (string) ($tab['label'] ?? $tabId);
        // $isActive stores whether this subtab is selected in server-rendered markup.
        $isActive = $tabId === $resolvedActiveId;
        // $controlId stores the accessible id for the subtab control.
        $controlId = $tabId . '-control';
        echo '<button type="button" class="admin-subtab' . ($isActive ? ' is-active' : '') . '" id="' . e($controlId) . '" role="tab" aria-controls="' . e($tabId) . '" aria-selected="' . ($isActive ? 'true' : 'false') . '" tabindex="' . ($isActive ? '0' : '-1') . '" data-admin-subtab-target="' . e($tabId) . '">';
        echo '<span>' . e($tabLabel) . '</span>';
        if (array_key_exists('badge', $tab) && $tab['badge'] !== null && $tab['badge'] !== '') {
            echo '<span class="admin-subtab-badge">' . e((string) $tab['badge']) . '</span>';
        }
        echo '</button>';
    }
    echo '</div></nav>';
}

/**
 * Render one reusable admin subtab panel.
 *
 * @param string $id Panel id referenced by the matching subtab.
 * @param string $contentHtml Trusted admin HTML rendered by the caller.
 * @param bool $active Whether the panel should start selected.
 * @return void
 */
function render_admin_subtab_panel(string $id, string $contentHtml, bool $active = false): void
{
    if (function_exists('Gallery\\Views\\view_render_admin_subtab_panel')) {
        view_render_admin_subtab_panel($id, $contentHtml, $active);
        return;
    }
    // $controlId stores the generated subtab id used by aria-labelledby.
    $controlId = $id . '-control';
    echo '<section class="admin-subtab-panel' . ($active ? ' is-active' : '') . '" id="' . e($id) . '" role="tabpanel" aria-labelledby="' . e($controlId) . '" data-admin-subtab-panel>';
    echo $contentHtml;
    echo '</section>';
}

/**
 * Render the persistent admin sidebar used by all authenticated admin pages.
 */
function render_admin_sidebar(string $currentPage): void
{
    if (function_exists('Gallery\\Views\\view_render_admin_sidebar')) {
        view_render_admin_sidebar($currentPage);
        return;
    }
    echo '<aside class="admin-sidebar" aria-label="' . e(t('admin.menu.aria_navigation', 'Admin navigation')) . '">';
    echo '<div class="admin-sidebar-title">' . e(t('admin.menu.title', 'Admin')) . '</div>';
    foreach (admin_menu_structure() as $group) {
        echo '<section class="admin-menu-group">';
        echo '<h2>' . e((string) $group['label']) . '</h2>';
        echo '<nav class="admin-menu-links">';
        foreach ((array) $group['items'] as $item) {
            // $featureKey stores the optional feature gate assigned to this menu item.
            $featureKey = (string) ($item['feature'] ?? '');
            if ($featureKey !== '' && function_exists('Gallery\\Services\\feature_flag_enabled') && !feature_flag_enabled($featureKey)) {
                continue;
            }
            // $activeClass stores an intermediate value used by the surrounding gallery workflow.
            $activeClass = admin_menu_item_is_active($item, $currentPage) ? ' is-active' : '';
            // $highlightClass stores an intermediate value used by the surrounding gallery workflow.
            $highlightClass = !empty($item['highlight']) ? ' is-update-pending' : '';
            echo '<a class="admin-menu-link' . e($activeClass . $highlightClass) . '" href="' . e((string) $item['url']) . '">' . e((string) $item['label']) . '</a>';
        }
        echo '</nav></section>';
    }
    echo '</aside>';
}


/**
 * Render the admin notice that asks existing admins to add a recovery email.
 */
function render_missing_admin_email_notice(?array $user, string $currentPage): void
{
    if (function_exists('Gallery\\Views\\view_render_missing_admin_email_notice')) {
        view_render_missing_admin_email_notice($user, $currentPage);
        return;
    }
    if (!$user || $currentPage === 'admin_login' || $currentPage === 'admin_logout' || $currentPage === 'setup') {
        return;
    }
    if (trim((string) ($user['email'] ?? '')) !== '') {
        return;
    }

    echo '<div class="notice admin-account-notice">';
    echo '<strong>' . e(t('admin.account.notice_recovery_email_missing_title', 'Recovery email missing.')) . '</strong> ';
    echo e(t('admin.account.notice_recovery_email_missing_body', 'Add an email address to your account so username-or-email login works and the app is ready for future account recovery.'));
    echo ' <a href="' . e(url_for('admin_account')) . '">' . e(t('admin.account.open_account_settings', 'Open account settings')) . '</a>';
    echo '</div>';
}

