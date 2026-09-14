<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/seo.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders crawler-facing metadata for public gallery pages.
 *
 * Responsibilities:
 *   - Emit escaped meta and link tags
 *   - Build JSON-LD from public gallery view models
 *   - Keep SEO HTML generation out of generic helpers and controllers
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
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;

/**
 * Handle view render meta tag.
 *
 * Used by server-rendered view helpers.
 *
 * @param string $attributeName Attribute name value.
 * @param string $attributeValue Attribute value value.
 * @param string $content Content value.
 */
function view_render_meta_tag(string $attributeName, string $attributeValue, string $content): void
{
    echo '<meta ' . $attributeName . '="' . e($attributeValue) . '" content="' . e($content) . '">' . "\n";
}

/**
 * Handle view render link tag.
 *
 * Used by server-rendered view helpers.
 *
 * @param string $rel Rel value.
 * @param string $href Href value.
 */
function view_render_link_tag(string $rel, string $href): void
{
    echo '<link rel="' . e($rel) . '" href="' . e($href) . '">' . "\n";
}

/**
 * Handle view render public seo tags.
 *
 * Used by server-rendered view helpers.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param array $images Images value.
 */
function view_render_public_seo_tags(array $model): void
{
    $title = (string) ($model['title'] ?? '');
    $description = (string) ($model['description'] ?? '');
    $canonical = (string) ($model['canonical'] ?? '');
    $previewImage = is_array($model['preview_image'] ?? null) ? $model['preview_image'] : null;
    $ogImage = (string) ($previewImage['url'] ?? '');

    if ($canonical !== '') {
        view_render_link_tag('canonical', $canonical);
    }
    view_render_meta_tag('name', 'description', $description);
    view_render_meta_tag('property', 'og:type', 'website');
    view_render_meta_tag('property', 'og:title', $title);
    view_render_meta_tag('property', 'og:description', $description);
    if ($canonical !== '') {
        view_render_meta_tag('property', 'og:url', $canonical);
    }
    view_render_meta_tag('property', 'og:site_name', (string) ($model['site_name'] ?? ''));
    view_render_meta_tag('property', 'og:locale', (string) ($model['og_locale'] ?? 'en_US'));
    if ($previewImage !== null) {
        view_render_meta_tag('property', 'og:image', (string) ($previewImage['url'] ?? ''));
        view_render_meta_tag('property', 'og:image:url', (string) ($previewImage['url'] ?? ''));
        if (str_starts_with((string) ($previewImage['secure_url'] ?? ''), 'https://')) {
            view_render_meta_tag('property', 'og:image:secure_url', (string) $previewImage['secure_url']);
        }
        view_render_meta_tag('property', 'og:image:type', (string) ($previewImage['type'] ?? ''));
        view_render_meta_tag('property', 'og:image:width', (string) ($previewImage['width'] ?? ''));
        view_render_meta_tag('property', 'og:image:height', (string) ($previewImage['height'] ?? ''));
        view_render_meta_tag('property', 'og:image:alt', (string) ($previewImage['alt'] ?? ''));
        view_render_meta_tag('name', 'image', (string) ($previewImage['url'] ?? ''));
        view_render_meta_tag('itemprop', 'image', (string) ($previewImage['url'] ?? ''));
    }
    view_render_meta_tag('name', 'twitter:card', $ogImage !== '' ? 'summary_large_image' : 'summary');
    view_render_meta_tag('name', 'twitter:title', $title);
    view_render_meta_tag('name', 'twitter:description', $description);
    if ($canonical !== '') {
        view_render_meta_tag('name', 'twitter:url', $canonical);
    }
    if ($previewImage !== null) {
        view_render_meta_tag('name', 'twitter:image', (string) ($previewImage['url'] ?? ''));
        view_render_meta_tag('name', 'twitter:image:src', (string) ($previewImage['url'] ?? ''));
        view_render_meta_tag('name', 'twitter:image:alt', (string) ($previewImage['alt'] ?? ''));
    }
}

/**
 * Handle view render gallery json ld.
 *
 * Used by server-rendered view helpers.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param array $images Images value.
 * @param array $publicMediaManifest Request-local media manifest keyed by image id.
 */
function view_render_gallery_json_ld(array $jsonLd): void
{
    if ($jsonLd === []) {
        return;
    }
    $json = json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    echo '<script type="application/ld+json">' . str_replace('</', '<\/', $json) . '</script>';
}


/**
 * Render the public sitemap XML body from controller-prepared entries.
 *
 * @param array<int, array<string, mixed>> $entries Sitemap entries.
 */
function view_render_sitemap_xml(array $entries): void
{
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';
    foreach ($entries as $entry) {
        $loc = trim((string) ($entry['loc'] ?? ''));
        if ($loc === '') {
            continue;
        }
        echo '<url>';
        echo '<loc>' . e($loc) . '</loc>';
        if (!empty($entry['lastmod'])) {
            echo '<lastmod>' . e((string) $entry['lastmod']) . '</lastmod>';
        }
        if (!empty($entry['priority'])) {
            echo '<priority>' . e((string) $entry['priority']) . '</priority>';
        }
        foreach ((array) ($entry['images'] ?? []) as $image) {
            $imageLoc = trim((string) ($image['loc'] ?? ''));
            if ($imageLoc === '') {
                continue;
            }
            echo '<image:image>';
            echo '<image:loc>' . e($imageLoc) . '</image:loc>';
            if (!empty($image['title'])) {
                echo '<image:title>' . e((string) $image['title']) . '</image:title>';
            }
            if (!empty($image['caption'])) {
                echo '<image:caption>' . e((string) $image['caption']) . '</image:caption>';
            }
            echo '</image:image>';
        }
        echo '</url>';
    }
    echo '</urlset>';
}
