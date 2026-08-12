<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/nsfw_schema_policy_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies the three-state NSFW Guard schema policy without a live database.
 *
 * Responsibilities:
 *   - Preserve existing enforcement when both required columns are available
 *   - Preserve documented pre-NSFW compatibility for confirmed missing columns
 *   - Prove that unknown inspection state fails closed
 *   - Verify route-appropriate 503 response representations
 *   - Verify the central dispatcher catches the shared public schema-policy exception boundary
 *   - Verify protected media, thumbnails, lightbox metadata, and map metadata fail before handler output
 *   - Verify Admin diagnostics distinguish confirmed missing schema from unknown inspection state
 *   - Verify logged-in anonymous preview requests follow the same fail-closed public policy
 *   - Verify repeated NSFW capability checks query each required object only once per request
 *   - Remain executable with plain PHP in an isolated process
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - The schema executor seam prevents any connection to a local database.
 *   - Dispatcher behavior runs in a child fixture so route handlers can be safely replaced with sentinels.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-08-12
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/services/schema_inspection.php';
require_once __DIR__ . '/../app/services/gallery_access.php';
require_once __DIR__ . '/../app/services/admin_dashboard.php';
require_once __DIR__ . '/../app/controllers/http_helpers.php';

use Gallery\Services\NsfwGuardSchemaUnavailableException;
use function Gallery\Controllers\nsfw_guard_unavailable_response_format;
use function Gallery\Services\admin_nsfw_schema_health_model;
use function Gallery\Services\gallery_nsfw_requirement;
use function Gallery\Services\image_nsfw_restricted;
use function Gallery\Services\nsfw_guard_schema_ready;
use function Gallery\Services\nsfw_guard_schema_status;
use function Gallery\Services\schema_inspection_set_query_executor_for_tests;

/**
 * Throw when one strict NSFW policy expectation fails.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual Actual value.
 * @param string $label Assertion label.
 */
function nsfw_policy_assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/**
 * Assert that one callback fails through the dedicated safe-policy exception.
 *
 * @param callable $callback Callback expected to fail closed.
 * @param string $label Assertion label.
 */
function nsfw_policy_assert_unavailable(callable $callback, string $label): void
{
    try {
        $callback();
    } catch (NsfwGuardSchemaUnavailableException) {
        return;
    }
    throw new RuntimeException($label . ' did not fail closed.');
}

/**
 * Run one isolated real-dispatcher scenario and decode its fixture result.
 *
 * @param string $state Available, missing, or unknown schema state.
 * @param string $route Protected public route identifier.
 * @param bool $anonymousPreview Whether the fixture should emulate a logged-in anonymous preview.
 * @return array<string,mixed> Decoded fixture result.
 */
function nsfw_policy_dispatch_fixture(string $state, string $route, bool $anonymousPreview = false): array
{
    $fixture = __DIR__ . '/support/nsfw_policy_dispatch_fixture.php';
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($fixture)
        . ' ' . escapeshellarg($state)
        . ' ' . escapeshellarg($route)
        . ' ' . escapeshellarg($anonymousPreview ? '1' : '0')
        . ' 2>&1';
    $lines = [];
    $exitCode = 0;
    exec($command, $lines, $exitCode);
    $output = implode("\n", $lines);
    if ($exitCode !== 0) {
        throw new RuntimeException('Dispatcher fixture failed for ' . $state . '/' . $route . ': ' . $output);
    }
    $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($result)) {
        throw new RuntimeException('Dispatcher fixture returned an invalid result for ' . $state . '/' . $route . '.');
    }
    return $result;
}

/**
 * Assert that unknown schema state blocks one protected route before its handler emits output.
 *
 * @param string $route Protected public route identifier.
 * @param string $format Expected response representation.
 * @param bool $anonymousPreview Whether this route is tested as an anonymous preview.
 */
function nsfw_policy_assert_dispatch_blocked(string $route, string $format, bool $anonymousPreview = false): void
{
    $result = nsfw_policy_dispatch_fixture('unknown', $route, $anonymousPreview);
    nsfw_policy_assert_same(503, $result['status'] ?? null, $route . ' unknown HTTP status');
    nsfw_policy_assert_same(false, $result['handler_reached'] ?? null, $route . ' handler blocked');
    nsfw_policy_assert_same($anonymousPreview, $result['anonymous_preview_active'] ?? null, $route . ' anonymous preview state');

    $body = (string) ($result['body'] ?? '');
    nsfw_policy_assert_same(true, str_contains($body, '[translated]'), $route . ' translated public content');
    nsfw_policy_assert_same(true, str_contains($body, 'request-phase7-503'), $route . ' request reference');
    foreach (['protected-handler-output', 'fixture-secret', 'password=phase7', 'SELECT * FROM private_media'] as $forbidden) {
        nsfw_policy_assert_same(false, str_contains($body, $forbidden), $route . ' public redaction for ' . $forbidden);
    }

    if ($format === 'json') {
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        nsfw_policy_assert_same(false, $payload['ok'] ?? null, $route . ' JSON failure flag');
        nsfw_policy_assert_same('service_unavailable', $payload['error'] ?? null, $route . ' JSON error code');
    } elseif ($format === 'text') {
        nsfw_policy_assert_same(false, str_contains(strtolower($body), '<html'), $route . ' plain-text body');
    } else {
        nsfw_policy_assert_same(true, str_contains(strtolower($body), '<!doctype html>'), $route . ' HTML body');
    }
}

// Complete schema keeps the established gallery and image restriction behavior.
// Repeated feature and policy checks must reuse one request-local inspection per required column.
$availableQueryCounts = [];
schema_inspection_set_query_executor_for_tests(static function (string $objectType, string $table, string $object) use (&$availableQueryCounts): bool {
    $key = $objectType . ':' . $table . ':' . $object;
    $availableQueryCounts[$key] = ($availableQueryCounts[$key] ?? 0) + 1;
    return true;
});
$availableStatus = nsfw_guard_schema_status();
nsfw_policy_assert_same('available', $availableStatus['state'], 'complete schema state');
nsfw_policy_assert_same(true, nsfw_guard_schema_ready(), 'complete schema readiness');
$gallery = ['id' => 7, 'parent_id' => null, 'nsfw_enabled' => 1];
nsfw_policy_assert_same($gallery, gallery_nsfw_requirement($gallery), 'gallery restriction');
nsfw_policy_assert_same(true, image_nsfw_restricted(['nsfw_enabled' => 1], ['id' => 8, 'parent_id' => null, 'nsfw_enabled' => 0]), 'image restriction');
nsfw_policy_assert_same(1, $availableQueryCounts['column:galleries:nsfw_enabled'] ?? 0, 'gallery NSFW schema query count');
nsfw_policy_assert_same(1, $availableQueryCounts['column:images:nsfw_enabled'] ?? 0, 'image NSFW schema query count');
$availableHealth = admin_nsfw_schema_health_model($availableStatus, true, 'unused-reference');
nsfw_policy_assert_same('available', $availableHealth['state'], 'complete schema Admin health');

// A complete existing installation still reaches the established public route handler.
$availableDispatch = nsfw_policy_dispatch_fixture('available', 'public_media');
nsfw_policy_assert_same(200, $availableDispatch['status'] ?? null, 'complete schema dispatcher status');
nsfw_policy_assert_same(true, $availableDispatch['handler_reached'] ?? null, 'complete schema dispatcher compatibility');
nsfw_policy_assert_same('protected-handler-output:public_media', $availableDispatch['body'] ?? null, 'complete schema unchanged route output');

// Confirmed old schema follows the documented historical compatibility path.
schema_inspection_set_query_executor_for_tests(static fn (): bool => false);
$missingStatus = nsfw_guard_schema_status();
nsfw_policy_assert_same('missing', $missingStatus['state'], 'missing schema state');
nsfw_policy_assert_same(false, nsfw_guard_schema_ready(), 'missing schema readiness');
nsfw_policy_assert_same(null, gallery_nsfw_requirement($gallery), 'missing gallery compatibility');
nsfw_policy_assert_same(false, image_nsfw_restricted(['nsfw_enabled' => 1], $gallery), 'missing image compatibility');
$missingHealth = admin_nsfw_schema_health_model($missingStatus, true, 'unused-reference');
nsfw_policy_assert_same('missing', $missingHealth['state'], 'missing Admin health state');
nsfw_policy_assert_same(['pending_migrations'], $missingHealth['suggested_checks'], 'missing Admin migration guidance');
$missingDispatch = nsfw_policy_dispatch_fixture('missing', 'public_media');
nsfw_policy_assert_same(200, $missingDispatch['status'] ?? null, 'missing schema compatibility status');
nsfw_policy_assert_same(true, $missingDispatch['handler_reached'] ?? null, 'missing schema compatibility reaches handler');

// An operational inspection failure can never become unrestricted access.
schema_inspection_set_query_executor_for_tests(static function (): bool {
    throw new RuntimeException('fixture-secret password=phase7 SELECT * FROM private_media');
});
$unknownStatus = nsfw_guard_schema_status();
nsfw_policy_assert_same('unknown', $unknownStatus['state'], 'unknown schema state');
nsfw_policy_assert_same(false, nsfw_guard_schema_ready(), 'unknown schema readiness');
nsfw_policy_assert_unavailable(static fn () => gallery_nsfw_requirement($gallery), 'unknown gallery policy');
nsfw_policy_assert_unavailable(static fn () => image_nsfw_restricted([], $gallery), 'unknown image policy');
nsfw_policy_assert_same(false, str_contains((string) json_encode($unknownStatus), 'fixture-secret'), 'unknown status omits raw fixture secret');
nsfw_policy_assert_same(false, str_contains((string) json_encode($unknownStatus), 'SELECT * FROM private_media'), 'unknown status omits raw SQL');

// Admin health must preserve unknown as an operational failure, never as disabled or migration-missing.
$unknownHealth = admin_nsfw_schema_health_model($unknownStatus, true, 'request-phase7-admin');
nsfw_policy_assert_same('unknown', $unknownHealth['state'], 'unknown Admin health state');
nsfw_policy_assert_same('request-phase7-admin', $unknownHealth['request_id'], 'unknown Admin request reference');
nsfw_policy_assert_same(['database_connection', 'selected_database', 'schema_inspection_permissions'], $unknownHealth['suggested_checks'], 'unknown Admin operational guidance');
nsfw_policy_assert_same(false, $unknownHealth['state'] === 'disabled', 'unknown is not NSFW disabled');
nsfw_policy_assert_same(false, $unknownHealth['suggested_checks'] === ['pending_migrations'], 'unknown is not migration-missing');

// Public response classification keeps machine and binary routes parseable.
nsfw_policy_assert_same('html', nsfw_guard_unavailable_response_format('gallery'), 'gallery response format');
nsfw_policy_assert_same('json', nsfw_guard_unavailable_response_format('gallery_lightbox_data'), 'lightbox response format');
nsfw_policy_assert_same('json', nsfw_guard_unavailable_response_format('gallery_map_data'), 'map response format');
nsfw_policy_assert_same('text', nsfw_guard_unavailable_response_format('public_media'), 'media response format');
nsfw_policy_assert_same('text', nsfw_guard_unavailable_response_format('public_thumb'), 'thumbnail response format');

// Unknown inspection state must fail before protected bytes or metadata handlers are reached.
nsfw_policy_assert_dispatch_blocked('public_media', 'text');
nsfw_policy_assert_dispatch_blocked('public_thumb', 'text');
nsfw_policy_assert_dispatch_blocked('gallery_lightbox_data', 'json');
nsfw_policy_assert_dispatch_blocked('gallery_map_data', 'json');

// Logged-in anonymous preview remains a public-policy request and follows the same safe failure behavior.
nsfw_policy_assert_dispatch_blocked('gallery', 'html', true);

// Source contracts protect necessary integration ownership and the forbidden mutation fallback.
$dispatchSource = (string) file_get_contents(__DIR__ . '/../app/bootstrap/dispatch.php');
nsfw_policy_assert_same(true, str_contains($dispatchSource, 'catch (PublicSchemaPolicyUnavailableException'), 'dispatcher safe boundary');
nsfw_policy_assert_same(true, str_contains($dispatchSource, 'nsfw_guard_assert_public_policy_available();'), 'dispatcher pre-output policy check');
$bulkSource = (string) file_get_contents(__DIR__ . '/../app/controllers/admin_images_bulk.php');
nsfw_policy_assert_same(true, str_contains($bulkSource, "in_array(\$action, ['nsfw_on', 'nsfw_off'], true) && !nsfw_guard_schema_ready()"), 'bulk mutation refusal');
$dashboardViewSource = (string) file_get_contents(__DIR__ . '/../app/views/admin_dashboard_sections.php');
nsfw_policy_assert_same(true, str_contains($dashboardViewSource, "view_admin_dashboard_array(\$model, 'security_schema_statuses')"), 'System Health status ownership');
$diagnosticsSource = (string) file_get_contents(__DIR__ . '/../app/controllers/admin_diagnostics.php');
nsfw_policy_assert_same(true, str_contains($diagnosticsSource, 'admin_nsfw_schema_health_status()'), 'Runtime Diagnostics shared NSFW health model');

schema_inspection_set_query_executor_for_tests(null);
echo "NSFW schema policy checks passed.\n";
