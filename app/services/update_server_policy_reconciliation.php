<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/update_server_policy_reconciliation.php
 * Module Type: Service
 *
 * Purpose:
 *   Reconciles application-owned Apache policy files after updater activation.
 *
 * Responsibilities:
 *   - Keep the exact application-owned .htaccess files synchronized with a validated release
 *   - Repair first-generation updates whose older updater skipped server policy files
 *   - Extend rollback metadata before any bootstrap reconciliation changes the active tree
 *   - Avoid traversing or replacing neighboring gallery, cache, configuration, or user content
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
 *   - Prefer small, readable changes over broad rewrites.
 *   - This file is deliberately dependency-light so a newly activated migration can load it
 *     even when the current PHP request still has the previous updater implementation in memory.
 *
 * Last Updated:
 *   2026-08-31
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;

/**
 * Return the exact Apache policy files owned by PHP Gallery releases.
 *
 * These files are intentionally updater-managed even inside otherwise protected
 * runtime-data directories. No neighboring gallery, cache, data, or host-specific
 * dotfile is implicitly included.
 *
 * @return array<int,string> Project-relative server policy files.
 */
function application_update_server_policy_files(): array
{
    return [
        '.htaccess',
        'public/.htaccess',
        'galleries/.htaccess',
        'cache/.htaccess',
        'data/admin-log-archives/.htaccess',
    ];
}

/**
 * Return a normalized text hash compatible with app/core-manifest.json.
 *
 * @param string $path Absolute file path.
 * @return string Manifest-style sha256 value or an empty string when unreadable.
 */
function application_update_server_policy_normalized_hash(string $path): string
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        return '';
    }
    if (str_starts_with($contents, "\xEF\xBB\xBF")) {
        $contents = substr($contents, 3);
    }
    $contents = str_replace(["\r\n", "\r"], "\n", $contents);
    return 'sha256:' . hash('sha256', $contents);
}

/**
 * Read a small JSON state file without depending on the currently loaded updater version.
 *
 * @param string $path JSON file path.
 * @return array<string,mixed> Decoded object or an empty array.
 */
function application_update_server_policy_read_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $contents = @file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return [];
    }
    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Atomically persist small bootstrap metadata without relying on updater helpers.
 *
 * @param string $path Destination JSON path.
 * @param array<string,mixed> $payload JSON payload.
 */
function application_update_server_policy_write_json_atomic(string $path, array $payload): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not prepare server-policy reconciliation metadata directory.');
    }

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Could not encode server-policy reconciliation metadata.');
    }

    $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not persist server-policy reconciliation metadata.');
    }
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Could not commit server-policy reconciliation metadata.');
    }
}

/**
 * Read the previous release hash for one file from a rollback core manifest.
 *
 * @param string $manifestPath Previous release app/core-manifest.json path.
 * @param string $relativePath Project-relative file path.
 * @return ?string Manifest-style sha256 value when available.
 */
/**
 * Return normalized hashes of historical official root .htaccess variants.
 *
 * The one-time bootstrap may encounter a policy file that an older updater skipped
 * across several releases, so the active file can legitimately predate the rollback
 * manifest by more than one version. These hashes cover the official header-bearing
 * root policies that preceded the current updater repair and are normalized exactly
 * like app/core-manifest.json. Administrator-customized variants do not match.
 *
 * @return array<int,string> Manifest-style normalized sha256 values.
 */
function application_update_server_policy_known_root_hashes(): array
{
    return [
        'sha256:b0673f34ef5cd87dca217d5c6e4d3f221e4863c0fdca844c9fd51ff95724619b',
        'sha256:9be0b9184de82d6b5e34b72be8fb584598fb35b7b49e45af1fc59a69bf0fa80d',
        'sha256:4021ba275f58c2f67357d101606d10ba600df81fa4aa82bd0f968a0553b99859',
    ];
}

/**
 * Read a previously published hash for one policy file from an integrity manifest.
 *
 * @param string $manifestPath Manifest JSON path.
 * @param string $relativePath Project-relative policy path.
 * @return ?string Normalized sha256 hash, or null when unavailable.
 */
function application_update_server_policy_previous_manifest_hash(string $manifestPath, string $relativePath): ?string
{
    $manifest = application_update_server_policy_read_json($manifestPath);
    $files = isset($manifest['files']) && is_array($manifest['files']) ? $manifest['files'] : [];
    $entry = $files[$relativePath] ?? null;
    if (is_string($entry) && preg_match('/^sha256:[a-f0-9]{64}$/i', $entry) === 1) {
        return strtolower($entry);
    }
    if (is_array($entry)) {
        $hash = (string) ($entry['hash'] ?? $entry['sha256'] ?? '');
        if ($hash !== '' && !str_starts_with(strtolower($hash), 'sha256:')) {
            $hash = 'sha256:' . $hash;
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/i', $hash) === 1) {
            return strtolower($hash);
        }
    }
    return null;
}

/**
 * Return whether an existing nested policy file identifies itself as a PHP Gallery artifact.
 *
 * This is used only by the one-generation bootstrap repair. Normal updater runs
 * already own these exact paths through the regular activation/rollback planner.
 *
 * @param string $path Existing destination file.
 * @param string $relativePath Expected project-relative file identity.
 */
function application_update_server_policy_has_gallery_identity(string $path, string $relativePath): bool
{
    $contents = @file_get_contents($path, false, null, 0, 8192);
    if ($contents === false) {
        return false;
    }
    return str_contains($contents, '# Project: PHP Gallery')
        && str_contains($contents, '# File: ' . $relativePath);
}

/**
 * Decide whether the bootstrap migration may replace an existing policy file.
 *
 * The root .htaccess is verified against the previous release core manifest when
 * available, which avoids silently replacing administrator-customized root rules.
 * Nested policy files are not part of the core manifest, so bootstrap replacement
 * is limited to missing files or files that explicitly identify themselves as PHP
 * Gallery policy artifacts. Subsequent normal updates use the standard updater
 * ownership model instead of this compatibility guard.
 *
 * @param string $destination Existing active destination.
 * @param string $relativePath Project-relative policy file.
 * @param string $rollbackRoot Rollback snapshot root.
 */
function application_update_server_policy_bootstrap_replacement_allowed(
    string $destination,
    string $relativePath,
    string $rollbackRoot
): bool {
    if (!file_exists($destination) && !is_link($destination)) {
        return true;
    }
    if (is_link($destination) || !is_file($destination)) {
        return false;
    }

    if ($relativePath === '.htaccess') {
        $actual = strtolower(application_update_server_policy_normalized_hash($destination));
        if ($actual === '') {
            return false;
        }

        $previousManifest = $rollbackRoot . '/app/core-manifest.json';
        $expected = application_update_server_policy_previous_manifest_hash($previousManifest, '.htaccess');
        if ($expected !== null && hash_equals($expected, $actual)) {
            return true;
        }

        return in_array($actual, application_update_server_policy_known_root_hashes(), true);
    }

    return application_update_server_policy_has_gallery_identity($destination, $relativePath);
}

/**
 * Ensure a directory exists for one exact updater-managed policy file.
 *
 * @param string $directory Parent directory path.
 */
function application_update_server_policy_ensure_directory(string $directory): void
{
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not prepare an application server-policy directory.');
    }
}

/**
 * Copy an original active policy file into the durable rollback snapshot once.
 *
 * @param string $source Active destination file.
 * @param string $backup Durable rollback destination.
 */
function application_update_server_policy_backup_original(string $source, string $backup): void
{
    if (is_file($backup)) {
        return;
    }
    if (file_exists($backup) || is_link($backup)) {
        throw new RuntimeException('Server-policy rollback destination has an unexpected filesystem type.');
    }
    application_update_server_policy_ensure_directory(dirname($backup));
    $temporary = $backup . '.tmp-' . bin2hex(random_bytes(4));
    if (!@copy($source, $temporary)) {
        throw new RuntimeException('Could not prepare server-policy rollback snapshot.');
    }
    $sourceHash = @hash_file('sha256', $source);
    $temporaryHash = @hash_file('sha256', $temporary);
    if (!is_string($sourceHash) || !is_string($temporaryHash) || !hash_equals($sourceHash, $temporaryHash)) {
        @unlink($temporary);
        throw new RuntimeException('Server-policy rollback snapshot failed integrity verification.');
    }
    if (!@rename($temporary, $backup)) {
        @unlink($temporary);
        throw new RuntimeException('Could not commit server-policy rollback snapshot.');
    }
}

/**
 * Atomically replace one active policy file from the validated release snapshot.
 *
 * @param string $source Validated release file.
 * @param string $destination Active installation file.
 */
function application_update_server_policy_replace_file(string $source, string $destination): void
{
    if (!is_file($source) || is_link($source)) {
        throw new RuntimeException('Validated release server-policy file is missing or unsafe.');
    }
    if (is_link($destination) || is_dir($destination)) {
        throw new RuntimeException('Active server-policy destination has an unsafe filesystem type.');
    }

    application_update_server_policy_ensure_directory(dirname($destination));
    $sourceHash = @hash_file('sha256', $source);
    if (!is_string($sourceHash) || $sourceHash === '') {
        throw new RuntimeException('Could not verify validated release server-policy file.');
    }

    $temporary = dirname($destination) . '/.php-gallery-policy-' . bin2hex(random_bytes(6)) . '.tmp';
    if (!@copy($source, $temporary)) {
        throw new RuntimeException('Could not prepare active server-policy replacement.');
    }
    $temporaryHash = @hash_file('sha256', $temporary);
    if (!is_string($temporaryHash) || !hash_equals($sourceHash, $temporaryHash)) {
        @unlink($temporary);
        throw new RuntimeException('Active server-policy replacement failed integrity verification.');
    }
    if (!@rename($temporary, $destination)) {
        @unlink($temporary);
        throw new RuntimeException('Could not atomically replace an active server-policy file.');
    }
    clearstatcache(true, $destination);
}

/**
 * Reconcile exact policy files from one validated updater source and extend rollback metadata.
 *
 * Metadata is committed before every active replacement. If the request fails after
 * one file is changed, a later rollback can therefore restore that file safely.
 * The operation is replay-safe: an already-current destination is a no-op and an
 * existing rollback snapshot is never overwritten by a retry.
 *
 * @param string $sourceRoot Validated extracted release root.
 * @param string $projectRoot Active installation root.
 * @param string $rollbackRoot Durable rollback/original directory.
 * @param string $metadataPath Durable rollback metadata.json path.
 * @param bool $bootstrapSafety Respect previous-release/custom-file guards.
 * @return array<string,mixed> Reconciliation diagnostics.
 */
function application_update_reconcile_server_policy_files(
    string $sourceRoot,
    string $projectRoot,
    string $rollbackRoot,
    string $metadataPath,
    bool $bootstrapSafety
): array {
    $metadata = application_update_server_policy_read_json($metadataPath);
    if ($metadata === []) {
        throw new RuntimeException('Server-policy reconciliation requires durable rollback metadata.');
    }

    $createdPaths = array_fill_keys(array_map('strval', (array) ($metadata['created_paths'] ?? [])), true);
    $activationFiles = array_fill_keys(array_map('strval', (array) ($metadata['activation_files'] ?? [])), true);
    $updated = [];
    $unchanged = [];
    $missing = [];
    $skippedCustomized = [];

    foreach (application_update_server_policy_files() as $relativePath) {
        $source = $sourceRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!file_exists($source)) {
            $missing[] = $relativePath;
            continue;
        }
        if (!is_file($source) || is_link($source)) {
            throw new RuntimeException('Validated release contains an unsafe server-policy path.');
        }

        $destination = $projectRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (is_link($destination) || is_dir($destination)) {
            throw new RuntimeException('Active server-policy destination has an unsafe filesystem type.');
        }

        $sourceHash = @hash_file('sha256', $source);
        $destinationHash = is_file($destination) ? @hash_file('sha256', $destination) : false;
        if (is_string($sourceHash) && is_string($destinationHash) && hash_equals($sourceHash, $destinationHash)) {
            $unchanged[] = $relativePath;
            continue;
        }

        if ($bootstrapSafety
            && !application_update_server_policy_bootstrap_replacement_allowed($destination, $relativePath, $rollbackRoot)) {
            $skippedCustomized[] = $relativePath;
            continue;
        }

        $backup = $rollbackRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (is_file($destination)) {
            application_update_server_policy_backup_original($destination, $backup);
        } else {
            $createdPaths[$relativePath] = true;
        }
        $activationFiles[$relativePath] = true;

        $metadata['created_paths'] = array_values(array_keys($createdPaths));
        $metadata['activation_files'] = array_values(array_keys($activationFiles));
        $metadata['server_policy_bootstrap'] = [
            'updated_at' => time(),
            'files' => array_values(array_unique(array_merge(
                (array) (($metadata['server_policy_bootstrap']['files'] ?? [])),
                $updated,
                [$relativePath]
            ))),
        ];
        application_update_server_policy_write_json_atomic($metadataPath, $metadata);

        application_update_server_policy_replace_file($source, $destination);
        $updated[] = $relativePath;
    }

    $result = [
        'checked' => count(application_update_server_policy_files()),
        'updated' => count($updated),
        'unchanged' => count($unchanged),
        'missing_from_release' => count($missing),
        'skipped_customized' => count($skippedCustomized),
        'updated_files' => $updated,
        'skipped_customized_files' => $skippedCustomized,
    ];

    $markerPath = dirname($metadataPath, 2) . '/server-policy-reconciliation.json';
    application_update_server_policy_write_json_atomic($markerPath, [
        'schema' => 1,
        'updated_at' => time(),
        'bootstrap_safety' => $bootstrapSafety,
        'result' => $result,
    ]);

    return $result;
}

/**
 * Validate and return one active updater job's release/rollback filesystem context.
 *
 * @param array<string,mixed> $job Update job state.
 * @return array<string,string>|null Reconciliation context, or null when not applicable.
 */
function application_update_server_policy_job_context(array $job): ?array
{
    $operation = (string) ($job['operation'] ?? '');
    if ($operation === '' || $operation === 'rollback' || empty($job['checkpoints']['activation_complete'])) {
        return null;
    }

    $jobId = (string) ($job['id'] ?? '');
    if (preg_match('/^[0-9]{14}-[a-f0-9]{12}$/', $jobId) !== 1) {
        throw new RuntimeException('Server-policy reconciliation received an invalid update job identifier.');
    }

    $projectRoot = dirname(__DIR__, 2);
    $jobDir = $projectRoot . '/cache/updates/jobs/' . $jobId;
    $sourceRoot = (string) ($job['checkpoints']['source_root'] ?? '');
    $realJobDir = realpath($jobDir);
    $realExtractDir = realpath($jobDir . '/extract');
    $realSourceRoot = $sourceRoot !== '' ? realpath($sourceRoot) : false;
    if ($realJobDir === false || $realExtractDir === false || $realSourceRoot === false) {
        throw new RuntimeException('Server-policy reconciliation update source is unavailable.');
    }

    $normalizedExtract = rtrim(str_replace('\\', '/', $realExtractDir), '/');
    $normalizedSource = rtrim(str_replace('\\', '/', $realSourceRoot), '/');
    if ($normalizedSource !== $normalizedExtract && !str_starts_with($normalizedSource . '/', $normalizedExtract . '/')) {
        throw new RuntimeException('Server-policy reconciliation refused a source outside the validated update extraction.');
    }

    $rollbackRoot = $jobDir . '/rollback/original';
    $metadataPath = $jobDir . '/rollback/metadata.json';
    if (!is_dir($rollbackRoot) || !is_file($metadataPath)) {
        throw new RuntimeException('Server-policy reconciliation rollback snapshot is unavailable.');
    }

    return [
        'source_root' => $realSourceRoot,
        'project_root' => $projectRoot,
        'rollback_root' => $rollbackRoot,
        'metadata_path' => $metadataPath,
    ];
}

/**
 * Reconcile policy files for a known update job.
 *
 * @param array<string,mixed> $job Active update job.
 * @param bool $bootstrapSafety Respect one-generation custom-file safeguards.
 * @return array<string,mixed> Reconciliation diagnostics.
 */
function application_update_reconcile_server_policy_files_for_job(array $job, bool $bootstrapSafety): array
{
    $context = application_update_server_policy_job_context($job);
    if ($context === null) {
        return [
            'checked' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'missing_from_release' => 0,
            'skipped_customized' => 0,
            'updated_files' => [],
            'skipped_customized_files' => [],
        ];
    }

    return application_update_reconcile_server_policy_files(
        $context['source_root'],
        $context['project_root'],
        $context['rollback_root'],
        $context['metadata_path'],
        $bootstrapSafety
    );
}

/**
 * Bootstrap reconciliation entrypoint for a migration activated by an older updater.
 *
 * The previous updater implementation remains loaded in memory for the current
 * request. This helper therefore uses only stable job functions when available
 * and otherwise becomes a harmless no-op on fresh installs or manual migrations.
 *
 * @return array<string,mixed> Reconciliation diagnostics.
 */
function application_update_reconcile_server_policy_files_from_active_job(): array
{
    $activeFunction = __NAMESPACE__ . '\\application_update_active_job';
    if (!function_exists($activeFunction)) {
        return [
            'checked' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'missing_from_release' => 0,
            'skipped_customized' => 0,
            'updated_files' => [],
            'skipped_customized_files' => [],
        ];
    }

    $job = application_update_active_job();
    if (!is_array($job)) {
        return [
            'checked' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'missing_from_release' => 0,
            'skipped_customized' => 0,
            'updated_files' => [],
            'skipped_customized_files' => [],
        ];
    }

    return application_update_reconcile_server_policy_files_for_job($job, true);
}
