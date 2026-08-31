<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/public_thumbnail_markup_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies responsive and progressive public thumbnail markup using request-local thumbnail bundles.
 *
 * Responsibilities:
 *   - Lock complete responsive srcset exposure and small-only progressive initial srcsets
 *   - Verify larger progressive candidates remain inert until browser activation
 *   - Cover WebP/JPEG structure, missing variants, thumbnail bounds, media fallback, and warm-up metadata
 *   - Verify database-backed intrinsic dimensions reserve layout space in both renderers
 *   - Verify the selected-gallery NSFW gate remains ahead of URL and thumbnail rendering work
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
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-08-09
 */

declare(strict_types=1);

namespace Gallery\Core {
    /** Escape a test HTML value with the same semantics expected by thumbnail markup helpers. */
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Return a deterministic route URL for media fallback assertions. */
    function url_for(string $route, array $params = []): string
    {
        return '/' . $route . '/' . (int) ($params['id'] ?? 0);
    }
}

namespace Gallery\Services {
    /** Ignore render-profile counters in this database-free markup test. */
    function public_render_profile_count(string $name, int $increment = 1): void
    {
    }

    /** Ignore thumbnail-purpose profiling in this database-free markup test. */
    function public_render_profile_record_thumbnail_purpose(?string $purpose, int $size, string $format, string $source): void
    {
    }

    /** Keep JPEG as the deterministic img fallback for markup assertions. */
    function thumbnail_preferred_browser_format(): string
    {
        return 'jpg';
    }

    /** Keep this structural markup fixture in explicit legacy compatibility mode. */
    function thumbnail_policy_requested_formats(): array
    {
        return ['jpg', 'webp'];
    }

    /** Allow both formats in this explicit legacy compatibility fixture. */
    function thumbnail_policy_format_allowed(string $format): bool
    {
        return in_array($format, thumbnail_policy_requested_formats(), true);
    }

    /**
     * Apply deterministic synthetic min/max bounds supplied on the test gallery row.
     *
     * @param array $sizes Requested candidate sizes.
     * @param array $image Image row.
     * @param ?array $gallery Gallery row.
     * @return array<int,int> Sizes inside the synthetic bounds.
     */
    function thumbnail_bound_filter_sizes(array $sizes, array $image, ?array $gallery = null): array
    {
        $min = (int) ($gallery['test_thumbnail_min_size'] ?? 0);
        $max = (int) ($gallery['test_thumbnail_max_size'] ?? 0);
        return array_values(array_filter(array_map('intval', $sizes), static function (int $size) use ($min, $max): bool {
            return ($min <= 0 || $size >= $min) && ($max <= 0 || $size <= $max);
        }));
    }

    /** Return a bounded synthetic fallback size for the bundle selector. */
    function thumbnail_bound_fallback_size(array $image, int $fallbackSize, ?array $gallery = null): int
    {
        $min = (int) ($gallery['test_thumbnail_min_size'] ?? 0);
        $max = (int) ($gallery['test_thumbnail_max_size'] ?? 0);
        if ($min > 0 && $fallbackSize < $min) {
            return $min;
        }
        if ($max > 0 && $fallbackSize > $max) {
            return $max;
        }
        return $fallbackSize;
    }

    /** Return observable warm-up attributes while avoiding signed-token dependencies. */
    function thumbnail_warmup_candidate_attributes(array $image, array $gallery, array $sizes): string
    {
        return 'data-thumbnail-warmup-test="1"';
    }
}

namespace {
    use function Gallery\Services\thumbnail_picture_html;
    use function Gallery\Services\thumbnail_progressive_picture_html;

    require_once __DIR__ . '/../app/services/thumbnail_bundles.php';
    require_once __DIR__ . '/../app/services/thumbnail_html.php';

    /** Throw when a markup condition is false. */
    function assert_public_thumbnail_markup(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new RuntimeException($label);
        }
    }

    /** Throw when markup omits an expected fragment. */
    function assert_public_thumbnail_markup_contains(string $needle, string $haystack, string $label): void
    {
        assert_public_thumbnail_markup(str_contains($haystack, $needle), $label . ' missing ' . var_export($needle, true));
    }

    /** Throw when markup contains an unexpected fragment. */
    function assert_public_thumbnail_markup_not_contains(string $needle, string $haystack, string $label): void
    {
        assert_public_thumbnail_markup(!str_contains($haystack, $needle), $label . ' unexpectedly contained ' . var_export($needle, true));
    }

    $image = [
        'id' => 41,
        'gallery_id' => 7,
        'filename' => 'photo.jpg',
        'relative_path' => '',
        'display_width' => 1600,
        'display_height' => 1200,
    ];
    $gallery = [
        'id' => 7,
        'test_thumbnail_min_size' => 300,
        'test_thumbnail_max_size' => 800,
    ];
    $bundle = [
        'image' => $image,
        'gallery' => $gallery,
        'media_url' => '/media/41',
        'variants' => [
            'jpg' => [
                300 => '/thumb/photo-300.jpg',
                600 => '/thumb/photo-600.jpg',
                800 => '/thumb/photo-800.jpg',
                960 => '/thumb/photo-960.jpg',
            ],
            'webp' => [
                300 => '/thumb/photo-300.webp',
                600 => '/thumb/photo-600.webp',
                800 => '/thumb/photo-800.webp',
                960 => '/thumb/photo-960.webp',
            ],
        ],
        'warmup_sizes' => [],
    ];

    $responsive = thumbnail_picture_html(
        $image,
        300,
        [300, 600, 800, 960],
        '(min-width: 48rem) 25vw, 94vw',
        'Accessible photo',
        'loading="eager" fetchpriority="high"',
        $bundle
    );
    assert_public_thumbnail_markup_contains('<picture>', $responsive, 'responsive markup remains server-rendered');
    assert_public_thumbnail_markup_contains('<source type="image/webp"', $responsive, 'responsive markup exposes WebP source');
    assert_public_thumbnail_markup_contains('/thumb/photo-300.webp 300w', $responsive, 'responsive WebP srcset exposes small candidate immediately');
    assert_public_thumbnail_markup_contains('/thumb/photo-600.webp 600w', $responsive, 'responsive WebP srcset exposes medium candidate immediately');
    assert_public_thumbnail_markup_contains('/thumb/photo-800.webp 800w', $responsive, 'responsive WebP srcset exposes large bounded candidate immediately');
    assert_public_thumbnail_markup_contains('/thumb/photo-300.jpg 300w', $responsive, 'responsive JPEG srcset exposes small fallback candidate immediately');
    assert_public_thumbnail_markup_contains('/thumb/photo-800.jpg 800w', $responsive, 'responsive JPEG srcset exposes large bounded fallback candidate immediately');
    assert_public_thumbnail_markup_not_contains('photo-960', $responsive, 'responsive markup respects configured thumbnail bounds');
    assert_public_thumbnail_markup_not_contains('data-progressive-thumbnail', $responsive, 'responsive markup is not JavaScript-dependent');
    assert_public_thumbnail_markup_contains('src="/thumb/photo-300.jpg"', $responsive, 'responsive fallback src remains the 300px derivative');
    assert_public_thumbnail_markup_contains('width="1600" height="1200"', $responsive, 'responsive markup reserves intrinsic aspect ratio');
    assert_public_thumbnail_markup_contains('alt="Accessible photo"', $responsive, 'responsive no-JavaScript image keeps useful alt text');
    assert_public_thumbnail_markup_contains('loading="eager" fetchpriority="high"', $responsive, 'responsive helper preserves caller loading policy');

    $progressive = thumbnail_progressive_picture_html(
        $image,
        300,
        [300, 600, 800, 960],
        '(min-width: 48rem) 25vw, 94vw',
        '(min-width: 48rem) 25vw, 94vw',
        'Accessible photo',
        'loading="eager" fetchpriority="high"',
        $bundle
    );
    assert_public_thumbnail_markup_contains('<picture>', $progressive, 'progressive markup remains server-rendered');
    assert_public_thumbnail_markup_contains('data-progressive-thumbnail', $progressive, 'progressive markup exposes permanent browser activation marker');
    assert_public_thumbnail_markup_contains('data-progressive-active-width="300"', $progressive, 'progressive markup records the active small candidate width');
    assert_public_thumbnail_markup_contains('data-progressive-sizes="(min-width: 48rem) 25vw, 94vw"', $progressive, 'progressive markup stores the final responsive sizes hint inertly');
    assert_public_thumbnail_markup_contains('src="/thumb/photo-300.jpg"', $progressive, 'progressive no-JavaScript fallback has a real small thumbnail URL');
    assert_public_thumbnail_markup_contains('srcset="/thumb/photo-300.webp 300w"', $progressive, 'progressive active WebP srcset contains only the small candidate');
    assert_public_thumbnail_markup_contains('srcset="/thumb/photo-300.jpg 300w"', $progressive, 'progressive active JPEG srcset contains only the small candidate');
    assert_public_thumbnail_markup_contains('data-progressive-srcset="/thumb/photo-600.webp 600w, /thumb/photo-800.webp 800w"', $progressive, 'progressive larger WebP candidates remain inert and bounded');
    assert_public_thumbnail_markup_contains('data-progressive-srcset="/thumb/photo-600.jpg 600w, /thumb/photo-800.jpg 800w"', $progressive, 'progressive larger JPEG candidates remain inert and bounded');
    assert_public_thumbnail_markup_not_contains('data-progressive-srcset="/thumb/photo-300', $progressive, 'progressive inert data excludes the already active small candidate');
    assert_public_thumbnail_markup_not_contains('photo-960', $progressive, 'progressive markup respects configured thumbnail bounds');
    assert_public_thumbnail_markup_contains('width="1600" height="1200"', $progressive, 'progressive markup reserves intrinsic aspect ratio');
    assert_public_thumbnail_markup_contains('alt="Accessible photo"', $progressive, 'progressive no-JavaScript image keeps useful alt text');

    $missingVariantBundle = $bundle;
    unset($missingVariantBundle['variants']['webp'][600], $missingVariantBundle['variants']['jpg'][800]);
    $missingVariantMarkup = thumbnail_picture_html($image, 300, [300, 600, 800], '50vw', 'Photo', '', $missingVariantBundle);
    assert_public_thumbnail_markup_not_contains('/thumb/photo-600.webp', $missingVariantMarkup, 'missing WebP variant is not emitted into responsive srcset');
    assert_public_thumbnail_markup_not_contains('/thumb/photo-800.jpg', $missingVariantMarkup, 'missing JPEG variant is not emitted into responsive srcset');

    $missingSmallBundle = $bundle;
    unset($missingSmallBundle['variants']['webp'][300], $missingSmallBundle['variants']['jpg'][300]);
    $missingSmallProgressiveMarkup = thumbnail_progressive_picture_html($image, 300, [300, 600, 800], '50vw', '50vw', 'Photo', '', $missingSmallBundle);
    assert_public_thumbnail_markup_contains('data-progressive-active-width="600"', $missingSmallProgressiveMarkup, 'progressive markup selects the smallest actually available bounded fallback');
    assert_public_thumbnail_markup_contains('src="/thumb/photo-600.jpg"', $missingSmallProgressiveMarkup, 'progressive no-JavaScript fallback remains real when the preferred small derivative is missing');
    assert_public_thumbnail_markup_not_contains('/thumb/photo-300', $missingSmallProgressiveMarkup, 'progressive markup does not invent missing preferred-small variants');

    $warmupBundle = $bundle;
    $warmupBundle['warmup_sizes'] = [600];
    $warmupMarkup = thumbnail_progressive_picture_html($image, 300, [300, 600, 800], '50vw', '50vw', 'Photo', '', $warmupBundle);
    assert_public_thumbnail_markup_contains('data-thumbnail-warmup-test="1"', $warmupMarkup, 'progressive markup preserves warm-up metadata');

    $mediaFallbackBundle = [
        'image' => $image,
        'gallery' => $gallery,
        'media_url' => '/media/41',
        'variants' => ['jpg' => [], 'webp' => []],
        'warmup_sizes' => [],
    ];
    $mediaFallbackMarkup = thumbnail_progressive_picture_html($image, 300, [300, 600], '50vw', '50vw', 'Photo', '', $mediaFallbackBundle);
    assert_public_thumbnail_markup_contains('src="/media/41"', $mediaFallbackMarkup, 'progressive markup keeps media fallback when derivatives are unavailable');
    assert_public_thumbnail_markup_contains('data-thumbnail-warmup-test="1"', $mediaFallbackMarkup, 'media fallback remains eligible for thumbnail warm-up');

    // The selected-gallery controller must complete the restricted NSFW placeholder branch before any URL bundle/render work.
    $controllerSource = file_get_contents(__DIR__ . '/../app/controllers/public_gallery_page.php');
    assert_public_thumbnail_markup(is_string($controllerSource), 'public gallery controller source is readable');
    $gatePosition = strpos($controllerSource, '$imageNeedsNsfwGate =');
    $continuePosition = strpos($controllerSource, 'continue;', $gatePosition === false ? 0 : $gatePosition);
    $mediaUrlPosition = strpos($controllerSource, '$mediaUrl =', $gatePosition === false ? 0 : $gatePosition);
    $rendererPosition = strpos($controllerSource, 'public_thumbnail_render_picture_html(', $gatePosition === false ? 0 : $gatePosition);
    assert_public_thumbnail_markup($gatePosition !== false, 'NSFW gate remains present in selected-gallery photo rendering');
    assert_public_thumbnail_markup($continuePosition !== false && $mediaUrlPosition !== false && $continuePosition < $mediaUrlPosition, 'restricted NSFW branch exits before media URL construction');
    assert_public_thumbnail_markup($continuePosition !== false && $rendererPosition !== false && $continuePosition < $rendererPosition, 'restricted NSFW branch exits before responsive/progressive thumbnail rendering');

    echo "Public thumbnail markup tests passed.\n";
}
