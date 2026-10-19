<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/autoupdate_request_latency_safety_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Prevents automatic-update optimizations from spawning loopback HTTP requests
 *   back into the same shared-host PHP worker pool.
 *
 * Responsibilities:
 *   - Preserve the established automatic update lifecycle
 *   - Prevent public requests from creating self-request worker amplification
 *   - Keep the configured metadata throttle and resumable job continuation
 *   - Keep resumable update processing bounded by the established request slice
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

/**
 * Throw when an automatic-update request-safety contract is not satisfied.
 *
 * @param bool $condition Assertion condition.
 * @param string $label Assertion label.
 */
function assert_autoupdate_request_latency_safety(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$serviceSource = (string) file_get_contents(__DIR__ . '/../app/services/updates_request.php')
    . (string) file_get_contents(__DIR__ . '/../app/services/updates_install.php');
$bootstrapSource = (string) file_get_contents(__DIR__ . '/../app/bootstrap/maintenance.php');
$controllerSource = (string) file_get_contents(__DIR__ . '/../app/controllers/updates.php');
$dispatchSource = (string) file_get_contents(__DIR__ . '/../app/bootstrap/dispatch.php');
$statusSource = (string) file_get_contents(__DIR__ . '/../app/services/updates_status.php');

assert_autoupdate_request_latency_safety(
    str_contains($bootstrapSource, 'application_autoupdate_maybe_run($intervalSeconds, request_method(), $kernel')
        && str_contains($bootstrapSource, '$intervalSeconds = application_autoupdate_interval_seconds();')
        && strpos($bootstrapSource, "\$kernel?->load('request-maintenance');") < strpos($bootstrapSource, '$intervalSeconds = application_autoupdate_interval_seconds();'),
    'Request maintenance must load the lightweight updater policy, read the saved interval, and pass the request method explicitly.'
);

assert_autoupdate_request_latency_safety(
    !str_contains($serviceSource, 'function application_autoupdate_fire_background_tick(): bool')
        && !str_contains($serviceSource, 'X-Gallery-Autoupdate-Token:')
        && !str_contains($serviceSource, 'fsockopen('),
    'The retired unleased updater socket/token endpoint must stay absent; server continuation uses the existing authenticated maintenance transport.'
);

$backgroundSource = (string) file_get_contents(__DIR__ . '/../app/services/updates_background.php');
$maintenanceController = (string) file_get_contents(__DIR__ . '/../app/controllers/site_maintenance.php');
assert_autoupdate_request_latency_safety(
    str_contains($backgroundSource, "hash('sha256', \$ticket)")
        && str_contains($backgroundSource, 'application_update_release_lock($lock);')
        && !str_contains($backgroundSource, 'sleep(')
        && strpos($maintenanceController, 'if (!site_maintenance_token_is_valid($token))')
            < strpos($maintenanceController, 'application_update_background_chain_run('),
    'Autonomous updater work must consume hashed single-use tickets after authentication, release its chain lock before handoff and never sleep between worker slices.'
);

assert_autoupdate_request_latency_safety(
    str_contains($serviceSource, 'function application_autoupdate_maybe_run(')
        && str_contains($serviceSource, 'int $ttlSeconds = APPLICATION_AUTOUPDATE_DEFAULT_INTERVAL_SECONDS,')
        && str_contains($serviceSource, "string \$requestMethod = 'GET',")
        && str_contains($serviceSource, '?callable $loadDependencies = null')
        && str_contains($serviceSource, 'application_update_continue_background_job(3.0);')
        && str_contains($serviceSource, '$ttlSeconds = application_autoupdate_interval_seconds();')
        && str_contains($serviceSource, 'application_autoupdate_run_installing_check(false, $now);'),
    'The persisted automatic-update throttle and bounded resumable job lifecycle must remain enabled.'
);
assert_autoupdate_request_latency_safety(
    strpos($serviceSource, '$activeJob = application_update_active_job();')
        < strpos($serviceSource, "if (!in_array(\$method, ['GET', 'HEAD'], true)")
        && str_contains($serviceSource, "\$loadDependencies('updater-work');"),
    'Active jobs must precede new-check guards and load their explicit execution dependencies.'
);

assert_autoupdate_request_latency_safety(
    !str_contains($controllerSource, 'function cms_application_autoupdate_tick(): void')
        && !str_contains($dispatchSource, "'application_autoupdate_tick'"),
    'The removed private loopback worker endpoint must not remain reachable.'
);

assert_autoupdate_request_latency_safety(
    str_contains($statusSource, 'function application_update_discovery_try_lock()')
        && str_contains($statusSource, 'LOCK_EX | LOCK_NB')
        && str_contains($serviceSource, 'application_update_discovery_try_lock();')
        && str_contains($serviceSource, 'application_autoupdate_discovery_due($now, application_update_discovery_last_attempt_at())'),
    'Request, CLI, and Admin probes must share a nonblocking discovery lock and recheck due state.'
);

fwrite(STDOUT, "Automatic-update shared-host worker safety tests passed.\n");
