<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/leaflet_marker_rendering_model_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects the Safari-safe CSS-only Leaflet marker rendering contract used by
 *   normal public maps and fullscreen lightbox split maps.
 *
 * Responsibilities:
 *   - Keep gallery markers independent from Leaflet's default PNG marker URLs
 *   - Keep explicit divIcon dimensions, anchors, and marker-pane assignment
 *   - Prevent nested CSS transforms from returning inside Leaflet marker icons
 *   - Preserve all public marker roles and their role-specific visual classes
 *   - Verify deferred lightbox imports inherit the server asset revision
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
 *   2026-08-11
 */

declare(strict_types=1);

/**
 * Read a repository text asset or fail the test with context.
 *
 * @param string $relativePath Repository-relative path.
 * @return string File contents.
 */
function leaflet_marker_read_source(string $relativePath): string
{
    $source = file_get_contents(__DIR__ . '/../' . $relativePath);
    if (!is_string($source)) {
        throw new RuntimeException('Could not read ' . $relativePath . '.');
    }
    return $source;
}

/**
 * Require an exact source fragment.
 *
 * @param string $source Source text.
 * @param string $needle Required fragment.
 * @param string $label Failure label.
 */
function leaflet_marker_assert_contains(string $source, string $needle, string $label): void
{
    if (!str_contains($source, $needle)) {
        throw new RuntimeException($label . ' did not contain ' . var_export($needle, true) . '.');
    }
}

/**
 * Reject an exact source fragment.
 *
 * @param string $source Source text.
 * @param string $needle Forbidden fragment.
 * @param string $label Failure label.
 */
function leaflet_marker_assert_not_contains(string $source, string $needle, string $label): void
{
    if (str_contains($source, $needle)) {
        throw new RuntimeException($label . ' unexpectedly contained ' . var_export($needle, true) . '.');
    }
}

$lightbox = leaflet_marker_read_source('public/assets/gallery-modules/lightbox.js');
$deferred = leaflet_marker_read_source('public/assets/gallery-modules/lightbox-deferred.js');
$sharedCss = leaflet_marker_read_source('public/assets/styles/public-shared.css');
$helpers = leaflet_marker_read_source('app/helpers.php')
    . leaflet_marker_read_source('app/helpers_page_rendering.php');
$layout = leaflet_marker_read_source('app/views/layout.php');

leaflet_marker_assert_contains($lightbox, 'L.divIcon({', 'lightbox marker factory');
leaflet_marker_assert_contains($lightbox, 'className: `gallery-leaflet-marker gallery-leaflet-marker--${markerRole}`', 'lightbox marker class');
leaflet_marker_assert_contains($lightbox, '<span class="gallery-leaflet-marker-tail" aria-hidden="true"></span>', 'Safari-safe marker tail');
leaflet_marker_assert_contains($lightbox, "pane: 'markerPane'", 'marker pane assignment');
leaflet_marker_assert_contains($lightbox, 'const marker = L.marker([point.lat, point.lng], {', 'Leaflet marker creation');
leaflet_marker_assert_contains($lightbox, '}).addTo(map);', 'Leaflet marker attachment');
leaflet_marker_assert_contains($lightbox, 'marker.bindPopup(mapPopupHtml(point));', 'marker popup binding');
leaflet_marker_assert_contains($lightbox, "const iconSize = isActivePhoto ? [32, 46] : (isRouteVia ? [8, 8] : [26, 40]);", 'marker icon geometry');
leaflet_marker_assert_contains($lightbox, "const iconAnchor = isActivePhoto ? [16, 39] : (isRouteVia ? [4, 4] : [13, 31]);", 'marker icon anchors');
leaflet_marker_assert_contains($lightbox, "return 'active-photo';", 'active-photo role');
leaflet_marker_assert_contains($lightbox, "return 'route-start';", 'route-start role');
leaflet_marker_assert_contains($lightbox, "return 'route-end';", 'route-end role');
leaflet_marker_assert_contains($lightbox, "return 'route-via';", 'route-via role');
leaflet_marker_assert_contains($lightbox, "return 'route';", 'route role');
leaflet_marker_assert_contains($lightbox, "return 'photo';", 'photo role');

$markerCssStart = strpos($sharedCss, '.leaflet-container .leaflet-marker-pane');
$markerCssEnd = strpos($sharedCss, '.image-detail-panel', $markerCssStart === false ? 0 : $markerCssStart);
if ($markerCssStart === false || $markerCssEnd === false || $markerCssEnd <= $markerCssStart) {
    throw new RuntimeException('Could not isolate the Leaflet marker CSS contract.');
}
$markerCss = substr($sharedCss, $markerCssStart, $markerCssEnd - $markerCssStart);

leaflet_marker_assert_contains($markerCss, 'z-index: 600 !important;', 'marker pane z-index');
leaflet_marker_assert_contains($markerCss, 'filter: none !important;', 'marker pane filtering reset');
leaflet_marker_assert_contains($markerCss, 'opacity: 1 !important;', 'marker pane opacity');
leaflet_marker_assert_contains($markerCss, 'visibility: visible !important;', 'marker pane visibility');
leaflet_marker_assert_contains($markerCss, '.gallery-leaflet-marker-pin', 'marker pin selector');
leaflet_marker_assert_contains($markerCss, '.gallery-leaflet-marker-tail', 'marker tail selector');
leaflet_marker_assert_contains($markerCss, '.gallery-leaflet-marker-shadow', 'marker shadow selector');
leaflet_marker_assert_contains($markerCss, 'transform: none;', 'untransformed marker geometry');
leaflet_marker_assert_not_contains($markerCss, 'rotate(-45deg)', 'Safari-safe marker CSS');
leaflet_marker_assert_contains($markerCss, '.gallery-leaflet-marker--route-start', 'route-start CSS role');
leaflet_marker_assert_contains($markerCss, '.gallery-leaflet-marker--route-end', 'route-end CSS role');
leaflet_marker_assert_contains($markerCss, '.gallery-leaflet-marker--route-via', 'route-via CSS role');
leaflet_marker_assert_contains($markerCss, '.gallery-leaflet-marker--route', 'route CSS role');
leaflet_marker_assert_contains($markerCss, '.gallery-leaflet-marker--photo', 'photo CSS role');
leaflet_marker_assert_contains($markerCss, '.gallery-leaflet-marker--active-photo', 'active-photo CSS role');

leaflet_marker_assert_contains($deferred, "new URL('./lightbox.js', import.meta.url)", 'deferred lightbox URL construction');
leaflet_marker_assert_contains($deferred, "script[data-gallery-asset-revision]", 'server asset revision lookup');
leaflet_marker_assert_contains($deferred, "moduleUrl.searchParams.set('v', assetRevision)", 'deferred lightbox cache revision');
leaflet_marker_assert_not_contains($deferred, "./lightbox.js?v=", 'hard-coded deferred lightbox revision');

foreach ([$helpers, $layout] as $assetRendererSource) {
    leaflet_marker_assert_contains($assetRendererSource, "gallery-modules/lightbox.js'", 'anonymous lightbox asset-version dependency');
    leaflet_marker_assert_contains($assetRendererSource, 'data-gallery-asset-revision=', 'entrypoint revision data attribute');
}

$defaultPngCall = strpos($lightbox, 'L.Icon.Default.mergeOptions');
$divIconCall = strpos($lightbox, 'L.divIcon({');
if ($defaultPngCall === false || $divIconCall === false) {
    throw new RuntimeException('Expected both Leaflet default-icon configuration and gallery divIcon creation.');
}
if ($defaultPngCall >= $divIconCall) {
    throw new RuntimeException('Unexpected marker factory order while checking default PNG independence.');
}

$divIconFactoryEndNeedle = 'return window.galleryMapMarkerIcons[markerRole];';
$divIconFactoryEnd = strpos($lightbox, $divIconFactoryEndNeedle, $divIconCall);
if ($divIconFactoryEnd === false) {
    throw new RuntimeException('Could not isolate the gallery divIcon factory.');
}
$divIconFactory = substr($lightbox, $divIconCall, $divIconFactoryEnd - $divIconCall + strlen($divIconFactoryEndNeedle));
leaflet_marker_assert_not_contains($divIconFactory, 'marker-icon.png', 'gallery divIcon PNG independence');
leaflet_marker_assert_not_contains($divIconFactory, 'marker-shadow.png', 'gallery divIcon PNG independence');

echo "Leaflet marker rendering model tests passed.\n";
