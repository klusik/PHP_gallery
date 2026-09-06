<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_gallery_report/query_helpers.php
 * Module Type: Service
 *
 * Purpose:
 *   Read-only query and grouping helpers shared by every report section.
 *
 * Responsibilities:
 *   - Run bounded read-only queries that degrade to empty results on failure
 *   - Probe optional tables and columns before a section depends on them
 *   - Accumulate and finalize grouped count/byte rows with stable ordering
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
 *   - Loaded by app/services/admin_gallery_report.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/admin_gallery_report.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;
use function Gallery\Core\cms_config;
use function Gallery\Core\cms_current_version;
use function Gallery\Core\db;

/**
 * Build a safe optional SQL column expression.
 *
 * @param string $table Table name.
 * @param string $column Column name.
 * @param string $alias SQL table alias.
 * @param string $fallback Fallback SQL expression.
 * @param string $outputAlias Output alias.
 * @return string SQL expression.
 */
function admin_gallery_report_column_select(string $table, string $column, string $alias, string $fallback, string $outputAlias): string
{
    if (admin_gallery_report_column_exists($table, $column)) {
        return $alias . '.' . $column . ' AS ' . $outputAlias;
    }
    return $fallback . ' AS ' . $outputAlias;
}

/**
 * Return whether a table exists.
 *
 * @param string $table Table name.
 * @return bool True when the table exists.
 */
function admin_gallery_report_table_exists(string $table): bool
{
    $status = schema_inspection_feature('presentation.admin_report.table.' . $table, [
        schema_inspection_table($table),
    ]);
    return presentation_schema_render_available($status, 'admin_gallery_report_table');
}

/**
 * Return whether one optional report column is positively available.
 *
 * Unknown inspection state omits only the affected report field and is logged
 * through the Phase 11 presentation policy. Confirmed absence remains a normal
 * compatibility state for reports generated against older installations.
 *
 * @param string $table Table name.
 * @param string $column Column name.
 * @return bool True only when the column is verified available.
 */
function admin_gallery_report_column_exists(string $table, string $column): bool
{
    $status = schema_inspection_feature('presentation.admin_report.column.' . $table . '.' . $column, [
        schema_inspection_column($table, $column),
    ]);
    return presentation_schema_render_available($status, 'admin_gallery_report_column');
}

/**
 * Read rows with failure isolation.
 *
 * @param string $sql SQL query.
 * @param array $params Query parameters.
 * @return array<int, array<string, mixed>> Rows.
 */
function admin_gallery_report_rows(string $sql, array $params = []): array
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

/**
 * Read scalar integer with failure isolation.
 *
 * @param string $sql SQL query.
 * @param array $params Query parameters.
 * @param bool $countRows Count result rows instead of reading first column.
 * @return int Integer value.
 */
function admin_gallery_report_scalar_int(string $sql, array $params = [], bool $countRows = false): int
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        if ($countRows) {
            return count($stmt->fetchAll());
        }
        return (int) ($stmt->fetchColumn() ?: 0);
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Read grouping rows with standard labels and counts.
 *
 * @param string $sql SQL query.
 * @param array $params Query parameters.
 * @return array<int, array<string, mixed>> Group rows.
 */
function admin_gallery_report_group_query(string $sql, array $params = []): array
{
    $rows = admin_gallery_report_rows($sql, $params);
    foreach ($rows as &$row) {
        $row['label'] = (string) ($row['label'] ?? 'unknown');
        $row['count'] = (int) ($row['count'] ?? 0);
    }
    unset($row);
    return $rows;
}

/**
 * Add one value to an aggregate group.
 *
 * @param array $groups Group accumulator.
 * @param string $key Group key.
 * @param string $label Human label.
 * @param int $count Count increment.
 * @param int $bytes Byte increment.
 * @param array $meta Additional fields.
 */
function admin_gallery_report_add_group(array &$groups, string $key, string $label, int $count, int $bytes = 0, array $meta = []): void
{
    if (!isset($groups[$key]) || !is_array($groups[$key])) {
        $groups[$key] = array_merge([
            'key' => $key,
            'label' => $label,
            'count' => 0,
            'bytes' => 0,
        ], $meta);
    }
    $groups[$key]['count'] = (int) ($groups[$key]['count'] ?? 0) + $count;
    $groups[$key]['bytes'] = (int) ($groups[$key]['bytes'] ?? 0) + $bytes;
}

/**
 * Finalize group rows sorted by count or bytes.
 *
 * @param array $groups Group accumulator.
 * @param string $sortKey Sort key.
 * @param int $limit Maximum rows.
 * @return array<int, array<string, mixed>> Final rows.
 */
function admin_gallery_report_finalize_group_rows(array $groups, string $sortKey = 'count', int $limit = 80): array
{
    $rows = array_values(array_filter($groups, static fn ($row): bool => is_array($row)));
    usort($rows, static function (array $a, array $b) use ($sortKey): int {
        $primary = ((int) ($b[$sortKey] ?? 0)) <=> ((int) ($a[$sortKey] ?? 0));
        if ($primary !== 0) {
            return $primary;
        }
        return strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    });
    return array_slice($rows, 0, max(1, $limit));
}

/**
 * Update a string date range.
 *
 * @param array $target Target array.
 * @param string $minKey Minimum key.
 * @param string $maxKey Maximum key.
 * @param string $value Date value.
 */
function admin_gallery_report_update_date_range(array &$target, string $minKey, string $maxKey, string $value): void
{
    if (!admin_gallery_report_valid_datetime($value)) {
        return;
    }
    if (empty($target[$minKey]) || strcmp($value, (string) $target[$minKey]) < 0) {
        $target[$minKey] = $value;
    }
    if (empty($target[$maxKey]) || strcmp($value, (string) $target[$maxKey]) > 0) {
        $target[$maxKey] = $value;
    }
}

/**
 * Update a float range.
 *
 * @param array $target Target array.
 * @param string $minKey Minimum key.
 * @param string $maxKey Maximum key.
 * @param float $value Numeric value.
 */
function admin_gallery_report_update_float_range(array &$target, string $minKey, string $maxKey, float $value): void
{
    if ($target[$minKey] === null || $value < (float) $target[$minKey]) {
        $target[$minKey] = $value;
    }
    if ($target[$maxKey] === null || $value > (float) $target[$maxKey]) {
        $target[$maxKey] = $value;
    }
}
