<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/app_linking_passive_profile_test.php
 * Module Type: PHP Regression Test
 * Purpose: Prove A2 request-local profiling is off by default and privacy-safe.
 * Responsibilities:
 *   - Validate authorization-gated, fixed-format phase diagnostics.
 *   - Reuse the real SQL telemetry dispatch without retaining SQL text.
 *   - Verify hard caps, no header before authorization and no persistence.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/../app/services/app_linking_passive_profile.php';
require_once __DIR__ . '/../app/database.php';

/**
 * Assert the fixed A2 contract without dumping sensitive values on failure.
 *
 * @param bool $condition One safe, fully evaluated fixture assertion.
 * @param string $name Constant assertion name for hosted test output.
 * @return void Throw an opaque failure rather than printing sampled state.
 */
function a2_profile_check(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('A2 PHP passive contract failed: ' . $name);
    }
}

$prior = getenv('PHP_GALLERY_A2_PASSIVE_PROFILE');
try {
    putenv('PHP_GALLERY_A2_PASSIVE_PROFILE');
    \Gallery\Services\app_linking_passive_reset();
    a2_profile_check(
        !\Gallery\Services\app_linking_passive_begin('gallery_tree'),
        'default_off',
    );
    a2_profile_check(
        \Gallery\Services\app_linking_passive_snapshot() === null
        && \Gallery\Services\app_linking_passive_header() === null,
        'default_no_header',
    );
    a2_profile_check(
        ($GLOBALS['app_linking_passive_sql_observer'] ?? null) === null,
        'disabled_route_registers_no_sql_callback',
    );

    putenv('PHP_GALLERY_A2_PASSIVE_PROFILE=1');
    a2_profile_check(
        !\Gallery\Services\app_linking_passive_begin('user-controlled-url'),
        'strict_route',
    );
    a2_profile_check(
        \Gallery\Services\app_linking_passive_begin('gallery_tree'),
        'explicit_opt_in',
    );
    a2_profile_check(
        ($GLOBALS['app_linking_passive_sql_observer'] ?? null) instanceof \Closure,
        'opt_in_installs_request_local_sql_callback',
    );
    // Unauthorized work must never produce an externally visible profiler.
    $start = \Gallery\Services\app_linking_passive_span_start();
    \Gallery\Services\app_linking_passive_span_end('auth', $start);
    \Gallery\Core\database_dispatch_telemetry_query_observation(
        "SELECT 'private-url-and-token-value'", 4.5, true,
    );
    a2_profile_check(
        \Gallery\Services\app_linking_passive_snapshot() === null,
        'authorization_gate',
    );
    a2_profile_check(
        \Gallery\Services\app_linking_passive_header() === null,
        'no_unauthorized_metrics',
    );
    \Gallery\Services\app_linking_passive_authorized();
    $hashStart = \Gallery\Services\app_linking_passive_span_start();
    \Gallery\Services\app_linking_passive_span_end('file_hash', $hashStart, 65536);
    $snapshot = \Gallery\Services\app_linking_passive_snapshot();
    a2_profile_check(
        is_array($snapshot) && $snapshot['sql_count'] === 1
        && $snapshot['sql_total_ms'] === 4.5
        && $snapshot['sql_max_ms'] === 4.5
        && $snapshot['phases']['file_hash']['bytes'] === 65536,
        'numeric_only',
    );
    $header = \Gallery\Services\app_linking_passive_header();
    a2_profile_check(
        is_string($header)
        && str_contains($header, 'gallery-sql;dur=4.500')
        && str_contains($header, 'n=1;max_ms=4.500')
        && str_contains($header, 'bytes=65536')
        && !str_contains($header, 'private-url-and-token-value')
        && strlen($header) < 512,
        'bounded_safe_header',
    );
    for ($i = 0; $i < 520; ++$i) {
        \Gallery\Services\app_linking_passive_sql_observe(0.25, false);
    }
    $capped = \Gallery\Services\app_linking_passive_snapshot();
    a2_profile_check(
        is_array($capped) && $capped['sql_count'] === 512
        && $capped['sql_truncated'] === true
        && $capped['sql_failed'] === 511,
        'sql_cap_and_truncation',
    );
    // A subsequent request must start with a completely isolated sample.
    \Gallery\Services\app_linking_passive_begin('media_page');
    $next = \Gallery\Services\app_linking_passive_snapshot();
    a2_profile_check($next === null, 'new_request_not_authorized');
    \Gallery\Services\app_linking_passive_authorized();
    $next = \Gallery\Services\app_linking_passive_snapshot();
    a2_profile_check(
        is_array($next) && $next['sql_count'] === 0
        && $next['route'] === 'media_page',
        'no_prior_request_data',
    );
    \Gallery\Services\app_linking_passive_reset();
    a2_profile_check(
        ($GLOBALS['app_linking_passive_sql_observer'] ?? null) === null,
        'reset_disarms_request_local_sql_callback',
    );
    a2_profile_check(
        \Gallery\Services\app_linking_passive_header() === null,
        'explicit_teardown',
    );
    echo "PASS A2 passive PHP unit and SQL-dispatch contracts\n";
} finally {
    \Gallery\Services\app_linking_passive_reset();
    if ($prior === false) {
        putenv('PHP_GALLERY_A2_PASSIVE_PROFILE');
    } else {
        putenv('PHP_GALLERY_A2_PASSIVE_PROFILE=' . $prior);
    }
}
