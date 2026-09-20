<?php

/**
 * Project: PHP Gallery
 * Module Type: Regression Test
 * Purpose: Protect public gallery presentation ownership.
 * Responsibilities:
 *   - Keep controls, cards and root-index markup behind prepared view data.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/public_gallery_mvc_boundary_test.php
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
 * Protect the strict MVC presentation boundary for public gallery controls,
 * cards, and the root gallery index.
 */

declare(strict_types=1);

/**
 * Assert one public-gallery MVC boundary.
 *
 * @param bool $condition Assertion result.
 * @param string $label Failure label.
 */
function public_gallery_mvc_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$root = dirname(__DIR__);
$controlsController = (string) file_get_contents($root . '/app/controllers/public_gallery_controls.php');
$cardsController = (string) file_get_contents($root . '/app/controllers/public_gallery_cards.php');
$homeController = (string) file_get_contents($root . '/app/controllers/public_gallery_home.php');
$pageController = (string) file_get_contents($root . '/app/controllers/public_gallery_page.php');
$controlsView = (string) file_get_contents($root . '/app/views/public_gallery_controls.php');
$cardsView = (string) file_get_contents($root . '/app/views/public_gallery_cards.php');
$pagesView = (string) file_get_contents($root . '/app/views/public_gallery_pages.php');
$viewsLoader = (string) file_get_contents($root . '/app/views.php');

foreach (['public_gallery_controls.php', 'public_gallery_cards.php', 'public_gallery_pages.php'] as $viewFile) {
    public_gallery_mvc_assert(str_contains($viewsLoader, "'/views/" . $viewFile . "'"), 'View loader must register ' . $viewFile . '.');
}

foreach ([$controlsController, $cardsController, $homeController, $pageController] as $controllerSource) {
    public_gallery_mvc_assert(!preg_match('/\becho\s+[\'\"][^\n]*</', $controllerSource), 'Migrated public gallery controllers must not own HTML output.');
}

foreach ([
    'view_render_public_subgallery_date_sort_toolbar',
    'view_render_public_page_reorder_toolbar',
    'view_render_picture_manager_toolbar',
    'view_render_public_gallery_preview_toolbar',
    'view_render_public_gallery_branding_header',
    'view_render_public_gallery_branding_separator',
    'view_render_public_gallery_breadcrumbs',
    'view_render_gallery_access_gate',
] as $renderer) {
    public_gallery_mvc_assert(str_contains($controlsController, '\\Gallery\\Views\\' . $renderer . '('), 'Public gallery controls controller must delegate to ' . $renderer . '.');
    public_gallery_mvc_assert(str_contains($controlsView, 'function ' . $renderer . '('), 'Public gallery controls view must implement ' . $renderer . '.');
}

foreach ([
    'view_render_public_gallery_card',
    'view_render_public_smart_gallery_card',
    'view_render_public_gallery_admin_add_child_link',
    'view_render_public_gallery_admin_edit_link',
    'view_render_public_gallery_admin_delete_form',
    'view_render_public_image_admin_edit_link',
    'view_render_public_image_admin_delete_form',
    'view_render_public_admin_visibility_menu',
] as $renderer) {
    public_gallery_mvc_assert(str_contains($cardsController, '\\Gallery\\Views\\' . $renderer . '('), 'Public gallery cards controller must delegate to ' . $renderer . '.');
    public_gallery_mvc_assert(str_contains($cardsView, 'function ' . $renderer . '('), 'Public gallery cards view must implement ' . $renderer . '.');
}

public_gallery_mvc_assert(str_contains($homeController, '\\Gallery\\Views\\view_render_public_gallery_home(['), 'Public gallery home controller must delegate to its page view.');
public_gallery_mvc_assert(str_contains($pagesView, 'function view_render_public_gallery_home('), 'Public gallery pages view must render the home page.');
public_gallery_mvc_assert(str_contains($pageController, '\\Gallery\\Views\\view_render_public_gallery_detail(['), 'Selected-gallery controller must delegate to its page view.');
foreach (['view_render_public_smart_gallery_attachment_group', 'view_render_public_gallery_hero', 'view_render_public_subgallery_section', 'view_render_public_gallery_nsfw_image_card', 'view_render_public_gallery_image_card', 'view_render_public_gallery_image_section', 'view_render_public_gallery_detail'] as $renderer) {
    public_gallery_mvc_assert(str_contains($pagesView, 'function ' . $renderer . '('), 'Public gallery pages view must implement ' . $renderer . '.');
}

foreach ([$controlsView, $cardsView, $pagesView] as $viewSource) {
    foreach (['$_GET', '$_POST', '$_REQUEST', '$_FILES', '$_SESSION', '$_COOKIE', '$_SERVER'] as $requestGlobal) {
        public_gallery_mvc_assert(!str_contains($viewSource, $requestGlobal), 'Public gallery views must not inspect request/session globals: ' . $requestGlobal);
    }
    public_gallery_mvc_assert(!str_contains($viewSource, 'db()->') && !str_contains($viewSource, '->prepare(') && !str_contains($viewSource, '->query(') && !str_contains($viewSource, '->exec('), 'Public gallery views must not access persistence.');
    public_gallery_mvc_assert(!str_contains($viewSource, 'url_for(') && !str_contains($viewSource, 'csrf_field(') && !str_contains($viewSource, 'csrf_token('), 'Public gallery views must consume controller-prepared URLs and CSRF state.');
}

foreach ([$controlsView, $cardsView, $pagesView] as $translatedViewSource) {
    public_gallery_mvc_assert(substr_count($translatedViewSource, 'Gallery\\Services\\') === 1 && str_contains($translatedViewSource, 'use function Gallery\\Services\\t;'), 'Translated public gallery views may depend only on the translation helper from the service namespace.');
}

public_gallery_mvc_assert(str_contains($controlsController, "'destination_picker_html' => \$destinationPickerHtml"), 'Picture manager controller must prepare the reused gallery-picker fragment.');
public_gallery_mvc_assert(str_contains($cardsController, "'admin_controls_html' => \$adminControlsHtml"), 'Gallery card controller must prepare nested Admin controls before rendering.');
public_gallery_mvc_assert(str_contains($homeController, "'cards_html' => \$cardsHtml") && str_contains($homeController, "'search_bar' => public_search_bar_view_model()"), 'Home controller must prepare card/search presentation state before rendering.');

fwrite(STDOUT, "Public gallery MVC boundary checks passed.\n");
