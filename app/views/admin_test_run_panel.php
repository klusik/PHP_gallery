<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_test_run_panel.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders the opt-in full Admin test-run panel.
 *
 * Responsibilities:
 *   - Render test-run transport metadata from prepared values
 *   - Render the latest run status and download action
 *   - Keep test-run presentation outside the Service layer
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
 * Render the opt-in Admin test-run panel near existing benchmark/testing controls.
 *
 * @param array<string, mixed>|null $model Prepared test-run panel model.
 */
function view_render_admin_test_run_panel(?array $model): void
{
    if ($model === null) {
        return;
    }
    $active = !empty($model['active']);
    $activeToken = (string) ($model['active_token'] ?? '');
    $currentRequestId = (string) ($model['current_request_id'] ?? '');
    $starterRequestId = (string) ($model['starter_request_id'] ?? '');
    $latest = is_array($model['latest'] ?? null) ? $model['latest'] : null;

    echo '<section class="panel admin-full-test-run" data-admin-test-run-panel data-test-run-active="' . ($active ? '1' : '0') . '"';
    if ($activeToken !== '') {
        echo ' data-test-run-token="' . e($activeToken) . '"';
    }
    if ($currentRequestId !== '') {
        echo ' data-current-request-id="' . e($currentRequestId) . '"';
    }
    if ($starterRequestId !== '') {
        echo ' data-starter-request-id="' . e($starterRequestId) . '"';
    }
    echo ' data-finish-url="' . e((string) ($model['finish_url'] ?? '')) . '"';
    echo ' data-finalize-url="' . e((string) ($model['finalize_url'] ?? '')) . '"';
    echo ' data-probe-url="' . e((string) ($model['probe_url'] ?? '')) . '"';
    echo ' data-static-probe-url="' . e((string) ($model['static_probe_url'] ?? '')) . '"';
    echo ' data-csrf-token="' . e((string) ($model['csrf_token'] ?? '')) . '">';
    echo '<div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.test_run.kicker', 'Deep diagnostics')) . '</p><h2>' . e(t('admin.test_run.title', 'Full Admin test run')) . '</h2></div><div class="admin-hero-actions">';
    if ($latest) {
        echo '<a class="button secondary" href="' . e((string) ($model['latest_download_url'] ?? '')) . '">' . e(t('admin.test_run.download_latest', 'Download latest test run')) . '</a>';
    }
    echo '</div></div>';
    echo '<p class="muted">' . e(t('admin.test_run.help', 'Opt-in administrator diagnostics. A run clears safe application caches, forcibly reloads this gallery, records PHP lifecycle/database/cache/process/concurrency details for every same-origin PHP request, performs only sequential verification probes, and produces a downloadable JSON/ZIP report.')) . '</p>';
    if ($active) {
        echo '<div class="notice" data-admin-test-run-status>' . e(t('admin.test_run.running', 'Test run is active. Browser and PHP probes will finalize automatically after the page load settles.')) . '</div>';
    } elseif ($latest) {
        echo '<p class="muted">' . e(t('admin.test_run.latest_summary', 'Latest: {time}; PHP requests: {requests}; peak concurrency: {peak}; all requests closed: {closed}.', [
            'time' => (string) ($latest['finalized_at'] ?? ''),
            'requests' => (string) ($latest['request_count'] ?? 0),
            'peak' => (string) ($latest['peak_concurrency'] ?? 0),
            'closed' => !empty($latest['all_closed']) ? 'yes' : 'no',
        ])) . '</p>';
    } else {
        echo '<p class="muted">' . e(t('admin.test_run.none', 'No completed full test run is stored for this gallery yet.')) . '</p>';
    }
    echo '</section>';
}
