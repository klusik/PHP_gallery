<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/route_reference_integrity_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects central route registration and namespaced function references from
 *   stale refactor leftovers that only fail when a less common workflow executes.
 *
 * Responsibilities:
 *   - Verify every dispatcher handler is declared by the controller tree
 *   - Verify literal url_for() and page-route references target registered routes
 *   - Verify pretty-route and feature-registry page identifiers are dispatchable
 *   - Verify Gallery namespaced use-function imports resolve to real functions
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - The implicit not_found page is allowed because dispatch intentionally falls
 *     back to cms_not_found() when no explicit route entry exists.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$violations = [];

/**
 * Read one repository file for route-reference inspection.
 *
 * @param string $relativePath Repository-relative path.
 * @return string File contents.
 */
function route_reference_read(string $relativePath): string
{
    global $root;
    $contents = file_get_contents($root . '/' . $relativePath);
    if ($contents === false) {
        throw new RuntimeException('Unable to read route-reference target: ' . $relativePath);
    }
    return $contents;
}

/**
 * Return every first-party PHP source file below app/.
 *
 * @return list<string> Repository-relative PHP paths.
 */
function route_reference_app_php_files(): array
{
    global $root;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS)
    );
    $paths = [];
    foreach ($iterator as $entry) {
        if (!$entry->isFile() || strtolower($entry->getExtension()) !== 'php') {
            continue;
        }
        $paths[] = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
    }
    sort($paths, SORT_STRING);
    return $paths;
}

$dispatchSource = route_reference_read('app/bootstrap/dispatch.php');
if (preg_match_all(
    '/^\s*[\'\"]([A-Za-z0-9_]+)[\'\"]\s*=>\s*[\'\"]([^\'\"]+)[\'\"]\s*,/m',
    $dispatchSource,
    $dispatchMatches,
    PREG_SET_ORDER
) === false) {
    throw new RuntimeException('Unable to inspect the central dispatcher route map.');
}

$routes = [];
foreach ($dispatchMatches as $match) {
    $routes[(string) $match[1]] = ltrim(str_replace('\\\\', '\\', (string) $match[2]), '\\');
}
if ($routes === []) {
    $violations[] = 'Central dispatcher route map could not be parsed.';
}

$declaredFunctions = [];
foreach (route_reference_app_php_files() as $relativePath) {
    $source = route_reference_read($relativePath);
    $namespace = '';
    if (preg_match('/^\s*namespace\s+([^;]+);/m', $source, $namespaceMatch) === 1) {
        $namespace = trim((string) $namespaceMatch[1]);
    }
    if (preg_match_all('/^\s*function\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $source, $functionMatches) === false) {
        $violations[] = 'Unable to inspect function declarations in ' . $relativePath;
        continue;
    }
    foreach ($functionMatches[1] ?? [] as $functionName) {
        $qualified = $namespace !== '' ? $namespace . '\\' . $functionName : (string) $functionName;
        $declaredFunctions[$qualified] = $relativePath;
    }
}

foreach ($routes as $page => $handler) {
    if (!isset($declaredFunctions[$handler])) {
        $violations[] = 'Dispatcher route ' . $page . ' targets missing handler ' . $handler;
    }
}

$knownPages = array_fill_keys(array_keys($routes), true);
$knownPages['not_found'] = true;

foreach (route_reference_app_php_files() as $relativePath) {
    $source = route_reference_read($relativePath);

    if (preg_match_all('/\burl_for\s*\(\s*[\'\"]([A-Za-z0-9_]+)[\'\"]/', $source, $urlMatches) !== false) {
        foreach ($urlMatches[1] ?? [] as $page) {
            if (!isset($knownPages[$page])) {
                $violations[] = $relativePath . ' references unregistered url_for route ' . $page;
            }
        }
    }

    if (preg_match_all('/[\'\"]page[\'\"]\s*=>\s*[\'\"]([A-Za-z0-9_]+)[\'\"]/', $source, $pageMatches) !== false) {
        foreach ($pageMatches[1] ?? [] as $page) {
            if (!isset($knownPages[$page])) {
                $violations[] = $relativePath . ' references unregistered page route ' . $page;
            }
        }
    }

    if (preg_match_all('/^\s*use\s+function\s+(Gallery\\\\[A-Za-z0-9_\\\\]+);/m', $source, $importMatches) !== false) {
        foreach ($importMatches[1] ?? [] as $functionName) {
            $normalized = str_replace('\\\\', '\\', (string) $functionName);
            if (!isset($declaredFunctions[$normalized])) {
                $violations[] = $relativePath . ' imports missing function ' . $normalized;
            }
        }
    }
}

$routingSource = route_reference_read('app/bootstrap/routing.php');
if (preg_match_all('/[\'\"]page[\'\"]\s*=>\s*[\'\"]([A-Za-z0-9_]+)[\'\"]/', $routingSource, $prettyRouteMatches) !== false) {
    foreach ($prettyRouteMatches[1] ?? [] as $page) {
        if (!isset($knownPages[$page])) {
            $violations[] = 'Pretty routing resolves to unregistered page ' . $page;
        }
    }
}

$featureRegistrySource = route_reference_read('app/services/feature_flags/registry.php');
if (preg_match_all('/[\'\"]routes[\'\"]\s*=>\s*\[(.*?)\]/s', $featureRegistrySource, $featureRouteBlocks) !== false) {
    foreach ($featureRouteBlocks[1] ?? [] as $routeBlock) {
        if (preg_match_all('/[\'\"]([A-Za-z0-9_]+)[\'\"]/', (string) $routeBlock, $featureRoutes) === false) {
            continue;
        }
        foreach ($featureRoutes[1] ?? [] as $page) {
            if (!isset($knownPages[$page])) {
                $violations[] = 'Feature registry owns unregistered route ' . $page;
            }
        }
    }
}

foreach ([
    'admin_navigraph_connect' => 'Gallery\\Controllers\\cms_admin_navigraph_connect',
    'admin_navigraph_callback' => 'Gallery\\Controllers\\cms_admin_navigraph_callback',
    'admin_navigraph_disconnect' => 'Gallery\\Controllers\\cms_admin_navigraph_disconnect',
    'admin_navigraph_refresh' => 'Gallery\\Controllers\\cms_admin_navigraph_refresh',
] as $page => $handler) {
    if (($routes[$page] ?? null) !== $handler) {
        $violations[] = 'Legacy Navigraph compatibility route is not wired correctly: ' . $page;
    }
}

if ($violations !== []) {
    throw new RuntimeException("Route/function reference integrity violations:\n  - " . implode("\n  - ", array_values(array_unique($violations))));
}

fwrite(STDOUT, "Route/function reference integrity checks passed.\n");
