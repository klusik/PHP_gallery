<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_ux_presentation_test.php
 * Module Type: Regression Test
 * Purpose: Protect public text projection, cardinal counts and photo names.
 * Responsibilities: Exercise production presentation owners without storage.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Supply the canonical language fallback without reading installation data.
     * @return array{language:array{default:string}} Isolated English fallback.
     */
    function cms_config(): array
    {
        return ['language' => ['default' => 'en']];
    }

    /**
     * Escape a plain fixture value using the public rendering contract.
     * @param string $value Untrusted description text.
     * @return string HTML-escaped text.
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/services/translations.php';
    require_once dirname(__DIR__) . '/app/services/gallery_description_layout.php';
    require_once dirname(__DIR__) . '/app/services/public_search.php';
    require_once dirname(__DIR__) . '/app/helpers_public_urls.php';

    use function Gallery\Core\image_alt_text;
    use function Gallery\Services\public_search_compact_text;
    use function Gallery\Services\t;

    /**
     * Fail the independent presentation fixture with its behavioral mismatch.
     * @param bool $condition Observed behavior matches the expected result.
     * @param string $message Failure description.
     * @return void Continues on success; throws on a regression.
     */
    function public_ux_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $_SESSION = ['cms_language' => 'en'];
    /* Bound the fixture's deliberately untruncated short descriptions.
     * Type: int. Units: characters. Scope: this presentation fixture.
     * Consumers: production snippet projection calls below.
     * Rationale: 160 exceeds these sample lengths while retaining a real bound.
     */
    const PUBLIC_UX_SNIPPET_LIMIT = 160;
    $snippet = public_search_compact_text(
        '**Robert** at `LKKB`: [url=https://example.com]video[/url] '
        . '[Docs](https://example.org) <b>safe</b> &amp; readable.',
        PUBLIC_UX_SNIPPET_LIMIT,
    );
    public_ux_assert(
        $snippet === 'Robert at LKKB: video Docs safe & readable.',
        'Search must retain rich-text labels without markup or HTML: ' . $snippet,
    );
    public_ux_assert(
        public_search_compact_text('<p>First</p><p>Second</p>', PUBLIC_UX_SNIPPET_LIMIT)
            === 'First Second',
        'Adjacent rich-text paragraphs must not join their words.',
    );
    public_ux_assert(
        public_search_compact_text('&lt;b&gt;literal&lt;/b&gt; &amp; text',
            PUBLIC_UX_SNIPPET_LIMIT) === '<b>literal</b> & text',
        'Encoded literal markup must remain escaped by the result view, not lost.',
    );
    public_ux_assert(
        !str_contains(public_search_compact_text(
            '[url=javascript:alert(1)]text[/url] <img src=x onerror=alert(1)>',
            PUBLIC_UX_SNIPPET_LIMIT,
        ), '<'),
        'An unsafe description must never inject result HTML.',
    );
    public_ux_assert(public_search_compact_text(
        '`code_a_b_c` [url]https://youtu.be/a_b_c_d[/url]',
        PUBLIC_UX_SNIPPET_LIMIT,
    ) === 'code_a_b_c https://youtu.be/a_b_c_d',
        'Inline code and visible literal URLs must preserve underscores.');
    public_ux_assert(t('gallery.image_count', ['count' => 1]) === '1 images',
        'Catalog keys without cardinal variants retain their existing text.');
    public_ux_assert(t('public.tag.gallery_count', null, ['count' => 1])
        === '1 gallery', 'Both translation parameter forms support cardinal text.');
    // Exact small count boundaries exercise the maintained cardinal grammar.
    foreach (['en' => ['1 gallery', '2 galleries', '5 galleries'],
        'cs' => ['1 galerie', '2 galerie', '5 galerií'],
        'de' => ['1 Galerie', '2 Galerien', '5 Galerien'],
        'sv' => ['1 galleri', '2 gallerier', '5 gallerier']] as $lang => $labels) {
        $_SESSION['cms_language'] = $lang;
        foreach ([1, 2, 5] as $index => $count) {
            public_ux_assert(t('public.tag.gallery_count', ['count' => $count])
                === $labels[$index], 'Count grammar must follow ' . $lang);
        }
    }
    $_SESSION['cms_language'] = 'cs';
    public_ux_assert(str_starts_with(t('gallery.card.pdf_file_count', ['count' => 1]),
        '1 jedinečný soubor PDF'), 'One PDF needs a singular accessible count.');
    public_ux_assert(str_starts_with(t('gallery.card.music_file_count', ['count' => 3]),
        '3 jedinečné hudební soubory'), 'Czech media paucal must stay grammatical.');
    public_ux_assert(image_alt_text([
        'title' => 'DSC_0001', 'filename' => 'DSC_0001.jpg',
    ], ['title' => 'Album'], 2) === 'Album — fotografie 2',
        'Filename-derived names need a useful localized numbered fallback.');
    public_ux_assert(image_alt_text(['title' => 'Authored title'],
        ['title' => 'Album']) === 'Authored title', 'Authored titles survive.');
    public_ux_assert(image_alt_text(['description' => 'Authored caption',
        'title' => 'Other title'], ['title' => 'Album']) === 'Authored caption',
        'Existing authored captions retain precedence.');
    echo "Public UX text, count and accessible photo-name contracts passed.\n";
}
