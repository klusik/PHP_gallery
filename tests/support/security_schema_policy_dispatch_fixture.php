<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/support/security_schema_policy_dispatch_fixture.php
 * Module Type: Test Support Fixture
 *
 * Purpose:
 *   Exercises the real public dispatcher for Phase 9 access and visibility schema policy.
 *
 * Responsibilities:
 *   - Simulate complete, partial, and operationally unknown security schema states
 *   - Prove protected gallery, media, thumbnail, metadata, and download handlers fail before output
 *   - Capture bounded 503 logging without using a database or web server
 *   - Keep simulated secrets and SQL text confined to inspection exceptions
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - This support file is invoked by tests/gallery_access_schema_policy_test.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-08-12
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return no authenticated user for the public security-policy fixture.
     *
     * @return ?array Always null.
     */
    function current_user(): ?array
    {
        return null;
    }
}

namespace Gallery\Services {
    /**
     * Return deterministic translated wording for the dispatcher fixture.
     *
     * @param string $key Translation key.
     * @param mixed $fallback Fallback wording or replacement map.
     * @param array $replacements Placeholder replacements.
     * @return string Resolved fixture text.
     */
    function t(string $key, mixed $fallback = '', array $replacements = []): string
    {
        if (is_array($fallback)) {
            $replacements = $fallback;
            $fallback = $key;
        }
        $text = '[translated] ' . (string) $fallback;
        foreach ($replacements as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    /**
     * Return a deterministic request identifier for 503 correlation checks.
     *
     * @return string Request identifier.
     */
    function telemetry_request_id(): string
    {
        return 'request-phase9-503';
    }

    /**
     * Capture one bounded operational log event in memory.
     *
     * @param string $level Event level.
     * @param string $eventKey Stable event key.
     * @param string $message Safe event message.
     * @param array $context Structured bounded context.
     * @param array $options Event classification and correlation options.
     */
    function admin_log_event(string $level, string $eventKey, string $message, array $context = [], array $options = []): void
    {
        $GLOBALS['security_schema_fixture_logs'][] = compact('level', 'eventKey', 'message', 'context', 'options');
    }

    /**
     * Keep every fixture route enabled so dispatcher policy runs.
     *
     * @param string $page Route identifier.
     * @return bool Always true.
     */
    function feature_flag_route_enabled(string $page): bool
    {
        return true;
    }

    /**
     * Record an unexpected disabled-route path.
     *
     * @param string $page Route identifier.
     */
    function feature_flag_render_disabled_route(string $page): void
    {
        $GLOBALS['security_schema_fixture_disabled_route'] = $page;
    }
}

namespace Gallery\Controllers {
    /**
     * Record that one protected fixture handler was reached.
     *
     * @param string $route Route identifier.
     */
    function security_schema_fixture_handler(string $route): void
    {
        $GLOBALS['security_schema_fixture_handler_reached'] = true;
        echo 'protected-handler-output:' . $route;
    }

    /** Handle the public gallery fixture route. */
    function cms_gallery(): void
    {
        security_schema_fixture_handler('gallery');
    }

    /** Handle the protected public-media fixture route. */
    function cms_public_media(): void
    {
        security_schema_fixture_handler('public_media');
    }

    /** Handle the protected public-thumbnail fixture route. */
    function cms_public_thumb(): void
    {
        security_schema_fixture_handler('public_thumb');
    }

    /** Handle the lazy lightbox metadata fixture route. */
    function cms_gallery_lightbox_data(): void
    {
        security_schema_fixture_handler('gallery_lightbox_data');
    }

    /** Handle the protected gallery-download fixture route. */
    function cms_download_gallery(): void
    {
        security_schema_fixture_handler('download_gallery');
    }
}

namespace {
    $projectRoot = dirname(__DIR__, 2);
    require_once $projectRoot . '/app/services/schema_inspection.php';
    require_once $projectRoot . '/app/services/gallery_access.php';
    require_once $projectRoot . '/app/controllers/http_helpers.php';
    require_once $projectRoot . '/app/helpers_request.php';
    require_once $projectRoot . '/app/bootstrap/dispatch.php';

    use function Gallery\Core\cms_dispatch_page;
    use function Gallery\Services\schema_inspection_set_query_executor_for_tests;

    $state = (string) ($argv[1] ?? 'unknown_access');
    $route = (string) ($argv[2] ?? 'gallery');

    if (!in_array($state, ['available', 'partial_access', 'unknown_access', 'unknown_visibility'], true)) {
        fwrite(STDERR, "Invalid fixture schema state.\n");
        exit(2);
    }
    if (!in_array($route, ['gallery', 'public_media', 'public_thumb', 'gallery_lightbox_data', 'download_gallery'], true)) {
        fwrite(STDERR, "Invalid fixture route.\n");
        exit(2);
    }

    $GLOBALS['security_schema_fixture_handler_reached'] = false;
    $GLOBALS['security_schema_fixture_logs'] = [];

    schema_inspection_set_query_executor_for_tests(static function (string $objectType, string $table, string $object, string $token = '') use ($state): bool {
        if ($state === 'partial_access' && $objectType === 'column' && $table === 'galleries' && $object === 'access_token_expires_at') {
            return false;
        }
        if ($state === 'unknown_access' && $objectType === 'column' && $table === 'galleries' && $object === 'access_mode') {
            throw new \RuntimeException('fixture-secret password=phase9 SELECT access_mode FROM galleries');
        }
        if ($state === 'unknown_visibility' && $objectType === 'column_definition_contains' && $table === 'galleries' && $object === 'visibility' && $token === 'unpublished') {
            throw new \RuntimeException('fixture-secret dsn=mysql:private visibility enum inspection failed');
        }
        return true;
    });

    http_response_code(200);
    ob_start();
    cms_dispatch_page($route);
    $body = (string) ob_get_clean();

    $result = [
        'state' => $state,
        'route' => $route,
        'status' => http_response_code(),
        'body' => $body,
        'handler_reached' => (bool) $GLOBALS['security_schema_fixture_handler_reached'],
        'log' => $GLOBALS['security_schema_fixture_logs'][0] ?? [],
    ];

    schema_inspection_set_query_executor_for_tests(null);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
