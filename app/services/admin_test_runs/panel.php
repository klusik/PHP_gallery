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

use function Gallery\Core\request_data;

use RuntimeException;
use Throwable;
use ZipArchive;
use function Gallery\Core\cms_config;
use function Gallery\Core\current_user;
use function Gallery\Core\db;
use function Gallery\Core\url_for;

/**
 * Build the opt-in Admin test-run panel model near existing benchmark/testing controls.
 *
 * @return array<string, mixed>|null Prepared panel data, or null when the panel is unavailable.
 */
function admin_test_run_panel_model(): ?array
{
    $user = current_user();
    $page = (string) (request_data('query')['page'] ?? 'home');
    if (!$user || !in_array($page, ['gallery', 'smart_gallery'], true) || !feature_capability_effective_enabled('admin_test_runs')) {
        return null;
    }
    $target = admin_test_run_normalize_target((string) (request_data('server')['REQUEST_URI'] ?? '/'));
    $activeToken = admin_test_run_cookie_token();
    $active = admin_test_run_active();
    $latest = admin_test_run_latest_for_target($target);
    $currentRequestId = admin_test_run_current_request_id();
    $starterRequestId = preg_match('/^[a-z0-9_.:-]{8,160}$/iD', (string) (request_data('query')['test_run_starter_request_id'] ?? ''))
        ? (string) request_data('query')['test_run_starter_request_id']
        : '';

    return [
        'active' => $active,
        'active_token' => $activeToken,
        'current_request_id' => $currentRequestId,
        'starter_request_id' => $starterRequestId,
        'finish_url' => url_for('admin_test_run_finish'),
        'finalize_url' => url_for('admin_test_run_finalize'),
        'probe_url' => url_for('admin_test_run_probe'),
        'static_probe_url' => function_exists('Gallery\\Core\\asset_url')
            ? \Gallery\Core\asset_url('assets/gallery-benchmark-static-probe.txt')
            : '/public/assets/gallery-benchmark-static-probe.txt',
        'csrf_token' => \Gallery\Core\csrf_token(),
        'latest' => $latest,
        'latest_download_url' => $latest ? url_for('admin_test_run_download', ['token' => $latest['token']]) : '',
    ];
}
