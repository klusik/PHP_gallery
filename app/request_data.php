<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/request_data.php
 * Module Type: Core HTTP Adapter
 *
 * Purpose:
 *   Provides read-only PHP request bags and response status for MVC layers that
 *   must keep transport globals and native response calls behind Core.
 *
 * Responsibilities:
 *   - Expose normalized query, post, cookie, file, server, and request bags
 *   - Observe the current response status without mutating its transport
 *   - Keep PHP transport globals behind one Core infrastructure boundary
 *   - Remain dependency-free so isolated service modules can load the adapter
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
 *   - This adapter performs no normalization beyond guaranteeing array values.
 *
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Core;

/**
 * Observe the current response status through the dependency-free Core HTTP adapter.
 * @return int Current HTTP status, or the ordinary 200 status when PHP has no explicit response code; never changes headers or status.
 */
function response_status_code(): int
{
    return http_response_code() ?: 200;
}

/**
 * Return one read-only request input bag through the Core HTTP adapter.
 *
 * MVC Services use this adapter instead of reading PHP request superglobals
 * directly. Controllers may still pass explicit request data where a use case
 * benefits from dependency injection, while low-level diagnostics can consume
 * the normalized request bag without owning transport globals themselves.
 *
 * @param string $source One of query, post, cookie, files, server, or request.
 * @return array<string,mixed> Request data for the selected source.
 */
function request_data(string $source): array
{
    return match ($source) {
        'query' => is_array($_GET) ? $_GET : [],
        'post' => is_array($_POST) ? $_POST : [],
        'cookie' => is_array($_COOKIE) ? $_COOKIE : [],
        'files' => is_array($_FILES) ? $_FILES : [],
        'server' => is_array($_SERVER) ? $_SERVER : [],
        'request' => is_array($_REQUEST) ? $_REQUEST : [],
        default => [],
    };
}
