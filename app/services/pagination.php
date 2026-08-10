<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/pagination.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides reusable application logic for gallery data, media, settings, or maintenance workflows.
 *
 * Responsibilities:
 *   - Keep domain logic reusable outside controllers
 *   - Protect existing behavior with small focused functions
 *   - Return predictable values for callers
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
 *   2026-05-04
 */

declare(strict_types=1);

namespace Gallery\Services;

use function Gallery\Core\e;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\public_base_url;
use function Gallery\Core\url_for;

/**
 * Unified public-list pagination helpers.
 *
 * The current release exposes only global Theme settings. The context argument is
 * kept in the public functions so later gallery-specific overrides or separate
 * photo/gallery listing defaults can be added without changing controller calls.
 */

const CMS_PAGINATION_PARAM = 'list_page';
const CMS_PAGINATION_DEFAULT_COLUMNS = 3;
const CMS_PAGINATION_DEFAULT_ROWS = 3;
const CMS_PAGINATION_MAX_COLUMNS = 12;
const CMS_PAGINATION_MAX_ROWS = 50;

/**
 * Clamp one pagination dimension to a safe positive integer range.
 *
 * @param mixed $value Value to process.
 * @param int $default Default value when no explicit value is available.
 * @param int $maximum Maximum value.
 * @return int Integer result for the caller.
 */
function pagination_dimension_value(mixed $value, int $default, int $maximum): int
{
    // $number stores the normalized integer candidate before range clamping.
    $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $maximum]]);
    if ($number === false) {
        return $default;
    }
    return max(1, min($maximum, (int) $number));
}

/**
 * Return sanitized global pagination settings for public gallery lists.
 *
 * @param ?array $context Context value.
 * @return array Structured result data for the caller.
 */
function pagination_global_settings(?array $context = null): array
{
    // $context is intentionally unused for now. It reserves the extension point
    // for future per-gallery overrides without requiring callers to change shape.
    unset($context);

    // $enabled stores whether public gallery and photo lists should be sliced.
    $enabled = app_setting('pagination_enabled', '0') === '1';
    // $columns stores the sanitized column count used to derive items per page.
    $columns = pagination_dimension_value(app_setting('pagination_columns', (string) CMS_PAGINATION_DEFAULT_COLUMNS), CMS_PAGINATION_DEFAULT_COLUMNS, CMS_PAGINATION_MAX_COLUMNS);
    // $rows stores the sanitized row count used to derive items per page.
    $rows = pagination_dimension_value(app_setting('pagination_rows', (string) CMS_PAGINATION_DEFAULT_ROWS), CMS_PAGINATION_DEFAULT_ROWS, CMS_PAGINATION_MAX_ROWS);

    return [
        'enabled' => $enabled,
        'columns' => $columns,
        'rows' => $rows,
        'items_per_page' => $columns * $rows,
    ];
}

/**
 * Return a safe current page number from one GET parameter.
 *
 * @param string $parameterName Parameter name value.
 * @return int Integer result for the caller.
 */
function pagination_current_page(string $parameterName = CMS_PAGINATION_PARAM): int
{
    // $rawValue stores the untrusted GET value before validation.
    $rawValue = $_GET[$parameterName] ?? 1;
    // $page stores the validated current-page candidate.
    $page = filter_var($rawValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $page === false ? 1 : (int) $page;
}

/**
 * Build a pagination model for one listing.
 *
 * @param int $totalItems Total items value.
 * @param int $currentPage Current page value.
 * @param int $columns Columns value.
 * @param int $rows Rows to process.
 * @param string $parameterName Parameter name value.
 * @param ?array $baseQuery Base query value.
 * @param ?callable $urlBuilder Url builder URL.
 * @return array Structured result data for the caller.
 */
function pagination_model(int $totalItems, int $currentPage, int $columns, int $rows, string $parameterName = CMS_PAGINATION_PARAM, ?array $baseQuery = null, ?callable $urlBuilder = null): array
{
    // $safeTotal stores the non-negative item count used by all calculations.
    $safeTotal = max(0, $totalItems);
    // $safeColumns stores the sanitized column setting for this model.
    $safeColumns = pagination_dimension_value($columns, CMS_PAGINATION_DEFAULT_COLUMNS, CMS_PAGINATION_MAX_COLUMNS);
    // $safeRows stores the sanitized row setting for this model.
    $safeRows = pagination_dimension_value($rows, CMS_PAGINATION_DEFAULT_ROWS, CMS_PAGINATION_MAX_ROWS);
    // $limit stores the effective list size, always derived from columns and rows.
    $limit = $safeColumns * $safeRows;
    // $totalPages stores at least one page so invalid page values can clamp safely.
    $totalPages = max(1, (int) ceil($safeTotal / $limit));
    // $page stores the current page clamped into the valid page range.
    $page = max(1, min($totalPages, $currentPage));
    // $offset stores the first list item index for array slicing.
    $offset = ($page - 1) * $limit;
    // $isNeeded stores whether controls and slicing should be active.
    $isNeeded = $safeTotal > $limit;

    return [
        'parameter' => $parameterName,
        'current_page' => $page,
        'total_pages' => $totalPages,
        'offset' => $offset,
        'limit' => $limit,
        'pagination_needed' => $isNeeded,
        'previous_url' => $page > 1 ? pagination_page_url($parameterName, $page - 1, $baseQuery, $urlBuilder) : '',
        'next_url' => $page < $totalPages ? pagination_page_url($parameterName, $page + 1, $baseQuery, $urlBuilder) : '',
        'page_urls' => pagination_page_urls($parameterName, $page, $totalPages, $baseQuery, $urlBuilder),
    ];
}

/**
 * Return the current list sliced only when pagination is enabled and useful.
 *
 * @param array $items Items value.
 * @param array $pagination Pagination value.
 * @return array Structured result data for the caller.
 */
function pagination_slice_items(array $items, array $pagination): array
{
    if (empty($pagination['pagination_needed'])) {
        return $items;
    }
    return array_slice($items, (int) $pagination['offset'], (int) $pagination['limit']);
}

/**
 * Build a URL for one pagination page while preserving the active route query.
 *
 * @param string $parameterName Parameter name value.
 * @param int $pageNumber Page number value.
 * @param ?array $baseQuery Base query value.
 * @param ?callable $urlBuilder Url builder URL.
 * @return string Text result for the caller.
 */
function pagination_page_url(string $parameterName, int $pageNumber, ?array $baseQuery = null, ?callable $urlBuilder = null): string
{
    if ($urlBuilder !== null) {
        return (string) $urlBuilder($pageNumber);
    }

    // $query stores the current route and filter query without stale pagination state.
    $query = $baseQuery ?? $_GET;
    if ($pageNumber <= 1) {
        unset($query[$parameterName]);
    } else {
        $query[$parameterName] = (string) $pageNumber;
    }
    // $routePage stores the existing router value. It must remain named page.
    $routePage = (string) ($query['page'] ?? 'home');
    unset($query['page']);
    return url_for($routePage, $query);
}

/**
 * Return compact page-number URLs for the control bar.
 *
 * @param string $parameterName Parameter name value.
 * @param int $currentPage Current page value.
 * @param int $totalPages Total pages value.
 * @param ?array $baseQuery Base query value.
 * @param ?callable $urlBuilder Url builder URL.
 * @return array Structured result data for the caller.
 */
function pagination_page_urls(string $parameterName, int $currentPage, int $totalPages, ?array $baseQuery = null, ?callable $urlBuilder = null): array
{
    // $pages stores the candidate page numbers before deduplication and sorting.
    $pages = [1, $totalPages, $currentPage - 2, $currentPage - 1, $currentPage, $currentPage + 1, $currentPage + 2];
    $pages = array_values(array_unique(array_filter($pages, static fn (int $page): bool => $page >= 1 && $page <= $totalPages)));
    sort($pages);

    // $urls stores the compact page model used by the renderer.
    $urls = [];
    foreach ($pages as $page) {
        $urls[] = [
            'page' => $page,
            'url' => pagination_page_url($parameterName, $page, $baseQuery, $urlBuilder),
            'current' => $page === $currentPage,
        ];
    }
    return $urls;
}


/**
 * Return a grid column class matching the active pagination column setting.
 *
 * @param array $settings Settings used by this workflow.
 * @return string Text result for the caller.
 */
function pagination_grid_columns_class(array $settings): string
{
    if (empty($settings['enabled']) && empty($settings['grid_columns_enabled'])) {
        return '';
    }

    // $columns stores the safe configured column count used by CSS grid classes.
    $columns = pagination_dimension_value($settings['columns'] ?? CMS_PAGINATION_DEFAULT_COLUMNS, CMS_PAGINATION_DEFAULT_COLUMNS, CMS_PAGINATION_MAX_COLUMNS);
    return ' pagination-grid-columns-' . $columns;
}


/**
 * Return an initial sizes attribute for paginated photo thumbnails.
 *
 * The responsive renderer gives this hint directly to the browser with the full
 * candidate set during HTML parsing. The progressive renderer uses the same
 * conservative initial hint, then its optional near-viewport module measures the
 * actual rendered width before selecting a larger inert candidate.
 *
 * @param array $settings Settings used by this workflow.
 * @return string Text result for the caller.
 */
function pagination_photo_thumbnail_sizes_attribute(array $settings): string
{
    if (empty($settings['enabled'])) {
        return '(min-width: 70rem) 28vw, (min-width: 50rem) 34vw, 90vw';
    }

    // $columns stores the safe configured column count used by the responsive image hint.
    $columns = pagination_dimension_value($settings['columns'] ?? CMS_PAGINATION_DEFAULT_COLUMNS, CMS_PAGINATION_DEFAULT_COLUMNS, CMS_PAGINATION_MAX_COLUMNS);
    // $desktopGapAllowance stores a small viewport deduction for page padding and grid gaps.
    $desktopGapAllowance = max(2, min(12, $columns + 2));

    return '(min-width: 48rem) calc((' . (100 - $desktopGapAllowance) . 'vw) / ' . $columns . '), 94vw';
}


/**
 * Return a query suffix for clean pagination links while removing route state.
 *
 * @param array $removeNames Remove names value.
 * @return string Text result for the caller.
 */
function pagination_clean_query_suffix(array $removeNames = []): string
{
    // $query stores current GET parameters that should survive clean pagination links.
    $query = $_GET;
    foreach (array_merge(['page', 'public_path', 'gallery_path', 'slug', 'gallery_page', 'photo_page', CMS_PAGINATION_PARAM], $removeNames) as $name) {
        unset($query[$name]);
    }

    if ($query === []) {
        return '';
    }

    return '?' . http_build_query($query);
}

/**
 * Build a clean public gallery pagination URL.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param int $pageNumber Page number value.
 * @param string $listing Listing value.
 * @return string Text result for the caller.
 */
function pagination_gallery_clean_url(array $gallery, int $pageNumber, string $listing = 'photos'): string
{
    // $baseUrl stores the gallery URL without a trailing slash for path appending.
    $baseUrl = rtrim(gallery_public_url($gallery), '/');
    // $safePage stores the requested page number normalized for URL output.
    $safePage = max(1, $pageNumber);

    if ($safePage <= 1) {
        return $baseUrl . '/' . pagination_clean_query_suffix();
    }

    if ($listing === 'subgalleries') {
        return $baseUrl . '/galleries/' . $safePage . '/' . pagination_clean_query_suffix();
    }

    return $baseUrl . '/' . $safePage . '/' . pagination_clean_query_suffix();
}

/**
 * Build a clean home gallery-list pagination URL.
 *
 * @param int $pageNumber Page number value.
 * @return string Text result for the caller.
 */
function pagination_home_gallery_clean_url(int $pageNumber): string
{
    // $safePage stores the requested page number normalized for URL output.
    $safePage = max(1, $pageNumber);
    if ($safePage <= 1) {
        return public_base_url() . '/' . pagination_clean_query_suffix();
    }
    return public_base_url() . '/galleries/' . $safePage . '/' . pagination_clean_query_suffix();
}

/**
 * Render accessible public pagination controls for one listing.
 *
 * @param array $pagination Pagination value.
 * @param string $label Label value.
 */
function render_pagination_controls(array $pagination, string $label = ''): void
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
