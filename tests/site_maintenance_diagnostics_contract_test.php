<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/site_maintenance_diagnostics_contract_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects bounded Stage 10 site-maintenance failure diagnostics and cleanup isolation.
 *
 * Responsibilities:
 *   - Verify successful and failed optional cleanup operations return stable diagnostics
 *   - Verify cleanup exceptions do not escape into the surrounding maintenance cycle
 *   - Verify persisted/logged failure metadata excludes raw exception messages
 *   - Verify authenticated Admin status exposes separate last-success and last-failure records
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

namespace Gallery\Core {
    /** Return deterministic SQL time for the isolated maintenance fixture. */
    function now_sql(): string
    {
        return '2026-09-19 10:00:00';
    }

    /** Return empty request data for the isolated maintenance fixture. */
    function request_data(?string $bucket = null): array
    {
        return [];
    }
}

namespace Gallery\Services {
    /** Read one isolated maintenance fixture setting. */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return $GLOBALS['site_maintenance_test_settings'][$key] ?? $default;
    }

    /** Persist one isolated maintenance fixture setting. */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['site_maintenance_test_settings'][$key] = $value;
    }

    /** Delete isolated maintenance fixture settings. */
    function delete_app_settings(array $keys): void
    {
        foreach ($keys as $key) {
            unset($GLOBALS['site_maintenance_test_settings'][(string) $key]);
        }
    }

    /** Capture one maintenance log event without external persistence. */
    function admin_log_event(string $level, string $eventKey, string $message, array $context = [], array $options = []): void
    {
        $GLOBALS['site_maintenance_test_logs'][] = compact('level', 'eventKey', 'message', 'context', 'options');
    }
}

namespace {
    use function Gallery\Services\site_maintenance_last_failure;
    use function Gallery\Services\site_maintenance_run_cleanup_operation;

    /** Throw when one maintenance diagnostic contract fails. */
    function site_maintenance_diagnostics_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new RuntimeException($label);
        }
    }

    $root = dirname(__DIR__);
    $GLOBALS['site_maintenance_test_settings'] = [];
    $GLOBALS['site_maintenance_test_logs'] = [];
    require_once $root . '/app/services/site_maintenance.php';

    $state = ['cycle_date' => 'manual-2026-09-19-100000', 'source' => 'admin'];
    $success = site_maintenance_run_cleanup_operation('fixture_success', static fn(): array => ['deleted' => 2], $state);
    site_maintenance_diagnostics_assert($success['ok'] === true && ($success['value']['deleted'] ?? 0) === 2, 'Successful cleanup must return its existing result value.');
    site_maintenance_diagnostics_assert(($success['diagnostic']['status'] ?? '') === 'completed' && ($success['diagnostic']['operation'] ?? '') === 'fixture_success', 'Successful cleanup must expose stable operation/result diagnostics.');

    $failure = site_maintenance_run_cleanup_operation('fixture_failure', static function (): never {
        throw new RuntimeException('private path /secret/example and SQL SELECT * FROM users');
    }, $state);
    site_maintenance_diagnostics_assert($failure['ok'] === false, 'Optional cleanup failure must be isolated rather than thrown to the whole cycle.');
    site_maintenance_diagnostics_assert(($failure['diagnostic']['error_code'] ?? '') === 'cleanup_exception', 'Failed cleanup must expose a stable safe error code.');
    site_maintenance_diagnostics_assert(($failure['diagnostic']['exception_class'] ?? '') === RuntimeException::class, 'Failed cleanup may expose only the bounded exception class, not the message.');

    $serialized = json_encode([$failure, $GLOBALS['site_maintenance_test_settings'], $GLOBALS['site_maintenance_test_logs']], JSON_UNESCAPED_SLASHES);
    site_maintenance_diagnostics_assert(is_string($serialized) && !str_contains($serialized, '/secret/example') && !str_contains($serialized, 'SELECT * FROM users'), 'Exception messages containing paths or SQL must not be persisted or logged.');

    $lastFailure = site_maintenance_last_failure();
    site_maintenance_diagnostics_assert(($lastFailure['operation'] ?? '') === 'fixture_failure' && ($lastFailure['error_code'] ?? '') === 'cleanup_exception', 'Most recent isolated cleanup failure must be persisted for authenticated Admin diagnostics.');

    $serviceSource = (string) file_get_contents($root . '/app/services/site_maintenance.php');
    $viewSource = (string) file_get_contents($root . '/app/views/admin_dashboard_sections.php');
    site_maintenance_diagnostics_assert(
        str_contains($serviceSource, "'last_success' => site_maintenance_last_success()")
            && str_contains($serviceSource, "'last_failure' => site_maintenance_last_failure()")
            && str_contains($viewSource, 'admin.site_maintenance.last_success_summary')
            && str_contains($viewSource, 'admin.site_maintenance.last_failure_summary'),
        'Authenticated Admin diagnostics must surface distinct most-recent success and failure outcomes.'
    );

    echo "site_maintenance_diagnostics_contract_test: ok\n";
}
