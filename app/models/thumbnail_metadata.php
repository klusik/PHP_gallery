<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/thumbnail_metadata.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns database persistence and read queries for durable thumbnail metadata.
 *
 * Responsibilities:
 *   - Read compact thumbnail variant rows for public rendering caches
 *   - Read allowlisted thumbnail-related table schemas
 *   - Create, update, and delete thumbnail metadata rows
 *   - Persist compact source-image metadata fields
 *   - Return bounded storage diagnostics for Admin telemetry
 *   - Keep SQL and PDO calls out of thumbnail service orchestration
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
 *   - Dynamic identifiers are accepted only through fixed internal allowlists.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use InvalidArgumentException;
use function Gallery\Core\db;

/**
 * Return thumbnail metadata rows for one bounded render batch.
 *
 * @param array<int,int> $imageIds Image identifiers.
 * @param array<int,int> $sizes Allowed thumbnail sizes.
 * @param array<int,string> $formats Allowed derivative formats.
 * @return array<int,array<string,mixed>>
 */
function thumbnail_metadata_model_renderable_rows(array $imageIds, array $sizes, array $formats): array
{
    if ($imageIds === [] || $sizes === [] || $formats === []) {
        return [];
    }

    $imagePlaceholders = implode(',', array_fill(0, count($imageIds), '?'));
    $sizePlaceholders = implode(',', array_fill(0, count($sizes), '?'));
    $formatPlaceholders = implode(',', array_fill(0, count($formats), '?'));
    $stmt = db()->prepare(
        "SELECT * FROM image_thumbnail_variants WHERE image_id IN ($imagePlaceholders)"
        . " AND size_px IN ($sizePlaceholders) AND format IN ($formatPlaceholders)"
        . ' ORDER BY image_id, size_px, format'
    );
    $stmt->execute(array_merge($imageIds, $sizes, $formats));
    return $stmt->fetchAll() ?: [];
}

/**
 * Return column names for one explicitly supported thumbnail table.
 *
 * @return array<string,bool>
 */
function thumbnail_metadata_model_table_columns(string $table): array
{
    $allowedTables = ['image_thumbnail_variants', 'images'];
    if (!in_array($table, $allowedTables, true)) {
        return [];
    }

    $stmt = db()->query('SHOW COLUMNS FROM `' . $table . '`');
    $columns = [];
    foreach ($stmt->fetchAll() as $column) {
        $field = (string) ($column['Field'] ?? '');
        if ($field !== '') {
            $columns[$field] = true;
        }
    }
    return $columns;
}

/** Return whether at least one durable thumbnail row exists for an image. */
function thumbnail_metadata_model_image_has_rows(int $imageId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM image_thumbnail_variants WHERE image_id = ? LIMIT 1');
    $stmt->execute([$imageId]);
    return (bool) $stmt->fetchColumn();
}

/** Delete one concrete thumbnail variant row. */
function thumbnail_metadata_model_delete_variant(int $imageId, int $size, string $format): void
{
    $stmt = db()->prepare('DELETE FROM image_thumbnail_variants WHERE image_id = ? AND size_px = ? AND format = ?');
    $stmt->execute([$imageId, $size, $format]);
}

/** Delete every thumbnail variant row for an image. */
function thumbnail_metadata_model_delete_image_variants(int $imageId): void
{
    $stmt = db()->prepare('DELETE FROM image_thumbnail_variants WHERE image_id = ?');
    $stmt->execute([$imageId]);
}

/**
 * Update compact source-image facts using a fixed allowlist of optional columns.
 *
 * @param array<string,mixed> $fields Field values keyed by column name.
 */
function thumbnail_metadata_model_update_image_source(int $imageId, array $fields): void
{
    $allowedColumns = [
        'display_width',
        'display_height',
        'exif_orientation',
        'thumbnail_metadata_refreshed_at',
    ];
    $filtered = [];
    foreach ($fields as $column => $value) {
        if (!in_array($column, $allowedColumns, true)) {
            throw new InvalidArgumentException('Unsupported thumbnail image source column: ' . $column);
        }
        $filtered[$column] = $value;
    }
    if ($filtered === []) {
        return;
    }

    $assignments = [];
    $params = [];
    foreach ($filtered as $column => $value) {
        $assignments[] = '`' . $column . '` = ?';
        $params[] = $value;
    }
    $params[] = $imageId;
    $stmt = db()->prepare('UPDATE images SET ' . implode(', ', $assignments) . ' WHERE id = ?');
    $stmt->execute($params);
}

/**
 * Insert or refresh one compact thumbnail variant row.
 *
 * @param array<string,mixed> $fields Variant values keyed by column name.
 */
function thumbnail_metadata_model_upsert_variant(array $fields): void
{
    $allowedColumns = [
        'image_id',
        'gallery_id',
        'size_px',
        'format',
        'derivative_version',
        'thumbnail_rel_path',
        'width',
        'height',
        'file_size',
        'modified_at',
        'status',
        'status_reason',
        'checked_at',
        'created_at',
        'updated_at',
    ];
    $requiredColumns = ['image_id', 'size_px', 'format', 'created_at'];
    foreach ($fields as $column => $_value) {
        if (!in_array($column, $allowedColumns, true)) {
            throw new InvalidArgumentException('Unsupported thumbnail variant column: ' . $column);
        }
    }
    foreach ($requiredColumns as $column) {
        if (!array_key_exists($column, $fields)) {
            throw new InvalidArgumentException('Missing required thumbnail variant column: ' . $column);
        }
    }

    $columnNames = array_keys($fields);
    $insertColumns = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columnNames));
    $placeholders = implode(', ', array_fill(0, count($columnNames), '?'));
    $updateColumns = array_values(array_filter(
        $columnNames,
        static fn (string $column): bool => !in_array($column, ['image_id', 'size_px', 'format', 'created_at'], true)
    ));
    $updates = implode(', ', array_map(
        static fn (string $column): string => '`' . $column . '` = VALUES(`' . $column . '`)',
        $updateColumns
    ));

    $stmt = db()->prepare(
        'INSERT INTO image_thumbnail_variants (' . $insertColumns . ') VALUES (' . $placeholders . ')'
        . ' ON DUPLICATE KEY UPDATE ' . $updates
    );
    $stmt->execute(array_values($fields));
}

/** @return array{row_count:int,status_counts:array<string,int>} */
function thumbnail_metadata_model_storage_counts(): array
{
    $rowCount = (int) db()->query('SELECT COUNT(*) FROM image_thumbnail_variants')->fetchColumn();
    $statusCounts = [];
    $stmt = db()->query('SELECT status, COUNT(*) AS count_rows FROM image_thumbnail_variants GROUP BY status ORDER BY status');
    foreach ($stmt->fetchAll() as $row) {
        $statusCounts[(string) ($row['status'] ?? '')] = (int) ($row['count_rows'] ?? 0);
    }
    return ['row_count' => $rowCount, 'status_counts' => $statusCounts];
}

/** @return array{data_bytes:int,index_bytes:int,total_bytes:int} */
function thumbnail_metadata_model_storage_size(): array
{
    $stmt = db()->prepare(
        'SELECT data_length, index_length FROM information_schema.tables'
        . ' WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    $stmt->execute(['image_thumbnail_variants']);
    $table = $stmt->fetch() ?: [];
    $dataBytes = (int) ($table['data_length'] ?? 0);
    $indexBytes = (int) ($table['index_length'] ?? 0);
    return [
        'data_bytes' => $dataBytes,
        'index_bytes' => $indexBytes,
        'total_bytes' => $dataBytes + $indexBytes,
    ];
}


/**
 * Return valid thumbnail rows for the public media manifest.
 *
 * @param array<int,int> $imageIds Image identifiers.
 * @param array<int,int> $sizes Requested thumbnail sizes.
 * @param array<int,string> $formats Allowed formats.
 * @return array<int,array<string,mixed>> Valid thumbnail metadata rows.
 */
function thumbnail_metadata_model_public_manifest_rows(array $imageIds, array $sizes, array $formats, bool $hasDerivativeVersion): array
{
    $imageIds = array_values(array_unique(array_filter(array_map('intval', $imageIds), static fn (int $id): bool => $id > 0)));
    $sizes = array_values(array_unique(array_filter(array_map('intval', $sizes), static fn (int $size): bool => $size > 0)));
    $formats = array_values(array_intersect(['jpg', 'webp'], array_map('strval', $formats)));
    if ($imageIds === [] || $sizes === [] || $formats === []) {
        return [];
    }

    $imagePlaceholders = implode(',', array_fill(0, count($imageIds), '?'));
    $sizePlaceholders = implode(',', array_fill(0, count($sizes), '?'));
    $formatPlaceholders = implode(',', array_fill(0, count($formats), '?'));
    $derivativeVersionSelect = $hasDerivativeVersion ? 'derivative_version' : '0 AS derivative_version';
    $stmt = db()->prepare(
        "SELECT image_id, size_px, format, width, height, status, $derivativeVersionSelect"
        . " FROM image_thumbnail_variants WHERE image_id IN ($imagePlaceholders)"
        . " AND size_px IN ($sizePlaceholders) AND format IN ($formatPlaceholders)"
        . " AND status = 'valid' ORDER BY image_id, size_px, format"
    );
    $stmt->execute(array_merge($imageIds, $sizes, $formats));
    return $stmt->fetchAll() ?: [];
}
