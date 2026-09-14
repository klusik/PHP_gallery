<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_media_renamer_mvc_boundary_test.php
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */
/**
 * Protect the strict MVC presentation boundary for the Admin Media Renamer.
 */

declare(strict_types=1);

/** Assert one Admin Media Renamer MVC boundary. */
function admin_media_renamer_mvc_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/app/controllers/admin_media_renamer.php');
$view = (string) file_get_contents($root . '/app/views/admin_media_renamer.php');
$viewsLoader = (string) file_get_contents($root . '/app/views.php');

admin_media_renamer_mvc_assert(str_contains($viewsLoader, "'/views/admin_media_renamer.php'"), 'View loader must register the Media Renamer view.');
admin_media_renamer_mvc_assert(!preg_match('/\becho\s+[\'\"][^\n]*</', $controller), 'Media Renamer controller must not own HTML output.');
admin_media_renamer_mvc_assert(str_contains($controller, 'echo json_encode('), 'Media Renamer controller must retain explicit JSON response ownership.');

foreach ([
    'view_render_admin_media_renamer_page',
    'view_render_admin_media_renamer_site_workspace',
    'view_render_admin_media_renamer_gallery_panel',
    'view_render_admin_media_renamer_fragment',
    'view_render_admin_media_renamer_pattern_preview_form',
    'view_render_admin_media_renamer_scope_form',
    'view_render_admin_media_renamer_apply_form',
    'view_render_admin_media_renamer_plan_table',
    'view_render_admin_media_renamer_execution_details',
] as $renderer) {
    admin_media_renamer_mvc_assert(str_contains($view, 'function ' . $renderer . '('), 'Media Renamer view must implement ' . $renderer . '.');
}

foreach ([
    'view_render_admin_media_renamer_page',
    'view_render_admin_media_renamer_site_workspace',
    'view_render_admin_media_renamer_gallery_panel',
    'view_render_admin_media_renamer_fragment',
    'view_render_admin_media_renamer_pattern_preview_form',
    'view_render_admin_media_renamer_scope_form',
    'view_render_admin_media_renamer_apply_form',
    'view_render_admin_media_renamer_plan_table',
    'view_render_admin_media_renamer_execution_details',
] as $renderer) {
    admin_media_renamer_mvc_assert(str_contains($controller, '\\Gallery\\Views\\' . $renderer . '('), 'Media Renamer controller must delegate to ' . $renderer . '.');
}

foreach (['$_GET', '$_POST', '$_REQUEST', '$_FILES', '$_SESSION', '$_COOKIE', '$_SERVER'] as $requestGlobal) {
    admin_media_renamer_mvc_assert(!str_contains($view, $requestGlobal), 'Media Renamer view must not inspect request/session globals: ' . $requestGlobal);
}
admin_media_renamer_mvc_assert(!str_contains($view, 'db()->') && !str_contains($view, '->prepare(') && !str_contains($view, '->query(') && !str_contains($view, '->exec('), 'Media Renamer view must not access persistence.');
admin_media_renamer_mvc_assert(!str_contains($view, 'url_for(') && !str_contains($view, 'csrf_field(') && !str_contains($view, 'csrf_token('), 'Media Renamer view must consume controller-prepared URLs and CSRF state.');
admin_media_renamer_mvc_assert(substr_count($view, 'Gallery\\Services\\') === 1 && str_contains($view, 'use function Gallery\\Services\\t;'), 'Media Renamer view may depend only on the translation helper from the service namespace.');
admin_media_renamer_mvc_assert(!str_contains($view, 'media_renamer_plan_for_gallery(') && !str_contains($view, 'media_renamer_plans_for_galleries(') && !str_contains($view, 'media_renamer_execute_galleries(') && !str_contains($view, 'media_renamer_default_pattern('), 'Media Renamer view must not invoke rename-domain services.');

admin_media_renamer_mvc_assert(str_contains($controller, "'csrf_html' => csrf_field()"), 'Controller must prepare CSRF fragments before rendering forms.');
admin_media_renamer_mvc_assert(str_contains($controller, "'default_pattern' => media_renamer_default_pattern()"), 'Controller must prepare the default filename pattern.');
admin_media_renamer_mvc_assert(str_contains($controller, "'pattern_help' => media_renamer_pattern_help_text()"), 'Controller must prepare wildcard help text.');
admin_media_renamer_mvc_assert(str_contains($controller, "['status_label'] = admin_media_renamer_status_label"), 'Controller must prepare plan-row status labels.');
admin_media_renamer_mvc_assert(str_contains($controller, 'function admin_media_renamer_render_gallery_panel_html('), 'Gallery Editor compatibility renderer must remain available.');

fwrite(STDOUT, "Admin Media Renamer MVC boundary checks passed.\n");
