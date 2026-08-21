<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_test_runs.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles the opt-in administrator full test-run lifecycle and report downloads.
 *
 * Responsibilities:
 *   - Start a bounded test run from the current gallery after CSRF/admin checks
 *   - Force a cache-busted reload while carrying a short-lived diagnostics cookie
 *   - Accept browser timing/probe data, let that traced request reach shutdown, then finalize from a separate untraced Admin request
 *   - Provide a minimal sequential PHP timing probe
 *   - Download the final ZIP artifact, with JSON fallback when ZipArchive is unavailable
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
 *   2026-08-21
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use Throwable;
use function Gallery\Core\current_user;
use function Gallery\Core\redirect_to;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\admin_test_run_active_context;
use function Gallery\Services\admin_test_run_clear_cookie;
use function Gallery\Services\admin_test_run_create;
use function Gallery\Services\admin_test_run_download_filename;
use function Gallery\Services\admin_test_run_finalize;
use function Gallery\Services\admin_test_run_mark;
use function Gallery\Services\admin_test_run_normalize_target;
use function Gallery\Services\admin_test_run_owned_by_current_admin;
use function Gallery\Services\admin_test_run_read_json;
use function Gallery\Services\admin_test_run_report_path;
use function Gallery\Services\admin_test_run_register_final_shutdown_observer;
use function Gallery\Services\admin_test_run_request_begin_for_token;
use function Gallery\Services\admin_test_run_response_logical_finish;
use function Gallery\Services\admin_test_run_runtime_snapshot;
use function Gallery\Services\admin_test_run_set_cookie;
use function Gallery\Services\admin_test_run_set_starter_request_id;
use function Gallery\Services\admin_test_run_store_browser_payload;
use function Gallery\Services\admin_test_run_target_with_params;
use function Gallery\Services\admin_test_run_token_valid;
use function Gallery\Services\admin_test_run_zip_path;
use function Gallery\Services\feature_flag_enabled;
use function Gallery\Services\t;

/**
 * Return an Admin test-run JSON response and stop execution.
 *
 * @param array<string,mixed> $payload JSON payload.
 */
function admin_test_run_json_response(array $payload, int $statusCode = 200): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    admin_test_run_response_logical_finish('json_response_ready');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * Start a new full diagnostics run and force a reload of the current gallery target.
 */
function cms_admin_test_run_start(): void
{
    if (function_exists('Gallery\\Diagnostics\\admin_test_run_early_mark')) {
        \Gallery\Diagnostics\admin_test_run_early_mark('starter.controller_enter');
        \Gallery\Diagnostics\admin_test_run_early_mark('starter.authentication_begin');
    }
    require_admin();
    if (function_exists('Gallery\\Diagnostics\\admin_test_run_early_mark')) {
        \Gallery\Diagnostics\admin_test_run_early_mark('starter.authentication_complete');
    }
    if (!feature_flag_enabled('admin_test_runs')) {
        http_response_code(403);
        echo t('admin.test_run.disabled', 'Admin test runs are disabled in Features.');
        return;
    }
    if (request_method() !== 'POST') {
        http_response_code(405);
        echo t('admin.test_run.post_required', 'Test runs must be started with POST.');
        return;
    }
    if (function_exists('Gallery\\Diagnostics\\admin_test_run_early_mark')) {
        \Gallery\Diagnostics\admin_test_run_early_mark('starter.csrf_begin');
    }
    verify_csrf();
    if (function_exists('Gallery\\Diagnostics\\admin_test_run_early_mark')) {
        \Gallery\Diagnostics\admin_test_run_early_mark('starter.csrf_complete');
    }
    $target = admin_test_run_normalize_target((string) ($_POST['target'] ?? '/'));
    $targetPage = (string) ($_POST['target_page'] ?? 'gallery');
    if (!in_array($targetPage, ['gallery', 'smart_gallery'], true)) {
        $targetPage = 'gallery';
    }
    try {
        if (function_exists('Gallery\\Diagnostics\\admin_test_run_early_mark')) {
            \Gallery\Diagnostics\admin_test_run_early_mark('starter.cache_and_context_preparation_begin');
        }
        $meta = admin_test_run_create($target, $targetPage);
        $token = (string) ($meta['token'] ?? '');
        if (function_exists('Gallery\\Diagnostics\\admin_test_run_early_bind_token')) {
            \Gallery\Diagnostics\admin_test_run_early_bind_token($token);
        }
        admin_test_run_set_cookie($token);
        $starterRequestId = admin_test_run_request_begin_for_token($token, 'starter');
        admin_test_run_register_final_shutdown_observer();
        admin_test_run_set_starter_request_id($token, $starterRequestId);
        admin_test_run_mark('starter.cache_and_context_preparation_complete', [
            'preparation_ms' => (float) ($meta['starter_preparation']['duration_ms'] ?? 0.0),
        ]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
            admin_test_run_mark('starter.session_released');
        }
        admin_test_run_mark('starter.redirect_preparation_begin');
        $redirect = admin_test_run_target_with_params($target, [
            'test_run_token' => $token,
            'test_run_starter_request_id' => $starterRequestId,
            'test_run_cache_bust' => (string) round(microtime(true) * 1000),
        ]);
        admin_test_run_mark('starter.redirect_preparation_complete');
        admin_test_run_response_logical_finish('starter_redirect_ready');
        redirect_to($redirect);
    } catch (Throwable $exception) {
        http_response_code(500);
        echo t('admin.test_run.start_failed', 'Test run could not be started: {error}', ['error' => $exception->getMessage()]);
    }
}

/**
 * Return a minimal traced PHP probe used by the sequential browser runner.
 */
function cms_admin_test_run_probe(): void
{
    require_admin();
    if (!feature_flag_enabled('admin_test_runs')) {
        admin_test_run_json_response(['ok' => false, 'error' => 'feature_disabled'], 403);
    }
    $context = admin_test_run_active_context();
    $token = strtolower(trim((string) ($_GET['token'] ?? '')));
    if (!$context || !admin_test_run_token_valid($token) || !hash_equals((string) ($context['token'] ?? ''), $token)) {
        admin_test_run_json_response(['ok' => false, 'error' => 'invalid_test_run'], 400);
    }
    admin_test_run_mark('test_run_probe_controller_enter');
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
        admin_test_run_mark('test_run_probe_session_released');
    }
    $requestTime = isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT'])
        ? (float) $_SERVER['REQUEST_TIME_FLOAT']
        : microtime(true);
    admin_test_run_json_response([
        'ok' => true,
        'request_time_unix' => $requestTime,
        'controller_at_unix' => microtime(true),
        'request_age_ms' => max(0.0, (microtime(true) - $requestTime) * 1000),
        'runtime' => admin_test_run_runtime_snapshot('php_probe'),
    ]);
}

/**
 * Store browser measurements, close the finalizer request sidecar, and assemble the report.
 */
function cms_admin_test_run_finish(): void
{
    require_admin();
    if (!feature_flag_enabled('admin_test_runs')) {
        admin_test_run_json_response(['ok' => false, 'error' => 'feature_disabled'], 403);
    }
    if (request_method() !== 'POST') {
        admin_test_run_json_response(['ok' => false, 'error' => 'post_required'], 405);
    }
    verify_csrf();
    $context = admin_test_run_active_context();
    $token = strtolower(trim((string) ($_POST['token'] ?? '')));
    if (!$context || !admin_test_run_token_valid($token) || !hash_equals((string) ($context['token'] ?? ''), $token)
        || !admin_test_run_owned_by_current_admin($context)) {
        admin_test_run_json_response(['ok' => false, 'error' => 'invalid_test_run'], 400);
    }
    $browserJson = (string) ($_POST['browser_json'] ?? '{}');
    $browser = json_decode($browserJson, true);
    if (!is_array($browser)) {
        admin_test_run_json_response(['ok' => false, 'error' => 'invalid_browser_payload'], 400);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
        admin_test_run_mark('test_run_browser_payload_session_released');
    }
    try {
        admin_test_run_store_browser_payload($token, $browser);
        admin_test_run_mark('test_run_browser_payload_stored');
        // Clearing the cookie here makes the subsequent report assembly request intentionally untraced.
        // This lets the current request reach its real shutdown observer before sidecars are assembled.
        admin_test_run_clear_cookie();
        admin_test_run_json_response([
            'ok' => true,
            'finalize_url' => url_for('admin_test_run_finalize'),
        ]);
    } catch (Throwable $exception) {
        admin_test_run_clear_cookie();
        admin_test_run_json_response([
            'ok' => false,
            'error' => 'browser_payload_store_failed',
            'message' => $exception->getMessage(),
        ], 500);
    }
}

/**
 * Assemble a completed report after the browser-payload request has reached shutdown.
 */
function cms_admin_test_run_finalize(): void
{
    require_admin();
    if (!feature_flag_enabled('admin_test_runs')) {
        admin_test_run_json_response(['ok' => false, 'error' => 'feature_disabled'], 403);
    }
    if (request_method() !== 'POST') {
        admin_test_run_json_response(['ok' => false, 'error' => 'post_required'], 405);
    }
    verify_csrf();
    $token = strtolower(trim((string) ($_POST['token'] ?? '')));
    if (!admin_test_run_token_valid($token)) {
        admin_test_run_json_response(['ok' => false, 'error' => 'invalid_test_run'], 400);
    }
    $meta = admin_test_run_read_json(\Gallery\Services\admin_test_run_meta_path($token));
    if (!$meta || !admin_test_run_owned_by_current_admin($meta)) {
        admin_test_run_json_response(['ok' => false, 'error' => 'invalid_test_run'], 404);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    try {
        $report = admin_test_run_finalize($token);
        admin_test_run_json_response([
            'ok' => true,
            'download_url' => url_for('admin_test_run_download', ['token' => $token]),
            'summary' => [
                'request_count' => (int) ($report['request_lifecycle']['completed_count'] ?? 0),
                'peak_concurrency' => (int) ($report['request_concurrency']['peak_concurrent_php_requests'] ?? 0),
                'all_closed' => !empty($report['request_lifecycle']['all_completed_cleanly']),
                'db_queries' => (int) ($report['database_summary']['query_count'] ?? 0),
                'analysis_flags' => $report['analysis_flags'] ?? [],
            ],
        ]);
    } catch (Throwable $exception) {
        admin_test_run_json_response([
            'ok' => false,
            'error' => 'finalize_failed',
            'message' => $exception->getMessage(),
        ], 500);
    }
}

/**
 * Download a finalized test-run ZIP report, or JSON when ZipArchive was unavailable.
 */
function cms_admin_test_run_download(): void
{
    require_admin();
    $token = strtolower(trim((string) ($_GET['token'] ?? '')));
    if (!admin_test_run_token_valid($token)) {
        http_response_code(400);
        echo t('admin.test_run.invalid_token', 'Invalid test-run token.');
        return;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $report = admin_test_run_read_json(admin_test_run_report_path($token));
    if (!$report || !admin_test_run_owned_by_current_admin($report)) {
        http_response_code(404);
        echo t('admin.test_run.report_missing', 'The test-run report was not found.');
        return;
    }
    $zipPath = admin_test_run_zip_path($token);
    if (is_file($zipPath)) {
        header('Content-Type: application/zip');
        header('Content-Length: ' . (string) filesize($zipPath));
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', admin_test_run_download_filename($report, true)) . '"');
        header('Cache-Control: no-store, private');
        readfile($zipPath);
        return;
    }
    $jsonPath = admin_test_run_report_path($token);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . (string) filesize($jsonPath));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', admin_test_run_download_filename($report, false)) . '"');
    header('Cache-Control: no-store, private');
    readfile($jsonPath);
}
