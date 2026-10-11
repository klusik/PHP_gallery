<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_content_widget_editor_localization_test.php
 * Module Type: Localization Regression Test
 * Purpose: Verify translated values in the public widget Admin editor and placement controls.
 * Responsibilities:
 *   - Render the real editor against each maintained EN/CS/DE/SV catalog.
 *   - Keep source-language choices and widget-list placement summaries localized.
 *   - Prove placement, appearance, and mobile-fallback options use catalog values.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Escape one editor value for an HTML text or attribute context.
     *
     * @param string|int|float|bool|null $value Scalar content emitted by the Admin view.
     * @return string HTML-escaped scalar text, or empty text for null.
     */
    function e(string|int|float|bool|null $value): string
    {
        return htmlspecialchars($value === null ? '' : (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

namespace Gallery\Services {
    /**
     * Resolve one Admin label from the test-selected maintained catalog.
     *
     * @param string $key Flat translation catalog key.
     * @param string|null $default Optional English fallback label supplied by the view.
     * @return string Catalog translation or the explicit English fallback.
     */
    function t(string $key, ?string $default = null): string
    {
        $catalog = $GLOBALS['public_widget_editor_locale_test_catalog'] ?? [];
        $value = is_array($catalog) ? ($catalog[$key] ?? null) : null;
        if (is_string($value)) {
            return $value;
        }
        return $default ?? $key;
    }
}

namespace {
    use function Gallery\Views\view_render_admin_public_widgets;

    $root = dirname(__DIR__);
    require_once $root . '/app/views/admin_public_widgets.php';
    $english = json_decode((string) file_get_contents($root . '/app/lang/en.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($english)) {
        throw new \RuntimeException('English widget editor catalog did not decode to a dictionary.');
    }

    $languages = ['en', 'cs', 'de', 'sv'];
    $sourceLanguageKeys = [
        'en' => 'admin.widgets.language.en',
        'cs' => 'admin.widgets.language.cs',
        'de' => 'admin.widgets.language.de',
        'sv' => 'admin.widgets.language.sv',
    ];
    $optionKeys = [
        'page_scope' => [
            'home' => 'admin.widgets.scope.home',
            'gallery' => 'admin.widgets.scope.gallery',
            'all' => 'admin.widgets.scope.all',
        ],
        'placement_mode' => [
            'flow' => 'admin.widgets.mode.flow',
            'floating' => 'admin.widgets.mode.floating',
        ],
        'flow_slot' => [
            'content_top' => 'admin.widgets.slot.content_top',
            'content_bottom' => 'admin.widgets.slot.content_bottom',
            'left_rail' => 'admin.widgets.slot.left_rail',
            'right_rail' => 'admin.widgets.slot.right_rail',
            'home_before_grid' => 'admin.widgets.slot.home_before_grid',
            'home_after_grid' => 'admin.widgets.slot.home_after_grid',
            'footer' => 'admin.widgets.slot.footer',
        ],
        'floating_anchor' => [
            'top-left' => 'admin.widgets.anchor.top-left',
            'top-center' => 'admin.widgets.anchor.top-center',
            'top-right' => 'admin.widgets.anchor.top-right',
            'middle-left' => 'admin.widgets.anchor.middle-left',
            'middle-right' => 'admin.widgets.anchor.middle-right',
            'bottom-left' => 'admin.widgets.anchor.bottom-left',
            'bottom-center' => 'admin.widgets.anchor.bottom-center',
            'bottom-right' => 'admin.widgets.anchor.bottom-right',
            'custom' => 'admin.widgets.anchor.custom',
        ],
        'appearance' => [
            'card' => 'admin.widgets.appearance.card',
            'minimal' => 'admin.widgets.appearance.minimal',
        ],
        'mobile_fallback' => [
            'flow' => 'admin.widgets.mobile_flow',
        ],
        'source_language' => $sourceLanguageKeys,
    ];
    $labelKeys = [
        'page_scope' => 'admin.widgets.field.page_scope',
        'placement_mode' => 'admin.widgets.field.placement_mode',
        'flow_slot' => 'admin.widgets.field.flow_slot',
        'floating_anchor' => 'admin.widgets.field.floating_anchor',
        'appearance' => 'admin.widgets.field.appearance',
        'mobile_fallback' => 'admin.widgets.field.mobile_fallback',
        'source_language' => 'admin.widgets.field.source_language',
    ];

    foreach ($languages as $language) {
        $path = $root . '/app/lang/' . $language . '.json';
        $catalog = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($catalog)) {
            throw new \RuntimeException('Widget editor catalog did not decode for ' . $language . '.');
        }
        $GLOBALS['public_widget_editor_locale_test_catalog'] = $catalog;
        ob_start();
        view_render_admin_public_widgets([
            'rows' => [[
                'widget_id' => '0123456789abcdef0123456789abcdef',
                'title' => 'Localization fixture',
                'status' => 'published',
                'page_scope' => 'gallery',
                'placement_mode' => 'floating',
                'edit_url' => '/admin.php?widget=0123456789abcdef0123456789abcdef',
                'revision' => 1,
            ]],
            'selected' => null,
            'draft' => [
                'status' => 'draft',
                'page_scope' => 'all',
                'placement_mode' => 'flow',
                'flow_slot' => 'home_after_grid',
                'floating_anchor' => 'bottom-right',
                'appearance' => 'card',
                'mobile_fallback' => 'flow',
                'source_language' => 'en',
                'x_permille' => 900,
                'y_permille' => 900,
                'width_px' => 320,
                'sort_order' => 0,
            ],
            'editor_url' => '/admin.php?widget=editor',
            'theme_url' => '/admin.php?theme=1',
            'settings_url' => '/admin.php?settings=1',
            'new_url' => '/admin.php?widget=new',
            'discard_url' => '/admin.php?widget=discard',
            'preview_home_url' => '/?widget_preview=home',
            'preview_gallery_url' => '/gallery/example?widget_preview=gallery',
            'preview_html' => '',
        ]);
        $html = (string) ob_get_clean();

        foreach ($labelKeys as $field => $key) {
            $label = htmlspecialchars((string) ($catalog[$key] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $expectedPrefix = '<label for="public-widget-' . $field . '">' . $label . '<select';
            if ($label === '' || !str_contains($html, $expectedPrefix)) {
                throw new \RuntimeException('Widget editor field label is not localized for ' . $language . ': ' . $field);
            }
        }

        foreach ($optionKeys as $field => $options) {
            $selectPattern = '/<select id="public-widget-' . preg_quote($field, '/') . '"(?:\s+[^>]*)?>(.*?)<\/select>/s';
            if (preg_match($selectPattern, $html, $selectMatch) !== 1) {
                throw new \RuntimeException('Widget editor select is missing for ' . $language . ': ' . $field);
            }
            foreach ($options as $value => $key) {
                $translated = (string) ($catalog[$key] ?? '');
                $optionPattern = '/<option value="' . preg_quote((string) $value, '/') . '"(?: selected)?>(.*?)<\/option>/s';
                if ($translated === '' || preg_match($optionPattern, $selectMatch[1], $optionMatch) !== 1) {
                    throw new \RuntimeException('Widget editor option is missing for ' . $language . ': ' . $key);
                }
                $renderedText = html_entity_decode($optionMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($renderedText !== $translated) {
                    throw new \RuntimeException('Widget editor option is not translated for ' . $language . ': ' . $key);
                }
            }
        }

        $summary = '<small>'
            . htmlspecialchars((string) ($catalog['admin.widgets.state.published'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . ' · '
            . htmlspecialchars((string) ($catalog['admin.widgets.scope.gallery'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . ' · '
            . htmlspecialchars((string) ($catalog['admin.widgets.mode.floating'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</small>';
        if (!str_contains($html, $summary)) {
            throw new \RuntimeException('Widget list placement summary is not localized for ' . $language . '.');
        }
        if ($language !== 'en') {
            foreach ($sourceLanguageKeys as $key) {
                if (($catalog[$key] ?? null) === ($english[$key] ?? null)) {
                    throw new \RuntimeException('English source-language label leaked into ' . $language . ': ' . $key);
                }
            }
        }
    }

    unset($GLOBALS['public_widget_editor_locale_test_catalog']);
    echo "public_content_widget_editor_localization_test: PASS\n";
}
