<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_test_runs/context.php
 * Module Type: Service
 *
 * Purpose:
 *   Owns the active run cookie, ownership checks, and target normalization.
 *
 * Responsibilities:
 *   - Resolve whether the current request belongs to an active run
 *   - Set and clear the run cookie without widening its scope
 *   - Normalize recorded targets and restrict runs to their owning administrator
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
 *   - Loaded by app/services/admin_test_runs.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/admin_test_runs.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use ZipArchive;
use function Gallery\Core\cms_config;
use function Gallery\Core\current_user;
use function Gallery\Core\db;
use function Gallery\Core\url_for;

/**
 * Return the current opaque test-run token from the short-lived HttpOnly cookie.
 */
function admin_test_run_cookie_token(): string
{
    $token = strtolower(trim((string) ($GLOBALS['admin_test_run_request_transport']['cookie_token'] ?? '')));
    return admin_test_run_token_valid($token) ? $token : '';
}

/**
 * Bind request transport metadata prepared by the Core bootstrap boundary.
 *
 * @param array<string,mixed> $context Bounded request transport context.
 */
function admin_test_run_bind_request_transport_context(array $context): void
{
    $GLOBALS['admin_test_run_request_transport'] = [
        'cookie_token' => substr((string) ($context['cookie_token'] ?? ''), 0, 160),
        'request_time_float' => is_numeric($context['request_time_float'] ?? null) ? (float) $context['request_time_float'] : null,
        'request_uri' => substr((string) ($context['request_uri'] ?? ''), 0, 1200),
        'request_method' => substr((string) ($context['request_method'] ?? ''), 0, 16),
        'script_name' => substr((string) ($context['script_name'] ?? ''), 0, 500),
        'protocol' => substr((string) ($context['protocol'] ?? ''), 0, 40),
        'https' => !empty($context['https']),
        'query_keys' => array_values(array_slice(array_map('strval', is_array($context['query_keys'] ?? null) ? $context['query_keys'] : []), 0, 100)),
        'cookie_names' => array_values(array_slice(array_map('strval', is_array($context['cookie_names'] ?? null) ? $context['cookie_names'] : []), 0, 100)),
    ];
}

/**
 * Return the Core-prepared request transport metadata for test-run diagnostics.
 *
 * @return array<string,mixed>
 */
function admin_test_run_request_transport_context(): array
{
    return is_array($GLOBALS['admin_test_run_request_transport'] ?? null)
        ? $GLOBALS['admin_test_run_request_transport']
        : [];
}

/**
 * Return metadata for the active cookie context when it is still valid.
 *
 * @return array<string,mixed>|null
 */
function admin_test_run_active_context(): ?array
{
    static $cachedToken = null;
    static $cachedContext = null;
    $token = admin_test_run_cookie_token();
    if ($token === '') {
        return null;
    }
    if ($cachedToken === $token) {
        return is_array($cachedContext) ? $cachedContext : null;
    }
    $cachedToken = $token;
    $meta = admin_test_run_read_json(admin_test_run_meta_path($token));
    $createdAt = (int) ($meta['created_at_unix'] ?? 0);
    $finalized = !empty($meta['finalized_at']);
    if ($createdAt <= 0 || time() - $createdAt > ADMIN_TEST_RUN_TTL_SECONDS || $finalized) {
        $cachedContext = null;
        return null;
    }
    $cachedContext = $meta;
    return $meta;
}

/**
 * Return whether detailed request instrumentation is active for this request.
 */
function admin_test_run_active(): bool
{
    return admin_test_run_active_context() !== null;
}

/**
 * Normalize a local request target and remove previous test-run control parameters.
 */
function admin_test_run_normalize_target(string $target): string
{
    $target = trim($target);
    if ($target === '' || str_contains($target, "\r") || str_contains($target, "\n")) {
        return '/';
    }
    $parts = parse_url($target);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return '/';
    }
    $path = (string) ($parts['path'] ?? '/');
    if ($path === '' || $path[0] !== '/') {
        $path = '/';
    }
    parse_str((string) ($parts['query'] ?? ''), $query);
    foreach (['test_run_token', 'test_run_cache_bust', 'test_run_phase', 'test_run_starter_request_id'] as $key) {
        unset($query[$key]);
    }
    $result = $path;
    if ($query) {
        $result .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    if (!empty($parts['fragment'])) {
        $result .= '#' . rawurlencode((string) $parts['fragment']);
    }
    return $result;
}

/**
 * Append query parameters to one normalized local target.
 *
 * @param array<string,string|int> $params Query parameters.
 */
function admin_test_run_target_with_params(string $target, array $params): string
{
    $target = admin_test_run_normalize_target($target);
    $fragment = '';
    $fragmentPos = strpos($target, '#');
    if ($fragmentPos !== false) {
        $fragment = substr($target, $fragmentPos);
        $target = substr($target, 0, $fragmentPos);
    }
    $separator = str_contains($target, '?') ? '&' : '?';
    return $target . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986) . $fragment;
}

/**
 * Return whether the current authenticated Admin owns one Test Run metadata/report payload.
 *
 * @param array<string,mixed> $payload Metadata or report payload.
 */
function admin_test_run_owned_by_current_admin(array $payload): bool
{
    $user = current_user();
    return is_array($user)
        && (string) ($user['role'] ?? '') === 'admin'
        && (int) ($user['id'] ?? 0) > 0
        && (int) ($payload['admin']['id'] ?? 0) === (int) ($user['id'] ?? 0);
}

/**
 * Persist starter request correlation after the authenticated starter trace is adopted.
 */
function admin_test_run_set_starter_request_id(string $token, string $requestId): void
{
    $meta = admin_test_run_read_json(admin_test_run_meta_path($token));
    if (!$meta || !preg_match('/^[a-z0-9_.:-]{8,160}$/iD', $requestId)) {
        return;
    }
    $meta['starter_request_id'] = $requestId;
    $meta['events'][] = ['at' => gmdate('c'), 'type' => 'starter_request_correlated', 'request_id' => $requestId];
    admin_test_run_write_json(admin_test_run_meta_path($token), $meta);
}

/**
 * Set the short-lived HttpOnly cookie that makes same-origin PHP subrequests join one run.
 */
function admin_test_run_set_cookie(string $token): array
{
    return [[
        'name' => ADMIN_TEST_RUN_COOKIE,
        'value' => $token,
        'expires' => time() + ADMIN_TEST_RUN_TTL_SECONDS,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]];
}

/**
 * Expire the active diagnostic context cookie.
 */
function admin_test_run_clear_cookie(): array
{
    return [[
        'name' => ADMIN_TEST_RUN_COOKIE,
        'value' => '',
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]];
}
