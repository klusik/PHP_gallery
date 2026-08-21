<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/request_trigger_latency_safety_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Prevents opportunistic maintenance from holding visible gallery requests open
 *   on shared-hosting SAPIs that cannot detach shutdown work from the response.
 *
 * Responsibilities:
 *   - Preserve the one-minute site-maintenance throttle without turning it into latency
 *   - Require a real FastCGI/LiteSpeed response-finishing primitive before inline work
 *   - Apply the same safety rule to daily Admin-log archive maintenance
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
 * Throw when a request-trigger latency safety contract is not satisfied.
 *
 * @param bool $condition Assertion condition.
 * @param string $label Assertion label.
 */
function assert_request_trigger_latency_safety(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$siteSource = (string) file_get_contents(__DIR__ . '/../app/services/site_maintenance.php');
$archiveSource = (string) file_get_contents(__DIR__ . '/../app/services/admin_log_archives.php');

assert_request_trigger_latency_safety(
    str_contains($siteSource, "app_setting('site_maintenance_request_trigger_interval_seconds', '60')"),
    'The existing one-minute opportunistic-maintenance throttle remains explicit.'
);

assert_request_trigger_latency_safety(
    str_contains($siteSource, 'function site_maintenance_request_trigger_can_detach_response(): bool')
        && str_contains($siteSource, "function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')")
        && str_contains($siteSource, '|| !site_maintenance_request_trigger_can_detach_response()'),
    'Site maintenance must not register request-time background work unless the runtime can detach the visible response.'
);

$finishStart = strpos($siteSource, 'function site_maintenance_finish_response_before_background_work(): void');
$finishEnd = strpos($siteSource, '/**', $finishStart + 20);
$finishSource = $finishStart === false ? '' : substr($siteSource, $finishStart, ($finishEnd === false ? strlen($siteSource) : $finishEnd) - $finishStart);
assert_request_trigger_latency_safety(
    !str_contains($finishSource, 'ob_end_flush') && !str_contains($finishSource, '@flush()'),
    'Generic output-buffer flushing must not be treated as proof that a shared-hosting response was detached.'
);

assert_request_trigger_latency_safety(
    str_contains($archiveSource, 'function admin_log_archive_request_trigger_can_detach_response(): bool')
        && str_contains($archiveSource, '|| !admin_log_archive_request_trigger_can_detach_response()'),
    'Admin-log archive shutdown maintenance must use the same no-visible-latency detachment rule.'
);

fwrite(STDOUT, "Request-trigger latency safety tests passed.\n");
