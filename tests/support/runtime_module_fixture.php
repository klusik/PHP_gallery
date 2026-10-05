<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/runtime_module_fixture.php
 * Module Type: Runtime Contract Fixture
 * Purpose: Prepare all routes owned by one runtime module in a fresh PHP process.
 * Responsibilities: Check handler availability and absence of output, session or umbrella-loader effects.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

/**
 * Exercise one compiled route module without running request initialization or dispatch.
 *
 * @param string $root Absolute repository root.
 * @param string $module Route-owned logical module to prepare.
 * @return array<string,mixed> Bounded observations from bootstrap and route preparation.
 */
function runtime_module_fixture_run(string $root, string $module): array
{
    $plan = require $root . '/app/runtime/modules.php';
    if (!is_array($plan) || !isset($plan['route_modules'], $plan['modules'])) {
        throw new RuntimeException('Compiled route module plan is malformed.');
    }
    $routes = array_keys(array_filter(
        $plan['route_modules'],
        static fn (string $routeModule): bool => $routeModule === $module
    ));
    if (!isset($plan['modules'][$module])) {
        throw new RuntimeException('Unknown route/lifecycle module: ' . $module);
    }

    $beforeHeaders = headers_list();
    $beforeSession = session_status();
    ob_start();
    require $root . '/app/bootstrap.php';
    $kernel = \Gallery\Core\cms_runtime_kernel();
    $kernel->load($module);
    $prepared = [];
    foreach ($routes as $page) {
        $route = $kernel->resolve(new \Gallery\Core\Request($page));
        if ($route->name !== $page || $route->modules !== [$module]) {
            throw new RuntimeException('Canonical route metadata disagrees for page: ' . $page);
        }
        $handler = $kernel->prepare($route);
        if (!is_callable($handler)) {
            throw new RuntimeException('Prepared handler is not callable for page: ' . $page);
        }
        $prepared[] = $page;
    }
    // Composed callback families must be present in their reviewed execution modules.
    require_once $root . '/scripts/runtime_dynamic_dependencies.php';
    foreach (\Gallery\Tools\RuntimeDynamicDependencies\runtime_dynamic_dependency_additional_roots()[$module] ?? [] as $target) {
        if (!function_exists($target)) {
            throw new RuntimeException('Missing reviewed callback in ' . $module . ': ' . $target);
        }
    }
    $output = (string) ob_get_clean();
    $included = get_included_files();
    $normalized = array_map(static fn (string $path): string => strtolower(str_replace('\\', '/', $path)), $included);
    $forbidden = [];
    foreach (['models.php', 'services.php', 'views.php', 'controllers.php'] as $umbrella) {
        foreach ($normalized as $path) {
            if (str_ends_with($path, '/app/' . $umbrella)) {
                $forbidden[] = $umbrella;
            }
        }
    }
    if ($output !== '') {
        throw new RuntimeException('Bootstrap or route preparation emitted output.');
    }
    if (headers_list() !== $beforeHeaders) {
        throw new RuntimeException('Bootstrap or route preparation changed response headers.');
    }
    if (session_status() !== $beforeSession || session_status() !== PHP_SESSION_NONE) {
        throw new RuntimeException('Bootstrap or route preparation started or changed a session.');
    }
    if ($forbidden !== []) {
        throw new RuntimeException('Route preparation included umbrella loader(s): ' . implode(', ', array_unique($forbidden)));
    }
    if (function_exists('Gallery\\Core\\cms_config') && isset($GLOBALS['pdo'])) {
        throw new RuntimeException('Bootstrap unexpectedly initialized database state.');
    }

    return [
        'module' => $module,
        'routes' => $prepared,
        'included_count' => count($included),
        'loaded_modules' => $kernel->loadedModules(),
        'side_effects' => ['output' => false, 'headers' => false, 'session' => false, 'umbrellas' => false],
    ];
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $root = dirname(__DIR__, 2);
        $module = (string) ($argv[1] ?? '');
        if ($module === '') {
            throw new InvalidArgumentException('A logical route module argument is required.');
        }
        $result = runtime_module_fixture_run($root, $module);
        fwrite(STDOUT, json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR) . "\n");
    } catch (Throwable $exception) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        fwrite(STDOUT, json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR) . "\n");
        exit(1);
    }
}
