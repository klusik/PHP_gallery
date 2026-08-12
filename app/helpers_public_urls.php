<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/helpers_public_urls.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Provides public gallery/image URL, SEO metadata, social preview, and sitemap rendering helpers.
 *
 * Responsibilities:
 *   - Support shared project infrastructure
 *   - Keep behavior compatible with existing controllers and services
 *   - Avoid unnecessary coupling to presentation code
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

namespace Gallery\Core;

use PDO;
use RuntimeException;
use function Gallery\Services\app_setting;
use function Gallery\Services\application_update_nav_label;
use function Gallery\Services\application_update_pending;
use function Gallery\Services\cms_github_project_url;
use function Gallery\Services\custom_css_path;
use function Gallery\Services\custom_css_url;
use function Gallery\Services\dev_mode_enabled;
use function Gallery\Services\dng_conversion_supported;
use function Gallery\Services\favicon_asset_url;
use function Gallery\Services\feature_flag_enabled;
use function Gallery\Services\find_gallery;
use function Gallery\Services\gallery_branding_asset_url;
use function Gallery\Services\gallery_branding_schema_ready;
use function Gallery\Services\gallery_cover_collage_images;
use function Gallery\Services\gallery_cover_image;
use function Gallery\Services\gallery_nsfw_requirement;
use function Gallery\Services\heic_conversion_supported;
use function Gallery\Services\image_nsfw_restricted;
use function Gallery\Services\public_gallery_metadata;
use function Gallery\Services\public_gallery_sitemap_entries;
use function Gallery\Services\public_render_profile_count;
use function Gallery\Services\public_render_profile_with_thumbnail_purpose;
use function Gallery\Services\public_sitemap_entries;
use function Gallery\Services\public_sitemap_image_last_modified;
use function Gallery\Services\public_sitemap_lastmod;
use function Gallery\Services\site_name;
use function Gallery\Services\t;
use function Gallery\Services\theme_branding_asset_url;
use function Gallery\Services\theme_favorite_gallery_navigation_items;
use function Gallery\Services\theme_page_width_mode;
use function Gallery\Services\theme_settings;
use function Gallery\Services\thumbnail_abs_path;
use function Gallery\Services\thumbnail_bound_filter_sizes;
use function Gallery\Services\thumbnail_existing_fallback;
use function Gallery\Services\thumbnail_metadata_select_renderable_variant;
use function Gallery\Services\thumbnail_serving_url;
use function Gallery\Services\thumbnail_sizes;
use function Gallery\Services\thumbnail_url;
use function Gallery\Services\translation_active_language;
use function Gallery\Services\translation_default_language;
use function Gallery\Services\translation_load_language;
use function Gallery\Services\url_rewrite_should_emit_clean_urls;
use function Gallery\Views\view_admin_menu_item_is_active;
use function Gallery\Views\view_admin_menu_structure;
use function Gallery\Views\view_cms_browser_i18n_strings;
use function Gallery\Views\view_public_header_branding_model;
use function Gallery\Views\view_render_admin_sidebar;
use function Gallery\Views\view_render_admin_subtab_panel;
use function Gallery\Views\view_render_admin_subtabs;
use function Gallery\Views\view_render_admin_tab_panel;
use function Gallery\Views\view_render_admin_tabs;
use function Gallery\Views\view_render_browser_i18n_script;
use function Gallery\Views\view_render_footer;
use function Gallery\Views\view_render_gallery_json_ld;
use function Gallery\Views\view_render_header;
use function Gallery\Views\view_render_link_tag;
use function Gallery\Views\view_render_meta_tag;
use function Gallery\Views\view_render_missing_admin_email_notice;
use function Gallery\Views\view_render_public_seo_tags;

/**
 * Encode one relative gallery path for clean public URLs while preserving slashes.
 *
 * @param string $folderPath Folder path filesystem path.
 * @return string Text result for the caller.
 */
function gallery_public_path_segment(string $folderPath): string
{
    return public_path_segment($folderPath);
}

/**
 * Build the preferred public URL for one gallery, using its clean public path when available.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return string Text result for the caller.
 */
function gallery_public_url(array $gallery): string
{
    // $urlPath stores an intermediate value used by the surrounding gallery workflow.
    $urlPath = trim((string) ($gallery['url_path'] ?? ''), '/');
    if ($urlPath === '') {
        // A globally unique stored slug is a safer legacy fallback than exposing
        // physical folder names containing spaces, accents, or private naming.
        $urlPath = trim((string) ($gallery['slug'] ?? ''), '/');
    }
    if ($urlPath === '') {
        $folderName = basename(str_replace('\\', '/', trim((string) ($gallery['folder_path'] ?? ''), '/')));
        $urlPath = slugify((string) (($gallery['title'] ?? '') ?: $folderName ?: 'gallery'));
    }
    if (!url_rewrite_should_emit_clean_urls()) {
        return url_for('gallery', ['public_path' => $urlPath]);
    }
    return public_base_url() . '/gallery/' . public_path_segment($urlPath) . '/';
}

/**
 * Build the route-level public path for one image.
 *
 * The returned value is independent from URL rewrite support so callers can
 * safely choose either clean path routing or index.php query-string routing.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @return string Public gallery/image path without a leading or trailing slash.
 */
function image_public_route_path(array $image, array $gallery): string
{
    // $slug stores the stable public image slug used by both routing modes.
    $slug = trim((string) ($image['url_slug'] ?? ''));
    if ($slug === '') {
        $slug = slugify(pathinfo((string) ($image['filename'] ?? 'image'), PATHINFO_FILENAME));
    } else {
        $slug = slugify($slug);
    }

    // $urlPath stores the gallery portion of the public route.
    $urlPath = trim((string) ($gallery['url_path'] ?? ''), '/');
    if ($urlPath === '') {
        $urlPath = trim((string) ($gallery['slug'] ?? ''), '/');
    }
    if ($urlPath === '') {
        $folderName = basename(str_replace('\\', '/', trim((string) ($gallery['folder_path'] ?? ''), '/')));
        $urlPath = slugify((string) (($gallery['title'] ?? '') ?: $folderName ?: 'gallery'));
    }

    return trim($urlPath . '/' . $slug, '/');
}

/**
 * Build the preferred public URL for one image detail page.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @return string Text result for the caller.
 */
function image_public_url(array $image, array $gallery): string
{
    // $publicPath stores the rewrite-independent gallery/image route.
    $publicPath = image_public_route_path($image, $gallery);
    if (!url_rewrite_should_emit_clean_urls()) {
        return url_for('gallery', ['public_path' => $publicPath]);
    }
    return public_base_url() . '/gallery/' . public_path_segment($publicPath) . '/';
}


/**
 * Build the preferred public media URL for one original image file.
 *
 * Query-string installations must use the dedicated public_media route rather
 * than appending /media after an index.php query string.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @return string Text result for the caller.
 */
function image_public_media_url(array $image, array $gallery): string
{
    if (!url_rewrite_should_emit_clean_urls()) {
        return image_public_asset_url_with_version(url_for('public_media', ['public_path' => image_public_route_path($image, $gallery)]), $image);
    }
    return image_public_asset_url_with_version(rtrim(image_public_url($image, $gallery), '/') . '/media', $image);
}

/**
 * Return a public media or thumbnail URL without adding cache-version parameters.
 *
 * Rewritten installations use clean image paths, while rewrite-disabled
 * installations already depend on routing query parameters. Cache invalidation
 * for replaced media and regenerated thumbnails must therefore not append an
 * additional version parameter that could change routing behavior on shared hosting.
 *
 * @param string $url Public media or thumbnail URL.
 * @param array $image Image row or image data.
 * @return string Unmodified public URL.
 */
function image_public_asset_url_with_version(string $url, array $image): string
{
    unset($image);

    return $url;
}

/**
 * Build a stable cache version for media and thumbnail URLs.
 *
 * @param array $image Image row or image data.
 * @return string Compact cache version.
 */
function image_public_asset_version(array $image): string
{
    unset($image);

    return '';
}

/**
 * Build the preferred public thumbnail URL for one generated image variant.
 *
 * Query-string installations must use the dedicated public_thumb route rather
 * than appending /thumb-N.ext after an index.php query string.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param int $size Size value.
 * @param string $format Format value.
 * @return string Text result for the caller.
 */
function image_public_thumbnail_url(array $image, array $gallery, int $size, string $format = 'jpg'): string
{
    // $format stores an intermediate value used by the surrounding gallery workflow.
    $format = $format === 'webp' ? 'webp' : 'jpg';
    if (!url_rewrite_should_emit_clean_urls()) {
        return image_public_asset_url_with_version(url_for('public_thumb', [
            'public_path' => image_public_route_path($image, $gallery),
            'size' => $size,
            'format' => $format,
        ]), $image);
    }
    return image_public_asset_url_with_version(rtrim(image_public_url($image, $gallery), '/') . '/thumb-' . $size . '.' . $format, $image);
}

/**
 * Build the canonical public URL for one gallery.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return string Text result for the caller.
 */
function canonical_url_for_gallery(array $gallery): string
{
    return gallery_public_url($gallery);
}

/**
 * Return the best public title for one gallery page.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return string Text result for the caller.
 */
function gallery_seo_title(array $gallery): string
{
    // $metadata stores an intermediate value used by the surrounding gallery workflow.
    $metadata = public_gallery_metadata($gallery);
    return $metadata['title'];
}

/**
 * Return the best public description for one gallery page.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return string Text result for the caller.
 */
function gallery_seo_description(array $gallery): string
{
    // $metadata stores an intermediate value used by the surrounding gallery workflow.
    $metadata = public_gallery_metadata($gallery);
    return $metadata['description'];
}

/**
 * Build safe alt text for one gallery image.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param int $index Index value.
 * @return string Text result for the caller.
 */
function image_alt_text(array $image, array $gallery, int $index = 1): string
{
    // $caption stores an intermediate value used by the surrounding gallery workflow.
    $caption = trim((string) ($image['description'] ?? ''));
    if ($caption !== '') {
        return $caption;
    }
    // $title stores an intermediate value used by the surrounding gallery workflow.
    $title = trim((string) ($image['title'] ?? ''));
    if ($title !== '') {
        return $title;
    }
    // $filename stores an intermediate value used by the surrounding gallery workflow.
    $filename = trim((string) ($image['filename'] ?? ''));
    if ($filename !== '') {
        return trim(preg_replace('/[-_]+/', ' ', pathinfo($filename, PATHINFO_FILENAME)) ?: $filename);
    }
    return (string) ($gallery['title'] ?? 'Gallery') . ' image ' . $index;
}

/**
 * Build the strongest social-preview image candidate for a gallery page.
 *
 * Link-preview crawlers are much stricter than normal browsers. Discord,
 * WhatsApp, Facebook, Slack, X/Twitter, and similar consumers behave most
 * consistently when the first image has a stable absolute URL, a real
 * Content-Type, explicit pixel dimensions, and alt text. This helper prefers a
 * generated JPEG thumbnail when DB metadata says one exists, but it can use a
 * generated WebP thumbnail for WebP-only galleries instead of probing files.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param array $images Images value.
 * @return array{url:string,secure_url:string,type:string,width:int,height:int,alt:string}|null Structured result data for the caller.
 */
function gallery_social_preview_image(array $gallery, array $images = []): ?array
{
    if (gallery_nsfw_requirement($gallery) !== null) {
        return null;
    }
    // $candidates stores images in priority order while avoiding duplicate ids.
    $candidates = [];
    // $seenIds stores ids that were already added to the candidate list.
    $seenIds = [];

    foreach ($images as $image) {
        // $imageId stores the normalized image id used for duplicate protection.
        $imageId = (int) ($image['id'] ?? 0);
        if ($imageId <= 0 || isset($seenIds[$imageId]) || (int) ($image['nsfw_enabled'] ?? 0) === 1) {
            continue;
        }
        $seenIds[$imageId] = true;
        $candidates[] = $image;
    }

    // $cover stores the configured or inferred direct cover image for this gallery.
    $cover = gallery_cover_image((int) ($gallery['id'] ?? 0), true);
    if ($cover) {
        // $coverId stores the normalized cover image id for duplicate protection.
        $coverId = (int) ($cover['id'] ?? 0);
        if ($coverId > 0 && !isset($seenIds[$coverId]) && !image_nsfw_restricted($cover, find_gallery((int) ($cover['gallery_id'] ?? 0)) ?: $gallery)) {
            $seenIds[$coverId] = true;
            array_unshift($candidates, $cover);
        }
    }

    foreach (gallery_cover_collage_images((int) ($gallery['id'] ?? 0), true, 4) as $descendantCover) {
        // $descendantCoverId stores the normalized image id used for duplicate protection.
        $descendantCoverId = (int) ($descendantCover['id'] ?? 0);
        if ($descendantCoverId <= 0 || isset($seenIds[$descendantCoverId]) || (int) ($descendantCover['nsfw_enabled'] ?? 0) === 1) {
            continue;
        }
        $seenIds[$descendantCoverId] = true;
        $candidates[] = $descendantCover;
    }

    foreach ($candidates as $candidate) {
        // $preview stores the crawler-safe metadata for one generated JPEG thumbnail.
        $preview = social_preview_image_from_thumbnail($candidate, $gallery, 1280);
        if ($preview !== null) {
            return $preview;
        }
    }

    return null;
}

/**
 * Build crawler-facing metadata for one generated thumbnail.
 *
 * @param array $image Image row or image data.
 * @param array $currentGallery Current gallery value.
 * @param int $preferredSize Preferred size value.
 * @return array{url:string,secure_url:string,type:string,width:int,height:int,alt:string}|null Structured result data for the caller.
 */
function social_preview_image_from_thumbnail(array $image, array $currentGallery, int $preferredSize = 1280): ?array
{
    // $imageGallery stores the real gallery for the candidate image. Descendant
    // cover images can belong to child galleries, so using only the current
    // gallery would produce an invalid thumbnail path for parent galleries.
    $imageGallery = find_gallery((int) ($image['gallery_id'] ?? 0));
    if (!$imageGallery || image_nsfw_restricted($image, $imageGallery)) {
        return null;
    }

    // $sizes stores the configured thumbnail sizes the preview may use.
    $sizes = function_exists('Gallery\\Services\\thumbnail_bound_filter_sizes')
        ? thumbnail_bound_filter_sizes(thumbnail_sizes(), $image, $imageGallery)
        : thumbnail_sizes();
    // $selected stores the best crawler preview candidate known from DB metadata only.
    $selected = function_exists('Gallery\\Services\\thumbnail_metadata_select_renderable_variant')
        ? thumbnail_metadata_select_renderable_variant($image, $sizes, $preferredSize, 'jpg', true)
        : null;
    if ($selected === null) {
        try {
            // $fallback stores the closest existing thumbnail for older installations without metadata.
            $fallback = thumbnail_existing_fallback($image, $imageGallery, $preferredSize, 'jpg');
            if ($fallback === null) {
                return null;
            }
            // $thumbnailPath stores the local file for legacy dimension extraction only.
            $thumbnailPath = thumbnail_abs_path($image, $imageGallery, (int) $fallback['size'], (string) $fallback['format']);
        } catch (RuntimeException) {
            return null;
        }

        if (!is_file($thumbnailPath)) {
            return null;
        }

        // $imageSize stores the actual thumbnail dimensions from disk for legacy installations.
        $imageSize = @getimagesize($thumbnailPath);
        if ($imageSize === false || (int) $imageSize[0] < 200 || (int) $imageSize[1] < 200) {
            return null;
        }

        // $selected stores the legacy fallback represented with the same shape used by metadata rows.
        $selected = [
            'size' => (int) $fallback['size'],
            'format' => (string) $fallback['format'],
            'width' => (int) $imageSize[0],
            'height' => (int) $imageSize[1],
        ];
    }

    if ((int) ($selected['width'] ?? 0) < 200 || (int) ($selected['height'] ?? 0) < 200) {
        return null;
    }

    // $format stores the concrete image format selected from metadata.
    $format = (string) ($selected['format'] ?? 'jpg');
    // $url stores the absolute public URL that social crawlers receive.
    $url = absolute_public_url(thumbnail_serving_url($image, $imageGallery, (int) $selected['size'], $format));
    // $alt stores descriptive text for Open Graph and Twitter image metadata.
    $alt = image_alt_text($image, $currentGallery);

    // $previewUrl stores the crawler URL exactly as generated, without adding
    // query parameters. Public thumbnail URLs must remain clean.
    $previewUrl = social_preview_cache_busted_url($url, '');

    return [
        'url' => $previewUrl,
        'secure_url' => preg_replace('#^http://#i', 'https://', $previewUrl) ?: $previewUrl,
        'type' => $format === 'webp' ? 'image/webp' : 'image/jpeg',
        'width' => (int) $selected['width'],
        'height' => (int) $selected['height'],
        'alt' => $alt,
    ];
}

/**
 * Return the social preview image URL without adding a version marker.
 *
 * Public image URLs must stay parameter-free for normal gallery pages and
 * metadata consumers. The file path is accepted for compatibility with older
 * callers, but it is intentionally not used to append cache markers.
 *
 * @param string $url URL used by this workflow.
 * @param string $filePath File path filesystem path.
 * @return string Text result for the caller.
 */
function social_preview_cache_busted_url(string $url, string $filePath): string
{
    unset($filePath);

    return $url;
}

/**
 * Emit one meta tag followed by a newline so crawler diagnostics are readable.
 *
 * @param string $attributeName Attribute name value.
 * @param string $attributeValue Attribute value value.
 * @param string $content Content value.
 */
function render_meta_tag(string $attributeName, string $attributeValue, string $content): void
{
    if (function_exists('Gallery\\Views\\view_render_meta_tag')) {
        view_render_meta_tag($attributeName, $attributeValue, $content);
        return;
    }
    echo '<meta ' . $attributeName . '="' . e($attributeValue) . '" content="' . e($content) . '">' . "\n";
}

/**
 * Emit one link tag followed by a newline so crawler diagnostics are readable.
 *
 * @param string $rel Rel value.
 * @param string $href Href value.
 */
function render_link_tag(string $rel, string $href): void
{
    if (function_exists('Gallery\\Views\\view_render_link_tag')) {
        view_render_link_tag($rel, $href);
        return;
    }
    echo '<link rel="' . e($rel) . '" href="' . e($href) . '">' . "\n";
}

/**
 * Render SEO tags for a gallery page.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param array $images Images value.
 */
function render_public_seo_tags(array $gallery, array $images = []): void
{
    if (function_exists('Gallery\\Views\\view_render_public_seo_tags')) {
        view_render_public_seo_tags($gallery, $images);
        return;
    }
    // $title stores an intermediate value used by the surrounding gallery workflow.
    $title = gallery_seo_title($gallery);
    // $description stores an intermediate value used by the surrounding gallery workflow.
    $description = gallery_seo_description($gallery);
    // $canonical stores an intermediate value used by the surrounding gallery workflow.
    $canonical = canonical_url_for_gallery($gallery);
    // $previewImage stores crawler-safe social image metadata when available.
    $previewImage = gallery_social_preview_image($gallery, $images);
    // $ogImage stores an intermediate value used by the surrounding gallery workflow.
    $ogImage = $previewImage['url'] ?? '';

    render_link_tag('canonical', $canonical);
    render_meta_tag('name', 'description', $description);
    render_meta_tag('property', 'og:type', 'website');
    render_meta_tag('property', 'og:title', $title);
    render_meta_tag('property', 'og:description', $description);
    render_meta_tag('property', 'og:url', $canonical);
    render_meta_tag('property', 'og:site_name', site_name());
    render_meta_tag('property', 'og:locale', 'cs_CZ');
    if ($previewImage !== null) {
        render_meta_tag('property', 'og:image', $previewImage['url']);
        render_meta_tag('property', 'og:image:url', $previewImage['url']);
        if (str_starts_with((string) $previewImage['secure_url'], 'https://')) {
            render_meta_tag('property', 'og:image:secure_url', $previewImage['secure_url']);
        }
        render_meta_tag('property', 'og:image:type', $previewImage['type']);
        render_meta_tag('property', 'og:image:width', (string) $previewImage['width']);
        render_meta_tag('property', 'og:image:height', (string) $previewImage['height']);
        render_meta_tag('property', 'og:image:alt', $previewImage['alt']);
        render_meta_tag('name', 'image', $previewImage['url']);
        render_meta_tag('itemprop', 'image', $previewImage['url']);
    }
    render_meta_tag('name', 'twitter:card', $ogImage !== '' ? 'summary_large_image' : 'summary');
    render_meta_tag('name', 'twitter:title', $title);
    render_meta_tag('name', 'twitter:description', $description);
    render_meta_tag('name', 'twitter:url', $canonical);
    if ($previewImage !== null) {
        render_meta_tag('name', 'twitter:image', $previewImage['url']);
        render_meta_tag('name', 'twitter:image:src', $previewImage['url']);
        render_meta_tag('name', 'twitter:image:alt', $previewImage['alt']);
    }
}

/**
 * Render JSON-LD for one gallery page.
 *
 * The caller intentionally passes only the images rendered on the current public
 * page. Full-gallery lightbox ordering is handled by hidden source nodes in the
 * body, while crawler metadata stays capped to the visible pagination slice so
 * large galleries do not perform thumbnail resolution for every image during a
 * normal request.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param array $images Images value.
 * @param array $publicMediaManifest Request-local media manifest keyed by image id.
 */
function render_gallery_json_ld(array $gallery, array $images = [], array $publicMediaManifest = []): void
{
    if (function_exists('Gallery\\Views\\view_render_gallery_json_ld')) {
        view_render_gallery_json_ld($gallery, $images, $publicMediaManifest);
        return;
    }
    // $items stores an intermediate value used by the surrounding gallery workflow.
    $items = [];
    // $position stores an intermediate value used by the surrounding gallery workflow.
    $position = 1;
    // $jsonLdImages stores a conservative visible-page subset for crawler metadata.
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
    // $jsonLd stores an intermediate value used by the surrounding gallery workflow.
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'ImageGallery',
        'name' => gallery_seo_title($gallery),
        'description' => gallery_seo_description($gallery),
        'url' => canonical_url_for_gallery($gallery),
        'image' => $items,
    ];
    // $metadata stores an intermediate value used by the surrounding gallery workflow.
    $metadata = public_gallery_metadata($gallery);
    if (!empty($metadata['tags'])) {
        $jsonLd['keywords'] = $metadata['tags'];
    }
    // $json stores an intermediate value used by the surrounding gallery workflow.
    $json = json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    echo '<script type="application/ld+json">' . str_replace('</', '<\/', $json) . '</script>';
}

/**
 * Output the sitemap XML with public gallery URLs and image sitemap metadata.
 */
function output_sitemap_xml(): void
{
    header('Content-Type: application/xml; charset=utf-8');
    $entries = function_exists('Gallery\\Services\\public_sitemap_entries')
        ? public_sitemap_entries()
        : array_map(static fn (string $url): array => ['loc' => $url, 'images' => []], public_gallery_sitemap_entries());

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
        foreach (($entry['images'] ?? []) as $image) {
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
