<?php

/**
 * Project: PHP Gallery
 * Module Type: Regression Test
 * Purpose: Protect deferred descriptive and deep-search work.
 * Responsibilities:
 *   - Verify phase separation and browser scheduling of expensive follow-up search.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/public_search_progressive_stage5_test.php
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
 * Protect the Stage 5 descriptive/deep search split and deferred browser scheduling.
 */

declare(strict_types=1);

require_once __DIR__ . '/support/module_source.php';
require_once dirname(__DIR__) . '/app/models.php';
require_once dirname(__DIR__) . '/app/services/public_search.php';
require_once dirname(__DIR__) . '/app/services/public_search_progressive.php';

use function Gallery\Services\public_search_phase_result_limit;
use function Gallery\Services\public_search_phase_supported;

/**
 * Assert one progressive-search Stage 5 contract.
 *
 * @param bool $condition Assertion result.
 * @param string $label Failure label.
 */
function public_search_progressive_stage5_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

/**
 * Return one named function body from concatenated module source.
 *
 * @param string $source Module source.
 * @param string $name Function name.
 * @param string $nextName Next function delimiting the body.
 * @return string Extracted function source.
 */
function public_search_progressive_stage5_function_source(string $source, string $name, string $nextName): string
{
    $start = strpos($source, 'function ' . $name . '(');
    $end = strpos($source, 'function ' . $nextName . '(', $start === false ? 0 : $start + 1);
    if ($start === false || $end === false || $end <= $start) {
        return '';
    }
    return substr($source, $start, $end - $start);
}

$root = dirname(__DIR__);
$progressiveSource = module_source($root . '/app/services/public_search_progressive.php');
$modelSource = module_source($root . '/app/models/public_search_progressive.php');
$browserSource = (string) file_get_contents($root . '/public/assets/gallery-modules/public-home-search.js');
$viewSource = (string) file_get_contents($root . '/app/views/public_search.php');
$controllerSource = (string) file_get_contents($root . '/app/controllers/public_search.php');

public_search_progressive_stage5_assert(public_search_phase_supported('descriptive'), 'Stage 5 must expose the descriptive phase.');
public_search_progressive_stage5_assert(public_search_phase_supported('deep'), 'Stage 5 must expose the deep phase.');
public_search_progressive_stage5_assert(public_search_phase_result_limit('descriptive', 14) === 8, 'Descriptive result budget must remain bounded.');
public_search_progressive_stage5_assert(public_search_phase_result_limit('deep', 14) === 8, 'Deep result budget must remain bounded.');

$descriptiveSource = public_search_progressive_stage5_function_source(
    $progressiveSource,
    'public_search_descriptive_gallery_candidates',
    'public_search_descriptive_gallery_description_candidates'
);
public_search_progressive_stage5_assert($descriptiveSource !== '', 'Descriptive gallery candidate orchestrator is missing.');
public_search_progressive_stage5_assert(str_contains($descriptiveSource, 'public_search_descriptive_gallery_description_candidates'), 'Descriptive phase must search canonical gallery descriptions.');
public_search_progressive_stage5_assert(str_contains($descriptiveSource, 'public_search_descriptive_gallery_tag_description_candidates'), 'Descriptive phase must search gallery-tag descriptions.');
public_search_progressive_stage5_assert(str_contains($descriptiveSource, 'public_search_descriptive_gallery_translated_title_candidates'), 'Descriptive phase must preserve translated gallery-title discovery.');
public_search_progressive_stage5_assert(!str_contains($descriptiveSource, 'image_ai_metadata') && !str_contains($descriptiveSource, 'FROM images'), 'Descriptive gallery orchestration must not scan image/AI tables.');

$deepImageDescriptionSource = public_search_progressive_stage5_function_source(
    $modelSource,
    'public_search_model_image_description_rows',
    'public_search_model_image_tag_description_rows'
);
public_search_progressive_stage5_assert(str_contains($deepImageDescriptionSource, 'FROM images i'), 'Deep image-description model must start from images.');
public_search_progressive_stage5_assert(str_contains($deepImageDescriptionSource, 'i.description LIKE ?'), 'Deep image-description model must search canonical descriptions.');
public_search_progressive_stage5_assert(!str_contains($deepImageDescriptionSource, 'image_ai_metadata'), 'Canonical image-description model must stay independent from AI metadata.');

$deepTranslationServiceSource = public_search_progressive_stage5_function_source(
    $progressiveSource,
    'public_search_deep_image_translation_candidates',
    'public_search_deep_image_ai_candidates'
);
$deepTranslationModelSource = public_search_progressive_stage5_function_source(
    $modelSource,
    'public_search_model_image_translation_rows',
    'public_search_model_image_ai_rows'
);
public_search_progressive_stage5_assert(str_contains($deepTranslationServiceSource, "content_localization_schema_ready('image')"), 'Deep translation service must honor localization schema readiness.');
public_search_progressive_stage5_assert(str_contains($deepTranslationModelSource, 'FROM image_translations tr'), 'Deep translation model must use the translation table directly.');
public_search_progressive_stage5_assert(str_contains($deepTranslationModelSource, 'tr.title LIKE ?') && str_contains($deepTranslationModelSource, 'tr.description LIKE ?'), 'Deep translation model must preserve title and description discovery.');

$deepAiServiceSource = public_search_progressive_stage5_function_source(
    $progressiveSource,
    'public_search_deep_image_ai_candidates',
    'public_search_deep_image_rows_to_candidates'
);
$deepAiModelStart = strpos($modelSource, 'function public_search_model_image_ai_rows(');
$deepAiModelSource = $deepAiModelStart === false ? '' : substr($modelSource, $deepAiModelStart);
public_search_progressive_stage5_assert(str_contains($deepAiServiceSource, 'public_search_ai_metadata_ready()'), 'Deep AI service must honor the canonical AI capability/schema readiness helper.');
public_search_progressive_stage5_assert(str_contains($deepAiModelSource, 'FROM image_ai_metadata m'), 'AI metadata must be isolated in the deep model.');
public_search_progressive_stage5_assert(str_contains($deepAiModelSource, 'm.searchable_text LIKE ?'), 'Deep AI model must search AI searchable text.');

public_search_progressive_stage5_assert(!str_contains($progressiveSource, 'db()->') && !str_contains($progressiveSource, '->prepare('), 'Progressive service module must delegate deferred SQL to the model layer.');

$runSearchStart = strpos($browserSource, 'const runSearch = () => {');
$runSearchEnd = strpos($browserSource, "input.addEventListener('input'", $runSearchStart === false ? 0 : $runSearchStart + 1);
$runSearchSource = ($runSearchStart !== false && $runSearchEnd !== false) ? substr($browserSource, $runSearchStart, $runSearchEnd - $runSearchStart) : '';
public_search_progressive_stage5_assert(str_contains($browserSource, "buildSearchUrl(query, 'descriptive')"), 'Browser must request the descriptive phase.');
public_search_progressive_stage5_assert(str_contains($browserSource, "buildSearchUrl(query, 'deep')"), 'Browser must request the deep phase.');
public_search_progressive_stage5_assert(str_contains($runSearchSource, 'descriptiveTimer = window.setTimeout'), 'Descriptive work must be deferred independently.');
public_search_progressive_stage5_assert(str_contains($runSearchSource, 'deepTimer = window.setTimeout'), 'Deep work must be deferred independently.');
public_search_progressive_stage5_assert(!str_contains($runSearchSource, 'requestCompatibilitySearch(generation, query)'), 'Normal Stage 5 live search must no longer schedule the monolithic compatibility query.');
public_search_progressive_stage5_assert(str_contains($browserSource, "pendingPhases = new Set(['primary', 'media', 'descriptive', 'deep'])"), 'Browser completion state must track all progressive phases independently.');
public_search_progressive_stage5_assert(str_contains($browserSource, "settlePhase('deep', false)"), 'Deep failure must settle independently without replacing earlier results.');
public_search_progressive_stage5_assert(str_contains($controllerSource, "'descriptive_delay_ms' => 300") && str_contains($viewSource, "data-descriptive-delay-ms=\"' . (int) (\$viewModel['descriptive_delay_ms'] ?? 300)"), 'Controller view model and view must expose an explicit descriptive delay.');
public_search_progressive_stage5_assert(str_contains($controllerSource, "'deep_delay_ms' => 550") && str_contains($viewSource, "data-deep-delay-ms=\"' . (int) (\$viewModel['deep_delay_ms'] ?? 550)"), 'Controller view model and view must expose an explicit deep delay.');

fwrite(STDOUT, "Progressive public-search Stage 5 checks passed.\n");
