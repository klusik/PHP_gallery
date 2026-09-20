<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * Module Type: Controller
 * Purpose: Provide administrator-only destination search and a no-JavaScript directory.
 * Responsibilities:
 *   - Validate request inputs and render JSON or directory views from bounded service results.
 * File: app/controllers/admin_gallery_picker_search.php
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 * Administrator-only destination discovery, JSON and no-JavaScript directory.
 */
declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Core\current_user;
use function Gallery\Core\url_for;
use function Gallery\Services\gallery_picker_empty_page;
use function Gallery\Services\gallery_picker_search_page;
use function Gallery\Services\t;
use function Gallery\Views\view_render_gallery_picker_directory;
use const Gallery\Core\GALLERY_PICKER_JSON_MAX_BYTES;
use const Gallery\Core\GALLERY_PICKER_TITLE_MAX_CHARACTERS;

/**
 * Parse a bounded nonnegative destination-search request identifier.
 *
 * @param string|array<array-key,mixed> $value PHP-parsed query text or nested query array; all non-string values are rejected.
 * @return int Valid representable identifier, including zero for root/no cursor.
 */
function admin_gallery_picker_request_id(mixed $value): int
{
    if (!is_string($value) || preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/', $value) !== 1
        || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
        throw new \InvalidArgumentException('Invalid destination request.');
    }
    return (int) $value;
}

/**
 * Send a private bounded destination search without gallery mutations.
 *
 * @return void Emits JSON or an authenticated standalone directory document.
 */
function cms_admin_gallery_picker_search(): void
{
    clear_response_cache_headers();
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    $html = ($_GET['format'] ?? '') === 'html';
    header('Content-Type: ' . ($html ? 'text/html' : 'application/json') . '; charset=utf-8');
    $page = gallery_picker_empty_page();
    $status = 200;
    $query = '';
    $parameters = ['page' => 'admin_gallery_picker_search', 'format' => 'html',
        'excluded_id' => 0, 'exclude_descendants' => 0, 'allow_root' => 0];
    try {
        $user = current_user();
        if (!is_array($user) || ($user['role'] ?? '') !== 'admin') {
            $status = 401;
        } elseif (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            $status = 405;
            header('Allow: GET');
        } else {
            $rawQuery = $_GET['q'] ?? '';
            if (!is_string($rawQuery) || !in_array($_GET['format'] ?? 'json', ['json', 'html'], true)) {
                throw new \InvalidArgumentException('Invalid destination request.');
            }
            $afterId = admin_gallery_picker_request_id($_GET['after_id'] ?? '0');
            $selectedId = admin_gallery_picker_request_id($_GET['selected_id'] ?? '0');
            $parameters['excluded_id'] = admin_gallery_picker_request_id($_GET['excluded_id'] ?? '0');
            $parameters['exclude_descendants'] = admin_gallery_picker_request_id($_GET['exclude_descendants'] ?? '0');
            $parameters['allow_root'] = admin_gallery_picker_request_id($_GET['allow_root'] ?? '0');
            if ($parameters['exclude_descendants'] > 1 || $parameters['allow_root'] > 1) {
                throw new \InvalidArgumentException('Invalid destination request.');
            }
            $page = gallery_picker_search_page($rawQuery, $afterId, $selectedId,
                $parameters['excluded_id'], $parameters['exclude_descendants'] === 1);
            $query = $rawQuery;
        }
    } catch (\InvalidArgumentException) {
        $status = 400;
    } catch (\Throwable) {
        $status = 503;
        $page = gallery_picker_empty_page();
    }
    if ($html) {
        http_response_code($status);
        echo view_render_gallery_picker_directory([
            'ok' => $page['ok'], 'rows' => $page['rows'], 'query' => $query,
            'query_max_characters' => GALLERY_PICKER_TITLE_MAX_CHARACTERS,
            'allow_root' => $status === 200 && $parameters['allow_root'] === 1,
            'action_url' => url_for('admin_gallery_picker_search'),
            'parameters' => $parameters,
            'next_url' => $page['more'] ? url_for('admin_gallery_picker_search',
                $parameters + ['q' => $query, 'after_id' => $page['next_after_id']]) : '',
            'labels' => [
                'title' => t('gallery_picker.directory', 'Gallery directory'),
                'help' => t('gallery_picker.directory_help', 'Search or browse, then enter the chosen gallery ID in the form in your original tab. The full path distinguishes galleries with the same name.'),
                'query' => t('gallery_picker.placeholder', 'Search gallery by name or path'),
                'search' => t('gallery_picker.search', 'Search'),
                'id' => t('gallery_picker.id', 'Gallery ID'),
                'gallery' => t('gallery_picker.gallery', 'Gallery'),
                'path' => t('gallery_picker.path', 'Path'),
                'root' => t('admin.gallery_editor.no_parent', 'No parent'),
                'more' => t('gallery_picker.more', 'Next results'),
                'empty' => t('gallery_picker.no_results', 'No matching galleries found.'),
                'error' => t('gallery_picker.directory_error', 'The directory is unavailable. Check your administrator login and try again.'),
            ],
        ]);
        return;
    }
    // Decimal strings preserve BIGINT identity across JavaScript's number limit.
    foreach ($page['rows'] as &$row) {
        $row['id'] = (string) $row['id'];
    }
    unset($row);
    if ($page['selected'] !== null) {
        $page['selected']['id'] = (string) $page['selected']['id'];
    }
    if ($page['next_after_id'] !== null) {
        $page['next_after_id'] = (string) $page['next_after_id'];
    }
    $body = json_encode($page, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body) || strlen($body) > GALLERY_PICKER_JSON_MAX_BYTES) {
        $status = 503;
        $body = (string) json_encode(gallery_picker_empty_page());
    }
    http_response_code($status);
    echo $body;
}
