<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/gallery_revision_runtime_updates_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Ensure every scoped runtime gallery-row UPDATE advances edit_revision in application SQL.
 *
 * Responsibilities:
 *   - Inventory direct gallery UPDATE literals in the runtime model owners
 *   - Cover dynamic gallery field, trash-restore and dependency-cleanup writers
 *   - Reject newly introduced direct gallery writers that lack an explicit increment
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

require_once __DIR__ . '/support/module_source.php';
require_once __DIR__ . '/support/gallery_edit_writer_source.php';

/**
 * Count direct UPDATE galleries SET fragments in executable string literals.
 *
 * Dynamic statements whose table name is concatenated are intentionally counted
 * separately through their owning function contracts below.
 *
 * @param string $source Complete PHP module or function source.
 * @return int Number of direct gallery UPDATE string fragments.
 */
function gallery_revision_direct_update_count(string $source): int
{
    $count = 0;
    $tokenSource = str_starts_with(ltrim($source), '<?php') ? $source : '<?php ' . $source;
    foreach (token_get_all($tokenSource) as $token) {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $count += preg_match_all('/\bUPDATE\s+galleries\s+SET\b/i', $token[1]);
    }
    return $count;
}

/**
 * Normalize a function body for an application-owned revision-expression check.
 *
 * @param string $source Extracted executable function body.
 * @return string Lowercase source without whitespace or identifier quoting.
 */
function gallery_revision_normalized_source(string $source): string
{
    return strtolower((string) preg_replace('/[`\s]+/', '', $source));
}

$root = dirname(__DIR__);
$contracts = [
    'app/models/gallery_edit_concurrency.php' => [
        'gallery_edit_model_reserve' => 1,
    ],
    'app/models/galleries.php' => [
        'gallery_model_update_scalar_for_ids' => 1,
        'gallery_model_update_fields' => 1,
        'gallery_model_clear_background_sources' => 1,
        'gallery_model_set_cover_image_id' => 1,
        'gallery_model_set_cover_image_path' => 1,
        'gallery_model_reset_grid_overrides' => 1,
        'gallery_model_set_thumbnail_bounds' => 1,
    ],
    'app/models/gallery_trash.php' => [
        'gallery_trash_model_set_cover_image' => 1,
        'gallery_trash_model_update_metadata_row' => 0,
    ],
    'app/models/gallery_order.php' => [
        'gallery_order_model_save_tree' => 1,
        'gallery_order_model_save_children' => 1,
    ],
    'app/models/gallery_mutations.php' => [
        'gallery_mutation_model_move_images' => 2,
        'gallery_mutation_model_update_gallery_paths' => 1,
        'gallery_mutation_model_sync_parent_map' => 2,
        'gallery_mutation_model_null_fixed_rows' => 0,
    ],
    'app/models/exif.php' => [
        'exif_model_reset_gallery_gps_overrides' => 1,
    ],
    'app/models/public_paths.php' => [
        'public_path_model_apply_parent_assignments' => 1,
        'public_path_model_apply_gallery_regeneration' => 2,
    ],
    'app/models/picture_manager.php' => [
        'picture_manager_model_copy_rows' => 1,
    ],
    'app/models/picture_game.php' => [
        'picture_game_model_sync_voting_state' => 1,
    ],
];

$contractedDirectCounts = [];

foreach ($contracts as $relativePath => $functionsWithExpectedCounts) {
    $source = module_source($root . '/' . $relativePath);
    gallery_writer_check($source !== '', 'Missing runtime model source: ' . $relativePath);
    $functions = gallery_writer_functions($source);
    $expectedFileCount = array_sum($functionsWithExpectedCounts);
    $contractedDirectCounts[str_replace('\\', '/', $relativePath)] = $expectedFileCount;
    $actualFileCount = gallery_revision_direct_update_count($source);
    gallery_writer_check(
        $actualFileCount === $expectedFileCount,
        'Uncontracted direct gallery UPDATE in ' . $relativePath . '.'
    );
    foreach ($functionsWithExpectedCounts as $functionName => $expectedDirectCount) {
        $function = $functions[$functionName] ?? null;
        gallery_writer_check(is_array($function), 'Missing runtime gallery writer: ' . $functionName);
        gallery_writer_check(
            gallery_revision_direct_update_count($function['body']) === $expectedDirectCount,
            'Gallery UPDATE inventory changed in ' . $functionName . '.'
        );
        gallery_writer_check(
            str_contains(
                gallery_revision_normalized_source($function['body']),
                'edit_revision=edit_revision+1'
            ),
            'Application-owned revision increment missing in ' . $functionName . '.'
        );
    }
}

/**
 * Fail when a direct gallery UPDATE appears in a new model file without an
 * explicit per-function contract above. Dynamic table/assignment builders stay
 * represented by their named zero-direct-count contracts.
 */
$modelFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/app/models', FilesystemIterator::SKIP_DOTS)
);
foreach ($modelFiles as $modelFile) {
    if (!$modelFile->isFile() || strtolower($modelFile->getExtension()) !== 'php') {
        continue;
    }
    $source = file_get_contents($modelFile->getPathname());
    gallery_writer_check(is_string($source), 'Could not read runtime model source: ' . $modelFile->getPathname());
    $directCount = gallery_revision_direct_update_count($source);
    if ($directCount === 0) {
        continue;
    }
    $relativePath = str_replace('\\', '/', substr($modelFile->getPathname(), strlen($root) + 1));
    gallery_writer_check(
        array_key_exists($relativePath, $contractedDirectCounts),
        'Direct gallery UPDATE lacks a revision contract: ' . $relativePath . '.'
    );
    gallery_writer_check(
        $directCount === $contractedDirectCounts[$relativePath],
        'Direct gallery UPDATE inventory changed in ' . $relativePath . '.'
    );
}

echo "Gallery runtime revision UPDATE checks passed.\n";
