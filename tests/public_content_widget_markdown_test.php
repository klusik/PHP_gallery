<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_content_widget_markdown_test.php
 * Module Type: Regression Test
 * Purpose: Prove widget Markdown is safe and identical for Admin preview and public SSR.
 * Responsibilities:
 *   - Check formatting, arbitrary link labels, internal/external navigation behavior.
 *   - Refuse executable markup, unvalidated URLs and oversized malformed input.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/public_content_widgets.php';

use function Gallery\Services\public_widget_inline_html;
use function Gallery\Services\public_widget_markdown_html;

$source = "# Friend galleries\n\nVisit our **friends** and *resources*:\n\n"
    . "- [Aircraft photos](https://example.org/pictures?q=1&v=2)\n"
    . "- [Internal gallery](/index.php?page=gallery&id=42)\n\n"
    . "1. First\n2. Second\n\n## More information\nA second line.\n";
$html = public_widget_markdown_html($source);
foreach (['<h2>Friend galleries</h2>', '<strong>friends</strong>', '<em>resources</em>',
    '<ul>', '<ol>', '<li>First</li>', '<h3>More information</h3>',
    '<p>A second line.</p>',
    '<a href="https://example.org/pictures?q=1&amp;v=2" target="_blank" rel="noopener noreferrer">Aircraft photos</a>',
    '<a href="/index.php?page=gallery&amp;id=42">Internal gallery</a>'] as $needle) {
    if (!str_contains($html, $needle)) {
        throw new RuntimeException('Markdown widget renderer missing: ' . $needle);
    }
}
$malicious = public_widget_markdown_html("# Safe heading\n<script>alert(1)</script>\n"
    . "- [unsafe](javascript:alert)\n"
    . "- [injected](https://example.org/\"onclick=\"alert)\n"
    . "- <img src=x onerror=alert(1)>\n");
foreach (['<script', '<img ', 'onclick=', 'onerror=', 'href="javascript:', '<iframe'] as $needle) {
    if (str_contains($malicious, $needle)) {
        throw new RuntimeException('Untrusted widget source emitted active HTML: ' . $needle);
    }
}
if (!str_contains($malicious, '&lt;script&gt;') || !str_contains($malicious, '[unsafe](javascript:alert)')) {
    throw new RuntimeException('Unsupported HTML and URLs must remain visibly inert.');
}
if (public_widget_markdown_html(str_repeat('a', 16385)) !== ''
    || public_widget_markdown_html("\xff") !== ''
    || public_widget_markdown_html("  \n\n ") !== '') {
    throw new RuntimeException('Widget renderer must fail closed on invalid source.');
}
if (str_contains(public_widget_inline_html('[bad](data:text/html,hi)'), '<a')) {
    throw new RuntimeException('Executable URI created a link.');
}
echo "public_content_widget_markdown_test: PASS\n";
