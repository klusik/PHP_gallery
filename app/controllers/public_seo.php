<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/public_seo.php
 * Module Type: Controller
 *
 * Purpose:
 *   Prepares crawler-facing SEO view models for public gallery pages.
 *
 * Responsibilities:
 *   - Resolve canonical/social metadata before Views render tags
 *   - Resolve safe JSON-LD image entries and thumbnail fallbacks
 *   - Apply NSFW, sitemap date, metadata, and profiling policies outside Views
 *   - Keep SEO Views independent from Service-layer calls
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
 *   - This controller prepares data only and must not emit SEO markup.
 *
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Core\absolute_public_url;
use function Gallery\Core\canonical_url_for_gallery;
use function Gallery\Core\gallery_seo_description;
use function Gallery\Core\gallery_seo_title;
use function Gallery\Core\gallery_social_preview_image;
use function Gallery\Core\image_alt_text;
use function Gallery\Core\image_public_url;
use function Gallery\Services\content_language_og_locale;
use function Gallery\Services\image_nsfw_restricted;
use function Gallery\Services\public_gallery_metadata;
use function Gallery\Services\public_render_profile_count;
use function Gallery\Services\public_render_profile_with_thumbnail_purpose;
use function Gallery\Services\public_sitemap_image_last_modified;
use function Gallery\Services\public_sitemap_lastmod;
use function Gallery\Services\site_name;
use function Gallery\Services\thumbnail_url;
use function Gallery\Services\translation_active_language;

/**
 * Prepare canonical, OpenGraph, and Twitter metadata for one public gallery.
 *
 * @param array<string,mixed> $gallery Gallery row.
 * @param array<int,array<string,mixed>> $images Public gallery images.
 * @return array<string,mixed>
 */
function public_seo_tags_view_model(array $gallery, array $images = []): array
{
    return [
        'title' => gallery_seo_title($gallery),
        'description' => gallery_seo_description($gallery),
        'canonical' => canonical_url_for_gallery($gallery),
        'preview_image' => gallery_social_preview_image($gallery, $images),
        'site_name' => site_name(),
        'og_locale' => content_language_og_locale(translation_active_language()),
    ];
}

/**
 * Prepare ImageGallery JSON-LD data for one public gallery.
 *
 * @param array<string,mixed> $gallery Gallery row.
 * @param array<int,array<string,mixed>> $images Public gallery images.
 * @param array<int,array<string,mixed>> $publicMediaManifest Request-local media manifest keyed by image id.
 * @return array<string,mixed>
 */
function public_gallery_json_ld_view_model(array $gallery, array $images = [], array $publicMediaManifest = []): array
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
        $manifestEntry = is_array($publicMediaManifest[(int) ($image['id'] ?? 0)] ?? null)
            ? $publicMediaManifest[(int) ($image['id'] ?? 0)]
            : [];
        $contentUrl = (string) ($manifestEntry['seo_content_url'] ?? '');
        if ($contentUrl === '') {
            $contentUrl = public_render_profile_with_thumbnail_purpose(
                'seo json-ld visible content 1200 fallback',
                static fn (): string => thumbnail_url($image, 1200, 'jpg')
            );
        } else {
            public_render_profile_count('seo_json_ld_manifest_hits');
        }
        $thumbnailUrl = (string) ($manifestEntry['seo_thumbnail_url'] ?? '');
        if ($thumbnailUrl === '') {
            $thumbnailUrl = public_render_profile_with_thumbnail_purpose(
                'seo json-ld thumbnail 800 fallback',
                static fn (): string => thumbnail_url($image, 800, 'jpg')
            );
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
        $dateModified = public_sitemap_lastmod(public_sitemap_image_last_modified($image));
        if ($dateModified !== null) {
            $item['dateModified'] = $dateModified;
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
    return $jsonLd;
}
