<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/runtime_plan_ratchet_test.php
 * Module Type: Regression Test
 * Purpose: Verify structural runtime-plan metrics and tight per-route growth ratchets.
 * Responsibilities: Exercise graph validation, reviewed baselines, and bounded route growth.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

require_once __DIR__ . '/../scripts/runtime_plan_metrics.php';

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
};
$throws = static function (callable $operation): bool {
    try {
        $operation();
    } catch (Throwable) {
        return true;
    }
    return false;
};

$validPlan = [
    'schema_version' => 2,
    'route_modules' => ['route_one' => 'feature-one', 'route_two' => 'feature-two'],
    'modules' => [
        'shared' => ['depends' => [], 'files' => ['app/shared.php']],
        'feature-one' => ['depends' => ['shared'], 'files' => ['app/one.php']],
        'feature-two' => ['depends' => ['shared'], 'files' => ['app/two.php']],
    ],
    'file_order' => ['app/one.php', 'app/shared.php', 'app/two.php'],
];
$metrics = \PhpGallery\Audit\runtime_plan_route_metrics($validPlan, [
    'probe_one' => 'route_one',
    'probe_two' => 'route_two',
]);
$expect($metrics['probe_one']['root_module'] === 'feature-one'
    && $metrics['probe_one']['root_count'] === 1
    && $metrics['probe_one']['transitive_module_count'] === 2
    && $metrics['probe_one']['plan_file_count'] === 2
    && $metrics['probe_one']['shared_file_count'] === 1
    && $metrics['probe_one']['direct_file_entries'] === 2
    && $metrics['probe_one']['direct_duplicate_file_entries'] === 0,
    'A route should report its root, transitive modules, files, and shared overlap.');

$baseline = [
    'schema_version' => 1,
    'scope' => 'runtime-plan-route-ratchet',
    'routes' => [
        'probe_one' => [
            'root_module' => 'feature-one', 'root_count' => 1, 'transitive_module_count' => 2,
            'plan_file_count' => 100, 'shared_file_count' => 5, 'direct_file_entries' => 100,
            'direct_duplicate_file_entries' => 0, 'included_php_files' => 100,
            'peak_memory_bytes' => 16 * 1024 * 1024,
        ],
        'probe_two' => [
            'root_module' => 'feature-two', 'root_count' => 1, 'transitive_module_count' => 2,
            'plan_file_count' => 100, 'shared_file_count' => 5, 'direct_file_entries' => 100,
            'direct_duplicate_file_entries' => 0, 'included_php_files' => 100,
            'peak_memory_bytes' => 16 * 1024 * 1024,
        ],
    ],
    'plan' => [
        'module_count' => 2, 'direct_file_entries' => 200,
        'unique_direct_file_count' => 150, 'direct_duplicate_file_entries' => 50,
    ],
];
$baselines = \PhpGallery\Audit\runtime_plan_baseline_routes($baseline, ['probe_one', 'probe_two']);
$expect(\PhpGallery\Audit\runtime_plan_ratchet_problems($metrics['probe_one'] + [
    'included_php_files' => 104,
    'peak_memory_bytes' => 20 * 1024 * 1024,
], $baselines['probe_one']) === [],
    'The documented four-file and four-MiB tolerances should pass at the boundary.');

$fileBoundary = array_replace($metrics['probe_one'], ['plan_file_count' => 105]);
$expect(\PhpGallery\Audit\runtime_plan_ratchet_problems($fileBoundary, $baselines['probe_one']) === [],
    'Five-percent file growth should pass exactly at the tolerance boundary.');
$isolatedRoute = array_replace($metrics['probe_one'], ['shared_file_count' => 0]);
$isolatedBaseline = array_replace($baselines['probe_one'], ['shared_file_count' => 0]);
$expect(\PhpGallery\Audit\runtime_plan_ratchet_problems($isolatedRoute, $isolatedBaseline) === [],
    'A unique route with no overlap must remain a valid zero-overlap measurement.');
$largeFanout = array_replace($metrics['probe_one'], ['plan_file_count' => 106]);
$expect(\PhpGallery\Audit\runtime_plan_ratchet_problems($largeFanout, $baselines['probe_one']) !== [],
    'A route fan-out increase must fail above the tight ratchet even below the old 160-file hard ceiling.');
$largeRuntime = ['included_php_files' => 106, 'peak_memory_bytes' => 16 * 1024 * 1024];
$expect(\PhpGallery\Audit\runtime_plan_ratchet_problems($largeRuntime, $baselines['probe_one']) !== [],
    'Actual included-file growth must fail above the tight ratchet.');
$largeMemory = ['peak_memory_bytes' => 20 * 1024 * 1024 + 1];
$expect(\PhpGallery\Audit\runtime_plan_ratchet_problems($largeMemory, $baselines['probe_one']) !== [],
    'Peak memory growth must fail above the four-MiB allocator tolerance.');
$moduleFanout = array_replace($metrics['probe_one'], ['transitive_module_count' => 7]);
$expect(\PhpGallery\Audit\runtime_plan_ratchet_problems($moduleFanout, $baselines['probe_one']) !== [],
    'Transitive module fan-out must fail beyond the reviewed decomposition allowance.');
$duplicateGrowth = array_replace($metrics['probe_one'], ['direct_duplicate_file_entries' => 1]);
$expect(\PhpGallery\Audit\runtime_plan_ratchet_problems($duplicateGrowth, $baselines['probe_one']) !== [],
    'A route closure must not reintroduce duplicate direct file ownership.');
$changedRoot = array_replace($metrics['probe_one'], ['root_module' => 'unexpected-root']);
$expect(\PhpGallery\Audit\runtime_plan_ratchet_problems($changedRoot, $baselines['probe_one']) !== [],
    'A route root change must require a reviewed baseline update.');
$inventory = \PhpGallery\Audit\runtime_plan_inventory_metrics($validPlan);
$planBaseline = \PhpGallery\Audit\runtime_plan_baseline_inventory($baseline);
$expect($inventory['direct_duplicate_file_entries'] === 0
    && \PhpGallery\Audit\runtime_plan_inventory_problems($inventory, $planBaseline) === [],
    'Plan-wide direct file ownership should report its deduplication against the reviewed legacy baseline.');
$duplicateInventory = array_replace($inventory, ['direct_duplicate_file_entries' => 1]);
$deduplicatedPlanBaseline = array_replace($planBaseline, ['direct_duplicate_file_entries' => 0]);
$expect(\PhpGallery\Audit\runtime_plan_inventory_problems($duplicateInventory, $deduplicatedPlanBaseline) !== [],
    'A composed plan with no duplicate direct ownership must reject even a small duplicate regression.');

$expect($throws(static fn(): array => \PhpGallery\Audit\runtime_plan_baseline_routes(
    ['schema_version' => 1, 'scope' => 'runtime-plan-route-ratchet', 'routes' => [], 'plan' => [
        'module_count' => 1, 'direct_file_entries' => 1, 'unique_direct_file_count' => 1,
        'direct_duplicate_file_entries' => 0,
    ]], ['probe_one'])),
    'A baseline missing registered routes must fail closed.');
$expect($throws(static fn(): array => \PhpGallery\Audit\runtime_plan_baseline_routes(
    ['schema_version' => 1, 'scope' => 'runtime-plan-route-ratchet', 'routes' => ['probe_one' => []]], ['probe_one'])),
    'A baseline with malformed route metrics must fail closed.');
$wrongBaselineIdentity = $baseline;
$wrongBaselineIdentity['routes']['unregistered_probe'] = $wrongBaselineIdentity['routes']['probe_two'];
unset($wrongBaselineIdentity['routes']['probe_two']);
$expect($throws(static fn(): array => \PhpGallery\Audit\runtime_plan_baseline_routes(
    $wrongBaselineIdentity, ['probe_one', 'probe_two'])),
    'A baseline with corrupted probe identities must fail closed.');
$missingBaselineMetric = $baseline;
unset($missingBaselineMetric['routes']['probe_one']['peak_memory_bytes']);
$expect($throws(static fn(): array => \PhpGallery\Audit\runtime_plan_baseline_routes(
    $missingBaselineMetric, ['probe_one', 'probe_two'])),
    'A baseline missing a required route metric must fail closed.');
$badInventoryBaseline = $baseline;
$badInventoryBaseline['plan']['direct_duplicate_file_entries'] = -1;
$expect($throws(static fn(): array => \PhpGallery\Audit\runtime_plan_baseline_inventory($badInventoryBaseline)),
    'A baseline with malformed plan inventory metrics must fail closed.');

$cyclePlan = [
    'schema_version' => 2,
    'route_modules' => ['route_one' => 'cycle-a'],
    'modules' => [
        'cycle-a' => ['depends' => ['cycle-b'], 'files' => ['app/a.php']],
        'cycle-b' => ['depends' => ['cycle-a'], 'files' => ['app/b.php']],
    ],
    'file_order' => ['app/a.php', 'app/b.php'],
];
$expect($throws(static fn(): array => \PhpGallery\Audit\runtime_plan_route_metrics($cyclePlan, ['probe_one' => 'route_one'])),
    'Cyclic runtime module plans must fail closed.');
$missingReference = $validPlan;
$missingReference['modules']['feature-one']['depends'][] = 'missing-module';
$expect($throws(static fn(): array => \PhpGallery\Audit\runtime_plan_route_metrics($missingReference, ['probe_one' => 'route_one'])),
    'Invalid module references must fail closed.');
$expect($throws(static fn(): array => \PhpGallery\Audit\runtime_plan_route_metrics(
    ['schema_version' => 1], ['probe_one' => 'route_one'])),
    'Schema-1 plans must not silently bypass the structural ratchet.');

$root = dirname(__DIR__);
$applicationPlan = require $root . '/app/runtime/modules.php';
$registry = require $root . '/scripts/audit_route_probe_registry.php';
$baselineJson = file_get_contents($root . '/scripts/runtime_plan_baseline.json');
$expect(is_string($baselineJson), 'The tracked runtime plan baseline must be readable.');
$trackedBaseline = json_decode($baselineJson, true, 512, JSON_THROW_ON_ERROR);
$baselineRouteMetrics = \PhpGallery\Audit\runtime_plan_baseline_routes($trackedBaseline, array_keys($registry));
$routeNames = [];
foreach ($registry as $probeId => $definition) {
    $routeNames[$probeId] = $definition['route'];
}
$applicationMetrics = \PhpGallery\Audit\runtime_plan_route_metrics($applicationPlan, $routeNames);
foreach ($applicationMetrics as $probeId => $routeMetrics) {
    $routeProblems = \PhpGallery\Audit\runtime_plan_ratchet_problems($routeMetrics, $baselineRouteMetrics[$probeId]);
    $expect($routeProblems === [], 'The checked-in route plan must pass its structural ratchet: '
        . $probeId . ' ' . implode(' ', $routeProblems));
}
$applicationInventory = \PhpGallery\Audit\runtime_plan_inventory_metrics($applicationPlan);
$trackedInventory = \PhpGallery\Audit\runtime_plan_baseline_inventory($trackedBaseline);
$inventoryProblems = \PhpGallery\Audit\runtime_plan_inventory_problems($applicationInventory, $trackedInventory);
$expect($inventoryProblems === [], 'The checked-in plan must pass its global ownership ratchet: '
    . implode(' ', $inventoryProblems));
$legacyInventory = \PhpGallery\Audit\runtime_plan_baseline_inventory([
    'plan' => $trackedBaseline['pre_refactor_plan'] ?? $trackedBaseline['plan'],
]);
$expect($applicationInventory['direct_duplicate_file_entries'] < $legacyInventory['direct_duplicate_file_entries'],
    'Module composition should materially reduce duplicate direct file ownership.');

fwrite(STDOUT, "PASS runtime plan ratchet metrics and malformed-plan contracts\n");
