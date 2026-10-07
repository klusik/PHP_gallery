<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/patch_notes_links_render_test.php
 * Module Type: Regression Test
 * Purpose: Verify visible patch-note links and offline rendering of older cached Markdown.
 * Responsibilities:
 *   - Exercise HTTP(S) references, literal code and escaped unsafe input
 *   - Verify new-tab attributes and current presentation of cached release history
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Restrict patch-note fixture history to one trusted branch.
     * @var list<string>
     * Units: branch names. Scope: this standalone offline fixture.
     * Consumers: application_update_branch_candidates and the patch-note cache reader.
     * Rationale: exercise the real version parser without loading installation configuration.
     */
    const CMS_UPDATE_BRANCHES = ['main'];
    /** Escape fixture text for HTML. @param string $value Raw text. @return string Escaped text. */
    function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    /** Return the installed fixture version. @return string Installed version. */
    function cms_current_version(): string { return '1.0'; }
}

namespace Gallery\Services {
    /** Supply fixture presentation text.
     * @param string $key Translation identifier.
     * @param string $fallback English fallback.
     * @param array<string,string|int|float> $parameters Replacement values.
     * @return string Fixture text with parameters applied.
     */
    function t(string $key, string $fallback = '', array $parameters = []): string
    {
        $text = $fallback !== '' ? $fallback : $key;
        foreach ($parameters as $name => $value) { $text = str_replace('{' . $name . '}', (string) $value, $text); }
        return $text;
    }
    /** Return the owned fixture directory. @return string Temporary root. */
    function application_update_project_root(): string { return $GLOBALS['patch_notes_link_root']; }
}

namespace {
    require_once __DIR__ . '/../app/services/updates_remote.php';
    require_once __DIR__ . '/../app/services/updates_patch_notes.php';
    require_once __DIR__ . '/../app/views/admin_updates.php';

    /** Require an observable rendered-note behavior.
     * @param bool $condition Expected behavior. @param string $message Failure description.
     * @return void Throws when a rendering contract is broken.
     */
    function patch_notes_links_assert(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }

    $markdown = <<<'MD'
### Ref: [#100](https://github.com/klusik/PHP_gallery/issues/100)
- **Fixed** [Issue **#101**](https://github.com/klusik/PHP_gallery/issues/101?source=a_b&lang=cs)
- [Nested URL](https://example.test/wiki/Tool_(version))
Plain URL: https://example.test/a_b?first=1&second=2.
Autolink: <https://example.test/guide>.
Literal `[#9](https://example.test/code)` and `https://example.test/literal`.
[Bad](javascript:alert(1)) [Data](data:text/html,unsafe) [Relative](/admin) [No host](https:///missing)
Raw <img src=x onerror=alert(1)> and <script>alert(1)</script>.
```text
[#99](https://example.test/fenced)
```
MD;
    $html = \Gallery\Views\view_update_patch_notes_markdown_html($markdown);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);
    $anchors = $xpath->query('//a');
    patch_notes_links_assert($anchors->length === 5, 'References, plain URLs and autolinks must render exactly once; code and unsafe links stay literal.');
    $expectedUrls = [
        'https://github.com/klusik/PHP_gallery/issues/100',
        'https://github.com/klusik/PHP_gallery/issues/101?source=a_b&lang=cs',
        'https://example.test/wiki/Tool_(version)',
        'https://example.test/a_b?first=1&second=2',
        'https://example.test/guide',
    ];
    foreach ($anchors as $index => $anchor) {
        patch_notes_links_assert($anchor->getAttribute('href') === $expectedUrls[$index], 'Link target bytes or prose punctuation changed.');
        patch_notes_links_assert($anchor->getAttribute('target') === '_blank', 'Patch-note links must open in a new tab.');
        $rel = explode(' ', $anchor->getAttribute('rel'));
        patch_notes_links_assert(in_array('noopener', $rel, true) && in_array('noreferrer', $rel, true), 'New-tab links need opener protection.');
    }
    patch_notes_links_assert($xpath->query('//a/strong')->length === 1, 'A reference caption must preserve bold emphasis.');
    patch_notes_links_assert($xpath->query('//code//a | //img | //script | //iframe')->length === 0, 'Code or raw HTML must not become active markup.');
    patch_notes_links_assert(str_contains($html, '<code>[#9](https://example.test/code)</code>') && str_contains($html, '<pre><code>[#99]'), 'Inline and fenced code must remain literal.');
    patch_notes_links_assert(str_contains($html, '[Bad](javascript:alert(1))') && !str_contains($html, 'PATCHNOTES'), 'Rejected URLs must remain escaped text without parser tokens.');
    $boldUrl = \Gallery\Views\view_update_patch_notes_inline_markdown('[**Caption** `code_a_b`](https://example.test/a**b**?x=1&y=2)');
    patch_notes_links_assert(str_contains($boldUrl, 'href="https://example.test/a**b**?x=1&amp;y=2"') && str_contains($boldUrl, '<strong>Caption</strong> <code>code_a_b</code></a>'), 'Emphasis and caption code must never alter href bytes.');
    $controls = \Gallery\Views\view_update_patch_notes_inline_markdown('[Unsafe](https://example.test/&#10;bad)');
    patch_notes_links_assert(!str_contains($controls, '<a '), 'Entity-encoded URL controls must fail closed.');

    $GLOBALS['patch_notes_link_root'] = sys_get_temp_dir() . '/gallery-patch-links-' . bin2hex(random_bytes(6));
    mkdir($GLOBALS['patch_notes_link_root']);
    try {
        file_put_contents($GLOBALS['patch_notes_link_root'] . '/PATCH_NOTES.md', "## Version 1.0\nInstalled [#100](https://github.com/klusik/PHP_gallery/issues/100).\n");
        \Gallery\Services\application_patch_notes_write_cache('main', ['versions' => ['2.0' => [
            'version' => '2.0', 'title' => 'Version 2.0',
            'markdown' => 'Cached [#101](https://github.com/klusik/PHP_gallery/issues/101).',
            'html' => '<a href="javascript:alert(1)">Obsolete cached HTML</a>',
        ]]]);
        $notes = \Gallery\Services\application_patch_notes_viewer_data('main');
        patch_notes_links_assert(!isset($notes['versions']['2.0']['html']) && !isset($notes['versions']['1.0']['html']), 'The service must return raw Markdown, including older cached history.');
        $fragment = \Gallery\Views\view_render_update_patch_notes_fragment(['data' => $notes, 'versions' => $notes['versions'], 'selected_version' => '2.0']);
        patch_notes_links_assert(str_contains($fragment, 'href="https://github.com/klusik/PHP_gallery/issues/101" target="_blank"') && !str_contains($fragment, 'Obsolete cached HTML'), 'The visible fragment must re-render cached Markdown with current link rules without networking.');
    } finally {
        unlink($GLOBALS['patch_notes_link_root'] . '/cache/patch-notes/main.json');
        rmdir($GLOBALS['patch_notes_link_root'] . '/cache/patch-notes');
        rmdir($GLOBALS['patch_notes_link_root'] . '/cache');
        unlink($GLOBALS['patch_notes_link_root'] . '/PATCH_NOTES.md');
        rmdir($GLOBALS['patch_notes_link_root']);
    }
    echo "PASS patch-note references, safe new-tab links and cached Markdown rendering\n";
}
