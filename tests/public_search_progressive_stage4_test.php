<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/public_search_progressive_stage4_test.php
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */
/**
 * Protect the Stage 4 lightweight media-search contracts and eligibility boundaries.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/models.php';
require_once dirname(__DIR__) . '/app/services/public_search.php';
require_once dirname(__DIR__) . '/app/services/public_search_progressive.php';

use function Gallery\Services\public_search_media_image_name_match_source;
use function Gallery\Services\public_search_media_image_tag_match_source;
use function Gallery\Services\public_search_phase_supported;
use function Gallery\Services\public_search_relevance_score;

/**
 * Assert one progressive-search Stage 4 contract.
 *
 * @param bool $condition Assertion result.
 * @param string $label Failure label.
 */
function public_search_progressive_stage4_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

/**
 * Return one named function body from source text.
 *
 * @param string $source Source text.
 * @param string $name Function name.
 * @param string $nextName Next function name delimiting the body.
 * @return string Extracted function source.
 */
function public_search_progressive_stage4_function_source(string $source, string $name, string $nextName): string
{
    $start = strpos($source, 'function ' . $name . '(');
    $end = strpos($source, 'function ' . $nextName . '(', $start === false ? 0 : $start + 1);
    if ($start === false || $end === false || $end <= $start) {
        return '';
    }
    return substr($source, $start, $end - $start);
}

$root = dirname(__DIR__);
$progressiveSource = (string) file_get_contents($root . '/app/services/public_search_progressive.php');
$modelSource = (string) file_get_contents($root . '/app/models/public_search_progressive.php');
$legacySource = (string) file_get_contents($root . '/app/services/public_search.php');
$browserSource = (string) file_get_contents($root . '/public/assets/gallery-modules/public-home-search.js');

public_search_progressive_stage4_assert(public_search_phase_supported('primary'), 'Primary phase must remain supported.');
public_search_progressive_stage4_assert(public_search_phase_supported('media'), 'Stage 4 must expose the media phase.');
public_search_progressive_stage4_assert(public_search_phase_supported('descriptive'), 'Stage 5 must expose the descriptive phase without regressing Stage 4.');
public_search_progressive_stage4_assert(public_search_phase_supported('deep'), 'Stage 5 must expose the deep phase without regressing Stage 4.');

public_search_progressive_stage4_assert(
    public_search_media_image_name_match_source(public_search_relevance_score('image_name_exact')) === 'image_name_exact',
    'Exact image-name source mismatch.'
);
public_search_progressive_stage4_assert(
    public_search_media_image_name_match_source(public_search_relevance_score('image_name_prefix')) === 'image_name_prefix',
    'Prefix image-name source mismatch.'
);
public_search_progressive_stage4_assert(
    public_search_media_image_tag_match_source(public_search_relevance_score('image_tag_exact')) === 'image_tag_exact',
    'Exact image-tag source mismatch.'
);
public_search_progressive_stage4_assert(
    public_search_media_image_tag_match_source(public_search_relevance_score('image_tag_substring')) === 'image_tag_substring',
    'Substring image-tag source mismatch.'
);

$nameCandidateSource = public_search_progressive_stage4_function_source(
    $modelSource,
    'public_search_model_media_image_name_rows',
    'public_search_model_media_image_tag_rows'
);
public_search_progressive_stage4_assert($nameCandidateSource !== '', 'Media filename/title candidate function source is missing.');
public_search_progressive_stage4_assert(str_contains($nameCandidateSource, 'FROM images i'), 'Media name candidate discovery must start from images.');
public_search_progressive_stage4_assert(str_contains($nameCandidateSource, 'INNER JOIN galleries g ON g.id = i.gallery_id'), 'Media candidates must retain parent gallery authorization context.');
public_search_progressive_stage4_assert(!str_contains($nameCandidateSource, 'image_tags'), 'Filename/title candidate discovery must not join image tags.');
public_search_progressive_stage4_assert(!str_contains($nameCandidateSource, 'description LIKE'), 'Filename/title candidate discovery must not scan descriptions.');
public_search_progressive_stage4_assert(!str_contains($nameCandidateSource, 'image_ai_metadata'), 'Filename/title candidate discovery must not scan AI metadata.');
public_search_progressive_stage4_assert(!str_contains($nameCandidateSource, 'g.title LIKE'), 'Parent gallery titles must not make an image eligible in the media phase.');

$tagCandidateSource = public_search_progressive_stage4_function_source(
    $modelSource,
    'public_search_model_media_image_tag_rows',
    'public_search_model_media_image_rows_by_ids'
);
public_search_progressive_stage4_assert($tagCandidateSource !== '', 'Media image-tag candidate function source is missing.');
public_search_progressive_stage4_assert(str_contains($tagCandidateSource, 'FROM tags t'), 'Image-tag candidate discovery must start from matching tags.');
public_search_progressive_stage4_assert(str_contains($tagCandidateSource, ') tag_match'), 'Image-tag matches must be reduced before joining gallery authorization state.');
public_search_progressive_stage4_assert(str_contains($tagCandidateSource, 'INNER JOIN image_tags it ON it.tag_id = t.id'), 'Image-tag candidate discovery must use the tag-to-image relation index.');
public_search_progressive_stage4_assert(!str_contains($tagCandidateSource, 'gallery_tags'), 'Image-tag candidate discovery must not expand through gallery tags.');
public_search_progressive_stage4_assert(!str_contains($tagCandidateSource, 'description LIKE'), 'Image-tag candidate discovery must not scan descriptions.');
public_search_progressive_stage4_assert(!str_contains($tagCandidateSource, 'image_ai_metadata'), 'Image-tag candidate discovery must not scan AI metadata.');

$hydrateStart = strpos($modelSource, 'function public_search_model_media_image_rows_by_ids(');
$hydrateSource = $hydrateStart === false ? '' : substr($modelSource, $hydrateStart);
public_search_progressive_stage4_assert(str_contains($hydrateSource, 'WHERE i.id IN ($placeholders)'), 'Media hydration must be bounded to selected image IDs.');
public_search_progressive_stage4_assert(!str_contains($progressiveSource, 'FROM images i') && !str_contains($progressiveSource, 'FROM tags t'), 'Stage 4 service orchestration must not own media SQL after MVC extraction.');
public_search_progressive_stage4_assert(str_contains($hydrateSource, "i.visibility = 'public'"), 'Media hydration must recheck public image visibility.');
public_search_progressive_stage4_assert(str_contains($hydrateSource, "GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ')"), 'Media hydration may aggregate image tags only after candidate reduction.');

$legacyImageStart = strpos($legacySource, 'function public_search_image_results(');
$legacyImageEnd = strpos($legacySource, 'function public_search_gallery_from_image_row(', $legacyImageStart === false ? 0 : $legacyImageStart + 1);
$legacyImageSource = ($legacyImageStart !== false && $legacyImageEnd !== false) ? substr($legacySource, $legacyImageStart, $legacyImageEnd - $legacyImageStart) : '';
public_search_progressive_stage4_assert($legacyImageSource !== '', 'Compatibility image-search function source is missing.');
public_search_progressive_stage4_assert(!str_contains($legacyImageSource, 'LEFT JOIN gallery_tags'), 'Compatibility image search must not multiply rows through gallery tags.');
public_search_progressive_stage4_assert(!str_contains($legacyImageSource, 'OR g.title LIKE ?'), 'Parent gallery title must not manufacture photo matches in compatibility search.');
public_search_progressive_stage4_assert(!str_contains($legacyImageSource, 'OR g.description LIKE ?'), 'Parent gallery description must not manufacture photo matches in compatibility search.');
public_search_progressive_stage4_assert(!str_contains($legacyImageSource, 'content_gallery_translation'), 'Translated gallery metadata must not manufacture photo matches in compatibility search.');
public_search_progressive_stage4_assert(!str_contains($legacySource, 'COUNT(DISTINCT public_image.id) AS image_count'), 'Compatibility gallery search must not calculate an unused distinct image count.');

public_search_progressive_stage4_assert(str_contains($browserSource, "buildSearchUrl(query, 'media')"), 'Browser orchestration must request the media phase.');
public_search_progressive_stage4_assert(str_contains($browserSource, 'mediaController = new AbortController()'), 'Media requests must own an abort controller.');
public_search_progressive_stage4_assert(str_contains($browserSource, 'mediaTimer = window.setTimeout'), 'Media requests must be independently scheduled.');
public_search_progressive_stage4_assert(str_contains($browserSource, "data-media-delay-ms") || str_contains($browserSource, "'100'"), 'Media scheduling must have an explicit short delay.');

fwrite(STDOUT, "Progressive public-search Stage 4 checks passed.\n");
