<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/generate_runtime_modules.php
 * Module Type: Development Tool
 * Purpose: Compile reviewed logical roots into deterministic selective loading plans.
 * Responsibilities: Analyze source without executing domains and check the shipped plan for drift.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Tools\RuntimeModules;

require_once __DIR__ . '/cli_guard.php';
\gallery_guard_cli_entrypoint(__FILE__);

require_once __DIR__ . '/runtime_dependencies.php';
require_once __DIR__ . '/runtime_dynamic_dependencies.php';

use function Gallery\Tools\RuntimeDependencies\scan_files;
use function Gallery\Tools\RuntimeDependencies\build_symbol_index;
use function Gallery\Tools\RuntimeDependencies\read_source_file;
use function Gallery\Tools\RuntimeDependencies\collect_symbol_dependencies;
use function Gallery\Tools\RuntimeDependencies\module_owner;
use function Gallery\Tools\RuntimeDynamicDependencies\runtime_dynamic_dependency_callback_targets;
use function Gallery\Tools\RuntimeDynamicDependencies\runtime_dynamic_dependency_policy_drift;
use function Gallery\Tools\RuntimeDynamicDependencies\runtime_dynamic_dependency_sites;

/**
 * Compile domain roots and the canonical handler inventory without executing application code.
 *
 * @param string $root Absolute source checkout root.
 * @return array{schema_version:int,route_modules:array<string,string>,file_order:list<string>,modules:array<string,array{depends:list<string>,files:list<string>}>} Shippable logical module plans.
 */
function compile_runtime_modules(string $root): array
{
    $files = array_filter(scan_files($root), static fn(array $file): bool => str_starts_with($file['path'], 'app/'));
    $indexed = build_symbol_index($files);
    $symbols = $indexed['symbols'];
    $policy = require __DIR__ . '/runtime_module_roots.php';
    $dynamicTargets = runtime_dynamic_dependency_callback_targets();
    $drift = runtime_dynamic_dependency_policy_drift(runtime_dynamic_dependency_sites($root));
    if ($drift['unexpected'] !== [] || $drift['stale'] !== []) {
        throw new \RuntimeException('Dynamic runtime dependencies need review: '
            . implode(', ', array_merge($drift['unexpected'], $drift['stale'])));
    }
    $dependencies = [];
    foreach ($indexed['perFile'] as $path => $declarations) {
        if ($declarations === []) {
            continue;
        }
        $parsed = read_source_file($root . '/' . $path, $root);
        foreach ($declarations as $symbol) {
            if (!in_array($symbol['kind'], ['function', 'method', 'T_CLASS', 'T_INTERFACE', 'T_TRAIT', 'T_ENUM'], true)
                || str_starts_with($symbol['file'], 'app/runtime/')) {
                continue;
            }
            $analysis = collect_symbol_dependencies($parsed, $symbol, $symbols);
            $deferred = array_map('strtolower', $policy['deferred_calls'][$symbol['name']] ?? []);
            $symbolKey = in_array($symbol['kind'], ['T_CLASS', 'T_INTERFACE', 'T_TRAIT', 'T_ENUM'], true)
                ? 'class:' . $symbol['id'] : $symbol['id'];
            $dependencies[$symbolKey] = array_values(array_unique(array_map(
                static fn(array $edge): string => $edge['target_id'],
                array_filter($analysis['edges'], static fn(array $edge): bool => !in_array(strtolower($edge['target']), $deferred, true))
            )));
            // Reviewed composed callback arrays are concrete caller dependencies,
            // including callers reached from a different route-owned module.
            foreach ($dynamicTargets[$symbol['name']] ?? [] as $target) {
                $targetId = strtolower($target);
                if (!isset($symbols[$targetId])) {
                    throw new \RuntimeException('Missing reviewed dynamic dependency: ' . $target);
                }
                $dependencies[$symbolKey][] = $targetId;
            }
        }
    }

    // The literal handler map remains the route authority. This parser reads its
    // quoted page/function pairs; it neither includes dispatch.php nor evaluates PHP.
    $dispatch = (string) file_get_contents($root . '/app/bootstrap/dispatch.php');
    if (preg_match('/\$routes\s*=\s*\[(.*?)\n\s*\];/s', $dispatch, $routeBlock) !== 1) {
        throw new \RuntimeException('Canonical route table is unavailable to the module compiler.');
    }
    preg_match_all("/'([a-z][a-z0-9_]*)'\\s*=>\\s*'([^']+)'/", $routeBlock[1], $pairs, PREG_SET_ORDER);
    $handlers = [];
    foreach ($pairs as $pair) {
        $handlers[$pair[1]] = ltrim(str_replace('\\\\', '\\', $pair[2]), '\\');
    }
    if ($handlers === []) {
        throw new \RuntimeException('Canonical route table contains no handlers.');
    }
    $handlers['not_found'] = 'Gallery\\Controllers\\cms_not_found';
    $roots = $policy['modules'];
    $routeModules = [];
    foreach ($handlers as $route => $handler) {
        $id = strtolower($handler);
        if (!isset($symbols[$id]) || $symbols[$id]['kind'] !== 'function') {
            throw new \RuntimeException('Route handler has no source owner: ' . $route);
        }
        $owner = module_owner($symbols[$id]['file'], $files);
        $module = $policy['handler_modules'][$handler]
            ?? 'domain-' . str_replace('_', '-', pathinfo($owner, PATHINFO_FILENAME));
        $routeModules[$route] = $module;
        $roots[$module][] = $handler;
    }

    $oracleClosures = [];
    foreach ($roots as $name => $entrypoints) {
        $pending = array_map(static fn(string $symbol): string => strtolower(ltrim($symbol, '\\')), $entrypoints);
        $visited = [];
        $owned = [];
        while ($pending !== []) {
            $id = array_pop($pending);
            if (isset($visited[$id])) {
                continue;
            }
            if (!isset($symbols[$id])) {
                throw new \RuntimeException('Unknown module root/dependency: ' . $id);
            }
            $visited[$id] = true;
            $owner = module_owner($symbols[$id]['file'], $files);
            // Front-controller early diagnostics are optional transport instrumentation,
            // initialized before bootstrap. Domain plans must preserve guarded hook seams.
            // Bootstrap defines canonical constants; runtime classes have their own autoloader.
            if (!in_array($owner, ['app/bootstrap.php', 'app/diagnostics/admin_test_run_early.php'], true)
                && !str_starts_with($owner, 'app/runtime/')) {
                $owned[$owner] = true;
            }
            foreach ($dependencies[$id] ?? [] as $dependency) {
                $pending[] = $dependency;
            }
        }
        $oracleClosures[$name] = runtime_module_ordered_paths(array_keys($owned));
    }
    $sharedModules = $policy['shared_modules'] ?? [];
    $moduleDependencies = $policy['module_dependencies'] ?? [];
    $routeDependencies = $policy['route_dependencies'] ?? [];
    if (!is_array($sharedModules) || !array_is_list($sharedModules)
        || !is_array($moduleDependencies) || !is_array($routeDependencies)) {
        throw new \RuntimeException('Runtime module dependency policy is malformed.');
    }

    // Shared owners are authored semantic roots. A candidate becomes a dependency
    // only when its complete old closure is contained by the consumer's oracle.
    $dependencyGraph = [];
    foreach ($oracleClosures as $name => $oracle) {
        $candidates = [];
        foreach ($sharedModules as $sharedName) {
            if (!is_string($sharedName) || !isset($oracleClosures[$sharedName])) {
                throw new \RuntimeException('Unknown shared runtime module: ' . (string) $sharedName);
            }
            if ($sharedName !== $name
                && count($oracleClosures[$sharedName]) < count($oracle)
                && array_diff($oracleClosures[$sharedName], $oracle) === []) {
                $candidates[] = $sharedName;
            }
        }
        // Keep maximal contained owners. A selected larger shared root already
        // carries every file from any smaller candidate it contains.
        $candidates = array_values(array_filter($candidates, static function (string $candidate) use ($candidates, $oracleClosures): bool {
            foreach ($candidates as $other) {
                if ($candidate !== $other
                    && count($oracleClosures[$candidate]) < count($oracleClosures[$other])
                    && array_diff($oracleClosures[$candidate], $oracleClosures[$other]) === []) {
                    return false;
                }
            }
            return true;
        }));
        $dependencyGraph[$name] = $candidates;
    }

    foreach ($moduleDependencies as $consumer => $dependenciesForModule) {
        if (!is_string($consumer) || !isset($oracleClosures[$consumer])
            || !is_array($dependenciesForModule) || !array_is_list($dependenciesForModule)) {
            throw new \RuntimeException('Explicit runtime module dependencies are malformed.');
        }
        foreach ($dependenciesForModule as $dependency) {
            if (!is_string($dependency) || !isset($oracleClosures[$dependency]) || $dependency === $consumer) {
                throw new \RuntimeException('Unknown explicit runtime module dependency for ' . $consumer);
            }
            if (array_diff($oracleClosures[$dependency], $oracleClosures[$consumer]) !== []) {
                throw new \RuntimeException('Explicit dependency closure is not contained by ' . $consumer . ': ' . $dependency);
            }
            $dependencyGraph[$consumer][] = $dependency;
        }
    }

    foreach ($routeDependencies as $dependency => $routesForDependency) {
        if (!is_string($dependency) || !isset($oracleClosures[$dependency])
            || !is_array($routesForDependency) || !array_is_list($routesForDependency)) {
            throw new \RuntimeException('Route runtime module dependencies are malformed.');
        }
        foreach ($routesForDependency as $route) {
            if (!is_string($route) || !isset($routeModules[$route])) {
                throw new \RuntimeException('Unknown route in runtime module dependency policy: ' . (string) $route);
            }
            $consumer = $routeModules[$route];
            if ($consumer === $dependency) {
                continue;
            }
            if (array_diff($oracleClosures[$dependency], $oracleClosures[$consumer]) !== []) {
                throw new \RuntimeException('Route dependency closure is not contained by ' . $consumer . ': ' . $dependency);
            }
            $dependencyGraph[$consumer][] = $dependency;
        }
    }

    foreach ($dependencyGraph as $name => $dependenciesForModule) {
        $dependencyGraph[$name] = array_values(array_unique($dependenciesForModule));
        $dependencyGraph[$name] = array_values(array_filter($dependencyGraph[$name], static function (string $dependency) use ($dependencyGraph, $oracleClosures, $name): bool {
            foreach ($dependencyGraph[$name] as $other) {
                if ($dependency !== $other && count($oracleClosures[$dependency]) < count($oracleClosures[$other])
                    && array_diff($oracleClosures[$dependency], $oracleClosures[$other]) === []) {
                    return false;
                }
            }
            return true;
        }));
        sort($dependencyGraph[$name], SORT_STRING);
    }

    $modules = [];
    foreach ($oracleClosures as $name => $oracle) {
        $inherited = [];
        foreach ($dependencyGraph[$name] as $dependency) {
            $inherited += array_fill_keys($oracleClosures[$dependency], true);
        }
        $direct = array_values(array_filter($oracle, static fn(string $path): bool => !isset($inherited[$path])));
        $modules[$name] = ['depends' => $dependencyGraph[$name], 'files' => $direct];
    }

    // One canonical order lets the runtime merge a module closure without
    // changing the historic core/model/service/view/controller include order.
    $fileOrder = runtime_module_ordered_paths(array_values(array_unique(array_merge(...array_values($oracleClosures)))));
    $plan = ['schema_version' => 2, 'route_modules' => $routeModules, 'file_order' => $fileOrder, 'modules' => $modules];
    foreach ($oracleClosures as $name => $oracle) {
        $actual = runtime_module_transitive_closure($plan, $name)['files'];
        if ($actual !== $oracle) {
            throw new \RuntimeException('Composed module differs from its previous ordered closure: ' . $name);
        }
    }
    ksort($modules, SORT_STRING);
    ksort($routeModules, SORT_STRING);
    return ['schema_version' => 2, 'route_modules' => $routeModules, 'file_order' => $fileOrder, 'modules' => $modules];
}

/**
 * Order runtime source paths by the historic layer rank and lexical path.
 *
 * @param list<string> $paths Unique or repeated project-relative PHP paths.
 * @return list<string> Deduplicated paths in canonical include order.
 */
function runtime_module_ordered_paths(array $paths): array
{
    $paths = array_values(array_unique($paths));
    usort($paths, static function (string $left, string $right): int {
        $rank = static fn(string $path): int => match (true) {
            str_starts_with($path, 'app/models/') => 1,
            str_starts_with($path, 'app/services/') => 2,
            str_starts_with($path, 'app/views/') => 3,
            str_starts_with($path, 'app/controllers/') => 4,
            default => 0,
        };
        return [$rank($left), $left] <=> [$rank($right), $right];
    });
    return $paths;
}

/**
 * Resolve one module's graph closure into dependency-first module names and canonical files.
 *
 * @param array<string,mixed> $plan A schema-version 2 compiled runtime plan.
 * @param string $module Logical module identifier to resolve.
 * @return array{modules:list<string>,files:list<string>} Dependency-first modules and globally ordered files.
 */
function runtime_module_transitive_closure(array $plan, string $module): array
{
    $definitions = $plan['modules'] ?? null;
    $fileOrder = $plan['file_order'] ?? null;
    if (($plan['schema_version'] ?? null) !== 2 || !is_array($definitions)
        || !is_array($fileOrder) || !array_is_list($fileOrder)) {
        throw new \InvalidArgumentException('Runtime module closure requires a schema-version 2 plan.');
    }
    $allFiles = [];
    foreach ($definitions as $name => $definition) {
        if (!is_string($name) || !is_array($definition)
            || !is_array($definition['depends'] ?? null) || !array_is_list($definition['depends'])
            || !is_array($definition['files'] ?? null) || !array_is_list($definition['files'])) {
            throw new \InvalidArgumentException('Malformed runtime module definition: ' . (string) $name);
        }
        foreach ($definition['depends'] as $dependency) {
            if (!is_string($dependency) || !isset($definitions[$dependency])) {
                throw new \InvalidArgumentException('Unknown runtime module dependency: ' . $name);
            }
        }
        foreach ($definition['files'] as $path) {
            if (!is_string($path) || preg_match('#^app/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.php$#D', $path) !== 1) {
                throw new \InvalidArgumentException('Malformed runtime module file: ' . $name);
            }
            $allFiles[$path] = true;
        }
    }
    $orderedSet = [];
    foreach ($fileOrder as $path) {
        if (!is_string($path) || preg_match('#^app/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.php$#D', $path) !== 1
            || !isset($allFiles[$path]) || isset($orderedSet[$path])) {
            throw new \InvalidArgumentException('Invalid or duplicate path in global runtime file order.');
        }
        $orderedSet[$path] = true;
    }
    if (count($orderedSet) !== count($allFiles) || $fileOrder !== runtime_module_ordered_paths($fileOrder)) {
        throw new \InvalidArgumentException('Global runtime file order is incomplete or non-canonical.');
    }
    $graphVisiting = [];
    $graphVisited = [];
    $validateNode = static function (string $name) use (&$validateNode, &$graphVisiting, &$graphVisited, $definitions): void {
        if (isset($graphVisited[$name])) {
            return;
        }
        if (isset($graphVisiting[$name])) {
            throw new \InvalidArgumentException('Cyclic runtime module graph: ' . $name);
        }
        $graphVisiting[$name] = true;
        foreach ($definitions[$name]['depends'] as $dependency) {
            $validateNode($dependency);
        }
        unset($graphVisiting[$name]);
        $graphVisited[$name] = true;
    };
    foreach (array_keys($definitions) as $name) {
        $validateNode($name);
    }
    $ordered = [];
    $visiting = [];
    $planned = [];
    $visit = static function (string $name) use (&$visit, &$ordered, &$visiting, &$planned, $definitions): void {
        if (isset($planned[$name])) {
            return;
        }
        if (!isset($definitions[$name]) || isset($visiting[$name])) {
            throw new \InvalidArgumentException('Unknown or cyclic runtime module: ' . $name);
        }
        $definition = $definitions[$name];
        if (!is_array($definition) || !is_array($definition['depends'] ?? null)
            || !is_array($definition['files'] ?? null) || !array_is_list($definition['depends'])
            || !array_is_list($definition['files'])) {
            throw new \InvalidArgumentException('Malformed runtime module definition: ' . $name);
        }
        $visiting[$name] = true;
        foreach ($definition['depends'] as $dependency) {
            if (!is_string($dependency)) {
                throw new \InvalidArgumentException('Malformed runtime module dependency: ' . $name);
            }
            $visit($dependency);
        }
        unset($visiting[$name]);
        $planned[$name] = true;
        $ordered[] = $name;
    };
    $visit($module);
    $files = [];
    foreach ($ordered as $name) {
        foreach ($definitions[$name]['files'] as $path) {
            if (!is_string($path)) {
                throw new \InvalidArgumentException('Malformed runtime module file: ' . $name);
            }
            $files[$path] = true;
        }
    }
    foreach ($fileOrder as $path) {
        if (!is_string($path)) {
            throw new \InvalidArgumentException('Malformed global runtime file order.');
        }
    }
    $orderedFiles = array_values(array_filter($fileOrder, static fn(string $path): bool => isset($files[$path])));
    if (count($orderedFiles) !== count($files) || count($orderedFiles) !== count(array_unique($orderedFiles))) {
        throw new \InvalidArgumentException('Runtime module files are missing from global file order.');
    }
    return ['modules' => $ordered, 'files' => $orderedFiles];
}

/**
 * Render the shipped plain-PHP loading plan with a deterministic attribution header.
 *
 * @param array<string,mixed> $plans Compiled module metadata.
 * @return string Complete PHP source for the checked-in loading plans.
 */
function render_runtime_modules(array $plans): string
{
    return "<?php\n\n/**\n * Project: PHP Gallery\n * Repository: https://github.com/klusik/PHP_gallery\n"
        . " * File: app/runtime/modules.php\n * Module Type: Core Configuration\n"
        . " * Purpose: Provide reviewed logical loading plans without runtime source scanning.\n"
        . " * Responsibilities: Own deterministic route-to-module and module-to-file dependency metadata.\n"
        . " * Author: Rudolf Klusal\n * License: MIT License (see LICENSE file in repository)\n"
        . " * Generated by: php scripts/generate_runtime_modules.php\n */\n\ndeclare(strict_types=1);\n\n"
        . 'return ' . preg_replace('/[\t ]+$/m', '', var_export($plans, true)) . ";\n";
}

/**
 * Generate or check the selective loading plans from the final source tree.
 *
 * @param list<string> $arguments Optional --check argument.
 * @return int Zero for a current plan, nonzero for drift or invalid arguments.
 */
function main(array $arguments): int
{
    if ($arguments !== [] && $arguments !== ['--check']) {
        fwrite(STDERR, "Usage: php scripts/generate_runtime_modules.php [--check]\n");
        return 2;
    }
    $root = dirname(__DIR__);
    $target = $root . '/app/runtime/modules.php';
    $plans = compile_runtime_modules($root);
    $source = render_runtime_modules($plans);
    if ($arguments === ['--check']) {
        if (!is_file($target) || file_get_contents($target) !== $source) {
            fwrite(STDERR, "Runtime module plans are stale; regenerate after dependency/route changes.\n");
            return 1;
        }
    } elseif (!is_file($target) || file_get_contents($target) !== $source) {
        if (is_link($target) || file_put_contents($target, $source, LOCK_EX) === false) {
            throw new \RuntimeException('Could not save runtime module plans.');
        }
    }
    fwrite(STDOUT, 'Runtime module plans current: ' . count($plans['route_modules']) . ' routes, '
        . count($plans['modules']) . " logical modules.\n");
    return 0;
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    try {
        exit(main(array_slice($argv, 1)));
    } catch (\Throwable $exception) {
        fwrite(STDERR, 'Runtime module compilation failed: ' . $exception->getMessage() . "\n");
        exit(1);
    }
}
