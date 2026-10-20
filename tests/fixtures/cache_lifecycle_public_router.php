<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/fixtures/cache_lifecycle_public_router.php
 * Module Type: Isolated HTTP Fixture
 * Purpose: Exercise the real ordinary-visit trigger against an owned cache root.
 * Responsibilities: Supply request/settings/central-claim seams without a real database or installation cache; never expose fixture controls to production.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services {
    /**
     * Read only the fixture's explicit capability preference.
     * @param string $key Canonical setting identity.
     * @param string|null $default Missing-value fallback.
     * @return string|null Fixture OFF or the supplied fallback.
     */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return $key === 'feature_flag.automatic_cache_cleanup.enabled'
        && is_file(CACHE_LIFECYCLE_TEST_ROOT . '/off')
            ? '0'
            : $default;
    }

    /**
     * Provide the fixture's canonical automatic capability availability.
     * @param string $key Requested capability identity.
     * @return bool True for enabled automatic cache cleanup only.
     */
    function feature_capability_effective_enabled(string $key): bool
    {
        return (
            $key === 'automatic_cache_cleanup'
                && app_setting(
                    'feature_flag.automatic_cache_cleanup.enabled',
                    '1',
                ) !== '0'
        );
    }
}

namespace Gallery\Models {
    /**
     * Resolve the fixture's absent central maintenance owner.
     * @param bool $requireKnown Whether strict production observation was requested.
     * @return null This fixture owns no central job.
     */
    function maintenance_center_model_mutation_owner(bool $requireKnown = false): ?array
    {
        return null;
    }
}

namespace {
    $fixture = getenv('CACHE_VISITOR_HTTP_ROOT');
    if (
        !is_string($fixture)
        || !is_file($fixture . '/.visitor-fixture-owner')
    ) {
        http_response_code(404);
        return;
    }
    // Reuse the test parent's owned root instead of the real gallery cache.
    // Type: string. Units: absolute path. Scope: this disposable HTTP request.
    // Consumers: cache lifecycle path guards. Rationale: the marker-verified parent fixture owns every test deletion.
    define('CACHE_LIFECYCLE_TEST_ROOT', $fixture);
    $GLOBALS['cache_lifecycle_test_now'] = (int) file_get_contents($fixture
        . '/clock');
    file_put_contents(
        $fixture . '/disk-space-capability',
        function_exists('disk_free_space') ? '1' : '0',
    );
    require_once dirname(__DIR__, 2) . '/app/request_data.php';
    require_once dirname(__DIR__, 2) . '/app/services/cache_lifecycle.php';
    require_once dirname(__DIR__, 2) . '/app/services/site_maintenance.php';
    // This is the real registration seam used by the bootstrap for public HTML.
    \Gallery\Services\site_maintenance_register_request_trigger('home');
    http_response_code(($_GET['denied'] ?? '') === '1' ? 404 : 200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<main>Public gallery fixture</main>';
}
