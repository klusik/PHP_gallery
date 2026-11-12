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
 * Define the fixed full-suite partition count owned by the central audit.
 * @var int
 * Units: child processes. Scope: the optional partitioned WinApp full suite.
 * Consumers: audit_validate_winapp_report() and audit.php's coordinator call.
 * Rationale: five serial class groups isolate measured slow owners while
 * retaining the exact complete canonical test inventory.
 */
const AUDIT_WINAPP_PARTITION_COUNT = 5;

/**
 * Validate a complete version-one WinApp unittest evidence document.
 *
 * The report records unittest callbacks; aggregate counts alone never establish
 * individual test coverage. Invalid or incomplete documents return no trusted
 * per-test counts and include a stable reason for the audit task.
 * The default requires complete discovery; only an explicit diagnostic caller
 * may require the narrower native-viewer pattern instead.
 *
 * @param array<string,mixed> $report Untrusted schema-1 object with exactly `schema_version:int`, `successful:bool`, `runtime:{python_version:string,python_implementation:string,platform:string,executable:string,suite_settings:{start_directory:'winapp/tests',pattern:'test_*.py'|'test_native_gallery_viewer.py',runner:'unittest.TextTestRunner',verbosity:1}}`, `counts:{total:int,passed:int,failed:int,errors:int,skipped:int,expected_failures:int,unexpected_successes:int,subtest_failures:int,subtest_errors:int,subtest_skips:int,fixture_errors:int,fixture_skips:int}`, `tests:list<{id:string,status:'PASS'|'FAIL'|'ERROR'|'SKIP'|'EXPECTED_FAILURE'|'UNEXPECTED_SUCCESS',duration_seconds:int|float,reason:?string,subtests:list<{id:string,status:'FAIL'|'ERROR'|'SKIP',reason:string}>}>`, `fixture_errors:list<{phase:'setUpClass'|'tearDownClass'|'setUpModule'|'tearDownModule',id:string,reason:string}>` and same-shaped `fixture_skips`; partitioned mode additionally requires exactly five ordered process records and proof identity lists.
 * @return array{valid:bool,reason:?string,successful:?bool,runtime:array{python_version:string,python_implementation:string,platform:string,executable:string,suite_settings:array{start_directory:string,pattern:string,runner:string,verbosity:int}}|null,counts:array{total:int,passed:int,failed:int,errors:int,skipped:int,expected_failures:int,unexpected_successes:int,subtest_failures:int,subtest_errors:int,subtest_skips:int,fixture_errors:int,fixture_skips:int},tests:list<array{id:string,status:string,reason:?string,duration_seconds:float,subtests:list<array{id:string,status:string,reason:string}>}>,fixture_errors:list<array{phase:string,id:string,reason:string}>,fixture_skips:list<array{phase:string,id:string,reason:string}>,partition_processes?:list<array{partition:int,test_count:int,exit_code:?int,timed_out:bool,duration_seconds:float,report_present:bool,report_valid:bool,report_successful:?bool,coverage_complete:bool,expected_test_ids:list<string>,observed_test_ids:list<string>}>,partition_proof?:array{partition_count:int,complete:bool,exact_union:bool,expected_test_ids:list<string>,observed_test_ids:list<string>,total_duration_seconds:int|float}|null,gaps:list<string>} Validated schema-v1 evidence with calculated counts and coverage.
 * @param bool $nativeOnly Require the explicit native-viewer diagnostic pattern instead of complete discovery; default false never accepts a diagnostic as full evidence.
 * @param bool $partitioned Require the complete five-partition full-discovery proof; default false retains the original schema contract.
 */
function audit_validate_winapp_report(
    array $report,
    bool $nativeOnly = false,
    bool $partitioned = false,
): array
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
    $invalid = static function (string $reason) use (
        $report,
        $emptyCounts,
        $partitioned,
    ): array {
        $result = [
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
        if ($partitioned) {
            $result['partition_processes'] = [];
            $result['partition_proof'] = null;
        }
        return $result;
    };
    $expectedTopKeys = [
        'schema_version',
        'successful',
        'runtime',
        'counts',
        'tests',
        'fixture_errors',
        'fixture_skips',
    ];
    if ($partitioned) {
        $expectedTopKeys[] = 'partition_processes';
        $expectedTopKeys[] = 'partition_proof';
    }
    $actualTopKeys = array_keys($report);
    sort($expectedTopKeys, SORT_STRING);
    sort($actualTopKeys, SORT_STRING);
    if (
        $actualTopKeys !== $expectedTopKeys
        || ($report['schema_version'] ?? null) !== 1
    ) {
        return $invalid('missing-or-unsupported-report-schema');
    }

    $partitionFailure = false;
    $partitionProcesses = [];
    $partitionProof = null;
    if ($partitioned) {
        $rawProcesses = $report['partition_processes'];
        $rawProof = $report['partition_proof'];
        if (!is_array($rawProcesses) || !array_is_list($rawProcesses)
            || count($rawProcesses) !== AUDIT_WINAPP_PARTITION_COUNT
            || !is_array($rawProof)
            || array_is_list($rawProof)) {
            return $invalid('invalid-partition-evidence');
        }
        $expectedProofKeys = [
            'partition_count', 'complete', 'exact_union', 'expected_test_ids',
            'observed_test_ids', 'total_duration_seconds',
        ];
        $actualProofKeys = array_keys($rawProof);
        sort($expectedProofKeys, SORT_STRING);
        sort($actualProofKeys, SORT_STRING);
        if ($actualProofKeys !== $expectedProofKeys
            || $rawProof['partition_count'] !== AUDIT_WINAPP_PARTITION_COUNT
            || !is_bool($rawProof['complete'])
            || !is_bool($rawProof['exact_union'])
            || !is_array($rawProof['expected_test_ids'])
            || !array_is_list($rawProof['expected_test_ids'])
            || !is_array($rawProof['observed_test_ids'])
            || !array_is_list($rawProof['observed_test_ids'])
            || (!is_int($rawProof['total_duration_seconds'])
                && !is_float($rawProof['total_duration_seconds']))
            || !is_finite((float) $rawProof['total_duration_seconds'])
            || $rawProof['total_duration_seconds'] < 0) {
            return $invalid('invalid-partition-proof');
        }
        $expectedIds = $rawProof['expected_test_ids'];
        $observedIds = $rawProof['observed_test_ids'];
        if (!array_is_list($expectedIds) || !array_is_list($observedIds)
            || count(array_filter($expectedIds, 'is_string')) !== count($expectedIds)
            || count(array_filter($observedIds, 'is_string')) !== count($observedIds)) {
            return $invalid('invalid-partition-test-identities');
        }
        $exactUnion = count($expectedIds) > 0
            && count($expectedIds) === count(array_unique($expectedIds))
            && count($observedIds) === count(array_unique($observedIds))
            && count($expectedIds) === count($observedIds)
            && array_diff($expectedIds, $observedIds) === []
            && array_diff($observedIds, $expectedIds) === [];
        $partitionComplete = $exactUnion;
        $partitionExpectedIds = [];
        $partitionObservedIds = [];
        $classOwners = [];
        foreach ($rawProcesses as $index => $processRecord) {
            $processKeys = [
                'partition', 'test_count', 'exit_code', 'timed_out',
                'duration_seconds', 'report_present', 'report_valid',
                'report_successful',
                'coverage_complete', 'expected_test_ids', 'observed_test_ids',
            ];
            if (!is_array($processRecord) || array_is_list($processRecord)) {
                return $invalid('invalid-partition-process');
            }
            $actualProcessKeys = array_keys($processRecord);
            sort($processKeys, SORT_STRING);
            sort($actualProcessKeys, SORT_STRING);
            if ($actualProcessKeys !== $processKeys
                || $processRecord['partition'] !== $index + 1
                || !is_int($processRecord['test_count'])
                || $processRecord['test_count'] < 1
                || (!is_int($processRecord['exit_code'])
                    && $processRecord['exit_code'] !== null)
                || !is_bool($processRecord['timed_out'])
                || (!is_int($processRecord['duration_seconds'])
                    && !is_float($processRecord['duration_seconds']))
                || !is_finite((float) $processRecord['duration_seconds'])
                || $processRecord['duration_seconds'] < 0
                || !is_bool($processRecord['report_present'])
                || !is_bool($processRecord['report_valid'])
                || ($processRecord['report_valid']
                    && !$processRecord['report_present'])
                || (!$processRecord['report_valid']
                    && $processRecord['report_successful'] !== null)
                || ($processRecord['report_valid']
                    && !is_bool($processRecord['report_successful']))
                || (!is_bool($processRecord['report_successful'])
                    && $processRecord['report_successful'] !== null)
                || !is_bool($processRecord['coverage_complete'])
                || !is_array($processRecord['expected_test_ids'])
                || !array_is_list($processRecord['expected_test_ids'])
                || !is_array($processRecord['observed_test_ids'])
                || !array_is_list($processRecord['observed_test_ids'])
                || count(array_filter(
                    $processRecord['expected_test_ids'],
                    'is_string',
                )) !== count($processRecord['expected_test_ids'])
                || count(array_filter(
                    $processRecord['observed_test_ids'],
                    'is_string',
                )) !== count($processRecord['observed_test_ids'])
                || $processRecord['test_count']
                    !== count($processRecord['expected_test_ids'])) {
                return $invalid('invalid-partition-process');
            }
            $shardComplete = $processRecord['expected_test_ids']
                === $processRecord['observed_test_ids'];
            if ($processRecord['coverage_complete'] !== $shardComplete) {
                return $invalid('partition-shard-proof-mismatch');
            }
            foreach ($processRecord['expected_test_ids'] as $testId) {
                $separator = strrpos($testId, '.');
                if ($separator === false) {
                    return $invalid('invalid-partition-test-identities');
                }
                $classId = substr($testId, 0, $separator);
                if (isset($classOwners[$classId])
                    && $classOwners[$classId] !== $index + 1) {
                    return $invalid('partition-split-test-class');
                }
                $classOwners[$classId] = $index + 1;
            }
            array_push($partitionExpectedIds, ...$processRecord['expected_test_ids']);
            array_push($partitionObservedIds, ...$processRecord['observed_test_ids']);
            $partitionComplete = $partitionComplete
                && $processRecord['coverage_complete'];
            $partitionFailure = $partitionFailure
                || $processRecord['exit_code'] !== 0
                || $processRecord['timed_out']
                || !$processRecord['report_present']
                || !$processRecord['report_valid']
                || $processRecord['report_successful'] !== true
                || !$processRecord['coverage_complete'];
            $partitionProcesses[] = $processRecord;
        }
        $sortedExpectedIds = $expectedIds;
        $sortedPartitionExpectedIds = $partitionExpectedIds;
        $sortedObservedIds = $observedIds;
        $sortedPartitionObservedIds = $partitionObservedIds;
        sort($sortedExpectedIds, SORT_STRING);
        sort($sortedPartitionExpectedIds, SORT_STRING);
        sort($sortedObservedIds, SORT_STRING);
        sort($sortedPartitionObservedIds, SORT_STRING);
        if ($sortedExpectedIds !== $sortedPartitionExpectedIds
            || $sortedObservedIds !== $sortedPartitionObservedIds
            || $rawProof['exact_union'] !== $exactUnion
            || $rawProof['complete'] !== $partitionComplete) {
            return $invalid('partition-proof-does-not-match-identities');
        }
        $partitionFailure = $partitionFailure || !$partitionComplete;
        $partitionProof = $rawProof;
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
        'pattern' => $nativeOnly ? 'test_native_gallery_viewer.py' : 'test_*.py',
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
    if (!$partitioned && $rawTests === [] && $rawFixtureErrors === []
        && $rawFixtureSkips === []) {
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

    if ($partitioned) {
        $reportedTestIds = array_column($tests, 'id');
        $observedTestIds = $partitionProof['observed_test_ids'];
        $reportedProcessTests = array_sum(array_column(
            $partitionProcesses,
            'test_count',
        ));
        $sortedReportedIds = $reportedTestIds;
        $sortedObservedIds = $observedTestIds;
        sort($sortedReportedIds, SORT_STRING);
        sort($sortedObservedIds, SORT_STRING);
        if ($sortedReportedIds !== $sortedObservedIds
            || $reportedProcessTests !== count($partitionProof['expected_test_ids'])) {
            return $invalid('partition-report-identities-disagree');
        }
        if ($partitionFailure) {
            $gaps[] = 'Partition child exit, report or complete identity proof failed.';
        }
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
    if ($report['successful'] === ($hasFailure || $partitionFailure)) {
        return $invalid('successful-flag-disagrees-with-outcomes');
    }

    $validated = [
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
    if ($partitioned) {
        $validated['partition_processes'] = $partitionProcesses;
        $validated['partition_proof'] = $partitionProof;
    }
    return $validated;
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
 * @return array{status:string,valid:bool,reason:?string,successful:?bool,runtime:array{python_version:string,python_implementation:string,platform:string,executable:string,suite_settings:array{start_directory:string,pattern:string,runner:string,verbosity:int}}|null,counts:array{total:int,passed:int,failed:int,errors:int,skipped:int,expected_failures:int,unexpected_successes:int,subtest_failures:int,subtest_errors:int,subtest_skips:int,fixture_errors:int,fixture_skips:int},tests:list<array{id:string,status:string,reason:?string,duration_seconds:float,subtests:list<array{id:string,status:string,reason:string}>}>,fixture_errors:list<array{phase:string,id:string,reason:string}>,fixture_skips:list<array{phase:string,id:string,reason:string}>,partition_processes?:list<array{partition:int,test_count:int,exit_code:?int,timed_out:bool,duration_seconds:float,report_present:bool,report_valid:bool,report_successful:?bool,coverage_complete:bool,expected_test_ids:list<string>,observed_test_ids:list<string>}>,partition_proof?:array{partition_count:int,complete:bool,exact_union:bool,expected_test_ids:list<string>,observed_test_ids:list<string>,total_duration_seconds:int|float}|null,gaps:list<string>,process:array{exit_code:?int,timed_out:bool,duration_seconds:float},problems:list<string>} Normalized status and validated evidence.
 * @param bool $nativeOnly Validate only explicitly requested native-viewer diagnostic evidence; default false retains complete-suite qualification.
 * @param bool $partitioned Validate five child processes and complete/disjoint full-discovery identities.
 */
function audit_evaluate_winapp_report(
    ?string $contents,
    array $process,
    bool $nativeOnly = false,
    bool $partitioned = false,
): array
{
    $empty = audit_validate_winapp_report([], $nativeOnly, $partitioned);
    $decoded = $contents === null ? null : json_decode($contents, true);
    $validation = is_array($decoded)
        ? audit_validate_winapp_report($decoded, $nativeOnly, $partitioned)
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

    $partitionChildFailed = false;
    foreach ($validation['partition_processes'] ?? [] as $partitionProcess) {
        $partitionChildFailed = $partitionChildFailed
            || ($partitionProcess['exit_code'] ?? null) !== 0
            || ($partitionProcess['timed_out'] ?? true)
            || !($partitionProcess['report_present'] ?? false)
            || !($partitionProcess['report_valid'] ?? false)
            || ($partitionProcess['report_successful'] ?? null) !== true
            || !($partitionProcess['coverage_complete'] ?? false);
    }
    $processFailed = $timedOut || $exitCode !== 0 || !$validDuration
        || $partitionChildFailed;
    $status = $processFailed
        ? STATUS_FAIL
        : (
            !$validation['valid']
                ? ($partitioned ? STATUS_FAIL : STATUS_BLOCKED)
                : (!$validation['successful'] ? STATUS_FAIL : STATUS_PASS)
        );
    $result = [
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
    if ($partitioned) {
        $result['partition_processes'] = $validation['partition_processes'];
        $result['partition_proof'] = $validation['partition_proof'];
    }
    return $result;
}
