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
    $token = strtolower(trim((string) ($_COOKIE[ADMIN_TEST_RUN_COOKIE] ?? '')));
    return admin_test_run_token_valid($token) ? $token : '';
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
function admin_test_run_set_cookie(string $token): void
{
    setcookie(ADMIN_TEST_RUN_COOKIE, $token, [
        'expires' => time() + ADMIN_TEST_RUN_TTL_SECONDS,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[ADMIN_TEST_RUN_COOKIE] = $token;
}

/**
 * Expire the active diagnostic context cookie.
 */
function admin_test_run_clear_cookie(): void
{
    setcookie(ADMIN_TEST_RUN_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[ADMIN_TEST_RUN_COOKIE]);
}
