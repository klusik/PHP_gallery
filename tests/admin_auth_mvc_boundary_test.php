<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_auth_mvc_boundary_test.php
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
 * Protect the strict MVC presentation boundary for administrator authentication.
 */

declare(strict_types=1);

/**
 * Assert one administrator-authentication MVC boundary.
 *
 * @param bool $condition Assertion result.
 * @param string $label Failure label.
 */
function admin_auth_mvc_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$root = dirname(__DIR__);
$controllerSource = (string) file_get_contents($root . '/app/controllers/admin_auth.php');
$viewSource = (string) file_get_contents($root . '/app/views/admin_auth.php');
$viewsLoader = (string) file_get_contents($root . '/app/views.php');

admin_auth_mvc_assert(str_contains($viewsLoader, "'/views/admin_auth.php'"), 'View loader must register the administrator authentication view.');
foreach ([
    'view_render_admin_login',
    'view_render_admin_forgot_password',
    'view_render_admin_reset_password',
    'view_render_admin_account',
    'view_render_admin_stable_reset',
] as $renderer) {
    admin_auth_mvc_assert(str_contains($controllerSource, '\\Gallery\\Views\\' . $renderer . '('), 'Admin auth controller must delegate to ' . $renderer . '.');
    admin_auth_mvc_assert(str_contains($viewSource, 'function ' . $renderer . '('), 'Admin auth view must implement ' . $renderer . '.');
}

admin_auth_mvc_assert(!preg_match('/\becho\s+[\'\"][^\n]*</', $controllerSource), 'Admin auth controller must not own HTML output.');
admin_auth_mvc_assert(!str_contains($controllerSource, 'render_header(') && !str_contains($controllerSource, 'render_footer('), 'Admin auth controller must not render the page chrome directly.');
admin_auth_mvc_assert(str_contains($controllerSource, "'central_settings_url' => admin_settings_url('advanced')"), 'Account controller must prepare the centralized Settings URL.');
admin_auth_mvc_assert(str_contains($controllerSource, "'csrf_html' => csrf_field()"), 'Admin auth controller must prepare CSRF presentation fields.');
admin_auth_mvc_assert(str_contains($controllerSource, "'google_status' => \$googleStatus") && str_contains($controllerSource, "'openai_models' => \$openaiModels"), 'Account controller must prepare integration presentation state before rendering.');

foreach (['$_GET', '$_POST', '$_REQUEST', '$_FILES', '$_SESSION', '$_COOKIE', '$_SERVER'] as $requestGlobal) {
    admin_auth_mvc_assert(!str_contains($viewSource, $requestGlobal), 'Admin auth view must not inspect request/session globals: ' . $requestGlobal);
}
admin_auth_mvc_assert(!str_contains($viewSource, 'db()->') && !str_contains($viewSource, '->prepare(') && !str_contains($viewSource, '->query(') && !str_contains($viewSource, '->exec('), 'Admin auth view must not access persistence.');
admin_auth_mvc_assert(substr_count($viewSource, 'Gallery\\Services\\') === 1 && str_contains($viewSource, 'use function Gallery\\Services\\t;'), 'Admin auth view may depend on the translation helper only from the service namespace.');
admin_auth_mvc_assert(!str_contains($viewSource, 'url_for(') && !str_contains($viewSource, 'csrf_field(') && !str_contains($viewSource, 'site_name('), 'Admin auth view must consume controller-prepared URLs, CSRF HTML, and site identity.');

fwrite(STDOUT, "Admin auth MVC boundary checks passed.\n");
