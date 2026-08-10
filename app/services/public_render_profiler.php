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

use function Gallery\Core\current_user;
use function Gallery\Core\csrf_token;
use function Gallery\Core\e;
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
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            'request_uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            'query_keys' => array_values(array_map('strval', array_keys($_GET))),
        ],
    ];
}

/**
 * Render the admin-only public render profile diagnostic panel.
 */
function render_public_render_profile_panel(): void
{
    if (!public_render_profile_enabled()) {
        return;
    }
    if (isset($_GET['benchmark_token'])) {
        return;
    }
    $snapshot = public_render_profile_snapshot();
    $counters = isset($snapshot['counters']) && is_array($snapshot['counters']) ? $snapshot['counters'] : [];
    $timers = isset($snapshot['timers']) && is_array($snapshot['timers']) ? $snapshot['timers'] : [];
    // $thumbnailRenderingMode exposes only the validated machine mode to the admin-only browser diagnostics panel.
    $thumbnailRenderingMode = (string) ($snapshot['route'] ?? '') === 'gallery' && function_exists('Gallery\Services\public_thumbnail_rendering_mode')
        ? public_thumbnail_rendering_mode()
        : '';

    echo '<details class="public-render-profile" data-public-render-profile>'; 
    echo '<summary>' . e(t('dev.public_render_profile.title', 'Public render profile'));
    if ((string) ($snapshot['route'] ?? '') !== '') {
        echo ' · ' . e((string) $snapshot['route']);
    }
    if (($snapshot['gallery_id'] ?? null) !== null) {
        echo ' · ' . e(t('dev.public_render_profile.gallery_number', 'gallery #{id}', ['id' => (string) $snapshot['gallery_id']]));
    }
    echo '</summary>';
    echo '<div class="public-render-profile-grid">';
    echo '<section><h2>' . e(t('dev.public_render_profile.counters', 'Counters')) . '</h2><table><tbody>';
    foreach ($counters as $name => $value) {
        echo '<tr><th>' . e(str_replace('_', ' ', (string) $name)) . '</th><td>' . number_format((float) $value, 0, '.', ' ') . '</td></tr>';
    }
    echo '</tbody></table></section>';
    echo '<section><h2>' . e(t('dev.public_render_profile.timers', 'Timers')) . '</h2><table><thead><tr><th>' . e(t('dev.public_render_profile.name', 'Name')) . '</th><th>' . e(t('dev.public_render_profile.count', 'Count')) . '</th><th>' . e(t('dev.public_render_profile.total_ms', 'Total ms')) . '</th><th>' . e(t('dev.public_render_profile.max_ms', 'Max ms')) . '</th></tr></thead><tbody>';
    foreach ($timers as $name => $timer) {
        echo '<tr><th>' . e(str_replace('_', ' ', (string) $name)) . '</th><td>' . (int) $timer['count'] . '</td><td>' . number_format((float) $timer['total_ms'], 2, '.', ' ') . '</td><td>' . number_format((float) $timer['max_ms'], 2, '.', ' ') . '</td></tr>';
    }
    echo '</tbody></table></section>';
    $thumbnailPurposes = isset($snapshot['thumbnail_purposes']) && is_array($snapshot['thumbnail_purposes']) ? $snapshot['thumbnail_purposes'] : [];
    if ($thumbnailPurposes) {
        echo '<section class="public-render-profile-wide"><h2>' . e(t('dev.public_render_profile.thumbnail_lookup_purposes', 'Thumbnail lookup purposes')) . '</h2><table><thead><tr><th>' . e(t('dev.public_render_profile.purpose', 'Purpose')) . '</th><th>' . e(t('dev.public_render_profile.size', 'Size')) . '</th><th>' . e(t('dev.public_render_profile.format', 'Format')) . '</th><th>' . e(t('dev.public_render_profile.lookups', 'Lookups')) . '</th><th>' . e(t('dev.public_render_profile.cache_hits', 'Cache hits')) . '</th><th>' . e(t('dev.public_render_profile.bundle_calls', 'Bundle calls')) . '</th><th>' . e(t('dev.public_render_profile.total_ms', 'Total ms')) . '</th><th>' . e(t('dev.public_render_profile.max_ms', 'Max ms')) . '</th></tr></thead><tbody>';
        foreach ($thumbnailPurposes as $row) {
            echo '<tr><th>' . e((string) $row['purpose']) . '</th><td>' . (int) $row['size'] . '</td><td>' . e((string) $row['format']) . '</td><td>' . (int) $row['calls'] . '</td><td>' . (int) $row['cache_hits'] . '</td><td>' . (int) $row['bundle_calls'] . '</td><td>' . number_format((float) $row['total_ms'], 2, '.', ' ') . '</td><td>' . number_format((float) $row['max_ms'], 2, '.', ' ') . '</td></tr>';
        }
        echo '</tbody></table></section>';
    }
    if ($thumbnailRenderingMode !== '') {
        echo '<section class="public-render-profile-wide public-thumbnail-diagnostics" data-public-thumbnail-diagnostics data-thumbnail-rendering-mode="' . e($thumbnailRenderingMode) . '" data-gallery-id="' . (int) ($snapshot['gallery_id'] ?? 0) . '" data-server-total-ms="' . e(number_format((float) ($snapshot['total_ms'] ?? 0.0), 4, '.', '')) . '">';
        echo '<div class="public-thumbnail-diagnostics-heading"><div><h2>' . e(t('dev.public_render_profile.thumbnail_renderer_diagnostics', 'Thumbnail renderer diagnostics')) . '</h2><p class="public-thumbnail-diagnostics-help">' . e(t('dev.public_render_profile.thumbnail_renderer_diagnostics_help', 'Live admin-only browser measurements for comparing responsive and progressive loads. Clear the browser cache the same way before each comparison.')) . '</p></div><button type="button" class="button secondary" data-public-thumbnail-diagnostics-copy data-copy-label="' . e(t('dev.public_render_profile.copy_thumbnail_report', 'Copy thumbnail report')) . '" data-copied-label="' . e(t('dev.public_render_profile.thumbnail_report_copied', 'Copied')) . '">' . e(t('dev.public_render_profile.copy_thumbnail_report', 'Copy thumbnail report')) . '</button></div>';
        echo '<textarea class="public-thumbnail-diagnostics-report" rows="24" readonly spellcheck="false" data-public-thumbnail-diagnostics-report>' . e(t('dev.public_render_profile.thumbnail_report_loading', 'Collecting browser thumbnail measurements...')) . '</textarea>';
        echo '<p class="public-thumbnail-diagnostics-help">' . e(t('dev.public_render_profile.thumbnail_report_bytes_note', 'Resource Timing byte values can be zero for browser cache hits. Use the same hard-reload or cache-clearing procedure for both renderer samples.')) . '</p>';
        echo '</section>';
    }
    echo '</div>';
    echo '<p class="public-render-profile-note">' . e(t('dev.public_render_profile.admin_only_note', 'Admin-only diagnostics. Anonymous visitors do not see this panel.')) . '</p>';
    echo '</details>';
    render_public_gallery_benchmark_panel($snapshot);
}

/**
 * Render the admin-only benchmark launcher for the current public gallery.
 *
 * @param array<string, mixed> $snapshot Public render profile snapshot.
 */
function render_public_gallery_benchmark_panel(array $snapshot): void
{
    $galleryId = (int) ($snapshot['gallery_id'] ?? 0);
    if ((string) ($snapshot['route'] ?? '') !== 'gallery' || $galleryId <= 0) {
        return;
    }
    echo '<section class="panel public-gallery-benchmark" data-gallery-benchmark data-gallery-id="' . $galleryId . '" data-benchmark-runs="5" data-csrf-token="' . e(csrf_token()) . '" data-start-url="' . e(url_for('admin_gallery_benchmark_start')) . '" data-browser-url="' . e(url_for('admin_gallery_benchmark_browser')) . '" data-status-url="' . e(url_for('admin_gallery_benchmark_status')) . '">';
    echo '<div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.gallery_benchmark.kicker', 'Benchmark')) . '</p><h2>' . e(t('admin.gallery_benchmark.title', 'Public gallery load benchmark')) . '</h2></div><div class="admin-hero-actions"><button type="button" class="button secondary" data-gallery-benchmark-start>' . e(t('admin.gallery_benchmark.start_button', 'Run benchmark')) . '</button><a class="button secondary is-disabled" data-gallery-benchmark-download href="#" aria-disabled="true" download>' . e(t('admin.gallery_benchmark.download_button', 'Download log')) . '</a></div></div>';
    echo '<p class="muted">' . e(t('admin.gallery_benchmark.help', 'Runs this gallery several times in a hidden same-origin iframe as an anonymous preview, records PHP render counters plus browser timing, then enables the JSON log download.')) . '</p>';
    echo '<div class="thumbnail-progress" data-gallery-benchmark-progress hidden><progress class="thumbnail-progress-bar" max="100" value="0" data-gallery-benchmark-progress-bar></progress><p class="muted" data-gallery-benchmark-status>' . e(t('admin.gallery_benchmark.idle', 'Benchmark is idle.')) . '</p><p class="muted" data-gallery-benchmark-summary></p></div>';
    echo '</section>';
}
