<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/public_search.php
 * Module Type: View
 *
 * Purpose:
 *   Renders the optional progressive public-search control on public gallery pages.
 *
 * Responsibilities:
 *   - Render accessible search input, context control, clear button, and result container
 *   - Expose phase scheduling configuration as presentation data attributes
 *   - Keep public-search HTML out of controller modules
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
 *   - Search execution and database access must remain outside the view layer.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Core\url_for;
use function Gallery\Services\public_home_search_enabled;
use function Gallery\Services\t;

/**
 * Render the optional thin public search bar above public gallery content.
 *
 * @param ?array $gallery Gallery row or gallery data.
 */
function view_render_public_search_bar(?array $gallery = null): void
{
    if (!public_home_search_enabled()) {
        return;
    }

    $searchId = $gallery ? 'public-gallery-search-input-' . (int) $gallery['id'] : 'public-home-search-input';
    $contextId = $gallery ? 'public-gallery-search-context-' . (int) $gallery['id'] : '';
    $ariaLabel = $gallery ? t('search.gallery_label', 'Search this gallery and all galleries') : t('search.home_label', 'Search galleries and photos');
    $placeholder = $gallery ? t('search.gallery_placeholder', 'Search this gallery, subgalleries, tags, photos...') : t('search.placeholder', 'Search galleries, tags, photos...');

    echo '<section class="public-home-search" data-public-home-search data-search-url="' . e(url_for('public_search')) . '" data-min-length="2" data-delay-ms="200" data-media-delay-ms="100" data-descriptive-delay-ms="300" data-deep-delay-ms="550" data-loading-label="' . e(t('search.loading', 'Searching...')) . '" data-empty-label="' . e(t('search.empty', 'No matches found.')) . '" data-error-label="' . e(t('search.error', 'Search is temporarily unavailable.')) . '"' . ($gallery ? ' data-gallery-id="' . (int) $gallery['id'] . '"' : '') . ' aria-label="' . e($ariaLabel) . '" aria-busy="false">';
    echo '<label class="visually-hidden" for="' . e($searchId) . '">' . e($ariaLabel) . '</label>';
    echo '<div class="public-home-search-shell">';
    echo '<span class="public-home-search-icon" aria-hidden="true">&#128269;</span>';
    echo '<input id="' . e($searchId) . '" class="public-home-search-input" type="search" autocomplete="off" spellcheck="false" placeholder="' . e($placeholder) . '" data-public-home-search-input>';
    if ($gallery) {
        echo '<label class="public-home-search-context" for="' . e($contextId) . '"><input id="' . e($contextId) . '" type="checkbox" checked data-public-home-search-context> <span>' . e(t('search.context_current_gallery', 'Search only this gallery and its subgalleries')) . '</span></label>';
    }
    echo '<button type="button" class="public-home-search-clear" data-public-home-search-clear aria-label="' . e(t('search.clear', 'Clear search')) . '" hidden>&times;</button>';
    echo '</div>';
    echo '<div class="public-home-search-results" data-public-home-search-results role="region" aria-label="' . e($ariaLabel) . '" hidden></div>';
    echo '</section>';
}
