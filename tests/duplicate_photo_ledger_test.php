<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/duplicate_photo_ledger_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies persistent Duplicate Photo Detector ledger semantics and schema contracts.
 *
 * Responsibilities:
 *   - Verify canonical image-pair keys are deterministic
 *   - Verify pair and exact-gallery rules filter independently
 *   - Verify parent and child gallery ids do not implicitly cascade
 *   - Verify the migration defines administrator-owned ledger tables with cascading cleanup
 *   - Verify the service writes only ledger tables with parameterized SQL
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
 */

declare(strict_types=1);

use function Gallery\Services\duplicate_photo_ledger_empty_snapshot;
use function Gallery\Services\duplicate_photo_ledger_ignores_pair;
use function Gallery\Services\duplicate_photo_ledger_normalize_pair;
use function Gallery\Services\duplicate_photo_ledger_pair_key;

require_once __DIR__ . '/../app/services/duplicate_photo_ledger.php';

/**
 * Throw when a ledger expectation differs from the actual value.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual Actual value.
 * @param string $label Assertion label.
 */
function assert_duplicate_ledger_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/**
 * Throw when a ledger condition is false.
 *
 * @param bool $condition Condition to verify.
 * @param string $label Assertion label.
 */
function assert_duplicate_ledger_true(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label . ' expected true.');
    }
}

assert_duplicate_ledger_same([4, 9], duplicate_photo_ledger_normalize_pair(9, 4), 'pair ids normalize into ascending canonical order');
assert_duplicate_ledger_same('4:9', duplicate_photo_ledger_pair_key(9, 4), 'pair key is independent of left/right display order');

$pairLedger = duplicate_photo_ledger_empty_snapshot();
$pairLedger['pairs']['4:9'] = true;
$pairLedger['pair_count'] = 1;
assert_duplicate_ledger_true(duplicate_photo_ledger_ignores_pair($pairLedger, 9, 4, 100, 200), 'canonical ledger pair suppresses the same pair in reversed display order');
assert_duplicate_ledger_same(false, duplicate_photo_ledger_ignores_pair($pairLedger, 9, 5, 100, 200), 'pair ledger does not suppress an unrelated image relationship');

$galleryLedger = duplicate_photo_ledger_empty_snapshot();
$galleryLedger['galleries'][100] = true;
$galleryLedger['gallery_count'] = 1;
assert_duplicate_ledger_true(duplicate_photo_ledger_ignores_pair($galleryLedger, 1, 2, 100, 101), 'exact gallery ledger rule suppresses a pair containing that gallery');
assert_duplicate_ledger_same(false, duplicate_photo_ledger_ignores_pair($galleryLedger, 3, 4, 101, 102), 'parent gallery ledger rule does not cascade to child gallery ids');
assert_duplicate_ledger_true(duplicate_photo_ledger_ignores_pair($galleryLedger, 5, 6, 103, 100), 'gallery rule applies independently whether the ledgered gallery is displayed left or right');

$migrationSource = file_get_contents(__DIR__ . '/../database/migrations/202608080001_duplicate_photo_ledger.php');
if (!is_string($migrationSource)) {
    throw new RuntimeException('Could not read Duplicate Photo Detector ledger migration source.');
}
assert_duplicate_ledger_true(str_contains($migrationSource, 'CREATE TABLE IF NOT EXISTS duplicate_photo_ledger_pairs'), 'migration creates the ignored-pair ledger table');
assert_duplicate_ledger_true(str_contains($migrationSource, 'CREATE TABLE IF NOT EXISTS duplicate_photo_ledger_galleries'), 'migration creates the exact-gallery ledger table');
assert_duplicate_ledger_true(str_contains($migrationSource, 'PRIMARY KEY (user_id, image_id_low, image_id_high)'), 'pair ledger uniqueness is scoped per administrator and canonical image pair');
assert_duplicate_ledger_true(str_contains($migrationSource, 'PRIMARY KEY (user_id, gallery_id)'), 'gallery ledger uniqueness is scoped per administrator and exact gallery');
assert_duplicate_ledger_true(substr_count($migrationSource, 'REFERENCES users(id) ON DELETE CASCADE') >= 2, 'ledger rows are removed when their administrator user is removed');
assert_duplicate_ledger_true(str_contains($migrationSource, 'REFERENCES images(id) ON DELETE CASCADE'), 'pair ledger rows are removed automatically when referenced images are deleted');
assert_duplicate_ledger_true(str_contains($migrationSource, 'REFERENCES galleries(id) ON DELETE CASCADE'), 'gallery ledger rows are removed automatically when referenced galleries are deleted');

$serviceSource = file_get_contents(__DIR__ . '/../app/services/duplicate_photo_ledger.php');
if (!is_string($serviceSource)) {
    throw new RuntimeException('Could not read Duplicate Photo Detector ledger service source.');
}
assert_duplicate_ledger_true(str_contains($serviceSource, "db()->prepare("), 'ledger writes and reads use prepared statements');
assert_duplicate_ledger_true(str_contains($serviceSource, 'INSERT IGNORE INTO duplicate_photo_ledger_pairs'), 'pair decisions persist only to the dedicated ledger table');
assert_duplicate_ledger_true(str_contains($serviceSource, 'INSERT IGNORE INTO duplicate_photo_ledger_galleries'), 'gallery decisions persist only to the dedicated ledger table');
assert_duplicate_ledger_same(false, preg_match('/\\b(?:UPDATE|DELETE\\s+FROM|INSERT\\s+(?:IGNORE\\s+)?INTO|REPLACE\\s+INTO)\\s+(?:images|galleries)\\b/i', $serviceSource) === 1, 'ledger service does not mutate image or gallery records');
assert_duplicate_ledger_true(str_contains($serviceSource, 'DELETE FROM duplicate_photo_ledger_pairs WHERE user_id = ?'), 'clear ledger deletes only the current administrator pair rules');
assert_duplicate_ledger_true(str_contains($serviceSource, 'DELETE FROM duplicate_photo_ledger_galleries WHERE user_id = ?'), 'clear ledger deletes only the current administrator gallery rules');

$maintenanceSource = file_get_contents(__DIR__ . '/../app/services/database_maintenance.php');
if (!is_string($maintenanceSource)) {
    throw new RuntimeException('Could not read database maintenance policies for ledger protection assertions.');
}
assert_duplicate_ledger_true(str_contains($maintenanceSource, "'duplicate_photo_ledger_pairs' => \$protected"), 'database maintenance explicitly protects pair-ledger workflow state');
assert_duplicate_ledger_true(str_contains($maintenanceSource, "'duplicate_photo_ledger_galleries' => \$protected"), 'database maintenance explicitly protects gallery-ledger workflow state');

echo "Duplicate photo ledger tests passed.\n";
