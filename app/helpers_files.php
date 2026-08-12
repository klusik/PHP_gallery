<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/helpers_files.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Provides image-extension, DNG, relative-path, path containment, theme cache key, and CSS value helpers.
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
 * Image extensions accepted during filesystem scans.
 */
function supported_image_extensions(): array
{
    // $extensions stores an intermediate value used by the surrounding gallery workflow.
    $extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (function_exists('Gallery\\Services\\heic_conversion_supported') && heic_conversion_supported()) {
        $extensions[] = 'heic';
        $extensions[] = 'heif';
    }
    if (function_exists('Gallery\\Services\\dng_conversion_supported') && dng_conversion_supported()) {
        $extensions[] = 'dng';
    }
    return $extensions;
}

/**
 * Return whether a filesystem path uses the Adobe Digital Negative extension.
 */
function is_dng_image_path(string $path): bool
{
    return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'dng';
}

/**
 * Check whether a path points to one of the supported image formats.
 */
function is_supported_image_path(string $path): bool
{
    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), supported_image_extensions(), true);
}

/**
 * Normalize a user/filesystem relative path and reject traversal segments.
 */
function normalize_relative_path(string $path): string
{
    // Variable $path stores this steps working value.
    $path = str_replace('\\', '/', $path);
    // Variable $segments stores this steps working value.
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            throw new RuntimeException('Invalid relative path.');
        }
        $segments[] = $segment;
    }
    return implode('/', $segments);
}

/**
 * Verify that a resolved path stays inside a resolved root directory.
 */
function path_inside(string $root, string $path): bool
{
    // Variable $rootReal stores this steps working value.
    $rootReal = realpath($root);
    // Variable $pathReal stores this steps working value.
    $pathReal = realpath($path);
    if ($rootReal === false || $pathReal === false) {
        return false;
    }
    return str_starts_with($pathReal, rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) || $pathReal === $rootReal;
}

/**
 * Build a short cache key for the generated theme stylesheet URL.
 */
function theme_cache_key(array $theme): string
{
    // $assetRevision changes when the generated-theme controller changes, so immutable browser caches do not keep stale generated CSS.
    $assetRevision = is_file(__DIR__ . '/controllers/theme_assets.php') ? (string) filemtime(__DIR__ . '/controllers/theme_assets.php') : '0';
    // $cachePayload combines user settings with the implementation revision that affects the emitted CSS rules.
    $cachePayload = ['theme' => $theme, 'asset_revision' => $assetRevision];
    return substr(hash('sha256', json_encode($cachePayload, JSON_UNESCAPED_SLASHES)), 0, 12);
}

/**
 * Escape a CSS custom property value used by the generated theme stylesheet.
 */
function css_value(string $value): string
{
    return str_replace(['\\', ';', '{', '}'], '', $value);
}