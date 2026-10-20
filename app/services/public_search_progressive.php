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
 *   2026-09-19
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
use function Gallery\Models\public_search_model_compound_gallery_rows;
use function Gallery\Models\public_search_model_compound_image_rows;
use function Gallery\Services\tag_media_type_schema_state;

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
    return in_array(
        $phase,
        [
            PUBLIC_SEARCH_PHASE_PRIMARY,
            PUBLIC_SEARCH_PHASE_MEDIA,
            PUBLIC_SEARCH_PHASE_DESCRIPTIVE,
            PUBLIC_SEARCH_PHASE_DEEP,
        ],
        true,
    );
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
    if (in_array(
        $phase,
        [
            PUBLIC_SEARCH_PHASE_PRIMARY,
            PUBLIC_SEARCH_PHASE_MEDIA,
            PUBLIC_SEARCH_PHASE_DESCRIPTIVE,
            PUBLIC_SEARCH_PHASE_DEEP,
        ],
        true,
    )) {
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
 * @param ?array{id:int,folder_path:string} $contextGallery Optional branch-limiting gallery.
 * @return list<array{key:string,type:'gallery'|'photo',label:string,title:string,subtitle:string,url:string,rank:int,match_source:string}> Ranked public result cards for the requested phase, or an empty list when unsupported or unmatched.
 */
function public_search_phase_results(
    string $phase,
    string $query,
    int $limit = 12,
    ?array $contextGallery = null,
): array {
    if (!public_search_phase_supported($phase)) {
        throw new \InvalidArgumentException('Unsupported public search phase.');
    }

    $query = public_search_normalize_query($query);
    if (public_search_query_length($query) < 2) {
        return [];
    }

    $limit = public_search_phase_result_limit($phase, $limit);
    $groups = public_search_parse_groups($query);
    if ($groups === []) {
        return [];
    }
    if (count($groups) === 1 && count($groups[0]) === 1) {
        // An ordinary one-term or explicitly quoted phrase stays on the fast path.
        $query = $groups[0][0];
        if (public_search_query_length($query) < 2) {
            return [];
        }
    } else {
        return public_search_compound_phase_results(
            $phase,
            $groups,
            $limit,
            $contextGallery,
        );
    }

    if ($phase === PUBLIC_SEARCH_PHASE_PRIMARY) {
        return public_search_primary_results($query, $limit, $contextGallery);
    }
    if ($phase === PUBLIC_SEARCH_PHASE_MEDIA) {
        return public_search_media_results($query, $limit, $contextGallery);
    }
    if ($phase === PUBLIC_SEARCH_PHASE_DESCRIPTIVE) {
        return public_search_descriptive_results(
            $query,
            $limit,
            $contextGallery,
        );
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
 * @param ?array{id:int,folder_path:string} $contextGallery Optional branch-limiting gallery.
 * @return list<array{key:string,type:'gallery',label:string,title:string,subtitle:string,url:string,rank:int,match_source:string}> Authorized gallery cards ordered by primary relevance.
 */
function public_search_primary_results(
    string $query,
    int $limit,
    ?array $contextGallery = null,
): array {
    $candidates = public_search_primary_gallery_candidates(
        $query,
        $limit,
        $contextGallery,
    );
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
 * @param ?array{id:int,folder_path:string} $contextGallery Optional branch-limiting gallery.
 * @return list<array{entity_type:'gallery',entity_id:int,result_key:string,score:int,match_source:string,title_sort:string}> Gallery candidates ordered by relevance, or an empty list.
 */
function public_search_primary_gallery_candidates(
    string $query,
    int $limit,
    ?array $contextGallery = null,
): array {
    $query = public_search_normalize_query($query);
    if (public_search_query_length($query) < 2) {
        return [];
    }

    $limit = max(1, min(30, $limit));
    $candidateLimit = min(90, max($limit * 2, $limit + 8));
    $listedOnly = public_search_listing_requires_listed();
    $tagState = tag_media_type_schema_state();

    $titleRows = public_search_model_primary_gallery_title_rows(
        $query,
        [
            'exact' => public_search_relevance_score('gallery_title_exact'),
            'normalized_exact' => public_search_relevance_score(
                'gallery_title_normalized_exact',
            ),
            'prefix' => public_search_relevance_score('gallery_title_prefix'),
            'substring' => public_search_relevance_score(
                'gallery_title_substring',
            ),
        ],
        $listedOnly,
        $contextGallery,
        $candidateLimit,
    );
    $tagRows = public_search_model_primary_gallery_tag_rows(
        $query,
        [
            'exact' => public_search_relevance_score('gallery_tag_exact'),
            'prefix' => public_search_relevance_score('gallery_tag_prefix'),
            'substring' => public_search_relevance_score(
                'gallery_tag_substring',
            ),
        ],
        $listedOnly,
        $contextGallery,
        $tagState,
        $candidateLimit,
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
            'match_source' => public_search_primary_gallery_match_source(
                $score,
                0,
            ),
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
            'match_source' => public_search_primary_gallery_match_source(
                0,
                $score,
            ),
            'title_sort' => (string) ($row['title'] ?? ''),
        ];
        $existing = $candidates[$id] ?? null;
        if (!is_array($existing) || $score > (int) ($existing['score'] ?? 0)) {
            $candidates[$id] = $candidate;
        }
    }

    $candidates = array_values($candidates);
    usort($candidates, static function (array $left, array $right): int {
        $scoreCompare =
            (int) ($right['score'] ?? 0) <=> (int) ($left['score'] ?? 0);
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }
        $titleCompare = strcasecmp(
            (string) ($left['title_sort'] ?? ''),
            (string) ($right['title_sort'] ?? ''),
        );
        if ($titleCompare !== 0) {
            return $titleCompare;
        }
        return (
            (int) ($left['entity_id'] ?? 0) <=> (int) ($right['entity_id'] ?? 0)
        );
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
function public_search_primary_gallery_match_source(
    int $titleScore,
    int $tagScore,
): string {
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
    $authorizedGalleryRows = [];
    $authorizedGalleryIds = [];
    foreach ($galleryRows as $gallery) {
        $id = (int) ($gallery['id'] ?? 0);
        if (
            $id <= 0
            || !public_search_gallery_visible_to_current_visitor($gallery)
        ) {
            continue;
        }
        $authorizedGalleryRows[] = $gallery;
        $authorizedGalleryIds[] = $id;
    }
    $galleryRows = $authorizedGalleryRows;
    if (
        content_localization_enabled()
        && content_localization_schema_ready('gallery')
    ) {
        $galleryRows = content_localize_entities(
            'gallery',
            $galleryRows,
            translation_active_language(),
        );
    }

    $galleriesById = [];
    foreach ($galleryRows as $gallery) {
        $id = (int) ($gallery['id'] ?? 0);
        if ($id > 0) {
            $galleriesById[$id] = $gallery;
        }
    }

    $tagNamesByGalleryId = [];
    foreach (public_search_model_gallery_tag_name_rows(
        $authorizedGalleryIds,
        tag_media_type_schema_state(),
    ) as $row) {
        $galleryId = (int) ($row['gallery_id'] ?? 0);
        if ($galleryId > 0) {
            $tagNamesByGalleryId[$galleryId] = trim(
                (string) ($row['gallery_tag_names'] ?? ''),
            );
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
            $details[] = t(
                'search.tags_prefix',
                'Tags: {tags}',
                ['tags' => public_search_compact_text($tagNames, 120)],
            );
        }
        $description = public_search_compact_text(
            (string) ($gallery['description'] ?? ''),
            180,
        );
        if ($description !== '') {
            $details[] = $description;
        }

        $results[] = [
            'key' => (string) (
                $candidate['result_key'] ?? public_search_result_key(
                    'gallery',
                    $id,
                )
            ),
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
 * @param ?array{id:int,folder_path:string} $contextGallery Optional branch-limiting gallery.
 * @return list<array{key:string,type:'photo',label:string,title:string,subtitle:string,url:string,rank:int,match_source:string}> Authorized photo cards matching a filename, title, or image tag.
 */
function public_search_media_results(
    string $query,
    int $limit,
    ?array $contextGallery = null,
): array {
    $candidates = public_search_media_image_candidates(
        $query,
        $limit,
        $contextGallery,
    );
    if ($candidates === []) {
        return [];
    }

    return public_search_hydrate_media_image_candidates(
        $candidates,
        $contextGallery,
    );
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
 * @param ?array{id:int,folder_path:string} $contextGallery Optional branch-limiting gallery.
 * @return list<array{entity_type:'photo',entity_id:int,result_key:string,score:int,match_source:string,title_sort:string}> Photo candidates ordered by score, title, and identifier.
 */
function public_search_media_image_candidates(
    string $query,
    int $limit,
    ?array $contextGallery = null,
): array {
    $query = public_search_normalize_query($query);
    if (public_search_query_length($query) < 2) {
        return [];
    }

    $limit = max(1, min(30, $limit));
    $candidateLimit = min(90, max($limit * 3, $limit + 8));
    $candidates = [];

    foreach (public_search_media_image_name_candidates(
        $query,
        $candidateLimit,
        $contextGallery,
    ) as $candidate) {
        $id = (int) ($candidate['entity_id'] ?? 0);
        if ($id > 0) {
            $candidates[$id] = $candidate;
        }
    }
    foreach (public_search_media_image_tag_candidates(
        $query,
        $candidateLimit,
        $contextGallery,
    ) as $candidate) {
        $id = (int) ($candidate['entity_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $existing = $candidates[$id] ?? null;
        if (
            !is_array($existing)
            || (int) ($candidate['score'] ?? 0)
                > (int) ($existing['score'] ?? 0)
        ) {
            $candidates[$id] = $candidate;
        }
    }

    $candidates = array_values($candidates);
    usort($candidates, static function (array $left, array $right): int {
        $scoreCompare =
            (int) ($right['score'] ?? 0) <=> (int) ($left['score'] ?? 0);
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }
        $titleCompare = strcasecmp(
            (string) ($left['title_sort'] ?? ''),
            (string) ($right['title_sort'] ?? ''),
        );
        if ($titleCompare !== 0) {
            return $titleCompare;
        }
        return (
            (int) ($left['entity_id'] ?? 0) <=> (int) ($right['entity_id'] ?? 0)
        );
    });

    return array_slice($candidates, 0, $limit);
}

/**
 * Discover direct filename/title image candidates without tag or deep joins.
 *
 * @param string $query Normalized query value.
 * @param int $limit Maximum number of candidates returned by this source.
 * @param ?array{id:int,folder_path:string} $contextGallery Optional branch-limiting gallery.
 * @return list<array{entity_type:'photo',entity_id:int,result_key:string,score:int,match_source:string,title_sort:string}> Photo candidates matched by filename or saved title.
 */
function public_search_media_image_name_candidates(
    string $query,
    int $limit,
    ?array $contextGallery = null,
): array {
    $listedOnly = public_search_listing_requires_listed();

    $rows = public_search_model_media_image_name_rows(
        $query,
        [
            'exact' => public_search_relevance_score('image_name_exact'),
            'prefix' => public_search_relevance_score('image_name_prefix'),
            'substring' => public_search_relevance_score(
                'image_name_substring',
            ),
        ],
        $listedOnly,
        $contextGallery,
        $limit,
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
            'match_source' =>
                public_search_media_image_name_match_source($score),
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
 * @param ?array{id:int,folder_path:string} $contextGallery Optional branch-limiting gallery.
 * @return list<array{entity_type:'photo',entity_id:int,result_key:string,score:int,match_source:string,title_sort:string}> Photo candidates matched by an assigned image tag.
 */
function public_search_media_image_tag_candidates(
    string $query,
    int $limit,
    ?array $contextGallery = null,
): array {
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
        tag_media_type_schema_state(),
        $limit,
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
            'match_source' =>
                public_search_media_image_tag_match_source($score),
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
 * @param ?array{id:int,folder_path:string} $contextGallery Optional branch-limiting gallery.
 * @return list<array{key:string,type:'photo',label:string,title:string,subtitle:string,url:string,rank:int,match_source:string}> Authorized photo cards in candidate order.
 */
function public_search_hydrate_media_image_candidates(
    array $candidates,
    ?array $contextGallery = null,
): array {
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
        $contextGallery,
    );

    $rawImagesById = [];
    $galleryIds = [];
    foreach ($imageRows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $galleryId = (int) ($row['gallery_id'] ?? 0);
        if ($id <= 0 || $galleryId <= 0) {
            continue;
        }
        $rawImagesById[$id] = $row;
        $galleryIds[$galleryId] = $galleryId;
    }
    if ($rawImagesById === [] || $galleryIds === []) {
        return [];
    }

    $rawGalleryRows = public_search_model_gallery_rows_by_ids(array_values(
        $galleryIds,
    ));
    $rawGalleriesById = [];
    foreach ($rawGalleryRows as $gallery) {
        $galleryId = (int) ($gallery['id'] ?? 0);
        if ($galleryId > 0) {
            $rawGalleriesById[$galleryId] = $gallery;
        }
    }

    $authorizedImageRows = [];
    $authorizedImageIds = [];
    $authorizedGalleryIds = [];
    foreach ($ids as $id) {
        $row = $rawImagesById[$id] ?? null;
        if (!is_array($row)) {
            continue;
        }
        $galleryId = (int) ($row['gallery_id'] ?? 0);
        $gallery = $rawGalleriesById[$galleryId] ?? null;
        if (
            !is_array($gallery)
            || !public_search_image_visible_to_current_visitor($row, $gallery)
        ) {
            continue;
        }
        $authorizedImageRows[] = $row;
        $authorizedImageIds[] = $id;
        $authorizedGalleryIds[$galleryId] = $galleryId;
    }
    if ($authorizedImageRows === []) {
        return [];
    }

    $contentLanguage = translation_active_language();
    if (
        content_localization_enabled()
        && content_localization_schema_ready('image')
    ) {
        $authorizedImageRows = content_localize_entities(
            'image',
            $authorizedImageRows,
            $contentLanguage,
        );
    }
    $imagesById = [];
    foreach ($authorizedImageRows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $imagesById[$id] = $row;
        }
    }

    $galleryRows = [];
    foreach ($authorizedGalleryIds as $galleryId) {
        $gallery = $rawGalleriesById[$galleryId] ?? null;
        if (is_array($gallery)) {
            $galleryRows[] = $gallery;
        }
    }
    if (
        content_localization_enabled()
        && content_localization_schema_ready('gallery')
    ) {
        $galleryRows = content_localize_entities(
            'gallery',
            $galleryRows,
            $contentLanguage,
        );
    }
    $galleriesById = [];
    foreach ($galleryRows as $gallery) {
        $galleryId = (int) ($gallery['id'] ?? 0);
        if ($galleryId > 0) {
            $galleriesById[$galleryId] = $gallery;
        }
    }

    $tagNamesByImageId = [];
    foreach (public_search_model_image_tag_name_rows(
        $authorizedImageIds,
        tag_media_type_schema_state(),
    ) as $row) {
        $imageId = (int) ($row['image_id'] ?? 0);
        if ($imageId > 0) {
            $tagNamesByImageId[$imageId] = trim(
                (string) ($row['image_tag_names'] ?? ''),
            );
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
            $title = (string) (
                $row['filename'] ?? t('search.untitled_photo', 'Untitled photo')
            );
        }
        $details = [t('search.in_gallery', 'In {gallery}', [
            'gallery' => (string) ($gallery['title'] ?? ''),
        ])];
        $tagNames = $tagNamesByImageId[$id] ?? '';
        if ($tagNames !== '') {
            $details[] = t(
                'search.tags_prefix',
                'Tags: {tags}',
                ['tags' => public_search_compact_text($tagNames, 120)],
            );
        }
        $description = public_search_compact_text(
            (string) ($row['description'] ?? ''),
            160,
        );
        if ($description !== '') {
            $details[] = $description;
        }

        $results[] = [
            'key' => (string) (
                $candidate['result_key'] ?? public_search_result_key(
                    'photo',
                    $id,
                )
            ),
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

// Compound phase orchestration must be part of the selective public-search runtime.
/**
 * Convert bounded compound gallery rows into the existing result candidate shape.
 *
 * @param array<int,array<string,mixed>> $rows Model gallery candidate rows.
 * @return array<int,array<string,mixed>> Stable candidate models.
 */
function public_search_compound_gallery_candidates(array $rows): array
{
    $candidates = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $score = (int) ($row['match_score'] ?? 0);
        if ($id < 1 || $score < 1) {
            continue;
        }
        $source = match ($score) {
            1200 => 'gallery_title_exact',
            1100 => 'gallery_title_prefix',
            950 => 'gallery_title_substring',
            800 => 'gallery_tag_substring',
            520 => 'gallery_description',
            default => 'tag_description',
        };
        $candidates[] = public_search_candidate_model(
            'gallery',
            $id,
            $score,
            $source,
            (string) ($row['title'] ?? ''),
        );
    }
    return $candidates;
}

/**
 * Convert bounded compound photo rows into the existing result candidate shape.
 *
 * @param array<int,array<string,mixed>> $rows Model photo candidate rows.
 * @return array<int,array<string,mixed>> Stable candidate models.
 */
function public_search_compound_photo_candidates(array $rows): array
{
    $candidates = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $score = (int) ($row['match_score'] ?? 0);
        if ($id < 1 || $score < 1) {
            continue;
        }
        $source = match ($score) {
            900 => 'image_name_exact',
            820 => 'image_name_prefix',
            720 => 'image_name_substring',
            680 => 'image_tag_substring',
            420 => 'image_description',
            default => 'ai_searchable_metadata',
        };
        $title = trim((string) ($row['title'] ?? ''));
        if ($title === '') {
            $title = (string) ($row['filename'] ?? '');
        }
        $candidates[] = public_search_candidate_model(
            'photo',
            $id,
            $score,
            $source,
            $title,
        );
    }
    return $candidates;
}

/**
 * Execute a single compound search phase and reuse canonical result hydration.
 *
 * Primary phase inspects gallery titles/tags; media inspects image names/tags.
 * Deferred phases progressively add descriptions and enabled translations/AI.
 * All required terms must match the SAME gallery or the SAME photo.
 *
 * @param string $phase Supported public search phase.
 * @param array<int,array<int,string>> $groups OR groups containing AND terms.
 * @param int $limit Maximum returned items.
 * @param ?array{id:int,folder_path:string} $contextGallery Optional branch restriction.
 * @return array<int,array<string,mixed>> Progressive result payload items.
 */
function public_search_compound_phase_results(
    string $phase,
    array $groups,
    int $limit,
    ?array $contextGallery = null,
): array {
    if (!public_search_phase_supported($phase) || $groups === []) {
        return [];
    }

    $limit = max(1, min(30, $limit));
    $candidateLimit = min(90, max($limit * 3, $limit + 8));
    $listedOnly = public_search_listing_requires_listed();
    $language = translation_active_language();
    $tagState = tag_media_type_schema_state();

    if (
        $phase === PUBLIC_SEARCH_PHASE_PRIMARY
        || $phase === PUBLIC_SEARCH_PHASE_DESCRIPTIVE
    ) {
        $translated =
            $phase === PUBLIC_SEARCH_PHASE_DESCRIPTIVE
            && content_localization_enabled()
            && content_localization_schema_ready('gallery');
        $rows = public_search_model_compound_gallery_rows(
            $groups,
            $phase,
            $listedOnly,
            $contextGallery,
            $translated,
            $language,
            $tagState,
            $candidateLimit,
        );
        $candidates = array_slice(
            public_search_compound_gallery_candidates($rows),
            0,
            $limit,
        );
        return public_search_hydrate_primary_gallery_candidates($candidates);
    }

    if ($phase === PUBLIC_SEARCH_PHASE_MEDIA) {
        $rows = public_search_model_compound_image_rows(
            $groups,
            $phase,
            $listedOnly,
            $contextGallery,
            false,
            $language,
            false,
            $tagState,
            $candidateLimit,
        );
        $candidates = array_slice(
            public_search_compound_photo_candidates($rows),
            0,
            $limit,
        );
        return public_search_hydrate_media_image_candidates(
            $candidates,
            $contextGallery,
        );
    }

    $galleryTranslated =
        content_localization_enabled()
        && content_localization_schema_ready('gallery');
    $imageTranslated =
        content_localization_enabled()
        && content_localization_schema_ready('image');
    $aiReady = public_search_ai_metadata_ready();
    $galleryRows = public_search_model_compound_gallery_rows(
        $groups,
        $phase,
        $listedOnly,
        $contextGallery,
        $galleryTranslated,
        $language,
        $tagState,
        $candidateLimit,
    );
    $imageRows = public_search_model_compound_image_rows(
        $groups,
        $phase,
        $listedOnly,
        $contextGallery,
        $imageTranslated,
        $language,
        $aiReady,
        $tagState,
        $candidateLimit,
    );

    $candidates = [];
    public_search_merge_candidate_rows(
        $candidates,
        public_search_compound_gallery_candidates($galleryRows),
    );
    public_search_merge_candidate_rows(
        $candidates,
        public_search_compound_photo_candidates($imageRows),
    );
    $ordered = array_slice(
        public_search_sort_candidate_rows(array_values($candidates)),
        0,
        $limit,
    );
    return public_search_hydrate_deep_candidates($ordered, $contextGallery);
}

/**
 * Combine bounded compound phase results for callers of the non-phased endpoint.
 *
 * The live browser keeps its four asynchronous requests. This compatibility
 * fallback uses the same phase-level eligibility and canonical authorization,
 * then deduplicates stable result keys and retains the strongest score.
 *
 * @param array<int,array<int,string>> $groups Parsed boolean search expression.
 * @param int $limit Maximum returned results.
 * @param ?array{id:int,folder_path:string} $contextGallery Optional gallery-branch restriction.
 * @return array<int,array<string,mixed>> Bounded, merged search results.
 */
function public_search_compound_results(
    array $groups,
    int $limit,
    ?array $contextGallery = null,
): array {
    $limit = max(1, min(30, $limit));
    $byKey = [];

    foreach ([
        PUBLIC_SEARCH_PHASE_PRIMARY,
        PUBLIC_SEARCH_PHASE_MEDIA,
        PUBLIC_SEARCH_PHASE_DESCRIPTIVE,
        PUBLIC_SEARCH_PHASE_DEEP,
    ] as $phase) {
        foreach (public_search_compound_phase_results(
            $phase,
            $groups,
            $limit,
            $contextGallery,
        ) as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $existing = $byKey[$key] ?? null;
            if (
                !is_array($existing)
                || (int) ($row['rank'] ?? 0) > (int) ($existing['rank'] ?? 0)
            ) {
                $byKey[$key] = $row;
            }
        }
    }

    $results = array_values($byKey);
    usort($results, static function (array $left, array $right): int {
        $rank = (int) ($right['rank'] ?? 0) <=> (int) ($left['rank'] ?? 0);
        if ($rank !== 0) {
            return $rank;
        }
        $kind =
            (($left['type'] ?? '') === 'gallery' ? 0 : 1)
            <=> (($right['type'] ?? '') === 'gallery' ? 0 : 1);
        if ($kind !== 0) {
            return $kind;
        }
        $title = strcasecmp(
            (string) ($left['title'] ?? ''),
            (string) ($right['title'] ?? ''),
        );
        return $title !== 0
            ? $title
            : strcmp(
                (string) ($left['key'] ?? ''),
                (string) ($right['key'] ?? ''),
            );
    });
    return array_slice($results, 0, $limit);
}

require_once __DIR__ . '/public_search_progressive/deferred.php';
