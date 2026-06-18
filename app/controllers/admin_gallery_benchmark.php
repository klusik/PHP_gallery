<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_gallery_benchmark.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles admin-triggered public gallery benchmark requests.
 *
 * Responsibilities:
 *   - Start benchmark logs for selected galleries
 *   - Accept browser timing payloads from hidden iframe runs
 *   - Return benchmark status and downloadable JSON logs
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
 *   2026-06-18
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use Throwable;
use function Gallery\Core\anonymous_preview_url;
use function Gallery\Core\current_user;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\request_method;
use function Gallery\Core\url_for;
use function Gallery\Services\admin_log_event;
use function Gallery\Services\find_gallery;
use function Gallery\Services\gallery_benchmark_build_summary;
use function Gallery\Services\gallery_benchmark_download_filename;
use function Gallery\Services\gallery_benchmark_load_log;
use function Gallery\Services\gallery_benchmark_record_browser_load;
use function Gallery\Services\gallery_benchmark_start;
use function Gallery\Services\gallery_benchmark_token_is_valid;
use function Gallery\Services\t;

/**
 * Start an isolated output buffer for benchmark AJAX routes.
 *
 * Benchmark endpoints must return parseable JSON even when a lower-level helper
 * emits a warning, notice, or plain-text validation failure. The stored base
 * level prevents the response helper from closing unrelated outer buffers.
 */
function admin_gallery_benchmark_start_json_buffer(): void
{
    if (!isset($GLOBALS['gallery_benchmark_json_ob_base_level'])) {
        $GLOBALS['gallery_benchmark_json_ob_base_level'] = ob_get_level();
    }
    ob_start();
}

/**
 * Write a benchmark admin log entry without risking the JSON response path.
 *
 * @param string $level Log level.
 * @param string $eventKey Event key.
 * @param string $message Human-readable message.
 * @param array<string, mixed> $context Structured context.
 * @param array<string, mixed> $options Log routing options.
 */
function admin_gallery_benchmark_log_event_safe(string $level, string $eventKey, string $message, array $context = [], array $options = []): void
{
    try {
        admin_log_event($level, $eventKey, $message, $context, $options);
    } catch (Throwable) {
    }
}

/**
 * Send a JSON response for gallery benchmark AJAX routes.
 *
 * @param array<string, mixed> $payload Response payload.
 * @param int $statusCode HTTP status code.
 */
function admin_gallery_benchmark_json_response(array $payload, int $statusCode = 200): void
{
    $baseLevel = isset($GLOBALS['gallery_benchmark_json_ob_base_level']) ? (int) $GLOBALS['gallery_benchmark_json_ob_base_level'] : ob_get_level();
    while (ob_get_level() > $baseLevel) {
        @ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"ok":false}';
    exit;
}

/**
 * Require a logged-in admin for gallery benchmark routes.
 *
 * @return bool True when the request may continue.
 */
function admin_gallery_benchmark_require_admin(): bool
{
    if (current_user()) {
        return true;
    }
    admin_gallery_benchmark_json_response([
        'ok' => false,
        'error' => t('admin.gallery_benchmark.auth_required', 'Admin session expired. Reload the page and sign in again.'),
    ], 403);
    return false;
}

/**
 * Validate benchmark POST request method and CSRF token.
 *
 * @return bool True when the request may continue.
 */
function admin_gallery_benchmark_require_post(): bool
{
    if (request_method() !== 'POST') {
        admin_gallery_benchmark_json_response([
            'ok' => false,
            'error' => t('admin.gallery_benchmark.post_required', 'Benchmark requests must use POST.'),
        ], 405);
        return false;
    }
    $token = (string) ($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        admin_gallery_benchmark_log_event_safe('warning', 'gallery_benchmark.csrf_failed', 'Gallery benchmark AJAX request failed CSRF validation.', [
            'request_uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            'has_token' => $token !== '',
        ], ['category' => 'security', 'severity' => 'warning']);
        admin_gallery_benchmark_json_response([
            'ok' => false,
            'error' => t('admin.gallery_benchmark.csrf_failed', 'Security token expired or invalid. Reload the gallery page and try again.'),
        ], 400);
        return false;
    }
    return true;
}

/**
 * Start a new browser-driven benchmark for one gallery.
 */
function cms_admin_gallery_benchmark_start(): void
{
    admin_gallery_benchmark_start_json_buffer();
    admin_gallery_benchmark_require_admin();
    admin_gallery_benchmark_require_post();

    $galleryId = (int) ($_POST['gallery_id'] ?? 0);
    $runsTotal = (int) ($_POST['runs_total'] ?? 5);
    $gallery = $galleryId > 0 ? find_gallery($galleryId, true) : null;
    if (!$gallery) {
        admin_gallery_benchmark_json_response([
            'ok' => false,
            'error' => t('admin.gallery_benchmark.gallery_missing', 'Gallery was not found.'),
        ], 404);
    }

    try {
        $log = gallery_benchmark_start($gallery, $runsTotal);
        $token = (string) $log['token'];
        $publicUrl = anonymous_preview_url(gallery_public_url($gallery), true);
        $downloadUrl = url_for('admin_gallery_benchmark_download', ['token' => $token]);
        admin_gallery_benchmark_log_event_safe('info', 'gallery_benchmark.started', 'Admin started public gallery benchmark.', [
            'gallery_id' => $galleryId,
            'runs_total' => (int) $log['runs_total'],
            'token_prefix' => substr($token, 0, 8),
        ], ['category' => 'admin', 'severity' => 'info', 'subject_type' => 'gallery', 'subject_id' => $galleryId]);
        admin_gallery_benchmark_json_response([
            'ok' => true,
            'token' => $token,
            'runs_total' => (int) $log['runs_total'],
            'public_url' => $publicUrl,
            'download_url' => $downloadUrl,
            'summary' => gallery_benchmark_build_summary($log),
        ]);
    } catch (Throwable $exception) {
        admin_gallery_benchmark_log_event_safe('error', 'gallery_benchmark.start_failed', 'Public gallery benchmark start failed.', [
            'gallery_id' => $galleryId,
            'exception' => $exception->getMessage(),
        ], ['category' => 'admin', 'severity' => 'error', 'subject_type' => 'gallery', 'subject_id' => $galleryId]);
        admin_gallery_benchmark_json_response([
            'ok' => false,
            'error' => t('admin.gallery_benchmark.start_failed', 'Benchmark could not be started: {error}', ['error' => $exception->getMessage()]),
        ], 500);
    }
}

/**
 * Store browser-side navigation timing for one completed benchmark iframe load.
 */
function cms_admin_gallery_benchmark_browser(): void
{
    admin_gallery_benchmark_start_json_buffer();
    admin_gallery_benchmark_require_admin();
    admin_gallery_benchmark_require_post();

    $token = strtolower(trim((string) ($_POST['token'] ?? '')));
    $runIndex = (int) ($_POST['run_index'] ?? 0);
    $browserJson = (string) ($_POST['browser_json'] ?? '{}');
    $browserPayload = json_decode($browserJson, true);
    if (!gallery_benchmark_token_is_valid($token) || $runIndex < 1 || !is_array($browserPayload)) {
        admin_gallery_benchmark_json_response([
            'ok' => false,
            'error' => t('admin.gallery_benchmark.invalid_payload', 'Benchmark payload is invalid.'),
        ], 400);
    }

    try {
        $log = gallery_benchmark_record_browser_load($token, $runIndex, $browserPayload);
        admin_gallery_benchmark_json_response([
            'ok' => true,
            'summary' => $log['summary'] ?? gallery_benchmark_build_summary($log),
        ]);
    } catch (Throwable $exception) {
        admin_gallery_benchmark_log_event_safe('error', 'gallery_benchmark.browser_failed', 'Public gallery benchmark browser timing save failed.', [
            'run_index' => $runIndex,
            'exception' => $exception->getMessage(),
        ], ['category' => 'admin', 'severity' => 'error']);
        admin_gallery_benchmark_json_response([
            'ok' => false,
            'error' => t('admin.gallery_benchmark.browser_failed', 'Browser timing could not be saved: {error}', ['error' => $exception->getMessage()]),
        ], 500);
    }
}

/**
 * Return current benchmark status for the browser runner.
 */
function cms_admin_gallery_benchmark_status(): void
{
    admin_gallery_benchmark_start_json_buffer();
    admin_gallery_benchmark_require_admin();

    $token = strtolower(trim((string) ($_GET['token'] ?? $_POST['token'] ?? '')));
    if (!gallery_benchmark_token_is_valid($token)) {
        admin_gallery_benchmark_json_response([
            'ok' => false,
            'error' => t('admin.gallery_benchmark.invalid_token', 'Benchmark token is invalid.'),
        ], 400);
    }

    try {
        $log = gallery_benchmark_load_log($token);
        admin_gallery_benchmark_json_response([
            'ok' => true,
            'summary' => $log['summary'] ?? gallery_benchmark_build_summary($log),
            'download_url' => url_for('admin_gallery_benchmark_download', ['token' => $token]),
        ]);
    } catch (Throwable $exception) {
        admin_gallery_benchmark_json_response([
            'ok' => false,
            'error' => t('admin.gallery_benchmark.status_failed', 'Benchmark status could not be loaded: {error}', ['error' => $exception->getMessage()]),
        ], 404);
    }
}

/**
 * Download a completed or partial benchmark JSON log.
 */
function cms_admin_gallery_benchmark_download(): void
{
    if (!current_user()) {
        http_response_code(403);
        echo 'Admin session expired.';
        return;
    }
    $token = strtolower(trim((string) ($_GET['token'] ?? '')));
    if (!gallery_benchmark_token_is_valid($token)) {
        http_response_code(400);
        echo 'Invalid benchmark token.';
        return;
    }

    try {
        $log = gallery_benchmark_load_log($token);
        $log['summary'] = gallery_benchmark_build_summary($log);
        $json = json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('Benchmark log could not be encoded.');
        }
        $filename = gallery_benchmark_download_filename($log);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Cache-Control: no-store, private');
        echo $json . "\n";
    } catch (Throwable $exception) {
        http_response_code(404);
        echo 'Benchmark log was not found or could not be read.';
    }
}
