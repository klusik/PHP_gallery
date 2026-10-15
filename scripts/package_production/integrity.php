<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/package_production/integrity.php
 * Module Type: Package Integrity Library
 * Purpose: Bind package membership and every payload byte to a current source manifest.
 * Responsibilities: Validate normalized core hashes and independently verify raw directory and ZIP bytes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Packaging;

use RuntimeException;
use ZipArchive;

/**
 * Capture complete-file digests after validating the canonical current core manifest.
 *
 * @param string $root Canonical source directory.
 * @param list<string> $additionalPaths Existing output parents to include in the single native Windows attribute batch.
 * @return array{files:array<string,array{sha256:string,size:int,mtime:int,atime:int,mode:int}>,generated_at:string} Exact byte evidence and manifest build date.
 */
function source_evidence(string $root, array $additionalPaths = []): array
{
    $paths = \Gallery\Core\release_file_policy_paths($root);
    foreach (['index.php', 'app/bootstrap.php', 'app/core-manifest.json', 'app/production-files.json'] as $required) {
        if (!in_array($required, $paths, true)) {
            throw new RuntimeException('Production package is missing a required structural file: ' . $required);
        }
    }
    foreach (['deploy.bat', 'deploy.sh', 'scripts/deploy.sh', 'scripts/deploy.ps1'] as $retired) {
        if (in_array($retired, $paths, true)) {
            throw new RuntimeException('Production inventory still contains a retired deployment entrypoint: ' . $retired);
        }
    }
    assert_native_paths(array_merge($additionalPaths, array_map(static fn(string $path): string => $root . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $path), $paths)));
    $manifestBytes = read_file($root . '/app/core-manifest.json')['bytes'];
    $manifest = json_decode($manifestBytes, true);
    if (!is_array($manifest) || ($manifest['algorithm'] ?? null) !== 'sha256'
        || ($manifest['hash_mode'] ?? null) !== 'normalized-text-sha256'
        || !is_string($manifest['version'] ?? null) || !is_string($manifest['generated_at'] ?? null)
        || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(Z|[+-][0-9]{2}:[0-9]{2})$/D', $manifest['generated_at']) !== 1
        || strtotime($manifest['generated_at']) === false || !is_array($manifest['files'] ?? null)) {
        throw new RuntimeException('Production core manifest has an invalid schema.');
    }
    $bootstrap = read_file($root . '/app/bootstrap.php')['bytes'];
    if (preg_match('/const\s+CMS_VERSION\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/i', $bootstrap, $version) !== 1
        || $manifest['version'] !== $version[1]) {
        throw new RuntimeException('Production core manifest version is stale.');
    }
    $expectedCore = array_values(array_filter($paths, '\\Gallery\\Core\\release_file_policy_is_integrity_path'));
    if (array_keys($manifest['files']) !== $expectedCore) {
        throw new RuntimeException('Production core manifest membership is stale or invalid.');
    }
    $files = [];
    foreach ($paths as $path) {
        $record = read_file($root . '/' . $path);
        if ($path === 'app/core-manifest.json' && $record['bytes'] !== $manifestBytes) {
            throw new RuntimeException('Production core manifest changed during validation.');
        }
        if (array_key_exists($path, $manifest['files'])) {
            $normalized = str_starts_with($record['bytes'], "\xEF\xBB\xBF") ? substr($record['bytes'], 3) : $record['bytes'];
            $digest = 'sha256:' . hash('sha256', str_replace(["\r\n", "\r"], "\n", $normalized));
            if (!is_string($manifest['files'][$path]) || !hash_equals($digest, $manifest['files'][$path])) {
                throw new RuntimeException('Production core manifest hash is stale: ' . $path);
            }
        }
        unset($record['bytes']);
        $files[$path] = $record;
    }
    return ['files' => $files, 'generated_at' => $manifest['generated_at']];
}

/**
 * Verify exact directory membership and complete raw-byte digests.
 *
 * @param string $root Staged package directory.
 * @param array<string,array{sha256:string,size:int,mtime:int,atime:int,mode:int}> $files Source evidence indexed by canonical relative paths.
 * @return void Throws when membership, content, permissions, or modification time differs.
 */
function verify_directory(string $root, array $files): void
{
    $actual = \Gallery\Core\release_file_policy_enumerate_files($root);
    if ($actual !== array_keys($files)) {
        throw new RuntimeException('Production package directory membership does not match the inventory.');
    }
    foreach ($files as $path => $expected) {
        $record = read_file($root . '/' . $path);
        if (!hash_equals($expected['sha256'], $record['sha256']) || $expected['size'] !== $record['size']
            || $expected['mtime'] !== $record['mtime']
            || (DIRECTORY_SEPARATOR !== '\\' && ($expected['mode'] & 0777) !== ($record['mode'] & 0777))) {
            throw new RuntimeException('Production package directory content or metadata mismatch: ' . $path);
        }
    }
}

/**
 * Verify every ZIP member, uncompressed byte digest, and Unix permission record.
 *
 * @param string $archivePath Completed archive to inspect without extraction.
 * @param array<string,array{sha256:string,size:int,mtime:int,atime:int,mode:int}> $files Source evidence indexed by sorted paths.
 * @param int $timestamp Fixed ZIP timestamp in UTC, aligned to two seconds.
 * @return void Throws when archive membership, bytes, timestamp, or permissions differs.
 */
function verify_zip(string $archivePath, array $files, int $timestamp): void
{
    $nativeTimezone = getenv('TZ');
    try {
        if (!putenv('TZ=UTC0')) {
            throw new RuntimeException('Could not set the native ZIP verification timezone.');
        }
        $archive = new ZipArchive();
        if ($archive->open($archivePath, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('Could not reopen the production ZIP for integrity verification.');
        }
        try {
            if ($archive->numFiles !== count($files)) {
                throw new RuntimeException('Production ZIP membership count differs from the inventory.');
            }
            $index = 0;
            foreach ($files as $path => $expected) {
                $stat = $archive->statIndex($index);
                $system = 0;
                $attributes = 0;
                if (!is_array($stat) || $stat['name'] !== $path || (int) $stat['size'] !== $expected['size']
                    || (int) $stat['mtime'] !== $timestamp
                    || !$archive->getExternalAttributesIndex($index, $system, $attributes)
                    || $system !== ZipArchive::OPSYS_UNIX || (($attributes >> 16) & 0777) !== ($expected['mode'] & 0777)) {
                    throw new RuntimeException('Production ZIP membership or metadata mismatch: ' . $path);
                }
                $stream = $archive->getStream($path);
                if (!is_resource($stream)) {
                    throw new RuntimeException('Could not read production ZIP member: ' . $path);
                }
                try {
                    $context = hash_init('sha256');
                    $bytes = hash_update_stream($context, $stream);
                    $digest = hash_final($context);
                    if ($bytes !== $expected['size'] || !hash_equals($expected['sha256'], $digest)) {
                        throw new RuntimeException('Production ZIP member content mismatch: ' . $path);
                    }
                } finally {
                    fclose($stream);
                }
                $index++;
            }
        } finally {
            $archive->close();
        }
    } finally {
        if (!putenv($nativeTimezone === false ? 'TZ' : 'TZ=' . $nativeTimezone)) {
            throw new RuntimeException('Could not restore the native ZIP verification timezone.');
        }
    }
}
