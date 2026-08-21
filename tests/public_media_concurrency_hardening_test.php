<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/public_media_concurrency_hardening_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies bounded public media concurrency hardening without a live database.
 *
 * Responsibilities:
 *   - Prove bulk schema snapshots satisfy repeated table/column capability checks
 *   - Preserve fail-closed fallback behavior when snapshots are reset
 *   - Keep thumbnail metadata writes conditional on missing/stale metadata
 *   - Keep protected media PHP-authorized rather than introducing static bypasses
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
 *   - No live MySQL connection is required.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-08-21
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/services/database_helpers.php';
require_once __DIR__ . '/../app/services/schema_inspection.php';

use const Gallery\Services\SCHEMA_INSPECTION_AVAILABLE;
use const Gallery\Services\SCHEMA_INSPECTION_MISSING;
use function Gallery\Services\db_column_exists;
use function Gallery\Services\db_schema_helper_reset_request_cache;
use function Gallery\Services\db_table_exists;
use function Gallery\Services\schema_inspection_column;
use function Gallery\Services\schema_inspection_column_definition_contains;
use function Gallery\Services\schema_inspection_column_nullable;
use function Gallery\Services\schema_inspection_reset_request_cache;
use function Gallery\Services\schema_inspection_set_query_executor_for_tests;
use function Gallery\Services\schema_inspection_snapshot_column_exists;
use function Gallery\Services\schema_inspection_snapshot_table_exists;
use function Gallery\Services\schema_inspection_store_table_snapshots;
use function Gallery\Services\schema_inspection_table;

/**
 * Fail the regression script with one precise contract message.
 */
function public_media_concurrency_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$queryCount = 0;
schema_inspection_set_query_executor_for_tests(
    static function () use (&$queryCount): bool {
        $queryCount++;
        throw new RuntimeException('Snapshot-backed checks unexpectedly reached the fallback query executor.');
    }
);

schema_inspection_store_table_snapshots(
    ['galleries', 'images', 'image_thumbnail_variants', 'missing_table'],
    [
        ['TABLE_NAME' => 'galleries', 'COLUMN_NAME' => 'url_path_hash', 'COLUMN_TYPE' => 'char(64)', 'IS_NULLABLE' => 'NO'],
        ['TABLE_NAME' => 'galleries', 'COLUMN_NAME' => 'visibility', 'COLUMN_TYPE' => "enum('public','private','unpublished')", 'IS_NULLABLE' => 'NO'],
        ['TABLE_NAME' => 'images', 'COLUMN_NAME' => 'url_slug', 'COLUMN_TYPE' => 'varchar(191)', 'IS_NULLABLE' => 'YES'],
        ['TABLE_NAME' => 'image_thumbnail_variants', 'COLUMN_NAME' => 'image_id', 'COLUMN_TYPE' => 'bigint unsigned', 'IS_NULLABLE' => 'NO'],
    ]
);

public_media_concurrency_assert(schema_inspection_snapshot_table_exists('galleries') === true, 'Bulk snapshot must confirm returned tables.');
public_media_concurrency_assert(schema_inspection_snapshot_table_exists('missing_table') === false, 'Successful bulk snapshot must confirm requested tables with no columns as missing.');
public_media_concurrency_assert(schema_inspection_snapshot_column_exists('galleries', 'url_path_hash') === true, 'Bulk snapshot must confirm returned columns.');
public_media_concurrency_assert(schema_inspection_snapshot_column_exists('galleries', 'missing_column') === false, 'Bulk snapshot must answer absent columns without another query.');
public_media_concurrency_assert((schema_inspection_table('galleries')['state'] ?? '') === SCHEMA_INSPECTION_AVAILABLE, 'Structured table inspection must reuse bulk snapshot state.');
public_media_concurrency_assert((schema_inspection_table('missing_table')['state'] ?? '') === SCHEMA_INSPECTION_MISSING, 'Structured table inspection must preserve confirmed missing state from a successful snapshot.');
public_media_concurrency_assert((schema_inspection_column('images', 'url_slug')['state'] ?? '') === SCHEMA_INSPECTION_AVAILABLE, 'Structured column inspection must reuse bulk snapshot state.');
public_media_concurrency_assert((schema_inspection_column_definition_contains('galleries', 'visibility', 'unpublished')['state'] ?? '') === SCHEMA_INSPECTION_AVAILABLE, 'Column-definition checks must reuse snapshotted COLUMN_TYPE.');
public_media_concurrency_assert((schema_inspection_column_nullable('images', 'url_slug')['state'] ?? '') === SCHEMA_INSPECTION_AVAILABLE, 'Column-nullability checks must reuse snapshotted IS_NULLABLE.');
public_media_concurrency_assert(db_column_exists('galleries', 'url_path_hash'), 'Legacy db_column_exists must reuse the bulk snapshot.');
public_media_concurrency_assert(db_table_exists('image_thumbnail_variants'), 'Legacy db_table_exists must reuse the bulk snapshot.');
public_media_concurrency_assert($queryCount === 0, 'Snapshot-backed schema checks must execute zero per-object metadata queries.');

schema_inspection_reset_request_cache();
db_schema_helper_reset_request_cache();
$fallbackCount = 0;
schema_inspection_set_query_executor_for_tests(
    static function () use (&$fallbackCount): bool {
        $fallbackCount++;
        return true;
    }
);
public_media_concurrency_assert((schema_inspection_column('images', 'url_slug')['state'] ?? '') === SCHEMA_INSPECTION_AVAILABLE, 'Reset snapshots must fall back to the existing object inspection path.');
public_media_concurrency_assert($fallbackCount === 1, 'Snapshot reset must restore exactly one fallback inspection query for one fresh capability check.');

$thumbnailSource = (string) file_get_contents(__DIR__ . '/../app/services/thumbnail_generation.php');
$ensureStart = strpos($thumbnailSource, 'function thumbnail_ensure_image_thumbnail_variant_file(');
$ensureEnd = strpos($thumbnailSource, 'function image_ids_for_galleries(', $ensureStart === false ? 0 : $ensureStart);
$ensureSource = $ensureStart !== false ? substr($thumbnailSource, $ensureStart, $ensureEnd !== false ? $ensureEnd - $ensureStart : null) : '';
public_media_concurrency_assert($ensureSource !== '', 'Thumbnail direct-view resolver must remain present.');
$metadataRead = strpos($ensureSource, 'thumbnail_metadata_has_renderable_variant');
$metadataWrite = strpos($ensureSource, 'thumbnail_metadata_record_file');
public_media_concurrency_assert(
    $metadataRead !== false && $metadataWrite !== false && $metadataRead < $metadataWrite
        && str_contains($ensureSource, 'if (!$metadataCurrent'),
    'Valid thumbnail delivery must verify current metadata before performing the legacy repair upsert.'
);

$mediaControllerSource = (string) file_get_contents(__DIR__ . '/../app/controllers/public_media.php');
public_media_concurrency_assert(
    str_contains($mediaControllerSource, 'resolve_public_gallery_path')
        && str_contains($mediaControllerSource, 'public_image_visible_to_current_visitor')
        && str_contains($mediaControllerSource, 'public_media_needs_private_cache'),
    'Protected thumbnail/media delivery must remain PHP-authorized with existing visibility/access/cache policy checks.'
);

schema_inspection_set_query_executor_for_tests(null);
fwrite(STDOUT, "public media concurrency hardening: OK\n");
