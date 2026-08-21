<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/viewer_feature_wrapper_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects the global Admin feature wrapper around the complete viewer-account subsystem.
 *
 * Responsibilities:
 *   - Verify viewer accounts are disabled by default at the global feature layer
 *   - Verify the existing viewer mode remains a subordinate gate after the wrapper is enabled
 *   - Verify every current viewer route and Admin viewer-management entry belongs to the wrapper
 *   - Verify hidden Admin navigation and maintained translations are wired to the registered feature
 *   - Audit existing feature-menu and route-map references against the canonical feature registry
 */

declare(strict_types=1);

namespace Gallery\Core {
    /** Return mutable viewer configuration for the focused wrapper test. */
    function cms_config(): array
    {
        return $GLOBALS['viewer_feature_wrapper_config'] ?? [];
    }

    /** Database stub required only for symbol binding in the included viewer service. */
    function db(): object
    {
        throw new \RuntimeException('Database access is not expected in viewer_feature_wrapper_test.php.');
    }

    /** Deterministic SQL timestamp stub required only for symbol binding. */
    function now_sql(): string
    {
        return '2026-08-19 16:00:00';
    }
}

namespace Gallery\Services {
    /** Minimal translation stub for registry definitions. */
    function t(string $key, string|array|null $fallback = null, array $parameters = []): string
    {
        $text = is_string($fallback) ? $fallback : $key;
        foreach ($parameters as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    /** Return one in-memory app setting for the focused feature test. */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return array_key_exists($key, $GLOBALS['viewer_feature_wrapper_settings'] ?? [])
            ? (string) $GLOBALS['viewer_feature_wrapper_settings'][$key]
            : $default;
    }

    /** Persist one in-memory app setting for the focused feature test. */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['viewer_feature_wrapper_settings'][$key] = $value;
    }
}

namespace {
    use function Gallery\Services\feature_flag_default_enabled;
    use function Gallery\Services\feature_flag_definitions;
    use function Gallery\Services\feature_flag_enabled;
    use function Gallery\Services\feature_flag_exists;
    use function Gallery\Services\feature_flag_for_route;
    use function Gallery\Services\feature_flag_route_map;
    use function Gallery\Services\set_feature_flag_enabled;
    use function Gallery\Services\viewer_accounts_enabled;
    use function Gallery\Services\viewer_registration_mode;

    /** Throw when one global viewer-wrapper expectation fails. */
    function viewer_feature_wrapper_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new RuntimeException($label);
        }
    }

    $root = dirname(__DIR__);
    require_once $root . '/app/services/feature_flags.php';
    require_once $root . '/app/services/viewer_accounts.php';

    $GLOBALS['viewer_feature_wrapper_settings'] = [];
    $GLOBALS['viewer_feature_wrapper_config'] = [
        'viewer_accounts' => [
            'enabled' => true,
            'registration_mode' => 'invite_only',
        ],
    ];

    viewer_feature_wrapper_assert(feature_flag_exists('viewer_accounts'), 'Viewer accounts must be registered on Admin > Features.');
    viewer_feature_wrapper_assert(!feature_flag_default_enabled('viewer_accounts'), 'The viewer-account master feature must default to disabled.');
    viewer_feature_wrapper_assert(!feature_flag_enabled('viewer_accounts'), 'Missing persisted master state must keep viewer accounts disabled.');
    viewer_feature_wrapper_assert(!viewer_accounts_enabled(), 'An enabled historical viewer mode must not bypass the disabled master wrapper.');
    viewer_feature_wrapper_assert(viewer_registration_mode() === 'disabled', 'The master wrapper must force effective viewer registration mode to disabled.');

    $establishedFeatures = [
        'public_search',
        'lightbox_modes',
        'picture_manager',
        'image_voting',
        'picture_game',
        'downloads',
        'gallery_maps',
        'flight_maps',
        'navigation_data',
        'simbrief',
        'openai_text_assist',
        'ai_image_metadata',
        'upload_api',
        'mobile_webdav',
        'gallery_migration',
        'media_renamer',
        'telemetry',
    ];
    foreach ($establishedFeatures as $feature) {
        viewer_feature_wrapper_assert(feature_flag_default_enabled($feature), 'Existing feature default changed unexpectedly: ' . $feature);
        viewer_feature_wrapper_assert(feature_flag_enabled($feature), 'Existing feature must retain enabled-by-default compatibility: ' . $feature);
    }

    set_feature_flag_enabled('viewer_accounts', true);
    viewer_feature_wrapper_assert(feature_flag_enabled('viewer_accounts'), 'Admin feature setting must enable the master wrapper.');
    viewer_feature_wrapper_assert(viewer_accounts_enabled(), 'Master wrapper plus historical invite-only mode must enable viewer authentication.');
    viewer_feature_wrapper_assert(viewer_registration_mode() === 'invite_only', 'Enabled wrapper must reveal the existing subordinate invite-only mode.');

    set_feature_flag_enabled('viewer_accounts', false);
    viewer_feature_wrapper_assert(!viewer_accounts_enabled(), 'Disabling the wrapper must immediately fail viewer authority closed.');
    viewer_feature_wrapper_assert(viewer_registration_mode() === 'disabled', 'Disabling the wrapper must hide subordinate registration mode.');

    $definitions = feature_flag_definitions();
    viewer_feature_wrapper_assert(count($definitions) === 19, 'Feature registry audit expected 19 current switches after adding the opt-in Admin test-run diagnostics feature.');
    viewer_feature_wrapper_assert(!feature_flag_default_enabled('admin_test_runs'), 'Admin test-run diagnostics must remain disabled by default.');

    $routeMap = feature_flag_route_map();
    foreach ($routeMap as $route => $feature) {
        viewer_feature_wrapper_assert(feature_flag_exists($feature), 'Feature route references an unregistered switch: ' . $route . ' -> ' . $feature);
    }
    viewer_feature_wrapper_assert(feature_flag_for_route('admin_viewer_invitations') === 'viewer_accounts', 'Admin viewer management route must be owned by the master wrapper.');

    $dispatch = (string) file_get_contents($root . '/app/bootstrap/dispatch.php');
    preg_match_all("/'(viewer_[a-z0-9_]+)'\\s*=>/", $dispatch, $viewerRouteMatches);
    $viewerRoutes = array_values(array_unique($viewerRouteMatches[1] ?? []));
    viewer_feature_wrapper_assert(count($viewerRoutes) >= 20, 'Expected the complete current viewer route family to be discoverable in the dispatcher.');
    foreach ($viewerRoutes as $route) {
        viewer_feature_wrapper_assert(feature_flag_for_route($route) === 'viewer_accounts', 'Viewer route escaped the master wrapper: ' . $route);
    }

    $adminChrome = (string) file_get_contents($root . '/app/views/admin_chrome.php');
    viewer_feature_wrapper_assert(
        str_contains($adminChrome, "'page' => 'admin_viewer_invitations'")
            && str_contains($adminChrome, "'feature' => 'viewer_accounts'"),
        'Admin Viewer accounts navigation must be hidden by the master feature switch.'
    );
    preg_match_all("/'feature'\\s*=>\\s*'([a-z0-9_]+)'/", $adminChrome, $menuFeatureMatches);
    foreach (array_unique($menuFeatureMatches[1] ?? []) as $feature) {
        viewer_feature_wrapper_assert(feature_flag_exists($feature), 'Admin menu references an unregistered feature switch: ' . $feature);
    }

    $publicLayout = (string) file_get_contents($root . '/app/views/layout.php');
    viewer_feature_wrapper_assert(
        str_contains($publicLayout, "if (\$bodyClass === 'public-page' && viewer_accounts_enabled())"),
        'Public Viewer Login/Account navigation must remain behind the effective viewer-account gate.'
    );

    $featureService = (string) file_get_contents($root . '/app/services/feature_flags.php');
    viewer_feature_wrapper_assert(
        str_contains($featureService, "if (str_starts_with(\$page, 'viewer_'))")
            && str_contains($featureService, "return 'viewer_accounts';"),
        'The central dispatcher feature owner must cover the complete viewer_* route family.'
    );
    viewer_feature_wrapper_assert(
        str_contains($featureService, "if (\$featureKey === 'viewer_accounts')")
            && str_contains($featureService, "http_response_code(404);")
            && str_contains($featureService, "'error' => 'not_found'"),
        'Disabled public viewer routes must look unavailable rather than advertise the hidden subsystem.'
    );

    $servicesLoader = (string) file_get_contents($root . '/app/services.php');
    $featureLoaderPosition = strpos($servicesLoader, "'/services/feature_flags.php'");
    $viewerLoaderPosition = strpos($servicesLoader, "'/services/viewer_accounts.php'");
    viewer_feature_wrapper_assert(
        is_int($featureLoaderPosition) && is_int($viewerLoaderPosition) && $featureLoaderPosition < $viewerLoaderPosition,
        'Production service loading must establish the master feature service before viewer_accounts.php.'
    );

    foreach (['en', 'cs', 'de', 'sv'] as $language) {
        $catalog = json_decode((string) file_get_contents($root . '/app/lang/' . $language . '.json'), true);
        viewer_feature_wrapper_assert(is_array($catalog), 'Translation catalog failed to decode: ' . $language);
        foreach ([
            'admin.features.group.accounts',
            'admin.features.group.accounts_help',
            'admin.features.viewer_accounts.label',
            'admin.features.viewer_accounts.description',
        ] as $key) {
            viewer_feature_wrapper_assert(isset($catalog[$key]) && is_string($catalog[$key]) && $catalog[$key] !== '', 'Missing master viewer feature translation ' . $key . ' in ' . $language . '.');
        }
    }

    echo "Viewer master feature-wrapper tests passed.\n";
}
