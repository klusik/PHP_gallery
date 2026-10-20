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
 *   - Preserve each authored language and the deterministic English default in public HTML.
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
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
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
    use function Gallery\Services\public_widget_normalize_public_row;
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
        $title = $number === 1
            ? '<script>alert(1)</script>'
            : 'Gallery ' . $number;
        $content = $number === 1
            ? "See **friends**:\n- [Custom label](https://example.org/a?b=1&c=2)"
            : 'Published text for gallery ' . $number;
        $input = [
            'title' => $title,
            'content_md' => $content,
            'page_scope' => $scope,
            'placement_mode' => $mode,
            'flow_slot' => $slot,
            'status' => $status,
            'sort_order' => $number * 10,
        ];
        if ($number === 2) {
            $input['title'] = 'Galerie Zwei';
            $input['content_md'] = 'Beispieltext in der verfassten Sprache.';
            $input['source_language'] = 'cs';
        }
        $rows[] = [
            'widget_id' => sprintf('%032x', $number),
            ...public_widget_normalize($input),
        ];
    }

    // Simulate an older or directly corrupted published database row that bypassed current write validation.
    $malformedStoredWidget = [
        'widget_id' => sprintf('%032x', 99),
        ...public_widget_normalize([
            'title' => 'Malformed stored widget probe',
            'content_md' => 'Previously accepted content',
            'status' => 'published',
            'page_scope' => 'all',
            'flow_slot' => 'content_top',
        ]),
    ];
    $malformedStoredWidget['content_md'] = 'Legacy unsafe link [probe](javascript:alert(1))';
    foreach (['home', 'gallery'] as $publicPageType) {
        $malformedPlan = public_widget_plan_rows(
            [...$rows, $malformedStoredWidget],
            $publicPageType,
        );
        $malformedPlanRows = [];
        foreach ($malformedPlan as $zoneRows) {
            $malformedPlanRows = [...$malformedPlanRows, ...$zoneRows];
        }
        $malformedPlanJson = json_encode(
            $malformedPlanRows,
            JSON_THROW_ON_ERROR,
        );
        $check(
            !in_array(
                $malformedStoredWidget['widget_id'],
                array_column($malformedPlanRows, 'widget_id'),
                true,
            )
                && !str_contains(
                    $malformedPlanJson,
                    'Malformed stored widget probe',
                )
                && !str_contains($malformedPlanJson, 'Legacy unsafe link'),
            'Malformed persisted widget content or metadata reached the public '
                . $publicPageType
                . ' plan.',
        );
    }

    $home = public_widget_plan_rows($rows, 'home');
    $gallery = public_widget_plan_rows($rows, 'gallery');
    $denied = public_widget_plan_rows($rows, 'admin');
    $check(
        count($home['home_before_grid']) === 1
            && count($home['home_after_grid']) === 1,
        'Homepage grid zones',
    );
    $check(
        count($home['left_rail']) === 1 && count($home['right_rail']) === 1,
        'Independent rails',
    );
    $check(
        count($home['footer']) === 1 && count($home['floating']) === 1,
        'Footer and floating fallback',
    );
    $check(
        count($home['content_bottom']) === 1,
        'Home-only content and unpublished exclusion',
    );
    $check(
        count($gallery['content_bottom']) === 3,
        'Unsupported grid slots fall back on gallery pages',
    );
    $check(
        $gallery['home_before_grid'] === []
            && $gallery['home_after_grid'] === [],
        'Gallery has no homepage grid zone',
    );
    $check(
        $denied['content_top'] === [] && $denied['floating'] === [],
        'Nonpublic page may not emit widgets',
    );
    $check(
        !str_contains(
            (string) json_encode($home),
            'Published text for gallery 8',
        )
            && !str_contains(
                (string) json_encode($gallery),
                'Published text for gallery 9',
            ),
        'Draft and disabled data absent',
    );

    $invalidLanguageWidget = [
        'widget_id' => sprintf('%032x', 98),
        ...public_widget_normalize_public_row([
            'title' => 'Restored widget with unsupported language metadata',
            'content_md' => 'Published content remains visible.',
            'status' => 'published',
            'page_scope' => 'all',
            'flow_slot' => 'content_top',
            'source_language' => 'xx',
        ]),
    ];
    $invalidLanguagePlan = public_widget_plan_rows(
        [$invalidLanguageWidget],
        'home',
    );
    $invalidLanguageHtml = view_public_widget_region_html(
        $invalidLanguagePlan,
        'content_top',
    );
    $check(
        str_contains(
            $invalidLanguageHtml,
            'data-public-widget-id="'
                . $invalidLanguageWidget['widget_id']
                . '"',
        )
            && str_contains($invalidLanguageHtml, 'lang="en"')
            && str_contains(
                $invalidLanguageHtml,
                'Published content remains visible.',
            ),
        'Unsupported stored source language hid public widget content instead of using the English markup fallback.',
    );

    $strictLanguageRejected = false;
    try {
        public_widget_normalize([
            'title' => 'Strict Admin language validation',
            'content_md' => 'Draft content',
            'source_language' => 'xx',
        ]);
    } catch (\Gallery\Services\PublicWidgetInvalidField $error) {
        $strictLanguageRejected = $error->field === 'source_language';
    }
    $check(
        $strictLanguageRejected,
        'Admin writes must continue to reject unsupported source languages.',
    );

    $top = view_public_widget_region_html($home, 'content_top');
    $check(
        str_contains($top, '&lt;script&gt;alert(1)&lt;/script&gt;'),
        'Authored title escaped',
    );
    $check(
        str_contains($top, '<strong>friends</strong>'),
        'Sanitized formatted content survives',
    );
    $check(
        str_contains($top, 'rel="noopener noreferrer"'),
        'External links protected',
    );
    $check(
        str_contains($top, 'data-public-widget-sort-order="10"'),
        'Stable saved order is available to transient Admin previews',
    );
    $check(!str_contains($top, '<script'), 'No executable title markup');
    $float = view_public_widget_region_html($home, 'floating');
    $check(
        str_contains($float, 'data-public-widget-floating="1"'),
        'Floating remains in initial HTML',
    );
    $check(
        str_contains($float, 'data-public-widget-width="320"'),
        'Bounded width survives to progressive enhancement',
    );
    $check(
        str_contains($float, 'data-public-widget-dismiss-label="Close"'),
        'Localized dismiss label is supplied',
    );
    $check(
        !str_contains($float, 'position:fixed'),
        'No-JS fallback remains document flow',
    );
    $check(
        view_public_widget_region_html([], 'floating') === '',
        'Empty pages emit no widget wrapper',
    );

    $homeHtml = implode('', array_map(
        static fn(string $slot): string => view_public_widget_region_html(
            $home,
            $slot,
        ),
        array_keys($home),
    ));
    $galleryHtml = implode('', array_map(
        static fn(string $slot): string => view_public_widget_region_html(
            $gallery,
            $slot,
        ),
        array_keys($gallery),
    ));
    $check(
        substr_count($homeHtml, 'data-public-widget-id=') === 8,
        'One home instance per published ID',
    );
    $check(
        substr_count($galleryHtml, 'data-public-widget-id=') === 8,
        'One gallery instance per published ID',
    );
    $check(
        str_contains($galleryHtml, 'data-public-widget-zone="content_bottom"'),
        'Gallery fallback is visible',
    );
    $authoredLanguageWidget = sprintf('%032x', 2);
    $defaultLanguageWidget = sprintf('%032x', 3);
    $check(
        str_contains(
            $homeHtml,
            'data-public-widget-id="'
                . $authoredLanguageWidget
                . '" data-public-widget-sort-order="20" lang="cs"',
        )
            && str_contains(
                $galleryHtml,
                'Beispieltext in der verfassten Sprache.',
            ),
        'Public widget content did not retain its explicitly authored language across public page families.',
    );
    $check(
        str_contains(
            $homeHtml,
            'data-public-widget-id="'
                . $defaultLanguageWidget
                . '" data-public-widget-sort-order="30" lang="en"',
        ),
        'Public widget without source_language did not use the documented English default.',
    );
    echo "public_content_widget_public_render_test: PASS\n";
}
