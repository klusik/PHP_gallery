<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/runtime_support_constants_test.php
 * Module Type: Regression Test
 * Purpose: Preserve immutable runtime policy during migration to the Core owner.
 * Responsibilities:
 *   - Prove dependency-free loading and absence of service-local aliases.
 *   - Retain exact policy values and inclusive UTC lifecycle behavior.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

use function Gallery\Services\runtime_support_status;
use const Gallery\Core\RUNTIME_SUPPORT_MINIMUM_COMPATIBLE;
use const Gallery\Core\RUNTIME_SUPPORT_MINIMUM_DEPLOYMENT;
use const Gallery\Core\RUNTIME_SUPPORT_PREFERRED_BRANCH;
use const Gallery\Core\RUNTIME_SUPPORT_VERIFIED_ON;
use const Gallery\Core\RUNTIME_SUPPORT_REFERENCE;
use const Gallery\Core\RUNTIME_SUPPORT_SCHEDULE;

/**
 * Refuse an ownership or compatibility regression with a non-sensitive explanation.
 *
 * @param bool $condition Whether the invariant is preserved.
 * @param string $message Fixed assertion description, never host data.
 * @return void
 */
function runtime_constants_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$before = get_included_files();
require_once $root . '/app/policy_constants.php';
$added = array_values(array_diff(get_included_files(), $before));
runtime_constants_assert(count($added) === 1 && realpath($added[0]) === realpath($root . '/app/policy_constants.php'),
    'Core policy loading must not load bootstrap, configuration, services or persistence.');

$expected = [
    'RUNTIME_SUPPORT_MINIMUM_COMPATIBLE' => '8.1',
    'RUNTIME_SUPPORT_MINIMUM_DEPLOYMENT' => '8.3',
    'RUNTIME_SUPPORT_PREFERRED_BRANCH' => '8.5',
    'RUNTIME_SUPPORT_VERIFIED_ON' => '2026-09-20',
    'RUNTIME_SUPPORT_REFERENCE' => 'https://www.php.net/supported-versions.php',
    'RUNTIME_SUPPORT_SCHEDULE' => [
        '8.1' => ['active_until' => null, 'security_until' => '2025-12-31'],
        '8.2' => ['active_until' => '2024-12-31', 'security_until' => '2026-12-31'],
        '8.3' => ['active_until' => '2025-12-31', 'security_until' => '2027-12-31'],
        '8.4' => ['active_until' => '2026-12-31', 'security_until' => '2028-12-31'],
        '8.5' => ['active_until' => '2027-12-31', 'security_until' => '2029-12-31'],
    ],
];
$actual = [
    'RUNTIME_SUPPORT_MINIMUM_COMPATIBLE' => RUNTIME_SUPPORT_MINIMUM_COMPATIBLE,
    'RUNTIME_SUPPORT_MINIMUM_DEPLOYMENT' => RUNTIME_SUPPORT_MINIMUM_DEPLOYMENT,
    'RUNTIME_SUPPORT_PREFERRED_BRANCH' => RUNTIME_SUPPORT_PREFERRED_BRANCH,
    'RUNTIME_SUPPORT_VERIFIED_ON' => RUNTIME_SUPPORT_VERIFIED_ON,
    'RUNTIME_SUPPORT_REFERENCE' => RUNTIME_SUPPORT_REFERENCE,
    'RUNTIME_SUPPORT_SCHEDULE' => RUNTIME_SUPPORT_SCHEDULE,
];
runtime_constants_assert($actual === $expected, 'All six definitions must retain their exact values and scalar/array types.');

$before = get_included_files();
require_once $root . '/app/services/runtime_support.php';
$added = array_values(array_diff(get_included_files(), $before));
runtime_constants_assert(count($added) === 1 && realpath($added[0]) === realpath($root . '/app/services/runtime_support.php'),
    'Standalone runtime policy must only reuse the already loaded Core owner.');
foreach (array_keys($expected) as $name) {
    runtime_constants_assert(defined('Gallery\\Core\\' . $name), 'The immutable runtime definition must live in Core.');
    runtime_constants_assert(!defined('Gallery\\Services\\' . $name), 'No duplicate service-local runtime constant or alias may remain.');
}

$cases = [
    [80134, '2026-09-20T12:00:00Z', 'end_of_life', false],
    [80200, '2026-09-20T12:00:00Z', 'security_only', false],
    [80330, '2026-09-20T12:00:00Z', 'security_only', true],
    [80500, '2026-09-19T23:59:59Z', 'unknown', false],
    [80500, '2026-09-20T00:00:00Z', 'active', true],
    [80400, '2026-12-31T23:59:59Z', 'active', true],
    [80400, '2027-01-01T00:00:00Z', 'security_only', true],
    [80300, '2027-12-31T23:59:59Z', 'security_only', true],
    [80300, '2028-01-01T00:00:00Z', 'end_of_life', false],
    [80300, '2028-01-01T01:00:00+02:00', 'security_only', true],
    [80500, '2029-12-31T23:59:59Z', 'security_only', true],
    [80500, '2030-01-01T00:00:00Z', 'end_of_life', false],
    [80600, '2026-09-20T12:00:00Z', 'unknown', false],
];
foreach ($cases as [$version, $instant, $state, $recommended]) {
    $status = runtime_support_status($version, new DateTimeImmutable($instant));
    runtime_constants_assert($status['state'] === $state
        && $status['deployment_recommended'] === $recommended
        && $status['action_required'] === !$recommended
        && $status['compatible'], 'Inclusive UTC lifecycle and advisory-only compatibility must remain unchanged.');
    runtime_constants_assert($status['minimum_compatible'] === '8.1'
        && $status['minimum_deployment'] === '8.3'
        && $status['preferred_branch'] === '8.5'
        && $status['policy_verified_on'] === '2026-09-20'
        && $status['reference_url'] === $expected['RUNTIME_SUPPORT_REFERENCE'],
        'The public bounded model must retain the immutable reviewed policy.');
}
echo "PASS runtime constants: six Core definitions, standalone dependencies, exact values and 13 UTC lifecycle cases\n";

