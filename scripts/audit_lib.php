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
 * @return ?string Browser executable path.
 */
function resolve_browser_executable(): ?string
{
    $override = trim((string) getenv('PHP_GALLERY_BROWSER'));
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
 * @param array $command Executable followed by arguments.
 * @param string $cwd Working directory.
 * @param int $timeoutSeconds Timeout in seconds.
 * @return array Process result.
 */
function run_process(array $command, string $cwd, int $timeoutSeconds = 30): array
{
    $started = microtime(true);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $process = @proc_open($command, $descriptors, $pipes, $cwd, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return [
            'exit_code' => 127,
            'stdout' => '',
            'stderr' => 'Unable to start process: ' . implode(' ', array_map('strval', $command)),
            'timed_out' => false,
            'duration' => microtime(true) - $started,
        ];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $timedOut = false;
    $lastExitCode = null;

    while (true) {
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        $status = proc_get_status($process);
        if (!$status['running']) {
            $lastExitCode = (int) $status['exitcode'];
            break;
        }
        if ((microtime(true) - $started) >= $timeoutSeconds) {
            $timedOut = true;
            proc_terminate($process);
            usleep(150000);
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9);
            }
            break;
        }
        usleep(20000);
    }

    $stdout .= stream_get_contents($pipes[1]) ?: '';
    $stderr .= stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closedCode = proc_close($process);
    $exitCode = $timedOut ? 124 : ($lastExitCode !== null && $lastExitCode >= 0 ? $lastExitCode : $closedCode);

    return [
        'exit_code' => (int) $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'timed_out' => $timedOut,
        'duration' => microtime(true) - $started,
    ];
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
 * Return changed paths reported by Git, or null when Git metadata is unavailable.
 *
 * @param string $rootPath Repository root.
 * @return ?array Repository-relative paths.
 */
function git_changed_paths(string $rootPath): ?array
{
    $git = resolve_executable('PHP_GALLERY_GIT', ['git']);
    if ($git === null || !is_dir($rootPath . '/.git')) {
        return null;
    }

    $tracked = run_process([$git, 'diff', '--name-only', '-z', 'HEAD'], $rootPath, 15);
    $untracked = run_process([$git, 'ls-files', '--others', '--exclude-standard', '-z'], $rootPath, 15);
    if ($tracked['exit_code'] !== 0 || $untracked['exit_code'] !== 0) {
        return null;
    }

    $paths = [];
    foreach ([$tracked['stdout'], $untracked['stdout']] as $output) {
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
 * @param array $report Structured audit report.
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
        '## Environment',
        '',
        '| Component | Status | Value |',
        '| --- | --- | --- |',
    ];

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

    if (($report['slowest'] ?? []) !== []) {
        $lines[] = '';
        $lines[] = '## Slowest checks';
        $lines[] = '';
        foreach ($report['slowest'] as $item) {
            $lines[] = '- `' . format_duration((float) $item['duration_seconds']) . '` ' . $item['name'];
        }
    }

    $lines[] = '';
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
