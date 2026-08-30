<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/early_runtime.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Provides dependency-free request hardening before the normal application bootstrap is loaded.
 *
 * Responsibilities:
 *   - Reject new ordinary HTTP requests during update activation
 *   - Keep authenticated Admin update recovery reachable during a gated activation
 *   - Convert uncaught top-level failures to safe server-error responses when possible
 *   - Record fatal shutdown failures without depending on the database or application logger
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
 *   - Keep this file dependency-free because it executes before app/bootstrap.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-08-30
 */

declare(strict_types=1);

namespace Gallery\EarlyRuntime;

use Throwable;

/**
 * Return a short random request reference suitable for public error correlation.
 */
function request_error_reference(): string
{
    try {
        return strtoupper(bin2hex(random_bytes(8)));
    } catch (Throwable) {
        return strtoupper(substr(hash('sha256', uniqid('gallery-error-', true)), 0, 16));
    }
}

/**
 * Return whether this request is known to expect a JSON response.
 */
function request_expects_json(): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (str_contains($accept, 'application/json')) {
        return true;
    }

    $page = (string) ($_GET['page'] ?? '');
    if (in_array($page, [
        'gallery_lightbox_data',
        'smart_gallery_lightbox_data',
        'gallery_map_data',
        'public_search',
        'admin_gallery_benchmark_status',
        'admin_gallery_benchmark_probe',
        'admin_test_run_probe',
        'admin_storage_statistics_update',
        'gallery_migration_manifest',
        'gallery_migration_receive_manifest',
        'gallery_migration_receive_complete',
        'gallery_migration_receive_status',
        'upload_automation_upload',
    ], true)) {
        return true;
    }

    return $page === 'admin_update'
        && ((string) ($_GET['update_async'] ?? $_POST['update_async'] ?? '') === '1'
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest');
}

/**
 * Log one emergency event without requiring bootstrap, database, or filesystem log services.
 */
function log_emergency(string $kind, string $reference, string $errorClass = '', string $message = '', string $file = '', int $line = 0): void
{
    $class = preg_replace('/[^A-Za-z0-9_.-]/', '', str_replace('\\', '.', $errorClass)) ?: 'unknown';
    $detail = trim(preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', $message) ?? '');
    if (strlen($detail) > 2000) {
        $detail = substr($detail, 0, 2000) . '...';
    }
    $location = $file !== '' ? $file . ($line > 0 ? ':' . $line : '') : '';
    $entry = '[PHP Gallery] ' . $kind . ' reference=' . $reference . ' type=' . $class;
    if ($location !== '') {
        $entry .= ' location=' . $location;
    }
    if ($detail !== '') {
        $entry .= ' message=' . $detail;
    }
    error_log($entry);
}

/**
 * Emit the minimal safe 500 response used by both Throwable and shutdown handling.
 */
function emit_server_error(string $reference): void
{
    if (headers_sent()) {
        return;
    }

    http_response_code(500);
    header('Cache-Control: no-store, private, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    if (request_expects_json()) {
        header('Content-Type: application/json; charset=utf-8');
        $json = json_encode([
            'ok' => false,
            'error' => 'Internal server error.',
            'reference' => $reference,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        echo $json === false ? '{"ok":false,"error":"Internal server error."}' : $json;
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Server error</title></head><body><h1>Server error</h1><p>The request could not be completed.</p><p>Reference: ' . $reference . '</p></body></html>';
}

/**
 * Convert one uncaught top-level Throwable to a safe HTTP 500 where headers remain mutable.
 */
function handle_uncaught(Throwable $exception): void
{
    $GLOBALS['php_gallery_emergency_handled'] = true;
    $reference = request_error_reference();
    log_emergency('uncaught', $reference, get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine());
    emit_server_error($reference);
}

/**
 * Register fatal shutdown handling before any normal application dependency is required.
 */
function register_emergency_handler(): void
{
    if (!empty($GLOBALS['php_gallery_emergency_registered'])) {
        return;
    }
    $GLOBALS['php_gallery_emergency_registered'] = true;

    set_exception_handler(static function (Throwable $exception): void {
        handle_uncaught($exception);
    });

    register_shutdown_function(static function (): void {
        if (!empty($GLOBALS['php_gallery_emergency_handled'])) {
            return;
        }

        $error = error_get_last();
        if (!is_array($error)) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array((int) ($error['type'] ?? 0), $fatalTypes, true)) {
            return;
        }

        $GLOBALS['php_gallery_emergency_handled'] = true;
        $reference = request_error_reference();
        log_emergency('fatal', $reference, 'php-error-' . (string) ((int) ($error['type'] ?? 0)), (string) ($error['message'] ?? ''), (string) ($error['file'] ?? ''), (int) ($error['line'] ?? 0));
        emit_server_error($reference);
    });
}

/**
 * Return the updater activation marker path without loading updater services.
 */
function activation_gate_path(string $projectRoot): string
{
    return rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'updates' . DIRECTORY_SEPARATOR . 'activation.json';
}

/**
 * Read one JSON file conservatively, returning null for missing or malformed state.
 *
 * @return array<string,mixed>|null
 */
function read_json_file(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Return whether the durable job checkpoint proves that activation is already complete.
 */
function activation_job_is_complete(string $projectRoot, array $marker): bool
{
    $jobId = (string) ($marker['job_id'] ?? '');
    if (preg_match('/^\d{14}-[0-9a-f]{12}$/', $jobId) !== 1) {
        return false;
    }

    $jobPath = rtrim($projectRoot, '/\\')
        . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'updates'
        . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . $jobId
        . DIRECTORY_SEPARATOR . 'job.json';
    $job = read_json_file($jobPath);
    return is_array($job)
        && hash_equals($jobId, (string) ($job['id'] ?? ''))
        && !empty($job['checkpoints']['activation_complete']);
}

/**
 * Return whether this request is an authenticated Admin update recovery request.
 *
 * The early gate deliberately does not trust a query parameter by itself. It only
 * permits the existing Admin update route after a real server-side Admin session
 * can be read using the session name captured when activation began. Full Admin
 * authentication and CSRF checks still run later in the normal application stack.
 */
function activation_admin_recovery_allowed(array $marker): bool
{
    if ((string) ($_GET['page'] ?? '') !== 'admin_update') {
        return false;
    }

    $sessionName = (string) ($marker['admin_session_name'] ?? '');
    if ($sessionName === '' || preg_match('/^[A-Za-z0-9_-]{1,128}$/', $sessionName) !== 1 || empty($_COOKIE[$sessionName])) {
        return false;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        return !empty($_SESSION['user_id']);
    }

    $previousName = session_name();
    if (!@session_name($sessionName)) {
        return false;
    }

    $started = @session_start(['read_and_close' => true]);
    $allowed = $started && !empty($_SESSION['user_id']);

    if (!$started && session_status() !== PHP_SESSION_ACTIVE) {
        @session_name($previousName);
    }
    return $allowed;
}

/**
 * Emit the dependency-free activation 503 response.
 */
function emit_activation_unavailable(): void
{
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store, private, max-age=0');
        header('Pragma: no-cache');
        header('Retry-After: 3');
        header('X-Content-Type-Options: nosniff');
    }
    echo "Service temporarily unavailable.\n";
}

/**
 * Fail new ordinary HTTP requests closed while a release activation is in progress.
 *
 * A marker is cleared here only when the durable job state explicitly records
 * activation_complete. Missing, corrupt, or incomplete job state remains gated so
 * an interrupted mixed-version release cannot silently reopen to public traffic.
 */
function enforce_activation_gate(string $projectRoot): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $markerPath = activation_gate_path($projectRoot);
    if (!is_file($markerPath)) {
        return;
    }

    $marker = read_json_file($markerPath);
    if (is_array($marker) && activation_job_is_complete($projectRoot, $marker)) {
        @unlink($markerPath);
        if (!is_file($markerPath)) {
            return;
        }
    }

    if (is_array($marker) && activation_admin_recovery_allowed($marker)) {
        return;
    }

    emit_activation_unavailable();
    exit;
}
