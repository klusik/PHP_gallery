<?php

/**
 * Project: PHP Gallery
 * Module Type: Regression Test
 * Purpose: Verify Smart Gallery presentation inheritance.
 * Responsibilities:
 *   - Exercise defaults, overrides and display guardrails.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/smart_gallery_presentation_test.php
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */
/** Regression tests for Smart Gallery presentation defaults, overrides, and guardrails. */

declare(strict_types=1);

namespace Gallery\Services {
    const CMS_PAGINATION_DEFAULT_COLUMNS = 4;
    const CMS_PAGINATION_DEFAULT_ROWS = 6;
    const CMS_PAGINATION_MAX_COLUMNS = 12;
    const CMS_PAGINATION_MAX_ROWS = 50;

    /** Return deterministic site defaults for this isolated presentation test. */
    function pagination_global_settings(?array $context = null): array
    {
        unset($context);
        return ['enabled' => true, 'columns' => 5, 'rows' => 7, 'items_per_page' => 35];
    }

    /** Normalize an integer pagination dimension. */
    function pagination_dimension_value(mixed $value, int $default, int $maximum): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $maximum]]);
        return $number === false ? $default : (int) $number;
    }

    /** Return the isolated configured feature state for deterministic presentation tests. */
    function feature_flag_enabled(string $feature): bool
    {
        return (bool) ($GLOBALS['smart_gallery_feature_states'][$feature] ?? true);
    }

    /** Return the isolated effective feature state for deterministic presentation tests. */
    function feature_capability_effective_enabled(string $feature): bool
    {
        return feature_flag_enabled($feature);
    }

    /** Return the configured site renderer default. */
    function public_thumbnail_rendering_mode(): string
    {
        return 'progressive';
    }

    /** Return the supported public thumbnail renderers. */
    function public_thumbnail_rendering_modes(): array
    {
        return ['responsive', 'progressive'];
    }

    /** Normalize renderer values like the production helper. */
    function public_thumbnail_rendering_mode_normalize(mixed $value): string
    {
        return is_string($value) && in_array(trim($value), public_thumbnail_rendering_modes(), true) ? trim($value) : 'progressive';
    }

    /** Return the configured site lightbox browsing default. */
    function theme_lightbox_browsing_mode(): string
    {
        return 'carousel';
    }

    /** Return the configured Theme gallery-card layout default. */
    function theme_gallery_description_layout(): string
    {
        return 'horizontal';
    }

    /** Return the configured breadcrumb Theme default for this presentation test.
     * @return string Isolated configured style value.
     */
    function theme_breadcrumb_style(): string
    {
        return (string) ($GLOBALS['smart_gallery_breadcrumb_theme_style'] ?? 'chevron');
    }

    /** Return the shared breadcrumb style identifiers needed by this isolated test.
     * @return array<string,array{label:string,class:string}> Test style metadata.
     */
    function breadcrumb_style_registry(): array
    {
        return [
            'minimal' => ['label' => 'Minimal', 'class' => 'breadcrumbs--minimal'],
            'chevron' => ['label' => 'Chevron', 'class' => 'breadcrumbs--chevron'],
            'pills' => ['label' => 'Pills', 'class' => 'breadcrumbs--pills'],
            'surface' => ['label' => 'Surface', 'class' => 'breadcrumbs--surface'],
            'ribbon' => ['label' => 'Ribbon', 'class' => 'breadcrumbs--ribbon'],
            'nodes' => ['label' => 'Connected nodes', 'class' => 'breadcrumbs--nodes'],
            'tabs' => ['label' => 'Tabs', 'class' => 'breadcrumbs--tabs'],
            'tiles' => ['label' => 'Tiles', 'class' => 'breadcrumbs--tiles'],
            'gradient' => ['label' => 'Gradient', 'class' => 'breadcrumbs--gradient'],
        ];
    }

    /** Resolve a registered Smart Gallery override through the isolated Theme default.
     * @param string|null $galleryStyle Optional registered gallery override or inheritance sentinel.
     * @param string|null $themeStyle Theme style or invalid-value fixture.
     * @return string Supported resolved style ID.
     */
    function breadcrumb_style_resolve(mixed $galleryStyle = null, mixed $themeStyle = null): string
    {
        $styles = array_keys(breadcrumb_style_registry());
        $theme = is_string($themeStyle) && in_array($themeStyle, $styles, true) ? $themeStyle : 'chevron';
        return is_string($galleryStyle) && in_array($galleryStyle, $styles, true) ? $galleryStyle : $theme;
    }

    /** Normalize a persisted gallery-card layout override. */
    function gallery_description_layout_storage_value(mixed $value): ?string
    {
        $layout = strtolower(trim((string) $value));
        return in_array($layout, ['vertical', 'horizontal'], true) ? $layout : null;
    }

    /** Normalize an explicit lightbox browsing mode. */
    function gallery_lightbox_browsing_mode_storage_value(mixed $value): ?string
    {
        $mode = strtolower(trim((string) $value));
        if ($mode === 'strip') $mode = 'picture_strip';
        return in_array($mode, ['single', 'picture_strip', 'carousel'], true) ? $mode : null;
    }

    /** Return generated thumbnail candidates used by this test. */
    function thumbnail_sizes(): array
    {
        return [300, 600, 800, 1200, 1600];
    }

    /** Normalize a thumbnail bound against generated sizes. */
    function thumbnail_bound_post_value(mixed $value): ?int
    {
        $size = (int) ($value ?? 0);
        return in_array($size, thumbnail_sizes(), true) ? $size : null;
    }

    /** Apply a deterministic source-gallery thumbnail bound for the isolated test. */
    function thumbnail_bound_filter_sizes(array $sizes, array $image, ?array $gallery = null): array
    {
        unset($image);
        $min = thumbnail_bound_post_value($gallery['thumbnail_min_size'] ?? null);
        $max = thumbnail_bound_post_value($gallery['thumbnail_max_size'] ?? null);
        $filtered = array_values(array_filter($sizes, static fn (int $size): bool => ($min === null || $size >= $min) && ($max === null || $size <= $max)));
        return $filtered !== [] ? $filtered : $sizes;
    }

    require_once dirname(__DIR__) . '/app/services/smart_galleries.php';

    /** Fail this standalone test with a concise label. */
    function smart_gallery_presentation_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    $GLOBALS['smart_gallery_feature_states'] = [
        'lightbox_modes' => true,
        'downloads' => true,
        'image_voting' => true,
        'gallery_maps' => true,
    ];
    $GLOBALS['smart_gallery_breadcrumb_theme_style'] = 'chevron';

    $defaults = smart_gallery_presentation_defaults();
    smart_gallery_presentation_assert($defaults['grid_columns'] === 5 && $defaults['grid_rows'] === 7, 'Smart Gallery defaults inherit the current site grid.');
    smart_gallery_presentation_assert($defaults['thumbnail_rendering_mode'] === 'progressive', 'Smart Gallery defaults inherit the current site thumbnail renderer.');
    smart_gallery_presentation_assert($defaults['card_layout'] === 'horizontal', 'Smart Gallery defaults inherit the canonical Theme gallery-card layout.');
    smart_gallery_presentation_assert($defaults['breadcrumb_style'] === 'chevron', 'Smart Gallery breadcrumb style defaults inherit the Theme setting.');
    smart_gallery_presentation_assert($defaults['lightbox_browsing_mode'] === 'carousel', 'Smart Gallery defaults inherit the current Theme lightbox mode.');
    smart_gallery_presentation_assert($defaults['source_gallery_visible'] === true, 'Smart Gallery source-gallery context is visible by default.');
    smart_gallery_presentation_assert($defaults['map_enabled'] === true, 'Smart Gallery aggregate GPS maps are enabled by default before capability suppression.');

    $emptyEffective = smart_gallery_effective_presentation(['presentation_json' => null]);
    smart_gallery_presentation_assert($emptyEffective['grid_columns'] === 5 && $emptyEffective['items_per_page'] === 35, 'Missing presentation data inherits the site defaults.');
    smart_gallery_presentation_assert($emptyEffective['grid_source'] === 'theme', 'Missing presentation data reports Theme inheritance.');
    smart_gallery_presentation_assert($emptyEffective['breadcrumb_style'] === 'chevron', 'Missing Smart Gallery breadcrumb overrides use the Theme style.');

    $GLOBALS['smart_gallery_breadcrumb_theme_style'] = 'surface';
    $inheritedBreadcrumbStyle = smart_gallery_effective_presentation(['presentation_json' => '{"version":1}']);
    smart_gallery_presentation_assert($inheritedBreadcrumbStyle['breadcrumb_style'] === 'surface', 'Inheriting Smart Gallery breadcrumbs follow a changed Theme default.');
    $explicitBreadcrumbStyle = smart_gallery_effective_presentation(['presentation_json' => '{"version":1,"breadcrumb_style":"pills"}']);
    smart_gallery_presentation_assert($explicitBreadcrumbStyle['breadcrumb_style'] === 'pills', 'An explicit Smart Gallery breadcrumb style overrides Theme.');
    $invalidBreadcrumbStyle = smart_gallery_effective_presentation(['presentation_json' => '{"version":1,"breadcrumb_style":"obsolete"}']);
    smart_gallery_presentation_assert($invalidBreadcrumbStyle['breadcrumb_style'] === 'surface', 'Invalid Smart Gallery breadcrumb styles safely inherit Theme.');
    $invalidBreadcrumbType = smart_gallery_effective_presentation(['presentation_json' => ['version' => 1, 'breadcrumb_style' => ['pills']]]);
    smart_gallery_presentation_assert($invalidBreadcrumbType['breadcrumb_style'] === 'surface', 'Malformed breadcrumb override types safely inherit Theme.');
    $breadcrumbJson = json_decode(smart_gallery_presentation_json(['breadcrumb_style' => 'inherit']), true, 16, JSON_THROW_ON_ERROR);
    smart_gallery_presentation_assert(!array_key_exists('breadcrumb_style', $breadcrumbJson), 'The inheritance sentinel is omitted from Smart Gallery presentation JSON.');
    $breadcrumbJson = json_decode(smart_gallery_presentation_json(['breadcrumb_style' => 'gradient']), true, 16, JSON_THROW_ON_ERROR);
    smart_gallery_presentation_assert(($breadcrumbJson['breadcrumb_style'] ?? null) === 'gradient', 'A new explicit breadcrumb style persists through the Smart Gallery JSON roundtrip.');
    $smartGalleryView = file_get_contents(__DIR__ . '/../app/views/smart_galleries.php');
    smart_gallery_presentation_assert(is_string($smartGalleryView)
        && str_contains($smartGalleryView, "view_render_breadcrumb_style_picker((array) (\$viewModel['breadcrumb_style_picker'] ?? []))")
        && !str_contains($smartGalleryView, '<select name="presentation_breadcrumb_style"'),
        'Smart Gallery presentation must render the prepared shared radio-card picker.');
    $smartGalleryController = file_get_contents(__DIR__ . '/../app/controllers/smart_galleries.php');
    smart_gallery_presentation_assert(is_string($smartGalleryController)
        && str_contains($smartGalleryController, "'field_name' => 'presentation_breadcrumb_style'")
        && str_contains($smartGalleryController, 'breadcrumb_style_picker_options(true, theme_breadcrumb_style())'),
        'Smart Gallery controller must prepare inherited previews with the canonical presentation name.');
    $GLOBALS['smart_gallery_breadcrumb_theme_style'] = 'chevron';

    $malformed = smart_gallery_effective_presentation(['presentation_json' => '{not-json']);
    smart_gallery_presentation_assert($malformed['thumbnail_rendering_mode'] === 'progressive' && $malformed['grid_columns'] === 5, 'Malformed presentation JSON falls back to current defaults.');

    $unknownVersion = smart_gallery_effective_presentation(['presentation_json' => '{"version":99,"grid_columns":12}']);
    smart_gallery_presentation_assert($unknownVersion['grid_columns'] === 5, 'Unknown presentation versions fail closed to inherited defaults.');

    $normalized = smart_gallery_normalize_presentation([
        'version' => 1,
        'grid_columns' => 8,
        'grid_rows' => 9,
        'pagination_enabled' => false,
        'thumbnail_min_size' => 1200,
        'thumbnail_max_size' => 600,
        'thumbnail_rendering_mode' => 'responsive',
        'card_layout' => 'vertical',
        'metadata_visible' => false,
        'source_gallery_visible' => false,
        'map_enabled' => false,
        'lightbox_enabled' => true,
        'lightbox_browsing_mode' => 'picture_strip',
        'slideshow_enabled' => false,
        'download_enabled' => false,
        'voting_enabled' => true,
    ]);
    smart_gallery_presentation_assert($normalized['grid_columns'] === 8 && $normalized['grid_rows'] === 9, 'Explicit grid overrides are preserved.');
    smart_gallery_presentation_assert($normalized['pagination_enabled'] === false && $normalized['metadata_visible'] === false && $normalized['source_gallery_visible'] === false && $normalized['map_enabled'] === false, 'Explicit false booleans remain explicit overrides.');
smart_gallery_presentation_assert($normalized['card_layout'] === 'vertical', 'Explicit canonical gallery-card layout overrides are preserved.');
    smart_gallery_presentation_assert($normalized['thumbnail_min_size'] === 600 && $normalized['thumbnail_max_size'] === 1200, 'Reversed thumbnail bounds are normalized safely.');

    $badValues = smart_gallery_effective_presentation(['presentation_json' => json_encode([
        'version' => 1,
        'grid_columns' => 'bad',
        'grid_rows' => 999,
        'thumbnail_rendering_mode' => 'unknown-renderer',
        'card_layout' => 'unknown-layout',
        'lightbox_browsing_mode' => 'unknown-mode',
    ])]);
    smart_gallery_presentation_assert($badValues['grid_columns'] === 5 && $badValues['grid_rows'] === 7, 'Invalid grid values inherit the current site grid instead of hardcoded values.');
    smart_gallery_presentation_assert($badValues['thumbnail_rendering_mode'] === 'progressive' && $badValues['lightbox_browsing_mode'] === 'carousel', 'Invalid renderer and lightbox values inherit current defaults.');
smart_gallery_presentation_assert($badValues['card_layout'] === 'horizontal', 'Invalid gallery-card layout values inherit the current Theme default.');

    $previewWins = smart_gallery_effective_presentation([
        'presentation_json' => '{"version":1,"grid_columns":6}',
        'presentation' => ['grid_columns' => 10, 'grid_rows' => 2],
    ]);
    smart_gallery_presentation_assert($previewWins['grid_columns'] === 10 && $previewWins['grid_rows'] === 2, 'Unsaved Admin preview presentation overrides stored presentation.');

    $smartBounds = ['thumbnail_min_size' => 600, 'thumbnail_max_size' => 1200];
    $sizes = smart_gallery_thumbnail_sizes($smartBounds, [], [], thumbnail_sizes());
    smart_gallery_presentation_assert($sizes === [600, 800, 1200], 'Smart Gallery thumbnail guardrails filter generated candidates.');

    $sourceConflict = smart_gallery_thumbnail_sizes(
        ['thumbnail_min_size' => 1200, 'thumbnail_max_size' => 1600],
        [],
        ['thumbnail_min_size' => 600, 'thumbnail_max_size' => 800],
        thumbnail_sizes()
    );
    smart_gallery_presentation_assert($sourceConflict === [600, 800], 'Physical gallery thumbnail guardrails remain authoritative when Smart Gallery bounds conflict.');

    $storedLocalPreferences = [
        'presentation_json' => json_encode([
            'version' => 1,
            'map_enabled' => true,
            'lightbox_enabled' => true,
            'slideshow_enabled' => true,
            'download_enabled' => true,
            'voting_enabled' => true,
        ]),
    ];
    $GLOBALS['smart_gallery_feature_states'] = [
        'lightbox_modes' => false,
        'downloads' => false,
        'image_voting' => false,
        'gallery_maps' => false,
    ];
    $storedPreferencesWhileMastersOff = smart_gallery_presentation_preferences($storedLocalPreferences);
    smart_gallery_presentation_assert($storedPreferencesWhileMastersOff['map_enabled'], 'Editor preference state must preserve a stored local map ON preference while the global master is OFF.');
    smart_gallery_presentation_assert($storedPreferencesWhileMastersOff['lightbox_enabled'], 'Editor preference state must preserve a stored local Lightbox ON preference while the global master is OFF.');
    smart_gallery_presentation_assert($storedPreferencesWhileMastersOff['slideshow_enabled'], 'Editor preference state must preserve a stored local slideshow ON preference while the global Lightbox master is OFF.');
    smart_gallery_presentation_assert($storedPreferencesWhileMastersOff['download_enabled'], 'Editor preference state must preserve a stored local Downloads ON preference while the global master is OFF.');
    smart_gallery_presentation_assert($storedPreferencesWhileMastersOff['voting_enabled'], 'Editor preference state must preserve a stored local Voting ON preference while the global master is OFF.');

    $mastersOff = smart_gallery_effective_presentation($storedLocalPreferences);
    smart_gallery_presentation_assert(!$mastersOff['map_enabled'], 'Global EXIF GPS Gallery Maps master must override a stored Smart Gallery local ON preference.');
    smart_gallery_presentation_assert(!$mastersOff['lightbox_enabled'], 'Global Lightbox master must override a stored Smart Gallery local ON preference.');
    smart_gallery_presentation_assert(!$mastersOff['slideshow_enabled'], 'Smart Gallery slideshow must become ineffective while its Lightbox master is OFF.');
    smart_gallery_presentation_assert(!$mastersOff['download_enabled'], 'Global Downloads master must override a stored Smart Gallery local ON preference.');
    smart_gallery_presentation_assert(!$mastersOff['voting_enabled'], 'Global Image Voting master must override a stored Smart Gallery local ON preference.');

    $GLOBALS['smart_gallery_feature_states'] = [
        'lightbox_modes' => true,
        'downloads' => true,
        'image_voting' => true,
        'gallery_maps' => true,
    ];
    $mastersRestored = smart_gallery_effective_presentation($storedLocalPreferences);
    smart_gallery_presentation_assert($mastersRestored['map_enabled'], 'Re-enabling EXIF GPS Gallery Maps must restore the stored Smart Gallery map preference.');
    smart_gallery_presentation_assert($mastersRestored['lightbox_enabled'], 'Re-enabling the Lightbox master must restore the stored Smart Gallery local preference.');
    smart_gallery_presentation_assert($mastersRestored['slideshow_enabled'], 'Re-enabling the Lightbox master must restore the stored Smart Gallery slideshow preference.');
    smart_gallery_presentation_assert($mastersRestored['download_enabled'], 'Re-enabling Downloads must restore the stored Smart Gallery local preference.');
    smart_gallery_presentation_assert($mastersRestored['voting_enabled'], 'Re-enabling Image Voting must restore the stored Smart Gallery local preference.');

    fwrite(STDOUT, "Smart Gallery presentation tests passed.\n");
}
