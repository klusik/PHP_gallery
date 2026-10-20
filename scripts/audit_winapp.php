<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/audit_winapp.php
 * Module Type: Audit Evidence Validator
 * Purpose: Validate structured outcomes from the central WinApp unittest discovery.
 * Responsibilities:
 *   - Require complete per-test and fixture evidence before reporting WinApp coverage.
 *   - Reconcile reported outcomes with their exact summary counts and native success flag.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace PhpGallery\Audit;

/**
 * Validate a complete version-one WinApp unittest evidence document.
 *
 * The report records unittest callbacks; aggregate counts alone never establish
 * individual test coverage. Invalid or incomplete documents return no trusted
 * per-test counts and include a stable reason for the audit task.
 *
 * @param array<string,mixed> $report Untrusted decoded JSON object; schema v1 requires exactly `schema_version:int`, `successful:bool`, `runtime:{python_version:string,python_implementation:string,platform:string,executable:string,suite_settings:{start_directory:'winapp/tests',pattern:'test_*.py',runner:'unittest.TextTestRunner',verbosity:1}}`, `counts:{total:int,passed:int,failed:int,errors:int,skipped:int,expected_failures:int,unexpected_successes:int,subtest_failures:int,subtest_errors:int,subtest_skips:int,fixture_errors:int,fixture_skips:int}`, `tests:list<{id:string,status:'PASS'|'FAIL'|'ERROR'|'SKIP'|'EXPECTED_FAILURE'|'UNEXPECTED_SUCCESS',duration_seconds:int|float,reason:?string,subtests:list<{id:string,status:'FAIL'|'ERROR'|'SKIP',reason:string}>}>`, `fixture_errors:list<{phase:'setUpClass'|'tearDownClass'|'setUpModule'|'tearDownModule',id:string,reason:string}>`, and `fixture_skips` with the same fixture-entry shape.
 * @return array{valid:bool,reason:?string,successful:?bool,runtime:array{python_version:string,python_implementation:string,platform:string,executable:string,suite_settings:array{start_directory:string,pattern:string,runner:string,verbosity:int}}|null,counts:array{total:int,passed:int,failed:int,errors:int,skipped:int,expected_failures:int,unexpected_successes:int,subtest_failures:int,subtest_errors:int,subtest_skips:int,fixture_errors:int,fixture_skips:int},tests:list<array{id:string,status:string,reason:?string,duration_seconds:float,subtests:list<array{id:string,status:string,reason:string}>}>,fixture_errors:list<array{phase:string,id:string,reason:string}>,fixture_skips:list<array{phase:string,id:string,reason:string}>,gaps:list<string>} Validated results, or empty counts and details when validation fails.
 */
function audit_validate_winapp_report(array $report): array
{
    $emptyCounts = [
        'total' => 0,
        'passed' => 0,
        'failed' => 0,
        'errors' => 0,
        'skipped' => 0,
        'expected_failures' => 0,
        'unexpected_successes' => 0,
        'subtest_failures' => 0,
        'subtest_errors' => 0,
        'subtest_skips' => 0,
        'fixture_errors' => 0,
        'fixture_skips' => 0,
    ];
    $invalid = static fn(string $reason): array => [
        'valid' => false,
        'reason' => $reason,
        'successful' => is_bool($report['successful'] ?? null)
            ? $report['successful']
            : null,
        'runtime' => null,
        'counts' => $emptyCounts,
        'tests' => [],
        'fixture_errors' => [],
        'fixture_skips' => [],
        'gaps' => [],
    ];
    $expectedTopKeys = [
        'schema_version',
        'successful',
        'runtime',
        'counts',
        'tests',
        'fixture_errors',
        'fixture_skips',
    ];
    $actualTopKeys = array_keys($report);
    sort($expectedTopKeys, SORT_STRING);
    sort($actualTopKeys, SORT_STRING);
    if (
        $actualTopKeys !== $expectedTopKeys
        || ($report['schema_version'] ?? null) !== 1
    ) {
        return $invalid('missing-or-unsupported-report-schema');
    }
    if (!is_bool($report['successful'])) {
        return $invalid('invalid-successful-flag');
    }

    $runtime = $report['runtime'];
    $runtimeKeys = [
        'python_version',
        'python_implementation',
        'platform',
        'executable',
        'suite_settings',
    ];
    if (!is_array($runtime) || array_is_list($runtime)) {
        return $invalid('invalid-runtime-metadata');
    }
    $actualRuntimeKeys = array_keys($runtime);
    sort($runtimeKeys, SORT_STRING);
    sort($actualRuntimeKeys, SORT_STRING);
    if ($actualRuntimeKeys !== $runtimeKeys) {
        return $invalid('incomplete-runtime-metadata');
    }
    foreach ([
        'python_version',
        'python_implementation',
        'platform',
        'executable',
    ] as $key) {
        if (
            !is_string($runtime[$key])
            || trim($runtime[$key]) === ''
            || strlen($runtime[$key]) > 4096
        ) {
            return $invalid('invalid-runtime-metadata');
        }
    }
    $suiteSettings = $runtime['suite_settings'];
    $expectedSuiteSettings = [
        'start_directory' => 'winapp/tests',
        'pattern' => 'test_*.py',
        'runner' => 'unittest.TextTestRunner',
        'verbosity' => 1,
    ];
    $suiteSettingKeys = array_keys($expectedSuiteSettings);
    $actualSuiteSettingKeys = is_array($suiteSettings)
        ? array_keys($suiteSettings)
        : [];
    sort($suiteSettingKeys, SORT_STRING);
    sort($actualSuiteSettingKeys, SORT_STRING);
    if (
        !is_array($suiteSettings)
        || array_is_list($suiteSettings)
        || $actualSuiteSettingKeys !== $suiteSettingKeys
    ) {
        return $invalid('unexpected-unittest-suite-settings');
    }
    foreach ($expectedSuiteSettings as $key => $expectedValue) {
        if ($suiteSettings[$key] !== $expectedValue) {
            return $invalid('unexpected-unittest-suite-settings');
        }
    }

    $countKeys = array_keys($emptyCounts);
    $reportedCounts = $report['counts'];
    if (!is_array($reportedCounts) || array_is_list($reportedCounts)) {
        return $invalid('invalid-counts-object');
    }
    $actualCountKeys = array_keys($reportedCounts);
    sort($countKeys, SORT_STRING);
    sort($actualCountKeys, SORT_STRING);
    if ($actualCountKeys !== $countKeys) {
        return $invalid('incomplete-counts-object');
    }
    foreach ($countKeys as $key) {
        if (!is_int($reportedCounts[$key]) || $reportedCounts[$key] < 0) {
            return $invalid('invalid-count-value');
        }
    }

    $rawTests = $report['tests'];
    if (!is_array($rawTests) || !array_is_list($rawTests)) {
        return $invalid('missing-or-empty-test-results');
    }
    $rawFixtureErrors = $report['fixture_errors'];
    if (!is_array($rawFixtureErrors) || !array_is_list($rawFixtureErrors)) {
        return $invalid('invalid-fixture-errors');
    }
    $rawFixtureSkips = $report['fixture_skips'];
    if (!is_array($rawFixtureSkips) || !array_is_list($rawFixtureSkips)) {
        return $invalid('invalid-fixture-skips');
    }
    if (
        $rawTests === []
        && $rawFixtureErrors === []
        && $rawFixtureSkips === []
    ) {
        return $invalid('missing-or-empty-test-results');
    }

    $allowedStatuses = [
        'PASS',
        'FAIL',
        'ERROR',
        'SKIP',
        'EXPECTED_FAILURE',
        'UNEXPECTED_SUCCESS',
    ];
    $computed = $emptyCounts;
    $tests = [];
    $gaps = [];
    $seenTestIds = [];
    foreach ($rawTests as $test) {
        $expectedTestKeys = [
            'id',
            'status',
            'duration_seconds',
            'reason',
            'subtests',
        ];
        if (!is_array($test) || array_is_list($test)) {
            return $invalid('invalid-test-result');
        }
        $actualTestKeys = array_keys($test);
        sort($expectedTestKeys, SORT_STRING);
        sort($actualTestKeys, SORT_STRING);
        if ($actualTestKeys !== $expectedTestKeys) {
            return $invalid('incomplete-test-result');
        }
        $id = $test['id'];
        $status = $test['status'];
        $duration = $test['duration_seconds'];
        $reason = $test['reason'];
        $subtests = $test['subtests'];
        if (
            !is_string($id)
            || trim($id) === ''
            || strlen($id) > 4096
            || preg_match('/[\x00-\x1F\x7F]/', $id) === 1
            || isset($seenTestIds[$id])
        ) {
            return $invalid('invalid-or-duplicate-test-id');
        }
        if (!is_string($status) || !in_array($status, $allowedStatuses, true)) {
            return $invalid('unknown-test-status');
        }
        if (
            (
                !is_int($duration)
                && !is_float($duration)
            )
            || !is_finite((float) $duration)
            || $duration < 0
        ) {
            return $invalid('invalid-test-duration');
        }
        if (
            $reason !== null
            && (
                !is_string($reason)
                || strlen($reason) > 16384
            )
        ) {
            return $invalid('invalid-test-reason');
        }
        if ($status === 'PASS' && $reason !== null) {
            return $invalid('passing-test-has-reason');
        }
        if (
            in_array(
                $status,
                ['FAIL', 'ERROR', 'SKIP', 'EXPECTED_FAILURE'],
                true,
            )
            && (
                !is_string($reason)
                || trim($reason) === ''
            )
        ) {
            return $invalid('test-outcome-missing-reason');
        }
        if (!is_array($subtests) || !array_is_list($subtests)) {
            return $invalid('invalid-subtest-list');
        }
        $seenSubtestIds = [];
        $normalizedSubtests = [];
        $hasFailingSubtest = false;
        $hasSkippedSubtest = false;
        foreach ($subtests as $subtest) {
            $expectedSubtestKeys = ['id', 'status', 'reason'];
            if (!is_array($subtest) || array_is_list($subtest)) {
                return $invalid('invalid-subtest-result');
            }
            $actualSubtestKeys = array_keys($subtest);
            sort($expectedSubtestKeys, SORT_STRING);
            sort($actualSubtestKeys, SORT_STRING);
            if ($actualSubtestKeys !== $expectedSubtestKeys) {
                return $invalid('incomplete-subtest-result');
            }
            $subtestId = $subtest['id'];
            $subtestStatus = $subtest['status'];
            $subtestReason = $subtest['reason'];
            if (
                !is_string($subtestId)
                || trim($subtestId) === ''
                || strlen($subtestId) > 4096
                || preg_match('/[\x00-\x1F\x7F]/', $subtestId) === 1
                || isset($seenSubtestIds[$subtestId])
            ) {
                return $invalid('invalid-or-duplicate-subtest-id');
            }
            if (
                !is_string($subtestStatus)
                || !in_array($subtestStatus, ['FAIL', 'ERROR', 'SKIP'], true)
            ) {
                return $invalid('unknown-subtest-status');
            }
            if (
                !is_string($subtestReason)
                || trim($subtestReason) === ''
                || strlen($subtestReason) > 16384
            ) {
                return $invalid('invalid-subtest-reason');
            }
            $seenSubtestIds[$subtestId] = true;
            $normalizedSubtests[] = [
                'id' => $subtestId,
                'status' => $subtestStatus,
                'reason' => $subtestReason,
            ];
            $subtestCounter = match ($subtestStatus) {
                'FAIL' => 'subtest_failures',
                'ERROR' => 'subtest_errors',
                'SKIP' => 'subtest_skips',
            };
            $computed[$subtestCounter]++;
            if (in_array($subtestStatus, ['FAIL', 'ERROR'], true)) {
                $hasFailingSubtest = true;
                $gaps[] =
                    $id
                    . ' subtest '
                    . $subtestId
                    . ' '
                    . $subtestStatus
                    . ': '
                    . $subtestReason;
            } elseif ($subtestStatus === 'SKIP') {
                $hasSkippedSubtest = true;
                $gaps[] =
                    $id . ' subtest ' . $subtestId . ' SKIP: ' . $subtestReason;
            }
        }
        if (
            (
                $hasFailingSubtest
                && !in_array($status, ['FAIL', 'ERROR'], true)
            )
            || (
                $hasSkippedSubtest
                && $status === 'PASS'
            )
        ) {
            return $invalid('parent-outcome-inconsistent-with-subtest');
        }
        $seenTestIds[$id] = true;
        $computed['total']++;
        $counter = match ($status) {
            'PASS' => 'passed',
            'FAIL' => 'failed',
            'ERROR' => 'errors',
            'SKIP' => 'skipped',
            'EXPECTED_FAILURE' => 'expected_failures',
            'UNEXPECTED_SUCCESS' => 'unexpected_successes',
        };
        $computed[$counter]++;
        if ($status === 'SKIP') {
            $gaps[] = $id . ' SKIP: ' . $reason;
        } elseif ($status === 'EXPECTED_FAILURE') {
            $gaps[] = $id . ' EXPECTED_FAILURE: ' . $reason;
        } elseif ($status === 'UNEXPECTED_SUCCESS') {
            $gaps[] = $id . ' UNEXPECTED_SUCCESS';
        } elseif (in_array($status, ['FAIL', 'ERROR'], true)) {
            $gaps[] = $id . ' ' . $status . ': ' . $reason;
        }
        $tests[] = [
            'id' => $id,
            'status' => $status,
            'reason' => $reason,
            'duration_seconds' => (float) $duration,
            'subtests' => $normalizedSubtests,
        ];
    }

    $fixtureErrors = [];
    $fixtureSkips = [];
    $seenFixtureIds = [];
    $allowedFixturePhases = [
        'setUpClass',
        'tearDownClass',
        'setUpModule',
        'tearDownModule',
    ];
    foreach ($rawFixtureErrors as $fixtureError) {
        $expectedFixtureKeys = ['phase', 'id', 'reason'];
        if (!is_array($fixtureError) || array_is_list($fixtureError)) {
            return $invalid('invalid-fixture-error');
        }
        $actualFixtureKeys = array_keys($fixtureError);
        sort($expectedFixtureKeys, SORT_STRING);
        sort($actualFixtureKeys, SORT_STRING);
        if (
            $actualFixtureKeys !== $expectedFixtureKeys
            || !is_string($fixtureError['phase'])
            || trim($fixtureError['phase']) === ''
            || !in_array($fixtureError['phase'], $allowedFixturePhases, true)
            || strlen($fixtureError['phase']) > 256
            || !is_string($fixtureError['id'])
            || trim($fixtureError['id']) === ''
            || strlen($fixtureError['id']) > 4096
            || !is_string($fixtureError['reason'])
            || trim($fixtureError['reason']) === ''
            || strlen($fixtureError['reason']) > 16384
        ) {
            return $invalid('invalid-fixture-error');
        }
        $fixtureIdentity = $fixtureError['id'];
        if (isset($seenFixtureIds[$fixtureIdentity])) {
            return $invalid('duplicate-fixture-identity');
        }
        $seenFixtureIds[$fixtureIdentity] = true;
        $fixtureErrors[] = [
            'phase' => $fixtureError['phase'],
            'id' => $fixtureError['id'],
            'reason' => $fixtureError['reason'],
        ];
        $gaps[] =
            $fixtureError['id']
            . ' fixture '
            . $fixtureError['phase']
            . ': '
            . $fixtureError['reason'];
    }
    foreach ($rawFixtureSkips as $fixtureSkip) {
        $expectedFixtureKeys = ['phase', 'id', 'reason'];
        if (!is_array($fixtureSkip) || array_is_list($fixtureSkip)) {
            return $invalid('invalid-fixture-skip');
        }
        $actualFixtureKeys = array_keys($fixtureSkip);
        sort($expectedFixtureKeys, SORT_STRING);
        sort($actualFixtureKeys, SORT_STRING);
        if (
            $actualFixtureKeys !== $expectedFixtureKeys
            || !is_string($fixtureSkip['phase'])
            || trim($fixtureSkip['phase']) === ''
            || !in_array($fixtureSkip['phase'], $allowedFixturePhases, true)
            || strlen($fixtureSkip['phase']) > 256
            || !is_string($fixtureSkip['id'])
            || trim($fixtureSkip['id']) === ''
            || strlen($fixtureSkip['id']) > 4096
            || !is_string($fixtureSkip['reason'])
            || trim($fixtureSkip['reason']) === ''
            || strlen($fixtureSkip['reason']) > 16384
        ) {
            return $invalid('invalid-fixture-skip');
        }
        $fixtureIdentity = $fixtureSkip['id'];
        if (isset($seenFixtureIds[$fixtureIdentity])) {
            return $invalid('duplicate-fixture-identity');
        }
        $seenFixtureIds[$fixtureIdentity] = true;
        $fixtureSkips[] = [
            'phase' => $fixtureSkip['phase'],
            'id' => $fixtureSkip['id'],
            'reason' => $fixtureSkip['reason'],
        ];
        $gaps[] =
            $fixtureSkip['id']
            . ' fixture '
            . $fixtureSkip['phase']
            . ' SKIP: '
            . $fixtureSkip['reason'];
    }
    $computed['fixture_errors'] = count($fixtureErrors);
    $computed['errors'] += count($fixtureErrors);
    $computed['fixture_skips'] = count($fixtureSkips);
    foreach ($countKeys as $key) {
        if ($computed[$key] !== $reportedCounts[$key]) {
            return $invalid('reported-counts-do-not-match-outcomes');
        }
    }

    $hasFailure =
        $computed['failed'] > 0
        || $computed['errors'] > 0
        || $computed['unexpected_successes'] > 0
        || $computed['subtest_failures'] > 0
        || $computed['subtest_errors'] > 0
        || $computed['fixture_errors'] > 0;
    if ($report['successful'] === $hasFailure) {
        return $invalid('successful-flag-disagrees-with-outcomes');
    }

    return [
        'valid' => true,
        'reason' => null,
        'successful' => $report['successful'],
        'runtime' => [
            'python_version' => $runtime['python_version'],
            'python_implementation' => $runtime['python_implementation'],
            'platform' => $runtime['platform'],
            'executable' => $runtime['executable'],
            'suite_settings' => $expectedSuiteSettings,
        ],
        'counts' => $computed,
        'tests' => $tests,
        'fixture_errors' => $fixtureErrors,
        'fixture_skips' => $fixtureSkips,
        'gaps' => $gaps,
    ];
}

/**
 * Evaluate the adapter's saved JSON together with its real process outcome.
 *
 * A zero process exit is insufficient without a complete report, and a report
 * cannot erase a timeout or a nonzero process result. Missing or malformed
 * JSON on an otherwise successful process is blocked evidence.
 *
 * @param string|null $contents Exact saved adapter report bytes, or null when the output file is missing or unreadable.
 * @param array{exit_code:?int,timed_out:bool,duration_seconds:int|float} $process Bounded process result returned by the central audit runner.
 * @return array{status:string,valid:bool,reason:?string,successful:?bool,runtime:array{python_version:string,python_implementation:string,platform:string,executable:string,suite_settings:array{start_directory:string,pattern:string,runner:string,verbosity:int}}|null,counts:array{total:int,passed:int,failed:int,errors:int,skipped:int,expected_failures:int,unexpected_successes:int,subtest_failures:int,subtest_errors:int,subtest_skips:int,fixture_errors:int,fixture_skips:int},tests:list<array{id:string,status:string,reason:?string,duration_seconds:float,subtests:list<array{id:string,status:string,reason:string}>}>,fixture_errors:list<array{phase:string,id:string,reason:string}>,fixture_skips:list<array{phase:string,id:string,reason:string}>,gaps:list<string>,process:array{exit_code:?int,timed_out:bool,duration_seconds:float},problems:list<string>} Normalized status and validated evidence.
 */
function audit_evaluate_winapp_report(?string $contents, array $process): array
{
    $empty = audit_validate_winapp_report([]);
    $decoded = $contents === null ? null : json_decode($contents, true);
    $validation = is_array($decoded)
        ? audit_validate_winapp_report($decoded)
        : $empty;
    if (!$validation['valid']) {
        $validation['reason'] = $contents === null
            ? 'missing-report-file'
            : (
                json_last_error() === JSON_ERROR_NONE
                    ? $validation['reason']
                    : 'malformed-report-json'
            );
    }

    $exitCode = is_int($process['exit_code'] ?? null)
        ? $process['exit_code']
        : null;
    $timedOut = is_bool($process['timed_out'] ?? null)
        ? $process['timed_out']
        : true;
    $duration = $process['duration_seconds'] ?? null;
    $validDuration =
        (is_int($duration) || is_float($duration))
        && is_finite((float) $duration)
        && $duration >= 0;
    $normalizedProcess = [
        'exit_code' => $exitCode,
        'timed_out' => $timedOut,
        'duration_seconds' => $validDuration ? (float) $duration : 0.0,
    ];
    $problems = [];
    if (!$validation['valid']) {
        $problems[] =
            'WinApp structured report is unavailable or invalid: '
            . ($validation['reason'] ?? 'unknown reason')
            . '.';
    }
    if ($timedOut) {
        $problems[] = 'Python WinApp unittest discovery timed out.';
    }
    if ($exitCode !== 0) {
        $problems[] =
            'Python WinApp reporting adapter exited with code '
            . ($exitCode ?? 'unavailable')
            . '.';
    }
    if (!$validDuration) {
        $problems[] = 'Python WinApp process duration is unavailable or invalid.';
    }
    if ($validation['valid'] && !$validation['successful']) {
        $problems[] = 'WinApp unittest reported unsuccessful outcomes.';
    }

    $processFailed = $timedOut || $exitCode !== 0 || !$validDuration;
    $status = $processFailed
        ? STATUS_FAIL
        : (
            !$validation['valid']
                ? STATUS_BLOCKED
                : (!$validation['successful'] ? STATUS_FAIL : STATUS_PASS)
        );
    return [
        'status' => $status,
        'valid' => $validation['valid'],
        'reason' => $validation['reason'],
        'successful' => $validation['successful'],
        'runtime' => $validation['runtime'],
        'counts' => $validation['counts'],
        'tests' => $validation['tests'],
        'fixture_errors' => $validation['fixture_errors'],
        'fixture_skips' => $validation['fixture_skips'],
        'gaps' => $validation['gaps'],
        'process' => $normalizedProcess,
        'problems' => $problems,
    ];
}
