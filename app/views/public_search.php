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

/**
 * Render the optional thin public search bar above public gallery content.
 *
 * @param array<string, mixed> $viewModel Prepared public-search presentation data.
 */
function view_render_public_search_bar(array $viewModel): void
{
    if (empty($viewModel['enabled'])) {
        return;
    }

    $searchId = (string) ($viewModel['search_id'] ?? 'public-home-search-input');
    $contextId = (string) ($viewModel['context_id'] ?? '');
    $galleryId = isset($viewModel['gallery_id']) ? (int) $viewModel['gallery_id'] : 0;
    $ariaLabel = (string) ($viewModel['aria_label'] ?? '');
    $placeholder = (string) ($viewModel['placeholder'] ?? '');

    echo '<section class="public-home-search" data-public-home-search data-search-url="' . e((string) ($viewModel['search_url'] ?? '')) . '" data-min-length="' . (int) ($viewModel['min_length'] ?? 2) . '" data-delay-ms="' . (int) ($viewModel['delay_ms'] ?? 200) . '" data-media-delay-ms="' . (int) ($viewModel['media_delay_ms'] ?? 100) . '" data-descriptive-delay-ms="' . (int) ($viewModel['descriptive_delay_ms'] ?? 300) . '" data-deep-delay-ms="' . (int) ($viewModel['deep_delay_ms'] ?? 550) . '" data-loading-label="' . e((string) ($viewModel['loading_label'] ?? '')) . '" data-empty-label="' . e((string) ($viewModel['empty_label'] ?? '')) . '" data-error-label="' . e((string) ($viewModel['error_label'] ?? '')) . '"' . ($galleryId > 0 ? ' data-gallery-id="' . $galleryId . '"' : '') . ' aria-label="' . e($ariaLabel) . '" aria-busy="false">';
    echo '<label class="visually-hidden" for="' . e($searchId) . '">' . e($ariaLabel) . '</label>';
    echo '<div class="public-home-search-shell">';
    echo '<span class="public-home-search-icon" aria-hidden="true">&#128269;</span>';
    echo '<input id="' . e($searchId) . '" class="public-home-search-input" type="search" autocomplete="off" spellcheck="false" placeholder="' . e($placeholder) . '" data-public-home-search-input>';
    if ($galleryId > 0 && $contextId !== '') {
        echo '<label class="public-home-search-context" for="' . e($contextId) . '"><input id="' . e($contextId) . '" type="checkbox" checked data-public-home-search-context> <span>' . e((string) ($viewModel['context_label'] ?? '')) . '</span></label>';
    }
    echo '<button type="button" class="public-home-search-clear" data-public-home-search-clear aria-label="' . e((string) ($viewModel['clear_label'] ?? '')) . '" hidden>&times;</button>';
    echo '</div>';
    echo '<div class="public-home-search-results" data-public-home-search-results role="region" aria-label="' . e($ariaLabel) . '" hidden></div>';
    echo '</section>';
}
