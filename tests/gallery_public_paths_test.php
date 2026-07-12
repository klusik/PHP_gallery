<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/gallery_public_paths_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies deterministic gallery slug and hierarchical public-path generation.
 *
 * Responsibilities:
 *   - Cover Czech and Central European transliteration
 *   - Cover decomposed combining accents and invisible Unicode characters
 *   - Cover HTML-entity input copied from browser forms
 *   - Cover hierarchical gallery paths independent from physical folder names
 *   - Cover deterministic sibling collision suffixes
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
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-07-12
 */

declare(strict_types=1);

use function Gallery\Core\slugify;
use function Gallery\Services\gallery_parent_id_assignments_from_folder_paths;
use function Gallery\Services\gallery_public_path_assignments;

require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/services/public_paths.php';

/**
 * Throw when a public-path expectation fails.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual Actual value.
 * @param string $label Assertion label.
 */
function assert_gallery_public_path_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

assert_gallery_public_path_same('testovaci-fotky', slugify('Testovací fotky'), 'Czech gallery title');
assert_gallery_public_path_same('test-nahrani', slugify('test nahrání'), 'Czech upload title');
assert_gallery_public_path_same('prilis-zlutoucky-kun', slugify('Příliš žluťoučký kůň'), 'Czech pangram');
assert_gallery_public_path_same('testovaci-fotky', slugify('Testovac&iacute; fotky'), 'HTML entity input');
assert_gallery_public_path_same('test-nahrani', slugify("test nahra\u{0301}ni\u{0301}"), 'decomposed accents');
assert_gallery_public_path_same('test-nahrani', slugify("test\u{200B} nahrání"), 'zero-width character removal');

$assignments = gallery_public_path_assignments([
    [
        'id' => 1,
        'parent_id' => null,
        'title' => 'Testovací fotky',
        'folder_path' => 'Testovací fotky',
        'slug' => 'legacy-root',
    ],
    [
        'id' => 2,
        'parent_id' => null,
        'title' => 'Test nahrání',
        'folder_path' => 'Testovací fotky/test-nahr-an-i',
        'slug' => 'legacy-child',
    ],
    [
        'id' => 3,
        'parent_id' => 999,
        'title' => 'Test nahrání',
        'folder_path' => 'Testovací fotky/another physical folder',
        'slug' => 'legacy-child-2',
    ],
    [
        'id' => 4,
        'parent_id' => null,
        'title' => '',
        'folder_path' => 'Staré výlety',
        'slug' => 'legacy-empty-title',
    ],
    [
        'id' => 5,
        'parent_id' => null,
        'title' => 'Vnitřní galerie',
        'folder_path' => 'Testovací fotky/test-nahr-an-i/Vnitřní galerie',
        'slug' => 'legacy-grandchild',
    ],
    [
        'id' => 6,
        'parent_id' => null,
        'title' => 'Leaf title',
        'folder_path' => 'Missing parent folder/Leaf folder',
        'slug' => 'legacy-missing-parent',
    ],
]);

assert_gallery_public_path_same('testovaci-fotky', $assignments[1]['slug'], 'root slug');
assert_gallery_public_path_same('testovaci-fotky', $assignments[1]['path'], 'root path');
assert_gallery_public_path_same('test-nahrani', $assignments[2]['slug'], 'child slug');
assert_gallery_public_path_same('testovaci-fotky/test-nahrani', $assignments[2]['path'], 'child path');
assert_gallery_public_path_same('test-nahrani-2', $assignments[3]['slug'], 'duplicate child slug');
assert_gallery_public_path_same('testovaci-fotky/test-nahrani-2', $assignments[3]['path'], 'duplicate child path');
assert_gallery_public_path_same('stare-vylety', $assignments[4]['path'], 'legacy folder fallback');
assert_gallery_public_path_same('testovaci-fotky/test-nahrani/vnitrni-galerie', $assignments[5]['path'], 'grandchild path inferred from folders');
assert_gallery_public_path_same('missing-parent-folder/leaf-title', $assignments[6]['path'], 'missing ancestor row remains in path');

$parentAssignments = gallery_parent_id_assignments_from_folder_paths([
    ['id' => 1, 'parent_id' => null, 'folder_path' => 'Testovací fotky'],
    ['id' => 2, 'parent_id' => null, 'folder_path' => 'Testovací fotky/test-nahr-an-i'],
    ['id' => 3, 'parent_id' => 999, 'folder_path' => 'Testovací fotky/test-nahr-an-i/Vnitřní galerie'],
]);
assert_gallery_public_path_same(null, $parentAssignments[1], 'root parent assignment');
assert_gallery_public_path_same(1, $parentAssignments[2], 'missing child parent inferred from folder path');
assert_gallery_public_path_same(2, $parentAssignments[3], 'stale grandchild parent replaced from folder path');

echo "Gallery public path tests passed.\n";
