<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/package_production/filesystem.php
 * Module Type: Package Filesystem Library
 * Purpose: Validate explicit package paths and maintain isolated publication workspaces.
 * Responsibilities: Reject links, inspect native Windows attributes in one batch, and preserve file metadata.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Packaging;

use RuntimeException;

/**
 * Resolve an absolute spelling without accepting traversal or ambiguous platform names.
 *
 * @param string $path User-supplied source or output path.
 * @return string Absolute native path without trailing separators.
 */
function absolute_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    if ($path === '' || str_contains($path, "\0")) {
        throw new RuntimeException('Package path is empty or invalid.');
    }
    if (
        !str_starts_with($path, '/')
        && preg_match('/^[A-Za-z]:\//D', $path) !== 1
    ) {
        $path = str_replace('\\', '/', (string) getcwd()) . '/' . $path;
    }
    $tail = preg_replace('/^[A-Za-z]:\//', '', ltrim($path, '/'));
    foreach (explode('/', (string) $tail) as $segment) {
        if (
            $segment === ''
            || !\Gallery\Core\release_file_policy_is_safe_relative_path(
                $segment,
            )
        ) {
            throw new RuntimeException(
                'Package path contains an unsafe or ambiguous component.',
            );
        }
    }
    return str_replace('/', DIRECTORY_SEPARATOR, rtrim($path, '/'));
}

/**
 * Reject symbolic links and Windows reparse attributes on explicit paths and ancestors.
 *
 * PHP does not expose every Windows reparse tag through is_link(). Native Windows
 * PowerShell supplies the missing DOS attribute in one bounded input batch; no
 * filesystem discovery or per-file child process participates in packaging.
 *
 * @param list<string> $paths Existing absolute files or directories to inspect.
 * @param bool $inspectWindows Whether to execute the native attribute batch now rather than at the combined source boundary.
 * @return void Throws if an ancestor redirects, a special entry occurs, or native inspection fails.
 */
function assert_native_paths(array $paths, bool $inspectWindows = true): void
{
    $ancestors = [];
    foreach ($paths as $path) {
        do {
            $ancestors[$path] = true;
            $parent = dirname($path);
            if ($parent === $path) {
                break;
            }
            $path = $parent;
        } while (!isset($ancestors[$path]));
    }
    foreach (array_keys($ancestors) as $path) {
        clearstatcache(true, $path);
        $resolved = realpath($path);
        $same =
            is_string($resolved)
            && (
                DIRECTORY_SEPARATOR === '\\'
                    ? strcasecmp($resolved, $path) === 0
                    : $resolved === $path
            );
        if (is_link($path) || !$same || (!is_file($path) && !is_dir($path))) {
            throw new RuntimeException('Package path is missing, linked, redirected, or non-regular: '
                . $path);
        }
    }
    if (DIRECTORY_SEPARATOR !== '\\' || !$inspectWindows) {
        return;
    }
    if (!function_exists('proc_open')) {
        throw new RuntimeException(
            'Native Windows reparse-point inspection requires proc_open.',
        );
    }
    $script =
        '$ErrorActionPreference="Stop"; try { '
        . '[Console]::InputEncoding=[System.Text.UTF8Encoding]::new($false); '
        . '$paths=ConvertFrom-Json ([Console]::In.ReadToEnd()); foreach($path in $paths) { '
        . 'if(([IO.File]::GetAttributes($path) -band [IO.FileAttributes]::ReparsePoint) -ne 0) { '
        . 'throw "Reparse point rejected: $path" } }; exit 0 '
        . '} catch { [Console]::Error.WriteLine($_.Exception.Message); exit 1 }';
    $nativeShell =
        (string) getenv('SystemRoot')
        . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
    $pipes = [];
    $process = proc_open(
        [
            $nativeShell,
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-Command',
            $script,
        ],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException(
            'Could not start native Windows reparse-point inspection.',
        );
    }
    $json = json_encode(
        array_keys($ancestors),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
    );
    $offset = 0;
    while ($offset < strlen($json)) {
        $written = fwrite($pipes[0], substr($json, $offset));
        if (!is_int($written) || $written === 0) {
            break;
        }
        $offset += $written;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || $offset !== strlen($json)) {
        throw new RuntimeException(
            'Native Windows reparse-point inspection failed: '
                . trim((string) $stderr . (string) $stdout),
        );
    }
}

/**
 * Validate a new output with an existing parent outside the source tree.
 *
 * @param string $path Requested output path.
 * @param string $source Canonical source directory.
 * @return string Absolute output path whose parent is safe and whose leaf is absent.
 */
function output_path(string $path, string $source): string
{
    $absolute = absolute_path($path);
    $parent = dirname($absolute);
    assert_native_paths([$parent], false);
    $key = DIRECTORY_SEPARATOR === '\\' ? strtolower($absolute) : $absolute;
    $sourceKey = DIRECTORY_SEPARATOR === '\\' ? strtolower($source) : $source;
    if (
        $key === $sourceKey
        || str_starts_with($key, $sourceKey . DIRECTORY_SEPARATOR)
        || str_starts_with($sourceKey, $key . DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException(
            'Package output must be outside and must not contain the source tree.',
        );
    }
    clearstatcache(true, $absolute);
    if (
        file_exists($absolute)
        || is_link($absolute)
        || @lstat($absolute) !== false
    ) {
        throw new RuntimeException('Package output already exists: '
            . $absolute);
    }
    return $absolute;
}

/**
 * Read complete regular-file bytes and verify the file remained stable during reading.
 *
 * @param string $path Existing explicitly selected source file.
 * @return array{bytes:string,sha256:string,size:int,mtime:int,atime:int,mode:int} Bytes and source metadata.
 */
function read_file(string $path): array
{
    clearstatcache(true, $path);
    $before = @stat($path);
    if (
        !is_array($before)
        || is_link($path)
        || !is_file($path)
        || !is_readable($path)
    ) {
        throw new RuntimeException('Package input is missing, linked, or unreadable: '
            . $path);
    }
    $bytes = file_get_contents($path);
    clearstatcache(true, $path);
    $after = @stat($path);
    if (
        !is_string($bytes)
        || !is_array($after)
        || strlen($bytes) !== (int) $before['size']
        || $before['size'] !== $after['size']
        || $before['mtime'] !== $after['mtime']
        || $before['ino'] !== $after['ino']
        || $before['mode'] !== $after['mode']
    ) {
        throw new RuntimeException('Package input changed or was not read completely: '
            . $path);
    }
    return [
        'bytes' => $bytes,
        'sha256' => hash('sha256', $bytes),
        'size' => strlen($bytes),
        'mtime' => (int) $before['mtime'],
        'atime' => (int) $before['atime'],
        'mode' => (int) $before['mode'],
    ];
}

/**
 * Remove an owned workspace without following symbolic links.
 *
 * @param string $path Builder-created file or directory to remove.
 * @return void Removes the workspace or throws on incomplete cleanup.
 */
function remove_tree(string $path): void
{
    if (is_link($path) || !is_dir($path)) {
        if (is_file($path) && !is_link($path)) {
            chmod($path, 0600);
        }
        if ((file_exists($path) || is_link($path)) && !unlink($path)) {
            throw new RuntimeException('Could not remove package workspace file: '
                . $path);
        }
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            remove_tree($path . DIRECTORY_SEPARATOR . $entry);
        }
    }
    if (!rmdir($path)) {
        throw new RuntimeException('Could not remove package workspace directory: '
            . $path);
    }
}
