<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/audit.php
 * Module Type: Central Audit Runner
 *
 * Purpose:
 *   Runs the source-tree quality gate once and emits a compact console result plus drill-down reports.
 *
 * Responsibilities:
 *   - Orchestrate PHP, Node, Python, syntax, contract, browser, manifest, and Git checks
 *   - Preserve process isolation while hiding successful subprocess noise from agent output
 *   - Distinguish PASS, FAIL, SKIP, and BLOCKED coverage
 *   - Apply bounded subprocess timeouts and continue after individual failures
 *   - Produce Markdown, JSON, and per-suite logs under cache/test-audit
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
 *   - The source test tree is intentionally absent from normal production deployment packages.
 *   - Run `php scripts/audit.php --profile=full` as the normal agent verification entrypoint.
 *
 * Last Updated:
 *   2026-09-05
 */

declare(strict_types=1);

use function PhpGallery\Audit\discover_files;
use function PhpGallery\Audit\exit_code_for_status;
use function PhpGallery\Audit\find_executable;
use function PhpGallery\Audit\format_duration;
use function PhpGallery\Audit\git_changed_paths;
use function PhpGallery\Audit\output_is_skip;
use function PhpGallery\Audit\parse_python_unittest_summary;
use function PhpGallery\Audit\overall_status;
use function PhpGallery\Audit\parse_options;
use function PhpGallery\Audit\project_root;
use function PhpGallery\Audit\relative_path;
use function PhpGallery\Audit\render_markdown_report;
use function PhpGallery\Audit\resolve_browser_executable;
use function PhpGallery\Audit\resolve_executable;
use function PhpGallery\Audit\resolve_python_command;
use function PhpGallery\Audit\run_process;
use function PhpGallery\Audit\task_result;
use function PhpGallery\Audit\write_text_file;

use const PhpGallery\Audit\STATUS_BLOCKED;
use const PhpGallery\Audit\STATUS_FAIL;
use const PhpGallery\Audit\STATUS_PASS;
use const PhpGallery\Audit\STATUS_SKIP;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/audit_lib.php';

$registryPath = __DIR__ . '/audit_registry.php';
if (!is_file($registryPath)) {
    fwrite(STDERR, "Audit registry is missing: scripts/audit_registry.php\n");
    exit(2);
}
$registry = require $registryPath;

try {
    $options = parse_options($argv);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\nUse --help for supported options.\n");
    exit(2);
}

if ($options['help']) {
    fwrite(STDOUT, <<<'TEXT'
PHP Gallery central audit

Usage:
  php scripts/audit.php [--profile quick|full|release]
  php scripts/audit.php --suite <suite-id>
  php scripts/audit.php --profile full --changed

Options:
  --profile <name>   Audit profile. Default: full.
  --suite <id>       Run one registered suite only. Intended for diagnosis and tests/run.php compatibility.
  --changed          Use Git-changed PHP/JavaScript lint targets when possible.
  --report <path>    Override the Markdown report path.
  --json <path>      Override the JSON report path.
  --no-report        Do not persist reports or logs. Intended for compatibility wrappers.
  --help             Show this help.

Environment overrides:
  PHP_GALLERY_NODE, PHP_GALLERY_PYTHON, PHP_GALLERY_GIT, PHP_GALLERY_BROWSER

Profiles:
  quick    Full PHP regression plus fast Node/Python/contracts and changed-file syntax checks.
  full     Complete deterministic source audit, including slow ZIP64 coverage and full syntax checks.
  release  Full audit plus browser integration, core-manifest freshness, and git diff validation.
TEXT
    );
    exit(0);
}

$root = project_root();
$profiles = is_array($registry['profiles'] ?? null) ? $registry['profiles'] : [];
$profile = (string) $options['profile'];
if ($options['suite'] !== null) {
    $suiteIds = [(string) $options['suite']];
    $profileLabel = 'suite:' . $options['suite'];
} else {
    if (!isset($profiles[$profile]) || !is_array($profiles[$profile])) {
        fwrite(STDERR, 'Unknown audit profile: ' . $profile . "\n");
        exit(2);
    }
    $suiteIds = array_values($profiles[$profile]);
    $profileLabel = $profile;
}

if ($options['changed']) {
    $suiteIds = array_map(
        static fn(string $suite): string => $suite === 'php-lint' ? 'php-lint-changed' : ($suite === 'js-lint' ? 'js-lint-changed' : $suite),
        $suiteIds
    );
}

$knownSuites = [
    'php-regression',
    'node-fast',
    'node-full',
    'winapp',
    'mutation-contracts',
    'version-audit',
    'php-lint',
    'php-lint-changed',
    'js-lint',
    'js-lint-changed',
    'browser-map',
    'manifest',
    'git-diff-check',
];
foreach ($suiteIds as $suiteId) {
    if (!in_array($suiteId, $knownSuites, true)) {
        fwrite(STDERR, 'Unknown audit suite: ' . $suiteId . "\n");
        exit(2);
    }
}

$startedAt = new DateTimeImmutable('now');
$startedClock = microtime(true);
$persistReports = !$options['no_report'];
$runId = $startedAt->format('Ymd-His') . '-' . getmypid();
$runDirectory = $persistReports
    ? $root . '/cache/test-audit/' . $runId
    : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-gallery-audit-' . $runId;
if (!is_dir($runDirectory) && !mkdir($runDirectory, 0775, true) && !is_dir($runDirectory)) {
    fwrite(STDERR, 'Unable to create audit work directory: ' . $runDirectory . "\n");
    exit(2);
}

$node = resolve_executable('PHP_GALLERY_NODE', ['node']);
$python = resolve_python_command();
$git = resolve_executable('PHP_GALLERY_GIT', ['git']);
$browser = resolve_browser_executable();

/**
 * Return a concise executable version string without exposing noisy tool output.
 *
 * @param array $command Command prefix.
 * @param array $versionArgs Version arguments.
 * @return string Version or unavailable marker.
 */
function audit_tool_version(array $command, array $versionArgs): string
{
    $result = run_process(array_merge($command, $versionArgs), project_root(), 10);
    $output = trim($result['stdout'] . "\n" . $result['stderr']);
    if ($result['exit_code'] !== 0 || $output === '') {
        return 'unavailable';
    }
    $line = preg_split('/\R/', $output)[0] ?? $output;
    return trim($line);
}

$environment = [
    ['component' => 'PHP', 'status' => STATUS_PASS, 'value' => PHP_VERSION . ' (' . PHP_BINARY . ')'],
    ['component' => 'PHP GD', 'status' => extension_loaded('gd') ? STATUS_PASS : STATUS_BLOCKED, 'value' => extension_loaded('gd') ? 'loaded' : 'not loaded'],
    ['component' => 'pdo_mysql', 'status' => extension_loaded('pdo_mysql') ? STATUS_PASS : STATUS_SKIP, 'value' => extension_loaded('pdo_mysql') ? 'loaded' : 'not loaded'],
    ['component' => 'Node', 'status' => $node !== null ? STATUS_PASS : STATUS_BLOCKED, 'value' => $node !== null ? audit_tool_version([$node], ['--version']) : 'not found'],
    ['component' => 'Python', 'status' => $python !== null ? STATUS_PASS : STATUS_BLOCKED, 'value' => $python !== null ? audit_tool_version($python, ['--version']) : 'not found'],
    ['component' => 'Git', 'status' => $git !== null && is_dir($root . '/.git') ? STATUS_PASS : STATUS_SKIP, 'value' => $git !== null ? ($git . (is_dir($root . '/.git') ? '' : ' (no checkout metadata)')) : 'not found'],
    ['component' => 'Chromium browser', 'status' => $browser !== null ? STATUS_PASS : STATUS_SKIP, 'value' => $browser ?? 'not found'],
];

$tasks = [];
$slowChecks = [];

/**
 * Write one suite log and return its repository-relative path.
 *
 * @param string $suiteId Stable suite identifier.
 * @param array $lines Log lines.
 * @return ?string Repository-relative log path, or null when report persistence is disabled.
 */
function audit_write_log(string $suiteId, array $lines): ?string
{
    global $persistReports, $runDirectory, $root;
    if (!$persistReports) {
        return null;
    }
    $path = $runDirectory . '/' . preg_replace('/[^a-z0-9._-]+/i', '-', $suiteId) . '.log';
    write_text_file($path, implode("\n", $lines) . "\n");
    return relative_path($path, $root);
}

/**
 * Append a timed atomic check for the report's slowest-check section.
 *
 * @param string $name Human-readable check name.
 * @param float $duration Duration in seconds.
 * @return void
 */
function audit_record_slow_check(string $name, float $duration): void
{
    global $slowChecks;
    $slowChecks[] = ['name' => $name, 'duration_seconds' => round($duration, 4)];
}

/**
 * Return a compact output excerpt suitable for a detailed suite log.
 *
 * @param array $process Process result.
 * @return string Combined output.
 */
function audit_process_output(array $process): string
{
    $parts = [];
    if (trim((string) $process['stdout']) !== '') {
        $parts[] = rtrim((string) $process['stdout']);
    }
    if (trim((string) $process['stderr']) !== '') {
        $parts[] = rtrim((string) $process['stderr']);
    }
    return implode("\n", $parts);
}

/**
 * Run every standalone PHP regression script in an isolated process.
 *
 * @param array $registry Audit registry.
 * @return array Normalized task result.
 */
function audit_run_php_regression(array $registry): array
{
    global $root;
    $started = microtime(true);
    $testDirectory = $root . '/tests';
    if (!is_dir($testDirectory)) {
        return task_result('php-regression', 'PHP regression', STATUS_BLOCKED, 0.0, [], 'tests/ source tree is unavailable.', null, ['problems' => ['The tracked tests/ directory is required for source-tree regression coverage.']]);
    }

    $files = glob($testDirectory . '/*_test.php') ?: [];
    sort($files, SORT_STRING);
    if ($files === []) {
        return task_result('php-regression', 'PHP regression', STATUS_BLOCKED, 0.0, [], 'No *_test.php scripts were found.');
    }

    $requirements = is_array($registry['php_test_requirements'] ?? null) ? $registry['php_test_requirements'] : [];
    $counts = ['passed' => 0, 'failed' => 0, 'skipped' => 0, 'blocked' => 0, 'total' => count($files)];
    $problems = [];
    $gaps = [];
    $log = ['PHP regression suite'];

    foreach ($files as $file) {
        $name = basename($file);
        $requirement = is_array($requirements[$name] ?? null) ? $requirements[$name] : [];
        $missingExtensions = [];
        foreach (($requirement['extensions'] ?? []) as $extension) {
            if (!extension_loaded((string) $extension)) {
                $missingExtensions[] = (string) $extension;
            }
        }
        if ($missingExtensions !== []) {
            $counts['blocked']++;
            $reason = (string) ($requirement['reason'] ?? ('Missing PHP extension(s): ' . implode(', ', $missingExtensions)));
            $problems[] = $name . ': ' . $reason;
            $gaps[] = $name . ': ' . $reason;
            $log[] = '[BLOCKED] ' . $name . ' - ' . $reason;
            continue;
        }

        $process = run_process([PHP_BINARY, $file], $root, 45);
        audit_record_slow_check('PHP ' . $name, (float) $process['duration']);
        $output = audit_process_output($process);
        if ($process['timed_out']) {
            $counts['failed']++;
            $message = $name . ': timed out after 45 seconds.';
            $problems[] = $message;
            $log[] = '[FAIL] ' . $name . ' ' . format_duration((float) $process['duration']) . ' - timeout';
            $log[] = $output;
            continue;
        }
        if ((int) $process['exit_code'] !== 0) {
            $counts['failed']++;
            $problems[] = $name . ': exit code ' . $process['exit_code'] . '.';
            $log[] = '[FAIL] ' . $name . ' ' . format_duration((float) $process['duration']) . ' exit=' . $process['exit_code'];
            $log[] = $output;
            continue;
        }
        if (output_is_skip($output)) {
            $counts['skipped']++;
            $skipReason = $output !== '' ? preg_replace('/\s+/', ' ', trim($output)) : 'test reported SKIP';
            $gaps[] = $name . ': ' . $skipReason;
            $log[] = '[SKIP] ' . $name . ' ' . format_duration((float) $process['duration']) . ($output !== '' ? ' - ' . preg_replace('/\s+/', ' ', trim($output)) : '');
            continue;
        }
        $counts['passed']++;
        $log[] = '[PASS] ' . $name . ' ' . format_duration((float) $process['duration']);
    }

    $status = $counts['failed'] > 0 ? STATUS_FAIL : ($counts['blocked'] > 0 ? STATUS_BLOCKED : STATUS_PASS);
    $summary = $counts['passed'] . ' pass / ' . $counts['failed'] . ' fail / ' . $counts['skipped'] . ' skip / ' . $counts['blocked'] . ' blocked';
    $logPath = audit_write_log('php-regression', $log);
    return task_result('php-regression', 'PHP regression', $status, microtime(true) - $started, $counts, $summary, $logPath, ['problems' => $problems, 'gaps' => $gaps]);
}

/**
 * Return explicit Node test definitions for one source-tree suite.
 *
 * @param array $registry Audit registry.
 * @param bool $includeSlow True to include slow deterministic tests.
 * @param bool $browserOnly True to select only the browser integration test.
 * @return array Test definitions keyed by filename.
 */
function audit_select_node_tests(array $registry, bool $includeSlow, bool $browserOnly = false): array
{
    $tests = is_array($registry['node_tests'] ?? null) ? $registry['node_tests'] : [];
    return array_filter(
        $tests,
        static function (array $definition) use ($includeSlow, $browserOnly): bool {
            $isBrowser = !empty($definition['browser']);
            if ($browserOnly) {
                return $isBrowser;
            }
            if ($isBrowser) {
                return false;
            }
            if (!$includeSlow && !empty($definition['slow'])) {
                return false;
            }
            return true;
        }
    );
}

/**
 * Run explicit Node regression definitions, including tests that require temporary file arguments.
 *
 * @param string $suiteId Stable suite identifier.
 * @param string $label Human-readable label.
 * @param array $definitions Selected Node definitions.
 * @param ?string $node Node executable.
 * @param ?string $browser Browser executable for browser-only tests.
 * @return array Normalized task result.
 */
function audit_run_node_suite(string $suiteId, string $label, array $definitions, ?string $node, ?string $browser = null): array
{
    global $root, $runDirectory;
    $started = microtime(true);
    if ($node === null) {
        return task_result($suiteId, $label, STATUS_BLOCKED, 0.0, [], 'Node executable was not found.', null, ['problems' => ['Set PHP_GALLERY_NODE or install Node.js to execute JavaScript regression tests.']]);
    }
    if ($definitions === []) {
        return task_result($suiteId, $label, STATUS_BLOCKED, 0.0, [], 'No registered Node tests were selected.');
    }

    $counts = ['passed' => 0, 'failed' => 0, 'skipped' => 0, 'blocked' => 0, 'total' => count($definitions)];
    $problems = [];
    $gaps = [];
    $log = [$label];

    foreach ($definitions as $name => $definition) {
        $file = $root . '/tests/' . $name;
        if (!is_file($file)) {
            $counts['blocked']++;
            $problems[] = $name . ': registered file is missing.';
            $log[] = '[BLOCKED] ' . $name . ' - registered file is missing';
            continue;
        }

        $command = [$node, $file];
        $temporaryOutput = null;
        if (!empty($definition['temporary_output'])) {
            $temporaryOutput = $runDirectory . '/' . basename((string) $definition['temporary_output']);
            $command[] = $temporaryOutput;
        }
        if (!empty($definition['browser'])) {
            if ($browser === null) {
                $counts['skipped']++;
                $gaps[] = $name . ': no Chrome/Chromium/Edge executable detected';
                $log[] = '[SKIP] ' . $name . ' - no Chrome/Chromium/Edge executable detected';
                continue;
            }
            $command[] = $browser;
        }

        $timeout = max(5, (int) ($definition['timeout'] ?? 30));
        $process = run_process($command, $root, $timeout);
        audit_record_slow_check('Node ' . $name, (float) $process['duration']);
        $output = audit_process_output($process);
        if ($temporaryOutput !== null && is_file($temporaryOutput)) {
            @unlink($temporaryOutput);
        }

        if ($process['timed_out']) {
            $counts['failed']++;
            $problems[] = $name . ': timed out after ' . $timeout . ' seconds.';
            $log[] = '[FAIL] ' . $name . ' ' . format_duration((float) $process['duration']) . ' - timeout';
            $log[] = $output;
            continue;
        }
        if ((int) $process['exit_code'] !== 0) {
            $counts['failed']++;
            $problems[] = $name . ': exit code ' . $process['exit_code'] . '.';
            $log[] = '[FAIL] ' . $name . ' ' . format_duration((float) $process['duration']) . ' exit=' . $process['exit_code'];
            $log[] = $output;
            continue;
        }
        if (output_is_skip($output)) {
            $counts['skipped']++;
            $skipReason = $output !== '' ? preg_replace('/\s+/', ' ', trim($output)) : 'test reported SKIP';
            $gaps[] = $name . ': ' . $skipReason;
            $log[] = '[SKIP] ' . $name . ' ' . format_duration((float) $process['duration']) . ($output !== '' ? ' - ' . preg_replace('/\s+/', ' ', trim($output)) : '');
            continue;
        }
        $counts['passed']++;
        $log[] = '[PASS] ' . $name . ' ' . format_duration((float) $process['duration']);
    }

    $status = $counts['failed'] > 0 ? STATUS_FAIL : ($counts['blocked'] > 0 ? STATUS_BLOCKED : STATUS_PASS);
    if ($counts['passed'] === 0 && $counts['failed'] === 0 && $counts['blocked'] === 0 && $counts['skipped'] > 0) {
        $status = STATUS_SKIP;
    }
    $summary = $counts['passed'] . ' pass / ' . $counts['failed'] . ' fail / ' . $counts['skipped'] . ' skip / ' . $counts['blocked'] . ' blocked';
    $logPath = audit_write_log($suiteId, $log);
    return task_result($suiteId, $label, $status, microtime(true) - $started, $counts, $summary, $logPath, ['problems' => $problems, 'gaps' => $gaps]);
}

/**
 * Run the Windows companion application's Python unittest tree.
 *
 * @param ?array $python Python command prefix.
 * @return array Normalized task result.
 */
function audit_run_winapp(?array $python): array
{
    global $root;
    $started = microtime(true);
    $testDirectory = $root . '/winapp/tests';
    if (!is_dir($testDirectory)) {
        return task_result('winapp', 'WinApp regression', STATUS_SKIP, 0.0, [], 'winapp/tests is not present in this source tree.');
    }
    if ($python === null) {
        return task_result('winapp', 'WinApp regression', STATUS_BLOCKED, 0.0, [], 'Python executable was not found.', null, ['problems' => ['Set PHP_GALLERY_PYTHON or install Python 3 to execute WinApp regression tests.']]);
    }

    $command = array_merge($python, ['-m', 'unittest', 'discover', '-s', 'winapp/tests', '-p', 'test_*.py']);
    $process = run_process($command, $root, 90);
    audit_record_slow_check('Python WinApp unittest discovery', (float) $process['duration']);
    $output = audit_process_output($process);
    $logPath = audit_write_log('winapp', ['WinApp unittest discovery', $output]);
    $counts = parse_python_unittest_summary($output);
    if ($process['timed_out']) {
        return task_result('winapp', 'WinApp regression', STATUS_FAIL, (float) $process['duration'], $counts, 'Timed out after 90 seconds.', $logPath, ['problems' => ['Python unittest discovery timed out.']]);
    }
    $status = (int) $process['exit_code'] === 0 ? STATUS_PASS : STATUS_FAIL;
    if ($counts['total'] !== null && $counts['passed'] !== null) {
        $summary = $counts['passed'] . ' pass / ' . $counts['failed'] . ' fail / ' . $counts['errors'] . ' error / ' . $counts['skipped'] . ' skip';
    } else {
        $summary = 'exit code ' . $process['exit_code'];
    }
    return task_result('winapp', 'WinApp regression', $status, microtime(true) - $started, $counts, $summary, $logPath, $status === STATUS_FAIL ? ['problems' => ['WinApp unittest discovery failed.']] : []);
}

/**
 * Run one repository PHP utility as an audit task.
 *
 * @param string $id Stable task identifier.
 * @param string $label Human-readable label.
 * @param string $relativeScript Repository-relative script path.
 * @param array $arguments Script arguments.
 * @param int $timeout Timeout in seconds.
 * @return array Normalized task result.
 */
function audit_run_php_command(string $id, string $label, string $relativeScript, array $arguments = [], int $timeout = 45): array
{
    global $root;
    $path = $root . '/' . $relativeScript;
    if (!is_file($path)) {
        return task_result($id, $label, STATUS_BLOCKED, 0.0, [], $relativeScript . ' is missing.', null, ['problems' => [$relativeScript . ' is required for this audit task.']]);
    }
    $process = run_process(array_merge([PHP_BINARY, $path], $arguments), $root, $timeout);
    audit_record_slow_check($label, (float) $process['duration']);
    $output = audit_process_output($process);
    $logPath = audit_write_log($id, [$label, $output]);
    if ($process['timed_out']) {
        return task_result($id, $label, STATUS_FAIL, (float) $process['duration'], [], 'Timed out after ' . $timeout . ' seconds.', $logPath, ['problems' => [$relativeScript . ' timed out.']]);
    }
    if ((int) $process['exit_code'] !== 0) {
        return task_result($id, $label, STATUS_FAIL, (float) $process['duration'], [], 'Exit code ' . $process['exit_code'] . '.', $logPath, ['problems' => [$relativeScript . ' reported a failure.']]);
    }
    if (output_is_skip($output)) {
        return task_result($id, $label, STATUS_SKIP, (float) $process['duration'], [], preg_replace('/\s+/', ' ', trim($output)), $logPath);
    }
    return task_result($id, $label, STATUS_PASS, (float) $process['duration'], [], 'Passed.', $logPath);
}

/**
 * Return lint targets for full-repository or Git-changed mode.
 *
 * @param array $extensions File extensions without dots.
 * @param bool $changedOnly True to prefer Git-changed files.
 * @return array Array containing files and fallback metadata.
 */
function audit_lint_targets(array $extensions, bool $changedOnly): array
{
    global $root;
    $extensionMap = array_fill_keys(array_map('strtolower', $extensions), true);
    if ($changedOnly) {
        $changed = git_changed_paths($root);
        if ($changed !== null) {
            $files = [];
            foreach ($changed as $relative) {
                $absolute = $root . '/' . $relative;
                $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
                if (isset($extensionMap[$extension]) && is_file($absolute)) {
                    $files[] = $absolute;
                }
            }
            sort($files, SORT_STRING);
            return ['files' => array_values(array_unique($files)), 'fallback' => false];
        }
    }

    return [
        'files' => discover_files($root, $extensions, ['.git', '.idea', '.vscode', 'cache', 'data', 'galleries', 'deploy', 'node_modules', 'vendor', '__pycache__', '.pytest_cache', '.venv', 'venv']),
        'fallback' => $changedOnly,
    ];
}

/**
 * Run PHP syntax validation over selected source files.
 *
 * @param bool $changedOnly True to prefer Git-changed files.
 * @return array Normalized task result.
 */
function audit_run_php_lint(bool $changedOnly): array
{
    global $root;
    $id = $changedOnly ? 'php-lint-changed' : 'php-lint';
    $label = $changedOnly ? 'PHP syntax (changed)' : 'PHP syntax';
    $started = microtime(true);
    $targets = audit_lint_targets(['php'], $changedOnly);
    $files = $targets['files'];
    $failed = [];
    $log = [$label];

    foreach ($files as $file) {
        $process = run_process([PHP_BINARY, '-l', $file], $root, 15);
        audit_record_slow_check('PHP lint ' . relative_path($file, $root), (float) $process['duration']);
        if ((int) $process['exit_code'] !== 0 || $process['timed_out']) {
            $failed[] = relative_path($file, $root);
            $log[] = '[FAIL] ' . relative_path($file, $root);
            $log[] = audit_process_output($process);
        } else {
            $log[] = '[PASS] ' . relative_path($file, $root);
        }
    }

    $status = $failed === [] ? STATUS_PASS : STATUS_FAIL;
    $summary = count($files) . ' files' . ($targets['fallback'] ? ' (Git unavailable, linted full tree)' : '') . ($failed !== [] ? ', ' . count($failed) . ' failed' : '');
    $logPath = audit_write_log($id, $log);
    return task_result($id, $label, $status, microtime(true) - $started, ['total' => count($files), 'failed' => count($failed)], $summary, $logPath, ['problems' => array_map(static fn(string $file): string => $file . ': PHP syntax check failed.', $failed)]);
}

/**
 * Run Node syntax validation over selected JavaScript and MJS source files.
 *
 * @param bool $changedOnly True to prefer Git-changed files.
 * @param ?string $node Node executable.
 * @return array Normalized task result.
 */
function audit_run_js_lint(bool $changedOnly, ?string $node): array
{
    global $root;
    $id = $changedOnly ? 'js-lint-changed' : 'js-lint';
    $label = $changedOnly ? 'JavaScript syntax (changed)' : 'JavaScript syntax';
    if ($node === null) {
        return task_result($id, $label, STATUS_BLOCKED, 0.0, [], 'Node executable was not found.', null, ['problems' => ['Node is required for JavaScript syntax validation.']]);
    }

    $started = microtime(true);
    $targets = audit_lint_targets(['js', 'mjs'], $changedOnly);
    $files = $targets['files'];
    $failed = [];
    $log = [$label];
    foreach ($files as $file) {
        $process = run_process([$node, '--check', $file], $root, 15);
        audit_record_slow_check('JS lint ' . relative_path($file, $root), (float) $process['duration']);
        if ((int) $process['exit_code'] !== 0 || $process['timed_out']) {
            $failed[] = relative_path($file, $root);
            $log[] = '[FAIL] ' . relative_path($file, $root);
            $log[] = audit_process_output($process);
        } else {
            $log[] = '[PASS] ' . relative_path($file, $root);
        }
    }

    $status = $failed === [] ? STATUS_PASS : STATUS_FAIL;
    $summary = count($files) . ' files' . ($targets['fallback'] ? ' (Git unavailable, linted full tree)' : '') . ($failed !== [] ? ', ' . count($failed) . ' failed' : '');
    $logPath = audit_write_log($id, $log);
    return task_result($id, $label, $status, microtime(true) - $started, ['total' => count($files), 'failed' => count($failed)], $summary, $logPath, ['problems' => array_map(static fn(string $file): string => $file . ': JavaScript syntax check failed.', $failed)]);
}

/**
 * Run git diff --check when the audit is executing inside a Git checkout.
 *
 * @param ?string $git Git executable.
 * @return array Normalized task result.
 */
function audit_run_git_diff_check(?string $git): array
{
    global $root;
    if ($git === null || !is_dir($root . '/.git')) {
        return task_result('git-diff-check', 'Git whitespace check', STATUS_SKIP, 0.0, [], 'Git checkout metadata is unavailable in this source package.');
    }
    $process = run_process([$git, 'diff', '--check'], $root, 30);
    $output = audit_process_output($process);
    $logPath = audit_write_log('git-diff-check', ['git diff --check', $output]);
    return task_result(
        'git-diff-check',
        'Git whitespace check',
        (int) $process['exit_code'] === 0 ? STATUS_PASS : STATUS_FAIL,
        (float) $process['duration'],
        [],
        (int) $process['exit_code'] === 0 ? 'Passed.' : 'Whitespace errors detected.',
        $logPath,
        (int) $process['exit_code'] === 0 ? [] : ['problems' => ['git diff --check reported whitespace errors.']]
    );
}

/**
 * Return the console label used while a suite is running.
 *
 * @param string $suiteId Stable suite identifier.
 * @return string Human-readable suite label.
 */
function audit_suite_console_label(string $suiteId): string
{
    return match ($suiteId) {
        'php-regression' => 'PHP regression',
        'node-fast' => 'Node regression (fast)',
        'node-full' => 'Node regression',
        'browser-map' => 'Chromium map integration',
        'winapp' => 'WinApp regression',
        'mutation-contracts' => 'Admin mutation contracts',
        'version-audit' => 'Runtime hardening audit',
        'php-lint' => 'PHP syntax',
        'php-lint-changed' => 'PHP syntax (changed)',
        'js-lint' => 'JavaScript syntax',
        'js-lint-changed' => 'JavaScript syntax (changed)',
        'manifest' => 'Core manifest freshness',
        'git-diff-check' => 'Git whitespace check',
        default => $suiteId,
    };
}

$suiteCount = count($suiteIds);
fwrite(STDOUT, 'PHP Gallery audit | Profile: ' . $profileLabel . ' | Suites: ' . $suiteCount . "\n");
fwrite(STDOUT, str_repeat('=', 72) . "\n");
fflush(STDOUT);

foreach ($suiteIds as $suiteIndex => $suiteId) {
    $position = $suiteIndex + 1;
    $consoleLabel = audit_suite_console_label($suiteId);
    fwrite(STDOUT, '[' . $position . '/' . $suiteCount . '] RUN     ' . $consoleLabel . "\n");
    fflush(STDOUT);

    $task = match ($suiteId) {
        'php-regression' => audit_run_php_regression($registry),
        'node-fast' => audit_run_node_suite('node-fast', 'Node regression (fast)', audit_select_node_tests($registry, false), $node),
        'node-full' => audit_run_node_suite('node-full', 'Node regression', audit_select_node_tests($registry, true), $node),
        'browser-map' => audit_run_node_suite('browser-map', 'Chromium map integration', audit_select_node_tests($registry, true, true), $node, $browser),
        'winapp' => audit_run_winapp($python),
        'mutation-contracts' => audit_run_php_command('mutation-contracts', 'Admin mutation contracts', 'scripts/check_admin_mutation_contracts.php'),
        'version-audit' => audit_run_php_command('version-audit', 'Runtime hardening audit', 'tests/version_094_audit_hardening.php'),
        'php-lint' => audit_run_php_lint(false),
        'php-lint-changed' => audit_run_php_lint(true),
        'js-lint' => audit_run_js_lint(false, $node),
        'js-lint-changed' => audit_run_js_lint(true, $node),
        'manifest' => audit_run_php_command('manifest', 'Core manifest freshness', 'scripts/generate_manifest.php', ['--check'], 60),
        'git-diff-check' => audit_run_git_diff_check($git),
    };
    $tasks[] = $task;

    $statusText = str_pad((string) $task['status'], 8);
    fwrite(
        STDOUT,
        '[' . $position . '/' . $suiteCount . '] ' . $statusText . ' ' . $task['label'] . ' '
        . format_duration((float) $task['duration_seconds']) . '  ' . $task['summary'] . "\n"
    );
    fflush(STDOUT);
}

usort(
    $slowChecks,
    static fn(array $left, array $right): int => $right['duration_seconds'] <=> $left['duration_seconds']
);
$slowChecks = array_slice($slowChecks, 0, 10);
$status = overall_status($tasks);
$duration = microtime(true) - $startedClock;

$reportRoot = $root . '/cache/test-audit';
$defaultMarkdown = $reportRoot . '/latest.md';
$defaultJson = $reportRoot . '/latest.json';
$markdownPath = $options['report'] !== null
    ? (str_starts_with((string) $options['report'], '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', (string) $options['report']) === 1 ? (string) $options['report'] : $root . '/' . $options['report'])
    : $defaultMarkdown;
$jsonPath = $options['json'] !== null
    ? (str_starts_with((string) $options['json'], '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', (string) $options['json']) === 1 ? (string) $options['json'] : $root . '/' . $options['json'])
    : $defaultJson;

$report = [
    'schema_version' => 1,
    'status' => $status,
    'profile' => $profileLabel,
    'started_at' => $startedAt->format(DATE_ATOM),
    'duration_seconds' => round($duration, 4),
    'environment' => $environment,
    'tasks' => $tasks,
    'slowest' => $slowChecks,
    'report_files' => [
        'markdown' => relative_path($markdownPath, $root),
        'json' => relative_path($jsonPath, $root),
        'run_directory' => relative_path($runDirectory, $root),
    ],
];

if ($persistReports) {
    $runJsonPath = $runDirectory . '/report.json';
    $runMarkdownPath = $runDirectory . '/report.md';
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        fwrite(STDERR, "Unable to encode audit JSON report.\n");
        exit(2);
    }
    write_text_file($runJsonPath, $json . "\n");
    write_text_file($runMarkdownPath, render_markdown_report($report));
    write_text_file($jsonPath, $json . "\n");
    write_text_file($markdownPath, render_markdown_report($report));
}

fwrite(STDOUT, str_repeat('-', 72) . "\n");
fwrite(STDOUT, 'Result: ' . $status . ' | Profile: ' . $profileLabel . ' | Duration: ' . format_duration($duration) . "\n");
if ($persistReports) {
    fwrite(STDOUT, 'Report: ' . relative_path($markdownPath, $root) . "\n");
} else {
    @rmdir($runDirectory);
}

exit(exit_code_for_status($status));
