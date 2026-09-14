<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/gallery_dates.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders prepared public gallery date presentation data.
 *
 * Responsibilities:
 *   - Render optional single-date and date-range time markup
 *   - Keep date HTML outside the Gallery Date service
 *   - Consume only normalized values prepared by the service/controller layer
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
 *   - This view performs no schema, request, or persistence lookup.
 *
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;

/**
 * Render one prepared gallery date or date range.
 *
 * @param ?array{start:?string,end:?string,display:string} $date Prepared date presentation data.
 * @param string $class CSS class applied to the time element.
 */
function view_render_gallery_date(?array $date, string $class = 'gallery-date'): void
{
    if ($date === null || trim((string) ($date['display'] ?? '')) === '') {
        return;
    }

    $start = isset($date['start']) && $date['start'] !== '' ? (string) $date['start'] : null;
    $end = isset($date['end']) && $date['end'] !== '' ? (string) $date['end'] : null;
    $attributes = ' class="' . e($class) . '"';
    if ($start !== null) {
        $attributes .= ' datetime="' . e($start) . '" data-date-start="' . e($start) . '"';
    }
    if ($end !== null) {
        $attributes .= ' data-date-end="' . e($end) . '"';
    }
    echo '<time' . $attributes . '>' . e((string) $date['display']) . '</time>';
}
