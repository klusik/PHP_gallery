<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/public_search_progressive.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides staged public-search contracts and the fast primary gallery phase.
 *
 * Responsibilities:
 *   - Define stable progressive-search phase and relevance contracts
 *   - Discover primary gallery candidates without scanning image metadata
 *   - Hydrate only the bounded gallery candidates selected for display
 *   - Return stable result identities for later browser-side phase merging
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
 *   - The legacy no-phase orchestration remains in public_search.php; its SQL lives in the model layer.
 *   - Do not add image-table joins to the primary gallery candidate query.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Services;

use function Gallery\Core\gallery_public_url;
use function Gallery\Core\image_public_url;
use function Gallery\Models\public_search_model_gallery_rows_by_ids;
use function Gallery\Models\public_search_model_gallery_tag_name_rows;
use function Gallery\Models\public_search_model_image_tag_name_rows;
use function Gallery\Models\public_search_model_media_image_name_rows;
use function Gallery\Models\public_search_model_media_image_rows_by_ids;
use function Gallery\Models\public_search_model_media_image_tag_rows;
use function Gallery\Models\public_search_model_primary_gallery_tag_rows;
use function Gallery\Models\public_search_model_primary_gallery_title_rows;

const PUBLIC_SEARCH_PHASE_PRIMARY = 'primary';
const PUBLIC_SEARCH_PHASE_MEDIA = 'media';
const PUBLIC_SEARCH_PHASE_DESCRIPTIVE = 'descriptive';
const PUBLIC_SEARCH_PHASE_DEEP = 'deep';

/** @var array<string, int> */
const PUBLIC_SEARCH_RELEVANCE_SCORES = [
    'gallery_title_exact' => 1200,
    'gallery_title_normalized_exact' => 1180,
    'gallery_title_prefix' => 1100,
    'gallery_tag_exact' => 1050,
    'gallery_tag_prefix' => 1000,
    'gallery_title_substring' => 950,
    'image_name_exact' => 900,
    'image_tag_exact' => 860,
    'image_name_prefix' => 820,
    'gallery_tag_substring' => 800,
    'image_tag_prefix' => 760,
    'image_name_substring' => 720,
    'image_tag_substring' => 680,
    'translated_title' => 640,
    'gallery_description' => 520,
    'translated_description' => 460,
    'image_description' => 420,
    'tag_description' => 360,
    'ai_searchable_metadata' => 300,
];

/**
 * Return whether a progressive public-search phase is currently implemented.
 *
 * @param string $phase Phase identifier.
 * @return bool True when the phase can be requested from the public endpoint.
 */
function public_search_phase_supported(string $phase): bool
{
    return in_array($phase, [PUBLIC_SEARCH_PHASE_PRIMARY, PUBLIC_SEARCH_PHASE_MEDIA, PUBLIC_SEARCH_PHASE_DESCRIPTIVE, PUBLIC_SEARCH_PHASE_DEEP], true);
}

/**
 * Return the bounded result limit for one progressive search phase.
 *
 * Early phases intentionally return fewer rows than the compatibility endpoint
 * because the browser keeps a compact cross-phase result set near 14 items.
 *
 * @param string $phase Stable phase identifier.
 * @param int $requested Requested endpoint limit.
 * @return int Bounded per-phase result limit.
 */
function public_search_phase_result_limit(string $phase, int $requested): int
{
    $requested = max(1, min(30, $requested));
    if (in_array($phase, [PUBLIC_SEARCH_PHASE_PRIMARY, PUBLIC_SEARCH_PHASE_MEDIA, PUBLIC_SEARCH_PHASE_DESCRIPTIVE, PUBLIC_SEARCH_PHASE_DEEP], true)) {
        return min(8, $requested);
    }
    return $requested;
}

/**
 * Return the centralized relevance score for one search match class.
 *
 * @param string $matchClass Stable match-source identifier.
 * @return int Relevance score, or zero for an unknown class.
 */
function public_search_relevance_score(string $matchClass): int
{
    return (int) (PUBLIC_SEARCH_RELEVANCE_SCORES[$matchClass] ?? 0);
}

/**
 * Return a stable browser merge key for one search result entity.
 *
 * @param string $type Result type.
 * @param int $id Persistent entity identifier.
 * @return string Stable result key.
 */
function public_search_result_key(string $type, int $id): string
{
    $type = $type === 'photo' ? 'photo' : 'gallery';
    return $type . ':' . max(0, $id);
}

/**
 * Execute one implemented progressive public-search phase.
 *
 * @param string $phase Stable phase identifier.
 * @param string $query Query value.
 * @param int $limit Maximum number of result items.
 * @param ?array $contextGallery Optional branch-limiting gallery.
 * @return array<int, array<string, mixed>> Public phase results.
 */
function public_search_phase_results(string $phase, string $query, int $limit = 12, ?array $contextGallery = null): array
{
    if (!public_search_phase_supported($phase)) {
        throw new \InvalidArgumentException('Unsupported public search phase.');
    }

    $query = public_search_normalize_query($query);
    if (public_search_query_length($query) < 2) {
        return [];
    }

    $limit = public_search_phase_result_limit($phase, $limit);

    if ($phase === PUBLIC_SEARCH_PHASE_PRIMARY) {
        return public_search_primary_results($query, $limit, $contextGallery);
    }
    if ($phase === PUBLIC_SEARCH_PHASE_MEDIA) {
        return public_search_media_results($query, $limit, $contextGallery);
    }
    if ($phase === PUBLIC_SEARCH_PHASE_DESCRIPTIVE) {
        return public_search_descriptive_results($query, $limit, $contextGallery);
    }
    if ($phase === PUBLIC_SEARCH_PHASE_DEEP) {
        return public_search_deep_results($query, $limit, $contextGallery);
    }

    return [];
}

/**
 * Return the fast primary gallery/title/tag search results.
 *
 * Candidate discovery intentionally avoids images, image tags, descriptions,
 * AI metadata, and other deep sources. Display details are hydrated only for
 * the bounded candidate IDs that survived the relevance ordering.
 *
 * @param string $query Normalized query value.
 * @param int $limit Maximum number of gallery results.
 * @param ?array $contextGallery Optional branch-limiting gallery.
 * @return array<int, array<string, mixed>> Primary public result models.
 */
function public_search_primary_results(string $query, int $limit, ?array $contextGallery = null): array
{
    $candidates = public_search_primary_gallery_candidates($query, $limit, $contextGallery);
    if ($candidates === []) {
        return [];
    }

    return public_search_hydrate_primary_gallery_candidates($candidates);
}

/**
 * Discover bounded primary gallery candidates using only gallery and gallery-tag data.
 *
 * @param string $query Normalized query value.
 * @param int $limit Maximum number of candidates.
 * @param ?array $contextGallery Optional branch-limiting gallery.
 * @return array<int, array<string, mixed>> Candidate models ordered by relevance.
 */
function public_search_primary_gallery_candidates(string $query, int $limit, ?array $contextGallery = null): array
{
    $query = public_search_normalize_query($query);
    if (public_search_query_length($query) < 2) {
        return [];
    }

    $limit = max(1, min(30, $limit));
    $candidateLimit = min(90, max($limit * 2, $limit + 8));
    $listedOnly = public_search_listing_requires_listed();

    $titleRows = public_search_model_primary_gallery_title_rows(
        $query,
        [
            'exact' => public_search_relevance_score('gallery_title_exact'),
            'normalized_exact' => public_search_relevance_score('gallery_title_normalized_exact'),
            'prefix' => public_search_relevance_score('gallery_title_prefix'),
            'substring' => public_search_relevance_score('gallery_title_substring'),
        ],
        $listedOnly,
        $contextGallery,
        $candidateLimit
    );
    $tagRows = public_search_model_primary_gallery_tag_rows(
        $query,
        [
            'exact' => public_search_relevance_score('gallery_tag_exact'),
            'prefix' => public_search_relevance_score('gallery_tag_prefix'),
            'substring' => public_search_relevance_score('gallery_tag_substring'),
        ],
        $listedOnly,
        $contextGallery,
        $candidateLimit
    );

    $candidates = [];
    foreach ($titleRows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $score = (int) ($row['match_score'] ?? 0);
        if ($id <= 0 || $score <= 0) {
            continue;
        }
        $candidates[$id] = [
            'entity_type' => 'gallery',
            'entity_id' => $id,
            'result_key' => public_search_result_key('gallery', $id),
            'score' => $score,
            'match_source' => public_search_primary_gallery_match_source($score, 0),
            'title_sort' => (string) ($row['title'] ?? ''),
        ];
    }
    foreach ($tagRows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $score = (int) ($row['match_score'] ?? 0);
        if ($id <= 0 || $score <= 0) {
            continue;
        }
        $candidate = [
            'entity_type' => 'gallery',
            'entity_id' => $id,
            'result_key' => public_search_result_key('gallery', $id),
            'score' => $score,
            'match_source' => public_search_primary_gallery_match_source(0, $score),
            'title_sort' => (string) ($row['title'] ?? ''),
        ];
        $existing = $candidates[$id] ?? null;
        if (!is_array($existing) || $score > (int) ($existing['score'] ?? 0)) {
            $candidates[$id] = $candidate;
        }
    }

    $candidates = array_values($candidates);
    usort($candidates, static function (array $left, array $right): int {
        $scoreCompare = ((int) ($right['score'] ?? 0)) <=> ((int) ($left['score'] ?? 0));
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }
        $titleCompare = strcasecmp((string) ($left['title_sort'] ?? ''), (string) ($right['title_sort'] ?? ''));
        if ($titleCompare !== 0) {
            return $titleCompare;
        }
        return ((int) ($left['entity_id'] ?? 0)) <=> ((int) ($right['entity_id'] ?? 0));
    });

    return array_slice($candidates, 0, $limit);
}

/**
 * Return the strongest primary gallery match source from SQL score columns.
 *
 * @param int $titleScore Gallery-title score.
 * @param int $tagScore Gallery-tag score.
 * @return string Stable match-source identifier.
 */
function public_search_primary_gallery_match_source(int $titleScore, int $tagScore): string
{
    if ($titleScore >= $tagScore && $titleScore > 0) {
        return match ($titleScore) {
            1200 => 'gallery_title_exact',
            1180 => 'gallery_title_normalized_exact',
            1100 => 'gallery_title_prefix',
            default => 'gallery_title_substring',
        };
    }

    return match ($tagScore) {
        1050 => 'gallery_tag_exact',
        1000 => 'gallery_tag_prefix',
        default => 'gallery_tag_substring',
    };
}

/**
 * Hydrate bounded primary gallery candidates into public result models.
 *
 * @param array<int, array<string, mixed>> $candidates Ordered candidate models.
 * @return array<int, array<string, mixed>> Hydrated result models in candidate order.
 */
function public_search_hydrate_primary_gallery_candidates(array $candidates): array
{
    $candidateById = [];
    $ids = [];
    foreach ($candidates as $candidate) {
        $id = (int) ($candidate['entity_id'] ?? 0);
        if ($id <= 0 || isset($candidateById[$id])) {
            continue;
        }
        $candidateById[$id] = $candidate;
        $ids[] = $id;
    }
    if ($ids === []) {
        return [];
    }

    $galleryRows = public_search_model_gallery_rows_by_ids($ids);
    if (content_localization_enabled() && content_localization_schema_ready('gallery')) {
        $galleryRows = content_localize_entities('gallery', $galleryRows, translation_active_language());
    }

    $galleriesById = [];
    foreach ($galleryRows as $gallery) {
        $id = (int) ($gallery['id'] ?? 0);
        if ($id > 0) {
            $galleriesById[$id] = $gallery;
        }
    }

    $tagNamesByGalleryId = [];
    foreach (public_search_model_gallery_tag_name_rows($ids) as $row) {
        $galleryId = (int) ($row['gallery_id'] ?? 0);
        if ($galleryId > 0) {
            $tagNamesByGalleryId[$galleryId] = trim((string) ($row['gallery_tag_names'] ?? ''));
        }
    }

    $results = [];
    foreach ($ids as $id) {
        $candidate = $candidateById[$id] ?? null;
        $gallery = $galleriesById[$id] ?? null;
        if (!is_array($candidate) || !is_array($gallery)) {
            continue;
        }

        $details = [];
        $tagNames = $tagNamesByGalleryId[$id] ?? '';
        if ($tagNames !== '') {
            $details[] = t('search.tags_prefix', 'Tags: {tags}', ['tags' => public_search_compact_text($tagNames, 120)]);
        }
        $description = public_search_compact_text((string) ($gallery['description'] ?? ''), 180);
        if ($description !== '') {
            $details[] = $description;
        }

        $results[] = [
            'key' => (string) ($candidate['result_key'] ?? public_search_result_key('gallery', $id)),
            'type' => 'gallery',
            'label' => t('search.type_gallery', 'Gallery'),
            'title' => (string) ($gallery['title'] ?? ''),
            'subtitle' => implode(' · ', array_filter($details)),
            'url' => gallery_public_url($gallery),
            'rank' => (int) ($candidate['score'] ?? 0),
            'match_source' => (string) ($candidate['match_source'] ?? ''),
        ];
    }

    return $results;
}

/**
 * Return lightweight direct image matches for the progressive media phase.
 *
 * The media phase deliberately limits eligibility to image filename/title and
 * image tags. Parent gallery metadata remains authorization/display context and
 * must not manufacture photo matches merely because the gallery itself matches.
 *
 * @param string $query Normalized query value.
 * @param int $limit Maximum number of photo results.
 * @param ?array $contextGallery Optional branch-limiting gallery.
 * @return array<int, array<string, mixed>> Lightweight public photo results.
 */
function public_search_media_results(string $query, int $limit, ?array $contextGallery = null): array
{
    $candidates = public_search_media_image_candidates($query, $limit, $contextGallery);
    if ($candidates === []) {
        return [];
    }

    return public_search_hydrate_media_image_candidates($candidates, $contextGallery);
}

/**
 * Discover bounded media candidates from direct image names/titles and image tags.
 *
 * Candidate discovery is intentionally split into two narrow queries so tag
 * lookup does not multiply every image row by every matching relation. The
 * strongest candidate for each image is retained before hydration.
 *
 * @param string $query Normalized query value.
 * @param int $limit Maximum number of final candidates.
 * @param ?array $contextGallery Optional branch-limiting gallery.
 * @return array<int, array<string, mixed>> Ordered media candidate models.
 */
function public_search_media_image_candidates(string $query, int $limit, ?array $contextGallery = null): array
{
    $query = public_search_normalize_query($query);
    if (public_search_query_length($query) < 2) {
        return [];
    }

    $limit = max(1, min(30, $limit));
    $candidateLimit = min(90, max($limit * 3, $limit + 8));
    $candidates = [];

    foreach (public_search_media_image_name_candidates($query, $candidateLimit, $contextGallery) as $candidate) {
        $id = (int) ($candidate['entity_id'] ?? 0);
        if ($id > 0) {
            $candidates[$id] = $candidate;
        }
    }
    foreach (public_search_media_image_tag_candidates($query, $candidateLimit, $contextGallery) as $candidate) {
        $id = (int) ($candidate['entity_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $existing = $candidates[$id] ?? null;
        if (!is_array($existing) || (int) ($candidate['score'] ?? 0) > (int) ($existing['score'] ?? 0)) {
            $candidates[$id] = $candidate;
        }
    }

    $candidates = array_values($candidates);
    usort($candidates, static function (array $left, array $right): int {
        $scoreCompare = ((int) ($right['score'] ?? 0)) <=> ((int) ($left['score'] ?? 0));
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }
        $titleCompare = strcasecmp((string) ($left['title_sort'] ?? ''), (string) ($right['title_sort'] ?? ''));
        if ($titleCompare !== 0) {
            return $titleCompare;
        }
        return ((int) ($left['entity_id'] ?? 0)) <=> ((int) ($right['entity_id'] ?? 0));
    });

    return array_slice($candidates, 0, $limit);
}

/**
 * Discover direct filename/title image candidates without tag or deep joins.
 *
 * @param string $query Normalized query value.
 * @param int $limit Maximum number of candidates returned by this source.
 * @param ?array $contextGallery Optional branch-limiting gallery.
 * @return array<int, array<string, mixed>> Name/title candidate models.
 */
function public_search_media_image_name_candidates(string $query, int $limit, ?array $contextGallery = null): array
{
    $listedOnly = public_search_listing_requires_listed();

    $rows = public_search_model_media_image_name_rows(
        $query,
        [
            'exact' => public_search_relevance_score('image_name_exact'),
            'prefix' => public_search_relevance_score('image_name_prefix'),
            'substring' => public_search_relevance_score('image_name_substring'),
        ],
        $listedOnly,
        $contextGallery,
        $limit
    );

    $candidates = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $score = (int) ($row['match_score'] ?? 0);
        if ($id <= 0 || $score <= 0) {
            continue;
        }
        $titleSort = trim((string) ($row['title'] ?? ''));
        if ($titleSort === '') {
            $titleSort = (string) ($row['filename'] ?? '');
        }
        $candidates[] = [
            'entity_type' => 'photo',
            'entity_id' => $id,
            'result_key' => public_search_result_key('photo', $id),
            'score' => $score,
            'match_source' => public_search_media_image_name_match_source($score),
            'title_sort' => $titleSort,
        ];
    }

    return $candidates;
}

/**
 * Discover image-tag candidates through the tag-to-image relation index.
 *
 * @param string $query Normalized query value.
 * @param int $limit Maximum number of candidates returned by this source.
 * @param ?array $contextGallery Optional branch-limiting gallery.
 * @return array<int, array<string, mixed>> Image-tag candidate models.
 */
function public_search_media_image_tag_candidates(string $query, int $limit, ?array $contextGallery = null): array
{
    $listedOnly = public_search_listing_requires_listed();

    $rows = public_search_model_media_image_tag_rows(
        $query,
        [
            'exact' => public_search_relevance_score('image_tag_exact'),
            'prefix' => public_search_relevance_score('image_tag_prefix'),
            'substring' => public_search_relevance_score('image_tag_substring'),
        ],
        $listedOnly,
        $contextGallery,
        $limit
    );

    $candidates = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $score = (int) ($row['match_score'] ?? 0);
        if ($id <= 0 || $score <= 0) {
            continue;
        }
        $titleSort = trim((string) ($row['title'] ?? ''));
        if ($titleSort === '') {
            $titleSort = (string) ($row['filename'] ?? '');
        }
        $candidates[] = [
            'entity_type' => 'photo',
            'entity_id' => $id,
            'result_key' => public_search_result_key('photo', $id),
            'score' => $score,
            'match_source' => public_search_media_image_tag_match_source($score),
            'title_sort' => $titleSort,
        ];
    }

    return $candidates;
}

/**
 * Return the stable match source for one direct image name/title score.
 *
 * @param int $score Candidate relevance score.
 * @return string Stable match-source identifier.
 */
function public_search_media_image_name_match_source(int $score): string
{
    if ($score === public_search_relevance_score('image_name_exact')) {
        return 'image_name_exact';
    }
    if ($score === public_search_relevance_score('image_name_prefix')) {
        return 'image_name_prefix';
    }
    return 'image_name_substring';
}

/**
 * Return the stable match source for one image-tag score.
 *
 * @param int $score Candidate relevance score.
 * @return string Stable match-source identifier.
 */
function public_search_media_image_tag_match_source(int $score): string
{
    if ($score === public_search_relevance_score('image_tag_exact')) {
        return 'image_tag_exact';
    }
    if ($score === public_search_relevance_score('image_tag_prefix')) {
        return 'image_tag_prefix';
    }
    return 'image_tag_substring';
}

/**
 * Hydrate bounded media candidates into public photo result models.
 *
 * Visibility and gallery-listing constraints are rechecked during hydration so
 * a row changed between candidate discovery and hydration cannot leak through.
 * Descriptions are display enrichment only here and never participate in media
 * eligibility.
 *
 * @param array<int, array<string, mixed>> $candidates Ordered candidate models.
 * @param ?array $contextGallery Optional branch-limiting gallery.
 * @return array<int, array<string, mixed>> Hydrated media result models.
 */
function public_search_hydrate_media_image_candidates(array $candidates, ?array $contextGallery = null): array
{
    $candidateById = [];
    $ids = [];
    foreach ($candidates as $candidate) {
        $id = (int) ($candidate['entity_id'] ?? 0);
        if ($id <= 0 || isset($candidateById[$id])) {
            continue;
        }
        $candidateById[$id] = $candidate;
        $ids[] = $id;
    }
    if ($ids === []) {
        return [];
    }

    $imageRows = public_search_model_media_image_rows_by_ids(
        $ids,
        public_search_listing_requires_listed(),
        $contextGallery
    );

    $contentLanguage = translation_active_language();
    if (content_localization_enabled() && content_localization_schema_ready('image')) {
        $imageRows = content_localize_entities('image', $imageRows, $contentLanguage);
    }

    $imagesById = [];
    $galleryIds = [];
    foreach ($imageRows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $galleryId = (int) ($row['gallery_id'] ?? 0);
        if ($id <= 0 || $galleryId <= 0) {
            continue;
        }
        $imagesById[$id] = $row;
        $galleryIds[$galleryId] = $galleryId;
    }
    if ($imagesById === [] || $galleryIds === []) {
        return [];
    }

    $galleryRows = public_search_model_gallery_rows_by_ids(array_values($galleryIds));
    if (content_localization_enabled() && content_localization_schema_ready('gallery')) {
        $galleryRows = content_localize_entities('gallery', $galleryRows, $contentLanguage);
    }
    $galleriesById = [];
    foreach ($galleryRows as $gallery) {
        $galleryId = (int) ($gallery['id'] ?? 0);
        if ($galleryId > 0) {
            $galleriesById[$galleryId] = $gallery;
        }
    }

    $tagNamesByImageId = [];
    foreach (public_search_model_image_tag_name_rows($ids) as $row) {
        $imageId = (int) ($row['image_id'] ?? 0);
        if ($imageId > 0) {
            $tagNamesByImageId[$imageId] = trim((string) ($row['image_tag_names'] ?? ''));
        }
    }

    $results = [];
    foreach ($ids as $id) {
        $candidate = $candidateById[$id] ?? null;
        $row = $imagesById[$id] ?? null;
        if (!is_array($candidate) || !is_array($row)) {
            continue;
        }
        $galleryId = (int) ($row['gallery_id'] ?? 0);
        $gallery = $galleriesById[$galleryId] ?? null;
        if (!is_array($gallery)) {
            continue;
        }

        $title = trim((string) ($row['title'] ?? ''));
        if ($title === '') {
            $title = (string) ($row['filename'] ?? t('search.untitled_photo', 'Untitled photo'));
        }
        $details = [t('search.in_gallery', 'In {gallery}', ['gallery' => (string) ($gallery['title'] ?? '')])];
        $tagNames = $tagNamesByImageId[$id] ?? '';
        if ($tagNames !== '') {
            $details[] = t('search.tags_prefix', 'Tags: {tags}', ['tags' => public_search_compact_text($tagNames, 120)]);
        }
        $description = public_search_compact_text((string) ($row['description'] ?? ''), 160);
        if ($description !== '') {
            $details[] = $description;
        }

        $results[] = [
            'key' => (string) ($candidate['result_key'] ?? public_search_result_key('photo', $id)),
            'type' => 'photo',
            'label' => t('search.type_photo', 'Photo'),
            'title' => $title,
            'subtitle' => implode(' · ', array_filter($details)),
            'url' => image_public_url($row, $gallery),
            'rank' => (int) ($candidate['score'] ?? 0),
            'match_source' => (string) ($candidate['match_source'] ?? ''),
        ];
    }

    return $results;
}

require_once __DIR__ . '/public_search_progressive/deferred.php';
