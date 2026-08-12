<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/service_unavailable_response_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies the route-aware NSFW Guard service-unavailable response boundary.
 *
 * Responsibilities:
 *   - Exercise HTML, JSON, and plain-text response bodies
 *   - Verify HTTP 503 status and stable public error vocabulary
 *   - Verify request-reference correlation in every representation
 *   - Verify bounded structured Admin-log context and event classification
 *   - Prove that private exception-like values never enter public output
 *   - Remain executable with plain PHP and no database connection
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Namespace-local helper doubles isolate translation, escaping, and logging.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-08-12
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Escape HTML for the isolated response test.
     *
     * @param string $value Text to escape.
     * @return string Escaped text.
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

namespace Gallery\Services {
    /**
     * Return deterministic translated test wording with placeholder expansion.
     *
     * @param string $key Translation key.
     * @param mixed $fallback Fallback text or replacements.
     * @param array $replacements Placeholder replacements.
     * @return string Resolved test text.
     */
    function t(string $key, mixed $fallback = '', array $replacements = []): string
    {
        if (is_array($fallback)) {
            $replacements = $fallback;
            $fallback = $key;
        }
        $text = (string) $fallback;
        foreach ($replacements as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    /**
     * Return a deterministic request identifier for response correlation tests.
     */
    function telemetry_request_id(): string
    {
        return 'request-phase5-123';
    }

    /**
     * Capture one operational event instead of writing to a database.
     *
     * @param string $level Legacy event level.
     * @param string $eventKey Stable event key.
     * @param string $message Safe internal message.
     * @param array $context Structured event context.
     * @param array $options Event classification and correlation options.
     */
    function admin_log_event(string $level, string $eventKey, string $message, array $context = [], array $options = []): void
    {
        if (!empty($GLOBALS['service_unavailable_test_log_failure'])) {
            throw new \RuntimeException('simulated Admin log storage failure');
        }
        $GLOBALS['service_unavailable_test_logs'][] = compact('level', 'eventKey', 'message', 'context', 'options');
    }
}

namespace {
    require_once __DIR__ . '/../app/controllers/http_helpers.php';

    use const Gallery\Controllers\NSFW_GUARD_SCHEMA_UNAVAILABLE_EVENT;
    use function Gallery\Controllers\cms_nsfw_guard_schema_unavailable;

    /**
     * Throw when one strict response-boundary expectation fails.
     *
     * @param mixed $expected Expected value.
     * @param mixed $actual Actual value.
     * @param string $label Assertion label.
     */
    function unavailable_response_assert_same(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    /**
     * Render one isolated response and return its body and captured log event.
     *
     * @param string $route Resolved route identifier.
     * @return array{body:string,log:array}
     */
    function unavailable_response_render(string $route): array
    {
        $GLOBALS['service_unavailable_test_logs'] = [];
        http_response_code(200);
        ob_start();
        cms_nsfw_guard_schema_unavailable($route);
        $body = (string) ob_get_clean();
        return ['body' => $body, 'log' => $GLOBALS['service_unavailable_test_logs'][0] ?? []];
    }

    $html = unavailable_response_render('gallery');
    unavailable_response_assert_same(503, http_response_code(), 'HTML HTTP status');
    unavailable_response_assert_same(true, str_contains($html['body'], '<!doctype html>'), 'HTML representation');
    unavailable_response_assert_same(true, str_contains($html['body'], 'request-phase5-123'), 'HTML request reference');

    $json = unavailable_response_render('gallery_lightbox_data');
    unavailable_response_assert_same(503, http_response_code(), 'JSON HTTP status');
    $payload = json_decode($json['body'], true, 512, JSON_THROW_ON_ERROR);
    unavailable_response_assert_same(false, $payload['ok'] ?? null, 'JSON failure flag');
    unavailable_response_assert_same('service_unavailable', $payload['error'] ?? null, 'JSON public error code');
    unavailable_response_assert_same('request-phase5-123', $payload['request_id'] ?? null, 'JSON request reference');

    $text = unavailable_response_render('public_media');
    unavailable_response_assert_same(503, http_response_code(), 'text HTTP status');
    unavailable_response_assert_same(false, str_contains($text['body'], '<html'), 'text representation');
    unavailable_response_assert_same(true, str_contains($text['body'], 'request-phase5-123'), 'text request reference');

    unavailable_response_assert_same('error', $text['log']['level'] ?? null, 'log level');
    unavailable_response_assert_same(NSFW_GUARD_SCHEMA_UNAVAILABLE_EVENT, $text['log']['eventKey'] ?? null, 'stable internal event code');
    unavailable_response_assert_same('nsfw_guard', $text['log']['context']['feature'] ?? null, 'log feature');
    unavailable_response_assert_same('unknown', $text['log']['context']['schema_state'] ?? null, 'log schema state');
    unavailable_response_assert_same('inspection_failed', $text['log']['context']['error_code'] ?? null, 'log error code');
    unavailable_response_assert_same('text', $text['log']['context']['response_format'] ?? null, 'log response format');
    unavailable_response_assert_same('security', $text['log']['options']['category'] ?? null, 'log category');
    unavailable_response_assert_same('request-phase5-123', $text['log']['options']['request_id'] ?? null, 'log request correlation');

    $allBodies = $html['body'] . $json['body'] . $text['body'];
    foreach (['SELECT * FROM users', 'password=secret', 'token-value', 'C:\\private\\gallery', '/srv/private/gallery', 'Stack trace'] as $privateValue) {
        unavailable_response_assert_same(false, str_contains($allBodies, $privateValue), 'public redaction for ' . $privateValue);
    }

    // A secondary logging failure cannot replace the safe public response.
    $GLOBALS['service_unavailable_test_log_failure'] = true;
    $loggingFailure = unavailable_response_render('gallery');
    $GLOBALS['service_unavailable_test_log_failure'] = false;
    unavailable_response_assert_same(503, http_response_code(), 'logging failure HTTP status');
    unavailable_response_assert_same(true, str_contains($loggingFailure['body'], 'temporarily unavailable'), 'logging failure safe body');

    // CLI does not expose response headers through headers_list(), so protect the production header contract at source level.
    $helperSource = (string) file_get_contents(__DIR__ . '/../app/controllers/http_helpers.php');
    foreach (['Cache-Control: private, no-store, max-age=0', 'Retry-After: 60', 'X-Robots-Tag: noindex, nofollow', 'Content-Type: application/json; charset=utf-8', 'Content-Type: text/plain; charset=utf-8', 'Content-Type: text/html; charset=utf-8'] as $headerContract) {
        unavailable_response_assert_same(true, str_contains($helperSource, $headerContract), 'header contract ' . $headerContract);
    }

    echo "Service-unavailable response boundary checks passed.\n";
}
