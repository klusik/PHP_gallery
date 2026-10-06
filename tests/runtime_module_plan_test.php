<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/runtime_module_plan_test.php
 * Module Type: Regression Test
 * Purpose: Protect generated route plans and verify each module in an isolated child.
 * Responsibilities: Check plan freshness, dynamic policies and clean loading of every logical module.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Tests\RuntimeModulePlan;

require_once dirname(__DIR__) . '/scripts/audit_lib.php';
require_once dirname(__DIR__) . '/scripts/generate_runtime_modules.php';

use function Gallery\Tools\RuntimeModules\compile_runtime_modules;
use function Gallery\Tools\RuntimeModules\render_runtime_modules;
use function PhpGallery\Audit\run_process_pool;

/**
 * Assert one runtime module-plan contract and stop with a direct diagnostic.
 *
 * @param bool $condition Condition expected to hold.
 * @param string $message Failure explanation.
 * @return void Writes a failure and exits when the condition is false.
 */
function assert_contract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$target = $root . '/app/runtime/modules.php';
$plans = compile_runtime_modules($root);
$rendered = render_runtime_modules($plans);
$checkedIn = file_get_contents($target);
assert_contract(is_string($checkedIn) && hash_equals(hash('sha256', $rendered), hash('sha256', $checkedIn)),
    'Checked-in runtime module plans must byte-match compiler output.');
assert_contract(($plans['schema_version'] ?? null) === 2 && is_array($plans['file_order'] ?? null)
    && array_is_list($plans['file_order']), 'Runtime module plan must use the validated composed schema.');
assert_contract(count($plans['file_order']) === count(array_unique($plans['file_order'])),
    'Global runtime file order must contain unique source paths.');

$routeModules = $plans['route_modules'];
$requestMaintenancePaths = $plans['modules']['request-maintenance']['files'];
assert_contract(in_array('app/services/updates_job_lookup.php', $requestMaintenancePaths, true),
    'Request maintenance must retain active-job lookup before eligibility gates.');
assert_contract(!in_array('app/services/updates_jobs.php', $requestMaintenancePaths, true)
    && !in_array('app/services/updates_install.php', $requestMaintenancePaths, true),
    'Ineligible requests must not load updater worker or installation modules.');
foreach ($plans['modules'] as $modulePlan) {
    assert_contract(!in_array('app/diagnostics/admin_test_run_early.php', $modulePlan['files'], true),
        'Optional early diagnostics remain owned by the front-controller transport.');
}
$modules = array_keys($plans['modules']);
sort($modules, SORT_STRING);
require_once $root . '/app/bootstrap.php';
assert_contract(count($routeModules) === count(\Gallery\Core\cms_route_handlers()) + 1,
    'Every canonical route plus the dedicated not-found fallback must have a module plan.');
assert_contract(count($modules) > 0, 'At least one route-owned logical module must be compiled.');

$fixture = $root . '/tests/support/runtime_module_fixture.php';
$jobs = [];
$jobMap = [];
foreach ($modules as $module) {
    foreach (['composed', 'legacy'] as $mode) {
        $jobs[] = ['command' => [PHP_BINARY, $fixture, $module, $mode], 'timeout' => 180];
        $jobMap[] = ['module' => $module, 'mode' => $mode];
    }
}
$results = run_process_pool($jobs, $root, 4);
$preparedRoutes = [];
foreach ($results as $index => $result) {
    $module = $jobMap[$index]['module'];
    $mode = $jobMap[$index]['mode'];
    assert_contract(!$result['timed_out'], 'Isolated module preparation timed out: ' . $module);
    assert_contract($result['exit_code'] === 0, 'Isolated module preparation failed for ' . $module . ': ' . trim($result['stdout'] . ' ' . $result['stderr']));
    $decoded = json_decode($result['stdout'], true);
    assert_contract(is_array($decoded) && ($decoded['ok'] ?? false) === true, 'Fixture output was invalid for ' . $module . '.');
    $observed = $decoded['result'] ?? [];
    assert_contract(($observed['module'] ?? null) === $module, 'Fixture prepared the wrong module: ' . $module . '.');
    assert_contract(($observed['mode'] ?? null) === $mode, 'Fixture ran the wrong loader mode for ' . $module . '.');
    assert_contract(($observed['side_effects'] ?? null) === ['output' => false, 'headers' => false, 'session' => false, 'umbrellas' => false],
        'Bootstrap or route preparation caused an include-time side effect for ' . $module . '.');
    foreach (($observed['routes'] ?? []) as $route) {
        assert_contract(isset($routeModules[$route]) && $routeModules[$route] === $module, 'Fixture returned a route outside its module: ' . $route . '.');
        if ($mode === 'composed') {
            assert_contract(!isset($preparedRoutes[$route]), 'Canonical route was prepared more than once: ' . $route . '.');
            $preparedRoutes[$route] = true;
        }
    }
    $pairIndex = $index % 2 === 0 ? $index + 1 : $index - 1;
    $otherDecoded = json_decode($results[$pairIndex]['stdout'], true);
    $other = is_array($otherDecoded) ? ($otherDecoded['result'] ?? []) : [];
    $composed = $mode === 'composed' ? $observed : $other;
    $legacy = $mode === 'legacy' ? $observed : $other;
    assert_contract(($composed['included_app_order'] ?? null) === ($legacy['included_app_order'] ?? null),
        'Composed loading must preserve actual PHP include order from the flattened legacy plan: ' . $module . '.');
    assert_contract(($composed['routes'] ?? null) === ($legacy['routes'] ?? null),
        'Composed and flattened loaders must prepare the same routes: ' . $module . '.');
    if ($mode === 'composed') {
        $expectedClosure = \Gallery\Tools\RuntimeModules\runtime_module_transitive_closure($plans, $module);
        assert_contract(($observed['loaded_modules'] ?? null) === $expectedClosure['modules'],
            'Composed module diagnostics must retain dependency-first module history: ' . $module . '.');
    }
}
assert_contract(count($preparedRoutes) === count($routeModules), 'Prepared route count does not cover the complete canonical route map.');
foreach ($routeModules as $route => $_module) {
    assert_contract(isset($preparedRoutes[$route]), 'Canonical route handler was not prepared: ' . $route . '.');
}

$unknownDynamic = \Gallery\Tools\RuntimeDynamicDependencies\runtime_dynamic_dependency_policy_drift([
    ['caller' => 'Gallery\\Services\\new_unreviewed_callback', 'kind' => 'variable_callable',
        'expression' => '$unreviewed()'],
]);
assert_contract(count($unknownDynamic['unexpected']) === 1, 'A new unresolved callback must require explicit review.');

fwrite(STDOUT, 'PASS: runtime module plan byte freshness and ' . count($preparedRoutes)
    . ' route handlers across ' . count($modules) . ' modules / ' . (count($modules) * 2) . " isolated comparison children\n");
