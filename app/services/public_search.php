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
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Services;

use function Gallery\Core\gallery_public_url;
use function Gallery\Core\image_public_url;
use function Gallery\Core\normalize_relative_path;
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
 * Return the public gallery listing condition, optionally restricted to one gallery branch.
 *
 * @param string $alias Alias value.
 * @param ?array $contextGallery Context gallery value.
 * @return string A hardcoded SQL fragment safe for interpolation — MUST NOT contain any user-derived values.
 * @internal
 */
function public_search_context_listing_sql_fragment(string $alias, ?array $contextGallery): string
{
    $listingCondition = public_gallery_listing_sql_fragment($alias);
    if (!$contextGallery) {
        // Contract: MUST only return hardcoded SQL with no user-derived values because this fragment is interpolated into prepared statement strings.
        return $listingCondition;
    }

    // Contract: MUST only return hardcoded SQL with no user-derived values because this fragment is interpolated into prepared statement strings.
    return '(' . $listingCondition . ') AND (' . $alias . '.folder_path = ? OR ' . $alias . '.folder_path LIKE ?)';
}

/**
 * Return bound SQL values for a gallery branch search context.
 *
 * @param ?array $contextGallery Context gallery value.
 * @return array Structured result data for the caller.
 */
function public_search_context_params(?array $contextGallery): array
{
    if (!$contextGallery) {
        return [];
    }

    $folderPath = normalize_relative_path((string) ($contextGallery['folder_path'] ?? ''));
    if ($folderPath === '') {
        return [(string) ($contextGallery['folder_path'] ?? ''), (string) ($contextGallery['folder_path'] ?? '') . '/%'];
    }

    return [$folderPath, $folderPath . '/%'];
}

/**
 * Return a wildcard LIKE pattern for one normalized query.
 *
 * @param string $query Query value.
 * @return string Text result for the caller.
 */
function public_search_like_pattern(string $query): string
{
    return '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
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
    $listingCondition = public_search_context_listing_sql_fragment('g', $contextGallery);
    $contextParams = public_search_context_params($contextGallery);
    $like = public_search_like_pattern($query);
    $aiSearchReady = public_search_ai_metadata_ready();
    $contentLanguage = translation_active_language();
    $localizedGallerySearchReady = content_localization_enabled() && content_localization_schema_ready('gallery');

    $galleryRows = public_search_model_compatibility_gallery_rows(
        $query,
        $like,
        $listingCondition,
        $contextParams,
        $aiSearchReady,
        $localizedGallerySearchReady,
        $contentLanguage,
        $limit
    );
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
    $listingCondition = public_search_context_listing_sql_fragment('g', $contextGallery);
    $contextParams = public_search_context_params($contextGallery);
    $like = public_search_like_pattern($query);
    $aiSearchReady = public_search_ai_metadata_ready();
    $contentLanguage = translation_active_language();
    $localizedSearchReady = content_localization_enabled()
        && content_localization_schema_ready('image');

    $rows = public_search_model_compatibility_image_rows(
        $query,
        $like,
        $listingCondition,
        $contextParams,
        $aiSearchReady,
        $localizedSearchReady,
        $contentLanguage,
        $limit
    );
    $rows = content_localize_entities('image', $rows, $contentLanguage);
    $galleries = [];
    foreach ($rows as $row) {
        $gallery = public_search_gallery_from_image_row($row);
        $galleries[(int) ($gallery['id'] ?? 0)] = $gallery;
    }
    $localizedGalleries = content_localize_entities('gallery', array_values($galleries), $contentLanguage);
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
