<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/helpers_runtime.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Provides runtime redirects, flash/request utilities, timestamps, slugging, and unique slug generation.
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
 * Resolve public asset paths for either repository-root or public/ web roots.
 */
function asset_url(string $path): string
{
    // Variable $path stores this steps working value.
    $path = ltrim($path, '/');
    // Variable $script stores this steps working value.
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (str_ends_with($script, '/public/index.php')) {
        return base_url('public/' . $path);
    }
    // Variable $scriptFile stores this steps working value.
    $scriptFile = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if (str_ends_with($scriptFile, '/public/index.php')) {
        return base_url($path);
    }
    return base_url('public/' . $path);
}

/**
 * Send a 302 redirect and stop processing immediately.
 */
function redirect_to(string $url): never
{
    header('Location: ' . $url, true, 302);
    exit;
}

/**
 * Store or retrieve a one-time flash message in the active session.
 */
function flash_message(string $key, ?string $message = null): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return $message;
    }

    if ($message !== null) {
        $_SESSION['flash_messages'][$key] = $message;
        return null;
    }

    if (!isset($_SESSION['flash_messages'][$key])) {
        return null;
    }

    // $value stores an intermediate value used by the surrounding gallery workflow.
    $value = (string) $_SESSION['flash_messages'][$key];
    unset($_SESSION['flash_messages'][$key]);
    return $value;
}

/**
 * Normalize the current HTTP method for simple route guards.
 */
function request_method(): string
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

/**
 * Return the current timestamp in the format used by MySQL DATETIME columns.
 */
function now_sql(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * Convert human-entered titles/tag names into URL-safe slugs.
 *
 * The normalization is deliberately deterministic across shared-hosting PHP
 * installations. Czech and common Central European characters are mapped
 * explicitly before the optional intl/iconv fallbacks, decomposed combining
 * marks are removed, and invisible formatting characters cannot split words.
 */
function slugify(string $text): string
{
    // Decode values copied from HTML sources before transliteration.
    $text = html_entity_decode(trim($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Remove soft hyphens, zero-width characters, and byte-order marks.
    $text = (string) preg_replace('/[\x{00AD}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $text);

    if (class_exists('Normalizer')) {
        // NFKD exposes combining accents so they can be removed consistently.
        $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KD);
        if (is_string($normalized)) {
            $text = $normalized;
        }
    }

    // Keep Czech and neighboring-language transliteration stable even when the
    // server locale or iconv implementation differs from the development host.
    $text = strtr($text, [
        'Á' => 'A', 'Ä' => 'A', 'Č' => 'C', 'Ć' => 'C', 'Ď' => 'D', 'É' => 'E', 'Ě' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ĺ' => 'L', 'Ľ' => 'L', 'Ň' => 'N', 'Ń' => 'N', 'Ó' => 'O', 'Ö' => 'O', 'Ô' => 'O',
        'Ř' => 'R', 'Ŕ' => 'R', 'Š' => 'S', 'Ś' => 'S', 'Ť' => 'T', 'Ú' => 'U', 'Ů' => 'U', 'Ü' => 'U',
        'Ý' => 'Y', 'Ž' => 'Z', 'Ź' => 'Z', 'Ż' => 'Z', 'Æ' => 'AE', 'Œ' => 'OE', 'Ø' => 'O', 'Ł' => 'L',
        'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ć' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'ë' => 'e',
        'í' => 'i', 'ĺ' => 'l', 'ľ' => 'l', 'ň' => 'n', 'ń' => 'n', 'ó' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ř' => 'r', 'ŕ' => 'r', 'š' => 's', 'ś' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z', 'æ' => 'ae', 'œ' => 'oe', 'ø' => 'o', 'ł' => 'l',
        'ß' => 'ss',
    ]);
    // Remove accents left as decomposed Unicode combining marks.
    $text = (string) preg_replace('/\p{M}+/u', '', $text);

    // iconv remains useful for characters outside the explicit map. Failure is
    // tolerated because the ASCII filter below still produces a safe result.
    $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) : false;
    $source = $ascii === false ? $text : $ascii;
    $source = function_exists('mb_strtolower') ? mb_strtolower($source, 'UTF-8') : strtolower($source);
    $slug = (string) preg_replace('/[^a-z0-9]+/i', '-', $source);
    $slug = trim(strtolower($slug), '-');
    return $slug !== '' ? $slug : 'gallery';
}

/**
 * Generate a unique gallery slug, optionally excluding an existing gallery ID.
 */
function unique_slug(PDO $pdo, string $title, ?int $excludeGalleryId = null): string
{
    // Variable $base stores this steps working value.
    $base = slugify($title);
    // Variable $slug stores this steps working value.
    $slug = $base;
    // Variable $counter stores this steps working value.
    $counter = 2;
    while (true) {
        // Variable $sql stores this steps working value.
        $sql = 'SELECT id FROM galleries WHERE slug = ?';
        // Variable $params stores this steps working value.
        $params = [$slug];
        if ($excludeGalleryId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeGalleryId;
        }
        // Variable $stmt stores this steps working value.
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if (!$stmt->fetch()) {
            return $slug;
        }
        // Variable $slug stores this steps working value.
        $slug = $base . '-' . $counter;
        $counter++;
    }
}

