<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_sorting.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides reusable ordering helpers for gallery rows.
 *
 * Responsibilities:
 *   - Keep gallery ordering algorithms outside request controllers
 *   - Preserve existing row positions for galleries that should not participate
 *   - Return deterministic sorted row lists for read and write workflows
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
 *   2026-06-14
 */

declare(strict_types=1);

namespace Gallery\Services;

/**
 * Return whether a gallery row has a filled start date usable for sorting.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return bool True when the gallery has a non-empty start date.
 */
function gallery_sort_row_has_start_date(array $gallery): bool
{
    return trim((string) ($gallery['gallery_date'] ?? '')) !== '';
}

/**
 * Count gallery rows that can participate in start-date sorting.
 *
 * @param array $galleries Gallery rows in their current order.
 * @return int Number of rows with a filled start date.
 */
function gallery_count_dated_rows(array $galleries): int
{
    // $count stores the number of sortable dated gallery rows.
    $count = 0;
    foreach ($galleries as $gallery) {
        if (is_array($gallery) && gallery_sort_row_has_start_date($gallery)) {
            $count++;
        }
    }
    return $count;
}

/**
 * Sort dated gallery rows while preserving undated row positions.
 *
 * Undated gallery rows are ignored by the sort. Their existing positions remain
 * stable, while dated rows are sorted only within the slots that were already
 * occupied by dated rows. This lets the public preview and the persistent admin
 * save action use the same deterministic order.
 *
 * @param array $galleries Gallery rows in their current order.
 * @param string $mode Sort mode: asc or desc.
 * @return array Gallery rows with dated rows sorted inside dated positions.
 */
function gallery_sort_rows_by_date_preserving_undated_positions(array $galleries, string $mode): array
{
    // $datedRows stores sortable gallery rows keyed by their original index.
    $datedRows = [];
    foreach ($galleries as $index => $gallery) {
        if (is_array($gallery) && gallery_sort_row_has_start_date($gallery)) {
            $datedRows[(int) $index] = $gallery;
        }
    }

    if (count($datedRows) < 2) {
        return array_values($galleries);
    }

    // $datedPositions stores the current slots occupied by dated rows.
    $datedPositions = array_keys($datedRows);
    usort($datedRows, static function (array $left, array $right) use ($mode): int {
        // $leftDate stores the normalized start date used as the primary key.
        $leftDate = (string) ($left['gallery_date'] ?? '');
        // $rightDate stores the normalized start date used as the primary key.
        $rightDate = (string) ($right['gallery_date'] ?? '');
        // $dateComparison stores ascending date comparison before optional reversal.
        $dateComparison = strcmp($leftDate, $rightDate);
        if ($dateComparison === 0) {
            // $orderComparison keeps date ties deterministic and close to normal gallery order.
            $orderComparison = ((int) ($left['sort_order'] ?? 0)) <=> ((int) ($right['sort_order'] ?? 0));
            if ($orderComparison === 0) {
                $orderComparison = strcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
            }
            if ($orderComparison === 0) {
                $orderComparison = ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
            }
            return $orderComparison;
        }
        return $mode === 'desc' ? -$dateComparison : $dateComparison;
    });

    // $sortedIndex stores the next dated row to inject back into a dated position.
    $sortedIndex = 0;
    foreach ($datedPositions as $index) {
        $galleries[(int) $index] = $datedRows[$sortedIndex];
        $sortedIndex++;
    }

    return array_values($galleries);
}
