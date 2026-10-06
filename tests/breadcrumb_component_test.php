<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/breadcrumb_component_test.php
 * Module Type: Regression Test
 * Purpose: Protect breadcrumb style resolution, view-model normalization, and semantic rendering.
 * Responsibilities: Exercise the real shared service and view with isolated translation and setting stubs.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Escape one value using the application-compatible HTML escaping policy.
     *
     * @param scalar|array<array-key,mixed>|object|resource|null $value Value to encode for HTML output.
     * @return string Escaped HTML text or attribute value.
     */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

namespace Gallery\Services {
    /**
     * Return the provided translation fallback for this isolated component test.
     *
     * @param string $key Translation key requested by the component.
     * @param string|array|null $fallback Fallback text or interpolation data.
     * @param array<string,scalar|null> $parameters Optional interpolation parameters.
     * @return string Test translation text.
     */
    function t(string $key, string|array|null $fallback = null, array $parameters = []): string
    {
        return is_string($fallback) ? $fallback : $key;
    }

    /**
     * Read one isolated application setting while recording the requested key.
     *
     * @param string $key Setting key requested by the service.
     * @param ?string $default Default value used when no test value is configured.
     * @return ?string Configured test value or its default.
     */
    function app_setting(string $key, ?string $default = null): ?string
    {
        $settings = $GLOBALS['breadcrumbTestSettings'] ?? [];
        $GLOBALS['breadcrumbTestSettingReads'][] = $key;
        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    /**
     * Persist one setting value in the isolated test map.
     *
     * @param string $key Setting key being changed.
     * @param string $value Value assigned to the setting.
     * @return void
     */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['breadcrumbTestSettings'][$key] = $value;
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/services/breadcrumbs.php';
    require_once dirname(__DIR__) . '/app/views/breadcrumbs.php';

    /**
     * Assert one breadcrumb component behavior.
     *
     * @param bool $condition Whether the expectation passed.
     * @param string $message Failure description.
     * @return void
     */
    function breadcrumb_component_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $GLOBALS['breadcrumbTestSettings'] = [];
    $GLOBALS['breadcrumbTestSettingReads'] = [];

    $registry = \Gallery\Services\breadcrumb_style_registry();
    $styleIds = ['minimal', 'chevron', 'pills', 'surface', 'ribbon', 'nodes', 'tabs', 'tiles', 'gradient'];
    breadcrumb_component_assert(array_keys($registry) === $styleIds, 'The shared style registry must expose all nine stable IDs in order.');
    breadcrumb_component_assert(\Gallery\Services\BREADCRUMB_STYLE_DEFAULT === 'chevron', 'The theme default must have one shared stable ID.');
    breadcrumb_component_assert(\Gallery\Services\BREADCRUMB_STYLE_INHERIT === 'inherit', 'The gallery inheritance sentinel must have one shared stable ID.');
    foreach ($styleIds as $styleId) {
        breadcrumb_component_assert($registry[$styleId]['label'] !== '', 'Every style must have a translated label.');
        breadcrumb_component_assert($registry[$styleId]['class'] === 'breadcrumbs--' . $styleId, 'Every style must have its stable CSS modifier class.');
    }

    $readsBeforePickerPreparation = count($GLOBALS['breadcrumbTestSettingReads']);
    $galleryPickerOptions = \Gallery\Services\breadcrumb_style_picker_options(true, 'gradient');
    breadcrumb_component_assert(count($galleryPickerOptions) === count($styleIds) + 1, 'Gallery picker must include inheritance and all registered styles.');
    breadcrumb_component_assert($galleryPickerOptions[0]['value'] === 'inherit'
        && str_contains($galleryPickerOptions[0]['label'], $registry['gradient']['label'])
        && $galleryPickerOptions[0]['preview']['style_class'] === $registry['gradient']['class'],
        'The inherited picker preview must show the provided Theme style and label.');
    foreach ($styleIds as $index => $styleId) {
        $option = $galleryPickerOptions[$index + 1];
        breadcrumb_component_assert($option['value'] === $styleId
            && $option['label'] === $registry[$styleId]['label']
            && $option['preview']['style_class'] === $registry[$styleId]['class']
            && count($option['preview']['items']) === 3,
            'Each radio-card preview must map a registered ID to its translated label and real class: ' . $styleId);
    }
    $themePickerOptions = \Gallery\Services\breadcrumb_style_picker_options(false, 'gradient');
    breadcrumb_component_assert(array_column($themePickerOptions, 'value') === $styleIds,
        'Theme picker must expose the nine registered styles without a gallery inheritance choice.');
    breadcrumb_component_assert(count($GLOBALS['breadcrumbTestSettingReads']) === $readsBeforePickerPreparation,
        'Picker preparation with an explicit Theme style must not read persisted settings.');

    foreach ($styleIds as $styleId) {
        breadcrumb_component_assert(\Gallery\Services\breadcrumb_style_normalize($styleId) === $styleId, 'A registered style must normalize unchanged.');
        breadcrumb_component_assert(\Gallery\Services\breadcrumb_style_resolve($styleId, 'surface') === $styleId, 'Every registered gallery style must resolve as an explicit override.');
    }
    $pickerViewModel = [
        'field_name' => 'gallery_breadcrumb_style',
        'label' => 'Breadcrumb style',
        'current' => 'inherit',
        'options' => $galleryPickerOptions,
    ];
    ob_start();
    \Gallery\Views\view_render_breadcrumb_style_picker($pickerViewModel);
    $pickerMarkup = (string) ob_get_clean();
    breadcrumb_component_assert(str_contains($pickerMarkup, 'type="radio" name="gallery_breadcrumb_style" value="inherit" checked'),
        'Picker rendering must preserve the canonical form name and selected inherit state.');
    breadcrumb_component_assert(substr_count($pickerMarkup, 'class="breadcrumb-style-picker__option"') === count($styleIds) + 1,
        'Picker rendering must produce one selectable card for inherit and each registered style.');
    breadcrumb_component_assert(substr_count($pickerMarkup, 'aria-hidden="true"') === count($styleIds) + 1
        && !str_contains($pickerMarkup, '<a') && !str_contains($pickerMarkup, '<nav'),
        'Picker examples must remain decorative spans with no nested links or navigation.');
    breadcrumb_component_assert(\Gallery\Services\breadcrumb_style_normalize('obsolete') === 'chevron', 'Unknown theme styles must use chevron.');
    breadcrumb_component_assert(\Gallery\Services\breadcrumb_style_normalize([]) === 'chevron', 'Invalid theme-style types must use chevron.');
    breadcrumb_component_assert(\Gallery\Services\breadcrumb_style_normalize('obsolete', true) === 'inherit', 'Unknown gallery overrides must become inherit.');
    breadcrumb_component_assert(\Gallery\Services\breadcrumb_style_normalize(null, true) === 'inherit', 'Missing gallery overrides must become inherit.');
    breadcrumb_component_assert(\Gallery\Services\breadcrumb_style_normalize(new stdClass(), true) === 'inherit', 'Invalid gallery override types must become inherit.');
    breadcrumb_component_assert(\Gallery\Services\breadcrumb_style_normalize(' INHERIT ', true) === 'inherit', 'The gallery inherit sentinel must be case-insensitive and trimmed.');

    breadcrumb_component_assert(\Gallery\Services\breadcrumb_style_resolve('pills', 'surface') === 'pills', 'An explicit gallery style must take precedence.');
    breadcrumb_component_assert(\Gallery\Services\breadcrumb_style_resolve('inherit', 'surface') === 'surface', 'Inherit must resolve to the theme style.');
    breadcrumb_component_assert(\Gallery\Services\breadcrumb_style_resolve('obsolete', 'surface') === 'surface', 'An invalid gallery override must inherit the valid theme style.');
    breadcrumb_component_assert(\Gallery\Services\breadcrumb_style_resolve(null, 'pills') === 'pills', 'A missing gallery override must inherit the theme style.');
    breadcrumb_component_assert(\Gallery\Services\breadcrumb_style_resolve('inherit', 'obsolete') === 'chevron', 'Invalid theme values must resolve to chevron.');

    $GLOBALS['breadcrumbTestSettings'] = [
        'theme_breadcrumb_style' => 'surface',
        'gallery_breadcrumb_style.73' => 'pills',
        'gallery_breadcrumb_style.91' => 'obsolete',
    ];
    breadcrumb_component_assert(\Gallery\Services\theme_breadcrumb_style() === 'surface', 'The theme reader must load the stored style.');
    breadcrumb_component_assert(\Gallery\Services\gallery_breadcrumb_style_override(73) === 'pills', 'The gallery reader must load the stored override.');
    breadcrumb_component_assert(\Gallery\Services\gallery_breadcrumb_style_override(0) === 'inherit', 'Invalid gallery IDs must not read a physical setting.');
    breadcrumb_component_assert(\Gallery\Services\gallery_breadcrumb_style(['id' => 73]) === 'pills', 'A physical gallery must use its explicit override.');
    breadcrumb_component_assert(\Gallery\Services\gallery_breadcrumb_style(['id' => 91]) === 'surface', 'An obsolete gallery value must inherit the theme.');
    breadcrumb_component_assert(\Gallery\Services\gallery_breadcrumb_style(null) === 'surface', 'The root context must use the theme.');

    breadcrumb_component_assert(\Gallery\Services\gallery_breadcrumb_style_validate('inherit') === 'inherit', 'Gallery validation must accept the inherit sentinel.');
    breadcrumb_component_assert(\Gallery\Services\gallery_breadcrumb_style_validate('surface') === 'surface', 'Gallery validation must accept registered styles.');
    $invalidGalleryStyleRejected = false;
    try {
        \Gallery\Services\gallery_breadcrumb_style_validate('unknown');
    } catch (InvalidArgumentException) {
        $invalidGalleryStyleRejected = true;
    }
    breadcrumb_component_assert($invalidGalleryStyleRejected, 'Gallery validation must reject obsolete submitted values.');
    breadcrumb_component_assert(\Gallery\Services\gallery_breadcrumb_style_save(73, 'minimal') === 'minimal', 'Saving must return the normalized style.');
    breadcrumb_component_assert($GLOBALS['breadcrumbTestSettings']['gallery_breadcrumb_style.73'] === 'minimal', 'Saving must persist under the physical gallery setting key.');
    $beforeInvalidSave = $GLOBALS['breadcrumbTestSettings'];
    $invalidSaveRejected = false;
    try {
        \Gallery\Services\gallery_breadcrumb_style_save(73, 'obsolete');
    } catch (InvalidArgumentException) {
        $invalidSaveRejected = true;
    }
    breadcrumb_component_assert($invalidSaveRejected && $GLOBALS['breadcrumbTestSettings'] === $beforeInvalidSave, 'An invalid save must fail before changing settings.');

    $items = [
        ['label' => 'Home & <archive>', 'url' => '/'],
        ['label' => 'Parent "one"', 'url' => '/gallery/parent?x=1&y=2'],
        ['label' => 'Current <place>', 'current' => true, 'url' => '/ignored-current-url'],
        ['label' => ''],
        'invalid item',
    ];
    $viewModel = \Gallery\Services\breadcrumb_view_model($items, 'ribbon', 'Path & place');
    breadcrumb_component_assert($viewModel['aria_label'] === 'Path & place', 'The view model must preserve its accessible label.');
    breadcrumb_component_assert($viewModel['style_class'] === 'breadcrumbs--ribbon', 'The view model must take its class from the registry.');
    breadcrumb_component_assert(count($viewModel['items']) === 3, 'The view model must skip malformed and empty-label items.');
    breadcrumb_component_assert($viewModel['items'][1]['url'] === '/gallery/parent?x=1&y=2', 'The view model must preserve the supplied URL exactly.');

    ob_start();
    \Gallery\Views\view_render_breadcrumbs($viewModel);
    $markup = (string) ob_get_clean();
    breadcrumb_component_assert(str_contains($markup, '<nav class="breadcrumbs breadcrumbs--ribbon" aria-label="Path &amp; place">'), 'The renderer must output the accessible navigation wrapper with its selected graphic preset.');
    breadcrumb_component_assert(str_contains($markup, '<ol class="breadcrumbs__list">'), 'The renderer must use an ordered list.');
    breadcrumb_component_assert(str_contains($markup, '<li class="breadcrumbs__item"><a class="breadcrumbs__link" href="/">Home &amp; &lt;archive&gt;</a></li>'), 'The renderer must preserve the root link and escape its label.');
    breadcrumb_component_assert(str_contains($markup, 'href="/gallery/parent?x=1&amp;y=2"'), 'The renderer must preserve and escape a deep-path URL.');
    breadcrumb_component_assert(str_contains($markup, '<span class="breadcrumbs__current" aria-current="page">Current &lt;place&gt;</span>'), 'The current page must be a non-link with aria-current.');
    breadcrumb_component_assert(!str_contains($markup, 'ignored-current-url'), 'A current breadcrumb must not render as a link.');
    breadcrumb_component_assert(substr_count($markup, '<li class="breadcrumbs__item">') === 3, 'Every valid item must render in the ordered list.');
    foreach ($styleIds as $styleId) {
        $styledViewModel = \Gallery\Services\breadcrumb_view_model($items, $styleId, 'Path & place');
        breadcrumb_component_assert($styledViewModel['style_class'] === 'breadcrumbs--' . $styleId, 'Every registered preset must render through its canonical class.');
        ob_start();
        \Gallery\Views\view_render_breadcrumbs($styledViewModel);
        $styledMarkup = (string) ob_get_clean();
        breadcrumb_component_assert(str_contains($styledMarkup, 'class="breadcrumbs breadcrumbs--' . $styleId . '"'), 'The renderer omitted a registered style modifier: ' . $styleId);
    }
    $breadcrumbCss = (string) file_get_contents(dirname(__DIR__) . '/public/assets/styles/breadcrumbs.css');
    breadcrumb_component_assert(str_contains($breadcrumbCss, '.breadcrumbs__link:focus-visible') && str_contains($breadcrumbCss, 'outline: 2px solid'), 'The shared stylesheet must retain a visible keyboard-focus treatment.');

    $smartGalleryController = (string) file_get_contents(dirname(__DIR__) . '/app/controllers/smart_galleries.php');
    $smartBreadcrumbStart = strpos($smartGalleryController, "'breadcrumbs' => \\Gallery\\Services\\breadcrumb_view_model(");
    breadcrumb_component_assert($smartBreadcrumbStart !== false, 'The public Smart Gallery page must supply its breadcrumb view model.');
    $smartBreadcrumbContract = substr($smartGalleryController, $smartBreadcrumbStart, 600);
    breadcrumb_component_assert(str_contains($smartBreadcrumbContract, "\$presentation['breadcrumb_style']"), 'Smart Gallery breadcrumbs must use the effective Smart Gallery style preference.');
    breadcrumb_component_assert(!str_contains($smartBreadcrumbContract, '\\Gallery\\Services\\gallery_breadcrumb_style('), 'A Smart Gallery ID must never be read as a physical gallery style key.');

    fwrite(STDOUT, "Breadcrumb component checks passed.\n");
}
