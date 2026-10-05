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
 * @return array{schema_version:int,route_modules:array<string,string>,modules:array<string,array{depends:list<string>,files:list<string>}>} Shippable logical module plans.
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

    $modules = [];
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
        $paths = array_keys($owned);
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
        $modules[$name] = ['depends' => [], 'files' => $paths];
    }
    ksort($modules, SORT_STRING);
    ksort($routeModules, SORT_STRING);
    return ['schema_version' => 1, 'route_modules' => $routeModules, 'modules' => $modules];
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
        . 'return ' . var_export($plans, true) . ";\n";
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
    } elseif (file_put_contents($target, $source, LOCK_EX) === false) {
        throw new \RuntimeException('Could not save runtime module plans.');
    }
    fwrite(STDOUT, 'Runtime module plans current: ' . count($plans['route_modules']) . ' routes, '
        . count($plans['modules']) . " logical modules.\n");
    return 0;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    try {
        exit(main(array_slice($argv, 1)));
    } catch (\Throwable $exception) {
        fwrite(STDERR, 'Runtime module compilation failed: ' . $exception->getMessage() . "\n");
        exit(1);
    }
}
