<?php

/**
 * Project: PHP Gallery
 * Purpose: Provide administrator-only title completion for create-gallery forms.
 * Responsibilities:
 *   - Validate HTTP inputs and frame JSON while delegating matching and SQL.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/admin_gallery_title_completion.php
 * Module Type: Controller
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 *
 * Administrator-only, read-only title suggestions for the create-gallery form.
 * HTTP validation and JSON framing stay here; matching and SQL belong below.
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Core\current_user;
use function Gallery\Services\gallery_title_completion_candidates;
use function Gallery\Services\gallery_title_completion_empty_result;

/** Send a bounded optional suggestion response without HTML/login redirects. */
function cms_admin_gallery_title_completion(): void
{
    clear_response_cache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');

    $result = gallery_title_completion_empty_result(false);
    $status = 200;
    try {
        $user = current_user();
        if (!is_array($user) || ($user['role'] ?? '') !== 'admin') {
            $status = 401;
        } elseif (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            $status = 405;
            header('Allow: GET');
        } else {
            $query = $_GET['q'] ?? '';
            $parent = $_GET['parent_id'] ?? '0';
            if (!is_string($query) || !is_string($parent)
                || preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/', $parent) !== 1
                || filter_var($parent, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
                throw new \InvalidArgumentException('Invalid title completion request.');
            }
            $result = gallery_title_completion_candidates($query, (int) $parent);
            $status = $result['ok'] ? 200 : 503;
        }
    } catch (\InvalidArgumentException) {
        $status = 400;
    } catch (\Throwable) {
        $status = 503;
        $result = gallery_title_completion_empty_result(false, true);
    }

    $body = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body) || strlen($body) > 16384) {
        $status = 503;
        $body = (string) json_encode(gallery_title_completion_empty_result(false, true));
    }
    http_response_code($status);
    echo $body;
}
