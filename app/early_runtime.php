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
 * Return the updater activation marker path without loading updater services.
 *
 * @param string $projectRoot Absolute path to the project root.
 * @return string Absolute path to the activation marker.
 */
function activation_gate_path(string $projectRoot): string
{
    return (
        rtrim($projectRoot, '/\\')
            . DIRECTORY_SEPARATOR
            . 'cache'
            . DIRECTORY_SEPARATOR
            . 'updates'
            . DIRECTORY_SEPARATOR
            . 'activation.json'
    );
}

/**
 * Read one JSON file conservatively, returning null for missing or malformed state.
 *
 * @param string $path Absolute path to the JSON file.
 * @return array<string,mixed>|null Decoded JSON object, or null when it is unavailable or invalid.
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
 *
 * @param string $projectRoot Absolute path to the project root.
 * @param array<string,mixed> $marker Activation marker data read from disk.
 * @return bool True for a completed isolated job or legacy activation-complete receipt.
 */
function activation_job_is_complete(string $projectRoot, array $marker): bool
{
    $jobId = (string) ($marker['job_id'] ?? '');
    if (preg_match('/^\d{14}-[0-9a-f]{12}$/', $jobId) !== 1) {
        return false;
    }

    $jobPath =
        rtrim($projectRoot, '/\\')
        . DIRECTORY_SEPARATOR
        . 'cache'
        . DIRECTORY_SEPARATOR
        . 'updates'
        . DIRECTORY_SEPARATOR
        . 'jobs'
        . DIRECTORY_SEPARATOR
        . $jobId
        . DIRECTORY_SEPARATOR
        . 'job.json';
    $job = read_json_file($jobPath);
    return (
        is_array($job)
            && hash_equals($jobId, (string) ($job['id'] ?? ''))
            && (!empty($marker['recovery_isolation'])
                ? ($job['status'] ?? '') === 'completed'
                : !empty($job['checkpoints']['activation_complete']))
    );
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
    if (
        $sessionName === ''
        || preg_match('/^[A-Za-z0-9_-]{1,128}$/', $sessionName) !== 1
        || empty($_COOKIE[$sessionName])
    ) {
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
 * New isolated jobs remain gated until completion; legacy jobs use the durable
 * activation_complete receipt. Missing, corrupt, or incomplete state remains gated so
 * an interrupted mixed-version release cannot silently reopen to public traffic.
 *
 * @param string $projectRoot Absolute path to the project root.
 * @return void Emits the unavailable response and exits when the activation gate blocks the request.
 */
function enforce_activation_gate(string $projectRoot): void
{
    updater_runtime_enter($projectRoot);
    if (PHP_SAPI === 'cli') {
        return;
    }

    $markerPath = activation_gate_path($projectRoot);
    if (!is_file($markerPath)) {
        return;
    }

    $marker = read_json_file($markerPath);
    if (
        is_array($marker)
        && activation_job_is_complete($projectRoot, $marker)
    ) {
        @unlink($markerPath);
        if (!is_file($markerPath)) {
            return;
        }
    }

    if (is_array($marker) && (activation_admin_recovery_allowed($marker)
        || updater_worker_allowed($projectRoot, $marker))) {
        return;
    }

    emit_activation_unavailable();
    exit();
}

/**
 * Identify the cooperative request isolation contract in verified packages.
 * Type: int. Units: protocol revision. Scope: application bootstrap and updater.
 * Consumers: activation and rollback admission.
 * Rationale: revision 1 promises shared request leases and durable use witnesses;
 * older packages cannot establish this proof merely by having a valid manifest.
 */
const UPDATER_RUNTIME_ISOLATION_PROTOCOL = 1;

/**
 * Use the established group-writable cache directory permission.
 * Type: int. Units: POSIX permission bits. Scope: updater bootstrap cache only.
 * Consumers: updater_runtime_enter().
 * Rationale: 0775 preserves hosting group access; the existing cache web policy
 * protects contents, and this mode grants no access to application credentials.
 */
const UPDATER_RUNTIME_DIRECTORY_MODE = 0775;

/**
 * Validate a private updater handoff before exempting its bootstrap from use tracking.
 * @param string $projectRoot Absolute installation root.
 * @param array<string,mixed> $owner Gate/window/job with its server-generated job_id.
 * @return bool True for an unexpired hashed ticket bound to this job or its rollback.
 */
function updater_worker_allowed(string $projectRoot, array $owner): bool
{
    if (($_GET['page'] ?? '') !== 'site_maintenance_cron'
        || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || array_diff(array_keys($_GET), ['page']) !== []
        || array_diff(array_keys($_POST), ['update_ticket']) !== []) {
        return false;
    }
    $ticket = $_POST['update_ticket'] ?? null;
    if (!is_string($ticket) || preg_match('/^[a-f0-9]{64}$/D', $ticket) !== 1) {
        return false;
    }
    $base = rtrim($projectRoot, '/\\') . '/cache/updates';
    $chain = read_json_file($base . '/background-chain.json');
    $active = read_json_file($base . '/active-job.json');
    $id = (string) ($chain['job_id'] ?? '');
    if (preg_match('/^\d{14}-[a-f0-9]{12}$/D', $id) !== 1
        || ($active['job_id'] ?? '') !== $id
        || (int) ($chain['queued_until'] ?? 0) <= time()
        || !hash_equals((string) ($chain['ticket_hash'] ?? ''), hash('sha256', $ticket))) {
        return false;
    }
    $job = read_json_file($base . '/jobs/' . $id . '/job.json');
    $ownerId = (string) ($owner['job_id'] ?? '');
    return ($job['status'] ?? '') === 'running'
        && ($id === $ownerId || (!empty($job['automatic_rollback'])
            && ($job['parameters']['source_job_id'] ?? '') === $ownerId));
}

/**
 * Acquire a shared runtime lease and record admission during a rollback window.
 * @param string $projectRoot Absolute installation root.
 * @return void Holds a request-local lease until shutdown; refuses unwitnessed use.
 */
function updater_runtime_enter(string $projectRoot): void
{
    if (isset($GLOBALS['gallery_updater_runtime_lease'])
        || (defined('GALLERY_UPDATER_WORKER') && GALLERY_UPDATER_WORKER === true)) {
        return;
    }
    $base = rtrim($projectRoot, '/\\') . '/cache/updates';
    $owner = read_json_file($base . '/rollback-window.json');
    if ($owner === null) {
        $active = read_json_file($base . '/active-job.json');
        $id = (string) ($active['job_id'] ?? '');
        if (preg_match('/^\d{14}-[a-f0-9]{12}$/D', $id) === 1) {
            $job = read_json_file($base . '/jobs/' . $id . '/job.json');
            $owner = ['job_id' => $id,
                'admin_session_name' => (string) ($job['admin_session_name'] ?? '')];
        }
    }
    if (is_array($owner) && (activation_admin_recovery_allowed($owner)
        || updater_worker_allowed($projectRoot, $owner))) {
        return;
    }
    if (!is_dir($base) && !@mkdir($base, UPDATER_RUNTIME_DIRECTORY_MODE, true)
        && !is_dir($base)) {
        throw new \RuntimeException('Updater runtime isolation storage is unavailable.');
    }
    $lease = @fopen($base . '/runtime-use.lock', 'c+b');
    if ($lease === false || !flock($lease, LOCK_SH | LOCK_NB)) {
        if (is_resource($lease)) { fclose($lease); }
        emit_activation_unavailable();
        exit();
    }
    $GLOBALS['gallery_updater_runtime_lease'] = $lease;
    register_shutdown_function(__NAMESPACE__ . '\\updater_runtime_leave');
    $gate = read_json_file($base . '/activation.json');
    if (is_array($gate) && !empty($gate['recovery_isolation'])
        && !activation_job_is_complete($projectRoot, $gate)) {
        emit_activation_unavailable();
        exit();
    }
    // Read only after taking the shared lock: activation creates the window
    // under its exclusive lock, so admission cannot race past the witness.
    if (is_file($base . '/rollback-window.json')) {
        $window = read_json_file($base . '/rollback-window.json');
        $id = (string) ($window['job_id'] ?? '');
        if (preg_match('/^\d{14}-[a-f0-9]{12}$/D', $id) !== 1
            || @file_put_contents($base . '/jobs/' . $id . '/runtime-used.json',
                json_encode(['at' => time()], JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new \RuntimeException('Updater runtime use cannot be witnessed.');
        }
    }
}

/**
 * Release the request lease after domain handling, before post-response update work.
 * @return void Releases an owned resource once; repeated calls are harmless.
 */
function updater_runtime_leave(): void
{
    $lease = $GLOBALS['gallery_updater_runtime_lease'] ?? null;
    unset($GLOBALS['gallery_updater_runtime_lease']);
    if (is_resource($lease)) {
        flock($lease, LOCK_UN);
        fclose($lease);
    }
}
