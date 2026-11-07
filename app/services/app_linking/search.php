<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/app_linking/search.php
 * Module Type: Service
 * Purpose: Rank authorized physical galleries for native typeahead.
 * Responsibilities:
 *   - Apply the public query grammar and Admin tree typo tolerance.
 *   - Return bounded gallery identities with display ancestry.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

use OverflowException;
use function Gallery\Core\slugify;
use function Gallery\Models\gallery_model_linked_client_page;

/**
 * Bound the number of rows evaluated for one complete picker search.
 * Type: int. Units: galleries. Scope: authorized native picker catalog.
 * Consumers: app_linking_gallery_search_page.
 * Rationale: 10,000 matches the linked creation catalog budget. The lookahead
 * prevents a partial catalog from being presented as a complete search.
 */
const APP_LINKING_SEARCH_CATALOG_LIMIT = 10000;

/**
 * Bound the query before running the public search parser.
 * Type: int. Units: Unicode characters. Scope: one native picker query.
 * Consumers: cms_app_linking_galleries.
 * Rationale: 120 matches the public search field and bounds term length.
 */
const APP_LINKING_SEARCH_QUERY_LIMIT = 120;

/**
 * Permit fuzzy title matching only on sufficiently descriptive search words.
 * Type: int. Units: Unicode characters. Scope: one unquoted search word.
 * Consumers: app_linking_gallery_picker_results.
 * Rationale: four matches the Admin gallery tree and limits false positives.
 */
const APP_LINKING_SEARCH_FUZZY_MIN = 4;

/**
 * Keep display breadcrumbs short while carrying the actual hierarchy depth.
 * Type: int. Units: parent titles. Scope: one picker row.
 * Consumers: app_linking_gallery_picker_results.
 * Rationale: four nearby ancestors distinguish deeply nested names in a compact row.
 */
const APP_LINKING_SEARCH_ANCESTORS_LIMIT = 4;

/**
 * Rank title matches ahead of descriptions and gallery tags.
 * Type: array<string,int>. Units: relevance tiers. Scope: one matched term.
 * Consumers: app_linking_gallery_picker_results.
 * Rationale: higher tiers favor exact, partial and similar titles in that
 * order. Descriptions precede tag names and descriptions.
 */
const APP_LINKING_SEARCH_RANK = [
    'exact' => 6, 'prefix' => 5, 'title' => 4, 'similar' => 3,
    'description' => 2, 'tag' => 1,
];

/**
 * Read and rank the exact catalog authorized by the current search grant.
 *
 * @param array{grant_id:int,user_id:int,scope:string} $identity Authenticated grant and Admin.
 * @param string $token Exact presented bearer token.
 * @param string $issuer Trusted issuer including its installation path.
 * @param string $query Bounded, normalized public search expression or empty to browse.
 * @param int $afterId Last returned gallery ID, or zero for a fresh query.
 * @return array{galleries:list<array{id:int,parent_id:?int,title:string,depth:int,ancestors:list<string>}>,next_after:?int}|null
 *     Ranked or tree-ordered options, or null when the grant is unavailable.
 * @throws OverflowException When the catalog exceeds the picker search budget.
 */
function app_linking_gallery_search_page(
    array $identity,
    string $token,
    string $issuer,
    string $query,
    int $afterId,
): ?array {
    $hash = app_linking_access_token_hash($token);
    if ($hash === null || !app_linking_scope_allows_gallery_search($identity['scope'])) {
        return null;
    }
    app_linking_require_schema(app_linking_gallery_search_schema_status());
    $mediaType = tag_media_type_schema_state();
    if ($mediaType === 'unknown') {
        throw new \RuntimeException('Gallery search storage is unavailable.');
    }
    $quoted = [];
    $groups = $query === '' ? [] : public_search_parse_groups($query, $quoted);
    if ($query !== '' && $groups === []) {
        return ['galleries' => [], 'next_after' => null];
    }
    $terms = array_values(array_unique(array_merge([], ...$groups)));
    $rows = gallery_model_linked_client_page(
        $identity['grant_id'],
        $identity['user_id'],
        $hash,
        $issuer,
        $identity['scope'],
        0,
        APP_LINKING_SEARCH_CATALOG_LIMIT + 1,
        [
            'terms' => $terms,
            'translated' => $terms !== [] && content_localization_enabled()
                && content_localization_schema_ready('gallery'),
            'media_typed' => $mediaType === 'available',
        ],
    );
    if ($rows === null) {
        return null;
    }
    if (count($rows) > APP_LINKING_SEARCH_CATALOG_LIMIT) {
        throw new OverflowException('Gallery search catalog is too large.');
    }
    return app_linking_gallery_picker_results(
        $rows, $groups, $quoted, $terms, $afterId,
    );
}

/**
 * Rank matching galleries, or browse them in their physical tree order.
 *
 * @param list<array{id:int|string,parent_id:int|string|null,title:string,folder_path?:string,matches?:list<string>}> $rows Compact authorized catalog with per-term match classes.
 * @param list<list<string>> $groups Alternatives of required words or phrases.
 * @param array<int,array<int,true>> $quoted Group and term positions requiring literal matches.
 * @param list<string> $terms Unique terms in the same order as the model's matches.
 * @param int $afterId Last option from the same query, or zero for page one.
 * @return array{galleries:list<array{id:int,parent_id:?int,title:string,depth:int,ancestors:list<string>}>,next_after:?int}
 *     Bounded choice page with non-secret title breadcrumbs.
 */
function app_linking_gallery_picker_results(
    array $rows,
    array $groups,
    array $quoted,
    array $terms,
    int $afterId,
): array {
    $catalog = [];
    $ranked = [];
    $words = [];
    foreach ($terms as $term) {
        $words[$term] = preg_match('/\A[\p{L}\p{M}\p{N}]+\z/uD', $term) === 1
            ? app_linking_picker_word($term) : [];
    }
    foreach ($rows as $row) {
        $gallery = app_linking_gallery_identity($row);
        $id = $gallery['id'];
        $catalog[$id] = $gallery;
        $rank = $groups === [] ? [0, 0] : app_linking_picker_rank(
            $gallery['title'], $row['matches'] ?? [], $groups, $quoted, $terms, $words,
        );
        if ($rank !== null) {
            $ranked[] = [
                'id' => $id, 'rank' => $rank, 'title' => $gallery['title'],
                'branch' => ($row['folder_path'] ?? '') . '/',
            ];
        }
    }
    usort($ranked, static function (array $left, array $right) use ($groups): int {
        $order = $groups === []
            ? strcmp($left['branch'], $right['branch'])
            : (($right['rank'] <=> $left['rank'])
                ?: strnatcasecmp($left['title'], $right['title']));
        return $order ?: ($left['id'] <=> $right['id']);
    });
    $offset = 0;
    if ($afterId > 0) {
        $cursor = array_search($afterId, array_column($ranked, 'id'), true);
        $offset = $cursor === false ? count($ranked) : $cursor + 1;
    }
    $result = [];
    foreach (array_slice($ranked, $offset, APP_LINKING_GALLERY_PAGE_SIZE) as $item) {
        $gallery = $catalog[$item['id']];
        $parents = [];
        $seen = [$gallery['id'] => true];
        $parentId = $gallery['parent_id'];
        while ($parentId !== null && isset($catalog[$parentId]) && !isset($seen[$parentId])) {
            $seen[$parentId] = true;
            if (count($parents) < APP_LINKING_SEARCH_ANCESTORS_LIMIT) {
                $parents[] = $catalog[$parentId]['title'];
            }
            $parentId = $catalog[$parentId]['parent_id'];
        }
        $result[] = $gallery + [
            'depth' => count($seen) - 1,
            'ancestors' => array_reverse($parents),
        ];
    }
    return [
        'galleries' => $result,
        'next_after' => $offset + count($result) < count($ranked)
            ? $result[array_key_last($result)]['id'] : null,
    ];
}

/**
 * Find the strongest alternative that satisfies every one of its terms.
 *
 * @param string $title Current canonical gallery title.
 * @param list<string> $matches Best literal field match for each unique term.
 * @param list<list<string>> $groups Parsed alternatives of required terms.
 * @param array<int,array<int,true>> $quoted Group and term positions requiring literal matches.
 * @param list<string> $terms Unique terms in model projection order.
 * @param array<string,list<string>> $words Pre-folded single-word search terms.
 * @return array{int,int}|null Weakest match tier and aggregate tie break, or null.
 */
function app_linking_picker_rank(
    string $title,
    array $matches,
    array $groups,
    array $quoted,
    array $terms,
    array $words,
): ?array {
    $titleWords = null;
    $literal = [];
    $scores = [];
    foreach ($terms as $index => $term) {
        $score = APP_LINKING_SEARCH_RANK[$matches[$index] ?? ''] ?? 0;
        $literal[$term] = $score;
        $word = $words[$term];
        if ($score < APP_LINKING_SEARCH_RANK['similar']
            && count($word) >= APP_LINKING_SEARCH_FUZZY_MIN) {
            $titleWords ??= array_map(
                app_linking_picker_word(...),
                preg_split('/[^\p{L}\p{M}\p{N}]+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            );
            foreach ($titleWords as $candidate) {
                if (app_linking_picker_word_matches($word, $candidate)) {
                    $score = APP_LINKING_SEARCH_RANK['similar'];
                    break;
                }
            }
        }
        $scores[$term] = $score;
    }
    $best = null;
    foreach ($groups as $index => $group) {
        $groupScores = [];
        foreach ($group as $position => $term) {
            $groupScores[] = isset($quoted[$index][$position])
                ? $literal[$term] : $scores[$term];
        }
        $weakest = min($groupScores);
        $rank = [$weakest, array_sum($groupScores)];
        if ($weakest > 0 && ($best === null || $rank > $best)) {
            $best = $rank;
        }
    }
    return $best;
}

/**
 * Fold a title word to Unicode characters for the Admin tree edit rules.
 *
 * @param string $word Valid Unicode word.
 * @return list<string> Lowercase characters without combining accents.
 */
function app_linking_picker_word(string $word): array
{
    if (class_exists(\Normalizer::class)) {
        $word = \Normalizer::normalize($word, \Normalizer::FORM_D) ?: $word;
    } elseif (preg_match('/\A[\p{Latin}\p{M}\p{N}]+\z/uD', $word) === 1) {
        // Use the existing deterministic Latin folding on hosts without intl.
        $word = slugify($word);
    }
    $word = preg_replace('/\p{M}/u', '', $word) ?? '';
    $word = function_exists('mb_strtolower') ? mb_strtolower($word, 'UTF-8') : strtolower($word);
    return preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

/**
 * Match the same one-edit prefixes as the Admin web gallery tree.
 *
 * @param list<string> $word Folded query word.
 * @param list<string> $candidate Folded gallery title word.
 * @return bool True for a prefix within one insertion, deletion, replacement or adjacent swap.
 */
function app_linking_picker_word_matches(array $word, array $candidate): bool
{
    $length = count($word);
    foreach ([$length - 1, $length, $length + 1] as $size) {
        $prefix = array_slice($candidate, 0, $size);
        $right = count($prefix);
        if (abs($length - $right) > 1) {
            continue;
        }
        $i = 0;
        $j = 0;
        $edited = false;
        while ($i < $length && $j < $right) {
            if ($word[$i] === $prefix[$j]) {
                $i++;
                $j++;
                continue;
            }
            if ($edited) {
                continue 2;
            }
            $edited = true;
            if ($length === $right && $word[$i] === ($prefix[$j + 1] ?? null)
                && ($word[$i + 1] ?? null) === $prefix[$j]) {
                $i += 2;
                $j += 2;
            } elseif ($length > $right) {
                $i++;
            } elseif ($length < $right) {
                $j++;
            } else {
                $i++;
                $j++;
            }
        }
        if (!$edited || ($i === $length && $j === $right)) {
            return true;
        }
    }
    return false;
}
