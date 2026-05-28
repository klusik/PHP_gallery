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
 *   - Query public gallery and image metadata safely
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
 *
 * Last Updated:
 *   2026-05-28
 */

declare(strict_types=1);

const PUBLIC_HOME_SEARCH_SETTING = 'public_home_search_enabled';

/**
 * Return true when the thin public search bar is enabled.
 */
function public_home_search_enabled(): bool
{
    return app_setting(PUBLIC_HOME_SEARCH_SETTING, '0') === '1';
}

/**
 * Persist the global public home search setting.
 */
function set_public_home_search_enabled(bool $enabled): void
{
    set_app_setting(PUBLIC_HOME_SEARCH_SETTING, $enabled ? '1' : '0');
}

/**
 * Normalize a browser-supplied public search query.
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
        unset($result['score']);
        return $result;
    }, $merged), 0, $limit);
}

/**
 * Return the user-visible query length in characters.
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
 */
function public_search_context_listing_condition(string $alias, ?array $contextGallery): string
{
    $listingCondition = public_gallery_listing_condition($alias);
    if (!$contextGallery) {
        return $listingCondition;
    }

    return '(' . $listingCondition . ') AND (' . $alias . '.folder_path = ? OR ' . $alias . '.folder_path LIKE ?)';
}

/**
 * Return bound SQL values for a gallery branch search context.
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
 */
function public_search_like_pattern(string $query): string
{
    return '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
}

/**
 * Return gallery matches for the public search endpoint.
 */
function public_search_gallery_results(string $query, int $limit, ?array $contextGallery = null): array
{
    $listingCondition = public_search_context_listing_condition('g', $contextGallery);
    $contextParams = public_search_context_params($contextGallery);
    $like = public_search_like_pattern($query);
    $sql = "SELECT g.*, COUNT(DISTINCT public_image.id) AS image_count,
            GROUP_CONCAT(DISTINCT gallery_tag.name ORDER BY gallery_tag.name SEPARATOR ', ') AS gallery_tag_names,
            GROUP_CONCAT(DISTINCT image_tag.name ORDER BY image_tag.name SEPARATOR ', ') AS image_tag_names,
            MAX(CASE WHEN LOWER(g.title) = LOWER(?) THEN 80 ELSE 0 END) AS exact_title_score,
            MAX(CASE WHEN g.title LIKE ? THEN 40 ELSE 0 END) AS title_score,
            MAX(CASE WHEN gallery_tag.name LIKE ? THEN 24 ELSE 0 END) AS gallery_tag_score,
            MAX(CASE WHEN public_image.filename LIKE ? OR public_image.title LIKE ? THEN 16 ELSE 0 END) AS image_name_score
        FROM galleries g
        LEFT JOIN images public_image ON public_image.gallery_id = g.id AND public_image.visibility = 'public'
        LEFT JOIN gallery_tags gt ON gt.gallery_id = g.id
        LEFT JOIN tags gallery_tag ON gallery_tag.id = gt.tag_id
        LEFT JOIN image_tags it ON it.image_id = public_image.id
        LEFT JOIN tags image_tag ON image_tag.id = it.tag_id
        WHERE $listingCondition
          AND (
              g.title LIKE ? OR g.description LIKE ?
              OR gallery_tag.name LIKE ? OR gallery_tag.description LIKE ?
              OR public_image.filename LIKE ? OR public_image.title LIKE ? OR public_image.description LIKE ?
              OR image_tag.name LIKE ? OR image_tag.description LIKE ?
          )
        GROUP BY g.id
        ORDER BY exact_title_score DESC, title_score DESC, gallery_tag_score DESC, image_name_score DESC, g.title ASC
        LIMIT " . (int) $limit;
    $params = array_merge([$query, $like, $like, $like, $like], $contextParams, [$like, $like, $like, $like, $like, $like, $like, $like, $like]);
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $results = [];
    foreach ($stmt->fetchAll() as $gallery) {
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
        $score = (int) ($gallery['exact_title_score'] ?? 0) + (int) ($gallery['title_score'] ?? 0) + (int) ($gallery['gallery_tag_score'] ?? 0) + (int) ($gallery['image_name_score'] ?? 0);
        $results[] = [
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
 */
function public_search_image_results(string $query, int $limit, ?array $contextGallery = null): array
{
    $listingCondition = public_search_context_listing_condition('g', $contextGallery);
    $contextParams = public_search_context_params($contextGallery);
    $like = public_search_like_pattern($query);
    $sql = "SELECT i.*, g.id AS matched_gallery_id, g.parent_id AS matched_gallery_parent_id,
            g.folder_path AS matched_gallery_folder_path, g.folder_path_hash AS matched_gallery_folder_path_hash,
            g.slug AS matched_gallery_slug, g.title AS matched_gallery_title, g.description AS matched_gallery_description,
            g.cover_image_id AS matched_gallery_cover_image_id, g.sort_order AS matched_gallery_sort_order,
            g.visibility AS matched_gallery_visibility, g.voting_enabled AS matched_gallery_voting_enabled,
            g.show_filenames AS matched_gallery_show_filenames, g.access_mode AS matched_gallery_access_mode,
            g.access_listing AS matched_gallery_access_listing, g.access_password_hash AS matched_gallery_access_password_hash,
            g.access_share_token AS matched_gallery_access_share_token, g.access_token_hash AS matched_gallery_access_token_hash,
            g.access_token_expires_at AS matched_gallery_access_token_expires_at, g.created_at AS matched_gallery_created_at,
            g.updated_at AS matched_gallery_updated_at,
            " . (db_column_exists('galleries', 'url_slug') ? 'g.url_slug AS matched_gallery_url_slug,' : "'' AS matched_gallery_url_slug,") . "
            " . (db_column_exists('galleries', 'url_path') ? 'g.url_path AS matched_gallery_url_path,' : "'' AS matched_gallery_url_path,") . "
            GROUP_CONCAT(DISTINCT image_tag.name ORDER BY image_tag.name SEPARATOR ', ') AS image_tag_names,
            MAX(CASE WHEN LOWER(i.filename) = LOWER(?) OR LOWER(i.title) = LOWER(?) THEN 70 ELSE 0 END) AS exact_name_score,
            MAX(CASE WHEN i.filename LIKE ? OR i.title LIKE ? THEN 36 ELSE 0 END) AS name_score,
            MAX(CASE WHEN image_tag.name LIKE ? THEN 24 ELSE 0 END) AS tag_score,
            MAX(CASE WHEN g.title LIKE ? THEN 12 ELSE 0 END) AS gallery_score
        FROM images i
        INNER JOIN galleries g ON g.id = i.gallery_id
        LEFT JOIN image_tags it ON it.image_id = i.id
        LEFT JOIN tags image_tag ON image_tag.id = it.tag_id
        LEFT JOIN gallery_tags gt ON gt.gallery_id = g.id
        LEFT JOIN tags gallery_tag ON gallery_tag.id = gt.tag_id
        WHERE i.visibility = 'public'
          AND $listingCondition
          AND (
              i.filename LIKE ? OR i.title LIKE ? OR i.description LIKE ?
              OR image_tag.name LIKE ? OR image_tag.description LIKE ?
              OR g.title LIKE ? OR g.description LIKE ?
              OR gallery_tag.name LIKE ? OR gallery_tag.description LIKE ?
          )
        GROUP BY i.id
        ORDER BY exact_name_score DESC, name_score DESC, tag_score DESC, gallery_score DESC, i.filename ASC
        LIMIT " . (int) $limit;
    $params = array_merge([$query, $query, $like, $like, $like, $like], $contextParams, [$like, $like, $like, $like, $like, $like, $like, $like, $like]);
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $results = [];
    foreach ($stmt->fetchAll() as $row) {
        $gallery = public_search_gallery_from_image_row($row);
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
        $score = (int) ($row['exact_name_score'] ?? 0) + (int) ($row['name_score'] ?? 0) + (int) ($row['tag_score'] ?? 0) + (int) ($row['gallery_score'] ?? 0);
        $results[] = [
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
