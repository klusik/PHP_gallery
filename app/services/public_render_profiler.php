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
    if (!function_exists('current_user')) {
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
 * Render the admin-only public render profile diagnostic panel.
 */
function render_public_render_profile_panel(): void
{
    if (!public_render_profile_enabled()) {
        return;
    }
    $state =& public_render_profile_state();
    $state['timers']['total_request'] = [
        'count' => 1,
        'total_ms' => (microtime(true) - (float) $state['started_at']) * 1000,
        'max_ms' => (microtime(true) - (float) $state['started_at']) * 1000,
    ];
    $counters = $state['counters'];
    $timers = $state['timers'];
    uasort($timers, static fn (array $left, array $right): int => $right['total_ms'] <=> $left['total_ms']);

    echo '<details class="public-render-profile" data-public-render-profile>'; 
    echo '<summary>' . e(t('dev.public_render_profile.title', 'Public render profile'));
    if ($state['route'] !== '') {
        echo ' · ' . e((string) $state['route']);
    }
    if ($state['gallery_id'] !== null) {
        echo ' · ' . e(t('dev.public_render_profile.gallery_number', 'gallery #{id}', ['id' => (string) $state['gallery_id']]));
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
    $thumbnailPurposes = $state['thumbnail_purposes'] ?? [];
    if ($thumbnailPurposes) {
        uasort($thumbnailPurposes, static function (array $left, array $right): int {
            return (($right['total_ms'] ?? 0.0) <=> ($left['total_ms'] ?? 0.0))
                ?: (($right['calls'] ?? 0) <=> ($left['calls'] ?? 0));
        });
        echo '<section class="public-render-profile-wide"><h2>' . e(t('dev.public_render_profile.thumbnail_lookup_purposes', 'Thumbnail lookup purposes')) . '</h2><table><thead><tr><th>' . e(t('dev.public_render_profile.purpose', 'Purpose')) . '</th><th>' . e(t('dev.public_render_profile.size', 'Size')) . '</th><th>' . e(t('dev.public_render_profile.format', 'Format')) . '</th><th>' . e(t('dev.public_render_profile.lookups', 'Lookups')) . '</th><th>' . e(t('dev.public_render_profile.cache_hits', 'Cache hits')) . '</th><th>' . e(t('dev.public_render_profile.bundle_calls', 'Bundle calls')) . '</th><th>' . e(t('dev.public_render_profile.total_ms', 'Total ms')) . '</th><th>' . e(t('dev.public_render_profile.max_ms', 'Max ms')) . '</th></tr></thead><tbody>';
        foreach ($thumbnailPurposes as $row) {
            echo '<tr><th>' . e((string) $row['purpose']) . '</th><td>' . (int) $row['size'] . '</td><td>' . e((string) $row['format']) . '</td><td>' . (int) $row['calls'] . '</td><td>' . (int) $row['cache_hits'] . '</td><td>' . (int) $row['bundle_calls'] . '</td><td>' . number_format((float) $row['total_ms'], 2, '.', ' ') . '</td><td>' . number_format((float) $row['max_ms'], 2, '.', ' ') . '</td></tr>';
        }
        echo '</tbody></table></section>';
    }
    echo '</div>';
    echo '<p class="public-render-profile-note">' . e(t('dev.public_render_profile.admin_only_note', 'Admin-only diagnostics. Anonymous visitors do not see this panel.')) . '</p>';
    echo '</details>';
}
