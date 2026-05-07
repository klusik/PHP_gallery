<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/thumbnail_bounds.php
 * Module Type: Service
 *
 * Purpose:
 *   Stores optional per-gallery and per-image responsive thumbnail size bounds.
 *
 * Responsibilities:
 *   - Keep thumbnail bounds nullable so existing automatic behavior remains unchanged
 *   - Validate submitted Admin min/max values against generated thumbnail sizes
 *   - Provide small rendering helpers for Admin forms
 *   - Support explicit recursive gallery saves when requested by the admin
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
 *   2026-05-07
 */

declare(strict_types=1);

/**
 * Return true when thumbnail-bound columns are available for galleries and images.
 */
function thumbnail_bounds_schema_ready(): bool
{
    return db_column_exists('galleries', 'thumbnail_min_size')
        && db_column_exists('galleries', 'thumbnail_max_size')
        && db_column_exists('images', 'thumbnail_min_size')
        && db_column_exists('images', 'thumbnail_max_size');
}

/**
 * Return slider options as integers with the virtual Auto sentinel at both ends.
 */
function thumbnail_bound_slider_values(): array
{
    return array_values(array_unique(array_merge([0], thumbnail_sizes())));
}

/**
 * Convert a stored thumbnail-bound value to the slider sentinel when unset.
 */
function thumbnail_bound_form_value(mixed $value): int
{
    $size = (int) ($value ?? 0);
    return in_array($size, thumbnail_sizes(), true) ? $size : 0;
}

/**
 * Sanitize a posted thumbnail-bound value.
 */
function thumbnail_bound_post_value(mixed $value): ?int
{
    $size = (int) ($value ?? 0);
    if ($size === 0) {
        return null;
    }
    return in_array($size, thumbnail_sizes(), true) ? $size : null;
}

/**
 * Normalize a submitted thumbnail-bound pair, preserving Auto when either side is unset.
 */
function thumbnail_bound_pair_from_post(string $prefix): array
{
    $minSize = thumbnail_bound_post_value($_POST[$prefix . '_min_size'] ?? 0);
    $maxSize = thumbnail_bound_post_value($_POST[$prefix . '_max_size'] ?? 0);
    if ($minSize !== null && $maxSize !== null && $minSize > $maxSize) {
        $temporarySize = $minSize;
        $minSize = $maxSize;
        $maxSize = $temporarySize;
    }
    return [$minSize, $maxSize];
}

/**
 * Return gallery IDs for one gallery and all descendants based on folder paths.
 */
function thumbnail_bound_gallery_branch_ids(array $gallery): array
{
    $folderPath = normalize_relative_path((string) ($gallery['folder_path'] ?? ''));
    if ($folderPath === '') {
        return [(int) $gallery['id']];
    }
    $stmt = db()->prepare('SELECT id FROM galleries WHERE folder_path = ? OR folder_path LIKE ? ORDER BY CHAR_LENGTH(folder_path), folder_path, id');
    $stmt->execute([$folderPath, $folderPath . '/%']);
    $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
    return $ids !== [] ? $ids : [(int) $gallery['id']];
}

/**
 * Apply gallery thumbnail bounds to one gallery or its whole descendant branch.
 */
function save_gallery_thumbnail_bounds(array $gallery, ?int $minSize, ?int $maxSize, bool $recursive): int
{
    if (!thumbnail_bounds_schema_ready()) {
        return 0;
    }
    $galleryIds = $recursive ? thumbnail_bound_gallery_branch_ids($gallery) : [(int) $gallery['id']];
    $placeholders = implode(', ', array_fill(0, count($galleryIds), '?'));
    $stmt = db()->prepare('UPDATE galleries SET thumbnail_min_size = ?, thumbnail_max_size = ?, updated_at = ? WHERE id IN (' . $placeholders . ')');
    $stmt->execute(array_merge([$minSize, $maxSize, now_sql()], $galleryIds));
    $changedRows = $stmt->rowCount();
    if (function_exists('write_gallery_sidecar')) {
        foreach ($galleryIds as $galleryId) {
            $updatedGallery = find_gallery((int) $galleryId, true);
            if ($updatedGallery) {
                write_gallery_sidecar($updatedGallery);
            }
        }
    }
    return $changedRows;
}

/**
 * Render a dual-pin thumbnail-bound slider for Admin forms.
 */
function render_admin_thumbnail_bound_slider(string $prefix, ?int $storedMinSize, ?int $storedMaxSize, string $label, string $description): void
{
    $values = thumbnail_bound_slider_values();
    $maxIndex = count($values) - 1;
    $minIndex = array_search(thumbnail_bound_form_value($storedMinSize), $values, true);
    $maxValue = thumbnail_bound_form_value($storedMaxSize);
    $maxIndexValue = $maxValue === 0 ? $maxIndex : array_search($maxValue, $values, true);
    $minIndex = $minIndex === false ? 0 : (int) $minIndex;
    $maxIndexValue = $maxIndexValue === false ? $maxIndex : (int) $maxIndexValue;
    if ($minIndex > $maxIndexValue) {
        $minIndex = $maxIndexValue;
    }

    echo '<div class="admin-thumbnail-bound-control" data-thumbnail-bound-control data-thumbnail-bound-values="' . e(implode(',', $values)) . '">';
    echo '<div class="admin-thumbnail-bound-header"><div><h3>' . e($label) . '</h3><p class="muted">' . e($description) . '</p></div><strong data-thumbnail-bound-summary>Auto</strong></div>';
    echo '<div class="admin-thumbnail-bound-slider" aria-label="' . e($label) . '">';
    echo '<input type="range" min="0" max="' . (int) $maxIndex . '" step="1" value="' . (int) $minIndex . '" data-thumbnail-bound-min-index aria-label="Minimum thumbnail size">';
    echo '<input type="range" min="0" max="' . (int) $maxIndex . '" step="1" value="' . (int) $maxIndexValue . '" data-thumbnail-bound-max-index aria-label="Maximum thumbnail size">';
    echo '</div>';
    echo '<input type="hidden" name="' . e($prefix) . '_min_size" value="' . (int) $values[$minIndex] . '" data-thumbnail-bound-min-value>';
    echo '<input type="hidden" name="' . e($prefix) . '_max_size" value="' . (int) $values[$maxIndexValue] . '" data-thumbnail-bound-max-value>';
    echo '<div class="admin-thumbnail-bound-scale" aria-hidden="true"><span>Auto</span>';
    foreach (thumbnail_sizes() as $size) {
        echo '<span>' . (int) $size . '</span>';
    }
    echo '<span>Auto</span></div></div>';
}


/**
 * Return the nearest generated thumbnail size inside the supplied bounds.
 */
function thumbnail_bound_clamp_size(int $size, ?int $minSize, ?int $maxSize): int
{
    $availableSizes = thumbnail_sizes();
    if (!in_array($size, $availableSizes, true)) {
        usort($availableSizes, static fn (int $left, int $right): int => abs($left - $size) <=> abs($right - $size));
        $size = $availableSizes[0] ?? $size;
    }
    if ($minSize !== null && $size < $minSize) {
        $size = $minSize;
    }
    if ($maxSize !== null && $size > $maxSize) {
        $size = $maxSize;
    }
    return in_array($size, thumbnail_sizes(), true) ? $size : (thumbnail_sizes()[0] ?? 300);
}

/**
 * Return effective thumbnail bounds for one image, with image values taking precedence over gallery values.
 */
function thumbnail_bound_effective_pair(array $image, ?array $gallery = null): array
{
    if (!thumbnail_bounds_schema_ready()) {
        return [null, null];
    }

    if ($gallery === null && isset($image['gallery_id'])) {
        $gallery = find_gallery((int) $image['gallery_id']);
    }

    $galleryMinSize = thumbnail_bound_post_value($gallery['thumbnail_min_size'] ?? null);
    $galleryMaxSize = thumbnail_bound_post_value($gallery['thumbnail_max_size'] ?? null);
    $imageMinSize = thumbnail_bound_post_value($image['thumbnail_min_size'] ?? null);
    $imageMaxSize = thumbnail_bound_post_value($image['thumbnail_max_size'] ?? null);

    $minSize = $imageMinSize ?? $galleryMinSize;
    $maxSize = $imageMaxSize ?? $galleryMaxSize;
    if ($minSize !== null && $maxSize !== null && $minSize > $maxSize) {
        $temporarySize = $minSize;
        $minSize = $maxSize;
        $maxSize = $temporarySize;
    }
    return [$minSize, $maxSize];
}

/**
 * Filter responsive thumbnail candidates so browser auto-selection respects configured guardrails.
 */
function thumbnail_bound_filter_sizes(array $sizes, array $image, ?array $gallery = null): array
{
    [$minSize, $maxSize] = thumbnail_bound_effective_pair($image, $gallery);
    if ($minSize === null && $maxSize === null) {
        return $sizes;
    }

    $filteredSizes = [];
    foreach ($sizes as $size) {
        $size = (int) $size;
        if ($minSize !== null && $size < $minSize) {
            continue;
        }
        if ($maxSize !== null && $size > $maxSize) {
            continue;
        }
        $filteredSizes[] = $size;
    }

    if ($filteredSizes !== []) {
        return array_values(array_unique($filteredSizes));
    }

    $preferredSize = (int) ($sizes[0] ?? thumbnail_sizes()[0] ?? 300);
    return [thumbnail_bound_clamp_size($preferredSize, $minSize, $maxSize)];
}

/**
 * Clamp a requested fallback thumbnail size to the effective guardrails for one image.
 */
function thumbnail_bound_fallback_size(array $image, int $fallbackSize, ?array $gallery = null): int
{
    [$minSize, $maxSize] = thumbnail_bound_effective_pair($image, $gallery);
    if ($minSize === null && $maxSize === null) {
        return $fallbackSize;
    }
    return thumbnail_bound_clamp_size($fallbackSize, $minSize, $maxSize);
}
