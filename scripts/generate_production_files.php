<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/generate_production_files.php
 * Module Type: CLI Generator
 * Purpose: Refresh the checked-in exact package membership from reviewed Git-index files.
 * Responsibilities: Select approved production/source-review surfaces and write deterministic JSON.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

require_once __DIR__ . '/cli_guard.php';
gallery_require_cli_sapi();

require_once dirname(__DIR__) . '/app/release_file_policy.php';

/**
 * Return the requested project root or this script's repository root.
 *
 * @return string Canonical project root.
 * @throws RuntimeException When an explicit root does not exist as a directory.
 */
function production_files_root(): string
{
    $requestedRoot = production_files_option('root');
    $candidate = $requestedRoot === null ? dirname(__DIR__) : $requestedRoot;
    $root = realpath($candidate);
    if ($root === false || !is_dir($root)) {
        throw new RuntimeException('Production inventory root is unavailable.');
    }
    return $root;
}

/**
 * Return a valued command-line option or null when it was not supplied.
 *
 * @param string $name Option name without its leading dashes.
 * @return ?string Option value when present.
 */
function production_files_option(string $name): ?string
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
 * Return true when a standalone command-line flag is present.
 *
 * @param string $flag Complete flag name.
 * @return bool True when the flag is present.
 */
function production_files_has_flag(string $flag): bool
{
    global $argv;
    return in_array($flag, $argv, true);
}

/**
 * Return exact server-policy files that belong in releases even under protected roots.
 *
 * @return array<string,true> Project-relative Apache policy paths.
 */
function production_files_server_policy_allowlist(): array
{
    return array_fill_keys(\Gallery\Core\release_file_policy_server_paths(), true);
}

/**
 * Return whether an approved production file belongs to updater activation ownership.
 *
 * @param string $relativePath Git-index path using forward slashes.
 * @return bool True when updater replacement and obsolete-path ownership include the path.
 */
function production_files_is_updater_owned(string $relativePath): bool
{
    return \Gallery\Core\release_file_policy_is_updater_path($relativePath);
}

/**
 * Return whether a tracked path is part of the approved production package surface.
 *
 * @param string $relativePath Git-index path using forward slashes.
 * @return bool True when the path belongs to an approved production surface.
 */
function production_files_is_approved(string $relativePath): bool
{
    $relativePath = str_replace('\\', '/', $relativePath);
    return \Gallery\Core\release_file_policy_is_safe_relative_path($relativePath)
        && \Gallery\Core\release_file_policy_is_production_path($relativePath);
}

/**
 * Return staged/tracked files with Git file modes from the index.
 *
 * @param string $root Project root containing the Git index.
 * @return list<array{mode:string,path:string}> Git-index path and mode records.
 * @throws RuntimeException When Git metadata is unavailable or contains malformed entries.
 */
function production_files_git_index(string $root): array
{
    $command = ['git', '-C', $root, 'ls-files', '--stage', '-z', '--cached'];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not read the Git index for production inventory generation.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || !is_string($stdout)) {
        throw new RuntimeException('Git index listing failed: ' . trim((string) $stderr));
    }

    $records = [];
    foreach (explode("\0", $stdout) as $line) {
        if ($line === '') {
            continue;
        }
        $tab = strpos($line, "\t");
        if ($tab === false) {
            throw new RuntimeException('Git index listing contains a malformed entry.');
        }
        $metadata = substr($line, 0, $tab);
        $path = substr($line, $tab + 1);
        if (preg_match('/^(100644|100755|120000|160000) [0-9a-f]{40} 0$/', $metadata, $match) !== 1) {
            throw new RuntimeException('Git index listing contains an unsupported file mode.');
        }
        if (!\Gallery\Core\release_file_policy_is_safe_relative_path($path)) {
            throw new RuntimeException('Git index contains an unsafe production path.');
        }
        $records[] = ['mode' => $match[1], 'path' => $path];
    }

    return $records;
}

/**
 * Build deterministic CMS, companion and source-review path arrays from the Git index.
 *
 * @param string $root Project root.
 * @return array{schema_version:int,production_files:list<string>,companion_files:list<string>,updater_files:list<string>,source_review_files:list<string>} Refreshed inventory.
 * @throws RuntimeException When an approved path is missing or is a symlink/submodule.
 */
function production_files_build_inventory(string $root): array
{
    $production = [];
    $companion = [];
    $updater = [];
    $sourceReview = [];
    foreach (production_files_git_index($root) as $record) {
        $relative = $record['path'];
        if (isset(production_files_server_policy_allowlist()[$relative])) {
            if (in_array($record['mode'], ['120000', '160000'], true)) {
                throw new RuntimeException('Production inventory cannot contain symlinks or submodules.');
            }
            $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (is_link($absolute) || !is_file($absolute) || !is_readable($absolute)
                || !\Gallery\Core\release_file_policy_resolves_to_expected_path($root, $relative, $absolute)) {
                throw new RuntimeException('A tracked server-policy file is missing, unreadable, or unsafe: ' . $relative);
            }
            $production[] = $relative;
            $updater[] = $relative;
            continue;
        }
        if (str_starts_with($relative, 'tests/')) {
            if (in_array($record['mode'], ['120000', '160000'], true)) {
                throw new RuntimeException('Source-review inventory cannot contain symlinks or submodules.');
            }
            $sourceReview[] = $relative;
            continue;
        }
        if (!production_files_is_approved($relative)) {
            continue;
        }
        if (in_array($record['mode'], ['120000', '160000'], true)) {
            throw new RuntimeException('Production inventory cannot contain symlinks or submodules.');
        }
        $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_link($absolute) || !is_file($absolute) || !is_readable($absolute)
            || !\Gallery\Core\release_file_policy_resolves_to_expected_path($root, $relative, $absolute)) {
            throw new RuntimeException('A tracked production file is missing, unreadable, or unsafe: ' . $relative);
        }
        // Keep the schema-1 base readable by installed CMS updaters with the older allowlist.
        if (\Gallery\Core\release_file_policy_is_winapp_source_path($relative)) {
            $companion[] = $relative;
        } else {
            $production[] = $relative;
        }
        if (production_files_is_updater_owned($relative)) {
            $updater[] = $relative;
        }
    }

    sort($production, SORT_STRING);
    sort($companion, SORT_STRING);
    sort($updater, SORT_STRING);
    sort($sourceReview, SORT_STRING);
    $production = \Gallery\Core\release_file_policy_validate_path_list($production);
    $companion = \Gallery\Core\release_file_policy_validate_path_list($companion);
    $updater = \Gallery\Core\release_file_policy_validate_path_list($updater);
    $sourceReview = \Gallery\Core\release_file_policy_validate_path_list($sourceReview);
    $all = array_merge($production, $companion, $sourceReview);
    sort($all, SORT_STRING);
    \Gallery\Core\release_file_policy_validate_path_list($all);
    return [
        'schema_version' => 1,
        'production_files' => array_values(array_unique($production)),
        'companion_files' => array_values(array_unique($companion)),
        'updater_files' => array_values(array_unique($updater)),
        'source_review_files' => array_values(array_unique($sourceReview)),
    ];
}

/**
 * Print usage for the explicit production inventory refresh/check command.
 *
 * @return void Writes concise CLI usage text.
 */
function production_files_print_usage(): void
{
    echo "Usage: php scripts/generate_production_files.php [--root=PATH] [--check]\n";
    echo "\n";
    echo "Refresh app/production-files.json from approved tracked/staged paths.\n";
    echo "  --check  Compare the checked-in inventory with Git-index membership without writing.\n";
}

/**
 * Compare generated inventory text while ignoring only checkout line-ending conversion.
 *
 * @param ?string $current Current checked-in inventory contents.
 * @param string $expected Canonical generated inventory contents with LF endings.
 * @return bool True when contents match after CRLF normalization.
 */
function production_files_inventory_content_matches(?string $current, string $expected): bool
{
    if ($current === null) {
        return false;
    }
    return str_replace("\r\n", "\n", $current) === $expected;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

if (production_files_has_flag('--help') || production_files_has_flag('-h')) {
    production_files_print_usage();
    exit(0);
}

try {
    $root = production_files_root();
    $inventory = production_files_build_inventory($root);
    $json = json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Could not encode the production file inventory.');
    }
    $output = $root . '/app/production-files.json';
    $content = $json . "\n";

    if (production_files_has_flag('--check')) {
        $current = is_file($output) && !is_link($output) ? file_get_contents($output) : false;
        // Ignore checkout EOL conversion while retaining the full deterministic content comparison.
        if (!production_files_inventory_content_matches(is_string($current) ? $current : null, $content)) {
            fwrite(STDERR, "Production file inventory is stale. Run php scripts/generate_production_files.php after staging intended production files.\n");
            exit(1);
        }
        echo "Production file inventory is current.\n";
        exit(0);
    }

    if (file_put_contents($output, $content, LOCK_EX) === false) {
        throw new RuntimeException('Could not write app/production-files.json.');
    }
    echo 'Wrote app/production-files.json (' . (count($inventory['production_files']) + count($inventory['companion_files'])) . ' production files including '
        . count($inventory['companion_files']) . ' companion files, '
        . count($inventory['source_review_files']) . " source-review files).\n";
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
