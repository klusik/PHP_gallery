<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/fixtures/telemetry_visitor_router.php
 * Module Type: Isolated HTTP Fixture
 * Purpose: Exercise production visitor dispatch and token-protected telemetry transport.
 * Responsibilities:
 *   - Confine scheduling markers and worker receipts to a marker-owned root.
 *   - Supply inert telemetry and central-owner adapters without database access.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Core {
    /**
     * Supply the fixture's fixed loopback installation origin.
     * @return array{base_url:string} Controlled origin; request Host is not used.
     */
    function cms_config(): array
    {
        return ['base_url' => (string) getenv('TELEMETRY_VISITOR_BASE')];
    }

    /**
     * Read the production runtime-limit defaults without bootstrapping a database.
     * @param string $key Canonical numeric runtime-limit key.
     * @return int Fixture value from the production configuration defaults.
     */
    function cms_runtime_limit(string $key): int
    {
        $defaults = require
            dirname(__DIR__, 2) . '/app/configuration_defaults.php';
        return (int) $defaults['runtime_limits'][$key];
    }

    /**
     * Return actual request server metadata to the production registration owner.
     * @param string $part Requested request-context partition.
     * @return array<string,string> String-valued server metadata only.
     */
    function request_data(string $part): array
    {
        return array_filter($part === 'query' ? $_GET : $_SERVER, 'is_string');
    }

    /**
     * Expose the actual visible response status to shutdown admission.
     * @return int Current status or the normal successful default.
     */
    function response_status_code(): int
    {
        return http_response_code() ?: 200;
    }
}

namespace Gallery\Services {
    /**
     * Provide only the marker-controlled telemetry capability.
     * @param string $key Canonical feature identity.
     * @return bool True for telemetry unless explicitly disabled in the fixture.
     */
    function feature_capability_effective_enabled(string $key): bool
    {
        return $key === 'telemetry'
            && !is_file(CACHE_LIFECYCLE_TEST_ROOT . '/off');
    }

    /**
     * Supply the private credential without any real installation settings.
     * @param string $key Canonical setting key.
     * @param ?string $default Missing-value fallback.
     * @return ?string Fixed private test token or the supplied fallback.
     */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return $key === 'site_maintenance_token'
            ? str_repeat('a', 64)
            : $default;
    }

    /**
     * Model the master telemetry opt-out independently of capability state.
     * @param string $key Requested preference identity.
     * @param string $default Missing-preference fallback.
     * @return bool True unless the fixture's master switch is explicitly disabled.
     */
    function telemetry_setting_enabled(string $key, string $default = '0'): bool
    {
        return !is_file(CACHE_LIFECYCLE_TEST_ROOT . '/master-off');
    }

    /**
     * Record authenticated worker arrival without running SQL or expensive work.
     * @return array{ok:bool,has_more:bool} A completed fixture slice.
     */
    function telemetry_run_scheduled_maintenance(): array
    {
        file_put_contents(
            CACHE_LIFECYCLE_TEST_ROOT . '/receipts',
            "worker\n",
            FILE_APPEND | LOCK_EX,
        );
        return ['ok' => true, 'has_more' => false];
    }

    /**
     * Keep controller messages inert while using the real transport/security flow.
     * @param string $key Translation key.
     * @param string $default English fallback.
     * @return string Supplied fallback only.
     */
    function t(string $key, string $default = ''): string
    {
        return $default;
    }
}

namespace Gallery\Models {
    /**
     * Resolve strict central ownership from marker-controlled fixture state.
     * @param bool $requireKnown Whether unavailable ownership must throw.
     * @return array{id:int}|null Central claim or no active owner.
     */
    function maintenance_center_model_mutation_owner(bool $requireKnown = false): ?array
    {
        if ($requireKnown && is_file(CACHE_LIFECYCLE_TEST_ROOT . '/unknown')) {
            throw new \RuntimeException('fixture unavailable');
        }
        return (
            is_file(CACHE_LIFECYCLE_TEST_ROOT . '/central') ? ['id' => 1] : null
        );
    }
}

namespace {
    $root = (string) getenv('TELEMETRY_VISITOR_ROOT');
    if (!is_file($root . '/.telemetry-fixture-owner')) {
        http_response_code(404);
        return;
    }
    // Type: string. Units: absolute path. Scope: this disposable HTTP process.
    // Consumers: existing maintenance path guards. Rationale: isolate all trigger metadata from the real installation.
    define('CACHE_LIFECYCLE_TEST_ROOT', $root);
    require_once dirname(__DIR__, 2) . '/app/services/updates_path_safety.php';
    require_once dirname(__DIR__, 2) . '/app/release_file_policy.php';
    require_once dirname(__DIR__, 2) . '/app/services/site_maintenance.php';
    require_once dirname(__DIR__, 2) . '/app/controllers/site_maintenance.php';
    if (($_GET['page'] ?? '') === 'site_maintenance_cron') {
        \Gallery\Controllers\cms_site_maintenance_cron();
        return;
    }
    if (isset($_GET['ready'])) {
        echo 'ready';
        return;
    }
    \Gallery\Services\site_maintenance_register_request_trigger(
        (string) ($_GET['route'] ?? 'home'),
    );
    http_response_code(isset($_GET['denied']) ? 403 : 200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<main>Public gallery fixture</main>';
}
