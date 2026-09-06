<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_test_runs/panel.php
 * Module Type: Service
 *
 * Purpose:
 *   Renders the Admin side-panel surface for test runs.
 *
 * Responsibilities:
 *   - Render the test-run panel fragment for the Admin right-side panel
 *   - Keep presentation separate from run capture and analysis
 *   - Expose only bounded, already-sanitized run metadata
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
 * Render the opt-in Admin test-run panel near existing benchmark/testing controls.
 */
function render_admin_test_run_panel(): void
{
    $user = current_user();
    $page = (string) ($_GET['page'] ?? 'home');
    if (!$user || !in_array($page, ['gallery', 'smart_gallery'], true) || !feature_flag_enabled('admin_test_runs')) {
        return;
    }
    $target = admin_test_run_normalize_target((string) ($_SERVER['REQUEST_URI'] ?? '/'));
    $activeToken = admin_test_run_cookie_token();
    $active = admin_test_run_active();
    $latest = admin_test_run_latest_for_target($target);
    $currentRequestId = admin_test_run_current_request_id();
    $starterRequestId = preg_match('/^[a-z0-9_.:-]{8,160}$/iD', (string) ($_GET['test_run_starter_request_id'] ?? ''))
        ? (string) $_GET['test_run_starter_request_id']
        : '';
    echo '<section class="panel admin-full-test-run" data-admin-test-run-panel data-test-run-active="' . ($active ? '1' : '0') . '"';
    if ($activeToken !== '') {
        echo ' data-test-run-token="' . htmlspecialchars($activeToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
    }
    if ($currentRequestId !== '') {
        echo ' data-current-request-id="' . htmlspecialchars($currentRequestId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
    }
    if ($starterRequestId !== '') {
        echo ' data-starter-request-id="' . htmlspecialchars($starterRequestId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
    }
    echo ' data-finish-url="' . htmlspecialchars(url_for('admin_test_run_finish'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
    echo ' data-finalize-url="' . htmlspecialchars(url_for('admin_test_run_finalize'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
    echo ' data-probe-url="' . htmlspecialchars(url_for('admin_test_run_probe'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
    echo ' data-static-probe-url="' . htmlspecialchars((function_exists('Gallery\\Core\\asset_url') ? \Gallery\Core\asset_url('assets/gallery-benchmark-static-probe.txt') : '/public/assets/gallery-benchmark-static-probe.txt'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
    echo ' data-csrf-token="' . htmlspecialchars(\Gallery\Core\csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
    echo '<div class="admin-panel-heading"><div><p class="admin-kicker">' . htmlspecialchars(t('admin.test_run.kicker', 'Deep diagnostics'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p><h2>' . htmlspecialchars(t('admin.test_run.title', 'Full Admin test run'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2></div><div class="admin-hero-actions">';
    if ($latest) {
        echo '<a class="button secondary" href="' . htmlspecialchars(url_for('admin_test_run_download', ['token' => $latest['token']]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . htmlspecialchars(t('admin.test_run.download_latest', 'Download latest test run'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
    }
    echo '</div></div>';
    echo '<p class="muted">' . htmlspecialchars(t('admin.test_run.help', 'Opt-in administrator diagnostics. A run clears safe application caches, forcibly reloads this gallery, records PHP lifecycle/database/cache/process/concurrency details for every same-origin PHP request, performs only sequential verification probes, and produces a downloadable JSON/ZIP report.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    if ($active) {
        echo '<div class="notice" data-admin-test-run-status>' . htmlspecialchars(t('admin.test_run.running', 'Test run is active. Browser and PHP probes will finalize automatically after the page load settles.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
    } elseif ($latest) {
        echo '<p class="muted">' . htmlspecialchars(t('admin.test_run.latest_summary', 'Latest: {time}; PHP requests: {requests}; peak concurrency: {peak}; all requests closed: {closed}.', [
            'time' => (string) $latest['finalized_at'],
            'requests' => (string) $latest['request_count'],
            'peak' => (string) $latest['peak_concurrency'],
            'closed' => $latest['all_closed'] ? 'yes' : 'no',
        ]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    } else {
        echo '<p class="muted">' . htmlspecialchars(t('admin.test_run.none', 'No completed full test run is stored for this gallery yet.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }
    echo '</section>';
}
