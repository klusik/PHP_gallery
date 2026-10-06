<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/runtime/bridge.php
 * Module Type: Core Module
 * Purpose: Expose the single request-local kernel to existing procedural transport boundaries.
 * Responsibilities: Inject shipped route metadata and module plans into one request-local kernel.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core;

/**
 * Construct the request's kernel from shipped metadata without loading domain code.
 *
 * @return Kernel The same runtime coordinator for every boundary in this PHP request.
 */
function cms_runtime_kernel(): Kernel
{
    static $kernel = null;
    if ($kernel instanceof Kernel) {
        return $kernel;
    }
    $plans = require __DIR__ . '/modules.php';
    if (!is_array($plans) || ($plans['schema_version'] ?? null) !== 2
        || !is_array($plans['route_modules'] ?? null) || !is_array($plans['modules'] ?? null)
        || !is_array($plans['file_order'] ?? null) || !array_is_list($plans['file_order'])) {
        throw new \RuntimeException('Runtime module metadata is malformed or unsupported.');
    }
    $definitions = [];
    foreach (cms_route_handlers() as $page => $handler) {
        if (!isset($plans['route_modules'][$page])) {
            throw new \RuntimeException('Runtime route metadata is stale: ' . $page);
        }
        $definitions[] = new RouteDefinition($page, $handler, [$plans['route_modules'][$page]]);
    }
    $fallback = new RouteDefinition('not_found', '\\Gallery\\Controllers\\cms_not_found', [$plans['route_modules']['not_found']]);
    $registry = new RouteRegistry($definitions, $fallback);
    $kernel = new Kernel(new Router($registry), new ModuleLoader(dirname(__DIR__, 2), $plans['modules'], $plans['file_order']));
    return $kernel;
}
