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
 *   - Verify Theme-preview copy explains eligible Gallery markup, simulated floating placement and flow fallback.
 *   - Verify browser placement/status messages use localized catalog values.
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
    $themePreviewTextKeys = [
        'title' => 'admin.widgets.preview_theme_title',
        'hint' => 'admin.widgets.preview_theme_hint',
    ];
    $themePreviewCopyRequirements = [
        'en' => [
            'admin.widgets.preview_theme_hint' => [
                'Home page', 'when available', 'public root Gallery', 'anonymously accessible',
                'actual markup and Theme CSS', 'Scripts and forms are disabled',
                'sanitized unsaved draft', 'nothing is saved',
            ],
            'admin.widgets.preview_theme_floating' => [
                'simulated', 'preview geometry', 'safe position', 'normal page-flow zone',
            ],
            'admin.widgets.preview_theme_ready' => [
                'selected public page', 'unsaved draft', 'simulated', 'not saved',
            ],
        ],
        'cs' => [
            'admin.widgets.preview_theme_hint' => [
                'veřejné úvodní stránky', 'pokud je dostupná', 'veřejné kořenové galerie',
                'anonymním návštěvníkům', 'skutečné rozhraní a CSS motivu',
                'Skripty a formuláře jsou vypnuté', 'sanitovaný neuložený návrh', 'nic se neukládá',
            ],
            'admin.widgets.preview_theme_floating' => [
                'simuluje', 'geometrie náhledu', 'bezpečnou polohu', 'běžné oblasti toku stránky',
            ],
            'admin.widgets.preview_theme_ready' => [
                'vybrané veřejné stránky', 'neuložený návrh', 'simulované', 'neukládají',
            ],
        ],
        'de' => [
            'admin.widgets.preview_theme_hint' => [
                'öffentlichen Startseite', 'sofern verfügbar', 'öffentlichen Root-Galerie',
                'anonymen Besuchern zugänglich', 'echte Markup und Theme-CSS',
                'Skripte und Formulare sind deaktiviert', 'bereinigte, ungespeicherte Entwurf',
                'nichts wird gespeichert',
            ],
            'admin.widgets.preview_theme_floating' => [
                'simuliert', 'Geometrie der Vorschau', 'sichere Position', 'normalen Seitenflussbereich',
            ],
            'admin.widgets.preview_theme_ready' => [
                'ausgewählten öffentlichen Seite', 'ungespeicherte Entwurf', 'simuliert', 'nicht gespeichert',
            ],
        ],
        'sv' => [
            'admin.widgets.preview_theme_hint' => [
                'offentliga startsidan', 'när det finns ett sådant', 'offentligt rotgalleri',
                'tillgängligt för anonyma besökare', 'faktiska HTML-strukturen och temats CSS',
                'Skript och formulär är avstängda', 'sanerade, osparade utkastet', 'inget sparas',
            ],
            'admin.widgets.preview_theme_floating' => [
                'simuleras', 'förhandsvisningens geometri', 'säker position', 'vanliga sidflödesområde',
            ],
            'admin.widgets.preview_theme_ready' => [
                'valda offentliga sidan', 'osparade utkastet', 'simuleras', 'sparas inte',
            ],
        ],
    ];
    $themePreviewBrowserLabelKeys = [
        'warning_fallback' => 'admin.widgets.preview_warning_fallback',
        'warning_header' => 'admin.widgets.preview_warning_header',
        'warning_edge' => 'admin.widgets.preview_warning_edge',
        'warning_collision' => 'admin.widgets.preview_warning_collision',
        'warning_limit' => 'admin.widgets.preview_warning_limit',
        'warning_invalid' => 'admin.widgets.preview_warning_invalid',
        'warning_disclaimer' => 'admin.widgets.preview_warning_disclaimer',
        'theme_loading' => 'admin.widgets.preview_theme_loading',
        'theme_unavailable' => 'admin.widgets.preview_theme_unavailable',
        'theme_gallery' => 'admin.widgets.preview_theme_gallery',
        'theme_floating' => 'admin.widgets.preview_theme_floating',
        'theme_ready' => 'admin.widgets.preview_theme_ready',
    ];

    foreach ($languages as $language) {
        $path = $root . '/app/lang/' . $language . '.json';
        $catalog = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($catalog)) {
            throw new \RuntimeException('Widget editor catalog did not decode for ' . $language . '.');
        }
        foreach ($themePreviewCopyRequirements[$language] as $key => $requiredFragments) {
            $translated = $catalog[$key] ?? null;
            foreach ($requiredFragments as $fragment) {
                if (!is_string($translated) || !str_contains($translated, $fragment)) {
                    throw new \RuntimeException('Theme preview copy omits required meaning for ' . $language . ': ' . $key);
                }
            }
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

        foreach ($themePreviewTextKeys as $label => $key) {
            $translated = (string) ($catalog[$key] ?? '');
            $tag = $label === 'title' ? 'h3' : 'p class="muted"';
            $expected = '<' . $tag . '>'
                . htmlspecialchars($translated, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</' . ($label === 'title' ? 'h3' : 'p') . '>';
            if ($translated === '' || !str_contains($html, $expected)) {
                throw new RuntimeException('Theme preview copy is not localized for ' . $language . ': ' . $key);
            }
        }

        if (!str_contains($html, '<p role="status" data-widget-theme-status></p>')
            || !str_contains($html, '<div class="public-widgets-theme-frame-wrap" data-widget-theme-frame-wrap hidden></div>')) {
            throw new \RuntimeException('Theme preview status or frame container is missing for ' . $language . '.');
        }

        if (preg_match('/data-widget-i18n="([^"]*)"/', $html, $widgetI18nMatch) !== 1) {
            throw new \RuntimeException('Widget editor browser labels are missing for ' . $language . '.');
        }
        $browserLabelsJson = html_entity_decode($widgetI18nMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $browserLabels = json_decode($browserLabelsJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($browserLabels)) {
            throw new \RuntimeException('Widget editor browser labels did not decode for ' . $language . '.');
        }
        foreach ($themePreviewBrowserLabelKeys as $label => $key) {
            $translated = $catalog[$key] ?? null;
            if (!is_string($translated) || $translated === '' || ($browserLabels[$label] ?? null) !== $translated) {
                throw new \RuntimeException('Theme preview browser label is not localized for ' . $language . ': ' . $key);
            }
        }

        if ($language !== 'en') {
            foreach (array_unique(array_merge(array_values($themePreviewTextKeys), array_values($themePreviewBrowserLabelKeys))) as $key) {
                if (($catalog[$key] ?? null) === ($english[$key] ?? null)) {
                    throw new \RuntimeException('English Theme preview text leaked into ' . $language . ': ' . $key);
                }
            }
        }

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
