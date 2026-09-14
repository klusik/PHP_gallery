<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/public_render_profiler.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides admin-only request profiling for public gallery rendering.
 *
 * Responsibilities:
 *   - Measure public render timings without changing visitor behavior
 *   - Count selected database, filesystem, thumbnail, and scan operations
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
 *   2026-05-10
 */

declare(strict_types=1);

namespace Gallery\Services;

use function Gallery\Core\request_data;

use function Gallery\Core\asset_url;
use function Gallery\Core\current_user;
use function Gallery\Core\csrf_token;
use function Gallery\Core\url_for;

/**
 * Return whether the current request should collect public render profiling data.
 *
 * @return bool True when the condition matches.
 */
function public_render_profile_enabled(): bool
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
    return current_user() !== null;
}

/**
 * Return the mutable profiler state for this request.
 *
 * @return array Structured result data for the caller.
 */
function &public_render_profile_state(): array
{
    static $state = null;
    if ($state === null) {
        $state = [
            'started_at' => microtime(true),
            'route' => '',
            'gallery_id' => null,
            'counters' => [
                'db_queries' => 0,
                'filesystem_checks' => 0,
                'thumbnail_lookups' => 0,
                'thumbnail_direct_hits' => 0,
                'thumbnail_db_fallback_hits' => 0,
                'thumbnail_fallback_searches' => 0,
                'thumbnail_fallback_checks' => 0,
                'thumbnail_fallback_hits' => 0,
                'thumbnail_media_fallbacks' => 0,
                'thumbnail_bundle_requests' => 0,
                'thumbnail_bundle_cache_hits' => 0,
                'thumbnail_bundle_cache_misses' => 0,
                'thumbnail_bundle_variant_hits' => 0,
                'thumbnail_bundle_fallback_hits' => 0,
                'thumbnail_bundle_media_fallbacks' => 0,
                'gallery_scan_calls' => 0,
                'gallery_map_cache_hits' => 0,
                'gallery_map_cache_misses' => 0,
                'thumbnail_lookup_cache_hits' => 0,
                'rendered_subgalleries' => 0,
                'rendered_images' => 0,
            ],
            'timers' => [],
            'events' => [],
            'thumbnail_purpose_stack' => [],
            'thumbnail_purposes' => [],
        ];
    }
    return $state;
}

/**
 * Start a named public render profile request.
 *
 * @param string $route Route value.
 * @param ?int $galleryId Gallery identifier.
 */
function public_render_profile_start(string $route, ?int $galleryId = null): void
{
    if (!public_render_profile_enabled()) {
        return;
    }
    $state =& public_render_profile_state();
    $state['started_at'] = microtime(true);
    $state['route'] = $route;
    $state['gallery_id'] = $galleryId;
}

/**
 * Set or update the current gallery id after the route has resolved it.
 *
 * @param ?int $galleryId Gallery identifier.
 */
function public_render_profile_set_gallery(?int $galleryId): void
{
    if (!public_render_profile_enabled()) {
        return;
    }
    $state =& public_render_profile_state();
    $state['gallery_id'] = $galleryId;
}

/**
 * Add to a named public render profile counter.
 *
 * @param string $counter Counter value.
 * @param int $amount Amount value.
 */
function public_render_profile_count(string $counter, int $amount = 1): void
{
    if (!public_render_profile_enabled()) {
        return;
    }
    $state =& public_render_profile_state();
    if (!array_key_exists($counter, $state['counters'])) {
        $state['counters'][$counter] = 0;
    }
    $state['counters'][$counter] += $amount;
}

/**
 * Return the active thumbnail lookup purpose label for nested public render operations.
 *
 * @return string Text result for the caller.
 */
function public_render_profile_thumbnail_purpose(): string
{
    if (!public_render_profile_enabled()) {
        return 'unprofiled';
    }
    $state =& public_render_profile_state();
    $stack = $state['thumbnail_purpose_stack'] ?? [];
    if (!$stack) {
        return 'unlabeled';
    }
    return (string) end($stack);
}

/**
 * Run one callback with a thumbnail lookup purpose label.
 *
 * @param string $purpose Purpose value.
 * @param callable():T $callback Callback invoked by this workflow.
 * @return T Result value for the caller.
 * @template T
 */
function public_render_profile_with_thumbnail_purpose(string $purpose, callable $callback)
{
    if (!public_render_profile_enabled()) {
        return $callback();
    }
    $state =& public_render_profile_state();
    $state['thumbnail_purpose_stack'][] = $purpose;
    try {
        return $callback();
    } finally {
        array_pop($state['thumbnail_purpose_stack']);
    }
}

/**
 * Record a thumbnail lookup under the current or explicit purpose label.
 *
 * @param ?string $purpose Purpose value.
 * @param int $size Size value.
 * @param string $format Format value.
 * @param string $kind Kind value.
 * @param float $elapsedMs Elapsed ms value.
 */
function public_render_profile_record_thumbnail_purpose(?string $purpose, int $size, string $format, string $kind, float $elapsedMs = 0.0): void
{
    if (!public_render_profile_enabled()) {
        return;
    }
    $state =& public_render_profile_state();
    $label = trim((string) ($purpose ?: public_render_profile_thumbnail_purpose()));
    if ($label === '') {
        $label = 'unlabeled';
    }
    $format = $format === 'webp' ? 'webp' : 'jpg';
    $key = $label . ' | ' . (int) $size . ' | ' . $format;
    if (!isset($state['thumbnail_purposes'][$key])) {
        $state['thumbnail_purposes'][$key] = [
            'purpose' => $label,
            'size' => (int) $size,
            'format' => $format,
            'calls' => 0,
            'cache_hits' => 0,
            'bundle_calls' => 0,
            'total_ms' => 0.0,
            'max_ms' => 0.0,
        ];
    }
    if ($kind === 'cache_hit') {
        $state['thumbnail_purposes'][$key]['cache_hits']++;
    } elseif ($kind === 'bundle') {
        $state['thumbnail_purposes'][$key]['bundle_calls']++;
    } else {
        $state['thumbnail_purposes'][$key]['calls']++;
    }
    if ($elapsedMs > 0.0) {
        $state['thumbnail_purposes'][$key]['total_ms'] += $elapsedMs;
        $state['thumbnail_purposes'][$key]['max_ms'] = max($state['thumbnail_purposes'][$key]['max_ms'], $elapsedMs);
    }
}

/**
 * Add elapsed time to one named timer.
 *
 * @param string $timer Timer value.
 * @param float $elapsedMs Elapsed ms value.
 */
function public_render_profile_add_time(string $timer, float $elapsedMs): void
{
    if (!public_render_profile_enabled()) {
        return;
    }
    $state =& public_render_profile_state();
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
function public_render_profile_span(string $timer, callable $callback)
{
    if (!public_render_profile_enabled()) {
        return $callback();
    }
    $startedAt = microtime(true);
    try {
        return $callback();
    } finally {
        public_render_profile_add_time($timer, (microtime(true) - $startedAt) * 1000);
    }
}

/**
 * Record a database query duration and query count.
 *
 * @param float $elapsedMs Elapsed ms value.
 */
function public_render_profile_record_db(float $elapsedMs): void
{
    public_render_profile_count('db_queries');
    public_render_profile_add_time('db_query', $elapsedMs);
}

/**
 * Measure one database callback and return its result unchanged.
 *
 * @param string $timer Timer value.
 * @param callable():T $callback Callback invoked by this workflow.
 * @return T Result value for the caller.
 * @template T
 */
function public_render_profile_db(string $timer, callable $callback)
{
    if (!public_render_profile_enabled()) {
        return $callback();
    }
    $startedAt = microtime(true);
    try {
        return $callback();
    } finally {
        $elapsedMs = (microtime(true) - $startedAt) * 1000;
        public_render_profile_record_db($elapsedMs);
        public_render_profile_add_time($timer, $elapsedMs);
    }
}

/**
 * Record one filesystem existence check duration.
 *
 * @param float $elapsedMs Elapsed ms value.
 */
function public_render_profile_record_filesystem_check(float $elapsedMs): void
{
    public_render_profile_count('filesystem_checks');
    public_render_profile_add_time('filesystem_check', $elapsedMs);
}

/**
 * Measure is_file() while profiling public render filesystem pressure.
 *
 * @param string $path Filesystem path.
 * @return bool True when the condition matches.
 */
function public_render_profile_is_file(string $path): bool
{
    if (!public_render_profile_enabled()) {
        return is_file($path);
    }
    $startedAt = microtime(true);
    try {
        return is_file($path);
    } finally {
        public_render_profile_record_filesystem_check((microtime(true) - $startedAt) * 1000);
    }
}


/**
 * Return a structured snapshot of the current public render profile state.
 *
 * The snapshot is safe to store in benchmark logs because it contains counters,
 * timers, memory usage, and request shape only. It does not include rendered HTML
 * or private photo data.
 *
 * @return array<string, mixed> Structured result data for the caller.
 */
function public_render_profile_snapshot(): array
{
    if (!public_render_profile_enabled()) {
        return [];
    }
    $state =& public_render_profile_state();
    $endedAt = microtime(true);
    $totalMs = ($endedAt - (float) $state['started_at']) * 1000;
    $timers = $state['timers'];
    $timers['total_request'] = [
        'count' => 1,
        'total_ms' => $totalMs,
        'max_ms' => $totalMs,
    ];
    $thumbnailPurposes = $state['thumbnail_purposes'] ?? [];
    uasort($timers, static fn (array $left, array $right): int => $right['total_ms'] <=> $left['total_ms']);
    if ($thumbnailPurposes) {
        uasort($thumbnailPurposes, static function (array $left, array $right): int {
            return (($right['total_ms'] ?? 0.0) <=> ($left['total_ms'] ?? 0.0))
                ?: (($right['calls'] ?? 0) <=> ($left['calls'] ?? 0));
        });
    }

    return [
        'route' => (string) $state['route'],
        'gallery_id' => $state['gallery_id'],
        'started_at_unix' => (float) $state['started_at'],
        'ended_at_unix' => $endedAt,
        'total_ms' => $totalMs,
        'memory_usage_bytes' => memory_get_usage(true),
        'memory_peak_bytes' => memory_get_peak_usage(true),
        'included_file_count' => count(get_included_files()),
        'counters' => $state['counters'],
        'timers' => $timers,
        'thumbnail_purposes' => array_values($thumbnailPurposes),
        'events' => $state['events'] ?? [],
        'request' => [
            'method' => (string) (request_data('server')['REQUEST_METHOD'] ?? ''),
            'request_uri' => (string) (request_data('server')['REQUEST_URI'] ?? ''),
            'query_keys' => array_values(array_map('strval', array_keys(request_data('query')))),
        ],
    ];
}

/**
 * Build the admin-only public render profile diagnostic view model.
 *
 * @return array<string, mixed>|null Prepared panel data, or null when diagnostics are disabled.
 */
function public_render_profile_panel_model(): ?array
{
    if (!public_render_profile_enabled()) {
        return null;
    }
    if (isset(request_data('query')['benchmark_token'])) {
        return null;
    }

    $snapshot = public_render_profile_snapshot();
    if (function_exists(__NAMESPACE__ . '\\admin_test_run_active') && admin_test_run_active()
        && function_exists(__NAMESPACE__ . '\\admin_test_run_record_component')) {
        admin_test_run_record_component('public_render_profile', $snapshot);
    }

    // $thumbnailRenderingMode exposes only the validated machine mode to the admin-only browser diagnostics panel.
    $thumbnailRenderingMode = (string) ($snapshot['route'] ?? '') === 'gallery' && function_exists('Gallery\\Services\\public_thumbnail_rendering_mode')
        ? public_thumbnail_rendering_mode()
        : '';

    $benchmark = null;
    $galleryId = (int) ($snapshot['gallery_id'] ?? 0);
    if ((string) ($snapshot['route'] ?? '') === 'gallery' && $galleryId > 0) {
        $benchmark = [
            'gallery_id' => $galleryId,
            'csrf_token' => csrf_token(),
            'start_url' => url_for('admin_gallery_benchmark_start'),
            'browser_url' => url_for('admin_gallery_benchmark_browser'),
            'status_url' => url_for('admin_gallery_benchmark_status'),
            'php_probe_url' => url_for('admin_gallery_benchmark_probe'),
            'static_probe_url' => asset_url('assets/gallery-benchmark-static-probe.txt'),
        ];
    }

    return [
        'snapshot' => $snapshot,
        'thumbnail_rendering_mode' => $thumbnailRenderingMode,
        'benchmark' => $benchmark,
    ];
}
