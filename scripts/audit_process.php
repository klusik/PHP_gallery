<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/audit_process.php
 * Module Type: Audit Process Orchestration
 * Purpose: Schedule bounded portable child processes with exclusive barriers and isolated capture streams.
 * Responsibilities: Own process lifetimes, capture output, enforce deadlines and clean up descendants.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace PhpGallery\Audit;

/**
 * Resolve the conservative PHP worker count, rejecting invalid configuration.
 *
 * @param ?string $override Explicit setting, or null to read PHP_GALLERY_AUDIT_WORKERS.
 * @return int Worker limit from one through eight; the default is four.
 */
function worker_count(?string $override = null): int
{
    $value = trim($override ?? (string) getenv('PHP_GALLERY_AUDIT_WORKERS'));
    if ($value === '') {
        return 4;
    }
    if (preg_match('/^[1-8]$/D', $value) !== 1) {
        throw new \InvalidArgumentException('PHP_GALLERY_AUDIT_WORKERS must be an integer from 1 through 8.');
    }
    return (int) $value;
}

/**
 * Identify whether Chromium fixtures are mandatory for this invocation.
 *
 * @return bool True when the dedicated browser coverage mode is enabled.
 */
function browser_required(): bool
{
    return trim((string) getenv('PHP_GALLERY_BROWSER_REQUIRED')) === '1';
}

/**
 * Normalize child outcomes without confusing missing coverage with assertions.
 *
 * @param array{exit_code:int,stdout:string,stderr:string,timed_out:bool,blocked?:bool,duration?:float} $process Captured process result.
 * @param bool $required Whether a child-reported skip must block required coverage.
 * @return string PASS, FAIL, SKIP or BLOCKED.
 */
function process_status(array $process, bool $required = false): string
{
    if (!empty($process['blocked'])) {
        return STATUS_BLOCKED;
    }
    if (!empty($process['timed_out'])) {
        return STATUS_FAIL;
    }
    $output = (string) ($process['stdout'] ?? '') . "\n" . (string) ($process['stderr'] ?? '');
    if (preg_match('/^\s*BLOCKED\b/im', $output) === 1) {
        return STATUS_BLOCKED;
    }
    if ((int) ($process['exit_code'] ?? 127) !== 0) {
        return STATUS_FAIL;
    }
    if (output_is_skip($output)) {
        return $required ? STATUS_BLOCKED : STATUS_SKIP;
    }
    return STATUS_PASS;
}

/**
 * Allocate capture streams and start one owned subprocess without a shell.
 *
 * @param array{command:array<int,string>,timeout?:int,serial?:bool} $job Command, timeout and optional serial flag.
 * @param string $cwd Child working directory.
 * @return array<string,mixed> Active process resources or a completed blocked result.
 */
function start_process(array $job, string $cwd): array
{
    $started = hrtime(true) / 1e9;
    // Windows pipes cannot reliably become nonblocking. Each child owns two files.
    $stdout = tmpfile();
    $stderr = tmpfile();
    $command = $job['command'];
    $group = false;
    if (DIRECTORY_SEPARATOR !== '\\' && function_exists('posix_kill') && is_executable('/usr/bin/setsid')) {
        // A private group lets timeout cleanup include grandchildren on Linux.
        $command = array_merge(['/usr/bin/setsid'], $command);
        $group = true;
    }
    $pipes = [];
    $process = false;
    $executable = (string) ($job['command'][0] ?? '');
    // Absolute/relative paths can be checked without rejecting runnable Windows aliases on PATH.
    $missingPath = (str_contains($executable, '/') || str_contains($executable, '\\')) && !is_file($executable);
    if (DIRECTORY_SEPARATOR !== '\\' && find_executable($executable) === null) {
        $missingPath = true;
    }
    if (!$missingPath && $stdout !== false && $stderr !== false) {
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => $stdout, 2 => $stderr], $pipes, $cwd, null, ['bypass_shell' => true]);
    }
    if (!is_resource($process)) {
        foreach ([$stdout, $stderr] as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        return ['result' => ['exit_code' => 127, 'stdout' => '', 'stderr' => 'Unable to allocate capture streams or start process: ' . implode(' ', $job['command']),
            'timed_out' => false, 'blocked' => true, 'duration' => hrtime(true) / 1e9 - $started]];
    }
    // Allocation and executable discovery never consume a successfully spawned child's deadline.
    $started = hrtime(true) / 1e9;
    fclose($pipes[0]);
    $status = proc_get_status($process);
    return ['process' => $process, 'stdout' => $stdout, 'stderr' => $stderr, 'started' => $started,
        'timeout' => max(1, (int) ($job['timeout'] ?? 30)), 'serial' => !empty($job['serial']),
        'pid' => (int) $status['pid'], 'group' => $group, 'status' => $status];
}

/**
 * Read descendants from the operating system when private process groups are unavailable.
 *
 * @param int $parentPid Owned child PID whose descendants must be stopped.
 * @return array<int,int> Owned descendant PIDs ordered parents before children.
 */
function descendant_process_ids(int $parentPid): array
{
    $ps = is_executable('/bin/ps') ? '/bin/ps' : (is_executable('/usr/bin/ps') ? '/usr/bin/ps' : null);
    if ($ps === null) {
        return [];
    }
    $stream = tmpfile();
    if ($stream === false) {
        return [];
    }
    $pipes = [];
    $process = @proc_open([$ps, '-axo', 'pid=,ppid='], [0 => ['file', '/dev/null', 'r'], 1 => $stream, 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($process)) {
        fclose($stream);
        return [];
    }
    $deadline = hrtime(true) / 1e9 + 1;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        usleep(10000);
    } while (hrtime(true) / 1e9 < $deadline);
    if ($status['running']) {
        proc_terminate($process, 9);
    }
    proc_close($process);
    rewind($stream);
    $output = stream_get_contents($stream) ?: '';
    fclose($stream);
    $children = [];
    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        if (preg_match('/^\s*(\d+)\s+(\d+)\s*$/D', $line, $match) === 1) {
            $children[(int) $match[2]][] = (int) $match[1];
        }
    }
    $owned = [$parentPid];
    for ($index = 0; $index < count($owned); $index++) {
        foreach ($children[$owned[$index]] ?? [] as $pid) {
            if ($pid > 1 && !in_array($pid, $owned, true)) {
                $owned[] = $pid;
            }
        }
    }
    return array_slice($owned, 1);
}

/**
 * Signal only previously identified owned descendants without shell interpolation.
 *
 * @param array<int,int> $pids Owned positive descendant PIDs.
 * @param int $signal TERM or KILL signal number.
 * @return void
 */
function signal_process_ids(array $pids, int $signal): void
{
    if ($pids === []) {
        return;
    }
    if (function_exists('posix_kill')) {
        foreach ($pids as $pid) {
            @posix_kill($pid, $signal);
        }
        return;
    }
    $kill = is_executable('/bin/kill') ? '/bin/kill' : (is_executable('/usr/bin/kill') ? '/usr/bin/kill' : null);
    if ($kill !== null) {
        $pipes = [];
        $process = @proc_open(array_merge([$kill, '-' . $signal], array_map('strval', $pids)),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (is_resource($process)) {
            proc_close($process);
        }
    }
}

/**
 * Terminate an owned process tree, then forcibly stop a surviving child.
 *
 * @param array<string,mixed> $active Owned process handle and private group metadata.
 * @return void
 */
function terminate_process(array $active): void
{
    $descendants = [];
    if (DIRECTORY_SEPARATOR === '\\') {
        $taskkill = rtrim((string) getenv('SystemRoot'), '\\/') . '/System32/taskkill.exe';
        if (is_file($taskkill)) {
            $pipes = [];
            $killer = @proc_open([$taskkill, '/PID', (string) $active['pid'], '/T', '/F'],
                [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, null, null, ['bypass_shell' => true]);
            if (is_resource($killer)) {
                proc_close($killer);
            }
        }
    } elseif ($active['group'] && function_exists('posix_getpgid') && posix_getpgid($active['pid']) === $active['pid']) {
        posix_kill(-$active['pid'], 15);
    } else {
        // macOS generally lacks setsid; a bounded ps snapshot supplies the owned subtree.
        $descendants = array_reverse(descendant_process_ids($active['pid']));
        signal_process_ids($descendants, 15);
    }
    @proc_terminate($active['process']);
    usleep(100000);
    signal_process_ids($descendants, 9);
    if ($active['group'] && function_exists('posix_kill')) {
        // The group ID was allocated only to this child, never to the audit parent.
        @posix_kill(-$active['pid'], 9);
    }
    if (proc_get_status($active['process'])['running']) {
        @proc_terminate($active['process'], 9);
    }
}

/**
 * Reap a completed child and close both capture streams exactly once.
 *
 * @param array<string,mixed> $active Owned process resources.
 * @param array<string,mixed> $status Last observed process status.
 * @param bool $timedOut Whether the child's individual deadline elapsed.
 * @return array{exit_code:int,stdout:string,stderr:string,timed_out:bool,duration:float} Captured output, exit status and monotonic duration.
 */
function finish_process(array $active, array $status, bool $timedOut): array
{
    $closed = proc_close($active['process']);
    rewind($active['stdout']);
    rewind($active['stderr']);
    $stdout = stream_get_contents($active['stdout']) ?: '';
    $stderr = stream_get_contents($active['stderr']) ?: '';
    fclose($active['stdout']);
    fclose($active['stderr']);
    return ['exit_code' => $timedOut ? 124 : ((int) $status['exitcode'] >= 0 ? (int) $status['exitcode'] : $closed),
        'stdout' => $stdout, 'stderr' => $stderr, 'timed_out' => $timedOut,
        'duration' => hrtime(true) / 1e9 - $active['started']];
}

/**
 * Run bounded children with global exclusive barriers and stable input ordering.
 *
 * @param array<int,array{command:array<int,string>,timeout?:int,serial?:bool}> $jobs Ordered commands with per-child timeout and optional serial metadata.
 * @param string $cwd Child working directory.
 * @param int $workers Maximum simultaneously active children, from one through eight.
 * @return array<int,array{exit_code:int,stdout:string,stderr:string,timed_out:bool,blocked?:bool,duration:float}> Process results in the same order as the input jobs.
 */
function run_process_pool(array $jobs, string $cwd, int $workers): array
{
    if ($workers < 1 || $workers > 8) {
        throw new \InvalidArgumentException('Process pool worker limit must be from 1 through 8.');
    }
    $jobs = array_values($jobs);
    $next = 0;
    $active = [];
    $results = [];
    try {
        while ($next < count($jobs) || $active !== []) {
            while ($next < count($jobs) && count($active) < $workers) {
                if ($active !== [] && (!empty($jobs[$next]['serial']) || !empty(reset($active)['serial']))) {
                    break;
                }
                $child = start_process($jobs[$next], $cwd);
                if (isset($child['result'])) {
                    $results[$next] = $child['result'];
                } else {
                    $active[$next] = $child;
                }
                $next++;
                if (!empty($child['serial'])) {
                    break;
                }
            }
            foreach ($active as $index => $child) {
                // Preserve an already-observed exit code on PHP 8.1/8.2, where a second poll can lose it.
                $status = $child['status']['running'] ? proc_get_status($child['process']) : $child['status'];
                $timedOut = $status['running'] && hrtime(true) / 1e9 - $child['started'] >= $child['timeout'];
                if ($timedOut) {
                    terminate_process($child);
                }
                if (!$status['running'] || $timedOut) {
                    $results[$index] = finish_process($child, $status, $timedOut);
                    unset($active[$index]);
                }
            }
            if ($active !== []) {
                usleep(10000);
            }
        }
    } finally {
        foreach ($active as $child) {
            terminate_process($child);
            finish_process($child, proc_get_status($child['process']), true);
        }
    }
    ksort($results, SORT_NUMERIC);
    return array_values($results);
}
