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
 *   - Bind split hosted audit owners to one candidate and partition PHP tests deterministically
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
 *   2026-10-15
 */

declare(strict_types=1);

namespace PhpGallery\Audit;

const STATUS_PASS = 'PASS';
const STATUS_FAIL = 'FAIL';
const STATUS_SKIP = 'SKIP';
const STATUS_BLOCKED = 'BLOCKED';

/**
 * Extract one optional, bounded numeric metric record from a PHP regression child's stdout.
 *
 * The AUDIT_METRICS convention accepts at most 32 simple ASCII keys and a
 * 4096-byte record. Values must be finite nonnegative integers or floats; no
 * strings, paths, nested values or duplicate fields enter retained metrics.
 *
 * @param string $stdout Complete captured child stdout, with ordinary diagnostic lines allowed around the optional record.
 * @return array{present:bool,valid:bool,metrics:array<string,int|float>,error:?string} Valid numeric measurements, an absent-record success, or an explicit fixed error code for malformed emitted evidence.
 */
function parse_php_audit_metrics(string $stdout): array
{
    $result = ['present'=>false,'valid'=>true,'metrics'=>[],'error'=>null];
    $records = [];
    foreach (preg_split('/\r\n|\n|\r/', $stdout) ?: [] as $line) {
        if (str_starts_with($line, 'AUDIT_METRICS')) $records[] = $line;
    }
    if ($records === []) return $result;
    $result['present'] = true;
    $error = null;
    if (count($records) !== 1) $error = 'multiple-records';
    elseif (strlen($records[0]) > 4096) $error = 'record-too-large';
    elseif (!str_starts_with($records[0], 'AUDIT_METRICS ')) $error = 'invalid-prefix';
    if ($error !== null) return array_replace($result, ['valid'=>false,'error'=>$error]);
    $payload = substr($records[0], strlen('AUDIT_METRICS '));
    try {
        $metrics = json_decode($payload, true, 3, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        return array_replace($result, ['valid'=>false,'error'=>'invalid-json']);
    }
    if (!is_array($metrics) || $metrics === [] || array_is_list($metrics)
        || count($metrics) > 32 || !str_starts_with(ltrim($payload), '{')) {
        return array_replace($result, ['valid'=>false,'error'=>'invalid-object']);
    }
    foreach ($metrics as $key => $value) {
        if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) !== 1
            || !(is_int($value) || is_float($value)) || !is_finite((float) $value) || $value < 0) {
            return array_replace($result, ['valid'=>false,'error'=>'invalid-field']);
        }
    }
    // Only plain keys are allowed; comparing their raw count also rejects JSON
    // duplicate fields that json_decode would otherwise silently overwrite.
    preg_match_all('/"([a-z][a-z0-9_]{0,63})"\s*:/', $payload, $keys);
    if (count($keys[1]) !== count($metrics) || count(array_unique($keys[1])) !== count($keys[1])) {
        return array_replace($result, ['valid'=>false,'error'=>'duplicate-or-escaped-field']);
    }
    $result['metrics'] = $metrics;
    return $result;
}

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
            // Git's NUL-delimited paths already use repository separators;
            // whitespace belongs to the filename and must survive selection.
            if ($path !== '') {
                $paths[] = $path;
            }
        }
    }

    return array_values(array_unique($paths));
}

/**
 * Select complete lint inputs, expanding evaluator/configuration changes to the whole source tree.
 *
 * @param string $rootPath Repository or isolated fixture root.
 * @param list<string> $extensions Source extensions without leading dots.
 * @param bool $changedOnly Whether the caller requested immutable-delta selection.
 * @param ?string $baseSha Immutable ancestor commit, or null for local working-tree feedback.
 * @param bool $requireGitEvidence Whether absent baseline evidence must block.
 * @return array{files:list<string>,fallback:bool,blocked:bool,reason:string} Complete source paths and explicit selection evidence.
 */
function select_lint_targets(string $rootPath, array $extensions, bool $changedOnly, ?string $baseSha, bool $requireGitEvidence): array
{
    $reason = 'whole-tree';
    if ($changedOnly) {
        $changed = $requireGitEvidence && $baseSha === null ? null : git_changed_paths($rootPath, $baseSha);
        if ($changed === null && $requireGitEvidence) {
            return ['files' => [], 'fallback' => false, 'blocked' => true, 'reason' => 'immutable-baseline-unavailable'];
        }
        $reason = $changed === null ? 'git-unavailable-full-tree' : 'immutable-delta';
        $authorityChanged = $changed === null ? [] : array_values(array_filter($changed,
            static fn(string $path): bool => preg_match('~^(?:scripts/(?:audit[^/]*|cli_guard[.]php|check_source_documentation[.]php|source_contract_debt_baseline[.]json|source_contracts/.*)|[.]github/workflows/.*[.]ya?ml|[.]gitattributes|[.]node-version|[.]nvmrc|[.]user[.]ini|php[.]ini|package[.]json)$~D', $path) === 1));
        if ($authorityChanged !== []) {
            $reason = 'evaluator-or-configuration-change: ' . implode(', ', $authorityChanged);
        } elseif ($changed !== null) {
            $extensionMap = array_fill_keys(array_map('strtolower', $extensions), true);
            $files = [];
            foreach ($changed as $relative) {
                $absolute = $rootPath . '/' . $relative;
                if (isset($extensionMap[strtolower(pathinfo($relative, PATHINFO_EXTENSION))]) && is_file($absolute)) {
                    $files[] = $absolute;
                }
            }
            sort($files, SORT_STRING);
            return ['files' => array_values(array_unique($files)), 'fallback' => false, 'blocked' => false, 'reason' => $reason];
        }
    }
    return [
        'files' => discover_files($rootPath, $extensions, ['.git', '.idea', '.vscode', 'cache', 'data', 'galleries', 'deploy', 'node_modules', 'vendor', '__pycache__', '.pytest_cache', '.venv', 'venv']),
        'fallback' => $changedOnly,
        'blocked' => false,
        'reason' => $reason,
    ];
}

/**
 * Delegate the packaging suite only to the required native three-platform same-candidate owner.
 *
 * This reports delegation, never a local PASS or authorization to change protected
 * refs. The installed integration harness continues to reject branch-authored
 * authority changes; maintenance_full qualifies proposed changes separately.
 *
 * @param list<string> $suites Selected central suites before ownership delegation.
 * @param string $profile Central full or release profile being executed.
 * @param array<string,string> $environment Current Actions process identity.
 * @param ?string $actualHead Checked-out immutable candidate SHA.
 * @return array{suites:list<string>,delegated_to_packaging:list<string>} Executed suites and explicit required-owner delegation.
 */
function audit_hosted_packaging_ownership(array $suites, string $profile, array $environment, ?string $actualHead): array
{
    $result = ['suites' => $suites, 'delegated_to_packaging' => []];
    if (!in_array($profile, ['full', 'release'], true)
        || ($environment['GITHUB_ACTIONS'] ?? '') !== 'true'
        || ($environment['GITHUB_REPOSITORY'] ?? '') !== 'klusik/PHP_gallery'
        || !in_array($environment['GITHUB_JOB'] ?? '', ['real-database-browser', 'runtime-compatibility', 'release-audit'], true)
        || ($environment['PHP_GALLERY_PACKAGING_OWNER'] ?? '') !== 'production-package'
        || !is_string($actualHead) || preg_match('/^[a-f0-9]{40}$/D', $actualHead) !== 1
        || ($environment['PHP_GALLERY_PACKAGING_CANDIDATE'] ?? '') !== $actualHead
        || count(array_keys($suites, 'packaging', true)) !== 1) {
        return $result;
    }
    $result['suites'] = array_values(array_diff($suites, ['packaging']));
    $result['delegated_to_packaging'] = ['packaging'];
    return $result;
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
 * @param array{id?:string,status?:string,counts?:array<string,int>,details?:array{tests?:list<array{name?:string,status?:string,exit_code?:int,timed_out?:bool}>}} $regression Structured PHP regression, fast-feedback or packaging evidence; incomplete/malformed evidence is rejected.
 * @param list<string> $requiredTests Mandatory standalone PHP test basenames.
 * @return list<string> Names without verified PASS, or all required names for malformed evidence.
 */
function missing_required_php_test_passes(array $regression, array $requiredTests): array
{
    $tests = $regression['details']['tests'] ?? null;
    $reportedCounts = $regression['counts'] ?? null;
    if (!in_array($regression['id'] ?? null, ['php-fast', 'php-regression', 'packaging'], true)
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
 * Validate a hosted parallel FULL owner against the checked-out candidate identity.
 *
 * The profile owner and workflow candidate are explicit inputs so a shard cannot
 * claim qualification for another checkout or run outside the intended workflow.
 *
 * @param array<string,string> $environment Current process environment.
 * @param string $profile Selected central profile name.
 * @param ?string $actualHead Immutable checked-out Git HEAD SHA, or null when unavailable.
 * @param ?string $comparisonBase Resolved immutable comparison-base SHA, or null when unavailable.
 * @param int $shardCount Centrally registered PHP shard count.
 * @return array{valid:bool,index:?int,total:?int,owner:?string,candidate:?string,error:?string} Validated one-based shard coordinates and owner binding, or a diagnostic.
 */
function audit_hosted_parallel_owner(array $environment, string $profile, ?string $actualHead, ?string $comparisonBase, int $shardCount): array
{
    $owners = [
        'source-contracts' => ['variable' => 'PHP_GALLERY_SOURCE_OWNER', 'value' => 'shared-source-contracts'],
        'portable' => ['variable' => 'PHP_GALLERY_PORTABLE_OWNER', 'value' => 'portable-node-winapp'],
        'hosted-full' => ['variable' => 'PHP_GALLERY_AUDIT_OWNER', 'value' => 'hosted-full'],
    ];
    $ownerDefinition = $owners[$profile] ?? null;
    if (!is_array($ownerDefinition)) {
        return ['valid' => false, 'index' => null, 'total' => null, 'owner' => null, 'candidate' => null,
            'error' => 'Profile is not a hosted parallel owner.'];
    }
    $candidate = $environment['PHP_GALLERY_AUDIT_CANDIDATE'] ?? '';
    $owner = $environment[$ownerDefinition['variable']] ?? '';
    if (($environment['GITHUB_ACTIONS'] ?? '') !== 'true'
        || ($environment['GITHUB_REPOSITORY'] ?? '') !== 'klusik/PHP_gallery') {
        $error = 'Parallel owner profiles require the trusted PHP_gallery GitHub Actions repository.';
    } elseif ($actualHead === null || preg_match('/^[a-f0-9]{40}$/D', $actualHead) !== 1
        || $candidate !== $actualHead) {
        $error = 'PHP_GALLERY_AUDIT_CANDIDATE must equal the checked-out immutable HEAD SHA.';
    } elseif ($comparisonBase === null || preg_match('/^[a-f0-9]{40}$/D', $comparisonBase) !== 1
        || $comparisonBase === $actualHead) {
        $error = 'Parallel owner profiles require a distinct resolved immutable comparison-base SHA.';
    } elseif ($owner !== $ownerDefinition['value']) {
        $error = $ownerDefinition['variable'] . ' must equal ' . $ownerDefinition['value'] . '.';
    } elseif ($profile === 'hosted-full'
        && (($environment['PHP_GALLERY_SOURCE_OWNER'] ?? '') !== 'shared-source-contracts'
            || ($environment['PHP_GALLERY_PORTABLE_OWNER'] ?? '') !== 'portable-node-winapp')) {
        $error = 'Hosted FULL shards must bind the required shared source and portable owners.';
    } else {
        $error = null;
    }
    $index = null;
    $total = null;
    if ($profile === 'hosted-full') {
        $indexText = $environment['PHP_GALLERY_AUDIT_SHARD_INDEX'] ?? '';
        $totalText = $environment['PHP_GALLERY_AUDIT_SHARD_TOTAL'] ?? '';
        if (preg_match('/^[1-9][0-9]*$/D', $indexText) !== 1
            || preg_match('/^[1-9][0-9]*$/D', $totalText) !== 1
            || (int) $totalText !== $shardCount
            || (int) $indexText < 1 || (int) $indexText > $shardCount) {
            $error ??= 'Hosted FULL shard index and total must identify one registered one-based shard.';
        } else {
            $index = (int) $indexText;
            $total = (int) $totalText;
        }
    } elseif (isset($environment['PHP_GALLERY_AUDIT_SHARD_INDEX'])
        || isset($environment['PHP_GALLERY_AUDIT_SHARD_TOTAL'])) {
        $error ??= 'PHP shard coordinates are supported only by the hosted-full profile.';
    }
    return ['valid' => $error === null, 'index' => $index, 'total' => $total,
        'owner' => $owner, 'candidate' => $candidate, 'error' => $error];
}

/**
 * Assign sorted PHP regression filenames to deterministic, disjoint hosted shards.
 *
 * The registry remains the sole source of test membership: callers pass the
 * discovered standalone test names and each shard receives every Nth name.
 *
 * @param list<string> $testNames Unique standalone PHP regression filenames.
 * @param int $oneBasedIndex Requested shard number from one through total.
 * @param int $total Number of registered shards.
 * @return list<string> Sorted test filenames owned by the requested shard.
 */
function audit_partition_php_tests(array $testNames, int $oneBasedIndex, int $total): array
{
    if ($total < 1 || $oneBasedIndex < 1 || $oneBasedIndex > $total) {
        throw new \InvalidArgumentException('PHP regression shard coordinates are outside the registered range.');
    }
    $names = array_values($testNames);
    foreach ($names as $name) {
        if (!is_string($name) || preg_match('/^[a-z0-9_]+_test[.]php$/D', $name) !== 1) {
            throw new \InvalidArgumentException('PHP regression shard input contains an invalid test filename.');
        }
    }
    if (count($names) !== count(array_unique($names))) {
        throw new \InvalidArgumentException('PHP regression shard input contains duplicate test filenames.');
    }
    sort($names, SORT_STRING);
    $selected = [];
    foreach ($names as $offset => $name) {
        if ($offset % $total === $oneBasedIndex - 1) {
            $selected[] = $name;
        }
    }
    return $selected;
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
 * Return a compact Markdown report with exact source and parallel owner bindings.
 *
 * @param array{status:string,profile:string,started_at:string,duration_seconds:int|float,source?:array{head_sha:?string,comparison_base:?string,requested_base:string,worktree_dirty:?bool},parallel_owner?:array{profile:string,owner:?string,candidate:?string,comparison_base:?string,shard_index:?int,shard_total:?int},delegated_to_parallel_owners?:list<array{owner:string,profile:?string,candidate:?string,comparison_base:?string,suites:list<string>}>,environment:list<array{component:string,status:string,value:string}>,tasks:list<array{id:string,label:string,status:string,duration_seconds:int|float,summary:string,log?:?string,counts?:array<string,int>,details?:array{problems?:list<string>,gaps?:list<string>,metrics?:list<array{probe:string,included_php_files:int,peak_memory_bytes:int,wall_ms?:int,bootstrap_wall_ms?:int,limits:array{max_included_php_files:int,max_peak_memory_bytes:int}}>}}>,slowest?:list<array{name:string,duration_seconds:int|float}>,manual_review_summary?:string,source_fingerprint_before?:?string,source_fingerprint_after?:?string,report_files:array{json:string,run_directory:string}} $report Structured audit outcomes, exact source identity, and optional same-candidate parallel owner records.
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

    if (is_array($report['parallel_owner'] ?? null)) {
        $owner = $report['parallel_owner'];
        $lines[] = '- Parallel owner: `' . ($owner['owner'] ?? 'unavailable') . '`';
        $lines[] = '- Owner candidate: `' . ($owner['candidate'] ?? 'unavailable') . '`';
        $lines[] = '- Owner comparison base: `' . ($owner['comparison_base'] ?? 'unavailable') . '`';
        if (is_int($owner['shard_index'] ?? null) && is_int($owner['shard_total'] ?? null)) {
            $lines[] = '- PHP shard: `' . $owner['shard_index'] . '/' . $owner['shard_total'] . '`';
        }
        $lines[] = '';
    }
    foreach (($report['delegated_to_parallel_owners'] ?? []) as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $suites = is_array($owner['suites'] ?? null) ? implode(', ', $owner['suites']) : '';
        $lines[] = '- Expected separate owner: `' . ($owner['owner'] ?? 'unavailable') . '`'
            . ' (candidate `' . ($owner['candidate'] ?? 'unavailable') . '`, base `'
            . ($owner['comparison_base'] ?? 'unavailable') . '`, suites `' . $suites . '`)';
    }
    if (($report['delegated_to_parallel_owners'] ?? []) !== []) {
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
