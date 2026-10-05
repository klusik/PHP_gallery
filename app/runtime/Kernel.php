<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/runtime/Kernel.php
 * Module Type: Core Module
 * Purpose: Coordinate route selection, selective loading and procedural handler dispatch.
 * Responsibilities: Preserve request startup, identity, policy and response-completion ordering.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core;

/** Owns the request's router/loader lifecycle while keeping existing domain code procedural. */
final class Kernel
{
    private ?RouteDefinition $currentRoute = null;
    /**
     * Inject the concrete routing and module-loading boundaries.
     *
     * @param Router $router Router bound to the canonical registry.
     * @param ModuleLoader $loader Request-local selective dependency loader.
     * @return void Initializes kernel ownership.
     */
    public function __construct(private readonly Router $router, private readonly ModuleLoader $loader)
    {
    }

    /**
     * Execute the web request lifecycle while preserving transport and security ordering.
     *
     * @return void Resolves the route, initializes identity/policy, and dispatches existing controllers.
     */
    public function run(): void
    {
        // Observers must exist before configuration/session policies issue their first query.
        $this->load('database-observer');
        $cookies = request_data('cookie');
        $query = request_data('query');
        if (!empty($cookies['gallery_admin_test_run'])
            || ($query['page'] ?? '') === 'admin_test_run_start') {
            $this->load('request-diagnostics');
        }
        if (isset($query['benchmark_token']) || isset($cookies['gallery_benchmark_media_context'])) {
            $this->load('benchmark-diagnostics');
        }
        cms_request_trace_begin();
        if (!cms_has_config()) {
            cms_redirect_to_installer();
        }
        cms_request_trace_mark('config_load_start');
        $config = cms_config();
        cms_request_trace_mark('config_load_end');
        $this->load('request-policy');
        cms_start_session($config);
        cms_request_trace_mark('request_initialize_start');
        $request = Request::fromRoute(cms_route_from_request(fn(string $module): mixed => $this->load($module)));
        // Select immutable metadata before any feature controller or view is loaded.
        $this->resolve($request);
        $this->load('viewer-identity');
        $page = cms_initialize_request($request);
        cms_request_trace_mark('request_initialize_end', ['page' => $page]);
        cms_release_read_only_media_session_lock($page);
        cms_prime_read_only_media_schema_cache($page);
        cms_request_trace_mark('request_maintenance_start', ['page' => $page]);
        cms_run_request_maintenance($page, $this);
        cms_request_trace_mark('request_maintenance_end', ['page' => $page]);
        if (function_exists('Gallery\\Services\\admin_test_run_register_final_shutdown_observer')) {
            \Gallery\Services\admin_test_run_register_final_shutdown_observer();
        }
        cms_request_trace_mark('dispatch_start', ['page' => $page]);
        cms_dispatch_page($page, $this);
        cms_request_trace_mark('dispatch_end', ['page' => $page]);
        if (function_exists('Gallery\\Services\\admin_test_run_response_logical_finish')) {
            apply_response_header_intents(\Gallery\Services\admin_test_run_response_logical_finish('cms_dispatch_returned', http_response_code() ?: 200));
        }
        if (function_exists('Gallery\\Services\\gallery_benchmark_record_request_completion')) {
            \Gallery\Services\gallery_benchmark_record_request_completion($page);
        }
    }

    /**
     * Select route metadata before loading its feature implementation.
     *
     * @param Request $request Normalized request snapshot.
     * @return RouteDefinition Matched route or the existing not-found fallback.
     */
    public function resolve(Request $request): RouteDefinition
    {
        return $this->currentRoute = $this->router->resolve($request);
    }

    /**
     * Report the actual selected route without performing another lookup or loading dependencies.
     *
     * @return RouteDefinition|null Current request's selected route, or null before routing.
     */
    public function currentRoute(): ?RouteDefinition
    {
        return $this->currentRoute;
    }

    /**
     * Load the modules owned by a selected route and verify its procedural handler.
     *
     * @param RouteDefinition $route Previously resolved immutable metadata.
     * @return callable Available procedural controller function.
     */
    public function prepare(RouteDefinition $route): callable
    {
        foreach ($route->modules as $module) {
            $this->loader->load($module);
        }
        if (!is_callable($route->handler)) {
            throw new \RuntimeException('Runtime route handler is unavailable: ' . $route->name);
        }
        return $route->handler;
    }

    /**
     * Invoke an existing controller after the transport boundary has run its preflight.
     *
     * @param RouteDefinition $route Route whose public/auth/schema boundary has passed.
     * @return void Delegates output and response semantics to the existing handler.
     */
    public function dispatch(RouteDefinition $route): void
    {
        $handler = $this->prepare($route);
        $handler();
    }

    /**
     * Load an explicit shared or deferred lifecycle module through the same loader.
     *
     * @param string $module Reviewed logical lifecycle module identifier.
     * @return void Includes the requested module's declared plan.
     */
    public function load(string $module): void
    {
        $this->loader->load($module);
    }

    /**
     * Report the actual logical loading history for request probes.
     *
     * @return list<string> Completed modules in dependency order.
     */
    public function loadedModules(): array
    {
        return $this->loader->loadedModules();
    }
}
