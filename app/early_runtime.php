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
 *   - Support previous entry points during in-place file replacement
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
 *
 * @return string Uppercase hexadecimal correlation reference.
 */
function request_error_reference(): string
{
    try {
        return strtoupper(bin2hex(random_bytes(8)));
    } catch (Throwable) {
        return strtoupper(substr(
            hash('sha256', uniqid('gallery-error-', true)),
            0,
            16,
        ));
    }
}

/**
 * Return whether this request is known to expect a JSON response.
 *
 * @return bool True when headers or route parameters identify a JSON request.
 */
function request_expects_json(): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (str_contains($accept, 'application/json')) {
        return true;
    }

    $page = (string) ($_GET['page'] ?? '');
    if (in_array(
        $page,
        [
            'gallery_lightbox_data',
            'smart_gallery_lightbox_data',
            'smart_gallery_map_data',
            'gallery_map_data',
            'public_search',
            'admin_gallery_benchmark_status',
            'admin_gallery_benchmark_probe',
            'admin_test_run_probe',
            'admin_storage_statistics_update',
            'gallery_migration_manifest',
            'gallery_migration_receive_manifest',
            'gallery_migration_receive_asset',
            'gallery_migration_receive_package',
            'gallery_migration_receive_complete',
            'gallery_migration_receive_status',
            'upload_automation_upload',
        ],
        true,
    )) {
        return true;
    }

    return (
        $page === 'admin_update'
            && (
                (string) ($_GET['update_async'] ?? $_POST['update_async'] ?? '')
                === '1'
                || strtolower(
                    (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''),
                ) === 'xmlhttprequest'
            )
    );
}

/**
 * Log one emergency event without requiring bootstrap, database, or filesystem log services.
 *
 * @param string $kind Event kind, such as uncaught or fatal.
 * @param string $reference Public-safe request correlation reference.
 * @param string $errorClass Exception or PHP error class label.
 * @param string $message Error message to sanitize and log.
 * @param string $file Source file associated with the failure, when known.
 * @param int $line Source line associated with the failure, when known.
 * @return void Writes a sanitized emergency entry to PHP's error log.
 */
function log_emergency(
    string $kind,
    string $reference,
    string $errorClass = '',
    string $message = '',
    string $file = '',
    int $line = 0,
): void {
    $class = preg_replace(
        '/[^A-Za-z0-9_.-]/',
        '',
        str_replace('\\', '.', $errorClass),
    ) ?: 'unknown';
    $detail = trim(preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', $message) ?? '');
    if (strlen($detail) > 2000) {
        $detail = substr($detail, 0, 2000) . '...';
    }
    $location = $file !== '' ? $file . ($line > 0 ? ':' . $line : '') : '';
    $entry =
        '[PHP Gallery] '
        . $kind
        . ' reference='
        . $reference
        . ' type='
        . $class;
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
        echo
            $json === false
                ? '{"ok":false,"error":"Internal server error."}'
                : $json;
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo
        '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Server error</title></head><body><h1>Server error</h1><p>The request could not be completed.</p><p>Reference: '
            . $reference
            . '</p></body></html>';
}

/**
 * Convert one uncaught top-level Throwable to a safe HTTP 500 where headers remain mutable.
 *
 * @param Throwable $exception Uncaught exception or error.
 * @return void Logs the failure and emits a safe response when possible.
 */
function handle_uncaught(Throwable $exception): void
{
    $GLOBALS['php_gallery_emergency_handled'] = true;
    $reference = request_error_reference();
    log_emergency(
        'uncaught',
        $reference,
        get_class($exception),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
    );
    emit_server_error($reference);
}

/**
 * Register fatal shutdown handling before any normal application dependency is required.
 *
 * @return void Registers exception and shutdown handlers once per request.
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

        $fatalTypes = [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
        ];
        if (!in_array((int) ($error['type'] ?? 0), $fatalTypes, true)) {
            return;
        }

        $GLOBALS['php_gallery_emergency_handled'] = true;
        $reference = request_error_reference();
        log_emergency(
            'fatal',
            $reference,
            'php-error-' . (string) (int) ($error['type'] ?? 0),
            (string) ($error['message'] ?? ''),
            (string) ($error['file'] ?? ''),
            (int) ($error['line'] ?? 0),
        );
        emit_server_error($reference);
    });
}

/**
 * Resolve the historical marker for an updater service loaded before an overlay.
 *
 * @param string $projectRoot Absolute installation root.
 * @return string Path to the old marker, used only as updater evidence.
 */
function activation_gate_path(string $projectRoot): string
{
    return rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . 'maintenance.lock';
}

/**
 * Accept an older updater caller during the in-place transition.
 *
 * The compatibility entry points below can retire once supported installations
 * can no longer mix the older bootstrap, controller or updater with this file.
 * The public runtime performs no updater filesystem work.
 *
 * @param string $projectRoot Absolute installation root supplied by the old worker.
 * @return void Leaves legacy evidence in its original locations.
 */
function import_legacy_activation_gate(string $projectRoot): void
{
}

/**
 * Admit a request from a previous public or installer entry point.
 *
 * @param string $projectRoot Absolute installation root supplied by the old caller.
 * @return void Returns immediately, even when historical markers remain.
 */
function enforce_activation_gate(string $projectRoot): void
{
}

/**
 * Admit a request from a previous bootstrap during an in-place overlay.
 *
 * @param string $projectRoot Absolute installation root supplied by the old bootstrap.
 * @return void Returns without holding a visitor lease.
 */
function updater_runtime_enter(string $projectRoot): void
{
}

/**
 * Accept the previous scheduler and Admin controller's lease release call.
 *
 * @return void Returns immediately because this runtime holds no request lease.
 */
function updater_runtime_leave(): void
{
}
