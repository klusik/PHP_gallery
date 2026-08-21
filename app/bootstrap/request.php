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
use function Gallery\Services\viewer_remember_restore_from_cookie;

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
    // Restore only the dedicated viewer persistent credential before response cache classification.
    // The adapter is fail-closed and never mutates administrator identity.
    viewer_remember_restore_from_cookie();
    send_security_headers();

    return $page;
}

/**
 * Prime one bounded schema-capability snapshot for high-fanout media requests.
 *
 * Thumbnail/media controllers repeatedly inspect a small set of authentication,
 * public-path, access-policy, NSFW, Viewer, and thumbnail-metadata tables. One
 * information_schema query after the session lock has been released is cheaper
 * and safer under shared-host latency than dozens of independent metadata
 * round-trips per thumbnail. Failure is non-fatal and existing per-object
 * inspection remains the fallback.
 *
 * @param string $page Resolved page identifier.
 */
function cms_prime_read_only_media_schema_cache(string $page): void
{
    if (!cms_route_is_read_only_media_asset($page)
        || !function_exists('Gallery\\Services\\schema_inspection_prime_table_snapshots')) {
        return;
    }

    \Gallery\Services\schema_inspection_prime_table_snapshots([
        'galleries',
        'images',
        'image_thumbnail_variants',
        'users',
        'admin_remember_tokens',
        'viewer_accounts',
        'viewer_account_state',
        'viewer_sessions',
        'viewer_remember_tokens',
        'viewer_password_reset_tokens',
        'viewer_security_events',
        'viewer_rate_limit_buckets',
        'viewer_rate_limits',
        'viewer_invitations',
        'viewer_registration_state',
        'viewer_registration_requests',
    ]);
}
/**
 * Start optional request diagnostics before the normal CMS lifecycle marks begin.
 *
 * The helper keeps app/bootstrap.php as a thin coordinator while allowing both the
 * existing gallery benchmark and the opt-in Admin full test run to observe the
 * same lifecycle boundaries.
 */
function cms_request_trace_begin(): void
{
    if (function_exists('Gallery\\Services\\admin_test_run_request_begin')) {
        \Gallery\Services\admin_test_run_request_begin();
    }
    cms_request_trace_mark('cms_run_enter');
}

/**
 * Mirror one lifecycle mark into every request diagnostics collector that is active.
 *
 * @param array<string,mixed> $context Structured non-secret mark context.
 */
function cms_request_trace_mark(string $name, array $context = []): void
{
    if (function_exists('Gallery\\Diagnostics\\admin_test_run_early_mark')) {
        \Gallery\Diagnostics\admin_test_run_early_mark($name, $context);
    }
    if (function_exists('Gallery\\Services\\admin_test_run_mark')) {
        \Gallery\Services\admin_test_run_mark($name, $context);
    }
    if (function_exists('Gallery\\Services\\gallery_benchmark_trace_mark')) {
        \Gallery\Services\gallery_benchmark_trace_mark($name, $context);
    }
}
