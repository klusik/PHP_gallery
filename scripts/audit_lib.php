<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/audit_lib.php
 * Module Type: Audit Support Library
 *
 * Purpose:
 *   Provides process, discovery, reporting, and environment helpers for the central audit runner.
 *
 * Responsibilities:
 *   - Execute subprocesses with bounded time and captured output
 *   - Discover lint targets deterministically
 *   - Detect optional development executables across supported host platforms
 *   - Normalize audit status and report data
 *   - Write compact Markdown and machine-readable JSON reports
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
 *   - This library must remain compatible with PHP 8.1 and must not require Composer.
 *
 * Last Updated:
 *   2026-09-05
 */

declare(strict_types=1);

namespace PhpGallery\Audit;

const STATUS_PASS = 'PASS';
const STATUS_FAIL = 'FAIL';
const STATUS_SKIP = 'SKIP';
const STATUS_BLOCKED = 'BLOCKED';

/**
 * Extract a bounded, single-line failure reason from a Node fixture's output.
 *
 * @param string $output Captured stdout and stderr from the owned child.
 * @return string Assertion or runtime error text, empty when no recognized reason exists.
 */
function node_failure_detail(string $output): string
{
    $output = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $output) ?? $output;
    if (preg_match('/^(?:BROWSER FAIL(?: #[0-9]+)?|AssertionError(?: \[[^\r\n]+\])?|Error|TypeError|SyntaxError|RangeError):[^\r\n]*/m', $output, $matches) !== 1) {
        return '';
    }
    $detail = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $matches[0]) ?? '';
    return strlen($detail) > 1000 ? substr($detail, 0, 997) . '...' : $detail;
}

/**
 * Render actionable failure details without flooding the audit console.
 *
 * @param array<string,mixed> $task Normalized suite result and its recorded problems.
 * @return string At most five bounded problem lines and a pointer to any remaining details.
 */
function render_console_problems(array $task): string
{
    if (!in_array($task['status'] ?? '', [STATUS_FAIL, STATUS_BLOCKED], true)) {
        return '';
    }
    $problems = $task['details']['problems'] ?? [];
    $lines = [];
    foreach (array_slice($problems, 0, 5) as $problem) {
        $line = preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $problem) ?? '';
        $lines[] = '  - ' . (strlen($line) > 1200 ? substr($line, 0, 1197) . '...' : $line);
    }
    if (count($problems) > 5) {
        $lines[] = '  - ' . (count($problems) - 5) . ' more problems; see the complete evidence linked in the report.';
    }
    if ($lines !== [] && !empty($task['log'])) {
        $lines[] = '  Log: ' . $task['log'];
    }
    return $lines === [] ? '' : implode("\n", $lines) . "\n";
}

require_once __DIR__ . '/audit_process.php';
require_once __DIR__ . '/audit_performance.php';

/**
 * Bind release consistency to the tree actually observed before and after tests.
 * Missing identity is blocked; content changing during a run is a release failure.
 */
function bind_release_source_identity(array $tasks, ?array $before, ?array $after): array
{
    $missing = !isset($before['fingerprint'], $after['fingerprint']);
    if (!$missing && hash_equals($before['fingerprint'], $after['fingerprint'])) {
        return $tasks;
    }
    foreach ($tasks as &$task) {
        if ($task['id'] !== 'release-consistency') {
            continue;
        }
        $message = $missing
            ? 'Release source identity could not be captured; qualification cannot bind this audit.'
            : 'Release inputs changed during the audit; regenerate integrity data and rerun release.';
        if ($task['status'] !== STATUS_FAIL) {
            $task['status'] = $missing ? STATUS_BLOCKED : STATUS_FAIL;
        }
        $counter = $missing ? 'blocked' : 'failed';
        $task['counts'][$counter] = ($task['counts'][$counter] ?? 0) + 1;
        $task['summary'] .= ' ' . $message;
        $task['details']['problems'][] = $message;
    }
    unset($task);
    return $tasks;
}

/**
 * Return the repository root for the audit tooling.
 *
 * @return string Absolute repository path.
 */
function project_root(): string
{
    return dirname(__DIR__);
}

/**
 * Normalize a repository-relative path to forward slashes.
 *
 * @param string $path Filesystem path.
 * @param ?string $rootPath Optional repository root.
 * @return string Normalized path.
 */
function relative_path(string $path, ?string $rootPath = null): string
{
    $root = str_replace('\\', '/', rtrim($rootPath ?? project_root(), '/\\'));
    $normalized = str_replace('\\', '/', $path);
    if (str_starts_with($normalized, $root . '/')) {
        return substr($normalized, strlen($root) + 1);
    }

    return ltrim($normalized, '/');
}

/**
 * Parse the supported audit command-line options.
 *
 * @param array $arguments Full argv array.
 * @return array Structured option values.
 */
function parse_options(array $arguments): array
{
    $options = [
        'profile' => 'full',
        'suite' => null,
        'report' => null,
        'json' => null,
        'no_report' => false,
        'help' => false,
        'changed' => false,
    ];

    for ($index = 1, $count = count($arguments); $index < $count; $index++) {
        $argument = (string) $arguments[$index];
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($argument === '--no-report') {
            $options['no_report'] = true;
            continue;
        }
        if ($argument === '--changed') {
            $options['changed'] = true;
            continue;
        }
        foreach (['profile', 'suite', 'report', 'json'] as $name) {
            $prefix = '--' . $name . '=';
            if (str_starts_with($argument, $prefix)) {
                $options[$name] = substr($argument, strlen($prefix));
                continue 2;
            }
            if ($argument === '--' . $name && isset($arguments[$index + 1])) {
                $options[$name] = (string) $arguments[++$index];
                continue 2;
            }
        }
        throw new \InvalidArgumentException('Unknown audit option: ' . $argument);
    }

    return $options;
}

/**
 * Return true when the audit output explicitly represents a skipped test.
 *
 * @param string $output Captured process output.
 * @return bool True when the process intentionally skipped coverage.
 */
function output_is_skip(string $output): bool
{
    return preg_match('/(^|\R)\s*SKIP(?:\s|:)/i', $output) === 1;
}

/**
 * Parse the standard Python unittest summary into normalized audit counts.
 *
 * Successful unittest output ends with `OK` and failed output uses a `FAILED (...)`
 * trailer containing counters such as failures, errors, or skipped tests. Keeping
 * this parsing in the audit support library lets the console report useful counts
 * without exposing the full Python test log.
 *
 * @param string $output Combined unittest stdout and stderr.
 * @return array Normalized total, passed, failed, errors, and skipped counters.
 */
function parse_python_unittest_summary(string $output): array
{
    $counts = [
        'total' => null,
        'passed' => null,
        'failed' => 0,
        'errors' => 0,
        'skipped' => 0,
    ];

    if (preg_match('/Ran\s+(\d+)\s+tests?/i', $output, $ranMatch) === 1) {
        $counts['total'] = (int) $ranMatch[1];
    }

    if (preg_match('/(?:FAILED|OK)\s*\(([^)]*)\)/i', $output, $trailerMatch) === 1) {
        foreach ([
            'failures' => 'failed',
            'errors' => 'errors',
            'skipped' => 'skipped',
        ] as $sourceName => $targetName) {
            if (preg_match('/(?:^|,\s*)' . preg_quote($sourceName, '/') . '=(\d+)/i', $trailerMatch[1], $countMatch) === 1) {
                $counts[$targetName] = (int) $countMatch[1];
            }
        }
    }

    if ($counts['total'] !== null) {
        $counts['passed'] = max(0, $counts['total'] - $counts['failed'] - $counts['errors'] - $counts['skipped']);
    }

    return $counts;
}

/**
 * Convert seconds to a compact human-readable duration.
 *
 * @param float $seconds Duration in seconds.
 * @return string Formatted duration.
 */
function format_duration(float $seconds): string
{
    if ($seconds < 0.01) {
        return '<0.01 s';
    }
    return number_format($seconds, 2, '.', '') . ' s';
}

/**
 * Return an executable path found on PATH or null when unavailable.
 *
 * @param string $name Executable name or explicit path.
 * @return ?string Resolved executable path.
 */
function find_executable(string $name): ?string
{
    if ($name === '') {
        return null;
    }

    if (str_contains($name, '/') || str_contains($name, '\\')) {
        return is_file($name) ? $name : null;
    }

    $pathValue = (string) getenv('PATH');
    if ($pathValue === '') {
        return null;
    }

    $extensions = [''];
    if (DIRECTORY_SEPARATOR === '\\') {
        $pathExtensions = (string) getenv('PATHEXT');
        $extensions = $pathExtensions !== ''
            ? array_values(array_filter(array_map('trim', explode(';', $pathExtensions))))
            : ['.EXE', '.BAT', '.CMD'];
        array_unshift($extensions, '');
    }

    foreach (explode(PATH_SEPARATOR, $pathValue) as $directory) {
        $directory = trim($directory, " \t\n\r\0\x0B\"");
        if ($directory === '') {
            continue;
        }
        foreach ($extensions as $extension) {
            $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name . $extension;
            if (is_file($candidate) && (DIRECTORY_SEPARATOR === '\\' || is_executable($candidate))) {
                return $candidate;
            }
        }
    }

    return null;
}

/**
 * Resolve an executable using an environment override and fallback names.
 *
 * @param string $environmentVariable Override environment variable.
 * @param array $fallbackNames Executable names to try in order.
 * @return ?string Resolved executable path.
 */
function resolve_executable(string $environmentVariable, array $fallbackNames): ?string
{
    $override = trim((string) getenv($environmentVariable));
    if ($override !== '') {
        return find_executable($override);
    }
    foreach ($fallbackNames as $name) {
        $resolved = find_executable((string) $name);
        if ($resolved !== null) {
            return $resolved;
        }
    }
    return null;
}

/**
 * Return true when a command prefix starts a usable Python 3 interpreter.
 *
 * The probe deliberately executes the command instead of relying only on filesystem inspection.
 * Windows App Execution Aliases and launchers can be runnable from PATH even when PHP's is_file()
 * does not treat the exposed alias as a normal executable file.
 *
 * @param array $commandPrefix Executable plus any launcher arguments that precede --version.
 * @param ?callable $runner Optional process runner used by regression tests.
 * @return bool True when the command reports a Python 3 version successfully.
 */
function python_command_is_usable(array $commandPrefix, ?callable $runner = null): bool
{
    if ($commandPrefix === []) {
        return false;
    }

    $processRunner = $runner ?? static fn(array $command): array => run_process($command, project_root(), 5);
    $process = $processRunner(array_merge($commandPrefix, ['--version']));
    if (!is_array($process) || (int) ($process['exit_code'] ?? 127) !== 0 || !empty($process['timed_out'])) {
        return false;
    }

    $output = trim((string) ($process['stdout'] ?? '') . "\n" . (string) ($process['stderr'] ?? ''));
    return preg_match('/(?:^|\s)Python\s+3(?:\.\d+){1,2}(?:\s|$)/i', $output) === 1;
}

/**
 * Resolve the first runnable Python 3 command from ordered candidates.
 *
 * A filesystem resolver is still used when possible so normal installations are reported with
 * their concrete executable path. If that lookup returns null, the original command name is still
 * probed because Windows can resolve App Execution Aliases that are not visible as ordinary files.
 *
 * @param array $candidates Ordered command prefixes such as ['python'] or ['py', '-3'].
 * @param ?callable $finder Optional executable resolver used by regression tests.
 * @param ?callable $probe Optional command probe used by regression tests.
 * @return ?array Resolved runnable command prefix, or null when no Python 3 command works.
 */
function resolve_python_command_from_candidates(array $candidates, ?callable $finder = null, ?callable $probe = null): ?array
{
    $executableFinder = $finder ?? static fn(string $name): ?string => find_executable($name);
    $commandProbe = $probe ?? static fn(array $commandPrefix): bool => python_command_is_usable($commandPrefix);

    foreach ($candidates as $candidate) {
        if (!is_array($candidate) || $candidate === []) {
            continue;
        }

        $command = array_values(array_map('strval', $candidate));
        $executableName = $command[0];
        if ($executableName === '') {
            continue;
        }

        $resolved = $executableFinder($executableName);
        if (is_string($resolved) && $resolved !== '') {
            $command[0] = $resolved;
        }

        if ($commandProbe($command)) {
            return $command;
        }
    }

    return null;
}

/**
 * Return the preferred Python command prefix for the current host.
 *
 * @return ?array Command prefix, or null when Python is unavailable.
 */
function resolve_python_command(): ?array
{
    $override = trim((string) getenv('PHP_GALLERY_PYTHON'));
    if ($override !== '') {
        return resolve_python_command_from_candidates([[$override]]);
    }

    return resolve_python_command_from_candidates([
        ['python3'],
        ['python'],
        ['py', '-3'],
    ]);
}

/**
 * Return an installed Chromium-family browser path when one can be identified safely.
 *
 * PHP_GALLERY_BROWSER=disabled explicitly opts out without automatic discovery.
 * @return ?string Browser executable path, or null when explicitly disabled or unavailable.
 */
function resolve_browser_executable(): ?string
{
    $override = trim((string) getenv('PHP_GALLERY_BROWSER'));
    if ($override === 'disabled') {
        return null;
    }
    if ($override !== '') {
        return find_executable($override);
    }

    // Chromium's normal sandbox refuses root execution. Do not auto-select a browser in root-owned
    // CI/agent containers; release reporting records browser coverage as SKIP instead of weakening sandboxing.
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        return null;
    }

    foreach (['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser', 'microsoft-edge', 'msedge'] as $name) {
        $resolved = find_executable($name);
        if ($resolved !== null) {
            return $resolved;
        }
    }

    $candidates = DIRECTORY_SEPARATOR === '\\'
        ? [
            getenv('PROGRAMFILES') . '\\Google\\Chrome\\Application\\chrome.exe',
            getenv('PROGRAMFILES(X86)') . '\\Google\\Chrome\\Application\\chrome.exe',
            getenv('LOCALAPPDATA') . '\\Google\\Chrome\\Application\\chrome.exe',
            getenv('PROGRAMFILES') . '\\Microsoft\\Edge\\Application\\msedge.exe',
            getenv('PROGRAMFILES(X86)') . '\\Microsoft\\Edge\\Application\\msedge.exe',
        ]
        : [
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
            '/usr/bin/google-chrome',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
        ];

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Execute a process with captured output and a hard timeout.
 *
 * @param array<int,string> $command Executable followed by arguments.
 * @param string $cwd Working directory.
 * @param int $timeoutSeconds Timeout in seconds.
 * @return array{exit_code:int,stdout:string,stderr:string,timed_out:bool,blocked?:bool,duration:float} Process result.
 */
function run_process(array $command, string $cwd, int $timeoutSeconds = 30): array
{
    return run_process_pool([['command' => $command, 'timeout' => $timeoutSeconds]], $cwd, 1)[0];
}

/**
 * Check each source file in an isolated child within the shared worker limit.
 *
 * @param list<string> $files Ordered absolute source paths, passed as literal arguments.
 * @param list<string> $commandPrefix Syntax checker executable and flags.
 * @param string $cwd Child working directory.
 * @param int $workers Maximum concurrent children from one through eight.
 * @param int $timeoutSeconds Independent deadline in seconds for each started checker. Rationale: bound syntax-only children to the existing 15-second lint budget.
 * @return list<array<string,mixed>> Captured checker outcomes in source-path order.
 */
function run_file_checks(array $files, array $commandPrefix, string $cwd, int $workers, int $timeoutSeconds): array
{
    $jobs = array_map(static fn(string $file): array => [
        'command' => array_merge($commandPrefix, [$file]), 'timeout' => $timeoutSeconds,
    ], $files);
    return run_process_pool($jobs, $cwd, $workers);
}

/**
 * Return deterministic files with one of the requested extensions.
 *
 * @param string $rootPath Repository root.
 * @param array $extensions Lowercase extensions without a dot.
 * @param array $excludedDirectories Directory basenames excluded anywhere.
 * @return array Absolute file paths.
 */
function discover_files(string $rootPath, array $extensions, array $excludedDirectories = []): array
{
    $extensionMap = array_fill_keys(array_map('strtolower', $extensions), true);
    $excludedMap = array_fill_keys($excludedDirectories, true);
    $files = [];
    $directory = new \RecursiveDirectoryIterator($rootPath, \FilesystemIterator::SKIP_DOTS);
    $filter = new \RecursiveCallbackFilterIterator(
        $directory,
        static function (\SplFileInfo $current) use ($excludedMap): bool {
            if ($current->isDir() && isset($excludedMap[$current->getBasename()])) {
                return false;
            }
            return true;
        }
    );
    $iterator = new \RecursiveIteratorIterator($filter);

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
            continue;
        }
        $extension = strtolower($fileInfo->getExtension());
        if (isset($extensionMap[$extension])) {
            $files[] = $fileInfo->getPathname();
        }
    }
    sort($files, SORT_STRING);
    return $files;
}

/**
 * Return changed paths reported by Git, optionally from an immutable base commit.
 *
 * @param string $rootPath Repository root.
 * @param ?string $baseSha Full commit SHA whose committed tree is the comparison base; null compares the worktree with HEAD.
 * @return list<string>|null Repository-relative paths, or null when Git evidence is unavailable or incomplete.
 */
function git_changed_paths(string $rootPath, ?string $baseSha = null): ?array
{
    $git = resolve_executable('PHP_GALLERY_GIT', ['git']);
    if ($git === null || !is_dir($rootPath . '/.git')) {
        return null;
    }

    if ($baseSha !== null) {
        if (preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D', $baseSha) !== 1) {
            return null;
        }
        $resolvedBase = run_process([$git, 'rev-parse', '--verify', $baseSha . '^{commit}'], $rootPath, 15);
        if ($resolvedBase['exit_code'] !== 0 || trim($resolvedBase['stdout']) !== $baseSha) {
            return null;
        }
        $ancestry = run_process([$git, 'merge-base', '--is-ancestor', $baseSha, 'HEAD'], $rootPath, 15);
        if ($ancestry['exit_code'] !== 0) {
            return null;
        }
        $committed = run_process([$git, 'diff', '--name-only', '-z', '--no-renames', $baseSha, 'HEAD', '--'], $rootPath, 15);
        $tracked = run_process([$git, 'diff', '--name-only', '-z', 'HEAD', '--'], $rootPath, 15);
        if ($committed['exit_code'] !== 0) {
            return null;
        }
    } else {
        $committed = ['stdout' => ''];
        $tracked = run_process([$git, 'diff', '--name-only', '-z', 'HEAD'], $rootPath, 15);
    }
    $untracked = run_process([$git, 'ls-files', '--others', '--exclude-standard', '-z'], $rootPath, 15);
    if ($tracked['exit_code'] !== 0 || $untracked['exit_code'] !== 0) {
        return null;
    }

    $paths = [];
    foreach ([$committed['stdout'], $tracked['stdout'], $untracked['stdout']] as $output) {
        foreach (explode("\0", $output) as $path) {
            $path = str_replace('\\', '/', trim($path));
            if ($path !== '') {
                $paths[] = $path;
            }
        }
    }

    return array_values(array_unique($paths));
}

/**
 * Return a single task result in the normalized report shape.
 *
 * @param string $id Stable task identifier.
 * @param string $label Human-readable task label.
 * @param string $status PASS, FAIL, SKIP, or BLOCKED.
 * @param float $duration Duration in seconds.
 * @param array $counts Optional result counts.
 * @param string $summary Compact result summary.
 * @param ?string $logPath Repository-relative detailed log path.
 * @param array $details Additional structured details.
 * @return array Normalized task result.
 */
function task_result(
    string $id,
    string $label,
    string $status,
    float $duration,
    array $counts = [],
    string $summary = '',
    ?string $logPath = null,
    array $details = []
): array {
    return [
        'id' => $id,
        'label' => $label,
        'status' => $status,
        'duration_seconds' => round($duration, 4),
        'counts' => $counts,
        'summary' => $summary,
        'log' => $logPath,
        'details' => $details,
    ];
}


/**
 * Identify mandatory PHP regression children without an unambiguous successful result.
 *
 * The central audit may report PASS while unrelated optional children SKIP.
 * Required disposable-database coverage must instead have an individual PASS,
 * a zero child exit code, and no timeout in the structured report.
 *
 * @param array<string,mixed> $regression Normalized php-fast or php-regression task.
 * @param list<string> $requiredTests Mandatory standalone PHP test basenames.
 * @return list<string> Names without verified PASS, or all required names for malformed evidence.
 */
function missing_required_php_test_passes(array $regression, array $requiredTests): array
{
    $tests = $regression['details']['tests'] ?? null;
    $reportedCounts = $regression['counts'] ?? null;
    if (!in_array($regression['id'] ?? null, ['php-fast', 'php-regression'], true)
        || ($regression['status'] ?? null) !== STATUS_PASS
        || !is_array($tests) || !array_is_list($tests)
        || !is_array($reportedCounts)
        || ($reportedCounts['total'] ?? null) !== count($tests)) {
        return $requiredTests;
    }

    $observed = [];
    $counts = ['passed' => 0, 'failed' => 0, 'skipped' => 0, 'blocked' => 0];
    foreach ($tests as $test) {
        if (!is_array($test)
            || !is_string($test['name'] ?? null)
            || basename($test['name']) !== $test['name']
            || !str_ends_with($test['name'], '_test.php')
            || array_key_exists($test['name'], $observed)
            || !is_int($test['exit_code'] ?? null)
            || !is_bool($test['timed_out'] ?? null)) {
            return $requiredTests;
        }
        $counter = match ($test['status'] ?? null) {
            STATUS_PASS => 'passed',
            STATUS_FAIL => 'failed',
            STATUS_SKIP => 'skipped',
            STATUS_BLOCKED => 'blocked',
            default => null,
        };
        if ($counter === null) {
            return $requiredTests;
        }
        $counts[$counter]++;
        $observed[$test['name']] = $counter === 'passed'
            && $test['exit_code'] === 0 && !$test['timed_out'];
    }
    foreach ($counts as $counter => $value) {
        if (($reportedCounts[$counter] ?? null) !== $value) {
            return $requiredTests;
        }
    }

    $missing = [];
    foreach ($requiredTests as $testName) {
        if (($observed[$testName] ?? false) !== true) {
            $missing[] = $testName;
        }
    }
    return $missing;
}

/**
 * Delegate only the repeated generated-artifact freshness suite in an identified
 * hosted disposable database audit. The preflight is an existing required native
 * job on the same prepared candidate SHA, and full PHP 8.1/8.5 and release audits
 * continue to run the suite. Delegation is not a local PASS.
 *
 * @param array<string,list<string>> $profiles Central audit profile definitions.
 * @param string $profile Selected audit profile.
 * @param array<string,string> $environment Current process environment.
 * @return array{suites:list<string>,delegated_to_preflight:list<string>} Executed suites and delegation labels.
 */
function audit_hosted_full_suite_ownership(array $profiles, string $profile, array $environment): array
{
    $suites = $profiles[$profile] ?? [];
    $result = ['suites' => $suites, 'delegated_to_preflight' => []];
    $requiredEnvironment = [
        'GITHUB_ACTIONS' => 'true',
        'GITHUB_REPOSITORY' => 'klusik/PHP_gallery',
        'GALLERY_WORKFLOW_ENABLE' => 'disposable-only',
        'GALLERY_WORKFLOW_REQUIRED' => '1',
        'GALLERY_WORKFLOW_DB_HOST' => '127.0.0.1',
        'GALLERY_WORKFLOW_DB_PORT' => '13316',
        'GALLERY_WORKFLOW_DB_USER' => 'gallery_workflow_runner',
        'GALLERY_RUNTIME_NORMALIZATION' => 'unicode',
        'GALLERY_SESSION_REQUIRED' => '1',
    ];
    if ($profile !== 'full') {
        return $result;
    }
    foreach ($requiredEnvironment as $name => $value) {
        if (($environment[$name] ?? null) !== $value) {
            return $result;
        }
    }
    // Every retained mandatory owner must still contain exactly one manifest.
    foreach (['candidate-preflight', 'full', 'release'] as $owner) {
        $ownedSuites = $profiles[$owner] ?? null;
        if (!is_array($ownedSuites) || count(array_keys($ownedSuites, 'manifest', true)) !== 1) {
            return $result;
        }
    }
    $result['suites'] = array_values(array_diff($suites, ['manifest']));
    $result['delegated_to_preflight'] = ['manifest'];
    return $result;
}

/**
 * Require exactly one successful native central-audit suite result.
 *
 * An aggregate PASS must not conceal a skipped, missing, failed or duplicated
 * generated-artifact freshness check in the required candidate preflight.
 *
 * @param list<array<string,mixed>> $tasks Executed central audit tasks.
 * @param string $suiteId Required suite ID.
 * @return bool Whether the suite was observed exactly once with an individual PASS.
 */
function audit_required_suite_pass(array $tasks, string $suiteId): bool
{
    $observed = 0;
    foreach ($tasks as $task) {
        if (!is_array($task)) {
            return false;
        }
        if (($task['id'] ?? null) !== $suiteId) {
            continue;
        }
        $observed++;
        if (($task['status'] ?? null) !== STATUS_PASS) {
            return false;
        }
    }
    return $observed === 1;
}

/**
 * Verify that a hosted database audit which delegated manifest freshness has
 * not changed its checked-out candidate or comparison ref while tests ran.
 *
 * The required candidate preflight owns generator freshness before DB tests.
 * This separate end-of-audit proof protects tracked and non-ignored workspace
 * integrity after those tests without running the generators a second time.
 * It does not authenticate the mutable GitHub Actions workflow publisher.
 *
 * @param array<string,mixed> $before Git source identity captured before suites.
 * @param array<string,mixed> $after Git source identity captured after suites.
 * @return array{status:string,reason:string} Fail-closed integrity result.
 */
function audit_delegated_checkout_integrity(array $before, array $after): array
{
    foreach ([$before, $after] as $identity) {
        $head = $identity['head_sha'] ?? null;
        $base = $identity['comparison_base'] ?? null;
        $requestedBase = $identity['requested_base'] ?? null;
        if (!is_string($head) || preg_match('/^[a-f0-9]{40,64}$/D', $head) !== 1
            || !is_string($base) || preg_match('/^[a-f0-9]{40,64}$/D', $base) !== 1
            || !is_string($requestedBase) || $requestedBase === ''
            || !is_bool($identity['worktree_dirty'] ?? null)) {
            return ['status' => STATUS_BLOCKED, 'reason' => 'git-identity-unavailable'];
        }
    }

    if ($before['worktree_dirty']) {
        return ['status' => STATUS_FAIL, 'reason' => 'checkout-dirty-at-start'];
    }
    if ($before['head_sha'] !== $after['head_sha']
        || $before['comparison_base'] !== $after['comparison_base']
        || $before['requested_base'] !== $after['requested_base']) {
        return ['status' => STATUS_FAIL, 'reason' => 'checkout-identity-changed-during-tests'];
    }
    if ($after['worktree_dirty']) {
        return ['status' => STATUS_FAIL, 'reason' => 'checkout-dirty-after-tests'];
    }

    return ['status' => STATUS_PASS, 'reason' => 'unchanged-clean-checkout'];
}

/**
 * Return the overall status for a set of normalized task results.
 *
 * @param array $tasks Task results.
 * @return string Overall status.
 */
function overall_status(array $tasks): string
{
    foreach ($tasks as $task) {
        if (($task['status'] ?? '') === STATUS_FAIL) {
            return STATUS_FAIL;
        }
    }
    foreach ($tasks as $task) {
        if (($task['status'] ?? '') === STATUS_BLOCKED) {
            return STATUS_BLOCKED;
        }
    }
    return STATUS_PASS;
}

/**
 * Return the process exit code for an overall audit status.
 *
 * @param string $status Overall status.
 * @return int Process exit code.
 */
function exit_code_for_status(string $status): int
{
    if ($status === STATUS_FAIL) {
        return 1;
    }
    if ($status === STATUS_BLOCKED) {
        return 2;
    }
    return 0;
}

/**
 * Write text atomically enough for local audit reporting.
 *
 * @param string $path Destination path.
 * @param string $contents File contents.
 * @return void
 */
function write_text_file(string $path, string $contents): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new \RuntimeException('Unable to create report directory: ' . $directory);
    }
    if (file_put_contents($path, $contents) === false) {
        throw new \RuntimeException('Unable to write report: ' . $path);
    }
}

/**
 * Return a compact Markdown report for the completed audit.
 *
 * @param array<string,mixed> $report Structured audit report.
 * @return string Markdown document.
 */
function render_markdown_report(array $report): string
{
    $lines = [
        '# PHP Gallery Audit',
        '',
        '- Result: **' . $report['status'] . '**',
        '- Profile: `' . $report['profile'] . '`',
        '- Started: `' . $report['started_at'] . '`',
        '- Duration: `' . format_duration((float) $report['duration_seconds']) . '`',
        '',
    ];

    if (isset($report['source'])) {
        $source = $report['source'];
        $lines[] = '- Source HEAD: `' . ($source['head_sha'] ?? 'unavailable') . '`';
        $lines[] = '- Comparison base: `' . ($source['comparison_base'] ?? 'unavailable') . '`';
        $lines[] = '- Working tree at start: ' . match ($source['worktree_dirty']) {
            true => 'modified (checkout feedback, not clean-HEAD qualification)',
            false => 'clean', default => 'unavailable',
        };
        if (isset($report['report_files']['source_failures'])) {
            $lines[] = '- Complete source findings: `' . $report['report_files']['source_failures'] . '`';
        }
        $lines[] = '';
    }

    array_push($lines, '## Environment', '', '| Component | Status | Value |', '| --- | --- | --- |');
    foreach ($report['environment'] as $item) {
        $lines[] = '| ' . markdown_cell((string) $item['component']) . ' | ' . markdown_cell((string) $item['status']) . ' | ' . markdown_cell((string) $item['value']) . ' |';
    }

    $lines[] = '';
    $lines[] = '## Suites';
    $lines[] = '';
    $lines[] = '| Suite | Status | Result | Duration |';
    $lines[] = '| --- | --- | --- | ---: |';
    foreach ($report['tasks'] as $task) {
        $lines[] = '| ' . markdown_cell((string) $task['label']) . ' | **' . markdown_cell((string) $task['status']) . '** | ' . markdown_cell((string) $task['summary']) . ' | ' . format_duration((float) $task['duration_seconds']) . ' |';
    }

    $problemTasks = array_values(array_filter(
        $report['tasks'],
        static fn(array $task): bool => in_array($task['status'], [STATUS_FAIL, STATUS_BLOCKED], true)
    ));
    if ($problemTasks !== []) {
        $lines[] = '';
        $lines[] = '## Failures and blocked coverage';
        foreach ($problemTasks as $task) {
            $lines[] = '';
            $lines[] = '### ' . $task['label'];
            $lines[] = '';
            $lines[] = '- Status: **' . $task['status'] . '**';
            if ($task['summary'] !== '') {
                $lines[] = '- Summary: ' . $task['summary'];
            }
            if (!empty($task['log'])) {
                $lines[] = '- Log: `' . $task['log'] . '`';
            }
            foreach (($task['details']['problems'] ?? []) as $problem) {
                $lines[] = '- ' . $problem;
            }
        }
    }

    $coverageGaps = array_values(array_filter(
        $report['tasks'],
        static fn(array $task): bool => $task['status'] === STATUS_SKIP
    ));
    foreach ($report['tasks'] as $task) {
        if (($task['counts']['skipped'] ?? 0) > 0 || ($task['counts']['blocked'] ?? 0) > 0) {
            $coverageGaps[] = $task;
        }
    }
    if ($coverageGaps !== []) {
        $lines[] = '';
        $lines[] = '## Coverage gaps';
        $seen = [];
        foreach ($coverageGaps as $task) {
            if (isset($seen[$task['id']])) {
                continue;
            }
            $seen[$task['id']] = true;
            $lines[] = '- **' . $task['label'] . '**: ' . ($task['summary'] !== '' ? $task['summary'] : $task['status']);
            foreach (($task['details']['gaps'] ?? []) as $gap) {
                $lines[] = '  - ' . $gap;
            }
        }
    }

    foreach ($report['tasks'] as $task) {
        if ($task['id'] !== 'runtime-performance' || empty($task['details']['metrics'])) {
            continue;
        }
        $lines[] = '';
        $lines[] = '## Runtime performance (include phases and available full route lifecycles)';
        $lines[] = '';
        $lines[] = '| Probe | PHP files / ceiling | Peak bytes / ceiling | Wall ms (observational) |';
        $lines[] = '| --- | ---: | ---: | ---: |';
        foreach ($task['details']['metrics'] as $metric) {
            $lines[] = '| ' . markdown_cell($metric['probe']) . ' | ' . $metric['included_php_files'] . ' / ' . $metric['limits']['max_included_php_files']
                . ' | ' . $metric['peak_memory_bytes'] . ' / ' . $metric['limits']['max_peak_memory_bytes'] . ' | '
                . ($metric['bootstrap_wall_ms'] ?? $metric['wall_ms']) . ' |';
        }
    }

    if (($report['slowest'] ?? []) !== []) {
        $lines[] = '';
        $lines[] = '## Slowest checks';
        $lines[] = '';
        foreach ($report['slowest'] as $item) {
            $lines[] = '- `' . format_duration((float) $item['duration_seconds']) . '` ' . $item['name'];
        }
    }

    $lines[] = '';
    if (isset($report['manual_review_summary'])) {
        $lines[] = '## Release qualification';
        $lines[] = '';
        $lines[] = 'Source before: ' . ($report['source_fingerprint_before'] ?? 'unavailable');
        $lines[] = '';
        $lines[] = 'Source after: ' . ($report['source_fingerprint_after'] ?? 'unavailable');
        $lines[] = '';
        $lines[] = $report['manual_review_summary'];
        $lines[] = '';
        $lines[] = 'Automated PASS is not human sign-off. Use scripts/release_qualification.php check for the artifact-bound handoff.';
        $lines[] = '';
    }
    $lines[] = '## Report files';
    $lines[] = '';
    $lines[] = '- JSON: `' . $report['report_files']['json'] . '`';
    $lines[] = '- Run directory: `' . $report['report_files']['run_directory'] . '`';
    $lines[] = '';

    return implode("\n", $lines);
}

/**
 * Escape Markdown table metacharacters and line breaks.
 *
 * @param string $value Cell value.
 * @return string Escaped value.
 */
function markdown_cell(string $value): string
{
    return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], $value);
}

/**
 * Classify source changes conservatively without selecting or skipping audit suites.
 *
 * Only modifications to the four maintained manual TeX sources can be marked
 * as a candidate for a future source-only documentation gate. The caller must
 * independently verify the authored-source SHA and its comparison baseline.
 * Renames, additions, deletions, mixed changes and uncertain history remain full.
 *
 * @param list<array{status:string,path:string}> $changes Git diff name-status entries with repository-relative paths.
 * @param bool $baselineVerified Whether the authored SHA and its correct comparison baseline were independently verified.
 * @return array{scope:string,reason:string} Advisory scope and stable rationale; never a qualification result.
 */
function classify_source_change_scope(array $changes, bool $baselineVerified): array
{
    if (!$baselineVerified) {
        return ['scope' => 'full', 'reason' => 'unverified-baseline'];
    }
    if ($changes === []) {
        return ['scope' => 'full', 'reason' => 'empty-diff'];
    }

    // Only maintained manual-source modifications are eligible. This is not
    // evidence that the TeX is prose-only or that its structural checks passed.
    $maintainedManuals = [
        'docs/PHP_Gallery_Manual.tex' => true,
        'docs/PHP_Gallery_Manual_CZ.tex' => true,
        'docs/PHP_Gallery_Manual_DE.tex' => true,
        'docs/PHP_Gallery_Manual_SV.tex' => true,
    ];
    foreach ($changes as $change) {
        if (($change['status'] ?? null) !== 'M') {
            return ['scope' => 'full', 'reason' => 'non-modification-status'];
        }
        $path = $change['path'] ?? null;
        if (!is_string($path) || !isset($maintainedManuals[$path])) {
            return ['scope' => 'full', 'reason' => 'path-out-of-scope'];
        }
    }

    return ['scope' => 'manual-source', 'reason' => 'maintained-manual-modifications-only'];
}
