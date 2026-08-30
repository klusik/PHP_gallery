<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/link_favicon_model_test.php
 * Module Type: Standalone Regression Test
 *
 * Purpose:
 *   Verifies the pure URL, markup, domain, and image-validation contracts used by gallery-description link favicons.
 *
 * Responsibilities:
 *   - Reject executable and malformed gallery-description link targets
 *   - Preserve exact-domain brand matching without substring spoofing
 *   - Cover supported link syntaxes and relative favicon URL resolution
 *   - Accept only structurally valid supported favicon image bytes
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Last Updated:
 *   2026-08-30
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/services/link_favicons.php';

use function Gallery\Services\link_favicon_extract_description_urls;
use function Gallery\Services\link_favicon_html_icon_urls;
use function Gallery\Services\link_favicon_known_icon_id;
use function Gallery\Services\link_favicon_normalize_url;
use function Gallery\Services\link_favicon_resolve_relative_url;
use function Gallery\Services\link_favicon_validate_image;

/**
 * Fail the standalone script when one expected contract is not satisfied.
 */
function link_favicon_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

link_favicon_test_assert(link_favicon_normalize_url('www.example.com/path') === 'https://www.example.com/path', 'www. targets should normalize to HTTPS.');
link_favicon_test_assert(link_favicon_normalize_url('javascript:alert(1)') === null, 'Executable schemes must be rejected.');
link_favicon_test_assert(link_favicon_normalize_url('data:text/html,test') === null, 'Data URLs must be rejected.');
link_favicon_test_assert(link_favicon_normalize_url("https://example.com/\nnext") === null, 'Whitespace-bearing targets must be rejected.');

link_favicon_test_assert(link_favicon_known_icon_id('https://www.youtube.com/watch?v=1') === 'youtube', 'Known brand domains should use their bundled symbol.');
link_favicon_test_assert(link_favicon_known_icon_id('https://music.youtube.com/watch?v=1') === 'youtube', 'Known brand subdomains should use their bundled symbol.');
link_favicon_test_assert(link_favicon_known_icon_id('https://notyoutube.com/watch?v=1') === null, 'Substring lookalike domains must not receive a brand symbol.');

$description = '[link=https://one.example/a]One[/link] [url]www.two.example[/url] [Three](https://three.example/c) [link=javascript:alert(1)]Unsafe[/link]';
link_favicon_test_assert(
    link_favicon_extract_description_urls($description) === [
        'https://one.example/a',
        'https://www.two.example',
        'https://three.example/c',
    ],
    'All supported safe link syntaxes should be extracted in source order.'
);

$html = '<link rel="apple-touch-icon" href="/apple.png"><link rel="icon" type="image/png" href="icons/site.png"><link rel="icon" type="image/svg+xml" href="active.svg">';
link_favicon_test_assert(
    link_favicon_html_icon_urls($html, 'https://example.com/path/page.html') === [
        'https://example.com/path/icons/site.png',
        'https://example.com/apple.png',
    ],
    'Preferred raster favicon candidates should resolve ahead of touch icons while SVG stays excluded.'
);
link_favicon_test_assert(
    link_favicon_resolve_relative_url('https://example.com/a/b/page.html', '../icon.png?size=32#ignored') === 'https://example.com/a/icon.png?size=32',
    'Relative favicon paths should normalize without retaining fragments.'
);
link_favicon_test_assert(link_favicon_resolve_relative_url('https://example.com/a/', 'javascript:alert(1)') === null, 'Relative resolution must reject non-HTTP schemes.');

$validIco = "\x00\x00\x01\x00\x01\x00"
    . "\x10\x10\x00\x00\x01\x00\x20\x00"
    . "\x04\x00\x00\x00\x16\x00\x00\x00"
    . "DATA";
link_favicon_test_assert(link_favicon_validate_image($validIco) === ['mime_type' => 'image/x-icon', 'extension' => 'ico'], 'A bounded structurally valid ICO should be accepted.');
link_favicon_test_assert(link_favicon_validate_image('<svg><script>alert(1)</script></svg>') === null, 'SVG and active image content must be rejected.');
link_favicon_test_assert(link_favicon_validate_image("\x00\x00\x01\x00\x00\x00") === null, 'An ICO with no image entries must be rejected.');

$serviceSource = (string) file_get_contents(__DIR__ . '/../app/services/link_favicons.php');
link_favicon_test_assert(str_contains($serviceSource, "presentation_schema_tables_status('presentation.link_favicon_cache'"), 'Favicon persistence must use an explicit three-state schema capability.');
link_favicon_test_assert(!str_contains($serviceSource, "db_table_exists(LINK_FAVICON_CACHE_TABLE)"), 'Legacy boolean table checks must not authorize favicon cache persistence.');

echo "Link favicon model tests passed.\n";
