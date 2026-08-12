<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/bootstrap/request.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Owns request route normalization, SEO guard enforcement, translation initialization, and security-header emission.
 *
 * Responsibilities:
 *   - Support shared project infrastructure
 *   - Keep behavior compatible with existing controllers and services
 *   - Avoid unnecessary coupling to presentation code
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

namespace Gallery\Core;

use function Gallery\Services\seo_request_guard_enforce;
use function Gallery\Services\translation_bootstrap_request;

/**
 * Resolve the route and initialize request-scoped behavior in the legacy startup order.
 *
 * @return string Resolved page identifier.
 */
function cms_initialize_request(): string
{
    // Variable $route stores this steps working value.
    $route = cms_route_from_request();
    // Variable $page stores this steps working value.
    $page = $route['page'];
    $_GET['page'] = $page;
    foreach ($route['params'] as $name => $value) {
        $_GET[$name] = $value;
    }
    if (function_exists('Gallery\\Services\\seo_request_guard_enforce')) {
        seo_request_guard_enforce($page);
    }
    translation_bootstrap_request($page);
    send_security_headers();

    return $page;
}