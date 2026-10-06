<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_description_links_render_test.php
 * Module Type: Standalone Regression Test
 * Purpose: Verify gallery-description links render as safe inline anchors.
 * Responsibilities: Cover link syntax, inline placement, protected URL punctuation, and code literals.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Escape one value using the production helper contract.
     *
     * @param string $value Raw text.
     * @return string Escaped text.
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Return the asset path used by the description view.
     *
     * @param string $path Relative asset path.
     * @return string Root-relative asset URL.
     */
    function asset_url(string $path): string
    {
        return '/' . ltrim($path, '/');
    }
}

namespace {
    require_once __DIR__ . '/../app/views/gallery_descriptions.php';

    use function Gallery\Views\view_gallery_description_markdown_html;

    /**
     * Fail the standalone regression when one rendering contract is broken.
     *
     * @param bool $condition Whether the expected contract holds.
     * @param string $message Failure context.
     * @return void
     */
    function gallery_description_links_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    $description = 'Start [link]https://youtu.be/a_b_c_d_01[/link] and '
        . '[url]https://www.youtube.com/watch?v=a_b_c_d_02&list=PL_abc[/url], then '
        . '[link=https://example.com/path?q=a_b_c]named **caption**[/link] and '
        . '[url=https://example.org/item_a]URL caption[/url]. '
        . '[Markdown label](https://example.net/path_a_b). '
        . '[Docs `code_a_b`](https://example.net/docs). '
        . '[url=javascript:alert(1)]Unsafe `code_a_b` target[/url]. '
        . 'Code: `[link]https://youtu.be/not_a_real_video[/link]`.';
    $html = view_gallery_description_markdown_html($description);

    gallery_description_links_assert(substr_count($html, '<a class="gallery-description-external-link"') === 6, 'Supported URL tags and Markdown links should render as anchors, including inline in prose.');
    gallery_description_links_assert(str_contains($html, 'href="https://youtu.be/a_b_c_d_01"'), 'Underscores in a short YouTube URL path must remain part of the link target.');
    gallery_description_links_assert(str_contains($html, 'href="https://www.youtube.com/watch?v=a_b_c_d_02&amp;list=PL_abc"'), 'Underscores and query parameters in a YouTube watch URL must remain intact.');
    gallery_description_links_assert(str_contains($html, '>named <strong>caption</strong></a>'), 'Formatting inside a named link label should remain supported.');
    gallery_description_links_assert(str_contains($html, '>URL caption</a>') && str_contains($html, '>Markdown label</a>'), 'URL and Markdown label forms should preserve their visible captions.');
    gallery_description_links_assert(str_contains($html, '<p>Start <a class="gallery-description-external-link"') && str_contains($html, '</a> and <a class="gallery-description-external-link"'), 'Links embedded in ordinary prose should stay inside the paragraph.');
    gallery_description_links_assert(str_contains($html, '<code>[link]https://youtu.be/not_a_real_video[/link]</code>'), 'Link-like code literals should stay literal.');
    gallery_description_links_assert(str_contains($html, '>Docs <code>code_a_b</code></a>'), 'Code literals inside a link caption must preserve underscores without leaking parser tokens.');
    gallery_description_links_assert(!str_contains($html, 'href="javascript:'), 'Unsafe link targets must never become anchors.');
    gallery_description_links_assert(str_contains($html, '[url=javascript:alert(1)]Unsafe <code>code_a_b</code> target[/url]') && !str_contains($html, 'GALLERYDESCRIPTION'), 'Rejected link targets must preserve code captions without leaking parser tokens.');
    gallery_description_links_assert(!str_contains($html, '<iframe '), 'Description links should remain ordinary anchors.');

    echo "Gallery description link rendering tests passed.\n";
}
