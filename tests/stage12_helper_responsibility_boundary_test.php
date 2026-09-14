<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/stage12_helper_responsibility_boundary_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects the Stage 12 rule that generic Core helpers do not become a
 *   presentation bypass around the MVC layers.
 *
 * Responsibilities:
 *   - Reject direct echo/print presentation output from app/helpers*.php
 *   - Require shared page/admin/SEO helper adapters to delegate to Views
 *   - Keep 404/back-to-top markup in the HTTP view module
 *   - Keep sitemap HTTP transport in the controller and XML markup in Views
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
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$violations = [];
foreach (glob($root . '/app/helpers*.php') ?: [] as $path) {
    $source = file_get_contents($path);
    if ($source === false) {
        $violations[] = 'Unable to read helper module: ' . basename($path);
        continue;
    }
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_ECHO, T_PRINT], true)) {
            $violations[] = basename($path) . ':' . $token[2] . ' emits presentation output directly';
        }
    }
}

$adminRendering = (string) file_get_contents($root . '/app/helpers_admin_rendering.php');
foreach ([
    'view_render_admin_tabs',
    'view_render_admin_tab_panel',
    'view_render_admin_subtabs',
    'view_render_admin_subtab_panel',
    'view_render_admin_sidebar',
    'view_render_missing_admin_email_notice',
] as $viewFunction) {
    if (!str_contains($adminRendering, $viewFunction)) {
        $violations[] = 'Admin rendering adapter does not delegate to ' . $viewFunction;
    }
}

$pageRendering = (string) file_get_contents($root . '/app/helpers_page_rendering.php');
foreach (['view_render_header', 'view_render_browser_i18n_script', 'view_render_footer'] as $viewFunction) {
    if (!str_contains($pageRendering, $viewFunction)) {
        $violations[] = 'Page rendering adapter does not delegate to ' . $viewFunction;
    }
}

$httpHelpers = (string) file_get_contents($root . '/app/controllers/http_helpers.php');
$httpView = (string) file_get_contents($root . '/app/views/http.php');
if (!str_contains($httpHelpers, 'view_render_back_to_top_button(') || !str_contains($httpView, 'function view_render_back_to_top_button(')) {
    $violations[] = 'Back-to-top rendering is not owned by app/views/http.php';
}
if (!str_contains($httpHelpers, 'view_render_not_found(') || !str_contains($httpView, 'function view_render_not_found(')) {
    $violations[] = '404 rendering is not owned by app/views/http.php';
}

$publicMedia = (string) file_get_contents($root . '/app/controllers/public_media.php');
$seoView = (string) file_get_contents($root . '/app/views/seo.php');
if (!str_contains($publicMedia, "header('Content-Type: application/xml; charset=utf-8')")
    || !str_contains($publicMedia, 'view_render_sitemap_xml(public_sitemap_entries())')) {
    $violations[] = 'Sitemap controller does not own XML transport and explicit view dispatch';
}
if (!str_contains($seoView, 'function view_render_sitemap_xml(')) {
    $violations[] = 'Sitemap XML renderer is unavailable in app/views/seo.php';
}

$sharedLayoutController = (string) file_get_contents($root . '/app/controllers/shared_layout.php');
$layoutView = (string) file_get_contents($root . '/app/views/layout.php');
$seoRequestGuard = (string) file_get_contents($root . '/app/services/seo_request_guard.php');
if (!str_contains($sharedLayoutController, 'seo_request_guard_public_canonical_url(')
    || !str_contains($sharedLayoutController, "'canonical_url' =>")) {
    $violations[] = 'Shared layout controller does not prepare the public canonical URL for the View';
}
if (!str_contains($layoutView, '$canonicalUrl = trim((string) ($model[\'canonical_url\'] ?? \'\'));')
    || !str_contains($layoutView, 'e($canonicalUrl)')) {
    $violations[] = 'Shared layout View does not own escaped canonical-link rendering';
}
if (str_contains($seoRequestGuard, 'function seo_request_guard_canonical_head_html(')
    || str_contains($seoRequestGuard, '<link rel=\"canonical\"')) {
    $violations[] = 'SEO request guard Service must return canonical policy data, not canonical-link HTML';
}

if ($violations !== []) {
    throw new RuntimeException("Stage 12 helper responsibility violations:\n  - " . implode("\n  - ", $violations));
}

fwrite(STDOUT, "Stage 12 helper responsibility boundary checks passed.\n");
