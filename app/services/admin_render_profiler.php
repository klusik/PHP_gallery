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

/**
 * Return whether the current request should collect admin dashboard profiling data.
 */
function admin_render_profile_enabled(): bool
{
    if (PHP_SAPI === 'cli') {
        return false;
    }
    if (!function_exists('current_user')) {
        return false;
    }
    return current_user() !== null && (string) ($_GET['page'] ?? '') === 'admin';
}

/**
 * Return the mutable admin profiler state for this request.
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
 * @template T
 * @param callable():T $callback
 * @return T
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
 * @template T
 * @param callable():T $callback
 * @return T
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
 * @template T
 * @param callable():T $callback
 * @return T
 */
function admin_render_profile_schema(string $timer, callable $callback)
{
    admin_render_profile_count('schema_checks');
    return admin_render_profile_span($timer, $callback);
}

/**
 * Measure one app-setting read callback, count it, and return its result unchanged.
 *
 * @template T
 * @param callable():T $callback
 * @return T
 */
function admin_render_profile_setting_read(string $timer, callable $callback)
{
    admin_render_profile_count('app_setting_reads');
    return admin_render_profile_span($timer, $callback);
}

/**
 * Measure one app-setting write callback, count it, and return its result unchanged.
 *
 * @template T
 * @param callable():T $callback
 * @return T
 */
function admin_render_profile_setting_write(string $timer, callable $callback)
{
    admin_render_profile_count('app_setting_writes');
    return admin_render_profile_span($timer, $callback);
}

/**
 * Render the admin-only dashboard profile diagnostic panel.
 */
function render_admin_render_profile_panel(): void
{
    if (!admin_render_profile_enabled()) {
        return;
    }
    $state =& admin_render_profile_state();
    $totalMs = (microtime(true) - (float) $state['started_at']) * 1000;
    $state['timers']['total_request'] = [
        'count' => 1,
        'total_ms' => $totalMs,
        'max_ms' => $totalMs,
    ];

    $counters = $state['counters'];
    $timers = $state['timers'];
    uasort($timers, static fn (array $left, array $right): int => $right['total_ms'] <=> $left['total_ms']);

    echo '<details class="admin-render-profile" data-admin-render-profile open>';
    echo '<summary>' . e(t('dev.admin_render_profile.title', 'Admin render profile'));
    if ($state['route'] !== '') {
        echo ' · ' . e((string) $state['route']);
    }
    echo '</summary>';
    echo '<div class="admin-render-profile-grid">';
    echo '<section><h2>' . e(t('dev.admin_render_profile.counters', 'Counters')) . '</h2><table><tbody>';
    foreach ($counters as $name => $value) {
        echo '<tr><th>' . e(str_replace('_', ' ', (string) $name)) . '</th><td>' . number_format((float) $value, 0, '.', ' ') . '</td></tr>';
    }
    echo '</tbody></table></section>';
    echo '<section><h2>' . e(t('dev.admin_render_profile.timers', 'Timers')) . '</h2><table><thead><tr><th>' . e(t('dev.admin_render_profile.name', 'Name')) . '</th><th>' . e(t('dev.admin_render_profile.count', 'Count')) . '</th><th>' . e(t('dev.admin_render_profile.total_ms', 'Total ms')) . '</th><th>' . e(t('dev.admin_render_profile.max_ms', 'Max ms')) . '</th></tr></thead><tbody>';
    foreach ($timers as $name => $timer) {
        echo '<tr><th>' . e(str_replace('_', ' ', (string) $name)) . '</th><td>' . (int) $timer['count'] . '</td><td>' . number_format((float) $timer['total_ms'], 2, '.', ' ') . '</td><td>' . number_format((float) $timer['max_ms'], 2, '.', ' ') . '</td></tr>';
    }
    echo '</tbody></table></section>';
    echo '</div>';
    echo '<p class="admin-render-profile-note">' . e(t('dev.admin_render_profile.admin_only_note', 'Admin-only diagnostics for /index.php?page=admin. Use this output to identify first-load cost before optimizing.')) . '</p>';
    echo '</details>';
}
