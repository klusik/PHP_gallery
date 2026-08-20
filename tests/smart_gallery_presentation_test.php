<?php

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

    /** Keep both optional site features enabled for deterministic defaults. */
    function feature_flag_enabled(string $feature): bool
    {
        unset($feature);
        return true;
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

    $defaults = smart_gallery_presentation_defaults();
    smart_gallery_presentation_assert($defaults['grid_columns'] === 5 && $defaults['grid_rows'] === 7, 'Smart Gallery defaults inherit the current site grid.');
    smart_gallery_presentation_assert($defaults['thumbnail_rendering_mode'] === 'progressive', 'Smart Gallery defaults inherit the current site thumbnail renderer.');
smart_gallery_presentation_assert($defaults['card_layout'] === 'horizontal', 'Smart Gallery defaults inherit the canonical Theme gallery-card layout.');
    smart_gallery_presentation_assert($defaults['lightbox_browsing_mode'] === 'carousel', 'Smart Gallery defaults inherit the current Theme lightbox mode.');

    $emptyEffective = smart_gallery_effective_presentation(['presentation_json' => null]);
    smart_gallery_presentation_assert($emptyEffective['grid_columns'] === 5 && $emptyEffective['items_per_page'] === 35, 'Missing presentation data inherits the site defaults.');
    smart_gallery_presentation_assert($emptyEffective['grid_source'] === 'theme', 'Missing presentation data reports Theme inheritance.');

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
        'lightbox_enabled' => true,
        'lightbox_browsing_mode' => 'picture_strip',
        'slideshow_enabled' => false,
        'download_enabled' => false,
        'voting_enabled' => true,
    ]);
    smart_gallery_presentation_assert($normalized['grid_columns'] === 8 && $normalized['grid_rows'] === 9, 'Explicit grid overrides are preserved.');
    smart_gallery_presentation_assert($normalized['pagination_enabled'] === false && $normalized['metadata_visible'] === false, 'Explicit false booleans remain explicit overrides.');
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

    fwrite(STDOUT, "Smart Gallery presentation tests passed.\n");
}
