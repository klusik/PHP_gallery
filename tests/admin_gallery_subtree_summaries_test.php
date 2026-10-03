<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_gallery_subtree_summaries_test.php
 * Module Type: Regression Test
 * Purpose: Verify pure Admin subtree photo counts and feature aggregates.
 * Responsibilities:
 *   - Include every descendant without multiplying direct image counts
 *   - Distinguish all-on, all-off and mixed subtree feature states
 *   - Preserve row order, direct fields and feature readiness boundaries
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/admin_dashboard.php';

/**
 * Assert a prepared gallery subtree contract.
 * @param bool $condition Expected observation.
 * @param string $message Failure description.
 * @return void
 */
function gallery_subtree_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$rows = [
    ['id' => 1, 'parent_id' => null, 'image_count' => 2, 'view_gps_map_enabled' => true, 'show_filenames' => 0, 'voting_enabled' => 1, 'picture_game_enabled' => 1, 'view_background_source_set' => false],
    ['id' => 2, 'parent_id' => 1, 'image_count' => 3, 'view_gps_map_enabled' => false, 'show_filenames' => 0, 'voting_enabled' => 1, 'picture_game_enabled' => 0, 'view_background_source_set' => true],
    ['id' => 3, 'parent_id' => 2, 'image_count' => 4, 'view_gps_map_enabled' => true, 'show_filenames' => 0, 'voting_enabled' => 1, 'picture_game_enabled' => 0, 'view_background_source_set' => false],
    ['id' => 4, 'parent_id' => 1, 'image_count' => 5, 'view_gps_map_enabled' => true, 'show_filenames' => 0, 'voting_enabled' => 1, 'picture_game_enabled' => 0, 'view_background_source_set' => false],
    ['id' => 5, 'parent_id' => null, 'image_count' => 6, 'view_gps_map_enabled' => false, 'show_filenames' => 1, 'voting_enabled' => 0, 'picture_game_enabled' => 0, 'view_background_source_set' => false],
];
$prepared = \Gallery\Services\admin_dashboard_gallery_subtree_summaries($rows, ['maps', 'filenames', 'voting', 'game', 'background']);
gallery_subtree_assert(array_column($prepared, 'id') === [1, 2, 3, 4, 5], 'Preparation must preserve the canonical displayed order.');
gallery_subtree_assert($prepared[0]['image_count'] === 2 && $prepared[0]['view_subgallery_image_count'] === 12 && $prepared[0]['view_total_image_count'] === 14, 'Root totals must include direct, child and grandchild photos exactly once.');
gallery_subtree_assert($prepared[0]['view_subgallery_count'] === 3 && $prepared[0]['view_subtree_count'] === 4, 'Descendant gallery count must include every hierarchy depth.');
gallery_subtree_assert($prepared[1]['view_subgallery_image_count'] === 4 && $prepared[1]['view_total_image_count'] === 7, 'Nested parent must report its own subtree independently.');
gallery_subtree_assert($prepared[2]['view_subgallery_count'] === 0 && $prepared[2]['view_subgallery_image_count'] === 0 && $prepared[2]['view_total_image_count'] === 4, 'Leaf rows must keep their direct count without invented descendants.');
gallery_subtree_assert($prepared[0]['view_feature_states'] === ['maps' => 'mixed', 'filenames' => 'off', 'voting' => 'on', 'game' => 'mixed', 'background' => 'mixed'], 'Feature states must aggregate the current gallery together with every descendant.');
gallery_subtree_assert($prepared[0]['view_feature_counts']['maps'] === ['on' => 3, 'total' => 4] && $prepared[0]['view_feature_counts']['background'] === ['on' => 1, 'total' => 4], 'Confirmation counts must use the same effective states as the indicators.');
gallery_subtree_assert($prepared[4]['view_feature_counts']['maps'] === ['on' => 0, 'total' => 1] && $prepared[4]['view_total_image_count'] === 6, 'Sibling root must never receive another root subtree counts.');
$limited = \Gallery\Services\admin_dashboard_gallery_subtree_summaries($rows, ['maps', 'unknown']);
gallery_subtree_assert(array_keys($limited[0]['view_feature_states']) === ['maps'], 'Unavailable and unknown features must not trigger reads or appear as false states.');
gallery_subtree_assert(\Gallery\Services\admin_dashboard_gallery_subtree_summaries([], []) === [], 'An unloaded gallery surface must remain empty.');
foreach ($prepared as $index => $row) {
    foreach ($rows[$index] as $key => $value) {
        gallery_subtree_assert($row[$key] === $value, 'Aggregation must preserve every existing policy field.');
    }
}
echo "Admin gallery subtree summaries tests passed.\n";
