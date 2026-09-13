<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/public_search_progressive.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns database access for the latency-sensitive primary and media phases of public search.
 *
 * Responsibilities:
 *   - Execute bounded candidate queries for gallery titles/tags and image names/tags
 *   - Hydrate only already-selected gallery/image identifiers
 *   - Aggregate display tag names after candidate reduction
 *   - Keep SQL and PDO concerns out of controllers, views, and service orchestration
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
 *   - SQL listing fragments passed into this model must be hardcoded policy fragments produced by the service layer and must never contain user-derived values.
 *   - This model intentionally does not call service-layer functions.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;


/**
 * Return a placeholder list for a bounded identifier collection.
 *
 * @param array<int, int> $ids Persistent identifiers.
 * @return string Prepared-statement placeholder list.
 */
function public_search_model_placeholders(array $ids): string
{
    return implode(', ', array_fill(0, count($ids), '?'));
}

/**
 * Fetch gallery-title candidates for the primary search phase.
 *
 * @param string $query Normalized query.
 * @param string $prefix Escaped prefix LIKE pattern.
 * @param string $like Escaped substring LIKE pattern.
 * @param array<string, int> $scores Relevance scores used by SQL ordering.
 * @param string $listingCondition Hardcoded public-listing SQL condition.
 * @param array<int, mixed> $contextParams Bound branch-scope values.
 * @param int $limit Maximum rows returned.
 * @return array<int, array<string, mixed>> Raw candidate rows.
 */
function public_search_model_primary_gallery_title_rows(
    string $query,
    string $prefix,
    string $like,
    array $scores,
    string $listingCondition,
    array $contextParams,
    int $limit
): array {
    $limit = max(1, min(90, $limit));
    $titleExact = (int) ($scores['exact'] ?? 0);
    $titleNormalizedExact = (int) ($scores['normalized_exact'] ?? 0);
    $titlePrefix = (int) ($scores['prefix'] ?? 0);
    $titleSubstring = (int) ($scores['substring'] ?? 0);

    $sql = "SELECT g.id, g.title,
            CASE
                WHEN LOWER(g.title) = LOWER(?) THEN $titleExact
                WHEN LOWER(TRIM(g.title)) = LOWER(?) THEN $titleNormalizedExact
                WHEN g.title LIKE ? THEN $titlePrefix
                WHEN g.title LIKE ? THEN $titleSubstring
                ELSE 0
            END AS match_score
        FROM galleries g
        WHERE $listingCondition
          AND g.title LIKE ?
        ORDER BY match_score DESC, LOWER(g.title) ASC, g.id ASC
        LIMIT " . (int) $limit;
    return public_search_diagnostics_fetch_all(
        'primary.gallery_title_candidates',
        $sql,
        array_merge([$query, $query, $prefix, $like], $contextParams, [$like])
    );
}

/**
 * Fetch gallery-tag candidates for the primary search phase.
 *
 * Matching tags are reduced to one strongest score per gallery before result hydration.
 *
 * @param string $query Normalized query.
 * @param string $prefix Escaped prefix LIKE pattern.
 * @param string $like Escaped substring LIKE pattern.
 * @param array<string, int> $scores Relevance scores used by SQL ordering.
 * @param string $listingCondition Hardcoded public-listing SQL condition.
 * @param array<int, mixed> $contextParams Bound branch-scope values.
 * @param int $limit Maximum rows returned.
 * @return array<int, array<string, mixed>> Raw candidate rows.
 */
function public_search_model_primary_gallery_tag_rows(
    string $query,
    string $prefix,
    string $like,
    array $scores,
    string $listingCondition,
    array $contextParams,
    int $limit
): array {
    $limit = max(1, min(90, $limit));
    $tagExact = (int) ($scores['exact'] ?? 0);
    $tagPrefix = (int) ($scores['prefix'] ?? 0);
    $tagSubstring = (int) ($scores['substring'] ?? 0);

    $sql = "SELECT g.id, g.title,
            MAX(CASE
                WHEN LOWER(t.name) = LOWER(?) THEN $tagExact
                WHEN t.name LIKE ? THEN $tagPrefix
                WHEN t.name LIKE ? THEN $tagSubstring
                ELSE 0
            END) AS match_score
        FROM tags t
        INNER JOIN gallery_tags gt ON gt.tag_id = t.id
        INNER JOIN galleries g ON g.id = gt.gallery_id
        WHERE t.name LIKE ?
          AND $listingCondition
        GROUP BY g.id, g.title
        ORDER BY match_score DESC, LOWER(g.title) ASC, g.id ASC
        LIMIT " . (int) $limit;
    return public_search_diagnostics_fetch_all(
        'primary.gallery_tag_candidates',
        $sql,
        array_merge([$query, $prefix, $like, $like], $contextParams)
    );
}

/**
 * Fetch gallery rows for an already-bounded identifier set.
 *
 * @param array<int, int> $ids Gallery identifiers.
 * @return array<int, array<string, mixed>> Gallery rows.
 */
function public_search_model_gallery_rows_by_ids(array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $placeholders = public_search_model_placeholders($ids);
    $sql = "SELECT g.* FROM galleries g WHERE g.id IN ($placeholders)";
    return public_search_diagnostics_fetch_all(
        'hydrate.gallery_rows',
        $sql,
        $ids
    );
}

/**
 * Fetch aggregated gallery tag names after candidate reduction.
 *
 * @param array<int, int> $ids Gallery identifiers.
 * @return array<int, array<string, mixed>> Gallery/tag rows.
 */
function public_search_model_gallery_tag_name_rows(array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $placeholders = public_search_model_placeholders($ids);
    $sql = "SELECT gt.gallery_id,
            GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ') AS gallery_tag_names
        FROM gallery_tags gt
        INNER JOIN tags t ON t.id = gt.tag_id
        WHERE gt.gallery_id IN ($placeholders)
        GROUP BY gt.gallery_id";
    return public_search_diagnostics_fetch_all(
        'hydrate.gallery_tag_names',
        $sql,
        $ids
    );
}

/**
 * Fetch direct filename/title candidates for the media search phase.
 *
 * @param string $query Normalized query.
 * @param string $prefix Escaped prefix LIKE pattern.
 * @param string $like Escaped substring LIKE pattern.
 * @param array<string, int> $scores Relevance scores used by SQL ordering.
 * @param string $listingCondition Hardcoded public-listing SQL condition.
 * @param array<int, mixed> $contextParams Bound branch-scope values.
 * @param int $limit Maximum rows returned.
 * @return array<int, array<string, mixed>> Raw image candidate rows.
 */
function public_search_model_media_image_name_rows(
    string $query,
    string $prefix,
    string $like,
    array $scores,
    string $listingCondition,
    array $contextParams,
    int $limit
): array {
    $limit = max(1, min(90, $limit));
    $exactScore = (int) ($scores['exact'] ?? 0);
    $prefixScore = (int) ($scores['prefix'] ?? 0);
    $substringScore = (int) ($scores['substring'] ?? 0);

    $sql = "SELECT i.id, i.filename, i.title,
            CASE
                WHEN LOWER(i.filename) = LOWER(?) OR LOWER(COALESCE(i.title, '')) = LOWER(?) THEN $exactScore
                WHEN i.filename LIKE ? OR i.title LIKE ? THEN $prefixScore
                WHEN i.filename LIKE ? OR i.title LIKE ? THEN $substringScore
                ELSE 0
            END AS match_score
        FROM images i
        INNER JOIN galleries g ON g.id = i.gallery_id
        WHERE i.visibility = 'public'
          AND $listingCondition
          AND (i.filename LIKE ? OR i.title LIKE ?)
        ORDER BY match_score DESC, LOWER(COALESCE(NULLIF(i.title, ''), i.filename)) ASC, i.id ASC
        LIMIT " . (int) $limit;
    return public_search_diagnostics_fetch_all(
        'media.image_name_candidates',
        $sql,
        array_merge([$query, $query, $prefix, $prefix, $like, $like], $contextParams, [$like, $like])
    );
}

/**
 * Fetch image-tag candidates for the media search phase.
 *
 * Tag matches are aggregated by image before joining gallery authorization state,
 * avoiding a wider grouped result across image, gallery, and tag columns.
 *
 * @param string $query Normalized query.
 * @param string $prefix Escaped prefix LIKE pattern.
 * @param string $like Escaped substring LIKE pattern.
 * @param array<string, int> $scores Relevance scores used by SQL ordering.
 * @param string $listingCondition Hardcoded public-listing SQL condition.
 * @param array<int, mixed> $contextParams Bound branch-scope values.
 * @param int $limit Maximum rows returned.
 * @return array<int, array<string, mixed>> Raw image candidate rows.
 */
function public_search_model_media_image_tag_rows(
    string $query,
    string $prefix,
    string $like,
    array $scores,
    string $listingCondition,
    array $contextParams,
    int $limit
): array {
    $limit = max(1, min(90, $limit));
    $exactScore = (int) ($scores['exact'] ?? 0);
    $prefixScore = (int) ($scores['prefix'] ?? 0);
    $substringScore = (int) ($scores['substring'] ?? 0);

    $sql = "SELECT i.id, i.filename, i.title, tag_match.match_score
        FROM (
            SELECT it.image_id,
                MAX(CASE
                    WHEN LOWER(t.name) = LOWER(?) THEN $exactScore
                    WHEN t.name LIKE ? THEN $prefixScore
                    WHEN t.name LIKE ? THEN $substringScore
                    ELSE 0
                END) AS match_score
            FROM tags t
            INNER JOIN image_tags it ON it.tag_id = t.id
            WHERE t.name LIKE ?
            GROUP BY it.image_id
        ) tag_match
        INNER JOIN images i ON i.id = tag_match.image_id
        INNER JOIN galleries g ON g.id = i.gallery_id
        WHERE i.visibility = 'public'
          AND $listingCondition
        ORDER BY tag_match.match_score DESC, LOWER(COALESCE(NULLIF(i.title, ''), i.filename)) ASC, i.id ASC
        LIMIT " . (int) $limit;
    return public_search_diagnostics_fetch_all(
        'media.image_tag_candidates',
        $sql,
        array_merge([$query, $prefix, $like, $like], $contextParams)
    );
}

/**
 * Fetch public image rows for an already-bounded identifier set.
 *
 * @param array<int, int> $ids Image identifiers.
 * @param string $listingCondition Hardcoded public-listing SQL condition.
 * @param array<int, mixed> $contextParams Bound branch-scope values.
 * @return array<int, array<string, mixed>> Image rows.
 */
function public_search_model_media_image_rows_by_ids(array $ids, string $listingCondition, array $contextParams): array
{
    if ($ids === []) {
        return [];
    }
    $placeholders = public_search_model_placeholders($ids);
    $sql = "SELECT i.*
        FROM images i
        INNER JOIN galleries g ON g.id = i.gallery_id
        WHERE i.id IN ($placeholders)
          AND i.visibility = 'public'
          AND $listingCondition";
    return public_search_diagnostics_fetch_all(
        'hydrate.image_rows',
        $sql,
        array_merge($ids, $contextParams)
    );
}

/**
 * Fetch aggregated image tag names after candidate reduction.
 *
 * @param array<int, int> $ids Image identifiers.
 * @return array<int, array<string, mixed>> Image/tag rows.
 */
function public_search_model_image_tag_name_rows(array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $placeholders = public_search_model_placeholders($ids);
    $sql = "SELECT it.image_id,
            GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ') AS image_tag_names
        FROM image_tags it
        INNER JOIN tags t ON t.id = it.tag_id
        WHERE it.image_id IN ($placeholders)
        GROUP BY it.image_id";
    return public_search_diagnostics_fetch_all(
        'hydrate.image_tag_names',
        $sql,
        $ids
    );
}

require_once __DIR__ . '/public_search_progressive/deferred.php';
