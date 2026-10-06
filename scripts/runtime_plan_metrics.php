<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/runtime_plan_metrics.php
 * Module Type: Audit Measurement Model
 * Purpose: Measure schema-versioned runtime module plans and apply route growth ratchets.
 * Responsibilities: Derive deterministic route closures and compare them with reviewed metrics.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace PhpGallery\Audit;

/**
 * Build deterministic structural measurements for the registered runtime routes.
 *
 * @param array<string,mixed> $plan Reviewed generated runtime module plan.
 * @param array<string,string> $routes Route probe identifiers mapped to runtime route names.
 * @return array<string,array<string,mixed>> Per-probe root, transitive-module, file, and overlap metrics.
 */
function runtime_plan_route_metrics(array $plan, array $routes): array
{
    if (($plan['schema_version'] ?? null) !== 2
        || !is_array($plan['route_modules'] ?? null)
        || !is_array($plan['modules'] ?? null)
        || !is_array($plan['file_order'] ?? null)) {
        throw new \UnexpectedValueException('Runtime module plan must use the complete schema-2 shape.');
    }
    require_once __DIR__ . '/generate_runtime_modules.php';

    $closures = [];
    $roots = [];
    foreach ($routes as $probeId => $routeName) {
        if (!is_string($probeId) || $probeId === '' || !is_string($routeName) || $routeName === '') {
            throw new \UnexpectedValueException('Runtime route metric identities must be non-empty strings.');
        }
        $root = $plan['route_modules'][$routeName] ?? null;
        if (!is_string($root) || $root === '') {
            throw new \UnexpectedValueException('Runtime route has no valid root module: ' . $routeName . '.');
        }
        $closure = \Gallery\Tools\RuntimeModules\runtime_module_transitive_closure($plan, $root);
        if (!is_array($closure) || !is_array($closure['modules'] ?? null) || !is_array($closure['files'] ?? null)
            || !array_is_list($closure['modules']) || !array_is_list($closure['files'])) {
            throw new \UnexpectedValueException('Runtime module closure helper returned an invalid result.');
        }
        $closures[$probeId] = ['modules' => $closure['modules'], 'files' => $closure['files']];
        $roots[$probeId] = $root;
    }

    $allFileUses = [];
    foreach ($closures as $closure) {
        foreach ($closure['files'] as $file) {
            if (!is_string($file) || $file === '') {
                throw new \UnexpectedValueException('Runtime module closure contains an invalid file path.');
            }
            $allFileUses[$file] = ($allFileUses[$file] ?? 0) + 1;
        }
    }

    $metrics = [];
    foreach ($closures as $probeId => $closure) {
        $shared = 0;
        $directEntries = 0;
        $directFiles = [];
        foreach ($closure['files'] as $file) {
            if (($allFileUses[$file] ?? 0) > 1) {
                $shared++;
            }
        }
        foreach ($closure['modules'] as $moduleName) {
            $module = $plan['modules'][$moduleName] ?? null;
            if (!is_array($module) || !is_array($module['files'] ?? null) || !array_is_list($module['files'])) {
                throw new \UnexpectedValueException('Runtime module closure references malformed direct file ownership.');
            }
            $directEntries += count($module['files']);
            foreach ($module['files'] as $file) {
                if (!is_string($file) || $file === '') {
                    throw new \UnexpectedValueException('Runtime module owns an invalid direct file path.');
                }
                $directFiles[$file] = true;
            }
        }
        $metrics[$probeId] = [
            'root_module' => $roots[$probeId],
            'root_count' => 1,
            'transitive_module_count' => count($closure['modules']),
            'plan_file_count' => count($closure['files']),
            'shared_file_count' => $shared,
            'direct_file_entries' => $directEntries,
            'direct_duplicate_file_entries' => $directEntries - count($directFiles),
        ];
    }
    return $metrics;
}

/**
 * Measure global direct file ownership and duplicate entries in the generated plan.
 *
 * @param array<string,mixed> $plan Reviewed generated runtime module plan.
 * @return array{module_count:int,direct_file_entries:int,unique_direct_file_count:int,direct_duplicate_file_entries:int} Plan-wide structural metrics.
 */
function runtime_plan_inventory_metrics(array $plan): array
{
    if (!is_array($plan['modules'] ?? null)) {
        throw new \UnexpectedValueException('Runtime module plan has no module inventory.');
    }
    $directEntries = 0;
    $files = [];
    foreach ($plan['modules'] as $name => $module) {
        if (!is_string($name) || $name === '' || !is_array($module)
            || !is_array($module['files'] ?? null) || !array_is_list($module['files'])) {
            throw new \UnexpectedValueException('Runtime module inventory contains a malformed module.');
        }
        foreach ($module['files'] as $file) {
            if (!is_string($file) || $file === '') {
                throw new \UnexpectedValueException('Runtime module inventory contains an invalid file path.');
            }
            $directEntries++;
            $files[$file] = true;
        }
    }
    return [
        'module_count' => count($plan['modules']),
        'direct_file_entries' => $directEntries,
        'unique_direct_file_count' => count($files),
        'direct_duplicate_file_entries' => $directEntries - count($files),
    ];
}

/**
 * Validate a structural route measurement against a reviewed baseline with narrow growth allowances.
 *
 * File growth permits four PHP files or five percent, whichever is larger. Memory growth permits four
 * MiB to absorb allocator granularity changes between supported PHP builds. Module growth permits two
 * modules after the reviewed schema-2 composition. Wall time is observational.
 *
 * @param array<string,mixed> $current Current structural or captured lifecycle route metrics.
 * @param array<string,mixed> $baseline Reviewed post-composition structure plus pre-change lifecycle metrics where present.
 * @return array<int,string> Violations; an empty list means the route remains inside its ratchet.
 */
function runtime_plan_ratchet_problems(array $current, array $baseline): array
{
    $problems = [];
    foreach (['plan_file_count', 'included_php_files', 'peak_memory_bytes', 'shared_file_count'] as $field) {
        if (!array_key_exists($field, $current)) {
            continue;
        }
        $value = $current[$field];
        $old = $baseline[$field] ?? null;
        $minimum = $field === 'shared_file_count' ? 0 : 1;
        if (!is_int($value) || $value < $minimum || !is_int($old) || $old < $minimum) {
            $problems[] = $field . ' must be an integer in both current metrics and baseline.';
            continue;
        }
        $allowance = $field === 'peak_memory_bytes'
            ? 4 * 1024 * 1024
            : max(4, (int) ceil($old * 0.05));
        if ($value > $old + $allowance) {
            $problems[] = $field . '=' . $value . ' exceeds baseline ' . $old . ' plus allowance ' . $allowance . '.';
        }
    }
    foreach (['root_count', 'transitive_module_count', 'direct_duplicate_file_entries'] as $field) {
        if (array_key_exists($field, $current)) {
            if (!is_int($current[$field]) || $current[$field] < 0
                || !is_int($baseline[$field] ?? null) || $baseline[$field] < 0) {
                $problems[] = $field . ' must be a non-negative integer in both current metrics and baseline.';
            }
        }
    }
    if (isset($current['root_module']) && (!is_string($current['root_module']) || $current['root_module'] === '')) {
        $problems[] = 'root_module must be a non-empty string.';
    }
    if (isset($current['root_module']) && isset($baseline['root_module'])
        && $current['root_module'] !== $baseline['root_module']) {
        $problems[] = 'root_module differs from the reviewed route owner.';
    }
    if (isset($current['root_count']) && is_int($current['root_count'])
        && is_int($baseline['root_count'] ?? null) && $current['root_count'] !== $baseline['root_count']) {
        $problems[] = 'root_count differs from the reviewed route root count.';
    }
    if (isset($current['transitive_module_count']) && is_int($current['transitive_module_count'])
        && is_int($baseline['transitive_module_count'] ?? null)) {
        // Two new modules allow a small follow-on split; larger fan-out needs baseline review.
        if ($current['transitive_module_count'] > $baseline['transitive_module_count'] + 2) {
            $problems[] = 'transitive_module_count exceeds the reviewed decomposition allowance.';
        }
    }
    if (isset($current['direct_duplicate_file_entries']) && is_int($current['direct_duplicate_file_entries'])
        && is_int($baseline['direct_duplicate_file_entries'] ?? null)
        && $current['direct_duplicate_file_entries'] > $baseline['direct_duplicate_file_entries']) {
        $problems[] = 'direct_duplicate_file_entries exceeds the reviewed route baseline.';
    }
    return $problems;
}

/**
 * Validate the ratchet baseline document and return route metrics by stable probe identifier.
 *
 * @param array{schema_version?:int,scope?:string,routes?:array<string,array<string,int|string>>,plan?:array<string,int>}|array<array-key,scalar|array<array-key,scalar|array<array-key,scalar>>>|scalar|null $baseline Decoded JSON candidate; malformed values are rejected.
 * @param array<int,string> $probeIds Required registered probe identifiers.
 * @return array<string,array<string,mixed>> Validated baseline metrics keyed by probe identifier.
 */
function runtime_plan_baseline_routes(mixed $baseline, array $probeIds): array
{
    if (!is_array($baseline) || ($baseline['schema_version'] ?? null) !== 1
        || ($baseline['scope'] ?? null) !== 'runtime-plan-route-ratchet'
        || !is_array($baseline['routes'] ?? null) || !is_array($baseline['plan'] ?? null)) {
        throw new \UnexpectedValueException('Runtime plan ratchet baseline has an invalid schema.');
    }
    runtime_plan_baseline_inventory($baseline);
    $routes = $baseline['routes'];
    $expected = $probeIds;
    sort($expected, SORT_STRING);
    $actual = array_keys($routes);
    sort($actual, SORT_STRING);
    if ($actual !== $expected) {
        throw new \UnexpectedValueException('Runtime plan ratchet baseline probe identities do not match the registry.');
    }
    foreach ($routes as $probeId => $metrics) {
        if (!is_string($probeId) || !is_array($metrics)) {
            throw new \UnexpectedValueException('Runtime plan ratchet baseline route entry is malformed.');
        }
        foreach (['root_count', 'transitive_module_count', 'plan_file_count', 'shared_file_count', 'direct_file_entries', 'direct_duplicate_file_entries', 'included_php_files', 'peak_memory_bytes'] as $field) {
            if (!is_int($metrics[$field] ?? null) || $metrics[$field] < 0) {
                throw new \UnexpectedValueException('Runtime plan ratchet baseline metric is invalid: ' . $probeId . '.' . $field . '.');
            }
        }
        if ($metrics['plan_file_count'] < 1 || $metrics['included_php_files'] < 1 || $metrics['peak_memory_bytes'] < 1
            || $metrics['root_count'] !== 1 || $metrics['transitive_module_count'] < 1
            || $metrics['direct_file_entries'] < $metrics['direct_duplicate_file_entries']
            || $metrics['direct_file_entries'] - $metrics['direct_duplicate_file_entries'] !== $metrics['plan_file_count']
            || !is_string($metrics['root_module'] ?? null) || $metrics['root_module'] === '') {
            throw new \UnexpectedValueException('Runtime plan ratchet baseline route entry is incomplete: ' . $probeId . '.');
        }
    }
    return $routes;
}

/**
 * Validate the baseline plan-wide ownership measurements.
 *
 * @param array{plan?:array{module_count?:int,direct_file_entries?:int,unique_direct_file_count?:int,direct_duplicate_file_entries?:int}}|array<array-key,scalar|array<array-key,scalar>>|scalar|null $baseline Decoded JSON candidate; malformed values are rejected.
 * @return array{module_count:int,direct_file_entries:int,unique_direct_file_count:int,direct_duplicate_file_entries:int} Validated plan inventory baseline.
 */
function runtime_plan_baseline_inventory(mixed $baseline): array
{
    if (!is_array($baseline) || !is_array($baseline['plan'] ?? null)) {
        throw new \UnexpectedValueException('Runtime plan ratchet baseline has no plan inventory.');
    }
    $plan = $baseline['plan'];
    foreach (['module_count', 'direct_file_entries', 'unique_direct_file_count', 'direct_duplicate_file_entries'] as $field) {
        if (!is_int($plan[$field] ?? null) || $plan[$field] < 0) {
            throw new \UnexpectedValueException('Runtime plan baseline inventory metric is invalid: ' . $field . '.');
        }
    }
    if ($plan['module_count'] < 1 || $plan['unique_direct_file_count'] < 1
        || $plan['direct_file_entries'] < $plan['unique_direct_file_count']
        || $plan['direct_duplicate_file_entries'] !== $plan['direct_file_entries'] - $plan['unique_direct_file_count']) {
        throw new \UnexpectedValueException('Runtime plan baseline inventory metrics are inconsistent.');
    }
    return $plan;
}

/**
 * Ensure direct file ownership remains inside the reviewed post-composition inventory.
 *
 * @param array<string,mixed> $current Current plan-wide direct ownership metrics.
 * @param array<string,mixed> $baseline Reviewed post-composition plan inventory.
 * @return array<int,string> Structural duplicate ownership regressions.
 */
function runtime_plan_inventory_problems(array $current, array $baseline): array
{
    foreach (['module_count', 'direct_file_entries', 'unique_direct_file_count', 'direct_duplicate_file_entries'] as $field) {
        if (!is_int($current[$field] ?? null) || $current[$field] < 0
            || !is_int($baseline[$field] ?? null) || $baseline[$field] < 0) {
            return ['Plan inventory metric is invalid: ' . $field . '.'];
        }
    }
    $fileAllowance = max(4, (int) ceil($baseline['direct_file_entries'] * 0.05));
    if ($current['direct_file_entries'] > $baseline['direct_file_entries'] + $fileAllowance) {
        return ['Direct module file entries grew beyond the reviewed plan allowance.'];
    }
    if ($current['module_count'] > $baseline['module_count'] + 2) {
        return ['Logical module count grew beyond the reviewed plan allowance.'];
    }
    if ($current['direct_duplicate_file_entries'] > $baseline['direct_duplicate_file_entries']) {
        return ['Direct file ownership duplicates grew beyond the reviewed plan.'];
    }
    return [];
}
