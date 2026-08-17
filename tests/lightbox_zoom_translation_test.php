<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/lightbox_zoom_translation_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies complete maintained-language coverage for public lightbox zoom controls.
 *
 * Responsibilities:
 *   - Require labels, reset text, status placeholder, and updated shortcut help
 *   - Keep English, Czech, German, and Swedish catalogs aligned for this feature
 *   - Confirm the browser export includes catalog strings rather than a separate list
 *
 * Last Updated:
 *   2026-08-16
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$languages = ['en', 'cs', 'de', 'sv'];
$requiredKeys = [
    'lightbox.zoom_controls',
    'lightbox.zoom_in',
    'lightbox.zoom_out',
    'lightbox.zoom_reset',
    'lightbox.zoom_status',
    'lightbox.quality_loading',
    'lightbox.help_shortcuts',
];

/**
 * Throw when a zoom translation expectation is absent.
 *
 * @param bool $condition Assertion result.
 * @param string $message Failure diagnostic.
 */
function lightbox_zoom_translation_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach ($languages as $language) {
    $catalogJson = (string) file_get_contents($root . '/app/lang/' . $language . '.json');
    $catalog = json_decode($catalogJson, true, 512, JSON_THROW_ON_ERROR);
    foreach ($requiredKeys as $key) {
        lightbox_zoom_translation_assert(
            isset($catalog[$key]) && is_string($catalog[$key]) && trim($catalog[$key]) !== '',
            $language . ' catalog is missing ' . $key . '.'
        );
    }
    lightbox_zoom_translation_assert(
        str_contains($catalog['lightbox.zoom_status'], '{percent}'),
        $language . ' zoom status must retain the {percent} placeholder.'
    );
    lightbox_zoom_translation_assert(
        str_contains($catalog['lightbox.help_shortcuts'], '+/−') && str_contains($catalog['lightbox.help_shortcuts'], '0'),
        $language . ' shortcut help must describe zoom and reset keys.'
    );
}

$assetRendererSource = (string) file_get_contents($root . '/app/helpers_page_rendering.php');
$lightboxMarkupSource = (string) file_get_contents($root . '/app/controllers/public_gallery_lightbox.php');
$lightboxBrowserSource = (string) file_get_contents($root . '/public/assets/gallery-modules/lightbox.js');
lightbox_zoom_translation_assert(
    str_contains($assetRendererSource, 'array_merge($defaultStrings, $activeStrings)'),
    'Browser localization must continue exporting the active catalog with English fallback.'
);
lightbox_zoom_translation_assert(
    str_contains($lightboxMarkupSource, "t('lightbox.zoom_status', 'Zoom {percent}', ['percent' => '100%'])"),
    'The initial assistive status must replace {percent} through the translation helper before escaping.'
);
lightbox_zoom_translation_assert(
    str_contains($lightboxBrowserSource, "i18n('lightbox.quality_loading', 'Loading full-quality image...')"),
    'The background quality indicator must use the browser translation catalog with a safe fallback.'
);

echo "Lightbox zoom translation checks passed.\n";
