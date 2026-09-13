<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/public_search_progressive/deferred.php
 * Module Type: Service Part
 *
 * Purpose:
 *   Implements deferred descriptive and deep phases for progressive public search.
 *
 * Responsibilities:
 *   - Discover gallery-description candidates without scanning image tables
 *   - Discover deep image candidates through narrow description, translation, tag, and AI queries
 *   - Keep expensive localization and AI metadata out of the initial search critical path
 *   - Hydrate only bounded candidate IDs after relevance ordering
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
 *   - This part is loaded only by app/services/public_search_progressive.php.
 *   - Canonical gallery/image visibility remains the authorization source of truth.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Services;

use function Gallery\Models\public_search_model_gallery_description_rows;
use function Gallery\Models\public_search_model_gallery_tag_description_rows;
use function Gallery\Models\public_search_model_gallery_translated_description_rows;
use function Gallery\Models\public_search_model_gallery_translated_title_rows;
use function Gallery\Models\public_search_model_image_ai_rows;
use function Gallery\Models\public_search_model_image_description_rows;
use function Gallery\Models\public_search_model_image_tag_description_rows;
use function Gallery\Models\public_search_model_image_translation_rows;

/**
 * Return deferred gallery-description matches for the descriptive phase.
 *
 * @param string $query Normalized query value.
 * @param int $limit Maximum number of gallery results.
 * @param ?array $contextGallery Optional branch-limiting gallery.
 * @return array<int, array<string, mixed>> Descriptive public result models.
 */
function public_search_descriptive_results(string $query, int $limit, ?array $contextGallery = null): array
{
    $candidates = public_search_descriptive_gallery_candidates($query, $limit, $contextGallery);
    if ($candidates === []) {
        return [];
    }

    return public_search_hydrate_primary_gallery_candidates($candidates);
}

/**
 * Discover bounded gallery candidates from canonical descriptions, gallery-tag
 * descriptions, and translated gallery titles.
 *
 * Translated descriptions intentionally remain in the later deep phase. This
 * keeps the descriptive request relatively small while preserving translation
 * coverage once the query has remained stable long enough for deep search.
 *
 * @param string $query Normalized query value.
 * @param int $limit Maximum number of final candidates.
 * @param ?array $contextGallery Optional branch-limiting gallery.
 * @return array<int, array<string, mixed>> Ordered gallery candidate models.
 */
function public_search_descriptive_gallery_candidates(string $query, int $limit, ?array $contextGallery = null): array
{
    $query = public_search_normalize_query($query);
    if (public_search_query_length($query) < 2) {
        return [];
    }

    $limit = max(1, min(30, $limit));
    $candidateLimit = min(90, max($limit * 3, $limit + 8));
    $candidates = [];

    public_search_merge_candidate_rows(
        $candidates,
        public_search_descriptive_gallery_description_candidates($query, $candidateLimit, $contextGallery)
    );
    public_search_merge_candidate_rows(
        $candidates,
        public_search_descriptive_gallery_tag_description_candidates($query, $candidateLimit, $contextGallery)
    );
    public_search_merge_candidate_rows(
        $candidates,
        public_search_descriptive_gallery_translated_title_candidates($query, $candidateLimit, $contextGallery)
    );

    return array_slice(public_search_sort_candidate_rows(array_values($candidates)), 0, $limit);
}

/**
 * Discover galleries whose canonical description contains the query.
 *
 * @param string $query Normalized query value.
 * @param int $limit Maximum number of candidates returned by this source.
 * @param ?array $contextGallery Optional branch-limiting gallery.
 * @return array<int, array<string, mixed>> Gallery-description candidate models.
 */
function public_search_descriptive_gallery_description_candidates(string $query, int $limit, ?array $contextGallery = null): array
{
    $listingCondition = public_search_context_listing_sql_fragment('g', $contextGallery);
    $rows = public_search_model_gallery_description_rows(
        public_search_like_pattern($query),
        $listingCondition,
        public_search_context_params($contextGallery),
        $limit
    );
    $score = public_search_relevance_score('gallery_description');

    $candidates = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $candidates[] = public_search_candidate_model('gallery', $id, $score, 'gallery_description', (string) ($row['title'] ?? ''));
    }

    return $candidates;
}

/**
 * Discover galleries through descriptions attached to their gallery tags.
 *
 * @param string $query Normalized query value.
 * @param int $limit Maximum number of candidates returned by this source.
 * @param ?array $contextGallery Optional branch-limiting gallery.
 * @return array<int, array<string, mixed>> Gallery-tag-description candidate models.
 */
function public_search_descriptive_gallery_tag_description_candidates(string $query, int $limit, ?array $contextGallery = null): array
{
    $listingCondition = public_search_context_listing_sql_fragment('g', $contextGallery);
    $rows = public_search_model_gallery_tag_description_rows(
        public_search_like_pattern($query),
        $listingCondition,
        public_search_context_params($contextGallery),
        $limit
    );
    $score = public_search_relevance_score('tag_description');

    $candidates = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $candidates[] = public_search_candidate_model('gallery', $id, $score, 'tag_description', (string) ($row['title'] ?? ''));
    }

    return $candidates;
}

/**
 * Discover galleries by translated title in the active content language.
 *
 * @param string $query Normalized query value.
 * @param int $limit Maximum number of candidates returned by this source.
 * @param ?array $contextGallery Optional branch-limiting gallery.
 * @return array<int, array<string, mixed>> Translated-title gallery candidates.
 */
function public_search_descriptive_gallery_translated_title_candidates(string $query, int $limit, ?array $contextGallery = null): array
{
    if (!content_localization_enabled() || !content_localization_schema_ready('gallery')) {
        return [];
    }

    $listingCondition = public_search_context_listing_sql_fragment('g', $contextGallery);
    $rows = public_search_model_gallery_translated_title_rows(
        translation_active_language(),
        public_search_like_pattern($query),
        $listingCondition,
        public_search_context_params($contextGallery),
        $limit
    );
    $score = public_search_relevance_score('translated_title');

    $candidates = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $candidates[] = public_search_candidate_model('gallery', $id, $score, 'translated_title', (string) ($row['title_sort'] ?? ''));
    }

    return $candidates;
}

/**
 * Return late deep search results after descriptions, translations, and optional
 * AI metadata have been evaluated through separate bounded candidate queries.
 *
 * @param string $query Normalized query value.
 * @param int $limit Maximum number of mixed gallery/photo results.
 * @param ?array $contextGallery Optional branch-limiting gallery.
 * @return array<int, array<string, mixed>> Deep public result models.
 */
function public_search_deep_results(string $query, int $limit, ?array $contextGallery = null): array
{
    $query = public_search_normalize_query($query);
    if (public_search_query_length($query) < 2) {
        return [];
    }

    $limit = max(1, min(30, $limit));
    $candidateLimit = min(90, max($limit * 3, $limit + 8));
    $candidates = [];

    public_search_merge_candidate_rows(
        $candidates,
        public_search_deep_gallery_translated_description_candidates($query, $candidateLimit, $contextGallery)
    );
    public_search_merge_candidate_rows(
        $candidates,
        public_search_deep_image_candidates($query, $candidateLimit, $contextGallery)
    );

    $ordered = array_slice(public_search_sort_candidate_rows(array_values($candidates)), 0, $limit);
    return public_search_hydrate_deep_candidates($ordered, $contextGallery);
}

/**
 * Discover galleries through translated descriptions in the active language.
 *
 * @param string $query Normalized query value.
 * @param int $limit Maximum number of candidates returned by this source.
 * @param ?array $contextGallery Optional branch-limiting gallery.
 * @return array<int, array<string, mixed>> Translated-description gallery candidates.
 */
function public_search_deep_gallery_translated_description_candidates(string $query, int $limit, ?array $contextGallery = null): array
{
    if (!content_localization_enabled() || !content_localization_schema_ready('gallery')) {
        return [];
    }

    $listingCondition = public_search_context_listing_sql_fragment('g', $contextGallery);
    $rows = public_search_model_gallery_translated_description_rows(
        translation_active_language(),
        public_search_like_pattern($query),
        $listingCondition,
        public_search_context_params($contextGallery),
        $limit
    );
    $score = public_search_relevance_score('translated_description');

    $candidates = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $candidates[] = public_search_candidate_model('gallery', $id, $score, 'translated_description', (string) ($row['title_sort'] ?? ''));
    }

    return $candidates;
}

/**
 * Discover deep photo candidates from each approved expensive metadata source.
 *
 * Each source uses its own bounded query so description/tag/translation/AI data
 * cannot multiply one another in a single large intermediate result set.
 *
 * @param string $query Normalized query value.
 * @param int $limit Maximum number of final photo candidates.
 * @param ?array $contextGallery Optional branch-limiting gallery.
 * @return array<int, array<string, mixed>> Ordered deep image candidates.
 */
function public_search_deep_image_candidates(string $query, int $limit, ?array $contextGallery = null): array
{
    $candidates = [];

    public_search_merge_candidate_rows(
        $candidates,
        public_search_deep_image_description_candidates($query, $limit, $contextGallery)
    );
    public_search_merge_candidate_rows(
        $candidates,
        public_search_deep_image_tag_description_candidates($query, $limit, $contextGallery)
    );
    public_search_merge_candidate_rows(
        $candidates,
        public_search_deep_image_translation_candidates($query, $limit, $contextGallery)
    );
    public_search_merge_candidate_rows(
        $candidates,
        public_search_deep_image_ai_candidates($query, $limit, $contextGallery)
    );

    return array_slice(public_search_sort_candidate_rows(array_values($candidates)), 0, $limit);
}

/**
 * Discover public images whose canonical description contains the query.
 *
 * @param string $query Normalized query value.
 * @param int $limit Maximum number of candidates returned by this source.
 * @param ?array $contextGallery Optional branch-limiting gallery.
 * @return array<int, array<string, mixed>> Image-description candidate models.
 */
function public_search_deep_image_description_candidates(string $query, int $limit, ?array $contextGallery = null): array
{
    $listingCondition = public_search_context_listing_sql_fragment('g', $contextGallery);
    $rows = public_search_model_image_description_rows(
        public_search_like_pattern($query),
        $listingCondition,
        public_search_context_params($contextGallery),
        $limit
    );
    return public_search_deep_image_rows_to_candidates(
        $rows,
        public_search_relevance_score('image_description'),
        'image_description'
    );
}

/**
 * Discover public images through descriptions attached to their image tags.
 *
 * @param string $query Normalized query value.
 * @param int $limit Maximum number of candidates returned by this source.
 * @param ?array $contextGallery Optional branch-limiting gallery.
 * @return array<int, array<string, mixed>> Image-tag-description candidate models.
 */
function public_search_deep_image_tag_description_candidates(string $query, int $limit, ?array $contextGallery = null): array
{
    $listingCondition = public_search_context_listing_sql_fragment('g', $contextGallery);
    $rows = public_search_model_image_tag_description_rows(
        public_search_like_pattern($query),
        $listingCondition,
        public_search_context_params($contextGallery),
        $limit
    );
    return public_search_deep_image_rows_to_candidates(
        $rows,
        public_search_relevance_score('tag_description'),
        'tag_description'
    );
}

/**
 * Discover public images through translated title/description metadata.
 *
 * @param string $query Normalized query value.
 * @param int $limit Maximum number of candidates returned by this source.
 * @param ?array $contextGallery Optional branch-limiting gallery.
 * @return array<int, array<string, mixed>> Localized image candidate models.
 */
function public_search_deep_image_translation_candidates(string $query, int $limit, ?array $contextGallery = null): array
{
    if (!content_localization_enabled() || !content_localization_schema_ready('image')) {
        return [];
    }

    $listingCondition = public_search_context_listing_sql_fragment('g', $contextGallery);
    $like = public_search_like_pattern($query);
    $titleScore = public_search_relevance_score('translated_title');
    $descriptionScore = public_search_relevance_score('translated_description');
    $rows = public_search_model_image_translation_rows(
        translation_active_language(),
        $like,
        $titleScore,
        $descriptionScore,
        $listingCondition,
        public_search_context_params($contextGallery),
        $limit
    );

    $candidates = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $score = (int) ($row['match_score'] ?? 0);
        if ($id <= 0 || $score <= 0) {
            continue;
        }
        $source = $score === $titleScore ? 'translated_title' : 'translated_description';
        $candidates[] = public_search_candidate_model('photo', $id, $score, $source, (string) ($row['title_sort'] ?? ''));
    }

    return $candidates;
}

/**
 * Discover public images through optional local AI searchable metadata.
 *
 * AI scanning is deliberately isolated in the last progressive phase and is
 * skipped entirely when the canonical AI metadata capability/schema is not ready.
 *
 * @param string $query Normalized query value.
 * @param int $limit Maximum number of candidates returned by this source.
 * @param ?array $contextGallery Optional branch-limiting gallery.
 * @return array<int, array<string, mixed>> AI-backed image candidate models.
 */
function public_search_deep_image_ai_candidates(string $query, int $limit, ?array $contextGallery = null): array
{
    if (!public_search_ai_metadata_ready()) {
        return [];
    }

    $listingCondition = public_search_context_listing_sql_fragment('g', $contextGallery);
    $rows = public_search_model_image_ai_rows(
        public_search_like_pattern($query),
        $listingCondition,
        public_search_context_params($contextGallery),
        $limit
    );
    return public_search_deep_image_rows_to_candidates(
        $rows,
        public_search_relevance_score('ai_searchable_metadata'),
        'ai_searchable_metadata'
    );
}

/**
 * Convert a narrow deep-image query result into standard candidate models.
 *
 * @param array<int, array<string, mixed>> $rows Candidate source rows.
 * @param int $score Relevance score for the source.
 * @param string $matchSource Stable match-source identifier.
 * @return array<int, array<string, mixed>> Standard candidate models.
 */
function public_search_deep_image_rows_to_candidates(array $rows, int $score, string $matchSource): array
{
    $candidates = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || $score <= 0) {
            continue;
        }
        $titleSort = trim((string) ($row['title'] ?? ''));
        if ($titleSort === '') {
            $titleSort = (string) ($row['filename'] ?? '');
        }
        $candidates[] = public_search_candidate_model('photo', $id, $score, $matchSource, $titleSort);
    }

    return $candidates;
}

/**
 * Create one normalized candidate model shared by deferred search sources.
 *
 * @param string $entityType Gallery or photo entity type.
 * @param int $entityId Persistent entity identifier.
 * @param int $score Relevance score.
 * @param string $matchSource Stable match-source identifier.
 * @param string $titleSort Display title used for deterministic tie-breaking.
 * @return array<string, mixed> Candidate model.
 */
function public_search_candidate_model(string $entityType, int $entityId, int $score, string $matchSource, string $titleSort): array
{
    $entityType = $entityType === 'photo' ? 'photo' : 'gallery';
    return [
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'result_key' => public_search_result_key($entityType, $entityId),
        'score' => $score,
        'match_source' => $matchSource,
        'title_sort' => $titleSort,
    ];
}

/**
 * Merge candidate rows by stable result key while retaining the strongest match.
 *
 * @param array<string, array<string, mixed>> $target Candidate map modified in place.
 * @param array<int, array<string, mixed>> $incoming Candidate rows from one source.
 * @return void
 */
function public_search_merge_candidate_rows(array &$target, array $incoming): void
{
    foreach ($incoming as $candidate) {
        $key = (string) ($candidate['result_key'] ?? '');
        if ($key === '') {
            continue;
        }
        $existing = $target[$key] ?? null;
        if (!is_array($existing) || (int) ($candidate['score'] ?? 0) > (int) ($existing['score'] ?? 0)) {
            $target[$key] = $candidate;
        }
    }
}

/**
 * Sort candidate rows by relevance, entity type, display title, and stable ID.
 *
 * @param array<int, array<string, mixed>> $candidates Candidate rows.
 * @return array<int, array<string, mixed>> Deterministically ordered candidates.
 */
function public_search_sort_candidate_rows(array $candidates): array
{
    usort($candidates, static function (array $left, array $right): int {
        $scoreCompare = ((int) ($right['score'] ?? 0)) <=> ((int) ($left['score'] ?? 0));
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }
        $leftType = ($left['entity_type'] ?? '') === 'gallery' ? 0 : 1;
        $rightType = ($right['entity_type'] ?? '') === 'gallery' ? 0 : 1;
        if ($leftType !== $rightType) {
            return $leftType <=> $rightType;
        }
        $titleCompare = strcasecmp((string) ($left['title_sort'] ?? ''), (string) ($right['title_sort'] ?? ''));
        if ($titleCompare !== 0) {
            return $titleCompare;
        }
        return ((int) ($left['entity_id'] ?? 0)) <=> ((int) ($right['entity_id'] ?? 0));
    });

    return $candidates;
}

/**
 * Hydrate a mixed deep candidate list while preserving its relevance order.
 *
 * @param array<int, array<string, mixed>> $candidates Ordered mixed candidate models.
 * @param ?array $contextGallery Optional branch-limiting gallery.
 * @return array<int, array<string, mixed>> Hydrated result models in candidate order.
 */
function public_search_hydrate_deep_candidates(array $candidates, ?array $contextGallery = null): array
{
    if ($candidates === []) {
        return [];
    }

    $galleryCandidates = [];
    $photoCandidates = [];
    foreach ($candidates as $candidate) {
        if (($candidate['entity_type'] ?? '') === 'photo') {
            $photoCandidates[] = $candidate;
        } else {
            $galleryCandidates[] = $candidate;
        }
    }

    $hydratedByKey = [];
    foreach (public_search_hydrate_primary_gallery_candidates($galleryCandidates) as $result) {
        $key = (string) ($result['key'] ?? '');
        if ($key !== '') {
            $hydratedByKey[$key] = $result;
        }
    }
    foreach (public_search_hydrate_media_image_candidates($photoCandidates, $contextGallery) as $result) {
        $key = (string) ($result['key'] ?? '');
        if ($key !== '') {
            $hydratedByKey[$key] = $result;
        }
    }

    $results = [];
    foreach ($candidates as $candidate) {
        $key = (string) ($candidate['result_key'] ?? '');
        if ($key !== '' && isset($hydratedByKey[$key])) {
            $results[] = $hydratedByKey[$key];
        }
    }

    return $results;
}
