<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/runtime/ModuleLoader.php
 * Module Type: Core Module
 * Purpose: Load reviewed logical runtime modules in their declared dependency order.
 * Responsibilities: Validate complete plans and paths before includes and own request-local load state.
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

    /**
     * Bind a trusted project root and checked-in module definitions.
     *
     * @param string $root Absolute application checkout/installation root.
     * @param array<string,array{depends:list<string>,files:list<string>}> $definitions Reviewed logical module plans.
     * @return void Initializes the loader without including any feature files.
     */
    public function __construct(string $root, private readonly array $definitions)
    {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) {
            throw new \InvalidArgumentException('Runtime project root is unavailable.');
        }
        $this->root = str_replace('\\', '/', $resolved);
    }

    /**
     * Load a validated dependency plan once, keeping failure visible to the kernel.
     *
     * @param string $module Logical module identifier from route/lifecycle metadata.
     * @return void Includes the declared dependencies and entrypoints.
     */
    public function load(string $module): void
    {
        $ordered = [];
        $visiting = [];
        $planned = [];
        $this->plan($module, $visiting, $planned, $ordered);
        foreach ($ordered as $name) {
            $phase = 'runtime_module.' . $name;
            if (function_exists('Gallery\\Diagnostics\\admin_test_run_early_phase_start')) {
                \Gallery\Diagnostics\admin_test_run_early_phase_start($phase);
            }
            try {
                foreach ($this->definitions[$name]['files'] as $file) {
                    require_once $this->root . '/' . $file;
                }
            } catch (\Throwable $exception) {
                if (function_exists('Gallery\\Diagnostics\\admin_test_run_early_phase_end')) {
                    \Gallery\Diagnostics\admin_test_run_early_phase_end($phase, false, $exception);
                }
                throw $exception;
            }
            if (function_exists('Gallery\\Diagnostics\\admin_test_run_early_phase_end')) {
                \Gallery\Diagnostics\admin_test_run_early_phase_end($phase);
            }
            $this->loaded[$name] = true;
        }
    }

    /**
     * Validate the complete graph before any entrypoint executes include-time code.
     *
     * @param string $module Logical module currently being visited.
     * @param array<string,bool> $visiting Active dependency chain for cycle detection.
     * @param array<string,bool> $planned Completed nodes within this request's load plan.
     * @param list<string> $ordered Dependency-first modules to execute.
     * @return void Appends validated modules or throws on an invalid dependency/path.
     */
    private function plan(string $module, array &$visiting, array &$planned, array &$ordered): void
    {
        if (isset($this->loaded[$module], $this->definitions[$module]) || isset($planned[$module])) {
            return;
        }
        if (!isset($this->definitions[$module])) {
            throw new \RuntimeException('Unknown runtime module: ' . $module);
        }
        if (isset($visiting[$module])) {
            throw new \RuntimeException('Circular runtime module dependency: ' . $module);
        }
        $definition = $this->definitions[$module];
        if (!is_array($definition) || !is_array($definition['depends'] ?? null)
            || !is_array($definition['files'] ?? null)
            || !array_is_list($definition['depends']) || !array_is_list($definition['files'])) {
            throw new \RuntimeException('Invalid runtime module plan: ' . $module);
        }
        $visiting[$module] = true;
        foreach ($definition['depends'] as $dependency) {
            if (!is_string($dependency)) {
                throw new \RuntimeException('Invalid runtime module dependency: ' . $module);
            }
            $this->plan($dependency, $visiting, $planned, $ordered);
        }
        foreach ($definition['files'] as $file) {
            if (!is_string($file) || preg_match('#^app/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\\.php$#D', $file) !== 1) {
                throw new \RuntimeException('Invalid runtime module entrypoint: ' . $module);
            }
            $resolved = realpath($this->root . '/' . $file);
            $normalized = $resolved !== false ? str_replace('\\', '/', $resolved) : '';
            $prefix = $this->root . '/app/';
            if (PHP_OS_FAMILY === 'Windows') {
                $normalized = strtolower($normalized);
                $prefix = strtolower($prefix);
            }
            if ($resolved === false || !is_file($resolved)
                || !str_starts_with($normalized, $prefix)) {
                throw new \RuntimeException('Missing or escaped runtime module entrypoint: ' . $module);
            }
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
}
