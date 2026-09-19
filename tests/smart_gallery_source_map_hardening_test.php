<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/smart_gallery_source_map_hardening_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects final Smart Gallery source-provenance and aggregate-map hardening.
 *
 * Responsibilities:
 *   - Keep source and GPS disclosure subordinate to physical-gallery access/presentation policy
 *   - Keep Admin Test Run diagnostics bounded to counts rather than marker/location payloads
 *   - Keep source lookup and map availability free from per-result N+1 query patterns
 *   - Keep EN/CS/DE/SV source/map translations and keyboard/mobile presentation contracts present
 *   - Keep permanent Smart Gallery documentation synchronized with the implemented architecture
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
 */

declare(strict_types=1);

/** Fail one hardening contract with a concise diagnostic. */
function smart_gallery_source_map_hardening_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/app/services/smart_galleries.php');
$model = (string) file_get_contents($root . '/app/models/smart_galleries.php');
$controller = (string) file_get_contents($root . '/app/controllers/smart_galleries.php');
$view = (string) file_get_contents($root . '/app/views/smart_galleries.php');
$lightboxView = (string) file_get_contents($root . '/app/views/public_gallery_lightbox.php');
$lightboxJs = (string) file_get_contents($root . '/public/assets/gallery-modules/lightbox.js');
$css = (string) file_get_contents($root . '/public/assets/styles/public-shared.css');
$docs = (string) file_get_contents($root . '/docs/SMART_GALLERIES.md');

smart_gallery_source_map_hardening_assert(
    str_contains($service, 'gallery_is_public_listed($sourceGallery)')
    && str_contains($service, 'visitor_can_access_gallery($sourceGallery)'),
    'Public Smart Gallery membership must remain subordinate to physical-gallery listing and visitor access policy.'
);
smart_gallery_source_map_hardening_assert(
    str_contains($model, "AND i.visibility = 'public'")
    && str_contains($model, 'COALESCE(i.nsfw_enabled, 0) = 0')
    && str_contains($model, 'COALESCE(g.nsfw_enabled, 0) = 0'),
    'Public canonical result SQL must retain image visibility and NSFW restrictions.'
);
smart_gallery_source_map_hardening_assert(
    str_contains($service, 'gallery_allows_gps_maps($source, $lookup)')
    && str_contains($service, "'semantic' => \$mapGalleryIds !== [] ? smart_gallery_query_semantics"),
    'Aggregate map scope must intersect canonical query semantics with inherited physical-gallery GPS policy.'
);
smart_gallery_source_map_hardening_assert(
    str_contains($controller, '$imageHasPublicGps = gallery_allows_gps_maps($source) && image_has_gps($image);')
    && str_contains($controller, 'image_map_point($image, $source, true, $bundle)'),
    'Initial Smart Gallery items must use the same source-gallery GPS authorization as lazy items.'
);

$diagnosticsStart = strpos($service, 'function smart_gallery_map_diagnostics');
$diagnosticsEnd = strpos($service, '/** Return whether an aggregate Smart Gallery map has at least one authorized GPS point. */', $diagnosticsStart ?: 0);
smart_gallery_source_map_hardening_assert($diagnosticsStart !== false && $diagnosticsEnd !== false, 'Bounded Smart Gallery map diagnostics function must exist.');
$diagnosticsSource = substr($service, (int) $diagnosticsStart, (int) $diagnosticsEnd - (int) $diagnosticsStart);
smart_gallery_source_map_hardening_assert(
    str_contains($diagnosticsSource, 'smart_gallery_model_count_map_images')
    && !str_contains($diagnosticsSource, 'smart_gallery_model_query_map_images')
    && !str_contains($diagnosticsSource, 'image_map_point('),
    'Page-level map diagnostics must count canonical GPS rows without materializing marker DTOs.'
);
smart_gallery_source_map_hardening_assert(
    str_contains($controller, "'source_context' => [")
    && str_contains($controller, "'page_source_context_cards' => \$sourceContextCardCount")
    && str_contains($controller, "'map' => [")
    && str_contains($controller, "'gps_images' => (int) (\$mapDiagnostics['gps_images'] ?? 0)")
    && !str_contains($controller, "'points' => \$mapDiagnostics"),
    'Admin Test Run Smart Gallery component must expose bounded source/map counts without marker/location payloads.'
);
smart_gallery_source_map_hardening_assert(
    str_contains($service, "'source_gallery_rows'")
    && str_contains($service, 'function smart_gallery_source_galleries_by_ids')
    && str_contains($service, 'smart_gallery_all_source_gallery_rows()'),
    'Source-gallery provenance and GPS inheritance must reuse one request-cached physical-gallery inventory.'
);
smart_gallery_source_map_hardening_assert(
    str_contains($service, 'function smart_gallery_source_contexts')
    && str_contains($service, "'breadcrumb' =>")
    && str_contains($service, "'breadcrumb_compact' =>")
    && str_contains($service, 'content_localize_entities(\'gallery\', array_values($ancestorRows), $contentLanguage)'),
    'Source-gallery breadcrumbs must use the request-cached hierarchy and one batched ancestor-localization pass.'
);
smart_gallery_source_map_hardening_assert(
    str_contains($model, 'GROUP BY i.gallery_id ORDER BY image_count DESC, i.gallery_id ASC')
    && str_contains($model, 'SELECT i.id, i.gallery_id, i.filename, i.url_slug, i.title, i.description, i.content_language, i.gps_lat, i.gps_lng'),
    'Source summary and map projection must remain bounded model-owned projections rather than per-result queries or SELECT *.'
);

smart_gallery_source_map_hardening_assert(
    str_contains($view, '<a class="smart-gallery-source-badge"')
    && str_contains($view, '<summary>')
    && str_contains($view, '<button type="button" class="button secondary hero-icon-button smart-gallery-map-button"'),
    'Source provenance, disclosure summary, and map action must retain native keyboard-operable elements.'
);
smart_gallery_source_map_hardening_assert(
    str_contains($css, '.smart-gallery-source-badge:focus-visible')
    && str_contains($css, '.smart-gallery-source-filter-chip:focus-visible')
    && str_contains($css, '@media (pointer: coarse)')
    && str_contains($css, '.hero-actions .smart-gallery-map-button'),
    'Smart Gallery source/map controls must retain visible focus handling and coarse-pointer touch sizing.'
);
smart_gallery_source_map_hardening_assert(
    str_contains($lightboxView, 'data-lightbox-source-gallery')
    && str_contains($lightboxView, 'data-source-gallery-breadcrumb')
    && str_contains($lightboxJs, 'sourceGalleryBreadcrumb')
    && str_contains($lightboxJs, 'data-map-smart-gallery-context')
    && str_contains($lightboxJs, 'map-popup-source-gallery'),
    'Shared lightbox/map UI must keep breadcrumb-aware source provenance and Smart Gallery-context marker navigation wired.'
);

$translationKeys = [
    'smart_gallery.source_gallery_visible',
    'smart_gallery.map_enabled',
    'smart_gallery.show_map',
    'smart_gallery.source_gallery',
    'smart_gallery.source_gallery_link',
    'smart_gallery.source_summary',
    'smart_gallery.source_filter_active',
    'smart_gallery.source_filter_label',
    'smart_gallery.source_filter_all',
    'smart_gallery.source_filter_one',
    'smart_gallery.source_open_gallery',
];
foreach (['en', 'cs', 'de', 'sv'] as $language) {
    $catalog = json_decode((string) file_get_contents($root . '/app/lang/' . $language . '.json'), true);
    smart_gallery_source_map_hardening_assert(is_array($catalog), 'Translation catalog must decode: ' . $language);
    foreach ($translationKeys as $key) {
        smart_gallery_source_map_hardening_assert(
            isset($catalog[$key]) && trim((string) $catalog[$key]) !== '',
            'Smart Gallery source/map translation missing for ' . $language . ': ' . $key
        );
    }
}

foreach ([
    '## Source-gallery provenance and temporary source filtering',
    '## Aggregate GPS maps',
    '## Diagnostics and performance verification',
    'The map endpoint never broadens source-gallery disclosure.',
    'The current hard cap is 10,000 markers.',
] as $requiredDocumentation) {
    smart_gallery_source_map_hardening_assert(
        str_contains($docs, $requiredDocumentation),
        'Permanent Smart Gallery documentation missing final source/map architecture: ' . $requiredDocumentation
    );
}

fwrite(STDOUT, "Smart Gallery source/map hardening tests passed.\n");
