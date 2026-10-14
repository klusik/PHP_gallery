<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/floating_scroll_controls_test.php
 * Module Type: Regression Test
 * Purpose: Verify semantic public scroll navigation markup, localized labels, and shared styling.
 * Responsibilities:
 *   - Render both controls without a database or full application bootstrap
 *   - Verify safe attribute/text escaping and accessible English fallback
 *   - Check all four supported complete catalogs and the responsive stylesheet contract
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Escape fixture-controlled text using the same HTML attribute and text policy.
     *
     * @param string $value Untrusted fixture text.
     * @return string HTML-escaped text.
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

namespace {
    require_once __DIR__ . '/../app/views/http.php';

    /**
     * Fail the focused regression on a missing behavioral or rendering contract.
     *
     * @param bool $condition Whether the expected behavior is present.
     * @param string $reason Description of a failed expectation.
     * @return void Throws on failure.
     */
    function assert_floating_scroll_control(bool $condition, string $reason): void
    {
        if (!$condition) {
            throw new \RuntimeException($reason);
        }
    }

    ob_start();
    \Gallery\Views\view_render_back_to_top_button('Top " <test>', 'Top &', 'Bottom " <test>', 'Down &');
    $html = (string) ob_get_clean();

    assert_floating_scroll_control(substr_count($html, 'data-floating-scroll-controls') === 1, 'Both controls must share one position container.');
    assert_floating_scroll_control(substr_count($html, 'class="back-to-top-button"') === 2, 'Both buttons must share the visual class.');
    assert_floating_scroll_control(substr_count($html, 'type="button"') === 2, 'Both controls must be semantic non-submitting buttons.');
    assert_floating_scroll_control(substr_count($html, ' hidden ') === 2, 'Both buttons must be hidden before client setup.');
    assert_floating_scroll_control(strpos($html, 'data-back-to-top-button') < strpos($html, 'data-scroll-to-bottom-button'), 'Top must precede Down.');
    assert_floating_scroll_control(str_contains($html, 'aria-hidden="true">↑') && str_contains($html, 'aria-hidden="true">↓'), 'Each visible directional icon must be decorative.');
    assert_floating_scroll_control(str_contains($html, 'aria-label="Bottom &quot; &lt;test&gt;"')
        && str_contains($html, 'title="Bottom &quot; &lt;test&gt;"')
        && str_contains($html, 'Down &amp;'), 'The Down accessible label, title and caption must be escaped.');
    assert_floating_scroll_control(str_contains($html, 'aria-label="Top &quot; &lt;test&gt;"') && str_contains($html, 'Top &amp;'), 'The Top label must retain proper escaping.');

    ob_start();
    \Gallery\Views\view_render_back_to_top_button('Go back to top', 'Top');
    $legacyCall = (string) ob_get_clean();
    assert_floating_scroll_control(str_contains($legacyCall, 'aria-label="Go to bottom"')
        && str_contains($legacyCall, '>Down</span>'), 'Two-argument callers must retain a usable English Down fallback.');

    $expected = [
        'en' => ['Go to bottom', 'Down'],
        'cs' => ['Přejít na konec stránky', 'Dolů'],
        'de' => ['Zum Seitenende springen', 'Nach unten'],
        'sv' => ['Gå till sidans slut', 'Nedåt'],
    ];
    foreach ($expected as $locale => [$label, $short]) {
        $catalog = json_decode(
            (string) file_get_contents(__DIR__ . '/../app/lang/' . $locale . '.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        assert_floating_scroll_control(is_array($catalog)
            && ($catalog['public.back_to_bottom_label'] ?? null) === $label
            && ($catalog['public.back_to_bottom_short'] ?? null) === $short,
            'Localized Down labels must exist in the ' . $locale . ' catalog.');
        assert_floating_scroll_control(isset($catalog['public.back_to_top_label'], $catalog['public.back_to_top_short']),
            'The ' . $locale . ' catalog must retain its existing Top labels.');
    }

    $helper = (string) file_get_contents(__DIR__ . '/../app/controllers/http_helpers.php');
    assert_floating_scroll_control(str_contains($helper, "t('public.back_to_bottom_label'")
        && str_contains($helper, "t('public.back_to_bottom_short'"), 'Controller must use translated labels.');

    foreach (['public-shared.css', 'admin-layout.css', 'admin.css'] as $name) {
        $css = (string) file_get_contents(__DIR__ . '/../public/assets/styles/' . $name);
        assert_floating_scroll_control(str_contains($css, '.floating-scroll-controls')
            && str_contains($css, '.back-to-top-button[data-scroll-to-bottom-button]')
            && str_contains($css, 'grid-template-rows: repeat(2, minmax(2.85rem, auto))')
            && str_contains($css, 'position: fixed;'),
            $name . ' must retain grouped two-slot positioning on desktop and mobile.');
    }

    echo "Floating page-edge control rendering, escaping, locales and CSS: PASS\n";
}
