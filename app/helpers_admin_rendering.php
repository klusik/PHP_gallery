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
use function Gallery\Controllers\shared_layout_admin_chrome_model;
use function Gallery\Services\app_setting;
use function Gallery\Services\application_update_nav_label;
use function Gallery\Services\application_update_pending;
use function Gallery\Services\cms_github_project_url;
use function Gallery\Services\custom_css_path;
use function Gallery\Services\custom_css_url;
use function Gallery\Services\dev_mode_enabled;
use function Gallery\Services\dng_conversion_supported;
use function Gallery\Services\favicon_asset_url;
use function Gallery\Services\feature_capability_effective_enabled;
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
    if (!function_exists('Gallery\Views\view_admin_menu_structure')) {
        throw new RuntimeException('Admin menu view model is unavailable. Ensure app/views.php is loaded before rendering.');
    }
    return view_admin_menu_structure();
}
/**
 * Return true when one admin menu item should be marked as active.
 */
function admin_menu_item_is_active(array $item, string $currentPage): bool
{
    if (!function_exists('Gallery\Views\view_admin_menu_item_is_active')) {
        throw new RuntimeException('Admin menu presentation helper is unavailable. Ensure app/views.php is loaded before rendering.');
    }
    return view_admin_menu_item_is_active($item, $currentPage);
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
    if (!function_exists('Gallery\Views\view_render_admin_tabs')) {
        throw new RuntimeException('Admin tab view is unavailable. Ensure app/views.php is loaded before rendering.');
    }
    view_render_admin_tabs($tabs, $activeId);
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
    if (!function_exists('Gallery\Views\view_render_admin_tab_panel')) {
        throw new RuntimeException('Admin tab-panel view is unavailable. Ensure app/views.php is loaded before rendering.');
    }
    view_render_admin_tab_panel($id, $contentHtml, $active);
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
    if (!function_exists('Gallery\Views\view_render_admin_subtabs')) {
        throw new RuntimeException('Admin subtab view is unavailable. Ensure app/views.php is loaded before rendering.');
    }
    view_render_admin_subtabs($tabs, $activeId, $ariaLabel);
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
    if (!function_exists('Gallery\Views\view_render_admin_subtab_panel')) {
        throw new RuntimeException('Admin subtab-panel view is unavailable. Ensure app/views.php is loaded before rendering.');
    }
    view_render_admin_subtab_panel($id, $contentHtml, $active);
}

/**
 * Render the persistent admin sidebar used by all authenticated admin pages.
 */
function render_admin_sidebar(string $currentPage): void
{
    if (!function_exists('Gallery\Views\view_render_admin_sidebar')) {
        throw new RuntimeException('Admin sidebar view is unavailable. Ensure app/views.php is loaded before rendering.');
    }
    view_render_admin_sidebar($currentPage, shared_layout_admin_chrome_model());
}


/**
 * Render the admin notice that asks existing admins to add a recovery email.
 */
function render_missing_admin_email_notice(?array $user, string $currentPage): void
{
    if (!function_exists('Gallery\Views\view_render_missing_admin_email_notice')) {
        throw new RuntimeException('Admin account-notice view is unavailable. Ensure app/views.php is loaded before rendering.');
    }
    view_render_missing_admin_email_notice($user, $currentPage);
}
