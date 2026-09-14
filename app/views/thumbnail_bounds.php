<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/thumbnail_bounds.php
 * Module Type: View
 *
 * Purpose:
 *   Renders Admin thumbnail-bound controls prepared by controller/service logic.
 *
 * Responsibilities:
 *   - Render the dual-pin thumbnail-size bound slider
 *   - Escape all labels, field names, and generated values
 *   - Keep presentation output outside thumbnail services
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
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Services\t;

/**
 * Render a prepared dual-pin thumbnail-bound slider.
 *
 * @param string $prefix Form field prefix.
 * @param array<int,int> $values Slider values.
 * @param int $minIndex Selected minimum index.
 * @param int $maxIndexValue Selected maximum index.
 * @param string $label Human-readable control label.
 * @param string $description Human-readable description.
 */
function render_admin_thumbnail_bound_slider(string $prefix, array $values, int $minIndex, int $maxIndexValue, string $label, string $description): void
{
    $maxIndex = max(0, count($values) - 1);
    echo '<div class="admin-thumbnail-bound-control" data-thumbnail-bound-control data-thumbnail-bound-values="' . e(implode(',', $values)) . '">';
    echo '<div class="admin-thumbnail-bound-header"><div><h3>' . e($label) . '</h3><p class="muted">' . e($description) . '</p></div><strong data-thumbnail-bound-summary>' . e(t('thumbnail_bounds.auto', 'Auto')) . '</strong></div>';
    echo '<div class="admin-thumbnail-bound-valuebar" aria-hidden="true">';
    echo '<span><small>' . e(t('thumbnail_bounds.min', 'Min')) . '</small><b data-thumbnail-bound-min-display>' . e(t('thumbnail_bounds.auto_min', 'Auto min')) . '</b></span>';
    echo '<span><small>' . e(t('thumbnail_bounds.max', 'Max')) . '</small><b data-thumbnail-bound-max-display>' . e(t('thumbnail_bounds.auto_max', 'Auto max')) . '</b></span>';
    echo '</div>';
    echo '<div class="admin-thumbnail-bound-slider" aria-label="' . e($label) . '">';
    echo '<div class="admin-thumbnail-bound-rail" aria-hidden="true"></div>';
    echo '<input type="range" min="0" max="' . (int) $maxIndex . '" step="1" value="' . (int) $minIndex . '" data-thumbnail-bound-min-index aria-label="' . e(t('thumbnail_bounds.minimum_size', 'Minimum thumbnail size')) . '">';
    echo '<input type="range" min="0" max="' . (int) $maxIndex . '" step="1" value="' . (int) $maxIndexValue . '" data-thumbnail-bound-max-index aria-label="' . e(t('thumbnail_bounds.maximum_size', 'Maximum thumbnail size')) . '">';
    echo '</div>';
    echo '<input type="hidden" name="' . e($prefix) . '_min_size" value="' . (int) ($values[$minIndex] ?? 0) . '" data-thumbnail-bound-min-value>';
    echo '<input type="hidden" name="' . e($prefix) . '_max_size" value="' . (int) ($values[$maxIndexValue] ?? 0) . '" data-thumbnail-bound-max-value>';
    echo '</div>';
}
