<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/dispatch_kernel.php
 * Module Type: Test Fixture
 * Purpose: Inject already-declared policy/controller doubles into the real dispatcher.
 * Responsibilities: Supply explicit empty module plans for isolated security-boundary fixtures.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Tests;

use Gallery\Core\Kernel;
use Gallery\Core\ModuleLoader;
use Gallery\Core\RouteDefinition;
use Gallery\Core\RouteRegistry;
use Gallery\Core\Router;

require_once dirname(__DIR__, 2) . '/app/runtime/autoload.php';

/**
 * Build an isolated dispatcher kernel whose fixture has explicitly provided its dependencies.
 *
 * @return Kernel Coordinator using the real route table and no production-domain includes.
 */
function dispatch_fixture_kernel(): Kernel
{
    $definitions = [];
    foreach (\Gallery\Core\cms_route_handlers() as $page => $handler) {
        $definitions[] = new RouteDefinition($page, $handler, ['fixture']);
    }
    $fallback = new RouteDefinition('not_found', '\\Gallery\\Controllers\\cms_not_found', ['fixture']);
    $modules = array_fill_keys(['fixture', 'request-policy', 'public-policy',
        'schema-unavailable-response', 'feature-disabled-response'], ['depends' => [], 'files' => []]);
    return new Kernel(new Router(new RouteRegistry($definitions, $fallback)),
        new ModuleLoader(dirname(__DIR__, 2), $modules));
}
