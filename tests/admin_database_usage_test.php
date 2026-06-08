<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_database_usage_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies pure helper logic for Admin database usage statistics.
 *
 * Responsibilities:
 *   - Cover information_schema row normalization
 *   - Cover gallery/content table grouping
 *   - Cover total, data, index, and percentage aggregation
 *   - Keep the test database-free for plain PHP execution
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
 *   2026-06-08
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/services/admin_database_usage.php';

/**
 * Throw when a database-usage expectation fails.
 */
function assert_admin_database_usage_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$galleryLookup = array_fill_keys(admin_database_usage_gallery_table_names(), true);
$normalized = admin_database_usage_normalize_table_row([
    'TABLE_NAME' => 'images',
    'TABLE_ROWS' => '42',
    'DATA_LENGTH' => '1024',
    'INDEX_LENGTH' => '512',
    'ENGINE' => 'InnoDB',
], $galleryLookup);

assert_admin_database_usage_same('images', (string) ($normalized['table_name'] ?? ''), 'table name is normalized');
assert_admin_database_usage_same(42, (int) ($normalized['count'] ?? 0), 'table row estimate is normalized');
assert_admin_database_usage_same(1536, (int) ($normalized['bytes'] ?? 0), 'data and index bytes are combined');
assert_admin_database_usage_same(true, (bool) ($normalized['is_gallery_table'] ?? false), 'images table is classified as gallery data');

$summary = admin_database_usage_build_summary_from_rows('gallery_cms', [
    ['table_name' => 'images', 'table_rows' => 20, 'data_bytes' => 4000, 'index_bytes' => 1000, 'engine' => 'InnoDB'],
    ['table_name' => 'galleries', 'table_rows' => 5, 'data_bytes' => 700, 'index_bytes' => 300, 'engine' => 'InnoDB'],
    ['table_name' => 'admin_logs', 'table_rows' => 100, 'data_bytes' => 6000, 'index_bytes' => 2000, 'engine' => 'InnoDB'],
], admin_database_usage_gallery_table_names());

assert_admin_database_usage_same(true, (bool) ($summary['available'] ?? false), 'summary is available');
assert_admin_database_usage_same('gallery_cms', (string) ($summary['database_name'] ?? ''), 'database name is stored');
assert_admin_database_usage_same(14000, (int) ($summary['total_bytes'] ?? 0), 'total database bytes are aggregated');
assert_admin_database_usage_same(10700, (int) ($summary['data_bytes'] ?? 0), 'data bytes are aggregated');
assert_admin_database_usage_same(3300, (int) ($summary['index_bytes'] ?? 0), 'index bytes are aggregated');
assert_admin_database_usage_same(6000, (int) ($summary['gallery_bytes'] ?? 0), 'gallery database bytes are aggregated');
assert_admin_database_usage_same(8000, (int) ($summary['operational_bytes'] ?? 0), 'operational database bytes are aggregated');
assert_admin_database_usage_same(42.9, (float) ($summary['gallery_percent_of_database'] ?? 0.0), 'gallery percentage is calculated');
assert_admin_database_usage_same(125, (int) ($summary['table_rows_estimate'] ?? 0), 'estimated rows are aggregated');
assert_admin_database_usage_same(25, (int) ($summary['gallery_rows_estimate'] ?? 0), 'gallery estimated rows are aggregated');
assert_admin_database_usage_same('admin_logs', (string) ($summary['largest_table_name'] ?? ''), 'largest table is detected');
assert_admin_database_usage_same(2, count($summary['gallery_table_rows'] ?? []), 'only gallery tables are returned in gallery rows');
assert_admin_database_usage_same('admin_logs', (string) (($summary['table_rows'][0]['table_name'] ?? '')), 'all table rows sort by bytes');
assert_admin_database_usage_same('images', (string) (($summary['gallery_table_rows'][0]['table_name'] ?? '')), 'gallery table rows sort by bytes');
assert_admin_database_usage_same(83.3, (float) (($summary['gallery_table_rows'][0]['percent'] ?? 0.0)), 'gallery chart percentage uses gallery total');

$unavailable = admin_database_usage_unavailable('denied');
assert_admin_database_usage_same(false, (bool) ($unavailable['available'] ?? true), 'unavailable state is explicit');
assert_admin_database_usage_same('denied', (string) ($unavailable['error'] ?? ''), 'unavailable reason is preserved');

$limitedRows = admin_database_usage_finalize_table_rows([
    ['label' => 'a', 'bytes' => 1],
    ['label' => 'b', 'bytes' => 2],
    ['label' => 'c', 'bytes' => 3],
], 6, 2);
assert_admin_database_usage_same(2, count($limitedRows), 'table rows are limited');
assert_admin_database_usage_same('c', (string) ($limitedRows[0]['label'] ?? ''), 'limited rows keep largest values');
assert_admin_database_usage_same(50.0, (float) ($limitedRows[0]['percent'] ?? 0.0), 'percentage uses full total before limiting');

echo "Admin database usage helper tests passed.\n";
