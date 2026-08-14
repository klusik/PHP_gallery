<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/gallery_access_schema_policy_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies Phase 9 gallery access, visibility, share-token, and protected-route schema policy.
 *
 * Responsibilities:
 *   - Preserve verified current-schema behavior
 *   - Preserve only the proven fully legacy access compatibility path
 *   - Refuse partially applied access migrations instead of defaulting to public/listed
 *   - Distinguish legacy draft visibility vocabulary from unknown enum inspection
 *   - Refuse share-token use when its optional storage column is unknown
 *   - Prove gallery, media, thumbnail, metadata, and download handlers fail before output
 *   - Verify public 503 logs and bodies omit simulated secrets and SQL text
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - The schema executor seam prevents any connection to a local database.
 *   - Dispatcher behavior runs in an isolated child fixture with sentinel handlers.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-08-12
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/services/schema_inspection.php';
require_once __DIR__ . '/../app/services/gallery_access.php';

use Gallery\Services\GalleryAccessSchemaUnavailableException;
use Gallery\Services\GalleryShareTokenSchemaUnavailableException;
use Gallery\Services\GalleryVisibilitySchemaUnavailableException;
use function Gallery\Services\gallery_access_assert_public_policy_available;
use function Gallery\Services\gallery_access_requirement;
use function Gallery\Services\gallery_access_schema_is_confirmed_legacy;
use function Gallery\Services\gallery_access_schema_ready;
use function Gallery\Services\gallery_access_schema_status;
use function Gallery\Services\gallery_access_share_token_schema_status;
use function Gallery\Services\gallery_is_public_listed;
use function Gallery\Services\gallery_visibility_schema_status;
use function Gallery\Services\gallery_visibility_storage_value;
use function Gallery\Services\request_share_token_allows_gallery;
use function Gallery\Services\schema_inspection_set_query_executor_for_tests;

/**
 * Assert strict equality for one Phase 9 gallery policy expectation.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual Actual value.
 * @param string $label Assertion label.
 */
function gallery_access_policy_assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/**
 * Assert that one callback throws the expected public-policy exception type.
 *
 * @param callable $callback Callback expected to fail closed.
 * @param string $exceptionClass Expected exception class.
 * @param string $label Assertion label.
 */
function gallery_access_policy_assert_throws(callable $callback, string $exceptionClass, string $label): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $exceptionClass) {
            return;
        }
        throw new RuntimeException($label . ' threw ' . $exception::class . ' instead of ' . $exceptionClass . '.');
    }
    throw new RuntimeException($label . ' did not fail closed.');
}

/**
 * Run one isolated dispatcher security-policy scenario.
 *
 * @param string $state Fixture schema state.
 * @param string $route Protected public route.
 * @return array<string,mixed> Fixture result.
 */
function gallery_access_policy_dispatch_fixture(string $state, string $route): array
{
    $fixture = __DIR__ . '/support/security_schema_policy_dispatch_fixture.php';
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($fixture)
        . ' ' . escapeshellarg($state)
        . ' ' . escapeshellarg($route)
        . ' 2>&1';
    $lines = [];
    $exitCode = 0;
    exec($command, $lines, $exitCode);
    $output = implode("\n", $lines);
    if ($exitCode !== 0) {
        throw new RuntimeException('Security dispatcher fixture failed for ' . $state . '/' . $route . ': ' . $output);
    }
    $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($result)) {
        throw new RuntimeException('Security dispatcher fixture returned invalid JSON.');
    }
    return $result;
}

// Verified current access schema keeps password/listing behavior unchanged.
schema_inspection_set_query_executor_for_tests(static fn (): bool => true);
$availableAccess = gallery_access_schema_status();
gallery_access_policy_assert_same('available', $availableAccess['state'], 'available access state');
gallery_access_policy_assert_same(true, gallery_access_schema_ready(), 'available access readiness');
gallery_access_policy_assert_same(false, gallery_access_schema_is_confirmed_legacy($availableAccess), 'available schema not legacy');
$currentGallery = [
    'id' => 17,
    'parent_id' => null,
    'visibility' => 'public',
    'access_mode' => 'password',
    'access_listing' => 'unlisted',
    'access_password_hash' => 'fixture-hash',
];
gallery_access_policy_assert_same($currentGallery, gallery_access_requirement($currentGallery), 'available password requirement');
gallery_access_policy_assert_same(false, gallery_is_public_listed($currentGallery), 'available unlisted policy');

// A fully confirmed pre-access schema is the only permissive compatibility path.
schema_inspection_set_query_executor_for_tests(static function (string $objectType, string $table, string $object): bool {
    if ($objectType === 'column' && $table === 'galleries' && str_starts_with($object, 'access_')) {
        return false;
    }
    return true;
});
$legacyAccess = gallery_access_schema_status();
gallery_access_policy_assert_same('missing', $legacyAccess['state'], 'legacy access state');
gallery_access_policy_assert_same(true, gallery_access_schema_is_confirmed_legacy($legacyAccess), 'legacy access classification');
gallery_access_assert_public_policy_available();
gallery_access_policy_assert_same(null, gallery_access_requirement(['id' => 18, 'parent_id' => null, 'visibility' => 'public']), 'legacy unprotected compatibility');
gallery_access_policy_assert_same(true, gallery_is_public_listed(['id' => 18, 'parent_id' => null, 'visibility' => 'public']), 'legacy listed compatibility');

// One missing access column means a partial migration, not a safe legacy schema.
schema_inspection_set_query_executor_for_tests(static function (string $objectType, string $table, string $object): bool {
    if ($objectType === 'column' && $table === 'galleries' && $object === 'access_token_expires_at') {
        return false;
    }
    return true;
});
$partialAccess = gallery_access_schema_status();
gallery_access_policy_assert_same('missing', $partialAccess['state'], 'partial access aggregate state');
gallery_access_policy_assert_same(false, gallery_access_schema_is_confirmed_legacy($partialAccess), 'partial access not legacy');
gallery_access_policy_assert_throws(static fn () => gallery_access_assert_public_policy_available(), GalleryAccessSchemaUnavailableException::class, 'partial access assertion');
gallery_access_policy_assert_throws(static fn () => gallery_access_requirement($currentGallery), GalleryAccessSchemaUnavailableException::class, 'partial access requirement');

// Metadata inspection failure must fail closed and must not expose the raw exception.
schema_inspection_set_query_executor_for_tests(static function (string $objectType, string $table, string $object): bool {
    if ($objectType === 'column' && $table === 'galleries' && $object === 'access_mode') {
        throw new RuntimeException('fixture-secret password=phase9 SELECT access_mode FROM galleries');
    }
    return true;
});
$unknownAccess = gallery_access_schema_status();
gallery_access_policy_assert_same('unknown', $unknownAccess['state'], 'unknown access state');
gallery_access_policy_assert_same(false, str_contains((string) json_encode($unknownAccess), 'fixture-secret'), 'unknown access redaction');
gallery_access_policy_assert_throws(static fn () => gallery_access_assert_public_policy_available(), GalleryAccessSchemaUnavailableException::class, 'unknown access assertion');

// Visibility storage maps canonical unpublished to draft only after confirmed legacy enum inspection.
schema_inspection_set_query_executor_for_tests(static function (string $objectType, string $table, string $object, string $token = ''): bool {
    if ($objectType === 'column_definition_contains' && $table === 'galleries' && $object === 'visibility' && $token === 'unpublished') {
        return false;
    }
    return true;
});
$legacyVisibility = gallery_visibility_schema_status();
gallery_access_policy_assert_same('missing', $legacyVisibility['state'], 'legacy visibility state');
gallery_access_policy_assert_same('draft', gallery_visibility_storage_value('unpublished'), 'legacy visibility storage mapping');
gallery_access_policy_assert_same('public', gallery_visibility_storage_value('public'), 'legacy visibility public storage');

// Unknown enum metadata can never be guessed as draft or unpublished.
schema_inspection_set_query_executor_for_tests(static function (string $objectType, string $table, string $object, string $token = ''): bool {
    if ($objectType === 'column_definition_contains' && $table === 'galleries' && $object === 'visibility' && $token === 'unpublished') {
        throw new RuntimeException('fixture-secret visibility enum SQL failed');
    }
    return true;
});
$unknownVisibility = gallery_visibility_schema_status();
gallery_access_policy_assert_same('unknown', $unknownVisibility['state'], 'unknown visibility state');
gallery_access_policy_assert_throws(static fn () => gallery_visibility_storage_value('unpublished'), GalleryVisibilitySchemaUnavailableException::class, 'unknown visibility storage');

// Share-token storage missing means the token path is unavailable, while unknown is an operational failure.
$_GET['share'] = 'fixture-public-token';
schema_inspection_set_query_executor_for_tests(static function (string $objectType, string $table, string $object): bool {
    if ($objectType === 'column' && $table === 'galleries' && $object === 'access_share_token') {
        return false;
    }
    return true;
});
$missingShare = gallery_access_share_token_schema_status();
gallery_access_policy_assert_same('missing', $missingShare['state'], 'missing share-token state');
gallery_access_policy_assert_same(false, request_share_token_allows_gallery($currentGallery), 'missing share-token refusal');

schema_inspection_set_query_executor_for_tests(static function (string $objectType, string $table, string $object): bool {
    if ($objectType === 'column' && $table === 'galleries' && $object === 'access_share_token') {
        throw new RuntimeException('fixture-secret share token metadata failure');
    }
    return true;
});
gallery_access_policy_assert_throws(static fn () => request_share_token_allows_gallery($currentGallery), GalleryShareTokenSchemaUnavailableException::class, 'unknown share-token refusal');
unset($_GET['share']);

// The real dispatcher must fail before sensitive handlers for partial or unknown access policy.
foreach (['gallery' => 'html', 'public_media' => 'text', 'public_thumb' => 'text', 'gallery_lightbox_data' => 'json', 'download_gallery' => 'text'] as $route => $format) {
    foreach (['partial_access' => 'gallery_access', 'unknown_access' => 'gallery_access', 'unknown_visibility' => 'gallery_visibility'] as $state => $feature) {
        $result = gallery_access_policy_dispatch_fixture($state, $route);
        gallery_access_policy_assert_same(503, $result['status'] ?? null, $state . '/' . $route . ' status');
        gallery_access_policy_assert_same(false, $result['handler_reached'] ?? null, $state . '/' . $route . ' handler blocked');
        $body = (string) ($result['body'] ?? '');
        gallery_access_policy_assert_same(true, str_contains($body, 'request-phase9-503'), $state . '/' . $route . ' request reference');
        gallery_access_policy_assert_same(false, str_contains($body, 'protected-handler-output'), $state . '/' . $route . ' no handler bytes');
        foreach (['fixture-secret', 'password=phase9', 'SELECT access_mode', 'dsn=mysql:private'] as $forbidden) {
            gallery_access_policy_assert_same(false, str_contains($body, $forbidden), $state . '/' . $route . ' public redaction ' . $forbidden);
        }
        $log = is_array($result['log'] ?? null) ? $result['log'] : [];
        gallery_access_policy_assert_same('security.public_schema_inspection_unavailable', $log['eventKey'] ?? null, $state . '/' . $route . ' generic event');
        gallery_access_policy_assert_same($feature, $log['context']['feature'] ?? null, $state . '/' . $route . ' bounded feature');
        $expectedSchemaState = $state === 'partial_access' ? 'missing' : 'unknown';
        $expectedErrorCode = $state === 'partial_access' ? 'partial_schema' : 'inspection_failed';
        gallery_access_policy_assert_same($expectedSchemaState, $log['context']['schema_state'] ?? null, $state . '/' . $route . ' bounded schema state');
        gallery_access_policy_assert_same($expectedErrorCode, $log['context']['error_code'] ?? null, $state . '/' . $route . ' bounded error code');
        gallery_access_policy_assert_same($format, $log['context']['response_format'] ?? null, $state . '/' . $route . ' response format');
        gallery_access_policy_assert_same(false, str_contains((string) json_encode($log), 'fixture-secret'), $state . '/' . $route . ' log redaction');
    }
}

// Verified current schema still reaches the established handler.
$availableDispatch = gallery_access_policy_dispatch_fixture('available', 'public_media');
gallery_access_policy_assert_same(200, $availableDispatch['status'] ?? null, 'available dispatcher status');
gallery_access_policy_assert_same(true, $availableDispatch['handler_reached'] ?? null, 'available dispatcher handler');

schema_inspection_set_query_executor_for_tests(null);
echo "Gallery access schema policy checks passed.\n";
