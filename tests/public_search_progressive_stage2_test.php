<?php

/**
 * Protect the Stage 1/2 progressive public-search contracts and fast primary phase.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/models.php';
require_once dirname(__DIR__) . '/app/services/public_search.php';
require_once dirname(__DIR__) . '/app/services/public_search_progressive.php';

use function Gallery\Services\public_search_phase_result_limit;
use function Gallery\Services\public_search_phase_supported;
use function Gallery\Services\public_search_prefix_pattern;
use function Gallery\Services\public_search_primary_gallery_match_source;
use function Gallery\Services\public_search_relevance_score;
use function Gallery\Services\public_search_result_key;

/**
 * Assert one progressive-search Stage 1/2 contract.
 *
 * @param bool $condition Assertion result.
 * @param string $label Failure label.
 */
function public_search_progressive_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$root = dirname(__DIR__);
$architectureSource = (string) file_get_contents($root . '/ARCHITECTURE.md');
$progressiveSource = (string) file_get_contents($root . '/app/services/public_search_progressive.php');
$modelSource = (string) file_get_contents($root . '/app/models/public_search_progressive.php');
$controllerSource = (string) file_get_contents($root . '/app/controllers/public_search.php');
$seoGuardSource = (string) file_get_contents($root . '/app/services/seo_request_guard.php');
$servicesSource = (string) file_get_contents($root . '/app/services.php');

public_search_progressive_assert(public_search_phase_supported('primary'), 'Primary search phase must be implemented.');
public_search_progressive_assert(public_search_phase_supported('media'), 'Media search phase must be implemented after Stage 4.');
public_search_progressive_assert(public_search_phase_supported('descriptive'), 'Descriptive phase must be implemented after Stage 5.');
public_search_progressive_assert(public_search_phase_supported('deep'), 'Deep phase must be implemented after Stage 5.');
public_search_progressive_assert(public_search_phase_result_limit('primary', 14) === 8, 'Primary phase must remain bounded to the compact gallery budget.');
public_search_progressive_assert(public_search_phase_result_limit('media', 14) === 8, 'Media phase must remain bounded to the compact photo budget.');
public_search_progressive_assert(public_search_phase_result_limit('descriptive', 14) === 8, 'Descriptive phase must remain bounded.');
public_search_progressive_assert(public_search_phase_result_limit('deep', 14) === 8, 'Deep phase must remain bounded.');
public_search_progressive_assert(public_search_result_key('gallery', 42) === 'gallery:42', 'Gallery result keys must remain stable.');
public_search_progressive_assert(public_search_result_key('photo', 7) === 'photo:7', 'Photo result keys must remain stable.');
public_search_progressive_assert(public_search_prefix_pattern('A_3%') === 'A\\_3\\%%', 'Prefix LIKE escaping must preserve literal wildcard characters.');

$expectedScores = [
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
foreach ($expectedScores as $matchClass => $score) {
    public_search_progressive_assert(public_search_relevance_score($matchClass) === $score, 'Unexpected relevance score for ' . $matchClass . '.');
}
public_search_progressive_assert(public_search_relevance_score('missing') === 0, 'Unknown relevance classes must not gain an accidental score.');

public_search_progressive_assert(public_search_primary_gallery_match_source(1200, 0) === 'gallery_title_exact', 'Exact gallery-title source mismatch.');
public_search_progressive_assert(public_search_primary_gallery_match_source(1100, 1050) === 'gallery_title_prefix', 'Strongest title source must win deterministically.');
public_search_progressive_assert(public_search_primary_gallery_match_source(950, 1050) === 'gallery_tag_exact', 'Exact gallery tag must outrank a title substring.');
public_search_progressive_assert(public_search_primary_gallery_match_source(0, 800) === 'gallery_tag_substring', 'Gallery-tag substring source mismatch.');

$titleModelStart = strpos($modelSource, 'function public_search_model_primary_gallery_title_rows(');
$titleModelEnd = strpos($modelSource, 'function public_search_model_primary_gallery_tag_rows(', $titleModelStart ?: 0);
public_search_progressive_assert($titleModelStart !== false && $titleModelEnd !== false && $titleModelEnd > $titleModelStart, 'Primary gallery-title model query is missing.');
$titleModelSource = substr($modelSource, $titleModelStart, $titleModelEnd - $titleModelStart);
public_search_progressive_assert(str_contains($titleModelSource, 'FROM galleries g'), 'Primary title model must discover galleries directly.');
public_search_progressive_assert(!str_contains($titleModelSource, 'gallery_tags') && !str_contains($titleModelSource, 'FROM images'), 'Primary title model must stay isolated from tags and images.');

$tagModelStart = strpos($modelSource, 'function public_search_model_primary_gallery_tag_rows(');
$tagModelEnd = strpos($modelSource, 'function public_search_model_gallery_rows_by_ids(', $tagModelStart ?: 0);
public_search_progressive_assert($tagModelStart !== false && $tagModelEnd !== false && $tagModelEnd > $tagModelStart, 'Primary gallery-tag model query is missing.');
$tagModelSource = substr($modelSource, $tagModelStart, $tagModelEnd - $tagModelStart);
public_search_progressive_assert(str_contains($tagModelSource, 'INNER JOIN gallery_tags gt ON gt.tag_id = t.id'), 'Primary tag model must use the gallery-tag relation.');
public_search_progressive_assert(!str_contains($tagModelSource, 'FROM images') && !str_contains($tagModelSource, 'image_ai_metadata'), 'Primary tag model must not scan image/deep metadata.');
public_search_progressive_assert(str_contains($titleModelSource, 'LIMIT " . (int) $limit') && str_contains($tagModelSource, 'LIMIT " . (int) $limit'), 'Primary candidate model queries must stay bounded before hydration.');

public_search_progressive_assert(str_contains($modelSource, 'SELECT g.* FROM galleries g WHERE g.id IN ($placeholders)'), 'Primary model hydration must fetch only selected gallery IDs.');
public_search_progressive_assert(str_contains($modelSource, "GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ')"), 'Primary model hydration must aggregate gallery tags only after candidate reduction.');
public_search_progressive_assert(!str_contains($progressiveSource, 'SELECT g.* FROM galleries') && !str_contains($progressiveSource, 'FROM gallery_tags gt'), 'Primary service orchestration must not own SQL after MVC extraction.');
public_search_progressive_assert(str_contains($controllerSource, "'phase_complete' => true"), 'Progressive endpoint must expose phase completion state.');
public_search_progressive_assert(str_contains($controllerSource, 'public_search_phase_results($phase, $query, 14, $contextGallery)'), 'Progressive endpoint must dispatch phase-specific search.');
public_search_progressive_assert(str_contains((string) file_get_contents($root . '/app/services/public_search.php'), "'rank'") && str_contains((string) file_get_contents($root . '/app/services/public_search.php'), "'key' => 'gallery:'"), 'Compatibility results must expose stable merge keys and ranks for Stage 3.');
public_search_progressive_assert(str_contains($seoGuardSource, "'public_search' => ['q', 'context_only', 'gallery_id', 'phase']"), 'SEO request guard must permit the progressive phase parameter.');
public_search_progressive_assert(strpos($servicesSource, "'/models.php'") < strpos($servicesSource, "'/services/public_search.php'"), 'MVC model loader must run before public-search services.');
public_search_progressive_assert(strpos($servicesSource, "'/services/public_search.php'") < strpos($servicesSource, "'/services/public_search_progressive.php'"), 'Progressive search service must load after legacy search helpers.');

public_search_progressive_assert(str_contains($architectureSource, '`public_search_progressive.php`'), 'Permanent progressive-search architecture documentation must remain available after staged work is complete.');
public_search_progressive_assert(!is_file($root . '/TEMP_PROGRESSIVE_PUBLIC_SEARCH_SPEC.md'), 'Temporary progressive-search implementation specifications must not ship in a release.');

fwrite(STDOUT, "Progressive public-search Stage 1/2 checks passed.\n");
