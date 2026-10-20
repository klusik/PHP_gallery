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

require_once __DIR__ . '/support/php_source.php';

/**
 * Throw when a request-trigger latency safety contract is not satisfied.
 *
 * @param bool $condition Assertion condition.
 * @param string $label Assertion label.
 * @return void Returns normally only when the condition is true.
 */
function assert_request_trigger_latency_safety(
    bool $condition,
    string $label,
): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$siteSource = (string) file_get_contents(__DIR__
    . '/../app/services/site_maintenance.php');
$archiveSource = (string) file_get_contents(__DIR__
    . '/../app/services/admin_log_archives.php');

assert_request_trigger_latency_safety(
    php_source_contains(
        $siteSource,
        "app_setting('site_maintenance_request_trigger_interval_seconds', '60')",
    ),
    'The existing one-minute opportunistic-maintenance throttle remains explicit.',
);

assert_request_trigger_latency_safety(
    php_source_contains(
        $siteSource,
        'function site_maintenance_request_trigger_can_detach_response(): bool',
    )
        && php_source_contains(
            $siteSource,
            "function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')",
        )
        && php_source_contains(
            $siteSource,
            '!site_maintenance_route_allows_request_trigger($page) || !site_maintenance_request_trigger_can_detach_response() || !site_maintenance_request_trigger_due()',
        ),
    'Site maintenance must not register request-time background work unless the runtime can detach the visible response.',
);

$finishStart = strpos(
    $siteSource,
    'function site_maintenance_finish_response_before_background_work(',
);
$finishEnd = strpos($siteSource, '/**', $finishStart + 20);
$finishSource = $finishStart === false
    ? ''
    : substr(
        $siteSource,
        $finishStart,
        ($finishEnd === false ? strlen($siteSource) : $finishEnd)
            - $finishStart,
    );
assert_request_trigger_latency_safety(
    !str_contains($finishSource, 'ob_end_flush')
        && !str_contains($finishSource, '@flush()'),
    'Generic output-buffer flushing must not be treated as proof that a shared-hosting response was detached.',
);

assert_request_trigger_latency_safety(
    php_source_contains(
        $archiveSource,
        'function admin_log_archive_request_trigger_can_detach_response(): bool',
    )
        && php_source_contains(
            $archiveSource,
            '|| !admin_log_archive_request_trigger_can_detach_response()',
        ),
    'Admin-log archive shutdown maintenance must use the same no-visible-latency detachment rule.',
);

assert_request_trigger_latency_safety(
    php_source_contains($siteSource, "return \$page === 'admin';")
        && !php_source_contains($siteSource, "'gallery',
        'home',
        'share',
        'tag'"),
    'Anonymous/public gallery requests must never schedule after-response site maintenance that continues occupying a PHP worker.',
);

assert_request_trigger_latency_safety(
    php_source_contains(
        $archiveSource,
        "return in_array(\$page, ['admin', 'admin_logs'], true);",
    )
        && !php_source_contains(
            $archiveSource,
            "['home', 'gallery', 'share', 'tag', 'admin', 'admin_logs']",
        ),
    'Anonymous/public gallery requests must never schedule Admin-log archive work on the PHP worker pool.',
);

fwrite(STDOUT, "Request-trigger latency safety tests passed.\n");
