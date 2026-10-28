<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/fixtures/admin_navigation_view.php
 * Module Type: Browser Render Fixture
 * Purpose: Render the production Admin sidebar and stylesheet cascade in isolation.
 * Responsibilities: Exercise navigation without live credentials or database writes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Escape fixture labels using the production HTML convention.
     * @param string $value Prepared text or attribute value.
     * @return string Escaped HTML text.
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Keep fixture destinations on the same isolated page.
     * @param string $page Prepared Admin route identifier.
     * @param array<string,string> $query Optional route query parameters.
     * @return string Fixture URL retaining production query and hash behavior.
     */
    function url_for(string $page, array $query = []): string
    {
        return '?page=' . $page
            . ($query === [] ? '' : '&' . http_build_query($query));
    }
}

namespace Gallery\Services {
    /**
     * Return deterministic English labels without installation language state.
     * @param string $key Translation identity, unused by this English fixture.
     * @param string $fallback English display text.
     * @return string English display text.
     */
    function t(string $key, string $fallback = ''): string
    {
        return $fallback;
    }
}

namespace {
    require_once __DIR__ . '/../../app/views/admin_ui.php';
    require_once __DIR__ . '/../../app/views/admin_chrome.php';
    require_once __DIR__ . '/../../app/controllers/shared_layout.php';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Admin navigation preview</title>';
    foreach (
        \Gallery\Controllers\shared_layout_admin_stylesheet_files() as $stylesheet
    ) {
        echo '<link rel="stylesheet" href="/public/'
            . \Gallery\Core\e($stylesheet) . '">';
    }
    echo '</head><body class="admin-page">'
        . '<header class="site-header"><a class="brand" href="#overview">'
        . 'Gallery</a><nav class="nav"><a href="#overview">Open galleries</a>'
        . '</nav></header><div class="admin-shell">';
    \Gallery\Views\view_render_admin_sidebar('admin', [
        'feature_enabled' => [
            'smart_galleries' => true,
            'gallery_music' => true,
            'media_renamer' => true,
            'upload_api' => true,
            'telemetry' => true,
            'complete_gallery_report' => true,
            'navigation_data' => true,
            'viewer_accounts' => true,
        ],
    ]);
    echo '<main class="site-main admin-content" id="overview">'
        . '<section class="panel"><h1>Overview</h1>'
        . '<p>Manage galleries, appearance and maintenance.</p>'
        . '<button type="button" id="content-action">Content action</button>'
        . '</section></main></div><pre id="results" hidden></pre>'
        . '<script type="module">'
        . 'import {setupAdminNavigation} from '
        . '"/public/assets/gallery-modules/admin-navigation.js";'
        . 'setupAdminNavigation();</script></body></html>';
}
