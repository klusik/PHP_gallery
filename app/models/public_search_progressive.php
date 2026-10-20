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
 *   - Search scope SQL and wildcard patterns are built inside the model from semantic service inputs.
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
 * @param array<string, int> $scores Relevance scores used by SQL ordering.
 * @param bool $listedOnly Whether public access policy requires listed galleries.
 * @param ?array $contextGallery Optional gallery branch context.
 * @param int $limit Maximum rows returned.
 * @return array<int, array<string, mixed>> Raw candidate rows.
 */
function public_search_model_primary_gallery_title_rows(
    string $query,
    array $scores,
    bool $listedOnly,
    ?array $contextGallery,
    int $limit,
): array {
    $limit = max(1, min(90, $limit));
    $prefix = public_search_model_prefix_pattern($query);
    $like = public_search_model_like_pattern($query);
    [$listingCondition, $contextParams] = public_search_model_listing_scope(
        'g',
        $listedOnly,
        $contextGallery,
    );
    $titleExact = (int) ($scores['exact'] ?? 0);
    $titleNormalizedExact = (int) ($scores['normalized_exact'] ?? 0);
    $titlePrefix = (int) ($scores['prefix'] ?? 0);
    $titleSubstring = (int) ($scores['substring'] ?? 0);

    $sql =
        "SELECT g.id, g.title,
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
        array_merge([$query, $query, $prefix, $like], $contextParams, [$like]),
    );
}

/**
 * Fetch gallery-tag candidates for the primary search phase.
 *
 * Matching tags are reduced to one strongest score per gallery before result hydration.
 *
 * @param string $query Normalized query.
 * @param array<string, int> $scores Relevance scores used by SQL ordering.
 * @param bool $listedOnly Whether public access policy requires listed galleries.
 * @param ?array $contextGallery Optional gallery branch context.
 * @param int $limit Maximum rows returned.
 * @return array<int, array<string, mixed>> Raw candidate rows.
 */
function public_search_model_primary_gallery_tag_rows(
    string $query,
    array $scores,
    bool $listedOnly,
    ?array $contextGallery,
    int $limit,
): array {
    $limit = max(1, min(90, $limit));
    $prefix = public_search_model_prefix_pattern($query);
    $like = public_search_model_like_pattern($query);
    [$listingCondition, $contextParams] = public_search_model_listing_scope(
        'g',
        $listedOnly,
        $contextGallery,
    );
    $tagExact = (int) ($scores['exact'] ?? 0);
    $tagPrefix = (int) ($scores['prefix'] ?? 0);
    $tagSubstring = (int) ($scores['substring'] ?? 0);

    $sql =
        "SELECT g.id, g.title,
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
        array_merge([$query, $prefix, $like, $like], $contextParams),
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
        $ids,
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
        $ids,
    );
}

/**
 * Fetch direct filename/title candidates for the media search phase.
 *
 * @param string $query Normalized query.
 * @param array<string, int> $scores Relevance scores used by SQL ordering.
 * @param bool $listedOnly Whether public access policy requires listed galleries.
 * @param ?array $contextGallery Optional gallery branch context.
 * @param int $limit Maximum rows returned.
 * @return array<int, array<string, mixed>> Raw image candidate rows.
 */
function public_search_model_media_image_name_rows(
    string $query,
    array $scores,
    bool $listedOnly,
    ?array $contextGallery,
    int $limit,
): array {
    $limit = max(1, min(90, $limit));
    $prefix = public_search_model_prefix_pattern($query);
    $like = public_search_model_like_pattern($query);
    [$listingCondition, $contextParams] = public_search_model_listing_scope(
        'g',
        $listedOnly,
        $contextGallery,
    );
    $exactScore = (int) ($scores['exact'] ?? 0);
    $prefixScore = (int) ($scores['prefix'] ?? 0);
    $substringScore = (int) ($scores['substring'] ?? 0);

    $sql =
        "SELECT i.id, i.filename, i.title,
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
        array_merge(
            [$query, $query, $prefix, $prefix, $like, $like],
            $contextParams,
            [$like, $like],
        ),
    );
}

/**
 * Fetch image-tag candidates for the media search phase.
 *
 * Tag matches are aggregated by image before joining gallery authorization state,
 * avoiding a wider grouped result across image, gallery, and tag columns.
 *
 * @param string $query Normalized query.
 * @param array<string, int> $scores Relevance scores used by SQL ordering.
 * @param bool $listedOnly Whether public access policy requires listed galleries.
 * @param ?array $contextGallery Optional gallery branch context.
 * @param int $limit Maximum rows returned.
 * @return array<int, array<string, mixed>> Raw image candidate rows.
 */
function public_search_model_media_image_tag_rows(
    string $query,
    array $scores,
    bool $listedOnly,
    ?array $contextGallery,
    int $limit,
): array {
    $limit = max(1, min(90, $limit));
    $prefix = public_search_model_prefix_pattern($query);
    $like = public_search_model_like_pattern($query);
    [$listingCondition, $contextParams] = public_search_model_listing_scope(
        'g',
        $listedOnly,
        $contextGallery,
    );
    $exactScore = (int) ($scores['exact'] ?? 0);
    $prefixScore = (int) ($scores['prefix'] ?? 0);
    $substringScore = (int) ($scores['substring'] ?? 0);

    $sql =
        "SELECT i.id, i.filename, i.title, tag_match.match_score
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
        array_merge([$query, $prefix, $like, $like], $contextParams),
    );
}

/**
 * Fetch public image rows for an already-bounded identifier set.
 *
 * @param array<int, int> $ids Image identifiers.
 * @param bool $listedOnly Whether public access policy requires listed galleries.
 * @param ?array $contextGallery Optional gallery branch context.
 * @return array<int, array<string, mixed>> Image rows.
 */
function public_search_model_media_image_rows_by_ids(
    array $ids,
    bool $listedOnly,
    ?array $contextGallery,
): array {
    if ($ids === []) {
        return [];
    }
    [$listingCondition, $contextParams] = public_search_model_listing_scope(
        'g',
        $listedOnly,
        $contextGallery,
    );
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
        array_merge($ids, $contextParams),
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
        $ids,
    );
}

// Phase-bounded Boolean query model helpers share this registered runtime module.
/**
 * Construct a bound condition for one term against the fields enabled in this phase.
 *
 * The optional "new only" mode describes fields introduced in the current deferred
 * phase. It prevents a late phase from filling its bounded result set with hits
 * already eligible for a faster phase.
 *
 * @param string $kind gallery or photo.
 * @param string $phase Progressive phase.
 * @param string $term Unquoted term or quoted exact phrase.
 * @param bool $translated Whether the matching translation schema is available.
 * @param string $language Active content language.
 * @param bool $aiReady Whether image AI metadata may be searched.
 * @param bool $newOnly Limit matching to attributes introduced in this phase.
 * @return array{0:string,1:array<int,string>} Bound predicate and parameters.
 */
function public_search_model_compound_term_condition(
    string $kind,
    string $phase,
    string $term,
    bool $translated,
    string $language,
    bool $aiReady,
    bool $newOnly = false,
): array {
    $like = public_search_model_like_pattern($term);
    $conditions = [];
    $params = [];

    if ($kind === 'gallery') {
        if (!$newOnly) {
            $conditions[] = 'g.title LIKE ?';
            $params[] = $like;
        }
        if ($phase !== 'primary' && (!$newOnly || $phase === 'descriptive')) {
            $conditions[] = 'g.description LIKE ?';
            $params[] = $like;
        }

        $tagParts = [];
        if (!$newOnly) {
            $tagParts[] = 't.name LIKE ?';
        }
        if ($phase !== 'primary' && (!$newOnly || $phase === 'descriptive')) {
            $tagParts[] = 't.description LIKE ?';
        }
        if ($tagParts !== []) {
            $conditions[] =
                'EXISTS (SELECT 1 FROM gallery_tags gt '
                . 'INNER JOIN tags t ON t.id = gt.tag_id '
                . 'WHERE gt.gallery_id = g.id AND ('
                . implode(' OR ', $tagParts)
                . '))';
            foreach ($tagParts as $_) {
                $params[] = $like;
            }
        }

        if ($translated && $phase !== 'primary') {
            $translationParts = [];
            if (!$newOnly || $phase === 'descriptive') {
                $translationParts[] = 'tr.title LIKE ?';
            }
            if ($phase === 'deep') {
                $translationParts[] = 'tr.description LIKE ?';
            }
            if ($translationParts !== []) {
                $conditions[] =
                    'EXISTS (SELECT 1 FROM gallery_translations tr '
                    . 'WHERE tr.gallery_id = g.id AND tr.language_code = ? AND ('
                    . implode(' OR ', $translationParts)
                    . '))';
                $params[] = $language;
                foreach ($translationParts as $_) {
                    $params[] = $like;
                }
            }
        }
    } else {
        if (!$newOnly) {
            $conditions[] = 'i.filename LIKE ?';
            $conditions[] = 'i.title LIKE ?';
            array_push($params, $like, $like);
        }
        if ($phase === 'deep') {
            $conditions[] = 'i.description LIKE ?';
            $params[] = $like;
        }

        $tagParts = [];
        if (!$newOnly) {
            $tagParts[] = 't.name LIKE ?';
        }
        if ($phase === 'deep') {
            $tagParts[] = 't.description LIKE ?';
        }
        if ($tagParts !== []) {
            $conditions[] =
                'EXISTS (SELECT 1 FROM image_tags it '
                . 'INNER JOIN tags t ON t.id = it.tag_id '
                . 'WHERE it.image_id = i.id AND ('
                . implode(' OR ', $tagParts)
                . '))';
            foreach ($tagParts as $_) {
                $params[] = $like;
            }
        }

        if ($translated && $phase === 'deep') {
            $conditions[] =
                'EXISTS (SELECT 1 FROM image_translations tr '
                . 'WHERE tr.image_id = i.id AND tr.language_code = ? '
                . 'AND (tr.title LIKE ? OR tr.description LIKE ?))';
            array_push($params, $language, $like, $like);
        }
        if ($aiReady && $phase === 'deep') {
            $conditions[] =
                'EXISTS (SELECT 1 FROM image_ai_metadata m '
                . 'WHERE m.image_id = i.id AND m.searchable_text LIKE ?)';
            $params[] = $like;
        }
    }

    if ($conditions === []) {
        return ['0 = 1', []];
    }
    return ['(' . implode(' OR ', $conditions) . ')', $params];
}

/**
 * Build (AND terms) OR (AND terms) without moving term matching across entities.
 *
 * @param array<int,array<int,string>> $groups Disjunctive groups of required terms.
 * @param string $kind gallery or photo.
 * @param string $phase Progressive phase.
 * @param bool $translated Availability of active-language entity translations.
 * @param string $language Active content language.
 * @param bool $aiReady Whether AI image metadata is enabled.
 * @return array{0:string,1:array<int,string>} SQL predicate and ordered bindings.
 */
function public_search_model_compound_expression(
    array $groups,
    string $kind,
    string $phase,
    bool $translated,
    string $language,
    bool $aiReady,
): array {
    $alternatives = [];
    $params = [];
    foreach ($groups as $group) {
        $required = [];
        foreach ($group as $term) {
            [$condition, $termParams] =
                public_search_model_compound_term_condition(
                    $kind,
                    $phase,
                    $term,
                    $translated,
                    $language,
                    $aiReady,
                );
            $required[] = $condition;
            array_push($params, ...$termParams);
        }
        if ($required !== []) {
            $alternatives[] = '(' . implode(' AND ', $required) . ')';
        }
    }
    return [
        $alternatives === []
            ? '0 = 1'
            : '(' . implode(' OR ', $alternatives) . ')',
        $params,
    ];
}

/**
 * Match a field newly added in a deferred phase for at least one search term.
 *
 * @param array<int,array<int,string>> $groups Search groups.
 * @param string $kind gallery or photo.
 * @param string $phase Progressive phase.
 * @param bool $translated Whether translated fields may be searched.
 * @param string $language Active content language.
 * @param bool $aiReady Whether AI metadata may be searched.
 * @return array{0:string,1:array<int,string>} Optional late-phase eligibility predicate.
 */
function public_search_model_compound_new_source_condition(
    array $groups,
    string $kind,
    string $phase,
    bool $translated,
    string $language,
    bool $aiReady,
): array {
    if ($phase === 'primary' || $phase === 'media') {
        return ['1 = 1', []];
    }
    $conditions = [];
    $params = [];
    foreach ($groups as $group) {
        foreach ($group as $term) {
            [$condition, $termParams] =
                public_search_model_compound_term_condition(
                    $kind,
                    $phase,
                    $term,
                    $translated,
                    $language,
                    $aiReady,
                    true,
                );
            $conditions[] = $condition;
            array_push($params, ...$termParams);
        }
    }
    return [
        $conditions === [] ? '0 = 1' : '(' . implode(' OR ', $conditions) . ')',
        $params,
    ];
}

/**
 * Find bounded gallery candidates whose own metadata satisfies a compound query.
 *
 * No images or image tags participate in gallery eligibility: media matches
 * belong to photo entities and continue through their separately authorized
 * media/deep phases.
 *
 * @param array<int,array<int,string>> $groups Parsed query groups.
 * @param string $phase primary, descriptive, or deep.
 * @param bool $listedOnly Whether galleries must be publicly listed.
 * @param ?array $contextGallery Optional gallery-branch restriction.
 * @param bool $translated Whether active-language gallery translations are ready.
 * @param string $language Active content language.
 * @param int $limit Maximum candidate rows.
 * @return array<int,array<string,mixed>> Bounded gallery candidate rows.
 */
function public_search_model_compound_gallery_rows(
    array $groups,
    string $phase,
    bool $listedOnly,
    ?array $contextGallery,
    bool $translated,
    string $language,
    int $limit,
): array {
    $limit = max(1, min(90, $limit));
    [$listing, $contextParams] = public_search_model_listing_scope(
        'g',
        $listedOnly,
        $contextGallery,
    );
    [$required, $matchParams] = public_search_model_compound_expression(
        $groups,
        'gallery',
        $phase,
        $translated,
        $language,
        false,
    );
    [$newSource, $newParams] =
        public_search_model_compound_new_source_condition(
            $groups,
            'gallery',
            $phase,
            $translated,
            $language,
            false,
        );
    $joined = implode(' ', $groups[0] ?? []);
    $first = (string) ($groups[0][0] ?? '');
    $scoreParams = [
        $joined,
        public_search_model_prefix_pattern($joined),
        public_search_model_like_pattern($first),
        public_search_model_like_pattern($first),
    ];
    $descriptionScore = '';
    if ($phase !== 'primary') {
        $descriptionScore = 'WHEN g.description LIKE ? THEN 520';
        $scoreParams[] = public_search_model_like_pattern($first);
    }
    $sql =
        "SELECT g.id, g.title,
            CASE
                WHEN LOWER(g.title) = LOWER(?) THEN 1200
                WHEN g.title LIKE ? THEN 1100
                WHEN g.title LIKE ? THEN 950
                WHEN EXISTS (
                    SELECT 1 FROM gallery_tags gt
                    INNER JOIN tags t ON t.id = gt.tag_id
                    WHERE gt.gallery_id = g.id AND t.name LIKE ?
                ) THEN 800
                $descriptionScore
                ELSE 360
            END AS match_score
        FROM galleries g
        WHERE $listing
          AND $required
          AND $newSource
        ORDER BY match_score DESC, LOWER(g.title) ASC, g.id ASC
        LIMIT " . (int) $limit;
    return public_search_diagnostics_fetch_all(
        'compound.' . $phase . '.gallery_candidates',
        $sql,
        array_merge($scoreParams, $contextParams, $matchParams, $newParams),
    );
}

/**
 * Find bounded photo candidates against phase-appropriate photo metadata.
 *
 * @param array<int,array<int,string>> $groups Parsed query groups.
 * @param string $phase media or deep.
 * @param bool $listedOnly Whether the parent gallery must be publicly listed.
 * @param ?array $contextGallery Optional gallery-branch restriction.
 * @param bool $translated Whether active-language image translations are ready.
 * @param string $language Active content language.
 * @param bool $aiReady Whether AI image metadata can be searched.
 * @param int $limit Maximum candidate rows.
 * @return array<int,array<string,mixed>> Bounded image candidate rows.
 */
function public_search_model_compound_image_rows(
    array $groups,
    string $phase,
    bool $listedOnly,
    ?array $contextGallery,
    bool $translated,
    string $language,
    bool $aiReady,
    int $limit,
): array {
    $limit = max(1, min(90, $limit));
    [$listing, $contextParams] = public_search_model_listing_scope(
        'g',
        $listedOnly,
        $contextGallery,
    );
    [$required, $matchParams] = public_search_model_compound_expression(
        $groups,
        'photo',
        $phase,
        $translated,
        $language,
        $aiReady,
    );
    [$newSource, $newParams] =
        public_search_model_compound_new_source_condition(
            $groups,
            'photo',
            $phase,
            $translated,
            $language,
            $aiReady,
        );
    $joined = implode(' ', $groups[0] ?? []);
    $first = (string) ($groups[0][0] ?? '');
    $prefix = public_search_model_prefix_pattern($joined);
    $like = public_search_model_like_pattern($first);
    $scoreParams = [$joined, $joined, $prefix, $prefix, $like, $like, $like];
    $descriptionScore = '';
    if ($phase === 'deep') {
        $descriptionScore = 'WHEN i.description LIKE ? THEN 420';
        $scoreParams[] = $like;
    }
    $sql =
        "SELECT i.id, i.filename, i.title,
            CASE
                WHEN LOWER(i.filename) = LOWER(?) OR LOWER(COALESCE(i.title, '')) = LOWER(?) THEN 900
                WHEN i.filename LIKE ? OR i.title LIKE ? THEN 820
                WHEN i.filename LIKE ? OR i.title LIKE ? THEN 720
                WHEN EXISTS (
                    SELECT 1 FROM image_tags it
                    INNER JOIN tags t ON t.id = it.tag_id
                    WHERE it.image_id = i.id AND t.name LIKE ?
                ) THEN 680
                $descriptionScore
                ELSE 300
            END AS match_score
        FROM images i
        INNER JOIN galleries g ON g.id = i.gallery_id
        WHERE i.visibility = 'public'
          AND $listing
          AND $required
          AND $newSource
        ORDER BY match_score DESC, LOWER(COALESCE(NULLIF(i.title, ''), i.filename)) ASC, i.id ASC
        LIMIT " . (int) $limit;
    return public_search_diagnostics_fetch_all(
        'compound.' . $phase . '.image_candidates',
        $sql,
        array_merge($scoreParams, $contextParams, $matchParams, $newParams),
    );
}

require_once __DIR__ . '/public_search_progressive/deferred.php';
