<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/package_production.php
 * Module Type: CLI Package Builder
 * Purpose: Build exact production folders and reproducible ZIPs from the canonical inventory.
 * Responsibilities: Validate source integrity, preserve metadata, verify all output bytes, and publish without clobbering.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Packaging;

use RuntimeException;
use Throwable;
use ZipArchive;

require_once __DIR__ . '/cli_guard.php';
\gallery_guard_cli_entrypoint(__FILE__);
require_once dirname(__DIR__) . '/app/release_file_policy.php';
require_once __DIR__ . '/package_production/filesystem.php';
require_once __DIR__ . '/package_production/integrity.php';

/**
 * Select a portable UTC ZIP date with the format's two-second precision.
 *
 * @param ?int $requested Explicit Unix timestamp or null for SOURCE_DATE_EPOCH/manifest time.
 * @param string $generatedAt Validated manifest generation date.
 * @return int Even Unix timestamp between 1980 and the end of 2107.
 */
function archive_timestamp(?int $requested, string $generatedAt): int
{
    $epoch = getenv('SOURCE_DATE_EPOCH');
    if ($requested === null && $epoch !== false && $epoch !== '') {
        if (preg_match('/^[0-9]{1,12}$/D', $epoch) !== 1) {
            throw new RuntimeException(
                'SOURCE_DATE_EPOCH must be a nonnegative integer.',
            );
        }
        $requested = (int) $epoch;
    }
    $timestamp = $requested ?? strtotime($generatedAt);
    if (
        !is_int($timestamp)
        || $timestamp < 315532800
        || $timestamp > 4354819199
    ) {
        throw new RuntimeException(
            'Package timestamp must be within the ZIP date range 1980 through 2107.',
        );
    }
    return $timestamp - ($timestamp % 2);
}

/**
 * Copy one explicit allowlist into an isolated directory, preserving payload metadata.
 *
 * @param string $source Canonical source root.
 * @param string $stage Empty builder-owned directory.
 * @param array<string,array{sha256:string,size:int,mtime:int,atime:int,mode:int}> $files Validated source evidence.
 * @return void Copies exactly the selected bytes or throws before publication.
 */
function copy_files(string $source, string $stage, array $files): void
{
    foreach ($files as $path => $expected) {
        $target = $stage . '/' . $path;
        $parent = dirname($target);
        if (
            !is_dir($parent)
            && !mkdir($parent, 0755, true)
            && !is_dir($parent)
        ) {
            throw new RuntimeException('Could not create production package directory: '
                . $path);
        }
        $input = $source . '/' . $path;
        $record = read_file($input);
        if (
            $record['sha256'] !== $expected['sha256']
            || $record['mtime'] !== $expected['mtime']
            || $record['mode'] !== $expected['mode']
        ) {
            throw new RuntimeException('Production source changed after validation: '
                . $path);
        }
        $handle = fopen($target, 'xb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not create staged production file: '
                . $path);
        }
        try {
            $offset = 0;
            while ($offset < $record['size']) {
                $written = fwrite($handle, substr($record['bytes'], $offset));
                if (!is_int($written) || $written === 0) {
                    throw new RuntimeException('Could not copy complete production file: '
                        . $path);
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new RuntimeException('Could not flush staged production file: '
                    . $path);
            }
        } finally {
            fclose($handle);
        }
        if (
            !chmod($target, $expected['mode'] & 0777)
            || !touch($target, $expected['mtime'], $expected['atime'])
        ) {
            throw new RuntimeException('Could not preserve production file metadata: '
                . $path);
        }
    }
}

/**
 * Create a deterministic STORE archive without external compression tools.
 *
 * @param string $stage Verified package tree.
 * @param string $archivePath New archive file inside an owned workspace.
 * @param array<string,array{sha256:string,size:int,mtime:int,atime:int,mode:int}> $files Validated source evidence.
 * @param int $timestamp Portable UTC timestamp for every member.
 * @return void Creates a closed archive or throws before publication.
 */
function write_zip(
    string $stage,
    string $archivePath,
    array $files,
    int $timestamp,
): void {
    $archive = new ZipArchive();
    if (
        $archive->open($archivePath, ZipArchive::CREATE | ZipArchive::EXCL)
        !== true
    ) {
        throw new RuntimeException('Could not create the production ZIP.');
    }
    try {
        foreach ($files as $path => $record) {
            if (
                !$archive->addFile($stage . '/' . $path, $path)
                || !$archive->setCompressionName($path, ZipArchive::CM_STORE)
                || !$archive->setMtimeName($path, $timestamp)
                || !$archive->setExternalAttributesName(
                    $path,
                    ZipArchive::OPSYS_UNIX,
                    ($record['mode'] & 0177777) << 16,
                )
            ) {
                throw new RuntimeException('Could not prepare production ZIP member: '
                    . $path);
            }
        }
    } catch (Throwable $exception) {
        $archive->close();
        throw $exception;
    }
    if (!$archive->close()) {
        throw new RuntimeException('Could not finish the production ZIP.');
    }
}

/**
 * Build and verify requested production outputs before their final publication.
 *
 * Destination parents must exist. Exclusive sibling locks serialize builders;
 * workspaces remain beside their outputs so publication never crosses devices.
 * All outputs are verified before publication. POSIX directory publication first
 * atomically reserves the absent output name with an empty owned directory;
 * rename replaces only that reservation. Windows directory rename already refuses
 * an existing directory. Caught publication or cleanup failures remove outputs
 * created by this invocation. Output parents must remain trusted during the build.
 *
 * @param string $sourceRoot Source package or checkout; Git metadata is not used.
 * @param ?string $directory New production directory or null when not requested.
 * @param ?string $zip New production ZIP path or null when not requested.
 * @param ?int $timestamp Explicit ZIP build epoch, or null for the reproducible environment/manifest date.
 * @return array{schema_version:int,file_count:int,directory:?string,zip:?string,archive_sha256:?string,timestamp:int,timings:array{source_validation_seconds:float,copy_seconds:float,zip_seconds:float,verification_seconds:float,total_seconds:float}} Verified output identities and measured phases.
 */
function build(
    string $sourceRoot,
    ?string $directory = null,
    ?string $zip = null,
    ?int $timestamp = null,
): array {
    $started = microtime(true);
    if ($directory === null && $zip === null) {
        throw new RuntimeException(
            'At least one --directory or --zip output is required.',
        );
    }
    if ($zip !== null && !class_exists(ZipArchive::class)) {
        throw new RuntimeException(
            'Production ZIP packaging requires the PHP ZipArchive extension.',
        );
    }
    $source = absolute_path($sourceRoot);
    assert_native_paths([$source], false);
    $source = \Gallery\Core\release_file_policy_root($source);
    $directory = $directory === null ? null : output_path($directory, $source);
    $zip = $zip === null ? null : output_path($zip, $source);
    if ($directory !== null && $zip !== null) {
        $directoryKey = strtolower($directory);
        $zipKey = strtolower($zip);
        if (
            $directoryKey === $zipKey
            || str_starts_with($zipKey, $directoryKey . DIRECTORY_SEPARATOR)
            || str_starts_with($directoryKey, $zipKey . DIRECTORY_SEPARATOR)
        ) {
            throw new RuntimeException(
                'Package outputs must be distinct and must not contain one another.',
            );
        }
    }
    $parents = array_map(static fn(string $path): string => dirname(
        $path,
    ), array_values(array_filter([$directory, $zip], static fn(?string $path): bool => $path !== null)));
    $evidence = source_evidence($source, $parents);
    $timestamp = archive_timestamp($timestamp, $evidence['generated_at']);
    $files = $evidence['files'];
    $validated = microtime(true);
    $workspaces = [];
    $locks = [];
    $published = [];
    $timezone = date_default_timezone_get();
    $nativeTimezone = getenv('TZ');
    try {
        try {
            // libzip uses C localtime, independently of PHP's date timezone. putenv(TZ)
            // also invokes PHP's native tzset bridge; UTC0 is accepted by Windows CRT.
            if (!putenv('TZ=UTC0')) {
                throw new RuntimeException(
                    'Could not set the native ZIP timestamp timezone.',
                );
            }
            date_default_timezone_set('UTC');
            foreach (array_filter(
                [$directory, $zip],
                static fn(?string $path): bool => $path !== null,
            ) as $output) {
                $lockPath = $output . '.package.lock';
                $lock = @fopen($lockPath, 'xb');
                if (!is_resource($lock)) {
                    throw new RuntimeException('Production output is already reserved: '
                        . $output);
                }
                $locks[$lockPath] = $lock;
                if (file_exists($output) || is_link($output)) {
                    throw new RuntimeException('Production output appeared before publication: '
                        . $output);
                }
            }
            $treeParent = dirname($directory ?? (string) $zip);
            $workspace =
                $treeParent
                . DIRECTORY_SEPARATOR
                . '.gallery-package-'
                . bin2hex(random_bytes(8));
            if (!mkdir($workspace, 0700)) {
                throw new RuntimeException(
                    'Could not create a package workspace.',
                );
            }
            $workspaces[] = $workspace;
            $stage = $workspace . DIRECTORY_SEPARATOR . 'tree';
            if (!mkdir($stage, 0755)) {
                throw new RuntimeException(
                    'Could not create a staged package tree.',
                );
            }
            $copyStarted = microtime(true);
            copy_files($source, $stage, $files);
            $copied = microtime(true);
            verify_directory($stage, $files);
            $treeVerified = microtime(true);
            $archivePath = null;
            $archiveSha256 = null;
            $zipSeconds = 0.0;
            $zipVerificationSeconds = 0.0;
            if ($zip !== null) {
                $archiveWorkspace = $workspace;
                if (dirname($zip) !== $treeParent) {
                    $archiveWorkspace =
                        dirname($zip)
                        . DIRECTORY_SEPARATOR
                        . '.gallery-package-'
                        . bin2hex(random_bytes(8));
                    if (!mkdir($archiveWorkspace, 0700)) {
                        throw new RuntimeException(
                            'Could not create a ZIP workspace.',
                        );
                    }
                    $workspaces[] = $archiveWorkspace;
                }
                $archivePath =
                    $archiveWorkspace . DIRECTORY_SEPARATOR . 'production.zip';
                $zipStarted = microtime(true);
                write_zip($stage, $archivePath, $files, $timestamp);
                $zipWritten = microtime(true);
                verify_zip($archivePath, $files, $timestamp);
                $archiveSha256 = hash_file('sha256', $archivePath);
                if (!is_string($archiveSha256)) {
                    throw new RuntimeException(
                        'Could not hash the complete production ZIP.',
                    );
                }
                $zipSeconds = $zipWritten - $zipStarted;
                $zipVerificationSeconds = microtime(true) - $zipWritten;
            }
            foreach (array_filter(
                [$directory, $zip],
                static fn(?string $path): bool => $path !== null,
            ) as $output) {
                if (file_exists($output) || is_link($output)) {
                    throw new RuntimeException('Production output appeared before publication: '
                        . $output);
                }
                $temporary = $output === $directory
                    ? $stage
                    : (string) $archivePath;
                if ($output === $zip) {
                    // A hard-link publication fails atomically if another file already owns the name.
                    if (!link($temporary, $output)) {
                        throw new RuntimeException(
                            'Could not publish the production ZIP without clobbering.',
                        );
                    }
                } else {
                    if (DIRECTORY_SEPARATOR !== '\\') {
                        if (!@mkdir($output, 0700)) {
                            throw new RuntimeException(
                                'Could not reserve the production directory without clobbering.',
                            );
                        }
                        $published[] = $output;
                    }
                    if (!rename($temporary, $output)) {
                        throw new RuntimeException(
                            'Could not publish the production directory.',
                        );
                    }
                }
                if (!in_array($output, $published, true)) {
                    $published[] = $output;
                }
            }
            $report = [
                'schema_version' => 1,
                'file_count' => count($files),
                'directory' => $directory,
                'zip' => $zip,
                'archive_sha256' => $archiveSha256,
                'timestamp' => $timestamp,
                'timings' => [
                    'source_validation_seconds' => $validated - $started,
                    'copy_seconds' => $copied - $copyStarted,
                    'zip_seconds' => $zipSeconds,
                    'verification_seconds' =>
                        $treeVerified - $copied + $zipVerificationSeconds,
                    'total_seconds' => microtime(true) - $started,
                ],
            ];
        } finally {
            try {
                foreach (array_reverse($workspaces) as $workspace) {
                    remove_tree($workspace);
                }
                foreach ($locks as $path => $handle) {
                    fclose($handle);
                    if (!unlink($path)) {
                        throw new RuntimeException('Could not release package output reservation: '
                            . $path);
                    }
                }
            } finally {
                if (!putenv(
                    $nativeTimezone === false ? 'TZ' : 'TZ=' . $nativeTimezone,
                )) {
                    throw new RuntimeException(
                        'Could not restore the native timestamp timezone.',
                    );
                }
            }
        }
        $report['timings']['total_seconds'] = microtime(true) - $started;
        return $report;
    } catch (Throwable $exception) {
        foreach (array_reverse($published) as $output) {
            remove_tree($output);
        }
        throw $exception;
    } finally {
        date_default_timezone_set($timezone);
    }
}

/**
 * Parse the strict non-interactive builder CLI and print one machine-readable result.
 *
 * @param list<string> $arguments CLI arguments excluding the executable/script name.
 * @return int Zero for a verified build or help; errors are raised to the CLI boundary.
 */
function main(array $arguments): int
{
    if ($arguments === ['--help']) {
        echo
            "Usage: php scripts/package_production.php [--root=SOURCE] [--directory=NEW_PATH] [--zip=NEW_PATH] [--timestamp=UNIX]\n";
        return 0;
    }
    $options = [];
    foreach ($arguments as $argument) {
        if (
            preg_match(
                '/^--(root|directory|zip|timestamp)=(.+)$/D',
                $argument,
                $match,
            ) !== 1
            || isset($options[$match[1]])
        ) {
            throw new RuntimeException('Unknown, empty, or repeated package option: '
                . $argument);
        }
        $options[$match[1]] = $match[2];
    }
    if (
        isset($options['timestamp'])
        && preg_match('/^[0-9]{1,12}$/D', $options['timestamp']) !== 1
    ) {
        throw new RuntimeException(
            '--timestamp must be a nonnegative integer.',
        );
    }
    $report = build(
        $options['root'] ?? dirname(__DIR__),
        $options['directory'] ?? null,
        $options['zip'] ?? null,
        isset($options['timestamp']) ? (int) $options['timestamp'] : null,
    );
    echo
        json_encode(
            $report,
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
        ),
        "\n";
    return 0;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        exit(main(array_slice($argv, 1)));
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");
        exit(1);
    }
}
