<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_content_widget_page_composition_test.php
 * Module Type: Regression Test
 * Purpose: Verify public Home and Gallery composition around prepared widget zones.
 * Responsibilities:
 *   - Exercise the production public page view functions with narrow shell stubs.
 *   - Preserve Home pagination, gallery cards, Gallery detail content, and footer markers.
 *   - Ensure empty widget plans add no region or rail wrappers.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Escape the static view-test values with the application's HTML encoding contract.
     *
     * @param string|int|float|bool|null $value Scalar or nullable view text under test.
     * @return string HTML-safe UTF-8 text.
     */
    function e(string|int|float|bool|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Emit stable existing public header and navigation markers for page composition tests.
     *
     * @param string $title Prepared public page title.
     * @param array<string,mixed> $gallery Prepared gallery metadata for a detail page.
     * @param bool $publicOnly Whether the detail page is rendered in public-only mode.
     * @return void Emit the test shell header and opening main element.
     */
    function render_header(string $title, array $gallery = [], bool $publicOnly = false): void
    {
        echo '<header data-existing-header><nav data-existing-navigation>Existing navigation</nav>'
            . '<span data-existing-page-title>' . e($title) . '</span>'
            . '<span data-existing-gallery-meta-count="' . count($gallery) . '"></span>'
            . '<span data-existing-public-mode>' . ($publicOnly ? 'public' : 'normal') . '</span></header><main>';
    }

    /**
     * Place prepared footer widgets inside the existing public footer before its credit.
     *
     * @param string $publicWidgetFooterHtml Already-rendered widget footer region.
     * @return void Emit the footer shell, widget region, and existing credit marker.
     */
    function render_footer(string $publicWidgetFooterHtml): void
    {
        echo '</main><footer class="site-footer">' . $publicWidgetFooterHtml
            . '<span data-existing-footer-credit>Existing footer credit</span></footer>';
    }
}

namespace Gallery\Services {
    /**
     * Return the supplied fallback for deterministic, installation-free view rendering.
     *
     * @param string $key Localization catalog key requested by the view.
     * @param string $fallback English fallback text requested by the view.
     * @param array<string,string|int|float> $replace Optional scalar interpolation values.
     * @return string Fallback text with the requested scalar values interpolated.
     */
    function t(string $key, string $fallback, array $replace = []): string
    {
        if (trim($key) === '') {
            throw new \InvalidArgumentException('Localization test keys must not be empty.');
        }
        foreach ($replace as $name => $value) {
            $fallback = str_replace('{' . $name . '}', (string) $value, $fallback);
        }
        return $fallback;
    }
}

namespace Gallery\Views {
    /**
     * Emit a stable existing search marker where the production view calls its search renderer.
     *
     * @param array{} $viewModel Empty public search state used by this page-composition test.
     * @return void Emit the existing search marker.
     */
    function view_render_public_search_bar(array $viewModel): void
    {
        echo '<section data-existing-search data-existing-search-state="'
            . (empty($viewModel) ? 'empty' : 'prepared') . '">Existing public search</section>';
    }

    /**
     * Emit a stable existing Gallery hero-tags marker from the production hero renderer.
     *
     * @param array<string,mixed> $viewModel Controller-prepared public hero-tag state.
     * @return void Emit the existing Gallery hero-tags marker.
     */
    function view_render_public_hero_tags(array $viewModel): void
    {
        $groups = is_array($viewModel['groups'] ?? null) ? $viewModel['groups'] : [];
        echo '<div data-existing-hero-tags data-existing-tag-group-count="' . count($groups)
            . '">Existing Gallery hero tags</div>';
    }
}

namespace {
    use function Gallery\Views\view_render_public_gallery_detail;
    use function Gallery\Views\view_render_public_gallery_home;

    require_once dirname(__DIR__) . '/app/views/public_content_widgets.php';
    require_once dirname(__DIR__) . '/app/views/public_gallery_pages.php';

    $check = static function (bool $condition, string $message): void {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    };
    $capture = static function (callable $render): string {
        ob_start();
        try {
            $render();
            return (string) ob_get_clean();
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
        }
    };
    $assertOrdered = static function (string $html, array $markers, string $context) use ($check): void {
        $cursor = 0;
        foreach ($markers as $marker) {
            $position = strpos($html, $marker, $cursor);
            $check($position !== false, $context . ' is missing marker: ' . $marker);
            $cursor = $position + strlen($marker);
        }
    };
    $makeWidget = static fn (string $id, string $title, string $body, int $sortOrder): array => [
        'widget_id' => $id,
        'title' => $title,
        'body_html' => '<p>' . $body . '</p>',
        'appearance' => 'card',
        'source_language' => 'en',
        'placement_mode' => 'flow',
        'floating_anchor' => 'bottom-right',
        'x_permille' => 900,
        'y_permille' => 900,
        'width_px' => 320,
        'sort_order' => $sortOrder,
    ];

    $homeBase = [
        'site_name' => 'Existing Home title',
        'physical_gallery_count' => 2,
        'smart_gallery_count' => 0,
        'physical_gallery_revision' => 'home-revision',
        'canonical_url' => '/index.php?page=home',
        'gallery_count' => 2,
        'pagination_html' => '<nav data-existing-pagination>Existing Home pagination</nav>',
        'cards_html' => '<article data-existing-gallery-card>Existing Home gallery card</article>',
        'current_page' => 1,
        'total_pages' => 2,
        'back_to_top_html' => '<button data-existing-back-to-top>Back to top</button>',
    ];
    $renderHome = static function (array $widgets) use ($capture, $homeBase): string {
        return $capture(static function () use ($widgets, $homeBase): void {
            view_render_public_gallery_home($homeBase + ['public_widgets' => $widgets]);
        });
    };

    $homeWithWidgets = $renderHome([
        'home_before_grid' => [$makeWidget(str_repeat('a', 32), 'Before grid', 'Before-grid widget', 10)],
        'home_after_grid' => [$makeWidget(str_repeat('b', 32), 'After grid', 'After-grid widget', 20)],
        'footer' => [$makeWidget(str_repeat('c', 32), 'Footer widget', 'Footer widget content', 30)],
    ]);
    $assertOrdered($homeWithWidgets, [
        'data-existing-header',
        'data-existing-navigation',
        'data-existing-search',
        'data-public-widget-zone="home_before_grid"',
        'data-existing-pagination',
        'data-public-gallery-index-grid',
        'data-existing-gallery-card',
        'data-existing-pagination',
        'data-public-widget-zone="home_after_grid"',
        'data-existing-back-to-top',
    ], 'Home view with widgets');
    $homeFooter = strpos($homeWithWidgets, '<footer class="site-footer">');
    $homeFooterWidget = strpos($homeWithWidgets, 'data-public-widget-zone="footer"');
    $homeFooterCredit = strpos($homeWithWidgets, 'data-existing-footer-credit');
    $homeFooterEnd = strpos($homeWithWidgets, '</footer>');
    $check($homeFooter !== false && $homeFooterWidget !== false && $homeFooterCredit !== false
        && $homeFooterEnd !== false && $homeFooter < $homeFooterWidget
        && $homeFooterWidget < $homeFooterCredit && $homeFooterCredit < $homeFooterEnd,
        'Home footer widget stays inside the existing footer before its credit.');
    $check(substr_count($homeWithWidgets, 'data-existing-pagination') === 2,
        'Home keeps both pagination instances when grid widgets are present.');

    $homeWithoutWidgets = $renderHome([]);
    $check(!str_contains($homeWithoutWidgets, 'data-public-widget-zone="')
        && !str_contains($homeWithoutWidgets, 'public-widget-content-layout')
        && !str_contains($homeWithoutWidgets, 'public-widget-primary'),
        'An empty Home plan emits no widget region or rail wrapper.');
    $assertOrdered($homeWithoutWidgets, [
        'data-existing-header',
        'data-existing-navigation',
        'data-existing-search',
        'data-existing-pagination',
        'data-public-gallery-index-grid',
        'data-existing-gallery-card',
        'data-existing-pagination',
        'data-existing-back-to-top',
        'data-existing-footer-credit',
    ], 'Home view without widgets');

    $galleryBase = [
        'page_title' => 'Existing Gallery title',
        'gallery' => ['id' => 17],
        'public_only' => true,
        'hero' => [
            'gallery_id' => 17,
            'branding_header_html' => '<h1 data-existing-gallery-heading>Existing Gallery heading</h1>',
            'breadcrumbs_html' => '<nav data-existing-breadcrumbs>Existing Gallery breadcrumbs</nav>',
        ],
        'branding_separator_html' => '<hr data-existing-branding-separator>',
        'preview_toolbar_html' => '<div data-existing-preview-toolbar>Existing Gallery toolbar</div>',
        'search_bar' => [],
        'picture_manager_toolbar_html' => '<div data-existing-picture-manager-toolbar>Existing picture-manager toolbar</div>',
        'has_list_content' => true,
        'top_smart_group_html' => '<section data-existing-top-smart-gallery>Existing top Smart Gallery</section>',
        'subgallery_section' => ['visible' => false],
        'image_section' => [
            'visible' => true,
            'gallery_id' => 17,
            'pagination_html' => '<nav data-existing-gallery-pagination>Existing Gallery pagination</nav>',
            'cards_html' => '<article data-existing-photo-card>Existing Gallery photo</article>',
            'lightbox_enabled' => true,
            'lightbox_endpoint' => '/index.php?page=lightbox',
            'lightbox_total' => 1,
            'current_page' => 1,
            'total_pages' => 1,
        ],
        'bottom_smart_group_html' => '<section data-existing-bottom-smart-gallery>Existing bottom Smart Gallery</section>',
        'back_to_top_html' => '<button data-existing-gallery-back-to-top>Back to top</button>',
        'lightbox_html' => '<div data-existing-lightbox>Existing Gallery lightbox</div>',
    ];
    $renderGallery = static function (array $widgets) use ($capture, $galleryBase): string {
        return $capture(static function () use ($widgets, $galleryBase): void {
            view_render_public_gallery_detail($galleryBase + ['public_widgets' => $widgets]);
        });
    };

    $galleryWithWidgets = $renderGallery([
        'content_top' => [$makeWidget(str_repeat('d', 32), 'Gallery top', 'Gallery top widget', 10)],
        'right_rail' => [$makeWidget(str_repeat('e', 32), 'Gallery rail', 'Gallery rail widget', 20)],
        'content_bottom' => [$makeWidget(str_repeat('f', 32), 'Gallery bottom', 'Gallery bottom widget', 30)],
        'footer' => [$makeWidget(str_repeat('1', 32), 'Gallery footer', 'Gallery footer widget', 40)],
    ]);
    $assertOrdered($galleryWithWidgets, [
        'data-existing-header',
        'data-existing-navigation',
        'data-public-widget-zone="content_top"',
        'public-widget-content-layout--right',
        'class="hero" data-public-gallery-id="17"',
        'data-existing-gallery-heading',
        'data-existing-breadcrumbs',
        'data-existing-hero-tags',
        'data-existing-branding-separator',
        'data-existing-preview-toolbar',
        'data-existing-search',
        'data-existing-picture-manager-toolbar',
        'data-existing-top-smart-gallery',
        'data-existing-gallery-pagination',
        'data-gallery-image-list',
        'data-existing-photo-card',
        'data-existing-gallery-pagination',
        'data-existing-bottom-smart-gallery',
        'data-existing-gallery-back-to-top',
        'data-public-widget-zone="right_rail"',
        'data-public-widget-zone="content_bottom"',
        'data-existing-lightbox',
    ], 'Gallery detail view with widgets');
    $galleryFooter = strpos($galleryWithWidgets, '<footer class="site-footer">');
    $galleryFooterWidget = strpos($galleryWithWidgets, 'data-public-widget-zone="footer"');
    $galleryFooterCredit = strpos($galleryWithWidgets, 'data-existing-footer-credit');
    $galleryFooterEnd = strpos($galleryWithWidgets, '</footer>');
    $check($galleryFooter !== false && $galleryFooterWidget !== false && $galleryFooterCredit !== false
        && $galleryFooterEnd !== false && $galleryFooter < $galleryFooterWidget
        && $galleryFooterWidget < $galleryFooterCredit && $galleryFooterCredit < $galleryFooterEnd,
        'Gallery footer widget stays inside the existing footer before its credit.');

    $galleryWithoutWidgets = $renderGallery([]);
    $check(!str_contains($galleryWithoutWidgets, 'data-public-widget-zone="')
        && !str_contains($galleryWithoutWidgets, 'public-widget-content-layout')
        && !str_contains($galleryWithoutWidgets, 'public-widget-primary'),
        'An empty Gallery plan emits no widget region or rail wrapper.');
    $assertOrdered($galleryWithoutWidgets, [
        'data-existing-header',
        'data-existing-navigation',
        'class="hero" data-public-gallery-id="17"',
        'data-existing-gallery-heading',
        'data-existing-breadcrumbs',
        'data-existing-hero-tags',
        'data-existing-branding-separator',
        'data-existing-preview-toolbar',
        'data-existing-search',
        'data-existing-picture-manager-toolbar',
        'data-existing-top-smart-gallery',
        'data-existing-gallery-pagination',
        'data-gallery-image-list',
        'data-existing-photo-card',
        'data-existing-gallery-pagination',
        'data-existing-bottom-smart-gallery',
        'data-existing-gallery-back-to-top',
        'data-existing-lightbox',
        'data-existing-footer-credit',
    ], 'Gallery detail view without widgets');

    echo "public_content_widget_page_composition_test: PASS\n";
}
