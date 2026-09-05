<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/audit_runner_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects the central audit runner's deterministic registry and normalized helper contracts.
 *
 * Responsibilities:
 *   - Keep every standalone Node regression script explicitly registered
 *   - Preserve quick/full/release profile composition
 *   - Verify command-line parsing and SKIP classification helpers
 *   - Prevent accidental browser or slow-test inclusion in the quick profile
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - This test intentionally validates registry coverage without executing the child suites recursively.
 *
 * Last Updated:
 *   2026-09-05
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/audit_lib.php';
$registry = require dirname(__DIR__) . '/scripts/audit_registry.php';

use function PhpGallery\Audit\output_is_skip;
use function PhpGallery\Audit\parse_python_unittest_summary;
use function PhpGallery\Audit\parse_options;
use function PhpGallery\Audit\python_command_is_usable;
use function PhpGallery\Audit\relative_path;
use function PhpGallery\Audit\resolve_python_command_from_candidates;

/**
 * Throw when an audit-runner contract is not satisfied.
 *
 * @param bool $condition Assertion condition.
 * @param string $message Failure message.
 * @return void
 */
function audit_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$actualNodeFiles = glob(__DIR__ . '/*_test.mjs') ?: [];
$actualNodeNames = array_map('basename', $actualNodeFiles);
sort($actualNodeNames, SORT_STRING);
$registeredNodeNames = array_keys($registry['node_tests'] ?? []);
sort($registeredNodeNames, SORT_STRING);
audit_test_assert($actualNodeNames === $registeredNodeNames, 'Every tests/*_test.mjs file must have exactly one explicit audit registry entry.');

$profiles = $registry['profiles'] ?? [];
audit_test_assert(isset($profiles['quick'], $profiles['full'], $profiles['release']), 'Audit registry must retain quick, full, and release profiles.');
audit_test_assert(in_array('php-regression', $profiles['quick'], true), 'Quick profile must retain the complete PHP regression suite.');
audit_test_assert(in_array('node-fast', $profiles['quick'], true), 'Quick profile must use the fast Node suite.');
audit_test_assert(in_array('node-full', $profiles['full'], true), 'Full profile must include slow deterministic Node coverage.');
audit_test_assert(in_array('browser-map', $profiles['release'], true), 'Release profile must include browser integration coverage.');
audit_test_assert(in_array('manifest', $profiles['release'], true), 'Release profile must verify the core manifest.');

audit_test_assert(!empty($registry['node_tests']['gallery_download_zip64_test.mjs']['slow']), 'ZIP64 boundary coverage must stay classified as slow.');
audit_test_assert(!empty($registry['node_tests']['lightbox_map_browser_test.mjs']['browser']), 'The real Chromium lightbox test must stay classified as browser integration.');
audit_test_assert(($registry['node_tests']['gallery_download_zip_test.mjs']['temporary_output'] ?? '') !== '', 'ZIP writer regression must receive a temporary output path.');

$options = parse_options(['audit.php', '--profile', 'quick', '--changed', '--report=cache/custom.md']);
audit_test_assert($options['profile'] === 'quick', 'Profile parser must accept separated option values.');
audit_test_assert($options['changed'] === true, 'Changed-file flag must be retained.');
audit_test_assert($options['report'] === 'cache/custom.md', 'Report parser must accept inline option values.');

audit_test_assert(output_is_skip("SKIP browser unavailable\n"), 'SKIP output at the first line must be recognized.');
audit_test_assert(output_is_skip("setup\nSKIP: pdo_mysql unavailable\n"), 'SKIP output after setup text must be recognized.');
audit_test_assert(!output_is_skip("All tests passed.\n"), 'Ordinary successful output must not be classified as SKIP.');

$unittestFailure = parse_python_unittest_summary("Ran 36 tests in 0.320s\n\nFAILED (failures=1, errors=2, skipped=3)\n");
audit_test_assert($unittestFailure['total'] === 36, 'Python unittest parser must retain the reported total.');
audit_test_assert($unittestFailure['passed'] === 30, 'Python unittest parser must derive passed tests from failure/error/skip counters.');
audit_test_assert($unittestFailure['failed'] === 1, 'Python unittest parser must retain failure counts.');
audit_test_assert($unittestFailure['errors'] === 2, 'Python unittest parser must retain error counts.');
audit_test_assert($unittestFailure['skipped'] === 3, 'Python unittest parser must retain skip counts.');

$unittestSuccess = parse_python_unittest_summary("Ran 36 tests in 0.200s\n\nOK\n");
audit_test_assert($unittestSuccess['passed'] === 36, 'Successful Python unittest output must classify all non-skipped tests as passed.');

$python3Runner = static fn(array $command): array => [
    'exit_code' => 0,
    'stdout' => 'Python 3.14.4',
    'stderr' => '',
    'timed_out' => false,
    'duration' => 0.001,
];
$python2Runner = static fn(array $command): array => [
    'exit_code' => 0,
    'stdout' => 'Python 2.7.18',
    'stderr' => '',
    'timed_out' => false,
    'duration' => 0.001,
];
audit_test_assert(python_command_is_usable(['python'], $python3Runner), 'Python 3 version probes must be accepted.');
audit_test_assert(!python_command_is_usable(['python'], $python2Runner), 'Python 2 version probes must not satisfy WinApp coverage.');

$aliasLikePython = resolve_python_command_from_candidates(
    [['python3'], ['python'], ['py', '-3']],
    static fn(string $name): ?string => null,
    static fn(array $command): bool => $command === ['python']
);
audit_test_assert($aliasLikePython === ['python'], 'Runnable PATH commands must remain eligible when filesystem lookup cannot resolve a Windows-style execution alias.');

$launcherPython = resolve_python_command_from_candidates(
    [['python3'], ['python'], ['py', '-3']],
    static fn(string $name): ?string => null,
    static fn(array $command): bool => $command === ['py', '-3']
);
audit_test_assert($launcherPython === ['py', '-3'], 'The Windows py -3 launcher must remain a supported Python fallback.');

$root = dirname(__DIR__);
audit_test_assert(relative_path($root . '/tests/audit_runner_test.php', $root) === 'tests/audit_runner_test.php', 'Repository-relative path normalization must remain stable.');

echo "Central audit runner contracts passed.\n";
