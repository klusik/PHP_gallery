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
 *   - Exercise bounded child scheduling, exclusive barriers and outcome normalization
 *   - Keep the curated PHP feedback and isolated browser fixture registries consistent
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
 *   2026-10-05
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/audit_lib.php';
$registry = require dirname(__DIR__) . '/scripts/audit_registry.php';

use function PhpGallery\Audit\output_is_skip;
use function PhpGallery\Audit\parse_python_unittest_summary;
use function PhpGallery\Audit\parse_options;
use function PhpGallery\Audit\performance_metric_problems;
use function PhpGallery\Audit\python_command_is_usable;
use function PhpGallery\Audit\relative_path;
use function PhpGallery\Audit\resolve_python_command_from_candidates;
use function PhpGallery\Audit\resolve_browser_executable;
use function PhpGallery\Audit\browser_required;
use function PhpGallery\Audit\process_status;
use function PhpGallery\Audit\run_process_pool;
use function PhpGallery\Audit\run_file_checks;
use function PhpGallery\Audit\worker_count;

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
audit_test_assert(($profiles['release-preflight'] ?? []) === [
    'php-lint', 'source-documentation-changed', 'source-policy-changed',
    'source-contract-inventory', 'python-import-policy', 'ci-workflow-contract',
], 'Release preflight must own the complete cheap static gate centrally, without expensive artifact-dependent suites.');
audit_test_assert(isset($profiles['quick'], $profiles['full'], $profiles['release']), 'Audit registry must retain quick, full, and release profiles.');
audit_test_assert(($profiles['candidate-preflight'] ?? []) === [
    'php-lint', 'js-lint', 'source-documentation-changed',
    'source-policy-changed', 'source-contract-inventory', 'python-import-policy',
    'mvc-boundaries', 'mutation-contracts', 'ci-workflow-contract', 'manifest',
], 'Candidate preflight must enforce deterministic source and generated-artifact blockers without heavy CI jobs.');
audit_test_assert(in_array('manifest', $profiles['full'], true),
    'Authoritative full handoff must refuse stale runtime plans, production inventory and manifest.');
audit_test_assert(str_contains((string) file_get_contents(dirname(__DIR__) . '/scripts/audit.php'),
    "'scripts/prepare_candidate.php', ['--check']"),
    'Central manifest suite must use the common read-only candidate preparation contract.');

audit_test_assert(in_array('php-fast', $profiles['quick'], true) && !in_array('php-regression', $profiles['quick'], true), 'Quick must use curated PHP feedback instead of the complete regression tree.');
foreach (['full', 'release'] as $profile) {
    audit_test_assert(in_array('php-regression', $profiles[$profile], true), 'Full and release must retain complete PHP regression coverage.');
    audit_test_assert(in_array('source-contract-inventory', $profiles[$profile], true), 'Full and release must expose the complete source-contract inventory.');
    audit_test_assert(in_array('winapp', $profiles[$profile], true), 'Full and release must retain WinApp regression coverage.');
}
audit_test_assert(!in_array('source-contract-inventory', $profiles['quick'], true) && !in_array('winapp', $profiles['quick'], true), 'Quick must avoid advisory tree scans and unrelated WinApp discovery.');
audit_test_assert(in_array('node-fast', $profiles['quick'], true), 'Quick profile must use the fast Node suite.');
audit_test_assert(in_array('node-full', $profiles['full'], true), 'Full profile must include slow deterministic Node coverage.');
audit_test_assert(in_array('browser-map', $profiles['full'], true), 'Full handoff must exercise available Chromium fixtures.');
audit_test_assert(!in_array('browser-map', $profiles['quick'], true), 'Quick profile must not launch browsers.');
audit_test_assert(in_array('browser-map', $profiles['release'], true), 'Release profile must include browser integration coverage.');
audit_test_assert(in_array('release-consistency', $profiles['release'], true), 'Release profile must verify release metadata and documentation consistency.');
audit_test_assert(in_array('manifest', $profiles['release'], true), 'Release profile must verify the core manifest.');
foreach (['quick', 'full', 'release'] as $profile) {
    audit_test_assert(in_array('runtime-performance', $profiles[$profile], true), 'Every profile must enforce changed-source operational policy.');
    audit_test_assert(in_array('python-import-policy', $profiles[$profile], true), 'Every central profile must forbid future annotations across all admitted Python sources.');
    audit_test_assert(in_array('source-documentation-changed', $profiles[$profile], true), 'Every central profile must enforce added and materially changed declaration documentation.');
    audit_test_assert(in_array('source-policy-changed', $profiles[$profile], true), 'Every central profile must enforce recognized new or materially changed runtime policy sites.');
}

$phpRegistry = require dirname(__DIR__) . '/scripts/audit_php_registry.php';
$quickTests = $phpRegistry['quick_tests'];
/**
 * Bound the explicit feedback subset without the complete module-loading matrix.
 * @var int Units: registered test cases. Scope: central quick registry contract.
 * Consumers: curated feedback cardinality assertion below.
 * Rationale: the reviewed subset has 46 cases, including the in-process description
 * renderer. Complete compilation and isolated comparison of every runtime module
 * remain covered by full/release rather than repeated during each edit cycle.
 */
const QUICK_REGISTRY_CASE_LIMIT = 46;
audit_test_assert(count($quickTests) >= 15 && count($quickTests) <= QUICK_REGISTRY_CASE_LIMIT && count($quickTests) === count(array_unique($quickTests)), 'Curated feedback must be a small explicit duplicate-free PHP list.');
audit_test_assert(!in_array('runtime_module_plan_test.php', $quickTests, true)
    && in_array('runtime_dependencies_test.php', $quickTests, true)
    && in_array('runtime_plan_ratchet_test.php', $quickTests, true),
    'Quick must retain dependency/graph contracts while reserving the complete clean-child module matrix for full/release.');
foreach ($quickTests as $testName) {
    audit_test_assert(basename($testName) === $testName && is_file(__DIR__ . '/' . $testName), 'Every curated PHP entry must identify an existing standalone test.');
}
foreach ($phpRegistry['serial_tests'] as $testName => $reason) {
    audit_test_assert(is_file(__DIR__ . '/' . $testName) && trim($reason) !== '', 'Every exclusive PHP exception requires an existing test and a concrete reason.');
}

$harnessSource = file_get_contents(__DIR__ . '/admin_panel_lifecycle_browser_test.mjs') ?: '';
preg_match("/const fixtureName = \[(.*?)\]\\.includes\\(process\\.argv\\[3\\]\\)/s", $harnessSource, $fixtureMatch);
$harnessFixtures = [];
if (isset($fixtureMatch[1])) {
    preg_match_all("/'([^']+)'/", $fixtureMatch[1], $fixtureNames);
    $harnessFixtures = $fixtureNames[1] ?? [];
}
foreach ($registry['node_tests'] as $nodeName => $nodeConfig) {
    if (empty($nodeConfig['php_argument'])) {
        continue;
    }
    $wrapperSource = file_get_contents(__DIR__ . '/' . $nodeName) ?: '';
    if (!str_contains($wrapperSource, "admin_panel_lifecycle_browser_test.mjs")) {
        continue;
    }
    audit_test_assert(preg_match("/process\\.argv\\[3\\]\\s*=\\s*'([^']+)'/", $wrapperSource, $wrapperMatch) === 1,
        'PHP-rendered wrapper must select its harness fixture with a literal process.argv[3] assignment: ' . $nodeName);
    audit_test_assert(in_array($wrapperMatch[1], $harnessFixtures, true),
        'PHP-rendered wrapper fixture must be allowlisted by the shared browser harness: ' . $nodeName);
}

audit_test_assert(!empty($registry['node_tests']['gallery_download_zip64_test.mjs']['slow']), 'ZIP64 boundary coverage must stay classified as slow.');
audit_test_assert(!empty($registry['node_tests']['lightbox_map_browser_test.mjs']['browser']), 'The real Chromium lightbox test must stay classified as browser integration.');
audit_test_assert(!empty($registry['node_tests']['admin_gallery_title_completion_browser_test.mjs']['browser']), 'Title completion must retain actual DOM-event browser coverage.');
audit_test_assert(!empty($registry['node_tests']['gallery_picker_parent_integration_browser_test.mjs']['php_argument']), 'PHP-rendered picker browser fixture must receive the current PHP binary even outside PATH.');
audit_test_assert(($registry['node_tests']['gallery_download_zip_test.mjs']['temporary_output'] ?? '') !== '', 'ZIP writer regression must receive a temporary output path.');
audit_test_assert(($registry['php_test_requirements']['gallery_workflow_integration_test.php']['timeout'] ?? 0) >= 120, 'The opt-in migrated HTTP workflow needs a bounded setup-aware timeout.');

$options = parse_options(['audit.php', '--profile', 'quick', '--changed', '--report=cache/custom.md']);
audit_test_assert($options['profile'] === 'quick', 'Profile parser must accept separated option values.');
audit_test_assert($options['changed'] === true, 'Changed-file flag must be retained.');
audit_test_assert($options['report'] === 'cache/custom.md', 'Report parser must accept inline option values.');

audit_test_assert(output_is_skip("SKIP browser unavailable\n"), 'SKIP output at the first line must be recognized.');
audit_test_assert(output_is_skip("setup\nSKIP: pdo_mysql unavailable\n"), 'SKIP output after setup text must be recognized.');
audit_test_assert(!output_is_skip("All tests passed.\n"), 'Ordinary successful output must not be classified as SKIP.');

$previousBrowser = getenv('PHP_GALLERY_BROWSER');
try {
    putenv('PHP_GALLERY_BROWSER=disabled');
    audit_test_assert(resolve_browser_executable() === null, 'Explicit browser disablement must prevent fallback discovery.');
    putenv('PHP_GALLERY_BROWSER=' . PHP_BINARY);
    audit_test_assert(resolve_browser_executable() !== null, 'An explicit local executable must retain ordinary override discovery after disablement.');
} finally {
    putenv($previousBrowser === false ? 'PHP_GALLERY_BROWSER' : 'PHP_GALLERY_BROWSER=' . $previousBrowser);
}

$previousWorkflowBrowser = getenv('GALLERY_WORKFLOW_BROWSER');
$previousWorkflowRequired = getenv('GALLERY_WORKFLOW_REQUIRED');
try {
    putenv('GALLERY_WORKFLOW_BROWSER=disabled');
    putenv('GALLERY_WORKFLOW_REQUIRED=1');
    $disabledWorkflow = \PhpGallery\Audit\run_process(
        [PHP_BINARY, __DIR__ . '/gallery_workflow_browser_test.php'], dirname(__DIR__), 5
    );
    audit_test_assert($disabledWorkflow['exit_code'] === 0
        && output_is_skip($disabledWorkflow['stdout'])
        && str_contains($disabledWorkflow['stdout'], 'explicitly disabled'),
        'Explicit browser-only opt-out must SKIP even when database/HTTP workflows remain required.');
} finally {
    putenv($previousWorkflowBrowser === false ? 'GALLERY_WORKFLOW_BROWSER' : 'GALLERY_WORKFLOW_BROWSER=' . $previousWorkflowBrowser);
    putenv($previousWorkflowRequired === false ? 'GALLERY_WORKFLOW_REQUIRED' : 'GALLERY_WORKFLOW_REQUIRED=' . $previousWorkflowRequired);
}

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
$previousWorkerCount = getenv('PHP_GALLERY_AUDIT_WORKERS');
try {
    putenv('PHP_GALLERY_AUDIT_WORKERS');
    audit_test_assert(worker_count() === 4, 'The process pool default must remain four workers.');
    putenv('PHP_GALLERY_AUDIT_WORKERS=7');
    audit_test_assert(worker_count() === 7 && worker_count('2') === 2 && worker_count() >= 1 && worker_count() <= 8,
        'Worker count must honor the environment and explicit override within the bounded range.');
    foreach (['0', '9', '1.5', 'many'] as $invalidWorkerCount) {
        $invalidRejected = false;
        try {
            worker_count($invalidWorkerCount);
        } catch (InvalidArgumentException) {
            $invalidRejected = true;
        }
        audit_test_assert($invalidRejected, 'Invalid worker override must be rejected: ' . $invalidWorkerCount);
    }
} finally {
    putenv($previousWorkerCount === false ? 'PHP_GALLERY_AUDIT_WORKERS' : 'PHP_GALLERY_AUDIT_WORKERS=' . $previousWorkerCount);
}
$previousBrowserRequired = getenv('PHP_GALLERY_BROWSER_REQUIRED');
try {
    putenv('PHP_GALLERY_BROWSER_REQUIRED=1');
    audit_test_assert(browser_required(), 'The dedicated browser coverage switch must be recognized.');
    putenv('PHP_GALLERY_BROWSER_REQUIRED=0');
    audit_test_assert(!browser_required(), 'Other browser switch values must not require Chromium.');
} finally {
    putenv($previousBrowserRequired === false ? 'PHP_GALLERY_BROWSER_REQUIRED' : 'PHP_GALLERY_BROWSER_REQUIRED=' . $previousBrowserRequired);
}
audit_test_assert(process_status(['exit_code' => 0, 'stdout' => 'passed']) === 'PASS', 'A clean child must normalize to PASS.');
audit_test_assert(process_status(['exit_code' => 1, 'stdout' => 'specific failure']) === 'FAIL', 'A nonzero child must normalize to FAIL.');
audit_test_assert(process_status(['exit_code' => 0, 'stdout' => "SKIP unavailable\n"]) === 'SKIP', 'Optional child skip must remain SKIP.');
audit_test_assert(process_status(['exit_code' => 0, 'stdout' => "SKIP Chromium unavailable\n"], true) === 'BLOCKED', 'Required child skip must become BLOCKED.');
audit_test_assert(process_status(['exit_code' => 0, 'stdout' => "BLOCKED missing runtime\n"]) === 'BLOCKED', 'Explicit blocked child output must remain BLOCKED.');
audit_test_assert(process_status(['exit_code' => 124, 'timed_out' => true]) === 'FAIL', 'Timed out children must normalize to FAIL.');

$fixtureDirectory = sys_get_temp_dir() . '/php-gallery-audit-' . bin2hex(random_bytes(6));
mkdir($fixtureDirectory);
$statePath = $fixtureDirectory . '/state.txt';
$eventPath = $fixtureDirectory . '/events.txt';
$lockPath = $fixtureDirectory . '/state.lock';
try {
    $validSource = $fixtureDirectory . '/valid source.php';
    $invalidSource = $fixtureDirectory . '/invalid source.php';
    $executionSentinel = $fixtureDirectory . '/must-not-execute.txt';
    file_put_contents($validSource, '<?php file_put_contents(' . var_export($executionSentinel, true) . ', "executed");');
    file_put_contents($invalidSource, '<?php function broken( {');
    $syntaxResults = run_file_checks([$invalidSource, $validSource], [PHP_BINARY, '-n', '-l'], $fixtureDirectory, 2, 15);
    audit_test_assert(process_status($syntaxResults[0]) === 'FAIL' && process_status($syntaxResults[1]) === 'PASS'
        && str_contains($syntaxResults[0]['stdout'] . $syntaxResults[0]['stderr'], 'invalid source.php')
        && str_contains($syntaxResults[1]['stdout'], 'valid source.php') && !is_file($executionSentinel),
        'Parallel syntax checks must attribute failures to literal paths with spaces and never execute checked code.');
    file_put_contents($statePath, "0,0\n");
    $makeFixtureJob = static function (string $name, string $kind = 'parallel', int $delayMs = 120) use ($statePath, $eventPath, $lockPath): array {
        $script = '$state=' . var_export($statePath, true) . ';$events=' . var_export($eventPath, true) . ';$lockPath=' . var_export($lockPath, true)
            . ';$kind=' . var_export($kind, true) . ';$name=' . var_export($name, true) . ';$delay=' . $delayMs . ';'
            . '$lock=fopen($lockPath,"c+");flock($lock,LOCK_EX);$state=file_get_contents(' . var_export($statePath, true) . ');[$active,$max]=array_map("intval",explode(",",trim($state)));$active++;$max=max($max,$active);'
            . 'file_put_contents(' . var_export($statePath, true) . ',"$active,$max\n");if($kind==="serial"){file_put_contents($events,"serial-active:$active\n",FILE_APPEND);}flock($lock,LOCK_UN);fclose($lock);'
            . 'usleep($delay*1000);$lock=fopen($lockPath,"c+");flock($lock,LOCK_EX);$state=file_get_contents(' . var_export($statePath, true) . ');[$active,$max]=array_map("intval",explode(",",trim($state)));$active--;file_put_contents(' . var_export($statePath, true) . ',"$active,$max\n");flock($lock,LOCK_UN);fclose($lock);echo ' . var_export($name, true) . ';';
        return ['command' => [PHP_BINARY, '-r', $script], 'timeout' => 3, 'serial' => $kind === 'serial'];
    };
    $poolResults = run_process_pool([
        $makeFixtureJob('first', 'parallel'),
        $makeFixtureJob('second', 'parallel', 30),
        $makeFixtureJob('exclusive', 'serial', 40),
        $makeFixtureJob('fourth', 'parallel'),
        $makeFixtureJob('fifth', 'parallel'),
    ], $fixtureDirectory, 2);
    $poolOutputs = array_map(static fn(array $result): string => trim($result['stdout']), $poolResults);
    $stateValues = array_map('intval', explode(',', trim((string) file_get_contents($statePath))));
    $events = file_get_contents($eventPath) ?: '';
    audit_test_assert($poolOutputs === ['first', 'second', 'exclusive', 'fourth', 'fifth'], 'Concurrent process results must retain input order after out-of-order completion.');
    audit_test_assert($stateValues[1] === 2 && str_contains($events, 'serial-active:1'), 'Parallel work must obey its bound and drain before an exclusive child starts.');
    audit_test_assert(count(array_filter($poolResults, static fn(array $result): bool => process_status($result) === 'PASS')) === 5,
        'All successful process-pool fixture children must retain independent result status.');
    $invalidPoolRejected = false;
    try {
        run_process_pool([], $fixtureDirectory, 9);
    } catch (InvalidArgumentException) {
        $invalidPoolRejected = true;
    }
    audit_test_assert($invalidPoolRejected, 'The scheduler itself must reject worker limits outside the supported range.');
    $failureResults = run_process_pool([
        ['command' => [PHP_BINARY, '-r', 'fwrite(STDERR,"fixture-failure"); exit(7);'], 'timeout' => 2],
        ['command' => ['php-gallery-command-that-does-not-exist'], 'timeout' => 2],
    ], $fixtureDirectory, 2);
    audit_test_assert(process_status($failureResults[0]) === 'FAIL' && $failureResults[0]['exit_code'] === 7
        && str_contains($failureResults[0]['stderr'], 'fixture-failure'), 'Failure output and exit status must remain attributable to the corresponding child.');
    audit_test_assert(process_status($failureResults[1]) === 'BLOCKED' && str_contains($failureResults[1]['stderr'], 'Unable to allocate capture streams'),
        'Process spawn failure must produce a diagnostic BLOCKED result.');
    $timeoutResults = run_process_pool([
        ['command' => [PHP_BINARY, '-r', 'usleep(1100000); echo "preceding";'], 'timeout' => 3],
        ['command' => [PHP_BINARY, '-r', 'echo "started-later";'], 'timeout' => 1],
        ['command' => [PHP_BINARY, '-r', 'usleep(1300000); echo "must-time-out";'], 'timeout' => 1],
        ['command' => [PHP_BINARY, '-r', 'echo "recovered";'], 'timeout' => 1],
    ], $fixtureDirectory, 1);
    audit_test_assert(process_status($timeoutResults[1]) === 'PASS' && trim($timeoutResults[1]['stdout']) === 'started-later'
        && process_status($timeoutResults[2]) === 'FAIL' && $timeoutResults[2]['timed_out']
        && process_status($timeoutResults[3]) === 'PASS',
        'A queued child gets a fresh timeout after its actual start; a timed-out child does not prevent recovery.');

    $treeFixtures = [
        ['sentinel' => $fixtureDirectory . '/group-late-sentinel.txt', 'ready' => $fixtureDirectory . '/group-ready.txt', 'force_fallback' => false],
    ];
    if (DIRECTORY_SEPARATOR !== '\\') {
        $treeFixtures[] = ['sentinel' => $fixtureDirectory . '/fallback-late-sentinel.txt', 'ready' => $fixtureDirectory . '/fallback-ready.txt', 'force_fallback' => true];
    }
    $treeActive = [];
    try {
        $nullDevice = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        foreach ($treeFixtures as $index => $treeFixture) {
            $grandchildReady = $fixtureDirectory . '/grandchild-' . $index . '-ready.txt';
            $grandchildCode = '$ready=' . var_export($grandchildReady, true) . ';$sentinel=' . var_export($treeFixture['sentinel'], true)
                . ';file_put_contents($ready,"ready");usleep(1500000);file_put_contents($sentinel,"survived");';
            $rootCode = '$childCommand=' . var_export([PHP_BINARY, '-r', $grandchildCode], true) . ';$null=' . var_export($nullDevice, true)
                . ';$pipes=[];$child=proc_open($childCommand,[0=>["file",$null,"r"],1=>["file",$null,"w"],2=>["file",$null,"w"]],$pipes,'
                . var_export($root, true) . ',null,["bypass_shell"=>true]);if(!is_resource($child)){exit(31);} $deadline=microtime(true)+8;'
                . 'while(!is_file(' . var_export($grandchildReady, true) . ')&&microtime(true)<$deadline){usleep(10000);}file_put_contents('
                . var_export($treeFixture['ready'], true) . ',"ready");usleep(3000000);';
            $activeTreeProcess = \PhpGallery\Audit\start_process(['command' => [PHP_BINARY, '-r', $rootCode], 'timeout' => 1], $root);
            audit_test_assert(isset($activeTreeProcess['process']), 'Process-tree fixture root must start successfully.');
            if ($treeFixture['force_fallback']) {
                $activeTreeProcess['group'] = false;
            }
            $treeActive[$index] = $activeTreeProcess;
        }
        $readyDeadline = hrtime(true) / 1e9 + 8;
        do {
            $allReady = true;
            foreach ($treeFixtures as $treeFixture) {
                $allReady = $allReady && is_file($treeFixture['ready']);
            }
            if (!$allReady) {
                usleep(10000);
            }
        } while (!$allReady && hrtime(true) / 1e9 < $readyDeadline);
        audit_test_assert($allReady, 'Each process-tree fixture must confirm its grandchild started before cleanup.');
        foreach ($treeActive as $index => $activeTreeProcess) {
            // Set the monotonic start just beyond the registered one-second timeout after the fixture is ready.
            $activeTreeProcess['started'] = hrtime(true) / 1e9 - 1.1;
            \PhpGallery\Audit\terminate_process($activeTreeProcess);
            $treeResults[$index] = \PhpGallery\Audit\finish_process(
                $activeTreeProcess, proc_get_status($activeTreeProcess['process']), true
            );
            unset($treeActive[$index]);
        }
        audit_test_assert(count($treeResults ?? []) === count($treeFixtures)
            && count(array_filter($treeResults, static fn(array $result): bool => $result['timed_out'] && $result['exit_code'] === 124)) === count($treeFixtures),
            'Process-tree cleanup must return the same timeout result as an expired child deadline.');
        usleep(1700000);
        foreach ($treeFixtures as $treeFixture) {
            audit_test_assert(!is_file($treeFixture['sentinel']), 'A killed child or grandchild must not write after its original delayed side effect deadline: ' . basename($treeFixture['sentinel']));
        }
    } finally {
        foreach ($treeActive as $activeTreeProcess) {
            \PhpGallery\Audit\terminate_process($activeTreeProcess);
            \PhpGallery\Audit\finish_process($activeTreeProcess, proc_get_status($activeTreeProcess['process']), true);
        }
    }
} finally {
    foreach (glob($fixtureDirectory . '/*') ?: [] as $fixtureFile) {
        unlink($fixtureFile);
    }
    rmdir($fixtureDirectory);
}
$noisyProcess = \PhpGallery\Audit\run_process(
    [PHP_BINARY, '-r', 'fwrite(STDERR, str_repeat("E", 262144)); fwrite(STDOUT, str_repeat("O", 262144));'], $root, 5
);
audit_test_assert($noisyProcess['exit_code'] === 0 && strlen($noisyProcess['stdout']) === 262144
    && strlen($noisyProcess['stderr']) === 262144, 'Both large output streams must drain without Windows pipe deadlocks.');
$timedProcess = \PhpGallery\Audit\run_process([PHP_BINARY, '-r', 'usleep(3000000);'], $root, 1);
audit_test_assert($timedProcess['timed_out'] && $timedProcess['exit_code'] === 124 && $timedProcess['duration'] < 2.5,
    'A silent child must still observe the hard process timeout.');

$performanceRegistry = require $root . '/scripts/audit_performance_registry.php';
audit_test_assert(array_keys($performanceRegistry) === ['early-runtime', 'application-bootstrap'],
    'Both registered clean-process bootstrap probes must remain explicit.');
foreach ($performanceRegistry as $probeId => $limits) {
    $probeProcess = \PhpGallery\Audit\run_process([PHP_BINARY, $root . '/scripts/audit_runtime_probe.php', $probeId], $root, 15);
    $metrics = json_decode($probeProcess['stdout'], true);
    audit_test_assert($probeProcess['exit_code'] === 0 && is_array($metrics), 'Registered probe must emit valid JSON from a fresh PHP process: ' . $probeId);
    audit_test_assert(performance_metric_problems($metrics, $probeId, $limits) === [], 'Registered clean-process metrics must satisfy their schema and deterministic limits: ' . $probeId);
    audit_test_assert(count($metrics['included_paths']) === $metrics['included_php_files']
        && !array_filter($metrics['included_paths'], static fn(string $path): bool => !str_starts_with($path, 'app/') || str_contains($path, 'audit_')),
        'Probe include inventory must be application PHP only and match its count: ' . $probeId);

    $observationalWall = $metrics;
    $observationalWall['bootstrap_wall_ms'] = 9999999999.0;
    audit_test_assert(performance_metric_problems($observationalWall, $probeId, $limits) === [],
        'A large finite wall time must remain observational rather than failing deterministic guards.');
    $includeOverLimit = $metrics;
    $includeOverLimit['included_php_files'] = $limits['max_included_php_files'] + 1;
    $includeOverLimit['included_paths'] = array_fill(0, $includeOverLimit['included_php_files'], 'app/performance_test_synthetic.php');
    $includeProblems = performance_metric_problems($includeOverLimit, $probeId, $limits);
    audit_test_assert(count($includeProblems) === 1 && str_contains($includeProblems[0], 'included_php_files'),
        'Exceeding the included-file ceiling must produce a clear failure problem.');
    $memoryOverLimit = $metrics;
    $memoryOverLimit['peak_memory_bytes'] = $limits['max_peak_memory_bytes'] + 1;
    $memoryProblems = performance_metric_problems($memoryOverLimit, $probeId, $limits);
    audit_test_assert(count($memoryProblems) === 1 && str_contains($memoryProblems[0], 'peak_memory_bytes'),
        'Exceeding the peak-memory ceiling must produce a clear failure problem.');
    $malformedMetrics = $metrics;
    unset($malformedMetrics['included_paths']);
    audit_test_assert(performance_metric_problems($malformedMetrics, $probeId, $limits) !== [],
        'Malformed performance output must fail schema validation.');
}

$previousBrowserRequired = getenv('PHP_GALLERY_BROWSER_REQUIRED');
$previousBrowser = getenv('PHP_GALLERY_BROWSER');
try {
    putenv('PHP_GALLERY_BROWSER_REQUIRED=1');
    putenv('PHP_GALLERY_BROWSER=disabled');
    $requiredBrowserAudit = \PhpGallery\Audit\run_process(
        [PHP_BINARY, $root . '/scripts/audit.php', '--suite=browser-map', '--no-report'], $root, 20
    );
    $requiredBrowserCount = count(array_filter($registry['node_tests'], static fn(array $definition): bool => !empty($definition['browser'])));
    audit_test_assert($requiredBrowserAudit['exit_code'] === 2
        && str_contains($requiredBrowserAudit['stdout'], 'Result: BLOCKED')
        && str_contains($requiredBrowserAudit['stdout'], $requiredBrowserCount . ' blocked')
        && !str_contains($requiredBrowserAudit['stdout'], $requiredBrowserCount . ' skip'),
        'Required browser CLI must exit 2 with every registered unavailable fixture BLOCKED, never silently SKIP.');
} finally {
    putenv($previousBrowserRequired === false ? 'PHP_GALLERY_BROWSER_REQUIRED' : 'PHP_GALLERY_BROWSER_REQUIRED=' . $previousBrowserRequired);
    putenv($previousBrowser === false ? 'PHP_GALLERY_BROWSER' : 'PHP_GALLERY_BROWSER=' . $previousBrowser);
}

$previousWorkerCount = getenv('PHP_GALLERY_AUDIT_WORKERS');
try {
    putenv('PHP_GALLERY_AUDIT_WORKERS=0');
    foreach (['php-lint-changed', 'js-lint-changed', 'node-fast'] as $suiteId) {
        $invalidWorkerAudit = \PhpGallery\Audit\run_process(
            [PHP_BINARY, $root . '/scripts/audit.php', '--suite=' . $suiteId, '--no-report'], $root, 20
        );
        audit_test_assert($invalidWorkerAudit['exit_code'] === 2
            && str_contains($invalidWorkerAudit['stdout'], 'Result: BLOCKED')
            && str_contains($invalidWorkerAudit['stdout'], 'PHP_GALLERY_AUDIT_WORKERS'),
            'Syntax and Node CLI suites must block an invalid shared worker limit: ' . $suiteId);
    }
} finally {
    putenv($previousWorkerCount === false ? 'PHP_GALLERY_AUDIT_WORKERS' : 'PHP_GALLERY_AUDIT_WORKERS=' . $previousWorkerCount);
}

$releaseTask = \PhpGallery\Audit\task_result(
    'release-consistency', 'Release consistency', 'PASS', 0.0, [], 'Consistent.'
);
$identity = ['fingerprint' => str_repeat('a', 64)];
audit_test_assert(\PhpGallery\Audit\bind_release_source_identity([$releaseTask], $identity, $identity) === [$releaseTask],
    'A frozen release tree keeps its original automated result.');
$changedTask = \PhpGallery\Audit\bind_release_source_identity([$releaseTask], $identity, ['fingerprint' => str_repeat('b', 64)])[0];
audit_test_assert($changedTask['status'] === 'FAIL' && $changedTask['details']['problems'] !== [],
    'A source change during auditing invalidates release consistency.');
$missingTask = \PhpGallery\Audit\bind_release_source_identity([$releaseTask], null, $identity)[0];
audit_test_assert($missingTask['status'] === 'BLOCKED', 'Unavailable source identity must not qualify a release.');
$failedTask = array_replace($releaseTask, ['status' => 'FAIL']);
audit_test_assert(\PhpGallery\Audit\bind_release_source_identity([$failedTask], null, null)[0]['status'] === 'FAIL',
    'Source capture failure cannot downgrade an existing release failure.');

echo "Central audit runner contracts passed.\n";
