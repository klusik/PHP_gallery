<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_tags_layout_test.php
 * Module Type: Regression Test
 * Purpose: Verify tag catalog/editor containment and native form semantics.
 * Responsibilities: Render prepared page/panel states without installation data;
 *   protect safe output, help associations and media-specific actions.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Escape untrusted prepared values using production HTML semantics.
     * @param string $value Plain text or an attribute value.
     * @return string Escaped HTML text.
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Supply an isolated document boundary without bootstrapping a live site.
     * @param string $title Prepared page title, unused by this layout fixture.
     * @return void Open the fixture's native document and main region.
     */
    function render_header(string $title): void
    {
        echo '<!doctype html><html><body><main>';
    }

    /**
     * Close the isolated document so browser-repaired ancestry is observable.
     * @return void Emit matching main/body/document closing elements.
     */
    function render_footer(): void
    {
        echo '</main></body></html>';
    }
}

namespace Gallery\Services {
    /**
     * Read the real English catalog without application configuration or storage.
     * @param string $key Flat translation identity.
     * @param string $fallback Source fallback when no catalog entry exists.
     * @param array<string,string|int> $parameters Named plain-text placeholders.
     * @return string Localized plain text for the production renderer to escape.
     */
    function t(string $key, string $fallback = '', array $parameters = []): string
    {
        $value = $GLOBALS['tag_layout_catalog'][$key] ?? $fallback;
        foreach ($parameters as $name => $replacement) {
            $value = str_replace('{' . $name . '}', (string) $replacement, $value);
        }
        return $value;
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/views/admin_tags.php';

    /**
     * Fail on a lost layout or form contract using a fixed diagnostic.
     * @param bool $condition Actual production-renderer observation.
     * @param string $message Static failure explanation.
     * @return void Throw on failure; otherwise continue the fixture.
     */
    function tag_layout_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $GLOBALS['tag_layout_catalog'] = json_decode(
        (string) file_get_contents(dirname(__DIR__) . '/app/lang/en.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $render = static function (array $model): DOMXPath {
        ob_start();
        try {
            Gallery\Views\view_render_admin_tags($model);
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return new DOMXPath($document);
    };
    $tag = ['id' => 7, 'name' => '<b>wing</b>', 'slug' => 'wing-url'];
    $form = [
        'tag' => $tag,
        'description' => '</textarea><script>unsafe</script>',
        'sort_mode' => 'name',
        'media_type' => 'image',
        'csrf_html' => '<input type="hidden" name="csrf_token" value="fixture">',
        'action_url' => '/save-tag',
        'public_url' => '/tag/wing-url',
        'theme_url' => '/theme/tags',
    ];
    $model = [
        'selected_id' => 7,
        'selected_tag' => $tag,
        'sort_mode' => 'name',
        'media_type' => 'all',
        'filter_options' => [
            ['value' => 'all', 'label' => 'All tags', 'disabled' => false],
            ['value' => 'image', 'label' => 'Images', 'disabled' => false],
            ['value' => 'gallery', 'label' => 'Galleries', 'disabled' => false],
            ['value' => 'music', 'label' => 'Music', 'disabled' => false],
            ['value' => 'documents', 'label' => 'Documents (PDF, later)', 'disabled' => true],
        ],
        'tags' => [$tag + ['edit_url' => '/edit-tag?id=7', 'usage_count' => 2]],
        'form' => $form,
        'settings_url' => '/settings/content',
        'filters_url' => '/edit-tags',
        'usage' => [
            'galleries' => [['title' => '<b>Gallery</b>', 'public_url' => '/gallery']],
            'images' => [[
                'relative_path' => '<script>image.jpg</script>',
                'edit_url' => '/image-editor',
                'side_panel_url' => '/image-editor?panel=1',
                'gallery_title' => 'Gallery',
            ]],
        ],
    ];
    $page = $render($model);
    tag_layout_assert(
        $page->query('//*[@class="admin-tags-layout"]/section//ul/li/a')->length === 1
            && $page->query('//*[@class="admin-tags-layout"]/div//form')->length === 1
            && $page->query('//*[@id="admin-tags-editor"]//section/h3')->length === 1,
        'Catalog, selected editor and usage must stay inside their owned layout.',
    );
    tag_layout_assert(
        $page->query('//ul/li/a[@aria-current="true" and @href="/edit-tag?id=7"]')->length === 1
            && $page->query('//a[@href="#admin-tags-editor"]')->length === 1,
        'Catalog links retain native link semantics, selection and editor navigation.',
    );
    // These are the existing name/slug boundaries (100/120 characters),
    // asserted as HTML contracts rather than new fixture policy defaults.
    tag_layout_assert(
        $page->query('//form[@method="post" and @action="/save-tag"]//input[@name="csrf_token"]')->length === 1
            && $page->query('//input[@name="name" and @required and @maxlength="100"]')->length === 1
            && $page->query('//input[@name="slug" and @required and @maxlength="120"]')->length === 1
            && $page->query('//form[@method="post"]//button[@type="submit"]')->length === 1,
        'Explicit native Save retains CSRF, field names and existing limits.',
    );
    foreach (['name', 'slug', 'description'] as $field) {
        $control = $page->query('//*[@name="' . $field . '"]')->item(0);
        $id = $control->getAttribute('id');
        $helpId = $control->getAttribute('aria-describedby');
        tag_layout_assert(
            $page->query('//label[@for="' . $id . '"]')->length === 1
                && $page->query('//*[@id="' . $helpId . '" and @popover]')->length === 1
                && $page->query('//button[@popovertarget="' . $helpId . '"]')->length === 1,
            'Each field keeps an associated label and native contextual help.',
        );
    }
    tag_layout_assert(
        $page->query('//script|//b')->length === 0
            && $page->query('//a[@href="/gallery" and @target="_blank" and @rel="noopener"]')->length === 1
            && $page->query('//a[@data-gallery-side-panel-url="/image-editor?panel=1"]')->length === 1,
        'Untrusted tag/usage text stays escaped and existing safe links survive.',
    );
    $panel = $render($model + ['panel' => true]);
    foreach ($panel->query('//*[@id]') as $element) {
        tag_layout_assert(
            $page->query('//*[@id="' . $element->getAttribute('id') . '"]')->length === 0,
            'Injected tag panels must not collide with full-page field/help IDs.',
        );
    }
    $music = $render(array_replace($model, [
        'media_type' => 'music',
        'tags' => [$tag + [
            'media_type' => 'music', 'edit_url' => '/edit-music-tag',
            'usage_count' => 2,
        ]],
        'usage' => ['songs' => [['title' => 'Song', 'edit_url' => '/song-editor']]],
        'selected_tag' => $tag + ['media_type' => 'music'],
        'form' => array_replace($form, [
            'media_type' => 'music', 'public_url' => '', 'theme_url' => '',
        ]),
    ]));
    tag_layout_assert(
        $music->query('//a[@href="/tag/wing-url" or @href="/theme/tags"]')->length === 0
            && str_contains($music->document->documentElement->textContent, 'Internal identifier for this music tag.')
            && $music->query('//a[@href="/song-editor"]')->length === 1,
        'Music tags retain internal help and omit public-only actions.',
    );
    $gallery = $render(array_replace($model, [
        'media_type' => 'gallery',
        'form' => $form + ['filter_value' => 'gallery'],
    ]));
    tag_layout_assert(
        $gallery->query('//select[@name="media_type"]/option[@value="gallery" and @selected]')->length === 1
            && $gallery->query('//option[@value="documents" and @disabled]')->length === 1
            && $gallery->query('//form[@method="post"]//input[@name="catalog_filter" and @value="gallery"]')->length === 1
            && $gallery->query('//a[@href="/tag/wing-url"]')->length === 1
            && $gallery->query('//form[@method="post"]//input[@name="media_type" and @value="image"]')->length === 1,
        'Gallery filters retain POST navigation context and public visual-tag actions while PDF stays reserved.',
    );
    $documents = $render(['media_type' => 'documents', 'tags' => []]);
    tag_layout_assert(
        str_contains($documents->document->documentElement->textContent, 'PDF document tagging is planned.')
            && $documents->query('//form[@method="post"]')->length === 0,
        'Direct reserved Documents requests explain their pending state without an editor.',
    );
    $empty = $render(['tags' => [], 'selected_tag' => null]);
    tag_layout_assert(
        $empty->query('//*[@class="admin-tags-layout"]/div/section/h2')->length === 1
            && $empty->query('//form[@method="post"]')->length === 0,
        'Empty catalogs retain a contained no-selection state without a fake editor.',
    );
    echo "Admin tag layout and native forms: PASS\n";
}
