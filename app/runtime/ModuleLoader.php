<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/runtime/ModuleLoader.php
 * Module Type: Core Module
 * Purpose: Load reviewed logical runtime modules while preserving canonical file order.
 * Responsibilities: Validate complete composed plans and paths before includes and own request-local load state.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core;

/** Owns per-request module state and refuses invalid plans without a load-all fallback. */
final class ModuleLoader
{
    private array $loaded = [];
    private readonly string $root;
    private readonly ?array $fileOrder;

    /**
     * Bind a trusted project root and checked-in module definitions.
     *
     * @param string $root Absolute application checkout/installation root.
     * @param array<string,array{depends:list<string>,files:list<string>}> $definitions Reviewed logical module plans.
     * @param list<string>|null $fileOrder Complete canonical order for composed plans; null selects legacy traversal.
     * @return void Initializes the loader without including feature files.
     */
    public function __construct(
        string $root,
        private readonly array $definitions,
        ?array $fileOrder = null,
    ) {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) {
            throw new \InvalidArgumentException(
                'Runtime project root is unavailable.',
            );
        }
        $this->root = str_replace('\\', '/', $resolved);
        $this->fileOrder = $fileOrder;
        if ($fileOrder !== null) {
            $this->observe('validate', function (): void {
                $this->validateComposedPlan();
            });
        }
    }

    /**
     * Load a validated dependency plan once, keeping failure visible to the kernel.
     *
     * @param string $module Logical module identifier from route/lifecycle metadata.
     * @return void Includes the declared dependencies and entrypoints.
     */
    public function load(string $module): void
    {
        $traceIncludes = isset($GLOBALS['gallery_admin_test_run_early'])
            && function_exists(
                'Gallery\\Diagnostics\\admin_test_run_early_phase_start',
            );
        $ordered = [];
        $visiting = [];
        $planned = [];
        // Share physical checks only inside this preflight, before any include.
        // A later load gets fresh checks after possible filesystem updates.
        $checkedFiles = [];
        $this->observe('plan', function () use (
            $module, &$visiting, &$planned, &$ordered, &$checkedFiles,
        ): void {
            $this->plan($module, $visiting, $planned, $ordered, $checkedFiles);
        });
        if ($ordered === []) {
            return;
        }
        if ($this->fileOrder !== null) {
            $this->observe('execute', function () use ($ordered): void {
                $this->loadComposed($ordered);
            });
            return;
        }
        foreach ($ordered as $name) {
            $phase = 'runtime_module.' . $name;
            if (function_exists(
                'Gallery\\Diagnostics\\admin_test_run_early_phase_start',
            )) {
                \Gallery\Diagnostics\admin_test_run_early_phase_start($phase);
            }
            try {
                foreach ($this->definitions[$name]['files'] as $file) {
                    if ($traceIncludes) {
                        \Gallery\Diagnostics\admin_test_run_early_phase_start(
                            'runtime_loader.include',
                        );
                    }
                    require_once $this->root . '/' . $file;
                    if ($traceIncludes) {
                        \Gallery\Diagnostics\admin_test_run_early_phase_end(
                            'runtime_loader.include',
                        );
                    }
                }
            } catch (\Throwable $exception) {
                if ($traceIncludes) {
                    \Gallery\Diagnostics\admin_test_run_early_phase_end(
                        'runtime_loader.include', false, $exception,
                    );
                }
                if (function_exists(
                    'Gallery\\Diagnostics\\admin_test_run_early_phase_end',
                )) {
                    \Gallery\Diagnostics\admin_test_run_early_phase_end(
                        $phase,
                        false,
                        $exception,
                    );
                }
                throw $exception;
            }
            if (function_exists(
                'Gallery\\Diagnostics\\admin_test_run_early_phase_end',
            )) {
                \Gallery\Diagnostics\admin_test_run_early_phase_end($phase);
            }
            $this->loaded[$name] = true;
        }
    }

    /**
     * Validate all nodes, paths, and global ordering for a composed schema-version 2 plan.
     *
     * @return void Throws before feature files can be included when any plan part is invalid.
     */
    private function validateComposedPlan(): void
    {
        if (!array_is_list($this->fileOrder ?? [])) {
            throw new \RuntimeException('Invalid composed runtime file order.');
        }
        $allFiles = [];
        foreach ($this->definitions as $name => $definition) {
            if (
                !is_string($name)
                || preg_match('/^[a-z][a-z0-9-]*$/D', $name) !== 1
                || !is_array($definition)
                || !is_array($definition['depends'] ?? null)
                || !is_array($definition['files'] ?? null)
                || !array_is_list($definition['depends'])
                || !array_is_list($definition['files'])
            ) {
                throw new \RuntimeException(
                    'Invalid composed runtime module plan.',
                );
            }
            $seenFiles = [];
            foreach ($definition['files'] as $file) {
                if (
                    !is_string($file)
                    || preg_match(
                        '#^app/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\\.php$#D',
                        $file,
                    ) !== 1
                    || isset($seenFiles[$file])
                ) {
                    throw new \RuntimeException('Invalid composed runtime module entrypoint: '
                        . $name);
                }
                $seenFiles[$file] = true;
                $allFiles[$file] = true;
            }
            foreach ($definition['depends'] as $dependency) {
                if (
                    !is_string($dependency)
                    || !isset($this->definitions[$dependency])
                ) {
                    throw new \RuntimeException('Unknown composed runtime module dependency: '
                        . $name);
                }
            }
        }
        $seenOrder = [];
        foreach ($this->fileOrder as $file) {
            if (
                !is_string($file)
                || preg_match(
                    '#^app/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\\.php$#D',
                    $file,
                ) !== 1
                || !isset($allFiles[$file])
                || isset($seenOrder[$file])
            ) {
                throw new \RuntimeException(
                    'Invalid or duplicate path in composed runtime file order.',
                );
            }
            $seenOrder[$file] = true;
        }
        if (
            count($seenOrder) !== count($allFiles)
            || !$this->isCanonicalFileOrder($this->fileOrder)
        ) {
            throw new \RuntimeException(
                'Composed runtime file order is incomplete or non-canonical.',
            );
        }
        $visiting = [];
        $visited = [];
        foreach (array_keys($this->definitions) as $name) {
            $this->validateComposedGraphNode($name, $visiting, $visited);
        }
    }

    /**
     * Validate one dependency node across the complete composed graph.
     *
     * @param string $module Logical module currently being visited.
     * @param array<string,bool> $visiting Active dependency chain for cycle detection.
     * @param array<string,bool> $visited Fully validated modules.
     * @return void Throws when the dependency graph contains a cycle.
     */
    private function validateComposedGraphNode(
        string $module,
        array &$visiting,
        array &$visited,
    ): void {
        if (isset($visited[$module])) {
            return;
        }
        if (isset($visiting[$module])) {
            throw new \RuntimeException('Circular composed runtime module dependency: '
                . $module);
        }
        $visiting[$module] = true;
        foreach ($this->definitions[$module]['depends'] as $dependency) {
            $this->validateComposedGraphNode($dependency, $visiting, $visited);
        }
        unset($visiting[$module]);
        $visited[$module] = true;
    }

    /**
     * Include a validated module closure in global canonical file order.
     *
     * @param list<string> $modules Dependency-first logical modules selected by a route.
     * @return void Includes selected files and records successful modules.
     */
    private function loadComposed(array $modules): void
    {
        $traceIncludes = isset($GLOBALS['gallery_admin_test_run_early'])
            && function_exists(
                'Gallery\\Diagnostics\\admin_test_run_early_phase_start',
            );
        $owners = [];
        $remaining = [];
        $waiting = [];
        foreach ($modules as $name) {
            $remaining[$name] = count($this->definitions[$name]['files']);
            foreach ($this->definitions[$name]['files'] as $file) {
                $owners[$file] ??= $name;
                $waiting[$file][] = $name;
            }
        }
        $phase = null;
        $completedPrefix = 0;
        $this->markCompletedComposedModules(
            $modules, $remaining, $completedPrefix,
        );
        try {
            foreach ($this->fileOrder ?? [] as $file) {
                if (!isset($owners[$file])) {
                    continue;
                }
                $owner = $owners[$file];
                if ($phase !== $owner) {
                    if (
                        $phase !== null
                        && function_exists(
                            'Gallery\\Diagnostics\\admin_test_run_early_phase_end',
                        )
                    ) {
                        \Gallery\Diagnostics\admin_test_run_early_phase_end('runtime_module.'
                            . $phase);
                    }
                    $phase = $owner;
                    if (function_exists(
                        'Gallery\\Diagnostics\\admin_test_run_early_phase_start',
                    )) {
                        \Gallery\Diagnostics\admin_test_run_early_phase_start('runtime_module.'
                            . $phase);
                    }
                }
                if ($traceIncludes) {
                    \Gallery\Diagnostics\admin_test_run_early_phase_start(
                        'runtime_loader.include',
                    );
                }
                require_once $this->root . '/' . $file;
                if ($traceIncludes) {
                    \Gallery\Diagnostics\admin_test_run_early_phase_end(
                        'runtime_loader.include',
                    );
                }
                foreach ($waiting[$file] as $name) {
                    $remaining[$name]--;
                }
                $this->markCompletedComposedModules(
                    $modules, $remaining, $completedPrefix,
                );
            }
        } catch (\Throwable $exception) {
            if ($traceIncludes) {
                \Gallery\Diagnostics\admin_test_run_early_phase_end(
                    'runtime_loader.include', false, $exception,
                );
            }
            if (
                $phase !== null
                && function_exists(
                    'Gallery\\Diagnostics\\admin_test_run_early_phase_end',
                )
            ) {
                \Gallery\Diagnostics\admin_test_run_early_phase_end(
                    'runtime_module.' . $phase,
                    false,
                    $exception,
                );
            }
            throw $exception;
        }
        if (
            $phase !== null
            && function_exists(
                'Gallery\\Diagnostics\\admin_test_run_early_phase_end',
            )
        ) {
            \Gallery\Diagnostics\admin_test_run_early_phase_end('runtime_module.'
                . $phase);
        }
        $this->markCompletedComposedModules(
            $modules, $remaining, $completedPrefix,
        );
    }

    /**
     * Record the completed dependency-first prefix without claiming later partial modules.
     *
     * @param list<string> $modules Dependency-first modules in the requested closure.
     * @param array<string,int> $remaining Untraversed file counts per selected module.
     * @param int $completedPrefix Length of the completed dependency-first prefix.
     * @return void Appends the conservative completed prefix in dependency-first order.
     */
    private function markCompletedComposedModules(
        array $modules,
        array $remaining,
        int &$completedPrefix,
    ): void {
        while (isset($modules[$completedPrefix])) {
            $name = $modules[$completedPrefix];
            if (isset($this->loaded[$name])) {
                $completedPrefix++;
                continue;
            }
            $definition = $this->definitions[$name];
            if ($remaining[$name] !== 0) {
                break;
            }
            $complete = true;
            foreach ($definition['depends'] as $dependency) {
                if (!isset($this->loaded[$dependency])) {
                    $complete = false;
                    break;
                }
            }
            if ($complete) {
                $this->loaded[$name] = true;
                $completedPrefix++;
                continue;
            }
            // Canonical file order may complete independent branches in another order.
            // Preserve stable DFS diagnostics by exposing only the completed prefix.
            break;
        }
    }

    /**
     * Verify the canonical core/model/service/view/controller order in one pass.
     *
     * @param list<string> $files Root-relative application PHP files.
     * @return bool Whether adjacent paths preserve historical layer and lexical order.
     */
    private function isCanonicalFileOrder(array $files): bool
    {
        $previous = null;
        foreach ($files as $file) {
            // Structural ranks preserve the compiler's established five layers.
            $rank = match (true) {
                str_starts_with($file, 'app/models/') => 1,
                str_starts_with($file, 'app/services/') => 2,
                str_starts_with($file, 'app/views/') => 3,
                str_starts_with($file, 'app/controllers/') => 4,
                default => 0,
            };
            $current = [$rank, $file];
            if ($previous !== null && $previous > $current) {
                return false;
            }
            $previous = $current;
        }
        return true;
    }

    /**
     * Validate the complete graph before any entrypoint executes include-time code.
     *
     * @param string $module Logical module currently being visited.
     * @param array<string,bool> $visiting Active dependency chain for cycle detection.
     * @param array<string,bool> $planned Completed nodes within this request's load plan.
     * @param list<string> $ordered Dependency-first modules to execute.
     * @param array<string,bool> $checkedFiles Physically verified paths in this preflight only.
     * @return void Appends validated modules or throws on an invalid dependency/path.
     */
    private function plan(
        string $module,
        array &$visiting,
        array &$planned,
        array &$ordered,
        array &$checkedFiles,
    ): void {
        if (
            isset($this->loaded[$module], $this->definitions[$module])
            || isset($planned[$module])
        ) {
            return;
        }
        if (!isset($this->definitions[$module])) {
            throw new \RuntimeException('Unknown runtime module: ' . $module);
        }
        if (isset($visiting[$module])) {
            throw new \RuntimeException('Circular runtime module dependency: '
                . $module);
        }
        $definition = $this->definitions[$module];
        if (
            !is_array($definition)
            || !is_array($definition['depends'] ?? null)
            || !is_array($definition['files'] ?? null)
            || !array_is_list($definition['depends'])
            || !array_is_list($definition['files'])
        ) {
            throw new \RuntimeException('Invalid runtime module plan: '
                . $module);
        }
        $visiting[$module] = true;
        foreach ($definition['depends'] as $dependency) {
            if (!is_string($dependency)) {
                throw new \RuntimeException('Invalid runtime module dependency: '
                    . $module);
            }
            $this->plan($dependency, $visiting, $planned, $ordered, $checkedFiles);
        }
        foreach ($definition['files'] as $file) {
            if (is_string($file) && isset($checkedFiles[$file])) {
                continue;
            }
            if (
                !is_string($file)
                || preg_match(
                    '#^app/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\\.php$#D',
                    $file,
                ) !== 1
            ) {
                throw new \RuntimeException('Invalid runtime module entrypoint: '
                    . $module);
            }
            $resolved = realpath($this->root . '/' . $file);
            $normalized = $resolved !== false
                ? str_replace('\\', '/', $resolved)
                : '';
            $prefix = $this->root . '/app/';
            if (PHP_OS_FAMILY === 'Windows') {
                $normalized = strtolower($normalized);
                $prefix = strtolower($prefix);
            }
            if (
                $resolved === false
                || !is_file($resolved)
                || !str_starts_with($normalized, $prefix)
            ) {
                throw new \RuntimeException('Missing or escaped runtime module entrypoint: '
                    . $module);
            }
            $checkedFiles[$file] = true;
        }
        unset($visiting[$module]);
        $planned[$module] = true;
        $ordered[] = $module;
    }

    /**
     * Expose successfully loaded logical modules for bounded diagnostics and tests.
     *
     * @return list<string> Module identifiers in actual completion order.
     */
    public function loadedModules(): array
    {
        return array_keys($this->loaded);
    }

    /**
     * Observe loader work through the existing opt-in early Test Run trace.
     *
     * @param string $phase Fixed loader phase: validate, plan, execute or include.
     * @param callable():void $operation Original operation, invoked exactly once.
     * @return void Preserve operation failures and record active diagnostics only.
     */
    private function observe(string $phase, callable $operation): void
    {
        $trace = function_exists(
            'Gallery\\Diagnostics\\admin_test_run_early_phase_start',
        ) && isset($GLOBALS['gallery_admin_test_run_early'])
            && empty($GLOBALS['gallery_admin_test_run_early']['adopted']);
        if (!$trace) {
            $operation();
            return;
        }
        $name = 'runtime_loader.' . $phase;
        \Gallery\Diagnostics\admin_test_run_early_phase_start($name);
        try {
            $operation();
        } catch (\Throwable $exception) {
            \Gallery\Diagnostics\admin_test_run_early_phase_end(
                $name, false, $exception,
            );
            throw $exception;
        }
        \Gallery\Diagnostics\admin_test_run_early_phase_end($name);
    }
}
