<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/fixtures/autoupdate_background_router.php
 * Module Type: HTTP Test Fixture
 * Purpose: Exercise the real visitor shutdown and authenticated update transport.
 * Responsibilities: Confine real workers to disposable storage and record actual
 *   HTTP arrivals without a database, browser script or installed application.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core {
    /** Provide the already-loaded worker fixture's selective-loader adapter. */
    final class UpdateChainFixtureKernel
    {
        /**
         * Retain the real controller's loader boundary without a generated plan.
         * @param string $module Reviewed module requested by the controller.
         * @return void Worker owners are already explicitly loaded below.
         */
        public function load(string $module): void {}
    }

    /**
     * Supply the isolated controller's worker-module loader.
     * @return UpdateChainFixtureKernel Adapter for explicitly loaded fixture owners.
     */
    function cms_runtime_kernel(): UpdateChainFixtureKernel
    {
        return new UpdateChainFixtureKernel();
    }

    /**
     * Resolve only the owned server origin for real asynchronous socket writes.
     * @return array{base_url:string} Random-port loopback origin selected by the parent.
     */
    function cms_config(): array
    {
        return [
            'base_url' => (string) getenv('GALLERY_UPDATE_CHAIN_FIXTURE_URL'),
        ];
    }

    /**
     * Observe the final fixture HTTP status in the production shutdown callback.
     * @return int Current HTTP status, defaulting to a successful visitor response.
     */
    function response_status_code(): int
    {
        return http_response_code() ?: 200;
    }
}

namespace Gallery\Services {
    /**
     * Supply only the disposable transport token and optional-setting defaults.
     * @param string $key Setting requested by production owners.
     * @param string $default Missing-value fallback.
     * @return string Fixture-only maintenance token or the fallback.
     */
    function app_setting(string $key, string $default = ''): string
    {
        return $key === 'site_maintenance_token'
            // The production token grammar requires exactly 64 hex characters.
            ? str_repeat('a', 64)
            : $default;
    }

    /**
     * Enable only the real installer in the isolated HTTP worker.
     * @param string $key Canonical capability key.
     * @return bool True only for the built-in updater.
     */
    function feature_capability_effective_enabled(string $key): bool
    {
        return $key === 'built_in_update_installer';
    }

    /**
     * Return safe untranslated refusal text in the database-free controller.
     * @param string $key Translation identity, unused by this fixture.
     * @param string $fallback Controller-authored refusal text.
     * @return string Literal safe fallback without inspecting private state.
     */
    function t(string $key, string $fallback = ''): string
    {
        return $fallback;
    }
}

namespace {
    $root = (string) getenv('GALLERY_UPDATE_CHAIN_FIXTURE_ROOT');
    if (
        PHP_SAPI !== 'cli-server'
        || $root === ''
        || !is_file($root . '/owned-fixture')
    ) {
        http_response_code(503);
        exit();
    }
    // Type: string. Units: absolute path. Scope: this owned HTTP request.
    // Consumers: production updater filesystem and background job workers.
    // Rationale: the parent-authored environment and ownership marker select
    // the same disposable installation for every independent HTTP hop.
    define('CACHE_LIFECYCLE_TEST_ROOT', $root);
    // Cross the real early gate before loading the mutable worker graph. Valid
    // private successors must pass while an ordinary follow-up visit gets 503.
    require_once __DIR__ . '/../../app/early_runtime.php';
    \Gallery\EarlyRuntime\enforce_activation_gate($root);
    require_once __DIR__ . '/../../app/services/updates.php';
    require_once __DIR__ . '/../../app/services/site_maintenance.php';
    require_once __DIR__ . '/../../app/bootstrap/maintenance.php';
    require_once __DIR__ . '/../../app/controllers/site_maintenance.php';

    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if ($path === '/visit' || $path === '/blocked') {
        file_put_contents(
            $root . '/arrivals.log',
            "visit\n",
            FILE_APPEND | LOCK_EX,
        );
        http_response_code($path === '/blocked' ? 503 : 200);
        if ($path === '/visit') {
            // Seed the durable receipt of an activation slice that just yielded
            // for old readers. Real bounded cleanup hops below retain this
            // receipt, reproducing the stopped-chain bug without fake workers.
            $job = \Gallery\Services\application_update_active_job();
            $job['checkpoints']['recovery_admission_closed'] = true;
            $job['checkpoints']['runtime_lease_busy'] = true;
            \Gallery\Services\application_update_save_job($job);
            \Gallery\Services\application_update_activation_gate_begin($job);
        }
        \Gallery\Core\cms_register_update_background_continuation();
        echo 'ordinary anonymous gallery fixture';
        return;
    }
    if (($_GET['page'] ?? '') === 'site_maintenance_cron') {
        file_put_contents(
            $root . '/arrivals.log',
            "worker\n",
            FILE_APPEND | LOCK_EX,
        );
        \Gallery\Controllers\cms_site_maintenance_cron();
        return;
    }
    http_response_code(404);
}
