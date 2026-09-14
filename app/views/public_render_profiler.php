<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/public_render_profiler.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders admin-only diagnostics for profiled public gallery requests.
 *
 * Responsibilities:
 *   - Render profiler counters and timers from controller-prepared data
 *   - Render thumbnail diagnostics without reading request state
 *   - Render the public gallery benchmark launcher from prepared URLs
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
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Services\t;

/**
 * Render the admin-only public render profile diagnostic panel.
 *
 * @param array<string, mixed>|null $model Prepared profiler view model.
 */
function view_render_public_render_profile_panel(?array $model): void
{
    if ($model === null) {
        return;
    }

    $snapshot = is_array($model['snapshot'] ?? null) ? $model['snapshot'] : [];
    $counters = isset($snapshot['counters']) && is_array($snapshot['counters']) ? $snapshot['counters'] : [];
    $timers = isset($snapshot['timers']) && is_array($snapshot['timers']) ? $snapshot['timers'] : [];
    $thumbnailRenderingMode = (string) ($model['thumbnail_rendering_mode'] ?? '');

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
    view_render_public_gallery_benchmark_panel(is_array($model['benchmark'] ?? null) ? $model['benchmark'] : null);
}

/**
 * Render the admin-only benchmark launcher for the current public gallery.
 *
 * @param array<string, mixed>|null $benchmark Prepared benchmark data.
 */
function view_render_public_gallery_benchmark_panel(?array $benchmark): void
{
    if ($benchmark === null) {
        return;
    }
    $galleryId = (int) ($benchmark['gallery_id'] ?? 0);
    if ($galleryId <= 0) {
        return;
    }
    echo '<section class="panel public-gallery-benchmark" data-gallery-benchmark data-gallery-id="' . $galleryId . '" data-benchmark-runs="5" data-csrf-token="' . e((string) ($benchmark['csrf_token'] ?? '')) . '" data-start-url="' . e((string) ($benchmark['start_url'] ?? '')) . '" data-browser-url="' . e((string) ($benchmark['browser_url'] ?? '')) . '" data-status-url="' . e((string) ($benchmark['status_url'] ?? '')) . '" data-php-probe-url="' . e((string) ($benchmark['php_probe_url'] ?? '')) . '" data-static-probe-url="' . e((string) ($benchmark['static_probe_url'] ?? '')) . '">';
    echo '<div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.gallery_benchmark.kicker', 'Benchmark')) . '</p><h2>' . e(t('admin.gallery_benchmark.title', 'Public gallery load benchmark')) . '</h2></div><div class="admin-hero-actions"><button type="button" class="button secondary" data-gallery-benchmark-start>' . e(t('admin.gallery_benchmark.start_button', 'Run benchmark')) . '</button><a class="button secondary is-disabled" data-gallery-benchmark-download href="#" aria-disabled="true" download>' . e(t('admin.gallery_benchmark.download_button', 'Download log')) . '</a></div></div>';
    echo '<p class="muted">' . e(t('admin.gallery_benchmark.help', 'Runs this gallery several times in a hidden same-origin iframe as an anonymous preview, records PHP render counters plus browser timing, then enables the JSON log download.')) . '</p>';
    echo '<div class="thumbnail-progress" data-gallery-benchmark-progress hidden><progress class="thumbnail-progress-bar" max="100" value="0" data-gallery-benchmark-progress-bar></progress><p class="muted" data-gallery-benchmark-status>' . e(t('admin.gallery_benchmark.idle', 'Benchmark is idle.')) . '</p><p class="muted" data-gallery-benchmark-summary></p></div>';
    echo '</section>';
}
