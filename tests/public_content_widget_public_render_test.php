<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_content_widget_public_render_test.php
 * Module Type: Regression Test
 * Purpose: Verify safe public zone planning, page-scope fallback and single-instance SSR.
 * Responsibilities:
 *   - Prove page access and publication filters before rendering.
 *   - Ensure every supported region produces escaped content without duplicate IDs.
 *   - Keep floating data available in a non-obstructive no-JavaScript article.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Escape test HTML attributes and text using the production output encoding contract.
     *
     * @param string|int|float|bool|null $value Untrusted scalar text, including optional null, under test.
     * @return string HTML-safe UTF-8 text.
     */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

namespace Gallery\Services {
    /**
     * Provide an isolated localization fallback without bootstrapping live settings.
     *
     * @param string $key Catalog key under test.
     * @param string $fallback English default used in the unit test.
     * @return string Provided fallback label.
     */
    function t(string $key, string $fallback): string
    {
        return $fallback;
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/services/public_content_widgets.php';
    require_once dirname(__DIR__) . '/app/views/public_content_widgets.php';

    use function Gallery\Services\public_widget_normalize;
    use function Gallery\Services\public_widget_plan_rows;
    use function Gallery\Views\view_public_widget_region_html;

    $check = static function (bool $condition, string $message): void {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    };
    $rows = [];
    $fixtures = [
        [1, 'content_top', 'all', 'published', 'flow'],
        [2, 'left_rail', 'all', 'published', 'flow'],
        [3, 'right_rail', 'all', 'published', 'flow'],
        [4, 'home_before_grid', 'all', 'published', 'flow'],
        [5, 'home_after_grid', 'all', 'published', 'flow'],
        [6, 'footer', 'all', 'published', 'flow'],
        [7, 'content_bottom', 'all', 'published', 'floating'],
        [8, 'content_bottom', 'all', 'draft', 'flow'],
        [9, 'content_bottom', 'all', 'disabled', 'flow'],
        [10, 'content_bottom', 'gallery', 'published', 'flow'],
        [11, 'content_bottom', 'home', 'published', 'flow'],
    ];
    foreach ($fixtures as [$number, $slot, $scope, $status, $mode]) {
        $title = $number === 1 ? '<script>alert(1)</script>' : 'Gallery ' . $number;
        $content = $number === 1 ? "See **friends**:\n- [Custom label](https://example.org/a?b=1&c=2)"
            : 'Published text for gallery ' . $number;
        $rows[] = [
            'widget_id' => sprintf('%032x', $number),
            ...public_widget_normalize([
                'title' => $title, 'content_md' => $content,
                'page_scope' => $scope, 'placement_mode' => $mode,
                'flow_slot' => $slot, 'status' => $status, 'sort_order' => $number * 10,
            ]),
        ];
    }

    $home = public_widget_plan_rows($rows, 'home');
    $gallery = public_widget_plan_rows($rows, 'gallery');
    $denied = public_widget_plan_rows($rows, 'admin');
    $check(count($home['home_before_grid']) === 1 && count($home['home_after_grid']) === 1, 'Homepage grid zones');
    $check(count($home['left_rail']) === 1 && count($home['right_rail']) === 1, 'Independent rails');
    $check(count($home['footer']) === 1 && count($home['floating']) === 1, 'Footer and floating fallback');
    $check(count($home['content_bottom']) === 1, 'Home-only content and unpublished exclusion');
    $check(count($gallery['content_bottom']) === 3, 'Unsupported grid slots fall back on gallery pages');
    $check($gallery['home_before_grid'] === [] && $gallery['home_after_grid'] === [], 'Gallery has no homepage grid zone');
    $check($denied['content_top'] === [] && $denied['floating'] === [], 'Nonpublic page may not emit widgets');
    $check(!str_contains((string) json_encode($home), 'Published text for gallery 8')
        && !str_contains((string) json_encode($gallery), 'Published text for gallery 9'), 'Draft and disabled data absent');

    $top = view_public_widget_region_html($home, 'content_top');
    $check(str_contains($top, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'Authored title escaped');
    $check(str_contains($top, '<strong>friends</strong>'), 'Sanitized formatted content survives');
    $check(str_contains($top, 'rel="noopener noreferrer"'), 'External links protected');
    $check(str_contains($top, 'data-public-widget-sort-order="10"'), 'Stable saved order is available to transient Admin previews');
    $check(!str_contains($top, '<script'), 'No executable title markup');
    $float = view_public_widget_region_html($home, 'floating');
    $check(str_contains($float, 'data-public-widget-floating="1"'), 'Floating remains in initial HTML');
    $check(str_contains($float, 'data-public-widget-width="320"'), 'Bounded width survives to progressive enhancement');
    $check(str_contains($float, 'data-public-widget-dismiss-label="Close"'), 'Localized dismiss label is supplied');
    $check(!str_contains($float, 'position:fixed'), 'No-JS fallback remains document flow');
    $check(view_public_widget_region_html([], 'floating') === '', 'Empty pages emit no widget wrapper');

    $homeHtml = implode('', array_map(static fn (string $slot): string =>
        view_public_widget_region_html($home, $slot), array_keys($home)));
    $galleryHtml = implode('', array_map(static fn (string $slot): string =>
        view_public_widget_region_html($gallery, $slot), array_keys($gallery)));
    $check(substr_count($homeHtml, 'data-public-widget-id=') === 8, 'One home instance per published ID');
    $check(substr_count($galleryHtml, 'data-public-widget-id=') === 8, 'One gallery instance per published ID');
    $check(str_contains($galleryHtml, 'data-public-widget-zone="content_bottom"'), 'Gallery fallback is visible');
    echo "public_content_widget_public_render_test: PASS\n";
}
