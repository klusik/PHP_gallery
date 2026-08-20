<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/public_thumbnail_rendering.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Resolves the permanent public thumbnail rendering policy used by selected-gallery photo cards.
 *
 * Responsibilities:
 *   - Define the supported responsive and progressive machine values in one place
 *   - Normalize persisted settings to the progressive default
 *   - Persist only normalized public thumbnail rendering values through app_settings
 *   - Keep renderer selection out of the public gallery controller
 *   - Preserve the existing responsive loading policy and define the progressive small-first policy
 *   - Dispatch only thumbnail picture markup while leaving the surrounding photo-card renderer shared
 *
 * Integration points:
 *   - app/services.php loads this service after the thumbnail helpers
 *   - app/controllers/admin_theme.php reads and saves the site-level renderer setting
 *   - app/controllers/public_gallery.php delegates selected-gallery photo picture markup to this service
 *   - app/services/thumbnail_html.php supplies the responsive and progressive server-rendered picture helpers
 *
 * Lifecycle:
 *   The setting is resolved once per request from app_settings, normalized, then used while selected-gallery
 *   photo cards render. Responsive markup is complete at parse time. Progressive markup is also complete and
 *   accessible at parse time, but exposes only its small candidate actively until the browser-side progressive
 *   renderer elects to sharpen a visible or near-visible image.
 *
 * Invariants:
 *   - Progressive thumbnail sharpening is the default and fallback mode
 *   - Unknown, empty, malformed, or obsolete persisted values cannot select another renderer
 *   - Renderer selection changes only thumbnail picture markup and its loading policy
 *   - Access checks, card links, lightbox metadata, votes, maps, tags, pagination, manifest data, and warm-up
 *     authorization remain owned by their existing layers
 *   - Subgallery covers, collage cells, home-page gallery cards, and Admin thumbnails intentionally remain on
 *     the responsive helper; the site-level mode currently applies only to selected-gallery photo cards
 *
 * Fallback behavior:
 *   Missing settings and unsupported submitted values normalize to progressive. Progressive markup always keeps
 *   a real server-rendered small image, so failed or unavailable JavaScript leaves a usable gallery rather than
 *   an empty placeholder.
 *
 * Accessibility:
 *   Both modes retain semantic picture/img markup, caller-provided alt text, stable intrinsic dimensions when
 *   known, and normal card links. Sharpening never replaces navigation or requires a pointer interaction.
 *
 * No-JavaScript behavior:
 *   Responsive mode behaves exactly as before. Progressive mode remains navigable and displays the initial small
 *   thumbnail indefinitely when JavaScript is disabled.
 *
 * Performance rationale:
 *   Responsive mode lets the browser choose from the complete candidate set during HTML parsing. Progressive
 *   mode deliberately starts with a small candidate and defers larger transfers to the near-viewport scheduler,
 *   trading earliest full sharpness and potentially extra transferred bytes for faster perceived initial paint.
 *
 * Naming:
 *   Responsive and progressive are permanent architecture terms because both pipelines are intended to remain
 *   supported. The Admin UI may describe renderer status, but that wording is not a machine
 *   value, setting key, function name, class name, filename, test identifier, or browser data marker.
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
 *   2026-08-20
 */

declare(strict_types=1);

namespace Gallery\Services;

const PUBLIC_THUMBNAIL_RENDERING_SETTING_KEY = 'public_thumbnail_rendering_mode';
const PUBLIC_THUMBNAIL_RENDERING_RESPONSIVE = 'responsive';
const PUBLIC_THUMBNAIL_RENDERING_PROGRESSIVE = 'progressive';
const PUBLIC_THUMBNAIL_RENDERING_DEFAULT = PUBLIC_THUMBNAIL_RENDERING_PROGRESSIVE;

/**
 * Return the permanent machine values accepted for public thumbnail rendering.
 *
 * @return array<int,string> Supported rendering mode values.
 */
function public_thumbnail_rendering_modes(): array
{
    return [
        PUBLIC_THUMBNAIL_RENDERING_PROGRESSIVE,
        PUBLIC_THUMBNAIL_RENDERING_RESPONSIVE,
    ];
}

/**
 * Normalize an arbitrary persisted or submitted value to a supported renderer mode.
 *
 * Unsupported values intentionally fall back to progressive so corrupted, stale, or manually edited
 * app_settings rows resolve to the current site default instead of selecting the legacy renderer unexpectedly.
 *
 * @param mixed $value Persisted or submitted rendering mode value.
 * @return string One of the supported public thumbnail rendering machine values.
 */
function public_thumbnail_rendering_mode_normalize(mixed $value): string
{
    if (!is_string($value)) {
        return PUBLIC_THUMBNAIL_RENDERING_DEFAULT;
    }

    $normalized = trim($value);
    return in_array($normalized, public_thumbnail_rendering_modes(), true)
        ? $normalized
        : PUBLIC_THUMBNAIL_RENDERING_DEFAULT;
}

/**
 * Resolve the effective site-level public thumbnail rendering mode.
 *
 * @return string Effective supported rendering mode.
 */
function public_thumbnail_rendering_mode(): string
{
    return public_thumbnail_rendering_mode_normalize(
        app_setting(PUBLIC_THUMBNAIL_RENDERING_SETTING_KEY, PUBLIC_THUMBNAIL_RENDERING_DEFAULT)
    );
}

/**
 * Persist a validated public thumbnail rendering mode through the application settings service.
 *
 * Invalid input is deliberately persisted as the progressive default instead of retaining an obsolete value.
 * This makes Admin POST handling deterministic while keeping validation and renderer fallback in one service.
 *
 * @param mixed $value Submitted rendering mode value.
 * @return string Normalized value that was persisted.
 */
function public_thumbnail_rendering_mode_save(mixed $value): string
{
    $normalized = public_thumbnail_rendering_mode_normalize($value);
    set_app_setting(PUBLIC_THUMBNAIL_RENDERING_SETTING_KEY, $normalized);
    return $normalized;
}

/**
 * Persist the public thumbnail rendering mode and preserve Theme content-revision side effects.
 *
 * Both the Theme editor and centralized Settings hub use this helper so changing the renderer
 * has one canonical normalization path and one canonical cache/content-revision behavior.
 *
 * @param mixed $value Submitted rendering mode value.
 * @return string Normalized value that was persisted.
 */
function public_thumbnail_rendering_mode_save_with_revision(mixed $value): string
{
    $previous = public_thumbnail_rendering_mode();
    $normalized = public_thumbnail_rendering_mode_save($value);
    if ($normalized !== $previous) {
        set_app_setting('theme_public_content_revision', (string) time());
    }
    return $normalized;
}

/**
 * Return the unchanged loading and fetch-priority policy used by responsive public thumbnails.
 *
 * @param int $index Zero-based public card index.
 * @return string HTML attributes for the responsive image element.
 */
function public_responsive_thumbnail_loading_attributes(int $index): string
{
    if ($index < 2) {
        return 'loading="eager" fetchpriority="high"';
    }
    if ($index < 8) {
        return 'loading="eager" fetchpriority="auto"';
    }
    return 'loading="lazy" fetchpriority="low"';
}

/**
 * Return the initial-small-image loading policy used by progressive selected-gallery photo cards.
 *
 * The first two small thumbnails are available immediately, but only the first receives high fetch priority.
 * Remaining cards stay native-lazy and low priority so the initial small-image phase does not create an eager
 * request burst before the near-viewport sharpening scheduler can make relevance decisions.
 *
 * @param int $index Zero-based selected-gallery photo-card index.
 * @return string HTML attributes for the progressive image element.
 */
function public_progressive_thumbnail_loading_attributes(int $index): string
{
    if ($index === 0) {
        return 'loading="eager" fetchpriority="high"';
    }
    if ($index === 1) {
        return 'loading="eager" fetchpriority="auto"';
    }
    return 'loading="lazy" fetchpriority="low"';
}

/**
 * Render selected-gallery photo thumbnail markup using the effective permanent rendering pipeline.
 *
 * This is intentionally a narrow renderer boundary. The caller still owns all card semantics and access-sensitive
 * metadata; this function chooses only the picture markup strategy and its mode-specific loading attributes.
 *
 * @param array $image Image row or image data.
 * @param int $fallbackSize Preferred initial thumbnail size in pixels.
 * @param array<int,int> $srcsetSizes Available responsive candidate sizes requested by the card.
 * @param string $sizes Responsive sizes hint used by the server-rendered picture.
 * @param string $alt Alternative text for the image.
 * @param int $cardIndex Zero-based selected-gallery photo-card index.
 * @param ?array $thumbnailBundle Request-local preloaded thumbnail bundle.
 * @param mixed $mode Optional pre-resolved rendering mode; unsupported values normalize to responsive.
 * @return string Server-rendered picture/img markup.
 */
function public_thumbnail_render_picture_html(
    array $image,
    int $fallbackSize,
    array $srcsetSizes,
    string $sizes,
    string $alt,
    int $cardIndex,
    ?array $thumbnailBundle = null,
    mixed $mode = null
): string {
    $effectiveMode = $mode === null
        ? public_thumbnail_rendering_mode()
        : public_thumbnail_rendering_mode_normalize($mode);

    // $rendererMarkerAttributes identify selected-gallery photo thumbnails for admin-only browser diagnostics.
    // The marker is permanent renderer metadata, not a behavior hook, and does not expose any additional media URL.
    $rendererMarkerAttributes = 'data-public-thumbnail-rendering-mode="' . $effectiveMode . '" data-public-thumbnail-card-index="' . max(0, $cardIndex) . '"';

    if ($effectiveMode === PUBLIC_THUMBNAIL_RENDERING_PROGRESSIVE) {
        return thumbnail_progressive_picture_html(
            $image,
            $fallbackSize,
            $srcsetSizes,
            $sizes,
            $sizes,
            $alt,
            $rendererMarkerAttributes . ' ' . public_progressive_thumbnail_loading_attributes($cardIndex),
            $thumbnailBundle
        );
    }

    return thumbnail_picture_html(
        $image,
        $fallbackSize,
        $srcsetSizes,
        $sizes,
        $alt,
        $rendererMarkerAttributes . ' ' . public_responsive_thumbnail_loading_attributes($cardIndex),
        $thumbnailBundle
    );
}
