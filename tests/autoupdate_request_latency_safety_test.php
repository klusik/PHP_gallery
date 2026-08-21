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
 *   - Keep the existing hourly update throttle
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

$serviceSource = (string) file_get_contents(__DIR__ . '/../app/services/updates_install.php');
$bootstrapSource = (string) file_get_contents(__DIR__ . '/../app/bootstrap/maintenance.php');
$controllerSource = (string) file_get_contents(__DIR__ . '/../app/controllers/updates.php');
$dispatchSource = (string) file_get_contents(__DIR__ . '/../app/bootstrap/dispatch.php');

assert_autoupdate_request_latency_safety(
    str_contains($bootstrapSource, 'application_autoupdate_maybe_run();'),
    'Request maintenance must use the established automatic-update entry point.'
);

assert_autoupdate_request_latency_safety(
    !str_contains($serviceSource, 'function application_autoupdate_fire_background_tick(): bool')
        && !str_contains($serviceSource, 'X-Gallery-Autoupdate-Token:')
        && !str_contains($serviceSource, 'fsockopen('),
    'Automatic updates must not create loopback HTTP requests that can consume the same shared-host PHP worker pool.'
);

assert_autoupdate_request_latency_safety(
    str_contains($serviceSource, 'function application_autoupdate_maybe_run(int $ttlSeconds = 3600): void')
        && str_contains($serviceSource, 'application_update_continue_background_job(3.0);')
        && str_contains($serviceSource, '$ttlSeconds = max(3600, $ttlSeconds);')
        && str_contains($serviceSource, 'application_autoupdate_run_installing_check(false, $now);'),
    'The established hourly automatic-update throttle and bounded resumable job lifecycle must remain enabled.'
);

assert_autoupdate_request_latency_safety(
    !str_contains($controllerSource, 'function cms_application_autoupdate_tick(): void')
        && !str_contains($dispatchSource, "'application_autoupdate_tick'"),
    'The removed private loopback worker endpoint must not remain reachable.'
);

fwrite(STDOUT, "Automatic-update shared-host worker safety tests passed.\n");
