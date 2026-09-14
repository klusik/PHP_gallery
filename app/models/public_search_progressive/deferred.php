<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/public_search_progressive/deferred.php
 * Module Type: Model Part
 *
 * Purpose:
 *   Owns deferred descriptive and deep database queries for progressive public search.
 *
 * Responsibilities:
 *   - Query canonical and translated gallery description sources
 *   - Query image descriptions, tag descriptions, translations, and optional AI metadata
 *   - Keep each expensive metadata source isolated in its own bounded query
 *   - Reduce duplicate relation rows before joining public gallery authorization state where practical
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
 *   - This part is loaded only by app/models/public_search_progressive.php.
 *   - Search scope SQL and wildcard patterns are built inside the model from semantic service inputs.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;


/**
 * Fetch galleries whose canonical description contains the query.
 *
 * @param string $query Normalized query.
 * @param bool $listedOnly Whether public access policy requires listed galleries.
 * @param ?array $contextGallery Optional gallery branch context.
 * @param int $limit Maximum rows returned.
 * @return array<int, array<string, mixed>> Raw gallery rows.
 */
function public_search_model_gallery_description_rows(string $query, bool $listedOnly, ?array $contextGallery, int $limit): array
{
    $limit = max(1, min(90, $limit));
    $like = public_search_model_like_pattern($query);
    [$listingCondition, $contextParams] = public_search_model_listing_scope('g', $listedOnly, $contextGallery);
    $sql = "SELECT g.id, g.title
        FROM galleries g
        WHERE $listingCondition
          AND g.description LIKE ?
        ORDER BY LOWER(g.title) ASC, g.id ASC
        LIMIT " . (int) $limit;
    return public_search_diagnostics_fetch_all(
        'descriptive.gallery_description_candidates',
        $sql,
        array_merge($contextParams, [$like])
    );
}

/**
 * Fetch galleries through matching gallery-tag descriptions.
 *
 * @param string $query Normalized query.
 * @param bool $listedOnly Whether public access policy requires listed galleries.
 * @param ?array $contextGallery Optional gallery branch context.
 * @param int $limit Maximum rows returned.
 * @return array<int, array<string, mixed>> Raw gallery rows.
 */
function public_search_model_gallery_tag_description_rows(string $query, bool $listedOnly, ?array $contextGallery, int $limit): array
{
    $limit = max(1, min(90, $limit));
    $like = public_search_model_like_pattern($query);
    [$listingCondition, $contextParams] = public_search_model_listing_scope('g', $listedOnly, $contextGallery);
    $sql = "SELECT g.id, g.title
        FROM (
            SELECT DISTINCT gt.gallery_id
            FROM tags t
            INNER JOIN gallery_tags gt ON gt.tag_id = t.id
            WHERE t.description LIKE ?
        ) tag_match
        INNER JOIN galleries g ON g.id = tag_match.gallery_id
        WHERE $listingCondition
        ORDER BY LOWER(g.title) ASC, g.id ASC
        LIMIT " . (int) $limit;
    return public_search_diagnostics_fetch_all(
        'descriptive.gallery_tag_description_candidates',
        $sql,
        array_merge([$like], $contextParams)
    );
}

/**
 * Fetch galleries by translated title in one language.
 *
 * @param string $language Active content language.
 * @param string $query Normalized query.
 * @param bool $listedOnly Whether public access policy requires listed galleries.
 * @param ?array $contextGallery Optional gallery branch context.
 * @param int $limit Maximum rows returned.
 * @return array<int, array<string, mixed>> Raw translated gallery rows.
 */
function public_search_model_gallery_translated_title_rows(string $language, string $query, bool $listedOnly, ?array $contextGallery, int $limit): array
{
    $limit = max(1, min(90, $limit));
    $like = public_search_model_like_pattern($query);
    [$listingCondition, $contextParams] = public_search_model_listing_scope('g', $listedOnly, $contextGallery);
    $sql = "SELECT g.id, COALESCE(NULLIF(tr.title, ''), g.title) AS title_sort
        FROM gallery_translations tr
        INNER JOIN galleries g ON g.id = tr.gallery_id
        WHERE tr.language_code = ?
          AND tr.title LIKE ?
          AND $listingCondition
        ORDER BY LOWER(COALESCE(NULLIF(tr.title, ''), g.title)) ASC, g.id ASC
        LIMIT " . (int) $limit;
    return public_search_diagnostics_fetch_all(
        'descriptive.gallery_translated_title_candidates',
        $sql,
        array_merge([$language, $like], $contextParams)
    );
}

/**
 * Fetch galleries by translated description in one language.
 *
 * @param string $language Active content language.
 * @param string $query Normalized query.
 * @param bool $listedOnly Whether public access policy requires listed galleries.
 * @param ?array $contextGallery Optional gallery branch context.
 * @param int $limit Maximum rows returned.
 * @return array<int, array<string, mixed>> Raw translated gallery rows.
 */
function public_search_model_gallery_translated_description_rows(string $language, string $query, bool $listedOnly, ?array $contextGallery, int $limit): array
{
    $limit = max(1, min(90, $limit));
    $like = public_search_model_like_pattern($query);
    [$listingCondition, $contextParams] = public_search_model_listing_scope('g', $listedOnly, $contextGallery);
    $sql = "SELECT g.id, COALESCE(NULLIF(tr.title, ''), g.title) AS title_sort
        FROM gallery_translations tr
        INNER JOIN galleries g ON g.id = tr.gallery_id
        WHERE tr.language_code = ?
          AND tr.description LIKE ?
          AND $listingCondition
        ORDER BY LOWER(COALESCE(NULLIF(tr.title, ''), g.title)) ASC, g.id ASC
        LIMIT " . (int) $limit;
    return public_search_diagnostics_fetch_all(
        'deep.gallery_translated_description_candidates',
        $sql,
        array_merge([$language, $like], $contextParams)
    );
}

/**
 * Fetch public images whose canonical description contains the query.
 *
 * @param string $query Normalized query.
 * @param bool $listedOnly Whether public access policy requires listed galleries.
 * @param ?array $contextGallery Optional gallery branch context.
 * @param int $limit Maximum rows returned.
 * @return array<int, array<string, mixed>> Raw image rows.
 */
function public_search_model_image_description_rows(string $query, bool $listedOnly, ?array $contextGallery, int $limit): array
{
    $limit = max(1, min(90, $limit));
    $like = public_search_model_like_pattern($query);
    [$listingCondition, $contextParams] = public_search_model_listing_scope('g', $listedOnly, $contextGallery);
    $sql = "SELECT i.id, i.filename, i.title
        FROM images i
        INNER JOIN galleries g ON g.id = i.gallery_id
        WHERE i.visibility = 'public'
          AND $listingCondition
          AND i.description LIKE ?
        ORDER BY LOWER(COALESCE(NULLIF(i.title, ''), i.filename)) ASC, i.id ASC
        LIMIT " . (int) $limit;
    return public_search_diagnostics_fetch_all(
        'deep.image_description_candidates',
        $sql,
        array_merge($contextParams, [$like])
    );
}

/**
 * Fetch public images through matching image-tag descriptions.
 *
 * The relation is reduced to distinct image IDs before joining image/gallery rows.
 *
 * @param string $query Normalized query.
 * @param bool $listedOnly Whether public access policy requires listed galleries.
 * @param ?array $contextGallery Optional gallery branch context.
 * @param int $limit Maximum rows returned.
 * @return array<int, array<string, mixed>> Raw image rows.
 */
function public_search_model_image_tag_description_rows(string $query, bool $listedOnly, ?array $contextGallery, int $limit): array
{
    $limit = max(1, min(90, $limit));
    $like = public_search_model_like_pattern($query);
    [$listingCondition, $contextParams] = public_search_model_listing_scope('g', $listedOnly, $contextGallery);
    $sql = "SELECT i.id, i.filename, i.title
        FROM (
            SELECT DISTINCT it.image_id
            FROM tags t
            INNER JOIN image_tags it ON it.tag_id = t.id
            WHERE t.description LIKE ?
        ) tag_match
        INNER JOIN images i ON i.id = tag_match.image_id
        INNER JOIN galleries g ON g.id = i.gallery_id
        WHERE i.visibility = 'public'
          AND $listingCondition
        ORDER BY LOWER(COALESCE(NULLIF(i.title, ''), i.filename)) ASC, i.id ASC
        LIMIT " . (int) $limit;
    return public_search_diagnostics_fetch_all(
        'deep.image_tag_description_candidates',
        $sql,
        array_merge([$like], $contextParams)
    );
}

/**
 * Fetch public images through translated title/description metadata.
 *
 * @param string $language Active content language.
 * @param string $like Escaped substring LIKE pattern.
 * @param int $titleScore Relevance score for translated title matches.
 * @param int $descriptionScore Relevance score for translated description matches.
 * @param string $listingCondition Hardcoded public-listing SQL condition.
 * @param array<int, mixed> $contextParams Bound branch-scope values.
 * @param int $limit Maximum rows returned.
 * @return array<int, array<string, mixed>> Raw translated image rows with match score.
 */
function public_search_model_image_translation_rows(
    string $language,
    string $query,
    int $titleScore,
    int $descriptionScore,
    bool $listedOnly,
    ?array $contextGallery,
    int $limit
): array {
    $limit = max(1, min(90, $limit));
    $like = public_search_model_like_pattern($query);
    [$listingCondition, $contextParams] = public_search_model_listing_scope('g', $listedOnly, $contextGallery);
    $sql = "SELECT i.id, i.filename, i.title,
            COALESCE(NULLIF(tr.title, ''), NULLIF(i.title, ''), i.filename) AS title_sort,
            CASE
                WHEN tr.title LIKE ? THEN $titleScore
                WHEN tr.description LIKE ? THEN $descriptionScore
                ELSE 0
            END AS match_score
        FROM image_translations tr
        INNER JOIN images i ON i.id = tr.image_id
        INNER JOIN galleries g ON g.id = i.gallery_id
        WHERE tr.language_code = ?
          AND (tr.title LIKE ? OR tr.description LIKE ?)
          AND i.visibility = 'public'
          AND $listingCondition
        ORDER BY match_score DESC, LOWER(COALESCE(NULLIF(tr.title, ''), NULLIF(i.title, ''), i.filename)) ASC, i.id ASC
        LIMIT " . (int) $limit;
    return public_search_diagnostics_fetch_all(
        'deep.image_translation_candidates',
        $sql,
        array_merge([$like, $like, $language, $like, $like], $contextParams)
    );
}

/**
 * Fetch public images through optional local AI searchable metadata.
 *
 * Matching metadata is reduced to distinct image IDs before image/gallery joins.
 *
 * @param string $query Normalized query.
 * @param bool $listedOnly Whether public access policy requires listed galleries.
 * @param ?array $contextGallery Optional gallery branch context.
 * @param int $limit Maximum rows returned.
 * @return array<int, array<string, mixed>> Raw image rows.
 */
function public_search_model_image_ai_rows(string $query, bool $listedOnly, ?array $contextGallery, int $limit): array
{
    $limit = max(1, min(90, $limit));
    $like = public_search_model_like_pattern($query);
    [$listingCondition, $contextParams] = public_search_model_listing_scope('g', $listedOnly, $contextGallery);
    $sql = "SELECT i.id, i.filename, i.title
        FROM (
            SELECT DISTINCT m.image_id
            FROM image_ai_metadata m
            WHERE m.searchable_text LIKE ?
        ) ai_match
        INNER JOIN images i ON i.id = ai_match.image_id
        INNER JOIN galleries g ON g.id = i.gallery_id
        WHERE i.visibility = 'public'
          AND $listingCondition
        ORDER BY LOWER(COALESCE(NULLIF(i.title, ''), i.filename)) ASC, i.id ASC
        LIMIT " . (int) $limit;
    return public_search_diagnostics_fetch_all(
        'deep.image_ai_candidates',
        $sql,
        array_merge([$like], $contextParams)
    );
}
