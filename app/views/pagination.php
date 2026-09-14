<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/pagination.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders accessible public pagination controls from prepared pagination data.
 *
 * Responsibilities:
 *   - Render previous, numbered, gap, next, and status controls
 *   - Keep pagination HTML outside service orchestration
 *   - Consume only controller/service-prepared URLs and page metadata
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
 *   - This view performs no request or persistence lookup.
 *
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Services\t;

/**
 * Render accessible public pagination controls for one listing.
 *
 * @param array $pagination Prepared pagination data.
 * @param string $label Optional translated navigation label.
 */
function view_render_pagination_controls(array $pagination, string $label = ''): void
{
    if (empty($pagination['pagination_needed'])) {
        return;
    }

    $navLabel = $label !== '' ? $label : t('pagination.label', 'Pagination');
    echo '<nav class="pagination" aria-label="' . e($navLabel) . '">';
    if ((string) $pagination['previous_url'] !== '') {
        echo '<a class="pagination-link" href="' . e((string) $pagination['previous_url']) . '">' . e(t('pagination.previous', 'Previous')) . '</a>';
    } else {
        echo '<span class="pagination-link is-disabled" aria-disabled="true">' . e(t('pagination.previous', 'Previous')) . '</span>';
    }

    // $previousPage stores the last rendered page number so gaps can be shown.
    $previousPage = 0;
    foreach ((array) $pagination['page_urls'] as $pageLink) {
        // $pageNumber stores the visible page number for this link.
        $pageNumber = (int) $pageLink['page'];
        if ($previousPage > 0 && $pageNumber > $previousPage + 1) {
            echo '<span class="pagination-gap" aria-hidden="true">...</span>';
        }
        if (!empty($pageLink['current'])) {
            echo '<span class="pagination-link is-current" aria-current="page">' . $pageNumber . '</span>';
        } else {
            echo '<a class="pagination-link" href="' . e((string) $pageLink['url']) . '">' . $pageNumber . '</a>';
        }
        $previousPage = $pageNumber;
    }

    if ((string) $pagination['next_url'] !== '') {
        echo '<a class="pagination-link" href="' . e((string) $pagination['next_url']) . '">' . e(t('pagination.next', 'Next')) . '</a>';
    } else {
        echo '<span class="pagination-link is-disabled" aria-disabled="true">' . e(t('pagination.next', 'Next')) . '</span>';
    }
    echo '<span class="pagination-status">' . e(t('pagination.status', 'Page {current} of {total}', ['current' => (string) $pagination['current_page'], 'total' => (string) $pagination['total_pages']])) . '</span>';
    echo '</nav>';
}
