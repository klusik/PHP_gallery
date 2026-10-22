<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/lightbox_zoom_quality_rendering_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects the server-rendered and lazy progressive zoom source contract.
 *
 * Responsibilities:
 *   - Require visible physical and Smart Gallery cards to pass their thumbnail bundle
 *   - Require server markup to emit JSON quality candidates
 *   - Require lazy pagination payloads to expose the same candidate model
 *   - Preserve the legacy preview/full attributes during progressive enhancement
 *
 * Author:
 *   Rudolf Klusal
 */

declare(strict_types=1);

namespace Gallery\Core {
    /** Escape the canonical view fixture's scalar output using production semantics.
     * @param ?string $value Value supplied to the HTML view.
     * @return string Escaped string safe for the fixture's HTML assertions.
     */
    function e(?string $value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
    }
}

namespace Gallery\Services {
    /** Resolve a deterministic translation fallback for the isolated view fixture.
     * @param string $key Translation key requested by the shared lightbox view.
     * @param string|array|null $fallback Optional view fallback or interpolation map.
     * @param array<string,scalar|null> $parameters Placeholder values.
     * @return string The supplied fallback or key with placeholder values applied.
     */
    function t(
        string $key,
        string|array|null $fallback = null,
        array $parameters = [],
    ): string {
        if (is_array($fallback)) {
            $parameters = $fallback;
            $fallback = null;
        }
        $text = is_string($fallback) ? $fallback : $key;
        foreach ($parameters as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }
}

namespace Gallery\Controllers {
    /** Supply an empty viewer-favourite slot for the lightbox wrapper fixture.
     * @return string No personalized control is rendered in this test principal.
     */
    function render_viewer_favourite_lightbox_form_html(): string
    {
        return '';
    }
}

namespace {

require_once __DIR__ . '/support/php_source.php';
require_once dirname(__DIR__) . '/app/views/admin_ui.php';
require_once dirname(__DIR__) . '/app/views/public_gallery_lightbox.php';
require_once dirname(__DIR__) . '/app/services/gallery_lightbox_mode.php';
require_once dirname(__DIR__) . '/app/controllers/public_gallery_lightbox.php';

$root = dirname(__DIR__);
$rendererControllerSource = (string) file_get_contents($root
    . '/app/controllers/public_gallery_lightbox.php');
$rendererViewSource = (string) file_get_contents($root
    . '/app/views/public_gallery_lightbox.php');
$rendererSource = $rendererControllerSource . "\n" . $rendererViewSource;
$galleryPageSource = (string) file_get_contents($root
    . '/app/controllers/public_gallery_page.php');
$smartGalleryControllerSource = (string) file_get_contents($root
    . '/app/controllers/smart_galleries.php');
$smartGalleryViewSource = (string) file_get_contents($root
    . '/app/views/smart_galleries.php');
$smartGallerySource =
    $smartGalleryControllerSource . "\n" . $smartGalleryViewSource;
$lazyEndpointSource = (string) file_get_contents($root
    . '/app/controllers/gallery_lightbox.php');
$lightboxStyleSource = (string) file_get_contents($root
    . '/public/assets/styles/lightbox.css');
$mobileGalleryStyleSource = (string) file_get_contents($root
    . '/public/assets/styles/mobile-gallery.css');

/**
 * Throw when a rendering integration expectation fails.
 *
 * @param bool $condition Assertion result.
 * @param string $message Failure diagnostic.
 * @return void Throw when the rendering integration expectation fails.
 */
function lightbox_zoom_quality_rendering_assert(
    bool $condition,
    string $message,
): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

lightbox_zoom_quality_rendering_assert(
    php_source_contains($rendererSource, 'data-lightbox-quality-sources=')
        && php_source_contains(
            $rendererSource,
            'lightbox_zoom_quality_candidates($image, $previewUrl, $mediaUrl, $thumbnailBundle)',
        ),
    'Shared lightbox markup must emit service-owned quality candidates.',
);
lightbox_zoom_quality_rendering_assert(
    php_source_contains($rendererSource, 'data-full-src=')
        && php_source_contains($rendererSource, 'data-preview-src='),
    'Progressive quality markup must preserve legacy preview/full attributes.',
);
lightbox_zoom_quality_rendering_assert(
    php_source_contains(
        $rendererSource,
        'class="lightbox-zoom-surface" data-lightbox-zoom-surface',
    )
        && php_source_contains($rendererSource, 'data-lightbox-img'),
    'The active image must render inside the stable zoom surface used for quality swaps.',
);
lightbox_zoom_quality_rendering_assert(
    str_contains($lightboxStyleSource, '.lightbox-zoom-surface {')
        && !str_contains(
            $lightboxStyleSource,
            '.lightbox-zoom-surface {\n    grid-area: 1 / 1;\n    display: grid;\n    place-items: center;\n    width: 100%;\n    height: 100%;\n    min-width: 0;\n    min-height: 0;\n    transform-origin: center center;\n    will-change: transform;',
        )
        && str_contains(
            $lightboxStyleSource,
            '.lightbox.is-zoom-animating .lightbox-zoom-surface',
        )
        && str_contains(
            $lightboxStyleSource,
            'transition: width 120ms ease-out, height 120ms ease-out, transform 120ms ease-out;',
        ),
    'Desktop zoom must animate the real image dimensions instead of permanently scaling a preview compositor layer.',
);
lightbox_zoom_quality_rendering_assert(
    str_contains(
        $mobileGalleryStyleSource,
        '.lightbox.is-mobile-device .lightbox-zoom-surface > img',
    )
        && !str_contains(
            $mobileGalleryStyleSource,
            '.lightbox.is-mobile-device .lightbox-stage-link > img',
        ),
    'Mobile swipe styling must keep targeting the image child inside the stable zoom surface.',
);
lightbox_zoom_quality_rendering_assert(
    php_source_contains(
        $galleryPageSource,
        '$lightboxIndex >= 0 ? $lightboxIndex : null, $thumbnailBundle, null, $quickTagEnabled',
    ),
    'Physical gallery cards must pass the already-resolved thumbnail bundle.',
);
lightbox_zoom_quality_rendering_assert(
    php_source_contains($smartGalleryControllerSource, "'data-lightbox-image'")
        && php_source_contains($smartGalleryControllerSource, '$offset + $index')
        && php_source_contains($smartGalleryControllerSource, '$bundle')
        && php_source_contains(
            $smartGalleryControllerSource,
            "'attributes_html' => \$attributesHtml",
        )
        && php_source_contains(
            $smartGalleryViewSource,
            '($card[\'attributes_html\'] ?? \'\')',
        ),
    'Smart Gallery cards must preserve bundle-backed global lightbox attributes across the controller/view boundary.',
);
lightbox_zoom_quality_rendering_assert(
    php_source_contains(
        $rendererControllerSource,
        "'quick_tag_enabled' => \$quickTagEnabled",
    )
        && php_source_contains(
            $rendererControllerSource,
            "'quick_tag_url' => \$quickTagUrl",
        )
        && php_source_contains(
            $rendererControllerSource,
            "'quick_tag_csrf' => \$quickTagCsrf",
        )
        && php_source_contains(
            $galleryPageSource,
            '$quickTagEnabled, $quickTagUrl, $quickTagCsrf',
        )
        && php_source_contains(
            $smartGalleryControllerSource,
            '$quickTagEnabled, $quickTagUrl, $quickTagCsrf',
        ),
    'Physical and Smart Gallery controllers must pass their prepared quick-tag authorization context through the shared lightbox wrapper.',
);
lightbox_zoom_quality_rendering_assert(
    php_source_contains(
        $lazyEndpointSource,
        "'quality_sources' => lightbox_zoom_quality_candidates(\$image, \$previewUrl, \$mediaUrl, \$thumbnailBundle)",
    ),
    'Lazy lightbox pagination must return the same service-owned candidates.',
);

$galleryAccessPosition = strpos(
    $lazyEndpointSource,
    'visitor_can_access_gallery($gallery)',
);
$candidatePosition = strpos(
    $lazyEndpointSource,
    '$items[] = gallery_lightbox_json_item(',
);
lightbox_zoom_quality_rendering_assert(
    $galleryAccessPosition !== false
        && $candidatePosition !== false
        && $galleryAccessPosition < $candidatePosition,
    'Lazy candidate URLs must be produced only after gallery access is accepted.',
);

ob_start();
\Gallery\Controllers\render_lightbox(
    false,
    false,
    '',
    'Fixture Gallery',
    'single',
    false,
    [],
    true,
    '/admin?action=quick&mode=image',
    'fixture-token',
);
$authorizedLightboxHtml = (string) ob_get_clean();
lightbox_zoom_quality_rendering_assert(
    substr_count(
        $authorizedLightboxHtml,
        'data-quick-photo-tag-viewer',
    ) === 2
        && str_contains(
            $authorizedLightboxHtml,
            'class="lightbox-quick-photo-tag-button lightbox-hud"',
        )
        && str_contains(
            $authorizedLightboxHtml,
            'data-quick-tag-url="/admin?action=quick&amp;mode=image"',
        )
        && str_contains(
            $authorizedLightboxHtml,
            'data-quick-tag-csrf="fixture-token"',
        ),
    'The real shared PHP lightbox renderer must emit configured toolbar and HUD quick-tag buttons for an authorized view model.',
);

ob_start();
\Gallery\Controllers\render_lightbox(
    false,
    false,
    '',
    'Fixture Gallery',
    'single',
    false,
    [],
    false,
);
$disabledLightboxHtml = (string) ob_get_clean();
lightbox_zoom_quality_rendering_assert(
    !str_contains(
        $disabledLightboxHtml,
        'data-quick-photo-tag-viewer',
    )
        && !str_contains(
            $disabledLightboxHtml,
            'data-quick-tag-url=',
        ),
    'The real shared PHP lightbox renderer must omit quick-tag controls and endpoint configuration when disabled.',
);

echo "Lightbox zoom quality rendering checks passed.\n";
}
