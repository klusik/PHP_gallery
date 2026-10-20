<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/public_theme_width_composition_fixture.php
 * Module Type: Browser Test Fixture
 * Purpose: Render production public Home and Gallery compositions without installation state.
 * Responsibilities:
 *   - Use production page views, shared shell, widget planning, pagination and gallery-card renderers.
 *   - Supply only deterministic controller-prepared view models and disposable Theme width values.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Return a stable fixture asset dependency revision for the production footer view.
     * @param list<string> $paths Production public script and dependency paths selected by the footer.
     * @return string Stable nonempty revision for the disposable document.
     */
    function asset_dependency_revision(array $paths): string
    {
        return hash('sha256', implode("\n", $paths));
    }

    /** Return a deterministic version label for the production public footer.
     * @return string Fixture-only application version label.
     */
    function cms_current_version(): string
    {
        return '0.0.0-fixture';
    }

    /** Suppress transient footer scripts in the isolated rendering fixture.
     * @return string No installation-provided footer snippets.
     */
    function cms_footer_scripts_html(): string
    {
        return '';
    }

    /** Render the actual shared production header with disposable request and Theme state.
     * @param string $title Prepared public page title.
     * @param array{id:int,title:string,...}|null $currentGallery Full prepared Gallery row forwarded by the production View; intentionally unused because this shared-shell fixture renders only the supplied title and route.
     * @param bool $publicOnly Whether the Gallery view selected public-only presentation; intentionally unused because this fixture renders both routes as public pages.
     * @return void Emit the real shared layout header around the production page view.
     */
    function render_header(
        string $title,
        ?array $currentGallery = null,
        bool $publicOnly = true,
    ): void {
        $page = (string) (
            $GLOBALS['public_theme_width_fixture_page'] ?? 'home'
        );
        $mode = \Gallery\Services\theme_page_width_mode(
            (string) ($GLOBALS['public_theme_width_fixture_mode'] ?? 'default'),
        );
        $stylesheetUrls = array_map(
            static fn(string $stylesheet): string => asset_url($stylesheet),
            \Gallery\Controllers\shared_layout_public_stylesheet_files(),
        );
        $themeValues = [
            'theme_page_width' => $mode,
            'theme_page_width_custom' => '1320',
        ];
        $headExtras =
            '<style id="fixture-generated-theme-css">'
            . \theme_fixture_css($themeValues)
            . '</style>'
            . '<link rel="stylesheet" href="'
            . e(asset_url('assets/styles/mobile-gallery.css'))
            . '">';

        \Gallery\Views\view_render_header(
            $title,
            [
                'site_name' => 'PHP Gallery layout fixture',
                'body_class' => 'public-page',
                'page_width_class' => ' page-width-' . $mode,
                'active_language' => 'en',
                'stylesheet_urls' => $stylesheetUrls,
                'head_extras' => $headExtras,
            ],
            '/index.php?page=' . rawurlencode($page),
            $page,
        );
    }

    /** Render the actual shared production footer with prepared public widget markup.
     * @param string $publicWidgetFooterHtml Safe fixture widget footer fragment produced by the production widget view.
     * @return void Emit the real shared footer and its public browser entrypoint.
     */
    function render_footer(string $publicWidgetFooterHtml = ''): void
    {
        $page = (string) (
            $GLOBALS['public_theme_width_fixture_page'] ?? 'home'
        );
        \Gallery\Views\view_render_footer($page, [
            'github_project_url' => '',
            'public_widget_footer_html' => $publicWidgetFooterHtml,
        ]);
    }
}

namespace {
    require_once __DIR__ . '/theme_appearance_fixture.php';
    require_once __DIR__ . '/theme_css_fixture.php';
    require_once dirname(__DIR__, 2) . '/app/views/admin_test_run_panel.php';
    require_once
        dirname(__DIR__, 2) . '/app/services/public_content_widgets.php';
    require_once dirname(__DIR__, 2) . '/app/views/layout.php';
    require_once dirname(__DIR__, 2) . '/app/views/http.php';
    require_once dirname(__DIR__, 2) . '/app/views/gallery_descriptions.php';
    require_once dirname(__DIR__, 2) . '/app/views/public_search.php';
    require_once dirname(__DIR__, 2) . '/app/views/pagination.php';
    require_once dirname(__DIR__, 2) . '/app/views/public_tags.php';
    require_once dirname(__DIR__, 2) . '/app/views/public_content_widgets.php';
    require_once dirname(__DIR__, 2) . '/app/views/public_gallery_cards.php';
    require_once dirname(__DIR__, 2) . '/app/views/public_gallery_pages.php';

    use function Gallery\Services\public_widget_normalize;
    use function Gallery\Services\public_widget_plan_rows;
    use function Gallery\Views\view_render_pagination_controls;
    use function Gallery\Views\view_render_back_to_top_button;
    use function Gallery\Views\view_render_public_gallery_card;
    use function Gallery\Views\view_render_public_gallery_detail;
    use function Gallery\Views\view_render_public_gallery_home;
    use function Gallery\Views\view_render_public_gallery_image_card;

    /** Plan published widgets for one deterministic public page using the production policy service.
     * @param string $page Authorized public page family represented by this fixture: home or gallery.
     * @return array<string,list<array{widget_id:string,title:string,body_html:string,appearance:string,source_language:string,placement_mode:string,floating_anchor:string,x_permille:int,y_permille:int,width_px:int,sort_order:int}>> Production widget zones for the requested page.
     */
    function public_theme_width_fixture_widgets(string $page): array
    {
        $slots = $page === 'home'
            ? [
                'left_rail',
                'right_rail',
                'home_before_grid',
                'home_after_grid',
                'footer',
            ]
            : [
                'left_rail',
                'right_rail',
                'content_top',
                'content_bottom',
                'footer',
            ];
        $rows = [];
        foreach ($slots as $index => $slot) {
            $number = $index + 1;
            $title = ucfirst(str_replace('_', ' ', $slot)) . ' fixture content';
            $rows[] = [
                'widget_id' => sprintf('%032x', $number),
                ...public_widget_normalize([
                    'title' => $title,
                    'content_md' =>
                        'Production widget output with a long unbroken token '
                            . str_repeat('overflowcheck', 9)
                            . ' and readable fixture text.',
                    'status' => 'published',
                    'page_scope' => 'all',
                    'placement_mode' => 'flow',
                    'flow_slot' => $slot,
                    'width_px' => 480,
                    'sort_order' => $number * 10,
                ]),
            ];
        }
        if ($page === 'home') {
            $rows[] = [
                'widget_id' => sprintf('%032x', count($rows) + 1),
                ...public_widget_normalize([
                    'title' => 'Top-left floating fixture',
                    'content_md' => 'A bounded panel used to test Home search clearance.',
                    'status' => 'published',
                    'page_scope' => 'home',
                    'placement_mode' => 'floating',
                    'flow_slot' => 'home_after_grid',
                    'floating_anchor' => 'top-left',
                    'x_permille' => 0,
                    'y_permille' => 0,
                    'width_px' => 320,
                    'sort_order' => 60,
                ]),
            ];
        }

        return public_widget_plan_rows($rows, $page);
    }

    /** Render pagination through the production View for one multi-page listing.
     * @param string $label Accessible label for the represented listing.
     * @param string $routeBase Fixture-owned route prefix for this listing's page numbers.
     * @param int $currentPage Current one-based page in the three-page fixture; defaults to page two.
     * @return string Actual public pagination controls with route-backed page links.
     */
    function public_theme_width_fixture_pagination(
        string $label,
        string $routeBase,
        int $currentPage = 2,
    ): string {
        $pageUrls = [];
        for ($pageNumber = 1; $pageNumber <= 3; $pageNumber++) {
            $pageUrls[] = [
                'page' => $pageNumber,
                'url' => rtrim($routeBase, '/') . '/' . $pageNumber,
                'current' => $pageNumber === $currentPage,
            ];
        }
        ob_start();
        view_render_pagination_controls([
            'pagination_needed' => true,
            'previous_url' => $currentPage > 1
                ? rtrim($routeBase, '/') . '/' . ($currentPage - 1)
                : '',
            'page_urls' => $pageUrls,
            'next_url' => $currentPage < 3
                ? rtrim($routeBase, '/') . '/' . ($currentPage + 1)
                : '',
            'current_page' => $currentPage,
            'total_pages' => 3,
        ], $label);
        return (string) ob_get_clean();
    }

    /** Render several real public gallery cards with authorized disposable data-image covers.
     * @param string $prefix Stable page-specific card title prefix.
     * @param int $firstId First positive gallery identity in the sample.
     * @param int $count Number of production cards to render.
     * @return string HTML emitted by the production public gallery-card View.
     */
    function public_theme_width_fixture_gallery_cards(
        string $prefix,
        int $firstId,
        int $count,
    ): string {
        $image = 'data:image/svg+xml,'
        . rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="360"><rect width="640" height="360" fill="#8aa4b8"/></svg>',
        );
        ob_start();
        for ($offset = 0; $offset < $count; $offset++) {
            $galleryId = $firstId + $offset;
            view_render_public_gallery_card([
                'gallery_id' => $galleryId,
                'title' => $prefix . ' gallery ' . $galleryId,
                'url' => '/fixture/gallery/' . $galleryId,
                'visibility' => 'public',
                'description_layout' => 'horizontal',
                'description' => 'A prepared public gallery card in the rendered production grid.',
                'cover_asset' => $image,
                'cover_loading_attributes' => 'loading="lazy"',
                'tag_list_html' => '<div class="tag-list"><span class="tag">Fixture</span></div>',
            ]);
        }
        return (string) ob_get_clean();
    }

    /** Render several actual gallery photo cards with data-image thumbnails and public metadata.
     * @param int $firstId First positive photo identity in the sample.
     * @param int $count Number of production photo cards to render.
     * @return string HTML emitted by the production public photo-card View.
     */
    function public_theme_width_fixture_photo_cards(
        int $firstId,
        int $count,
    ): string {
        $image = 'data:image/svg+xml,'
        . rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="480"><rect width="640" height="480" fill="#b9a27e"/></svg>',
        );
        $thumbnailHtml =
            '<img src="'
            . \Gallery\Core\e($image)
            . '" width="640" height="480" alt="Disposable public gallery photograph">';
        ob_start();
        for ($offset = 0; $offset < $count; $offset++) {
            $imageId = $firstId + $offset;
            view_render_public_gallery_image_card([
                'image_id' => $imageId,
                'class' => 'image-card',
                'visibility' => 'public',
                'image_url' => '/fixture/photo/' . $imageId,
                'thumbnail_html' => $thumbnailHtml,
                'has_public_meta' => true,
                'display_title' => 'Gallery photo ' . $imageId,
                'description' => 'Prepared public photograph metadata for real responsive content.',
            ]);
        }
        return (string) ob_get_clean();
    }

    /** Render one complete production Home or Gallery page for the disposable Chromium route.
     * @param string $page Page fixture key: home, home-page-3, or gallery.
     * @return void Emit a full actual shared-layout document with a deterministic view model and route state.
     */
    function public_theme_width_fixture_page(string $page): void
    {
        if (!in_array($page, ['home', 'home-page-3', 'gallery'], true)) {
            throw new InvalidArgumentException(
                'Choose a supported Home or Gallery production page fixture.',
            );
        }
        $pageFamily = $page === 'home-page-3' ? 'home' : $page;
        $homeCurrentPage = $page === 'home-page-3' ? 3 : 2;
        $GLOBALS['public_theme_width_fixture_page'] = $pageFamily;
        $GLOBALS['public_theme_width_fixture_mode'] = 'default';
        $widgets = public_theme_width_fixture_widgets($pageFamily);
        $pagination = public_theme_width_fixture_pagination(
            $pageFamily === 'home'
                ? 'Home gallery pages'
                : 'Gallery listing pages',
            $pageFamily === 'home'
                ? '/fixture/home/page'
                : '/fixture/gallery/page',
            $homeCurrentPage,
        );
        ob_start();
        view_render_back_to_top_button('Back to top', 'Back to top');
        $backToTopHtml = (string) ob_get_clean();

        if ($pageFamily === 'home') {
            view_render_public_gallery_home([
                'site_name' => 'PHP Gallery layout fixture',
                'physical_gallery_count' => 6,
                'smart_gallery_count' => 0,
                'physical_gallery_revision' => 'fixture-home-revision',
                'canonical_url' => '/fixture/home/page/' . $homeCurrentPage,
                'search_bar' => [
                    'enabled' => true,
                    'search_id' => 'fixture-public-home-search',
                    'search_url' => '/fixture-search',
                    'min_length' => 2,
                    'delay_ms' => 10,
                    'media_delay_ms' => 0,
                    'descriptive_delay_ms' => 0,
                    'deep_delay_ms' => 0,
                    'aria_label' => 'Search public galleries',
                    'placeholder' => 'Search galleries and photos',
                    'loading_label' => 'Searching public content',
                    'empty_label' => 'No matching public content',
                    'error_label' => 'Search is temporarily unavailable',
                    'clear_label' => 'Clear public search',
                ],
                'gallery_count' => 6,
                'pagination_html' => $pagination,
                'cards_html' => public_theme_width_fixture_gallery_cards(
                    'Home',
                    $homeCurrentPage * 100,
                    6,
                ),
                'grid_class' => ' pagination-grid-columns-3',
                'current_page' => $homeCurrentPage,
                'total_pages' => 3,
                'back_to_top_html' => $backToTopHtml,
                'public_widgets' => $widgets,
            ]);
            return;
        }

        $subgalleryPagination = public_theme_width_fixture_pagination(
            'Subgallery pages',
            '/fixture/gallery/subgallery/page',
        );
        $photoPagination = public_theme_width_fixture_pagination(
            'Photo pages',
            '/fixture/gallery/photo/page',
        );
        view_render_public_gallery_detail([
            'page_title' => 'Production Gallery fixture',
            'gallery' => ['id' => 17, 'title' => 'Production Gallery fixture'],
            'public_only' => true,
            'public_widgets' => $widgets,
            'hero' => [
                'gallery_id' => 17,
                'title' => 'Production Gallery fixture',
                'visibility' => 'public',
                'branding_header_html' => '<h1>Production Gallery fixture</h1><p>Prepared gallery description.</p>',
                'breadcrumbs_html' => '<nav class="breadcrumbs" aria-label="Breadcrumb"><ol class="breadcrumbs__list"><li class="breadcrumbs__item"><a class="breadcrumbs__link" href="/fixture/home">Home</a></li><li class="breadcrumbs__item"><span class="breadcrumbs__current" aria-current="page">Production Gallery</span></li></ol></nav>',
                'tag_count' => 0,
                'tag_groups' => [],
            ],
            'has_list_content' => true,
            'subgallery_section' => [
                'visible' => true,
                'gallery_id' => 17,
                'total_count' => 2,
                'revision' => 'fixture-subgallery-revision',
                'current_page' => 2,
                'total_pages' => 3,
                'pagination_html' => $subgalleryPagination,
                'grid_class' => ' pagination-grid-columns-2',
                'cards_html' => public_theme_width_fixture_gallery_cards(
                    'Child',
                    200,
                    2,
                ),
            ],
            'image_section' => [
                'visible' => true,
                'gallery_id' => 17,
                'total_count' => 6,
                'revision' => 'fixture-photo-revision',
                'current_page' => 2,
                'total_pages' => 3,
                'pagination_html' => $photoPagination,
                'cards_html' => public_theme_width_fixture_photo_cards(300, 6),
            ],
            'back_to_top_html' => $backToTopHtml,
        ]);
    }

    $page = is_string($argv[1] ?? null) ? $argv[1] : '';
    public_theme_width_fixture_page($page);
}
