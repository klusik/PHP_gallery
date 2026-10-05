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
foreach ($modules as $module) {
    $jobs[] = ['command' => [PHP_BINARY, $fixture, $module], 'timeout' => 180];
}
$results = run_process_pool($jobs, $root, 4);
$preparedRoutes = [];
foreach ($results as $index => $result) {
    $module = $modules[$index];
    assert_contract(!$result['timed_out'], 'Isolated module preparation timed out: ' . $module);
    assert_contract($result['exit_code'] === 0, 'Isolated module preparation failed for ' . $module . ': ' . trim($result['stdout'] . ' ' . $result['stderr']));
    $decoded = json_decode($result['stdout'], true);
    assert_contract(is_array($decoded) && ($decoded['ok'] ?? false) === true, 'Fixture output was invalid for ' . $module . '.');
    $observed = $decoded['result'] ?? [];
    assert_contract(($observed['module'] ?? null) === $module, 'Fixture prepared the wrong module: ' . $module . '.');
    assert_contract(($observed['side_effects'] ?? null) === ['output' => false, 'headers' => false, 'session' => false, 'umbrellas' => false],
        'Bootstrap or route preparation caused an include-time side effect for ' . $module . '.');
    foreach (($observed['routes'] ?? []) as $route) {
        assert_contract(isset($routeModules[$route]) && $routeModules[$route] === $module, 'Fixture returned a route outside its module: ' . $route . '.');
        assert_contract(!isset($preparedRoutes[$route]), 'Canonical route was prepared more than once: ' . $route . '.');
        $preparedRoutes[$route] = true;
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
    . ' route handlers across ' . count($modules) . " isolated module processes\n");
