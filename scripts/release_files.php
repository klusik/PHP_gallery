<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/release_files.php
 * Module Type: CLI Package Policy Bridge
 * Purpose: List and verify package paths using the checked-in production inventory.
 * Responsibilities: Expose deterministic path lists to deployment wrappers and compare staged trees.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

require_once __DIR__ . '/cli_guard.php';
gallery_require_cli_sapi();

require_once dirname(__DIR__) . '/app/release_file_policy.php';

/**
 * Return a valued command-line option or null when it was not supplied.
 *
 * @param string $name Option name without its leading dashes.
 * @return ?string Option value when present.
 */
function release_files_option(string $name): ?string
{
    global $argv;

    $prefix = '--' . $name . '=';
    foreach ($argv as $argument) {
        if (str_starts_with($argument, $prefix)) {
            return substr($argument, strlen($prefix));
        }
    }
    return null;
}

/**
 * Return the command-line root or the current repository root.
 *
 * @param string $option Option name to read.
 * @return string Supplied path or script repository root.
 */
function release_files_root_option(string $option): string
{
    return release_files_option($option) ?? dirname(__DIR__);
}

/**
 * Return true when the named standalone flag appears in argv.
 *
 * @param string $flag Complete flag name.
 * @return bool True when present.
 */
function release_files_has_flag(string $flag): bool
{
    global $argv;
    return in_array($flag, $argv, true);
}

/**
 * Print package policy bridge usage.
 *
 * @return void Writes usage text to stdout.
 */
function release_files_print_usage(): void
{
    echo "Usage:\n";
    echo "  php scripts/release_files.php list --root=PATH [--profile=production|updater|source-review] [--include-media] [--format=json|nul]\n";
    echo "  php scripts/release_files.php verify --root=STAGE [--source-root=SOURCE] [--profile=production|updater|source-review] [--include-media]\n";
}

/**
 * Run the package inventory list or exact staged-tree verification command.
 *
 * @return int Process exit status.
 */
function release_files_main(): int
{
    global $argv;

    if (release_files_has_flag('--help') || release_files_has_flag('-h')) {
        release_files_print_usage();
        return 0;
    }

    $command = (string) ($argv[1] ?? '');
    $root = release_files_root_option('root');
    $profile = release_files_option('profile') ?? 'production';
    $includeMedia = release_files_has_flag('--include-media');

    if ($command === 'list') {
        $format = release_files_option('format') ?? 'json';
        $paths = \Gallery\Core\release_file_policy_paths($root, $profile, $includeMedia);
        if ($format === 'nul') {
            foreach ($paths as $path) {
                echo $path, "\0";
            }
            return 0;
        }
        if ($format !== 'json') {
            throw new RuntimeException('List format must be json or nul.');
        }
        $json = json_encode(
            ['schema_version' => 1, 'profile' => $profile, 'files' => $paths],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            throw new RuntimeException('Could not encode the production file list.');
        }
        echo $json, "\n";
        return 0;
    }

    if ($command === 'verify') {
        $sourceRoot = release_files_root_option('source-root');
        $result = \Gallery\Core\release_file_policy_verify_tree($root, $sourceRoot, $profile, $includeMedia);
        $json = json_encode(
            ['schema_version' => 1, 'profile' => $profile, 'ok' => !$result['unexpected'] && !$result['missing']] + $result,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            throw new RuntimeException('Could not encode the package verification result.');
        }
        echo $json, "\n";
        if ($result['unexpected'] || $result['missing']) {
            fwrite(STDERR, 'Package path verification failed: ' . count($result['missing']) . ' missing, '
                . count($result['unexpected']) . " unexpected.\n");
            return 1;
        }
        return 0;
    }

    release_files_print_usage();
    return 2;
}

try {
    exit(release_files_main());
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
