<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_theme.php
 * Module Type: Controller
 *
 * Purpose:
 *   Loads focused Theme controller modules and coordinates the Theme request lifecycle.
 *
 * Responsibilities:
 *   - Validate and route incoming request data
 *   - Call service-layer functions where possible
 *   - Return redirects, rendered views, or HTTP responses
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

namespace Gallery\Controllers;


use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Services\feature_flag_enabled;

require_once __DIR__ . '/admin_theme_actions.php';
require_once __DIR__ . '/admin_theme_appearance.php';
require_once __DIR__ . '/admin_theme_media.php';
require_once __DIR__ . '/admin_theme_layout.php';
require_once __DIR__ . '/admin_theme_language.php';
require_once __DIR__ . '/admin_theme_custom_css.php';
require_once __DIR__ . '/admin_theme_page.php';

/**
 * Render and process visual theme settings.
 */
function cms_admin_theme(): void
{
    require_admin();
    // $gpsMapsFeatureEnabled stores whether GPS-related theme controls should be visible and saved.
    $gpsMapsFeatureEnabled = !function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('gallery_maps');
    // $lightboxModesFeatureEnabled stores whether lightbox browsing-mode theme controls should be visible and saved.
    $lightboxModesFeatureEnabled = !function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('lightbox_modes');

    if (isset($_GET['download_language_pack'])) {
        admin_theme_download_language_pack();
        return;
    }

    if (request_method() === 'POST') {
        admin_theme_process_post($gpsMapsFeatureEnabled, $lightboxModesFeatureEnabled);
        return;
    }

    render_admin_theme_page($gpsMapsFeatureEnabled, $lightboxModesFeatureEnabled);
}