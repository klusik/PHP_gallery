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
 *   2026-05-24
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\absolute_public_url;
use function Gallery\Core\canonical_url_for_gallery;
use function Gallery\Core\e;
use function Gallery\Core\gallery_seo_description;
use function Gallery\Core\gallery_seo_title;
use function Gallery\Core\gallery_social_preview_image;
use function Gallery\Core\image_alt_text;
use function Gallery\Core\image_public_url;
use function Gallery\Services\image_nsfw_restricted;
use function Gallery\Services\public_gallery_metadata;
use function Gallery\Services\public_render_profile_count;
use function Gallery\Services\public_render_profile_with_thumbnail_purpose;
use function Gallery\Services\public_sitemap_image_last_modified;
use function Gallery\Services\public_sitemap_lastmod;
use function Gallery\Services\site_name;
use function Gallery\Services\thumbnail_url;

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
function view_render_public_seo_tags(array $gallery, array $images = []): void
{
    $title = gallery_seo_title($gallery);
    $description = gallery_seo_description($gallery);
    $canonical = canonical_url_for_gallery($gallery);
    $previewImage = gallery_social_preview_image($gallery, $images);
    $ogImage = $previewImage['url'] ?? '';

    view_render_link_tag('canonical', $canonical);
    view_render_meta_tag('name', 'description', $description);
    view_render_meta_tag('property', 'og:type', 'website');
    view_render_meta_tag('property', 'og:title', $title);
    view_render_meta_tag('property', 'og:description', $description);
    view_render_meta_tag('property', 'og:url', $canonical);
    view_render_meta_tag('property', 'og:site_name', site_name());
    view_render_meta_tag('property', 'og:locale', 'cs_CZ');
    if ($previewImage !== null) {
        view_render_meta_tag('property', 'og:image', $previewImage['url']);
        view_render_meta_tag('property', 'og:image:url', $previewImage['url']);
        if (str_starts_with((string) $previewImage['secure_url'], 'https://')) {
            view_render_meta_tag('property', 'og:image:secure_url', $previewImage['secure_url']);
        }
        view_render_meta_tag('property', 'og:image:type', $previewImage['type']);
        view_render_meta_tag('property', 'og:image:width', (string) $previewImage['width']);
        view_render_meta_tag('property', 'og:image:height', (string) $previewImage['height']);
        view_render_meta_tag('property', 'og:image:alt', $previewImage['alt']);
        view_render_meta_tag('name', 'image', $previewImage['url']);
        view_render_meta_tag('itemprop', 'image', $previewImage['url']);
    }
    view_render_meta_tag('name', 'twitter:card', $ogImage !== '' ? 'summary_large_image' : 'summary');
    view_render_meta_tag('name', 'twitter:title', $title);
    view_render_meta_tag('name', 'twitter:description', $description);
    view_render_meta_tag('name', 'twitter:url', $canonical);
    if ($previewImage !== null) {
        view_render_meta_tag('name', 'twitter:image', $previewImage['url']);
        view_render_meta_tag('name', 'twitter:image:src', $previewImage['url']);
        view_render_meta_tag('name', 'twitter:image:alt', $previewImage['alt']);
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
function view_render_gallery_json_ld(array $gallery, array $images = [], array $publicMediaManifest = []): void
{
    $items = [];
    $position = 1;
    $jsonLdImages = array_slice($images, 0, 20);
    public_render_profile_count('seo_json_ld_images', count($jsonLdImages));

    foreach ($jsonLdImages as $image) {
        if (image_nsfw_restricted($image, $gallery)) {
            continue;
        }
        $imageName = image_alt_text($image, $gallery, $position);
        $manifestEntry = is_array($publicMediaManifest[(int) ($image['id'] ?? 0)] ?? null) ? $publicMediaManifest[(int) ($image['id'] ?? 0)] : [];
        $contentUrl = (string) ($manifestEntry['seo_content_url'] ?? '');
        if ($contentUrl === '') {
            $contentUrl = public_render_profile_with_thumbnail_purpose('seo json-ld visible content 1200 fallback', static fn (): string => thumbnail_url($image, 1200, 'jpg'));
        } else {
            public_render_profile_count('seo_json_ld_manifest_hits');
        }
        $thumbnailUrl = (string) ($manifestEntry['seo_thumbnail_url'] ?? '');
        if ($thumbnailUrl === '') {
            $thumbnailUrl = public_render_profile_with_thumbnail_purpose('seo json-ld thumbnail 800 fallback', static fn (): string => thumbnail_url($image, 800, 'jpg'));
        } else {
            public_render_profile_count('seo_json_ld_manifest_hits');
        }
        $item = [
            '@type' => 'ImageObject',
            'position' => $position++,
            'name' => $imageName,
            'description' => trim((string) ($image['description'] ?? '')) !== '' ? trim((string) $image['description']) : $imageName,
            'contentUrl' => absolute_public_url($contentUrl),
            'thumbnailUrl' => absolute_public_url($thumbnailUrl),
            'url' => absolute_public_url(image_public_url($image, $gallery)),
        ];
        if (!empty($image['width'])) {
            $item['width'] = (int) $image['width'];
        }
        if (!empty($image['height'])) {
            $item['height'] = (int) $image['height'];
        }
        if (function_exists('Gallery\\Services\\public_sitemap_lastmod')) {
            $dateModified = public_sitemap_lastmod(public_sitemap_image_last_modified($image));
            if ($dateModified !== null) {
                $item['dateModified'] = $dateModified;
            }
        }
        $items[] = $item;
    }

    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'ImageGallery',
        'name' => gallery_seo_title($gallery),
        'description' => gallery_seo_description($gallery),
        'url' => canonical_url_for_gallery($gallery),
        'image' => $items,
    ];
    $metadata = public_gallery_metadata($gallery);
    if (!empty($metadata['tags'])) {
        $jsonLd['keywords'] = $metadata['tags'];
    }
    $json = json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    echo '<script type="application/ld+json">' . str_replace('</', '<\/', $json) . '</script>';
}
