<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/support/nsfw_policy_dispatch_fixture.php
 * Module Type: Test Support Fixture
 *
 * Purpose:
 *   Exercises the real public dispatcher and NSFW 503 response boundary in an isolated process.
 *
 * Responsibilities:
 *   - Simulate available, missing, and unknown NSFW schema inspection states
 *   - Detect whether protected public route handlers are reached
 *   - Exercise route-appropriate response bodies without a database or web server
 *   - Verify logged-in anonymous preview requests use the same dispatcher safety boundary
 *   - Keep fixture secrets confined to the simulated inspection failure
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - This support file is invoked by tests/nsfw_schema_policy_test.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-08-12
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return the deterministic fixture user used by anonymous preview checks.
     *
     * @return ?array Fixture user or null.
     */
    function current_user(): ?array
    {
        $user = $GLOBALS['nsfw_policy_fixture_user'] ?? null;
        return is_array($user) ? $user : null;
    }
}

namespace Gallery\Services {
    /**
     * Return deterministic translated wording for the isolated dispatcher fixture.
     *
     * @param string $key Translation key.
     * @param mixed $fallback Fallback text or replacements.
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
     * Return a deterministic request identifier for dispatcher correlation checks.
     *
     * @return string Request identifier.
     */
    function telemetry_request_id(): string
    {
        return 'request-phase7-503';
    }

    /**
     * Capture bounded operational events without database storage.
     *
     * @param string $level Legacy event level.
     * @param string $eventKey Stable event key.
     * @param string $message Safe internal message.
     * @param array $context Structured event context.
     * @param array $options Event classification and correlation options.
     */
    function admin_log_event(string $level, string $eventKey, string $message, array $context = [], array $options = []): void
    {
        $GLOBALS['nsfw_policy_fixture_logs'][] = compact('level', 'eventKey', 'message', 'context', 'options');
    }

    /**
     * Keep every fixture route enabled so the dispatcher reaches the NSFW boundary.
     *
     * @param string $page Resolved route identifier.
     * @return bool Always true for this fixture.
     */
    function feature_flag_route_enabled(string $page): bool
    {
        return true;
    }

    /**
     * Record an unexpected disabled-route render attempt.
     *
     * @param string $page Resolved route identifier.
     */
    function feature_flag_render_disabled_route(string $page): void
    {
        $GLOBALS['nsfw_policy_fixture_disabled_route'] = $page;
    }
}

namespace Gallery\Controllers {
    /**
     * Record that a protected route handler was reached.
     *
     * @param string $route Route identifier.
     */
    function nsfw_policy_fixture_handler(string $route): void
    {
        $GLOBALS['nsfw_policy_fixture_handler_reached'] = true;
        echo 'protected-handler-output:' . $route;
    }

    /** Handle the public gallery fixture route. */
    function cms_gallery(): void
    {
        nsfw_policy_fixture_handler('gallery');
    }

    /** Handle the protected public-media fixture route. */
    function cms_public_media(): void
    {
        nsfw_policy_fixture_handler('public_media');
    }

    /** Handle the protected public-thumbnail fixture route. */
    function cms_public_thumb(): void
    {
        nsfw_policy_fixture_handler('public_thumb');
    }

    /** Handle the lazy lightbox metadata fixture route. */
    function cms_gallery_lightbox_data(): void
    {
        nsfw_policy_fixture_handler('gallery_lightbox_data');
    }

    /** Handle the lazy map metadata fixture route. */
    function cms_gallery_map_data(): void
    {
        nsfw_policy_fixture_handler('gallery_map_data');
    }
}

namespace {
    $projectRoot = dirname(__DIR__, 2);
    require_once $projectRoot . '/app/services/schema_inspection.php';
    require_once $projectRoot . '/app/services/gallery_access.php';
    require_once $projectRoot . '/app/controllers/http_helpers.php';
    require_once $projectRoot . '/app/helpers_request.php';
    require_once $projectRoot . '/app/bootstrap/dispatch.php';

    use function Gallery\Core\admin_anonymous_preview_active;
    use function Gallery\Core\cms_dispatch_page;
    use function Gallery\Services\schema_inspection_set_query_executor_for_tests;

    $state = (string) ($argv[1] ?? 'unknown');
    $route = (string) ($argv[2] ?? 'gallery');
    $anonymousPreview = (string) ($argv[3] ?? '0') === '1';

    if (!in_array($state, ['available', 'missing', 'unknown'], true)) {
        fwrite(STDERR, "Invalid fixture schema state.\n");
        exit(2);
    }
    if (!in_array($route, ['gallery', 'public_media', 'public_thumb', 'gallery_lightbox_data', 'gallery_map_data'], true)) {
        fwrite(STDERR, "Invalid fixture route.\n");
        exit(2);
    }

    $GLOBALS['nsfw_policy_fixture_user'] = $anonymousPreview ? ['id' => 91, 'role' => 'admin'] : null;
    $GLOBALS['nsfw_policy_fixture_handler_reached'] = false;
    $GLOBALS['nsfw_policy_fixture_logs'] = [];
    if ($anonymousPreview) {
        $_GET['view_as'] = 'anonymous';
    } else {
        unset($_GET['view_as']);
    }

    schema_inspection_set_query_executor_for_tests(match ($state) {
        'available' => static fn (): bool => true,
        'missing' => static fn (): bool => false,
        default => static function (): bool {
            throw new \RuntimeException('fixture-secret password=phase7 SELECT * FROM private_media');
        },
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
        'handler_reached' => (bool) $GLOBALS['nsfw_policy_fixture_handler_reached'],
        'anonymous_preview_active' => admin_anonymous_preview_active(),
        'log' => $GLOBALS['nsfw_policy_fixture_logs'][0] ?? [],
    ];

    schema_inspection_set_query_executor_for_tests(null);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
