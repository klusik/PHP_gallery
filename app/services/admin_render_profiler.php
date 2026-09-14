<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_render_profiler.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides admin-only request profiling for the main admin dashboard.
 *
 * Responsibilities:
 *   - Measure first-load admin dashboard timings without changing behavior
 *   - Count selected database, schema, maintenance, and render operations
 *   - Render a compact diagnostic panel for logged-in administrators
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
 *   2026-05-11
 */

declare(strict_types=1);

namespace Gallery\Services;

use function Gallery\Core\request_data;

use function Gallery\Core\current_user;

/**
 * Return whether the current request should collect admin dashboard profiling data.
 *
 * @return bool True when the condition matches.
 */
function admin_render_profile_enabled(): bool
{
    if (PHP_SAPI === 'cli') {
        return false;
    }
    if (!function_exists('Gallery\\Core\\current_user')) {
        return false;
    }
    if (function_exists(__NAMESPACE__ . '\\dev_mode_enabled') && !dev_mode_enabled()) {
        return false;
    }
    return current_user() !== null && (string) (request_data('query')['page'] ?? '') === 'admin';
}

/**
 * Return the mutable admin profiler state for this request.
 *
 * @return array Structured result data for the caller.
 */
function &admin_render_profile_state(): array
{
    static $state = null;
    if ($state === null) {
        $state = [
            'started_at' => microtime(true),
            'route' => '',
            'counters' => [
                'schema_checks' => 0,
                'db_queries' => 0,
                'app_setting_reads' => 0,
                'app_setting_writes' => 0,
                'gallery_rows' => 0,
                'ordered_gallery_rows' => 0,
                'collapsed_gallery_ids' => 0,
                'parent_groups' => 0,
                'thumbnail_maintenance_sample_limit' => 0,
                'thumbnail_missing_variants' => 0,
                'rendered_gallery_rows' => 0,
                'preview_requests' => 0,
                'preview_cover_asset_hits' => 0,
                'preview_direct_cover_hits' => 0,
                'preview_collage_cover_hits' => 0,
                'preview_empty' => 0,
            ],
            'timers' => [],
            'events' => [],
        ];
    }
    return $state;
}

/**
 * Start one named admin dashboard profile request.
 *
 * @param string $route Route value.
 */
function admin_render_profile_start(string $route): void
{
    if (!admin_render_profile_enabled()) {
        return;
    }
    $state =& admin_render_profile_state();
    $state['started_at'] = microtime(true);
    $state['route'] = $route;
}

/**
 * Add to a named admin dashboard profile counter.
 *
 * @param string $counter Counter value.
 * @param int $amount Amount value.
 */
function admin_render_profile_count(string $counter, int $amount = 1): void
{
    if (!admin_render_profile_enabled()) {
        return;
    }
    $state =& admin_render_profile_state();
    if (!array_key_exists($counter, $state['counters'])) {
        $state['counters'][$counter] = 0;
    }
    $state['counters'][$counter] += $amount;
}

/**
 * Set one admin dashboard profile counter to an exact value.
 *
 * @param string $counter Counter value.
 * @param int $value Value to process.
 */
function admin_render_profile_set_counter(string $counter, int $value): void
{
    if (!admin_render_profile_enabled()) {
        return;
    }
    $state =& admin_render_profile_state();
    $state['counters'][$counter] = $value;
}

/**
 * Add elapsed time to one named admin dashboard timer.
 *
 * @param string $timer Timer value.
 * @param float $elapsedMs Elapsed ms value.
 */
function admin_render_profile_add_time(string $timer, float $elapsedMs): void
{
    if (!admin_render_profile_enabled()) {
        return;
    }
    $state =& admin_render_profile_state();
    if (!isset($state['timers'][$timer])) {
        $state['timers'][$timer] = [
            'count' => 0,
            'total_ms' => 0.0,
            'max_ms' => 0.0,
        ];
    }
    $state['timers'][$timer]['count']++;
    $state['timers'][$timer]['total_ms'] += $elapsedMs;
    $state['timers'][$timer]['max_ms'] = max($state['timers'][$timer]['max_ms'], $elapsedMs);
}

/**
 * Measure one callback and return its result unchanged.
 *
 * @param string $timer Timer value.
 * @param callable():T $callback Callback invoked by this workflow.
 * @return T Result value for the caller.
 * @template T
 */
function admin_render_profile_span(string $timer, callable $callback)
{
    if (!admin_render_profile_enabled()) {
        return $callback();
    }
    $startedAt = microtime(true);
    try {
        return $callback();
    } finally {
        admin_render_profile_add_time($timer, (microtime(true) - $startedAt) * 1000);
    }
}

/**
 * Measure one database callback, count it, and return its result unchanged.
 *
 * @param string $timer Timer value.
 * @param callable():T $callback Callback invoked by this workflow.
 * @return T Result value for the caller.
 * @template T
 */
function admin_render_profile_db(string $timer, callable $callback)
{
    if (!admin_render_profile_enabled()) {
        return $callback();
    }
    $startedAt = microtime(true);
    try {
        return $callback();
    } finally {
        $elapsedMs = (microtime(true) - $startedAt) * 1000;
        admin_render_profile_count('db_queries');
        admin_render_profile_add_time('db_query', $elapsedMs);
        admin_render_profile_add_time($timer, $elapsedMs);
    }
}

/**
 * Measure one schema readiness callback, count it, and return its result unchanged.
 *
 * @param string $timer Timer value.
 * @param callable():T $callback Callback invoked by this workflow.
 * @return T Result value for the caller.
 * @template T
 */
function admin_render_profile_schema(string $timer, callable $callback)
{
    admin_render_profile_count('schema_checks');
    return admin_render_profile_span($timer, $callback);
}

/**
 * Measure one app-setting read callback, count it, and return its result unchanged.
 *
 * @param string $timer Timer value.
 * @param callable():T $callback Callback invoked by this workflow.
 * @return T Result value for the caller.
 * @template T
 */
function admin_render_profile_setting_read(string $timer, callable $callback)
{
    admin_render_profile_count('app_setting_reads');
    return admin_render_profile_span($timer, $callback);
}

/**
 * Measure one app-setting write callback, count it, and return its result unchanged.
 *
 * @param string $timer Timer value.
 * @param callable():T $callback Callback invoked by this workflow.
 * @return T Result value for the caller.
 * @template T
 */
function admin_render_profile_setting_write(string $timer, callable $callback)
{
    admin_render_profile_count('app_setting_writes');
    return admin_render_profile_span($timer, $callback);
}

/**
 * Build the admin-only dashboard profile diagnostic view model.
 *
 * @return array<string, mixed>|null Prepared profile data, or null when profiling is disabled.
 */
function admin_render_profile_panel_model(): ?array
{
    if (!admin_render_profile_enabled()) {
        return null;
    }
    $state =& admin_render_profile_state();
    $totalMs = (microtime(true) - (float) $state['started_at']) * 1000;
    $state['timers']['total_request'] = [
        'count' => 1,
        'total_ms' => $totalMs,
        'max_ms' => $totalMs,
    ];

    $timers = $state['timers'];
    uasort($timers, static fn (array $left, array $right): int => $right['total_ms'] <=> $left['total_ms']);

    return [
        'route' => (string) $state['route'],
        'counters' => $state['counters'],
        'timers' => $timers,
    ];
}
