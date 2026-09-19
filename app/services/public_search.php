<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/public_search.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides reusable application logic for the optional public live search.
 *
 * Responsibilities:
 *   - Read the global public search setting
 *   - Orchestrate compatibility public-search policy and result shaping
 *   - Return compact result models for the browser search UI
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
 *   - SQL/PDO access belongs in app/models/public_search.php.
 *
 * Last Updated:
 *   2026-09-19
 */

declare(strict_types=1);

namespace Gallery\Services;

use function Gallery\Core\gallery_public_url;
use function Gallery\Core\image_public_url;
use function Gallery\Models\public_search_model_compatibility_gallery_rows;
use function Gallery\Models\public_search_model_compatibility_image_rows;

const PUBLIC_HOME_SEARCH_SETTING = 'public_home_search_enabled';

/**
 * Return true when the thin public search bar is enabled.
 *
 * @return bool True when the condition matches.
 */
function public_home_search_enabled(): bool
{
    if (function_exists('Gallery\\Services\\feature_capability_effective_enabled') && !feature_capability_effective_enabled('public_search')) {
        return false;
    }
    return app_setting(PUBLIC_HOME_SEARCH_SETTING, '0') === '1';
}

/**
 * Persist the global public home search setting.
 *
 * @param bool $enabled Enabled flag.
 */
function set_public_home_search_enabled(bool $enabled): void
{
    set_app_setting(PUBLIC_HOME_SEARCH_SETTING, $enabled ? '1' : '0');
}

/**
 * Normalize a browser-supplied public search query.
 *
 * @param string $query Query value.
 * @return string Text result for the caller.
 */
function public_search_normalize_query(string $query): string
{
    $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
    if (function_exists('mb_substr')) {
        return mb_substr($query, 0, 120);
    }
    return substr($query, 0, 120);
}

/**
 * Return compact public search results for galleries and photos.
 *
 * @param string $query Query value.
 * @param int $limit Maximum number of items.
 * @param ?array $contextGallery Context gallery value.
 * @return array Structured result data for the caller.
 */
function public_search_results(string $query, int $limit = 12, ?array $contextGallery = null): array
{
    $query = public_search_normalize_query($query);
    if (public_search_query_length($query) < 2) {
        return [];
    }

    $limit = max(1, min(30, $limit));
    $galleryLimit = max(4, (int) ceil($limit * 0.6));
    $imageLimit = max(4, $limit - $galleryLimit + 4);
    $galleryResults = public_search_gallery_results($query, $galleryLimit, $contextGallery);
    $imageResults = public_search_image_results($query, $imageLimit, $contextGallery);

    $merged = [];
    foreach ($galleryResults as $result) {
        $merged[] = $result;
    }
    foreach ($imageResults as $result) {
        $merged[] = $result;
    }

    usort($merged, static function (array $left, array $right): int {
        $scoreCompare = ((int) ($right['score'] ?? 0)) <=> ((int) ($left['score'] ?? 0));
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }
        return strcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
    });

    return array_slice(array_map(static function (array $result): array {
        $result['rank'] = (int) ($result['score'] ?? 0);
        unset($result['score']);
        return $result;
    }, $merged), 0, $limit);
}

/**
 * Return the user-visible query length in characters.
 *
 * @param string $query Query value.
 * @return int Integer result for the caller.
 */
function public_search_query_length(string $query): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($query);
    }
    return strlen($query);
}


/**
 * Return whether public-search persistence must restrict galleries to listed access.
 *
 * Public visibility/access policy stays in the service layer, while the model
 * translates this semantic decision into SQL.
 *
 * @return bool True when public queries must require listed galleries.
 */
function public_search_listing_requires_listed(): bool
{
    gallery_visibility_assert_public_policy_available();
    gallery_access_assert_public_policy_available();
    return gallery_access_schema_ready();
}

/**
 * Return whether one gallery may be exposed by public search to this visitor.
 *
 * Search is a discovery surface, so direct-URL reachability is not sufficient:
 * the gallery must be publicly listed and the current visitor must satisfy the
 * same inherited password, share-link, and NSFW policy as the gallery page.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return bool True when the gallery may appear in public search results.
 */
function public_search_gallery_visible_to_current_visitor(array $gallery): bool
{
    return gallery_is_public_listed($gallery) && visitor_can_access_gallery($gallery);
}

/**
 * Return whether one image may be exposed by public search to this visitor.
 *
 * The gallery must remain publicly listed, while image visibility, inherited
 * gallery authorization, and image/gallery NSFW policy are delegated to the
 * canonical public-image authorization helper.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Owning gallery row or gallery data.
 * @return bool True when the image may appear in public search results.
 */
function public_search_image_visible_to_current_visitor(array $image, array $gallery): bool
{
    return gallery_is_public_listed($gallery) && public_image_visible_to_current_visitor($image, $gallery);
}

/**
 * Return whether local AI metadata may participate in public search.
 *
 * The capability master is checked before schema inspection so disabling local
 * AI metadata preserves stored rows without continuing to query or rank by them.
 *
 * @return bool True when AI-backed search enrichment is available.
 */
function public_search_ai_metadata_ready(): bool
{
    if (function_exists(__NAMESPACE__ . '\\feature_capability_effective_enabled') && !feature_capability_effective_enabled('ai_image_metadata')) {
        return false;
    }
    return function_exists(__NAMESPACE__ . '\\ai_image_analysis_schema_ready') && ai_image_analysis_schema_ready();
}

/**
 * Return gallery matches for the public search endpoint.
 *
 * @param string $query Query value.
 * @param int $limit Maximum number of items.
 * @param ?array $contextGallery Context gallery value.
 * @return array Structured result data for the caller.
 */
function public_search_gallery_results(string $query, int $limit, ?array $contextGallery = null): array
{
    $listedOnly = public_search_listing_requires_listed();
    $aiSearchReady = public_search_ai_metadata_ready();
    $contentLanguage = translation_active_language();
    $localizedGallerySearchReady = content_localization_enabled() && content_localization_schema_ready('gallery');

    $galleryRows = public_search_model_compatibility_gallery_rows(
        $query,
        $listedOnly,
        $contextGallery,
        $aiSearchReady,
        $localizedGallerySearchReady,
        $contentLanguage,
        $limit
    );
    $authorizedGalleryRows = [];
    foreach ($galleryRows as $gallery) {
        if (public_search_gallery_visible_to_current_visitor($gallery)) {
            $authorizedGalleryRows[] = $gallery;
        }
    }
    $galleryRows = $authorizedGalleryRows;
    if ($localizedGallerySearchReady) {
        $galleryRows = content_localize_entities('gallery', $galleryRows, $contentLanguage);
    }

    $results = [];
    foreach ($galleryRows as $gallery) {
        $tagNames = trim((string) ($gallery['gallery_tag_names'] ?? ''));
        $containedTags = trim((string) ($gallery['image_tag_names'] ?? ''));
        $details = [];
        if ($tagNames !== '') {
            $details[] = t('search.tags_prefix', 'Tags: {tags}', ['tags' => public_search_compact_text($tagNames, 120)]);
        }
        if ($containedTags !== '' && $containedTags !== $tagNames) {
            $details[] = t('search.photo_tags_prefix', 'Photo tags: {tags}', ['tags' => public_search_compact_text($containedTags, 120)]);
        }
        $description = public_search_compact_text((string) ($gallery['description'] ?? ''), 180);
        if ($description !== '') {
            $details[] = $description;
        }
        $score = (int) ($gallery['exact_title_score'] ?? 0) + (int) ($gallery['title_score'] ?? 0) + (int) ($gallery['gallery_tag_score'] ?? 0) + (int) ($gallery['image_name_score'] ?? 0) + (int) ($gallery['ai_score'] ?? 0);
        $results[] = [
            'key' => 'gallery:' . (int) ($gallery['id'] ?? 0),
            'type' => 'gallery',
            'label' => t('search.type_gallery', 'Gallery'),
            'title' => (string) $gallery['title'],
            'subtitle' => implode(' · ', array_filter($details)),
            'url' => gallery_public_url($gallery),
            'score' => $score,
        ];
    }

    return $results;
}

/**
 * Return image matches for the public search endpoint.
 *
 * @param string $query Query value.
 * @param int $limit Maximum number of items.
 * @param ?array $contextGallery Context gallery value.
 * @return array Structured result data for the caller.
 */
function public_search_image_results(string $query, int $limit, ?array $contextGallery = null): array
{
    $listedOnly = public_search_listing_requires_listed();
    $aiSearchReady = public_search_ai_metadata_ready();
    $contentLanguage = translation_active_language();
    $localizedSearchReady = content_localization_enabled()
        && content_localization_schema_ready('image');

    $rows = public_search_model_compatibility_image_rows(
        $query,
        $listedOnly,
        $contextGallery,
        $aiSearchReady,
        $localizedSearchReady,
        $contentLanguage,
        $limit
    );

    // Re-fetch canonical gallery rows before exposing image metadata. The legacy
    // compatibility query intentionally projects only a bounded gallery subset,
    // which is not sufficient for inherited NSFW/password authorization.
    $authorizedRows = [];
    $galleries = [];
    foreach ($rows as $row) {
        $galleryId = (int) ($row['matched_gallery_id'] ?? 0);
        if ($galleryId <= 0) {
            continue;
        }
        if (!array_key_exists($galleryId, $galleries)) {
            $galleries[$galleryId] = find_gallery($galleryId);
        }
        $gallery = $galleries[$galleryId] ?? null;
        if (!is_array($gallery) || !public_search_image_visible_to_current_visitor($row, $gallery)) {
            continue;
        }
        $authorizedRows[] = $row;
    }
    $rows = content_localize_entities('image', $authorizedRows, $contentLanguage);

    $authorizedGalleries = [];
    foreach ($galleries as $galleryId => $gallery) {
        if (!is_array($gallery) || !public_search_gallery_visible_to_current_visitor($gallery)) {
            continue;
        }
        $authorizedGalleries[(int) $galleryId] = $gallery;
    }
    $localizedGalleries = content_localize_entities('gallery', array_values($authorizedGalleries), $contentLanguage);
    $galleries = [];
    foreach ($localizedGalleries as $localizedGallery) {
        $galleries[(int) ($localizedGallery['id'] ?? 0)] = $localizedGallery;
    }
    $results = [];
    foreach ($rows as $row) {
        $galleryId = (int) ($row['matched_gallery_id'] ?? 0);
        $gallery = $galleries[$galleryId] ?? public_search_gallery_from_image_row($row);
        $title = trim((string) ($row['title'] ?? ''));
        if ($title === '') {
            $title = (string) ($row['filename'] ?? t('search.untitled_photo', 'Untitled photo'));
        }
        $details = [];
        $details[] = t('search.in_gallery', 'In {gallery}', ['gallery' => (string) ($gallery['title'] ?? '')]);
        $tagNames = trim((string) ($row['image_tag_names'] ?? ''));
        if ($tagNames !== '') {
            $details[] = t('search.tags_prefix', 'Tags: {tags}', ['tags' => public_search_compact_text($tagNames, 120)]);
        }
        $description = public_search_compact_text((string) ($row['description'] ?? ''), 160);
        if ($description !== '') {
            $details[] = $description;
        }
        $score = (int) ($row['exact_name_score'] ?? 0) + (int) ($row['name_score'] ?? 0) + (int) ($row['tag_score'] ?? 0) + (int) ($row['ai_score'] ?? 0);
        $results[] = [
            'key' => 'photo:' . (int) ($row['id'] ?? 0),
            'type' => 'photo',
            'label' => t('search.type_photo', 'Photo'),
            'title' => $title,
            'subtitle' => implode(' · ', array_filter($details)),
            'url' => image_public_url($row, $gallery),
            'score' => $score,
        ];
    }

    return $results;
}

/**
 * Build a gallery-shaped array from a joined image search row.
 *
 * @param array $row Row data.
 * @return array Structured result data for the caller.
 */
function public_search_gallery_from_image_row(array $row): array
{
    $gallery = [];
    foreach ($row as $key => $value) {
        if (!str_starts_with((string) $key, 'matched_gallery_')) {
            continue;
        }
        $gallery[substr((string) $key, 16)] = $value;
    }
    $gallery['id'] = $row['matched_gallery_id'] ?? 0;
    $gallery['title'] = $row['matched_gallery_title'] ?? '';
    $gallery['url_slug'] = $row['matched_gallery_url_slug'] ?? '';
    $gallery['url_path'] = $row['matched_gallery_url_path'] ?? '';
    return $gallery;
}

/**
 * Collapse rich text into a short one-line search result detail.
 *
 * @param string $text Text value.
 * @param int $limit Maximum number of items.
 * @return string Text result for the caller.
 */
function public_search_compact_text(string $text, int $limit): string
{
    $text = trim(strip_tags($text));
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, max(1, $limit - 1))) . '…';
    }
    if (strlen($text) <= $limit) {
        return $text;
    }
    return rtrim(substr($text, 0, max(1, $limit - 3))) . '...';
}
